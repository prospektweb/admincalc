<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/ControlCenterEditorsService.php';

use Prospektweb\Calc\Services\ControlCenterEditorsService;

class TestStorefrontEditorProvider
{
    /** @var array<int, array<int, mixed>> */
    public $calls = [];

    public function loadWorkspace(
        int $productId,
        string $target = 'effective',
        string $templateId = '',
        array $allowedProductIds = []
    ): array
    {
        $this->calls[] = ['loadWorkspace', $productId, $target, $templateId, $allowedProductIds];
        return $this->result('load', ['target' => $target, 'templateId' => $templateId]);
    }

    public function validateSchema(int $productId, string $target, array $schema, array $allowedProductIds = []): array
    {
        $this->calls[] = ['validateSchema', $productId, $target, $schema, $allowedProductIds];
        return $this->result('validate', ['target' => $target]);
    }

    public function saveTemplate(
        int $productId,
        string $templateId,
        int $expectedRevision,
        string $name,
        int $sectionId,
        array $schema,
        array $allowedProductIds = []
    ): array {
        $this->calls[] = [
            'saveTemplate',
            $productId,
            $templateId,
            $expectedRevision,
            $name,
            $sectionId,
            $schema,
            $allowedProductIds,
        ];
        return $this->result('saveTemplate');
    }

    public function saveProduct(
        int $productId,
        string $expectedRevision,
        array $schema,
        array $allowedProductIds = []
    ): array
    {
        $this->calls[] = ['saveProduct', $productId, $expectedRevision, $schema, $allowedProductIds];
        return $this->result('saveProduct');
    }

    public function enableInheritance(int $productId, string $expectedRevision, array $allowedProductIds = []): array
    {
        $this->calls[] = ['enableInheritance', $productId, $expectedRevision, $allowedProductIds];
        return $this->result('enableInheritance');
    }

    public function deleteTemplate(
        int $productId,
        string $templateId,
        int $expectedRevision,
        array $allowedProductIds = []
    ): array
    {
        $this->calls[] = ['deleteTemplate', $productId, $templateId, $expectedRevision, $allowedProductIds];
        return $this->result('deleteTemplate');
    }

    public function loadFormFirstWorkspace(
        int $productId,
        int $presetId,
        array $allowedProductIds = [],
        array $dependencyContract = []
    ): array {
        $this->calls[] = ['loadFormFirstWorkspace', $productId, $presetId, $allowedProductIds, $dependencyContract];
        return $this->formFirstResult('load', $dependencyContract);
    }

    public function saveFormFirstDraft(
        int $productId,
        int $presetId,
        string $expectedAggregateRevision,
        array $formDefinition,
        array $bindingDefinition,
        array $allowedProductIds = [],
        array $dependencyContract = []
    ): array {
        $this->calls[] = [
            'saveFormFirstDraft',
            $productId,
            $presetId,
            $expectedAggregateRevision,
            $formDefinition,
            $bindingDefinition,
            $allowedProductIds,
            $dependencyContract,
        ];
        return $this->formFirstResult('save_draft', $dependencyContract);
    }

    public function previewFormFirst(
        int $productId,
        int $presetId,
        array $formDefinition,
        array $bindingDefinition,
        array $allowedProductIds = [],
        array $dependencyContract = []
    ): array {
        $this->calls[] = [
            'previewFormFirst',
            $productId,
            $presetId,
            $formDefinition,
            $bindingDefinition,
            $allowedProductIds,
            $dependencyContract,
        ];
        return $this->formFirstResult('preview', $dependencyContract);
    }

    public function publishFormFirst(
        int $productId,
        int $presetId,
        string $expectedAggregateRevision,
        string $expectedCompileHash,
        array $allowedProductIds = [],
        array $dependencyContract = []
    ): array {
        $this->calls[] = [
            'publishFormFirst',
            $productId,
            $presetId,
            $expectedAggregateRevision,
            $expectedCompileHash,
            $allowedProductIds,
            $dependencyContract,
        ];
        return $this->formFirstResult('publish', $dependencyContract);
    }

