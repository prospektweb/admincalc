<?php

declare(strict_types=1);

$component = file_get_contents(__DIR__ . '/../install/components/prospektweb/calc.custom_field.edit/class.php');
$template = file_get_contents(__DIR__ . '/../install/components/prospektweb/calc.custom_field.edit/templates/.default/template.php');
$script = file_get_contents(__DIR__ . '/../install/components/prospektweb/calc.custom_field.edit/templates/.default/script.js');

foreach (['number', 'text', 'checkbox', 'select'] as $type) {
    if (!str_contains($component, "'{$type}'")) {
        throw new RuntimeException("Bitrix custom-field editor does not support type {$type}");
    }
}

if (str_contains($template, 'pattern="[A-Z][A-Z0-9_]*"')) {
    throw new RuntimeException('The custom-field code must not be blocked by brittle browser pattern validation');
}
foreach ([
    'data-field-code',
    'autocapitalize="characters"',
    'spellcheck="false"',
] as $needle) {
    if (!str_contains($template, $needle)) {
        throw new RuntimeException("Custom-field code input contract missing: {$needle}");
    }
}
foreach ([
    'bindCodeNormalization',
    "toUpperCase().replace(/[^A-Z0-9_]+/g, '_')",
    '`FIELD_${codeInput.value}`',
] as $needle) {
    if (!str_contains($script, $needle)) {
        throw new RuntimeException("Custom-field code normalization missing: {$needle}");
    }
}
if (!str_contains($component, "\$submittedCode !== \$existingCode")
    || !str_contains($component, "preg_replace('/[^A-Z0-9_]+/', '_', \$fieldCode)")) {
    throw new RuntimeException('Server-side custom-field code normalization is missing');
}
if (str_contains($component . $template, '? IBLOCK_ID=')) {
    throw new RuntimeException('Custom-field editor return URL contains an invalid query string');
}

echo "Custom field editor static contract: OK\n";
