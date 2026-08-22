<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/PresetMutationCoordinatorService.php';
require_once dirname(__DIR__) . '/lib/Services/PresetLifecycleMutationService.php';

use Prospektweb\Calc\Services\PresetLifecycleMutationService;

final class PresetLifecycleFakeAuthority
{
    /** @var array<int,array<string,mixed>> */
    public array $graphs;
    /** @var string[] */
    public array $events = [];

    /** @param array<int,array<string,mixed>> $graphs */
    public function __construct(array $graphs)
    {
        $this->graphs = $graphs;
    }

    public function readLockedPresetGraph(int $presetId): array
    {
        $this->events[] = 'read:' . $presetId;
        if (!isset($this->graphs[$presetId])) {
            throw new RuntimeException('missing graph');
        }
        return $this->graphs[$presetId];
    }

    public function refreshLockedState(int $presetId): void
    {
        $this->events[] = 'refresh:' . $presetId;
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$authority = new PresetLifecycleFakeAuthority([
    41 => ['presetId' => 41, 'rootDetailIds' => [101], 'revision' => str_repeat('a', 64)],
]);
$identities = [41 => 'Source'];
$events = [];
$audits = [];

$service = new PresetLifecycleMutationService([
    'with_source_authority' => static function (
        int $sourcePresetId,
        callable $criticalSection
    ) use ($authority, &$events, &$identities) {
        $graphsSnapshot = $authority->graphs;
        $identitySnapshot = $identities;
        $events[] = 'begin:' . $sourcePresetId;
        try {
            $result = $criticalSection($authority, [
                'CALC_PRESETS' => 11,
                'CALC_DETAILS' => 12,
                'CALC_STAGES' => 13,
                'CALC_SETTINGS' => 14,
            ]);
            $events[] = 'commit';
            return $result;
        } catch (Throwable $error) {
            $authority->graphs = $graphsSnapshot;
            $identities = $identitySnapshot;
            $events[] = 'rollback';
            throw $error;
        }
    },
    'clone_locked' => static function (int $sourcePresetId, array $pinned) use ($authority, &$identities): int {
        if ($sourcePresetId !== 41 || ($pinned['CALC_PRESETS'] ?? 0) !== 11) {
            throw new RuntimeException('clone did not receive pinned source authority');
        }
        $authority->graphs[42] = [
            'presetId' => 42,
            'rootDetailIds' => [201],
            'revision' => str_repeat('b', 64),
        ];
        $identities[42] = 'Source (copy)';
        return 42;
    },
    'identity_loader' => static function (int $presetId) use (&$identities): array {
        return [
            'id' => $presetId,
            'name' => $identities[$presetId] ?? '',
        ];
    },
    'audit' => static function (array $audit) use (&$audits): int {
        $audits[] = $audit;
        return count($audits);
    },
    'actor_id' => static fn(): int => 9,
]);

$receipt = $service->duplicatePreset(41);
$assert(($receipt['newPresetId'] ?? 0) === 42, 'lifecycle returns authoritative clone identity');
$assert($events === ['begin:41', 'commit'], 'clone uses one source-authority transaction');
$assert(
    $authority->events === ['read:41', 'refresh:41', 'read:41', 'read:42'],
    'source is read under lock before clone and source/clone are read back before commit'
);
$assert(count($audits) === 1 && ($audits[0]['newPresetId'] ?? 0) === 42, 'clone audit is inside transaction');

$created = [];
$createAudits = [];
$failCreateAudit = false;
$createService = new PresetLifecycleMutationService([
    'with_global_authority' => static function (callable $criticalSection) use (&$created): array {
        $snapshot = $created;
        try {
            return $criticalSection([
                'CALC_PRESETS' => 11,
                'CALC_DETAILS' => 12,
                'CALC_STAGES' => 13,
                'CALC_SETTINGS' => 14,
            ]);
        } catch (Throwable $error) {
            $created = $snapshot;
            throw $error;
        }
    },
    'create_locked' => static function (string $name, array $pinned) use (&$created): int {
        $created[51] = $name;
        return 51;
    },
    'identity_loader' => static function (int $presetId) use (&$created): array {
        return ['id' => $presetId, 'name' => $created[$presetId] ?? ''];
    },
    'audit' => static function (array $audit) use (&$createAudits, &$failCreateAudit) {
        if ($failCreateAudit) {
            return false;
        }
        $createAudits[] = $audit;
        return count($createAudits);
    },
]);
$createReceipt = $createService->createPreset('New independent preset');
$assert(
    ($createReceipt['presetId'] ?? 0) === 51
        && ($created[51] ?? '') === 'New independent preset'
        && ($createAudits[0]['action'] ?? '') === 'create_preset',
    'create uses global lifecycle authority, authoritative identity readback and audit'
);
$created = [];
$failCreateAudit = true;
$createFailed = false;
try {
    $createService->createPreset('Must roll back');
} catch (RuntimeException $error) {
    $createFailed = str_contains($error->getMessage(), 'audit');
}
$assert($createFailed && $created === [], 'create audit failure leaves no preset artifact');

$source = (string)file_get_contents(dirname(__DIR__) . '/lib/Calculator/BundleHandler.php');
$assert(str_contains($source, 'clonePresetLocked('), 'BundleHandler exposes only locked clone primitive');
$assert(
    !str_contains($source, 'startTransaction()')
        && !str_contains($source, 'commitTransaction()')
        && !str_contains($source, 'rollbackTransaction()'),
    'BundleHandler clone must not own a hybrid inner transaction'
);

fwrite(STDOUT, "Preset lifecycle mutation service tests passed\n");
