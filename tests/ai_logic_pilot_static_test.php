<?php

$root = dirname(__DIR__);
$gateway = (string)file_get_contents($root . '/lib/Services/AiGatewayService.php');

$checks = [
    'structural pilot zone exists' => strpos($gateway, "'logic_structure_pilot'") !== false,
    'draft response contract is versioned' => strpos($gateway, 'prospektweb.calc.ai-logic-pilot-draft/v1') !== false,
    'response echoes exact preset context' => strpos($gateway, "'presetId' => max(1, (int)(\$context['presetId'] ?? 0))") !== false,
    'response echoes exact version context' => strpos($gateway, "'versionKey' => mb_substr") !== false,
    'response echoes exact snapshot hash' => strpos($gateway, "'baseCompileHash' => mb_substr") !== false,
    'response echoes request token' => strpos($gateway, "'requestToken' => mb_substr") !== false,
    'prompt forbids real records and formulas' => strpos($gateway, 'Не добавляй реальные ID, sourcePath, формулы') !== false,
    'prompt requires exact context echo' => strpos($gateway, 'Скопируй context из обязательной схемы без единого изменения') !== false,
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "Failed: {$label}\n");
        exit(1);
    }
}

echo "AI logic pilot static checks passed\n";
