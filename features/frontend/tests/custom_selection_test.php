<?php
require_once __DIR__ . '/../lib/Service/VirtualOfferBatchBuilder.php';
require_once __DIR__ . '/../lib/Service/CustomSelectionValidator.php';

use Prospektweb\Frontcalc\Service\VirtualOfferBatchBuilder;
use Prospektweb\Frontcalc\Service\CustomSelectionValidator;

function assert_true_custom($cond, $msg) { if (!$cond) { throw new RuntimeException($msg); } }
function assert_same_custom($expected, $actual, $msg) { if ($expected !== $actual) { throw new RuntimeException($msg . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)); } }

$config = [
    'fields' => [
        ['property_code' => 'CALC_PROP_FORMAT', 'display_mode' => 'inputs', 'use_for_area_dependency' => true, 'group_delimiter' => 'x', 'inputs' => [
            ['min' => 100, 'max' => 300, 'step' => 5], ['min' => 100, 'max' => 400, 'step' => 5],
        ]],
        ['property_code' => 'CALC_PROP_MATERIAL', 'display_mode' => 'chips_only', 'display_preset_xml_ids' => ['PAPER_150'], 'presets' => [
            ['value' => 'Backend paper', 'xml_id' => 'PAPER_150', 'sort' => 10],
        ]],
        ['property_code' => 'CALC_PROP_VOLUME', 'inputs' => [['min' => 100, 'max' => 5000, 'step' => 100]], 'area_ranges' => [
            ['index' => 0, 'area_from_mm2' => 0, 'area_to_mm2' => 50000, 'min' => 100, 'max' => 500, 'step' => 100],
            ['index' => 1, 'area_from_mm2' => 50001, 'area_to_mm2' => 100000, 'min' => 1000, 'max' => 3000, 'step' => 100],
        ], 'reference_volumes' => ['base' => [111], 'area' => [
            ['index' => 0, 'volumes' => [100, 250]],
            ['index' => 1, 'volumes' => [1000, 2000]],
        ]]],
    ],
];
$enum = [
    'CALC_PROP_MATERIAL' => [
        'PAPER_150' => ['value' => 'Backend paper', 'xml_id' => 'PAPER_150', 'sort' => 10],
        'HIDDEN' => ['value' => 'Hidden paper', 'xml_id' => 'HIDDEN', 'sort' => 20],
    ],
];
$presetBuckets = [];
$validator = new CustomSelectionValidator();
$valid = $validator->validate($config, [
    'CALC_PROP_FORMAT' => ['value' => '215x305', 'xmlId' => ''],
    'CALC_PROP_MATERIAL' => ['value' => 'Browser paper', 'xmlId' => 'PAPER_150'],
], $enum, $presetBuckets);
assert_true_custom($valid['ok'], 'Custom selection should validate');
assert_same_custom('Backend paper', $valid['values']['CALC_PROP_MATERIAL']['value'], 'Allowed XML_ID must use backend value');

$hidden = $validator->validate($config, ['CALC_PROP_FORMAT' => ['value' => '215x305'], 'CALC_PROP_MATERIAL' => ['value' => 'Hidden paper', 'xmlId' => 'HIDDEN']], $enum, $presetBuckets);
assert_same_custom(CustomSelectionValidator::NOT_ALLOWED, $hidden['error']['code'], 'Hidden chips_only XML_ID should be rejected');

$offers = (new VirtualOfferBatchBuilder())->buildForSelection($config, $valid['values'], 123, 7, 1500);
$quantities = array_map(static fn($offer) => $offer['properties']['CALC_PROP_VOLUME']['VALUE_XML_ID'], $offers);
assert_same_custom(['1000', '1500', '2000', '3000'], $quantities, '215x305 should choose area range volumes, include target quantity and bounds');
assert_same_custom(-200000001, $offers[0]['id'], 'Custom virtual ids should use separate negative range');

$target = $validator->parseTargetQuantity('1 500');
assert_true_custom($target['ok'], 'Spaced target quantity should parse');
assert_same_custom(1500, $target['value'], 'Spaced target quantity should become integer');
$context = (new VirtualOfferBatchBuilder())->resolveVolumeContext($config, $valid['values']);
assert_same_custom(1, $context['areaRangeIndex'], 'Area range must be resolved from custom dimensions');
$xmlIdOnlyContext = (new VirtualOfferBatchBuilder())->resolveVolumeContext($config, [
    'CALC_PROP_FORMAT' => ['value' => 'Wrong visible label 10x10', 'xml_id' => 'A6 215x305'],
]);
assert_same_custom(1, $xmlIdOnlyContext['areaRangeIndex'], 'Preset area must be resolved strictly from XML_ID, not visible value');
foreach ([1500 => true, 1550 => false, 50 => false, 5000 => false] as $quantity => $expectedOk) {
    $rangeResult = $validator->validateTargetQuantityAgainstContext((int)$quantity, $context);
    assert_same_custom($expectedOk, !empty($rangeResult['ok']), 'Target range/step validation failed for ' . $quantity);
}
$stepOnlyContext = ['referenceVolumes' => [], 'min' => null, 'max' => null, 'step' => 5, 'areaRangeIndex' => null];
assert_true_custom($validator->validateTargetQuantityAgainstContext(10, $stepOnlyContext)['ok'], 'Step without min should use zero anchor');
assert_same_custom(CustomSelectionValidator::OUT_OF_RANGE, $validator->validateTargetQuantityAgainstContext(7, $stepOnlyContext)['error']['code'], 'Step without min should reject non-multiple');
foreach ([0, -100, '1000.5', 'abc', '', [], true] as $badTarget) {
    $badTargetResult = $validator->parseTargetQuantity($badTarget);
    assert_same_custom(CustomSelectionValidator::TARGET_QUANTITY_INVALID, $badTargetResult['error']['code'], 'Invalid target quantity should be rejected');
}

