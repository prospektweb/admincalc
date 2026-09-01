<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Install/SupplierDirectorySchemaService.php';

use Prospektweb\Calc\Install\SupplierDirectorySchemaService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$targets = [
    'CALC_MATERIALS' => [
        'ID' => 44,
        'IBLOCK_TYPE_ID' => 'calculator_catalog',
        'CODE' => 'CALC_MATERIALS',
        'XML_ID' => '',
        'NAME' => 'Материалы',
        'VERSION' => 2,
        'ACTIVE' => 'Y',
    ],
    'CALC_MATERIALS_VARIANTS' => [
        'ID' => 45,
        'IBLOCK_TYPE_ID' => 'calculator',
        'CODE' => 'CALC_MATERIALS_VARIANTS',
        'XML_ID' => '',
        'NAME' => 'Варианты материалов',
        'VERSION' => 2,
        'ACTIVE' => 'Y',
    ],
];
$freshState = [
    'configuredSupplierId' => 0,
    'targets' => $targets,
    'supplierCandidates' => [],
    'properties' => [
        'CALC_MATERIALS' => [],
        'CALC_MATERIALS_VARIANTS' => [],
        'CALC_SUPPLIERS' => [],
    ],
    'counts' => ['suppliers' => 0, 'materialLinks' => 0, 'variantLinks' => 0],
];
$fresh = (new SupplierDirectorySchemaService([
    'state' => static fn(): array => $freshState,
]))->analyze();
$assert($fresh['blockers'] === [], 'fresh exact material targets are accepted');
$assert(
    in_array(['action' => 'create_iblock', 'code' => 'CALC_SUPPLIERS'], $fresh['operations'], true),
    'fresh plan creates the private supplier iblock'
);
$assert(
    count(array_filter(
        $fresh['operations'],
        static fn(array $operation): bool => ($operation['action'] ?? '') === 'update_iblock_type'
            && ($operation['iblock'] ?? '') === 'CALC_MATERIALS_VARIANTS'
            && ($operation['iblockId'] ?? 0) === 45
            && ($operation['from'] ?? '') === 'calculator'
            && ($operation['to'] ?? '') === 'calculator_catalog'
    )) === 1,
    'legacy material variants type is repaired in place without changing its ID'
);
$assert(
    count(array_filter(
        $fresh['operations'],
        static fn(array $operation): bool => ($operation['action'] ?? '') === 'create_property'
    )) === 12,
    'fresh plan creates eight supplier fields and two fields on each material iblock'
);

$supplierId = 77;
$propertyRow = static function (string $code, array $definition, int $id) use ($supplierId): array {
    $values = [];
    foreach ($definition['VALUES'] ?? [] as $item) {
        $values[(string)$item['XML_ID']] = [
            'VALUE' => (string)$item['VALUE'],
            'SORT' => (int)$item['SORT'],
            'DEF' => (string)$item['DEF'],
        ];
    }
    return [
        'ID' => $id,
        'CODE' => $code,
        'NAME' => (string)$definition['NAME'],
        'PROPERTY_TYPE' => (string)$definition['PROPERTY_TYPE'],
        'USER_TYPE' => (string)($definition['USER_TYPE'] ?? ''),
        'MULTIPLE' => (string)$definition['MULTIPLE'],
        'IS_REQUIRED' => (string)$definition['IS_REQUIRED'],
        'SORT' => (int)$definition['SORT'],
        'LINK_IBLOCK_ID' => (int)($definition['LINK_IBLOCK_ID'] ?? 0),
        'WITH_DESCRIPTION' => (string)($definition['WITH_DESCRIPTION'] ?? 'N'),
        'SEARCHABLE' => (string)($definition['SEARCHABLE'] ?? 'N'),
        'FILTRABLE' => (string)($definition['FILTRABLE'] ?? 'N'),
    ] + ($values !== [] ? ['VALUES' => $values] : []);
};

$supplierProperties = [];
$id = 100;
foreach (SupplierDirectorySchemaService::supplierPropertySchema() as $code => $definition) {
    $supplierProperties[$code] = $propertyRow($code, $definition, $id++);
}
$materialProperties = [];
$materialSchema = SupplierDirectorySchemaService::materialPropertySchema();
$materialSchema['SUPPLIERS']['LINK_IBLOCK_ID'] = $supplierId;
foreach ($materialSchema as $code => $definition) {
    $materialProperties[$code] = $propertyRow($code, $definition, $id++);
}

