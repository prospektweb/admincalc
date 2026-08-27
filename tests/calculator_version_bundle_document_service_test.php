<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionBundleDocumentService.php';

use Prospektweb\Calc\Services\CalculatorVersionBundleDocumentService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$storage = [];
$service = new CalculatorVersionBundleDocumentService([
    'get' => static fn(string $name): string => (string)($GLOBALS['bundle_storage'][$name] ?? ''),
    'set' => static function (string $name, string $value): void { $GLOBALS['bundle_storage'][$name] = $value; },
    'delete' => static function (string $name): void { unset($GLOBALS['bundle_storage'][$name]); },
    'now' => static fn(): string => '2026-08-26T18:00:00+05:00',
]);
$GLOBALS['bundle_storage'] = &$storage;
$components = [];
foreach (CalculatorVersionBundleDocumentService::COMPONENTS as $component) {
    $components[$component] = ['contract' => 'test/' . $component, 'value' => $component];
}
$components['form'] = [
    'contract' => 'prospektweb.calc.calculator-version-form/v1',
    'formDefinition' => ['contract' => 'prospektweb.frontcalc.form-definition/v1', 'sections' => [], 'fields' => []],
    'bindingDefinition' => ['contract' => 'prospektweb.frontcalc.binding-definition/v1', 'bindings' => []],
];
$components['logic'] = [
    'contract' => 'prospektweb.calc.version-logic-snapshot/v1',
    'presetId' => 12740,
    'graph' => [],
    'elements' => [],
    'runtimePayload' => [
        'contract' => 'prospektweb.calc.version-runtime-payload/v1',
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
$saved = $service->save(12740, 'v_1111111111111111', $components);
$assert($saved['documents'] == $components, 'complete bundle readback mismatch');
$assert(count($saved['componentHashes']) === 8, 'all component hashes are required');
$assert(($saved['readiness']['complete'] ?? false) === true, 'v2 bundle must be publication-ready');
$assert(preg_match('/^[a-f0-9]{64}$/D', $saved['contentHash']) === 1, 'aggregate content hash is invalid');
$formDocument = [
    'formDefinition' => $components['form']['formDefinition'],
    'bindingDefinition' => $components['form']['bindingDefinition'],
];
$assert(
    $service->formForActivation($saved, $formDocument) == $components['form'],
    'activation must return the exact bundle-owned form'
);
$mismatchRejected = false;
try {
    $differentForm = $formDocument;
    $differentForm['formDefinition']['fields'][] = ['fieldId' => 'foreign'];
    $service->formForActivation($saved, $differentForm);
} catch (RuntimeException $error) {
    $mismatchRejected = $error->getCode() === 409 && str_contains($error->getMessage(), 'расходится');
}
$assert($mismatchRejected, 'activation must fail closed when the standalone form differs from the bundle form');

$objectComponents = $components;
$objectComponents['form']['emptyObject'] = (object)[];
$objectComponents['storefronts']['nested'] = ['emptyObject' => (object)[]];
$objectRoundTrip = $service->save(12740, 'v_6666666666666666', $objectComponents);
$assert(
    isset($objectRoundTrip['documents']['form']['emptyObject'])
        && is_array($objectRoundTrip['documents']['form']['emptyObject']),
    'JSON object/list transport normalization must not produce a false aggregate corruption report'
);

$incompleteComponents = $components;
unset($incompleteComponents['logic']['runtimePayload']);
$incomplete = $service->save(12740, 'v_7777777777777777', $incompleteComponents);
$assert(($incomplete['readiness']['complete'] ?? true) === false, 'logic without an immutable runtime payload must require rebuild');
$assert(in_array('logic.runtimePayload', $incomplete['readiness']['missingComponents'] ?? [], true), 'readiness must name the missing logic runtime payload');

$copied = $service->copy(12740, 'v_1111111111111111', 'v_2222222222222222');
$assert($copied['contentHash'] === $saved['contentHash'], 'copy must preserve exact content');

$manifestName = 'CALC_VERSION_BUNDLE_12740_v_2222222222222222';
$manifest = json_decode($storage[$manifestName], true);
$componentName = 'CALC_VERSION_COMPONENT_12740_v_2222222222222222_LOGIC';
$storage[$componentName] .= ' ';
$corruptionDetected = false;
try {
    $service->load(12740, 'v_2222222222222222');
} catch (RuntimeException $error) {
    $corruptionDetected = str_contains($error->getMessage(), 'повреждён');
}
$assert($corruptionDetected, 'component corruption must be detected before exposing the bundle');
$assert(is_array($manifest), 'manifest must be stored separately from components');

$legacyVersionId = 'v_3333333333333333';
$legacyComponents = [];
foreach (CalculatorVersionBundleDocumentService::LEGACY_COMPONENTS as $component) {
    $legacyComponents[$component] = ['contract' => 'legacy/' . $component, 'value' => $component];
    $raw = json_encode($legacyComponents[$component], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $storage['CALC_VERSION_COMPONENT_12740_' . $legacyVersionId . '_' . strtoupper($component)] = $raw;
    $legacyManifestComponents[$component] = ['sha256' => hash('sha256', $raw), 'bytes' => strlen($raw)];
}
$legacyPayload = ['contract' => CalculatorVersionBundleDocumentService::LEGACY_CONTRACT, 'components' => $legacyComponents];
$canonicalize = static function ($value) use (&$canonicalize) {
    if (!is_array($value)) return $value;
    if (array_values($value) === $value) return array_map($canonicalize, $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $child) $value[$key] = $canonicalize($child);
    return $value;
};
$legacyRawPayload = json_encode($canonicalize($legacyPayload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$legacyManifest = [
    'storageVersion' => 1,
    'contract' => CalculatorVersionBundleDocumentService::LEGACY_CONTRACT,
    'presetId' => 12740,
    'versionId' => $legacyVersionId,
    'contentHash' => hash('sha256', $legacyRawPayload),
    'components' => $legacyManifestComponents,
    'totalBytes' => array_sum(array_column($legacyManifestComponents, 'bytes')),
    'updatedAt' => '2026-08-26T17:00:00+05:00',
];
$storage['CALC_VERSION_BUNDLE_12740_' . $legacyVersionId] = json_encode($legacyManifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$legacy = $service->load(12740, $legacyVersionId);
$assert(($legacy['readiness']['complete'] ?? true) === false, 'legacy six-component bundle must not look publication-ready');
$assert(
    ($legacy['readiness']['missingComponents'] ?? []) === ['publicationMetadata', 'commercialPolicy', 'logic.runtimePayload'],
    'legacy readiness must name missing v2 components and immutable logic runtime'
);

$service->delete(12740, 'v_1111111111111111');
$assert(!$service->has(12740, 'v_1111111111111111'), 'draft bundle delete must remove its manifest');

echo "Calculator version bundle document service tests passed\n";
