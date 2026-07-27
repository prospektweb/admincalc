<?php

$calculator = file_get_contents(__DIR__ . '/../install/assets/js/calculator.js');
$calculatorAjax = file_get_contents(__DIR__ . '/../tools/calculator_ajax.php');
$options = file_get_contents(__DIR__ . '/../options.php');
$initPayload = file_get_contents(__DIR__ . '/../lib/Calculator/InitPayloadService.php');

if (
    !is_string($calculator)
    || !is_string($calculatorAjax)
    || !is_string($options)
    || !is_string($initPayload)
) {
    throw new RuntimeException('Markup rounding sources are unavailable');
}

$checks = [
    [$calculator, 'data-role="pw-markup-rounding"', 'Markup dialog must expose its own rounding-step selector'],
    [$calculator, 'rounding: roundingNode.value', 'Markup request must submit the selected rounding step'],
    [$calculatorAjax, "\$settings['rounding'] = (float)Option::get(\$moduleId, 'PRICE_ROUNDING', 1)", 'Markup dialog must load its last selected rounding step'],
    [$calculatorAjax, "\$roundingRaw = str_replace(',', '.', (string)\$request->get('rounding'))", 'Markup endpoint must read the dialog rounding step'],
    [$calculatorAjax, "'Некорректный шаг округления'", 'Markup endpoint must reject unsupported rounding steps'],
    [$calculatorAjax, "Option::set('prospektweb.calc', 'PRICE_ROUNDING', \$rounding)", 'Markup dialog must remember its last selected rounding step'],
];

foreach ($checks as [$source, $needle, $message]) {
    if (strpos($source, $needle) === false) {
        throw new RuntimeException($message);
    }
}

if (
    strpos($options, 'name="PRICE_ROUNDING"') !== false
    || strpos($options, "Option::set(\$module_id, 'PRICE_ROUNDING'") !== false
) {
    throw new RuntimeException('Markup rounding must not remain on the module settings page');
}

if (strpos($initPayload, "'priceRounding'") !== false) {
    throw new RuntimeException('Markup-only rounding must not leak into the calculator editor payload');
}

echo "Markup rounding static tests passed\n";
