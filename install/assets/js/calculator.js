/**
 * ProspekwebCalc - Калькулятор себестоимости
 * Интеграция React-приложения через iframe + postMessage
 * @version 2.0.0
 */

console.log('[BitrixBridge] calculator.js loaded, init integration...');

var ProspekwebCalc = {
    // Пути
    appUrl: '/local/apps/prospektweb.calc/index.html?v=fb1db22f49f7',
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
    
    // Константы
    DOM_STABILIZATION_DELAY: 150, // Задержка в мс для стабилизации DOM после AJAX-обновлений
    INIT_RETRY_DELAY: 200,        // Задержка в мс между повторными попытками initAdminButton
    MAX_INIT_RETRIES: 10,         // Максимальное количество повторных попыток initAdminButton
    
    // Состояние
    dialog: null,
    iframe: null,
    observer: null,
    windowCloseHandler: null,
    isClosing: false,
    _isInserting: false,

    /**
     * Внутренний диалог вместо системных alert/confirm. Возвращает Promise,
     * чтобы одинаково работать в обычных и асинхронных сценариях.
     */
    showInternalDialog: function(options) {
        options = options || {};

        return new Promise(function(resolve) {
            var overlay = document.createElement('div');
            overlay.className = 'prospektweb-calc-internal-dialog';
            overlay.setAttribute('role', 'presentation');
            overlay.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(20,24,31,.48);display:flex;align-items:center;justify-content:center;padding:24px;';

            var panel = document.createElement('div');
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-modal', 'true');
            panel.style.cssText = 'width:min(460px,100%);background:#fff;border:1px solid #dfe3e8;border-radius:12px;box-shadow:0 18px 60px rgba(0,0,0,.28);padding:24px;font:14px/1.45 Arial,sans-serif;color:#202124;';

            var title = document.createElement('div');
            title.style.cssText = 'font-size:18px;font-weight:600;margin:0 0 10px;';
            title.textContent = options.title || 'Калькуляция';

            var message = document.createElement('div');
            message.style.cssText = 'white-space:pre-wrap;color:#4b5563;margin-bottom:22px;';
            message.textContent = options.message || '';

            var actions = document.createElement('div');
            actions.style.cssText = 'display:flex;justify-content:flex-end;gap:10px;';

            var settle = function(result) {
                document.removeEventListener('keydown', onKeyDown, true);
                if (overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
                resolve(result);
            };

            var onKeyDown = function(event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    settle(false);
                }
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    settle(true);
                }
            };

            if (options.confirm) {
                var cancelButton = document.createElement('button');
                cancelButton.type = 'button';
                cancelButton.className = 'adm-btn';
                cancelButton.textContent = options.cancelLabel || 'Отмена';
                cancelButton.addEventListener('click', function() { settle(false); });
                actions.appendChild(cancelButton);
            }

            var acceptButton = document.createElement('button');
            acceptButton.type = 'button';
            acceptButton.className = 'adm-btn adm-btn-save';
            acceptButton.textContent = options.confirmLabel || 'Понятно';
            acceptButton.addEventListener('click', function() { settle(true); });
            actions.appendChild(acceptButton);

            panel.appendChild(title);
            panel.appendChild(message);
            panel.appendChild(actions);
            overlay.appendChild(panel);
            document.body.appendChild(overlay);
            document.addEventListener('keydown', onKeyDown, true);
            acceptButton.focus();
        });
    },

    showMessage: function(message, title) {
        return this.showInternalDialog({ title: title || 'Калькуляция', message: message });
    },

    showConfirmation: function(message, title, confirmLabel) {
        return this.showInternalDialog({
            title: title || 'Подтвердите действие',
            message: message,
            confirm: true,
            confirmLabel: confirmLabel || 'Продолжить'
        });
    },

    /**
     * Инициализация кнопки в админке
     */
    init: function(containerId, props) {
        this.loadCss(this.cssPath);
        if (!containerId) {
            this.initAdminButton();
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

        // Если кнопка уже есть — ничего не делаем
        var existingCalc = document.getElementById('btn_prospektweb_calc');

        if (existingCalc) {
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

                if (anchorNode && anchorNode.parentNode) {
                    anchorNode.parentNode.insertBefore(calcBtn, anchorNode.nextSibling);
                } else {
                    toolbar.appendChild(calcBtn);
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
        var offersTab = document.getElementById('tab_sub_list');
        if (!offersTab) {
            return null;
        }

        var genBtn = offersTab.querySelector('#btn_sub_gen');
        if (genBtn && genBtn.parentNode) {
            return { toolbar: genBtn.parentNode, anchor: genBtn };
        }

        var selectors = [
            '.adm-detail-toolbar',
            '.adm-list-table-top',
            '.adm-list-table-layout',
            '.adm-list-table-footer'
        ];

        for (var i = 0; i < selectors.length; i++) {
            var toolbar = offersTab.querySelector(selectors[i]);
            if (!toolbar) {
                continue;
            }

            var anchor = toolbar.querySelector('.adm-btn') || toolbar.querySelector('a,button,input[type="button"]');
            return { toolbar: toolbar, anchor: anchor };
        }

        return null;
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
        
        // Следим только за таблицей торговых предложений. Общая панель карточки
        // товара не является допустимым местом для этих массовых действий.
        var targetNode = document.getElementById('tab_sub_list');
        if (!targetNode) {
            return;
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
            
            // Проверяем, что кнопка калькуляции присутствует после AJAX-перерисовки
            var calcBtn = document.getElementById('btn_prospektweb_calc');

            if (!calcBtn) {
                setTimeout(function() {
                    self.initAdminButton();
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
            var editUrl = '/bitrix/admin/iblock_list_admin.php?IBLOCK_ID=' + iblockId +
                         '&type=catalog&lang=ru&find_section_section=0&find_id=' + productId +
                         '&set_filter=Y&apply_filter=Y';
            
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
            this.showMessage('Не выбраны торговые предложения');
            return;
        }

        // Загружаем тот же строгий INIT, который получит редактор. Это единая
        // проверка всех выбранных ТП, их товаров и одного явного CALC_PRESET.
        var catalogInit = await this.loadCatalogInitPayload(offers);
        if (!catalogInit || catalogInit.error) {
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
            this.showMessage('Ошибка загрузки модуля интеграции', 'Не удалось открыть калькуляцию');
            return;
        }

        // Создаём интеграцию с передачей iframe напрямую
        self.integration = new window.ProspektwebCalcIntegration({
            iframe: iframe,
            ajaxEndpoint: '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
            offerIds: offers.map(function(o) { return o.id; }),
            siteId: BX.message('PROSPEKTWEB_CALC_SITE_ID') || BX.message('SITE_ID') || (typeof SITE_ID !== 'undefined' ? SITE_ID : 's1'),
            sessid: BX.bitrix_sessid(),
            initPayload: catalogInit.initPayload,
            onClose: function() {
                self.closeDialog();
            },
            onError: function(error) {
                console.error('[ProspekwebCalc] Calc error:', error);
                self.showMessage('Ошибка калькулятора: ' + (error.message || 'Неизвестная ошибка'), 'Ошибка калькулятора');
            }
        });

        console.log('[BitrixBridge] ProspektwebCalcIntegration created', {
            iframe: '#calc-iframe',
            ajaxUrl: '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
        });

        dialog.Show();
        this.expandCalculatorDialog(dialog);
    },

    /**
     * Разворачивает CAdminDialog сразу после показа. Bitrix добавляет кнопку
     * асинхронно, поэтому ждём два кадра отрисовки и используем штатное действие.
     */
    expandCalculatorDialog: function(dialog) {
        var expand = function() {
            if (!dialog || !dialog.DIV) {
                return;
            }

            var nativeExpand = dialog.DIV.querySelector('.bx-core-adm-icon-expand');
            if (nativeExpand && nativeExpand.dataset.prospektwebExpanded !== 'Y') {
                nativeExpand.dataset.prospektwebExpanded = 'Y';
                nativeExpand.click();
            }

            dialog.DIV.classList.add('prospektweb-calc-fullscreen-dialog');
            dialog.DIV.style.position = 'fixed';
            dialog.DIV.style.inset = '0';
            dialog.DIV.style.left = '0';
            dialog.DIV.style.top = '0';
            dialog.DIV.style.width = '100vw';
            dialog.DIV.style.height = '100vh';
            dialog.DIV.style.maxWidth = 'none';
            dialog.DIV.style.maxHeight = 'none';
            dialog.DIV.style.margin = '0';
            dialog.DIV.style.padding = '0';
            dialog.DIV.style.border = '0';
            dialog.DIV.style.borderRadius = '0';
            dialog.DIV.style.boxSizing = 'border-box';
            dialog.DIV.style.overflow = 'hidden';
            dialog.DIV.style.transform = 'none';

            var head = dialog.DIV.querySelector('.bx-core-adm-dialog-head');
            if (head) {
                head.style.setProperty('display', 'none', 'important');
            }

            var buttons = dialog.DIV.querySelector('.bx-core-adm-dialog-buttons');
            if (buttons) {
                buttons.style.setProperty('display', 'none', 'important');
            }

            var tabs = dialog.DIV.querySelector('.bx-core-adm-dialog-tabs');
            if (tabs) {
                tabs.style.setProperty('display', 'none', 'important');
            }

            var wrap = dialog.DIV.querySelector('.bx-core-adm-dialog-content-wrap');
            if (wrap) {
                wrap.style.setProperty('position', 'absolute', 'important');
                wrap.style.setProperty('inset', '0', 'important');
                wrap.style.setProperty('width', '100%', 'important');
                wrap.style.setProperty('height', '100%', 'important');
                wrap.style.setProperty('max-height', 'none', 'important');
                wrap.style.setProperty('margin', '0', 'important');
                wrap.style.setProperty('padding', '0', 'important');
                wrap.style.setProperty('box-sizing', 'border-box', 'important');
                wrap.style.setProperty('overflow', 'hidden', 'important');
            }

            var content = dialog.DIV.querySelector('.bx-core-adm-dialog-content');
            if (content) {
                content.style.setProperty('width', '100%', 'important');
                content.style.setProperty('height', '100%', 'important');
                content.style.setProperty('max-height', 'none', 'important');
                content.style.setProperty('margin', '0', 'important');
                content.style.setProperty('padding', '0', 'important');
                content.style.setProperty('box-sizing', 'border-box', 'important');
                content.style.setProperty('overflow', 'hidden', 'important');
            }

            var frame = dialog.DIV.querySelector('.bx-core-adm-dialog-content iframe');
            if (frame) {
                frame.style.setProperty('width', '100%', 'important');
                frame.style.setProperty('height', '100%', 'important');
                frame.style.setProperty('margin', '0', 'important');
                frame.style.setProperty('border', '0', 'important');
                frame.style.setProperty('display', 'block', 'important');
            }

            var resizer = dialog.DIV.querySelector('.bx-core-resizer');
            if (resizer) {
                resizer.style.setProperty('display', 'none', 'important');
            }

            document.documentElement.classList.add('prospektweb-calc-dialog-open');
            document.body.classList.add('prospektweb-calc-dialog-open');
        };

        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(function() {
                window.requestAnimationFrame(expand);
            });
        } else {
            window.setTimeout(expand, 0);
        }
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
     * Загрузить строгий catalog INIT до открытия диалога.
     * @param {Array} offers
     * @returns {Promise<{success: boolean, presetId?: number, initPayload?: Object, error?: boolean}>}
     */
    loadCatalogInitPayload: async function(offers) {
        var offerIds = offers.map(function(o) { return o.id; });
        var ajaxEndpoint = '/bitrix/tools/prospektweb.calc/calculator_ajax.php';
        var sessid = BX.bitrix_sessid();
        var siteId = BX.message('PROSPEKTWEB_CALC_SITE_ID') || BX.message('SITE_ID') || (typeof SITE_ID !== 'undefined' ? SITE_ID : 's1');

        try {
            var initBody = new URLSearchParams();
            initBody.set('action', 'getInitData');
            initBody.set('offerIds', offerIds.join(','));
            initBody.set('siteId', siteId);
            initBody.set('sessid', sessid);

            var initResponse = await fetch(ajaxEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: initBody.toString()
            });

            var initData = await this.parseJsonResponse(initResponse);
            if (!initResponse.ok || !initData.success || !initData.data) {
                throw new Error((initData && (initData.message || initData.error)) || 'Не удалось загрузить калькулятор');
            }

            var presetId = parseInt(initData.data.preset && initData.data.preset.id, 10) || 0;
            if (!presetId || !initData.data.editorRuntime) {
                throw new Error('Сервер вернул неполный контракт запуска калькулятора');
            }

            return { success: true, presetId: presetId, initPayload: initData.data };
        } catch (error) {
            console.error('[ProspektwebCalc] Catalog INIT error:', error);
            var message = error && error.message ? error.message : 'Не удалось загрузить калькулятор';
            if (message.indexOf('не имеет пресета') !== -1 || message.indexOf('CALC_PRESET') !== -1) {
                message = 'Товару не назначен калькулятор. Назначьте пресет в Центре управления и повторите запуск.';
            }
            this.showMessage(message, 'Калькулятор не открыт');
            return { success: false, error: true };
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
        document.documentElement.classList.remove('prospektweb-calc-dialog-open');
        document.body.classList.remove('prospektweb-calc-dialog-open');

        this.isClosing = false;
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
