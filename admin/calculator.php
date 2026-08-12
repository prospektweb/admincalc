<?php
/**
 * Страница калькулятора в админке Bitrix
 * Отображает iframe с React-калькулятором и автоматически инициализирует интеграцию
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Page\Asset;
use Prospektweb\Calc\Config\ConfigManager;

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
$controlCenterMode = (string)($_GET['control_center'] ?? '') === 'Y';
$editorInstanceId = is_string($_GET['editor_instance_id'] ?? null)
    && preg_match('/^[a-f0-9]{32}$/', (string)$_GET['editor_instance_id'])
        ? (string)$_GET['editor_instance_id']
        : '';
if ($controlCenterMode && !$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('PROSPEKTWEB_CALC_ACCESS_DENIED'));
    exit;
}
$offerIds = preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/', $offerIdsRaw)
    ? array_map('intval', explode(',', $offerIdsRaw))
    : [];
$uniqueOfferIds = array_values(array_unique($offerIds));

if (empty($offerIds) || count($offerIds) > 500 || count($uniqueOfferIds) !== count($offerIds)) {
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
$isValidLaunch = $productIblockId > 0
    && $skuIblockId > 0
    && Loader::includeModule('iblock')
    && (!$controlCenterMode || $editorInstanceId !== '');

if ($isValidLaunch) {
    $foundOfferIds = [];
    $offerFilter = [
        'IBLOCK_ID' => $skuIblockId,
        'ID' => $offerIds,
    ];
    if ($controlCenterMode) {
        $offerFilter['ACTIVE'] = 'Y';
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
        if ($validatedProductId > 0 && $validatedProductId !== $parentProductId) {
            $isValidLaunch = false;
            break;
        }
        $validatedProductId = $parentProductId;
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

$validatedProduct = null;
if ($isValidLaunch && $validatedProductId > 0) {
    $productFilter = [
        'ID' => $validatedProductId,
        'IBLOCK_ID' => $productIblockId,
    ];
    if ($controlCenterMode) {
        $productFilter['ACTIVE'] = 'Y';
    }
    $validatedProduct = \CIBlockElement::GetList(
        [],
        $productFilter,
        false,
        false,
        ['ID', 'IBLOCK_ID']
    )->Fetch();
    $isValidLaunch = is_array($validatedProduct);
}

if ($isValidLaunch && $controlCenterMode) {
    $hasFocusPreset = false;
    $presetCursor = \CIBlockElement::GetProperty(
        $productIblockId,
        $validatedProductId,
        ['ID' => 'ASC'],
        ['CODE' => 'CALC_PRESET']
    );
    while ($presetProperty = $presetCursor->Fetch()) {
        if ((int)($presetProperty['VALUE'] ?? 0) === 12740) {
            $hasFocusPreset = true;
            break;
        }
    }
    $isValidLaunch = $hasFocusPreset;
}

if (!$isValidLaunch) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    ShowError('Некорректный набор торговых предложений для редактора калькуляций');
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

// Подключение JS интеграции
Asset::getInstance()->addJs('/local/js/prospektweb.calc/integration.js');

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
        src="/local/apps/prospektweb.calc/index.html?v=33ec9378dd7a"
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
        offerIds: <?= json_encode($offerIds) ?>,
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
    console.log('[Calculator Page] Integration initialized with offer IDs:', <?= json_encode($offerIds) ?>);
});
</script>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'); ?>
