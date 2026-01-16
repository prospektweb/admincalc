<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;

/**
 * Универсальный сервис для работы с ценами товаров Bitrix Catalog.
 * Работает с любыми элементами инфоблоков-каталогов через CPrice API.
 * ID инфоблоков НЕ нужны - цены привязаны к PRODUCT_ID в таблице b_catalog_price.
 */
class CatalogPriceService
{
    public function __construct()
    {
        if (!Loader::includeModule('catalog')) {
            throw new \RuntimeException('Требуется модуль Bitrix catalog');
        }
    }

    /**
     * Записать одну цену
     *
     * @param int $productId ID товара
     * @param int $priceTypeId ID типа цены
     * @param float $price Цена
     * @param string $currency Валюта
     * @param int|null $quantityFrom Количество от
     * @param int|null $quantityTo Количество до
     * @return bool
     */
    public function writePrice(
        int $productId,
        int $priceTypeId,
        float $price,
        string $currency = 'RUB',
        ?int $quantityFrom = null,
        ?int $quantityTo = null
    ): bool {
        if ($productId <= 0 || $priceTypeId <= 0 || $price < 0) {
            return false;
        }

        // Ищем существующую цену
        $filter = [
            'PRODUCT_ID' => $productId,
            'CATALOG_GROUP_ID' => $priceTypeId,
        ];

        if ($quantityFrom !== null) {
            $filter['QUANTITY_FROM'] = $quantityFrom;
        }
        if ($quantityTo !== null) {
            $filter['QUANTITY_TO'] = $quantityTo;
        }

        $priceRes = \CPrice::GetList([], $filter);

        if ($arPrice = $priceRes->Fetch()) {
            // Обновляем существующую цену
            return (bool)\CPrice::Update($arPrice['ID'], [
                'PRICE' => $price,
                'CURRENCY' => $currency,
            ]);
        } else {
            // Создаём новую цену
            $params = [
                'PRODUCT_ID' => $productId,
                'CATALOG_GROUP_ID' => $priceTypeId,
                'PRICE' => $price,
                'CURRENCY' => $currency,
            ];

            if ($quantityFrom !== null) {
                $params['QUANTITY_FROM'] = $quantityFrom;
            }
            if ($quantityTo !== null) {
                $params['QUANTITY_TO'] = $quantityTo;
            }

            return (bool)\CPrice::Add($params);
        }
    }

