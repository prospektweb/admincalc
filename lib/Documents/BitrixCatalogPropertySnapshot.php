<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/BitrixConnection.php';
require_once __DIR__ . '/DocumentCatalogWritePlan.php';
require_once __DIR__ . '/DocumentRepository.php';

/** Batched, uncached input reads on the catalog coordinator's connection. */
final class BitrixCatalogPropertySnapshot
{
    private SqlConnection $db;
    private array $evidence = [];
    private array $tables = [];
    private bool $lock = false;
    private const ROW_LIMIT = 20000;

    public function __construct(SqlConnection $db) { $this->db = $db; }

    /**
     * Sources are exact mapping.source objects (duplicates may feed several fields).
     * Returns serializable authority, semantic-validation authority and property
     * values indexed by scope/element/property. No preset, cache, API or writes.
     * Catalog pair, SKU-parent membership, settings and publication remain the
     * outer port's responsibility; this reader must not be an HTTP entrypoint.
     */
    public function capture(int $products, int $offers, array $productIds, array $offerIds, array $sources, bool $lock): array
    {
        if (!$this->db->inTransaction()) throw new \LogicException('Catalog input reads require a coordinator-owned snapshot.');
        if ($lock && $this->db instanceof BitrixConnection) $this->db->assertCatalogWriteTransaction();
        self::id($products); self::id($offers);
        if ($products === $offers) throw new \InvalidArgumentException('Product and offer catalogs must differ.');
        $elementIds = ['product' => self::ids($productIds), 'selected_offer' => self::ids($offerIds)];
        if (array_intersect($elementIds['product'], $elementIds['selected_offer'])) throw new \InvalidArgumentException('Catalog element scopes overlap.');
        if (!array_is_list($sources) || count($sources) > 200) throw new \InvalidArgumentException('Invalid catalog source list.');
        $catalogs = ['product' => $products, 'selected_offer' => $offers]; $wanted = [];
        foreach ($sources as $source) {
            if (!is_array($source)) throw new \InvalidArgumentException('Invalid source identity.');
            $keys = array_keys($source); sort($keys);
            $scope = $source['scope'] ?? '';
            if ($keys !== ['iblock_id', 'property_code', 'property_id', 'scope'] || !is_string($scope) || !isset($catalogs[$scope])
                || $source['iblock_id'] !== $catalogs[$scope] || !is_int($source['property_id'])
                || !is_string($source['property_code']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,254}$/D', $source['property_code'])) {
                throw new \InvalidArgumentException('Exact catalog source identity is required.');
            }
            $id = self::id($source['property_id']);
            if (isset($wanted[$id]) && $wanted[$id] !== $source) throw new \InvalidArgumentException('Conflicting catalog source identities.');
            $wanted[$id] = $source;
        }
        ksort($wanted, SORT_NUMERIC);
        $this->lock = $lock; $this->evidence = []; $this->tables = [];
        $iblocks = $this->select('b_iblock', '*', 'ID IN (?,?)', [$products, $offers], 'ID', 2);
        $versions = [];
        foreach ($iblocks as $row) {
            $id = self::dbId($row['ID']);
            if (!in_array($id, $catalogs, true) || isset($versions[$id]) || $row['ACTIVE'] !== 'Y' || !in_array((string)$row['VERSION'], ['1', '2'], true)) {
                throw new DocumentConflict('Схема каталога отсутствует или не поддерживается.');
            }
            $versions[$id] = (int)$row['VERSION'];
        }
        if (count($versions) !== 2) throw new DocumentConflict('Каталог отсутствует.');
        $elements = [];
        foreach ($catalogs as $scope => $iblock) {
            $ids = $elementIds[$scope];
            $rows = $this->select('b_iblock_element',
                'ID,IBLOCK_ID,NAME,ACTIVE,ACTIVE_FROM,ACTIVE_TO,CASE WHEN (ACTIVE_FROM IS NULL OR ACTIVE_FROM<=CURRENT_TIMESTAMP) AND (ACTIVE_TO IS NULL OR ACTIVE_TO>=CURRENT_TIMESTAMP) THEN 1 ELSE 0 END AS DATE_ACTIVE',
                'ID IN (' . self::marks($ids) . ')', $ids, 'ID', 100);
            if (count($rows) !== count($ids)) throw new DocumentConflict('Элемент каталога отсутствует.');
            foreach ($rows as $row) {
                $id = self::dbId($row['ID']);
                if (!in_array($id, $ids, true) || self::dbId($row['IBLOCK_ID']) !== $iblock || $row['ACTIVE'] !== 'Y' || (string)$row['DATE_ACTIVE'] !== '1') {
                    throw new DocumentConflict('Элемент неактивен или принадлежит другому каталогу.');
                }
                $elements[$scope][$id] = $row;
            }
        }
        $metadata = []; $enumRows = []; $directories = [];
        if ($wanted) {
            $ids = array_keys($wanted);
            $rows = $this->select('b_iblock_property', '*', 'ID IN (' . self::marks($ids) . ')', $ids, 'ID', 200);
            if (count($rows) !== count($wanted)) throw new DocumentConflict('Сопоставленное свойство удалено.');
            foreach ($rows as $row) {
                $id = self::dbId($row['ID']); $source = $wanted[$id] ?? null;
                if ($source === null || self::dbId($row['IBLOCK_ID']) !== $source['iblock_id'] || $row['CODE'] !== $source['property_code']
                    || $row['ACTIVE'] !== 'Y' || !in_array($row['MULTIPLE'], ['Y', 'N'], true)
                    || !in_array($row['PROPERTY_TYPE'], ['S', 'N', 'L', 'E', 'G', 'F'], true)
                    || (isset($row['VERSION']) && (string)$row['VERSION'] !== (string)$versions[$source['iblock_id']])) {
                    throw new DocumentConflict('Сопоставленное свойство изменило идентификатор или схему.');
                }
                // Arbitrary user-type callbacks cannot participate in a locked
                // snapshot. Standard scalar storage and registered directories can.
                $userType = (string)($row['USER_TYPE'] ?? '');
                if ($userType !== '' && (strtolower($userType) !== 'directory' || $row['PROPERTY_TYPE'] !== 'S')) {
                    throw new DocumentConflict('Пользовательский тип источника требует явного адаптера.');
                }
                $metadata[$id] = $row;
            }
            $lists = array_keys(array_filter($metadata, static fn(array $row): bool => $row['PROPERTY_TYPE'] === 'L'));
            if ($lists) {
                foreach ($this->select('b_iblock_property_enum', '*', 'PROPERTY_ID IN (' . self::marks($lists) . ')', $lists, 'PROPERTY_ID,SORT,ID') as $row) {
                    $enumRows[self::dbId($row['PROPERTY_ID'])][] = $row;
                }
            }
            foreach ($metadata as $id => $row) {
                if (strtolower((string)($row['USER_TYPE'] ?? '')) === 'directory') {
                    $settings = $row['USER_TYPE_SETTINGS'] ?? null;
                    if (is_string($settings)) $settings = @unserialize($settings, ['allowed_classes' => false]);
                    $table = is_array($settings) ? ($settings['TABLE_NAME'] ?? null) : null;
                    if (!is_string($table) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $table)) throw new DocumentConflict('Некорректная таблица справочника.');
                    if (!isset($directories[$table])) $directories[$table] = $this->directory($table);
                    $enumRows[$id] = $directories[$table];
                }
            }
        }
        $choices = []; $validation = ['product_iblock_id' => $products, 'offer_iblock_id' => $offers, 'properties' => []];
        $properties = [];
        foreach ($wanted as $id => $source) {
            $row = $metadata[$id]; $list = $enumRows[$id] ?? []; $xmlIds = []; $byId = []; $byXml = [];
            foreach ($list as $choice) {
                $choiceId = self::dbId($choice['ID']); $xml = trim((string)($choice['XML_ID'] ?? '')); $key = strtolower($xml);
                if ($xml === '' || isset($byId[$choiceId]) || isset($byXml[$key])) throw new DocumentConflict('Неоднозначный XML_ID справочника.');
                $value = ['value' => trim((string)$choice['VALUE']), 'xml_id' => $xml, 'sort' => (int)$choice['SORT']];
                $byId[$choiceId] = $value; $byXml[$key] = $value; $xmlIds[] = $xml;
            }
            $isEnum = $row['PROPERTY_TYPE'] === 'L' || strtolower((string)($row['USER_TYPE'] ?? '')) === 'directory';
            if ($isEnum && !$xmlIds) throw new DocumentConflict('Справочник значений пуст.');
            $choices[$id] = ['ids' => $byId, 'xml' => $byXml];
            $scope = $source['scope']; $iblock = $source['iblock_id'];
            $shape = ['scope' => $scope, 'code' => $row['CODE'], 'active' => true, 'property_type' => $row['PROPERTY_TYPE'],
                'user_type' => (string)($row['USER_TYPE'] ?? ''), 'multiple' => $row['MULTIPLE'] === 'Y', 'enum_xml_ids' => $xmlIds];
            $validation['properties'][$scope][$iblock][$id] = $shape;
            foreach ($elementIds[$scope] as $element) {
                $properties[$scope][$element][$id] = ['iblock_id' => $iblock, 'property_id' => $id, 'property_code' => $row['CODE'],
                    'active' => true, 'property_type' => $shape['property_type'], 'user_type' => $shape['user_type'],
                    'multiple' => $shape['multiple'], 'enum_xml_ids' => $xmlIds, 'values' => []];
            }
        }
        foreach ($catalogs as $scope => $iblock) {
            $ids = $elementIds[$scope];
            $props = array_keys(array_filter($wanted, static fn(array $source): bool => $source['scope'] === $scope));
            if (!$props) continue;
            if ($versions[$iblock] === 2) {
                $single = array_values(array_filter($props, static fn(int $id): bool => $metadata[$id]['MULTIPLE'] === 'N'));
                $multi = array_values(array_diff($props, $single));
                if ($single) {
                    $columns = 'IBLOCK_ELEMENT_ID,' . implode(',', array_map(static fn(int $id): string => 'PROPERTY_' . $id, $single));
                    $rows = $this->select('b_iblock_element_prop_s' . $iblock, $columns, 'IBLOCK_ELEMENT_ID IN (' . self::marks($ids) . ')', $ids, 'IBLOCK_ELEMENT_ID', 100);
                    // An existing element without its V2 row is not a valid empty value.
                    if (count($rows) !== count($ids)) throw new DocumentConflict('Отсутствует строка отдельного хранения свойств.');
                    foreach ($rows as $row) foreach ($single as $prop) {
                        $this->append($properties, $scope, self::dbId($row['IBLOCK_ELEMENT_ID']), $prop, $row['PROPERTY_' . $prop], $choices);
                    }
                }
                $table = 'b_iblock_element_prop_m' . $iblock;
            } else { $multi = $props; $table = 'b_iblock_element_property'; }
            if ($multi) {
                $rows = $this->select($table, '*', 'IBLOCK_ELEMENT_ID IN (' . self::marks($ids) . ') AND IBLOCK_PROPERTY_ID IN (' . self::marks($multi) . ')',
                    array_merge($ids, $multi), 'IBLOCK_ELEMENT_ID,IBLOCK_PROPERTY_ID,ID');
                foreach ($rows as $row) {
                    $prop = self::dbId($row['IBLOCK_PROPERTY_ID']);
                    // Bitrix joins list choices on VALUE_ENUM in both V1 and V2-multiple.
                    $raw = $metadata[$prop]['PROPERTY_TYPE'] === 'L' ? $row['VALUE_ENUM'] : $row['VALUE'];
                    if ($raw === null && $metadata[$prop]['PROPERTY_TYPE'] === 'L' && ($row['VALUE'] ?? '') !== '') throw new DocumentConflict('Повреждено enum-значение свойства.');
                    $this->append($properties, $scope, self::dbId($row['IBLOCK_ELEMENT_ID']), $prop, $raw, $choices);
                }
            }
        }
        foreach ($properties as &$byElement) foreach ($byElement as &$byProperty) foreach ($byProperty as &$property) {
            if (!$property['multiple'] && count($property['values']) > 1) throw new DocumentConflict('Одиночное свойство имеет несколько значений.');
            // Same visible order as the regular property prefill, deterministic on ties.
            usort($property['values'], static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);
        }
        unset($byElement, $byProperty, $property);
        $this->assertEngines();
        return ['authority' => (object)['fingerprint' => DocumentCatalogWritePlan::hash($this->evidence)],
            'sourceAuthority' => $validation, 'properties' => $properties, 'elements' => $elements];
    }

    private function append(array &$properties, string $scope, int $element, int $prop, $raw, array $choices): void
    {
        if (!isset($properties[$scope][$element][$prop])) throw new DocumentConflict('Значение вышло за пределы выбранных источников.');
        if ($raw === null || $raw === '') return;
        if (!is_scalar($raw) || is_bool($raw)) throw new DocumentConflict('Некорректное значение свойства.');
        if (trim((string)$raw) === '') return;
        $property =& $properties[$scope][$element][$prop];
        if ($property['property_type'] === 'L') {
            $value = $choices[$prop]['ids'][self::dbId($raw)] ?? null;
        } elseif (strtolower($property['user_type']) === 'directory') {
            $value = $choices[$prop]['xml'][strtolower(trim((string)$raw))] ?? null;
            if ($value !== null && $value['xml_id'] !== trim((string)$raw)) $value = null;
        } else { $value = ['value' => trim((string)$raw), 'xml_id' => '', 'sort' => 0]; }
        if ($value === null) throw new DocumentConflict('Значение свойства не найдено в точном справочнике.');
        $property['values'][] = $value;
    }

    private function directory(string $table): array
    {
        $registry = $this->select('b_hlblock_entity', '*', 'TABLE_NAME=?', [$table], 'ID', 1);
        if (count($registry) !== 1 || $registry[0]['TABLE_NAME'] !== $table) throw new DocumentConflict('Таблица справочника не зарегистрирована однозначно.');
        $id = self::dbId($registry[0]['ID']);
        $fields = $this->select('b_user_field', '*', 'ENTITY_ID=?', ['HLBLOCK_' . $id], 'ID', 200);
        $names = [];
        foreach ($fields as $field) {
            $name = $field['FIELD_NAME'];
            if (isset($names[$name])) throw new DocumentConflict('Повторное поле справочника.');
            $names[$name] = $field;
        }
        if (!isset($names['UF_XML_ID'])) throw new DocumentConflict('Неполная схема справочника.');
        $labels = array_values(array_filter(['UF_NAME', 'UF_DESCRIPTION', 'UF_XML_ID'], static fn(string $name): bool => isset($names[$name])));
        foreach ($labels as $name) {
            if ($names[$name]['MULTIPLE'] !== 'N' || $names[$name]['USER_TYPE_ID'] !== 'string') throw new DocumentConflict('Неполная схема справочника.');
        }
        if (isset($names['UF_SORT']) && ($names['UF_SORT']['MULTIPLE'] !== 'N' || $names['UF_SORT']['USER_TYPE_ID'] !== 'integer')) throw new DocumentConflict('Некорректная сортировка справочника.');
        $sort = isset($names['UF_SORT']) ? 'UF_SORT' : '0';
        $label = count($labels) > 1 ? 'COALESCE(' . implode(',', $labels) . ')' : $labels[0];
        $rows = $this->select($table, 'ID,UF_XML_ID AS XML_ID,' . $label . ' AS VALUE,' . $sort . ' AS SORT', '1=1', [], isset($names['UF_SORT']) ? 'UF_SORT,ID' : 'ID');
        return $rows;
    }

    private function select(string $table, string $columns, string $where, array $parameters, string $order, int $limit = self::ROW_LIMIT): array
    {
        $rows = $this->db->rows('SELECT ' . $columns . ' FROM ' . $table . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . ($limit + 1)
            . ($this->lock && $this->db->dialect() === 'mysql' ? ' FOR UPDATE' : ''), $parameters);
        if (count($rows) > $limit) throw new DocumentConflict('Превышен лимит или источник неоднозначен.');
        $this->tables[$table] = true;
        $this->evidence[] = [$table, $columns, $where, $parameters, $rows];
        if (strlen(DocumentCatalogWritePlan::canonical($this->evidence)) > 12000000) throw new DocumentConflict('Слишком большой снимок источников.');
        return $rows;
    }

    private function assertEngines(): void
    {
        if ($this->db->dialect() !== 'mysql') return;
        // Data reads have already acquired metadata locks, closing the ALTER gap.
        $tables = array_keys($this->tables); sort($tables);
        $rows = $this->db->rows('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . self::marks($tables) . ') ORDER BY TABLE_NAME', $tables);
        if (array_column($rows, 'TABLE_NAME') !== $tables || count(array_filter($rows, static fn(array $row): bool => strtoupper((string)$row['ENGINE']) === 'INNODB')) !== count($tables)) {
            throw new DocumentConflict('Для снимка входов нужны транзакционные таблицы InnoDB.');
        }
    }
    private static function id(int $value): int { if ($value < 1 || $value > 999999999) throw new \InvalidArgumentException('Invalid catalog identity.'); return $value; }
    private static function dbId($value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$value)) throw new DocumentConflict('Некорректный ID источника.');
        return self::id((int)$value);
    }
    private static function ids(array $values): array
    {
        if (!array_is_list($values) || !$values || count($values) > 100 || count(array_unique($values, SORT_REGULAR)) !== count($values)) throw new \InvalidArgumentException('Invalid catalog element list.');
        foreach ($values as $value) { if (!is_int($value)) throw new \InvalidArgumentException('Invalid catalog element ID.'); self::id($value); }
        sort($values, SORT_NUMERIC); return $values;
    }
    private static function marks(array $values): string { return implode(',', array_fill(0, count($values), '?')); }
}
