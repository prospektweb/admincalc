<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentApplication.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentRepository, DocumentApplication, DocumentVersions, SiteConnection};
$db = new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($db);
$repo = new DocumentRepository($db, 'site:test', 'user:1'); $resourceCalls = 0; $onCompile = null;
$core = static function (array $request) use (&$onCompile): array {
    $json = json_encode($request['document'], JSON_THROW_ON_ERROR);
    if ($request['action'] === 'validate') return ['documentJson' => $json, 'documentHash' => hash('sha256', $json)];
    if ($request['action'] === 'compile') {
        if ($onCompile !== null) $onCompile();
        return ['engineVersion' => 'test:1', 'snapshotJson' => json_encode(['contract' => 'prospektweb.calculator/publication-v1', 'calculatorId' => $request['document']->id,
            'engineVersion' => 'test:1', 'documentHash' => hash('sha256', $json), 'resources' => [], 'plan' => new stdClass()], JSON_THROW_ON_ERROR)];
    }
    return ['name' => $request['document']->name];
};
$app = new DocumentApplication($repo, $core, static function () use (&$resourceCalls): array { $resourceCalls++; return []; }, static fn() => ['testOnly' => true]);
$body = static fn(string $name): string => json_encode(['contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1, 'id' => 'sheet', 'name' => $name,
    'presentations' => ['views' => [['id' => 'BASE']]], 'pricing' => ['types' => [['id' => 'retail']]]], JSON_THROW_ON_ERROR);
$connection = json_encode(['contract' => SiteConnection::CONTRACT, 'provider' => 'bitrix:test', 'productsCatalog' => '14', 'offersCatalog' => '15',
    'products' => [['key' => '42', 'presentationId' => 'BASE']], 'priceTypes' => [['key' => '1', 'typeId' => 'retail']],
    'formBindings' => new stdClass(), 'inputMappings' => [], 'outputMappings' => []], JSON_THROW_ON_ERROR);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($message); };
$rejects = static function (callable $operation, int $code) use ($check): void { try { $operation(); } catch (Throwable $e) { $check($e->getCode() === $code, $e->getMessage()); return; } throw new RuntimeException('Expected rejection'); };
$cmd = static fn(string $action, array $fields = []): array => $app->command(['action' => $action, 'id' => 'sheet'] + $fields);
$registry = static fn(): array => $cmd('versions');
$change = static fn(string $action, string $versionId, array $fields = []): array => $cmd($action, ['versionId' => $versionId, 'expectedVersionsRevision' => $registry()['registryRevision']] + $fields);
$app->command(['action' => 'create', 'documentJson' => $body('Листовая печать')]);
$primary = DocumentVersions::primaryId('sheet');
$repo->save('sheet', 1, $body('Листовая печать'), $connection, true);
$cmd('publishSite', ['expectedRevision' => 2, 'expectedSitePublication' => null]);
$publication = $repo->sitePublication('sheet');
$preview = static fn(): array => $cmd('previewCalculatorLifecycle');
$toggle = static fn(bool $enabled): array => $cmd('setCalculatorEnabled', ['expectedLifecycleRevision' => $preview()['revision'], 'enabled' => $enabled]);
$before = $preview();
$check($before['counts']['versions'] === 1 && $before['counts']['products'] === 1, 'Preview exact dependencies');
$rejects(fn() => $cmd('deleteCalculator', ['expectedLifecycleRevision' => $before['revision'], 'confirmationName' => 'Wrong']), 0);
$check($preview() === $before, 'Wrong name leaves all state intact');
$toggle(false);
$rejects(fn() => $repo->sitePublication('sheet'), 404);
$check($repo->productBinding('bitrix:test', '14', '42') === null && $repo->siteListing() === [], 'Disabled calculator is unavailable through all public reads');
$db->begin(); $rejects(fn() => $repo->lockSitePublication('sheet', $publication['id']), 409); $db->rollback();
$check(count($db->rows('SELECT * FROM b_pw_calc_product_binding')) === 1, 'Disabled calculator retains product reservations');
$cmd('saveVersion', ['versionId' => $primary, 'expectedRevision' => 2, 'documentJson' => $body('Draft after disabling')]);
$toggle(true);
$check($repo->sitePublication('sheet') === $publication, 'Reactivation returns same publication, not new draft');
$rejects(fn() => $cmd('deleteCalculator', ['expectedLifecycleRevision' => $before['revision'], 'confirmationName' => $before['name']]), 409);
$foreign = new DocumentRepository($db, 'site:other', 'user:2');
$rejects(fn() => $foreign->lifecycle()->preview('sheet'), 404);
$beforeDelete = $registry();
$change('deleteVersion', $primary);
$check($registry()['versions'] === [] && $registry()['activePublication'] === null, 'Active version deletion removes publication pointer and version');
$check($db->rows('SELECT * FROM b_pw_calc_version') === [] && $db->rows('SELECT * FROM b_pw_calc_product_binding') === [], 'Actual rows and live bindings deleted');
$rejects(fn() => $repo->sitePublication('sheet'), 404);
DocumentSchema::install($db);
$check($registry()['versions'] === [], 'Installer does not resurrect deleted last version');
$blank = $cmd('createVersion', ['creationMode' => 'blank', 'name' => 'Second', 'documentJson' => $body('Blank'), 'expectedVersionsRevision' => $registry()['registryRevision']]);
$check($blank['versions'][0]['versionNo'] === 2, 'Version numbers are not reused');
$otherBody = json_decode($body('Other'), true); $otherBody['id'] = 'other'; $repo->create(json_encode($otherBody));
$db->execute('CREATE TABLE user_products (id INTEGER PRIMARY KEY, name TEXT)');
$db->execute('INSERT INTO user_products VALUES (42, ?)', ['Existing product']);
$receipt = '{"price":123}';
$db->execute('INSERT INTO b_pw_calc_catalog_write (id, scope_id, document_id, publication_id, actor_id, fingerprint, receipt_json, receipt_hash, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', ['receipt1','site:test','sheet',$publication['id'],'user:1',str_repeat('a',64),$receipt,hash('sha256',$receipt),gmdate('c')]);
$ready = $preview();
$db->execute("CREATE TRIGGER fail_cascade BEFORE DELETE ON b_pw_calc_version BEGIN SELECT RAISE(ABORT, 'Injected rollback'); END");
$auditBefore = $db->rows('SELECT * FROM b_pw_calc_deletion_audit');
try { $cmd('deleteCalculator', ['expectedLifecycleRevision' => $ready['revision'], 'confirmationName' => $ready['name']]); throw new RuntimeException('Expected injected failure'); }
catch (PDOException $error) { $check($preview() === $ready && $db->rows('SELECT * FROM b_pw_calc_deletion_audit') === $auditBefore, 'Mid-cascade failure rolls back rows and audit together'); }
$db->execute('DROP TRIGGER fail_cascade');

