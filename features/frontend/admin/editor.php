<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

global $APPLICATION, $USER;

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Access denied');
}

if (!Loader::includeModule('iblock')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    echo '<div class="adm-info-message-wrap"><div class="adm-info-message">Модуль iblock не подключен.</div></div>';
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

$moduleId = 'prospektweb.calc';
$elementId = (int)($_REQUEST['ID'] ?? 0);
$iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? 0);
$propertyCode = (string)Option::get($moduleId, 'CALC_PROPERTY_CODE', 'FRONTCALC_CONFIG');

$productsIblockId = (int)Option::get($moduleId, 'PRODUCTS_IBLOCK_ID', '0');
$offersIblockId = (int)Option::get($moduleId, 'OFFERS_IBLOCK_ID', '0');
$areaDisplayUnit = (string)Option::get($moduleId, 'AREA_DISPLAY_UNIT', 'mm2');
$areaUnitFactors = ['mm2' => 1, 'cm2' => 100, 'dm2' => 10000, 'm2' => 1000000];
$areaDisplayFactor = $areaUnitFactors[$areaDisplayUnit] ?? 1;

$schema = '';
$schemaFromDefault = false;
$propertyRes = CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode]);
$property = $propertyRes->Fetch();
$propertyId = (int)($property['ID'] ?? 0);

if ($elementId > 0 && $propertyId > 0) {
    $propValueRes = CIBlockElement::GetProperty($iblockId, $elementId, [], ['ID' => $propertyId]);
    if ($propValue = $propValueRes->Fetch()) {
        $schema = (string)($propValue['VALUE']['TEXT'] ?? '');
    }
}

$defaultSchema = Option::get($moduleId, 'CALC_EDITOR_SCHEMA', '');
if ($schema === '' && $defaultSchema !== '') {
    $schema = $defaultSchema;
    $schemaFromDefault = true;
}

$allProperties = [];
$propertyMap = [];
$saveError = '';

function frontcalcParsePositiveInt($value): ?int
{
    if (is_bool($value) || is_array($value) || is_object($value) || $value === null) { return null; }
    $text = preg_replace('/[\s\x{00A0}]+/u', '', trim((string)$value));
    return preg_match('/^[1-9][0-9]*$/', $text) === 1 ? (int)$text : null;
}

function frontcalcGetOfferVolumeNumbers(int $productId, int $productsIblockId, int $offersIblockId, string $volumeCode): array
{
    if ($productId <= 0 || $productsIblockId <= 0 || $offersIblockId <= 0 || !Loader::includeModule('catalog')) {
        return [];
    }
    $offersMap = CCatalogSKU::getOffersList([$productId], $productsIblockId, ['ACTIVE' => 'Y'], ['ID'], [$volumeCode]);
    $numbers = [];
    if (!empty($offersMap[$productId]) && is_array($offersMap[$productId])) {
        foreach ($offersMap[$productId] as $offerRow) {
            $offerId = (int)($offerRow['ID'] ?? 0);
            if ($offerId <= 0) { continue; }
            $propRes = CIBlockElement::GetProperty($offersIblockId, $offerId, [], ['CODE' => $volumeCode]);
            if ($prop = $propRes->Fetch()) {
                $xmlId = trim((string)($prop['VALUE_XML_ID'] ?? ''));
                $value = trim((string)($prop['VALUE_ENUM'] ?? $prop['VALUE'] ?? ''));
                $num = frontcalcParsePositiveInt($value);
                if ($num === null && $xmlId !== '') { $num = frontcalcParsePositiveInt($xmlId); }
                if ($num !== null) { $numbers[$num] = $num; }
            }
        }
    }
    sort($numbers, SORT_NUMERIC);
    return array_values($numbers);
}

function frontcalcGetOfferCalcProps(int $productId, int $productsIblockId, int $offersIblockId): array
{
    if ($productId <= 0 || $productsIblockId <= 0 || $offersIblockId <= 0 || !Loader::includeModule('catalog')) {
        return [];
    }
    $offersMap = CCatalogSKU::getOffersList([$productId], $productsIblockId, ['ACTIVE' => 'Y'], ['ID'], []);
    $result = [];
    if (!empty($offersMap[$productId]) && is_array($offersMap[$productId])) {
        foreach ($offersMap[$productId] as $offerRow) {
            $offerId = (int)($offerRow['ID'] ?? 0);
            if ($offerId <= 0) { continue; }
            $props = [];
            $propRes = CIBlockElement::GetProperty($offersIblockId, $offerId, [], []);
            while ($prop = $propRes->Fetch()) {
                $code = trim((string)($prop['CODE'] ?? ''));
                if ($code === '' || strpos($code, 'CALC_PROP_') !== 0) { continue; }
                $props[$code] = [
                    'value' => (string)($prop['VALUE'] ?? ''),
                    'xml_id' => (string)($prop['VALUE_XML_ID'] ?? ''),
                ];
            }
            $result[] = ['id' => $offerId, 'properties' => $props];
        }
    }
    return $result;
}

function frontcalcVolumeDefaultField(string $requiredVolumeCode): array
{
    return [
        'property_code' => $requiredVolumeCode,
        'inputs' => [[
            'code' => strtolower(str_replace('CALC_PROP_', '', $requiredVolumeCode)),
            'min' => '1',
            'max' => '99999',
            'step' => '1',
            'unit' => ' экз.',
            'show_unit' => true,
        ]],
        'show_presets' => true,
        'show_unit' => true,
        'is_group' => false,
        'group_code' => '',
        'group_delimiter' => 'x',
        'display_preset_xml_ids' => [],
        'display_mode' => 'input_presets',
        'deadline_adjustments' => [
            'mode' => 'simple',
            'urgent_markup' => '',
            'flexible_discount' => '',
            'advanced' => ['urgent_markup' => [], 'flexible_discount' => []],
        ],
    ];
}

function frontcalcPropertyDefaultField(string $propertyCode): array
{
    return [
        'property_code' => $propertyCode,
        'inputs' => [[
            'code' => strtolower(str_replace('CALC_PROP_', '', $propertyCode)),
            'min' => '',
            'max' => '',
            'step' => '',
            'unit' => '',
            'show_unit' => true,
        ]],
        'show_presets' => true,
        'show_unit' => true,
        'is_group' => false,
        'group_code' => '',
        'group_delimiter' => 'x',
        'display_preset_xml_ids' => [],
        'display_mode' => 'input_presets',
    ];
}

function frontcalcGetUsedOfferPropertyCodes(array $offerCalcProps): array
{
    $codes = [];
    foreach ($offerCalcProps as $offer) {
        foreach (($offer['properties'] ?? []) as $code => $property) {
            $value = trim((string)($property['value'] ?? ''));
            $xmlId = trim((string)($property['xml_id'] ?? ''));
            if ($value === '' && $xmlId === '') {
                continue;
            }
            $codes[$code] = true;
        }
    }
    return array_keys($codes);
}

function frontcalcMoveVolumeFieldFirst(array $fields, string $requiredVolumeCode): array
{
    $volumeField = null;
    $otherFields = [];
    foreach ($fields as $field) {
        if ((string)($field['property_code'] ?? '') === $requiredVolumeCode) {
            $volumeField = $field;
            continue;
        }
        $otherFields[] = $field;
    }
    return $volumeField === null ? $otherFields : array_merge([$volumeField], $otherFields);
}

