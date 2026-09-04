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

$materialDecisionTree = [
    'contract' => StageVariantMappingService::MATERIAL_DECISION_TREE_CONTRACT,
    'tree' => [
        'kind' => 'condition',
        'source' => ['kind' => 'form_field', 'field_id' => 'material.type'],
        'branches' => [
            [
                'option_id' => 'paper',
                'child' => [
                    'kind' => 'result',
                    'result' => ['entity_type' => 'variant', 'entity_id' => 601],
                    'resolution' => 'manual',
                ],
            ],
            [
                'option_id' => 'board',
                'child' => [
                    'kind' => 'result',
                    'result' => ['entity_type' => 'material', 'entity_id' => 501],
                    'resolution' => 'manual',
                ],
            ],
        ],
    ],
];
$materialDecisionTreeJson = $service->encode($materialDecisionTree);
$assert(
    $service->normalizeMaterialJson($materialDecisionTreeJson) === $materialDecisionTreeJson
    && $service->materialReferencesFromJson($materialDecisionTreeJson) === [
        ['entity_type' => 'variant', 'entity_id' => 601],
        ['entity_type' => 'material', 'entity_id' => 501],
    ],
    'material decision tree v4 must round-trip and expose every referenced terminal'
);
$equipmentDecisionTree = $materialDecisionTree;
$equipmentDecisionTree['tree']['branches'] = [[
    'option_id' => 'paper',
    'child' => [
        'kind' => 'result',
        'result' => ['entity_type' => 'equipment', 'entity_id' => 701],
        'resolution' => 'manual',
    ],
]];
$equipmentDecisionTreeJson = $service->normalizeMaterialJson(json_encode($equipmentDecisionTree, JSON_UNESCAPED_SLASHES));
$assert(
    $service->materialReferencesFromJson($equipmentDecisionTreeJson) === [['entity_type' => 'equipment', 'entity_id' => 701]],
    'universal decision tree preserves a typed equipment terminal'
);
$rejects(
    static fn() => $service->normalizeJson($materialDecisionTreeJson),
    'Unsupported stage variant mapping contract'
);
$repeatedSourceTree = $materialDecisionTree;
$repeatedSourceTree['tree']['branches'][0]['child'] = [
    'kind' => 'condition',
    'source' => ['kind' => 'form_field', 'field_id' => 'material.type'],
    'branches' => [[
        'option_id' => 'paper',
        'child' => [
            'kind' => 'result',
            'result' => ['entity_type' => 'variant', 'entity_id' => 601],
            'resolution' => 'automatic',
        ],
    ]],
];
$rejects(static fn() => $service->encode($repeatedSourceTree), 'repeats source field');
foreach ([
    'prospektweb.calc.stage-material-selection/v1',
    'prospektweb.calc.stage-material-selection/v2',
    'prospektweb.calc.stage-material-selection/v3',
] as $retiredMaterialContract) {
    $rejects(
        static fn() => $service->normalizeMaterialJson(json_encode([
            'contract' => $retiredMaterialContract,
            'input_field_ids' => ['material.type'],
            'metric_source' => null,
            'metric_keys' => [],
            'rules' => [[
                'input_values' => ['material.type' => 'paper'],
                'metric_ranges' => new stdClass(),
                'variant_id' => 601,
            ]],
        ])),
        'supports only'
    );
}
$materialRuleMappingJson = json_encode([
    'contract' => StageVariantMappingService::CONTRACT,
    'input_field_ids' => ['global.constant.PRINT_SITE', 'global.variable.RunMode'],
    'metric_source' => null,
    'metric_keys' => [],
    'rules' => [[
        'input_values' => [
            'global.constant.PRINT_SITE' => 'main',
            'global.variable.RunMode' => 'true',
        ],
        'metric_ranges' => new stdClass(),
        'variant_id' => 601,
    ]],
], JSON_UNESCAPED_SLASHES);
$normalizedMaterialRuleMapping = $service->normalizeMaterialJson($materialRuleMappingJson);
$assert(
    json_decode($normalizedMaterialRuleMapping, true)['contract'] === StageVariantMappingService::CONTRACT
    && $service->materialReferencesFromJson($normalizedMaterialRuleMapping) === [
        ['entity_type' => 'variant', 'entity_id' => 601],
    ],
    'material rule mapping accepts scalar global sources and exposes variant references'
);
$service->assertSemanticSources($normalizedMaterialRuleMapping, [], [
    ['kind' => 'constant', 'code' => 'PRINT_SITE', 'dataType' => 'string'],
    ['kind' => 'variable', 'code' => 'RunMode', 'dataType' => 'boolean'],
]);
$rejects(
    static fn() => $service->assertSemanticSources($normalizedMaterialRuleMapping, [], [
        ['kind' => 'constant', 'code' => 'PRINT_SITE', 'dataType' => 'string'],
    ]),
    'missing semantic source'
);
$invalidBooleanMapping = json_decode($normalizedMaterialRuleMapping, true);
$invalidBooleanMapping['rules'][0]['input_values']['global.variable.RunMode'] = 'Y';
$rejects(
    static fn() => $service->assertSemanticSources($service->encode($invalidBooleanMapping), [], [
        ['kind' => 'constant', 'code' => 'PRINT_SITE', 'dataType' => 'string'],
        ['kind' => 'variable', 'code' => 'RunMode', 'dataType' => 'boolean'],
    ]),
    'invalid boolean global value'
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
$rejects(static fn() => $service->normalizeJson(json_encode($legacy)), 'Unsupported stage variant mapping contract');

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
