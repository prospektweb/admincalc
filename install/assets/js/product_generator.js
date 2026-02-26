(function(window) {
    'use strict';

    var ProductGenerator = {
        endpoint: '/bitrix/tools/prospektweb.calc/product_generator.php',
        menuObserver: null,
        isInitialized: false,

        init: function() {
            if (this.isInitialized) {
                return;
            }

            if (!this.isIblockListPage()) {
                return;
            }

            this.isInitialized = true;
            this.observeMenus();
            this.injectMenuItem(document);
        },

        isIblockListPage: function() {
            return window.location.pathname.indexOf('/bitrix/admin/iblock_list_admin.php') !== -1;
        },

        observeMenus: function() {
            var self = this;

            if (this.menuObserver) {
                return;
            }

            this.menuObserver = new MutationObserver(function() {
                self.injectMenuItem(document);
            });

            this.menuObserver.observe(document.body, {
                childList: true,
                subtree: true
            });
        },

        injectMenuItem: function(root) {
            var self = this;
            var menus = root.querySelectorAll('.bx-core-popup-menu');

            menus.forEach(function(menu) {
                if (menu.dataset.calcGeneratorAttached === 'Y') {
                    return;
                }

                var hasProductCreateItem = menu.querySelector('a[href*="iblock_element_edit.php"]');
                if (!hasProductCreateItem) {
                    return;
                }

                var item = document.createElement('a');
                item.href = 'javascript:void(0)';
                item.className = 'bx-core-popup-menu-item';
                item.innerHTML = '<span class="bx-core-popup-menu-item-icon"></span>' +
                    '<span class="bx-core-popup-menu-item-text">Генератор товаров</span>';
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    if (typeof e.stopImmediatePropagation === 'function') {
                        e.stopImmediatePropagation();
                    }

                    // Даем штатному popup-меню Bitrix завершить цикл клика,
                    // после чего открываем независимый модальный диалог.
                    setTimeout(function() {
                        self.openDialog();
                    }, 0);
                });

                menu.appendChild(item);
                menu.dataset.calcGeneratorAttached = 'Y';
            });
        },

        openDialog: function() {
            var self = this;
            var context = this.getContext();

            var content = document.createElement('div');
            content.className = 'pw-calc-generator';
            content.innerHTML = this.getDialogMarkup();

            var dialog = new BX.CAdminDialog({
                title: 'Генератор товаров',
                content: content,
                width: 1200,
                height: 720,
                draggable: true,
                resizable: true
            });

            dialog.Show();

            // Защита от автозакрытия при глобальных кликах Bitrix popup-меню.
            var canCloseDialog = false;
            var originalClose = dialog.Close.bind(dialog);
            var requestClose = function() {
                canCloseDialog = true;
                originalClose();
            };

            dialog.Close = function() {
                if (!canCloseDialog) {
                    return;
                }

                originalClose();
            };

            if (dialog.PARTS && dialog.PARTS.CLOSE_BTN) {
                BX.bind(dialog.PARTS.CLOSE_BTN, 'click', function(e) {
                    if (e && e.preventDefault) {
                        e.preventDefault();
                    }

                    requestClose();
                });
            }

            var state = {
                dialog: dialog,
                context: context,
                properties: []
            };

            content.querySelector('[data-role="refresh"]').addEventListener('click', function() {
                self.loadProperties(state, content);
            });

            content.querySelector('[data-role="count"]').addEventListener('click', function() {
                self.calculateCombinations(content);
            });

            content.querySelector('[data-role="generate"]').addEventListener('click', function() {
                self.generateProducts(state, content);
            });

            this.loadProperties(state, content);
        },

        getContext: function() {
            var query = new URLSearchParams(window.location.search);

            return {
                iblockId: parseInt(query.get('IBLOCK_ID') || query.get('iblock_id') || '0', 10),
                sectionId: parseInt(query.get('find_section_section') || query.get('IBLOCK_SECTION_ID') || '0', 10)
            };
        },

        getDialogMarkup: function() {
            return '' +
                '<style>' +
                '.pw-calc-generator{padding:16px;font:14px/1.4 Arial,sans-serif;color:#1f2937}' +
                '.pw-calc-generator__toolbar{display:flex;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap}' +
                '.pw-calc-generator__input{min-width:360px;padding:8px 10px;border:1px solid #c9d0d6;border-radius:4px}' +
                '.pw-calc-generator__hint{font-size:12px;color:#6b7280}' +
                '.pw-calc-generator__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px;max-height:430px;overflow:auto}' +
                '.pw-calc-generator__card{border:1px solid #d5dce1;border-radius:6px;background:#fff}' +
                '.pw-calc-generator__card-head{padding:8px 10px;background:#f5f8fa;border-bottom:1px solid #e5eaef;font-weight:600;display:flex;justify-content:space-between}' +
                '.pw-calc-generator__list{max-height:260px;overflow:auto;padding:8px 10px;display:flex;flex-direction:column;gap:6px}' +
                '.pw-calc-generator__value{display:flex;align-items:center;gap:8px}' +
                '.pw-calc-generator__footer{margin-top:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}' +
                '.pw-calc-generator__status{font-size:13px;color:#374151}' +
                '.pw-calc-generator__warn{color:#b45309}' +
                '.pw-calc-generator__error{color:#b91c1c}' +
                '</style>' +
                '<div class="pw-calc-generator__toolbar">' +
                '<label>Базовое название: <input class="pw-calc-generator__input" data-role="base-name" placeholder="Например: Методическое пособие"></label>' +
                '</div>' +
                '<div class="pw-calc-generator__toolbar">' +
                '<label>Шаблон названия: <input class="pw-calc-generator__input" data-role="template" value="{#BASE_NAME#} {CALC_FORMAT} {CALC_BINDING}" placeholder="Текст + {CODE}"></label>' +
                '</div>' +
                '<div class="pw-calc-generator__hint">Используйте плейсхолдеры вида {CODE} для подстановки значений выбранных свойств. Также доступен {#BASE_NAME#}.</div>' +
                '<div class="pw-calc-generator__toolbar" style="margin-top:12px">' +
                '<button class="adm-btn" data-role="refresh">Обновить свойства</button>' +
                '<button class="adm-btn" data-role="count">Посчитать комбинации</button>' +
                '<span class="pw-calc-generator__status" data-role="counter">Комбинации: —</span>' +
                '</div>' +
                '<div class="pw-calc-generator__grid" data-role="properties"></div>' +
                '<div class="pw-calc-generator__footer">' +
                '<button class="adm-btn adm-btn-save" data-role="generate">Создать товары</button>' +
                '<span class="pw-calc-generator__status" data-role="status"></span>' +
                '</div>';
        },

        loadProperties: function(state, content) {
            var self = this;
            var propsWrap = content.querySelector('[data-role="properties"]');
            var status = content.querySelector('[data-role="status"]');

            propsWrap.innerHTML = '';
            status.textContent = 'Загрузка свойств...';
            status.className = 'pw-calc-generator__status';

            BX.ajax({
                method: 'POST',
                dataType: 'json',
                url: this.endpoint,
                data: {
                    sessid: BX.bitrix_sessid(),
                    action: 'get_config',
                    iblock_id: state.context.iblockId,
                    section_id: state.context.sectionId
                },
                onsuccess: function(response) {
                    if (!response || response.error) {
                        status.textContent = 'Ошибка загрузки свойств.';
                        status.className = 'pw-calc-generator__status pw-calc-generator__error';
                        return;
                    }

                    state.properties = response.properties || [];
                    self.renderProperties(state.properties, propsWrap);

                    var unsupported = state.properties.filter(function(p) { return !p.isSupported; });
                    if (unsupported.length > 0) {
                        status.textContent = 'Некоторые свойства пропущены (поддерживаются только типы L и E): ' + unsupported.map(function(p){return p.code;}).join(', ');
                        status.className = 'pw-calc-generator__status pw-calc-generator__warn';
                    } else {
                        status.textContent = 'Свойства загружены: ' + state.properties.length;
                        status.className = 'pw-calc-generator__status';
                    }
                },
                onfailure: function() {
                    status.textContent = 'Ошибка запроса к серверу.';
                    status.className = 'pw-calc-generator__status pw-calc-generator__error';
                }
            });
        },

        renderProperties: function(properties, container) {
            container.innerHTML = '';

            var supported = properties.filter(function(property) {
                return property.isSupported && Array.isArray(property.values) && property.values.length > 0;
            });

            if (supported.length === 0) {
                container.innerHTML = '<div class="pw-calc-generator__status pw-calc-generator__warn">Не найдено CALC_* свойств с возможными значениями.</div>';
                return;
            }

            supported.forEach(function(property) {
                var card = document.createElement('div');
                card.className = 'pw-calc-generator__card';
                card.dataset.propertyId = property.id;
                card.dataset.propertyCode = property.code;

                var head = document.createElement('div');
                head.className = 'pw-calc-generator__card-head';
                head.innerHTML = '<span>' + BX.util.htmlspecialchars(property.name) + ' (' + BX.util.htmlspecialchars(property.code) + ')</span>' +
                    '<label><input type="checkbox" data-role="select-all"> Все</label>';
                card.appendChild(head);

                var list = document.createElement('div');
                list.className = 'pw-calc-generator__list';

                property.values.forEach(function(value) {
                    var row = document.createElement('label');
                    row.className = 'pw-calc-generator__value';
                    row.innerHTML = '<input type="checkbox" data-role="value" value="' + value.id + '">' +
                        '<span>' + BX.util.htmlspecialchars(value.value || ('#' + value.id)) + '</span>';
                    list.appendChild(row);
                });

                head.querySelector('[data-role="select-all"]').addEventListener('change', function(e) {
                    list.querySelectorAll('[data-role="value"]').forEach(function(checkbox) {
                        checkbox.checked = e.target.checked;
                    });
                });

                card.appendChild(list);
                container.appendChild(card);
            });
        },

        collectSelected: function(content) {
            var selected = {};

            content.querySelectorAll('.pw-calc-generator__card').forEach(function(card) {
                var propertyId = card.dataset.propertyId;
                var values = [];

                card.querySelectorAll('[data-role="value"]:checked').forEach(function(checkbox) {
                    values.push(parseInt(checkbox.value, 10));
                });

                if (values.length > 0) {
                    selected[propertyId] = values;
                }
            });

            return selected;
        },

        calculateCombinations: function(content) {
            var selected = this.collectSelected(content);
            var counts = Object.keys(selected).map(function(propertyId) {
                return selected[propertyId].length;
            });
            var total = counts.reduce(function(acc, count) {
                return acc * count;
            }, counts.length > 0 ? 1 : 0);

            content.querySelector('[data-role="counter"]').textContent = 'Комбинации: ' + total;
        },

        generateProducts: function(state, content) {
            var self = this;
            var selected = this.collectSelected(content);
            var template = (content.querySelector('[data-role="template"]').value || '').trim();
            var baseName = (content.querySelector('[data-role="base-name"]').value || '').trim();
            var status = content.querySelector('[data-role="status"]');

            if (!template) {
                status.textContent = 'Заполните шаблон названия.';
                status.className = 'pw-calc-generator__status pw-calc-generator__error';
                return;
            }

            if (Object.keys(selected).length === 0) {
                status.textContent = 'Выберите хотя бы одно значение свойств.';
                status.className = 'pw-calc-generator__status pw-calc-generator__error';
                return;
            }

            self.calculateCombinations(content);
            var counterText = content.querySelector('[data-role="counter"]').textContent;
            if (!window.confirm(counterText + '\nСоздать товары?')) {
                return;
            }

            status.textContent = 'Создание товаров...';
            status.className = 'pw-calc-generator__status';

            BX.ajax({
                method: 'POST',
                dataType: 'json',
                url: this.endpoint,
                data: {
                    sessid: BX.bitrix_sessid(),
                    action: 'generate',
                    iblock_id: state.context.iblockId,
                    section_id: state.context.sectionId,
                    name_template: template,
                    base_name: baseName,
                    selected: selected
                },
                onsuccess: function(response) {
                    if (!response || response.error) {
                        var msg = response && response.message ? response.message : 'Ошибка создания товаров.';
                        status.textContent = msg;
                        status.className = 'pw-calc-generator__status pw-calc-generator__error';
                        return;
                    }

                    status.textContent = 'Готово. Создано: ' + response.createdCount + ' из ' + response.combinationCount + '.';
                    status.className = 'pw-calc-generator__status';

                    if (Array.isArray(response.errors) && response.errors.length > 0) {
                        status.textContent += ' Ошибки: ' + response.errors.slice(0, 3).join(' | ');
                        status.className = 'pw-calc-generator__status pw-calc-generator__warn';
                    }

                    setTimeout(function() {
                        window.location.reload();
                    }, 1200);
                },
                onfailure: function() {
                    status.textContent = 'Сервер недоступен.';
                    status.className = 'pw-calc-generator__status pw-calc-generator__error';
                }
            });
        }
    };

    window.ProspektwebProductGenerator = ProductGenerator;

    BX.ready(function() {
        ProductGenerator.init();
    });
})(window);
