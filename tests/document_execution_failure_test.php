<?php
declare(strict_types=1);
require_once __DIR__ . '/document_application_test.php';
use Prospektweb\Calc\Documents\{CoreExecutionFailure, DocumentApplication, DocumentVersions};
$failure = ['contract' => 'prospektweb.calculator/execution-failure-v1', 'code' => 'CALCULATOR_EXECUTION_INVALID',
    'message' => 'Formula failed', 'location' => ['stageId' => 'stage', 'calculationId' => 'selected-logic',
        'kind' => 'formula', 'code' => 'total', 'formulaId' => 'formula']];
check((new CoreExecutionFailure($failure))->failure === $failure, 'Exact bounded identity, no implicit numeric ids');
foreach ([
    static function (&$f) { $f['diagnostic'] = ['variables' => ['private' => 1]]; },
    static function (&$f) { $f['result'] = ['basePrice' => 1]; },
    static function (&$f) { $f['location']['variables'] = []; },
    static function (&$f) { unset($f['location']['formulaId']); },
    static function (&$f) { unset($f['location']['calculationId']); },
    static function (&$f) { $f['location']['kind'] = 'guess'; },
    static function (&$f) { $f['message'] = str_repeat('x', 8001); },
    static function (&$f) { $f['message'] = "unsafe\ncontrol"; },
    static function (&$f) { $f['contract'] = 'unknown'; },
] as $mutate) { $bad = $failure; $mutate($bad); fails(fn() => new CoreExecutionFailure($bad), 503); }
$duringFailure = null;
$core = static function () use ($failure, &$duringFailure): array {
    if ($duringFailure) $duringFailure();
    throw new CoreExecutionFailure($failure);
};
$app = new DocumentApplication($repo, $core, static fn() => []);
$version = DocumentVersions::primaryId('test'); $baseline = $repo->versions()->load('test', $version);
$cmd = ['id' => 'test', 'versionId' => $version, 'revision' => $baseline['revision']];
$response = $app->command(['action' => 'previewVersion'] + $cmd);
check($response === ['failure' => $failure, 'source' => ['documentId' => 'test', 'versionId' => $version,
    'revision' => $baseline['revision'], 'bodyHash' => $baseline['bodyHash']]], 'Failure is pinned to saved source; no result or partial prices');
check($repo->versions()->load('test', $version) === $baseline, 'Diagnostic never changes saved data');
fails(fn() => $app->command(['action' => 'preview', 'id' => 'test', 'revision' => $baseline['revision']]), 422);
$unavailable = new DocumentApplication($repo, static function (): array { throw new RuntimeException('Transport failed', 503); }, static fn() => []);
fails(fn() => $unavailable->command(['action' => 'previewVersion'] + $cmd), 503);
$duringFailure = static function () use ($repo, $version, $baseline): void {
    $repo->versions()->save('test', $version, $baseline['revision'], str_replace('"name":"Test"', '"name":"Failure race"', $baseline['bodyJson']), null, false);
};
fails(fn() => $app->command(['action' => 'previewVersion'] + $cmd), 409);
check(str_contains($repo->versions()->load('test', $version)['bodyJson'], 'Failure race'), 'Concurrent edit invalidates failure as well as success');
echo "PASS $checks native execution failure assertions\n";
