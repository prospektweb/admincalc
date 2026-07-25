<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$contracts = [
    'calculation-module-v1.schema.json' => 'prospektweb.calc.module/v1',
    'calculation-module-instance-v1.schema.json' => 'prospektweb.calc.module-instance/v1',
    'calculation-resolved-snapshot-v1.schema.json' => 'prospektweb.calc.resolved-snapshot/v1',
];

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

foreach ($contracts as $file => $schemaName) {
    $path = $root . '/contracts/' . $file;
    $raw = file_get_contents($path);
    $assert($raw !== false, "cannot read {$file}");

    $schema = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $assert(
        ($schema['$schema'] ?? '') === 'https://json-schema.org/draft/2020-12/schema',
        "{$file} must use JSON Schema 2020-12"
    );
    $assert(
        ($schema['properties']['schema']['const'] ?? '') === $schemaName,
        "{$file} must declare {$schemaName}"
    );
    $assert(
        strpos($raw, '^[a-f0-9]{64}$') !== false,
        "{$file} must require lowercase SHA-256"
    );
}

$module = file_get_contents($root . '/contracts/calculation-module-v1.schema.json');
$instance = file_get_contents($root . '/contracts/calculation-module-instance-v1.schema.json');
$snapshot = file_get_contents($root . '/contracts/calculation-resolved-snapshot-v1.schema.json');
$adr = file_get_contents($root . '/docs/adr/0001-versioned-reusable-calculation-modules.md');
$inventory = file_get_contents($root . '/docs/legacy-v1-characterization.md');

$assert(
    $module !== false && $instance !== false && $snapshot !== false && $adr !== false && $inventory !== false,
    'contract files are readable'
);
$assert(strpos($module, '"additionalProperties": false') !== false, 'module contract is closed by default');
$assert(strpos($module, '"version": {"const": 1}') !== false, 'legacy formula version stays exact');
$assert(strpos($module, '"number", "string", "boolean"') !== false, 'v1 ports use the supported scalar types');
$assert(strpos($instance, '"legacyElementIds"') !== false, 'legacy IDs are isolated in instance provenance');
$assert(strpos($instance, '"activationCondition"') !== false, 'instance retains activation');
$assert(strpos($instance, '"globalAssignments"') !== false, 'instance retains global assignments');
$assert(strpos($snapshot, '"dependencyLock"') !== false, 'snapshot pins dependencies');
$assert(strpos($snapshot, '"executionOrder"') !== false, 'snapshot freezes execution order');
$assert(strpos($adr, 'RFC 8785 JSON Canonicalization Scheme') !== false, 'ADR fixes canonicalization');
$assert(strpos($adr, 'absence of a module-instance record means legacy v1') !== false, 'ADR preserves legacy fallback');
$assert(strpos($adr, 'preset 4592 / offer 10541') !== false, 'ADR records the confirmed production fixture');
$assert(strpos($inventory, '`OPTIONS_OPERATION`') !== false, 'inventory includes serialized operation mappings');
$assert(strpos($inventory, '`stage_6298`') !== false, 'inventory includes serialized stage IDs');
$assert(strpos($inventory, 'preset: 6297') !== false, 'inventory includes the no-globals offset fixture');
$assert(strpos($inventory, 'binding detail: 1090') !== false, 'inventory includes the nested binding fixture');

echo "Versioned calculation module contract checks passed\n";
