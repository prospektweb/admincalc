<?php

require_once dirname(__DIR__) . '/lib/Config/ConfigManager.php';
require_once dirname(__DIR__) . '/lib/Calculator/InitPayloadService.php';
require_once dirname(__DIR__) . '/lib/Services/CatalogCalculationWriteService.php';

use Prospektweb\Calc\Calculator\InitPayloadService;
use Prospektweb\Calc\Services\CatalogCalculationWriteService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$codes = [
    'CALC_PRESETS',
    'CALC_STAGES',
    'CALC_SETTINGS',
    'CALC_GLOBAL_VALUES',
    'CALC_CUSTOM_FIELDS',
    'CALC_MATERIALS',
    'CALC_MATERIALS_VARIANTS',
    'CALC_OPERATIONS',
    'CALC_OPERATIONS_VARIANTS',
    'CALC_EQUIPMENT',
    'CALC_DETAILS',
];
$snapshot = [
    'prospektweb.frontcalc:PRODUCTS_IBLOCK_ID' => '214',
    'prospektweb.frontcalc:OFFERS_IBLOCK_ID' => '215',
];
$expected = ['PRODUCTS' => 214, 'OFFERS' => 215];
foreach ($codes as $index => $code) {
    $id = 300 + $index;
    $snapshot['prospektweb.frontcalc:IBLOCK_' . $code] = (string)$id;
    $snapshot['prospektweb.calc:IBLOCK_' . $code] = $code === 'CALC_GLOBAL_VALUES'
        ? (string)$id
        : (string)(900 + $index);
    $expected[$code] = $id;
}

$service = new InitPayloadService();
$reflection = new ReflectionClass($service);
$buildMap = $reflection->getMethod('buildPinnedRuntimeIblockMap');
$buildMap->setAccessible(true);
$map = $buildMap->invoke($service, $snapshot);
$assert($map === $expected, 'direct frontcalc option snapshot wins over the conflicting admin option snapshot');
$conflictingGlobals = $snapshot;
$conflictingGlobals['prospektweb.calc:IBLOCK_CALC_GLOBAL_VALUES'] = '999';
try {
    $buildMap->invoke($service, $conflictingGlobals);
    $assert(false, 'different FrontCalc/AdminCalc global registries must fail closed');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'global registry authority conflict is reported as stale configuration');
}
foreach ([
    'prospektweb.frontcalc:IBLOCK_CALC_GLOBAL_VALUES',
    'prospektweb.calc:IBLOCK_CALC_GLOBAL_VALUES',
] as $emptyAuthorityKey) {
    $emptyAuthority = $snapshot;
    $emptyAuthority[$emptyAuthorityKey] = '';
    try {
        $buildMap->invoke($service, $emptyAuthority);
        $assert(false, 'an existing empty global registry authority must fail closed');
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, 'empty global registry authority is invalid, not absent');
    }
}

$resolveGlobals = $reflection->getMethod('resolvePinnedGlobalSymbolIblockId');
$resolveGlobals->setAccessible(true);
$assert(
    $resolveGlobals->invoke($service, $snapshot) === $expected['CALC_GLOBAL_VALUES'],
    'standalone global resolver requires the same direct authority consensus'
);
$initSource = (string)file_get_contents(dirname(__DIR__) . '/lib/Calculator/InitPayloadService.php');
$writerSource = (string)file_get_contents(dirname(__DIR__) . '/lib/Services/CatalogCalculationWriteService.php');
$assert(
    strpos($initSource, "'neutralInputRequired' => true") !== false
        && strpos($initSource, 'parseNeutralInputOption') === false
        && strpos($initSource, 'parseAdapterAuthoringNeutralInputOption') === false,
    'neutral input is an unconditional payload invariant rather than an option gate'
);

$pinnedProperty = $reflection->getProperty('pinnedRuntimeIblockIds');
$pinnedProperty->setAccessible(true);
$pinnedProperty->setValue($service, $map);
$runtimeIblockId = $reflection->getMethod('runtimeIblockId');
$runtimeIblockId->setAccessible(true);
foreach ($expected as $code => $id) {
    $assert(
        $runtimeIblockId->invoke($service, $code) === $id,
        'pinned runtime source ' . $code . ' ignores mutable runtime configuration lookups'
    );
}

$assert(
    method_exists($service, 'preparePresetCalculationPayloadReadOnlyPinned'),
    'catalog calculation exposes a dedicated read-only pinned payload builder'
);

$catalogService = new CatalogCalculationWriteService();
$assert(
    strpos($writerSource, "['_neutralInputRequired'] !== true") !== false,
    'catalog writer requires the exact pinned neutral-input marker'
);
$effectiveIblock = (new ReflectionClass($catalogService))->getMethod('effectiveRuntimeConfigIblockId');
$effectiveIblock->setAccessible(true);
$assert(
    $effectiveIblock->invoke($catalogService, $snapshot, 'CALC_GLOBAL_VALUES')
        === $expected['CALC_GLOBAL_VALUES'],
    'catalog writer uses the same exact global registry consensus'
);
$emptyCatalogAuthority = $snapshot;
$emptyCatalogAuthority['prospektweb.calc:IBLOCK_CALC_GLOBAL_VALUES'] = '';
try {
    $effectiveIblock->invoke($catalogService, $emptyCatalogAuthority, 'CALC_GLOBAL_VALUES');
    $assert(false, 'catalog writer must reject an existing empty global registry authority');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'catalog writer treats empty authority as corruption');
}

echo "Pinned runtime config tests passed\n";
