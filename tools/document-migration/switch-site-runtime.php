<?php
/** Reversible, pinned pilot cutover; no iblocks or catalog rows are deleted. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? ''); $directory = realpath($argv[2] ?? ''); $mode = $argv[3] ?? '';
if (!$root || !$directory || str_starts_with($directory . '/', $root . '/') || !in_array($mode, ['--enable', '--disable'], true)) { throw new RuntimeException('Private site release and explicit mode required.'); }
$_SERVER['DOCUMENT_ROOT'] = $root; define('SITE_ID', 's1'); define('STOP_STATISTICS', true); define('NO_KEEP_STATISTIC', true);
require $root . '/bitrix/modules/main/include/prolog_before.php';
foreach (['prospektweb.calc', 'prospektweb.frontcalc'] as $module) { if (!\Bitrix\Main\Loader::includeModule($module)) { throw new RuntimeException('Module unavailable.'); } }
$repository = \Prospektweb\Frontcalc\Service\DocumentSiteRuntime::repository();
$publication = $repository->sitePublication('db538f5a-4cb1-8c8f-a113-93120de3f035');
$report = json_decode(file_get_contents($directory . '/native-verification.json'), true, 64, JSON_THROW_ON_ERROR);
if ($publication['id'] !== $report['publicationId'] || $publication['sourceRevision'] !== 4 || $report['productBindings'] !== 16) { throw new RuntimeException('Verified publication changed.'); }
$db = \Bitrix\Main\Application::getConnection();
$db->startTransaction();
try {
    $repository->lockSitePublication($publication['documentId'], $publication['id']);
    $authority = new \Prospektweb\Frontcalc\Service\ExactGlobalOptionAuthority('prospektweb.calc', $db);
    $current = $authority->readForUpdate('DOCUMENT_PUBLIC_RUNTIME', 'N');
    $next = $mode === '--enable' ? 'Y' : 'N';
    $editor = $authority->readForUpdate('document_editor_enabled', 'N');
    if (!in_array($editor, ['Y', 'N'], true)) throw new RuntimeException('Unknown editor switch value.');
    if ($editor !== $next) {
        $path = $directory . '/editor-switch.before.json';
        if (!is_file($path)) {
            $body=json_encode(['name'=>'document_editor_enabled','value'=>$editor],JSON_THROW_ON_ERROR);
            $file=fopen($path,'x'); if (!$file || fwrite($file,$body)!==strlen($body)) throw new RuntimeException('Editor backup failed.'); fclose($file); chmod($path,0600);
        }
        $authority->write('document_editor_enabled', $next);
    }
    if ($current !== $next) {
        if ($current !== ($next === 'Y' ? 'N' : 'Y')) { throw new RuntimeException('Unknown runtime switch value.'); }
        $backup = $directory . '/runtime-switch.before.json';
        if ($next === 'Y' && !is_file($backup)) {
            $body = json_encode(['module' => 'prospektweb.calc', 'name' => 'DOCUMENT_PUBLIC_RUNTIME', 'effectiveValue' => $current, 'publicationId' => $publication['id']], JSON_THROW_ON_ERROR);
            $file = fopen($backup, 'x'); if (!$file || fwrite($file, $body) !== strlen($body)) { throw new RuntimeException('Switch backup failed.'); } fclose($file); chmod($backup, 0600);
        }
        $authority->write('DOCUMENT_PUBLIC_RUNTIME', $next);
    }
    if ($authority->readForUpdate('DOCUMENT_PUBLIC_RUNTIME', 'N') !== $next) { throw new RuntimeException('Runtime switch readback failed.'); }
    $db->commitTransaction();
} catch (Throwable $error) { $db->rollbackTransaction(); throw $error; }
echo json_encode(['publicRuntime' => $next, 'documentEditor' => $next, 'publicationId' => $publication['id'], 'publicId' => $publication['publicId']], JSON_THROW_ON_ERROR) . "\n";
