<?php
/**
 * Страница настроек модуля prospektweb.calc
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\Highloadblock\HighloadBlockTable;

Loc::loadMessages(__FILE__);

$module_id = 'prospektweb.calc';

if (!Loader::includeModule($module_id)) {
    ShowError(Loc::getMessage('PROSPEKTWEB_CALC_MODULE_NOT_INSTALLED'));
    return;
}

use Prospektweb\Calc\Config\SettingsManager;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Install\SnapshotManager;
use Prospektweb\Calc\Integration\ConsolidationManager;
use Prospektweb\Calc\Integration\TemplatePatchCoordinator;

global $USER, $APPLICATION;

// Проверка прав доступа
if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}


$settingsManager = new SettingsManager();
$configManager = new ConfigManager();

/**
 * Возвращает DataClass для HL истории расчётов.
 */
function getHistoryEntityClass(string $moduleId)
{
    if (!Loader::includeModule('highloadblock')) {
        return null;
    }

    $hlblockId = (int)Option::get($moduleId, 'HIGHLOAD_CALC_HISTORY_ID', 0);
    if ($hlblockId <= 0) {
        return null;
    }

    $hlblock = HighloadBlockTable::getById($hlblockId)->fetch();
    if (!$hlblock) {
        return null;
    }

    $entity = HighloadBlockTable::compileEntity($hlblock);
    return $entity->getDataClass();
}

/**
 * Очищает историю расчётов.
 */
function cleanupCalculationHistory(string $moduleId, string $mode, ConfigManager $configManager): array
{
    $entityClass = getHistoryEntityClass($moduleId);
    if ($entityClass === null) {
        return ['deleted' => 0, 'checked' => 0, 'message' => 'HighloadBlock истории не найден'];
    }

    if ($mode === 'all') {
        $deleted = 0;
        $rows = $entityClass::getList(['select' => ['ID']]);
        while ($row = $rows->fetch()) {
            $entityClass::delete((int)$row['ID']);
            $deleted++;
        }

        $skuIblockId = $configManager->getSkuIblockId();
        if ($skuIblockId > 0 && Loader::includeModule('iblock')) {
            $offers = \CIBlockElement::GetList([], ['IBLOCK_ID' => $skuIblockId], false, false, ['ID']);
            while ($offer = $offers->Fetch()) {
                \CIBlockElement::SetPropertyValuesEx((int)$offer['ID'], $skuIblockId, ['COMPLETED_CALCS' => []]);
            }
        }

        return ['deleted' => $deleted, 'checked' => $deleted, 'message' => 'История полностью очищена'];
    }

    $skuIblockId = $configManager->getSkuIblockId();
    if ($skuIblockId <= 0 || !Loader::includeModule('iblock')) {
        return ['deleted' => 0, 'checked' => 0, 'message' => 'Не найден инфоблок ТП для проверки сирот'];
    }

    $offerIds = [];
    $rows = $entityClass::getList(['select' => ['ID', 'UF_OFFER_ID']]);
    $historyRows = [];
    while ($row = $rows->fetch()) {
        $historyRows[] = ['ID' => (int)$row['ID'], 'UF_OFFER_ID' => (int)$row['UF_OFFER_ID']];
        if ((int)$row['UF_OFFER_ID'] > 0) {
            $offerIds[(int)$row['UF_OFFER_ID']] = true;
        }
    }

    if (empty($historyRows)) {
        return ['deleted' => 0, 'checked' => 0, 'message' => 'История уже пуста'];
    }

    $existingOffers = [];
    if (!empty($offerIds)) {
        $offerFilter = ['IBLOCK_ID' => $skuIblockId, 'ID' => array_keys($offerIds)];
        $offerRs = \CIBlockElement::GetList([], $offerFilter, false, false, ['ID']);
        while ($offer = $offerRs->Fetch()) {
            $existingOffers[(int)$offer['ID']] = true;
        }
    }

    $deleted = 0;
    foreach ($historyRows as $row) {
        if (!isset($existingOffers[$row['UF_OFFER_ID']])) {
            $entityClass::delete($row['ID']);
            $deleted++;
        }
    }

    return [
        'deleted' => $deleted,
        'checked' => count($historyRows),
        'message' => 'Удалены записи истории для удалённых ТП',
    ];
}

/**
 * Сервисная очистка неиспользуемых деталей и этапов.
 */
