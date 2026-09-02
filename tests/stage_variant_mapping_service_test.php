<?php

require_once dirname(__DIR__) . '/lib/Services/StageVariantMappingService.php';

use Prospektweb\Calc\Services\StageVariantMappingService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$rejects = static function (callable $callback, string $needle) use ($assert): void {
    try {
        $callback();
    } catch (InvalidArgumentException $error) {
        $assert(str_contains($error->getMessage(), $needle), 'rejection explains ' . $needle);
        return;
    }
    $assert(false, 'invalid mapping must be rejected: ' . $needle);
};

$service = new StageVariantMappingService();

$materialSelection = $service->normalize([
    'contract' => StageVariantMappingService::MATERIAL_SELECTION_CONTRACT,
    'candidate_refs' => [
        ['entity_type' => 'material', 'entity_id' => 501],
        ['entity_type' => 'variant', 'entity_id' => 601],
    ],
    'input_field_ids' => ['paper.kind'],
    'metric_source' => null,
    'metric_keys' => [],
    'rules' => [
        [
            'input_values' => ['paper.kind' => 'cardboard'],
            'metric_ranges' => [],
            'result' => ['entity_type' => 'material', 'entity_id' => 501],
        ],
        [
            'input_values' => ['paper.kind' => 'coated'],
            'metric_ranges' => [],
            'result' => ['entity_type' => 'variant', 'entity_id' => 601],
        ],
    ],
]);
$assert(
    ($materialSelection['rules'][0]['result']['entity_type'] ?? null) === 'material'
    && $service->materialReferencesFromJson($service->encode($materialSelection)) === $materialSelection['candidate_refs'],
    'material selection v2 must preserve terminal material and variant references'
);
$document = [
    'contract' => StageVariantMappingService::CONTRACT,
    'input_field_ids' => ['method', 'paper.type'],
    'metric_source' => ['detail_id' => 123, 'stage_id' => 456],
    'metric_keys' => ['width', 'output:sheet.area'],
    'rules' => [[
        'input_values' => ['method' => 'digital', 'paper.type' => 'coated'],
        'metric_ranges' => [
            'width' => ['min' => 0, 'max' => 320.0],
            'output:sheet.area' => ['min' => 1, 'max' => 1000],
        ],
        'variant_id' => 1083,
    ]],
];
$encoded = $service->normalizeJson(json_encode(
    $document,
    JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
));
$decoded = json_decode($encoded, true);
$assert($decoded === $document, 'canonical mapping preserves the exact v1 document');
$assert($service->variantIdsFromJson($encoded) === [1083], 'variant IDs come only from canonical rules');
$assert($service->normalizeJson('') === '', 'empty string clears a mapping');

$legacy = [
    'offerPropertyCodes' => ['METHOD'],
    'productPropertyCodes' => [],
    'mappings' => [['xmlId' => 'digital', 'variantId' => 1083]],
];
$rejects(static fn() => $service->normalizeJson(json_encode($legacy)), 'invalid JSON object/list shape');

$inputOnly = [
    'contract' => StageVariantMappingService::CONTRACT,
    'input_field_ids' => ['method'],
    'metric_source' => null,
    'metric_keys' => [],
    'rules' => [[
        'input_values' => ['method' => 'digital'],
        'metric_ranges' => [],
        'variant_id' => 1083,
    ]],
];
$inputOnlyJson = $service->encode($inputOnly);
$assert(str_contains($inputOnlyJson, '"metric_ranges":{}'), 'empty metric_ranges is encoded as a JSON object');
$assert($service->normalizeJson($inputOnlyJson) === $inputOnlyJson, 'input-only canonical JSON round-trips exactly');
$rejects(
    static fn() => $service->normalizeJson(str_replace('"metric_ranges":{}', '"metric_ranges":[]', $inputOnlyJson)),
    'must be JSON objects'
);

$metricOnly = [
    'contract' => StageVariantMappingService::CONTRACT,
    'input_field_ids' => [],
    'metric_source' => ['detail_id' => 123, 'stage_id' => 456],
    'metric_keys' => ['width'],
    'rules' => [[
        'input_values' => [],
        'metric_ranges' => ['width' => ['min' => 0, 'max' => 320]],
        'variant_id' => 1083,
    ]],
];
$metricOnlyJson = $service->encode($metricOnly);
$assert(str_contains($metricOnlyJson, '"input_values":{}'), 'empty input_values is encoded as a JSON object');
$assert($service->normalizeJson($metricOnlyJson) === $metricOnlyJson, 'metric-only canonical JSON round-trips exactly');

$noCriteria = $inputOnly;
$noCriteria['input_field_ids'] = [];
$noCriteria['rules'][0]['input_values'] = [];
$rejects(static fn() => $service->encode($noCriteria), 'at least one semantic input or metric criterion');

$mutate = static function (array $source, callable $callback): string {
    $callback($source);
    return json_encode($source, JSON_UNESCAPED_SLASHES);
};
$rejects(static fn() => $service->normalizeJson($mutate($document, static function (array &$value): void {
    $value['contract'] = 'prospektweb.calc.stage-variant-mapping/v0';
})), 'Unsupported');
$rejects(static fn() => $service->normalizeJson($mutate($document, static function (array &$value): void {
    $value['input_field_ids'][] = 'METHOD';
})), 'invalid');
$rejects(static fn() => $service->normalizeJson($mutate($document, static function (array &$value): void {
    $value['input_field_ids'][] = 'method';
})), 'duplicate');
$rejects(static fn() => $service->normalizeJson($mutate($document, static function (array &$value): void {
    $value['metric_source'] = null;
})), 'required');
$rejects(static fn() => $service->normalizeJson($mutate($document, static function (array &$value): void {
    $value['metric_keys'] = [];
    $value['rules'][0]['metric_ranges'] = new stdClass();
})), 'must be null');
$rejects(static fn() => $service->normalizeJson($mutate($document, static function (array &$value): void {
    unset($value['rules'][0]['input_values']['paper.type']);
})), 'exactly match');
$rejects(static fn() => $service->normalizeJson($mutate($document, static function (array &$value): void {
    $value['rules'][0]['input_values']['method'] = '';
})), 'non-empty option ID');
$rejects(static fn() => $service->normalizeJson($mutate($document, static function (array &$value): void {
    $value['rules'][0]['metric_ranges']['width']['min'] = -1;
})), '0 <= min <= max');
$rejects(static fn() => $service->normalizeJson($mutate($document, static function (array &$value): void {
    $value['rules'][0]['metric_ranges']['width']['max'] = '320';
})), 'finite number');
$rejects(static fn() => $service->normalizeJson($mutate($document, static function (array &$value): void {
    $value['rules'][0]['variant_id'] = 0;
})), 'positive safe integer');
$rejects(static fn() => $service->normalizeJson($mutate($document, static function (array &$value): void {
    $value['rules'][0]['catalog_property_code'] = 'METHOD';
})), 'exact keys');

fwrite(STDOUT, "OK\n");
