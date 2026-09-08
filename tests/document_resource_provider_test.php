<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/BitrixResourceProvider.php';
use Prospektweb\Calc\Documents\BitrixResourceProvider;
$checks = 0;
foreach (['RUB' => ['amount', 'RUB'], 'PRC' => ['markupPercent', null], 'MRG' => ['marginPercent', null]] as $code => [$mode, $currency]) {
    $row = BitrixResourceProvider::normalizePrice(['PRICE' => 17.5, 'CURRENCY' => $code, 'QUANTITY_FROM' => null, 'QUANTITY_TO' => 50], 'price-type');
    if ($row !== ['typeId' => 'price-type', 'price' => 17.5, 'mode' => $mode, 'currency' => $currency, 'quantityFrom' => null, 'quantityTo' => 50]) { throw new RuntimeException('Invalid portable resource price'); }
    $checks++;
}
if ((new BitrixResourceProvider('test'))((object)['resources' => []]) !== []) { throw new RuntimeException('Resource-free calculation must not access Bitrix'); }
echo 'PASS ' . ($checks + 1) . " resource adapter checks\n";
require __DIR__ . '/bitrix_resource_snapshot_identity_test.php';
