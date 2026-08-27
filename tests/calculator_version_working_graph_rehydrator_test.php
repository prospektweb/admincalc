<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/Services/BitrixTransactionStateAuthority.php';
require_once __DIR__ . '/../lib/Services/CalculatorMutationAuthorityService.php';
require_once __DIR__ . '/../lib/Services/StageVariantMappingService.php';
require_once __DIR__ . '/../lib/Services/CalculatorVersionSnapshotSourceService.php';
require_once __DIR__ . '/../lib/Services/CalculatorVersionComponentDocumentService.php';
require_once __DIR__ . '/../lib/Services/CalculatorVersionWorkingGraphRehydrator.php';

use Prospektweb\Calc\Services\CalculatorMutationAuthorityService;
use Prospektweb\Calc\Services\CalculatorVersionSnapshotSourceService;
use Prospektweb\Calc\Services\CalculatorVersionWorkingGraphRehydrator;
use Prospektweb\Calc\Services\StageVariantMappingService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$property = static function (
    string $type,
    string $multiple,
    $value,
    string $userType = '',
    string $withDescription = 'N',
    $description = ''
): array {
    return [
        'PROPERTY_TYPE' => $type,
        'USER_TYPE' => $userType,
        'MULTIPLE' => $multiple,
        'WITH_DESCRIPTION' => $withDescription,
        'VALUE' => $value,
        '~VALUE' => $value,
        'DESCRIPTION' => $description,
    ];
};

$mappingJson = static function (int $detailId, int $stageId): string {
    return (new StageVariantMappingService())->encode([
        'contract' => StageVariantMappingService::CONTRACT,
        'input_field_ids' => ['paper.type'],
        'metric_source' => ['detail_id' => $detailId, 'stage_id' => $stageId],
        'metric_keys' => ['width'],
        'rules' => [[
            'input_values' => ['paper.type' => 'coated'],
            'metric_ranges' => ['width' => ['min' => 1, 'max' => 1000]],
            'variant_id' => 777,
        ]],
    ]);
};

