<?php
/**
 * Экспорт демо-данных инфоблоков, созданных установщиком модуля prospektweb.calc.
 *
 * Запускать из PHP-консоли Битрикса (или через include в контексте ядра).
 */

use Bitrix\Main\Config\Option;

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

if (!CModule::IncludeModule('iblock')) {
    die("Не удалось подключить модуль iblock\n");
}

$catalogLoaded = CModule::IncludeModule('catalog');

$moduleId = 'prospektweb.calc';

// Коды инфоблоков строго из lib/Install/Installer.php::createIblocks()
$installerIblockCodes = [
    'CALC_STAGES',
    'CALC_SETTINGS',
    'CALC_MATERIALS',
    'CALC_MATERIALS_VARIANTS',
    'CALC_OPERATIONS',
    'CALC_OPERATIONS_VARIANTS',
    'CALC_EQUIPMENT',
    'CALC_DETAILS',
];

// Дополнительно (если создаются в других версиях модуля)
$optionalInstallerCodes = [
    'CALC_PRESETS',
    'CALC_WORKS',
    'CALC_WORKS_VARIANTS',
];

$allCodes = array_values(array_unique(array_merge($installerIblockCodes, $optionalInstallerCodes)));

$targetIblockIds = [];
$resolvedBy = [];

foreach ($allCodes as $code) {
    $id = (int)Option::get($moduleId, 'IBLOCK_' . $code, 0);

    if ($id > 0) {
        $targetIblockIds[$id] = $id;
        $resolvedBy[$code] = 'option';
        continue;
    }

    // Fallback: поиск по CODE инфоблока
    $rs = CIBlock::GetList([], ['CHECK_PERMISSIONS' => 'N', '=CODE' => mb_strtolower($code)]);
    if ($ib = $rs->Fetch()) {
        $id = (int)$ib['ID'];
        if ($id > 0) {
            $targetIblockIds[$id] = $id;
            $resolvedBy[$code] = 'iblock_code_lower';
            continue;
        }
    }

    $rs = CIBlock::GetList([], ['CHECK_PERMISSIONS' => 'N', '=CODE' => $code]);
    if ($ib = $rs->Fetch()) {
        $id = (int)$ib['ID'];
        if ($id > 0) {
            $targetIblockIds[$id] = $id;
            $resolvedBy[$code] = 'iblock_code_raw';
            continue;
        }
    }

    $resolvedBy[$code] = 'not_found';
}

// Явно исключаем товарный и SKU ИБ каталога, если они записаны в опции модуля
$productIblockId = (int)Option::get($moduleId, 'PRODUCT_IBLOCK_ID', 0);
$skuIblockId = (int)Option::get($moduleId, 'SKU_IBLOCK_ID', 0);

if ($productIblockId > 0) {
    unset($targetIblockIds[$productIblockId]);
}
if ($skuIblockId > 0) {
    unset($targetIblockIds[$skuIblockId]);
}

if (empty($targetIblockIds)) {
    die("Не удалось определить ни один инфоблок из установщика. Проверьте Option::get(..., 'IBLOCK_*').\n");
}

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
if ($docRoot === '') {
    $docRoot = getcwd();
}

$exportFile = $docRoot . '/upload/prospektweb_installer_iblocks_' . date('Ymd_His') . '.txt';
$fp = fopen($exportFile, 'ab');
if (!$fp) {
    die("Не удалось открыть файл для записи: {$exportFile}\n");
}


$isEmptyValue = static function ($value): bool {
    if ($value === null || $value === false) {
        return true;
    }
    if ($value === '') {
        return true;
    }
    if (is_array($value)) {
        foreach ($value as $item) {
            if (!($item === null || $item === false || $item === '' || (is_array($item) && count(array_filter($item, static function ($v) { return !($v === null || $v === false || $v === ''); })) === 0))) {
                return false;
            }
        }
        return true;
    }
    return false;
};

$pruneEmpty = static function ($data) use (&$pruneEmpty): array {
    if (!is_array($data)) {
        return [];
    }

    $result = [];
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $value = $pruneEmpty($value);
            if ($value === []) {
                continue;
            }
            $result[$key] = $value;
            continue;
        }

        if ($value === null || $value === false || $value === '') {
            continue;
        }

        $result[$key] = $value;
    }

    return $result;
};

