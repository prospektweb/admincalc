<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Services\DetailHandler;

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
        if ($presetsIblockId <= 0) {
            throw new \Exception('Инфоблок CALC_PRESETS не настроен');
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
            $detailHandler = new DetailHandler();
            $propertyValues = $this->getElementPropertyValuesForClone($presetId, $presetsIblockId);

            // 1. Определяем корневые детали — те, что НЕ являются вложенными в скрепления
            $originalDetailIds = $this->normalizeToIntArray($propertyValues['CALC_DETAILS'] ?? []);
            $nestedDetailIds = $this->collectNestedDetailIds($originalDetailIds);
            $rootDetailIds = array_values(array_diff($originalDetailIds, $nestedDetailIds));

            // 2. Клонируем ТОЛЬКО корневые детали (скрепления рекурсивно клонируют свои вложенные)
            $clonedDetailIds = [];
            $allClonedConfigIds = [];
            $detailIdMap = []; // оригинал → клон (для маппинга вложенных)

            foreach ($rootDetailIds as $detailId) {
                $cloneResult = $detailHandler->cloneDetail(['detailId' => $detailId]);
                if (($cloneResult['status'] ?? 'error') !== 'ok') {
                    throw new \Exception((string)($cloneResult['message'] ?? 'Не удалось клонировать детали пресета'));
                }
                $clonedDetailIds[] = (int)$cloneResult['detail']['id'];

                // Собираем ID всех склонированных этапов
                if (!empty($cloneResult['createdConfigIds'])) {
                    foreach ($cloneResult['createdConfigIds'] as $configId) {
                        $allClonedConfigIds[] = (int)$configId;
                    }
                }
            }

            // 3. Определяем собственные этапы пресета (не принадлежащие деталям)
            $originalStageIds = $this->normalizeToIntArray($propertyValues['CALC_STAGES'] ?? []);
            $detailStageIds = $this->collectAllDetailStageIds($presetId, $presetsIblockId);
            $presetOnlyStageIds = array_values(array_diff($originalStageIds, $detailStageIds));

            // 4. Клонируем ТОЛЬКО собственные этапы пресета
            $clonedPresetOnlyStageIds = [];
            foreach ($presetOnlyStageIds as $stageId) {
                $newStageId = $this->cloneStageElement($stageId, $presetsIblockId);
                if ($newStageId) {
                    $clonedPresetOnlyStageIds[] = $newStageId;
                }
            }

            // 5. Итоговые CALC_STAGES = этапы из деталей + собственные этапы пресета
            $finalStageIds = array_merge($allClonedConfigIds, $clonedPresetOnlyStageIds);

            // 6. Подменяем свойства на клонированные ID
            $propertyValues['CALC_DETAILS'] = $clonedDetailIds;
            $propertyValues['CALC_STAGES'] = !empty($finalStageIds) ? $finalStageIds : [];

            \CIBlockElement::SetPropertyValuesEx($newPresetId, $presetsIblockId, $propertyValues);

            // 7. Клонируем данные торгового каталога (цены, НДС, валюта)
            $this->cloneCatalogData($presetId, $newPresetId);

            return $newPresetId;
        } catch (\Throwable $e) {
            \CIBlockElement::Delete($newPresetId);
            throw $e;
        }
        
    }

    /**
     * Рекурсивно собрать все ID этапов (CALC_STAGES) из деталей пресета.
     * Нужно для определения, какие этапы принадлежат деталям, а какие — пресету.
     *
     * @param int $presetId ID пресета
     * @param int $presetsIblockId ID инфоблока пресетов
     * @return int[] Все ID этапов из всех деталей/скреплений
     */
    private function collectAllDetailStageIds(int $presetId, int $presetsIblockId): array
    {
        $detailsIblockId = $this->configManager->getIblockId('CALC_DETAILS');

        // Получаем ID деталей пресета
        $detailIds = [];
        $rs = \CIBlockElement::GetProperty($presetsIblockId, $presetId, [], ['CODE' => 'CALC_DETAILS']);
        while ($prop = $rs->Fetch()) {
            if (!empty($prop['VALUE'])) {
                $detailIds[] = (int)$prop['VALUE'];
            }
        }

        $allStageIds = [];
        foreach ($detailIds as $detailId) {
            $this->collectStageIdsRecursive($detailId, $detailsIblockId, $allStageIds);
        }

        return $allStageIds;
    }

    /**
     * Собрать ID деталей, которые вложены в скрепления (BINDING).
     * Эти детали НЕ нужно клонировать отдельно — они клонируются рекурсивно
     * при клонировании родительского скрепления.
     *
     * @param int[] $detailIds Все ID деталей из CALC_DETAILS пресета
     * @return int[] ID деталей, вложенных в скрепления
     */
    private function collectNestedDetailIds(array $detailIds): array
    {
        $detailsIblockId = $this->configManager->getIblockId('CALC_DETAILS');
        $nested = [];

        foreach ($detailIds as $detailId) {
            $element = \CIBlockElement::GetList(
                [],
                ['ID' => $detailId, 'IBLOCK_ID' => $detailsIblockId],
                false,
                ['nTopCount' => 1],
                ['ID']
            )->GetNextElement();

            if (!$element) {
                continue;
            }

            $properties = $element->GetProperties();
            $type = $properties['TYPE']['VALUE_XML_ID'] ?? 'DETAIL';

            if ($type === 'BINDING') {
                // Рекурсивно собираем все вложенные ID
                $this->collectChildDetailIds($detailId, $detailsIblockId, $nested);
            }
        }

        return $nested;
    }

    /**
     * Рекурсивно собрать ID всех дочерних деталей скрепления.
     */
    private function collectChildDetailIds(int $parentId, int $detailsIblockId, array &$result): void
    {
        $element = \CIBlockElement::GetList(
            [],
            ['ID' => $parentId, 'IBLOCK_ID' => $detailsIblockId],
            false,
            ['nTopCount' => 1],
            ['ID']
        )->GetNextElement();

        if (!$element) {
            return;
        }

        $properties = $element->GetProperties();
        $childIds = $properties['DETAILS']['VALUE'] ?? [];
        if (!is_array($childIds)) {
            $childIds = !empty($childIds) ? [$childIds] : [];
        }

        foreach ($childIds as $childId) {
            $childId = (int)$childId;
            $result[] = $childId;
            // Рекурсия — если дочерний элемент тоже скрепление
            $this->collectChildDetailIds($childId, $detailsIblockId, $result);
        }
    }

    /**
     * Рекурсивно собрать все CALC_STAGES из деталей пресета.
     */
    private function collectAllDetailStageIds(int $presetId, int $presetsIblockId): array
    {
        $detailsIblockId = $this->configManager->getIblockId('CALC_DETAILS');

        $detailIds = [];
        $rs = \CIBlockElement::GetProperty($presetsIblockId, $presetId, [], ['CODE' => 'CALC_DETAILS']);
        while ($prop = $rs->Fetch()) {
            if (!empty($prop['VALUE'])) {
                $detailIds[] = (int)$prop['VALUE'];
            }
        }

        $allStageIds = [];
        foreach ($detailIds as $detailId) {
            $this->collectStageIdsRecursive($detailId, $detailsIblockId, $allStageIds);
        }

        return $allStageIds;
    }

    /**
     * Рекурсивно собрать CALC_STAGES из детали и её вложенных деталей.
     */
    private function collectStageIdsRecursive(int $detailId, int $detailsIblockId, array &$result): void
    {
        $element = \CIBlockElement::GetList(
            [],
            ['ID' => $detailId, 'IBLOCK_ID' => $detailsIblockId],
            false,
            ['nTopCount' => 1],
            ['ID']
        )->GetNextElement();

        if (!$element) {
            return;
        }

        $properties = $element->GetProperties();

        $stageValues = $properties['CALC_STAGES']['VALUE'] ?? [];
        if (!is_array($stageValues)) {
            $stageValues = !empty($stageValues) ? [$stageValues] : [];
        }
        foreach ($stageValues as $stageId) {
            $result[] = (int)$stageId;
        }

        $childIds = $properties['DETAILS']['VALUE'] ?? [];
        if (!is_array($childIds)) {
            $childIds = !empty($childIds) ? [$childIds] : [];
        }
        foreach ($childIds as $childId) {
            $this->collectStageIdsRecursive((int)$childId, $detailsIblockId, $result);
        }
    }

    /**
     * Рекурсивно собрать CALC_STAGES из детали и её вложенных деталей.
     */
    private function collectStageIdsRecursive(int $detailId, int $detailsIblockId, array &$result): void
    {
        $element = \CIBlockElement::GetList(
            [],
            ['ID' => $detailId, 'IBLOCK_ID' => $detailsIblockId],
            false,
            ['nTopCount' => 1],
            ['ID']
        )->GetNextElement();

        if (!$element) {
            return;
        }

        $properties = $element->GetProperties();

        // Собираем CALC_STAGES этой детали
        $stageValues = $properties['CALC_STAGES']['VALUE'] ?? [];
        if (!is_array($stageValues)) {
            $stageValues = !empty($stageValues) ? [$stageValues] : [];
        }
        foreach ($stageValues as $stageId) {
            $result[] = (int)$stageId;
        }

        // Рекурсивно обрабатываем вложенные детали (DETAILS) для скреплений
        $detailValues = $properties['DETAILS']['VALUE'] ?? [];
        if (!is_array($detailValues)) {
            $detailValues = !empty($detailValues) ? [$detailValues] : [];
        }
        foreach ($detailValues as $childDetailId) {
            $this->collectStageIdsRecursive((int)$childDetailId, $detailsIblockId, $result);
        }
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

        // 1. Копируем данные товара (НДС, вес, единицы измерения и т.д.)
        $product = \Bitrix\Catalog\ProductTable::getRow([
            'filter' => ['=ID' => $sourceId],
            'select' => ['*'],
        ]);

        if ($product) {
            // Проверяем, существует ли запись каталога для нового элемента
            $existingProduct = \Bitrix\Catalog\ProductTable::getRow([
                'filter' => ['=ID' => $targetId],
                'select' => ['ID'],
            ]);

            $catalogFields = [
                'VAT_ID' => $product['VAT_ID'],
                'VAT_INCLUDED' => $product['VAT_INCLUDED'],
                'QUANTITY' => $product['QUANTITY'] ?? 0,
                'WEIGHT' => $product['WEIGHT'] ?? 0,
                'WIDTH' => $product['WIDTH'] ?? 0,
                'LENGTH' => $product['LENGTH'] ?? 0,
                'HEIGHT' => $product['HEIGHT'] ?? 0,
                'MEASURE' => $product['MEASURE'] ?? null,
                'PURCHASING_PRICE' => $product['PURCHASING_PRICE'],
                'PURCHASING_CURRENCY' => $product['PURCHASING_CURRENCY'],
            ];

            if ($existingProduct) {
                \Bitrix\Catalog\ProductTable::update($targetId, $catalogFields);
            } else {
                $catalogFields['ID'] = $targetId;
                \Bitrix\Catalog\ProductTable::add($catalogFields);
            }
        }

        // 2. Копируем цены
        $dbPrices = \Bitrix\Catalog\PriceTable::getList([
            'filter' => ['=PRODUCT_ID' => $sourceId],
            'select' => ['CATALOG_GROUP_ID', 'PRICE', 'CURRENCY', 'QUANTITY_FROM', 'QUANTITY_TO'],
        ]);

        while ($price = $dbPrices->fetch()) {
            // Удаляем старые цены этого типа для нового элемента (на случай повторных вызовов)
            $existingPrices = \Bitrix\Catalog\PriceTable::getList([
                'filter' => [
                    '=PRODUCT_ID' => $targetId,
                    '=CATALOG_GROUP_ID' => $price['CATALOG_GROUP_ID'],
                ],
                'select' => ['ID'],
            ]);

            while ($existing = $existingPrices->fetch()) {
                \Bitrix\Catalog\PriceTable::delete($existing['ID']);
            }

            \Bitrix\Catalog\PriceTable::add([
                'PRODUCT_ID' => $targetId,
                'CATALOG_GROUP_ID' => $price['CATALOG_GROUP_ID'],
                'PRICE' => $price['PRICE'],
                'CURRENCY' => $price['CURRENCY'],
                'QUANTITY_FROM' => $price['QUANTITY_FROM'],
                'QUANTITY_TO' => $price['QUANTITY_TO'],
            ]);
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
