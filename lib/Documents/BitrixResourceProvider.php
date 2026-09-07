<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
if (!class_exists(BitrixConnection::class, false)) { require_once __DIR__ . '/BitrixConnection.php'; }

/** Reads only explicitly referenced catalog resources. No preset/stage/settings graph,
 * no read repair, no b_option document storage, and no catalog I/O at execution. */
final class BitrixResourceProvider
{
    private const KINDS = ['CALC_MATERIALS' => 'material', 'CALC_MATERIALS_VARIANTS' => 'materialVariant',
        'CALC_OPERATIONS' => 'operation', 'CALC_OPERATIONS_VARIANTS' => 'operationVariant', 'CALC_EQUIPMENT' => 'equipment'];
    private string $provider;
    public function __construct(string $provider) { $this->provider = $provider; }

    public function __invoke(\stdClass $document): array
    {
        if (($document->resources ?? null) === []) { return []; }
        $connection = \Bitrix\Main\Application::getConnection();
        if (\Prospektweb\Calc\Services\BitrixTransactionStateAuthority::isActive($connection)) { throw new \LogicException('Resource snapshot owns its transaction.'); }
        $connection->queryExecute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db = new BitrixConnection($connection); $db->begin();
        try { $result = $this->snapshot($document); $db->commit(); return $result; }
        catch (\Throwable $error) { $db->rollback(); throw $error; }
    }

