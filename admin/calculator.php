<?php
/**
 * Страница калькулятора в админке Bitrix
 * Отображает iframe с React-калькулятором и автоматически инициализирует интеграцию
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Page\Asset;

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

// Получение offer_ids из GET
$offerIdsRaw = $_GET['offer_ids'] ?? '';
$offerIds = array_filter(array_map('intval', explode(',', $offerIdsRaw)));

if (empty($offerIds)) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    ShowError(Loc::getMessage('PROSPEKTWEB_CALC_NO_OFFERS_SELECTED'));
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    die();
}

// Standalone page close target: return to the product that owns the selected SKUs.
// The regular CAdminDialog flow does not use this page and keeps its native close behavior.
$returnProductUrl = '';
if (Loader::includeModule('catalog') && Loader::includeModule('iblock')) {
    foreach ($offerIds as $offerId) {
        $productInfo = \CCatalogSku::GetProductInfo((int)$offerId);
        $productId = (int)($productInfo['ID'] ?? 0);
        $productIblockId = (int)($productInfo['IBLOCK_ID'] ?? 0);
        if ($productId <= 0 || $productIblockId <= 0) {
            continue;
        }

        $productIblock = \CIBlock::GetByID($productIblockId)->Fetch();
        $productIblockType = trim((string)($productIblock['IBLOCK_TYPE_ID'] ?? ''));
        if ($productIblockType === '') {
            continue;
        }

        $returnProductUrl = '/bitrix/admin/iblock_element_edit.php?' . http_build_query([
            'IBLOCK_ID' => $productIblockId,
            'type' => $productIblockType,
            'lang' => defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru',
            'ID' => $productId,
            'find_section_section' => 0,
            'WF' => 'Y',
        ]);
        break;
    }
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
        src="/local/apps/prospektweb.calc/index.html?v=14f6d20b9519"
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
