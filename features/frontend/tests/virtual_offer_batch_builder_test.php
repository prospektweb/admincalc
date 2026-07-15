<?php

require_once __DIR__ . '/../lib/Service/VirtualOfferBatchBuilder.php';

use Prospektweb\Frontcalc\Service\VirtualOfferBatchBuilder;

function assert_same_value($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function offer_volumes(array $offers): array
{
    $volumes = [];
    foreach ($offers as $offer) {
        $volumes[] = (int)($offer['properties']['CALC_PROP_VOLUME']['VALUE_XML_ID'] ?? 0);
    }
    sort($volumes, SORT_NUMERIC);
    return $volumes;
}

function offer_keys(array $offers): array
{
    $keys = [];
    foreach ($offers as $offer) {
        $props = $offer['properties'];
        $keys[] = ($props['CALC_PROP_FORMAT']['VALUE_XML_ID'] ?? '') . '+' . ($props['CALC_PROP_VOLUME']['VALUE_XML_ID'] ?? '');
    }
    sort($keys);
    return $keys;
}

$builder = new VirtualOfferBatchBuilder();

$baseConfig = [
    'fields' => [
        [
            'property_code' => 'CALC_PROP_FORMAT',
            'presets' => [['value' => 'A4', 'xml_id' => 'A4', 'sort' => 100]],
        ],
        [
            'property_code' => 'CALC_PROP_VOLUME',
            'min' => 100,
            'max' => 1000,
            'reference_volumes' => ['base' => ['100', '500', '1000']],
        ],
    ],
];
$offers = $builder->build($baseConfig, [], [], [], 10, 20);
assert_same_value([100, 500, 1000], offer_volumes($offers), 'Base reference volumes should be used without area dependency');
assert_same_value([-100003, -100002, -100001], array_values(array_map(static fn($offer) => $offer['id'], array_reverse($offers))), 'Virtual IDs should be unique and negative');


$inputBoundsConfig = [
    'fields' => [
        [
            'property_code' => 'CALC_PROP_FORMAT',
            'presets' => [['value' => 'A4', 'xml_id' => 'A4', 'sort' => 100]],
        ],
        [
            'property_code' => 'CALC_PROP_VOLUME',
            'inputs' => [[
                'min' => '100',
                'max' => '1000',
                'step' => '100',
            ]],
            'reference_volumes' => ['base' => []],
        ],
    ],
];
$offers = $builder->build($inputBoundsConfig, [], [], [], 10, 20);
assert_same_value([100, 1000], offer_volumes($offers), 'Input min/max should create virtual points when base reference volumes are empty');

$areaConfig = [
    'fields' => [
        [
            'property_code' => 'CALC_PROP_FORMAT',
            'use_for_area_dependency' => true,
            'presets' => [['value' => '210x297', 'xml_id' => '210x297', 'sort' => 100]],
        ],
        [
            'property_code' => 'CALC_PROP_VOLUME',
            'reference_volumes' => [
                'base' => ['100', '500', '1000'],
                'area' => [
                    ['index' => 0, 'volumes' => ['100', '250', '500']],
                    ['index' => 1, 'volumes' => ['50', '100', '250']],
                ],
            ],
            'area_ranges' => [
                ['index' => 0, 'area_from_mm2' => 1, 'area_to_mm2' => 70000],
                ['index' => 1, 'area_from_mm2' => 70001, 'area_to_mm2' => null],
            ],
        ],
    ],
];
$offers = $builder->build($areaConfig, [], [], [], 10, 20);
assert_same_value([100, 250, 500], offer_volumes($offers), 'Area reference volumes should be selected for A4 area');
assert_same_value(62370.0, $builder->parseAreaMm2('210 × 297 мм'), 'Area parser should support multiplication sign and unit');
assert_same_value(62370.0, $builder->parseAreaMm2('A4 (210×297 мм)'), 'Area parser should ignore numbers outside dimension separator');
assert_same_value(null, $builder->parseAreaMm2('A4 210 297 мм'), 'Area parser should require a dimension separator');

$fallbackConfig = $areaConfig;
$fallbackConfig['fields'][0]['presets'][0] = ['value' => 'custom', 'xml_id' => 'custom', 'sort' => 100];
$offers = $builder->build($fallbackConfig, [], [], [], 10, 20);
assert_same_value([100, 500, 1000], offer_volumes($offers), 'Unrecognized area should fall back to base volumes');

$emptyAreaVolumesConfig = $areaConfig;
$emptyAreaVolumesConfig['fields'][1]['reference_volumes']['area'][0]['volumes'] = [];
$offers = $builder->build($emptyAreaVolumesConfig, [], [], [], 10, 20);
assert_same_value([100, 500, 1000], offer_volumes($offers), 'Empty area volumes should fall back to base volumes');

$existingOffers = [[
    'properties' => [
        'CALC_PROP_FORMAT' => ['xml_id' => 'A4'],
        'CALC_PROP_VOLUME' => ['xml_id' => '500'],
    ],
    'catalog' => ['prices_view_all' => [['price' => 1]]],
]];
$offers = $builder->build($baseConfig, [], [], $existingOffers, 10, 20);
assert_same_value(['A4+100', 'A4+1000'], offer_keys($offers), 'Existing priced real offer should not be duplicated');


$existingOfferWithPriceHole = [[
    'quantity' => 500,
    'properties' => [
        'CALC_PROP_FORMAT' => ['xml_id' => 'A4'],
        'CALC_PROP_VOLUME' => ['xml_id' => '500'],
    ],
    'catalog' => [
        'prices_view_all' => [[
            'price' => 1,
            'quantity_from' => 1,
            'quantity_to' => 99,
        ]],
    ],
]];
$offers = $builder->build($baseConfig, [], [], $existingOfferWithPriceHole, 10, 20);
assert_same_value(['A4+100', 'A4+1000'], offer_keys($offers), 'Real offer price for one basket item should suppress duplicate calculation regardless of circulation');
$existingOfferWithPriceHole[0]['catalog']['prices_view_all'][0]['quantity_from'] = 2;
$offers = $builder->build($baseConfig, [], [], $existingOfferWithPriceHole, 10, 20);
assert_same_value(['A4+100', 'A4+1000', 'A4+500'], offer_keys($offers), 'Real offer without a price for one basket item should not suppress virtual offer');

$batches = $builder->splitIntoBatches([1, 2, 3, 4, 5], 2);
assert_same_value([2, 2, 1], array_map('count', $batches), 'Batch limit should split requests without dropping offers');

echo "VirtualOfferBatchBuilder tests passed\n";
