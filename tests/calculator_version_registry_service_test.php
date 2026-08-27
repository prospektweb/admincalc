<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionBundleDocumentService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionRegistryService.php';

use Prospektweb\Calc\Services\CalculatorVersionRegistryService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$storage = [];
$ids = ['v_1111111111111111', 'v_2222222222222222', 'v_3333333333333333'];
$idIndex = 0;
$versionDocuments = [];
$blockedDocumentDeletes = [];
$runtimeMeta = [];
$bundleMeta = [];
$service = new CalculatorVersionRegistryService([
    'get' => static function (string $name) use (&$storage): string {
        return (string)($storage[$name] ?? '');
    },
    'set' => static function (string $name, string $value) use (&$storage): void {
        $storage[$name] = $value;
    },
    'lock' => static fn(int $_presetId, callable $callback) => $callback(),
    'id' => static function () use (&$ids, &$idIndex): string {
        return $ids[$idIndex++];
    },
    'now' => static fn(): string => '2026-08-26T12:00:00+05:00',
    'runtime_meta' => static function (int $presetId) use (&$runtimeMeta): ?array {
        return is_array($runtimeMeta[$presetId] ?? null) ? $runtimeMeta[$presetId] : null;
    },
    'bundle_meta' => static function (int $presetId, string $versionId) use (&$bundleMeta): ?array {
        return is_array($bundleMeta[$presetId][$versionId] ?? null) ? $bundleMeta[$presetId][$versionId] : null;
    },
    'delete_version_documents' => static function (int $presetId, string $versionId) use (&$versionDocuments, &$blockedDocumentDeletes): void {
        if (($blockedDocumentDeletes[$presetId][$versionId] ?? false) === true) {
            return;
        }
        $versionDocuments[$presetId][$versionId] = false;
    },
    'version_documents_exist' => static function (int $presetId, string $versionId) use (&$versionDocuments): bool {
        return ($versionDocuments[$presetId][$versionId] ?? false) === true;
    },
]);

$actor = ['id' => 7, 'name' => 'Иван Иванов'];
$publishedHash = str_repeat('a', 64);
$legacy = [
    'published' => ['revision' => 4, 'compileHash' => $publishedHash],
    'history' => [['revision' => 4, 'publishedAt' => '2026-08-25T10:00:00+05:00']],
    'compile' => ['diff' => [['op' => 'replace', 'path' => 'fields.0']]],
];

$workspace = $service->loadWorkspace(12740, 'Листовая печать', $legacy, $actor);
$assert($workspace['contract'] === CalculatorVersionRegistryService::CONTRACT, 'contract mismatch');
$assert($workspace['activeVersionId'] === 'v_' . substr($publishedHash, 0, 20), 'legacy publication must become active');
$assert(count($workspace['versions']) === 2, 'active publication and differing editable version are expected');
$assert($workspace['versions'][0]['active'] === true, 'active version must be sorted first');
$assert($workspace['versions'][1]['status'] === 'VERSION', 'authoring rows must not expose draft/publication lifecycle');
$assert($workspace['versions'][1]['versionNo'] === 5, 'every editable version must receive a stable number immediately');

$created = $service->createVersion(
    12740,
    $workspace['registryRevision'],
    'Экспериментальная',
    $workspace['activeVersionId'],
    'Листовая печать',
    $legacy,
    $actor
);
$assert(count($created['versions']) === 3, 'new version was not appended');
$newRow = array_values(array_filter($created['versions'], static fn(array $row): bool => $row['name'] === 'Экспериментальная'))[0] ?? null;
$assert(is_array($newRow) && $newRow['versionId'] === 'v_2222222222222222', 'new version identity mismatch');
$assert($newRow['versionNo'] === 6, 'new version must receive the next stable number');
$assert($newRow['status'] === 'VERSION', 'new version must be immediately editable');

$renamed = $service->renameVersion(
    12740,
    $created['registryRevision'],
    $newRow['versionId'],
    'Тестовая версия',
    'Листовая печать',
    $legacy,
    $actor
);
$renamedRow = array_values(array_filter($renamed['versions'], static fn(array $row): bool => $row['versionId'] === $newRow['versionId']))[0] ?? null;
$assert(($renamedRow['name'] ?? null) === 'Тестовая версия', 'inline rename was not persisted');

$deleted = $service->deleteDraft(
    12740,
    $renamed['registryRevision'],
    $newRow['versionId'],
    'Листовая печать',
    $legacy,
    $actor
);
$assert(count($deleted['versions']) === 2, 'version delete did not remove only the requested row');

