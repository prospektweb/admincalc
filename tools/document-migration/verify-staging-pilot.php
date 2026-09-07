<?php
/** Read-only staging parity check against the accepted production pilot cases. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? ''); $private = realpath($argv[2] ?? '');
if ($root !== '/home/bitrix/www' || !$private || str_starts_with($private . '/', $root . '/') || !is_file('/etc/prospekt-calc-stage/service.env')) { throw new RuntimeException('Isolated staging required.'); }
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REQUEST_METHOD'] = 'GET';
define('STOP_STATISTICS', true); define('NO_KEEP_STATISTIC', true); define('SITE_ID', 's1');
require $root . '/bitrix/modules/main/include/prolog_before.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"); exit(1); });
\Bitrix\Main\Loader::includeModule('prospektweb.calc');
putenv('PROSPEKTWEB_CALC_SERVER_CLIENT_ID=prospektprint-stage');
$config = new \Prospektweb\Calc\Config\ModuleOptions();
if ($config->get('CALC_SERVER_URL') !== 'https://127.0.0.1:3443') { throw new RuntimeException('Wrong core endpoint.'); }
$repo = new \Prospektweb\Calc\Documents\DocumentRepository(new \Prospektweb\Calc\Documents\BitrixConnection(\Bitrix\Main\Application::getConnection()), 'site:s1', 'verification:stage-pilot');
$saved = $repo->load('db538f5a-4cb1-8c8f-a113-93120de3f035');
$document = json_decode($saved['bodyJson'], false, 64, JSON_THROW_ON_ERROR);
$resources = (new \Prospektweb\Calc\Documents\BitrixResourceProvider($config->get('DOCUMENT_RESOURCE_PROVIDER')))($document);
$core = new \Prospektweb\Calc\Documents\BitrixCoreGateway();
$compiled = $core(['action' => 'compile', 'document' => $document, 'resources' => $resources]);
if ($compiled['documentHash'] !== $saved['bodyHash'] || hash('sha256', $compiled['snapshotJson']) !== $compiled['snapshotHash']) { throw new RuntimeException('Compilation integrity mismatch.'); }
$publication = json_decode($compiled['snapshotJson'], false, 64, JSON_THROW_ON_ERROR);
$values = ['volume' => 1000, 'system.layout-count' => 1, 'system.deadline-type' => 'strict', 'format.width' => 90, 'format.length' => 50,
    'method' => 'OFSET', 'color.scheme' => '4+4', 'type.material' => 'paper', 'type.paper' => 'mel-mat-paper', 'density.paper' => '150', 'section:protection' => false, 'protection' => '', 'options' => []];
$execution = ['unitCount' => 1000, 'runCount' => 1, 'layoutCount' => 1, 'deadlineType' => 'strict'];
$cases = [['name' => 'offset', 'values' => $values, 'base' => 7073.168235294117],
    ['name' => 'offset-lamination', 'values' => array_replace($values, ['section:protection' => true, 'protection' => 'lamination-rulon', 'lamination' => 'gloss-low', 'lamination.sides' => '2']), 'base' => 8979.800861588234],
    ['name' => 'digital', 'values' => array_replace($values, ['method' => 'DIGITAL', 'color.scheme' => '4+0']), 'base' => 2077.7928333333334]];
$report = [];
foreach ($cases as $case) {
    $start = hrtime(true);
    $a = $core(['action' => 'preview', 'document' => $document, 'resources' => $resources, 'values' => (object)$case['values'], 'execution' => $execution])['result'];
    $b = $core(['action' => 'execute', 'publication' => $publication, 'values' => (object)$case['values'], 'execution' => $execution])['result'];
    if ($a !== $b || isset($a['globals']) || abs($a['basePrice'] - $case['base']) > 1e-8 || count($a['priceRanges']) < 1) { throw new RuntimeException('Quote mismatch: ' . $case['name'] . ' actual=' . $a['basePrice']); }
    $report[] = ['name' => $case['name'], 'basePrice' => $a['basePrice'], 'rangeCount' => count($a['priceRanges']), 'previewAndExecuteMs' => round((hrtime(true) - $start) / 1e6, 3)];
}
$invalidRejected = false;
try { $core(['action' => 'preview', 'document' => $document, 'resources' => $resources, 'values' => (object)array_replace($values, ['format.width' => 1000, 'format.length' => 1000]), 'execution' => $execution]); }
catch (InvalidArgumentException $error) { $invalidRejected = true; }
if (!$invalidRejected) { throw new RuntimeException('Invalid geometry accepted.'); }
$after = $repo->load($document->id);
if ($after !== $saved) { throw new RuntimeException('Verification changed the document.'); }
$result = ['id' => $document->id, 'revision' => $saved['revision'], 'hash' => $saved['bodyHash'], 'resources' => count($resources), 'cases' => $report, 'invalidGeometryRejected' => true, 'documentUnchanged' => true];
file_put_contents($private . '/stage-core-parity.json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX); chmod($private . '/stage-core-parity.json', 0600);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
