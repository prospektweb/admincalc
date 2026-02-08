<?php
/**
 * Шаг 1 установки: Выбор инфоблока товаров
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
    echo '<div class="adm-info-message adm-info-message-red">' .
        Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_MODULES_ERROR') .
        '</div>';
    return;
}

// Восстанавливаем выбранные значения из сессии (если есть)
$savedProductIblockId = (int)($_SESSION['PROSPEKTWEB_CALC_STEP1_DATA']['PRODUCT_IBLOCK_ID'] ?? 0);

// Получаем список каталогов
$catalogs = [];
$rsIBlocks = \CIBlock::GetList(
    ['NAME' => 'ASC'],
    ['TYPE' => 'catalog', 'ACTIVE' => 'Y']
);

while ($arIBlock = $rsIBlocks->Fetch()) {
    $catalogInfo = \CCatalogSKU::GetInfoByProductIBlock($arIBlock['ID']);

    $catalogs[] = [
        'ID' => $arIBlock['ID'],
        'NAME' => $arIBlock['NAME'],
        'CODE' => $arIBlock['CODE'],
        'SKU_IBLOCK_ID' => $catalogInfo['IBLOCK_ID'] ?? null,
    ];
}

?>
<style>
.install-console {
    background: #1e1e1e;
    color: #d4d4d4;
    padding: 20px;
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 13px;
    line-height: 1.8;
    border-radius: 4px;
    margin-top: 15px;
    min-height: 100px;
    max-height: 300px;
    overflow-y: auto;
    display: none;
}
.install-console.visible { display: block; }
.install-console .log-success { color: #4ec9b0; }
.install-console .log-info { color: #9cdcfe; }
.install-console .log-warning { color: #dcdcaa; }
.install-console .log-error { color: #f14c4c; }
.install-console .log-header { color: #569cd6; font-weight: bold; }

.install-confirm {
    background: #2d2d2d;
    border: 1px solid #569cd6;
    border-radius: 4px;
    padding: 15px;
    margin-top: 15px;
    display: none;
}
.install-confirm.visible { display: block; }
.install-confirm h4 { color: #569cd6; margin: 0 0 10px 0; }
.install-confirm ul { color: #d4d4d4; margin: 10px 0; padding-left: 20px; }
.install-confirm li { margin: 5px 0; }
.install-confirm .btn-confirm { 
    background: #4ec9b0; 
    color: #1e1e1e; 
    border: none; 
    padding: 8px 20px; 
    cursor: pointer;
    margin-right: 10px;
    border-radius: 3px;
}
.install-confirm .btn-confirm:hover { background: #3db99f; }
.install-confirm .btn-cancel { 
    background: #3c3c3c; 
    color: #d4d4d4; 
    border: 1px solid #555; 
    padding: 8px 20px; 
    cursor: pointer;
    border-radius: 3px;
}
.install-confirm .btn-cancel:hover { background: #4c4c4c; }

.install-step1-info {
    margin-top: 15px;
}
</style>

<form action="<?= $APPLICATION->GetCurPage() ?>" method="post" id="installForm" enctype="multipart/form-data">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="prospektweb.calc">
    <input type="hidden" name="install" value="Y">
    <input type="hidden" name="step" value="2">

    <table class="adm-detail-content-table edit-table">
        <tr class="heading">
            <td colspan="2"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_STEP1_TITLE') ?></td>
        </tr>

        <tr>
            <td width="40%">
                <label for="PRODUCT_IBLOCK_ID"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_PRODUCT_IBLOCK') ?></label>
            </td>
            <td width="60%">
                <select name="PRODUCT_IBLOCK_ID" id="PRODUCT_IBLOCK_ID" class="adm-input" style="width: 300px;">
                    <option value=""><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_SELECT_IBLOCK') ?></option>
                    <?php foreach ($catalogs as $catalog): ?>
                        <option value="<?= (int)$catalog['ID'] ?>" 
                                data-sku-id="<?= (int)($catalog['SKU_IBLOCK_ID'] ?? 0) ?>"
                                data-name="<?= htmlspecialcharsbx($catalog['NAME']) ?>"
                                <?= ($savedProductIblockId === (int)$catalog['ID']) ? 'selected' : '' ?>>
                            <?= htmlspecialcharsbx($catalog['NAME']) ?> [<?= (int)$catalog['ID'] ?>]
                            <?php if ($catalog['SKU_IBLOCK_ID']): ?>
                                (<?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_HAS_SKU') ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>

        <tr>
            <td>
                <label for="IMPORT_SNAPSHOT_FILE">Импорт данных (snapshot)</label>
            </td>
            <td>
                <input type="file" name="IMPORT_SNAPSHOT_FILE" id="IMPORT_SNAPSHOT_FILE" accept=".json,.txt,application/json,text/plain">
                <div style="color:#777;font-size:11px;margin-top:6px;">Необязательно. Если файл не выбран — установка будет выполнена начисто.</div>
            </td>
        </tr>
    </table>

    <div class="adm-info-message install-step1-info">
        <?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_STEP1_INFO') ?>
    </div>

    <!-- Console block for logging -->
    <div id="installConsole" class="install-console"></div>

    <!-- Confirmation block -->
    <div id="installConfirm" class="install-confirm">
        <h4><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_CONFIRM_TITLE') ?></h4>
        <div id="confirmDetails"></div>
        <div style="margin-top: 15px;">
            <button type="button" class="btn-confirm" id="btnConfirmInstall"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_CONFIRM_YES') ?></button>
            <button type="button" class="btn-cancel" id="btnCancelInstall"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_CONFIRM_CANCEL') ?></button>
        </div>
    </div>

    <div style="margin-top: 20px;">
        <input type="button" name="install_next" id="btnNext" value="<?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_NEXT') ?>" class="adm-btn-save" disabled>
    </div>
</form>

<script>
(function() {
    var selectIblock = document.getElementById('PRODUCT_IBLOCK_ID');
    var btnNext = document.getElementById('btnNext');
    var consoleDiv = document.getElementById('installConsole');
    var confirmDiv = document.getElementById('installConfirm');
    var confirmDetails = document.getElementById('confirmDetails');
    var btnConfirm = document.getElementById('btnConfirmInstall');
    var btnCancel = document.getElementById('btnCancelInstall');
    var form = document.getElementById('installForm');

    // Language strings
    var LANG = {
        selected: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_CONSOLE_SELECTED')) ?>',
        type: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_CONSOLE_TYPE')) ?>',
        typeCatalog: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_CONSOLE_TYPE_CATALOG')) ?>',
        skuDetected: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_CONSOLE_SKU_DETECTED')) ?>',
        noSku: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_CONSOLE_NO_SKU')) ?>',
        modeWithSku: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_CONSOLE_MODE_WITH_SKU')) ?>',
        modeWithoutSku: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_CONSOLE_MODE_WITHOUT_SKU')) ?>',
        selectError: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_CONSOLE_SELECT_ERROR')) ?>',
        productIblock: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_CONFIRM_PRODUCT_IBLOCK')) ?>',
        skuIblock: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_CONFIRM_SKU_IBLOCK')) ?>',
        yes: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_YES')) ?>',
        no: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_NO')) ?>',
        willCreate: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_CONFIRM_WILL_CREATE')) ?>',
        iblockTypeCalc: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_TYPE_CALCULATOR')) ?>',
        iblockTypeCatalog: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_TYPE_CATALOG')) ?>',
        iblockCount: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_CONFIRM_IBLOCK_COUNT')) ?>',
        skuLinks: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_CONFIRM_SKU_LINKS')) ?>',
        eventHandlers: '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_CONFIRM_EVENT_HANDLERS')) ?>'
    };

    function clearConsole() {
        consoleDiv.innerHTML = '';
    }

    function logToConsole(message, type) {
        type = type || 'info';
        var line = document.createElement('div');
        line.className = 'log-' + type;
        line.innerHTML = message;
        consoleDiv.appendChild(line);
        consoleDiv.scrollTop = consoleDiv.scrollHeight;
    }

    function showConsole() {
        consoleDiv.classList.add('visible');
    }

    function hideConsole() {
        consoleDiv.classList.remove('visible');
    }

    function showConfirm() {
        confirmDiv.classList.add('visible');
    }

    function hideConfirm() {
        confirmDiv.classList.remove('visible');
    }

    function updateConsoleOnSelect() {
        var option = selectIblock.options[selectIblock.selectedIndex];
        var iblockId = selectIblock.value;
        var skuId = option.getAttribute('data-sku-id');
        var iblockName = option.getAttribute('data-name');

        clearConsole();
        hideConfirm();

        if (!iblockId) {
            hideConsole();
            btnNext.disabled = true;
            return;
        }

        showConsole();
        btnNext.disabled = false;

        logToConsole('→ ' + LANG.selected + ': ' + iblockName + ' [ID: ' + iblockId + ']', 'info');
        logToConsole('→ ' + LANG.type + ': ' + LANG.typeCatalog, 'info');

        if (skuId && skuId !== '0' && skuId !== '') {
            logToConsole('→ ' + LANG.skuDetected + ': ID ' + skuId, 'success');
            logToConsole('→ ' + LANG.modeWithSku, 'success');
        } else {
            logToConsole('→ ' + LANG.noSku, 'warning');
            logToConsole('→ ' + LANG.modeWithoutSku, 'warning');
        }
    }

    function buildConfirmDetails() {
        var option = selectIblock.options[selectIblock.selectedIndex];
        var iblockId = selectIblock.value;
        var skuId = option.getAttribute('data-sku-id');
        var iblockName = option.getAttribute('data-name');

        var html = '<p style="color: #4ec9b0;">✓ ' + LANG.productIblock + ': ' + iblockName + ' [' + iblockId + ']</p>';

        if (skuId && skuId !== '0' && skuId !== '') {
            html += '<p style="color: #4ec9b0;">✓ ' + LANG.skuIblock + ': ID ' + skuId + '</p>';
        }


        html += '<p style="color: #d4d4d4; margin-top: 15px;">' + LANG.willCreate + '</p>';
        html += '<ul>';
        html += '<li>→ ' + LANG.iblockTypeCalc + '</li>';
        html += '<li>→ ' + LANG.iblockTypeCatalog + '</li>';
        html += '<li>→ ' + LANG.iblockCount + '</li>';
        html += '<li>→ ' + LANG.skuLinks + '</li>';
        html += '<li>→ ' + LANG.eventHandlers + '</li>';
        html += '</ul>';

        confirmDetails.innerHTML = html;
    }

    function onNextClick() {
        var iblockId = selectIblock.value;

        // Defensive validation: button should be disabled, but check anyway for edge cases
        if (!iblockId) {
            clearConsole();
            showConsole();
            logToConsole('✗ ' + LANG.selectError, 'error');
            return;
        }

        // Add preparation header to console
        logToConsole('', 'info');
        logToConsole('═══ <?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_CONSOLE_PREPARATION')) ?> ═══', 'header');
        logToConsole('', 'info');

        // Build and show confirmation block
        buildConfirmDetails();
        showConfirm();
    }

    function onConfirmClick() {
        form.submit();
    }

    function onCancelClick() {
        hideConfirm();
        updateConsoleOnSelect();
    }

    // Event listeners
    selectIblock.addEventListener('change', updateConsoleOnSelect);
    btnNext.addEventListener('click', onNextClick);
    btnConfirm.addEventListener('click', onConfirmClick);
    btnCancel.addEventListener('click', onCancelClick);

    // Initialize
    updateConsoleOnSelect();
})();
</script>
