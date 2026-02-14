<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Сервис подготовки INIT payload для React-калькулятора
 */
class InitPayloadService
{
    /** @var string ID модуля */
    private const MODULE_ID = 'prospektweb.calc';

    /** @var array Кэш элементов для preset */
    private array $elementsStore = [];

    /**
     * Подготовить INIT payload для отправки в iframe
     *
     * @param array $offerIds ID торговых предложений
     * @param string $siteId ID сайта
     * @param bool $forceCreatePreset Принудительное создание нового preset (после подтверждения пользователя)
     * @return array
     * @throws \Exception
     */
    public function prepareInitPayload(array $offerIds, string $siteId, bool $forceCreatePreset = false): array
    {
        if (empty($offerIds)) {
            throw new \Exception('Список торговых предложений не может быть пустым');
        }

        $this->ensureBitrixModulesLoaded();

        // Загружаем информацию о ТП
        $selectedOffers = $this->loadOffers($offerIds);
        
        // Анализируем состояние CALC_PRESET у ТП
        $analysis = $this->analyzeBundles($selectedOffers);
        
        // Определяем presetId
        $presetId = $analysis['bundleId'];
        $productId = (int)($analysis['productId'] ?? 0);
        
        if ($presetId === null || $forceCreatePreset) {
            // Создаём новый постоянный preset
            $bundleHandler = new BundleHandler();
            $presetId = $bundleHandler->createPreset($offerIds);
        }
        
        $this->elementsStore = [];

        // Загружаем preset с данными
        $preset = $this->loadPreset($presetId);

        // Загружаем товар, из которого получен presetId
        $product = $this->loadProduct($productId);

        // Собираем контекст
        $context = $this->buildContext($siteId);

        // Собираем информацию об инфоблоках
        $iblocks = $this->getIblocks();

        // Формируем payload
        return [
            'context' => $context,
            'iblocks' => $iblocks,
            'iblocksTree' => $this->buildIblocksTree(),
            'selectedOffers' => $selectedOffers,
            'priceTypes' => $this->getPriceTypes(),
            'preset' => $preset,
            'product' => $product,
            'elementsStore' => $this->elementsStore ?? [],
            'elementsSiblings' => $this->buildElementsSiblings($preset),
        ];
    }

