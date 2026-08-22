<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$elementData = (string)file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$enrichment = (string)file_get_contents($root . '/lib/Services/PresetEnrichmentService.php');

$helperStart = strpos($elementData, 'private static function enrichStructuralResultPinned(');
$helperEnd = strpos($elementData, 'private static function assertPinnedElementExists(', $helperStart ?: 0);
$helper = $helperStart !== false && $helperEnd !== false
    ? substr($elementData, $helperStart, $helperEnd - $helperStart)
    : '';

if ($helper === ''
    || strpos($helper, '} else {') === false
    || strpos($helper, '$enrichment->clearPreset($presetId);') === false
    || strpos($helper, 'preparePresetPayload(') === false
    || strpos($helper, '$enrichment->clearPreset($presetId);')
        > strpos($helper, 'preparePresetPayload(')) {
    throw new RuntimeException(
        'Deleting the last calculator root must clear derived preset links before returning its payload.'
    );
}

$clearStart = strpos($enrichment, 'public function clearPreset(');
$clearEnd = strpos($enrichment, 'public function addStageToPreset(', $clearStart ?: 0);
$clear = $clearStart !== false && $clearEnd !== false
    ? substr($enrichment, $clearStart, $clearEnd - $clearStart)
    : '';
foreach ([
    'CALC_DETAILS',
    'CALC_STAGES',
    'CALC_SETTINGS',
    'CALC_MATERIALS',
    'CALC_MATERIALS_VARIANTS',
    'CALC_OPERATIONS',
    'CALC_OPERATIONS_VARIANTS',
    'CALC_EQUIPMENT',
    'CALC_CUSTOM_FIELDS',
] as $propertyCode) {
    if (strpos($clear, "'{$propertyCode}' => false") === false) {
        throw new RuntimeException('Preset clear omits derived property ' . $propertyCode . '.');
    }
}

echo "Empty-root preset enrichment static tests passed\n";
