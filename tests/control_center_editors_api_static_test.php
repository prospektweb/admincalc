<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/tools/control_center_editors.php');
$service = file_get_contents($root . '/lib/Services/ControlCenterEditorsService.php');
$parityService = file_get_contents($root . '/lib/Services/Phase5aParityContractService.php');
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
$assert(strpos($endpoint, "\$action === 'registry'") !== false, 'Editors endpoint must expose the server-paged preset registry');
$assert(strpos($endpoint, "\$action === 'preset_load'") !== false, 'Editors endpoint must lazy-load preset detail');
$assert(strpos($endpoint, "\$action === 'duplicate_preset'") !== false, 'Editors endpoint must expose preset duplication');
$assert(strpos($endpoint, "\$action === 'set_preset_active'") !== false, 'Editors endpoint must expose bounded activation and archival');
$assert(strpos($endpoint, "\$action === 'validate_calculation_launch'") !== false, 'Editors endpoint must validate calculation launches');
$assert(strpos($endpoint, "\$action === 'validate_preset_launch'") !== false, 'Editors endpoint must validate standalone preset launches');
$assert(strpos($endpoint, "\$action === 'validate_storefront_launch'") !== false, 'Editors endpoint must validate storefront launches');
$assert(strpos($endpoint, "\$action === 'storefront_load'") !== false, 'Editors endpoint must expose native storefront workspace loading');
$assert(strpos($endpoint, "\$action === 'storefront_validate'") !== false, 'Editors endpoint must expose native storefront schema validation');
$assert(strpos($endpoint, "\$action === 'storefront_save_template'") !== false, 'Editors endpoint must expose revisioned template saving');
$assert(strpos($endpoint, "\$action === 'storefront_save_product'") !== false, 'Editors endpoint must expose revisioned product saving');
$assert(strpos($endpoint, "\$action === 'storefront_enable_inheritance'") !== false, 'Editors endpoint must expose revisioned inheritance activation');
$assert(strpos($endpoint, "\$action === 'storefront_delete_template'") !== false, 'Editors endpoint must expose revisioned template deletion');
$assert(strpos($endpoint, "\$action === 'form_first_load'") !== false, 'Editors endpoint must expose form-first workspace loading');
$assert(strpos($endpoint, "\$action === 'form_first_field_delete_impact'") !== false, 'Editors endpoint must expose current field deletion impact');
$assert(strpos($endpoint, "\$action === 'form_first_save_draft'") !== false, 'Editors endpoint must expose revisioned form-first draft saving');
$assert(strpos($endpoint, "\$action === 'form_first_preview'") !== false, 'Editors endpoint must expose form-first compile preview');
$assert(strpos($endpoint, "\$action === 'form_first_publish'") !== false, 'Editors endpoint must expose guarded form-first publication');
$assert(strpos($endpoint, "\$action === 'form_first_rollback'") !== false, 'Editors endpoint must expose form-first rollback');
$assert(strpos($endpoint, "\$action === 'phase5a_parity_contract'") !== false, 'Editors endpoint must expose a read-only Phase 5A parity contract');
$assert(strpos($endpoint, "\$action === 'phase5a_parity_compare'") !== false, 'Editors endpoint must expose the strict read-only Phase 5A comparator');
$assert(strpos($endpoint, "\$request['offerIds']") !== false, 'Editors endpoint must accept a bounded selective offer list for validation');
$assert(strpos($endpoint, "throw new \\InvalidArgumentException('Request contains unsupported fields')") !== false, 'Editors endpoint must reject unknown request fields');
$assert(strpos($endpoint, 'validateCalculationLaunch($presetId, $offerIds)') !== false, 'Calculation validation must pass only preset and offer hints to server validation');
$assert(strpos($endpoint, 'validatePresetLaunch($presetId)') !== false, 'Standalone launch validation must pass only the preset ID');
$assert(strpos($endpoint, "strlen(\$encoded) > 60000") !== false, 'Structured storefront schemas must have a strict 60 KB transport cap');
$assert(substr_count($endpoint, "strlen(\$encoded) > 60000") >= 2, 'Form and binding documents must share the strict 60 KB transport cap');
$assert(strpos($endpoint, "preg_match('/^[a-f0-9]{64}$/D', \$value)") !== false, 'Product mutations must require a SHA-256 individual revision');
$assert(strpos($endpoint, "preg_match('/^[a-f0-9]{16,32}$/D', \$value)") !== false, 'Template actions must require a canonical template identifier');
$assert(strpos($endpoint, "\$exception->getCode() === 409 ? 'REVISION_CONFLICT' : 'EDITOR_UNAVAILABLE'") !== false, 'Provider revision conflicts must retain a stable API error code');

