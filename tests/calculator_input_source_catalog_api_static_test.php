<?php

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/tools/control_center_editors.php');
$include = file_get_contents($root . '/include.php');
$diagnostic = file_get_contents($root . '/lib/Diagnostic/ModuleDiagnostic.php');

$assert(
    strpos($include, "'Prospektweb\\\\Calc\\\\Services\\\\CalculatorInputSourceCatalogService' => 'lib/Services/CalculatorInputSourceCatalogService.php'") !== false,
    'input source catalog is registered in the Bitrix module autoloader'
);
$assert(
    strpos($diagnostic, "'lib/Services/CalculatorInputSourceCatalogService.php'") !== false,
    'input source catalog participates in integrity diagnostics'
);
$assert(
    strpos($endpoint, "use Prospektweb\\Calc\\Services\\CalculatorInputSourceCatalogService;") !== false,
    'editors endpoint imports the dedicated source catalog service'
);
$assert(
    strpos($endpoint, "\$action === 'calculator_input_source_catalog'") !== false,
    'editors endpoint routes the read-only source catalog action'
);
$assert(
    strpos($endpoint, "['action', 'sessid', 'preset_id']") !== false
    && strpos($endpoint, "(new CalculatorInputSourceCatalogService())->load(\$presetId)") !== false,
    'source catalog endpoint accepts only the exact preset-scoped request'
);

fwrite(STDOUT, "Calculator input source catalog API static tests passed\n");
