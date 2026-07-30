<?php

$root = dirname(__DIR__);
$gateway = file_get_contents($root . '/lib/Services/AiGatewayService.php');
$globals = file_get_contents($root . '/lib/Services/GlobalSymbolService.php');
$refactor = file_get_contents($root . '/lib/Services/GlobalCodeRefactorService.php');
$groups = file_get_contents($root . '/lib/Services/StageGroupService.php');
$service = file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$integration = file_get_contents($root . '/install/assets/js/integration.js');
$init = file_get_contents($root . '/lib/Calculator/InitPayloadService.php');
$autoload = file_get_contents($root . '/include.php');

$checks = [
    'global registry has dedicated iblock' => strpos($globals, "CALC_GLOBAL_VALUES") !== false,
    'global code is generated server-side' => strpos($globals, 'generateCode(') !== false && strpos($globals, "\\CUtil::translit") !== false,
    'global code generation uses the title instead of a user-entered code hint' => strpos($globals, '$this->generateCode($title, $iblockId, $reservedCodes)') !== false
        && strpos($globals, "\$row['hint']") === false,
    'global code generation reserves calculator inputs, local variables and legacy globals' => strpos($globals, 'collectCalculatorNamespaceCodes') !== false
        && strpos($globals, "'PARAMS'") !== false
        && strpos($globals, "'LOGIC_JSON'") !== false
        && strpos($globals, "'GLOBAL_CONSTANTS'") !== false,
    'AI global rename is previewed, fingerprinted and transactionally applied' => strpos($refactor, 'function preview(') !== false
        && strpos($refactor, 'function apply(') !== false
        && strpos($refactor, 'hash_equals(') !== false
        && strpos($refactor, 'startTransaction()') !== false
        && strpos($refactor, 'replaceIdentifiers(') !== false,
    'init exposes shared symbols' => strpos($init, "'globalSymbols'") !== false,
    'global registry saves value and type by stable property ids and verifies the write' => strpos($globals, "'INITIAL_VALUE' => \$this->propertyId") !== false
        && strpos($globals, "Глобальное значение не было полностью записано") !== false,
    'AI audit is a dedicated contract' => strpos($gateway, 'LOGIC_AUDIT_PROPOSAL_SCHEMA') !== false,
    'stage groups are stored on preset' => strpos($groups, "STAGE_GROUPS") !== false,
    'init preserves the HTML stage-group property shape after any refresh' => strpos($init, "\$code === 'STAGE_GROUPS'") !== false
        && strpos($init, "'~VALUE' => \$value") !== false,
    'server validates preset stage membership and topology' => strpos($groups, 'collectPresetStageTopology') !== false
        && strpos($groups, 'Все этапы группы должны находиться в одной колонке') !== false
        && strpos($groups, 'Этапы группы должны идти подряд') !== false,
    'stage groups support one nested level and verify durable persistence' => strpos($groups, "'parentId' => \$parentId") !== false
        && strpos($groups, 'Подгруппа должна принадлежать группе верхнего уровня') !== false
        && strpos($groups, 'Группы этапов не были записаны в пресет') !== false,
    'new services are registered in Bitrix autoload map' => strpos($autoload, "'Prospektweb\\\\Calc\\\\Services\\\\GlobalSymbolService'") !== false
        && strpos($autoload, "'Prospektweb\\\\Calc\\\\Services\\\\GlobalCodeRefactorService'") !== false
        && strpos($autoload, "'Prospektweb\\\\Calc\\\\Services\\\\StageGroupService'") !== false,
    'actions are routed through PHP service' => strpos($service, "case 'saveGlobalSymbols'") !== false
        && strpos($service, "case 'previewGlobalCodeRefactor'") !== false
        && strpos($service, "case 'applyGlobalCodeRefactor'") !== false
        && strpos($service, "case 'saveStageGroups'") !== false,
    'browser bridge routes AI and both saves' => strpos($integration, 'GENERATE_LOGIC_AUDIT_REQUEST') !== false
        && strpos($integration, 'SAVE_GLOBAL_SYMBOLS_REQUEST') !== false
        && strpos($integration, 'SAVE_GLOBAL_VALUES_REQUEST') !== false
        && strpos($integration, 'PREVIEW_GLOBAL_CODE_REFACTOR_REQUEST') !== false
        && strpos($integration, 'APPLY_GLOBAL_CODE_REFACTOR_REQUEST') !== false
        && strpos($integration, 'SAVE_STAGE_GROUPS_REQUEST') !== false,
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAILED: {$label}\n");
        exit(1);
    }
}

echo "Global registry, AI audit and stage-group static checks passed\n";