    /**
     * Записать диапазоны цен для одного типа цены
     *
     * @param int $productId ID товара
     * @param int $priceTypeId ID типа цены
     * @param array $ranges Массив диапазонов [{from, to, value}]
     * @param string $currency Валюта (одна для всех диапазонов)
     * @return bool
     */
    public function writePriceRanges(
        int $productId,
        int $priceTypeId,
        array $ranges,
        string $currency = 'RUB'
    ): bool {
        if ($productId <= 0 || $priceTypeId <= 0 || empty($ranges)) {
            return false;
        }

        // Удаляем существующие цены для этого типа
        $this->deletePricesByType($productId, $priceTypeId);

        // Добавляем новые цены
        $success = true;

        foreach ($ranges as $range) {
            if (!isset($range['value'])) {
                continue;
            }

            $params = [
                'PRODUCT_ID' => $productId,
                'CATALOG_GROUP_ID' => $priceTypeId,
                'PRICE' => (float)$range['value'],
                'CURRENCY' => $currency,
                'QUANTITY_FROM' => $range['from'] ?? false,
                'QUANTITY_TO' => $range['to'] ?? false,
            ];

            $result = \CPrice::Add($params);
            if (!$result) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Записать диапазоны цен для нескольких типов цен.
     * Каждый диапазон может иметь свою валюту.
     *
     * @param int $productId ID товара
     * @param array $pricesByType Массив [typeId => [{price, currency, quantityFrom, quantityTo}]]
     * @return bool
     */
    public function writePriceRangesMultiType(int $productId, array $pricesByType): bool
    {
        if ($productId <= 0 || empty($pricesByType)) {
            return false;
        }

        $success = true;

        foreach ($pricesByType as $typeId => $ranges) {
            foreach ($ranges as $range) {
                $params = [
                    'PRODUCT_ID' => $productId,
                    'CATALOG_GROUP_ID' => (int)$typeId,
                    'PRICE' => (float)($range['price'] ?? 0),
                    'CURRENCY' => $range['currency'] ?? 'RUB',
                    'QUANTITY_FROM' => $range['quantityFrom'] ?? false,
                    'QUANTITY_TO' => $range['quantityTo'] ?? false,
                ];

                $result = \CPrice::Add($params);
                if (!$result) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    /**
     * Удалить цены товара для указанного типа цены
     *
     * @param int $productId ID товара
     * @param int $priceTypeId ID типа цены
     * @return bool
     */
    public function deletePricesByType(int $productId, int $priceTypeId): bool
    {
        $priceRes = \CPrice::GetList(
            [],
            [
                'PRODUCT_ID' => $productId,
                'CATALOG_GROUP_ID' => $priceTypeId,
            ]
        );

        while ($row = $priceRes->Fetch()) {
            if (isset($row['ID'])) {
                \CPrice::Delete((int)$row['ID']);
            }
        }

        return true;
    }

    /**
     * Удалить ВСЕ цены товара (все типы цен)
     *
     * @param int $productId ID товара
     * @return bool
     */
    public function deleteAllPrices(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }

        $priceRes = \CPrice::GetList(
            [],
            ['PRODUCT_ID' => $productId]
        );

        while ($row = $priceRes->Fetch()) {
            if (isset($row['ID'])) {
                \CPrice::Delete((int)$row['ID']);
            }
        }

        return true;
    }

    /**
     * Получить все цены товара
     *
     * @param int $productId ID товара
     * @return array Массив цен
     */
    public function getPrices(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $prices = [];
        $priceRes = \CPrice::GetList(
            [],
            ['PRODUCT_ID' => $productId]
        );

        while ($row = $priceRes->Fetch()) {
            $prices[] = [
                'id' => (int)$row['ID'],
                'typeId' => (int)$row['CATALOG_GROUP_ID'],
                'price' => (float)$row['PRICE'],
                'currency' => $row['CURRENCY'],
                'quantityFrom' => $row['QUANTITY_FROM'] !== null ? (int)$row['QUANTITY_FROM'] : null,
                'quantityTo' => $row['QUANTITY_TO'] !== null ? (int)$row['QUANTITY_TO'] : null,
            ];
        }

        return $prices;
    }

    /**
     * Обновить закупочную цену товара
     *
     * @param int $productId ID товара
     * @param float $price Цена
     * @param string $currency Валюта
     * @return bool
     */
    public function updatePurchasingPrice(int $productId, float $price, string $currency = 'RUB'): bool
    {
        if ($productId <= 0 || $price < 0) {
            return false;
        }

        return (bool)\CCatalogProduct::Update($productId, [
            'PURCHASING_PRICE' => $price,
            'PURCHASING_CURRENCY' => $currency,
        ]);
    }

    /**
     * Обновить физические параметры товара
     *
     * @param int $productId ID товара
     * @param array $params Параметры (WIDTH, LENGTH, HEIGHT, WEIGHT, MEASURE)
     * @return bool
     */
    public function updateProductParams(int $productId, array $params): bool
    {
        if ($productId <= 0 || empty($params)) {
            return false;
        }

        // Фильтруем допустимые поля
        $allowedFields = ['WIDTH', 'LENGTH', 'HEIGHT', 'WEIGHT', 'MEASURE'];
        $fields = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $params) && $params[$field] !== null) {
                $fields[$field] = $params[$field];
            }
        }

        if (empty($fields)) {
            return true;
        }

        return (bool)\CCatalogProduct::Update($productId, $fields);
    }
}