    /**
     * Проверяет наличие необходимых модулей Bitrix
     *
     * @throws \RuntimeException
     */
    private function ensureBitrixModulesLoaded(): void
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix iblock');
        }

        if (!Loader::includeModule('catalog')) {
            throw new \RuntimeException('Требуется модуль Bitrix catalog');
        }
    }

    /**
     * Загрузить информацию о торговых предложениях
     *
     * @param array $offerIds
     * @return array
     */
    private function loadOffers(array $offerIds): array
    {
        $offers = [];

        foreach ($offerIds as $offerId) {
            $offerId = (int)$offerId;
            if ($offerId <= 0) {
                continue;
            }

            $elementObject = \CIBlockElement::GetList(
                [],
                ['ID' => $offerId],
                false,
                false,
                ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'TIMESTAMP_X', 'MODIFIED_BY', 'PROPERTY_*']
            )->GetNextElement();

            if (!$elementObject) {
                continue;
            }

            $element = $elementObject->GetFields();
            $properties = PropertyPayloadLoader::loadElementProperties((int)$element['IBLOCK_ID'], $offerId);

            $productData = \CCatalogProduct::GetByID($offerId) ?: [];
            $measureInfo = $this->getMeasureInfo((int)($productData['MEASURE'] ?? 0));
            $measureRatio = $this->getMeasureRatio($offerId);
            $prices = $this->getPrices($offerId);
            $purchasingPrice = isset($productData['PURCHASING_PRICE']) ? (float)$productData['PURCHASING_PRICE'] : null;
            $purchasingCurrency = $productData['PURCHASING_CURRENCY'] ?? null;

            $productId = (int)($element['PROPERTY_CML2_LINK_VALUE'] ?? 0);
            if ($productId <= 0) {
                $skuParent = \CCatalogSku::GetProductInfo($offerId);
                if (!empty($skuParent['ID'])) {
                    $productId = (int)$skuParent['ID'];
                }
            }

            $offers[] = [
                'id' => $offerId,
                'iblockId' => (int)$element['IBLOCK_ID'],
                'name' => $element['NAME'] ?? '',
                'code' => $element['CODE'] ?? null,
                'timestampX' => $element['TIMESTAMP_X'] ?? null,
                'modifiedBy' => isset($element['MODIFIED_BY']) ? (int)$element['MODIFIED_BY'] : null,
                'timestamp_x' => $element['TIMESTAMP_X'] ?? null,
                'modified_by' => isset($element['MODIFIED_BY']) ? (int)$element['MODIFIED_BY'] : null,
                'attributes' => [
                    'width' => isset($productData['WIDTH']) ? (float)$productData['WIDTH'] : null,
                    'height' => isset($productData['HEIGHT']) ? (float)$productData['HEIGHT'] :  null,
                    'length' => isset($productData['LENGTH']) ? (float)$productData['LENGTH'] : null,
                    'weight' => isset($productData['WEIGHT']) ? (float)$productData['WEIGHT'] : null,
                ],
                'measure' => $measureInfo,
                'measureRatio' => $measureRatio,
                'prices' => $prices,
                'purchasingPrice' => $purchasingPrice,
                'purchasingCurrency' => $purchasingCurrency,
                'properties' => $properties,
            ];
        }

        return $offers;
    }

    /**
     * Получить коэффициент единицы измерения для товара
     */
    private function getMeasureRatio(int $productId): float
    {
        if ($productId <= 0) {
            return 1.0;
        }

        $ratioIterator = \CCatalogMeasureRatio::getList(
            [],
            ['PRODUCT_ID' => $productId]
        );

        if ($ratio = $ratioIterator->Fetch()) {
            return (float)($ratio['RATIO'] ?? 1);
        }

        return 1.0;
    }

    /**
     * Получить информацию о единице измерения
     */
    private function getMeasureInfo(int $measureId): ?array
    {
        if ($measureId <= 0) {
            return null;
        }

        $measureIterator = \CCatalogMeasure::getList(
            ['ID' => 'ASC'],
            ['=ID' => $measureId]
        );

        if ($measure = $measureIterator->Fetch()) {
            return [
                'id' => (int)$measure['ID'],
                'code' => $measure['CODE'] ?? null,
                'symbol' => $measure['SYMBOL'] ?? null,
                'symbolInt' => $measure['SYMBOL_INTL'] ?? null,
                'title' => $measure['MEASURE_TITLE'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Получить цены для торгового предложения
     */
    private function getPrices(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $prices = [];
        $priceIterator = \CPrice::GetList(
            [],
            ['PRODUCT_ID' => $productId]
        );

        while ($price = $priceIterator->Fetch()) {
            $prices[] = [
                'typeId' => (int)$price['CATALOG_GROUP_ID'],
                'price' => (float)$price['PRICE'],
                'currency' => $price['CURRENCY'] ?? null,
                'quantityFrom' => isset($price['QUANTITY_FROM']) ? (int)$price['QUANTITY_FROM'] : null,
                'quantityTo' => isset($price['QUANTITY_TO']) ? (int)$price['QUANTITY_TO'] : null,
            ];
        }

        return $prices;
    }

    /**
     * Анализировать состояние PRESET у торговых предложений
     * Все ТП принадлежат одному товару, берём productId из первого
     * 
     * @param array $offers Массив ТП
     * @return array Результат анализа
     */
    private function analyzeBundles(array $offers): array
    {
        if (empty($offers)) {
            return [
                'scenario' => 'NEW_BUNDLE',
                'bundleId' => null,
                'productId' => 0,
            ];
        }
        
        // Все ТП принадлежат одному товару, берём productId из первого
        $productId = $this->getProductIdFromOffer($offers[0]);
        
        if ($productId <= 0) {
            return [
                'scenario' => 'NEW_BUNDLE',
                'bundleId' => null,
                'productId' => 0,
            ];
        }
        
        // Получаем CALC_PRESET из товара
        $presetId = $this->getPresetFromProduct($productId);
        
        if ($presetId !== null && $presetId > 0) {
            // У товара есть preset → используем существующий
            return [
                'scenario' => 'EXISTING_PRESET',
                'bundleId' => $presetId,
                'productId' => $productId,
            ];
        }
        
        // У товара нет preset → создаём новый
        return [
            'scenario' => 'NEW_BUNDLE',
            'bundleId' => null,
            'productId' => $productId,
        ];
    }
    
    /**
     * Получить ID товара из offer
     * 
     * @param array $offer Данные ТП
     * @return int ID товара
     */
    private function getProductIdFromOffer(array $offer): int
    {
        // Сначала проверяем прямое поле (если добавлено)
        if (!empty($offer['productId'])) {
            return (int)$offer['productId'];
        }
        
        // Затем ищем в properties
        if (isset($offer['properties']['CML2_LINK']['VALUE'])) {
            return (int)$offer['properties']['CML2_LINK']['VALUE'];
        }
        
        // Fallback через CCatalogSku
        if (! empty($offer['id'])) {
            $skuParent = \CCatalogSku::GetProductInfo((int)$offer['id']);
            if (!empty($skuParent['ID'])) {
                return (int)$skuParent['ID'];
            }
        }
        
        return 0;
    }
    
    /**
     * Получить presetId из товара
     * 
     * @param int $productId ID товара
     * @return int|null ID пресета или null
     */
    private function getPresetFromProduct(int $productId): ?int
    {
        if ($productId <= 0) {
            return null;
        }
        
        $configManager = new ConfigManager();
        $productIblockId = $configManager->getProductIblockId();
        
        if ($productIblockId <= 0) {
            return null;
        }
        
        $rsProduct = \CIBlockElement::GetList(
            [],
            ['ID' => $productId, 'IBLOCK_ID' => $productIblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'PROPERTY_CALC_PRESET']
        );
        
        if ($product = $rsProduct->Fetch()) {
            $presetId = $product['PROPERTY_CALC_PRESET_VALUE'] ?? null;
            return $presetId ? (int)$presetId : null;
        }
        
        return null;
    }

    /**
     * Собрать контекст запроса
     *
     * @param string $siteId
     * @return array
     */
    private function buildContext(string $siteId): array
    {
        global $USER;

        $context = Application::getInstance()->getContext();

        $resolvedSiteId = $context->getSite() ?: (defined('SITE_ID') ? SITE_ID : null);
        if (empty($resolvedSiteId)) {
            $resolvedSiteId = $siteId;
        }

        $languageId = $context->getLanguage() ?: (defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru');

        $siteUrl = $this->buildSiteUrl($context->getRequest()->getHttpHost());

        $userId = '0';
        if (is_object($USER) && method_exists($USER, 'GetID')) {
            $userIdValue = $USER->GetID();
            if ($userIdValue !== null) {
                $userId = (string)$userIdValue;
            }
        }

        $settingsManager = new \Prospektweb\Calc\Config\SettingsManager();

        return [
            'siteId' => (string)$resolvedSiteId,
            'userId' => $userId,
            'lang' => $languageId,
            'timestamp' => time(),
            'url' => $siteUrl,
            'priceRounding' => (float)Option::get(self::MODULE_ID, 'PRICE_ROUNDING', 1),
            'defaultExtraValue' => $settingsManager->getDefaultExtraValue(),
            'defaultExtraCurrency' => $settingsManager->getDefaultExtraCurrency(),
        ];
    }

    private function buildSiteUrl(?string $host): string
    {
        if (empty($host)) {
            $host = (string)Option::get('main', 'server_name', '');
        }

        $host = trim((string)$host);

        if ($host === '') {
            return '';
        }

        return sprintf('https://%s', $host);
    }

    /**
     * Получить ID инфоблоков из настроек
     *
     * @return array
     */
    private function getIblocks(): array
    {
        $configManager = new ConfigManager();
        $moduleIblocks = $configManager->getAllIblockIds();

        $map = [
            'PRODUCTS' => $configManager->getProductIblockId(),
            'OFFERS' => $configManager->getSkuIblockId(),
            'CALC_PRESETS' => (int)($moduleIblocks['CALC_PRESETS'] ?? 0),
            'CALC_STAGES' => (int)($moduleIblocks['CALC_STAGES'] ?? 0),
            'CALC_SETTINGS' => (int)($moduleIblocks['CALC_SETTINGS'] ?? 0),
            'CALC_CUSTOM_FIELDS' => (int)($moduleIblocks['CALC_CUSTOM_FIELDS'] ?? 0),
            'CALC_MATERIALS' => (int)($moduleIblocks['CALC_MATERIALS'] ?? 0),
            'CALC_MATERIALS_VARIANTS' => (int)($moduleIblocks['CALC_MATERIALS_VARIANTS'] ?? 0),
            'CALC_OPERATIONS' => (int)($moduleIblocks['CALC_OPERATIONS'] ?? 0),
            'CALC_OPERATIONS_VARIANTS' => (int)($moduleIblocks['CALC_OPERATIONS_VARIANTS'] ?? 0),
            'CALC_EQUIPMENT' => (int)($moduleIblocks['CALC_EQUIPMENT'] ?? 0),
            'CALC_DETAILS' => (int)($moduleIblocks['CALC_DETAILS'] ?? 0),
        ];

        $parentMap = [
            'CALC_MATERIALS_VARIANTS' => 'CALC_MATERIALS',
            'CALC_OPERATIONS_VARIANTS' => 'CALC_OPERATIONS',
        ];

        $result = [];

        foreach ($map as $code => $id) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }

            $iblock = \CIBlock::GetArrayByID($id) ?: [];
            
            // Получаем свойства инфоблока
            $properties = $this->getIblockProperties($id);
            
            $result[] = [
                'id' => $id,
                'code' => $code,
                'type' => $iblock['IBLOCK_TYPE_ID'] ?? null,
                'name' => $iblock['NAME'] ?? $code,
                'parent' => isset($parentMap[$code], $map[$parentMap[$code]]) && (int)$map[$parentMap[$code]] > 0
                    ? (int)$map[$parentMap[$code]]
                    : null,
                'properties' => $properties,
            ];
        }

        return $result;
    }

    /**
     * Получить свойства инфоблока
     *
     * @param int $iblockId ID инфоблока
     * @return array Массив свойств
     */
    private function getIblockProperties(int $iblockId): array
    {
        $properties = [];
        
        $rsProperties = \CIBlockProperty::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y']
        );
        
        while ($prop = $rsProperties->Fetch()) {
            $property = [
                'ID' => (int)$prop['ID'],
                'CODE' => $prop['CODE'] ?? '',
                'NAME' => $prop['NAME'] ?? '',
                'PROPERTY_TYPE' => $prop['PROPERTY_TYPE'] ?? '',
                'MULTIPLE' => $prop['MULTIPLE'] ?? 'N',
                'IS_REQUIRED' => $prop['IS_REQUIRED'] ?? 'N',
                'SORT' => (int)($prop['SORT'] ?? 500),
                'DEFAULT_VALUE' => $prop['DEFAULT_VALUE'] ?? '',
                'LINK_IBLOCK_ID' => $prop['LINK_IBLOCK_ID'] ? (int)$prop['LINK_IBLOCK_ID'] : null,
                'USER_TYPE' => $prop['USER_TYPE'] ?? null,
                'USER_TYPE_SETTINGS' => $prop['USER_TYPE_SETTINGS'] ?? null,
                'WITH_DESCRIPTION' => $prop['WITH_DESCRIPTION'] ?? 'N',
                'MULTIPLE_CNT' => $prop['MULTIPLE_CNT'] ?? 5,
                'ROW_COUNT' => $prop['ROW_COUNT'] ?? 1,
                'COL_COUNT' => $prop['COL_COUNT'] ?? 30,
            ];
            
            // Если тип свойства - список (L), получаем варианты значений
            if ($prop['PROPERTY_TYPE'] === 'L') {
                $enums = [];
                $rsEnums = \CIBlockPropertyEnum::GetList(
                    ['SORT' => 'ASC', 'ID' => 'ASC'],
                    ['PROPERTY_ID' => $prop['ID']]
                );
                
                while ($enum = $rsEnums->Fetch()) {
                    $enums[] = [
                        'ID' => (int)$enum['ID'],
                        'VALUE' => $enum['VALUE'] ?? '',
                        'XML_ID' => $enum['XML_ID'] ?? '',
                        'DEF' => $enum['DEF'] ?? 'N',
                        'SORT' => (int)($enum['SORT'] ?? 500),
                    ];
                }
                
                $property['ENUMS'] = $enums;
            }
            
            $properties[] = $property;
        }
        
        return $properties;
    }

    /**
     * Найти ID инфоблока по его коду в массиве объектов.
     */
    private function findIblockIdByCode(array $iblocks, string $code): int
    {
        foreach ($iblocks as $iblock) {
            if (($iblock['code'] ?? null) === $code) {
                return (int)($iblock['id'] ?? 0);
            }
        }

        return 0;
    }


    /**
    * Загрузить preset со всеми данными
    * 
    * @param int $presetId ID пресета
    * @return array|null
    */
    private function loadPreset(int $presetId): ?array
    {
        if ($presetId <= 0) {
            return null;
        }

        $configManager = new ConfigManager();
        $iblockId = $configManager->getIblockId('CALC_PRESETS');
        
        if ($iblockId <= 0) {
            return null;
        }

        // Получаем основные поля элемента
        $rsElement = \CIBlockElement:: GetList(
            [],
            ['ID' => $presetId, 'IBLOCK_ID' => $iblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME', 'CODE', 'IBLOCK_SECTION_ID']
        );

        $fields = $rsElement->Fetch();
        if (! $fields) {
            return null;
        }

        $elementDataService = new ElementDataService();
        $presetElement = $elementDataService->loadSingleElement($iblockId, $presetId, null, true);
        if (!$presetElement) {
            return null;
        }

        $propertiesRaw = $this->loadPresetProperties($iblockId, $presetId);
        $presetElement['properties'] = array_map(
            static fn(array $property) => $property['values'] ?? [],
            $propertiesRaw
        );
        $presetElement['iblockId'] = $iblockId;

        $this->elementsStore = $this->buildElementsStore($propertiesRaw);

        return $presetElement;
    }

    /**
    * Загрузить товар со всеми данными для INIT payload
    *
    * @param int $productId ID товара
    * @return array|null
    */
    private function loadProduct(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        $configManager = new ConfigManager();
        $iblockId = $configManager->getProductIblockId();
        if ($iblockId <= 0) {
            return null;
        }

        $elementDataService = new ElementDataService();
        $productElement = $elementDataService->loadSingleElement($iblockId, $productId, null, true);

        if (!$productElement) {
            return null;
        }

        return $productElement;
    }

    /**
    * Загрузить свойства preset через GetProperty (для инфоблоков версии 2)
    * 
    * @param int $iblockId ID инфоблока
    * @param int $elementId ID элемента
    * @return array Массив [CODE => [values]]
    */
    private function loadPresetProperties(int $iblockId, int $elementId): array
    {
        $result = [];

        $rsProperty = \CIBlockElement::GetProperty(
            $iblockId,
            $elementId,
            [],
            []
        );

        while ($arProp = $rsProperty->Fetch()) {
            $code = $arProp['CODE'] ?: (string)$arProp['ID'];

            if (in_array($code, ['JSON', 'CALC_DIMENSIONS_WEIGHT'], true)) {
                continue;
            }

            if (!isset($result[$code])) {
                $result[$code] = [
                    'property' => $arProp,
                    'values' => [],
                ];
            }

            if ($arProp['VALUE'] !== null && $arProp['VALUE'] !== '') {
                $result[$code]['values'][] = $arProp['VALUE'];
            }
        }

        return $result;
    }


    /**
     * Собирает элементы в elementsStore по коду свойства.
     */
    private function buildElementsStore(array $propertiesRaw): array
    {
        $elementDataService = new ElementDataService();
        $store = [];

        foreach ($propertiesRaw as $code => $propertyData) {
            $values = $propertyData['values'] ?? [];
            $ids = array_filter(array_map('intval', $values), static fn($id) => $id > 0);

            $linkIblockId = isset($propertyData['property']['LINK_IBLOCK_ID'])
                ? (int)$propertyData['property']['LINK_IBLOCK_ID']
                : 0;

            if ($linkIblockId <= 0) {
                continue;
            }

            if (empty($ids)) {
                if ($code === 'CALC_CUSTOM_FIELDS') {
                    $store[$code] = [];
                }
                continue;
            }

            $payload = $elementDataService->prepareRefreshPayload([
                [
                    'iblockId' => $linkIblockId,
                    'iblockType' => null,
                    'ids' => $ids,
                    'includeParent' => true,
                ],
            ]);

            $store[$code] = $payload[0]['data'] ?? [];
        }

        return $store;
    }

    /**
     * Собрать дерево данных всех инфоблоков модуля для MultiLevelSelect
     * 
     * @return array Массив деревьев по ключам инфоблоков
     */
    private function buildIblocksTree(): array
    {
        $iblocks = $this->getIblocks();
        $trees = [];

        // CALC_SETTINGS
        $calcSettingsId = $this->findIblockIdByCode($iblocks, 'CALC_SETTINGS');
        if ($calcSettingsId > 0) {
            $trees['calcSettings'] = $this->buildIblockTree($calcSettingsId);
        }

        // CALC_EQUIPMENT
        $calcEquipmentId = $this->findIblockIdByCode($iblocks, 'CALC_EQUIPMENT');
        if ($calcEquipmentId > 0) {
            $trees['calcEquipment'] = $this->buildIblockTree($calcEquipmentId);
        }

        // CALC_MATERIALS с variants
        $calcMaterialsId = $this->findIblockIdByCode($iblocks, 'CALC_MATERIALS');
        $calcMaterialsVariantsId = $this->findIblockIdByCode($iblocks, 'CALC_MATERIALS_VARIANTS');
        if ($calcMaterialsId > 0) {
            $trees['calcMaterials'] = $this->buildCatalogTree(
                $calcMaterialsId,
                $calcMaterialsVariantsId
            );
        }
        
        // CALC_OPERATIONS с variants
        $calcOperationsId = $this->findIblockIdByCode($iblocks, 'CALC_OPERATIONS');
        $calcOperationsVariantsId = $this->findIblockIdByCode($iblocks, 'CALC_OPERATIONS_VARIANTS');
        if ($calcOperationsId > 0) {
            $trees['calcOperations'] = $this->buildCatalogTree(
                $calcOperationsId,
                $calcOperationsVariantsId
            );
        }

        return $trees;
    }

    /**
     * Строит дерево разделов и элементов для одного инфоблока (без дочерних элементов)
     *
     * @param int $iblockId ID инфоблока
     * @return array
     */
    private function buildIblockTree(int $iblockId): array
    {
        if ($iblockId <= 0) {
            return [];
        }

        $sections = $this->getSections($iblockId);
        $elements = $this->getElements($iblockId);

        return $this->assembleTree($sections, $elements);
    }

    /**
     * Строит дерево товаров с торговыми предложениями
     *
     * @param int $productIblockId ID инфоблока товаров
     * @param int $offersIblockId ID инфоблока торговых предложений
     * @return array
     */
    private function buildProductsTree(int $productIblockId, int $offersIblockId): array
    {
        if ($productIblockId <= 0) {
            return [];
        }

        $sections = $this->getSections($productIblockId);
        $elements = $this->getElements($productIblockId);

        // Получаем торговые предложения для товаров
        $productIds = array_column($elements, 'id');
        $offers = [];
        
        if ($offersIblockId > 0 && !empty($productIds)) {
            $offersData = \CCatalogSKU::getOffersList(
                $productIds,
                $productIblockId,
                [],
                ['ID', 'NAME', 'CODE'],
                ['ID', 'NAME', 'CODE']
            );
            
            if (is_array($offersData)) {
                foreach ($offersData as $productId => $productOffers) {
                    $offers[$productId] = [];
                    foreach ($productOffers as $offer) {
                        $offers[$productId][] = [
                            'type' => 'child',
                            'id' => (int)$offer['ID'],
                            'name' => $offer['NAME'] ?? '',
                            'code' => $offer['CODE'] ?? '',
                            'iblockId' => $offersIblockId,
                            'parentId' => $productId,
                        ];
                    }
                }
            }
        }

        // Добавляем торговые предложения к элементам
        foreach ($elements as &$element) {
            if (!empty($offers[$element['id']])) {
                $element['children'] = $offers[$element['id']];
            }
        }
        unset($element);

        return $this->assembleTree($sections, $elements);
    }

    /**
     * Строит дерево для каталогов со SKU-связью (materials, operations, details)
     *
     * @param int $parentIblockId ID основного инфоблока
     * @param int $variantsIblockId ID инфоблока вариантов
     * @return array
     */
    private function buildCatalogTree(int $parentIblockId, int $variantsIblockId): array
    {
        if ($parentIblockId <= 0) {
            return [];
        }

        $sections = $this->getSections($parentIblockId);
        $elements = $this->getElements($parentIblockId);

        // Получаем варианты для элементов
        $parentIds = array_column($elements, 'id');
        $variants = [];
        
        if ($variantsIblockId > 0 && !empty($parentIds)) {
            $variantsData = $this->getVariants($variantsIblockId, $parentIds);
            
            foreach ($variantsData as $variant) {
                $parentId = $variant['parentId'];
                if (!isset($variants[$parentId])) {
                    $variants[$parentId] = [];
                }
                $variants[$parentId][] = $variant;
            }
        }

        // Добавляем варианты к элементам
        foreach ($elements as &$element) {
            if (!empty($variants[$element['id']])) {
                $element['children'] = $variants[$element['id']];
            }
        }
        unset($element);

        return $this->assembleTree($sections, $elements);
    }

    /**
     * Получает разделы и строит иерархию
     *
     * @param int $iblockId ID инфоблока
     * @return array
     */
    private function getSections(int $iblockId): array
    {
        if ($iblockId <= 0) {
            return [];
        }

        $sections = [];
        $res = \CIBlockSection::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
            false,
            ['ID', 'NAME', 'CODE', 'IBLOCK_SECTION_ID', 'IBLOCK_ID', 'SORT', 'DEPTH_LEVEL']
        );

        while ($section = $res->Fetch()) {
            $sections[] = [
                'type' => 'section',
                'id' => (int)$section['ID'],
                'name' => $section['NAME'] ?? '',
                'code' => $section['CODE'] ?? '',
                'iblockId' => (int)$section['IBLOCK_ID'],
                'parentId' => !empty($section['IBLOCK_SECTION_ID']) ? (int)$section['IBLOCK_SECTION_ID'] : null,
                'depth' => (int)($section['DEPTH_LEVEL'] ?? 1),
            ];
        }

        return $sections;
    }

    /**
     * Получает элементы с их свойствами
     *
     * @param int $iblockId ID инфоблока
     * @return array
     */
    private function getElements(int $iblockId): array
    {
        if ($iblockId <= 0) {
            return [];
        }

        $elements = [];
        $res = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
            false,
            false,
            ['ID', 'NAME', 'CODE', 'IBLOCK_SECTION_ID', 'IBLOCK_ID', 'TIMESTAMP_X', 'MODIFIED_BY']
        );

        while ($fields = $res->Fetch()) {
            $elements[] = [
                'type' => 'element',
                'id' => (int)$fields['ID'],
                'name' => $fields['NAME'] ?? '',
                'code' => $fields['CODE'] ?? '',
                'iblockId' => (int)$fields['IBLOCK_ID'],
                'sectionId' => !empty($fields['IBLOCK_SECTION_ID']) ? (int)$fields['IBLOCK_SECTION_ID'] : 0,
                'timestampX' => $fields['TIMESTAMP_X'] ?? null,
                'modifiedBy' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
                'timestamp_x' => $fields['TIMESTAMP_X'] ?? null,
                'modified_by' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
            ];
        }

        return $elements;
    }

    /**
     * Получает варианты для списка родительских элементов
     *
     * @param int $variantsIblockId ID инфоблока вариантов
     * @param array $parentIds Массив ID родительских элементов
     * @return array
     */
    private function getVariants(int $variantsIblockId, array $parentIds): array
    {
        if ($variantsIblockId <= 0 || empty($parentIds)) {
            return [];
        }

        $variants = [];
        $res = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            [
                'IBLOCK_ID' => $variantsIblockId,
                'ACTIVE' => 'Y',
                'PROPERTY_CML2_LINK' => $parentIds,
            ],
            false,
            false,
            ['ID', 'NAME', 'CODE', 'IBLOCK_ID', 'TIMESTAMP_X', 'MODIFIED_BY', 'PROPERTY_CML2_LINK']
        );

        while ($fields = $res->Fetch()) {
            $parentId = !empty($fields['PROPERTY_CML2_LINK_VALUE'])
                ? (int)$fields['PROPERTY_CML2_LINK_VALUE']
                : 0;

            $variants[] = [
                'type' => 'child',
                'id' => (int)$fields['ID'],
                'name' => $fields['NAME'] ?? '',
                'code' => $fields['CODE'] ?? '',
                'iblockId' => $variantsIblockId,
                'parentId' => $parentId,
                'timestampX' => $fields['TIMESTAMP_X'] ?? null,
                'modifiedBy' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
                'timestamp_x' => $fields['TIMESTAMP_X'] ?? null,
                'modified_by' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
            ];
        }

        return $variants;
    }

    /**
     * Собирает дерево из разделов и элементов
     *
     * @param array $sections Массив разделов
     * @param array $elements Массив элементов
     * @return array
     */
    private function assembleTree(array $sections, array $elements): array
    {
        // Распределяем элементы по разделам
        $sectionElements = [];
        $rootElements = [];

        foreach ($elements as $element) {
            $sectionId = $element['sectionId'];
            if ($sectionId > 0) {
                if (!isset($sectionElements[$sectionId])) {
                    $sectionElements[$sectionId] = [];
                }
                $sectionElements[$sectionId][] = $element;
            } else {
                $rootElements[] = $element;
            }
        }

        // Функция для построения дерева рекурсивно
        $buildTree = function ($parentId) use (&$buildTree, &$sections, &$sectionElements) {
            $result = [];

            foreach ($sections as $section) {
                if ($section['parentId'] === $parentId) {
                    $sectionNode = $section;
                    
                    // Добавляем дочерние разделы
                    $children = $buildTree($section['id']);
                    
                    // Добавляем элементы текущего раздела
                    if (!empty($sectionElements[$section['id']])) {
                        foreach ($sectionElements[$section['id']] as $element) {
                            $children[] = $element;
                        }
                    }
                    
                    if (!empty($children)) {
                        $sectionNode['children'] = $children;
                    }
                    
                    $result[] = $sectionNode;
                }
            }

            return $result;
        };

        $tree = $buildTree(null);

        // Добавляем элементы без раздела в конец
        if (!empty($rootElements)) {
            $tree = array_merge($tree, $rootElements);
        }

        return $tree;
    }

    /**
     * Получить список типов цен из каталога Bitrix
     *
     * @return array
     */
    private function getPriceTypes(): array
    {
        $priceTypes = [];

        // Проверяем, что модуль catalog загружен
        if (!Loader::includeModule('catalog')) {
            return $priceTypes;
        }

        try {
            $result = \CCatalogGroup::GetListArray();
            
            if (is_array($result)) {
                foreach ($result as $type) {
                    $priceTypes[] = [
                        'id' => (int)$type['ID'],
                        'name' => $type['NAME'] ?? '',
                        'base' => ($type['BASE'] ?? 'N') === 'Y',
                        'sort' => (int)($type['SORT'] ?? 100),
                    ];
                }
            }
        } catch (\Exception $e) {
            // В случае ошибки возвращаем пустой массив
            return [];
        }

        return $priceTypes;
    }

    /**
     * Собирает "соседние" варианты операций/материалов для всех этапов пресета
     * 
     * @param array|null $preset Данные пресета
     * @return array Массив соседних вариантов по этапам
     */
    private function buildElementsSiblings(?array $preset): array
    {
        if (!$preset || empty($preset['properties']['CALC_STAGES'])) {
            return [];
        }
        
        $stageIds = $preset['properties']['CALC_STAGES'];
        $configManager = new ConfigManager();
        $operationsIblockId = $configManager->getIblockId('CALC_OPERATIONS');
        $operationsVariantsIblockId = $configManager->getIblockId('CALC_OPERATIONS_VARIANTS');
        $materialsIblockId = $configManager->getIblockId('CALC_MATERIALS');
        $materialsVariantsIblockId = $configManager->getIblockId('CALC_MATERIALS_VARIANTS');
        
        $result = [];
        
        foreach ($stageIds as $stageId) {
            $stageId = (int)$stageId;
            if ($stageId <= 0) continue;
            
            $siblings = [
                'stageId' => $stageId,
                'CALC_OPERATIONS_VARIANTS' => [],
                'CALC_MATERIALS_VARIANTS' => [],
            ];
            
            $operationVariantsSelected = $this->getStageSelectedVariants(
                $stageId,
                'OPERATION_VARIANT',
                $operationsVariantsIblockId
            );
            $materialVariantsSelected = $this->getStageSelectedVariants(
                $stageId,
                'MATERIAL_VARIANT',
                $materialsVariantsIblockId
            );

            $operationReason = null;
            if (empty($operationVariantsSelected)) {
                $operationReason = 'Нет выбранных ТП в этапе ' . $stageId;
            }

            $materialReason = null;
            if (empty($materialVariantsSelected)) {
                $materialReason = 'Нет выбранных ТП в этапе ' . $stageId;
            }

            $operationParentIds = $this->collectParentIdsFromOffers($operationVariantsSelected);
            $materialParentIds = $this->collectParentIdsFromOffers($materialVariantsSelected);

            if ($operationReason === null && empty($operationParentIds)) {
                $operationReason = 'Не найден parentId у выбранных ТП';
            }

            if ($materialReason === null && empty($materialParentIds)) {
                $materialReason = 'Не найден parentId у выбранных ТП';
            }

            $operationSiblingIds = $this->loadSiblingOfferIds(
                $operationsIblockId,
                $operationParentIds
            );
            $materialSiblingIds = $this->loadSiblingOfferIds(
                $materialsIblockId,
                $materialParentIds
            );

            if ($operationReason === null && empty($operationSiblingIds)) {
                $operationReason = 'Не найдены соседи для parentId (проверьте SKU‑связь/инфоблок)';
            }

            if ($materialReason === null && empty($materialSiblingIds)) {
                $materialReason = 'Не найдены соседи для parentId (проверьте SKU‑связь/инфоблок)';
            }

            if (!empty($operationSiblingIds)) {
                $siblings['CALC_OPERATIONS_VARIANTS'] = $this->loadOfferElements(
                    $operationsVariantsIblockId,
                    $operationSiblingIds
                );
            }

            if (!empty($materialSiblingIds)) {
                $siblings['CALC_MATERIALS_VARIANTS'] = $this->loadOfferElements(
                    $materialsVariantsIblockId,
                    $materialSiblingIds
                );
            }

            if ($operationReason !== null) {
                $siblings['CALC_OPERATIONS_VARIANTS_REASON'] = $operationReason;
            }

            if ($materialReason !== null) {
                $siblings['CALC_MATERIALS_VARIANTS_REASON'] = $materialReason;
            }
            
            $result[] = $siblings;
        }
        
        return $result;
    }

    /**
     * Получить выбранные варианты для этапа.
     */
    private function getStageSelectedVariants(int $stageId, string $propertyCode, int $offersIblockId): array
    {
        $stageData = $this->elementsStore[$stageId] ?? null;
        if (is_array($stageData) && isset($stageData[$propertyCode])) {
            return $this->normalizeVariantsFromStore($stageData[$propertyCode], $offersIblockId);
        }

        $stageFromStore = $this->findStageInStore($stageId);
        if ($stageFromStore === null) {
            return [];
        }
        
        $properties = $stageFromStore['properties'] ?? [];
        if (!isset($properties[$propertyCode])) {
            return [];
        }

        return $this->normalizeVariantsFromStore($properties[$propertyCode]['VALUE'] ?? [], $offersIblockId);
    }

    private function normalizeVariantsFromStore($value, int $offersIblockId): array
    {
        if (is_array($value) && isset($value[0]) && is_array($value[0]) && isset($value[0]['id'])) {
            return $value;
        }

        $ids = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                $itemId = (int)$item;
                if ($itemId > 0) {
                    $ids[] = $itemId;
                }
            }
        } else {
            $itemId = (int)$value;
            if ($itemId > 0) {
                $ids[] = $itemId;
            }
        }

        if (empty($ids) || $offersIblockId <= 0) {
            return [];
        }

        return $this->loadOfferElements($offersIblockId, $ids);
    }

    /**
     * Резервный сбор parentId из списка элементов.
     */
    private function collectParentIdsFromStore(array $elements): array
    {
        $parentIds = [];

        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $parentId = (int)($element['productId'] ?? 0);
            if ($parentId <= 0) {
                $parentId = (int)($element['id'] ?? 0);
            }

            if ($parentId > 0) {
                $parentIds[] = $parentId;
            }
        }

        return array_values(array_unique($parentIds));
    }

    private function findStageInStore(int $stageId): ?array
    {
        foreach ($this->elementsStore['CALC_STAGES'] ?? [] as $stage) {
            if ((int)($stage['id'] ?? 0) === $stageId) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Получить список родительских элементов для выбранных ТП.
     */
    private function collectParentIdsFromOffers(array $offers): array
    {
        $parentIds = [];

        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }

            $parentId = $this->getParentIdFromOffer($offer);
            if ($parentId > 0) {
                $parentIds[] = $parentId;
            }
        }

        return array_values(array_unique($parentIds));
    }

    /**
     * Получить parentId из элемента ТП.
     */
    private function getParentIdFromOffer(array $offer): int
    {
        $parentId = (int)($offer['productId'] ?? 0);
        if ($parentId > 0) {
            return $parentId;
        }

        $properties = $offer['properties'] ?? [];
        if (isset($properties['CML2_LINK']['VALUE'])) {
            $parentId = (int)$properties['CML2_LINK']['VALUE'];
            if ($parentId > 0) {
                return $parentId;
            }
        }

        if (isset($properties['CML2_LINK']['VALUE_ID'])) {
            $parentId = (int)$properties['CML2_LINK']['VALUE_ID'];
            if ($parentId > 0) {
                return $parentId;
            }
        }

        $fields = $offer['fields'] ?? [];
        if (isset($fields['CML2_LINK'])) {
            $parentId = (int)$fields['CML2_LINK'];
            if ($parentId > 0) {
                return $parentId;
            }
        }

        return 0;
    }

    /**
     * Получить список offerId для parentId (объединенный).
     */
    private function loadSiblingOfferIds(int $productIblockId, array $parentIds): array
    {
        if ($productIblockId <= 0 || empty($parentIds)) {
            return [];
        }

        $offersData = \CCatalogSKU::getOffersList(
            $parentIds,
            $productIblockId,
            [],
            ['ID'],
            ['ID']
        );

        $offerIds = [];
        if (is_array($offersData)) {
            foreach ($offersData as $offersByParent) {
                foreach ($offersByParent as $offer) {
                    $offerId = (int)($offer['ID'] ?? 0);
                    if ($offerId > 0) {
                        $offerIds[] = $offerId;
                    }
                }
            }
        }

        return array_values(array_unique($offerIds));
    }

    /**
     * Загрузить элементы ТП в формате elementsStore.
     */
    private function loadOfferElements(int $offersIblockId, array $offerIds): array
    {
        if ($offersIblockId <= 0 || empty($offerIds)) {
            return [];
        }
        
        $elementDataService = new ElementDataService();
        
        // Загружаем данные через ElementDataService (формат как в elementsStore)
        $payload = $elementDataService->prepareRefreshPayload([
            [
                'iblockId' => $offersIblockId,
                'iblockType' => null,
                'ids' => $offerIds,
                'includeParent' => false,
            ],
        ]);
        
        return $payload[0]['data'] ?? [];
    }
}
