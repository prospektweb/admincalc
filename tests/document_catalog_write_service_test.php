<?php
declare(strict_types=1);
require_once __DIR__ . '/fixtures/native_catalog_write_fixture.php';
use Prospektweb\Calc\Documents\{DocumentCatalogWriteService, DocumentCatalogWritePlan as Plan, DocumentSchema};
$checks = 0;
function writeCheck(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function writeReject(callable $work, ?int $code = null): void
{
    try { $work(); } catch (Throwable $e) { writeCheck($code === null || $e->getCode() === $code, $e->getMessage()); return; }
    throw new RuntimeException('Expected catalog write rejection');
}
function applyRequest(array $request, array $preview): array { return array_replace($request, ['action' => 'applyCatalogWrite', 'expectedFingerprint' => $preview['fingerprint']]); }
$f = nativeWriteFixture(); extract($f);
$before = $port->read(); $baseline = [$repo->load('sheet'), $repo->history('sheet'), $repo->sitePublication('sheet')];
$preview = $service->command($request);
writeCheck($preview['contract'] === DocumentCatalogWriteService::PREVIEW && $preview['offerIds'] === [101, 102], 'Native identity and deterministic order');
writeCheck($preview['summary'] === ['total' => 2, 'changedOffers' => 2, 'unchangedOffers' => 0, 'changedFields' => 12], 'Exact preview summary');
writeCheck(Plan::hash($port->read()) === Plan::hash($before) && $db->rows('SELECT id FROM b_pw_calc_catalog_write') === [], 'Preview is read-only');
writeCheck($coreState->calls === 1 && $port->writes === 0, 'All offers calculated in one bounded core batch outside transaction');
$apply = applyRequest($request, $preview); $receipt = $service->command($apply);
writeCheck($receipt['applied'] === true && $receipt['summary'] === ['total' => 2, 'updated' => 2], 'Atomic native catalog write');
writeCheck($coreState->calls === 2 && $port->writes === 1, 'Apply recalculates all targets then writes once');
writeCheck(Plan::hash($port->read()[0]['current']) === Plan::hash(Plan::target(nativeWriteQuote(), $connection, nativeWriteState())), 'Exact target readback');
writeCheck(count($db->rows('SELECT id FROM b_pw_calc_catalog_write')) === 1, 'Immutable receipt stored with catalog transaction');
writeCheck(Plan::hash($service->command($apply)) === Plan::hash($receipt) && $coreState->calls === 2 && $port->writes === 1, 'Lost-response replay skips remote work and duplicate writes');
writeCheck(count($receipt['provenance']['inputs']) === 2 && count($receipt['provenance']['resultHashes']) === 2 && $receipt['provenance']['snapshotHash'] === $published['snapshotHash'], 'Receipt retains native reproducible inputs and calculation provenance');
writeCheck([$repo->load('sheet'), $repo->history('sheet'), $repo->sitePublication('sheet')] === $baseline, 'Writing catalog never mutates document or publication');
DocumentSchema::install($db); writeCheck(count($db->rows('SELECT id FROM b_pw_calc_catalog_write')) === 1, 'Idempotent schema install preserves receipt');
$noop = $service->command($request); $noopReceipt = $service->command(applyRequest($request, $noop));
writeCheck($noop['summary']['changedFields'] === 0 && $noopReceipt['summary']['updated'] === 0 && $port->writes === 1, 'No-op does not touch catalog');
$rows = $port->read(); $rows[0]['values']->qty = 200; $port->replace($rows);
writeReject(fn() => $service->command($apply), 409); writeCheck($port->writes === 1, 'Replay after input drift rejects');

foreach (['offerResults' => [], 'values' => (object)['qty' => 999], 'actor' => 'user:2', 'presetId' => 1, 'connectionJson' => '{}'] as $key => $value) {
    writeReject(fn() => $service->command($request + [$key => $value]), 0);
}
foreach ([[], [101, 101], ['101'], [0], [1.5], range(1, 101)] as $bad) writeReject(fn() => $service->command(array_replace($request, ['offerIds' => $bad])), 0);
writeReject(fn() => $service->command(array_replace($apply, ['expectedFingerprint' => 'bad'])), 0);
writeReject(fn() => (new DocumentCatalogWriteService($db, 'site:s2', 'user:1', 'bitrix:test', $port, $core))->command($request), 404);
writeReject(fn() => (new DocumentCatalogWriteService($db, 'site:s1', 'user:2', 'bitrix:test', $port, $core))->command($apply), 409);
writeReject(fn() => (new DocumentCatalogWriteService($db, 'site:s1', 'user:1', 'bitrix:foreign', $port, $core))->command($request));

// Every failure below uses real SQLite rollback, not a mocked transaction flag.
foreach (['partial', 'readback', 'input', 'schema', 'receipt'] as $failure) {
    extract(nativeWriteFixture()); $preview = $service->command($request); $before = $port->read();
    if ($failure === 'receipt') $db->execute("CREATE TRIGGER fail_receipt BEFORE INSERT ON b_pw_calc_catalog_write BEGIN SELECT RAISE(ABORT, 'receipt failed'); END");
    else $port->onWrite = static function () use ($failure, $port, $db): void {
        if ($failure === 'partial') throw new RuntimeException('Partial write');
        if ($failure === 'schema') { $db->execute('UPDATE qa_catalog SET schema_hash = ?', [str_repeat('b', 64)]); return; }
        $rows = $port->read();
        if ($failure === 'input') $rows[0]['values']->qty = 555;
        else $rows[0]['current']['dimensions']['width'] = 999;
        $port->replace($rows);
    };
    writeReject(fn() => $service->command(applyRequest($request, $preview)));
    writeCheck(Plan::hash($port->read()) === Plan::hash($before) && $db->rows('SELECT id FROM b_pw_calc_catalog_write') === [] && !$db->inTransaction(), 'Rollback: ' . $failure);
    writeCheck($db->rows('SELECT schema_hash FROM qa_catalog')[0]['schema_hash'] === str_repeat('a', 64), 'Schema state restored: ' . $failure);
}
foreach (['price', 'input', 'schema'] as $drift) {
    extract(nativeWriteFixture()); $preview = $service->command($request);
    $coreState->hook = static function () use ($port, $db, $drift): void {
        if ($drift === 'schema') { $db->execute('UPDATE qa_catalog SET schema_hash = ?', [str_repeat('b', 64)]); return; }
        $rows = $port->read(); if ($drift === 'price') $rows[0]['current']['prices'][0]['price'] = 999; else $rows[0]['values']->qty = 333;
        $port->replace($rows);
    };
    writeReject(fn() => $service->command(applyRequest($request, $preview)), 409);
    writeCheck($port->writes === 0 && !$db->inTransaction(), 'Drift during server calculation rejected: ' . $drift);
}
extract(nativeWriteFixture());
$coreState->hook = static function () use ($repo, $body, $published): void {
    $draft = $repo->save('sheet', 2, str_replace('Sheet', 'New', $body));
    $repo->publishSite('sheet', $draft['revision'], $published['id'], nativeWriteSnapshot($draft));
};
writeReject(fn() => $service->command($request), 409); writeCheck($port->writes === 0, 'Publication race during preview rejected');
foreach (['target', 'provider', 'offers', 'execution'] as $invalid) {
    extract(nativeWriteFixture());
    $port->onCapture = static function (array $snapshot) use ($invalid): array {
        if ($invalid === 'target') $snapshot['offers'][0]['productId'] = 777;
        elseif ($invalid === 'provider') $snapshot['authority']->provider = 'bitrix:foreign';
        elseif ($invalid === 'offers') $snapshot['offers'][0]['offerId'] = 999;
        else $snapshot['offers'][0]['execution']['unitCount'] = '100';
        return $snapshot;
    };
    writeReject(fn() => $service->command($request)); writeCheck($coreState->calls === 0 && $port->writes === 0, 'Invalid adapter authority before core: ' . $invalid);
}
extract(nativeWriteFixture()); $preview = $service->command($request); $apply = applyRequest($request, $preview); $service->command($apply);
$db->execute('UPDATE b_pw_calc_catalog_write SET receipt_json = ?', ['{}']);
writeReject(fn() => $service->command($apply)); writeCheck($port->writes === 1, 'Corrupt receipt never triggers a second write');
extract(nativeWriteFixture()); $db->begin(); writeReject(fn() => $service->command($request)); writeCheck($db->inTransaction(), 'Caller transaction not committed or rolled back'); $db->rollback();
foreach (['missing', 'duplicate', 'foreign', 'partial', 'wrongtype'] as $invalid) {
    extract(nativeWriteFixture());
    $coreState->responseHook = static function (array $response) use ($invalid): array {
        if ($invalid === 'missing') return [];
        if ($invalid === 'partial') array_pop($response['results']);
        elseif ($invalid === 'duplicate') $response['results'][1] = $response['results'][0];
        elseif ($invalid === 'foreign') $response['results'][0]['id'] = 999;
        else $response['results'][0]['id'] = '101';
        return $response;
    };
    writeReject(fn() => $service->command($request)); writeCheck($port->writes === 0, 'Malformed core batch rejected: ' . $invalid);
}
extract(nativeWriteFixture()); $rows = [];
for ($index = 1; $index <= 41; $index++) { $row = $port->read()[0]; $row['offerId'] = $index; $rows[] = $row; }
$port->replace($rows); $request['offerIds'] = range(1, 41);
$preview = $service->command($request); writeCheck($coreState->calls === 3 && $preview['summary']['total'] === 41, 'Bounded 20-offer batches, not one HTTP call per offer');
$service->command(applyRequest($request, $preview)); writeCheck($coreState->calls === 6 && $port->writes === 1, 'All batches calculated before a single write transaction');
extract(nativeWriteFixture()); $preview = $service->command($request); $apply = applyRequest($request, $preview);
$coreState->hook = static function () use ($service, $apply): void { $service->command($apply); };
$receipt = $service->command($apply); writeCheck($receipt['applied'] && $port->writes === 1 && count($db->rows('SELECT id FROM b_pw_calc_catalog_write')) === 1, 'Concurrent duplicate committed during core work replays under final lock');
extract(nativeWriteFixture()); $preview = $service->command($request); $apply = applyRequest($request, $preview); $before = $port->read();
$coreState->quote['basePrice'] += 1; writeReject(fn() => $service->command($apply), 409);
writeCheck(Plan::hash($before) === Plan::hash($port->read()) && $port->writes === 0, 'Changed authoritative quote cannot use an earlier approval');
echo "PASS $checks native catalog write service assertions\n";
