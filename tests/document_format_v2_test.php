<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentRepository.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentRepository, DocumentVersions};

$db = new PdoConnection(new PDO('sqlite::memory:'));
DocumentSchema::install($db);
$repo = new DocumentRepository($db, 'test:v2', 'user:test');
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    $checks++; if (!$ok) throw new RuntimeException($message);
};
$reject = static function (callable $run) use ($check): void {
    try { $run(); } catch (InvalidArgumentException $e) { $check(true, 'Rejected'); return; }
    throw new RuntimeException('Expected invalid contract pair to fail');
};
// Repository envelope test, not a substitute for full remote Core validation.
$legacy = ['contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1,
    'id' => 'format', 'name' => 'История', 'fields' => [['code' => 'UNUSED', 'defaultValue' => false]]];
$current = ['contract' => 'prospektweb.calculator/document-v2', 'schemaVersion' => 2, 'id' => 'format', 'name' => 'История'];
$encode = static fn(array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
$one = $repo->create($encode($legacy));
$primary = DocumentVersions::primaryId('format');
$two = $repo->versions()->save('format', $primary, 1, $encode($current));
$check($two['revision'] === 2 && $two['bodyJson'] === $encode($current), 'Explicit save writes v2');
$check($repo->load('format', 1)['bodyJson'] === $one['bodyJson'], 'Original historical bytes preserved');
$check($repo->load('format', 1)['bodyHash'] === $one['bodyHash'], 'Historical hash preserved');
$check($repo->versions()->load('format', $primary)['bodyHash'] === hash('sha256', $encode($current)), 'Head uses exact v2 hash');
$check($repo->versions()->save('format', $primary, 2, $encode($current))['revision'] === 2, 'Idempotent v2 save');
foreach ([['prospektweb.calculator/document-v1', 2], ['prospektweb.calculator/document-v2', 1],
    ['prospektweb.calculator/document-v2', '2'], ['prospektweb.calculator/document-v3', 3]] as [$contract, $version]) {
    $bad = $current; $bad['contract'] = $contract; $bad['schemaVersion'] = $version;
    $reject(fn() => $repo->versions()->save('format', $primary, 2, $encode($bad)));
    $bad['id'] = 'new-format';
    $reject(fn() => $repo->create($encode($bad)));
}
$new = $current; $new['id'] = 'new-format';
$created = $repo->create($encode($new));
$check($created['revision'] === 1, 'Brand new v2 document is supported');
$check($repo->versions()->load('format', $primary)['revision'] === 2, 'Rejected writes leave head unchanged');
echo 'document_format_v2_test: ' . $checks . " PASS\n";
