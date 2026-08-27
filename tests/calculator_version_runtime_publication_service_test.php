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
$formCompileHash = str_repeat('c', 64);
$legacyFormPublication = [
    'authoring' => [
        'formDefinition' => $documents['form']['formDefinition'],
        'bindingDefinition' => $documents['form']['bindingDefinition'],
        'publication' => ['revision' => 1, 'compileHash' => $formCompileHash],
    ],
    'snapshot' => [
        'version' => 2,
        'fields' => [],
        '_form_first' => ['publishedRevision' => 1, 'compileHash' => $formCompileHash],
    ],
];
$GLOBALS['runtime_pointer_storage'] = &$pointerStorage;
$GLOBALS['runtime_form_publication'] = &$legacyFormPublication;
$GLOBALS['runtime_legacy_form_reads'] = 0;
$service = new CalculatorVersionRuntimePublicationService($bundles, [
    'get' => static fn(string $name): string => (string)($GLOBALS['runtime_pointer_storage'][$name] ?? ''),
    'set' => static function (string $name, string $value): void { $GLOBALS['runtime_pointer_storage'][$name] = $value; },
    'lock' => static fn(int $_presetId, callable $callback) => $callback(),
    'legacy_form_publication' => static function (int $_presetId): array {
        $GLOBALS['runtime_legacy_form_reads']++;
        return $GLOBALS['runtime_form_publication'];
    },
    'now' => static fn(): string => '2026-08-27T09:01:00+05:00',
], $inputMappings);
$active = $service->activate(12740, 'v_4444444444444444');
$assert($active['calculatorName'] === 'Листовая печать', 'active pointer must use version metadata name');
$assert(($active['readiness']['complete'] ?? false) === true, 'active pointer must expose only a complete bundle');
$assert(preg_match('/^a_[a-f0-9]{32}$/D', (string)($active['activationId'] ?? '')) === 1, 'activation must identify an immutable snapshot');
$assert(($active['sourceContentHash'] ?? null) !== ($active['contentHash'] ?? null), 'deployment hash must include embedded form runtime');
$assert(($active['documents']['form']['runtimePublication']['snapshot']['_form_first']['compileHash'] ?? null) === $formCompileHash, 'immutable bundle must contain the exact form runtime');
$exactCompileHash = str_repeat('e', 64);
$exactSnapshot = [
    'version' => 2,
    'fields' => [['property_code' => 'CALC_PROP_VOLUME']],
    '_form_first' => ['publishedRevision' => 1, 'compileHash' => $exactCompileHash],
];
$bundles->save(12740, 'v_8888888888888888', $documents);
$legacyReadsBeforeExactActivation = $GLOBALS['runtime_legacy_form_reads'];
$exactActive = $service->activate(12740, 'v_8888888888888888', [
    'contract' => CalculatorVersionRuntimePublicationService::FORM_RUNTIME_CONTRACT,
    'publication' => ['revision' => 1, 'compileHash' => $exactCompileHash],
    'snapshot' => $exactSnapshot,
]);
$assert(
    $GLOBALS['runtime_legacy_form_reads'] === $legacyReadsBeforeExactActivation
        && ($exactActive['documents']['form']['runtimePublication']['snapshot']['fields'][0]['property_code'] ?? '') === 'CALC_PROP_VOLUME'
        && ($exactActive['documents']['form']['runtimePublication']['snapshot']['_form_first']['compileHash'] ?? '') === $exactCompileHash,
    'exact version activation must materialize its supplied form runtime without reading the legacy active form'
);
$active = $service->activate(12740, 'v_4444444444444444');
$readiness = $service->readiness(12740);
$assert($readiness['ready'] === true && $readiness['versionId'] === 'v_4444444444444444', 'readiness must pin the exact version');