    public function rollbackFormFirst(
        int $productId,
        int $presetId,
        string $expectedAggregateRevision,
        int $targetPublishedRevision,
        array $allowedProductIds = [],
        array $dependencyContract = []
    ): array {
        $this->calls[] = [
            'rollbackFormFirst',
            $productId,
            $presetId,
            $expectedAggregateRevision,
            $targetPublishedRevision,
            $allowedProductIds,
            $dependencyContract,
        ];
        return $this->formFirstResult('rollback', $dependencyContract);
    }

    private function result(string $operation, array $extra = []): array
    {
        return array_merge([
            'contract' => ControlCenterEditorsService::STOREFRONT_EDITOR_CONTRACT,
            'operation' => $operation,
        ], $extra);
    }

    private function formFirstResult(string $operation, array $dependencyContract): array
    {
        return [
            'contract' => ControlCenterEditorsService::FORM_FIRST_AUTHORING_CONTRACT,
            'operation' => $operation,
            'product' => ['id' => 4267],
            'presetId' => 12740,
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

final class LegacyStorefrontEditorProvider
{
    public function loadWorkspace(int $productId, string $target = 'effective', string $templateId = '', array $allowedProductIds = []): array { return $this->result(); }
    public function validateSchema(int $productId, string $target, array $schema, array $allowedProductIds = []): array { return $this->result(); }
    public function saveTemplate(int $productId, string $templateId, int $expectedRevision, string $name, int $sectionId, array $schema, array $allowedProductIds = []): array { return $this->result(); }
    public function saveProduct(int $productId, string $expectedRevision, array $schema, array $allowedProductIds = []): array { return $this->result(); }
    public function enableInheritance(int $productId, string $expectedRevision, array $allowedProductIds = []): array { return $this->result(); }
    public function deleteTemplate(int $productId, string $templateId, int $expectedRevision, array $allowedProductIds = []): array { return $this->result(); }

    private function result(): array
    {
        return ['contract' => ControlCenterEditorsService::STOREFRONT_EDITOR_CONTRACT];
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
                'name' => 'Phase 5A pilot',
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
        ],
    ];
};

$service = new ControlCenterEditorsService($presetLoader, static fn(): int => 7, static fn(): bool => true);
$catalog = $service->getCatalog();

$assert($catalog['contract'] === ControlCenterEditorsService::CONTRACT, 'Catalog contract must be versioned');
$assert($catalog['focusPresetId'] === 12740, 'Only preset 12740 is in the Phase 4A workspace');
$assert(($catalog['calculations'][0]['offerCount'] ?? 0) === 4, 'Catalog offer count must come from active server offers');
$assert(($catalog['calculations'][0]['products'][1]['offers'][1]['id'] ?? 0) === 101, 'Catalog must expose server-authored offer choices');
$assert(($catalog['storefront']['productIblockId'] ?? 0) === 7, 'Storefront launch catalog must expose the configured product iblock');
$assert(($catalog['storefront']['products'][0]['presetIds'] ?? []) === [12740], 'Storefront products must carry the focus-preset relation');
$assert(($catalog['storefront']['visualEditorAvailable'] ?? true) === false, 'The visual editor must fail closed without a provider');
$assert(
    ($catalog['storefront']['visualEditorContract'] ?? '') === ControlCenterEditorsService::STOREFRONT_EDITOR_CONTRACT,
    'The storefront catalog must advertise the native editor contract'
);

$calculationLaunch = $service->validateCalculationLaunch(12740, 10, [101, 100]);
$assert(($calculationLaunch['offerIds'] ?? []) === [100, 101], 'Calculation launch must reconstruct the selected active offers in server order');
$assert(($calculationLaunch['productId'] ?? 0) === 10, 'Calculation launch must preserve the validated product ID');
$assert(!in_array(102, $calculationLaunch['offerIds'], true), 'Calculation launch must not mix offers from another product');

$presetLaunch = $service->validatePresetLaunch(12740);
$assert(($presetLaunch['focusPresetId'] ?? 0) === 12740, 'Standalone launch must retain the focus preset');
$assert(($presetLaunch['presetName'] ?? '') === 'Focus preset', 'Standalone launch must resolve the authoritative preset name');
$expectInvalid(static function () use ($service): void {
    $service->validatePresetLaunch(12741);
}, 'Standalone launch must reject a non-focus preset');

$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(12741, 10, [100]);
}, 'A non-focus preset must be rejected');
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(12740, 999, [100]);
}, 'A product outside preset 12740 must be rejected');
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(12740, 10, [100, 102]);
}, 'An offer from another product must be rejected');
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(12740, 10, [100, 100]);
}, 'Duplicate offer IDs must be rejected');
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(12740, 10, []);
}, 'An empty offer selection must be rejected');
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(12740, 10, ['100']);
}, 'String offer IDs must be rejected');
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(12740, 10, [0]);
}, 'Zero offer IDs must be rejected');
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(12740, 10, [-100]);
}, 'Negative offer IDs must be rejected');
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(12740, 10, [100.5]);
}, 'Fractional offer IDs must be rejected');
$expectInvalid(static function () use ($service): void {
    $service->validateCalculationLaunch(12740, 10, [9007199254740992]);
}, 'Unsafe JavaScript-sized offer IDs must be rejected');

