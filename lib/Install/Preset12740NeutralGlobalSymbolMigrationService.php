<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Install;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Services\CatalogAdapterDefinitionService;
use Prospektweb\Calc\Services\NeutralFormulaPolicy;

/**
 * One-time migration of preset-12740 global registry formulas.
 *
 * The original neutral-input migration owns preset-local globals and stage
 * INPUTS. The global registry is a separate iblock and therefore needs its
 * own exact, versioned and recoverable migration boundary.
 */
final class Preset12740NeutralGlobalSymbolMigrationService
{
    public const CONTRACT = 'prospektweb.calc.preset-12740-neutral-global-symbol-migration/v2';
    public const PRESET_ID = 12740;
    public const EXPECTED_MUTATION_COUNT = 24;
    public const EXPECTED_PROSPECTIVE_RUNTIME_ROW_COUNT = 37;

    /**
     * The immutable v2 evidence was produced before the public form dimension
     * was renamed from height to length. Keep that exact historical target
     * available only for evidence verification; current authoring continues
     * to use the canonical length formula from SYMBOLS.
     */
    private const HISTORICAL_V2_VALUE_FORMAT_TEXT =
        'toString(get(input, "values.format.width")) + "×" + '
        . 'toString(get(input, "values.format.height")) + " мм"';

    private const MODULE_ID = 'prospektweb.calc';
    private const CONFIG_OPTION = 'IBLOCK_CALC_GLOBAL_VALUES';
    private const ACTIVE_OPTION = Preset12740NeutralInputMigrationService::ACTIVE_OPTION;
    private const BACKUP_OPTION = 'PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_BACKUP_V1';
    private const MARKER_OPTION = 'PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_MIGRATION_V1';

    /** @var array<string,list<string>> */
    private const OPTION_NAMES_BY_MODULE = [
        'prospektweb.calc' => [
            self::CONFIG_OPTION,
            self::ACTIVE_OPTION,
            self::BACKUP_OPTION,
            self::MARKER_OPTION,
        ],
        'prospektweb.frontcalc' => [
            self::CONFIG_OPTION,
        ],
    ];

    /** @var list<string> */
    private const IMMUTABLE_EVIDENCE_OPTION_NAMES = [
        self::BACKUP_OPTION,
        self::MARKER_OPTION,
    ];

    /** @var list<string> */
    private const RESERVED_GLOBAL_CODES = [
        'if', 'round', 'ceil', 'floor', 'min', 'max', 'abs', 'trim', 'lower', 'upper',
        'len', 'contains', 'replace', 'tonumber', 'tostring', 'split', 'join', 'get',
        'getprice', 'regexmatch', 'regexextract', 'true', 'false', 'null', 'undefined',
        'input', 'product', 'offer', 'calculator', 'operation', 'operationvariant',
        'equipment', 'material', 'materialvariant', 'stage', 'preset', 'selectedoffer',
        'selectedoffers', 'context', 'iblocks', 'elementsstore', 'pricetypes',
        'resources', 'globalsymbols', 'globalvalues', 'current_stage',
        '__proto__', 'prototype', 'constructor',
    ];

    /** @var array<string,array{id:int,kind:string,dataType:string,legacy:string,neutral:string}> */
    private const SYMBOLS = [
        'is_roll_lamination' => [
            'id' => 12777,
            'kind' => 'constant',
            'dataType' => 'boolean',
            'legacy' => 'contains(get(offer, "properties.CALC_PROP_PROTECTION.VALUE_XML_ID"), "lamination-rulon")',
            'neutral' => 'contains(get(input, "values.protection"), "lamination-rulon")',
        ],
        'is_offset_printing' => [
            'id' => 12780,
            'kind' => 'constant',
            'dataType' => 'boolean',
            'legacy' => 'get(offer, "properties.CALC_PROP_METHOD.VALUE_XML_ID") == "OFSET"',
            'neutral' => 'get(input, "values.method") == "OFSET"',
        ],
        'is_pouch_lamination' => [
            'id' => 12787,
            'kind' => 'constant',
            'dataType' => 'boolean',
            'legacy' => 'contains(get(offer, "properties.CALC_PROP_PROTECTION.VALUE_XML_ID"), "lamination-pocket")',
            'neutral' => 'contains(get(input, "values.protection"), "lamination-pocket")',
        ],
        'is_digital_printing' => [
            'id' => 12790,
            'kind' => 'constant',
            'dataType' => 'boolean',
            'legacy' => 'get(offer, "properties.CALC_PROP_METHOD.VALUE_XML_ID") == "DIGITAL"',
            'neutral' => 'get(input, "values.method") == "DIGITAL"',
        ],
        'is_coated_paper' => [
            'id' => 12791,
            'kind' => 'constant',
            'dataType' => 'boolean',
            'legacy' => 'get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "mel-paper"',
            'neutral' => 'get(input, "values.type.paper") == "mel-paper"',
        ],
        'is_offset_paper' => [
            'id' => 12792,
            'kind' => 'constant',
            'dataType' => 'boolean',
            'legacy' => 'get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "vhi-paper"',
            'neutral' => 'get(input, "values.type.paper") == "vhi-paper"',
        ],
        'is_designer_paper' => [
            'id' => 12793,
            'kind' => 'constant',
            'dataType' => 'boolean',
            'legacy' => 'get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "shyne" || get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "plake" || get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "gmund" || get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "aquarello"',
            'neutral' => 'get(input, "values.type.paper") == "shyne" || get(input, "values.type.paper") == "plake" || get(input, "values.type.paper") == "gmund" || get(input, "values.type.paper") == "aquarello" || get(input, "values.type.paper") == "design-paper"',
        ],
        'has_rounded_corners' => [
            'id' => 12797,
            'kind' => 'constant',
            'dataType' => 'boolean',
            'legacy' => 'contains(get(offer, "properties.CALC_PROP_OPTIONS.VALUE_XML_ID"), "round-corners")',
            'neutral' => 'contains(get(input, "values.options"), "round-corners")',
        ],
        'finished_item_qty' => [
            'id' => 12925,
            'kind' => 'constant',
            'dataType' => 'number',
            'legacy' => 'toNumber(get(offer, "properties.CALC_PROP_VOLUME.VALUE_XML_ID"))',
            'neutral' => 'toNumber(get(input, "values.volume"))',
        ],
        'has_holes' => [
            'id' => 12976,
            'kind' => 'constant',
            'dataType' => 'boolean',
            'legacy' => 'contains(get(offer, "properties.CALC_PROP_OPTIONS.VALUE_XML_ID"), "round-holes")',
            'neutral' => 'contains(get(input, "values.options"), "round-holes")',
        ],
        'product_name' => [
            'id' => 12978,
            'kind' => 'constant',
            'dataType' => 'string',
            'legacy' => 'get(get(product, "properties.TR_CASE.VALUE"), 0)',
            'neutral' => '"Листовая продукция"',
        ],
        'value_format_text' => [
            'id' => 12979,
            'kind' => 'constant',
            'dataType' => 'string',
            'legacy' => 'get(offer, "properties.CALC_PROP_FORMAT.VALUE~")',
            'neutral' => 'toString(get(input, "values.format.width")) + "×" + toString(get(input, "values.format.length")) + " мм"',
        ],
        'is_text_filling_printing' => [
            'id' => 13085,
            'kind' => 'constant',
            'dataType' => 'boolean',
            'legacy' => 'get(offer, "properties.CALC_PROP_FILLING.VALUE_XML_ID") == "text"',
            'neutral' => 'get(input, "values.filling") == "text"',
        ],
        'is_standart_filling_printing' => [
            'id' => 13093,
            'kind' => 'constant',
            'dataType' => 'boolean',
            'legacy' => '(get(offer, "properties.CALC_PROP_FILLING.VALUE_XML_ID") == "standart") || (get(offer, "properties.CALC_PROP_FILLING.VALUE_XML_ID") != "text")',
            'neutral' => '(get(input, "values.filling") == "standart") || (get(input, "values.filling") != "text")',
        ],
    ];