$completeState = [
    'configuredSupplierId' => $supplierId,
    'targets' => $targets,
    'supplierCandidates' => [[
        'ID' => $supplierId,
        'IBLOCK_TYPE_ID' => SupplierDirectorySchemaService::IBLOCK_TYPE,
        'CODE' => SupplierDirectorySchemaService::IBLOCK_CODE,
        'XML_ID' => SupplierDirectorySchemaService::IBLOCK_XML_ID,
        'NAME' => 'Поставщики материалов',
        'VERSION' => 2,
        'ACTIVE' => 'Y',
    ]],
    'properties' => [
        'CALC_MATERIALS' => $materialProperties,
        'CALC_MATERIALS_VARIANTS' => $materialProperties,
        'CALC_SUPPLIERS' => $supplierProperties,
    ],
    'counts' => ['suppliers' => 0, 'materialLinks' => 0, 'variantLinks' => 0],
];
$completeState['targets']['CALC_MATERIALS_VARIANTS']['IBLOCK_TYPE_ID'] = SupplierDirectorySchemaService::IBLOCK_TYPE;
$completeState['targetCandidates'] = [
    'CALC_MATERIALS' => [$completeState['targets']['CALC_MATERIALS']],
    'CALC_MATERIALS_VARIANTS' => [$completeState['targets']['CALC_MATERIALS_VARIANTS']],
];
$complete = (new SupplierDirectorySchemaService([
    'state' => static fn(): array => $completeState,
]))->analyze();
$assert($complete['operations'] === [] && $complete['blockers'] === [], 'repeat analyze has zero diff');

$wrongLinkState = $completeState;
$wrongLinkState['properties']['CALC_MATERIALS']['SUPPLIERS']['LINK_IBLOCK_ID'] = 88;
$wrong = (new SupplierDirectorySchemaService([
    'state' => static fn(): array => $wrongLinkState,
]))->analyze();
$assert(
    count(array_filter($wrong['blockers'], static fn(string $value): bool => str_contains($value, 'LINK_IBLOCK_ID'))) === 1,
    'wrong supplier link target fails closed without rewriting it'
);

$source = (string)file_get_contents(dirname(__DIR__) . '/lib/Install/SupplierDirectorySchemaService.php');
$installer = (string)file_get_contents(dirname(__DIR__) . '/install/step3.php');
$assert(
    preg_match('/CIBlockElement\s*::\s*Add|new\s+\\?CIBlockElement\b[\s\S]{0,300}->Add\s*\(/', $source) !== 1,
    'supplier schema service never creates business elements'
);
$assert(
    str_contains($installer, 'SupplierDirectorySchemaService')
        && str_contains($installer, "['iblock_ids']['CALC_SUPPLIERS']")
        && str_contains($installer, '$expected = 12;'),
    'fresh calc installer invokes the same supplier schema service'
);
$assert(
    str_contains($installer, "['CODE' => \$code, 'CHECK_PERMISSIONS' => 'N']")
        && !str_contains($installer, "['CODE' => \$code, 'TYPE' => \$typeId]")
        && str_contains($installer, 'count($iblockCandidates) > 1')
        && str_contains($installer, "'IBLOCK_TYPE_ID' => \$typeId"),
    'installer adopts a unique stable CODE, rejects duplicates and reconciles the expected type'
);
$duplicateState = $completeState;
$duplicateState['targetCandidates']['CALC_MATERIALS_VARIANTS'][] = array_merge(
    $completeState['targets']['CALC_MATERIALS_VARIANTS'],
    ['ID' => 145]
);
$duplicate = (new SupplierDirectorySchemaService([
    'state' => static fn(): array => $duplicateState,
]))->analyze();
$assert(
    count(array_filter($duplicate['blockers'], static fn(string $value): bool => str_contains($value, 'дубли'))) === 1,
    'duplicate stable material iblock code fails closed'
);
$assert(
    str_contains($source, 'writeCanonicalRuntimeOption')
        && str_contains($source, "UPDATE b_option SET MODULE_ID='")
        && !str_contains($source, 'Option::set(self::MODULE_ID, self::OPTION_NAME'),
    'supplier runtime option is written with binary-exact canonical identity'
);

echo "supplier_directory_schema_service_test: OK\n";
