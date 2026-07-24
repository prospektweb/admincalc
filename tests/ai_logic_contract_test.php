<?php

require_once dirname(__DIR__) . '/lib/Services/AiGatewayService.php';

use Prospektweb\Calc\Services\AiGatewayService;

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function invokePrivate(object $service, string $method, ...$arguments)
{
    $reflection = new ReflectionMethod($service, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke($service, ...$arguments);
}

$service = new AiGatewayService();
$fingerprint = 'sha256:' . str_repeat('a', 64);
$request = [
    'schema' => 'prospektweb.calc.ai-logic-request/v1',
    'baseFingerprint' => $fingerprint,
    'intent' => 'Округлить число листов вверх.',
    'variable' => [
        'code' => 'sheetCount',
        'title' => 'Количество листов',
        'description' => '',
        'formula' => 'quantity / itemsPerSheet',
    ],
    'availableSymbols' => [
        ['code' => 'quantity', 'title' => 'Тираж', 'description' => '', 'type' => 'number', 'kind' => 'input'],
    ],
];

$cleanRequest = invokePrivate($service, 'sanitizeLogicRequest', $request);
if (($cleanRequest['variable']['code'] ?? '') !== 'sheetCount') fail('request sanitizer changed the target variable');

$proposal = [
    'schema' => 'prospektweb.calc.ai-logic-proposal/v1',
    'baseFingerprint' => $fingerprint,
    'status' => 'proposal',
    'summary' => 'Количество листов округлено вверх.',
    'assumptions' => [],
    'questions' => [],
    'operations' => [[
        'op' => 'updateVariableFormula',
        'targetCode' => 'sheetCount',
        'expectedFingerprint' => $fingerprint,
        'formula' => 'ceil(quantity / itemsPerSheet)',
        'rationale' => 'Неполный лист требует целого листа.',
    ]],
];
$parsed = invokePrivate(
    $service,
    'parseLogicProposal',
    json_encode($proposal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    $cleanRequest
);
if (($parsed['operations'][0]['formula'] ?? '') !== 'ceil(quantity / itemsPerSheet)') {
    fail('valid formula proposal was not accepted');
}

$forbidden = $proposal;
$forbidden['operations'][0]['sourcePath'] = 'invented.path';
try {
    invokePrivate(
        $service,
        'parseLogicProposal',
        json_encode($forbidden, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $cleanRequest
    );
    fail('sourcePath was accepted');
} catch (InvalidArgumentException $error) {
    if (strpos($error->getMessage(), 'внутренние пути') === false) fail('sourcePath failed for the wrong reason');
}

$wrongTarget = $proposal;
$wrongTarget['operations'][0]['targetCode'] = 'otherVariable';
try {
    invokePrivate(
        $service,
        'parseLogicProposal',
        json_encode($wrongTarget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $cleanRequest
    );
    fail('cross-variable operation was accepted');
} catch (RuntimeException $error) {
    if (strpos($error->getMessage(), 'другую переменную') === false) fail('cross-variable operation failed for the wrong reason');
}

echo "AI logic contract checks passed\n";
