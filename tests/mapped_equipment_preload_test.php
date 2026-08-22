<?php

require_once __DIR__ . '/../lib/Calculator/InitPayloadService.php';
require_once __DIR__ . '/../lib/Services/StageVariantMappingService.php';

use Prospektweb\Calc\Calculator\InitPayloadService;

$service = (new ReflectionClass(InitPayloadService::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(InitPayloadService::class, 'extractMappedVariantIdsFromStages');
$method->setAccessible(true);

$mapping = htmlspecialchars(json_encode([
    'contract' => 'prospektweb.calc.stage-variant-mapping/v1',
    'input_field_ids' => ['method'],
    'metric_source' => null,
    'metric_keys' => [],
    'rules' => [
        ['input_values' => ['method' => 'digital'], 'metric_ranges' => new stdClass(), 'variant_id' => 1083],
        ['input_values' => ['method' => 'offset'], 'metric_ranges' => new stdClass(), 'variant_id' => 1085],
        ['input_values' => ['method' => 'other'], 'metric_ranges' => new stdClass(), 'variant_id' => 1085],
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

$legacyIds = $method->invoke($service, [[
    'properties' => [
        'OPTIONS_EQUIPMENT' => ['VALUE' => json_encode([
            'offerPropertyCodes' => ['CALC_METHOD'],
            'mappings' => [['xmlId' => 'DIGITAL', 'variantId' => 1083]],
        ])],
    ],
]], 'OPTIONS_EQUIPMENT');
if ($legacyIds !== []) {
    throw new RuntimeException('Removed product/offer-property mapping documents must not be interpreted');
}

echo "Mapped equipment preload tests passed\n";
