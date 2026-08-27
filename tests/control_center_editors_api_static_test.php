<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$endpoint = (string)file_get_contents($root . '/tools/control_center_editors.php');
$service = (string)file_get_contents($root . '/lib/Services/ControlCenterEditorsService.php');
$host = (string)file_get_contents($root . '/admin/prospektweb_calc_control_center.php');
$calculator = (string)file_get_contents($root . '/admin/calculator.php');
$integration = (string)file_get_contents($root . '/install/assets/js/integration.js');
$autoload = (string)file_get_contents($root . '/include.php');
$diagnostic = (string)file_get_contents($root . '/lib/Diagnostic/ModuleDiagnostic.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    strpos($endpoint, "Loader::includeModule('iblock')") !== false,
    'Control center editors endpoint must bootstrap the iblock module before lifecycle actions.'
);

foreach ([
    'catalog',
    'registry',
    'preset_load',
    'preset_product_catalog',
    'preset_products_impact',
    'set_preset_products',
    'create_preset',
    'set_preset_active',
    'duplicate_preset',
    'version_registry',
    'version_create',
    'version_rename',
    'version_delete_draft',
    'version_archive',
    'version_restore',
    'version_form_load',
    'version_input_mapping_validate',
    'version_form_save_draft',
    'version_component_load',
    'version_component_save_draft',
    'version_logic_launch',
    'version_logic_init',
    'version_logic_sync',
    'version_publish_activate',
    'version_activate',
    'form_first_load',
    'form_first_save_draft',
    'form_first_preview',
    'form_first_field_delete_impact',
    'form_first_publish',
    'form_first_rollback',
    'storefront_list',
    'storefront_get',
    'storefront_save',
    'storefront_delete',
    'calculator_input_mapping_load',
    'calculator_input_mapping_validate',
    'calculator_input_mapping_save',
    'catalog_output_mapping_load',
    'catalog_output_mapping_validate',
    'catalog_output_mapping_save',
    'preset_sections',
    'preset_section_preview',
    'calculator_catalog',
    'calculator_section_create',
    'calculator_section_rename',
    'calculator_section_delete',
    'calculator_move',
] as $action) {
    $assert(str_contains($endpoint, "'" . $action . "'"), 'Missing vNext action ' . $action);
}

$assert(
    str_contains($endpoint, '$identity = $service->validatePresetLaunch($presetId);')
        && str_contains($endpoint, "'presetName' => (string)\$identity['presetName']"),
    'version registry must use canonical preset identity instead of form-first fallback name'
);
$publication = strpos($endpoint, "if (\$action === 'version_publish_activate')");
$activation = strpos($endpoint, "if (\$action === 'version_activate')", $publication ?: 0);
$publicationSource = $publication !== false && $activation !== false
    ? substr($endpoint, $publication, $activation - $publication)
    : '';
$assert(
    str_contains($publicationSource, '$versionBundles->inspect($storedBundle[\'documents\']);')
        && str_contains($publicationSource, '$versionRuntimePublications->activate($presetId, $versionId)')
        && !str_contains($publicationSource, '$versionSources->capture($presetId, $document)'),
    'publication must activate the exact edited version bundle instead of comparing or recapturing shared runtime'
);

$assert(
    str_contains($endpoint, 'assertStorefrontAuthoritativeReadback')
        && !str_contains($endpoint, '$readBack !== $saved'),
    'Storefront save readback must compare canonical JSON values instead of stdClass identity'
);

foreach ([
    'validate_storefront_launch',
    'storefront_load',
    'storefront_validate',
    'storefront_save_template',
    'storefront_save_product',
    'storefront_enable_inheritance',
    'storefront_delete_template',
    'phase5a_parity_contract',
    'phase5a_parity_compare',
] as $legacyAction) {
    $assert(!str_contains($endpoint, "'" . $legacyAction . "'"), 'Removed action remains: ' . $legacyAction);
}

