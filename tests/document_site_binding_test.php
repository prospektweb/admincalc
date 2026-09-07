<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentRepository.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentRepository, SiteConnection};

$checks = 0;
function ok(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
function rejects(callable $operation, ?int $code = null): void {
    try { $operation(); } catch (Throwable $e) { ok($code === null || $code === $e->getCode(), $e->getMessage()); return; }
    throw new RuntimeException('Expected rejection');
}
function definition(string $id): string {
    return json_encode(['contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1, 'id' => $id, 'name' => $id,
        'presentations' => ['views' => [['id' => 'BASE']]], 'pricing' => ['types' => [['id' => 'retail']]]], JSON_THROW_ON_ERROR);
}
function connection(string $product = '42'): string {
    return json_encode(['contract' => SiteConnection::CONTRACT, 'provider' => 'bitrix:test', 'productsCatalog' => '14', 'offersCatalog' => '15',
        'products' => [['key' => $product, 'presentationId' => 'BASE']], 'priceTypes' => [['key' => '1', 'typeId' => 'retail']],
        'formBindings' => new stdClass(), 'inputMappings' => [], 'outputMappings' => []], JSON_THROW_ON_ERROR);
}
function snapshot(array $row): string {
    return json_encode(['contract' => 'prospektweb.calculator/site-publication-v1', 'documentId' => $row['id'], 'sourceRevision' => $row['revision'],
        'documentHash' => $row['bodyHash'], 'connectionHash' => $row['connectionHash'], 'connection' => json_decode($row['connectionJson']),
        'core' => ['contract' => 'prospektweb.calculator/publication-v1', 'calculatorId' => $row['id'], 'documentHash' => $row['bodyHash']],
        'runtime' => ['testOnly' => true]], JSON_THROW_ON_ERROR);
}
$db = new PdoConnection(new PDO('sqlite::memory:'));
DocumentSchema::install($db); DocumentSchema::install($db);
$repo = new DocumentRepository($db, 'site:s1', 'user:1');
$repo->create(definition('sheet'));
$draft = $repo->save('sheet', 1, definition('sheet'), connection(), true);
ok($draft['revision'] === 2 && $draft['connectionHash'] === hash('sha256', $draft['connectionJson']), 'Connection is an immutable aggregate revision');
ok($repo->load('sheet', 1)['connectionJson'] === null, 'Pre-migration revision retained');
ok($repo->productBinding('bitrix:test', '14', '42') === null, 'Draft cannot leak to public lookup');
ok($repo->save('sheet', 2, definition('sheet'), connection(), true)['revision'] === 2, 'Canonical no-op');
rejects(fn() => $repo->save('sheet', 1, definition('sheet'), connection('43'), true), 409);
$first = $repo->publishSite('sheet', 2, null, snapshot($draft));
ok($repo->productBinding('bitrix:test', '14', '42')['document_id'] === 'sheet', 'Active indexed binding');
ok($repo->productBinding('bitrix:other', '14', '42') === null, 'Provider isolation');
ok($repo->productBinding('bitrix:test', '15', '42') === null, 'Catalog isolation');
ok((new DocumentRepository($db, 'site:other', 'user:1'))->productBinding('bitrix:test', '14', '42') === null, 'Scope isolation');
rejects(fn() => $repo->publishSite('sheet', 2, null, snapshot($draft)), 409);
rejects(fn() => $repo->archive('sheet', 2, true), 409);
rejects(fn() => $repo->lockSitePublication('sheet', $first['id']));
$db->begin(); $repo->lockSitePublication('sheet', $first['id']); $db->commit();
$next = $repo->save('sheet', 2, definition('sheet'), connection('43'), true);
ok($repo->productBinding('bitrix:test', '14', '42') !== null, 'Draft reassignment preserves live product');
ok($repo->productBinding('bitrix:test', '14', '43') === null, 'New draft product not visible');
rejects(fn() => $repo->publishSite('sheet', 3, $first['id'], snapshot($draft)));
$second = $repo->publishSite('sheet', 3, $first['id'], snapshot($next));
ok($repo->productBinding('bitrix:test', '14', '42') === null && $repo->productBinding('bitrix:test', '14', '43')['publication_id'] === $second['id'], 'Atomic projection replacement');
$db->begin(); rejects(fn() => $repo->lockSitePublication('sheet', $first['id']), 409); $db->rollback();
$repo->create(definition('other'));
$other = $repo->save('other', 1, definition('other'), connection('43'), true);
rejects(fn() => $repo->publishSite('other', 2, null, snapshot($other)), 409);
ok($repo->productBinding('bitrix:test', '14', '43')['document_id'] === 'sheet', 'A second calculator cannot steal a product');
ok($repo->load('other')['activeSitePublication'] === null, 'Conflicting publication rolls back pointer');
ok(count($db->rows('SELECT id FROM b_pw_calc_site_publication WHERE document_id = ?', ['other'])) === 0, 'Conflicting publication rolls back immutable insert');
$changedDefinition = json_decode(definition('sheet'), true); $changedDefinition['presentations']['views'] = [];
rejects(fn() => $repo->save('sheet', 3, json_encode($changedDefinition)), null);
ok($repo->load('sheet')['revision'] === 3, 'Definition cannot orphan a presentation binding');
$bad = json_decode(connection()); $bad->products[] = clone $bad->products[0];
rejects(fn() => $repo->save('sheet', 3, definition('sheet'), json_encode($bad), true));
$bad = json_decode(connection()); $bad->extra = 'silent data';
rejects(fn() => $repo->save('sheet', 3, definition('sheet'), json_encode($bad), true));
$bad = json_decode(connection()); $bad->priceTypes[0]->typeId = 'missing';
rejects(fn() => $repo->save('sheet', 3, definition('sheet'), json_encode($bad), true));
$db->execute('UPDATE b_pw_calc_revision SET connection_json = ? WHERE document_id = ? AND revision = ?', ['{}', 'sheet', 3]);
rejects(fn() => $repo->load('sheet'));
echo "PASS $checks site binding checks\n";