$blank = $service->createVersion(
    12740,
    $deleted['registryRevision'],
    'Чистая версия',
    null,
    'Листовая печать',
    $legacy,
    $actor
);
$blankRow = array_values(array_filter($blank['versions'], static fn(array $row): bool => $row['name'] === 'Чистая версия'))[0] ?? null;
$assert(is_array($blankRow) && $blankRow['versionId'] === 'v_3333333333333333', 'blank version identity mismatch');
$assert($blankRow['basedOnVersionId'] === null, 'blank version must not silently inherit the active version lineage');
$deleted = $service->deleteDraft(
    12740,
    $blank['registryRevision'],
    $blankRow['versionId'],
    'Листовая печать',
    $legacy,
    $actor
);

$activeArchiveBlocked = false;
try {
    $service->archivePublished(
        12740,
        $deleted['registryRevision'],
        $deleted['activeVersionId'],
        false,
        'Листовая печать',
        $legacy,
        $actor
    );
} catch (InvalidArgumentException $error) {
    $activeArchiveBlocked = str_contains($error->getMessage(), 'Активную версию');
}
$assert($activeArchiveBlocked, 'active published version must not be archived');

$legacyActivationBlocked = false;
try {
    $service->coordinatedActivateVersion(
        12740,
        $deleted['registryRevision'],
        $deleted['activeVersionId'],
        str_repeat('e', 64),
        'Листовая печать',
        $legacy,
        $actor,
        static fn(): array => ['published' => ['revision' => 5, 'compileHash' => $publishedHash]]
    );
} catch (RuntimeException $error) {
    $legacyActivationBlocked = $error->getCode() === 409
        && str_contains($error->getMessage(), 'Полный снимок');
}
$assert($legacyActivationBlocked, 'form-only legacy publication must not be presented as a complete activatable version');

$activeVersionId = (string)$deleted['activeVersionId'];
$workHash = str_repeat('e', 64);
$componentHashes = array_fill_keys(
    \Prospektweb\Calc\Services\CalculatorVersionBundleDocumentService::COMPONENTS,
    str_repeat('f', 64)
);
$bundleMeta[12740][$activeVersionId] = [
    'contentHash' => $workHash,
    'componentHashes' => $componentHashes,
    'readiness' => ['complete' => true],
];
$runtimeMeta[12740] = [
    'versionId' => $activeVersionId,
    'activationId' => 'a_' . str_repeat('1', 32),
    'snapshotVersionId' => 'v_' . str_repeat('2', 40),
    'contentHash' => str_repeat('d', 64),
    'componentHashes' => array_fill_keys(
        \Prospektweb\Calc\Services\CalculatorVersionBundleDocumentService::COMPONENTS,
        str_repeat('8', 64)
    ),
    'sourceContentHash' => $workHash,
    'sourceComponentHashes' => $componentHashes,
    'activatedAt' => '2026-08-27T12:00:00+05:00',
];
$GLOBALS['registry_runtime_activation'] = $runtimeMeta[12740];
$activated = $service->coordinatedActivateVersion(
    12740,
    $deleted['registryRevision'],
    $activeVersionId,
    $workHash,
    'Листовая печать',
    $legacy,
    $actor,
    static fn(): array => [
        'published' => ['published' => ['revision' => 5, 'compileHash' => str_repeat('a', 64)]],
        'runtime' => $GLOBALS['registry_runtime_activation'],
    ]
);
$activatedRow = array_values(array_filter($activated['versions'], static fn(array $row): bool => $row['active']))[0] ?? null;
$assert(($activatedRow['deployedContentHash'] ?? null) === $workHash, 'activation must record the deployed work hash');
$assert(($activatedRow['hasUnactivatedChanges'] ?? true) === false, 'freshly activated work must not be dirty');
$runtimeMeta[12740]['versionId'] = 'v_9999999999999999';
$mismatchedDeployment = $service->loadWorkspace(12740, 'Листовая печать', $legacy, $actor);
$assert(
    ($mismatchedDeployment['deploymentReadiness']['ready'] ?? true) === false
        && ($mismatchedDeployment['deploymentReadiness']['problem'] ?? null) === 'registry_runtime_mismatch',
    'registry projection must fail closed when runtime points to another version'
);
$runtimeMeta[12740]['versionId'] = $activeVersionId;
$runtimeMeta[12740]['sourceContentHash'] = str_repeat('7', 64);
$hashMismatchedDeployment = $service->loadWorkspace(12740, 'Листовая печать', $legacy, $actor);
$assert(
    ($hashMismatchedDeployment['deploymentReadiness']['ready'] ?? true) === false
        && ($hashMismatchedDeployment['deploymentReadiness']['problem'] ?? null) === 'registry_runtime_hash_mismatch',
    'registry projection must fail closed when the active runtime source hash differs'
);
$runtimeMeta[12740]['sourceContentHash'] = $workHash;
$runtimeMeta[12740]['sourceComponentHashes']['form'] = str_repeat('7', 64);
$componentMismatchedDeployment = $service->loadWorkspace(12740, 'Листовая печать', $legacy, $actor);
$assert(
    ($componentMismatchedDeployment['deploymentReadiness']['ready'] ?? true) === false
        && ($componentMismatchedDeployment['deploymentReadiness']['problem'] ?? null) === 'registry_runtime_hash_mismatch',
    'registry projection must fail closed when an active runtime source component differs'
);
$runtimeMeta[12740]['sourceComponentHashes'] = $componentHashes;
$bundleMeta[12740][$activeVersionId]['contentHash'] = str_repeat('9', 64);
$dirty = $service->loadWorkspace(12740, 'Листовая печать', $legacy, $actor);
$dirtyRow = array_values(array_filter($dirty['versions'], static fn(array $row): bool => $row['active']))[0] ?? null;
$assert(($dirtyRow['deployedContentHash'] ?? null) === $workHash, 'editing work must preserve the deployed hash');
$assert(($dirtyRow['hasUnactivatedChanges'] ?? false) === true, 'editing active work must require explicit reactivation');
$stalePublisherCalled = false;
try {
    $service->coordinatedActivateVersion(
        12740,
        $dirty['registryRevision'],
        $activeVersionId,
        $workHash,
        'Листовая печать',
        $legacy,
        $actor,
        static function () use (&$stalePublisherCalled): array {
            $stalePublisherCalled = true;
            return [];
        }
    );
    $assert(false, 'stale authoring hash must reject activation');
} catch (RuntimeException $error) {
    $assert($error->getCode() === 409, 'stale authoring hash must return a conflict');
}
$assert($stalePublisherCalled === false, 'stale activation must not invoke the publisher');

