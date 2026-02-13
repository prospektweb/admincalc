<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Services\CatalogPriceService;

/**
 * Сервис для записи результатов расчёта.
 */
class ResultWriter
{
    /** @var ConfigManager */
    protected ConfigManager $configManager;

    /** @var CatalogPriceService */
    protected CatalogPriceService $catalogPriceService;

    public function __construct()
    {
        $this->configManager = new ConfigManager();
        $this->catalogPriceService = new CatalogPriceService();
    }

    /**
     * Записывает цену товара.
     *
     * @deprecated Use CatalogPriceService::writePrice() directly
     *
     * @param int    $productId   ID товара.
     * @param int    $priceTypeId ID типа цены.
     * @param float  $price       Цена.
     * @param string $currency    Валюта.
     * @param int|null $quantityFrom Количество от.
     * @param int|null $quantityTo   Количество до.
     *
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
        return $this->catalogPriceService->writePrice(
            $productId,
            $priceTypeId,
            $price,
            $currency,
            $quantityFrom,
            $quantityTo
        );
    }

    /**
     * Записывает диапазоны цен для товара.
     *
     * @deprecated Use CatalogPriceService::writePriceRanges() directly
     *
     * @param int    $productId   ID товара.
     * @param int    $priceTypeId ID типа цены.
     * @param array  $ranges      Массив диапазонов [{from, to, value}].
     * @param string $currency    Валюта.
     *
     * @return bool
     */
    public function writePriceRanges(
        int $productId,
        int $priceTypeId,
        array $ranges,
        string $currency = 'RUB'
    ): bool {
        return $this->catalogPriceService->writePriceRanges(
            $productId,
            $priceTypeId,
            $ranges,
            $currency
        );
    }

    /**
     * Удаляет цены товара для указанного типа.
     *
     * @deprecated Use CatalogPriceService::deletePricesByType() directly
     *
     * @param int $productId   ID товара.
     * @param int $priceTypeId ID типа цены.
     *
     * @return bool
     */
    public function deletePrices(int $productId, int $priceTypeId): bool
    {
        return $this->catalogPriceService->deletePricesByType($productId, $priceTypeId);
    }

    /**
     * Обновляет закупочную цену товара.
     *
     * @deprecated Use CatalogPriceService::updatePurchasingPrice() directly
     *
     * @param int    $productId ID товара.
     * @param float  $price     Цена.
     * @param string $currency  Валюта.
     *
     * @return bool
     */
    public function updatePurchasingPrice(int $productId, float $price, string $currency = 'RUB'): bool
    {
        return $this->catalogPriceService->updatePurchasingPrice($productId, $price, $currency);
    }

    /**
     * Обновляет физические параметры товара.
     *
     * @deprecated Use CatalogPriceService::updateProductParams() directly
     *
     * @param int   $productId ID товара.
     * @param array $params    Параметры (WIDTH, LENGTH, HEIGHT, WEIGHT, MEASURE).
     *
     * @return bool
     */
    public function updateProductParams(int $productId, array $params): bool
    {
        return $this->catalogPriceService->updateProductParams($productId, $params);
    }

    /**
     * Сохраняет конфигурацию расчёта в инфоблок CALC_STAGES.
     *
     * @param int    $productId ID товара.
     * @param array  $structure Структура расчёта.
     * @param float  $totalCost Итоговая себестоимость.
     * @param array  $usedIds   Использованные ID [materials, operations, equipment, details].
     *
     * @return int|bool ID элемента или false.
     */
    public function saveCalculationConfig(
        int $productId,
        array $structure,
        float $totalCost,
        array $usedIds = []
    ) {
        if (!Loader::includeModule('iblock')) {
            return false;
        }

        $iblockId = $this->configManager->getIblockId('CALC_STAGES');
        if ($iblockId <= 0) {
            return false;
        }

        // Ищем существующую конфигурацию для этого товара
        $rsElements = \CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $iblockId,
                'PROPERTY_PRODUCT_ID' => $productId,
            ],
            false,
            ['nTopCount' => 1],
            ['ID']
        );

        $existingId = null;
        if ($arElement = $rsElements->Fetch()) {
            $existingId = (int)$arElement['ID'];
        }

        $fields = [
            'IBLOCK_ID' => $iblockId,
            'NAME' => 'Калькуляция для товара ' . $productId,
            'ACTIVE' => 'Y',
        ];

        $properties = [
            'PRODUCT_ID' => $productId,
            'STATUS' => 'active',
            'LAST_CALC_DATE' => date('d.m.Y H:i:s'),
            'TOTAL_COST' => $totalCost,
            'STRUCTURE' => ['VALUE' => ['TEXT' => json_encode($structure), 'TYPE' => 'html']],
        ];

        if (!empty($usedIds['materials'])) {
            $properties['USED_MATERIALS'] = $usedIds['materials'];
        }
        if (!empty($usedIds['operations'])) {
            $properties['USED_OPERATIONS'] = $usedIds['operations'];
        }
        if (!empty($usedIds['equipment'])) {
            $properties['USED_EQUIPMENT'] = $usedIds['equipment'];
        }
        if (!empty($usedIds['details'])) {
            $properties['USED_DETAILS'] = $usedIds['details'];
        }

        $fields['PROPERTY_VALUES'] = $properties;

        $el = new \CIBlockElement();

        if ($existingId) {
            $el->Update($existingId, $fields);
            return $existingId;
        }

        $fields['CODE'] = $this->generateUniqueElementCode($iblockId, (string)$fields['NAME']);

        return $el->Add($fields);
    }

    private function generateUniqueElementCode(int $iblockId, string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'element';
        }

        $baseCode = (string)\CUtil::translit($name, 'ru', [
            'max_len' => 100,
            'change_case' => 'L',
            'replace_space' => '-',
            'replace_other' => '-',
            'delete_repeat_replace' => true,
            'use_google' => true,
        ]);

        if ($baseCode === '') {
            $baseCode = 'element';
        }

        $candidate = $baseCode;
        $suffix = 2;
        while ($this->isElementCodeExists($iblockId, $candidate)) {
            $suffixText = '-' . $suffix;
            $candidate = mb_substr($baseCode, 0, 100 - strlen($suffixText)) . $suffixText;
            $suffix++;
        }

        return $candidate;
    }

    private function isElementCodeExists(int $iblockId, string $code): bool
    {
        $exists = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, '=CODE' => $code],
            false,
            ['nTopCount' => 1],
            ['ID']
        )->Fetch();

        return (int)($exists['ID'] ?? 0) > 0;
    }
}
