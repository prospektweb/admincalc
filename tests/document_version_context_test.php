<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentApplication.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentRepository, DocumentApplication, DocumentVersions};

// Storage/application fixture; real Bitrix resource authority is covered separately.
$db = new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($db);
$repo = new DocumentRepository($db, 'site:test', 'user:1'); $calls = []; $duringRead = null; $checks = 0;
$core = static function (array $request) use (&$calls): array {
    $calls[] = $request['action'];
    if ($request['document']->name === 'Invalid') throw new InvalidArgumentException('Invalid body');
    $json = json_encode($request['document'], JSON_THROW_ON_ERROR);
    return ['documentJson' => $json, 'documentHash' => hash('sha256', $json)];
};
$provider = static function (object $document) use (&$calls, &$duringRead): array {
    $calls[] = 'resources';
    if ($duringRead) $duringRead();
    if (($document->resources[0]->binding->provider ?? '') !== 'bitrix:test') throw new InvalidArgumentException('Foreign resource');
    return [['id' => $document->resources[0]->id, 'name' => 'Actual resource', 'binding' => $document->resources[0]->binding]];
};
$app = new DocumentApplication($repo, $core, $provider);
$body = static fn(string $name, string $resource = 'paper'): string => json_encode([
    'contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1, 'id' => 'sheet', 'name' => $name,
    'form' => new stdClass(), 'resources' => [['id' => $resource, 'binding' => ['provider' => 'bitrix:test', 'catalog' => 'CALC_MATERIALS', 'key' => '7']]],
], JSON_THROW_ON_ERROR);
$check = static function (bool $ok, string $label) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($label); };
$rejects = static function (callable $operation, int $code = 0) use ($check): void {
    try { $operation(); } catch (Throwable $e) { $check($e->getCode() === $code, $e->getMessage()); return; }
    throw new RuntimeException('Expected rejection');
};
$app->command(['action' => 'create', 'documentJson' => $body('Original')]);
$version = DocumentVersions::primaryId('sheet');
$command = static fn(int $revision, string $json): array => ['action' => 'contextVersion', 'id' => 'sheet', 'versionId' => $version,
    'expectedRevision' => $revision, 'documentJson' => $json];
$baseline = $repo->load('sheet'); $history = $repo->history('sheet'); $listing = $repo->versions()->listing('sheet'); $calls = [];
$draft = $body('Unsaved', 'draft-paper'); $result = $app->command($command(1, $draft));
$check($result['contract'] === 'prospektweb.calculator/resource-context-v1' && $result['documentId'] === 'sheet' && $result['versionId'] === $version && $result['revision'] === 1, 'Exact scope and revision returned');
$check($result['bodyHash'] === hash('sha256', $draft) && $result['resources'][0]['id'] === 'draft-paper', 'Context uses submitted validated draft');
$check($calls === ['validate', 'resources'], 'Context does not compile or access site connection');
$check($repo->load('sheet') === $baseline && $repo->history('sheet') === $history && $repo->versions()->listing('sheet') === $listing, 'Read leaves body, history and versions unchanged');
$rejects(fn() => $repo->sitePublication('sheet'), 404);
$calls = []; $rejects(fn() => $app->command($command(0, $draft)), 409);
$check($calls === [], 'Stale request performs no external work');
$foreign = new DocumentApplication(new DocumentRepository($db, 'site:other', 'user:2'), $core, $provider);
$rejects(fn() => $foreign->command($command(1, $draft)), 404);
$check($calls === [], 'Other site cannot query context');
$wrong = json_decode($draft); $wrong->id = 'other';
$rejects(fn() => $app->command($command(1, json_encode($wrong))));
$rejects(fn() => $app->command($command(1, $body('Invalid'))));
$wrong = json_decode($draft); $wrong->resources[0]->binding->provider = 'foreign';
$rejects(fn() => $app->command($command(1, json_encode($wrong))));
$rejects(fn() => $app->command($command(1, $draft) + ['scope' => 'site:other']));
$rejects(fn() => $app->command($command(1, $draft) + ['connectionJson' => '{}']));
$check($repo->load('sheet') === $baseline && !$db->inTransaction(), 'Failures leave storage intact');
$duringRead = static function () use (&$duringRead, $repo, $version, $body): void {
    $duringRead = null; $repo->versions()->save('sheet', $version, 1, $body('Winner'), null, false);
};
$rejects(fn() => $app->command($command(1, $draft)), 409);
$check(json_decode($repo->load('sheet')['bodyJson'])->name === 'Winner' && count($repo->history('sheet')) === count($history) + 1, 'Late stale context rejected without overwriting concurrent winner');
echo "PASS $checks read-only version context assertions\n";
