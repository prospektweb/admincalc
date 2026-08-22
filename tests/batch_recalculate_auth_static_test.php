<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/lib/Services/BatchRecalculateService.php');
$endpoint = (string)file_get_contents($root . '/tools/batch_recalculate.php');
$writer = (string)file_get_contents($root . '/lib/Services/CatalogCalculationWriteService.php');
$signer = (string)file_get_contents($root . '/lib/Services/CalcServerRequestSigner.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    "headers(\$requestBody, 'POST', '/calculate')",
    "'X-Frontcalc-Signature: '",
    'normalizeCalcServerUrl',
    'array_chunk($offerIds, self::PREVIEW_CHUNK_SIZE)',
    'captureOfferStateFingerprints',
    'replayAuthoritativeBatch(',
    'preparePresetCalculationPayloadReadOnlyPinned(',
    "'catalog-input-mapping'",
    "['catalogInputMapping']",
    "['catalogOutputMapping']",
    "['editorRuntime']",
    "['globalSymbols']",
    "['neutralInputRequired']",
] as $needle) {
    $assert(str_contains($service . $signer, $needle), 'Missing batch invariant: ' . $needle);
}

foreach ([
    "header('Cache-Control: no-store, private')",
    "\$requestMethod !== 'POST'",
    'check_bitrix_sessid()',
    '$USER->IsAdmin()',
    'BatchRecalculateService::normalizeRequestedPresetIds($presetIds)',
    'BatchRecalculateService::normalizeProductIdsByPresetScope($rawMap)',
    "if (\$action === 'preview')",
    "'PREVIEW_REQUIRED'",
    "'PREVIEW_EXPIRED'",
    "'PREVIEW_STALE'",
] as $needle) {
    $assert(str_contains($endpoint, $needle), 'Missing batch endpoint invariant: ' . $needle);
}

$assert(!str_contains($service, 'CatalogAdapterDefinitionService'), 'removed adapter is not a batch dependency');
$assert(!str_contains($service, 'StandaloneCatalogSelectionMapper'), 'batch has no fixed product allowlist');
$assert(!str_contains($service, 'StandalonePresetRuntime'), 'batch uses the published form runtime catalog');
$assert(str_contains($service, 'PresetRuntimeCatalog'), 'published form builds validation options');
$assert(str_contains($writer, 'withOutputMappingMutationLock'), 'catalog writes serialize with the output mapping');
$assert(str_contains($writer, 'CALCULATOR_INPUT_MAPPING_'), 'input mapping revision is locked for write CAS');
$assert(str_contains($writer, 'CATALOG_OUTPUT_MAPPING_'), 'output mapping revision is locked for write CAS');

echo "Batch recalculation auth/static tests passed\n";
