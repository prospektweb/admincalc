<?php
declare(strict_types=1);
namespace Bitrix\Main { class Loader { public static function includeModule(string $id): bool { return in_array($id, ['iblock', 'catalog', 'currency'], true); } } }
namespace {
    function rows(array $rows) { return new class(array_values($rows)) { public function __construct(private array $rows) {} public function Fetch() { return array_shift($this->rows) ?: false; } }; }
    class CSite { public static function GetDefSite(): string { return 's1'; } }
    class CCurrency { public static array $items = []; public static function GetByID($id) { return self::$items[$id] ?? false; } public static function Add(array $f): bool { self::$items[$f['CURRENCY']] = $f; return true; } }
    class CCurrencyLang { public static array $items = []; public static function GetByID($id, $lang) { return self::$items[$id . ':' . $lang] ?? false; } public static function Add(array $f): bool { self::$items[$f['CURRENCY'] . ':' . $f['LID']] = $f; return true; } }
    class CIBlockType { public static array $items = []; public static function GetByID($id) { return rows(isset(self::$items[$id]) ? [self::$items[$id]] : []); } public function Add(array $fields) { self::$items[$fields['ID']] = $fields; return $fields['ID']; } }
    class CIBlock {
        public static array $items = [];
        public static function GetList($order, array $filter) { return rows(array_filter(self::$items, fn($r) => ($r['CODE'] ?? '') === $filter['CODE'])); }
        public function Add(array $f): int { $id = count(self::$items) + 1; self::$items[$id] = ['ID' => $id] + $f; return $id; }
    }
    class CIBlockProperty {
        public static array $items = [];
        public static function GetList($order, array $filter) { return rows(array_filter(self::$items, function ($r) use ($filter) { foreach ($filter as $k => $v) { if ((string)($r[$k] ?? '') !== (string)$v) return false; } return true; })); }
        public function Add(array $f): int { $id = count(self::$items) + 1; self::$items[$id] = ['ID' => $id] + $f; return $id; }
    }
    class CCatalog {
        public static array $items = [];
        public static function GetByID(int $id) { return self::$items[$id] ?? false; }
        public static function Add(array $f): bool { self::$items[$f['IBLOCK_ID']] = $f; return true; }
    }
    // Deliberately no CIBlockElement / CIBlockSection implementation: content writes fail.
    require_once dirname(__DIR__) . '/lib/Install/ResourceDirectoryInstaller.php';
    $installer = new \Prospektweb\Calc\Install\ResourceDirectoryInstaller();
    $ids = $installer->install(); $before = serialize([CIBlock::$items, CIBlockProperty::$items, CCatalog::$items]);
    if (count($ids) !== 6 || array_keys($ids) !== array_keys(\Prospektweb\Calc\Documents\ResourceCatalogRegistry::LABELS)) throw new RuntimeException('Only six resource directories');
    if ($installer->install() !== $ids || serialize([CIBlock::$items, CIBlockProperty::$items, CCatalog::$items]) !== $before) throw new RuntimeException('Second install must be a no-op');
    foreach (['CALC_PRESETS','CALC_STAGES','CALC_SETTINGS','CALC_DETAILS','CALC_GLOBAL_VALUES','CALC_CUSTOM_FIELDS'] as $code) { if (isset($ids[$code])) throw new RuntimeException('Legacy iblock recreated'); }
    if (CIBlock::$items[$ids['CALC_SUPPLIERS']]['GROUP_ID']['2'] !== 'D') throw new RuntimeException('Supplier directory must be private');
    $property = array_key_first(CIBlockProperty::$items); CIBlockProperty::$items[$property]['PROPERTY_TYPE'] = 'N';
    try { $installer->install(); throw new LogicException('Conflict accepted'); } catch (RuntimeException $expected) { if ($expected instanceof LogicException) throw $expected; }
    CIBlockProperty::$items[$property]['PROPERTY_TYPE'] = 'S';
    CIBlock::$items[99] = CIBlock::$items[1]; CIBlock::$items[99]['ID'] = 99;
    try { $installer->install(); throw new LogicException('Duplicate accepted'); } catch (RuntimeException $expected) { if ($expected instanceof LogicException) throw $expected; }
    echo "native_resource_installer_test: PASS (isolated API harness, not a licensed Bitrix staging install)\n";
}
