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
    private int $presetsIblockId;

    public function __construct()
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix iblock');
        }

        $configManager = new ConfigManager();
        $this->detailsIblockId = $configManager->getIblockId('CALC_DETAILS');
        $this->stagesIblockId = $configManager->getIblockId('CALC_STAGES');
        $this->presetsIblockId = $configManager->getIblockId('CALC_PRESETS');
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

        // Собираем этапы детали (CALC_STAGES или CALC_STAGES_BINDINGS)
        // Для BINDING собираем из CALC_STAGES_BINDINGS, для DETAIL из CALC_STAGES
        $stageIds = [];
        if ($detail['TYPE'] === 'BINDING') {
            // Для BINDING используем CALC_STAGES_BINDINGS, если есть
            if (!empty($detail['CALC_STAGES_BINDINGS'])) {
                $stageIds = $detail['CALC_STAGES_BINDINGS'];
            }
        } else {
            // Для обычной DETAIL используем CALC_STAGES
            if (!empty($detail['CALC_STAGES'])) {
                $stageIds = $detail['CALC_STAGES'];
            }
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
                    $calcSettingsId = is_array($stage['CALC_SETTINGS']) 
                        ? $stage['CALC_SETTINGS'][0] 
                        : $stage['CALC_SETTINGS'];
                    
                    if (!in_array($calcSettingsId, $linkedElements['calcSettings'])) {
                        $linkedElements['calcSettings'][] = $calcSettingsId;
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
                    $parentId = $this->getParentByCml2Link($variantId);
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
                    $parentId = $this->getParentByCml2Link($variantId);
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
        ];

        // Записываем свойства пресета
        \CIBlockElement::SetPropertyValuesEx($presetId, $this->presetsIblockId, $propertyValues);
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
        
        $calcStagesBindings = is_array($properties['CALC_STAGES_BINDINGS']['VALUE']) 
            ? $properties['CALC_STAGES_BINDINGS']['VALUE'] 
            : (!empty($properties['CALC_STAGES_BINDINGS']['VALUE']) ? [$properties['CALC_STAGES_BINDINGS']['VALUE']] : []);
        
        $details = is_array($properties['DETAILS']['VALUE']) 
            ? $properties['DETAILS']['VALUE'] 
            : (!empty($properties['DETAILS']['VALUE']) ? [$properties['DETAILS']['VALUE']] : []);

        return [
            'ID' => (int)$fields['ID'],
            'NAME' => $fields['NAME'],
            'TYPE' => $type,
            'CALC_STAGES' => array_map('intval', $calcStages),
            'CALC_STAGES_BINDINGS' => array_map('intval', $calcStagesBindings),
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
     * Получить родительский элемент через CML2_LINK
     *
     * @param int $variantId ID варианта
     * @return int|null ID родительского элемента
     */
    private function getParentByCml2Link(int $variantId): ?int
    {
        $element = \CIBlockElement::GetList(
            [],
            ['ID' => $variantId],
            false,
            false,
            ['ID', 'PROPERTY_CML2_LINK']
        )->Fetch();

        if (!$element) {
            return null;
        }

        $parentId = (int)($element['PROPERTY_CML2_LINK_VALUE'] ?? 0);
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
            'CALC_DETAILS_VARIANTS' => false,
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
