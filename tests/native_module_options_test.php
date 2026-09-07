<?php
declare(strict_types=1);
namespace Bitrix\Main\DB {
    class MysqlCommonConnection { protected int $transactionLevel = 0; }
    class MysqliConnection extends MysqlCommonConnection {
        public \PDO $pdo; public string $engine = 'InnoDB';
        public function __construct() {
            $this->pdo = new \PDO('sqlite::memory:'); $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('CREATE TABLE b_option (MODULE_ID TEXT COLLATE NOCASE,NAME TEXT COLLATE NOCASE,VALUE TEXT,SITE_ID TEXT NULL)');
            $this->pdo->exec("CREATE TABLE b_module (ID TEXT); INSERT INTO b_module VALUES ('prospektweb.calc')");
        }
        public function getSqlHelper() { return new class($this->pdo) { public function __construct(private \PDO $pdo) {} public function forSql(string $s): string { return substr($this->pdo->quote($s), 1, -1); } }; }
        public function query(string $sql) {
            if (str_starts_with($sql, 'SHOW TABLE STATUS')) { $rows = [['Engine' => $this->engine]]; }
            else { $rows = $this->pdo->query(str_replace([' FOR UPDATE', 'BINARY '], '', $sql))->fetchAll(\PDO::FETCH_ASSOC); }
            return new class($rows) { public function __construct(private array $rows) {} public function fetch() { return array_shift($this->rows) ?: false; } };
        }
        public function queryExecute(string $sql): void { $this->pdo->exec(str_replace('BINARY ', '', $sql)); }
        public function startTransaction(): void { $this->pdo->beginTransaction(); $this->transactionLevel++; }
        public function commitTransaction(): void { $this->pdo->commit(); $this->transactionLevel--; }
        public function rollbackTransaction(): void { $this->pdo->rollBack(); $this->transactionLevel--; }
    }
}
namespace Bitrix\Main { class Application { public static int $clears = 0; public static function getInstance() { return new self(); } public function getManagedCache() { return $this; } public function clean(string $key, string $dir): void { if ($key !== 'b_option:prospektweb.calc' || $dir !== 'b_option') { throw new \RuntimeException('Unscoped cache clear'); } self::$clears++; } } }
namespace {
    class CEventLog { public static bool $fail = false; public static function Add(array $fields) { if (str_contains($fields['DESCRIPTION'], 'secret-value')) { throw new RuntimeException('Raw settings leaked to audit'); } return !self::$fail; } }
    require_once dirname(__DIR__) . '/lib/Config/ModuleOptions.php';
    $db = new \Bitrix\Main\DB\MysqliConnection();
    $options = new \Prospektweb\Calc\Config\ModuleOptions($db);
    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    $reject = static function (callable $call): void { try { $call(); } catch (Throwable $error) { return; } throw new RuntimeException('Unsafe operation accepted'); };
    $assert($options->get('Missing', null) === null, 'Missing is not empty');
    $reject(fn() => $options->set('SETTING', 'outside'));
    $options->mutate(function () use ($options): array { $options->set('setting', 'secret-value'); return ['before' => [], 'after' => ['setting' => 'secret-value']]; });
    $assert($options->get('SETTING') === 'secret-value', 'Case-compatible read without Option cache');
    $reject(fn() => $options->mutate(function () use ($options): void { $options->set('SETTING', 'rollback'); throw new RuntimeException('abort'); }));
    $assert($options->get('setting') === 'secret-value', 'Atomic rollback');
    $reject(fn() => $options->mutate(fn() => $options->mutate(fn() => null)));
    CEventLog::$fail = true;
    $reject(fn() => $options->mutate(function () use ($options): array { $options->set('SETTING', 'audit-failure'); return ['before' => [], 'after' => []]; }));
    $assert($options->get('setting') === 'secret-value', 'Audit failure rolls back settings'); CEventLog::$fail = false;
    $db->pdo->exec("INSERT INTO b_option VALUES ('prospektweb.calc','SETTING','duplicate',NULL)");
    $reject(fn() => $options->get('setting'));
    $db->pdo->exec("DELETE FROM b_option WHERE VALUE='duplicate'; UPDATE b_option SET SITE_ID='s1'");
    $reject(fn() => $options->get('setting'));
    $db->pdo->exec('UPDATE b_option SET SITE_ID=NULL'); $db->engine = 'MyISAM';
    $reject(fn() => $options->mutate(fn() => null));
    $assert(\Bitrix\Main\Application::$clears >= 4, 'Rollback and success invalidate only module cache');
    echo "native_module_options_test: PASS\n";
}
