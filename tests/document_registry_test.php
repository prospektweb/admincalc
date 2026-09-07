<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentApplication.php';
use Prospektweb\Calc\Documents\{PdoConnection, SqlConnection, DocumentSchema, DocumentRepository, DocumentApplication};
$db = new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($db);
$repo = new DocumentRepository($db, 'site:registry', 'user:1');
$make = static fn(string $id, string $name): string => json_encode(['contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1, 'id' => $id, 'name' => $name], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
for ($n = 1; $n <= 65; $n++) { $repo->create($make(sprintf('doc-%03d', $n), sprintf('Калькулятор %03d', $n))); }
$repo->create($make('sheet', 'Листовая ПЕЧАТЬ'));
$repo->create($make('literal', '100%_! тест'));
$repo->archive('doc-001', 1, true);
(new DocumentRepository($db, 'site:foreign', 'user:2'))->create($make('foreign', 'Листовая печать'));
$db->execute('UPDATE b_pw_calc_document SET updated_at = ?, created_at = ? WHERE id = ?', ['2099-01-01T00:00:00Z', '2099-01-01T00:00:00Z', 'sheet']);
$db->execute('INSERT INTO b_pw_calc_site_publication (id, document_id, source_revision, snapshot_json, snapshot_hash, actor_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', ['site-pub', 'sheet', 1, '{}', hash('sha256', '{}'), 'user:1', '2099-01-01T00:00:00Z']);
$db->execute('INSERT INTO b_pw_calc_site_active (document_id, publication_id) VALUES (?, ?)', ['sheet', 'site-pub']);
$db->execute('INSERT INTO b_pw_calc_product_binding (scope_id, provider, catalog_key, product_key, document_id, publication_id, presentation_id) VALUES (?, ?, ?, ?, ?, ?, ?)', ['site:registry', 'provider', 'catalog', 'product', 'sheet', 'site-pub', 'BASE']);

// A metadata-only read must not touch graph payloads even when they are unavailable.
$guard = new class($db) implements SqlConnection {
    public int $reads = 0;
    public function __construct(private SqlConnection $inner) {}
    public function dialect(): string { return $this->inner->dialect(); }
    public function inTransaction(): bool { return $this->inner->inTransaction(); }
    public function begin(bool $readSnapshot = false): void { if (!$readSnapshot) { throw new RuntimeException('Registry did not request a read snapshot.'); } $this->inner->begin($readSnapshot); }
    public function commit(): void { $this->inner->commit(); }
    public function rollback(): void { $this->inner->rollback(); }
    public function execute(string $sql, array $parameters = []): void { throw new RuntimeException('Registry attempted a mutation.'); }
    public function rows(string $sql, array $parameters = []): array {
        $this->reads++;
        if (preg_match('/body_json|snapshot_json|b_pw_calc_revision|iblock/i', $sql)) { throw new RuntimeException('Registry attempted a graph/catalog read.'); }
        return $this->inner->rows($sql, $parameters);
    }
};
$never = static function (): never { throw new RuntimeException('Registry called remote core or resource provider.'); };
$app = new DocumentApplication(new DocumentRepository($guard, 'site:registry', 'user:1'), $never, $never);
$checks = 0;
function registry_check(bool $value, string $message): void { global $checks; $checks++; if (!$value) { throw new RuntimeException($message); } }
function registry_fails(callable $fn): void { try { $fn(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException('Invalid request accepted.'); }
$first = $app->command(['action' => 'registry']);
registry_check($first['total'] === 67 && count($first['rows']) === 30 && $first['pageCount'] === 3, 'Registry spans more than the old first 50 entries.');
registry_check($first['rows'][0]['id'] === 'sheet' && $first['rows'][0]['activeSitePublication'] === 'site-pub' && $first['rows'][0]['productCount'] === 1, 'Publication and product count are authoritative metadata.');
$next = $app->command(['action' => 'registry', 'page' => 2]);
registry_check(count(array_intersect(array_column($first['rows'], 'id'), array_column($next['rows'], 'id'))) === 0, 'Stable tie break avoids repeated page entries.');
$last = $app->command(['action' => 'registry', 'page' => 999]);
registry_check($last['page'] === 3 && count($last['rows']) === 7, 'Out-of-range pages are clamped after filtering.');
registry_check($app->command(['action' => 'registry', 'status' => 'active'])['total'] === 66, 'Active status filters metadata.');
registry_check($app->command(['action' => 'registry', 'status' => 'archived'])['rows'][0]['id'] === 'doc-001', 'Archived documents remain discoverable.');
registry_check($app->command(['action' => 'registry', 'query' => 'листовая печать'])['total'] === 1, 'Unicode case-insensitive search never crosses site scope.');
registry_check($app->command(['action' => 'registry', 'query' => '%_!'])['total'] === 1, 'LIKE wildcards in user input are literal.');
registry_check($app->command(['action' => 'registry', 'query' => 'doc-042'])['rows'][0]['name'] === 'Калькулятор 042', 'Identity search is supported.');
registry_check($app->command(['action' => 'registry', 'sort' => 'created_desc'])['rows'][0]['id'] === 'sheet', 'Creation ordering does not rely on random UUID order.');
registry_check($app->command(['action' => 'registry', 'sort' => 'name_asc'])['rows'][0]['id'] === 'literal', 'Ascending name sort.');
registry_check($app->command(['action' => 'registry', 'sort' => 'name_desc'])['rows'][0]['id'] === 'sheet', 'Descending name sort.');
$empty = $app->command(['action' => 'registry', 'query' => 'not found', 'page' => 20]);
registry_check($empty['rows'] === [] && $empty['total'] === 0 && $empty['page'] === 1 && $empty['pageCount'] === 1, 'Empty state has valid page bounds.');
foreach ([['sort' => 'name; DROP TABLE'], ['status' => 'published'], ['query' => str_repeat('я', 101)], ['query' => []], ['page' => '1'], ['page' => 0], ['pageSize' => 101], ['scope' => 'site:foreign']] as $invalid) { registry_fails(fn() => $app->command(['action' => 'registry'] + $invalid)); }
registry_check(!$db->inTransaction(), 'Read snapshots always close.');
echo "PASS $checks document registry checks\n";
