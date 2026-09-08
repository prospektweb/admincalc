<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentApplication.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentRepository, DocumentApplication, DocumentVersions, SiteConnection};

// Application/CAS fixture. Native Bitrix semantic authority is verified separately.
$db = new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($db);
$repo = new DocumentRepository($db, 'site:test', 'user:1'); $checks = 0; $calls = []; $race = null;
$core = static function (array $request) use (&$calls): array {
    $calls[] = $request['action'];
    if ($request['action'] !== 'validate') throw new RuntimeException('Mapping check must not compile pricing');
    $json = json_encode($request['document'], JSON_THROW_ON_ERROR);
    return ['documentJson' => $json, 'documentHash' => hash('sha256', $json)];
};
$unavailable = static function (): array { throw new RuntimeException('Unexpected resource or publication access'); };
$warning = ['severity' => 'warning', 'code' => 'coverage', 'path' => 'form.fields', 'message' => 'Unmapped manual field'];
$validator = static function (object $document, object $connection) use (&$calls, &$race, $warning): array {
    $calls[] = ['mapping-check', $document->form->fields[0]->fieldId, $connection->inputMappings];
    if ($race) $race();
    if ($connection->provider !== 'bitrix:test') throw new InvalidArgumentException('Foreign provider');
    return [$warning];
};
$app = new DocumentApplication($repo, $core, $unavailable, $unavailable, $validator);
$body = json_encode(['contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1, 'id' => 'sheet', 'name' => 'Sheet',
    'form' => ['fields' => [['fieldId' => 'volume']]], 'presentations' => ['views' => []], 'pricing' => ['types' => []]], JSON_THROW_ON_ERROR);
$connection = json_encode(['contract' => SiteConnection::CONTRACT, 'provider' => 'bitrix:test', 'productsCatalog' => '14', 'offersCatalog' => '15',
    'products' => [], 'priceTypes' => [], 'formBindings' => new stdClass(), 'inputMappings' => [], 'outputMappings' => []], JSON_THROW_ON_ERROR);
$check = static function (bool $ok, string $label) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($label); };
$rejects = static function (callable $work, int $code = 0) use ($check): void {
    try { $work(); } catch (Throwable $error) { $check($error->getCode() === $code, $error->getMessage()); return; }
    throw new RuntimeException('Expected rejection');
};
$app->command(['action' => 'create', 'documentJson' => $body]);
$repo->save('sheet', 1, $body, $connection, true);
$version = DocumentVersions::primaryId('sheet');
$command = ['action' => 'checkInputMappings', 'id' => 'sheet', 'versionId' => $version, 'expectedRevision' => 2,
    'documentJson' => str_replace('volume', 'unsaved-volume', $body), 'connectionJson' => $connection];
$baseline = [$repo->load('sheet'), $repo->history('sheet'), $repo->versions()->listing('sheet')]; $calls = [];
$result = $app->command($command);
$check($result['contract'] === 'prospektweb.calculator/input-mapping-check-v1' && $result['valid'] && $result['issues'] === [$warning], 'Exact native check response and coverage issues');
$check($result['documentId'] === 'sheet' && $result['versionId'] === $version && $result['revision'] === 2, 'Selected revision evidence');
$check($result['bodyHash'] === hash('sha256', $command['documentJson']), 'Evidence hashes actual unsaved form');
$check($result['connectionHash'] === hash('sha256', SiteConnection::canonical($connection, json_decode($body, true))), 'Evidence hashes actual connection');
$check($calls === ['validate', ['mapping-check', 'unsaved-volume', []]], 'Only core validation and explicit input check, no resource/compile/site publication');
$check([$repo->load('sheet'), $repo->history('sheet'), $repo->versions()->listing('sheet')] === $baseline, 'No document/history/registry writes');
$calls = []; $rejects(fn() => $app->command(array_replace($command, ['expectedRevision' => 1])), 409);
$check($calls === [], 'Reject stale head before external work');
$foreign = new DocumentApplication(new DocumentRepository($db, 'site:other', 'user:2'), $core, $unavailable, $unavailable, $validator);
$rejects(fn() => $foreign->command($command), 404); $check($calls === [], 'Reject foreign site before external work');
$rejects(fn() => $app->command($command + ['preset_id' => 1]));
$rejects(fn() => $app->command($command + ['actor' => 'spoof']));
$rejects(fn() => $app->command(array_replace($command, ['documentJson' => str_replace('sheet', 'other', $body)])));
$rejects(fn() => $app->command(array_replace($command, ['connectionJson' => str_replace('bitrix:test', 'bitrix:other', $connection)])));
foreach ([null, '[]', '{}', '', 1] as $bad) $rejects(fn() => $app->command(array_replace($command, ['connectionJson' => $bad])));
$unconfigured = new DocumentApplication($repo, $core, $unavailable, $unavailable);
$rejects(fn() => $unconfigured->command($command), 503);
$check([$repo->load('sheet'), $repo->history('sheet'), $repo->versions()->listing('sheet')] === $baseline, 'Rejected requests leave storage intact');
$race = static function () use (&$race, $repo, $version, $body): void { $race = null; $repo->versions()->save('sheet', $version, 2, str_replace('Sheet', 'Winner', $body), null, false); };
$rejects(fn() => $app->command($command), 409);
$check(json_decode($repo->load('sheet')['bodyJson'])->name === 'Winner' && count($repo->history('sheet')) === count($baseline[1]) + 1, 'Late check detects concurrent save without overwriting winner');
echo "PASS $checks native input-mapping command assertions\n";
