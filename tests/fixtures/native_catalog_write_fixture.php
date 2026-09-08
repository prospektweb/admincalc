<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__, 2) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__, 2) . '/lib/Documents/DocumentCatalogWriteService.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentRepository, SiteConnection, DocumentOutputMappings, DocumentCatalogWritePort, DocumentCatalogWriteService, DocumentCatalogWritePlan};

final class NativeWriteFixturePort implements DocumentCatalogWritePort
{
    public PdoConnection $db;
    public int $writes = 0;
    public $onLock = null;
    public $onWrite = null;
    public $onCapture = null;
    public function __construct(PdoConnection $db) { $this->db = $db; }
    public function read(): array
    {
        $rows = json_decode($this->db->rows('SELECT body FROM qa_catalog')[0]['body'], true, 64, JSON_THROW_ON_ERROR);
        foreach ($rows as &$row) $row['values'] = (object)$row['values'];
        return $rows;
    }
    public function replace(array $rows): void { $this->db->execute('UPDATE qa_catalog SET body = ?', [json_encode($rows, JSON_THROW_ON_ERROR)]); }
    public function capture(object $publication, array $ids, bool $lock): array
    {
        if (!$this->db->inTransaction()) throw new RuntimeException('Capture outside transaction');
        if ($lock && $this->onLock) { $hook = $this->onLock; $this->onLock = null; $hook(); }
        $rows = array_values(array_filter($this->read(), static fn($row) => in_array($row['offerId'], $ids, true)));
        $result = ['authority' => (object)['provider' => 'bitrix:test', 'productsCatalog' => '14', 'offersCatalog' => '15',
            'schemaHash' => $this->db->rows('SELECT schema_hash FROM qa_catalog')[0]['schema_hash']], 'offers' => $rows];
        if ($this->onCapture) $result = ($this->onCapture)($result);
        return $result;
    }
    public function write(array $targets): void
    {
        if (!$this->db->inTransaction()) throw new RuntimeException('Write outside transaction');
        $this->writes++;
        $rows = $this->read();
        foreach ($targets as $target) {
            foreach ($rows as &$row) if ($row['offerId'] === $target['offerId']) $row['current'] = $target['state'];
            unset($row); $this->replace($rows);
            if ($this->onWrite) ($this->onWrite)();
        }
    }
}

function nativeWriteState(): array
{
    return ['purchasingPrice' => ['value' => 10, 'currency' => 'RUB'], 'dimensions' => ['width' => 1, 'length' => 1, 'height' => 1, 'weight' => 1],
        'prices' => [['typeId' => 1, 'quantityFrom' => null, 'quantityTo' => null, 'price' => 15, 'currency' => 'RUB'],
            ['typeId' => 99, 'quantityFrom' => null, 'quantityTo' => null, 'price' => 333, 'currency' => 'USD']]];
}
function nativeWriteQuote(): array
{
    return ['currency' => 'RUB', 'basePrice' => 100.1234567895, 'purchasingPrice' => 80,
        'parts' => [['outputs' => ['width' => 90, 'length' => 50, 'height' => 0.3, 'weight' => 2.543210987654321]]],
        'priceRanges' => [['quantityFrom' => 0, 'quantityTo' => 10, 'prices' => [['typeId' => 'retail', 'basePrice' => 120.1234567895, 'currency' => 'RUB']]],
            ['quantityFrom' => 11, 'quantityTo' => null, 'prices' => [['typeId' => 'retail', 'basePrice' => 110.12, 'currency' => 'RUB']]]]];
}
function nativeWriteSnapshot(array $row): string
{
    return json_encode(['contract' => 'prospektweb.calculator/site-publication-v1', 'documentId' => $row['id'], 'sourceRevision' => $row['revision'],
        'documentHash' => $row['bodyHash'], 'connectionHash' => $row['connectionHash'], 'connection' => json_decode($row['connectionJson']),
        'core' => ['contract' => 'prospektweb.calculator/publication-v1', 'calculatorId' => $row['id'], 'documentHash' => $row['bodyHash']],
        'runtime' => ['testOnly' => true]], JSON_THROW_ON_ERROR);
}
function nativeWriteFixture(): array
{
    $db = new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($db); DocumentSchema::install($db);
    $repo = new DocumentRepository($db, 'site:s1', 'user:1');
    $body = json_encode(['contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1, 'id' => 'sheet', 'name' => 'Sheet',
        'presentations' => ['views' => [['id' => 'BASE']]], 'pricing' => ['types' => [['id' => 'retail']]]], JSON_THROW_ON_ERROR);
    $pairs = []; foreach (DocumentOutputMappings::PAIRS as $source => $target) $pairs[] = (object)['source_path' => $source, 'target_path' => $target];
    $connection = (object)['contract' => SiteConnection::CONTRACT, 'provider' => 'bitrix:test', 'productsCatalog' => '14', 'offersCatalog' => '15',
        'products' => [(object)['key' => '42', 'presentationId' => 'BASE']], 'priceTypes' => [(object)['key' => '1', 'typeId' => 'retail']],
        'formBindings' => new stdClass(), 'inputMappings' => [], 'outputMappings' => $pairs];
    $repo->create($body); $draft = $repo->save('sheet', 1, $body, json_encode($connection, JSON_THROW_ON_ERROR), true);
    $published = $repo->publishSite('sheet', 2, null, nativeWriteSnapshot($draft));
    $db->execute('CREATE TABLE qa_catalog (body TEXT NOT NULL, schema_hash TEXT NOT NULL)');
    $rows = []; foreach ([101, 102] as $id) $rows[] = ['offerId' => $id, 'productId' => 42, 'name' => 'Offer ' . $id, 'values' => (object)['qty' => 100],
        'execution' => ['unitCount' => 100, 'runCount' => 1, 'layoutCount' => 1, 'deadlineType' => 'strict'], 'current' => nativeWriteState()];
    $db->execute('INSERT INTO qa_catalog (body, schema_hash) VALUES (?, ?)', [json_encode($rows, JSON_THROW_ON_ERROR), str_repeat('a', 64)]);
    $port = new NativeWriteFixturePort($db); $coreState = (object)['calls' => 0, 'hook' => null, 'quote' => nativeWriteQuote(), 'responseHook' => null];
    $core = static function (array $request) use ($coreState, $db): array {
        if ($db->inTransaction() || $request['action'] !== 'executeBatch' || count($request['executions']) > 20 || ($request['publication']->calculatorId ?? '') !== 'sheet') throw new RuntimeException('Core authority/transaction violation');
        $coreState->calls++;
        if ($coreState->hook) { $hook = $coreState->hook; $coreState->hook = null; $hook(); }
        $response = ['results' => array_map(static fn(array $row): array => ['id' => $row['id'], 'result' => $coreState->quote], $request['executions'])];
        return $coreState->responseHook ? ($coreState->responseHook)($response) : $response;
    };
    $service = new DocumentCatalogWriteService($db, 'site:s1', 'user:1', 'bitrix:test', $port, $core);
    $request = ['action' => 'previewCatalogWrite', 'id' => 'sheet', 'publicationId' => $published['id'], 'offerIds' => [102, 101]];
    return compact('db', 'repo', 'body', 'connection', 'published', 'port', 'coreState', 'core', 'service', 'request');
}
