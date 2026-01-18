<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Сервис для отслеживания зависимостей между элементами.
 */
class DependencyTracker
{
    /** @var ConfigManager */
    protected ConfigManager $configManager;

    public function __construct()
    {
        $this->configManager = new ConfigManager();
    }

    /**
     * Находит все конфигурации, использующие указанный элемент.
     *
     * @param int    $elementId ID элемента.
     * @param string $type      Тип элемента (material, operation, equipment, detail).
     *
     * @return int[] ID конфигураций.
     */
    public function findConfigsUsingElement(int $elementId, string $type): array
    {
        if (!Loader::includeModule('iblock')) {
            return [];
        }

        $iblockId = $this->configManager->getIblockId('CALC_STAGES');
        if ($iblockId <= 0) {
            return [];
        }

        $propertyCode = match ($type) {
            'material' => 'USED_MATERIALS',
            'operation' => 'USED_OPERATIONS',
            'equipment' => 'USED_EQUIPMENT',
            'detail' => 'USED_DETAILS',
            default => null,
        };

        if ($propertyCode === null) {
            return [];
        }

        $rsElements = \CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $iblockId,
                'PROPERTY_' . $propertyCode => $elementId,
            ],
            false,
            false,
            ['ID']
        );

        $ids = [];
        while ($arElement = $rsElements->Fetch()) {
            $ids[] = (int)$arElement['ID'];
        }

        return $ids;
    }

    /**
     * Помечает конфигурации как требующие пересчёта.
     *
     * @param int[] $configIds ID конфигураций.
     *
     * @return bool
     */
    public function markConfigsForRecalc(array $configIds): bool
    {
        if (!Loader::includeModule('iblock')) {
            return false;
        }

        if (empty($configIds)) {
            return true;
        }

        $iblockId = $this->configManager->getIblockId('CALC_STAGES');
        if ($iblockId <= 0) {
            return false;
        }

        foreach ($configIds as $configId) {
            \CIBlockElement::SetPropertyValuesEx(
                $configId,
                $iblockId,
                ['STATUS' => 'recalc']
            );
        }

        return true;
    }

    /**
     * Получает все конфигурации, требующие пересчёта.
     *
     * @return array
     */
    public function getConfigsNeedingRecalc(): array
    {
        if (!Loader::includeModule('iblock')) {
            return [];
        }

        $iblockId = $this->configManager->getIblockId('CALC_STAGES');
        if ($iblockId <= 0) {
            return [];
        }

        $rsElements = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => $iblockId,
                'PROPERTY_STATUS' => 'recalc',
            ],
            false,
            false,
            ['ID', 'NAME', 'PROPERTY_PRODUCT_ID']
        );

        $configs = [];
        while ($arElement = $rsElements->Fetch()) {
            $configs[] = [
                'ID' => (int)$arElement['ID'],
                'NAME' => $arElement['NAME'],
                'PRODUCT_ID' => (int)($arElement['PROPERTY_PRODUCT_ID_VALUE'] ?? 0),
            ];
        }

        return $configs;
    }

    /**
     * Обрабатывает изменение элемента и помечает зависимые конфигурации.
     *
     * @param int    $elementId ID изменённого элемента.
     * @param int    $iblockId  ID инфоблока элемента.
     *
     * @return int Количество помеченных конфигураций.
     */
    public function handleElementChange(int $elementId, int $iblockId): int
    {
        // Определяем тип элемента по инфоблоку
        $type = $this->getElementTypeByIblock($iblockId);
        if ($type === null) {
            return 0;
        }

        // Находим все конфигурации, использующие этот элемент
        $configIds = $this->findConfigsUsingElement($elementId, $type);

        if (empty($configIds)) {
            return 0;
        }

        // Помечаем их как требующие пересчёта
        $this->markConfigsForRecalc($configIds);

        return count($configIds);
    }

    /**
     * Определяет тип элемента по ID инфоблока.
     *
     * @param int $iblockId ID инфоблока.
     *
     * @return string|null Тип элемента или null.
     */
    protected function getElementTypeByIblock(int $iblockId): ?string
    {
        $materialsId = $this->configManager->getIblockId('CALC_MATERIALS');
        $materialsVariantsId = $this->configManager->getIblockId('CALC_MATERIALS_VARIANTS');
        $operationsId = $this->configManager->getIblockId('CALC_OPERATIONS');
        $operationsVariantsId = $this->configManager->getIblockId('CALC_OPERATIONS_VARIANTS');
        $equipmentId = $this->configManager->getIblockId('CALC_EQUIPMENT');
        $detailsId = $this->configManager->getIblockId('CALC_DETAILS');

        if ($iblockId === $materialsId || $iblockId === $materialsVariantsId) {
            return 'material';
        }

        if ($iblockId === $operationsId || $iblockId === $operationsVariantsId) {
            return 'operation';
        }

        if ($iblockId === $equipmentId) {
            return 'equipment';
        }

        if ($iblockId === $detailsId || $iblockId === $detailsVariantsId) {
            return 'detail';
        }

        return null;
    }

    /**
     * Извлекает использованные ID из структуры расчёта.
     *
     * @param array $structure Структура расчёта.
     *
     * @return array [materials => [], operations => [], equipment => [], details => []]
    */
    public function extractUsedIdsFromStructure(array $structure): array
    {
        $result = [
            'materials' => [],
            'operations' => [],
            'equipment' => [],
            'details' => [],
        ];

        $this->walkStructure($structure, $result);

        // Убираем дубликаты
        foreach ($result as $key => $ids) {
            $result[$key] = array_values(array_unique(array_filter(array_map('intval', $ids))));
        }

        return $result;
    }

    /**
     * Рекурсивно обходит структуру и собирает ID.
     *
     * @param array $node   Узел структуры.
     * @param array &$result Результат.
     */
    protected function walkStructure(array $node, array &$result): void
    {
        if (isset($node['materialId'])) {
            $result['materials'][] = (int)$node['materialId'];
        }
        if (isset($node['operationId'])) {
            $result['operations'][] = (int)$node['operationId'];
        }
        if (isset($node['equipmentId'])) {
            $result['equipment'][] = (int)$node['equipmentId'];
        }
        if (isset($node['detailId'])) {
            $result['details'][] = (int)$node['detailId'];
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                if (is_array($child)) {
                    $this->walkStructure($child, $result);
                }
            }
        }

        if (isset($node['calculators']) && is_array($node['calculators'])) {
            foreach ($node['calculators'] as $calc) {
                if (is_array($calc)) {
                    $this->walkStructure($calc, $result);
                }
            }
        }
    }
}