$badType = $validator->validate($config, ['CALC_PROP_FORMAT' => ['value' => true], 'CALC_PROP_MATERIAL' => ['xmlId' => 'PAPER_150']], $enum, $presetBuckets);
assert_same_custom(CustomSelectionValidator::INVALID, $badType['error']['code'], 'Boolean selected value should be rejected');
$badParts = $validator->validate($config, ['CALC_PROP_FORMAT' => ['value' => '215x305x10'], 'CALC_PROP_MATERIAL' => ['xmlId' => 'PAPER_150']], $enum, $presetBuckets);
assert_same_custom(CustomSelectionValidator::OUT_OF_RANGE, $badParts['error']['code'], 'Wrong grouped component count should be rejected');
assert_true_custom(method_exists($validator, 'parseTargetQuantity'), 'Custom test does not require mbstring-specific APIs');

$displayWhitelistConfig = ['fields' => [
    ['property_code' => 'CALC_PROP_SIZE', 'display_mode' => 'chips_only', 'display_preset_xml_ids' => ['A4']],
]];
$displayWhitelistEnums = ['CALC_PROP_SIZE' => [
    'A4' => ['value' => 'Backend A4', 'xml_id' => 'A4', 'sort' => 10],
    'A3' => ['value' => 'Backend A3', 'xml_id' => 'A3', 'sort' => 20],
]];
$hiddenA3 = $validator->validate($displayWhitelistConfig, ['CALC_PROP_SIZE' => ['xmlId' => 'A3']], $displayWhitelistEnums, []);
assert_same_custom(CustomSelectionValidator::NOT_ALLOWED, $hiddenA3['error']['code'], 'display_preset_xml_ids should whitelist chips_only XML_ID values');
$allowedA4 = $validator->validate($displayWhitelistConfig, ['CALC_PROP_SIZE' => ['xmlId' => 'A4']], $displayWhitelistEnums, []);
assert_same_custom('Backend A4', $allowedA4['values']['CALC_PROP_SIZE']['value'], 'Allowed display XML_ID should use backend value');

$ajax = file_get_contents(__DIR__ . '/../ajax/frontcalc.php');
assert_true_custom(substr_count($ajax, 'frontcalc_assemble_public_calc_offers(') >= 3, 'Initial and custom endpoints should call shared public offer assembler');
assert_true_custom(strpos($ajax, 'FRONTCALC_METHOD_NOT_ALLOWED') < strpos($ajax, '$productsIblockId'), 'calculate_custom should have early POST/sessid protection before offer loading');
assert_true_custom(strpos($ajax, 'if (empty($publicOffers))') !== false && strpos($ajax, "frontcalc_error_response('CALC_SERVER_BATCH_FAILED'") !== false, 'Full batch failure should return success=false');
assert_true_custom(strpos($ajax, "echo json_encode(['success' => true") !== false, 'Partial success with at least one offer should keep success=true');
assert_true_custom(strpos($ajax, 'json_decode($rawSelectedValues, true)') !== false && strpos($ajax, 'FRONTCALC_CUSTOM_VALUES_INVALID') !== false, 'Broken JSON selected_values should be rejected');
assert_true_custom(strpos($ajax, '$_POST[\'selected_values\']') !== false && strpos($ajax, '$_POST[\'target_quantity\']') !== false, 'calculate_custom should read payload from POST');
assert_true_custom(strpos($ajax, 'function frontcalc_sanitize_public_calculation_meta(array $meta): array') !== false, 'Public calculation meta sanitizer should exist');
assert_true_custom(strpos($ajax, "'request_hash'") === false || strpos($ajax, "'request_hash'") < strpos($ajax, 'frontcalc_sanitize_public_calculation_meta'), 'request_hash must not be allowed by public calculation_meta sanitizer');
assert_true_custom(strpos($ajax, "'cache_error' => true") === false, 'cache_error must not be allowed by public calculation_meta sanitizer');
assert_true_custom(substr_count($ajax, 'frontcalc_sanitize_public_calculation_meta($calculationMeta)') >= 3, 'Initial load and calculate_custom responses should sanitize calculation_meta');
assert_true_custom(strpos($ajax, 'if (!$canViewInternal) {') !== false && strpos($ajax, 'unset($offer[\'internal\']);') !== false, 'Verified public offer JSON should not include internal purchase/direct/parametr values');
assert_true_custom(strpos($ajax, "'directPurchasePrice' => frontcalc_nullable_non_negative_float") !== false && strpos($ajax, "'purchasePrice' => frontcalc_nullable_non_negative_float") !== false, 'Internal monetary values should preserve zero');

echo "CustomSelection tests passed\n";
