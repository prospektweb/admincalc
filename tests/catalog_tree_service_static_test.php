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

if (!str_contains($dispatcher, '$replaceCustomFields') || !str_contains($dispatcher, '? $customFieldIds')) {
    throw new RuntimeException('Exact custom-field replacement contract is missing');
}

echo "Catalog tree static contract: OK\n";
