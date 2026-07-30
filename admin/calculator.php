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

// Заголовок страницы
$APPLICATION->SetTitle(Loc::getMessage('PROSPEKTWEB_CALC_PAGE_TITLE'));

// Подключение JS интеграции
Asset::getInstance()->addJs('/local/js/prospektweb.calc/integration.js');

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
?>

<style>
/* Стили для полноэкранного отображения iframe */
.prospektweb-calc-page {
    margin: 0;
    padding: 0;
}

.prospektweb-calc-page #calc-container {
    position: fixed;
    top: 90px; /* Отступ для административного меню Bitrix */
    left: 0;
    right: 0;
    bottom: 0;
    width: 100%;
    height: calc(100% - 90px);
    background: #f5f5f5;
}

.prospektweb-calc-page #calc-iframe {
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
        src="/local/apps/prospektweb.calc/index.html?v=a99a1856cd09"
        title="<?= Loc::getMessage('PROSPEKTWEB_CALC_IFRAME_TITLE') ?>">
    </iframe>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // КЛЮЧЕВОЙ КОД: создание экземпляра интеграции
    var integration = new ProspektwebCalcIntegration({
        iframeSelector: '#calc-iframe',
        ajaxEndpoint: '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
        offerIds: <?= json_encode($offerIds) ?>,
        siteId: '<?= SITE_ID ?>',
        sessid: '<?= bitrix_sessid() ?>',
        onClose: function() {
            // При закрытии калькулятора возвращаемся к списку товаров
            window.close();
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
