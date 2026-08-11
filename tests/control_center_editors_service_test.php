<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/ControlCenterEditorsService.php';

use Prospektweb\Calc\Services\ControlCenterEditorsService;

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

echo "Control center editors service tests passed\n";
