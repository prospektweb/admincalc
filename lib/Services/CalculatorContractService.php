<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Read-only dependency index for a reusable calculator contract.
 */
final class CalculatorContractService
{
    public function inspect(int $settingsId): array
    {
        if ($settingsId <= 0 || !Loader::includeModule('iblock')) {
            return ['status' => 'error', 'message' => 'Не удалось проверить зависимости калькулятора'];
        }

        $config = new ConfigManager();
        $stageIblockId = $config->getIblockId('CALC_STAGES');
        $detailIblockId = $config->getIblockId('CALC_DETAILS');
        $presetIblockId = $config->getIblockId('CALC_PRESETS');
        if ($stageIblockId <= 0 || $detailIblockId <= 0 || $presetIblockId <= 0) {
            return ['status' => 'error', 'message' => 'Не найдены инфоблоки контрактов калькулятора'];
        }

        $stageIds = $this->findIds($stageIblockId, ['PROPERTY_CALC_SETTINGS' => $settingsId]);
        if (!$stageIds) {
            return ['status' => 'ok', 'settingsId' => $settingsId, 'stageIds' => [], 'presets' => []];
        }

        $detailStageMap = $this->loadDetailStageMap($detailIblockId, $stageIds);
        $detailIds = array_keys($detailStageMap);
        $allDetailIds = array_fill_keys($detailIds, true);
        $frontier = $detailIds;
        while ($frontier) {
            $parents = $this->findIds($detailIblockId, ['PROPERTY_DETAILS' => $frontier]);
            $frontier = [];
            foreach ($parents as $parentId) {
                if (!isset($allDetailIds[$parentId])) {
                    $allDetailIds[$parentId] = true;
                    $frontier[] = $parentId;
                }
            }
        }

        $presets = [];
        if ($allDetailIds) {
            $rows = \CIBlockElement::GetList(
                ['NAME' => 'ASC'],
                ['IBLOCK_ID' => $presetIblockId, 'ACTIVE' => 'Y', 'PROPERTY_CALC_DETAILS' => array_keys($allDetailIds)],
                false,
                false,
                ['ID', 'NAME']
            );
            while ($row = $rows->Fetch()) {
                $presetId = (int)$row['ID'];
                $presetStageIds = [];
                foreach ($this->loadPropertyIds($presetIblockId, $presetId, 'CALC_DETAILS') as $rootDetailId) {
                    foreach ($this->collectDetailTreeIds($detailIblockId, $rootDetailId) as $treeDetailId) {
                        foreach ($detailStageMap[$treeDetailId] ?? [] as $stageId) {
                            $presetStageIds[$stageId] = true;
                        }
                    }
                }
                $presets[$presetId] = [
                    'id' => $presetId,
                    'name' => (string)$row['NAME'],
                    'stageIds' => array_map('intval', array_keys($presetStageIds)),
                ];
            }
        }

        foreach ($presets as &$preset) {
            try {
                $options = (new CatalogTreeService())->presetLoadOptions(['presetId' => (int)$preset['id']]);
                $product = (array)(($options['products'] ?? [])[0] ?? []);
                $offerIds = array_values(array_filter(array_map(
                    static fn(array $offer): int => (int)($offer['id'] ?? 0),
                    (array)($product['offers'] ?? [])
                )));
                if ($offerIds) {
                    $focusStageId = (int)($preset['stageIds'][0] ?? 0);
                    $preset['editorUrl'] = '/bitrix/admin/prospektweb_calc_calculator.php?offer_ids='
                        . implode(',', $offerIds);
                    if ($focusStageId > 0) {
                        $preset['editorUrl'] .= '&focus_stage_id=' . $focusStageId;
                    }
                }
            } catch (\Throwable $exception) {
                // Dependency inspection must remain available even when a preset
                // currently has no product/offers from which to open the editor.
            }
        }
        unset($preset);

        return [
            'status' => 'ok',
            'settingsId' => $settingsId,
            'stageIds' => $stageIds,
            'presets' => array_values($presets),
        ];
    }

