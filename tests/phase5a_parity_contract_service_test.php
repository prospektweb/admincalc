<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/Phase5aParityContractService.php';

use Prospektweb\Calc\Services\Phase5aParityContractService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$categories = [
    'ui',
    'passive_context',
    'stage_inputs',
    'globals',
    'options_mappings',
    'routes',
    'basket',
    'seo_display',
];
$consumers = [];
foreach ($categories as $index => $category) {
    $consumers[] = [
        'propertyCode' => $index % 2 === 0 ? 'CALC_PROP_METHOD' : 'CALC_PROP_OPTIONS',
        'category' => $category,
        'source' => 'fixture.' . $category,
        'path' => 'fixture.' . $index,
        'provenance' => $category === 'basket' ? 'declared' : 'discovered',
    ];
}
$consumers[] = $consumers[0];
$consumers[] = [
    'propertyCode' => 'FORGED',
    'category' => 'ui',
    'source' => 'fixture.invalid',
    'path' => 'fixture.invalid',
    'provenance' => 'discovered',
];

$presetLoader = static function (int $presetId): array {
    return [
        'status' => 'ok',
        'preset' => ['id' => $presetId, 'name' => 'Focus preset'],
        'products' => array_map(static function (int $productId): array {
            return ['id' => $productId, 'name' => 'Product ' . $productId, 'offers' => []];
        }, Phase5aParityContractService::GOLDEN_PRODUCT_IDS),
    ];
};
$dependencyLoader = static function (int $presetId, array $allowedProductIds) use ($consumers, $categories): array {
    $categoryStatus = [];
    foreach ($categories as $category) {
        $categoryStatus[$category] = [
            'scanned' => true,
            'sourceMode' => $category === 'basket' ? 'declared' : 'discovered',
        ];
    }
    return [
        'presetId' => $presetId,
        'consumers' => $consumers,
        'requiredPropertyCodes' => ['CALC_PROP_METHOD'],
        'categoryStatus' => $categoryStatus,
        'unresolvedSources' => [],
    ];
};
$completeCapture = [
    'prices' => [['quantity' => 100, 'price' => 530.0, 'currency' => 'RUB']],
    'selectedStageIds' => [12758],
    'routeProductId' => 4267,
    'reopenSelection' => [
        'CALC_PROP_COLOR_SCHEME' => '4+0',
        'CALC_PROP_DENSITY_PAPER' => 'MAX',
        'CALC_PROP_FORMAT' => '90x50',
        'CALC_PROP_METHOD' => 'OFSET',
        'CALC_PROP_TYPE_PAPER' => 'mel-paper',
    ],
    'basketReprice' => ['before' => 530.0, 'after' => 530.0],
    'basketFingerprint' => str_repeat('a', 64),
    'schemaFingerprint' => str_repeat('b', 64),
    'compileHash' => str_repeat('c', 64),
    'formRevision' => 3,
    'bindingRevision' => 4,
    'publishedRevision' => 2,
];
$goldenLoader = static function (int $presetId, array $productIds) use ($completeCapture): array {
    return [
        4267 => $completeCapture,
    ];
};

$service = new Phase5aParityContractService($presetLoader, $dependencyLoader, $goldenLoader);
$first = $service->build();
$second = $service->build();

