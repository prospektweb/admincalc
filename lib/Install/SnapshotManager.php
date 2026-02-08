<?php

namespace Prospektweb\Calc\Install;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class SnapshotManager
{
    private const MODULE_ID = 'prospektweb.calc';

    public function exportToFile(?string $filePath = null): string
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Не удалось подключить модуль iblock');
        }

        $snapshot = $this->buildSnapshot();
        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($encoded === false) {
            throw new \RuntimeException('Ошибка JSON-кодирования snapshot');
        }

        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($docRoot === '') {
            $docRoot = getcwd();
        }

        if ($filePath === null) {
            $filePath = $docRoot . '/upload/prospektweb_snapshot_' . date('Ymd_His') . '.json';
        }

        if (file_put_contents($filePath, $encoded) === false) {
            throw new \RuntimeException('Не удалось сохранить snapshot в файл ' . $filePath);
        }

        return $filePath;
    }

    public function importFromFile(string $filePath, array $iblockIds): array
    {
        if (!Loader::includeModule('iblock')) {
            return ['created' => [], 'errors' => ['Не удалось подключить модуль iblock']];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return ['created' => [], 'errors' => ['Не удалось прочитать snapshot файл: ' . $filePath]];
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['iblocks']) || !is_array($data['iblocks'])) {
            return ['created' => [], 'errors' => ['Некорректный формат snapshot файла']];
        }

        $created = [];
        $errors = [];

        foreach ($data['iblocks'] as $iblockData) {
            $srcCode = (string)($iblockData['iblock']['fields']['CODE'] ?? '');
            if ($srcCode === '') {
                continue;
            }

            $targetIblockId = (int)($iblockIds[$srcCode] ?? 0);
            if ($targetIblockId <= 0) {
                $errors[] = "Не найден целевой инфоблок для кода {$srcCode}";
                continue;
            }

            $sectionMap = $this->importSections($targetIblockId, $iblockData['sections'] ?? [], $created, $errors);
            $this->importElements($targetIblockId, $iblockData['elements'] ?? [], $sectionMap, $created, $errors);
        }

        return ['created' => $created, 'errors' => $errors];
    }

    private function buildSnapshot(): array
    {
        $moduleId = self::MODULE_ID;
        $catalogLoaded = Loader::includeModule('catalog');

        $installerCodes = [
            'CALC_STAGES',
            'CALC_SETTINGS',
            'CALC_MATERIALS',
            'CALC_MATERIALS_VARIANTS',
            'CALC_OPERATIONS',
            'CALC_OPERATIONS_VARIANTS',
            'CALC_EQUIPMENT',
            'CALC_DETAILS',
            'CALC_PRESETS',
        ];

        $targetIblockIds = [];
        $resolvedBy = [];

        foreach ($installerCodes as $code) {
            $optionKey = 'IBLOCK_' . $code;
            $id = (int)Option::get($moduleId, $optionKey, 0);
            if ($id > 0) {
                $targetIblockIds[$id] = $id;
                $resolvedBy[$code] = 'option:' . $optionKey;
                continue;
            }

            $resolvedBy[$code] = 'not_found';
        }

        $rsOptions = Option::getForModule($moduleId);
        if (is_array($rsOptions)) {
            foreach ($rsOptions as $name => $value) {
                if (strpos((string)$name, 'IBLOCK_') !== 0) {
                    continue;
                }

                $id = (int)$value;
                if ($id > 0) {
                    $targetIblockIds[$id] = $id;
                }
            }
        }

        $productIblockId = (int)Option::get($moduleId, 'PRODUCT_IBLOCK_ID', 0);
        $skuIblockId = (int)Option::get($moduleId, 'SKU_IBLOCK_ID', 0);
        unset($targetIblockIds[$productIblockId], $targetIblockIds[$skuIblockId]);

        $snapshot = [
            'meta' => [
                'generated_at' => date('c'),
                'module_id' => $moduleId,
                'excluded_product_iblock_id' => $productIblockId,
                'excluded_sku_iblock_id' => $skuIblockId,
                'resolved_codes' => $resolvedBy,
                'target_iblock_ids' => array_values($targetIblockIds),
            ],
            'iblocks' => [],
        ];

        foreach (array_values($targetIblockIds) as $iblockId) {
            $rsIblock = \CIBlock::GetList([], ['ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N']);
            $iblock = $rsIblock->Fetch();
            if (!$iblock) {
                continue;
            }

            $propertyIdToCode = [];
            $rsProperty = \CIBlockProperty::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['IBLOCK_ID' => $iblockId]);
            while ($property = $rsProperty->Fetch()) {
                $propertyId = (int)($property['ID'] ?? 0);
                if ($propertyId > 0) {
                    $propertyCode = (string)($property['CODE'] ?? '');
                    $propertyIdToCode[$propertyId] = $propertyCode !== '' ? $propertyCode : ('PROPERTY_' . $propertyId);
                }
            }

            $sections = [];
            $rsSections = \CIBlockSection::GetList(
                ['LEFT_MARGIN' => 'ASC', 'ID' => 'ASC'],
                ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
                false,
                ['*']
            );
            while ($section = $rsSections->Fetch()) {
                $sections[] = $this->pruneEmpty([
                    'old_id' => (int)$section['ID'],
                    'old_parent_id' => (int)$section['IBLOCK_SECTION_ID'],
                    'fields' => $section,
                ]);
            }

            $elements = [];
            $rsElements = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
                false,
                false,
                ['*']
            );

            while ($obElement = $rsElements->GetNextElement()) {
                $fields = $obElement->GetFields();
                $elementId = (int)$fields['ID'];

                $groups = [];
                $rsGroups = \CIBlockElement::GetElementGroups($elementId, true, ['ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'DEPTH_LEVEL']);
                while ($group = $rsGroups->Fetch()) {
                    $groups[] = $this->pruneEmpty($group);
                }

                $properties = [];
                $rsProps = \CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc', 'id' => 'asc'], []);
                while ($prop = $rsProps->Fetch()) {
                    $propertyId = (int)$prop['ID'];
                    $propertyCode = $propertyIdToCode[$propertyId] ?? (($prop['CODE'] ?? '') !== '' ? $prop['CODE'] : ('PROPERTY_' . $propertyId));

                    $value = $prop['VALUE'];
                    if (($prop['PROPERTY_TYPE'] ?? '') === 'F' && (int)$value > 0) {
                        $value = \CFile::GetFileArray((int)$value);
                    }

                    if (($prop['PROPERTY_TYPE'] ?? '') === 'L') {
                        $value = [
                            'VALUE' => $prop['VALUE'],
                            'VALUE_ENUM' => $prop['VALUE_ENUM'],
                            'VALUE_XML_ID' => $prop['VALUE_XML_ID'],
                        ];
                    }

                    $entry = $this->pruneEmpty([
                        'property_id' => $propertyId,
                        'code' => $propertyCode,
                        'value' => $value,
                        'description' => $prop['DESCRIPTION'] ?? null,
                    ]);

                    if ($entry === []) {
                        continue;
                    }

                    $properties[$propertyCode][] = $entry;
                }

                $catalogProduct = null;
                $prices = [];
                $measure = null;
                $vat = null;

                if ($catalogLoaded && class_exists('\CCatalogProduct')) {
                    $catalogProduct = \CCatalogProduct::GetByID($elementId) ?: null;

                    if ($catalogProduct && class_exists('\CPrice')) {
                        $rsPrices = \CPrice::GetList([], ['PRODUCT_ID' => $elementId]);
                        while ($price = $rsPrices->Fetch()) {
                            $prices[] = $this->pruneEmpty($price);
                        }
                    }

                    if ($catalogProduct && !empty($catalogProduct['MEASURE']) && class_exists('\CCatalogMeasure')) {
                        $measureRs = \CCatalogMeasure::getList([], ['ID' => (int)$catalogProduct['MEASURE']]);
                        if ($measureRow = $measureRs->Fetch()) {
                            $measure = $this->pruneEmpty($measureRow);
                        }
                    }

                    if ($catalogProduct && !empty($catalogProduct['VAT_ID']) && class_exists('\CCatalogVat')) {
                        $vatRes = \CCatalogVat::GetByID((int)$catalogProduct['VAT_ID']);
                        if (is_object($vatRes)) {
                            $vatRow = $vatRes->Fetch();
                            if ($vatRow) {
                                $vat = $this->pruneEmpty($vatRow);
                            }
                        }
                    }
                }

                $elements[] = $this->pruneEmpty([
                    'old_id' => $elementId,
                    'fields' => $fields,
                    'sections' => $groups,
                    'properties' => $properties,
                    'catalog_product' => $catalogProduct ? $this->pruneEmpty($catalogProduct) : null,
                    'prices' => $prices,
                    'measure' => $measure,
                    'vat' => $vat,
                ]);
            }

            $snapshot['iblocks'][] = $this->pruneEmpty([
                'iblock' => [
                    'old_id' => (int)$iblock['ID'],
                    'fields' => $iblock,
                ],
                'sections' => $sections,
                'elements' => $elements,
            ]);
        }

        return $this->pruneEmpty($snapshot);
    }

    private function importSections(int $iblockId, array $sections, array &$created, array &$errors): array
    {
        $map = [];
        usort($sections, static fn($a, $b) => (int)($a['fields']['LEFT_MARGIN'] ?? 0) <=> (int)($b['fields']['LEFT_MARGIN'] ?? 0));

        foreach ($sections as $sectionData) {
            $fields = (array)($sectionData['fields'] ?? []);
            $oldId = (int)($sectionData['old_id'] ?? 0);
            $oldParentId = (int)($sectionData['old_parent_id'] ?? 0);
            $parentId = $oldParentId > 0 ? (int)($map[$oldParentId] ?? 0) : 0;

            $code = (string)($fields['CODE'] ?? '');
            $xmlId = (string)($fields['XML_ID'] ?? '');
            $name = (string)($fields['NAME'] ?? '');

            $sectionId = 0;
            if ($xmlId !== '') {
                $exists = \CIBlockSection::GetList([], ['IBLOCK_ID' => $iblockId, '=XML_ID' => $xmlId], false, ['ID'])->Fetch();
                $sectionId = (int)($exists['ID'] ?? 0);
            }
            if ($sectionId <= 0 && $code !== '' && $name !== '') {
                $exists = \CIBlockSection::GetList([], ['IBLOCK_ID' => $iblockId, '=CODE' => $code, '=IBLOCK_SECTION_ID' => $parentId], false, ['ID'])->Fetch();
                $sectionId = (int)($exists['ID'] ?? 0);
            }

            $saveFields = [
                'IBLOCK_ID' => $iblockId,
                'IBLOCK_SECTION_ID' => $parentId,
                'NAME' => $name,
                'CODE' => $code,
                'XML_ID' => $xmlId,
                'ACTIVE' => (string)($fields['ACTIVE'] ?? 'Y'),
                'SORT' => (int)($fields['SORT'] ?? 500),
                'DESCRIPTION' => (string)($fields['DESCRIPTION'] ?? ''),
                'DESCRIPTION_TYPE' => (string)($fields['DESCRIPTION_TYPE'] ?? 'text'),
            ];

            $bs = new \CIBlockSection();
            if ($sectionId > 0) {
                $ok = $bs->Update($sectionId, $saveFields);
                if (!$ok) {
                    $errors[] = 'Ошибка обновления раздела: ' . $name;
                    continue;
                }
            } else {
                $sectionId = (int)$bs->Add($saveFields);
                if ($sectionId <= 0) {
                    $errors[] = 'Ошибка создания раздела: ' . $name;
                    continue;
                }
                $created[] = "Раздел {$name} (ID: {$sectionId})";
            }

            if ($oldId > 0) {
                $map[$oldId] = $sectionId;
            }
        }

        return $map;
    }

    private function importElements(int $iblockId, array $elements, array $sectionMap, array &$created, array &$errors): void
    {
        $catalogLoaded = Loader::includeModule('catalog');
        $elementApi = new \CIBlockElement();

        foreach ($elements as $elementData) {
            $fields = (array)($elementData['fields'] ?? []);
            $name = (string)($fields['NAME'] ?? '');
            if ($name === '') {
                continue;
            }

            $sectionIds = [];
            foreach ((array)($elementData['sections'] ?? []) as $section) {
                $oldSectionId = (int)($section['ID'] ?? 0);
                if ($oldSectionId > 0 && isset($sectionMap[$oldSectionId])) {
                    $sectionIds[] = (int)$sectionMap[$oldSectionId];
                }
            }
            $sectionIds = array_values(array_unique(array_filter($sectionIds)));

            $xmlId = (string)($fields['XML_ID'] ?? '');
            $code = (string)($fields['CODE'] ?? '');

            $elementId = 0;
            if ($xmlId !== '') {
                $exists = \CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, '=XML_ID' => $xmlId], false, ['nTopCount' => 1], ['ID'])->Fetch();
                $elementId = (int)($exists['ID'] ?? 0);
            }
            if ($elementId <= 0 && $code !== '') {
                $exists = \CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, '=CODE' => $code], false, ['nTopCount' => 1], ['ID'])->Fetch();
                $elementId = (int)($exists['ID'] ?? 0);
            }

            $saveFields = [
                'IBLOCK_ID' => $iblockId,
                'IBLOCK_SECTION_ID' => (int)($sectionIds[0] ?? 0),
                'IBLOCK_SECTION' => $sectionIds,
                'NAME' => $name,
                'CODE' => $code,
                'XML_ID' => $xmlId,
                'ACTIVE' => (string)($fields['ACTIVE'] ?? 'Y'),
                'SORT' => (int)($fields['SORT'] ?? 500),
                'PREVIEW_TEXT' => (string)($fields['PREVIEW_TEXT'] ?? ''),
                'PREVIEW_TEXT_TYPE' => (string)($fields['PREVIEW_TEXT_TYPE'] ?? 'text'),
                'DETAIL_TEXT' => (string)($fields['DETAIL_TEXT'] ?? ''),
                'DETAIL_TEXT_TYPE' => (string)($fields['DETAIL_TEXT_TYPE'] ?? 'text'),
            ];

            if ($elementId > 0) {
                $ok = $elementApi->Update($elementId, $saveFields);
                if (!$ok) {
                    $errors[] = 'Ошибка обновления элемента: ' . $name;
                    continue;
                }
            } else {
                $elementId = (int)$elementApi->Add($saveFields);
                if ($elementId <= 0) {
                    $errors[] = 'Ошибка создания элемента: ' . $name;
                    continue;
                }
                $created[] = "Элемент {$name} (ID: {$elementId})";
            }

            $propertyValues = $this->preparePropertyValues($iblockId, (array)($elementData['properties'] ?? []));
            if (!empty($propertyValues)) {
                \CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, $propertyValues);
            }

            if ($catalogLoaded && class_exists('\CCatalogProduct')) {
                $this->importCatalogData($elementId, $elementData, $errors);
            }
        }
    }

    private function preparePropertyValues(int $iblockId, array $properties): array
    {
        $prepared = [];

        foreach ($properties as $code => $entries) {
            if (!is_array($entries)) {
                continue;
            }

            $property = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code])->Fetch();
            if (!$property) {
                continue;
            }

            $propertyType = (string)($property['PROPERTY_TYPE'] ?? '');
            $multiple = (string)($property['MULTIPLE'] ?? 'N') === 'Y';

            $values = [];
            foreach ($entries as $entry) {
                $value = $entry['value'] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                if ($propertyType === 'F' && is_array($value)) {
                    $src = (string)($value['SRC'] ?? '');
                    if ($src !== '') {
                        $fullPath = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . $src;
                        if (is_file($fullPath)) {
                            $value = \CFile::MakeFileArray($fullPath);
                        } else {
                            continue;
                        }
                    } else {
                        continue;
                    }
                }

                if ($propertyType === 'L' && is_array($value)) {
                    $enumXml = (string)($value['VALUE_XML_ID'] ?? '');
                    $enumVal = (string)($value['VALUE'] ?? '');
                    if ($enumXml !== '') {
                        $enum = \CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => $property['ID'], '=XML_ID' => $enumXml])->Fetch();
                        if ($enum) {
                            $value = (int)$enum['ID'];
                        }
                    } elseif ($enumVal !== '') {
                        $enum = \CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => $property['ID'], '=VALUE' => $enumVal])->Fetch();
                        if ($enum) {
                            $value = (int)$enum['ID'];
                        } else {
                            $value = $enumVal;
                        }
                    }
                }

                $description = (string)($entry['description'] ?? '');
                $values[] = $description !== '' ? ['VALUE' => $value, 'DESCRIPTION' => $description] : $value;
            }

            if (empty($values)) {
                continue;
            }

            $prepared[$code] = $multiple ? $values : $values[0];
        }

        return $prepared;
    }

    private function importCatalogData(int $elementId, array $elementData, array &$errors): void
    {
        $product = (array)($elementData['catalog_product'] ?? []);
        if (!empty($product)) {
            $productFields = [];
            foreach (['QUANTITY','QUANTITY_TRACE','CAN_BUY_ZERO','NEGATIVE_AMOUNT_TRACE','SUBSCRIBE','VAT_ID','VAT_INCLUDED','MEASURE','WIDTH','LENGTH','HEIGHT','WEIGHT','PURCHASING_PRICE','PURCHASING_CURRENCY'] as $field) {
                if (array_key_exists($field, $product)) {
                    $productFields[$field] = $product[$field];
                }
            }

            if (!empty($productFields)) {
                $productFields['ID'] = $elementId;
                $existing = \CCatalogProduct::GetByID($elementId);
                if ($existing) {
                    \CCatalogProduct::Update($elementId, $productFields);
                } else {
                    \CCatalogProduct::Add($productFields);
                }
            }
        }

        foreach ((array)($elementData['prices'] ?? []) as $price) {
            $groupId = (int)($price['CATALOG_GROUP_ID'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }

            $fields = [
                'PRODUCT_ID' => $elementId,
                'CATALOG_GROUP_ID' => $groupId,
                'PRICE' => (float)($price['PRICE'] ?? 0),
                'CURRENCY' => (string)($price['CURRENCY'] ?? 'RUB'),
            ];

            if (isset($price['QUANTITY_FROM'])) {
                $fields['QUANTITY_FROM'] = $price['QUANTITY_FROM'];
            }
            if (isset($price['QUANTITY_TO'])) {
                $fields['QUANTITY_TO'] = $price['QUANTITY_TO'];
            }

            $existing = \CPrice::GetList([], ['PRODUCT_ID' => $elementId, 'CATALOG_GROUP_ID' => $groupId], false, ['nTopCount' => 1])->Fetch();
            if ($existing) {
                \CPrice::Update((int)$existing['ID'], $fields);
            } else {
                \CPrice::Add($fields);
            }
        }
    }

    private function pruneEmpty($value)
    {
        if (is_array($value)) {
            $isList = array_values($value) === $value;
            $result = [];

            foreach ($value as $k => $v) {
                $clean = $this->pruneEmpty($v);
                if ($clean === null || $clean === false || $clean === '' || $clean === []) {
                    continue;
                }

                if ($isList) {
                    $result[] = $clean;
                } else {
                    $result[$k] = $clean;
                }
            }

            return $result;
        }

        return $value;
    }
}
