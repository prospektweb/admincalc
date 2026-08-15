<?php

require_once dirname(__DIR__) . '/lib/Services/CatalogAdapterDefinitionService.php';
require_once dirname(__DIR__) . '/lib/Services/BatchRecalculateService.php';
require_once dirname(__DIR__) . '/lib/Services/BatchPreviewFingerprintService.php';
require_once dirname(__DIR__) . '/lib/Services/CatalogCalculationWriteService.php';

use Prospektweb\Calc\Services\CatalogAdapterDefinitionService;
use Prospektweb\Calc\Services\BatchRecalculateService;
use Prospektweb\Calc\Services\BatchPreviewFingerprintService;
use Prospektweb\Calc\Services\CatalogCalculationWriteService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$expectFailure = static function (callable $callback, string $message, ?int $code = null) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $error) {
        if ($code !== null) {
            $assert($error->getCode() === $code, $message . ' returns the expected code');
        }
        return;
    }
    $assert(false, $message);
};

$adapterRevision = str_repeat('a', 64);
$publicationRevision = 4;
$publicationHash = str_repeat('b', 64);
$scenarioIdsOverride = null;
$scenarioVolume = 100;
$runtimeSourceVersion = 1;
$globalSymbolVersion = 1;
$siblingVersion = 1;
$measureAuthorityVersion = 1;
$globalPropertyAuthorityVersion = 1;
$neutralInputRequired = true;
$adapterPersisted = true;
$catalogMappingRevisionOverride = null;
$runtimeConfigVersion = 1;
$runtimeSkuIblockId = 15;
$runtimeProductIblockId = 14;
$calcServerUrlVersion = 1;
$makeRuntimeConfigSnapshot = static function () use (
    &$runtimeConfigVersion,
    &$runtimeSkuIblockId,
    &$runtimeProductIblockId,
    &$calcServerUrlVersion
): array {
    $keys = [
        'prospektweb.calc' => [
            'CALC_SERVER_URL', 'PRODUCT_IBLOCK_ID', 'SKU_IBLOCK_ID', 'IBLOCK_CALC_PRESETS', 'IBLOCK_CALC_STAGES',
            'IBLOCK_CALC_SETTINGS', 'IBLOCK_CALC_GLOBAL_VALUES', 'IBLOCK_CALC_CUSTOM_FIELDS',
            'IBLOCK_CALC_MATERIALS', 'IBLOCK_CALC_MATERIALS_VARIANTS', 'IBLOCK_CALC_OPERATIONS',
            'IBLOCK_CALC_OPERATIONS_VARIANTS', 'IBLOCK_CALC_EQUIPMENT', 'IBLOCK_CALC_DETAILS',
        ],
        'prospektweb.frontcalc' => [
            'PRODUCTS_IBLOCK_ID', 'OFFERS_IBLOCK_ID', 'IBLOCK_CALC_PRESETS', 'IBLOCK_CALC_STAGES',
            'IBLOCK_CALC_SETTINGS', 'IBLOCK_CALC_GLOBAL_VALUES', 'IBLOCK_CALC_CUSTOM_FIELDS',
            'IBLOCK_CALC_MATERIALS', 'IBLOCK_CALC_MATERIALS_VARIANTS', 'IBLOCK_CALC_OPERATIONS',
            'IBLOCK_CALC_OPERATIONS_VARIANTS', 'IBLOCK_CALC_EQUIPMENT', 'IBLOCK_CALC_DETAILS',
        ],
    ];
    $snapshot = [];
    foreach ($keys as $moduleId => $names) {
        foreach ($names as $name) {
            $snapshot[$moduleId . ':' . $name] = null;
        }
    }
    $snapshot['prospektweb.calc:IBLOCK_CALC_PRESETS'] = '41';
    $snapshot['prospektweb.calc:IBLOCK_CALC_STAGES'] = (string)(41 + $runtimeConfigVersion);
    $snapshot['prospektweb.calc:IBLOCK_CALC_GLOBAL_VALUES'] = '60';
    $snapshot['prospektweb.calc:PRODUCT_IBLOCK_ID'] = (string)$runtimeProductIblockId;
    $snapshot['prospektweb.calc:SKU_IBLOCK_ID'] = (string)$runtimeSkuIblockId;
    $snapshot['prospektweb.calc:CALC_SERVER_URL'] = $calcServerUrlVersion === 1
        ? 'https://calc.example.test'
        : 'https://calc-new.example.test';
    ksort($snapshot, SORT_STRING);
    return $snapshot;
};
$current = [15320 => [
    'id' => 15320,
    'iblockId' => 15,
    'name' => 'Визитки 100 шт. 4+0',
    'purchasingPrice' => 400.0,
    'purchasingCurrency' => 'RUB',
    'attributes' => ['width' => 80.0, 'length' => 40.0, 'height' => 2.0, 'weight' => 100.0],
    'prices' => [[
        'typeId' => 1,
        'price' => 500.0,
        'currency' => 'RUB',
        'quantityFrom' => null,
        'quantityTo' => null,
    ]],
]];

