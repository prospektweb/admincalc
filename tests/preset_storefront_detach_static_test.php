<?php

declare(strict_types=1);

$service = (string)file_get_contents(__DIR__ . '/../lib/Services/ControlCenterEditorsService.php');
$coordinator = (string)file_get_contents(__DIR__ . '/../lib/Services/PresetMutationCoordinatorService.php');

function preset_storefront_detach_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

preset_storefront_detach_assert(
    strpos($service, 'array_diff($currentProductIds, $productIds)') !== false
        && strpos($service, 'storefrontProductDetacher') !== false
        && strpos($service, 'StorefrontRepository') !== false,
    'preset unlink must explicitly detach the removed products from vNext storefronts'
);
preset_storefront_detach_assert(
    strpos($service, "'action' => 'set_preset_products'") !== false
        && strpos($service, '$this->withPresetMutation(') !== false
        && strpos($coordinator, 'startTransaction()') !== false
        && strpos($coordinator, 'FOR UPDATE') !== false,
    'product assignments and storefront detach must share one durable database transaction'
);
preset_storefront_detach_assert(
    strpos($service, '$linkedReadback = $this->loadLinkedProductIds(') !== false
        && strpos($service, '$this->storefrontProductReadbackLoader') !== false
        && strpos($service, 'остался привязан к витринному калькулятору') !== false,
    'success requires authoritative product and storefront detachment readbacks'
);
$mutationStart = strpos($service, '$applied = [];');
$detachCall = strpos($service, 'call_user_func($this->storefrontProductDetacher', $mutationStart ?: 0);
$rollback = strpos($service, 'foreach (array_reverse($applied)', $detachCall ?: 0);
preset_storefront_detach_assert(
    $mutationStart !== false && $detachCall !== false && $rollback !== false && $mutationStart < $detachCall && $detachCall < $rollback,
    'storefront detach must be inside the product mutation rollback boundary'
);

echo "Preset storefront detach checks passed\n";
