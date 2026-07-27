<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$detailHandler = file_get_contents($root . '/lib/Services/DetailHandler.php');
$elementDataService = file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$integration = file_get_contents($root . '/install/assets/js/integration.js');

if ($detailHandler === false || $elementDataService === false || $integration === false) {
    throw new RuntimeException('Unable to read detail lifecycle sources');
}

$slice = static function (string $source, string $startNeedle, string $endNeedle): string {
    $start = strpos($source, $startNeedle);
    $end = strpos($source, $endNeedle, $start !== false ? $start : 0);

    return $start !== false && $end !== false
        ? substr($source, $start, $end - $start)
        : '';
};

$addDetail = $slice(
    $detailHandler,
    'public function addDetail(array $data): array',
    'public function cloneDetail(array $data): array'
);
if (
    $addDetail === ''
    || strpos($addDetail, "'config' => null") === false
    || strpos($addDetail, 'createConfigElement(') !== false
    || strpos($addDetail, 'linkConfigToDetail(') !== false
) {
    throw new RuntimeException('A new detail must be stored without an implicit stage');
}

$addDetailToBinding = $slice(
    $detailHandler,
    "public function addDetailToBinding(int \$parentId, string \$name = ''): array",
    'public function addDetailsToBinding('
);
if (
    $addDetailToBinding === ''
    || strpos($addDetailToBinding, "'config' => null") === false
    || strpos($addDetailToBinding, 'createConfigElement(') !== false
    || strpos($addDetailToBinding, 'linkConfigToDetail(') !== false
) {
    throw new RuntimeException('A bound detail must be stored without an implicit stage');
}

$removeFromBinding = $slice(
    $detailHandler,
    'public function removeDetailFromBinding(',
    'private function deleteDetailPhysically('
);
if (
    $removeFromBinding === ''
    || strpos($removeFromBinding, 'in_array($parentId, $presetDetails, true)') === false
    || strpos($removeFromBinding, "'code' => 'product_type_change_required'") === false
    || strpos($removeFromBinding, "'productDetailCount' => count(\$parent['DETAIL_IDS'])") === false
    || strpos($removeFromBinding, '$this->setPresetDetails($presetId, $updatedPresetDetails)') === false
    || strpos($removeFromBinding, '$this->deleteDetailPhysically($detailId)') === false
) {
    throw new RuntimeException('Bound detail deletion must remove its stages and repair every root position');
}

$removeTopLevel = $slice(
    $detailHandler,
    'public function removeTopLevelDetail(',
    'public function changeName('
);
if (
    $removeTopLevel === ''
    || strpos($removeTopLevel, "\$workingDetailCount = max(0, count(\$rootDetailIds) - 1)") === false
    || strpos($removeTopLevel, "'code' => 'product_type_change_required'") === false
    || strpos($removeTopLevel, "'productDetailCount' => \$workingDetailCount") === false
) {
    throw new RuntimeException('Top-level detail deletion must protect a two-detail complex product');
}

$cloneAction = $slice($elementDataService, "case 'cloneDetail':", "case 'changeProductType':");
if (
    $cloneAction === ''
    || strpos($cloneAction, 'enrichPresetFromProductRoots') === false
    || strpos($cloneAction, "'initPayload'") === false
) {
    throw new RuntimeException('Cloning must atomically return the complete updated topology');
}

$cloneDetail = $slice(
    $detailHandler,
    'public function cloneDetail(array $data): array',
    'public function addGroup(array $data): array'
);
if (
    $cloneDetail === ''
    || strpos($cloneDetail, "['DETAILS' => false]") !== false
    || strpos($cloneDetail, "['CALC_DETAILS' => false]") !== false
) {
    throw new RuntimeException('Cloning must not publish an intermediate empty topology before INIT');
}

$cloneRecursive = $slice(
    $detailHandler,
    'private function cloneDetailRecursive(',
    'private function cloneConfig('
);
if (
    $cloneRecursive === ''
    || strpos($cloneRecursive, "unset(\$propertyValues['TYPE'], \$propertyValues['CALC_STAGES'], \$propertyValues['DETAILS'])") === false
    || strpos($cloneRecursive, "\$propertyValues['TYPE'] = \$this->resolveDetailTypePropertyValue") !== false
    || strpos($cloneRecursive, "if (\$newConfigIds !== [])") === false
    || strpos($cloneRecursive, "if (\$newDetailIds !== [])") === false
    || strpos($cloneRecursive, "\$propertyValues['TYPE'] = ['VALUE'") !== false
) {
    throw new RuntimeException('A clone must rebuild topology fields using native Bitrix property value shapes');
}

if (
    strpos($cloneDetail, "array_merge(\n                    array_map('intval', \$presetDetails),\n                    array_map('intval', \$createdDetailIds)") === false
    || strpos($cloneDetail, 'CALC_DETAILS also acts as the preload list for elementsStore') === false
) {
    throw new RuntimeException('Every cloned detail node must be included in the CALC_DETAILS preload list');
}

$cloneBridge = $slice(
    $integration,
    'async handleCloneDetailRequest',
    'async handleSaveSettingsEquipmentRequest'
);
if (
    $cloneBridge === ''
    || strpos($cloneBridge, 'responsePayload.initPayload') === false
    || strpos($cloneBridge, 'this.enrichPreset(') !== false
) {
    throw new RuntimeException('The browser must not re-enrich a clone through the legacy flow');
}

$removeAction = $slice($elementDataService, "case 'removeDetail':", "case 'renameDetail':");
if (
    $removeAction === ''
    || strpos($removeAction, 'removeTopLevelDetail') === false
    || strpos($removeAction, 'getPresetRootDetailIds') === false
    || strpos($removeAction, 'enrichPresetFromProductRoots') === false
    || strpos($removeAction, 'enrichPresetFromDetails') !== false
) {
    throw new RuntimeException('Deletion must support root columns and preserve remaining roots');
}

$removeBridge = $slice(
    $integration,
    'async handleRemoveDetailRequest',
    'async handleRenameDetailRequest'
);
if (
    $removeBridge === ''
    || strpos($removeBridge, 'if (detailId <= 0)') === false
    || strpos($removeBridge, 'parentId <= 0 || detailId <= 0') !== false
) {
    throw new RuntimeException('A top-level deletion request must not require a parent binding');
}

echo "Detail lifecycle static tests passed\n";
