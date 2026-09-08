<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/PriceTemplateApplication.php';
use Prospektweb\Calc\Documents\{PdoConnection, SqlConnection, DocumentSchema, DocumentRepository, DocumentLibrary, PriceTemplate, PriceTemplateApplication, DocumentConflict};

$checks = 0;
function price_check(bool $pass, string $message): void { global $checks; $checks++; if (!$pass) { throw new RuntimeException($message); } }
function price_fails(callable $action, string $class = InvalidArgumentException::class, ?int $code = null): void {
    try { $action(); } catch (Throwable $error) {
        price_check($error instanceof $class && ($code === null || $error->getCode() === $code), 'Unexpected error: ' . get_class($error) . ': ' . $error->getMessage()); return;
    }
    throw new RuntimeException('Invalid template action succeeded.');
}
$encode = static fn($body): string => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$template = ['contract' => PriceTemplate::CONTRACT, 'currency' => 'RUB', 'mode' => 'margin',
    'types' => [['code' => 'BASE', 'base' => true, 'sort' => 100], ['code' => 'PARTNER', 'base' => false, 'sort' => 200]], 'ranges' => []];
foreach ($template['types'] as $type) {
    foreach ([[null, 1], [2, 2], [3, null]] as [$from, $to]) {
        $template['ranges'][] = ['typeCode' => $type['code'], 'price' => 25, 'mode' => 'marginPercent', 'currency' => null,
            'quantityFrom' => $from, 'quantityTo' => $to, 'limitAmount' => 7000, 'limitCurrency' => 'RUB'];
    }
}
$json = PriceTemplate::canonical($encode($template));
price_check(PriceTemplate::canonical($json) === $json, 'Canonicalization is idempotent.');
price_check(json_decode($json, true)['ranges'][0]['quantityFrom'] === null, 'Null range boundary is preserved.');
price_check(!str_contains($json, 'typeId') && !str_contains($json, 'iblock'), 'Template is independent of document UUIDs and Bitrix IDs.');
$amount = $template; $amount['ranges'][0]['mode'] = 'amount'; $amount['ranges'][0]['currency'] = 'RUB';
price_check(PriceTemplate::canonical($encode($amount)) !== '', 'Fixed amounts may coexist with percentage ranges.');
foreach ([
    static function (&$v) { $v['scope'] = 'site:foreign'; },
    static function (&$v) { unset($v['currency']); },
    static function (&$v) { $v['mode'] = 'unknown'; },
    static function (&$v) { $v['currency'] = 'rub'; },
    static function (&$v) { $v['types'] = []; },
    static function (&$v) { $v['types'][1]['code'] = 'BASE'; },
    static function (&$v) { $v['types'][1]['base'] = true; },
    static function (&$v) { $v['types'][0]['base'] = false; },
    static function (&$v) { $v['types'][0]['sort'] = '100'; },
    static function (&$v) { $v['types'][0]['code'] = "bad\ncode"; },
    static function (&$v) { $v['types'][0]['id'] = 1; },
    static function (&$v) { $v['ranges'][0]['typeCode'] = 'UNKNOWN'; },
    static function (&$v) { $v['ranges'][0]['price'] = 100; },
    static function (&$v) { $v['ranges'][0]['price'] = -1; },
    static function (&$v) { $v['ranges'][0]['price'] = '25'; },
    static function (&$v) { $v['ranges'][0]['price'] = true; },
    static function (&$v) { $v['ranges'][0]['mode'] = 'markupPercent'; },
    static function (&$v) { $v['ranges'][0]['currency'] = 'RUB'; },
    static function (&$v) { $v['ranges'][0]['limitCurrency'] = 'USD'; },
    static function (&$v) { $v['ranges'][0]['limitAmount'] = -1; },
    static function (&$v) { $v['ranges'][0]['quantityFrom'] = 0.5; },
    static function (&$v) { $v['ranges'][0]['quantityTo'] = -1; },
    static function (&$v) { $v['ranges'][0]['quantityTo'] = 9007199254740992; },
    static function (&$v) { $v['ranges'][0]['quantityTo'] = 2; },
    static function (&$v) { $v['ranges'][0]['quantityTo'] = null; },
    static function (&$v) { $v['ranges'][0]['quantityTo'] = 0; },
    static function (&$v) { $v['ranges'] = array_slice($v['ranges'], 0, 3); },
    static function (&$v) { $v['ranges'][0]['extra'] = true; },
] as $mutate) { $invalid = $template; $mutate($invalid); price_fails(fn() => PriceTemplate::canonical($encode($invalid))); }
foreach (['[]', 'null', 'true', '{}'] as $invalid) { price_fails(fn() => PriceTemplate::canonical($invalid)); }
price_fails(fn() => PriceTemplate::canonical(str_repeat(' ', 1000001)));
price_fails(fn() => PriceTemplate::canonical('{'), JsonException::class);

