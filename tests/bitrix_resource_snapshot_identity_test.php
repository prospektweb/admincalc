<?php
declare(strict_types=1);
namespace Bitrix\Main\DB {
    abstract class MysqlCommonConnection { protected int $transactionLevel = 0; }
    class MysqliConnection extends MysqlCommonConnection {
        public array $events = [];
        public function queryExecute(string $sql): void {
            if ($sql !== 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') throw new \RuntimeException('Unexpected SQL');
            $this->events[] = 'isolation';
        }
        public function startTransaction(): void { $this->transactionLevel++; $this->events[] = 'begin'; }
        public function commitTransaction(): void { $this->transactionLevel--; $this->events[] = 'commit'; }
        public function rollbackTransaction(): void { $this->transactionLevel--; $this->events[] = 'rollback'; }
        public function getSqlHelper() { return new class { public function forSql(string $s): string { return $s; } }; }
        public function query(string $sql) {
            if (str_contains($sql, 'FROM b_option')) return new \SnapshotCursor([['MODULE_ID'=>'prospektweb.calc','NAME'=>'IBLOCK_CALC_MATERIALS','VALUE'=>'7','SITE_ID'=>null]]);
            if (str_contains($sql, 'FROM b_iblock')) return new \SnapshotCursor([['ID'=>7,'CODE'=>'CALC_MATERIALS']]);
            throw new \RuntimeException('Unexpected query');
        }
    }
}
namespace Bitrix\Main {
    class Application { public static $connection; public static function getConnection() { return self::$connection; } }
    class Loader { public static function includeModule(string $name): bool { return in_array($name, ['iblock', 'catalog'], true); } }
}
namespace Bitrix\Catalog {
    class ProductTable { public static function getList(array $p) { return new \SnapshotCursor([]); } }
}
namespace Prospektweb\Calc\Services {
    class EntityLoader {
        public static ?string $code = 'paper-code';
        public function loadPrices(array $ids): array { return []; }
        public function loadElements(int $iblock, array $ids): array {
            if ($iblock !== 7 || $ids !== [42]) throw new \RuntimeException('Wrong external identities');
            return [42 => ['FIELDS'=>['ACTIVE'=>'Y','NAME'=>'Paper &amp; Board','CODE'=>self::$code], 'PROPERTIES'=>[]]];
        }
    }
}
namespace {
    class SnapshotCursor { private int $i = 0; public function __construct(private array $rows) {} public function fetch() { return $this->rows[$this->i++] ?? false; } }
    class CCatalogGroup { public static function GetList(array $order, array $filter) { return new SnapshotCursor([]); } }
    require_once dirname(__DIR__) . '/lib/Documents/BitrixResourceProvider.php';
    $snapshotDb = new \Bitrix\Main\DB\MysqliConnection(); \Bitrix\Main\Application::$connection = $snapshotDb;
    $binding = (object)['provider'=>'external','catalog'=>'CALC_MATERIALS','key'=>'42'];
    $document = (object)['resources'=>[(object)['id'=>'native-paper','kind'=>'material','binding'=>$binding]], 'pricing'=>(object)['types'=>[]]];
    $provider = new \Prospektweb\Calc\Documents\BitrixResourceProvider('external');
    foreach (['paper-code', '', null] as $code) {
        \Prospektweb\Calc\Services\EntityLoader::$code = $code;
        $snapshot = $provider($document)[0];
        if ($snapshot['id'] !== 'native-paper' || $snapshot['binding'] !== $binding || $snapshot['code'] !== ($code ?? '')
            || $snapshot['name'] !== 'Paper & Board') throw new RuntimeException('Snapshot mixed native ID, external ID, code or label');
    }
    if ($snapshotDb->events !== array_merge(...array_fill(0, 3, ['isolation','begin','commit']))) throw new RuntimeException('Snapshot transaction changed');
    echo "PASS 4 resource snapshot identity assertions\n";
}