$logic = static function (bool $historical) use ($property, $mappingJson): array {
    $presetId = $historical ? 10 : 100;
    $detailIds = $historical ? [20, 21] : [200, 201];
    $stageIds = $historical ? [30, 31, 32] : [300, 301, 302];
    $settingsIds = $historical ? [40, 41, 42] : [400, 401, 402];
    [$rootDetailId, $childDetailId] = $detailIds;
    [$firstStageId, $secondStageId, $childStageId] = $stageIds;
    [$sharedSettingsId, $otherSettingsId, $presetSettingsId] = $settingsIds;
    $optionJson = $mappingJson($rootDetailId, $firstStageId);
    $label = $historical ? 'saved' : 'clone';
    $orderedRootStageIds = $historical
        ? [$firstStageId, $secondStageId]
        : [$secondStageId, $firstStageId];

    $rows = [[
        'id' => $presetId,
        'name' => $historical ? 'Saved calculator' : 'Fresh clone',
        'previewText' => $label . ' preview',
        'detailText' => $label . ' details',
        'measureRatio' => $historical ? null : 1.0,
        'properties' => [
            'CALC_DETAILS' => $property('E', 'Y', [$rootDetailId]),
            'CALC_SETTINGS' => $property('E', 'Y', $settingsIds),
            'OFFER_NAME_TEMPLATE' => $property('S', 'N', $label . ' stage_' . $firstStageId),
            'STAGE_GROUPS' => $property(
                'S',
                'N',
                [
                    'TEXT' => json_encode([
                        'groups' => [[
                            'stageIds' => [$firstStageId, $secondStageId],
                            'formula' => 'stage_' . $childStageId . '.result',
                        ]],
                    ], JSON_UNESCAPED_SLASHES),
                    'TYPE' => 'HTML',
                ],
                'HTML'
            ),
        ],
    ], [
        'id' => $rootDetailId,
        'name' => 'Root detail',
        'previewText' => '',
        'detailText' => '',
        'properties' => [
            'DETAILS' => $property('E', 'Y', [$childDetailId]),
            'CALC_STAGES' => $property('E', 'Y', $orderedRootStageIds),
        ],
    ], [
        'id' => $childDetailId,
        'name' => 'Child detail',
        'previewText' => '',
        'detailText' => '',
        'properties' => [
            'DETAILS' => $property('E', 'Y', []),
            'CALC_STAGES' => $property('E', 'Y', [$childStageId]),
        ],
    ], [
        'id' => $firstStageId,
        'name' => 'Coating',
        'previewText' => '',
        'detailText' => '',
        'customFields' => [['id' => $historical ? 9001 : 9901, 'code' => $label . '_field']],
        'properties' => [
            'CALC_SETTINGS' => $property('E', 'Y', [$sharedSettingsId]),
            'CUSTOM_FIELDS' => $property('E', 'Y', [$historical ? 9001 : 9901]),
            'INPUTS' => $property(
                'S',
                'Y',
                ['stage_' . $secondStageId . '.value'],
                '',
                'Y',
                ['stage_' . $childStageId . '.description']
            ),
            'OPTIONS_OPERATION' => $property(
                'S',
                'N',
                ['TEXT' => $optionJson, 'TYPE' => 'HTML'],
                'HTML',
                'Y',
                $optionJson
            ),
        ],
    ], [
        'id' => $secondStageId,
        'name' => 'Printing',
        'previewText' => '',
        'detailText' => '',
        'customFields' => [],
        'properties' => [
            'CALC_SETTINGS' => $property('E', 'Y', [$otherSettingsId]),
            'CUSTOM_FIELDS' => $property('E', 'Y', []),
        ],
    ], [
        'id' => $childStageId,
        'name' => 'Finishing',
        'previewText' => '',
        'detailText' => '',
        'customFields' => [],
        'properties' => [
            'CALC_SETTINGS' => $property('E', 'Y', [$sharedSettingsId]),
            'CUSTOM_FIELDS' => $property('E', 'Y', []),
        ],
    ], [
        'id' => $sharedSettingsId,
        'name' => 'Shared settings',
        'previewText' => '',
        'detailText' => '',
        'properties' => [
            'LOGIC_JSON' => $property(
                'S',
                'N',
                ['TEXT' => '{"formula":"stage_' . $firstStageId . '.price"}', 'TYPE' => 'HTML'],
                'HTML'
            ),
        ],
    ], [
        'id' => $otherSettingsId,
        'name' => 'Other settings',
        'previewText' => '',
        'detailText' => '',
        'properties' => [
            'PARAMS' => $property('S', 'Y', [$label . '-parameter']),
        ],
    ], [
        'id' => $presetSettingsId,
        'name' => 'Preset settings',
        'previewText' => '',
        'detailText' => '',
        'properties' => [
            'PARAMS' => $property('S', 'Y', [$label . '-preset-parameter']),
        ],
    ]];

    $graph = [
        'presetId' => $presetId,
        'rootDetailIds' => [$rootDetailId],
        'detailIds' => $detailIds,
        'stageIds' => $stageIds,
        'settingsIds' => $settingsIds,
        'directSettingsIds' => [$presetSettingsId],
        'detailChildren' => [
            $rootDetailId => [$childDetailId],
            $childDetailId => [],
        ],
        'detailStages' => [
            $rootDetailId => [$firstStageId, $secondStageId],
            $childDetailId => [$childStageId],
        ],
        'stageSettings' => [
            $firstStageId => [$sharedSettingsId],
            $secondStageId => [$otherSettingsId],
            $childStageId => [$sharedSettingsId],
        ],
        'revision' => hash('sha256', $label),
    ];
    if ($historical) {
        unset($graph['directSettingsIds']);
    }

    return [
        'contract' => CalculatorVersionSnapshotSourceService::LOGIC_CONTRACT,
        'presetId' => $presetId,
        'graph' => $graph,
        'elements' => [['iblockId' => 1, 'data' => $rows]],
        'runtimePayload' => [
            'contract' => CalculatorVersionSnapshotSourceService::LOGIC_RUNTIME_CONTRACT,
            'preset' => [
                'id' => $presetId,
                'runtimePresetId' => $presetId,
                'marker' => $label,
                'measureRatio' => $historical ? null : 1.0,
                'properties' => [
                    'CALC_DETAILS' => $property('E', 'Y', [$rootDetailId]),
                    'CALC_STAGES' => $property('E', 'Y', $orderedRootStageIds),
                    'CALC_SETTINGS' => $property('E', 'Y', $settingsIds),
                    'OFFER_NAME_TEMPLATE' => $property(
                        'S',
                        'N',
                        $label . ' stage_' . $firstStageId
                    ),
                ],
            ],
            'elementsStore' => [
                'CALC_STAGES' => [['id' => $firstStageId, 'marker' => $label]],
                'CALC_EQUIPMENT' => [['id' => 777, 'marker' => $label]],
            ],
            'elementsSiblings' => [
                'operations' => [['stageId' => $firstStageId, 'marker' => $label, 'variantId' => 777]],
            ],
            'globalSymbols' => $historical ? [[
                'id' => 501,
                'iblockId' => 99,
                'presetId' => $presetId,
                'active' => 'Y',
                'code' => 'SHEET_COUNT',
                'title' => 'Sheets',
                'description' => 'Saved global',
                'kind' => 'variable',
                'dataType' => 'number',
                'initialValue' => '1',
            ]] : [],
            'priceTypes' => [['id' => 1, 'marker' => $label]],
            'selectedOffers' => [],
            'product' => null,
            'neutralInputRequired' => true,
            'runtimeConfigSnapshot' => ['contract' => 'test-runtime-config/v1', 'marker' => $label],
        ],
    ];
};

