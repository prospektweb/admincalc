<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/FormFirstDependencyContractService.php';

use Prospektweb\Calc\Services\FormFirstDependencyContractService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$categories = [
    'ui',
    'catalog_input_mapping',
    'stage_inputs',
    'globals',
    'options_mappings',
    'basket',
    'storefront_presentation',
];
$dependencyLoader = static function (int $presetId) use ($categories): array {
    $status = [];
    foreach ($categories as $category) {
        $status[$category] = [
            'scanned' => true,
            'sourceMode' => in_array($category, ['basket', 'catalog_input_mapping', 'storefront_presentation'], true)
                ? 'declared'
                : 'discovered',
        ];
    }
    return [
        'presetId' => $presetId,
        'consumers' => [[
            'propertyCode' => 'CALC_PROP_METHOD',
            'category' => 'catalog_input_mapping',
            'source' => 'prospektweb.calc.calculator-input-mapping/v1',
            'path' => 'calculator_input_mapping.mappings.0.target.field_id',
            'provenance' => 'declared',
        ], [
            'propertyCode' => 'CALC_PROP_METHOD',
            'category' => 'storefront_presentation',
            'source' => 'prospektweb.frontcalc.storefront-definition/v2',
            'path' => 'storefront.main.presentation.field_patches.method',
            'provenance' => 'declared',
        ]],
        'categoryStatus' => $status,
        'unresolvedSources' => [],
    ];
};
$fieldReferenceLoader = static function (int $presetId): array {
    return [[
        'fieldId' => 'method',
        'category' => 'catalog_input_mapping',
        'source' => 'prospektweb.calc.calculator-input-mapping/v1',
        'path' => 'calculator_input_mapping.mappings.0.target.field_id',
        'provenance' => 'declared',
    ], [
        'fieldId' => 'method',
        'category' => 'storefront_presentation',
        'source' => 'prospektweb.frontcalc.storefront-definition/v2',
        'path' => 'storefront.main.presentation.field_patches.method',
        'provenance' => 'declared',
    ], [
        'fieldId' => 'quantity',
        'category' => 'catalog_input_mapping',
        'source' => 'prospektweb.calc.calculator-input-mapping/v1',
        'path' => 'calculator_input_mapping.mappings.1.target.field_id',
        'provenance' => 'declared',
    ]];
};

$service = new FormFirstDependencyContractService($dependencyLoader, $fieldReferenceLoader);
$contract = $service->buildPublicInputContract(41);
$assert(
    array_keys($contract['categoryStatus'] ?? []) === $categories,
    'The dependency contract exposes the exact seven clean categories'
);
$assert(
    array_column($contract['consumers'] ?? [], 'category') === [
        'catalog_input_mapping',
        'storefront_presentation',
    ],
    'Input mappings and storefront patches are first-class dependency consumers'
);
$assert(
    ($contract['requiredPropertyCodes'] ?? null) === [],
    'Optional prefill and presentation references must not make manual calculator inputs required'
);
$assert(
    preg_match('/^[a-f0-9]{64}$/D', (string)($contract['fingerprint'] ?? '')) === 1,
    'The clean dependency contract is fingerprinted'
);

$references = $service->fieldReferences(41, 'method');
$assert(
    count($references) === 2
        && array_column($references, 'category') === [
            'catalog_input_mapping',
            'storefront_presentation',
        ],
    'Field deletion can resolve exact mapping and storefront blockers by field ID'
);

$invalidService = new FormFirstDependencyContractService(
    $dependencyLoader,
    static fn(int $presetId): array => [[
        'fieldId' => 'method',
        'category' => 'passive_context',
        'source' => 'legacy',
        'path' => 'legacy.seedPropertyCode',
        'provenance' => 'declared',
    ]]
);
try {
    $invalidService->fieldReferences(41, 'method');
    throw new RuntimeException('Legacy field reference category was accepted');
} catch (RuntimeException $error) {
    $assert(
        $error->getMessage() !== 'Legacy field reference category was accepted',
        'Legacy passive-context references are rejected'
    );
}

echo "Form-first dependency contract service tests passed\n";
