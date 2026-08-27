<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/PresetProductAssignmentLockService.php';
require_once dirname(__DIR__) . '/lib/Services/PresetMutationCoordinatorService.php';
require_once dirname(__DIR__) . '/lib/Services/ControlCenterEditorsService.php';

use Prospektweb\Calc\Services\ControlCenterEditorsService;

$coordinatedMutation = static function (
    int $presetId,
    array $metadata,
    callable $mutation,
    callable $authoritativeReadback
) {
    $authoritativeReadback();
    $result = $mutation();
    $authoritativeReadback();
    return $result;
};

class TestFormFirstAuthoringProvider
{
    /** @var array<int, array<int, mixed>> */
    public $calls = [];

    public function loadFormFirstWorkspace(
        int $presetId,
        array $dependencyContract = []
    ): array {
        $this->calls[] = ['loadFormFirstWorkspace', $presetId, $dependencyContract];
        return $this->formFirstResult('load', $dependencyContract, $presetId);
    }

    public function newVersionFormTemplate(
        int $presetId,
        array $dependencyContract = []
    ): array {
        $this->calls[] = ['newVersionFormTemplate', $presetId, $dependencyContract];
        return $this->formFirstResult('new_version_template', $dependencyContract, $presetId);
    }

    public function materializeSystemFields(
        array $formDefinition,
        array $bindingDefinition
    ): array {
        $this->calls[] = ['materializeSystemFields', $formDefinition, $bindingDefinition];
        return [
            'changed' => true,
            'formDefinition' => $formDefinition,
            'bindingDefinition' => $bindingDefinition,
        ];
    }

    public function saveFormFirstDraft(
        int $presetId,
        string $expectedAggregateRevision,
        array $formDefinition,
        array $bindingDefinition,
        array $dependencyContract = []
    ): array {
        $this->calls[] = [
            'saveFormFirstDraft',
            $presetId,
            $expectedAggregateRevision,
            $formDefinition,
            $bindingDefinition,
            $dependencyContract,
        ];
        return $this->formFirstResult('save_draft', $dependencyContract, $presetId);
    }

    public function previewFormFirst(
        int $presetId,
        array $formDefinition,
        array $bindingDefinition,
        array $dependencyContract = []
    ): array {
        $this->calls[] = [
            'previewFormFirst',
            $presetId,
            $formDefinition,
            $bindingDefinition,
            $dependencyContract,
        ];
        return $this->formFirstResult('preview', $dependencyContract, $presetId);
    }

    public function previewVersionFormFirst(
        int $presetId,
        array $formDefinition,
        array $bindingDefinition,
        array $dependencyContract = []
    ): array {
        $this->calls[] = [
            'previewVersionFormFirst',
            $presetId,
            $formDefinition,
            $bindingDefinition,
            $dependencyContract,
        ];
        return $this->formFirstResult('preview', $dependencyContract, $presetId);
    }

    public function publishFormFirst(
        int $presetId,
        string $expectedAggregateRevision,
        string $expectedCompileHash,
        array $dependencyContract = []
    ): array {
        $this->calls[] = [
            'publishFormFirst',
            $presetId,
            $expectedAggregateRevision,
            $expectedCompileHash,
            $dependencyContract,
        ];
        return $this->formFirstResult('publish', $dependencyContract, $presetId);
    }

    public function rollbackFormFirst(
        int $presetId,
        string $expectedAggregateRevision,
        int $targetPublishedRevision,
        array $dependencyContract = []
    ): array {
        $this->calls[] = [
            'rollbackFormFirst',
            $presetId,
            $expectedAggregateRevision,
            $targetPublishedRevision,
            $dependencyContract,
        ];
        return $this->formFirstResult('rollback', $dependencyContract, $presetId);
    }

    private function formFirstResult(
        string $operation,
        array $dependencyContract,
        int $presetId
    ): array
    {
        return [
            'contract' => ControlCenterEditorsService::FORM_FIRST_AUTHORING_CONTRACT,
            'operation' => $operation,
            'preset' => ['id' => $presetId, 'name' => 'Preset #' . $presetId],
            'presetId' => $presetId,
            'dependencyFingerprint' => (string)($dependencyContract['fingerprint'] ?? ''),
            'aggregateRevision' => str_repeat('a', 64),
            'formDefinition' => ['contract' => 'prospektweb.frontcalc.form-definition/v1'],
            'bindingDefinition' => ['contract' => 'prospektweb.frontcalc.binding-definition/v1'],
            'history' => [[
                'revision' => 0,
                'formRevision' => 0,
                'bindingRevision' => 0,
                'compileHash' => str_repeat('b', 64),
            ]],
            'compile' => [
                'valid' => true,
                'hash' => str_repeat('c', 64),
            ],
        ];
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expectInvalid = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return;
    }
    $assert(false, $message);
};
$expectRuntime = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (RuntimeException $exception) {
        return;
    }
    $assert(false, $message);
};

$presetLoader = static function (int $presetId): array {
    return [
        'status' => 'ok',
        'preset' => ['id' => $presetId, 'name' => 'Focus preset'],
        'products' => [
            [
                'id' => 4267,
                'name' => 'Test product',
                'offers' => [
                    ['id' => 13142, 'name' => 'Pilot offer'],
                ],
            ],
            [
                'id' => 10,
                'name' => 'Product A',
                'offers' => [
                    ['id' => 100, 'name' => 'Offer A1'],
                    ['id' => 101, 'name' => 'Offer A2'],
                ],
            ],
            [
                'id' => 11,
                'name' => 'Product B',
                'offers' => [
                    ['id' => 102, 'name' => 'Offer B1'],
                ],
            ],
            [
                'id' => 12727,
                'name' => 'Prepared product',
                'offers' => [
                    ['id' => 15320, 'name' => 'Prepared offer 4+0'],
                    ['id' => 15321, 'name' => 'Prepared offer 4+4'],
                ],
            ],
            [
                'id' => 12764,
                'name' => 'Second prepared product',
                'offers' => [
                    ['id' => 15326, 'name' => 'Second prepared offer'],
                ],
            ],
        ],
    ];
};

$exactPropertyAuthority = static fn(int $productIblockId, bool $forUpdate, int $presetIblockId = 0): array => [
    'productIblockId' => $productIblockId,
    'presetIblockId' => $presetIblockId > 0 ? $presetIblockId : 41,
    'propertyId' => 91,
];
$service = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    presetProductPropertyAuthority: $exactPropertyAuthority
);
$catalog = $service->getCatalog();