$assert(str_contains($endpoint, 'storefront.revision must match expected_revision'), 'storefront save uses integer CAS');
$assert(
    str_contains($endpoint, "preg_match('/^[a-z0-9][a-z0-9_.-]{0,30}$/D', \$value)")
        && !str_contains($endpoint, "[a-z0-9_.-]{0,63}")
        && preg_match('/^[a-z0-9][a-z0-9_.-]{0,30}$/D', str_repeat('a', 31)) === 1
        && preg_match('/^[a-z0-9][a-z0-9_.-]{0,30}$/D', str_repeat('a', 32)) === 0,
    'storefront parser accepts at most 31 bytes and rejects a 32-byte identifier before mutation'
);
$assert(
    str_contains($endpoint, 'assertStorefrontProductsBelongToPreset(')
        && str_contains($endpoint, '$lockedProductIblockId'),
    'storefront product scope uses the exact locked assignment authority'
);
$delete = strpos($endpoint, "if (\$action === 'storefront_delete')");
$get = strpos($endpoint, '$existing = $repository->get($storefrontId);', $delete ?: 0);
$write = strpos($endpoint, '$deleted = $repository->delete($storefrontId, $expectedRevision);', $delete ?: 0);
$assert($delete !== false && $get !== false && $write !== false && $get < $write, 'delete verifies preset ownership before mutation');

$assert(str_contains($service, "public const CONTRACT = 'prospektweb.control-center.editors/v1'"), 'catalog is versioned');
$assert(!str_contains($service, 'FOCUS_PRESET_ID'), 'service has no focus preset hardcode');
$assert(str_contains($service, 'ControlCenterFormFirstAuthoringService'), 'form-first uses the form-only provider');
$assert(
    str_contains($service, "array_key_exists('catalog', \$result)"),
    'form-first facade rejects the removed catalog side channel'
);
$assert(!str_contains($service, 'ControlCenterStorefrontEditorService'), 'removed template provider is absent');
$assert(!str_contains($service, 'StandaloneCatalogSelectionMapper'), 'product scope has no fixed allowlist');
$assert(str_contains($service, 'public function assertStorefrontProductsBelongToPreset('), 'storefront membership invariant exists');
$assert(str_contains($service, 'public function getPresetProductCatalog('), 'product assignment has a dedicated catalog');
$assert(str_contains($service, 'public function setPresetProducts('), 'product assignment is explicit');
$assert(str_contains($service, 'public function previewPresetProductImpact('), 'product assignment exposes a read-only impact preview');
$previewPosition = strpos($service, 'public function previewPresetProductImpact(');
$setPosition = strpos($service, 'public function setPresetProducts(');
$assert(
    $previewPosition !== false
        && $setPosition !== false
        && str_contains($service, 'affectedStorefronts')
        && str_contains($service, 'expectedImpactFingerprint')
        && str_contains($service, "(string)\$lockedImpact['impactFingerprint']")
        && str_contains($endpoint, "'impactFingerprint'"),
    'product impact contract lists and consumes an exact locked storefront proof before mutation'
);
$assert(str_contains($service, 'Products already assigned to other presets:'), 'second CALC_PRESET assignment is rejected');
$activationBranch = strpos($endpoint, "if (\$action === 'set_preset_active')");
$activationEnd = strpos($endpoint, "if (\$action === 'create_preset')", $activationBranch ?: 0);
$activationSource = $activationBranch !== false && $activationEnd !== false
    ? substr($endpoint, $activationBranch, $activationEnd - $activationBranch)
    : '';
$assert(
    str_contains($activationSource, "['action', 'sessid', 'presetId', 'expected_revision', 'active']")
        && str_contains($activationSource, 'setPresetActive($presetId, $expectedRevision, $active)')
        && !str_contains($activationSource, 'presetIds'),
    'activation endpoint is single-preset exact CAS with no bulk presetIds payload'
);