$historical = $logic(true);
$working = $logic(false);
$plan = CalculatorVersionWorkingGraphRehydrator::plan($historical, $working, 100);
$assert(($plan['maps']['detail'] ?? null) === [20 => 200, 21 => 201], 'details must map by exact tree order');
$assert(($plan['maps']['stage'] ?? null) === [30 => 300, 31 => 301, 32 => 302], 'stages must map by exact adjacency order');
$assert(
    ($plan['maps']['settings'] ?? null) === [40 => 400, 41 => 401, 42 => 402],
    'shared and preset-only settings must map consistently'
);
$presetMutation = null;
foreach ($plan['mutations'] as $mutation) {
    if (($mutation['kind'] ?? '') === 'preset') $presetMutation = $mutation;
}
$assert(
    is_array($presetMutation)
        && array_key_exists('measureRatio', $presetMutation['catalog'])
        && $presetMutation['catalog']['measureRatio'] === null,
    'null historical preset measure ratio must be an authoritative catalog mutation'
);

$positiveRatioHistorical = $historical;
$positiveRatioHistorical['elements'][0]['data'][0]['measureRatio'] = 2.5;
$positiveRatioPlan = CalculatorVersionWorkingGraphRehydrator::plan(
    $positiveRatioHistorical,
    $working,
    100
);
$positivePresetMutation = null;
foreach ($positiveRatioPlan['mutations'] as $mutation) {
    if (($mutation['kind'] ?? '') === 'preset') $positivePresetMutation = $mutation;
}
$assert(
    ($positivePresetMutation['catalog']['measureRatio'] ?? null) === 2.5,
    'positive historical preset measure ratio must be carried for exact upsert'
);

$stageMutation = null;
foreach ($plan['mutations'] as $mutation) {
    if (($mutation['kind'] ?? '') === 'stage' && (int)($mutation['sourceId'] ?? 0) === 30) {
        $stageMutation = $mutation;
    }
}
$assert(is_array($stageMutation), 'historical stage mutation must exist');
$assert(
    ($stageMutation['properties']['INPUTS']['VALUE'][0] ?? '') === 'stage_301.value'
        && ($stageMutation['properties']['INPUTS']['DESCRIPTION'][0] ?? '') === 'stage_302.description',
    'stage_<id> references in values and descriptions must be remapped'
);
$mappedOptionsValue = json_decode(
    (string)($stageMutation['properties']['OPTIONS_OPERATION']['VALUE']['TEXT'] ?? ''),
    true
);
$mappedOptionsDescription = json_decode(
    (string)($stageMutation['properties']['OPTIONS_OPERATION']['DESCRIPTION'] ?? ''),
    true
);
$assert(
    ($mappedOptionsValue['metric_source'] ?? null) === ['detail_id' => 200, 'stage_id' => 300]
        && ($mappedOptionsDescription['metric_source'] ?? null) === ['detail_id' => 200, 'stage_id' => 300],
    'stage-variant metric_source IDs must be remapped in value and description'
);
$assert(
    (int)($mappedOptionsValue['rules'][0]['variant_id'] ?? 0) === 777,
    'external variant IDs must not be rewritten by working-graph rehydration'
);

