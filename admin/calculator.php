<?php
/**
 * Страница калькулятора в админке Bitrix
 * Отображает iframe с React-калькулятором и автоматически инициализирует интеграцию
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Page\Asset;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Services\PresetProductAssignmentPropertyAuthorityService;

Loc::loadMessages(__FILE__);

// Проверка авторизации
global $USER, $APPLICATION;
if (!$USER->IsAuthorized()) {
    $APPLICATION->AuthForm(Loc::getMessage('PROSPEKTWEB_CALC_NOT_AUTHORIZED'));
    exit;
}

// Проверка прав доступа к каталогу
if (!$USER->CanDoOperation('edit_catalog')) {
    $APPLICATION->AuthForm(Loc::getMessage('PROSPEKTWEB_CALC_ACCESS_DENIED'));
    exit;
}

// Загрузка модуля
if (!Loader::includeModule('prospektweb.calc')) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    ShowError(Loc::getMessage('PROSPEKTWEB_CALC_MODULE_NOT_INSTALLED'));
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    die();
}

// The editor URL is a launch envelope, not data authority. Resolve every SKU
// against the configured catalog before initializing the React application.
$offerIdsRaw = is_string($_GET['offer_ids'] ?? null) ? (string)$_GET['offer_ids'] : '';
$presetIdRaw = is_string($_GET['preset_id'] ?? null) ? (string)$_GET['preset_id'] : '';
$controlCenterMode = (string)($_GET['control_center'] ?? '') === 'Y';
$versionId = is_string($_GET['version_id'] ?? null) && preg_match('/^v_[a-f0-9]{16,40}$/D', (string)$_GET['version_id'])
    ? (string)$_GET['version_id']
    : '';
$versionMode = in_array((string)($_GET['version_mode'] ?? ''), ['edit', 'readonly'], true)
    ? (string)$_GET['version_mode']
    : '';
$versionOriginalPresetId = is_string($_GET['original_preset_id'] ?? null)
    && preg_match('/^[1-9][0-9]*$/D', (string)$_GET['original_preset_id'])
    ? (int)$_GET['original_preset_id']
    : 0;
$versionContentHash = is_string($_GET['version_content_hash'] ?? null)
    && preg_match('/^[a-f0-9]{64}$/D', (string)$_GET['version_content_hash'])
    ? (string)$_GET['version_content_hash']
    : '';
$versionLogicHash = is_string($_GET['version_logic_hash'] ?? null)
    && preg_match('/^[a-f0-9]{64}$/D', (string)$_GET['version_logic_hash'])
    ? (string)$_GET['version_logic_hash']
    : '';
$editorInstanceId = is_string($_GET['editor_instance_id'] ?? null)
    && preg_match('/^[a-f0-9]{32}$/', (string)$_GET['editor_instance_id'])
        ? (string)$_GET['editor_instance_id']
        : '';
if ($controlCenterMode && !$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('PROSPEKTWEB_CALC_ACCESS_DENIED'));
    exit;
}
if (($versionId === '') !== ($versionMode === '')
    || (($versionId !== '' || $versionMode !== '') && !$controlCenterMode)
    || ($versionId !== '' && ($versionOriginalPresetId <= 0 || $versionContentHash === '' || $versionLogicHash === ''))) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    ShowError('Некорректный контекст версии калькулятора');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    die();
}
$offerIds = preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/', $offerIdsRaw)
    ? array_map('intval', explode(',', $offerIdsRaw))
    : [];
$uniqueOfferIds = array_values(array_unique($offerIds));
$standalonePresetId = preg_match('/^[1-9][0-9]*$/D', $presetIdRaw) === 1
    ? (int)$presetIdRaw
    : 0;
$isStandalonePresetLaunch = $controlCenterMode
    && $standalonePresetId > 0
    && $offerIdsRaw === '';
$isCatalogPresetLaunch = $controlCenterMode
    && $standalonePresetId > 0
    && $offerIdsRaw !== '';

if (($offerIdsRaw !== '' && (empty($offerIds) || count($offerIds) > 500 || count($uniqueOfferIds) !== count($offerIds)))
    || ($offerIdsRaw === '' && !$isStandalonePresetLaunch)
    || ($offerIdsRaw !== '' && $presetIdRaw !== '' && !$isCatalogPresetLaunch)) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    ShowError(Loc::getMessage('PROSPEKTWEB_CALC_NO_OFFERS_SELECTED'));
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    die();
}

$offerIds = $uniqueOfferIds;
$configManager = new ConfigManager();
$productIblockId = (int)$configManager->getProductIblockId();
$skuIblockId = (int)$configManager->getSkuIblockId();
$validatedProductId = 0;
$validatedProductIds = [];
$isValidLaunch = Loader::includeModule('iblock')
    && (!$controlCenterMode || $editorInstanceId !== '')
    && ($isStandalonePresetLaunch || ($productIblockId > 0 && $skuIblockId > 0));

if ($isValidLaunch && $isStandalonePresetLaunch) {
    $presetIblockId = (int)($configManager->getAllIblockIds()['CALC_PRESETS'] ?? 0);
    $presetFilter = ['ID' => $standalonePresetId];
    // A version workspace is already bound by its inactive trusted marker,
    // original calculator ID and immutable bundle hashes. Do not make editor
    // startup depend on catalog/offer configuration or an admin-context site
    // resolver. Ordinary standalone launches still require the configured
    // presets iblock.
    if ($versionId === '' && $presetIblockId > 0) {
        $presetFilter['IBLOCK_ID'] = $presetIblockId;
    }
    $validatedPreset = ($versionId !== '' || $presetIblockId > 0)
        ? \CIBlockElement::GetList(
            [],
            $presetFilter,
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID', 'CODE', 'ACTIVE']
        )->Fetch()
        : false;
    $isValidLaunch = is_array($validatedPreset);
    if ($isValidLaunch && $versionMode === 'readonly') {
        $isValidLaunch = $standalonePresetId === $versionOriginalPresetId
            && (string)($validatedPreset['ACTIVE'] ?? 'N') === 'Y';
    } elseif ($isValidLaunch && $versionMode === 'edit') {
        $expectedWorkingPrefix = \Prospektweb\Calc\Services\PresetLifecycleMutationService::VERSION_WORKING_CODE_PREFIX
            . $versionOriginalPresetId . '-'
            . str_replace('_', '-', strtolower($versionId)) . '-';
        $isValidLaunch = (string)($validatedPreset['ACTIVE'] ?? 'Y') === 'N'
            && str_starts_with((string)($validatedPreset['CODE'] ?? ''), $expectedWorkingPrefix);
    } elseif ($isValidLaunch) {
        $isValidLaunch = (string)($validatedPreset['ACTIVE'] ?? 'N') === 'Y';
    }
}

if ($isValidLaunch && !$isStandalonePresetLaunch) {
    $foundOfferIds = [];
    $offerFilter = [
        'IBLOCK_ID' => $skuIblockId,
        'ID' => $offerIds,
    ];
    if ($controlCenterMode) {
        $offerFilter['ACTIVE'] = 'Y';
        $offerFilter['ACTIVE_DATE'] = 'Y';
    }
    $offerCursor = \CIBlockElement::GetList(
        ['ID' => 'ASC'],
        $offerFilter,
        false,
        false,
        ['ID', 'PROPERTY_CML2_LINK']
    );
    while ($offer = $offerCursor->Fetch()) {
        $offerId = (int)($offer['ID'] ?? 0);
        $parentProductId = (int)($offer['PROPERTY_CML2_LINK_VALUE'] ?? 0);
        if ($offerId <= 0 || $parentProductId <= 0) {
            $isValidLaunch = false;
            break;
        }
        if (!$isCatalogPresetLaunch
            && $validatedProductId > 0
            && $validatedProductId !== $parentProductId) {
            $isValidLaunch = false;
            break;
        }
        if ($validatedProductId <= 0) {
            $validatedProductId = $parentProductId;
        }
        $validatedProductIds[$parentProductId] = true;
        $foundOfferIds[$offerId] = true;
    }

    if (count($foundOfferIds) !== count($offerIds)) {
        $isValidLaunch = false;
    }
    foreach ($offerIds as $offerId) {
        if (!isset($foundOfferIds[$offerId])) {
            $isValidLaunch = false;
            break;
        }
    }
}

$validatedProducts = [];
if ($isValidLaunch && $validatedProductIds !== []) {
    $productFilter = [
        'ID' => array_map('intval', array_keys($validatedProductIds)),
        'IBLOCK_ID' => $productIblockId,
    ];
    if ($controlCenterMode) {
        $productFilter['ACTIVE'] = 'Y';
        $productFilter['ACTIVE_DATE'] = 'Y';
    }
    $validatedProductCursor = \CIBlockElement::GetList(
        [],
        $productFilter,
        false,
        false,
        ['ID', 'IBLOCK_ID']
    );
    while ($validatedProduct = $validatedProductCursor->Fetch()) {
        $productId = (int)($validatedProduct['ID'] ?? 0);
        if ($productId > 0) {
            $validatedProducts[$productId] = true;
        }
    }
    $isValidLaunch = count($validatedProducts) === count($validatedProductIds);
}

if ($isValidLaunch && $controlCenterMode && !$isStandalonePresetLaunch) {
    try {
        $presetIblockId = (int)$configManager->getIblockId('CALC_PRESETS');
        $calcPresetPropertyId = (int)(new PresetProductAssignmentPropertyAuthorityService())
            ->resolve($productIblockId, $presetIblockId)['propertyId'];
    } catch (\Throwable $error) {
        $calcPresetPropertyId = 0;
        $isValidLaunch = false;
    }
    foreach (array_keys($validatedProductIds) as $productId) {
        if (!$isValidLaunch) {
            break;
        }
        $hasSelectedPreset = false;
        $presetCursor = \CIBlockElement::GetProperty(
            $productIblockId,
            (int)$productId,
            ['ID' => 'ASC'],
            ['ID' => $calcPresetPropertyId]
        );
        while ($presetProperty = $presetCursor->Fetch()) {
            if ((int)($presetProperty['VALUE'] ?? 0) === $standalonePresetId) {
                $hasSelectedPreset = true;
                break;
            }
        }
        if (!$hasSelectedPreset) {
            $isValidLaunch = false;
            break;
        }
    }
}

if (!$isValidLaunch) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    ShowError($isStandalonePresetLaunch
        ? 'Некорректный контекст пресета для редактора калькуляций'
        : 'Некорректный набор торговых предложений для редактора калькуляций');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    die();
}

// Standalone page close target: return to the product that owns the selected SKUs.
// The regular CAdminDialog flow does not use this page and keeps its native close behavior.
$returnProductUrl = '';
$productIblock = \CIBlock::GetByID($productIblockId)->Fetch();
$productIblockType = trim((string)($productIblock['IBLOCK_TYPE_ID'] ?? ''));
if ($productIblockType !== '') {
    $returnProductUrl = '/bitrix/admin/iblock_element_edit.php?' . http_build_query([
        'IBLOCK_ID' => $productIblockId,
        'type' => $productIblockType,
        'lang' => defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru',
        'ID' => $validatedProductId,
        'find_section_section' => 0,
        'WF' => 'Y',
    ]);
}

// Заголовок страницы
$APPLICATION->SetTitle(Loc::getMessage('PROSPEKTWEB_CALC_PAGE_TITLE'));
$appIndexPath = $_SERVER['DOCUMENT_ROOT'] . '/local/apps/prospektweb.calc/index.html';
$appVersion = is_file($appIndexPath) ? (string)filemtime($appIndexPath) : '1';
$appIframeQuery = ['v' => $appVersion];
if ($versionId !== '' && $versionContentHash !== '') {
    $appIframeQuery['version_id'] = $versionId;
    $appIframeQuery['version_content_hash'] = $versionContentHash;
    if ($versionOriginalPresetId > 0) {
        $appIframeQuery['original_preset_id'] = $versionOriginalPresetId;
    }
}
if (isset($_GET['open_calculation_panel']) && (string)$_GET['open_calculation_panel'] === 'Y') {
    $appIframeQuery['open_calculation_panel'] = 'Y';
}
$appIframeUrl = '/local/apps/prospektweb.calc/index.html?' . http_build_query($appIframeQuery);

// Подключение JS интеграции с cache key фактически развёрнутого файла.
$integrationPath = '/local/js/prospektweb.calc/integration.js';
$integrationFile = Application::getDocumentRoot() . $integrationPath;
$integrationVersion = is_file($integrationFile) ? (string)filemtime($integrationFile) : '1';
Asset::getInstance()->addJs($integrationPath . '?v=' . $integrationVersion);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
?>

<style>
/* Стили для полноэкранного отображения iframe */
html,
body {
    width: 100% !important;
    height: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
}

