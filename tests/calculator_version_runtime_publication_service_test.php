<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionBundleDocumentService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionSnapshotSourceService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorInputMappingService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionComponentDocumentService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionRuntimePublicationService.php';

use Prospektweb\Calc\Services\CalculatorVersionBundleDocumentService;
use Prospektweb\Calc\Services\CalculatorInputMappingService;
use Prospektweb\Calc\Services\CalculatorVersionRuntimePublicationService;
use Prospektweb\Calc\Services\CalculatorVersionSnapshotSourceService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$bundleStorage = [];
$pointerStorage = [];
$bundles = new CalculatorVersionBundleDocumentService([
    'get' => static fn(string $name): string => (string)($GLOBALS['runtime_bundle_storage'][$name] ?? ''),
    'set' => static function (string $name, string $value): void { $GLOBALS['runtime_bundle_storage'][$name] = $value; },
    'delete' => static function (string $name): void { unset($GLOBALS['runtime_bundle_storage'][$name]); },
    'now' => static fn(): string => '2026-08-27T09:00:00+05:00',
]);
$GLOBALS['runtime_bundle_storage'] = &$bundleStorage;
$documents = [];
foreach (CalculatorVersionBundleDocumentService::COMPONENTS as $component) {
    $documents[$component] = ['contract' => 'test/' . $component, 'presetId' => 12740];
}
$documents['logic'] = [
    'contract' => CalculatorVersionSnapshotSourceService::LOGIC_CONTRACT,
    'presetId' => 12740,
    'graph' => [],
    'elements' => [],
    'runtimePayload' => [
        'contract' => CalculatorVersionSnapshotSourceService::LOGIC_RUNTIME_CONTRACT,
        'preset' => ['id' => 12740, 'runtimePresetId' => 54321],
        'elementsStore' => [],
        'elementsSiblings' => [],
        'globalSymbols' => [],
        'priceTypes' => [],
        'selectedOffers' => [],
        'product' => null,
        'neutralInputRequired' => true,
        'runtimeConfigSnapshot' => ['CALC_PRESETS' => '41'],
    ],
];
$documents['publicationMetadata'] = [
    'contract' => CalculatorVersionSnapshotSourceService::PUBLICATION_METADATA_CONTRACT,
    'presetId' => 12740,
    'calculatorName' => 'Листовая печать',
];
$documents['form'] = [
    'contract' => 'prospektweb.calc.calculator-version-form/v1',
    'formDefinition' => [
        'contract' => 'prospektweb.frontcalc.form-definition/v1',
        'fields' => [[
            'fieldId' => 'volume',
            'type' => 'number',
            'options' => [],
            'dimensionInputs' => [],
        ]],
    ],
    'bindingDefinition' => [
        'contract' => 'prospektweb.frontcalc.binding-definition/v1',
        'bindings' => [['fieldId' => 'volume', 'valueMode' => 'scalar']],
    ],
];
$documents['inputMappings'] = [
    'contract' => CalculatorInputMappingService::CONTRACT,
    'preset_id' => 12740,
    'revision' => 0,
    'mappings' => [],
];
$documents['commercialPolicy'] = CalculatorVersionSnapshotSourceService::defaultCommercialPolicy(12740);
$bundles->save(12740, 'v_4444444444444444', $documents);

$inputMappings = new CalculatorInputMappingService([
    'source_authority' => static fn(int $presetId): array => [
        'product_iblock_id' => 14,
        'offer_iblock_id' => 15,
        'properties' => [
            'product' => [
                14 => [
                    301 => [
                        'scope' => 'product',
                        'code' => 'CALC_PROP_VOLUME',
                        'active' => true,
                        'property_type' => 'N',
                        'multiple' => false,
                        'enum_xml_ids' => [],
                    ],
                ],
            ],
            'selected_offer' => [],
        ],
    ],
]);
$service = new CalculatorVersionRuntimePublicationService($bundles, [
    'get' => static fn(string $name): string => (string)($GLOBALS['runtime_pointer_storage'][$name] ?? ''),
    'set' => static function (string $name, string $value): void { $GLOBALS['runtime_pointer_storage'][$name] = $value; },
    'now' => static fn(): string => '2026-08-27T09:01:00+05:00',
], $inputMappings);
$GLOBALS['runtime_pointer_storage'] = &$pointerStorage;
$active = $service->activate(12740, 'v_4444444444444444');
$assert($active['calculatorName'] === 'Листовая печать', 'active pointer must use version metadata name');
$assert(($active['readiness']['complete'] ?? false) === true, 'active pointer must expose only a complete bundle');
$readiness = $service->readiness(12740);
$assert($readiness['ready'] === true && $readiness['versionId'] === 'v_4444444444444444', 'readiness must pin the exact version');

$invalidPolicyDocuments = $documents;
$invalidPolicyDocuments['commercialPolicy']['deadlinePolicy']['basic']['urgent']['effortPercent'] = -1;
$bundles->save(12740, 'v_5555555555555555', $invalidPolicyDocuments);
$invalidPolicyRejected = false;
try {
    $service->activate(12740, 'v_5555555555555555');
} catch (RuntimeException $error) {
    $invalidPolicyRejected = $error->getCode() === 409
        && str_contains($error->getMessage(), 'Исправьте вкладку «Сроки»');
}
$assert($invalidPolicyRejected, 'activation must reject an invalid deadline policy with an actionable editor destination');

$orphanMappingDocuments = $documents;
$orphanMappingDocuments['inputMappings']['mappings'][] = [
    'target' => ['field_id' => 'missing-field'],
    'source' => [
        'scope' => 'product',
        'iblock_id' => 14,
        'property_id' => 301,
        'property_code' => 'CALC_PROP_VOLUME',
    ],
    'value_mode' => 'scalar',
];
$bundles->save(12740, 'v_7777777777777777', $orphanMappingDocuments);
$orphanMappingRejected = false;
try {
    $service->activate(12740, 'v_7777777777777777');
} catch (RuntimeException $error) {
    $orphanMappingRejected = $error->getCode() === 409
        && str_contains($error->getMessage(), 'вкладку «Сопоставления»');
}
$assert($orphanMappingRejected, 'activation must reject input mappings that target a field outside the same bundle');

$invalidLogicDocuments = $documents;
unset($invalidLogicDocuments['logic']['runtimePayload']);
$bundles->save(12740, 'v_6666666666666666', $invalidLogicDocuments);
$invalidLogicRejected = false;
try {
    $service->activate(12740, 'v_6666666666666666');
} catch (RuntimeException $error) {
    $invalidLogicRejected = $error->getCode() === 409
        && str_contains($error->getMessage(), 'вкладку «Логика»');
}
$assert($invalidLogicRejected, 'activation must reject a logic snapshot that depends on a live working preset');

$documents['logic']['changed'] = true;
$bundles->save(12740, 'v_4444444444444444', $documents);
$assert($service->readiness(12740)['ready'] === false, 'a mutated bundle must invalidate the active pointer');

echo "Calculator version runtime publication service tests passed\n";
