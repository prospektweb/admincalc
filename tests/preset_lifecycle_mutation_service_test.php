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

    public function assertLockedPresetGraphDeletable(int $presetId): array
    {
        $this->events[] = 'deletable:' . $presetId;
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

$deleteAuthority = new PresetLifecycleFakeAuthority([
    61 => [
        'presetId' => 61,
        'detailIds' => [701, 702],
        'stageIds' => [801],
        'settingsIds' => [901],
        'revision' => str_repeat('c', 64),
    ],
]);
$deleteEvents = [];
$deleteAudits = [];
$deleteService = new PresetLifecycleMutationService([
    'with_source_authority' => static function (int $presetId, callable $criticalSection) use ($deleteAuthority, &$deleteEvents) {
        $deleteEvents[] = 'begin:' . $presetId;
        try {
            $result = $criticalSection($deleteAuthority, [
                'CALC_PRESETS' => 11,
                'CALC_DETAILS' => 12,
                'CALC_STAGES' => 13,
                'CALC_SETTINGS' => 14,
                'CALC_GLOBAL_VALUES' => 15,
            ]);
            $deleteEvents[] = 'commit';
            return $result;
        } catch (Throwable $error) {
            $deleteEvents[] = 'rollback';
            throw $error;
        }
    },
    'identity_loader' => static fn(int $presetId): array => ['id' => $presetId, 'name' => 'Disposable clone'],
    'deletion_dependencies' => static fn(): array => [
        'productIblockId' => 20,
        'productPropertyId' => 21,
        'productIds' => [301, 302],
        'storefronts' => [['id' => 'sf-1', 'revision' => 3]],
        'globalIds' => [401],
        'optionRows' => [
            ['moduleId' => 'prospektweb.calc', 'name' => 'CALC_VERSIONS_61', 'value' => '{"versions":[{},{}]}'],
        ],
        'versionCount' => 2,
    ],
    'delete_locked' => static function (int $presetId, array $_pinned, array $dependencies, array $graph) use (&$deleteEvents): array {
        $deleteEvents[] = 'delete:' . $presetId . ':' . count($dependencies['productIds']) . ':' . count($graph['detailIds']);
        return ['deleted' => true];
    },
    'audit' => static function (array $audit) use (&$deleteAudits): int {
        $deleteAudits[] = $audit;
        return count($deleteAudits);
    },
]);
$preview = $deleteService->previewCascadeDelete(61);
$assert(
    ($preview['presetName'] ?? '') === 'Disposable clone'
        && ($preview['counts']['products'] ?? 0) === 2
        && ($preview['counts']['versions'] ?? 0) === 2
        && preg_match('/^[a-f0-9]{64}$/D', (string)($preview['deletionRevision'] ?? '')) === 1,
    'cascade preview exposes exact identity, owned counts and one deletion revision'
);
$wrongNameRejected = false;
try {
    $deleteService->deletePresetCascade(61, (string)$preview['deletionRevision'], 'Disposable Clone');
} catch (InvalidArgumentException $error) {
    $wrongNameRejected = str_contains($error->getMessage(), 'точное название');
}
$assert($wrongNameRejected, 'cascade deletion requires a byte-exact calculator name');
$wrongRevisionRejected = false;
try {
    $deleteService->deletePresetCascade(61, str_repeat('d', 64), 'Disposable clone');
} catch (RuntimeException $error) {
    $wrongRevisionRejected = str_contains($error->getMessage(), 'изменился');
}
$assert($wrongRevisionRejected, 'cascade deletion rejects a stale dependency revision');
$receipt = $deleteService->deletePresetCascade(61, (string)$preview['deletionRevision'], 'Disposable clone');
$assert(
    ($receipt['deleted'] ?? false) === true
        && ($receipt['presetId'] ?? 0) === 61
        && end($deleteEvents) === 'commit'
        && ($deleteAudits[0]['action'] ?? '') === 'delete_preset_cascade',
    'cascade deletion and its audit commit under one source authority'
);
$assert(
    in_array('delete:61:2:2', $deleteEvents, true),
    'cascade deletion receives the exact previewed products and structural graph'
);

$source = (string)file_get_contents(dirname(__DIR__) . '/lib/Calculator/BundleHandler.php');
$assert(str_contains($source, 'clonePresetLocked('), 'BundleHandler exposes only locked clone primitive');
$assert(
    !str_contains($source, 'startTransaction()')
        && !str_contains($source, 'commitTransaction()')
        && !str_contains($source, 'rollbackTransaction()'),
    'BundleHandler clone must not own a hybrid inner transaction'
);

fwrite(STDOUT, "Preset lifecycle mutation service tests passed\n");
