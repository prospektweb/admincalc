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
$app->command(['action' => 'create', 'documentJson' => $body('Original')]);
$primary = DocumentVersions::primaryId('sheet'); $initial = $registry();
$check(count($initial['versions']) === 1 && $initial['versions'][0]['versionId'] === $primary && $initial['versions'][0]['versionNo'] === 1, 'Creation initializes one named version');
$repo->save('sheet', 1, $body('Original'), $connection, true);
$check($registry()['versions'][0]['headRevision'] === 2 && $resourceCalls === 0, 'Default saves update the first head without external I/O');
$cmd('publishSite', ['expectedRevision' => 2, 'expectedSitePublication' => null]);
$publication = $repo->sitePublication('sheet'); $original = $repo->load('sheet'); $beforeCalls = $resourceCalls;
$check($registry()['activeVersionId'] === $primary && !$registry()['versions'][0]['hasUnactivatedChanges'], 'Existing site publication belongs to first named version');
$clone = static function (string $source, string $name) use ($cmd, $registry): string {
    $state = $registry(); $row = array_column($state['versions'], null, 'versionId')[$source];
    return $cmd('createVersion', ['creationMode' => 'clone', 'name' => $name, 'basedOnVersionId' => $source,
        'expectedContentHash' => $row['workContentHash'], 'expectedVersionsRevision' => $state['registryRevision']])['createdVersionId'];
};
$branch = $clone($primary, 'Alternative'); $fork = $cmd('loadVersion', ['versionId' => $branch]);
$check($fork['bodyJson'] === $original['bodyJson'] && $fork['connectionJson'] === $original['connectionJson'] && $fork['revision'] === 2, 'Clone shares complete immutable content initially');
$beforeSaveRegistry = $registry()['registryRevision'];
$changed = $cmd('saveVersion', ['versionId' => $branch, 'expectedRevision' => 2, 'documentJson' => $body('Alternative')]);
$check($changed['saveReceipt'] === ['fromRevision' => 2, 'fromRegistryRevision' => $beforeSaveRegistry, 'toRevision' => 3, 'toRegistryRevision' => $beforeSaveRegistry + 1], 'Save receipt captures both locked revision pairs atomically');
$check($changed['saveReceipt']['toRegistryRevision'] === $registry()['registryRevision'], 'Receipt is the resulting registry pointer, not a frontend counter guess');
$same = $cmd('saveVersion', ['versionId' => $branch, 'expectedRevision' => 3, 'documentJson' => $body('Alternative')]);
$check($same['saveReceipt'] === ['fromRevision' => 3, 'fromRegistryRevision' => $beforeSaveRegistry + 1, 'toRevision' => 3, 'toRegistryRevision' => $beforeSaveRegistry + 1], 'No-op save receipts do not advance either revision');
$check(!isset($cmd('loadVersion', ['versionId' => $branch])['saveReceipt']), 'Load never manufactures or replays a former save receipt');
$check($changed['revision'] === 3 && $repo->load('sheet')['revision'] === 2 && $repo->load('sheet')['bodyHash'] === $original['bodyHash'], 'Editing a fork does not overwrite default branch');
$check($repo->sitePublication('sheet') === $publication && $repo->productBinding('bitrix:test', '14', '42')['publication_id'] === $publication['id'], 'Draft edits never switch site or product bindings');
$rootChanged = $repo->save('sheet', 2, $body('Root edited'));
$check($rootChanged['revision'] === 4 && $cmd('loadVersion', ['versionId' => $branch])['revision'] === 3, 'Global allocation prevents root/branch revision collisions');
$check($resourceCalls === $beforeCalls, 'Registry, clone and saves do not load external resources');
$rejects(fn() => $cmd('saveVersion', ['versionId' => $branch, 'expectedRevision' => 2, 'documentJson' => $body('Stale')]), 409);
$rejects(fn() => $cmd('createVersion', ['creationMode' => 'clone', 'name' => 'Stale clone', 'basedOnVersionId' => $branch, 'expectedContentHash' => str_repeat('a', 64), 'expectedVersionsRevision' => $registry()['registryRevision']]), 409);
$activate = static function (string $versionId) use ($cmd, $registry): array {
    $state = $registry(); $row = array_column($state['versions'], null, 'versionId')[$versionId];
    return $cmd('activateVersion', ['versionId' => $versionId, 'expectedRevision' => $row['headRevision'], 'expectedVersionsRevision' => $state['registryRevision'], 'expectedSitePublication' => $state['activePublication']]);
};
$active = $activate($branch);
$check($active['activeVersionId'] === $branch && count(array_filter($active['versions'], static fn(array $v): bool => $v['active'])) === 1, 'Activation switches exact named branch');
$check($repo->sitePublication('sheet')['sourceRevision'] === 3 && $repo->load('sheet')['revision'] === 4, 'Site executes branch snapshot, not default working pointer');
$rejects(fn() => $change('deleteVersion', $branch), 409);
$rejects(fn() => $change('archiveVersion', $branch, ['archived' => true]), 409);
$identical = $clone($branch, 'Same content'); $before = $repo->sitePublication('sheet');
$active = $activate($identical);
$check($repo->sitePublication('sheet') === $before && $active['activeVersionId'] === $identical && count(array_filter($active['versions'], static fn(array $v): bool => $v['active'])) === 1, 'Identical snapshots still have exactly one explicit active version');
$versionBefore = $cmd('loadVersion', ['versionId' => $branch]);
$rejects(fn() => $cmd('saveVersionConnection', ['versionId' => $branch, 'expectedRevision' => 3, 'connectionJson' => '{}']), 0);
$check($cmd('loadVersion', ['versionId' => $branch]) === $versionBefore, 'Invalid bindings do not change the branch');
$change('archiveVersion', $branch, ['archived' => true]);
$rejects(fn() => $activate($branch), 409);
$change('archiveVersion', $branch, ['archived' => false]);
$onCompile = static function () use ($change, $branch): void { $change('renameVersion', $branch, ['name' => 'Changed during compile']); };
$rejects(fn() => $activate($branch), 409); $onCompile = null;
$check($repo->sitePublication('sheet') === $before && $registry()['activeVersionId'] === $identical, 'Compile-time CAS conflict preserves publication and active version together');
$change('deleteVersion', $primary);
$rejects(fn() => $cmd('loadVersion', ['versionId' => $primary]), 404);
$rejects(fn() => $repo->save('sheet', 4, $body('Deleted default write')), 404);
$blank = $cmd('createVersion', ['creationMode' => 'blank', 'name' => 'Blank', 'documentJson' => $body('Blank'), 'expectedVersionsRevision' => $registry()['registryRevision']]);
$row = array_column($blank['versions'], null, 'versionId')[$blank['createdVersionId']];
$check($row['versionNo'] === 4 && $cmd('loadVersion', ['versionId' => $row['versionId']])['connectionJson'] === null, 'Blank version has independent empty bindings and numbers are never reused');
$rejects(fn() => $activate($row['versionId']), 0);
$check($repo->sitePublication('sheet') === $before, 'Incomplete activation leaves site intact');
$restored = $cmd('restoreVersionRevision', ['versionId' => $branch, 'expectedRevision' => 3, 'revision' => 2]);
$check($restored['revision'] > 5 && $restored['bodyJson'] === $original['bodyJson'] && $restored['connectionJson'] === $original['connectionJson'], 'Historical restore creates a new head of the selected branch including its connections');
$check($repo->load('sheet')['revision'] === 4 && $repo->sitePublication('sheet') === $before, 'Restoring a branch leaves the default and public snapshot intact');
$check($cmd('previewVersion', ['versionId' => $branch, 'revision' => $restored['revision']])['name'] === 'Original', 'Preview reads selected branch');
$rejects(fn() => $cmd('previewVersion', ['versionId' => $branch, 'revision' => 3]), 409);
$foreign = new DocumentRepository($db, 'site:other', 'user:2');
$rejects(fn() => $foreign->versions()->listing('sheet'), 404);
$rejects(fn() => $foreign->versions()->load('sheet', $branch), 404);
$rejects(fn() => $foreign->versions()->save('sheet', $branch, 3, $body('Foreign')), 404);
foreach ([['action' => 'versions', 'id' => 'sheet', 'actor' => 'root'], ['action' => 'createVersion', 'id' => 'sheet', 'creationMode' => 'blank', 'basedOnVersionId' => $branch, 'name' => 'Invalid'], ['action' => 'deleteVersion', 'id' => 'sheet', 'versionId' => $branch, 'expectedVersionsRevision' => '1'] ] as $invalid) { $rejects(fn() => $app->command($invalid), 0); }
$snapshot = $registry(); DocumentSchema::install($db); DocumentSchema::install($db);
$check($registry() === $snapshot && $repo->sitePublication('sheet') === $before, 'Repeated installation neither resurrects deleted versions nor switches site');
$check(!$db->inTransaction(), 'Transactions close on all success and error paths');
// An interrupted schema-3 bootstrap must be resumable without changing bodies.
$migration = new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($migration);
$migrationRepo = new DocumentRepository($migration, 'site:test', 'user:1'); $migrationRepo->create($body('Legacy'));
$migration->execute('DELETE FROM b_pw_calc_version');
$migration->execute('UPDATE b_pw_calc_document SET versions_revision = 0');
$baseline = $migrationRepo->load('sheet'); DocumentSchema::install($migration);
$check($migrationRepo->load('sheet') === $baseline && count($migrationRepo->versions()->listing('sheet')['versions']) === 1, 'Schema bootstrap only adds metadata');
DocumentSchema::install($migration);
$check(count($migrationRepo->versions()->listing('sheet')['versions']) === 1, 'Metadata bootstrap is idempotent');
echo "PASS $checks named document version checks\n";
