<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentRepository.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentRepository, DocumentVersions};
function versionBody(string $name): string { return json_encode(['contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1, 'id' => 'sheet', 'name' => $name]); }
if (($argv[1] ?? '') === '--worker') {
    $repo = new DocumentRepository(new PdoConnection(new PDO('sqlite:' . $argv[2])), 'site:test', 'test:worker');
    echo "ready\n"; fflush(STDOUT); fgets(STDIN);
    try {
        if ($argv[5] === '--archive') $repo->versions()->change('sheet', $argv[3], (int)$argv[4], 'archiveVersion', ['archived' => true]);
        else $repo->versions()->save('sheet', $argv[3], (int)$argv[4], versionBody($argv[5]));
        echo '200';
    }
    catch (Throwable $e) { if ($e->getCode() !== 409) throw $e; echo '409'; } exit;
}
function race(string $path, array $requests): array {
    $workers = [];
    foreach ($requests as [$versionId, $revision, $name]) {
        $process = proc_open([PHP_BINARY, '-d', 'extension=pdo_sqlite', __FILE__, '--worker', $path, $versionId, (string)$revision, $name], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process) || trim((string)fgets($pipes[1])) !== 'ready') throw new RuntimeException('Worker failed'); $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) { fwrite($pipes[0], "go\n"); fclose($pipes[0]); }
    $statuses = [];
    foreach ($workers as [$process, $pipes]) {
        $statuses[] = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException($error);
    }
    sort($statuses); return $statuses;
}
$path = tempnam(sys_get_temp_dir(), 'pw-versions-');
try {
    $db = new PdoConnection(new PDO('sqlite:' . $path)); DocumentSchema::install($db); $repo = new DocumentRepository($db, 'site:test', 'test:main');
    $repo->create(versionBody('Sheet')); $primary = DocumentVersions::primaryId('sheet'); $versions = $repo->versions();
    $state = $versions->listing('sheet'); $branch = $versions->create('sheet', $state['registryRevision'], 'Branch', $primary, $state['versions'][0]['workContentHash'], null)['createdVersionId'];
    if (race($path, [[$primary, 1, 'A'], [$branch, 1, 'B']]) !== ['200', '200']) throw new RuntimeException('Independent branches conflict');
    $a = $versions->load('sheet', $primary); $b = $versions->load('sheet', $branch);
    if ($a['revision'] === $b['revision'] || json_decode($a['bodyJson'])->name !== 'A' || json_decode($b['bodyJson'])->name !== 'B') throw new RuntimeException('Branch overwrite or revision collision');
    if (race($path, [[$branch, $b['revision'], 'C'], [$branch, $b['revision'], 'D']]) !== ['200', '409']) throw new RuntimeException('Same-branch CAS failed');
    $before = $versions->load('sheet', $branch); $registry = $versions->listing('sheet'); $count = count($repo->history('sheet'));
    $db->execute("CREATE TRIGGER fail_version_head BEFORE UPDATE ON b_pw_calc_version BEGIN SELECT RAISE(ABORT, 'head update fault'); END");
    try { $versions->save('sheet', $branch, $before['revision'], versionBody('Must rollback')); throw new LogicException('Fault absent'); } catch (PDOException $e) {}
    if ($versions->load('sheet', $branch) !== $before || $versions->listing('sheet') !== $registry || count($repo->history('sheet')) !== $count || $db->inTransaction()) throw new RuntimeException('Revision insert escaped head-update rollback');
    $db->execute('DROP TRIGGER fail_version_head');
    $statuses = race($path, [[$branch, $before['revision'], 'Concurrent edit'], [$branch, $registry['registryRevision'], '--archive']]);
    if ($statuses !== ['200', '409']) throw new RuntimeException('Archive/save must serialize: archive blocks save, or save invalidates archive CAS');
    $afterRace = $versions->load('sheet', $branch); $afterRegistry = $versions->listing('sheet');
    if (!$afterRace['versionArchived']) $versions->change('sheet', $branch, $afterRegistry['registryRevision'], 'archiveVersion', ['archived' => true]);
    $hidden = $versions->load('sheet', $branch); $hiddenRegistry = $versions->listing('sheet'); $hiddenHistory = $repo->history('sheet');
    if (race($path, [[$branch, $hidden['revision'], 'Forbidden A'], [$branch, $hidden['revision'], 'Forbidden B']]) !== ['409', '409']) throw new RuntimeException('Archived branch accepted a concurrent write');
    if ($versions->load('sheet', $branch) !== $hidden || $versions->listing('sheet') !== $hiddenRegistry || $repo->history('sheet') !== $hiddenHistory) throw new RuntimeException('Rejected archived writes changed authoritative state');
    echo "PASS version branch concurrency, same-head CAS, save rollback and archive/write serialization\n";
} finally { unset($versions, $repo, $db); if (is_file($path)) unlink($path); }
