<?php
/** CLI-only, pinned pilot update and signed production smoke test. No public cutover. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? ''); $directory = realpath($argv[2] ?? '');
if (!$root || !$directory || str_starts_with($directory . '/', $root . '/') || !is_file($root . '/bitrix/modules/main/include/prolog_before.php')) { throw new RuntimeException('Existing site and private release directory required.'); }
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REQUEST_METHOD'] = 'GET';
define('STOP_STATISTICS', true); define('NO_KEEP_STATISTIC', true);
require $root . '/bitrix/modules/main/include/prolog_before.php';
if (!\Bitrix\Main\Loader::includeModule('prospektweb.calc')) { throw new RuntimeException('Module unavailable.'); }
$module = \Bitrix\Main\Loader::getLocal('modules/prospektweb.calc');
foreach (['BitrixConnection', 'DocumentApplication', 'BitrixCoreGateway', 'BitrixResourceProvider'] as $file) { require_once $module . '/lib/Documents/' . $file . '.php'; }
$sourceHash = '61791207acb91446debf1057f30c14d070c5d9069b44116991b21b6fdce59192';
$documentHash = '15bba8fa76339c7fd99075243836d0714bd754ea736413c93580457687fb0b7d';
$previousHash = 'a4066135f4e8bf7d450eff4c54d4e4d25c966d209056e9d0e0a11faf2db590a1';
$id = 'db538f5a-4cb1-8c8f-a113-93120de3f035';
$activeHash = static fn() => (new \Prospektweb\Calc\Services\CalculatorVersionRuntimePublicationService())->resolve(12740)['contentHash'] ?? null;
if ($activeHash() !== $sourceHash) { throw new RuntimeException('Public pilot changed. Refresh parity before migration.'); }
$json = file_get_contents($directory . '/document.canonical.json');
if (hash('sha256', $json) !== $documentHash) { throw new RuntimeException('Import hash mismatch.'); }
$document = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
$gateway = new \Prospektweb\Calc\Documents\BitrixCoreGateway();
$validated = $gateway(['action' => 'validate', 'document' => $document]);
if ($validated['documentJson'] !== $json || $validated['documentHash'] !== $documentHash) { throw new RuntimeException('Canonical core validation mismatch.'); }
$provider = new \Prospektweb\Calc\Documents\BitrixResourceProvider('bitrix:prospektprint.ru');
$started = hrtime(true); $resources = $provider($document); $snapshotMs = (hrtime(true) - $started) / 1e6;
$writeOnce = static function (string $path, string $body): void {
    if (is_file($path)) { if (file_get_contents($path) !== $body) { throw new RuntimeException('Existing private artifact differs: ' . basename($path)); } return; }
    $f = fopen($path, 'x'); if (!$f || fwrite($f, $body) !== strlen($body)) { throw new RuntimeException('Private backup failed.'); } fclose($f); chmod($path, 0600);
};
$writeOnce($directory . '/live-resources.json', json_encode($resources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$values = ['volume' => 1000, 'system.layout-count' => 1, 'system.deadline-type' => 'strict', 'format.width' => 90, 'format.length' => 50,
    'method' => 'OFSET', 'color.scheme' => '4+4', 'type.material' => 'paper', 'type.paper' => 'mel-mat-paper', 'density.paper' => '150', 'section:protection' => false, 'protection' => '', 'options' => []];
$execution = ['unitCount' => 1000, 'runCount' => 1, 'layoutCount' => 1, 'deadlineType' => 'strict'];
$cases = [['name' => 'offset', 'values' => $values, 'base' => 7073.168235294117],
    ['name' => 'offset-lamination', 'values' => array_replace($values, ['section:protection' => true, 'protection' => 'lamination-rulon', 'lamination' => 'gloss-low', 'lamination.sides' => '2']), 'base' => 8979.800861588234],
    ['name' => 'digital', 'values' => array_replace($values, ['method' => 'DIGITAL', 'color.scheme' => '4+0']), 'base' => 2077.7928333333334]];
$compiled = $gateway(['action' => 'compile', 'document' => $document, 'resources' => $resources]);
if ($compiled['documentHash'] !== $documentHash || hash('sha256', $compiled['snapshotJson']) !== $compiled['snapshotHash']) { throw new RuntimeException('Compiled snapshot integrity mismatch.'); }
$publication = json_decode($compiled['snapshotJson'], false, 64, JSON_THROW_ON_ERROR); $report = [];
foreach ($cases as $case) {
    $start = hrtime(true);
    $a = $gateway(['action' => 'preview', 'document' => $document, 'resources' => $resources, 'values' => (object)$case['values'], 'execution' => $execution])['result'];
    $b = $gateway(['action' => 'execute', 'publication' => $publication, 'values' => (object)$case['values'], 'execution' => $execution])['result'];
    if ($a !== $b || isset($a['globals']) || abs($a['basePrice'] - $case['base']) > 1e-8 || count($a['priceRanges']) < 1) { throw new RuntimeException('Live quote mismatch: ' . $case['name']); }
    $report[] = ['name' => $case['name'], 'basePrice' => $a['basePrice'], 'rangeCount' => count($a['priceRanges']), 'previewAndExecuteMs' => round((hrtime(true) - $start) / 1e6, 3)];
}
$invalidRejected = false;
try { $gateway(['action' => 'preview', 'document' => $document, 'resources' => $resources, 'values' => (object)array_replace($values, ['format.width' => 1000, 'format.length' => 1000]), 'execution' => $execution]); }
catch (InvalidArgumentException $e) { $invalidRejected = true; }
if (!$invalidRejected) { throw new RuntimeException('Invalid geometry accepted.'); }
$config = new \Prospektweb\Calc\Config\ConfigManager(); $beforeOptions = [];
foreach (['DOCUMENT_SITE_ID' => 's1', 'DOCUMENT_RESOURCE_PROVIDER' => 'bitrix:prospektprint.ru'] as $key => $value) {
    $old = (string)$config->getOption($key, '');
    if ($old !== '' && $old !== $value) { throw new RuntimeException('Unknown adapter configuration; refusing overwrite.'); }
    $beforeOptions[$key] = $old;
}
$writeOnce($directory . '/adapter-options.backup.json', json_encode($beforeOptions, JSON_THROW_ON_ERROR));
$repo = new \Prospektweb\Calc\Documents\DocumentRepository(new \Prospektweb\Calc\Documents\BitrixConnection(\Bitrix\Main\Application::getConnection()), 'site:s1', 'migration:document-workbench-20260907');
$before = $repo->load($id);
if ($before['revision'] === 2 && $before['bodyHash'] === $previousHash && $before['activePublication'] === null) {
    $writeOnce($directory . '/draft-revision-2.backup.json', $before['bodyJson']);
    $saved = $repo->save($id, 2, $validated['documentJson']);
} elseif ($before['revision'] === 3 && $before['bodyHash'] === $documentHash && $before['activePublication'] === null) { $saved = $before; }
else { throw new RuntimeException('Draft changed; automatic overwrite prohibited.'); }
if ($repo->load($id)['bodyJson'] !== $json || $repo->load($id, 2)['bodyHash'] !== $previousHash) { throw new RuntimeException('SQL revision readback mismatch.'); }
$config->setOption('DOCUMENT_SITE_ID', 's1'); $config->setOption('DOCUMENT_RESOURCE_PROVIDER', 'bitrix:prospektprint.ru');
if ($activeHash() !== $sourceHash) { throw new RuntimeException('Public pilot changed during verification.'); }
echo json_encode(['documentId' => $id, 'revision' => $saved['revision'], 'documentHash' => $documentHash, 'engineVersion' => $compiled['engineVersion'],
    'resources' => count($resources), 'resourceSnapshotMs' => round($snapshotMs, 3), 'cases' => $report, 'invalidGeometryRejected' => true,
    'oldPublicPilotUnchanged' => true, 'publicCutover' => false, 'documentEditorDefaultEnabled' => $config->getOption('DOCUMENT_EDITOR_ENABLED', 'N') === 'Y'], JSON_THROW_ON_ERROR) . "\n";
