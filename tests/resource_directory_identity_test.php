<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Install/ResourceDirectoryIdentity.php';
use Prospektweb\Calc\Install\ResourceDirectoryIdentity as Identity;
class Cursor { public function __construct(private array $row) {} public function Fetch(): array { return $this->row; } }
class CIBlock {
    public static array $rows = [], $writes = [];
    public function Update(int $id, array $fields): bool { self::$writes[] = [$id, $fields]; self::$rows[$id] = array_replace(self::$rows[$id], $fields); return true; }
    public static function GetByID(int $id): Cursor { return new Cursor([]); }
    public static function GetList(array $sort, array $filter): Cursor { return new Cursor(($filter['CHECK_PERMISSIONS'] ?? '') === 'N' ? self::$rows[$filter['ID']] : []); }
}
$check = static function(bool $value): void { if (!$value) throw new RuntimeException('Identity regression'); };
$fails = static function(callable $fn) use ($check): void { try { $fn(); } catch (RuntimeException $e) { return; } $check(false); };
foreach (['CALC_MATERIALS', 'CALC_MATERIALS_VARIANTS'] as $code) {
    $row=['ID'=>45,'CODE'=>$code,'IBLOCK_TYPE_ID'=>'catalog','ELEMENTS'=>[1,2],'CML2_LINK'=>901]; CIBlock::$rows=[45=>$row]; CIBlock::$writes=[];
    $check(Identity::reconcile($code, [$row]) === 45);
    $check(CIBlock::$writes === [[45,['IBLOCK_TYPE_ID'=>'calculator_catalog']]]);
    $check(CIBlock::$rows[45]['ELEMENTS'] === [1,2] && CIBlock::$rows[45]['CML2_LINK'] === 901);
    $check(Identity::reconcile($code, [CIBlock::$rows[45]]) === 45 && count(CIBlock::$writes) === 1);
    CIBlock::$writes=[]; $fails(fn()=>Identity::reconcile($code, [$row,$row])); $check(CIBlock::$writes === []);
}
$check(Identity::reconcile('CALC_MATERIALS', []) === null);
$fails(fn()=>Identity::reconcile('CALC_EQUIPMENT', [['ID'=>45,'CODE'=>'CALC_EQUIPMENT','IBLOCK_TYPE_ID'=>'catalog']]));
$fails(fn()=>Identity::reconcile('CALC_MATERIALS', [['ID'=>45,'CODE'=>'OTHER','IBLOCK_TYPE_ID'=>'calculator_catalog']]));
echo "resource_directory_identity_test: PASS (legacy repair, retained IDs/data/links, repeat no-op, duplicates, wrong ownership)\n";
