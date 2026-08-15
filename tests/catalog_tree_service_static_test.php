<?php

declare(strict_types=1);

$service = file_get_contents(__DIR__ . '/../lib/Services/CatalogTreeService.php');
$batchService = file_get_contents(__DIR__ . '/../lib/Services/BatchRecalculateService.php');
$dispatcher = file_get_contents(__DIR__ . '/../lib/Calculator/ElementDataService.php');
$bridge = file_get_contents(__DIR__ . '/../install/assets/js/integration.js');

foreach ([
    'class CatalogTreeService',
    "['IBLOCK_ID' => \$iblockId]",
    'buildTree($sections, $elements)',
    'saveCustomFieldProperties',
    'normalizeCustomFieldType',
    'normalizeCustomFieldCode',
    "(string)\$iblock['CODE'] === 'CALC_CUSTOM_FIELDS'",
    "'replace_space' => '_'",
    "'replace_other' => '_'",
    "'change_case' => 'U'",
    "preg_match('/^[A-Za-z_]/'",
    "\$tail = '_' . \$suffix++",
    "['number', 'text', 'checkbox', 'select']",
    "VALUE_ENUM",
    "preg_match('/\\((number|text|checkbox|select)\\)/'",
    'Сначала удалите или переместите вложенные разделы и элементы',
] as $needle) {
    if (!str_contains($service, $needle)) {
        throw new RuntimeException("CatalogTreeService contract missing: {$needle}");
    }
}

foreach (['getCatalogTree', 'saveCatalogTreeElement', 'saveCatalogTreeSection', 'deleteCatalogTreeNode'] as $action) {
    if (!str_contains($dispatcher, $action) || !str_contains($bridge, $action)) {
        throw new RuntimeException("Catalog tree action is not wired end-to-end: {$action}");
    }
}

if (substr_count($batchService, "'ACTIVE_DATE' => 'Y'") < 1) {
    throw new RuntimeException('Preset product allowlist must reject expired and future-dated products');
}
if (substr_count($service, "'ACTIVE_DATE' => 'Y'") < 1) {
    throw new RuntimeException('Preset offer catalog must reject expired and future-dated offers');
}
if (!str_contains($service, 'StandaloneCatalogSelectionMapper::supportedProductIds()')) {
    throw new RuntimeException('Preset 12740 authoring catalog must retain the fixed prepared-product allowlist');
}
if (!str_contains($batchService, '$this->catalogAdapterService->supportedProductIds()')) {
    throw new RuntimeException('Batch calculation must retain the current persisted adapter scope');
}

foreach (['getPresetLoadOptions', 'PRESET_LOAD_OPTIONS_RESPONSE', 'presetLoadOptions'] as $action) {
    if (!str_contains($dispatcher . $bridge . $service, $action)) {
        throw new RuntimeException("Preset loading action is not wired end-to-end: {$action}");
    }
}

if (!str_contains($dispatcher, '$replaceCustomFields') || !str_contains($dispatcher, '? $customFieldIds')) {
    throw new RuntimeException('Exact custom-field replacement contract is missing');
}

foreach ([$service, $dispatcher] as $enumWriter) {
    if (!str_contains($enumWriter, "CIBlockPropertyEnum::GetList(['SORT' => 'ASC', 'ID' => 'ASC']")) {
        throw new RuntimeException('Custom-field types must be resolved by their exact XML_ID');
    }
    if (!str_contains($enumWriter, "(string)(\$enum['XML_ID'] ?? '') === \$xmlId")) {
        throw new RuntimeException('Custom-field enum lookup must not coerce one type into another');
    }
}

echo "Catalog tree static contract: OK\n";