$assert(strpos($service, "public const CONTRACT = 'prospektweb.control-center.editors/v1'") !== false, 'Editors catalog must have a versioned contract');
$assert(strpos($service, 'public const FOCUS_PRESET_ID = 12740') !== false, 'Phase 4A must be explicitly scoped to preset 12740');
$assert(strpos($service, "(new CatalogTreeService())->presetLoadOptions(['presetId' => \$presetId])") !== false, 'The catalog must reuse the authoritative preset/product/offer resolver');
$assert(strpos($service, 'public function getPresetRegistry(') !== false, 'The service must expose a lightweight registry contract');
$assert(strpos($service, 'public function loadPresetWorkspace(int $presetId)') !== false, 'The service must separate lazy preset detail from registry summaries');
$assert(strpos($service, "'calculations' => \$registry['rows']") !== false, 'Bootstrap catalog must not load every preset snapshot');
$assert(strpos($service, 'PROPERTY_CALC_PRESET') !== false && strpos($service, 'PROPERTY_CML2_LINK') !== false, 'Registry usage must aggregate products and offers without nested snapshots');
$assert(strpos($service, 'validateCalculationLaunch(int $presetId, array $offerIds)') !== false, 'Calculation launch must validate a multi-product offer list');
$assert(strpos($service, 'validatePresetLaunch(int $presetId)') !== false, 'The control center must support product-neutral preset launches');
$assert(strpos($service, "'offerIds' => \$validatedOfferIds") !== false, 'Validated offer IDs must be derived from the server snapshot');
$assert(strpos($service, 'MAX_CALCULATION_OFFERS') !== false, 'Server-derived launch URLs must have a bounded offer count');
$assert(strpos($service, 'Offer IDs must not contain duplicates') !== false, 'Duplicate selective IDs must be rejected');
$assert(strpos($service, "public const STOREFRONT_EDITOR_CONTRACT = 'prospektweb.frontcalc.storefront-editor/v1'") !== false, 'The native storefront adapter must pin the provider contract');
$assert(strpos($service, "public const FORM_FIRST_AUTHORING_CONTRACT = 'prospektweb.frontcalc.form-first-authoring/v1'") !== false, 'The form-first adapter must pin its distinct provider contract');
$assert(strpos($service, 'ControlCenterStorefrontEditorService') !== false, 'The native storefront adapter must resolve only the FrontCalc-owned provider');
$assert(strpos($service, "'visualEditorAvailable' => \$visualEditorAvailable") !== false, 'The catalog must advertise provider availability separately from the legacy editor');
$assert(strpos($service, "'visualEditorContract' => self::STOREFRONT_EDITOR_CONTRACT") !== false, 'The catalog must advertise the exact visual editor contract');
$assert(strpos($service, "'formFirstAuthoringAvailable' => \$formFirstAuthoringAvailable") !== false, 'The catalog must advertise form-first provider availability');
$assert(strpos($service, "'formFirstAuthoringContract' => self::FORM_FIRST_AUTHORING_CONTRACT") !== false, 'The catalog must advertise the exact form-first provider contract');
$assert(strpos($service, "'formFirstPilotProductIds' => [4267]") !== false, 'The catalog must retain the exact product 4267 pilot gate');
$assert(substr_count($service, '$this->resolveStorefrontAuthority($productId);') === 7, 'Every product-owned storefront action must resolve the current product allowlist');
$assert(substr_count($service, '$this->resolvePresetFormAuthority($presetId, $productId);') === 6, 'Every preset-owned form action and deletion impact must resolve the preset and optional product authority');
$assert(strpos($service, "public const FORM_FIRST_FIELD_DELETE_IMPACT_CONTRACT = 'prospektweb.calc.form-first-field-delete-impact/v1'") !== false, 'Field deletion impact must have a versioned contract');
$assert(strpos($service, "'allowedProductIds' => \$allowedProductIds") !== false, 'Server authority must materialize the current preset allowlist');
$assert(strpos($service, '->loadWorkspace(') !== false, 'Workspace loading must delegate to the FrontCalc provider');
$assert(strpos($service, '->validateSchema(') !== false, 'Schema validation must delegate to the FrontCalc provider');
$assert(strpos($service, '->saveTemplate(') !== false && strpos($service, '->saveProduct(') !== false, 'Template and product saves must delegate to the FrontCalc provider');
$assert(strpos($service, '->enableInheritance(') !== false && strpos($service, '->deleteTemplate(') !== false, 'Inheritance and deletion must delegate to the FrontCalc provider');
$assert(strpos($service, "(string)(\$result['contract'] ?? '') !== self::STOREFRONT_EDITOR_CONTRACT") !== false, 'Provider responses must fail closed on contract drift');
$assert(strpos($service, "(string)(\$result['contract'] ?? '') !== self::FORM_FIRST_AUTHORING_CONTRACT") !== false, 'Form-first provider responses must fail closed on contract drift');
$assert(strpos($service, 'loadFormFirstWorkspace') !== false && strpos($service, 'saveFormFirstDraft') !== false, 'Provider availability must include form-first load and save methods');
$assert(strpos($service, 'previewFormFirst') !== false && strpos($service, 'publishFormFirst') !== false && strpos($service, 'rollbackFormFirst') !== false, 'Provider availability must include preview, publish and rollback methods');
$assert(
    strpos($service, '(int)$product[\'id\'] !== $expectedProductId') !== false
        && strpos($service, '(int)$result[\'presetId\'] !== $expectedPresetId') !== false
        && strpos($service, '(string)$result[\'operation\'] !== $expectedOperation') !== false,
    'Form-first responses must be bound to the requested product, preset and action'
);

