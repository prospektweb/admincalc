<?php

namespace Prospektweb\PropValManager\Service;

use Bitrix\Main\Context;

final class AdminPropertySettingsExtension
{
    public const EVENT_MODULE = 'main';
    public const EVENT_NAME = 'OnEndBufferContent';

    public static function onEndBufferContent(&$content): void
    {
        if (!is_string($content) || !defined('ADMIN_SECTION') || ADMIN_SECTION !== true) {
            return;
        }

        global $USER, $APPLICATION;
        if (!is_object($USER) || !$USER->IsAdmin() || !ModuleConfig::isEnabled()) {
            return;
        }

        $request = Context::getCurrent()->getRequest();
        $page = $APPLICATION && is_object($APPLICATION) ? (string)$APPLICATION->GetCurPage() : (string)$request->getRequestedPage();
        if (!in_array(basename($page), ['iblock_edit_property.php', 'iblock_edit.php'], true)) {
            return;
        }

        $settingsUrl = '/bitrix/admin/prospektweb_calc_property_values.php?' . http_build_query([
            'lang' => defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru',
        ]);

        $config = [
            'productsIblockId' => ModuleConfig::getProductsIblockId(),
            'offersIblockId' => ModuleConfig::getOffersIblockId(),
            'settingsUrl' => $settingsUrl,
            'quickBaseUrl' => $settingsUrl . '&pvm_quick=Y',
        ];

        $script = "\n" . '<script data-prospektweb-propvalmanager="iblock-property-values">' . "\n" .
            'window.ProspektPropValManagerConfig = ' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';' . "\n" .
            self::getInlineScript() . "\n" .
            '</script>' . "\n";

        $bodyPos = stripos($content, '</body>');
        if ($bodyPos === false) {
            $content .= $script;
            return;
        }

        $content = substr($content, 0, $bodyPos) . $script . substr($content, $bodyPos);
    }

    private static function getInlineScript(): string
    {
        return <<<'JS'
(function () {
    'use strict';

    var config = window.ProspektPropValManagerConfig || {};
    var managedIblocks = [String(config.productsIblockId || ''), String(config.offersIblockId || '')].filter(Boolean);

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    function getForm() {
        return document.getElementById('frm_prop') || document.querySelector('form[name="frm_prop"]');
    }

    function getFormValue(form, names) {
        for (var i = 0; i < names.length; i += 1) {
            var field = form.querySelector('[name="' + names[i] + '"]');
            if (field && field.value !== '') {
                return field.value;
            }
        }
        return '';
    }

    function getPropertyId(form) {
        var id = getFormValue(form, ['propedit', 'PROPERTY_ID', 'ID']);
        if (id) {
            return id;
        }
        var match = String(form.action || location.href).match(/[?&]propedit=(\d+)/);
        return match ? match[1] : '';
    }

    function getIblockId(form) {
        var id = getFormValue(form, ['PARAMS[IBLOCK_ID]', 'IBLOCK_ID']);
        if (id) {
            return id;
        }
        var match = String(form.action || location.href).match(/[?&](?:IBLOCK_ID|ID)=(\d+)/);
        return match ? match[1] : '';
    }

    function getPropertyCode(form) {
        return getFormValue(form, ['CODE', 'PROPERTY_CODE', 'FIELDS[CODE]']);
    }

    function getInput(row, suffixes) {
        var inputs = row.querySelectorAll('input, textarea, select');
        for (var i = 0; i < inputs.length; i += 1) {
            if (inputs[i].closest('tr') !== row) {
                continue;
            }

            var name = inputs[i].name || '';
            for (var j = 0; j < suffixes.length; j += 1) {
                if (name.indexOf(suffixes[j]) !== -1 || name.match(new RegExp('\\[' + suffixes[j].replace(/[\[\]]/g, '') + '\\]$'))) {
                    return inputs[i];
                }
            }
        }
        return null;
    }

    function getValueId(row) {
        var firstCell = row.cells && row.cells.length ? row.cells[0].textContent.replace(/\s+/g, '') : '';
        if (/^\d+$/.test(firstCell)) {
            return firstCell;
        }
        var input = getInput(row, ['[ID]']);
        return input ? input.value : '';
    }

    function getRowData(form, row) {
        var xmlInput = getInput(row, ['[XML_ID]', 'XML_ID']);
        var valueInput = getInput(row, ['[VALUE]', 'VALUE']);
        return {
            UF_IBLOCK_ID: getIblockId(form),
            UF_PROPERTY_ID: getPropertyId(form),
            UF_PROPERTY_CODE: getPropertyCode(form),
            UF_VALUE_ID: getValueId(row),
            UF_VALUE_XML_ID: xmlInput ? xmlInput.value : '',
            UF_VALUE_NAME: valueInput ? valueInput.value : ''
        };
    }

    function buildQuickUrl(data) {
        var params = [];
        Object.keys(data).forEach(function (key) {
            params.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key] || ''));
        });
        return String(config.quickBaseUrl || config.settingsUrl || '') + '&' + params.join('&');
    }

