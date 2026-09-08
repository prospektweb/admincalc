<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentLibrary.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentLibrary};
if (($argv[1] ?? '') === '--worker') {
    $library = new DocumentLibrary(new PdoConnection(new PDO('sqlite:' . $argv[2])), $argv[3], 'test:worker', 'pricing');
    echo "ready\n"; fflush(STDOUT); fgets(STDIN);
    try {
        if ($argv[4] === 'create') { $library->create((int)$argv[5], $argv[6], '{}'); }
        else { $library->change('rename', $argv[4], (int)$argv[5], 1, null, $argv[6]); }
        echo '200';
    } catch (Throwable $error) { if ($error->getCode() !== 409) { throw $error; } echo '409'; }
    exit;
}
function templateRace(string $path, array $requests): array {
    $workers = [];
    foreach ($requests as [$scope, $action, $revision, $name]) {
        $process = proc_open([PHP_BINARY, '-d', 'extension=pdo_sqlite', __FILE__, '--worker', $path, $scope, $action, (string)$revision, $name], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process) || trim((string)fgets($pipes[1])) !== 'ready') { throw new RuntimeException('Template worker failed.'); }
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) { fwrite($pipes[0], "go\n"); fclose($pipes[0]); }
    $statuses = [];
    foreach ($workers as [$process, $pipes]) {
        $statuses[] = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException($error); }
    }
    sort($statuses); return $statuses;
}
$path = tempnam(sys_get_temp_dir(), 'pw-price-template-');
try {
    $db = new PdoConnection(new PDO('sqlite:' . $path)); DocumentSchema::install($db);
    $library = new DocumentLibrary($db, 'site:a', 'test:main', 'pricing');
    if (templateRace($path, [['site:a', 'create', 0, 'A'], ['site:a', 'create', 0, 'B']]) !== ['200', '409']) { throw new RuntimeException('First catalog creation CAS failed.'); }
    $catalog = $library->listing();
    if ($catalog['revision'] !== 1 || count($catalog['items']) !== 1) { throw new RuntimeException('Concurrent create leaked a row.'); }
    $id = $catalog['items'][0]['id']; $original = $library->load($id);
    if (templateRace($path, [['site:a', $id, 1, 'C'], ['site:a', $id, 1, 'D']]) !== ['200', '409']) { throw new RuntimeException('Template rename CAS failed.'); }
    if ($library->listing()['revision'] !== 2 || $library->load($id)['revision'] !== 2 || $library->load($id, 1) !== $original) { throw new RuntimeException('Concurrent rename lost history.'); }
    if (templateRace($path, [['site:b', 'create', 0, 'Same'], ['site:c', 'create', 0, 'Same']]) !== ['200', '200']) { throw new RuntimeException('Independent scopes conflict.'); }
    if (count($db->rows('SELECT * FROM b_pw_calc_library_revision')) !== 4 || $db->inTransaction()) { throw new RuntimeException('Transaction or history leak.'); }
    echo "PASS template catalog creation race, same-record CAS, immutable history and independent scopes\n";
} finally { unset($library, $db); if (is_file($path)) { unlink($path); } }
