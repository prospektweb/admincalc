<?php

require_once __DIR__ . '/../lib/Handlers/AdminHandler.php';

use Prospektweb\Calc\Handlers\AdminHandler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$madeToOrder = [
    'CATALOG_QUANTITY_TRACE' => 'N',
    'CAN_BUY' => 'Y',
    'ITEM_PRICES' => [['DISCOUNT_PRICE' => 71.29]],
];
$count = 0;
AdminHandler::onAsproGetTotalCountFromCatalog($madeToOrder, [], $count);
$assert($count === 1, 'Purchasable positively priced made-to-order offer must be shown as available');

$count = 7;
AdminHandler::onAsproGetTotalCountFromCatalog($madeToOrder, [], $count);
$assert($count === 7, 'Real positive stock quantity must not be changed');

$count = 0;
$tracked = $madeToOrder;
$tracked['CATALOG_QUANTITY_TRACE'] = 'Y';
AdminHandler::onAsproGetTotalCountFromCatalog($tracked, [], $count);
$assert($count === 0, 'Tracked zero-stock offer must remain unavailable');

$count = 0;
$notPurchasable = $madeToOrder;
$notPurchasable['CAN_BUY'] = 'N';
AdminHandler::onAsproGetTotalCountFromCatalog($notPurchasable, [], $count);
$assert($count === 0, 'Non-purchasable offer must remain unavailable');

$count = 0;
$free = $madeToOrder;
$free['ITEM_PRICES'][0]['DISCOUNT_PRICE'] = 0;
AdminHandler::onAsproGetTotalCountFromCatalog($free, [], $count);
$assert($count === 0, 'Zero-price offer must remain unavailable');

$count = 0;
$catalogPrice = [
    'PRODUCT' => ['QUANTITY_TRACE' => 'N', 'AVAILABLE' => 'Y'],
    'CATALOG_PRICE_1' => '88.40',
];
AdminHandler::onAsproGetTotalCountFromCatalog($catalogPrice, [], $count);
$assert($count === 1, 'Catalog price and nested product availability must be supported');

echo "Catalog availability handler tests passed\n";