.prospektweb-calc-page {
    margin: 0;
    padding: 0;
}

#calc-container {
    position: fixed;
    inset: 0;
    z-index: 2147483647;
    width: 100vw;
    height: 100vh;
    background: #f5f5f5;
    isolation: isolate;
}

#calc-iframe {
    width: 100%;
    height: 100%;
    border: none;
    display: block;
}

/* Скрываем стандартные элементы админки для чистого вида */
.prospektweb-calc-page .adm-workarea {
    padding: 0 !important;
}
</style>

<div class="prospektweb-calc-page">
<!-- HTML с iframe -->
<div id="calc-container">
    <iframe 
        id="calc-iframe" 
        src="<?= htmlspecialcharsbx($appIframeUrl) ?>"
        title="<?= Loc::getMessage('PROSPEKTWEB_CALC_IFRAME_TITLE') ?>">
    </iframe>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Move the standalone editor above Bitrix workarea stacking contexts.
    // A high z-index alone is insufficient while the node remains inside adm-workarea.
    var container = document.getElementById('calc-container');
    if (container && container.parentNode !== document.body) {
        document.body.appendChild(container);
    }

    // КЛЮЧЕВОЙ КОД: создание экземпляра интеграции
    var integration = new ProspektwebCalcIntegration({
        iframeSelector: '#calc-iframe',
        ajaxEndpoint: '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
        versionAjaxEndpoint: '/bitrix/tools/prospektweb.calc/control_center_editors.php',
        offerIds: <?= json_encode($offerIds) ?>,
        presetId: <?= json_encode(($isStandalonePresetLaunch || $isCatalogPresetLaunch) ? $standalonePresetId : 0) ?>,
        versionId: <?= json_encode($versionId) ?>,
        versionMode: <?= json_encode($versionMode) ?>,
        versionOriginalPresetId: <?= json_encode($versionOriginalPresetId) ?>,
        versionContentHash: <?= json_encode($versionContentHash) ?>,
        versionLogicHash: <?= json_encode($versionLogicHash) ?>,
        editorInstanceId: <?= json_encode($editorInstanceId) ?>,
        siteId: '<?= SITE_ID ?>',
        sessid: '<?= bitrix_sessid() ?>',
        onClose: function() {
            var controlCenterMode = <?= json_encode($controlCenterMode) ?>;
            var editorInstanceId = <?= json_encode($editorInstanceId) ?>;
            if (controlCenterMode && editorInstanceId && window.parent !== window) {
                window.parent.postMessage({
                    protocol: 'pwrt-v1',
                    source: 'prospektweb.calc',
                    target: 'bitrix',
                    type: 'CLOSE_CONTROL_CENTER_EDITOR',
                    payload: {editorInstanceId: editorInstanceId},
                    timestamp: Date.now()
                }, window.location.origin);
                return;
            }

            // Regular popup/tab flow closes the auxiliary window. When the same URL
            // is opened in the current admin tab, navigate back to the owning product.
            if (window.opener && !window.opener.closed) {
                window.close();
                return;
            }

            var returnProductUrl = <?= json_encode($returnProductUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
            if (returnProductUrl) {
                window.location.assign(returnProductUrl);
                return;
            }

            window.history.back();
        },
        onError: function(error) {
            console.error('Calc error:', error);
            var message = error.message || '<?= Loc::getMessage('PROSPEKTWEB_CALC_UNKNOWN_ERROR') ?>';
            alert('<?= Loc::getMessage('PROSPEKTWEB_CALC_ERROR_PREFIX') ?>' + message);
        }
    });

    // Логирование для отладки
    console.log('[Calculator Page] Integration initialized', {
        presetId: <?= json_encode(($isStandalonePresetLaunch || $isCatalogPresetLaunch) ? $standalonePresetId : 0) ?>,
        offerIds: <?= json_encode($offerIds) ?>
    });
});
</script>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'); ?>
