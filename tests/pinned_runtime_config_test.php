<?php

declare(strict_types=1);

namespace Prospektweb\Frontcalc\Service {
    if (!class_exists(FrontcalcSettingsAuthority::class, false)) {
        final class FrontcalcSettingsAuthority
        {
            public const CONTRACT = 'prospektweb.frontcalc.settings/v1';
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/lib/Calculator/InitPayloadService.php';
    require_once dirname(__DIR__) . '/lib/Services/CatalogCalculationWriteService.php';

    use Prospektweb\Calc\Calculator\InitPayloadService;
    use Prospektweb\Calc\Services\CatalogCalculationWriteService;
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
    $calculatorSnapshot = [
        'contract' => CatalogRuntimeConfigAuthorityService::CONTRACT,
        'prospektweb.calc:CALC_SERVER_URL' => 'https://pwrt.ru/calc-api',
    ];
    $expectedCalculator = [];
    foreach ($codes as $index => $code) {
        $id = 300 + $index;
        $calculatorSnapshot['prospektweb.calc:IBLOCK_' . $code] = (string)$id;
        $expectedCalculator[$code] = $id;
    }
    $catalogSnapshot = $calculatorSnapshot + [
        'frontSettingsContract' => \Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::CONTRACT,
        'frontSettingsRevision' => '7',
        'frontSettingsFingerprint' => str_repeat('a', 64),
        'prospektweb.frontcalc:PRODUCTS_IBLOCK_ID' => '214',
        'prospektweb.frontcalc:OFFERS_IBLOCK_ID' => '215',
    ];
    $expectedCatalog = ['PRODUCTS' => 214, 'OFFERS' => 215] + $expectedCalculator;

    $assert(
        CatalogRuntimeConfigAuthorityService::runtimeIblockMap($calculatorSnapshot)
            === $expectedCalculator,
        'standalone calculator snapshot contains no catalog dependency'
    );
    $assert(
        CatalogRuntimeConfigAuthorityService::runtimeIblockMap($catalogSnapshot) === $expectedCatalog,
        'catalog snapshot combines one Front aggregate with Admin calculator sources'
    );

    $legacyAdmin = $catalogSnapshot;
    $legacyAdmin['prospektweb.calc:PRODUCT_IBLOCK_ID'] = '999';
    $expectConflict(
        static fn() => CatalogRuntimeConfigAuthorityService::runtimeIblockMap($legacyAdmin),
        'legacy Admin product mirror is outside the exact snapshot contract'
    );
    $legacyFront = $catalogSnapshot;
    $legacyFront['prospektweb.frontcalc:IBLOCK_CALC_GLOBAL_VALUES'] = '999';
    $expectConflict(
        static fn() => CatalogRuntimeConfigAuthorityService::runtimeIblockMap($legacyFront),
        'legacy Front calculator mirror is outside the exact snapshot contract'
    );
    $badRevision = $catalogSnapshot;
    $badRevision['frontSettingsRevision'] = '0';
    $expectConflict(
        static fn() => CatalogRuntimeConfigAuthorityService::runtimeIblockMap($badRevision),
        'unactivated Front settings aggregate fails closed'
    );
    $badId = $catalogSnapshot;
    $badId['prospektweb.frontcalc:OFFERS_IBLOCK_ID'] = '0215';
    $expectConflict(
        static fn() => CatalogRuntimeConfigAuthorityService::runtimeIblockMap($badId),
        'non-canonical catalog ID fails closed'
    );

    $init = new InitPayloadService();
    $reflection = new ReflectionClass($init);
    $pinnedProperty = $reflection->getProperty('pinnedRuntimeIblockIds');
    $pinnedProperty->setAccessible(true);
    $pinnedProperty->setValue($init, $expectedCatalog);
    $runtimeIblockId = $reflection->getMethod('runtimeIblockId');
    $runtimeIblockId->setAccessible(true);
    foreach ($expectedCatalog as $code => $id) {
        $assert(
            $runtimeIblockId->invoke($init, $code) === $id,
            'pinned runtime source ' . $code . ' never falls back to another authority'
        );
    }
    $resolveGlobals = $reflection->getMethod('resolvePinnedGlobalSymbolIblockId');
    $resolveGlobals->setAccessible(true);
    $assert(
        $resolveGlobals->invoke($init, $catalogSnapshot) === $expectedCalculator['CALC_GLOBAL_VALUES'],
        'global registry is owned only by the Admin calculator snapshot'
    );

    $catalogWriter = new CatalogCalculationWriteService();
    $writerReflection = new ReflectionClass($catalogWriter);
    $effective = $writerReflection->getMethod('effectiveRuntimeConfigIblockId');
    $effective->setAccessible(true);
    $assert(
        $effective->invoke($catalogWriter, $catalogSnapshot, 'PRODUCTS') === 214
            && $effective->invoke($catalogWriter, $catalogSnapshot, 'CALC_GLOBAL_VALUES')
                === $expectedCalculator['CALC_GLOBAL_VALUES'],
        'catalog writer consumes the same strict cross-module snapshot'
    );

    $initSource = (string)file_get_contents(dirname(__DIR__) . '/lib/Calculator/InitPayloadService.php');
    $writerSource = (string)file_get_contents(
        dirname(__DIR__) . '/lib/Services/CatalogCalculationWriteService.php'
    );
    $batchSource = (string)file_get_contents(
        dirname(__DIR__) . '/lib/Services/BatchRecalculateService.php'
    );
    foreach ([$initSource, $writerSource, $batchSource] as $source) {
        $assert(
            !str_contains($source, 'prospektweb.calc:PRODUCT_IBLOCK_ID')
                && !str_contains($source, 'prospektweb.calc:SKU_IBLOCK_ID')
                && !str_contains($source, 'prospektweb.frontcalc:IBLOCK_CALC_'),
            'runtime consumer source contains no legacy catalog or calculator mirrors'
        );
    }
    $assert(
        str_contains($initSource, '$offerIds === []')
            && str_contains($initSource, 'captureCalculatorSnapshot()')
            && str_contains($initSource, 'captureCatalogSnapshot()'),
        'manual and catalog INIT select the appropriate exact authority boundary'
    );

    echo "Pinned runtime config tests passed\n";
}