$makePayload = static function () use (
    &$current,
    &$adapterRevision,
    &$publicationRevision,
    &$publicationHash,
    &$scenarioIdsOverride,
    &$scenarioVolume,
    &$runtimeSourceVersion,
    &$globalSymbolVersion,
    &$siblingVersion,
    &$measureAuthorityVersion,
    &$neutralInputRequired,
    &$adapterPersisted,
    &$catalogMappingRevisionOverride,
    $makeRuntimeConfigSnapshot
): array {
    $offerIds = array_keys($current);
    sort($offerIds, SORT_NUMERIC);
    $scenarios = [];
    $scenarioIds = is_array($scenarioIdsOverride) ? $scenarioIdsOverride : $offerIds;
    foreach ($scenarioIds as $offerId) {
        $scenarios[] = [
            'contract' => CatalogAdapterDefinitionService::SCENARIO_CONTRACT,
            'scenarioId' => 'offer:' . $offerId,
            'presetId' => 12740,
            'publicationRevision' => $publicationRevision,
            'publicationCompileHash' => $publicationHash,
            'adapterRevision' => $adapterRevision,
            'target' => ['productId' => 12727, 'offerId' => $offerId, 'name' => 'Визитки'],
            'values' => ['volume' => $scenarioVolume, 'colorScheme' => '4+0'],
        ];
    }
    return [
        'presetId' => 12740,
        'selectedOffers' => array_values($current),
        'priceTypes' => [['id' => 1]],
        'editorRuntime' => [
            'contract' => CatalogAdapterDefinitionService::EDITOR_RUNTIME_CONTRACT,
            'launchContext' => [
                'contract' => CatalogAdapterDefinitionService::LAUNCH_CONTEXT_CONTRACT,
                'mode' => 'catalog',
                'presetId' => 12740,
                'offerIds' => $offerIds,
            ],
            'publication' => ['revision' => $publicationRevision, 'compileHash' => $publicationHash],
            'catalogAdapter' => ['revision' => $adapterRevision],
            'catalogScenarios' => $scenarios,
            'catalogMapping' => [
                'adapterPersisted' => $adapterPersisted,
                'adapterRevision' => $catalogMappingRevisionOverride ?? $adapterRevision,
            ],
        ],
        '_publishedSnapshot' => [
            'version' => 1,
            '_form_first' => [
                'publishedRevision' => $publicationRevision,
                'compileHash' => $publicationHash,
            ],
        ],
        '_neutralInputRequired' => $neutralInputRequired,
        '_globalSymbols' => [[
            'id' => 17000,
            'iblockId' => 60,
            'code' => 'test_global',
            'kind' => 'constant',
            'dataType' => 'number',
            'initialValue' => (string)$globalSymbolVersion,
        ]],
        '_globalSymbolIblockId' => 60,
        '_productIblockIds' => [12727 => 14],
        '_runtimeConfigSnapshot' => $makeRuntimeConfigSnapshot(),
    ];
};

$result = [[
    'offerId' => 15320,
    'purchasePrice' => 410.25,
    'currency' => 'RUB',
    'priceRangesWithMarkup' => [[
        'quantityFrom' => null,
        'quantityTo' => null,
        'prices' => [['typeId' => 1, 'basePrice' => 530.0, 'currency' => 'RUB']],
    ]],
    'details' => [[
        'outputs' => ['width' => 90.0, 'length' => 50.0, 'height' => 3.0, 'weight' => 120.0],
    ]],
    'catalogAdapterProvenance' => [
        'contract' => CatalogAdapterDefinitionService::CONTRACT,
        'presetId' => 12740,
        'revision' => $adapterRevision,
        'publicationRevision' => $publicationRevision,
        'publicationCompileHash' => $publicationHash,
    ],
]];
$serverResult = $result;

$captureCalculationState = static function (array $offerIds, string $siteId) use (
    &$publicationRevision,
    &$publicationHash,
    &$adapterRevision,
    &$scenarioVolume,
    &$runtimeSourceVersion,
    &$globalSymbolVersion,
    &$siblingVersion,
    &$measureAuthorityVersion,
    &$globalPropertyAuthorityVersion,
    &$neutralInputRequired,
    &$runtimeConfigVersion,
    &$runtimeSkuIblockId,
    &$runtimeProductIblockId,
    &$calcServerUrlVersion
): array {
    if ($siteId !== 's1') {
        throw new RuntimeException('Unexpected state site');
    }
    $states = [];
    foreach ($offerIds as $offerId) {
        $states[(int)$offerId] = [
            'calculation' => hash('sha256', json_encode([
                'offerId' => (int)$offerId,
                'publicationRevision' => $publicationRevision,
                'publicationHash' => $publicationHash,
                'adapterRevision' => $adapterRevision,
                'scenarioVolume' => $scenarioVolume,
                'runtimeSourceVersion' => $runtimeSourceVersion,
                'globalSymbolVersion' => $globalSymbolVersion,
                'siblingVersion' => $siblingVersion,
                'measureAuthorityVersion' => $measureAuthorityVersion,
                'globalPropertyAuthorityVersion' => $globalPropertyAuthorityVersion,
                'neutralInputRequired' => $neutralInputRequired,
                'runtimeConfigVersion' => $runtimeConfigVersion,
                'runtimeSkuIblockId' => $runtimeSkuIblockId,
                'runtimeProductIblockId' => $runtimeProductIblockId,
                'calcServerUrlVersion' => $calcServerUrlVersion,
            ], JSON_UNESCAPED_SLASHES)),
        ];
    }
    ksort($states, SORT_NUMERIC);
    return $states;
};

