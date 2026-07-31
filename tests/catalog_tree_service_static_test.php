<?php

declare(strict_types=1);

$service = file_get_contents(__DIR__ . '/../lib/Services/CatalogTreeService.php');
$dispatcher = file_get_contents(__DIR__ . '/../lib/Calculator/ElementDataService.php');
$bridge = file_get_contents(__DIR__ . '/../install/assets/js/integration.js');

foreach ([
    'class CatalogTreeService',
    "['IBLOCK_ID' => \$iblockId]",
    'buildTree($sections, $elements)',
    'saveCustomFieldProperties',
    'normalizeCustomFieldType',
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
