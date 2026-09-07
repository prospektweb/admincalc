<?php
/** Stage-only native catalog adapter setup; source rules read and verified 2026-09-07. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? ''); $private = realpath($argv[2] ?? '');
if ($root !== '/home/bitrix/www' || !$private || str_starts_with($private . '/', $root . '/') || !is_file('/etc/prospekt-calc-stage/service.env')) { throw new RuntimeException('Isolated staging required.'); }
$_SERVER['DOCUMENT_ROOT'] = $root;
define('STOP_STATISTICS', true); define('NO_KEEP_STATISTIC', true); define('SITE_ID', 's1');
require $root . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('catalog');
$groups = [1 => 'BASE', 9 => 'CORPORATE', 10 => 'CONTRACT', 11 => 'PARTNER'];
$before = []; $missing = [];
foreach ($groups as $id => $code) {
    if ((\CCatalogGroup::GetByID($id)['NAME'] ?? '') !== $code) { throw new RuntimeException('Staging price group mismatch.'); }
    $rules = \Bitrix\Catalog\RoundingTable::getList(['filter' => ['=CATALOG_GROUP_ID' => $id]])->fetchAll();
    $before[$id] = $rules;
    if (!$rules) { $missing[] = $id; continue; }
    if (count($rules) !== 1 || (float)$rules[0]['PRICE'] !== 100.0 || (int)$rules[0]['ROUND_TYPE'] !== 2 || (float)$rules[0]['ROUND_PRECISION'] !== 10.0) { throw new RuntimeException('Existing rounding rules differ; no overwrite.'); }
}
$backup = $private . '/before-pilot-rounding.json';
if (!is_file($backup)) { file_put_contents($backup, json_encode($before, JSON_THROW_ON_ERROR), LOCK_EX); chmod($backup, 0600); }
$db = \Bitrix\Main\Application::getConnection(); $db->startTransaction();
try {
    foreach ($missing as $id) {
        $result = \Bitrix\Catalog\RoundingTable::add(['CATALOG_GROUP_ID' => $id, 'PRICE' => 100, 'ROUND_TYPE' => \Bitrix\Catalog\RoundingTable::ROUND_UP, 'ROUND_PRECISION' => 10]);
        if (!$result->isSuccess()) { throw new RuntimeException('Cannot add staging rounding rule.'); }
    }
    $db->commitTransaction();
} catch (Throwable $e) { $db->rollbackTransaction(); throw $e; }
echo json_encode(['addedGroupIds' => $missing, 'rounding' => 'up-to-10-from-100', 'scope' => 'staging native catalog adapter'], JSON_THROW_ON_ERROR) . "\n";