$assert(
    ($first['contract'] ?? '') === Phase5aParityContractService::CONTRACT,
    'The dependency matrix must have a versioned machine contract'
);
$assert(($first['readOnly'] ?? false) === true, 'The parity capture contract must be explicitly read-only');
$assert(
    ($first['allowedProductIds'] ?? []) === Phase5aParityContractService::GOLDEN_PRODUCT_IDS,
    'The exact five-product golden pilot must be represented by the current preset allowlist'
);
$assert(
    ($first['dependencyMatrix']['valid'] ?? false) === true
        && array_keys($first['dependencyMatrix']['categoryStatus'] ?? []) === $categories,
    'All eight required Phase 5A dependency categories must be explicitly scanned'
);
$assert(
    count($first['dependencyMatrix']['consumers'] ?? []) === count($categories),
    'Dependency consumers must be canonical, deduplicated and forged codes rejected'
);
$assert(
    ($first['dependencyMatrix']['propertyCodes'] ?? []) === ['CALC_PROP_METHOD', 'CALC_PROP_OPTIONS'],
    'Public input property codes must be canonical and sorted'
);
$assert(
    ($first['publicInputContract']['requiredPropertyCodes'] ?? []) === ['CALC_PROP_METHOD', 'CALC_PROP_OPTIONS']
        && preg_match('/^[a-f0-9]{64}$/D', (string)($first['publicInputContract']['fingerprint'] ?? '')) === 1,
    'The public input authority must include external logic and presentation consumers, not its own published UI'
);
$assert(
    ($first['fingerprint'] ?? '') === ($second['fingerprint'] ?? ''),
    'Equivalent parity inputs must produce a deterministic fingerprint'
);
$genericContract = $service->buildPublicInputContract(12800, []);
$assert(
    ($genericContract['presetId'] ?? 0) === 12800
        && preg_match('/^[a-f0-9]{64}$/D', (string)($genericContract['fingerprint'] ?? '')) === 1,
    'A standalone preset without catalog products must still receive a fingerprinted dependency authority'
);
$assert(
    ($first['dependencyMatrix']['categoryCoverage']['ui'] ?? 0) > 0
        && ($first['dependencyMatrix']['categoryCoverage']['basket'] ?? 0) > 0,
    'The dependency matrix must expose machine-countable UI and basket consumers'
);
$irrelevantProperty = array_values(array_filter(
    $first['dependencyMatrix']['byProperty'] ?? [],
    static fn(array $property): bool => ($property['propertyCode'] ?? '') === 'CALC_PROP_METHOD'
))[0] ?? [];
$assert(
    !in_array('routes', $irrelevantProperty['categories'] ?? [], true)
        && !in_array('passive_context', $irrelevantProperty['categories'] ?? [], true)
        && !in_array('seo_display', $irrelevantProperty['categories'] ?? [], true),
    'An unrelated runtime property must not inherit route, passive or SEO consumers'
);

$internalOnlyConsumers = $consumers;
$internalOnlyConsumers[] = [
    'propertyCode' => 'CALC_PROP_INTERNAL',
    'category' => 'stage_inputs',
    'source' => 'fixture.stage',
    'path' => 'fixture.stage.internal',
    'provenance' => 'discovered',
];
$internalOnlyLoader = static function (int $presetId, array $allowedProductIds) use (
    $dependencyLoader,
    $internalOnlyConsumers
): array {
    $graph = $dependencyLoader($presetId, $allowedProductIds);
    $graph['consumers'] = $internalOnlyConsumers;
    $graph['requiredPropertyCodes'][] = 'CALC_PROP_INTERNAL';
    return $graph;
};
$internalOnly = (new Phase5aParityContractService(
    $presetLoader,
    $internalOnlyLoader,
    $goldenLoader
))->build();
$assert(
    in_array('CALC_PROP_INTERNAL', $internalOnly['dependencyMatrix']['propertyCodes'] ?? [], true)
        && in_array(
            'CALC_PROP_INTERNAL',
            $internalOnly['publicInputContract']['requiredPropertyCodes'] ?? [],
            true
        ),
    'A stage input must block deleting its form field until the logic reference is removed'
);

$requiredUiConsumers = $consumers;
$requiredUiConsumers[] = [
    'propertyCode' => 'CALC_PROP_INTERNAL',
    'category' => 'ui',
    'source' => 'fixture.runtime',
    'path' => 'products.4267.schema.fields.9.property_code',
    'provenance' => 'discovered',
];
$requiredUiLoader = static function (int $presetId, array $allowedProductIds) use (
    $dependencyLoader,
    $requiredUiConsumers
): array {
    $graph = $dependencyLoader($presetId, $allowedProductIds);
    $graph['consumers'] = $requiredUiConsumers;
    $graph['requiredPropertyCodes'][] = 'CALC_PROP_INTERNAL';
    return $graph;
};
$requiredUi = (new Phase5aParityContractService(
    $presetLoader,
    $requiredUiLoader,
    $goldenLoader
))->build();
$assert(
    !in_array(
        'CALC_PROP_INTERNAL',
        $requiredUi['publicInputContract']['requiredPropertyCodes'] ?? [],
        true
    ),
    'The currently published UI must not make its own field impossible to delete'
);
$assert(
    ($first['runtimeBoundary']['runtimeSchemaVersion'] ?? 0) === 2
        && array_key_exists('calcServerChangeRequired', $first['runtimeBoundary'] ?? [])
        && $first['runtimeBoundary']['calcServerChangeRequired'] === null
        && ($first['runtimeBoundary']['calcServerDecision'] ?? '') === 'undetermined_until_parity',
    'Calc-server compatibility must remain undetermined while the production golden gate is incomplete'
);

