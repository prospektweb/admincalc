<?php

$root = dirname(__DIR__);
$handler = file_get_contents($root . '/lib/Handlers/AdminHandler.php');
$page = file_get_contents($root . '/admin/prospektweb_calc_control_center.php');
$installer = file_get_contents($root . '/install/index.php');
$diagnosticTool = file_get_contents($root . '/tools/diagnostic.php');
$moduleDiagnostic = file_get_contents($root . '/lib/Diagnostic/ModuleDiagnostic.php');
$contextualCalculator = file_get_contents($root . '/install/assets/js/calculator.js');
$contextualGenerator = file_get_contents($root . '/install/assets/js/product_generator.js');
$appIndex = file_get_contents($root . '/install/assets/apps_dist/index.html');
$appBundle = file_get_contents($root . '/install/assets/apps_dist/assets/index.js');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(strpos($handler, "['global_menu_prospektweb']") !== false, 'A dedicated PROSPEKT global menu is registered');
$assert(strpos($handler, "'text' => 'ПРОСПЕКТ'") !== false, 'The global menu has the requested Russian label');
$assert(strpos($handler, "'parent_menu' => 'global_menu_prospektweb'") !== false, 'The calculator module menu is attached to the PROSPEKT global menu');
$assert(strpos($handler, 'prospektweb_calc_control_center.php') !== false, 'The global menu opens the control center');
$assert(strpos($handler, "'parent_menu' => 'global_menu_services'") !== false, 'The established Services menu entry is preserved');
$assert(strpos($handler, 'prospektweb_calc_recalculate.php') !== false, 'The established batch recalculation entry is preserved');

$assert(strpos($page, "'mode' => 'control-center'") !== false, 'The admin page opens the central SPA mode');
$assert(strpos($page, "\$_GET['offer_ids']") === false, 'The control center must not require selected offers');
$assert(strpos($page, 'position: fixed') === false, 'The control center must not cover the Bitrix global navigation');
$assert(strpos($page, '.adm-workarea') !== false && strpos($page, 'padding: 0 !important') !== false, 'The control center removes page-local workarea padding');
$assert(strpos($page, '#adm-title') !== false && strpos($page, 'display: none !important') !== false, 'The duplicate Bitrix page title is hidden');
$assert(strpos($page, 'prospektweb-control-center-editor__bar') === false, 'The owned editor does not reserve space for a duplicate outer header');
$assert(strpos($page, 'prospektweb-control-center-editor-title') === false, 'The owned editor does not render a duplicate outer title');
$assert(strpos($page, 'prospektweb-control-center-editor-close') === false, 'The owned editor does not render a duplicate outer close button');
$assert(strpos($page, 'requestOwnedEditorClose') === false, 'Only the native editor close flow owns close confirmation');
$assert(strpos($page, "message.type === 'CLOSE_CONTROL_CENTER_EDITOR'") !== false, 'The native editor close bridge remains available');
$assert(strpos($page, '#prospektweb-control-center-editor-iframe') !== false && strpos($page, 'height: 100%') !== false, 'The embedded editor uses the full overlay height');
$assert(strpos($page, 'Math.max(1, window.innerHeight - Math.max(0, rect.top))') !== false, 'The iframe height follows the actual available workarea without a clipping floor');
$assert(strpos($page, 'Math.max(480') === false, 'Short and zoomed viewports are not forced into a clipped 480px canvas');
$assert(strpos($page, "window.addEventListener('resize', resizeControlCenter)") !== false, 'The workarea height follows viewport changes');
$assert(strpos($page, "event.source !== iframe.contentWindow") !== false, 'Messages are accepted only from the owned iframe');
$assert(strpos($page, "event.origin !== window.location.origin") !== false, 'Messages are accepted only from the same origin');
$assert(strpos($page, "message.protocol !== 'pwrt-v1'") !== false, 'Messages must use the versioned bridge protocol');
$assert(strpos($page, "message.source !== 'prospektweb.calc'") !== false, 'Messages must identify the calculator SPA');
$assert(strpos($page, "message.target !== 'bitrix'") !== false, 'Messages must target the Bitrix host');
$assert(strpos($page, "message.type === 'READY'") !== false, 'The host recognizes the control-center readiness message');
$assert(strpos($page, "message.payload.mode !== 'control-center'") !== false, 'Legacy editor readiness messages cannot receive the control-center bootstrap');
$assert(strpos($page, "type: 'CONTROL_CENTER_INIT'") !== false, 'The trusted iframe receives the versioned control-center bootstrap');
$assert(strpos($page, "source: 'bitrix'") !== false, 'The bootstrap identifies the Bitrix host as its source');
$assert(strpos($page, "target: 'prospektweb.calc'") !== false, 'The bootstrap targets only the calculator SPA');
$assert(strpos($page, 'iframe.contentWindow.postMessage({') !== false, 'The bootstrap is sent only to the owned iframe window');
$assert(strpos($page, '}, window.location.origin)') !== false, 'The bootstrap is sent only to the current origin');
$assert(strpos($page, "'sessid' => bitrix_sessid()") !== false, 'The bootstrap carries the authenticated Bitrix session token');
$assert(strpos($page, "'settings' => '/bitrix/tools/prospektweb.calc/control_center_settings.php'") !== false, 'The bootstrap exposes the native settings endpoint');
$assert(strpos($page, "'diagnostics' => '/bitrix/tools/prospektweb.calc/diagnostic.php'") !== false, 'The bootstrap exposes the native diagnostics endpoint');
$assert(strpos($page, "'batch' => '/bitrix/tools/prospektweb.calc/batch_recalculate.php'") !== false, 'The bootstrap exposes the native batch endpoint');
$assert(strpos($page, "'modules' => '/bitrix/tools/prospektweb.calc/control_center_modules.php'") !== false, 'The bootstrap exposes the native modules endpoint');
$assert(strpos($page, "'moduleVersion' => \$moduleVersion") !== false, 'The bootstrap exposes the installed module version');
$assert(strpos($page, "'capabilities' => \$controlCenterCapabilities") !== false, 'The bootstrap exposes explicit feature capabilities');
$assert(strpos($page, "'settings' => true") !== false && strpos($page, "'diagnostics' => true") !== false && strpos($page, "'batch' => true") !== false, 'All embedded Phase 2 capabilities are advertised');
$assert(strpos($page, "'modules' => true") !== false, 'The embedded Phase 3A module catalog is advertised');
$assert(strpos($page, "message.type !== 'OPEN_ADMIN_URL'") !== false, 'Only the agreed navigation message remains as a fallback after bootstrap handling');
$assert(strpos($page, "message.payload.route") !== false, 'The bridge consumes a route key');
$assert(strpos($page, 'message.payload.url') === false, 'The bridge must never consume a raw iframe URL');
$iframeUrlSourceStart = strpos($page, '$iframeUrl =');
$iframeUrlSourceEnd = $iframeUrlSourceStart === false ? false : strpos($page, 'require $_SERVER', $iframeUrlSourceStart);
$iframeUrlSource = ($iframeUrlSourceStart === false || $iframeUrlSourceEnd === false)
    ? ''
    : substr($page, $iframeUrlSourceStart, $iframeUrlSourceEnd - $iframeUrlSourceStart);