$assert(strpos($parityService, "public const CONTRACT = 'prospektweb.calc.form-first-parity/v1'") !== false, 'Dependency matrix must have a versioned contract');
$assert(strpos($parityService, 'public const GOLDEN_PRODUCT_IDS = [4267, 4403, 5058, 12727, 12764]') !== false, 'Golden parity must gate the exact five pilot products');
$defaultPresetLoaderOffset = strpos(
    $parityService,
    '$this->presetLoader = $presetLoader ?? static function (int $presetId): array {'
);
$defaultPresetIblockOffset = strpos(
    $parityService,
    "if (!\\Bitrix\\Main\\Loader::includeModule('iblock')) {",
    $defaultPresetLoaderOffset === false ? 0 : $defaultPresetLoaderOffset
);
$defaultPresetResolverOffset = strpos(
    $parityService,
    "return (new CatalogTreeService())->presetLoadOptions(['presetId' => \$presetId]);",
    $defaultPresetLoaderOffset === false ? 0 : $defaultPresetLoaderOffset
);
$defaultPresetFailureOffset = strpos(
    $parityService,
    "throw new \\RuntimeException('The iblock module is not available');",
    $defaultPresetIblockOffset === false ? 0 : $defaultPresetIblockOffset
);
$assert(
    $defaultPresetLoaderOffset !== false
        && $defaultPresetIblockOffset !== false
        && $defaultPresetResolverOffset !== false
        && $defaultPresetFailureOffset !== false
        && $defaultPresetLoaderOffset < $defaultPresetIblockOffset
        && $defaultPresetIblockOffset < $defaultPresetResolverOffset
        && $defaultPresetFailureOffset < $defaultPresetResolverOffset,
    'The parity default preset loader must own a fail-closed iblock bootstrap before catalog access'
);
$assert(
    strpos($parityService, "'calcServerChangeRequired' => \$dependency['matrix']['valid'] && \$goldenValid ? false : null") !== false
        && strpos($parityService, "\$dependency['matrix']['valid'] && \$goldenValid") !== false,
    'Calc-server compatibility must require both exact dependency coverage and complete golden parity'
);
$assert(
    substr_count($service, '$this->resolveDependencyContract($presetId, $authority[\'allowedProductIds\'])') === 6
        && substr_count($service, '$dependencyContract[\'fingerprint\']') === 6,
    'All form-first calls and deletion impact must receive a freshly server-resolved dependency authority'
);
$assert(
    strpos($service, "!hash_equals(\$expectedDependencyFingerprint, (string)\$result['dependencyFingerprint'])") !== false,
    'Form-first responses must be bound to the exact dependency fingerprint used for compilation'
);
$assert(
    strpos($endpoint, "'dependencyContract'") === false,
    'The browser request must not be allowed to supply dependency authority'
);
$assert(strpos($parityService, "'readOnly' => true") !== false, 'Golden capture must be explicitly read-only');
$assert(
    strpos($parityService, '$provider->capturePhase5aGoldenParity(') !== false
        && strpos($parityService, 'catch (\Throwable $exception)') !== false
        && strpos($parityService, '$result = null;') !== false,
    'An unavailable optional live golden capture must fall back to the fail-closed versioned fixture'
);
$assert(
    strpos($parityService, "Option::get(\n            'prospektweb.frontcalc',\n            'PRODUCTS_IBLOCK_ID'") !== false,
    'Dependency capture must use the exact public FrontCalc product iblock authority'
);
$assert(
    strpos($parityService, 'getProductIblockId()') === false,
    'Dependency capture must not read the unrelated legacy AdminCalc product iblock option'
);
$assert(
    strpos($parityService, "in_array(\$version, [1, 2], true)") !== false
        && strpos($parityService, "\$source === 'none' && \$schema === []") !== false
        && strpos($parityService, "&& !array_key_exists('required', \$field)") !== false,
    'Dependency capture must scan active RuntimeSchema v1 and treat the exact empty resolver state as proven'
);

