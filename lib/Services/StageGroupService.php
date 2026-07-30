<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Config\Option;

final class StageGroupService
{
    private const MODULE_ID = 'prospektweb.calc';
    private const PROPERTY_CODE = 'STAGE_GROUPS';

    public function save(array $request): array
    {
        global $USER;
        if (!$USER || !$USER->IsAdmin()) throw new \RuntimeException('Недостаточно прав для изменения групп этапов');
        $presetId = (int)($request['presetId'] ?? 0);
        $groups = is_array($request['groups'] ?? null) ? $request['groups'] : [];
        if ($presetId <= 0 || count($groups) > 100) throw new \InvalidArgumentException('Некорректный пресет или количество групп');
        $iblockId = (int)Option::get(self::MODULE_ID, 'IBLOCK_CALC_PRESETS', 0);
        if ($iblockId <= 0 || !\CIBlockElement::GetList([], ['ID' => $presetId, 'IBLOCK_ID' => $iblockId], false, ['nTopCount' => 1], ['ID'])->Fetch()) {
            throw new \RuntimeException('Пресет не найден');
        }
        $this->ensureProperty($iblockId);
        $stageTopology = $this->collectPresetStageTopology($presetId, $iblockId);
        $normalized = [];
        $groupIds = [];
        foreach ($groups as $index => $group) {
            if (!is_array($group)) throw new \InvalidArgumentException('Группа этапов должна быть объектом');
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($group['id'] ?? ''));
            $id = $id !== '' ? $id : 'group_' . ($index + 1) . '_' . bin2hex(random_bytes(4));
            if (isset($groupIds[$id])) throw new \InvalidArgumentException('Коды групп этапов не должны повторяться');
            $groupIds[$id] = true;
            $normalized[] = [
                'source' => $group,
                'id' => $id,
                'parentId' => trim((string)($group['parentId'] ?? '')) ?: null,
            ];
        }
        $normalizedById = [];
        foreach ($normalized as $item) {
            $normalizedById[$item['id']] = $item;
        }
        $usedByParent = [];
        $clean = [];
        foreach ($normalized as $item) {
            $group = $item['source'];
            $title = trim((string)($group['title'] ?? ''));
            $description = trim((string)($group['description'] ?? ''));
            $id = $item['id'];
            $parentId = $item['parentId'];
            if ($parentId !== null) {
                $parent = $normalizedById[$parentId] ?? null;
                if (!$parent || $parent['parentId'] !== null || $parentId === $id) {
                    throw new \InvalidArgumentException('Подгруппа должна принадлежать группе верхнего уровня');
                }
            }
            if ($title === '' || mb_strlen($title) > 250 || mb_strlen($description) > 4000) {
                throw new \InvalidArgumentException('Укажите корректное название и описание группы');
            }
            $stageIds = [];
            foreach (is_array($group['stageIds'] ?? null) ? $group['stageIds'] : [] as $stageId) {
                $stageId = (int)$stageId;
                $scope = $parentId ?? '__root__';
                if ($stageId <= 0 || isset($usedByParent[$scope][$stageId])) throw new \InvalidArgumentException('Этап не может входить в две соседние группы');
                if (!isset($stageTopology[$stageId])) throw new \InvalidArgumentException('Группа содержит этап из другого пресета');
                $usedByParent[$scope][$stageId] = true;
                $stageIds[] = $stageId;
            }
            if (count($stageIds) < 2) throw new \InvalidArgumentException('Группа должна содержать как минимум два этапа');
            $container = $stageTopology[$stageIds[0]]['container'];
            foreach ($stageIds as $stageId) {
                if ($stageTopology[$stageId]['container'] !== $container) {
                    throw new \InvalidArgumentException('Все этапы группы должны находиться в одной колонке');
                }
            }
            usort($stageIds, static fn(int $left, int $right): int =>
                $stageTopology[$left]['position'] <=> $stageTopology[$right]['position']
            );
            foreach ($stageIds as $position => $stageId) {
                if ($position > 0
                    && $stageTopology[$stageId]['position'] !== $stageTopology[$stageIds[$position - 1]]['position'] + 1) {
                    throw new \InvalidArgumentException('Этапы группы должны идти подряд');
                }
            }
            if ($parentId !== null) {
                $parent = $normalizedById[$parentId];
                $parentStageIds = array_map('intval', is_array($parent['source']['stageIds'] ?? null) ? $parent['source']['stageIds'] : []);
                if (array_diff($stageIds, $parentStageIds) !== []) {
                    throw new \InvalidArgumentException('Подгруппа может содержать только этапы родительской группы');
                }
            }
            $clean[] = [
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'stageIds' => $stageIds,
                'parentId' => $parentId,
            ];
        }
        $json = json_encode(['version' => 2, 'groups' => $clean], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $connection = \Bitrix\Main\Application::getConnection();
        $connection->startTransaction();
        try {
            \CIBlockElement::SetPropertyValues($presetId, $iblockId, [], self::PROPERTY_CODE);
            \CIBlockElement::SetPropertyValuesEx($presetId, $iblockId, [
                self::PROPERTY_CODE => ['VALUE' => ['TEXT' => $json, 'TYPE' => 'TEXT']],
            ]);
            $storedElement = \CIBlockElement::GetList(
                [],
                ['ID' => $presetId, 'IBLOCK_ID' => $iblockId],
                false,
                ['nTopCount' => 1],
                ['ID', 'IBLOCK_ID']
            )->GetNextElement();
            $storedProperties = $storedElement ? $storedElement->GetProperties() : [];
            $storedText = (string)($storedProperties[self::PROPERTY_CODE]['~VALUE']['TEXT']
                ?? $storedProperties[self::PROPERTY_CODE]['VALUE']['TEXT']
                ?? $storedProperties[self::PROPERTY_CODE]['VALUE']
                ?? '');
            if ($storedText !== $json) throw new \RuntimeException('Группы этапов не были записаны в пресет');
            $connection->commitTransaction();
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw $error;
        }
        return ['status' => 'ok', 'groups' => $clean];
    }

