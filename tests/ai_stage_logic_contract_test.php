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
    [$gateway, "availableSources.example", 'verified source example guidance'],
    [$gateway, "'example' => \$this->logicOptionalText", 'source example sanitizer'],
    [$gateway, "count(\$rawSources) > 180", 'source count limit'],
    [$gateway, "sourceRef", 'opaque source binding'],
    [$gateway, "draft=null", 'clarification boundary'],
    [$gateway, "Never emit sourcePath or any ID", 'prompt safety boundary'],
    [$gateway, "authoritative administrator brief", 'administrator intent boundary'],
    [$gateway, "Do not ask the administrator to choose sourceRef values", 'implementation detail boundary'],
    [$gateway, "only clarification round", 'single clarification round boundary'],
    [$gateway, "explicit compatibility boolean additional result", 'deterministic physical compatibility fallback'],
    [$gateway, "role=mapped-candidate", 'dynamic entity selection contract'],
    [$gateway, "baseProducts", 'base product examples'],
    [$gateway, 'previewStageLogicPrompt', 'exact final prompt preview'],
    [$gateway, 'prepareStageLogicPrompt', 'shared generation and preview prompt builder'],
    [$gateway, "'compatibleModules'", 'stale client field compatibility'],
    [$gateway, "expectedResults", 'expected results contract'],
    [$gateway, "'results' => []", 'no invalid result placeholder'],
    [$gateway, "Include only results that are actually bound", 'result omission instruction'],
    [$gateway, "Use [] when there are no additional results", 'additional result omission instruction'],
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
