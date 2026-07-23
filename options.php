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
use Prospektweb\Calc\Services\AsproAiPatchManager;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && isset($_POST['ASPRO_AI_PATCH_ACTION'])) {
    $patchAction = (string)$_POST['ASPRO_AI_PATCH_ACTION'];
    $timewebEnabled = (($_POST['ASPRO_AI_TIMEWEB_ENABLED'] ?? 'N') === 'Y') ? 'Y' : 'N';
    $timewebBaseUrl = trim((string)($_POST['ASPRO_AI_TIMEWEB_BASE_URL'] ?? 'https://api.timeweb.ai/v1'));
    if ($timewebBaseUrl === '') {
        $timewebBaseUrl = 'https://api.timeweb.ai/v1';
    }

    $parsedBaseUrl = parse_url($timewebBaseUrl);
    $isValidBaseUrl = is_array($parsedBaseUrl)
        && strtolower((string)($parsedBaseUrl['scheme'] ?? '')) === 'https'
        && (string)($parsedBaseUrl['host'] ?? '') !== ''
        && empty($parsedBaseUrl['user'])
        && empty($parsedBaseUrl['pass'])
        && empty($parsedBaseUrl['query'])
        && empty($parsedBaseUrl['fragment']);

    try {
        if (!$isValidBaseUrl) {
            throw new \RuntimeException('Base URL должен быть корректным HTTPS-адресом без логина, пароля, query и fragment.');
        }

        Option::set($module_id, 'ASPRO_AI_TIMEWEB_ENABLED', $timewebEnabled);
        Option::set($module_id, 'ASPRO_AI_TIMEWEB_BASE_URL', rtrim($timewebBaseUrl, '/'));

        $patchManager = new AsproAiPatchManager();
        if ($patchAction === 'apply') {
            $patchResult = $patchManager->apply();
        } elseif ($patchAction === 'remove') {
            $patchResult = $patchManager->remove();
        } else {
            throw new \RuntimeException('Неизвестное действие менеджера патча.');
        }

        $_SESSION['PROSPEKTWEB_CALC_ASPRO_PATCH_FLASH'] = [
            'TYPE' => 'OK',
            'MESSAGE' => (string)($patchResult['message'] ?? 'Действие выполнено.'),
        ];
    } catch (\Throwable $exception) {
        $_SESSION['PROSPEKTWEB_CALC_ASPRO_PATCH_FLASH'] = [
            'TYPE' => 'ERROR',
            'MESSAGE' => $exception->getMessage(),
        ];
    }

    LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($module_id) . '&lang=' . LANGUAGE_ID . '&asproPatch=Y');
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
    Option::set($module_id, 'ASPRO_AI_TIMEWEB_ENABLED', (($_POST['ASPRO_AI_TIMEWEB_ENABLED'] ?? 'N') === 'Y') ? 'Y' : 'N');

    $timewebBaseUrl = trim((string)($_POST['ASPRO_AI_TIMEWEB_BASE_URL'] ?? 'https://api.timeweb.ai/v1'));
    $parsedBaseUrl = parse_url($timewebBaseUrl);
    if (
        is_array($parsedBaseUrl)
        && strtolower((string)($parsedBaseUrl['scheme'] ?? '')) === 'https'
        && (string)($parsedBaseUrl['host'] ?? '') !== ''
        && empty($parsedBaseUrl['user'])
        && empty($parsedBaseUrl['pass'])
        && empty($parsedBaseUrl['query'])
        && empty($parsedBaseUrl['fragment'])
    ) {
        Option::set($module_id, 'ASPRO_AI_TIMEWEB_BASE_URL', rtrim($timewebBaseUrl, '/'));
    }

    // Сохраняем настройки связей ТП
    Option::set($module_id, 'FORMAT_FIELD_CODE', (string)($_POST['FORMAT_FIELD_CODE'] ?? 'FORMAT'));
    Option::set($module_id, 'VOLUME_FIELD_CODE', (string)($_POST['VOLUME_FIELD_CODE'] ?? 'VOLUME'));

    // Сохраняем настройки округления цен
    Option::set($module_id, 'PRICE_ROUNDING', (float)($_POST['PRICE_ROUNDING'] ?? 1));

    // Сохраняем настройки истории расчётов
    Option::set($module_id, 'SAVE_CALC_HISTORY', (($_POST['SAVE_CALC_HISTORY'] ?? 'N') === 'Y') ? 'Y' : 'N');
    Option::set($module_id, 'CALC_HISTORY_LIMIT', (int)($_POST['CALC_HISTORY_LIMIT'] ?? 10));

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

