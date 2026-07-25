<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

final class CatalogTreeService
{
    public function tree(array $request): array
    {
        $this->assertAdmin();
        $iblock = $this->resolveIblock($request);
        $iblockId = (int)$iblock['ID'];
        $sections = [];
        $sectionCursor = \CIBlockSection::GetList(
            ['LEFT_MARGIN' => 'ASC', 'SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId],
            false,
            ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'ACTIVE', 'SORT']
        );
        while ($row = $sectionCursor->Fetch()) {
            $sections[] = [
                'type' => 'section',
                'id' => (int)$row['ID'],
                'iblockId' => $iblockId,
                'parentId' => (int)($row['IBLOCK_SECTION_ID'] ?? 0),
                'name' => (string)$row['NAME'],
                'code' => (string)($row['CODE'] ?? ''),
                'active' => ($row['ACTIVE'] ?? 'N') === 'Y',
                'sort' => (int)($row['SORT'] ?? 500),
                'children' => [],
            ];
        }

        $elements = [];
        $elementCursor = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'ACTIVE', 'SORT', 'PREVIEW_TEXT', 'DETAIL_TEXT']
        );
        while ($row = $elementCursor->Fetch()) {
            $elements[] = $this->normalizeElement($row, (string)$iblock['CODE']);
        }

        return [
            'status' => 'ok',
            'iblock' => [
                'id' => $iblockId,
                'code' => (string)$iblock['CODE'],
                'name' => (string)$iblock['NAME'],
                'type' => (string)$iblock['IBLOCK_TYPE_ID'],
            ],
            'tree' => $this->buildTree($sections, $elements),
            'counts' => ['sections' => count($sections), 'elements' => count($elements)],
        ];
    }

    public function saveElement(array $request): array
    {
        $this->assertAdmin();
        $iblock = $this->resolveIblock($request);
        $iblockId = (int)$iblock['ID'];
        $elementId = (int)($request['elementId'] ?? 0);
        $sectionId = $this->validateSection($iblockId, (int)($request['sectionId'] ?? 0));
        $name = trim((string)($request['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Введите название элемента');
        }

        $code = $this->normalizeCode(
            $iblockId,
            trim((string)($request['code'] ?? '')),
            $name,
            'element',
            $elementId
        );
        $fields = [
            'IBLOCK_ID' => $iblockId,
            'IBLOCK_SECTION_ID' => $sectionId > 0 ? $sectionId : false,
            'ACTIVE' => !array_key_exists('active', $request) || !empty($request['active']) ? 'Y' : 'N',
            'NAME' => $name,
            'CODE' => $code,
            'PREVIEW_TEXT' => trim((string)($request['previewText'] ?? '')),
            'PREVIEW_TEXT_TYPE' => 'text',
            'DETAIL_TEXT' => (string)($request['detailText'] ?? ''),
            'DETAIL_TEXT_TYPE' => 'html',
        ];
        $element = new \CIBlockElement();
        if ($elementId > 0) {
            $this->assertElement($iblockId, $elementId);
            if (!$element->Update($elementId, $fields)) {
                throw new \RuntimeException($element->LAST_ERROR ?: 'Не удалось сохранить элемент');
            }
        } else {
            $elementId = (int)$element->Add($fields);
            if ($elementId <= 0) {
                throw new \RuntimeException($element->LAST_ERROR ?: 'Не удалось создать элемент');
            }
        }

        if ((string)$iblock['CODE'] === 'CALC_CUSTOM_FIELDS') {
            $this->saveCustomFieldProperties($iblockId, $elementId, (array)($request['customField'] ?? []));
        }

        return ['status' => 'ok', 'nodeType' => 'element', 'nodeId' => $elementId, 'name' => $name];
    }

    public function saveSection(array $request): array
    {
        $this->assertAdmin();
        $iblock = $this->resolveIblock($request);
        $iblockId = (int)$iblock['ID'];
        $sectionId = (int)($request['sectionId'] ?? 0);
        $parentId = $this->validateSection($iblockId, (int)($request['parentSectionId'] ?? 0));
        if ($sectionId > 0) {
            $this->assertSection($iblockId, $sectionId);
            if ($parentId === $sectionId) {
                throw new \InvalidArgumentException('Раздел нельзя переместить внутрь самого себя');
            }
        }
        $name = trim((string)($request['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Введите название раздела');
        }
        $fields = [
            'IBLOCK_ID' => $iblockId,
            'IBLOCK_SECTION_ID' => $parentId > 0 ? $parentId : false,
            'ACTIVE' => !array_key_exists('active', $request) || !empty($request['active']) ? 'Y' : 'N',
            'NAME' => $name,
            'CODE' => $this->normalizeCode(
                $iblockId,
                trim((string)($request['code'] ?? '')),
                $name,
                'section',
                $sectionId
            ),
        ];
        $section = new \CIBlockSection();
        if ($sectionId > 0) {
            if (!$section->Update($sectionId, $fields)) {
                throw new \RuntimeException($section->LAST_ERROR ?: 'Не удалось сохранить раздел');
            }
        } else {
            $sectionId = (int)$section->Add($fields);
            if ($sectionId <= 0) {
                throw new \RuntimeException($section->LAST_ERROR ?: 'Не удалось создать раздел');
            }
        }
        return ['status' => 'ok', 'nodeType' => 'section', 'nodeId' => $sectionId, 'name' => $name];
    }

    public function deleteNode(array $request): array
    {
        $this->assertAdmin();
        $iblock = $this->resolveIblock($request);
        $iblockId = (int)$iblock['ID'];
        $nodeType = (string)($request['nodeType'] ?? '');
        $nodeId = (int)($request['nodeId'] ?? 0);
        if ($nodeId <= 0) {
            throw new \InvalidArgumentException('Элемент структуры не выбран');
        }
        if ($nodeType === 'element') {
            $this->assertElement($iblockId, $nodeId);
            if (!\CIBlockElement::Delete($nodeId)) {
                throw new \RuntimeException('Не удалось удалить элемент');
            }
        } elseif ($nodeType === 'section') {
            $this->assertSection($iblockId, $nodeId);
            $hasChildren = \CIBlockSection::GetList([], ['IBLOCK_ID' => $iblockId, 'SECTION_ID' => $nodeId], false, ['ID'])->Fetch();
            $hasElements = \CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, 'SECTION_ID' => $nodeId], false, ['nTopCount' => 1], ['ID'])->Fetch();
            if ($hasChildren || $hasElements) {
                throw new \RuntimeException('Сначала удалите или переместите вложенные разделы и элементы');
            }
            if (!\CIBlockSection::Delete($nodeId)) {
                throw new \RuntimeException('Не удалось удалить раздел');
            }
        } else {
            throw new \InvalidArgumentException('Неизвестный тип элемента структуры');
        }
        return ['status' => 'ok', 'nodeType' => $nodeType, 'nodeId' => $nodeId, 'deleted' => true];
    }

    private function resolveIblock(array $request): array
    {
        $iblockId = (int)($request['iblockId'] ?? 0);
        $iblockCode = trim((string)($request['iblockCode'] ?? ''));
        if ($iblockId <= 0 || $iblockCode === '') {
            throw new \InvalidArgumentException('Инфоблок не выбран');
        }
        $iblock = \CIBlock::GetByID($iblockId)->Fetch();
        if (!$iblock || (string)$iblock['CODE'] !== $iblockCode) {
            throw new \RuntimeException('Инфоблок не найден или его код изменился');
        }
        return $iblock;
    }

    private function normalizeElement(array $row, string $iblockCode): array
    {
        $result = [
            'type' => 'element',
            'id' => (int)$row['ID'],
            'iblockId' => (int)$row['IBLOCK_ID'],
            'parentId' => (int)($row['IBLOCK_SECTION_ID'] ?? 0),
            'name' => (string)$row['NAME'],
            'code' => (string)($row['CODE'] ?? ''),
            'active' => ($row['ACTIVE'] ?? 'N') === 'Y',
            'sort' => (int)($row['SORT'] ?? 500),
            'previewText' => trim(strip_tags((string)($row['PREVIEW_TEXT'] ?? ''))),
            'detailText' => (string)($row['DETAIL_TEXT'] ?? ''),
            'children' => [],
        ];
        if ($iblockCode === 'CALC_CUSTOM_FIELDS') {
            $result['customField'] = $this->loadCustomFieldProperties((int)$row['IBLOCK_ID'], (int)$row['ID']);
        }
        return $result;
    }

    private function buildTree(array $sections, array $elements, int $parentId = 0): array
    {
        $nodes = [];
        foreach ($sections as $section) {
            if ((int)$section['parentId'] !== $parentId) {
                continue;
            }
            $section['children'] = $this->buildTree($sections, $elements, (int)$section['id']);
            $nodes[] = $section;
        }
        foreach ($elements as $element) {
            if ((int)$element['parentId'] === $parentId) {
                $nodes[] = $element;
            }
        }
        return $nodes;
    }

    private function loadCustomFieldProperties(int $iblockId, int $elementId): array
    {
        $values = [];
        foreach (['FIELD_TYPE', 'DEFAULT_VALUE', 'IS_REQUIRED', 'UNIT', 'SORT_ORDER'] as $code) {
            $cursor = \CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['CODE' => $code]);
            $property = $cursor->Fetch();
            $values[$code] = $property ?: [];
        }
        return [
            'type' => (string)($values['FIELD_TYPE']['VALUE_XML_ID'] ?? $values['FIELD_TYPE']['VALUE'] ?? 'text'),
            'defaultValue' => (string)($values['DEFAULT_VALUE']['VALUE'] ?? ''),
            'required' => (string)($values['IS_REQUIRED']['VALUE_XML_ID'] ?? $values['IS_REQUIRED']['VALUE'] ?? 'N') === 'Y',
            'unit' => (string)($values['UNIT']['VALUE'] ?? ''),
            'sortOrder' => (int)($values['SORT_ORDER']['VALUE'] ?? 500),
        ];
    }

    private function saveCustomFieldProperties(int $iblockId, int $elementId, array $customField): void
    {
        $type = (string)($customField['type'] ?? 'text');
        if (!in_array($type, ['number', 'text', 'checkbox', 'select'], true)) {
            throw new \InvalidArgumentException('Неизвестный тип дополнительного поля');
        }
        $typeEnumId = $this->enumId($iblockId, 'FIELD_TYPE', $type);
        $requiredEnumId = $this->enumId($iblockId, 'IS_REQUIRED', !empty($customField['required']) ? 'Y' : 'N');
        if ($typeEnumId <= 0) {
            throw new \RuntimeException('Тип дополнительного поля не настроен в инфоблоке');
        }
        \CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [
            'FIELD_TYPE' => $typeEnumId,
            'DEFAULT_VALUE' => (string)($customField['defaultValue'] ?? ''),
            'IS_REQUIRED' => $requiredEnumId ?: false,
            'UNIT' => $type === 'number' ? trim((string)($customField['unit'] ?? '')) : '',
            'SORT_ORDER' => max(0, (int)($customField['sortOrder'] ?? 500)),
        ]);
    }

    private function enumId(int $iblockId, string $propertyCode, string $xmlId): int
    {
        $property = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, '=CODE' => $propertyCode])->Fetch();
        if (!$property) {
            return 0;
        }
        $enum = \CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => (int)$property['ID'], '=XML_ID' => $xmlId])->Fetch();
        return $enum ? (int)$enum['ID'] : 0;
    }

    private function validateSection(int $iblockId, int $sectionId): int
    {
        if ($sectionId > 0) {
            $this->assertSection($iblockId, $sectionId);
        }
        return $sectionId;
    }

    private function assertSection(int $iblockId, int $sectionId): void
    {
        if (!\CIBlockSection::GetList([], ['ID' => $sectionId, 'IBLOCK_ID' => $iblockId], false, ['ID'])->Fetch()) {
            throw new \RuntimeException('Раздел не найден');
        }
    }

    private function assertElement(int $iblockId, int $elementId): void
    {
        if (!\CIBlockElement::GetList([], ['ID' => $elementId, 'IBLOCK_ID' => $iblockId], false, ['nTopCount' => 1], ['ID'])->Fetch()) {
            throw new \RuntimeException('Элемент не найден');
        }
    }

    private function normalizeCode(int $iblockId, string $code, string $name, string $kind, int $excludeId): string
    {
        $base = $code !== '' ? $code : trim((string)\CUtil::translit($name, 'ru', [
            'replace_space' => '-',
            'replace_other' => '-',
            'change_case' => 'L',
            'delete_repeat_replace' => true,
        ]), '-');
        $base = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '-', $base), '-');
        if ($base === '') {
            $base = $kind;
        }
        $candidate = $base;
        $suffix = 2;
        do {
            if ($kind === 'section') {
                $row = \CIBlockSection::GetList([], ['IBLOCK_ID' => $iblockId, '=CODE' => $candidate], false, ['ID'])->Fetch();
            } else {
                $row = \CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, '=CODE' => $candidate], false, ['nTopCount' => 1], ['ID'])->Fetch();
            }
            if (!$row || (int)$row['ID'] === $excludeId) {
                return $candidate;
            }
            $candidate = $base . '-' . $suffix++;
        } while ($suffix < 10000);
        throw new \RuntimeException('Не удалось подобрать уникальный код');
    }

    private function assertAdmin(): void
    {
        global $USER;
        if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
            throw new \RuntimeException('Недостаточно прав');
        }
    }
}
