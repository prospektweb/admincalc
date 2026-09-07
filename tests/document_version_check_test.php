<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentApplication.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentRepository, DocumentApplication, DocumentVersions, SiteConnection};

// Application/storage fixture, deliberately separate from real core + Bitrix QA.
$db = new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($db);
$repo = new DocumentRepository($db, 'site:test', 'user:1'); $checks = 0; $calls = []; $duringCompile = null;
$core = static function (array $request) use (&$calls, &$duringCompile): array {
    $calls[] = $request['action'];
    if ($request['document']->name === 'Invalid') throw new InvalidArgumentException('Invalid body');
    if ($request['action'] === 'compile') {
        if ($duringCompile) $duringCompile();
        if ($request['document']->name === 'Compile error') throw new InvalidArgumentException('Invalid expression');
        return ['compiled' => true];
    }
    $json = json_encode($request['document'], JSON_THROW_ON_ERROR);
    return ['documentJson' => $json, 'documentHash' => hash('sha256', $json)];
};
$resources = static function () use (&$calls): array { $calls[] = 'resources'; return []; };
$compiler = static function (object $document, object $connection, int $revision) use (&$calls): array {
    $calls[] = 'site';
    if ($connection->provider !== 'bitrix:test') throw new InvalidArgumentException('Foreign provider');
    return ['revision' => $revision, 'label' => $document->name];
};
$app = new DocumentApplication($repo, $core, $resources, $compiler);
$body = static fn(string $name, string $view = 'view'): string => json_encode([
    'contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1, 'id' => 'sheet', 'name' => $name,
    'form' => ['fields' => [['fieldId' => 'method', 'label' => $name]], 'sections' => []],
    'presentations' => ['views' => [['id' => $view]]], 'pricing' => ['types' => [['id' => 'retail']]],
], JSON_THROW_ON_ERROR);
$connection = static fn(string $view = 'view'): string => json_encode([
    'contract' => SiteConnection::CONTRACT, 'provider' => 'bitrix:test', 'productsCatalog' => '14', 'offersCatalog' => '15',
    'products' => [['key' => '42', 'presentationId' => $view]], 'priceTypes' => [['key' => '1', 'typeId' => 'retail']],
    'formBindings' => new stdClass(), 'inputMappings' => [], 'outputMappings' => [],
], JSON_THROW_ON_ERROR);
$check = static function (bool $ok, string $label) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($label); };
$rejects = static function (callable $operation, int $code = 0) use ($check): void {
    try { $operation(); } catch (Throwable $e) { $check($e->getCode() === $code, $e->getMessage()); return; }
    throw new RuntimeException('Expected rejection');
};
$app->command(['action' => 'create', 'documentJson' => $body('Original')]);
$versionId = DocumentVersions::primaryId('sheet');
$command = static fn(int $revision, string $json, array $extra = []): array => ['action' => 'checkVersion', 'id' => 'sheet', 'versionId' => $versionId,
    'expectedRevision' => $revision, 'documentJson' => $json] + $extra;
$rejects(fn() => $app->command($command(1, $body('No connection'))));
$repo->save('sheet', 1, $body('Original'), $connection(), true);
$baseline = $repo->load('sheet'); $versions = $repo->versions()->listing('sheet'); $history = $repo->history('sheet'); $calls = [];
$candidate = $body('Unsaved form', 'draft-view'); $pair = $connection('draft-view');
$result = $app->command($command(2, $candidate, ['connectionJson' => $pair]));
$check($result['valid'] && $result['documentId'] === 'sheet' && $result['versionId'] === $versionId && $result['revision'] === 2, 'Check identifies exact selected branch revision');
$check($result['runtime']['label'] === 'Unsaved form' && $result['runtime']['revision'] === 2, 'Compiler sees submitted draft, not stored body');
$check($result['bodyHash'] === hash('sha256', $candidate) && $result['connectionHash'] === hash('sha256', SiteConnection::canonical($pair, json_decode($candidate, true))), 'Hash evidence identifies both checked halves');
$check($calls === ['validate', 'site', 'resources', 'compile'], 'Only explicit check fetches resources and compiles both surfaces');
$app->command($command(2, $body('Body-only check')));
$check($repo->load('sheet') === $baseline && $repo->versions()->listing('sheet') === $versions && $repo->history('sheet') === $history, 'Checking never saves, inserts history or updates version metadata');
$check($repo->productBinding('bitrix:test', '14', '42') === null, 'Checking never activates product bindings');
$rejects(fn() => $repo->sitePublication('sheet'), 404);
$calls = []; $rejects(fn() => $app->command($command(1, $candidate, ['connectionJson' => $pair])), 409);
$check($calls === [], 'Stale revision rejected before external work');
$foreign = new DocumentApplication(new DocumentRepository($db, 'site:other', 'user:2'), $core, $resources, $compiler);
$rejects(fn() => $foreign->command($command(2, $candidate, ['connectionJson' => $pair])), 404);
$check($calls === [], 'Cross-site lookup rejected before external work');
$wrong = json_decode($body('Wrong identity')); $wrong->id = 'other';
$rejects(fn() => $app->command($command(2, json_encode($wrong))));
$rejects(fn() => $app->command($command(2, $body('Invalid'))));
$rejects(fn() => $app->command($command(2, $body('Compile error'))));
foreach ([null, '', '{}', [], 1] as $bad) $rejects(fn() => $app->command($command(2, $body('Bad connection'), ['connectionJson' => $bad])));
$rejects(fn() => $app->command($command(2, $candidate, ['connectionJson' => $pair, 'actor' => 'spoof'])));
$rejects(fn() => $app->command($command(2, $candidate, ['connectionJson' => $connection()])));
$withoutCompiler = new DocumentApplication($repo, $core, $resources);
$rejects(fn() => $withoutCompiler->command($command(2, $body('No compiler'))), 503);
$check($repo->load('sheet') === $baseline && $repo->history('sheet') === $history && !$db->inTransaction(), 'Failed checks leave storage and transaction state intact');
$duringCompile = static function () use (&$duringCompile, $repo, $versionId, $body): void {
    $duringCompile = null; $repo->versions()->save('sheet', $versionId, 2, $body('Concurrent winner'), null, false);
};
$rejects(fn() => $app->command($command(2, $body('Late check'))), 409);
$check(json_decode($repo->load('sheet')['bodyJson'])->name === 'Concurrent winner' && count($repo->history('sheet')) === count($history) + 1, 'Late check detects concurrent head without adding its candidate');
echo "PASS $checks read-only document version check assertions\n";