$staleConflict = false;
try {
    $service->createVersion(12740, $created['registryRevision'], 'Устаревший запрос', null, 'Листовая печать', $legacy, $actor);
} catch (RuntimeException $error) {
    $staleConflict = $error->getCode() === 409;
}
$assert($staleConflict, 'stale registry mutation must fail with CAS conflict');

$inactivePublishedId = 'v_4444444444444444';
$activePublishedId = 'v_5555555555555555';
$storage['CALC_VERSIONS_12741'] = json_encode([
    'storageVersion' => 1,
    'presetId' => 12741,
    'calculatorName' => 'История версий',
    'activeVersionId' => $activePublishedId,
    'updatedAt' => '2026-08-27T12:00:00+05:00',
    'versions' => [
        [
            'versionId' => $activePublishedId,
            'versionNo' => 12,
            'status' => 'PUBLISHED',
            'name' => 'Полный bundle v2',
            'basedOnVersionId' => $inactivePublishedId,
            'createdAt' => '2026-08-27T10:44:00+05:00',
            'updatedAt' => '2026-08-27T10:44:00+05:00',
            'publishedAt' => '2026-08-27T10:44:00+05:00',
            'createdBy' => $actor,
            'updatedBy' => $actor,
            'publishedBy' => $actor,
            'legacyFormRevision' => 12,
            'legacyCompileHash' => str_repeat('b', 64),
            'contentHash' => str_repeat('c', 64),
            'componentHashes' => null,
        ],
        [
            'versionId' => $inactivePublishedId,
            'versionNo' => 11,
            'status' => 'PUBLISHED',
            'name' => 'Историческая версия',
            'basedOnVersionId' => null,
            'createdAt' => '2026-08-27T09:29:00+05:00',
            'updatedAt' => '2026-08-27T09:29:00+05:00',
            'publishedAt' => '2026-08-27T09:29:00+05:00',
            'createdBy' => $actor,
            'updatedBy' => $actor,
            'publishedBy' => $actor,
            'legacyFormRevision' => 11,
            'legacyCompileHash' => str_repeat('d', 64),
            'contentHash' => null,
            'componentHashes' => null,
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$runtimeMeta[12741] = [
    'versionId' => $activePublishedId,
    'contentHash' => str_repeat('c', 64),
];
$versionDocuments[12741][$inactivePublishedId] = true;
$historyWorkspace = $service->loadWorkspace(12741, 'История версий', $legacy, $actor);
$runtimeMeta[12741]['versionId'] = 'v_8888888888888888';
$runtimeMismatchBlocked = false;
try {
    $service->deleteInactiveVersions(
        12741,
        $historyWorkspace['registryRevision'],
        [$inactivePublishedId],
        'История версий',
        $legacy,
        $actor
    );
} catch (RuntimeException $error) {
    $runtimeMismatchBlocked = $error->getCode() === 409
        && str_contains($error->getMessage(), 'runtime');
}
$assert($runtimeMismatchBlocked, 'registry/runtime active mismatch must block permanent deletion');
$assert($versionDocuments[12741][$inactivePublishedId] === true, 'runtime mismatch must not delete documents');
$runtimeMeta[12741]['versionId'] = $activePublishedId;
$blockedDocumentDeletes[12741][$inactivePublishedId] = true;
$readbackBlocked = false;
try {
    $service->deleteInactiveVersions(
        12741,
        $historyWorkspace['registryRevision'],
        [$inactivePublishedId],
        'История версий',
        $legacy,
        $actor
    );
} catch (RuntimeException $error) {
    $readbackBlocked = $error->getCode() === 409
        && str_contains($error->getMessage(), 'подтвердить удаление');
}
$assert($readbackBlocked, 'document cleanup must require an authoritative readback');
$blockedDocumentDeletes[12741][$inactivePublishedId] = false;
$pruned = $service->deleteInactiveVersions(
    12741,
    $historyWorkspace['registryRevision'],
    [$inactivePublishedId],
    'История версий',
    $legacy,
    $actor
);
$assert(count($pruned['versions']) === 1, 'inactive published version must be permanently removed');
$assert($pruned['versions'][0]['versionId'] === $activePublishedId, 'active version must remain after pruning');
$assert($pruned['versions'][0]['basedOnVersionId'] === null, 'remaining lineage must not reference a deleted base');
$assert($versionDocuments[12741][$inactivePublishedId] === false, 'selected version documents must be deleted');
$activeDeleteBlocked = false;
try {
    $service->deleteInactiveVersions(
        12741,
        $pruned['registryRevision'],
        [$activePublishedId],
        'История версий',
        $legacy,
        $actor
    );
} catch (InvalidArgumentException $error) {
    $activeDeleteBlocked = str_contains($error->getMessage(), 'Активную версию');
}
$assert($activeDeleteBlocked, 'active version must never be permanently removed');

$versionDocuments[12741][$activePublishedId] = true;
$preflightBlocked = false;
try {
    $service->deleteInactiveVersions(
        12741,
        $pruned['registryRevision'],
        [$activePublishedId, 'v_6666666666666666'],
        'История версий',
        $legacy,
        $actor
    );
} catch (InvalidArgumentException $error) {
    $preflightBlocked = str_contains($error->getMessage(), 'Активную версию');
}
$assert($preflightBlocked, 'batch cleanup must reject a protected active version during preflight');
$assert($versionDocuments[12741][$activePublishedId] === true, 'preflight failure must not delete active documents');

$missingBlocked = false;
try {
    $service->deleteInactiveVersions(
        12741,
        $pruned['registryRevision'],
        ['v_7777777777777777'],
        'История версий',
        $legacy,
        $actor
    );
} catch (InvalidArgumentException $error) {
    $missingBlocked = str_contains($error->getMessage(), 'не найдена');
}
$assert($missingBlocked, 'batch cleanup must reject a missing version during preflight');
$assert($versionDocuments[12741][$activePublishedId] === true, 'missing preflight must not delete documents');

$legacyDraftId = 'v_9999999999999999';
$storage['CALC_VERSIONS_12742'] = json_encode([
    'storageVersion' => 1,
    'presetId' => 12742,
    'calculatorName' => 'Legacy draft migration',
    'activeVersionId' => null,
    'createdAt' => '2026-08-26T10:00:00+05:00',
    'updatedAt' => '2026-08-26T10:00:00+05:00',
    'versions' => [[
        'versionId' => $legacyDraftId,
        'versionNo' => null,
        'status' => 'DRAFT',
        'name' => 'Первичный черновик',
        'basedOnVersionId' => null,
        'createdAt' => '2026-08-26T10:00:00+05:00',
        'updatedAt' => '2026-08-26T10:00:00+05:00',
        'publishedAt' => null,
        'createdBy' => $actor,
        'updatedBy' => $actor,
        'publishedBy' => null,
        'legacyFormRevision' => null,
        'legacyCompileHash' => null,
        'contentHash' => null,
        'componentHashes' => null,
    ]],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$migrated = $service->loadWorkspace(12742, 'Legacy draft migration', $legacy, $actor);
$assert($migrated['versions'][0]['versionId'] === $legacyDraftId, 'legacy migration must preserve version identity');
$assert($migrated['versions'][0]['status'] === 'VERSION', 'legacy draft must become an ordinary editable version');
$assert($migrated['versions'][0]['versionNo'] === 1, 'legacy draft must receive a stable positive number');
$storedMigrated = json_decode($storage['CALC_VERSIONS_12742'], true);
$assert(($storedMigrated['versions'][0]['status'] ?? null) === 'PUBLISHED', 'legacy migration must be persisted idempotently');

echo "Calculator version registry service tests passed\n";