$assert($catalog['contract'] === ControlCenterEditorsService::CONTRACT, 'Catalog contract must be versioned');
$assert(!array_key_exists('focusPresetId', $catalog), 'Catalog has no hard-coded focus preset');
$assert(is_array($catalog['calculations']), 'Bootstrap catalog exposes lightweight registry rows');
$assert(!isset($catalog['calculations'][0]['products']), 'Bootstrap registry rows never embed product or offer payloads');
$presetWorkspace = $service->loadPresetWorkspace(41);
$assert(($presetWorkspace['offerCount'] ?? 0) === 7, 'Preset detail must lazy-load its authoritative product and offer scope');
$assert(($presetWorkspace['products'][3]['offers'][1]['id'] ?? 0) === 15321, 'Preset detail must expose server-authored offer choices');
$broaderUsageService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    null,
    null,
    null,
    null,
    null,
    static function (int $presetId) use ($presetLoader): array {
        $snapshot = $presetLoader($presetId);
        $snapshot['preset']['name'] = 'Full usage preset';
        $snapshot['products'][] = [
            'id' => 13000,
            'name' => 'Usage-only product',
            'offers' => [['id' => 16000, 'name' => 'Usage-only offer']],
        ];
        return $snapshot;
    }
);
$broaderUsage = $broaderUsageService->loadPresetWorkspace(41);
$assert(
    ($broaderUsage['presetName'] ?? '') === 'Full usage preset'
        && ($broaderUsage['productCount'] ?? 0) === 6
        && ($broaderUsage['offerCount'] ?? 0) === 8,
    'Preset usage detail must use the full preset membership rather than optional writeback configuration'
);
$assignedProductIds = [11, 12];
$productCatalogProvider = static function (
    int $presetId,
    string $query,
    int $page,
    int $pageSize
) use (&$assignedProductIds): array {
    $allRows = [
        ['id' => 11, 'name' => 'Business cards', 'presetIds' => in_array(11, $assignedProductIds, true) ? [$presetId] : []],
        ['id' => 12, 'name' => 'Leaflets', 'presetIds' => in_array(12, $assignedProductIds, true) ? [$presetId] : []],
        ['id' => 12727, 'name' => 'Standard cards', 'presetIds' => in_array(12727, $assignedProductIds, true) ? [$presetId] : []],
    ];
    $rows = array_values(array_filter($allRows, static function (array $row) use ($query): bool {
        return $query === '' || stripos($row['name'] . ' ' . $row['id'], $query) !== false;
    }));
    sort($assignedProductIds, SORT_NUMERIC);
    return [
        'presetName' => 'Focus preset',
        'productIblockId' => 7,
        'linkedProductIds' => $assignedProductIds,
        'rows' => $rows,
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => count($rows),
    ];
};
$productMutationHandler = static function (
    int $presetId,
    array $productIds,
    string $expectedRevision,
    int $lockedProductIblockId
) use (&$assignedProductIds, $productCatalogProvider): array {
    if ($lockedProductIblockId !== 7) {
        throw new RuntimeException('Product mutation did not receive the locked iblock authority.');
    }
    sort($assignedProductIds, SORT_NUMERIC);
    $currentRevision = hash('sha256', json_encode([
        'presetId' => $presetId,
        'linkedProductIds' => array_values($assignedProductIds),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (!hash_equals($currentRevision, $expectedRevision)) {
        throw new RuntimeException('Preset product assignment changed', 409);
    }
    $assignedProductIds = $productIds;
    sort($assignedProductIds, SORT_NUMERIC);
    return $productCatalogProvider($presetId, '', 1, 50);
};
$productManagerService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    null,
    null,
    null,
    null,
    null,
    null,
    $productCatalogProvider,
    $productMutationHandler,
    storefrontProductReadbackLoader: static fn(int $presetId): array => [
        'preset_id' => $presetId,
        'items' => [],
    ],
    presetProductAssignmentLocker: static fn(int $productIblockId, callable $criticalSection) => $criticalSection($productIblockId),
    presetMutationCoordinator: $coordinatedMutation,
    presetProductPropertyAuthority: $exactPropertyAuthority
);
$productCatalog = $productManagerService->getPresetProductCatalog(41, '12727', 1, 50);
$assert(($productCatalog['linkedProductIds'] ?? []) === [11, 12], 'Product manager must return the complete authoritative linked set beside filtered rows');
$assert(($productCatalog['rows'][0]['id'] ?? 0) === 12727 && empty($productCatalog['rows'][0]['linked']), 'Product search must expose an unlinked candidate');
$assert(preg_match('/^[a-f0-9]{64}$/', (string)($productCatalog['revision'] ?? '')) === 1, 'Product manager must expose an optimistic revision');
$managerImpact = $productManagerService->previewPresetProductImpact(
    41,
    [11, 12727],
    $productCatalog['revision']
);
$previewMutationCalls = 0;
$productImpactService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    presetProductCatalogLoader: $productCatalogProvider,
    presetProductMutationHandler: static function () use (&$previewMutationCalls): array {
        $previewMutationCalls++;
        throw new RuntimeException('Read-only impact preview attempted a mutation.');
    },
    storefrontProductDetacher: static fn(int $presetId, array $productIds): array => [],
    storefrontProductReadbackLoader: static fn(int $presetId): array => [
        'preset_id' => $presetId,
        'items' => [[
            'id' => 'storefront-a',
            'name' => 'Product presentation',
            'active' => true,
            'revision' => 3,
            'product_ids' => [11, 12],
        ]],
    ]
);
$impact = $productImpactService->previewPresetProductImpact(
    41,
    [11, 12727],
    $productCatalog['revision']
);
$assert(
    $impact['contract'] === ControlCenterEditorsService::PRESET_PRODUCT_IMPACT_CONTRACT
        && $impact['addedProductIds'] === [12727]
        && $impact['removedProductIds'] === [12]
        && ($impact['affectedStorefronts'][0]['id'] ?? '') === 'storefront-a'
        && ($impact['affectedStorefronts'][0]['removedProductIds'] ?? []) === [12]
        && $assignedProductIds === [11, 12]
        && $previewMutationCalls === 0,
    'Product impact preview is read-only and lists exact storefront detachments before confirmation'
);
$savedProductCatalog = $productManagerService->setPresetProducts(
    41,
    [11, 12727],
    $productCatalog['revision'],
    $managerImpact['impactFingerprint']
);
$assert(($savedProductCatalog['linkedProductIds'] ?? []) === [11, 12727], 'Product manager must return authoritative assignment readback');
$expectInvalid(static function () use ($productManagerService): void {
    $productManagerService->setPresetProducts(41, [11], 'not-a-revision', str_repeat('a', 64));
}, 'Product assignment must reject an invalid revision before mutation');
$conflictRaised = false;
try {
    $productManagerService->setPresetProducts(
        41,
        [11],
        $productCatalog['revision'],
        $managerImpact['impactFingerprint']
    );
} catch (RuntimeException $exception) {
    $conflictRaised = $exception->getCode() === 409;
}
$assert($conflictRaised, 'Product assignment must reject a stale revision');

$raceStorefrontRevision = 1;
$raceAssignedProductIds = [11, 12];
$raceMutationCalls = 0;
$raceSuccessfulCoordinations = 0;
$raceCatalogProvider = static function (
    int $presetId,
    string $query,
    int $page,
    int $pageSize
) use (&$raceAssignedProductIds): array {
    return [
        'presetName' => 'Race preset',
        'productIblockId' => 7,
        'linkedProductIds' => $raceAssignedProductIds,
        'rows' => [],
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => 0,
    ];
};
$raceReadback = static function (int $presetId) use (&$raceStorefrontRevision): array {
    return [
        'preset_id' => $presetId,
        'items' => [[
            'id' => 'race-storefront',
            'name' => 'Race storefront',
            'active' => true,
            'revision' => $raceStorefrontRevision,
            'product_ids' => [11, 12],
        ]],
    ];
};
$raceCoordinator = static function (
    int $presetId,
    array $metadata,
    callable $mutation,
    callable $authoritativeReadback
) use (&$raceSuccessfulCoordinations) {
    $authoritativeReadback();
    $result = $mutation();
    $authoritativeReadback();
    $raceSuccessfulCoordinations++;
    return $result;
};
$raceService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    presetProductCatalogLoader: $raceCatalogProvider,
    presetProductMutationHandler: static function () use (&$raceMutationCalls): array {
        $raceMutationCalls++;
        throw new RuntimeException('Stale impact reached the product writer.');
    },
    storefrontProductReadbackLoader: $raceReadback,
    presetProductAssignmentLocker: static fn(int $iblockId, callable $criticalSection) => $criticalSection($iblockId),
    presetMutationCoordinator: $raceCoordinator,
    presetProductPropertyAuthority: $exactPropertyAuthority
);
$raceCatalog = $raceService->getPresetProductCatalog(41);
$raceImpact = $raceService->previewPresetProductImpact(41, [11], $raceCatalog['revision']);
$raceStorefrontRevision = 2;
$raceConflict = false;
try {
    $raceService->setPresetProducts(
        41,
        [11],
        $raceCatalog['revision'],
        $raceImpact['impactFingerprint']
    );
} catch (RuntimeException $exception) {
    $raceConflict = $exception->getCode() === 409;
}
$assert(
    $raceConflict
        && $raceMutationCalls === 0
        && $raceSuccessfulCoordinations === 0
        && $raceAssignedProductIds === [11, 12],
    'A storefront changed after preview must fail before assignment mutation, revision advancement or success audit'
);

