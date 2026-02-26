<?php
/**
 * API endpoint: Генератор товаров по CALC_* свойствам.
 *
 * POST /local/modules/prospektweb.calc/tools/product_generator.php
 */

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;

global $APPLICATION;
$APPLICATION->RestartBuffer();

global $USER;

header('Content-Type: application/json; charset=UTF-8');

if (!check_bitrix_sessid() || !$USER->CanDoOperation('iblock_edit')) {
    echo json_encode(['error' => 'access_denied'], JSON_UNESCAPED_UNICODE);
    die();
}

if (!Loader::includeModule('prospektweb.calc') || !Loader::includeModule('iblock')) {
    echo json_encode(['error' => 'module_not_loaded'], JSON_UNESCAPED_UNICODE);
    die();
}

$action = (string)($_REQUEST['action'] ?? '');
$iblockId = (int)($_REQUEST['iblock_id'] ?? 0);
$sectionId = (int)($_REQUEST['section_id'] ?? 0);

if ($iblockId <= 0) {
    echo json_encode(['error' => 'invalid_iblock_id'], JSON_UNESCAPED_UNICODE);
    die();
}

const PRODUCT_NAME_MAX_LENGTH = 255;
const SYMBOLIC_CODE_MAX_LENGTH = 255;
const UNIQUE_SUFFIX_LENGTH = 6;

/**
 * @return string
 */
function generateUniqueSuffix(): string
{
    return strtoupper(substr(bin2hex(random_bytes(4)), 0, UNIQUE_SUFFIX_LENGTH));
}

/**
 * @param string $value
 * @param int $maxLength
 * @param string $suffix
 * @param string $separator
 * @return string
 */
function appendSuffixWithLimit(string $value, int $maxLength, string $suffix, string $separator = '-'): string
{
    $suffixPart = $separator . $suffix;
    $baseLimit = max(1, $maxLength - mb_strlen($suffixPart));
    $base = trim(mb_substr($value, 0, $baseLimit));

    if ($base === '') {
        $base = 'item';
    }

    return mb_substr($base . $suffixPart, 0, $maxLength);
}

/**
 * @param int $iblockId
 * @param string $code
 * @return bool
 */
function isElementCodeExists(int $iblockId, string $code): bool
{
    if ($code === '') {
        return false;
    }

    $res = \CIBlockElement::GetList(
        [],
        [
            'IBLOCK_ID' => $iblockId,
            '=CODE' => $code,
            'CHECK_PERMISSIONS' => 'N',
        ],
        false,
        ['nTopCount' => 1],
        ['ID']
    );

    return (bool)$res->Fetch();
}

/**
 * @param int $iblockId
 * @return array<int,array<string,mixed>>
 */
function getCalcProperties(int $iblockId): array
{
    $result = [];

    $propertyRes = \CIBlockProperty::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        [
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'CODE' => 'CALC_%',
        ]
    );

    while ($property = $propertyRes->Fetch()) {
        $propertyId = (int)($property['ID'] ?? 0);
        if ($propertyId <= 0) {
            continue;
        }

        $propertyType = (string)($property['PROPERTY_TYPE'] ?? '');
        $userType = (string)($property['USER_TYPE'] ?? '');
        $values = [];

        if ($propertyType === 'L') {
            $enumRes = \CIBlockPropertyEnum::GetList(
                ['SORT' => 'ASC', 'VALUE' => 'ASC'],
                ['PROPERTY_ID' => $propertyId]
            );

            while ($enum = $enumRes->Fetch()) {
                $enumId = (int)($enum['ID'] ?? 0);
                if ($enumId <= 0) {
                    continue;
                }

                $values[] = [
                    'id' => $enumId,
                    'value' => (string)($enum['VALUE'] ?? ''),
                    'sort' => (int)($enum['SORT'] ?? 500),
                    'xmlId' => (string)($enum['XML_ID'] ?? ''),
                ];
            }
        }

        if ($propertyType === 'E') {
            $linkedIblockId = (int)($property['LINK_IBLOCK_ID'] ?? 0);
            if ($linkedIblockId > 0) {
                $elementRes = \CIBlockElement::GetList(
                    ['SORT' => 'ASC', 'NAME' => 'ASC'],
                    ['IBLOCK_ID' => $linkedIblockId, 'ACTIVE' => 'Y'],
                    false,
                    false,
                    ['ID', 'NAME', 'SORT', 'CODE']
                );

                while ($element = $elementRes->Fetch()) {
                    $elementId = (int)($element['ID'] ?? 0);
                    if ($elementId <= 0) {
                        continue;
                    }

                    $values[] = [
                        'id' => $elementId,
                        'value' => (string)($element['NAME'] ?? ''),
                        'sort' => (int)($element['SORT'] ?? 500),
                        'xmlId' => (string)($element['CODE'] ?? ''),
                    ];
                }
            }
        }

        $result[] = [
            'id' => $propertyId,
            'name' => (string)($property['NAME'] ?? ('Свойство #' . $propertyId)),
            'code' => (string)($property['CODE'] ?? ''),
            'type' => $propertyType,
            'userType' => $userType,
            'multiple' => (string)($property['MULTIPLE'] ?? 'N') === 'Y',
            'values' => $values,
            'isSupported' => in_array($propertyType, ['L', 'E'], true),
        ];
    }

    return $result;
}

