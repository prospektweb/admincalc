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
                offerIds: config.offerIds || [],
                siteId: config.siteId || '',
                sessid: config.sessid || '',
                onClose: config.onClose || null,
                onError: config.onError || null,
                presetCheckResult: config.presetCheckResult || null,
                initPayload: config.initPayload || null,
            };

            this.iframe = null;
            this.iframeWindow = null;
            this.isInitialized = false;
            this.hasUnsavedChanges = false;
            this.debug = Boolean(config.debug);
            this.targetOrigin = '*';
            this.readyOrigin = null;
            this.pendingRequests = {};
            this.initData = null;
            this.currentSelectionItems = null;

            // Сохраняем ссылку на обработчик для корректного removeEventListener
            this.boundHandleMessage = this.handleMessage.bind(this);

            this.logBridge('[BitrixBridge] ProspektwebCalcIntegration created', {
                iframe: config.iframe ? this.describeIframe(config.iframe) : this.config.iframeSelector,
                ajaxUrl: this.config.ajaxEndpoint,
                offerIds: this.config.offerIds,
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
            this.iframe.__calcIntegrationInstance = this;
            this.setupMessageListener();
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
            // Проверка origin (в продакшене нужно проверять конкретный домен)
            // if (event.origin !== window.location.origin) {
            //     return;
            // }

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

            const origin = (event && event.origin) ? event.origin : (this.targetOrigin || '*');
            if (origin && origin !== '*') {
                this.targetOrigin = origin;
            }

            // Note: pwcode parameter was removed from the protocol as it was unused
            // and caused unnecessary log pollution (pwcode: undefined)
            console.info('[FROM_IFRAME]', {
                type: message.type,
                requestId: message.requestId,
                payload: message.payload,
            });

            console.log('[BitrixBridge][DEBUG] Routing message type:', message.type);

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
                case 'REMOVE_OFFER_REQUEST':
                    this.handleRemoveOfferRequest(message, origin);
                    break;
                case 'ADD_DETAIL_REQUEST':
                    await this.handleAddNewDetailRequest(message, origin);
                    break;
                case 'ADD_STAGE_REQUEST':
                    await this.handleAddStageRequest(message, origin);
                    break;
                case 'DELETE_STAGE_REQUEST':
                    await this.handleDeleteStageRequest(message, origin);
                    break;
                case 'SAVE_OPTIONAL_STAGE_REQUEST':
                    await this.handleSaveOptionalStageRequest(message, origin);
                    break;
                case 'REMOVE_DETAIL_REQUEST':
                    await this.handleRemoveDetailRequest(message, origin);
                    break;
                case 'RENAME_DETAIL_REQUEST':
                    await this.handleRenameDetailRequest(message, origin);
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
                case 'SAVE_SETTINGS_EQUIPMENT_REQUEST':
                    await this.handleSaveSettingsEquipmentRequest(message, origin);
                    break;
                case 'CHANGE_STAGE_NAME_REQUEST':
                    await this.handleChangeStageNameRequest(message, origin);
                    break;
                case 'CHANGE_ENTITY_META_REQUEST':
                    await this.handleChangeEntityMetaRequest(message, origin);
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
                case 'GET_CATALOG_ENTITY_META_REQUEST':
                    await this.handleGetCatalogEntityMetaRequest(message, origin);
                    break;
                case 'SAVE_CATALOG_ENTITY_META_REQUEST':
                    await this.handleSaveCatalogEntityMetaRequest(message, origin);
                    break;
                case 'CLEAR_PRESET_REQUEST':
                    await this.handleClearPresetRequest(message, origin);
                    break;
                case 'SAVE_PRESET_GLOBALS_REQUEST':
                    await this.handleSavePresetGlobalsRequest(message, origin);
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
                case 'CHANGE_DETAIL_LEVEL_REQUEST':
                    await this.handleChangeDetailLevelRequest(message, origin);
                    break;
                case 'CHANGE_SORT_STAGE_REQUEST':
                    await this.handleChangeSortStageRequest(message, origin);
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
                case 'SAVE_CALCULATION_REQUEST':
                    await this.handleSaveCalculationRequest(message, origin);
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
                default:
                    console.warn('[BitrixBridge][DEBUG] Unknown pwrt message type:', message.type);
                    console.warn('[BitrixBridge][DEBUG] Known types:', [
                        'SELECT_REQUEST', 'SELECT_DETAILS_REQUEST', 'SELECT_FIELDS_REQUEST', 'SELECT_DETAILS_TO_BINDING_REQUEST',
                        'ADD_DETAIL_REQUEST', 'ADD_DETAIL_TO_BINDING_REQUEST',
                        'ADD_STAGE_REQUEST', 'DELETE_STAGE_REQUEST', 'REMOVE_DETAIL_REQUEST', 
                        'RENAME_DETAIL_REQUEST', 'CHANGE_SETTINGS_REQUEST', 'CHANGE_OPERATION_VARIANT_REQUEST', 
                        'CHANGE_EQUIPMENT_REQUEST', 'CHANGE_MATERIAL_VARIANT_REQUEST',
                        'CHANGE_CUSTOM_FIELDS_VALUE_REQUEST', 'CLONE_DETAIL_REQUEST',
                        'SAVE_SETTINGS_EQUIPMENT_REQUEST', 'CHANGE_STAGE_NAME_REQUEST', 'CHANGE_ENTITY_META_REQUEST',
                        'GET_AI_SETTINGS_REQUEST', 'SAVE_AI_SETTINGS_REQUEST', 'GENERATE_STAGE_PREVIEW_REQUEST',
                        'CHANGE_DETAIL_SORT_REQUEST', 'CHANGE_DETAIL_LEVEL_REQUEST', 'CHANGE_SORT_STAGE_REQUEST',
                        'CHANGE_PRICE_PRESET_REQUEST',
                        'CHANGE_OPTIONS_OPERATION', 'CHANGE_OPTIONS_MATERIAL', 'CHANGE_OPTIONS_EQUIPMENT',
                        'SAVE_CALC_LOGIC_REQUEST',
                        'SAVE_CALCULATION_REQUEST',
                        'CLEAR_OPTIONS_OPERATION', 'CLEAR_OPTIONS_MATERIAL', 'CLEAR_OPTIONS_EQUIPMENT',
                        'CLEAR_PRESET_REQUEST', 'SAVE_PRESET_GLOBALS_REQUEST', 'CLOSE_REQUEST'
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

            const origin = targetOrigin || this.targetOrigin || '*';
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

                    console.log('[BitrixBridge] updateSettingsPropertyInInitDataWithRaw: обновлены настройки', {
                        settingsId: settingsId,
                        propertyCode: propertyCode,
                        value: value ? value.substring(0, 50) + '...' : '(пусто)'
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

            const selectedIds = await this.openElementSelectionDialog({
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
            const settingsId = parseInt(payload.settingsId, 10) || 0;
            const stageId = parseInt(payload.stageId, 10) || 0;
            const presetId = parseInt(payload.presetId, 10) || 0;

            const customFieldsIblock = this.findIblockByCode('CALC_CUSTOM_FIELDS');
            const iblockId = customFieldsIblock?.id || null;
            const iblockType = customFieldsIblock?.type || null;
            const lang = payload.lang || (this.initData?.lang) || null;

            const selectedIds = await this.openElementSelectionDialog({ iblockId, iblockType, lang });
            if (!selectedIds || selectedIds.length === 0) {
                return;
            }

            try {
                const selectResult = await this.fetchRefreshData([{ action: 'selectFields', settingsId, stageId, presetId, customFieldIds: selectedIds, offerIds: this.config.offerIds || [] }]);
                const selectPayload = Array.isArray(selectResult) ? selectResult[0] : null;
                if (selectPayload?.initPayload) {
                    this.initData = selectPayload.initPayload;
                    this.sendPwrtMessage('INIT', selectPayload.initPayload, message.requestId, origin);
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

            const selectedIds = await this.openElementSelectionDialog({
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
                const existingDetails = this.initData?.preset?.properties?.CALC_DETAILS || [];
                const existingDetailId = existingDetails.length > 0 ? existingDetails[0] : 0;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                // Вызываем обогащение пресета
                const enrichResult = await this.enrichPreset({
                    presetId: presetId,
                    detailIds: selectedIds,
                    binding: binding,
                    existingDetailId: existingDetailId,
                    offerIds: this.config.offerIds,
                    siteId: this.config.siteId,
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
            const binding = payload.binding || false;
            const name = payload.name || '';
            const offerIds = payload.offerIds || [];

            try {
                // Получаем presetId и существующую деталь из initData
                const presetId = this.initData?.preset?.id;
                const existingDetails = this.initData?.preset?.properties?.CALC_DETAILS || [];
                const existingDetailId = existingDetails.length > 0 ? existingDetails[0] : 0;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                // Шаг 1: Создаём новую деталь (с 1 этапом, который создаётся автоматически)
                const createResult = await this.fetchRefreshData([
                    {
                        action: 'addNewDetail',
                        offerIds: offerIds,
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

                // Шаг 2: Определяем список деталей для обогащения
                let detailIds = [newDetailId];
                
                if (binding && existingDetailId > 0) {
                    // Если binding=true и есть существующая деталь, создаём скрепление
                    detailIds = [newDetailId]; // Новая деталь будет в списке для создания скрепления
                } else if (binding && existingDetailId === 0) {
                    // Если binding=true но нет существующей детали, используем только новую
                    detailIds = [newDetailId];
                } else {
                    // Если binding=false, используем только новую деталь
                    detailIds = [newDetailId];
                }

                // Шаг 3: Вызываем обогащение пресета
                const enrichResult = await this.enrichPreset({
                    presetId: presetId,
                    detailIds: detailIds,
                    binding: binding,
                    existingDetailId: existingDetailId,
                    offerIds: this.config.offerIds,
                    siteId: this.config.siteId,
                });

                if (enrichResult.success && enrichResult.data) {
                    // Обновляем локальный initData
                    this.initData = enrichResult.data;
                    
                    // Шаг 4: Отправляем INIT message вместо ADD_DETAIL_RESPONSE
                    this.sendPwrtMessage('INIT', enrichResult.data, message.requestId, origin);
                    
                    console.log('[BitrixBridge][DEBUG] handleAddNewDetailRequest END - success, INIT sent');
                } else {
                    throw new Error(enrichResult.message || 'Ошибка обогащения пресета');
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
                const result = await this.fetchRefreshData([{ action: 'cloneDetail', detailId, presetId }]);
                const responsePayload = (Array.isArray(result) && result[0]) ? result[0] : null;
                if (!responsePayload || responsePayload.status !== 'ok') {
                    throw new Error(responsePayload?.message || 'Не удалось клонировать деталь');
                }

                const enrichResult = await this.enrichPreset({
                    presetId,
                    detailIds: [responsePayload.rootDetailId || detailId],
                    binding: false,
                    existingDetailId: 0,
                    offerIds: this.config.offerIds || [],
                    siteId: this.config.siteId || SITE_ID,
                });

                if (enrichResult.success && enrichResult.data) {
                    this.initData = enrichResult.data;
                    this.sendPwrtMessage('INIT', enrichResult.data, message.requestId, origin);
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
            if (!equipmentId) {
                this.sendPwrtMessage('SAVE_SETTINGS_EQUIPMENT_RESPONSE', {
                    status: 'error',
                    message: 'Не указано оборудование',
                }, message.requestId, origin);
                return;
            }

            try {
                const result = await this.fetchRefreshData([{ action: 'saveSettingsEquipment', equipmentId, name, properties }]);
                const responsePayload = Array.isArray(result) && result[0]
                    ? result[0]
                    : { status: 'error', message: 'Пустой ответ сохранения оборудования' };
                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось сохранить оборудование');
                }

                const equipmentItems = this.initData && this.initData.elementsStore
                    ? this.initData.elementsStore.CALC_EQUIPMENT
                    : null;
                if (Array.isArray(equipmentItems)) {
                    const equipment = equipmentItems.find((item) => Number(item.id) === equipmentId);
                    if (equipment) {
                        if (responsePayload.name) {
                            equipment.name = responsePayload.name;
                        }
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
                    equipmentId: equipmentId,
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

        async handleSavePresetGlobalsRequest(message, origin) {
            const payload = message.payload || {};
            try {
                const result = await this.fetchRefreshData([{
                    action: 'savePresetGlobals',
                    presetId: Number(payload.presetId || 0),
                    variables: Array.isArray(payload.variables) ? payload.variables : [],
                    constants: Array.isArray(payload.constants) ? payload.constants : [],
                    offerIds: this.config.offerIds || [],
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
                const result = await this.fetchRefreshData([{ action: 'saveCatalogEntityMeta', entityType: payload.entityType, entities: Array.isArray(payload.entities) ? payload.entities : [] }]);
                const response = Array.isArray(result) ? result[0] : { status: 'error' };
                this.sendPwrtMessage('CATALOG_ENTITY_META_RESPONSE', response, message.requestId, origin);
                if (response && response.status === 'ok') {
                    await this.handleRefreshRequest({ requestId: message.requestId, payload: {} }, origin);
                }
            } catch (error) {
                this.sendPwrtMessage('CATALOG_ENTITY_META_RESPONSE', { status: 'error', message: error && error.message ? error.message : 'Не удалось сохранить данные' }, message.requestId, origin);
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
                const offerIds = this.config.offerIds || [];
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
                        optional: payload.optional === true,
                        presetId: presetId,
                        offerIds: offerIds,
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

                // Отправляем INIT message
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);

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
                const offerIds = this.config.offerIds || [];
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
                        offerIds: offerIds,
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
            const detailId = payload.detailId || 0;

            try {
                // Получаем presetId из initData
                const presetId = this.initData?.preset?.id;
                const offerIds = this.config.offerIds || [];
                const siteId = this.config.siteId || SITE_ID;

                if (!presetId) {
                    throw new Error('Preset ID не найден');
                }

                if (parentId <= 0 || detailId <= 0) {
                    throw new Error('Parent ID и Detail ID обязательны');
                }

                // Вызываем удаление детали через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'removeDetail',
                        parentId: parentId,
                        detailId: detailId,
                        presetId: presetId,
                        offerIds: offerIds,
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
                const offerIds = this.config.offerIds || [];
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
                        offerIds: offerIds,
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
                const offerIds = this.config.offerIds || [];
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
                        offerIds: offerIds,
                        siteId: siteId,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось обновить вариант операции');
                }

                // Очищаем OPTIONS_OPERATION, т.к. старые настройки не актуальны для нового варианта
                await this.fetchRefreshData([{
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'OPTIONS_OPERATION',
                    value: ''
                }]);

                // Обновляем локальный initData
                if (responsePayload.initPayload) {
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
                const offerIds = this.config.offerIds || [];
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
                        offerIds: offerIds,
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
                if (responsePayload.initPayload) {
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
                const offerIds = this.config.offerIds || [];
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
                        offerIds: offerIds,
                        siteId: siteId,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    throw new Error(responsePayload.message || 'Не удалось обновить вариант материала');
                }

                // Очищаем OPTIONS_MATERIAL, т.к. старые настройки не актуальны для нового варианта
                await this.fetchRefreshData([{
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'OPTIONS_MATERIAL',
                    value: ''
                }]);

                // Обновляем локальный initData
                if (responsePayload.initPayload) {
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
         * 3. Ничего не отправлять (режим тишины)
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
                    console.error('[BitrixBridge] Stage ID и массив customFieldsValue обязательны');
                    // В режиме тишины не отправляем ошибку
                    return;
                }

                // Вызываем обновление через AJAX
                const result = await this.fetchRefreshData([
                    {
                        action: 'changeCustomFieldsValue',
                        stageId: stageId,
                        customFieldsValue: customFieldsValue,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                if (responsePayload.status !== 'ok') {
                    console.error('[BitrixBridge] changeCustomFieldsValue error:', responsePayload.message);
                    // В режиме тишины не отправляем ошибку
                    return;
                }

                console.log('[BitrixBridge] changeCustomFieldsValue success for stageId:', stageId);
                // В режиме тишины НЕ отправляем ответ обратно во фрейм

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleChangeCustomFieldsValue ERROR', {
                    error: error,
                    message: error.message,
                });
                // В режиме тишины не отправляем ошибку
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
                const offerIds = this.config.offerIds || [];
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
                        offerIds: offerIds,
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

                // 1. Показать окно выбора деталей
                const selectedIds = await this.openElementSelectionDialog({
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
                const offerIds = this.config.offerIds || [];
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
                        offerIds: offerIds,
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
                const offerIds = this.config.offerIds || [];
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
                        offerIds: offerIds,
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
                const offerIds = this.config.offerIds || [];
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
                        offerIds: offerIds,
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
                const offerIds = this.config.offerIds || [];
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
                        offerIds: offerIds,
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

                this.sendPwrtMessage(
                    'ERROR',
                    {
                        message: 'Ошибка изменения сортировки этапов',
                        details: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
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
                const offerIds = this.config.offerIds || [];
                const siteId = this.config.siteId || SITE_ID;

                if (!presetId) {
                    throw new Error('PresetId not found in initData');
                }

                // Вызываем очистку пресета через AJAX
                const url = this.config.ajaxEndpoint +
                    '?action=clearPreset' +
                    '&presetId=' + encodeURIComponent(presetId) +
                    '&offerIds=' + encodeURIComponent(offerIds.join(',')) +
                    '&siteId=' + encodeURIComponent(siteId) +
                    '&sessid=' + encodeURIComponent(this.config.sessid);

                const response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error('HTTP error ' + response.status);
                }

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.message || data.error || 'Ошибка очистки пресета');
                }

                console.log('[BitrixBridge] clearPreset success for presetId:', presetId);

                // Обновляем локальный initData если есть
                if (data.data) {
                    this.initData = data.data;
                }

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

            try {
                // Получаем presetId из initData
                const presetId = this.initData?.preset?.id;
                const offerIds = this.config.offerIds || [];
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
                        offerIds: offerIds,
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
                return;
            }
            
            try {
                // 1. Сохраняем на сервере
                await this.fetchRefreshData([{
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'OPTIONS_OPERATION',
                    value: json
                }]);
                
                // 2. Лёгкое обогащение - обновляем локально this.initData
                this.updateStagePropertyInInitData(stageId, 'OPTIONS_OPERATION', json);
                
                // 3. Отправляем модифицированный INIT
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                
            } catch (error) {
                console.error('[BitrixBridge] CHANGE_OPTIONS_OPERATION error:', error);
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
                return;
            }
            
            try {
                // 1. Сохраняем на сервере
                await this.fetchRefreshData([{
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'OPTIONS_MATERIAL',
                    value: json
                }]);
                
                // 2. Лёгкое обогащение
                this.updateStagePropertyInInitData(stageId, 'OPTIONS_MATERIAL', json);
                
                // 3. Отправляем модифицированный INIT
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                
            } catch (error) {
                console.error('[BitrixBridge] CHANGE_OPTIONS_MATERIAL error:', error);
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
                return;
            }
            try {
                await this.fetchRefreshData([{
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'OPTIONS_EQUIPMENT',
                    value: json
                }]);
                this.updateStagePropertyInInitData(stageId, 'OPTIONS_EQUIPMENT', json);
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                console.error('[BitrixBridge] CHANGE_OPTIONS_EQUIPMENT error:', error);
            }
        }

        async handleSaveOptionalStageRequest(message, origin) {
            const payload = message.payload || {};
            const stageId = parseInt(payload.stageId, 10);
            const condition = payload.condition && typeof payload.condition === 'object'
                ? payload.condition
                : { version: 1, enabled: true, kind: null, code: '' };
            if (!stageId) {
                this.sendPwrtMessage('ERROR', { message: 'Не указан этап для сохранения условия' }, message.requestId, origin);
                return;
            }
            try {
                const value = JSON.stringify({
                    version: 1,
                    enabled: condition.enabled === true,
                    kind: condition.kind === 'variable' || condition.kind === 'constant' ? condition.kind : null,
                    code: String(condition.code || '').trim(),
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
                    message: 'Не удалось сохранить условие опционального этапа',
                    details: error && error.message ? error.message : 'Unknown error',
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
            const inputs = Array.isArray(stageWiring.inputs) ? stageWiring.inputs : [];
            const outputs = Array.isArray(stageWiring.outputs) ? stageWiring.outputs : [];
            const globalAssignments = typeof stageWiring.globalAssignments === 'string'
                ? stageWiring.globalAssignments
                : '{"version":1,"assignments":[]}';
            const schemeOffer = Array.isArray(stageParametrValuesScheme.offer)
                ? stageParametrValuesScheme.offer
                : [];

            const toValueDescriptionList = (items, valueKey, descriptionKey) => {
                if (!Array.isArray(items) || items.length === 0) {
                    return false;
                }

                return items.map((item) => ({
                    VALUE: item?.[valueKey] ?? '',
                    DESCRIPTION: item?.[descriptionKey] ?? '',
                }));
            };

            const refreshPayload = [];

            if (settingsId) {
                refreshPayload.push({
                    action: 'updateSettingsProperty',
                    settingsId: settingsId,
                    propertyCode: 'LOGIC_JSON',
                    value: rawLogicJson,
                });
                refreshPayload.push({
                    action: 'updateSettingsProperty',
                    settingsId: settingsId,
                    propertyCode: 'PARAMS',
                    value: toValueDescriptionList(params, 'name', 'type'),
                });
            }

            if (stageId) {
                refreshPayload.push({
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'INPUTS',
                    value: toValueDescriptionList(inputs, 'name', 'path'),
                });
                refreshPayload.push({
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'OUTPUTS',
                    value: toValueDescriptionList(outputs, 'key', 'var'),
                });
                refreshPayload.push({
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'SCHEME_PARAMETR_VALUES',
                    value: toValueDescriptionList(schemeOffer, 'name', 'template'),
                });
                refreshPayload.push({
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'GLOBAL_ASSIGNMENTS',
                    value: globalAssignments,
                });
            }

            try {
                await this.fetchRefreshData(refreshPayload);

                if (settingsId) {
                    const safeJson = this.escapeHtmlValue(rawLogicJson);
                    this.updateSettingsPropertyInInitDataWithRaw(settingsId, 'LOGIC_JSON', safeJson, rawLogicJson);
                    this.updateSettingsPropertyInInitDataWithDescriptions(
                        settingsId,
                        'PARAMS',
                        params.map((item) => ({ value: item?.name ?? '', description: item?.type ?? '' }))
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
                    this.updateStagePropertyInInitDataWithRaw(
                        stageId,
                        'GLOBAL_ASSIGNMENTS',
                        this.escapeHtmlValue(globalAssignments),
                        globalAssignments
                    );
                }

                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                console.error('[BitrixBridge] SAVE_CALC_LOGIC_REQUEST error:', error);
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
                return;
            }
            
            try {
                // 1. Очищаем на сервере
                await this.fetchRefreshData([{
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'OPTIONS_OPERATION',
                    value: ''
                }]);
                
                // 2. Лёгкое обогащение
                this.updateStagePropertyInInitData(stageId, 'OPTIONS_OPERATION', '');
                
                // 3. Отправляем модифицированный INIT
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                
            } catch (error) {
                console.error('[BitrixBridge] CLEAR_OPTIONS_OPERATION error:', error);
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
                return;
            }
            
            try {
                // 1. Очищаем на сервере
                await this.fetchRefreshData([{
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'OPTIONS_MATERIAL',
                    value: ''
                }]);
                
                // 2. Лёгкое обогащение
                this.updateStagePropertyInInitData(stageId, 'OPTIONS_MATERIAL', '');
                
                // 3. Отправляем модифицированный INIT
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
                
            } catch (error) {
                console.error('[BitrixBridge] CLEAR_OPTIONS_MATERIAL error:', error);
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
                return;
            }
            try {
                await this.fetchRefreshData([{
                    action: 'updateStageProperty',
                    stageId: stageId,
                    propertyCode: 'OPTIONS_EQUIPMENT',
                    value: ''
                }]);
                this.updateStagePropertyInInitData(stageId, 'OPTIONS_EQUIPMENT', '');
                this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);
            } catch (error) {
                console.error('[BitrixBridge] CLEAR_OPTIONS_EQUIPMENT error:', error);
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

        /**
         * Обработка запроса активации панели цен (pricepanel)
         * Вызывается при выборе калькулятора и заполнении значений по умолчанию
         */
        async handleActivatePricePanelRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleActivatePricePanelRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};

            try {
                const result = await this.fetchRefreshData([
                    {
                        action: 'activatePricePanel',
                        calculatorSettingsId: payload.calculatorSettingsId || 0,
                        detailId: payload.detailId || 0,
                        defaultOperationVariantId: payload.defaultOperationVariantId || null,
                        defaultMaterialVariantId: payload.defaultMaterialVariantId || null,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                console.log('[BitrixBridge][DEBUG] Sending ACTIVATE_PRICE_PANEL_RESPONSE', {
                    requestId: message.requestId,
                    status: responsePayload.status,
                });

                this.sendPwrtMessage('ACTIVATE_PRICE_PANEL_RESPONSE', responsePayload, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleActivatePricePanelRequest END - success');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleActivatePricePanelRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ACTIVATE_PRICE_PANEL_RESPONSE',
                    {
                        status: 'error',
                        message: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
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

        async handleSaveCalculationRequest(message, origin) {
            const payload = message.payload || {};
            const offers = this.normalizeSaveCalculationOffers(payload);
            const total = offers.length;

            this.sendProcessMessage(
                'info',
                'Сохранение расчётов запущено',
                { step: 'start', total: total },
                message.requestId,
                origin
            );

            this.sendPwrtMessage('SAVE_CALCULATION_PROGRESS', {
                step: 'start',
                total: total,
                processed: 0,
                success: 0,
                failed: 0,
                percent: 0,
            }, message.requestId, origin);

            if (total === 0) {
                this.sendProcessMessage(
                    'warning',
                    'Нет данных для сохранения расчётов',
                    { step: 'skipped', total: 0 },
                    message.requestId,
                    origin
                );

                this.sendPwrtMessage('SAVE_CALCULATION_RESPONSE', {
                    status: 'error',
                    message: 'Некорректный payload: offers должен быть непустым массивом',
                    results: [],
                    total: 0,
                    saved: 0,
                }, message.requestId, origin);
                return;
            }

            let aggregateResults = [];
            let savedCount = 0;

            try {
                // SaveAllService already accepts an array. Sending one request for the whole
                // calculation avoids a full Bitrix/PHP bootstrap for every offer and mirrors
                // the fast batch recalculation path.
                const response = await this.sendPwrtRequest('SAVE_CALCULATION_REQUEST', {
                    offers: offers,
                }, message.requestId);
                const responsePayload = response && response.payload ? response.payload : {};
                aggregateResults = this.normalizeBatchSaveResults(offers, responsePayload);
            } catch (error) {
                aggregateResults = offers.map((offer) => ({
                    offerId: Number(offer.offerId || 0),
                    historyId: null,
                    status: 'error',
                    message: error && error.message ? error.message : 'Unknown error',
                }));
            }

            aggregateResults.forEach((itemResult, index) => {
                if (itemResult.status === 'ok') {
                    savedCount++;
                }

                const processed = index + 1;
                const failed = processed - savedCount;
                const percent = total > 0 ? Math.round((processed / total) * 100) : 0;

                this.sendPwrtMessage('SAVE_CALCULATION_PROGRESS', {
                    step: 'item',
                    total: total,
                    processed: processed,
                    success: savedCount,
                    failed: failed,
                    percent: percent,
                    item: itemResult,
                }, message.requestId, origin);
            });

            const failedCount = total - savedCount;
            const finalStatus = failedCount === 0 ? 'ok' : (savedCount > 0 ? 'partial' : 'error');
            const finalPayload = {
                status: finalStatus,
                results: aggregateResults,
                total: total,
                saved: savedCount,
                failed: failedCount,
            };

            this.sendProcessMessage(
                finalStatus === 'error' ? 'error' : 'success',
                'Сохранение расчётов завершено',
                {
                    step: 'complete',
                    total: total,
                    saved: savedCount,
                    failed: failedCount,
                },
                message.requestId,
                origin
            );

            this.sendPwrtMessage('SAVE_CALCULATION_PROGRESS', {
                step: 'complete',
                total: total,
                processed: total,
                success: savedCount,
                failed: failedCount,
                percent: 100,
            }, message.requestId, origin);

            this.sendPwrtMessage('SAVE_CALCULATION_RESPONSE', finalPayload, message.requestId, origin);
        }

        normalizeSaveCalculationOffers(payload) {
            if (Array.isArray(payload.offers)) {
                return payload.offers;
            }

            if (!Array.isArray(payload.results)) {
                return [];
            }

            return payload.results
                .map((item) => {
                    if (!item || typeof item !== 'object') {
                        return null;
                    }

                    const offerId = Number(item.offerId || item.offerID || item.id || 0);
                    const json = Object.prototype.hasOwnProperty.call(item, 'json') ? item.json : item;

                    return {
                        offerId: offerId,
                        json: json,
                    };
                })
                .filter((item) => item && item.offerId > 0);
        }

        normalizeBatchSaveResults(offers, responsePayload) {
            const historyByOffer = new Map(
                (Array.isArray(responsePayload.results) ? responsePayload.results : [])
                    .map((item) => [Number(item && item.offerId || 0), item])
            );
            const updateItems = responsePayload.offersUpdate && Array.isArray(responsePayload.offersUpdate.offers)
                ? responsePayload.offersUpdate.offers
                : [];
            const updateByOffer = new Map(
                updateItems.map((item) => [Number(item && item.offerId || 0), item])
            );
            const historyStatus = responsePayload.history && responsePayload.history.status
                ? responsePayload.history.status
                : 'skipped';

            return offers.map((offer) => {
                const offerId = Number(offer.offerId || 0);
                const history = historyByOffer.get(offerId);
                const update = updateByOffer.get(offerId);
                const historyFailed = historyStatus !== 'skipped' && (!history || history.status !== 'ok');
                const updateFailed = !update || update.status !== 'ok';

                if (historyFailed || updateFailed) {
                    return {
                        offerId: offerId,
                        historyId: history && history.historyId ? history.historyId : null,
                        status: 'error',
                        message: (history && history.message) || (update && update.message) || 'Не удалось сохранить расчёт',
                    };
                }

                return {
                    offerId: offerId,
                    historyId: history && history.historyId ? history.historyId : null,
                    historyXmlId: history && history.historyXmlId ? history.historyXmlId : null,
                    status: 'ok',
                };
            });
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
            const debugItems = Array.isArray(items) ? items.map(item => {
                if (!item || typeof item !== 'object' || !Object.prototype.hasOwnProperty.call(item, 'apiKey')) return item;
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

                return data.data || [];
            } catch (error) {
                console.error('[BitrixBridge][DEBUG] fetchRefreshData ERROR', {
                    error: error,
                    message: error.message,
                });
                throw error;
            }
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

            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }

            const data = await response.json();

            if (data && data.type === 'ERROR') {
                const errorPayload = data.payload || {};
                throw new Error(errorPayload.message || errorPayload.error || 'Ошибка pwrt-запроса');
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

            if (!message.source) {
                return { valid: false, reason: 'Missing source' };
            }

            if (!message.target) {
                return { valid: false, reason: 'Missing target' };
            }

            if (!message.type) {
                return { valid: false, reason: 'Missing type' };
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

            const targetOrigin = this.targetOrigin || '*';

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
        async handleReady(message, event) {
            this.logDebug('[CalcIntegration] Iframe is ready, loading init data...');

            if (event && event.origin) {
                this.readyOrigin = event.origin;
                this.targetOrigin = event.origin;
                this.logBridge('[BitrixBridge] targetOrigin set from READY origin: ' + event.origin);
            }

            try {
                // Всегда запрашиваем init payload после отображения диалога,
                // чтобы избежать зависимости от предварительных проверок пресетов
                this.logDebug('[CalcIntegration] Fetching init data via AJAX');
                const initData = await this.fetchInitData();

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

            if (this.hasUnsavedChanges) {
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
            const url = this.config.ajaxEndpoint +
                '?action=getInitData' +
                '&offerIds=' + encodeURIComponent(this.config.offerIds.join(',')) +
                '&siteId=' + encodeURIComponent(this.config.siteId) +
                '&sessid=' + encodeURIComponent(this.config.sessid);

            const startedAt = (window.performance && window.performance.now) ? window.performance.now() : Date.now();
            this.logBridge('[BitrixBridge] AJAX getInitData start', {
                url: url,
                offerIdsCount: this.config.offerIds.length,
                siteId: this.config.siteId,
            });

            try {
                const response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                const duration = ((window.performance && window.performance.now) ? window.performance.now() : Date.now()) - startedAt;

                if (!response.ok) {
                    this.logBridge('[BitrixBridge] AJAX getInitData error response', {
                        status: response.status,
                        durationMs: Math.round(duration),
                    });
                    throw new Error('HTTP error ' + response.status);
                }

                const data = await response.json();

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
            const url = this.config.ajaxEndpoint +
                '?action=enrichPreset' +
                '&presetId=' + encodeURIComponent(params.presetId) +
                '&detailIds=' + encodeURIComponent(params.detailIds.join(',')) +
                '&binding=' + encodeURIComponent(params.binding ? 'true' : 'false') +
                '&existingDetailId=' + encodeURIComponent(params.existingDetailId || 0) +
                '&offerIds=' + encodeURIComponent(params.offerIds.join(',')) +
                '&siteId=' + encodeURIComponent(params.siteId) +
                '&sessid=' + encodeURIComponent(this.config.sessid);

            console.log('[BitrixBridge] AJAX enrichPreset start', {
                presetId: params.presetId,
                detailIds: params.detailIds,
                binding: params.binding,
                existingDetailId: params.existingDetailId,
            });

            try {
                const response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    console.error('[BitrixBridge] AJAX enrichPreset error response', {
                        status: response.status,
                    });
                    throw new Error('HTTP error ' + response.status);
                }

                const data = await response.json();

                if (!data.success) {
                    console.error('[BitrixBridge] AJAX enrichPreset business error', {
                        message: data.message || data.error,
                    });
                    throw new Error(data.message || data.error || 'Ошибка обогащения пресета');
                }

                console.log('[BitrixBridge] AJAX enrichPreset success');

                return data;
            } catch (error) {
                console.error('[BitrixBridge] AJAX enrichPreset failed', {
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
