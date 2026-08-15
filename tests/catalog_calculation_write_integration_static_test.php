<?php

$root = dirname(__DIR__);
$service = file_get_contents($root . '/lib/Services/CatalogCalculationWriteService.php');
$batch = file_get_contents($root . '/lib/Services/BatchRecalculateService.php');
$globals = file_get_contents($root . '/lib/Services/GlobalSymbolService.php');
$offerWriter = file_get_contents($root . '/lib/Services/OfferUpdateService.php');
$init = file_get_contents($root . '/lib/Calculator/InitPayloadService.php');
$endpoint = file_get_contents($root . '/tools/calculator_ajax.php');
$elementData = file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$autoload = file_get_contents($root . '/include.php');
$bridge = file_get_contents($root . '/install/assets/js/integration.js');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

foreach ([$service, $batch, $globals, $offerWriter, $init, $endpoint, $elementData, $autoload, $bridge] as $source) {
    $assert(is_string($source), 'all catalog write integration sources are readable');
}

$assert(strpos($service, 'prepareCatalogWritePayload') !== false, 'preview/apply re-resolve runtime by offer IDs');
$assert(strpos($service, 'projectPinnedResultsForWrite') !== false, 'only raw-pinned adapter output reaches the writer');
$assert(strpos($service, "['catalogScenarios']") !== false, 'CatalogScenario is the server-side target allowlist');
$assert(strpos($service, "hash('sha256'") !== false, 'preview is content fingerprinted');
$assert(strpos($service, "'catalogState'") !== false
    && strpos($service, "'publication'") !== false
    && strpos($service, "'adapterRevision'") !== false
    && strpos($service, "'catalogScenarios'") !== false
    && strpos($service, "'projectedResults'") !== false, 'fingerprint binds state, publication, adapter and projection');
$assert(substr_count($service, 'FOR UPDATE') >= 1
    && strpos($service, 'b_iblock_element') !== false
    && strpos($service, 'b_catalog_product') !== false
    && strpos($service, 'b_catalog_price') !== false
    && strpos($service, 'b_iblock_element_property') !== false
    && strpos($service, 'b_option') !== false, 'apply pins catalog sinks, scenario inputs, publication and adapter rows');
$assert(strpos($service, 'startTransaction()') !== false
    && strpos($service, 'commitTransaction()') !== false
    && strpos($service, 'rollbackTransaction()') !== false, 'apply owns one all-or-nothing transaction');
$assert(strpos($service, 'updateOffersFromCalculation($projected, true, false)') !== false, 'nested offer writes cannot commit independently');
$assert(strpos($service, 'withMutationLock') !== false, 'adapter CAS saves cannot race the checked catalog write');
$assert(strpos($service, 'calculateOffersForPreview') !== false
    && strpos($service, 'clientResultComparison') !== false, 'client results are comparison-only and calc-server is the sole authority');
$assert(strpos($service, 'lockRuntimeSourceRows') !== false
    && strpos($service, 'b_catalog_group') !== false, 'apply locks preset resources and price authority before pinned CAS');
$assert(strpos($service, 'SELECT ID FROM b_catalog_group ORDER BY ID FOR UPDATE') !== false
    && strpos($service, 'SELECT CATALOG_GROUP_ID, LANG FROM b_catalog_group_lang') !== false,
    'the complete price-type membership, including an empty set, is range-locked and compared');
$assert(strpos($service, 'SELECT ID, IBLOCK_ID FROM b_iblock_element WHERE IBLOCK_ID IN (') !== false
    && strpos($service, 'ORDER BY IBLOCK_ID, ID FOR UPDATE') !== false
    && strpos($service, 'sourceIblockIds') !== false,
    'configured source iblock ranges block new sibling/runtime element phantoms');
$assert(strpos($service, 'publishedAuthoringFromRaw') !== false
    && strpos($service, 'publishedSnapshotFromRaw') !== false
    && strpos($service, 'loadFromRaw') !== false
    && strpos($service, 'prepareCatalogWritePayloadPinned') !== false, 'locked CAS bypasses Bitrix Option process cache');
