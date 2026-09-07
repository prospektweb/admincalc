<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentRepository.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentRepository};
if (($argv[1] ?? '') === '--worker') {
    $repo = new DocumentRepository(new PdoConnection(new PDO('sqlite:' . $argv[2])), 'site:test', 'test:worker');
    echo "ready\n"; fflush(STDOUT); fgets(STDIN);
    try { $repo->changeCatalog('renameSection', 2, ['id' => $argv[3], 'name' => $argv[4]]); echo '200'; }
    catch (Throwable $e) { if ($e->getCode() !== 409) throw $e; echo '409'; }
    exit;
}
$path = tempnam(sys_get_temp_dir(), 'pw-catalog-');
try {
    $db = new PdoConnection(new PDO('sqlite:' . $path)); DocumentSchema::install($db);
    $repo = new DocumentRepository($db, 'site:test', 'test:main');
    $root = $repo->changeCatalog('createSection', 0, ['name' => 'Root', 'parentId' => null])['createdId'];
    $child = $repo->changeCatalog('createSection', 1, ['name' => 'Child', 'parentId' => $root])['createdId'];
    $workers = [];
    foreach (['Writer A', 'Writer B'] as $name) {
        $process = proc_open([PHP_BINARY, '-d', 'extension=pdo_sqlite', __FILE__, '--worker', $path, $child, $name], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process) || trim((string)fgets($pipes[1])) !== 'ready') throw new RuntimeException('Worker failed');
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) { fwrite($pipes[0], "go\n"); fclose($pipes[0]); }
    $statuses = [];
    foreach ($workers as [$process, $pipes]) {
        $statuses[] = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException($error);
    }
    sort($statuses);
    if ($statuses !== ['200', '409'] || $repo->catalog()['revision'] !== 3) throw new RuntimeException('Concurrent CAS failed');
    $repo->create(json_encode(['contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1, 'id' => 'sheet', 'name' => 'Sheet']), $root, 3);
    $before = $repo->catalog();
    $db->execute("CREATE TRIGGER fail_section_delete BEFORE DELETE ON b_pw_calc_section BEGIN SELECT RAISE(ABORT, 'test fault'); END");
    try { $repo->changeCatalog('deleteSection', 4, ['id' => $root]); throw new LogicException('Fault was not raised'); }
    catch (PDOException $e) { /* Database rejected the final delete after both reparent updates. */ }
    if ($repo->catalog() !== $before || $repo->registry()['rows'][0]['sectionId'] !== $root || $db->inTransaction()) throw new RuntimeException('Partial reparent escaped rollback');
    echo "PASS native catalog concurrent writers and atomic delete rollback\n";
} finally { unset($repo, $db); if (is_file($path)) unlink($path); }
