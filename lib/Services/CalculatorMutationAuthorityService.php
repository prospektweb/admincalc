<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

require_once __DIR__ . '/BitrixTransactionStateAuthority.php';

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

/**
 * Generic calculator mutation transaction and formula-safety boundary.
 * Every preset uses the same configured iblock authority; none is privileged.
 */
final class CalculatorMutationAuthorityService
{
    private const MAX_GRAPH_NODES = 20000;
    private const MODULE_ID = 'prospektweb.calc';

    /** Every calculator iblock whose identity participates in mutation authority. */
    private const AUTHORITY_IBLOCK_CODES = [
        'CALC_DETAILS',
        'CALC_PRESETS',
        'CALC_SETTINGS',
        'CALC_STAGES',
        'CALC_GLOBAL_VALUES',
        'CALC_CUSTOM_FIELDS',
        'CALC_OPERATIONS',
        'CALC_OPERATIONS_VARIANTS',
        'CALC_MATERIALS',
        'CALC_MATERIALS_VARIANTS',
        'CALC_EQUIPMENT',
    ];

    /** @var array<string,true> */
    private const FORBIDDEN_ROOTS = [
        'product' => true,
        'offer' => true,
        'selectedOffer' => true,
        'selectedOffers' => true,
        'context' => true,
        'iblocks' => true,
        'elementsStore' => true,
        'priceTypes' => true,
        'resources' => true,
        'globalSymbols' => true,
    ];

    /** @var string[] */
    private const RESERVED_IDENTIFIERS = [
        'if', 'round', 'ceil', 'floor', 'min', 'max', 'abs', 'trim', 'lower', 'upper',
        'len', 'contains', 'replace', 'tonumber', 'tostring', 'split', 'join', 'get',
        'getprice', 'regexmatch', 'regexextract', 'true', 'false', 'null', 'undefined',
        'input', 'offer', 'product', 'calculator', 'operation', 'operationvariant',
        'equipment', 'material', 'materialvariant', 'stage', 'preset', 'selectedoffer',
        'selectedoffers', 'context', 'iblocks', 'elementsstore', 'pricetypes',
        'resources', 'globalsymbols', 'globalvalues', 'current_stage',
        '__proto__', 'prototype', 'constructor',
    ];

    /** @var array<string,callable> */
    private array $adapters;

    /** @var array<string,int>|null */
    private ?array $lockedIblockIds = null;

    private int $lockedPresetId = 0;

    /** @var array<string,array<int,array<int,array{sourceKind:string,sourceId:int}>>>|null */
    private ?array $structuralReferenceIndex = null;

    /** @var array<int,array<string,mixed>>|null */
    private ?array $presetGraphsCache = null;

    /** @param array<string,callable> $adapters */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    public static function findForbiddenRoot(string $formula): ?string
    {
        foreach (self::identifiers($formula) as $identifier) {
            if (isset(self::FORBIDDEN_ROOTS[$identifier])) {
                return $identifier;
            }
        }
        return null;
    }

    public static function assertFormula(string $formula, string $surface = 'formula'): void
    {
        $root = self::findForbiddenRoot($formula);
        if ($root !== null) {
            throw new \RuntimeException(
                'Calculator ' . $surface . ' cannot reference private runtime root "' . $root . '".',
                409
            );
        }
    }

    public static function isReservedIdentifier(string $code): bool
    {
        return in_array(strtolower($code), self::RESERVED_IDENTIFIERS, true)
            || preg_match('/^stage_\d+$/Di', $code) === 1;
    }

    public function assertStructuralMutationAllowed(
        int $requestedPresetId,
        array $subjectDetailIds,
        bool $protected,
        string $surface
    ): void {
        $graph = $this->presetGraph($requestedPresetId);
        foreach ($this->positiveUniqueIds($subjectDetailIds, $surface . ' detail') as $detailId) {
            $this->assertEntityOwnedOnlyByPreset('detail', $detailId, $requestedPresetId, $graph);
            $this->assertSingleStructuralReference('detail', $detailId, $requestedPresetId, $graph);
        }
    }

    /** @return int[] */
    public function presetRootDetailIds(int $presetId): array
    {
        if ($presetId <= 0) {
            return [];
        }
        $iblockId = $this->lockedIblockIds !== null
            ? (int)($this->lockedIblockIds['CALC_PRESETS'] ?? 0)
            : (int)($this->iblockIds()['CALC_PRESETS'] ?? 0);
        $preset = \CIBlockElement::GetList(
            [],
            ['ID' => $presetId, 'IBLOCK_ID' => $iblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID']
        )->Fetch();
        if (!is_array($preset)) {
            throw new \RuntimeException('Calculator preset #' . $presetId . ' was not found.', 409);
        }
        return $this->readPropertyIds($iblockId, $presetId, 'CALC_DETAILS');
    }

    public function assertDetailDeletionCascadeAllowed(
        int $requestedPresetId,
        int $detailId,
        bool $protected,
        string $surface
    ): void {
        $graph = $this->presetGraph($requestedPresetId);
        $this->assertEntityOwnedOnlyByPreset('detail', $detailId, $requestedPresetId, $graph);
        $closure = $this->detailClosure($detailId, $graph);
        foreach ($closure as $descendantId) {
            $this->assertEntityOwnedOnlyByPreset('detail', $descendantId, $requestedPresetId, $graph);
            $this->assertSingleStructuralReference('detail', $descendantId, $requestedPresetId, $graph);
            foreach ($graph['detailStages'][$descendantId] ?? [] as $stageId) {
                $this->assertEntityOwnedOnlyByPreset('stage', $stageId, $requestedPresetId, $graph);
                $this->assertSingleStructuralReference('stage', $stageId, $requestedPresetId, $graph);
                foreach ($graph['stageSettings'][$stageId] ?? [] as $settingsId) {
                    $this->assertEntityOwnedOnlyByPreset('settings', $settingsId, $requestedPresetId, $graph);
                    $this->assertSingleStructuralReference('settings', $settingsId, $requestedPresetId, $graph);
                }
            }
        }
    }

    public function assertStageStructuralMutationAllowed(
        int $requestedPresetId,
        int $stageId,
        bool $protected,
        string $surface
    ): void {
        $graph = $this->presetGraph($requestedPresetId);
        $this->assertEntityOwnedOnlyByPreset('stage', $stageId, $requestedPresetId, $graph);
        $this->assertSingleStructuralReference('stage', $stageId, $requestedPresetId, $graph);
    }

    public function assertPresetMutationAllowed(int $requestedPresetId, int $subjectPresetId): void
    {
        if ($requestedPresetId <= 0 || $subjectPresetId !== $requestedPresetId) {
            throw new \RuntimeException('Preset mutation target does not match the locked preset.', 409);
        }
        $this->presetGraph($requestedPresetId);
    }

    /**
     * A contract clone reads the currently linked settings but only mutates the
     * caller's exclusively owned stage. The source settings may intentionally
     * be shared; modifying those shared bytes is never allowed here.
     */
    public function assertContractCloneAllowed(
        int $requestedPresetId,
        int $stageId,
        int $settingsId
    ): void {
        $graph = $this->presetGraph($requestedPresetId);
        $this->assertEntityOwnedOnlyByPreset('stage', $stageId, $requestedPresetId, $graph);
        $this->assertSingleStructuralReference('stage', $stageId, $requestedPresetId, $graph);
        $this->assertKnownElement('settings', $settingsId);
        if (!in_array($settingsId, $graph['stageSettings'][$stageId] ?? [], true)) {
            throw new \RuntimeException(
                'Calculator contract source is stale or is not linked to the requested stage.',
                409
            );
        }
    }

    public function assertStageActivationConditionWrite(
        int $requestedPresetId,
        int $stageId,
        $raw,
        bool $protected,
        int $globalIblockId
    ): void {
        $this->assertStageStructuralMutationAllowed(
            $requestedPresetId,
            $stageId,
            $protected,
            'stage activation condition'
        );
        if ($globalIblockId !== (int)($this->lockedIblockIds['CALC_GLOBAL_VALUES'] ?? 0)) {
            throw new \RuntimeException('Stage activation condition used a stale global iblock authority.', 409);
        }
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('Stage activation condition must be a string.', 422);
        }
        if (trim($raw) !== '') {
            self::assertFormula($raw, 'activation condition');
        }
    }

