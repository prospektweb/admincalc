<?php

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/tools/control_center_editors.php');
$legacyEndpoint = file_get_contents($root . '/tools/calculator_ajax.php');
$include = file_get_contents($root . '/include.php');
$diagnostic = file_get_contents($root . '/lib/Diagnostic/ModuleDiagnostic.php');
$mappingService = file_get_contents($root . '/lib/Services/CalculatorInputMappingService.php');

$assert(
    strpos($include, "'Prospektweb\\\\Calc\\\\Services\\\\CalculatorInputMappingService' => 'lib/Services/CalculatorInputMappingService.php'") !== false,
    'input mapping service is registered in the Bitrix module autoloader'
);
$assert(
    strpos($mappingService, 'new PresetMutationCoordinatorService()') !== false
        && strpos($mappingService, "'action' => 'calculator_input_mapping_save'") !== false
        && strpos($mappingService, 'saveDirectUnderCoordinatorTransaction(') !== false
        && strpos($mappingService, 'startTransaction()') === false,
    'mapping CAS, semantic validation, readback and audit use the shared preset transaction'
);
$assert(
    strpos($diagnostic, "'lib/Services/CalculatorInputMappingService.php'") !== false,
    'input mapping service participates in integrity diagnostics'
);
$assert(
    strpos($include, "'Prospektweb\\\\Calc\\\\Services\\\\CatalogCalculationScenarioService' => 'lib/Services/CatalogCalculationScenarioService.php'") !== false,
    'catalog scenario runtime is registered in the Bitrix module autoloader'
);
$assert(
    strpos($diagnostic, "'lib/Services/CatalogCalculationScenarioService.php'") !== false,
    'catalog scenario runtime participates in integrity diagnostics'
);

foreach (['calculator_input_mapping_load', 'calculator_input_mapping_validate', 'calculator_input_mapping_save'] as $action) {
    $assert(strpos($endpoint, "\$action === '{$action}'") !== false, $action . ' action is routed by the editors endpoint');
}
$assert(
    substr_count($endpoint, 'new CalculatorInputMappingService()') === 3,
    'load, validate and save use only the new mapping service'
);
$assert(
    strpos($endpoint, "['action', 'sessid', 'preset_id']") !== false,
    'load API accepts exact snake_case keys'
);
$assert(
    strpos($endpoint, "['action', 'sessid', 'mapping']") !== false,
    'validate API accepts the exact mapping document'
);
$assert(
    strpos($endpoint, "['action', 'sessid', 'expected_revision', 'mapping']") !== false,
    'save API exposes exact integer CAS keys'
);
$assert(
    strpos($endpoint, "->validate(\$presetId, \$mapping)") !== false
    && strpos($endpoint, "->save(\n                \$presetId,\n                \$expectedRevision,\n                \$mapping") !== false,
    'validation and save pass one preset-owned mapping aggregate'
);
$assert(
    strpos($legacyEndpoint, 'calculator_input_mapping_') === false,
    'there is one editor authority instead of a duplicate calculator_ajax endpoint'
);

fwrite(STDOUT, "Calculator input mapping API static tests passed\n");
