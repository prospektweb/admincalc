<?php
/**
 * Snapshot-экспорт текущих данных инфоблоков модуля prospektweb.calc.
 *
 * Цель: снять эталон текущей системы (разделы/элементы/свойства/catalog-данные),
 * чтобы затем установщик мог восстановить структуру и демо-данные 1:1.
 *
 * Запуск: Битрикс -> Настройки -> Инструменты -> Командная PHP-строка.
 */

use Bitrix\Main\Config\Option;

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

if (!CModule::IncludeModule('iblock')) {
    die("Не удалось подключить модуль iblock\n");
}
$catalogLoaded = CModule::IncludeModule('catalog');

$moduleId = 'prospektweb.calc';

/**
 * Бизнес-коды инфоблоков, которые создаются установщиком модуля.
 * Используем как первичный источник, далее добираем фактически найденные в опциях.
 */
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

/**
 * Удаляет пустые значения из массива рекурсивно.
 */
$pruneEmpty = static function ($value) use (&$pruneEmpty) {
    if (is_array($value)) {
        $isList = array_values($value) === $value;
        $result = [];

        foreach ($value as $k => $v) {
            $clean = $pruneEmpty($v);

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
};

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

// На случай, если в опциях есть дополнительные IBLOCK_* ключи, тоже включаем в snapshot.
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

// Исключаем пользовательский товарный и SKU инфоблоки магазина.
unset($targetIblockIds[$productIblockId], $targetIblockIds[$skuIblockId]);

if (empty($targetIblockIds)) {
    die("Не найдено ни одного инфоблока модуля в Option::getForModule('{$moduleId}').\n");
}

$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($docRoot === '') {
    $docRoot = getcwd();
}

$exportFile = $docRoot . '/upload/prospektweb_snapshot_' . date('Ymd_His') . '.txt';

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
    $rsIblock = CIBlock::GetList([], ['ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N']);
    $iblock = $rsIblock->Fetch();
    if (!$iblock) {
        continue;
    }

    // Свойства инфоблока (с enum-значениями)
    $propertiesMeta = [];
    $propertyIdToCode = [];

    $rsProperty = CIBlockProperty::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['IBLOCK_ID' => $iblockId]);
    while ($property = $rsProperty->Fetch()) {
        $property = $pruneEmpty($property);
        $propertyCode = (string)($property['CODE'] ?? '');
        $propertyId = (int)($property['ID'] ?? 0);

        if ($propertyId > 0) {
            $propertyIdToCode[$propertyId] = $propertyCode !== '' ? $propertyCode : ('PROPERTY_' . $propertyId);
        }

        $enumValues = [];
        if (($property['PROPERTY_TYPE'] ?? '') === 'L' && $propertyId > 0) {
            $rsEnum = CIBlockPropertyEnum::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['PROPERTY_ID' => $propertyId]);
            while ($enum = $rsEnum->Fetch()) {
                $enumValues[] = $pruneEmpty($enum);
            }
        }

        $propertiesMeta[] = $pruneEmpty([
            'property' => $property,
            'enum' => $enumValues,
        ]);
    }

    // Разделы (с привязкой к старому ID для восстановления дерева)
    $sections = [];
    $rsSections = CIBlockSection::GetList(
        ['LEFT_MARGIN' => 'ASC', 'ID' => 'ASC'],
        ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
        false,
        ['*']
    );
    while ($section = $rsSections->Fetch()) {
        $sections[] = $pruneEmpty([
            'old_id' => (int)$section['ID'],
            'old_parent_id' => (int)$section['IBLOCK_SECTION_ID'],
            'fields' => $section,
        ]);
    }

    // Элементы + свойства + привязка к разделам + catalog обогащение
    $elements = [];
    $rsElements = CIBlockElement::GetList(
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
        $rsGroups = CIBlockElement::GetElementGroups($elementId, true, ['ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'DEPTH_LEVEL']);
        while ($group = $rsGroups->Fetch()) {
            $groups[] = $pruneEmpty($group);
        }

        $properties = [];
        $rsProps = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc', 'id' => 'asc'], []);
        while ($prop = $rsProps->Fetch()) {
            $propertyId = (int)$prop['ID'];
            $propertyCode = $propertyIdToCode[$propertyId] ?? (($prop['CODE'] ?? '') !== '' ? $prop['CODE'] : ('PROPERTY_' . $propertyId));

            $value = $prop['VALUE'];
            if (($prop['PROPERTY_TYPE'] ?? '') === 'F' && (int)$value > 0) {
                $value = CFile::GetFileArray((int)$value);
            }

            if (($prop['PROPERTY_TYPE'] ?? '') === 'L') {
                $value = [
                    'VALUE' => $prop['VALUE'],
                    'VALUE_ENUM' => $prop['VALUE_ENUM'],
                    'VALUE_XML_ID' => $prop['VALUE_XML_ID'],
                ];
            }

            $entry = $pruneEmpty([
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

        if ($catalogLoaded && class_exists('CCatalogProduct')) {
            $catalogProduct = CCatalogProduct::GetByID($elementId) ?: null;

            if ($catalogProduct && class_exists('CPrice')) {
                $rsPrices = CPrice::GetList([], ['PRODUCT_ID' => $elementId]);
                while ($price = $rsPrices->Fetch()) {
                    $prices[] = $pruneEmpty($price);
                }
            }

            if ($catalogProduct && !empty($catalogProduct['MEASURE']) && class_exists('CCatalogMeasure')) {
                $measureRs = CCatalogMeasure::getList([], ['ID' => (int)$catalogProduct['MEASURE']]);
                if ($measureRow = $measureRs->Fetch()) {
                    $measure = $pruneEmpty($measureRow);
                }
            }

            if ($catalogProduct && !empty($catalogProduct['VAT_ID']) && class_exists('CCatalogVat')) {
                $vatRes = CCatalogVat::GetByID((int)$catalogProduct['VAT_ID']);
                if (is_object($vatRes)) {
                    $vatRow = $vatRes->Fetch();
                    if ($vatRow) {
                        $vat = $pruneEmpty($vatRow);
                    }
                }
            }
        }

        $elements[] = $pruneEmpty([
            'old_id' => $elementId,
            'fields' => $fields,
            'sections' => $groups,
            'properties' => $properties,
            'catalog_product' => $catalogProduct ? $pruneEmpty($catalogProduct) : null,
            'prices' => $prices,
            'measure' => $measure,
            'vat' => $vat,
        ]);
    }

    $snapshot['iblocks'][] = $pruneEmpty([
        'iblock' => [
            'old_id' => (int)$iblock['ID'],
            'fields' => $iblock,
        ],
        'properties_meta' => $propertiesMeta,
        'sections' => $sections,
        'elements' => $elements,
    ]);
}

$snapshot = $pruneEmpty($snapshot);

$encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($encoded === false) {
    die("Ошибка JSON-кодирования snapshot\n");
}

if (file_put_contents($exportFile, $encoded) === false) {
    die("Не удалось сохранить snapshot в файл {$exportFile}\n");
}

echo "Snapshot готов: {$exportFile}\n";
echo 'Iblocks: ' . count($snapshot['iblocks'] ?? []) . "\n";
