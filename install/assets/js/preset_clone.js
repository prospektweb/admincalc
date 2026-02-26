(function(window) {
    'use strict';

    var PresetClone = {
        observer: null,
        initialized: false,

        init: function() {
            if (this.initialized) {
                return;
            }

            if (!this.isPresetEditPage()) {
                return;
            }

            this.initialized = true;
            this.observeMenus();
            this.attachToMenus(document);
        },

        isPresetEditPage: function() {
            var path = window.location.pathname || '';
            if (path.indexOf('/bitrix/admin/iblock_element_edit.php') === -1 && path.indexOf('/bitrix/admin/iblock_subelement_edit.php') === -1) {
                return false;
            }

            var query = new URLSearchParams(window.location.search);
            var iblockId = parseInt(query.get('IBLOCK_ID') || query.get('iblock_id') || '0', 10);
            var elementId = parseInt(query.get('ID') || query.get('id') || '0', 10);
            var presetIblockId = parseInt(window.PROSPEKTWEB_CALC_PRESET_IBLOCK_ID || 0, 10);

            return presetIblockId > 0 && iblockId === presetIblockId && elementId > 0;
        },

        observeMenus: function() {
            var self = this;

            if (this.observer) {
                return;
            }

            this.observer = new MutationObserver(function() {
                self.attachToMenus(document);
            });

            this.observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        },

        attachToMenus: function(root) {
            var self = this;
            var menus = root.querySelectorAll('.bx-core-popup-menu');

            menus.forEach(function(menu) {
                if (menu.dataset.pwCalcPresetCloneAttached === 'Y') {
                    return;
                }

                var hasElementAction = menu.querySelector('a[href*="iblock_element_edit.php"], a[href*="action=delete"]');
                if (!hasElementAction) {
                    return;
                }

                var item = document.createElement('a');
                item.href = 'javascript:void(0)';
                item.className = 'bx-core-popup-menu-item';
                item.innerHTML = '<span class="bx-core-popup-menu-item-icon"></span>' +
                    '<span class="bx-core-popup-menu-item-text">Клонировать пресет</span>';

                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    if (typeof e.stopImmediatePropagation === 'function') {
                        e.stopImmediatePropagation();
                    }

                    self.clonePreset();
                });

                menu.appendChild(item);
                menu.dataset.pwCalcPresetCloneAttached = 'Y';
            });
        },

        clonePreset: function() {
            var query = new URLSearchParams(window.location.search);
            var presetId = parseInt(query.get('ID') || query.get('id') || '0', 10);
            if (!presetId) {
                alert('Не удалось определить ID пресета');
                return;
            }

            if (!window.confirm('Создать копию текущего пресета?')) {
                return;
            }

            var sessid = (window.BX && typeof window.BX.bitrix_sessid === 'function')
                ? window.BX.bitrix_sessid()
                : '';

            var body = new URLSearchParams();
            body.set('action', 'clonePreset');
            body.set('presetId', String(presetId));
            body.set('sessid', sessid);

            fetch('/bitrix/tools/prospektweb.calc/calculator_ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data || data.success !== true || !data.data || !data.data.newPresetId) {
                        throw new Error((data && (data.message || data.error)) || 'Не удалось клонировать пресет');
                    }

                    var newPresetId = parseInt(data.data.newPresetId, 10);
                    if (!newPresetId) {
                        throw new Error('Некорректный ID нового пресета');
                    }

                    var redirectUrl = new URL(window.location.href);
                    redirectUrl.searchParams.set('ID', String(newPresetId));
                    redirectUrl.searchParams.set('id', String(newPresetId));
                    window.location.href = redirectUrl.toString();
                })
                .catch(function(error) {
                    alert('Ошибка клонирования пресета: ' + error.message);
                });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            PresetClone.init();
        });
    } else {
        PresetClone.init();
    }
})(window);