    /** @var array<string,array{id:int,kind:string,dataType:string,legacy:string,neutral:string}> */
    private const TYPED_INITIALIZERS = [
        'is_self_adhesive_paper' => [
            'id' => 12794,
            'kind' => 'constant',
            'dataType' => 'boolean',
            'legacy' => '',
            'neutral' => 'get(input, "values.type.paper") == "sticker-paper"',
        ],
        'is_uv_printing' => [
            'id' => 12796,
            'kind' => 'constant',
            'dataType' => 'boolean',
            'legacy' => '',
            'neutral' => 'get(input, "values.method") == "UF_PECHAT"',
        ],
        'needs_pre_lamination_trim' => [
            'id' => 12838,
            'kind' => 'variable',
            'dataType' => 'boolean',
            'legacy' => '',
            'neutral' => 'false',
        ],
        'print_layout_length_mm' => [
            'id' => 12839,
            'kind' => 'constant',
            'dataType' => 'number',
            'legacy' => '',
            'neutral' => '0',
        ],
        'print_layout_width_mm' => [
            'id' => 12840,
            'kind' => 'constant',
            'dataType' => 'number',
            'legacy' => '',
            'neutral' => '0',
        ],
        'print_sheet_thickness_initial_mm' => [
            'id' => 12902,
            'kind' => 'constant',
            'dataType' => 'number',
            'legacy' => '',
            'neutral' => '0',
        ],
        'print_sheet_weight_initial_g' => [
            'id' => 12903,
            'kind' => 'constant',
            'dataType' => 'number',
            'legacy' => '',
            'neutral' => '0',
        ],
        'title_for_base_in_offer' => [
            'id' => 12977,
            'kind' => 'constant',
            'dataType' => 'string',
            'legacy' => '',
            'neutral' => '""',
        ],
        'print_vibrancy_text' => [
            'id' => 12980,
            'kind' => 'constant',
            'dataType' => 'string',
            'legacy' => '',
            'neutral' => '""',
        ],
        'print_method_text' => [
            'id' => 12981,
            'kind' => 'constant',
            'dataType' => 'string',
            'legacy' => '',
            'neutral' => '""',
        ],
    ];

    public function audit(): array
    {
        $config = $this->readConfigSnapshot(false);
        $active = $this->readActiveSnapshot(false);
        $state = $this->loadState($config, $active);
        $plan = $this->buildAuditedPlan(
            $state,
            $this->readOptionRaw(self::MARKER_OPTION, false),
            $this->readOptionRaw(self::BACKUP_OPTION, false)
        );
        unset($plan['_nextState']);
        return $plan;
    }

    /**
     * Return the exact in-memory post-migration registry for a signed,
     * read-only calc-server capability probe. The caller supplies the fresh
     * audit fingerprint; any authority or row drift is rejected here and is
     * checked again by apply() under the mutation lock.
     *
     * @return array<int,array<string,mixed>>
     */
    public function previewProspectiveRuntimeRows(string $expectedFingerprint): array
    {
        self::assertFingerprint($expectedFingerprint);
        $config = $this->readConfigSnapshot(false);
        $active = $this->readActiveSnapshot(false);
        $state = $this->loadState($config, $active);
        $plan = $this->buildAuditedPlan(
            $state,
            $this->readOptionRaw(self::MARKER_OPTION, false),
            $this->readOptionRaw(self::BACKUP_OPTION, false)
        );
        if (!hash_equals((string)($plan['fingerprint'] ?? ''), $expectedFingerprint)) {
            throw new \RuntimeException('Global symbols changed after audit. Repeat the audit.', 409);
        }
        if (($plan['status'] ?? '') !== 'pending'
            || ($plan['ready'] ?? false) !== true
            || count((array)($plan['mutations'] ?? [])) !== self::EXPECTED_MUTATION_COUNT
            || !is_array($plan['_nextState'] ?? null)) {
            throw new \RuntimeException('Prospective global-symbol runtime is not safe to probe.', 409);
        }

        $rows = self::runtimeRowsFromState($plan['_nextState']);
        if (count($rows) !== self::EXPECTED_PROSPECTIVE_RUNTIME_ROW_COUNT) {
            throw new \RuntimeException('Prospective global-symbol runtime row count is invalid.', 409);
        }
        self::assertNeutralRuntimeRows($rows);
        return $rows;
    }

    /**
     * Narrow predecessor proof for the additive ownership correction. It
     * validates immutable V2 evidence and pinned authorities without applying
     * today's corrected blank-declaration runtime rules.
     *
     * @return array<string,mixed>
     */
    public function assertHistoricalCompletionReady(): array
    {
        $config = $this->readConfigSnapshot(false);
        $active = $this->readActiveSnapshot(false);
        $state = $this->loadState($config, $active);
        $evidence = self::assertHistoricalEvidence(
            $this->readOptionRaw(self::MARKER_OPTION, false),
            $this->readOptionRaw(self::BACKUP_OPTION, false)
        );
        self::assertHistoricalAuthorityUnchanged($state, $evidence['targetState']);
        return self::buildHistoricalCompletionSummary($state, $evidence, (int)$config['iblockId']);
    }

    /** @return array<string,mixed> */
    public function assertHistoricalCompletionReadyLocked(
        bool $optionAuthoritiesAlreadyLocked = false
    ): array {
        if (!$optionAuthoritiesAlreadyLocked) {
            $this->lockOptionAuthorityRows();
        }
        $config = $this->readConfigSnapshot(true);
        $active = $this->readActiveSnapshot(true);
        $markerRaw = $this->readOptionRaw(self::MARKER_OPTION, true);
        $backupRaw = $this->readOptionRaw(self::BACKUP_OPTION, true);
        $this->lockRegistryRows((int)$config['iblockId']);
        $state = $this->loadState($config, $active, true);
        $evidence = self::assertHistoricalEvidence($markerRaw, $backupRaw);
        self::assertHistoricalAuthorityUnchanged($state, $evidence['targetState']);
        return self::buildHistoricalCompletionSummary($state, $evidence, (int)$config['iblockId']);
    }

    /**
     * Activation gate used by the V1 input migration. The V1 marker/backup
     * remain byte-compatible; neutral input may be enabled only after this
     * independent registry phase has exact read-back evidence.
     *
     * @return array<string,mixed>
     */
    public function assertActivationReady(): array
    {
        return (new Preset12740NeutralGlobalSymbolCorrectionMigrationService())
            ->assertActivationReady();
    }

    /**
     * Transactional activation gate used by the V1 input migration.
     *
     * The caller must already own a database transaction. Authorities are
     * locked before the registry storage so every neutral-contract writer
     * observes the same options -> iblock rows order. The state is loaded only
     * after all registry rows are locked, preventing ACTIVE=Y from being
     * committed against a concurrent global-symbol edit.
     *
     * @return array<string,mixed>
     */
    public function assertActivationReadyLocked(bool $optionAuthoritiesAlreadyLocked = false): array
    {
        return (new Preset12740NeutralGlobalSymbolCorrectionMigrationService())
            ->assertActivationReadyLocked($optionAuthoritiesAlreadyLocked);
    }

    /**
     * Lock and validate the V2 side before V1 rollback changes ACTIVE.
     *
     * A completed V2 migration may be rolled back only while its current
     * registry is still the exact uncustomized migration target. The legacy
     * pending registry is also accepted for recovery when V2 was never
     * applied. All mixed/corrupt states fail before any V1 write.
     *
     * @return array<string,mixed>
     */
    public function assertV1RollbackReadyLocked(bool $optionAuthoritiesAlreadyLocked = false): array
    {
        return (new Preset12740NeutralGlobalSymbolCorrectionMigrationService())
            ->assertV1RollbackReadyLocked($optionAuthoritiesAlreadyLocked);
    }

    /**
     * Runtime/save boundary after the one-time migration. Exact target
     * formulas may later be author-edited, but product/SKU roots must never
     * re-enter the independent calculation context.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public static function assertNeutralRuntimeRows(array $rows): void
    {
        $byId = [];
        $byCode = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('Neutral global-symbol registry contains an invalid row.', 409);
            }
            $id = (int)($row['id'] ?? 0);
            $code = (string)($row['code'] ?? '');
            $presetId = (int)($row['presetId'] ?? 0);
            $active = (string)($row['active'] ?? '');
            $kind = (string)($row['kind'] ?? '');
            $dataType = (string)($row['dataType'] ?? '');
            $initialValue = trim((string)($row['initialValue'] ?? ''));
            $normalizedCode = strtolower($code);
            $reservedCode = self::isReservedGlobalCode($code);
            if ($id <= 0
                || $code === ''
                || trim($code) !== $code
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $code) !== 1
                || $reservedCode
                || $presetId !== self::PRESET_ID
                || $active !== 'Y'
                || !in_array($kind, ['constant', 'variable'], true)
                || !in_array($dataType, ['auto', 'string', 'number', 'boolean', 'array', 'object'], true)
                || $initialValue === ''
                || isset($byId[$id])
                || isset($byCode[$normalizedCode])) {
                throw new \RuntimeException('Neutral global-symbol registry identity is invalid.', 409);
            }
            $byId[$id] = $row;
            $byCode[$normalizedCode] = $row;
            self::assertNeutralFormulaSource($initialValue);
        }
        foreach (self::SYMBOLS as $code => $specification) {
            $row = $byId[$specification['id']] ?? null;
            if (!is_array($row)
                || (string)($row['code'] ?? '') !== $code
                || (string)($row['kind'] ?? '') !== $specification['kind']
                || (string)($row['dataType'] ?? '') !== $specification['dataType']
                || trim((string)($row['initialValue'] ?? '')) === '') {
                throw new \RuntimeException('Neutral global-symbol contract is incomplete for ' . $code . '.', 409);
            }
        }
    }

    /**
     * One canonical formula-namespace denylist shared by authoring and the
     * runtime boundary. Codes are case-insensitive even though required
     * contract identities retain their exact case.
     */
    public static function isReservedGlobalCode(string $code): bool
    {
        return in_array(strtolower($code), self::RESERVED_GLOBAL_CODES, true)
            || preg_match('/^stage_\d+$/Di', $code) === 1;
    }