$events = [];
$writeCount = 0;
$failWrite = false;
$skipMutation = false;
$receipts = [];
$mutateScenarioOnLock = false;
$mutatePublicationOnRuntimeLock = false;
$mutateGlobalOnRuntimeLock = false;
$mutateSiblingOnRuntimeLock = false;
$mutateMeasureOnRuntimeLock = false;
$mutateGlobalMetadataOnRuntimeLock = false;
$mutateNeutralOnOptionLock = false;
$mutateConfigOnOptionLock = false;
$mutateCalcServerUrlOnOptionLock = false;
$calculateResults = static function (array $offerIds, string $siteId) use (
        &$serverResult,
        &$publicationRevision,
        &$publicationHash,
        &$adapterRevision,
        &$runtimeSourceVersion,
        &$globalSymbolVersion,
        &$siblingVersion,
        &$measureAuthorityVersion,
        &$globalPropertyAuthorityVersion,
        &$neutralInputRequired,
        $makeRuntimeConfigSnapshot,
        $captureCalculationState,
        &$events
    ): array {
        $events[] = 'calculate';
        return [
            'contract' => BatchRecalculateService::SERVER_CALCULATION_CONTRACT,
            'results' => $serverResult,
            'stateFingerprints' => $captureCalculationState($offerIds, $siteId),
            'provenance' => [
                'contract' => BatchRecalculateService::SERVER_CALCULATION_CONTRACT . '/provenance',
                'presetId' => 12740,
                'publication' => [
                    'revision' => $publicationRevision,
                    'compileHash' => $publicationHash,
                ],
                'adapterRevision' => $adapterRevision,
                'neutralInputRequired' => $neutralInputRequired,
                'runtimeConfigSnapshot' => $makeRuntimeConfigSnapshot(),
                'requestHashes' => [hash('sha256', json_encode([
                    'offerIds' => $offerIds,
                    'publicationRevision' => $publicationRevision,
                    'publicationHash' => $publicationHash,
                    'adapterRevision' => $adapterRevision,
                    'runtimeSourceVersion' => $runtimeSourceVersion,
                    'globalSymbolVersion' => $globalSymbolVersion,
                    'siblingVersion' => $siblingVersion,
                    'measureAuthorityVersion' => $measureAuthorityVersion,
                    'globalPropertyAuthorityVersion' => $globalPropertyAuthorityVersion,
                    'neutralInputRequired' => $neutralInputRequired,
                    'runtimeConfigSnapshot' => $makeRuntimeConfigSnapshot(),
                ]))],
                'sourceVersions' => ['test-server-v1'],
                'runtimeLocks' => [
                    'elements' => [
                        ['id' => 12740, 'iblockId' => 41],
                        ['id' => 16000, 'iblockId' => 42],
                        ['id' => 17000, 'iblockId' => 60],
                    ],
                    'sourceIblockIds' => [41, 42, 60],
                    'priceTypeIds' => [1],
                    'globalSymbolIblockIds' => [60],
                    'globalSymbolProperties' => [[
                        'iblockId' => 60,
                        'properties' => [
                            'DATA_TYPE' => 20,
                            'INITIAL_VALUE' => 21,
                            'KIND' => 22,
                            'PRESET_ID' => 23,
                        ],
                    ]],
                    'measureRatioProductIds' => [12740, 16000, 17000],
                    'measureIds' => [1],
                    'propertyIds' => [10, 11, 20, 21, 22, 23],
                ],
            ],
        ];
    };
