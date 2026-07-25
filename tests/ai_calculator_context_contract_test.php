<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$context = file_get_contents($root . '/lib/Services/AiCalculatorContextService.php');
$service = file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$integration = file_get_contents($root . '/install/assets/js/integration.js');
$schema = file_get_contents($root . '/lib/Install/SchemaRepairService.php');
$installer = file_get_contents($root . '/install/step3.php');
$navigation = file_get_contents($root . '/install/assets/js/calculator.js');

foreach ([$context, $service, $integration, $schema, $installer, $navigation] as $source) {
    if ($source === false) {
        fwrite(STDERR, "Unable to read AI calculator context files\n");
        exit(1);
    }
}

$checks = [
    [$context, 'prospektweb.calc.ai-calculator-context/v1', 'versioned persisted schema'],
    [$context, 'MAX_BYTES = 262144', 'context size limit'],
    [$context, 'assertAdmin', 'admin authorization'],
    [$context, "stripos((string)\$code, 'CALC_') !== 0", 'CALC property filter'],
    [$context, "'xmlIdContract' => ''", 'non-hardcoded XML_ID contract'],
    [$context, 'PROPERTY_CML2_LINK', 'linked offer analysis'],
    [$service, "case 'getAiBaseProducts'", 'base product route'],
    [$service, "case 'previewStageLogicPrompt'", 'prompt preview route'],
    [$service, "case 'saveAiCalculatorContext'", 'save context route'],
    [$integration, 'GET_AI_BASE_PRODUCTS_REQUEST', 'base product postMessage request'],
    [$integration, 'AI_BASE_PRODUCTS_RESPONSE', 'base product postMessage response'],
    [$integration, 'PREVIEW_STAGE_LOGIC_PROMPT_REQUEST', 'prompt preview postMessage request'],
    [$integration, 'STAGE_LOGIC_PROMPT_PREVIEW_RESPONSE', 'prompt preview postMessage response'],
    [$integration, 'SAVE_AI_CALCULATOR_CONTEXT_REQUEST', 'context save request'],
    [$integration, 'AI_CONTEXT_JSON', 'calculator property update'],
    [$schema, 'AI_CONTEXT_JSON', 'repair schema'],
    [$installer, 'AI_CONTEXT_JSON', 'installer schema'],
    [file_get_contents($root . '/lib/Services/AiCalculatorContextService.php'), 'availableProductProperties', 'all product properties for manual selection'],
    [file_get_contents($root . '/lib/Services/AiCalculatorContextService.php'), 'availableOfferProperties', 'all offer properties for manual selection'],
    [$navigation, '/bitrix/admin/iblock_list_admin.php', 'product list navigation'],
];

foreach ($checks as [$haystack, $needle, $label]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "Missing {$label}: {$needle}\n");
        exit(1);
    }
}

foreach ([$navigation, file_get_contents($root . '/../calcconfig/src/lib/bitrix-utils.ts') ?: ''] as $source) {
    if (strpos($source, 'cat_product_edit.php') !== false) {
        fwrite(STDERR, "Legacy cat_product_edit.php navigation is still present\n");
        exit(1);
    }
}

echo "AI calculator context contract checks passed\n";
