<?php
declare(strict_types=1);
require_once __DIR__ . '/fixtures/native_catalog_write_fixture.php';
use Prospektweb\Calc\Documents\{DocumentQuotePricing, DocumentCatalogWritePlan as Plan};
$checks = 0;
function selectedCheck(bool $pass, string $message): void { global $checks; $checks++; if (!$pass) throw new RuntimeException($message); }
function selectedReject(callable $work): void {
    try { $work(); } catch (InvalidArgumentException $error) { selectedCheck(true, $error->getMessage()); return; }
    throw new RuntimeException('Invalid selected price grid accepted.');
}
$fixture = nativeWriteFixture(true); $document = json_decode($fixture['body']); $connection = $fixture['connection']; $quote = nativeWriteQuote(); $state = nativeWriteState();
$source = Plan::canonical([$document, $connection, $quote, $state]);
$selected = DocumentQuotePricing::connection($document, $quote, $connection);
selectedCheck(array_column($selected->priceTypes, 'key') === ['1'], 'Only rules in the selected published grid own writes.');
$target = Plan::target($quote, $selected, $state);
selectedCheck(array_values(array_filter($target['prices'], fn($row) => $row['typeId'] === 99)) === [Plan::state($state)['prices'][1]], 'Inactive registered type remains exact including its foreign currency.');
selectedCheck(Plan::canonical([$document, $connection, $quote, $state]) === $source, 'Published document/connection and quote are not mutated by projection.');

foreach ([
    static function (&$q) { unset($q['appliedPriceRules']); },
    static function (&$q) { array_pop($q['appliedPriceRules']); },
    static function (&$q) { $q['appliedPriceRules'][0]['price'] = 999; },
    static function (&$q) { $q['calculatorId'] = 'other'; },
    static function (&$q) { $q['currency'] = 'USD'; },
    static function (&$q) { unset($q['priceProfile']); },
    static function (&$q) { $q['priceProfile'] = ['id' => 'unknown']; },
    static function (&$q) { array_pop($q['priceRanges']); },
    static function (&$q) { $q['priceRanges'][1]['quantityFrom'] = 20; },
    static function (&$q) { $q['priceRanges'][0]['prices'] = []; },
    static function (&$q) { $q['priceRanges'][0]['prices'][0]['typeId'] = 'inactive'; },
] as $mutate) {
    $q = $quote; $mutate($q);
    selectedReject(fn() => Plan::target($q, DocumentQuotePricing::connection($document, $q, $connection), $state));
}
$bad = clone $connection; array_pop($bad->priceTypes);
selectedReject(fn() => DocumentQuotePricing::connection($document, $quote, $bad));
$bad = clone $connection; $bad->priceTypes = [$connection->priceTypes[0], $connection->priceTypes[0]];
selectedReject(fn() => DocumentQuotePricing::connection($document, $quote, $bad));

$profileDocument = json_decode(json_encode($document));
$rules = json_decode(json_encode($document->pricing->ranges));
foreach ($document->pricing->ranges as $rule) { $copy = clone $rule; $copy->typeId = 'inactive'; $rules[] = $copy; }
$profileDocument->pricing->profiles = [(object)['id' => 'special', 'name' => 'Special', 'enabled' => true,
    'condition' => (object)['code' => 'special', 'equals' => true], 'prices' => $rules]];
$profileQuote = $quote; $profileQuote['priceProfile'] = ['id' => 'special', 'name' => 'Special', 'conditionCode' => 'special'];
$profileQuote['appliedPriceRules'] = json_decode(json_encode($rules), true);
foreach ($profileQuote['priceRanges'] as &$range) { $range['prices'][] = ['typeId' => 'inactive', 'basePrice' => 105, 'currency' => 'RUB']; } unset($range);
$profileConnection = DocumentQuotePricing::connection($profileDocument, $profileQuote, $connection);
selectedCheck(array_column($profileConnection->priceTypes, 'key') === ['1', '99'], 'Conditional profile has its own type set.');
$profileTarget = Plan::target($profileQuote, $profileConnection, $state);
selectedCheck(count(array_filter($profileTarget['prices'], fn($row) => $row['typeId'] === 99 && $row['currency'] === 'RUB')) === 2, 'Selected profile may legitimately replace its owned price currency/ranges.');
$profileDocument->pricing->profiles[0]->enabled = false;
selectedReject(fn() => DocumentQuotePricing::connection($profileDocument, $profileQuote, $connection));
$profileDocument->pricing->profiles[0]->enabled = true;
$badQuote = $profileQuote; $badQuote['appliedPriceRules'] = $quote['appliedPriceRules'];
selectedReject(fn() => DocumentQuotePricing::connection($profileDocument, $badQuote, $connection));
$badQuote = $profileQuote; $badQuote['priceProfile']['conditionCode'] = 'other';
selectedReject(fn() => DocumentQuotePricing::connection($profileDocument, $badQuote, $connection));
$badQuote = $profileQuote; foreach ($badQuote['priceRanges'] as &$range) { array_pop($range['prices']); } unset($range);
selectedReject(fn() => Plan::target($badQuote, DocumentQuotePricing::connection($profileDocument, $badQuote, $connection), $state));

extract($fixture);
$before = $port->read(); $publicationBefore = $repo->sitePublication('sheet');
$preview = $service->command($request);
$receipt = $service->command(array_replace($request, ['action' => 'applyCatalogWrite', 'expectedFingerprint' => $preview['fingerprint']]));
selectedCheck($receipt['applied'] && $port->writes === 1, 'Native coordinator writes the selected subset.');
foreach ($port->lastTargets as $target) selectedCheck($target['priceTypeIds'] === [1], 'Scope is explicit per offer, not all publication bindings.');
foreach ($port->read() as $index => $row) selectedCheck(Plan::state($row['current'])['prices'][2] === Plan::state($before[$index]['current'])['prices'][1], 'Inactive type survives the full coordinator cycle.');
selectedCheck($repo->sitePublication('sheet') === $publicationBefore, 'Catalog write does not mutate the published registry or connection.');

// Two offers may select different profiles. Each receives its own verified write scope.
extract(nativeWriteFixture(true));
$data = json_decode($body); $data->pricing->profiles = $profileDocument->pricing->profiles;
$draft = $repo->save('sheet', 2, json_encode($data, JSON_THROW_ON_ERROR));
$publication = $repo->publishSite('sheet', $draft['revision'], $published['id'], nativeWriteSnapshot($draft));
$request['publicationId'] = $publication['id'];
$coreState->responseHook = static function (array $response) use ($profileQuote): array { $response['results'][1]['result'] = $profileQuote; return $response; };
$port->expectedPriceTypes = [101 => [1], 102 => [1, 99]];
$preview = $service->command($request);
$receipt = $service->command(array_replace($request, ['action' => 'applyCatalogWrite', 'expectedFingerprint' => $preview['fingerprint']]));
selectedCheck($receipt['applied'] && array_column($port->lastTargets, 'priceTypeIds') === [[1], [1, 99]], 'Mixed-profile batch never expands one offer to another offer scope.');
selectedCheck(!$db->inTransaction(), 'Transactions are closed.');
echo "PASS $checks selected price-grid authority checks\n";