$current = $working;
$writtenGlobals = null;
$service = new CalculatorVersionWorkingGraphRehydrator([
    'capture' => static function (int $presetId) use (&$current): array {
        if ($presetId !== 100) throw new RuntimeException('unexpected capture preset');
        return $current;
    },
    'write_element' => static function (array $mutation, array $_iblocks) use (&$current): void {
        foreach ($current['elements'] as &$batch) {
            foreach ($batch['data'] as &$row) {
                if ((int)$row['id'] !== (int)$mutation['targetId']) continue;
                $row['name'] = (string)$mutation['fields']['NAME'];
                $row['previewText'] = (string)$mutation['fields']['PREVIEW_TEXT'];
                $row['detailText'] = (string)$mutation['fields']['DETAIL_TEXT'];
                $row['properties'] = $mutation['properties'];
                if (array_key_exists('customFields', $mutation['derived'])) {
                    $row['customFields'] = $mutation['derived']['customFields'];
                }
                if (($mutation['kind'] ?? '') === 'preset') {
                    $row['measureRatio'] = $mutation['catalog']['measureRatio'] ?? null;
                }
                return;
            }
            unset($row);
        }
        unset($batch);
        throw new RuntimeException('mutation target not found');
    },
    'write_globals' => static function (
        array $rows,
        int $presetId,
        CalculatorMutationAuthorityService $_authority,
        array $_iblocks
    ) use (&$current, &$writtenGlobals): void {
        $writtenGlobals = $rows;
        $current['runtimePayload']['globalSymbols'] = [];
        foreach ($rows as $index => $row) {
            $current['runtimePayload']['globalSymbols'][] = array_merge([
                'id' => 800 + $index,
                'iblockId' => 99,
                'presetId' => $presetId,
                'active' => 'Y',
            ], $row);
        }
    },
]);
$iblocks = [
    'CALC_PRESETS' => 1,
    'CALC_DETAILS' => 2,
    'CALC_STAGES' => 3,
    'CALC_SETTINGS' => 4,
    'CALC_GLOBAL_VALUES' => 5,
];
$result = $service->rehydrateLocked(
    100,
    10,
    'v_aaaaaaaaaaaaaaaa',
    $historical,
    new CalculatorMutationAuthorityService(),
    $iblocks
);
$assert(($result['contract'] ?? '') === CalculatorVersionWorkingGraphRehydrator::CONTRACT, 'rehydration result contract is missing');
$assert(($result['mutationCount'] ?? 0) === 9, 'every structural row must be restored exactly once');
$assert(($result['globalSymbolCount'] ?? 0) === 1 && ($result['globalsChanged'] ?? false) === true, 'saved globals must be materialized');
$assert(
    is_array($writtenGlobals)
        && array_keys($writtenGlobals[0]) === ['code', 'title', 'description', 'kind', 'dataType', 'initialValue'],
    'global symbols must be written without historical storage identities'
);
$assert(
    ($result['logic']['presetId'] ?? null) === 10
        && ($result['logic']['workingPresetId'] ?? null) === 100
        && ($result['logic']['workingVersionId'] ?? null) === 'v_aaaaaaaaaaaaaaaa'
        && ($result['logic']['runtimePayload']['preset']['id'] ?? null) === 10
        && ($result['logic']['runtimePayload']['preset']['runtimePresetId'] ?? null) === 100,
    'read-back must be enveloped with calculator and physical working identities'
);
$assert(
    ($result['logic']['runtimePayload']['globalSymbols'][0]['presetId'] ?? null) === 10,
    'returned global symbols must expose calculator identity, not physical storage identity'
);