function cleanupUnusedDetailsAndStages(ConfigManager $configManager): array
{
    if (!Loader::includeModule('iblock')) {
        return ['deletedDetails' => 0, 'deletedStages' => 0, 'checkedDetails' => 0, 'checkedStages' => 0, 'message' => 'Модуль iblock не доступен'];
    }

    $presetsIblockId = $configManager->getIblockId('CALC_PRESETS');
    $detailsIblockId = $configManager->getIblockId('CALC_DETAILS');
    $stagesIblockId = $configManager->getIblockId('CALC_STAGES');

    if ($presetsIblockId <= 0 || $detailsIblockId <= 0 || $stagesIblockId <= 0) {
        return ['deletedDetails' => 0, 'deletedStages' => 0, 'checkedDetails' => 0, 'checkedStages' => 0, 'message' => 'Не настроены инфоблоки CALC_PRESETS/CALC_DETAILS/CALC_STAGES'];
    }

    $usedDetails = [];
    $usedStages = [];
    $queue = [];

    $presetRs = \CIBlockElement::GetList([], ['IBLOCK_ID' => $presetsIblockId], false, false, ['ID']);
    while ($preset = $presetRs->Fetch()) {
        $presetId = (int)$preset['ID'];

        $detailsRs = \CIBlockElement::GetProperty($presetsIblockId, $presetId, [], ['CODE' => 'CALC_DETAILS']);
        while ($detail = $detailsRs->Fetch()) {
            $detailId = (int)($detail['VALUE'] ?? 0);
            if ($detailId > 0 && !isset($usedDetails[$detailId])) {
                $usedDetails[$detailId] = true;
                $queue[] = $detailId;
            }
        }

        $stagesRs = \CIBlockElement::GetProperty($presetsIblockId, $presetId, [], ['CODE' => 'CALC_STAGES']);
        while ($stage = $stagesRs->Fetch()) {
            $stageId = (int)($stage['VALUE'] ?? 0);
            if ($stageId > 0) {
                $usedStages[$stageId] = true;
            }
        }
    }

    while (!empty($queue)) {
        $detailId = (int)array_pop($queue);
        if ($detailId <= 0) {
            continue;
        }

        $detailStagesRs = \CIBlockElement::GetProperty($detailsIblockId, $detailId, [], ['CODE' => 'CALC_STAGES']);
        while ($detailStage = $detailStagesRs->Fetch()) {
            $stageId = (int)($detailStage['VALUE'] ?? 0);
            if ($stageId > 0) {
                $usedStages[$stageId] = true;
            }
        }

        $childDetailsRs = \CIBlockElement::GetProperty($detailsIblockId, $detailId, [], ['CODE' => 'DETAILS']);
        while ($childDetail = $childDetailsRs->Fetch()) {
            $childDetailId = (int)($childDetail['VALUE'] ?? 0);
            if ($childDetailId > 0 && !isset($usedDetails[$childDetailId])) {
                $usedDetails[$childDetailId] = true;
                $queue[] = $childDetailId;
            }
        }
    }

    $allDetails = [];
    $detailsRs = \CIBlockElement::GetList([], ['IBLOCK_ID' => $detailsIblockId], false, false, ['ID']);
    while ($detail = $detailsRs->Fetch()) {
        $detailId = (int)$detail['ID'];
        if ($detailId > 0) {
            $allDetails[] = $detailId;
        }
    }

    $allStages = [];
    $stagesRs = \CIBlockElement::GetList([], ['IBLOCK_ID' => $stagesIblockId], false, false, ['ID']);
    while ($stage = $stagesRs->Fetch()) {
        $stageId = (int)$stage['ID'];
        if ($stageId > 0) {
            $allStages[] = $stageId;
        }
    }

    $detailsToDelete = [];
    foreach ($allDetails as $detailId) {
        if (!isset($usedDetails[$detailId])) {
            $detailsToDelete[] = $detailId;
        }
    }

    $stagesToDelete = [];
    foreach ($allStages as $stageId) {
        if (!isset($usedStages[$stageId])) {
            $stagesToDelete[] = $stageId;
        }
    }

    $deletedDetails = 0;
    foreach ($detailsToDelete as $detailId) {
        if (\CIBlockElement::Delete($detailId)) {
            $deletedDetails++;
        }
    }

    $deletedStages = 0;
    foreach ($stagesToDelete as $stageId) {
        if (\CIBlockElement::Delete($stageId)) {
            $deletedStages++;
        }
    }

    return [
        'deletedDetails' => $deletedDetails,
        'deletedStages' => $deletedStages,
        'checkedDetails' => count($allDetails),
        'checkedStages' => count($allStages),
        'message' => 'Удалены незадействованные элементы',
    ];
}

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

