<?php
/** Read-only source export. Execute under the configured hosting account via CLI. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = realpath($argv[1] ?? '');
$destination = realpath($argv[2] ?? '');
if (!$root || !$destination || !is_file($root . '/bitrix/modules/main/include/prolog_before.php')
    || $destination === $root || str_starts_with($destination . '/', $root . '/')) {
    throw new RuntimeException('An existing Bitrix root and private export directory are required.');
}
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['REQUEST_METHOD'] = 'GET';
define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
require $root . '/bitrix/modules/main/include/prolog_before.php';
if (!\Bitrix\Main\Loader::includeModule('prospektweb.calc')) {
    throw new RuntimeException('Calculator module unavailable.');
}
$connection = \Bitrix\Main\Application::getConnection();
$row = $connection->query("SELECT VALUE FROM b_option WHERE BINARY MODULE_ID='prospektweb.calc' AND BINARY NAME='calc_versions_12740' AND (SITE_ID IS NULL OR SITE_ID='')")->fetch();
$registry = json_decode((string)($row['VALUE'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
$publication = (new \Prospektweb\Calc\Services\CalculatorVersionRuntimePublicationService())->resolve(12740);
if (!$publication || (int)$publication['presetId'] !== 12740 || empty($publication['documents']['logic']['runtimePayload'])) {
    throw new RuntimeException('The pilot has no complete publication.');
}
$bundles = new \Prospektweb\Calc\Services\CalculatorVersionBundleDocumentService();
$versions = [];
foreach ($registry['versions'] as $version) {
    $id = (string)$version['versionId'];
    $versions[$id] = $bundles->load(12740, $id);
}
$payload = [
    'contract' => 'prospektweb.calc.bitrix-pilot-export/v1',
    'exportedAt' => gmdate('c'),
    'sourcePresetId' => 12740,
    'registry' => $registry,
    'publication' => $publication,
    'versions' => $versions,
];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$path = $destination . '/pilot-source.json';
$mask = umask(0077);
$handle = fopen($path, 'xb');
umask($mask);
if ($handle === false) throw new RuntimeException('Export is create-only; choose a fresh destination.');
try {
    if (fwrite($handle, $json) !== strlen($json)) throw new RuntimeException('Incomplete pilot export.');
} finally { fclose($handle); }
$paths = [
    '/bitrix/modules/prospektweb.calc/lib/Services/CalculatorVersionRuntimePublicationService.php',
    '/local/modules/prospektweb.frontcalc/ajax/frontcalc.php',
    '/local/apps/prospektweb.calc/assets/index.js',
];
$hashes = [];
foreach ($paths as $relative) {
    $hashes[$relative] = is_file($root . $relative) ? hash_file('sha256', $root . $relative) : null;
}
echo json_encode([
    'bytes' => strlen($json), 'sha256' => hash('sha256', $json),
    'versionCount' => count($versions),
    'publicationHash' => $publication['contentHash'],
    'php' => PHP_VERSION, 'database' => $connection->query('SELECT VERSION() AS V')->fetch()['V'],
    'fileHashes' => $hashes,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
