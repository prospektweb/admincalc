<?php
/** Explicit numeric-ID backfill; never changes UUIDs, versions or publications. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? '');
if (!$root || !is_file($root . '/bitrix/modules/main/include/prolog_before.php')) throw new RuntimeException('Site root required.');
$apply = ($argv[2] ?? '--inspect') === '--apply';
if (!in_array($argv[2] ?? '--inspect', ['--inspect', '--apply'], true)) throw new InvalidArgumentException('Use --inspect or --apply.');
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REQUEST_METHOD'] = 'GET';
define('STOP_STATISTICS', true); define('NO_KEEP_STATISTIC', true); define('NO_AGENT_CHECK', true);
require $root . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('prospektweb.calc');
$module = \Bitrix\Main\Loader::getLocal('modules/prospektweb.calc');
require_once $module . '/lib/Documents/BitrixConnection.php';
require_once $module . '/lib/Documents/DocumentSchema.php';
$db = new \Prospektweb\Calc\Documents\BitrixConnection(\Bitrix\Main\Application::getConnection());
$db->begin(!$apply);
try {
    $documentsBefore = $db->rows('SELECT * FROM b_pw_calc_document ORDER BY id');
    $identitiesBefore = $db->rows('SELECT * FROM b_pw_calc_site_identity ORDER BY public_id');
    if ($apply) \Prospektweb\Calc\Documents\DocumentSchema::backfillRegistryIdentities($db);
    if ($documentsBefore !== $db->rows('SELECT * FROM b_pw_calc_document ORDER BY id')) throw new RuntimeException('Document metadata changed.');
    $identitiesAfter = $db->rows('SELECT * FROM b_pw_calc_site_identity ORDER BY public_id');
    foreach ($identitiesBefore as $identity) {
        if (!in_array($identity, $identitiesAfter, true)) throw new RuntimeException('Existing numeric identity changed.');
    }
    $missing = count($db->rows('SELECT d.id FROM b_pw_calc_document d LEFT JOIN b_pw_calc_site_identity i ON i.document_id = d.id WHERE i.document_id IS NULL'));
    if ($apply && $missing !== 0) throw new RuntimeException('Numeric identities are incomplete.');
    $result = ['mode' => $apply ? 'apply' : 'inspect', 'added' => count($identitiesAfter) - count($identitiesBefore),
        'missing' => $missing, 'documentsUnchanged' => true, 'identities' => $identitiesAfter];
    $db->commit();
} catch (Throwable $error) { $db->rollback(); throw $error; }
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