$service = new CatalogCalculationWriteService([
    'capture_runtime_config' => static function () use ($makeRuntimeConfigSnapshot): array {
        return $makeRuntimeConfigSnapshot();
    },
    'calculate_results' => $calculateResults,
    'capture_calculation_state' => $captureCalculationState,
    'resolve_runtime' => static function (array $offerIds, string $siteId) use ($makePayload): array {
        if ($offerIds !== [15320] || $siteId !== 's1') {
            throw new RuntimeException('Unexpected resolver request');
        }
        return $makePayload();
    },
    'project_results' => static function (
        array $results,
        array $_priceTypes,
        array $_adapter,
        array $_publication
    ): array {
        return $results;
    },
    'validate_projected' => static function (array $projected, array $offerIds): array {
        $row = $projected[0] ?? [];
        $outputs = is_array($row['details'][0]['outputs'] ?? null) ? $row['details'][0]['outputs'] : [];
        $price = is_array($row['priceRangesWithMarkup'][0]['prices'][0] ?? null)
            ? $row['priceRangesWithMarkup'][0]['prices'][0]
            : [];
        return [
            'ready' => count($projected) === count($offerIds),
            'errors' => [],
            'summary' => ['total' => count($projected), 'valid' => count($projected), 'invalid' => 0],
            'offers' => [[
                'offerId' => (int)($row['offerId'] ?? 0),
                'offerName' => '',
                'valid' => true,
                'purchasePrice' => (float)($row['purchasePrice'] ?? 0),
                'currency' => (string)($row['currency'] ?? 'RUB'),
                'dimensions' => [
                    'width' => (float)($outputs['width'] ?? 0),
                    'length' => (float)($outputs['length'] ?? 0),
                    'height' => (float)($outputs['height'] ?? 0),
                    'weight' => (float)($outputs['weight'] ?? 0),
                ],
                'prices' => [[
                    'typeId' => (int)($price['typeId'] ?? 0),
                    'basePrice' => (float)($price['basePrice'] ?? 0),
                    'currency' => (string)($price['currency'] ?? 'RUB'),
                    'quantityFrom' => null,
                    'quantityTo' => null,
                ]],
                'errors' => [],
            ]],
        ];
    },
    'write_projected' => static function (array $projected) use (
        &$current,
        &$events,
        &$writeCount,
        &$failWrite,
        &$skipMutation
    ): array {
        $events[] = 'write';
        $writeCount++;
        if ($failWrite) {
            return [
                'status' => 'error',
                'updated' => 0,
                'errors' => [['offerId' => 15320, 'message' => 'synthetic failure']],
                'offers' => [],
            ];
        }
        if (!$skipMutation) {
            $row = $projected[0];
            $outputs = $row['details'][0]['outputs'];
            $current[15320]['purchasingPrice'] = (float)$row['purchasePrice'];
            $current[15320]['purchasingCurrency'] = (string)$row['currency'];
            $current[15320]['attributes'] = [
                'width' => (float)$outputs['width'],
                'length' => (float)$outputs['length'],
                'height' => (float)$outputs['height'],
                'weight' => (float)$outputs['weight'],
            ];
            $price = $row['priceRangesWithMarkup'][0]['prices'][0];
            $current[15320]['prices'] = [[
                'typeId' => (int)$price['typeId'],
                'price' => (float)$price['basePrice'],
                'currency' => (string)$price['currency'],
                'quantityFrom' => null,
                'quantityTo' => null,
            ]];
        }
        return [
            'status' => 'ok',
            'updated' => 1,
            'errors' => [],
            'offers' => [['offerId' => 15320, 'status' => 'ok']],
        ];
    },
    'adapter_mutation_lock' => static function (callable $callback) use (&$events) {
        $events[] = 'adapter-lock';
        return $callback();
    },
    'begin_transaction' => static function () use (&$events): void {
        $events[] = 'begin';
    },
    'lock_catalog_rows' => static function (array $offerIds, array $productIds) use (
        &$events,
        &$mutateScenarioOnLock,
        &$scenarioVolume
    ): void {
        if ($offerIds !== [15320] || $productIds !== [12727]) {
            throw new RuntimeException('Unexpected lock targets');
        }
        $events[] = 'lock';
        if ($mutateScenarioOnLock) {
            $scenarioVolume++;
        }
    },
    'lock_runtime_rows' => static function (array $runtimeLocks) use (
        &$events,
        &$mutatePublicationOnRuntimeLock,
        &$runtimeSourceVersion,
        &$mutateGlobalOnRuntimeLock,
        &$globalSymbolVersion,
        &$mutateSiblingOnRuntimeLock,
        &$siblingVersion,
        &$mutateMeasureOnRuntimeLock,
        &$measureAuthorityVersion,
        &$mutateGlobalMetadataOnRuntimeLock,
        &$globalPropertyAuthorityVersion
    ): void {
        if (($runtimeLocks['elements'][0]['id'] ?? 0) !== 12740
            || ($runtimeLocks['priceTypeIds'] ?? []) !== [1]
            || ($runtimeLocks['sourceIblockIds'] ?? []) !== [41, 42, 60]
            || ($runtimeLocks['globalSymbolIblockIds'] ?? []) !== [60]
            || ($runtimeLocks['globalSymbolProperties'][0]['properties'] ?? []) !== [
                'DATA_TYPE' => 20,
                'INITIAL_VALUE' => 21,
                'KIND' => 22,
                'PRESET_ID' => 23,
            ]
            || ($runtimeLocks['measureRatioProductIds'] ?? []) !== [12740, 16000, 17000]
            || ($runtimeLocks['measureIds'] ?? []) !== [1]
            || ($runtimeLocks['propertyIds'] ?? []) !== [10, 11, 20, 21, 22, 23]) {
            throw new RuntimeException('Unexpected runtime lock targets');
        }
        $events[] = 'runtime-lock';
        if ($mutatePublicationOnRuntimeLock) {
            $runtimeSourceVersion++;
        }
        if ($mutateGlobalOnRuntimeLock) {
            $globalSymbolVersion++;
        }
        if ($mutateSiblingOnRuntimeLock) {
            $siblingVersion++;
        }
        if ($mutateMeasureOnRuntimeLock) {
            $measureAuthorityVersion++;
        }
        if ($mutateGlobalMetadataOnRuntimeLock) {
            $globalPropertyAuthorityVersion++;
        }
    },
    'lock_runtime_options' => static function () use (
        &$events,
        &$mutateNeutralOnOptionLock,
        &$neutralInputRequired,
        &$mutateConfigOnOptionLock,
        &$runtimeConfigVersion,
        &$mutateCalcServerUrlOnOptionLock,
        &$calcServerUrlVersion
    ): void {
        $events[] = 'option-lock';
        if ($mutateNeutralOnOptionLock) {
            $neutralInputRequired = !$neutralInputRequired;
        }
        if ($mutateConfigOnOptionLock) {
            $runtimeConfigVersion++;
        }
        if ($mutateCalcServerUrlOnOptionLock) {
            $calcServerUrlVersion++;
        }
    },
    'commit_transaction' => static function () use (&$events): void {
        $events[] = 'commit';
    },
    'rollback_transaction' => static function () use (&$events): void {
        $events[] = 'rollback';
    },
    'load_receipt' => static function (string $name, bool $_forUpdate) use (&$receipts): ?array {
        return is_array($receipts[$name] ?? null) ? $receipts[$name] : null;
    },
    'save_receipt' => static function (string $name, array $receipt) use (&$receipts): void {
        $receipts[$name] = $receipt;
    },
]);

$neutralInputRequired = false;
$events = [];
$expectFailure(
    static function () use ($service, $result): void {
        $service->preview(12740, [15320], $result, 's1');
    },
    'catalog preview fails closed before calc-server when neutral-input activation is absent',
    409
);
$assert($events === ['calculate'], 'inactive neutral-input mode never reaches catalog locks or writes');
$neutralInputRequired = true;
$events = [];

