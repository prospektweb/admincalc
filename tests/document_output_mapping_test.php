<?php
declare(strict_types=1);
namespace Bitrix\Main { final class Loader { public static bool $available = true; public static function includeModule(string $id): bool { return self::$available; } } }
namespace Prospektweb\Frontcalc\Config { final class ConfigManager { public function getProductIblockId(): int { return 14; } public function getSkuIblockId(): int { return 15; } } }
namespace {
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentApplication.php';
require_once dirname(__DIR__) . '/lib/Documents/BitrixOutputMappingValidator.php';
require_once dirname(__DIR__) . '/lib/Services/CatalogOutputMappingService.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentRepository, DocumentApplication, DocumentVersions, SiteConnection, DocumentOutputMappings, BitrixOutputMappingValidator};
$checks = 0;
$check = static function (bool $value, string $label) use (&$checks): void { $checks++; if (!$value) throw new RuntimeException($label); };
$rejects = static function (callable $work, int $code = 0) use ($check): void { try { $work(); } catch (Throwable $e) { $check($e->getCode() === $code, $e->getMessage()); return; } throw new RuntimeException('Expected rejection'); };
$pairs = []; foreach (DocumentOutputMappings::PAIRS as $source => $target) $pairs[] = (object)['source_path' => $source, 'target_path' => $target];
$legacy = (new ReflectionClass(Prospektweb\Calc\Services\CatalogOutputMappingService::class))->getConstant('PAIRS');
$check(DocumentOutputMappings::PAIRS === $legacy, 'All seven original pairs, no new write targets');
DocumentOutputMappings::validate([]); DocumentOutputMappings::validate($pairs); DocumentOutputMappings::validate(array_reverse($pairs));
foreach ([null, new stdClass(), [$pairs[0]], array_merge(array_slice($pairs, 1), [$pairs[1]]),
    array_merge([(object)['source_path' => 'result.purchasePrice', 'target_path' => 'catalog.offer.secret']], array_slice($pairs, 1)),
    array_merge([(object)((array)$pairs[0] + ['revision' => 1])], array_slice($pairs, 1)),
    array_merge([null], array_slice($pairs, 1)), array_merge([(array)$pairs[0]], array_slice($pairs, 1))] as $bad) $rejects(fn() => DocumentOutputMappings::validate($bad));
$body = json_encode(['contract' => 'prospektweb.calculator/document-v1', 'schemaVersion' => 1, 'id' => 'sheet', 'name' => 'Sheet',
    'form' => ['fields' => [['fieldId' => 'volume']]], 'presentations' => ['views' => []], 'pricing' => ['types' => []]], JSON_THROW_ON_ERROR);
$connection = (object)['contract' => SiteConnection::CONTRACT, 'provider' => 'bitrix:test', 'productsCatalog' => '14', 'offersCatalog' => '15',
    'products' => [], 'priceTypes' => [], 'formBindings' => new stdClass(), 'inputMappings' => [], 'outputMappings' => $pairs];
$json = json_encode($connection, JSON_THROW_ON_ERROR);
$db = new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($db);
$repo = new DocumentRepository($db, 'site:test', 'user:1'); $calls = []; $race = null;
$core = static function (array $request) use (&$calls): array {
    $calls[] = $request['action']; if ($request['action'] !== 'validate') throw new RuntimeException('Unexpected compilation');
    $json = json_encode($request['document'], JSON_THROW_ON_ERROR); return ['documentJson' => $json, 'documentHash' => hash('sha256', $json)];
};
$noWork = static function (): array { throw new RuntimeException('Unexpected resources, publication or input catalog access'); };
$semantic = new BitrixOutputMappingValidator('bitrix:test');
$validator = static function (object $doc, object $site) use ($semantic, &$calls, &$race): array {
    $calls[] = ['output-check', $doc->name, count($site->outputMappings)]; if ($race) $race(); return $semantic($doc, $site);
};
$app = new DocumentApplication($repo, $core, $noWork, $noWork, $noWork, $validator);
$app->command(['action' => 'create', 'documentJson' => $body]); $repo->save('sheet', 1, $body, $json, true);
$version = DocumentVersions::primaryId('sheet');
$command = ['action' => 'checkOutputMappings', 'id' => 'sheet', 'versionId' => $version, 'expectedRevision' => 2,
    'documentJson' => str_replace('Sheet', 'Unsaved', $body), 'connectionJson' => $json];
$baseline = [$repo->load('sheet'), $repo->history('sheet'), $repo->versions()->listing('sheet')]; $calls = [];
$result = $app->command($command);
$check($result['contract'] === 'prospektweb.calculator/output-mapping-check-v1' && $result['valid'] === true, 'Native check contract');
$check($result['documentId'] === 'sheet' && $result['versionId'] === $version && $result['revision'] === 2, 'Pinned identity');
$check($result['bodyHash'] === hash('sha256', $command['documentJson']) && $result['connectionHash'] === hash('sha256', SiteConnection::canonical($json, json_decode($body, true))), 'Draft hashes');
$check($result['issues'][0]['code'] === 'output_mappings.writeback_unavailable', 'No claim that settings enable a writer');
$check($calls === ['validate', ['output-check', 'Unsaved', 7]], 'No resources, input catalog, compilation or catalog mutation');
$check([$repo->load('sheet'), $repo->history('sheet'), $repo->versions()->listing('sheet')] === $baseline, 'Check is read-only');
$empty = clone $connection; $empty->outputMappings = [];
$emptyResult = $app->command(array_replace($command, ['connectionJson' => json_encode($empty, JSON_THROW_ON_ERROR)]));
$check(count($emptyResult['issues']) === 2 && $emptyResult['issues'][0]['code'] === 'output_mappings.not_configured', 'Empty state is not silently enabled');
foreach (['provider' => 'bitrix:other', 'productsCatalog' => '16', 'offersCatalog' => '17'] as $key => $value) {
    $foreign = clone $connection; $foreign->$key = $value; $rejects(fn() => $semantic(json_decode($body), $foreign));
}
\Bitrix\Main\Loader::$available = false; $rejects(fn() => $semantic(json_decode($body), $connection), 503); \Bitrix\Main\Loader::$available = true;
$calls = []; $rejects(fn() => $app->command(array_replace($command, ['expectedRevision' => 1])), 409); $check($calls === [], 'Stale before external work');
$foreignApp = new DocumentApplication(new DocumentRepository($db, 'site:other', 'user:2'), $core, $noWork, $noWork, $noWork, $validator);
$rejects(fn() => $foreignApp->command($command), 404); $check($calls === [], 'Foreign site before external work');
foreach (['preset_id' => 12740, 'actor' => 'spoof', 'writeback' => true] as $key => $value) $rejects(fn() => $app->command($command + [$key => $value]));
$unavailable = new DocumentApplication($repo, $core, $noWork); $rejects(fn() => $unavailable->command($command), 503);
$badSite = clone $connection; $badSite->outputMappings = [$pairs[0]];
$rejects(fn() => $app->command(['action' => 'saveVersion', 'id' => 'sheet', 'versionId' => $version, 'expectedRevision' => 2, 'documentJson' => $body, 'connectionJson' => json_encode($badSite, JSON_THROW_ON_ERROR)]));
$check([$repo->load('sheet'), $repo->history('sheet'), $repo->versions()->listing('sheet')] === $baseline, 'Invalid output save never changes paired storage');
$race = static function () use (&$race, $repo, $version, $body): void { $race = null; $repo->versions()->save('sheet', $version, 2, str_replace('Sheet', 'Winner', $body), null, false); };
$rejects(fn() => $app->command($command), 409);
$check(json_decode($repo->load('sheet')['bodyJson'])->name === 'Winner', 'Late check preserves concurrent winner');
$invalidStored = SiteConnection::encode($badSite);
$db->execute('UPDATE b_pw_calc_revision SET connection_json = ?, connection_hash = ? WHERE document_id = ? AND revision = ?',
    [$invalidStored, hash('sha256', $invalidStored), 'sheet', 3]);
$calls = [];
$rejects(fn() => $app->command(['action' => 'publishSite', 'id' => 'sheet', 'expectedRevision' => 3, 'expectedSitePublication' => null]));
$check($calls === [] && $db->rows('SELECT id FROM b_pw_calc_site_publication') === [], 'Old invalid stored mappings cannot reach compiler or publication');
echo "PASS $checks native output-mapping assertions\n";
}
