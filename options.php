<?php
/**
 * Страница настроек модуля prospektweb.calc
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;

Loc::loadMessages(__FILE__);

$module_id = 'prospektweb.calc';

if (!Loader::includeModule($module_id)) {
    ShowError(Loc::getMessage('PROSPEKTWEB_CALC_MODULE_NOT_INSTALLED'));
    return;
}

use Prospektweb\Calc\Install\SnapshotManager;
use Prospektweb\Calc\Services\ControlCenterSettingsService;

global $USER, $APPLICATION;

// Проверка прав доступа
if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}


$controlCenterSettingsService = new ControlCenterSettingsService();

// Экспорт snapshot текущих данных
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && isset($_POST['EXPORT_SNAPSHOT'])) {
    try {
        $snapshotManager = new SnapshotManager();
        $snapshotFile = $snapshotManager->exportToFile();
        $downloadName = 'prospektweb_snapshot_' . date('Ymd_His') . '.json';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string)filesize($snapshotFile));
        readfile($snapshotFile);
        @unlink($snapshotFile);
        die();
    } catch (\Throwable $e) {
        ShowError('Ошибка экспорта snapshot: ' . $e->getMessage());
    }
}

$controlCenterSettings = $controlCenterSettingsService->getSettings();

$asproAiPatchStatus = (array)($controlCenterSettings['integration']['patchStatus'] ?? []);

$APPLICATION->SetTitle(Loc::getMessage('PROSPEKTWEB_CALC_OPTIONS_TITLE'));
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

// Создаём вкладки
$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'edit1', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_MAIN'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_MAIN_TITLE')],
    ['DIV' => 'edit2', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_SERVICE'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_SERVICE_TITLE')],
    ['DIV' => 'edit3', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_IBLOCKS'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_IBLOCKS_TITLE')],
    ['DIV' => 'edit4', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_INTEGRATION'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_INTEGRATION_TITLE')],
    ['DIV' => 'edit5', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_DIAGNOSTIC'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_DIAGNOSTIC_TITLE')],
]);

$tabControl->Begin();

?>
<style>
    .pwcalc-field-hint {
        color: #777;
        font-size: 11px;
        line-height: 1.45;
        margin-top: 5px;
        max-width: 760px;
    }

    .pwcalc-patch-status {
        background: #fff;
        border: 1px solid #d5d9de;
        border-radius: 4px;
        max-width: 760px;
        padding: 10px 12px;
    }
</style>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>

    <?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td colspan="2">
            <div class="adm-info-message">
                Расчётная модель, формы, сопоставления, публикация и настройки цен управляются только
                через «Центр управления». Эта системная страница больше не содержит параллельной формы
                записи и не выполняет разрушительные очистки данных.
            </div>
        </td>
    </tr>

    <?php $tabControl->BeginNextTab(); ?>

    <tr class="heading">
        <td colspan="2"><?= Loc::getMessage('PROSPEKTWEB_CALC_SNAPSHOT_HEADING') ?></td>
    </tr>

    <tr>
        <td width="40%"><?= Loc::getMessage('PROSPEKTWEB_CALC_SNAPSHOT_LABEL') ?></td>
        <td>
            <button type="submit" name="EXPORT_SNAPSHOT" value="Y" class="adm-btn"><?= Loc::getMessage('PROSPEKTWEB_CALC_SNAPSHOT_BUTTON') ?></button>
            <div class="pwcalc-field-hint"><?= Loc::getMessage('PROSPEKTWEB_CALC_SNAPSHOT_HINT') ?></div>
        </td>
    </tr>

    <?php $tabControl->BeginNextTab(); ?>

    <?php
    $iblockCodes = [
        'CALC_PRESETS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_PRESETS'),
        'CALC_STAGES' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_CALC_STAGES'),
        'CALC_SETTINGS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_CALC_SETTINGS'),
        'CALC_MATERIALS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_MATERIALS'),
        'CALC_MATERIALS_VARIANTS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_MATERIALS_VARIANTS'),
        'CALC_OPERATIONS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_OPERATIONS'),
        'CALC_OPERATIONS_VARIANTS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_OPERATIONS_VARIANTS'),
        'CALC_EQUIPMENT' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_EQUIPMENT'),
        'CALC_DETAILS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_DETAILS'),
    ];

    foreach ($iblockCodes as $code => $label):
        $iblockId = (int)Option::get($module_id, 'IBLOCK_' . $code, 0);
    ?>
    <tr>
        <td width="40%"><?= htmlspecialcharsbx($label) ?></td>
        <td width="60%">
            <?php if ($iblockId > 0): ?>
                <a href="/bitrix/admin/iblock_list_admin.php?IBLOCK_ID=<?= $iblockId ?>&type=calculator&lang=<?= LANGUAGE_ID ?>">
                    ID: <?= $iblockId ?>
                </a>
            <?php else: ?>
                <span style="color: #999;"><?= Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_NOT_CREATED') ?></span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>

    <?php $tabControl->BeginNextTab(); ?>

    <tr class="heading">
        <td colspan="2"><?= Loc::getMessage('PROSPEKTWEB_CALC_CALC_SERVER_HEADING') ?></td>
    </tr>

    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_CALC_SERVER_URL') ?></td>
        <td>
            <code><?= htmlspecialcharsbx((string)$controlCenterSettings['integration']['calcServerUrl']) ?></code>
            <br><span style="color: #777; font-size: 11px;"><?= Loc::getMessage('PROSPEKTWEB_CALC_CALC_SERVER_URL_HINT') ?></span>
        </td>
    </tr>

    <tr class="heading">
        <td colspan="2">Timeweb Cloud AI Gateway для «Аспро: AI»</td>
    </tr>

    <tr>
        <td width="40%">Использовать Timeweb Cloud AI Gateway</td>
        <td width="60%">
            <code><?= !empty($controlCenterSettings['integration']['asproAiEnabled']) ? 'включено' : 'выключено' ?></code>
        </td>
    </tr>

    <tr>
        <td>Base URL</td>
        <td>
            <code><?= htmlspecialcharsbx((string)$controlCenterSettings['integration']['asproAiBaseUrl']) ?></code>
            <br><span style="color:#777;font-size:11px;">Изменяется только через транзакционные настройки Центра управления.</span>
        </td>
    </tr>

    <tr>
        <td>Состояние управляемого патча</td>
        <td>
            <div class="pwcalc-patch-status">
                <div style="font-weight:600;margin-bottom:6px;"><?= htmlspecialcharsbx((string)$asproAiPatchStatus['message']) ?></div>
                <div style="color:#666;font-size:12px;line-height:1.5;">
                    Состояние: <code><?= htmlspecialcharsbx((string)$asproAiPatchStatus['state']) ?></code>;
                    «Аспро: AI»: <code><?= htmlspecialcharsbx((string)($asproAiPatchStatus['asproVersion'] ?: 'не определена')) ?></code>;
                    патч: <code><?= htmlspecialcharsbx((string)$asproAiPatchStatus['patchVersion']) ?></code>.
                </div>
            </div>
            <div style="max-width:760px;margin:10px 0;color:#777;font-size:12px;line-height:1.5;">
                Управляемый патч обслуживается только офлайн-деплоем с резервной копией и проверкой целостности.
                Эта страница показывает состояние и не изменяет файлы или настройки.
            </div>
        </td>
    </tr>

    <?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td colspan="2" style="padding: 16px;">
            <div style="margin-bottom: 16px;">
                <button type="button" id="btn-run-diagnostic" class="adm-btn adm-btn-save" onclick="pwCalcDiagRun()">
                    🔍 Запустить диагностику
                </button>
                &nbsp;
                <button type="button" id="btn-fix-events" class="adm-btn" onclick="pwCalcDiagFix('activate_assignment_guard', 'Проверить и восстановить обязательные обработчики защиты CALC_PRESET?')">
                    🔧 Проверить защиту назначений
                </button>
            </div>
            <div id="pwcalc-diag-loading" style="display:none; margin-bottom: 12px;">
                <img src="/bitrix/images/main/wait.gif" alt="Загрузка..."> Выполняется диагностика...
            </div>
            <div id="pwcalc-diag-results"></div>
        </td>
    </tr>

    <script>
    (function() {
        var diagUrl = '/bitrix/tools/prospektweb.calc/diagnostic.php';
        var diagSessid = '<?= bitrix_sessid() ?>';
        function pwCalcDiagRun() {
            var loading = document.getElementById('pwcalc-diag-loading');
            var results = document.getElementById('pwcalc-diag-results');
            loading.style.display = 'block';
            results.innerHTML = '';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', diagUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                loading.style.display = 'none';
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success && data.data) {
                        renderDiagnosticResults(results, data.data);
                    } else {
                        results.innerHTML = '<div style="color:red; padding:8px; border:1px solid #f88; background:#fff5f5;">Ошибка: ' + (data.error || 'Неизвестная ошибка') + '</div>';
                    }
                } catch(e) {
                    results.innerHTML = '<div style="color:red; padding:8px;">Ошибка разбора ответа: ' + e.message + '</div>';
                }
            };
            xhr.onerror = function() {
                loading.style.display = 'none';
                results.innerHTML = '<div style="color:red; padding:8px;">Сетевая ошибка при выполнении запроса</div>';
            };
            xhr.send('sessid=' + encodeURIComponent(diagSessid) + '&action=run');
        }

        function pwCalcDiagFix(action, confirmText) {
            if (!confirm(confirmText)) {
                return;
            }
            var loading = document.getElementById('pwcalc-diag-loading');
            var results = document.getElementById('pwcalc-diag-results');
            loading.style.display = 'block';
            results.innerHTML = '';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', diagUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                loading.style.display = 'none';
                try {
                    var data = JSON.parse(xhr.responseText);
                    var msgDiv = document.createElement('div');
                    msgDiv.style.cssText = 'padding:8px; margin-bottom:12px; border:1px solid ' + (data.success ? '#8bc34a' : '#f88') + '; background:' + (data.success ? '#f1f8e9' : '#fff5f5') + '; border-radius:4px;';
                    msgDiv.textContent = data.message || (data.success ? 'Успешно выполнено' : ('Ошибка: ' + (data.error || 'Неизвестная ошибка')));
                    results.appendChild(msgDiv);
                    if (data.success) {
                        pwCalcDiagRun();
                    }
                } catch(e) {
                    results.innerHTML = '<div style="color:red; padding:8px;">Ошибка разбора ответа: ' + e.message + '</div>';
                }
            };
            xhr.onerror = function() {
                loading.style.display = 'none';
                results.innerHTML = '<div style="color:red; padding:8px;">Сетевая ошибка при выполнении запроса</div>';
            };
            xhr.send('sessid=' + encodeURIComponent(diagSessid) + '&action=' + encodeURIComponent(action));
        }

        function renderDiagnosticResults(container, data) {
            container.innerHTML = '';
            var sections = data.sections || [];

            sections.forEach(function(section) {
                var hasErrors = section.errors && section.errors.length > 0;
                var hasWarnings = section.warnings && section.warnings.length > 0;
                var borderColor = hasErrors ? '#e53935' : (hasWarnings ? '#fb8c00' : '#43a047');
                var statusIcon = hasErrors ? '❌' : (hasWarnings ? '⚠️' : '✅');

                var block = document.createElement('div');
                block.style.cssText = 'border-left:4px solid ' + borderColor + '; margin-bottom:12px; padding:12px 16px; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.08); border-radius:0 4px 4px 0;';

                var title = document.createElement('div');
                title.style.cssText = 'font-weight:bold; font-size:14px; margin-bottom:8px;';
                title.textContent = statusIcon + ' ' + section.icon + ' ' + section.name;
                block.appendChild(title);

                if (section.checks && section.checks.length > 0) {
                    var table = document.createElement('table');
                    table.style.cssText = 'width:100%; border-collapse:collapse; font-size:12px;';

                    section.checks.forEach(function(check) {
                        var tr = document.createElement('tr');
                        var icon = check.status === 'ok' ? '✅' : (check.status === 'warning' ? '⚠️' : '❌');
                        var bgColor = check.status === 'ok' ? '#f9fff9' : (check.status === 'warning' ? '#fffdf0' : '#fff5f5');
                        tr.style.cssText = 'background:' + bgColor + ';';

                        var tdIcon = document.createElement('td');
                        tdIcon.style.cssText = 'padding:3px 6px; width:24px; text-align:center;';
                        tdIcon.textContent = icon;

                        var tdLabel = document.createElement('td');
                        tdLabel.style.cssText = 'padding:3px 8px; color:#333; white-space:nowrap;';
                        tdLabel.textContent = check.label;

                        var tdValue = document.createElement('td');
                        tdValue.style.cssText = 'padding:3px 8px; color:#555; width:60%;';
                        tdValue.textContent = check.value;

                        tr.appendChild(tdIcon);
                        tr.appendChild(tdLabel);
                        tr.appendChild(tdValue);
                        table.appendChild(tr);
                    });

                    block.appendChild(table);
                }

                if (section.errors && section.errors.length > 0) {
                    var errList = document.createElement('ul');
                    errList.style.cssText = 'margin:8px 0 0; padding:0 0 0 20px; color:#c62828; font-size:12px;';
                    section.errors.forEach(function(err) {
                        var li = document.createElement('li');
                        li.textContent = err;
                        errList.appendChild(li);
                    });
                    block.appendChild(errList);
                }

                if (section.warnings && section.warnings.length > 0) {
                    var warnList = document.createElement('ul');
                    warnList.style.cssText = 'margin:8px 0 0; padding:0 0 0 20px; color:#e65100; font-size:12px;';
                    section.warnings.forEach(function(warn) {
                        var li = document.createElement('li');
                        li.textContent = warn;
                        warnList.appendChild(li);
                    });
                    block.appendChild(warnList);
                }

                container.appendChild(block);
            });
        }

        // Экспорт в глобальный scope для использования в onclick
        window.pwCalcDiagRun = pwCalcDiagRun;
        window.pwCalcDiagFix = pwCalcDiagFix;
        window.renderDiagnosticResults = renderDiagnosticResults;
    })();
    </script>

    <?php
    $tabControl->Buttons([
        'disabled' => true,
        'back_url' => '/bitrix/admin/settings.php?lang=' . LANGUAGE_ID,
    ]);
    ?>

    <?php $tabControl->End(); ?>
</form>

<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
