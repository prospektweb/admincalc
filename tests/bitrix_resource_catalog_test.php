<?php
declare(strict_types=1);
namespace Bitrix\Main\DB {
    abstract class MysqlCommonConnection { protected int $transactionLevel = 0; }
    class MysqliConnection extends MysqlCommonConnection {
        public array $events = [];
        public function queryExecute(string $sql): void { if ($sql !== 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY') throw new \RuntimeException('Unexpected SQL'); $this->events[] = 'read-only'; }
        public function startTransaction(): void { $this->transactionLevel++; $this->events[] = 'begin'; }
        public function commitTransaction(): void { $this->transactionLevel--; $this->events[] = 'commit'; }
        public function rollbackTransaction(): void { $this->transactionLevel--; $this->events[] = 'rollback'; }
    }
}
namespace Bitrix\Main {
    class Application { public static $connection; public static function getConnection() { return self::$connection; } }
    class Loader { public static function includeModule(string $name): bool { return $name === 'iblock'; } }
}
namespace {
    class Cursor { private int $position = 0; public function __construct(private array $rows) {} public function Fetch() { return $this->rows[$this->position++] ?? false; } }
    class CIBlockSection {
        public static function GetList($order, $filter, $count, $select) {
            if (($filter['CHECK_PERMISSIONS'] ?? '') !== 'Y') throw new RuntimeException('Permissions required');
            return new Cursor([['ID' => 1, 'IBLOCK_ID' => $filter['IBLOCK_ID'], 'IBLOCK_SECTION_ID' => 0, 'NAME' => 'Directory']]);
        }
    }
    class CIBlockElement {
        public static int $count = 2; public static int $bulk = 0; public static bool $wrong = false;
        public static function GetList($order, $filter, $group, $nav, $select) {
            if ($filter['CHECK_PERMISSIONS'] !== 'Y' || $filter['ACTIVE'] !== 'Y' || $select !== ['ID','IBLOCK_ID','IBLOCK_SECTION_ID','NAME','CODE','PREVIEW_TEXT']) throw new RuntimeException('Unexpected resource read');
            $rows = []; for ($i = 1; $i <= self::$count; $i++) $rows[] = ['ID' => $i, 'IBLOCK_ID' => self::$wrong ? 999 : $filter['IBLOCK_ID'], 'IBLOCK_SECTION_ID' => 1, 'NAME' => 'Resource ' . $i, 'CODE' => 'r' . $i, 'PREVIEW_TEXT' => 'Description'];
            return new Cursor($rows);
        }
        public static function GetPropertyValuesArray(&$batch, $iblock, $filter, $props, $options) {
            self::$bulk++;
            if (count($batch) > 250 || $filter['ID'] !== array_keys($batch) || $props['CODE'] !== ['CML2_LINK','SUPPORTED_EQUIPMENT_LIST','SUPPORTED_MATERIALS_VARIANTS_LIST'] || $options !== ['GET_RAW_DATA' => 'Y']) throw new RuntimeException('Unexpected property batch');
            foreach ($batch as &$row) $row['PROPERTIES'] = ['CML2_LINK' => ['VALUE' => in_array($iblock, [2, 4], true) ? '1' : ''], 'SUPPORTED_EQUIPMENT_LIST' => ['VALUE' => ['5', '5', '', '0']], 'SUPPORTED_MATERIALS_VARIANTS_LIST' => ['VALUE' => '7']];
        }
    }
    require_once dirname(__DIR__) . '/lib/Documents/BitrixResourceCatalog.php';
    $db = new \Bitrix\Main\DB\MysqliConnection(); \Bitrix\Main\Application::$connection = $db;
    $map = ['CALC_MATERIALS'=>1, 'CALC_MATERIALS_VARIANTS'=>2, 'CALC_OPERATIONS'=>3, 'CALC_OPERATIONS_VARIANTS'=>4, 'CALC_EQUIPMENT'=>5];
    $links = static function (int $iblock, array $ids): array {
        CIBlockElement::$bulk++; if (count($ids) > 250) throw new RuntimeException('Unbounded links');
        return array_fill_keys($ids, ['CML2_LINK' => in_array($iblock, [2,4], true) ? ['1'] : [], 'SUPPORTED_EQUIPMENT_LIST' => ['5','5','','0'], 'SUPPORTED_MATERIALS_VARIANTS_LIST' => ['7']]);
    };
    $browser = new \Prospektweb\Calc\Documents\BitrixResourceCatalog('bitrix:test', fn($code) => $map[$code], $links); $checks = 0;
    $check = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($message); };
    $result = $browser();
    $check(count($result['items']) === 10 && count($result['sections']) === 5 && CIBlockElement::$bulk === 5, 'Bounded bulk queries, full directories');
    $check($result['items'][2]['parentBinding'] === ['provider'=>'bitrix:test','catalog'=>'CALC_MATERIALS','key'=>'1'], 'Explicit external parent binding');
    $check($result['items'][0]['supportedEquipmentKeys'] === ['5'] && $result['items'][0]['supportedMaterialVariantKeys'] === ['7'], 'Typed supported identities');
    $check($db->events === ['read-only','begin','commit'], 'Read-only snapshot');
    $db->events = []; CIBlockElement::$wrong = true;
    try { $browser(); throw new LogicException('Expected wrong catalog rejection'); } catch (RuntimeException $e) { $check($e->getCode() === 409, 'Catalog identity checked'); }
    $check($db->events === ['read-only','begin','rollback'], 'Failure rolls back'); CIBlockElement::$wrong = false;
    CIBlockElement::$bulk = 0; CIBlockElement::$count = 501; $result = $browser();
    $check(count($result['items']) === 2505 && CIBlockElement::$bulk === 15, 'No N+1 across three property batches per directory');
    CIBlockElement::$count = 20001;
    try { $browser(); throw new LogicException('Expected bound rejection'); } catch (InvalidArgumentException $e) { $check(str_contains($e->getMessage(), 'не усечены'), 'No silent truncation'); }
    $db->startTransaction(); $events = $db->events;
    try { $browser(); throw new RuntimeException('Expected existing transaction rejection'); } catch (LogicException $e) { $check($db->events === $events, 'Never joins another transaction'); } $db->rollbackTransaction();
    echo "PASS $checks Bitrix catalog browser assertions\n";
}
