<?php
$ajax = file_get_contents(__DIR__ . '/../ajax/frontcalc.php');
function assert_static($cond,$msg){ if(!$cond){fwrite(STDERR,$msg."\n"); exit(1);} }
$sale = strpos($ajax, "includeModule('sale')");
assert_static(strpos($ajax, 'FRONTCALC_METHOD_NOT_ALLOWED') < $sale, 'POST check is before sale');
assert_static(strpos($ajax, 'FRONTCALC_INVALID_SESSID') < $sale, 'sessid check is before sale');
assert_static(strpos($ajax, '$_POST[\'displayed_price\']') !== false && strpos($ajax, '(float)$quote[\'price\']') !== false, 'browser price only diagnostic');
assert_static(strpos($ajax, 'frontcalc_parse_displayed_price') !== false && strpos($ajax, "preg_match('/^-?\\d+(?:[,.]\\d+)?$/") !== false, 'displayed_price is strictly parsed');
assert_static(strpos($ajax, '$displayedPrice !== null') !== false, 'invalid displayed_price is ignored');
assert_static(strpos($ajax, 'ServerQuoteCalculator())->calculate') < strpos($ajax, 'frontcalc_parse_displayed_price($_POST[\'displayed_price\'])'), 'displayed_price is parsed after server quote');
$saleBlock = substr($ajax, $sale);
assert_static(strpos($saleBlock, '$_REQUEST[\'offer_id\']') === false && strpos($saleBlock, '$_POST[\'offer_id\']') === false, 'offer_id is not read for basket product');
assert_static(strpos($ajax, 'ServerQuoteCalculator())->calculate') !== false, 'ServerQuoteCalculator is used');
assert_static(strpos($ajax, 'priceAccessForBasket[\'buy\']') !== false, 'buy rights checked');
assert_static(strpos($ajax, '($quote[\'mode\'] ?? \'\') === \'exact\'') !== false && strpos($ajax, 'SERVICE_OFFER_ID') !== false, 'real exact and service offer selection present');
assert_static(strpos($ajax, ': $productId;') !== false, 'current product is the fallback carrier for calculated basket positions');
assert_static(strpos($ajax, 'FRONTCALC_SERVICE_OFFER_NOT_CONFIGURED') === false, 'service offer is no longer mandatory');
assert_static(strpos($ajax, 'FRONTCALC_CALCULATED_NAME') !== false && strpos($ajax, '(string)($quote[\'name\'] ?? \'\')') !== false, 'calculated title is stored in the basket');
echo "OK\n";
