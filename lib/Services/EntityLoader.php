<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Сервис для загрузки сущностей из инфоблоков.
 */
class EntityLoader
{
    /** @var ConfigManager */
    protected ConfigManager $configManager;

    public function __construct()
    {
        $this->configManager = new ConfigManager();
    }

    /**
     * Фильтрует массив ID, оставляя только положительные уникальные целые числа.
     *
     * @param array $ids Массив ID для фильтрации.
     *
     * @return int[]
     */
    public function filterIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
    }

    /**
     * Загружает элементы инфоблока.
     *
     * @param int|null $iblockId ID инфоблока.
     * @param int[]    $ids      Массив ID элементов.
     *
     * @return array
     */
    public function loadElements(?int $iblockId, array $ids): array
    {
        if (!Loader::includeModule('iblock')) {
            return [];
        }

        $ids = $this->filterIds($ids);

        if (empty($ids)) {
            return [];
        }

        $filter = ['ID' => $ids];

        if ($iblockId !== null && $iblockId > 0) {
            $filter['IBLOCK_ID'] = $iblockId;
        }

        $result = [];

        $res = \CIBlockElement::GetList(
            [],
            $filter,
            false,
            false,
            ['*', 'TIMESTAMP_X', 'MODIFIED_BY']
        );

        while ($element = $res->GetNextElement()) {
            $fields = $element->GetFields();
            $props = [];

            $rawProps = $element->GetProperties();
            foreach ($rawProps as $code => $property) {
                $props[$code] = $property;
            }

            $id = (int)$fields['ID'];
            $result[$id] = [
                'FIELDS' => $fields,
                'META' => [
                    'timestampX' => $fields['TIMESTAMP_X'] ?? null,
                    'modifiedBy' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
                    'timestamp_x' => $fields['TIMESTAMP_X'] ?? null,
                    'modified_by' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
                ],
                'PROPERTIES' => $props,
                'PRICES' => [],
            ];
        }

        return $result;
    }

    /**
     * Загружает цены товаров.
     *
     * @param int[] $productIds Массив ID товаров.
     *
     * @return array
     */
    public function loadPrices(array $productIds): array
    {
        if (!Loader::includeModule('catalog')) {
            return [];
        }

        $productIds = $this->filterIds($productIds);

        if (empty($productIds)) {
            return [];
        }

        $index = [];

        $priceRes = \CPrice::GetList(
            [],
            ['PRODUCT_ID' => $productIds]
        );

        while ($price = $priceRes->Fetch()) {
            $productId = (int)$price['PRODUCT_ID'];
            $index[$productId][] = [
                'CATALOG_GROUP_ID' => isset($price['CATALOG_GROUP_ID']) ? (int)$price['CATALOG_GROUP_ID'] : null,
                'PRICE' => isset($price['PRICE']) ? (float)$price['PRICE'] : null,
                'CURRENCY' => isset($price['CURRENCY']) ? (string)$price['CURRENCY'] : null,
                'QUANTITY_FROM' => $price['QUANTITY_FROM'] !== null && $price['QUANTITY_FROM'] !== ''
                    ? (int)$price['QUANTITY_FROM']
                    : null,
                'QUANTITY_TO' => $price['QUANTITY_TO'] !== null && $price['QUANTITY_TO'] !== ''
                    ? (int)$price['QUANTITY_TO']
                    : null,
            ];
        }

        return $index;
    }

    /**
     * Загружает карту SKU -> родитель.
     *
     * @param int[] $offerIds Массив ID предложений.
     *
     * @return array
     */
    public function loadSkuParentMap(array $offerIds): array
    {
        if (!Loader::includeModule('catalog')) {
            return [];
        }

        $offerIds = $this->filterIds($offerIds);

        if (empty($offerIds)) {
            return [];
        }

        $map = \CCatalogSKU::getProductList($offerIds);
        $map = is_array($map) ? $map : [];

        return $map;
    }

    /**
     * Загружает данные каталога товара.
     *
     * @param int $productId ID товара.
     *
     * @return array|null
     */
    public function loadCatalogData(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        if (!Loader::includeModule('catalog')) {
            return null;
        }

        $product = \CCatalogProduct::GetByID($productId);

        if (!is_array($product)) {
            return null;
        }

        $productData = [
            'ID' => isset($product['ID']) ? (int)$product['ID'] : $productId,
            'WEIGHT' => isset($product['WEIGHT']) ? (float)$product['WEIGHT'] : null,
            'WIDTH' => isset($product['WIDTH']) ? (float)$product['WIDTH'] : null,
            'LENGTH' => isset($product['LENGTH']) ? (float)$product['LENGTH'] : null,
            'HEIGHT' => isset($product['HEIGHT']) ? (float)$product['HEIGHT'] : null,
            'MEASURE_ID' => isset($product['MEASURE']) ? (int)$product['MEASURE'] : null,
            'PURCHASING_PRICE' => isset($product['PURCHASING_PRICE']) ? (float)$product['PURCHASING_PRICE'] : null,
            'PURCHASING_CURRENCY' => $product['PURCHASING_CURRENCY'] ?? null,
        ];

        $result = [
            'PRODUCT' => $productData,
        ];

        if (!empty($productData['MEASURE_ID'])) {
            $measureRes = \CCatalogMeasure::getList(
                [],
                ['ID' => $productData['MEASURE_ID']],
                false,
                false,
                ['ID', 'CODE', 'MEASURE_TITLE', 'SYMBOL']
            );

            if ($measure = $measureRes->Fetch()) {
                $result['MEASURE'] = [
                    'ID' => isset($measure['ID']) ? (int)$measure['ID'] : null,
                    'CODE' => $measure['CODE'] ?? null,
                    'MEASURE' => $measure['MEASURE_TITLE'] ?? null,
                    'SYMBOL' => $measure['SYMBOL'] ?? null,
                ];
            }
        }

        return $result;
    }

    /**
     * Присоединяет цены и данные каталога к элементам.
     *
     * @param array $elements   Массив элементов.
     * @param array $priceIndex Индекс цен.
     * @param int[] $ids        Массив ID.
     *
     * @return array
     */
    public function attachPrices(array $elements, array $priceIndex, array $ids): array
    {
        $result = [];

        foreach ($ids as $id) {
            $result[$id] = [
                'FIELDS' => $elements[$id]['FIELDS'] ?? [],
                'META' => $elements[$id]['META'] ?? [
                    'timestampX' => null,
                    'modifiedBy' => null,
                    'timestamp_x' => null,
                    'modified_by' => null,
                ],
                'PROPERTIES' => $elements[$id]['PROPERTIES'] ?? [],
                'PRICES' => $priceIndex[$id] ?? [],
                'CATALOG' => $this->loadCatalogData((int)$id),
            ];
        }

        return $result;
    }

    /**
     * Загружает данные вариантов продуктов и их родителей.
     *
     * @param int[]    $offerIds  ID вариантов продуктов.
     * @param int|null $iblockId  ID инфоблока.
     *
     * @return array
     */
    public function loadProductVariantsData(array $offerIds, ?int $iblockId = null): array
    {
        $offerIds = $this->filterIds($offerIds);

        if (empty($offerIds)) {
            return [
                'variants' => [],
                'products' => [],
                'skuMap' => [],
            ];
        }

        $skuMap = $this->loadSkuParentMap($offerIds);
        $parentIds = $this->filterIds(array_column($skuMap, 'ID'));

        $allIds = array_values(array_unique(array_merge($offerIds, $parentIds)));

        $priceIndex = $this->loadPrices($allIds);
        $offersElements = $this->loadElements($iblockId, $offerIds);
        $parentsElements = $this->loadElements(null, $parentIds);

        $variantsData = $this->attachPrices($offersElements, $priceIndex, $offerIds);
        $productsData = $this->attachPrices($parentsElements, $priceIndex, $parentIds);

        // Добавляем PARENT_ID к вариантам
        foreach ($offerIds as $offerId) {
            if (!array_key_exists($offerId, $variantsData)) {
                $variantsData[$offerId] = [
                    'FIELDS' => [],
                    'META' => [
                        'timestampX' => null,
                        'modifiedBy' => null,
                        'timestamp_x' => null,
                        'modified_by' => null,
                    ],
                    'PROPERTIES' => [],
                    'PRICES' => [],
                ];
            }

            $parentId = isset($skuMap[$offerId]['ID']) ? (int)$skuMap[$offerId]['ID'] : 0;
            $variantsData[$offerId]['PARENT_ID'] = $parentId > 0 ? $parentId : null;
        }

        return [
            'variants' => $variantsData,
            'products' => $productsData,
            'skuMap' => $skuMap,
        ];
    }

    /**
     * Загружает оборудование для операции.
     *
     * @param array $operationsData Данные операций.
     *
     * @return int[]
     */
    public function collectEquipmentIdsFromOperations(array $operationsData): array
    {
        $ids = [];

        foreach ($operationsData as $operation) {
            $values = $operation['PROPERTIES']['EQUIPMENTS']['VALUE'] ?? [];

            if (!is_array($values)) {
                $values = [$values];
            }

            foreach ($values as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return $this->filterIds($ids);
    }
}
