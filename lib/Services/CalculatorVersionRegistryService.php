<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

/**
 * Version-first metadata authority for one calculator preset.
 *
 * The first read performs a lossless metadata migration from the current
 * form-first publication. Component snapshots are introduced separately;
 * this registry never pretends that legacy form-only history is a complete
 * calculator version.
 */
final class CalculatorVersionRegistryService
{
    public const CONTRACT = 'prospektweb.calc.calculator-versions/v1';

    private const MODULE_ID = 'prospektweb.calc';
    private const STORAGE_VERSION = 1;
    private const MAX_BYTES = 524288;
    private const STATUS_DRAFT = 'DRAFT';
    private const STATUS_PUBLISHED = 'PUBLISHED';
    private const STATUS_ARCHIVED = 'ARCHIVED';

    /** @var array<string,callable> */
    private array $adapters;

    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /** @return array<string,mixed> */
    public function loadWorkspace(int $presetId, string $presetName, array $legacyWorkspace, array $actor): array
    {
        $this->assertPresetId($presetId);
        $presetName = $this->normalizeName($presetName, 'Калькулятор #' . $presetId);
        $actor = $this->normalizeActor($actor);

        return $this->withLock($presetId, function () use ($presetId, $presetName, $legacyWorkspace, $actor): array {
            $state = $this->loadState($presetId);
            if ($state === null) {
                $state = $this->bootstrap($presetId, $presetName, $legacyWorkspace, $actor);
                $this->saveState($presetId, $state);
            } elseif ((string)$state['calculatorName'] !== $presetName) {
                $state['calculatorName'] = $presetName;
                $state['updatedAt'] = $this->now();
                $this->saveState($presetId, $state);
            }
            return $this->publicWorkspace($state);
        });
    }

    /** @return array<string,mixed> */
    public function createDraft(
        int $presetId,
        string $expectedRegistryRevision,
        string $name,
        ?string $basedOnVersionId,
        string $presetName,
        array $legacyWorkspace,
        array $actor
    ): array {
        $this->assertRevision($expectedRegistryRevision);
        $name = $this->normalizeName($name, 'Новая версия');
        $actor = $this->normalizeActor($actor);

        return $this->mutate($presetId, $expectedRegistryRevision, $presetName, $legacyWorkspace, $actor,
            function (array $state) use ($name, $basedOnVersionId, $actor): array {
                $baseId = $basedOnVersionId;
                if ($baseId !== null) {
                    $this->assertVersionId($baseId);
                    $this->findVersion($state, $baseId);
                } elseif (is_string($state['activeVersionId'] ?? null)) {
                    $baseId = (string)$state['activeVersionId'];
                }
                $now = $this->now();
                $state['versions'][] = [
                    'versionId' => $this->newVersionId(),
                    'versionNo' => null,
                    'status' => self::STATUS_DRAFT,
                    'name' => $name,
                    'basedOnVersionId' => $baseId,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                    'publishedAt' => null,
                    'createdBy' => $actor,
                    'updatedBy' => $actor,
                    'publishedBy' => null,
                    'legacyFormRevision' => null,
                    'legacyCompileHash' => null,
                    'contentHash' => null,
                    'componentHashes' => null,
                ];
                return $state;
            }
        );
    }

    /** @return array<string,mixed> */
    public function renameVersion(
        int $presetId,
        string $expectedRegistryRevision,
        string $versionId,
        string $name,
        string $presetName,
        array $legacyWorkspace,
        array $actor
    ): array {
        $this->assertRevision($expectedRegistryRevision);
        $this->assertVersionId($versionId);
        $name = $this->normalizeName($name, '');
        if ($name === '') {
            throw new \InvalidArgumentException('Название версии не может быть пустым.');
        }
        $actor = $this->normalizeActor($actor);

        return $this->mutate($presetId, $expectedRegistryRevision, $presetName, $legacyWorkspace, $actor,
            function (array $state) use ($versionId, $name, $actor): array {
                $index = $this->findVersionIndex($state, $versionId);
                $state['versions'][$index]['name'] = $name;
                $state['versions'][$index]['updatedAt'] = $this->now();
                $state['versions'][$index]['updatedBy'] = $actor;
                return $state;
            }
        );
    }

