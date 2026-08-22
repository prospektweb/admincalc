<?php

declare(strict_types=1);

namespace Bitrix\Main {
    final class Application
    {
        public static $connection;

        public static function getConnection()
        {
            return self::$connection;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/lib/Services/PresetProductAssignmentLockService.php';

    use Bitrix\Main\Application;
    use Prospektweb\Calc\Services\PresetProductAssignmentLockService;

    $assert = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };

    $connection = new class {
        public array $queries = [];
        public int $acquired = 1;

        public function getSqlHelper()
        {
            return new class {
                public function forSql(string $value): string
                {
                    return str_replace("'", "''", $value);
                }
            };
        }

        public function query(string $sql)
        {
            $this->queries[] = $sql;
            $value = str_contains($sql, 'GET_LOCK') ? $this->acquired : 1;
            return new class($value) {
                private ?array $row;

                public function __construct(int $value)
                {
                    $this->row = ['ACQUIRED' => $value, 'RELEASED' => $value];
                }

                public function fetch(): ?array
                {
                    $row = $this->row;
                    $this->row = null;
                    return $row;
                }
            };
        }
    };
    Application::$connection = $connection;

    $inside = false;
    $result = (new PresetProductAssignmentLockService())->withLock(
        7,
        static function (int $productIblockId) use (&$inside): string {
            $inside = $productIblockId === 7;
            return 'saved';
        }
    );
    $assert($inside && $result === 'saved', 'database lock wraps and returns the exact critical-section result');
    $assert(
        count($connection->queries) === 2
            && str_contains($connection->queries[0], "GET_LOCK('prospektweb.calc.preset-products.7', 5)")
            && str_contains($connection->queries[1], "RELEASE_LOCK('prospektweb.calc.preset-products.7')"),
        'product assignment mutex is acquired and released on the same database connection'
    );

    $connection->queries = [];
    $connection->acquired = 0;
    $called = false;
    $blocked = false;
    try {
        (new PresetProductAssignmentLockService())->withLock(
            7,
            static function () use (&$called): void {
                $called = true;
            }
        );
    } catch (RuntimeException $error) {
        $blocked = $error->getCode() === 409;
    }
    $assert(
        $blocked && !$called && count($connection->queries) === 1,
        'a contended database mutex fails before entering the critical section'
    );

    fwrite(STDOUT, "Preset product assignment DB lock tests passed\n");
}
