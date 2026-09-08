<?php
declare(strict_types=1);
require_once __DIR__ . '/fixtures/native_catalog_write_fixture.php';
use Prospektweb\Calc\Documents\DocumentCatalogWritePlan as Plan;
$checks = 0;
function planCheck(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function planReject(callable $work): void { try { $work(); } catch (InvalidArgumentException $e) { planCheck(true, $e->getMessage()); return; } throw new RuntimeException('Expected invalid plan'); }
$fixture = nativeWriteFixture(); $connection = $fixture['connection']; $state = nativeWriteState(); $quote = nativeWriteQuote();
$target = Plan::target($quote, $connection, $state);
planCheck($target['purchasingPrice']['value'] === 100.12345679, 'Catalog cost is basePrice rounded to DECIMAL scale, not direct cost');
planCheck($target['prices'][1]['price'] === 120.12345679 && $target['prices'][1]['quantityFrom'] === null, 'Bitrix zero/null range and decimal normalization');
planCheck($target['prices'][2] === Plan::state($state)['prices'][1], 'Unbound price type/currency preserved');
planCheck(count(array_filter(Plan::diffs($state, $target), fn($row) => $row['changed'])) === 6, 'Exactly six original catalog diff fields');
planCheck(count(array_filter(Plan::diffs($target, $target), fn($row) => $row['changed'])) === 0, 'No-op diff');
planCheck(Plan::hash(['b' => 1, 'a' => (object)['z' => 2, 'x' => 3]]) === Plan::hash(['a' => (object)['x' => 3, 'z' => 2], 'b' => 1]), 'Canonical property order');
planCheck(Plan::hash(new stdClass()) !== Plan::hash([]), 'Object/list identity preserved');
foreach ([null, 0, -1, '100', true, INF, NAN, 1e18, 0.000000001] as $bad) {
    $q = $quote; $q['basePrice'] = $bad; planReject(fn() => Plan::target($q, $connection, $state));
}
foreach (['width', 'length', 'height', 'weight'] as $key) {
    $q = $quote; unset($q['parts'][0]['outputs'][$key]); planReject(fn() => Plan::target($q, $connection, $state));
    $q = $quote; $q['parts'][0]['outputs'][$key] = 0; planReject(fn() => Plan::target($q, $connection, $state));
}
foreach (['unknown', ''] as $type) { $q = $quote; $q['priceRanges'][0]['prices'][0]['typeId'] = $type; planReject(fn() => Plan::target($q, $connection, $state)); }
$q = $quote; $q['priceRanges'][0]['prices'][] = $q['priceRanges'][0]['prices'][0]; planReject(fn() => Plan::target($q, $connection, $state));
$q = $quote; $q['priceRanges'][0]['prices'] = []; planReject(fn() => Plan::target($q, $connection, $state));
$q = $quote; $q['priceRanges'][1]['quantityFrom'] = 10; planReject(fn() => Plan::target($q, $connection, $state));
$q = $quote; $q['priceRanges'][0]['prices'][0]['currency'] = 'USD'; planReject(fn() => Plan::target($q, $connection, $state));
foreach (['quantityFrom', 'quantityTo'] as $key) { $q = $quote; unset($q['priceRanges'][0][$key]); planReject(fn() => Plan::target($q, $connection, $state)); }
foreach (['', 'rub', 'RUB ', null] as $currency) { $q = $quote; $q['currency'] = $currency; planReject(fn() => Plan::target($q, $connection, $state)); }
$bad = clone $connection; $bad->outputMappings = []; planReject(fn() => Plan::target($quote, $bad, $state));
$bad = clone $connection; $bad->outputMappings = [$bad->outputMappings[0]]; planReject(fn() => Plan::target($quote, $bad, $state));
$bad = clone $connection; $bad->priceTypes = array_merge($bad->priceTypes, $bad->priceTypes); planReject(fn() => Plan::target($quote, $bad, $state));
$badState = $state; $badState['name'] = 'Do not overwrite'; planReject(fn() => Plan::state($badState));
$badState = $state; $badState['prices'][] = $badState['prices'][0]; planReject(fn() => Plan::state($badState));
planCheck(!array_key_exists('name', $target) && !array_key_exists('parametrValues', $target), 'Projection cannot write names or arbitrary iblock properties');
echo "PASS $checks native catalog write plan assertions\n";
