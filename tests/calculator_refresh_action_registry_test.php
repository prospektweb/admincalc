<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/GlobalCalculatorMutationCoordinatorService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorSemanticMutationService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorRefreshActionRegistryService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorGlobalMutationService.php';

use Prospektweb\Calc\Services\CalculatorGlobalMutationService;
use Prospektweb\Calc\Services\CalculatorRefreshActionRegistryService;
use Prospektweb\Calc\Services\GlobalCalculatorMutationCoordinatorService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = dirname(__DIR__);
$elementSource = (string)file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$endpointSource = (string)file_get_contents($root . '/tools/calculator_ajax.php');
$bridgeSource = (string)file_get_contents($root . '/install/assets/js/integration.js');
$aiSource = (string)file_get_contents($root . '/lib/Services/AiGatewayService.php');

preg_match_all("/case '([^']+)':/", $elementSource, $matches);
foreach (array_values(array_unique($matches[1] ?? [])) as $action) {
    $assert(
        CalculatorRefreshActionRegistryService::classify($action) !== null,
        'ElementData action is absent from the closed refresh registry: ' . $action
    );
}
$assert(CalculatorRefreshActionRegistryService::classify('unknownWriter') === null, 'unknown action must fail closed');
$assert(
    CalculatorRefreshActionRegistryService::classify('saveAiCalculatorContext')
        === CalculatorRefreshActionRegistryService::PRESET_MUTATION,
    'AI calculator context must use preset aggregate ownership'
);

$revision = 6;
$state = ['value' => 'before'];
$writerCalls = 0;
$audits = [];
$coordinator = new GlobalCalculatorMutationCoordinatorService([
    'actor_id' => static fn(): int => 9,
    'audit' => static function (array $audit) use (&$audits): int {
        $audits[] = $audit;
        return count($audits);
    },
    'with_locked_revision' => static function (
        int $expectedRevision,
        callable $criticalSection
    ) use (&$revision, &$state): array {
        if ($expectedRevision !== $revision) {
            throw new RuntimeException('stale revision', 409);
        }
        $snapshot = $state;
        try {
            $envelope = $criticalSection($revision, [
                'iblockIds' => ['CALC_PRESETS' => 10, 'CALC_SETTINGS' => 20],
            ], null);
            $revision = (int)$envelope['next_revision'];
            return $envelope;
        } catch (Throwable $error) {
            $state = $snapshot;
            throw $error;
        }
    },
]);
$service = new CalculatorGlobalMutationService([
    'coordinator' => static fn() => $coordinator,
    'state' => static function () use (&$state): array {
        return $state;
    },
    'mutation' => static function (array $request) use (&$state, &$writerCalls): array {
        $writerCalls++;
        $state = ['value' => (string)($request['name'] ?? 'after')];
        return ['status' => 'ok'];
    },
    'affected_preset_ids' => static fn(): array => [42, 41],
]);
$fingerprint = CalculatorGlobalMutationService::fingerprintForRevision(6);
$result = $service->mutatePayload([[
    'action' => 'savePriceSettingsPreset',
    'presetId' => 12740,
    'name' => 'after',
]], 6, $fingerprint, 's1');
$assert(
    ($result[0]['globalRevision'] ?? null) === 7
        && ($result[0]['globalFingerprint'] ?? '') === CalculatorGlobalMutationService::fingerprintForRevision(7)
        && $state === ['value' => 'after']
        && $writerCalls === 1
        && count($audits) === 1,
    'global mutation must atomically advance CAS, write audit and return exact authority readback'
);

$fingerprintConflict = false;
try {
    $service->mutatePayload([[
        'action' => 'savePriceSettingsPreset',
        'presetId' => 12740,
        'name' => 'must-not-write',
    ]], 7, 'sha256:' . str_repeat('f', 64), 's1');
} catch (RuntimeException $error) {
    $fingerprintConflict = $error->getCode() === 409;
}
$assert($fingerprintConflict && $writerCalls === 1 && count($audits) === 1, 'stale fingerprint fails before writer/audit');

foreach ([
    'refreshData accepts exactly one preclassified action per request.',
    'CalculatorRefreshActionRegistryService::classify',
    'CalculatorGlobalMutationService())->mutatePayload',
    'expectedGlobalRevision',
    'expectedGlobalFingerprint',
] as $needle) {
    $assert(str_contains($endpointSource, $needle), 'refresh endpoint is missing closed authority: ' . $needle);
}
$assert(!str_contains($endpointSource, '$semanticActions !== []'), 'refresh endpoint has no non-semantic catch-all');
foreach (CalculatorRefreshActionRegistryService::globalMutationActions() as $action) {
    $assert(str_contains($bridgeSource, "'" . $action . "',"), 'bridge global action registry is missing ' . $action);
}
$getSettingsStart = strpos($aiSource, 'public function getSettings(): array');
$saveSettingsStart = strpos($aiSource, 'public function saveSettings(', $getSettingsStart ?: 0);
$getSettingsBody = $getSettingsStart !== false && $saveSettingsStart !== false
    ? substr($aiSource, $getSettingsStart, $saveSettingsStart - $getSettingsStart)
    : '';
$assert($getSettingsBody !== '' && !str_contains($getSettingsBody, 'getModels('), 'getAiSettings must not refresh or persist model cache');
$saveSettingsEnd = strpos($aiSource, 'public function generateStagePreview(', $saveSettingsStart ?: 0);
$saveSettingsBody = $saveSettingsStart !== false && $saveSettingsEnd !== false
    ? substr($aiSource, $saveSettingsStart, $saveSettingsEnd - $saveSettingsStart)
    : '';
$assert($saveSettingsBody !== ''
    && !str_contains($saveSettingsBody, 'getModels(')
    && !str_contains($saveSettingsBody, 'MODELS_CACHE_OPTION')
    && !str_contains($saveSettingsBody, 'request('),
    'saveAiSettings must not perform model HTTP/cache work under global authority');
$assert(str_contains($saveSettingsBody, '$storedTemplates = json_decode(')
    && str_contains($saveSettingsBody, 'canonicalTemplates(')
    && str_contains($saveSettingsBody, 'return $this->getSettings()'),
    'saveAiSettings must perform deterministic same-transaction readback');

echo "Calculator refresh action registry tests passed\n";
