<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/BitrixResourceCardSnapshot.php';

/** Pure diff/validation over a server-captured resource card. No I/O or legacy
 * full-card rewrite. IDs and write targets are derived only from that snapshot. */
final class ResourceCardMutationPlan
{
    private const ROW_KEYS = ['catalog', 'detailText', 'id', 'name', 'parameters', 'previewText', 'sourceLinks', 'supplierIds'];
    private const PRODUCT_FIELDS = ['vatId' => 'VAT_ID', 'vatIncluded' => 'VAT_INCLUDED', 'purchasingPrice' => 'PURCHASING_PRICE',
        'purchasingCurrency' => 'PURCHASING_CURRENCY', 'weight' => 'WEIGHT', 'length' => 'LENGTH', 'width' => 'WIDTH', 'height' => 'HEIGHT'];
    private const DEFAULT_CATALOG = ['vatId' => 0, 'vatIncluded' => false, 'purchasingPrice' => null, 'purchasingCurrency' => 'RUB',
        'basePrice' => null, 'baseCurrency' => 'RUB', 'weight' => null, 'length' => null, 'width' => null, 'height' => null];

    public static function build(array $snapshot, array $rows): array
    {
        if (!array_is_list($rows) || !$rows || count($rows) > 1001) throw new \InvalidArgumentException('Карточка должна содержать родителя и его варианты.');
        $before = [$snapshot['data']['parent'], ...$snapshot['data']['variants']];
        $byId = array_column($before, null, 'id'); $seen = []; $mutations = []; $expectedRows = [];
        $variantCode = BitrixResourceCardSnapshot::CATALOGS[$snapshot['binding']['catalog']][2];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) throw new \InvalidArgumentException('Некорректная строка карточки.');
            $keys = array_keys($row); sort($keys);
            if ($keys !== self::ROW_KEYS || !is_int($row['id']) || $row['id'] < 0 || $row['id'] > 999999999) throw new \InvalidArgumentException('Некорректные поля карточки.');
            $id = $row['id']; $create = $id === 0;
            if ($index === 0 && $id !== $snapshot['parentId']) throw new \InvalidArgumentException('Родитель карточки изменён.');
            if ($create && ($variantCode === null || $index === 0)) throw new \InvalidArgumentException('Этот ресурс не поддерживает варианты.');
            if (!$create && (!isset($byId[$id]) || isset($seen[$id]))) throw new \InvalidArgumentException('Повторный или чужой элемент карточки.');
            if (!$create) $seen[$id] = true;
            $old = $create ? self::emptyRow() : $byId[$id];
            $iblock = $create ? (int)$snapshot['raw']['catalogs'][$variantCode]['ID'] : (int)$snapshot['raw']['elements'][$id]['IBLOCK_ID'];
            $name = self::text($row['name'], 1020, true); $preview = self::text($row['previewText'], 100000); $detail = self::text($row['detailText'], 1000000, false, false);
            if ($name === '') throw new \InvalidArgumentException('Название не может быть пустым.');
            $fields = [];
            if ($create || $name !== trim($old['name'])) $fields['NAME'] = $name;
            if ($preview !== $old['previewText']) $fields += ['PREVIEW_TEXT' => $preview, 'PREVIEW_TEXT_TYPE' => 'text'];
            if ($detail !== $old['detailText']) $fields += ['DETAIL_TEXT' => $detail, 'DETAIL_TEXT_TYPE' => 'html'];
            // Existing values can predate today's authoring validation. A rename
            // must not repair/reject unrelated data, including legacy codes or
            // source URLs. Object key order in JSON is not a field edit.
            $parameters = self::same($row['parameters'], $old['parameters']) ? $old['parameters'] : self::parameters($row['parameters']);
            $sources = self::same($row['sourceLinks'], $old['sourceLinks']) ? $old['sourceLinks'] : self::sources($row['sourceLinks']);
            $suppliers = self::suppliers($row['supplierIds']);
            $properties = [];
            if ($parameters !== $old['parameters']) {
                self::property($snapshot, $iblock, 'PARAMETRS', 'S', true);
                $properties['PARAMETRS'] = array_map(static fn(array $p): array => ['VALUE' => $p['code'], 'DESCRIPTION' => implode('|', [$p['value'], $p['title'], $p['description']])], $parameters) ?: false;
            }
            if ($sources !== $old['sourceLinks']) {
                self::property($snapshot, $iblock, 'SOURCE_LINKS', 'S', true);
                $properties['SOURCE_LINKS'] = array_map(static fn(array $p): array => ['VALUE' => $p['url'], 'DESCRIPTION' => implode('|', [$p['title'], $p['description']])], $sources) ?: false;
            }
            if ($suppliers !== $old['supplierIds']) {
                if ($snapshot['type'] !== 'material') throw new \InvalidArgumentException('Поставщики доступны только материалам.');
                $schema = self::property($snapshot, $iblock, 'SUPPLIERS', 'E', false);
                if ((int)$schema['LINK_IBLOCK_ID'] !== (int)$snapshot['raw']['catalogs']['CALC_SUPPLIERS']['ID']) throw new DocumentConflict('Схема поставщиков изменилась.');
                $allowed = array_map('intval', array_column($snapshot['raw']['suppliers'], 'ID'));
                if (array_diff($suppliers, $allowed)) throw new \InvalidArgumentException('Выбран отсутствующий поставщик.');
                $properties['SUPPLIERS'] = $suppliers ?: false;
            }
            $catalog = self::catalog($row['catalog']); $oldCatalog = self::catalog($old['catalog']); $product = [];
            foreach (self::PRODUCT_FIELDS as $key => $column) if ($catalog[$key] !== $oldCatalog[$key] || ($create && array_key_exists($key, $row['catalog']))) {
                if ($key === 'vatId' && $catalog[$key] !== 0 && !array_filter($snapshot['raw']['vat'], static fn(array $v): bool => (int)$v['ID'] === $catalog[$key] && $v['ACTIVE'] === 'Y')) throw new \InvalidArgumentException('Выбрана недоступная ставка НДС.');
                if ($key === 'purchasingCurrency') self::currency($snapshot, $catalog[$key]);
                $product[$column] = $key === 'vatIncluded' ? ($catalog[$key] ? 'Y' : 'N') : $catalog[$key];
            }
            $price = null;
            if ($catalog['basePrice'] !== $oldCatalog['basePrice'] || $catalog['baseCurrency'] !== $oldCatalog['baseCurrency']) {
                self::currency($snapshot, $catalog['baseCurrency']);
                $groupId = (int)$snapshot['raw']['groups'][0]['ID'];
                $existing = $create ? [] : array_values(array_filter($snapshot['raw']['prices'], static fn(array $p): bool => (int)$p['PRODUCT_ID'] === $id && (int)$p['CATALOG_GROUP_ID'] === $groupId));
                if (count($existing) > 1 || ($existing && ($existing[0]['QUANTITY_FROM'] !== null || $existing[0]['QUANTITY_TO'] !== null))) throw new DocumentConflict('У ресурса интервальные цены. Измените их в штатной карточке торгового каталога; они не будут перезаписаны одной ценой.');
                if ($catalog['basePrice'] === null) {
                    if ($existing) { $price = ['action' => 'delete', 'id' => (int)$existing[0]['ID']]; $catalog['baseCurrency'] = 'RUB'; }
                    elseif ($catalog['baseCurrency'] !== 'RUB') throw new \InvalidArgumentException('Укажите цену для выбранной валюты.');
                } else $price = ['action' => $existing ? 'update' : 'add', 'id' => $existing ? (int)$existing[0]['ID'] : null,
                    'fields' => ['CATALOG_GROUP_ID' => $groupId, 'PRICE' => $catalog['basePrice'], 'CURRENCY' => $catalog['baseCurrency']]];
            }
            $requestedCatalogFields = array_keys($row['catalog']);
            if ($create || $fields || $properties || $product || $price) $mutations[] = compact('id', 'index', 'create', 'iblock', 'fields', 'properties', 'product', 'price', 'requestedCatalogFields');
            $expectedRows[] = ['id' => $id, 'name' => $name, 'previewText' => $preview, 'detailText' => $detail, 'parameters' => $parameters,
                'sourceLinks' => $sources, 'supplierIds' => $suppliers, 'catalog' => $catalog];
        }
        if (count($seen) !== count($byId)) throw new \InvalidArgumentException('Карточка не содержит всех исходных вариантов. Удаление через неполный список запрещено.');
        return ['fingerprint' => $snapshot['fingerprint'], 'parentId' => $snapshot['parentId'], 'mutations' => $mutations, 'expectedRows' => $expectedRows,
            'parentProduct' => self::parentProductTransition($snapshot, $mutations)];
    }

    /** Native SKU identity is derived from adding a variant, never client-owned.
     * Keep independently authored parent prices and stock outside this transition. */
    public static function parentProductTransition(array $snapshot, array $mutations): ?array
    {
        if (!array_filter($mutations, static fn(array $m): bool => $m['create'])) return null;
        $products = array_column($snapshot['raw']['products'], null, 'ID');
        $type = (int)($products[$snapshot['parentId']]['TYPE'] ?? 0);
        if (!in_array($type, [1, 3, 6], true)) throw new DocumentConflict('Родитель не является товаром с вариантами. Проверьте его карточку торгового каталога.');
        return $type === 3 ? null : ['id' => $snapshot['parentId'], 'fromType' => $type, 'type' => 3];
    }

    public static function comparable(array $row): array
    {
        return ['id' => $row['id'], 'name' => trim($row['name']), 'previewText' => $row['previewText'], 'detailText' => $row['detailText'],
            'parameters' => $row['parameters'], 'sourceLinks' => $row['sourceLinks'],
            'supplierIds' => self::suppliers($row['supplierIds']), 'catalog' => self::catalog($row['catalog'])];
    }
    private static function property(array $s, int $iblock, string $code, string $type, bool $description): array
    {
        $rows = array_values(array_filter($s['raw']['properties'][$iblock]['schema'], static fn(array $p): bool => $p['CODE'] === $code));
        if (count($rows) !== 1 || $rows[0]['ACTIVE'] !== 'Y' || $rows[0]['PROPERTY_TYPE'] !== $type || $rows[0]['MULTIPLE'] !== 'Y'
            || (string)($rows[0]['USER_TYPE'] ?? '') !== '' || ($description && $rows[0]['WITH_DESCRIPTION'] !== 'Y')) throw new DocumentConflict('Свойство ' . $code . ' недоступно для записи. Требуется согласованная схема модуля.');
        return $rows[0];
    }
    private static function parameters($values): array
    {
        self::list($values); $result = []; $seen = [];
        foreach ($values as $row) {
            self::keys($row, ['code', 'description', 'title', 'value']);
            $code = self::text($row['code'], 255); $value = self::text($row['value'], 10000); $title = self::text($row['title'], 10000); $description = self::text($row['description'], 10000);
            if ($code === '' && $value === '' && $title === '' && $description === '') continue;
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $code) || isset($seen[$code])) throw new \InvalidArgumentException('Некорректный или повторный код параметра.');
            if (str_contains($value . $title . $description, '|')) throw new \InvalidArgumentException('Символ | зарезервирован в параметрах.');
            $seen[$code] = true; $result[] = compact('code', 'value', 'title', 'description');
        }
        return $result;
    }
    private static function sources($values): array
    {
        self::list($values); $result = [];
        foreach ($values as $row) {
            self::keys($row, ['description', 'title', 'url']);
            $url = self::text($row['url'], 10000); $title = self::text($row['title'], 10000); $description = self::text($row['description'], 10000);
            if ($url === '' && $title === '' && $description === '') continue;
            if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url) || str_contains($title . $description, '|')) throw new \InvalidArgumentException('Источник должен содержать HTTP(S)-ссылку и описание без |.');
            $result[] = compact('url', 'title', 'description');
        }
        return $result;
    }
    private static function suppliers($values): array
    {
        self::list($values); $seen = [];
        foreach ($values as $id) {
            if (!is_int($id) || $id < 1 || $id > 999999999 || isset($seen[$id])) throw new \InvalidArgumentException('Некорректный или повторный поставщик.');
            $seen[$id] = true;
        }
        return $values;
    }
    private static function catalog($row): array
    {
        if (!is_array($row) || array_diff(array_keys($row), array_keys(self::DEFAULT_CATALOG))) throw new \InvalidArgumentException('Неизвестные поля торгового каталога.');
        $row += self::DEFAULT_CATALOG;
        if ($row['vatId'] === null) $row['vatId'] = 0;
        if (!is_int($row['vatId']) || $row['vatId'] < 0 || $row['vatId'] > 999999999 || !is_bool($row['vatIncluded'])) throw new \InvalidArgumentException('Некорректные параметры НДС.');
        foreach (['purchasingCurrency', 'baseCurrency'] as $key) {
            $row[$key] = self::text($row[$key], 3, true);
            if (!preg_match('/^[A-Z]{3}$/D', $row[$key])) throw new \InvalidArgumentException('Некорректный код валюты.');
        }
        foreach (['purchasingPrice', 'basePrice', 'weight', 'length', 'width', 'height'] as $key) $row[$key] = self::number($row[$key]);
        // Stable key order independent of JSON property order.
        return array_replace(self::DEFAULT_CATALOG, $row);
    }
    private static function number($value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) return null;
        if (!is_int($value) && !is_float($value) && !is_string($value)) throw new \InvalidArgumentException('Параметр торгового каталога должен быть числом.');
        $text = trim(str_replace(',', '.', (string)$value));
        if (!is_numeric($text) || !is_finite((float)$text) || (float)$text < 0 || (float)$text > 999999999999.9999) throw new \InvalidArgumentException('Параметр торгового каталога должен быть конечным неотрицательным числом.');
        return rtrim(rtrim(sprintf('%.12F', (float)$text), '0'), '.') ?: '0';
    }
    private static function currency(array $snapshot, string $currency): void
    { if (!in_array($currency, array_column($snapshot['raw']['currencies'], 'CURRENCY'), true)) throw new \InvalidArgumentException('Валюта или режим цены не настроены.'); }
    private static function emptyRow(): array
    { return ['id' => 0, 'name' => '', 'previewText' => '', 'detailText' => '', 'parameters' => [], 'sourceLinks' => [], 'supplierIds' => [], 'catalog' => self::DEFAULT_CATALOG]; }
    private static function same($left, $right): bool
    {
        $canonical = static function($value) use (&$canonical) {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            foreach ($value as $key => $item) $value[$key] = $canonical($item);
            return $value;
        };
        return $canonical($left) === $canonical($right);
    }
    private static function text($value, int $limit, bool $required = false, bool $trim = true): string
    {
        if (!is_string($value) || strlen($value) > $limit || str_contains($value, "\0") || !preg_match('//u', $value)) throw new \InvalidArgumentException('Некорректный текст карточки.');
        $value = $trim ? trim($value) : $value;
        if ($required && $value === '') throw new \InvalidArgumentException('Обязательное поле карточки пусто.');
        return $value;
    }
    private static function list($value): void
    { if (!is_array($value) || !array_is_list($value) || count($value) > 1000) throw new \InvalidArgumentException('Некорректный список карточки.'); }
    private static function keys($row, array $keys): void
    { if (!is_array($row)) throw new \InvalidArgumentException('Некорректная запись карточки.'); $actual = array_keys($row); sort($actual); if ($actual !== $keys) throw new \InvalidArgumentException('Неизвестные поля записи карточки.'); }
}