$assert(strpos($host, "'editors' => '/bitrix/tools/prospektweb.calc/control_center_editors.php'") !== false, 'Bootstrap must expose the editors endpoint');
$assert(strpos($endpoint, "if (\$action === 'create_preset')") !== false, 'Editors endpoint must expose preset-first creation');
$assert(substr_count($endpoint, "\$parseStrictNonNegativeInt(\$request['productId'] ?? 0, 'productId')") === 6, 'All form-first actions and deletion impact must accept productless preset-owned authoring');
$assert(strpos($host, "'controlCenterInstanceId' => \$controlCenterInstanceId") !== false, 'Bootstrap must issue a per-page instance token');
$assert(strpos($host, 'message.payload.controlCenterInstanceId !== controlCenterInstanceId') !== false, 'Launch messages must echo the exact control-center token');
$assert(strpos($host, "typeof message !== 'object' || Array.isArray(message)") !== false, 'Host must reject non-object and array message envelopes');
$assert(strpos($host, "message.type === 'OPEN_CALC_EDITOR'") !== false, 'Host must recognize the typed calculation launch');
$assert(strpos($host, "message.type === 'OPEN_STOREFRONT_EDITOR'") !== false, 'Host must recognize the typed storefront launch');
$assert(strpos($host, "postEditorAction('validate_preset_launch'") !== false, 'Host must revalidate standalone preset launch server-side');
$assert(strpos($host, "postEditorAction('validate_storefront_launch'") !== false, 'Host must revalidate storefront launch server-side');
$assert(strpos($host, "new URL('/bitrix/admin/prospektweb_calc_calculator.php', window.location.origin)") !== false, 'Host must construct the fixed calculator URL');
$assert(strpos($host, "new URL('/bitrix/admin/prospektweb_frontcalc_editor.php', window.location.origin)") !== false, 'Host must construct the fixed storefront URL');
$assert(strpos($host, 'message.payload.url') === false, 'No child-supplied URL may reach the host');
$assert(strpos($host, 'event.source === editorIframe.contentWindow') !== false, 'Editor close messages must come from the owned overlay iframe');
$assert(strpos($host, "message.type === 'CLOSE_CONTROL_CENTER_EDITOR'") !== false, 'Host must support the typed editor close event');
$assert(strpos($host, 'message.payload.editorInstanceId === activeEditor.id') !== false, 'Close messages must echo the active editor token');
$assert(strpos($host, "'pwProductId' => max(0, (int)(\$_GET['pwProductId'] ?? 0))") !== false, 'Trusted host must forward the contextual product ID into the isolated SPA query');
$assert(strpos($host, "\$_GET['pwRoute']") !== false && strpos($host, "'storefront-calculators'") !== false && strpos($host, "\$iframeUrl .= '#/'") !== false, 'Trusted host must forward only an allowlisted contextual workspace route into the iframe hash');
$assert(strpos($host, "sendToControlCenter('EDITOR_CLOSED'") !== false, 'The SPA must receive the agreed editor-closed result');
$assert(strpos($host, 'Number.isSafeInteger(payload.presetId)') !== false && strpos($host, 'Number.isSafeInteger(payload.productId)') !== false, 'Preset and storefront IDs must be safe integers');
$assert(strpos($host, "hasExactPayloadKeys(payload, ['controlCenterInstanceId', 'presetId'])") !== false, 'Standalone preset launches must reject catalog fields and unknown payload keys');
$assert(strpos($host, "hasExactPayloadKeys(payload, ['controlCenterInstanceId', 'offerIds', 'presetId'])") !== false, 'Catalog launches must use a distinct exact payload envelope');
$assert(strpos($host, "hasExactPayloadKeys(payload, ['controlCenterInstanceId', 'productId'])") !== false, 'Storefront launches must reject unknown payload keys');
$assert(strpos($host, 'prospektweb-control-center-editor__bar') === false, 'The host must not reserve editor height for a duplicate outer header');
$assert(strpos($host, 'prospektweb-control-center-editor-close') === false, 'The host must not render a duplicate outer close button');
$assert(strpos($host, 'requestOwnedEditorClose') === false, 'Close confirmation belongs only to the native inner editor');
$assert(strpos($host, "editorIframe.src = 'about:blank'") !== false, 'Closing the overlay must release its document');

