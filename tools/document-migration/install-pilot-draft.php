<?php
/** Explicit CLI-only additive migration. Does not switch or modify the existing pilot. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? '');
$mode = $argv[2] ?? '--inspect';
if (!$root || !is_file($root . '/bitrix/modules/main/include/prolog_before.php')) throw new RuntimeException('Existing Bitrix root required.');
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['REQUEST_METHOD'] = 'GET';
define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
require $root . '/bitrix/modules/main/include/prolog_before.php';
if (!\Bitrix\Main\Loader::includeModule('prospektweb.calc')) throw new RuntimeException('Calculator module unavailable.');
$module = $root . '/bitrix/modules/prospektweb.calc';
$connection = \Bitrix\Main\Application::getConnection();
$files = ['SqlConnection.php', 'BitrixConnection.php', 'PdoConnection.php', 'DocumentSchema.php', 'DocumentRepository.php'];
$hashes = [];
foreach ($files as $file) $hashes[$file] = is_file($module . '/lib/Documents/' . $file) ? hash_file('sha256', $module . '/lib/Documents/' . $file) : null;
$tables = [];
foreach (['b_pw_calc_document', 'b_pw_calc_revision', 'b_pw_calc_publication'] as $table) {
    $row = $connection->query("SHOW TABLES LIKE '" . $connection->getSqlHelper()->forSql($table) . "'")->fetch();
    $tables[$table] = (bool)$row;
}
$publication = (new \Prospektweb\Calc\Services\CalculatorVersionRuntimePublicationService())->resolve(12740);
if ($mode === '--inspect') {
    echo json_encode(['files' => $hashes, 'tables' => $tables, 'activePilotHash' => $publication['contentHash'] ?? null], JSON_THROW_ON_ERROR) . "\n";
    exit;
}
if ($mode !== '--apply') throw new RuntimeException('Use --inspect or --apply.');
$path = realpath($argv[3] ?? ''); $expectedHash = $argv[4] ?? ''; $expectedSourceHash = $argv[5] ?? '';
if (!$path || !is_file($path) || str_starts_with($path, $root . DIRECTORY_SEPARATOR)
    || !preg_match('/^[a-f0-9]{64}$/D', $expectedHash) || !preg_match('/^[a-f0-9]{64}$/D', $expectedSourceHash)) {
    throw new RuntimeException('Private core-validated canonical document and pinned hashes required.');
}
if (!hash_equals($expectedHash, hash_file('sha256', $path))) throw new RuntimeException('Imported document hash mismatch.');
if (!hash_equals($expectedSourceHash, (string)($publication['contentHash'] ?? ''))) throw new RuntimeException('Source pilot changed; export and validate again.');
foreach ($hashes as $file => $hash) if ($hash === null) throw new RuntimeException('Missing document library: ' . $file);
require_once $module . '/lib/Documents/BitrixConnection.php';
require_once $module . '/lib/Documents/DocumentSchema.php';
require_once $module . '/lib/Documents/DocumentRepository.php';
$db = new \Prospektweb\Calc\Documents\BitrixConnection($connection);
\Prospektweb\Calc\Documents\DocumentSchema::install($db);
$repo = new \Prospektweb\Calc\Documents\DocumentRepository($db, 'site:s1', 'migration:pilot-20260907');
$json = file_get_contents($path); $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
if (($document['id'] ?? '') !== 'db538f5a-4cb1-8c8f-a113-93120de3f035') throw new RuntimeException('Only the explicit sheet-printing pilot can be imported by this command.');
$start = hrtime(true);
$created = false;
try { $stored = $repo->load($document['id']); }
catch (RuntimeException $error) {
    if ($error->getCode() !== 404) throw $error;
    $stored = $repo->create($json); $created = true;
}
if (!hash_equals($expectedHash, $stored['bodyHash'])) throw new RuntimeException('Existing document differs; automatic overwrite prohibited.');
if ($stored['activePublication'] !== null) throw new RuntimeException('Imported foundation draft must not be an active runtime publication.');
$saveMs = (hrtime(true) - $start) / 1e6;
$readTimes = [];
for ($i = 0; $i < 20; $i++) { $start = hrtime(true); $read = $repo->load($document['id']); $readTimes[] = (hrtime(true) - $start) / 1e6; }
sort($readTimes);
$same = $repo->save($document['id'], 1, $json);
$casRejected = false;
try { $repo->save($document['id'], 2, $json); }
catch (RuntimeException $error) { if ($error->getCode() !== 409) throw $error; $casRejected = true; }
if (!$casRejected || $same['revision'] !== 1 || $read['bodyJson'] !== $json) throw new RuntimeException('MySQL revision/byte-integrity verification failed.');
$after = (new \Prospektweb\Calc\Services\CalculatorVersionRuntimePublicationService())->resolve(12740);
if (($after['contentHash'] ?? '') !== $expectedSourceHash) throw new RuntimeException('Source publication changed during import.');
echo json_encode(['created' => $created, 'documentId' => $document['id'], 'revision' => $stored['revision'],
    'bodyBytes' => strlen($json), 'bodyHash' => $stored['bodyHash'], 'saveMs' => round($saveMs, 3),
    'readP50Ms' => round($readTimes[9], 3), 'readP95Ms' => round($readTimes[18], 3), 'casRejected' => $casRejected,
    'activeDocumentPublication' => null, 'oldPilotUnchanged' => true, 'libraryHashes' => $hashes], JSON_THROW_ON_ERROR) . "\n";
