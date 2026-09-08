<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/BitrixConnection.php';
require_once __DIR__ . '/ResourceCardMutationPlan.php';

/** Executes a complete server-built plan. Never begins or commits a transaction. */
final class BitrixResourceCardWriter
{
    private $mutate;
    private $within;
    public function __construct(private SqlConnection $db, ?callable $mutate = null, ?callable $within = null)
    {
        if ($mutate === null) {
            if (!$db instanceof BitrixConnection || $db->nativeConnection() !== \Bitrix\Main\Application::getConnection()
                || !\Bitrix\Main\Loader::includeModule('iblock') || !\Bitrix\Main\Loader::includeModule('catalog')) throw new \LogicException('Resource APIs require the coordinator Bitrix connection.');
            $within = static function(callable $operation) {
                // These are technical resource directories, not storefront SKU
                // price summaries. Native automatic SKU calculation would replace
                // a separately authored parent cost with the cheapest variant.
                // Use Bitrix's balanced, request-local API; never change settings
                // or remove handlers. Its calculate methods return before queuing
                // work while disabled, so no delayed recalculation escapes here.
                $allowed = \Bitrix\Catalog\Product\Sku::allowedUpdateAvailable();
                \Bitrix\Catalog\Product\Sku::disableUpdateAvailable();
                try { return $operation(); }
                finally {
                    \Bitrix\Catalog\Product\Sku::enableUpdateAvailable();
                    if (\Bitrix\Catalog\Product\Sku::allowedUpdateAvailable() !== $allowed) throw new \LogicException('Native SKU update guard was not restored.');
                }
            };
            $mutate = static function(string $action, int $id, array $fields) {
                if ($action === 'element.add' || $action === 'element.update') {
                    $element = new \CIBlockElement();
                    $result = $action === 'element.add' ? $element->Add($fields) : $element->Update($id, $fields);
                    if (!$result) throw new DocumentConflict($element->LAST_ERROR ?: 'Не удалось сохранить ресурс.');
                    return $result;
                }
                if ($action === 'properties.set') {
                    \CIBlockElement::SetPropertyValuesEx($id, $fields['iblock'], $fields['values']);
                    return true; // The API is void; the coordinator verifies actual values.
                }
                return match ($action) {
                    'product.update' => \CCatalogProduct::Update($id, $fields),
                    'product.add' => \CCatalogProduct::Add(['ID' => $id] + $fields),
                    'price.update' => \CPrice::Update($id, $fields),
                    'price.add' => \CPrice::Add($fields),
                    'price.delete' => \CPrice::Delete($id),
                    default => throw new \LogicException('Unknown resource mutation.'),
                };
            };
        }
        $this->mutate = $mutate;
        $this->within = $within ?? static fn(callable $operation) => $operation();
    }