    private function snapshot(\stdClass $document): array
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock') || !\Bitrix\Main\Loader::includeModule('catalog')) { throw new \RuntimeException('Catalog modules unavailable.'); }
        if (!is_array($document->resources ?? null) || count($document->resources) > 5000) { throw new \InvalidArgumentException('Invalid resource references.'); }
        $config = new \Prospektweb\Calc\Config\ConfigManager();
        $loader = new \Prospektweb\Calc\Services\EntityLoader();
        $references = []; $groups = []; $allIds = [];
        foreach ($document->resources as $ref) {
            $binding = $ref->binding; $catalog = $binding->catalog;
            if ($binding->provider !== $this->provider || !isset(self::KINDS[$catalog]) || self::KINDS[$catalog] !== $ref->kind || !ctype_digit($binding->key) || (int)$binding->key < 1) {
                throw new \InvalidArgumentException('Unsupported external resource binding.');
            }
            $key = $catalog . ':' . $binding->key;
            if (isset($references[$key])) { throw new \InvalidArgumentException('Duplicate external resource binding.'); }
            $references[$key] = $ref; $groups[$catalog][] = (int)$binding->key; $allIds[] = (int)$binding->key;
        }
        $priceTypes = []; $baseTypeId = null;
        $cursor = \CCatalogGroup::GetList([], []);
        while ($group = $cursor->Fetch()) {
            foreach ($document->pricing->types ?? [] as $type) {
                if ($type->code === $group['NAME']) { $priceTypes[(int)$group['ID']] = $type->id; if (($group['BASE'] ?? '') === 'Y') { $baseTypeId = $type->id; } }
            }
        }
        $prices = $loader->loadPrices($allIds); $products = [];
        if ($allIds) {
            $cursor = \Bitrix\Catalog\ProductTable::getList(['filter' => ['@ID' => $allIds], 'select' => ['ID', 'WEIGHT', 'WIDTH', 'LENGTH', 'HEIGHT', 'PURCHASING_PRICE', 'PURCHASING_CURRENCY']]);
            while ($row = $cursor->fetch()) { $products[(int)$row['ID']] = $row; }
        }
        $result = [];
        foreach ($groups as $catalog => $ids) {
            $iblockId = $config->getIblockId($catalog);
            if ($iblockId <= 0) { throw new \RuntimeException('Resource catalog unavailable: ' . $catalog); }
            $rows = $loader->loadElements($iblockId, $ids);
            foreach ($ids as $nativeId) {
                $row = $rows[$nativeId] ?? null;
                if (!$row || ($row['FIELDS']['ACTIVE'] ?? '') !== 'Y') { throw new \RuntimeException('Referenced resource missing or inactive: ' . $catalog . ':' . $nativeId, 409); }
                $ref = $references[$catalog . ':' . $nativeId]; $props = $row['PROPERTIES']; $fields = []; $parameters = []; $moduleFacts = []; $parameterFacts = [];
                foreach ($props as $code => $property) {
                    if (!in_array($code, ['PARAMETRS', 'CML2_LINK', 'SOURCE_LINKS', 'SUPPLIERS', 'ENTITY_KEY', 'FIELDS'], true)) { $fields[$code] = $property['VALUE']; }
                    if (($property['MULTIPLE'] ?? '') === 'N' && in_array($property['PROPERTY_TYPE'] ?? '', ['N', 'S', 'L'], true) && !in_array($code, ['PARAMETRS', 'SOURCE_LINKS', 'SUPPLIERS', 'CML2_LINK'], true)) {
                        $v = $property['VALUE'] ?? null;
                        $moduleFacts[$code] = ['code' => $code, 'title' => $property['NAME'] ?: $code, 'description' => $property['HINT'] ?? '',
                            'valueType' => $property['PROPERTY_TYPE'] === 'N' ? 'number' : 'string', 'value' => $property['PROPERTY_TYPE'] === 'N' && $v !== '' && $v !== null ? (float)$v : $v];
                    }
                }
                $descriptions = array_values((array)($props['PARAMETRS']['DESCRIPTION'] ?? []));
                foreach (array_values((array)($props['PARAMETRS']['VALUE'] ?? [])) as $i => $code) {
                    if (!is_string($code) || $code === '') { continue; }
                    [$raw, $title, $description] = array_pad(explode('|', (string)($descriptions[$i] ?? ''), 3), 3, '');
                    $value = json_decode($raw, true); if (json_last_error() !== JSON_ERROR_NONE) { $value = $raw; }
                    $parameters[] = ['code' => $code, 'value' => $value, 'title' => $title, 'description' => $description];
                    $parameterFacts[$code] = ['code' => $code, 'value' => $value, 'title' => $title ?: $code, 'description' => $description,
                        'valueType' => is_numeric($raw) ? 'number' : (in_array(strtoupper($raw), ['Y', 'N', 'YES', 'NO', 'TRUE', 'FALSE'], true) ? 'boolean' : 'string')];
                }
                $product = $products[$nativeId] ?? []; $attributes = [];
                foreach (['height', 'length', 'weight', 'width'] as $key) { $v = $product[strtoupper($key)] ?? null; $attributes[$key] = $v !== null && $v !== '' ? (float)$v : null; }
                $mappedPrices = []; $basePrice = null;
                foreach ($prices[$nativeId] ?? [] as $price) {
                    $typeId = $priceTypes[$price['CATALOG_GROUP_ID']] ?? null;
                    if ($typeId === null) { continue; }
                    $mappedPrices[] = self::normalizePrice($price, $typeId);
                    if ($typeId === $baseTypeId) { $basePrice = $price['PRICE']; }
                }
                $parentId = null; $parentNative = (int)($props['CML2_LINK']['VALUE'] ?? 0);
                if ($parentNative > 0) {
                    $parentCatalog = $ref->kind === 'materialVariant' ? 'CALC_MATERIALS' : 'CALC_OPERATIONS';
                    $parentId = $references[$parentCatalog . ':' . $parentNative]->id ?? null;
                    if ($parentId === null) { throw new \RuntimeException('Resource parent must be explicitly referenced.', 409); }
                }
                $margins = array_pad(explode(',', (string)($props['FIELDS']['VALUE'] ?? '')), 4, '0');
                $machine = [];
                foreach (['top', 'right', 'bottom', 'left'] as $i => $key) {
                    $v = trim($margins[$i]); if ($v !== '' && !is_numeric($v)) { throw new \RuntimeException('Invalid machine margins.', 409); }
                    $machine[$key] = (float)($v ?: 0);
                }
                $machine['horizontalSum'] = $machine['left'] + $machine['right']; $machine['verticalSum'] = $machine['top'] + $machine['bottom'];
                $purchase = isset($product['PURCHASING_PRICE']) ? (float)$product['PURCHASING_PRICE'] : null;
                $result[] = ['id' => $ref->id, 'name' => html_entity_decode((string)$row['FIELDS']['NAME']), 'description' => (string)($row['FIELDS']['PREVIEW_TEXT'] ?? ''),
                    'kind' => $ref->kind, 'binding' => $ref->binding, 'parentId' => $parentId, 'attributes' => (object)$attributes, 'fields' => (object)$fields, 'parameters' => $parameters, 'prices' => $mappedPrices,
                    'purchasingPrice' => $purchase, 'purchasingCurrency' => $product['PURCHASING_CURRENCY'] ?? null, 'machine' => (object)$machine,
                    'selectionFacts' => ['catalog' => (object)($attributes + ['purchasingPrice' => $purchase, 'basePrice' => $basePrice]),
                        'module' => (object)$moduleFacts, 'parameters' => (object)$parameterFacts, 'supplierIds' => array_values(array_map('intval', (array)($props['SUPPLIERS']['VALUE'] ?? [])))]];
            }
        }
        return $result;
    }

    /** Legacy catalog percentage codes are decoded at the adapter boundary only. */
    public static function normalizePrice(array $price, string $typeId): array
    {
        $currency = $price['CURRENCY'];
        $mode = $currency === 'PRC' ? 'markupPercent' : ($currency === 'MRG' ? 'marginPercent' : 'amount');
        return ['typeId' => $typeId, 'price' => $price['PRICE'], 'mode' => $mode,
            'currency' => $mode === 'amount' ? $currency : null,
            'quantityFrom' => $price['QUANTITY_FROM'], 'quantityTo' => $price['QUANTITY_TO']];
    }
}
