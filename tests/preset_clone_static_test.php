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
    [$bundleSource, 'cloneSettingsElement', 'Preset cloning must create independent calculator settings'],
    [$bundleSource, "\$propValues['CALC_SETTINGS']", 'Every cloned stage must point to cloned settings'],
    [$bundleSource, 'remapSettingsStageReferences', 'Cloned settings formulas must point to cloned stage IDs'],
    [$bundleSource, 'assertSettingsCloneReadBack', 'Cloned settings fields and properties must be authoritatively verified before commit'],
    [$bundleSource, 'Cloned calculator settings properties differ from the source.', 'A partial settings property write must fail the whole clone'],
    [$bundleSource, 'extractHtmlPropertyValueForClone', 'Bitrix HTML property wrappers must be normalized before cloning'],
    [$bundleSource, 'public function clonePresetLocked(int $presetId, array $pinnedIblockIds): int', 'Preset cloning must require pinned source authority'],
    [$bundleSource, "'IBLOCK_SECTION_ID' => (int)(\$original['IBLOCK_SECTION_ID'] ?? 0) > 0", 'Preset clone must preserve its calculator catalog section'],
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
$normalizeHtmlMarkersMethod = $reflection->getMethod('normalizeHtmlPropertyMarkersForComparison');
$normalizeHtmlMarkersMethod->setAccessible(true);
$collectChangedPropertiesMethod = $reflection->getMethod('collectChangedPropertyValues');
$collectChangedPropertiesMethod->setAccessible(true);
$omitEmptyHtmlPropertiesMethod = $reflection->getMethod('omitEmptyHtmlPropertyValues');
$omitEmptyHtmlPropertiesMethod->setAccessible(true);
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
$legacyTextWrapped = $htmlPropertyMethod->invoke($handler, [
    'VALUE' => ['TEXT' => $jsonFixture, 'TYPE' => 'text'],
    'PROPERTY_TYPE' => 'S',
    'USER_TYPE' => 'HTML',
]);

if (($valueWrapped['TEXT'] ?? null) !== $jsonFixture
    || ($valueWrapped['TYPE'] ?? null) !== 'HTML'
    || ($rawValueWrapped['TEXT'] ?? null) !== $jsonFixture
    || ($rawValueWrapped['TYPE'] ?? null) !== 'HTML'
    || ($legacyTextWrapped['TEXT'] ?? null) !== $jsonFixture
    || ($legacyTextWrapped['TYPE'] ?? null) !== 'text') {
    throw new RuntimeException('Bitrix HTML property wrappers must preserve their exact write representation');
}

$legacyProperties = [
    'AI_CONTEXT_JSON' => ['TEXT' => '', 'TYPE' => 'text'],
    'LOGIC_JSON' => ['TEXT' => '{"formula":"stage_10"}', 'TYPE' => 'TEXT'],
];
$bitrixReadBack = [
    'AI_CONTEXT_JSON' => ['TEXT' => '', 'TYPE' => 'HTML'],
    'LOGIC_JSON' => ['TEXT' => '{"formula":"stage_10"}', 'TYPE' => 'HTML'],
];
$corruptReadBack = $bitrixReadBack;
$corruptReadBack['AI_CONTEXT_JSON']['TEXT'] = 'HTML';
if ($normalizeHtmlMarkersMethod->invoke($handler, $legacyProperties)
        !== $normalizeHtmlMarkersMethod->invoke($handler, $bitrixReadBack)
    || $normalizeHtmlMarkersMethod->invoke($handler, $legacyProperties)
        === $normalizeHtmlMarkersMethod->invoke($handler, $corruptReadBack)) {
    throw new RuntimeException('HTML marker normalization must ignore only TYPE storage drift, never payload drift');
}

$mappedProperties = $legacyProperties;
$mappedProperties['LOGIC_JSON']['TEXT'] = '{"formula":"stage_110"}';
$changedProperties = $collectChangedPropertiesMethod->invoke($handler, $legacyProperties, $mappedProperties);
if (array_keys($changedProperties) !== ['LOGIC_JSON']
    || ($changedProperties['LOGIC_JSON']['TEXT'] ?? null) !== '{"formula":"stage_110"}') {
    throw new RuntimeException('Settings remap must write only properties whose stage references changed');
}

$cloneWriteProperties = $omitEmptyHtmlPropertiesMethod->invoke($handler, $legacyProperties);
if (array_keys($cloneWriteProperties) !== ['LOGIC_JSON']
    || ($cloneWriteProperties['LOGIC_JSON']['TEXT'] ?? null) !== '{"formula":"stage_10"}') {
    throw new RuntimeException('Settings clone must omit only empty HTML wrappers that Bitrix corrupts on write');
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