    function openUrl(url) {
        window.open(url, '_blank');
        return false;
    }

    function isCalcProperty(form) {
        var propertyCode = getPropertyCode(form);
        return propertyCode !== '' && propertyCode.toUpperCase().indexOf('CALC_') !== -1;
    }

    function makeButton(text, title, onClick) {
        var btn = document.createElement('input');
        btn.type = 'button';
        btn.className = 'adm-btn prospekt-pvm-admin-button';
        btn.value = text;
        btn.title = title;
        btn.style.marginRight = '6px';
        btn.onclick = onClick;
        return btn;
    }

    function enhanceForm(form) {
        if (!form || form.getAttribute('data-prospekt-pvm-enhanced') === 'Y') {
            return;
        }

        var iblockId = getIblockId(form);
        if (managedIblocks.length && managedIblocks.indexOf(String(iblockId)) === -1) {
            return;
        }
        if (!isCalcProperty(form)) {
            return;
        }

        form.setAttribute('data-prospekt-pvm-enhanced', 'Y');
        var settingsButton = makeButton('Настройки описаний значений', 'Открыть настройки модуля', function () {
            return openUrl(config.settingsUrl);
        });
        var heading = null;
        Array.prototype.some.call(form.querySelectorAll('tr.heading, .heading'), function (node) {
            if (node.textContent.indexOf('Значения списка') !== -1) {
                heading = node;
                return true;
            }
            return false;
        });
        if (heading && heading.parentNode) {
            var row = document.createElement('tr');
            var cell = document.createElement('td');
            cell.colSpan = 10;
            cell.style.textAlign = 'center';
            cell.appendChild(settingsButton);
            row.appendChild(cell);
            heading.parentNode.insertBefore(row, heading);
        }

        Array.prototype.forEach.call(form.querySelectorAll('tr'), function (row) {
            if (row.getAttribute('data-prospekt-pvm-row') === 'Y') {
                return;
            }
            var data = getRowData(form, row);
            if (!data.UF_PROPERTY_ID || !data.UF_IBLOCK_ID || !data.UF_VALUE_XML_ID || !data.UF_VALUE_NAME) {
                return;
            }
            row.setAttribute('data-prospekt-pvm-row', 'Y');
            var cell = row.cells && row.cells.length ? row.cells[row.cells.length - 1] : null;
            if (!cell) {
                return;
            }
            var btn = makeButton('Описание', 'Заполнить или посмотреть подробности значения свойства', function () {
                return openUrl(buildQuickUrl(getRowData(form, row)));
            });
            cell.insertBefore(btn, cell.firstChild);
        });
    }

    ready(function () {
        var run = function () { enhanceForm(getForm()); };
        run();
        setInterval(run, 1000);
    });
}());
JS;
    }
}