if ($offersIblockId > 0) {
    $res = CIBlockProperty::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ['IBLOCK_ID' => $offersIblockId, 'ACTIVE' => 'Y', 'PROPERTY_TYPE' => 'L']
    );

    while ($row = $res->Fetch()) {
        if (strpos((string)$row['CODE'], 'CALC_PROP_') !== 0) {
            continue;
        }

        $enumValues = [];
        $enumRes = CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => $row['ID']]);
        while ($enum = $enumRes->Fetch()) {
            if ((string)$enum['XML_ID'] === '') {
                continue;
            }
            $enumValues[] = [
                'ID' => (int)$enum['ID'],
                'XML_ID' => (string)$enum['XML_ID'],
                'VALUE' => (string)$enum['VALUE'],
            ];
        }

        $item = [
            'CODE' => (string)$row['CODE'],
            'NAME' => (string)$row['NAME'],
            'ENUMS' => $enumValues,
        ];
        $allProperties[] = $item;
        $propertyMap[$item['CODE']] = $item;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && $elementId > 0 && $iblockId > 0 && $propertyCode !== '') {
    $schema = trim((string)($_POST['CALC_EDITOR_SCHEMA'] ?? ''));
    $requiredVolumeCode = 'CALC_PROP_VOLUME';
    $postedSchema = json_decode($schema, true);
    if (is_array($postedSchema) && isset($propertyMap[$requiredVolumeCode])) {
        $postedSchema['fields'] = (isset($postedSchema['fields']) && is_array($postedSchema['fields'])) ? $postedSchema['fields'] : [];
        $hasRequiredVolume = false;
        $volumeFieldIndex = null;
        foreach ($postedSchema['fields'] as $idx => $field) {
            if ((string)($field['property_code'] ?? '') === $requiredVolumeCode) {
                $hasRequiredVolume = true;
                $volumeFieldIndex = $idx;
                break;
            }
        }
        if (!$hasRequiredVolume) {
            array_unshift($postedSchema['fields'], frontcalcVolumeDefaultField($requiredVolumeCode));
            $volumeFieldIndex = 0;
        }
        $volumeField = $postedSchema['fields'][$volumeFieldIndex] ?? [];
        $volumeInputs = (isset($volumeField['inputs']) && is_array($volumeField['inputs'])) ? $volumeField['inputs'] : [];
        $volumeInput = $volumeInputs[0] ?? [];
        $min = isset($volumeInput['min']) && trim((string)$volumeInput['min']) !== '' ? (int)str_replace(' ', '', (string)$volumeInput['min']) : null;
        $max = isset($volumeInput['max']) && trim((string)$volumeInput['max']) !== '' ? (int)str_replace(' ', '', (string)$volumeInput['max']) : null;
        $step = isset($volumeInput['step']) && trim((string)$volumeInput['step']) !== '' ? (int)str_replace(' ', '', (string)$volumeInput['step']) : null;
        if ($min === null || $max === null || $step === null || $min <= 0 || $max <= 0 || $step <= 0 || $min > $max) {
            $saveError = 'Для тиража укажите положительные минимум, максимум и шаг; минимум не должен превышать максимум.';
        } elseif (($max - $min) % $step !== 0) {
            $saveError = 'Шаг тиража некорректен: максимум должен достигаться от минимума целым количеством шагов.';
        }
        $hasAreaDependency = false;
        foreach ($postedSchema['fields'] as $field) {
            if ((string)($field['property_code'] ?? '') !== $requiredVolumeCode && !empty($field['use_for_area_dependency'])) {
                $hasAreaDependency = true;
                break;
            }
        }
        if ($saveError === '' && $hasAreaDependency) {
            $ranges = (isset($volumeField['area_ranges']) && is_array($volumeField['area_ranges'])) ? $volumeField['area_ranges'] : [];
            $filledRanges = [];
            foreach ($ranges as $range) {
                $from = trim((string)($range['area_from_mm2'] ?? ''));
                $to = trim((string)($range['area_to_mm2'] ?? ''));
                $rangeMin = trim((string)($range['min'] ?? ''));
                $rangeMax = trim((string)($range['max'] ?? ''));
                $rangeStep = trim((string)($range['step'] ?? ''));
                if ($from === '' && $to === '' && $rangeMin === '' && $rangeMax === '' && $rangeStep === '') {
                    continue;
                }
                if ($from === '' || $rangeMin === '' || $rangeMax === '' || $rangeStep === '') {
                    $saveError = 'Для режима зависимости тиража от площади заполните площадь от, минимум, максимум и шаг. Площадь до заполняется автоматически.';
                    break;
                }
                $fromNum = (float)str_replace(',', '.', $from);
                $toNum = $to === '' ? INF : (float)str_replace(',', '.', $to);
                if ($fromNum < 0 || $toNum <= 0 || $fromNum > $toNum || (int)$rangeMin <= 0 || (int)$rangeMax <= 0 || (int)$rangeStep <= 0 || (int)$rangeMin > (int)$rangeMax) {
                    $saveError = 'Проверьте диапазоны зависимости тиража от площади: значения должны быть положительными, а минимум не должен превышать максимум.';
                    break;
                }
                if (((int)$rangeMax - (int)$rangeMin) % (int)$rangeStep !== 0) {
                    $saveError = 'Проверьте диапазоны зависимости тиража от площади: максимум должен достигаться от минимума целым количеством шагов.';
                    break;
                }
                foreach ($filledRanges as $existing) {
                    if ($fromNum <= $existing['to'] && $toNum >= $existing['from']) {
                        $saveError = 'Диапазоны площадей не должны пересекаться.';
                        break 2;
                    }
                }
                $filledRanges[] = ['from' => $fromNum, 'to' => $toNum];
            }
            if ($saveError === '' && empty($filledRanges)) {
                $saveError = 'Для режима зависимости тиража от площади добавьте хотя бы один заполненный диапазон.';
            }
        } elseif ($saveError === '') {
            $offerVolumes = frontcalcGetOfferVolumeNumbers($elementId, $productsIblockId, $offersIblockId, $requiredVolumeCode);
            foreach ($offerVolumes as $offerVolume) {
                if (($min !== null && $offerVolume < $min) || ($max !== null && $offerVolume > $max)) {
                    $saveError = 'Тираж ТП ' . $offerVolume . ' экз. не попадает в диапазон настроек. Исправьте минимум/максимум или удалите ТП вне диапазона.';
                    break;
                }
            }
        }
        $postedSchema['fields'] = frontcalcMoveVolumeFieldFirst($postedSchema['fields'], $requiredVolumeCode);
        $schema = json_encode($postedSchema, JSON_UNESCAPED_UNICODE);
    }
    if ($saveError === '') {
        CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [
        $propertyCode => [
            'VALUE' => [
                'TYPE' => 'HTML',
                'TEXT' => $schema,
            ],
        ],
    ]);

        LocalRedirect($APPLICATION->GetCurPageParam('saved=Y', ['saved']));
    }
}

$initialFields = [];
$decoded = json_decode($schema, true);
$requiredVolumeCode = 'CALC_PROP_VOLUME';
$offerVolumeNumbers = frontcalcGetOfferVolumeNumbers($elementId, $productsIblockId, $offersIblockId, $requiredVolumeCode);
$offerCalcProps = frontcalcGetOfferCalcProps($elementId, $productsIblockId, $offersIblockId);
if (is_array($decoded) && isset($decoded['fields']) && is_array($decoded['fields'])) {
    $usedOfferCodes = $schemaFromDefault ? array_flip(frontcalcGetUsedOfferPropertyCodes($offerCalcProps)) : [];
    foreach ($decoded['fields'] as $field) {
        $code = (string)($field['property_code'] ?? '');
        if ($code === '' || !isset($propertyMap[$code])) {
            continue;
        }
        if ($schemaFromDefault && $code !== $requiredVolumeCode && !isset($usedOfferCodes[$code])) {
            continue;
        }
        $initialFields[] = $field;
    }
} else {
    foreach (frontcalcGetUsedOfferPropertyCodes($offerCalcProps) as $code) {
        if (!isset($propertyMap[$code])) {
            continue;
        }
        $initialFields[] = $code === $requiredVolumeCode
            ? frontcalcVolumeDefaultField($requiredVolumeCode)
            : frontcalcPropertyDefaultField($code);
    }
}

$hasRequiredVolume = false;
foreach ($initialFields as $field) {
    if ((string)($field['property_code'] ?? '') === $requiredVolumeCode) {
        $hasRequiredVolume = true;
        break;
    }
}

if (!$hasRequiredVolume && isset($propertyMap[$requiredVolumeCode])) {
    array_unshift($initialFields, frontcalcVolumeDefaultField($requiredVolumeCode));
}
$initialFields = frontcalcMoveVolumeFieldFirst($initialFields, $requiredVolumeCode);

