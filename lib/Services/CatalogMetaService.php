<?php

namespace Prospektweb\Calc\Services;

use Prospektweb\Calc\Config\ConfigManager;

final class CatalogMetaService
{
    private const IBLOCK_CODES = [
        'calculator' => ['CALC_SETTINGS'],
        'equipment' => ['CALC_EQUIPMENT'],
        'operation' => ['CALC_OPERATIONS', 'CALC_OPERATIONS_VARIANTS'],
        'material' => ['CALC_MATERIALS', 'CALC_MATERIALS_VARIANTS'],
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
        if ($entities === []) throw new \InvalidArgumentException('Не переданы элементы для сохранения');
        $allowedEntityIds = $this->resolveAllowedEntityIds($type, $iblocks, (int)($entities[0]['id'] ?? 0));
        $updated = [];
        foreach ($entities as $entity) {
            $id = (int)($entity['id'] ?? 0);
            $name = trim((string)($entity['name'] ?? ''));
            if ($id <= 0 || $name === '') throw new \InvalidArgumentException('Название технического элемента не может быть пустым');
            if (!isset($allowedEntityIds[$id])) throw new \InvalidArgumentException('Элемент не относится к выбранному родителю или его вариантам');
            $loaded = $this->loadElement($id, $iblocks);
            $element = new \CIBlockElement();
            $preview = trim((string)($entity['previewText'] ?? ''));
            if (!$element->Update($id, ['NAME' => $name, 'PREVIEW_TEXT' => $preview, 'PREVIEW_TEXT_TYPE' => 'text'])) throw new \RuntimeException($element->LAST_ERROR ?: 'Не удалось сохранить технический элемент');
            $parameters = $this->normalizeParameters((array)($entity['parameters'] ?? []));
            \CIBlockElement::SetPropertyValuesEx($id, (int)$loaded['iblockId'], [
                'PARAMETRS' => $parameters ? array_map(static function (array $parameter): array {
                    return [
                        'VALUE' => $parameter['code'],
                        'DESCRIPTION' => implode('|', [$parameter['value'], $parameter['title'], $parameter['description']]),
                    ];
                }, $parameters) : false,
            ]);
            $updated[] = ['id' => $id, 'name' => $name, 'previewText' => $preview, 'parameters' => $parameters];
        }
        return ['status' => 'ok', 'entityType' => $type, 'entities' => $updated];
    }

    private function resolveAllowedEntityIds(string $type, array $iblocks, int $anchorId): array
    {
        if ($anchorId <= 0) return [];
        $anchor = $this->loadElement($anchorId, $iblocks);
        if ($type === 'calculator' || $type === 'equipment') return [$anchorId => true];

        $parentId = $anchor['iblockId'] === $iblocks[0]
            ? $anchorId
            : $this->getParentId($anchorId, $iblocks[1]);
        if ($parentId <= 0) throw new \RuntimeException('Не удалось определить родительский элемент');

        $allowed = [$parentId => true];
        $cursor = \CIBlockElement::GetList([], ['IBLOCK_ID' => $iblocks[1], 'PROPERTY_CML2_LINK' => $parentId], false, false, ['ID']);
        while ($row = $cursor->Fetch()) $allowed[(int)$row['ID']] = true;
        return $allowed;
    }

    private function getIblocks(string $type): array
    {
        if (!isset(self::IBLOCK_CODES[$type])) throw new \InvalidArgumentException('Неизвестный тип технического элемента');
        $config = new ConfigManager();
        $ids = array_map(static fn(string $code): int => $config->getIblockId($code), self::IBLOCK_CODES[$type]);
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
        return [
            'id' => (int)$row['ID'],
            'iblockId' => (int)$row['IBLOCK_ID'],
            'name' => (string)$row['NAME'],
            'code' => (string)$row['CODE'],
            'previewText' => trim(strip_tags((string)($row['PREVIEW_TEXT'] ?? ''))),
            'parameters' => $this->loadParameters((int)$row['ID'], (int)$row['IBLOCK_ID']),
        ];
    }

    private function loadParameters(int $elementId, int $iblockId): array
    {
        $result = [];
        $cursor = \CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc', 'id' => 'asc'], ['CODE' => 'PARAMETRS']);
        while ($property = $cursor->Fetch()) {
            $code = trim((string)($property['VALUE'] ?? ''));
            if ($code === '') continue;
            $parts = explode('|', (string)($property['DESCRIPTION'] ?? ''), 3);
            $result[] = [
                'code' => $code,
                'value' => trim((string)($parts[0] ?? '')),
                'title' => trim((string)($parts[1] ?? '')),
                'description' => trim((string)($parts[2] ?? '')),
            ];
        }
        return $result;
    }

    private function normalizeParameters(array $parameters): array
    {
        $result = [];
        $codes = [];
        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) continue;
            $code = trim((string)($parameter['code'] ?? ''));
            $title = trim((string)($parameter['title'] ?? ''));
            $value = trim((string)($parameter['value'] ?? ''));
            $description = trim((string)($parameter['description'] ?? ''));
            if ($code === '' && $title === '' && $value === '' && $description === '') continue;
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $code)) {
                throw new \InvalidArgumentException('Код параметра должен начинаться с латинской буквы или _ и содержать только латиницу, цифры и _');
            }
            if (isset($codes[$code])) throw new \InvalidArgumentException('Коды параметров внутри одного элемента не должны повторяться');
            if (strpos($title, '|') !== false || strpos($value, '|') !== false || strpos($description, '|') !== false) {
                throw new \InvalidArgumentException('Символ | зарезервирован и недопустим в названии, значении и описании параметра');
            }
            $codes[$code] = true;
            $result[] = compact('code', 'title', 'value', 'description');
        }
        return $result;
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