$adapterPersisted = false;
$expectFailure(
    static function () use ($service, $result): void {
        $service->preview(12740, [15320], $result, 's1');
    },
    'catalog preview cannot calculate from an unpersisted system adapter template',
    409
);
$assert($events === ['calculate'], 'unpersisted adapter never reaches catalog locks or writes');
$adapterPersisted = true;
$events = [];

$catalogMappingRevisionOverride = str_repeat('e', 64);
$expectFailure(
    static function () use ($service, $result): void {
        $service->preview(12740, [15320], $result, 's1');
    },
    'catalog preview rejects a mapping status from another adapter revision'
);
$assert($events === ['calculate'], 'mismatched mapping revision never reaches catalog locks or writes');
$catalogMappingRevisionOverride = null;
$events = [];

$preview = $service->preview(12740, [15320], $result, 's1');
$assert($preview['contract'] === CatalogCalculationWriteService::PREVIEW_CONTRACT, 'preview is versioned');
$assert($preview['ready'] === true, 'complete projection is ready');
$assert(preg_match('/^[a-f0-9]{64}$/D', $preview['fingerprint']) === 1, 'preview has a SHA-256 fingerprint');
$assert(($preview['summary']['changedFields'] ?? 0) === 6, 'preview exposes purchase price, four dimensions and prices');
$assert(($preview['offers'][0]['diff'][0]['old']['value'] ?? null) === 400.0, 'preview exposes the old purchasing price');
$assert(($preview['offers'][0]['diff'][0]['new']['value'] ?? null) === 410.25, 'preview exposes the new purchasing price');
$assert(($preview['clientResultComparison']['matchesAuthoritative'] ?? false) === true, 'matching client result is only reported as matching server authority');
$assert(!isset($preview['_projectedResults']), 'public service preview never exposes projected write payloads');
$assert(!isset(CatalogCalculationWriteService::publicPreview($preview)['_projectedResults']), 'client preview hides the in-process write handoff');

$apply = $service->apply(12740, [15320], $result, 's1', $preview['fingerprint'], 1);
$assert($apply['contract'] === CatalogCalculationWriteService::APPLY_CONTRACT && $apply['applied'] === true, 'matching CAS applies');
$assert($events === ['calculate', 'calculate', 'adapter-lock', 'begin', 'option-lock', 'lock', 'runtime-lock', 'write', 'commit'], 'remote calculation finishes before apply locks options, catalog and exact runtime sources');
$assert($current[15320]['purchasingPrice'] === 410.25 && $current[15320]['prices'][0]['price'] === 530.0, 'apply writes the projected state');
$events = [];
$writesBeforeReplay = $writeCount;
$replay = $service->apply(12740, [15320], $result, 's1', $preview['fingerprint'], 1);
$assert(($replay['replayed'] ?? false) === true && ($replay['applied'] ?? false) === true, 'lost-response replay returns the durable applied receipt');
$assert($events === ['adapter-lock', 'begin', 'option-lock', 'lock', 'commit'], 'receipt replay verifies the locked current target without a network calculation or write');
$assert($writeCount === $writesBeforeReplay, 'exact replay never writes twice');
$receiptName = array_key_first($receipts);
$assert(is_string($receiptName), 'the completed write stores a durable receipt');
$receipts[$receiptName]['createdAt'] = gmdate('c', time() - (8 * 86400));
$events = [];
$expectFailure(
    static function () use ($service, $result, $preview): void {
        $service->apply(12740, [15320], $result, 's1', $preview['fingerprint'], 1);
    },
    'an expired exact replay requires a new preview instead of writing again',
    409
);
$assert($events === ['adapter-lock', 'begin', 'option-lock', 'lock', 'rollback'], 'expired replay fails under locks');
$assert($writeCount === $writesBeforeReplay, 'expired replay never reaches the catalog writer');
$receipts[$receiptName]['createdAt'] = gmdate('c');
$current[15320]['attributes']['weight'] = 121.0;
$events = [];
$expectFailure(
    static function () use ($service, $result, $preview): void {
        $service->apply(12740, [15320], $result, 's1', $preview['fingerprint'], 1);
    },
    'a receipt cannot replay after its written target changes',
    409
);
$assert($events === ['adapter-lock', 'begin', 'option-lock', 'lock', 'rollback'], 'changed replay target fails under catalog locks');
$assert($writeCount === $writesBeforeReplay, 'changed replay target never writes');
$current[15320]['attributes']['weight'] = 120.0;
$assert($service->preview(12740, [15320], $result, 's1')['summary']['changedFields'] === 0, 'readback is idempotent');

$stalePreview = $service->preview(12740, [15320], $result, 's1');
$current[15320]['attributes']['weight'] = 121.0;
$events = [];
$writesBeforeStale = $writeCount;
$expectFailure(
    static function () use ($service, $result, $stalePreview): void {
        $service->apply(12740, [15320], $result, 's1', $stalePreview['fingerprint'], 1);
    },
    'catalog drift invalidates the reviewed preview',
    409
);
$assert($events === ['calculate'], 'stale reviewed catalog state is rejected before acquiring mutation locks');
$assert($writeCount === $writesBeforeStale, 'stale CAS never calls the writer');

