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

    $codes = [
        'CALC_PRESETS',
        'CALC_STAGES',
        'CALC_SETTINGS',
        'CALC_GLOBAL_VALUES',
        'CALC_CUSTOM_FIELDS',
        'CALC_MATERIALS',
        'CALC_MATERIALS_VARIANTS',
        'CALC_SUPPLIERS',
        'CALC_OPERATIONS',
        'CALC_OPERATIONS_VARIANTS',
        'CALC_EQUIPMENT',
        'CALC_DETAILS',
    ];
    $ids = [];
    $calculatorSnapshot = [
        'contract' => CatalogRuntimeConfigAuthorityService::CONTRACT,
        'prospektweb.calc:CALC_SERVER_URL' => 'https://pwrt.ru/calc-api',
    ];
    foreach ($codes as $index => $code) {
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
    $frontState = ['contract' => \Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::CONTRACT,
        'revision' => 3, 'fingerprint' => str_repeat('b', 64),
        'settings' => ['PRODUCTS_IBLOCK_ID' => '14', 'OFFERS_IBLOCK_ID' => '15']];
    $authority = new CatalogRuntimeConfigAuthorityService([
        'resolve_calculator_iblock' => static function (string $code) use (
            &$resolvedCalls,
            $ids
        ): int {
            $resolvedCalls[] = $code;
            return $ids[$code];
        },
        'front_settings_state' => static function () use (&$catalogCaptures, $frontState): array {
            $catalogCaptures++;
            return $frontState;
        },
        'capture_catalog' => static function (): array { throw new RuntimeException('Legacy graph must not be read.'); },
    ]);
    $manager = new ConfigManager(['runtime_config_authority' => $authority]);
    $assert($manager->getIblockId('CALC_PRESETS') === $ids['CALC_PRESETS'], 'exact calculator target is returned');
    $assert(
        $resolvedCalls === ['CALC_PRESETS'],
        'one ConfigManager read validates only the requested calculator target'
    );
    $assert($manager->getIblockId('CALC_DETAILS') === $ids['CALC_DETAILS'], 'a second target is resolved independently');
    $assert(
        $resolvedCalls === ['CALC_PRESETS', 'CALC_DETAILS'],
        'independently resolved calculator targets are cached by code'
    );
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
        'resolve_calculator_iblock' => static function (string $code): int {
            throw new RuntimeException('ambiguous target', 409);
        },
    ]);
    $expectConflict(
        static fn() => (new ConfigManager(['runtime_config_authority' => $brokenAuthority]))
            ->getIblockId('CALC_PRESETS'),
        'target authority conflict propagates without discovery or repair'
    );

    $unrelatedConflictAuthority = new CatalogRuntimeConfigAuthorityService([
        'resolve_calculator_iblock' => static function (string $code) use ($ids): int {
            if ($code === 'CALC_STAGES') {
                throw new RuntimeException('unrelated stage authority drift', 409);
            }
            return $ids[$code];
        },
    ]);
    $unrelatedConflictManager = new ConfigManager([
        'runtime_config_authority' => $unrelatedConflictAuthority,
    ]);
    $assert(
        $unrelatedConflictManager->getIblockId('CALC_PRESETS') === $ids['CALC_PRESETS'],
        'unrelated stage authority drift cannot block a preset-only admin request'
    );
    $expectConflict(
        static fn() => $unrelatedConflictManager->getIblockId('CALC_STAGES'),
        'stage authority drift still fails closed when stages are requested'
    );

    $badCatalog = $frontState;
    $badCatalog['settings']['PRODUCTS_IBLOCK_ID'] = '014';
    $expectConflict(
        static fn() => (new ConfigManager([
            'runtime_config_authority' => new CatalogRuntimeConfigAuthorityService([
                'front_settings_state' => static fn(): array => $badCatalog,
            ]),
        ]))->getProductIblockId(),
        'non-canonical Front product authority fails closed'
    );

    foreach (['missing_offers', 'same_catalog', 'inactive', 'bad_contract', 'bad_fingerprint', 'overflow'] as $case) {
        $bad = $frontState;
        if ($case === 'missing_offers') { unset($bad['settings']['OFFERS_IBLOCK_ID']); }
        if ($case === 'same_catalog') { $bad['settings']['OFFERS_IBLOCK_ID'] = '14'; }
        if ($case === 'inactive') { $bad['revision'] = 0; }
        if ($case === 'bad_contract') { $bad['contract'] = 'unknown'; }
        if ($case === 'bad_fingerprint') { $bad['fingerprint'] = ''; }
        if ($case === 'overflow') { $bad['settings']['PRODUCTS_IBLOCK_ID'] = '9999999999999999999999999'; }
        $expectConflict(static fn() => (new ConfigManager(['front_settings_state' => static fn() => $bad]))->getProductIblockId(), $case);
    }
    $expectConflict(static fn() => CatalogRuntimeConfigAuthorityService::normalizeCatalogSnapshot([
        'contract' => CatalogRuntimeConfigAuthorityService::CONTRACT,
    ]), 'legacy full snapshot remains strict');

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
            && !str_contains($source, 'setIblockId')
            && !str_contains($source, 'IBLOCK_TYPES'),
        'ConfigManager runtime contains no cache read, legacy mirror, type dependency, discovery, or writer'
    );

    $authoritySource = (string)file_get_contents(
        dirname(__DIR__) . '/lib/Services/CatalogRuntimeConfigAuthorityService.php'
    );
    $assert(
        !str_contains($authoritySource, 'SELECT ID, CODE, IBLOCK_TYPE_ID FROM b_iblock')
            && str_contains($authoritySource, 'SELECT ID, CODE FROM b_iblock'),
        'runtime calculator identity is configured ID plus exact code, independent of Bitrix admin grouping'
    );

    fwrite(STDOUT, "ConfigManager exact authority tests passed\n");
}
