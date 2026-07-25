<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Modules;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Prospektweb\Calc\Modules\Storage\ModuleAuditTable;
use Prospektweb\Calc\Modules\Storage\ModuleFamilyTable;
use Prospektweb\Calc\Modules\Storage\ModuleInstanceTable;
use Prospektweb\Calc\Modules\Storage\ModuleSnapshotTable;
use Prospektweb\Calc\Modules\Storage\ModuleVersionTable;

final class ModuleLifecycleService
{
    public function createFamily(string $code, string $name, string $description, int $actorId): int
    {
        ModuleAccess::assertCurrentUser('draft.create');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $code)) {
            throw new \InvalidArgumentException('Module family code is invalid');
        }
        return $this->transaction(function () use ($code, $name, $description, $actorId): int {
            $result = ModuleFamilyTable::add([
                'CODE' => $code,
                'NAME' => trim($name),
                'DESCRIPTION' => $description,
                'REVISION' => 1,
                'CREATED_BY' => $actorId,
                'UPDATED_BY' => $actorId,
            ]);
            $this->assertResult($result->isSuccess(), $result->getErrorMessages());
            $familyId = (int)$result->getId();
            $this->audit('family.create', $actorId, $familyId, null, null, null, ['code' => $code]);
            return $familyId;
        });
    }

    public function createDraft(int $familyId, array $module, int $actorId): int
    {
        ModuleAccess::assertCurrentUser('draft.create');
        if (($module['status'] ?? null) !== 'draft') {
            throw new \DomainException('New module version must start as draft');
        }
        ModuleValidator::assertValid($module);
        return $this->transaction(function () use ($familyId, $module, $actorId): int {
            $family = $this->lockRow(ModuleFamilyTable::getTableName(), $familyId);
            if ($family['CODE'] !== $module['familyId']) {
                throw new \InvalidArgumentException('Module family does not match the draft contract');
            }
            $result = ModuleVersionTable::add([
                'FAMILY_ID' => $familyId,
                'VERSION' => $module['version'],
                'KIND' => $module['kind'],
                'STATUS' => 'draft',
                'CONTENT_JSON' => CanonicalJson::encode($module),
                'CONTENT_HASH' => $module['contentHash'],
                'REVISION' => 1,
                'CREATED_BY' => $actorId,
                'UPDATED_BY' => $actorId,
            ]);
            $this->assertResult($result->isSuccess(), $result->getErrorMessages());
            $versionId = (int)$result->getId();
            $this->audit('version.draft.create', $actorId, $familyId, $versionId);
            return $versionId;
        });
    }

    public function updateDraft(int $versionId, array $module, int $expectedRevision, int $actorId): int
    {
        ModuleAccess::assertCurrentUser('draft.edit');
        if (($module['status'] ?? null) !== 'draft') {
            throw new \DomainException('Draft update must retain draft status');
        }
        ModuleValidator::assertValid($module);
        return $this->transaction(function () use ($versionId, $module, $expectedRevision, $actorId): int {
            $row = $this->lockRow(ModuleVersionTable::getTableName(), $versionId);
            ModuleLifecyclePolicy::assertContentMutable((string)$row['STATUS']);
            ModuleLifecyclePolicy::assertRevision((int)$row['REVISION'], $expectedRevision);
            if ($row['VERSION'] !== $module['version']) {
                throw new \DomainException('A version number cannot be changed in place');
            }
            $nextRevision = $expectedRevision + 1;
            $result = ModuleVersionTable::update($versionId, [
                'KIND' => $module['kind'],
                'CONTENT_JSON' => CanonicalJson::encode($module),
                'CONTENT_HASH' => $module['contentHash'],
                'REVISION' => $nextRevision,
                'UPDATED_AT' => new DateTime(),
                'UPDATED_BY' => $actorId,
            ]);
            $this->assertResult($result->isSuccess(), $result->getErrorMessages());
            $this->audit('version.draft.update', $actorId, (int)$row['FAMILY_ID'], $versionId, null, null, [
                'fromRevision' => $expectedRevision,
                'toRevision' => $nextRevision,
            ]);
            return $nextRevision;
        });
    }

    public function publish(int $versionId, int $expectedRevision, array $testResults, int $actorId): int
    {
        ModuleAccess::assertCurrentUser('version.publish');
        return $this->transaction(function () use ($versionId, $expectedRevision, $testResults, $actorId): int {
            $row = $this->lockRow(ModuleVersionTable::getTableName(), $versionId);
            ModuleLifecyclePolicy::assertRevision((int)$row['REVISION'], $expectedRevision);
            $module = json_decode((string)$row['CONTENT_JSON'], true, 512, JSON_THROW_ON_ERROR);
            ModuleLifecyclePolicy::assertPublishable($module, $testResults);
            ModuleLifecyclePolicy::assertTransition((string)$row['STATUS'], 'published');
            $module['status'] = 'published';
            $nextRevision = $expectedRevision + 1;
            $result = ModuleVersionTable::update($versionId, [
                'STATUS' => 'published',
                'CONTENT_JSON' => CanonicalJson::encode($module),
                'TEST_RESULTS_JSON' => CanonicalJson::encode($testResults),
                'REVISION' => $nextRevision,
                'PUBLISHED_AT' => new DateTime(),
                'PUBLISHED_BY' => $actorId,
                'UPDATED_AT' => new DateTime(),
                'UPDATED_BY' => $actorId,
            ]);
            $this->assertResult($result->isSuccess(), $result->getErrorMessages());
            $this->audit('version.publish', $actorId, (int)$row['FAMILY_ID'], $versionId, null, null, [
                'contentHash' => $row['CONTENT_HASH'],
                'revision' => $nextRevision,
            ]);
            return $nextRevision;
        });
    }

    public function changeStatus(
        int $versionId,
        string $status,
        int $expectedRevision,
        int $actorId
    ): int {
        $operation = $status === 'deprecated' ? 'version.deprecate' : 'version.archive';
        ModuleAccess::assertCurrentUser($operation);
        return $this->transaction(function () use ($versionId, $status, $expectedRevision, $actorId): int {
            $row = $this->lockRow(ModuleVersionTable::getTableName(), $versionId);
            ModuleLifecyclePolicy::assertRevision((int)$row['REVISION'], $expectedRevision);
            ModuleLifecyclePolicy::assertTransition((string)$row['STATUS'], $status);
            $module = json_decode((string)$row['CONTENT_JSON'], true, 512, JSON_THROW_ON_ERROR);
            $module['status'] = $status;
            $nextRevision = $expectedRevision + 1;
            $result = ModuleVersionTable::update($versionId, [
                'STATUS' => $status,
                'CONTENT_JSON' => CanonicalJson::encode($module),
                'REVISION' => $nextRevision,
                'UPDATED_AT' => new DateTime(),
                'UPDATED_BY' => $actorId,
            ]);
            $this->assertResult($result->isSuccess(), $result->getErrorMessages());
            $this->audit("version.{$status}", $actorId, (int)$row['FAMILY_ID'], $versionId);
            return $nextRevision;
        });
    }

    public function bindInstance(
        int $presetId,
        int $versionId,
        array $bindings,
        array $entityBindings,
        array $dependencyLock,
        array $context,
        int $actorId
    ): int {
        ModuleAccess::assertCurrentUser('instance.bind');
        return $this->transaction(function () use (
            $presetId,
            $versionId,
            $bindings,
            $entityBindings,
            $dependencyLock,
            $context,
            $actorId
        ): int {
            $version = $this->lockRow(ModuleVersionTable::getTableName(), $versionId);
            if ($version['STATUS'] !== 'published') {
                throw new \DomainException('Only a published module version can be bound');
            }
            $result = ModuleInstanceTable::add([
                'INSTANCE_UID' => bin2hex(random_bytes(16)),
                'PRESET_ID' => $presetId,
                'VERSION_ID' => $versionId,
                'REVISION' => 1,
                'BINDINGS_JSON' => CanonicalJson::encode($bindings),
                'ENTITY_BINDINGS_JSON' => CanonicalJson::encode($entityBindings),
                'DEPENDENCY_LOCK_JSON' => CanonicalJson::encode($dependencyLock),
                'CONTEXT_JSON' => CanonicalJson::encode($context),
                'CREATED_BY' => $actorId,
                'UPDATED_BY' => $actorId,
            ]);
            $this->assertResult($result->isSuccess(), $result->getErrorMessages());
            $instanceId = (int)$result->getId();
            $this->audit('instance.bind', $actorId, (int)$version['FAMILY_ID'], $versionId, $instanceId, null, [
                'presetId' => $presetId,
                'contentHash' => $version['CONTENT_HASH'],
            ]);
            return $instanceId;
        });
    }

    public function appendSnapshot(
        int $instanceId,
        int $expectedRevision,
        array $snapshot,
        ?array $legacySnapshot,
        int $actorId
    ): int {
        ModuleAccess::assertCurrentUser('instance.bind');
        return $this->transaction(function () use (
            $instanceId,
            $expectedRevision,
            $snapshot,
            $legacySnapshot,
            $actorId
        ): int {
            $instance = $this->lockRow(ModuleInstanceTable::getTableName(), $instanceId);
            ModuleLifecyclePolicy::assertRevision((int)$instance['REVISION'], $expectedRevision);
            $snapshotJson = CanonicalJson::encode($snapshot);
            $snapshotHash = hash('sha256', $snapshotJson);
            $result = ModuleSnapshotTable::add([
                'SNAPSHOT_UID' => bin2hex(random_bytes(16)),
                'INSTANCE_ID' => $instanceId,
                'INSTANCE_REVISION' => $expectedRevision,
                'PRESET_ID' => (int)$instance['PRESET_ID'],
                'SNAPSHOT_JSON' => $snapshotJson,
                'SNAPSHOT_HASH' => $snapshotHash,
                'LEGACY_SNAPSHOT_JSON' => $legacySnapshot === null ? null : CanonicalJson::encode($legacySnapshot),
                'CREATED_BY' => $actorId,
            ]);
            $this->assertResult($result->isSuccess(), $result->getErrorMessages());
            $snapshotId = (int)$result->getId();
            $update = ModuleInstanceTable::update($instanceId, [
                'SNAPSHOT_ID' => $snapshotId,
                'UPDATED_AT' => new DateTime(),
                'UPDATED_BY' => $actorId,
            ]);
            $this->assertResult($update->isSuccess(), $update->getErrorMessages());
            $this->audit('snapshot.append', $actorId, null, (int)$instance['VERSION_ID'], $instanceId, $snapshotId, [
                'snapshotHash' => $snapshotHash,
                'instanceRevision' => $expectedRevision,
            ]);
            return $snapshotId;
        });
    }

    public function updateInstance(
        int $instanceId,
        int $versionId,
        array $bindings,
        array $entityBindings,
        array $dependencyLock,
        array $context,
        int $expectedRevision,
        int $actorId
    ): int {
        ModuleAccess::assertCurrentUser('instance.bind');
        return $this->transaction(function () use (
            $instanceId,
            $versionId,
            $bindings,
            $entityBindings,
            $dependencyLock,
            $context,
            $expectedRevision,
            $actorId
        ): int {
            $instance = $this->lockRow(ModuleInstanceTable::getTableName(), $instanceId);
            ModuleLifecyclePolicy::assertRevision((int)$instance['REVISION'], $expectedRevision);
            $version = $this->lockRow(ModuleVersionTable::getTableName(), $versionId);
            if ($version['STATUS'] !== 'published') {
                throw new \DomainException('An instance update requires a published exact version');
            }
            $nextRevision = $expectedRevision + 1;
            $result = ModuleInstanceTable::update($instanceId, [
                'VERSION_ID' => $versionId,
                'REVISION' => $nextRevision,
                'BINDINGS_JSON' => CanonicalJson::encode($bindings),
                'ENTITY_BINDINGS_JSON' => CanonicalJson::encode($entityBindings),
                'DEPENDENCY_LOCK_JSON' => CanonicalJson::encode($dependencyLock),
                'CONTEXT_JSON' => CanonicalJson::encode($context),
                'SNAPSHOT_ID' => null,
                'UPDATED_AT' => new DateTime(),
                'UPDATED_BY' => $actorId,
            ]);
            $this->assertResult($result->isSuccess(), $result->getErrorMessages());
            $this->audit('instance.update', $actorId, (int)$version['FAMILY_ID'], $versionId, $instanceId, null, [
                'fromRevision' => $expectedRevision,
                'toRevision' => $nextRevision,
                'contentHash' => $version['CONTENT_HASH'],
            ]);
            return $nextRevision;
        });
    }

    public function rollbackToSnapshot(
        int $instanceId,
        int $snapshotId,
        int $expectedRevision,
        int $actorId
    ): int {
        ModuleAccess::assertCurrentUser('snapshot.rollback');
        return $this->transaction(function () use ($instanceId, $snapshotId, $expectedRevision, $actorId): int {
            $instance = $this->lockRow(ModuleInstanceTable::getTableName(), $instanceId);
            ModuleLifecyclePolicy::assertRevision((int)$instance['REVISION'], $expectedRevision);
            $snapshot = $this->lockRow(ModuleSnapshotTable::getTableName(), $snapshotId);
            if ((int)$snapshot['INSTANCE_ID'] !== $instanceId) {
                throw new \DomainException('Snapshot does not belong to the module instance');
            }
            $nextRevision = $expectedRevision + 1;
            $result = ModuleInstanceTable::update($instanceId, [
                'SNAPSHOT_ID' => $snapshotId,
                'REVISION' => $nextRevision,
                'UPDATED_AT' => new DateTime(),
                'UPDATED_BY' => $actorId,
            ]);
            $this->assertResult($result->isSuccess(), $result->getErrorMessages());
            $this->audit(
                'snapshot.rollback',
                $actorId,
                null,
                (int)$instance['VERSION_ID'],
                $instanceId,
                $snapshotId,
                [
                    'fromRevision' => $expectedRevision,
                    'toRevision' => $nextRevision,
                    'snapshotHash' => $snapshot['SNAPSHOT_HASH'],
                ]
            );
            return $nextRevision;
        });
    }

    public function listVersionUsage(int $versionId): array
    {
        ModuleAccess::assertCurrentUser('view');
        return ModuleInstanceTable::getList([
            'filter' => ['=VERSION_ID' => $versionId],
            'order' => ['PRESET_ID' => 'ASC', 'ID' => 'ASC'],
        ])->fetchAll();
    }

    public function listCatalog(bool $includeDrafts = true): array
    {
        ModuleAccess::assertCurrentUser('view');
        $filter = $includeDrafts ? [] : ['@STATUS' => ['published', 'deprecated']];
        $families = ModuleFamilyTable::getList(['order' => ['NAME' => 'ASC', 'ID' => 'ASC']])->fetchAll();
        $versions = ModuleVersionTable::getList([
            'filter' => $filter,
            'order' => ['FAMILY_ID' => 'ASC', 'ID' => 'DESC'],
        ])->fetchAll();
        $byFamily = [];
        foreach ($versions as $version) {
            $version['CONTENT'] = json_decode((string)$version['CONTENT_JSON'], true, 512, JSON_THROW_ON_ERROR);
            unset($version['CONTENT_JSON']);
            $byFamily[(int)$version['FAMILY_ID']][] = $version;
        }
        foreach ($families as &$family) {
            $family['VERSIONS'] = $byFamily[(int)$family['ID']] ?? [];
        }
        unset($family);
        return $families;
    }

    public function listPresetInstances(int $presetId): array
    {
        ModuleAccess::assertCurrentUser('view');
        $rows = ModuleInstanceTable::getList([
            'filter' => ['=PRESET_ID' => $presetId],
            'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
        ])->fetchAll();
        foreach ($rows as &$row) {
            $row = $this->decodeInstanceRow($row);
        }
        unset($row);
        return $rows;
    }

    public function listInstanceSnapshots(int $instanceId): array
    {
        ModuleAccess::assertCurrentUser('view');
        $rows = ModuleSnapshotTable::getList([
            'filter' => ['=INSTANCE_ID' => $instanceId],
            'order' => ['ID' => 'DESC'],
        ])->fetchAll();
        foreach ($rows as &$row) {
            $row['SNAPSHOT'] = json_decode((string)$row['SNAPSHOT_JSON'], true, 512, JSON_THROW_ON_ERROR);
            unset($row['SNAPSHOT_JSON'], $row['LEGACY_SNAPSHOT_JSON']);
        }
        unset($row);
        return $rows;
    }

    public function listAudit(?int $familyId = null, ?int $instanceId = null, int $limit = 100): array
    {
        ModuleAccess::assertCurrentUser('view');
        $filter = [];
        if ($familyId !== null) {
            $filter['=FAMILY_ID'] = $familyId;
        }
        if ($instanceId !== null) {
            $filter['=INSTANCE_ID'] = $instanceId;
        }
        $rows = ModuleAuditTable::getList([
            'filter' => $filter,
            'order' => ['ID' => 'DESC'],
            'limit' => max(1, min($limit, 500)),
        ])->fetchAll();
        foreach ($rows as &$row) {
            $row['PAYLOAD'] = json_decode((string)$row['PAYLOAD_JSON'], true, 512, JSON_THROW_ON_ERROR);
            unset($row['PAYLOAD_JSON']);
        }
        unset($row);
        return $rows;
    }

    public function previewMaterialization(int $versionId, array $instance, array $options): array
    {
        ModuleAccess::assertCurrentUser('view');
        $version = ModuleVersionTable::getByPrimary($versionId)->fetch();
        if (!$version) {
            throw new \RuntimeException("Module version not found: {$versionId}");
        }
        $module = json_decode((string)$version['CONTENT_JSON'], true, 512, JSON_THROW_ON_ERROR);
        $instance = $this->normalizeInstanceContract($module, $instance, (int)($instance['revision'] ?? 1));
        return ModuleMaterializer::materialize($module, $instance, $options);
    }

    public function applyInstance(
        int $presetId,
        int $versionId,
        array $instance,
        array $options,
        ?int $instanceRowId,
        ?int $expectedRevision,
        ?array $legacySnapshot,
        int $actorId
    ): array {
        ModuleAccess::assertCurrentUser('instance.bind');
        return $this->transaction(function () use (
            $presetId,
            $versionId,
            $instance,
            $options,
            $instanceRowId,
            $expectedRevision,
            $legacySnapshot,
            $actorId
        ): array {
            $version = $this->lockRow(ModuleVersionTable::getTableName(), $versionId);
            if ($version['STATUS'] !== 'published') {
                throw new \DomainException('Applying an instance requires an exact published module version');
            }
            $module = json_decode((string)$version['CONTENT_JSON'], true, 512, JSON_THROW_ON_ERROR);
            $revision = 1;
            $instanceUid = trim((string)($instance['instanceId'] ?? ''));
            if ($instanceUid === '') {
                $instanceUid = bin2hex(random_bytes(16));
            }
            $existing = null;
            if ($instanceRowId !== null) {
                $existing = $this->lockRow(ModuleInstanceTable::getTableName(), $instanceRowId);
                ModuleLifecyclePolicy::assertRevision((int)$existing['REVISION'], (int)$expectedRevision);
                if ((int)$existing['PRESET_ID'] !== $presetId) {
                    throw new \DomainException('Module instance cannot move between presets');
                }
                $revision = (int)$existing['REVISION'] + 1;
                $instanceUid = (string)$existing['INSTANCE_UID'];
            }
            $instance['instanceId'] = $instanceUid;
            $instance['presetId'] = $presetId;
            $instance = $this->normalizeInstanceContract($module, $instance, $revision);
            $snapshotUid = trim((string)($options['snapshotId'] ?? ''));
            if ($snapshotUid === '') {
                $snapshotUid = bin2hex(random_bytes(16));
            }
            $options['snapshotId'] = $snapshotUid;
            $options['presetRevision'] = max(1, (int)($options['presetRevision'] ?? $revision));
            $options['createdAt'] = (string)($options['createdAt'] ?? gmdate('c'));
            $options['resolvedBy'] = (string)($options['resolvedBy'] ?? ('user:' . $actorId));
            $options['resolverVersion'] = (string)($options['resolverVersion'] ?? ModuleMaterializer::RESOLVER_VERSION);
            if ($legacySnapshot !== null) {
                $options['legacySnapshotHash'] = CanonicalJson::hash($legacySnapshot);
            }
            $snapshot = ModuleMaterializer::materialize($module, $instance, $options);
            $context = [
                'activationCondition' => $instance['activationCondition'] ?? null,
                'globalAssignments' => $instance['globalAssignments'] ?? [],
                'customFieldValues' => $instance['customFieldValues'] ?? (object)[],
                'provenance' => $instance['provenance'] ?? (object)[],
            ];
            $instanceFields = [
                'VERSION_ID' => $versionId,
                'REVISION' => $revision,
                'ENABLED' => ($instance['enabled'] ?? true) ? 1 : 0,
                'SORT' => max(0, (int)($instance['order'] ?? 500)),
                'BINDINGS_JSON' => CanonicalJson::encode($instance['bindings'] ?? []),
                'ENTITY_BINDINGS_JSON' => CanonicalJson::encode($instance['entityBindings'] ?? []),
                'DEPENDENCY_LOCK_JSON' => CanonicalJson::encode($instance['dependencyLock'] ?? []),
                'CONTEXT_JSON' => CanonicalJson::encode($context),
                'SNAPSHOT_ID' => null,
                'UPDATED_AT' => new DateTime(),
                'UPDATED_BY' => $actorId,
            ];
            if ($existing === null) {
                $result = ModuleInstanceTable::add($instanceFields + [
                    'INSTANCE_UID' => $instanceUid,
                    'PRESET_ID' => $presetId,
                    'CREATED_BY' => $actorId,
                ]);
                $this->assertResult($result->isSuccess(), $result->getErrorMessages());
                $instanceRowId = (int)$result->getId();
            } else {
                $result = ModuleInstanceTable::update((int)$instanceRowId, $instanceFields);
                $this->assertResult($result->isSuccess(), $result->getErrorMessages());
            }
            $snapshotResult = ModuleSnapshotTable::add([
                'SNAPSHOT_UID' => $snapshotUid,
                'INSTANCE_ID' => $instanceRowId,
                'INSTANCE_REVISION' => $revision,
                'PRESET_ID' => $presetId,
                'SNAPSHOT_JSON' => CanonicalJson::encode($snapshot),
                'SNAPSHOT_HASH' => $snapshot['snapshotHash'],
                'LEGACY_SNAPSHOT_JSON' => $legacySnapshot === null ? null : CanonicalJson::encode($legacySnapshot),
                'CREATED_BY' => $actorId,
            ]);
            $this->assertResult($snapshotResult->isSuccess(), $snapshotResult->getErrorMessages());
            $snapshotRowId = (int)$snapshotResult->getId();
            $linkResult = ModuleInstanceTable::update($instanceRowId, [
                'SNAPSHOT_ID' => $snapshotRowId,
                'UPDATED_AT' => new DateTime(),
                'UPDATED_BY' => $actorId,
            ]);
            $this->assertResult($linkResult->isSuccess(), $linkResult->getErrorMessages());
            $this->audit(
                $existing === null ? 'instance.apply' : 'instance.update.apply',
                $actorId,
                (int)$version['FAMILY_ID'],
                $versionId,
                $instanceRowId,
                $snapshotRowId,
                [
                    'presetId' => $presetId,
                    'revision' => $revision,
                    'contentHash' => $version['CONTENT_HASH'],
                    'snapshotHash' => $snapshot['snapshotHash'],
                ]
            );
            return [
                'instanceId' => $instanceRowId,
                'instanceUid' => $instanceUid,
                'revision' => $revision,
                'snapshotId' => $snapshotRowId,
                'snapshotUid' => $snapshotUid,
                'snapshotHash' => $snapshot['snapshotHash'],
                'snapshot' => $snapshot,
            ];
        });
    }

    private function lockRow(string $table, int $id): array
    {
        $row = Application::getConnection()
            ->query("SELECT * FROM `{$table}` WHERE `ID` = {$id} FOR UPDATE")
            ->fetch();
        if (!$row) {
            throw new \RuntimeException("Module storage row not found: {$table}#{$id}");
        }
        return $row;
    }

    private function normalizeInstanceContract(array $module, array $instance, int $revision): array
    {
        $instance['schema'] = 'prospektweb.calc.module-instance/v1';
        $instance['familyId'] = $module['familyId'];
        $instance['version'] = $module['version'];
        $instance['contentHash'] = $module['contentHash'];
        $instance['revision'] = $revision;
        $instance['bindings'] = is_array($instance['bindings'] ?? null) ? $instance['bindings'] : [];
        $instance['entityBindings'] = is_array($instance['entityBindings'] ?? null) ? $instance['entityBindings'] : [];
        $instance['dependencyLock'] = is_array($instance['dependencyLock'] ?? null) ? $instance['dependencyLock'] : [];
        $instance['provenance'] = is_array($instance['provenance'] ?? null)
            ? $instance['provenance']
            : ['createdAt' => gmdate('c'), 'createdBy' => 'unknown', 'legacyElementIds' => []];
        return $instance;
    }

    private function decodeInstanceRow(array $row): array
    {
        foreach ([
            'BINDINGS_JSON' => 'BINDINGS',
            'ENTITY_BINDINGS_JSON' => 'ENTITY_BINDINGS',
            'DEPENDENCY_LOCK_JSON' => 'DEPENDENCY_LOCK',
            'CONTEXT_JSON' => 'CONTEXT',
        ] as $source => $target) {
            $row[$target] = ($row[$source] ?? '') === ''
                ? []
                : json_decode((string)$row[$source], true, 512, JSON_THROW_ON_ERROR);
            unset($row[$source]);
        }
        return $row;
    }

    private function transaction(callable $callback): mixed
    {
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $result = $callback();
            $connection->commitTransaction();
            return $result;
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw $error;
        }
    }

    private function audit(
        string $action,
        int $actorId,
        ?int $familyId = null,
        ?int $versionId = null,
        ?int $instanceId = null,
        ?int $snapshotId = null,
        array $payload = []
    ): void {
        $result = ModuleAuditTable::add([
            'ACTION' => $action,
            'ACTOR_ID' => $actorId,
            'FAMILY_ID' => $familyId,
            'VERSION_ID' => $versionId,
            'INSTANCE_ID' => $instanceId,
            'SNAPSHOT_ID' => $snapshotId,
            'PAYLOAD_JSON' => CanonicalJson::encode($payload),
        ]);
        if (!$result->isSuccess()) {
            throw new \RuntimeException('Unable to append module audit: ' . implode('; ', $result->getErrorMessages()));
        }
    }

    private function assertResult(bool $success, array $errors): void
    {
        if (!$success) {
            throw new \RuntimeException(implode('; ', $errors));
        }
    }
}
