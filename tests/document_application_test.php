<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentApplication.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentRepository, DocumentApplication};
$db = new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($db);
$repo = new DocumentRepository($db, 'site:s1', 'user:1'); $calls = []; $resourceCalls = 0; $onCompile = null;
$core = static function (array $command) use (&$calls, &$onCompile): array {
    $calls[] = $command['action']; $json = json_encode($command['document'], JSON_THROW_ON_ERROR);
    if ($command['action'] === 'validate') { return ['documentJson' => $json, 'documentHash' => hash('sha256', $json)]; }
    if ($command['action'] === 'compile') {
        if ($onCompile) { $onCompile(); }
        return ['engineVersion' => 'test:1', 'snapshotJson' => json_encode(['contract' => 'prospektweb.calculator/publication-v1', 'calculatorId' => $command['document']->id,
            'engineVersion' => 'test:1', 'documentHash' => hash('sha256', $json), 'resources' => [], 'plan' => new stdClass()], JSON_THROW_ON_ERROR)];
    }
    return ['result' => []];
};
$app = new DocumentApplication($repo, $core, static function () use (&$resourceCalls): array { $resourceCalls++; return []; });
$checks = 0;
function check(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) { throw new RuntimeException($message); } }
function fails(callable $action, ?int $code = null): void {
    try { $action(); } catch (Throwable $e) { check($code === null || $e->getCode() === $code, $e->getMessage()); return; }
    throw new RuntimeException('Expected failure');
}
$json = json_encode(['contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1, 'id' => 'test', 'name' => 'Test', 'form' => new stdClass()], JSON_THROW_ON_ERROR);
$first = $app->command(['action' => 'create', 'documentJson' => $json]);
check($first['revision'] === 1 && $resourceCalls === 0, 'Create validates but never loads catalogs');
check(str_contains($first['bodyJson'], '"form":{}'), 'Empty objects preserved');
$app->command(['action' => 'load', 'id' => 'test']); $app->command(['action' => 'list']);
check($calls === ['validate'] && $resourceCalls === 0, 'Read has no remote core/catalog dependency');
fails(fn() => $app->command(['action' => 'save', 'id' => 'test', 'expectedRevision' => '1', 'documentJson' => $json]));
fails(fn() => $app->command(['action' => 'list', 'scope' => 'site:other']));
fails(fn() => $app->command(['action' => 'archive', 'id' => 'test', 'expectedRevision' => 1, 'archived' => 'N']));
$archived = $app->command(['action' => 'archive', 'id' => 'test', 'expectedRevision' => 1, 'archived' => true]);
check($archived['archived'] && $archived['revision'] === 2, 'Archive creates audit revision');
fails(fn() => $app->command(['action' => 'save', 'id' => 'test', 'expectedRevision' => 2, 'documentJson' => $json]), 409);
$app->command(['action' => 'archive', 'id' => 'test', 'expectedRevision' => 2, 'archived' => false]);
check(count($app->command(['action' => 'history', 'id' => 'test'])['items']) === 3, 'Archive/recovery history');
$onCompile = static function () use ($repo, $json) { $repo->save('test', 3, str_replace('"name":"Test"', '"name":"Changed"', $json)); };
fails(fn() => $app->command(['action' => 'publish', 'id' => 'test', 'expectedRevision' => 3, 'expectedPublication' => null]), 409);
check($repo->load('test')['activePublication'] === null, 'Concurrent edit prevents publication');
$onCompile = null;
$published = $app->command(['action' => 'publish', 'id' => 'test', 'expectedRevision' => 4, 'expectedPublication' => null]);
check($published['sourceRevision'] === 4, 'Exact revision published');
fails(fn() => $app->command(['action' => 'archive', 'id' => 'test', 'expectedRevision' => 4, 'archived' => true]), 409);
$restored = $app->command(['action' => 'restore', 'id' => 'test', 'expectedRevision' => 4, 'revision' => 1]);
check($restored['revision'] === 5 && $restored['bodyJson'] === $json, 'Restore appends new revision');
check($repo->publication('test')['sourceRevision'] === 4, 'Draft restore does not change publication');
$other = new DocumentRepository($db, 'site:other', 'user:2'); fails(fn() => $other->load('test'), 404);
echo "PASS $checks document application checks\n";
