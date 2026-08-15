<?php

namespace Bitrix\Main {
    final class Application
    {
        /** @var mixed */
        public static $connection;

        /** @return mixed */
        public static function getConnection()
        {
            return self::$connection;
        }
    }
}

namespace Bitrix\Main\Config {
    final class Option
    {
        public static int $getCalls = 0;

        public static function get(string $moduleId, string $name, string $default = ''): string
        {
            self::$getCalls++;
            return $default;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/lib/Services/CatalogAdapterDefinitionService.php';

    use Bitrix\Main\Application;
    use Bitrix\Main\Config\Option;
    use Prospektweb\Calc\Services\CatalogAdapterDefinitionService;

    $assert = static function (bool $condition, string $message): void {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };
    $expectFailure = static function (callable $callback, string $message, ?int $code = null) use ($assert): void {
        try {
            $callback();
        } catch (\Throwable $error) {
            if ($code !== null) {
                $assert($error->getCode() === $code, $message . ' has the expected error code');
            }
            return;
        }
        $assert(false, $message);
    };

    final class CatalogAdapterDirectReadResult
    {
        /** @var array<int,array<string,mixed>> */
        private array $rows;
        private int $offset = 0;

        /** @param array<int,array<string,mixed>> $rows */
        public function __construct(array $rows)
        {
            $this->rows = array_values($rows);
        }

        /** @return array<string,mixed>|false */
        public function fetch()
        {
            if (!isset($this->rows[$this->offset])) {
                return false;
            }
            return $this->rows[$this->offset++];
        }
    }

    final class CatalogAdapterDirectReadConnection
    {
        /** @var array<int,array<string,mixed>> */
        private array $rows;
        public string $sql = '';

        /** @param array<int,array<string,mixed>> $rows */
        public function __construct(array $rows)
        {
            $this->rows = $rows;
        }

        public function query(string $sql): CatalogAdapterDirectReadResult
        {
            $this->sql = $sql;
            return new CatalogAdapterDirectReadResult($this->rows);
        }
    }

    $codec = new CatalogAdapterDefinitionService(['get_option' => static fn(): string => '']);
    $default = $codec->load();
    $raw = CatalogAdapterDefinitionService::encodeCanonical($default);

    $connection = new CatalogAdapterDirectReadConnection([[
        'MODULE_ID' => 'prospektweb.calc',
        'NAME' => 'catalog_adapter_12740',
        'VALUE' => $raw,
    ]]);
    Application::$connection = $connection;
    $loaded = (new CatalogAdapterDefinitionService())->load();
    $assert(
        hash_equals((string)$default['revision'], (string)$loaded['revision']),
        'adapter load reads and validates the exact direct global option value'
    );
    $assert(Option::$getCalls === 0, 'adapter load bypasses the stale Bitrix Option cache');
    $assert(
        strpos($connection->sql, "(SITE_ID IS NULL OR SITE_ID='')") !== false,
        'direct adapter load is restricted to global option rows'
    );

    Application::$connection = new CatalogAdapterDirectReadConnection([]);
    $missing = (new CatalogAdapterDefinitionService())->load();
    $assert(
        hash_equals((string)$default['revision'], (string)$missing['revision']),
        'an absent direct option still resolves to the immutable default adapter'
    );

    Application::$connection = new CatalogAdapterDirectReadConnection([
        ['MODULE_ID' => 'prospektweb.calc', 'NAME' => 'CATALOG_ADAPTER_12740', 'VALUE' => $raw],
        ['MODULE_ID' => 'prospektweb.calc', 'NAME' => 'catalog_adapter_12740', 'VALUE' => $raw],
    ]);
    $expectFailure(
        static fn() => (new CatalogAdapterDefinitionService())->load(),
        'canonical duplicate adapter rows fail closed',
        409
    );

    Application::$connection = new CatalogAdapterDirectReadConnection([[
        'MODULE_ID' => 'prospektweb.calc',
        'NAME' => 'catalog_adapter_12740 ',
        'VALUE' => $raw,
    ]]);
    $expectFailure(
        static fn() => (new CatalogAdapterDefinitionService())->load(),
        'whitespace aliases are not accepted as adapter authority'
    );

    Application::$connection = new CatalogAdapterDirectReadConnection([[
        'MODULE_ID' => 'PROSPEKTWEB.CALC',
        'NAME' => 'CATALOG_ADAPTER_12740',
        'VALUE' => $raw,
    ]]);
    $expectFailure(
        static fn() => (new CatalogAdapterDefinitionService())->load(),
        'unexpected module-id casing remains fail closed'
    );

    echo "Catalog adapter direct read tests passed\n";
}
