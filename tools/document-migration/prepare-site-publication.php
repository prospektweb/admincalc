<?php
/** CLI-only pinned pilot migration. Keeps the public feature switch OFF. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? ''); $directory = realpath($argv[2] ?? '');
if (!$root || !$directory || str_starts_with($directory . '/', $root . '/') || !is_file($root . '/bitrix/modules/main/include/prolog_before.php')) { throw new RuntimeException('Existing site and private directory required.'); }
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REQUEST_METHOD'] = 'GET';
define('STOP_STATISTICS', true); define('NO_KEEP_STATISTIC', true); define('SITE_ID', 's1');
require $root . '/bitrix/modules/main/include/prolog_before.php';
foreach (['prospektweb.calc', 'prospektweb.frontcalc'] as $moduleId) {
    if (!\Bitrix\Main\Loader::includeModule($moduleId)) { throw new RuntimeException('Module unavailable.'); }
}
$option = \Bitrix\Main\Config\Option::get('prospektweb.calc', 'DOCUMENT_PUBLIC_RUNTIME', 'N');
if ($option !== 'N') { throw new RuntimeException('Public runtime must remain off during preparation.'); }
$writeOnce = static function (string $name, string $body) use ($directory): void {
    $path = $directory . '/' . $name;
    if (is_file($path)) { if (!hash_equals(hash_file('sha256', $path), hash('sha256', $body))) { throw new RuntimeException('Private artifact differs: ' . $name); } return; }
    $file = fopen($path, 'x'); if (!$file || fwrite($file, $body) !== strlen($body)) { throw new RuntimeException('Backup failed.'); }
    fclose($file); chmod($path, 0600);
};
$readPinned = static function (string $name, string $hash) use ($directory): object|array {
    $body = file_get_contents($directory . '/' . $name);
    if (!hash_equals($hash, hash('sha256', $body))) { throw new RuntimeException('Import integrity failed: ' . $name); }
    return json_decode($body, false, 64, JSON_THROW_ON_ERROR);
};
$integration = $readPinned('integration.json', '15d67fc198bab73d8ee738340a0134c9d4f9990d104ea485dd02850fc2fd7731');
$external = $readPinned('externalBindings.json', 'adf14c927b85550d6794a839e07216609ff1c8cd868aadde19955f5a44da23d3');
$old = (new \Prospektweb\Calc\Services\CalculatorVersionRuntimePublicationService())->resolve(12740);
if (($old['contentHash'] ?? '') !== '61791207acb91446debf1057f30c14d070c5d9069b44116991b21b6fdce59192'
    || $integration->sourcePublicationHash !== $old['contentHash']) { throw new RuntimeException('Legacy pilot changed.'); }
$repo = new \Prospektweb\Calc\Documents\DocumentRepository(new \Prospektweb\Calc\Documents\BitrixConnection(\Bitrix\Main\Application::getConnection()), 'site:s1', 'migration:site-publication-20260907');
$id = 'db538f5a-4cb1-8c8f-a113-93120de3f035'; $before = $repo->load($id);
$gateway = new \Prospektweb\Calc\Documents\BitrixCoreGateway();
if ($before['revision'] === 3 && $before['bodyHash'] === '15bba8fa76339c7fd99075243836d0714bd754ea736413c93580457687fb0b7d' && $before['connectionJson'] === null && $before['activeSitePublication'] === null) {
    $writeOnce('revision-3.backup.json', json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $writeOnce('legacy-publication.backup.json', json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $document = json_decode($before['bodyJson'], false, 64, JSON_THROW_ON_ERROR);
    $removed = [];
    foreach ($document->presentations->views as $view) {
        foreach (['draft.field-27', 'draft.field-31'] as $field) {
            if (isset($view->presentation->field_patches->$field)) {
                if (array_filter($document->form->fields, static fn($f) => $f->fieldId === $field)) { throw new RuntimeException('Expected orphan is now a real field.'); }
                $removed[] = ['presentation' => $view->id, 'field' => $field]; unset($view->presentation->field_patches->$field);
            }
        }
    }
    if (count($removed) !== 2) { throw new RuntimeException('Unexpected orphan patch count.'); }
    $validated = $gateway(['action' => 'validate', 'document' => $document]);
    $types = array_column(json_decode(json_encode($document->pricing->types), true), 'id');
    $connection = (object)['contract' => \Prospektweb\Calc\Documents\SiteConnection::CONTRACT, 'provider' => $integration->provider,
        'productsCatalog' => '14', 'offersCatalog' => '15',
        'products' => array_map(static fn($a) => (object)['key' => (string)$a->productId, 'presentationId' => $a->storefrontId], $integration->productAssignments->assignments),
        'priceTypes' => array_values(array_map(static fn($a) => (object)['key' => $a->sourceId, 'typeId' => $a->id], array_filter($external, static fn($a) => $a->category === 'priceType' && in_array($a->id, $types, true)))),
        'formBindings' => $integration->formBindings, 'inputMappings' => $integration->inputMappings->mappings, 'outputMappings' => $integration->outputMappings->mappings];
    // Validate live catalog and complete form before appending any migration revision.
    (new \Prospektweb\Frontcalc\Service\BitrixDocumentSiteCompiler($integration->provider))($document, $connection, 4);
    $saved = $repo->save($id, 3, $validated['documentJson'], \Prospektweb\Calc\Documents\SiteConnection::encode($connection), true);
    $writeOnce('revision-4.expected.json', json_encode(['bodyHash' => $saved['bodyHash'], 'connectionHash' => $saved['connectionHash'], 'removedOrphanPatches' => $removed], JSON_THROW_ON_ERROR));
} else {
    $expected = json_decode(file_get_contents($directory . '/revision-4.expected.json'), true, 64, JSON_THROW_ON_ERROR);
    if ($before['revision'] !== 4 || $before['bodyHash'] !== $expected['bodyHash'] || $before['connectionHash'] !== $expected['connectionHash']) { throw new RuntimeException('Draft changed; refusing overwrite.'); }
    $saved = $before;
}
$app = new \Prospektweb\Calc\Documents\DocumentApplication($repo, $gateway,
    new \Prospektweb\Calc\Documents\BitrixResourceProvider($integration->provider), new \Prospektweb\Frontcalc\Service\BitrixDocumentSiteCompiler($integration->provider));
$publication = $saved['activeSitePublication'] === null
    ? $app->command(['action' => 'publishSite', 'id' => $id, 'expectedRevision' => 4, 'expectedSitePublication' => null]) : $repo->sitePublication($id);
$bundle = \Prospektweb\Frontcalc\Service\DocumentSiteRuntime::project($publication);
$source = $bundle['documents']['logic']['source'];
$client = new \Prospektweb\Frontcalc\Service\CalcServerClient();
$url = 'https://prospektweb-calc-server-13bd.twc1.net';
$context = $client->createContext($url, 20, $source);
if (!$context['success']) { throw new RuntimeException('Native context failed: ' . json_encode($context['error'])); }
$values = ['volume' => 1000, 'system.layout-count' => 1, 'system.deadline-type' => 'strict', 'format.width' => 90, 'format.length' => 50,
    'method' => 'OFSET', 'color.scheme' => '4+4', 'type.material' => 'paper', 'type.paper' => 'mel-mat-paper', 'density.paper' => '150', 'section:protection' => false, 'protection' => '', 'options' => []];
$cases = [['name' => 'offset', 'values' => $values, 'base' => 7073.168235294117],
    ['name' => 'offset-lamination', 'values' => array_replace($values, ['section:protection' => true, 'protection' => 'lamination-rulon', 'lamination' => 'gloss-low', 'lamination.sides' => '2']), 'base' => 8979.800861588234],
    ['name' => 'digital', 'values' => array_replace($values, ['method' => 'DIGITAL', 'color.scheme' => '4+0']), 'base' => 2077.7928333333334]];
$report = [];
foreach ($cases as $case) {
    $offer = ['id' => -1, 'name' => $case['name'], 'calculationInput' => ['contract' => 'prospektweb.calculator/frontcalc-input-v1', 'calculator' => ['id' => $id, 'publicationId' => $publication['id']], 'values' => $case['values']],
        'calculationExecution' => ['contract' => 'prospektweb.calc.execution-context/v1', 'unitCount' => 1000, 'runCount' => 1, 'layoutCount' => 1, 'deadlineType' => 'strict']];
    $result = $client->calculateContext($url, 20, $context['data']['contextId'], $context['data']['sourceVersion'], [$offer]);
    $quote = $result['data'][0] ?? [];
    if (!$result['success'] || ($quote['calculator_contract'] ?? '') !== $source['contract'] || abs(($quote['purchase_price'] ?? -1) - $case['base']) > 1e-8 || count($quote['price_ranges_with_markup'] ?? []) < 1) {
        throw new RuntimeException('Native quote mismatch: ' . $case['name'] . ': ' . json_encode($result['error'] ?? $quote));
    }
    $report[] = ['case' => $case['name'], 'basePrice' => $quote['purchase_price'], 'priceRangeRows' => count($quote['price_ranges_with_markup'])];
}
foreach ($bundle['connection']['products'] as $product) {
    $binding = $repo->productBinding($integration->provider, '14', $product['key']);
    if (!$binding || $binding['publication_id'] !== $publication['id']) { throw new RuntimeException('Binding projection mismatch.'); }
}
$report = ['documentId' => $id, 'revision' => 4, 'publicId' => $publication['publicId'], 'publicationId' => $publication['id'], 'snapshotHash' => $publication['snapshotHash'],
    'productBindings' => count($bundle['connection']['products']), 'quotes' => $report, 'publicRuntime' => $option];
$writeOnce('native-verification.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
