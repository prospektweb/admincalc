<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Modules/CanonicalJson.php';
require_once dirname(__DIR__) . '/lib/Modules/ModuleValidator.php';
require_once dirname(__DIR__) . '/lib/Modules/LegacyV1MigrationAssistant.php';

use Prospektweb\Calc\Modules\LegacyV1MigrationAssistant;
use Prospektweb\Calc\Modules\ModuleValidator;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$preset = ['id' => 1, 'name' => 'Legacy', 'properties' => ['CALC_DETAILS' => ['100']]];
$store = [
    'CALC_DETAILS' => [
        ['id' => 100, 'name' => 'Group', 'properties' => [
            'TYPE' => ['VALUE_XML_ID' => 'BINDING'],
            'DETAILS' => ['VALUE' => ['102', '101']],
            'CALC_STAGES' => ['VALUE' => ['201']],
        ]],
        ['id' => 101, 'name' => 'Block', 'properties' => [
            'TYPE' => ['VALUE_XML_ID' => 'DETAIL'],
            'CALC_STAGES' => ['VALUE' => ['202']],
        ]],
        ['id' => 102, 'name' => 'Cover', 'properties' => [
            'TYPE' => ['VALUE_XML_ID' => 'DETAIL'],
            'CALC_STAGES' => ['VALUE' => ['203']],
        ]],
    ],
    'CALC_STAGES' => [
        ['id' => 201, 'name' => 'Assembly', 'properties' => ['CALC_SETTINGS' => ['VALUE' => '301']]],
        ['id' => 202, 'name' => 'Block print', 'properties' => ['CALC_SETTINGS' => ['VALUE' => '302']]],
        ['id' => 203, 'name' => 'Cover print', 'properties' => ['CALC_SETTINGS' => ['VALUE' => '302']]],
    ],
];
$analysis = LegacyV1MigrationAssistant::analyzePreset($preset, $store);
$assert(count($analysis['inventory']) === 3, 'legacy inventory must include fragment and both details');
$assert($analysis['inventory'][0]['childLegacyIds'] === [102, 101], 'legacy child order must be preserved');
$shared = array_values(array_filter($analysis['sharedSettings'], static fn(array $row): bool => $row['shared']));
$assert(count($shared) === 1 && $shared[0]['settingsLegacyId'] === 302, 'shared settings must be explicit');
$assert($analysis['automaticWrites'] === false, 'analysis must never mutate legacy data');

$review = [
    'familyId' => 'reviewed_legacy_stage',
    'version' => '1.0.0',
    'kind' => 'stage',
    'name' => 'Reviewed stage',
    'description' => 'Explicitly reviewed migration',
    'content' => [
        'rootNodeId' => 'reviewedStage',
        'nodes' => [[
            'nodeId' => 'reviewedStage',
            'nodeType' => 'stage',
            'order' => 0,
            'name' => 'Reviewed stage',
            'logic' => ['version' => 1, 'vars' => [['name' => 'result', 'formula' => 'quantity * rate']]],
        ]],
    ],
    'ports' => [
        ['code' => 'quantity', 'direction' => 'input', 'valueType' => 'number', 'required' => true],
        ['code' => 'rate', 'direction' => 'global-input', 'valueType' => 'number', 'required' => true],
        ['code' => 'result', 'direction' => 'output', 'valueType' => 'number', 'required' => true],
    ],
    'entityRoles' => [],
    'dependencies' => [],
    'tests' => [[
        'name' => 'reviewed differential case',
        'inputs' => ['quantity' => 2, 'rate' => 3],
        'expectedOutputs' => ['result' => 6],
    ]],
    'createdAt' => '2026-07-25T00:00:00Z',
    'createdBy' => 'test',
];
$preview = LegacyV1MigrationAssistant::buildDraft([
    'presetLegacyId' => 1,
    'stageLegacyIds' => [202],
    'settingsLegacyIds' => [302],
], $review);
$assert(ModuleValidator::validate($preview['module']) === [], 'reviewed draft must satisfy module contract');
$assert($preview['publishAllowed'] === false, 'migration assistant must not publish');
$assert(
    !preg_match('/"legacyId"|"sourcePath"|"localElementIds"|stage_[1-9][0-9]*/', json_encode($preview['module'])),
    'portable draft must not contain live legacy identifiers'
);
$assert($preview['legacyProvenance']['stageLegacyIds'] === [202], 'legacy ids must remain separate provenance');

$missingPortsRejected = false;
try {
    $invalid = $review;
    unset($invalid['ports']);
    LegacyV1MigrationAssistant::buildDraft([], $invalid);
} catch (InvalidArgumentException $error) {
    $missingPortsRejected = str_contains($error->getMessage(), 'ports');
}
$assert($missingPortsRejected, 'assistant must not guess missing ports');

$livePathRejected = false;
try {
    $invalid = $review;
    $invalid['content']['sourcePath'] = 'CALC_SETTINGS.302';
    LegacyV1MigrationAssistant::buildDraft([], $invalid);
} catch (InvalidArgumentException $error) {
    $livePathRejected = str_contains($error->getMessage(), 'sourcePath');
}
$assert($livePathRejected, 'assistant must reject real sourcePath');

$passed = LegacyV1MigrationAssistant::compareResults(['price' => 10.0], ['price' => 10.004], 0.01);
$assert($passed['passed'] === true && $passed['blocksPublication'] === false, 'tolerated differential must pass');
$failed = LegacyV1MigrationAssistant::compareResults(['price' => 10.0], ['price' => 10.02], 0.01);
$assert($failed['passed'] === false && $failed['blocksPublication'] === true, 'failed differential must block publish');

echo "Legacy v1 migration assistant tests passed\n";
