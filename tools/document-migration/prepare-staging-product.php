<?php
/** Add one explicit pilot product and draft connection, without public activation. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? ''); $private = realpath($argv[2] ?? '');
if ($root !== '/home/bitrix/www' || !$private || str_starts_with($private . '/', $root . '/') || !is_file('/etc/prospekt-calc-stage/service.env')) { throw new RuntimeException('Isolated staging required.'); }
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REQUEST_METHOD'] = 'GET';
define('STOP_STATISTICS', true); define('NO_KEEP_STATISTIC', true); define('SITE_ID', 's1');
require $root . '/bitrix/modules/main/include/prolog_before.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"); exit(1); });
foreach (['prospektweb.calc', 'prospektweb.frontcalc', 'catalog'] as $module) { if (!\Bitrix\Main\Loader::includeModule($module)) { throw new RuntimeException('Required module missing.'); } }
$config = new \Prospektweb\Frontcalc\Config\ConfigManager(); $products = $config->getProductIblockId(); $offers = $config->getSkuIblockId();
if ($products !== 14 || $offers !== 15) { throw new RuntimeException('Unexpected staging catalogs.'); }
$db = \Bitrix\Main\Application::getConnection();
$repo = new \Prospektweb\Calc\Documents\DocumentRepository(new \Prospektweb\Calc\Documents\BitrixConnection($db), 'site:s1', 'migration:stage-pilot-product');
$draft = $repo->load('db538f5a-4cb1-8c8f-a113-93120de3f035');
if (hash_file('sha256', $private . '/pilot-integration.json') !== '15d67fc198bab73d8ee738340a0134c9d4f9990d104ea485dd02850fc2fd7731') { throw new RuntimeException('Integration source integrity mismatch.'); }
$map = json_decode(file_get_contents($private . '/pilot-stage-map.json'), true, 64, JSON_THROW_ON_ERROR);
$productMap = $private . '/pilot-stage-product.json';
if (!is_file($productMap)) {
    if ($draft['revision'] !== 1 || $draft['activeSitePublication'] !== null) { throw new RuntimeException('Pilot already changed.'); }
    $sectionCode = 'prospekt-calculator-pilot'; $productCode = 'sheet-print-business-cards';
    if (\CIBlockSection::GetList([], ['IBLOCK_ID' => $products, '=CODE' => $sectionCode])->Fetch()
        || \CIBlockElement::GetList([], ['IBLOCK_ID' => $products, '=CODE' => $productCode])->Fetch()) { throw new RuntimeException('Existing pilot content requires manual reconciliation.'); }
    $db->startTransaction();
    try {
        $section = new \CIBlockSection(); $sectionId = (int)$section->Add(['IBLOCK_ID' => $products, 'NAME' => 'Калькуляторы печати', 'CODE' => $sectionCode, 'ACTIVE' => 'Y', 'SORT' => 100]);
        if ($sectionId < 1) { throw new RuntimeException('Pilot section failed: ' . $section->LAST_ERROR); }
        $element = new \CIBlockElement();
        $productId = (int)$element->Add(['IBLOCK_ID' => $products, 'IBLOCK_SECTION_ID' => $sectionId, 'NAME' => 'Визитки', 'CODE' => $productCode,
            'XML_ID' => 'prospekt.pilot.sheet-print.business-cards', 'ACTIVE' => 'Y', 'SORT' => 100,
            'PREVIEW_TEXT' => 'Демонстрационный товар для проверки калькулятора листовой печати на тестовом стенде.', 'PREVIEW_TEXT_TYPE' => 'text']);
        if ($productId < 1) { throw new RuntimeException('Pilot product failed: ' . $element->LAST_ERROR); }
        if (!\CCatalogProduct::Add(['ID' => $productId, 'TYPE' => \Bitrix\Catalog\ProductTable::TYPE_SKU, 'QUANTITY_TRACE' => 'N', 'CAN_BUY_ZERO' => 'Y'])) { throw new RuntimeException('Pilot SKU parent failed.'); }
        $db->commitTransaction();
    } catch (Throwable $e) { $db->rollbackTransaction(); throw $e; }
    file_put_contents($productMap, json_encode(['productId' => $productId, 'sectionId' => $sectionId], JSON_THROW_ON_ERROR), LOCK_EX); chmod($productMap, 0600);
} else { $productId = json_decode(file_get_contents($productMap), true, 64, JSON_THROW_ON_ERROR)['productId']; }
$product = \CIBlockElement::GetList([], ['ID' => $productId], false, false, ['ID', 'IBLOCK_ID', 'XML_ID'])->Fetch();
if (!$product || (int)$product['IBLOCK_ID'] !== $products || $product['XML_ID'] !== 'prospekt.pilot.sheet-print.business-cards') { throw new RuntimeException('Existing product identity mismatch.'); }
$integration = json_decode(file_get_contents($private . '/pilot-integration.json'), false, 64, JSON_THROW_ON_ERROR);
$provider = (new \Prospektweb\Calc\Config\ModuleOptions())->get('DOCUMENT_RESOURCE_PROVIDER');
$connection = (object)['contract' => \Prospektweb\Calc\Documents\SiteConnection::CONTRACT, 'provider' => $provider,
    'productsCatalog' => (string)$products, 'offersCatalog' => (string)$offers,
    'products' => [(object)['key' => (string)$productId, 'presentationId' => 'storefront-c325dc27764f4336be69']],
    'priceTypes' => array_map(static fn($typeId, $key) => (object)['typeId' => $typeId, 'key' => (string)$key], array_keys($map['prices']), array_values($map['prices'])),
    'formBindings' => $integration->formBindings, 'inputMappings' => $integration->inputMappings->mappings, 'outputMappings' => $integration->outputMappings->mappings];
$document = json_decode($draft['bodyJson'], false, 64, JSON_THROW_ON_ERROR);
(new \Prospektweb\Frontcalc\Service\BitrixDocumentSiteCompiler($provider))($document, $connection, $draft['revision'] + 1);
$connectionJson = \Prospektweb\Calc\Documents\SiteConnection::canonical(
    \Prospektweb\Calc\Documents\SiteConnection::encode($connection),
    json_decode($draft['bodyJson'], true, 64, JSON_THROW_ON_ERROR)
);
if ($draft['connectionJson'] === null) { $draft = $repo->save($document->id, $draft['revision'], $draft['bodyJson'], $connectionJson, true); }
elseif ($draft['connectionJson'] !== $connectionJson) { throw new RuntimeException('Existing connection differs; no overwrite.'); }
$url = \CIBlockElement::GetList([], ['ID' => $productId], false, false, ['ID', 'DETAIL_PAGE_URL'])->GetNext()['DETAIL_PAGE_URL'];
echo json_encode(['productId' => $productId, 'url' => $url, 'documentRevision' => $draft['revision'], 'sitePublication' => $draft['activeSitePublication']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