$rowsById = [];
foreach ($result['logic']['elements'] as $batch) {
    foreach ($batch['data'] as $row) $rowsById[(int)$row['id']] = $row;
}
$assert(($rowsById[100]['name'] ?? '') === 'Saved calculator', 'preset saved fields must overwrite clone drift');
$assert(
    ($rowsById[100]['properties']['OFFER_NAME_TEMPLATE']['VALUE'] ?? '') === 'saved stage_300',
    'preset formulas must be restored with remapped stage identities'
);
$assert(
    ($rowsById[300]['customFields'][0]['id'] ?? null) === 9001
        && ($rowsById[400]['name'] ?? '') === 'Shared settings'
        && ($rowsById[402]['name'] ?? '') === 'Preset settings',
    'stage custom fields, shared settings and preset-only settings must be proven by read-back'
);
$runtime = $result['logic']['runtimePayload'];
$assert(
    ($runtime['runtimeConfigSnapshot']['marker'] ?? '') === 'saved'
        && ($runtime['priceTypes'][0]['marker'] ?? '') === 'saved'
        && ($runtime['elementsStore']['CALC_EQUIPMENT'][0]['marker'] ?? '') === 'saved',
    'immutable external runtime/catalog marker A must not be replaced by physical marker B'
);
$assert(
    ($runtime['elementsStore']['CALC_STAGES'][0]['marker'] ?? '') === 'clone',
    'structural elementsStore keys must come from the proven physical read-back'
);
$assert(
    ($runtime['elementsSiblings']['operations'][0]['marker'] ?? '') === 'saved'
        && ($runtime['elementsSiblings']['operations'][0]['stageId'] ?? 0) === 300
        && ($runtime['elementsSiblings']['operations'][0]['variantId'] ?? 0) === 777,
    'elementsSiblings must preserve external bytes and remap only stageId'
);
$assert(
    ($runtime['preset']['marker'] ?? '') === 'saved'
        && array_key_exists('measureRatio', $runtime['preset'])
        && $runtime['preset']['measureRatio'] === null
        && ($runtime['preset']['properties']['CALC_DETAILS']['VALUE'][0] ?? 0) === 200
        && ($runtime['preset']['properties']['CALC_STAGES']['VALUE'] ?? null) === [300, 301]
        && ($runtime['preset']['properties']['CALC_SETTINGS']['VALUE'] ?? null) === [400, 401, 402]
        && ($runtime['preset']['properties']['OFFER_NAME_TEMPLATE']['VALUE'] ?? '') === 'saved stage_300',
    'historical runtime preset semantics and property schema must survive with remapped structural identities'
);
$assert(
    array_key_exists('measureRatio', $rowsById[100])
        && $rowsById[100]['measureRatio'] === null,
    'a null historical preset measure ratio must remove the inherited clone ratio'
);

$duplicateHistorical = $historical;
$duplicateWorking = $working;
foreach ($duplicateHistorical['elements'][0]['data'] as &$row) {
    if (in_array((int)$row['id'], [30, 31], true)) $row['name'] = 'Coating';
}
unset($row);
foreach ($duplicateWorking['elements'][0]['data'] as &$row) {
    if (in_array((int)$row['id'], [300, 301], true)) $row['name'] = 'Coating';
}
unset($row);
$duplicateWorking['elements'][0]['data'][1]['properties']['CALC_STAGES']['VALUE'] = [300, 301];
$duplicateWorking['elements'][0]['data'][1]['properties']['CALC_STAGES']['~VALUE'] = [300, 301];
$duplicatePlan = CalculatorVersionWorkingGraphRehydrator::plan(
    $duplicateHistorical,
    $duplicateWorking,
    100
);
$assert(
    ($duplicatePlan['maps']['stage'] ?? null) === [30 => 300, 31 => 301, 32 => 302],
    'duplicate stage names must be consumed deterministically by occurrence inside their owner'
);

$incompatible = $logic(false);
$incompatible['graph']['stageSettings'][302] = [401];
$topologyRejected = false;
try {
    CalculatorVersionWorkingGraphRehydrator::plan($historical, $incompatible, 100);
} catch (RuntimeException $error) {
    $topologyRejected = $error->getCode() === 409;
}
$assert($topologyRejected, 'incompatible shared-settings topology must fail closed');

$unchangedReadBackRejected = false;
try {
    CalculatorVersionWorkingGraphRehydrator::assertReadBack($plan, $working);
} catch (RuntimeException $error) {
    $unchangedReadBackRejected = $error->getCode() === 409;
}
$assert($unchangedReadBackRejected, 'missing physical writes must be detected by semantic read-back');

$outsideMetricSource = $historical;
foreach ($outsideMetricSource['elements'][0]['data'] as &$row) {
    if ((int)$row['id'] !== 30) continue;
    $badJson = $mappingJson(999, 30);
    $row['properties']['OPTIONS_OPERATION']['VALUE'] = ['TEXT' => $badJson, 'TYPE' => 'HTML'];
    $row['properties']['OPTIONS_OPERATION']['~VALUE'] = ['TEXT' => $badJson, 'TYPE' => 'HTML'];
}
unset($row);
$metricSourceRejected = false;
try {
    CalculatorVersionWorkingGraphRehydrator::plan($outsideMetricSource, $working, 100);
} catch (RuntimeException $error) {
    $metricSourceRejected = $error->getCode() === 409
        && str_contains($error->getMessage(), 'outside the version graph');
}
$assert($metricSourceRejected, 'metric_source references outside saved topology must fail closed');

