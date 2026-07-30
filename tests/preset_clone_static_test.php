<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bundleSource = file_get_contents($root . '/lib/Calculator/BundleHandler.php');
$elementDataSource = file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$integrationSource = file_get_contents($root . '/install/assets/js/integration.js');

if ($bundleSource === false || $elementDataSource === false || $integrationSource === false) {
    throw new RuntimeException('Unable to read preset clone sources');
}

$checks = [
    [$bundleSource, 'startTransaction()', 'Preset clone must start a transaction'],
    [$bundleSource, 'commitTransaction()', 'Preset clone must commit only a complete graph'],
    [$bundleSource, 'rollbackTransaction()', 'Preset clone must roll back an incomplete graph'],
    [$bundleSource, 'remapPresetStageReferences', 'Preset properties must be remapped to cloned stage IDs'],
    [$bundleSource, "'CALC_PRESET' => \$newPresetId", 'The clone must be assigned to the current product'],
    [$bundleSource, 'resolveSingleProductIdFromOffers', 'All selected offers must resolve to one product'],
    [$bundleSource, 'getElementLinkPropertyId', 'The persisted product assignment must be verified'],
    [$elementDataSource, "case 'clonePreset':", 'Refresh endpoint must expose preset cloning'],
    [$elementDataSource, "'initPayload' => \$initPayload", 'Clone response must return confirmed editor state'],
    [$integrationSource, "case 'CLONE_PRESET_REQUEST':", 'Iframe bridge must route preset clone requests'],
    [$integrationSource, "action: 'clonePreset'", 'Iframe bridge must invoke the atomic server action'],
    [$integrationSource, "this.sendPwrtMessage('INIT'", 'Successful cloning must replace the current editor state'],
];

foreach ($checks as [$source, $needle, $message]) {
    if (strpos($source, $needle) === false) {
        throw new RuntimeException($message);
    }
}

require_once $root . '/lib/Calculator/BundleHandler.php';

$reflection = new ReflectionClass(\Prospektweb\Calc\Calculator\BundleHandler::class);
$handler = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('remapPresetStageReferences');
$method->setAccessible(true);

$groups = [
    'version' => 3,
    'groups' => [[
        'id' => 'condition_1',
        'stageIds' => [10, 20],
        'branches' => [
            ['id' => 'a', 'stageIds' => [10]],
            ['id' => 'else', 'stageIds' => [20]],
        ],
    ]],
];
$properties = [
    'STAGE_GROUPS' => [
        'TEXT' => json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'TYPE' => 'TEXT',
    ],
    'GLOBAL_VARIABLES' => [[
        'VALUE' => 'get(stage_10, "result") + get(stage_20, "result")',
        'DESCRIPTION' => 'stage_10|source',
    ]],
];

$remapped = $method->invoke($handler, $properties, [10 => 110, 20 => 220]);
$remappedGroups = json_decode((string)$remapped['STAGE_GROUPS']['TEXT'], true, 512, JSON_THROW_ON_ERROR);

if (($remappedGroups['groups'][0]['stageIds'] ?? null) !== [110, 220]
    || ($remappedGroups['groups'][0]['branches'][0]['stageIds'] ?? null) !== [110]
    || ($remappedGroups['groups'][0]['branches'][1]['stageIds'] ?? null) !== [220]) {
    throw new RuntimeException('Condition groups and branches must point only to cloned stages');
}

$formula = (string)($remapped['GLOBAL_VARIABLES'][0]['VALUE'] ?? '');
$description = (string)($remapped['GLOBAL_VARIABLES'][0]['DESCRIPTION'] ?? '');
if (strpos($formula, 'stage_110') === false
    || strpos($formula, 'stage_220') === false
    || strpos($description, 'stage_110') === false
    || preg_match('/stage_(10|20)(?!\d)/', $formula . ' ' . $description)) {
    throw new RuntimeException('Preset formulas must point only to cloned stages');
}

echo "preset_clone_static_test: OK\n";