$inactiveAssignedProductIds = [7001];
$inactiveProductIsPublished = false;
$inactiveAssignmentCatalog = static function (
    int $presetId,
    string $query,
    int $page,
    int $pageSize
) use (&$inactiveAssignedProductIds, &$inactiveProductIsPublished): array {
    sort($inactiveAssignedProductIds, SORT_NUMERIC);
    return [
        'presetName' => 'Focus preset',
        'productIblockId' => 7,
        'linkedProductIds' => $inactiveAssignedProductIds,
        'revision' => hash('sha256', json_encode([
            'presetId' => $presetId,
            'linkedProductIds' => $inactiveAssignedProductIds,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'rows' => [[
            'id' => 7001,
            'name' => 'Temporarily unavailable product',
            'active' => $inactiveProductIsPublished,
            'presetIds' => $inactiveAssignedProductIds === [] ? [] : [$presetId],
        ]],
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => 1,
    ];
};
$inactiveAssignmentService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    presetProductCatalogLoader: $inactiveAssignmentCatalog,
    presetProductMutationHandler: static function (
        int $presetId,
        array $productIds,
        string $expectedRevision,
        int $productIblockId
    ) use (&$inactiveAssignedProductIds, $inactiveAssignmentCatalog): array {
        $currentRevision = hash('sha256', json_encode([
            'presetId' => $presetId,
            'linkedProductIds' => $inactiveAssignedProductIds,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!hash_equals($currentRevision, $expectedRevision)) {
            throw new RuntimeException('inactive assignment CAS mismatch', 409);
        }
        $inactiveAssignedProductIds = $productIds;
        return $inactiveAssignmentCatalog($presetId, '', 1, 50);
    },
    storefrontProductReadbackLoader: static fn(int $presetId): array => [
        'preset_id' => $presetId,
        'items' => [],
    ],
    presetProductAssignmentLocker: static fn(int $iblockId, callable $criticalSection) => $criticalSection($iblockId),
    presetMutationCoordinator: $coordinatedMutation,
    presetProductPropertyAuthority: $exactPropertyAuthority
);
$inactiveAssignmentBefore = $inactiveAssignmentService->getPresetProductCatalog(41);
$inactiveImpact = $inactiveAssignmentService->previewPresetProductImpact(
    41,
    [],
    (string)$inactiveAssignmentBefore['revision']
);
$assert(
    ($inactiveAssignmentBefore['linkedProductIds'] ?? []) === [7001]
        && ($inactiveAssignmentBefore['rows'][0]['active'] ?? true) === false,
    'inactive linked product remains part of assignment CAS while launch availability is metadata only'
);
$inactiveAssignmentAfter = $inactiveAssignmentService->setPresetProducts(
    41,
    [],
    (string)$inactiveAssignmentBefore['revision'],
    (string)$inactiveImpact['impactFingerprint']
);
$inactiveProductIsPublished = true;
$inactiveAssignmentAfterReactivation = $inactiveAssignmentService->getPresetProductCatalog(41);
$assert(
    ($inactiveAssignmentAfter['linkedProductIds'] ?? null) === []
        && ($inactiveAssignmentAfterReactivation['linkedProductIds'] ?? null) === []
        && ($inactiveAssignmentAfterReactivation['rows'][0]['active'] ?? false) === true,
    'detaching an inactive product is authoritative and it does not resurrect after reactivation'
);
$assert(
    array_keys($catalog['storefront'] ?? []) === ['formFirstAuthoringAvailable', 'formFirstAuthoringContract']
        && ($catalog['storefront']['formFirstAuthoringAvailable'] ?? true) === false
        && ($catalog['storefront']['formFirstAuthoringContract'] ?? '')
            === ControlCenterEditorsService::FORM_FIRST_AUTHORING_CONTRACT,
    'The bootstrap catalog must expose only the active preset-owned form authoring capability'
);

$service->assertStorefrontProductsBelongToPreset(41, [10, 12727]);
$scopeError = '';
try {
    $service->assertStorefrontProductsBelongToPreset(41, [999, 12728]);
} catch (InvalidArgumentException $exception) {
    $scopeError = $exception->getMessage();
}
$assert(
    $scopeError === 'Storefront product_ids are not linked to preset #41: #999, #12728',
    'vNext storefront assignment must report every product outside current CALC_PRESET authority'
);
$expectInvalid(static function () use ($service): void {
    $service->assertStorefrontProductsBelongToPreset(41, [10, 10]);
}, 'vNext storefront assignment must reject duplicate product IDs');

$inactiveMembershipService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    storefrontProductAssignmentLoader: static fn(int $presetId, array $productIds, int $iblockId): array => [
        7001 => [41], // inactive/expired but still canonically assigned
        7002 => [42], // inactive foreign assignment
    ],
    presetProductPropertyAuthority: $exactPropertyAuthority
);
$inactiveMembershipService->assertStorefrontProductsBelongToPreset(41, [7001], 7);
$inactiveForeignRejected = false;
try {
    $inactiveMembershipService->assertStorefrontProductsBelongToPreset(41, [7002], 7);
} catch (InvalidArgumentException $error) {
    $inactiveForeignRejected = str_contains($error->getMessage(), '#7002');
}
$assert(
    $inactiveForeignRejected,
    'inactive linked products remain valid storefront members while inactive foreign products are rejected'
);

$exclusiveError = '';
try {
    ControlCenterEditorsService::assertExclusivePresetAssignments(
        41,
        [12, 11, 13],
        [
            11 => [41],
            12 => [12742, 12741],
            13 => [12743, 41],
        ]
    );
} catch (InvalidArgumentException $exception) {
    $exclusiveError = $exception->getMessage();
}
$assert(
    $exclusiveError === 'Products already assigned to other presets: #12 -> #12741, #12742; #13 -> #12743',
    'Product assignment must reject every foreign preset regardless of legacy MULTIPLE metadata'
);

$calculationLaunch = $service->validateCalculationLaunch(41, [15326, 15321, 15320]);
$assert(($calculationLaunch['offerIds'] ?? []) === [15320, 15321, 15326], 'Calculation launch must reconstruct offers from multiple products in authoritative server order');
$assert(($calculationLaunch['productIds'] ?? []) === [12727, 12764], 'Calculation launch must reconstruct all selected parent products server-side');
$assert(!in_array(102, $calculationLaunch['offerIds'], true), 'Calculation launch returns only the explicitly selected offers');

$presetLaunch = $service->validatePresetLaunch(41);
$assert(($presetLaunch['focusPresetId'] ?? 0) === 41, 'Standalone launch must retain the focus preset');
$assert(($presetLaunch['presetName'] ?? '') === 'Focus preset', 'Standalone launch must resolve the authoritative preset name');
$secondaryPresetLaunch = $service->validatePresetLaunch(12741);
$assert(($secondaryPresetLaunch['focusPresetId'] ?? 0) === 12741, 'Standalone launch must accept another authoritative preset');
$secondaryCalculationLaunch = $service->validateCalculationLaunch(12741, [15320]);
$assert(($secondaryCalculationLaunch['offerIds'] ?? []) === [15320], 'Any authoritative preset may launch its assigned offer');
$singleProductLaunch = $service->validateCalculationLaunch(41, [100]);
$assert(
    ($singleProductLaunch['offerIds'] ?? []) === [100]
        && ($singleProductLaunch['productIds'] ?? []) === [10],
    'Calculation launch scope comes only from current preset membership'
);
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(41, [15320, 15320]);
}, 'Duplicate offer IDs must be rejected');
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(41, []);
}, 'An empty offer selection must be rejected');
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(41, ['15320']);
}, 'String offer IDs must be rejected');
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(41, [0]);
}, 'Zero offer IDs must be rejected');
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(41, [-100]);
}, 'Negative offer IDs must be rejected');
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(41, [100.5]);
}, 'Fractional offer IDs must be rejected');
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(41, [9007199254740992]);
}, 'Unsafe JavaScript-sized offer IDs must be rejected');