$externalStageLikeCode = $historical;
$externalStageLikeCode['elements'][0]['data'][0]['properties']['EXTERNAL_CODE'] = $property(
    'S',
    'N',
    'stage_1785833534266'
);
$externalCodePlan = CalculatorVersionWorkingGraphRehydrator::plan(
    $externalStageLikeCode,
    $working,
    100
);
$assert(
    str_contains(json_encode($externalCodePlan['mutations']), 'stage_1785833534266'),
    'stage-shaped external field codes outside the graph must remain byte-stable'
);

$numericTypeHistorical = $historical;
$numericTypeWorking = $working;
$numericTypeHistorical['elements'][0]['data'][0]['attributes'] = ['weight' => 0];
$numericTypeWorking['elements'][0]['data'][0]['attributes'] = ['weight' => 0.0];
$numericTypeHistorical['elements'][0]['data'][0]['catalog'] = ['basePrice' => 25];
$numericTypeWorking['elements'][0]['data'][0]['catalog'] = ['basePrice' => 25.0];
$numericTypeHistorical['elements'][0]['data'][0]['prices'] = [[
    'typeId' => 1,
    'price' => 25,
    'currency' => 'MRG',
]];
$numericTypeWorking['elements'][0]['data'][0]['prices'] = [[
    'typeId' => 1.0,
    'price' => 25.0,
    'currency' => 'MRG',
]];
CalculatorVersionWorkingGraphRehydrator::plan($numericTypeHistorical, $numericTypeWorking, 100);

$rehydratorReflection = new ReflectionClass(CalculatorVersionWorkingGraphRehydrator::class);
$rehydrator = $rehydratorReflection->newInstanceWithoutConstructor();
$propertySemanticsMethod = $rehydratorReflection->getMethod('propertySemantics');
$propertySemanticsMethod->setAccessible(true);
$normalizeSingleWriteMethod = $rehydratorReflection->getMethod('normalizeSinglePropertyWriteValue');
$normalizeSingleWriteMethod->setAccessible(true);
$normalizeNumbersMethod = $rehydratorReflection->getMethod('normalizeNumericRepresentations');
$normalizeNumbersMethod->setAccessible(true);
$legacyHtmlProperty = $property('S', 'N', ['TEXT' => '', 'TYPE' => 'TEXT'], 'HTML');
$bitrixHtmlProperty = $property('S', 'N', ['TEXT' => '', 'TYPE' => 'HTML'], 'HTML');
$assert(
    $propertySemanticsMethod->invoke(null, $legacyHtmlProperty)
        === $propertySemanticsMethod->invoke(null, $bitrixHtmlProperty),
    'HTML property semantics must ignore Bitrix TEXT-to-HTML storage marker normalization'
);
$assert(
    $normalizeSingleWriteMethod->invoke(
        $rehydrator,
        $legacyHtmlProperty,
        ['TEXT' => '', 'TYPE' => 'TEXT']
    ) === false
        && $normalizeSingleWriteMethod->invoke(
            $rehydrator,
            $legacyHtmlProperty,
            ['TEXT' => '{"ok":true}', 'TYPE' => 'TEXT']
        ) !== false,
    'empty single HTML values must be cleared without dropping non-empty JSON'
);
$assert(
    $normalizeNumbersMethod->invoke(null, [['min' => 0, 'max' => 10, 'default' => 2]])
        === $normalizeNumbersMethod->invoke(null, [['min' => 0.0, 'max' => 10.0, 'default' => 2.0]]),
    'custom-field numeric JSON and Bitrix double representations must compare semantically'
);

$catalogDrift = $working;
$catalogDrift['elements'][0]['data'][0]['catalog']['basePrice'] = 999.0;
$catalogDriftRejected = false;
try {
    CalculatorVersionWorkingGraphRehydrator::plan($historical, $catalogDrift, 100);
} catch (RuntimeException $error) {
    $catalogDriftRejected = $error->getCode() === 409
        && str_contains($error->getMessage(), 'catalog semantics differ');
}
$assert(
    $catalogDriftRejected,
    'non-versioned catalog drift must fail closed instead of producing a mixed editable graph'
);

$rehydratorSource = file_get_contents(
    dirname(__DIR__) . '/lib/Services/CalculatorVersionWorkingGraphRehydrator.php'
);
$assert(
    is_string($rehydratorSource)
        && str_contains($rehydratorSource, "['IBLOCK_ID' => \$iblockId, 'CODE' => \$code]")
        && !str_contains($rehydratorSource, "['IBLOCK_ID' => \$iblockId, '=CODE' => \$code]"),
    'CIBlockProperty schema lookup must use the native Bitrix CODE filter'
);

echo "calculator_version_working_graph_rehydrator_test: OK\n";
