<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Config\Option;

final class CatalogMetaService
{
    private const MODULE_ID = 'prospektweb.calc';
    private const OPTIONS = [
        'calculator' => ['IBLOCK_CALC_SETTINGS'],
        'equipment' => ['IBLOCK_CALC_EQUIPMENT'],
        'operation' => ['IBLOCK_CALC_OPERATIONS', 'IBLOCK_CALC_OPERATION_VARIANTS'],
        'material' => ['IBLOCK_CALC_MATERIALS', 'IBLOCK_CALC_MATERIAL_VARIANTS'],
    ];

    public function get(array $request): array
    {
        $this->assertAdmin();
        $type = (string)($request['entityType'] ?? '');
        $id = (int)($request['entityId'] ?? 0);
        $iblocks = $this->getIblocks($type);
        if ($id <= 0) throw new \InvalidArgumentException('Элемент не выбран');
        if ($type === 'calculator' || $type === 'equipment') {
            $entity = $this->loadElement($id, [$iblocks[0]]);
            return ['status' => 'ok', 'entityType' => $type, 'parent' => $entity, 'variants' => []];
        }
        $selected = $this->loadElement($id, $iblocks);
        $parentId = $selected['iblockId'] === $iblocks[0] ? $selected['id'] : $this->getParentId($id, $iblocks[1]);
        if ($parentId <= 0) throw new \RuntimeException('Не удалось определить родительский элемент');
        $parent = $this->loadElement($parentId, [$iblocks[0]]);
        $variants = [];
        $cursor = \CIBlockElement::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['IBLOCK_ID' => $iblocks[1], 'PROPERTY_CML2_LINK' => $parentId], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'PREVIEW_TEXT']);
        while ($row = $cursor->Fetch()) $variants[] = $this->normalize($row);
        return ['status' => 'ok', 'entityType' => $type, 'parent' => $parent, 'variants' => $variants];
    }

    public function save(array $request): array
    {
        $this->assertAdmin();
        $type = (string)($request['entityType'] ?? '');
        $iblocks = $this->getIblocks($type);
        $entities = is_array($request['entities'] ?? null) ? $request['entities'] : [];
        $updated = [];
        foreach ($entities as $entity) {
            $id = (int)($entity['id'] ?? 0);
            $name = trim((string)($entity['name'] ?? ''));
            if ($id <= 0 || $name === '') throw new \InvalidArgumentException('Название технического элемента не может быть пустым');
            $this->loadElement($id, $iblocks);
            $element = new \CIBlockElement();
            $preview = trim((string)($entity['previewText'] ?? ''));
            if (!$element->Update($id, ['NAME' => $name, 'PREVIEW_TEXT' => $preview, 'PREVIEW_TEXT_TYPE' => 'text'])) throw new \RuntimeException($element->LAST_ERROR ?: 'Не удалось сохранить технический элемент');
            $updated[] = ['id' => $id, 'name' => $name, 'previewText' => $preview];
        }
        return ['status' => 'ok', 'entityType' => $type, 'entities' => $updated];
    }

    private function getIblocks(string $type): array
    {
        if (!isset(self::OPTIONS[$type])) throw new \InvalidArgumentException('Неизвестный тип технического элемента');
        $ids = array_map(static fn(string $option): int => (int)Option::get(self::MODULE_ID, $option, 0), self::OPTIONS[$type]);
        if (in_array(0, $ids, true)) throw new \RuntimeException('Инфоблок технического элемента не настроен');
        return $ids;
    }

    private function loadElement(int $id, array $allowedIblocks): array
    {
        $cursor = \CIBlockElement::GetList([], ['ID' => $id, 'IBLOCK_ID' => $allowedIblocks], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'PREVIEW_TEXT']);
        $row = $cursor->Fetch();
        if (!$row) throw new \RuntimeException('Технический элемент не найден или относится к другому инфоблоку');
        return $this->normalize($row);
    }

    private function normalize(array $row): array
    {
        return ['id' => (int)$row['ID'], 'iblockId' => (int)$row['IBLOCK_ID'], 'name' => (string)$row['NAME'], 'code' => (string)$row['CODE'], 'previewText' => trim(strip_tags((string)($row['PREVIEW_TEXT'] ?? '')))];
    }

    private function getParentId(int $variantId, int $iblockId): int
    {
        $properties = [];
        $cursor = \CIBlockElement::GetProperty($iblockId, $variantId, ['sort' => 'asc'], ['CODE' => 'CML2_LINK']);
        while ($property = $cursor->Fetch()) $properties[] = (int)$property['VALUE'];
        return $properties[0] ?? 0;
    }

    private function assertAdmin(): void
    {
        global $USER;
        if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) throw new \RuntimeException('Недостаточно прав');
    }
}
