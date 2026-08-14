<?php

require_once dirname(__DIR__) . '/lib/Services/StandaloneCatalogSelectionMapper.php';

use Prospektweb\Calc\Services\StandaloneCatalogSelectionMapper;

function standalone_mapper_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$mapper = new StandaloneCatalogSelectionMapper();
standalone_mapper_assert(
    StandaloneCatalogSelectionMapper::supportedProductIds() === [12727, 12764, 14379, 14380, 15344],
    'adapter scope is the exact prepared five-product matrix'
);
$rounded = $mapper->map([
    'id' => 15326,
    'productId' => 12764,
    'name' => 'Скруглённые визитки | 100 экз.',
    'properties' => [
        'CALC_PROP_COLOR_SCHEME' => ['VALUE_XML_ID' => '4+0'],
        'CALC_PROP_VOLUME' => ['VALUE_XML_ID' => '100'],
        // Deliberately wrong carrier values must not become calculation input.
        'CALC_PROP_FORMAT' => ['VALUE_XML_ID' => 'A4'],
        'CALC_PROP_DENSITY_PAPER' => ['VALUE_XML_ID' => 'THIN'],
    ],
]);
standalone_mapper_assert($rounded['offerId'] === 15326, 'target offer id is preserved');
standalone_mapper_assert($rounded['quantity'] === 100, 'circulation selects the exact target result');
standalone_mapper_assert($rounded['selection']['CALC_PROP_FORMAT'] === '90x50', 'carrier format cannot override the profile');
standalone_mapper_assert($rounded['selection']['CALC_PROP_DENSITY_PAPER'] === 'MAX', 'carrier density cannot override the profile');
standalone_mapper_assert($rounded['selection']['CALC_PROP_OPTIONS'] === ['round-corners'], 'rounded product profile selects the preset operation');

$euro = $mapper->map([
    'id' => 15349,
    'productId' => 0,
    'properties' => [
        'CML2_LINK' => ['VALUE' => '15344'],
        'CALC_PROP_COLOR_SCHEME' => ['VALUE_XML_ID' => ['4+4']],
        'CALC_PROP_VOLUME' => ['VALUE_XML_ID' => ['500']],
    ],
]);
standalone_mapper_assert($euro['selection']['CALC_PROP_FORMAT'] === '85x55', 'euro target profile owns its format');
standalone_mapper_assert($euro['selection']['CALC_PROP_COLOR_SCHEME'] === '4+4', 'offer colour identifies the target result');
standalone_mapper_assert($euro['productId'] === 15344, 'CML2_LINK resolves the output carrier when Bitrix omits productId');

$unsupportedRejected = false;
try {
    $mapper->map([
        'id' => 999,
        'productId' => 998,
        'properties' => [
            'CALC_PROP_COLOR_SCHEME' => ['VALUE_XML_ID' => '4+0'],
            'CALC_PROP_VOLUME' => ['VALUE_XML_ID' => '100'],
        ],
    ]);
} catch (InvalidArgumentException $exception) {
    $unsupportedRejected = strpos($exception->getMessage(), 'не настроен профиль') !== false;
}
standalone_mapper_assert($unsupportedRejected, 'unknown products must fail closed');

echo "standalone_catalog_selection_mapper_test: OK\n";
