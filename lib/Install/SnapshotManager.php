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
        $sourceElementsIndex = $this->buildSourceElementsIndex($data['iblocks']);
        $targetIblockIdToCode = [];
        foreach ($iblockIds as $code => $id) {
            $targetId = (int)$id;
            if ($targetId > 0) {
                $targetIblockIdToCode[$targetId] = (string)$code;
            }
        }

        $elementIdMapsByCode = [];
        $resolvedElementsByCode = [];
        $targetIblockIdsByCode = [];

        // pass 1: sections + elements only (without properties)
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
            $resolvedElementsByCode[$srcCode] = $this->importElements(
                $targetIblockId,
                $srcCode,
                $iblockData['elements'] ?? [],
                $sectionMap,
                $created,
                $errors,
                $elementIdMapsByCode
            );
            $targetIblockIdsByCode[$srcCode] = $targetIblockId;
        }

        // pass 2: properties + catalog data after all mappings are known
        foreach ($resolvedElementsByCode as $srcCode => $resolvedElements) {
            $targetIblockId = (int)($targetIblockIdsByCode[$srcCode] ?? 0);
            if ($targetIblockId <= 0) {
                continue;
            }

            $this->applyElementDataToResolvedElements(
                $targetIblockId,
                (string)$srcCode,
                (array)$resolvedElements,
                $sourceElementsIndex,
                $elementIdMapsByCode,
                $targetIblockIdToCode,
                $errors
            );
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
            'CALC_CUSTOM_FIELDS',
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

    private function importElements(
        int $iblockId,
        string $sourceIblockCode,
        array $elements,
        array $sectionMap,
        array &$created,
        array &$errors,
        array &$elementIdMapsByCode
    ): array
    {
        $elementApi = new \CIBlockElement();
        $currentIblockMap = $elementIdMapsByCode[$sourceIblockCode] ?? [];
        $resolvedElements = [];
        $generatedCodes = [];

        foreach ($elements as $elementData) {
            $fields = (array)($elementData['fields'] ?? []);
            $name = (string)($fields['NAME'] ?? '');
            if ($name === '') {
                continue;
            }

            $oldElementId = (int)($elementData['old_id'] ?? 0);

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

            if ($code === '' && $name !== '') {
                $code = $this->generateElementCodeFromName($iblockId, $name, $generatedCodes);
            }

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

            if ($oldElementId > 0) {
                $currentIblockMap[$oldElementId] = $elementId;
            }

            $resolvedElements[] = ['id' => $elementId, 'data' => $elementData];
        }

        $elementIdMapsByCode[$sourceIblockCode] = $currentIblockMap;

        return $resolvedElements;
    }

    private function applyElementDataToResolvedElements(
        int $iblockId,
        string $sourceIblockCode,
        array $resolvedElements,
        array $sourceElementsIndex,
        array $elementIdMapsByCode,
        array $targetIblockIdToCode,
        array &$errors
    ): void
    {
        $catalogLoaded = Loader::includeModule('catalog');

        foreach ($resolvedElements as $resolvedElement) {
            $elementId = (int)($resolvedElement['id'] ?? 0);
            if ($elementId <= 0) {
                continue;
            }

            $elementData = (array)($resolvedElement['data'] ?? []);

            $propertyValues = $this->preparePropertyValues(
                $iblockId,
                $sourceIblockCode,
                (array)($elementData['properties'] ?? []),
                $sourceElementsIndex,
                $elementIdMapsByCode,
                $targetIblockIdToCode,
                $errors
            );
            if (!empty($propertyValues)) {
                \CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, $propertyValues);
            }

            if ($catalogLoaded && class_exists('\CCatalogProduct')) {
                $this->importCatalogData($elementId, $elementData, $errors);
            }
        }
    }

    private function generateElementCodeFromName(int $iblockId, string $name, array &$generatedCodes): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $translitOptions = $this->getIblockCodeTranslitSettings($iblockId);
        $baseCode = (string)\CUtil::translit($name, 'ru', $translitOptions);
        if ($baseCode === '') {
            $baseCode = 'element';
        }

        $maxLen = (int)($translitOptions['max_len'] ?? 100);
        if ($maxLen <= 0) {
            $maxLen = 100;
        }

        $baseCode = mb_substr($baseCode, 0, $maxLen);
        $candidate = $baseCode;
        $suffix = 2;

        while ($candidate !== '' && ($this->isElementCodeExists($iblockId, $candidate) || isset($generatedCodes[$candidate]))) {
            $suffixText = '-' . $suffix;
            $allowedBaseLen = max(1, $maxLen - strlen($suffixText));
            $candidate = mb_substr($baseCode, 0, $allowedBaseLen) . $suffixText;
            $suffix++;
        }

        if ($candidate !== '') {
            $generatedCodes[$candidate] = true;
        }

        return $candidate;
    }

    private function getIblockCodeTranslitSettings(int $iblockId): array
    {
        $defaults = [
            'max_len' => 100,
            'change_case' => 'L',
            'replace_space' => '-',
            'replace_other' => '-',
            'delete_repeat_replace' => true,
            'use_google' => true,
        ];

        $iblock = \CIBlock::GetByID($iblockId)->Fetch();
        $fieldsRaw = $iblock['FIELDS'] ?? null;
        $fields = [];

        if (is_array($fieldsRaw)) {
            $fields = $fieldsRaw;
        } elseif (is_string($fieldsRaw) && $fieldsRaw !== '') {
            $unserialized = @unserialize($fieldsRaw, ['allowed_classes' => false]);
            if (is_array($unserialized)) {
                $fields = $unserialized;
            }
        }

        $codeField = (array)($fields['CODE'] ?? []);
        $defaultValue = (array)($codeField['DEFAULT_VALUE'] ?? []);

        return [
            'max_len' => (int)($defaultValue['TRANS_LEN'] ?? $defaults['max_len']),
            'change_case' => (string)($defaultValue['TRANS_CASE'] ?? $defaults['change_case']),
            'replace_space' => (string)($defaultValue['TRANS_SPACE'] ?? $defaults['replace_space']),
            'replace_other' => (string)($defaultValue['TRANS_OTHER'] ?? $defaults['replace_other']),
            'delete_repeat_replace' => (string)($defaultValue['TRANS_EAT'] ?? 'Y') === 'Y',
            'use_google' => (string)($defaultValue['USE_GOOGLE'] ?? 'Y') === 'Y',
        ];
    }

    private function isElementCodeExists(int $iblockId, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        $exists = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, '=CODE' => $code],
            false,
            ['nTopCount' => 1],
            ['ID']
        )->Fetch();

        return (int)($exists['ID'] ?? 0) > 0;
    }

    private function preparePropertyValues(
        int $iblockId,
        string $sourceIblockCode,
        array $properties,
        array $sourceElementsIndex,
        array $elementIdMapsByCode,
        array $targetIblockIdToCode,
        array &$errors
    ): array
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

                if ($propertyType === 'E') {
                    $rawValue = is_array($value) ? ($value['VALUE'] ?? null) : $value;
                    $oldLinkedElementId = (int)$rawValue;
                    if ($oldLinkedElementId > 0) {
                        $linkedIblockId = (int)($property['LINK_IBLOCK_ID'] ?? 0);
                        $linkedSourceCode = $linkedIblockId > 0
                            ? (string)($targetIblockIdToCode[$linkedIblockId] ?? '')
                            : $sourceIblockCode;

                        $resolvedId = $this->resolveLinkedElementId(
                            $oldLinkedElementId,
                            $linkedIblockId,
                            $linkedSourceCode,
                            $sourceElementsIndex,
                            $elementIdMapsByCode
                        );

                        if ($resolvedId > 0) {
                            $value = $resolvedId;
                        } else {
                            $errors[] = sprintf(
                                'Не удалось сопоставить элемент-связку для свойства %s (старый ID %d, инфоблок %s)',
                                (string)$code,
                                $oldLinkedElementId,
                                $linkedSourceCode !== '' ? $linkedSourceCode : (string)$linkedIblockId
                            );
                            continue;
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

    private function buildSourceElementsIndex(array $iblocks): array
    {
        $index = [];

        foreach ($iblocks as $iblockData) {
            $sourceCode = (string)($iblockData['iblock']['fields']['CODE'] ?? '');
            if ($sourceCode === '') {
                continue;
            }

            foreach ((array)($iblockData['elements'] ?? []) as $elementData) {
                $oldId = (int)($elementData['old_id'] ?? 0);
                if ($oldId <= 0) {
                    continue;
                }

                $fields = (array)($elementData['fields'] ?? []);
                $index[$sourceCode][$oldId] = [
                    'XML_ID' => (string)($fields['XML_ID'] ?? ''),
                    'CODE' => (string)($fields['CODE'] ?? ''),
                ];
            }
        }

        return $index;
    }

    private function resolveLinkedElementId(
        int $oldLinkedElementId,
        int $linkedIblockId,
        string $linkedSourceCode,
        array $sourceElementsIndex,
        array $elementIdMapsByCode
    ): int
    {
        if ($linkedSourceCode !== '' && isset($elementIdMapsByCode[$linkedSourceCode][$oldLinkedElementId])) {
            return (int)$elementIdMapsByCode[$linkedSourceCode][$oldLinkedElementId];
        }

        if ($linkedIblockId <= 0 || $linkedSourceCode === '') {
            return 0;
        }

        $identity = (array)($sourceElementsIndex[$linkedSourceCode][$oldLinkedElementId] ?? []);
        $xmlId = (string)($identity['XML_ID'] ?? '');
        $code = (string)($identity['CODE'] ?? '');

        if ($xmlId !== '') {
            $exists = \CIBlockElement::GetList([], ['IBLOCK_ID' => $linkedIblockId, '=XML_ID' => $xmlId], false, ['nTopCount' => 1], ['ID'])->Fetch();
            $resolvedId = (int)($exists['ID'] ?? 0);
            if ($resolvedId > 0) {
                return $resolvedId;
            }
        }

        if ($code !== '') {
            $exists = \CIBlockElement::GetList([], ['IBLOCK_ID' => $linkedIblockId, '=CODE' => $code], false, ['nTopCount' => 1], ['ID'])->Fetch();
            $resolvedId = (int)($exists['ID'] ?? 0);
            if ($resolvedId > 0) {
                return $resolvedId;
            }
        }

        return 0;
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