    /** @return array<string,mixed> */
    public function deleteDraft(
        int $presetId,
        string $expectedRegistryRevision,
        string $versionId,
        string $presetName,
        array $legacyWorkspace,
        array $actor
    ): array {
        $this->assertRevision($expectedRegistryRevision);
        $this->assertVersionId($versionId);
        $actor = $this->normalizeActor($actor);

        return $this->mutate($presetId, $expectedRegistryRevision, $presetName, $legacyWorkspace, $actor,
            function (array $state) use ($versionId): array {
                $index = $this->findVersionIndex($state, $versionId);
                if (($state['versions'][$index]['status'] ?? null) !== self::STATUS_DRAFT) {
                    throw new \InvalidArgumentException('Удалить можно только черновик. Опубликованная версия архивируется.');
                }
                array_splice($state['versions'], $index, 1);
                return $state;
            }
        );
    }

    /** @return array<string,mixed> */
    public function archivePublished(
        int $presetId,
        string $expectedRegistryRevision,
        string $versionId,
        bool $restore,
        string $presetName,
        array $legacyWorkspace,
        array $actor
    ): array {
        $this->assertRevision($expectedRegistryRevision);
        $this->assertVersionId($versionId);
        $actor = $this->normalizeActor($actor);

        return $this->mutate($presetId, $expectedRegistryRevision, $presetName, $legacyWorkspace, $actor,
            function (array $state) use ($versionId, $restore, $actor): array {
                $index = $this->findVersionIndex($state, $versionId);
                $row = $state['versions'][$index];
                if ($restore) {
                    if (($row['status'] ?? null) !== self::STATUS_ARCHIVED) {
                        throw new \InvalidArgumentException('Восстановить можно только архивную версию.');
                    }
                    $state['versions'][$index]['status'] = self::STATUS_PUBLISHED;
                } else {
                    if (($row['status'] ?? null) !== self::STATUS_PUBLISHED) {
                        throw new \InvalidArgumentException('Архивировать можно только опубликованную версию.');
                    }
                    if (($state['activeVersionId'] ?? null) === $versionId) {
                        throw new \InvalidArgumentException('Активную версию нельзя архивировать. Сначала активируйте другую.');
                    }
                    $state['versions'][$index]['status'] = self::STATUS_ARCHIVED;
                }
                $state['versions'][$index]['updatedAt'] = $this->now();
                $state['versions'][$index]['updatedBy'] = $actor;
                return $state;
            }
        );
    }

    /** @return array<string,mixed> */
    public function publishAndActivateDraft(
        int $presetId,
        string $expectedRegistryRevision,
        string $versionId,
        int $legacyPublishedRevision,
        string $legacyCompileHash,
        string $presetName,
        array $legacyWorkspace,
        array $actor
    ): array {
        $this->assertRevision($expectedRegistryRevision);
        $this->assertVersionId($versionId);
        if ($legacyPublishedRevision <= 0 || preg_match('/^[a-f0-9]{64}$/D', $legacyCompileHash) !== 1) {
            throw new \InvalidArgumentException('Сервер не подтвердил опубликованный снимок формы.');
        }
        $actor = $this->normalizeActor($actor);

        return $this->mutate($presetId, $expectedRegistryRevision, $presetName, $legacyWorkspace, $actor,
            function (array $state) use ($versionId, $legacyPublishedRevision, $legacyCompileHash, $actor): array {
                $index = $this->findVersionIndex($state, $versionId);
                if (($state['versions'][$index]['status'] ?? null) !== self::STATUS_DRAFT) {
                    throw new \InvalidArgumentException('Опубликовать и активировать можно только черновик.');
                }
                $usedNumbers = array_map(
                    static fn(array $row): int => (int)($row['versionNo'] ?? 0),
                    $state['versions']
                );
                $versionNo = max($legacyPublishedRevision, ($usedNumbers ? max($usedNumbers) : 0) + 1);
                $now = $this->now();
                $state['versions'][$index]['versionNo'] = $versionNo;
                $state['versions'][$index]['status'] = self::STATUS_PUBLISHED;
                $state['versions'][$index]['updatedAt'] = $now;
                $state['versions'][$index]['publishedAt'] = $now;
                $state['versions'][$index]['updatedBy'] = $actor;
                $state['versions'][$index]['publishedBy'] = $actor;
                $state['versions'][$index]['legacyFormRevision'] = $legacyPublishedRevision;
                $state['versions'][$index]['legacyCompileHash'] = $legacyCompileHash;
                $bundle = $this->bundleMeta((int)$state['presetId'], $versionId, true);
                $state['versions'][$index]['contentHash'] = $bundle['contentHash'];
                $state['versions'][$index]['componentHashes'] = $bundle['componentHashes'];
                $state['activeVersionId'] = $versionId;
                return $state;
            }
        );
    }