$assert(!str_contains($host, 'OPEN_STOREFRONT_EDITOR'), 'host has no separate storefront editor bridge');
$assert(!str_contains($host, 'prospektweb_frontcalc_editor.php'), 'host does not launch the removed editor');
$assert(str_contains($host, "message.type === 'OPEN_CALC_EDITOR'"), 'calculator editor launch remains');
$assert(
    str_contains($host, "['controlCenterInstanceId', 'mode', 'presetId', 'returnRoute', 'versionId']")
        && str_contains($host, "targetUrl.searchParams.set('version_id', versionId)")
        && str_contains($host, "targetUrl.searchParams.set('version_mode', editorMode)"),
    'calculator editor launch carries the exact focused version and access mode'
);
$assert(
    str_contains($endpoint, "if (\$action === 'version_form_load')")
        && str_contains($endpoint, "if (\$action === 'version_form_save_draft')")
        && str_contains($endpoint, 'CalculatorVersionFormDocumentService'),
    'version drafts own isolated form documents instead of sharing the legacy preset draft'
);
$assert(
    str_contains($endpoint, "'inputMappings', 'expectedInputMappingsHash'")
        && str_contains($endpoint, '$versionComponents->validateInputMappings(')
        && str_contains($endpoint, '$components[\'inputMappings\'] = $mappingValidation[\'mapping\'];')
        && str_contains($endpoint, 'Связи Bitrix изменены в другой вкладке'),
    'version form save coordinates prospective form and input mappings under exact component CAS'
);
$assert(
    str_contains($calculator, "\$versionMode = in_array")
        && str_contains($integration, "this.config.versionMode === 'readonly'")
        && str_contains($integration, 'CALCULATOR_VERSION_READ_ONLY'),
    'published calculation logic is fail-closed against editor mutations'
);
$assert(
    str_contains($endpoint, 'prepareVersionEditorInitPayloadReadOnly(')
        && str_contains($endpoint, "(int)(\$logic['workingPresetId'] ?? 0) !== \$workingPresetId")
        && str_contains($integration, "action: 'version_logic_init'")
        && str_contains($integration, 'endpoint = this.config.versionAjaxEndpoint')
        && str_contains($integration, 'const serverMessage = data && (data.message || data.error || data.details);'),
    'version logic INIT must load the isolated graph from its full version bundle and expose server conflicts'
);
$assert(!str_contains($calculator, 'StandaloneCatalogSelectionMapper'), 'calculator launch has no preset/product allowlist');
$assert(!str_contains($calculator, '=== 12740'), 'calculator launch has no pilot gate');
$assert(
    str_contains($calculator, 'PresetProductAssignmentPropertyAuthorityService')
        && str_contains($calculator, "['ID' => \$calcPresetPropertyId]")
        && !str_contains($calculator, "['CODE' => 'CALC_PRESET']"),
    'catalog launch revalidates product preset membership through one exact property ID'
);

foreach (['Phase5aParityContractService', 'Preset12740', 'NeutralFormulaPolicy', 'CatalogAdapterDefinitionService'] as $legacyClass) {
    $assert(!str_contains($autoload . $diagnostic, $legacyClass), 'autoload/diagnostic retains ' . $legacyClass);
}
$assert(str_contains($autoload, 'FormFirstDependencyContractService'), 'clean dependency contract is autoloaded');
$assert(str_contains($autoload, 'CalculatorInputMappingService'), 'input mapping service is autoloaded');
$assert(str_contains($autoload, 'CatalogOutputMappingService'), 'output mapping service is autoloaded');
$assert(str_contains($autoload, 'PresetSectionSelectorService'), 'section selector is autoloaded');
$assert(str_contains($autoload, 'CalculatorCatalogService'), 'calculator catalog authority is autoloaded');
$assert(str_contains($diagnostic, 'CalculatorCatalogService.php'), 'calculator catalog authority is diagnosed');

echo "Control center editors API static tests passed\n";
