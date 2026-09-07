<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentApplication.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentRepository, DocumentApplication, DocumentVersions, SiteConnection};

// Storage/application-boundary fixture. Real engine/pilot tests are separate.
$db = new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($db);
$repo = new DocumentRepository($db, 'site:test', 'user:1');
$onValidate = null; $externalCalls = 0; $checks = 0;
$core = static function (array $request) use (&$onValidate): array {
    if ($request['action'] !== 'validate') throw new RuntimeException('Unexpected engine operation');
    if ($onValidate) $onValidate();
    if ($request['document']->name === 'Invalid') throw new InvalidArgumentException('Invalid fixture body');
    $json = json_encode($request['document'], JSON_THROW_ON_ERROR);
    return ['documentJson' => $json, 'documentHash' => hash('sha256', $json)];
};
$resources = static function () use (&$externalCalls): array { $externalCalls++; return []; };
$app = new DocumentApplication($repo, $core, $resources);
$body = static fn(string $name, string $view): string => json_encode([
    'contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1, 'id' => 'sheet', 'name' => $name,
    'form' => ['fields' => [['fieldId' => 'method', 'label' => $name]], 'sections' => []],
    'presentations' => ['views' => [['id' => $view]]], 'pricing' => ['types' => [['id' => 'retail']]],
], JSON_THROW_ON_ERROR);
$connection = static fn(string $view): string => json_encode([
    'contract' => SiteConnection::CONTRACT, 'provider' => 'bitrix:test', 'productsCatalog' => '14', 'offersCatalog' => '15',
    'products' => [['key' => '42', 'presentationId' => $view]], 'priceTypes' => [['key' => '1', 'typeId' => 'retail']],
    'formBindings' => new stdClass(), 'inputMappings' => [], 'outputMappings' => [],
], JSON_THROW_ON_ERROR);
$check = static function (bool $ok, string $label) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($label); };
$rejects = static function (callable $operation, int $code = 0) use ($check): void {
    try { $operation(); } catch (Throwable $e) { $check($e->getCode() === $code, $e->getMessage()); return; }
    throw new RuntimeException('Expected rejection');
};
$app->command(['action' => 'create', 'documentJson' => $body('Original', 'old-view')]);
$primary = DocumentVersions::primaryId('sheet');
$repo->save('sheet', 1, $body('Original', 'old-view'), $connection('old-view'), true);
$state = $repo->versions()->listing('sheet');
$branch = $repo->versions()->create('sheet', $state['registryRevision'], 'Form editing', $primary, $state['versions'][0]['workContentHash'], null)['createdVersionId'];
$load = static fn(): array => $repo->versions()->load('sheet', $branch);
$save = static fn(int $revision, string $json, array $extra = []): array => $app->command([
    'action' => 'saveVersion', 'id' => 'sheet', 'versionId' => $branch, 'expectedRevision' => $revision, 'documentJson' => $json,
] + $extra);
$baseline = $load(); $primaryBefore = $repo->load('sheet');
// Neither half can be saved alone: each refers to a different view identity.
$rejects(fn() => $save(2, $body('Edited', 'new-view')));
$rejects(fn() => $app->command(['action' => 'saveVersionConnection', 'id' => 'sheet', 'versionId' => $branch, 'expectedRevision' => 2, 'connectionJson' => $connection('new-view')]));
$check($load() === $baseline, 'Invalid individual halves must not create a partial draft');
$pair = $save(2, $body('Edited', 'new-view'), ['connectionJson' => $connection('new-view')]);
$check($pair['revision'] === 3 && json_decode($pair['bodyJson'])->name === 'Edited'
    && json_decode($pair['connectionJson'])->products[0]->presentationId === 'new-view', 'A complete pair advances exactly one revision');
$check($repo->load('sheet') === $primaryBefore
    && $repo->productBinding('bitrix:test', '14', '42') === null, 'Pair save changes neither other branch nor public bindings');
$rejects(fn() => $repo->sitePublication('sheet'), 404);
$state = $repo->versions()->listing('sheet'); $count = count($repo->history('sheet'));
$again = $save(3, $body('Edited', 'new-view'), ['connectionJson' => $connection('new-view')]);
$check($again === $pair && count($repo->history('sheet')) === $count && $repo->versions()->listing('sheet') === $state, 'Identical pair is a no-op');
$rejects(fn() => $save(2, $body('Stale', 'old-view'), ['connectionJson' => $connection('old-view')]), 409);
$rejects(fn() => $save(3, $body('Invalid', 'new-view'), ['connectionJson' => $connection('new-view')]));
foreach ([null, '', '{}', [], 123] as $invalid) $rejects(fn() => $save(3, $body('Invalid connection', 'new-view'), ['connectionJson' => $invalid]));
$rejects(fn() => $save(3, $body('Spoof', 'new-view'), ['connectionJson' => $connection('new-view'), 'actor' => 'admin']));
$check($load() === $pair && $repo->versions()->listing('sheet') === $state && count($repo->history('sheet')) === $count, 'Every invalid request preserves both halves and revision counters');
$foreign = new DocumentApplication(new DocumentRepository($db, 'site:other', 'user:2'), $core, $resources);
$rejects(fn() => $foreign->command(['action' => 'saveVersion', 'id' => 'sheet', 'versionId' => $branch, 'expectedRevision' => 3,
    'documentJson' => $body('Foreign', 'new-view'), 'connectionJson' => $connection('new-view')]), 404);
$db->execute("CREATE TRIGGER fail_bundle BEFORE UPDATE ON b_pw_calc_version BEGIN SELECT RAISE(ABORT, 'bundle head fault'); END");
try { $save(3, $body('Rollback', 'third-view'), ['connectionJson' => $connection('third-view')]); throw new LogicException('Missing fault'); } catch (PDOException $e) {}
$db->execute('DROP TRIGGER fail_bundle');
$check($load() === $pair && $repo->versions()->listing('sheet') === $state && count($repo->history('sheet')) === $count, 'Head update fault rolls back body, connection and revision insert');
// A write arriving while core validation runs must win; the submitted pair is stale.
$onValidate = static function () use (&$onValidate, $save, $body, $connection): void {
    $onValidate = null; $save(3, $body('Concurrent winner', 'winner-view'), ['connectionJson' => $connection('winner-view')]);
};
$rejects(fn() => $save(3, $body('Late validation', 'loser-view'), ['connectionJson' => $connection('loser-view')]), 409);
$winner = $load();
$check(json_decode($winner['bodyJson'])->name === 'Concurrent winner'
    && json_decode($winner['connectionJson'])->products[0]->presentationId === 'winner-view', 'Validation-time race cannot mix pairs');
$bodyOnly = $save($winner['revision'], $body('Body-only still supported', 'winner-view'));
$check($bodyOnly['connectionJson'] === $winner['connectionJson'], 'Omitted connection preserves the existing adapter configuration');
$check($externalCalls === 0 && !$db->inTransaction(), 'Save never fetches resources and closes every transaction');
echo "PASS $checks atomic document version bundle checks\n";
