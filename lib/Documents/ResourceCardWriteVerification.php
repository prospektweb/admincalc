<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/ResourceCardMutationPlan.php';

/** Verify user-visible values AND every captured unowned before-image. */
final class ResourceCardWriteVerification
{
    public static function assert(array $before, array $after, array $plan, array $created): void
    {
        foreach (['binding', 'type', 'parentId'] as $key) self::same($before[$key], $after[$key], $key);
        foreach (['options', 'catalogs', 'linkSchema', 'pair', 'groups', 'vat', 'currencies', 'suppliers', 'supplierKeys'] as $key) self::same($before['raw'][$key], $after['raw'][$key], $key);
        $mutations = []; $new = []; $expected = []; $actual = [];
        foreach ($plan['mutations'] as $mutation) {
            $id = $mutation['id'];
            if ($mutation['create']) {
                $entry = $created[$mutation['index']] ?? null;
                if (!is_array($entry) || !is_int($entry['id'] ?? null) || $entry['id'] < 1 || !is_string($entry['code'] ?? null) || $entry['code'] === '' || isset($before['raw']['elements'][$entry['id']]) || isset($new[$entry['id']])) throw new DocumentConflict('Не подтверждён созданный вариант.');
                $id = $entry['id']; $new[$id] = $entry; unset($created[$mutation['index']]);
            $row = $after['raw']['elements'][$id] ?? [];
                if ((int)($row['IBLOCK_ID'] ?? 0) !== $mutation['iblock'] || ($row['CODE'] ?? null) !== $entry['code'] || ($row['ACTIVE'] ?? null) !== 'Y' || (int)($row['IBLOCK_SECTION_ID'] ?? 0) !== 0) throw new DocumentConflict('Созданный вариант изменил каталог или идентичность.');
            }
            if (isset($mutations[$id])) throw new DocumentConflict('Повторная запись карточки.');
            $mutations[$id] = $mutation;
        }
        if ($created) throw new DocumentConflict('Bitrix создал лишние варианты.');
        foreach ([$after['data']['parent'], ...$after['data']['variants']] as $row) $actual[$row['id']] = ResourceCardMutationPlan::comparable($row);
        foreach ($plan['expectedRows'] as $index => $row) {
            if ($row['id'] === 0) {
                $matching = array_filter($mutations, static fn(array $m): bool => $m['create'] && $m['index'] === $index);
                if (count($matching) !== 1) throw new DocumentConflict('Не подтверждён порядок новых вариантов.');
                $row['id'] = (int)array_key_first($matching); $mutation = array_values($matching)[0];
                // A freshly created SKU has native defaults. The UI's empty
                // catalog object does not request overwriting those defaults.
                foreach (array_keys($row['catalog']) as $key) if (!in_array($key, $mutation['requestedCatalogFields'], true)) $row['catalog'][$key] = $actual[$row['id']]['catalog'][$key] ?? null;
            }
            $expected[$row['id']] = $row;
        }
        ksort($expected, SORT_NUMERIC); ksort($actual, SORT_NUMERIC);
        self::same($expected, $actual, 'значения карточки');
        $wantedIds = array_merge(array_keys($before['raw']['elements']), array_keys($new)); sort($wantedIds, SORT_NUMERIC);
        $actualIds = array_keys($after['raw']['elements']); sort($actualIds, SORT_NUMERIC);
        self::same($wantedIds, $actualIds, 'состав вариантов');
        foreach ($before['raw']['elements'] as $id => $row) {
            $mutation = $mutations[$id] ?? null; $ignored = array_keys($mutation['fields'] ?? []);
            if ($mutation && ($mutation['fields'] || $mutation['properties'])) $ignored = array_merge($ignored, ['TIMESTAMP_X', 'MODIFIED_BY', 'SEARCHABLE_CONTENT']);
            self::same(self::except($row, $ignored), self::except($after['raw']['elements'][$id], $ignored), 'посторонние поля ресурса ' . $id);
            self::same($before['raw']['sections'][$id], $after['raw']['sections'][$id], 'раздел ресурса');
        }
        foreach ($before['raw']['properties'] as $iblock => $properties) {
            $next = $after['raw']['properties'][$iblock] ?? null;
            if (!is_array($next)) throw new DocumentConflict('Исчезла схема свойств.');
            self::same($properties['schema'], $next['schema'], 'схема свойств');
            $owned = [];
            foreach ($mutations as $id => $mutation) if ($mutation['iblock'] === $iblock) {
                foreach ($properties['schema'] as $p) if (array_key_exists($p['CODE'], $mutation['properties'])) $owned[$id][(int)$p['ID']] = true;
            }
            $single = static fn(array $rows): array => array_values(array_filter($rows, static fn(array $r): bool => !isset($new[(int)$r['IBLOCK_ELEMENT_ID']])));
            $multiple = static fn(array $rows): array => array_values(array_filter($rows, static fn(array $r): bool => !isset($new[(int)$r['IBLOCK_ELEMENT_ID']]) && !isset($owned[(int)$r['IBLOCK_ELEMENT_ID']][(int)$r['IBLOCK_PROPERTY_ID']])));
            self::same($single($properties['single']), $single($next['single']), 'посторонние одиночные свойства');
            self::same($multiple($properties['multiple']), $multiple($next['multiple']), 'посторонние множественные свойства');
        }
        $productsBefore = array_column($before['raw']['products'], null, 'ID'); $productsAfter = array_column($after['raw']['products'], null, 'ID');
        $transition = ResourceCardMutationPlan::parentProductTransition($before, $plan['mutations']);
        foreach ($new as $id => $_) if ((int)($productsAfter[$id]['TYPE'] ?? 0) !== 4) throw new DocumentConflict('Тип нового варианта не подтверждён.');
        foreach ($productsBefore as $id => $row) {
            if (!isset($productsAfter[$id])) throw new DocumentConflict('Исчез товар каталога.');
            $mutation = $mutations[$id] ?? null; $ignored = array_keys($mutation['product'] ?? []);
            if ($mutation && ($mutation['product'] || $mutation['price'])) $ignored[] = 'TIMESTAMP_X';
            if ($transition !== null && $id === $transition['id']) {
                if ((int)$productsAfter[$id]['TYPE'] !== 3) throw new DocumentConflict('Тип родителя с вариантами не подтверждён.');
                $ignored = array_merge($ignored, ['TYPE', 'TIMESTAMP_X']);
            }
            self::same(self::except($row, $ignored), self::except($productsAfter[$id], $ignored), 'посторонние поля товара ' . $id);
        }
        foreach (array_diff_key($productsAfter, $productsBefore) as $id => $row) if (!isset($new[$id]) && empty($mutations[$id]['product'])) throw new DocumentConflict('Создан незапрошенный товар каталога.');
        $pricesBefore = array_column($before['raw']['prices'], null, 'ID'); $pricesAfter = array_column($after['raw']['prices'], null, 'ID'); $adds = [];
        foreach ($mutations as $id => $mutation) if (($mutation['price']['action'] ?? null) === 'add') $adds[$id] = $mutation['price']['fields'];
        foreach ($pricesBefore as $priceId => $row) {
            $price = $mutations[(int)$row['PRODUCT_ID']]['price'] ?? null;
            $owned = $price !== null && ($price['id'] ?? null) === (int)$priceId;
            if ($owned && $price['action'] === 'delete') { if (isset($pricesAfter[$priceId])) throw new DocumentConflict('Удаление цены не подтверждено.'); continue; }
            if (!isset($pricesAfter[$priceId])) throw new DocumentConflict('Посторонняя цена удалена.');
            $ignored = $owned ? ['PRICE', 'CURRENCY', 'PRICE_SCALE', 'TIMESTAMP_X'] : [];
            self::same(self::except($row, $ignored), self::except($pricesAfter[$priceId], $ignored), 'метаданные цены');
        }
        foreach (array_diff_key($pricesAfter, $pricesBefore) as $row) {
            $id = (int)$row['PRODUCT_ID']; $expectedPrice = $adds[$id] ?? null;
            if (!$expectedPrice || (int)$row['CATALOG_GROUP_ID'] !== $expectedPrice['CATALOG_GROUP_ID'] || $row['QUANTITY_FROM'] !== null || $row['QUANTITY_TO'] !== null) throw new DocumentConflict('Создана лишняя или интервальная цена.');
            unset($adds[$id]);
        }
        if ($adds) throw new DocumentConflict('Новая цена не подтверждена.');
    }
    private static function except(array $row, array $keys): array { return array_diff_key($row, array_fill_keys($keys, true)); }
    private static function same($a, $b, string $label): void
    {
        if ($a === $b) return;
        $paths = [];
        $diff = static function($left, $right, string $path) use (&$diff, &$paths): void {
            if ($left === $right || count($paths) >= 4) return;
            if (is_array($left) && is_array($right)) {
                foreach (array_unique(array_merge(array_keys($left), array_keys($right))) as $key) $diff($left[$key] ?? null, $right[$key] ?? null, $path . '/' . $key);
            } else $paths[] = $path;
        };
        $diff($a, $b, '');
        throw new DocumentConflict('Контрольное чтение не совпало: ' . $label . ($paths ? ' [' . implode(', ', $paths) . ']' : '') . '.');
    }
}
