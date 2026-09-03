/**
 * Интеграция Bitrix с React-калькулятором через postMessage
 * @module prospektweb.calc
 */

(function (window) {
    'use strict';

    var INTEGRATION_VERSION = '2.5.0-debug';
    console.log('[BitrixBridge] integration.js loaded, version=' + INTEGRATION_VERSION);

    /**
     * @typedef {Object} PwrtMessage
     * @property {'prospektweb.calc'|'bitrix'} source - Источник сообщения
     * @property {'bitrix'|'prospektweb.calc'} target - Получатель сообщения
     * @property {string} type - Тип сообщения
     * @property {string} [requestId] - ID запроса для связи запрос-ответ
     * @property {*} [payload] - Данные сообщения
     * @property {number} [timestamp] - Временная метка
     */

    const MODULE_SOURCE = 'bitrix';
    const MODULE_TARGET = 'prospektweb.calc';
    const MODULE_PROTOCOL = 'pwrt-v1';

    /**
     * Класс для интеграции с React-калькулятором
     */
    class CalcIntegration {
        constructor(config) {
            this.config = {
                iframe: config.iframe || null,
                iframeSelector: config.iframeSelector || '#calc-iframe',
                ajaxEndpoint: config.ajaxEndpoint || '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
                versionAjaxEndpoint: config.versionAjaxEndpoint || '/bitrix/tools/prospektweb.calc/control_center_editors.php',
                offerIds: config.offerIds || [],
                presetId: Number.isSafeInteger(config.presetId) ? config.presetId : 0,
                versionId: typeof config.versionId === 'string' ? config.versionId : '',
                versionMode: config.versionMode === 'readonly' ? 'readonly' : config.versionMode === 'edit' ? 'edit' : '',
                versionOriginalPresetId: Number.isSafeInteger(config.versionOriginalPresetId) ? config.versionOriginalPresetId : 0,
                versionContentHash: typeof config.versionContentHash === 'string' ? config.versionContentHash : '',
                versionLogicHash: typeof config.versionLogicHash === 'string' ? config.versionLogicHash : '',
                editorInstanceId: typeof config.editorInstanceId === 'string' ? config.editorInstanceId : '',
                siteId: config.siteId || '',
                sessid: config.sessid || '',
                onClose: config.onClose || null,
                onError: config.onError || null,
                initPayload: config.initPayload || null,
            };

            this.iframe = null;
            this.iframeWindow = null;
            this.isInitialized = false;
            this.hasUnsavedChanges = false;
            this.debug = Boolean(config.debug);
            this.targetOrigin = window.location.origin;
            this.readyOrigin = null;
            this.pendingRequests = {};
            this.pendingFormEditorRequest = null;
            this.initData = null;
            this.currentSelectionItems = null;

            // Сохраняем ссылку на обработчик для корректного removeEventListener
            this.boundHandleMessage = this.handleMessage.bind(this);

            this.logBridge('[BitrixBridge] ProspektwebCalcIntegration created', {
                iframe: config.iframe ? this.describeIframe(config.iframe) : this.config.iframeSelector,
                ajaxUrl: this.config.ajaxEndpoint,
                offerIds: this.config.offerIds,
                presetId: this.config.presetId,
                hasInitPayload: !!this.config.initPayload,
            });

            this.init();
        }

        /**
         * Инициализация
         */
        init() {
            // Поддержка передачи iframe напрямую или через селектор
            if (this.config.iframe) {
                this.iframe = this.config.iframe;
            } else {
                this.iframe = document.querySelector(this.config.iframeSelector);
            }
            
            if (!this.iframe) {
                console.error('[CalcIntegration] Iframe not found:', this.config.iframeSelector);
                return;
            }

            // Закрываем предыдущий экземпляр, привязанный к этому iframe
            if (this.iframe.__calcIntegrationInstance && this.iframe.__calcIntegrationInstance !== this) {
                this.iframe.__calcIntegrationInstance.destroy();
            }

            this.iframeWindow = this.iframe.contentWindow;
            this.targetOrigin = this.resolveIframeOrigin();
            this.iframe.__calcIntegrationInstance = this;
            this.setupMessageListener();
        }

        resolveIframeOrigin() {
            try {
                const rawSrc = this.iframe && (this.iframe.getAttribute('src') || this.iframe.src);
                const parsed = new URL(rawSrc || window.location.href, window.location.href);
                if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') {
                    throw new Error('Unsupported iframe protocol');
                }
                return parsed.origin;
            } catch (error) {
                this.logBridge('[BitrixBridge] iframe origin fallback to the current site', {
                    reason: error && error.message ? error.message : String(error),
                });
                return window.location.origin;
            }
        }

        /**
         * Настройка обработчика postMessage
         */
        setupMessageListener() {
            window.addEventListener('message', this.boundHandleMessage);
        }

        /**
         * Обработка входящих сообщений
         * @param {MessageEvent} event
         */
        handleMessage(event) {
            if (this.handleControlCenterFormEditorResponse(event)) {
                return;
            }
            if (event.source !== this.iframeWindow || event.origin !== this.targetOrigin) {
                this.logBridge('[BitrixBridge] ignored message outside the bound iframe channel', {
                    origin: event.origin,
                    expectedOrigin: this.targetOrigin,
                });
                return;
            }

            const message = event.data;

            // Валидация структуры сообщения
            const validationResult = this.validateMessage(message);
            if (!validationResult.valid) {
                this.logBridge('[BitrixBridge] received invalid message', {
                    origin: event.origin,
                    reason: validationResult.reason,
                });
                return;
            }

            if (message.protocol === MODULE_PROTOCOL) {
                this.handlePwrtMessage(message, event);
                return;
            }

            // Проверяем, что сообщение для нас
            if (message.target !== MODULE_SOURCE) {
                return;
            }

            const sourceOk = event.source === this.iframeWindow;
            this.logBridge('[BitrixBridge] received message', {
                type: message.type,
                source: message.source,
                target: message.target,
                requestId: message.requestId || null,
                origin: event.origin,
                sourceOk: sourceOk,
            });

            this.logDebug('[CalcIntegration] Received message:', message.type, message);

            // Маршрутизация по типу сообщения
            switch (message.type) {
                case 'READY':
                    this.handleReady(message, event);
                    break;

                case 'INIT_DONE':
                    this.handleInitDone(message);
                    break;

                case 'CLOSE_REQUEST':
                    this.handleCloseRequest(message);
                    break;
                case 'UNSAVED_CHANGES_CHANGED':
                    this.hasUnsavedChanges = Boolean(message.payload && message.payload.hasChanges);
                    break;

                case 'ERROR':
                    this.handleError(message);
                    break;

                default:
                    console.warn('[CalcIntegration] Unknown message type:', message.type);
            }
        }

        /**
         * Обработка сообщений протокола pwrt-v1
         */
        async handlePwrtMessage(message, event) {
            console.log('[BitrixBridge][DEBUG] handlePwrtMessage called', {
                messageType: message.type,
                messageTarget: message.target,
                expectedTarget: MODULE_SOURCE,
                hasPayload: !!message.payload,
                payload: message.payload,
                protocol: message.protocol,
                requestId: message.requestId,
            });

            if (message.target !== MODULE_SOURCE) {
                console.warn('[BitrixBridge][DEBUG] Message target mismatch', {
                    received: message.target,
                    expected: MODULE_SOURCE,
                });
                return;
            }

            const origin = this.targetOrigin;

            // Note: pwcode parameter was removed from the protocol as it was unused
            // and caused unnecessary log pollution (pwcode: undefined)
            console.info('[FROM_IFRAME]', {
                type: message.type,
                requestId: message.requestId,
                payload: message.payload,
            });

            console.log('[BitrixBridge][DEBUG] Routing message type:', message.type);

            if (this.config.versionMode === 'readonly') {
                const readOnlyAllowed = new Set([
                    'GET_AI_SETTINGS_REQUEST',
                    'GET_AI_BASE_PRODUCTS_REQUEST',
                    'GET_CATALOG_ENTITY_META_REQUEST',
                    'GET_CATALOG_TREE_REQUEST',
                    'GET_PRESET_LOAD_OPTIONS_REQUEST',
                    'GENERATE_STAGE_PREVIEW_REQUEST',
                    'GENERATE_AI_TEXT_REQUEST',
                    'LOAD_AI_LOGIC_PILOT_DRAFT_REQUEST',
                    'LOAD_AI_LOGIC_PILOT_REPLACEMENT_CANDIDATES_REQUEST',
                    'PREVIEW_AI_LOGIC_PILOT_MANIFEST_REQUEST',
                    'GENERATE_LOGIC_PROPOSAL_REQUEST',
                    'GENERATE_STAGE_LOGIC_PROPOSAL_REQUEST',
                    'GENERATE_LOGIC_AUDIT_REQUEST',
                    'PREVIEW_GLOBAL_CODE_REFACTOR_REQUEST',
                    'PREVIEW_STAGE_LOGIC_PROMPT_REQUEST',
                    'CHECK_CALC_CONTRACT_REQUEST',
                    'PREVIEW_CATALOG_WRITE_REQUEST',
                    'SAVE_USER_THEME_REQUEST',
                    'OPEN_FORM_EDITOR_REQUEST',
                    'REFRESH_EDITOR_CONTEXT_REQUEST',
                    'CLOSE_REQUEST',
                    'UNSAVED_CHANGES_CHANGED',
                ]);
                if (!readOnlyAllowed.has(message.type)) {
                    this.sendPwrtMessage('ERROR', {
                        code: 'CALCULATOR_VERSION_READ_ONLY',
                        message: 'Режим тестирования не изменяет сохранённую версию. Откройте версию для редактирования логики.',
                        requestType: message.type,
                    }, message.requestId, origin);
                    return;
                }
            }

            switch (message.type) {
                case 'SELECT_REQUEST':
                    await this.handleSelectRequest(message, origin);
                    break;
                case 'SELECT_DETAILS_REQUEST':
                    await this.handleSelectDetailsRequest(message, origin);
                    break;
                case 'SELECT_FIELDS_REQUEST':
                    await this.handleSelectFieldsRequest(message, origin);
                    break;
                case 'OPEN_FORM_EDITOR_REQUEST':
                    this.handleOpenFormEditorRequest(message, origin);
                    break;
                case 'REFRESH_EDITOR_CONTEXT_REQUEST':
                    await this.handleRefreshEditorContextRequest(message, origin);
                    break;
                case 'CREATE_CUSTOM_FIELD_REQUEST':
                    await this.handleCreateCustomFieldRequest(message, origin);
                    break;
                case 'REMOVE_OFFER_REQUEST':
                    this.handleRemoveOfferRequest(message, origin);
                    break;
                case 'ADD_DETAIL_REQUEST':
                    await this.handleAddNewDetailRequest(message, origin);
                    break;
                case 'ADD_STAGE_REQUEST':
                    await this.handleAddStageRequest(message, origin);
                    break;
                case 'DUPLICATE_STAGE_REQUEST':
                    await this.handleDuplicateStageRequest(message, origin);
                    break;
                case 'DELETE_STAGE_REQUEST':
                    await this.handleDeleteStageRequest(message, origin);
                    break;
                case 'SAVE_STAGE_ACTIVATION_REQUEST':
                    await this.handleSaveStageActivationRequest(message, origin);
                    break;
                case 'SAVE_STAGE_USED_ENTITIES_REQUEST':
                    await this.handleSaveStageUsedEntitiesRequest(message, origin);
                    break;
                case 'REMOVE_DETAIL_REQUEST':
                    await this.handleRemoveDetailRequest(message, origin);
                    break;
                case 'RENAME_DETAIL_REQUEST':
                    await this.handleRenameDetailRequest(message, origin);
                    break;
                case 'CHANGE_PRODUCT_TYPE_REQUEST':
                    await this.handleChangeProductTypeRequest(message, origin);
                    break;
                case 'CHANGE_SETTINGS_REQUEST':
                    await this.handleChangeSettingsRequest(message, origin);
                    break;
                case 'CHANGE_OPERATION_VARIANT_REQUEST':
                    await this.handleChangeOperationVariantRequest(message, origin);
                    break;
                case 'CHANGE_EQUIPMENT_REQUEST':
                    await this.handleChangeEquipmentRequest(message, origin);
                    break;
                case 'CHANGE_MATERIAL_VARIANT_REQUEST':
                    await this.handleChangeMaterialVariantRequest(message, origin);
                    break;
                case 'CHANGE_CUSTOM_FIELDS_VALUE_REQUEST':
                    await this.handleChangeCustomFieldsValue(message, origin);
                    break;
                case 'CLONE_DETAIL_REQUEST':
                    await this.handleCloneDetailRequest(message, origin);
                    break;
                case 'CLONE_SELECTED_DETAILS_REQUEST':
                    await this.handleCloneSelectedDetailsRequest(message, origin);
                    break;
                case 'SAVE_SETTINGS_EQUIPMENT_REQUEST':
                    await this.handleSaveSettingsEquipmentRequest(message, origin);
                    break;
                case 'CHANGE_STAGE_NAME_REQUEST':
                    await this.handleChangeStageNameRequest(message, origin);
                    break;
                case 'CHANGE_ENTITY_META_REQUEST':
                    await this.handleChangeEntityMetaRequest(message, origin);
                    break;
                case 'SAVE_PRICE_SETTINGS_PRESET_REQUEST':
                    await this.handleSavePriceSettingsPresetRequest(message, origin);
                    break;
                case 'RENAME_PRICE_SETTINGS_PRESET_REQUEST':
                    await this.handleRenamePriceSettingsPresetRequest(message, origin);
                    break;
                case 'DELETE_PRICE_SETTINGS_PRESET_REQUEST':
                    await this.handleDeletePriceSettingsPresetRequest(message, origin);
                    break;
                case 'GET_AI_SETTINGS_REQUEST':
                    await this.handleGetAiSettingsRequest(message, origin);
                    break;
                case 'SAVE_AI_SETTINGS_REQUEST':
                    await this.handleSaveAiSettingsRequest(message, origin);
                    break;
                case 'GENERATE_STAGE_PREVIEW_REQUEST':
                    await this.handleGenerateStagePreviewRequest(message, origin);
                    break;
                case 'GENERATE_AI_TEXT_REQUEST':
                    await this.handleGenerateAiTextRequest(message, origin);
                    break;
                case 'LOAD_AI_LOGIC_PILOT_DRAFT_REQUEST':
                    await this.handleLoadAiLogicPilotDraftRequest(message, origin);
                    break;
                case 'SAVE_AI_LOGIC_PILOT_DRAFT_REQUEST':
                    await this.handleSaveAiLogicPilotDraftRequest(message, origin);
                    break;
                case 'LOAD_AI_LOGIC_PILOT_REPLACEMENT_CANDIDATES_REQUEST':
                    await this.handleLoadAiLogicPilotReplacementCandidatesRequest(message, origin);
                    break;
                case 'PREVIEW_AI_LOGIC_PILOT_MANIFEST_REQUEST':
                    await this.handlePreviewAiLogicPilotManifestRequest(message, origin);
                    break;
                case 'APPLY_AI_LOGIC_PILOT_MANIFEST_REQUEST':
                    await this.handleApplyAiLogicPilotManifestRequest(message, origin);
                    break;
                case 'INSPECT_AI_LOGIC_PILOT_APPLICATION_REQUEST':
                    await this.handleAiLogicPilotMaterializationRequest(message, origin, 'inspectAiLogicPilotApplication', 'AI_LOGIC_PILOT_APPLICATION_READBACK_RESPONSE');
                    break;
                case 'REPAIR_AI_LOGIC_PILOT_APPLICATION_REQUEST':
                    await this.handleAiLogicPilotMaterializationRequest(message, origin, 'repairAiLogicPilotApplication', 'AI_LOGIC_PILOT_REPAIR_RESPONSE');
                    break;
                case 'GENERATE_LOGIC_PROPOSAL_REQUEST':
                    await this.handleGenerateLogicProposalRequest(message, origin);
                    break;
                case 'GENERATE_STAGE_LOGIC_PROPOSAL_REQUEST':
                    await this.handleGenerateStageLogicProposalRequest(message, origin);
                    break;
                case 'GENERATE_LOGIC_AUDIT_REQUEST':
                    await this.handleGenerateLogicAuditRequest(message, origin);
                    break;
                case 'PREVIEW_GLOBAL_CODE_REFACTOR_REQUEST':
                    await this.handlePreviewGlobalCodeRefactorRequest(message, origin);
                    break;
                case 'APPLY_GLOBAL_CODE_REFACTOR_REQUEST':
                    await this.handleApplyGlobalCodeRefactorRequest(message, origin);
                    break;
                case 'GET_AI_BASE_PRODUCTS_REQUEST':
                    await this.handleGetAiBaseProductsRequest(message, origin);
                    break;
                case 'PREVIEW_STAGE_LOGIC_PROMPT_REQUEST':
                    await this.handlePreviewStageLogicPromptRequest(message, origin);
                    break;
                case 'SAVE_AI_CALCULATOR_CONTEXT_REQUEST':
                    await this.handleSaveAiCalculatorContextRequest(message, origin);
                    break;
                case 'GET_CATALOG_ENTITY_META_REQUEST':
                    await this.handleGetCatalogEntityMetaRequest(message, origin);
                    break;
                case 'SAVE_CATALOG_ENTITY_META_REQUEST':
                    await this.handleSaveCatalogEntityMetaRequest(message, origin);
                    break;
                case 'MOVE_CATALOG_ENTITY_SECTION_REQUEST':
                    await this.handleMoveCatalogEntitySectionRequest(message, origin);
                    break;
                case 'CREATE_CATALOG_SECTION_REQUEST':
                    await this.handleCreateCatalogSectionRequest(message, origin);
                    break;
                case 'GET_CATALOG_TREE_REQUEST':
                    await this.handleGetCatalogTreeRequest(message, origin);
                    break;
                case 'GET_PRESET_LOAD_OPTIONS_REQUEST':
                    await this.handleGetPresetLoadOptionsRequest(message, origin);
                    break;
                case 'SAVE_CATALOG_TREE_ELEMENT_REQUEST':
                    await this.handleSaveCatalogTreeElementRequest(message, origin);
                    break;
                case 'SAVE_CATALOG_TREE_SECTION_REQUEST':
                    await this.handleSaveCatalogTreeSectionRequest(message, origin);
                    break;
                case 'DELETE_CATALOG_TREE_NODE_REQUEST':
                    await this.handleDeleteCatalogTreeNodeRequest(message, origin);
                    break;
                case 'CLEAR_PRESET_REQUEST':
                    await this.handleClearPresetRequest(message, origin);
                    break;
                case 'SAVE_PRESET_GLOBALS_REQUEST':
                    await this.handleSavePresetGlobalsRequest(message, origin);
                    break;
                case 'SAVE_GLOBAL_SYMBOLS_REQUEST':
                    await this.handleSaveGlobalSymbolsRequest(message, origin);
                    break;
                case 'SAVE_GLOBAL_VALUES_REQUEST':
                    await this.handleSaveGlobalValuesRequest(message, origin);
                    break;
                case 'SAVE_STAGE_GROUPS_REQUEST':
                    await this.handleSaveStageGroupsRequest(message, origin);
                    break;
                case 'ADD_DETAIL_TO_BINDING_REQUEST':
                    await this.handleAddDetailToBindingRequest(message, origin);
                    break;
                case 'SELECT_DETAILS_TO_BINDING_REQUEST':
                    await this.handleSelectDetailsToBindingRequest(message, origin);
                    break;
                case 'CHANGE_DETAIL_SORT_REQUEST':
                    await this.handleChangeDetailSortRequest(message, origin);
                    break;
                case 'CHANGE_ROOT_DETAIL_SORT_REQUEST':
                    await this.handleChangeRootDetailSortRequest(message, origin);
                    break;
                case 'CHANGE_DETAIL_LEVEL_REQUEST':
                    await this.handleChangeDetailLevelRequest(message, origin);
                    break;
                case 'CHANGE_SORT_STAGE_REQUEST':
                    await this.handleChangeSortStageRequest(message, origin);
                    break;
                case 'MOVE_STAGE_REQUEST':
                    await this.handleMoveStageRequest(message, origin);
                    break;
                case 'CHANGE_PRICE_PRESET_REQUEST':
                    await this.handleChangePricePresetRequest(message, origin);
                    break;
                case 'CHANGE_OPTIONS_OPERATION':
                    await this.handleChangeOptionsOperation(message, origin);
                    break;
                case 'CHANGE_OPTIONS_MATERIAL':
                    await this.handleChangeOptionsMaterial(message, origin);
                    break;
                case 'CHANGE_OPTIONS_EQUIPMENT':
                    await this.handleChangeOptionsEquipment(message, origin);
                    break;
                case 'SAVE_CALC_LOGIC_REQUEST':
                    await this.handleSaveCalcLogicRequest(message, origin);
                    break;
                case 'CHECK_CALC_CONTRACT_REQUEST':
                    await this.handleCheckCalcContractRequest(message, origin);
                    break;
                case 'RESOLVE_CALC_CONTRACT_REQUEST':
                    await this.handleResolveCalcContractRequest(message, origin);
                    break;
                case 'PREVIEW_CATALOG_WRITE_REQUEST':
                    await this.handleCatalogWriteLifecycleRequest(
                        message,
                        origin,
                        'PREVIEW_CATALOG_WRITE_REQUEST',
                        'PREVIEW_CATALOG_WRITE_RESULT'
                    );
                    break;
                case 'APPLY_CATALOG_WRITE_REQUEST':
                    await this.handleCatalogWriteLifecycleRequest(
                        message,
                        origin,
                        'APPLY_CATALOG_WRITE_REQUEST',
                        'APPLY_CATALOG_WRITE_RESULT'
                    );
                    break;
                case 'SAVE_USER_THEME_REQUEST':
                    await this.handleSaveUserThemeRequest(message, origin);
                    break;
                case 'CLEAR_OPTIONS_OPERATION':
                    await this.handleClearOptionsOperation(message, origin);
                    break;
                case 'CLEAR_OPTIONS_MATERIAL':
                    await this.handleClearOptionsMaterial(message, origin);
                    break;
                case 'CLEAR_OPTIONS_EQUIPMENT':
                    await this.handleClearOptionsEquipment(message, origin);
                    break;
                case 'CHANGE_LOGIC':
                    await this.handleChangeLogic(message, origin);
                    break;
                case 'CLOSE_REQUEST':
                    this.handleCloseRequest(message);
                    break;
                case 'UNSAVED_CHANGES_CHANGED':
                    this.hasUnsavedChanges = Boolean(message.payload && message.payload.hasChanges);
                    break;
                default:
                    console.warn('[BitrixBridge][DEBUG] Unknown pwrt message type:', message.type);
                    console.warn('[BitrixBridge][DEBUG] Known types:', [
                        'SELECT_REQUEST', 'SELECT_DETAILS_REQUEST', 'SELECT_FIELDS_REQUEST', 'SELECT_DETAILS_TO_BINDING_REQUEST',
                        'OPEN_FORM_EDITOR_REQUEST', 'REFRESH_EDITOR_CONTEXT_REQUEST',
                        'ADD_DETAIL_REQUEST', 'ADD_DETAIL_TO_BINDING_REQUEST',
                        'ADD_STAGE_REQUEST', 'DUPLICATE_STAGE_REQUEST', 'DELETE_STAGE_REQUEST', 'SAVE_STAGE_ACTIVATION_REQUEST', 'REMOVE_DETAIL_REQUEST',
                        'RENAME_DETAIL_REQUEST', 'CHANGE_PRODUCT_TYPE_REQUEST', 'CHANGE_SETTINGS_REQUEST', 'CHANGE_OPERATION_VARIANT_REQUEST',
                        'CHANGE_EQUIPMENT_REQUEST', 'CHANGE_MATERIAL_VARIANT_REQUEST',
                        'CHANGE_CUSTOM_FIELDS_VALUE_REQUEST', 'CLONE_DETAIL_REQUEST', 'CLONE_SELECTED_DETAILS_REQUEST',
                        'SAVE_SETTINGS_EQUIPMENT_REQUEST', 'CHANGE_STAGE_NAME_REQUEST', 'CHANGE_ENTITY_META_REQUEST',
                        'GET_AI_SETTINGS_REQUEST', 'SAVE_AI_SETTINGS_REQUEST', 'GENERATE_STAGE_PREVIEW_REQUEST', 'GENERATE_AI_TEXT_REQUEST', 'LOAD_AI_LOGIC_PILOT_DRAFT_REQUEST', 'SAVE_AI_LOGIC_PILOT_DRAFT_REQUEST', 'LOAD_AI_LOGIC_PILOT_REPLACEMENT_CANDIDATES_REQUEST', 'PREVIEW_AI_LOGIC_PILOT_MANIFEST_REQUEST', 'APPLY_AI_LOGIC_PILOT_MANIFEST_REQUEST', 'INSPECT_AI_LOGIC_PILOT_APPLICATION_REQUEST', 'REPAIR_AI_LOGIC_PILOT_APPLICATION_REQUEST', 'GENERATE_LOGIC_PROPOSAL_REQUEST', 'GENERATE_STAGE_LOGIC_PROPOSAL_REQUEST', 'GENERATE_LOGIC_AUDIT_REQUEST', 'PREVIEW_GLOBAL_CODE_REFACTOR_REQUEST', 'APPLY_GLOBAL_CODE_REFACTOR_REQUEST', 'PREVIEW_STAGE_LOGIC_PROMPT_REQUEST',
                        'CHANGE_DETAIL_SORT_REQUEST', 'CHANGE_DETAIL_LEVEL_REQUEST', 'CHANGE_SORT_STAGE_REQUEST', 'MOVE_STAGE_REQUEST',
                        'CHANGE_PRICE_PRESET_REQUEST',
                        'CHANGE_OPTIONS_OPERATION', 'CHANGE_OPTIONS_MATERIAL', 'CHANGE_OPTIONS_EQUIPMENT',
                        'SAVE_CALC_LOGIC_REQUEST',
                        'CHECK_CALC_CONTRACT_REQUEST',
                        'RESOLVE_CALC_CONTRACT_REQUEST',
                        'PREVIEW_CATALOG_WRITE_REQUEST', 'APPLY_CATALOG_WRITE_REQUEST',
                        'CLEAR_OPTIONS_OPERATION', 'CLEAR_OPTIONS_MATERIAL', 'CLEAR_OPTIONS_EQUIPMENT',
                        'CLEAR_PRESET_REQUEST', 'SAVE_PRESET_GLOBALS_REQUEST', 'SAVE_GLOBAL_SYMBOLS_REQUEST', 'SAVE_GLOBAL_VALUES_REQUEST', 'SAVE_STAGE_GROUPS_REQUEST', 'CLOSE_REQUEST'
                    ]);
            }
        }

        /**
         * Отправка сообщения по протоколу pwrt-v1
         */
        sendPwrtMessage(type, payload, requestId, targetOrigin) {
            console.log('[BitrixBridge][DEBUG] sendPwrtMessage called', {
                type: type,
                requestId: requestId,
                targetOrigin: targetOrigin,
                hasPayload: !!payload,
                payloadStatus: payload ? payload.status : undefined,
                payloadHasItem: payload ? !!payload.item : undefined,
            });

            if (!this.iframeWindow) {
                console.log('[BitrixBridge][DEBUG] sendPwrtMessage FAILED - Iframe window not available');
                return;
            }

            const message = {
                protocol: MODULE_PROTOCOL,
                version: '1.0.0',
                source: MODULE_SOURCE,
                target: MODULE_TARGET,
                type: type,
                requestId: requestId,
                timestamp: Date.now(),
                payload: payload,
            };

            const origin = targetOrigin || this.targetOrigin || window.location.origin;
            const payloadSummary = this.buildPayloadSummary(type, payload);

            console.info('[TO_IFRAME]', {
                type: type,
                requestId: requestId,
                payloadSummary: payloadSummary,
                targetOrigin: origin,
            });

            if (type === 'SELECT_DONE') {
                console.info('[TO_IFRAME_SELECT_DONE]', message);
            }

            console.log('[BitrixBridge][DEBUG] sendPwrtMessage SENT', {
                type: type,
                requestId: requestId,
                origin: origin,
                messageKeys: Object.keys(message),
            });

            this.iframeWindow.postMessage(message, origin);
        }

        /**
         * Обновить свойство этапа в локальном this.initData без AJAX
         * Ищет этап в elementsStore.CALC_STAGES и обновляет указанное свойство
         * 
         * @param {number} stageId - ID этапа
         * @param {string} propertyCode - Код свойства (OPTIONS_OPERATION, OPTIONS_MATERIAL и т.д.)
         * @param {string} value - Новое значение
         */
        updateStagePropertyInInitData(stageId, propertyCode, value) {
            if (!this.initData || !this.initData.elementsStore) {
                console.warn('[BitrixBridge] updateStagePropertyInInitData: initData или elementsStore отсутствует');
                return;
            }
            
            const stages = this.initData.elementsStore.CALC_STAGES;
            if (!Array.isArray(stages)) {
                console.warn('[BitrixBridge] updateStagePropertyInInitData: CALC_STAGES не массив');
                return;
            }
            
            // Ищем этап по ID
            for (let i = 0; i < stages.length; i++) {
                const stage = stages[i];
                if (parseInt(stage.id, 10) === stageId || parseInt(stage.ID, 10) === stageId) {
                    // Обновляем свойство
                    if (!stage.properties) {
                        stage.properties = {};
                    }
                    
                    // Устанавливаем значение свойства
                    if (!stage.properties[propertyCode]) {
                        stage.properties[propertyCode] = {};
                    }
                    stage.properties[propertyCode].VALUE = value;
                    // The React transformer deliberately prefers the raw Bitrix
                    // value. Keep both representations coherent so a reset is
                    // visible immediately instead of only after page reload.
                    stage.properties[propertyCode]['~VALUE'] = value;
                    
                    console.log('[BitrixBridge] updateStagePropertyInInitData: обновлён этап', {
                        stageId: stageId,
                        propertyCode: propertyCode,
                        value: value ? value.substring(0, 50) + '...' : '(пусто)'
                    });
                    
                    return;
                }
            }
            
            console.warn('[BitrixBridge] updateStagePropertyInInitData: этап не найден', { stageId });
        }

        updateStagePropertyInInitDataWithRaw(stageId, propertyCode, value, rawValue) {
            if (!this.initData || !this.initData.elementsStore) {
                console.warn('[BitrixBridge] updateStagePropertyInInitDataWithRaw: initData или elementsStore отсутствует');
                return;
            }

            const stages = this.initData.elementsStore.CALC_STAGES;
            if (!Array.isArray(stages)) {
                console.warn('[BitrixBridge] updateStagePropertyInInitDataWithRaw: CALC_STAGES не массив');
                return;
            }

            for (let i = 0; i < stages.length; i++) {
                const stage = stages[i];
                if (parseInt(stage.id, 10) === stageId || parseInt(stage.ID, 10) === stageId) {
                    if (!stage.properties) {
                        stage.properties = {};
                    }

                    if (!stage.properties[propertyCode]) {
                        stage.properties[propertyCode] = {};
                    }

                    stage.properties[propertyCode].VALUE = value;
                    stage.properties[propertyCode]['~VALUE'] = rawValue;

                    console.log('[BitrixBridge] updateStagePropertyInInitDataWithRaw: обновлён этап', {
                        stageId: stageId,
                        propertyCode: propertyCode,
                        value: value ? value.substring(0, 50) + '...' : '(пусто)'
                    });

                    return;
                }
            }

            console.warn('[BitrixBridge] updateStagePropertyInInitDataWithRaw: этап не найден', { stageId });
        }

        updateStagePropertyInInitDataWithDescriptions(stageId, propertyCode, items) {
            if (!this.initData || !this.initData.elementsStore) {
                console.warn('[BitrixBridge] updateStagePropertyInInitDataWithDescriptions: initData или elementsStore отсутствует');
                return;
            }

            const stages = this.initData.elementsStore.CALC_STAGES;
            if (!Array.isArray(stages)) {
                console.warn('[BitrixBridge] updateStagePropertyInInitDataWithDescriptions: CALC_STAGES не массив');
                return;
            }

            const values = Array.isArray(items) ? items.map((item) => item.value ?? item.VALUE ?? '') : [];
            const descriptions = Array.isArray(items) ? items.map((item) => item.description ?? item.DESCRIPTION ?? '') : [];

            for (let i = 0; i < stages.length; i++) {
                const stage = stages[i];
                if (parseInt(stage.id, 10) === stageId || parseInt(stage.ID, 10) === stageId) {
                    if (!stage.properties) {
                        stage.properties = {};
                    }

                    if (!stage.properties[propertyCode]) {
                        stage.properties[propertyCode] = {};
                    }

                    stage.properties[propertyCode].VALUE = values;
                    stage.properties[propertyCode]['~VALUE'] = values;
                    stage.properties[propertyCode].DESCRIPTION = descriptions;

                    console.log('[BitrixBridge] updateStagePropertyInInitDataWithDescriptions: обновлён этап', {
                        stageId: stageId,
                        propertyCode: propertyCode,
                        count: values.length,
                    });

                    return;
                }
            }

            console.warn('[BitrixBridge] updateStagePropertyInInitDataWithDescriptions: этап не найден', { stageId });
        }

        updateSettingsPropertyInInitDataWithRaw(settingsId, propertyCode, value, rawValue) {
            if (!this.initData || !this.initData.elementsStore) {
                console.warn('[BitrixBridge] updateSettingsPropertyInInitDataWithRaw: initData или elementsStore отсутствует');
                return;
            }

            const settings = this.initData.elementsStore.CALC_SETTINGS;
            if (!Array.isArray(settings)) {
                console.warn('[BitrixBridge] updateSettingsPropertyInInitDataWithRaw: CALC_SETTINGS не массив');
                return;
            }

            for (let i = 0; i < settings.length; i++) {
                const setting = settings[i];
                if (parseInt(setting.id, 10) === settingsId || parseInt(setting.ID, 10) === settingsId) {
                    if (!setting.properties) {
                        setting.properties = {};
                    }

                    if (!setting.properties[propertyCode]) {
                        setting.properties[propertyCode] = {};
                    }

                    setting.properties[propertyCode].VALUE = value;
                    setting.properties[propertyCode]['~VALUE'] = rawValue;

                    let debugValue = '';
                    try {
                        debugValue = typeof value === 'string' ? value : JSON.stringify(value);
                    } catch (error) {
                        debugValue = String(value ?? '');
                    }

                    console.log('[BitrixBridge] updateSettingsPropertyInInitDataWithRaw: обновлены настройки', {
                        settingsId: settingsId,
                        propertyCode: propertyCode,
                        value: debugValue ? debugValue.substring(0, 50) + '...' : '(пусто)'
                    });

                    return;
                }
            }

            console.warn('[BitrixBridge] updateSettingsPropertyInInitDataWithRaw: настройки не найдены', { settingsId });
        }

        updateSettingsPropertyInInitDataWithDescriptions(settingsId, propertyCode, items) {
            if (!this.initData || !this.initData.elementsStore) {
                console.warn('[BitrixBridge] updateSettingsPropertyInInitDataWithDescriptions: initData или elementsStore отсутствует');
                return;
            }

            const settings = this.initData.elementsStore.CALC_SETTINGS;
            if (!Array.isArray(settings)) {
                console.warn('[BitrixBridge] updateSettingsPropertyInInitDataWithDescriptions: CALC_SETTINGS не массив');
                return;
            }

            const values = Array.isArray(items) ? items.map((item) => item.value ?? item.VALUE ?? '') : [];
            const descriptions = Array.isArray(items) ? items.map((item) => item.description ?? item.DESCRIPTION ?? '') : [];

            for (let i = 0; i < settings.length; i++) {
                const setting = settings[i];
                if (parseInt(setting.id, 10) === settingsId || parseInt(setting.ID, 10) === settingsId) {
                    if (!setting.properties) {
                        setting.properties = {};
                    }

                    if (!setting.properties[propertyCode]) {
                        setting.properties[propertyCode] = {};
                    }

                    setting.properties[propertyCode].VALUE = values;
                    setting.properties[propertyCode]['~VALUE'] = values;
                    setting.properties[propertyCode].DESCRIPTION = descriptions;

                    console.log('[BitrixBridge] updateSettingsPropertyInInitDataWithDescriptions: обновлены настройки', {
                        settingsId: settingsId,
                        propertyCode: propertyCode,
                        count: values.length,
                    });

                    return;
                }
            }

            console.warn('[BitrixBridge] updateSettingsPropertyInInitDataWithDescriptions: настройки не найдены', { settingsId });
        }

        escapeHtmlValue(value) {
            if (value === null || value === undefined) {
                return '';
            }

            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        buildPayloadSummary(type, payload) {
            if (type === 'REFRESH_RESULT' && Array.isArray(payload)) {
                return payload.map(function(item) {
                    const hasData = item && Array.isArray(item.data);
                    const dataCount = hasData ? item.data.length : 0;
                    return { iblockId: item ? (item.iblockId || null) : null, count: dataCount };
                });
            }

            if (payload && typeof payload === 'object') {
                if (payload.id) {
                    return { id: payload.id, productId: payload.productId || null };
                }
            }

            return null;
        }

        sendProcessMessage(level, message, extraPayload, requestId, origin) {
            const payload = Object.assign({}, extraPayload || {}, {
                status: level,
                message: message,
            });

            this.sendPwrtMessage('PROCESS_MESSAGE', payload, requestId, origin);
        }

        async handleSelectRequest(message, origin) {
            const requestPayload = message.payload || {};
            const iblockId = requestPayload.iblockId || null;
            const iblockType = requestPayload.iblockType || null;
            const lang = requestPayload.lang || null;

            const selectedIds = Array.isArray(requestPayload.selectedIds)
                ? requestPayload.selectedIds.map((id) => parseInt(id, 10)).filter((id) => id > 0)
                : await this.openElementSelectionDialog({
                    iblockId: iblockId,
                    iblockType: iblockType,
                    lang: lang,
                });

            await this.sendSelectDone({
                ids: selectedIds,
                iblockId: iblockId,
                iblockType: iblockType,
                lang: lang,
                requestId: message.requestId,
                origin: origin,
            });
        }

        async handleSelectFieldsRequest(message, origin) {
            const payload = message.payload || {};
            const stageId = parseInt(payload.stageId, 10) || 0;
            const presetId = parseInt(payload.presetId, 10) || 0;

            const selectedIds = Array.isArray(payload.customFieldIds)
                ? payload.customFieldIds.map(Number).filter(id => id > 0)
                : [];
            try {
                const selectResult = await this.fetchRefreshData([{
                    action: 'selectFields',
                    stageId,
                    presetId,
                    customFieldIds: selectedIds,
                    customFieldsValue: Array.isArray(payload.customFieldsValue) ? payload.customFieldsValue : [],
                    replace: payload.replace === true,
                }]);
                const selectPayload = Array.isArray(selectResult) ? selectResult[0] : null;
                if (this.applySemanticReadback(selectPayload)) {
                    this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                    return;
                }
                if (selectPayload?.initPayload) {
                    this.initData = selectPayload.initPayload;
                    this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                    return;
                }
                console.warn('[BitrixBridge] selectFields completed without INIT payload; data will be repaired on the next load');
            } catch (error) {
                console.error('[BitrixBridge] SELECT_FIELDS_REQUEST error:', error);
                this.sendPwrtMessage('ERROR', {
                    message: 'Ошибка выбора дополнительных полей',
                    details: error.message,
                }, message.requestId, origin);
            }
        }

        async handleSelectDetailsRequest(message, origin) {
            const requestPayload = message.payload || {};
            const binding = requestPayload.binding || false;
            
            // Получить iblockId для CALC_DETAILS из initData
            const calcDetails = this.findIblockByCode('CALC_DETAILS');
            const iblockId = calcDetails?.id || requestPayload.iblockId || null;
            const iblockType = calcDetails?.type || requestPayload.iblockType || null;
            const lang = requestPayload.lang || (this.initData?.lang) || null;

            const selectedIds = Array.isArray(requestPayload.selectedIds)
                ? requestPayload.selectedIds.map((id) => parseInt(id, 10)).filter((id) => id > 0)
                : await this.openElementSelectionDialog({
                    iblockId: iblockId,
                    iblockType: iblockType,
                    lang: lang,
                });

            // Режим тишины - 0 деталей выбрано
            if (!selectedIds || selectedIds.length === 0) {
                console.log('[BitrixBridge] No details selected, silent mode');
                return;
            }

            try {
                // Получаем presetId и существующую деталь
                const presetId = this.initData?.preset?.id;
                const existingDetailId = 0;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                // Вызываем обогащение пресета
                const enrichResult = await this.enrichPreset({
                    presetId: presetId,
                    detailIds: selectedIds,
                    binding: binding,
                    existingDetailId: existingDetailId,
                });

                if (enrichResult.success && enrichResult.data) {
                    // Обновляем локальный initData
                    this.initData = enrichResult.data;
                    
                    // Отправляем INIT message вместо SELECT_DETAILS_RESPONSE
                    this.sendPwrtMessage('INIT', enrichResult.data, message.requestId, origin);
                } else {
                    throw new Error(enrichResult.message || 'Ошибка обогащения пресета');
                }
            } catch (error) {
                console.error('[BitrixBridge] Error during preset enrichment', error);
                this.sendPwrtMessage('ERROR', {
                    message: 'Ошибка обогащения пресета',
                    details: error.message,
                }, message.requestId, origin);
            }
        }

        async handleRefreshRequest(message, origin) {
            try {
                const payload = Array.isArray(message.payload) ? message.payload : [];
                const result = await this.fetchRefreshData(payload);

                this.sendPwrtMessage('REFRESH_RESULT', result, message.requestId, origin);
            } catch (error) {
                console.error('[CalcIntegration] Error during refresh request', error);
                this.sendPwrtMessage('REFRESH_RESULT', [], message.requestId, origin);
            }
        }

        handleControlCenterFormEditorResponse(event) {
            const pending = this.pendingFormEditorRequest;
            if (!pending || window.parent === window
                || event.source !== window.parent || event.origin !== window.location.origin) {
                return false;
            }
            const message = event.data;
            if (!message || typeof message !== 'object' || Array.isArray(message)
                || message.protocol !== MODULE_PROTOCOL
                || message.source !== MODULE_SOURCE
                || message.target !== MODULE_TARGET
                || message.requestId !== pending.requestId
                || (message.type !== 'CONTROL_CENTER_FORM_EDITOR_OPENED'
                    && message.type !== 'CONTROL_CENTER_FORM_EDITOR_ERROR')) {
                return false;
            }
            const payload = message.payload;
            if (!payload || typeof payload !== 'object' || Array.isArray(payload)
                || payload.editorInstanceId !== this.config.editorInstanceId) {
                return false;
            }
            if (pending.timeoutId !== null && typeof window.clearTimeout === 'function') {
                window.clearTimeout(pending.timeoutId);
            }
            this.pendingFormEditorRequest = null;
            if (message.type === 'CONTROL_CENTER_FORM_EDITOR_OPENED') {
                this.sendPwrtMessage('RESPONSE', {
                    requestType: 'OPEN_FORM_EDITOR_REQUEST',
                    status: 'success',
                }, pending.childRequestId, pending.childOrigin);
            } else {
                this.sendPwrtMessage('ERROR', {
                    message: 'Не удалось открыть редактор полей формы',
                    details: typeof payload.message === 'string' && payload.message !== ''
                        ? payload.message : 'Центр управления отклонил запрос.',
                }, pending.childRequestId, pending.childOrigin);
            }
            return true;
        }

        handleOpenFormEditorRequest(message, origin) {
            try {
                const originalPresetId = Number(this.config.versionOriginalPresetId || this.config.presetId || 0);
                if (!Number.isSafeInteger(originalPresetId) || originalPresetId <= 0) {
                    throw new Error('Редактор не содержит идентификатор калькулятора.');
                }
                const versionId = String(this.config.versionId || '');
                if (versionId !== '' && !/^v_[a-f0-9]{16,40}$/.test(versionId)) {
                    throw new Error('Редактор не содержит корректную версию формы.');
                }
                const editorInstanceId = String(this.config.editorInstanceId || '');
                if (!/^[a-f0-9]{32}$/.test(editorInstanceId) || window.parent === window) {
                    throw new Error('Редактор формы доступен только из Центра управления.');
                }
                if (this.pendingFormEditorRequest) {
                    throw new Error('Редактор полей формы уже открывается.');
                }
                const parentRequestId = 'form_workspace_' + Date.now() + '_'
                    + Math.random().toString(36).slice(2, 8);
                const timeoutId = typeof window.setTimeout === 'function'
                    ? window.setTimeout(() => {
                        const pending = this.pendingFormEditorRequest;
                        if (!pending || pending.requestId !== parentRequestId) return;
                        this.pendingFormEditorRequest = null;
                        this.sendPwrtMessage('ERROR', {
                            message: 'Не удалось открыть редактор полей формы',
                            details: 'Центр управления не подтвердил открытие редактора.',
                        }, pending.childRequestId, pending.childOrigin);
                    }, 18000)
                    : null;
                this.pendingFormEditorRequest = {
                    requestId: parentRequestId,
                    childRequestId: message.requestId,
                    childOrigin: origin,
                    timeoutId: timeoutId,
                };
                // Reuse the already loaded control-center application behind the
                // calculation editor. Starting a second complete control center in
                // a nested Bitrix SidePanel duplicates the React workspace and can
                // exhaust the Edge renderer on large calculator payloads.
                window.parent.postMessage({
                    protocol: MODULE_PROTOCOL,
                    source: MODULE_TARGET,
                    target: MODULE_SOURCE,
                    type: 'OPEN_CONTROL_CENTER_FORM_EDITOR',
                    requestId: parentRequestId,
                    payload: {
                        editorInstanceId: editorInstanceId,
                        presetId: originalPresetId,
                        versionId: versionId,
                    },
                    timestamp: Date.now(),
                }, window.location.origin);
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    message: 'Не удалось открыть редактор полей формы',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        async refreshVersionLaunchContext() {
            if (this.config.versionMode !== 'edit' && this.config.versionMode !== 'readonly') {
                return;
            }
            const originalPresetId = Number(this.config.versionOriginalPresetId || 0);
            if (!Number.isSafeInteger(originalPresetId) || originalPresetId <= 0
                || !/^v_[a-f0-9]{16,40}$/.test(this.config.versionId)) {
                throw new Error('Редактор не содержит точный контекст версии.');
            }
            const body = new URLSearchParams();
            body.set('sessid', this.config.sessid);
            body.set('payload', JSON.stringify({
                action: 'version_logic_launch',
                presetId: originalPresetId,
                versionId: this.config.versionId,
                mode: this.config.versionMode,
                foundationMode: '',
            }));
            const response = await fetch(this.config.versionAjaxEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: body.toString(),
                cache: 'no-store',
            });
            const result = await response.json().catch(function () { return null; });
            const data = result && result.success === true ? result.data : null;
            const focusPresetId = Number(data && data.focusPresetId || 0);
            const returnedPresetId = Number(data && data.presetId || 0);
            if (!response.ok || !data
                || returnedPresetId !== originalPresetId
                || String(data.versionId || '') !== this.config.versionId
                || String(data.mode || '') !== this.config.versionMode
                || !Number.isSafeInteger(focusPresetId) || focusPresetId <= 0
                || !/^[a-f0-9]{64}$/.test(String(data.contentHash || ''))
                || !/^[a-f0-9]{64}$/.test(String(data.logicHash || ''))) {
                throw new Error(result && (result.error || result.message)
                    ? String(result.error || result.message)
                    : 'Сервер не вернул актуальный контекст версии.');
            }
            this.config.presetId = focusPresetId;
            this.config.versionContentHash = String(data.contentHash);
            this.config.versionLogicHash = String(data.logicHash);
        }

        async handleRefreshEditorContextRequest(message, origin) {
            try {
                await this.refreshVersionLaunchContext();
                const initData = await this.fetchInitData();
                this.initData = initData;
                this.sendPwrtMessage('INIT', initData, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    message: 'Не удалось обновить контекст редактора',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        async handleAddOfferRequest(message, origin) {
            const offersIblock = this.findIblockByCode('OFFERS');
            const offersIblockId = offersIblock ? offersIblock.id : null;
            const iblockType = offersIblock ? offersIblock.type : null;

            const selectedIds = await this.openElementSelectionDialog({
                iblockId: offersIblockId,
                iblockType: iblockType,
                lang: (this.initData && this.initData.lang) ? this.initData.lang : null,
            });

            await this.sendSelectDone({
                ids: selectedIds,
                iblockId: offersIblockId,
                iblockType: iblockType,
                lang: (this.initData && this.initData.lang) ? this.initData.lang : null,
                requestId: message.requestId,
                origin: origin,
            });
        }







        /**
         * Обработка запроса создания новой детали
         * Создаёт деталь и обогащает пресет
         */
        async handleAddNewDetailRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleAddNewDetailRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const name = payload.name || '';

            try {
                // Получаем presetId и существующую деталь из initData
                const presetId = this.initData?.preset?.id;
                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                // Создаём новую пустую деталь. Этап появляется только после
                // явного действия технолога в новой колонке.
                const createResult = await this.fetchRefreshData([
                    {
                        action: 'addNewDetail',
                        presetId: presetId,
                        name: name,
                    }
                ]);

                console.log('[BitrixBridge][DEBUG] Detail created:', {
                    isArray: Array.isArray(createResult),
                    length: Array.isArray(createResult) ? createResult.length : 0,
                    firstItem: Array.isArray(createResult) && createResult[0] ? createResult[0] : null,
                });

                const createResponsePayload = (Array.isArray(createResult) && createResult[0])
                    ? createResult[0]
                    : { status: 'error', message: 'Empty response' };

                if (createResponsePayload.status !== 'ok') {
                    throw new Error(createResponsePayload.message || 'Не удалось создать деталь');
                }

                const newDetailId = createResponsePayload.detail?.id;
                if (!newDetailId) {
                    throw new Error('ID новой детали не получен');
                }

                const initPayload = createResponsePayload.initPayload;
                if (initPayload) {
                    // The create request already appended the new root and
                    // rebuilt the complete ordered product topology.
                    this.initData = initPayload;
                    
                    // Отправляем INIT message вместо ADD_DETAIL_RESPONSE
                    this.sendPwrtMessage('INIT', initPayload, message.requestId, origin);
                    
                    console.log('[BitrixBridge][DEBUG] handleAddNewDetailRequest END - success, INIT sent');
                } else {
                    throw new Error('Сервер не вернул обновлённую структуру пресета');
                }

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleAddNewDetailRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ERROR',
                    {
                        message: 'Ошибка создания детали',
                        details: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        async handleCloneDetailRequest(message, origin) {
            const payload = message.payload || {};
            const detailId = parseInt(payload.detailId, 10) || 0;
            const presetId = parseInt(payload.presetId, 10) || 0;
            if (!detailId || !presetId) {
                return;
            }

            try {
                const result = await this.fetchRefreshData([{
                    action: 'cloneDetail',
                    detailId,
                    presetId,
                }]);
                const responsePayload = (Array.isArray(result) && result[0]) ? result[0] : null;
                if (!responsePayload || responsePayload.status !== 'ok') {
                    throw new Error(responsePayload?.message || 'Не удалось клонировать деталь');
                }

                if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                    this.sendPwrtMessage('INIT', responsePayload.initPayload, message.requestId, origin);
                } else {
                    throw new Error('Сервер не вернул обновлённую структуру пресета');
                }
            } catch (error) {
                console.error('[BitrixBridge] CLONE_DETAIL_REQUEST error:', error);
                this.sendPwrtMessage('ERROR', { message: 'Ошибка клонирования детали', details: error.message }, message.requestId, origin);
            }
        }

        async handleSaveSettingsEquipmentRequest(message, origin) {
            const payload = message.payload || {};
            const equipmentId = parseInt(payload.eqipmentId || payload.equipmentId, 10) || 0;
            const name = String(payload.name || '').trim();
            const properties = payload.properties || {};
            const previewText = String(payload.previewText || '').trim();
            const create = payload.create === true;
            if (!create && !equipmentId) {
                this.sendPwrtMessage('SAVE_SETTINGS_EQUIPMENT_RESPONSE', {
                    status: 'error',
                    message: 'Не указано оборудование',
                }, message.requestId, origin);
                return;
            }

            try {
                const result = await this.fetchRefreshData([{
                    action: 'saveSettingsEquipment',
                    equipmentId,
                    create,
                    sectionId: parseInt(payload.sectionId, 10) || 0,
                    name,
                    previewText,
                    detailText: String(payload.detailText || ''),
                    image: payload.image || null,
                    catalog: payload.catalog || {},
                    properties,
                }]);
                const responsePayload = Array.isArray(result) && result[0]
                    ? result[0]
                    : { status: 'error', message: 'Пустой ответ сохранения оборудования' };
                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось сохранить оборудование');
                }

                const savedEquipmentId = parseInt(responsePayload.equipmentId, 10) || equipmentId;
                const equipmentItems = this.initData && this.initData.elementsStore
                    ? this.initData.elementsStore.CALC_EQUIPMENT
                    : null;
                if (Array.isArray(equipmentItems)) {
                    let equipment = equipmentItems.find((item) => Number(item.id) === savedEquipmentId);
                    if (!equipment && responsePayload.element) {
                        equipment = responsePayload.element;
                        equipmentItems.push(equipment);
                    }
                    if (equipment) {
                        if (responsePayload.name) {
                            equipment.name = responsePayload.name;
                        }
                        equipment.previewText = responsePayload.previewText || '';
                        equipment.detailText = responsePayload.detailText || '';
                        if (responsePayload.previewPicture) equipment.previewPicture = responsePayload.previewPicture;
                        if (responsePayload.detailPicture) equipment.detailPicture = responsePayload.detailPicture;
                        if (responsePayload.catalog) equipment.catalog = responsePayload.catalog;
                        equipment.properties = equipment.properties || {};
                        Object.entries(responsePayload.properties || {}).forEach(([code, property]) => {
                            equipment.properties[code] = {
                                ...(equipment.properties[code] || {}),
                                ...property,
                            };
                        });
                    }
                }

                this.sendPwrtMessage('SAVE_SETTINGS_EQUIPMENT_RESPONSE', {
                    status: 'ok',
                    equipmentId: savedEquipmentId,
                }, message.requestId, origin);
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                console.error('[BitrixBridge] SAVE_SETTINGS_EQUIPMENT_REQUEST error:', error);
                this.sendPwrtMessage('SAVE_SETTINGS_EQUIPMENT_RESPONSE', {
                    status: 'error',
                    equipmentId: equipmentId,
                    message: error && error.message ? error.message : 'Не удалось сохранить оборудование',
                }, message.requestId, origin);
            }
        }

        async handleChangeProductTypeRequest(message, origin) {
            const payload = message.payload || {};
            const presetId = parseInt(payload.presetId, 10) || 0;
            const mode = payload.mode === 'complex' ? 'complex' : 'simple';

            if (!presetId) {
                this.sendPwrtMessage('ERROR', {
                    message: 'Не удалось изменить тип продукта',
                    details: 'Не указан ID пресета',
                }, message.requestId, origin);
                return;
            }

            try {
                const result = await this.fetchRefreshData([{
                    action: 'changeProductType',
                    presetId: presetId,
                    mode: mode,
                    basisDetailId: parseInt(payload.basisDetailId, 10) || 0,
                    deleteOthers: payload.deleteOthers === true,
                    siteId: this.config.siteId || SITE_ID,
                }]);
                const responsePayload = Array.isArray(result) ? result[0] : null;
                if (!responsePayload || responsePayload.status !== 'ok') {
                    throw new Error(responsePayload && responsePayload.message
                        ? responsePayload.message
                        : 'Не удалось изменить тип продукта');
                }

                if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                    this.sendPwrtMessage('INIT', responsePayload.initPayload, message.requestId, origin);
                } else {
                    this.sendPwrtMessage('RESPONSE', {
                        requestType: message.type,
                        success: true,
                    }, message.requestId, origin);
                }
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    message: 'Не удалось изменить тип продукта',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        async handleCreateCustomFieldRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'createCustomField',
                    stageId: parseInt(payload.stageId, 10) || 0,
                    presetId: parseInt(payload.presetId, 10) || 0,
                    field: payload.field || {},
                }]);
                const response = Array.isArray(result) ? result[0] : null;
                if (!response || response.status !== 'ok') {
                    throw new Error(response?.message || 'Не удалось создать дополнительный параметр');
                }
                if (this.applySemanticReadback(response)) {
                    this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                } else if (response.initPayload) {
                    this.initData = response.initPayload;
                    this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                }
                this.sendPwrtMessage('CREATE_CUSTOM_FIELD_RESPONSE', response, message.requestId, origin);
            } catch (error) {
                console.error('[BitrixBridge] CREATE_CUSTOM_FIELD_REQUEST error:', error);
                this.sendPwrtMessage('CREATE_CUSTOM_FIELD_RESPONSE', {
                    status: 'error',
                    message: error && error.message ? error.message : 'Не удалось создать дополнительный параметр',
                }, message.requestId, origin);
            }
        }

        async handleSavePresetGlobalsRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'savePresetGlobals',
                    presetId: Number(payload.presetId || 0),
                    variables: Array.isArray(payload.variables) ? payload.variables : [],
                    constants: Array.isArray(payload.constants) ? payload.constants : [],
                }]);
                const responsePayload = Array.isArray(result) && result[0] ? result[0] : { status: 'error', message: 'Пустой ответ сервера' };
                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось сохранить глобальные значения');
                }
                if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                    this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                } else {
                    this.sendPwrtMessage('RESPONSE', responsePayload, message.requestId, origin);
                }
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    message: 'Не удалось сохранить глобальные значения',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        async handleChangeStageNameRequest(message, origin) {
            const payload = message.payload || {};
            const stageId = parseInt(payload.stageId, 10) || 0;
            const name = payload.name || '';
            const previewText = payload.previewText || '';
            if (!stageId || !name) {
                return;
            }

            try {
                const result = await this.fetchRefreshData([{ action: 'changeStageName', stageId, name, previewText }]);
                const response = Array.isArray(result) ? result[0] : null;
                if (!response || response.status !== 'ok') {
                    throw new Error(response && response.message ? response.message : 'Не удалось сохранить этап');
                }
                const stage = this.initData && this.initData.elementsStore && Array.isArray(this.initData.elementsStore.CALC_STAGES)
                    ? this.initData.elementsStore.CALC_STAGES.find(item => Number(item.id) === stageId)
                    : null;
                if (stage) {
                    stage.name = name;
                    stage.previewText = previewText;
                }
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                console.error('[BitrixBridge] CHANGE_STAGE_NAME_REQUEST error:', error);
                this.sendPwrtMessage('ERROR', { message: error && error.message ? error.message : 'Не удалось сохранить этап' }, message.requestId, origin);
            }
        }

        async handleChangeEntityMetaRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'changeEntityMeta',
                    entityType: payload.entityType,
                    entityId: Number(payload.entityId || 0),
                    name: payload.name || '',
                    previewText: payload.previewText || '',
                }]);
                const response = Array.isArray(result) ? result[0] : null;
                if (!response || response.status !== 'ok') throw new Error(response && response.message ? response.message : 'Не удалось сохранить данные');
                if (payload.entityType === 'detail' && this.initData && this.initData.elementsStore && Array.isArray(this.initData.elementsStore.CALC_DETAILS)) {
                    const item = this.initData.elementsStore.CALC_DETAILS.find(entity => Number(entity.id) === Number(payload.entityId));
                    if (item) { item.name = payload.name; item.previewText = payload.previewText || ''; }
                }
                if (payload.entityType === 'preset' && this.initData && this.initData.preset) {
                    this.initData.preset.name = payload.name;
                    this.initData.preset.previewText = payload.previewText || '';
                }
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('ERROR', { message: error && error.message ? error.message : 'Не удалось сохранить данные' }, message.requestId, origin);
            }
        }

        async handleGetAiSettingsRequest(message, origin) {
            try {
                const result = await this.fetchRefreshData([{ action: 'getAiSettings' }]);
                this.sendPwrtMessage('AI_SETTINGS_RESPONSE', Array.isArray(result) ? result[0] : { status: 'error' }, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('AI_SETTINGS_RESPONSE', { status: 'error', message: error && error.message ? error.message : 'Не удалось загрузить настройки AI' }, message.requestId, origin);
            }
        }

        async handleSaveAiSettingsRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'saveAiSettings',
                    apiKey: payload.apiKey || '',
                    templates: Array.isArray(payload.templates) ? payload.templates : [],
                }]);
                this.sendPwrtMessage('AI_SETTINGS_RESPONSE', Array.isArray(result) ? result[0] : { status: 'error' }, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('AI_SETTINGS_RESPONSE', { status: 'error', message: error && error.message ? error.message : 'Не удалось сохранить настройки AI' }, message.requestId, origin);
            }
        }

        async handleGenerateStagePreviewRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'generateStagePreview',
                    templateId: payload.templateId || '',
                    context: payload.context || {},
                }]);
                this.sendPwrtMessage('AI_GENERATE_RESPONSE', Array.isArray(result) ? result[0] : { status: 'error' }, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('AI_GENERATE_RESPONSE', { status: 'error', message: error && error.message ? error.message : 'Не удалось сгенерировать описание' }, message.requestId, origin);
            }
        }

        async handleGenerateAiTextRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'generateAiText',
                    templateId: payload.templateId || '',
                    zone: payload.zone || '',
                    prompt: payload.prompt || '',
                    context: payload.context || {},
                }]);
                this.sendPwrtMessage('AI_GENERATE_RESPONSE', Array.isArray(result) ? result[0] : { status: 'error' }, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('AI_GENERATE_RESPONSE', { status: 'error', message: error && error.message ? error.message : 'Не удалось сгенерировать описание' }, message.requestId, origin);
            }
        }

        async handleGenerateLogicProposalRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'generateLogicProposal',
                    request: payload,
                }]);
                this.sendPwrtMessage(
                    'AI_LOGIC_PROPOSAL_RESPONSE',
                    Array.isArray(result) ? result[0] : { status: 'error', message: 'AI не вернул предложение' },
                    message.requestId,
                    origin
                );
            } catch (error) {
                this.sendPwrtMessage('AI_LOGIC_PROPOSAL_RESPONSE', {
                    status: 'error',
                    message: error && error.message ? error.message : 'Не удалось сформировать предложение формулы',
                }, message.requestId, origin);
            }
        }

        async handleGenerateStageLogicProposalRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'generateStageLogicProposal',
                    request: payload,
                }]);
                this.sendPwrtMessage(
                    'AI_STAGE_LOGIC_PROPOSAL_RESPONSE',
                    Array.isArray(result) ? result[0] : { status: 'error', message: 'AI не вернул проект логики этапа' },
                    message.requestId,
                    origin
                );
            } catch (error) {
                this.sendPwrtMessage('AI_STAGE_LOGIC_PROPOSAL_RESPONSE', {
                    status: 'error',
                    message: error && error.message ? error.message : 'Не удалось сформировать проект логики этапа',
                }, message.requestId, origin);
            }
        }

        applySemanticReadback(responsePayload) {
            const readback = responsePayload && responsePayload.semanticReadback;
            if (!readback || typeof readback !== 'object' || Array.isArray(readback) || !this.initData) {
                return false;
            }
            for (const key of ['preset', 'elementsStore', 'globalSymbols']) {
                if (Object.prototype.hasOwnProperty.call(readback, key)) {
                    this.initData[key] = readback[key];
                }
            }
            if (typeof responsePayload.semanticRevision === 'string') {
                this.initData.semanticRevision = responsePayload.semanticRevision;
            }
            return true;
        }

        async handleLoadAiLogicPilotDraftRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'loadAiLogicPilotDraft',
                    presetId: Number(payload.presetId || 0),
                    versionKey: String(payload.versionKey || ''),
                    baseCompileHash: String(payload.baseCompileHash || ''),
                    expectedContentHash: String(this.config.versionContentHash || payload.expectedContentHash || ''),
                }]);
                this.sendPwrtMessage('AI_LOGIC_PILOT_DRAFT_RESPONSE', Array.isArray(result) ? result[0] : { status: 'error' }, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('AI_LOGIC_PILOT_DRAFT_RESPONSE', { status: 'error', message: error && error.message ? error.message : 'Не удалось загрузить AI-черновик' }, message.requestId, origin);
            }
        }

        async handleSaveAiLogicPilotDraftRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'saveAiLogicPilotDraft',
                    presetId: Number(payload.presetId || 0),
                    versionKey: String(payload.versionKey || ''),
                    baseCompileHash: String(payload.baseCompileHash || ''),
                    expectedContentHash: String(this.config.versionContentHash || payload.expectedContentHash || ''),
                    draft: payload.draft || null,
                    decisions: payload.decisions || {},
                    replacements: payload.replacements || {},
                    expectedDraftRevision: Number(payload.expectedDraftRevision || 0),
                    clientRevision: Number(payload.clientRevision || 0),
                }]);
                this.sendPwrtMessage('AI_LOGIC_PILOT_DRAFT_RESPONSE', Array.isArray(result) ? result[0] : { status: 'error' }, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('AI_LOGIC_PILOT_DRAFT_RESPONSE', { status: 'error', message: error && error.message ? error.message : 'Не удалось сохранить AI-черновик' }, message.requestId, origin);
            }
        }

        async handleLoadAiLogicPilotReplacementCandidatesRequest(message, origin) {
            await this.handleAiLogicPilotMaterializationRequest(message, origin, 'loadAiLogicPilotReplacementCandidates', 'AI_LOGIC_PILOT_REPLACEMENT_CANDIDATES_RESPONSE');
        }

        async handlePreviewAiLogicPilotManifestRequest(message, origin) {
            await this.handleAiLogicPilotMaterializationRequest(message, origin, 'previewAiLogicPilotManifest', 'AI_LOGIC_PILOT_MANIFEST_PREVIEW_RESPONSE');
        }

        async handleApplyAiLogicPilotManifestRequest(message, origin) {
            await this.handleAiLogicPilotMaterializationRequest(message, origin, 'applyAiLogicPilotManifest', 'AI_LOGIC_PILOT_APPLY_RESPONSE');
        }

        async handleAiLogicPilotMaterializationRequest(message, origin, action, responseType) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([Object.assign({}, payload, {
                    action,
                    presetId: Number(payload.presetId || 0),
                    versionId: String(payload.versionId || payload.versionKey || ''),
                    versionKey: String(payload.versionKey || payload.versionId || ''),
                    baseCompileHash: String(payload.baseCompileHash || ''),
                    expectedContentHash: String(this.config.versionContentHash || payload.expectedContentHash || ''),
                    expectedDraftRevision: Number(payload.expectedDraftRevision || payload.draftRevision || 0),
                })]);
                this.sendPwrtMessage(responseType, Array.isArray(result) ? result[0] : { status: 'error' }, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage(responseType, { status: 'error', message: error && error.message ? error.message : 'Не удалось обработать AI-пилот' }, message.requestId, origin);
            }
        }

        async handleSavePriceSettingsPresetRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'savePriceSettingsPreset',
                    name: payload.name || '',
                    mode: payload.mode || 'markup',
                    prices: Array.isArray(payload.prices) ? payload.prices : [],
                }]);
                const response = Array.isArray(result) ? result[0] : null;
                if (!response || response.status !== 'ok') {
                    throw new Error(response && response.message ? response.message : 'Не удалось сохранить пресет отпускных цен');
                }
                this.initData.context = this.initData.context || {};
                this.initData.context.priceSettingsPresets = response.presets || [];
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    message: error && error.message ? error.message : 'Не удалось сохранить пресет отпускных цен',
                }, message.requestId, origin);
            }
        }

        async handleRenamePriceSettingsPresetRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'renamePriceSettingsPreset',
                    id: payload.id || '',
                    name: payload.name || '',
                }]);
                const response = Array.isArray(result) ? result[0] : null;
                if (!response || response.status !== 'ok') {
                    throw new Error(response && response.message ? response.message : 'Не удалось переименовать шаблон отпускных цен');
                }
                this.initData.context = this.initData.context || {};
                this.initData.context.priceSettingsPresets = response.presets || [];
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    message: error && error.message ? error.message : 'Не удалось переименовать шаблон отпускных цен',
                }, message.requestId, origin);
            }
        }

        async handleDeletePriceSettingsPresetRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'deletePriceSettingsPreset',
                    id: payload.id || '',
                }]);
                const response = Array.isArray(result) ? result[0] : null;
                if (!response || response.status !== 'ok') {
                    throw new Error(response && response.message ? response.message : 'Не удалось удалить шаблон отпускных цен');
                }
                this.initData.context = this.initData.context || {};
                this.initData.context.priceSettingsPresets = response.presets || [];
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    message: error && error.message ? error.message : 'Не удалось удалить шаблон отпускных цен',
                }, message.requestId, origin);
            }
        }

        async handleCloneSelectedDetailsRequest(message, origin) {
            const payload = message.payload || {};
            const detailIds = Array.from(new Set((Array.isArray(payload.detailIds) ? payload.detailIds : [])
                .map((id) => parseInt(id, 10) || 0)
                .filter((id) => id > 0)));
            const presetId = parseInt(payload.presetId, 10) || 0;
            if (detailIds.length === 0 || !presetId) {
                this.sendPwrtMessage('ERROR', {
                    message: 'Не выбраны детали для клонирования',
                }, message.requestId, origin);
                return;
            }

            try {
                const result = await this.fetchRefreshData([{
                    action: 'cloneDetails',
                    detailIds,
                    presetId,
                }]);
                const responsePayload = (Array.isArray(result) && result[0]) ? result[0] : null;
                if (!responsePayload || responsePayload.status !== 'ok') {
                    throw new Error(responsePayload?.message || 'Не удалось клонировать выбранные детали');
                }
                if (!responsePayload.initPayload) {
                    throw new Error('Сервер не вернул обновлённую структуру пресета');
                }
                this.initData = responsePayload.initPayload;
                this.sendPwrtMessage('INIT', responsePayload.initPayload, message.requestId, origin);
            } catch (error) {
                console.error('[BitrixBridge] CLONE_SELECTED_DETAILS_REQUEST error:', error);
                this.sendPwrtMessage('ERROR', {
                    message: 'Ошибка клонирования выбранных деталей',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        async handleGenerateLogicAuditRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'generateLogicAudit',
                    request: payload,
                }]);
                this.sendPwrtMessage(
                    'AI_LOGIC_AUDIT_RESPONSE',
                    Array.isArray(result) ? result[0] : { status: 'error', message: 'AI не вернул анализ' },
                    message.requestId,
                    origin
                );
            } catch (error) {
                this.sendPwrtMessage('AI_LOGIC_AUDIT_RESPONSE', {
                    status: 'error',
                    message: error && error.message ? error.message : 'Не удалось выполнить AI-анализ',
                }, message.requestId, origin);
            }
        }

        async handlePreviewGlobalCodeRefactorRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'previewGlobalCodeRefactor',
                    presetId: Number(payload.presetId || this.config?.presetId || this.initData?.preset?.id || 0),
                    renames: Array.isArray(payload.renames) ? payload.renames : [],
                }]);
                this.sendPwrtMessage(
                    'GLOBAL_CODE_REFACTOR_PREVIEW_RESPONSE',
                    Array.isArray(result) ? result[0] : { status: 'error', message: 'Сервер не вернул предварительную проверку' },
                    message.requestId,
                    origin
                );
            } catch (error) {
                this.sendPwrtMessage('GLOBAL_CODE_REFACTOR_PREVIEW_RESPONSE', {
                    status: 'error',
                    message: error && error.message ? error.message : 'Не удалось проверить влияние переименования',
                }, message.requestId, origin);
            }
        }

        async handleApplyGlobalCodeRefactorRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'applyGlobalCodeRefactor',
                    presetId: Number(payload.presetId || this.config?.presetId || this.initData?.preset?.id || 0),
                    renames: Array.isArray(payload.renames) ? payload.renames : [],
                    fingerprint: String(payload.fingerprint || ''),
                    expectedGlobalRevision: Number(payload.expectedGlobalRevision),
                }]);
                const response = Array.isArray(result) ? result[0] : null;
                if (!response || response.status !== 'ok') {
                    throw new Error(response?.message || 'Сервер не применил безопасное переименование');
                }
                this.initData = await this.fetchInitData();
                this.sendPwrtMessage('GLOBAL_CODE_REFACTOR_APPLIED', response, message.requestId, origin);
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('GLOBAL_CODE_REFACTOR_APPLIED', {
                    status: 'error',
                    message: error && error.message ? error.message : 'Не удалось применить безопасное переименование',
                }, message.requestId, origin);
            }
        }

        async handlePreviewStageLogicPromptRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'previewStageLogicPrompt',
                    request: payload,
                }]);
                this.sendPwrtMessage(
                    'STAGE_LOGIC_PROMPT_PREVIEW_RESPONSE',
                    Array.isArray(result) ? result[0] : { status: 'error', message: 'Сервер не вернул итоговый промпт' },
                    message.requestId,
                    origin
                );
            } catch (error) {
                this.sendPwrtMessage('STAGE_LOGIC_PROMPT_PREVIEW_RESPONSE', {
                    status: 'error',
                    message: error && error.message ? error.message : 'Не удалось подготовить итоговый промпт',
                }, message.requestId, origin);
            }
        }

        async handleGetAiBaseProductsRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'getAiBaseProducts',
                    mode: payload.mode === 'details' ? 'details' : 'tree',
                    productIds: Array.isArray(payload.productIds) ? payload.productIds.map(Number) : [],
                }]);
                this.sendPwrtMessage(
                    'AI_BASE_PRODUCTS_RESPONSE',
                    Array.isArray(result) ? result[0] : { status: 'error' },
                    message.requestId,
                    origin
                );
            } catch (error) {
                this.sendPwrtMessage('AI_BASE_PRODUCTS_RESPONSE', {
                    status: 'error',
                    message: error && error.message ? error.message : 'Не удалось загрузить базисные продукты',
                }, message.requestId, origin);
            }
        }

        async handleSaveAiCalculatorContextRequest(message, origin) {
            const payload = message.payload || {};
            const settingsId = Number(payload.settingsId || 0);
            try {
                const result = await this.fetchRefreshData([{
                    action: 'saveAiCalculatorContext',
                    settingsId: settingsId,
                    context: payload.context || {},
                }]);
                const response = Array.isArray(result) ? result[0] : { status: 'error' };
                if (response && response.status === 'ok') {
                    const json = JSON.stringify(response.context || {});
                    this.updateSettingsPropertyInInitDataWithRaw(settingsId, 'AI_CONTEXT_JSON', json, json);
                }
                this.sendPwrtMessage('AI_CALCULATOR_CONTEXT_RESPONSE', response, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('AI_CALCULATOR_CONTEXT_RESPONSE', {
                    status: 'error',
                    message: error && error.message ? error.message : 'Не удалось сохранить контекст AI-конструктора',
                }, message.requestId, origin);
            }
        }

        async handleGetCatalogEntityMetaRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{ action: 'getCatalogEntityMeta', entityType: payload.entityType, entityId: Number(payload.entityId || 0) }]);
                this.sendPwrtMessage('CATALOG_ENTITY_META_RESPONSE', Array.isArray(result) ? result[0] : { status: 'error' }, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('CATALOG_ENTITY_META_RESPONSE', { status: 'error', message: error && error.message ? error.message : 'Не удалось загрузить данные' }, message.requestId, origin);
            }
        }

        async handleSaveCatalogEntityMetaRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'saveCatalogEntityMeta',
                    entityType: payload.entityType,
                    create: Boolean(payload.create),
                    sectionId: Number(payload.sectionId || 0),
                    entities: Array.isArray(payload.entities) ? payload.entities : [],
                }]);
                const response = Array.isArray(result) ? result[0] : { status: 'error' };
                let refreshedInitData = null;
                if (response && response.status === 'ok') {
                    try {
                        refreshedInitData = await this.fetchInitData();
                        this.initData = refreshedInitData;
                    } catch (refreshError) {
                        console.warn('[BitrixBridge] Catalog metadata saved, but INIT refresh failed', refreshError);
                    }
                }
                this.sendPwrtMessage('CATALOG_ENTITY_META_RESPONSE', response, message.requestId, origin);
                if (refreshedInitData) {
                    this.sendPwrtMessage('INIT', refreshedInitData, message.requestId, origin);
                }
            } catch (error) {
                this.sendPwrtMessage('CATALOG_ENTITY_META_RESPONSE', { status: 'error', message: error && error.message ? error.message : 'Не удалось сохранить данные' }, message.requestId, origin);
            }
        }

        async handleMoveCatalogEntitySectionRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'moveCatalogEntitySection',
                    entityType: payload.entityType,
                    entityId: Number(payload.entityId || 0),
                    sectionId: Number(payload.sectionId || 0),
                }]);
                const response = Array.isArray(result) ? result[0] : { status: 'error' };
                this.sendPwrtMessage('CATALOG_SECTION_RESPONSE', response, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('CATALOG_SECTION_RESPONSE', { status: 'error', message: error && error.message ? error.message : 'Не удалось переместить элемент' }, message.requestId, origin);
            }
        }

        async handleCreateCatalogSectionRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'createCatalogSection',
                    entityType: payload.entityType,
                    parentSectionId: Number(payload.parentSectionId || 0),
                    name: String(payload.name || ''),
                }]);
                const response = Array.isArray(result) ? result[0] : { status: 'error' };
                this.sendPwrtMessage('CATALOG_SECTION_RESPONSE', response, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('CATALOG_SECTION_RESPONSE', { status: 'error', message: error && error.message ? error.message : 'Не удалось создать раздел' }, message.requestId, origin);
            }
        }

        async handleGetCatalogTreeRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'getCatalogTree',
                    iblockId: Number(payload.iblockId || 0),
                    iblockCode: String(payload.iblockCode || ''),
                }]);
                this.sendPwrtMessage('CATALOG_TREE_RESPONSE', Array.isArray(result) ? result[0] : { status: 'error' }, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('CATALOG_TREE_RESPONSE', { status: 'error', message: error && error.message ? error.message : 'Не удалось загрузить дерево инфоблока' }, message.requestId, origin);
            }
        }

        async handleGetPresetLoadOptionsRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'getPresetLoadOptions',
                    presetId: Number(payload.presetId || 0),
                }]);
                this.sendPwrtMessage('PRESET_LOAD_OPTIONS_RESPONSE', Array.isArray(result) ? result[0] : { status: 'error' }, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('PRESET_LOAD_OPTIONS_RESPONSE', { status: 'error', message: error && error.message ? error.message : 'Не удалось получить товары пресета' }, message.requestId, origin);
            }
        }

        async handleSaveCatalogTreeElementRequest(message, origin) {
            await this.handleCatalogTreeMutation(message, origin, 'saveCatalogTreeElement');
        }

        async handleSaveCatalogTreeSectionRequest(message, origin) {
            await this.handleCatalogTreeMutation(message, origin, 'saveCatalogTreeSection');
        }

        async handleDeleteCatalogTreeNodeRequest(message, origin) {
            await this.handleCatalogTreeMutation(message, origin, 'deleteCatalogTreeNode');
        }

        async handleCatalogTreeMutation(message, origin, action) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{ action, ...payload }]);
                this.sendPwrtMessage('CATALOG_TREE_MUTATION_RESPONSE', Array.isArray(result) ? result[0] : { status: 'error' }, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('CATALOG_TREE_MUTATION_RESPONSE', { status: 'error', message: error && error.message ? error.message : 'Не удалось изменить структуру инфоблока' }, message.requestId, origin);
            }
        }

        /**
         * Обработка запроса ADD_STAGE_REQUEST
         * Payload: { detailId }
         * Логика:
         * 1. Создать новый этап с названием "Этап #" + date('dmY_His')
         * 2. Добавить этап последним в свойство CALC_STAGES детали с ID = detailId
         * 3. Добавить этап последним в свойство CALC_STAGES пресета
         * 4. Обогатить пресет на основе первого элемента CALC_DETAILS
         * 5. Отправить INIT
         */
        async handleAddStageRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleAddStageRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const detailId = payload.detailId || 0;

            try {
                // Получаем presetId из initData
                const presetId = this.initData?.preset?.id;
                const siteId = this.config.siteId || SITE_ID;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                if (detailId <= 0) {
                    throw new Error('Detail ID обязателен');
                }

                // Вызываем добавление этапа через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'addStage',
                        detailId: detailId,
                        name: String(payload.name || ''),
                        previewText: String(payload.previewText || ''),
                        afterStageId: Number(payload.afterStageId || 0),
                        presetId: presetId,
                        siteId: siteId,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось добавить этап');
                }

                // Обновляем локальный initData
                if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                }

                if (responsePayload.initPayload) {
                    this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                } else {
                    this.sendPwrtMessage('PROCESS_MESSAGE', {
                        status: 'success',
                        message: 'Порядок этапов сохранён; данные будут обновлены при следующей синхронизации',
                    }, message.requestId, origin);
                }

                console.log('[BitrixBridge][DEBUG] handleAddStageRequest END - success, INIT sent');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleAddStageRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ERROR',
                    {
                        message: 'Ошибка добавления этапа',
                        details: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        async handleDuplicateStageRequest(message, origin) {
            const payload = message.payload || {};
            const detailId = Number(payload.detailId || 0);
            const stageId = Number(payload.stageId || 0);
            try {
                const presetId = this.initData?.preset?.id;
                if (!presetId || detailId <= 0 || stageId <= 0) {
                    throw new Error('Preset ID, detail ID and stage ID are required');
                }
                const result = await this.fetchRefreshData([{
                    action: 'duplicateStage',
                    detailId,
                    stageId,
                    presetId,
                    siteId: this.config.siteId || SITE_ID,
                }]);
                const responsePayload = Array.isArray(result) && result[0]
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };
                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось клонировать этап');
                }
                if (!responsePayload.initPayload) {
                    throw new Error('Сервер не вернул обновлённое состояние после клонирования');
                }
                this.initData = responsePayload.initPayload;
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    message: 'Ошибка клонирования этапа',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        /**
         * Обработка запроса DELETE_STAGE_REQUEST
         * Payload: { stageId }
         * Логика:
         * 1. Физически удалить элемент инфоблока этапа с ID = stageId через \CIBlockElement::Delete($stageId)
         * 2. Обогатить пресет на основе первого элемента CALC_DETAILS
         * 3. Отправить INIT
         */
        async handleDeleteStageRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleDeleteStageRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const stageId = payload.stageId || 0;

            try {
                // Получаем presetId из initData
                const presetId = this.initData?.preset?.id;
                const siteId = this.config.siteId || SITE_ID;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                if (stageId <= 0) {
                    throw new Error('Stage ID обязателен');
                }

                // Вызываем удаление этапа через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'deleteStage',
                        stageId: stageId,
                        presetId: presetId,
                        siteId: siteId,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось удалить этап');
                }

                // Обновляем локальный initData
                if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                }

                // Отправляем INIT message
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleDeleteStageRequest END - success, INIT sent');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleDeleteStageRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ERROR',
                    {
                        message: 'Ошибка удаления этапа',
                        details: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса REMOVE_DETAIL_REQUEST
         * Payload: { parentId, detailId }
         * Логика (с рекурсивной чисткой):
         * 1. isRootParent = (parentId === CALC_DETAILS[0] пресета)
         * 2. Убрать detailId из DETAILS родителя (parentId)
         * 3. Проверить сколько деталей осталось в DETAILS родителя:
         *    А) Осталась 1 деталь:
         *       → survivorId = эта оставшаяся деталь
         *       → Удалить скрепление parentId физически
         *       Если isRootParent = true:
         *          → Обогатить пресет на основе survivorId
         *       Иначе:
         *          → Заменить parentId на survivorId в родителе parentId
         *          → Рекурсивно проверить родителя
         *          → Обогатить пресет на основе CALC_DETAILS[0]
         *    Б) Осталось 0 деталей:
         *       → Удалить скрепление parentId физически
         *       → Рекурсивно убрать parentId из его родителя
         *       → Обогатить пресет на основе CALC_DETAILS[0]
         *    В) Осталось 2+ деталей:
         *       → Скрепление остаётся
         *       → Обогатить пресет на основе CALC_DETAILS[0]
         * 4. Отправить INIT
         */
        async handleRemoveDetailRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleRemoveDetailRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const parentId = payload.parentId || 0;
            const name = typeof payload.name === 'string' ? payload.name.trim() : '';
            const detailId = payload.detailId || 0;

            try {
                // Получаем presetId из initData
                const presetId = this.initData?.preset?.id;
                const siteId = this.config.siteId || SITE_ID;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                if (detailId <= 0) {
                    throw new Error('Detail ID обязателен');
                }

                // Вызываем удаление детали через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'removeDetail',
                        parentId: parentId,
                        detailId: detailId,
                        presetId: presetId,
                        siteId: siteId,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось удалить деталь');
                }

                // Обновляем локальный initData
                if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                }

                // Отправляем INIT message
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleRemoveDetailRequest END - success, INIT sent');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleRemoveDetailRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ERROR',
                    {
                        message: 'Ошибка удаления детали',
                        details: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса RENAME_DETAIL_REQUEST
         * Payload: { detailId, name }
         * Логика:
         * 1. Изменить NAME элемента детали через \CIBlockElement::Update()
         * 2. **Ничего не отправлять** (режим тишины)
         */
        async handleRenameDetailRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleRenameDetailRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const detailId = payload.detailId || 0;
            const name = payload.name || '';

            try {
                if (detailId <= 0) {
                    console.error('[BitrixBridge] Detail ID обязателен');
                    // В режиме тишины не отправляем ошибку
                    return;
                }

                if (!name) {
                    console.error('[BitrixBridge] Name обязателен');
                    // В режиме тишины не отправляем ошибку
                    return;
                }

                // Вызываем переименование детали через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'renameDetail',
                        detailId: detailId,
                        name: name,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    console.error('[BitrixBridge] renameDetail error:', responsePayload.message);
                    // В режиме тишины не отправляем ошибку
                    return;
                }

                console.log('[BitrixBridge] renameDetail success for detailId:', detailId);
                // В режиме тишины НЕ отправляем ответ обратно во фрейм

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleRenameDetailRequest ERROR', {
                    error: error,
                    message: error.message,
                });
                // В режиме тишины не отправляем ошибку
            }
        }

        /**
         * Обработка запроса CHANGE_SETTINGS_REQUEST
         * Payload: { settingsId, stageId }
         * Логика:
         * 1. Обновить свойство CALC_SETTINGS в этапе stageId значением settingsId
         * 2. Взять первый ID из CALC_DETAILS пресета
         * 3. Обогатить пресет на его основе
         * 4. Отправить INIT
         */
        async handleChangeSettingsRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleChangeSettingsRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const settingsId = payload.settingsId || 0;
            const stageId = payload.stageId || 0;

            try {
                const presetId = this.initData?.preset?.id;
                const siteId = this.config.siteId || SITE_ID;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                if (stageId <= 0) {
                    throw new Error('Stage ID обязателен');
                }

                // Вызываем обновление через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'changeSettings',
                        settingsId: settingsId,
                        stageId: stageId,
                        presetId: presetId,
                        siteId: siteId,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось обновить настройки');
                }

                // Обновляем локальный initData
                if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                }

                // Отправляем INIT message
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleChangeSettingsRequest END - success, INIT sent');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleChangeSettingsRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ERROR',
                    {
                        message: 'Ошибка обновления настроек',
                        details: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса CHANGE_OPERATION_VARIANT_REQUEST
         * Payload: { operationVariantId, stageId }
         * Логика:
         * 1. Обновить свойство OPERATION_VARIANT в этапе stageId значением operationVariantId
         * 2. Взять первый ID из CALC_DETAILS пресета
         * 3. Обогатить пресет на его основе
         * 4. Отправить INIT
         */
        async handleChangeOperationVariantRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleChangeOperationVariantRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const operationVariantId = payload.operationVariantId || 0;
            const stageId = payload.stageId || 0;

            try {
                const presetId = this.initData?.preset?.id;
                const siteId = this.config.siteId || SITE_ID;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                if (stageId <= 0) {
                    throw new Error('Stage ID обязателен');
                }

                // Вызываем обновление через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'changeOperationVariant',
                        operationVariantId: operationVariantId,
                        stageId: stageId,
                        presetId: presetId,
                        siteId: siteId,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось обновить вариант операции');
                }

                // Обновляем локальный initData
                if (this.applySemanticReadback(responsePayload)) {
                    // Version-aware authoritative state merged into the current INIT.
                } else if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                }

                // Отправляем INIT message
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleChangeOperationVariantRequest END - success, INIT sent');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleChangeOperationVariantRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ERROR',
                    {
                        message: 'Ошибка обновления варианта операции',
                        details: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса CHANGE_EQUIPMENT_REQUEST
         * Payload: { equipmentId, stageId }
         * Логика:
         * 1. Обновить свойство EQUIPMENT в этапе stageId значением equipmentId
         * 2. Взять первый ID из CALC_DETAILS пресета
         * 3. Обогатить пресет на его основе
         * 4. Отправить INIT
         */
        async handleChangeEquipmentRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleChangeEquipmentRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const equipmentId = payload.equipmentId || 0;
            const stageId = payload.stageId || 0;

            try {
                const presetId = this.initData?.preset?.id;
                const siteId = this.config.siteId || SITE_ID;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                if (stageId <= 0) {
                    throw new Error('Stage ID обязателен');
                }

                // Вызываем обновление через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'changeEquipment',
                        equipmentId: equipmentId,
                        stageId: stageId,
                        presetId: presetId,
                        siteId: siteId,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось обновить оборудование');
                }

                // Обновляем локальный initData
                if (this.applySemanticReadback(responsePayload)) {
                    // Version-aware authoritative state merged into the current INIT.
                } else if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                }

                // Отправляем INIT message
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleChangeEquipmentRequest END - success, INIT sent');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleChangeEquipmentRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ERROR',
                    {
                        message: 'Ошибка обновления оборудования',
                        details: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса CHANGE_MATERIAL_VARIANT_REQUEST
         * Payload: { materialVariantId, stageId }
         * Логика:
         * 1. Обновить свойство MATERIAL_VARIANT в этапе stageId значением materialVariantId
         * 2. Взять первый ID из CALC_DETAILS пресета
         * 3. Обогатить пресет на его основе
         * 4. Отправить INIT
         */
        async handleChangeMaterialVariantRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleChangeMaterialVariantRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const materialVariantId = payload.materialVariantId || 0;
            const stageId = payload.stageId || 0;

            try {
                const presetId = this.initData?.preset?.id;
                const siteId = this.config.siteId || SITE_ID;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                if (stageId <= 0) {
                    throw new Error('Stage ID обязателен');
                }

                // Вызываем обновление через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'changeMaterialVariant',
                        materialVariantId: materialVariantId,
                        stageId: stageId,
                        presetId: presetId,
                        siteId: siteId,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось обновить вариант материала');
                }

                // Обновляем локальный initData
                if (this.applySemanticReadback(responsePayload)) {
                    // Version-aware authoritative state merged into the current INIT.
                } else if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                }

                // Отправляем INIT message
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleChangeMaterialVariantRequest END - success, INIT sent');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleChangeMaterialVariantRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ERROR',
                    {
                        message: 'Ошибка обновления варианта материала',
                        details: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса CHANGE_CUSTOM_FIELDS_VALUE_REQUEST (silent mode)
         * Payload: { stageId, customFieldsValue: [{ CODE, VALUE }, ...] }
         * Логика:
         * 1. Записать в множественное свойство CUSTOM_FIELDS_VALUE этапа stageId
         * 2. CODE → VALUE поля, VALUE → DESCRIPTION поля
         * 3. Вернуть INIT с подтверждённым состоянием либо коррелированную ошибку
         */
        async handleChangeCustomFieldsValue(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleChangeCustomFieldsValue START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const stageId = payload.stageId || 0;
            const customFieldsValue = payload.customFieldsValue || [];

            try {
                if (stageId <= 0 || !Array.isArray(customFieldsValue)) {
                    throw new Error('Stage ID и массив customFieldsValue обязательны');
                }

                // Вызываем обновление через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'changeCustomFieldsValue',
                        stageId: stageId,
                        customFieldsValue: customFieldsValue,
                        presetId: Number(this.initData?.preset?.id || 0),
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Change custom fields value failed');
                }

                if (this.applySemanticReadback(responsePayload)) {
                    this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                } else if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                    this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                } else {
                    this.sendPwrtMessage('PROCESS_MESSAGE', {
                        status: 'success',
                        message: 'Дополнительные параметры этапа сохранены',
                    }, message.requestId, origin);
                }
                console.log('[BitrixBridge] changeCustomFieldsValue success for stageId:', stageId);

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleChangeCustomFieldsValue ERROR', {
                    error: error,
                    message: error.message,
                });
                this.sendPwrtMessage('ERROR', {
                    message: 'Ошибка сохранения дополнительных параметров этапа',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        /**
         * Обработка запроса ADD_DETAIL_TO_BINDING_REQUEST
         * Payload: { parentId }
         * Логика:
         * 1. Создать новую деталь с TYPE = DETAIL и 1 пустым этапом
         * 2. Добавить ID новой детали в свойство DETAILS родителя
         * 3. Переобогатить пресет на основе CALC_DETAILS[0]
         * 4. Отправить INIT
         */
        async handleAddDetailToBindingRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleAddDetailToBindingRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const parentId = payload.parentId || 0;

            try {
                // Получаем presetId из initData
                const presetId = this.initData?.preset?.id;
                const siteId = this.config.siteId || SITE_ID;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                if (parentId <= 0) {
                    throw new Error('Parent ID обязателен');
                }

                // Вызываем создание детали и добавление в скрепление через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'addDetailToBinding',
                        parentId: parentId,
                        presetId: presetId,
                        name: name,
                        siteId: siteId,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось добавить деталь в скрепление');
                }

                // Обновляем локальный initData
                if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                }

                // Отправляем INIT message
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleAddDetailToBindingRequest END - success, INIT sent');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleAddDetailToBindingRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ERROR',
                    {
                        message: 'Ошибка добавления детали в скрепление',
                        details: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса SELECT_DETAILS_TO_BINDING_REQUEST
         * Payload: { parentId }
         * Логика:
         * 1. Показать окно выбора деталей
         * 2. После завершения выбора — добавить выбранные детали в DETAILS родителя
         * 3. Переобогатить пресет на основе CALC_DETAILS[0]
         * 4. Отправить INIT
         */
        async handleSelectDetailsToBindingRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleSelectDetailsToBindingRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const parentId = payload.parentId || 0;

            try {
                if (parentId <= 0) {
                    throw new Error('Parent ID обязателен');
                }

                // Получить iblockId для CALC_DETAILS из initData
                const calcDetails = this.findIblockByCode('CALC_DETAILS');
                const iblockId = calcDetails?.id || null;
                const iblockType = calcDetails?.type || null;
                const lang = this.initData?.lang || null;

                // 1. Использовать выбор из нового iframe-каталога либо открыть
                //    штатный Bitrix-диалог для старых клиентов.
                const selectedIds = Array.isArray(payload.selectedIds)
                    ? payload.selectedIds.map((id) => parseInt(id, 10)).filter((id) => id > 0)
                    : await this.openElementSelectionDialog({
                        iblockId: iblockId,
                        iblockType: iblockType,
                        lang: lang,
                    });

                // Режим тишины - 0 деталей выбрано
                if (!selectedIds || selectedIds.length === 0) {
                    console.log('[BitrixBridge] No details selected, silent mode');
                    return;
                }

                // Получаем presetId из initData
                const presetId = this.initData?.preset?.id;
                const siteId = this.config.siteId || SITE_ID;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                // 2. Добавить выбранные детали в скрепление через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'addDetailsToBinding',
                        parentId: parentId,
                        detailIds: selectedIds,
                        presetId: presetId,
                        siteId: siteId,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось добавить детали в скрепление');
                }

                // Обновляем локальный initData
                if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                }

                // Отправляем INIT message
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleSelectDetailsToBindingRequest END - success, INIT sent');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleSelectDetailsToBindingRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ERROR',
                    {
                        message: 'Ошибка добавления выбранных деталей в скрепление',
                        details: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса CHANGE_DETAIL_SORT_REQUEST
         * Payload: { parentId, sorting }
         * Логика:
         * 1. В свойство DETAILS родителя записать массив sorting
         * 2. Переобогатить пресет на основе CALC_DETAILS[0]
         * 3. Отправить INIT
         */
        async handleChangeDetailSortRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleChangeDetailSortRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const parentId = payload.parentId || 0;
            const sorting = payload.sorting || [];

            try {
                if (parentId <= 0) {
                    throw new Error('Parent ID обязателен');
                }

                if (!Array.isArray(sorting) || sorting.length === 0) {
                    throw new Error('Sorting обязателен');
                }

                // Получаем presetId из initData
                const presetId = this.initData?.preset?.id;
                const siteId = this.config.siteId || SITE_ID;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                // Вызываем изменение сортировки через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'changeDetailSort',
                        parentId: parentId,
                        sorting: sorting,
                        presetId: presetId,
                        siteId: siteId,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось изменить сортировку деталей');
                }

                // Обновляем локальный initData
                if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                }

                // Отправляем INIT message
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleChangeDetailSortRequest END - success, INIT sent');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleChangeDetailSortRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ERROR',
                    {
                        message: 'Ошибка изменения сортировки деталей',
                        details: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса CHANGE_DETAIL_LEVEL_REQUEST
         * Payload: { fromParentId, detailId, toParentId, sorting }
         * Логика:
         * 1. Убрать detailId из DETAILS у fromParentId
         * 2. В toParentId записать DETAILS = sorting
         * 3. Переобогатить пресет на основе CALC_DETAILS[0]
         * 4. Отправить INIT
         */
        async handleChangeDetailLevelRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleChangeDetailLevelRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const fromParentId = payload.fromParentId || 0;
            const detailId = payload.detailId || 0;
            const toParentId = payload.toParentId || 0;
            const sorting = payload.sorting || [];

            try {
                if (fromParentId <= 0 || detailId <= 0 || toParentId <= 0) {
                    throw new Error('fromParentId, detailId, toParentId обязательны');
                }

                if (!Array.isArray(sorting) || sorting.length === 0) {
                    throw new Error('Sorting обязателен');
                }

                // Получаем presetId из initData
                const presetId = this.initData?.preset?.id;
                const siteId = this.config.siteId || SITE_ID;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                // Вызываем перенос детали через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'changeDetailLevel',
                        fromParentId: fromParentId,
                        detailId: detailId,
                        toParentId: toParentId,
                        sorting: sorting,
                        presetId: presetId,
                        siteId: siteId,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось перенести деталь между скреплениями');
                }

                // Обновляем локальный initData
                if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                }

                // Отправляем INIT message
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleChangeDetailLevelRequest END - success, INIT sent');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleChangeDetailLevelRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ERROR',
                    {
                        message: 'Ошибка переноса детали между скреплениями',
                        details: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса CHANGE_SORT_STAGE_REQUEST
         * Payload: { detailId, sorting }
         * Логика:
         * 1. В свойство CALC_STAGES детали записать массив sorting
         * 2. Переобогатить пресет на основе CALC_DETAILS[0]
         * 3. Отправить INIT
         */
        async handleChangeSortStageRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleChangeSortStageRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const detailId = payload.detailId || 0;
            const sorting = payload.sorting || [];

            try {
                if (detailId <= 0) {
                    throw new Error('Detail ID обязателен');
                }

                if (!Array.isArray(sorting) || sorting.length === 0) {
                    throw new Error('Sorting обязателен');
                }

                // Получаем presetId из initData
                const presetId = this.initData?.preset?.id;
                const siteId = this.config.siteId || SITE_ID;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                // Вызываем изменение сортировки этапов через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'changeSortStage',
                        detailId: detailId,
                        sorting: sorting,
                        presetId: presetId,
                        siteId: siteId,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось изменить сортировку этапов');
                }

                // Обновляем локальный initData
                if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                }

                // Отправляем INIT message
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleChangeSortStageRequest END - success, INIT sent');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleChangeSortStageRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage('ERROR', {
                    message: 'Ошибка изменения сортировки этапов',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
                this.sendPwrtMessage('INIT', this.initData, null, origin);
            }
        }

        /**
         * Обработка запроса очистки пресета
         * Очищает свойства пресета и отправляет INIT
         */
        async handleClearPresetRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleClearPresetRequest START', {
                messageType: message.type,
                origin: origin,
            });

            try {
                // Получаем presetId из initData
                const presetId = this.initData?.preset?.id;

                if (!presetId) {
                    throw new Error('PresetId not found in initData');
                }

                const result = await this.fetchRefreshData([{
                    action: 'clearPreset',
                    presetId,
                    siteId: String(this.config?.siteId || 's1'),
                }]);
                const cleared = Array.isArray(result) ? result[0] : null;
                if (!cleared?.initPayload) {
                    throw new Error('Сервер не вернул подтверждённое состояние очищенного пресета.');
                }

                console.log('[BitrixBridge] clearPreset success for presetId:', presetId);

                this.initData = cleared.initPayload;

                // Отправляем INIT message
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleClearPresetRequest END - success, INIT sent');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleClearPresetRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ERROR',
                    {
                        message: 'Ошибка очистки пресета',
                        details: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса CHANGE_PRICE_PRESET_REQUEST
         * Payload: { prices: [{ typeId, price, currency, quantityFrom, quantityTo }, ...] }
         * Логика:
         * 1. Очистить все текущие цены пресета
         * 2. Записать новые цены из payload
         * 3. Переобогатить пресет
         * 4. Отправить INIT
         */
        async handleChangePricePresetRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleChangePricePresetRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};
            const prices = Array.isArray(payload) ? payload : (payload.prices || []);
            const priceProfilePolicy = Array.isArray(payload) ? null : (payload.priceProfilePolicy || null);

            try {
                // Получаем presetId из initData
                const presetId = this.initData?.preset?.id;
                const siteId = this.config.siteId || SITE_ID;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                if (!Array.isArray(prices) || prices.length === 0) {
                    throw new Error('Prices обязателен');
                }

                // Вызываем обработку через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'changePricePreset',
                        presetId: presetId,
                        prices: prices,
                        priceProfilePolicy: priceProfilePolicy,
                        siteId: siteId,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось обновить цены пресета');
                }

                // Обновляем локальный initData
                if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                }

                // Отправляем INIT message
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleChangePricePresetRequest END - success, INIT sent');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleChangePricePresetRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ERROR',
                    {
                        message: 'Ошибка обновления цен пресета',
                        details: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка CHANGE_OPTIONS_OPERATION
         * Payload: { stageId, json }
         * Записывает json в свойство OPTIONS_OPERATION этапа
         * Ничего не отправляет в ответ
         */
        /**
         * Обработка CHANGE_OPTIONS_OPERATION
         * Payload: { stageId, json }
         * Записывает json в свойство OPTIONS_OPERATION этапа
         * Использует "лёгкое обогащение" - модификация this.initData без AJAX
         */
        async handleChangeOptionsOperation(message, origin) {
            const payload = message.payload || {};
            const stageId = parseInt(payload.stageId, 10);
            const json = payload.json || '';
            
            if (!stageId) {
                console.warn('[BitrixBridge] CHANGE_OPTIONS_OPERATION: stageId не указан');
                this.sendPwrtMessage('ERROR', { message: 'Не указан этап для сопоставления варианта операции' }, message.requestId, origin);
                return;
            }
            
            try {
                // 1. Сохраняем на сервере
                const result = await this.fetchRefreshData([{
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'OPTIONS_OPERATION',
                    value: json
                }]);
                const responsePayload = Array.isArray(result) && result[0]
                    ? result[0]
                    : { status: 'error', message: 'Пустой ответ сервера' };
                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось сохранить сопоставление варианта операции');
                }
                
                // 2. Лёгкое обогащение - обновляем локально this.initData
                this.updateStagePropertyInInitData(stageId, 'OPTIONS_OPERATION', responsePayload.value ?? json);
                
                // 3. Отправляем модифицированный INIT
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                
            } catch (error) {
                console.error('[BitrixBridge] CHANGE_OPTIONS_OPERATION error:', error);
                this.sendPwrtMessage('ERROR', {
                    message: 'Ошибка сохранения сопоставления варианта операции',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        /**
         * Обработка CHANGE_OPTIONS_MATERIAL
         * Payload: { stageId, json }
         * Записывает json в свойство OPTIONS_MATERIAL этапа
         * Использует "лёгкое обогащение" - модификация this.initData без AJAX
         */
        async handleChangeOptionsMaterial(message, origin) {
            const payload = message.payload || {};
            const stageId = parseInt(payload.stageId, 10);
            const json = payload.json || '';
            
            if (!stageId) {
                console.warn('[BitrixBridge] CHANGE_OPTIONS_MATERIAL: stageId не указан');
                this.sendPwrtMessage('ERROR', { message: 'Не указан этап для сопоставления варианта материала' }, message.requestId, origin);
                return;
            }
            
            try {
                // 1. Сохраняем на сервере
                const result = await this.fetchRefreshData([{
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'OPTIONS_MATERIAL',
                    value: json
                }]);
                const responsePayload = Array.isArray(result) && result[0]
                    ? result[0]
                    : { status: 'error', message: 'Пустой ответ сервера' };
                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось сохранить сопоставление варианта материала');
                }
                
                // 2. fetchRefreshData already installed the authoritative semantic
                // readback, including every material referenced by the saved tree.
                // Keep the local fallback only for legacy direct callers.
                if (!responsePayload.initPayload) {
                    this.updateStagePropertyInInitData(stageId, 'OPTIONS_MATERIAL', responsePayload.value ?? json);
                    if (responsePayload.clearedPropertyCode === 'MATERIAL_VARIANT') {
                        this.updateStagePropertyInInitData(stageId, 'MATERIAL_VARIANT', '');
                    }
                }
                
                // 3. Отправляем модифицированный INIT
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                
            } catch (error) {
                console.error('[BitrixBridge] CHANGE_OPTIONS_MATERIAL error:', error);
                this.sendPwrtMessage('ERROR', {
                    message: 'Ошибка сохранения сопоставления варианта материала',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        /**
         * Обработка SAVE_CALC_LOGIC_REQUEST
         * Payload: { settingsId, stageId, calcSettings: { logicJson, params }, stageWiring: { inputs, outputs } }
         * Записывает данные в LOGIC_JSON/PARAMS калькулятора и INPUTS/OUTPUTS этапа.
         * Использует "лёгкое обогащение" - модификация this.initData без AJAX
         */
        async handleChangeOptionsEquipment(message, origin) {
            const payload = message.payload || {};
            const stageId = parseInt(payload.stageId, 10);
            const json = payload.json || '';
            if (!stageId) {
                console.warn('[BitrixBridge] CHANGE_OPTIONS_EQUIPMENT: stageId не указан');
                this.sendPwrtMessage('ERROR', { message: 'Не указан этап для сопоставления оборудования' }, message.requestId, origin);
                return;
            }
            try {
                const result = await this.fetchRefreshData([{
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'OPTIONS_EQUIPMENT',
                    value: json
                }]);
                const responsePayload = Array.isArray(result) && result[0]
                    ? result[0]
                    : { status: 'error', message: 'Пустой ответ сервера' };
                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось сохранить сопоставление оборудования');
                }
                this.updateStagePropertyInInitData(stageId, 'OPTIONS_EQUIPMENT', responsePayload.value ?? json);
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                console.error('[BitrixBridge] CHANGE_OPTIONS_EQUIPMENT error:', error);
                this.sendPwrtMessage('ERROR', {
                    message: 'Ошибка сохранения сопоставления оборудования',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        async handleChangeRootDetailSortRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'changeRootDetailSort',
                    presetId: Number(payload.presetId || 0),
                    sorting: Array.isArray(payload.sorting) ? payload.sorting : [],
                    siteId: this.config.siteId || SITE_ID,
                }]);
                const responsePayload = Array.isArray(result) && result[0]
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };
                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось изменить порядок колонок');
                }
                if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                    this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                } else {
                    this.sendPwrtMessage('PROCESS_MESSAGE', {
                        status: 'success',
                        message: 'Порядок колонок сохранён',
                    }, message.requestId, origin);
                }
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    message: 'Ошибка изменения порядка колонок',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        /**
         * Перенос этапа между деталями с одновременным сохранением порядка
         * в исходной и целевой деталях.
         */
        async handleMoveStageRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const presetId = this.initData?.preset?.id;
                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                const result = await this.fetchRefreshData([{
                    action: 'moveStage',
                    stageId: Number(payload.stageId || 0),
                    sourceDetailId: Number(payload.sourceDetailId || 0),
                    targetDetailId: Number(payload.targetDetailId || 0),
                    sourceSorting: Array.isArray(payload.sourceSorting) ? payload.sourceSorting : [],
                    targetSorting: Array.isArray(payload.targetSorting) ? payload.targetSorting : [],
                    presetId: presetId,
                    siteId: this.config.siteId || SITE_ID,
                }]);

                const responsePayload = Array.isArray(result) && result[0]
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };
                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось перенести этап');
                }
                if (responsePayload.initPayload) {
                    this.initData = responsePayload.initPayload;
                }
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    message: 'Ошибка переноса этапа между деталями',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
                // ERROR завершает коррелированную клиентскую операцию. INIT без
                // requestId затем восстанавливает последнюю подтверждённую структуру.
                this.sendPwrtMessage('INIT', this.initData, null, origin);
            }
        }

        async handleSaveStageActivationRequest(message, origin) {
            const payload = message.payload || {};
            const stageId = parseInt(payload.stageId, 10);
            const condition = payload.condition && typeof payload.condition === 'object'
                ? payload.condition
                : { version: 2, enabled: true, mode: 'or', operands: [] };
            if (!stageId) {
                this.sendPwrtMessage('ERROR', { message: 'Не указан этап для сохранения условия' }, message.requestId, origin);
                return;
            }
            try {
                const operands = (Array.isArray(condition.operands)
                    ? condition.operands
                    : condition.kind && condition.code ? [{ kind: condition.kind, code: condition.code }] : [])
                    .filter(item => item && (item.kind === 'input' || item.kind === 'variable' || item.kind === 'constant') && String(item.code || '').trim())
                    .map(item => ({ kind: item.kind, code: String(item.code).trim() }));
                const value = JSON.stringify({
                    version: 2,
                    enabled: condition.enabled === true,
                    mode: condition.mode === 'and' ? 'and' : 'or',
                    operands: operands,
                });
                const result = await this.fetchRefreshData([{
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'ACTIVATION_CONDITION',
                    value: value,
                }]);
                const response = Array.isArray(result) ? result[0] : null;
                if (response && response.status === 'error') {
                    throw new Error(response.message || 'Не удалось сохранить условие');
                }
                this.updateStagePropertyInInitData(stageId, 'ACTIVATION_CONDITION', value);
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    message: 'Не удалось сохранить условие активации этапа',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        async handleSaveStageUsedEntitiesRequest(message, origin) {
            const payload = message.payload || {};
            const stageId = parseInt(payload.stageId, 10) || 0;
            const usedEntities = Array.isArray(payload.usedEntities) ? payload.usedEntities : [];
            if (!stageId) {
                this.sendPwrtMessage('ERROR', { message: 'Не указан этап для сохранения используемых сущностей' }, message.requestId, origin);
                return;
            }
            try {
                const result = await this.fetchRefreshData([{
                    action: 'saveStageUsedEntities',
                    stageId,
                    usedEntities,
                    presetId: Number(this.initData?.preset?.id || 0),
                }]);
                const response = Array.isArray(result) ? result[0] : null;
                if (!response || response.status !== 'ok') {
                    throw new Error(response?.message || 'Не удалось сохранить используемые сущности этапа');
                }
                if (response.initPayload) this.initData = response.initPayload;
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    message: 'Не удалось сохранить используемые сущности этапа',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        async handleSaveGlobalSymbolsRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'saveGlobalSymbols',
                    presetId: Number(payload.presetId || this.initData?.preset?.id || 0),
                    symbols: Array.isArray(payload.symbols) ? payload.symbols : [],
                }]);
                const response = Array.isArray(result) && result[0] ? result[0] : { status: 'error', message: 'Пустой ответ сервера' };
                if (response.status !== 'ok') throw new Error(response.message || 'Не удалось сохранить глобальный реестр');
                if (this.initData) {
                    this.initData.globalSymbols = Array.isArray(response.symbols) ? response.symbols : [];
                    this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                } else {
                    this.sendPwrtMessage('RESPONSE', response, message.requestId, origin);
                }
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    message: 'Не удалось сохранить глобальный реестр',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        async handleSaveGlobalValuesRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const presetId = Number(payload.presetId || this.initData?.preset?.id || 0);
                const result = await this.fetchRefreshData([{
                    action: 'saveCalculatorGlobals',
                    presetId: presetId,
                    symbols: Array.isArray(payload.symbols) ? payload.symbols : [],
                    variables: Array.isArray(payload.variables) ? payload.variables : [],
                    constants: Array.isArray(payload.constants) ? payload.constants : [],
                }]);
                const aggregateResponse = Array.isArray(result) ? result[0] : null;
                if (!aggregateResponse || aggregateResponse.status !== 'ok') {
                    throw new Error(aggregateResponse?.message || 'Не удалось сохранить глобальные значения');
                }
                if (aggregateResponse.initPayload) {
                    this.initData = aggregateResponse.initPayload;
                }
                if (this.initData) {
                    this.initData.globalSymbols = Array.isArray(aggregateResponse.symbols)
                        ? aggregateResponse.symbols
                        : [];
                    this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                } else {
                    this.sendPwrtMessage('RESPONSE', aggregateResponse, message.requestId, origin);
                }
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    message: 'Не удалось сохранить глобальные значения',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        async handleSaveStageGroupsRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'saveStageGroups',
                    presetId: Number(payload.presetId || 0),
                    groups: Array.isArray(payload.groups) ? payload.groups : [],
                }]);
                const response = Array.isArray(result) && result[0] ? result[0] : { status: 'error', message: 'Пустой ответ сервера' };
                if (response.status !== 'ok') throw new Error(response.message || 'Не удалось сохранить группы этапов');
                if (this.initData && this.initData.preset) {
                    const value = JSON.stringify({ version: 3, groups: Array.isArray(response.groups) ? response.groups : [] });
                    this.initData.preset.properties = this.initData.preset.properties || {};
                    this.initData.preset.properties.STAGE_GROUPS = {
                        VALUE: { TEXT: value, TYPE: 'TEXT' },
                        '~VALUE': { TEXT: value, TYPE: 'TEXT' },
                    };
                    this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                } else {
                    this.sendPwrtMessage('RESPONSE', response, message.requestId, origin);
                }
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    message: 'Не удалось сохранить группы этапов',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        async handleCheckCalcContractRequest(message, origin) {
            const payload = message.payload || {};
            const settingsId = parseInt(payload.settingsId, 10) || 0;
            const normalize = (values) => Array.from(new Set((Array.isArray(values) ? values : [])
                .map((value) => String(value || '').trim())
                .filter(Boolean)))
                .sort();
            const baselineInputCodes = normalize(payload.baselineInputCodes);
            const currentInputCodes = normalize(payload.currentInputCodes);
            const baselineGlobalCodes = normalize(payload.baselineGlobalCodes);
            const currentGlobalCodes = normalize(payload.currentGlobalCodes);
            const inputsChanged = JSON.stringify(baselineInputCodes) !== JSON.stringify(currentInputCodes);
            const globalsChanged = JSON.stringify(baselineGlobalCodes) !== JSON.stringify(currentGlobalCodes);

            try {
                const result = inputsChanged || globalsChanged
                    ? await this.fetchRefreshData([{ action: 'inspectCalculatorContract', settingsId }])
                    : [];
                const inspection = Array.isArray(result) && result[0]
                    ? result[0]
                    : { status: 'ok', presets: [], stageIds: [] };
                if (inspection.status !== 'ok') {
                    throw new Error(inspection.message || 'Не удалось проверить зависимости калькулятора');
                }
                this.sendPwrtMessage('CALC_CONTRACT_IMPACT_RESPONSE', {
                    status: 'ok',
                    settingsId,
                    stageId: parseInt(payload.stageId, 10) || 0,
                    currentPresetId: parseInt(payload.currentPresetId, 10) || 0,
                    inputsChanged,
                    globalsChanged,
                    inputChanges: {
                        removed: baselineInputCodes.filter((code) => !currentInputCodes.includes(code)),
                        added: currentInputCodes.filter((code) => !baselineInputCodes.includes(code)),
                    },
                    globalChanges: {
                        removed: baselineGlobalCodes.filter((code) => !currentGlobalCodes.includes(code)),
                        added: currentGlobalCodes.filter((code) => !baselineGlobalCodes.includes(code)),
                    },
                    presets: Array.isArray(inspection.presets) ? inspection.presets : [],
                }, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('CALC_CONTRACT_IMPACT_RESPONSE', {
                    status: 'error',
                    message: error && error.message ? error.message : 'Не удалось проверить зависимости калькулятора',
                }, message.requestId, origin);
            }
        }

        async handleResolveCalcContractRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'resolveCalculatorContract',
                    settingsId: parseInt(payload.settingsId, 10) || 0,
                    stageId: parseInt(payload.stageId, 10) || 0,
                    currentPresetId: parseInt(payload.currentPresetId, 10) || 0,
                    mode: String(payload.mode || ''),
                    message: String(payload.message || ''),
                }]);
                const response = Array.isArray(result) ? result[0] : null;
                if (!response || response.status !== 'ok') {
                    throw new Error(response?.message || 'Не удалось разрешить конфликт контракта калькулятора');
                }
                if (response.initPayload) {
                    this.initData = response.initPayload;
                }
                this.sendPwrtMessage('CALC_CONTRACT_RESOLVED', {
                    status: 'ok',
                    settingsId: parseInt(response.settingsId, 10) || 0,
                    mode: response.mode,
                }, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('CALC_CONTRACT_RESOLVED', {
                    status: 'error',
                    message: error && error.message ? error.message : 'Не удалось разрешить конфликт контракта калькулятора',
                }, message.requestId, origin);
            }
        }

        async handleSaveCalcLogicRequest(message, origin) {
            const payload = message.payload || {};
            const settingsId = parseInt(payload.settingsId, 10);
            const stageId = parseInt(payload.stageId, 10);
            const calcSettings = payload.calcSettings || {};
            const stageWiring = payload.stageWiring || {};
            const stageParametrValuesScheme = payload.stageParametrValuesScheme || {};

            if (!settingsId && !stageId) {
                console.warn('[BitrixBridge] SAVE_CALC_LOGIC_REQUEST: settingsId/stageId не указан');
                return;
            }

            const rawLogicJson = calcSettings.logicJson || '';
            const params = Array.isArray(calcSettings.params) ? calcSettings.params : [];
            const globalDependencies = Array.isArray(calcSettings.globalDependencies)
                ? Array.from(new Set(calcSettings.globalDependencies.map((code) => String(code || '').trim()).filter(Boolean)))
                : [];
            const inputs = Array.isArray(stageWiring.inputs) ? stageWiring.inputs : [];
            const outputs = Array.isArray(stageWiring.outputs) ? stageWiring.outputs : [];
            const schemeOffer = Array.isArray(stageParametrValuesScheme.offer)
                ? stageParametrValuesScheme.offer
                : [];

            try {
                await this.fetchRefreshData([{
                    action: 'saveCalcLogic',
                    settingsId,
                    stageId,
                    calcSettings: {
                        logicJson: rawLogicJson,
                        params,
                        globalDependencies,
                    },
                    stageWiring: { inputs, outputs },
                    stageParametrValuesScheme: { offer: schemeOffer },
                }]);

                if (settingsId) {
                    const safeJson = this.escapeHtmlValue(rawLogicJson);
                    this.updateSettingsPropertyInInitDataWithRaw(settingsId, 'LOGIC_JSON', safeJson, rawLogicJson);
                    this.updateSettingsPropertyInInitDataWithDescriptions(
                        settingsId,
                        'PARAMS',
                        params.map((item) => ({ value: item?.name ?? '', description: item?.type ?? '' }))
                    );
                    this.updateSettingsPropertyInInitDataWithRaw(
                        settingsId,
                        'GLOBAL_DEPENDENCIES',
                        globalDependencies,
                        globalDependencies
                    );
                }

                if (stageId) {
                    this.updateStagePropertyInInitDataWithDescriptions(
                        stageId,
                        'INPUTS',
                        inputs.map((item) => ({ value: item?.name ?? '', description: item?.path ?? '' }))
                    );
                    this.updateStagePropertyInInitDataWithDescriptions(
                        stageId,
                        'OUTPUTS',
                        outputs.map((item) => ({ value: item?.key ?? '', description: item?.var ?? '' }))
                    );
                    this.updateStagePropertyInInitDataWithDescriptions(
                        stageId,
                        'SCHEME_PARAMETR_VALUES',
                        schemeOffer.map((item) => ({ value: item?.name ?? '', description: item?.template ?? '' }))
                    );
                }

                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                console.error('[BitrixBridge] SAVE_CALC_LOGIC_REQUEST error:', error);
                this.sendPwrtMessage('ERROR', {
                    message: error && error.message ? error.message : 'Не удалось сохранить логику калькулятора',
                }, message.requestId, origin);
            }
        }

        /**
         * Обработка CLEAR_OPTIONS_OPERATION
         * Payload: { stageId }
         * Очищает свойство OPTIONS_OPERATION у этапа
         */
        async handleClearOptionsOperation(message, origin) {
            const payload = message.payload || {};
            const stageId = parseInt(payload.stageId, 10);
            
            if (!stageId) {
                console.warn('[BitrixBridge] CLEAR_OPTIONS_OPERATION: stageId не указан');
                this.sendPwrtMessage('ERROR', {
                    message: 'Не указан этап для сброса сопоставления операции',
                }, message.requestId, origin);
                return;
            }
            
            try {
                // 1. Очищаем на сервере
                const result = await this.fetchRefreshData([{
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'OPTIONS_OPERATION',
                    value: ''
                }]);
                const responsePayload = Array.isArray(result) && result[0]
                    ? result[0]
                    : { status: 'error', message: 'Пустой ответ сервера' };
                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось сбросить сопоставление варианта операции');
                }
                this.applySemanticReadback(responsePayload);
                
                // 2. Лёгкое обогащение
                this.updateStagePropertyInInitData(stageId, 'OPTIONS_OPERATION', '');
                
                // 3. Отправляем модифицированный INIT
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                
            } catch (error) {
                console.error('[BitrixBridge] CLEAR_OPTIONS_OPERATION error:', error);
                this.sendPwrtMessage('ERROR', {
                    message: 'Ошибка сброса сопоставления операции',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        /**
         * Обработка CLEAR_OPTIONS_MATERIAL
         * Payload: { stageId }
         * Очищает свойство OPTIONS_MATERIAL у этапа
         */
        async handleClearOptionsMaterial(message, origin) {
            const payload = message.payload || {};
            const stageId = parseInt(payload.stageId, 10);
            
            if (!stageId) {
                console.warn('[BitrixBridge] CLEAR_OPTIONS_MATERIAL: stageId не указан');
                this.sendPwrtMessage('ERROR', {
                    message: 'Не указан этап для сброса сопоставления материала',
                }, message.requestId, origin);
                return;
            }
            
            try {
                // 1. Очищаем на сервере
                const result = await this.fetchRefreshData([{
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'OPTIONS_MATERIAL',
                    value: ''
                }]);
                const responsePayload = Array.isArray(result) && result[0]
                    ? result[0]
                    : { status: 'error', message: 'Пустой ответ сервера' };
                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось сбросить сопоставление варианта материала');
                }
                this.applySemanticReadback(responsePayload);
                
                // 2. Лёгкое обогащение
                this.updateStagePropertyInInitData(stageId, 'OPTIONS_MATERIAL', '');
                
                // 3. Отправляем модифицированный INIT
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                
            } catch (error) {
                console.error('[BitrixBridge] CLEAR_OPTIONS_MATERIAL error:', error);
                this.sendPwrtMessage('ERROR', {
                    message: 'Ошибка сброса сопоставления материала',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        /**
         * Обработка CHANGE_LOGIC
         * Payload: { settingsId, json }
         * Записывает json в свойство LOGIC_JSON калькулятора
         * Ничего не отправляет в ответ
         */
        async handleClearOptionsEquipment(message, origin) {
            const payload = message.payload || {};
            const stageId = parseInt(payload.stageId, 10);
            if (!stageId) {
                console.warn('[BitrixBridge] CLEAR_OPTIONS_EQUIPMENT: stageId не указан');
                this.sendPwrtMessage('ERROR', {
                    message: 'Не указан этап для сброса сопоставления оборудования',
                }, message.requestId, origin);
                return;
            }
            try {
                const result = await this.fetchRefreshData([{
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'OPTIONS_EQUIPMENT',
                    value: ''
                }]);
                const responsePayload = Array.isArray(result) && result[0]
                    ? result[0]
                    : { status: 'error', message: 'Пустой ответ сервера' };
                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось сбросить сопоставление оборудования');
                }
                this.applySemanticReadback(responsePayload);
                this.updateStagePropertyInInitData(stageId, 'OPTIONS_EQUIPMENT', '');
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                console.error('[BitrixBridge] CLEAR_OPTIONS_EQUIPMENT error:', error);
                this.sendPwrtMessage('ERROR', {
                    message: 'Ошибка сброса сопоставления оборудования',
                    details: error && error.message ? error.message : 'Unknown error',
                }, message.requestId, origin);
            }
        }

        async handleChangeLogic(message, origin) {
            const payload = message.payload || {};
            const settingsId = payload.settingsId;
            const json = payload.json;
            
            if (!settingsId) return;
            
            try {
                await this.fetchRefreshData([{
                    action: 'updateSettingsProperty',
                    settingsId: settingsId,
                    propertyCode: 'LOGIC_JSON',
                    value: json
                }]);
            } catch (error) {
                console.error('[BitrixBridge] CHANGE_LOGIC error:', error);
            }
            // Ничего не отправляем в ответ
        }

        async sendSelectDone({ ids, iblockId, iblockType, lang, requestId, origin }) {
            const normalizedIds = this.normalizeSelectedIds(ids);
            let items = [];

            if (normalizedIds.length > 0) {
                try {
                    const response = await this.fetchRefreshData([
                        { iblockId: iblockId, iblockType: iblockType, ids: normalizedIds },
                    ]);

                    const elements = Array.isArray(response) && response[0] && Array.isArray(response[0].data)
                        ? response[0].data
                        : [];

                    items = elements.map((item) => this.normalizeItemData(item));
                } catch (error) {
                    console.error('[CalcIntegration] Error during select processing', error);
                }
            }

            this.sendPwrtMessage('SELECT_DONE', {
                iblockId: iblockId,
                iblockType: iblockType,
                lang: lang,
                items: items,
            }, requestId, origin);
        }

        async sendSelectDetailsResponse({ ids, iblockId, iblockType, lang, requestId, origin }) {
            const normalizedIds = this.normalizeSelectedIds(ids);
            let items = [];

            if (normalizedIds.length > 0) {
                try {
                    const response = await this.fetchRefreshData([
                        { iblockId: iblockId, iblockType: iblockType, ids: normalizedIds },
                    ]);

                    const elements = Array.isArray(response) && response[0] && Array.isArray(response[0].data)
                        ? response[0].data
                        : [];

                    items = elements.map((item) => this.normalizeItemData(item));
                } catch (error) {
                    console.error('[CalcIntegration] Error during select details processing', error);
                }
            }

            this.sendPwrtMessage('SELECT_DETAILS_RESPONSE', {
                iblockId: iblockId,
                iblockType: iblockType,
                lang: lang,
                items: items,
            }, requestId, origin);
        }

        normalizeSelectedIds(ids) {
            const list = Array.isArray(ids) ? ids : [];
            const result = [];

            list.forEach((value) => {
                const parsed = parseInt(value, 10);
                if (!parsed || isNaN(parsed) || parsed <= 0) {
                    return;
                }
                if (result.indexOf(parsed) === -1) {
                    result.push(parsed);
                }
            });

            return result;
        }

        normalizeItemData(item) {
            const safeItem = item || {};
            const normalizedMeasureRatio = (typeof safeItem.measureRatio === 'number')
                ? safeItem.measureRatio
                : (safeItem.measureRatio !== undefined && safeItem.measureRatio !== null
                    ? Number(safeItem.measureRatio)
                    : null);

            return {
                id: safeItem.id != null ? safeItem.id : null,
                productId: safeItem.productId != null ? safeItem.productId : null,
                name: safeItem.name || '',
                fields: safeItem.fields || {},
                measure: safeItem.measure !== undefined ? safeItem.measure : null,
                measureRatio: normalizedMeasureRatio,
                prices: Array.isArray(safeItem.prices) ? safeItem.prices : [],
                properties: safeItem.properties || {},
            };
        }

        handleRemoveOfferRequest(message, origin) {
            const payload = message.payload || {};
            const offerId = payload.id || null;

            const desyncFixed = this.tryUncheckOfferRow(offerId);
            if (!desyncFixed) {
                this.logBridge('[CalcIntegration] Failed to deselect offer checkbox before REMOVE_OFFER_ACK', {
                    offerId: offerId,
                });
            }

            this.sendPwrtMessage('REMOVE_OFFER_ACK', { id: offerId, status: 'ok' }, message.requestId, origin);
        }

        findOffersTabContainer() {
            const directSelectors = [
                '#tab_cont_offers',
                '#tab_content_offers',
                '#tab_cont_sku',
                '#tab_content_sku',
                '#tab_cont_product_sku',
                '#tab_content_product_sku',
                '[data-tab-id="offers"]',
                '[data-tab="offers"]',
            ];

            for (let i = 0; i < directSelectors.length; i++) {
                const element = document.querySelector(directSelectors[i]);
                if (element) {
                    return element;
                }
            }

            const tabLink = Array.from(document.querySelectorAll('.adm-detail-tab a, .adm-detail-subtab a'))
                .find(function(node) {
                    return node.textContent && node.textContent.trim() === 'Торговые предложения';
                });

            if (tabLink) {
                const href = tabLink.getAttribute('href');
                if (href && href.startsWith('#')) {
                    const contentId = href.slice(1).replace('tab_cont_', 'tab_content_');
                    const byHref = document.getElementById(href.slice(1)) || document.getElementById(contentId);
                    if (byHref) {
                        return byHref;
                    }
                }

                if (tabLink.dataset && tabLink.dataset.tabId) {
                    const byData = document.querySelector('[data-tab-id="' + tabLink.dataset.tabId + '"]');
                    if (byData) {
                        return byData;
                    }
                }
            }

            return document;
        }

        tryUncheckOfferRow(rawOfferId) {
            if (!rawOfferId && rawOfferId !== 0) {
                return false;
            }

            const stringId = String(rawOfferId);
            const normalizedId = stringId.replace(/^E/i, '');
            const candidateValues = [stringId, normalizedId, 'E' + normalizedId].filter(Boolean);
            const selectors = [
                'input[type="checkbox"][name="ID[]"]',
                'input[type="checkbox"][name="SUB_ID[]"]',
            ];

            const offersContainer = this.findOffersTabContainer();
            let checkbox = null;

            selectors.forEach(function(selector) {
                if (checkbox) {
                    return;
                }

                candidateValues.forEach(function(value) {
                    if (checkbox) {
                        return;
                    }

                    const localSelector = selector + '[value="' + value + '"]';
                    checkbox = offersContainer.querySelector(localSelector) || document.querySelector(localSelector);
                });
            });

            if (!checkbox) {
                return false;
            }

            if (!checkbox.checked) {
                return true;
            }

            checkbox.click();

            return !checkbox.checked;
        }

        openElementSelectionDialog({ iblockId, iblockType, lang }) {
            const dialogLang = lang
                || (window.BX && window.BX.message && window.BX.message('LANGUAGE_ID'))
                || 'ru';
            // Для popup Bitrix параметр `n` должен быть безопасным alnum-токеном,
            // иначе в popup может быть сгенерирован другой callback (`InS...`),
            // которого нет в window.opener.
            const callbackToken = 'pwrt' + Math.random().toString(36).slice(2);
            const callbackName = '__pwrtElementSelect_' + callbackToken;
            const selectedIds = [];
            this.currentSelectionItems = selectedIds;

            const params = new URLSearchParams({
                lang: dialogLang,
                n: callbackToken,
                func_name: callbackName,
                m: 'y',
            });

            if (iblockId) {
                params.append('IBLOCK_ID', iblockId);
            }

            if (iblockType) {
                params.append('IBLOCK_TYPE', iblockType);
            }

            const url = '/bitrix/admin/iblock_element_search.php?' + params.toString();

            return new Promise((resolve) => {
                let resolved = false;
                let popupWindow = null;
                let popupWatcher = null;
                let counterNode = null;
                let closeListenerAttached = false;
                let functionsOverridden = false;
                const registeredAliases = new Set();

                const cleanup = () => {
                    registeredAliases.forEach(function(alias) {
                        delete window[alias];
                    });
                    this.currentSelectionItems = null;

                    if (popupWatcher) {
                        clearInterval(popupWatcher);
                        popupWatcher = null;
                    }
                };

                const handleClose = () => {
                    if (resolved) {
                        return;
                    }

                    resolved = true;
                    cleanup();
                    resolve(selectedIds);
                };

                const updateCounter = () => {
                    try {
                        if (!popupWindow || !popupWindow.document) {
                            return;
                        }

                        if (!counterNode) {
                            counterNode = popupWindow.document.getElementById('pwrt-selected-counter');
                        }

                        if (!counterNode) {
                            counterNode = popupWindow.document.createElement('div');
                            counterNode.id = 'pwrt-selected-counter';
                            counterNode.style.position = 'fixed';
                            counterNode.style.right = '16px';
                            counterNode.style.top = '16px';
                            counterNode.style.zIndex = '9999';
                            counterNode.style.background = '#eef2f6';
                            counterNode.style.border = '1px solid #c5d0dc';
                            counterNode.style.borderRadius = '4px';
                            counterNode.style.padding = '6px 10px';
                            counterNode.style.color = '#1e1e1e';
                            counterNode.style.fontSize = '13px';
                            counterNode.style.fontFamily = 'Arial, sans-serif';

                            const container = popupWindow.document.body || popupWindow.document.documentElement;
                            if (container) {
                                container.appendChild(counterNode);
                            }
                        }

                        counterNode.textContent = 'Выбрано: ' + selectedIds.length;
                    } catch (e) {
                        // Игнорируем ошибки доступа к popup до готовности документа
                    }
                };

                const syncCallbackAliases = (handler) => {
                    const aliases = new Set([
                        callbackName,
                        'InS' + callbackToken,
                    ]);

                    try {
                        if (popupWindow && popupWindow.document && popupWindow.document.documentElement) {
                            const html = popupWindow.document.documentElement.innerHTML || '';
                            const matches = html.match(/\bInS[a-zA-Z0-9_]+\b/g) || [];
                            matches.forEach(function(alias) {
                                aliases.add(alias);
                            });
                        }
                    } catch (e) {
                        // Игнорируем ошибки доступа к DOM popup до готовности документа
                    }

                    aliases.forEach(function(alias) {
                        if (!alias) {
                            return;
                        }

                        if (window[alias] !== handler) {
                            window[alias] = handler;
                        }

                        registeredAliases.add(alias);
                    });
                };

                const overrideFunctions = () => {
                    if (functionsOverridden) return;

                    try {
                        if (!popupWindow || !popupWindow.document || !popupWindow.document.body) return;

                        const collectCheckedIds = function() {
                            const checkboxes = popupWindow.document.querySelectorAll('input[type="checkbox"][name="ID[]"]:checked');
                            checkboxes.forEach(function(checkbox) {
                                handleSelectedElement(checkbox.value);
                            });
                        };

                        const safeSelEl = function(id, name) {
                            handleSelectedElement(id);
                            updateCounter();
                            console.log('[PWRT] SelEl called:', id, name, 'selectedIds:', selectedIds);
                            return false;
                        };

                        const safeSelAll = function() {
                            collectCheckedIds();
                            console.log('[PWRT] SelAll called, collected IDs:', selectedIds);
                            popupWindow.close();
                            return false;
                        };

                        // Переопределяем стандартные и числовые варианты SelEl*/SelAll*.
                        popupWindow.SelEl = safeSelEl;
                        popupWindow.SelAll = safeSelAll;

                        Object.keys(popupWindow).forEach(function(key) {
                            if (/^SelEl\d+$/.test(key)) {
                                popupWindow[key] = safeSelEl;
                            }

                            if (/^SelAll\d+$/.test(key)) {
                                popupWindow[key] = safeSelAll;
                            }
                        });

                        functionsOverridden = true;
                        console.log('[PWRT] SelEl and SelAll overridden successfully');
                    } catch (e) {
                        console.warn('[PWRT] Failed to override functions:', e);
                    }
                };

                const handleSelectedElement = function (elementId) {
                    const parsedId = parseInt(elementId, 10);

                    if (!parsedId || isNaN(parsedId) || parsedId <= 0) {
                        return;
                    }

                    if (selectedIds.indexOf(parsedId) === -1) {
                        selectedIds.push(parsedId);
                    }

                    updateCounter();
                };

                // Bitrix в зависимости от режима popup может обращаться к callback
                // по разным алиасам. Синхронизируем все известные имена.
                syncCallbackAliases(handleSelectedElement);

                popupWindow = window.open(
                    url,
                    'pwrt-element-search-' + callbackName,
                    'width=900,height=700,resizable=yes,scrollbars=yes'
                );

                popupWatcher = setInterval(() => {
                    if (!popupWindow || popupWindow.closed) {
                        handleClose();
                        return;
                    }

                    syncCallbackAliases(handleSelectedElement);
                    overrideFunctions();
                    updateCounter();

                    try {
                        if (!closeListenerAttached) {
                            popupWindow.addEventListener('beforeunload', handleClose, { once: true });
                            closeListenerAttached = true;
                        }
                    } catch (e) {
                        // Игнорируем ошибки подписки, если окно ещё не инициализировалось
                    }
                }, 300);
            });
        }

        async fetchRefreshData(items) {
            items = this.withAuthoritativePreset(items);
            const mutationActions = this.presetMutationActions();
            const mutationItems = Array.isArray(items)
                ? items.filter((item) => item && typeof item === 'object' && mutationActions.has(item.action))
                : [];
            const globalMutationActions = this.globalMutationActions();
            const globalMutationItems = Array.isArray(items)
                ? items.filter((item) => item && typeof item === 'object' && globalMutationActions.has(item.action))
                : [];
            let expectedSemanticRevision = '';
            let expectedGlobalRevision = null;
            let expectedGlobalFingerprint = '';
            if (mutationItems.length > 0) {
                if (!Array.isArray(items) || items.length !== 1 || mutationItems.length !== 1) {
                    throw new Error('Изменение калькулятора должно отправляться одним агрегатным запросом.');
                }
                expectedSemanticRevision = String(this.initData?.semanticRevision || '').toLowerCase();
                if (!/^[a-f0-9]{64}$/.test(expectedSemanticRevision)) {
                    throw new Error('INIT не содержит точную ревизию семантики калькулятора. Обновите редактор.');
                }
            }
            if (globalMutationItems.length > 0) {
                if (!Array.isArray(items) || items.length !== 1 || globalMutationItems.length !== 1) {
                    throw new Error('Изменение общих данных должно отправляться одним агрегатным запросом.');
                }
                expectedGlobalRevision = this.initData?.globalMutationRevision;
                expectedGlobalFingerprint = String(this.initData?.globalMutationFingerprint || '').toLowerCase();
                if (!Number.isSafeInteger(expectedGlobalRevision) || expectedGlobalRevision < 0
                    || !/^sha256:[a-f0-9]{64}$/.test(expectedGlobalFingerprint)) {
                    throw new Error('INIT не содержит точную ревизию общих данных. Обновите редактор.');
                }
            }
            const debugItems = Array.isArray(items) ? items.map(item => {
                if (!item || typeof item !== 'object') return item;
                if (item.action === 'generateLogicProposal' || item.action === 'generateStageLogicProposal' || item.action === 'generateLogicAudit') {
                    const request = item.request && typeof item.request === 'object' ? item.request : {};
                    return {
                        action: item.action,
                        request: {
                            schema: request.schema || '',
                            baseFingerprint: request.baseFingerprint || '',
                            variableCode: request.variable && request.variable.code ? request.variable.code : '',
                            availableSymbolsCount: Array.isArray(request.availableSymbols) ? request.availableSymbols.length : 0,
                            availableSourcesCount: Array.isArray(request.availableSources) ? request.availableSources.length : 0,
                            entitiesCount: request.stage && Array.isArray(request.stage.entities) ? request.stage.entities.length : 0,
                            itemsCount: Array.isArray(request.items) ? request.items.length : 0,
                            intent: '[REDACTED]',
                            formula: '[REDACTED]',
                            currentLogic: '[REDACTED]',
                        },
                    };
                }
                if (!Object.prototype.hasOwnProperty.call(item, 'apiKey')) return item;
                return Object.assign({}, item, { apiKey: item.apiKey ? '[REDACTED]' : '' });
            }) : items;
            console.log('[BitrixBridge][DEBUG] fetchRefreshData START', {
                items: debugItems,
                ajaxEndpoint: this.config.ajaxEndpoint,
            });

            const payloadJson = JSON.stringify(items);
            const formData = new FormData();
            formData.append('action', 'refreshData');
            formData.append('payload', payloadJson);
            formData.append('sessid', this.config.sessid);
            if (expectedSemanticRevision) {
                formData.append('expectedSemanticRevision', expectedSemanticRevision);
                if (this.config.versionMode === 'edit') {
                    formData.append('versionOriginalPresetId', String(this.config.versionOriginalPresetId || ''));
                    formData.append('versionWorkingPresetId', String(this.config.presetId || ''));
                    formData.append('versionId', String(this.config.versionId || ''));
                }
            }
            if (expectedGlobalRevision !== null) {
                formData.append('expectedGlobalRevision', String(expectedGlobalRevision));
                formData.append('expectedGlobalFingerprint', expectedGlobalFingerprint);
            }

            console.log('[BitrixBridge][DEBUG] fetchRefreshData request', {
                action: 'refreshData',
                payload: JSON.stringify(debugItems),
                hasSessid: !!this.config.sessid,
            });

            try {
                const response = await fetch(this.config.ajaxEndpoint, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                });

                console.log('[BitrixBridge][DEBUG] fetchRefreshData response status:', response.status, response.ok);
                const responseText = await response.text();
                let data = null;
                try {
                    data = responseText ? JSON.parse(responseText) : null;
                } catch (parseError) {
                    console.error('[BitrixBridge][DEBUG] fetchRefreshData invalid JSON response', {
                        status: response.status,
                        parseError: parseError,
                    });
                }

                if (!response.ok) {
                    const serverMessage = data && (data.message || data.error || data.details);
                    throw new Error(serverMessage || ('HTTP error ' + response.status));
                }

                if (!data || typeof data !== 'object') {
                    throw new Error('Сервер вернул некорректный ответ');
                }

                const dataLength = Array.isArray(data.data) ? data.data.length : 0;
                console.log('[BitrixBridge][DEBUG] fetchRefreshData response data', {
                    success: data.success,
                    hasData: !!data.data,
                    dataLength: dataLength,
                    error: data.error || data.message,
                    rawData: dataLength <= 5 ? data : '[Large response - omitted]',
                });

                if (!data.success) {
                    throw new Error(data.message || data.error || 'Ошибка обновления данных');
                }

                const resultingSemanticRevision = String(data?.data?.[0]?.semanticRevision || '').toLowerCase();
                if (mutationItems.length === 1) {
                    if (!/^[a-f0-9]{64}$/.test(resultingSemanticRevision)) {
                        throw new Error('Сервер не вернул подтверждённую ревизию семантики калькулятора.');
                    }
                    const semanticReadback = data?.data?.[0]?.semanticReadback;
                    if (!semanticReadback || typeof semanticReadback !== 'object'
                        || !semanticReadback.preset || typeof semanticReadback.preset !== 'object'
                        || !semanticReadback.elementsStore || typeof semanticReadback.elementsStore !== 'object'
                        || !Array.isArray(semanticReadback.globalSymbols)) {
                        throw new Error('Сервер не вернул подтверждённое состояние калькулятора после сохранения.');
                    }
                    if (this.initData) {
                        this.initData = Object.assign({}, this.initData, semanticReadback, {
                            semanticRevision: resultingSemanticRevision,
                        });
                        data.data[0].initPayload = this.initData;
                    }
                }
                if (globalMutationItems.length === 1) {
                    const resultingGlobalRevision = data?.data?.[0]?.globalRevision;
                    const resultingGlobalFingerprint = String(data?.data?.[0]?.globalFingerprint || '').toLowerCase();
                    if (!Number.isSafeInteger(resultingGlobalRevision) || resultingGlobalRevision < 0
                        || !/^sha256:[a-f0-9]{64}$/.test(resultingGlobalFingerprint)) {
                        throw new Error('Сервер не вернул подтверждённую ревизию общих данных.');
                    }
                    if (this.initData) {
                        this.initData.globalMutationRevision = resultingGlobalRevision;
                        this.initData.globalMutationFingerprint = resultingGlobalFingerprint;
                    }
                }
                if (mutationItems.length === 1 && this.config.versionMode === 'edit') {
                    await this.syncVersionLogic();
                }

                return data.data || [];
            } catch (error) {
                console.error('[BitrixBridge][DEBUG] fetchRefreshData ERROR: ' + error.message);
                throw error;
            }
        }

        async syncVersionLogic() {
            const originalPresetId = Number(this.config.versionOriginalPresetId || 0);
            const workingPresetId = Number(this.config.presetId || 0);
            if (!Number.isSafeInteger(originalPresetId) || originalPresetId <= 0
                || !Number.isSafeInteger(workingPresetId) || workingPresetId <= 0
                || !/^v_[a-f0-9]{16,40}$/.test(this.config.versionId)
                || !/^[a-f0-9]{64}$/.test(this.config.versionContentHash)
                || !/^[a-f0-9]{64}$/.test(this.config.versionLogicHash)) {
                throw new Error('Редактор не содержит точный контекст черновика логики.');
            }
            const form = new URLSearchParams();
            form.set('sessid', this.config.sessid);
            form.set('payload', JSON.stringify({
                action: 'version_logic_sync',
                presetId: originalPresetId,
                versionId: this.config.versionId,
                workingPresetId: workingPresetId,
                expectedContentHash: this.config.versionContentHash,
                expectedLogicHash: this.config.versionLogicHash,
            }));
            const response = await fetch('/bitrix/tools/prospektweb.calc/control_center_editors.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: form.toString(),
                cache: 'no-store',
            });
            const result = await response.json().catch(() => null);
            if (!response.ok || !result || result.success !== true || !result.data) {
                throw new Error(result && result.error ? result.error : 'Не удалось закрепить логику в выбранном черновике.');
            }
            if (!/^[a-f0-9]{64}$/.test(result.data.contentHash)
                || !/^[a-f0-9]{64}$/.test(result.data.componentHash)) {
                throw new Error('Сервер не подтвердил ревизию черновика логики.');
            }
            this.config.versionContentHash = result.data.contentHash;
            this.config.versionLogicHash = result.data.componentHash;
        }

        authoritativePresetId() {
            const initializedPresetId = Number(this.initData?.preset?.id || 0);
            const configuredPresetId = Number(this.config?.presetId || 0);
            if (!Number.isSafeInteger(initializedPresetId) || initializedPresetId <= 0) {
                throw new Error('Authoritative preset ID is absent from INIT.');
            }
            if (configuredPresetId !== 0
                && (!Number.isSafeInteger(configuredPresetId)
                    || configuredPresetId <= 0
                    || configuredPresetId !== initializedPresetId)) {
                throw new Error('Configured preset ID does not match authoritative INIT.');
            }
            return initializedPresetId;
        }

        withAuthoritativePreset(items) {
            if (!Array.isArray(items)) return items;
            const guardedActions = new Set([
                ...this.presetMutationActions(),
                ...this.globalMutationActions(),
            ]);
            let presetId = 0;
            return items.map((item) => {
                if (!item || typeof item !== 'object' || !guardedActions.has(item.action)) {
                    return item;
                }
                if (!presetId) presetId = this.authoritativePresetId();
                return Object.assign({}, item, { presetId });
            });
        }

        presetMutationActions() {
            return new Set([
                'addDetailsToBinding',
                'addDetailToBinding',
                'addNewDetail',
                'addNewGroup',
                'addNewStage',
                'addStage',
                'changeCustomFieldsValue',
                'changeDetailLevel',
                'changeDetailSort',
                'changeEntityMeta',
                'changeEquipment',
                'changeMaterialVariant',
                'changeNameDetail',
                'changeOperationVariant',
                'changePricePreset',
                'changeProductType',
                'changeRootDetailSort',
                'changeSettings',
                'changeSortStage',
                'changeStageName',
                'cloneDetail',
                'cloneDetails',
                'clearPreset',
                'createCustomField',
                'deleteDetail',
                'deleteStage',
                'duplicateStage',
                'enrichPreset',
                'moveStage',
                'removeDetail',
                'renameDetail',
                'resolveCalculatorContract',
                'saveCalcLogic',
                'saveCalculatorGlobals',
                'saveGlobalSymbols',
                'saveAiCalculatorContext',
                'savePresetGlobals',
                'saveStageGroups',
                'saveStageUsedEntities',
                'selectFields',
                'updateSettingsProperty',
                'updateStageProperty',
            ]);
        }

        globalMutationActions() {
            return new Set([
                'createCatalogSection',
                'deleteCatalogTreeNode',
                'deletePriceSettingsPreset',
                'moveCatalogEntitySection',
                'renamePriceSettingsPreset',
                'saveAiSettings',
                'saveCatalogEntityMeta',
                'saveCatalogTreeElement',
                'saveCatalogTreeSection',
                'savePriceSettingsPreset',
                'saveSettingsEquipment',
            ]);
        }

        async sendPwrtRequest(type, payload, requestId) {
            const message = {
                protocol: MODULE_PROTOCOL,
                version: '1.0.0',
                source: MODULE_SOURCE,
                target: MODULE_TARGET,
                type: type,
                requestId: requestId || ('pwrt-' + Date.now()),
                timestamp: Date.now(),
                payload: payload || {},
            };

            const endpointUrl = this.config.ajaxEndpoint + (this.config.ajaxEndpoint.indexOf('?') >= 0 ? '&' : '?')
                + 'sessid=' + encodeURIComponent(this.config.sessid || '');

            const response = await fetch(endpointUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(message),
            });

            let data = null;
            try {
                data = await response.json();
            } catch (error) {
                throw new Error('Сервер вернул некорректный JSON (HTTP ' + response.status + ')');
            }

            if (!response.ok || (data && data.type === 'ERROR')) {
                const errorPayload = data && data.payload ? data.payload : {};
                throw new Error(errorPayload.message || errorPayload.error || ('Ошибка pwrt-запроса (HTTP ' + response.status + ')'));
            }

            return data;
        }

        /**
         * Валидация сообщения
         * @param {*} message
         * @returns {boolean}
         */
        isValidMessage(message) {
            if (!message || typeof message !== 'object') {
                return false;
            }

            if (!message.source || !message.target || !message.type) {
                return false;
            }

            return true;
        }

        /**
         * Расширенная валидация для логирования причин отказа
         * @param {*} message
         * @returns {{valid: boolean, reason?: string}}
         */
        validateMessage(message) {
            if (!message || typeof message !== 'object') {
                return { valid: false, reason: 'Message is not an object' };
            }

            if (message.source !== MODULE_TARGET) {
                return { valid: false, reason: 'Unexpected source' };
            }

            if (message.target !== MODULE_SOURCE) {
                return { valid: false, reason: 'Unexpected target' };
            }

            if (!message.type) {
                return { valid: false, reason: 'Missing type' };
            }

            if (message.protocol && message.protocol !== MODULE_PROTOCOL) {
                return { valid: false, reason: 'Unexpected protocol' };
            }

            return { valid: true };
        }

        /**
         * Отправка сообщения в iframe
         * @param {string} type - Тип сообщения
         * @param {*} payload - Данные
         * @param {string} [requestId] - ID запроса
         */
        sendMessageToIframe(type, payload, requestId) {
            if (!this.iframeWindow) {
                console.error('[CalcIntegration] Iframe window not available');
                return;
            }

            const message = {
                source: MODULE_SOURCE,
                target: MODULE_TARGET,
                type: type,
                payload: payload,
                timestamp: Date.now(),
            };

            if (requestId) {
                message.requestId = requestId;
            }

            const targetOrigin = this.targetOrigin || window.location.origin;

            if (type === 'INIT') {
                this.logBridge('[BitrixBridge] sending INIT -> ' + this.describeIframe(this.iframe), {
                    targetOrigin: targetOrigin,
                    iframeSrc: this.iframe ? this.iframe.getAttribute('src') : null,
                    summary: this.buildInitSummary(payload),
                });
            }

            this.logDebug('[CalcIntegration] Sending message:', type, message);
            this.iframeWindow.postMessage(message, targetOrigin);
        }

        /**
         * Обработка READY
         */
        async handleSaveUserThemeRequest(message, origin) {
            const allowedThemes = ['dark', 'cream', 'monolith', 'obsidian', 'soft-graphite'];
            const requestedTheme = message.payload && message.payload.theme;
            const theme = allowedThemes.includes(requestedTheme) ? requestedTheme : 'dark';
            const formData = new FormData();
            formData.append('action', 'saveUserTheme');
            formData.append('theme', theme);
            formData.append('sessid', this.config.sessid);

            try {
                const response = await fetch(this.config.ajaxEndpoint, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                });
                const data = await response.json();
                if (!response.ok || !data || !data.success) {
                    throw new Error((data && (data.message || data.error)) || ('HTTP error ' + response.status));
                }

                if (this.initData && this.initData.context) {
                    this.initData.context.editorTheme = theme;
                }
                this.sendPwrtMessage('SAVE_USER_THEME_RESPONSE', {
                    status: 'success',
                    theme: theme,
                }, message.requestId, origin);
            } catch (error) {
                console.error('[BitrixBridge] SAVE_USER_THEME_REQUEST error:', error);
                this.sendPwrtMessage('ERROR', {
                    code: 'SAVE_USER_THEME_FAILED',
                    message: 'Не удалось сохранить тему редактора',
                    details: error.message,
                }, message.requestId, origin);
            }
        }

        async handleReady(message, event) {
            this.logDebug('[CalcIntegration] Iframe is ready, loading init data...');

            if (event && event.origin) {
                this.readyOrigin = event.origin;
            }

            try {
                // Product-card launch may preload the exact authoritative INIT
                // before opening the dialog. Standalone/manual launch loads it here.
                const initData = this.config.initPayload || await this.fetchInitData();
                this.config.initPayload = null;
                // INIT is read-only. An empty graph is created only through the
                // explicit simple/complex foundation choice in Control Center.
                this.initData = initData;

                // Отправляем INIT в iframe
                this.sendMessageToIframe('INIT', initData, message.requestId);
            } catch (error) {
                console.error('[CalcIntegration] Error in handleReady:', error);
                this.sendMessageToIframe('ERROR', {
                    message: 'Ошибка загрузки данных инициализации',
                    details: error.message,
                }, message.requestId);
            }
        }

        async handleCatalogWriteLifecycleRequest(message, origin, requestType, resultType) {
            try {
                const response = await this.sendPwrtRequest(requestType, message.payload || {}, message.requestId);
                if (!response || response.type !== resultType || !response.payload) {
                    throw new Error('Сервер вернул некорректный ответ операции записи каталога');
                }
                this.sendPwrtMessage(resultType, response.payload, message.requestId, origin);
            } catch (error) {
                this.sendPwrtMessage('ERROR', {
                    code: requestType === 'PREVIEW_CATALOG_WRITE_REQUEST'
                        ? 'CATALOG_WRITE_PREVIEW_FAILED'
                        : 'CATALOG_WRITE_APPLY_FAILED',
                    message: error && error.message
                        ? error.message
                        : 'Не удалось выполнить операцию записи каталога',
                    requestType: requestType,
                }, message.requestId, origin);
            }
        }

        /**
         * Обработка INIT_DONE
         */
        handleInitDone(message) {
            this.logDebug('[CalcIntegration] Initialization completed');
            this.isInitialized = true;
        }


        /**
         * Обработка CLOSE_REQUEST
         */
        async handleCloseRequest(message) {
            this.logDebug('[CalcIntegration] Close request received');

            const hasUnsavedChanges = this.hasUnsavedChanges
                || Boolean(message && message.payload && message.payload.hasChanges);
            if (hasUnsavedChanges) {
                const confirmed = window.ProspekwebCalc
                    ? await window.ProspekwebCalc.showConfirmation(
                        'Есть несохранённые изменения. Вы уверены, что хотите закрыть окно?',
                        'Несохранённые изменения',
                        'Закрыть'
                    )
                    : false;
                if (!confirmed) {
                    return;
                }
            }

            if (typeof this.config.onClose === 'function') {
                this.config.onClose();
            } else {
                // По умолчанию закрываем окно/попап
                if (window.BX && window.BX.PopupWindow) {
                    // Если используется BX.PopupWindow
                    const popup = window.BX.PopupWindow.getById('calc-popup');
                    if (popup) {
                        popup.close();
                    }
                } else {
                    window.close();
                }
            }
        }

        /**
         * Обработка ERROR
         */
        handleError(message) {
            console.error('[CalcIntegration] Error from iframe:', message.payload);

            if (typeof this.config.onError === 'function') {
                this.config.onError(message.payload);
            } else {
                var errorMessage = (message.payload && message.payload.message) ? message.payload.message : 'Неизвестная ошибка';
                if (window.ProspekwebCalc) {
                    window.ProspekwebCalc.showMessage('Ошибка: ' + errorMessage, 'Ошибка калькулятора');
                }
            }
        }

        /**
         * Получение данных инициализации через AJAX
         * @returns {Promise<Object>}
         */
        async fetchInitData() {
            const body = new URLSearchParams();
            body.set('sessid', this.config.sessid);
            const isVersionLaunch = this.config.versionMode === 'edit' || this.config.versionMode === 'readonly';
            let endpoint = this.config.ajaxEndpoint;
            if (isVersionLaunch) {
                const originalPresetId = Number(this.config.versionOriginalPresetId || 0);
                const workingPresetId = Number(this.config.presetId || 0);
                if (!Number.isSafeInteger(originalPresetId) || originalPresetId <= 0
                    || !Number.isSafeInteger(workingPresetId) || workingPresetId <= 0
                    || !/^v_[a-f0-9]{16,40}$/.test(this.config.versionId)
                    || !/^[a-f0-9]{64}$/.test(this.config.versionContentHash)
                    || !/^[a-f0-9]{64}$/.test(this.config.versionLogicHash)) {
                    throw new Error('Редактор не содержит точный контекст выбранной версии логики.');
                }
                endpoint = this.config.versionAjaxEndpoint;
                body.set('payload', JSON.stringify({
                    action: 'version_logic_init',
                    presetId: originalPresetId,
                    versionId: this.config.versionId,
                    workingPresetId: workingPresetId,
                    mode: this.config.versionMode,
                    expectedContentHash: this.config.versionContentHash,
                    expectedLogicHash: this.config.versionLogicHash,
                    siteId: this.config.siteId,
                }));
            } else {
                body.set('action', 'getInitData');
                body.set('offerIds', this.config.offerIds.join(','));
                body.set('presetId', String(this.config.presetId || ''));
                body.set('siteId', this.config.siteId);
            }

            const startedAt = (window.performance && window.performance.now) ? window.performance.now() : Date.now();
            this.logBridge('[BitrixBridge] AJAX getInitData start', {
                offerIdsCount: this.config.offerIds.length,
                presetId: this.config.presetId,
                siteId: this.config.siteId,
            });

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: body.toString(),
                });

                const duration = ((window.performance && window.performance.now) ? window.performance.now() : Date.now()) - startedAt;

                const responseText = await response.text();
                let data = null;
                try {
                    data = responseText ? JSON.parse(responseText) : null;
                } catch (_parseError) {
                    data = null;
                }

                if (!response.ok) {
                    this.logBridge('[BitrixBridge] AJAX getInitData error response', {
                        status: response.status,
                        durationMs: Math.round(duration),
                    });
                    const serverMessage = data && (data.message || data.error || data.details);
                    throw new Error(serverMessage || ('HTTP error ' + response.status));
                }

                if (!data || typeof data !== 'object') {
                    throw new Error('Сервер вернул некорректный ответ инициализации');
                }

                if (!data.success) {
                    this.logBridge('[BitrixBridge] AJAX getInitData business error', {
                        durationMs: Math.round(duration),
                        message: data.message || data.error,
                    });
                    throw new Error(data.message || data.error || 'Ошибка получения данных');
                }

                this.logBridge('[BitrixBridge] AJAX getInitData success', {
                    durationMs: Math.round(duration),
                    status: 'ok',
                    summary: this.buildInitSummary(data.data),
                });

                return data.data;
            } catch (error) {
                const duration = ((window.performance && window.performance.now) ? window.performance.now() : Date.now()) - startedAt;
                this.logBridge('[BitrixBridge] AJAX getInitData failed', {
                    durationMs: Math.round(duration),
                    status: 'error',
                    message: error.message,
                });
                throw error;
            }
        }

        /**
         * Обогащение пресета связями на основе выбранных деталей
         * @param {Object} params - параметры обогащения
         * @returns {Promise<Object>}
         */
        async enrichPreset(params) {
            console.log('[BitrixBridge] enrichPreset start', {
                presetId: params.presetId,
                detailIds: params.detailIds,
                binding: params.binding,
                existingDetailId: params.existingDetailId,
            });

            try {
                const result = await this.fetchRefreshData([{
                    action: 'enrichPreset',
                    presetId: Number(params.presetId),
                    detailIds: Array.isArray(params.detailIds) ? params.detailIds.map(Number) : [],
                    binding: params.binding === true,
                    existingDetailId: Number(params.existingDetailId || 0),
                }]);
                const enriched = Array.isArray(result) ? result[0] : null;
                if (!enriched?.initPayload) {
                    throw new Error('Сервер не вернул подтверждённое состояние обогащённого пресета.');
                }

                console.log('[BitrixBridge] enrichPreset success');

                return {
                    success: true,
                    data: enriched.initPayload,
                    rootDetailId: Number(enriched.rootDetailId || 0),
                };
            } catch (error) {
                console.error('[BitrixBridge] enrichPreset failed', {
                    message: error.message,
                });
                throw error;
            }
        }

        /**
         * Сохранение данных через AJAX
         * @param {Object} payload
         * @returns {Promise<Object>}
         */
        async saveData(payload) {
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('payload', JSON.stringify(payload));
            formData.append('sessid', this.config.sessid);

            const response = await fetch(this.config.ajaxEndpoint, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });

            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || data.error || 'Ошибка сохранения данных');
            }

            return data.data;
        }

        /**
         * Уничтожение интеграции
         */
        destroy() {
            window.removeEventListener('message', this.boundHandleMessage);

            if (this.pendingFormEditorRequest?.timeoutId !== null
                && typeof window.clearTimeout === 'function') {
                window.clearTimeout(this.pendingFormEditorRequest.timeoutId);
            }
            this.pendingFormEditorRequest = null;

            if (this.iframe && this.iframe.__calcIntegrationInstance === this) {
                delete this.iframe.__calcIntegrationInstance;
            }
        }

        /**
         * Логирование отладочной информации
         * @param  {...any} args
         */
        logDebug(...args) {
            if (this.debug) {
                console.log(...args);
            }
        }

        /**
         * Универсальное логирование в консоль/BX.debug
         */
        logBridge(message, details) {
            if (details !== undefined) {
                console.log(message, details);
                if (window.BX && typeof window.BX.debug === 'function') {
                    window.BX.debug({ message: message, details: details });
                }
            } else {
                console.log(message);
                if (window.BX && typeof window.BX.debug === 'function') {
                    window.BX.debug({ message: message });
                }
            }
        }

        /**
         * Построение краткой сводки INIT payload
         */
        buildInitSummary(payload) {
            return {
                mode: payload ? payload.mode : null,
                offers: payload && payload.selectedOffers ? payload.selectedOffers.length : 0,
                ib_offers: payload && payload.iblocks ? (this.findIblockIdByCode(payload.iblocks, 'OFFERS')) : undefined,
                ib_products: payload && payload.iblocks ? (this.findIblockIdByCode(payload.iblocks, 'PRODUCTS')) : undefined,
                lang: payload && payload.context ? payload.context.lang : undefined,
                url: payload && payload.context ? payload.context.url : undefined,
            };
        }

        /**
         * Поиск инфоблока по коду в массиве объектов
         */
        findIblockByCode(iblocksOrCode, maybeCode) {
            const hasSeparateCode = typeof maybeCode !== 'undefined';
            const code = hasSeparateCode ? maybeCode : iblocksOrCode;
            const iblocks = hasSeparateCode ? iblocksOrCode : (this.initData?.iblocks || []);
            const items = iblocks || [];
            return items.find((item) => item && item.code === code) || null;
        }

        /**
         * Получить ID инфоблока по коду
         */
        findIblockIdByCode(iblocksOrCode, maybeCode) {
            const iblock = this.findIblockByCode(iblocksOrCode, maybeCode);
            return iblock ? iblock.id : null;
        }

        /**
         * Текстовое описание iframe для логов
         */
        describeIframe(iframe) {
            if (!iframe) {
                return 'iframe:not-found';
            }

            const id = iframe.id ? ('#' + iframe.id) : null;
            const name = iframe.getAttribute('name');
            return id || name || 'iframe';
        }
    }

    // Экспорт в глобальную область
    window.ProspektwebCalcIntegration = CalcIntegration;

})(window);
