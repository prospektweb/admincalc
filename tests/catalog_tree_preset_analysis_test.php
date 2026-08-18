<?php

declare(strict_types=1);

namespace Bitrix\Main {
    final class Loader
    {
        public static function includeModule(string $moduleId): bool
        {
            return $moduleId === 'iblock';
        }
    }
}

namespace Prospektweb\Calc\Config {
    final class ConfigManager
    {
        public function getIblockId(string $code): int
        {
            return $code === 'CALC_PRESETS' ? 41 : 0;
        }

        public function getProductIblockId(): int
        {
            return 14;
        }

        public function getSkuIblockId(): int
        {
            return 15;
        }
    }
}

namespace Prospektweb\Calc\Services {
    final class CatalogAdapterDefinitionService
    {
        public const PRESET_ID = 12740;

        /** @return int[] */
        public function supportedProductIds(): array
        {
            // Persisted adapter intentionally lost product 14380. Authoring
            // must still expose it so the missing profile can be repaired.
            return [12727];
        }
    }

    final class StandaloneCatalogSelectionMapper
    {
        /** @return int[] */
        public static function supportedProductIds(): array
        {
            return [12727, 14380];
        }
    }
}

namespace {
    use Prospektweb\Calc\Services\BatchRecalculateService;
    use Prospektweb\Calc\Services\ControlCenterEditorsService;

    final class CatalogTreePresetCursor
    {
        /** @var array<int,array<string,mixed>> */
        private array $rows;

        /** @param array<int,array<string,mixed>> $rows */
        public function __construct(array $rows)
        {
            $this->rows = array_values($rows);
        }

        /** @return array<string,mixed>|false */
        public function Fetch()
        {
            return array_shift($this->rows) ?? false;
        }
    }

    final class CIBlockElement
    {
        /** @var array<int,array<string,mixed>> */
        public static array $filters = [];

        /**
         * @param array<string,mixed> $order
         * @param array<string,mixed> $filter
         * @param array<int,string> $select
         */
        public static function GetList(
            array $order,
            array $filter,
            $groupBy = false,
            $navigation = false,
            array $select = []
        ): CatalogTreePresetCursor {
            unset($order, $groupBy, $navigation, $select);
            self::$filters[] = $filter;
            $iblockId = (int)($filter['IBLOCK_ID'] ?? 0);
            if ($iblockId === 41) {
                return new CatalogTreePresetCursor([
                    ['ID' => 12740, 'NAME' => 'Листовая печать'],
                ]);
            }
            if ($iblockId === 14) {
                $rows = [
                    ['ID' => 12727, 'NAME' => 'Стандартные визитки'],
                    ['ID' => 14380, 'NAME' => 'Персональные визитки'],
                    ['ID' => 99999, 'NAME' => 'Вне адаптера'],
                ];
                $allowedIds = array_map('intval', (array)($filter['ID'] ?? []));
                if ($allowedIds !== []) {
                    $rows = array_values(array_filter(
                        $rows,
                        static fn(array $row): bool => in_array((int)$row['ID'], $allowedIds, true)
                    ));
                }
                return new CatalogTreePresetCursor($rows);
            }
            if ($iblockId === 15) {
                $productId = (int)($filter['PROPERTY_CML2_LINK'] ?? 0);
                $rows = $productId === 12727
                    ? [
                        ['ID' => 15320, 'NAME' => '100 шт. 4+0'],
                        ['ID' => 15321, 'NAME' => '100 шт. 4+4'],
                    ]
                    : [
                        ['ID' => 15332, 'NAME' => '100 шт. 4+0'],
                    ];
                return new CatalogTreePresetCursor($rows);
            }
            return new CatalogTreePresetCursor([]);
        }
    }

    final class CIBlock
    {
        public static function GetByID(int $iblockId): CatalogTreePresetCursor
        {
            return new CatalogTreePresetCursor($iblockId === 14
                ? [['IBLOCK_TYPE_ID' => 'catalog']]
                : []);
        }
    }

    if (!defined('LANGUAGE_ID')) {
        define('LANGUAGE_ID', 'ru');
    }

    $USER = new class {
        public function IsAuthorized(): bool
        {
            return true;
        }

        public function IsAdmin(): bool
        {
            return true;
        }
    };

    $assert = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    };

    require_once dirname(__DIR__) . '/lib/Services/BatchRecalculateService.php';
    $constructorRejectedDummyUrl = false;
    try {
        new BatchRecalculateService('');
    } catch (\InvalidArgumentException $exception) {
        $constructorRejectedDummyUrl = $exception->getMessage() === 'CALC_SERVER_URL is not a valid URL.';
    }
    $assert($constructorRejectedDummyUrl, 'Batch service constructor must reject an empty calc-server URL');

    $catalogTreeSource = file_get_contents(dirname(__DIR__) . '/lib/Services/CatalogTreeService.php');
    $assert(
        is_string($catalogTreeSource) && !str_contains($catalogTreeSource, 'BatchRecalculateService'),
        'Read-only preset analysis must not depend on the network-backed batch service'
    );

    require_once dirname(__DIR__) . '/lib/Services/CatalogTreeService.php';
    require_once dirname(__DIR__) . '/lib/Services/ControlCenterEditorsService.php';

    $controlCenter = new ControlCenterEditorsService(
        null,
        static fn(): int => 14,
        static fn(): bool => false
    );
    $catalog = $controlCenter->getCatalog();
    $calculation = (array)(($catalog['calculations'] ?? [])[0] ?? []);
    $products = (array)($catalog['storefront']['products'] ?? []);

    $assert(($calculation['presetId'] ?? 0) === 12740, 'Control center must load preset 12740');
    $assert(
        array_column($products, 'id') === [12727, 14380, 99999],
        'Storefront authoring must use every preset-linked product even when the catalog-write adapter is narrower'
    );
    $assert(!isset($calculation['products']), 'Registry summaries must not embed product or offer rows');
    $assert(($calculation['offerCount'] ?? 0) === 0, 'Registry counts remain independent from lazy storefront detail in this fixture');
    $assert(
        str_contains((string)($products[0]['name'] ?? ''), 'Стандартные'),
        'Preset analysis must preserve product names'
    );
    foreach (CIBlockElement::$filters as $filter) {
        $iblockId = (int)($filter['IBLOCK_ID'] ?? 0);
        if ($iblockId === 14 || $iblockId === 15) {
            $assert(($filter['ACTIVE_DATE'] ?? null) === 'Y', 'Preset analysis must reject date-inactive catalog rows');
        }
    }

    echo "Catalog tree preset analysis: OK\n";
}