$incompleteDependencyLoader = static function (int $presetId, array $allowedProductIds) use (
    $dependencyLoader
): array {
    $graph = $dependencyLoader($presetId, $allowedProductIds);
    $graph['categoryStatus']['routes']['scanned'] = false;
    $graph['unresolvedSources'][] = 'effective_runtime:route_scan_failed';
    return $graph;
};
$incompleteService = new Phase5aParityContractService(
    $presetLoader,
    $incompleteDependencyLoader,
    $goldenLoader
);
$incomplete = $incompleteService->build();
$assert(
    ($incomplete['dependencyMatrix']['valid'] ?? true) === false
        && ($incomplete['dependencyMatrix']['categoryStatus']['routes']['scanned'] ?? true) === false
        && array_key_exists('calcServerChangeRequired', $incomplete['runtimeBoundary'] ?? [])
        && $incomplete['runtimeBoundary']['calcServerChangeRequired'] === null,
    'An unresolved exact dependency source must fail both the matrix and runtime release boundary closed'
);
$incompleteContractRejected = false;
try {
    $incompleteService->buildPublicInputContract(12740, Phase5aParityContractService::GOLDEN_PRODUCT_IDS);
} catch (RuntimeException $exception) {
    $incompleteContractRejected = true;
}
$assert($incompleteContractRejected, 'An incomplete dependency matrix must never become a compiler authority');

$runtimeClassifier = new ReflectionMethod(
    Phase5aParityContractService::class,
    'classifyEffectiveRuntimeResult'
);
$legacyRuntime = $runtimeClassifier->invoke(null, [
    'source' => 'product',
    'schema' => [
        'version' => 1,
        'fields' => [['property_code' => 'CALC_PROP_METHOD', 'required' => true]],
    ],
]);
$emptyRuntime = $runtimeClassifier->invoke(null, ['source' => 'none', 'schema' => []]);
$malformedRuntime = $runtimeClassifier->invoke(null, ['source' => 'product', 'schema' => []]);
$defaultRuntime = $runtimeClassifier->invoke(null, [
    'source' => 'default',
    'schema' => [
        'version' => 2,
        'fields' => [['property_code' => 'CALC_PROP_VOLUME', 'required' => true]],
    ],
]);
$legacyDefaultRuntime = $runtimeClassifier->invoke(null, [
    'source' => 'default',
    'schema' => [
        'version' => 1,
        'fields' => [['property_code' => 'CALC_PROP_VOLUME']],
    ],
]);
$malformedDefaultRuntime = $runtimeClassifier->invoke(null, [
    'source' => 'default',
    'schema' => ['version' => 2],
]);
$emptyDefaultRuntime = $runtimeClassifier->invoke(null, [
    'source' => 'default',
    'schema' => ['version' => 2, 'fields' => []],
]);
$scalarFieldDefaultRuntime = $runtimeClassifier->invoke(null, [
    'source' => 'default',
    'schema' => ['version' => 2, 'fields' => [123]],
]);
$assert(
    ($legacyRuntime['state'] ?? '') === 'supported'
        && ($emptyRuntime['state'] ?? '') === 'empty'
        && ($malformedRuntime['state'] ?? '') === 'invalid',
    'The exact runtime scanner must include legacy v1, accept proven source=none as empty, and reject malformed sources'
);
$assert(
    ($defaultRuntime['state'] ?? '') === 'invalid'
        && ($legacyDefaultRuntime['state'] ?? '') === 'invalid'
        && ($malformedDefaultRuntime['state'] ?? '') === 'invalid'
        && ($emptyDefaultRuntime['state'] ?? '') === 'invalid'
        && ($scalarFieldDefaultRuntime['state'] ?? '') === 'invalid',
    'Configured editor defaults never become effective public runtime sources'
);
$runtimeScanner = new ReflectionMethod(Phase5aParityContractService::class, 'scanRuntimeSchema');
$legacyConsumers = [];
$legacyRequiredCodes = [];
$runtimeScanner->invokeArgs(null, [
    7716,
    'product',
    [
        'version' => 1,
        'fields' => [
            ['property_code' => 'CALC_PROP_VOLUME'],
            ['property_code' => 'CALC_PROP_OPTIONAL', 'required' => false],
        ],
    ],
    &$legacyConsumers,
    &$legacyRequiredCodes,
]);
$assert(
    $legacyRequiredCodes === ['CALC_PROP_VOLUME'],
    'RuntimeSchema v1 fields without an explicit required flag must retain their required-by-default semantics'
);