    /**
     * Execute the legacy form publication and registry switch under the same
     * database transaction. The publisher must return the authoritative
     * published form-first workspace.
     *
     * @return array<string,mixed>
     */
    public function coordinatedPublishAndActivateDraft(
        int $presetId,
        string $expectedRegistryRevision,
        string $versionId,
        string $presetName,
        array $legacyWorkspace,
        array $actor,
        callable $publisher
    ): array {
        $this->assertPresetId($presetId);
        $this->assertRevision($expectedRegistryRevision);
        $this->assertVersionId($versionId);
        $actor = $this->normalizeActor($actor);

        return $this->withLock($presetId, function () use (
            $presetId,
            $expectedRegistryRevision,
            $versionId,
            $presetName,
            $legacyWorkspace,
            $actor,
            $publisher
        ): array {
            $state = $this->loadState($presetId)
                ?? $this->bootstrap($presetId, $presetName, $legacyWorkspace, $actor);
            if (!hash_equals($this->revision($state), $expectedRegistryRevision)) {
                throw new \RuntimeException('Список версий изменён в другой вкладке. Перезагрузите калькулятор.', 409);
            }
            $index = $this->findVersionIndex($state, $versionId);
            if (($state['versions'][$index]['status'] ?? null) !== self::STATUS_DRAFT) {
                throw new \InvalidArgumentException('Опубликовать и активировать можно только черновик.');
            }
            $published = call_user_func($publisher);
            if (!is_array($published)) {
                throw new \RuntimeException('Сервер не вернул опубликованный снимок формы.');
            }
            $publishedRevision = (int)($published['published']['revision'] ?? 0);
            $compileHash = (string)($published['published']['compileHash'] ?? '');
            if ($publishedRevision <= 0 || preg_match('/^[a-f0-9]{64}$/D', $compileHash) !== 1) {
                throw new \RuntimeException('Сервер не подтвердил опубликованный снимок формы.');
            }
            $usedNumbers = array_map(
                static fn(array $row): int => (int)($row['versionNo'] ?? 0),
                $state['versions']
            );
            $now = $this->now();
            $state['versions'][$index]['versionNo'] = max($publishedRevision, ($usedNumbers ? max($usedNumbers) : 0) + 1);
            $state['versions'][$index]['status'] = self::STATUS_PUBLISHED;
            $state['versions'][$index]['updatedAt'] = $now;
            $state['versions'][$index]['publishedAt'] = $now;
            $state['versions'][$index]['updatedBy'] = $actor;
            $state['versions'][$index]['publishedBy'] = $actor;
            $state['versions'][$index]['legacyFormRevision'] = $publishedRevision;
            $state['versions'][$index]['legacyCompileHash'] = $compileHash;
            $bundle = $this->bundleMeta($presetId, $versionId, true);
            $state['versions'][$index]['contentHash'] = $bundle['contentHash'];
            $state['versions'][$index]['componentHashes'] = $bundle['componentHashes'];
            $state['activeVersionId'] = $versionId;
            $state['updatedAt'] = $now;
            $this->saveState($presetId, $state);
            return $this->publicWorkspace($state);
        });
    }

