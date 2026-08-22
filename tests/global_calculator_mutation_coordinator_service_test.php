<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/GlobalCalculatorMutationCoordinatorService.php';

use Prospektweb\Calc\Services\GlobalCalculatorMutationCoordinatorService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$revision = 3;
$domain = ['code' => 'old'];
$audits = [];
$events = [];
$fingerprint = 'sha256:' . str_repeat('a', 64);
$coordinator = new GlobalCalculatorMutationCoordinatorService([
    'actor_id' => static fn(): int => 17,
    'audit' => static function (array $audit) use (&$audits): int {
        $audits[] = $audit;
        return count($audits);
    },
    'with_locked_revision' => static function (
        int $expectedRevision,
        callable $criticalSection
    ) use (&$revision, &$domain, &$events): array {
        if ($expectedRevision !== $revision) {
            throw new RuntimeException('stale global revision', 409);
        }
        $snapshot = $domain;
        $events[] = 'begin:' . $revision;
        try {
            $envelope = $criticalSection($revision, null, null);
            $revision = (int)$envelope['next_revision'];
            $events[] = 'commit:' . $revision;
            return $envelope;
        } catch (Throwable $error) {
            $domain = $snapshot;
            $events[] = 'rollback:' . $revision;
            throw $error;
        }
    },
]);

$result = $coordinator->mutate(3, $fingerprint, static function () use (&$domain): array {
    $before = $domain;
    $domain['code'] = 'next';
    return [
        'before' => $before,
        'after' => $domain,
        'affected_preset_ids' => [42, 41, 42],
        'result' => ['status' => 'ok'],
    ];
}, [
    'action' => 'save_shared_library',
    'entity_type' => 'calculator_shared_library',
    'entity_id' => 'library',
]);
$assert(($result['globalRevision'] ?? null) === 4, 'global revision advances exactly once');
$assert($revision === 4 && $domain === ['code' => 'next'], 'domain mutation commits with revision');
$assert(count($audits) === 1, 'global mutation writes one audit');
$assert(
    ($audits[0]['affectedPresetIds'] ?? null) === [41, 42]
        && ($audits[0]['expectedFingerprint'] ?? '') === $fingerprint
        && ($audits[0]['action'] ?? '') === 'save_shared_library'
        && ($audits[0]['entityType'] ?? '') === 'calculator_shared_library'
        && ($audits[0]['entityId'] ?? '') === 'library',
    'audit carries deterministic preset impact and exact plan fingerprint'
);

$staleRejected = false;
try {
    $coordinator->mutate(3, $fingerprint, static function (): array {
        throw new RuntimeException('must not run');
    });
} catch (RuntimeException $error) {
    $staleRejected = $error->getCode() === 409;
}
$assert($staleRejected && count($audits) === 1, 'stale global revision is rejected before mutation/audit');

$beforeError = $domain;
$errorRejected = false;
try {
    $coordinator->mutate(4, $fingerprint, static function () use (&$domain): array {
        $before = $domain;
        $domain['code'] = 'partial';
        return [
            'before' => $before,
            'after' => $domain,
            'affected_preset_ids' => [41],
            'result' => ['status' => 'error', 'message' => 'domain failed'],
        ];
    });
} catch (RuntimeException $error) {
    $errorRejected = $error->getCode() === 409 && str_contains($error->getMessage(), 'domain failed');
}
$assert($errorRejected, 'status:error aborts a global mutation');
$assert($domain === $beforeError && $revision === 4 && count($audits) === 1, 'failed mutation rolls back domain, revision and audit');
$assert(array_slice($events, -2) === ['begin:4', 'rollback:4'], 'failed mutation has rollback receipt');

$assert(
    GlobalCalculatorMutationCoordinatorService::hashCanonical(['b' => 2, 'a' => 1])
        === GlobalCalculatorMutationCoordinatorService::hashCanonical(['a' => 1, 'b' => 2]),
    'audit hashes are canonical'
);

$source = (string)file_get_contents(
    dirname(__DIR__) . '/lib/Services/GlobalCalculatorMutationCoordinatorService.php'
);
foreach (['lockAllAuthority', 'GLOBAL_CALCULATOR_MUTATION_REVISION', 'FOR UPDATE', 'CEventLog::Add', 'commitTransaction', 'rollbackTransaction'] as $needle) {
    $assert(str_contains($source, $needle), 'production coordinator must contain ' . $needle);
}

echo "Global calculator mutation coordinator tests passed\n";
