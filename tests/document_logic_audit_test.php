<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentApplication.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentLogicAudit.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentRepository, DocumentApplication, DocumentVersions, DocumentLogicAudit};
$db = new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($db);
$repo = new DocumentRepository($db, 'site:test', 'user:1'); $calls = []; $during = null; $checks = 0;
$body = json_encode(['contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1, 'id' => 'sheet', 'name' => 'Sheet', 'form' => new stdClass()], JSON_THROW_ON_ERROR);
$core = static function (array $request): array { $json = json_encode($request['document'], JSON_THROW_ON_ERROR); return ['documentJson' => $json, 'documentHash' => hash('sha256', $json)]; };
$app = new DocumentApplication($repo, $core, static fn() => []); $app->command(['action' => 'create', 'documentJson' => $body]);
$version = DocumentVersions::primaryId('sheet');
$gateway = static function (array $request) use (&$calls, &$during): array {
    $calls[] = $request; if ($during) $during();
    return ['status' => 'ok', 'proposal' => ['schema' => 'prospektweb.calc.ai-logic-audit-proposal/v1', 'baseFingerprint' => $request['baseFingerprint'], 'summary' => 'Checked', 'suggestions' => []]];
};
$audit = new DocumentLogicAudit($repo, $gateway);
$command = ['action' => 'auditVersionLogic', 'id' => 'sheet', 'versionId' => $version, 'expectedRevision' => 1,
    'items' => [(object)['id' => 'global:amount', 'kind' => 'global', 'code' => 'amount', 'title' => 'Стоимость', 'description' => '', 'type' => 'number', 'formula' => '2 * 3', 'codeMutable' => true]],
    'intent' => '', 'contextTitle' => 'Глобальные значения'];
$check = static function (bool $ok, string $label) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($label); };
$rejects = static function (callable $fn, int $code = 0) use ($check): void {
    try { $fn(); } catch (Throwable $error) { $check($error->getCode() === $code, $error->getMessage()); return; }
    throw new RuntimeException('Expected rejection');
};
$before = $repo->versions()->load('sheet', $version); $history = $repo->history('sheet');
$response = $audit->command($command);
$check(count($calls) === 1 && array_keys($calls[0]) === ['schema', 'baseFingerprint', 'intent', 'contextTitle', 'items'], 'Gateway receives only audit data');
$check($calls[0]['items'][0]['title'] === 'Стоимость' && !isset($calls[0]['id'], $calls[0]['versionId']), 'Draft data preserved, repository identifiers omitted');
$check($response['baseFingerprint'] === 'sha256:' . hash('sha256', json_encode($command['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 'HTTP-safe server fingerprint binds exact draft');
$check($before === $repo->versions()->load('sheet', $version) && $history === $repo->history('sheet') && !$db->inTransaction(), 'Audit is read-only without holding transaction over network');
$calls = [];
$rejects(fn() => $audit->command($command + ['scope' => 'site:other']));
$rejects(fn() => $audit->command(array_replace($command, ['expectedRevision' => 2])), 409);
$rejects(fn() => (new DocumentLogicAudit(new DocumentRepository($db, 'site:other', 'user:2'), $gateway))->command($command), 404);
$rejects(fn() => $audit->command(array_replace($command, ['versionId' => 'foreign'])), 404);
$rejects(fn() => $audit->command(array_replace($command, ['expectedRevision' => '1'])));
$rejects(fn() => $audit->command(array_replace($command, ['items' => []])));
$check($calls === [], 'Unauthorized, stale and malformed calls never invoke gateway');
$rejects(fn() => (new DocumentLogicAudit($repo, static fn() => ['status' => 'ok', 'proposal' => ['baseFingerprint' => 'wrong']]))->command($command), 503);
$during = static function () use ($repo, $version, $body): void { $repo->versions()->save('sheet', $version, 1, str_replace('Sheet', 'Winner', $body), null, false); };
$rejects(fn() => $audit->command($command), 409);
$check(json_decode($repo->versions()->load('sheet', $version)['bodyJson'])->name === 'Winner', 'Late response cannot overwrite concurrent edit');
$entry = file_get_contents(dirname(__DIR__) . '/tools/documents.php');
$check(strpos($entry, "!check_bitrix_sessid()") < strpos($entry, "=== 'auditVersionLogic'") && strpos($entry, '!$USER->IsAdmin()') < strpos($entry, "=== 'auditVersionLogic'"), 'HTTP adapter authenticates admin and CSRF before audit');
echo "PASS $checks native logic audit assertions\n";
