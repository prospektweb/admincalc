<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? ''); $directory = realpath($argv[2] ?? '');
if (!$root || !$directory || str_starts_with($directory, $root . DIRECTORY_SEPARATOR)) { throw new RuntimeException('Existing root and private directory required.'); }
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REQUEST_METHOD'] = 'GET';
define('STOP_STATISTICS', true); define('NO_KEEP_STATISTIC', true);
require $root . '/bitrix/modules/main/include/prolog_before.php';
if (!\Bitrix\Main\Loader::includeModule('prospektweb.calc')) { throw new RuntimeException('Calculator module unavailable.'); }
require_once $root . '/bitrix/modules/prospektweb.calc/lib/Documents/BitrixConnection.php';
require_once $directory . '/lib/Documents/BitrixResourceProvider.php';
$expected = '61791207acb91446debf1057f30c14d070c5d9069b44116991b21b6fdce59192';
$publication = (new \Prospektweb\Calc\Services\CalculatorVersionRuntimePublicationService())->resolve(12740);
if (($publication['contentHash'] ?? null) !== $expected) { throw new RuntimeException('Source publication changed.'); }
$json = file_get_contents($directory . '/document.canonical.json');
if (hash('sha256', $json) !== '15bba8fa76339c7fd99075243836d0714bd754ea736413c93580457687fb0b7d') { throw new RuntimeException('Unexpected document artifact.'); }
$document = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
$started = hrtime(true);
$resources = (new \Prospektweb\Calc\Documents\BitrixResourceProvider('bitrix:prospektprint.ru'))($document);
$encoded = json_encode($resources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$file = fopen($directory . '/live-resources.json', 'x');
if (!$file || fwrite($file, $encoded) !== strlen($encoded)) { throw new RuntimeException('Create-only resource snapshot failed.'); }
fclose($file); chmod($directory . '/live-resources.json', 0600);
echo json_encode(['resources' => count($resources), 'hash' => hash('sha256', $encoded), 'elapsedMs' => (hrtime(true) - $started) / 1e6,
    'modulePath' => \Bitrix\Main\Loader::getLocal('modules/prospektweb.calc'),
    'serverUrl' => (new \Prospektweb\Calc\Config\ConfigManager())->getOption('CALC_SERVER_URL', '')], JSON_THROW_ON_ERROR) . "\n";
