<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;
use Prospektweb\Calc\Calculator\BundleHandler;

/**
 * Transactional authority for preset registry lifecycle writes.
 *
 * A duplicate is created from one source snapshot while the shared calculator
 * graph authority is held. Clone bytes, audit and both authoritative readbacks
 * therefore commit or roll back together; BundleHandler never owns a nested
 * transaction.
 */
final class PresetLifecycleMutationService
{
    public const CONTRACT = 'prospektweb.calc.preset-lifecycle-mutation/v1';
    public const AUDIT_TYPE_ID = 'PROSPEKTWEB_PRESET_LIFECYCLE_V1';

    private const MODULE_ID = 'prospektweb.calc';

    /** @var array<string,callable> */
    private array $adapters;

    /** @param array<string,callable> $adapters */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /** @return array<string,mixed> */
    public function duplicatePreset(int $sourcePresetId): array
    {
        if ($sourcePresetId <= 0) {
            throw new \InvalidArgumentException('Source preset ID must be positive.', 422);
        }

        $criticalSection = function ($authority, array $pinnedIblockIds) use ($sourcePresetId): array {
            $sourceBefore = $authority->readLockedPresetGraph($sourcePresetId);
            $sourceIdentityBefore = $this->loadIdentity($sourcePresetId, $pinnedIblockIds);
            $sourceBeforeHash = PresetMutationCoordinatorService::hashCanonical($sourceBefore);

            $newPresetId = isset($this->adapters['clone_locked'])
                ? (int)call_user_func(
                    $this->adapters['clone_locked'],
                    $sourcePresetId,
                    $pinnedIblockIds
                )
                : (new BundleHandler())->clonePresetLocked($sourcePresetId, $pinnedIblockIds);
            if ($newPresetId <= 0 || $newPresetId === $sourcePresetId) {
                throw new \RuntimeException('Preset clone returned an invalid identity.', 409);
            }

            $authority->refreshLockedState($sourcePresetId);
            $sourceAfter = $authority->readLockedPresetGraph($sourcePresetId);
            $cloneGraph = $authority->readLockedPresetGraph($newPresetId);
            $sourceAfterHash = PresetMutationCoordinatorService::hashCanonical($sourceAfter);
            if (!hash_equals($sourceBeforeHash, $sourceAfterHash)) {
                throw new \RuntimeException('Source preset changed while it was being duplicated.', 409);
            }
            $cloneIdentity = $this->loadIdentity($newPresetId, $pinnedIblockIds);

            $audit = [
                'contract' => self::CONTRACT,
                'actorId' => $this->actorId(),
                'action' => 'duplicate_preset',
                'sourcePresetId' => $sourcePresetId,
                'newPresetId' => $newPresetId,
                'sourceBeforeSha256' => $sourceBeforeHash,
                'sourceAfterSha256' => $sourceAfterHash,
                'cloneSha256' => PresetMutationCoordinatorService::hashCanonical($cloneGraph),
                'result' => 'success',
            ];
            $this->writeAudit($audit);

            return [
                'contract' => self::CONTRACT,
                'presetId' => $sourcePresetId,
                'newPresetId' => $newPresetId,
                'presetName' => (string)$cloneIdentity['name'],
                'sourceRevision' => (string)($sourceBefore['revision'] ?? $sourceBeforeHash),
                'cloneRevision' => (string)($cloneGraph['revision'] ?? $audit['cloneSha256']),
                'sourceIdentity' => $sourceIdentityBefore,
            ];
        };

        if (isset($this->adapters['with_source_authority'])) {
            return call_user_func(
                $this->adapters['with_source_authority'],
                $sourcePresetId,
                $criticalSection
            );
        }

        $authority = new CalculatorMutationAuthorityService();
        return $authority->withAuthorityLock(
            $sourcePresetId,
            static function (
                bool $_unusedProtection,
                array $pinnedIblockIds
            ) use ($authority, $criticalSection): array {
                return $criticalSection($authority, $pinnedIblockIds);
            }
        );
    }

    /** @return array<string,mixed> */
    public function createPreset(string $name, int $sectionId = 0): array
    {
        $name = trim($name);
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $nameLength > 200) {
            throw new \InvalidArgumentException('Preset name must contain 1 to 200 characters.', 422);
        }
        if ($sectionId < 0 || $sectionId > 9007199254740991) {
            throw new \InvalidArgumentException('Calculator section ID must be a safe non-negative integer.', 422);
        }

