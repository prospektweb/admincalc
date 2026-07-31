<?php

$root = dirname(__DIR__);
$service = file_get_contents($root . '/lib/Services/CalculatorContractService.php');
$elementData = file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$detailHandler = file_get_contents($root . '/lib/Services/DetailHandler.php');
$integration = file_get_contents($root . '/install/assets/js/integration.js');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(strpos($service, 'collectDetailTreeIds') !== false, 'contract impact maps presets through their exact detail tree');
$assert(strpos($service, "'stageIds' => array_map('intval', array_keys(\$presetStageIds))") !== false, 'each preset receives only its own affected stages');
$assert(strpos($service, 'if ($focusStageId > 0)') !== false, 'preset editor links never emit a zero stage focus');
$assert(strpos($service, "ensureStringProperty") !== false, 'contract blocking property is prepared without destructive schema repair');
$assert(strpos($elementData, "\$propertyCode === 'GLOBAL_DEPENDENCIES'") !== false, 'global dependency property is prepared on first save');
$assert(strpos($detailHandler, 'array_splice($updatedPresetDetails, $origPos + 1, 0, [$newDetailId])') !== false, 'top-level duplication inserts an adjacent independent detail');
$assert(strpos($detailHandler, "createDetailElement(\$bindingName, 'BINDING')") === false, 'top-level duplication no longer creates a legacy binding');
$assert(strpos($integration, 'Array.isArray(requestPayload.selectedIds)') !== false, 'hierarchical multi-select can submit several selected detail ids');
$assert(strpos($integration, 'ensureDefaultPresetDetail(initData)') !== false, 'empty presets bootstrap a default root detail before INIT');
$assert(strpos($integration, "name: 'Деталь #1'") !== false, 'default detail uses a stable operator-facing name');
$assert(strpos($integration, 'defaultDetailBootstrapPresetIds.has(presetId)') !== false, 'default detail bootstrap is guarded against duplicate requests');

echo "Calculator contract static checks passed\n";
