<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentRepository.php';

use Prospektweb\Calc\Documents\{SqlConnection, PdoConnection, DocumentSchema, DocumentRepository};

function body(string $id, string $name = 'Листовая печать'): string {
    return json_encode(['contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1,
        'id' => $id, 'name' => $name, 'description' => 'A|B: VALUE and DESCRIPTION are ordinary text'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
function repository(string $path, string $actor): DocumentRepository {
    return new DocumentRepository(new PdoConnection(new PDO('sqlite:' . $path)), 'site:test', $actor);
}

if (($argv[1] ?? '') === '--worker') {
    $repo = repository($argv[2], $argv[3]);
    echo "ready\n"; fflush(STDOUT); fgets(STDIN);
    try { $repo->save('parallel', 1, body('parallel', $argv[3])); echo '200'; }
    catch (Throwable $error) { if ($error->getCode() !== 409) throw $error; echo '409'; }
    exit;
}

$checks = 0;
function check(bool $value, string $message): void {
    global $checks; $checks++;
    if (!$value) throw new RuntimeException($message);
}
function fails(callable $fn, ?int $code = null): void {
    try { $fn(); } catch (Throwable $error) {
        check($code === null || $error->getCode() === $code, 'Unexpected failure code: ' . $error->getMessage()); return;
    }
    throw new RuntimeException('Expected operation to fail.');
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('Tests require pdo_sqlite. Run php -d extension=pdo_sqlite tests/document_repository_test.php');
}
$path = tempnam(sys_get_temp_dir(), 'pw-calc-doc-');
try {
    $pdo = new PDO('sqlite:' . $path); $db = new PdoConnection($pdo);
    DocumentSchema::install($db); DocumentSchema::install($db);
    $repo = new DocumentRepository($db, 'site:test', 'user:1');
    $initial = $repo->create(body('sheet'));
    check($initial['revision'] === 1, 'First revision');
    check($initial['bodyJson'] === body('sheet'), 'Opaque canonical bytes preserved');
    check($initial['bodyHash'] === hash('sha256', body('sheet')), 'Content hash');
    check($repo->save('sheet', 1, body('sheet'))['revision'] === 1, 'Idempotent no-op save');
    fails(fn() => $repo->create(body('sheet')), 409);
    $changed = $repo->save('sheet', 1, body('sheet', 'Изменено'));
    check($changed['revision'] === 2, 'CAS revision increment');
    fails(fn() => $repo->save('sheet', 1, body('sheet', 'Stale writer')), 409);
    check($repo->load('sheet', 1)['bodyJson'] === body('sheet'), 'Previous revision immutable');
    check($repo->load('sheet')['bodyJson'] === body('sheet', 'Изменено'), 'Stale writer did not overwrite');
    fails(fn() => $repo->save('sheet', 2, body('another')));
    fails(fn() => $repo->save('sheet', 0, body('sheet')));
    fails(fn() => $repo->create('[]'));
    fails(fn() => $repo->create('{malformed'));
    fails(fn() => $repo->create(body('bad', str_repeat('Ю', 256))));
    fails(fn() => new DocumentRepository($db, "bad' OR 1=1", 'user:1'));
    $other = new DocumentRepository($db, 'another:site', 'user:2');
    check($other->listing() === [], 'Scope isolation in listing');
    fails(fn() => $other->load('sheet'), 404);
    fails(fn() => $other->load('sheet', 1), 404);
    fails(fn() => $other->save('sheet', 2, body('sheet')), 404);
    $metadata = $repo->listing()[0];
    check(!isset($metadata['body_json']) && !isset($metadata['snapshot_json']), 'List does not read graphs');
    fails(fn() => $repo->listing(101)); fails(fn() => $repo->listing(1, -1));

    $snapshot = json_encode(['contract' => 'prospektweb.calculator/publication-v1', 'calculatorId' => 'sheet',
        'engineVersion' => 'test-compiler:1', 'documentHash' => $changed['bodyHash'],
        'resources' => [['id' => 'paper', 'price' => 5]], 'plan' => ['testOnly' => true]], JSON_THROW_ON_ERROR);
    $published = $repo->publish('sheet', 2, null, 'test-compiler:1', $snapshot);
    check($published['sourceRevision'] === 2, 'Publication has exact source revision');
    check($repo->publication('sheet')['id'] === $published['id'], 'Atomic active publication pointer');
    fails(fn() => $repo->publish('sheet', 2, null, 'test-compiler:1', $snapshot), 409);
    fails(fn() => $other->publication('sheet', $published['id']), 404);
    check($repo->publish('sheet', 2, $published['id'], 'test-compiler:1', $snapshot)['id'] === $published['id'], 'Publishing identical content is idempotent');
    $repo->save('sheet', 2, body('sheet', 'Draft after publication'));
    check($repo->publication('sheet')['snapshotJson'] === $snapshot, 'Draft changes cannot mutate published resources or plan');
    fails(fn() => $repo->publish('sheet', 3, $published['id'], 'test-compiler:1', $snapshot), 409);
    check($repo->load('sheet')['activePublication'] === $published['id'], 'Failed publication preserves pointer');
    fails(fn() => $repo->publish('sheet', 3, $published['id'], 'test-compiler:1', '{}'));

    $fault = new class($db) implements SqlConnection {
        private SqlConnection $inner;
        public function __construct(SqlConnection $inner) { $this->inner = $inner; }
        public function dialect(): string { return $this->inner->dialect(); }
        public function inTransaction(): bool { return $this->inner->inTransaction(); }
        public function begin(bool $readSnapshot = false): void { $this->inner->begin($readSnapshot); }
        public function commit(): void { $this->inner->commit(); }
        public function rollback(): void { $this->inner->rollback(); }
        public function rows(string $sql, array $parameters = []): array { return $this->inner->rows($sql, $parameters); }
        public function execute(string $sql, array $parameters = []): void {
            $this->inner->execute($sql, $parameters);
            if (str_starts_with($sql, 'INSERT INTO b_pw_calc_revision')) throw new RuntimeException('Injected failure after insert');
        }
    };
    $failing = new DocumentRepository($fault, 'site:test', 'user:3');
    fails(fn() => $failing->save('sheet', 3, body('sheet', 'Must roll back')));
    check($repo->load('sheet')['revision'] === 3, 'Failure rolls back metadata');
    fails(fn() => $repo->load('sheet', 4), 404);
    fails(fn() => $failing->create(body('atomic-create')));
    fails(fn() => $repo->load('atomic-create'), 404);
    $db->begin(); fails(fn() => $repo->save('sheet', 3, body('sheet'))); $db->rollback();

    $repo->create(body('parallel'));
    $workers = [];
    foreach (['writer:a', 'writer:b'] as $actor) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'extension=pdo_sqlite', __FILE__, '--worker', $path, $actor],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start concurrent test worker');
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) check(trim((string)fgets($pipes[1])) === 'ready', 'Concurrent writer ready');
    foreach ($workers as [$process, $pipes]) { fwrite($pipes[0], "go\n"); fclose($pipes[0]); }
    $statuses = [];
    foreach ($workers as [$process, $pipes]) {
        $statuses[] = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0, 'Concurrent worker failed: ' . $error);
    }
    sort($statuses);
    check($statuses === ['200', '409'], 'Exactly one simultaneous writer wins');
    check($repo->load('parallel')['revision'] === 2, 'No skipped or overwritten revision');
    $db->execute('UPDATE b_pw_calc_revision SET body_json = ? WHERE document_id = ? AND revision = ?', [body('sheet', 'Out-of-band tamper'), 'sheet', 3]);
    fails(fn() => $repo->load('sheet'));
    $db->execute('UPDATE b_pw_calc_publication SET snapshot_json = ? WHERE id = ?', ['{}', $published['id']]);
    fails(fn() => $repo->publication('sheet'));
    echo "PASS: $checks checks; SQLite transactions, rollback, scope isolation, immutable publication, real concurrent CAS.\n";
} finally {
    unset($repo, $other, $failing, $fault, $db, $pdo);
    // Only the test-owned temporary database is removed; no project/site data.
    if (is_file($path)) unlink($path);
}
