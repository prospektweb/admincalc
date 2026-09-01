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
$assert(strpos($service, 'GLOBAL_ASSIGNMENTS') === false, 'retired stage assignment storage is not recreated');
$assert(strpos($service, "'USED_ENTITYS'") !== false, 'stage-owned USED_ENTITYS is repairable');
$assert(strpos($service, "'USED_ENTITY_CODES'") !== false, 'stable stage entity codes are repairable');
$assert(strpos($service, "'CUSTOM_FIELDS'") !== false, 'stage-owned CUSTOM_FIELDS is repairable');
$assert(strpos($service, 'migrateLegacyStageOwnership') === false, 'legacy stage ownership is never migrated by a runtime repair');
$assert(strpos($service, 'repairMissingProperties') === false, 'schema registry has no incremental runtime writer');
$assert(strpos($service, 'ensureOfferNamingAndMarginSchema') === false, 'schema registry has no hidden runtime currency/property writer');
$assert(substr_count($service, "'SOURCE_LINKS'") === 4, 'SOURCE_LINKS is registered for materials, variants, equipment and suppliers');
$assert(strpos($service, '\\CIBlockProperty::') === false, 'read-only schema registry never mutates Bitrix properties');
$assert(strpos($diagnosticTool, "case 'fix_schema':") === false, 'diagnostic endpoint is read-only for schema state');
$assert(strpos($diagnosticTool, "case 'fix_files':") === false, 'diagnostic endpoint cannot overwrite installed files');
$assert(strpos($options, "pwCalcDiagFix('fix_schema'") === false, 'module options have no runtime schema repair button');
$assert(strpos($include, 'SchemaRepairService') !== false, 'schema repair service is registered for autoload');
$assert(strpos($diagnostic, 'SchemaRepairService::getPropertySchema()') !== false, 'diagnostic uses repair schema');
$assert(
    strpos($diagnostic, 'IBLOCK_EXPECTED_TYPES') !== false
        && substr_count($diagnostic, "'CALC_MATERIALS_VARIANTS' => 'calculator_catalog'") === 1
        && strpos($diagnostic, "'label' => \$code . ' (TYPE)'") !== false,
    'diagnostic permanently detects material iblock type drift'
);

echo "OK\n";
