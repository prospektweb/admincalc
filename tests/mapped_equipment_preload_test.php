<?php

require_once __DIR__ . '/../lib/Calculator/InitPayloadService.php';

use Prospektweb\Calc\Calculator\InitPayloadService;

$service = (new ReflectionClass(InitPayloadService::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(InitPayloadService::class, 'extractMappedVariantIdsFromStages');
$method->setAccessible(true);

$mapping = htmlspecialchars(json_encode([
    'offerPropertyCodes' => [],
    'productPropertyCodes' => ['CALC_METHOD'],
    'mappings' => [
        ['productValues' => ['CALC_METHOD' => ['xmlId' => 'DIGITAL']], 'variantId' => 1083],
        ['productValues' => ['CALC_METHOD' => ['xmlId' => 'OFSET']], 'variantId' => 1085],
        ['variantId' => 1085],
    ],
], JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_HTML5, 'UTF-8');

$ids = $method->invoke($service, [[
    'properties' => [
        'OPTIONS_EQUIPMENT' => ['~VALUE' => ['TEXT' => $mapping]],
    ],
]], 'OPTIONS_EQUIPMENT');

if ($ids !== [1083, 1085]) {
    throw new RuntimeException('Dynamic equipment mapping candidates must be preloaded once each');
}

$invalidIds = $method->invoke($service, [[
    'properties' => [
        'OPTIONS_EQUIPMENT' => ['VALUE' => '{invalid json'],
    ],
]], 'OPTIONS_EQUIPMENT');

if ($invalidIds !== []) {
    throw new RuntimeException('Invalid mapping JSON must not add preload candidates');
}

echo "Mapped equipment preload tests passed\n";
