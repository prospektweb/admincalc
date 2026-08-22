<?php

require_once dirname(__DIR__) . '/lib/Services/CalculatorInputMappingService.php';

use Prospektweb\Calc\Services\CalculatorInputMappingService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$expectFailure = static function (callable $callback, string $message, ?int $code = null) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $error) {
        if ($code !== null) {
            $assert($error->getCode() === $code, $message . ' has the expected error code');
        }
        return;
    }
    $assert(false, $message);
};

$stored = [];
$optionWrites = [];
$locks = [];
$semanticContext = [
    'product_iblock_id' => 14,
    'offer_iblock_id' => 15,
    'fields' => [
        'paper.type' => [
            'fieldId' => 'paper.type',
            'type' => 'select',
            'options' => [['id' => 'offset'], ['id' => 'mel']],
            'dimensionInputs' => [],
        ],
        'format' => [
            'fieldId' => 'format',
            'type' => 'dimensions',
            'dimensionInputs' => [['id' => 'width'], ['id' => 'length']],
        ],
        'lamination' => [
            'fieldId' => 'lamination',
            'type' => 'select',
            'options' => [['id' => 'lamination'], ['id' => 'rounding']],
            'dimensionInputs' => [],
        ],
        'urgent' => ['fieldId' => 'urgent', 'type' => 'checkbox', 'options' => [], 'dimensionInputs' => []],
    ],
    'binding_modes' => [
        'paper.type' => 'scalar',
        'format' => 'dimensions',
        'lamination' => 'multiple',
        'urgent' => 'boolean_yn',
    ],
    'properties' => [
        'product' => [
            14 => [
                301 => ['scope' => 'product', 'code' => 'TYPE_PAPER', 'active' => true, 'property_type' => 'L', 'multiple' => false, 'enum_xml_ids' => ['OFFSET', 'MEL']],
                304 => ['scope' => 'product', 'code' => 'URGENT_AVAILABLE', 'active' => true, 'property_type' => 'S', 'multiple' => false, 'enum_xml_ids' => []],
            ],
        ],
        'selected_offer' => [
            15 => [
                902 => ['scope' => 'selected_offer', 'code' => 'FORMAT_DIMENSIONS', 'active' => true, 'property_type' => 'L', 'multiple' => true, 'enum_xml_ids' => ['WIDTH', 'LENGTH']],
                903 => ['scope' => 'selected_offer', 'code' => 'LAMINATION', 'active' => true, 'property_type' => 'L', 'multiple' => true, 'enum_xml_ids' => ['LAMINATION', 'ROUNDING']],
            ],
        ],
    ],
];
$service = new CalculatorInputMappingService([
    'get_option' => static function (string $name, string $default) use (&$stored): string {
        return $stored[$name] ?? $default;
    },
    'set_option' => static function (string $name, string $raw) use (&$stored, &$optionWrites): void {
        $stored[$name] = $raw;
        $optionWrites[] = $name;
    },
    'mutation_lock' => static function (int $presetId, callable $callback) use (&$locks) {
        $locks[] = $presetId;
        return $callback();
    },
    'semantic_context' => static fn(int $presetId): array => $semanticContext,
]);

$empty = $service->load(41);
$assert($empty === [
    'contract' => 'prospektweb.calc.calculator-input-mapping/v1',
    'preset_id' => 41,
    'revision' => 0,
    'mappings' => [],
], 'absent storage returns the exact CalcConfig document contract');
$assert($service->load(9876)['preset_id'] === 9876, 'service has no preset 12740 hardcode');

$candidate = [
    'contract' => CalculatorInputMappingService::CONTRACT,
    'preset_id' => 41,
    'revision' => 0,
    'mappings' => [
        [
            'target' => ['field_id' => 'paper.type'],
            'source' => [
                'scope' => 'product',
                'iblock_id' => 14,
                'property_id' => 301,
                'property_code' => 'TYPE_PAPER',
            ],
            'value_mode' => 'scalar',
            'option_map' => ['OFFSET' => 'offset', 'MEL' => 'mel'],
        ],
        [
            'target' => ['field_id' => 'format'],
            'source' => [
                'scope' => 'selected_offer',
                'iblock_id' => 15,
                'property_id' => 902,
                'property_code' => 'FORMAT_DIMENSIONS',
            ],
            'value_mode' => 'dimensions',
            'input_map' => ['WIDTH' => 'width', 'LENGTH' => 'length'],
        ],
        [
            'target' => ['field_id' => 'lamination'],
            'source' => [
                'scope' => 'selected_offer',
                'iblock_id' => 15,
                'property_id' => 903,
                'property_code' => 'LAMINATION',
            ],
            'value_mode' => 'multiple',
            'option_map' => ['LAMINATION' => 'lamination', 'ROUNDING' => 'rounding'],
        ],
        [
            'target' => ['field_id' => 'urgent'],
            'source' => [
                'scope' => 'product',
                'iblock_id' => 14,
                'property_id' => 304,
                'property_code' => 'URGENT_AVAILABLE',
            ],
            'value_mode' => 'boolean_yn',
        ],
    ],
];