$result = $cmd('deleteCalculator', ['expectedLifecycleRevision' => $ready['revision'], 'confirmationName' => $ready['name']]);
$check($result['deleted'] === true, 'Calculator delete receipt');
foreach (['document','version','revision','publication','site_publication','site_active','site_identity','product_binding','catalog_write'] as $table) {
    $column = $table === 'document' ? 'id' : 'document_id';
    $check($db->rows("SELECT * FROM b_pw_calc_$table WHERE $column = ?", ['sheet']) === [], 'Physical cascade: ' . $table);
}
$check($repo->load('other')['revision'] === 1 && count($db->rows('SELECT * FROM user_products')) === 1, 'Other calculators and goods untouched');
$audit = $db->rows('SELECT body_json, body_hash FROM b_pw_calc_deletion_audit WHERE document_id = ? AND version_id IS NULL', ['sheet'])[0];
$retained = json_decode($audit['body_json'], true);
$check(hash('sha256',$audit['body_json']) === $audit['body_hash'] && count($retained['publications']) === 1 && count($retained['catalogReceipts']) === 1, 'Detached immutable evidence retained with integrity hash');
DocumentSchema::install($db); $rejects(fn() => $repo->load('sheet'), 404);
$db->execute("UPDATE b_pw_calc_document SET archived = 1 WHERE id = 'other'");
$db->execute("UPDATE b_pw_calc_version SET hidden = 1 WHERE document_id = 'other'");
$db->execute('ALTER TABLE b_pw_calc_document DROP COLUMN enabled');
DocumentSchema::install($db);
$migrated = $repo->lifecycle()->preview('other');
$check(!$migrated['enabled'] && !$repo->load('other')['archived'] && !$repo->versions()->listing('other')['versions'][0]['archived'], 'Former archived calculator and version become manageable while staying offline');
$repo->versions()->save('other', DocumentVersions::primaryId('other'), 1, json_encode($otherBody));
DocumentSchema::install($db);
$check($repo->lifecycle()->preview('other') === $migrated, 'Repeated upgrade preserves availability and versions');
echo "PASS document lifecycle: $checks checks\n";