$current[15320]['attributes']['weight'] = 120.0;
$revisionPreview = $service->preview(12740, [15320], $result, 's1');
$publicationRevision = 5;
$publicationHash = str_repeat('c', 64);
$changedRevisionPreview = $service->preview(12740, [15320], $result, 's1');
$assert($revisionPreview['fingerprint'] !== $changedRevisionPreview['fingerprint'], 'publication changes invalidate the fingerprint');
$adapterFingerprintBefore = $changedRevisionPreview['fingerprint'];
$adapterRevision = str_repeat('d', 64);
$adapterChangedPreview = $service->preview(12740, [15320], $result, 's1');
$assert($adapterFingerprintBefore !== $adapterChangedPreview['fingerprint'], 'adapter revision changes invalidate the fingerprint');
$scenarioFingerprintBefore = $adapterChangedPreview['fingerprint'];
$scenarioVolume = 200;
$scenarioChangedPreview = $service->preview(12740, [15320], $result, 's1');
$assert($scenarioFingerprintBefore !== $scenarioChangedPreview['fingerprint'], 'catalog input scenario changes invalidate the fingerprint');
$scenarioVolume = 100;

$parentStatePreview = $service->preview(12740, [15320], $result, 's1');
$events = [];
$writesBeforeParentDrift = $writeCount;
$mutateScenarioOnLock = true;
$expectFailure(
    static function () use ($service, $result, $parentStatePreview): void {
        $service->apply(12740, [15320], $result, 's1', $parentStatePreview['fingerprint'], 1);
    },
    'parent-derived adapter input drift between network calculation and locked snapshot is rejected',
    409
);
$mutateScenarioOnLock = false;
$scenarioVolume = 100;
$assert($events === ['calculate', 'adapter-lock', 'begin', 'option-lock', 'lock', 'runtime-lock', 'rollback'], 'parent input drift is caught after exact rows are locked and before write');
$assert($writeCount === $writesBeforeParentDrift, 'parent input drift never reaches writer');

$runtimeStatePreview = $service->preview(12740, [15320], $result, 's1');
$events = [];
$writesBeforeRuntimeDrift = $writeCount;
$mutatePublicationOnRuntimeLock = true;
$expectFailure(
    static function () use ($service, $result, $runtimeStatePreview): void {
        $service->apply(12740, [15320], $result, 's1', $runtimeStatePreview['fingerprint'], 1);
    },
    'stage/detail/material runtime source drift after network calculation is rejected',
    409
);
$mutatePublicationOnRuntimeLock = false;
$runtimeSourceVersion = 1;
$assert($events === ['calculate', 'adapter-lock', 'begin', 'option-lock', 'lock', 'runtime-lock', 'rollback'], 'runtime source drift is caught under exact source locks before write');
$assert($writeCount === $writesBeforeRuntimeDrift, 'runtime source drift never reaches writer');

$globalStatePreview = $service->preview(12740, [15320], $result, 's1');
$events = [];
$mutateGlobalOnRuntimeLock = true;
$expectFailure(
    static function () use ($service, $result, $globalStatePreview): void {
        $service->apply(12740, [15320], $result, 's1', $globalStatePreview['fingerprint'], 1);
    },
    'global symbol drift after server calculation is rejected',
    409
);
$mutateGlobalOnRuntimeLock = false;
$globalSymbolVersion = 1;
$assert($events === ['calculate', 'adapter-lock', 'begin', 'option-lock', 'lock', 'runtime-lock', 'rollback'], 'global symbols are checked after their rows are locked');

$siblingStatePreview = $service->preview(12740, [15320], $result, 's1');
$events = [];
$mutateSiblingOnRuntimeLock = true;
$expectFailure(
    static function () use ($service, $result, $siblingStatePreview): void {
        $service->apply(12740, [15320], $result, 's1', $siblingStatePreview['fingerprint'], 1);
    },
    'elementsSiblings drift after server calculation is rejected',
    409
);
$mutateSiblingOnRuntimeLock = false;
$siblingVersion = 1;
$assert($events === ['calculate', 'adapter-lock', 'begin', 'option-lock', 'lock', 'runtime-lock', 'rollback'], 'sibling sources are checked after their rows are locked');

$measureStatePreview = $service->preview(12740, [15320], $result, 's1');
$events = [];
$mutateMeasureOnRuntimeLock = true;
$expectFailure(
    static function () use ($service, $result, $measureStatePreview): void {
        $service->apply(12740, [15320], $result, 's1', $measureStatePreview['fingerprint'], 1);
    },
    'measure, ratio or property enum drift after server calculation is rejected',
    409
);
$mutateMeasureOnRuntimeLock = false;
$measureAuthorityVersion = 1;
$assert($events === ['calculate', 'adapter-lock', 'begin', 'option-lock', 'lock', 'runtime-lock', 'rollback'], 'measure and property authorities are checked after exact source locks');

$globalMetadataStatePreview = $service->preview(12740, [15320], $result, 's1');
$events = [];
$mutateGlobalMetadataOnRuntimeLock = true;
$expectFailure(
    static function () use ($service, $result, $globalMetadataStatePreview): void {
        $service->apply(12740, [15320], $result, 's1', $globalMetadataStatePreview['fingerprint'], 1);
    },
    'global-symbol property rename, type or deletion drift after server calculation is rejected',
    409
);
$mutateGlobalMetadataOnRuntimeLock = false;
$globalPropertyAuthorityVersion = 1;
$assert($events === ['calculate', 'adapter-lock', 'begin', 'option-lock', 'lock', 'runtime-lock', 'rollback'], 'global-symbol metadata is checked after its property and enum rows are locked');

$neutralStatePreview = $service->preview(12740, [15320], $result, 's1');
$events = [];
$mutateNeutralOnOptionLock = true;
$expectFailure(
    static function () use ($service, $result, $neutralStatePreview): void {
        $service->apply(12740, [15320], $result, 's1', $neutralStatePreview['fingerprint'], 1);
    },
    'neutral-input mode drift after server calculation is rejected',
    409
);
$mutateNeutralOnOptionLock = false;
$neutralInputRequired = true;
$assert($events === ['calculate', 'adapter-lock', 'begin', 'option-lock', 'lock', 'runtime-lock', 'rollback'], 'neutral-input option is re-read only after its row lock');

