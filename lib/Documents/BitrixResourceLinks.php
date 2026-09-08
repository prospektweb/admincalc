<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/SqlConnection.php';

/** The standard property API repairs its serialized V2 cache while reading.
 * Read only the three declared element-link properties from authoritative rows instead. */
final class BitrixResourceLinks
{
    private SqlConnection $db;
    private array $metadata = [];
    public function __construct(SqlConnection $db) { $this->db = $db; }
    public function load(int $iblockId, array $ids): array
    {
        if (!$this->db->inTransaction()) throw new \LogicException('Resource links require the browser snapshot.');
        if ($iblockId < 1 || $iblockId > 999999999 || count($ids) > 250 || !$ids) throw new \InvalidArgumentException('Invalid resource link batch.');
        foreach ($ids as $id) if (!is_int($id) || $id < 1 || $id > 999999999) throw new \InvalidArgumentException('Invalid resource identity.');
        if (count(array_unique($ids)) !== count($ids)) throw new \InvalidArgumentException('Duplicate resource identity.');
        if (!isset($this->metadata[$iblockId])) {
            $rows = $this->db->rows('SELECT ID,VERSION FROM b_iblock WHERE ID=?', [$iblockId]);
            if (count($rows) !== 1 || !in_array((string)$rows[0]['VERSION'], ['1','2'], true)) throw new \RuntimeException('Unsupported resource storage.', 409);
            $properties = $this->db->rows("SELECT ID,CODE,PROPERTY_TYPE,USER_TYPE,MULTIPLE FROM b_iblock_property WHERE IBLOCK_ID=? AND ACTIVE='Y' AND CODE IN ('CML2_LINK','SUPPORTED_EQUIPMENT_LIST','SUPPORTED_MATERIALS_VARIANTS_LIST') ORDER BY ID", [$iblockId]);
            $codes = [];
            foreach ($properties as $property) {
                if (!preg_match('/^[1-9][0-9]{0,8}$/D', (string)$property['ID']) || isset($codes[$property['CODE']]) || $property['PROPERTY_TYPE'] !== 'E'
                    || (string)($property['USER_TYPE'] ?? '') !== '' || !in_array($property['MULTIPLE'], ['Y','N'], true)
                    || ($property['CODE'] === 'CML2_LINK' && $property['MULTIPLE'] !== 'N')) throw new \RuntimeException('Resource link schema changed.', 409);
                $codes[$property['CODE']] = true;
            }
            $this->metadata[$iblockId] = [(int)$rows[0]['VERSION'], $properties];
        }
        [$version, $properties] = $this->metadata[$iblockId]; $result = array_fill_keys($ids, []);
        $byId = []; foreach ($properties as $property) $byId[(int)$property['ID']] = $property;
        if (!$byId) return $result;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $append = static function ($element, int $prop, $value) use (&$result, $byId): void {
            if (!isset($result[$element], $byId[$prop])) throw new \RuntimeException('Resource link escaped the requested batch.', 409);
            if ($value === null || $value === '' || (string)$value === '0') return;
            if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$value)) throw new \RuntimeException('Invalid resource link value.', 409);
            $code = $byId[$prop]['CODE'];
            if ($byId[$prop]['MULTIPLE'] === 'N' && isset($result[$element][$code])) throw new \RuntimeException('Duplicate single resource link.', 409);
            $result[$element][$code][] = (string)$value;
        };
        $single = $version === 2 ? array_filter($byId, static fn(array $p): bool => $p['MULTIPLE'] === 'N') : [];
        if ($single) {
            $columns = implode(',', array_map(static fn(int $id): string => 'PROPERTY_' . $id, array_keys($single)));
            $rows = $this->db->rows('SELECT IBLOCK_ELEMENT_ID,' . $columns . ' FROM b_iblock_element_prop_s' . $iblockId . ' WHERE IBLOCK_ELEMENT_ID IN (' . $marks . ') ORDER BY IBLOCK_ELEMENT_ID', $ids);
            if (count($rows) !== count($ids)) throw new \RuntimeException('Resource property row missing.', 409);
            foreach ($rows as $row) foreach ($single as $prop => $_) $append($row['IBLOCK_ELEMENT_ID'], $prop, $row['PROPERTY_' . $prop]);
        }
        $multi = array_diff_key($byId, $single);
        if ($multi) {
            $table = $version === 2 ? 'b_iblock_element_prop_m' . $iblockId : 'b_iblock_element_property';
            $where = implode(',', array_fill(0, count($multi), '?'));
            $rows = $this->db->rows('SELECT IBLOCK_ELEMENT_ID,IBLOCK_PROPERTY_ID,VALUE FROM ' . $table . ' WHERE IBLOCK_ELEMENT_ID IN (' . $marks . ') AND IBLOCK_PROPERTY_ID IN (' . $where . ') ORDER BY IBLOCK_ELEMENT_ID,IBLOCK_PROPERTY_ID,ID LIMIT 20001', array_merge($ids, array_keys($multi)));
            if (count($rows) > 20000) throw new \InvalidArgumentException('Слишком много связей ресурса; данные не усечены.');
            foreach ($rows as $row) $append($row['IBLOCK_ELEMENT_ID'], (int)$row['IBLOCK_PROPERTY_ID'], $row['VALUE']);
        }
        return $result;
    }
}
