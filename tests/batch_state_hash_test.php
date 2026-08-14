<?php

require_once __DIR__ . '/../lib/Services/BatchRecalculateService.php';

use Prospektweb\Calc\Services\BatchRecalculateService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$service = (new ReflectionClass(BatchRecalculateService::class))->newInstanceWithoutConstructor();
$payload = [
    'elementsStore' => [
        'CALC_MATERIALS_VARIANTS' => [
            ['id' => 91, 'properties' => ['PURCHASE_PRICE' => ['VALUE' => 1525]]],
        ],
    ],
    'selectedOffers' => [[
        'id' => 15320,
        'name' => 'Offer 100',
        'timestampX' => '2026-08-14 06:47:48',
        'modifiedBy' => 1,
        'attributes' => ['width' => 90, 'length' => 50, 'height' => 32, 'weight' => 135.5],
        'prices' => [['typeId' => 1, 'price' => 88.4]],
        'purchasingPrice' => 66.3,
        'purchasingCurrency' => 'RUB',
        'catalog' => [
            'vatId' => 0,
            'vatIncluded' => false,
            'extendedPriceMode' => true,
            'basePrice' => 88.4,
            'baseCurrency' => 'RUB',
        ],
        'properties' => [
            'CALC_PROP_FORMAT' => ['VALUE_XML_ID' => '90x50'],
            'CALC_STATE_HASH' => ['VALUE' => 'old-hash'],
            'COMPLETED_CALCS' => ['VALUE' => ['history-1']],
            'PARAMETR_VALUES' => ['VALUE' => ['old result']],
        ],
    ]],
    'preset' => ['id' => 12740, 'logic' => ['version' => 1]],
    'priceTypes' => [['id' => 1, 'name' => 'Retail']],
];

$baseHash = $service->computeStateHash($payload);

$outputsChanged = $payload;
$outputsChanged['selectedOffers'][0]['timestampX'] = '2026-08-14 07:00:00';
$outputsChanged['selectedOffers'][0]['modifiedBy'] = 7;
$outputsChanged['selectedOffers'][0]['attributes']['weight'] = 999;
$outputsChanged['selectedOffers'][0]['prices'][0]['price'] = 999;
$outputsChanged['selectedOffers'][0]['purchasingPrice'] = 888;
$outputsChanged['selectedOffers'][0]['catalog']['basePrice'] = 777;
$outputsChanged['selectedOffers'][0]['properties']['CALC_STATE_HASH']['VALUE'] = 'new-hash';
$outputsChanged['selectedOffers'][0]['properties']['COMPLETED_CALCS']['VALUE'][] = 'history-2';
$outputsChanged['selectedOffers'][0]['properties']['PARAMETR_VALUES']['VALUE'] = ['new result'];
$assert(
    $service->computeStateHash($outputsChanged) === $baseHash,
    'Catalog outputs written by recalculation must not invalidate onlyChanged hash'
);

$sourceChanged = $payload;
$sourceChanged['selectedOffers'][0]['properties']['CALC_PROP_FORMAT']['VALUE_XML_ID'] = '85x55';
$assert(
    $service->computeStateHash($sourceChanged) !== $baseHash,
    'Offer calculation input must invalidate state hash'
);

$materialChanged = $payload;
$materialChanged['elementsStore']['CALC_MATERIALS_VARIANTS'][0]['properties']['PURCHASE_PRICE']['VALUE'] = 1600;
$assert(
    $service->computeStateHash($materialChanged) !== $baseHash,
    'Referenced material price must invalidate state hash'
);

$reordered = $payload;
$reordered['preset'] = ['logic' => ['version' => 1], 'id' => 12740];
$assert(
    $service->computeStateHash($reordered) === $baseHash,
    'Associative key order must not invalidate state hash'
);

echo "Batch state hash tests passed\n";
