<?php
/**
 * Central PROSPEKT-WEB administration workspace.
 *
 * The iframe is intentionally independent from a selected product or offer.
 * Contextual calculator entry points continue to use calculator.php.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;

global $USER, $APPLICATION;

if (!$USER || !$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ запрещён');
    exit;
}

if (!Loader::includeModule('prospektweb.calc')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    ShowError('Модуль prospektweb.calc не установлен');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    exit;
}

$APPLICATION->SetTitle('ПРОСПЕКТ — центр управления');

$languageId = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$languageId = preg_replace('/[^a-z]/i', '', $languageId) ?: 'ru';
$configManager = new ConfigManager();
$settingsUrl = '/bitrix/admin/settings.php?' . http_build_query([
    'lang' => $languageId,
    'mid' => 'prospektweb.calc',
    'mid_menu' => 1,
]);
$diagnosticsUrl = '/bitrix/admin/settings.php?' . http_build_query([
    'lang' => $languageId,
    'mid' => 'prospektweb.calc',
    'mid_menu' => 1,
    'tabControl_active_tab' => 'edit5',
]);

$resolveIblockType = static function (int $iblockId, string $fallback): string {
    if ($iblockId <= 0 || !Loader::includeModule('iblock')) {
        return $fallback;
    }

    $iblock = \CIBlock::GetByID($iblockId)->Fetch();
    $type = trim((string)($iblock['IBLOCK_TYPE_ID'] ?? ''));

    return $type !== '' ? $type : $fallback;
};

$buildIblockListUrl = static function (int $iblockId, string $fallbackType) use (
    $languageId,
    $settingsUrl,
    $resolveIblockType
): string {
    if ($iblockId <= 0) {
        return $settingsUrl;
    }

    return '/bitrix/admin/iblock_list_admin.php?' . http_build_query([
        'IBLOCK_ID' => $iblockId,
        'type' => $resolveIblockType($iblockId, $fallbackType),
        'lang' => $languageId,
        'find_section_section' => 0,
    ]);
};

$presetsUrl = $buildIblockListUrl($configManager->getIblockId('CALC_PRESETS'), 'calculator');
$productsUrl = $buildIblockListUrl($configManager->getProductIblockId(), 'catalog');

// This is the complete navigation authority for the control-center iframe.
// The iframe sends a route key only; it never supplies a URL.
$routeMap = [
    'presets' => $presetsUrl,
    'products' => $productsUrl,
    'storefront-calculators' => $productsUrl,
    'batch-recalculation' => '/bitrix/admin/prospektweb_calc_recalculate.php?' . http_build_query([
        'lang' => $languageId,
    ]),
    'directories' => '/bitrix/admin/iblock_admin.php?' . http_build_query([
        'type' => 'calculator_catalog',
        'lang' => $languageId,
        'admin' => 'Y',
    ]),
    'diagnostics' => $diagnosticsUrl,
    'settings' => $settingsUrl,
];

$allowedAdminPaths = [
    '/bitrix/admin/iblock_admin.php',
    '/bitrix/admin/iblock_list_admin.php',
    '/bitrix/admin/prospektweb_calc_recalculate.php',
    '/bitrix/admin/settings.php',
];

$appIndexPath = $_SERVER['DOCUMENT_ROOT'] . '/local/apps/prospektweb.calc/index.html';
$appVersion = is_file($appIndexPath) ? (string)filemtime($appIndexPath) : '1';
$iframeUrl = '/local/apps/prospektweb.calc/index.html?' . http_build_query([
    'mode' => 'control-center',
    'v' => $appVersion,
]);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>

<style>
html,
body {
    width: 100% !important;
    height: 100% !important;
    margin: 0 !important;
    overflow: hidden !important;
}

.adm-workarea {
    padding: 0 !important;
}

#adm-title,
.adm-title {
    display: none !important;
}

#prospektweb-control-center {
    position: relative;
    width: 100%;
    min-height: 0;
    background: #0b121a;
    isolation: isolate;
}

#prospektweb-control-center-iframe {
    display: block;
    width: 100%;
    height: 100%;
    border: 0;
}
</style>

<div id="prospektweb-control-center">
    <iframe
        id="prospektweb-control-center-iframe"
        src="<?= htmlspecialcharsbx($iframeUrl) ?>"
        title="ПРОСПЕКТ — центр управления"
        referrerpolicy="same-origin"
    ></iframe>
</div>

<script>
(function () {
    'use strict';

    var container = document.getElementById('prospektweb-control-center');
    var iframe = document.getElementById('prospektweb-control-center-iframe');
    var routeMap = <?= json_encode($routeMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var allowedAdminPaths = <?= json_encode($allowedAdminPaths, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function resizeControlCenter() {
        if (!container) {
            return;
        }

        var rect = container.getBoundingClientRect();
        var availableHeight = Math.max(1, window.innerHeight - Math.max(0, rect.top));
        container.style.height = availableHeight + 'px';
    }

    resizeControlCenter();
    window.addEventListener('resize', resizeControlCenter);

    window.addEventListener('message', function (event) {
        if (!iframe || event.source !== iframe.contentWindow || event.origin !== window.location.origin) {
            return;
        }

        var message = event.data;
        if (!message || typeof message !== 'object'
            || message.protocol !== 'pwrt-v1'
            || message.source !== 'prospektweb.calc'
            || message.target !== 'bitrix'
            || message.type !== 'OPEN_ADMIN_URL') {
            return;
        }

        var route = message.payload && typeof message.payload.route === 'string'
            ? message.payload.route
            : '';
        if (!Object.prototype.hasOwnProperty.call(routeMap, route)) {
            return;
        }

        var targetUrl;
        try {
            targetUrl = new URL(routeMap[route], window.location.origin);
        } catch (error) {
            return;
        }

        if (targetUrl.origin !== window.location.origin
            || allowedAdminPaths.indexOf(targetUrl.pathname) === -1) {
            return;
        }

        window.location.assign(targetUrl.pathname + targetUrl.search);
    });
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
