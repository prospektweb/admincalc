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

use Prospektweb\Calc\Config\SettingsManager;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Install\SnapshotManager;

global $USER, $APPLICATION;

// Проверка прав доступа
if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}

$settingsManager = new SettingsManager();
$configManager = new ConfigManager();

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

// Обработка сохранения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $settings = [
        'priceTypeId' => (int)($_POST['DEFAULT_PRICE_TYPE_ID'] ?? 1),
        'currency' => (string)($_POST['DEFAULT_CURRENCY'] ?? 'RUB'),
        'loggingEnabled' => ($_POST['LOGGING_ENABLED'] ?? 'N') === 'Y',
    ];

    $settingsManager->saveAllSettings($settings);

    // Сохраняем настройки интеграции
    Option::set($module_id, 'IBLOCK_MATERIALS', (int)($_POST['IBLOCK_MATERIALS'] ?? 0));
    Option::set($module_id, 'IBLOCK_OPERATIONS', (int)($_POST['IBLOCK_OPERATIONS'] ?? 0));
    Option::set($module_id, 'IBLOCK_EQUIPMENT', (int)($_POST['IBLOCK_EQUIPMENT'] ?? 0));
    Option::set($module_id, 'IBLOCK_DETAILS', (int)($_POST['IBLOCK_DETAILS'] ?? 0));
    Option::set($module_id, 'IBLOCK_CALCULATORS', (int)($_POST['IBLOCK_CALCULATORS'] ?? 0));
    Option::set($module_id, 'IBLOCK_CONFIGURATIONS', (int)($_POST['IBLOCK_CONFIGURATIONS'] ?? 0));

    // Сохраняем настройки связей ТП
    Option::set($module_id, 'FORMAT_FIELD_CODE', (string)($_POST['FORMAT_FIELD_CODE'] ?? 'FORMAT'));
    Option::set($module_id, 'VOLUME_FIELD_CODE', (string)($_POST['VOLUME_FIELD_CODE'] ?? 'VOLUME'));

    // Сохраняем настройки округления цен
    Option::set($module_id, 'PRICE_ROUNDING', (float)($_POST['PRICE_ROUNDING'] ?? 1));

    // Сохраняем лимит истории расчётов
    Option::set($module_id, 'CALC_HISTORY_LIMIT', (int)($_POST['CALC_HISTORY_LIMIT'] ?? 10));

    // Сохраняем настройки наценки
    if (isset($_POST['DEFAULT_EXTRA_VALUE'])) {
        $settingsManager->setDefaultExtraValue((int)$_POST['DEFAULT_EXTRA_VALUE']);
    }
    if (isset($_POST['DEFAULT_EXTRA_CURRENCY_VALUE'])) {
        $settingsManager->setDefaultExtraCurrency((string)$_POST['DEFAULT_EXTRA_CURRENCY_VALUE']);
    }

    LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($module_id) . '&lang=' . LANGUAGE_ID . '&saved=Y');
}

// Получаем текущие настройки
$currentSettings = $settingsManager->getAllSettings();

// Получаем список типов цен
$priceTypes = [];
if (Loader::includeModule('catalog')) {
    $priceTypeList = \CCatalogGroup::GetListArray();
    foreach ($priceTypeList as $type) {
        $priceTypes[(int)$type['ID']] = $type['NAME'] ?? ('ID ' . $type['ID']);
    }
}

// Получаем список валют
$currencies = [];
if (Loader::includeModule('currency')) {
    $currencyList = \Bitrix\Currency\CurrencyManager::getCurrencyList();
    foreach ($currencyList as $code => $name) {
        $currencies[$code] = $name;
    }
} else {
    $currencies = ['RUB' => 'Рубль', 'USD' => 'Доллар США', 'EUR' => 'Евро'];
}

// Получаем список свойств типа "список" из инфоблока ТП
$skuIblockId = $configManager->getSkuIblockId();
$listProperties = [];
if ($skuIblockId > 0 && Loader::includeModule('iblock')) {
    $rsProperties = \CIBlockProperty::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ['IBLOCK_ID' => $skuIblockId, 'PROPERTY_TYPE' => 'L', 'ACTIVE' => 'Y']
    );
    while ($arProperty = $rsProperties->Fetch()) {
        $listProperties[$arProperty['CODE']] = $arProperty['NAME'] . ' [' . $arProperty['CODE'] . ']';
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

// Вывод сообщения об успешном сохранении
if ($_GET['saved'] === 'Y') {
    CAdminMessage::ShowMessage([
        'MESSAGE' => Loc::getMessage('PROSPEKTWEB_CALC_SETTINGS_SAVED'),
        'TYPE' => 'OK',
    ]);
}

// Создаём вкладки
$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'edit1', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_MAIN'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_MAIN_TITLE')],
    ['DIV' => 'edit2', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_OFFERS'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_OFFERS_TITLE')],
    ['DIV' => 'edit3', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_IBLOCKS'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_IBLOCKS_TITLE')],
    ['DIV' => 'edit4', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_INTEGRATION'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_INTEGRATION_TITLE')],
]);

