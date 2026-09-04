<?php

$root = dirname(__DIR__);
$handler = file_get_contents($root . '/lib/Handlers/AdminHandler.php');
$page = file_get_contents($root . '/admin/prospektweb_calc_control_center.php');
$installer = file_get_contents($root . '/install/index.php');
$diagnosticTool = file_get_contents($root . '/tools/diagnostic.php');
$moduleDiagnostic = file_get_contents($root . '/lib/Diagnostic/ModuleDiagnostic.php');
$contextualCalculator = file_get_contents($root . '/install/assets/js/calculator.js');
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
$assert(strpos($page, 'position: fixed') !== false && strpos($page, 'height: 100dvh') !== false, 'The control center must take over the full viewport so the editor is not constrained to the narrow Bitrix workarea');
$assert(strpos($page, '.adm-workarea') !== false && strpos($page, 'padding: 0 !important') !== false, 'The control center removes page-local workarea padding');
$assert(strpos($page, 'var focusPresetId = Number(data.focusPresetId || 0);') !== false
    && strpos($page, '(versionLaunch ? data.presetId !== presetId : focusPresetId !== presetId)') !== false,
    'Version logic launch validates the owning preset separately from its internal working graph');
$assert(strpos($page, "targetUrl.searchParams.set('open_calculation_panel', 'Y')") !== false, 'A version row can launch the internal calculation panel directly');
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
$assert(strpos($page, "message.type === 'CONTROL_CENTER_ROUTE_CHANGED'") !== false, 'The host synchronizes trusted calculator workspace routes');
$assert(strpos($page, 'normalizeCalculatorWorkspaceHash') !== false, 'Workspace hashes are normalized before they reach the parent history');
$assert(strpos($page, "['q', 'status', 'sort', 'field', 'version']") !== false, 'Only the agreed workspace query keys can be persisted');
$assert(strpos($page, "window.addEventListener('popstate', syncCalculatorWorkspaceFromHost)") !== false, 'Browser history is forwarded back into the embedded workspace');
$assert(strpos($page, 'childLocation.replace(childLocation.pathname + childLocation.search + hash)') !== false, 'Parent history changes replace the nested entry instead of duplicating browser history');
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
$assert(strpos($page, "'partners' => '/bitrix/tools/prospektweb/partnermanager/control_center.php'") !== false, 'The bootstrap exposes the partner manager endpoint');
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
    'partners',
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
$assert(strpos($diagnosticTool, "case 'fix_files':") === false && strpos($diagnosticTool, '$installer->installFiles()') === false, 'HTTP diagnostics cannot overwrite module files');
$assert(strpos($moduleDiagnostic, '/bitrix/admin/prospektweb_calc_control_center.php') !== false, 'Module diagnostics verify the installed control-center page');

$assert(strpos($contextualCalculator, 'openCalculatorDialog') !== false, 'The contextual calculator popup remains available');
$assert(strpos($handler, 'product_generator.js') === false, 'The uncoordinated contextual product generator is retired');
$assert(strpos($appIndex, '85f176dbd4be') !== false, 'The control center ships the current calcconfig release');
$assert(strpos($appBundle, 'OPEN_ADMIN_URL') !== false, 'The published bundle contains the fixed admin navigation message');
$assert(strpos($appBundle, 'OPEN_CALC_EDITOR') !== false, 'The published bundle contains the calculation editor launch contract');
$assert(strpos($appBundle, 'OPEN_STOREFRONT_EDITOR') === false, 'The published bundle no longer contains the legacy storefront editor launch contract');
$assert(strpos($appBundle, 'storefront_list') !== false, 'The published bundle uses the vNext storefront list action');
$assert(strpos($appBundle, 'storefront_get') !== false, 'The published bundle uses the vNext storefront get action');
$assert(strpos($appBundle, 'storefront_save') !== false, 'The published bundle uses the vNext storefront save action');
$assert(strpos($appBundle, 'storefront_delete') !== false, 'The published bundle uses the vNext storefront delete action');
$assert(strpos($appBundle, 'calculator_input_mapping_load') !== false, 'The published bundle loads preset-owned calculator input mappings');
$assert(strpos($appBundle, 'calculator_input_mapping_validate') !== false, 'The published bundle validates preset-owned calculator input mappings');
$assert(strpos($appBundle, 'calculator_input_mapping_save') !== false, 'The published bundle saves preset-owned calculator input mappings');
$assert(strpos($appBundle, 'prospektweb.control-center.editors/v1') !== false, 'The published bundle validates the Phase 4A editors catalog');
$assert(strpos($appBundle, 'Реестр калькуляторов') !== false, 'The published bundle contains the server-driven calculator registry');
$assert(strpos($appBundle, 'Витрина запуска по товару') !== false, 'The published bundle assigns an explicit storefront to every linked product');
$assert(strpos($appBundle, 'Все поля формы присутствуют всегда') !== false, 'The published bundle renders every form field in storefront settings');
$assert(strpos($appBundle, 'Управлять связями') === false, 'The storefront workspace no longer owns preset product assignment management');
$assert(strpos($appBundle, 'Разделы калькулятора') !== false, 'The published bundle exposes the unified calculator workspace tabs');
$assert(strpos($appBundle, 'Это поле должно передавать в калькулятор одно значение.') !== false, 'The published form editor contains independent calculation-input guidance');
$assert(strpos($appBundle, 'Выберите свойство каталога') === false, 'The preset form editor no longer asks the operator to select a Bitrix property');
foreach (['Обновить на сайте', 'Связать со свойством Bitrix', 'Передавать в поле', 'Условия показа', 'Обязательность заполнения', 'При выполнении условий', 'Чипсы пресетов', 'Выбор вариантов значений свойства', 'Применить выбор', 'Базовая витрина', 'SORT', '210x297'] as $releaseLabel) {
    $assert(strpos($appBundle, $releaseLabel) !== false, 'The published bundle contains form authoring release marker: ' . $releaseLabel);
}
$assert(strpos($appBundle, 'Черновик сохранён, но ещё не применён на сайте.') === false, 'The redundant compact draft banner is absent from the published bundle');

echo "Control center static tests passed\n";