$assert($iframeUrlSource !== '' && strpos($iframeUrlSource, 'sessid') === false, 'The iframe URL must never expose the Bitrix session token');
$assert(
    strpos($page, "\$_GET['pwRoute']") !== false
        && strpos($page, "'storefront-calculators'") !== false
        && strpos($page, "in_array(\$requestedEmbeddedRoute, \$allowedEmbeddedRoutes, true)") !== false
        && strpos($iframeUrlSource, "\$iframeUrl .= '#/' . rawurlencode(\$embeddedRoute)") !== false,
    'Context links must deep-link only to a strictly allowlisted route inside the control-center iframe'
);
$assert(strpos($page, 'Object.prototype.hasOwnProperty.call(routeMap, route)') !== false, 'Route keys are checked against the server map');
$assert(strpos($page, 'new URL(routeMap[route], window.location.origin)') !== false, 'Server routes are resolved against the current origin');
$assert(strpos($page, 'targetUrl.origin !== window.location.origin') !== false, 'Resolved routes receive a same-origin check');
$assert(strpos($page, 'allowedAdminPaths.indexOf(targetUrl.pathname) === -1') !== false, 'Resolved routes receive an admin-path allowlist check');

$routeKeys = [
    'presets',
    'products',
    'storefront-calculators',
    'batch-recalculation',
    'directories',
    'diagnostics',
    'settings',
];
foreach ($routeKeys as $routeKey) {
    $assert(strpos($page, "'{$routeKey}' =>") !== false, "Route {$routeKey} is owned by the server allowlist");
}
$assert(strpos($page, "'offer-generator' =>") === false, 'The unfinished central offer generator cannot be opened through the bridge');
$assert(strpos($page, "'tabControl_active_tab' => 'edit5'") !== false, 'Diagnostics opens its dedicated settings tab');

$assert(strpos($installer, "'/prospektweb_calc_control_center.php'") !== false, 'Installer owns the control-center source and destination');
$assert(strpos($installer, "copy(\$adminControlCenterFile, \$targetAdmin . '/prospektweb_calc_control_center.php')") !== false, 'Installer copies the control-center admin page');
$assert(strpos($installer, 'unlink($adminControlCenterFile)') !== false, 'Uninstall removes the control-center admin page');
$assert(strpos($installer, "'Центр управления не найден'") !== false, 'Installation integrity checks the control-center page');
$assert(strpos($diagnosticTool, "case 'fix_files':") !== false && strpos($diagnosticTool, '$installer->installFiles()') !== false, 'File repair and updates reuse installer ownership');
$assert(strpos($moduleDiagnostic, '/bitrix/admin/prospektweb_calc_control_center.php') !== false, 'Module diagnostics verify the installed control-center page');

$assert(strpos($contextualCalculator, 'openCalculatorDialog') !== false, 'The contextual calculator popup remains available');
$assert(strpos($contextualGenerator, 'window.ProspektwebProductGenerator = ProductGenerator') !== false, 'The contextual offer generator remains available');
$assert(strpos($appIndex, '36d30b7ea30f') !== false, 'The control center ships the current calcconfig release');
$assert(strpos($appBundle, 'OPEN_ADMIN_URL') !== false, 'The published bundle contains the fixed admin navigation message');
$assert(strpos($appBundle, 'OPEN_CALC_EDITOR') !== false, 'The published bundle contains the calculation editor launch contract');
$assert(strpos($appBundle, 'OPEN_STOREFRONT_EDITOR') !== false, 'The published bundle contains the storefront editor launch contract');
$assert(strpos($appBundle, 'prospektweb.control-center.editors/v1') !== false, 'The published bundle validates the Phase 4A editors catalog');
$assert(strpos($appBundle, 'Независимые калькуляторы') !== false, 'The published bundle names the standalone calculator workspace');
$assert(strpos($appBundle, 'Необязательный адаптер') !== false, 'The published bundle separates catalog output from preset authoring');
$assert(strpos($appBundle, 'Витринные калькуляторы') !== false, 'The published bundle exposes storefront calculator navigation');

echo "Control center static tests passed\n";
