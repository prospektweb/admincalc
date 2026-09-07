<?php
declare(strict_types=1);
require_once __DIR__ . '/bitrix_transaction_test_stubs.php';
require_once dirname(__DIR__) . '/lib/Documents/BitrixConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
use Prospektweb\Calc\Documents\{BitrixConnection, PdoConnection};

$host = new class extends \Bitrix\Main\DB\MysqliConnection {
    public array $events = [];
    public function queryExecute(string $sql): void { $this->events[] = $sql; }
    public function startTransaction(): void { $this->events[] = 'begin'; parent::startTransaction(); }
};
$db = new BitrixConnection($host);
$db->begin(true); $db->commit();
if ($host->events !== ['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY', 'begin']) { throw new RuntimeException('Snapshot must set only next transaction characteristics before begin.'); }
$host->events = []; $db->begin();
if ($host->events !== ['begin']) { throw new RuntimeException('Ordinary commands must preserve host defaults.'); }
try { $db->begin(true); throw new RuntimeException('Nested read transaction accepted.'); }
catch (LogicException $expected) {}
if ($host->events !== ['begin']) { throw new RuntimeException('Nested read changed host characteristics.'); }
$db->rollback();
$sqlite = new PdoConnection(new PDO('sqlite::memory:'));
$sqlite->begin(true);
if (!$sqlite->inTransaction() || $sqlite->rows('SELECT LOWER(?) AS value', ['ЛИСТОВАЯ'])[0]['value'] !== 'листовая') { throw new RuntimeException('Portable read snapshot failed.'); }
$sqlite->commit();
if ($sqlite->inTransaction()) { throw new RuntimeException('Snapshot was not closed.'); }
echo "PASS read snapshot adapter, host isolation preservation and nested-transaction guards\n";