    /** Reactivate an immutable published version after its stored components were republished to runtime. */
    public function coordinatedActivatePublished(
        int $presetId,
        string $expectedRegistryRevision,
        string $versionId,
        string $presetName,
        array $legacyWorkspace,
        array $actor,
        callable $publisher
    ): array {
        $this->assertPresetId($presetId);
        $this->assertRevision($expectedRegistryRevision);
        $this->assertVersionId($versionId);
        $actor = $this->normalizeActor($actor);
        return $this->withLock($presetId, function () use ($presetId, $expectedRegistryRevision, $versionId, $presetName, $legacyWorkspace, $actor, $publisher): array {
            $state = $this->loadState($presetId) ?? $this->bootstrap($presetId, $presetName, $legacyWorkspace, $actor);
            if (!hash_equals($this->revision($state), $expectedRegistryRevision)) {
                throw new \RuntimeException('Список версий изменён в другой вкладке. Перезагрузите калькулятор.', 409);
            }
            $index = $this->findVersionIndex($state, $versionId);
            if (($state['versions'][$index]['status'] ?? null) !== self::STATUS_PUBLISHED) {
                throw new \InvalidArgumentException('Активировать повторно можно только опубликованную версию.');
            }
            $bundle = $this->bundleMeta($presetId, $versionId, true);
            $storedHash = (string)($state['versions'][$index]['contentHash'] ?? '');
            if ($storedHash === '' || !hash_equals($storedHash, (string)$bundle['contentHash'])) {
                throw new \RuntimeException('Полный снимок опубликованной версии отсутствует или изменён.', 409);
            }
            $published = call_user_func($publisher);
            if (!is_array($published)
                || (int)($published['published']['revision'] ?? 0) <= 0
                || preg_match('/^[a-f0-9]{64}$/D', (string)($published['published']['compileHash'] ?? '')) !== 1) {
                throw new \RuntimeException('Сервер не подтвердил повторную публикацию версии.');
            }
            $now = $this->now();
            $state['activeVersionId'] = $versionId;
            $state['versions'][$index]['updatedAt'] = $now;
            $state['versions'][$index]['updatedBy'] = $actor;
            $state['updatedAt'] = $now;
            $this->saveState($presetId, $state);
            return $this->publicWorkspace($state);
        });
    }

    /** @return array<string,mixed> */
    private function mutate(
        int $presetId,
        string $expectedRegistryRevision,
        string $presetName,
        array $legacyWorkspace,
        array $actor,
        callable $mutation
    ): array {
        $this->assertPresetId($presetId);
        return $this->withLock($presetId, function () use ($presetId, $expectedRegistryRevision, $presetName, $legacyWorkspace, $actor, $mutation): array {
            $state = $this->loadState($presetId);
            if ($state === null) {
                $state = $this->bootstrap($presetId, $presetName, $legacyWorkspace, $actor);
            }
            $actualRevision = $this->revision($state);
            if (!hash_equals($actualRevision, $expectedRegistryRevision)) {
                throw new \RuntimeException('Список версий изменён в другой вкладке. Перезагрузите калькулятор.', 409);
            }
            $next = $mutation($state);
            if (!is_array($next)) {
                throw new \RuntimeException('Version registry mutation did not return state.');
            }
            $next['updatedAt'] = $this->now();
            $this->saveState($presetId, $next);
            return $this->publicWorkspace($next);
        });
    }

