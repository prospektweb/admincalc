<?php

declare(strict_types=1);

namespace Bitrix\Main\Config {
    final class Option
    {
        public static int $getCalls = 0;
        public static int $setCalls = 0;

        public static function get(string $moduleId, string $name, $default = null)
        {
            self::$getCalls++;
            return $default;
        }

        public static function set(string $moduleId, string $name, $value): void
        {
            self::$setCalls++;
        }
    }
}

namespace Bitrix\Main {
    final class Application
    {
        /** @var object|null */
        public static $connection;

        public static function getConnection()
        {
            return self::$connection;
        }
    }
}

namespace Prospektweb\Frontcalc\Service {
    if (!class_exists(FrontcalcSettingsAuthority::class, false)) {
        final class FrontcalcSettingsAuthority
        {
            public const CONTRACT = 'prospektweb.frontcalc.settings/v1';
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/lib/Config/ConfigManager.php';

    use Bitrix\Main\Application;
    use Bitrix\Main\Config\Option;
    use Prospektweb\Calc\Config\ConfigManager;
    use Prospektweb\Calc\Services\CatalogRuntimeConfigAuthorityService;

    $assert = static function (bool $condition, string $message): void {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };
    $expectConflict = static function (callable $callback, string $message) use ($assert): void {
        try {
            $callback();
        } catch (RuntimeException $error) {
            $assert($error->getCode() === 409, $message . ' has conflict status');
            return;
        }
        $assert(false, $message);
    };

    $types = [
        'CALC_PRESETS' => 'calculator',
        'CALC_STAGES' => 'calculator_catalog',
        'CALC_SETTINGS' => 'calculator',
        'CALC_GLOBAL_VALUES' => 'calculator',
        'CALC_CUSTOM_FIELDS' => 'calculator',
        'CALC_MATERIALS' => 'calculator_catalog',
        'CALC_MATERIALS_VARIANTS' => 'calculator_catalog',
        'CALC_OPERATIONS' => 'calculator_catalog',
        'CALC_OPERATIONS_VARIANTS' => 'calculator_catalog',
        'CALC_EQUIPMENT' => 'calculator_catalog',
        'CALC_DETAILS' => 'calculator_catalog',
    ];
    $ids = [];
    $calculatorSnapshot = [
        'contract' => CatalogRuntimeConfigAuthorityService::CONTRACT,
        'prospektweb.calc:CALC_SERVER_URL' => 'https://pwrt.ru/calc-api',
    ];
    foreach (array_keys($types) as $index => $code) {
        $ids[$code] = 41 + $index;
        $calculatorSnapshot['prospektweb.calc:IBLOCK_' . $code] = (string)$ids[$code];
    }
    $catalogSnapshot = $calculatorSnapshot + [
        'frontSettingsContract' => \Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::CONTRACT,
        'frontSettingsRevision' => '3',
        'frontSettingsFingerprint' => str_repeat('b', 64),
        'prospektweb.frontcalc:PRODUCTS_IBLOCK_ID' => '14',
        'prospektweb.frontcalc:OFFERS_IBLOCK_ID' => '15',
    ];

    $resolvedCalls = [];
    $catalogCaptures = 0;
    $authority = new CatalogRuntimeConfigAuthorityService([
        'resolve_calculator_iblock' => static function (string $code, string $type) use (
            &$resolvedCalls,
            $types,
            $ids
        ): int {
            $resolvedCalls[$code] = $type;
            if (($types[$code] ?? null) !== $type) {
                throw new RuntimeException('wrong type', 409);
            }
            return $ids[$code];
        },
        'capture_catalog' => static function () use (&$catalogCaptures, $catalogSnapshot): array {
            $catalogCaptures++;
            return $catalogSnapshot;
        },
    ]);
    $manager = new ConfigManager(['runtime_config_authority' => $authority]);
    $assert($manager->getIblockId('CALC_PRESETS') === $ids['CALC_PRESETS'], 'exact calculator target is returned');
    $assert($resolvedCalls === $types, 'one ConfigManager read validates the complete calculator aggregate');
    $assert($manager->getIblockId('CALC_DETAILS') === $ids['CALC_DETAILS'], 'validated calculator targets are cached together');
    $assert($manager->getProductIblockId() === 14, 'products source comes only from the Front aggregate');
    $assert($manager->getSkuIblockId() === 15, 'offers source comes only from the Front aggregate');
    $assert($catalogCaptures === 1, 'product and offer IDs share one Front settings snapshot');
    $assert(Option::$getCalls === 0 && Option::$setCalls === 0, 'runtime authority bypasses the Bitrix Option cache');

    try {
        $manager->getIblockId('UNKNOWN');
        $assert(false, 'unknown calculator source is rejected');
    } catch (InvalidArgumentException $error) {
        $assert(true, 'unknown calculator source rejected');
    }

    $brokenAuthority = new CatalogRuntimeConfigAuthorityService([
        'resolve_calculator_iblock' => static function (string $code, string $type): int {
            throw new RuntimeException('ambiguous target', 409);
        },
    ]);
    $expectConflict(
        static fn() => (new ConfigManager(['runtime_config_authority' => $brokenAuthority]))
            ->getIblockId('CALC_PRESETS'),
        'target authority conflict propagates without discovery or repair'
    );

    $badCatalog = $catalogSnapshot;
    $badCatalog['prospektweb.frontcalc:PRODUCTS_IBLOCK_ID'] = '014';
    $expectConflict(
        static fn() => (new ConfigManager([
            'runtime_config_authority' => new CatalogRuntimeConfigAuthorityService([
                'capture_catalog' => static fn(): array => $badCatalog,
            ]),
        ]))->getProductIblockId(),
        'non-canonical Front product authority fails closed'
    );

    $source = (string)file_get_contents(dirname(__DIR__) . '/lib/Config/ConfigManager.php');
    $getIblockStart = strpos($source, 'public function getIblockId');
    $genericOptionStart = strpos($source, 'public function getOption', $getIblockStart ?: 0);
    $runtimeBody = substr(
        $source,
        $getIblockStart ?: 0,
        ($genericOptionStart ?: strlen($source)) - ($getIblockStart ?: 0)
    );
    $assert(
        !str_contains($runtimeBody, 'Option::get')
            && !str_contains($runtimeBody, 'PRODUCT_IBLOCK_ID')
            && !str_contains($runtimeBody, 'SKU_IBLOCK_ID')
            && !str_contains($source, 'findIblockId')
            && !str_contains($source, 'setIblockId'),
        'ConfigManager runtime contains no cache read, legacy mirror, discovery, or writer'
    );

    fwrite(STDOUT, "ConfigManager exact authority tests passed\n");
}
