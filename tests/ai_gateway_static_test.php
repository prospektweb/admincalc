<?php

$root = dirname(__DIR__);
$service = file_get_contents($root . '/lib/Services/AiGatewayService.php');
$elementService = file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$integration = file_get_contents($root . '/install/assets/js/integration.js');
$include = file_get_contents($root . '/include.php');

$checks = [
    'Timeweb base URL' => strpos($service, "https://api.timeweb.ai/v1") !== false,
    'server-side key option' => strpos($service, "AI_GATEWAY_API_KEY") !== false,
    'models endpoint' => strpos($service, "'/models'") !== false,
    'chat completions endpoint' => strpos($service, "'/chat/completions'") !== false,
    'preset preview tag' => strpos($service, "{анонс пресета}") !== false,
    'AI actions are routed' => strpos($elementService, "case 'getAiSettings'") !== false
        && strpos($elementService, "case 'saveAiSettings'") !== false
        && strpos($elementService, "case 'generateStagePreview'") !== false,
    'stage preview is persisted' => strpos($elementService, "'PREVIEW_TEXT' => \$previewText") !== false,
    'AI key is redacted from debug output' => strpos($integration, "[REDACTED]") !== false,
    'AI service is registered' => strpos($include, 'AiGatewayService') !== false,
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "AI gateway static checks passed\n";