$emptyOffersService = new ControlCenterEditorsService(
    static function (int $presetId): array {
        return [
            'status' => 'ok',
            'preset' => ['id' => $presetId, 'name' => 'Focus preset'],
            'products' => [['id' => 10, 'name' => 'Empty product', 'offers' => []]],
        ];
    },
    static fn(): int => 7,
    static fn(): bool => true
);
$expectInvalid(static function () use ($emptyOffersService): void {
    $emptyOffersService->validateCalculationLaunch(41, [1]);
}, 'A product without active offers must be rejected');

$tooManyOffersService = new ControlCenterEditorsService(
    static function (int $presetId): array {
        $offers = [];
        for ($id = 1; $id <= 501; $id++) {
            $offers[] = ['id' => $id, 'name' => 'Offer ' . $id];
        }
        return [
            'status' => 'ok',
            'preset' => ['id' => $presetId, 'name' => 'Focus preset'],
            'products' => [['id' => 10, 'name' => 'Large product', 'offers' => $offers]],
        ];
    },
    static fn(): int => 7,
    static fn(): bool => true
);
$expectInvalid(static function () use ($tooManyOffersService): void {
    $tooManyOffersService->validateCalculationLaunch(41, range(1, 501));
}, 'An oversized selective offer list must be rejected');

