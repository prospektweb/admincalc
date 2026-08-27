<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

require_once __DIR__ . '/BitrixTransactionStateAuthority.php';

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
    public const CONTRACT = 'prospektweb.calc.calculator-versions/v2';

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
            } else {
                $migrated = $this->migrateLegacyDraftRows($state);
                if ((string)$state['calculatorName'] !== $presetName) {
                    $state['calculatorName'] = $presetName;
                    $state['updatedAt'] = $this->now();
                    $migrated = true;
                }
                if ($migrated) {
                    $this->saveState($presetId, $state);
                }
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
        return $this->createVersion(
            $presetId,
            $expectedRegistryRevision,
            $name,
            $basedOnVersionId,
            $presetName,
            $legacyWorkspace,
            $actor
        );
    }

    /**
     * Run a complete version-document mutation under the same per-calculator
     * transaction and row lock as the registry CAS. Nested service calls reuse
     * the transaction through BitrixTransactionStateAuthority.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function coordinateVersionMutation(int $presetId, callable $callback)
    {
        $this->assertPresetId($presetId);
        return $this->withLock($presetId, $callback);
    }

    /** @return array<string,mixed> */
    public function createVersion(
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
                $usedNumbers = array_map(
                    static fn(array $row): int => (int)($row['versionNo'] ?? 0),
                    $state['versions']
                );
                $versionNo = ($usedNumbers ? max($usedNumbers) : 0) + 1;
                $now = $this->now();
                $state['versions'][] = [
                    'versionId' => $this->newVersionId(),
                    'versionNo' => $versionNo,
                    'status' => self::STATUS_PUBLISHED,
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
                if (($state['activeVersionId'] ?? null) === $versionId) {
                    throw new \InvalidArgumentException('Активную версию нельзя удалить. Сначала активируйте другую.');
                }
                array_splice($state['versions'], $index, 1);
                return $state;
            }
        );
    }

    /**
     * Permanently remove explicitly selected non-active versions and their
     * documents. This is intentionally not a normal archive operation: callers
     * must supply the current registry CAS. Registry and runtime active
     * pointers must agree, version documents are deleted in the same Bitrix
     * transaction, and the active version is always protected.
     *
     * @param string[] $versionIds
     * @return array<string,mixed>
     */
    public function deleteInactiveVersions(
        int $presetId,
        string $expectedRegistryRevision,
        array $versionIds,
        string $presetName,
        array $legacyWorkspace,
        array $actor
    ): array {
        $this->assertRevision($expectedRegistryRevision);
        if (!array_is_list($versionIds) || $versionIds === [] || count($versionIds) > 100) {
            throw new \InvalidArgumentException('Для удаления нужен непустой список не более чем из 100 версий.');
        }
        $normalizedIds = [];
        foreach ($versionIds as $versionId) {
            if (!is_string($versionId)) {
                throw new \InvalidArgumentException('Идентификатор удаляемой версии недопустим.');
            }
            $this->assertVersionId($versionId);
            if (isset($normalizedIds[$versionId])) {
                throw new \InvalidArgumentException('Список удаляемых версий содержит дубликат.');
            }
            $normalizedIds[$versionId] = true;
        }
        $actor = $this->normalizeActor($actor);

        return $this->mutate(
            $presetId,
            $expectedRegistryRevision,
            $presetName,
            $legacyWorkspace,
            $actor,
            function (array $state) use ($presetId, $normalizedIds, $actor): array {
                $activeVersionId = is_string($state['activeVersionId'] ?? null)
                    ? (string)$state['activeVersionId']
                    : null;
                $runtime = $this->runtimePublicationMeta($presetId);
                $runtimeVersionId = is_array($runtime) && is_string($runtime['versionId'] ?? null)
                    ? (string)$runtime['versionId']
                    : null;
                if ($activeVersionId === null
                    || $runtimeVersionId === null
                    || !hash_equals($activeVersionId, $runtimeVersionId)) {
                    throw new \RuntimeException(
                        'Активная версия в реестре и публичном runtime не совпадает. Удаление остановлено.',
                        409
                    );
                }
                $activeRow = $this->findVersion($state, $activeVersionId);
                $registryContentHash = (string)($activeRow['contentHash'] ?? '');
                $runtimeContentHash = (string)($runtime['sourceContentHash'] ?? $runtime['contentHash'] ?? '');
                if (preg_match('/^[a-f0-9]{64}$/D', $registryContentHash) !== 1
                    || preg_match('/^[a-f0-9]{64}$/D', $runtimeContentHash) !== 1
                    || !hash_equals($registryContentHash, $runtimeContentHash)) {
                    throw new \RuntimeException(
                        'Hash активного полного bundle в реестре и публичном runtime не совпадает. Удаление остановлено.',
                        409
                    );
                }
                foreach (array_keys($normalizedIds) as $versionId) {
                    $row = $this->findVersion($state, $versionId);
                    if (hash_equals($activeVersionId, $versionId)) {
                        throw new \InvalidArgumentException('Активную версию нельзя удалить.');
                    }
                    if (!in_array(
                        (string)($row['status'] ?? ''),
                        [self::STATUS_PUBLISHED, self::STATUS_ARCHIVED],
                        true
                    )) {
                        throw new \InvalidArgumentException(
                            'Постоянно удалить можно только неактивную опубликованную или архивную версию.'
                        );
                    }
                }
                if (count($state['versions']) <= count($normalizedIds)) {
                    throw new \InvalidArgumentException('Нельзя удалить все версии калькулятора.');
                }
                foreach (array_keys($normalizedIds) as $versionId) {
                    $this->deleteVersionDocuments($presetId, $versionId);
                    if ($this->versionDocumentsExist($presetId, $versionId)) {
                        throw new \RuntimeException(
                            'Не удалось подтвердить удаление документов версии ' . $versionId . '.',
                            409
                        );
                    }
                }
                $state['versions'] = array_values(array_filter(
                    $state['versions'],
                    static fn(array $row): bool => !isset($normalizedIds[(string)($row['versionId'] ?? '')])
                ));
                foreach ($state['versions'] as &$row) {
                    $baseId = is_string($row['basedOnVersionId'] ?? null)
                        ? (string)$row['basedOnVersionId']
                        : null;
                    if ($baseId !== null && isset($normalizedIds[$baseId])) {
                        $row['basedOnVersionId'] = null;
                        $row['updatedAt'] = $this->now();
                        $row['updatedBy'] = $actor;
                    }
                }
                unset($row);
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
                    throw new \InvalidArgumentException('Активировать можно только видимую редактируемую версию.');
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
                throw new \InvalidArgumentException('Активировать можно только видимую редактируемую версию.');
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

    /** Activate the current editable work of any visible version. */
    public function coordinatedActivateVersion(
        int $presetId,
        string $expectedRegistryRevision,
        string $versionId,
        string $expectedContentHash,
        string $presetName,
        array $legacyWorkspace,
        array $actor,
        callable $publisher
    ): array {
        $this->assertPresetId($presetId);
        $this->assertRevision($expectedRegistryRevision);
        $this->assertVersionId($versionId);
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedContentHash) !== 1) {
            throw new \InvalidArgumentException('Hash редактируемой версии некорректен.');
        }
        $actor = $this->normalizeActor($actor);
        return $this->withLock($presetId, function () use ($presetId, $expectedRegistryRevision, $versionId, $expectedContentHash, $presetName, $legacyWorkspace, $actor, $publisher): array {
            $state = $this->loadState($presetId) ?? $this->bootstrap($presetId, $presetName, $legacyWorkspace, $actor);
            if (!hash_equals($this->revision($state), $expectedRegistryRevision)) {
                throw new \RuntimeException('Список версий изменён в другой вкладке. Перезагрузите калькулятор.', 409);
            }
            $index = $this->findVersionIndex($state, $versionId);
            if (($state['versions'][$index]['status'] ?? null) !== self::STATUS_PUBLISHED) {
                throw new \InvalidArgumentException('Скрытую версию нужно сначала вернуть в список.');
            }
            $bundle = $this->bundleMeta($presetId, $versionId, true);
            if (!hash_equals($expectedContentHash, (string)$bundle['contentHash'])) {
                throw new \RuntimeException('Версия изменилась в другой вкладке. Повторите активацию с актуальными данными.', 409);
            }
            $result = call_user_func($publisher);
            $published = is_array($result['published'] ?? null) ? $result['published'] : null;
            $runtime = is_array($result['runtime'] ?? null) ? $result['runtime'] : null;
            if (!is_array($published)
                || (int)($published['published']['revision'] ?? 0) <= 0
                || preg_match('/^[a-f0-9]{64}$/D', (string)($published['published']['compileHash'] ?? '')) !== 1
                || !is_array($runtime)
                || (string)($runtime['versionId'] ?? '') !== $versionId
                || !hash_equals($expectedContentHash, (string)($runtime['sourceContentHash'] ?? ''))
                || !is_array($runtime['sourceComponentHashes'] ?? null)) {
                throw new \RuntimeException('Сервер не подтвердил активацию полного bundle версии.', 409);
            }
            $now = $this->now();
            $state['activeVersionId'] = $versionId;
            $state['versions'][$index]['updatedAt'] = $now;
            $state['versions'][$index]['updatedBy'] = $actor;
            $state['versions'][$index]['publishedAt'] = $now;
            $state['versions'][$index]['publishedBy'] = $actor;
            $state['versions'][$index]['legacyFormRevision'] = (int)$published['published']['revision'];
            $state['versions'][$index]['legacyCompileHash'] = (string)$published['published']['compileHash'];
            // Registry deployment metadata tracks the exact editable source
            // bundle that was activated. The runtime content hash belongs to
            // the enriched immutable snapshot and intentionally differs.
            $state['versions'][$index]['contentHash'] = (string)$runtime['sourceContentHash'];
            $state['versions'][$index]['componentHashes'] = $runtime['sourceComponentHashes'];
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
            $nextVersionNo = $versions === []
                ? 1
                : max(array_map(static fn(array $row): int => (int)($row['versionNo'] ?? 0), $versions)) + 1;
            $versions[] = [
                'versionId' => $this->newVersionId(),
                'versionNo' => $nextVersionNo,
                'status' => self::STATUS_PUBLISHED,
                'name' => 'Версия ' . $nextVersionNo,
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
            $leftActive = ($left['versionId'] ?? null) === $activeId;
            $rightActive = ($right['versionId'] ?? null) === $activeId;
            if ($leftActive !== $rightActive) {
                return $leftActive ? -1 : 1;
            }
            return (int)($right['versionNo'] ?? 0) <=> (int)($left['versionNo'] ?? 0);
        });
        foreach ($rows as &$row) {
            $row['active'] = ($row['versionId'] ?? null) === $activeId;
            $row['status'] = ($row['status'] ?? null) === self::STATUS_ARCHIVED
                ? self::STATUS_ARCHIVED
                : 'VERSION';
            $row['deployedContentHash'] = $row['contentHash'] ?? null;
            $row['deployedComponentHashes'] = $row['componentHashes'] ?? null;
            $row['lastActivatedAt'] = $row['publishedAt'] ?? null;
            $row['lastActivatedBy'] = $row['publishedBy'] ?? null;
            $bundle = $this->bundleMeta((int)$state['presetId'], (string)$row['versionId'], false);
            $row['snapshotComplete'] = $bundle !== null
                && ($bundle['readiness']['complete'] ?? true) === true;
            $row['snapshotReadiness'] = $bundle['readiness'] ?? [
                'complete' => false,
                'missingComponents' => CalculatorVersionBundleDocumentService::COMPONENTS,
                'requiresRebuild' => true,
            ];
            if ($bundle !== null) {
                $row['workContentHash'] = (string)$bundle['contentHash'];
                $row['workComponentHashes'] = $bundle['componentHashes'];
            } else {
                $row['workContentHash'] = null;
                $row['workComponentHashes'] = null;
            }
            // Compatibility aliases for clients transitioning from v1. They
            // now always identify mutable authoring work, never deployment.
            $row['contentHash'] = $row['workContentHash'];
            $row['componentHashes'] = $row['workComponentHashes'];
            $deployedHash = (string)($row['deployedContentHash'] ?? '');
            $workHash = (string)($row['workContentHash'] ?? '');
            $row['hasUnactivatedChanges'] = $workHash !== ''
                && ($deployedHash === '' || !hash_equals($deployedHash, $workHash));
        }
        unset($row);

        $deploymentProblem = null;
        try {
            $runtime = $this->runtimePublicationMeta((int)$state['presetId']);
        } catch (\Throwable $ignored) {
            $runtime = null;
            $deploymentProblem = 'bundle_invalid';
        }
        if ($runtime === null && $deploymentProblem === null) {
            $deploymentProblem = 'rebuild_required';
        }
        if (is_array($runtime)
            && ($activeId === null
                || !is_string($runtime['versionId'] ?? null)
                || !hash_equals($activeId, (string)$runtime['versionId']))) {
            $deploymentProblem = 'registry_runtime_mismatch';
        }
        if (is_array($runtime) && $deploymentProblem === null && $activeId !== null) {
            $activeRow = $this->findVersion($state, $activeId);
            $registryContentHash = (string)($activeRow['contentHash'] ?? '');
            $runtimeContentHash = (string)($runtime['sourceContentHash'] ?? $runtime['contentHash'] ?? '');
            $registryComponentHashes = $activeRow['componentHashes'] ?? null;
            $runtimeComponentHashes = $runtime['sourceComponentHashes'] ?? $runtime['componentHashes'] ?? null;
            if (preg_match('/^[a-f0-9]{64}$/D', $registryContentHash) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $runtimeContentHash) !== 1
                || !hash_equals($registryContentHash, $runtimeContentHash)
                || !is_array($registryComponentHashes)
                || !is_array($runtimeComponentHashes)
                || $this->encodeCanonical($registryComponentHashes) !== $this->encodeCanonical($runtimeComponentHashes)) {
                $deploymentProblem = 'registry_runtime_hash_mismatch';
            }
        }
        $activeDeployment = is_array($runtime) ? [
            'versionId' => (string)($runtime['versionId'] ?? ''),
            'activationId' => $runtime['activationId'] ?? null,
            'snapshotVersionId' => $runtime['snapshotVersionId'] ?? null,
            'contentHash' => (string)($runtime['contentHash'] ?? ''),
            'sourceContentHash' => (string)($runtime['sourceContentHash'] ?? $runtime['contentHash'] ?? ''),
            'activatedAt' => $runtime['activatedAt'] ?? null,
        ] : null;

        return [
            'contract' => self::CONTRACT,
            'presetId' => (int)$state['presetId'],
            'calculatorName' => (string)$state['calculatorName'],
            'activeVersionId' => $activeId,
            'activeDeployment' => $activeDeployment,
            'deploymentReadiness' => [
                'ready' => $deploymentProblem === null,
                'problem' => $deploymentProblem,
            ],
            'registryRevision' => $this->revision($state),
            'versions' => $rows,
        ];
    }

    /**
     * Legacy DRAFT rows become ordinary numbered editable versions. This is a
     * lossless metadata migration: identifiers, documents and provenance stay
     * unchanged, and activation state is not inferred.
     */
    private function migrateLegacyDraftRows(array &$state): bool
    {
        $changed = false;
        $nextVersionNo = 0;
        foreach ($state['versions'] as $row) {
            $nextVersionNo = max($nextVersionNo, (int)($row['versionNo'] ?? 0));
        }
        foreach ($state['versions'] as &$row) {
            if (($row['status'] ?? null) !== self::STATUS_DRAFT) {
                continue;
            }
            $nextVersionNo++;
            $row['status'] = self::STATUS_PUBLISHED;
            $row['versionNo'] = $nextVersionNo;
            if (in_array((string)($row['name'] ?? ''), ['Первичный черновик', 'Текущий черновик'], true)) {
                $row['name'] = 'Версия ' . $nextVersionNo;
            }
            $row['updatedAt'] = $this->now();
            $changed = true;
        }
        unset($row);
        if ($changed) {
            $state['updatedAt'] = $this->now();
        }
        return $changed;
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
        $name = mb_strtolower($this->optionName($presetId));
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $sql = "SELECT VALUE FROM b_option WHERE BINARY MODULE_ID='"
            . $helper->forSql(self::MODULE_ID)
            . "' AND BINARY NAME='" . $helper->forSql($name)
            . "' AND (SITE_ID IS NULL OR SITE_ID='')";
        if (BitrixTransactionStateAuthority::isActive($connection)) {
            $sql .= ' FOR UPDATE';
        }
        $rows = [];
        $result = $connection->query($sql);
        while (is_array($row = $result->fetch())) {
            $rows[] = $row;
            if (count($rows) > 1) break;
        }
        if (count($rows) > 1) {
            throw new \RuntimeException('Version registry contains a duplicate option row.', 409);
        }
        return count($rows) === 1 ? (string)($rows[0]['VALUE'] ?? '') : '';
    }

    private function setRaw(int $presetId, string $raw): void
    {
        if (isset($this->adapters['set'])) {
            call_user_func($this->adapters['set'], $this->optionName($presetId), $raw);
            return;
        }
        $name = mb_strtolower($this->optionName($presetId));
        $connection = Application::getConnection();
        if (!BitrixTransactionStateAuthority::isActive($connection)) {
            Option::set(self::MODULE_ID, $this->optionName($presetId), $raw);
            return;
        }
        $helper = $connection->getSqlHelper();
        $moduleSql = $helper->forSql(self::MODULE_ID);
        $nameSql = $helper->forSql($name);
        $rawSql = $helper->forSql($raw);
        if ($this->getRaw($presetId) === '') {
            $connection->queryExecute(
                "INSERT INTO b_option (MODULE_ID, NAME, VALUE, DESCRIPTION) VALUES ('"
                . $moduleSql . "','" . $nameSql . "','" . $rawSql . "','')"
            );
        } else {
            $connection->queryExecute(
                "UPDATE b_option SET VALUE='" . $rawSql
                . "' WHERE BINARY MODULE_ID='" . $moduleSql
                . "' AND BINARY NAME='" . $nameSql . "' AND (SITE_ID IS NULL OR SITE_ID='')"
            );
        }
        if (!hash_equals($raw, $this->getRaw($presetId))) {
            throw new \RuntimeException('Version registry write was not confirmed.', 409);
        }
    }

    private function withLock(int $presetId, callable $callback)
    {
        if (isset($this->adapters['lock'])) {
            return call_user_func($this->adapters['lock'], $presetId, $callback);
        }
        $connection = Application::getConnection();
        $ownsTransaction = !BitrixTransactionStateAuthority::isActive($connection);
        if ($ownsTransaction) {
            $connection->startTransaction();
        }
        try {
            $helper = $connection->getSqlHelper();
            $expectedModules = [self::MODULE_ID, 'prospektweb.frontcalc'];
            $result = $connection->query(
                "SELECT ID FROM b_module WHERE BINARY ID IN ('"
                . implode("','", array_map([$helper, 'forSql'], $expectedModules))
                . "') ORDER BY BINARY ID FOR UPDATE"
            );
            $lockedModules = [];
            while (is_array($row = $result->fetch())) {
                $lockedModules[] = (string)($row['ID'] ?? '');
            }
            sort($expectedModules, SORT_STRING);
            if ($lockedModules !== $expectedModules) {
                throw new \RuntimeException('Строки авторитета модулей калькулятора не найдены точно.', 409);
            }
            $result = $callback();
            if ($ownsTransaction) {
                $connection->commitTransaction();
            }
            return $result;
        } catch (\Throwable $error) {
            if ($ownsTransaction) {
                try {
                    $connection->rollbackTransaction();
                } catch (\Throwable $ignored) {
                }
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

    /** @return array<string,mixed>|null */
    private function runtimePublicationMeta(int $presetId): ?array
    {
        if (isset($this->adapters['runtime_meta'])) {
            $runtime = call_user_func($this->adapters['runtime_meta'], $presetId);
            return is_array($runtime) ? $runtime : null;
        }
        if (!class_exists(CalculatorVersionRuntimePublicationService::class)) {
            return null;
        }
        return (new CalculatorVersionRuntimePublicationService())->resolve($presetId);
    }

    private function deleteVersionDocuments(int $presetId, string $versionId): void
    {
        if (isset($this->adapters['delete_version_documents'])) {
            call_user_func($this->adapters['delete_version_documents'], $presetId, $versionId);
            return;
        }
        (new CalculatorVersionFormDocumentService())->delete($presetId, $versionId);
        (new CalculatorVersionBundleDocumentService())->delete($presetId, $versionId);
    }

    private function versionDocumentsExist(int $presetId, string $versionId): bool
    {
        if (isset($this->adapters['version_documents_exist'])) {
            return (bool)call_user_func($this->adapters['version_documents_exist'], $presetId, $versionId);
        }
        return (new CalculatorVersionFormDocumentService())->has($presetId, $versionId)
            || (new CalculatorVersionBundleDocumentService())->has($presetId, $versionId);
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