$validation = $service->validate(41, $candidate);
$assert(array_keys($validation) === ['contract', 'preset_id', 'valid', 'mapping', 'issues'], 'validation envelope has exact keys');
$assert($validation['valid'] === true && $validation['issues'] === [], 'structurally valid mapping has no synthetic issues');
$assert($validation['mapping']['revision'] === 0, 'validation never advances CAS revision');
$assert(
    array_keys($validation['mapping']['mappings'][0]['option_map']) === ['MEL', 'OFFSET'],
    'option_map is canonicalized as a strict string map'
);
$assert(
    $validation['mapping']['mappings'][1]['input_map'] === ['LENGTH' => 'length', 'WIDTH' => 'width'],
    'dimension input_map is canonicalized'
);
$assert(
    !isset($validation['mapping']['productProfiles']) && !isset($validation['mapping']['outputMappings']),
    'new aggregate contains no product or catalog-write model'
);

$saved = $service->save(41, 0, $candidate);
$assert($saved['revision'] === 1, 'successful CAS increments the integer revision exactly once');
$assert($saved === $service->load(41), 'save returns authoritative readback');
$assert($optionWrites === ['CALCULATOR_INPUT_MAPPING_41'], 'storage key is isolated from the legacy catalog adapter');
$assert($locks === [41], 'mutation lock is preset-scoped');
$expectFailure(static function () use ($service, $candidate): void {
    $service->save(41, 0, $candidate);
}, 'stale save is rejected', 409);

$wrongCandidateRevision = $saved;
$wrongCandidateRevision['revision'] = 0;
$expectFailure(static function () use ($service, $wrongCandidateRevision): void {
    $service->save(41, 1, $wrongCandidateRevision);
}, 'mapping revision must equal expected_revision', 409);

$invalid = $candidate;
$invalid['preset_id'] = 42;
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'preset identity cannot drift');

$invalid = $candidate;
$invalid['mappings'][0]['source']['scope'] = 'offer';
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'only product and exact selected_offer scopes are accepted');

$invalid = $candidate;
$invalid['mappings'][0]['target']['field_id'] = 'missing.field';
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'target field must exist in current form');

$invalid = $candidate;
$invalid['mappings'][0]['source']['iblock_id'] = 15;
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'product source must use configured product iblock');

$invalid = $candidate;
$invalid['mappings'][0]['source']['property_code'] = 'OTHER_CODE';
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'property id and code provenance must match');

$scopeDriftContext = $semanticContext;
$scopeDriftContext['properties']['product'][14][301]['scope'] = 'selected_offer';
$scopeDriftService = new CalculatorInputMappingService([
    'get_option' => static fn(string $name, string $default): string => $default,
    'set_option' => static function (string $name, string $raw): void {},
    'semantic_context' => static fn(int $presetId): array => $scopeDriftContext,
]);
$expectFailure(static function () use ($scopeDriftService, $candidate): void {
    $scopeDriftService->validate(41, $candidate);
}, 'source property authority must preserve the exact product or selected_offer entity');

$invalid = $candidate;
$invalid['mappings'][0]['option_map']['UNKNOWN'] = 'offset';
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'option_map keys must reference existing enum XML_IDs');

$partial = $candidate;
unset($partial['mappings'][0]['option_map']['MEL']);
$partialValidation = $service->validate(41, $partial);
$assert(
    $partialValidation['valid'] === true
    && $partialValidation['mapping']['mappings'][0]['option_map'] === ['OFFSET' => 'offset']
    && count($partialValidation['issues']) === 1
    && $partialValidation['issues'][0]['severity'] === 'warning'
    && $partialValidation['issues'][0]['code'] === 'source_value_unmapped'
    && $partialValidation['issues'][0]['path'] === 'calculator_input_mapping.mappings[0].option_map.MEL',
    'partial enum mappings remain valid but expose every source value that cannot be prefilled'
);

