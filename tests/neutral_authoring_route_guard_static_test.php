<?php

declare(strict_types=1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$root = dirname(__DIR__);
$elementSource = file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$bundleSource = file_get_contents($root . '/lib/Calculator/BundleHandler.php');
$ajaxSource = file_get_contents($root . '/tools/calculator_ajax.php');
$enrichmentSource = file_get_contents($root . '/lib/Services/PresetEnrichmentService.php');
$assert(
    is_string($elementSource)
        && is_string($bundleSource)
        && is_string($ajaxSource)
        && is_string($enrichmentSource),
    'sources are readable'
);

$caseSlice = static function (string $source, string $case, string $nextCase): string {
    $start = strpos($source, "case '{$case}':");
    $end = strpos($source, "case '{$nextCase}':", is_int($start) ? $start + 1 : 0);
    return is_int($start) && is_int($end) ? substr($source, $start, $end - $start) : '';
};
$before = static function (string $source, string $guard, string $write): bool {
    $guardOffset = strpos($source, $guard);
    $writeOffset = strpos($source, $write);
    return is_int($guardOffset) && is_int($writeOffset) && $guardOffset < $writeOffset;
};

$guardedDetailCases = [
    ['addNewDetail', 'cloneDetail', 'assertStructuralMutationAllowed(', '->addDetail('],
    ['changeProductType', 'addNewGroup', 'assertStructuralMutationAllowed(', '->changeProductType('],
    ['addNewStage', 'addStage', 'assertStructuralMutationAllowed(', '->addStage('],
    ['addStage', 'duplicateStage', 'assertStructuralMutationAllowed(', '->addStage('],
    ['removeDetail', 'renameDetail', 'assertStructuralMutationAllowed(', '->removeDetail'],
    ['deleteDetail', 'changeNameDetail', 'assertStructuralMutationAllowed(', '->deleteDetail('],
    ['addDetailToBinding', 'changeDetailSort', 'assertStructuralMutationAllowed(', '->addDetailToBinding('],
];
foreach ($guardedDetailCases as [$case, $nextCase, $guard, $write]) {
    $slice = $caseSlice($elementSource, $case, $nextCase);
    $assert($slice !== '' && $before($slice, $guard, $write), $case . ' validates actual ownership before DML');
    $assert(strpos($slice, 'withActiveAuthorityLock(') !== false, $case . ' keeps validation and DML in one authority transaction');
}
$changeProductType = $caseSlice($elementSource, 'changeProductType', 'addNewGroup');
$assert(
    $before($changeProductType, 'presetRootDetailIds($presetId)', '->changeProductType(')
        && $before($changeProductType, 'assertDetailDeletionCascadeAllowed(', '->changeProductType('),
    'changeProductType derives the actual preset topology and validates destructive cascades before DML'
);
$removeDetail = $caseSlice($elementSource, 'removeDetail', 'renameDetail');
$deleteDetail = $caseSlice($elementSource, 'deleteDetail', 'changeNameDetail');
$assert(
    $before($removeDetail, 'assertDetailDeletionCascadeAllowed(', '->removeDetail')
        && $before($deleteDetail, 'assertDetailDeletionCascadeAllowed(', '->deleteDetail('),
    'detail removals reject shared neutral descendants and stages before physical deletion'
);

$deleteStage = $caseSlice($elementSource, 'deleteStage', 'removeDetail');
$assert(
    $before($deleteStage, 'assertStageStructuralMutationAllowed(', '\CIBlockElement::Delete($stageId)')
        && strpos($deleteStage, 'withActiveAuthorityLock(') !== false,
    'deleteStage validates actual neutral-stage ownership before transactional deletion'
);

$duplicateStage = $caseSlice($elementSource, 'duplicateStage', 'deleteStage');
$assert(
    $before($duplicateStage, 'assertStructuralMutationAllowed(', '->duplicateStage(')
        && $before($duplicateStage, 'assertStageStructuralMutationAllowed(', '->duplicateStage('),
    'duplicateStage validates both target-detail and source-stage ownership before cloning'
);

$saveEquipment = $caseSlice($elementSource, 'saveSettingsEquipment', 'changeStageName');
$assert(
    $before($saveEquipment, 'self::assertPinnedElementExists(', '$element->Update($equipmentId')
        && strpos($saveEquipment, "'equipment'") !== false,
    'existing equipment writes require exact equipment-iblock membership'
);

$createStart = strpos($bundleSource, 'public function createPreset(');
$cloneStart = strpos($bundleSource, 'public function clonePreset(');
$createSource = is_int($createStart) && is_int($cloneStart)
    ? substr($bundleSource, $createStart, $cloneStart - $createStart)
    : '';
$assert(
    $createSource !== ''
        && strpos($createSource, '?int $pinnedPresetsIblockId = null') !== false
        && $before($createSource, 'resolveSingleProductIdFromOffers($offerIds)', '$el->Add(')
        && $before($createSource, '$existingPresetId > 0', '$el->Add(')
        && strpos($createSource, 'automatic replacement is forbidden') !== false
        && strpos($createSource, 'Preset creation requires the locked neutral authority') !== false
        && $before($createSource, 'SetPropertyValuesEx(', 'Preset binding readback mismatch'),
    'preset creation is pinned, all-offer/single-product, empty-binding-only and read-back verified'
);

$createRouteStart = strpos($ajaxSource, 'function handleCreateAndAssignPreset(');
$saveHandlerStart = strpos($ajaxSource, 'function handleSave(');
$createRoute = is_int($createRouteStart) && is_int($saveHandlerStart)
    ? substr($ajaxSource, $createRouteStart, $saveHandlerStart - $createRouteStart)
    : '';
$assert(
    $createRoute !== ''
        && $before($createRoute, 'withActiveAuthorityLock(', '->createPreset(')
        && $before($createRoute, 'if ($protected)', '->createPreset(')
        && strpos($createRoute, 'Automatic preset creation is disabled after preset 12740 neutral migration begins.') !== false
        && strpos($createRoute, "\$pinnedIblockIds['CALC_PRESETS']") !== false,
    'createAndAssignPreset holds neutral authority through binding validation and creation'
);
$constructorStart = strpos($enrichmentSource, 'public function __construct(?array $pinnedIblockIds = null)');
$enrichStart = strpos($enrichmentSource, 'public function enrichPresetFromDetails(');
$constructorSource = is_int($constructorStart) && is_int($enrichStart)
    ? substr($enrichmentSource, $constructorStart, $enrichStart - $constructorStart)
    : '';
$assert(
    $constructorSource !== ''
        && strpos($constructorSource, "\$pinnedIblockIds['CALC_OPERATIONS_VARIANTS']") !== false
        && strpos($constructorSource, "\$pinnedIblockIds['CALC_MATERIALS_VARIANTS']") !== false
        && $before($constructorSource, '} else {', 'new ConfigManager()'),
    'pinned enrichment resolves executable resource iblocks from locked authority, not ConfigManager cache'
);
$assert(
    strpos($ajaxSource, "case 'save':\n            throw new \\RuntimeException") !== false
        && strpos($ajaxSource, "case 'saveBundle':\n            throw new \\RuntimeException") !== false
        && strpos($ajaxSource, "case 'finalizeBundle':\n            throw new \\RuntimeException") !== false,
    'unimplemented legacy save endpoints fail explicitly before handler dispatch'
);

fwrite(STDOUT, "OK\n");