$products = $first['goldenParity']['products'] ?? [];
$assert(
    array_column($products, 'productId') === Phase5aParityContractService::GOLDEN_PRODUCT_IDS,
    'Golden capture ordering must remain exact for all five pilot products'
);
$assert(
    ($products[0]['status'] ?? '') === 'matched'
        && ($products[0]['missingAssertions'] ?? null) === [],
    'A complete live capture must be immediately regression-comparable'
);
$assert(
    ($products[1]['status'] ?? '') === 'capture_required'
        && in_array('basketFingerprint', $products[1]['missingAssertions'] ?? [], true),
    'Missing live observations must remain explicit instead of inventing golden values'
);

$productionFixturePath = dirname(__DIR__) . '/resources/phase5a_golden_capture_v1.json';
$productionFixture = json_decode((string)file_get_contents($productionFixturePath), true);
$assert(is_array($productionFixture), 'The checked-in production golden fixture must be valid JSON');
$assert(
    array_column($productionFixture['products'] ?? [], 'productId') === Phase5aParityContractService::GOLDEN_PRODUCT_IDS,
    'The checked-in fixture must gate the exact five pilot products'
);
foreach ((array)($productionFixture['products'] ?? []) as $product) {
    $assert(
        array_diff([
            'productId',
            'runtimeState',
            'runtimeSource',
            'prices',
            'selectedStageIds',
            'routeProductId',
            'reopenSelection',
            'basketReprice',
            'basketFingerprint',
            'schemaFingerprint',
            'compileHash',
            'formRevision',
            'bindingRevision',
            'publishedRevision',
        ], array_keys($product)) === [],
        'Every pilot fixture must reserve concrete price, stage, route, reopen, basket and revision observations'
    );
}
$assert(
    ($productionFixture['products'][3]['prices'][1]['price'] ?? 0.0) === 530.0
        && ($productionFixture['products'][0]['reopenSelection']['CALC_PROP_METHOD'] ?? '') === 'OFSET',
    'The baseline must preserve currently proven production price and reopen evidence'
);
$assert(
    ($productionFixture['provenance']['kind'] ?? '') === 'production'
        && ($productionFixture['provenance']['productionCaptureRequiredBeforeRelease'] ?? true) === false,
    'Authorized live capture must promote the versioned baseline to production evidence'
);
$assert(
    ($productionFixture['products'][0]['prices'][0]['price'] ?? 0.0) === 12520.0
        && ($productionFixture['products'][0]['selectedStageIds'] ?? []) !== []
        && ($productionFixture['products'][0]['basketFingerprint'] ?? '') !== ''
        && ($productionFixture['products'][2]['runtimeState'] ?? '') === 'unavailable'
        && ($productionFixture['products'][2]['runtimeSource'] ?? '') === 'none'
        && ($productionFixture['products'][2]['prices'] ?? null) === []
        && array_key_exists('schemaFingerprint', $productionFixture['products'][2])
        && $productionFixture['products'][2]['schemaFingerprint'] === null,
    'The production fixture must preserve positive live evidence and the exact product-5058 negative runtime'
);
$productionCaptures = [];
foreach ((array)($productionFixture['products'] ?? []) as $product) {
    if (!is_array($product) || !is_int($product['productId'] ?? null)) {
        continue;
    }
    $productId = (int)$product['productId'];
    unset($product['productId']);
    $productionCaptures[$productId] = $product;
}
$productionBuild = (new Phase5aParityContractService(
    $presetLoader,
    $dependencyLoader,
    static fn(int $presetId, array $productIds): array => [
        'mode' => 'versioned_fixture',
        'baselineKind' => 'production',
        'captures' => $productionCaptures,
    ]
))->build();
$assert(
    ($productionBuild['goldenParity']['valid'] ?? false) === true
        && array_column($productionBuild['goldenParity']['products'] ?? [], 'status')
            === ['matched', 'matched', 'matched', 'matched', 'matched']
        && ($productionBuild['runtimeBoundary']['calcServerChangeRequired'] ?? true) === false,
    'The exact versioned production fixture must pass all five golden products and close the runtime boundary'
);

