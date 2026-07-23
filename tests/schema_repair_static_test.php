<?php

$root = dirname(__DIR__);
$service = file_get_contents($root . '/lib/Install/SchemaRepairService.php');
$diagnosticTool = file_get_contents($root . '/tools/diagnostic.php');
$options = file_get_contents($root . '/options.php');
$include = file_get_contents($root . '/include.php');
$diagnostic = file_get_contents($root . '/lib/Diagnostic/ModuleDiagnostic.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(strpos($service, "'CALC_STAGES'") !== false, 'CALC_STAGES is present in schema registry');
$assert(strpos($service, "'ACTIVATION_CONDITION'") !== false, 'ACTIVATION_CONDITION is repairable');
$assert(substr_count($service, "'SOURCE_LINKS'") === 3, 'SOURCE_LINKS is registered for three iblocks');
$assert(strpos($service, '\\CIBlockProperty::GetList') !== false, 'existing property is checked before creation');
$assert(strpos($service, '$property->Add') !== false, 'missing property is created');
$assert(strpos($service, '$property->Update') === false, 'existing properties are never updated');
$assert(strpos($service, 'Delete(') === false, 'schema repair never deletes data');
$assert(strpos($diagnosticTool, "case 'fix_schema':") !== false, 'diagnostic endpoint exposes fix_schema');
$assert(strpos($options, "pwCalcDiagFix('fix_schema'") !== false, 'module options expose schema repair button');
$assert(strpos($include, 'SchemaRepairService') !== false, 'schema repair service is registered for autoload');
$assert(strpos($diagnostic, 'SchemaRepairService::getPropertySchema()') !== false, 'diagnostic uses repair schema');

echo "OK\n";
