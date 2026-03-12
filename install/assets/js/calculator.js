/**
 * ProspekwebCalc - Калькулятор себестоимости
 * Интеграция React-приложения через iframe + postMessage
 * @version 2.0.0
 */

console.log('[BitrixBridge] calculator.js loaded, init integration...');

var ProspekwebCalc = {
    // Пути
    appUrl: '/local/apps/prospektweb.calc/index.html',
    apiBase: '/bitrix/tools/prospektweb.calc/',
    cssPath: '/local/css/prospektweb.calc/calculator.css',

    loadCss: function(href) {
        if (document.querySelector('link[href="' + href + '"]')) {
            return;
        }
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.type = 'text/css';
        link.href = href;
        document.head.appendChild(link);
    },
    
    // Белый список разрешённых endpoints для безопасности
    allowedEndpoints: [
        'calculators.php',
        'config.php',
        'equipment.php',
        'elements.php',
        'calculator_config.php',
        'calculate.php',
        'save_result.php'
    ],
    
    // Константы
    DOM_STABILIZATION_DELAY: 150, // Задержка в мс для стабилизации DOM после AJAX-обновлений
    INIT_RETRY_DELAY: 200,        // Задержка в мс между повторными попытками initAdminButton
    MAX_INIT_RETRIES: 10,         // Максимальное количество повторных попыток initAdminButton
    PRESET_CONFIRM_MESSAGE: 'Необходимо создать новый пресет калькуляции',
    
    // Состояние
    dialog: null,
    iframe: null,
    messageHandler: null,
    observer: null,
    windowCloseHandler: null,
    isClosing: false,
    _isInserting: false,

    /**
     * Инициализация кнопки в админке
     */
    init: function(containerId, props) {
        this.loadCss(this.cssPath);
        if (!containerId) {
            this.initAdminButton();
            this.initMarkupAction();
            this.startObserver();
        }
    },

    /**
     * Инициализация кнопки в админке
     */
    initAdminButton: function(retryCount) {
        var self = this;
        retryCount = retryCount || 0;

        var context = this.findOffersToolbarContext();

        if (!context || !context.toolbar) {
            if (retryCount < self.MAX_INIT_RETRIES) {
                setTimeout(function() {
                    self.initAdminButton(retryCount + 1);
                }, self.INIT_RETRY_DELAY);
            }
            return;
        }

        var toolbar = context.toolbar;
        var anchorNode = context.anchor;

        // Если обе кнопки уже есть — ничего не делаем
        var existingCalc = document.getElementById('btn_prospektweb_calc');
        var existingMarkup = document.getElementById('btn_prospektweb_markup');

        if (existingCalc && existingMarkup) {
            return;
        }

        // Блокируем Observer на время вставки
        self._isInserting = true;

        try {
            // Создаём кнопку "Калькуляция" если её нет
            var calcBtn = existingCalc;
            if (!calcBtn) {
                calcBtn = document.createElement('a');
                calcBtn.id = 'btn_prospektweb_calc';
                calcBtn.className = 'adm-btn';
                calcBtn.href = 'javascript:void(0)';
                calcBtn.title = 'Калькуляция себестоимости';
                calcBtn.textContent = 'Калькуляция';

                calcBtn.addEventListener('click', function() {
                    self.openCalculatorDialog();
                });

                if (anchorNode && anchorNode.nextSibling) {
                    toolbar.insertBefore(calcBtn, anchorNode.nextSibling);
                } else {
                    toolbar.appendChild(calcBtn);
                }
            }

            // Создаём кнопку "Добавить наценку" если её нет — СРАЗУ после калькуляции
            if (!existingMarkup) {
                var markupBtn = document.createElement('a');
                markupBtn.id = 'btn_prospektweb_markup';
                markupBtn.className = 'adm-btn';
                markupBtn.href = 'javascript:void(0)';
                markupBtn.title = 'Добавить наценку';
                markupBtn.textContent = 'Добавить наценку';

                markupBtn.addEventListener('click', function() {
                    self.openMarkupDialog();
                });

                // Вставляем сразу после кнопки калькуляции
                if (calcBtn.nextSibling) {
                    toolbar.insertBefore(markupBtn, calcBtn.nextSibling);
                } else {
                    toolbar.appendChild(markupBtn);
                }
            }
        } finally {
            // Снимаем блокировку через микрозадержку, чтобы Observer успел пропустить наши изменения
            setTimeout(function() {
                self._isInserting = false;
            }, 0);
        }
    },

    /**
     * Найти тулбар ТП и опорную кнопку, рядом с которой вставлять наши кнопки.
     */
    findOffersToolbarContext: function() {
        var genBtn = document.getElementById('btn_sub_gen');
        if (genBtn && genBtn.parentNode) {
            return { toolbar: genBtn.parentNode, anchor: genBtn };
        }

        var selectors = [
            '#tab_sub_list .adm-detail-toolbar',
            '#tab_sub_list .adm-list-table-top',
            '#tab_sub_list .adm-list-table-layout',
            '.adm-detail-content-wrap .adm-detail-toolbar',
            '.adm-detail-toolbar',
            '#bx-admin-prefix .adm-detail-toolbar',
            '.adm-workarea .adm-detail-toolbar',
            '#tab_sub_list .adm-list-table-footer'
        ];

        for (var i = 0; i < selectors.length; i++) {
            var toolbar = document.querySelector(selectors[i]);
            if (!toolbar) {
                continue;
            }

            var anchor = toolbar.querySelector('.adm-btn') || toolbar.querySelector('a,button,input[type="button"]');
            return { toolbar: toolbar, anchor: anchor };
        }

        return null;
    },

    /**
     * Инициализация кнопки массовой наценки рядом с Калькуляцией
     */
    initMarkupButton: function(toolbar, afterNode) {
        var self = this;

        if (!toolbar || document.getElementById('btn_prospektweb_markup')) {
            return;
        }

        var markupBtn = document.createElement('a');
        markupBtn.id = 'btn_prospektweb_markup';
        markupBtn.className = 'adm-btn';
        markupBtn.href = 'javascript:void(0)';
        markupBtn.title = 'Добавить наценку';
        markupBtn.textContent = 'Добавить наценку';

        markupBtn.addEventListener('click', function() {
            self.openMarkupDialog();
        });

        if (afterNode && afterNode.nextSibling) {
            toolbar.insertBefore(markupBtn, afterNode.nextSibling);
        } else {
            toolbar.appendChild(markupBtn);
        }
    },

    /**
     * Запуск наблюдателя за изменениями DOM
     */
    startObserver: function() {
        var self = this;
        
        // Если уже запущен - не запускаем повторно
        if (this.observer) {
            return;
        }
        
        // Ищем контейнер таблицы ТП (tab_sub_list или adm-detail-content-wrap)
        var targetNode = document.getElementById('tab_sub_list') || 
                         document.querySelector('.adm-detail-content-wrap');
        
        if (!targetNode) {
            // Fallback: наблюдаем за body
            targetNode = document.body;
        }
        
        this.observer = new MutationObserver(function(mutations) {
            // Пропускаем, если мы сами вставляем кнопки
            if (self._isInserting) {
                return;
            }

            // Оптимизация: проверяем, есть ли изменения в добавленных/удалённых узлах
            var hasRelevantChanges = false;
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes.length > 0 || mutations[i].removedNodes.length > 0) {
                    hasRelevantChanges = true;
                    break;
                }
            }
            
            if (!hasRelevantChanges) {
                return;
            }
            
            // Проверяем, что обе кнопки присутствуют после AJAX-перерисовки
            var calcBtn = document.getElementById('btn_prospektweb_calc');
            var markupBtn = document.getElementById('btn_prospektweb_markup');
            var markupExists = !!document.querySelector('select[name="action"] option[value="pw_add_markup"]');
            
            if (calcBtn && !markupBtn) {
                // Кнопка калькуляции есть, а наценки нет — добавляем наценку напрямую
                setTimeout(function() {
                    var toolbar = calcBtn.parentNode;
                    if (toolbar) {
                        self.initMarkupButton(toolbar, calcBtn);
                    }
                }, self.DOM_STABILIZATION_DELAY);
            } else if (!calcBtn) {
                // Обеих кнопок нет — пробуем добавить обе
                setTimeout(function() {
                    self.initAdminButton();
                }, self.DOM_STABILIZATION_DELAY);
            }

            if (!markupExists) {
                setTimeout(function() {
                    self.initMarkupAction();
                }, self.DOM_STABILIZATION_DELAY);
            }
        });
        
        this.observer.observe(targetNode, {
            childList: true,
            subtree: true
        });
    },

    /**
     * Остановка наблюдателя за изменениями DOM
     */
    stopObserver: function() {
        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }
    },

    /**
     * Получение полной информации о выбранных торговых предложениях
     */
    getSelectedOffers: function() {
        var checkboxes = document.querySelectorAll('input[name="SUB_ID[]"]:checked');
        var offers = [];
        var productId = this.getProductId();
        var iblockId = this.getIblockId();
        
        for (var i = 0; i < checkboxes.length; i++) {
            var checkbox = checkboxes[i];
            var id = parseInt(checkbox.value, 10);
            
            if (isNaN(id) || id <= 0) {
                continue;
            }
            
            // Находим строку таблицы для получения названия
            var row = checkbox.closest('tr');
            var name = 'ТП #' + id; // Значение по умолчанию
            
            if (row) {
                // Ищем ячейку с названием (обычно это вторая или третья колонка после чекбокса)
                var cells = row.querySelectorAll('td');
                for (var j = 0; j < cells.length; j++) {
                    var cell = cells[j];
                    // Пропускаем ячейку с чекбоксом и ячейки с кнопками/иконками
                    if (!cell.querySelector('input[type="checkbox"]') && 
                        !cell.querySelector('a.adm-btn-delete') &&
                        cell.textContent.trim().length > 0) {
                        name = cell.textContent.trim();
                        break;
                    }
                }
            }
            
            // Формируем URL для редактирования ТП
            var editUrl = '/bitrix/admin/cat_product_edit.php?IBLOCK_ID=' + iblockId + 
                         '&type=catalog&ID=' + productId + 
                         '&WF=Y&find_section_section=-1&SUB_ID=' + id;
            
            offers.push({
                id: id,
                name: name,
                editUrl: editUrl,
                productId: productId,
                iblockId: iblockId
            });
        }
        
        return offers;
    },

    /**
     * Открытие диалога с iframe
     */
    openCalculatorDialog: async function() {
        this.loadCss(this.cssPath);
        var self = this;

        // Получаем выбранные ТП с полной информацией
        var offers = this.getSelectedOffers();

        if (offers.length === 0) {
            alert('Не выбраны торговые предложения');
            return;
        }

        // Проверяем CALC_PRESET перед созданием диалога
        var presetCheck = await this.ensurePresetAvailability(offers);
        if (!presetCheck || presetCheck.cancelled || presetCheck.error) {
            return;
        }

        // Создаём контейнер для iframe
        var container = document.createElement('div');
        container.style.width = '100%';
        container.style.height = '100%';
        container.style.overflow = 'hidden';

        // Создаём iframe
        var iframe = document.createElement('iframe');
        iframe.src = this.appUrl;
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = 'none';
        iframe.style.display = 'block';
        
        container.appendChild(iframe);
        this.iframe = iframe;

        // Создаём диалог
        var dialog = new BX.CAdminDialog({
            title: 'Калькуляция себестоимости',
            content: container,
            width: 1400,
            height: 800,
            resizable: true,
            draggable: true
        });

        this.dialog = dialog;

        this.windowCloseHandler = this.handleWindowClose.bind(this);
        BX.addCustomEvent(dialog, 'onWindowClose', this.windowCloseHandler);

        // Используем ProspektwebCalcIntegration для обработки postMessage сразу,
        // чтобы не пропустить первое сообщение READY, которое iframe отправляет
        // сразу после загрузки приложения.
        // Проверяем доступность ProspektwebCalcIntegration
        if (typeof window.ProspektwebCalcIntegration === 'undefined') {
            console.error('[ProspekwebCalc] ProspektwebCalcIntegration not loaded');
            alert('Ошибка загрузки модуля интеграции');
            return;
        }

        // Создаём интеграцию с передачей iframe напрямую
        self.integration = new window.ProspektwebCalcIntegration({
            iframe: iframe,
            ajaxEndpoint: '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
            offerIds: offers.map(function(o) { return o.id; }),
            siteId: BX.message('PROSPEKTWEB_CALC_SITE_ID') || BX.message('SITE_ID') || (typeof SITE_ID !== 'undefined' ? SITE_ID : 's1'),
            sessid: BX.bitrix_sessid(),
            presetCheckResult: presetCheck,
            onClose: function() {
                self.closeDialog();
            },
            onError: function(error) {
                console.error('[ProspekwebCalc] Calc error:', error);
                alert('Ошибка калькулятора: ' + (error.message || 'Неизвестная ошибка'));
            }
        });

        console.log('[BitrixBridge] ProspektwebCalcIntegration created', {
            iframe: '#calc-iframe',
            ajaxUrl: '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
        });

        dialog.Show();
    },

    /**
     * Helper function to safely parse JSON response
     * @param {Response} response - Fetch API response object
     * @returns {Promise<Object>} Parsed JSON data
     * @throws {Error} If response is not JSON or parsing fails
     */
    parseJsonResponse: async function(response) {
        // Check Content-Type before parsing
        var contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            // Response is not JSON, likely an error page
            var textResponse = await response.text();
            // Log only first 200 characters to avoid exposing sensitive data
            console.error('[ProspektwebCalc] Non-JSON response received:', textResponse.substring(0, 200));
            throw new Error('Сервер вернул некорректный ответ (HTML вместо JSON). Статус: ' + response.status);
        }

        try {
            return await response.json();
        } catch (parseError) {
            console.error('[ProspektwebCalc] JSON parse error:', parseError);
            throw new Error('Ошибка парсинга ответа сервера. Возможно, сервер вернул HTML вместо JSON.');
        }
    },

    /**
     * Предварительная проверка/создание CALC_PRESET для выбранных ТП
     * Упрощенная логика: один пресет на товар, конфликтов больше нет
     * @param {Array} offers
     * @returns {Promise<{success: boolean, presetId?: number, skipPresetCheck: boolean, cancelled?: boolean, error?: boolean}>}
     * When preset exists: {success: true, presetId: number, skipPresetCheck: true}
     * When cancelled: {success: false, cancelled: true, skipPresetCheck: true}
     * When error: {success: false, error: true, skipPresetCheck: true}
     */
    ensurePresetAvailability: async function(offers) {
        var offerIds = offers.map(function(o) { return o.id; });
        var ajaxEndpoint = '/bitrix/tools/prospektweb.calc/calculator_ajax.php';
        var sessid = BX.bitrix_sessid();
        var siteId = BX.message('PROSPEKTWEB_CALC_SITE_ID') || BX.message('SITE_ID') || (typeof SITE_ID !== 'undefined' ? SITE_ID : 's1');

        try {
            // Проверяем наличие пресета у товара
            var checkUrl = ajaxEndpoint +
                '?action=checkPresets' +
                '&offerIds=' + encodeURIComponent(offerIds.join(',')) +
                '&siteId=' + encodeURIComponent(siteId) +
                '&sessid=' + encodeURIComponent(sessid);

            var checkResponse = await fetch(checkUrl, {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            var checkData = await this.parseJsonResponse(checkResponse);

            if (!checkResponse.ok || !checkData.success) {
                throw new Error((checkData && (checkData.message || checkData.error)) || 'Ошибка проверки пресетов');
            }

            if (!checkData.data) {
                throw new Error('Некорректный ответ проверки пресетов');
            }

            var hasPreset = Boolean(checkData.data.hasPreset);
            var presetId = checkData.data.presetId ? parseInt(checkData.data.presetId, 10) : null;

            if (hasPreset && presetId) {
                // Пресет уже есть у товара — используем его
                return { success: true, presetId: presetId, skipPresetCheck: true };
            }

            // Пресета нет — запрашиваем подтверждение на создание
            var confirmed = confirm(this.PRESET_CONFIRM_MESSAGE);
            if (!confirmed) {
                return { success: false, cancelled: true, skipPresetCheck: true };
            }

            // Создаём новый пресет (автоматически привяжется к товару)
            var createUrl = ajaxEndpoint +
                '?action=createAndAssignPreset' +
                '&offerIds=' + encodeURIComponent(offerIds.join(',')) +
                '&siteId=' + encodeURIComponent(siteId) +
                '&sessid=' + encodeURIComponent(sessid);

            var createResponse = await fetch(createUrl, {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            var createData = await this.parseJsonResponse(createResponse);

            if (!createResponse.ok || !createData.success) {
                throw new Error((createData && (createData.message || createData.error)) || 'Ошибка создания пресета');
            }

            return {
                success: true,
                presetId: createData.data ? createData.data.presetId : null,
                skipPresetCheck: true,
            };
        } catch (error) {
            console.error('[ProspektwebCalc] Preset check error:', error);
            alert('Ошибка проверки/создания пресета: ' + error.message);
            return { success: false, error: true, skipPresetCheck: true };
        }
    },

    /**
     * Отправка сообщения в iframe
     * @deprecated Используется ProspektwebCalcIntegration
     */
    sendToIframe: function(message) {
        if (this.iframe && this.iframe.contentWindow) {
            // Отправляем в том же домене - безопасно использовать window.location.origin
            var targetOrigin = window.location.origin;
            this.iframe.contentWindow.postMessage(message, targetOrigin);
        }
    },

    /**
     * Обработка сообщений от iframe
     * @deprecated Используется ProspektwebCalcIntegration
     */
    handleMessage: function(event) {
        // Проверяем origin - принимаем только сообщения с того же домена
        if (event.origin !== window.location.origin) {
            return;
        }
        
        var data = event.data;
        
        if (!data || !data.type) {
            return;
        }

        switch (data.type) {
            case 'CALC_READY':
                console.log('Calculator ready');
                break;
                
            case 'CALC_CLOSE':
                this.closeDialog();
                break;
                
            case 'CALC_OPEN_OFFER':
                // Открываем ТП в новой вкладке браузера
                if (data.payload && data.payload.editUrl) {
                    window.open(data.payload.editUrl, '_blank');
                    console.log('Opening offer in new tab:', data.payload.id);
                }
                break;
                
            case 'CALC_REMOVE_OFFER':
                // Логирование удаления ТП из списка
                if (data.payload && data.payload.id) {
                    console.log('Offer removed from list:', data.payload.id);
                }
                break;
                
            case 'CALC_RESULT':
                this.handleCalculationResult(data.payload);
                break;
                
            case 'CALC_SAVE_CONFIG':
                this.saveConfiguration(data.payload);
                break;
                
            case 'CALC_API_REQUEST':
                this.proxyApiRequest(data.payload);
                break;
                
            case 'CALC_ERROR':
                console.error('Calculator error:', data.payload);
                break;
        }
    },

    /**
     * Закрытие диалога
     */
    handleWindowClose: function() {
        this.closeDialog({ skipDialogClose: true });
    },

    closeDialog: function(options) {
        var opts = options || {};

        if (this.isClosing) {
            return;
        }

        this.isClosing = true;

        // Уничтожаем интеграцию если она существует
        if (this.integration && typeof this.integration.destroy === 'function') {
            this.integration.destroy();
            this.integration = null;
        }
        
        // Удаляем старый обработчик сообщений (для обратной совместимости)
        if (this.messageHandler) {
            window.removeEventListener('message', this.messageHandler);
            this.messageHandler = null;
        }

        if (this.dialog) {
            if (this.windowCloseHandler) {
                BX.removeCustomEvent(this.dialog, 'onWindowClose', this.windowCloseHandler);
                this.windowCloseHandler = null;
            }

            if (!opts.skipDialogClose) {
                this.dialog.Close();
            }
            this.dialog = null;
        }

        this.iframe = null;

        this.isClosing = false;
    },

    /**
     * Обработка результата калькуляции
     * @deprecated Используется ProspektwebCalcIntegration
     */
    handleCalculationResult: function(result) {
        var self = this;
        
        // Отправляем результат на сервер
        BX.ajax.post(
            this.apiBase + 'save_result.php',
            {
                sessid: BX.bitrix_sessid(),
                result: JSON.stringify(result)
            },
            function(response) {
                try {
                    var data = JSON.parse(response);
                    if (data.success) {
                        self.sendToIframe({
                            type: 'BITRIX_SAVE_SUCCESS',
                            payload: data
                        });
                    } else {
                        self.sendToIframe({
                            type: 'BITRIX_SAVE_ERROR',
                            payload: data.error || 'Unknown error'
                        });
                    }
                } catch (e) {
                    self.sendToIframe({
                        type: 'BITRIX_SAVE_ERROR',
                        payload: 'Parse error'
                    });
                }
            },
            function(error) {
                // Обработка сетевых ошибок
                self.sendToIframe({
                    type: 'BITRIX_SAVE_ERROR',
                    payload: 'Network error: ' + (error || 'Unknown error')
                });
            }
        );
    },

    /**
     * Сохранение конфигурации
     * @deprecated Используется ProspektwebCalcIntegration
     */
    saveConfiguration: function(config) {
        var self = this;
        
        BX.ajax.post(
            this.apiBase + 'config.php',
            {
                sessid: BX.bitrix_sessid(),
                action: 'save',
                config: JSON.stringify(config)
            },
            function(response) {
                try {
                    var data = JSON.parse(response);
                    self.sendToIframe({
                        type: 'BITRIX_CONFIG_SAVED',
                        payload: data
                    });
                } catch (e) {
                    self.sendToIframe({
                        type: 'BITRIX_CONFIG_ERROR',
                        payload: 'Parse error'
                    });
                }
            },
            function(error) {
                // Обработка сетевых ошибок
                self.sendToIframe({
                    type: 'BITRIX_CONFIG_ERROR',
                    payload: 'Network error: ' + (error || 'Unknown error')
                });
            }
        );
    },

    /**
     * Проксирование API запросов
     * @deprecated Используется ProspektwebCalcIntegration
     */
    proxyApiRequest: function(request) {
        var self = this;
        
        // Валидация входных данных
        if (!request || typeof request.endpoint !== 'string') {
            self.sendToIframe({
                type: 'BITRIX_API_RESPONSE',
                payload: {
                    requestId: request ? request.requestId : null,
                    success: false,
                    error: 'Invalid request'
                }
            });
            return;
        }
        
        // Валидация HTTP метода
        var allowedMethods = ['GET', 'POST'];
        var method = request.method || 'GET';
        if (allowedMethods.indexOf(method.toUpperCase()) === -1) {
            self.sendToIframe({
                type: 'BITRIX_API_RESPONSE',
                payload: {
                    requestId: request.requestId,
                    success: false,
                    error: 'Invalid method'
                }
            });
            return;
        }
        
        // Проверяем, что endpoint в белом списке
        if (this.allowedEndpoints.indexOf(request.endpoint) === -1) {
            self.sendToIframe({
                type: 'BITRIX_API_RESPONSE',
                payload: {
                    requestId: request.requestId,
                    success: false,
                    error: 'Access denied'
                }
            });
            return;
        }
        
        // Создаём объект данных вручную для поддержки старых браузеров
        // ВАЖНО: sessid добавляется последним, чтобы предотвратить переопределение
        var data = {};
        if (request.data) {
            for (var key in request.data) {
                if (request.data.hasOwnProperty(key) && key !== 'sessid') {
                    data[key] = request.data[key];
                }
            }
        }
        // Добавляем sessid в конце, чтобы он не мог быть переопределён
        data.sessid = BX.bitrix_sessid();
        
        BX.ajax({
            method: method,
            url: this.apiBase + request.endpoint,
            data: data,
            dataType: 'json',
            onsuccess: function(data) {
                self.sendToIframe({
                    type: 'BITRIX_API_RESPONSE',
                    payload: {
                        requestId: request.requestId,
                        success: true,
                        data: data
                    }
                });
            },
            onfailure: function(error) {
                self.sendToIframe({
                    type: 'BITRIX_API_RESPONSE',
                    payload: {
                        requestId: request.requestId,
                        success: false,
                        error: error
                    }
                });
            }
        });
    },



    initMarkupAction: function() {
        var self = this;
        var selects = document.querySelectorAll('select[name="action"], select.adm-select[id*="_action"]');

        for (var i = 0; i < selects.length; i++) {
            var select = selects[i];
            if (!select || select.dataset.pwMarkupBound === 'Y') {
                continue;
            }

            if (!select.querySelector('option[value="pw_add_markup"]')) {
                var option = document.createElement('option');
                option.value = 'pw_add_markup';
                option.textContent = 'Добавить наценку';
                select.appendChild(option);
            }

            select.addEventListener('change', function(e) {
                var target = e.target;
                if (!target || target.value !== 'pw_add_markup') {
                    return;
                }

                target.value = '';
                self.openMarkupDialog();
            });

            select.dataset.pwMarkupBound = 'Y';
        }
    },

    openMarkupDialog: function() {
        var self = this;
        var offers = this.getSelectedOffers();

        if (!offers.length) {
            alert('Не выбраны торговые предложения');
            return;
        }

        BX.ajax({
            method: 'POST',
            dataType: 'json',
            url: '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
            data: {
                sessid: BX.bitrix_sessid(),
                action: 'getMarkupSettings'
            },
            onsuccess: function(response) {
                if (!response || !response.success || !response.data) {
                    alert('Не удалось загрузить настройки наценок');
                    return;
                }

                self.showMarkupPopup(response.data, offers);
            },
            onfailure: function() {
                alert('Ошибка запроса настроек наценок');
            }
        });
    },

    showMarkupPopup: function(data, offers) {
        var self = this;
        var priceTypes = Array.isArray(data.priceTypes) ? data.priceTypes : [];
        var settings = data.settings || {};
        var rates = settings.rates || {};
        var basePriceTypeId = parseInt(settings.basePriceTypeId || 0, 10);

        if (!priceTypes.length) {
            alert('Типы цен не найдены');
            return;
        }

        var html = '<div style="padding:12px;max-height:520px;overflow:auto;">' +
            '<div style="margin-bottom:10px;color:#666;">Выбрано ТП: ' + offers.length + '</div>' +
            '<table class="adm-list-table" style="width:100%;">' +
                '<thead><tr class="adm-list-table-header">' +
                    '<td>Тип цены</td><td style="width:210px;">Стартовая цена</td><td style="width:210px;">Наценка, %</td>' +
                '</tr></thead><tbody>';

        for (var i = 0; i < priceTypes.length; i++) {
            var pt = priceTypes[i];
            var id = parseInt(pt.id, 10);
            var checked = basePriceTypeId === id ? 'checked' : '';
            var rate = rates[id] !== undefined ? rates[id] : 0;

            html += '<tr>' +
                '<td>' + BX.util.htmlspecialchars(pt.name || ('ID ' + id)) + ' [' + id + ']</td>' +
                '<td><label><input type="radio" name="pw-markup-base" value="' + id + '" ' + checked + '> Базовый тип</label></td>' +
                '<td><input type="number" data-role="pw-markup-rate" data-price-type-id="' + id + '" value="' + rate + '" step="0.01" style="width:120px;"> %</td>' +
            '</tr>';
        }

        html += '</tbody></table></div>';

        var container = document.createElement('div');
        container.innerHTML = html;

        var popup = new BX.CAdminDialog({
            title: 'Добавить наценку',
            content: container,
            width: 920,
            height: 620,
            resizable: true,
            buttons: [
                '<input type="button" class="adm-btn-save" value="Запустить" id="pw-markup-run">',
                BX.CAdminDialog.btnCancel
            ]
        });

        popup.Show();

        setTimeout(function() {
            var runBtn = document.getElementById('pw-markup-run');
            if (!runBtn) {
                return;
            }

            runBtn.onclick = function() {
                var baseNode = container.querySelector('input[name="pw-markup-base"]:checked');
                if (!baseNode) {
                    alert('Выберите стартовый тип цены');
                    return;
                }

                var requestRates = {};
                container.querySelectorAll('[data-role="pw-markup-rate"]').forEach(function(input) {
                    requestRates[input.dataset.priceTypeId] = input.value || '0';
                });

                BX.ajax({
                    method: 'POST',
                    dataType: 'json',
                    url: '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
                    data: {
                        sessid: BX.bitrix_sessid(),
                        action: 'applyMarkups',
                        offerIds: offers.map(function(o) { return o.id; }).join(','),
                        basePriceTypeId: parseInt(baseNode.value, 10),
                        rates: JSON.stringify(requestRates)
                    },
                    onsuccess: function(response) {
                        if (!response || !response.success) {
                            alert('Ошибка запуска наценки: ' + ((response && response.message) || 'неизвестная ошибка'));
                            return;
                        }

                        popup.Close();
                        alert('Готово. Обновлено ТП: ' + (response.data && response.data.updated ? response.data.updated : 0));
                    },
                    onfailure: function() {
                        alert('Ошибка запроса запуска наценки');
                    }
                });
            };
        }, 0);
    },

    /**
     * Получение ID товара из URL
     */
    getProductId: function() {
        var match = window.location.search.match(/ID=(\d+)/);
        return match ? parseInt(match[1], 10) : null;
    },

    /**
     * Получение ID инфоблока из URL
     */
    getIblockId: function() {
        var match = window.location.search.match(/IBLOCK_ID=(\d+)/);
        return match ? parseInt(match[1], 10) : null;
    }
};

// Экспорт
if (typeof window !== 'undefined') {
    window.ProspekwebCalc = ProspekwebCalc;
}