$fixturePath = __DIR__ . '/fixtures/phase5a_golden_harness_v1.json';
$fixture = json_decode((string)file_get_contents($fixturePath), true);
$assert(is_array($fixture), 'The checked-in regression harness must be valid JSON');
$fixtureComparison = $service->compare($fixture, $fixture);
$assert(
    ($fixtureComparison['valid'] ?? false) === true
        && array_column($fixtureComparison['products'] ?? [], 'valid') === [true, true, true, true, true],
    'The concrete five-product regression harness must prove the comparator green path'
);
$assert(
    ($fixture['provenance']['productionCaptureRequiredBeforeRelease'] ?? false) === true,
    'Synthetic harness values must remain explicitly distinct from production capture evidence'
);

$observedProduct = static function (int $productId): array {
    $isPilot = $productId === 4267;
    if ($productId === 5058) {
        return [
            'productId' => 5058,
            'runtimeState' => 'unavailable',
            'runtimeSource' => 'none',
            'prices' => [],
            'selectedStageIds' => [],
            'routeProductId' => null,
            'reopenSelection' => null,
            'basketReprice' => null,
            'basketFingerprint' => null,
            'schemaFingerprint' => null,
            'compileHash' => null,
            'formRevision' => null,
            'bindingRevision' => null,
            'publishedRevision' => null,
        ];
    }
    return [
        'productId' => $productId,
        'runtimeState' => 'available',
        'runtimeSource' => 'family',
        'prices' => [['quantity' => 100, 'price' => (float)$productId, 'currency' => 'RUB']],
        'selectedStageIds' => [12758, 12744],
        'routeProductId' => $productId,
        'reopenSelection' => ['CALC_PROP_METHOD' => 'DIGITAL'],
        'basketReprice' => ['before' => (float)$productId, 'after' => (float)$productId],
        'basketFingerprint' => hash('sha256', 'basket-' . $productId),
        'schemaFingerprint' => hash('sha256', 'schema-' . $productId),
        'compileHash' => $isPilot ? hash('sha256', 'compile-' . $productId) : null,
        'formRevision' => $isPilot ? 3 : null,
        'bindingRevision' => $isPilot ? 4 : null,
        'publishedRevision' => $isPilot ? 2 : null,
    ];
};
$baselineObservation = [
    'contract' => Phase5aParityContractService::OBSERVATION_CONTRACT,
    'presetId' => 12740,
    'products' => array_map($observedProduct, Phase5aParityContractService::GOLDEN_PRODUCT_IDS),
];
$candidateObservation = $baselineObservation;
$candidateObservation['products'][0]['selectedStageIds'] = [12744, 12758];
$comparison = $service->compare($baselineObservation, $candidateObservation);
$assert(
    ($comparison['contract'] ?? '') === Phase5aParityContractService::COMPARISON_CONTRACT
        && ($comparison['valid'] ?? false) === true,
    'A complete equivalent five-product observation must pass the read-only parity gate'
);
$candidatePublished = $baselineObservation;
$candidatePublished['products'][0]['publishedRevision'] = 3;
$candidatePublished['products'][0]['schemaFingerprint'] = hash('sha256', 'schema-4267-published');
$candidatePublished['products'][0]['compileHash'] = hash('sha256', 'compile-4267-published');
$candidatePublished['products'][0]['runtimeSource'] = 'form_first';
$publishedComparison = $service->compare($baselineObservation, $candidatePublished);
$assert(
    ($publishedComparison['valid'] ?? false) === true
        && ($publishedComparison['authoringTransition']['publicationAdvanced'] ?? false) === true
        && ($publishedComparison['authoringTransition']['schemaFingerprintChanged'] ?? false) === true,
    'Pilot publication may change authoring revisions and schema fingerprint without failing price parity'
);
$candidateUnpublishedSchema = $baselineObservation;
$candidateUnpublishedSchema['products'][0]['schemaFingerprint'] = hash('sha256', 'schema-4267-unpublished');
$unpublishedSchemaComparison = $service->compare($baselineObservation, $candidateUnpublishedSchema);
$assert(
    ($unpublishedSchemaComparison['valid'] ?? true) === false
        && in_array(
            'public_schema_changed_without_publication',
            $unpublishedSchemaComparison['authoringTransition']['issues'] ?? [],
            true
        ),
    'Pilot public schema must not change without an advancing publication revision'
);
$candidateObservation['products'][3]['basketReprice']['after'] = 999.0;
$mismatch = $service->compare($baselineObservation, $candidateObservation);
$assert(
    ($mismatch['valid'] ?? true) === false
        && in_array(
            'products.12727.basketReprice.after',
            $mismatch['products'][3]['mismatches'] ?? [],
            true
        ),
    'A changed basket reprice must fail with a machine-checkable product path'
);
$invalidObservation = $baselineObservation;
array_pop($invalidObservation['products']);
$invalidRejected = false;
try {
    $service->compare($invalidObservation, $baselineObservation);
} catch (InvalidArgumentException $exception) {
    $invalidRejected = true;
}
$assert($invalidRejected, 'The comparator must reject an observation missing any pilot product');
$invalidPilotTypes = $baselineObservation;
$invalidPilotTypes['products'][0]['formRevision'] = hash('sha256', 'invented-form-revision');
$invalidPilotTypesRejected = false;
try {
    $service->compare($invalidPilotTypes, $baselineObservation);
} catch (InvalidArgumentException $exception) {
    $invalidPilotTypesRejected = true;
}
$assert(
    $invalidPilotTypesRejected,
    'Pilot authoring revisions must be runtime-compatible non-negative integers rather than invented hashes'
);
$invalidNonPilotTypes = $baselineObservation;
$invalidNonPilotTypes['products'][1]['formRevision'] = 0;
$invalidNonPilotTypesRejected = false;
try {
    $service->compare($invalidNonPilotTypes, $baselineObservation);
} catch (InvalidArgumentException $exception) {
    $invalidNonPilotTypesRejected = true;
}
$assert(
    $invalidNonPilotTypesRejected,
    'Non-pilot products must use explicit null for form-first authoring metadata'
);