$configStatePreview = $service->preview(12740, [15320], $result, 's1');
$events = [];
$mutateConfigOnOptionLock = true;
$expectFailure(
    static function () use ($service, $result, $configStatePreview): void {
        $service->apply(12740, [15320], $result, 's1', $configStatePreview['fingerprint'], 1);
    },
    'ConfigManager iblock authority drift after server calculation is rejected',
    409
);
$mutateConfigOnOptionLock = false;
$runtimeConfigVersion = 1;
$assert(
    $events === ['calculate', 'adapter-lock', 'begin', 'option-lock', 'rollback'],
    'ConfigManager options are rejected immediately under the option lock before catalog/source locks: ' . json_encode($events)
);

$calcServerUrlStatePreview = $service->preview(12740, [15320], $result, 's1');
$events = [];
$mutateCalcServerUrlOnOptionLock = true;
$expectFailure(
    static function () use ($service, $result, $calcServerUrlStatePreview): void {
        $service->apply(12740, [15320], $result, 's1', $calcServerUrlStatePreview['fingerprint'], 1);
    },
    'CALC_SERVER_URL authority drift between network calculation and write is rejected',
    409
);
$mutateCalcServerUrlOnOptionLock = false;
$calcServerUrlVersion = 1;
$assert($events === ['calculate', 'adapter-lock', 'begin', 'option-lock', 'rollback'], 'calc-server URL is pinned in the direct option snapshot and rejected under the first lock');

$runtimeSkuIblockId = 16;
$events = [];
$expectFailure(
    static function () use ($service, $result): void {
        $service->preview(12740, [15320], $result, 's1');
    },
    'a process-static SKU iblock mapping warmed from A cannot override direct option authority B',
    409
);
$runtimeSkuIblockId = 15;
$assert($events === ['calculate'], 'warm-cache SKU split brain fails before any catalog lock or write');

$runtimeProductIblockId = 16;
$events = [];
$expectFailure(
    static function () use ($service, $result): void {
        $service->preview(12740, [15320], $result, 's1');
    },
    'a process-static product iblock mapping warmed from A cannot override direct option authority B',
    409
);
$runtimeProductIblockId = 14;
$assert($events === ['calculate'], 'warm-cache product split brain fails before any catalog lock or write');

$scenarioIdsOverride = [];
$expectFailure(
    static function () use ($service, $result): void {
        $service->preview(12740, [15320], $result, 's1');
    },
    'catalog scenarios are the strict target allowlist'
);
$scenarioIdsOverride = null;

$unexpected = $result;
$unexpected[0]['offerId'] = 99999;
$tamperedTargetPreview = $service->preview(12740, [15320], $unexpected, 's1');
$assert(($tamperedTargetPreview['clientResultComparison']['valid'] ?? true) === false, 'an unexpected client target is ignored as write authority');
$assert(($tamperedTargetPreview['offers'][0]['diff'][0]['new']['value'] ?? null) === 410.25, 'unexpected client target cannot alter the server-authoritative preview');
$badRange = $result;
$badRange[0]['priceRangesWithMarkup'][0]['quantityFrom'] = -1;
$tamperedPricePreview = $service->preview(12740, [15320], $badRange, 's1');
$assert(($tamperedPricePreview['clientResultComparison']['matchesAuthoritative'] ?? true) === false, 'tampered client price ranges are comparison-only');
$assert(($tamperedPricePreview['offers'][0]['diff'][5]['new'][0]['price'] ?? null) === 530.0, 'tampered client prices never replace calc-server results');
$missingWeight = $result;
$missingWeight[0]['details'][0]['outputs']['weight'] = null;
$tamperedWeightPreview = $service->preview(12740, [15320], $missingWeight, 's1');
$assert(($tamperedWeightPreview['clientResultComparison']['matchesAuthoritative'] ?? true) === false, 'missing client physical output cannot affect authority');
$assert(($tamperedWeightPreview['offers'][0]['diff'][4]['new'] ?? null) === 120.0, 'server-authoritative weight remains in the preview');