$db = new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($db);
$repo = new DocumentRepository($db, 'site:template-test', 'user:1');
$repo->create($encode(['contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1, 'id' => 'unrelated', 'name' => 'Unchanged']));
$before = $db->rows('SELECT * FROM b_pw_calc_document'); $beforeRevisions = $db->rows('SELECT * FROM b_pw_calc_revision');
$guard = new class($db) implements SqlConnection {
    public bool $readOnly = false;
    public bool $failHeadUpdate = false;
    public array $queries = [];
    public function __construct(private SqlConnection $inner) {}
    public function dialect(): string { return $this->inner->dialect(); }
    public function inTransaction(): bool { return $this->inner->inTransaction(); }
    public function begin(bool $readSnapshot = false): void {
        if ($this->readOnly && !$readSnapshot) { throw new RuntimeException('Listing must use one read snapshot.'); }
        $this->inner->begin($readSnapshot);
    }
    public function commit(): void { $this->inner->commit(); }
    public function rollback(): void { $this->inner->rollback(); }
    public function execute(string $sql, array $parameters = []): void {
        if ($this->readOnly) { throw new RuntimeException('Read attempted mutation.'); }
        if ($this->failHeadUpdate && str_starts_with($sql, 'UPDATE b_pw_calc_library_record')) { throw new RuntimeException('Injected head-update failure.'); }
        $this->checkSql($sql); $this->inner->execute($sql, $parameters);
    }
    public function rows(string $sql, array $parameters = []): array {
        if ($this->readOnly && str_contains($sql, 'body_json')) { throw new RuntimeException('Listing read full template body.'); }
        $this->checkSql($sql); return $this->inner->rows($sql, $parameters);
    }
    private function checkSql(string $sql): void {
        $this->queries[] = $sql;
        if (preg_match('/\b(?:b_pw_calc_(?!library_)\w+|b_iblock\w*|b_option)\b/i', $sql)) { throw new RuntimeException('Template accessed unrelated storage.'); }
    }
};
$app = new PriceTemplateApplication($guard, 'site:template-test', 'user:1');
$other = new PriceTemplateApplication($guard, 'site:other', 'user:2');
$library = new DocumentLibrary($guard, 'site:template-test', 'user:1', 'pricing');
$guard->readOnly = true;
$empty = $app->command(['action' => 'priceTemplates']);
price_check($empty['revision'] === 0 && $empty['items'] === [], 'An empty list is a read, not lazy initialization.');
$guard->readOnly = false;
price_check($db->rows('SELECT * FROM b_pw_calc_library_catalog') === [], 'Listing creates no catalog row.');
$create = ['action' => 'createPriceTemplate', 'expectedCatalogRevision' => 0, 'name' => '  Цифровая печать  ', 'templateJson' => $json];
$created = $app->command($create); $id = $created['record']['id'];
price_check($created['catalog']['revision'] === 1 && $created['record']['revision'] === 1 && $created['record']['name'] === 'Цифровая печать', 'Creation returns authoritative catalog and record.');
price_check($created['record']['bodyJson'] === $json && $created['record']['bodyHash'] === hash('sha256', $json), 'Body and hash agree.');
price_check($created['record']['actor'] === 'user:1', 'Actor is adapter-owned.');
$loaded = $app->command(['action' => 'loadPriceTemplate', 'id' => $id, 'expectedRevision' => 1]);
price_check($loaded === $created['record'], 'Load preserves the immutable record.');
$guard->readOnly = true;
price_check($app->command(['action' => 'priceTemplates']) === $created['catalog'], 'Metadata-only listing is repeatable.');
$guard->readOnly = false;
price_fails(fn() => $app->command($create), DocumentConflict::class, 409);
price_fails(fn() => $app->command(array_replace($create, ['expectedCatalogRevision' => 1, 'name' => 'ЦИФРОВАЯ ПЕЧАТЬ'])), DocumentConflict::class, 409);
price_fails(fn() => $other->command(['action' => 'loadPriceTemplate', 'id' => $id, 'expectedRevision' => 1]), RuntimeException::class, 404);
price_check((new DocumentLibrary($guard, 'site:template-test', 'user:1', 'other'))->listing()['items'] === [], 'Library kinds are isolated.');
$foreign = $other->command($create);
price_check($foreign['catalog']['revision'] === 1 && $foreign['record']['id'] !== $id, 'Same name in a different scope is independent.');
$save = ['action' => 'savePriceTemplate', 'id' => $id, 'expectedRevision' => 1, 'expectedCatalogRevision' => 1, 'templateJson' => $json];
price_check($app->command($save) === $created, 'No-op saves do not advance either revision.');
$modified = $template; $modified['ranges'][0]['price'] = 26;
$updated = $app->command(array_replace($save, ['templateJson' => $encode($modified)]));
price_check($updated['record']['revision'] === 2 && $updated['catalog']['revision'] === 2, 'A save advances both revisions.');
price_check($library->load($id, 1) === $created['record'], 'Previous body is immutable.');
price_fails(fn() => $app->command(['action' => 'loadPriceTemplate', 'id' => $id, 'expectedRevision' => 1]), DocumentConflict::class, 409);
price_fails(fn() => $app->command(array_replace($save, ['expectedCatalogRevision' => 2])), DocumentConflict::class, 409);
$rename = ['action' => 'renamePriceTemplate', 'id' => $id, 'expectedRevision' => 2, 'expectedCatalogRevision' => 2, 'name' => 'Офсет'];
$guard->failHeadUpdate = true;
price_fails(fn() => $app->command($rename), RuntimeException::class);
$guard->failHeadUpdate = false;
price_check($library->listing() === $updated['catalog'] && $library->load($id) === $updated['record'], 'Failure rolls back head, catalog and immutable append together.');
price_check(count($db->rows('SELECT * FROM b_pw_calc_library_revision WHERE record_id = ?', [$id])) === 2, 'Failed append leaves no orphan history.');
$renamed = $app->command($rename);
price_check($renamed['record']['revision'] === 3 && $renamed['record']['name'] === 'Офсет' && $renamed['record']['bodyHash'] === $updated['record']['bodyHash'], 'Rename records history without changing pricing.');
$deleted = $app->command(['action' => 'deletePriceTemplate', 'id' => $id, 'expectedRevision' => 3, 'expectedCatalogRevision' => 3]);
price_check($deleted['catalog']['revision'] === 4 && $deleted['catalog']['items'] === [] && $deleted['record']['deleted'], 'Delete creates a recoverable tombstone.');
price_fails(fn() => $library->load($id), RuntimeException::class, 404);
price_check($library->load($id, 2) === $updated['record'] && $library->load($id, 4) === $deleted['record'], 'History remains retrievable after deletion.');
price_check($created['record']['bodyJson'] === $json, 'Previously applied snapshot has no mutable library coupling.');
$reused = $app->command(array_replace($create, ['expectedCatalogRevision' => 4, 'name' => 'Офсет']));
price_check($reused['record']['id'] !== $id, 'Deleted name may be reused under a new identity.');
foreach ([['scope' => 'site:other'], ['actor' => 'user:2'], ['expectedCatalogRevision' => '5'], ['expectedCatalogRevision' => -1], ['expectedCatalogRevision' => 2147483647], ['name' => ''], ['name' => str_repeat('я', 121)], ['name' => "bad\nname"], ['name' => "\xFF"], ['templateJson' => '[]']] as $fields) {
    price_fails(fn() => $app->command(array_replace($create, ['expectedCatalogRevision' => 5], $fields)));
}
foreach ([0, -1, '1', 2147483647] as $revision) { price_fails(fn() => $app->command(['action' => 'loadPriceTemplate', 'id' => $id, 'expectedRevision' => $revision])); }
price_fails(fn() => $app->command(['action' => 'priceTemplates', 'scope' => 'site:other']));
price_fails(fn() => $app->command(['action' => []]));
price_fails(fn() => $app->command(['action' => 'loadPriceTemplate', 'id' => $id]));
$db->begin(); price_fails(fn() => $library->listing(), LogicException::class); $db->rollback();
$liveId = $reused['record']['id'];
$db->execute('UPDATE b_pw_calc_library_revision SET body_json = ? WHERE record_id = ?', ['{}', $liveId]);
price_fails(fn() => $library->load($liveId), RuntimeException::class);
$db->execute('UPDATE b_pw_calc_library_revision SET body_json = ? WHERE record_id = ?', [$json, $liveId]);
$db->execute('UPDATE b_pw_calc_library_record SET name = ? WHERE id = ?', ['Tampered', $liveId]);
price_fails(fn() => $library->load($liveId), RuntimeException::class);
price_fails(fn() => $library->listing(), RuntimeException::class);
$db->execute('UPDATE b_pw_calc_library_record SET name = ?, head_revision = 999 WHERE id = ?', ['Офсет', $liveId]);
price_fails(fn() => $library->listing(), RuntimeException::class);
$db->execute('UPDATE b_pw_calc_library_record SET head_revision = 1 WHERE id = ?', [$liveId]);
$beforeInstall = $library->listing(); DocumentSchema::install($db); DocumentSchema::install($db);
price_check($library->listing() === $beforeInstall && $library->load($id, 1) === $created['record'], 'Repeated installation preserves library and history.');
price_check($db->rows('SELECT * FROM b_pw_calc_document') === $before && $db->rows('SELECT * FROM b_pw_calc_revision') === $beforeRevisions, 'Calculator bodies and versions are unchanged.');
price_check(!$db->inTransaction(), 'Every transaction is closed.');
$endpoint = file_get_contents(dirname(__DIR__) . '/tools/documents.php');
$dispatch = strpos($endpoint, 'new \\Prospektweb\\Calc\\Documents\\PriceTemplateApplication');
foreach (['POST_REQUIRED', 'ADMIN_REQUIRED', 'check_bitrix_sessid()', 'CSite::GetByID', "'user:' . (int)\$USER->GetID()"] as $boundary) { price_check(strpos($endpoint, $boundary) < $dispatch, 'Trusted boundary precedes template dispatch: ' . $boundary); }
price_check(!str_contains($endpoint, 'DocumentSchema::install'), 'No request-time DDL.');
echo "PASS $checks document price template checks\n";