$dependencyResolveCalls = [];
$dependencyContractResolver = static function (int $presetId) use (&$dependencyResolveCalls): array {
    $dependencyResolveCalls[] = [$presetId];
    $categoryStatus = [];
    foreach ([
        'ui',
        'catalog_input_mapping',
        'stage_inputs',
        'globals',
        'options_mappings',
        'basket',
        'storefront_presentation',
    ] as $category) {
        $categoryStatus[$category] = [
            'scanned' => true,
            'count' => $category === 'stage_inputs' ? 1 : 0,
            'sourceMode' => $category === 'basket' ? 'declared' : 'discovered',
        ];
    }
    $contract = [
        'contract' => 'prospektweb.calc.preset-public-inputs/v1',
        'presetId' => $presetId,
        'requiredPropertyCodes' => ['CALC_PROP_VOLUME'],
        'consumers' => [[
            'propertyCode' => 'CALC_PROP_VOLUME',
            'category' => 'stage_inputs',
            'source' => 'fixture.stage',
            'path' => 'stages.12.inputs.0.property_code',
            'provenance' => 'discovered',
        ]],
        'categoryStatus' => $categoryStatus,
    ];
    $sortRecursively = static function ($value) use (&$sortRecursively) {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $sortRecursively($item);
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    };
    $contract['fingerprint'] = hash('sha256', (string)json_encode(
        $sortRecursively($contract),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));

    return $contract;
};

$provider = new TestFormFirstAuthoringProvider();
$activeStorefrontValidationCalls = [];
$formFirstService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static fn() => $provider,
    $dependencyContractResolver,
    null,
    null,
    null,
    null,
    null,
    null,
    null,
    null,
    static function (int $presetId, string $fieldId): array {
        if ($presetId !== 41) {
            return [];
        }
        if ($fieldId === 'ui.note') {
            return [[
                'fieldId' => 'ui.note',
                'category' => 'storefront_presentation',
                'source' => 'prospektweb.frontcalc.storefront-definition/v2',
                'path' => 'storefront.main.presentation.field_patches.ui.note',
                'provenance' => 'declared',
            ]];
        }
        if ($fieldId !== 'volume') {
            return [];
        }
        return [[
            'fieldId' => 'volume',
            'category' => 'catalog_input_mapping',
            'source' => 'prospektweb.calc.calculator-input-mapping/v1',
            'path' => 'calculator_input_mapping.mappings.0.target.field_id',
            'provenance' => 'declared',
        ], [
            'fieldId' => 'volume',
            'category' => 'storefront_presentation',
            'source' => 'prospektweb.frontcalc.storefront-definition/v2',
            'path' => 'storefront.main.presentation.field_patches.volume',
            'provenance' => 'declared',
        ]];
    },
    presetMutationCoordinator: $coordinatedMutation,
    activeStorefrontPublicationValidator: static function (int $presetId) use (&$activeStorefrontValidationCalls): void {
        $activeStorefrontValidationCalls[] = $presetId;
    }
);
$formFirstCatalog = $formFirstService->getCatalog();
$assert(
    array_keys($formFirstCatalog['storefront'] ?? []) === ['formFirstAuthoringAvailable', 'formFirstAuthoringContract']
        && ($formFirstCatalog['storefront']['formFirstAuthoringAvailable'] ?? false) === true
        && ($formFirstCatalog['storefront']['formFirstAuthoringContract'] ?? '')
            === ControlCenterEditorsService::FORM_FIRST_AUTHORING_CONTRACT,
    'The catalog must expose only the active form-first capability and exact provider contract'
);

$multiPresetService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    null,
    null,
    static function (): array {
        return [
            ['id' => 41, 'name' => 'Focus preset'],
            ['id' => 12741, 'name' => 'Second preset'],
        ];
    },
    static fn(string $name): array => $name === 'Independent preset' ? [
        'presetId' => 12800,
        'presetName' => 'Independent preset',
        'identityRevision' => str_repeat('d', 64),
    ] : []
);
$multiCatalog = $multiPresetService->getCatalog();
$assert(count($multiCatalog['calculations']) === 2, 'Catalog must list all independent presets');
$multiRegistry = $multiPresetService->getPresetRegistry('', 'all', 'name_asc', 1, 1);
$assert(($multiRegistry['total'] ?? 0) === 2 && count($multiRegistry['rows'] ?? []) === 1, 'Registry must page independent preset summaries');
$assert(
    preg_match('/^[a-f0-9]{64}$/D', (string)($multiRegistry['rows'][0]['revision'] ?? '')) === 1,
    'Every registry row exposes an exact CAS revision'
);

