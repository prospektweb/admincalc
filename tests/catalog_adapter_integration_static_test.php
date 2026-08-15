<?php

$root = dirname(__DIR__);
$include = file_get_contents($root . '/include.php');
$adapter = file_get_contents($root . '/lib/Services/CatalogAdapterDefinitionService.php');
$mapper = file_get_contents($root . '/lib/Services/StandaloneCatalogSelectionMapper.php');
$init = file_get_contents($root . '/lib/Calculator/InitPayloadService.php');
$batch = file_get_contents($root . '/lib/Services/BatchRecalculateService.php');
$writer = file_get_contents($root . '/lib/Services/CatalogCalculationWriteService.php');
$ajax = file_get_contents($root . '/tools/calculator_ajax.php');
$integration = file_get_contents($root . '/install/assets/js/integration.js');
$diagnostic = file_get_contents($root . '/lib/Diagnostic/ModuleDiagnostic.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(strpos($include, 'CatalogAdapterDefinitionService') !== false, 'the adapter service is registered in the Bitrix module autoloader');
$assert(strpos($adapter, "prospektweb.calc.catalog-adapter/v1") !== false, 'the adapter has an explicit versioned contract');
$assert(strpos($adapter, "CatalogAdapterDefinitionService::") === false, 'the adapter does not delegate its authority to a second adapter implementation');
$assert(strpos($adapter, "NeutralCalculationInputBuilder") !== false, 'FormValues reuse the canonical FrontCalc semantic binding converter');
$assert(strpos($adapter, "strpos(\$fieldId, 'CALC_PROP_') === 0") !== false, 'catalog scenarios reject CALC_PROP keys at the FormValues boundary');
$assert(strpos($adapter, "catalog.offer.purchasingPrice") !== false, 'purchasing price is an explicit output target');
$assert(strpos($adapter, "catalog.offer.priceTypes") !== false, 'Bitrix price types are an explicit output target');
$assert(strpos($adapter, "publicationCompileHash") !== false, 'write provenance pins the exact published form compile hash');
foreach (['weight', 'length', 'width', 'height', 'provenance'] as $target) {
    $assert(strpos($adapter, "catalog.offer.{$target}") !== false, "{$target} is an explicit output target");
}
$assert(strpos($adapter, 'saveDirectUnderLock') !== false
    && strpos($adapter, 'SELECT VALUE FROM b_option') !== false
    && strpos($adapter, 'FOR UPDATE') !== false
    && strpos($adapter, '(SITE_ID IS NULL OR SITE_ID=\'\')') !== false,
    'adapter persistence uses direct global b_option row CAS under a DB transaction');
$assert(strpos($adapter, 'projectPinnedResultsForWrite') !== false
    && strpos($adapter, '$definitionIsPinned ? $definition : $this->load()') !== false,
    'writer projection can use its locked raw adapter without a cached Option reload');

$assert(strpos($mapper, '->mapOffer($offer)') !== false, 'the compatibility mapper delegates execution to the versioned adapter');
$assert(strpos($mapper, 'BASE_SELECTION') === false && strpos($mapper, 'PRODUCT_PROFILES') === false, 'the compatibility mapper contains no duplicate business rules');

$assert(strpos($init, "publishedAuthoringForPreset") !== false, 'INIT reads the canonical preset-owned FrontCalc publication');
$assert(strpos($init, "publishedAuthoringForProduct") === false, 'INIT has no permanent product-owned authoring fallback');
$assert(strpos($init, "'editorRuntime'") !== false, 'preset and offer launch payloads expose editorRuntime');
$assert(strpos($init, "'catalogScenarios'") !== false, 'INIT exposes semantic catalog scenarios');
$assert(strpos($init, "'catalogMapping'") !== false, 'INIT exposes incomplete adapter diagnostics without blocking authoring');
$assert(strpos($init, 'readOptionStateDirect') !== false
    && strpos($init, "'adapterPersisted' => \$adapterPersisted") !== false
    && strpos($init, '$adapterService->load($presetId)') === false,
    'INIT exposes adapter persistence from the exact global option row without Option-cache inference');
$assert(strpos($init, "if (\$mode === 'catalog' && (\$preview['ready']") === false, 'incomplete adapter mapping does not abort catalog INIT');
$assert(strpos($init, "'launchContext'") !== false, 'INIT preserves the optional launch envelope separately');

$assert(strpos($ajax, "case 'previewCatalogAdapter':") !== false, 'the adapter has a read-only dry-run action');
$assert(strpos($ajax, "case 'saveCatalogAdapter':") !== false, 'the adapter has a server-side save action');
$assert(strpos($ajax, "expectedRevision") !== false, 'the AJAX save requires an expected CAS revision');
$assert(strpos($ajax, '->saveValidatedAdapter(') !== false
    && strpos($ajax, '$service->save($presetId, $expectedRevision, $definition)') === false,
    'adapter endpoint validates, writes and re-resolves through one transactional service boundary');
$assert(strpos($ajax, 'assertCatalogAdapterMutationAuthority($request)') !== false, 'adapter preview and save use the narrow mutation authority gate');
$assert(strpos($ajax, '!$USER || !$USER->IsAdmin()') !== false, 'adapter authoring is administrator-only');
$assert(strpos($ajax, "!method_exists(\$request, 'isPost') || !\$request->isPost()") !== false, 'adapter mutations are POST-only');
$assert(strpos($ajax, 'check_bitrix_sessid()') !== false, 'adapter mutations retain the endpoint CSRF gate');
$assert(strpos($integration, "SAVE_CATALOG_ADAPTER_REQUEST") !== false, 'the iframe bridge accepts adapter save requests');
$assert(strpos($integration, "SAVE_CATALOG_ADAPTER_RESULT") !== false, 'the iframe bridge returns adapter save results');
$assert(strpos($integration, "this.sendPwrtMessage('INIT', this.initData") !== false, 'a successful CAS save refreshes the authoritative scenarios');
$assert(strpos($integration, 'this.targetOrigin = this.resolveIframeOrigin()') !== false, 'postMessage origin is bound to the configured iframe before READY');
$assert(strpos($integration, "this.targetOrigin = '*'") === false && strpos($integration, "this.targetOrigin || '*'") === false, 'postMessage transport has no wildcard origin fallback');
$assert(strpos($integration, 'message.source !== MODULE_TARGET') !== false && strpos($integration, 'message.target !== MODULE_SOURCE') !== false, 'postMessage validates the exact protocol principals');

foreach ([
    'BatchPreviewFingerprintService.php',
    'BatchRecalculateService.php',
    'CatalogAdapterDefinitionService.php',
    'CatalogCalculationWriteService.php',
    'OfferUpdateService.php',
    'StandaloneCatalogSelectionMapper.php',
    'CatalogCalcPropertyMigrationService.php',
    'Preset12740NeutralInputMigrationService.php',
    'GlobalCodeRefactorService.php',
    'GlobalSymbolService.php',
] as $criticalFile) {
    $assert(strpos($diagnostic, $criticalFile) !== false, $criticalFile . ' is covered by module file diagnostics');
}

$assert(substr_count($batch, 'projectCatalogAdapterResults($offerResults, $requestPayload)') === 1
    && strpos($writer, 'projectPinnedResultsForWrite(') !== false
    && strpos($writer, 'updateOffersFromCalculation($projected, true, false)') !== false,
    'preview and authoritative writer both apply the pinned output allowlist');
$assert(strpos($batch, "['catalogScenarios']") !== false && strpos($batch, "['launchContext']['offerIds']") !== false, 'per-offer idempotency payloads contain only their matching semantic scenarios');
$assert(strpos($batch, "'schemaVersion' => 3") !== false && strpos($batch, "hash('sha256'") !== false, 'idempotency includes the versioned editor runtime in a SHA-256 state hash');
$assert(strpos($batch, "\$payload['neutralInputRequired'] = \$neutralInputRequired") !== false
    && strpos($batch, "\$catalogPayload['_neutralInputRequired'] !== true") !== false,
    'batch payload fails closed unless the raw-pinned neutral-input cutover is active');
$assert(strpos($writer, "\$catalogMapping['adapterPersisted'] !== true") !== false
    && strpos($batch, "\$catalogMapping['adapterPersisted'] ?? null") !== false
    && strpos($writer, 'Catalog calculation requires an explicitly persisted adapter.') !== false,
    'catalog calculations and writes fail closed until the system adapter template is explicitly persisted');

echo "Catalog adapter integration static tests passed\n";