$invalidPolicyDocuments = $documents;
$invalidPolicyDocuments['commercialPolicy']['deadlinePolicy']['basic']['urgent']['effortPercent'] = -1;
$bundles->save(12740, 'v_5555555555555555', $invalidPolicyDocuments);
$pointerBeforeInvalidActivation = $pointerStorage['CALC_ACTIVE_BUNDLE_12740'];
$invalidPolicyRejected = false;
try {
    $service->activate(12740, 'v_5555555555555555');
} catch (RuntimeException $error) {
    $invalidPolicyRejected = $error->getCode() === 409
        && str_contains($error->getMessage(), 'Исправьте вкладку «Сроки»');
}
$assert($invalidPolicyRejected, 'activation must reject an invalid deadline policy with an actionable editor destination');
$assert($pointerStorage['CALC_ACTIVE_BUNDLE_12740'] === $pointerBeforeInvalidActivation, 'failed activation must preserve the previous public pointer');

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
$stillActive = $service->resolve(12740);
$assert($service->readiness(12740)['ready'] === true, 'editing active work must not invalidate the deployed snapshot');
$assert(!isset($stillActive['documents']['logic']['changed']), 'runtime must continue reading the immutable activated snapshot');

$reactivated = $service->activate(12740, 'v_4444444444444444');
$assert(isset($reactivated['documents']['logic']['changed']), 'reactivation must deploy the current working bundle');
$assert($reactivated['activationId'] !== $active['activationId'], 'changed work must create a distinct activation');

// A legacy v2 pointer remains readable and is upgraded before active work is edited.
$legacyBundle = $bundles->load(12740, 'v_4444444444444444');
$legacyPointer = [
    'contract' => CalculatorVersionRuntimePublicationService::LEGACY_CONTRACT,
    'presetId' => 12740,
    'versionId' => 'v_4444444444444444',
    'calculatorName' => 'Листовая печать',
    'contentHash' => $legacyBundle['contentHash'],
    'componentHashes' => $legacyBundle['componentHashes'],
    'activatedAt' => '2026-08-27T08:59:00+05:00',
];
$replacementPointer = $pointerBeforeInvalidActivation;
$pointerStorage['CALC_ACTIVE_BUNDLE_12740'] = json_encode($legacyPointer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$interleavingService = new CalculatorVersionRuntimePublicationService($bundles, [
    'get' => static fn(string $name): string => (string)($GLOBALS['runtime_pointer_storage'][$name] ?? ''),
    'set' => static function (string $name, string $value): void { $GLOBALS['runtime_pointer_storage'][$name] = $value; },
    'lock' => static function (int $_presetId, callable $callback) use ($replacementPointer) {
        // Simulates another request committing pointer B after this request
        // had already observed legacy pointer A but before it acquired locks.
        $GLOBALS['runtime_pointer_storage']['CALC_ACTIVE_BUNDLE_12740'] = $replacementPointer;
        return $callback();
    },
    'legacy_form_publication' => static fn(int $_presetId): array => $GLOBALS['runtime_form_publication'],
    'now' => static fn(): string => '2026-08-27T09:01:00+05:00',
], $inputMappings);
$interleavingService->freezeLegacyActiveForEditing(12740, 'v_4444444444444444');
$assert(
    $pointerStorage['CALC_ACTIVE_BUNDLE_12740'] === $replacementPointer,
    'legacy freeze must observe the pointer committed immediately before its lock and never overwrite it'
);
$pointerStorage['CALC_ACTIVE_BUNDLE_12740'] = json_encode($legacyPointer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$service->freezeLegacyActiveForEditing(12740, 'v_4444444444444444');
$upgraded = json_decode($pointerStorage['CALC_ACTIVE_BUNDLE_12740'], true);
$assert(($upgraded['contract'] ?? null) === CalculatorVersionRuntimePublicationService::CONTRACT, 'legacy active pointer must upgrade to v3 before editing');
$resolvedUpgrade = $service->resolve(12740);
$assert($resolvedUpgrade['sourceContentHash'] === $legacyBundle['contentHash'], 'legacy upgrade must preserve the exact source content hash');
$assert($resolvedUpgrade['contentHash'] !== $legacyBundle['contentHash'], 'legacy upgrade must enrich the immutable deployment snapshot');

echo "Calculator version runtime publication service tests passed\n";