        return $this->withGlobalAuthority(function (array $pinnedIblockIds) use ($name, $sectionId): array {
            $newPresetId = isset($this->adapters['create_locked'])
                ? (int)call_user_func($this->adapters['create_locked'], $name, $pinnedIblockIds, $sectionId)
                : (new BundleHandler())->createStandalonePreset(
                    $name,
                    (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0),
                    $sectionId
                );
            if ($newPresetId <= 0) {
                throw new \RuntimeException('Preset creation returned an invalid identity.', 409);
            }
            $identity = $this->loadIdentity($newPresetId, $pinnedIblockIds);
            $identityHash = PresetMutationCoordinatorService::hashCanonical($identity);
            $audit = [
                'contract' => self::CONTRACT,
                'actorId' => $this->actorId(),
                'action' => 'create_preset',
                'newPresetId' => $newPresetId,
                'sectionId' => $sectionId,
                'identitySha256' => $identityHash,
                'result' => 'success',
            ];
            $this->writeAudit($audit);
            return [
                'contract' => self::CONTRACT,
                'presetId' => $newPresetId,
                'presetName' => (string)$identity['name'],
                'identityRevision' => $identityHash,
            ];
        });
    }

    /** @return mixed */
    private function withGlobalAuthority(callable $criticalSection)
    {
        if (isset($this->adapters['with_global_authority'])) {
            return call_user_func($this->adapters['with_global_authority'], $criticalSection);
        }
        if (!class_exists(Application::class)) {
            throw new \RuntimeException('Bitrix database is unavailable for preset lifecycle mutation.');
        }
        $connection = Application::getConnection();
        $authority = new CalculatorMutationAuthorityService();
        $connection->startTransaction();
        try {
            $locked = $authority->lockAllAuthority($connection);
            $pinnedIblockIds = is_array($locked['iblockIds'] ?? null) ? $locked['iblockIds'] : [];
            $result = $criticalSection($pinnedIblockIds);
            $connection->commitTransaction();
            return $result;
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw $error;
        }
    }

    /** @param array<string,int> $pinnedIblockIds @return array{id:int,name:string} */
    private function loadIdentity(int $presetId, array $pinnedIblockIds): array
    {
        if (isset($this->adapters['identity_loader'])) {
            $row = call_user_func($this->adapters['identity_loader'], $presetId, $pinnedIblockIds);
        } else {
            $presetIblockId = (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0);
            $row = \CIBlockElement::GetList(
                [],
                ['ID' => $presetId, 'IBLOCK_ID' => $presetIblockId],
                false,
                ['nTopCount' => 1],
                ['ID', 'IBLOCK_ID', 'NAME']
            )->Fetch();
        }
        if (!is_array($row)
            || (int)($row['id'] ?? $row['ID'] ?? 0) !== $presetId
            || trim((string)($row['name'] ?? $row['NAME'] ?? '')) === '') {
            throw new \RuntimeException('Preset lifecycle identity readback failed.', 409);
        }
        return [
            'id' => $presetId,
            'name' => trim((string)($row['name'] ?? $row['NAME'])),
        ];
    }

    /** @param array<string,mixed> $audit */
    private function writeAudit(array $audit): void
    {
        if (isset($this->adapters['audit'])) {
            $result = call_user_func($this->adapters['audit'], $audit);
        } else {
            if (!class_exists('CEventLog')) {
                throw new \RuntimeException('Bitrix audit log is unavailable.');
            }
            $description = json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($description)) {
                throw new \RuntimeException('Unable to encode preset lifecycle audit metadata.');
            }
            $result = \CEventLog::Add([
                'SEVERITY' => 'SECURITY',
                'AUDIT_TYPE_ID' => self::AUDIT_TYPE_ID,
                'MODULE_ID' => self::MODULE_ID,
                'ITEM_ID' => (string)($audit['sourcePresetId'] ?? $audit['newPresetId'] ?? ''),
                'DESCRIPTION' => $description,
            ]);
        }
        if ($result === false) {
            throw new \RuntimeException('Preset lifecycle audit write failed.');
        }
    }

    private function actorId(): int
    {
        if (isset($this->adapters['actor_id'])) {
            return max(0, (int)call_user_func($this->adapters['actor_id']));
        }
        $user = $GLOBALS['USER'] ?? null;
        return is_object($user) && method_exists($user, 'GetID') ? max(0, (int)$user->GetID()) : 0;
    }
}