$storefrontLaunch = $service->validateStorefrontLaunch(11);
$assert(($storefrontLaunch['productIblockId'] ?? 0) === 7, 'Storefront launch must use the configured product iblock');
$assert(($storefrontLaunch['productName'] ?? '') === 'Product B', 'Storefront launch must resolve the product server-side');
$expectInvalid(static function () use ($service): void {
    $service->validateStorefrontLaunch(999);
}, 'A storefront product outside preset 12740 must be rejected');

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
    $emptyOffersService->validateCalculationLaunch(12740, 10, [1]);
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
    $tooManyOffersService->validateCalculationLaunch(12740, 10, range(1, 501));
}, 'An oversized selective offer list must be rejected');

$dependencyResolveCalls = [];
$dependencyContractResolver = static function (int $presetId, array $allowedProductIds) use (&$dependencyResolveCalls): array {
    $dependencyResolveCalls[] = [$presetId, $allowedProductIds];
    $categoryStatus = [];
    foreach ([
        'ui',
        'passive_context',
        'stage_inputs',
        'globals',
        'options_mappings',
        'routes',
        'basket',
        'seo_display',
    ] as $category) {
        $categoryStatus[$category] = [
            'scanned' => true,
            'count' => $category === 'ui' ? 1 : 0,
            'sourceMode' => $category === 'basket' ? 'declared' : 'discovered',
        ];
    }
    $contract = [
        'contract' => 'prospektweb.calc.preset-public-inputs/v1',
        'presetId' => $presetId,
        'requiredPropertyCodes' => ['CALC_PROP_VOLUME'],
        'consumers' => [[
            'propertyCode' => 'CALC_PROP_VOLUME',
            'category' => 'ui',
            'source' => 'fixture.runtime',
            'path' => 'products.4267.schema.fields.0.property_code',
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

$provider = new TestStorefrontEditorProvider();
$visualService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static fn() => $provider,
    $dependencyContractResolver
);
$visualCatalog = $visualService->getCatalog();
$assert(($visualCatalog['storefront']['visualEditorAvailable'] ?? false) === true, 'A complete provider enables the visual editor');
$assert(
    ($visualCatalog['storefront']['formFirstAuthoringAvailable'] ?? false) === true
        && ($visualCatalog['storefront']['formFirstAuthoringContract'] ?? '')
            === ControlCenterEditorsService::FORM_FIRST_AUTHORING_CONTRACT
        && ($visualCatalog['storefront']['formFirstPilotProductIds'] ?? []) === [4267],
    'The catalog must expose the exact form-first pilot gate and provider contract'
);
$legacyCatalog = (new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static fn() => new LegacyStorefrontEditorProvider(),
    $dependencyContractResolver
))->getCatalog();
$assert(
    ($legacyCatalog['storefront']['visualEditorAvailable'] ?? false) === true
        && ($legacyCatalog['storefront']['formFirstAuthoringAvailable'] ?? true) === false,
    'A legacy provider must retain the old visual editor while the form-first gate stays disabled'
);
$assert(
    ($visualService->loadStorefrontWorkspace(10, 'effective')['operation'] ?? '') === 'load',
    'The service must delegate an effective workspace load'
);
$assert(
    ($visualService->loadStorefrontWorkspace(10, 'template', 'abcdef0123456789')['templateId'] ?? '') === 'abcdef0123456789',
    'The service must delegate an exact template workspace load'
);
$assert(
    ($visualService->validateStorefrontSchema(10, 'product', ['version' => 2, 'fields' => [['property_code' => 'A']]])['operation'] ?? '') === 'validate',
    'The service must delegate structured schema validation'
);
$assert(
    ($visualService->saveStorefrontTemplate(
        10,
        '',
        0,
        'New template',
        0,
        ['version' => 2, 'fields' => [['property_code' => 'A']]]
    )['operation'] ?? '') === 'saveTemplate',
    'The service must delegate template creation with an empty provider ID'
);
$individualRevision = str_repeat('a', 64);
$assert(
    ($visualService->saveStorefrontProduct(
        10,
        $individualRevision,
        ['version' => 2, 'fields' => [['property_code' => 'A']]]
    )['operation'] ?? '') === 'saveProduct',
    'The service must delegate an individual product save'
);
$assert(
    ($visualService->enableStorefrontInheritance(10, $individualRevision)['operation'] ?? '') === 'enableInheritance',
    'The service must delegate inheritance activation'
);
$assert(
    ($visualService->deleteStorefrontTemplate(10, 'abcdef0123456789', 4)['operation'] ?? '') === 'deleteTemplate',
    'The service must delegate revisioned template deletion'
);
$assert(
    in_array(['loadWorkspace', 10, 'effective', '', [4267, 10, 11]], $provider->calls, true),
    'The provider must receive the current server-authorized product allowlist and load target'
);
$aggregateRevision = str_repeat('b', 64);
$compileHash = str_repeat('c', 64);
$formDefinition = ['version' => 1, 'fields' => [['id' => 'quantity', 'type' => 'number']]];
$bindingDefinition = ['version' => 1, 'bindings' => [['fieldId' => 'quantity', 'target' => 'CALC_PROP_VOLUME']]];
$assert(
    ($visualService->loadFormFirstWorkspace(4267, 12740)['operation'] ?? '') === 'load',
    'The service must delegate the form-first workspace load'
);
$assert(
    ($visualService->saveFormFirstDraft(
        4267,
        12740,
        $aggregateRevision,
        $formDefinition,
        $bindingDefinition
    )['operation'] ?? '') === 'save_draft',
    'The service must delegate a revisioned form-first draft save'
);
$assert(
    ($visualService->previewFormFirst(
        4267,
        12740,
        $formDefinition,
        $bindingDefinition
    )['operation'] ?? '') === 'preview',
    'The service must delegate form-first compile preview'
);
$assert(
    ($visualService->publishFormFirst(4267, 12740, $aggregateRevision, $compileHash)['operation'] ?? '')
        === 'publish',
    'The service must delegate form-first publication with CAS and compile hash'
);
$assert(
    ($visualService->rollbackFormFirst(4267, 12740, $aggregateRevision, 0)['operation'] ?? '')
        === 'rollback',
    'The service must allow rollback to the pre-form-first revision zero'
);
$assert(
    count($dependencyResolveCalls) === 5
        && array_column($dependencyResolveCalls, 0) === [12740, 12740, 12740, 12740, 12740],
    'Every form-first action must freshly resolve the server-owned dependency authority'
);
$formFirstCalls = array_values(array_filter($provider->calls, static function (array $call): bool {
    return in_array($call[0] ?? '', [
        'loadFormFirstWorkspace',
        'saveFormFirstDraft',
        'previewFormFirst',
        'publishFormFirst',
        'rollbackFormFirst',
    ], true);
}));
$assert(count($formFirstCalls) === 5, 'All five form-first calls must reach the provider');
foreach ($formFirstCalls as $formFirstCall) {
    $passedContract = $formFirstCall[count($formFirstCall) - 1] ?? null;
    $passedAllowlist = $formFirstCall[count($formFirstCall) - 2] ?? null;
    $assert(
        $passedAllowlist === [4267, 10, 11]
            && is_array($passedContract)
            && ($passedContract['contract'] ?? '') === 'prospektweb.calc.preset-public-inputs/v1'
            && preg_match('/^[a-f0-9]{64}$/D', (string)($passedContract['fingerprint'] ?? '')) === 1,
        'Each form-first provider call must receive the current allowlist and fingerprinted dependency contract'
    );
}
$expectInvalid(static function () use ($visualService): void {
    $visualService->loadFormFirstWorkspace(10, 12741);
}, 'Form-first actions must reject a preset outside the exact 12740 pilot');
$expectInvalid(static function () use ($visualService, $aggregateRevision, $formDefinition, $bindingDefinition): void {
    $visualService->saveFormFirstDraft(
        999,
        12740,
        $aggregateRevision,
        $formDefinition,
        $bindingDefinition
    );
}, 'Form-first actions must reject a product outside the current preset allowlist');
$expectInvalid(static function () use ($visualService, $formDefinition, $bindingDefinition): void {
    $visualService->saveFormFirstDraft(4267, 12740, 'stale', $formDefinition, $bindingDefinition);
}, 'Form-first draft saves must require an exact lowercase SHA-256 aggregate revision');
$expectInvalid(static function () use ($visualService, $aggregateRevision, $bindingDefinition): void {
    $visualService->saveFormFirstDraft(
        4267,
        12740,
        $aggregateRevision,
        ['version' => 1, 'padding' => str_repeat('x', 60001)],
        $bindingDefinition
    );
}, 'Form-first documents must be rejected above the 60 KB service cap');
$expectInvalid(static function () use ($visualService, $aggregateRevision): void {
    $visualService->rollbackFormFirst(4267, 12740, $aggregateRevision, -1);
}, 'Form-first rollback must reject negative published revisions');
$invalidContractProvider = new TestStorefrontEditorProvider();
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
    $invalidContractService->loadFormFirstWorkspace(4267, 12740);
}, 'Form-first facade must reject non-string aggregate revisions from the provider');

