<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$gateway = file_get_contents($root . '/lib/Services/AiGatewayService.php');
$service = file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$integration = file_get_contents($root . '/install/assets/js/integration.js');

if ($gateway === false || $service === false || $integration === false) {
    fwrite(STDERR, "Unable to read stage AI contract files\n");
    exit(1);
}

$checks = [
    [$gateway, "prospektweb.calc.ai-stage-logic-request/v1", 'stage request schema'],
    [$gateway, "prospektweb.calc.ai-stage-logic-proposal/v1", 'stage proposal schema'],
    [$gateway, "generateStageLogicProposal", 'gateway method'],
    [$gateway, "availableSources", 'source allowlist'],
    [$gateway, "count(\$rawSources) > 120", 'source count limit'],
    [$gateway, "sourceRef", 'opaque source binding'],
    [$gateway, "draft=null", 'clarification boundary'],
    [$gateway, "Never emit sourcePath or any ID", 'prompt safety boundary'],
    [$service, "generateStageLogicProposal", 'service route'],
    [$integration, "GENERATE_STAGE_LOGIC_PROPOSAL_REQUEST", 'postMessage request'],
    [$integration, "AI_STAGE_LOGIC_PROPOSAL_RESPONSE", 'postMessage response'],
    [$integration, "currentLogic: '[REDACTED]'", 'debug log redaction'],
];

foreach ($checks as [$haystack, $needle, $label]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "Missing {$label}: {$needle}\n");
        exit(1);
    }
}

echo "AI stage logic contract checks passed\n";
