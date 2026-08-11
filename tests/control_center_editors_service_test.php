<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/ControlCenterEditorsService.php';

use Prospektweb\Calc\Services\ControlCenterEditorsService;

final class TestStorefrontEditorProvider
{
    /** @var array<int, array<int, mixed>> */
    public $calls = [];

    public function loadWorkspace(int $productId, string $target = 'effective', string $templateId = ''): array
    {
        $this->calls[] = ['loadWorkspace', $productId, $target, $templateId];
        return $this->result('load', ['target' => $target, 'templateId' => $templateId]);
    }

    public function validateSchema(int $productId, string $target, array $schema): array
    {
        $this->calls[] = ['validateSchema', $productId, $target, $schema];
        return $this->result('validate', ['target' => $target]);
    }

    public function saveTemplate(
        int $productId,
        string $templateId,
        int $expectedRevision,
        string $name,
        int $sectionId,
        array $schema
    ): array {
        $this->calls[] = [
            'saveTemplate',
            $productId,
            $templateId,
            $expectedRevision,
            $name,
            $sectionId,
            $schema,
        ];
        return $this->result('saveTemplate');
    }

    public function saveProduct(int $productId, string $expectedRevision, array $schema): array
    {
        $this->calls[] = ['saveProduct', $productId, $expectedRevision, $schema];
        return $this->result('saveProduct');
    }

    public function enableInheritance(int $productId, string $expectedRevision): array
    {
        $this->calls[] = ['enableInheritance', $productId, $expectedRevision];
        return $this->result('enableInheritance');
    }

    public function deleteTemplate(int $productId, string $templateId, int $expectedRevision): array
    {
        $this->calls[] = ['deleteTemplate', $productId, $templateId, $expectedRevision];
        return $this->result('deleteTemplate');
    }

    private function result(string $operation, array $extra = []): array
    {
        return array_merge([
            'contract' => ControlCenterEditorsService::STOREFRONT_EDITOR_CONTRACT,
            'operation' => $operation,
        ], $extra);
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
$assert(($catalog['calculations'][0]['offerCount'] ?? 0) === 3, 'Catalog offer count must come from active server offers');
$assert(($catalog['calculations'][0]['products'][0]['offers'][1]['id'] ?? 0) === 101, 'Catalog must expose server-authored offer choices');
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

$provider = new TestStorefrontEditorProvider();
$visualService = new ControlCenterEditorsService(
    $presetLoader,
    static fn(): int => 7,
    static fn(): bool => true,
    static fn() => $provider
);
$visualCatalog = $visualService->getCatalog();
$assert(($visualCatalog['storefront']['visualEditorAvailable'] ?? false) === true, 'A complete provider enables the visual editor');
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
    in_array(['loadWorkspace', 10, 'effective', ''], $provider->calls, true),
    'The provider must receive the server-authorized product and load target'
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