$mismatchedWorkspaceService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static function () {
        return new class extends TestStorefrontEditorProvider {
            public function loadFormFirstWorkspace(
                int $productId,
                int $presetId,
                array $allowedProductIds = [],
                array $dependencyContract = []
            ): array {
                $result = parent::loadFormFirstWorkspace(
                    $productId,
                    $presetId,
                    $allowedProductIds,
                    $dependencyContract
                );
                $result['product']['id'] = 4403;
                return $result;
            }
        };
    },
    $dependencyContractResolver
);
$expectRuntime(static function () use ($mismatchedWorkspaceService): void {
    $mismatchedWorkspaceService->loadFormFirstWorkspace(4267, 12740);
}, 'Form-first facade must reject a provider workspace for another product');

$mismatchedOperationService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static function () {
        return new class extends TestStorefrontEditorProvider {
            public function loadFormFirstWorkspace(
                int $productId,
                int $presetId,
                array $allowedProductIds = [],
                array $dependencyContract = []
            ): array {
                $result = parent::loadFormFirstWorkspace(
                    $productId,
                    $presetId,
                    $allowedProductIds,
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
    $mismatchedOperationService->loadFormFirstWorkspace(4267, 12740);
}, 'Form-first facade must reject a provider response for another operation');

$mismatchedDependencyService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static function () {
        return new class extends TestStorefrontEditorProvider {
            public function loadFormFirstWorkspace(
                int $productId,
                int $presetId,
                array $allowedProductIds = [],
                array $dependencyContract = []
            ): array {
                $result = parent::loadFormFirstWorkspace(
                    $productId,
                    $presetId,
                    $allowedProductIds,
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
    $mismatchedDependencyService->loadFormFirstWorkspace(4267, 12740);
}, 'Form-first facade must reject a provider response compiled against a stale dependency fingerprint');

$incompleteDependencyResolver = static function (int $presetId, array $allowedProductIds) use (
    $dependencyContractResolver
): array {
    $contract = $dependencyContractResolver($presetId, $allowedProductIds);
    unset($contract['categoryStatus']['routes']);
    return $contract;
};
$incompleteDependencyService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static fn() => new TestStorefrontEditorProvider(),
    $incompleteDependencyResolver
);
$expectRuntime(static function () use ($incompleteDependencyService): void {
    $incompleteDependencyService->loadFormFirstWorkspace(4267, 12740);
}, 'Form-first facade must fail closed when any dependency category is unproven');

$tamperedDependencyResolver = static function (int $presetId, array $allowedProductIds) use (
    $dependencyContractResolver
): array {
    $contract = $dependencyContractResolver($presetId, $allowedProductIds);
    $contract['requiredPropertyCodes'][] = 'CALC_PROP_FORGED';
    return $contract;
};
$tamperedDependencyService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static fn() => new TestStorefrontEditorProvider(),
    $tamperedDependencyResolver
);
$expectRuntime(static function () use ($tamperedDependencyService): void {
    $tamperedDependencyService->loadFormFirstWorkspace(4267, 12740);
}, 'Form-first facade must reject a dependency contract whose fingerprint no longer matches its contents');

$mutableProducts = [
    ['id' => 4267, 'name' => 'Phase 5A pilot', 'offers' => [['id' => 13142, 'name' => 'Pilot offer']]],
    ['id' => 11, 'name' => 'Product B', 'offers' => [['id' => 101, 'name' => 'Offer B1']]],
];
$mutableLoader = static function (int $presetId) use (&$mutableProducts): array {
    return [
        'status' => 'ok',
        'preset' => ['id' => $presetId, 'name' => 'Focus preset'],
        'products' => $mutableProducts,
    ];
};
$mutableProvider = new TestStorefrontEditorProvider();
$mutableService = new ControlCenterEditorsService(
    $mutableLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static fn() => $mutableProvider,
    $dependencyContractResolver
);
$mutableService->loadStorefrontWorkspace(4267, 'effective');
$mutableProducts = [
    ['id' => 11, 'name' => 'Product B', 'offers' => [['id' => 101, 'name' => 'Offer B1']]],
];
$callsBeforeStaleProduct = count($mutableProvider->calls);
$expectInvalid(static function () use ($mutableService): void {
    $mutableService->saveStorefrontProduct(4267, str_repeat('d', 64), ['version' => 2, 'fields' => [['property_code' => 'A']]]);
}, 'A stale product must be re-resolved and rejected before a legacy save');
$expectInvalid(static function () use ($mutableService): void {
    $mutableService->loadFormFirstWorkspace(4267, 12740);
}, 'A stale product must be re-resolved and rejected before a form-first action');
$assert(
    count($mutableProvider->calls) === $callsBeforeStaleProduct,
    'A stale product must never reach either legacy or form-first provider methods'
);
$mutableProducts = [
    ['id' => 4267, 'name' => 'Phase 5A pilot', 'offers' => [['id' => 13142, 'name' => 'Pilot offer']]],
];
$mutableService->loadFormFirstWorkspace(4267, 12740);
$mutableLastCall = $mutableProvider->calls[count($mutableProvider->calls) - 1] ?? [];
$assert(
    ($mutableLastCall[0] ?? '') === 'loadFormFirstWorkspace'
        && ($mutableLastCall[3] ?? null) === [4267]
        && is_array($mutableLastCall[4] ?? null),
    'Each provider action must receive the newly resolved allowlist and dependency contract'
);
$callsBeforeRejectedProduct = count($provider->calls);
$expectInvalid(static function () use ($visualService): void {
    $visualService->loadStorefrontWorkspace(999, 'effective');
}, 'A product outside the focus preset must be rejected before visual-editor delegation');
$assert(
    count($provider->calls) === $callsBeforeRejectedProduct,
    'Rejected products must never reach the FrontCalc provider'
);
$expectInvalid(static function () use ($visualService): void {
    $visualService->loadStorefrontWorkspace(10, 'template');
}, 'Template loads must require an exact template ID');
$expectInvalid(static function () use ($visualService): void {
    $visualService->loadStorefrontWorkspace(10, 'effective', 'abcdef0123456789');
}, 'Non-template loads must not carry a template ID');
$expectInvalid(static function () use ($visualService): void {
    $visualService->validateStorefrontSchema(10, 'effective', ['fields' => [['property_code' => 'A']]]);
}, 'Schema validation must use a mutable product or template target');

$unavailableVisualService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static fn() => null
);
$assert(
    ($unavailableVisualService->getCatalog()['storefront']['visualEditorAvailable'] ?? true) === false,
    'An unavailable provider must be advertised fail-closed'
);
$expectRuntime(static function () use ($unavailableVisualService): void {
    $unavailableVisualService->loadStorefrontWorkspace(10, 'effective');
}, 'A native load must fail closed when the provider is unavailable');

echo "Control center editors service tests passed\n";