    public function write(array $snapshot, array $plan): array
    {
        $this->assertTransaction();
        // The coordinator builds the full plan. Check the write boundary again
        // before the first API call; internal plans cannot expand field ownership.
        if (($plan['fingerprint'] ?? null) !== $snapshot['fingerprint'] || ($plan['parentId'] ?? null) !== $snapshot['parentId']) throw new \LogicException('Resource plan authority mismatch.');
        $this->validateTargets($snapshot, $plan);
        if (($plan['parentProduct'] ?? null) !== ResourceCardMutationPlan::parentProductTransition($snapshot, $plan['mutations'])) throw new \LogicException('Invalid parent SKU transition.');
        return ($this->within)(fn(): array => $this->perform($snapshot, $plan));
    }
    private function perform(array $snapshot, array $plan): array
    {
        $created = []; $products = array_column($snapshot['raw']['products'], null, 'ID');
        foreach ($plan['mutations'] as $mutation) {
            $id = $mutation['id'];
            if ($mutation['create']) {
                $code = 'resource-variant-' . bin2hex(random_bytes(12));
                $values = ['CML2_LINK' => $snapshot['parentId']];
                foreach ($snapshot['raw']['properties'][$mutation['iblock']]['schema'] as $property) {
                    if (in_array($property['CODE'], ['PARAMETRS', 'SOURCE_LINKS', 'SUPPLIERS'], true)) $values[$property['CODE']] = false;
                }
                $id = $this->call('element.add', 0, ['IBLOCK_ID' => $mutation['iblock'], 'ACTIVE' => 'Y', 'CODE' => $code,
                    'PROPERTY_VALUES' => array_replace($values, $mutation['properties'])] + $mutation['fields']);
                if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$id)
                    || isset($snapshot['raw']['elements'][(int)$id]) || in_array((int)$id, array_column($created, 'id'), true)) throw new DocumentConflict('Bitrix вернул некорректный ID нового варианта.');
                $id = (int)$id; $created[$mutation['index']] = ['id' => $id, 'code' => $code];
            } elseif ($mutation['fields']) $this->call('element.update', $id, $mutation['fields']);
            if (!$mutation['create'] && $mutation['properties']) $this->call('properties.set', $id, ['iblock' => $mutation['iblock'], 'values' => $mutation['properties']]);
            if ($mutation['product'] || $mutation['create']) {
                // Add may cause native catalog handlers to create the product row.
                // Read actual existence rather than a cached CCatalogProduct result.
                $exists = isset($products[$id]) || $this->db->rows('SELECT ID FROM b_catalog_product WHERE ID=?', [$id]);
                $fields = $mutation['product'];
                if ($mutation['create']) $fields['TYPE'] = 4;
                // RUB equals the empty-card display default and therefore is not
                // a diff, but native Add requires a currency with purchase price.
                if (!$exists) $fields += ['PURCHASING_CURRENCY' => $plan['expectedRows'][$mutation['index']]['catalog']['purchasingCurrency']];
                $this->call($exists ? 'product.update' : 'product.add', $id, $fields);
            }
            if ($mutation['price']) {
                $price = $mutation['price']; $fields = $price['fields'] ?? [];
                if ($price['action'] === 'add') $fields['PRODUCT_ID'] = $id;
                $this->call('price.' . $price['action'], $price['id'] ?? 0, $fields);
            }
        }
        if ($plan['parentProduct'] !== null) $this->call('product.update', $plan['parentProduct']['id'], ['TYPE' => 3]);
        return $created;
    }
    private function validateTargets(array $snapshot, array $plan): void
    {
        if (!is_array($plan['mutations'] ?? null) || !array_is_list($plan['mutations']) || count($plan['mutations']) > 1001) throw new \LogicException('Invalid resource write targets.');
        $seen = []; $indices = []; $prices = array_column($snapshot['raw']['prices'], null, 'ID');
        $variantCode = BitrixResourceCardSnapshot::CATALOGS[$snapshot['binding']['catalog']][2];
        foreach ($plan['mutations'] as $m) {
            if (!is_array($m) || !is_int($m['id'] ?? null) || !is_int($m['index'] ?? null) || !is_int($m['iblock'] ?? null) || !is_bool($m['create'] ?? null)
                || !is_array($m['fields'] ?? null) || !is_array($m['properties'] ?? null) || !is_array($m['product'] ?? null) || isset($indices[$m['index']])) throw new \LogicException('Invalid resource write target.');
            $indices[$m['index']] = true;
            if ($m['create']) {
                if ($m['id'] !== 0 || $variantCode === null || $m['iblock'] !== (int)$snapshot['raw']['catalogs'][$variantCode]['ID']) throw new \LogicException('Invalid new variant authority.');
            } else {
                if ($m['id'] <= 0 || isset($seen[$m['id']]) || !isset($snapshot['raw']['elements'][$m['id']])
                    || (int)$snapshot['raw']['elements'][$m['id']]['IBLOCK_ID'] !== $m['iblock']) throw new \LogicException('Resource write left its captured membership.');
                $seen[$m['id']] = true;
            }
            if (array_diff(array_keys($m['fields']), ['NAME', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE'])
                || array_diff(array_keys($m['properties']), $snapshot['type'] === 'material' ? ['PARAMETRS', 'SOURCE_LINKS', 'SUPPLIERS'] : ['PARAMETRS', 'SOURCE_LINKS'])
                || array_diff(array_keys($m['product']), ['VAT_ID', 'VAT_INCLUDED', 'PURCHASING_PRICE', 'PURCHASING_CURRENCY', 'WEIGHT', 'LENGTH', 'WIDTH', 'HEIGHT'])) throw new \LogicException('Resource plan expanded field ownership.');
            $price = $m['price'] ?? null;
            if ($price !== null) {
                if (!is_array($price) || !in_array($price['action'] ?? null, ['add', 'update', 'delete'], true)) throw new \LogicException('Invalid price operation.');
                if ($price['action'] !== 'add') {
                    $row = $prices[$price['id'] ?? 0] ?? null;
                    if (!$row || (int)$row['PRODUCT_ID'] !== $m['id'] || (int)$row['CATALOG_GROUP_ID'] !== (int)$snapshot['raw']['groups'][0]['ID']) throw new \LogicException('Price operation left its exact row authority.');
                }
                if ($price['action'] !== 'delete' && (!is_array($price['fields'] ?? null) || array_diff(array_keys($price['fields']), ['CATALOG_GROUP_ID', 'PRICE', 'CURRENCY'])
                    || ($price['fields']['CATALOG_GROUP_ID'] ?? null) !== (int)$snapshot['raw']['groups'][0]['ID'])) throw new \LogicException('Price plan expanded field ownership.');
            }
        }
    }
    private function call(string $action, int $id, array $fields)
    {
        $this->assertTransaction(); $result = ($this->mutate)($action, $id, $fields); $this->assertTransaction();
        if (!$result) {
            $detail = '';
            $application = $GLOBALS['APPLICATION'] ?? null;
            if (is_object($application) && method_exists($application, 'GetException')) {
                $exception = $application->GetException();
                if (is_object($exception) && method_exists($exception, 'GetString')) $detail = trim(strip_tags((string)$exception->GetString()));
            }
            throw new DocumentConflict('Bitrix не подтвердил запись карточки (' . $action . ').' . ($detail !== '' ? ' ' . mb_substr($detail, 0, 2000) : ''));
        }
        return $result;
    }
    private function assertTransaction(): void
    {
        if (!$this->db->inTransaction()) throw new \LogicException('Resource write requires its coordinator transaction.');
        if ($this->db instanceof BitrixConnection) $this->db->assertCatalogWriteTransaction();
    }
}