$unmappedField = $candidate;
array_pop($unmappedField['mappings']);
$unmappedFieldValidation = $service->validate(41, $unmappedField);
$assert(
    $unmappedFieldValidation['valid'] === true
    && count($unmappedFieldValidation['issues']) === 1
    && $unmappedFieldValidation['issues'][0]['code'] === 'target_field_unmapped'
    && $unmappedFieldValidation['issues'][0]['path'] === 'form.fields.urgent',
    'validation reports form inputs that will not receive catalog prefill'
);

$invalid = $candidate;
unset($invalid['mappings'][0]['option_map']);
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'enum select never guesses XML_ID equals form option id');

$invalid = $candidate;
$invalid['mappings'][2]['value_mode'] = 'scalar';
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'multiple source and select binding require value_mode multiple');

$invalid = $candidate;
$invalid['mappings'][3]['value_mode'] = 'scalar';
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'checkbox requires value_mode boolean_yn');

$invalid = $candidate;
$invalid['mappings'][1]['input_map']['WIDTH'] = 'unknown-input';
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'input_map values must reference current dimension inputs');

$invalid = $candidate;
unset($invalid['mappings'][1]['input_map']['LENGTH']);
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'whole dimensions mapping covers every enum XML_ID and input');

$invalid = $candidate;
$invalid['mappings'][] = [
    'target' => ['field_id' => 'format', 'input_id' => 'width'],
    'source' => [
        'scope' => 'selected_offer',
        'iblock_id' => 15,
        'property_id' => 902,
        'property_code' => 'FORMAT_DIMENSIONS',
    ],
    'value_mode' => 'scalar',
];
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'whole-field and per-input dimensions mappings cannot be mixed');

$invalid = $candidate;
unset($invalid['mappings'][0]['source']['property_id']);
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'property_id is mandatory');

$invalid = $candidate;
$invalid['mappings'][0]['source']['property_code'] = 'bad-code';
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'property_code follows the shared strict contract');

$invalid = $candidate;
$invalid['mappings'][1]['target'] = $invalid['mappings'][0]['target'];
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'each field/input target has one source');

$invalid = $candidate;
$invalid['mappings'][0]['input_map'] = ['VALUE' => 'value'];
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'input_map is reserved for dimensions');

$invalid = $candidate;
$invalid['mappings'][1]['option_map'] = ['A' => 'a'];
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'dimensions cannot contain option_map');

$invalid = $candidate;
$invalid['mappings'][0]['option_map'] = ['__proto__' => 'unsafe'];
$expectFailure(static function () use ($service, $invalid): void {
    $service->validate(41, $invalid);
}, 'unsafe map keys are rejected');

foreach (['entries', 'productProfiles', 'inputMappings', 'outputMappings'] as $legacyKey) {
    $invalid = $candidate;
    $invalid[$legacyKey] = [];
    $expectFailure(static function () use ($service, $invalid): void {
        $service->validate(41, $invalid);
    }, 'non-contract and legacy keys are rejected');
}

$emptyMap = $candidate;
$emptyMap['mappings'][0]['option_map'] = [];
$expectFailure(static function () use ($service, $emptyMap): void {
    $service->validate(41, $emptyMap);
}, 'empty enum option_map is rejected instead of normalized into an implicit mapping');

$expectFailure(static function (): void {
    new CalculatorInputMappingService(['get_option' => static function (): string { return ''; }]);
}, 'option adapters must be an atomic read/write pair');

$source = file_get_contents(dirname(__DIR__) . '/lib/Services/CalculatorInputMappingService.php');
$assert(strpos($source, 'CatalogAdapterDefinitionService') === false, 'new service never reads the legacy adapter');
$assert(strpos($source, '12740') === false, 'new service contains no pilot preset hardcode');
$assert(strpos($source, 'productProfiles') === false, 'new service contains no product-profile model');
$assert(strpos($source, 'outputMappings') === false, 'new service contains no write-adapter model');
$assert(
    strpos($source, 'new CalculatorInputSourceCatalogService()') !== false
    && strpos($source, '\\CIBlockProperty::GetList') === false,
    'mapping validation reuses the same authoritative source catalog instead of a duplicate property resolver'
);

fwrite(STDOUT, "Calculator input mapping service tests passed\n");
