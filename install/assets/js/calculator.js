/**
 * ProspekwebCalc - РљР°Р»СЊРєСѓР»СЏС‚РѕСЂ СЃРµР±РµСЃС‚РѕРёРјРѕСЃС‚Рё
 * РРЅС‚РµРіСЂР°С†РёСЏ React-РїСЂРёР»РѕР¶РµРЅРёСЏ С‡РµСЂРµР· iframe + postMessage
 * @version 2.0.0
 */

console.log('[BitrixBridge] calculator.js loaded, init integration...');

var ProspekwebCalc = {
    // РџСѓС‚Рё
        appUrl: '/local/apps/prospektweb.calc/index.html?v=6c065575ace6',
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
    
    // Р‘РµР»С‹Р№ СЃРїРёСЃРѕРє СЂР°Р·СЂРµС€С‘РЅРЅС‹С… endpoints РґР»СЏ Р±РµР·РѕРїР°СЃРЅРѕСЃС‚Рё
    allowedEndpoints: [
        'calculators.php',
        'config.php',
        'equipment.php',
        'elements.php',
        'calculator_config.php',
        'calculate.php',
        'save_result.php'
    ],
    
    // РљРѕРЅСЃС‚Р°РЅС‚С‹
    DOM_STABILIZATION_DELAY: 150, // Р—Р°РґРµСЂР¶РєР° РІ РјСЃ РґР»СЏ СЃС‚Р°Р±РёР»РёР·Р°С†РёРё DOM РїРѕСЃР»Рµ AJAX-РѕР±РЅРѕРІР»РµРЅРёР№
    INIT_RETRY_DELAY: 200,        // Р—Р°РґРµСЂР¶РєР° РІ РјСЃ РјРµР¶РґСѓ РїРѕРІС‚РѕСЂРЅС‹РјРё РїРѕРїС‹С‚РєР°РјРё initAdminButton
    MAX_INIT_RETRIES: 10,         // РњР°РєСЃРёРјР°Р»СЊРЅРѕРµ РєРѕР»РёС‡РµСЃС‚РІРѕ РїРѕРІС‚РѕСЂРЅС‹С… РїРѕРїС‹С‚РѕРє initAdminButton
    PRESET_CONFIRM_MESSAGE: 'РќРµРѕР±С…РѕРґРёРјРѕ СЃРѕР·РґР°С‚СЊ РЅРѕРІС‹Р№ РїСЂРµСЃРµС‚ РєР°Р»СЊРєСѓР»СЏС†РёРё',
    
    // РЎРѕСЃС‚РѕСЏРЅРёРµ
    dialog: null,
    iframe: null,
    messageHandler: null,
    observer: null,
    windowCloseHandler: null,
    isClosing: false,
    _isInserting: false,

    /**
     * Р’РЅСѓС‚СЂРµРЅРЅРёР№ РґРёР°Р»РѕРі РІРјРµСЃС‚Рѕ СЃРёСЃС‚РµРјРЅС‹С… alert/confirm. Р’РѕР·РІСЂР°С‰Р°РµС‚ Promise,
     * С‡С‚РѕР±С‹ РѕРґРёРЅР°РєРѕРІРѕ СЂР°Р±РѕС‚Р°С‚СЊ РІ РѕР±С‹С‡РЅС‹С… Рё Р°СЃРёРЅС…СЂРѕРЅРЅС‹С… СЃС†РµРЅР°СЂРёСЏС….
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
            title.textContent = options.title || 'РљР°Р»СЊРєСѓР»СЏС†РёСЏ';

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
                cancelButton.textContent = options.cancelLabel || 'РћС‚РјРµРЅР°';
                cancelButton.addEventListener('click', function() { settle(false); });
                actions.appendChild(cancelButton);
            }

            var acceptButton = document.createElement('button');
            acceptButton.type = 'button';
            acceptButton.className = 'adm-btn adm-btn-save';
            acceptButton.textContent = options.confirmLabel || 'РџРѕРЅСЏС‚РЅРѕ';
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
        return this.showInternalDialog({ title: title || 'РљР°Р»СЊРєСѓР»СЏС†РёСЏ', message: message });
    },

    showConfirmation: function(message, title, confirmLabel) {
        return this.showInternalDialog({
            title: title || 'РџРѕРґС‚РІРµСЂРґРёС‚Рµ РґРµР№СЃС‚РІРёРµ',
            message: message,
            confirm: true,
            confirmLabel: confirmLabel || 'РџСЂРѕРґРѕР»Р¶РёС‚СЊ'
        });
    },

    /**
     * РРЅРёС†РёР°Р»РёР·Р°С†РёСЏ РєРЅРѕРїРєРё РІ Р°РґРјРёРЅРєРµ
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
     * РРЅРёС†РёР°Р»РёР·Р°С†РёСЏ РєРЅРѕРїРєРё РІ Р°РґРјРёРЅРєРµ
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

        // Р•СЃР»Рё РѕР±Рµ РєРЅРѕРїРєРё СѓР¶Рµ РµСЃС‚СЊ вЂ” РЅРёС‡РµРіРѕ РЅРµ РґРµР»Р°РµРј
        var existingCalc = document.getElementById('btn_prospektweb_calc');
        var existingMarkup = document.getElementById('btn_prospektweb_markup');

        if (existingCalc && existingMarkup) {
            return;
        }

        // Р‘Р»РѕРєРёСЂСѓРµРј Observer РЅР° РІСЂРµРјСЏ РІСЃС‚Р°РІРєРё
        self._isInserting = true;

        try {
            // РЎРѕР·РґР°С‘Рј РєРЅРѕРїРєСѓ "РљР°Р»СЊРєСѓР»СЏС†РёСЏ" РµСЃР»Рё РµС‘ РЅРµС‚
            var calcBtn = existingCalc;
            if (!calcBtn) {
                calcBtn = document.createElement('a');
                calcBtn.id = 'btn_prospektweb_calc';
                calcBtn.className = 'adm-btn';
                calcBtn.href = 'javascript:void(0)';
                calcBtn.title = 'РљР°Р»СЊРєСѓР»СЏС†РёСЏ СЃРµР±РµСЃС‚РѕРёРјРѕСЃС‚Рё';
                calcBtn.textContent = 'РљР°Р»СЊРєСѓР»СЏС†РёСЏ';

                calcBtn.addEventListener('click', function() {
                    self.openCalculatorDialog();
                });

                if (anchorNode && anchorNode.parentNode) {
                    anchorNode.parentNode.insertBefore(calcBtn, anchorNode.nextSibling);
                } else {
                    toolbar.appendChild(calcBtn);
                }
            }

            // РЎРѕР·РґР°С‘Рј РєРЅРѕРїРєСѓ "Р”РѕР±Р°РІРёС‚СЊ РЅР°С†РµРЅРєСѓ" РµСЃР»Рё РµС‘ РЅРµС‚ вЂ” РЎР РђР—РЈ РїРѕСЃР»Рµ РєР°Р»СЊРєСѓР»СЏС†РёРё
            if (!existingMarkup) {
                var markupBtn = document.createElement('a');
                markupBtn.id = 'btn_prospektweb_markup';
                markupBtn.className = 'adm-btn';
                markupBtn.href = 'javascript:void(0)';
                markupBtn.title = 'Р”РѕР±Р°РІРёС‚СЊ РЅР°С†РµРЅРєСѓ';
                markupBtn.textContent = 'Р”РѕР±Р°РІРёС‚СЊ РЅР°С†РµРЅРєСѓ';

                markupBtn.addEventListener('click', function() {
                    self.openMarkupDialog();
                });

                // Р’СЃС‚Р°РІР»СЏРµРј СЃСЂР°Р·Сѓ РїРѕСЃР»Рµ РєРЅРѕРїРєРё РєР°Р»СЊРєСѓР»СЏС†РёРё
                if (calcBtn && calcBtn.parentNode) {
                    calcBtn.parentNode.insertBefore(markupBtn, calcBtn.nextSibling);
                } else {
                    toolbar.appendChild(markupBtn);
                }
            }
        } finally {
            // РЎРЅРёРјР°РµРј Р±Р»РѕРєРёСЂРѕРІРєСѓ С‡РµСЂРµР· РјРёРєСЂРѕР·Р°РґРµСЂР¶РєСѓ, С‡С‚РѕР±С‹ Observer СѓСЃРїРµР» РїСЂРѕРїСѓСЃС‚РёС‚СЊ РЅР°С€Рё РёР·РјРµРЅРµРЅРёСЏ
            setTimeout(function() {
                self._isInserting = false;
            }, 0);
        }
    },

    /**
     * РќР°Р№С‚Рё С‚СѓР»Р±Р°СЂ РўРџ Рё РѕРїРѕСЂРЅСѓСЋ РєРЅРѕРїРєСѓ, СЂСЏРґРѕРј СЃ РєРѕС‚РѕСЂРѕР№ РІСЃС‚Р°РІР»СЏС‚СЊ РЅР°С€Рё РєРЅРѕРїРєРё.
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
     * РРЅРёС†РёР°Р»РёР·Р°С†РёСЏ РєРЅРѕРїРєРё РјР°СЃСЃРѕРІРѕР№ РЅР°С†РµРЅРєРё СЂСЏРґРѕРј СЃ РљР°Р»СЊРєСѓР»СЏС†РёРµР№
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
        markupBtn.title = 'Р”РѕР±Р°РІРёС‚СЊ РЅР°С†РµРЅРєСѓ';
        markupBtn.textContent = 'Р”РѕР±Р°РІРёС‚СЊ РЅР°С†РµРЅРєСѓ';

        markupBtn.addEventListener('click', function() {
            self.openMarkupDialog();
        });

        if (afterNode && afterNode.parentNode) {
            afterNode.parentNode.insertBefore(markupBtn, afterNode.nextSibling);
        } else {
            toolbar.appendChild(markupBtn);
        }
    },

    /**
     * Р—Р°РїСѓСЃРє РЅР°Р±Р»СЋРґР°С‚РµР»СЏ Р·Р° РёР·РјРµРЅРµРЅРёСЏРјРё DOM
     */
    startObserver: function() {
        var self = this;
        
        // Р•СЃР»Рё СѓР¶Рµ Р·Р°РїСѓС‰РµРЅ - РЅРµ Р·Р°РїСѓСЃРєР°РµРј РїРѕРІС‚РѕСЂРЅРѕ
        if (this.observer) {
            return;
        }
        
        // РС‰РµРј РєРѕРЅС‚РµР№РЅРµСЂ С‚Р°Р±Р»РёС†С‹ РўРџ (tab_sub_list РёР»Рё adm-detail-content-wrap)
        var targetNode = document.getElementById('tab_sub_list') || 
                         document.querySelector('.adm-detail-content-wrap');
        
        if (!targetNode) {
            // Fallback: РЅР°Р±Р»СЋРґР°РµРј Р·Р° body
            targetNode = document.body;
        }
        
        this.observer = new MutationObserver(function(mutations) {
            // РџСЂРѕРїСѓСЃРєР°РµРј, РµСЃР»Рё РјС‹ СЃР°РјРё РІСЃС‚Р°РІР»СЏРµРј РєРЅРѕРїРєРё
            if (self._isInserting) {
                return;
            }

            // РћРїС‚РёРјРёР·Р°С†РёСЏ: РїСЂРѕРІРµСЂСЏРµРј, РµСЃС‚СЊ Р»Рё РёР·РјРµРЅРµРЅРёСЏ РІ РґРѕР±Р°РІР»РµРЅРЅС‹С…/СѓРґР°Р»С‘РЅРЅС‹С… СѓР·Р»Р°С…
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
            
            // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РѕР±Рµ РєРЅРѕРїРєРё РїСЂРёСЃСѓС‚СЃС‚РІСѓСЋС‚ РїРѕСЃР»Рµ AJAX-РїРµСЂРµСЂРёСЃРѕРІРєРё
            var calcBtn = document.getElementById('btn_prospektweb_calc');
            var markupBtn = document.getElementById('btn_prospektweb_markup');
            var markupExists = !!document.querySelector('select[name="action"] option[value="pw_add_markup"]');
            
            if (calcBtn && !markupBtn) {
                // РљРЅРѕРїРєР° РєР°Р»СЊРєСѓР»СЏС†РёРё РµСЃС‚СЊ, Р° РЅР°С†РµРЅРєРё РЅРµС‚ вЂ” РґРѕР±Р°РІР»СЏРµРј РЅР°С†РµРЅРєСѓ РЅР°РїСЂСЏРјСѓСЋ
                setTimeout(function() {
                    var toolbar = calcBtn.parentNode;
                    if (toolbar) {
                        self.initMarkupButton(toolbar, calcBtn);
                    }
                }, self.DOM_STABILIZATION_DELAY);
            } else if (!calcBtn) {
                // РћР±РµРёС… РєРЅРѕРїРѕРє РЅРµС‚ вЂ” РїСЂРѕР±СѓРµРј РґРѕР±Р°РІРёС‚СЊ РѕР±Рµ
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
     * РћСЃС‚Р°РЅРѕРІРєР° РЅР°Р±Р»СЋРґР°С‚РµР»СЏ Р·Р° РёР·РјРµРЅРµРЅРёСЏРјРё DOM
     */
    stopObserver: function() {
        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }
    },

    /**
     * РџРѕР»СѓС‡РµРЅРёРµ РїРѕР»РЅРѕР№ РёРЅС„РѕСЂРјР°С†РёРё Рѕ РІС‹Р±СЂР°РЅРЅС‹С… С‚РѕСЂРіРѕРІС‹С… РїСЂРµРґР»РѕР¶РµРЅРёСЏС…
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
            
            // РќР°С…РѕРґРёРј СЃС‚СЂРѕРєСѓ С‚Р°Р±Р»РёС†С‹ РґР»СЏ РїРѕР»СѓС‡РµРЅРёСЏ РЅР°Р·РІР°РЅРёСЏ
            var row = checkbox.closest('tr');
            var name = 'РўРџ #' + id; // Р—РЅР°С‡РµРЅРёРµ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
            
            if (row) {
                // РС‰РµРј СЏС‡РµР№РєСѓ СЃ РЅР°Р·РІР°РЅРёРµРј (РѕР±С‹С‡РЅРѕ СЌС‚Рѕ РІС‚РѕСЂР°СЏ РёР»Рё С‚СЂРµС‚СЊСЏ РєРѕР»РѕРЅРєР° РїРѕСЃР»Рµ С‡РµРєР±РѕРєСЃР°)
                var cells = row.querySelectorAll('td');
                for (var j = 0; j < cells.length; j++) {
                    var cell = cells[j];
                    // РџСЂРѕРїСѓСЃРєР°РµРј СЏС‡РµР№РєСѓ СЃ С‡РµРєР±РѕРєСЃРѕРј Рё СЏС‡РµР№РєРё СЃ РєРЅРѕРїРєР°РјРё/РёРєРѕРЅРєР°РјРё
                    if (!cell.querySelector('input[type="checkbox"]') && 
                        !cell.querySelector('a.adm-btn-delete') &&
                        cell.textContent.trim().length > 0) {
                        name = cell.textContent.trim();
                        break;
                    }
                }
            }
            
            // Р¤РѕСЂРјРёСЂСѓРµРј URL РґР»СЏ СЂРµРґР°РєС‚РёСЂРѕРІР°РЅРёСЏ РўРџ
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
     * РћС‚РєСЂС‹С‚РёРµ РґРёР°Р»РѕРіР° СЃ iframe
     */
    openCalculatorDialog: async function() {
        this.loadCss(this.cssPath);
        var self = this;

        // РџРѕР»СѓС‡Р°РµРј РІС‹Р±СЂР°РЅРЅС‹Рµ РўРџ СЃ РїРѕР»РЅРѕР№ РёРЅС„РѕСЂРјР°С†РёРµР№
        var offers = this.getSelectedOffers();

        if (offers.length === 0) {
            this.showMessage('РќРµ РІС‹Р±СЂР°РЅС‹ С‚РѕСЂРіРѕРІС‹Рµ РїСЂРµРґР»РѕР¶РµРЅРёСЏ');
            return;
        }

        // РџСЂРѕРІРµСЂСЏРµРј CALC_PRESET РїРµСЂРµРґ СЃРѕР·РґР°РЅРёРµРј РґРёР°Р»РѕРіР°
        var presetCheck = await this.ensurePresetAvailability(offers);
        if (!presetCheck || presetCheck.cancelled || presetCheck.error) {
            return;
        }

        // РЎРѕР·РґР°С‘Рј РєРѕРЅС‚РµР№РЅРµСЂ РґР»СЏ iframe
        var container = document.createElement('div');
        container.style.width = '100%';
        container.style.height = '100%';
        container.style.overflow = 'hidden';

        // РЎРѕР·РґР°С‘Рј iframe
        var iframe = document.createElement('iframe');
        iframe.src = this.appUrl;
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = 'none';
        iframe.style.display = 'block';
        
        container.appendChild(iframe);
        this.iframe = iframe;

        // РЎРѕР·РґР°С‘Рј РґРёР°Р»РѕРі
        var dialog = new BX.CAdminDialog({
            title: 'РљР°Р»СЊРєСѓР»СЏС†РёСЏ СЃРµР±РµСЃС‚РѕРёРјРѕСЃС‚Рё',
            content: container,
            width: 1400,
            height: 800,
            resizable: true,
            draggable: true
        });

        this.dialog = dialog;

        this.windowCloseHandler = this.handleWindowClose.bind(this);
        BX.addCustomEvent(dialog, 'onWindowClose', this.windowCloseHandler);

        // РСЃРїРѕР»СЊР·СѓРµРј ProspektwebCalcIntegration РґР»СЏ РѕР±СЂР°Р±РѕС‚РєРё postMessage СЃСЂР°Р·Сѓ,
        // С‡С‚РѕР±С‹ РЅРµ РїСЂРѕРїСѓСЃС‚РёС‚СЊ РїРµСЂРІРѕРµ СЃРѕРѕР±С‰РµРЅРёРµ READY, РєРѕС‚РѕСЂРѕРµ iframe РѕС‚РїСЂР°РІР»СЏРµС‚
        // СЃСЂР°Р·Сѓ РїРѕСЃР»Рµ Р·Р°РіСЂСѓР·РєРё РїСЂРёР»РѕР¶РµРЅРёСЏ.
        // РџСЂРѕРІРµСЂСЏРµРј РґРѕСЃС‚СѓРїРЅРѕСЃС‚СЊ ProspektwebCalcIntegration
        if (typeof window.ProspektwebCalcIntegration === 'undefined') {
            console.error('[ProspekwebCalc] ProspektwebCalcIntegration not loaded');
            this.showMessage('РћС€РёР±РєР° Р·Р°РіСЂСѓР·РєРё РјРѕРґСѓР»СЏ РёРЅС‚РµРіСЂР°С†РёРё', 'РќРµ СѓРґР°Р»РѕСЃСЊ РѕС‚РєСЂС‹С‚СЊ РєР°Р»СЊРєСѓР»СЏС†РёСЋ');
            return;
        }

        // РЎРѕР·РґР°С‘Рј РёРЅС‚РµРіСЂР°С†РёСЋ СЃ РїРµСЂРµРґР°С‡РµР№ iframe РЅР°РїСЂСЏРјСѓСЋ
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
                self.showMessage('РћС€РёР±РєР° РєР°Р»СЊРєСѓР»СЏС‚РѕСЂР°: ' + (error.message || 'РќРµРёР·РІРµСЃС‚РЅР°СЏ РѕС€РёР±РєР°'), 'РћС€РёР±РєР° РєР°Р»СЊРєСѓР»СЏС‚РѕСЂР°');
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
     * Р Р°Р·РІРѕСЂР°С‡РёРІР°РµС‚ CAdminDialog СЃСЂР°Р·Сѓ РїРѕСЃР»Рµ РїРѕРєР°Р·Р°. Bitrix РґРѕР±Р°РІР»СЏРµС‚ РєРЅРѕРїРєСѓ
     * Р°СЃРёРЅС…СЂРѕРЅРЅРѕ, РїРѕСЌС‚РѕРјСѓ Р¶РґС‘Рј РґРІР° РєР°РґСЂР° РѕС‚СЂРёСЃРѕРІРєРё Рё РёСЃРїРѕР»СЊР·СѓРµРј С€С‚Р°С‚РЅРѕРµ РґРµР№СЃС‚РІРёРµ.
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
            throw new Error('РЎРµСЂРІРµСЂ РІРµСЂРЅСѓР» РЅРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ РѕС‚РІРµС‚ (HTML РІРјРµСЃС‚Рѕ JSON). РЎС‚Р°С‚СѓСЃ: ' + response.status);
        }

        try {
            return await response.json();
        } catch (parseError) {
            console.error('[ProspektwebCalc] JSON parse error:', parseError);
            throw new Error('РћС€РёР±РєР° РїР°СЂСЃРёРЅРіР° РѕС‚РІРµС‚Р° СЃРµСЂРІРµСЂР°. Р’РѕР·РјРѕР¶РЅРѕ, СЃРµСЂРІРµСЂ РІРµСЂРЅСѓР» HTML РІРјРµСЃС‚Рѕ JSON.');
        }
    },

    /**
     * РџСЂРµРґРІР°СЂРёС‚РµР»СЊРЅР°СЏ РїСЂРѕРІРµСЂРєР°/СЃРѕР·РґР°РЅРёРµ CALC_PRESET РґР»СЏ РІС‹Р±СЂР°РЅРЅС‹С… РўРџ
     * РЈРїСЂРѕС‰РµРЅРЅР°СЏ Р»РѕРіРёРєР°: РѕРґРёРЅ РїСЂРµСЃРµС‚ РЅР° С‚РѕРІР°СЂ, РєРѕРЅС„Р»РёРєС‚РѕРІ Р±РѕР»СЊС€Рµ РЅРµС‚
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
            // РџСЂРѕРІРµСЂСЏРµРј РЅР°Р»РёС‡РёРµ РїСЂРµСЃРµС‚Р° Сѓ С‚РѕРІР°СЂР°
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
                throw new Error((checkData && (checkData.message || checkData.error)) || 'РћС€РёР±РєР° РїСЂРѕРІРµСЂРєРё РїСЂРµСЃРµС‚РѕРІ');
            }

            if (!checkData.data) {
                throw new Error('РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ РѕС‚РІРµС‚ РїСЂРѕРІРµСЂРєРё РїСЂРµСЃРµС‚РѕРІ');
            }

            var hasPreset = Boolean(checkData.data.hasPreset);
            var presetId = checkData.data.presetId ? parseInt(checkData.data.presetId, 10) : null;

            if (hasPreset && presetId) {
                // РџСЂРµСЃРµС‚ СѓР¶Рµ РµСЃС‚СЊ Сѓ С‚РѕРІР°СЂР° вЂ” РёСЃРїРѕР»СЊР·СѓРµРј РµРіРѕ
                return { success: true, presetId: presetId, skipPresetCheck: true };
            }

            // РџСЂРµСЃРµС‚Р° РЅРµС‚ вЂ” Р·Р°РїСЂР°С€РёРІР°РµРј РїРѕРґС‚РІРµСЂР¶РґРµРЅРёРµ РЅР° СЃРѕР·РґР°РЅРёРµ
            var confirmed = await this.showConfirmation(this.PRESET_CONFIRM_MESSAGE, 'РЎРѕР·РґР°РЅРёРµ РїСЂРµСЃРµС‚Р°', 'РЎРѕР·РґР°С‚СЊ');
            if (!confirmed) {
                return { success: false, cancelled: true, skipPresetCheck: true };
            }

            // РЎРѕР·РґР°С‘Рј РЅРѕРІС‹Р№ РїСЂРµСЃРµС‚ (Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєРё РїСЂРёРІСЏР¶РµС‚СЃСЏ Рє С‚РѕРІР°СЂСѓ)
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
                throw new Error((createData && (createData.message || createData.error)) || 'РћС€РёР±РєР° СЃРѕР·РґР°РЅРёСЏ РїСЂРµСЃРµС‚Р°');
            }

            return {
                success: true,
                presetId: createData.data ? createData.data.presetId : null,
                skipPresetCheck: true,
            };
        } catch (error) {
            console.error('[ProspektwebCalc] Preset check error:', error);
            this.showMessage('РћС€РёР±РєР° РїСЂРѕРІРµСЂРєРё/СЃРѕР·РґР°РЅРёСЏ РїСЂРµСЃРµС‚Р°: ' + error.message, 'РћС€РёР±РєР° РїСЂРµСЃРµС‚Р°');
            return { success: false, error: true, skipPresetCheck: true };
        }
    },

    /**
     * РћС‚РїСЂР°РІРєР° СЃРѕРѕР±С‰РµРЅРёСЏ РІ iframe
     * @deprecated РСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ ProspektwebCalcIntegration
     */
    sendToIframe: function(message) {
        if (this.iframe && this.iframe.contentWindow) {
            // РћС‚РїСЂР°РІР»СЏРµРј РІ С‚РѕРј Р¶Рµ РґРѕРјРµРЅРµ - Р±РµР·РѕРїР°СЃРЅРѕ РёСЃРїРѕР»СЊР·РѕРІР°С‚СЊ window.location.origin
            var targetOrigin = window.location.origin;
            this.iframe.contentWindow.postMessage(message, targetOrigin);
        }
    },

    /**
     * РћР±СЂР°Р±РѕС‚РєР° СЃРѕРѕР±С‰РµРЅРёР№ РѕС‚ iframe
     * @deprecated РСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ ProspektwebCalcIntegration
     */
    handleMessage: function(event) {
        // РџСЂРѕРІРµСЂСЏРµРј origin - РїСЂРёРЅРёРјР°РµРј С‚РѕР»СЊРєРѕ СЃРѕРѕР±С‰РµРЅРёСЏ СЃ С‚РѕРіРѕ Р¶Рµ РґРѕРјРµРЅР°
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
                // РћС‚РєСЂС‹РІР°РµРј РўРџ РІ РЅРѕРІРѕР№ РІРєР»Р°РґРєРµ Р±СЂР°СѓР·РµСЂР°
                if (data.payload && data.payload.editUrl) {
                    window.open(data.payload.editUrl, '_blank');
                    console.log('Opening offer in new tab:', data.payload.id);
                }
                break;
                
            case 'CALC_REMOVE_OFFER':
                // Р›РѕРіРёСЂРѕРІР°РЅРёРµ СѓРґР°Р»РµРЅРёСЏ РўРџ РёР· СЃРїРёСЃРєР°
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
     * Р—Р°РєСЂС‹С‚РёРµ РґРёР°Р»РѕРіР°
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

        // РЈРЅРёС‡С‚РѕР¶Р°РµРј РёРЅС‚РµРіСЂР°С†РёСЋ РµСЃР»Рё РѕРЅР° СЃСѓС‰РµСЃС‚РІСѓРµС‚
        if (this.integration && typeof this.integration.destroy === 'function') {
            this.integration.destroy();
            this.integration = null;
        }
        
        // РЈРґР°Р»СЏРµРј СЃС‚Р°СЂС‹Р№ РѕР±СЂР°Р±РѕС‚С‡РёРє СЃРѕРѕР±С‰РµРЅРёР№ (РґР»СЏ РѕР±СЂР°С‚РЅРѕР№ СЃРѕРІРјРµСЃС‚РёРјРѕСЃС‚Рё)
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
        document.documentElement.classList.remove('prospektweb-calc-dialog-open');
        document.body.classList.remove('prospektweb-calc-dialog-open');

        this.isClosing = false;
    },

    /**
     * РћР±СЂР°Р±РѕС‚РєР° СЂРµР·СѓР»СЊС‚Р°С‚Р° РєР°Р»СЊРєСѓР»СЏС†РёРё
     * @deprecated РСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ ProspektwebCalcIntegration
     */
    handleCalculationResult: function(result) {
        var self = this;
        
        // РћС‚РїСЂР°РІР»СЏРµРј СЂРµР·СѓР»СЊС‚Р°С‚ РЅР° СЃРµСЂРІРµСЂ
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
                // РћР±СЂР°Р±РѕС‚РєР° СЃРµС‚РµРІС‹С… РѕС€РёР±РѕРє
                self.sendToIframe({
                    type: 'BITRIX_SAVE_ERROR',
                    payload: 'Network error: ' + (error || 'Unknown error')
                });
            }
        );
    },

    /**
     * РЎРѕС…СЂР°РЅРµРЅРёРµ РєРѕРЅС„РёРіСѓСЂР°С†РёРё
     * @deprecated РСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ ProspektwebCalcIntegration
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
                // РћР±СЂР°Р±РѕС‚РєР° СЃРµС‚РµРІС‹С… РѕС€РёР±РѕРє
                self.sendToIframe({
                    type: 'BITRIX_CONFIG_ERROR',
                    payload: 'Network error: ' + (error || 'Unknown error')
                });
            }
        );
    },

    /**
     * РџСЂРѕРєСЃРёСЂРѕРІР°РЅРёРµ API Р·Р°РїСЂРѕСЃРѕРІ
     * @deprecated РСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ ProspektwebCalcIntegration
     */
    proxyApiRequest: function(request) {
        var self = this;
        
        // Р’Р°Р»РёРґР°С†РёСЏ РІС…РѕРґРЅС‹С… РґР°РЅРЅС‹С…
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
        
        // Р’Р°Р»РёРґР°С†РёСЏ HTTP РјРµС‚РѕРґР°
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
        
        // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ endpoint РІ Р±РµР»РѕРј СЃРїРёСЃРєРµ
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
        
        // РЎРѕР·РґР°С‘Рј РѕР±СЉРµРєС‚ РґР°РЅРЅС‹С… РІСЂСѓС‡РЅСѓСЋ РґР»СЏ РїРѕРґРґРµСЂР¶РєРё СЃС‚Р°СЂС‹С… Р±СЂР°СѓР·РµСЂРѕРІ
        // Р’РђР–РќРћ: sessid РґРѕР±Р°РІР»СЏРµС‚СЃСЏ РїРѕСЃР»РµРґРЅРёРј, С‡С‚РѕР±С‹ РїСЂРµРґРѕС‚РІСЂР°С‚РёС‚СЊ РїРµСЂРµРѕРїСЂРµРґРµР»РµРЅРёРµ
        var data = {};
        if (request.data) {
            for (var key in request.data) {
                if (request.data.hasOwnProperty(key) && key !== 'sessid') {
                    data[key] = request.data[key];
                }
            }
        }
        // Р”РѕР±Р°РІР»СЏРµРј sessid РІ РєРѕРЅС†Рµ, С‡С‚РѕР±С‹ РѕРЅ РЅРµ РјРѕРі Р±С‹С‚СЊ РїРµСЂРµРѕРїСЂРµРґРµР»С‘РЅ
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
                option.textContent = 'Р”РѕР±Р°РІРёС‚СЊ РЅР°С†РµРЅРєСѓ';
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
            this.showMessage('РќРµ РІС‹Р±СЂР°РЅС‹ С‚РѕСЂРіРѕРІС‹Рµ РїСЂРµРґР»РѕР¶РµРЅРёСЏ');
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
                    self.showMessage('РќРµ СѓРґР°Р»РѕСЃСЊ Р·Р°РіСЂСѓР·РёС‚СЊ РЅР°СЃС‚СЂРѕР№РєРё РЅР°С†РµРЅРѕРє', 'РћС€РёР±РєР° РЅР°С†РµРЅРєРё');
                    return;
                }

                self.showMarkupPopup(response.data, offers);
            },
            onfailure: function() {
                self.showMessage('РћС€РёР±РєР° Р·Р°РїСЂРѕСЃР° РЅР°СЃС‚СЂРѕРµРє РЅР°С†РµРЅРѕРє', 'РћС€РёР±РєР° РЅР°С†РµРЅРєРё');
            }
        });
    },

    showMarkupPopup: function(data, offers) {
        var self = this;
        var priceTypes = Array.isArray(data.priceTypes) ? data.priceTypes : [];
        var settings = data.settings || {};
        var rates = settings.rates || {};
        var basePriceTypeId = parseInt(settings.basePriceTypeId || 0, 10);
        var rounding = parseFloat(settings.rounding || 1);
        var roundingOptions = [0.1, 0.5, 1, 5, 10, 50, 100];

        if (!priceTypes.length) {
            this.showMessage('РўРёРїС‹ С†РµРЅ РЅРµ РЅР°Р№РґРµРЅС‹', 'РќР°СЃС‚СЂРѕР№РєР° РЅР°С†РµРЅРєРё');
            return;
        }

        var roundingOptionsHtml = '';
        for (var roundingIndex = 0; roundingIndex < roundingOptions.length; roundingIndex++) {
            var roundingValue = roundingOptions[roundingIndex];
            var roundingSelected = Math.abs(rounding - roundingValue) < 0.001 ? 'selected' : '';
            roundingOptionsHtml += '<option value="' + roundingValue + '" ' + roundingSelected + '>' + roundingValue + '</option>';
        }

        var html = '<div style="padding:12px;max-height:520px;overflow:auto;">' +
            '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:12px;">' +
                '<div style="color:#666;">Р’С‹Р±СЂР°РЅРѕ РўРџ: ' + offers.length + '</div>' +
                '<label style="display:flex;align-items:center;gap:8px;">' +
                    '<span>РЁР°Рі РѕРєСЂСѓРіР»РµРЅРёСЏ РІРІРµСЂС…</span>' +
                    '<select data-role="pw-markup-rounding" style="min-width:90px;">' + roundingOptionsHtml + '</select>' +
                '</label>' +
            '</div>' +
            '<table class="adm-list-table" style="width:100%;">' +
                '<thead><tr class="adm-list-table-header">' +
                    '<td>РўРёРї С†РµРЅС‹</td><td style="width:210px;">РЎС‚Р°СЂС‚РѕРІР°СЏ С†РµРЅР°</td><td style="width:210px;">РќР°С†РµРЅРєР°, %</td>' +
                '</tr></thead><tbody>';

        for (var i = 0; i < priceTypes.length; i++) {
            var pt = priceTypes[i];
            var id = parseInt(pt.id, 10);
            var checked = basePriceTypeId === id ? 'checked' : '';
            var rate = rates[id] !== undefined ? rates[id] : 0;

            html += '<tr>' +
                '<td>' + BX.util.htmlspecialchars(pt.name || ('ID ' + id)) + ' [' + id + ']</td>' +
                '<td><label><input type="radio" name="pw-markup-base" value="' + id + '" ' + checked + '> Р‘Р°Р·РѕРІС‹Р№ С‚РёРї</label></td>' +
                '<td><input type="number" data-role="pw-markup-rate" data-price-type-id="' + id + '" value="' + rate + '" step="0.01" style="width:120px;"> %</td>' +
            '</tr>';
        }

        html += '</tbody></table></div>';

        var container = document.createElement('div');
        container.innerHTML = html;

        var popup = new BX.CAdminDialog({
            title: 'Р”РѕР±Р°РІРёС‚СЊ РЅР°С†РµРЅРєСѓ',
            content: container,
            width: 920,
            height: 620,
            resizable: true,
            buttons: [
                '<input type="button" class="adm-btn-save" value="Р—Р°РїСѓСЃС‚РёС‚СЊ" id="pw-markup-run">',
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
                    self.showMessage('Р’С‹Р±РµСЂРёС‚Рµ СЃС‚Р°СЂС‚РѕРІС‹Р№ С‚РёРї С†РµРЅС‹', 'РќР°СЃС‚СЂРѕР№РєР° РЅР°С†РµРЅРєРё');
                    return;
                }

                var requestRates = {};
                container.querySelectorAll('[data-role="pw-markup-rate"]').forEach(function(input) {
                    requestRates[input.dataset.priceTypeId] = input.value || '0';
                });
                var roundingNode = container.querySelector('[data-role="pw-markup-rounding"]');
                if (!roundingNode) {
                    self.showMessage('РќРµ СѓРґР°Р»РѕСЃСЊ РѕРїСЂРµРґРµР»РёС‚СЊ С€Р°Рі РѕРєСЂСѓРіР»РµРЅРёСЏ', 'РќР°СЃС‚СЂРѕР№РєР° РЅР°С†РµРЅРєРё');
                    return;
                }

                BX.ajax({
                    method: 'POST',
                    dataType: 'json',
                    url: '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
                    data: {
                        sessid: BX.bitrix_sessid(),
                        action: 'applyMarkups',
                        offerIds: offers.map(function(o) { return o.id; }).join(','),
                        basePriceTypeId: parseInt(baseNode.value, 10),
                        rates: JSON.stringify(requestRates),
                        rounding: roundingNode.value
                    },
                    onsuccess: function(response) {
                        if (!response || !response.success) {
                            self.showMessage('РћС€РёР±РєР° Р·Р°РїСѓСЃРєР° РЅР°С†РµРЅРєРё: ' + ((response && response.message) || 'РЅРµРёР·РІРµСЃС‚РЅР°СЏ РѕС€РёР±РєР°'), 'РћС€РёР±РєР° РЅР°С†РµРЅРєРё');
                            return;
                        }

                        popup.Close();
                        self.showMessage('Р“РѕС‚РѕРІРѕ. РћР±РЅРѕРІР»РµРЅРѕ РўРџ: ' + (response.data && response.data.updated ? response.data.updated : 0), 'РќР°С†РµРЅРєР° РїСЂРёРјРµРЅРµРЅР°');
                    },
                    onfailure: function() {
                        self.showMessage('РћС€РёР±РєР° Р·Р°РїСЂРѕСЃР° Р·Р°РїСѓСЃРєР° РЅР°С†РµРЅРєРё', 'РћС€РёР±РєР° РЅР°С†РµРЅРєРё');
                    }
                });
            };
        }, 0);
    },

    /**
     * РџРѕР»СѓС‡РµРЅРёРµ ID С‚РѕРІР°СЂР° РёР· URL
     */
    getProductId: function() {
        var match = window.location.search.match(/ID=(\d+)/);
        return match ? parseInt(match[1], 10) : null;
    },

    /**
     * РџРѕР»СѓС‡РµРЅРёРµ ID РёРЅС„РѕР±Р»РѕРєР° РёР· URL
     */
    getIblockId: function() {
        var match = window.location.search.match(/IBLOCK_ID=(\d+)/);
        return match ? parseInt(match[1], 10) : null;
    }
};

// Р­РєСЃРїРѕСЂС‚
if (typeof window !== 'undefined') {
    window.ProspekwebCalc = ProspekwebCalc;
}
