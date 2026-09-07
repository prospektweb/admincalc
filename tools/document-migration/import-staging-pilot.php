<?php
/** Explicit content import for the isolated pilot site. Never part of the installer. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? ''); $private = realpath($argv[2] ?? '');
if ($root !== '/home/bitrix/www' || !$private || str_starts_with($private . '/', $root . '/') || !is_file('/etc/prospekt-calc-stage/service.env')) { throw new RuntimeException('Isolated staging root and private import directory required.'); }
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REQUEST_METHOD'] = 'GET';
define('STOP_STATISTICS', true); define('NO_KEEP_STATISTIC', true); define('SITE_ID', 's1');
require $root . '/bitrix/modules/main/include/prolog_before.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"); exit(1); });
foreach (['prospektweb.calc', 'prospektweb.frontcalc', 'iblock', 'catalog', 'currency'] as $module) {
    if (!\Bitrix\Main\Loader::includeModule($module)) { throw new RuntimeException('Required module missing.'); }
}
putenv('PROSPEKTWEB_CALC_SERVER_CLIENT_ID=prospektprint-stage');
putenv('PROSPEKTWEB_FRONTCALC_CLIENT_ID=prospektprint-stage');
$read = static fn(string $file) => json_decode(file_get_contents($private . '/' . $file), false, 64, JSON_THROW_ON_ERROR);
$document = $read('pilot-document.json'); $resources = $read('pilot-resources.json');
if ($document->id !== 'db538f5a-4cb1-8c8f-a113-93120de3f035' || count($resources) !== 68
    || hash_file('sha256', $private . '/pilot-document.json') !== '15bba8fa76339c7fd99075243836d0714bd754ea736413c93580457687fb0b7d') { throw new RuntimeException('Unexpected source pilot.'); }
if (hash_file('sha256', $private . '/pilot-resources.json') !== '8502e57074792d0ca64653684c1440163d0b09904a828da0e31a6a559d936d8b') { throw new RuntimeException('Resource source integrity mismatch.'); }
if (hash_file('sha256', $private . '/pilot-extra-equipment.json') !== '1be6db9c60d97728e5f76ee29c8518ee2527648956885a9b650835df5f71e479') { throw new RuntimeException('Extra equipment source integrity mismatch.'); }
$db = \Bitrix\Main\Application::getConnection();
$options = new \Prospektweb\Calc\Config\ModuleOptions();
$provider = $options->get('DOCUMENT_RESOURCE_PROVIDER');
if ($provider === '' || $provider === 'bitrix:prospektprint.ru' || $options->get('CALC_SERVER_URL') !== 'https://127.0.0.1:3443') { throw new RuntimeException('Staging identity is not isolated.'); }
$repo = new \Prospektweb\Calc\Documents\DocumentRepository(new \Prospektweb\Calc\Documents\BitrixConnection($db), 'site:s1', 'migration:staging-pilot');
$core = new \Prospektweb\Calc\Documents\BitrixCoreGateway();
$registry = new \Prospektweb\Calc\Documents\ResourceCatalogRegistry();
$mapFile = $private . '/pilot-stage-map.json';
if (!is_file($mapFile)) {
    $catalogs = [];
    foreach (array_unique(array_map(static fn($r) => $r->binding->catalog, $resources)) as $code) { $catalogs[$code] = $registry->getIblockId($code); }
    $catalogs['CALC_SUPPLIERS'] = $registry->getIblockId('CALC_SUPPLIERS');
    foreach ($catalogs as $id) {
        if ((int)\CIBlockElement::GetList([], ['IBLOCK_ID' => $id], [], false) !== 0) { throw new RuntimeException('Import requires empty resource catalogs.'); }
    }
    $suppliers = [
        16829 => ['Дубль В — ООО "ДВ Импекс"', 'doublev', 'prospekt.supplier.doublev'],
        16909 => ['Дубль В — ООО "ДУБЛЬ В Центр"', 'dubl-v-tsentr', 'prospekt.supplier.dubl-v-tsentr'],
        16910 => ['Европапир', 'europapier', 'prospekt.supplier.europapier'],
        16919 => ['Петробумага', 'petrobumaga', 'prospekt.supplier.petrobumaga'],
    ];
    $map = ['resources' => [], 'native' => [], 'suppliers' => [], 'prices' => [], 'catalogs' => $catalogs];
    $element = new \CIBlockElement(); $db->startTransaction();
    try {
        foreach ($document->pricing->types as $type) {
            $row = \CCatalogGroup::GetList([], ['NAME' => $type->code])->Fetch();
            $id = $row ? (int)$row['ID'] : (int)\CCatalogGroup::Add(['NAME' => $type->code, 'BASE' => $type->base ? 'Y' : 'N', 'SORT' => $type->sort, 'USER_LANG' => ['ru' => $type->code], 'USER_GROUP' => [1], 'USER_GROUP_BUY' => [1]]);
            if ($id < 1) { throw new RuntimeException('Cannot create pilot price type.'); }
            $map['prices'][$type->id] = $id;
        }
        foreach ($suppliers as $source => [$name, $code, $xml]) {
            $id = (int)$element->Add(['IBLOCK_ID' => $catalogs['CALC_SUPPLIERS'], 'NAME' => $name, 'CODE' => $code, 'XML_ID' => $xml, 'ACTIVE' => 'Y']);
            if ($id < 1) { throw new RuntimeException('Supplier import failed: ' . $element->LAST_ERROR); }
            $map['suppliers'][$source] = $id;
        }
        // The selected digital operation also supports 3070L. It is not executed by
        // this pilot, but its directory link must remain valid on the target site.
        $extra = $read('pilot-extra-equipment.json');
        if ($extra->fields->ID !== '1081' || $extra->fields->NAME !== '3070L' || $extra->prices !== []) { throw new RuntimeException('Unexpected extra equipment.'); }
        $id = (int)$element->Add(['IBLOCK_ID' => $catalogs['CALC_EQUIPMENT'], 'NAME' => $extra->fields->NAME, 'CODE' => 'pilot-extra-1081', 'XML_ID' => 'pilot:equipment:1081',
            'ACTIVE' => $extra->fields->ACTIVE, 'PREVIEW_TEXT' => $extra->fields->PREVIEW_TEXT, 'PREVIEW_TEXT_TYPE' => $extra->fields->PREVIEW_TEXT_TYPE]);
        if ($id < 1) { throw new RuntimeException('Extra equipment import failed.'); }
        $map['native']['1081'] = $id; $extraProps = [];
        foreach ($extra->properties as $code => $property) {
            if ($property->MULTIPLE === 'Y' && is_array($property->VALUE)) {
                $extraProps[$code] = []; $descriptions = array_values((array)$property->DESCRIPTION);
                foreach (array_values($property->VALUE) as $i => $value) { $extraProps[$code][] = ['VALUE' => $value, 'DESCRIPTION' => $descriptions[$i] ?? '']; }
            } else { $extraProps[$code] = $property->VALUE; }
        }
        \CIBlockElement::SetPropertyValuesEx($id, $catalogs['CALC_EQUIPMENT'], $extraProps);
        $extraProduct = ['ID' => $id, 'QUANTITY_TRACE' => 'N', 'CAN_BUY_ZERO' => 'Y'];
        foreach (['WEIGHT', 'WIDTH', 'LENGTH', 'HEIGHT', 'PURCHASING_PRICE', 'PURCHASING_CURRENCY'] as $key) { if (($extra->product->$key ?? null) !== null) { $extraProduct[$key] = $extra->product->$key; } }
        if (!\CCatalogProduct::Add($extraProduct)) { throw new RuntimeException('Extra equipment catalog record failed.'); }
        foreach ($resources as $resource) {
            $id = (int)$element->Add(['IBLOCK_ID' => $catalogs[$resource->binding->catalog], 'NAME' => $resource->name,
                'CODE' => 'pilot-' . $resource->id, 'XML_ID' => 'pilot:' . $resource->id, 'ACTIVE' => 'Y',
                'PREVIEW_TEXT' => $resource->description, 'PREVIEW_TEXT_TYPE' => 'html']);
            if ($id < 1) { throw new RuntimeException('Resource import failed: ' . $element->LAST_ERROR); }
            $map['resources'][$resource->id] = $id; $map['native'][$resource->binding->key] = $id;
        }
        foreach ($resources as $resource) {
            $id = $map['resources'][$resource->id]; $props = (array)$resource->fields;
            foreach (['SUPPORTED_EQUIPMENT_LIST', 'SUPPORTED_MATERIALS_VARIANTS_LIST'] as $code) {
                if (is_array($props[$code] ?? null)) { $props[$code] = array_map(static fn($old) => $map['native'][(string)$old] ?? throw new RuntimeException('Unresolved resource link.'), $props[$code]); }
            }
            $props['PARAMETRS'] = array_map(static fn($p) => ['VALUE' => $p->code, 'DESCRIPTION' => (is_string($p->value) ? $p->value : json_encode($p->value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . '|' . $p->title . '|' . $p->description], $resource->parameters);
            if ($resource->parentId !== null) { $props['CML2_LINK'] = $map['resources'][$resource->parentId]; }
            if (in_array($resource->kind, ['material', 'materialVariant'], true)) {
                $props['ENTITY_KEY'] = $resource->selectionFacts->module->ENTITY_KEY->value ?? '';
                $props['SUPPLIERS'] = array_values(array_map(static fn($old) => $map['suppliers'][(string)$old] ?? throw new RuntimeException('Unresolved supplier.'), array_filter($resource->selectionFacts->supplierIds, static fn($id) => $id > 0)));
            }
            if ($resource->kind === 'equipment') { $props['FIELDS'] = implode(',', array_map(static fn($key) => (string)$resource->machine->$key, ['top', 'right', 'bottom', 'left'])); }
            \CIBlockElement::SetPropertyValuesEx($id, $catalogs[$resource->binding->catalog], $props);
            $product = ['ID' => $id, 'QUANTITY_TRACE' => 'N', 'CAN_BUY_ZERO' => 'Y', 'QUANTITY' => 0];
            foreach ((array)$resource->attributes as $key => $value) { if ($value !== null) { $product[strtoupper($key)] = $value; } }
            if ($resource->purchasingPrice !== null) { $product['PURCHASING_PRICE'] = $resource->purchasingPrice; $product['PURCHASING_CURRENCY'] = $resource->purchasingCurrency; }
            if (!\CCatalogProduct::Add($product)) { throw new RuntimeException('Resource catalog record failed.'); }
            foreach ($resource->prices as $price) {
                $currency = $price->mode === 'markupPercent' ? 'PRC' : ($price->mode === 'marginPercent' ? 'MRG' : $price->currency);
                if (!\CPrice::Add(['PRODUCT_ID' => $id, 'CATALOG_GROUP_ID' => $map['prices'][$price->typeId], 'PRICE' => $price->price, 'CURRENCY' => $currency,
                    'QUANTITY_FROM' => $price->quantityFrom, 'QUANTITY_TO' => $price->quantityTo])) { throw new RuntimeException('Resource price import failed.'); }
            }
        }
        $db->commitTransaction();
    } catch (Throwable $error) { $db->rollbackTransaction(); throw $error; }
    file_put_contents($mapFile, json_encode($map, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX); chmod($mapFile, 0600);
} else { $map = json_decode(file_get_contents($mapFile), true, 64, JSON_THROW_ON_ERROR); }
foreach ($document->resources as $reference) { $reference->binding->provider = $provider; $reference->binding->key = (string)$map['resources'][$reference->id]; }
// Same two known orphan presentation patches removed in the accepted source rev4.
foreach ($document->presentations->views as $view) { foreach (['draft.field-27', 'draft.field-31'] as $field) { unset($view->presentation->field_patches->$field); } }
$validated = $core(['action' => 'validate', 'document' => $document]);
$existing = $db->query("SELECT id FROM b_pw_calc_document WHERE id='db538f5a-4cb1-8c8f-a113-93120de3f035' AND scope_id='site:s1'")->fetch();
$saved = $existing ? $repo->load($document->id) : $repo->create($validated['documentJson']);
if ($saved['bodyHash'] !== $validated['documentHash']) { throw new RuntimeException('Existing pilot differs. No overwrite allowed.'); }
$live = (new \Prospektweb\Calc\Documents\BitrixResourceProvider($provider))($document);
file_put_contents($private . '/pilot-stage-resources.json', json_encode($live, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
file_put_contents($private . '/pilot-stage-document.json', $validated['documentJson'], LOCK_EX);
chmod($private . '/pilot-stage-resources.json', 0600); chmod($private . '/pilot-stage-document.json', 0600);
echo json_encode(['id' => $saved['id'], 'revision' => $saved['revision'], 'hash' => $saved['bodyHash'], 'resources' => count($live), 'suppliers' => count($map['suppliers']), 'priceTypes' => $map['prices']], JSON_THROW_ON_ERROR) . "\n";
