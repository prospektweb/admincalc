<?php

$calculator = file_get_contents(__DIR__ . '/../install/assets/js/calculator.js');
$calculatorAjax = file_get_contents(__DIR__ . '/../tools/calculator_ajax.php');
$integration = file_get_contents(__DIR__ . '/../install/assets/js/integration.js');

if (!is_string($calculator) || !is_string($calculatorAjax) || !is_string($integration)) {
    throw new RuntimeException('Catalog price write sources are unavailable');
}

foreach (['applyMarkups', 'getMarkupSettings', 'btn_prospektweb_markup', 'pw_add_markup'] as $legacyToken) {
    if (strpos($calculator, $legacyToken) !== false || strpos($calculatorAjax, $legacyToken) !== false) {
        throw new RuntimeException('Legacy non-transactional price writer remains reachable: ' . $legacyToken);
    }
}

foreach (['PREVIEW_CATALOG_WRITE_REQUEST', 'APPLY_CATALOG_WRITE_REQUEST'] as $canonicalMessage) {
    if (strpos($calculatorAjax, $canonicalMessage) === false || strpos($integration, $canonicalMessage) === false) {
        throw new RuntimeException('Canonical preview/apply catalog writer is unavailable: ' . $canonicalMessage);
    }
}

if (strpos($calculatorAjax, '\\CPrice::Delete') !== false || strpos($calculatorAjax, '\\CPrice::Add') !== false) {
    throw new RuntimeException('Calculator AJAX must not mutate catalog prices outside the canonical writer');
}

echo "Canonical catalog price write static tests passed\n";