    public static function assertNeutralFormulaSource(string $formula): void
    {
        NeutralFormulaPolicy::assertFormula($formula, 'global formula');
    }

    /** @return array<string,array{id:int,kind:string,dataType:string}> */
    public static function requiredSymbolIdentities(): array
    {
        $result = [];
        foreach (self::SYMBOLS as $code => $specification) {
            $result[$code] = [
                'id' => (int)$specification['id'],
                'kind' => (string)$specification['kind'],
                'dataType' => (string)$specification['dataType'],
            ];
        }
        return $result;
    }

    /** @return array<string,array{id:int,kind:string,dataType:string,legacy:string,neutral:string}> */
    private static function migrationSpecifications(): array
    {
        return array_merge(self::SYMBOLS, self::TYPED_INITIALIZERS);
    }

    public function apply(string $expectedFingerprint, string $expectedActive): array
    {
        self::assertFingerprint($expectedFingerprint);
        return (new CatalogAdapterDefinitionService())->withMutationLock(
            self::PRESET_ID,
            fn(): array => $this->applyLocked($expectedFingerprint, $expectedActive)
        );
    }

    private function applyLocked(string $expectedFingerprint, string $expectedActive): array
    {
        $connection = Application::getConnection();
        $initialConfig = $this->readConfigSnapshot(false);
        $initialActive = $this->readActiveSnapshot(false);
        self::assertExpectedActiveSnapshot($initialActive, $expectedActive);
        $initialState = $this->loadState($initialConfig, $initialActive);
        $transactionStarted = false;
        try {
            $connection->startTransaction();
            $transactionStarted = true;
            $this->lockAuthorityRows($initialState);
            $lockedConfig = $this->readConfigSnapshot(true);
            $lockedActive = $this->readActiveSnapshot(true);
            self::assertSnapshotUnchanged($initialConfig, $lockedConfig, 'global-symbol iblock configuration');
            self::assertSnapshotUnchanged($initialActive, $lockedActive, 'neutral activation state');
            self::assertExpectedActiveSnapshot($lockedActive, $expectedActive);
            $currentState = $this->loadState($lockedConfig, $lockedActive, true);
            $plan = self::buildPlan($currentState);
            if (!hash_equals((string)$plan['fingerprint'], $expectedFingerprint)) {
                throw new \RuntimeException('Global symbols changed after audit. Repeat the audit.', 409);
            }
            $markerRaw = $this->readOptionRaw(self::MARKER_OPTION, true);
            $backupRaw = $this->readOptionRaw(self::BACKUP_OPTION, true);
            $retainedBackupRaw = '';
            if ($markerRaw !== '') {
                if ($backupRaw === '') {
                    throw new \RuntimeException('Global-symbol migration evidence is incomplete or corrupted.', 409);
                }
                $evidence = self::assertHistoricalEvidence($markerRaw, $backupRaw);
                self::assertCurrentNeutralState($currentState, $evidence['targetState']);
                $verified = self::buildVerifiedCompletionPlan($currentState, $evidence);
                $connection->commitTransaction();
                $transactionStarted = false;
                $verified['applied'] = false;
                return $verified;
            }
            if ($backupRaw !== '') {
                self::assertRetainedBackupMatchesState($currentState, $backupRaw);
                $retainedBackupRaw = $backupRaw;
            }
            if (($plan['ready'] ?? false) !== true
                || count((array)($plan['mutations'] ?? [])) !== self::EXPECTED_MUTATION_COUNT
                || !is_array($plan['_nextState'] ?? null)) {
                throw new \RuntimeException('Preset 12740 global-symbol migration is not safe to apply.', 409);
            }
            self::assertNeutralStateRows($plan['_nextState']);

            if ($retainedBackupRaw !== '') {
                $backupRaw = self::prepareBackupRaw($currentState, $retainedBackupRaw);
            } else {
                $backupRaw = self::prepareBackupRaw($currentState, '');
                $this->setGlobalOption(self::BACKUP_OPTION, $backupRaw);
            }
            $this->writeState($plan['_nextState'], (array)$plan['mutations']);

            $readBack = $this->loadState($lockedConfig, $lockedActive, true);
            self::assertNeutralStateRows($readBack);
            $verified = self::buildPlan($readBack);
            if (($verified['status'] ?? '') !== 'complete'
                || ($verified['ready'] ?? false) !== true
                || !hash_equals((string)$plan['nextFingerprint'], (string)$verified['fingerprint'])) {
                throw new \RuntimeException('Global-symbol migration read-back verification failed.');
            }
            $this->setGlobalOption(self::MARKER_OPTION, self::encodeCanonical([
                'contract' => self::CONTRACT,
                'presetId' => self::PRESET_ID,
                'beforeFingerprint' => (string)$plan['fingerprint'],
                'afterFingerprint' => (string)$verified['fingerprint'],
                'backupHash' => hash('sha256', $backupRaw),
                'appliedAt' => gmdate('c'),
            ]));
            $connection->commitTransaction();
            $transactionStarted = false;
            unset($verified['_nextState']);
            $verified['applied'] = true;
            return $verified;
        } catch (\Throwable $error) {
            if ($transactionStarted) {
                try {
                    $connection->rollbackTransaction();
                } catch (\Throwable $rollbackError) {
                }
            }
            throw $error;
        }
    }

    public function rollback(string $expectedFingerprint): array
    {
        self::assertFingerprint($expectedFingerprint);
        return (new CatalogAdapterDefinitionService())->withMutationLock(
            self::PRESET_ID,
            fn(): array => $this->rollbackLocked($expectedFingerprint)
        );
    }