$activationState = ['id' => 41, 'name' => 'Focus preset', 'active' => true, 'updatedAt' => '2026-08-22 10:00:00'];
$activationMetadata = [];
$activationLockedReads = 0;
$activationCoordinator = static function (
    int $presetId,
    array $metadata,
    callable $mutation,
    callable $authoritativeReadback
) use (&$activationState, &$activationMetadata) {
    $before = $activationState;
    try {
        $authoritativeReadback();
        $result = $mutation();
        $authoritativeReadback();
        $activationMetadata[] = $metadata;
        return $result;
    } catch (Throwable $error) {
        $activationState = $before;
        throw $error;
    }
};
$activationService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    presetMutationCoordinator: $activationCoordinator,
    presetActiveStateLoader: static function (int $presetId) use (&$activationState): array {
        return $activationState;
    },
    presetActiveMutationHandler: static function (int $presetId, bool $active) use (&$activationState): void {
        $activationState['active'] = $active;
        $activationState['updatedAt'] = '2026-08-22 10:00:01';
    },
    presetActiveLockedStateLoader: static function (int $presetId) use (&$activationState, &$activationLockedReads): array {
        $activationLockedReads++;
        return $activationState;
    }
);
$activationRevision = hash('sha256', json_encode([
    'presetId' => 41,
    'active' => true,
    'updatedAt' => '2026-08-22 10:00:00',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$activationResult = $activationService->setPresetActive(41, $activationRevision, false);
$assert(
    ($activationResult['active'] ?? true) === false
        && preg_match('/^[a-f0-9]{64}$/D', (string)($activationResult['revision'] ?? '')) === 1
        && ($activationMetadata[0]['action'] ?? '') === 'set_preset_active'
        && ($activationMetadata[0]['expected_revision'] ?? '') === $activationRevision
        && $activationLockedReads >= 3,
    'Single-preset activation locks the authoritative row before CAS and for mutation readback'
);
$staleActivationRejected = false;
try {
    $activationService->setPresetActive(41, $activationRevision, true);
} catch (RuntimeException $error) {
    $staleActivationRejected = $error->getCode() === 409;
}
$assert($staleActivationRejected, 'Single-preset activation rejects a stale registry revision');

$failedActivationState = ['id' => 41, 'name' => 'Focus preset', 'active' => false, 'updatedAt' => '2026-08-22 10:00:01'];
$failedReadback = false;
$failedActivationCoordinator = static function (
    int $presetId,
    array $metadata,
    callable $mutation,
    callable $authoritativeReadback
) use (&$failedActivationState, &$failedReadback) {
    $before = $failedActivationState;
    try {
        $authoritativeReadback();
        $result = $mutation();
        $authoritativeReadback();
        return $result;
    } catch (Throwable $error) {
        $failedActivationState = $before;
        $failedReadback = false;
        throw $error;
    }
};
$failedActivationService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    presetMutationCoordinator: $failedActivationCoordinator,
    presetActiveStateLoader: static function (int $presetId) use (&$failedActivationState, &$failedReadback): array {
        $readback = $failedActivationState;
        if ($failedReadback) {
            $readback['active'] = false;
        }
        return $readback;
    },
    presetActiveMutationHandler: static function (int $presetId, bool $active) use (&$failedActivationState, &$failedReadback): void {
        $failedActivationState['active'] = $active;
        $failedActivationState['updatedAt'] = '2026-08-22 10:00:02';
        $failedReadback = true;
    },
    presetActiveLockedStateLoader: static function (int $presetId) use (&$failedActivationState, &$failedReadback): array {
        $readback = $failedActivationState;
        if ($failedReadback) {
            $readback['active'] = false;
        }
        return $readback;
    }
);
$failedRevision = hash('sha256', json_encode([
    'presetId' => 41,
    'active' => false,
    'updatedAt' => '2026-08-22 10:00:01',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$readbackFailureRaised = false;
try {
    $failedActivationService->setPresetActive(41, $failedRevision, true);
} catch (RuntimeException $error) {
    $readbackFailureRaised = str_contains($error->getMessage(), 'Контрольное чтение');
}
$assert(
    $readbackFailureRaised && $failedActivationState['active'] === false,
    'Activation readback failure rolls back the mutation boundary'
);
$activationSource = (string)file_get_contents(dirname(__DIR__) . '/lib/Services/ControlCenterEditorsService.php');
$assert(
    str_contains($activationSource, 'FROM b_iblock_element')
        && str_contains($activationSource, 'AND IBLOCK_ID = ')
        && str_contains($activationSource, " . ' FOR UPDATE'"),
    'Production activation CAS locks the exact preset element row and validates its CALC_PRESETS iblock'
);
$createdPreset = $multiPresetService->createStandalonePreset('Independent preset');
$assert(
    ($createdPreset['presetId'] ?? 0) === 12800
        && ($createdPreset['presetName'] ?? '') === 'Independent preset'
        && ($createdPreset['revision'] ?? '') === str_repeat('d', 64),
    'Standalone preset creation must return the transactional lifecycle receipt without a post-commit identity reread'
);
$assert(
    !str_contains($activationSource, '$snapshot = call_user_func($this->presetIdentityLoader, $presetId);'),
    'Create must not race by rereading preset identity after the lifecycle transaction commits'
);
$aggregateRevision = str_repeat('b', 64);
$compileHash = str_repeat('c', 64);
$formDefinition = ['version' => 1, 'fields' => [['id' => 'quantity', 'type' => 'number']]];
$bindingDefinition = ['version' => 1, 'bindings' => [['fieldId' => 'quantity', 'target' => 'CALC_PROP_VOLUME']]];
$assert(
    ($formFirstService->loadFormFirstWorkspace(41)['operation'] ?? '') === 'load',
    'The service must delegate the form-first workspace load'
);
$assert(
    ($formFirstService->newVersionFormTemplate(41)['operation'] ?? '') === 'new_version_template',
    'The service must expose the canonical clean-version form template'
);
$materializedSystemFields = $formFirstService->materializeFormFirstSystemFields(
    41,
    $formDefinition,
    $bindingDefinition
);
$assert(
    ($materializedSystemFields['changed'] ?? false) === true
        && ($materializedSystemFields['formDefinition'] ?? null) === $formDefinition
        && ($materializedSystemFields['bindingDefinition'] ?? null) === $bindingDefinition
        && ($provider->calls[count($provider->calls) - 1][0] ?? '') === 'materializeSystemFields',
    'The service must expose the pure system-field materializer without mutating a version itself'
);
$deleteImpact = $formFirstService->inspectFormFirstFieldDeletion(41, 'volume', 'CALC_PROP_VOLUME');
$assert(
    ($deleteImpact['contract'] ?? '') === ControlCenterEditorsService::FORM_FIRST_FIELD_DELETE_IMPACT_CONTRACT
        && ($deleteImpact['removable'] ?? true) === false
        && array_reduce(
            $deleteImpact['blockers'] ?? [],
            static fn(bool $valid, array $blocker): bool => $valid && array_keys($blocker) === [
                'propertyCode',
                'category',
                'source',
                'path',
                'provenance',
            ],
            true
        )
        && array_column($deleteImpact['blockers'] ?? [], 'category') === [
            'stage_inputs',
            'catalog_input_mapping',
            'storefront_presentation',
        ],
    'Field deletion impact must include logic, input-mapping and storefront-presentation blockers'
);
$displayOnlyImpact = $formFirstService->inspectFormFirstFieldDeletion(41, 'ui.note', null);
$assert(
    ($displayOnlyImpact['removable'] ?? true) === false
        && ($displayOnlyImpact['blockers'][0]['category'] ?? '') === 'storefront_presentation'
        && array_key_exists('propertyCode', $displayOnlyImpact['blockers'][0] ?? [])
        && $displayOnlyImpact['blockers'][0]['propertyCode'] === null,
    'A display-only field patched by a storefront must be blocked without a fabricated property code'
);
$invalidDraftImpact = $formFirstService->inspectFormFirstFieldDeletion(41, '23423423', null);
$assert(
    ($invalidDraftImpact['removable'] ?? false) === true,
    'The impact check must allow removing a numeric field id from an otherwise invalid unsaved draft'
);
$assert(
    ($formFirstService->saveFormFirstDraft(
        41,
        $aggregateRevision,
        $formDefinition,
        $bindingDefinition
    )['operation'] ?? '') === 'save_draft',
    'The service must delegate a revisioned form-first draft save'
);
$assert(
    ($formFirstService->previewFormFirst(
        41,
        $formDefinition,
        $bindingDefinition
    )['operation'] ?? '') === 'preview',
    'The service must delegate form-first compile preview'
);
$versionPreview = $formFirstService->previewVersionFormFirst(
    41,
    $formDefinition,
    [
        'version' => 1,
        'bindings' => [[
            'fieldId' => 'quantity',
            'target' => ['kind' => 'property', 'propertyCode' => 'CALC_PROP_VOLUME'],
        ]],
    ],
    [
        'logic' => [
            'runtimePayload' => [
                'stageFormula' => 'CALC_PROP_METHOD + 1',
                'globalSymbols' => [['propertyCode' => 'CALC_PROP_GLOBAL_RATE']],
            ],
        ],
        'inputMappings' => [
            'mappings' => [['target' => ['field_id' => 'quantity']]],
        ],
        'storefronts' => [
            'base' => ['presentation' => ['field_patches' => ['quantity' => []]]],
            'items' => [],
        ],
    ]
);
$versionDependency = $provider->calls[count($provider->calls) - 1][4] ?? [];
$assert(
    ($versionPreview['operation'] ?? '') === 'preview'
        && ($provider->calls[count($provider->calls) - 1][0] ?? '') === 'previewVersionFormFirst'
        && ($versionDependency['requiredPropertyCodes'] ?? null) === ['CALC_PROP_GLOBAL_RATE', 'CALC_PROP_METHOD']
        && in_array('catalog_input_mapping', array_column($versionDependency['consumers'] ?? [], 'category'), true)
        && in_array('storefront_presentation', array_column($versionDependency['consumers'] ?? [], 'category'), true),
    'Version form preview must derive its dependency authority from the exact version bundle.'
);
$assert(
    ($formFirstService->publishFormFirst(41, $aggregateRevision, $compileHash)['operation'] ?? '')
        === 'publish',
    'The service must delegate form-first publication with CAS and compile hash'
);
$assert(
    ($formFirstService->rollbackFormFirst(41, $aggregateRevision, 0)['operation'] ?? '')
        === 'rollback',
    'The service must allow rollback to the pre-form-first revision zero'
);
$assert(
    $activeStorefrontValidationCalls === [41, 41],
    'Publish and rollback must validate all active storefronts after the provider mutation'
);
$assert(
    count($dependencyResolveCalls) === 15
        && array_column($dependencyResolveCalls, 0) === array_fill(0, 15, 41),
    'Every form-first action and deletion impact must freshly resolve the server-owned dependency authority'
);
$formFirstCalls = array_values(array_filter($provider->calls, static function (array $call): bool {
    return in_array($call[0] ?? '', [
        'loadFormFirstWorkspace',
        'newVersionFormTemplate',
        'saveFormFirstDraft',
        'previewFormFirst',
        'previewVersionFormFirst',
        'publishFormFirst',
        'rollbackFormFirst',
    ], true);
}));
$assert(count($formFirstCalls) === 13, 'Form mutations must include authoritative before/after workspace readbacks and isolated version preview');
foreach ($formFirstCalls as $formFirstCall) {
    $passedContract = $formFirstCall[count($formFirstCall) - 1] ?? null;
    $assert(
        ($formFirstCall[1] ?? null) === 41
            && is_array($passedContract)
            && ($passedContract['contract'] ?? '') === 'prospektweb.calc.preset-public-inputs/v1'
            && preg_match('/^[a-f0-9]{64}$/D', (string)($passedContract['fingerprint'] ?? '')) === 1,
        'Each preset-owned form provider call must receive only the preset and fingerprinted dependency contract'
    );
}
$assert(
    ($formFirstService->loadFormFirstWorkspace(12741)['operation'] ?? '') === 'load',
    'Form-first authoring must be available to another authoritative preset'
);
$productlessWorkspace = $formFirstService->loadFormFirstWorkspace(12741);
$assert(
    !array_key_exists('product', $productlessWorkspace)
        && !array_key_exists('productId', $productlessWorkspace),
    'Preset-owned form-first authoring must not expose a product context'
);
$expectInvalid(static function () use ($formFirstService, $formDefinition, $bindingDefinition): void {
    $formFirstService->saveFormFirstDraft(41, 'stale', $formDefinition, $bindingDefinition);
}, 'Form-first draft saves must require an exact lowercase SHA-256 aggregate revision');
$expectInvalid(static function () use ($formFirstService, $aggregateRevision, $bindingDefinition): void {
    $formFirstService->saveFormFirstDraft(
        41,
        $aggregateRevision,
        ['version' => 1, 'padding' => str_repeat('x', 60001)],
        $bindingDefinition
    );
}, 'Form-first documents must be rejected above the 60 KB service cap');
$expectInvalid(static function () use ($formFirstService, $aggregateRevision): void {
    $formFirstService->rollbackFormFirst(41, $aggregateRevision, -1);
}, 'Form-first rollback must reject negative published revisions');
$invalidContractProvider = new TestFormFirstAuthoringProvider();
$invalidContractService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static function () use ($invalidContractProvider) {
        return new class($invalidContractProvider) {
            private $delegate;
            public function __construct($delegate) { $this->delegate = $delegate; }
            public function __call(string $name, array $arguments) {
                $result = call_user_func_array([$this->delegate, $name], $arguments);
                if ($name === 'loadFormFirstWorkspace') {
                    $result['aggregateRevision'] = 1;
                }
                return $result;
            }
        };
    },
    $dependencyContractResolver
);
$expectRuntime(static function () use ($invalidContractService): void {
    $invalidContractService->loadFormFirstWorkspace(41);
}, 'Form-first facade must reject non-string aggregate revisions from the provider');

$mismatchedWorkspaceService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static function () {
        return new class extends TestFormFirstAuthoringProvider {
            public function loadFormFirstWorkspace(
                int $presetId,
                array $dependencyContract = []
            ): array {
                $result = parent::loadFormFirstWorkspace(
                    $presetId,
                    $dependencyContract
                );
                $result['product'] = ['id' => 4403];
                return $result;
            }
        };
    },
    $dependencyContractResolver
);
$expectRuntime(static function () use ($mismatchedWorkspaceService): void {
    $mismatchedWorkspaceService->loadFormFirstWorkspace(41);
}, 'Form-first facade must reject product-scoped provider output');

$catalogSideChannelService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static function () {
        return new class extends TestFormFirstAuthoringProvider {
            public function loadFormFirstWorkspace(
                int $presetId,
                array $dependencyContract = []
            ): array {
                $result = parent::loadFormFirstWorkspace($presetId, $dependencyContract);
                $result['catalog'] = [];
                return $result;
            }
        };
    },
    $dependencyContractResolver
);
$expectRuntime(static function () use ($catalogSideChannelService): void {
    $catalogSideChannelService->loadFormFirstWorkspace(41);
}, 'Form-first facade must reject the removed catalog side channel');

$mismatchedOperationService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static function () {
        return new class extends TestFormFirstAuthoringProvider {
            public function loadFormFirstWorkspace(
                int $presetId,
                array $dependencyContract = []
            ): array {
                $result = parent::loadFormFirstWorkspace(
                    $presetId,
                    $dependencyContract
                );
                $result['operation'] = 'publish';
                return $result;
            }
        };
    },
    $dependencyContractResolver
);
$expectRuntime(static function () use ($mismatchedOperationService): void {
    $mismatchedOperationService->loadFormFirstWorkspace(41);
}, 'Form-first facade must reject a provider response for another operation');

$mismatchedDependencyService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static function () {
        return new class extends TestFormFirstAuthoringProvider {
            public function loadFormFirstWorkspace(
                int $presetId,
                array $dependencyContract = []
            ): array {
                $result = parent::loadFormFirstWorkspace(
                    $presetId,
                    $dependencyContract
                );
                $result['dependencyFingerprint'] = str_repeat('f', 64);
                return $result;
            }
        };
    },
    $dependencyContractResolver
);
$expectRuntime(static function () use ($mismatchedDependencyService): void {
    $mismatchedDependencyService->loadFormFirstWorkspace(41);
}, 'Form-first facade must reject a provider response compiled against a stale dependency fingerprint');