$publicationRevision = 4;
$publicationHash = str_repeat('b', 64);
$adapterRevision = str_repeat('a', 64);
$runtimeSourceVersion = 1;
$authority = $calculateResults([15320], 's1');
$catalogState = [
    'offerId' => 15320,
    'purchasingPrice' => ['value' => 410.25, 'currency' => 'RUB'],
    'dimensions' => ['width' => 90.0, 'length' => 50.0, 'height' => 3.0, 'weight' => 120.0],
    'prices' => [[
        'typeId' => 1,
        'quantityFrom' => null,
        'quantityTo' => null,
        'price' => 530.0,
        'currency' => 'RUB',
    ]],
];
$approvedBatchStates = $authority['stateFingerprints'];
$approvedBatchStates[15320]['catalog'] = hash(
    'sha256',
    CatalogCalculationWriteService::canonicalEncode($catalogState)
);
$approvedBatchResults = BatchPreviewFingerprintService::resultFingerprints([
    'ready' => true,
    'summary' => ['total' => 1, 'valid' => 1, 'invalid' => 0],
    'offers' => [[
        'offerId' => 15320,
        'offerName' => '',
        'valid' => true,
        'purchasePrice' => 410.25,
        'currency' => 'RUB',
        'dimensions' => ['width' => 90.0, 'length' => 50.0, 'height' => 3.0, 'weight' => 120.0],
        'prices' => [[
            'typeId' => 1,
            'basePrice' => 530.0,
            'currency' => 'RUB',
            'quantityFrom' => null,
            'quantityTo' => null,
        ]],
        'errors' => [],
    ]],
    'errors' => [],
]);
$events = [];
$writesBeforeBatchSkip = $writeCount;
$batchRequestId = str_repeat('c', 64);
$batchSkip = $service->applyAuthoritativeBatch(
    12740,
    [15320],
    $authority,
    's1',
    $approvedBatchStates,
    $approvedBatchResults,
    true,
    1,
    $batchRequestId
);
$assert(($batchSkip['offers'][0]['status'] ?? '') === 'skipped', 'onlyChanged skips only after locked catalog/result CAS proves an idempotent target');
$assert($events === ['adapter-lock', 'begin', 'option-lock', 'lock', 'runtime-lock', 'commit'], 'onlyChanged decision is made under catalog and runtime locks');
$assert($writeCount === $writesBeforeBatchSkip, 'locked onlyChanged skip performs no write');
$events = [];
$batchReplay = $service->replayAuthoritativeBatch(
    12740,
    [15320],
    's1',
    $approvedBatchStates,
    $approvedBatchResults,
    1,
    $batchRequestId
);
$assert(($batchReplay['replayed'] ?? false) === true, 'a lost batch response is recovered from the durable transaction receipt');
$assert($events === ['adapter-lock', 'begin', 'option-lock', 'lock', 'commit'], 'batch receipt replay verifies the target without calc-server or a catalog write');
$assert($writeCount === $writesBeforeBatchSkip, 'batch receipt replay never writes twice');

$current[15320]['prices'][0]['price'] = 531.0;
$events = [];
$expectFailure(
    static function () use ($service, $approvedBatchStates, $approvedBatchResults, $batchRequestId): void {
        $service->replayAuthoritativeBatch(
            12740,
            [15320],
            's1',
            $approvedBatchStates,
            $approvedBatchResults,
            1,
            $batchRequestId
        );
    },
    'a batch receipt cannot replay after its written target changes',
    409
);
$assert($events === ['adapter-lock', 'begin', 'option-lock', 'lock', 'rollback'], 'changed batch replay target fails under catalog locks');
$events = [];
$expectFailure(
    static function () use ($service, $authority, $approvedBatchStates, $approvedBatchResults): void {
        $service->applyAuthoritativeBatch(
            12740,
            [15320],
            $authority,
            's1',
            $approvedBatchStates,
            $approvedBatchResults,
            true,
            1,
            str_repeat('d', 64)
        );
    },
    'manual catalog mutation after batch start cannot be bypassed by onlyChanged',
    409
);
$assert($events === [], 'stale catalog state is rejected before acquiring mutation locks');
$assert($writeCount === $writesBeforeBatchSkip, 'stale catalog state is never reported as skipped or written');
$current[15320]['prices'][0]['price'] = 530.0;

$changedAuthority = $authority;
$changedAuthority['results'][0]['priceRangesWithMarkup'][0]['prices'][0]['basePrice'] = 531.0;
$events = [];
$expectFailure(
    static function () use ($service, $changedAuthority, $approvedBatchStates, $approvedBatchResults): void {
        $service->applyAuthoritativeBatch(
            12740,
            [15320],
            $changedAuthority,
            's1',
            $approvedBatchStates,
            $approvedBatchResults,
            false,
            1,
            str_repeat('e', 64)
        );
    },
    'fresh calc-server result must match the per-offer result reviewed by the operator',
    409
);
$assert($events === [], 'reviewed result mismatch is rejected before transaction');
$assert($writeCount === $writesBeforeBatchSkip, 'reviewed result mismatch never writes');

$failedWritePreview = $service->preview(12740, [15320], $result, 's1');
$failWrite = true;
$events = [];
$expectFailure(
    static function () use ($service, $result, $failedWritePreview): void {
        $service->apply(12740, [15320], $result, 's1', $failedWritePreview['fingerprint'], 1);
    },
    'a partial writer result aborts the whole transaction'
);
$assert($events === ['calculate', 'adapter-lock', 'begin', 'option-lock', 'lock', 'runtime-lock', 'write', 'rollback'], 'writer failure rolls back the outer transaction');

$failWrite = false;
$skipMutation = true;
$events = [];
$current[15320]['purchasingPrice'] = 399.0;
$unverifiedWritePreview = $service->preview(12740, [15320], $result, 's1');
$expectFailure(
    static function () use ($service, $result, $unverifiedWritePreview): void {
        $service->apply(12740, [15320], $result, 's1', $unverifiedWritePreview['fingerprint'], 1);
    },
    'a successful API response without matching readback is rejected'
);
$assert($events === ['calculate', 'calculate', 'adapter-lock', 'begin', 'option-lock', 'lock', 'runtime-lock', 'write', 'rollback'], 'failed readback rolls back before commit');

$writerSource = file_get_contents(dirname(__DIR__) . '/lib/Services/CatalogCalculationWriteService.php');
$assert(
    is_string($writerSource)
        && substr_count($writerSource, 'ORDER BY MODULE_ID, NAME, SITE_ID') >= 6
        && strpos($writerSource, 'ORDER BY MODULE_ID, NAME FOR UPDATE') === false,
    'all global option snapshots, locks and duplicate checks use one deterministic module/name/site order'
);

echo "Catalog calculation write service tests passed\n";