    private function rollbackLocked(string $expectedFingerprint): array
    {
        $connection = Application::getConnection();
        $initialConfig = $this->readConfigSnapshot(false);
        $initialActive = $this->readActiveSnapshot(false);
        if (($initialActive['value'] ?? '') !== 'N') {
            throw new \RuntimeException('Disable and roll back the neutral input migration before restoring legacy globals.', 409);
        }
        $initialState = $this->loadState($initialConfig, $initialActive);
        $transactionStarted = false;
        try {
            $connection->startTransaction();
            $transactionStarted = true;
            $this->lockAuthorityRows($initialState);
            (new Preset12740NeutralGlobalSymbolCorrectionMigrationService())
                ->assertV2RollbackReadyLocked(true);
            $lockedConfig = $this->readConfigSnapshot(true);
            $lockedActive = $this->readActiveSnapshot(true);
            self::assertSnapshotUnchanged($initialConfig, $lockedConfig, 'global-symbol iblock configuration');
            self::assertSnapshotUnchanged($initialActive, $lockedActive, 'neutral activation state');
            if (($lockedActive['value'] ?? '') !== 'N') {
                throw new \RuntimeException('Neutral input is active; global-symbol rollback is refused.', 409);
            }
            $currentState = $this->loadState($lockedConfig, $lockedActive, true);
            $currentPlan = self::buildPlan($currentState);
            if (!hash_equals((string)$currentPlan['fingerprint'], $expectedFingerprint)) {
                throw new \RuntimeException('Global symbols changed after rollback audit.', 409);
            }
            $backupRaw = $this->readOptionRaw(self::BACKUP_OPTION, true);
            $markerRaw = $this->readOptionRaw(self::MARKER_OPTION, true);
            self::assertCompletionEvidence($currentPlan, $markerRaw, $backupRaw);
            $backup = json_decode($backupRaw, true);
            if (!is_array($backup) || !is_array($backup['state'] ?? null)) {
                throw new \RuntimeException('Global-symbol backup is invalid.');
            }
            $backupPlan = self::buildPlan($backup['state']);
            if (($backupPlan['status'] ?? '') !== 'pending'
                || ($backupPlan['ready'] ?? false) !== true
                || count((array)($backupPlan['mutations'] ?? [])) !== self::EXPECTED_MUTATION_COUNT) {
                throw new \RuntimeException('Global-symbol backup does not match the audited legacy state.');
            }
            $this->writeState($backup['state'], (array)$backupPlan['mutations']);
            $restored = $this->loadState($lockedConfig, $lockedActive, true);
            if (!hash_equals((string)($backup['fingerprint'] ?? ''), self::fingerprint($restored))) {
                throw new \RuntimeException('Global-symbol rollback read-back verification failed.');
            }
            $this->deleteGlobalOption(self::MARKER_OPTION);
            $connection->commitTransaction();
            $transactionStarted = false;
            $result = self::buildPlan($restored);
            unset($result['_nextState']);
            $result['rolledBack'] = true;
            return $result;
        } catch (\Throwable $error) {
            if ($transactionStarted) {
                try {
                    $connection->rollbackTransaction();
                } catch (\Throwable $rollbackError) {
                }
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $state */
    public static function buildPlan(array $state): array
    {
        self::validateState($state);
        $migrationSpecifications = self::migrationSpecifications();
        $fingerprint = self::fingerprint($state);
        $next = $state;
        $mutations = [];
        $unresolved = [];
        $neutralCount = 0;
        $rowsByCode = [];
        $rowsById = [];
        $seenIds = [];
        foreach ($state['rows'] as $index => $row) {
            $id = (int)($row['id'] ?? 0);
            $code = (string)($row['code'] ?? '');
            if ($id <= 0 || $code === '' || isset($seenIds[$id])) {
                $unresolved[] = ['kind' => 'identity', 'rowIndex' => $index, 'reason' => 'duplicate-or-invalid-row'];
                continue;
            }
            $seenIds[$id] = true;
            $rowsById[$id] = ['index' => $index, 'row' => $row];
            if ((int)($row['presetId'] ?? 0) !== self::PRESET_ID) {
                continue;
            }
            if (isset($rowsByCode[$code])) {
                $unresolved[] = ['kind' => 'identity', 'rowIndex' => $index, 'reason' => 'duplicate-owned-code'];
                continue;
            }
            $rowsByCode[$code] = ['index' => $index, 'row' => $row];
            if (!isset($migrationSpecifications[$code]) && self::containsForbiddenRoot((string)($row['initialValue'] ?? ''))) {
                $unresolved[] = ['kind' => 'unexpected-symbol', 'id' => $id, 'code' => $code, 'reason' => 'forbidden-entity-root'];
            }
        }

        foreach ($migrationSpecifications as $code => $specification) {
            $entry = $rowsById[$specification['id']] ?? null;
            if (!is_array($entry)) {
                $unresolved[] = ['kind' => 'required-symbol', 'code' => $code, 'reason' => 'missing'];
                continue;
            }
            $row = $entry['row'];
            if ((int)($row['id'] ?? 0) !== $specification['id']
                || (int)($row['iblockId'] ?? 0) !== (int)$state['iblockId']
                || (int)($row['presetId'] ?? 0) !== self::PRESET_ID
                || (string)($row['code'] ?? '') !== $code
                || (string)($row['active'] ?? '') !== 'Y'
                || (string)($row['kind'] ?? '') !== $specification['kind']
                || (string)($row['dataType'] ?? '') !== $specification['dataType']) {
                $unresolved[] = ['kind' => 'required-symbol', 'code' => $code, 'reason' => 'identity-or-type-mismatch'];
                continue;
            }
            $formula = (string)($row['initialValue'] ?? '');
            $initialValueExists = $row['initialValueExists'] ?? null;
            $legacyInitialValueExists = !isset(self::TYPED_INITIALIZERS[$code]);
            if (!is_bool($initialValueExists)) {
                $unresolved[] = [
                    'kind' => 'required-symbol',
                    'id' => $specification['id'],
                    'code' => $code,
                    'reason' => 'invalid-initial-value-presence',
                ];
                continue;
            }
            if ($formula === $specification['neutral']) {
                if ($initialValueExists === true) {
                    $neutralCount++;
                } else {
                    $unresolved[] = [
                        'kind' => 'required-symbol',
                        'id' => $specification['id'],
                        'code' => $code,
                        'reason' => 'neutral-value-is-not-physically-stored',
                    ];
                }
                continue;
            }
            if ($formula !== $specification['legacy']) {
                $unresolved[] = ['kind' => 'required-symbol', 'id' => $specification['id'], 'code' => $code, 'reason' => 'unexpected-formula', 'value' => $formula];
                continue;
            }
            if ($initialValueExists !== $legacyInitialValueExists) {
                $unresolved[] = [
                    'kind' => 'required-symbol',
                    'id' => $specification['id'],
                    'code' => $code,
                    'reason' => 'unexpected-initial-value-presence',
                    'expected' => $legacyInitialValueExists,
                    'actual' => $initialValueExists,
                ];
                continue;
            }
            $next['rows'][(int)$entry['index']]['initialValue'] = $specification['neutral'];
            $next['rows'][(int)$entry['index']]['initialValueExists'] = true;
            $mutations[] = [
                'kind' => 'global-symbol',
                'elementId' => $specification['id'],
                'code' => $code,
                'before' => $formula,
                'after' => $specification['neutral'],
                'beforeExists' => $initialValueExists,
                'afterExists' => true,
            ];
        }

        $knownCount = $neutralCount + count($mutations);
        if ($knownCount !== self::EXPECTED_MUTATION_COUNT) {
            $unresolved[] = [
                'kind' => 'migration-scope',
                'reason' => 'unexpected-known-symbol-count',
                'expected' => self::EXPECTED_MUTATION_COUNT,
                'actual' => $knownCount,
            ];
        }
        if ($neutralCount > 0 && $neutralCount < self::EXPECTED_MUTATION_COUNT) {
            $unresolved[] = [
                'kind' => 'migration-scope',
                'reason' => 'partial-migration-state',
                'neutral' => $neutralCount,
                'legacy' => count($mutations),
            ];
        }
        $prospectiveOwnedCount = count(self::runtimeRowsFromState($next));
        if ($prospectiveOwnedCount !== self::EXPECTED_PROSPECTIVE_RUNTIME_ROW_COUNT) {
            $unresolved[] = [
                'kind' => 'runtime-registry',
                'reason' => 'unexpected-prospective-runtime-row-count',
                'expected' => self::EXPECTED_PROSPECTIVE_RUNTIME_ROW_COUNT,
                'actual' => $prospectiveOwnedCount,
            ];
        }
        try {
            self::assertNeutralStateRows($next);
        } catch (\Throwable $error) {
            $unresolved[] = [
                'kind' => 'runtime-registry',
                'reason' => 'invalid-prospective-runtime-registry',
            ];
        }
        $ready = $unresolved === [];
        $status = $ready && $neutralCount === self::EXPECTED_MUTATION_COUNT
            ? 'complete'
            : ($ready ? 'pending' : 'blocked');
        return [
            'contract' => self::CONTRACT,
            'presetId' => self::PRESET_ID,
            'status' => $status,
            'ready' => $ready,
            'fingerprint' => $fingerprint,
            'nextFingerprint' => self::fingerprint($next),
            'mutations' => $mutations,
            'neutralSymbolCount' => $neutralCount,
            'unresolved' => $unresolved,
            'active' => (string)($state['active']['value'] ?? ''),
            '_nextState' => $next,
        ];
    }

    /** @return array<string,mixed> */
    private function loadState(array $config, array $active, bool $forUpdate = false): array
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('The iblock module is required.');
        }
        $iblockId = (int)($config['iblockId'] ?? 0);
        $iblock = \CIBlock::GetList(['ID' => 'ASC'], ['ID' => $iblockId])->Fetch();
        if (!is_array($iblock)
            || (int)($iblock['ID'] ?? 0) !== $iblockId
            || (string)($iblock['CODE'] ?? '') !== 'CALC_GLOBAL_VALUES'
            || (string)($iblock['IBLOCK_TYPE_ID'] ?? '') !== 'calculator'
            || (string)($iblock['ACTIVE'] ?? '') !== 'Y'
            || (int)($iblock['VERSION'] ?? 0) !== 2) {
            throw new \RuntimeException('The pinned global-symbol iblock identity is invalid.', 409);
        }
        $propertySchema = $this->loadPropertySchema($iblockId);
        $rows = $this->loadAllRows(
            $iblockId,
            (int)$propertySchema['INITIAL_VALUE']['id'],
            $forUpdate
        );
        usort($rows, static fn(array $left, array $right): int => (int)$left['id'] <=> (int)$right['id']);
        return [
            'presetId' => self::PRESET_ID,
            'iblockId' => $iblockId,
            'config' => $config,
            'active' => $active,
            'iblock' => [
                'id' => $iblockId,
                'code' => (string)$iblock['CODE'],
                'type' => (string)$iblock['IBLOCK_TYPE_ID'],
                'active' => (string)$iblock['ACTIVE'],
                'version' => (int)($iblock['VERSION'] ?? 0),
            ],
            'propertySchema' => $propertySchema,
            'rows' => array_values($rows),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function loadPropertySchema(int $iblockId): array
    {
        $required = [
            'KIND' => ['type' => 'S', 'userType' => ''],
            'DATA_TYPE' => ['type' => 'S', 'userType' => ''],
            'INITIAL_VALUE' => ['type' => 'S', 'userType' => 'HTML'],
            'PRESET_ID' => ['type' => 'N', 'userType' => ''],
        ];
        $schema = [];
        $iterator = \CIBlockProperty::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $iblockId]);
        while ($property = $iterator->Fetch()) {
            $code = (string)($property['CODE'] ?? '');
            if (!isset($required[$code])) {
                continue;
            }
            if (isset($schema[$code])) {
                throw new \RuntimeException('Duplicate global-symbol property schema.', 409);
            }
            $schema[$code] = [
                'id' => (int)($property['ID'] ?? 0),
                'code' => $code,
                'active' => (string)($property['ACTIVE'] ?? ''),
                'type' => (string)($property['PROPERTY_TYPE'] ?? ''),
                'userType' => (string)($property['USER_TYPE'] ?? ''),
                'multiple' => (string)($property['MULTIPLE'] ?? ''),
            ];
        }
        ksort($schema, SORT_STRING);
        foreach ($required as $code => $expectation) {
            $property = $schema[$code] ?? null;
            if (!is_array($property)
                || (int)($property['id'] ?? 0) <= 0
                || (string)($property['active'] ?? '') !== 'Y'
                || (string)($property['type'] ?? '') !== $expectation['type']
                || (string)($property['userType'] ?? '') !== $expectation['userType']
                || (string)($property['multiple'] ?? '') !== 'N') {
                throw new \RuntimeException('Global-symbol property schema is invalid for ' . $code . '.', 409);
            }
        }
        return $schema;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadAllRows(
        int $iblockId,
        int $initialValuePropertyId,
        bool $forUpdate
    ): array
    {
        $rows = [];
        $storageByElementId = $this->loadInitialValueStorageV2(
            $iblockId,
            $initialValuePropertyId,
            $forUpdate
        );
        $elementIds = [];
        $iterator = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'CODE', 'NAME', 'ACTIVE', 'SORT', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE']
        );
        while ($element = $iterator->GetNextElement()) {
            $fields = $element->GetFields();
            $properties = $element->GetProperties();
            $elementId = (int)($fields['ID'] ?? 0);
            if ($elementId <= 0 || isset($elementIds[$elementId])) {
                throw new \RuntimeException('Global-symbol element storage identity is invalid.', 409);
            }
            $elementIds[$elementId] = true;
            if (!array_key_exists($elementId, $storageByElementId)) {
                throw new \RuntimeException('Global-symbol INITIAL_VALUE storage row is missing.', 409);
            }
            $initialValueProperty = $properties['INITIAL_VALUE'] ?? null;
            if (!is_array($initialValueProperty)) {
                throw new \RuntimeException('Global-symbol INITIAL_VALUE property is unavailable.', 409);
            }
            $initialValueExists = $storageByElementId[$elementId];
            $initialValue = self::normalizeDecodedInitialValue($initialValueProperty, $initialValueExists);
            $rows[] = [
                'id' => $elementId,
                'iblockId' => (int)($fields['IBLOCK_ID'] ?? $iblockId),
                'code' => (string)($fields['CODE'] ?? ''),
                'title' => (string)($fields['~NAME'] ?? $fields['NAME'] ?? ''),
                'active' => (string)($fields['ACTIVE'] ?? ''),
                'sort' => (int)($fields['SORT'] ?? 0),
                'description' => (string)($fields['~PREVIEW_TEXT'] ?? $fields['PREVIEW_TEXT'] ?? ''),
                'descriptionType' => (string)($fields['PREVIEW_TEXT_TYPE'] ?? ''),
                'presetId' => (int)($properties['PRESET_ID']['VALUE'] ?? 0),
                'kind' => (string)($properties['KIND']['VALUE'] ?? ''),
                'dataType' => (string)($properties['DATA_TYPE']['VALUE'] ?? ''),
                'initialValue' => $initialValue,
                'initialValueExists' => $initialValueExists,
            ];
        }
        self::assertInitialValueStorageCoverage($storageByElementId, array_keys($elementIds));
        return $rows;
    }

    /** @return array{table:string,column:string} */
    private static function v2InitialValueStorageAuthority(int $iblockId, int $propertyId): array
    {
        if ($iblockId <= 0 || $propertyId <= 0) {
            throw new \RuntimeException('Global-symbol INITIAL_VALUE storage authority is invalid.', 409);
        }
        return [
            'table' => 'b_iblock_element_prop_s' . $iblockId,
            'column' => 'PROPERTY_' . $propertyId,
        ];
    }

    /** @return array<int,bool> */
    private function loadInitialValueStorageV2(
        int $iblockId,
        int $propertyId,
        bool $forUpdate
    ): array
    {
        $authority = self::v2InitialValueStorageAuthority($iblockId, $propertyId);
        $connection = Application::getConnection();
        if (!method_exists($connection, 'isTableExists')
            || !$connection->isTableExists($authority['table'])) {
            throw new \RuntimeException('Global-symbol INITIAL_VALUE storage table is unavailable.', 409);
        }
        $tableFields = $connection->getTableFields($authority['table']);
        self::assertV2InitialValueStorageSchema($tableFields, $authority);

        $cursor = $connection->query(
            'SELECT IBLOCK_ELEMENT_ID, ' . $authority['column'] . ' AS INITIAL_VALUE_RAW'
            . ' FROM ' . $authority['table']
            . ' ORDER BY IBLOCK_ELEMENT_ID'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $rows = [];
        while (($row = $cursor->fetch()) !== false) {
            if (!is_array($row)) {
                throw new \RuntimeException('Global-symbol INITIAL_VALUE storage row is invalid.', 409);
            }
            $rows[] = $row;
        }
        return self::normalizeInitialValueStorageRows($rows);
    }

    /** @param mixed $tableFields @param array{table:string,column:string} $authority */
    private static function assertV2InitialValueStorageSchema($tableFields, array $authority): void
    {
        if (!is_array($tableFields)
            || !array_key_exists('IBLOCK_ELEMENT_ID', $tableFields)
            || !array_key_exists($authority['column'], $tableFields)) {
            throw new \RuntimeException('Global-symbol INITIAL_VALUE storage column is unavailable.', 409);
        }
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,bool> */
    private static function normalizeInitialValueStorageRows(array $rows): array
    {
        $storage = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || !array_key_exists('IBLOCK_ELEMENT_ID', $row)
                || !array_key_exists('INITIAL_VALUE_RAW', $row)) {
                throw new \RuntimeException('Global-symbol INITIAL_VALUE storage row is invalid.', 409);
            }
            $rawElementId = $row['IBLOCK_ELEMENT_ID'];
            if (is_int($rawElementId)) {
                $elementId = $rawElementId;
            } elseif (is_string($rawElementId)
                && ctype_digit($rawElementId)
                && (string)(int)$rawElementId === $rawElementId) {
                $elementId = (int)$rawElementId;
            } else {
                $elementId = 0;
            }
            $rawValue = $row['INITIAL_VALUE_RAW'];
            if ($elementId <= 0
                || array_key_exists($elementId, $storage)
                || ($rawValue !== null && !is_scalar($rawValue))) {
                throw new \RuntimeException('Global-symbol INITIAL_VALUE storage row is invalid.', 409);
            }
            $storage[$elementId] = $rawValue !== null;
        }
        ksort($storage, SORT_NUMERIC);
        return $storage;
    }

    /** @param array<int,bool> $storageByElementId @param array<int,int> $elementIds */
    private static function assertInitialValueStorageCoverage(array $storageByElementId, array $elementIds): void
    {
        $storageIds = array_keys($storageByElementId);
        $elementIds = array_values(array_map('intval', $elementIds));
        sort($storageIds, SORT_NUMERIC);
        sort($elementIds, SORT_NUMERIC);
        if ($storageIds !== $elementIds) {
            throw new \RuntimeException('Global-symbol INITIAL_VALUE storage membership is invalid.', 409);
        }
    }

    /** @param array<string,mixed> $property */
    private static function normalizeDecodedInitialValue(array $property, bool $storageExists): string
    {
        $found = false;
        $decoded = null;
        foreach (['~VALUE', 'VALUE'] as $valueKey) {
            if (!array_key_exists($valueKey, $property)) {
                continue;
            }
            $decoded = $property[$valueKey];
            if (is_array($decoded)) {
                if (!array_key_exists('TEXT', $decoded)
                    || (array_key_exists('TYPE', $decoded) && !is_string($decoded['TYPE']))) {
                    throw new \RuntimeException('Global-symbol INITIAL_VALUE decoded shape is invalid.', 409);
                }
                $decoded = $decoded['TEXT'];
            }
            $found = true;
            break;
        }
        if (!$found) {
            throw new \RuntimeException('Global-symbol INITIAL_VALUE decoded value is unavailable.', 409);
        }
        if ($storageExists) {
            if (!is_string($decoded)) {
                throw new \RuntimeException('Global-symbol INITIAL_VALUE storage does not match its decoded value.', 409);
            }
            return $decoded;
        }
        if (!in_array($decoded, [null, false, ''], true)) {
            throw new \RuntimeException('Global-symbol INITIAL_VALUE storage does not match its decoded value.', 409);
        }
        return '';
    }

    /** @param array<string,mixed> $state @param array<int,array<string,mixed>> $mutations */
    private function writeState(array $state, array $mutations): void
    {
        $rowsById = [];
        foreach ((array)$state['rows'] as $row) {
            $rowsById[(int)($row['id'] ?? 0)] = $row;
        }
        foreach ($mutations as $mutation) {
            $id = (int)($mutation['elementId'] ?? 0);
            $row = $rowsById[$id] ?? null;
            if (!is_array($row) || $id <= 0) {
                throw new \RuntimeException('Global-symbol write target is unavailable.');
            }
            $initialValueExists = $row['initialValueExists'] ?? null;
            if (!is_bool($initialValueExists)) {
                throw new \RuntimeException('Global-symbol INITIAL_VALUE storage presence is invalid.');
            }
            \CIBlockElement::SetPropertyValues($id, (int)$state['iblockId'], [], 'INITIAL_VALUE');
            if ($initialValueExists === false) {
                continue;
            }
            \CIBlockElement::SetPropertyValuesEx($id, (int)$state['iblockId'], [
                'INITIAL_VALUE' => ['VALUE' => ['TEXT' => (string)$row['initialValue'], 'TYPE' => 'TEXT']],
            ]);
        }
    }

    /** @param array<string,mixed> $state */
    private function lockAuthorityRows(array $state): void
    {
        $this->lockOptionAuthorityRows();
        $this->lockRegistryRows((int)$state['iblockId']);
    }

    private function lockOptionAuthorityRows(): void
    {
        Application::getConnection()->queryExecute(
            "SELECT MODULE_ID, NAME, SITE_ID FROM b_option WHERE "
            . "(((MODULE_ID='prospektweb.calc' OR MODULE_ID='prospektweb.frontcalc') AND UPPER(TRIM(NAME))='IBLOCK_CALC_GLOBAL_VALUES') OR "
            . "(MODULE_ID='prospektweb.calc' AND UPPER(TRIM(NAME)) IN ('PRESET_12740_NEUTRAL_INPUT_ACTIVE','PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_BACKUP_V1','PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_MIGRATION_V1','PRESET_12740_GLOBAL_OWNERSHIP_BACKUP_V1','PRESET_12740_GLOBAL_OWNERSHIP_MIGRATION_V1'))) "
            . "ORDER BY MODULE_ID, NAME, SITE_ID FOR UPDATE"
        );
    }

    private function lockRegistryRows(int $iblockId): void
    {
        if ($iblockId <= 0) {
            throw new \RuntimeException('Global-symbol iblock authority is invalid.', 409);
        }
        $connection = Application::getConnection();
        $connection->queryExecute('SELECT ID FROM b_iblock WHERE ID=' . $iblockId . ' FOR UPDATE');
        $propertyIds = [];
        $cursor = $connection->query('SELECT ID FROM b_iblock_property WHERE IBLOCK_ID=' . $iblockId . ' ORDER BY ID FOR UPDATE');
        while ($row = $cursor->fetch()) {
            $propertyIds[] = (int)$row['ID'];
        }
        if ($propertyIds !== []) {
            $connection->queryExecute(
                'SELECT ID FROM b_iblock_property_enum WHERE PROPERTY_ID IN (' . implode(',', $propertyIds) . ') ORDER BY ID FOR UPDATE'
            );
        }
        $elementIds = [];
        $elements = $connection->query('SELECT ID FROM b_iblock_element WHERE IBLOCK_ID=' . $iblockId . ' ORDER BY ID FOR UPDATE');
        while ($row = $elements->fetch()) {
            $elementIds[] = (int)$row['ID'];
        }
        if ($elementIds === []) {
            throw new \RuntimeException('Global-symbol iblock is empty.', 409);
        }
        $idList = implode(',', $elementIds);
        $connection->queryExecute(
            'SELECT ID FROM b_iblock_element_property WHERE IBLOCK_ELEMENT_ID IN (' . $idList . ') ORDER BY ID FOR UPDATE'
        );
        foreach (['b_iblock_element_prop_s' . $iblockId, 'b_iblock_element_prop_m' . $iblockId] as $tableName) {
            if (method_exists($connection, 'isTableExists') && !$connection->isTableExists($tableName)) {
                continue;
            }
            $connection->queryExecute(
                'SELECT IBLOCK_ELEMENT_ID FROM ' . $tableName
                . ' WHERE IBLOCK_ELEMENT_ID IN (' . $idList . ') ORDER BY IBLOCK_ELEMENT_ID FOR UPDATE'
            );
        }
    }

    /** @return array<string,mixed> */
    private function readConfigSnapshot(bool $forUpdate): array
    {
        $calc = $this->readOptionSnapshot(self::MODULE_ID, self::CONFIG_OPTION, $forUpdate, false);
        $frontcalc = $this->readOptionSnapshot(
            'prospektweb.frontcalc',
            self::CONFIG_OPTION,
            $forUpdate,
            false
        );
        return self::normalizeConfigAuthorities($calc, $frontcalc);
    }

    /**
     * @param array<string,mixed> $calc
     * @param array<string,mixed> $frontcalc
     * @return array<string,mixed>
     */
    private static function normalizeConfigAuthorities(array $calc, array $frontcalc): array
    {
        $resolvedIds = [];
        foreach ([$calc, $frontcalc] as $authority) {
            if (($authority['exists'] ?? false) !== true) {
                continue;
            }
            $raw = (string)($authority['value'] ?? '');
            if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) {
                throw new \RuntimeException('Global-symbol iblock option is invalid.', 409);
            }
            $resolvedIds[(int)$raw] = true;
        }
        if (count($resolvedIds) !== 1) {
            throw new \RuntimeException('Global-symbol iblock option authorities disagree.', 409);
        }
        return [
            'iblockId' => (int)array_key_first($resolvedIds),
            'calc' => $calc,
            'frontcalc' => $frontcalc,
        ];
    }

