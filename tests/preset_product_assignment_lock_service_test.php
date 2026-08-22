<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/PresetProductAssignmentLockService.php';
require_once dirname(__DIR__) . '/lib/Services/PresetMutationCoordinatorService.php';
require_once dirname(__DIR__) . '/lib/Services/ControlCenterEditorsService.php';

use Prospektweb\Calc\Services\ControlCenterEditorsService;
use Prospektweb\Calc\Services\PresetProductAssignmentLockService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$events = [];
$held = false;
$locker = static function (int $productIblockId, callable $criticalSection) use (&$events, &$held) {
    if ($held) {
        $events[] = 'interleaving-blocked';
        throw new RuntimeException('Simulated product-assignment interleaving was blocked.', 409);
    }
    $held = true;
    $events[] = 'lock-enter:' . $productIblockId;
    try {
        return $criticalSection($productIblockId);
    } finally {
        $events[] = 'lock-exit:' . $productIblockId;
        $held = false;
    }
};
$catalogProvider = static function (int $presetId, string $query, int $page, int $pageSize): array {
    return [
        'presetName' => 'Preset #' . $presetId,
        'productIblockId' => 7,
        'linkedProductIds' => [11],
        'revision' => str_repeat('a', 64),
        'rows' => [],
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => 0,
    ];
};
$mutation = static function (
    int $presetId,
    array $productIds,
    string $expectedRevision,
    int $lockedProductIblockId
) use (&$events, &$held, $catalogProvider): array {
    if (!$held || $lockedProductIblockId !== 7) {
        throw new RuntimeException('Preset product mutation ran outside the shared lock.');
    }
    $events[] = 'preset-products-mutation';
    return $catalogProvider($presetId, '', 1, 50);
};

$service = new ControlCenterEditorsService(
    presetLoader: static fn(int $presetId): array => ['preset' => ['id' => $presetId]],
    productIblockIdResolver: static fn(): int => 7,
    frontcalcAvailabilityResolver: static fn(): bool => false,
    presetProductCatalogLoader: $catalogProvider,
    presetProductMutationHandler: $mutation,
    storefrontProductDetacher: static fn(int $presetId, array $productIds): array => [],
    presetProductAssignmentLocker: $locker,
    presetMutationCoordinator: static function (
        int $presetId,
        array $metadata,
        callable $mutation,
        callable $authoritativeReadback
    ) {
        $authoritativeReadback();
        $result = $mutation();
        $authoritativeReadback();
        return $result;
    },
    storefrontProductReadbackLoader: static fn(int $presetId): array => [
        'preset_id' => $presetId,
        'items' => [],
    ],
    presetProductPropertyAuthority: static fn(int $productIblockId, bool $forUpdate): array => [
        'productIblockId' => $productIblockId,
        'propertyId' => 91,
        'multiple' => false,
    ]
);

$initialImpact = $service->previewPresetProductImpact(41, [11], str_repeat('a', 64));
$service->setPresetProducts(
    41,
    [11],
    str_repeat('a', 64),
    (string)$initialImpact['impactFingerprint']
);
$interleavingBlocked = false;
$storefrontResult = $service->withPresetProductAssignmentLock(
    static function (int $lockedProductIblockId) use (
        $service,
        &$events,
        &$held,
        &$interleavingBlocked
    ): string {
        if (!$held || $lockedProductIblockId !== 7) {
            throw new RuntimeException('Storefront proof ran outside the shared lock.');
        }
        $events[] = 'storefront-assignment-proof';
        try {
            $service->setPresetProducts(41, [11], str_repeat('b', 64), str_repeat('c', 64));
        } catch (RuntimeException $error) {
            $interleavingBlocked = $error->getCode() === 409;
        }
        $events[] = 'storefront-repository-save';
        return 'saved';
    }
);

$assert($storefrontResult === 'saved', 'storefront critical section returns its repository result');
$assert($interleavingBlocked, 'a preset-product mutation cannot interleave with storefront proof and save');
$assert($events === [
    'lock-enter:7',
    'preset-products-mutation',
    'lock-exit:7',
    'lock-enter:7',
    'storefront-assignment-proof',
    'interleaving-blocked',
    'storefront-repository-save',
    'lock-exit:7',
], 'both workflows use one exact product-iblock lock boundary');

$adapterEvents = [];
$lockService = new PresetProductAssignmentLockService(
    static function (int $productIblockId, callable $criticalSection) use (&$adapterEvents) {
        $adapterEvents[] = $productIblockId;
        return $criticalSection($productIblockId);
    }
);
$assert(
    $lockService->withLock(7, static fn(int $productIblockId): int => $productIblockId) === 7
        && $adapterEvents === [7],
    'lock service exposes a narrow deterministic callback seam'
);

$controlSource = (string)file_get_contents(dirname(__DIR__) . '/lib/Services/ControlCenterEditorsService.php');
$lockSource = (string)file_get_contents(dirname(__DIR__) . '/lib/Services/PresetProductAssignmentLockService.php');
$endpoint = (string)file_get_contents(dirname(__DIR__) . '/tools/control_center_editors.php');
$assert(
    substr_count($lockSource, 'prospektweb.calc.preset-products.') === 1
        && strpos($lockSource, 'GET_LOCK') !== false
        && strpos($lockSource, 'RELEASE_LOCK') !== false
        && strpos($lockSource, 'flock(') === false
        && strpos($controlSource, 'GET_LOCK') === false,
    'cross-process product assignment lock has one database-backed authority'
);
$storefrontSave = strpos($endpoint, "if (\$action === 'storefront_save')");
$lockCall = strpos($endpoint, '$service->withPresetProductAssignmentLock(', $storefrontSave ?: 0);
$coordinatorCall = strpos($endpoint, '$service->withPresetMutation(', $storefrontSave ?: 0);
$assignmentProof = strpos($endpoint, '$service->assertStorefrontProductsBelongToPreset(', $storefrontSave ?: 0);
$repositorySave = strpos($endpoint, '$repository->save($definition)', $storefrontSave ?: 0);
$assert(
    $storefrontSave !== false
        && $lockCall !== false
        && $coordinatorCall !== false
        && $assignmentProof !== false
        && $repositorySave !== false
        && $lockCall < $coordinatorCall
        && $coordinatorCall < $assignmentProof
        && $assignmentProof < $repositorySave,
    'storefront proof and save execute inside the product lock and durable preset transaction'
);

fwrite(STDOUT, "Preset product assignment lock tests passed\n");