    public function assertStageMoveAllowed(
        int $requestedPresetId,
        int $stageId,
        int $sourceDetailId,
        int $targetDetailId,
        bool $protected
    ): void {
        $graph = $this->presetGraph($requestedPresetId);
        $this->assertEntityOwnedOnlyByPreset('detail', $sourceDetailId, $requestedPresetId, $graph);
        $this->assertEntityOwnedOnlyByPreset('detail', $targetDetailId, $requestedPresetId, $graph);
        $this->assertEntityOwnedOnlyByPreset('stage', $stageId, $requestedPresetId, $graph);
        $this->assertSingleStructuralReference('detail', $sourceDetailId, $requestedPresetId, $graph);
        $this->assertSingleStructuralReference('detail', $targetDetailId, $requestedPresetId, $graph);
        $this->assertSingleStructuralReference('stage', $stageId, $requestedPresetId, $graph);
        if (!in_array($stageId, $graph['detailStages'][$sourceDetailId] ?? [], true)) {
            throw new \RuntimeException('Stage move source is stale or belongs to another detail.', 409);
        }
        if ($sourceDetailId !== $targetDetailId
            && in_array($stageId, $graph['detailStages'][$targetDetailId] ?? [], true)) {
            throw new \RuntimeException('Stage move target already contains the stage.', 409);
        }
    }

    /**
     * Resolve and authorize the one current containment edge for a detail that
     * is being moved into a binding. The source comes from the locked database,
     * never from a client-supplied parent identifier.
     *
     * @return array{sourceKind:string,sourceId:int}
     */
    public function assertDetailMoveIntoBindingAllowed(
        int $requestedPresetId,
        int $detailId,
        int $targetBindingId,
        bool $protected
    ): array {
        $graph = $this->presetGraph($requestedPresetId);
        $this->assertEntityOwnedOnlyByPreset('detail', $detailId, $requestedPresetId, $graph);
        $this->assertEntityOwnedOnlyByPreset('detail', $targetBindingId, $requestedPresetId, $graph);
        $this->assertSingleStructuralReference(
            'detail',
            $detailId,
            $requestedPresetId,
            $graph
        );
        $this->assertSingleStructuralReference(
            'detail',
            $targetBindingId,
            $requestedPresetId,
            $graph
        );
        if ($detailId === $targetBindingId
            || in_array($targetBindingId, $this->detailClosure($detailId, $graph), true)) {
            throw new \RuntimeException('Detail move would create a binding cycle.', 409);
        }
        if (in_array($detailId, $graph['detailChildren'][$targetBindingId] ?? [], true)) {
            throw new \RuntimeException('Target binding already contains the detail.', 409);
        }

        $primary = array_values(array_filter(
            $this->structuralReferenceIndex()['detail'][$detailId] ?? [],
            static fn(array $reference): bool => in_array(
                $reference['sourceKind'],
                ['preset', 'detail'],
                true
            )
        ));
        if (count($primary) !== 1) {
            throw new \RuntimeException('Detail move source is ambiguous.', 409);
        }
        return $primary[0];
    }

    /** @param array{sourceKind:string,sourceId:int} $previousSource */
    public function assertDetailMoveIntoBindingApplied(
        int $requestedPresetId,
        int $detailId,
        int $targetBindingId,
        array $previousSource
    ): void {
        $this->refreshLockedState($requestedPresetId);
        $graph = $this->presetGraph($requestedPresetId);
        $this->assertEntityOwnedOnlyByPreset('detail', $detailId, $requestedPresetId, $graph);
        $this->assertEntityOwnedOnlyByPreset('detail', $targetBindingId, $requestedPresetId, $graph);
        $this->assertSingleStructuralReference(
            'detail',
            $detailId,
            $requestedPresetId,
            $graph
        );
        if (!in_array($detailId, $graph['detailChildren'][$targetBindingId] ?? [], true)) {
            throw new \RuntimeException('Detail move target read-back failed.', 409);
        }
        $sourceKind = (string)($previousSource['sourceKind'] ?? '');
        $sourceId = (int)($previousSource['sourceId'] ?? 0);
        $sourceStillContains = $sourceKind === 'preset'
            ? in_array($detailId, $graph['rootDetailIds'] ?? [], true)
            : ($sourceKind === 'detail'
                && in_array($detailId, $graph['detailChildren'][$sourceId] ?? [], true));
        if ($sourceStillContains) {
            throw new \RuntimeException('Detail move source detach read-back failed.', 409);
        }
    }