$assert(strpos($calculator, "count(\$uniqueOfferIds) !== count(\$offerIds)") !== false, 'Calculator page must reject duplicate offer IDs');
$assert(strpos($calculator, '$isStandalonePresetLaunch') !== false, 'Calculator page must support a standalone preset launch envelope');
$assert(strpos($calculator, '$isCatalogPresetLaunch = $controlCenterMode') !== false && strpos($calculator, '$standalonePresetId > 0') !== false, 'Standalone and catalog control-center launches must accept any active positive preset');
$assert(strpos($calculator, "['ID' => \$standalonePresetId, 'IBLOCK_ID' => \$presetIblockId, 'ACTIVE' => 'Y']") !== false, 'Standalone launch must re-resolve the active preset without product or SKU authority');
$assert(strpos($calculator, 'presetId: <?= json_encode(($isStandalonePresetLaunch || $isCatalogPresetLaunch) ? $standalonePresetId : 0) ?>') !== false, 'Standalone and catalog preset identity must reach the integration bridge');
$assert(strpos($calculator, 'if ($controlCenterMode && !$USER->IsAdmin())') !== false, 'Control-center calculator launch must require an administrator');
$assert(strpos($calculator, 'count($offerIds) > 500') !== false, 'Calculator page must reject oversized offer lists');
$assert(strpos($calculator, "'IBLOCK_ID' => \$skuIblockId") !== false, 'Calculator page must resolve offers in the configured SKU iblock');
$assert(strpos($calculator, "if (\$controlCenterMode) {\n        \$offerFilter['ACTIVE'] = 'Y';") !== false, 'Control-center launches must require active offers');
$assert(strpos($calculator, "\$offerFilter['ACTIVE_DATE'] = 'Y';") !== false, 'Control-center launches must require date-valid offers');
$assert(strpos($calculator, "if (\$controlCenterMode) {\n        \$productFilter['ACTIVE'] = 'Y';") !== false, 'Control-center launches must require an active parent product');
$assert(strpos($calculator, "\$productFilter['ACTIVE_DATE'] = 'Y';") !== false, 'Control-center launches must require date-valid parent products');
$assert(strpos($calculator, "\$offerFilter = [\n        'IBLOCK_ID' => \$skuIblockId,\n        'ID' => \$offerIds,\n    ];") !== false, 'Legacy contextual launches must not unconditionally filter inactive offers');
$assert(strpos($calculator, "\$productFilter = [\n        'ID' => array_map('intval', array_keys(\$validatedProductIds)),\n        'IBLOCK_ID' => \$productIblockId,\n    ];") !== false, 'Every parent product in a catalog launch must be re-resolved');
$assert(strpos($calculator, '!$isCatalogPresetLaunch') !== false && strpos($calculator, '$validatedProductId !== $parentProductId') !== false, 'Only the exact control-center catalog envelope may contain multiple parents');
$assert(strpos($calculator, '$standalonePresetId === 12740') !== false && strpos($calculator, 'StandaloneCatalogSelectionMapper::supportedProductIds()') !== false, 'Only the legacy preset 12740 catalog launch keeps its adapter-supported product scope');
$assert(strpos($calculator, "'IBLOCK_ID' => \$productIblockId") !== false, 'Calculator page must require the configured product iblock');
$assert(strpos($calculator, "['CODE' => 'CALC_PRESET']") !== false && strpos($calculator, '=== $standalonePresetId') !== false, 'Control-center catalog launch must revalidate the selected preset on every product');
$assert(strpos($calculator, "type: 'CLOSE_CONTROL_CENTER_EDITOR'") !== false, 'Calculator close must return to the control-center overlay host');

$assert(strpos($autoload, 'ControlCenterEditorsService') !== false, 'Editors service must be autoloaded');
$assert(strpos($autoload, 'Phase5aParityContractService') !== false, 'Phase 5A parity service must be autoloaded');
$assert(strpos($installer, "\$toolsDir . '/control_center_editors.php'") !== false, 'Installer integrity must verify the editors endpoint');
$assert(substr_count($diagnostic, "'control_center_editors.php'") >= 1, 'Diagnostics must verify the published editors endpoint');
$assert(strpos($diagnostic, "'lib/Services/ControlCenterEditorsService.php'") !== false, 'Diagnostics must verify the editors service');
$assert(strpos($diagnostic, "'lib/Services/Phase5aParityContractService.php'") !== false, 'Diagnostics must verify the Phase 5A parity service');
$assert(strpos($version, "'VERSION' => '1.10.3'") !== false, 'Safe form structure deletion release must publish module version 1.10.3');

echo "Control center editors API static tests passed\n";
