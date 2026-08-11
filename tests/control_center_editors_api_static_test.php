<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/tools/control_center_editors.php');
$service = file_get_contents($root . '/lib/Services/ControlCenterEditorsService.php');
$host = file_get_contents($root . '/admin/prospektweb_calc_control_center.php');
$calculator = file_get_contents($root . '/admin/calculator.php');
$autoload = file_get_contents($root . '/include.php');
$installer = file_get_contents($root . '/install/index.php');
$diagnostic = file_get_contents($root . '/lib/Diagnostic/ModuleDiagnostic.php');
$version = file_get_contents($root . '/install/version.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(strpos($endpoint, "\$requestMethod !== 'POST'") !== false, 'Editors endpoint must be POST-only');
$assert(strpos($endpoint, 'check_bitrix_sessid()') !== false, 'Editors endpoint must enforce Bitrix CSRF protection');
$assert(strpos($endpoint, '!$USER || !$USER->IsAdmin()') !== false, 'Editors endpoint must require an administrator');
$assert(strpos($endpoint, "'action' => 'catalog'") === false, 'Endpoint must not fabricate a client action');
$assert(strpos($endpoint, "\$action === 'catalog'") !== false, 'Editors endpoint must expose the catalog action');
$assert(strpos($endpoint, "\$action === 'validate_calculation_launch'") !== false, 'Editors endpoint must validate calculation launches');
$assert(strpos($endpoint, "\$action === 'validate_storefront_launch'") !== false, 'Editors endpoint must validate storefront launches');
$assert(strpos($endpoint, "\$request['offerIds']") !== false, 'Editors endpoint must accept a bounded selective offer list for validation');
$assert(strpos($endpoint, "throw new \\InvalidArgumentException('Request contains unsupported fields')") !== false, 'Editors endpoint must reject unknown request fields');
$assert(strpos($endpoint, 'validateCalculationLaunch((int)$presetId, (int)$productId, $offerIds)') !== false, 'Calculation validation must pass the selective list only to server validation');

$assert(strpos($service, "public const CONTRACT = 'prospektweb.control-center.editors/v1'") !== false, 'Editors catalog must have a versioned contract');
$assert(strpos($service, 'public const FOCUS_PRESET_ID = 12740') !== false, 'Phase 4A must be explicitly scoped to preset 12740');
$assert(strpos($service, "(new CatalogTreeService())->presetLoadOptions(['presetId' => \$presetId])") !== false, 'The catalog must reuse the authoritative preset/product/offer resolver');
$assert(strpos($service, "'ACTIVE' => 'Y'") === false, 'The launch service must not duplicate lower-level Bitrix queries');
$assert(strpos($service, 'validateCalculationLaunch(int $presetId, int $productId, array $offerIds)') !== false, 'Calculation launch must validate the selective offer list');
$assert(strpos($service, "'offerIds' => \$validatedOfferIds") !== false, 'Validated offer IDs must be derived from the server snapshot');
$assert(strpos($service, 'MAX_CALCULATION_OFFERS') !== false, 'Server-derived launch URLs must have a bounded offer count');
$assert(strpos($service, 'Offer IDs must not contain duplicates') !== false, 'Duplicate selective IDs must be rejected');

$assert(strpos($host, "'editors' => '/bitrix/tools/prospektweb.calc/control_center_editors.php'") !== false, 'Bootstrap must expose the editors endpoint');
$assert(strpos($host, "'controlCenterInstanceId' => \$controlCenterInstanceId") !== false, 'Bootstrap must issue a per-page instance token');
$assert(strpos($host, 'message.payload.controlCenterInstanceId !== controlCenterInstanceId') !== false, 'Launch messages must echo the exact control-center token');
$assert(strpos($host, "typeof message !== 'object' || Array.isArray(message)") !== false, 'Host must reject non-object and array message envelopes');
$assert(strpos($host, "message.type === 'OPEN_CALC_EDITOR'") !== false, 'Host must recognize the typed calculation launch');
$assert(strpos($host, "message.type === 'OPEN_STOREFRONT_EDITOR'") !== false, 'Host must recognize the typed storefront launch');
$assert(strpos($host, "postEditorAction('validate_calculation_launch'") !== false, 'Host must revalidate calculation launch server-side');
$assert(strpos($host, "postEditorAction('validate_storefront_launch'") !== false, 'Host must revalidate storefront launch server-side');
$assert(strpos($host, "new URL('/bitrix/admin/prospektweb_calc_calculator.php', window.location.origin)") !== false, 'Host must construct the fixed calculator URL');
$assert(strpos($host, "new URL('/bitrix/admin/prospektweb_frontcalc_editor.php', window.location.origin)") !== false, 'Host must construct the fixed storefront URL');
$assert(strpos($host, 'message.payload.url') === false, 'No child-supplied URL may reach the host');
$assert(strpos($host, 'event.source === editorIframe.contentWindow') !== false, 'Editor close messages must come from the owned overlay iframe');
$assert(strpos($host, "message.type === 'CLOSE_CONTROL_CENTER_EDITOR'") !== false, 'Host must support the typed editor close event');
$assert(strpos($host, 'message.payload.editorInstanceId === activeEditor.id') !== false, 'Close messages must echo the active editor token');
$assert(strpos($host, "sendToControlCenter('EDITOR_CLOSED'") !== false, 'The SPA must receive the agreed editor-closed result');
$assert(strpos($host, 'Number.isSafeInteger(payload.presetId)') !== false && strpos($host, 'Number.isSafeInteger(payload.productId)') !== false, 'Launch IDs must be safe integers');
$assert(strpos($host, "hasExactPayloadKeys(payload, ['controlCenterInstanceId', 'presetId', 'productId', 'offerIds'])") !== false, 'Calculation launches must reject unknown payload keys');
$assert(strpos($host, "hasExactPayloadKeys(payload, ['controlCenterInstanceId', 'productId'])") !== false, 'Storefront launches must reject unknown payload keys');
$assert(strpos($host, "window.confirm('Закрыть редактор?") !== false, 'The host close button must warn about unsaved changes');
$assert(strpos($host, "editorIframe.src = 'about:blank'") !== false, 'Closing the overlay must release its document');

$assert(strpos($calculator, "count(\$uniqueOfferIds) !== count(\$offerIds)") !== false, 'Calculator page must reject duplicate offer IDs');
$assert(strpos($calculator, 'if ($controlCenterMode && !$USER->IsAdmin())') !== false, 'Control-center calculator launch must require an administrator');
$assert(strpos($calculator, 'count($offerIds) > 500') !== false, 'Calculator page must reject oversized offer lists');
$assert(strpos($calculator, "'IBLOCK_ID' => \$skuIblockId") !== false, 'Calculator page must resolve offers in the configured SKU iblock');
$assert(strpos($calculator, "if (\$controlCenterMode) {\n        \$offerFilter['ACTIVE'] = 'Y';") !== false, 'Control-center launches must require active offers');
$assert(strpos($calculator, "if (\$controlCenterMode) {\n        \$productFilter['ACTIVE'] = 'Y';") !== false, 'Control-center launches must require an active parent product');
$assert(strpos($calculator, "\$offerFilter = [\n        'IBLOCK_ID' => \$skuIblockId,\n        'ID' => \$offerIds,\n    ];") !== false, 'Legacy contextual launches must not unconditionally filter inactive offers');
$assert(strpos($calculator, "\$productFilter = [\n        'ID' => \$validatedProductId,\n        'IBLOCK_ID' => \$productIblockId,\n    ];") !== false, 'Legacy contextual launches must not unconditionally filter inactive products');
$assert(strpos($calculator, '$validatedProductId !== $parentProductId') !== false, 'Calculator page must reject mixed-parent offer lists');
$assert(strpos($calculator, "'IBLOCK_ID' => \$productIblockId") !== false, 'Calculator page must require the configured product iblock');
$assert(strpos($calculator, "['CODE' => 'CALC_PRESET']") !== false && strpos($calculator, '=== 12740') !== false, 'Control-center launch must revalidate preset 12740 on the product');
$assert(strpos($calculator, "type: 'CLOSE_CONTROL_CENTER_EDITOR'") !== false, 'Calculator close must return to the control-center overlay host');

$assert(strpos($autoload, 'ControlCenterEditorsService') !== false, 'Editors service must be autoloaded');
$assert(strpos($installer, "\$toolsDir . '/control_center_editors.php'") !== false, 'Installer integrity must verify the editors endpoint');
$assert(substr_count($diagnostic, "'control_center_editors.php'") >= 1, 'Diagnostics must verify the published editors endpoint');
$assert(strpos($diagnostic, "'lib/Services/ControlCenterEditorsService.php'") !== false, 'Diagnostics must verify the editors service');
$assert(strpos($version, "'VERSION' => '1.5.1'") !== false, 'Phase 4A hotfix must publish a coherent module version');

echo "Control center editors API static tests passed\n";