    public function assertPresetGlobalsWrite(
        int $presetId,
        array $variables,
        array $constants,
        ?bool $protected = null
    ): void {
        $this->presetGraph($presetId);
        $seen = [];
        foreach (['variable' => $variables, 'constant' => $constants] as $kind => $rows) {
            foreach ($rows as $index => $row) {
                if (!is_array($row)) {
                    throw new \InvalidArgumentException('Preset global ' . $kind . ' #' . $index . ' must be an object.', 422);
                }
                $code = trim((string)($row['VALUE'] ?? ''));
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $code) !== 1
                    || self::isReservedIdentifier($code)) {
                    throw new \InvalidArgumentException('Preset global code is invalid or reserved: ' . $code . '.', 422);
                }
                $canonical = strtolower($code);
                if (isset($seen[$canonical])) {
                    throw new \InvalidArgumentException('Preset global code is duplicated: ' . $code . '.', 422);
                }
                $seen[$canonical] = true;
                if ($kind === 'variable') {
                    $formula = (string)($row['DESCRIPTION'] ?? '');
                    if (trim($formula) !== '') {
                        self::assertFormula($formula, 'global variable ' . $code);
                    }
                }
            }
        }
    }

    public function assertSettingsLogicWrite(
        int $requestedPresetId,
        int $settingsId,
        $raw,
        ?bool $protected = null
    ): void
    {
        $this->assertSettingsMutationAllowed($requestedPresetId, $settingsId, $protected);
        if ($settingsId <= 0 || !is_string($raw) || trim($raw) === '') {
            throw new \InvalidArgumentException('LOGIC_JSON version 2 must be a non-empty JSON string.', 422);
        }
        try {
            $definition = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('LOGIC_JSON is invalid JSON.', 422, $error);
        }
        if (!($definition instanceof \stdClass)) {
            throw new \InvalidArgumentException('LOGIC_JSON must be an object.', 422);
        }
        if (!property_exists($definition, 'version') || $definition->version !== 2) {
            throw new \InvalidArgumentException('LOGIC_JSON must use version 2.', 422);
        }
        if (!property_exists($definition, 'vars') || !is_array($definition->vars)) {
            throw new \InvalidArgumentException(
                'LOGIC_JSON version 2 must contain an explicit vars array.',
                422
            );
        }
        foreach ($definition->vars as $index => $variable) {
            if (!($variable instanceof \stdClass)) {
                throw new \InvalidArgumentException(
                    'LOGIC_JSON.vars[' . $index . '] must be an object.',
                    422
                );
            }
            if (!property_exists($variable, 'name')
                || !is_string($variable->name)
                || trim($variable->name) === '') {
                throw new \InvalidArgumentException(
                    'LOGIC_JSON.vars[' . $index . '].name must be a non-empty string.',
                    422
                );
            }
            if (!property_exists($variable, 'formula') || !is_string($variable->formula)) {
                throw new \InvalidArgumentException(
                    'LOGIC_JSON.vars[' . $index . '].formula must be a string.',
                    422
                );
            }
            self::assertFormula($variable->formula, 'LOGIC_JSON variable ' . trim($variable->name));
        }
    }

    public function assertStageInputsWrite(
        int $requestedPresetId,
        int $stageId,
        $raw,
        ?bool $protected = null
    ): void
    {
        $this->assertStageStructuralMutationAllowed(
            $requestedPresetId,
            $stageId,
            (bool)$protected,
            'stage inputs'
        );
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('Stage INPUTS must be a string.', 422);
        }
        $this->assertFormulaMembers($raw, 'stage INPUTS');
    }

    public function assertSettingsMutationAllowed(
        int $requestedPresetId,
        int $settingsId,
        ?bool $protected = null
    ): void {
        $graph = $this->presetGraph($requestedPresetId);
        $this->assertEntityOwnedOnlyByPreset('settings', $settingsId, $requestedPresetId, $graph);
        $logicalStageIds = [];
        foreach ($graph['stageSettings'] ?? [] as $linkedStageId => $linkedSettingsIds) {
            if (in_array($settingsId, $linkedSettingsIds, true)) {
                $logicalStageIds[] = (int)$linkedStageId;
            }
        }
        if (count($logicalStageIds) === 1) {
            $logicalStageId = $logicalStageIds[0];
            foreach ($this->structuralReferenceIndex()['settings'][$settingsId] ?? [] as $reference) {
                $sourceKind = (string)($reference['sourceKind'] ?? '');
                $sourceId = (int)($reference['sourceId'] ?? 0);
                if (($sourceKind === 'stage' && $sourceId === $logicalStageId)
                    || ($sourceKind === 'preset' && $sourceId === $requestedPresetId)) {
                    continue;
                }
                throw new \RuntimeException('Calculator settings belong to another stage or preset.', 409);
            }
            return;
        }
        $this->assertSingleStructuralReference('settings', $settingsId, $requestedPresetId, $graph);
    }

    public function assertSettingsLinkToStage(
        int $requestedPresetId,
        int $stageId,
        int $settingsId,
        ?bool $protected = null
    ): void {
        $graph = $this->presetGraph($requestedPresetId);
        $this->assertEntityOwnedOnlyByPreset('stage', $stageId, $requestedPresetId, $graph);
        $this->assertEntityUnownedOrOwnedOnlyByPreset('settings', $settingsId, $requestedPresetId, $graph);
        foreach ($graph['stageSettings'] ?? [] as $linkedStageId => $linkedSettingsIds) {
            if ((int)$linkedStageId !== $stageId && in_array($settingsId, $linkedSettingsIds, true)) {
                throw new \RuntimeException('Calculator settings already belong to another stage.', 409);
            }
        }
        $references = $this->structuralReferenceIndex()['settings'][$settingsId] ?? [];
        foreach ($references as $reference) {
            $sourceKind = (string)($reference['sourceKind'] ?? '');
            $sourceId = (int)($reference['sourceId'] ?? 0);
            if (($sourceKind === 'stage' && $sourceId === $stageId)
                || ($sourceKind === 'preset' && $sourceId === $requestedPresetId)) {
                continue;
            }
            throw new \RuntimeException('Calculator settings already belong to another stage.', 409);
        }
    }

    public function assertStageLinkToPreset(
        int $presetId,
        int $stageId,
        ?bool $protected = null
    ): void {
        $graph = $this->presetGraph($presetId);
        $this->assertEntityUnownedOrOwnedOnlyByPreset('stage', $stageId, $presetId, $graph);
        if (!$this->graphContains($graph, 'stage', $stageId)) {
            throw new \RuntimeException(
                'A stage must be attached to an owned detail before preset indexing.',
                409
            );
        }
        $this->assertSingleStructuralReference('stage', $stageId, $presetId, $graph);
    }

    public function refreshLockedState(int $presetId): void
    {
        $this->assertLockedPreset($presetId);
        $this->structuralReferenceIndex = null;
        $this->presetGraphsCache = null;
    }

    /**
     * Keep calculator mutations and their read-back validation atomic.
     * The first callback argument is reserved for generic validation flags.
     *
     * @return mixed
     */
    public function withAuthorityLock(int $presetId, callable $mutation)
    {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Calculator preset ID must be positive.', 422);
        }
        if ($this->lockedPresetId > 0) {
            if ($this->lockedPresetId !== $presetId || $this->lockedIblockIds === null) {
                throw new \RuntimeException(
                    'Nested calculator mutation targets a different preset.',
                    409
                );
            }
            return $mutation(false, $this->lockedIblockIds, $this);
        }
        $connection = isset($this->adapters['connection_provider'])
            ? call_user_func($this->adapters['connection_provider'])
            : Application::getConnection();
        $transactionActive = isset($this->adapters['transaction_active'])
            ? (bool)call_user_func($this->adapters['transaction_active'], $connection)
            : (isset($this->adapters['connection_provider'])
                ? false
                : BitrixTransactionStateAuthority::isActive($connection));
        $ownsTransaction = !$transactionActive;
        if ($ownsTransaction) {
            $connection->startTransaction();
        }
        try {
            $result = $this->withAuthorityInTransaction($connection, $presetId, $mutation);
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

    /**
     * Join a transaction owned by a wider mutation coordinator. The caller is
     * responsible for commit/rollback; this service owns only the deterministic
     * graph lock and the lifetime of the pinned authority snapshot.
     *
     * @return mixed
     */
    public function withAuthorityInTransaction($connection, int $presetId, callable $criticalSection)
    {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Calculator preset ID must be positive.', 422);
        }
        if ($this->lockedPresetId !== 0) {
            throw new \RuntimeException('Nested calculator authority mutation is not allowed.', 409);
        }

        $authority = $this->lockAuthority($connection, $presetId);
        $iblockIds = $authority['iblockIds'];
        $this->lockedPresetId = $presetId;
        $this->lockedIblockIds = $iblockIds;
        try {
            return $criticalSection(false, $iblockIds, $authority);
        } finally {
            $this->lockedPresetId = 0;
            $this->lockedIblockIds = null;
            $this->structuralReferenceIndex = null;
            $this->presetGraphsCache = null;
        }
    }

    /**
     * Read any preset graph while the global calculator authority is held.
     * Lifecycle operations use this for source and freshly-created clone
     * readback without opening a second transaction.
     *
     * @return array<string,mixed>
     */
    public function readLockedPresetGraph(int $presetId): array
    {
        if ($this->lockedPresetId <= 0 || $this->lockedIblockIds === null) {
            throw new \RuntimeException('Calculator graph readback requires the authority lock.', 409);
        }
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Calculator preset ID must be positive.', 422);
        }
        $graphs = $this->allPresetGraphs();
        if (!isset($graphs[$presetId])) {
            throw new \RuntimeException('Calculator preset #' . $presetId . ' was not found.', 409);
        }
        return $graphs[$presetId];
    }

    /**
     * Prove that every structural entity reachable from a preset is owned by
     * that preset and has no incoming references from outside its graph.
     * Lifecycle deletion calls this while the global calculator authority is
     * held, immediately before the physical delete.
     *
     * @return array<string,mixed>
     */
    public function assertLockedPresetGraphDeletable(int $presetId): array
    {
        $graph = $this->readLockedPresetGraph($presetId);
        $keys = ['detail' => 'detailIds', 'stage' => 'stageIds', 'settings' => 'settingsIds'];
        $deletable = [];
        foreach ($keys as $kind => $key) {
            $deletable[$kind] = array_fill_keys(array_map('intval', $graph[$key]), true);
        }

        foreach ($this->allPresetGraphs() as $otherPresetId => $otherGraph) {
            if ((int)$otherPresetId === $presetId) {
                continue;
            }
            foreach ($keys as $kind => $key) {
                foreach ($otherGraph[$key] as $entityId) {
                    $entityId = (int)$entityId;
                    if (array_key_exists($entityId, $deletable[$kind])) {
                        $deletable[$kind][$entityId] = false;
                    }
                }
            }
        }

        do {
            $changed = false;
            foreach ($keys as $kind => $key) {
                foreach ($graph[$key] as $entityId) {
                    $entityId = (int)$entityId;
                    if (($deletable[$kind][$entityId] ?? false) !== true) {
                        continue;
                    }
                    foreach ($this->structuralReferenceIndex()[$kind][$entityId] ?? [] as $reference) {
                        $sourceKind = (string)($reference['sourceKind'] ?? '');
                        $sourceId = (int)($reference['sourceId'] ?? 0);
                        $sourceWillBeDeleted = $sourceKind === 'preset'
                            ? $sourceId === $presetId
                            : isset($deletable[$sourceKind][$sourceId])
                                && $deletable[$sourceKind][$sourceId] === true;
                        if (!$sourceWillBeDeleted) {
                            $deletable[$kind][$entityId] = false;
                            $changed = true;
                            break;
                        }
                    }
                }
            }
        } while ($changed);

        foreach ($keys as $kind => $key) {
            $prefix = $kind === 'settings' ? 'Settings' : ucfirst($kind);
            $graph['deletion' . $prefix . 'Ids'] = array_values(array_filter(
                array_map('intval', $graph[$key]),
                static fn(int $entityId): bool => ($deletable[$kind][$entityId] ?? false) === true
            ));
            $graph['preserved' . $prefix . 'Ids'] = array_values(array_filter(
                array_map('intval', $graph[$key]),
                static fn(int $entityId): bool => ($deletable[$kind][$entityId] ?? false) !== true
            ));
        }
        return $graph;
    }

    /**
     * Delete a previously-proven preset graph inside the caller transaction.
     * Shared catalog products and properties are deliberately outside this
     * primitive and must only be detached by the lifecycle coordinator.
     *
     * @return array{presetId:int,detailCount:int,stageCount:int,settingsCount:int}
     */
    public function deleteLockedPresetGraph(int $presetId, string $expectedRevision): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedRevision) !== 1) {
            throw new \InvalidArgumentException('Expected calculator graph revision must be a SHA-256 value.', 422);
        }
        $graph = $this->assertLockedPresetGraphDeletable($presetId);
        if (!hash_equals((string)$graph['revision'], $expectedRevision)) {
            throw new \RuntimeException('Calculator graph changed before deletion.', 409);
        }
        $iblockIds = $this->lockedIblockIds();
        $targets = [
            ['settings', array_reverse($graph['deletionSettingsIds']), (int)$iblockIds['CALC_SETTINGS']],
            ['stage', array_reverse($graph['deletionStageIds']), (int)$iblockIds['CALC_STAGES']],
            ['detail', array_reverse($graph['deletionDetailIds']), (int)$iblockIds['CALC_DETAILS']],
            ['preset', [(int)$presetId], (int)$iblockIds['CALC_PRESETS']],
        ];
        foreach ($targets as [$kind, $ids, $iblockId]) {
            foreach ($ids as $entityId) {
                $entityId = (int)$entityId;
                if (!\CIBlockElement::Delete($entityId)) {
                    throw new \RuntimeException('Unable to delete calculator ' . $kind . ' #' . $entityId . '.', 409);
                }
                $row = \CIBlockElement::GetList(
                    [],
                    ['ID' => $entityId, 'IBLOCK_ID' => $iblockId],
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'IBLOCK_ID']
                )->Fetch();
                if (is_array($row)) {
                    throw new \RuntimeException('Calculator ' . $kind . ' deletion readback failed.', 409);
                }
            }
        }
        $this->presetGraphsCache = null;
        $this->structuralReferenceIndex = null;
        return [
            'presetId' => $presetId,
            'detailCount' => count($graph['deletionDetailIds']),
            'stageCount' => count($graph['deletionStageIds']),
            'settingsCount' => count($graph['deletionSettingsIds']),
        ];
    }

    /** @return array<string,int> */
    public function lockedIblockIds(): array
    {
        if ($this->lockedPresetId <= 0 || $this->lockedIblockIds === null) {
            throw new \RuntimeException('Calculator iblock readback requires the authority lock.', 409);
        }
        return $this->lockedIblockIds;
    }

    /** @return array<string,mixed> */
    public function readAuthority(): array
    {
        $iblockIds = $this->iblockIds();
        return [
            'iblockIds' => $iblockIds,
            'globalIblockId' => (int)($iblockIds['CALC_GLOBAL_VALUES'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    public function lockAuthority($connection, int $presetId): array
    {
        if (isset($this->adapters['authority_locker'])) {
            $iblockIds = $this->iblockIds();
            $locked = call_user_func(
                $this->adapters['authority_locker'],
                $connection,
                $presetId,
                $iblockIds
            );
            if (!is_array($locked)) {
                throw new \RuntimeException('Calculator authority lock adapter returned invalid data.', 409);
            }
            return [
                'presetId' => $presetId,
                'iblockIds' => $iblockIds,
                'globalIblockId' => (int)($iblockIds['CALC_GLOBAL_VALUES'] ?? 0),
                'presetRowRevision' => (string)($locked['presetRowRevision'] ?? ''),
            ];
        }
        $globalAuthority = $this->lockAllAuthority($connection);
        $iblockIds = is_array($globalAuthority['iblockIds'] ?? null)
            ? $globalAuthority['iblockIds']
            : [];
        $presetIblockId = (int)$iblockIds['CALC_PRESETS'];
        $presetRows = $connection->query(
            'SELECT ID, IBLOCK_ID, TIMESTAMP_X FROM b_iblock_element'
            . ' WHERE ID = ' . $presetId
            . ' AND IBLOCK_ID = ' . $presetIblockId
            . ' FOR UPDATE'
        );
        $presetRow = $presetRows->fetch();
        if (!is_array($presetRow)
            || (int)($presetRow['ID'] ?? 0) !== $presetId
            || (int)($presetRow['IBLOCK_ID'] ?? 0) !== $presetIblockId) {
            throw new \RuntimeException('Calculator preset #' . $presetId . ' was not found.', 409);
        }
        return $globalAuthority + [
            'presetId' => $presetId,
            'presetRowRevision' => hash(
                'sha256',
                $presetId . "\0" . $presetIblockId . "\0" . (string)($presetRow['TIMESTAMP_X'] ?? '')
            ),
        ];
    }

    /**
     * Serialize cross-preset registry/refactor writes with every preset graph
     * mutation. The configured CALC_PRESETS iblock row is the shared lock row.
     *
     * @param array<string,int>|null $pinnedIblockIds
     * @return array<string,mixed>
     */
    public function lockAllAuthority($connection, ?array $pinnedIblockIds = null): array
    {
        if (isset($this->adapters['global_authority_locker'])) {
            $iblockIds = $pinnedIblockIds !== null
                ? $this->normalizeAuthorityIblockIds($pinnedIblockIds)
                : $this->iblockIds();
            $locked = call_user_func($this->adapters['global_authority_locker'], $connection, $iblockIds);
            if (!is_array($locked)) {
                throw new \RuntimeException('Global calculator authority lock adapter returned invalid data.', 409);
            }
            return [
                'iblockIds' => $iblockIds,
                'globalIblockId' => (int)($iblockIds['CALC_GLOBAL_VALUES'] ?? 0),
                'globalAuthorityRevision' => (string)($locked['globalAuthorityRevision'] ?? ''),
            ];
        }
        $iblockIds = $this->lockConfiguredIblockIds($connection);
        if ($pinnedIblockIds !== null
            && $this->normalizeAuthorityIblockIds($pinnedIblockIds) !== $iblockIds) {
            throw new \RuntimeException('Calculator iblock authority changed before the lock.', 409);
        }
        $presetIblockId = (int)($iblockIds['CALC_PRESETS'] ?? 0);
        if ($presetIblockId <= 0) {
            throw new \RuntimeException('Calculator preset iblock authority is invalid.', 409);
        }
        return [
            'iblockIds' => $iblockIds,
            'globalIblockId' => (int)($iblockIds['CALC_GLOBAL_VALUES'] ?? 0),
            'globalAuthorityRevision' => hash('sha256', 'calc-preset-iblock' . "\0" . $presetIblockId),
        ];
    }

    /** @return array<string,int> */
    private function iblockIds(): array
    {
        if (isset($this->adapters['iblock_ids'])) {
            $ids = call_user_func($this->adapters['iblock_ids']);
            if (!is_array($ids)) {
                throw new \RuntimeException('Calculator iblock adapter returned invalid data.', 409);
            }
            $ids = $this->normalizeAuthorityIblockIds($ids);
        } else {
            $ids = [];
            foreach (self::AUTHORITY_IBLOCK_CODES as $code) {
                $ids[$code] = (int)Option::get(self::MODULE_ID, 'IBLOCK_' . $code, 0);
            }
            $ids = $this->normalizeAuthorityIblockIds($ids);
        }
        return $ids;
    }

    /** @param array<string,mixed> $ids @return array<string,int> */
    private function normalizeAuthorityIblockIds(array $ids): array
    {
        $expectedKeys = self::AUTHORITY_IBLOCK_CODES;
        sort($expectedKeys, SORT_STRING);
        $actualKeys = array_map('strval', array_keys($ids));
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            throw new \RuntimeException('Calculator iblock authority has an unexpected shape.', 409);
        }
        $normalized = [];
        foreach (self::AUTHORITY_IBLOCK_CODES as $code) {
            $id = $ids[$code] ?? null;
            if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int)$id <= 0) {
                throw new \RuntimeException('Calculator iblock authority is incomplete: ' . $code . '.', 409);
            }
            $normalized[$code] = (int)$id;
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    /** Lock exact option mappings and all target iblock identities in one transaction. */
    private function lockConfiguredIblockIds($connection): array
    {
        $helper = $connection->getSqlHelper();
        $optionNames = array_map(static function (string $code) use ($helper): string {
            return "'" . $helper->forSql('IBLOCK_' . $code) . "'";
        }, self::AUTHORITY_IBLOCK_CODES);
        $rows = $connection->query(
            "SELECT NAME, VALUE FROM b_option WHERE MODULE_ID='" . $helper->forSql(self::MODULE_ID)
            . "' AND (SITE_ID IS NULL OR SITE_ID='') AND BINARY NAME IN ("
            . implode(',', $optionNames) . ') ORDER BY BINARY NAME FOR UPDATE'
        );
        $ids = [];
        while (is_object($rows) && method_exists($rows, 'fetch') && ($row = $rows->fetch())) {
            $name = (string)($row['NAME'] ?? $row['name'] ?? '');
            if (strpos($name, 'IBLOCK_') !== 0) {
                throw new \RuntimeException('Unexpected calculator iblock option row.', 409);
            }
            $code = substr($name, 7);
            if (!in_array($code, self::AUTHORITY_IBLOCK_CODES, true) || isset($ids[$code])) {
                throw new \RuntimeException('Duplicate or unexpected calculator iblock option row.', 409);
            }
            $value = (string)($row['VALUE'] ?? $row['value'] ?? '');
            if ($value === '' || !ctype_digit($value) || (int)$value <= 0) {
                throw new \RuntimeException('Calculator iblock option is invalid: ' . $code . '.', 409);
            }
            $ids[$code] = (int)$value;
        }
        $ids = $this->normalizeAuthorityIblockIds($ids);

        $uniqueIds = array_values(array_unique(array_values($ids)));
        sort($uniqueIds, SORT_NUMERIC);
        if (count($uniqueIds) !== count($ids)) {
            throw new \RuntimeException('Calculator iblock authority contains duplicate targets.', 409);
        }
        $codeLiterals = array_map(static function (string $code) use ($helper): string {
            return "'" . $helper->forSql($code) . "'";
        }, self::AUTHORITY_IBLOCK_CODES);
        $iblockRows = $connection->query(
            'SELECT ID, CODE FROM b_iblock WHERE CODE IN (' . implode(',', $codeLiterals)
            . ') ORDER BY CODE, ID FOR UPDATE'
        );
        $actualIdsByCode = [];
        while (is_object($iblockRows) && method_exists($iblockRows, 'fetch') && ($row = $iblockRows->fetch())) {
            $id = (int)($row['ID'] ?? $row['id'] ?? 0);
            $code = (string)($row['CODE'] ?? $row['code'] ?? '');
            if ($id <= 0
                || !in_array($code, self::AUTHORITY_IBLOCK_CODES, true)
                || isset($actualIdsByCode[$code])) {
                throw new \RuntimeException('Calculator iblock identity rows are ambiguous.', 409);
            }
            $actualIdsByCode[$code] = $id;
        }
        foreach ($ids as $code => $id) {
            if (($actualIdsByCode[$code] ?? null) !== $id) {
                throw new \RuntimeException('Calculator iblock option target does not match code ' . $code . '.', 409);
            }
        }
        return $ids;
    }

    /** @return array<string,mixed> */
    private function presetGraph(int $presetId): array
    {
        $this->assertLockedPreset($presetId);
        $graphs = $this->allPresetGraphs();
        if (!isset($graphs[$presetId])) {
            throw new \RuntimeException('Calculator preset #' . $presetId . ' was not found.', 409);
        }
        return $graphs[$presetId];
    }

    /** @return array<int,array<string,mixed>> */
    private function allPresetGraphs(): array
    {
        if ($this->presetGraphsCache !== null) {
            return $this->presetGraphsCache;
        }
        if ($this->lockedIblockIds === null) {
            throw new \RuntimeException('Calculator graph authority must be read under its database lock.', 409);
        }
        $rawGraphs = isset($this->adapters['graphs_loader'])
            ? call_user_func($this->adapters['graphs_loader'], $this->lockedIblockIds)
            : $this->loadAllPresetGraphs($this->lockedIblockIds);
        if (!is_array($rawGraphs)) {
            throw new \RuntimeException('Calculator graph loader returned invalid data.', 409);
        }
        $graphs = [];
        foreach ($rawGraphs as $key => $rawGraph) {
            if (!is_array($rawGraph)) {
                throw new \RuntimeException('Calculator preset graph must be an object.', 409);
            }
            $presetId = (int)($rawGraph['presetId'] ?? $key);
            if ($presetId <= 0 || isset($graphs[$presetId])) {
                throw new \RuntimeException('Calculator preset graph identity is invalid or duplicated.', 409);
            }
            $graphs[$presetId] = $this->normalizeGraph($presetId, $rawGraph);
        }
        ksort($graphs, SORT_NUMERIC);
        return $this->presetGraphsCache = $graphs;
    }

    /** @param array<string,int> $iblockIds @return array<int,array<string,mixed>> */
    private function loadAllPresetGraphs(array $iblockIds): array
    {
        $presetIds = [];
        $cursor = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => (int)$iblockIds['CALC_PRESETS']],
            false,
            false,
            ['ID', 'IBLOCK_ID']
        );
        while ($row = $cursor->Fetch()) {
            $id = (int)($row['ID'] ?? 0);
            if ($id > 0) {
                $presetIds[] = $id;
            }
        }
        $graphs = [];
        foreach ($presetIds as $presetId) {
            $graphs[$presetId] = $this->loadPresetGraph($presetId, $iblockIds);
        }
        return $graphs;
    }

    /** @param array<string,int> $iblockIds @return array<string,mixed> */
    private function loadPresetGraph(int $presetId, array $iblockIds): array
    {
        $presetIblockId = (int)$iblockIds['CALC_PRESETS'];
        $detailsIblockId = (int)$iblockIds['CALC_DETAILS'];
        $stagesIblockId = (int)$iblockIds['CALC_STAGES'];
        $settingsIblockId = (int)$iblockIds['CALC_SETTINGS'];
        $this->assertElementExists($presetId, $presetIblockId, 'preset');

        $rootDetailIds = $this->readPropertyIds($presetIblockId, $presetId, 'CALC_DETAILS');
        $directStageIds = $this->readPropertyIds($presetIblockId, $presetId, 'CALC_STAGES');
        $presetSettingsIds = $this->readPropertyIds($presetIblockId, $presetId, 'CALC_SETTINGS');
        $detailIds = [];
        $stageIds = [];
        $settingsIds = [];
        $detailChildren = [];
        $detailStages = [];
        $stageSettings = [];
        $queue = array_values($rootDetailIds);

        while ($queue !== []) {
            $detailId = (int)array_shift($queue);
            if ($detailId <= 0 || isset($detailIds[$detailId])) {
                continue;
            }
            if (count($detailIds) >= self::MAX_GRAPH_NODES) {
                throw new \RuntimeException('Calculator detail graph exceeds its safe node limit.', 409);
            }
            $this->assertElementExists($detailId, $detailsIblockId, 'detail');
            $detailIds[$detailId] = true;
            $children = $this->readPropertyIds($detailsIblockId, $detailId, 'DETAILS');
            $stages = $this->readPropertyIds($detailsIblockId, $detailId, 'CALC_STAGES');
            $detailChildren[$detailId] = $children;
            $detailStages[$detailId] = $stages;
            foreach ($children as $childId) {
                if (!isset($detailIds[$childId])) {
                    $queue[] = $childId;
                }
            }
            foreach ($stages as $stageId) {
                $stageIds[$stageId] = true;
            }
        }
        foreach ($directStageIds as $stageId) {
            $stageIds[$stageId] = true;
        }
        foreach ($presetSettingsIds as $settingsId) {
            $this->assertElementExists($settingsId, $settingsIblockId, 'settings');
            $settingsIds[$settingsId] = true;
        }
        if (count($stageIds) > self::MAX_GRAPH_NODES) {
            throw new \RuntimeException('Calculator stage graph exceeds its safe node limit.', 409);
        }
        foreach (array_keys($stageIds) as $stageId) {
            $this->assertElementExists((int)$stageId, $stagesIblockId, 'stage');
            $settings = $this->readPropertyIds($stagesIblockId, (int)$stageId, 'CALC_SETTINGS');
            $stageSettings[(int)$stageId] = $settings;
            foreach ($settings as $settingsId) {
                $this->assertElementExists($settingsId, $settingsIblockId, 'settings');
                $settingsIds[$settingsId] = true;
            }
            $calculatorTree = $this->readPropertyString($stagesIblockId, (int)$stageId, 'OPTIONS_CALCULATOR');
            if ($calculatorTree !== '') {
                try {
                    $mappingService = new StageVariantMappingService();
                    foreach ($mappingService->materialReferencesFromJson($calculatorTree) as $reference) {
                        if (($reference['entity_type'] ?? '') !== 'calculator') continue;
                        $settingsId = (int)($reference['entity_id'] ?? 0);
                        $this->assertElementExists($settingsId, $settingsIblockId, 'settings');
                        $settingsIds[$settingsId] = true;
                        $stageSettings[(int)$stageId][] = $settingsId;
                    }
                    $stageSettings[(int)$stageId] = array_values(array_unique($stageSettings[(int)$stageId]));
                } catch (\InvalidArgumentException $error) {
                    throw new \RuntimeException('Calculator selection tree is invalid for stage ' . (int)$stageId . '.', 409, $error);
                }
            }
        }

        $stageLinkedSettingsIds = [];
        foreach ($stageSettings as $settings) {
            foreach ($settings as $settingsId) {
                $stageLinkedSettingsIds[(int)$settingsId] = true;
            }
        }
        $directSettingsIds = array_values(array_filter(
            $presetSettingsIds,
            static fn(int $settingsId): bool => !isset($stageLinkedSettingsIds[$settingsId])
        ));

        return $this->normalizeGraph($presetId, [
            'presetId' => $presetId,
            'rootDetailIds' => $rootDetailIds,
            'detailIds' => array_keys($detailIds),
            'stageIds' => array_keys($stageIds),
            'settingsIds' => array_keys($settingsIds),
            'directSettingsIds' => $directSettingsIds,
            'detailChildren' => $detailChildren,
            'detailStages' => $detailStages,
            'stageSettings' => $stageSettings,
        ]);
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function normalizeGraph(int $presetId, array $raw): array
    {
        $graph = [
            'presetId' => $presetId,
            'rootDetailIds' => $this->sortedPositiveIds($raw['rootDetailIds'] ?? []),
            'detailIds' => $this->sortedPositiveIds($raw['detailIds'] ?? []),
            'stageIds' => $this->sortedPositiveIds($raw['stageIds'] ?? []),
            'settingsIds' => $this->sortedPositiveIds($raw['settingsIds'] ?? []),
            'directSettingsIds' => $this->sortedPositiveIds($raw['directSettingsIds'] ?? []),
            'detailChildren' => $this->normalizeAdjacency($raw['detailChildren'] ?? []),
            'detailStages' => $this->normalizeAdjacency($raw['detailStages'] ?? []),
            'stageSettings' => $this->normalizeAdjacency($raw['stageSettings'] ?? []),
        ];
        foreach ($graph['rootDetailIds'] as $detailId) {
            if (!in_array($detailId, $graph['detailIds'], true)) {
                throw new \RuntimeException('Calculator root detail is absent from its graph.', 409);
            }
        }
        foreach ($graph['detailChildren'] as $parentId => $children) {
            if (!in_array((int)$parentId, $graph['detailIds'], true)) {
                throw new \RuntimeException('Calculator detail adjacency has an unknown parent.', 409);
            }
            foreach ($children as $childId) {
                if (!in_array($childId, $graph['detailIds'], true)) {
                    throw new \RuntimeException('Calculator detail adjacency has an unknown child.', 409);
                }
            }
        }
        foreach ($graph['detailStages'] as $detailId => $stages) {
            if (!in_array((int)$detailId, $graph['detailIds'], true)) {
                throw new \RuntimeException('Calculator stage adjacency has an unknown detail.', 409);
            }
            foreach ($stages as $stageId) {
                if (!in_array($stageId, $graph['stageIds'], true)) {
                    throw new \RuntimeException('Calculator detail references an unknown stage.', 409);
                }
            }
        }
        foreach ($graph['stageSettings'] as $stageId => $settings) {
            if (!in_array((int)$stageId, $graph['stageIds'], true)) {
                throw new \RuntimeException('Calculator settings adjacency has an unknown stage.', 409);
            }
            foreach ($settings as $settingsId) {
                if (!in_array($settingsId, $graph['settingsIds'], true)) {
                    throw new \RuntimeException('Calculator stage references unknown settings.', 409);
                }
            }
        }
        foreach ($graph['directSettingsIds'] as $settingsId) {
            if (!in_array($settingsId, $graph['settingsIds'], true)) {
                throw new \RuntimeException('Calculator preset references unknown settings.', 409);
            }
        }
        $graph['revision'] = hash('sha256', self::canonicalJson($graph));
        return $graph;
    }

    /** @param mixed $raw @return array<int,int[]> */
    private function normalizeAdjacency($raw): array
    {
        if (!is_array($raw)) {
            throw new \RuntimeException('Calculator graph adjacency must be an object.', 409);
        }
        $result = [];
        foreach ($raw as $ownerId => $ids) {
            $owner = (int)$ownerId;
            if ($owner <= 0) {
                throw new \RuntimeException('Calculator graph adjacency owner is invalid.', 409);
            }
            $result[$owner] = $this->sortedPositiveIds($ids);
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    /** @param mixed $raw @return int[] */
    private function sortedPositiveIds($raw): array
    {
        if (!is_array($raw)) {
            throw new \RuntimeException('Calculator graph identifiers must be an array.', 409);
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = (int)$value;
            if ($id <= 0) {
                throw new \RuntimeException('Calculator graph contains an invalid identifier.', 409);
            }
            $ids[$id] = $id;
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    /** @return int[] */
    private function positiveUniqueIds(array $raw, string $surface): array
    {
        $ids = [];
        foreach ($raw as $value) {
            if (!(is_int($value) || (is_string($value) && preg_match('/^\d+$/D', trim($value)) === 1))) {
                throw new \InvalidArgumentException(ucfirst($surface) . ' ID must be a positive integer.', 422);
            }
            $id = (int)$value;
            if ($id <= 0) {
                throw new \InvalidArgumentException(ucfirst($surface) . ' ID must be a positive integer.', 422);
            }
            $ids[$id] = $id;
        }
        return array_values($ids);
    }

    /** @param array<string,mixed> $requestedGraph */
    private function assertEntityOwnedOnlyByPreset(
        string $kind,
        int $entityId,
        int $presetId,
        array $requestedGraph
    ): void {
        $this->assertKnownElement($kind, $entityId);
        $owners = $this->entityOwners($kind, $entityId);
        if ($owners !== [$presetId] || !$this->graphContains($requestedGraph, $kind, $entityId)) {
            throw new \RuntimeException(
                ucfirst($kind) . ' #' . $entityId . ' is not exclusively owned by preset #' . $presetId . '.',
                409
            );
        }
    }

    /** @param array<string,mixed> $requestedGraph */
    private function assertEntityUnownedOrOwnedOnlyByPreset(
        string $kind,
        int $entityId,
        int $presetId,
        array $requestedGraph
    ): void {
        $this->assertKnownElement($kind, $entityId);
        $owners = $this->entityOwners($kind, $entityId);
        if ($owners !== [] && $owners !== [$presetId]) {
            throw new \RuntimeException(
                ucfirst($kind) . ' #' . $entityId . ' belongs to another or multiple presets.',
                409
            );
        }
        if ($owners === [$presetId] && !$this->graphContains($requestedGraph, $kind, $entityId)) {
            throw new \RuntimeException('Calculator graph ownership read is inconsistent.', 409);
        }
    }

    /** @return int[] */
    private function entityOwners(string $kind, int $entityId): array
    {
        $owners = [];
        foreach ($this->allPresetGraphs() as $presetId => $graph) {
            if ($this->graphContains($graph, $kind, $entityId)) {
                $owners[] = (int)$presetId;
            }
        }
        sort($owners, SORT_NUMERIC);
        return $owners;
    }

    /** @param array<string,mixed> $requestedGraph */
    private function assertSingleStructuralReference(
        string $kind,
        int $entityId,
        int $presetId,
        array $requestedGraph
    ): void {
        $references = $this->structuralReferenceIndex()[$kind][$entityId] ?? [];
        $primarySourceKind = [
            'detail' => ['preset' => true, 'detail' => true],
            'stage' => ['detail' => true],
            'settings' => ['stage' => true, 'preset' => true],
        ][$kind] ?? null;
        if ($primarySourceKind === null) {
            throw new \LogicException('Unknown calculator graph entity kind.');
        }
        $primary = array_values(array_filter(
            $references,
            static fn(array $reference): bool => isset($primarySourceKind[$reference['sourceKind']])
        ));
        if ($kind === 'settings') {
            $stageReferences = array_values(array_filter(
                $primary,
                static fn(array $reference): bool => $reference['sourceKind'] === 'stage'
            ));
            $presetReferences = array_values(array_filter(
                $primary,
                static fn(array $reference): bool => $reference['sourceKind'] === 'preset'
            ));
            if ($stageReferences !== []) {
                $primary = $stageReferences;
                if (count($presetReferences) > 1
                    || ($presetReferences !== [] && (int)$presetReferences[0]['sourceId'] !== $presetId)) {
                    throw new \RuntimeException(
                        'Settings #' . $entityId . ' has a foreign or duplicated preset index reference.',
                        409
                    );
                }
            } else {
                $primary = $presetReferences;
            }
        }
        if (count($primary) !== 1) {
            throw new \RuntimeException(
                ucfirst($kind) . ' #' . $entityId . ' has ' . count($primary)
                    . ' global ownership references; mutation is forbidden.',
                409
            );
        }

        $reference = $primary[0];
        $sourceKind = (string)$reference['sourceKind'];
        $sourceId = (int)$reference['sourceId'];
        $reachable = false;
        if ($kind === 'detail' && $sourceKind === 'preset') {
            $reachable = $sourceId === $presetId
                && in_array($entityId, $requestedGraph['rootDetailIds'] ?? [], true);
        } elseif ($kind === 'detail' && $sourceKind === 'detail') {
            $reachable = in_array($sourceId, $requestedGraph['detailIds'] ?? [], true)
                && in_array($entityId, $requestedGraph['detailChildren'][$sourceId] ?? [], true);
        } elseif ($kind === 'stage' && $sourceKind === 'detail') {
            $reachable = in_array($sourceId, $requestedGraph['detailIds'] ?? [], true)
                && in_array($entityId, $requestedGraph['detailStages'][$sourceId] ?? [], true);
        } elseif ($kind === 'settings' && $sourceKind === 'stage') {
            $reachable = in_array($sourceId, $requestedGraph['stageIds'] ?? [], true)
                && in_array($entityId, $requestedGraph['stageSettings'][$sourceId] ?? [], true);
        } elseif ($kind === 'settings' && $sourceKind === 'preset') {
            $reachable = $sourceId === $presetId
                && in_array($entityId, $requestedGraph['directSettingsIds'] ?? [], true);
        }
        if (!$reachable) {
            throw new \RuntimeException(
                ucfirst($kind) . ' #' . $entityId
                    . ' is referenced by an orphan or unreachable graph node.',
                409
            );
        }

        if ($kind === 'stage') {
            $presetIndexes = array_values(array_filter(
                $references,
                static fn(array $item): bool => $item['sourceKind'] === 'preset'
            ));
            if (count($presetIndexes) > 1
                || ($presetIndexes !== [] && (int)$presetIndexes[0]['sourceId'] !== $presetId)) {
                throw new \RuntimeException(
                    'Stage #' . $entityId . ' has a foreign or duplicated preset index reference.',
                    409
                );
            }
        }
    }

    /**
     * @return array<string,array<int,array<int,array{sourceKind:string,sourceId:int}>>>
     */
    private function structuralReferenceIndex(): array
    {
        if ($this->structuralReferenceIndex !== null) {
            return $this->structuralReferenceIndex;
        }
        if ($this->lockedIblockIds === null) {
            throw new \RuntimeException('Structural references require a calculator authority lock.', 409);
        }
        $raw = isset($this->adapters['structural_references_loader'])
            ? call_user_func($this->adapters['structural_references_loader'], $this->lockedIblockIds)
            : $this->loadStructuralReferenceIndex($this->lockedIblockIds);
        if (!is_array($raw)) {
            throw new \RuntimeException('Structural reference loader returned invalid data.', 409);
        }
        $normalized = ['detail' => [], 'stage' => [], 'settings' => []];
        $allowedSources = [
            'detail' => ['preset' => true, 'detail' => true],
            'stage' => ['preset' => true, 'detail' => true],
            'settings' => ['stage' => true, 'preset' => true],
        ];
        foreach ($normalized as $kind => $_unused) {
            $targets = $raw[$kind] ?? [];
            if (!is_array($targets)) {
                throw new \RuntimeException('Structural reference targets must be an object.', 409);
            }
            foreach ($targets as $targetId => $references) {
                $targetId = (int)$targetId;
                if ($targetId <= 0 || !is_array($references)) {
                    throw new \RuntimeException('Structural reference target is invalid.', 409);
                }
                foreach ($references as $reference) {
                    if (!is_array($reference)) {
                        throw new \RuntimeException('Structural reference must be an object.', 409);
                    }
                    $sourceKind = (string)($reference['sourceKind'] ?? '');
                    $sourceId = (int)($reference['sourceId'] ?? 0);
                    if ($sourceId <= 0 || !isset($allowedSources[$kind][$sourceKind])) {
                        throw new \RuntimeException('Structural reference source is invalid.', 409);
                    }
                    $normalized[$kind][$targetId][] = [
                        'sourceKind' => $sourceKind,
                        'sourceId' => $sourceId,
                    ];
                }
                usort(
                    $normalized[$kind][$targetId],
                    static fn(array $left, array $right): int => [
                        $left['sourceKind'],
                        $left['sourceId'],
                    ] <=> [
                        $right['sourceKind'],
                        $right['sourceId'],
                    ]
                );
            }
            ksort($normalized[$kind], SORT_NUMERIC);
        }
        return $this->structuralReferenceIndex = $normalized;
    }

    /**
     * Scan every relationship row, including inactive and unreachable source
     * elements. A reachable-graph-only scan would miss hidden incoming links.
     *
     * @param array<string,int> $iblockIds
     * @return array<string,array<int,array<int,array{sourceKind:string,sourceId:int}>>>
     */
    private function loadStructuralReferenceIndex(array $iblockIds): array
    {
        $index = ['detail' => [], 'stage' => [], 'settings' => []];
        foreach ([
            ['preset', 'CALC_PRESETS', 'CALC_DETAILS', 'detail'],
            ['preset', 'CALC_PRESETS', 'CALC_STAGES', 'stage'],
            ['preset', 'CALC_PRESETS', 'CALC_SETTINGS', 'settings'],
            ['detail', 'CALC_DETAILS', 'DETAILS', 'detail'],
            ['detail', 'CALC_DETAILS', 'CALC_STAGES', 'stage'],
            ['stage', 'CALC_STAGES', 'CALC_SETTINGS', 'settings'],
        ] as [$sourceKind, $iblockCode, $propertyCode, $targetKind]) {
            $iblockId = (int)($iblockIds[$iblockCode] ?? 0);
            if ($iblockId <= 0) {
                throw new \RuntimeException('Structural reference iblock is invalid: ' . $iblockCode . '.', 409);
            }
            $cursor = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                ['IBLOCK_ID' => $iblockId],
                false,
                false,
                ['ID', 'IBLOCK_ID']
            );
            while ($row = $cursor->Fetch()) {
                $sourceId = (int)($row['ID'] ?? 0);
                if ($sourceId <= 0) {
                    continue;
                }
                $properties = \CIBlockElement::GetProperty(
                    $iblockId,
                    $sourceId,
                    ['sort' => 'asc', 'id' => 'asc'],
                    ['CODE' => $propertyCode]
                );
                while ($property = $properties->Fetch()) {
                    $targetId = (int)($property['VALUE'] ?? 0);
                    if ($targetId <= 0) {
                        continue;
                    }
                    $index[$targetKind][$targetId][] = [
                        'sourceKind' => $sourceKind,
                        'sourceId' => $sourceId,
                    ];
                }
            }
        }
        return $index;
    }

    /** @param array<string,mixed> $graph */
    private function graphContains(array $graph, string $kind, int $entityId): bool
    {
        $key = ['detail' => 'detailIds', 'stage' => 'stageIds', 'settings' => 'settingsIds'][$kind] ?? '';
        if ($key === '') {
            throw new \LogicException('Unknown calculator graph entity kind.');
        }
        return in_array($entityId, $graph[$key] ?? [], true);
    }

    /** @param array<string,mixed> $graph @return int[] */
    private function detailClosure(int $detailId, array $graph): array
    {
        $visited = [];
        $queue = [$detailId];
        while ($queue !== []) {
            $current = (int)array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = $current;
            foreach ($graph['detailChildren'][$current] ?? [] as $childId) {
                $queue[] = (int)$childId;
            }
        }
        ksort($visited, SORT_NUMERIC);
        return array_values($visited);
    }

    private function assertKnownElement(string $kind, int $entityId): void
    {
        $code = [
            'detail' => 'CALC_DETAILS',
            'stage' => 'CALC_STAGES',
            'settings' => 'CALC_SETTINGS',
        ][$kind] ?? '';
        if ($entityId <= 0 || $code === '' || $this->lockedIblockIds === null) {
            throw new \RuntimeException('Calculator entity authority is invalid.', 409);
        }
        $this->assertElementExists($entityId, (int)$this->lockedIblockIds[$code], $kind);
    }

    private function assertElementExists(int $elementId, int $iblockId, string $surface): void
    {
        if ($elementId <= 0 || $iblockId <= 0) {
            throw new \RuntimeException('Calculator ' . $surface . ' authority is invalid.', 409);
        }
        if (isset($this->adapters['element_exists'])) {
            if (call_user_func($this->adapters['element_exists'], $surface, $elementId, $iblockId) !== true) {
                throw new \RuntimeException(ucfirst($surface) . ' #' . $elementId . ' was not found.', 409);
            }
            return;
        }
        $row = \CIBlockElement::GetList(
            [],
            ['ID' => $elementId, 'IBLOCK_ID' => $iblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID']
        )->Fetch();
        if (!is_array($row)) {
            throw new \RuntimeException(ucfirst($surface) . ' #' . $elementId . ' was not found.', 409);
        }
    }

    private function assertLockedPreset(int $presetId): void
    {
        if ($presetId <= 0 || $this->lockedPresetId !== $presetId || $this->lockedIblockIds === null) {
            throw new \RuntimeException('Calculator mutation does not own the requested preset lock.', 409);
        }
    }

    private function assertFormulaMembers(string $raw, string $surface): void
    {
        if (trim($raw) === '') {
            return;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            self::assertFormula($raw, $surface);
            return;
        }
        $visit = static function ($value, string $path) use (&$visit, $surface): void {
            if (!is_array($value)) {
                return;
            }
            foreach ($value as $key => $item) {
                $childPath = $path . '.' . (string)$key;
                if (is_string($item)
                    && in_array(strtolower((string)$key), ['formula', 'expression'], true)) {
                    self::assertFormula($item, $surface . ' ' . $childPath);
                } elseif (is_array($item)) {
                    $visit($item, $childPath);
                }
            }
        };
        $visit($decoded, '$');
    }

    /** @param mixed $value */
    private static function canonicalJson($value): string
    {
        if (is_array($value)) {
            if (array_values($value) === $value) {
                $value = array_map([self::class, 'canonicalJsonValue'], $value);
            } else {
                ksort($value, SORT_STRING);
                foreach ($value as $key => $item) {
                    $value[$key] = self::canonicalJsonValue($item);
                }
            }
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Could not encode calculator graph revision.', 409);
        }
        return $encoded;
    }

    /** @param mixed $value @return mixed */
    private static function canonicalJsonValue($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_values($value) === $value) {
            return array_map([self::class, 'canonicalJsonValue'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalJsonValue($item);
        }
        return $value;
    }

    /** @return int[] */
    private function readPropertyIds(int $iblockId, int $elementId, string $propertyCode): array
    {
        $ids = [];
        $cursor = \CIBlockElement::GetProperty(
            $iblockId,
            $elementId,
            ['sort' => 'asc', 'id' => 'asc'],
            ['CODE' => $propertyCode]
        );
        while ($row = $cursor->Fetch()) {
            $id = (int)($row['VALUE'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        return array_map('intval', array_keys($ids));
    }

    private function readPropertyString(int $iblockId, int $elementId, string $propertyCode): string
    {
        $cursor = \CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc', 'id' => 'asc'], ['CODE' => $propertyCode]);
        $row = $cursor->Fetch();
        $value = $row['~VALUE'] ?? $row['VALUE'] ?? '';
        if (is_array($value)) $value = $value['TEXT'] ?? '';
        return is_string($value) ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }

    /** @return string[] */
    private static function identifiers(string $formula): array
    {
        $result = [];
        $length = strlen($formula);
        for ($index = 0; $index < $length;) {
            $character = $formula[$index];
            if ($character === '"' || $character === "'") {
                $quote = $character;
                $index++;
                while ($index < $length) {
                    if ($formula[$index] === '\\' && $index + 1 < $length) {
                        $index += 2;
                        continue;
                    }
                    if ($formula[$index] === $quote) {
                        $index++;
                        break;
                    }
                    $index++;
                }
                continue;
            }
            if (preg_match('/[A-Za-z_]/A', substr($formula, $index, 1)) === 1) {
                $start = $index++;
                while ($index < $length
                    && preg_match('/[A-Za-z0-9_]/A', substr($formula, $index, 1)) === 1) {
                    $index++;
                }
                $result[] = substr($formula, $start, $index - $start);
                continue;
            }
            $index++;
        }
        return $result;
    }
}
