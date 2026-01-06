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
                case 'REMOVE_OFFER_REQUEST':
                    this.handleRemoveOfferRequest(message, origin);
                    break;
                case 'CALC_SETTINGS_REQUEST':
                case 'CALC_EQUIPMENT_REQUEST':
                case 'CALC_MATERIAL_VARIANT_REQUEST':
                case 'CALC_OPERATION_VARIANT_REQUEST':
                    console.log('[BitrixBridge][DEBUG] Matched CALC_*_REQUEST, calling handleCalcItemRequest', {
                        type: message.type,
                        payload: message.payload,
                    });
                    await this.handleCalcItemRequest(message, origin);
                    break;
                case 'SYNC_VARIANTS_REQUEST':
                    await this.handleSyncVariantsRequest(message, origin);
                    break;
                case 'GET_DETAIL_REQUEST':
                    await this.handleGetDetailRequest(message, origin);
                    break;
                case 'ADD_DETAIL_REQUEST':
                    await this.handleAddNewDetailRequest(message, origin);
                    break;
                case 'ADD_NEW_GROUP_REQUEST':
                    await this.handleAddNewGroupRequest(message, origin);
                    break;
                case 'ADD_NEW_STAGE_REQUEST':
                    await this.handleAddNewStageRequest(message, origin);
                    break;
                case 'DELETE_STAGE_REQUEST':
                    await this.handleDeleteStageRequest(message, origin);
                    break;
                case 'DELETE_DETAIL_REQUEST':
                    await this.handleDeleteDetailRequest(message, origin);
                    break;
                case 'CHANGE_NAME_DETAIL_REQUEST':
                    await this.handleChangeNameDetailRequest(message, origin);
                    break;
                case 'CLOSE_REQUEST':
                    this.handleCloseRequest(message);
                    break;
                default:
                    console.warn('[BitrixBridge][DEBUG] Unknown pwrt message type:', message.type);
                    console.warn('[BitrixBridge][DEBUG] Known types:', [
                        'SELECT_REQUEST', 'SELECT_DETAILS_REQUEST', 'CALC_SETTINGS_REQUEST', 'CALC_EQUIPMENT_REQUEST',
                        'SYNC_VARIANTS_REQUEST', 'GET_DETAIL_REQUEST', 'ADD_DETAIL_REQUEST', 
                        'ADD_NEW_GROUP_REQUEST', 'ADD_NEW_STAGE_REQUEST', 
                        'DELETE_STAGE_REQUEST', 'DELETE_DETAIL_REQUEST', 'CHANGE_NAME_DETAIL_REQUEST', 'CLOSE_REQUEST'
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

        async handleSelectDetailsRequest(message, origin) {
            const requestPayload = message.payload || {};
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

            await this.sendSelectDetailsResponse({
                ids: selectedIds,
                iblockId: iblockId,
                iblockType: iblockType,
                lang: lang,
                requestId: message.requestId,
                origin: origin,
            });
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

        async handleCalcItemRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleCalcItemRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });
        
            const responseType = message.type. replace('_REQUEST', '_RESPONSE');
            console.log('[BitrixBridge][DEBUG] Response type will be:', responseType);
        
            const requestPayload = message.payload || {};
            const iblockId = requestPayload.iblockId ?  parseInt(requestPayload. iblockId, 10) : null;
            const iblockType = requestPayload.iblockType || null;
            const lang = requestPayload. lang || null;
            const id = requestPayload. id ?  parseInt(requestPayload.id, 10) : null;
            
            const isVariantRequest = message. type === 'CALC_OPERATION_VARIANT_REQUEST' 
                || message. type === 'CALC_MATERIAL_VARIANT_REQUEST';
        
            console.log('[BitrixBridge][DEBUG] Parsed request params', {
                id:  id,
                iblockId: iblockId,
                iblockType: iblockType,
                lang:  lang,
                isVariantRequest: isVariantRequest,
            });
        
            const basePayload = {
                id: id,
                iblockId:  iblockId,
                iblockType: iblockType,
                lang: lang,
            };
        
            if (! id || !iblockId) {
                console. error('[BitrixBridge][DEBUG] Invalid id or iblockId', { id, iblockId });
                this.sendPwrtMessage(
                    responseType,
                    { ... basePayload, status: 'error', message: 'Invalid id or iblockId' },
                    message.requestId,
                    origin
                );
                return;
            }
        
            try {
                console.log('[BitrixBridge][DEBUG] Calling fetchRefreshData with:', {
                    iblockId: iblockId,
                    iblockType: iblockType,
                    ids: [id],
                    includeParent: isVariantRequest,
                });
        
                const refreshResult = await this.fetchRefreshData([
                    {
                        iblockId: iblockId,
                        iblockType: iblockType,
                        ids:  [id],
                        includeParent:  isVariantRequest,  // <-- НОВОЕ
                    },
                ]);
        
                console. log('[BitrixBridge][DEBUG] fetchRefreshData result:', {
                    isArray: Array.isArray(refreshResult),
                    length: Array.isArray(refreshResult) ? refreshResult.length : 0,
                    firstItem: Array.isArray(refreshResult) && refreshResult[0] ? refreshResult[0] : null,
                    rawResult: refreshResult,
                });
        
                const element = Array.isArray(refreshResult)
                    && refreshResult[0]
                    && Array.isArray(refreshResult[0].data)
                    ? refreshResult[0]. data[0] || null
                    : null;
        
                console. log('[BitrixBridge][DEBUG] Extracted element:', {
                    hasElement: !!element,
                    elementId: element ?  element.id : null,
                    elementName: element ? element. name : null,
                    elementProperties: element ? Object.keys(element. properties || {}) : [],
                    hasItemParent: element ?  !!element.itemParent : false,  // <-- НОВОЕ:  логируем наличие родителя
                    itemParentId: element && element.itemParent ? element.itemParent.id : null,
                });
        
                const responsePayload = {
                    ...basePayload,
                    status: element ? 'ok' : 'not_found',
                    item: element,
                };
        
                console. log('[BitrixBridge][DEBUG] Sending response', {
                    type: responseType,
                    requestId: message.requestId,
                    status: responsePayload. status,
                    hasItem: !!responsePayload.item,
                    hasItemParent: responsePayload.item ?  !!responsePayload.item.itemParent : false,
                });
        
                this.sendPwrtMessage(
                    responseType,
                    responsePayload,
                    message.requestId,
                    origin
                );
        
                console.log('[BitrixBridge][DEBUG] handleCalcItemRequest END - success');
        
            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleCalcItemRequest ERROR', {
                    error: error,
                    message: error.message,
                    stack: error.stack,
                });
                this.sendPwrtMessage(
                    responseType,
                    {
                        ...basePayload,
                        status: 'error',
                        message: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса синхронизации вариантов
         * Использует тот же транспорт fetchRefreshData
         */
        async handleSyncVariantsRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleSyncVariantsRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};

            try {
                // Используем тот же fetchRefreshData, но с флагом action
                const result = await this.fetchRefreshData([
                    {
                        action: 'syncVariants',
                        items: payload.items || [],
                        offerIds: payload.offerIds || [],
                        deletedConfigIds: payload.deletedConfigIds || [],
                        context: payload.context || {},
                    }
                ]);

                console.log('[BitrixBridge][DEBUG] handleSyncVariantsRequest result:', {
                    isArray: Array.isArray(result),
                    length: Array.isArray(result) ? result.length : 0,
                    firstItem: Array.isArray(result) && result[0] ? result[0] : null,
                });

                // Ответ будет в result[0]
                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response', items: [], canCalculate: false, stats: {} };

                console.log('[BitrixBridge][DEBUG] Sending SYNC_VARIANTS_RESPONSE', {
                    requestId: message.requestId,
                    status: responsePayload.status,
                    itemsCount: responsePayload.items ? responsePayload.items.length : 0,
                    canCalculate: responsePayload.canCalculate,
                });

                this.sendPwrtMessage('SYNC_VARIANTS_RESPONSE', responsePayload, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleSyncVariantsRequest END - success');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleSyncVariantsRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'SYNC_VARIANTS_RESPONSE',
                    {
                        status: 'error',
                        message: error && error.message ? error.message : 'Unknown error',
                        items: [],
                        canCalculate: false,
                        stats: {},
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса получения детали с вложенными элементами
         */
        async handleGetDetailRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleGetDetailRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};

            try {
                const result = await this.fetchRefreshData([
                    {
                        action: 'getDetailWithChildren',
                        detailId: payload.detailId || 0,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                console.log('[BitrixBridge][DEBUG] Sending GET_DETAIL_RESPONSE', {
                    requestId: message.requestId,
                    status: responsePayload.status,
                });

                this.sendPwrtMessage('GET_DETAIL_RESPONSE', responsePayload, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleGetDetailRequest END - success');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleGetDetailRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'GET_DETAIL_RESPONSE',
                    {
                        status: 'error',
                        message: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса создания новой детали
         */
        async handleAddNewDetailRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleAddNewDetailRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};

            try {
                // Используем fetchRefreshData с action
                const result = await this.fetchRefreshData([
                    {
                        action: 'addNewDetail',
                        offerIds: payload.offerIds || [],
                        name: payload.name || '',
                    }
                ]);

                console.log('[BitrixBridge][DEBUG] handleAddNewDetailRequest result:', {
                    isArray: Array.isArray(result),
                    length: Array.isArray(result) ? result.length : 0,
                    firstItem: Array.isArray(result) && result[0] ? result[0] : null,
                });

                // Ответ будет в result[0]
                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                console.log('[BitrixBridge][DEBUG] Sending ADD_NEW_DETAIL_RESPONSE', {
                    requestId: message.requestId,
                    status: responsePayload.status,
                });

                this.sendPwrtMessage('ADD_NEW_DETAIL_RESPONSE', responsePayload, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleAddNewDetailRequest END - success');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleAddNewDetailRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ADD_NEW_DETAIL_RESPONSE',
                    {
                        status: 'error',
                        message: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса копирования детали
         */
        async handleCopyDetailRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleCopyDetailRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};

            try {
                const result = await this.fetchRefreshData([
                    {
                        action: 'copyDetail',
                        detailId: payload.detailId || 0,
                        offerIds: payload.offerIds || [],
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                console.log('[BitrixBridge][DEBUG] Sending COPY_DETAIL_RESPONSE', {
                    requestId: message.requestId,
                    status: responsePayload.status,
                });

                this.sendPwrtMessage('COPY_DETAIL_RESPONSE', responsePayload, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleCopyDetailRequest END - success');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleCopyDetailRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'COPY_DETAIL_RESPONSE',
                    {
                        status: 'error',
                        message: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса создания группы скрепления
         */
        async handleAddNewGroupRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleAddNewGroupRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};

            try {
                const result = await this.fetchRefreshData([
                    {
                        action: 'addNewGroup',
                        offerIds: payload.offerIds || [],
                        detailIds: payload.detailIds || [],
                        name: payload.name || 'Группа скрепления',
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                console.log('[BitrixBridge][DEBUG] Sending ADD_NEW_GROUP_RESPONSE', {
                    requestId: message.requestId,
                    status: responsePayload.status,
                });

                this.sendPwrtMessage('ADD_NEW_GROUP_RESPONSE', responsePayload, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleAddNewGroupRequest END - success');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleAddNewGroupRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ADD_NEW_GROUP_RESPONSE',
                    {
                        status: 'error',
                        message: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса добавления нового этапа
         */
        async handleAddNewStageRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleAddNewStageRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};

            try {
                const result = await this.fetchRefreshData([
                    {
                        action: 'addNewStage',
                        detailId: payload.detailId || 0,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                console.log('[BitrixBridge][DEBUG] Sending ADD_NEW_STAGE_RESPONSE', {
                    requestId: message.requestId,
                    status: responsePayload.status,
                });

                this.sendPwrtMessage('ADD_NEW_STAGE_RESPONSE', responsePayload, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleAddNewStageRequest END - success');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleAddNewStageRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'ADD_NEW_STAGE_RESPONSE',
                    {
                        status: 'error',
                        message: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса удаления этапа
         */
        async handleDeleteStageRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleDeleteStageRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};

            try {
                const result = await this.fetchRefreshData([
                    {
                        action: 'deleteStage',
                        configId: payload.configId || 0,
                        detailId: payload.detailId || 0,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                console.log('[BitrixBridge][DEBUG] Sending DELETE_STAGE_RESPONSE', {
                    requestId: message.requestId,
                    status: responsePayload.status,
                });

                this.sendPwrtMessage('DELETE_STAGE_RESPONSE', responsePayload, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleDeleteStageRequest END - success');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleDeleteStageRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'DELETE_STAGE_RESPONSE',
                    {
                        status: 'error',
                        message: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса удаления детали
         */
        async handleDeleteDetailRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleDeleteDetailRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};

            try {
                const result = await this.fetchRefreshData([
                    {
                        action: 'deleteDetail',
                        detailId: payload.detailId || 0,
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                console.log('[BitrixBridge][DEBUG] Sending DELETE_DETAIL_RESPONSE', {
                    requestId: message.requestId,
                    status: responsePayload.status,
                });

                this.sendPwrtMessage('DELETE_DETAIL_RESPONSE', responsePayload, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleDeleteDetailRequest END - success');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleDeleteDetailRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'DELETE_DETAIL_RESPONSE',
                    {
                        status: 'error',
                        message: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
        }

        /**
         * Обработка запроса изменения имени детали
         */
        async handleChangeNameDetailRequest(message, origin) {
            console.log('[BitrixBridge][DEBUG] handleChangeNameDetailRequest START', {
                messageType: message.type,
                payload: message.payload,
                origin: origin,
            });

            const payload = message.payload || {};

            try {
                const result = await this.fetchRefreshData([
                    {
                        action: 'changeNameDetail',
                        detailId: payload.detailId || 0,
                        newName: payload.newName || '',
                    }
                ]);

                const responsePayload = (Array.isArray(result) && result[0])
                    ? result[0]
                    : { status: 'error', message: 'Empty response' };

                console.log('[BitrixBridge][DEBUG] Sending CHANGE_NAME_DETAIL_RESPONSE', {
                    requestId: message.requestId,
                    status: responsePayload.status,
                });

                this.sendPwrtMessage('CHANGE_NAME_DETAIL_RESPONSE', responsePayload, message.requestId, origin);

                console.log('[BitrixBridge][DEBUG] handleChangeNameDetailRequest END - success');

            } catch (error) {
                console.error('[BitrixBridge][DEBUG] handleChangeNameDetailRequest ERROR', {
                    error: error,
                    message: error.message,
                });

                this.sendPwrtMessage(
                    'CHANGE_NAME_DETAIL_RESPONSE',
                    {
                        status: 'error',
                        message: error && error.message ? error.message : 'Unknown error',
                    },
                    message.requestId,
                    origin
                );
            }
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
            const callbackName = '__pwrtElementSelect_' + Math.random().toString(36).slice(2);
            const selectedIds = [];
            this.currentSelectionItems = selectedIds;

            const params = new URLSearchParams({
                lang: dialogLang,
                n: callbackName,
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

                const cleanup = () => {
                    delete window[callbackName];
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

                const overrideFunctions = () => {
                    if (functionsOverridden) return;

                    try {
                        if (!popupWindow || !popupWindow.document || !popupWindow.document.body) return;

                        // Переопределяем SelEl - вызывается при двойном клике на элемент
                        popupWindow.SelEl = function(id, name) {
                            const parsedId = parseInt(id, 10);
                            if (parsedId && !isNaN(parsedId) && parsedId > 0) {
                                if (selectedIds.indexOf(parsedId) === -1) {
                                    selectedIds.push(parsedId);
                                }
                            }
                            updateCounter();
                            console.log('[PWRT] SelEl called:', id, name, 'selectedIds:', selectedIds);
                        };

                        // Переопределяем SelAll - вызывается при клике на кнопку "Выбрать"
                        popupWindow.SelAll = function() {
                            // Собираем все отмеченные чекбоксы
                            const checkboxes = popupWindow.document.querySelectorAll('input[type="checkbox"][name="ID[]"]:checked');
                            checkboxes.forEach(function(checkbox) {
                                const parsedId = parseInt(checkbox.value, 10);
                                if (parsedId && !isNaN(parsedId) && parsedId > 0) {
                                    if (selectedIds.indexOf(parsedId) === -1) {
                                        selectedIds.push(parsedId);
                                    }
                                }
                            });

                            console.log('[PWRT] SelAll called, collected IDs:', selectedIds);

                            // Закрываем окно
                            popupWindow.close();
                        };

                        functionsOverridden = true;
                        console.log('[PWRT] SelEl and SelAll overridden successfully');
                    } catch (e) {
                        console.warn('[PWRT] Failed to override functions:', e);
                    }
                };

                window[callbackName] = function (elementId) {
                    const parsedId = parseInt(elementId, 10);

                    if (!parsedId || isNaN(parsedId) || parsedId <= 0) {
                        return;
                    }

                    if (selectedIds.indexOf(parsedId) === -1) {
                        selectedIds.push(parsedId);
                    }

                    updateCounter();
                };

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
            console.log('[BitrixBridge][DEBUG] fetchRefreshData START', {
                items: items,
                ajaxEndpoint: this.config.ajaxEndpoint,
            });

            const payloadJson = JSON.stringify(items);
            const formData = new FormData();
            formData.append('action', 'refreshData');
            formData.append('payload', payloadJson);
            formData.append('sessid', this.config.sessid);

            console.log('[BitrixBridge][DEBUG] fetchRefreshData request', {
                action: 'refreshData',
                payload: payloadJson,
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

                if (!response.ok) {
                    throw new Error('HTTP error ' + response.status);
                }

                const data = await response.json();

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
        handleCloseRequest(message) {
            this.logDebug('[CalcIntegration] Close request received');

            if (this.hasUnsavedChanges) {
                const confirmed = confirm('Есть несохранённые изменения. Вы уверены, что хотите закрыть окно?');
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
                alert('Ошибка: ' + errorMessage);
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
