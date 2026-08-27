<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Loader;

/**
 * Обработчик операций со сборками (bundles)
 */
class BundleHandler
{
    private const MODULE_ID = 'prospektweb.calc';
    
    public function __construct()
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix iblock');
        }
    }
    
    /**
     * Create an independent preset. Products and
     * offers may be connected later; they are not part of preset identity.
     */
    public function createStandalonePreset(
        string $name,
        int $pinnedPresetsIblockId,
        int $sectionId = 0,
        ?string $codeSeed = null,
        bool $active = true
    ): int
    {
        $name = trim($name);
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $nameLength > 200) {
            throw new \InvalidArgumentException('Preset name must contain 1 to 200 characters.', 400);
        }
        if ($pinnedPresetsIblockId <= 0) {
            throw new \RuntimeException('The CALC_PRESETS iblock is not configured.', 409);
        }
        if ($sectionId < 0) {
            throw new \InvalidArgumentException('Calculator section ID must not be negative.', 422);
        }
        if ($sectionId > 0 && !\CIBlockSection::GetList(
            [],
            ['ID' => $sectionId, 'IBLOCK_ID' => $pinnedPresetsIblockId],
            false,
            ['ID']
        )->Fetch()) {
            throw new \InvalidArgumentException('Calculator section does not belong to CALC_PRESETS.', 422);
        }

        $element = new \CIBlockElement();
        $presetId = (int)$element->Add([
            'IBLOCK_ID' => $pinnedPresetsIblockId,
            'IBLOCK_SECTION_ID' => $sectionId > 0 ? $sectionId : false,
            'NAME' => $name,
            'CODE' => $this->generateUniqueElementCode($pinnedPresetsIblockId, $codeSeed ?? $name),
            'ACTIVE' => $active ? 'Y' : 'N',
            'PROPERTY_VALUES' => [
                'JSON' => ['VALUE' => ['TEXT' => '{}', 'TYPE' => 'HTML']],
            ],
        ]);
        if ($presetId <= 0) {
            throw new \RuntimeException('Unable to create preset: ' . (string)$element->LAST_ERROR);
        }

        $readBack = \CIBlockElement::GetList(
            [],
            ['ID' => $presetId, 'IBLOCK_ID' => $pinnedPresetsIblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME', 'ACTIVE', 'IBLOCK_SECTION_ID']
        )->Fetch();
        if (!is_array($readBack)
            || (int)($readBack['ID'] ?? 0) !== $presetId
            || trim((string)($readBack['NAME'] ?? '')) !== $name
            || (string)($readBack['ACTIVE'] ?? 'N') !== ($active ? 'Y' : 'N')
            || (int)($readBack['IBLOCK_SECTION_ID'] ?? 0) !== $sectionId) {
            throw new \RuntimeException('Preset creation readback mismatch.', 409);
        }

        return $presetId;
    }

    /**
     * Клонировать пресет вместе со всеми деталями/этапами.
     *
     * @param int $presetId ID исходного пресета
     * @return int ID нового пресета
     * @throws \Exception
     */
    public function clonePresetLocked(int $presetId, array $pinnedIblockIds): int
    {
        if ($presetId <= 0) {
            throw new \Exception('presetId не указан');
        }

        $presetsIblockId = (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0);
        $detailsIblockId = (int)($pinnedIblockIds['CALC_DETAILS'] ?? 0);
        $stagesIblockId = (int)($pinnedIblockIds['CALC_STAGES'] ?? 0);
        $settingsIblockId = (int)($pinnedIblockIds['CALC_SETTINGS'] ?? 0);
        if ($presetsIblockId <= 0 || $detailsIblockId <= 0
            || $stagesIblockId <= 0 || $settingsIblockId <= 0) {
            throw new \Exception('Pinned calculator iblock authority is incomplete');
        }

        $original = \CIBlockElement::GetList(
            [],
            ['ID' => $presetId, 'IBLOCK_ID' => $presetsIblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME', 'ACTIVE', 'SORT', 'IBLOCK_SECTION_ID', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE']
        )->Fetch();

        if (!$original) {
            throw new \Exception('Пресет не найден');
        }

        $newPresetId = 0;
        // Transaction and rollback belong exclusively to
        // PresetLifecycleMutationService. This method may only run while the
        // caller holds the source preset and global calculator authority.
            $newPresetName = sprintf('%s (копия %s)', $original['NAME'], date('d.m.Y H:i:s'));
            $newPresetId = (int)(new \CIBlockElement())->Add([
                'IBLOCK_ID' => $presetsIblockId,
                'NAME' => $newPresetName,
                'CODE' => $this->generateUniqueElementCode($presetsIblockId, $newPresetName),
                'ACTIVE' => $original['ACTIVE'] ?? 'Y',
                'SORT' => (int)($original['SORT'] ?? 500),
                'IBLOCK_SECTION_ID' => (int)($original['IBLOCK_SECTION_ID'] ?? 0) > 0
                    ? (int)$original['IBLOCK_SECTION_ID']
                    : false,
                'PREVIEW_TEXT' => $original['PREVIEW_TEXT'] ?? '',
                'PREVIEW_TEXT_TYPE' => $original['PREVIEW_TEXT_TYPE'] ?? 'text',
                'DETAIL_TEXT' => $original['DETAIL_TEXT'] ?? '',
                'DETAIL_TEXT_TYPE' => $original['DETAIL_TEXT_TYPE'] ?? 'text',
            ]);

            if ($newPresetId <= 0) {
                throw new \Exception('Ошибка создания клона пресета');
            }

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
            foreach ($this->extractStageIdsFromValue($propertyValues) as $referencedStageId) {
                if (!in_array($referencedStageId, $stageIdsToClone, true)) {
                    $stageIdsToClone[] = $referencedStageId;
                }
            }

            $stageIdsToClone = $this->expandStageIdsForClone($stageIdsToClone, $stagesIblockId);

            // Settings contain the actual formulas. Sharing them between a
            // source calculator and a version workspace would make an edit in
            // one graph mutate every other graph that references the same row.
            $settingsIdsToClone = $this->normalizeToIntArray($propertyValues['CALC_SETTINGS'] ?? []);
            foreach ($stageIdsToClone as $stageId) {
                $stageProperties = $this->getElementPropertyValuesForClone($stageId, $stagesIblockId);
                foreach ($this->normalizeToIntArray($stageProperties['CALC_SETTINGS'] ?? []) as $settingsId) {
                    if (!in_array($settingsId, $settingsIdsToClone, true)) {
                        $settingsIdsToClone[] = $settingsId;
                    }
                }
            }
            $settingsMap = [];
            foreach ($settingsIdsToClone as $settingsId) {
                $newSettingsId = $this->cloneSettingsElement($settingsId, $settingsIblockId);
                if (!$newSettingsId) {
                    throw new \Exception('Не удалось клонировать настройки ID=' . $settingsId);
                }
                $settingsMap[$settingsId] = (int)$newSettingsId;
            }

            $stageMap = [];
            foreach ($stageIdsToClone as $stageId) {
                $newStageId = $this->cloneStageElement($stageId, $stagesIblockId, $settingsMap);
                if (!$newStageId) {
                    throw new \Exception('Не удалось клонировать этап ID=' . $stageId);
                }
                $stageMap[$stageId] = (int)$newStageId;
            }

            $this->remapStageInputDescriptions($stageMap, $stagesIblockId);
            $this->remapSettingsStageReferences($settingsMap, $settingsIblockId, $stageMap);
            $this->assertSettingsCloneReadBack($settingsMap, $settingsIblockId, $stageMap);

            // Шаг 2: клонируем все детали/скрепления 1:1 без замены связей.
            $detailMap = [];
            foreach ($detailOrder as $detailId) {
                $node = $detailGraph[$detailId] ?? null;
                if (!$node) {
                    continue;
                }
                $detailMap[$detailId] = $this->cloneDetailNodeRaw($node, $detailsIblockId);
            }

            // Шаг 3: remap связей в клонированном пресете (CALC_DETAILS/CALC_STAGES).
            $mappedRootDetailIds = $this->mapIdListOrFail($rootDetailIds, $detailMap, 'деталей пресета');
            $mappedPresetStageIds = $this->mapIdListOrFail(
                $this->normalizeToIntArray($propertyValues['CALC_STAGES'] ?? []),
                $stageMap,
                'этапов пресета'
            );

            $propertyValues['CALC_DETAILS'] = !empty($mappedRootDetailIds) ? $mappedRootDetailIds : false;
            $propertyValues['CALC_STAGES'] = !empty($mappedPresetStageIds) ? $mappedPresetStageIds : false;
            $propertyValues['CALC_SETTINGS'] = $this->mapIdListOrFail(
                $this->normalizeToIntArray($propertyValues['CALC_SETTINGS'] ?? []),
                $settingsMap,
                'настроек пресета'
            ) ?: false;
            $propertyValues = $this->remapPresetStageReferences($propertyValues, $stageMap);
            
            \CIBlockElement::SetPropertyValuesEx($newPresetId, $presetsIblockId, $propertyValues);

            // Шаг 4: remap связей в каждой клонированной детали.
            foreach ($detailOrder as $detailId) {
                $node = $detailGraph[$detailId] ?? null;
                if (!$node || !isset($detailMap[$detailId])) {
                    continue;
                }

                $newDetailId = (int)$detailMap[$detailId];

                $mappedStageIds = $this->mapIdListOrFail(
                    $this->normalizeToIntArray($node['stageIds'] ?? []),
                    $stageMap,
                    'этапов детали ID=' . $detailId
                );

                $mappedChildIds = $this->mapIdListOrFail(
                    $this->normalizeToIntArray($node['detailIds'] ?? []),
                    $detailMap,
                    'DETAILS детали ID=' . $detailId
                );

                $this->setMultipleElementLinkProperty($newDetailId, $detailsIblockId, 'CALC_STAGES', $mappedStageIds);
                $this->setMultipleElementLinkProperty($newDetailId, $detailsIblockId, 'DETAILS', $mappedChildIds);

                if (($node['type'] ?? 'DETAIL') === 'BINDING') {
                    $bindingEnumId = $this->getListPropertyEnumId($detailsIblockId, 'TYPE', 'BINDING');
                    if ($bindingEnumId <= 0) {
                        throw new \Exception('Не найден enum TYPE=BINDING');
                    }
                    \CIBlockElement::SetPropertyValuesEx($newDetailId, $detailsIblockId, ['TYPE' => $bindingEnumId]);
                }
            }

            // Валидация полноты клонирования
            if (count($detailMap) !== count($detailGraph)) {
                throw new \Exception('Обнаружен повтор или потеря при клонировании деталей: ожидалось '
                    . count($detailGraph) . ', создано ' . count($detailMap));
            }

            // Шаг 7: копируем товарный каталог (НДС/закупочная/валюта/цены).
            $this->cloneCatalogData($presetId, $newPresetId);

            return $newPresetId;
    }

    /**
     * Переназначить все ссылки на этапы, хранящиеся в свойствах пресета.
     * Числовые ID меняются только в stageIds структуры STAGE_GROUPS, а ссылки
     * вида stage_{id} — во всех строковых значениях (глобальные формулы и JSON).
     *
     * @param array<int, int> $stageMap
     */
    private function remapPresetStageReferences(array $propertyValues, array $stageMap): array
    {
        foreach ($propertyValues as $code => $value) {
            if ($code === 'STAGE_GROUPS') {
                $propertyValues[$code] = $this->remapStageGroupsValue($value, $stageMap);
                continue;
            }
            $propertyValues[$code] = $this->replaceStageTokensRecursive($value, $stageMap);
        }

        return $propertyValues;
    }

    /**
     * @param mixed $value
     * @param array<int, int> $stageMap
     * @return mixed
     */
    private function remapStageGroupsValue($value, array $stageMap)
    {
        $text = null;
        $write = null;

        if (is_string($value)) {
            $text = $value;
            $write = static function (string $next) {
                return $next;
            };
        } elseif (is_array($value) && isset($value['TEXT'])) {
            $text = (string)$value['TEXT'];
            $write = static function (string $next) use ($value) {
                $value['TEXT'] = $next;
                return $value;
            };
        } elseif (is_array($value) && isset($value['VALUE']['TEXT'])) {
            $text = (string)$value['VALUE']['TEXT'];
            $write = static function (string $next) use ($value) {
                $value['VALUE']['TEXT'] = $next;
                return $value;
            };
        }

        if ($text === null || trim($text) === '') {
            return $value;
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new \Exception('STAGE_GROUPS исходного пресета содержит некорректный JSON');
        }

        $decoded = $this->remapStageGroupNode($decoded, $stageMap);
        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \Exception('Не удалось сериализовать STAGE_GROUPS клонированного пресета');
        }

        return $write($encoded);
    }

    /**
     * @param mixed $node
     * @param array<int, int> $stageMap
     * @return mixed
     */
    private function remapStageGroupNode($node, array $stageMap)
    {
        if (!is_array($node)) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if ($key === 'stageIds' && is_array($value)) {
                $node[$key] = $this->mapIdListOrFail(
                    $this->normalizeToIntArray($value),
                    $stageMap,
                    'STAGE_GROUPS'
                );
                continue;
            }
            $node[$key] = $this->remapStageGroupNode($value, $stageMap);
        }

        return $node;
    }

    /**
     * @param mixed $value
     * @param array<int, int> $stageMap
     * @return mixed
     */
    private function replaceStageTokensRecursive($value, array $stageMap)
    {
        if (is_string($value)) {
            return $this->replaceStageIdsInString($value, $stageMap);
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $nestedValue) {
            $value[$key] = $this->replaceStageTokensRecursive($nestedValue, $stageMap);
        }
        return $value;
    }

    /**
     * @param mixed $value
     * @return int[]
     */
    private function extractStageIdsFromValue($value): array
    {
        $result = [];
        $walk = function ($item) use (&$walk, &$result): void {
            if (is_array($item)) {
                foreach ($item as $nested) {
                    $walk($nested);
                }
                return;
            }
            if (!is_string($item) || !preg_match_all('/stage_(\d+)/u', $item, $matches)) {
                return;
            }
            foreach (($matches[1] ?? []) as $stageIdRaw) {
                $stageId = (int)$stageIdRaw;
                if ($stageId > 0) {
                    $result[$stageId] = true;
                }
            }
        };
        $walk($value);
        return array_map('intval', array_keys($result));
    }

    private function getElementLinkPropertyId(int $iblockId, int $elementId, string $propertyCode): int
    {
        $row = \CIBlockElement::GetProperty(
            $iblockId,
            $elementId,
            ['sort' => 'asc', 'id' => 'asc'],
            ['CODE' => $propertyCode]
        )->Fetch();
        return (int)($row['VALUE'] ?? 0);
    }

    /**
     * Рекурсивно загрузить граф деталей (деталь/скрепление) в порядке обхода.
     *
     * @param int[] $detailIds
     * @param array<int, array> $graph
     * @param int[] $order
     * @param array<int, bool> $stack
     */
    private function collectDetailGraph($detailIds, $detailsIblockId, array &$graph, array &$order, $stack)
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
            $propertyValues = $this->getElementPropertyValuesForClone($detailId, $detailsIblockId);

            $type = $this->resolveDetailTypeXmlId($propertyValues['TYPE'] ?? null, $detailsIblockId);
            $childIds = $this->normalizeToIntArray($propertyValues['DETAILS'] ?? []);
            $stageIds = $this->normalizeToIntArray($propertyValues['CALC_STAGES'] ?? []);

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

        return $result;
    }

    /**
     * Расширить список этапов для клонирования, добавляя зависимости из INPUTS.DESCRIPTION (stage_{id}).
     *
     * @param int[] $initialStageIds
     * @return int[]
     */
    private function expandStageIdsForClone(array $initialStageIds, int $stagesIblockId): array
    {
        $result = [];
        $visited = [];
        $queue = [];

        foreach ($initialStageIds as $stageId) {
            $stageId = (int)$stageId;
            if ($stageId <= 0 || isset($visited[$stageId])) {
                continue;
            }

            $visited[$stageId] = true;
            $result[] = $stageId;
            $queue[] = $stageId;
        }

        while (!empty($queue)) {
            $currentStageId = (int)array_shift($queue);
            if ($currentStageId <= 0) {
                continue;
            }

            $stageProps = $this->getElementPropertyValuesForClone($currentStageId, $stagesIblockId);
            $linkedStageIds = $this->extractStageIdsFromInputsDescription($stageProps['INPUTS'] ?? null);

            foreach ($linkedStageIds as $linkedStageId) {
                if (isset($visited[$linkedStageId])) {
                    continue;
                }

                $visited[$linkedStageId] = true;
                $result[] = $linkedStageId;
                $queue[] = $linkedStageId;
            }
        }

        return $result;
    }

    /**
     * @param mixed $inputs
     * @return int[]
     */
    private function extractStageIdsFromInputsDescription($inputs): array
    {
        if (!is_array($inputs) || $inputs === []) {
            return [];
        }

        $stageIds = [];
        foreach ($inputs as $input) {
            if (!is_array($input)) {
                continue;
            }

            $description = (string)($input['DESCRIPTION'] ?? '');
            if ($description === '') {
                continue;
            }

            if (!preg_match_all('/stage_(\d+)/u', $description, $matches)) {
                continue;
            }

            foreach (($matches[1] ?? []) as $stageIdRaw) {
                $stageId = (int)$stageIdRaw;
                if ($stageId > 0) {
                    $stageIds[$stageId] = true;
                }
            }
        }

        return array_map('intval', array_keys($stageIds));
    }

    /**
     * Клонировать деталь/скрепление 1:1 (без remap связей).
     *
     * @param array $node Узел графа детали/скрепления
     */
    private function cloneDetailNodeRaw($node, $detailsIblockId)
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
        \CIBlockElement::SetPropertyValuesEx($newId, $detailsIblockId, $propertyValues);

        return $newId;
    }

    /**
     * Разрешить XML_ID типа детали из значения свойства TYPE.
     *
     * @param mixed $typePropertyValue enum ID или иной формат значения TYPE
     */
    private function resolveDetailTypeXmlId($typePropertyValue, int $iblockId): string
    {
        $enumId = (int)$typePropertyValue;

        if ($enumId > 0) {
            $enum = \CIBlockPropertyEnum::GetList([], ['IBLOCK_ID' => $iblockId, 'ID' => $enumId])->Fetch();
            $enumXmlId = (string)($enum['XML_ID'] ?? '');
            if ($enumXmlId !== '') {
                return $enumXmlId;
            }
        }

        return 'DETAIL';
    }

    /**
     * Для множественных E-свойств Bitrix стабильно сохраняет порядок при схеме: clear -> set.
     *
     * @param int[] $ids
     */
    private function setMultipleElementLinkProperty($elementId, $iblockId, $propertyCode, $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static function ($id) {
            return $id > 0;
        }));

        \CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [$propertyCode => false]);

        if (!empty($ids)) {
            \CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [$propertyCode => $ids]);
        }
    }

    /**
     * @param int[] $sourceIds
     * @param array<int, int> $idMap
     * @return int[]
     */
    private function mapIdListOrFail($sourceIds, $idMap, $context)
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

    private function getListPropertyEnumId($iblockId, $propertyCode, $xmlId)
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
        if ($offerId <= 0) {
            return 0;
        }
        $rsOffer = \CIBlockElement::GetList(
            [],
            ['ID' => $offerId],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID']
        );
        $offer = $rsOffer->Fetch();
        $offerIblockId = (int)($offer['IBLOCK_ID'] ?? 0);
        if ($offerIblockId <= 0) {
            return 0;
        }

        return $this->getElementLinkPropertyId($offerIblockId, $offerId, 'CML2_LINK');
    }

    private function resolveElementIblockId(int $elementId): int
    {
        if ($elementId <= 0) {
            return 0;
        }
        $row = \CIBlockElement::GetList(
            [],
            ['ID' => $elementId],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID']
        )->Fetch();
        return (int)($row['IBLOCK_ID'] ?? 0);
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
                $value = $this->extractHtmlPropertyValueForClone($prop);
            }

            $withDescription = (string)($prop['WITH_DESCRIPTION'] ?? 'N') === 'Y';
            $preparedValue = $withDescription
                ? ['VALUE' => $value, 'DESCRIPTION' => (string)($prop['DESCRIPTION'] ?? '')]
                : $value;

            if (($prop['MULTIPLE'] ?? 'N') === 'Y') {
                if (!array_key_exists($code, $result) || !is_array($result[$code])) {
                    $result[$code] = [];
                }
                $result[$code][] = $preparedValue;
            } else {
                $result[$code] = $preparedValue;
            }
        }

        return $result;
    }

    /**
     * CIBlockElement::GetProperty возвращает HTML-свойство в двух формах:
     * VALUE['TEXT'] в актуальных версиях Bitrix и ~VALUE['TEXT'] в части
     * совместимых обработчиков. Нельзя приводить VALUE-массив к строке:
     * результатом будет "Array" и валидный JSON свойства будет потерян.
     */
    private function extractHtmlPropertyValueForClone(array $property): array
    {
        $rawValue = $property['~VALUE'] ?? $property['VALUE'] ?? '';
        $fallbackValue = $property['VALUE'] ?? '';

        if (is_array($rawValue)) {
            $text = $rawValue['TEXT'] ?? '';
        } elseif (is_array($fallbackValue)) {
            $text = $fallbackValue['TEXT'] ?? '';
        } else {
            $text = $rawValue;
        }

        return [
            'TEXT' => (string)$text,
            // USER_TYPE=HTML is the schema authority. Historical rows can
            // still expose TEXT/text here, while SetPropertyValuesEx writes
            // them back as HTML. Canonicalize that storage-only difference so
            // clone read-back remains strict about the actual payload.
            'TYPE' => 'HTML',
        ];
    }

    private function normalizeToIntArray($value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $value = array_map(static function ($item) {
            if (is_array($item) && array_key_exists('VALUE', $item)) {
                return $item['VALUE'];
            }

            return $item;
        }, $value);

        return array_values(array_filter(array_map('intval', $value), static function ($id) {
            return $id > 0;
        }));
    }

    /**
     * Клонировать элемент этапа (CALC_STAGES) для пресета.
     *
     * @param int $stageId ID оригинального этапа
     * @param int $stagesIblockId pinned ID инфоблока этапов
     * @return int|null ID нового этапа или null при ошибке
     */
    private function cloneStageElement(int $stageId, int $stagesIblockId, array $settingsMap = []): ?int
    {
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
        if (array_key_exists('CALC_SETTINGS', $propValues)) {
            $propValues['CALC_SETTINGS'] = $this->mapIdListOrFail(
                $this->normalizeToIntArray($propValues['CALC_SETTINGS']),
                $settingsMap,
                'настроек этапа ID=' . $stageId
            ) ?: false;
        }
        if (!empty($propValues)) {
            \CIBlockElement::SetPropertyValuesEx($newId, $stagesIblockId, $propValues);
        }

        return $newId;
    }

    private function cloneSettingsElement(int $settingsId, int $settingsIblockId): ?int
    {
        if ($settingsId <= 0 || $settingsIblockId <= 0) {
            return null;
        }
        $element = \CIBlockElement::GetList(
            [],
            ['ID' => $settingsId, 'IBLOCK_ID' => $settingsIblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME', 'ACTIVE', 'SORT', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE']
        )->GetNextElement();
        if (!$element) {
            return null;
        }
        $fields = $element->GetFields();
        $newId = (int)(new \CIBlockElement())->Add([
            'IBLOCK_ID' => $settingsIblockId,
            'NAME' => (string)$fields['NAME'],
            'CODE' => $this->generateUniqueElementCode($settingsIblockId, (string)$fields['NAME']),
            'ACTIVE' => (string)($fields['ACTIVE'] ?? 'Y'),
            'SORT' => (int)($fields['SORT'] ?? 500),
            'PREVIEW_TEXT' => (string)($fields['PREVIEW_TEXT'] ?? ''),
            'PREVIEW_TEXT_TYPE' => (string)($fields['PREVIEW_TEXT_TYPE'] ?? 'text'),
            'DETAIL_TEXT' => (string)($fields['DETAIL_TEXT'] ?? ''),
            'DETAIL_TEXT_TYPE' => (string)($fields['DETAIL_TEXT_TYPE'] ?? 'text'),
        ]);
        if ($newId <= 0) {
            return null;
        }
        $properties = $this->getElementPropertyValuesForClone($settingsId, $settingsIblockId);
        if ($properties !== []) {
            \CIBlockElement::SetPropertyValuesEx($newId, $settingsIblockId, $properties);
        }
        return $newId;
    }

    /** @param array<int,int> $settingsMap @param array<int,int> $stageMap */
    private function remapSettingsStageReferences(
        array $settingsMap,
        int $settingsIblockId,
        array $stageMap
    ): void {
        foreach ($settingsMap as $newSettingsId) {
            $properties = $this->getElementPropertyValuesForClone((int)$newSettingsId, $settingsIblockId);
            $mapped = $this->replaceStageTokensRecursive($properties, $stageMap);
            if ($mapped !== $properties) {
                \CIBlockElement::SetPropertyValuesEx((int)$newSettingsId, $settingsIblockId, $mapped);
            }
        }
    }

    /**
     * Formula/settings rows are the executable body of a calculator. Prove
     * every cloned field and property after ID remapping while the caller's
     * transaction is still open; any partial Bitrix write must roll back the
     * whole clone.
     *
     * @param array<int,int> $settingsMap
     * @param array<int,int> $stageMap
     */
    private function assertSettingsCloneReadBack(
        array $settingsMap,
        int $settingsIblockId,
        array $stageMap
    ): void {
        foreach ($settingsMap as $sourceSettingsId => $targetSettingsId) {
            $loadFields = static function (int $elementId) use ($settingsIblockId): array {
                $element = \CIBlockElement::GetList(
                    [],
                    ['ID' => $elementId, 'IBLOCK_ID' => $settingsIblockId],
                    false,
                    ['nTopCount' => 1],
                    [
                        'ID', 'NAME', 'ACTIVE', 'SORT', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE',
                        'DETAIL_TEXT', 'DETAIL_TEXT_TYPE',
                    ]
                )->GetNextElement();
                if (!$element) {
                    throw new \RuntimeException('Cloned calculator settings read-back is missing.', 409);
                }
                $fields = $element->GetFields();
                return [
                    'NAME' => (string)($fields['NAME'] ?? ''),
                    'ACTIVE' => (string)($fields['ACTIVE'] ?? 'N'),
                    'SORT' => (int)($fields['SORT'] ?? 0),
                    'PREVIEW_TEXT' => (string)($fields['PREVIEW_TEXT'] ?? ''),
                    'PREVIEW_TEXT_TYPE' => (string)($fields['PREVIEW_TEXT_TYPE'] ?? 'text'),
                    'DETAIL_TEXT' => (string)($fields['DETAIL_TEXT'] ?? ''),
                    'DETAIL_TEXT_TYPE' => (string)($fields['DETAIL_TEXT_TYPE'] ?? 'text'),
                ];
            };

            $sourceFields = $loadFields((int)$sourceSettingsId);
            $targetFields = $loadFields((int)$targetSettingsId);
            if (!hash_equals(
                \Prospektweb\Calc\Services\PresetMutationCoordinatorService::hashCanonical($sourceFields),
                \Prospektweb\Calc\Services\PresetMutationCoordinatorService::hashCanonical($targetFields)
            )) {
                throw new \RuntimeException('Cloned calculator settings fields differ from the source.', 409);
            }

            $expectedProperties = $this->replaceStageTokensRecursive(
                $this->getElementPropertyValuesForClone((int)$sourceSettingsId, $settingsIblockId),
                $stageMap
            );
            $actualProperties = $this->getElementPropertyValuesForClone(
                (int)$targetSettingsId,
                $settingsIblockId
            );
            if (!hash_equals(
                \Prospektweb\Calc\Services\PresetMutationCoordinatorService::hashCanonical($expectedProperties),
                \Prospektweb\Calc\Services\PresetMutationCoordinatorService::hashCanonical($actualProperties)
            )) {
                throw new \RuntimeException('Cloned calculator settings properties differ from the source.', 409);
            }
        }
    }

    /**
     * Обновить ссылки вида stage_{id} в DESCRIPTION свойства INPUTS у клонированных этапов.
     *
     * @param array<int, int> $stageMap [oldStageId => newStageId]
     */
    private function remapStageInputDescriptions(array $stageMap, int $stagesIblockId): void
    {
        if (empty($stageMap)) {
            return;
        }

        if ($stagesIblockId <= 0) {
            return;
        }

        foreach ($stageMap as $newStageId) {
            $newStageId = (int)$newStageId;
            if ($newStageId <= 0) {
                continue;
            }

            $propValues = $this->getElementPropertyValuesForClone($newStageId, $stagesIblockId);
            $inputs = $propValues['INPUTS'] ?? null;

            if (!is_array($inputs) || $inputs === []) {
                continue;
            }

            $updatedInputs = [];
            $hasChanges = false;

            foreach ($inputs as $input) {
                if (!is_array($input) || !array_key_exists('VALUE', $input)) {
                    $updatedInputs[] = $input;
                    continue;
                }

                $description = (string)($input['DESCRIPTION'] ?? '');
                $missingStageIds = $this->getMissingStageIdsInString($description, $stageMap);
                if (!empty($missingStageIds)) {
                    $this->logMissingStageMappings($newStageId, $missingStageIds, $description);
                }

                $mappedDescription = $this->replaceStageIdsInString($description, $stageMap);
                if ($mappedDescription !== $description) {
                    $hasChanges = true;
                }

                $input['DESCRIPTION'] = $mappedDescription;
                $updatedInputs[] = $input;
            }

            if ($hasChanges) {
                \CIBlockElement::SetPropertyValuesEx($newStageId, $stagesIblockId, [
                    'INPUTS' => $updatedInputs,
                ]);
            }
        }
    }

    /**
     * Заменить подстроки stage_{oldId} на stage_{newId} согласно карте соответствия.
     *
     * @param array<int, int> $stageMap [oldStageId => newStageId]
     */
    private function replaceStageIdsInString(string $value, array $stageMap): string
    {
        if ($value === '') {
            return $value;
        }

        return (string)preg_replace_callback('/stage_(\d+)/u', static function (array $matches) use ($stageMap) {
            $oldStageId = (int)($matches[1] ?? 0);
            if ($oldStageId > 0 && isset($stageMap[$oldStageId])) {
                return 'stage_' . (int)$stageMap[$oldStageId];
            }

            return $matches[0];
        }, $value);
    }

    /**
     * @param array<int, int> $stageMap
     * @return int[]
     */
    private function getMissingStageIdsInString(string $value, array $stageMap): array
    {
        if ($value === '' || !preg_match_all('/stage_(\d+)/u', $value, $matches)) {
            return [];
        }

        $missing = [];
        foreach (($matches[1] ?? []) as $stageIdRaw) {
            $stageId = (int)$stageIdRaw;
            if ($stageId > 0 && !isset($stageMap[$stageId])) {
                $missing[$stageId] = true;
            }
        }

        return array_map('intval', array_keys($missing));
    }

    /**
     * @param int[] $missingStageIds
     */
    private function logMissingStageMappings(int $newStageId, array $missingStageIds, string $description): void
    {
        $message = sprintf(
            'BundleHandler: missing stage mapping for stage ID(s) [%s] while remapping INPUTS.DESCRIPTION in cloned stage ID=%d. Description: %s',
            implode(', ', $missingStageIds),
            $newStageId,
            $description
        );

        if (function_exists('AddMessage2Log')) {
            AddMessage2Log($message, self::MODULE_ID);
            return;
        }

        error_log($message);
    }

    /**
     * Клонировать данные торгового каталога (цены, НДС, валюта) из одного элемента в другой.
     *
     * @param int $sourceId ID исходного элемента
     * @param int $targetId ID целевого элемента
     */
    private function cloneCatalogData($sourceId, $targetId)
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
     * Generate an iblock-local unique element code.
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

}
