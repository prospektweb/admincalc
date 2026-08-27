<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

require_once __DIR__ . '/BitrixTransactionStateAuthority.php';

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
    public const VERSION_WORKING_CODE_PREFIX = 'prospektweb-version-work-';

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

    /** @return array<string,mixed> */
    public function createVersionWorkingPreset(
        string $name,
        int $calculatorPresetId,
        string $versionId
    ): array {
        $this->assertPresetId($calculatorPresetId);
        $this->assertVersionId($versionId);
        $name = trim($name);
        if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 200) {
            throw new \InvalidArgumentException('Working preset name must contain 1 to 200 characters.', 422);
        }
        return $this->withGlobalAuthority(function (array $pinnedIblockIds) use (
            $name,
            $calculatorPresetId,
            $versionId
        ): array {
            $codeSeed = self::workingCodePrefix($calculatorPresetId, $versionId) . 'graph';
            $workingPresetId = isset($this->adapters['create_working_locked'])
                ? (int)call_user_func(
                    $this->adapters['create_working_locked'],
                    $name,
                    $codeSeed,
                    $pinnedIblockIds
                )
                : (new BundleHandler())->createStandalonePreset(
                    $name,
                    (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0),
                    0,
                    $codeSeed,
                    false
                );
            $identity = $this->loadWorkingIdentity($workingPresetId, $pinnedIblockIds);
            $this->assertWorkingIdentity($identity, $calculatorPresetId, $versionId);
            $this->writeAudit([
                'contract' => self::CONTRACT,
                'actorId' => $this->actorId(),
                'action' => 'create_version_working_preset',
                'sourcePresetId' => $calculatorPresetId,
                'newPresetId' => $workingPresetId,
                'versionId' => $versionId,
                'result' => 'success',
            ]);
            return [
                'contract' => self::CONTRACT,
                'presetId' => $workingPresetId,
                'presetName' => (string)$identity['name'],
            ];
        });
    }

    /** @return array<string,mixed> */
    public function markVersionWorkingPreset(
        int $workingPresetId,
        int $calculatorPresetId,
        string $versionId
    ): array {
        $this->assertPresetId($workingPresetId);
        $this->assertPresetId($calculatorPresetId);
        $this->assertVersionId($versionId);
        if ($workingPresetId === $calculatorPresetId) {
            throw new \InvalidArgumentException('Calculator identity cannot become its own working preset.', 422);
        }
        return $this->withGlobalAuthority(function (array $pinnedIblockIds) use (
            $workingPresetId,
            $calculatorPresetId,
            $versionId
        ): array {
            $codeSeed = self::workingCodePrefix($calculatorPresetId, $versionId) . $workingPresetId;
            $before = $this->loadWorkingIdentity($workingPresetId, $pinnedIblockIds);
            if ($this->workingIdentityMatches($before, $calculatorPresetId, $versionId)) {
                return ['contract' => self::CONTRACT, 'presetId' => $workingPresetId, 'marked' => true, 'changed' => false];
            }
            if ($before['active'] !== 'Y'
                || str_starts_with($before['code'], self::VERSION_WORKING_CODE_PREFIX)) {
                throw new \RuntimeException('Рабочий граф уже принадлежит другой версии калькулятора.', 409);
            }
            $updated = isset($this->adapters['mark_working_locked'])
                ? call_user_func(
                    $this->adapters['mark_working_locked'],
                    $workingPresetId,
                    $codeSeed,
                    $pinnedIblockIds
                )
                : (new \CIBlockElement())->Update($workingPresetId, [
                    'CODE' => $codeSeed,
                    'ACTIVE' => 'N',
                    'IBLOCK_SECTION_ID' => false,
                ]);
            if ($updated === false) {
                throw new \RuntimeException('Не удалось изолировать рабочий граф версии.', 409);
            }
            $identity = $this->loadWorkingIdentity($workingPresetId, $pinnedIblockIds);
            $this->assertWorkingIdentity($identity, $calculatorPresetId, $versionId);
            return ['contract' => self::CONTRACT, 'presetId' => $workingPresetId, 'marked' => true, 'changed' => true];
        });
    }

    /** @return array<string,mixed> */
    public function deleteVersionWorkingPreset(
        int $workingPresetId,
        int $calculatorPresetId,
        string $versionId
    ): array {
        $this->assertPresetId($workingPresetId);
        $this->assertPresetId($calculatorPresetId);
        $this->assertVersionId($versionId);
        if ($workingPresetId === $calculatorPresetId) {
            throw new \InvalidArgumentException('Calculator identity cannot be deleted as a working preset.', 422);
        }
        $criticalSection = function ($authority, array $pinnedIblockIds) use (
            $workingPresetId,
            $calculatorPresetId,
            $versionId
        ): array {
            $identity = $this->loadWorkingIdentity($workingPresetId, $pinnedIblockIds);
            $this->assertWorkingIdentity($identity, $calculatorPresetId, $versionId);
            $graph = $authority->assertLockedPresetGraphDeletable($workingPresetId);
            $dependencies = $this->loadDeletionDependencies($workingPresetId, $pinnedIblockIds);
            if ($dependencies['productIds'] !== []
                || $dependencies['storefronts'] !== []
                || $dependencies['globalIds'] !== []) {
                throw new \RuntimeException('Рабочий граф версии получил внешние связи; автоматическое удаление остановлено.', 409);
            }
            $deleted = isset($this->adapters['delete_locked'])
                ? call_user_func(
                    $this->adapters['delete_locked'],
                    $workingPresetId,
                    $pinnedIblockIds,
                    $dependencies,
                    $graph,
                    $authority
                )
                : $this->deleteOwnedDependenciesLocked(
                    $workingPresetId,
                    $pinnedIblockIds,
                    $dependencies,
                    $graph,
                    $authority
                );
            if (!is_array($deleted)) {
                throw new \RuntimeException('Удаление рабочего графа не подтверждено.', 409);
            }
            $this->writeAudit([
                'contract' => self::CONTRACT,
                'actorId' => $this->actorId(),
                'action' => 'delete_version_working_preset',
                'sourcePresetId' => $calculatorPresetId,
                'workingPresetId' => $workingPresetId,
                'versionId' => $versionId,
                'result' => 'success',
            ]);
            return ['contract' => self::CONTRACT, 'presetId' => $workingPresetId, 'deleted' => true];
        };
        return $this->withSourceAuthority($workingPresetId, $criticalSection);
    }

    /** @return array<string,mixed> */
    public function previewCascadeDelete(int $presetId): array
    {
        $this->assertPresetId($presetId);
        $criticalSection = function ($authority, array $pinnedIblockIds) use ($presetId): array {
            $graph = $authority->assertLockedPresetGraphDeletable($presetId);
            $identity = $this->loadIdentity($presetId, $pinnedIblockIds);
            $dependencies = $this->loadDeletionDependencies($presetId, $pinnedIblockIds);
            return $this->deletionPreview($identity, $graph, $dependencies);
        };
        return $this->withSourceAuthority($presetId, $criticalSection);
    }

    /** @return array<string,mixed> */
    public function deletePresetCascade(
        int $presetId,
        string $expectedDeletionRevision,
        string $confirmationName
    ): array {
        $this->assertPresetId($presetId);
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedDeletionRevision) !== 1) {
            throw new \InvalidArgumentException('Expected deletion revision must be a lowercase SHA-256 value.', 422);
        }
        $criticalSection = function ($authority, array $pinnedIblockIds) use (
            $presetId,
            $expectedDeletionRevision,
            $confirmationName
        ): array {
            $graph = $authority->assertLockedPresetGraphDeletable($presetId);
            $identity = $this->loadIdentity($presetId, $pinnedIblockIds);
            if (!hash_equals((string)$identity['name'], $confirmationName)) {
                throw new \InvalidArgumentException('Введите точное название калькулятора для удаления.', 422);
            }
            $dependencies = $this->loadDeletionDependencies($presetId, $pinnedIblockIds);
            $preview = $this->deletionPreview($identity, $graph, $dependencies);
            if (!hash_equals((string)$preview['deletionRevision'], $expectedDeletionRevision)) {
                throw new \RuntimeException(
                    'Состав калькулятора изменился после открытия подтверждения. Обновите данные и повторите удаление.',
                    409
                );
            }

            if (isset($this->adapters['delete_locked'])) {
                $deleted = call_user_func(
                    $this->adapters['delete_locked'],
                    $presetId,
                    $pinnedIblockIds,
                    $dependencies,
                    $graph,
                    $authority
                );
            } else {
                $deleted = $this->deleteOwnedDependenciesLocked(
                    $presetId,
                    $pinnedIblockIds,
                    $dependencies,
                    $graph,
                    $authority
                );
            }
            if (!is_array($deleted)) {
                throw new \RuntimeException('Cascade deletion returned an invalid receipt.', 409);
            }

            $audit = [
                'contract' => self::CONTRACT,
                'actorId' => $this->actorId(),
                'action' => 'delete_preset_cascade',
                'sourcePresetId' => $presetId,
                'presetName' => (string)$identity['name'],
                'deletionRevision' => $expectedDeletionRevision,
                'counts' => $preview['counts'],
                'result' => 'success',
            ];
            $this->writeAudit($audit);
            return [
                'contract' => self::CONTRACT,
                'presetId' => $presetId,
                'presetName' => (string)$identity['name'],
                'deletionRevision' => $expectedDeletionRevision,
                'counts' => $preview['counts'],
                'deleted' => true,
            ];
        };
        return $this->withSourceAuthority($presetId, $criticalSection);
    }

    /** @return mixed */
    private function withSourceAuthority(int $presetId, callable $criticalSection)
    {
        if (isset($this->adapters['with_source_authority'])) {
            return call_user_func($this->adapters['with_source_authority'], $presetId, $criticalSection);
        }
        $authority = new CalculatorMutationAuthorityService();
        return $authority->withAuthorityLock(
            $presetId,
            static function (
                bool $_unusedProtection,
                array $pinnedIblockIds
            ) use ($authority, $criticalSection) {
                return $criticalSection($authority, $pinnedIblockIds);
            }
        );
    }

    /** @param array<string,mixed> $identity @param array<string,mixed> $graph @param array<string,mixed> $dependencies */
    private function deletionPreview(array $identity, array $graph, array $dependencies): array
    {
        $counts = [
            'products' => count($dependencies['productIds']),
            'storefronts' => count($dependencies['storefronts']),
            'versions' => (int)$dependencies['versionCount'],
            'documents' => count($dependencies['optionRows']),
            'globals' => count($dependencies['globalIds']),
            'details' => count($graph['deletionDetailIds'] ?? $graph['detailIds']),
            'stages' => count($graph['deletionStageIds'] ?? $graph['stageIds']),
            'settings' => count($graph['deletionSettingsIds'] ?? $graph['settingsIds']),
        ];
        $revisionPayload = [
            'presetId' => (int)$identity['id'],
            'presetName' => (string)$identity['name'],
            'graphRevision' => (string)$graph['revision'],
            'productIds' => $dependencies['productIds'],
            'storefronts' => array_map(static fn(array $row): array => [
                'id' => (string)$row['id'],
                'revision' => (int)$row['revision'],
            ], $dependencies['storefronts']),
            'globalIds' => $dependencies['globalIds'],
            'options' => array_map(static fn(array $row): array => [
                'moduleId' => (string)$row['moduleId'],
                'name' => (string)$row['name'],
                'sha256' => hash('sha256', (string)$row['value']),
            ], $dependencies['optionRows']),
        ];
        return [
            'contract' => self::CONTRACT,
            'presetId' => (int)$identity['id'],
            'presetName' => (string)$identity['name'],
            'deletionRevision' => PresetMutationCoordinatorService::hashCanonical($revisionPayload),
            'counts' => $counts,
            'warnings' => [
                'Будут удалены только данные этого калькулятора.',
                'Товары и свойства Bitrix останутся; связи с калькулятором будут сняты.',
                'Общие элементы расчётного графа сохранятся у других калькуляторов.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function loadDeletionDependencies(int $presetId, array $pinnedIblockIds): array
    {
        if (isset($this->adapters['deletion_dependencies'])) {
            $result = call_user_func($this->adapters['deletion_dependencies'], $presetId, $pinnedIblockIds);
            if (!is_array($result)) {
                throw new \RuntimeException('Cascade deletion dependency snapshot is invalid.', 409);
            }
            return $this->normalizeDeletionDependencies($result);
        }
        if (!class_exists(Application::class)) {
            throw new \RuntimeException('Bitrix database is unavailable for cascade deletion.', 409);
        }
        $productIblockId = (int)(new \Prospektweb\Calc\Config\ConfigManager())->getProductIblockId();
        $presetIblockId = (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0);
        $property = (new PresetProductAssignmentPropertyAuthorityService())->resolve(
            $productIblockId,
            $presetIblockId,
            true
        );
        $productIds = [];
        $cursor = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $productIblockId, '=PROPERTY_' . (int)$property['propertyId'] => $presetId],
            false,
            false,
            ['ID', 'IBLOCK_ID']
        );
        while ($row = $cursor->Fetch()) {
            $productIds[] = (int)$row['ID'];
        }

        $storefronts = [];
        if (\Bitrix\Main\Loader::includeModule('prospektweb.frontcalc')) {
            $storefrontClass = '\\Prospektweb\\Frontcalc\\Service\\StorefrontRepository';
            if (class_exists($storefrontClass)) {
                $listing = (new $storefrontClass())->listStorefronts($presetId);
                $storefronts = is_array($listing['items'] ?? null) ? $listing['items'] : [];
            }
        }
        $globalIds = [];
        $globalsIblockId = (int)($pinnedIblockIds['CALC_GLOBAL_VALUES'] ?? 0);
        if ($globalsIblockId > 0) {
            foreach ((new GlobalSymbolService())->listReadOnlyFromIblockId($globalsIblockId, $presetId) as $symbol) {
                $globalIds[] = (int)$symbol['id'];
            }
        }
        $optionRows = $this->loadOwnedOptionRows($presetId);
        $versionCount = 0;
        foreach ($optionRows as $optionRow) {
            if ((string)$optionRow['moduleId'] === self::MODULE_ID
                && (string)$optionRow['name'] === 'CALC_VERSIONS_' . $presetId) {
                $decoded = json_decode((string)$optionRow['value'], true);
                $versionCount = is_array($decoded['versions'] ?? null) ? count($decoded['versions']) : 0;
            }
        }
        return $this->normalizeDeletionDependencies([
            'productIblockId' => $productIblockId,
            'productPropertyId' => (int)$property['propertyId'],
            'productIds' => $productIds,
            'storefronts' => $storefronts,
            'globalIds' => $globalIds,
            'optionRows' => $optionRows,
            'versionCount' => $versionCount,
        ]);
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function normalizeDeletionDependencies(array $raw): array
    {
        foreach (['productIds', 'storefronts', 'globalIds', 'optionRows'] as $key) {
            if (!is_array($raw[$key] ?? null) || !array_is_list($raw[$key])) {
                throw new \RuntimeException('Cascade deletion dependency list is invalid: ' . $key . '.', 409);
            }
        }
        $raw['productIds'] = array_values(array_unique(array_map('intval', $raw['productIds'])));
        $raw['globalIds'] = array_values(array_unique(array_map('intval', $raw['globalIds'])));
        sort($raw['productIds'], SORT_NUMERIC);
        sort($raw['globalIds'], SORT_NUMERIC);
        usort($raw['storefronts'], static fn(array $left, array $right): int => strcmp((string)$left['id'], (string)$right['id']));
        usort($raw['optionRows'], static fn(array $left, array $right): int => [
            (string)$left['moduleId'], (string)$left['name'],
        ] <=> [
            (string)$right['moduleId'], (string)$right['name'],
        ]);
        $raw['productIblockId'] = (int)($raw['productIblockId'] ?? 0);
        $raw['productPropertyId'] = (int)($raw['productPropertyId'] ?? 0);
        $raw['versionCount'] = max(0, (int)($raw['versionCount'] ?? 0));
        return $raw;
    }

    /** @return array<int,array{moduleId:string,name:string,value:string}> */
    private function loadOwnedOptionRows(int $presetId): array
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $adminNames = [
            'CALC_VERSIONS_' . $presetId,
            'CALCULATOR_INPUT_MAPPING_' . $presetId,
            'CATALOG_OUTPUT_MAPPING_' . $presetId,
            'PRESET_MUTATION_V2_' . $presetId,
        ];
        $adminPrefixes = [
            'CALC_VERSION_BUNDLE_' . $presetId . '_',
            'CALC_VERSION_COMPONENT_' . $presetId . '_',
            'CALC_VERSION_FORM_' . $presetId . '_',
        ];
        $clauses = [];
        foreach ($adminNames as $name) {
            $clauses[] = "NAME='" . $helper->forSql($name) . "'";
        }
        foreach ($adminPrefixes as $prefix) {
            $clauses[] = "NAME LIKE '" . $helper->forSql($prefix) . "%'";
        }
        $frontName = 'FORM_FIRST_PRESET_' . $presetId;
        $frontPublicName = 'PUBLIC_CALC_BASE_' . $presetId;
        $sql = "SELECT MODULE_ID, NAME, SITE_ID, VALUE FROM b_option WHERE SITE_ID IS NULL AND ((MODULE_ID='"
            . $helper->forSql(self::MODULE_ID) . "' AND (" . implode(' OR ', $clauses) . ")) OR (MODULE_ID='prospektweb.frontcalc' AND (NAME='"
            . $helper->forSql($frontName) . "' OR NAME='" . $helper->forSql($frontPublicName)
            . "'))) ORDER BY BINARY MODULE_ID, BINARY NAME FOR UPDATE";
        $cursor = $connection->query($sql);
        $rows = [];
        while ($row = $cursor->fetch()) {
            if (!is_array($row)) {
                throw new \RuntimeException('Cascade deletion option snapshot is invalid.', 409);
            }
            $rows[] = [
                'moduleId' => (string)($row['MODULE_ID'] ?? $row['module_id'] ?? ''),
                'name' => (string)($row['NAME'] ?? $row['name'] ?? ''),
                'value' => (string)($row['VALUE'] ?? $row['value'] ?? ''),
            ];
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    private function deleteOwnedDependenciesLocked(
        int $presetId,
        array $pinnedIblockIds,
        array $dependencies,
        array $graph,
        $authority
    ): array {
        if (\Bitrix\Main\Loader::includeModule('prospektweb.frontcalc')) {
            $storefrontClass = '\\Prospektweb\\Frontcalc\\Service\\StorefrontRepository';
            if (class_exists($storefrontClass)) {
                $repository = new $storefrontClass();
                foreach ($dependencies['storefronts'] as $storefront) {
                    $repository->delete((string)$storefront['id'], (int)$storefront['revision']);
                    if ($repository->get((string)$storefront['id']) !== null) {
                        throw new \RuntimeException('Deleted storefront remains present during cascade readback.', 409);
                    }
                }
            }
        }
        foreach ($dependencies['productIds'] as $productId) {
            \CIBlockElement::SetPropertyValues(
                (int)$productId,
                (int)$dependencies['productIblockId'],
                false,
                (int)$dependencies['productPropertyId']
            );
        }
        foreach ($dependencies['globalIds'] as $globalId) {
            if (!\CIBlockElement::Delete((int)$globalId)) {
                throw new \RuntimeException('Unable to delete calculator global #' . (int)$globalId . '.', 409);
            }
            if (\CIBlockElement::GetByID((int)$globalId)->Fetch()) {
                throw new \RuntimeException('Deleted calculator global remains present during readback.', 409);
            }
        }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        foreach ($dependencies['optionRows'] as $optionRow) {
            $moduleId = (string)$optionRow['moduleId'];
            $name = (string)$optionRow['name'];
            $connection->queryExecute(
                "DELETE FROM b_option WHERE MODULE_ID='" . $helper->forSql($moduleId)
                . "' AND BINARY MODULE_ID=BINARY '" . $helper->forSql($moduleId)
                . "' AND NAME='" . $helper->forSql($name)
                . "' AND BINARY NAME=BINARY '" . $helper->forSql($name) . "' AND SITE_ID IS NULL"
            );
            $remaining = $connection->query(
                "SELECT NAME FROM b_option WHERE MODULE_ID='" . $helper->forSql($moduleId)
                . "' AND BINARY MODULE_ID=BINARY '" . $helper->forSql($moduleId)
                . "' AND NAME='" . $helper->forSql($name)
                . "' AND BINARY NAME=BINARY '" . $helper->forSql($name) . "' AND SITE_ID IS NULL"
            )->fetch();
            if (is_array($remaining)) {
                throw new \RuntimeException('Deleted calculator option remains present during readback.', 409);
            }
        }
        foreach ($dependencies['productIds'] as $productId) {
            $propertyCursor = \CIBlockElement::GetProperty(
                (int)$dependencies['productIblockId'],
                (int)$productId,
                ['sort' => 'asc'],
                ['ID' => (int)$dependencies['productPropertyId']]
            );
            while ($propertyRow = $propertyCursor->Fetch()) {
                if ((int)($propertyRow['VALUE'] ?? 0) === $presetId) {
                    throw new \RuntimeException('Product remains linked to the deleted calculator.', 409);
                }
            }
        }
        $graphReceipt = $authority->deleteLockedPresetGraph($presetId, (string)$graph['revision']);
        return [
            'productsDetached' => count($dependencies['productIds']),
            'storefrontsDeleted' => count($dependencies['storefronts']),
            'globalsDeleted' => count($dependencies['globalIds']),
            'documentsDeleted' => count($dependencies['optionRows']),
            'graph' => $graphReceipt,
        ];
    }

    private function assertPresetId(int $presetId): void
    {
        if ($presetId <= 0 || $presetId > 9007199254740991) {
            throw new \InvalidArgumentException('Preset ID must be a safe positive integer.', 422);
        }
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
        $ownsTransaction = !BitrixTransactionStateAuthority::isActive($connection);
        if ($ownsTransaction) {
            $connection->startTransaction();
        }
        try {
            $locked = $authority->lockAllAuthority($connection);
            $pinnedIblockIds = is_array($locked['iblockIds'] ?? null) ? $locked['iblockIds'] : [];
            $result = $criticalSection($pinnedIblockIds);
            if ($ownsTransaction) {
                $connection->commitTransaction();
            }
            return $result;
        } catch (\Throwable $error) {
            if ($ownsTransaction) {
                $connection->rollbackTransaction();
            }
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

    /** @param array<string,int> $pinnedIblockIds @return array{id:int,name:string,code:string,active:string} */
    private function loadWorkingIdentity(int $presetId, array $pinnedIblockIds): array
    {
        if (isset($this->adapters['working_identity_loader'])) {
            $row = call_user_func($this->adapters['working_identity_loader'], $presetId, $pinnedIblockIds);
        } else {
            $presetIblockId = (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0);
            $row = \CIBlockElement::GetList(
                [],
                ['ID' => $presetId, 'IBLOCK_ID' => $presetIblockId],
                false,
                ['nTopCount' => 1],
                ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'ACTIVE']
            )->Fetch();
        }
        if (!is_array($row)) {
            throw new \RuntimeException('Рабочий граф версии не найден.', 409);
        }
        return [
            'id' => (int)($row['id'] ?? $row['ID'] ?? 0),
            'name' => trim((string)($row['name'] ?? $row['NAME'] ?? '')),
            'code' => (string)($row['code'] ?? $row['CODE'] ?? ''),
            'active' => (string)($row['active'] ?? $row['ACTIVE'] ?? ''),
        ];
    }

    /** @param array{id:int,name:string,code:string,active:string} $identity */
    private function assertWorkingIdentity(array $identity, int $calculatorPresetId, string $versionId): void
    {
        if ($identity['id'] <= 0
            || $identity['name'] === ''
            || !$this->workingIdentityMatches($identity, $calculatorPresetId, $versionId)) {
            throw new \RuntimeException('Рабочий граф не принадлежит указанной версии калькулятора.', 409);
        }
    }

    /** @param array{id:int,name:string,code:string,active:string} $identity */
    private function workingIdentityMatches(array $identity, int $calculatorPresetId, string $versionId): bool
    {
        return $identity['active'] === 'N'
            && str_starts_with($identity['code'], self::workingCodePrefix($calculatorPresetId, $versionId));
    }

    private static function workingCodePrefix(int $calculatorPresetId, string $versionId): string
    {
        return self::VERSION_WORKING_CODE_PREFIX
            . $calculatorPresetId . '-'
            . str_replace('_', '-', strtolower($versionId)) . '-';
    }

    private function assertVersionId(string $versionId): void
    {
        if (preg_match('/^v_[a-f0-9]{16,40}$/D', $versionId) !== 1) {
            throw new \InvalidArgumentException('versionId must be a canonical calculator version identifier.', 422);
        }
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