$assert(strpos($service, 'PRESET_12740_NEUTRAL_INPUT_ACTIVE') !== false
    && strpos($service, 'parseNeutralInputOption') !== false
    && strpos($service, 'lockRuntimeOptionRows') !== false, 'neutral-input mode is read raw and pinned under the runtime option lock');
$assert(strpos($service, 'b_catalog_measure_ratio') !== false
    && strpos($service, 'b_catalog_measure') !== false
    && strpos($service, 'b_iblock_property_enum') !== false
    && strpos($service, 'globalSymbolIblockIds') !== false, 'writer locks measure, property enum and global-symbol runtime authority');
$assert(strpos($batch, "'elementsSiblings' => \$initPayload['elementsSiblings'] ?? []") !== false
    && strpos($batch, "'globalSymbols' => \$initPayload['globalSymbols'] ?? []") !== false
    && strpos($batch, "'neutralInputRequired' => (\$initPayload['neutralInputRequired'] ?? false) === true") !== false
    && strpos($batch, "'runtimeConfigSnapshot' => \$initPayload['_runtimeConfigSnapshot'] ?? []") !== false,
    'calculation CAS binds sibling, global, neutral-input and direct ConfigManager authority');
$assert(strpos($batch, 'preparePresetCalculationPayloadReadOnlyPinned(') !== false
    && strpos($globals, 'public function listReadOnly(') !== false, 'catalog preview uses mutation-free payload and global-symbol reads');
$assert(strpos($batch, "'CALC_OPERATIONS_VARIANTS'") !== false
    && strpos($batch, "'CALC_MATERIALS_VARIANTS'") !== false
    && strpos($batch, "'sourceIblockIds' => \$sourceIblockIds") !== false,
    'runtime locks carry configured membership authorities even when a source currently has no elements');
$assert(strpos($init, 'preparePresetCalculationPayloadReadOnlyPinned') !== false
    && strpos($init, 'buildPinnedRuntimeIblockMap') !== false
    && strpos($init, "runtimeIblockId('CALC_PRESETS')") !== false
    && strpos($init, "runtimeIblockId('CALC_OPERATIONS_VARIANTS')") !== false,
    'preset and all calculation directories resolve from the pinned direct option map');
$assert(strpos($init, 'Catalog calculation requires PRESET_12740_NEUTRAL_INPUT_ACTIVE=Y.') !== false
    && strpos($service, "\$payload['_neutralInputRequired'] !== true") !== false,
    'catalog preview/apply fail closed unless the neutral-input cutover is active');
$assert(strpos($globals, 'public function listReadOnlyFromIblockId(') !== false
    && strpos($batch, 'storageIblockIdReadOnly()') === false
    && strpos($service, 'listReadOnlyFromIblockId(') !== false,
    'catalog authority resolves the global-symbol registry only from the pinned option ID');
$assert(strpos($service, 'captureRuntimeConfigSnapshot()') !== false
    && strpos($service, "(SITE_ID IS NULL OR SITE_ID='')") !== false
    && strpos($service, 'Duplicate global runtime config option row.') !== false
    && strpos($service, 'ConfigManager options changed after calc-server calculation.') !== false,
    'direct global ConfigManager rows are snapshotted, duplicate-checked, locked and compared');
$assert(strpos($service, 'globalSymbolProperties') !== false
    && strpos($service, 'Global-symbol property metadata changed after calc-server calculation.') !== false
    && strpos($service, 'IBLOCK_TYPE_ID') !== false,
    'global-symbol storage identity and required property metadata are checked under row locks');
$assert(strpos($service, 'RECEIPT_CONTRACT') !== false
    && strpos($service, 'targetStateFingerprint') !== false
    && strpos($service, "\$response['replayed'] = true") !== false, 'apply has a durable exact-replay receipt');
$assert(strpos($service, 'BATCH_RECEIPT_CONTRACT') !== false
    && strpos($service, 'saveBatchReceipt(') !== false
    && strpos($batch, 'replayAuthoritativeBatch(') !== false, 'batch apply has an in-transaction receipt probed before network retry');
