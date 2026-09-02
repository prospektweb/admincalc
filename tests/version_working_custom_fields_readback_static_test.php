<?php

declare(strict_types=1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$bridge = (string)file_get_contents($root . '/install/assets/js/integration.js');

$slice = static function (string $source, string $start, string $end): string {
    $from = strpos($source, $start);
    $to = $from !== false ? strpos($source, $end, $from + strlen($start)) : false;
    return $from !== false && $to !== false ? substr($source, $from, $to - $from) : '';
};

$helper = $slice(
    $service,
    'private function completePresetOwnedMutation(',
    'private static function enrichStructuralResultPinned('
);
$assert(
    $helper !== ''
        && str_contains($helper, '$this->deferInitPayloadToSemanticReadback')
        && str_contains($helper, 'preparePresetPayload('),
    'preset-owned non-structural mutations must defer public INIT only at the version semantic boundary'
);

foreach ([
    "case 'changeCustomFieldsValue':" => "case 'selectFields':",
    "case 'selectFields':" => "case 'createCustomField':",
    "case 'createCustomField':" => "case 'saveSettingsEquipment':",
] as $start => $end) {
    $case = $slice($service, $start, $end);
    $assert(
        $case !== ''
            && str_contains($case, 'completePresetOwnedMutation(')
            && !str_contains($case, 'preparePresetPayload('),
        $start . ' must use version-aware semantic readback instead of public INIT'
    );
}

foreach ([
    'handleSelectFieldsRequest' => 'handleSelectDetailsRequest',
    'handleCreateCustomFieldRequest' => 'handleSavePresetGlobalsRequest',
    'handleChangeCustomFieldsValue' => 'handleAddDetailToBindingRequest',
] as $start => $end) {
    $handler = $slice($bridge, 'async ' . $start, 'async ' . $end);
    $assert(
        $handler !== '' && str_contains($handler, 'this.applySemanticReadback('),
        $start . ' must reconcile the authoritative version readback into the editor INIT'
    );
}

echo "Version-working custom-field readback static tests passed\n";