// Сервисная очистка истории
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && isset($_POST['CLEANUP_HISTORY_ACTION'])) {
    $cleanupAction = (string)$_POST['CLEANUP_HISTORY_ACTION'];
    $allowedActions = ['orphans', 'all'];

    if (in_array($cleanupAction, $allowedActions, true)) {
        $cleanupResult = cleanupCalculationHistory($module_id, $cleanupAction, $configManager);
        $query = [
            'mid' => $module_id,
            'lang' => LANGUAGE_ID,
            'cleanup' => 'Y',
            'cleanupAction' => $cleanupAction,
            'deleted' => (int)$cleanupResult['deleted'],
            'checked' => (int)$cleanupResult['checked'],
            'cleanupMessage' => urlencode((string)$cleanupResult['message']),
        ];
        LocalRedirect($APPLICATION->GetCurPage() . '?' . http_build_query($query));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && isset($_POST['CLEANUP_UNUSED_ELEMENTS'])) {
    $cleanupResult = cleanupUnusedDetailsAndStages($configManager);
    $query = [
        'mid' => $module_id,
        'lang' => LANGUAGE_ID,
        'cleanupUnused' => 'Y',
        'deletedDetails' => (int)$cleanupResult['deletedDetails'],
        'deletedStages' => (int)$cleanupResult['deletedStages'],
        'checkedDetails' => (int)$cleanupResult['checkedDetails'],
        'checkedStages' => (int)$cleanupResult['checkedStages'],
        'cleanupMessage' => urlencode((string)$cleanupResult['message']),
    ];
    LocalRedirect($APPLICATION->GetCurPage() . '?' . http_build_query($query));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && isset($_POST['APPLY_CONSOLIDATION'])) {
    try {
        (new ConsolidationManager())->apply();
        LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($module_id) . '&lang=' . LANGUAGE_ID . '&consolidation=ok');
    } catch (\Throwable $exception) {
        LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($module_id) . '&lang=' . LANGUAGE_ID . '&consolidation=error&message=' . urlencode($exception->getMessage()));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && isset($_POST['APPLY_TEMPLATE_PATCHES'])) {
    try {
        (new TemplatePatchCoordinator())->apply(trim((string)($_POST['APPROVED_ASPRO_PRICES_HASH'] ?? '')));
        LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($module_id) . '&lang=' . LANGUAGE_ID . '&templates=ok');
    } catch (\Throwable $exception) {
        LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($module_id) . '&lang=' . LANGUAGE_ID . '&templates=error&message=' . urlencode($exception->getMessage()));
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
    
    // Сохраняем URL calc-server
    Option::set($module_id, 'CALC_SERVER_URL', (string)($_POST['CALC_SERVER_URL'] ?? 'http://localhost:3100'));

    // Сохраняем настройки связей ТП
    Option::set($module_id, 'FORMAT_FIELD_CODE', (string)($_POST['FORMAT_FIELD_CODE'] ?? 'FORMAT'));
    Option::set($module_id, 'VOLUME_FIELD_CODE', (string)($_POST['VOLUME_FIELD_CODE'] ?? 'VOLUME'));

    // Сохраняем настройки округления цен
    Option::set($module_id, 'PRICE_ROUNDING', (float)($_POST['PRICE_ROUNDING'] ?? 1));

    // Сохраняем настройки истории расчётов
    Option::set($module_id, 'SAVE_CALC_HISTORY', (($_POST['SAVE_CALC_HISTORY'] ?? 'N') === 'Y') ? 'Y' : 'N');
    Option::set($module_id, 'CALC_HISTORY_LIMIT', (int)($_POST['CALC_HISTORY_LIMIT'] ?? 10));

    foreach ([
        'PRODUCTS_IBLOCK_ID', 'OFFERS_IBLOCK_ID', 'CALC_PROPERTY_CODE', 'CALC_AJAX_URL',
        'DEADLINE_URGENT_TEXT', 'DEADLINE_STRICT_TEXT', 'DEADLINE_FLEXIBLE_TEXT',
        'AREA_DISPLAY_UNIT', 'CALC_SERVER_TIMEOUT', 'CALC_SERVER_BATCH_LIMIT', 'SERVICE_OFFER_ID',
        'VOLUME_GRID_VALUES', 'VOLUME_GRID_TAIL_STEP',
        'base_folder', 'max_size', 'extensions', 'temp_lifetime_hours', 'tooltip_text',
        'desired_receive_tooltip_text', 'desired_receive_min_hours', 'desired_receive_workdays',
        'desired_receive_time_from', 'desired_receive_time_to', 'desired_receive_step_minutes',
        'desired_receive_default_time', 'desired_receive_holidays',
        'desired_receive_production_hours_property', 'hidden_basket_property_codes',
        'yadisk_client_id',
    ] as $optionName) {
        Option::set($module_id, $optionName, trim((string)($_POST[$optionName] ?? '')));
    }
    Option::set($module_id, 'CALC_SERVER_DEBUG_CONSOLE', (($_POST['CALC_SERVER_DEBUG_CONSOLE'] ?? 'N') === 'Y') ? 'Y' : 'N');
    Option::set($module_id, 'ENABLED', (($_POST['PROPERTY_VALUES_ENABLED'] ?? 'N') === 'Y') ? 'Y' : 'N');
    foreach (['restricted', 'verified', 'extended'] as $scenarioCode) {
        $postedName = 'ACCESS_SCENARIO_' . strtoupper($scenarioCode) . '_GROUP_IDS';
        $groupIds = array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', trim((string)($_POST[$postedName] ?? '')), -1, PREG_SPLIT_NO_EMPTY)))));
        Option::set($module_id, 'access_scenarios.' . $scenarioCode . '.group_ids', json_encode($groupIds));
    }
    Option::set($module_id, 'access_scenarios.restricted.more_url', trim((string)($_POST['ACCESS_SCENARIO_RESTRICTED_MORE_URL'] ?? '')));
    Option::set($module_id, 'access_scenarios.restricted.mobile_message', trim((string)($_POST['ACCESS_SCENARIO_RESTRICTED_MOBILE_MESSAGE'] ?? '')));
    $editorSchema = trim((string)($_POST['CALC_EDITOR_SCHEMA'] ?? ''));
    if ($editorSchema === '' || json_decode($editorSchema, true) !== null) {
        Option::set($module_id, 'CALC_EDITOR_SCHEMA', $editorSchema);
    }
    $yandexSecret = trim((string)($_POST['yadisk_client_secret'] ?? ''));
    if ($yandexSecret !== '') {
        Option::set($module_id, 'yadisk_client_secret', $yandexSecret);
    }

    // Сохраняем настройки наценки
    if (isset($_POST['DEFAULT_EXTRA_VALUE'])) {
        $settingsManager->setDefaultExtraValue((int)$_POST['DEFAULT_EXTRA_VALUE']);
    }
    if (isset($_POST['DEFAULT_EXTRA_CURRENCY_VALUE'])) {
        $settingsManager->setDefaultExtraCurrency((string)$_POST['DEFAULT_EXTRA_CURRENCY_VALUE']);
    }

    $markupSettings = [
        'basePriceTypeId' => (int)($_POST['MARKUP_BASE_PRICE_TYPE_ID'] ?? 0),
        'rates' => [],
    ];

    $postedRates = $_POST['MARKUP_RATE'] ?? [];
    if (is_array($postedRates)) {
        foreach ($postedRates as $priceTypeId => $rateValue) {
            $typeId = (int)$priceTypeId;
            if ($typeId <= 0) {
                continue;
            }

            $markupSettings['rates'][$typeId] = (float)str_replace(',', '.', (string)$rateValue);
        }
    }

    Option::set($module_id, 'MARKUP_SETTINGS', json_encode($markupSettings, JSON_UNESCAPED_UNICODE));

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

