<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/BitrixConnection.php';
require_once __DIR__ . '/DocumentRepository.php';

/** Exact resource-card properties without Bitrix's write-on-read V2 cache.
 * The caller owns the transaction, catalog authority and element membership.
 * Specs are internal adapter declarations, never accepted from HTTP callers. */
final class BitrixResourceCardProperties
{
    private SqlConnection $db;
    public function __construct(SqlConnection $db) { $this->db = $db; }

    /** @return array{version:int,metadata:array,values:array,tables:array} */
    public function read(int $iblockId, array $ids, array $specs, bool $lock = false): array
    {
        if (!$this->db->inTransaction()) throw new \LogicException('Resource card properties require their coordinator snapshot.');
        if ($lock && $this->db instanceof BitrixConnection) $this->db->assertCatalogWriteTransaction();
        self::id($iblockId);
        if (!array_is_list($ids) || !$ids || count($ids) > 1000 || count(array_unique($ids)) !== count($ids)) throw new \InvalidArgumentException('Invalid resource card element batch.');
        foreach ($ids as $id) self::id($id);
        if (!$specs || count($specs) > 20) throw new \InvalidArgumentException('Invalid resource property declarations.');
        foreach ($specs as $code => $spec) {
            if (!is_string($code) || !preg_match('/^[A-Z][A-Z0-9_]{0,99}$/D', $code) || !is_array($spec)
                || !in_array($spec['type'] ?? null, ['S', 'E'], true) || !is_bool($spec['multiple'] ?? null)
                || !is_bool($spec['description'] ?? null)
                || (isset($spec['userTypes']) && (!is_array($spec['userTypes']) || !array_is_list($spec['userTypes']) || array_diff($spec['userTypes'], ['', 'SKU'])))) throw new \InvalidArgumentException('Invalid resource property declaration.');
        }
        $suffix = $lock && $this->db->dialect() === 'mysql' ? ' FOR UPDATE' : '';
        $tables = ['b_iblock', 'b_iblock_property'];
        $iblocks = $this->db->rows('SELECT ID,VERSION FROM b_iblock WHERE ID=?' . $suffix, [$iblockId]);
        if (count($iblocks) !== 1 || (string)$iblocks[0]['ID'] !== (string)$iblockId || !in_array((string)$iblocks[0]['VERSION'], ['1', '2'], true)) throw new DocumentConflict('Схема хранения справочника изменилась.');
        $version = (int)$iblocks[0]['VERSION'];
        $rows = $this->db->rows('SELECT ID,IBLOCK_ID,CODE,ACTIVE,PROPERTY_TYPE,USER_TYPE,MULTIPLE,WITH_DESCRIPTION FROM b_iblock_property WHERE IBLOCK_ID=? AND CODE IN (' . self::marks(array_keys($specs)) . ') ORDER BY ID' . $suffix, array_merge([$iblockId], array_keys($specs)));
        $metadata = []; $byId = [];
        foreach ($rows as $row) {
            $code = $row['CODE']; $spec = $specs[$code] ?? null;
            if (!$spec || isset($metadata[$code]) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$row['ID'])
                || (string)$row['IBLOCK_ID'] !== (string)$iblockId || $row['ACTIVE'] !== 'Y' || $row['PROPERTY_TYPE'] !== $spec['type']
                || !in_array((string)($row['USER_TYPE'] ?? ''), $spec['userTypes'] ?? [''], true) || $row['MULTIPLE'] !== ($spec['multiple'] ? 'Y' : 'N')
                || ($spec['description'] && $row['WITH_DESCRIPTION'] !== 'Y')) throw new DocumentConflict('Свойство карточки изменило схему: ' . (string)$code);
            $metadata[$code] = $row; $byId[(int)$row['ID']] = $code;
        }
        if (count($metadata) !== count($specs)) throw new DocumentConflict('Свойство карточки отсутствует.');
        $values = array_fill_keys($ids, array_fill_keys(array_keys($specs), []));
        $append = static function ($element, int $property, $value, $description) use (&$values, $byId, $specs): void {
            if (!preg_match('/^[1-9][0-9]{0,8}$/D', (string)$element) || !isset($values[(int)$element], $byId[$property])) throw new DocumentConflict('Значение свойства вышло за пределы карточки.');
            $code = $byId[$property]; $spec = $specs[$code];
            if ($value === null || $value === '') return;
            if ((!is_string($value) && !is_int($value)) || (!is_string($description) && $description !== null)) throw new DocumentConflict('Некорректное значение свойства карточки.');
            if ($spec['type'] === 'E' && !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$value)) throw new DocumentConflict('Некорректная связь справочника.');
            if (!$spec['multiple'] && $values[(int)$element][$code]) throw new DocumentConflict('Одиночное свойство содержит несколько значений.');
            $values[(int)$element][$code][] = ['value' => (string)$value, 'description' => (string)($description ?? '')];
        };
        $single = $version === 2 ? array_filter($byId, static fn(string $code): bool => !$specs[$code]['multiple']) : [];
        if ($single) {
            $table = 'b_iblock_element_prop_s' . $iblockId; $tables[] = $table;
            $columns = ['IBLOCK_ELEMENT_ID'];
            foreach ($single as $id => $code) {
                $columns[] = 'PROPERTY_' . $id;
                if ($metadata[$code]['WITH_DESCRIPTION'] === 'Y') $columns[] = 'DESCRIPTION_' . $id;
            }
            $rows = $this->db->rows('SELECT ' . implode(',', $columns) . ' FROM ' . $table . ' WHERE IBLOCK_ELEMENT_ID IN (' . self::marks($ids) . ') ORDER BY IBLOCK_ELEMENT_ID' . $suffix, $ids);
            if (count($rows) !== count($ids)) throw new DocumentConflict('Строка свойств ресурса отсутствует.');
            $seen = [];
            foreach ($rows as $row) {
                if (isset($seen[$row['IBLOCK_ELEMENT_ID']])) throw new DocumentConflict('Повторная строка свойств ресурса.');
                $seen[$row['IBLOCK_ELEMENT_ID']] = true;
                foreach ($single as $id => $code) $append($row['IBLOCK_ELEMENT_ID'], $id, $row['PROPERTY_' . $id], $row['DESCRIPTION_' . $id] ?? null);
            }
        }
        $multi = array_diff_key($byId, $single);
        if ($multi) {
            $table = $version === 2 ? 'b_iblock_element_prop_m' . $iblockId : 'b_iblock_element_property'; $tables[] = $table;
            $rows = $this->db->rows('SELECT IBLOCK_ELEMENT_ID,IBLOCK_PROPERTY_ID,VALUE,DESCRIPTION FROM ' . $table . ' WHERE IBLOCK_ELEMENT_ID IN (' . self::marks($ids) . ') AND IBLOCK_PROPERTY_ID IN (' . self::marks(array_keys($multi)) . ') ORDER BY IBLOCK_ELEMENT_ID,IBLOCK_PROPERTY_ID,ID LIMIT 20001' . $suffix, array_merge($ids, array_keys($multi)));
            if (count($rows) > 20000) throw new DocumentConflict('Карточка слишком велика; значения не усечены.');
            foreach ($rows as $row) $append($row['IBLOCK_ELEMENT_ID'], (int)$row['IBLOCK_PROPERTY_ID'], $row['VALUE'], $row['DESCRIPTION']);
        }
        if ($this->db->dialect() === 'mysql') {
            sort($tables);
            $engines = $this->db->rows('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . self::marks($tables) . ') ORDER BY TABLE_NAME', $tables);
            if (array_column($engines, 'TABLE_NAME') !== $tables || count(array_filter($engines, static fn(array $row): bool => strtoupper((string)$row['ENGINE']) === 'INNODB')) !== count($tables)) throw new DocumentConflict('Карточка требует транзакционного хранения InnoDB.');
        }
        return compact('version', 'metadata', 'values', 'tables');
    }
    private static function marks(array $values): string { return implode(',', array_fill(0, count($values), '?')); }
    private static function id($value): void
    { if (!is_int($value) || $value < 1 || $value > 999999999) throw new \InvalidArgumentException('Invalid resource identity.'); }
}