$assert(strpos($offerWriter, 'bool $manageTransactions = true') !== false, 'legacy callers retain per-offer transaction management by default');
$writeResolverStart = strpos($init, 'public function prepareCatalogWritePayload');
$writeResolverEnd = strpos($init, 'private function buildEditorRuntime', $writeResolverStart ?: 0);
$writeResolver = $writeResolverStart !== false && $writeResolverEnd !== false
    ? substr($init, $writeResolverStart, $writeResolverEnd - $writeResolverStart)
    : '';
$assert($writeResolver !== ''
    && strpos($writeResolver, 'createPreset') === false
    && strpos($writeResolver, 'SchemaRepairService') === false
    && strpos($writeResolver, 'CatalogPropertyCodeMigrationService') === false, 'write resolver never creates, repairs or migrates catalog state');
$adapterPreviewStart = strpos($endpoint, 'function handlePreviewCatalogAdapter');
$adapterSaveStart = strpos($endpoint, 'function handleSaveCatalogAdapter');
$adapterEnd = strpos($endpoint, 'function decodeCatalogAdapterDefinition', $adapterSaveStart ?: 0);
$adapterPaths = $adapterPreviewStart !== false && $adapterSaveStart !== false && $adapterEnd !== false
    ? substr($endpoint, $adapterPreviewStart, $adapterEnd - $adapterPreviewStart)
    : '';
$adapterSavePath = $adapterSaveStart !== false && $adapterEnd !== false
    ? substr($endpoint, $adapterSaveStart, $adapterEnd - $adapterSaveStart)
    : '';
$assert($adapterPaths !== ''
    && strpos($adapterPaths, 'prepareCatalogWritePayload(') !== false
    && strpos($adapterPaths, 'prepareInitPayload(') === false,
    'adapter preview uses the read-only catalog resolver');
$assert($adapterSavePath !== ''
    && strpos($adapterSavePath, '->saveValidatedAdapter(') !== false
    && strpos($adapterSavePath, 'CatalogAdapterDefinitionService();') === false
    && strpos($adapterSavePath, '->save(') === false
    && strpos($adapterSavePath, 'preparePresetPayload(') === false,
    'adapter save delegates atomically to the validated writer with no commit-then-refresh gap');

foreach ([
    'PREVIEW_CATALOG_WRITE_REQUEST',
    'PREVIEW_CATALOG_WRITE_RESULT',
    'APPLY_CATALOG_WRITE_REQUEST',
    'APPLY_CATALOG_WRITE_RESULT',
] as $messageType) {
    $assert(strpos($endpoint, $messageType) !== false, 'endpoint exposes ' . $messageType);
    $assert(strpos($bridge, $messageType) !== false, 'iframe bridge routes ' . $messageType);
}
$assert(strpos($bridge, 'handleCatalogWriteLifecycleRequest') !== false, 'bridge forwards preview/apply without the legacy direct-save path');
$assert(strpos($endpoint, 'assertCatalogWritePwrtRequest') !== false, 'PWRT payloads are strict and POST-only');
$assert(strpos($endpoint, "(string)(defined('SITE_ID') ? SITE_ID : 's1')") !== false
    && strpos($endpoint, "\$payload['siteId'] ??") === false,
    'catalog price writes use server site context rather than client routing input');
$assert(strpos($endpoint, "case 'SAVE_CALCULATION_REQUEST':\n                throw new \\RuntimeException('USE_CATALOG_WRITE_PREVIEW_APPLY', 409);") !== false,
    'legacy PWRT client-result writes fail closed');
$assert(strpos($elementData, "case 'updateOffersFromCalculation':\n                        throw new \\RuntimeException('USE_CATALOG_WRITE_PREVIEW_APPLY', 409);") !== false,
    'legacy refresh-command client-result writes fail closed');
$assert(strpos($autoload, 'CatalogCalculationWriteService') !== false, 'write service is registered in the module autoloader');

echo "Catalog calculation write integration static tests passed\n";
