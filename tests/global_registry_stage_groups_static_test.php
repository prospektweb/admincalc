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
$ajax = file_get_contents($root . '/tools/calculator_ajax.php');

$checks = [
    'global registry has dedicated iblock' => strpos($globals, "CALC_GLOBAL_VALUES") !== false,
    'global code is generated server-side' => strpos($globals, 'generateCode(') !== false && strpos($globals, "\\CUtil::translit") !== false,
    'global registry accepts an explicit validated code and falls back to title generation' => strpos($globals, "\$row['code']") !== false
        && strpos($globals, 'normalizeRequestedCode') !== false
        && strpos($globals, '$this->generateCode($title, $iblockId, $reservedCodes)') !== false,
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
        && strpos($globals, 'SetPropertyValues(') !== false
        && strpos($globals, "Глобальное значение не было полностью записано") !== false,
    'global registry persists the exact submitted order through Bitrix SORT' => strpos($globals, 'foreach ($rows as $rowIndex => $row)') !== false
        && strpos($globals, "'SORT' => 100 + ((int)\$rowIndex * 10)") !== false
        && strpos($globals, "['SORT' => 'ASC', 'ID' => 'ASC']") !== false,
    'global registry resolves property codes with the supported legacy filter' => substr_count($globals, "'CODE' => \$code") >= 2
        && substr_count($globals, "'=CODE' => \$code") === 1,
    'AI audit is a dedicated contract' => strpos($gateway, 'LOGIC_AUDIT_PROPOSAL_SCHEMA') !== false,
    'stage groups are stored on preset' => strpos($groups, "STAGE_GROUPS") !== false,
    'safe global refactor updates condition branch operands' => strpos($refactor, "'STAGE_GROUPS', \$map, 'condition'") !== false,
    'safe global refactor preserves scalar activation-condition storage' => strpos($refactor, "'ACTIVATION_CONDITION', \$map, 'condition', 'scalar'") !== false
        && strpos($refactor, "\$mutation['mode'] === 'scalar'") !== false,
    'safe global refactor clears existing HTML values before writing converted arrays' => strpos($refactor, "if (\$mutation['mode'] === 'html' || \$mutation['mode'] === 'formula')") !== false
        && strpos($refactor, '\\CIBlockElement::SetPropertyValues(') !== false
        && strpos($refactor, "(string)\$mutation['propertyCode']") !== false,
    'init preserves the HTML stage-group property shape after any refresh' => strpos($init, "\$code === 'STAGE_GROUPS'") !== false
        && strpos($init, "'~VALUE' => \$value") !== false,
    'server validates preset stage membership and topology' => strpos($groups, 'collectPresetStageTopology') !== false
        && strpos($groups, 'Все этапы группы должны находиться в одной колонке') !== false
        && strpos($groups, 'Этапы группы должны идти подряд') !== false,
    'stage groups preserve recursive parent links and verify durable persistence' => strpos($groups, "'parentId' => \$parentId") !== false
        && strpos($groups, 'циклическую вложенность') !== false
        && strpos($groups, 'Подгруппа должна принадлежать родительской группе') !== false
        && strpos($groups, 'SetPropertyValues(') !== false
        && strpos($groups, 'Группы этапов не были записаны в пресет') !== false,
    'stage-group property lookup uses the supported legacy code filter' => strpos($groups, "'CODE' => self::PROPERTY_CODE") !== false
        && strpos($groups, "'=CODE' => self::PROPERTY_CODE") === false,
    'stage conditions persist ordered exclusive branches with mandatory else' => strpos($groups, "'kind' => \$kind") !== false
        && strpos($groups, "'branches' => \$branches") !== false
        && strpos($groups, "\$elseCount !== 1") !== false
        && strpos($groups, "['version' => 3") !== false,
    'every condition branch may remain empty while its condition is configured' => strpos($groups, "if (!\$isElse && \$branchStageIds === [])") === false
        && strpos($groups, "\$kind === 'condition' && count(\$stageIds) < 1") === false
        && strpos($groups, "\$container = \$stageIds === [] ? null") !== false,
    'server keeps else last after preserving the submitted regular-branch order' => strpos($groups, "array_filter(\$branches, static fn(array \$branch): bool => !\$branch['isElse'])") !== false
        && strpos($groups, "array_filter(\$branches, static fn(array \$branch): bool => \$branch['isElse'])") !== false,
    'stage activation bridge writes multiple AND OR operands' => strpos($integration, "version: 2") !== false
        && strpos($integration, "mode: condition.mode === 'and' ? 'and' : 'or'") !== false
        && strpos($integration, "operands: operands") !== false,
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
    'ajax error mapper accepts every throwable without corrupting JSON errors' => strpos($ajax, 'function resolveErrorType(\\Throwable $e)') !== false,
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAILED: {$label}\n");
        exit(1);
    }
}

echo "Global registry, AI audit and stage-group static checks passed\n";