    public function resolve(
        int $settingsId,
        int $currentStageId,
        int $currentPresetId,
        string $mode,
        string $message
    ): array
    {
        if ($settingsId <= 0 || $currentStageId <= 0 || !Loader::includeModule('iblock')) {
            return ['status' => 'error', 'message' => 'Не указан калькулятор или этап'];
        }

        $config = new ConfigManager();
        $stageIblockId = $config->getIblockId('CALC_STAGES');
        $settingsIblockId = $config->getIblockId('CALC_SETTINGS');
        if ($stageIblockId <= 0 || $settingsIblockId <= 0) {
            return ['status' => 'error', 'message' => 'Не найдены инфоблоки калькуляторов или этапов'];
        }
        if (!$this->ensureStringProperty(
            $stageIblockId,
            'CONTRACT_ISSUE',
            'Нарушение контракта калькулятора',
            false,
            700
        )) {
            return ['status' => 'error', 'message' => 'Не удалось подготовить свойство блокировки контракта этапа'];
        }

        if ($mode === 'clone') {
            $source = \CIBlockElement::GetList([], [
                'ID' => $settingsId,
                'IBLOCK_ID' => $settingsIblockId,
            ], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'ACTIVE', 'IBLOCK_SECTION_ID'])->GetNextElement();
            if (!$source) {
                return ['status' => 'error', 'message' => 'Исходный калькулятор не найден'];
            }
            $fields = $source->GetFields();
            $properties = $source->GetProperties();
            $propertyValues = [];
            foreach ($properties as $code => $property) {
                if (!is_string($code) || $code === '') {
                    continue;
                }
                $propertyValues[$code] = $this->copyPropertyValue($property);
            }

            $element = new \CIBlockElement();
            $newSettingsId = (int)$element->Add([
                'IBLOCK_ID' => $settingsIblockId,
                'IBLOCK_SECTION_ID' => (int)($fields['IBLOCK_SECTION_ID'] ?? 0) ?: false,
                'ACTIVE' => (string)($fields['ACTIVE'] ?? 'Y'),
                'NAME' => (string)($fields['NAME'] ?? ('Калькулятор #' . $settingsId)) . ' — новая версия',
                'PROPERTY_VALUES' => $propertyValues,
            ]);
            if ($newSettingsId <= 0) {
                return ['status' => 'error', 'message' => $element->LAST_ERROR ?: 'Не удалось создать новую версию калькулятора'];
            }
            \CIBlockElement::SetPropertyValuesEx($currentStageId, $stageIblockId, [
                'CALC_SETTINGS' => $newSettingsId,
                'CONTRACT_ISSUE' => false,
            ]);

            return ['status' => 'ok', 'settingsId' => $newSettingsId, 'mode' => 'clone'];
        }

        if ($mode !== 'force') {
            return ['status' => 'error', 'message' => 'Неизвестный способ разрешения конфликта'];
        }

        $inspection = $this->inspect($settingsId);
        if (($inspection['status'] ?? null) !== 'ok') {
            return $inspection;
        }
        $issue = trim($message) !== '' ? trim($message) : 'Контракт общего калькулятора был изменён';
        $affectedStageIds = [];
        foreach ((array)($inspection['presets'] ?? []) as $preset) {
            if ((int)($preset['id'] ?? 0) === $currentPresetId) {
                continue;
            }
            foreach ((array)($preset['stageIds'] ?? []) as $stageId) {
                $affectedStageIds[(int)$stageId] = true;
            }
        }
        foreach (array_keys($affectedStageIds) as $stageId) {
            $stageId = (int)$stageId;
            \CIBlockElement::SetPropertyValuesEx($stageId, $stageIblockId, [
                'CONTRACT_ISSUE' => $issue,
            ]);
        }
        \CIBlockElement::SetPropertyValuesEx($currentStageId, $stageIblockId, ['CONTRACT_ISSUE' => false]);

