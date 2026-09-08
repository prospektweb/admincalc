<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/BitrixResourceCardProperties.php';

/** One exact, cache-free before/after image for the former resource card.
 * The coordinator owns the transaction. Catalog IDs and provider come from
 * server-owned module options, not a client-supplied iblock or legacy graph. */
final class BitrixResourceCardSnapshot
{
    public const CATALOGS = [
        'CALC_MATERIALS' => ['material', 'CALC_MATERIALS', 'CALC_MATERIALS_VARIANTS'],
        'CALC_MATERIALS_VARIANTS' => ['material', 'CALC_MATERIALS', 'CALC_MATERIALS_VARIANTS'],
        'CALC_OPERATIONS' => ['operation', 'CALC_OPERATIONS', 'CALC_OPERATIONS_VARIANTS'],
        'CALC_OPERATIONS_VARIANTS' => ['operation', 'CALC_OPERATIONS', 'CALC_OPERATIONS_VARIANTS'],
        'CALC_EQUIPMENT' => ['equipment', 'CALC_EQUIPMENT', null],
    ];
    private bool $lock = false;
    private array $tables = [];
    public function __construct(private SqlConnection $db, private string $provider) {}

    public function capture(array $binding, bool $lock = false): array
    {
        if (!$this->db->inTransaction()) throw new \LogicException('Resource card requires the coordinator snapshot.');
        if ($lock && $this->db instanceof BitrixConnection) $this->db->assertCatalogWriteTransaction();
        $keys = array_keys($binding); sort($keys);
        if ($keys !== ['catalog', 'key', 'provider'] || !is_string($binding['catalog']) || !isset(self::CATALOGS[$binding['catalog']])
            || !is_string($binding['key']) || !preg_match('/^[1-9][0-9]{0,8}$/D', $binding['key'])
            || $binding['provider'] !== $this->provider || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $this->provider)) throw new \InvalidArgumentException('Invalid resource card binding.');
        $this->lock = $lock; $this->tables = [];
        [$type, $parentCode, $variantCode] = self::CATALOGS[$binding['catalog']];
        $provider = $this->option('document_resource_provider');
        if ($provider['VALUE'] !== $this->provider) throw new DocumentConflict('Provider справочников изменился.');
        $catalogs = []; $options = [$provider];
        foreach (array_filter([$parentCode, $variantCode, $type === 'material' ? 'CALC_SUPPLIERS' : null]) as $code) {
            $option = $this->option('iblock_' . strtolower($code)); $options[] = $option;
            $id = self::id($option['VALUE']);
            $rows = $this->rows('b_iblock', 'ID,CODE,IBLOCK_TYPE_ID,VERSION,ACTIVE', 'ID=? OR CODE=?', [$id, $code], 'ID', 2);
            if (count($rows) !== 1 || self::id($rows[0]['ID']) !== $id || $rows[0]['CODE'] !== $code || !in_array((string)$rows[0]['VERSION'], ['1', '2'], true)) throw new DocumentConflict('Справочник отсутствует или сменил идентификатор: ' . $code);
            $catalogs[$code] = $rows[0];
        }
        if (count(array_unique(array_column($catalogs, 'ID'))) !== count($catalogs)) throw new DocumentConflict('Справочники имеют повторные идентификаторы.');
        $selectedId = (int)$binding['key']; $selectedIblock = self::id($catalogs[$binding['catalog']]['ID']);
        $this->element($selectedIblock, $selectedId);
        $parentId = $selectedId; $variantIds = []; $linkSchema = null; $pair = [];
        if ($variantCode !== null) {
            $variantIblock = self::id($catalogs[$variantCode]['ID']); $parentIblock = self::id($catalogs[$parentCode]['ID']);
            $links = $this->rows('b_iblock_property', '*', 'IBLOCK_ID=? AND CODE=?', [$variantIblock, 'CML2_LINK'], 'ID', 2);
            if (count($links) !== 1) throw new DocumentConflict('Связь вариантов неоднозначна.');
            $linkSchema = $links[0];
            if ($linkSchema['CODE'] !== 'CML2_LINK' || $linkSchema['ACTIVE'] !== 'Y' || $linkSchema['PROPERTY_TYPE'] !== 'E'
                || $linkSchema['MULTIPLE'] !== 'N' || !in_array((string)($linkSchema['USER_TYPE'] ?? ''), ['', 'SKU'], true)
                || self::id($linkSchema['LINK_IBLOCK_ID']) !== $parentIblock) throw new DocumentConflict('Схема связи вариантов изменилась.');
            $pair = $this->rows('b_catalog_iblock', '*', 'IBLOCK_ID=?', [$variantIblock], 'IBLOCK_ID', 1);
            if (count($pair) !== 1 || self::id($pair[0]['PRODUCT_IBLOCK_ID']) !== $parentIblock || self::id($pair[0]['SKU_PROPERTY_ID']) !== self::id($linkSchema['ID'])) throw new DocumentConflict('Связь торгового каталога вариантов изменилась.');
            if ($binding['catalog'] === $variantCode) {
                $link = (new BitrixResourceCardProperties($this->db))->read($variantIblock, [$selectedId], self::linkSpec(), $lock);
                $values = $link['values'][$selectedId]['CML2_LINK'];
                if (count($values) !== 1) throw new DocumentConflict('Родитель варианта отсутствует.');
                $parentId = self::id($values[0]['value']);
            }
            if ((int)$catalogs[$variantCode]['VERSION'] === 2) {
                $members = $this->rows('b_iblock_element_prop_s' . $variantIblock, 'IBLOCK_ELEMENT_ID', 'PROPERTY_' . self::id($linkSchema['ID']) . '=?', [(string)$parentId], 'IBLOCK_ELEMENT_ID', 1000);
            } else {
                $members = $this->rows('b_iblock_element_property', 'IBLOCK_ELEMENT_ID', 'IBLOCK_PROPERTY_ID=? AND VALUE=?', [self::id($linkSchema['ID']), (string)$parentId], 'IBLOCK_ELEMENT_ID,ID', 1000);
            }
            foreach ($members as $member) $variantIds[] = self::id($member['IBLOCK_ELEMENT_ID']);
            if (count($variantIds) !== count(array_unique($variantIds)) || ($binding['catalog'] === $variantCode && !in_array($selectedId, $variantIds, true))) throw new DocumentConflict('Состав вариантов неоднозначен.');
        }
        $parent = $this->element(self::id($catalogs[$parentCode]['ID']), $parentId);
        $elements = [$parentId => $parent]; $properties = []; $owned = []; $sections = [];
        foreach ([$parentCode => [$parentId]] + ($variantCode !== null ? [$variantCode => $variantIds] : []) as $code => $ids) {
            $iblock = self::id($catalogs[$code]['ID']);
            if (!$ids) { $properties[$iblock] = $this->properties($iblock, [], (int)$catalogs[$code]['VERSION']); continue; }
            if ($code !== $parentCode) {
                $rows = $this->rows('b_iblock_element', '*', 'IBLOCK_ID=? AND ID IN (' . self::marks($ids) . ')', array_merge([$iblock], $ids), 'SORT,ID', 1000);
                if (count($rows) !== count($ids)) throw new DocumentConflict('Вариант отсутствует или находится в другом справочнике.');
                $variantIds = [];
                foreach ($rows as $row) { $id = self::id($row['ID']); $variantIds[] = $id; $elements[$id] = $row; }
            }
            $properties[$iblock] = $this->properties($iblock, $ids, (int)$catalogs[$code]['VERSION']);
            $schema = array_column($properties[$iblock]['schema'], null, 'CODE');
            $specs = ['PARAMETRS' => ['type' => 'S', 'multiple' => true, 'description' => true]];
            // Older operation directories did not install SOURCE_LINKS. Absence
            // is explicit authority, never silently accepted for a source write.
            if (isset($schema['SOURCE_LINKS'])) $specs['SOURCE_LINKS'] = ['type' => 'S', 'multiple' => true, 'description' => true];
            if ($type === 'material') $specs['SUPPLIERS'] = ['type' => 'E', 'multiple' => true, 'description' => false];
            if ($code === $variantCode) $specs += self::linkSpec();
            $read = (new BitrixResourceCardProperties($this->db))->read($iblock, $ids, $specs, $lock);
            foreach ($read['values'] as $id => $values) {
                if ($code === $variantCode && ($values['CML2_LINK'] ?? null) !== [['value' => (string)$parentId, 'description' => '']]) throw new DocumentConflict('Вариант сменил родителя.');
                $owned[$id] = $values;
            }
            foreach ($ids as $id) $sections[$id] = $this->sections($catalogs[$code], (int)($elements[$id]['IBLOCK_SECTION_ID'] ?? 0));
        }
        $ids = array_keys($elements);
        $products = $this->rows('b_catalog_product', '*', 'ID IN (' . self::marks($ids) . ')', $ids, 'ID', 1001);
        $prices = $this->rows('b_catalog_price', '*', 'PRODUCT_ID IN (' . self::marks($ids) . ')', $ids, 'PRODUCT_ID,CATALOG_GROUP_ID,ID', 20000);
        $groups = $this->rows('b_catalog_group', '*', "BASE='Y'", [], 'ID', 2);
        if (count($groups) !== 1) throw new DocumentConflict('Базовый тип цены неоднозначен.');
        $vat = $this->rows('b_catalog_vat', '*', '1=1', [], 'ID', 1000);
        $currencies = $this->rows('b_catalog_currency', '*', '1=1', [], 'CURRENCY', 1000);
        $suppliers = []; $supplierKeys = [];
        if ($type === 'material') {
            $supplierIblock = self::id($catalogs['CALC_SUPPLIERS']['ID']);
            $suppliers = $this->rows('b_iblock_element', 'ID,IBLOCK_ID,NAME,CODE,ACTIVE,SORT', 'IBLOCK_ID=?', [$supplierIblock], 'SORT,NAME,ID', 20000);
            foreach (array_chunk(array_map(static fn(array $row): int => self::id($row['ID']), $suppliers), 250) as $batch) {
                $read = (new BitrixResourceCardProperties($this->db))->read($supplierIblock, $batch, ['ENTITY_KEY' => ['type' => 'S', 'multiple' => false, 'description' => false]], $lock);
                foreach ($read['values'] as $id => $values) $supplierKeys[$id] = $values['ENTITY_KEY'][0]['value'] ?? '';
            }
        }
        $raw = compact('options', 'catalogs', 'linkSchema', 'pair', 'elements', 'properties', 'products', 'prices', 'groups', 'vat', 'currencies', 'suppliers', 'supplierKeys', 'sections');
        $this->assertEngines();
        $productMap = array_column($products, null, 'ID');
        $view = function (int $id) use ($elements, $owned, $productMap, $prices, $groups, $catalogs, $sections): array {
            $row = $elements[$id]; $product = $productMap[$id] ?? [];
            $base = array_values(array_filter($prices, static fn(array $price): bool => (int)$price['PRODUCT_ID'] === $id && (int)$price['CATALOG_GROUP_ID'] === (int)$groups[0]['ID']));
            $parameters = []; $sourceLinks = [];
            foreach ($owned[$id]['PARAMETRS'] as $p) {
                $parts = explode('|', $p['description'], 3);
                $parameters[] = ['code' => trim($p['value']), 'value' => trim($parts[0] ?? ''), 'title' => trim($parts[1] ?? ''), 'description' => trim($parts[2] ?? '')];
            }
            foreach ($owned[$id]['SOURCE_LINKS'] ?? [] as $p) {
                $parts = explode('|', $p['description'], 2);
                $sourceLinks[] = ['url' => trim($p['value']), 'title' => trim($parts[0] ?? ''), 'description' => trim($parts[1] ?? '')];
            }
            $catalog = ['vatId' => (int)($product['VAT_ID'] ?? 0), 'vatIncluded' => ($product['VAT_INCLUDED'] ?? 'N') === 'Y',
                'purchasingPrice' => $product['PURCHASING_PRICE'] ?? null, 'purchasingCurrency' => $product['PURCHASING_CURRENCY'] ?? 'RUB',
                'basePrice' => $base[0]['PRICE'] ?? null, 'baseCurrency' => $base[0]['CURRENCY'] ?? 'RUB'];
            foreach (['weight', 'length', 'width', 'height'] as $field) $catalog[$field] = $product[strtoupper($field)] ?? null;
            $iblock = array_values(array_filter($catalogs, static fn(array $c): bool => (int)$c['ID'] === (int)$row['IBLOCK_ID']))[0];
            return ['id' => $id, 'name' => (string)$row['NAME'], 'code' => (string)($row['CODE'] ?? ''),
                'adminUrl' => self::adminUrl($iblock, $id), 'sectionPath' => $sections[$id],
                'previewText' => trim(strip_tags((string)($row['PREVIEW_TEXT'] ?? ''))), 'detailText' => (string)($row['DETAIL_TEXT'] ?? ''),
                'parameters' => $parameters, 'sourceLinks' => $sourceLinks, 'catalog' => $catalog,
                'supplierIds' => array_values(array_unique(array_map(static fn(array $p): int => (int)$p['value'], $owned[$id]['SUPPLIERS'] ?? [])))];
        };
        $data = ['parent' => $view($parentId), 'variants' => array_map($view, $variantIds),
            'catalogOptions' => ['vatRates' => array_values(array_map(static fn(array $v): array => ['id' => (int)$v['ID'], 'name' => (string)$v['NAME'], 'value' => $v['RATE'] === null ? null : (float)$v['RATE']], array_filter($vat, static fn(array $v): bool => $v['ACTIVE'] === 'Y')))]];
        if ($type === 'material') $data['catalogOptions']['suppliers'] = array_map(static fn(array $s): array => ['id' => (int)$s['ID'], 'name' => (string)$s['NAME'], 'code' => (string)$s['CODE'], 'entityKey' => $supplierKeys[$s['ID']] ?? '', 'active' => $s['ACTIVE'] === 'Y'], $suppliers);
        $json = json_encode([$binding, $raw], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 12000000) throw new DocumentConflict('Карточка слишком велика; данные не усечены.');
        return ['binding' => $binding, 'type' => $type, 'parentId' => $parentId, 'variantIds' => $variantIds, 'raw' => $raw, 'data' => $data,
            'fingerprint' => hash('sha256', $json)];
    }

    private function option(string $name): array
    {
        $rows = $this->rows('b_option', 'MODULE_ID,NAME,VALUE,SITE_ID', 'LOWER(MODULE_ID)=? AND LOWER(NAME)=?', ['prospektweb.calc', $name], 'MODULE_ID,NAME,SITE_ID', 4);
        if (count($rows) !== 1 || $rows[0]['MODULE_ID'] !== 'prospektweb.calc' || strtolower($rows[0]['NAME']) !== $name || $rows[0]['SITE_ID'] !== null) throw new DocumentConflict('Настройка справочника неоднозначна: ' . $name);
        return $rows[0];
    }
    private function element(int $iblock, int $id): array
    {
        $rows = $this->rows('b_iblock_element', '*', 'IBLOCK_ID=? AND ID=?', [$iblock, $id], 'ID', 1);
        if (count($rows) !== 1 || self::id($rows[0]['ID']) !== $id || self::id($rows[0]['IBLOCK_ID']) !== $iblock) throw new DocumentConflict('Ресурс не найден в выбранном справочнике.');
        return $rows[0];
    }
    private function properties(int $iblock, array $ids, int $version): array
    {
        $schema = $this->rows('b_iblock_property', '*', 'IBLOCK_ID=?', [$iblock], 'ID', 1000);
        $single = []; $multiple = [];
        $where = $ids ? 'IBLOCK_ELEMENT_ID IN (' . self::marks($ids) . ')' : '1=0';
        if ($version === 2) {
            $columns = ['IBLOCK_ELEMENT_ID'];
            foreach ($schema as $p) if ($p['MULTIPLE'] === 'N') {
                $columns[] = 'PROPERTY_' . self::id($p['ID']);
                if ($p['WITH_DESCRIPTION'] === 'Y') $columns[] = 'DESCRIPTION_' . self::id($p['ID']);
            }
            $single = $this->rows('b_iblock_element_prop_s' . $iblock, implode(',', $columns), $where, $ids, 'IBLOCK_ELEMENT_ID', 1000);
            if (count($single) !== count($ids)) throw new DocumentConflict('Строка свойств ресурса отсутствует.');
        }
        $multiple = $this->rows($version === 2 ? 'b_iblock_element_prop_m' . $iblock : 'b_iblock_element_property', '*', $where, $ids, 'IBLOCK_ELEMENT_ID,IBLOCK_PROPERTY_ID,ID', 20000);
        return compact('schema', 'single', 'multiple');
    }
    private function sections(array $iblock, int $section): array
    {
        $path = []; $seen = [];
        while ($section > 0) {
            if (isset($seen[$section]) || count($path) >= 100) throw new DocumentConflict('Цикл разделов справочника.');
            $seen[$section] = true;
            $rows = $this->rows('b_iblock_section', 'ID,IBLOCK_ID,IBLOCK_SECTION_ID,NAME', 'IBLOCK_ID=? AND ID=?', [(int)$iblock['ID'], $section], 'ID', 1);
            if (count($rows) !== 1) throw new DocumentConflict('Раздел ресурса отсутствует.');
            array_unshift($path, ['id' => $section, 'name' => (string)$rows[0]['NAME'], 'adminUrl' => self::adminUrl($iblock, $section, true)]);
            $section = (int)($rows[0]['IBLOCK_SECTION_ID'] ?? 0);
        }
        return $path;
    }
    private function rows(string $table, string $columns, string $where, array $params, string $order, int $limit): array
    {
        $rows = $this->db->rows('SELECT ' . $columns . ' FROM ' . $table . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . ($limit + 1) . ($this->lock && $this->db->dialect() === 'mysql' ? ' FOR UPDATE' : ''), $params);
        foreach ($rows as &$row) foreach ($row as &$value) {
            // Bitrix materializes SQL dates as fresh Date/DateTime instances.
            // Compare actual database values, not PHP object identity or {} JSON.
            if ($value instanceof \DateTimeInterface || $value instanceof \Bitrix\Main\Type\Date) $value = $value->format('Y-m-d H:i:s.u');
            elseif ($value !== null && !is_scalar($value)) throw new DocumentConflict('Неподдерживаемый тип значения справочника.');
        }
        unset($row, $value);
        if (count($rows) > $limit || strlen(json_encode($rows, JSON_THROW_ON_ERROR)) > 12000000) throw new DocumentConflict('Карточка слишком велика; данные не усечены.');
        $this->tables[$table] = true; return $rows;
    }
    private function assertEngines(): void
    {
        if ($this->db->dialect() !== 'mysql') return;
        $tables = array_keys($this->tables); sort($tables);
        $rows = $this->db->rows('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . self::marks($tables) . ') ORDER BY TABLE_NAME', $tables);
        if (array_column($rows, 'TABLE_NAME') !== $tables || count(array_filter($rows, static fn(array $r): bool => strtoupper((string)$r['ENGINE']) === 'INNODB')) !== count($tables)) throw new DocumentConflict('Карточка требует транзакционного хранения InnoDB.');
    }
    private static function linkSpec(): array { return ['CML2_LINK' => ['type' => 'E', 'multiple' => false, 'description' => false, 'userTypes' => ['', 'SKU']]]; }
    private static function adminUrl(array $iblock, int $id, bool $section = false): string
    { return '/bitrix/admin/iblock_' . ($section ? 'section' : 'element') . '_edit.php?' . http_build_query(['IBLOCK_ID' => (int)$iblock['ID'], 'type' => (string)$iblock['IBLOCK_TYPE_ID'], 'lang' => 'ru', 'ID' => $id]); }
    private static function marks(array $values): string { return implode(',', array_fill(0, count($values), '?')); }
    private static function id($value): int
    { if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$value)) throw new DocumentConflict('Некорректный ID справочника.'); return (int)$value; }
}