$tabControl->Begin();

?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>

    <?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td width="40%"><?= Loc::getMessage('PROSPEKTWEB_CALC_DEFAULT_PRICE_TYPE') ?></td>
        <td width="60%">
            <select name="DEFAULT_PRICE_TYPE_ID">
                <?php foreach ($priceTypes as $id => $name): ?>
                <option value="<?= $id ?>" <?= $currentSettings['priceTypeId'] == $id ? 'selected' : '' ?>>
                    <?= htmlspecialcharsbx($name) ?> [<?= $id ?>]
                </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>

    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_DEFAULT_CURRENCY') ?></td>
        <td>
            <select name="DEFAULT_CURRENCY">
                <?php foreach ($currencies as $code => $name): ?>
                <option value="<?= htmlspecialcharsbx($code) ?>" <?= $currentSettings['currency'] == $code ? 'selected' : '' ?>>
                    <?= htmlspecialcharsbx($name) ?> (<?= $code ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>

    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_LOGGING_ENABLED') ?></td>
        <td>
            <input type="checkbox" name="LOGGING_ENABLED" value="Y" <?= $currentSettings['loggingEnabled'] ? 'checked' : '' ?>>
        </td>
    </tr>

    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_PRICE_ROUNDING') ?></td>
        <td>
            <select name="PRICE_ROUNDING">
                <?php 
                $roundingOptions = [0.1, 0.5, 1, 5, 10, 50, 100];
                $currentRounding = (float)Option::get($module_id, 'PRICE_ROUNDING', 1);
                foreach ($roundingOptions as $value): 
                ?>
                <option value="<?= $value ?>" <?= abs($currentRounding - $value) < 0.001 ? 'selected' : '' ?>>
                    <?= $value ?>
                </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>

    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_DEFAULT_EXTRA_VALUE') ?></td>
        <td>
            <input type="number" name="DEFAULT_EXTRA_VALUE" value="<?= htmlspecialcharsbx($settingsManager->getDefaultExtraValue()) ?>" min="0" step="1" style="width: 100px;">
            <br><span style="color: #777; font-size: 11px;">Значение наценки по умолчанию (только положительные значения и 0)</span>
        </td>
    </tr>


    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_HISTORY_LIMIT') ?></td>
        <td>
            <input type="number" name="CALC_HISTORY_LIMIT" value="<?= (int)Option::get($module_id, 'CALC_HISTORY_LIMIT', 10) ?>" min="1" max="100" size="5" style="width: 80px;">
            <br><span style="color: #777; font-size: 11px;">Максимальное количество записей истории расчётов, хранящихся для одного ТП</span>
        </td>
    </tr>

    <tr>
        <td>Snapshot данных модуля</td>
        <td>
            <button type="submit" name="EXPORT_SNAPSHOT" value="Y" class="adm-btn">Скачать snapshot текущего сайта</button>
            <div style="color:#777;font-size:11px;margin-top:6px;">Файл нужен для импорта данных при установке на другом сайте.</div>
        </td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_DEFAULT_EXTRA_CURRENCY') ?></td>
        <td>
            <select name="DEFAULT_EXTRA_CURRENCY_VALUE">
                <option value="RUB" <?= $settingsManager->getDefaultExtraCurrency() === 'RUB' ? 'selected' : '' ?>>
                    Рубли (RUB)
                </option>
                <option value="PRC" <?= $settingsManager->getDefaultExtraCurrency() === 'PRC' ? 'selected' : '' ?>>
                    Проценты (PRC)
                </option>
            </select>
            <br><span style="color: #777; font-size: 11px;">Валюта наценки по умолчанию</span>
        </td>
    </tr>

    <?php $tabControl->BeginNextTab(); ?>

    <tr class="heading">
        <td colspan="2"><?= Loc::getMessage('PROSPEKTWEB_CALC_OFFERS_PROPERTIES_HEADING') ?></td>
    </tr>

    <tr>
        <td width="40%" class="adm-detail-content-cell-l">
            <?= Loc::getMessage('PROSPEKTWEB_CALC_FORMAT_FIELD_CODE') ?>:
        </td>
        <td width="60%" class="adm-detail-content-cell-r">
            <?php if (!empty($listProperties)): ?>
                <select name="FORMAT_FIELD_CODE" style="width: 300px;">
                    <option value=""><?= Loc::getMessage('PROSPEKTWEB_CALC_SELECT_PROPERTY') ?></option>
                    <?php 
                    $currentFormatCode = Option::get($module_id, 'FORMAT_FIELD_CODE', 'FORMAT');
                    foreach ($listProperties as $code => $name): 
                    ?>
                    <option value="<?= htmlspecialcharsbx($code) ?>" <?= $currentFormatCode === $code ? 'selected' : '' ?>>
                        <?= htmlspecialcharsbx($name) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="text" name="FORMAT_FIELD_CODE" value="<?= htmlspecialcharsbx(Option::get($module_id, 'FORMAT_FIELD_CODE', 'FORMAT')) ?>" size="30">
                <br><span class="adm-info-message"><?= Loc::getMessage('PROSPEKTWEB_CALC_NO_LIST_PROPERTIES') ?></span>
            <?php endif; ?>
            <br><span style="color: #777; font-size: 11px;"><?= Loc::getMessage('PROSPEKTWEB_CALC_FORMAT_FIELD_CODE_HINT') ?></span>
        </td>
    </tr>

    <tr>
        <td class="adm-detail-content-cell-l">
            <?= Loc::getMessage('PROSPEKTWEB_CALC_VOLUME_FIELD_CODE') ?>:
        </td>
        <td class="adm-detail-content-cell-r">
            <?php if (!empty($listProperties)): ?>
                <select name="VOLUME_FIELD_CODE" style="width: 300px;">
                    <option value=""><?= Loc::getMessage('PROSPEKTWEB_CALC_SELECT_PROPERTY') ?></option>
                    <?php 
                    $currentVolumeCode = Option::get($module_id, 'VOLUME_FIELD_CODE', 'VOLUME');
                    foreach ($listProperties as $code => $name): 
                    ?>
                    <option value="<?= htmlspecialcharsbx($code) ?>" <?= $currentVolumeCode === $code ? 'selected' : '' ?>>
                        <?= htmlspecialcharsbx($name) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="text" name="VOLUME_FIELD_CODE" value="<?= htmlspecialcharsbx(Option::get($module_id, 'VOLUME_FIELD_CODE', 'VOLUME')) ?>" size="30">
                <br><span class="adm-info-message"><?= Loc::getMessage('PROSPEKTWEB_CALC_NO_LIST_PROPERTIES') ?></span>
            <?php endif; ?>
            <br><span style="color: #777; font-size: 11px;"><?= Loc::getMessage('PROSPEKTWEB_CALC_VOLUME_FIELD_CODE_HINT') ?></span>
        </td>
    </tr>

    <?php $tabControl->BeginNextTab(); ?>

    <?php
    $iblockCodes = [
        'CALC_PRESETS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_PRESETS'),
        'CALC_STAGES' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_CALC_STAGES'),
        'CALC_STAGES_VARIANTS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_CALC_STAGES_VARIANTS'),
        'CALC_SETTINGS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_CALC_SETTINGS'),
        'CALC_MATERIALS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_MATERIALS'),
        'CALC_MATERIALS_VARIANTS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_MATERIALS_VARIANTS'),
        'CALC_OPERATIONS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_OPERATIONS'),
        'CALC_OPERATIONS_VARIANTS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_OPERATIONS_VARIANTS'),
        'CALC_EQUIPMENT' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_EQUIPMENT'),
        'CALC_DETAILS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_DETAILS'),
        'CALC_DETAILS_VARIANTS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_DETAILS_VARIANTS'),
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

    <tr>
        <td width="40%"><?= Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_MATERIALS_INTEGRATION') ?></td>
        <td width="60%">
            <input type="number" name="IBLOCK_MATERIALS" value="<?= (int)Option::get($module_id, 'IBLOCK_MATERIALS', 0) ?>" min="0" style="width: 100px;">
        </td>
    </tr>

    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_OPERATIONS_INTEGRATION') ?></td>
        <td>
            <input type="number" name="IBLOCK_OPERATIONS" value="<?= (int)Option::get($module_id, 'IBLOCK_OPERATIONS', 0) ?>" min="0" style="width: 100px;">
        </td>
    </tr>

    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_EQUIPMENT_INTEGRATION') ?></td>
        <td>
            <input type="number" name="IBLOCK_EQUIPMENT" value="<?= (int)Option::get($module_id, 'IBLOCK_EQUIPMENT', 0) ?>" min="0" style="width: 100px;">
        </td>
    </tr>

    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_DETAILS_INTEGRATION') ?></td>
        <td>
            <input type="number" name="IBLOCK_DETAILS" value="<?= (int)Option::get($module_id, 'IBLOCK_DETAILS', 0) ?>" min="0" style="width: 100px;">
        </td>
    </tr>

    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_CALCULATORS_INTEGRATION') ?></td>
        <td>
            <input type="number" name="IBLOCK_CALCULATORS" value="<?= (int)Option::get($module_id, 'IBLOCK_CALCULATORS', 0) ?>" min="0" style="width: 100px;">
        </td>
    </tr>

    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_CONFIGURATIONS_INTEGRATION') ?></td>
        <td>
            <input type="number" name="IBLOCK_CONFIGURATIONS" value="<?= (int)Option::get($module_id, 'IBLOCK_CONFIGURATIONS', 0) ?>" min="0" style="width: 100px;">
        </td>
    </tr>

    <?php
    $tabControl->Buttons([
        'disabled' => false,
        'back_url' => '/bitrix/admin/settings.php?lang=' . LANGUAGE_ID,
    ]);
    ?>

    <?php $tabControl->End(); ?>
</form>

<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
