<?php

$root = dirname(__DIR__);
$gateway = file_get_contents($root . '/lib/Services/AiGatewayService.php');
$globals = file_get_contents($root . '/lib/Services/GlobalSymbolService.php');
$groups = file_get_contents($root . '/lib/Services/StageGroupService.php');
$service = file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$integration = file_get_contents($root . '/install/assets/js/integration.js');
$init = file_get_contents($root . '/lib/Calculator/InitPayloadService.php');
$autoload = file_get_contents($root . '/include.php');

$checks = [
    'global registry has dedicated iblock' => strpos($globals, "CALC_GLOBAL_VALUES") !== false,
    'global code is generated server-side' => strpos($globals, 'generateCode(') !== false && strpos($globals, "\\CUtil::translit") !== false,
    'init exposes shared symbols' => strpos($init, "'globalSymbols'") !== false,
    'AI audit is a dedicated contract' => strpos($gateway, 'LOGIC_AUDIT_PROPOSAL_SCHEMA') !== false,
    'stage groups are stored on preset' => strpos($groups, "STAGE_GROUPS") !== false,
    'server validates preset stage membership and topology' => strpos($groups, 'collectPresetStageTopology') !== false
        && strpos($groups, 'Все этапы группы должны находиться в одной колонке') !== false
        && strpos($groups, 'Этапы группы должны идти подряд') !== false,
    'new services are registered in Bitrix autoload map' => strpos($autoload, "'Prospektweb\\\\Calc\\\\Services\\\\GlobalSymbolService'") !== false
        && strpos($autoload, "'Prospektweb\\\\Calc\\\\Services\\\\StageGroupService'") !== false,
    'actions are routed through PHP service' => strpos($service, "case 'saveGlobalSymbols'") !== false && strpos($service, "case 'saveStageGroups'") !== false,
    'browser bridge routes AI and both saves' => strpos($integration, 'GENERATE_LOGIC_AUDIT_REQUEST') !== false
        && strpos($integration, 'SAVE_GLOBAL_SYMBOLS_REQUEST') !== false
        && strpos($integration, 'SAVE_STAGE_GROUPS_REQUEST') !== false,
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAILED: {$label}\n");
        exit(1);
    }
}

echo "Global registry, AI audit and stage-group static checks passed\n";
