<?php
/** Additive schema preparation before deploying readers that use the new columns. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? '');
if (!$root || !is_file($root . '/bitrix/modules/main/include/prolog_before.php')) { throw new RuntimeException('Site root required.'); }
$_SERVER['DOCUMENT_ROOT'] = $root; define('STOP_STATISTICS', true); define('NO_KEEP_STATISTIC', true);
require $root . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('prospektweb.calc');
$module = \Bitrix\Main\Loader::getLocal('modules/prospektweb.calc');
require_once $module . '/lib/Documents/BitrixConnection.php'; require_once $module . '/lib/Documents/DocumentSchema.php';
$db = new \Prospektweb\Calc\Documents\BitrixConnection(\Bitrix\Main\Application::getConnection());
$before = $db->rows('SELECT id, current_revision, active_publication FROM b_pw_calc_document ORDER BY id');
\Prospektweb\Calc\Documents\DocumentSchema::install($db);
if ($before !== $db->rows('SELECT id, current_revision, active_publication FROM b_pw_calc_document ORDER BY id')) { throw new RuntimeException('Document metadata changed during schema preparation.'); }
echo 'PASS additive site schema v' . \Prospektweb\Calc\Documents\DocumentSchema::VERSION . ', existing document metadata unchanged.' . "\n";