    private function ensureProperty(int $iblockId): int
    {
        $existing = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => self::PROPERTY_CODE])->Fetch();
        if ($existing) return (int)$existing['ID'];
        $property = new \CIBlockProperty();
        $propertyId = (int)$property->Add([
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'CODE' => self::PROPERTY_CODE,
            'NAME' => 'Группы этапов',
            'PROPERTY_TYPE' => 'S',
            'USER_TYPE' => 'HTML',
            'SORT' => 1120,
        ]);
        if ($propertyId <= 0) {
            throw new \RuntimeException('Не удалось создать свойство групп этапов: ' . trim((string)$property->LAST_ERROR));
        }
        return $propertyId;
    }

    private function collectPresetStageTopology(int $presetId, int $presetIblockId): array
    {
        $topology = [];
        foreach ($this->propertyIds($presetIblockId, $presetId, 'CALC_STAGES') as $position => $stageId) {
            $topology[(int)$stageId] = ['container' => 'preset:' . $presetId, 'position' => $position];
        }
        $detailsIblockId = (int)Option::get(self::MODULE_ID, 'IBLOCK_CALC_DETAILS', 0);
        if ($detailsIblockId <= 0) return $topology;
        $queue = $this->propertyIds($presetIblockId, $presetId, 'CALC_DETAILS');
        $visited = [];
        while ($queue !== []) {
            $detailId = (int)array_shift($queue);
            if ($detailId <= 0 || isset($visited[$detailId])) continue;
            $visited[$detailId] = true;
            foreach ($this->propertyIds($detailsIblockId, $detailId, 'CALC_STAGES') as $position => $stageId) {
                $topology[(int)$stageId] = ['container' => 'detail:' . $detailId, 'position' => $position];
            }
            foreach ($this->propertyIds($detailsIblockId, $detailId, 'DETAILS') as $childId) {
                if (!isset($visited[(int)$childId])) $queue[] = (int)$childId;
            }
        }
        return $topology;
    }

    private function propertyIds(int $iblockId, int $elementId, string $code): array
    {
        $result = [];
        $iterator = \CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['CODE' => $code]);
        while ($row = $iterator->Fetch()) {
            $value = (int)($row['VALUE'] ?? 0);
            if ($value > 0) $result[] = $value;
        }
        return $result;
    }
}