    /** @return array<string,mixed> */
    private function readActiveSnapshot(bool $forUpdate): array
    {
        $snapshot = $this->readOptionSnapshot(self::MODULE_ID, self::ACTIVE_OPTION, $forUpdate, false);
        return self::normalizeActiveSnapshot($snapshot);
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private static function normalizeActiveSnapshot(array $snapshot): array
    {
        if (($snapshot['exists'] ?? false) !== true) {
            // A clean installation has no activation row yet. It is an
            // explicit authoring-state N for this migration; V1 owns the
            // later, transactional creation of Y after both phases verify.
            $snapshot['value'] = 'N';
            $snapshot['implicit'] = true;
            return $snapshot;
        }
        if (!in_array((string)$snapshot['value'], ['N', 'Y'], true)) {
            throw new \RuntimeException('Neutral activation option is invalid.', 409);
        }
        $snapshot['implicit'] = false;
        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot */
    private static function assertExpectedActiveSnapshot(array $snapshot, string $expectedActive): void
    {
        if (!in_array($expectedActive, ['N', 'Y'], true)
            || (string)($snapshot['value'] ?? '') !== $expectedActive) {
            throw new \RuntimeException('Neutral activation state changed after audit.', 409);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private static function normalizeOptionSnapshotRows(
        string $moduleId,
        string $name,
        array $rows,
        bool $required
    ): array {
        self::assertAllowedOptionRequest($moduleId, $name);
        $snapshot = null;
        foreach ($rows as $row) {
            $actualModule = (string)($row['MODULE_ID'] ?? $row['module_id'] ?? '');
            $actualName = (string)($row['NAME'] ?? $row['name'] ?? '');
            $hasSiteId = array_key_exists('SITE_ID', $row) || array_key_exists('site_id', $row);
            $siteId = array_key_exists('SITE_ID', $row)
                ? $row['SITE_ID']
                : ($row['site_id'] ?? null);
            if ($actualModule !== $moduleId
                || $actualName !== trim($actualName)
                || strtoupper($actualName) !== $name
                || !$hasSiteId
                || !in_array($siteId, [null, ''], true)) {
                throw new \RuntimeException('Unexpected global option authority.', 409);
            }
            if (is_array($snapshot)) {
                throw new \RuntimeException('Duplicate global option authority.', 409);
            }
            $value = (string)($row['VALUE'] ?? $row['value'] ?? '');
            if ((in_array($name, self::IMMUTABLE_EVIDENCE_OPTION_NAMES, true) && trim($value) === '')
                || ($name === self::ACTIVE_OPTION && !in_array($value, ['N', 'Y'], true))) {
                throw new \RuntimeException('Invalid global option authority value.', 409);
            }
            $snapshot = [
                'exists' => true,
                'moduleId' => $actualModule,
                'name' => $actualName,
                'siteId' => $siteId,
                'value' => $value,
            ];
        }
        if (is_array($snapshot)) {
            return $snapshot;
        }
        if ($required) {
            throw new \RuntimeException('Required global option authority is missing.', 409);
        }
        return [
            'exists' => false,
            'moduleId' => $moduleId,
            'name' => $name,
            'siteId' => null,
            'value' => '',
        ];
    }

    private static function assertAllowedOptionRequest(string $moduleId, string $name): void
    {
        if (!array_key_exists($moduleId, self::OPTION_NAMES_BY_MODULE)
            || !in_array($name, self::OPTION_NAMES_BY_MODULE[$moduleId], true)) {
            throw new \InvalidArgumentException('Unsupported neutral global-symbol option authority request.');
        }
    }

    /** @return array<string,mixed> */
    private function readOptionSnapshot(string $moduleId, string $name, bool $forUpdate, bool $required): array
    {
        self::assertAllowedOptionRequest($moduleId, $name);
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $sql = "SELECT MODULE_ID, NAME, VALUE, SITE_ID FROM b_option WHERE MODULE_ID='"
            . $helper->forSql($moduleId) . "' AND UPPER(TRIM(NAME))='" . $helper->forSql($name) . "'"
            . " ORDER BY MODULE_ID, NAME, SITE_ID"
            . ($forUpdate ? ' FOR UPDATE' : '');
        $cursor = $connection->query($sql);
        $rows = [];
        while (($row = $cursor->fetch()) !== false) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return self::normalizeOptionSnapshotRows($moduleId, $name, $rows, $required);
    }

    private function readOptionRaw(string $name, bool $forUpdate): string
    {
        return (string)$this->readOptionSnapshot(self::MODULE_ID, $name, $forUpdate, false)['value'];
    }

    private function setGlobalOption(string $name, string $value): void
    {
        $before = $this->readOptionSnapshot(self::MODULE_ID, $name, true, false);
        if (($before['exists'] ?? false) === true
            && in_array($name, self::IMMUTABLE_EVIDENCE_OPTION_NAMES, true)
            && !hash_equals((string)$before['value'], $value)) {
            throw new \RuntimeException('A different global-symbol migration evidence option already exists.', 409);
        }
        Option::set(self::MODULE_ID, $name, $value);
        $after = $this->readOptionSnapshot(self::MODULE_ID, $name, true, true);
        if (!hash_equals($value, (string)$after['value'])
            || (($before['exists'] ?? false) === true
                && (!hash_equals((string)$before['name'], (string)$after['name'])
                    || $before['siteId'] !== $after['siteId']))) {
            throw new \RuntimeException('Global-symbol migration option read-back failed.');
        }
    }

    private function deleteGlobalOption(string $name): void
    {
        $snapshot = $this->readOptionSnapshot(self::MODULE_ID, $name, true, false);
        if (($snapshot['exists'] ?? false) !== true) {
            return;
        }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $sitePredicate = $snapshot['siteId'] === null ? 'SITE_ID IS NULL' : "SITE_ID=''";
        $connection->queryExecute(
            "DELETE FROM b_option WHERE BINARY MODULE_ID='" . $helper->forSql((string)$snapshot['moduleId'])
            . "' AND BINARY NAME='" . $helper->forSql((string)$snapshot['name']) . "' AND " . $sitePredicate
        );
        $readBack = $this->readOptionSnapshot(self::MODULE_ID, $name, false, false);
        if (($readBack['exists'] ?? false) === true) {
            throw new \RuntimeException('Global-symbol migration option delete read-back failed.');
        }
    }

    /**
     * Audit has two deliberately different meanings:
     * - before the one-time migration, only the exact reviewed legacy state is
     *   eligible for the deterministic twenty-four-row transition;
     * - after it, immutable marker/backup evidence proves that rewrite while
     *   the current formulas may be safely author-edited.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function buildAuditedPlan(array $state, string $markerRaw, string $backupRaw): array
    {
        $plan = self::buildPlan($state);
        if ($markerRaw === '' && $backupRaw === '') {
            if (($plan['status'] ?? '') === 'complete') {
                $plan['status'] = 'blocked';
                $plan['ready'] = false;
                $plan['unresolved'][] = [
                    'kind' => 'migration-evidence',
                    'reason' => 'missing-marker-and-backup',
                ];
            }
            return $plan;
        }
        if ($markerRaw === '' && $backupRaw !== '') {
            self::assertRetainedBackupMatchesState($state, $backupRaw);
            $plan['backupRetained'] = true;
            return $plan;
        }
        if ($markerRaw === '' || $backupRaw === '') {
            throw new \RuntimeException('Global-symbol migration evidence is incomplete or corrupted.', 409);
        }
        $evidence = self::assertHistoricalEvidence($markerRaw, $backupRaw);
        self::assertCurrentNeutralState($state, $evidence['targetState']);
        return self::buildVerifiedCompletionPlan($state, $evidence);
    }

    /**
     * Proves the immutable one-time transition without requiring today's
     * author-edited formulas to remain byte-identical to the original target.
     *
     * @return array{marker:array<string,mixed>,backup:array<string,mixed>,targetState:array<string,mixed>}
     */
    private static function assertHistoricalEvidence(string $markerRaw, string $backupRaw): array
    {
        $marker = json_decode($markerRaw, true);
        $backup = json_decode($backupRaw, true);
        $backupPlan = is_array($backup) && is_array($backup['state'] ?? null)
            ? self::buildPlan($backup['state'])
            : null;
        $targetState = is_array($backupPlan)
            ? self::resolveHistoricalEvidenceTarget(
                $backupPlan,
                (string)($marker['afterFingerprint'] ?? '')
            )
            : null;
        if (!is_array($marker)
            || !is_array($backup)
            || !is_array($backupPlan)
            || (string)($marker['contract'] ?? '') !== self::CONTRACT
            || (int)($marker['presetId'] ?? 0) !== self::PRESET_ID
            || (string)($marker['beforeFingerprint'] ?? '') !== (string)($backup['fingerprint'] ?? '')
            || (string)($marker['backupHash'] ?? '') !== hash('sha256', $backupRaw)
            || (string)($backup['contract'] ?? '') !== self::CONTRACT
            || (int)($backup['presetId'] ?? 0) !== self::PRESET_ID
            || !is_array($backup['state'] ?? null)
            || (string)($backup['fingerprint'] ?? '') !== self::fingerprint($backup['state'])
            || ($backupPlan['status'] ?? '') !== 'pending'
            || ($backupPlan['ready'] ?? false) !== true
            || count((array)($backupPlan['mutations'] ?? [])) !== self::EXPECTED_MUTATION_COUNT
            || !is_array($backupPlan['_nextState'] ?? null)
            || (string)($backupPlan['fingerprint'] ?? '') !== (string)($marker['beforeFingerprint'] ?? '')
            || !is_array($targetState)) {
            throw new \RuntimeException('Global-symbol migration evidence is incomplete or corrupted.', 409);
        }
        return [
            'marker' => $marker,
            'backup' => $backup,
            'targetState' => $targetState,
        ];
    }

    /**
     * Resolve only known deterministic targets for the immutable v2 marker.
     * This keeps historical evidence valid when a later safe authoring change
     * updates a formula without weakening any authority, backup or fingerprint
     * check.
     *
     * @param array<string,mixed> $backupPlan
     * @return array<string,mixed>|null
     */
    private static function resolveHistoricalEvidenceTarget(
        array $backupPlan,
        string $expectedAfterFingerprint
    ): ?array {
        $currentTarget = $backupPlan['_nextState'] ?? null;
        if (!is_array($currentTarget)) {
            return null;
        }
        if (hash_equals($expectedAfterFingerprint, self::fingerprint($currentTarget))) {
            return $currentTarget;
        }

        $historicalTarget = $currentTarget;
        $replaced = false;
        foreach ((array)($historicalTarget['rows'] ?? []) as $index => $row) {
            if (!is_array($row)
                || (int)($row['id'] ?? 0) !== 12979
                || (string)($row['code'] ?? '') !== 'value_format_text') {
                continue;
            }
            if ((string)($row['initialValue'] ?? '') !== self::SYMBOLS['value_format_text']['neutral']) {
                return null;
            }
            $historicalTarget['rows'][$index]['initialValue'] = self::HISTORICAL_V2_VALUE_FORMAT_TEXT;
            $replaced = true;
            break;
        }

        return $replaced
            && hash_equals($expectedAfterFingerprint, self::fingerprint($historicalTarget))
            ? $historicalTarget
            : null;
    }

    /**
     * Rollback intentionally retains the immutable legacy backup, matching
     * the V1 lifecycle. A later re-apply may reuse it only when the restored
     * state still has the exact same fingerprint and twenty-four-row plan.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private static function assertRetainedBackupMatchesState(array $state, string $backupRaw): array
    {
        $backup = json_decode($backupRaw, true);
        $backupPlan = is_array($backup) && is_array($backup['state'] ?? null)
            ? self::buildPlan($backup['state'])
            : null;
        if (!is_array($backup)
            || !is_array($backupPlan)
            || (string)($backup['contract'] ?? '') !== self::CONTRACT
            || (int)($backup['presetId'] ?? 0) !== self::PRESET_ID
            || (string)($backup['fingerprint'] ?? '') !== self::fingerprint($backup['state'])
            || !hash_equals((string)$backup['fingerprint'], self::fingerprint($state))
            || ($backupPlan['status'] ?? '') !== 'pending'
            || ($backupPlan['ready'] ?? false) !== true
            || count((array)($backupPlan['mutations'] ?? [])) !== self::EXPECTED_MUTATION_COUNT) {
            throw new \RuntimeException('Retained global-symbol backup does not match the restored state.', 409);
        }
        return $backup;
    }

    /** @param array<string,mixed> $state */
    private static function prepareBackupRaw(array $state, string $retainedBackupRaw): string
    {
        if ($retainedBackupRaw !== '') {
            self::assertRetainedBackupMatchesState($state, $retainedBackupRaw);
            return $retainedBackupRaw;
        }
        return self::encodeCanonical([
            'contract' => self::CONTRACT,
            'presetId' => self::PRESET_ID,
            'fingerprint' => self::fingerprint($state),
            'state' => $state,
        ]);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $historicalTarget */
    private static function assertHistoricalAuthorityUnchanged(array $state, array $historicalTarget): void
    {
        self::validateState($state);
        self::validateState($historicalTarget);
        foreach (['presetId', 'iblockId', 'config', 'iblock', 'propertySchema'] as $key) {
            $currentHash = hash('sha256', self::encodeCanonical($state[$key] ?? null));
            $targetHash = hash('sha256', self::encodeCanonical($historicalTarget[$key] ?? null));
            if (!hash_equals($targetHash, $currentHash)) {
                throw new \RuntimeException('Global-symbol historical authority changed after V2 migration.', 409);
            }
        }
    }

    /**
     * @param array<string,mixed> $state
     * @param array{marker:array<string,mixed>,backup:array<string,mixed>,targetState:array<string,mixed>} $evidence
     * @return array<string,mixed>
     */
    private static function buildHistoricalCompletionSummary(
        array $state,
        array $evidence,
        int $globalIblockId
    ): array {
        return [
            'contract' => self::CONTRACT,
            'presetId' => self::PRESET_ID,
            'status' => 'complete',
            'ready' => true,
            'fingerprint' => self::fingerprint($state),
            'migrationTargetFingerprint' => (string)($evidence['marker']['afterFingerprint'] ?? ''),
            'active' => (string)($state['active']['value'] ?? ''),
            'globalIblockId' => $globalIblockId,
            'evidenceVerified' => true,
        ];
    }

    /**
     * Current-state proof intentionally permits safe formula edits while
     * pinning all authorities and the required fourteen identities/types.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $historicalTarget
     */
    private static function assertCurrentNeutralState(array $state, array $historicalTarget): void
    {
        self::validateState($state);
        self::validateState($historicalTarget);
        foreach (['presetId', 'iblockId', 'config', 'iblock', 'propertySchema'] as $key) {
            $currentHash = hash('sha256', self::encodeCanonical($state[$key] ?? null));
            $targetHash = hash('sha256', self::encodeCanonical($historicalTarget[$key] ?? null));
            if (!hash_equals($targetHash, $currentHash)) {
                throw new \RuntimeException('Global-symbol runtime authority changed after migration.', 409);
            }
        }
        self::assertNeutralStateRows($state);
    }

    /** @param array<string,mixed> $state */
    private static function assertNeutralStateRows(array $state): void
    {
        self::validateState($state);
        foreach ((array)$state['rows'] as $row) {
            if (is_array($row)
                && (int)($row['presetId'] ?? 0) === self::PRESET_ID
                && ($row['initialValueExists'] ?? null) !== true) {
                throw new \RuntimeException('Neutral global-symbol INITIAL_VALUE storage is absent.', 409);
            }
        }
        self::assertNeutralRuntimeRows(self::runtimeRowsFromState($state));
    }

    /** @param array<string,mixed> $state @return array<int,array<string,mixed>> */
    private static function runtimeRowsFromState(array $state): array
    {
        self::validateState($state);
        $rows = array_values(array_filter(
            (array)$state['rows'],
            static fn($row): bool => is_array($row) && (int)($row['presetId'] ?? 0) === self::PRESET_ID
        ));
        usort($rows, static fn(array $left, array $right): int => (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0));
        return array_map(
            static fn(array $row): array => [
                'id' => (int)($row['id'] ?? 0),
                'code' => (string)($row['code'] ?? ''),
                'kind' => (string)($row['kind'] ?? ''),
                'dataType' => (string)($row['dataType'] ?? ''),
                'presetId' => (int)($row['presetId'] ?? 0),
                'active' => (string)($row['active'] ?? ''),
                'initialValue' => (string)($row['initialValue'] ?? ''),
            ],
            $rows
        );
    }

    /**
     * @param array<string,mixed> $state
     * @param array{marker:array<string,mixed>,backup:array<string,mixed>,targetState:array<string,mixed>} $evidence
     * @return array<string,mixed>
     */
    private static function buildVerifiedCompletionPlan(array $state, array $evidence): array
    {
        $fingerprint = self::fingerprint($state);
        $afterFingerprint = (string)($evidence['marker']['afterFingerprint'] ?? '');
        return [
            'contract' => self::CONTRACT,
            'presetId' => self::PRESET_ID,
            'status' => 'complete',
            'ready' => true,
            'fingerprint' => $fingerprint,
            'nextFingerprint' => $fingerprint,
            'mutations' => [],
            'neutralSymbolCount' => self::EXPECTED_MUTATION_COUNT,
            'unresolved' => [],
            'active' => (string)($state['active']['value'] ?? ''),
            'evidenceVerified' => true,
            'customized' => !hash_equals($afterFingerprint, $fingerprint),
            'migrationTargetFingerprint' => $afterFingerprint,
        ];
    }

    /** @param array<string,mixed> $state */
    private static function validateState(array $state): void
    {
        if ((int)($state['presetId'] ?? 0) !== self::PRESET_ID
            || (int)($state['iblockId'] ?? 0) <= 0
            || !is_array($state['config'] ?? null)
            || !is_array($state['active'] ?? null)
            || !is_array($state['rows'] ?? null)) {
            throw new \InvalidArgumentException('Invalid preset-12740 global-symbol migration state.');
        }
    }

    private static function containsForbiddenRoot(string $formula): bool
    {
        return NeutralFormulaPolicy::findForbiddenRoot($formula) !== null;
    }

    /** @param array<string,mixed> $state */
    private static function fingerprint(array $state): string
    {
        // ACTIVE is a separate activation authority and intentionally changes
        // before rollback. It is protected by its own snapshot CAS, but must
        // not make the migration backup/marker impossible to verify.
        unset($state['active']);
        return hash('sha256', self::encodeCanonical($state));
    }

    /** @param mixed $value */
    private static function encodeCanonical($value): string
    {
        $normalize = static function ($candidate) use (&$normalize) {
            if (!is_array($candidate)) {
                return $candidate;
            }
            if (array_is_list($candidate)) {
                return array_map($normalize, $candidate);
            }
            ksort($candidate, SORT_STRING);
            foreach ($candidate as $key => $nested) {
                $candidate[$key] = $normalize($nested);
            }
            return $candidate;
        };
        $encoded = json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to encode global-symbol migration state.');
        }
        return $encoded;
    }

    private static function assertFingerprint(string $fingerprint): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('Invalid global-symbol migration fingerprint.');
        }
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private static function assertSnapshotUnchanged(array $before, array $after, string $label): void
    {
        if (!hash_equals(hash('sha256', self::encodeCanonical($before)), hash('sha256', self::encodeCanonical($after)))) {
            throw new \RuntimeException('The ' . $label . ' changed after audit.', 409);
        }
    }

    /** @param array<string,mixed> $plan */
    private static function assertCompletionEvidence(array $plan, string $markerRaw, string $backupRaw): void
    {
        $evidence = self::assertHistoricalEvidence($markerRaw, $backupRaw);
        $marker = $evidence['marker'];
        if (($plan['status'] ?? '') !== 'complete'
            || ($plan['ready'] ?? false) !== true
            || (string)($marker['afterFingerprint'] ?? '') !== (string)($plan['fingerprint'] ?? '')) {
            throw new \RuntimeException('Global-symbol migration evidence is incomplete or corrupted.', 409);
        }
    }
}