    /** @return array<string,mixed> */
    private function bootstrap(int $presetId, string $presetName, array $legacyWorkspace, array $actor): array
    {
        $now = $this->now();
        $versions = [];
        $activeVersionId = null;
        $published = is_array($legacyWorkspace['published'] ?? null) ? $legacyWorkspace['published'] : null;
        $publishedRevision = (int)($published['revision'] ?? 0);
        $compileHash = (string)($published['compileHash'] ?? '');
        if ($publishedRevision > 0 && preg_match('/^[a-f0-9]{64}$/D', $compileHash) === 1) {
            $activeVersionId = 'v_' . substr($compileHash, 0, 20);
            $publishedAt = null;
            foreach ((array)($legacyWorkspace['history'] ?? []) as $historyRow) {
                if ((int)($historyRow['revision'] ?? 0) === $publishedRevision) {
                    $publishedAt = is_string($historyRow['publishedAt'] ?? null) ? $historyRow['publishedAt'] : null;
                    break;
                }
            }
            $versions[] = [
                'versionId' => $activeVersionId,
                'versionNo' => $publishedRevision,
                'status' => self::STATUS_PUBLISHED,
                'name' => 'Версия ' . $publishedRevision,
                'basedOnVersionId' => null,
                'createdAt' => $publishedAt ?: $now,
                'updatedAt' => $publishedAt ?: $now,
                'publishedAt' => $publishedAt ?: $now,
                'createdBy' => $actor,
                'updatedBy' => $actor,
                'publishedBy' => $actor,
                'legacyFormRevision' => $publishedRevision,
                'legacyCompileHash' => $compileHash,
                'contentHash' => null,
                'componentHashes' => null,
            ];
        }
        $diff = is_array($legacyWorkspace['compile']['diff'] ?? null) ? $legacyWorkspace['compile']['diff'] : [];
        if ($activeVersionId === null || $diff !== []) {
            $versions[] = [
                'versionId' => $this->newVersionId(),
                'versionNo' => null,
                'status' => self::STATUS_DRAFT,
                'name' => $activeVersionId === null ? 'Первичный черновик' : 'Текущий черновик',
                'basedOnVersionId' => $activeVersionId,
                'createdAt' => $now,
                'updatedAt' => $now,
                'publishedAt' => null,
                'createdBy' => $actor,
                'updatedBy' => $actor,
                'publishedBy' => null,
                'legacyFormRevision' => null,
                'legacyCompileHash' => null,
                'contentHash' => null,
                'componentHashes' => null,
            ];
        }

        return [
            'storageVersion' => self::STORAGE_VERSION,
            'presetId' => $presetId,
            'calculatorName' => $presetName,
            'activeVersionId' => $activeVersionId,
            'versions' => $versions,
            'createdAt' => $now,
            'updatedAt' => $now,
        ];
    }

    /** @return array<string,mixed> */
    private function publicWorkspace(array $state): array
    {
        $rows = array_values($state['versions']);
        $activeId = is_string($state['activeVersionId'] ?? null) ? $state['activeVersionId'] : null;
        usort($rows, static function (array $left, array $right) use ($activeId): int {
            $leftDraft = ($left['status'] ?? null) === self::STATUS_DRAFT;
            $rightDraft = ($right['status'] ?? null) === self::STATUS_DRAFT;
            if ($leftDraft !== $rightDraft) {
                return $leftDraft ? -1 : 1;
            }
            $leftActive = ($left['versionId'] ?? null) === $activeId;
            $rightActive = ($right['versionId'] ?? null) === $activeId;
            if ($leftActive !== $rightActive) {
                return $leftActive ? -1 : 1;
            }
            if ($leftDraft && $rightDraft) {
                return strcmp((string)$right['updatedAt'], (string)$left['updatedAt']);
            }
            return (int)($right['versionNo'] ?? 0) <=> (int)($left['versionNo'] ?? 0);
        });
        foreach ($rows as &$row) {
            $row['active'] = ($row['versionId'] ?? null) === $activeId;
            $bundle = $this->bundleMeta((int)$state['presetId'], (string)$row['versionId'], false);
            $row['snapshotComplete'] = $bundle !== null
                && ($bundle['readiness']['complete'] ?? true) === true;
            $row['snapshotReadiness'] = $bundle['readiness'] ?? [
                'complete' => false,
                'missingComponents' => CalculatorVersionBundleDocumentService::COMPONENTS,
                'requiresRebuild' => true,
            ];
            if ($bundle !== null) {
                $row['contentHash'] = (string)$bundle['contentHash'];
                $row['componentHashes'] = $bundle['componentHashes'];
            } else {
                $row['contentHash'] = null;
                $row['componentHashes'] = null;
            }
        }
        unset($row);

        return [
            'contract' => self::CONTRACT,
            'presetId' => (int)$state['presetId'],
            'calculatorName' => (string)$state['calculatorName'],
            'activeVersionId' => $activeId,
            'registryRevision' => $this->revision($state),
            'versions' => $rows,
        ];
    }

