<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/PresetMutationCoordinatorService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorSemanticMutationService.php';

use Prospektweb\Calc\Services\CalculatorSemanticMutationService;
use Prospektweb\Calc\Services\PresetMutationCoordinatorService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$state = [
    'preset' => ['id' => 41, 'variables' => [['VALUE' => 'old']]],
    'elementsStore' => ['CALC_SETTINGS' => [['id' => 301, 'logic' => 'old']]],
    'globalSymbols' => [['id' => 1, 'code' => 'old']],
];
$revision = 5;
$events = [];
$audits = [];
$failPresetWrite = false;

$coordinator = new PresetMutationCoordinatorService([
    'actor_id' => static fn(): int => 7,
    'audit' => static function (array $audit) use (&$audits): int {
        $audits[] = $audit;
        return count($audits);
    },
    'with_locked_revision' => static function (
        int $presetId,
        callable $criticalSection
    ) use (&$revision, &$state, &$events) {
        $snapshot = $state;
        $events[] = 'begin:' . $presetId;
        try {
            $envelope = $criticalSection($revision);
            $revision = (int)$envelope['next_revision'];
            $events[] = 'commit:' . $revision;
            return $envelope;
        } catch (Throwable $error) {
            $state = $snapshot;
            $events[] = 'rollback:' . $revision;
            throw $error;
        }
    },
]);

$readbackContexts = [];
$service = new CalculatorSemanticMutationService([
    'coordinator' => static fn() => $coordinator,
    'readback' => static function (int $presetId, array $context = []) use (&$state, &$readbackContexts): array {
        $readbackContexts[] = $context;
        return $state;
    },
    'mutation' => static function (string $action, array $request) use (&$state, &$failPresetWrite): array {
        if ($action === 'clearPreset') {
            return ['status' => 'error', 'message' => 'simulated domain rejection'];
        }
        if ($action !== 'saveCalculatorGlobals') {
            throw new RuntimeException('unexpected action');
        }
        $state['globalSymbols'] = $request['symbols'];
        if ($failPresetWrite) {
            throw new RuntimeException('simulated second aggregate write failure');
        }
        $state['preset']['variables'] = $request['variables'];
        return ['status' => 'ok', 'symbols' => $request['symbols']];
    },
]);

$beforeRevision = PresetMutationCoordinatorService::hashCanonical($state);
$result = $service->mutatePayload([[
    'action' => 'saveCalculatorGlobals',
    'presetId' => 41,
    'symbols' => [['id' => 2, 'code' => 'next']],
    'variables' => [['VALUE' => 'next']],
    'constants' => [],
]], $beforeRevision, 's1');

$assert(($result[0]['status'] ?? '') === 'ok', 'aggregate semantic mutation returns one result');
$assert(
    ($result[0]['semanticRevision'] ?? '') === PresetMutationCoordinatorService::hashCanonical($state),
    'aggregate receipt carries exact authoritative readback SHA'
);
$assert($revision === 6 && count($audits) === 1, 'aggregate advances and audits exactly once');
$assert($events === ['begin:41', 'commit:6'], 'aggregate owns one transaction envelope');

$versionBefore = PresetMutationCoordinatorService::hashCanonical($state);
$versionResult = $service->mutatePayload([[
    'action' => 'saveCalculatorGlobals',
    'presetId' => 41,
    'symbols' => [['id' => 4, 'code' => 'version-next']],
    'variables' => [['VALUE' => 'version-next']],
    'constants' => [],
]], $versionBefore, 's1', [
    'calculatorPresetId' => 12740,
    'workingPresetId' => 41,
    'versionId' => 'v_0123456789abcdef',
]);
$assert(
    ($versionResult[0]['semanticReadback'] ?? null) === $state,
    'version mutation returns the authoritative working-graph readback for immediate UI reconciliation'
);

$mutationsBeforeStale = $state;
$staleRejected = false;
try {
    $service->mutatePayload([[
        'action' => 'saveCalculatorGlobals',
        'presetId' => 41,
        'symbols' => [],
        'variables' => [],
        'constants' => [],
    ]], $beforeRevision, 's1');
} catch (RuntimeException $error) {
    $staleRejected = $error->getCode() === 409;
}
$assert($staleRejected && $state === $mutationsBeforeStale, 'stale semantic CAS is rejected before mutation');

$failPresetWrite = true;
$currentRevision = PresetMutationCoordinatorService::hashCanonical($state);
$failedState = $state;
$failedCoordinatorRevision = $revision;
$auditsBeforeFailure = count($audits);
$failed = false;
try {
    $service->mutatePayload([[
        'action' => 'saveCalculatorGlobals',
        'presetId' => 41,
        'symbols' => [['id' => 3, 'code' => 'partial']],
        'variables' => [['VALUE' => 'never']],
        'constants' => [],
    ]], $currentRevision, 's1');
} catch (RuntimeException $error) {
    $failed = str_contains($error->getMessage(), 'second aggregate');
}
$assert(
    $failed && $state === $failedState && $revision === $failedCoordinatorRevision
        && count($audits) === $auditsBeforeFailure,
    'failure of the second semantic write rolls back the first write, audit and revision'
);

$multiRejected = false;
try {
    $service->mutatePayload([
        ['action' => 'saveGlobalSymbols', 'presetId' => 41, 'symbols' => []],
        ['action' => 'savePresetGlobals', 'presetId' => 41, 'variables' => [], 'constants' => []],
    ], $currentRevision, 's1');
} catch (InvalidArgumentException $error) {
    $multiRejected = str_contains($error->getMessage(), 'exactly one');
}
$assert($multiRejected, 'legacy multi-action semantic refresh payload is rejected');

$versionContextRejected = false;
try {
    $service->mutatePayload([[
        'action' => 'saveCalculatorGlobals',
        'presetId' => 41,
        'symbols' => [],
        'variables' => [],
        'constants' => [],
    ]], PresetMutationCoordinatorService::hashCanonical($state), 's1', [
        'calculatorPresetId' => 12740,
        'workingPresetId' => 42,
        'versionId' => 'v_0123456789abcdef',
    ]);
} catch (InvalidArgumentException $error) {
    $versionContextRejected = $error->getCode() === 422;
}
$assert($versionContextRejected, 'version readback context must target the exact mutated working preset');

$revisionBeforeDomainError = $revision;
$auditsBeforeDomainError = count($audits);
$eventsBeforeDomainError = count($events);
$domainErrorRejected = false;
try {
    $service->mutatePayload([[
        'action' => 'clearPreset',
        'presetId' => 41,
    ]], PresetMutationCoordinatorService::hashCanonical($state), 's1');
} catch (RuntimeException $error) {
    $domainErrorRejected = $error->getCode() === 409
        && str_contains($error->getMessage(), 'domain rejection');
}
$assert($domainErrorRejected, 'status:error is converted to a transaction failure');
$assert(
    $revision === $revisionBeforeDomainError && count($audits) === $auditsBeforeDomainError,
    'a rejected domain result cannot advance coordinator revision or audit'
);
$assert(
    array_slice($events, $eventsBeforeDomainError) === ['begin:41', 'rollback:' . $revisionBeforeDomainError],
    'a rejected domain result rolls back the coordinator transaction'
);

fwrite(STDOUT, "Calculator semantic mutation service tests passed\n");
