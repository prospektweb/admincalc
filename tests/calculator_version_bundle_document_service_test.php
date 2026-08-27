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
$saved = $service->save(12740, 'v_1111111111111111', $components);
$assert($saved['documents'] === $components, 'complete bundle readback mismatch');
$assert(count($saved['componentHashes']) === 8, 'all component hashes are required');
$assert(($saved['readiness']['complete'] ?? false) === true, 'v2 bundle must be publication-ready');
$assert(preg_match('/^[a-f0-9]{64}$/D', $saved['contentHash']) === 1, 'aggregate content hash is invalid');

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
$assert(($legacy['readiness']['missingComponents'] ?? []) === ['publicationMetadata', 'commercialPolicy'], 'legacy readiness must name missing v2 components');

$service->delete(12740, 'v_1111111111111111');
$assert(!$service->has(12740, 'v_1111111111111111'), 'draft bundle delete must remove its manifest');

echo "Calculator version bundle document service tests passed\n";