$markupSettingsRaw = Option::get($module_id, 'MARKUP_SETTINGS', '');
$markupSettings = json_decode($markupSettingsRaw, true);
if (!is_array($markupSettings)) {
    $markupSettings = [];
}

$markupBasePriceTypeId = (int)($markupSettings['basePriceTypeId'] ?? 0);
$markupRates = is_array($markupSettings['rates'] ?? null) ? $markupSettings['rates'] : [];

if ($markupBasePriceTypeId <= 0 && !empty($priceTypes)) {
    $firstPriceTypeId = (int)array_key_first($priceTypes);
    $markupBasePriceTypeId = $firstPriceTypeId > 0 ? $firstPriceTypeId : 0;
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
if (($_GET['saved'] ?? '') === 'Y') {
    CAdminMessage::ShowMessage([
        'MESSAGE' => Loc::getMessage('PROSPEKTWEB_CALC_SETTINGS_SAVED'),
        'TYPE' => 'OK',
    ]);
}

foreach (['consolidation' => 'Обновление объединённого модуля', 'templates' => 'Изменение шаблона сайта'] as $resultKey => $resultTitle) {
    $result = (string)($_GET[$resultKey] ?? '');
    if ($result === '') {
        continue;
    }
    CAdminMessage::ShowMessage([
        'MESSAGE' => $resultTitle,
        'DETAILS' => $result === 'ok' ? 'Операция выполнена успешно.' : htmlspecialcharsbx(urldecode((string)($_GET['message'] ?? 'Неизвестная ошибка'))),
        'TYPE' => $result === 'ok' ? 'OK' : 'ERROR',
        'HTML' => true,
    ]);
}

if (($_GET['cleanup'] ?? '') === 'Y') {
    $cleanupAction = (string)($_GET['cleanupAction'] ?? 'orphans');
    $deleted = (int)($_GET['deleted'] ?? 0);
    $checked = (int)($_GET['checked'] ?? 0);
    $cleanupMessage = urldecode((string)($_GET['cleanupMessage'] ?? ''));

    CAdminMessage::ShowMessage([
        'MESSAGE' => Loc::getMessage('PROSPEKTWEB_CALC_HISTORY_CLEANUP_DONE'),
        'DETAILS' => sprintf(
            Loc::getMessage('PROSPEKTWEB_CALC_HISTORY_CLEANUP_DETAILS') ?: '',
            $cleanupAction,
            $checked,
            $deleted,
            htmlspecialcharsbx($cleanupMessage)
        ),
        'TYPE' => 'OK',
        'HTML' => true,
    ]);
}

if (($_GET['cleanupUnused'] ?? '') === 'Y') {
    $deletedDetails = (int)($_GET['deletedDetails'] ?? 0);
    $deletedStages = (int)($_GET['deletedStages'] ?? 0);
    $checkedDetails = (int)($_GET['checkedDetails'] ?? 0);
    $checkedStages = (int)($_GET['checkedStages'] ?? 0);
    $cleanupMessage = urldecode((string)($_GET['cleanupMessage'] ?? ''));

    CAdminMessage::ShowMessage([
        'MESSAGE' => Loc::getMessage('PROSPEKTWEB_CALC_UNUSED_ELEMENTS_CLEANUP_DONE'),
        'DETAILS' => sprintf(
            Loc::getMessage('PROSPEKTWEB_CALC_UNUSED_ELEMENTS_CLEANUP_DETAILS') ?: '',
            $checkedDetails,
            $deletedDetails,
            $checkedStages,
            $deletedStages,
            htmlspecialcharsbx($cleanupMessage)
        ),
        'TYPE' => 'OK',
        'HTML' => true,
    ]);
}

// Создаём вкладки
$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'edit1', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_MARKUPS'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_MARKUPS_TITLE')],
    ['DIV' => 'edit2', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_MAIN'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_MAIN_TITLE')],
    ['DIV' => 'edit3', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_OFFERS'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_OFFERS_TITLE')],
    ['DIV' => 'edit4', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_IBLOCKS'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_IBLOCKS_TITLE')],
    ['DIV' => 'edit5', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_INTEGRATION'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_INTEGRATION_TITLE')],
    ['DIV' => 'frontend', 'TAB' => 'Публичный калькулятор', 'TITLE' => 'Карточка товара, сервер расчёта и сценарии доступа'],
    ['DIV' => 'properties', 'TAB' => 'Свойства и ТП', 'TITLE' => 'Описания свойств и инструменты торговых предложений'],
    ['DIV' => 'orders', 'TAB' => 'Корзина и макеты', 'TITLE' => 'Файлы макетов, сроки и Яндекс.Диск'],
    ['DIV' => 'consolidation', 'TAB' => 'Обновление', 'TITLE' => 'Миграция старых модулей и управляемые файлы'],
    ['DIV' => 'edit6', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_DIAGNOSTIC'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_DIAGNOSTIC_TITLE')],
]);

