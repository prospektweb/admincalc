<?php

require_once dirname(__DIR__) . '/lib/Config/ConfigManager.php';
require_once dirname(__DIR__) . '/lib/Calculator/InitPayloadService.php';

use Prospektweb\Calc\Calculator\InitPayloadService;
use Prospektweb\Calc\Config\ConfigManager;

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
    $snapshot['prospektweb.calc:IBLOCK_' . $code] = (string)(900 + $index);
    $expected[$code] = $id;
}

// Poison the process-static ConfigManager cache with a different source
// topology before the pinned catalog resolver is initialized.
$cacheProperty = (new ReflectionClass(ConfigManager::class))->getProperty('iblockCache');
$cacheProperty->setAccessible(true);
$cacheProperty->setValue(null, array_fill_keys($codes, 777));

$service = new InitPayloadService();
$reflection = new ReflectionClass($service);
$buildMap = $reflection->getMethod('buildPinnedRuntimeIblockMap');
$buildMap->setAccessible(true);
$map = $buildMap->invoke($service, $snapshot);
$assert($map === $expected, 'direct frontcalc option snapshot wins over stale cache and admin fallback');

$pinnedProperty = $reflection->getProperty('pinnedRuntimeIblockIds');
$pinnedProperty->setAccessible(true);
$pinnedProperty->setValue($service, $map);
$runtimeIblockId = $reflection->getMethod('runtimeIblockId');
$runtimeIblockId->setAccessible(true);
foreach ($expected as $code => $id) {
    $assert(
        $runtimeIblockId->invoke($service, $code) === $id,
        'pinned runtime source ' . $code . ' ignores ConfigManager/Option cache'
    );
}

$assert(
    method_exists($service, 'preparePresetCalculationPayloadReadOnlyPinned'),
    'catalog calculation exposes a dedicated read-only pinned payload builder'
);

echo "Pinned runtime config tests passed\n";