$incompleteDependencyResolver = static function (int $presetId) use (
    $dependencyContractResolver
): array {
    $contract = $dependencyContractResolver($presetId);
    unset($contract['categoryStatus']['storefront_presentation']);
    return $contract;
};
$incompleteDependencyService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static fn() => new TestFormFirstAuthoringProvider(),
    $incompleteDependencyResolver
);
$expectRuntime(static function () use ($incompleteDependencyService): void {
    $incompleteDependencyService->loadFormFirstWorkspace(41);
}, 'Form-first facade must fail closed when any dependency category is unproven');

$tamperedDependencyResolver = static function (int $presetId) use (
    $dependencyContractResolver
): array {
    $contract = $dependencyContractResolver($presetId);
    $contract['requiredPropertyCodes'][] = 'CALC_PROP_FORGED';
    return $contract;
};
$tamperedDependencyService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static fn() => new TestFormFirstAuthoringProvider(),
    $tamperedDependencyResolver
);
$expectRuntime(static function () use ($tamperedDependencyService): void {
    $tamperedDependencyService->loadFormFirstWorkspace(41);
}, 'Form-first facade must reject a dependency contract whose fingerprint no longer matches its contents');

$catalogLoaderCalls = 0;
$catalogIndependentProvider = new TestFormFirstAuthoringProvider();
$catalogIndependentService = new ControlCenterEditorsService(
    static function () use (&$catalogLoaderCalls): array {
        $catalogLoaderCalls++;
        throw new RuntimeException('The product catalog must not authorize preset form authoring');
    },
    static fn(): int => 7,
    static fn(): bool => true,
    static fn() => $catalogIndependentProvider,
    $dependencyContractResolver,
    null,
    null,
    null,
    null,
    null,
    null,
    null,
    static fn(int $presetId): array => ['id' => $presetId, 'name' => 'Preset #' . $presetId]
);
$catalogIndependentWorkspace = $catalogIndependentService->loadFormFirstWorkspace(41);
$assert(
    !array_key_exists('product', $catalogIndependentWorkspace)
        && !array_key_exists('productId', $catalogIndependentWorkspace)
        && $catalogLoaderCalls === 0
        && ($catalogIndependentProvider->calls[0][1] ?? null) === 41
        && is_array($catalogIndependentProvider->calls[0][2] ?? null),
    'Preset form authoring must not read, authorize against, or scope itself to the product catalog'
);
$unavailableFormFirstService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static fn() => null
);
$assert(
    ($unavailableFormFirstService->getCatalog()['storefront']['formFirstAuthoringAvailable'] ?? true) === false,
    'An unavailable form-first provider must be advertised fail-closed'
);
$expectRuntime(static function () use ($unavailableFormFirstService): void {
    $unavailableFormFirstService->loadFormFirstWorkspace(41);
}, 'A form-first load must fail closed when the provider is unavailable');

$savedStorefront = [
    'contract' => 'prospektweb.frontcalc.storefront-definition/v2',
    'id' => 'storefront-01234567890123456789',
    'preset_id' => 12740,
    'name' => 'QA storefront',
    'active' => false,
    'revision' => 1,
    'presentation' => ['field_patches' => new stdClass()],
    'product_ids' => [],
];
$storefrontReadback = unserialize(serialize($savedStorefront));
$assert(
    $storefrontReadback !== $savedStorefront
        && ControlCenterEditorsService::assertStorefrontAuthoritativeReadback(
            $savedStorefront,
            $storefrontReadback
        ) === $storefrontReadback,
    'Equivalent empty JSON objects must survive authoritative storefront readback'
);
$driftedStorefrontReadback = $storefrontReadback;
$driftedStorefrontReadback['revision'] = 2;
$expectRuntime(static function () use ($savedStorefront, $driftedStorefrontReadback): void {
    ControlCenterEditorsService::assertStorefrontAuthoritativeReadback(
        $savedStorefront,
        $driftedStorefrontReadback
    );
}, 'Authoritative storefront readback must still reject real value drift');

echo "Control center editors service tests passed\n";
