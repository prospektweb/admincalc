<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Обработчик операций со сборками (bundles)
 */
class BundleHandler
{
    private const MODULE_ID = 'prospektweb.calc';
    
    private ConfigManager $configManager;
    
    public function __construct()
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix iblock');
        }
        $this->configManager = new ConfigManager();
    }
    
    /**
     * Создать новый preset (постоянный)
     * Вместо временного bundle теперь всегда создаём постоянный preset.
     * 
     * @param array $offerIds ID торговых предложений
     * @param string|null $name Название preset'а (опционально)
     * @return int ID созданного preset'а
     * @throws \Exception
     */
    public function createPreset(array $offerIds, ?string $name = null): int
    {
        // 1. Получить ID товара из первого ТП (все ТП принадлежат одному товару)
        $productId = $this->getProductIdFromOffer($offerIds[0]);
        
        if ($productId <= 0) {
            throw new \Exception('Не удалось определить товар для ТП');
        }
        
        $iblockId = $this->configManager->getIblockId('CALC_PRESETS');
        
        if ($iblockId <= 0) {
            throw new \Exception('Инфоблок CALC_PRESETS не настроен');
        }
        
        // 2. Создать элемент пресета
        $el = new \CIBlockElement();
        $presetName = $name ?: 'Новый пресет ' . date('Y-m-d H:i:s');
        $presetId = $el->Add([
            'IBLOCK_ID' => $iblockId,
            'NAME' => $presetName,
            'CODE' => $this->generateUniqueElementCode($iblockId, $presetName),
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => [
                'JSON' => ['VALUE' => ['TEXT' => '{}', 'TYPE' => 'HTML']],
            ],
        ]);
        
        if (!$presetId) {
            throw new \Exception('Ошибка создания пресета: ' . $el->LAST_ERROR);
        }
        
        // 3. Привязать пресет к ТОВАРУ (не к ТП!)
        $productIblockId = $this->configManager->getProductIblockId();
        
        \CIBlockElement::SetPropertyValuesEx($productId, $productIblockId, [
            'CALC_PRESET' => $presetId,
        ]);
        
        return (int)$presetId;
    }

    /**
     * Клонировать пресет вместе со всеми деталями/этапами.
     *
     * @param int $presetId ID исходного пресета
     * @return int ID нового пресета
     * @throws \Exception
     */
    public function clonePreset(int $presetId): int
    {
        if ($presetId <= 0) {
            throw new \Exception('presetId не указан');
        }

        $presetsIblockId = $this->configManager->getIblockId('CALC_PRESETS');
        $detailsIblockId = $this->configManager->getIblockId('CALC_DETAILS');
        if ($presetsIblockId <= 0 || $detailsIblockId <= 0) {
            throw new \Exception('Инфоблоки CALC_PRESETS/CALC_DETAILS не настроены');
        }

        $original = \CIBlockElement::GetList(
            [],
            ['ID' => $presetId, 'IBLOCK_ID' => $presetsIblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME', 'ACTIVE', 'SORT', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE']
        )->Fetch();

        if (!$original) {
            throw new \Exception('Пресет не найден');
        }

        $newPresetName = sprintf('%s (копия %s)', $original['NAME'], date('d.m.Y H:i:s'));
        $newPresetId = (new \CIBlockElement())->Add([
            'IBLOCK_ID' => $presetsIblockId,
            'NAME' => $newPresetName,
            'CODE' => $this->generateUniqueElementCode($presetsIblockId, $newPresetName),
            'ACTIVE' => $original['ACTIVE'] ?? 'Y',
            'SORT' => (int)($original['SORT'] ?? 500),
            'PREVIEW_TEXT' => $original['PREVIEW_TEXT'] ?? '',
            'PREVIEW_TEXT_TYPE' => $original['PREVIEW_TEXT_TYPE'] ?? 'text',
            'DETAIL_TEXT' => $original['DETAIL_TEXT'] ?? '',
            'DETAIL_TEXT_TYPE' => $original['DETAIL_TEXT_TYPE'] ?? 'text',
        ]);

        if (!$newPresetId) {
            throw new \Exception('Ошибка создания клона пресета');
        }

        $newPresetId = (int)$newPresetId;

        try {
            $propertyValues = $this->getElementPropertyValuesForClone($presetId, $presetsIblockId);
            $rootDetailIds = $this->normalizeToIntArray($propertyValues['CALC_DETAILS'] ?? []);

            $detailGraph = [];
            $detailOrder = [];
            $this->collectDetailGraph($rootDetailIds, $detailsIblockId, $detailGraph, $detailOrder, []);

            // Шаг 1: единоразово клонируем все этапы (у деталей, скреплений и пресета).
            $stageIdsToClone = $this->collectStageIdsFromGraph($detailGraph);
            foreach ($this->normalizeToIntArray($propertyValues['CALC_STAGES'] ?? []) as $presetStageId) {
                if (!in_array($presetStageId, $stageIdsToClone, true)) {
                    $stageIdsToClone[] = $presetStageId;
                }
            }

            $stageMap = [];
            foreach ($stageIdsToClone as $stageId) {
                $newStageId = $this->cloneStageElement($stageId, $presetsIblockId);
                if (!$newStageId) {
                    throw new \Exception('Не удалось клонировать этап ID=' . $stageId);
                }
                $stageMap[$stageId] = (int)$newStageId;
            }

            // Шаг 2: клонируем только детали (TYPE=DETAIL).
            $detailMap = [];
            foreach ($detailOrder as $detailId) {
                $node = $detailGraph[$detailId] ?? null;
                if (!$node || $node['type'] !== 'DETAIL') {
                    continue;
                }
                $detailMap[$detailId] = $this->cloneDetailNode($node, $detailsIblockId, $stageMap, []);
            }

            // Шаг 3: клонируем скрепления (TYPE=BINDING) без DETAILS, затем проставляем DETAILS по карте.
            foreach ($detailOrder as $detailId) {
                $node = $detailGraph[$detailId] ?? null;
                if (!$node || $node['type'] !== 'BINDING') {
                    continue;
                }
                $detailMap[$detailId] = $this->cloneDetailNode($node, $detailsIblockId, $stageMap, false);
            }

            foreach ($detailOrder as $detailId) {
                $node = $detailGraph[$detailId] ?? null;
                if (!$node || $node['type'] !== 'BINDING') {
                    continue;
                }

                $mappedChildIds = [];
                foreach ($node['detailIds'] as $childId) {
                    if (!isset($detailMap[$childId])) {
                        throw new \Exception('Некорректное клонирование: отсутствует клон дочерней детали ID=' . $childId);
                    }
                    $mappedChildIds[] = (int)$detailMap[$childId];
                }

                \CIBlockElement::SetPropertyValuesEx((int)$detailMap[$detailId], $detailsIblockId, [
                    'DETAILS' => !empty($mappedChildIds)
                        ? array_map(static function (int $id): array {
                            return ['VALUE' => $id, 'DESCRIPTION' => ''];
                        }, $mappedChildIds)
                        : false,
                ]);
            }

            // Валидация от дублирования клонирования
            if (count($detailMap) !== count($detailGraph)) {
                throw new \Exception('Обнаружено дублирование/потеря при клонировании деталей: ожидалось '
                    . count($detailGraph) . ', создано ' . count($detailMap));
            }

            // Шаг 4: клонируем пресетные ссылки на детали и этапы с сохранением последовательности.
            $mappedRootDetailIds = [];
            foreach ($rootDetailIds as $rootDetailId) {
                if (!isset($detailMap[$rootDetailId])) {
                    throw new \Exception('Не найден клон корневой детали ID=' . $rootDetailId);
                }
                $mappedRootDetailIds[] = (int)$detailMap[$rootDetailId];
            }

            $mappedPresetStageIds = $this->mapIdListOrFail(
                $this->normalizeToIntArray($propertyValues['CALC_STAGES'] ?? []),
                $stageMap,
                'этапов пресета'
            );

            $propertyValues['CALC_DETAILS'] = !empty($mappedRootDetailIds) ? $mappedRootDetailIds : false;
            $propertyValues['CALC_STAGES'] = !empty($mappedPresetStageIds) ? $mappedPresetStageIds : false;

            \CIBlockElement::SetPropertyValuesEx($newPresetId, $presetsIblockId, $propertyValues);

            // Шаг 7: копируем товарный каталог (НДС/закупочная/валюта/цены).
            $this->cloneCatalogData($presetId, $newPresetId);

            return $newPresetId;
        } catch (\Throwable $e) {
            \CIBlockElement::Delete($newPresetId);
            throw $e;
        }
    }

    /**
     * Рекурсивно загрузить граф деталей (деталь/скрепление) в порядке обхода.
     *
     * @param int[] $detailIds
     * @param array<int, array> $graph
     * @param int[] $order
     * @param array<int, bool> $stack
     */
    private function collectDetailGraph(array $detailIds, int $detailsIblockId, array &$graph, array &$order, array $stack): void
    {
        foreach ($detailIds as $detailId) {
            $detailId = (int)$detailId;
            if ($detailId <= 0) {
                continue;
            }

            if (isset($stack[$detailId])) {
                throw new \Exception('Обнаружена циклическая ссылка в DETAILS для ID=' . $detailId);
            }

            if (isset($graph[$detailId])) {
                continue;
            }

            $element = \CIBlockElement::GetList(
                [],
                ['ID' => $detailId, 'IBLOCK_ID' => $detailsIblockId],
                false,
                ['nTopCount' => 1],
                ['ID', 'NAME', 'ACTIVE', 'SORT', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE']
            )->GetNextElement();

            if (!$element) {
                throw new \Exception('Не найдена деталь/скрепление ID=' . $detailId);
            }

            $fields = $element->GetFields();
            $properties = $element->GetProperties();
            $type = (string)($properties['TYPE']['VALUE_XML_ID'] ?? 'DETAIL');
            $childIds = $this->normalizeToIntArray($properties['DETAILS']['VALUE'] ?? []);
            $stageIds = $this->normalizeToIntArray($properties['CALC_STAGES']['VALUE'] ?? []);

            if ($type !== 'DETAIL' && $type !== 'BINDING') {
                throw new \Exception('Некорректный TYPE для ID=' . $detailId . ': ' . $type);
            }

            if ($type === 'DETAIL' && !empty($childIds)) {
                throw new \Exception('Некорректные данные: TYPE=DETAIL содержит DETAILS для ID=' . $detailId);
            }

            $graph[$detailId] = [
                'id' => $detailId,
                'type' => $type,
                'fields' => $fields,
                'stageIds' => $stageIds,
                'detailIds' => $childIds,
            ];
            $order[] = $detailId;

            $stack[$detailId] = true;
            if (!empty($childIds)) {
                $this->collectDetailGraph($childIds, $detailsIblockId, $graph, $order, $stack);
            }
        }
    }

    /**
     * @param array<int, array> $graph
     * @return int[]
     */
    private function collectStageIdsFromGraph(array $graph): array
    {
        $result = [];
        foreach ($graph as $node) {
            foreach (($node['stageIds'] ?? []) as $stageId) {
                $stageId = (int)$stageId;
                if ($stageId > 0 && !in_array($stageId, $result, true)) {
                    $result[] = $stageId;
                }
            }
        }
    }

    /**
     * @param array<int, array> $graph
     * @return int[]
     */
    private function collectStageIdsFromGraph(array $graph): array
    {
        $result = [];
        foreach ($graph as $node) {
            foreach (($node['stageIds'] ?? []) as $stageId) {
                $stageId = (int)$stageId;
                if ($stageId > 0 && !in_array($stageId, $result, true)) {
                    $result[] = $stageId;
                }
            }

        return $result;
    }

    /**
     * @param array $node Узел графа детали/скрепления
     * @param array<int, int> $stageMap Карта oldStageId => newStageId
     * @param array|false $bindingDetailsValue Значение DETAILS для скрепления (false на первом проходе)
     */
    private function cloneDetailNode(array $node, int $detailsIblockId, array $stageMap, $bindingDetailsValue): int
    {
        $oldId = (int)($node['id'] ?? 0);
        $fields = $node['fields'] ?? [];

        $newId = (new \CIBlockElement())->Add([
            'IBLOCK_ID' => $detailsIblockId,
            'NAME' => (string)($fields['NAME'] ?? ('Копия ' . $oldId)),
            'CODE' => $this->generateUniqueElementCode($detailsIblockId, (string)($fields['NAME'] ?? ('detail-' . $oldId))),
            'ACTIVE' => $fields['ACTIVE'] ?? 'Y',
            'SORT' => (int)($fields['SORT'] ?? 500),
            'PREVIEW_TEXT' => $fields['PREVIEW_TEXT'] ?? '',
            'PREVIEW_TEXT_TYPE' => $fields['PREVIEW_TEXT_TYPE'] ?? 'text',
            'DETAIL_TEXT' => $fields['DETAIL_TEXT'] ?? '',
            'DETAIL_TEXT_TYPE' => $fields['DETAIL_TEXT_TYPE'] ?? 'text',
        ]);

        if (!$newId) {
            throw new \Exception('Ошибка создания клона детали/скрепления ID=' . $oldId);
        }

        $newId = (int)$newId;
        $propertyValues = $this->getElementPropertyValuesForClone($oldId, $detailsIblockId);

        $type = (string)($node['type'] ?? 'DETAIL');
        $typeEnumId = $this->getListPropertyEnumId($detailsIblockId, 'TYPE', $type);
        if ($typeEnumId <= 0) {
            throw new \Exception('Не найдено значение TYPE=' . $type . ' для детали ID=' . $oldId);
        }

        $mappedStageIds = $this->mapIdListOrFail(
            $this->normalizeToIntArray($node['stageIds'] ?? []),
            $stageMap,
            'этапов детали ID=' . $oldId
        );
        $propertyValues['TYPE'] = ['VALUE' => $typeEnumId, 'DESCRIPTION' => ''];
        $propertyValues['CALC_STAGES'] = !empty($mappedStageIds)
            ? array_map(static function (int $id): array {
                return ['VALUE' => $id, 'DESCRIPTION' => ''];
            }, $mappedStageIds)
            : false;

        if ($type === 'BINDING') {
            if (is_array($bindingDetailsValue)) {
                $propertyValues['DETAILS'] = array_map(static function (int $id): array {
                    return ['VALUE' => $id, 'DESCRIPTION' => ''];
                }, array_values(array_map('intval', $bindingDetailsValue)));
            } else {
                $propertyValues['DETAILS'] = false;
            }
        } else {
            $propertyValues['DETAILS'] = false;
        }

        \CIBlockElement::SetPropertyValuesEx($newId, $detailsIblockId, $propertyValues);

        return $newId;
    }

    /**
     * @param int[] $sourceIds
     * @param array<int, int> $idMap
     * @return int[]
     */
    private function mapIdListOrFail(array $sourceIds, array $idMap, string $context): array
    {
        $result = [];
        foreach ($sourceIds as $sourceId) {
            $sourceId = (int)$sourceId;
            if ($sourceId <= 0) {
                continue;
            }
            if (!isset($idMap[$sourceId])) {
                throw new \Exception('Не найден клон для ID=' . $sourceId . ' в контексте ' . $context);
            }
            $result[] = (int)$idMap[$sourceId];
        }

        return $result;
    }

    private function getListPropertyEnumId(int $iblockId, string $propertyCode, string $xmlId): int
    {
        $enum = \CIBlockPropertyEnum::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode, 'XML_ID' => $xmlId]
        )->Fetch();

        return (int)($enum['ID'] ?? 0);
    }

    /**
     * Получить ID товара из ТП
     * 
     * @param int $offerId ID торгового предложения
     * @return int ID товара
     */
    private function getProductIdFromOffer(int $offerId): int
    {
        $skuIblockId = $this->configManager->getSkuIblockId();
        
        $rsOffer = \CIBlockElement::GetList(
            [],
            ['ID' => $offerId, 'IBLOCK_ID' => $skuIblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'PROPERTY_CML2_LINK']
        );
        
        if ($offer = $rsOffer->Fetch()) {
            return (int)($offer['PROPERTY_CML2_LINK_VALUE'] ?? 0);
        }
        
        return 0;
    }

    private function getElementPropertyValuesForClone(int $elementId, int $iblockId): array
    {
        $result = [];

        $dbProps = \CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc', 'id' => 'asc'], []);
        while ($prop = $dbProps->Fetch()) {
            $code = (string)($prop['CODE'] ?? '');
            if ($code === '') {
                continue;
            }

            $value = $prop['VALUE'];
            if (($prop['PROPERTY_TYPE'] ?? '') === 'L') {
                $value = (int)($prop['VALUE_ENUM_ID'] ?? $prop['VALUE']);
            }
            if (($prop['PROPERTY_TYPE'] ?? '') === 'S' && ($prop['USER_TYPE'] ?? '') === 'HTML') {
                $value = ['TEXT' => (string)($prop['~VALUE']['TEXT'] ?? $prop['VALUE']), 'TYPE' => (string)($prop['VALUE_TYPE'] ?? 'text')];
            }

            if (($prop['MULTIPLE'] ?? 'N') === 'Y') {
                if (!array_key_exists($code, $result) || !is_array($result[$code])) {
                    $result[$code] = [];
                }
                $result[$code][] = $value;
            } else {
                $result[$code] = $value;
            }
        }

        return $result;
    }

    private function normalizeToIntArray($value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        return array_values(array_filter(array_map('intval', $value), static function ($id) {
            return $id > 0;
        }));
    }

    /**
     * Клонировать элемент этапа (CALC_STAGES) для пресета.
     *
     * @param int $stageId ID оригинального этапа
     * @param int $presetsIblockId ID инфоблока пресетов (не используется напрямую, но нужен для контекста)
     * @return int|null ID нового этапа или null при ошибке
     */
    private function cloneStageElement(int $stageId, int $presetsIblockId): ?int
    {
        $stagesIblockId = $this->configManager->getIblockId('CALC_STAGES');
        if ($stagesIblockId <= 0) {
            return null;
        }

        $element = \CIBlockElement::GetList(
            [],
            ['ID' => $stageId, 'IBLOCK_ID' => $stagesIblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME', 'ACTIVE', 'SORT', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE']
        )->GetNextElement();

        if (!$element) {
            return null;
        }

        $fields = $element->GetFields();

        $el = new \CIBlockElement();
        $newFields = [
            'IBLOCK_ID' => $stagesIblockId,
            'NAME' => $fields['NAME'],
            'CODE' => $this->generateUniqueElementCode($stagesIblockId, $fields['NAME']),
            'ACTIVE' => $fields['ACTIVE'] ?? 'Y',
            'SORT' => $fields['SORT'] ?? 500,
        ];

        if (!empty($fields['PREVIEW_TEXT'])) {
            $newFields['PREVIEW_TEXT'] = $fields['PREVIEW_TEXT'];
            $newFields['PREVIEW_TEXT_TYPE'] = $fields['PREVIEW_TEXT_TYPE'] ?? 'text';
        }

        if (!empty($fields['DETAIL_TEXT'])) {
            $newFields['DETAIL_TEXT'] = $fields['DETAIL_TEXT'];
            $newFields['DETAIL_TEXT_TYPE'] = $fields['DETAIL_TEXT_TYPE'] ?? 'text';
        }

        $newId = $el->Add($newFields);
        if (!$newId) {
            return null;
        }

        $newId = (int)$newId;

        // Копируем все свойства этапа
        $propValues = $this->getElementPropertyValuesForClone($stageId, $stagesIblockId);
        if (!empty($propValues)) {
            \CIBlockElement::SetPropertyValuesEx($newId, $stagesIblockId, $propValues);
        }

        return $newId;
    }

        /**
     * Клонировать данные торгового каталога (цены, НДС, валюта) из одного элемента в другой.
     *
     * @param int $sourceId ID исходного элемента
     * @param int $targetId ID целевого элемента
     */
    private function cloneCatalogData(int $sourceId, int $targetId): void
    {
        if (!Loader::includeModule('catalog')) {
            return;
        }

        // 1) Копирование каталожной карточки (VAT, purchasing, currency и т.д.)
        if (class_exists('\CCatalogProduct')) {
            $sourceProduct = \CCatalogProduct::GetByID($sourceId);
            if ($sourceProduct) {
                $targetProduct = \CCatalogProduct::GetByID($targetId);
                $productFields = $sourceProduct;
                unset($productFields['ID']);

                if ($targetProduct) {
                    \CCatalogProduct::Update($targetId, $productFields);
                } else {
                    $productFields['ID'] = $targetId;
                    \CCatalogProduct::Add($productFields);
                }
            }
        } elseif (class_exists('\Bitrix\Catalog\ProductTable')) {
            $sourceProduct = \Bitrix\Catalog\ProductTable::getRow([
                'filter' => ['=ID' => $sourceId],
                'select' => ['*'],
            ]);
            if ($sourceProduct) {
                $targetProduct = \Bitrix\Catalog\ProductTable::getRow([
                    'filter' => ['=ID' => $targetId],
                    'select' => ['ID'],
                ]);
                unset($sourceProduct['ID']);
                if ($targetProduct) {
                    \Bitrix\Catalog\ProductTable::update($targetId, $sourceProduct);
                } else {
                    $sourceProduct['ID'] = $targetId;
                    \Bitrix\Catalog\ProductTable::add($sourceProduct);
                }
            }
        }

        // 2) Полный перенос цен 1:1, включая диапазоны QUANTITY_FROM/QUANTITY_TO.
        if (class_exists('\CPrice')) {
            $existingTargetPrices = \CPrice::GetList([], ['PRODUCT_ID' => $targetId]);
            while ($existing = $existingTargetPrices->Fetch()) {
                \CPrice::Delete((int)$existing['ID']);
            }

            $sourcePrices = \CPrice::GetList(['ID' => 'ASC'], ['PRODUCT_ID' => $sourceId]);
            while ($price = $sourcePrices->Fetch()) {
                $priceFields = [
                    'PRODUCT_ID' => $targetId,
                    'CATALOG_GROUP_ID' => (int)$price['CATALOG_GROUP_ID'],
                    'PRICE' => (float)$price['PRICE'],
                    'CURRENCY' => (string)$price['CURRENCY'],
                    'QUANTITY_FROM' => $price['QUANTITY_FROM'] === null ? false : (int)$price['QUANTITY_FROM'],
                    'QUANTITY_TO' => $price['QUANTITY_TO'] === null ? false : (int)$price['QUANTITY_TO'],
                ];

                $extraId = isset($price['EXTRA_ID']) ? (int)$price['EXTRA_ID'] : 0;
                if ($extraId > 0) {
                    $priceFields['EXTRA_ID'] = $extraId;
                }

                if (!\CPrice::Add($priceFields)) {
                    throw new \Exception('Не удалось скопировать цену для группы ' . (int)$price['CATALOG_GROUP_ID']);
                }
            }
        } elseif (class_exists('\Bitrix\Catalog\PriceTable')) {
            $existingTargetPrices = \Bitrix\Catalog\PriceTable::getList([
                'filter' => ['=PRODUCT_ID' => $targetId],
                'select' => ['ID'],
            ]);
            while ($existing = $existingTargetPrices->fetch()) {
                \Bitrix\Catalog\PriceTable::delete((int)$existing['ID']);
            }

            $sourcePrices = \Bitrix\Catalog\PriceTable::getList([
                'order' => ['ID' => 'ASC'],
                'filter' => ['=PRODUCT_ID' => $sourceId],
                'select' => ['CATALOG_GROUP_ID', 'PRICE', 'CURRENCY', 'QUANTITY_FROM', 'QUANTITY_TO', 'EXTRA_ID'],
            ]);
            while ($price = $sourcePrices->fetch()) {
                $addRes = \Bitrix\Catalog\PriceTable::add([
                    'PRODUCT_ID' => $targetId,
                    'CATALOG_GROUP_ID' => (int)$price['CATALOG_GROUP_ID'],
                    'PRICE' => (float)$price['PRICE'],
                    'CURRENCY' => (string)$price['CURRENCY'],
                    'QUANTITY_FROM' => $price['QUANTITY_FROM'] === null ? null : (int)$price['QUANTITY_FROM'],
                    'QUANTITY_TO' => $price['QUANTITY_TO'] === null ? null : (int)$price['QUANTITY_TO'],
                    'EXTRA_ID' => isset($price['EXTRA_ID']) ? (int)$price['EXTRA_ID'] : null,
                ]);
                if (!$addRes->isSuccess()) {
                    throw new \Exception('Не удалось скопировать цену: ' . implode('; ', $addRes->getErrorMessages()));
                }
            }
        }
    }

    /**
     * Сохранить данные preset (SAVE_PRESET_REQUEST)
     * 
     * @param array $payload Данные от React
     * @return array Результат сохранения
     * @throws \Exception
     */
    public function savePreset(array $payload): array
    {
        $presetId = (int)($payload['presetId'] ?? 0);
        
        if ($presetId <= 0) {
            throw new \Exception('presetId не указан');
        }
        
        $linkedElements = $payload['linkedElements'] ?? [];
        $json = $payload['json'] ?? [];
        $meta = $payload['meta'] ?? [];
        
        // Формируем свойства для обновления
        $properties = $this->buildPropertyValues($linkedElements);
        
        // JSON с данными UI
        $jsonData = json_encode($json, JSON_UNESCAPED_UNICODE);
        $properties['JSON'] = ['VALUE' => ['TEXT' => $jsonData, 'TYPE' => 'HTML']];
        
        // Обновляем элемент
        $el = new \CIBlockElement();
        $fields = [
            'PROPERTY_VALUES' => $properties,
        ];
        
        if (!empty($meta['name'])) {
            $fields['NAME'] = $meta['name'];
        }
        
        if (!$el->Update($presetId, $fields)) {
            throw new \Exception('Ошибка сохранения пресета: ' . $el->LAST_ERROR);
        }
        
        return [
            'status' => 'ok',
            'presetId' => $presetId,
        ];
    }
    
    /**
     * Финализировать preset уже не требуется, т.к. создаются только постоянные пресеты.
     * Эта функция теперь может использоваться только для переименования preset'а.
     * 
     * @param int $presetId ID пресета
     * @param string|null $name Новое название (опционально)
     * @return array Результат
     * @throws \Exception
     */
    public function finalizePreset(int $presetId, ?string $name = null): array
    {
        if (!$name) {
            // Если имя не передано, ничего не делаем
            return [
                'status' => 'ok',
                'presetId' => $presetId,
                'finalized' => true,
            ];
        }
        
        $el = new \CIBlockElement();
        
        $fields = [
            'NAME' => $name,
        ];
        
        if (!$el->Update($presetId, $fields)) {
            throw new \Exception('Ошибка переименования пресета: ' . $el->LAST_ERROR);
        }
        
        return [
            'status' => 'ok',
            'presetId' => $presetId,
            'finalized' => true,
        ];
    }
    
    /**
     * Удалить preset и очистить привязки в ТОВАРЕ
     * 
     * @param int $presetId ID пресета
     */
    public function deletePreset(int $presetId): void
    {
        // Очищаем привязки в ТОВАРЕ (не в ТП!)
        $productIblockId = $this->configManager->getProductIblockId();
        
        if ($productIblockId > 0) {
            $rsProducts = \CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => $productIblockId, 'PROPERTY_CALC_PRESET' => $presetId],
                false,
                false,
                ['ID']
            );
            
            while ($arProduct = $rsProducts->Fetch()) {
                \CIBlockElement::SetPropertyValuesEx((int)$arProduct['ID'], $productIblockId, [
                    'CALC_PRESET' => false,
                ]);
            }
        }
        
        // Удаляем элемент preset
        \CIBlockElement::Delete($presetId);
    }
    
    /**
     * Загрузить краткую информацию о пресетах (для попапа предупреждения)
     * 
     * @param array $presetIds ID пресетов
     * @return array
     */
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

    public function loadPresetsSummary(array $presetIds): array
    {
        if (empty($presetIds)) {
            return [];
        }
        
        $iblockId = $this->configManager->getIblockId('CALC_PRESETS');
        $result = [];
        
        $rsElements = \CIBlockElement::GetList(
            [],
            ['ID' => $presetIds, 'IBLOCK_ID' => $iblockId],
            false,
            false,
            ['ID', 'NAME']
        );
        
        while ($arElement = $rsElements->Fetch()) {
            $result[(int)$arElement['ID']] = [
                'id' => (int)$arElement['ID'],
                'name' => $arElement['NAME'],
            ];
        }
        
        return $result;
    }
    
    /**
     * Собрать массив свойств из linkedElements
     * 
     * @param array $linkedElements Массив связанных элементов
     * @return array
     */
    private function buildPropertyValues(array $linkedElements): array
    {
        $map = [
            'calcConfig' => 'CALC_STAGES',
            'calcSettings' => 'CALC_SETTINGS',
            'materials' => 'CALC_MATERIALS',
            'materialsVariants' => 'CALC_MATERIALS_VARIANTS',
            'operations' => 'CALC_OPERATIONS',
            'operationsVariants' => 'CALC_OPERATIONS_VARIANTS',
            'equipment' => 'CALC_EQUIPMENT',
            'details' => 'CALC_DETAILS',
        ];
        
        $properties = [];
        
        foreach ($map as $jsKey => $propCode) {
            $ids = $linkedElements[$jsKey] ?? [];
            if (!is_array($ids)) {
                $ids = [$ids];
            }
            $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
            
            // Для множественных свойств Bitrix ожидает массив или false для очистки
            $properties[$propCode] = !empty($ids) ? $ids : false;
        }
        
        return $properties;
    }
}