$asproAiPatchStatus = [
    'state' => 'access_error',
    'message' => 'Не удалось получить состояние патча.',
    'canApply' => false,
    'canRemove' => false,
    'asproVersion' => '',
    'patchVersion' => AsproAiPatchManager::PATCH_VERSION,
];
try {
    $asproAiPatchStatus = (new AsproAiPatchManager())->getStatus();
} catch (\Throwable $exception) {
    $asproAiPatchStatus['message'] = $exception->getMessage();
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

// Вывод сообщения об успешном сохранении
if (($_GET['saved'] ?? '') === 'Y') {
    CAdminMessage::ShowMessage([
        'MESSAGE' => Loc::getMessage('PROSPEKTWEB_CALC_SETTINGS_SAVED'),
        'TYPE' => 'OK',
    ]);
}

if (($_GET['asproPatch'] ?? '') === 'Y' && isset($_SESSION['PROSPEKTWEB_CALC_ASPRO_PATCH_FLASH'])) {
    $patchFlash = (array)$_SESSION['PROSPEKTWEB_CALC_ASPRO_PATCH_FLASH'];
    unset($_SESSION['PROSPEKTWEB_CALC_ASPRO_PATCH_FLASH']);
    CAdminMessage::ShowMessage([
        'MESSAGE' => htmlspecialcharsbx((string)($patchFlash['MESSAGE'] ?? '')),
        'TYPE' => (($patchFlash['TYPE'] ?? '') === 'OK') ? 'OK' : 'ERROR',
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

    <tr class="heading">
        <td colspan="2">Timeweb Cloud AI Gateway для «Аспро: AI»</td>
    </tr>

    <tr>
        <td width="40%">Использовать Timeweb Cloud AI Gateway</td>
        <td width="60%">
            <input type="hidden" name="ASPRO_AI_TIMEWEB_ENABLED" value="N">
            <label>
                <input
                    type="checkbox"
                    name="ASPRO_AI_TIMEWEB_ENABLED"
                    value="Y"
                    <?= Option::get($module_id, 'ASPRO_AI_TIMEWEB_ENABLED', 'N') === 'Y' ? 'checked' : '' ?>
                >
                Направлять штатные текстовые запросы «Аспро: AI» через Timeweb
            </label>
        </td>
    </tr>

    <tr>
        <td>Base URL</td>
        <td>
            <input
                type="url"
                name="ASPRO_AI_TIMEWEB_BASE_URL"
                value="<?= htmlspecialcharsbx(Option::get($module_id, 'ASPRO_AI_TIMEWEB_BASE_URL', 'https://api.timeweb.ai/v1')) ?>"
                size="60"
                style="width: 460px;"
            >
            <br><span style="color:#777;font-size:11px;">По умолчанию: https://api.timeweb.ai/v1. API-ключ остаётся в штатных настройках «Аспро: AI».</span>
        </td>
    </tr>

    <tr>
        <td>Состояние управляемого патча</td>
        <td>
            <div style="max-width:760px;padding:12px 14px;border:1px solid #d5d9de;border-radius:4px;background:#fff;">
                <div style="font-weight:600;margin-bottom:6px;"><?= htmlspecialcharsbx((string)$asproAiPatchStatus['message']) ?></div>
                <div style="color:#666;font-size:12px;line-height:1.5;">
                    Состояние: <code><?= htmlspecialcharsbx((string)$asproAiPatchStatus['state']) ?></code>;
                    «Аспро: AI»: <code><?= htmlspecialcharsbx((string)($asproAiPatchStatus['asproVersion'] ?: 'не определена')) ?></code>;
                    патч: <code><?= htmlspecialcharsbx((string)$asproAiPatchStatus['patchVersion']) ?></code>.
                </div>
            </div>
            <div class="adm-info-message" style="max-width:760px;margin-top:10px;">
                После обновления шаблона рекомендуется повторно установить патч.
            </div>
            <div style="max-width:760px;margin:10px 0;color:#777;font-size:12px;line-height:1.5;">
                Патч направляет штатный ChatGPT-сервис через заданный Base URL и выбирает OpenAI ChatGPT по умолчанию во всех формах генерации.
                Текстовые запросы и список моделей используют совместимые endpoints.
                Совместимость штатной генерации изображений с прямым Timeweb AI Gateway не подтверждена и этим патчем не гарантируется.
            </div>
            <button
                type="submit"
                class="adm-btn adm-btn-save"
                name="ASPRO_AI_PATCH_ACTION"
                value="apply"
                title="Добавляет поддержку Timeweb Cloud AI Gateway в модуль «Аспро: AI». После обновления Аспро патч может потребовать повторной установки."
                <?= empty($asproAiPatchStatus['canApply']) && ($asproAiPatchStatus['state'] ?? '') !== 'installed' ? 'disabled' : '' ?>
            >Патч AI-интеграции Аспро</button>
            <?php if (!empty($asproAiPatchStatus['canRemove'])): ?>
                &nbsp;
                <button
                    type="submit"
                    class="adm-btn"
                    name="ASPRO_AI_PATCH_ACTION"
                    value="remove"
                    onclick="return confirm('Снять управляемый патч и восстановить подтверждённую резервную копию?');"
                >Снять патч</button>
            <?php endif; ?>
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
                <button type="button" id="btn-fix-events" class="adm-btn" onclick="pwCalcDiagFix('fix_events', 'Восстановить обработчики событий? Все текущие обработчики будут удалены и зарегистрированы заново.')">
                    🔧 Восстановить обработчики
                </button>
                &nbsp;
                <button type="button" id="btn-fix-files" class="adm-btn" onclick="pwCalcDiagFix('fix_files', 'Переустановить файлы модуля? Файлы будут скопированы заново из директории модуля.')">
                    📁 Переустановить файлы
                </button>
                &nbsp;
                <button type="button" id="btn-fix-schema" class="adm-btn" onclick="pwCalcDiagFix('fix_schema', 'Проверить структуру данных и создать отсутствующие свойства? Существующие свойства и данные изменены не будут.')">
                    🧱 Восстановить структуру
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
