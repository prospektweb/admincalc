<?php

$integration = file_get_contents(__DIR__ . '/../install/assets/js/integration.js');
$calculator = file_get_contents(__DIR__ . '/../install/assets/js/calculator.js');

if (!is_string($integration) || !is_string($calculator)) {
    throw new RuntimeException('Calculator JavaScript sources are unavailable');
}

$checks = [
    [$integration, "offers: offers", 'Save request must submit every offer as one batch'],
    [$integration, "normalizeBatchSaveResults", 'Batch save response must be mapped back to individual offers'],
    [$calculator, "this.expandCalculatorDialog(dialog);", 'Calculator dialog must request expanded mode after Show'],
    [$calculator, ".bx-core-adm-icon-expand", 'Calculator dialog must use the native Bitrix expand action'],
];

foreach ($checks as [$source, $needle, $message]) {
    if (strpos($source, $needle) === false) {
        throw new RuntimeException($message);
    }
}

if (strpos($integration, 'saveCalculationForOffer') !== false) {
    throw new RuntimeException('Save flow must not make one HTTP request per offer');
}

echo "Calculator UI static tests passed\n";
