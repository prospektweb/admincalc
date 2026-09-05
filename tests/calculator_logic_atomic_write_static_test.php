<?php

declare(strict_types=1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = dirname(__DIR__);
$bridge = (string)file_get_contents($root . '/install/assets/js/integration.js');
$service = (string)file_get_contents($root . '/lib/Calculator/ElementDataService.php');

$fetchStart = strpos($bridge, 'async fetchRefreshData(items)');
$fetchEnd = strpos($bridge, 'authoritativePresetId()', $fetchStart ?: 0);
$fetchSource = $fetchStart !== false && $fetchEnd !== false
    ? substr($bridge, $fetchStart, $fetchEnd - $fetchStart)
    : '';
$assert(
    str_contains($fetchSource, 'items = this.withAuthoritativePreset(items);'),
    'Every refresh-data property write must cross the authoritative preset injector.'
);

$optionsCaseStart = strpos($service, "case 'updateStageProperty':");
$optionsCaseEnd = strpos($service, "case 'inspectCalculatorContract':", $optionsCaseStart ?: 0);
$optionsCase = $optionsCaseStart !== false && $optionsCaseEnd !== false
    ? substr($service, $optionsCaseStart, $optionsCaseEnd - $optionsCaseStart)
    : '';
$assert(
    str_contains($optionsCase, 'StageVariantMappingService::CONTRACT')
        && str_contains($optionsCase, 'StageVariantMappingService::MATERIAL_DECISION_TREE_CONTRACT')
        && str_contains($optionsCase, 'StageVariantMappingService::ENTITY_PARAMETER_SELECTION_CONTRACT')
        && str_contains($optionsCase, 'normalizeMaterialJson(')
        && str_contains($optionsCase, "in_array((\$normalizedMapping['contract'] ?? ''), [")
        && str_contains($optionsCase, "'OPTIONS_MATERIAL' => 'material'")
        && str_contains($optionsCase, "'OPTIONS_OPERATION' => 'operation'")
        && str_contains($optionsCase, "'OPTIONS_EQUIPMENT' => 'equipment'")
        && str_contains($optionsCase, 'contains an incompatible selection target')
        && str_contains($optionsCase, 'assertMaterialDecisionReferences(')
        && str_contains($optionsCase, 'assertDecisionReferencesExist(')
        && str_contains($optionsCase, "if (\$clearDirectSelectionProperty)")
        && str_contains($optionsCase, "\$propertyValues[\$clearDirectSelectionProperty] = false")
        && substr_count($optionsCase, '\\CIBlockElement::SetPropertyValuesEx(') === 1,
    'Saving supported decision trees must validate exact catalog refs and atomically clear their direct selection.'
);
$referenceGuardStart = strpos($service, 'private static function assertMaterialDecisionReferences');
$referenceGuardEnd = strpos($service, 'private function normalizeIds', $referenceGuardStart ?: 0);
$referenceGuard = $referenceGuardStart !== false && $referenceGuardEnd !== false
    ? substr($service, $referenceGuardStart, $referenceGuardEnd - $referenceGuardStart)
    : '';
$assert(
    str_contains($referenceGuard, "'IBLOCK_ID' => \$iblockId, 'ID' => \$entityId")
        && str_contains($referenceGuard, "'PROPERTY_CML2_LINK' => \$entityId")
        && str_contains($referenceGuard, 'PROPERTY_CML2_LINK_VALUE')
        && str_contains($referenceGuard, 'has variants; select a concrete variant')
        && str_contains($referenceGuard, 'is not linked to a material'),
    'Material decision references must belong to pinned catalogs and preserve the parent/variant boundary.'
);
$materialHandlerStart = strpos($bridge, 'async handleChangeOptionsMaterial');
$materialHandlerEnd = strpos($bridge, 'async handleChangeOptionsEquipment', $materialHandlerStart ?: 0);
$materialHandler = $materialHandlerStart !== false && $materialHandlerEnd !== false
    ? substr($bridge, $materialHandlerStart, $materialHandlerEnd - $materialHandlerStart)
    : '';
$assert(
    str_contains($materialHandler, 'if (!responsePayload.initPayload)')
        && str_contains($materialHandler, "responsePayload.clearedPropertyCode === 'MATERIAL_VARIANT'")
        && str_contains($materialHandler, "updateStagePropertyInInitData(stageId, 'MATERIAL_VARIANT', '')"),
    'The bridge must prefer authoritative readback and retain a legacy direct-call fallback.'
);
foreach (['updateStageProperty', 'updateSettingsProperty', 'saveCalcLogic'] as $action) {
    $assert(
        str_contains($bridge, "'" . $action . "',")
            && str_contains($bridge, "guardedActions.has(item.action)"),
        $action . ' must be guarded by the authoritative INIT preset.'
    );
}

$handlerStart = strpos($bridge, 'async handleSaveCalcLogicRequest');
$handlerEnd = strpos($bridge, 'async handleClearOptionsOperation', $handlerStart ?: 0);
$handler = $handlerStart !== false && $handlerEnd !== false
    ? substr($bridge, $handlerStart, $handlerEnd - $handlerStart)
    : '';
$assert(
    substr_count($handler, "action: 'saveCalcLogic'") === 1
        && !str_contains($handler, "action: 'updateStageProperty'")
        && !str_contains($handler, "action: 'updateSettingsProperty'"),
    'SAVE_CALC_LOGIC_REQUEST must emit one atomic server command, not independent property writes.'
);

$caseStart = strpos($service, "case 'saveCalcLogic':");
$caseEnd = strpos($service, "case 'updateStageProperty':", $caseStart ?: 0);
$case = $caseStart !== false && $caseEnd !== false
    ? substr($service, $caseStart, $caseEnd - $caseStart)
    : '';
$assert(
    substr_count($case, 'withAuthorityLock($presetId') === 1
        && substr_count($case, '\\CIBlockElement::SetPropertyValuesEx(') === 2
        && str_contains($case, 'assertSettingsLinkToStage(')
        && str_contains($case, 'assertSettingsLogicWrite(')
        && str_contains($case, 'assertStageInputsWrite('),
    'The atomic server command must validate ownership and write settings plus stage inside one authority transaction.'
);
$firstWrite = strpos($case, '\\CIBlockElement::SetPropertyValuesEx(');
$lastValidation = strpos($case, 'assertPinnedPropertyCodesExist(', strpos($case, 'assertPinnedPropertyCodesExist(') + 1);
$assert(
    $firstWrite !== false && $lastValidation !== false && $lastValidation < $firstWrite,
    'All semantic and property-existence validation must complete before the first atomic write.'
);

echo "Calculator logic atomic write static tests passed\n";
