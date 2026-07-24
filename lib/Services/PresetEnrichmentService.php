<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Calculator\InitPayloadService;

/**
 * Сервис обогащения пресета связями на основе выбранных деталей
 */
class PresetEnrichmentService
{
    private const MODULE_ID = 'prospektweb.calc';

    private int $detailsIblockId;
    private int $stagesIblockId;
    private int $settingsIblockId;
    private int $presetsIblockId;
    private int $operationsVariantsIblockId;
    private int $materialsVariantsIblockId;

    public function __construct()
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix iblock');
        }

        $configManager = new ConfigManager();
        $this->detailsIblockId = $configManager->getIblockId('CALC_DETAILS');
        $this->stagesIblockId = $configManager->getIblockId('CALC_STAGES');
        $this->settingsIblockId = $configManager->getIblockId('CALC_SETTINGS');
        $this->presetsIblockId = $configManager->getIblockId('CALC_PRESETS');
        $this->operationsVariantsIblockId = $configManager->getIblockId('CALC_OPERATIONS_VARIANTS');
        $this->materialsVariantsIblockId = $configManager->getIblockId('CALC_MATERIALS_VARIANTS');
    }

    /**
     * Обогатить пресет связями на основе выбранной детали
     *
     * @param int $presetId ID пресета
     * @param int $rootDetailId ID корневой детали (может быть деталью или скреплением)
     * @param array $offerIds ID торговых предложений для построения INIT payload
     * @return array Обновлённый INIT payload
     * @throws \Exception
     */
    public function enrichPresetFromDetails(int $presetId, int $rootDetailId, array $offerIds = []): array
    {
        if ($presetId <= 0) {
            throw new \Exception('Некорректный ID пресета');
        }

        if ($rootDetailId <= 0) {
            throw new \Exception('Некорректный ID детали');
        }

        // Проверяем существование пресета
        $preset = $this->getPresetById($presetId);
        if (!$preset) {
            throw new \Exception('Пресет не найден');
        }

        // Собираем все связанные элементы рекурсивно
        $linkedElements = $this->collectLinkedElementsRecursive($rootDetailId);

        // Очищаем и записываем свойства пресета
        $this->updatePresetProperties($presetId, $linkedElements);

        // Возвращаем обновлённый INIT payload
        // Если offerIds не переданы, пытаемся найти их
        if (empty($offerIds)) {
            $offerIds = $this->getOffersForPreset($presetId);
        }
        
        $initPayloadService = new InitPayloadService();
        return $initPayloadService->prepareInitPayload($offerIds, SITE_ID, false);
    }

    /**
     * Rebuild linked preset properties for an ordered set of product roots while
     * preserving that exact top-level order in CALC_DETAILS.
     */
    public function enrichPresetFromProductRoots(int $presetId, array $rootDetailIds, array $offerIds = []): array
    {
        $rootDetailIds = array_values(array_unique(array_filter(array_map('intval', $rootDetailIds))));
        if ($presetId <= 0 || empty($rootDetailIds)) {
            throw new \InvalidArgumentException('Некорректная структура продукта');
        }

        if (!$this->getPresetById($presetId)) {
            throw new \RuntimeException('Пресет не найден');
        }

        $linkedElements = [];
        foreach ($rootDetailIds as $rootDetailId) {
            $this->collectLinkedElementsRecursive($rootDetailId, $linkedElements);
        }
        $linkedElements['details'] = $rootDetailIds;
        $this->updatePresetProperties($presetId, $linkedElements);

        if (empty($offerIds)) {
            $offerIds = $this->getOffersForPreset($presetId);
        }

        return (new InitPayloadService())->prepareInitPayload($offerIds, SITE_ID, false);
    }

    /** Synchronize preset custom-field links from all currently linked root details. */
    public function synchronizePresetCustomFields(int $presetId): array
    {
        if ($presetId <= 0 || !$this->getPresetById($presetId)) {
            return ['changed' => false, 'expected' => [], 'actual' => []];
        }

        $rootIds = [];
        $actual = [];
        $targets = ['CALC_DETAILS' => &$rootIds, 'CALC_CUSTOM_FIELDS' => &$actual];
        foreach ($targets as $code => &$target) {
            $result = \CIBlockElement::GetProperty($this->presetsIblockId, $presetId, ['sort' => 'asc'], ['CODE' => $code]);
            while ($property = $result->Fetch()) {
                $id = (int)($property['VALUE'] ?? 0);
                if ($id > 0) $target[] = $id;
            }
            $target = array_values(array_unique($target));
        }
        unset($target, $targets);

        $linked = [];
        foreach ($rootIds as $rootId) {
            $this->collectLinkedElementsRecursive($rootId, $linked);
        }
        $expected = array_values(array_unique(array_map('intval', $linked['customFields'] ?? [])));
        sort($expected);
        $actualSorted = $actual;
        sort($actualSorted);
        if ($expected === $actualSorted) {
            return ['changed' => false, 'expected' => $expected, 'actual' => $actual];
        }

        \CIBlockElement::SetPropertyValuesEx($presetId, $this->presetsIblockId, [
            'CALC_CUSTOM_FIELDS' => $expected ?: false,
        ]);
        if (defined('BX_COMP_MANAGED_CACHE')) {
            global $CACHE_MANAGER;
            $CACHE_MANAGER->ClearByTag('iblock_id_' . $this->presetsIblockId);
        }
        return ['changed' => true, 'expected' => $expected, 'actual' => $actual];
    }

    /**
     * Рекурсивно собирает все связанные элементы из детали
     *
     * @param int $detailId ID детали
     * @param array $linkedElements Аккумулятор для связанных элементов
     * @return array Массив связанных элементов
     */
    private function collectLinkedElementsRecursive(int $detailId, array &$linkedElements = []): array
    {
        if (empty($linkedElements)) {
            $linkedElements = [
                'details' => [],
                'calcStages' => [],
                'calcSettings' => [],
                'operations' => [],
                'operationsVariants' => [],
                'materials' => [],
                'materialsVariants' => [],
                'equipment' => [],
                'customFields' => [],
            ];
        }

        // Добавляем текущую деталь
        if (!in_array($detailId, $linkedElements['details'])) {
            $linkedElements['details'][] = $detailId;
        }

        // Получаем деталь
        $detail = $this->getDetailById($detailId);
        if (!$detail) {
            return $linkedElements;
        }

        // Собираем этапы детали из CALC_STAGES (для всех типов деталей)
        $stageIds = [];
        if (!empty($detail['CALC_STAGES'])) {
            $stageIds = $detail['CALC_STAGES'];
        }

        foreach ($stageIds as $stageId) {
            if (!in_array($stageId, $linkedElements['calcStages'])) {
                $linkedElements['calcStages'][] = $stageId;
            }

            // Получаем этап и извлекаем связи
            $stage = $this->getStageById($stageId);
            if ($stage) {
                // CALC_SETTINGS (калькулятор)
                if (!empty($stage['CALC_SETTINGS'])) {
                    $calcSettingsIds = is_array($stage['CALC_SETTINGS'])
                        ? $stage['CALC_SETTINGS']
                        : [$stage['CALC_SETTINGS']];

                    foreach ($calcSettingsIds as $calcSettingsId) {
                        if ($calcSettingsId <= 0) {
                            continue;
                        }

                        if (!in_array($calcSettingsId, $linkedElements['calcSettings'])) {
                            $linkedElements['calcSettings'][] = $calcSettingsId;
                        }

                        $customFieldIds = $this->getCalcSettingsCustomFields($calcSettingsId);
                        foreach ($customFieldIds as $customFieldId) {
                            if (!in_array($customFieldId, $linkedElements['customFields'])) {
                                $linkedElements['customFields'][] = $customFieldId;
                            }
                        }
                    }
                }

                // OPERATION_VARIANT
                if (!empty($stage['OPERATION_VARIANT'])) {
                    $variantId = is_array($stage['OPERATION_VARIANT']) 
                        ? $stage['OPERATION_VARIANT'][0] 
                        : $stage['OPERATION_VARIANT'];
                    
                    if (!in_array($variantId, $linkedElements['operationsVariants'])) {
                        $linkedElements['operationsVariants'][] = $variantId;
                    }

                    // Получаем родительскую операцию через CML2_LINK
                    $parentId = $this->getParentByCml2Link($variantId, $this->operationsVariantsIblockId);
                    if ($parentId && !in_array($parentId, $linkedElements['operations'])) {
                        $linkedElements['operations'][] = $parentId;
                    }
                }

                // MATERIAL_VARIANT
                if (!empty($stage['MATERIAL_VARIANT'])) {
                    $variantId = is_array($stage['MATERIAL_VARIANT']) 
                        ? $stage['MATERIAL_VARIANT'][0] 
                        : $stage['MATERIAL_VARIANT'];
                    
                    if (!in_array($variantId, $linkedElements['materialsVariants'])) {
                        $linkedElements['materialsVariants'][] = $variantId;
                    }

                    // Получаем родительский материал через CML2_LINK
                    $parentId = $this->getParentByCml2Link($variantId, $this->materialsVariantsIblockId);
                    if ($parentId && !in_array($parentId, $linkedElements['materials'])) {
                        $linkedElements['materials'][] = $parentId;
                    }
                }

                // EQUIPMENT
                if (!empty($stage['EQUIPMENT'])) {
                    $equipmentIds = is_array($stage['EQUIPMENT']) 
                        ? $stage['EQUIPMENT'] 
                        : [$stage['EQUIPMENT']];
                    
                    foreach ($equipmentIds as $equipmentId) {
                        if (!in_array($equipmentId, $linkedElements['equipment'])) {
                            $linkedElements['equipment'][] = $equipmentId;
                        }
                    }
                }
            }
        }

        // Рекурсия для вложенных деталей (если BINDING)
        if ($detail['TYPE'] === 'BINDING' && !empty($detail['DETAILS'])) {
            foreach ($detail['DETAILS'] as $childId) {
                $this->collectLinkedElementsRecursive($childId, $linkedElements);
            }
        }

        return $linkedElements;
    }

    /**
     * Обновить свойства пресета собранными связями
     *
     * @param int $presetId ID пресета
     * @param array $linkedElements Связанные элементы
     * @return void
     */
    private function updatePresetProperties(int $presetId, array $linkedElements): void
    {
        // Подготавливаем значения свойств (пустые массивы для очистки)
        $propertyValues = [
            'CALC_STAGES' => $linkedElements['calcStages'] ?: false,
            'CALC_SETTINGS' => $linkedElements['calcSettings'] ?: false,
            'CALC_MATERIALS' => $linkedElements['materials'] ?: false,
            'CALC_MATERIALS_VARIANTS' => $linkedElements['materialsVariants'] ?: false,
            'CALC_OPERATIONS' => $linkedElements['operations'] ?: false,
            'CALC_OPERATIONS_VARIANTS' => $linkedElements['operationsVariants'] ?: false,
            'CALC_EQUIPMENT' => $linkedElements['equipment'] ?: false,
            'CALC_DETAILS' => $linkedElements['details'] ?: false,
            'CALC_CUSTOM_FIELDS' => $linkedElements['customFields'] ?: false,
        ];

        // Записываем свойства пресета
        \CIBlockElement::SetPropertyValuesEx($presetId, $this->presetsIblockId, $propertyValues);

        // Сброс кэша для чтения актуальных данных
        if (defined('BX_COMP_MANAGED_CACHE')) {
            global $CACHE_MANAGER;
            $CACHE_MANAGER->ClearByTag('iblock_id_' . $this->presetsIblockId);
        }
    }

    /**
     * Получить пресет по ID
     *
     * @param int $presetId ID пресета
     * @return array|null Данные пресета
     */
    private function getPresetById(int $presetId): ?array
    {
        $element = \CIBlockElement::GetList(
            [],
            ['ID' => $presetId, 'IBLOCK_ID' => $this->presetsIblockId],
            false,
            false,
            ['ID', 'NAME', 'IBLOCK_ID']
        )->Fetch();

        return $element ?: null;
    }

    /**
     * Получить деталь по ID
     *
     * @param int $detailId ID детали
     * @return array|null Данные детали
     */
    private function getDetailById(int $detailId): ?array
    {
        $element = \CIBlockElement::GetList(
            [],
            ['ID' => $detailId, 'IBLOCK_ID' => $this->detailsIblockId],
            false,
            false,
            ['ID', 'NAME', 'IBLOCK_ID']
        )->GetNextElement();

        if (!$element) {
            return null;
        }

        $fields = $element->GetFields();
        $properties = $element->GetProperties();

        $type = $properties['TYPE']['VALUE_XML_ID'] ?? 'DETAIL';
        
        $calcStages = is_array($properties['CALC_STAGES']['VALUE']) 
            ? $properties['CALC_STAGES']['VALUE'] 
            : (!empty($properties['CALC_STAGES']['VALUE']) ? [$properties['CALC_STAGES']['VALUE']] : []);
        
        $details = is_array($properties['DETAILS']['VALUE']) 
            ? $properties['DETAILS']['VALUE'] 
            : (!empty($properties['DETAILS']['VALUE']) ? [$properties['DETAILS']['VALUE']] : []);

        return [
            'ID' => (int)$fields['ID'],
            'NAME' => $fields['NAME'],
            'TYPE' => $type,
            'CALC_STAGES' => array_map('intval', $calcStages),
            'DETAILS' => array_map('intval', $details),
        ];
    }

    /**
     * Получить этап по ID
     *
     * @param int $stageId ID этапа
     * @return array|null Данные этапа
     */
    private function getStageById(int $stageId): ?array
    {
        $element = \CIBlockElement::GetList(
            [],
            ['ID' => $stageId, 'IBLOCK_ID' => $this->stagesIblockId],
            false,
            false,
            ['ID', 'NAME', 'IBLOCK_ID']
        )->GetNextElement();

        if (!$element) {
            return null;
        }

        $properties = $element->GetProperties();

        $result = [];

        // CALC_SETTINGS
        if (isset($properties['CALC_SETTINGS'])) {
            $result['CALC_SETTINGS'] = is_array($properties['CALC_SETTINGS']['VALUE']) 
                ? array_map('intval', $properties['CALC_SETTINGS']['VALUE'])
                : (!empty($properties['CALC_SETTINGS']['VALUE']) ? (int)$properties['CALC_SETTINGS']['VALUE'] : null);
        }

        // OPERATION_VARIANT
        if (isset($properties['OPERATION_VARIANT'])) {
            $result['OPERATION_VARIANT'] = is_array($properties['OPERATION_VARIANT']['VALUE']) 
                ? array_map('intval', $properties['OPERATION_VARIANT']['VALUE'])
                : (!empty($properties['OPERATION_VARIANT']['VALUE']) ? (int)$properties['OPERATION_VARIANT']['VALUE'] : null);
        }

        // MATERIAL_VARIANT
        if (isset($properties['MATERIAL_VARIANT'])) {
            $result['MATERIAL_VARIANT'] = is_array($properties['MATERIAL_VARIANT']['VALUE']) 
                ? array_map('intval', $properties['MATERIAL_VARIANT']['VALUE'])
                : (!empty($properties['MATERIAL_VARIANT']['VALUE']) ? (int)$properties['MATERIAL_VARIANT']['VALUE'] : null);
        }

        // EQUIPMENT
        if (isset($properties['EQUIPMENT'])) {
            $result['EQUIPMENT'] = is_array($properties['EQUIPMENT']['VALUE']) 
                ? array_map('intval', $properties['EQUIPMENT']['VALUE'])
                : (!empty($properties['EQUIPMENT']['VALUE']) ? [(int)$properties['EQUIPMENT']['VALUE']] : []);
        }

        return $result;
    }

    /**
     * Получить дополнительные поля калькулятора по ID
     *
     * @param int $calcSettingsId ID калькулятора
     * @return array ID элементов CALC_CUSTOM_FIELDS
     */
    private function getCalcSettingsCustomFields(int $calcSettingsId): array
    {
        if ($calcSettingsId <= 0 || $this->settingsIblockId <= 0) {
            return [];
        }

        $element = \CIBlockElement::GetList(
            [],
            ['ID' => $calcSettingsId, 'IBLOCK_ID' => $this->settingsIblockId],
            false,
            false,
            ['ID', 'IBLOCK_ID']
        )->GetNextElement();

        if (!$element) {
            return [];
        }

        $properties = $element->GetProperties();
        if (empty($properties['CUSTOM_FIELDS']['VALUE'])) {
            return [];
        }

        $values = is_array($properties['CUSTOM_FIELDS']['VALUE'])
            ? $properties['CUSTOM_FIELDS']['VALUE']
            : [$properties['CUSTOM_FIELDS']['VALUE']];

        return array_values(array_unique(array_map('intval', $values)));
    }

    /**
     * Получить родительский элемент через CML2_LINK
     *
     * @param int $variantId ID варианта
     * @param int $iblockId ID инфоблока варианта
     * @return int|null ID родительского элемента
     */
    private function getParentByCml2Link(int $variantId, int $iblockId = 0): ?int
    {
        if ($variantId <= 0) {
            return null;
        }
        
        // Если iblockId не передан, пробуем получить из элемента
        $filter = ['ID' => $variantId];
        if ($iblockId > 0) {
            $filter['IBLOCK_ID'] = $iblockId;
        }
        
        $element = \CIBlockElement::GetList(
            [],
            $filter,
            false,
            false,
            ['ID', 'IBLOCK_ID']
        )->GetNextElement();

        if (!$element) {
            return null;
        }
        
        $properties = $element->GetProperties();
        $parentId = (int)($properties['CML2_LINK']['VALUE'] ?? 0);
        
        return $parentId > 0 ? $parentId : null;
    }

    /**
     * Получить offerIds для пресета
     *
     * @param int $presetId ID пресета
     * @return array Массив ID торговых предложений
     */
    private function getOffersForPreset(int $presetId): array
    {
        $offerIds = [];

        // Находим все торговые предложения, у которых CALC_PRESET = $presetId
        $result = \CIBlockElement::GetList(
            [],
            ['PROPERTY_CALC_PRESET' => $presetId],
            false,
            false,
            ['ID']
        );

        while ($offer = $result->Fetch()) {
            $offerIds[] = (int)$offer['ID'];
        }

        return $offerIds;
    }

    /**
     * Очистить свойства пресета
     *
     * @param int $presetId ID пресета
     * @return void
     * @throws \Exception
     */
    public function clearPreset(int $presetId): void
    {
        if ($presetId <= 0) {
            throw new \Exception('Некорректный ID пресета');
        }

        // Проверяем существование пресета
        $preset = $this->getPresetById($presetId);
        if (!$preset) {
            throw new \Exception('Пресет не найден');
        }

        // Очищаем все свойства пресета
        $propertyValues = [
            'CALC_STAGES' => false,
            'CALC_SETTINGS' => false,
            'CALC_MATERIALS' => false,
            'CALC_MATERIALS_VARIANTS' => false,
            'CALC_OPERATIONS' => false,
            'CALC_OPERATIONS_VARIANTS' => false,
            'CALC_EQUIPMENT' => false,
            'CALC_DETAILS' => false,
        ];

        // Записываем свойства пресета
        \CIBlockElement::SetPropertyValuesEx($presetId, $this->presetsIblockId, $propertyValues);
    }

    /**
     * Добавить этап в пресет
     *
     * @param int $presetId ID пресета
     * @param int $stageId ID этапа
     * @return void
     * @throws \Exception
     */
    public function addStageToPreset(int $presetId, int $stageId): void
    {
        if ($presetId <= 0 || $stageId <= 0) {
            throw new \Exception('Некорректные параметры');
        }

        // Получаем текущие этапы пресета
        $currentStages = [];
        $rs = \CIBlockElement::GetProperty(
            $this->presetsIblockId,
            $presetId,
            [],
            ['CODE' => 'CALC_STAGES']
        );
        
        while ($prop = $rs->Fetch()) {
            if (!empty($prop['VALUE'])) {
                $currentStages[] = (int)$prop['VALUE'];
            }
        }

        // Добавляем новый этап, если его еще нет
        if (!in_array($stageId, $currentStages)) {
            $currentStages[] = $stageId;
        }

        // Обновляем свойство CALC_STAGES
        \CIBlockElement::SetPropertyValuesEx($presetId, $this->presetsIblockId, [
            'CALC_STAGES' => $currentStages,
        ]);
    }

    /**
     * Обновить свойство этапа
     *
     * @param int $stageId ID этапа
     * @param string $propertyCode Код свойства
     * @param mixed $value Значение
     * @return void
     * @throws \Exception
     */
    public function updateStageProperty(int $stageId, string $propertyCode, $value): void
    {
        if ($stageId <= 0) {
            throw new \Exception('Некорректный ID этапа');
        }

        if (empty($propertyCode)) {
            throw new \Exception('Код свойства обязателен');
        }

        // Обновляем свойство этапа
        \CIBlockElement::SetPropertyValuesEx($stageId, $this->stagesIblockId, [
            $propertyCode => $value,
        ]);
    }

    /**
     * Получить первый ID из CALC_DETAILS пресета
     *
     * @param int $presetId ID пресета
     * @return int|null Первый ID детали или null
     */
    public function getFirstDetailFromPreset(int $presetId): ?int
    {
        if ($presetId <= 0) {
            return null;
        }

        // Получаем первую деталь из пресета
        $rs = \CIBlockElement::GetProperty(
            $this->presetsIblockId,
            $presetId,
            ['sort' => 'asc'],
            ['CODE' => 'CALC_DETAILS']
        );
        
        if ($prop = $rs->Fetch()) {
            return !empty($prop['VALUE']) ? (int)$prop['VALUE'] : null;
        }

        return null;
    }
}