$missingUnavailableFact = $baselineObservation;
unset($missingUnavailableFact['products'][2]['schemaFingerprint']);
$missingUnavailableFactRejected = false;
try {
    $service->compare($missingUnavailableFact, $baselineObservation);
} catch (InvalidArgumentException $exception) {
    $missingUnavailableFactRejected = true;
}
$assert(
    $missingUnavailableFactRejected,
    'The unavailable runtime baseline must contain every explicit empty/null assertion'
);

$unavailablePositiveProduct = $baselineObservation;
$unavailablePositiveProduct['products'][1] = $baselineObservation['products'][2];
$unavailablePositiveProduct['products'][1]['productId'] = 4403;
$unavailablePositiveRejected = false;
try {
    $service->compare($unavailablePositiveProduct, $baselineObservation);
} catch (InvalidArgumentException $exception) {
    $unavailablePositiveRejected = true;
}
$assert(
    $unavailablePositiveRejected,
    'Only the proven catalog-only product 5058 may use the unavailable runtime state'
);

$fabricatedPositiveBaseline = $baselineObservation;
$fabricatedPositiveBaseline['products'][2] = $observedProduct(12727);
$fabricatedPositiveBaseline['products'][2]['productId'] = 5058;
$fabricatedPositiveBaselineRejected = false;
try {
    $service->compare($fabricatedPositiveBaseline, $fabricatedPositiveBaseline);
} catch (InvalidArgumentException $exception) {
    $fabricatedPositiveBaselineRejected = true;
}
$assert(
    $fabricatedPositiveBaselineRejected,
    'The comparator must reject a fabricated positive baseline for product 5058'
);

$availableCandidate = $baselineObservation;
$availableCandidate['products'][2] = $observedProduct(12727);
$availableCandidate['products'][2]['productId'] = 5058;
$availableCandidateComparison = $service->compare($baselineObservation, $availableCandidate);
$assert(
    ($availableCandidateComparison['valid'] ?? true) === false
        && in_array(
            'products.5058.runtimeState',
            $availableCandidateComparison['products'][2]['mismatches'] ?? [],
            true
        ),
    'A newly available product-5058 runtime must be reported as a parity change, not waived'
);

$emptyPositivePrices = $baselineObservation;
$emptyPositivePrices['products'][1]['prices'] = [];
$emptyPositivePricesRejected = false;
try {
    $service->compare($emptyPositivePrices, $baselineObservation);
} catch (InvalidArgumentException $exception) {
    $emptyPositivePricesRejected = true;
}
$assert(
    $emptyPositivePricesRejected,
    'Positive FrontCalc products must retain strict non-empty price assertions'
);

echo "Phase 5A parity contract service tests passed\n";