    /** @return array<string,mixed>|null */
    private function loadState(int $presetId): ?array
    {
        $raw = $this->getRaw($presetId);
        if ($raw === '') {
            return null;
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new \RuntimeException('Version registry exceeds the safe storage limit.');
        }
        $state = json_decode($raw, true);
        if (!is_array($state)
            || (int)($state['storageVersion'] ?? 0) !== self::STORAGE_VERSION
            || (int)($state['presetId'] ?? 0) !== $presetId
            || !is_string($state['calculatorName'] ?? null)
            || !is_array($state['versions'] ?? null)) {
            throw new \RuntimeException('Хранилище версий калькулятора повреждено или имеет неизвестную версию.');
        }
        foreach ($state['versions'] as $row) {
            if (!array_key_exists('contentHash', $row)) $row['contentHash'] = null;
            if (!array_key_exists('componentHashes', $row)) $row['componentHashes'] = null;
            $this->assertStoredVersion($row);
        }
        foreach ($state['versions'] as &$row) {
            if (!array_key_exists('contentHash', $row)) $row['contentHash'] = null;
            if (!array_key_exists('componentHashes', $row)) $row['componentHashes'] = null;
        }
        unset($row);
        return $state;
    }

    private function saveState(int $presetId, array $state): void
    {
        $encoded = $this->encodeCanonical($state);
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Version registry exceeds the safe 512 KB limit.');
        }
        $this->setRaw($presetId, $encoded);
        $readBack = $this->loadState($presetId);
        if (!is_array($readBack) || !hash_equals($this->revision($state), $this->revision($readBack))) {
            throw new \RuntimeException('Не удалось подтвердить сохранение списка версий.');
        }
    }

    private function revision(array $state): string
    {
        return hash('sha256', $this->encodeCanonical($state));
    }

    private function encodeCanonical($value): string
    {
        $canonicalize = function ($node) use (&$canonicalize) {
            if (!is_array($node)) {
                return $node;
            }
            if (array_values($node) === $node) {
                return array_map($canonicalize, $node);
            }
            ksort($node, SORT_STRING);
            foreach ($node as $key => $child) {
                $node[$key] = $canonicalize($child);
            }
            return $node;
        };
        $encoded = json_encode($canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Не удалось сериализовать version registry.');
        }
        return $encoded;
    }

    private function getRaw(int $presetId): string
    {
        if (isset($this->adapters['get'])) {
            return (string)call_user_func($this->adapters['get'], $this->optionName($presetId));
        }
        return (string)Option::get(self::MODULE_ID, $this->optionName($presetId), '');
    }

    private function setRaw(int $presetId, string $raw): void
    {
        if (isset($this->adapters['set'])) {
            call_user_func($this->adapters['set'], $this->optionName($presetId), $raw);
            return;
        }
        Option::set(self::MODULE_ID, $this->optionName($presetId), $raw);
    }

    private function withLock(int $presetId, callable $callback)
    {
        if (isset($this->adapters['lock'])) {
            return call_user_func($this->adapters['lock'], $presetId, $callback);
        }
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $helper = $connection->getSqlHelper();
            $module = $connection->query(
                "SELECT ID FROM b_module WHERE ID='" . $helper->forSql(self::MODULE_ID) . "' FOR UPDATE"
            )->fetch();
            if (!is_array($module)) {
                throw new \RuntimeException('Строка авторитета модуля калькулятора не найдена.', 409);
            }
            $result = $callback();
            $connection->commitTransaction();
            return $result;
        } catch (\Throwable $error) {
            try {
                $connection->rollbackTransaction();
            } catch (\Throwable $ignored) {
            }
            throw $error;
        }
    }

    private function now(): string
    {
        return isset($this->adapters['now'])
            ? (string)call_user_func($this->adapters['now'])
            : date(DATE_ATOM);
    }

    private function newVersionId(): string
    {
        $id = isset($this->adapters['id'])
            ? (string)call_user_func($this->adapters['id'])
            : 'v_' . bin2hex(random_bytes(10));
        $this->assertVersionId($id);
        return $id;
    }

    private function optionName(int $presetId): string
    {
        return 'CALC_VERSIONS_' . $presetId;
    }

    private function findVersionIndex(array $state, string $versionId): int
    {
        foreach ($state['versions'] as $index => $row) {
            if (($row['versionId'] ?? null) === $versionId) {
                return (int)$index;
            }
        }
        throw new \InvalidArgumentException('Версия калькулятора не найдена.');
    }

    private function findVersion(array $state, string $versionId): array
    {
        return $state['versions'][$this->findVersionIndex($state, $versionId)];
    }

    private function normalizeName(string $name, string $fallback): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') {
            return $fallback;
        }
        if (mb_strlen($name) > 200) {
            throw new \InvalidArgumentException('Название не должно быть длиннее 200 символов.');
        }
        return $name;
    }

    private function normalizeActor(array $actor): array
    {
        $id = (int)($actor['id'] ?? 0);
        $name = trim((string)($actor['name'] ?? ''));
        if ($id <= 0) {
            throw new \InvalidArgumentException('Не удалось определить пользователя Bitrix.');
        }
        return ['id' => $id, 'name' => $name !== '' ? $name : 'Пользователь #' . $id];
    }

    private function assertStoredVersion($row): void
    {
        if (!is_array($row)) {
            throw new \RuntimeException('Version registry contains an invalid row.');
        }
        $this->assertVersionId((string)($row['versionId'] ?? ''));
        if (!in_array($row['status'] ?? null, [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED], true)) {
            throw new \RuntimeException('Version registry contains an invalid status.');
        }
        if (($row['status'] ?? null) === self::STATUS_DRAFT && ($row['versionNo'] ?? null) !== null) {
            throw new \RuntimeException('Draft version must not have a published number.');
        }
        if (($row['status'] ?? null) !== self::STATUS_DRAFT && (int)($row['versionNo'] ?? 0) <= 0) {
            throw new \RuntimeException('Published version must have a positive number.');
        }
        $contentHash = $row['contentHash'] ?? null;
        if ($contentHash !== null && preg_match('/^[a-f0-9]{64}$/D', (string)$contentHash) !== 1) {
            throw new \RuntimeException('Version registry contains an invalid content hash.');
        }
        $componentHashes = $row['componentHashes'] ?? null;
        if ($componentHashes !== null) {
            if (!is_array($componentHashes)) throw new \RuntimeException('Version registry contains invalid component hashes.');
            foreach (CalculatorVersionBundleDocumentService::COMPONENTS as $component) {
                if (preg_match('/^[a-f0-9]{64}$/D', (string)($componentHashes[$component] ?? '')) !== 1) {
                    throw new \RuntimeException('Version registry contains an invalid ' . $component . ' hash.');
                }
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function bundleMeta(int $presetId, string $versionId, bool $required): ?array
    {
        $bundle = isset($this->adapters['bundle_meta'])
            ? call_user_func($this->adapters['bundle_meta'], $presetId, $versionId)
            : null;
        if ($bundle === null && $required) {
            throw new \RuntimeException('Полный снимок версии не сформирован.', 409);
        }
        if ($bundle !== null
            && (!is_array($bundle)
                || preg_match('/^[a-f0-9]{64}$/D', (string)($bundle['contentHash'] ?? '')) !== 1
                || !is_array($bundle['componentHashes'] ?? null))) {
            throw new \RuntimeException('Манифест полного снимка версии повреждён.', 409);
        }
        if ($required && ($bundle['readiness']['complete'] ?? true) !== true) {
            throw new \RuntimeException('Полный снимок версии требует пересборки перед публикацией.', 409);
        }
        return is_array($bundle) ? $bundle : null;
    }

    private function assertPresetId(int $presetId): void
    {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('presetId must be positive.');
        }
    }

    private function assertRevision(string $revision): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new \InvalidArgumentException('expectedRegistryRevision must be a lowercase SHA-256.');
        }
    }

    private function assertVersionId(string $versionId): void
    {
        if (preg_match('/^v_[a-f0-9]{16,40}$/D', $versionId) !== 1) {
            throw new \InvalidArgumentException('versionId must be a canonical calculator version identifier.');
        }
    }
}
