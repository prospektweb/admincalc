<?php

declare(strict_types=1);

$endpoint = (string)file_get_contents(__DIR__ . '/../tools/control_center_editors.php');

function storefront_vnext_api_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach (['storefront_list', 'storefront_get', 'storefront_save', 'storefront_delete'] as $action) {
    storefront_vnext_api_assert(
        strpos($endpoint, "\$action === '" . $action . "'") !== false,
        'vNext endpoint is missing: ' . $action
    );
}
storefront_vnext_api_assert(
    strpos($endpoint, 'StorefrontRepository') !== false
        && strpos($endpoint, "'expected_revision'") !== false
        && strpos($endpoint, "['action', 'sessid', 'expected_revision', 'storefront']") !== false,
    'endpoint must delegate exact vNext definition validation and CAS to StorefrontRepository'
);
storefront_vnext_api_assert(
    strpos($endpoint, 'assertStorefrontProductsBelongToPreset(') !== false
        && strpos($endpoint, '$lockedProductIblockId') !== false,
    'save must prove every product assignment against the exact locked CALC_PRESET authority'
);
$saveAction = strpos($endpoint, "if (\$action === 'storefront_save')");
$semanticValidation = strpos($endpoint, '$validateStorefrontPresentation($presetId, $definition);', $saveAction ?: 0);
$coordinatorMutation = strpos($endpoint, '$service->withPresetMutation(', $saveAction ?: 0);
$saveMutation = strpos($endpoint, '$repository->save($definition)', $saveAction ?: 0);
storefront_vnext_api_assert(
    $saveAction !== false
    && $coordinatorMutation !== false
    && $semanticValidation !== false
    && $saveMutation !== false
    && $coordinatorMutation < $semanticValidation
    && $semanticValidation < $saveMutation,
    'presentation validation and repository save must share the durable preset mutation boundary'
);
foreach (['publishedBundleForPreset', 'StorefrontPresentationProjector'] as $authority) {
    storefront_vnext_api_assert(
        strpos($endpoint, $authority) !== false,
        'storefront save semantic validation is missing authority: ' . $authority
    );
}
storefront_vnext_api_assert(
    strpos($endpoint, 'publishedAuthoringForPreset') === false
        && strpos($endpoint, 'publishedSnapshotForPreset') === false,
    'storefront validation must read authoring and runtime from one immutable publication bundle'
);
storefront_vnext_api_assert(
    strpos($endpoint, 'Активная витрина должна изменять представление базовой формы.') !== false,
    'an active no-op storefront is rejected instead of creating a meaningless assignment'
);
storefront_vnext_api_assert(
    strpos($endpoint, 'catch (\InvalidArgumentException $exception)') !== false
    && strpos($endpoint, '$respond(422') !== false,
    'projector validation failures return 422 and cannot reach repository save'
);
$deleteAction = strpos($endpoint, "if (\$action === 'storefront_delete')");
$deleteGet = strpos($endpoint, '$existing = $repository->get($storefrontId);', $deleteAction ?: 0);
$deleteCoordinator = strpos($endpoint, '$deleted = $service->withPresetMutation(', $deleteAction ?: 0);
$deleteMutation = strpos($endpoint, '$repository->delete($storefrontId, $expectedRevision);', $deleteAction ?: 0);
storefront_vnext_api_assert(
    $deleteAction !== false
        && $deleteGet !== false
        && $deleteCoordinator !== false
        && $deleteMutation !== false
        && $deleteGet < $deleteCoordinator
        && $deleteCoordinator < $deleteMutation
        && strpos($endpoint, 'Deleted storefront remains present after authoritative readback', $deleteAction) !== false,
    'delete must verify ownership, mutate transactionally and prove authoritative absence'
);
foreach ([
    'validate_storefront_launch',
    'storefront_load',
    'storefront_validate',
    'storefront_save_template',
    'storefront_save_product',
    'storefront_enable_inheritance',
    'storefront_delete_template',
] as $legacyAction) {
    storefront_vnext_api_assert(
        strpos($endpoint, "\$action === '" . $legacyAction . "'") === false,
        'legacy storefront endpoint must be removed: ' . $legacyAction
    );
}

echo "Storefront vNext API checks passed\n";
