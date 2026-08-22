<?php

declare(strict_types=1);

namespace Bitrix\Main\Config {
    final class Option
    {
        /** @var array<string,string> */
        public static array $values = [];
        public static int $setCalls = 0;

        public static function get(string $moduleId, string $name, $default = null)
        {
            return self::$values[$moduleId . ':' . $name] ?? $default;
        }

        public static function set(string $moduleId, string $name, $value): void
        {
            self::$setCalls++;
            self::$values[$moduleId . ':' . $name] = (string)$value;
        }
    }
}

namespace Bitrix\Main {
    final class Loader
    {
        public static bool $available = true;

        public static function includeModule(string $moduleId): bool
        {
            return $moduleId === 'iblock' && self::$available;
        }
    }
}

namespace {
    final class ConfigManagerAuthorityCursor
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
        public function Fetch()
        {
            return $this->rows[$this->offset++] ?? false;
        }
    }

    final class CIBlock
    {
        /** @var array<int,array<string,mixed>> */
        public static array $rows = [];

        public static function GetList(array $order, array $filter): ConfigManagerAuthorityCursor
        {
            $rows = array_values(array_filter(
                self::$rows,
                static fn(array $row): bool => (string)($row['CODE'] ?? '') === (string)($filter['CODE'] ?? '')
                    && (string)($row['IBLOCK_TYPE_ID'] ?? '') === (string)($filter['TYPE'] ?? '')
            ));
            usort($rows, static fn(array $left, array $right): int => (int)$left['ID'] <=> (int)$right['ID']);
            return new ConfigManagerAuthorityCursor($rows);
        }
    }

    require_once dirname(__DIR__) . '/lib/Config/ConfigManager.php';

    use Bitrix\Main\Config\Option;
    use Bitrix\Main\Loader;
    use Prospektweb\Calc\Config\ConfigManager;

    $assert = static function (bool $condition, string $message): void {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };
    $expectConflict = static function (callable $callback, string $message) use ($assert): void {
        try {
            $callback();
            $assert(false, $message);
        } catch (\RuntimeException $error) {
            $assert($error->getCode() === 409, $message . ' (expected conflict status)');
        }
    };

    $optionKey = 'prospektweb.calc:IBLOCK_CALC_PRESETS';
    Option::$values = [$optionKey => '41'];
    CIBlock::$rows = [[
        'ID' => 41,
        'CODE' => 'CALC_PRESETS',
        'IBLOCK_TYPE_ID' => 'calculator',
    ]];
    $manager = new ConfigManager();
    $assert($manager->getIblockId('CALC_PRESETS') === 41, 'exact configured authority is returned');
    $assert(Option::$setCalls === 0, 'authority reads never mutate module options');

    Option::$values = [];
    $expectConflict(
        static fn() => $manager->getIblockId('CALC_PRESETS'),
        'missing authority fails closed instead of discovering by code'
    );
    $assert(Option::$setCalls === 0, 'missing authority is not repaired by a read');

    foreach (['0', '041', '41x', '-41'] as $invalidId) {
        Option::$values = [$optionKey => $invalidId];
        $expectConflict(
            static fn() => $manager->getIblockId('CALC_PRESETS'),
            'non-canonical authority ' . $invalidId . ' fails closed'
        );
    }

    Option::$values = [$optionKey => '42'];
    $expectConflict(
        static fn() => $manager->getIblockId('CALC_PRESETS'),
        'configured ID must match the exact code/type target'
    );

    Option::$values = [$optionKey => '41'];
    CIBlock::$rows[] = [
        'ID' => 42,
        'CODE' => 'CALC_PRESETS',
        'IBLOCK_TYPE_ID' => 'calculator',
    ];
    $expectConflict(
        static fn() => $manager->getIblockId('CALC_PRESETS'),
        'duplicate code/type authority is ambiguous'
    );

    CIBlock::$rows = [[
        'ID' => 41,
        'CODE' => 'CALC_PRESETS',
        'IBLOCK_TYPE_ID' => 'calculator_catalog',
    ]];
    $expectConflict(
        static fn() => $manager->getIblockId('CALC_PRESETS'),
        'wrong iblock type fails exact readback'
    );

    Loader::$available = false;
    CIBlock::$rows = [[
        'ID' => 41,
        'CODE' => 'CALC_PRESETS',
        'IBLOCK_TYPE_ID' => 'calculator',
    ]];
    $expectConflict(
        static fn() => $manager->getIblockId('CALC_PRESETS'),
        'missing iblock module fails closed'
    );
    Loader::$available = true;

    try {
        $manager->getIblockId('UNKNOWN');
        $assert(false, 'unknown authority code must be rejected');
    } catch (\InvalidArgumentException $error) {
        $assert(true, 'unknown authority code rejected');
    }

    $source = (string)file_get_contents(dirname(__DIR__) . '/lib/Config/ConfigManager.php');
    $getStart = strpos($source, 'public function getIblockId');
    $nextMethod = strpos($source, 'public function getProductIblockId', $getStart ?: 0);
    $getBody = substr($source, $getStart, $nextMethod - $getStart);
    $assert(strpos($getBody, 'Option::set') === false, 'getIblockId contains no option write');
    $assert(strpos($source, 'findIblockId') === false, 'first-match discovery is absent from runtime configuration');
    $assert(strpos($source, 'setIblockId') === false, 'runtime configuration exposes no obsolete direct iblock writer');

    fwrite(STDOUT, "ConfigManager exact authority tests passed\n");
}
