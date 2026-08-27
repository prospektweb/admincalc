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
$assert(count($workspace['versions']) === 2, 'published version and differing legacy draft are expected');
$assert($workspace['versions'][0]['status'] === 'DRAFT', 'draft must be sorted first');
$assert($workspace['versions'][1]['active'] === true, 'published legacy version must be active');

$created = $service->createDraft(
    12740,
    $workspace['registryRevision'],
    'Экспериментальная',
    $workspace['activeVersionId'],
    'Листовая печать',
    $legacy,
    $actor
);
$assert(count($created['versions']) === 3, 'new draft was not appended');
$newRow = array_values(array_filter($created['versions'], static fn(array $row): bool => $row['name'] === 'Экспериментальная'))[0] ?? null;
$assert(is_array($newRow) && $newRow['versionId'] === 'v_2222222222222222', 'new draft identity mismatch');
$assert($newRow['versionNo'] === null, 'draft must not receive a published number');

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
$assert(count($deleted['versions']) === 2, 'draft delete did not remove only the requested row');

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
    $service->coordinatedActivatePublished(
        12740,
        $deleted['registryRevision'],
        $deleted['activeVersionId'],
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

$staleConflict = false;
try {
    $service->createDraft(12740, $created['registryRevision'], 'Устаревший запрос', null, 'Листовая печать', $legacy, $actor);
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

echo "Calculator version registry service tests passed\n";