$write = static function ($resource, string $title, array $data) use ($pruneEmpty): void {
    $data = $pruneEmpty($data);
    fwrite($resource, "----- {$title} -----\n");
    fwrite($resource, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n\n");
};

fwrite($fp, "=== prospektweb.calc installer iblocks export ===\n");
fwrite($fp, 'Generated: ' . date('c') . "\n");
fwrite($fp, 'Module: ' . $moduleId . "\n");
fwrite($fp, 'Product iblock id (excluded): ' . $productIblockId . "\n");
fwrite($fp, 'SKU iblock id (excluded): ' . $skuIblockId . "\n\n");

$write($fp, 'RESOLVED_CODES', [
    'codes' => $allCodes,
    'resolved_by' => $resolvedBy,
    'resolved_iblock_ids' => array_values($targetIblockIds),
]);

$totalSections = 0;
$totalElements = 0;

foreach (array_values($targetIblockIds) as $iblockId) {
    $rsIblock = CIBlock::GetList([], ['ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N']);
    $iblock = $rsIblock->Fetch();
    if (!$iblock) {
        continue;
    }

    $write($fp, 'IBLOCK_INFO', [
        'ID' => $iblock['ID'],
        'CODE' => $iblock['CODE'],
        'NAME' => $iblock['NAME'],
        'IBLOCK_TYPE_ID' => $iblock['IBLOCK_TYPE_ID'],
        'MODULE_ID' => $iblock['MODULE_ID'],
        'LID' => $iblock['LID'],
    ]);

    // Разделы
    $rsSections = CIBlockSection::GetList(
        ['LEFT_MARGIN' => 'ASC', 'ID' => 'ASC'],
        ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
        false,
        ['*']
    );

    while ($section = $rsSections->Fetch()) {
        $totalSections++;
        $write($fp, 'SECTION', [
            'IBLOCK_ID' => $iblockId,
            'SECTION' => $section,
        ]);
    }

    // Элементы
    $rsElements = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['*']
    );

    while ($ob = $rsElements->GetNextElement()) {
        $fields = $ob->GetFields();
        $elementId = (int)$fields['ID'];
        $totalElements++;

        $properties = [];
        $rsProps = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc', 'id' => 'asc'], []);
        while ($prop = $rsProps->Fetch()) {
            $key = ($prop['CODE'] !== '' ? $prop['CODE'] : ('PROPERTY_' . $prop['ID']));

            $value = $prop['VALUE'];
            if ($prop['PROPERTY_TYPE'] === 'F' && (int)$value > 0) {
                $value = CFile::GetFileArray((int)$value);
            }
            if ($prop['PROPERTY_TYPE'] === 'L') {
                $value = [
                    'VALUE' => $prop['VALUE'],
                    'VALUE_ENUM' => $prop['VALUE_ENUM'],
                    'VALUE_XML_ID' => $prop['VALUE_XML_ID'],
                    'VALUE_SORT' => $prop['VALUE_SORT'],
                ];
            }

            $row = [
                'PROPERTY_ID' => $prop['ID'],
                'NAME' => $prop['NAME'],
                'CODE' => $prop['CODE'],
                'PROPERTY_TYPE' => $prop['PROPERTY_TYPE'],
                'USER_TYPE' => $prop['USER_TYPE'],
                'MULTIPLE' => $prop['MULTIPLE'],
                'VALUE' => $value,
                'DESCRIPTION' => $prop['DESCRIPTION'],
            ];

            $row = $pruneEmpty($row);
            if ($isEmptyValue($row['VALUE'] ?? null)) {
                continue;
            }

            if ($prop['MULTIPLE'] === 'Y') {
                $properties[$key][] = $row;
            } else {
                $properties[$key] = $row;
            }
        }

        // Привязка к разделам
        $sections = [];
        $rsGroups = CIBlockElement::GetElementGroups($elementId, true, ['ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'DEPTH_LEVEL']);
        while ($group = $rsGroups->Fetch()) {
            $sections[] = $group;
        }

        // Обогащение каталожными полями (цены, единицы, НДС, габариты/вес)
        $catalogProduct = null;
        $prices = [];
        $measure = null;
        $vat = null;

        if ($catalogLoaded && class_exists('CCatalogProduct')) {
            $catalogProduct = CCatalogProduct::GetByID($elementId) ?: null;

            if ($catalogProduct && class_exists('CPrice')) {
                $rsPrices = CPrice::GetList([], ['PRODUCT_ID' => $elementId]);
                while ($price = $rsPrices->Fetch()) {
                    $prices[] = $price;
                }
            }

            if ($catalogProduct && !empty($catalogProduct['MEASURE']) && class_exists('CCatalogMeasure')) {
                $measureRs = CCatalogMeasure::getList([], ['ID' => (int)$catalogProduct['MEASURE']]);
                if ($measureRow = $measureRs->Fetch()) {
                    $measure = $measureRow;
                }
            }

            if ($catalogProduct && !empty($catalogProduct['VAT_ID']) && class_exists('CCatalogVat')) {
                $vatRes = CCatalogVat::GetByID((int)$catalogProduct['VAT_ID']);
                if (is_object($vatRes)) {
                    $vat = $vatRes->Fetch();
                }
            }
        }

        $write($fp, 'ELEMENT', [
            'IBLOCK_ID' => $iblockId,
            'ELEMENT_ID' => $elementId,
            'FIELDS' => $fields,
            'SECTIONS' => $sections,
            'PROPERTIES' => $properties,
            'CATALOG_PRODUCT' => $catalogProduct,
            'PRICES' => $prices,
            'MEASURE' => $measure,
            'VAT' => $vat,
        ]);
    }
}

fwrite($fp, 'SUMMARY: sections=' . $totalSections . ', elements=' . $totalElements . "\n");
fclose($fp);

echo "Готово: {$exportFile}\n";