        return ['status' => 'ok', 'settingsId' => $settingsId, 'mode' => 'force'];
    }

    private function findIds(int $iblockId, array $filter): array
    {
        $ids = [];
        $rows = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'] + $filter,
            false,
            false,
            ['ID']
        );
        while ($row = $rows->Fetch()) {
            $ids[] = (int)$row['ID'];
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @return array<int, int[]>
     */
    private function loadDetailStageMap(int $detailIblockId, array $stageIds): array
    {
        $allowedStages = array_fill_keys(array_map('intval', $stageIds), true);
        $map = [];
        $rows = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $detailIblockId, 'ACTIVE' => 'Y', 'PROPERTY_CALC_STAGES' => array_keys($allowedStages)],
            false,
            false,
            ['ID']
        );
        while ($element = $rows->GetNextElement()) {
            $fields = $element->GetFields();
            $detailId = (int)($fields['ID'] ?? 0);
            if ($detailId <= 0) {
                continue;
            }
            $properties = $element->GetProperties(['sort' => 'asc'], ['CODE' => 'CALC_STAGES']);
            $values = array_map('intval', (array)($properties['CALC_STAGES']['VALUE'] ?? []));
            $map[$detailId] = array_values(array_filter(
                array_unique($values),
                static fn(int $stageId): bool => isset($allowedStages[$stageId])
            ));
        }

        return $map;
    }

    private function loadPropertyIds(int $iblockId, int $elementId, string $code): array
    {
        $element = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'ID' => $elementId],
            false,
            false,
            ['ID']
        )->GetNextElement();
        if (!$element) {
            return [];
        }
        $properties = $element->GetProperties(['sort' => 'asc'], ['CODE' => $code]);

        return array_values(array_unique(array_filter(array_map(
            'intval',
            (array)($properties[$code]['VALUE'] ?? [])
        ))));
    }

    private function collectDetailTreeIds(int $detailIblockId, int $rootDetailId): array
    {
        $visited = [];
        $frontier = [$rootDetailId];
        while ($frontier) {
            $detailId = (int)array_shift($frontier);
            if ($detailId <= 0 || isset($visited[$detailId])) {
                continue;
            }
            $visited[$detailId] = true;
            foreach ($this->loadPropertyIds($detailIblockId, $detailId, 'DETAILS') as $childId) {
                if (!isset($visited[$childId])) {
                    $frontier[] = $childId;
                }
            }
        }

        return array_map('intval', array_keys($visited));
    }

    private function ensureStringProperty(
        int $iblockId,
        string $code,
        string $name,
        bool $multiple,
        int $sort
    ): bool {
        $existing = \CIBlockProperty::GetList([], [
            'IBLOCK_ID' => $iblockId,
            '=CODE' => $code,
        ])->Fetch();
        if ($existing) {
            return true;
        }

        $property = new \CIBlockProperty();
        return (int)$property->Add([
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'CODE' => $code,
            'NAME' => $name,
            'PROPERTY_TYPE' => 'S',
            'MULTIPLE' => $multiple ? 'Y' : 'N',
            'MULTIPLE_CNT' => $multiple ? 1 : 5,
            'SORT' => $sort,
        ]) > 0;
    }

    private function copyPropertyValue(array $property)
    {
        $value = $property['VALUE'] ?? false;
        if (($property['WITH_DESCRIPTION'] ?? 'N') !== 'Y') {
            return $value === '' || $value === [] ? false : $value;
        }

        $values = is_array($value) ? array_values($value) : [$value];
        $descriptions = is_array($property['DESCRIPTION'] ?? null)
            ? array_values($property['DESCRIPTION'])
            : [(string)($property['DESCRIPTION'] ?? '')];
        $result = [];
        foreach ($values as $index => $item) {
            $result[] = [
                'VALUE' => $item,
                'DESCRIPTION' => (string)($descriptions[$index] ?? ''),
            ];
        }
        return $result ?: false;
    }
}
