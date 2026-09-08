<?php
declare(strict_types=1);
require_once __DIR__ . '/document_application_test.php';
use Prospektweb\Calc\Documents\{DocumentApplication, DocumentVersions};
$formCalls = 0; $duringForm = null; $duringPreview = null; $lastPreviewCommand = null;
$previewCore = static function (array $command) use (&$duringPreview, &$lastPreviewCommand): array {
    $lastPreviewCommand = $command;
    if ($duringPreview) $duringPreview();
    return ['result' => ['calculatorId' => $command['document']->id, 'values' => $command['values'], 'execution' => $command['execution']]];
};
$form = static function (object $document, int $revision) use (&$formCalls, &$duringForm): array {
    $formCalls++; if ($duringForm) $duringForm();
    return ['document' => $document, 'revision' => $revision];
};
$app = new DocumentApplication($repo, $previewCore, static fn() => [], null, null, null, $form);
$version = DocumentVersions::primaryId('test'); $baseline = $repo->versions()->load('test', $version);
$cmd = ['id' => 'test', 'versionId' => $version, 'revision' => $baseline['revision']];
$source = ['documentId' => 'test', 'versionId' => $version, 'revision' => $baseline['revision'], 'bodyHash' => $baseline['bodyHash']];
$formResult = $app->command(['action' => 'formVersion'] + $cmd);
check($formResult['source'] === $source && $formCalls === 1, 'Form pinned to saved revision without site connection');
$values = (object)['volume' => 123]; $execution = (object)['unitCount' => 123];
$result = $app->command(['action' => 'previewVersion', 'values' => $values, 'execution' => $execution] + $cmd);
check($result['source'] === $source && $result['result']['values'] === $values && $result['result']['execution'] === $execution, 'Preview carries exact source and explicit inputs');
check($lastPreviewCommand['includeReport'] === true, 'Only saved administrative preview opts into the execution report');
fails(fn() => $app->command(['action' => 'previewVersion', 'includeReport' => false] + $cmd));
check($repo->versions()->load('test', $version) === $baseline, 'Read-only preview leaves revision unchanged');
fails(fn() => $app->command(['action' => 'formVersion', 'scope' => 'site:other'] + $cmd));
fails(fn() => $app->command(['action' => 'formVersion', 'revision' => $baseline['revision'] - 1] + $cmd), 409);
check($formCalls === 1, 'Stale or spoofed form request never invokes compiler');
$foreign = new DocumentApplication($other, $previewCore, static fn() => [], null, null, null, $form);
fails(fn() => $foreign->command(['action' => 'formVersion'] + $cmd), 404);
$duringForm = static function () use ($repo, $version, $baseline, &$duringForm): void {
    $duringForm = null;
    $repo->versions()->save('test', $version, $baseline['revision'], str_replace('"name":"Test"', '"name":"Form race"', $baseline['bodyJson']), null, false);
};
fails(fn() => $app->command(['action' => 'formVersion'] + $cmd), 409);
$next = $repo->versions()->load('test', $version); $cmd['revision'] = $next['revision'];
$duringPreview = static function () use ($repo, $version, $next, &$duringPreview): void {
    $duringPreview = null;
    $repo->versions()->save('test', $version, $next['revision'], str_replace('Form race', 'Preview race', $next['bodyJson']), null, false);
};
fails(fn() => $app->command(['action' => 'previewVersion'] + $cmd), 409);
check(str_contains($repo->versions()->load('test', $version)['bodyJson'], 'Preview race'), 'Late preview cannot overwrite or relabel concurrent version');
echo "PASS $checks application and preview source assertions\n";