$tabControl->Begin();

?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>

    <?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td colspan="2" class="adm-detail-content-cell-r">
            <?php if (empty($priceTypes)): ?>
                <div class="adm-info-message"><?= Loc::getMessage('PROSPEKTWEB_CALC_MARKUPS_EMPTY_PRICE_TYPES') ?></div>
            <?php else: ?>
                <table class="adm-list-table" style="width:100%; max-width: 920px;">
                    <thead>
                        <tr class="adm-list-table-header">
                            <td><?= Loc::getMessage('PROSPEKTWEB_CALC_MARKUPS_COL_PRICE_TYPE') ?></td>
                            <td style="width: 220px;"><?= Loc::getMessage('PROSPEKTWEB_CALC_MARKUPS_COL_BASE') ?></td>
                            <td style="width: 220px;"><?= Loc::getMessage('PROSPEKTWEB_CALC_MARKUPS_COL_RATE') ?></td>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($priceTypes as $priceTypeId => $priceTypeName): ?>
                            <?php $rateValue = (float)($markupRates[$priceTypeId] ?? 0); ?>
                            <tr>
                                <td>
                                    <?= htmlspecialcharsbx($priceTypeName) ?> [<?= (int)$priceTypeId ?>]
                                </td>
                                <td>
                                    <label>
                                        <input
                                            type="radio"
                                            name="MARKUP_BASE_PRICE_TYPE_ID"
                                            value="<?= (int)$priceTypeId ?>"
                                            <?= $markupBasePriceTypeId === (int)$priceTypeId ? 'checked' : '' ?>
                                        >
                                        <?= Loc::getMessage('PROSPEKTWEB_CALC_MARKUPS_BASE_LABEL') ?>
                                    </label>
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        name="MARKUP_RATE[<?= (int)$priceTypeId ?>]"
                                        value="<?= htmlspecialcharsbx((string)$rateValue) ?>"
                                        step="0.01"
                                        style="width: 120px;"
                                    >
                                    <span>%</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="color:#777;font-size:11px;margin-top:6px;">
                    <?= Loc::getMessage('PROSPEKTWEB_CALC_MARKUPS_HINT') ?>
                </div>
            <?php endif; ?>
        </td>
    </tr>

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
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_SAVE_CALC_HISTORY') ?></td>
        <td>
            <input type="checkbox" name="SAVE_CALC_HISTORY" value="Y" <?= Option::get($module_id, 'SAVE_CALC_HISTORY', 'N') === 'Y' ? 'checked' : '' ?>>
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

    <tr class="heading">
        <td colspan="2"><?= Loc::getMessage('PROSPEKTWEB_CALC_HISTORY_SERVICE_TITLE') ?></td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_HISTORY_SERVICE_ORPHANS') ?></td>
        <td>
            <button type="submit" class="adm-btn" name="CLEANUP_HISTORY_ACTION" value="orphans" onclick="return confirm('<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_HISTORY_SERVICE_ORPHANS_CONFIRM')) ?>');">
                <?= Loc::getMessage('PROSPEKTWEB_CALC_HISTORY_SERVICE_ORPHANS_BTN') ?>
            </button>
            <div style="color:#777;font-size:11px;margin-top:6px;"><?= Loc::getMessage('PROSPEKTWEB_CALC_HISTORY_SERVICE_ORPHANS_HINT') ?></div>
        </td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_HISTORY_SERVICE_ALL') ?></td>
        <td>
            <button type="submit" class="adm-btn adm-btn-red" name="CLEANUP_HISTORY_ACTION" value="all" onclick="return confirm('<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_HISTORY_SERVICE_ALL_CONFIRM')) ?>');">
                <?= Loc::getMessage('PROSPEKTWEB_CALC_HISTORY_SERVICE_ALL_BTN') ?>
            </button>
            <div style="color:#777;font-size:11px;margin-top:6px;"><?= Loc::getMessage('PROSPEKTWEB_CALC_HISTORY_SERVICE_ALL_HINT') ?></div>
        </td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_UNUSED_ELEMENTS_SERVICE') ?></td>
        <td>
            <button type="submit" class="adm-btn" name="CLEANUP_UNUSED_ELEMENTS" value="Y" onclick="return confirm('<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_UNUSED_ELEMENTS_SERVICE_CONFIRM')) ?>');">
                <?= Loc::getMessage('PROSPEKTWEB_CALC_UNUSED_ELEMENTS_SERVICE_BTN') ?>
            </button>
            <div style="color:#777;font-size:11px;margin-top:6px;"><?= Loc::getMessage('PROSPEKTWEB_CALC_UNUSED_ELEMENTS_SERVICE_HINT') ?></div>
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

    <tr class="heading">
        <td colspan="2"><?= Loc::getMessage('PROSPEKTWEB_CALC_CALC_SERVER_HEADING') ?></td>
    </tr>

    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_CALC_SERVER_URL') ?></td>
        <td>
            <input type="text" name="CALC_SERVER_URL" value="<?= htmlspecialcharsbx(Option::get($module_id, 'CALC_SERVER_URL', 'http://localhost:3100')) ?>" size="50" style="width: 400px;">
            <br><span style="color: #777; font-size: 11px;"><?= Loc::getMessage('PROSPEKTWEB_CALC_CALC_SERVER_URL_HINT') ?></span>
        </td>
    </tr>

    <?php $tabControl->BeginNextTab(); ?>

    <tr class="heading"><td colspan="2">Инфоблоки и конфигурация</td></tr>
    <tr><td width="40%">ID инфоблока товаров</td><td><input type="number" name="PRODUCTS_IBLOCK_ID" value="<?= (int)Option::get($module_id, 'PRODUCTS_IBLOCK_ID', Option::get($module_id, 'PRODUCT_IBLOCK_ID', 0)) ?>"></td></tr>
    <tr><td>ID инфоблока торговых предложений</td><td><input type="number" name="OFFERS_IBLOCK_ID" value="<?= (int)Option::get($module_id, 'OFFERS_IBLOCK_ID', Option::get($module_id, 'SKU_IBLOCK_ID', 0)) ?>"></td></tr>
    <tr><td>Свойство конфигурации товара</td><td><input type="text" name="CALC_PROPERTY_CODE" value="<?= htmlspecialcharsbx(Option::get($module_id, 'CALC_PROPERTY_CODE', 'FRONTCALC_CONFIG')) ?>"></td></tr>
    <tr><td>AJAX endpoint</td><td><input type="text" size="55" name="CALC_AJAX_URL" value="<?= htmlspecialcharsbx(Option::get($module_id, 'CALC_AJAX_URL', '/local/ajax/frontcalc.php')) ?>"></td></tr>
    <tr><td>Единица отображения площади</td><td><select name="AREA_DISPLAY_UNIT"><option value="mm2"<?= Option::get($module_id, 'AREA_DISPLAY_UNIT', 'mm2') === 'mm2' ? ' selected' : '' ?>>мм²</option><option value="m2"<?= Option::get($module_id, 'AREA_DISPLAY_UNIT', 'mm2') === 'm2' ? ' selected' : '' ?>>м²</option></select></td></tr>
    <tr class="heading"><td colspan="2">Сервер расчёта и тиражи</td></tr>
    <tr><td>Таймаут запроса, секунд</td><td><input type="number" name="CALC_SERVER_TIMEOUT" value="<?= (int)Option::get($module_id, 'CALC_SERVER_TIMEOUT', 10) ?>" min="1"></td></tr>
    <tr><td>Максимум вариантов в batch</td><td><input type="number" name="CALC_SERVER_BATCH_LIMIT" value="<?= (int)Option::get($module_id, 'CALC_SERVER_BATCH_LIMIT', 200) ?>" min="1"></td></tr>
    <tr><td>Отладка в консоли браузера</td><td><input type="checkbox" name="CALC_SERVER_DEBUG_CONSOLE" value="Y"<?= Option::get($module_id, 'CALC_SERVER_DEBUG_CONSOLE', 'N') === 'Y' ? ' checked' : '' ?>></td></tr>
    <tr><td>ID служебного торгового предложения</td><td><input type="number" name="SERVICE_OFFER_ID" value="<?= (int)Option::get($module_id, 'SERVICE_OFFER_ID', 0) ?>"></td></tr>
    <tr><td>Сетка тиражей</td><td><textarea name="VOLUME_GRID_VALUES" cols="85" rows="3"><?= htmlspecialcharsbx(Option::get($module_id, 'VOLUME_GRID_VALUES', '')) ?></textarea></td></tr>
    <tr><td>Шаг после последнего тиража</td><td><input type="number" name="VOLUME_GRID_TAIL_STEP" value="<?= (int)Option::get($module_id, 'VOLUME_GRID_TAIL_STEP', 50000) ?>"></td></tr>
    <tr class="heading"><td colspan="2">Сценарии доступа</td></tr>
    <?php foreach (['restricted' => 'Ограниченный', 'verified' => 'Проверенный', 'extended' => 'Расширенный'] as $scenarioCode => $scenarioLabel): ?>
        <?php $scenarioGroups = json_decode((string)Option::get($module_id, 'access_scenarios.' . $scenarioCode . '.group_ids', '[]'), true); ?>
        <tr><td><?= $scenarioLabel ?>: ID групп</td><td><input type="text" size="55" name="ACCESS_SCENARIO_<?= strtoupper($scenarioCode) ?>_GROUP_IDS" value="<?= htmlspecialcharsbx(implode(',', is_array($scenarioGroups) ? $scenarioGroups : [])) ?>"></td></tr>
    <?php endforeach; ?>
    <tr><td>Ссылка «Подробнее» для ограниченного доступа</td><td><input type="text" size="75" name="ACCESS_SCENARIO_RESTRICTED_MORE_URL" value="<?= htmlspecialcharsbx(Option::get($module_id, 'access_scenarios.restricted.more_url', '')) ?>"></td></tr>
    <tr><td>Сообщение для мобильного устройства</td><td><textarea name="ACCESS_SCENARIO_RESTRICTED_MOBILE_MESSAGE" cols="85" rows="2"><?= htmlspecialcharsbx(Option::get($module_id, 'access_scenarios.restricted.mobile_message', '')) ?></textarea></td></tr>
    <tr><td>JSON-схема редактора</td><td><textarea name="CALC_EDITOR_SCHEMA" cols="100" rows="8"><?= htmlspecialcharsbx(Option::get($module_id, 'CALC_EDITOR_SCHEMA', '')) ?></textarea><br><small>Пустое значение включает схему по умолчанию.</small></td></tr>
    <tr class="heading"><td colspan="2">Тексты сроков</td></tr>
    <?php foreach (['DEADLINE_URGENT_TEXT' => 'Срочный', 'DEADLINE_STRICT_TEXT' => 'Строгий', 'DEADLINE_FLEXIBLE_TEXT' => 'Гибкий'] as $deadlineOption => $deadlineLabel): ?>
        <tr><td><?= $deadlineLabel ?></td><td><textarea name="<?= $deadlineOption ?>" cols="85" rows="2"><?= htmlspecialcharsbx(Option::get($module_id, $deadlineOption, '')) ?></textarea></td></tr>
    <?php endforeach; ?>

    <?php $tabControl->BeginNextTab(); ?>

    <tr class="heading"><td colspan="2">Описания значений свойств</td></tr>
    <tr><td width="40%">Функция включена</td><td><input type="checkbox" name="PROPERTY_VALUES_ENABLED" value="Y"<?= Option::get($module_id, 'ENABLED', 'Y') === 'Y' ? ' checked' : '' ?>></td></tr>
    <tr><td>HL-блок описаний</td><td>#<?= (int)Option::get($module_id, 'PROPERTY_DESCRIPTIONS_HL_BLOCK_ID', 0) ?></td></tr>
    <tr><td>Публичный JSON</td><td><code><?= htmlspecialcharsbx(Option::get($module_id, 'PROPERTY_DESCRIPTIONS_JSON_PATH', '—')) ?></code></td></tr>
    <tr><td>Редактор описаний</td><td><a class="adm-btn" href="/bitrix/admin/prospektweb_calc_property_values.php?lang=<?= LANGUAGE_ID ?>">Открыть редактор</a></td></tr>
    <tr class="heading"><td colspan="2">Торговые предложения</td></tr>
    <tr><td>Фильтр таблицы ТП</td><td>Подключается только на страницах редактирования товара и торговых предложений.</td></tr>
    <tr><td>AJAX-генератор ТП</td><td>Совместимый обработчик штатного генератора подключается автоматически.</td></tr>

    <?php $tabControl->BeginNextTab(); ?>

    <tr class="heading"><td colspan="2">Загрузка макетов</td></tr>
    <tr><td width="40%">Максимальный размер, байт</td><td><input type="number" name="max_size" value="<?= (int)Option::get($module_id, 'max_size', 104857600) ?>"></td></tr>
    <tr><td>Разрешённые расширения</td><td><input type="text" size="80" name="extensions" value="<?= htmlspecialcharsbx(Option::get($module_id, 'extensions', 'pdf,ai,eps,cdr,psd,tif,tiff,jpg,jpeg,png,zip,rar,7z')) ?>"></td></tr>
    <tr><td>Хранение временных файлов, часов</td><td><input type="number" name="temp_lifetime_hours" value="<?= (int)Option::get($module_id, 'temp_lifetime_hours', 24) ?>"></td></tr>
    <tr><td>Подсказка загрузки</td><td><textarea name="tooltip_text" cols="85" rows="3"><?= htmlspecialcharsbx(Option::get($module_id, 'tooltip_text', '')) ?></textarea></td></tr>
    <tr class="heading"><td colspan="2">Желаемая дата получения</td></tr>
    <tr><td>Минимальный задел, часов</td><td><input type="number" name="desired_receive_min_hours" value="<?= (int)Option::get($module_id, 'desired_receive_min_hours', 4) ?>"></td></tr>
    <tr><td>Рабочие дни</td><td><input type="text" name="desired_receive_workdays" value="<?= htmlspecialcharsbx(Option::get($module_id, 'desired_receive_workdays', '1,2,3,4,5')) ?>"></td></tr>
    <tr><td>Рабочее время</td><td><input type="text" name="desired_receive_time_from" value="<?= htmlspecialcharsbx(Option::get($module_id, 'desired_receive_time_from', '09:00')) ?>"> — <input type="text" name="desired_receive_time_to" value="<?= htmlspecialcharsbx(Option::get($module_id, 'desired_receive_time_to', '18:00')) ?>"></td></tr>
    <tr><td>Шаг времени, минут</td><td><input type="number" name="desired_receive_step_minutes" value="<?= (int)Option::get($module_id, 'desired_receive_step_minutes', 30) ?>"></td></tr>
    <tr><td>Время по умолчанию</td><td><input type="text" name="desired_receive_default_time" value="<?= htmlspecialcharsbx(Option::get($module_id, 'desired_receive_default_time', '11:00')) ?>"></td></tr>
    <tr><td>Праздничные даты</td><td><input type="text" size="80" name="desired_receive_holidays" value="<?= htmlspecialcharsbx(Option::get($module_id, 'desired_receive_holidays', '')) ?>"></td></tr>
    <tr><td>Свойство срока производства</td><td><input type="text" size="45" name="desired_receive_production_hours_property" value="<?= htmlspecialcharsbx(Option::get($module_id, 'desired_receive_production_hours_property', 'MIN_TIME_PRODUCTION_IN_WORK_HOURS')) ?>"></td></tr>
    <tr><td>Скрываемые свойства корзины</td><td><input type="text" size="80" name="hidden_basket_property_codes" value="<?= htmlspecialcharsbx(Option::get($module_id, 'hidden_basket_property_codes', '')) ?>"></td></tr>
    <tr><td>Подсказка желаемой даты</td><td><textarea name="desired_receive_tooltip_text" cols="85" rows="3"><?= htmlspecialcharsbx(Option::get($module_id, 'desired_receive_tooltip_text', '')) ?></textarea></td></tr>
    <tr class="heading"><td colspan="2">Яндекс.Диск</td></tr>
    <tr><td>Базовая папка</td><td><input type="text" size="60" name="base_folder" value="<?= htmlspecialcharsbx(Option::get($module_id, 'base_folder', '/')) ?>"></td></tr>
    <tr><td>OAuth Client ID</td><td><input type="text" size="60" name="yadisk_client_id" value="<?= htmlspecialcharsbx(Option::get($module_id, 'yadisk_client_id', '')) ?>"></td></tr>
    <tr><td>OAuth Client Secret</td><td><input type="password" size="60" name="yadisk_client_secret" value="" placeholder="Оставьте пустым, чтобы не менять"></td></tr>
    <tr><td>Подключение и проверка</td><td><a class="adm-btn" href="/bitrix/admin/prospektweb_calc_orders.php?lang=<?= LANGUAGE_ID ?>">Открыть расширенные настройки</a></td></tr>

    <?php $tabControl->BeginNextTab(); ?>

    <?php $consolidationStatus = (new ConsolidationManager())->status(); ?>
    <?php $templateInspection = (new TemplatePatchCoordinator())->inspect(); ?>
    <tr class="heading"><td colspan="2">Состояние объединения</td></tr>
    <tr><td width="40%">Версия миграции</td><td><?= htmlspecialcharsbx($consolidationStatus['version'] ?: 'не применена') ?></td></tr>
    <tr><td>Последнее применение</td><td><?= htmlspecialcharsbx($consolidationStatus['applied_at'] ?: '—') ?></td></tr>
    <tr><td>Управляемые wrappers</td><td><?= count($consolidationStatus['managed_files']) ?></td></tr>
    <tr><td></td><td><button type="submit" class="adm-btn adm-btn-save" name="APPLY_CONSOLIDATION" value="Y" onclick="return confirm('Создать резервные копии, перенести настройки и зарегистрировать объединённые обработчики?');">Применить безопасное обновление</button></td></tr>
    <tr><td></td><td><div class="adm-info-message">Старые модули не регистрируются и не удаляются. Значения существующих настроек <code>prospektweb.calc</code> имеют приоритет.</div></td></tr>
    <tr class="heading"><td colspan="2">Шаблон сайта</td></tr>
    <tr><td>Aspro prices.php</td><td><code><?= htmlspecialcharsbx($templateInspection['target']) ?></code></td></tr>
    <tr><td>Текущий SHA-256</td><td><code><?= htmlspecialcharsbx($templateInspection['target_hash'] ?: 'файл не найден') ?></code></td></tr>
    <tr><td>Подготовленный SHA-256</td><td><code><?= htmlspecialcharsbx($templateInspection['source_hash'] ?: 'файл не найден') ?></code></td></tr>
    <tr><td>Подтверждение версии</td><td><input type="text" size="72" name="APPROVED_ASPRO_PRICES_HASH" value="<?= htmlspecialcharsbx($templateInspection['target_hash']) ?>"><br><small>Перед применением сравните боевой файл с резервной копией. Изменение выполняется только при точном совпадении hash.</small></td></tr>
    <tr><td></td><td><button type="submit" class="adm-btn" name="APPLY_TEMPLATE_PATCHES" value="Y" onclick="return confirm('Создать резервные копии и применить согласованные изменения prices.php и шаблона корзины?');">Применить изменения шаблона</button></td></tr>

    <?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td colspan="2" style="padding: 16px;">
            <div style="margin-bottom: 16px;">
                <button type="button" id="btn-run-diagnostic" class="adm-btn adm-btn-save" onclick="pwCalcDiagRun()">
                    🔍 Запустить диагностику
                </button>
                &nbsp;
                <button type="button" id="btn-fix-events" class="adm-btn" onclick="pwCalcDiagFix('fix_events', 'Восстановить обработчики событий? Все текущие обработчики будут удалены и зарегистрированы заново.')">
                    🔧 Восстановить обработчики
                </button>
                &nbsp;
                <button type="button" id="btn-fix-files" class="adm-btn" onclick="pwCalcDiagFix('fix_files', 'Переустановить файлы модуля? Файлы будут скопированы заново из директории модуля.')">
                    📁 Переустановить файлы
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
        'disabled' => false,
        'back_url' => '/bitrix/admin/settings.php?lang=' . LANGUAGE_ID,
    ]);
    ?>

    <?php $tabControl->End(); ?>
</form>

<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
