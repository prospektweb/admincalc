<?php

declare(strict_types=1);

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

$republished = false;
$reactivated = $service->coordinatedActivatePublished(
    12740,
    $deleted['registryRevision'],
    $deleted['activeVersionId'],
    'Листовая печать',
    $legacy,
    $actor,
    static function () use (&$republished, $publishedHash): array {
        $republished = true;
        return ['published' => ['revision' => 5, 'compileHash' => $publishedHash]];
    }
);
$assert($republished, 'published version activation must republish its stored components');
$assert($reactivated['activeVersionId'] === $deleted['activeVersionId'], 'published version was not reactivated');

$staleConflict = false;
try {
    $service->createDraft(12740, $created['registryRevision'], 'Устаревший запрос', null, 'Листовая печать', $legacy, $actor);
} catch (RuntimeException $error) {
    $staleConflict = $error->getCode() === 409;
}
$assert($staleConflict, 'stale registry mutation must fail with CAS conflict');

echo "Calculator version registry service tests passed\n";
