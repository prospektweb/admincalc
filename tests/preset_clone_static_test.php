<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bundleSource = file_get_contents($root . '/lib/Calculator/BundleHandler.php');
$lifecycleSource = file_get_contents($root . '/lib/Services/PresetLifecycleMutationService.php');
$elementDataSource = file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$integrationSource = file_get_contents($root . '/install/assets/js/integration.js');

if ($bundleSource === false || $lifecycleSource === false || $elementDataSource === false || $integrationSource === false) {
    throw new RuntimeException('Unable to read preset clone sources');
}

$checks = [
    [$lifecycleSource, 'withAuthorityLock(', 'Preset lifecycle must own the source authority transaction'],
    [$lifecycleSource, 'readLockedPresetGraph($sourcePresetId)', 'Preset lifecycle must read source under lock'],
    [$lifecycleSource, 'readLockedPresetGraph($newPresetId)', 'Preset lifecycle must read clone under lock'],
    [$lifecycleSource, 'writeAudit($audit)', 'Preset lifecycle must audit before commit'],
    [$bundleSource, 'remapPresetStageReferences', 'Preset properties must be remapped to cloned stage IDs'],
    [$bundleSource, 'extractHtmlPropertyValueForClone', 'Bitrix HTML property wrappers must be normalized before cloning'],
    [$bundleSource, 'public function clonePresetLocked(int $presetId, array $pinnedIblockIds): int', 'Preset cloning must require pinned source authority'],
    [$elementDataSource, "case 'clonePreset':", 'Refresh endpoint must expose preset cloning'],
    [$elementDataSource, 'PresetLifecycleMutationService())', 'Server cloning must use lifecycle authority'],
    [$elementDataSource, 'preparePresetPayload($newPresetId, $siteId)', 'Clone response must use the product-neutral preset payload'],
    [$elementDataSource, "'initPayload' => \$initPayload", 'Clone response must return confirmed editor state'],
];

foreach ($checks as [$source, $needle, $message]) {
    if (strpos($source, $needle) === false) {
        throw new RuntimeException($message);
    }
}

foreach (['resolveSingleProductIdFromOffers', "'CALC_PRESET' => \$newPresetId", 'CLONE_PRESET_REQUEST'] as $retiredToken) {
    if (strpos($bundleSource . $integrationSource, $retiredToken) !== false) {
        throw new RuntimeException('Preset clone must not retain product assignment or iframe-authoring compatibility: ' . $retiredToken);
    }
}
$checksNoInnerTransaction = ['startTransaction()', 'commitTransaction()', 'rollbackTransaction()'];
foreach ($checksNoInnerTransaction as $retiredToken) {
    if (strpos($bundleSource, $retiredToken) !== false) {
        throw new RuntimeException('BundleHandler must not own clone transaction: ' . $retiredToken);
    }
}

require_once $root . '/lib/Calculator/BundleHandler.php';

$reflection = new ReflectionClass(\Prospektweb\Calc\Calculator\BundleHandler::class);
$handler = $reflection->newInstanceWithoutConstructor();
$htmlPropertyMethod = $reflection->getMethod('extractHtmlPropertyValueForClone');
$htmlPropertyMethod->setAccessible(true);
$method = $reflection->getMethod('remapPresetStageReferences');
$method->setAccessible(true);

$jsonFixture = '{"version":3,"groups":[]}';
$valueWrapped = $htmlPropertyMethod->invoke($handler, [
    'VALUE' => ['TEXT' => $jsonFixture, 'TYPE' => 'HTML'],
    'PROPERTY_TYPE' => 'S',
    'USER_TYPE' => 'HTML',
]);
$rawValueWrapped = $htmlPropertyMethod->invoke($handler, [
    '~VALUE' => ['TEXT' => $jsonFixture, 'TYPE' => 'HTML'],
    'VALUE' => 'escaped fallback',
    'PROPERTY_TYPE' => 'S',
    'USER_TYPE' => 'HTML',
]);

if (($valueWrapped['TEXT'] ?? null) !== $jsonFixture
    || ($valueWrapped['TYPE'] ?? null) !== 'HTML'
    || ($rawValueWrapped['TEXT'] ?? null) !== $jsonFixture
    || ($rawValueWrapped['TYPE'] ?? null) !== 'HTML') {
    throw new RuntimeException('Bitrix VALUE.TEXT and ~VALUE.TEXT HTML property formats must preserve STAGE_GROUPS JSON');
}

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
