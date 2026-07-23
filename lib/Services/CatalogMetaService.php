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
        $cursor = \CIBlockElement::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['IBLOCK_ID' => $iblocks[1], 'PROPERTY_CML2_LINK' => $parentId], false, false, ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'PREVIEW_TEXT', 'DETAIL_TEXT']);
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
        $createdVariantId = 0;
        if (!empty($request['create'])) {
            if (!in_array($type, ['operation', 'material'], true)) {
                throw new \InvalidArgumentException('Создание поддерживается только для операций и материалов');
            }
            [$entities, $createdVariantId] = $this->createEntities(
                $iblocks,
                $entities,
                (int)($request['sectionId'] ?? 0)
            );
        }
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
            $detail = (string)($entity['detailText'] ?? '');
            if (!$element->Update($id, [
                'NAME' => $name,
                'PREVIEW_TEXT' => $preview,
                'PREVIEW_TEXT_TYPE' => 'text',
                'DETAIL_TEXT' => $detail,
                'DETAIL_TEXT_TYPE' => 'html',
            ])) throw new \RuntimeException($element->LAST_ERROR ?: 'Не удалось сохранить технический элемент');
            $parameters = $this->normalizeParameters((array)($entity['parameters'] ?? []));
            $sourceLinks = $this->normalizeSourceLinks((array)($entity['sourceLinks'] ?? []));
            \CIBlockElement::SetPropertyValuesEx($id, (int)$loaded['iblockId'], [
                'PARAMETRS' => $parameters ? array_map(static function (array $parameter): array {
                    return [
                        'VALUE' => $parameter['code'],
                        'DESCRIPTION' => implode('|', [$parameter['value'], $parameter['title'], $parameter['description']]),
                    ];
                }, $parameters) : false,
                'SOURCE_LINKS' => $sourceLinks ? array_map(static function (array $source): array {
                    return [
                        'VALUE' => $source['url'],
                        'DESCRIPTION' => implode('|', [$source['title'], $source['description']]),
                    ];
                }, $sourceLinks) : false,
            ]);
            $catalog = $this->saveCatalog($id, (array)($entity['catalog'] ?? []));
            $updated[] = [
                'id' => $id,
                'name' => $name,
                'previewText' => $preview,
                'detailText' => $detail,
                'parameters' => $parameters,
                'sourceLinks' => $sourceLinks,
                'catalog' => $catalog,
            ];
        }
        return ['status' => 'ok', 'entityType' => $type, 'entities' => $updated, 'createdVariantId' => $createdVariantId];
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
        $cursor = \CIBlockElement::GetList([], ['ID' => $id, 'IBLOCK_ID' => $allowedIblocks], false, false, ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'PREVIEW_TEXT', 'DETAIL_TEXT']);
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
            'detailText' => (string)($row['DETAIL_TEXT'] ?? ''),
            'parameters' => $this->loadParameters((int)$row['ID'], (int)$row['IBLOCK_ID']),
            'sourceLinks' => $this->loadSourceLinks((int)$row['ID'], (int)$row['IBLOCK_ID']),
            'catalog' => $this->loadCatalog((int)$row['ID']),
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

    private function createEntities(array $iblocks, array $entities, int $sectionId): array
    {
        if (count($entities) < 2) {
            throw new \InvalidArgumentException('Для новой карточки нужен родитель и хотя бы один вариант');
        }
        if ($sectionId > 0 && !\CIBlockSection::GetList([], [
            'ID' => $sectionId,
            'IBLOCK_ID' => $iblocks[0],
        ], false, ['ID'])->Fetch()) {
            throw new \InvalidArgumentException('Выбранный раздел не найден');
        }

        $element = new \CIBlockElement();
        $parentName = trim((string)($entities[0]['name'] ?? ''));
        $parentId = (int)$element->Add([
            'IBLOCK_ID' => $iblocks[0],
            'IBLOCK_SECTION_ID' => $sectionId > 0 ? $sectionId : false,
            'ACTIVE' => 'Y',
            'NAME' => $parentName,
            'CODE' => $this->makeUniqueCode($iblocks[0], $parentName),
        ]);
        if ($parentId <= 0) throw new \RuntimeException($element->LAST_ERROR ?: 'Не удалось создать родительский элемент');

        $createdIds = [$parentId];
        $createdVariantId = 0;
        try {
            foreach (array_slice($entities, 1) as $entity) {
                $variantName = trim((string)($entity['name'] ?? ''));
                $variantId = (int)$element->Add([
                    'IBLOCK_ID' => $iblocks[1],
                    'ACTIVE' => 'Y',
                    'NAME' => $variantName,
                    'CODE' => $this->makeUniqueCode($iblocks[1], $variantName),
                    'PROPERTY_VALUES' => ['CML2_LINK' => $parentId],
                ]);
                if ($variantId <= 0) throw new \RuntimeException($element->LAST_ERROR ?: 'Не удалось создать вариант');
                $createdIds[] = $variantId;
                if ($createdVariantId === 0) $createdVariantId = $variantId;
            }
        } catch (\Throwable $exception) {
            foreach (array_reverse($createdIds) as $createdId) \CIBlockElement::Delete($createdId);
            throw $exception;
        }

        foreach ($entities as $index => &$entity) $entity['id'] = $createdIds[$index];
        unset($entity);
        return [$entities, $createdVariantId];
    }

    private function makeUniqueCode(int $iblockId, string $name): string
    {
        $base = trim((string)\CUtil::translit($name, 'ru', [
            'replace_space' => '-',
            'replace_other' => '-',
            'change_case' => 'L',
        ]), '-');
        if ($base === '') $base = 'element';
        $code = $base;
        $suffix = 2;
        while (\CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, '=CODE' => $code], false, ['nTopCount' => 1], ['ID'])->Fetch()) {
            $code = $base . '-' . $suffix++;
        }
        return $code;
    }

    private function loadSourceLinks(int $elementId, int $iblockId): array
    {
        $result = [];
        $cursor = \CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc', 'id' => 'asc'], ['CODE' => 'SOURCE_LINKS']);
        while ($property = $cursor->Fetch()) {
            $url = trim((string)($property['VALUE'] ?? ''));
            if ($url === '') continue;
            $parts = explode('|', (string)($property['DESCRIPTION'] ?? ''), 2);
            $result[] = [
                'url' => $url,
                'title' => trim((string)($parts[0] ?? '')),
                'description' => trim((string)($parts[1] ?? '')),
            ];
        }
        return $result;
    }

    private function normalizeSourceLinks(array $links): array
    {
        $result = [];
        foreach ($links as $link) {
            if (!is_array($link)) continue;
            $url = trim((string)($link['url'] ?? ''));
            $title = trim((string)($link['title'] ?? ''));
            $description = trim((string)($link['description'] ?? ''));
            if ($url === '' && $title === '' && $description === '') continue;
            if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url)) {
                throw new \InvalidArgumentException('Источник данных должен содержать корректную HTTP(S)-ссылку');
            }
            if (strpos($title, '|') !== false || strpos($description, '|') !== false) {
                throw new \InvalidArgumentException('Символ | недопустим в названии и описании источника');
            }
            $result[] = compact('url', 'title', 'description');
        }
        return $result;
    }

    private function loadCatalog(int $elementId): array
    {
        $product = \CCatalogProduct::GetByID($elementId) ?: [];
        $basePrice = null;
        $baseCurrency = 'RUB';
        $baseGroup = \CCatalogGroup::GetBaseGroup();
        if (!empty($baseGroup['ID'])) {
            $price = \CPrice::GetList([], ['PRODUCT_ID' => $elementId, 'CATALOG_GROUP_ID' => (int)$baseGroup['ID']])->Fetch();
            if ($price) {
                $basePrice = $price['PRICE'];
                $baseCurrency = (string)$price['CURRENCY'];
            }
        }
        return [
            'vatId' => (int)($product['VAT_ID'] ?? 0),
            'vatIncluded' => ($product['VAT_INCLUDED'] ?? 'N') === 'Y',
            'purchasingPrice' => $product['PURCHASING_PRICE'] ?? null,
            'purchasingCurrency' => (string)($product['PURCHASING_CURRENCY'] ?? 'RUB'),
            'basePrice' => $basePrice,
            'baseCurrency' => $baseCurrency,
            'weight' => $product['WEIGHT'] ?? null,
            'length' => $product['LENGTH'] ?? null,
            'width' => $product['WIDTH'] ?? null,
            'height' => $product['HEIGHT'] ?? null,
        ];
    }

    private function saveCatalog(int $elementId, array $catalog): array
    {
        $number = static function ($value): ?float {
            $value = trim(str_replace(',', '.', (string)$value));
            if ($value === '') return null;
            if (!is_numeric($value)) throw new \InvalidArgumentException('Значение торгового каталога должно быть числом');
            return (float)$value;
        };
        $fields = [
            'VAT_ID' => (int)($catalog['vatId'] ?? 0),
            'VAT_INCLUDED' => !empty($catalog['vatIncluded']) ? 'Y' : 'N',
            'PURCHASING_PRICE' => $number($catalog['purchasingPrice'] ?? null),
            'PURCHASING_CURRENCY' => trim((string)($catalog['purchasingCurrency'] ?? 'RUB')) ?: 'RUB',
            'WEIGHT' => $number($catalog['weight'] ?? null),
            'LENGTH' => $number($catalog['length'] ?? null),
            'WIDTH' => $number($catalog['width'] ?? null),
            'HEIGHT' => $number($catalog['height'] ?? null),
        ];
        $saved = \CCatalogProduct::GetByID($elementId)
            ? \CCatalogProduct::Update($elementId, $fields)
            : \CCatalogProduct::Add(['ID' => $elementId] + $fields);
        if (!$saved) throw new \RuntimeException('Не удалось сохранить параметры торгового каталога');

        $basePrice = $number($catalog['basePrice'] ?? null);
        $baseCurrency = trim((string)($catalog['baseCurrency'] ?? 'RUB')) ?: 'RUB';
        $baseGroup = \CCatalogGroup::GetBaseGroup();
        if ($basePrice !== null && !empty($baseGroup['ID'])) {
            $price = \CPrice::GetList([], ['PRODUCT_ID' => $elementId, 'CATALOG_GROUP_ID' => (int)$baseGroup['ID']])->Fetch();
            $priceFields = ['PRODUCT_ID' => $elementId, 'CATALOG_GROUP_ID' => (int)$baseGroup['ID'], 'PRICE' => $basePrice, 'CURRENCY' => $baseCurrency];
            $priceSaved = $price ? \CPrice::Update((int)$price['ID'], $priceFields) : \CPrice::Add($priceFields);
            if (!$priceSaved) throw new \RuntimeException('Не удалось сохранить базовую цену');
        }
        return $this->loadCatalog($elementId);
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
