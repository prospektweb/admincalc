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

$assert(
    !str_contains($service, 'array $authority'),
    'withAuthorityLock callbacks must not type the authority service object as an array'
);
$assert(
    str_contains($service, "(int)(\$pinnedIblockIds['CALC_GLOBAL_VALUES'] ?? 0)"),
    'stage activation writes must use the pinned global values iblock ID'
);

$slice = static function (string $source, string $start, string $end): string {
    $from = strpos($source, $start);
    $to = $from !== false ? strpos($source, $end, $from + strlen($start)) : false;
    return $from !== false && $to !== false ? substr($source, $from, $to - $from) : '';
};

$serverCases = [
    'changeOperationVariant' => ['OPERATION_VARIANT', 'OPTIONS_OPERATION', "case 'changeEquipment':"],
    'changeEquipment' => ['EQUIPMENT', 'OPTIONS_EQUIPMENT', "case 'changeMaterialVariant':"],
    'changeMaterialVariant' => ['MATERIAL_VARIANT', 'OPTIONS_MATERIAL', "case 'savePresetGlobals':"],
];
foreach ($serverCases as $action => [$selectionCode, $mappingCode, $endMarker]) {
    $case = $slice($service, "case '" . $action . "':", $endMarker);
    $assert($case !== '', $action . ' server case must exist');
    $assert(
        substr_count($case, '\\CIBlockElement::SetPropertyValuesEx(') === 1
            && str_contains($case, "'" . $selectionCode . "' =>")
            && str_contains($case, "'" . $mappingCode . "' => false")
            && str_contains($case, "['" . $selectionCode . "', '" . $mappingCode . "']"),
        $action . ' must save the selection and clear its mapping in one property write'
    );
}

$clientHandlers = [
    'handleChangeOperationVariantRequest' => 'handleChangeEquipmentRequest',
    'handleChangeEquipmentRequest' => 'handleChangeMaterialVariantRequest',
    'handleChangeMaterialVariantRequest' => 'handleChangeCustomFieldsValue',
];
foreach ($clientHandlers as $handlerName => $endHandlerName) {
    $handler = $slice($bridge, 'async ' . $handlerName, 'async ' . $endHandlerName);
    $assert($handler !== '', $handlerName . ' client handler must exist');
    $assert(
        substr_count($handler, 'this.fetchRefreshData([') === 1
            && !str_contains($handler, "action: 'updateStageProperty'"),
        $handlerName . ' must not advance the semantic revision with a second mapping-clear request'
    );
    $assert(
        str_contains($handler, 'this.applySemanticReadback(responsePayload)'),
        $handlerName . ' must reconcile the version-aware authoritative response without a page reload'
    );
}

foreach ([
    'handleClearOptionsOperation' => 'handleClearOptionsMaterial',
    'handleClearOptionsMaterial' => 'handleClearOptionsEquipment',
    'handleClearOptionsEquipment' => 'handleChangeLogic',
] as $handlerName => $endHandlerName) {
    $handler = $slice($bridge, 'async ' . $handlerName, 'async ' . $endHandlerName);
    $assert(
        substr_count($handler, "this.sendPwrtMessage('ERROR'") >= 2,
        $handlerName . ' must return correlated errors for an invalid request and a rejected server mutation'
    );
    $assert(
        str_contains($handler, "responsePayload.status !== 'ok'")
            && str_contains($handler, 'this.applySemanticReadback(responsePayload)'),
        $handlerName . ' must verify the server result and reconcile the authoritative reset before success'
    );
}

$assert(
    str_contains($bridge, "stage.properties[propertyCode]['~VALUE'] = value"),
    'local stage-property reconciliation must update the raw Bitrix value preferred by the React transformer'
);

$assert(
    str_contains($service, 'bool $deferInitPayloadToSemanticReadback = false')
        && str_contains($service, 'rebuildPresetIndexesFromRoots'),
    'version working-preset selections must defer public INIT construction to the semantic readback boundary'
);

$duplicateStage = $slice(
    $service,
    "case 'duplicateStage':",
    "case 'deleteStage':"
);
$assert(
    $duplicateStage !== ''
        && str_contains($duplicateStage, 'return $this->completeStructuralMutationPinned(')
        && !str_contains($duplicateStage, 'return self::enrichStructuralResultPinned('),
    'stage duplication in a version working preset must defer public INIT to version-aware semantic readback'
);

$addStage = $slice(
    $service,
    "case 'addStage':",
    "case 'duplicateStage':"
);
$assert(
    $addStage !== ''
        && str_contains($addStage, 'return $this->completeStructuralMutationPinned(')
        && !str_contains($addStage, 'return self::enrichStructuralResultPinned('),
    'stage creation in a version working preset must defer public INIT to version-aware semantic readback'
);

$updateStageProperty = $slice(
    $service,
    "case 'updateStageProperty':",
    "case 'inspectCalculatorContract':"
);
$assert(
    $updateStageProperty !== ''
        && str_contains($updateStageProperty, "\$propertyCode => \$value === '' ? false : \$value"),
    'an empty OPTIONS_* value must delete the Bitrix property instead of persisting an empty string'
);

echo "Stage variant selection atomicity static tests passed\n";
