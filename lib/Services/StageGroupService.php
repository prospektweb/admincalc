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
        $allowedStageIds = $this->collectPresetStageIds($presetId, $iblockId);
        $used = [];
        $clean = [];
        foreach ($groups as $index => $group) {
            if (!is_array($group)) throw new \InvalidArgumentException('Группа этапов должна быть объектом');
            $title = trim((string)($group['title'] ?? ''));
            $description = trim((string)($group['description'] ?? ''));
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($group['id'] ?? ''));
            if ($title === '' || mb_strlen($title) > 250 || mb_strlen($description) > 4000) {
                throw new \InvalidArgumentException('Укажите корректное название и описание группы');
            }
            $stageIds = [];
            foreach (is_array($group['stageIds'] ?? null) ? $group['stageIds'] : [] as $stageId) {
                $stageId = (int)$stageId;
                if ($stageId <= 0 || isset($used[$stageId])) throw new \InvalidArgumentException('Этап не может входить в две группы');
                if (!isset($allowedStageIds[$stageId])) throw new \InvalidArgumentException('Группа содержит этап из другого пресета');
                $used[$stageId] = true;
                $stageIds[] = $stageId;
            }
            if (count($stageIds) < 2) throw new \InvalidArgumentException('Группа должна содержать как минимум два этапа');
            $clean[] = [
                'id' => $id !== '' ? $id : 'group_' . ($index + 1) . '_' . bin2hex(random_bytes(4)),
                'title' => $title,
                'description' => $description,
                'stageIds' => $stageIds,
            ];
        }
        \CIBlockElement::SetPropertyValuesEx($presetId, $iblockId, [
            self::PROPERTY_CODE => ['VALUE' => ['TEXT' => json_encode(['version' => 1, 'groups' => $clean], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'TYPE' => 'TEXT']],
        ]);
        return ['status' => 'ok', 'groups' => $clean];
    }

    private function ensureProperty(int $iblockId): void
    {
        if (\CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, '=CODE' => self::PROPERTY_CODE])->Fetch()) return;
        $property = new \CIBlockProperty();
        if (!$property->Add([
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'CODE' => self::PROPERTY_CODE,
            'NAME' => 'Группы этапов',
            'PROPERTY_TYPE' => 'S',
            'USER_TYPE' => 'HTML',
            'SORT' => 1120,
        ])) {
            throw new \RuntimeException('Не удалось создать свойство групп этапов: ' . trim((string)$property->LAST_ERROR));
        }
    }

    private function collectPresetStageIds(int $presetId, int $presetIblockId): array
    {
        $stages = [];
        foreach ($this->propertyIds($presetIblockId, $presetId, 'CALC_STAGES') as $stageId) {
            $stages[(int)$stageId] = true;
        }
        $detailsIblockId = (int)Option::get(self::MODULE_ID, 'IBLOCK_CALC_DETAILS', 0);
        if ($detailsIblockId <= 0) return $stages;
        $queue = $this->propertyIds($presetIblockId, $presetId, 'CALC_DETAILS');
        $visited = [];
        while ($queue !== []) {
            $detailId = (int)array_shift($queue);
            if ($detailId <= 0 || isset($visited[$detailId])) continue;
            $visited[$detailId] = true;
            foreach ($this->propertyIds($detailsIblockId, $detailId, 'CALC_STAGES') as $stageId) $stages[(int)$stageId] = true;
            foreach ($this->propertyIds($detailsIblockId, $detailId, 'DETAILS') as $childId) {
                if (!isset($visited[(int)$childId])) $queue[] = (int)$childId;
            }
        }
        return $stages;
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
