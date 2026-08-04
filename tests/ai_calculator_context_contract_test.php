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
    [$integration, "Array.isArray(result) ? result[0]", 'prompt preview unwraps refresh response'],
    [$integration, 'SAVE_AI_CALCULATOR_CONTEXT_REQUEST', 'context save request'],
    [$integration, 'AI_CONTEXT_JSON', 'calculator property update'],
    [$schema, 'AI_CONTEXT_JSON', 'repair schema'],
    [$installer, 'AI_CONTEXT_JSON', 'installer schema'],
    [file_get_contents($root . '/lib/Services/AiCalculatorContextService.php'), 'availableProductProperties', 'all product properties for manual selection'],
    [file_get_contents($root . '/lib/Services/AiCalculatorContextService.php'), 'availableOfferProperties', 'all offer properties for manual selection'],
    [file_get_contents($root . '/lib/Services/AiCalculatorContextService.php'), 'CIBlockProperty::GetList', 'full iblock property definitions'],
    [file_get_contents($root . '/lib/Services/AiCalculatorContextService.php'), 'bool $includeEmpty = false', 'empty properties can remain selectable'],
    [file_get_contents($root . '/lib/Services/AiCalculatorContextService.php'), "['PROPERTY_TYPE'] ?? '') === 'L'", 'list-only automatic base property rule'],
    [file_get_contents($root . '/lib/Services/AiCalculatorContextService.php'), "['USER_TYPE'] ?? '')) === 'directory'", 'directory automatic base property rule'],
    [$context, "'iblockType' => \$this->iblockType", 'actual product iblock type'],
    [$context, "'sectionId' => (int)(\$product['sectionId']", 'actual product section'],
    [$context, '\\CIBlockPropertyEnum::GetByID', 'human-readable enum value resolution'],
    [$context, "CIBlockPropertyEnum::GetList", 'all list enum options are exposed to AI'],
    [$context, "['PROPERTY_ID' => (int)(\$property['ID'] ?? 0)]", 'enum options are scoped to their property'],
    [$navigation, '/bitrix/admin/iblock_list_admin.php', 'product list navigation'],
    [file_get_contents($root . '/../calcconfig/src/lib/bitrix-utils.ts'), '/bitrix/admin/iblock_element_edit.php', 'direct product element navigation'],
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
