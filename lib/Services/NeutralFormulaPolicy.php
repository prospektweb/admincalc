<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;

/**
 * Authoring boundary for the independent preset-12740 formula surface.
 *
 * Catalog entities remain available to the adapter, but executable preset,
 * registry and stage formulas may only consume semantic input and stage data.
 */
final class NeutralFormulaPolicy
{
    public const PRESET_ID = 12740;

    private const INPUT_BACKUP_OPTION = 'PRESET_12740_NEUTRAL_INPUT_BACKUP_V1';
    private const GLOBAL_BACKUP_OPTION = 'PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_BACKUP_V1';

    /** @var array<string,int>|null */
    private ?array $lockedIblockIds = null;

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
        if ($root === null) {
            return;
        }
        throw new \RuntimeException(
            'Neutral preset 12740 ' . $surface
                . ' cannot reference private runtime root "' . $root . '".',
            409
        );
    }

    public static function assertCloneAllowed(int $presetId, bool $protected, string $surface): void
    {
        if ($presetId !== self::PRESET_ID || !$protected) {
            return;
        }
        throw new \RuntimeException(
            'Cloning preset 12740 ' . $surface . ' is disabled after neutral migration begins.',
            409
        );
    }

    /**
     * Reject structural mutations by direct locked ownership, never by the
     * browser-supplied preset id alone.
     *
     * @param int[] $subjectDetailIds
     */
    public function assertStructuralMutationAllowed(
        int $requestedPresetId,
        array $subjectDetailIds,
        bool $protected,
        string $surface
    ): void {
        if (!$protected) {
            return;
        }
        if ($requestedPresetId === self::PRESET_ID) {
            throw new \RuntimeException(
                'Structural mutation of preset 12740 ' . $surface
                    . ' is disabled after neutral migration begins.',
                409
            );
        }
        foreach (array_values(array_unique(array_filter(array_map('intval', $subjectDetailIds)))) as $detailId) {
            if ($this->neutralPresetContainsDetail($detailId)) {
                throw new \RuntimeException(
                    'Structural mutation of preset 12740 ' . $surface
                        . ' is disabled after neutral migration begins.',
                    409
                );
            }
        }
    }

    public function neutralPresetContainsDetail(int $detailId): bool
    {
        if ($detailId <= 0) {
            return false;
        }
        $iblocks = $this->formulaIblockIds();
        $detailsIblockId = $iblocks['CALC_DETAILS'];
        $frontier = $this->readPropertyIds(
            $iblocks['CALC_PRESETS'],
            self::PRESET_ID,
            'CALC_DETAILS'
        );
        $visited = [];
        while ($frontier !== []) {
            $candidateId = (int)array_shift($frontier);
            if ($candidateId <= 0 || isset($visited[$candidateId])) {
                continue;
            }
            $detail = \CIBlockElement::GetList(
                [],
                ['ID' => $candidateId, 'IBLOCK_ID' => $detailsIblockId],
                false,
                ['nTopCount' => 1],
                ['ID', 'IBLOCK_ID']
            )->Fetch();
            if (!is_array($detail)) {
                throw new \RuntimeException('Neutral detail #' . $candidateId . ' was not found.', 409);
            }
            $visited[$candidateId] = true;
            if ($candidateId === $detailId) {
                return true;
            }
            foreach ($this->readPropertyIds($detailsIblockId, $candidateId, 'DETAILS') as $childId) {
                if (!isset($visited[$childId])) {
                    $frontier[] = $childId;
                }
            }
        }
        return false;
    }

    /** Direct locked ownership check for write routes that receive only a stage id. */
    public function neutralPresetContainsStage(int $stageId): bool
    {
        return $stageId > 0 && $this->presetContainsStage(self::PRESET_ID, $stageId);
    }

    /** @return int[] */
    public function presetRootDetailIds(int $presetId): array
    {
        if ($presetId <= 0) {
            return [];
        }
        $iblocks = $this->formulaIblockIds();
        $preset = \CIBlockElement::GetList(
            [],
            ['ID' => $presetId, 'IBLOCK_ID' => $iblocks['CALC_PRESETS']],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID']
        )->Fetch();
        if (!is_array($preset)) {
            throw new \RuntimeException('Calculator preset #' . $presetId . ' was not found in pinned storage.', 409);
        }
        return $this->readPropertyIds($iblocks['CALC_PRESETS'], $presetId, 'CALC_DETAILS');
    }

    /**
     * A non-neutral detail may still share a child or stage with preset 12740.
     * Destructive handlers delete those descendants by global element ID, so
     * validate the complete cascade before the first DML statement.
     */
    public function assertDetailDeletionCascadeAllowed(
        int $detailId,
        bool $protected,
        string $surface
    ): void {
        if (!$protected || $detailId <= 0) {
            return;
        }
        $iblocks = $this->formulaIblockIds();
        $detailsIblockId = $iblocks['CALC_DETAILS'];
        $frontier = [$detailId];
        $visited = [];
        while ($frontier !== []) {
            $candidateId = (int)array_shift($frontier);
            if ($candidateId <= 0 || isset($visited[$candidateId])) {
                continue;
            }
            $detail = \CIBlockElement::GetList(
                [],
                ['ID' => $candidateId, 'IBLOCK_ID' => $detailsIblockId],
                false,
                ['nTopCount' => 1],
                ['ID', 'IBLOCK_ID']
            )->Fetch();
            if (!is_array($detail)) {
                throw new \RuntimeException('Calculator detail #' . $candidateId . ' was not found.', 409);
            }
            $visited[$candidateId] = true;
            if ($this->neutralPresetContainsDetail($candidateId)) {
                throw new \RuntimeException(
                    'Destructive mutation of preset 12740 ' . $surface
                        . ' is disabled after neutral migration begins.',
                    409
                );
            }
            foreach ($this->readPropertyIds($detailsIblockId, $candidateId, 'CALC_STAGES') as $stageId) {
                if ($this->neutralPresetContainsStage($stageId)) {
                    throw new \RuntimeException(
                        'Destructive mutation of preset 12740 ' . $surface
                            . ' is disabled after neutral migration begins.',
                        409
                    );
                }
            }
            foreach ($this->readPropertyIds($detailsIblockId, $candidateId, 'DETAILS') as $childId) {
                if (!isset($visited[$childId])) {
                    $frontier[] = $childId;
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
        if (!$protected) {
            return;
        }
        if ($requestedPresetId === self::PRESET_ID
            || ($stageId > 0 && $this->neutralPresetContainsStage($stageId))) {
            throw new \RuntimeException(
                'Structural mutation of preset 12740 ' . $surface
                    . ' is disabled after neutral migration begins.',
                409
            );
        }
    }

    /** Reject legacy bulk-sync payloads that target the protected neutral graph. */
    public function assertSyncVariantsMutationAllowed(array $payload, bool $protected): void
    {
        $detailIds = [];
        $stageIds = array_values(array_map(
            'intval',
            is_array($payload['deletedConfigIds'] ?? null) ? $payload['deletedConfigIds'] : []
        ));
        $settingsIds = [];
        $queue = is_array($payload['items'] ?? null) ? array_values($payload['items']) : [];
        while ($queue !== []) {
            $item = array_shift($queue);
            if (!is_array($item)) {
                throw new \RuntimeException('Sync-variants payload item is invalid.', 409);
            }
            $detailIds[] = (int)($item['bitrixId'] ?? 0);
            foreach (['calculators', 'bindingCalculators', 'finishingCalculators'] as $key) {
                foreach (is_array($item[$key] ?? null) ? $item[$key] : [] as $calculator) {
                    if (!is_array($calculator)) {
                        throw new \RuntimeException('Sync-variants calculator is invalid.', 409);
                    }
                    $stageIds[] = (int)($calculator['configId'] ?? 0);
                    if (array_key_exists('calculatorCode', $calculator)
                        && $calculator['calculatorCode'] !== null
                        && $calculator['calculatorCode'] !== '') {
                        $settingsId = filter_var(
                            $calculator['calculatorCode'],
                            FILTER_VALIDATE_INT,
                            ['options' => ['min_range' => 1]]
                        );
                        if ($settingsId === false) {
                            throw new \RuntimeException(
                                'Sync-variants calculator settings identity is invalid.',
                                409
                            );
                        }
                        $settingsIds[] = (int)$settingsId;
                    }
                }
            }
            foreach (is_array($item['items'] ?? null) ? $item['items'] : [] as $child) {
                $queue[] = $child;
            }
        }
        $this->assertExactElementRole($detailIds, 'CALC_DETAILS', 'details');
        $this->assertExactElementRole($stageIds, 'CALC_STAGES', 'stages');
        $this->assertExactElementRole($settingsIds, 'CALC_SETTINGS', 'calculator settings');
        if (!$protected) {
            return;
        }
        foreach (array_values(array_unique(array_filter($detailIds))) as $detailId) {
            if ($this->neutralPresetContainsDetail((int)$detailId)) {
                throw new \RuntimeException(
                    'Legacy variant synchronization cannot mutate protected preset 12740 details.',
                    409
                );
            }
        }
        foreach (array_values(array_unique(array_filter($stageIds))) as $stageId) {
            if ($this->neutralPresetContainsStage((int)$stageId)) {
                throw new \RuntimeException(
                    'Legacy variant synchronization cannot mutate protected preset 12740 stages.',
                    409
                );
            }
        }
    }

    /** @param int[] $ids */
    private function assertExactElementRole(array $ids, string $iblockCode, string $surface): void
    {
        $rawIds = array_values(array_filter(array_map('intval', $ids)));
        if (count($rawIds) !== count(array_unique($rawIds))) {
            throw new \RuntimeException('Sync-variants ' . $surface . ' contain duplicate identities.', 409);
        }
        if ($rawIds === []) {
            return;
        }
        $iblocks = $this->formulaIblockIds();
        $iblockId = (int)($iblocks[$iblockCode] ?? 0);
        if ($iblockId <= 0) {
            throw new \RuntimeException('Sync-variants ' . $surface . ' iblock authority is missing.', 409);
        }
        sort($rawIds, SORT_NUMERIC);
        $actual = [];
        $cursor = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ID' => $rawIds],
            false,
            false,
            ['ID', 'IBLOCK_ID']
        );
        while ($row = $cursor->Fetch()) {
            $actual[] = (int)($row['ID'] ?? 0);
        }
        sort($actual, SORT_NUMERIC);
        if ($actual !== $rawIds) {
            throw new \RuntimeException(
                'Sync-variants ' . $surface . ' must belong to the exact pinned iblock.',
                409
            );
        }
    }

    /**
     * Validate executable condition operands against the exact pinned neutral
     * registry. Both the code and its declared kind are part of the contract.
     *
     * @param array<int,array<string,mixed>> $operands
     */
    public function assertNeutralGlobalOperands(
        array $operands,
        int $globalIblockId,
        string $surface
    ): void {
        if ($globalIblockId <= 0) {
            throw new \RuntimeException('Protected neutral registry authority is missing.', 409);
        }
        $rows = (new GlobalSymbolService())->listReadOnlyFromIblockId(
            $globalIblockId,
            self::PRESET_ID
        );
        \Prospektweb\Calc\Install\Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows($rows);
        $registry = [];
        foreach ($rows as $row) {
            $code = (string)($row['code'] ?? '');
            if ($code !== '') {
                $registry[$code] = (string)($row['kind'] ?? '');
            }
        }
        foreach ($operands as $index => $operand) {
            if (!is_array($operand)) {
                throw new \RuntimeException(
                    'Neutral ' . $surface . ' operand #' . ($index + 1) . ' is invalid.',
                    409
                );
            }
            $kind = $operand['kind'] ?? null;
            $code = $operand['code'] ?? null;
            if (!is_string($kind)
                || !in_array($kind, ['constant', 'variable'], true)
                || !is_string($code)
                || $code === ''
                || trim($code) !== $code
                || !array_key_exists($code, $registry)
                || $registry[$code] !== $kind) {
                throw new \RuntimeException(
                    'Neutral ' . $surface . ' operand #' . ($index + 1)
                        . ' does not match the pinned global registry.',
                    409
                );
            }
        }
    }

    /** @param mixed $raw */
    public function assertStageActivationConditionWrite(
        int $stageId,
        $raw,
        bool $protected,
        int $globalIblockId
    ): void {
        if (!$protected || !$this->neutralPresetContainsStage($stageId)) {
            return;
        }
        $json = self::jsonText($raw);
        if ($json === '') {
            return;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Neutral stage activation condition must be valid JSON.', 409);
        }
        $rawOperands = $decoded['operands'] ?? (
            isset($decoded['kind'], $decoded['code'])
                ? [['kind' => $decoded['kind'], 'code' => $decoded['code']]]
                : []
        );
        if (!is_array($rawOperands)) {
            throw new \RuntimeException('Neutral stage activation operands must be an array.', 409);
        }
        $this->assertNeutralGlobalOperands(array_values($rawOperands), $globalIblockId, 'stage activation');
    }

    public function assertStageMoveAllowed(
        int $requestedPresetId,
        int $stageId,
        int $sourceDetailId,
        int $targetDetailId,
        bool $protected
    ): void {
        $this->assertStructuralMutationAllowed(
            $requestedPresetId,
            [$sourceDetailId, $targetDetailId],
            $protected,
            'stage moves'
        );
        if ($protected && $stageId > 0 && $this->presetContainsStage(self::PRESET_ID, $stageId)) {
            throw new \RuntimeException(
                'Moving a preset 12740 stage is disabled after neutral migration begins.',
                409
            );
        }
    }

    /** @param mixed $raw */
    public static function assertLogicJson($raw, string $surface = 'logic'): void
    {
        $json = self::jsonText($raw);
        if ($json === '') {
            return;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Neutral preset 12740 logic must be valid JSON.', 409);
        }
        $variables = $decoded['vars'] ?? [];
        if (!is_array($variables)) {
            throw new \InvalidArgumentException('Neutral preset 12740 logic vars must be an array.', 409);
        }
        foreach ($variables as $index => $variable) {
            if (!is_array($variable)) {
                throw new \InvalidArgumentException('Neutral preset 12740 logic contains an invalid variable.', 409);
            }
            if (!array_key_exists('formula', $variable) || $variable['formula'] === null || $variable['formula'] === '') {
                continue;
            }
            if (!is_string($variable['formula'])) {
                throw new \InvalidArgumentException('Neutral preset 12740 variable formula must be a string.', 409);
            }
            $name = trim((string)($variable['name'] ?? '')) ?: '#' . ($index + 1);
            self::assertFormula($variable['formula'], $surface . ' variable ' . $name);
        }
    }

    /** @param mixed $raw */
    public static function assertGlobalAssignments($raw, string $surface = 'stage assignments'): void
    {
        $json = self::jsonText($raw);
        if ($json === '') {
            return;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Neutral preset 12740 global assignments must be valid JSON.', 409);
        }
        $assignments = $decoded['assignments'] ?? [];
        if (!is_array($assignments)) {
            throw new \InvalidArgumentException('Neutral preset 12740 global assignments must be an array.', 409);
        }
        foreach ($assignments as $index => $assignment) {
            if (!is_array($assignment)) {
                throw new \InvalidArgumentException('Neutral preset 12740 contains an invalid global assignment.', 409);
            }
            $formula = $assignment['formula'] ?? '';
            if ($formula === null || $formula === '') {
                continue;
            }
            if (!is_string($formula)) {
                throw new \InvalidArgumentException('Neutral preset 12740 assignment formula must be a string.', 409);
            }
            $name = trim((string)($assignment['globalCode'] ?? $assignment['name'] ?? '')) ?: '#' . ($index + 1);
            self::assertFormula($formula, $surface . ' variable ' . $name);
        }
    }

    /** @param mixed $raw */
    public static function assertInputMappings($raw, string $surface = 'stage inputs'): void
    {
        if ($raw === false || $raw === null || $raw === '' || $raw === []) {
            return;
        }
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Neutral preset 12740 inputs must be an array.', 409);
        }
        $rows = array_key_exists('VALUE', $raw) || array_key_exists('DESCRIPTION', $raw)
            ? [$raw]
            : array_values($raw);
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Neutral preset 12740 contains an invalid input mapping.', 409);
            }
            $parameter = trim((string)($row['VALUE'] ?? $row['name'] ?? ''));
            $reservedParameter = isset(self::FORBIDDEN_ROOTS[$parameter])
                || in_array($parameter, ['input', 'preset', 'globalValues', 'CURRENT_STAGE'], true)
                || in_array($parameter, ['__proto__', 'prototype', 'constructor'], true)
                || preg_match('/^stage_\d+$/D', $parameter) === 1;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $parameter) !== 1 || $reservedParameter) {
                throw new \RuntimeException(
                    'Neutral preset 12740 ' . $surface . ' parameter #'
                        . ($index + 1) . ' is invalid or reserved: ' . $parameter,
                    409
                );
            }
            $path = trim((string)($row['DESCRIPTION'] ?? $row['path'] ?? ''));
            if (strpos($path, '__literal__:') === 0) {
                continue;
            }
            $semantic = preg_match(
                '/^input\.values\.[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D',
                $path
            ) === 1;
            $stage = preg_match(
                '/^stage_\d+\.(?:(?:outputVar|outputSlug)\.[A-Za-z0-9_.-]+'
                    . '|(?:operationVariant|materialVariant|operation|material|equipment)'
                    . '(?:\.[A-Za-z0-9_.-]+)?)$/D',
                $path
            ) === 1;
            $unsafeSegment = array_filter(
                explode('.', $path),
                static fn(string $segment): bool => in_array(
                    $segment,
                    ['__proto__', 'prototype', 'constructor'],
                    true
                )
            ) !== [];
            if ((!$semantic && !$stage) || $unsafeSegment) {
                throw new \RuntimeException(
                    'Neutral preset 12740 ' . $surface . ' mapping ' . $parameter
                        . ' is outside the semantic/stage allowlist: ' . $path,
                    409
                );
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $variables
     * @param array<int,array<string,mixed>> $constants
     */
    public function assertPresetGlobalsWrite(
        int $presetId,
        array $variables,
        array $constants,
        ?bool $neutralInputActive = null
    ): void
    {
        if ($presetId !== self::PRESET_ID
            || !($neutralInputActive ?? $this->neutralInputActiveDirect())) {
            return;
        }
        foreach (array_merge($variables, $constants) as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Neutral preset 12740 global row must be an object.', 409);
            }
            $code = trim((string)($row['VALUE'] ?? '')) ?: '#' . ($index + 1);
            self::assertFormula(
                self::firstDescriptionField((string)($row['DESCRIPTION'] ?? '')),
                'preset global ' . $code
            );
        }
    }

    /** @param mixed $raw */
    public function assertSettingsLogicWrite(
        int $settingsId,
        $raw,
        ?bool $neutralInputActive = null
    ): void
    {
        if ($settingsId <= 0 || !($neutralInputActive ?? $this->neutralInputActiveDirect())) {
            return;
        }
        $iblocks = $this->formulaIblockIds();
        foreach ($this->reachablePresetStageIds(
            $iblocks['CALC_PRESETS'],
            $iblocks['CALC_DETAILS'],
            self::PRESET_ID
        ) as $stageId) {
            if (in_array($settingsId, $this->readPropertyIds(
                $iblocks['CALC_STAGES'],
                $stageId,
                'CALC_SETTINGS'
            ), true)) {
                self::assertLogicJson($raw, 'calculator #' . $settingsId);
                return;
            }
        }
    }

    /** @param mixed $raw */
    public function assertStageAssignmentsWrite(
        int $stageId,
        $raw,
        ?bool $neutralInputActive = null
    ): void
    {
        if ($stageId <= 0 || !($neutralInputActive ?? $this->neutralInputActiveDirect())) {
            return;
        }
        if ($this->presetContainsStage(self::PRESET_ID, $stageId)) {
            self::assertGlobalAssignments($raw, 'stage #' . $stageId . ' assignments');
        }
    }

    /** @param mixed $raw */
    public function assertStageInputsWrite(
        int $stageId,
        $raw,
        ?bool $neutralInputActive = null
    ): void {
        if ($stageId <= 0 || !($neutralInputActive ?? $this->neutralInputActiveDirect())) {
            return;
        }
        if ($this->presetContainsStage(self::PRESET_ID, $stageId)) {
            self::assertInputMappings($raw, 'stage #' . $stageId . ' inputs');
        }
    }

    /**
     * Validate a calculator before it becomes executable through an existing
     * preset-12740 stage. The preset id supplied by the browser is never used
     * as the ownership authority: direct topology is read back from Bitrix.
     */
    public function assertSettingsLinkToStage(
        int $requestedPresetId,
        int $stageId,
        int $settingsId,
        ?bool $neutralInputActive = null
    ): void {
        if ($stageId <= 0 || !($neutralInputActive ?? $this->neutralInputActiveDirect())) {
            return;
        }
        $belongsToNeutralPreset = $this->presetContainsStage(self::PRESET_ID, $stageId);
        if ($requestedPresetId === self::PRESET_ID && !$belongsToNeutralPreset) {
            throw new \RuntimeException(
                'Stage #' . $stageId . ' is not part of neutral preset 12740.',
                409
            );
        }
        if (!$belongsToNeutralPreset) {
            return;
        }
        $this->assertStageFormulaSurfaces($stageId, $settingsId);
    }

    /** Validate every executable formula before a stage is linked to 12740. */
    public function assertStageLinkToPreset(
        int $presetId,
        int $stageId,
        ?bool $neutralInputActive = null
    ): void {
        if ($presetId !== self::PRESET_ID
            || $stageId <= 0
            || !($neutralInputActive ?? $this->neutralInputActiveDirect())) {
            return;
        }
        $this->assertStageFormulaSurfaces($stageId, null);
    }

    public function neutralInputActiveDirect(): bool
    {
        return $this->readNeutralInputActive(Application::getConnection(), false);
    }

    /**
     * Re-audit every currently reachable formula while activation owns the
     * ACTIVE row lock. This closes the writer-first side of the N -> Y race:
     * an edit committed before activation is observed before cut-over.
     */
    public function assertCurrentPresetAuthoringStateSafe(
        array $pinnedConfigSnapshot,
        int $globalIblockId = 0
    ): void
    {
        $presetIblockId = (int)($pinnedConfigSnapshot['presetIblockId'] ?? 0);
        $detailsIblockId = (int)($pinnedConfigSnapshot['detailsIblockId'] ?? 0);
        $settingsIblockId = (int)($pinnedConfigSnapshot['settingsIblockId'] ?? 0);
        $stageIblockId = (int)($pinnedConfigSnapshot['stagesIblockId'] ?? 0);
        if ($presetIblockId <= 0
            || $detailsIblockId <= 0
            || $settingsIblockId <= 0
            || $stageIblockId <= 0) {
            throw new \RuntimeException('Neutral preset formula storages are not configured.', 409);
        }

        if ($globalIblockId <= 0) {
            throw new \RuntimeException('Neutral global registry is not pinned for activation.', 409);
        }
        $conditionOperands = [];
        $stageGroups = \CIBlockElement::GetProperty(
            $presetIblockId,
            self::PRESET_ID,
            ['sort' => 'asc'],
            ['CODE' => 'STAGE_GROUPS']
        );
        while ($row = $stageGroups->Fetch()) {
            $decoded = self::decodeJsonObject(
                $row['~VALUE'] ?? $row['VALUE'] ?? '',
                'neutral stage groups'
            );
            foreach ((array)($decoded['groups'] ?? []) as $group) {
                if (!is_array($group)) {
                    throw new \RuntimeException('Neutral stage group is invalid.', 409);
                }
                foreach ((array)($group['branches'] ?? []) as $branch) {
                    if (!is_array($branch)) {
                        throw new \RuntimeException('Neutral stage-group branch is invalid.', 409);
                    }
                    foreach ((array)($branch['operands'] ?? []) as $operand) {
                        $conditionOperands[] = $operand;
                    }
                }
            }
        }
        $pricePolicies = \CIBlockElement::GetProperty(
            $presetIblockId,
            self::PRESET_ID,
            ['sort' => 'asc'],
            ['CODE' => 'PRICE_PROFILE_POLICY_JSON']
        );
        while ($row = $pricePolicies->Fetch()) {
            $decoded = self::decodeJsonObject(
                $row['~VALUE'] ?? $row['VALUE'] ?? '',
                'neutral conditional price policy'
            );
            foreach ((array)($decoded['rules'] ?? []) as $rule) {
                if (!is_array($rule) || !is_array($rule['condition'] ?? null)) {
                    throw new \RuntimeException('Neutral conditional price rule is invalid.', 409);
                }
                $conditionOperands[] = $rule['condition'];
            }
        }

        foreach (['GLOBAL_VARIABLES', 'GLOBAL_CONSTANTS'] as $propertyCode) {
            $rows = \CIBlockElement::GetProperty(
                $presetIblockId,
                self::PRESET_ID,
                ['sort' => 'asc'],
                ['CODE' => $propertyCode]
            );
            while ($row = $rows->Fetch()) {
                self::assertFormula(
                    self::firstDescriptionField((string)($row['DESCRIPTION'] ?? '')),
                    'preset global ' . trim((string)($row['VALUE'] ?? ''))
                );
            }
        }

        $ownedStageIds = $this->reachablePresetStageIds(
            $presetIblockId,
            $detailsIblockId,
            self::PRESET_ID
        );
        foreach ($ownedStageIds as $stageId) {
            $stage = \CIBlockElement::GetList(
                [],
                ['ID' => $stageId, 'IBLOCK_ID' => $stageIblockId],
                false,
                ['nTopCount' => 1],
                ['ID', 'IBLOCK_ID']
            )->Fetch();
            if (!is_array($stage)) {
                throw new \RuntimeException('Neutral stage #' . $stageId . ' was not found.', 409);
            }
            $inputRows = [];
            $inputs = \CIBlockElement::GetProperty(
                $stageIblockId,
                (int)$stageId,
                ['sort' => 'asc'],
                ['CODE' => 'INPUTS']
            );
            while ($input = $inputs->Fetch()) {
                $inputRows[] = [
                    'VALUE' => $input['~VALUE'] ?? $input['VALUE'] ?? '',
                    'DESCRIPTION' => $input['~DESCRIPTION'] ?? $input['DESCRIPTION'] ?? '',
                ];
            }
            self::assertInputMappings($inputRows, 'stage #' . (int)$stageId . ' inputs');

            $activationRows = \CIBlockElement::GetProperty(
                $stageIblockId,
                (int)$stageId,
                ['sort' => 'asc'],
                ['CODE' => 'ACTIVATION_CONDITION']
            );
            while ($activation = $activationRows->Fetch()) {
                $decoded = self::decodeJsonObject(
                    $activation['~VALUE'] ?? $activation['VALUE'] ?? '',
                    'neutral stage activation condition'
                );
                $operands = $decoded['operands'] ?? (
                    isset($decoded['kind'], $decoded['code'])
                        ? [['kind' => $decoded['kind'], 'code' => $decoded['code']]]
                        : []
                );
                if (!is_array($operands)) {
                    throw new \RuntimeException('Neutral stage activation operands are invalid.', 409);
                }
                foreach ($operands as $operand) {
                    $conditionOperands[] = $operand;
                }
            }

            $assignmentRows = \CIBlockElement::GetProperty(
                $stageIblockId,
                (int)$stageId,
                ['sort' => 'asc'],
                ['CODE' => 'GLOBAL_ASSIGNMENTS']
            );
            while ($assignments = $assignmentRows->Fetch()) {
                self::assertGlobalAssignments(
                    $assignments['~VALUE'] ?? $assignments['VALUE'] ?? '',
                    'stage #' . (int)$stageId . ' assignments'
                );
            }

            foreach ($this->readPropertyIds($stageIblockId, (int)$stageId, 'CALC_SETTINGS') as $settingsId) {
                $settings = \CIBlockElement::GetList(
                    [],
                    ['ID' => $settingsId, 'IBLOCK_ID' => $settingsIblockId],
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'IBLOCK_ID']
                )->Fetch();
                if (!is_array($settings)) {
                    throw new \RuntimeException('Neutral calculator #' . $settingsId . ' was not found.', 409);
                }
                $logicRows = \CIBlockElement::GetProperty(
                    $settingsIblockId,
                    $settingsId,
                    ['sort' => 'asc'],
                    ['CODE' => 'LOGIC_JSON']
                );
                while ($logic = $logicRows->Fetch()) {
                    self::assertLogicJson(
                        $logic['~VALUE'] ?? $logic['VALUE'] ?? '',
                        'calculator #' . $settingsId
                    );
                }
            }
        }
        $this->assertNeutralGlobalOperands(
            $conditionOperands,
            $globalIblockId,
            'executable condition'
        );
    }

    /**
     * Serialize an authoring mutation with both neutral authorities.
     *
     * The callback receives whether the independent formula contract must be
     * enforced. That is true after either the global migration marker exists
     * or the final input cut-over is active; safe authoring therefore remains
     * possible while an inactive migrated preset is being reviewed.
     *
     * @template T
     * @param callable(bool,array<string,int>,array<string,mixed>):T $mutation
     * @return T
     */
    public function withActiveAuthorityLock(callable $mutation)
    {
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $authority = $this->lockNeutralContractAuthority($connection);
            $this->lockedIblockIds = $authority['iblockIds'];
            if ($authority['recoveryProtected']) {
                throw new \RuntimeException(
                    'Preset 12740 is in retained-backup recovery state; authoring is frozen until exact reapply.',
                    409
                );
            }
            $enforceNeutralContract = $authority['active'] || $authority['markerExists'];
            $result = $mutation($enforceNeutralContract, $authority['iblockIds'], $authority);
            $this->lockedIblockIds = null;
            $connection->commitTransaction();
            return $result;
        } catch (\Throwable $error) {
            $this->lockedIblockIds = null;
            $connection->rollbackTransaction();
            throw $error;
        }
    }

    /**
     * Resolve the same direct global authorities as the writer lock without
     * provisioning storage, claiming rows or consulting process caches.
     *
     * @return array{active:bool,markerExists:bool,recoveryProtected:bool,iblockIds:array<string,int>,globalIblockId:int}
     */
    public function readNeutralContractAuthority(): array
    {
        return $this->readNeutralContractAuthorityFromConnection(
            Application::getConnection(),
            false
        );
    }

    /**
     * @param object $connection
     * @return array{active:bool,markerExists:bool,recoveryProtected:bool,iblockIds:array<string,int>,globalIblockId:int}
     */
    public function lockNeutralContractAuthority($connection): array
    {
        return $this->readNeutralContractAuthorityFromConnection($connection, true);
    }

    /**
     * @param object $connection
     * @return array{active:bool,markerExists:bool,recoveryProtected:bool,iblockIds:array<string,int>,globalIblockId:int}
     */
    private function readNeutralContractAuthorityFromConnection($connection, bool $forUpdate): array
    {
        $cursor = $connection->query(
            "SELECT MODULE_ID, NAME, VALUE, SITE_ID FROM b_option "
            . "WHERE ((MODULE_ID='prospektweb.calc' "
            . "AND UPPER(NAME) IN ('IBLOCK_CALC_DETAILS','IBLOCK_CALC_PRESETS',"
            . "'IBLOCK_CALC_SETTINGS','IBLOCK_CALC_STAGES','IBLOCK_CALC_GLOBAL_VALUES',"
            . "'IBLOCK_CALC_OPERATIONS_VARIANTS','IBLOCK_CALC_MATERIALS_VARIANTS',"
            . "'IBLOCK_CALC_EQUIPMENT',"
            . "'PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_BACKUP_V1',"
            . "'PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_MIGRATION_V1',"
            . "'PRESET_12740_NEUTRAL_INPUT_ACTIVE',"
            . "'PRESET_12740_NEUTRAL_INPUT_BACKUP_V1')) "
            . "OR (MODULE_ID='prospektweb.frontcalc' "
            . "AND UPPER(NAME)='IBLOCK_CALC_GLOBAL_VALUES')) "
            . "AND (SITE_ID IS NULL OR SITE_ID='') "
            . 'ORDER BY MODULE_ID, NAME, SITE_ID'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $values = [];
        $calcValues = [];
        while ($row = $cursor->fetch()) {
            $moduleId = (string)($row['MODULE_ID'] ?? '');
            $actualName = (string)($row['NAME'] ?? '');
            $canonicalName = strtoupper($actualName);
            $allowed = $moduleId === 'prospektweb.calc'
                ? [
                    'IBLOCK_CALC_DETAILS',
                    'IBLOCK_CALC_PRESETS',
                    'IBLOCK_CALC_SETTINGS',
                    'IBLOCK_CALC_STAGES',
                    'IBLOCK_CALC_GLOBAL_VALUES',
                    'IBLOCK_CALC_OPERATIONS_VARIANTS',
                    'IBLOCK_CALC_MATERIALS_VARIANTS',
                    'IBLOCK_CALC_EQUIPMENT',
                    'PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_BACKUP_V1',
                    'PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_MIGRATION_V1',
                    'PRESET_12740_NEUTRAL_INPUT_ACTIVE',
                    'PRESET_12740_NEUTRAL_INPUT_BACKUP_V1',
                ]
                : ($moduleId === 'prospektweb.frontcalc' ? ['IBLOCK_CALC_GLOBAL_VALUES'] : []);
            $authorityKey = $moduleId . ':' . $canonicalName;
            if ($actualName !== trim($actualName)
                || !in_array($canonicalName, $allowed, true)
                || array_key_exists($authorityKey, $values)
                || !array_key_exists('SITE_ID', $row)
                || !in_array($row['SITE_ID'], [null, ''], true)) {
                throw new \RuntimeException('Neutral contract option authority is invalid.', 409);
            }
            $values[$authorityKey] = (string)($row['VALUE'] ?? '');
            if ($moduleId === 'prospektweb.calc') {
                $calcValues[$canonicalName] = (string)($row['VALUE'] ?? '');
            }
        }
        $activeValue = (string)($calcValues['PRESET_12740_NEUTRAL_INPUT_ACTIVE'] ?? 'N');
        if (!in_array($activeValue, ['N', 'Y'], true)) {
            throw new \RuntimeException('Neutral activation authority is invalid.', 409);
        }
        $markerExists = array_key_exists(
            'PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_MIGRATION_V1',
            $calcValues
        );
        if ($markerExists
            && trim((string)$calcValues['PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_MIGRATION_V1']) === '') {
            throw new \RuntimeException('Neutral global-symbol migration marker is invalid.', 409);
        }
        $inputBackupExists = array_key_exists(self::INPUT_BACKUP_OPTION, $calcValues);
        $globalBackupExists = array_key_exists(self::GLOBAL_BACKUP_OPTION, $calcValues);
        foreach ([
            self::INPUT_BACKUP_OPTION => $inputBackupExists,
            self::GLOBAL_BACKUP_OPTION => $globalBackupExists,
        ] as $optionName => $exists) {
            if ($exists && trim((string)$calcValues[$optionName]) === '') {
                throw new \RuntimeException('Neutral retained-backup authority is invalid.', 409);
            }
        }
        // A V1 backup with ACTIVE=N identifies either terminal rollback or the
        // between-commit V1-rolled-back/V2-still-applied state. A lone V2
        // backup becomes recovery evidence once its marker is gone.
        $recoveryProtected = $activeValue === 'N'
            && ($inputBackupExists || (!$markerExists && $globalBackupExists));
        $iblockIds = self::requiredIblockIdsFromValues($calcValues);
        foreach ([
            'CALC_OPERATIONS_VARIANTS' => 'IBLOCK_CALC_OPERATIONS_VARIANTS',
            'CALC_MATERIALS_VARIANTS' => 'IBLOCK_CALC_MATERIALS_VARIANTS',
            'CALC_EQUIPMENT' => 'IBLOCK_CALC_EQUIPMENT',
        ] as $code => $optionName) {
            if (!array_key_exists($optionName, $calcValues)) {
                continue;
            }
            $raw = (string)$calcValues[$optionName];
            if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1 || (string)(int)$raw !== $raw) {
                throw new \RuntimeException('Neutral resource iblock authority is invalid.', 409);
            }
            $iblockIds[$code] = (int)$raw;
        }
        $globalIds = [];
        foreach ([
            'prospektweb.calc:IBLOCK_CALC_GLOBAL_VALUES',
            'prospektweb.frontcalc:IBLOCK_CALC_GLOBAL_VALUES',
        ] as $authorityKey) {
            if (!array_key_exists($authorityKey, $values)) {
                continue;
            }
            $raw = (string)$values[$authorityKey];
            if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1 || (string)(int)$raw !== $raw) {
                throw new \RuntimeException('Global-symbol iblock option authority is invalid.', 409);
            }
            $globalIds[(int)$raw] = true;
        }
        if (count($globalIds) > 1) {
            throw new \RuntimeException('Global-symbol iblock option authorities disagree.', 409);
        }
        if (($activeValue === 'Y' || $markerExists || $recoveryProtected) && count($globalIds) !== 1) {
            throw new \RuntimeException('Protected neutral registry authority is missing.', 409);
        }
        return [
            'active' => $activeValue === 'Y',
            'markerExists' => $markerExists,
            'recoveryProtected' => $recoveryProtected,
            'iblockIds' => $iblockIds,
            'globalIblockId' => $globalIds === [] ? 0 : (int)array_key_first($globalIds),
        ];
    }

    /** @return array<string,int> */
    private function formulaIblockIds(): array
    {
        if (is_array($this->lockedIblockIds)) {
            return $this->lockedIblockIds;
        }
        $cursor = Application::getConnection()->query(
            "SELECT MODULE_ID, NAME, VALUE, SITE_ID FROM b_option "
            . "WHERE MODULE_ID='prospektweb.calc' "
            . "AND UPPER(NAME) IN ('IBLOCK_CALC_DETAILS','IBLOCK_CALC_PRESETS',"
            . "'IBLOCK_CALC_SETTINGS','IBLOCK_CALC_STAGES') "
            . "AND (SITE_ID IS NULL OR SITE_ID='') ORDER BY MODULE_ID, NAME, SITE_ID"
        );
        $values = [];
        while ($row = $cursor->fetch()) {
            $actualName = (string)($row['NAME'] ?? '');
            $canonicalName = strtoupper($actualName);
            if ((string)($row['MODULE_ID'] ?? '') !== 'prospektweb.calc'
                || $actualName !== trim($actualName)
                || !in_array($canonicalName, [
                    'IBLOCK_CALC_DETAILS',
                    'IBLOCK_CALC_PRESETS',
                    'IBLOCK_CALC_SETTINGS',
                    'IBLOCK_CALC_STAGES',
                ], true)
                || isset($values[$canonicalName])
                || !array_key_exists('SITE_ID', $row)
                || !in_array($row['SITE_ID'], [null, ''], true)) {
                throw new \RuntimeException('Neutral formula iblock authority is invalid.', 409);
            }
            $values[$canonicalName] = (string)($row['VALUE'] ?? '');
        }
        return self::requiredIblockIdsFromValues($values);
    }

    /** @param array<string,string> $values @return array<string,int> */
    private static function requiredIblockIdsFromValues(array $values): array
    {
        $iblockIds = [];
        foreach ([
            'CALC_DETAILS' => 'IBLOCK_CALC_DETAILS',
            'CALC_PRESETS' => 'IBLOCK_CALC_PRESETS',
            'CALC_SETTINGS' => 'IBLOCK_CALC_SETTINGS',
            'CALC_STAGES' => 'IBLOCK_CALC_STAGES',
        ] as $code => $optionName) {
            $raw = (string)($values[$optionName] ?? '');
            if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) {
                throw new \RuntimeException('Neutral formula iblock authority is missing or invalid.', 409);
            }
            $iblockIds[$code] = (int)$raw;
        }
        return $iblockIds;
    }

    /** @param object $connection */
    private function readNeutralInputActive($connection, bool $forUpdate): bool
    {
        $cursor = $connection->query(
            "SELECT MODULE_ID, NAME, VALUE, SITE_ID FROM b_option "
            . "WHERE MODULE_ID='prospektweb.calc' "
            . "AND UPPER(NAME)='PRESET_12740_NEUTRAL_INPUT_ACTIVE' "
            . "AND (SITE_ID IS NULL OR SITE_ID='') ORDER BY NAME, SITE_ID"
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $row = $cursor->fetch();
        if ($cursor->fetch() !== false) {
            throw new \RuntimeException('Duplicate neutral activation authority.', 409);
        }
        if ($row === false) {
            return false;
        }
        $name = (string)($row['NAME'] ?? '');
        $value = (string)($row['VALUE'] ?? '');
        if ((string)($row['MODULE_ID'] ?? '') !== 'prospektweb.calc'
            || strtoupper($name) !== 'PRESET_12740_NEUTRAL_INPUT_ACTIVE'
            || $name !== trim($name)
            || !array_key_exists('SITE_ID', $row)
            || !in_array($row['SITE_ID'], [null, ''], true)
            || !in_array($value, ['N', 'Y'], true)) {
            throw new \RuntimeException('Neutral activation authority is invalid.', 409);
        }
        return $value === 'Y';
    }

    private function assertStageFormulaSurfaces(int $stageId, ?int $settingsId): void
    {
        $iblocks = $this->formulaIblockIds();
        $stageIblockId = $iblocks['CALC_STAGES'];
        $settingsIblockId = $iblocks['CALC_SETTINGS'];
        $stage = \CIBlockElement::GetList(
            [],
            ['ID' => $stageId, 'IBLOCK_ID' => $stageIblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID']
        )->Fetch();
        if (!is_array($stage)) {
            throw new \RuntimeException('Neutral stage #' . $stageId . ' was not found.', 409);
        }

        $inputRows = [];
        $inputs = \CIBlockElement::GetProperty(
            $stageIblockId,
            $stageId,
            ['sort' => 'asc'],
            ['CODE' => 'INPUTS']
        );
        while ($input = $inputs->Fetch()) {
            $inputRows[] = [
                'VALUE' => $input['~VALUE'] ?? $input['VALUE'] ?? '',
                'DESCRIPTION' => $input['~DESCRIPTION'] ?? $input['DESCRIPTION'] ?? '',
            ];
        }
        self::assertInputMappings($inputRows, 'stage #' . $stageId . ' inputs');

        $assignmentRows = \CIBlockElement::GetProperty(
            $stageIblockId,
            $stageId,
            ['sort' => 'asc'],
            ['CODE' => 'GLOBAL_ASSIGNMENTS']
        );
        while ($assignments = $assignmentRows->Fetch()) {
            self::assertGlobalAssignments(
                $assignments['~VALUE'] ?? $assignments['VALUE'] ?? '',
                'stage #' . $stageId . ' assignments'
            );
        }

        if ($settingsId === null) {
            $settingsIds = $this->readPropertyIds($stageIblockId, $stageId, 'CALC_SETTINGS');
            $settingsId = (int)($settingsIds[0] ?? 0);
        }
        if ($settingsId <= 0) {
            return;
        }
        $settings = \CIBlockElement::GetList(
            [],
            ['ID' => $settingsId, 'IBLOCK_ID' => $settingsIblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID']
        )->Fetch();
        if (!is_array($settings)) {
            throw new \RuntimeException('Neutral calculator #' . $settingsId . ' was not found.', 409);
        }
        $logicRows = \CIBlockElement::GetProperty(
            $settingsIblockId,
            $settingsId,
            ['sort' => 'asc'],
            ['CODE' => 'LOGIC_JSON']
        );
        while ($logic = $logicRows->Fetch()) {
            self::assertLogicJson(
                $logic['~VALUE'] ?? $logic['VALUE'] ?? '',
                'calculator #' . $settingsId
            );
        }
    }

    /** @return int[] */
    private function reachablePresetStageIds(
        int $presetIblockId,
        int $detailsIblockId,
        int $presetId
    ): array {
        $stageIds = [];
        foreach ($this->readPropertyIds($presetIblockId, $presetId, 'CALC_STAGES') as $stageId) {
            $stageIds[$stageId] = true;
        }
        $frontier = $this->readPropertyIds($presetIblockId, $presetId, 'CALC_DETAILS');
        $visited = [];
        while ($frontier !== []) {
            $detailId = (int)array_shift($frontier);
            if ($detailId <= 0 || isset($visited[$detailId])) {
                continue;
            }
            $detail = \CIBlockElement::GetList(
                [],
                ['ID' => $detailId, 'IBLOCK_ID' => $detailsIblockId],
                false,
                ['nTopCount' => 1],
                ['ID', 'IBLOCK_ID']
            )->Fetch();
            if (!is_array($detail)) {
                throw new \RuntimeException('Neutral detail #' . $detailId . ' was not found.', 409);
            }
            $visited[$detailId] = true;
            foreach ($this->readPropertyIds($detailsIblockId, $detailId, 'CALC_STAGES') as $stageId) {
                $stageIds[$stageId] = true;
            }
            foreach ($this->readPropertyIds($detailsIblockId, $detailId, 'DETAILS') as $childId) {
                if (!isset($visited[$childId])) {
                    $frontier[] = $childId;
                }
            }
        }
        $result = array_map('intval', array_keys($stageIds));
        sort($result, SORT_NUMERIC);
        return $result;
    }

    private function presetContainsStage(int $presetId, int $stageId): bool
    {
        $iblocks = $this->formulaIblockIds();
        $presetIblockId = $iblocks['CALC_PRESETS'];
        $detailIblockId = $iblocks['CALC_DETAILS'];
        if (in_array($stageId, $this->readPropertyIds(
            $presetIblockId,
            $presetId,
            'CALC_STAGES'
        ), true)) {
            return true;
        }

        $frontier = $this->readPropertyIds($presetIblockId, $presetId, 'CALC_DETAILS');
        $visited = [];
        while ($frontier !== []) {
            $detailId = (int)array_shift($frontier);
            if ($detailId <= 0 || isset($visited[$detailId])) {
                continue;
            }
            $visited[$detailId] = true;
            if (in_array($stageId, $this->readPropertyIds(
                $detailIblockId,
                $detailId,
                'CALC_STAGES'
            ), true)) {
                return true;
            }
            foreach ($this->readPropertyIds($detailIblockId, $detailId, 'DETAILS') as $childId) {
                if (!isset($visited[$childId])) {
                    $frontier[] = $childId;
                }
            }
        }
        return false;
    }

    /** @return int[] */
    private function readPropertyIds(int $iblockId, int $elementId, string $propertyCode): array
    {
        $ids = [];
        $rows = \CIBlockElement::GetProperty(
            $iblockId,
            $elementId,
            ['sort' => 'asc'],
            ['CODE' => $propertyCode]
        );
        while ($row = $rows->Fetch()) {
            $id = (int)($row['VALUE'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $result = array_map('intval', array_keys($ids));
        sort($result, SORT_NUMERIC);
        return $result;
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

    /** @param mixed $raw @return array<string,mixed> */
    private static function decodeJsonObject($raw, string $surface): array
    {
        $json = self::jsonText($raw);
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || array_is_list($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(ucfirst($surface) . ' must be a valid JSON object.', 409);
        }
        return $decoded;
    }

    /** @param mixed $raw */
    private static function jsonText($raw): string
    {
        if (is_string($raw) || is_numeric($raw)) {
            return trim(html_entity_decode((string)$raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (!is_array($raw)) {
            return '';
        }
        foreach (['~VALUE', 'VALUE', 'TEXT'] as $key) {
            if (array_key_exists($key, $raw)) {
                return self::jsonText($raw[$key]);
            }
        }
        return '';
    }

    private static function firstDescriptionField(string $description): string
    {
        $formula = '';
        $length = strlen($description);
        for ($index = 0; $index < $length; $index++) {
            $character = $description[$index];
            if ($character === '\\' && $index + 1 < $length
                && in_array($description[$index + 1], ['|', '\\'], true)) {
                $formula .= $description[++$index];
                continue;
            }
            if ($character === '|') {
                break;
            }
            $formula .= $character;
        }
        return $formula;
    }
}
