<?php

$root = dirname(__DIR__);
$gateway = (string)file_get_contents($root . '/lib/Services/AiGatewayService.php');
$materialization = (string)file_get_contents($root . '/lib/Services/AiLogicPilotMaterializationService.php');
$bridge = (string)file_get_contents($root . '/install/assets/js/integration.js');

$checks = [
    'structural pilot zone exists' => strpos($gateway, "'logic_structure_pilot'") !== false,
    'draft response contract is versioned' => strpos($gateway, 'prospektweb.calc.ai-logic-pilot-draft/v1') !== false,
    'response echoes exact preset context' => strpos($gateway, "'presetId' => max(1, (int)(\$context['presetId'] ?? 0))") !== false,
    'response echoes exact version context' => strpos($gateway, "'versionKey' => mb_substr") !== false,
    'response echoes exact snapshot hash' => strpos($gateway, "'baseCompileHash' => mb_substr") !== false,
    'response echoes request token' => strpos($gateway, "'requestToken' => mb_substr") !== false,
    'prompt forbids real records and formulas' => strpos($gateway, 'Не добавляй реальные ID, sourcePath, формулы') !== false,
    'prompt requires exact context echo' => strpos($gateway, 'Скопируй context из обязательной схемы без единого изменения') !== false,
    'pilot schema shows branch mode' => strpos($gateway, "'draftId' => 'draft_branch_001', 'title' => '', 'mode' => 'and'") !== false,
    'pilot schema shows symbolic operands' => strpos($gateway, "'kind' => 'variable', 'code' => 'needs_lamination'") !== false,
    'pilot schema shows explicit else branch' => strpos($gateway, "'draftId' => 'draft_branch_else_001'") !== false,
];

$checks += [
    'calculator objects materialize to CALC_SETTINGS' => strpos($materialization, "'calculator' => 'CALC_SETTINGS'") !== false,
    'apply is scoped to wide format preset' => strpos($materialization, 'private const TARGET_PRESET_ID = 16488') !== false,
    'known sheet preset is explicitly forbidden' => strpos($materialization, 'private const FORBIDDEN_PRESET_ID = 12740') !== false,
    'apply requires explicit confirmation' => strpos($materialization, "explicitConfirm") !== false,
    'apply requires idempotency' => strpos($materialization, "idempotencyKey") !== false,
    'variant parent link is persisted' => strpos($materialization, "'CML2_LINK'") !== false,
    'bridge exposes candidate transport' => strpos($bridge, 'LOAD_AI_LOGIC_PILOT_REPLACEMENT_CANDIDATES_REQUEST') !== false,
    'bridge exposes manifest preview transport' => strpos($bridge, 'PREVIEW_AI_LOGIC_PILOT_MANIFEST_REQUEST') !== false,
    'bridge exposes manifest apply transport' => strpos($bridge, 'APPLY_AI_LOGIC_PILOT_MANIFEST_REQUEST') !== false,
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "Failed: {$label}\n");
        exit(1);
    }
}

echo "AI logic pilot static checks passed\n";