/**
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function buildCombinations(array $items): array
{
    $result = [[]];

    foreach ($items as $item) {
        $next = [];
        $values = (array)($item['selectedValues'] ?? []);

        foreach ($result as $combo) {
            foreach ($values as $value) {
                $candidate = $combo;
                $candidate[] = [
                    'propertyId' => (int)($item['propertyId'] ?? 0),
                    'code' => (string)($item['code'] ?? ''),
                    'valueId' => (int)($value['id'] ?? 0),
                    'value' => (string)($value['value'] ?? ''),
                ];
                $next[] = $candidate;
            }
        }

        $result = $next;
    }

    return $result;
}

/**
 * @param string $template
 * @param string $baseName
 * @param array<int,array<string,mixed>> $combo
 * @return string
 */
function applyNameTemplate(string $template, string $baseName, array $combo): string
{
    $name = str_replace('{#BASE_NAME#}', $baseName, $template);

    foreach ($combo as $item) {
        $code = (string)($item['code'] ?? '');
        if ($code === '') {
            continue;
        }

        $name = str_replace('{' . $code . '}', (string)($item['value'] ?? ''), $name);
    }

    return trim(preg_replace('/\s+/', ' ', $name) ?: '');
}

if ($action === 'get_config') {
    $properties = getCalcProperties($iblockId);

    echo json_encode([
        'success' => true,
        'properties' => $properties,
    ], JSON_UNESCAPED_UNICODE);
    die();
}

if ($action === 'generate') {
    $template = trim((string)($_REQUEST['name_template'] ?? ''));
    $baseName = trim((string)($_REQUEST['base_name'] ?? ''));
    $selectedRaw = (array)($_REQUEST['selected'] ?? []);

    if ($template === '') {
        echo json_encode(['error' => 'name_template_required'], JSON_UNESCAPED_UNICODE);
        die();
    }

    $properties = getCalcProperties($iblockId);
    $indexed = [];
    foreach ($properties as $property) {
        $indexed[(int)$property['id']] = $property;
    }

    $selected = [];
    foreach ($selectedRaw as $propertyId => $valueIds) {
        $pid = (int)$propertyId;
        $property = $indexed[$pid] ?? null;
        if (!$property || empty($property['isSupported'])) {
            continue;
        }

        $valueMap = [];
        foreach ((array)$property['values'] as $value) {
            $valueMap[(int)$value['id']] = $value;
        }

        $selectedValues = [];
        foreach ((array)$valueIds as $valueId) {
            $vid = (int)$valueId;
            if ($vid <= 0 || !isset($valueMap[$vid])) {
                continue;
            }
            $selectedValues[] = $valueMap[$vid];
        }

        if (empty($selectedValues)) {
            continue;
        }

        $selected[] = [
            'propertyId' => $pid,
            'code' => (string)$property['code'],
            'selectedValues' => $selectedValues,
        ];
    }

    if (empty($selected)) {
        echo json_encode(['error' => 'no_values_selected'], JSON_UNESCAPED_UNICODE);
        die();
    }

    $combinations = buildCombinations($selected);

    if (count($combinations) > 2000) {
        echo json_encode([
            'error' => 'too_many_combinations',
            'message' => 'Слишком много комбинаций (максимум 2000 за один запуск).',
            'count' => count($combinations),
        ], JSON_UNESCAPED_UNICODE);
        die();
    }

    $el = new \CIBlockElement();
    $createdIds = [];
    $errors = [];

    foreach ($combinations as $combo) {
        $name = applyNameTemplate($template, $baseName, $combo);
        if ($name === '') {
            $name = 'Товар';
        }

        $nameBase = $name;
        $uniqueSuffix = generateUniqueSuffix();
        $name = appendSuffixWithLimit($nameBase, PRODUCT_NAME_MAX_LENGTH, $uniqueSuffix, ' ');

        $baseCode = \CUtil::translit($nameBase, 'ru', [
            'replace_space' => '-',
            'replace_other' => '-',
            'change_case' => 'L',
        ]);
        $baseCode = trim((string)$baseCode, '-');
        if ($baseCode === '') {
            $baseCode = 'product';
        }

        $code = appendSuffixWithLimit($baseCode, SYMBOLIC_CODE_MAX_LENGTH, $uniqueSuffix, '-');

        $attempt = 0;
        while (isElementCodeExists($iblockId, $code) && $attempt < 5) {
            $attempt++;
            $uniqueSuffix = generateUniqueSuffix();
            $name = appendSuffixWithLimit($nameBase, PRODUCT_NAME_MAX_LENGTH, $uniqueSuffix, ' ');
            $code = appendSuffixWithLimit($baseCode, SYMBOLIC_CODE_MAX_LENGTH, $uniqueSuffix, '-');
        }

        $propertyValues = [];
        foreach ($combo as $item) {
            $propertyValues[(int)$item['propertyId']] = (int)$item['valueId'];
        }

        $fields = [
            'IBLOCK_ID' => $iblockId,
            'IBLOCK_SECTION_ID' => $sectionId > 0 ? $sectionId : false,
            'NAME' => $name,
            'ACTIVE' => 'Y',
            'CODE' => $code,
            'PROPERTY_VALUES' => $propertyValues,
        ];

        $newId = (int)$el->Add($fields, false, true, true);
        if ($newId <= 0) {
            $errors[] = $el->LAST_ERROR ?: ('Не удалось создать элемент: ' . $name);
            continue;
        }

        $createdIds[] = $newId;

        if (Loader::includeModule('catalog') && class_exists('\CCatalogProduct')) {
            if (!\CCatalogProduct::GetByID($newId)) {
                \CCatalogProduct::Add(['ID' => $newId, 'QUANTITY' => 0]);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'createdCount' => count($createdIds),
        'combinationCount' => count($combinations),
        'createdIds' => $createdIds,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE);
    die();
}

echo json_encode(['error' => 'unknown_action'], JSON_UNESCAPED_UNICODE);
die();