$selectedCodes = [];
foreach ($initialFields as $field) {
    $selectedCodes[] = (string)($field['property_code'] ?? '');
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>
<style>
body .adm-main-wrap{min-width:0 !important;}
body .adm-title{display:none!important;}
.fc-wrap{max-width:100%;width:100%;overflow-x:hidden;box-sizing:border-box;padding-right:10px;}
.fc-soft-wrap {background: linear-gradient(180deg,#f8fbff 0%,#f3f7ff 100%);border:1px solid #dce7ff;border-radius:16px;padding:16px;box-shadow:0 10px 24px rgba(34,71,156,.08);margin-bottom:12px;}
.fc-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.fc-card {border-radius:14px;border:1px solid #d9e3f8;background:#fff;box-shadow:0 6px 16px rgba(36,69,146,.08);transition:transform .18s ease, box-shadow .18s ease;overflow:hidden;margin-bottom:10px;}
.fc-card:hover {transform:translateY(-2px);box-shadow:0 12px 24px rgba(30,64,145,.16);}
.fc-card-head {width:100%;border:0;background:linear-gradient(180deg,#fff 0%,#f2f6ff 100%);text-align:left;padding:10px 12px;display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:14px;font-weight:600;cursor:pointer;box-sizing:border-box;}
.fc-card-title{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;min-width:0;}
.fc-head-actions{display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:12px;}
.fc-btn-inline{height:30px;padding:0 12px;border:1px solid #d3ddee;background:#f6f9ff;border-radius:8px;cursor:pointer;font-size:13px;color:#455a84;}
.fc-card-body{padding:12px;border-top:1px solid #e8efff;display:none;} .fc-card.open .fc-card-body{display:block;}
.fc-input,.fc-select{width:100%;height:40px;border:1px solid #cfd9f1;border-radius:10px;padding:0 10px;background:#fff;box-sizing:border-box;min-width:0;}
.fc-input:focus,.fc-select:focus{border-color:#2f6cff;box-shadow:0 0 0 3px rgba(47,108,255,.18);outline:none;}
.fc-field{display:flex;flex-direction:column;gap:2px;min-width:0;flex:1;}
.fc-field-label{font-size:11px;line-height:1.2;color:#6b7a99;margin:0;}
.fc-row{display:grid;grid-template-columns:repeat(3,minmax(70px,1fr));gap:8px;margin-bottom:8px;min-width:0;}
.fc-pills{display:grid;grid-template-columns:repeat(3,minmax(70px,1fr));gap:8px;margin:8px 0;}
.fc-pill{display:flex;align-items:center;gap:8px;border:1px solid #d7e2fb;background:#f8fbff;border-radius:10px;min-height:40px;padding:0 12px;font-size:13px;box-sizing:border-box;}
.fc-subtitle{margin:12px 0 8px;font-size:13px;color:#4d5d7d;font-weight:600;}
.fc-section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;}
.fc-section-head .fc-subtitle{margin-top:0;}
.fc-actions{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;}
.fc-input-block{border:1px dashed #d7e2fb;border-radius:10px;padding:8px;margin-bottom:8px;background:#fbfdff;min-width:0;}
.fc-input-row-actions{display:flex;justify-content:flex-end;align-items:center;gap:8px;margin-top:10px;}
.fc-btn-remove-input{padding:6px 14px;border:1px solid #f2c1c1;background:#fff5f5;color:#a93434;border-radius:10px;cursor:pointer;font-size:13px;}
.fc-add-input-inline{margin-right:auto;height:auto;padding: 6px;}
.fc-help{font-size:12px;color:#687895;margin-top:4px;}
.fc-btn-inline[disabled]{opacity:.45;cursor:not-allowed;}
.fc-match-select{max-width:360px;}
.fc-area-ranges,.fc-reference-groups{border:1px dashed #d7e2fb;border-radius:10px;padding:10px;background:#fbfdff;margin:10px 0;}
.fc-inline-row{display:grid;grid-template-columns:minmax(220px,1fr) minmax(220px,1fr);gap:12px;align-items:end;margin-bottom:8px;}
.js-area-dependency-wrap,.js-presets-toggle{display:block;grid-template-columns:none;}
.js-area-dependency-wrap .fc-pill,.js-presets-toggle .fc-pill{width:100%;max-width:420px;}
.fc-area-row{display:grid;grid-template-columns:42px repeat(5,minmax(70px,1fr)) 42px;gap:8px;margin-bottom:10px;align-items:end;}
.fc-area-ranges .fc-help,.fc-reference-groups .fc-help{margin:2px 0 14px;}
.fc-area-row .fc-input,.fc-area-row .fc-btn-inline{height:40px;min-width:40px;}
.fc-area-row .fc-btn-inline{padding:0;width:40px;min-width:40px;max-width:40px;justify-content:center;}
.fc-reference-accordion{border:1px solid #d7e2fb;border-radius:10px;background:#fff;margin:8px 0;overflow:hidden;}
.fc-reference-accordion-head{width:100%;border:0;background:#f6f9ff;padding:8px 10px;text-align:left;font-weight:600;cursor:pointer;display:flex;justify-content:space-between;gap:12px;align-items:center;}
.fc-reference-accordion-mark{margin-left:auto;color:#4d5d7d;white-space:nowrap;}
.fc-reference-accordion-body{display:none;padding:10px;}
.fc-reference-accordion.open .fc-reference-accordion-body{display:block;}
.fc-reference-list{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
.fc-reference-list label{min-height:30px;padding:4px 8px;border:1px solid #d7e2fb;border-radius:8px;background:#fff;}
.fc-deadline-accordion{border:1px solid #d7e2fb;border-radius:10px;background:#fff;margin-top:8px;overflow:hidden;}
.fc-deadline-accordion-head{width:100%;border:0;background:#f6f9ff;padding:10px 12px;text-align:left;font-weight:600;cursor:pointer;display:flex;justify-content:space-between;gap:12px;align-items:center;color:#4d5d7d;}
.fc-deadline-accordion-icon{font-size:16px;line-height:1;transition:transform .18s ease;}
.fc-deadline-accordion-body{display:none;padding:10px 12px 4px;}
.fc-deadline-accordion.open .fc-deadline-accordion-body{display:block;}
.fc-deadline-accordion.open .fc-deadline-accordion-icon{transform:rotate(180deg);}
@media (max-width: 520px){.fc-row,.fc-pills{grid-template-columns:1fr;}}
</style>

<div class="fc-wrap">
<?php if ($saveError !== ''): ?>
    <div class="adm-info-message-wrap"><div class="adm-info-message adm-info-message-red"><?= htmlspecialcharsbx($saveError) ?></div></div>
<?php endif; ?>
<?php if (($_GET['saved'] ?? '') === 'Y'): ?>
    <div class="adm-info-message-wrap success"><div class="adm-info-message">Конфигурация сохранена в свойство товара.</div></div>
<?php endif; ?>

<div class="fc-soft-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <h2 style="margin:0;">Настроить калькулятор</h2>
        <a class="adm-btn" href="/bitrix/admin/settings.php?mid=<?= urlencode($moduleId) ?>&lang=<?= LANGUAGE_ID ?>" target="_blank" rel="noopener" title="Глобальные настройки модуля" style="display:inline-flex;align-items:center;justify-content:center;font-size:18px;line-height:1;min-width:34px;">⚙</a>
    </div>
    <div class="fc-toolbar">
        <select id="fc-add-property" class="fc-select" style="min-width: 280px; max-width: 420px;">
            <option value="">Выберите свойство для добавления…</option>
            <?php foreach ($allProperties as $prop): ?>
                <?php if (in_array($prop['CODE'], $selectedCodes, true)) { continue; } ?>
                <option value="<?= htmlspecialcharsbx($prop['CODE']) ?>"><?= htmlspecialcharsbx($prop['NAME']) ?> (<?= htmlspecialcharsbx($prop['CODE']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="adm-btn" id="fc-add-property-btn">+ Добавить свойство</button>
    </div>
</div>

<form method="post" id="frontcalc-editor-form">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="CALC_EDITOR_SCHEMA" id="fc-schema-json" value="<?= htmlspecialcharsbx($schema) ?>">

    <div id="fc-fields-root">
        <?php foreach ($initialFields as $index => $field): ?>
            <?php
            $code = (string)$field['property_code'];
            $prop = $propertyMap[$code];
            $inputs = (isset($field['inputs']) && is_array($field['inputs']) && !empty($field['inputs'])) ? $field['inputs'] : [[
                'code' => strtolower(str_replace('CALC_PROP_', '', $code)),
                'min' => '',
                'max' => '',
                'step' => '',
                'unit' => '',
            ]];
            $displayXml = (isset($field['display_preset_xml_ids']) && is_array($field['display_preset_xml_ids'])) ? $field['display_preset_xml_ids'] : [];
            $isVolumeField = $code === $requiredVolumeCode;
            ?>
            <div class="fc-card<?= $index === 0 ? ' open' : '' ?>" data-prop-code="<?= htmlspecialcharsbx($code) ?>">
                <div class="fc-card-head js-fc-toggle" role="button" tabindex="0">
                    <span class="fc-card-title"><?= htmlspecialcharsbx($prop['NAME']) ?> <small style="opacity:.65; font-weight:400;">(<?= htmlspecialcharsbx($code) ?>)</small></span>
                    <span class="fc-head-actions">
                        <button type="button" class="fc-btn-inline js-remove-prop"<?= $code === $requiredVolumeCode ? ' disabled title="CALC_PROP_VOLUME обязательно для калькулятора"' : '' ?>>Удалить</button>
                        <span>▾</span>
                    </span>
                </div>
                <div class="fc-card-body">
                    <div class="fc-inline-row">
                        <div class="fc-field">
                            <div class="fc-field-label">Название чипсы открытия попапа</div>
                            <input class="fc-input js-open-popup-chip-label" placeholder="Например: Другой" value="<?= htmlspecialcharsbx((string)($field['open_popup_chip_label'] ?? '')) ?>">
                        </div>
                        <?php if (!$isVolumeField): ?>
                            <div class="fc-field">
                                <div class="fc-field-label">Тип отображения в попапе</div>
                                <select class="fc-select js-display-mode">
                                    <option value="input_presets"<?= (string)($field['display_mode'] ?? 'input_presets') !== 'chips_only' ? ' selected' : '' ?>>Инпут + пресеты</option>
                                    <option value="chips_only"<?= (string)($field['display_mode'] ?? '') === 'chips_only' ? ' selected' : '' ?>>Только чипсы без инпута</option>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!$isVolumeField): ?>
                        <div class="fc-pills js-area-dependency-wrap">
                            <label class="fc-pill"><input type="checkbox" class="js-use-area-dependency"<?= !empty($field['use_for_area_dependency']) ? ' checked' : '' ?>> Использовать для зависимости тиража от площади</label>
                        </div>
                    <?php endif; ?>
                    <div class="js-input-settings">
                        <div class="fc-subtitle">Инпуты поля</div>
                        <div class="js-fc-inputs">
                        <?php foreach ($inputs as $input): ?>
                            <div class="fc-input-block js-fc-input-row">
                                <div class="fc-row">
                                    <div class="fc-field"><div class="fc-field-label">Код</div><input class="fc-input js-inp-code" placeholder="Кодовое название" value="<?= htmlspecialcharsbx((string)($input['code'] ?? '')) ?>"></div>
                                    <div class="fc-field"><div class="fc-field-label">Мин</div><input class="fc-input js-inp-min" placeholder="Минимум" value="<?= htmlspecialcharsbx((string)($input['min'] ?? '')) ?>"></div>
                                    <div class="fc-field"><div class="fc-field-label">Макс</div><input class="fc-input js-inp-max" placeholder="Максимум" value="<?= htmlspecialcharsbx((string)($input['max'] ?? '')) ?>"></div>
                                    <div class="fc-field"><div class="fc-field-label">Шаг</div><input class="fc-input js-inp-step" placeholder="Шаг" value="<?= htmlspecialcharsbx((string)($input['step'] ?? '')) ?>"></div>
                                    <div class="fc-field"><div class="fc-field-label">Ед. изм.</div><input class="fc-input js-inp-unit" placeholder="Ед. изм." value="<?= htmlspecialcharsbx((string)($input['unit'] ?? '')) ?>"></div>
                                    <?php if (!$isVolumeField): ?>
                                        <div class="fc-field"><div class="fc-field-label">&nbsp;</div><label class="fc-pill"><input type="checkbox" class="js-inp-show-unit"<?= array_key_exists('show_unit', $input) ? (!empty($input['show_unit']) ? ' checked' : '') : (!isset($field['show_unit']) || $field['show_unit'] ? ' checked' : '') ?>> Показывать ед. изм.</label></div>
                                    <?php endif; ?>
                                </div>
                                <div class="fc-input-row-actions">
                                    <?php if (!$isVolumeField): ?>
                                        <button type="button" class="adm-btn fc-add-input-inline js-add-input">+ Добавить инпут</button>
                                    <?php endif; ?>
                                    <button type="button" class="fc-btn-remove-input js-remove-input">Удалить инпут</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($isVolumeField): ?>
                        <?php
                        $areaRanges = (isset($field['area_ranges']) && is_array($field['area_ranges'])) ? $field['area_ranges'] : [];
                        $hasSavedAreaRanges = !empty($areaRanges);
                        $referenceVolumes = (isset($field['reference_volumes']) && is_array($field['reference_volumes'])) ? $field['reference_volumes'] : [];
                        $baseInput = $inputs[0] ?? [];
                        if (empty($areaRanges)) {
                            $areaRanges = [[
                                'area_from_mm2' => '',
                                'area_to_mm2' => '',
                                'min' => (string)($baseInput['min'] ?? ''),
                                'max' => (string)($baseInput['max'] ?? ''),
                                'step' => (string)($baseInput['step'] ?? ''),
                            ]];
                        }
                        $volumeEnumNumbers = [];
                        foreach ($prop['ENUMS'] as $enum) {
                            $num = frontcalcParsePositiveInt((string)($enum['VALUE'] ?? ''));
                            if ($num === null) { $num = frontcalcParsePositiveInt((string)($enum['XML_ID'] ?? '')); }
                            if ($num !== null) { $volumeEnumNumbers[$num] = $num; }
                        }
                        foreach ($offerVolumeNumbers as $num) { $volumeEnumNumbers[(int)$num] = (int)$num; }
                        if ((int)($baseInput['min'] ?? 0) > 0) { $volumeEnumNumbers[(int)$baseInput['min']] = (int)$baseInput['min']; }
                        if ((int)($baseInput['max'] ?? 0) > 0) { $volumeEnumNumbers[(int)$baseInput['max']] = (int)$baseInput['max']; }
                        ksort($volumeEnumNumbers, SORT_NUMERIC);
                        ?>
                        <div class="js-volume-base-settings"></div>
                        <div class="fc-area-ranges js-area-ranges-wrap" style="display:none;">
                            <div class="fc-section-head"><div class="fc-subtitle">Ограничения тиража по площади (<?= htmlspecialcharsbx($areaDisplayUnit) ?>)</div><button type="button" class="fc-btn-inline js-reset-area-ranges">Сбросить</button></div>
                            <div class="fc-help">Заполните диапазоны без пересечений. Площадь вводится в выбранной единице, сохраняется в мм².</div>
                            <div class="js-area-ranges" data-auto-built="<?= $hasSavedAreaRanges ? '0' : '1' ?>">
                                <?php foreach ($areaRanges as $range): ?>
                                    <div class="fc-area-row js-area-row">
                                        <button type="button" class="fc-btn-inline js-add-area-row" title="Добавить диапазон">+</button>
                                        <div class="fc-field"><div class="fc-field-label">Площадь от</div><input class="fc-input js-area-from" value="<?= htmlspecialcharsbx((string)(array_key_exists('area_from_mm2', $range) && (string)$range['area_from_mm2'] !== '' ? ((float)$range['area_from_mm2'] / $areaDisplayFactor) : '')) ?>"></div>
                                        <div class="fc-field"><div class="fc-field-label">Площадь до</div><input class="fc-input js-area-to" readonly value="<?= htmlspecialcharsbx((string)((float)($range['area_to_mm2'] ?? 0) > 0 ? ((float)$range['area_to_mm2'] / $areaDisplayFactor) : '')) ?>"></div>
                                        <div class="fc-field"><div class="fc-field-label">Минимум</div><input class="fc-input js-area-min" value="<?= htmlspecialcharsbx((string)($range['min'] ?? '')) ?>"></div>
                                        <div class="fc-field"><div class="fc-field-label">Максимум</div><input class="fc-input js-area-max" value="<?= htmlspecialcharsbx((string)($range['max'] ?? '')) ?>"></div>
                                        <div class="fc-field"><div class="fc-field-label">Шаг</div><input class="fc-input js-area-step" value="<?= htmlspecialcharsbx((string)($range['step'] ?? '')) ?>"></div>
                                        <button type="button" class="fc-btn-inline js-remove-area-row">×</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="fc-reference-groups js-reference-wrap" data-reference-volumes="<?= htmlspecialcharsbx(json_encode($referenceVolumes, JSON_UNESCAPED_UNICODE)); ?>">
                            <div class="fc-section-head"><div class="fc-subtitle">Взаимодействие с сервером калькуляции</div><button type="button" class="fc-btn-inline js-reset-reference-volumes">Сбросить</button></div>
                            <div class="fc-help">Выберите опорные тиражи для будущей интерполяции/экстраполяции. Минимум, максимум и тиражи существующих ТП считаются обязательными.</div>
                            <div class="fc-reference-list js-reference-base" data-reference-template="1">
                                <?php foreach ($volumeEnumNumbers as $num): ?>
                                    <?php $checked = in_array((string)$num, array_map('strval', $referenceVolumes['base'] ?? []), true) || in_array($num, $offerVolumeNumbers, true) || (int)($baseInput['min'] ?? 0) === $num || (int)($baseInput['max'] ?? 0) === $num; ?>
                                    <label><input type="checkbox" class="js-reference-volume" value="<?= (int)$num ?>"<?= $checked ? ' checked' : '' ?><?= ((int)($baseInput['min'] ?? 0) === $num || (int)($baseInput['max'] ?? 0) === $num) ? ' disabled data-required="1"' : (in_array($num, $offerVolumeNumbers, true) ? ' data-required="1"' : '') ?>> <?= number_format((int)$num, 0, '.', ' ') ?></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php
                        $deadline = (isset($field['deadline_adjustments']) && is_array($field['deadline_adjustments'])) ? $field['deadline_adjustments'] : [];
                        $deadlineMode = (string)($deadline['mode'] ?? 'simple');
                        $advanced = (isset($deadline['advanced']) && is_array($deadline['advanced'])) ? $deadline['advanced'] : [];
                        $advMarkup = (isset($advanced['urgent_markup']) && is_array($advanced['urgent_markup'])) ? $advanced['urgent_markup'] : [];
                        $advDiscount = (isset($advanced['flexible_discount']) && is_array($advanced['flexible_discount'])) ? $advanced['flexible_discount'] : [];
                        $advMarkupByVolume = [];
                        foreach ($advMarkup as $row) { $advMarkupByVolume[(string)($row['volume'] ?? '')] = (string)($row['percent'] ?? ''); }
                        $advDiscountByVolume = [];
                        foreach ($advDiscount as $row) { $advDiscountByVolume[(string)($row['volume'] ?? '')] = (string)($row['percent'] ?? ''); }
                        ?>
                        <div class="fc-subtitle">Скидки и наценки по срокам</div>
                        <div class="fc-row">
                            <div class="fc-field"><div class="fc-field-label">Режим</div><select class="fc-select js-deadline-mode"><option value="simple"<?= $deadlineMode !== 'advanced' ? ' selected' : '' ?>>Простой</option><option value="advanced"<?= $deadlineMode === 'advanced' ? ' selected' : '' ?>>Расширенный</option></select></div>
                            <div class="fc-field js-deadline-simple"><div class="fc-field-label">Наценка за срочность, %</div><input class="fc-input js-urgent-markup" value="<?= htmlspecialcharsbx((string)($deadline['urgent_markup'] ?? '')) ?>"></div>
                            <div class="fc-field js-deadline-simple"><div class="fc-field-label">Скидка за гибкий срок, %</div><input class="fc-input js-flexible-discount" value="<?= htmlspecialcharsbx((string)($deadline['flexible_discount'] ?? '')) ?>"></div>
                        </div>
                        <div class="js-deadline-advanced" style="display:<?= $deadlineMode === 'advanced' ? 'block' : 'none' ?>;">
                            <div class="fc-deadline-accordion">
                                <button type="button" class="fc-deadline-accordion-head js-deadline-accordion-toggle" aria-expanded="false">
                                    <span>Наценки и скидки по тиражам</span><span class="fc-deadline-accordion-icon">▼</span>
                                </button>
                                <div class="fc-deadline-accordion-body">
                                    <div class="fc-help">Пустое значение не меняет действующую ставку. 0% останавливает ранее заданную ставку с этого тиража.</div>
                                    <?php foreach ($prop['ENUMS'] as $enum): ?>
                                        <?php
                                        $enumXmlId = (string)$enum['XML_ID'];
                                        $enumVolumeNumber = frontcalcParsePositiveInt((string)$enum['VALUE']);
                                        $markupValue = $advMarkupByVolume[$enumXmlId] ?? ($enumVolumeNumber !== null ? ($advMarkupByVolume[(string)$enumVolumeNumber] ?? '') : '');
                                        $discountValue = $advDiscountByVolume[$enumXmlId] ?? ($enumVolumeNumber !== null ? ($advDiscountByVolume[(string)$enumVolumeNumber] ?? '') : '');
                                        ?>
                                        <div class="fc-row js-deadline-row" data-volume="<?= htmlspecialcharsbx($enumXmlId) ?>">
                                            <div class="fc-field"><div class="fc-field-label">Тираж</div><input class="fc-input" value="<?= htmlspecialcharsbx($enum['VALUE']) ?>" disabled></div>
                                            <div class="fc-field"><div class="fc-field-label">Наценка, %</div><input class="fc-input js-adv-urgent" value="<?= htmlspecialcharsbx((string)$markupValue) ?>"></div>
                                            <div class="fc-field"><div class="fc-field-label">Скидка, %</div><input class="fc-input js-adv-flexible" value="<?= htmlspecialcharsbx((string)$discountValue) ?>"></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!$isVolumeField): ?>
                        <div class="fc-pills js-presets-toggle">
                            <label class="fc-pill"><input type="checkbox" class="js-show-presets"<?= !isset($field['show_presets']) || $field['show_presets'] ? ' checked' : '' ?>> Показывать чипсы в качестве пресетов</label>
                        </div>

                        <div class="fc-subtitle">Выберите чипсы для отображения</div>
                        <select class="fc-select js-display-presets" multiple size="5" style="height:auto; min-height:96px;">
                            <?php foreach ($prop['ENUMS'] as $enum): ?>
                                <option value="<?= htmlspecialcharsbx($enum['XML_ID']) ?>"<?= in_array($enum['XML_ID'], $displayXml, true) ? ' selected' : '' ?>><?= htmlspecialcharsbx($enum['VALUE']) ?> [<?= htmlspecialcharsbx($enum['XML_ID']) ?>]</option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <div class="fc-group-wrap" style="display:<?= count($inputs) > 1 ? 'block' : 'none' ?>;">
                        <div class="fc-subtitle">Групповые настройки (авто при >1 инпута)</div>
                    <div class="js-group-settings">
                        <div class="fc-row">
                            <div class="fc-field"><div class="fc-field-label">Код группы</div><input class="fc-input js-group-code" placeholder="Код группы" value="<?= htmlspecialcharsbx((string)($field['group_code'] ?? '')) ?>"></div>
                            <div class="fc-field"><div class="fc-field-label">Разделитель</div><input class="fc-input js-group-delimiter" placeholder="Разделитель значений" value="<?= htmlspecialcharsbx((string)($field['group_delimiter'] ?? 'x')) ?>"></div>
                        </div>
                    </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>


    <br>
    <input type="submit" value="Сохранить" class="adm-btn-save">
</form>
</div>

<script>
(function() {
    const allProperties = <?= \Bitrix\Main\Web\Json::encode($allProperties) ?>;
    const offerCalcProps = <?= \Bitrix\Main\Web\Json::encode($offerCalcProps) ?>;
    const savedSchema = (() => { try { return JSON.parse(<?= \Bitrix\Main\Web\Json::encode($schema ?: '{}') ?>) || {}; } catch (e) { return {}; } })();
    const requiredVolumeCode = 'CALC_PROP_VOLUME';
    const areaDisplayFactor = <?= (float)$areaDisplayFactor ?>;
    const propsByCode = {};
    allProperties.forEach(p => { propsByCode[p.CODE] = p; });

    const root = document.getElementById('fc-fields-root');
    const form = document.getElementById('frontcalc-editor-form');
    const schemaInput = document.getElementById('fc-schema-json');
    const addSelect = document.getElementById('fc-add-property');
    const addBtn = document.getElementById('fc-add-property-btn');
    function getRows(card){ return Array.from(card.querySelectorAll('.js-fc-input-row')); }
    function syncGroup(card){
        const group = card.querySelector('.fc-group-wrap');
        const rows = getRows(card);
        if (group) {
            group.style.display = rows.length > 1 ? 'block' : 'none';
        }
        rows.forEach(function(row, index){
            const removeBtn = row.querySelector('.js-remove-input');
            if (removeBtn) {
                removeBtn.style.display = rows.length > 1 ? 'inline-flex' : 'none';
            }
            const addInputBtn = row.querySelector('.js-add-input');
            if (addInputBtn) {
                addInputBtn.style.display = index === rows.length - 1 ? 'inline-flex' : 'none';
            }
        });
    }

    function syncDisplayMode(card){
        const mode = card.querySelector('.js-display-mode');
        if (!mode) {
            return;
        }
        const isChipsOnly = mode.value === 'chips_only';
        const inputSettings = card.querySelector('.js-input-settings');
        const presetsToggle = card.querySelector('.js-presets-toggle');
        const group = card.querySelector('.fc-group-wrap');
        if (inputSettings) {
            inputSettings.style.display = isChipsOnly ? 'none' : '';
        }
        if (presetsToggle) {
            presetsToggle.style.display = isChipsOnly ? 'none' : '';
        }
        if (group && isChipsOnly) {
            group.style.display = 'none';
        } else if (group) {
            syncGroup(card);
        }
        if (isChipsOnly) {
            const showPresets = card.querySelector('.js-show-presets');
            if (showPresets) {
                showPresets.checked = true;
            }
        }
    }

    function availableCodesInCards(){
        return Array.from(document.querySelectorAll('.fc-card')).map(card => card.dataset.propCode);
    }

    function refreshAddSelect(){
        const selected = availableCodesInCards();
        const current = addSelect.value;
        addSelect.innerHTML = '<option value="">Выберите свойство для добавления…</option>';
        allProperties.forEach(prop => {
            if (selected.indexOf(prop.CODE) !== -1) {
                return;
            }
            const option = document.createElement('option');
            option.value = prop.CODE;
            option.textContent = prop.NAME + ' (' + prop.CODE + ')';
            addSelect.appendChild(option);
        });
        addSelect.value = current;
    }

    function renderEnumOptions(prop){
        const enums = Array.isArray(prop.ENUMS) ? prop.ENUMS : [];
        return enums.map(e => '<option value="' + escapeHtml(e.XML_ID) + '">' + escapeHtml(e.VALUE) + ' [' + escapeHtml(e.XML_ID) + ']</option>').join('');
    }

    function createCard(propCode){
        const prop = propsByCode[propCode];
        if (!prop) {
            return null;
        }
        const isVolume = prop.CODE === requiredVolumeCode;

        const html = '\n<div class="fc-card open" data-prop-code="' + escapeHtml(prop.CODE) + '">\n'
            + '  <div class="fc-card-head js-fc-toggle" role="button" tabindex="0">\n'
            + '    <span class="fc-card-title">' + escapeHtml(prop.NAME) + ' <small style="opacity:.65; font-weight:400;">(' + escapeHtml(prop.CODE) + ')</small></span>\n'
            + '    <span class="fc-head-actions"><button type="button" class="fc-btn-inline js-remove-prop"' + (prop.CODE === requiredVolumeCode ? ' disabled title="CALC_PROP_VOLUME обязательно для калькулятора"' : '') + '>Удалить</button> <span>▾</span></span>\n'
            + '  </div>\n'
            + '  <div class="fc-card-body">\n'
            + '    <div class="fc-inline-row"><div class="fc-field"><div class="fc-field-label">Название чипсы открытия попапа</div><input class="fc-input js-open-popup-chip-label" placeholder="Например: Другой"></div>'
            + (isVolume ? '' : '<div class="fc-field"><div class="fc-field-label">Тип отображения в попапе</div><select class="fc-select js-display-mode"><option value="input_presets" selected>Инпут + пресеты</option><option value="chips_only">Только чипсы без инпута</option></select></div>')
            + '</div>\n'
            + '    <div class="js-input-settings">\n'
            + '      <div class="fc-subtitle">Инпуты поля</div>\n'
            + '      <div class="js-fc-inputs">\n'
            + '      <div class="fc-input-block js-fc-input-row">\n'
            + '        <div class="fc-row">\n'
            + '          <div class="fc-field"><div class="fc-field-label">Код</div><input class="fc-input js-inp-code" placeholder="Кодовое название" value="' + escapeHtml(prop.CODE.replace('CALC_PROP_', '').toLowerCase()) + '"></div>\n'
            + '          <div class="fc-field"><div class="fc-field-label">Мин</div><input class="fc-input js-inp-min" placeholder="Минимум" value="' + (isVolume ? '1' : '') + '"></div>\n'
            + '          <div class="fc-field"><div class="fc-field-label">Макс</div><input class="fc-input js-inp-max" placeholder="Максимум" value="' + (isVolume ? '99999' : '') + '"></div>\n'
            + '          <div class="fc-field"><div class="fc-field-label">Шаг</div><input class="fc-input js-inp-step" placeholder="Шаг" value="' + (isVolume ? '1' : '') + '"></div>\n'
            + '          <div class="fc-field"><div class="fc-field-label">Ед. изм.</div><input class="fc-input js-inp-unit" placeholder="Ед. изм." value="' + (isVolume ? ' экз.' : '') + '"></div>\n'
            + (isVolume ? '' : '          <div class="fc-field"><div class="fc-field-label">&nbsp;</div><label class="fc-pill"><input type="checkbox" class="js-inp-show-unit" checked> Показывать ед. изм.</label></div>\n')
            + '        </div>\n'
            + '        <div class="fc-input-row-actions">' + (isVolume ? '' : '<button type="button" class="adm-btn fc-add-input-inline js-add-input">+ Добавить инпут</button>') + '<button type="button" class="fc-btn-remove-input js-remove-input">Удалить инпут</button></div>\n'
            + '      </div>\n'
            + '      </div>\n'
            + '    </div>\n'
            + (isVolume ? '' : '    <div class="fc-pills js-area-dependency-wrap"><label class="fc-pill"><input type="checkbox" class="js-use-area-dependency"> Использовать для зависимости тиража от площади</label></div>\n')
            + (isVolume ? '' : '    <div class="fc-pills js-presets-toggle">\n'
            + '      <label class="fc-pill"><input type="checkbox" class="js-show-presets" checked> Показывать чипсы в качестве пресетов</label>\n'
            + '    </div>\n'
            + '    <div class="fc-subtitle">Выберите чипсы для отображения</div>\n'
            + '    <select class="fc-select js-display-presets" multiple size="5" style="height:auto; min-height:96px;">' + renderEnumOptions(prop) + '</select>\n')
            + '    <div class="fc-group-wrap" style="display:none;">\n'
            + '      <div class="fc-subtitle">Групповые настройки (авто при >1 инпута)</div>\n'
            + '      <div class="js-group-settings"><div class="fc-row">\n'
            + '        <input class="fc-input js-group-code" placeholder="Код группы">\n'
            + '        <input class="fc-input js-group-delimiter" placeholder="Разделитель значений" value="x">\n'
            + '      </div></div>\n'
            + '    </div>\n'
            + '  </div>\n'
            + '</div>';

        const wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        return wrap.firstChild;
    }

    function escapeHtml(str){
        return String(str || '').replace(/[&<>'"]/g, function(ch){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','\'' :'&#039;','"':'&quot;'}[ch];
        });
    }


    function parseNumber(value){
        const normalized = String(value || '').replace(/\s+/g, '').replace(',', '.');
        const num = Number(normalized);
        return Number.isFinite(num) ? num : NaN;
    }

    function parseAreaFromToken(token){
        const parts = String(token || '').replace(/[×*]/g, 'x').split('x').map(parseNumber).filter(Number.isFinite);
        if (parts.length < 2) return NaN;
        return parts[0] * parts[1];
    }

    function parseAreaFromOption(option){
        if (!option) return NaN;
        const fromValue = parseAreaFromToken(option.value);
        return Number.isFinite(fromValue) ? fromValue : parseAreaFromToken(option.textContent);
    }

    function getAreaDependencyCard(){
        return Array.from(document.querySelectorAll('.fc-card')).find(card => {
            const cb = card.querySelector('.js-use-area-dependency');
            return cb && cb.checked;
        }) || null;
    }

    function syncAreaDependency(){
        const activeCard = getAreaDependencyCard();
        document.querySelectorAll('.fc-card').forEach(card => {
            const cb = card.querySelector('.js-use-area-dependency');
            if (cb) {
                const wrap = cb.closest('.js-area-dependency-wrap');
                const mode = card.querySelector('.js-display-mode');
                const canUse = getRows(card).length === 2 && (!mode || mode.value !== 'chips_only');
                wrap.style.display = canUse && (!activeCard || activeCard === card) ? '' : 'none';
                if (!canUse) cb.checked = false;
            }
        });
        const volumeCard = document.querySelector('.fc-card[data-prop-code="' + requiredVolumeCode + '"]');
        if (!volumeCard) return;
        const hasArea = !!activeCard;
        const baseInputs = volumeCard.querySelector('.js-input-settings');
        const areaWrap = volumeCard.querySelector('.js-area-ranges-wrap');
        if (baseInputs) baseInputs.style.display = hasArea ? 'none' : '';
        if (areaWrap) areaWrap.style.display = hasArea ? 'block' : 'none';
        if (hasArea) {
            const base = getBaseVolumeInputValues(volumeCard);
            const firstArea = volumeCard.querySelector('.js-area-row');
            if (firstArea && !(firstArea.querySelector('.js-area-min').value || firstArea.querySelector('.js-area-max').value || firstArea.querySelector('.js-area-step').value)) {
                firstArea.querySelector('.js-area-min').value = base.min;
                firstArea.querySelector('.js-area-max').value = base.max;
                firstArea.querySelector('.js-area-step').value = base.step;
            }
            ensureAreaRowsFromDependency(activeCard, volumeCard);
        } else {
            const firstArea = volumeCard.querySelector('.js-area-row');
            const baseRow = volumeCard.querySelector('.js-fc-input-row');
            if (firstArea && baseRow) {
                const min = firstArea.querySelector('.js-area-min').value;
                const max = firstArea.querySelector('.js-area-max').value;
                const step = firstArea.querySelector('.js-area-step').value;
                if (min) baseRow.querySelector('.js-inp-min').value = min;
                if (max) baseRow.querySelector('.js-inp-max').value = max;
                if (step) baseRow.querySelector('.js-inp-step').value = step;
            }
        }
        syncAreaRows(volumeCard);
    }

    function cloneAreaRow(volumeCard){
        return cloneAreaRowFromTemplate(volumeCard);
    }

    function getBaseVolumeInputValues(volumeCard){
        const row = volumeCard.querySelector('.js-fc-input-row');
        return {
            min: row && row.querySelector('.js-inp-min') ? row.querySelector('.js-inp-min').value : '',
            max: row && row.querySelector('.js-inp-max') ? row.querySelector('.js-inp-max').value : '',
            step: row && row.querySelector('.js-inp-step') ? row.querySelector('.js-inp-step').value : ''
        };
    }

    function buildAreaThresholdRows(areas){
        const uniqueAreas = Array.from(new Set((areas || []).filter(area => Number.isFinite(area) && area > 0))).sort((a, b) => a - b);
        if (!uniqueAreas.length) return [];
        const rows = [{from: 0, to: Math.max(0, uniqueAreas[0] - 1), threshold: null}];
        uniqueAreas.forEach((area, index) => {
            rows.push({
                from: area,
                to: index < uniqueAreas.length - 1 ? Math.max(area, uniqueAreas[index + 1] - 1) : Number.POSITIVE_INFINITY,
                threshold: area
            });
        });
        return rows;
    }

    function fillAreaRow(row, config, base){
        const values = config.values || base;
        row.querySelector('.js-area-from').value = config.from / areaDisplayFactor;
        row.querySelector('.js-area-to').value = config.to === Number.POSITIVE_INFINITY ? '∞' : (config.to / areaDisplayFactor);
        row.querySelector('.js-area-min').value = values.min;
        row.querySelector('.js-area-max').value = values.max;
        row.querySelector('.js-area-step').value = values.step;
        row.dataset.areaThreshold = config.threshold === null ? '' : String(config.threshold);
    }

    function getAreaRowValuesByBoundary(container){
        const result = {};
        Array.from(container.querySelectorAll('.js-area-row')).forEach(row => {
            const from = parseNumber(row.querySelector('.js-area-from').value);
            const rawTo = row.querySelector('.js-area-to').value;
            const to = rawTo === '∞' ? Number.POSITIVE_INFINITY : parseNumber(rawTo);
            const values = {
                min: row.querySelector('.js-area-min').value || '',
                max: row.querySelector('.js-area-max').value || '',
                step: row.querySelector('.js-area-step').value || ''
            };
            if (Number.isFinite(from)) result[String(from * areaDisplayFactor)] = values;
            if (Number.isFinite(to)) result[String(to * areaDisplayFactor)] = values;
        });
        return result;
    }

    function ensureAreaRowsFromDependency(depCard, volumeCard){
        const select = depCard.querySelector('.js-display-presets');
        const container = volumeCard.querySelector('.js-area-ranges');
        if (!select || !container) return;
        const base = getBaseVolumeInputValues(volumeCard);
        const areas = Array.from(select.selectedOptions)
            .map(parseAreaFromOption)
            .filter(area => Number.isFinite(area) && area > 0)
            .sort((a, b) => a - b);
        const configs = buildAreaThresholdRows(areas);
        if (!configs.length) return;
        const existingValuesByBoundary = getAreaRowValuesByBoundary(container);
        configs.forEach(config => {
            if (existingValuesByBoundary[String(config.from)]) config.values = existingValuesByBoundary[String(config.from)];
        });
        const templateRow = volumeCard.querySelector('.js-area-row');
        const existingFilled = Array.from(container.querySelectorAll('.js-area-row')).some(row => {
            return ['.js-area-min', '.js-area-max', '.js-area-step'].some(selector => {
                const input = row.querySelector(selector);
                return input && String(input.value || '').trim() !== '';
            });
        });
        if (existingFilled && container.dataset.autoBuilt === '0') {
            ensureMissingAreaBoundaryRows(volumeCard, container, areas, base, templateRow);
            return;
        }
        const rows = [];
        let changed = false;
        container.innerHTML = '';
        configs.forEach(config => {
            const row = cloneAreaRowFromTemplate(volumeCard, templateRow);
            if (!row) return;
            fillAreaRow(row, config, base);
            container.appendChild(row);
            rows.push(row);
            changed = true;
        });
        if (changed) syncAreaRows(volumeCard);
    }

    function ensureMissingAreaBoundaryRows(volumeCard, container, areas, base, templateRow){
        const existingFroms = new Set(Array.from(container.querySelectorAll('.js-area-row')).map(row => {
            const from = parseNumber(row.querySelector('.js-area-from').value);
            return Number.isFinite(from) ? String(from * areaDisplayFactor) : '';
        }));
        buildAreaThresholdRows(areas).forEach(config => {
            if (existingFroms.has(String(config.from))) {
                return;
            }
            const row = cloneAreaRowFromTemplate(volumeCard, templateRow);
            if (!row) {
                return;
            }
            fillAreaRow(row, config, base);
            container.appendChild(row);
        });
        syncAreaRows(volumeCard);
    }

    function cloneAreaRowFromTemplate(volumeCard, templateRow){
        const row = templateRow || volumeCard.querySelector('.js-area-row');
        if (!row) return null;
        const clone = row.cloneNode(true);
        clone.querySelectorAll('input').forEach(input => { input.value = ''; input.readOnly = input.classList.contains('js-area-to'); });
        return clone;
    }

    function syncAreaRows(volumeCard){
        const container = volumeCard && volumeCard.querySelector('.js-area-ranges');
        if (!container) return;
        const rows = Array.from(container.querySelectorAll('.js-area-row'));
        rows.sort((a, b) => parseNumber(a.querySelector('.js-area-from').value) - parseNumber(b.querySelector('.js-area-from').value));
        rows.forEach(row => container.appendChild(row));
        rows.forEach((row, idx) => {
            const from = row.querySelector('.js-area-from');
            const to = row.querySelector('.js-area-to');
            const add = row.querySelector('.js-add-area-row');
            const remove = row.querySelector('.js-remove-area-row');
            if (from) from.readOnly = idx === 0;
            if (idx === 0 && from && !from.value) from.value = '0';
            if (to) {
                to.readOnly = true;
                if (idx < rows.length - 1) {
                    const nextFrom = parseNumber(rows[idx + 1].querySelector('.js-area-from').value);
                    to.value = Number.isFinite(nextFrom) ? Math.max(0, nextFrom - (1 / areaDisplayFactor)) : '';
                } else {
                    to.value = '∞';
                }
            }
            if (add) add.style.display = '';
            if (remove) remove.style.display = rows.length <= 1 || idx === 0 ? 'none' : '';
        });
        filterBaseReferenceValues(volumeCard);
        renderReferenceAccordions(volumeCard);
    }

    function getAllowedReferenceValues(volumeCard, rangeRow){
        const base = rangeRow ? {min: rangeRow.querySelector('.js-area-min').value, max: rangeRow.querySelector('.js-area-max').value} : getBaseVolumeInputValues(volumeCard);
        const min = parseNumber(base.min);
        const max = parseNumber(base.max);
        return Array.from(volumeCard.querySelectorAll('.js-reference-volume')).filter(input => {
            const value = parseNumber(input.value);
            return Number.isFinite(value) && (!Number.isFinite(min) || value >= min) && (!Number.isFinite(max) || value <= max);
        });
    }

    function filterBaseReferenceValues(volumeCard){
        const allowed = new Set(getAllowedReferenceValues(volumeCard).map(input => input.value));
        volumeCard.querySelectorAll('.js-reference-volume').forEach(input => {
            const label = input.closest('label');
            if (label) label.style.display = allowed.has(input.value) ? '' : 'none';
        });
    }

    function normalizeAreaKey(area){
        const numericArea = Number(area);
        return Number.isFinite(numericArea) ? String(Math.round(numericArea * 1000) / 1000) : '';
    }

    function getDependencyAreaLabelMap(activeCard){
        const select = activeCard && activeCard.querySelector('.js-display-presets');
        const map = {};
        Array.from(select ? select.options : []).forEach(option => {
            const area = parseAreaFromOption(option);
            const text = String(option.textContent || option.value || '').replace(/\s*\[[^\]]*]\s*$/, '').trim();
            const key = normalizeAreaKey(area);
            if (key && text) {
                map[key] = text;
            }
        });
        return map;
    }

    function getRowAreaBoundsMm(row){
        const from = parseNumber(row.querySelector('.js-area-from').value);
        const rawTo = row.querySelector('.js-area-to').value;
        const to = rawTo === '∞' ? Number.POSITIVE_INFINITY : parseNumber(rawTo);
        return {
            from: Number.isFinite(from) ? from * areaDisplayFactor : Number.NaN,
            to: to === Number.POSITIVE_INFINITY ? Number.POSITIVE_INFINITY : (Number.isFinite(to) ? to * areaDisplayFactor : Number.NaN)
        };
    }

    function getMatchingOfferVolumesForAreaRange(activeCard, row){
        const code = activeCard ? activeCard.dataset.propCode || '' : '';
        const bounds = getRowAreaBoundsMm(row);
        const volumes = new Set();
        if (!code || !Number.isFinite(bounds.from)) return volumes;
        offerCalcProps.forEach(offer => {
            const props = offer.properties || {};
            const dep = props[code] || {};
            const volume = props[requiredVolumeCode] || {};
            const areaFromXml = parseAreaFromToken(dep.xml_id);
            const area = Number.isFinite(areaFromXml) ? areaFromXml : parseAreaFromToken(dep.value);
            const volumeNumber = parseNumber(volume.value || volume.xml_id);
            if (Number.isFinite(area) && Number.isFinite(volumeNumber) && area >= bounds.from && area <= bounds.to) {
                volumes.add(String(volumeNumber));
            }
        });
        return volumes;
    }

    function getRangeHeaderParts(activeCard, row){
        const bounds = getRowAreaBoundsMm(row);
        if (!Number.isFinite(bounds.from)) return {title: 'Площадь', mark: ''};
        const formatLabelByArea = getDependencyAreaLabelMap(activeCard);
        const threshold = parseNumber(row.dataset.areaThreshold || '');
        const thresholdMm = Number.isFinite(threshold) ? threshold : bounds.from;
        const currentText = formatLabelByArea[normalizeAreaKey(thresholdMm)] || '';
        if (bounds.from <= 0 && Number.isFinite(bounds.to)) {
            const nextText = formatLabelByArea[normalizeAreaKey(bounds.to + 1)] || '';
            return {
                title: 'Площадь 0 — ' + String(bounds.to / areaDisplayFactor),
                mark: nextText ? '< ' + nextText : ''
            };
        }
        return {
            title: 'Площадь ≥ ' + String(bounds.from / areaDisplayFactor),
            mark: currentText ? '≥ ' + currentText : ''
        };
    }

    function getSavedReferenceVolumes(volumeCard){
        const wrap = volumeCard && volumeCard.querySelector('.js-reference-wrap');
        if (!wrap || !wrap.dataset.referenceVolumes) return {};
        try { return JSON.parse(wrap.dataset.referenceVolumes) || {}; } catch (e) { return {}; }
    }

    function clearSavedReferenceVolumes(volumeCard){
        const wrap = volumeCard && volumeCard.querySelector('.js-reference-wrap');
        if (wrap) wrap.dataset.referenceVolumes = '{}';
    }

    function renderReferenceAccordions(volumeCard){
        const wrap = volumeCard.querySelector('.js-reference-wrap');
        const baseList = volumeCard.querySelector('.js-reference-base');
        const areaRows = Array.from(volumeCard.querySelectorAll('.js-area-row'));
        if (!wrap || !baseList) return;
        wrap.querySelectorAll('.js-reference-area-accordions').forEach(node => node.remove());
        const activeCard = getAreaDependencyCard();
        baseList.style.display = activeCard ? 'none' : '';
        if (!activeCard) return;
        const holder = document.createElement('div');
        holder.className = 'js-reference-area-accordions';
        const savedReferenceVolumes = getSavedReferenceVolumes(volumeCard);
        const savedAreaVolumesByIndex = {};
        if (Array.isArray(savedReferenceVolumes.area)) {
            savedReferenceVolumes.area.forEach(item => { savedAreaVolumesByIndex[String(item.index)] = Array.isArray(item.volumes) ? item.volumes.map(String) : []; });
        }
        areaRows.forEach((row, idx) => {
            const acc = document.createElement('div');
            const matchingVolumes = getMatchingOfferVolumesForAreaRange(activeCard, row);
            const header = getRangeHeaderParts(activeCard, row);
            acc.className = 'fc-reference-accordion';
            acc.innerHTML = '<button type="button" class="fc-reference-accordion-head js-reference-accordion-toggle"><span>' + escapeHtml(header.title) + '</span><span class="fc-reference-accordion-mark">' + escapeHtml(header.mark) + '</span></button><div class="fc-reference-accordion-body"><div class="fc-reference-list"></div></div>';
            const list = acc.querySelector('.fc-reference-list');
            getAllowedReferenceValues(volumeCard, row).forEach(input => {
                const label = input.closest('label').cloneNode(true);
                const clonedInput = label.querySelector('input');
                clonedInput.className = 'js-reference-volume-area';
                clonedInput.name = 'area_ref_' + idx + '[]';
                const value = parseNumber(clonedInput.value);
                const rowMin = parseNumber(row.querySelector('.js-area-min').value);
                const rowMax = parseNumber(row.querySelector('.js-area-max').value);
                const isRequiredBoundary = value === rowMin || value === rowMax;
                const isRequiredOfferVolume = matchingVolumes.has(String(value));
                const isRequired = isRequiredBoundary || isRequiredOfferVolume;
                const savedVolumes = savedAreaVolumesByIndex[String(idx)];
                clonedInput.disabled = isRequired;
                clonedInput.dataset.required = isRequired ? '1' : '';
                clonedInput.checked = isRequired || (savedVolumes ? savedVolumes.indexOf(String(value)) !== -1 : false);
                list.appendChild(label);
            });
            holder.appendChild(acc);
        });
        wrap.appendChild(holder);
    }

    function collectAreaRanges(card){
        return Array.from(card.querySelectorAll('.js-area-row')).map(row => {
            const from = parseNumber(row.querySelector('.js-area-from').value);
            const rawTo = row.querySelector('.js-area-to').value;
            const to = rawTo === '∞' ? Number.POSITIVE_INFINITY : parseNumber(rawTo);
            return {
                area_from_mm2: Number.isFinite(from) ? from * areaDisplayFactor : '',
                area_to_mm2: to === Number.POSITIVE_INFINITY ? '' : (Number.isFinite(to) ? to * areaDisplayFactor : ''),
                min: row.querySelector('.js-area-min').value || '',
                max: row.querySelector('.js-area-max').value || '',
                step: row.querySelector('.js-area-step').value || ''
            };
        }).filter(row => row.area_from_mm2 !== '' || row.area_to_mm2 !== '' || row.min !== '' || row.max !== '' || row.step !== '');
    }

    function collectReferenceVolumes(card){
        const base = Array.from(card.querySelectorAll('.js-reference-volume')).filter(input => input.checked || input.disabled || input.dataset.required === '1').map(input => input.value);
        const accordions = Array.from(card.querySelectorAll('.fc-reference-accordion'));
        const rows = Array.from(card.querySelectorAll('.js-area-row'));
        const area = rows.map((row, idx) => {
            const acc = accordions[idx] || null;
            const inputs = acc ? Array.from(acc.querySelectorAll('.js-reference-volume-area')) : [];
            return {index: idx, volumes: inputs.filter(input => input.checked || input.disabled || input.dataset.required === '1').map(input => input.value)};
        });
        return {base: Array.from(new Set(base)), area: area};
    }

    function persistReferenceVolumes(card){
        const wrap = card && card.querySelector('.js-reference-wrap');
        if (wrap) {
            wrap.dataset.referenceVolumes = JSON.stringify(collectReferenceVolumes(card));
        }
    }

    if (root) {
        root.addEventListener('click', function(event){
            const removeProp = event.target.closest('.js-remove-prop');
            if (removeProp) {
                const card = removeProp.closest('.fc-card');
                if (card && card.dataset.propCode !== requiredVolumeCode) {
                    card.remove();
                    refreshAddSelect();
                    syncAreaDependency();
                }
                return;
            }

            const toggle = event.target.closest('.js-fc-toggle');
            if (toggle) {
                const card = toggle.closest('.fc-card');
                if (card) {
                    card.classList.toggle('open');
                }
                return;
            }

            const addInput = event.target.closest('.js-add-input');
            if (addInput) {
                const card = addInput.closest('.fc-card');
                const rows = card.querySelector('.js-fc-inputs');
                const row = card.querySelector('.js-fc-input-row');
                const clone = row.cloneNode(true);
                clone.querySelectorAll('input').forEach(inp => inp.value = '');
                rows.appendChild(clone);
                syncGroup(card);
                return;
            }

            const removeInput = event.target.closest('.js-remove-input');
            if (removeInput) {
                const card = removeInput.closest('.fc-card');
                const row = removeInput.closest('.js-fc-input-row');
                if (card && row) {
                    row.remove();
                    syncGroup(card);
                }
                return;
            }

            const addArea = event.target.closest('.js-add-area-row');
            if (addArea) {
                const card = addArea.closest('.fc-card');
                const container = card.querySelector('.js-area-ranges');
                const row = cloneAreaRow(card);
                if (row && container) {
                    const currentRow = addArea.closest('.js-area-row');
                    const currentFrom = currentRow ? parseNumber(currentRow.querySelector('.js-area-from').value) : 0;
                    row.querySelector('.js-area-from').value = Number.isFinite(currentFrom) ? currentFrom + 1 : '';
                    row.querySelector('.js-area-min').value = getBaseVolumeInputValues(card).min;
                    row.querySelector('.js-area-max').value = getBaseVolumeInputValues(card).max;
                    row.querySelector('.js-area-step').value = getBaseVolumeInputValues(card).step;
                    if (currentRow && currentRow.nextSibling) { container.insertBefore(row, currentRow.nextSibling); } else { container.appendChild(row); }
                    container.dataset.autoBuilt = '0';
                    syncAreaRows(card);
                }
                return;
            }

            const resetArea = event.target.closest('.js-reset-area-ranges');
            if (resetArea) {
                const card = resetArea.closest('.fc-card');
                const depCard = getAreaDependencyCard();
                const container = card ? card.querySelector('.js-area-ranges') : null;
                if (container) container.dataset.autoBuilt = '1';
                if (depCard && card) ensureAreaRowsFromDependency(depCard, card);
                if (card) syncAreaRows(card);
                return;
            }

            const resetReference = event.target.closest('.js-reset-reference-volumes');
            if (resetReference) {
                const card = resetReference.closest('.fc-card');
                clearSavedReferenceVolumes(card);
                if (card) {
                    card.querySelectorAll('.js-reference-volume').forEach(input => {
                        input.checked = input.dataset.required === '1' || input.disabled;
                    });
                    renderReferenceAccordions(card);
                    const wrap = card.querySelector('.js-reference-wrap');
                    if (wrap) wrap.dataset.referenceVolumes = JSON.stringify(collectReferenceVolumes(card));
                }
                return;
            }

            const accToggle = event.target.closest('.js-reference-accordion-toggle');
            if (accToggle) {
                accToggle.closest('.fc-reference-accordion').classList.toggle('open');
                return;
            }

            const deadlineToggle = event.target.closest('.js-deadline-accordion-toggle');
            if (deadlineToggle) {
                const accordion = deadlineToggle.closest('.fc-deadline-accordion');
                const isOpen = accordion.classList.toggle('open');
                deadlineToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                return;
            }

            const removeArea = event.target.closest('.js-remove-area-row');
            if (removeArea) {
                const card = removeArea.closest('.fc-card');
                const row = removeArea.closest('.js-area-row');
                if (row) row.remove();
                const container = card.querySelector('.js-area-ranges');
                if (container) container.dataset.autoBuilt = '0';
                syncAreaRows(card);
                return;
            }

        });
    }

    if (addBtn) {
        addBtn.addEventListener('click', function(){
            const code = addSelect.value;
            if (!code) {
                return;
            }
            const card = createCard(code);
            if (!card) {
                return;
            }
            root.appendChild(card);
            syncDisplayMode(card);
            refreshAddSelect();
            syncAreaDependency();
        });
    }

    if (root) {
        root.addEventListener('change', function(event){
            if (event.target && (event.target.classList.contains('js-use-area-dependency') || event.target.classList.contains('js-display-presets'))) {
                syncAreaDependency();
            }
            if (event.target && (event.target.classList.contains('js-reference-volume') || event.target.classList.contains('js-reference-volume-area'))) {
                persistReferenceVolumes(event.target.closest('.fc-card'));
            }
            if (event.target && event.target.classList.contains('js-deadline-mode')) {
                const card = event.target.closest('.fc-card');
                card.querySelectorAll('.js-deadline-simple').forEach(el => el.style.display = event.target.value === 'advanced' ? 'none' : '');
                const advanced = card.querySelector('.js-deadline-advanced');
                if (advanced) advanced.style.display = event.target.value === 'advanced' ? 'block' : 'none';
            }
            if (event.target && event.target.classList.contains('js-display-mode')) {
                syncDisplayMode(event.target.closest('.fc-card'));
            }
            syncSchemaInput();
        });

        root.addEventListener('input', function(event){
            if (event.target && event.target.matches('input,select,textarea')) {
                syncSchemaInput();
            }
        });
    }

    if (root) {
        root.addEventListener('focusout', function(event){
            if (event.target && (event.target.classList.contains('js-area-from') || event.target.classList.contains('js-area-min') || event.target.classList.contains('js-area-max'))) {
                const container = event.target.closest('.js-area-ranges');
                if (container) container.dataset.autoBuilt = '0';
                syncAreaRows(event.target.closest('.fc-card'));
            }
        });
    }

    function collectDeadlineAdjustments(card){
        if ((card.dataset.propCode || '') !== requiredVolumeCode) return undefined;
        const mode = card.querySelector('.js-deadline-mode') ? card.querySelector('.js-deadline-mode').value : 'simple';
        const result = {
            mode: mode,
            urgent_markup: card.querySelector('.js-urgent-markup') ? card.querySelector('.js-urgent-markup').value.trim() : '',
            flexible_discount: card.querySelector('.js-flexible-discount') ? card.querySelector('.js-flexible-discount').value.trim() : '',
            advanced: {urgent_markup: [], flexible_discount: []}
        };
        card.querySelectorAll('.js-deadline-row').forEach(row => {
            const volume = row.dataset.volume || '';
            const urgent = row.querySelector('.js-adv-urgent') ? row.querySelector('.js-adv-urgent').value.trim() : '';
            const flexible = row.querySelector('.js-adv-flexible') ? row.querySelector('.js-adv-flexible').value.trim() : '';
            if (volume && urgent !== '') result.advanced.urgent_markup.push({volume: volume, percent: urgent});
            if (volume && flexible !== '') result.advanced.flexible_discount.push({volume: volume, percent: flexible});
        });
        return result;
    }

    function collectEditorSchema(){
        const fields = [];
        document.querySelectorAll('.fc-card').forEach(card => {
                const inputs = getRows(card).map(row => ({
                    code: row.querySelector('.js-inp-code').value || '',
                    min: row.querySelector('.js-inp-min').value || '',
                    max: row.querySelector('.js-inp-max').value || '',
                    step: row.querySelector('.js-inp-step').value || '',
                    unit: row.querySelector('.js-inp-unit').value || '',
                    show_unit: row.querySelector('.js-inp-show-unit') ? row.querySelector('.js-inp-show-unit').checked : true,
                }));
                const displaySelect = card.querySelector('.js-display-presets');
                const displayPresetXmlIds = displaySelect ? Array.from(displaySelect.selectedOptions).map(opt => opt.value) : [];

                fields.push({
                    property_code: card.dataset.propCode || '',
                    open_popup_chip_label: card.querySelector('.js-open-popup-chip-label') ? card.querySelector('.js-open-popup-chip-label').value.trim() : '',
                    inputs: inputs,
                    show_presets: card.querySelector('.js-show-presets') ? card.querySelector('.js-show-presets').checked : false,
                    show_unit: inputs.some(input => input.show_unit),
                    is_group: inputs.length > 1,
                    group_code: card.querySelector('.js-group-code').value || '',
                    group_delimiter: card.querySelector('.js-group-delimiter').value || 'x',
                    display_preset_xml_ids: displayPresetXmlIds,
                    display_mode: card.querySelector('.js-display-mode') ? card.querySelector('.js-display-mode').value : 'input_presets',
                    deadline_adjustments: collectDeadlineAdjustments(card),
                    use_for_area_dependency: card.querySelector('.js-use-area-dependency') ? card.querySelector('.js-use-area-dependency').checked : false,
                    area_ranges: (card.dataset.propCode || '') === requiredVolumeCode ? collectAreaRanges(card) : undefined,
                    reference_volumes: (card.dataset.propCode || '') === requiredVolumeCode ? collectReferenceVolumes(card) : undefined
                });
        });
        return {version: 1, fields: fields};
    }

    function syncSchemaInput(){
        if (schemaInput) {
            schemaInput.value = JSON.stringify(collectEditorSchema());
        }
    }

    if (form) {
        form.addEventListener('submit', syncSchemaInput, true);
        form.addEventListener('click', function(event){
            if (event.target && event.target.matches('input[type="submit"],button[type="submit"]')) {
                syncSchemaInput();
            }
        }, true);
    }

    document.querySelectorAll('.fc-card').forEach(function(card){ syncGroup(card); syncDisplayMode(card); });
    refreshAddSelect();
    syncAreaDependency();
    const initialVolumeCard = document.querySelector('.fc-card[data-prop-code="' + requiredVolumeCode + '"]');
    const initialDependencyCard = getAreaDependencyCard();
    if (initialVolumeCard && initialDependencyCard) {
        ensureAreaRowsFromDependency(initialDependencyCard, initialVolumeCard);
        renderReferenceAccordions(initialVolumeCard);
    }
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
