<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Install;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * One-time, fail-closed cutover of preset 12740 author-facing sources.
 *
 * The service deliberately does not implement a generic preset upgrader. It
 * rewrites the eight audited product/offer source references of the only
 * supported preset to semantic form paths, verifies the read-back and only
 * then activates neutral FrontCalc requests.
 */
final class Preset12740NeutralInputMigrationService
{
    public const CONTRACT = 'prospektweb.calc.preset-12740-neutral-input-migration/v1';
    public const PRESET_ID = 12740;
    public const EXPECTED_MUTATION_COUNT = 8;
    public const ACTIVE_OPTION = 'PRESET_12740_NEUTRAL_INPUT_ACTIVE';

    private const MODULE_ID = 'prospektweb.calc';
    private const BACKUP_OPTION = 'PRESET_12740_NEUTRAL_INPUT_BACKUP_V1';
    private const MARKER_OPTION = 'PRESET_12740_NEUTRAL_INPUT_MIGRATION_V1';
    private const CONFIG_OPTION_TO_STATE_KEY = [
        'IBLOCK_CALC_DETAILS' => 'detailsIblockId',
        'IBLOCK_CALC_PRESETS' => 'presetIblockId',
        'IBLOCK_CALC_SETTINGS' => 'settingsIblockId',
        'IBLOCK_CALC_STAGES' => 'stagesIblockId',
    ];

    /** @var array<string,list<string>> */
    private const OPTION_NAMES_BY_MODULE = [
        'prospektweb.calc' => [
            'IBLOCK_CALC_DETAILS',
            'IBLOCK_CALC_PRESETS',
            'IBLOCK_CALC_SETTINGS',
            'IBLOCK_CALC_STAGES',
            self::ACTIVE_OPTION,
            self::BACKUP_OPTION,
            self::MARKER_OPTION,
        ],
        'prospektweb.frontcalc' => [
            'FORM_FIRST_PRESET_12740',
        ],
    ];

    /** @var list<string> */
    private const IMMUTABLE_EVIDENCE_OPTION_NAMES = [
        self::BACKUP_OPTION,
        self::MARKER_OPTION,
    ];

    public function audit(): array
    {
        $configSnapshot = $this->readConfigSnapshot(false);
        $publishedRaw = $this->readOptionRaw(
            'prospektweb.frontcalc',
            'FORM_FIRST_PRESET_12740',
            false
        );
        $plan = self::buildPlan($this->loadBitrixState($publishedRaw, $configSnapshot));
        unset($plan['_nextState']);
        return $plan;
    }

    /**
     * Read-only evidence gate for operational runners and diagnostics.
     *
     * A merely neutral-looking state is not enough: the exact legacy backup
     * and the matching migration marker must reproduce the current eight
     * rewritten references. No option, iblock or publication row is changed.
     *
     * @return array<string,mixed>
     */
    public function assertCompletionReady(): array
    {
        $configSnapshot = $this->readConfigSnapshot(false);
        $publishedRaw = $this->readOptionRaw(
            'prospektweb.frontcalc',
            'FORM_FIRST_PRESET_12740',
            false
        );
        $plan = self::buildPlan($this->loadBitrixState($publishedRaw, $configSnapshot));
        self::assertCompletionEvidence(
            $plan,
            $this->readOptionRaw(self::MODULE_ID, self::MARKER_OPTION, false),
            $this->readOptionRaw(self::MODULE_ID, self::BACKUP_OPTION, false)
        );
        unset($plan['_nextState']);
        return $plan;
    }

    /**
     * Read-only recovery gate for the operational two-phase rollback runner.
     *
     * V1 deliberately retains its immutable backup after restoring the legacy
     * state. If the process stops before V2 rollback, this proves that V1 is
     * already restored exactly and that resuming with V2-only rollback is safe.
     *
     * @return array<string,mixed>
     */
    public function assertRollbackResumeReady(): array
    {
        $configSnapshot = $this->readConfigSnapshot(false);
        $publishedRaw = $this->readOptionRaw(
            'prospektweb.frontcalc',
            'FORM_FIRST_PRESET_12740',
            false
        );
        $currentState = $this->loadBitrixState($publishedRaw, $configSnapshot);
        return self::assertRollbackResumeEvidence(
            $currentState,
            $this->readOptionState(self::MODULE_ID, self::ACTIVE_OPTION, false),
            $this->readOptionState(self::MODULE_ID, self::MARKER_OPTION, false),
            $this->readOptionState(self::MODULE_ID, self::BACKUP_OPTION, false)
        );
    }

    public function apply(string $expectedFingerprint): array
    {
        self::assertFingerprint($expectedFingerprint);
        $connection = Application::getConnection();
        // Discover the rows to lock before opening the transaction. Under
        // InnoDB REPEATABLE READ an ordinary Bitrix read inside the transaction
        // would establish a stale consistent snapshot before FOR UPDATE has a
        // chance to wait for a concurrent publication/mutation.
        $initialConfigSnapshot = $this->readConfigSnapshot(false);
        $initialPublishedRaw = $this->readOptionRaw(
            'prospektweb.frontcalc',
            'FORM_FIRST_PRESET_12740',
            false
        );
        $initialState = $this->loadBitrixState($initialPublishedRaw, $initialConfigSnapshot);
        $transactionStarted = false;

        try {
            $connection->startTransaction();
            $transactionStarted = true;
            $this->lockNeutralOptionAuthorities();
            // Lock and re-read V2 authorities/registry before any V1 source
            // row. This keeps the shared options -> iblock order and makes the
            // later ACTIVE=Y write depend on an exact in-transaction V2 gate.
            $globalActivationPlan = (new Preset12740NeutralGlobalSymbolMigrationService())
                ->assertActivationReadyLocked(true);
            $this->lockElements($initialState);
            $lockedConfigSnapshot = $this->readConfigSnapshot(true);
            self::assertConfigSnapshotUnchanged($initialConfigSnapshot, $lockedConfigSnapshot);
            $lockedPublishedRaw = $this->readOptionRaw(
                'prospektweb.frontcalc',
                'FORM_FIRST_PRESET_12740',
                true
            );
            $currentState = $this->loadBitrixState($lockedPublishedRaw, $lockedConfigSnapshot);
            $plan = self::buildPlan($currentState);
            if (!hash_equals((string)$plan['fingerprint'], $expectedFingerprint)) {
                throw new \RuntimeException(
                    'Preset 12740 changed after the migration audit. Repeat the audit before applying.',
                    409
                );
            }
            if (($plan['status'] ?? '') === 'complete') {
                self::assertCompletionEvidence(
                    $plan,
                    $this->readOptionRaw(self::MODULE_ID, self::MARKER_OPTION, true),
                    $this->readOptionRaw(self::MODULE_ID, self::BACKUP_OPTION, true)
                );
                (new \Prospektweb\Calc\Services\NeutralFormulaPolicy())
                    ->assertCurrentPresetAuthoringStateSafe(
                        $lockedConfigSnapshot,
                        (int)($globalActivationPlan['globalIblockId'] ?? 0)
                    );
                $this->setGlobalOption(self::ACTIVE_OPTION, 'Y');
                $connection->commitTransaction();
                $transactionStarted = false;
                unset($plan['_nextState']);
                $plan['activated'] = true;
                return $plan;
            }
            if (($plan['ready'] ?? false) !== true
                || count((array)($plan['mutations'] ?? [])) !== self::EXPECTED_MUTATION_COUNT
                || !is_array($plan['_nextState'] ?? null)) {
                throw new \RuntimeException('Preset 12740 neutral-input migration is not safe to apply.');
            }

            $backup = [
                'contract' => self::CONTRACT,
                'presetId' => self::PRESET_ID,
                'fingerprint' => (string)$plan['fingerprint'],
                'state' => $currentState,
            ];
            $backupRaw = $this->storeBackup($backup);
            $this->writeAffectedState($plan['_nextState'], (array)$plan['mutations']);

            $readBack = $this->loadBitrixState($lockedPublishedRaw, $lockedConfigSnapshot);
            $verified = self::buildPlan($readBack);
            if (($verified['status'] ?? '') !== 'complete'
                || ($verified['ready'] ?? false) !== true
                || !hash_equals((string)$plan['nextFingerprint'], (string)$verified['fingerprint'])) {
                throw new \RuntimeException('Preset 12740 neutral-input migration read-back verification failed.');
            }

            $this->setGlobalOption(self::MARKER_OPTION, self::encodeCanonical([
                'contract' => self::CONTRACT,
                'presetId' => self::PRESET_ID,
                'beforeFingerprint' => (string)$plan['fingerprint'],
                'afterFingerprint' => (string)$verified['fingerprint'],
                'backupHash' => hash('sha256', $backupRaw),
                'appliedAt' => gmdate('c'),
            ]));
            self::assertCompletionEvidence(
                $verified,
                $this->readOptionRaw(self::MODULE_ID, self::MARKER_OPTION, true),
                $this->readOptionRaw(self::MODULE_ID, self::BACKUP_OPTION, true)
            );
            (new \Prospektweb\Calc\Services\NeutralFormulaPolicy())
                ->assertCurrentPresetAuthoringStateSafe(
                    $lockedConfigSnapshot,
                    (int)($globalActivationPlan['globalIblockId'] ?? 0)
                );
            $this->setGlobalOption(self::ACTIVE_OPTION, 'Y');
            $connection->commitTransaction();
            $transactionStarted = false;

            unset($verified['_nextState']);
            $verified['activated'] = true;
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
        $connection = Application::getConnection();
        // See apply(): topology discovery must not pin the transaction to a
        // pre-lock consistent snapshot.
        $initialConfigSnapshot = $this->readConfigSnapshot(false);
        $initialPublishedRaw = $this->readOptionRaw(
            'prospektweb.frontcalc',
            'FORM_FIRST_PRESET_12740',
            false
        );
        $initialState = $this->loadBitrixState($initialPublishedRaw, $initialConfigSnapshot);
        $transactionStarted = false;
        try {
            $connection->startTransaction();
            $transactionStarted = true;
            $this->lockNeutralOptionAuthorities();
            (new Preset12740NeutralGlobalSymbolMigrationService())
                ->assertV1RollbackReadyLocked(true);
            $this->lockElements($initialState);
            $lockedConfigSnapshot = $this->readConfigSnapshot(true);
            self::assertConfigSnapshotUnchanged($initialConfigSnapshot, $lockedConfigSnapshot);
            $lockedPublishedRaw = $this->readOptionRaw(
                'prospektweb.frontcalc',
                'FORM_FIRST_PRESET_12740',
                true
            );
            $currentState = $this->loadBitrixState($lockedPublishedRaw, $lockedConfigSnapshot);
            $backupRaw = $this->readOptionRaw(self::MODULE_ID, self::BACKUP_OPTION, true);
            $markerRaw = $this->readOptionRaw(self::MODULE_ID, self::MARKER_OPTION, true);
            $backup = json_decode($backupRaw, true);
            $marker = json_decode($markerRaw, true);
            $currentPlan = self::buildPlan($currentState);
            self::assertCompletionEvidence($currentPlan, $markerRaw, $backupRaw);
            if (!is_array($backup) || !is_array($backup['state'] ?? null)
                || (string)($backup['contract'] ?? '') !== self::CONTRACT
                || (int)($backup['presetId'] ?? 0) !== self::PRESET_ID
                || !is_array($marker)
                || (string)($marker['afterFingerprint'] ?? '') !== $expectedFingerprint) {
                throw new \RuntimeException('A matching preset 12740 neutral-input backup is unavailable.');
            }
            $currentFingerprint = self::fingerprint($currentState);
            if (!hash_equals($expectedFingerprint, $currentFingerprint)) {
                throw new \RuntimeException('Preset 12740 changed after migration; rollback is refused.', 409);
            }

            $backupPlan = self::buildPlan($backup['state']);
            if (($backupPlan['status'] ?? '') !== 'pending'
                || ($backupPlan['ready'] ?? false) !== true
                || count((array)($backupPlan['mutations'] ?? [])) !== self::EXPECTED_MUTATION_COUNT) {
                throw new \RuntimeException('The preset 12740 backup no longer matches the audited migration scope.');
            }
            $this->writeAffectedState($backup['state'], (array)$backupPlan['mutations']);
            $restored = $this->loadBitrixState($lockedPublishedRaw, $lockedConfigSnapshot);
            if (!hash_equals((string)$backup['fingerprint'], self::fingerprint($restored))) {
                throw new \RuntimeException('Preset 12740 rollback read-back verification failed.');
            }
            $this->setGlobalOption(self::ACTIVE_OPTION, 'N');
            $this->deleteGlobalOption(self::MODULE_ID, self::MARKER_OPTION);
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

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public static function buildPlan(array $state): array
    {
        self::validateState($state);
        $fingerprint = self::fingerprint($state);
        $next = $state;
        $bindingMap = $state['bindingMap'];
        $mutations = [];
        $unresolved = [];
        $directReferenceCount = 0;
        $neutralReferenceCount = 0;

        foreach ($state['globals'] as $propertyCode => $rows) {
            foreach ($rows as $index => $row) {
                $description = (string)($row['DESCRIPTION'] ?? '');
                [$formula, $suffix] = self::splitDescription($description);
                $rewritten = self::rewriteFormula($formula, $bindingMap, $unresolved, [
                    'kind' => 'global',
                    'propertyCode' => (string)$propertyCode,
                    'rowIndex' => (int)$index,
                    'code' => (string)($row['VALUE'] ?? ''),
                ]);
                $neutralReferenceCount += self::countNeutralFormulaReferences($rewritten);
                if ($rewritten === $formula) {
                    continue;
                }
                $directReferenceCount++;
                $rewrittenDescription = self::rewriteHumanDescription($rewritten . $suffix);
                $next['globals'][$propertyCode][$index]['DESCRIPTION'] = $rewrittenDescription;
                $mutations[] = [
                    'kind' => 'global',
                    'elementId' => self::PRESET_ID,
                    'propertyCode' => (string)$propertyCode,
                    'rowIndex' => (int)$index,
                    'code' => (string)($row['VALUE'] ?? ''),
                    'before' => $description,
                    'after' => $rewrittenDescription,
                ];
            }
        }

        foreach ($state['stages'] as $stageKey => $stage) {
            foreach ($stage['rows'] as $index => $row) {
                $description = trim((string)($row['DESCRIPTION'] ?? ''));
                if (preg_match('/^input\.values\.[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $description) === 1) {
                    $neutralReferenceCount++;
                    continue;
                }
                if (preg_match(
                    '/^(offer|product|selectedOffer)\.properties\.(CALC_PROP_[A-Z0-9_]+)\.(VALUE_XML_ID|VALUE)$/D',
                    $description,
                    $matches
                ) !== 1) {
                    if (preg_match('/\b(?:offer|product|selectedOffers?)\b/i', $description) === 1) {
                        $unresolved[] = [
                            'kind' => 'stage-input',
                            'stageId' => (int)$stage['id'],
                            'rowIndex' => (int)$index,
                            'value' => (string)($row['VALUE'] ?? ''),
                            'sourcePath' => $description,
                            'reason' => 'unsupported-entity-source',
                        ];
                    }
                    continue;
                }
                $directReferenceCount++;
                $property = (string)$matches[2];
                $fieldId = (string)($bindingMap[$property] ?? '');
                if ($fieldId === '') {
                    $unresolved[] = [
                        'kind' => 'stage-input',
                        'stageId' => (int)$stage['id'],
                        'rowIndex' => (int)$index,
                        'value' => (string)($row['VALUE'] ?? ''),
                        'sourcePath' => $description,
                        'propertyCode' => $property,
                        'reason' => 'binding-missing',
                    ];
                    continue;
                }
                $rewritten = 'input.values.' . $fieldId;
                $neutralReferenceCount++;
                $next['stages'][$stageKey]['rows'][$index]['DESCRIPTION'] = $rewritten;
                $mutations[] = [
                    'kind' => 'stage-input',
                    'elementId' => (int)$stage['id'],
                    'elementName' => (string)($stage['name'] ?? ''),
                    'propertyCode' => 'INPUTS',
                    'rowIndex' => (int)$index,
                    'code' => (string)($row['VALUE'] ?? ''),
                    'before' => $description,
                    'after' => $rewritten,
                ];
            }
        }

        $mutationCount = count($mutations);
        $alreadyComplete = $directReferenceCount === 0
            && $mutationCount === 0
            && $neutralReferenceCount === self::EXPECTED_MUTATION_COUNT
            && $unresolved === [];
        $expectedCount = $alreadyComplete ? 0 : self::EXPECTED_MUTATION_COUNT;
        if ($unresolved === [] && $mutationCount !== $expectedCount) {
            $unresolved[] = [
                'kind' => 'migration-scope',
                'reason' => 'unexpected-mutation-count',
                'expected' => $expectedCount,
                'actual' => $mutationCount,
                'directReferences' => $directReferenceCount,
            ];
        }
        if ($unresolved === []
            && $mutationCount === 0
            && $neutralReferenceCount !== self::EXPECTED_MUTATION_COUNT) {
            $unresolved[] = [
                'kind' => 'migration-scope',
                'reason' => 'unexpected-neutral-reference-count',
                'expected' => self::EXPECTED_MUTATION_COUNT,
                'actual' => $neutralReferenceCount,
            ];
        }

        $ready = $unresolved === [];
        $status = $alreadyComplete && $ready ? 'complete' : ($ready ? 'pending' : 'blocked');
        return [
            'contract' => self::CONTRACT,
            'presetId' => self::PRESET_ID,
            'status' => $status,
            'ready' => $ready,
            'fingerprint' => $fingerprint,
            'nextFingerprint' => self::fingerprint($next),
            'mutations' => $mutations,
            'neutralReferenceCount' => $neutralReferenceCount,
            'unresolved' => $unresolved,
            'bindingMap' => $bindingMap,
            '_nextState' => $next,
        ];
    }

    /** @param array<string,string> $bindingMap @param array<int,array<string,mixed>> $unresolved */
    private static function rewriteFormula(string $formula, array $bindingMap, array &$unresolved, array $location): string
    {
        if (preg_match('/\b(?:offer|product|selectedOffers?)\b/i', $formula) !== 1) {
            return $formula;
        }

        $code = (string)($location['code'] ?? '');
        $specifications = [
            'finished_item_width_mm' => [
                'propertyCode' => 'CALC_PROP_FORMAT',
                'fieldId' => 'format',
                'pattern' => '/^get\(\s*split\(\s*get\(\s*offer\s*,\s*(["\'])properties\.CALC_PROP_FORMAT\.(?:VALUE_XML_ID|VALUE)\1\s*\)\s*,\s*(["\'])x\2\s*\)\s*,\s*0\s*\)$/D',
                'replacement' => 'get(input, "values.format.width")',
            ],
            'finished_item_length_mm' => [
                'propertyCode' => 'CALC_PROP_FORMAT',
                'fieldId' => 'format',
                'pattern' => '/^get\(\s*split\(\s*get\(\s*offer\s*,\s*(["\'])properties\.CALC_PROP_FORMAT\.(?:VALUE_XML_ID|VALUE)\1\s*\)\s*,\s*(["\'])x\2\s*\)\s*,\s*1\s*\)$/D',
                'replacement' => 'get(input, "values.format.height")',
            ],
            'is_double_sided_printing' => [
                'propertyCode' => 'CALC_PROP_COLOR_SCHEME',
                'fieldId' => 'color.scheme',
                'pattern' => '/^if\(\s*toNumber\(\s*get\(\s*split\(\s*get\(\s*offer\s*,\s*(["\'])properties\.CALC_PROP_COLOR_SCHEME\.(?:VALUE_XML_ID|VALUE)\1\s*\)\s*,\s*(["\'])\+\2\s*\)\s*,\s*1\s*\)\s*\)\s*!=\s*0\s*,\s*true\s*,\s*false\s*\)$/D',
                'replacement' => 'if(toNumber(get(split(get(input, "values.color.scheme"), "+"), 1)) != 0, true, false)',
            ],
        ];
        $specification = $specifications[$code] ?? null;
        if (is_array($specification)
            && (string)($bindingMap[(string)$specification['propertyCode']] ?? '') === (string)$specification['fieldId']
            && preg_match((string)$specification['pattern'], $formula) === 1) {
            return (string)$specification['replacement'];
        }

        $unresolved[] = $location + [
            'formula' => $formula,
            'reason' => 'unsupported-entity-formula',
        ];
        return $formula;
    }

    private static function rewriteHumanDescription(string $description): string
    {
        return str_replace([
            'Определяется первой стороной формата торгового предложения CALC_PROP_FORMAT.',
            'Определяется второй стороной формата торгового предложения CALC_PROP_FORMAT.',
            'Источник: цветовая схема торгового предложения.',
        ], [
            'Определяется первой стороной значения поля формы «Формат».',
            'Определяется второй стороной значения поля формы «Формат».',
            'Источник: поле формы «Красочность печати».',
        ], $description);
    }

    private static function countNeutralFormulaReferences(string $formula): int
    {
        $count = preg_match_all(
            '/get\(\s*input\s*,\s*(["\'])values\.[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\1\s*\)/',
            $formula
        );
        return is_int($count) ? $count : 0;
    }

    /** @return array{0:string,1:string} */
    private static function splitDescription(string $description): array
    {
        $escaped = false;
        $length = strlen($description);
        for ($index = 0; $index < $length; $index++) {
            if ($description[$index] === '\\') {
                $escaped = !$escaped;
                continue;
            }
            if ($description[$index] === '|' && !$escaped) {
                return [substr($description, 0, $index), substr($description, $index)];
            }
            $escaped = false;
        }
        return [$description, ''];
    }

    /**
     * @param array<string,mixed> $pinnedConfigSnapshot
     * @return array<string,mixed>
     */
    private function loadBitrixState(string $publishedRaw, array $pinnedConfigSnapshot): array
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('prospektweb.frontcalc')) {
            throw new \RuntimeException('The iblock and prospektweb.frontcalc modules are required.');
        }
        $presetIblockId = (int)($pinnedConfigSnapshot['presetIblockId'] ?? 0);
        $detailsIblockId = (int)($pinnedConfigSnapshot['detailsIblockId'] ?? 0);
        $stagesIblockId = (int)($pinnedConfigSnapshot['stagesIblockId'] ?? 0);
        if ($presetIblockId <= 0 || $detailsIblockId <= 0 || $stagesIblockId <= 0) {
            throw new \RuntimeException('The pinned preset, detail or stage iblock topology is invalid.');
        }
        $preset = \CIBlockElement::GetList([], [
            'IBLOCK_ID' => $presetIblockId,
            'ID' => self::PRESET_ID,
        ], false, ['nTopCount' => 1], ['ID'])->Fetch();
        if (!$preset) {
            throw new \RuntimeException('Preset 12740 is unavailable.');
        }

        $authoring = \Prospektweb\Frontcalc\Service\FormFirstAuthoringStore::publishedAuthoringFromRaw(
            self::PRESET_ID,
            $publishedRaw
        );
        if (!is_array($authoring) || !is_array($authoring['bindingDefinition'] ?? null)) {
            throw new \RuntimeException('Published preset-owned BindingDefinition 12740 is unavailable.');
        }
        $bindingMap = [];
        foreach ((array)($authoring['bindingDefinition']['bindings'] ?? []) as $binding) {
            if (!is_array($binding)) {
                continue;
            }
            $fieldId = trim((string)($binding['fieldId'] ?? ''));
            $target = is_array($binding['target'] ?? null) ? $binding['target'] : [];
            $propertyCode = trim((string)($target['propertyCode'] ?? ''));
            if ($fieldId !== '' && preg_match('/^CALC_PROP_[A-Z0-9_]+$/D', $propertyCode) === 1) {
                $bindingMap[$propertyCode] = $fieldId;
            }
        }
        ksort($bindingMap, SORT_STRING);

        $stageIds = self::elementPropertyIds($presetIblockId, self::PRESET_ID, 'CALC_STAGES');
        $pendingDetails = self::elementPropertyIds($presetIblockId, self::PRESET_ID, 'CALC_DETAILS');
        $seenDetails = [];
        while ($pendingDetails !== []) {
            $detailId = (int)array_shift($pendingDetails);
            if ($detailId <= 0 || isset($seenDetails[$detailId])) {
                continue;
            }
            $seenDetails[$detailId] = true;
            foreach (self::elementPropertyIds($detailsIblockId, $detailId, 'DETAILS') as $childId) {
                $pendingDetails[] = $childId;
            }
            foreach (self::elementPropertyIds($detailsIblockId, $detailId, 'CALC_STAGES') as $stageId) {
                $stageIds[] = $stageId;
            }
        }
        $detailIds = array_values(array_unique(array_filter(array_map('intval', array_keys($seenDetails)))));
        sort($detailIds, SORT_NUMERIC);
        self::assertExactElementMembership(
            $detailIds,
            self::readElementMembershipRows($detailIds),
            $detailsIblockId,
            'detail'
        );
        $stageIds = array_values(array_unique(array_filter(array_map('intval', $stageIds))));
        sort($stageIds, SORT_NUMERIC);
        self::assertExactElementMembership(
            $stageIds,
            self::readElementMembershipRows($stageIds),
            $stagesIblockId,
            'stage'
        );

        $globals = [];
        foreach (['GLOBAL_CONSTANTS', 'GLOBAL_VARIABLES'] as $propertyCode) {
            $globals[$propertyCode] = self::readPropertyRows($presetIblockId, self::PRESET_ID, $propertyCode);
        }
        $stages = [];
        if ($stageIds !== []) {
            $cursor = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                ['IBLOCK_ID' => $stagesIblockId, 'ID' => $stageIds],
                false,
                false,
                ['ID', 'NAME']
            );
            while ($stage = $cursor->Fetch()) {
                $stageId = (int)$stage['ID'];
                $stages[(string)$stageId] = [
                    'id' => $stageId,
                    'name' => (string)$stage['NAME'],
                    'rows' => self::readPropertyRows($stagesIblockId, $stageId, 'INPUTS'),
                ];
            }
        }
        ksort($stages, SORT_NUMERIC);

        return [
            'presetId' => self::PRESET_ID,
            'presetIblockId' => $presetIblockId,
            'detailsIblockId' => $detailsIblockId,
            'stagesIblockId' => $stagesIblockId,
            'detailIds' => $detailIds,
            'bindingMap' => $bindingMap,
            'globals' => $globals,
            'stages' => $stages,
        ];
    }

    /** @param array<string,mixed> $state */
    private function writeAffectedState(array $state, array $mutations): void
    {
        $presetIblockId = (int)$state['presetIblockId'];
        $stagesIblockId = (int)$state['stagesIblockId'];
        $globalPropertyCodes = [];
        $stageIds = [];
        foreach ($mutations as $mutation) {
            if (!is_array($mutation)) {
                continue;
            }
            if (($mutation['kind'] ?? '') === 'global') {
                $globalPropertyCodes[(string)($mutation['propertyCode'] ?? '')] = true;
            } elseif (($mutation['kind'] ?? '') === 'stage-input') {
                $stageIds[(int)($mutation['elementId'] ?? 0)] = true;
            }
        }
        unset($globalPropertyCodes[''], $stageIds[0]);

        foreach (array_keys($globalPropertyCodes) as $propertyCode) {
            $rows = (array)($state['globals'][$propertyCode] ?? []);
            // Bitrix may keep the old DESCRIPTION when the VALUE is unchanged.
            // Clear the complete multi-value property first so rollback and
            // re-apply persist the audited formula/path descriptions exactly.
            \CIBlockElement::SetPropertyValues(
                self::PRESET_ID,
                $presetIblockId,
                [],
                (string)$propertyCode
            );
            \CIBlockElement::SetPropertyValuesEx(self::PRESET_ID, $presetIblockId, [
                (string)$propertyCode => $rows !== []
                    ? self::encodeMultiplePropertyRows($rows)
                    : false,
            ]);
        }
        foreach ($state['stages'] as $stage) {
            if (!isset($stageIds[(int)$stage['id']])) {
                continue;
            }
            \CIBlockElement::SetPropertyValues(
                (int)$stage['id'],
                $stagesIblockId,
                [],
                'INPUTS'
            );
            \CIBlockElement::SetPropertyValuesEx((int)$stage['id'], $stagesIblockId, [
                'INPUTS' => $stage['rows'] !== []
                    ? self::encodeMultiplePropertyRows((array)$stage['rows'])
                    : false,
            ]);
        }
    }

    /**
     * The retained backup is canonical JSON, so each decoded row is ordered as
     * DESCRIPTION, VALUE. Production SetPropertyValuesEx accepts a described
     * non-file row only when its first two keys are VALUE, DESCRIPTION; in the
     * reverse order it silently drops the row. Rebuild both the documented
     * numeric list and every inner pair instead of preserving JSON key order.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{VALUE:string,DESCRIPTION:string}>
     */
    private static function encodeMultiplePropertyRows(array $rows): array
    {
        $encoded = [];
        foreach (array_values($rows) as $row) {
            if (!is_array($row)
                || !array_key_exists('VALUE', $row)
                || !array_key_exists('DESCRIPTION', $row)) {
                throw new \InvalidArgumentException('Invalid preset-12740 multi-value property row.');
            }
            $encoded[] = [
                'VALUE' => (string)$row['VALUE'],
                'DESCRIPTION' => (string)$row['DESCRIPTION'],
            ];
        }
        return $encoded;
    }

    /** @param array<string,mixed> $state */
    private function lockElements(array $state): void
    {
        $connection = Application::getConnection();
        $ids = [self::PRESET_ID];
        foreach ((array)($state['detailIds'] ?? []) as $detailId) {
            $ids[] = (int)$detailId;
        }
        foreach ($state['stages'] as $stage) {
            $ids[] = (int)$stage['id'];
        }
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids, SORT_NUMERIC);
        $connection->queryExecute(
            'SELECT ID FROM b_iblock_element WHERE ID IN (' . implode(',', $ids) . ') ORDER BY ID FOR UPDATE'
        );

        $idsByIblock = [
            (int)$state['presetIblockId'] => [self::PRESET_ID],
            (int)$state['detailsIblockId'] => array_values(array_map('intval', (array)($state['detailIds'] ?? []))),
            (int)$state['stagesIblockId'] => array_values(array_map(
                static fn(array $stage): int => (int)($stage['id'] ?? 0),
                array_values((array)$state['stages'])
            )),
        ];
        foreach ($idsByIblock as $iblockId => $elementIds) {
            $elementIds = array_values(array_unique(array_filter($elementIds)));
            sort($elementIds, SORT_NUMERIC);
            if ($iblockId <= 0 || $elementIds === []) {
                continue;
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

    }

    private function lockNeutralOptionAuthorities(): void
    {
        // Acquire the exact superset needed by V1 activation, V2 evidence and
        // formula writers in one deterministic pass. No registry/formula row
        // may be locked before this query, otherwise writers can form an
        // options <-> global-registry cycle with activation.
        Application::getConnection()->queryExecute(
            "SELECT MODULE_ID, NAME, SITE_ID FROM b_option WHERE ("
            . "(MODULE_ID='prospektweb.frontcalc' AND UPPER(TRIM(NAME)) IN "
            . "('FORM_FIRST_PRESET_12740','IBLOCK_CALC_GLOBAL_VALUES')) OR "
            . "(MODULE_ID='prospektweb.calc' AND UPPER(TRIM(NAME)) IN ("
            . "'IBLOCK_CALC_DETAILS','IBLOCK_CALC_GLOBAL_VALUES','IBLOCK_CALC_PRESETS',"
            . "'IBLOCK_CALC_SETTINGS','IBLOCK_CALC_STAGES',"
            . "'PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_BACKUP_V1',"
            . "'PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_MIGRATION_V1',"
            . "'PRESET_12740_NEUTRAL_INPUT_ACTIVE','PRESET_12740_NEUTRAL_INPUT_BACKUP_V1',"
            . "'PRESET_12740_NEUTRAL_INPUT_MIGRATION_V1'))) "
            . 'ORDER BY MODULE_ID, NAME, SITE_ID FOR UPDATE'
        );
    }

    /**
     * A pre-neutralized preset may only activate when it is the verified result
     * of this exact migration. This preserves a usable rollback boundary and
     * prevents an unrelated manual rewrite from silently enabling the new wire
     * contract.
     *
     * @param array<string,mixed> $plan
     */
    private static function assertCompletionEvidence(array $plan, string $markerRaw, string $backupRaw): void
    {
        $marker = json_decode($markerRaw, true);
        $backup = json_decode($backupRaw, true);
        $afterFingerprint = (string)($plan['fingerprint'] ?? '');
        if (($plan['status'] ?? '') !== 'complete'
            || ($plan['ready'] ?? false) !== true
            || preg_match('/^[a-f0-9]{64}$/D', $afterFingerprint) !== 1
            || !is_array($marker)
            || (string)($marker['contract'] ?? '') !== self::CONTRACT
            || (int)($marker['presetId'] ?? 0) !== self::PRESET_ID
            || !hash_equals($afterFingerprint, (string)($marker['afterFingerprint'] ?? ''))
            || !is_array($backup)
            || (string)($backup['contract'] ?? '') !== self::CONTRACT
            || (int)($backup['presetId'] ?? 0) !== self::PRESET_ID
            || !is_array($backup['state'] ?? null)
            || !hash_equals(self::encodeCanonical($backup), $backupRaw)) {
            throw new \RuntimeException(
                'Preset 12740 is neutral but has no matching verified migration marker and backup.'
            );
        }

        $beforeFingerprint = (string)($marker['beforeFingerprint'] ?? '');
        $backupHash = (string)($marker['backupHash'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $beforeFingerprint) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $backupHash) !== 1
            || !hash_equals($beforeFingerprint, (string)($backup['fingerprint'] ?? ''))
            || !hash_equals($backupHash, hash('sha256', $backupRaw))) {
            throw new \RuntimeException('Preset 12740 migration evidence is incomplete or corrupted.');
        }

        $backupPlan = self::buildPlan($backup['state']);
        if (($backupPlan['status'] ?? '') !== 'pending'
            || ($backupPlan['ready'] ?? false) !== true
            || count((array)($backupPlan['mutations'] ?? [])) !== self::EXPECTED_MUTATION_COUNT
            || !hash_equals($beforeFingerprint, (string)($backupPlan['fingerprint'] ?? ''))
            || !hash_equals($afterFingerprint, (string)($backupPlan['nextFingerprint'] ?? ''))) {
            throw new \RuntimeException('Preset 12740 migration backup does not reproduce the active neutral state.');
        }
    }

    /**
     * @param array<string,mixed> $currentState
     * @param array<string,mixed> $activeOption
     * @param array<string,mixed> $markerOption
     * @param array<string,mixed> $backupOption
     * @return array<string,mixed>
     */
    private static function assertRollbackResumeEvidence(
        array $currentState,
        array $activeOption,
        array $markerOption,
        array $backupOption
    ): array {
        if (($activeOption['exists'] ?? false) !== true || ($activeOption['value'] ?? null) !== 'N') {
            throw new \RuntimeException('Preset 12740 rollback resume requires exact ACTIVE=N evidence.', 409);
        }
        if (($markerOption['exists'] ?? false) !== false) {
            throw new \RuntimeException('Preset 12740 rollback resume requires the V1 marker to be absent.', 409);
        }
        $backupRaw = ($backupOption['exists'] ?? false) === true
            ? (string)($backupOption['value'] ?? '')
            : '';
        $backup = $backupRaw !== '' ? json_decode($backupRaw, true) : null;
        if (!is_array($backup)
            || !hash_equals(self::encodeCanonical($backup), $backupRaw)
            || (string)($backup['contract'] ?? '') !== self::CONTRACT
            || (int)($backup['presetId'] ?? 0) !== self::PRESET_ID
            || !is_array($backup['state'] ?? null)) {
            throw new \RuntimeException('Preset 12740 retained rollback backup is unavailable or non-canonical.', 409);
        }

        $currentPlan = self::buildPlan($currentState);
        $backupPlan = self::buildPlan($backup['state']);
        $backupFingerprint = (string)($backup['fingerprint'] ?? '');
        if (($currentPlan['status'] ?? '') !== 'pending'
            || ($currentPlan['ready'] ?? false) !== true
            || count((array)($currentPlan['mutations'] ?? [])) !== self::EXPECTED_MUTATION_COUNT
            || ($backupPlan['status'] ?? '') !== 'pending'
            || ($backupPlan['ready'] ?? false) !== true
            || count((array)($backupPlan['mutations'] ?? [])) !== self::EXPECTED_MUTATION_COUNT
            || preg_match('/^[a-f0-9]{64}$/D', $backupFingerprint) !== 1
            || !hash_equals($backupFingerprint, (string)($backupPlan['fingerprint'] ?? ''))
            || !hash_equals($backupFingerprint, (string)($currentPlan['fingerprint'] ?? ''))
            || !hash_equals(self::encodeCanonical($backup['state']), self::encodeCanonical($currentState))) {
            throw new \RuntimeException('Preset 12740 current state does not exactly match the retained rollback backup.', 409);
        }
        unset($currentPlan['_nextState']);
        $currentPlan['rollbackResumeReady'] = true;
        return $currentPlan;
    }

    /**
     * Read the four migration/activation topology authorities without either Bitrix
     * Option's process cache or ConfigManager's process-static cache.
     *
     * @return array<string,mixed>
     */
    private function readConfigSnapshot(bool $forUpdate): array
    {
        $rows = [];
        $result = Application::getConnection()->query(
            "SELECT MODULE_ID, NAME, VALUE, SITE_ID FROM b_option WHERE MODULE_ID='prospektweb.calc' "
            . "AND UPPER(TRIM(NAME)) IN ('IBLOCK_CALC_DETAILS','IBLOCK_CALC_PRESETS',"
            . "'IBLOCK_CALC_SETTINGS','IBLOCK_CALC_STAGES') "
            . "ORDER BY MODULE_ID, NAME, SITE_ID"
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        while (is_object($result) && method_exists($result, 'fetch') && ($row = $result->fetch())) {
            $rows[] = $row;
        }
        return self::normalizeConfigSnapshotRows($rows);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private static function normalizeConfigSnapshotRows(array $rows): array
    {
        $rawByName = [];
        foreach ($rows as $row) {
            $actualModule = (string)($row['MODULE_ID'] ?? $row['module_id'] ?? '');
            $actualName = (string)($row['NAME'] ?? $row['name'] ?? '');
            // Bitrix Option lookups on the production b_option collation are
            // case-insensitive. Canonicalize only ASCII case for the exact
            // allowlist; do not trim or accept aliases that the runtime probe
            // did not establish as equivalent authorities.
            $canonicalName = strtoupper($actualName);
            if ($actualModule !== self::MODULE_ID
                || $actualName !== trim($actualName)
                || !array_key_exists($canonicalName, self::CONFIG_OPTION_TO_STATE_KEY)) {
                throw new \RuntimeException('Unexpected preset 12740 migration config option row.', 409);
            }
            $hasSiteId = array_key_exists('SITE_ID', $row) || array_key_exists('site_id', $row);
            $siteId = array_key_exists('SITE_ID', $row)
                ? $row['SITE_ID']
                : ($row['site_id'] ?? null);
            if (!$hasSiteId || !in_array($siteId, [null, ''], true)) {
                throw new \RuntimeException('Preset 12740 migration config option row is not global.', 409);
            }
            if (array_key_exists($canonicalName, $rawByName)) {
                throw new \RuntimeException('Duplicate global preset 12740 migration config option row.', 409);
            }
            $rawByName[$canonicalName] = [
                'moduleId' => $actualModule,
                'name' => $actualName,
                'siteId' => $siteId,
                'value' => (string)($row['VALUE'] ?? $row['value'] ?? ''),
            ];
        }

        $snapshot = ['options' => [], 'rowIdentities' => []];
        foreach (self::CONFIG_OPTION_TO_STATE_KEY as $name => $stateKey) {
            if (!array_key_exists($name, $rawByName)) {
                throw new \RuntimeException('Preset 12740 migration iblock topology is incomplete.', 409);
            }
            $raw = (string)$rawByName[$name]['value'];
            $iblockId = (int)$raw;
            if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1
                || $iblockId <= 0
                || (string)$iblockId !== $raw) {
                throw new \RuntimeException('Preset 12740 migration iblock topology is invalid.', 409);
            }
            $snapshot['options'][$name] = $raw;
            $snapshot['rowIdentities'][$name] = [
                'moduleId' => (string)$rawByName[$name]['moduleId'],
                'name' => (string)$rawByName[$name]['name'],
                'siteId' => $rawByName[$name]['siteId'],
            ];
            $snapshot[$stateKey] = $iblockId;
        }
        ksort($snapshot['options'], SORT_STRING);
        ksort($snapshot['rowIdentities'], SORT_STRING);
        return $snapshot;
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private static function assertConfigSnapshotUnchanged(array $expected, array $actual): void
    {
        $expectedRaw = self::encodeCanonical($expected);
        $actualRaw = self::encodeCanonical($actual);
        if (!hash_equals($expectedRaw, $actualRaw)) {
            throw new \RuntimeException(
                'Preset 12740 migration iblock topology changed after audit. Repeat the audit before applying.',
                409
            );
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{exists:bool,moduleId:string,name:string,siteId:mixed,value:string}
     */
    private static function normalizeOptionStateRows(string $moduleId, string $name, array $rows): array
    {
        self::assertAllowedOptionRequest($moduleId, $name);
        $normalized = null;
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
                throw new \RuntimeException('Unexpected global preset 12740 migration option authority.', 409);
            }
            if (is_array($normalized)) {
                throw new \RuntimeException('Duplicate global preset 12740 migration option row.', 409);
            }
            $value = (string)($row['VALUE'] ?? $row['value'] ?? '');
            if ((in_array($name, self::IMMUTABLE_EVIDENCE_OPTION_NAMES, true) && trim($value) === '')
                || ($name === self::ACTIVE_OPTION && !in_array($value, ['N', 'Y'], true))) {
                throw new \RuntimeException('Invalid global preset 12740 migration option value.', 409);
            }
            $normalized = [
                'exists' => true,
                'moduleId' => $actualModule,
                'name' => $actualName,
                'siteId' => $siteId,
                'value' => $value,
            ];
        }
        return $normalized ?? [
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
            throw new \InvalidArgumentException('Unsupported preset 12740 option authority request.');
        }
    }

    /** @return array{exists:bool,moduleId:string,name:string,siteId:mixed,value:string} */
    private function readOptionState(string $moduleId, string $name, bool $forUpdate): array
    {
        self::assertAllowedOptionRequest($moduleId, $name);
        $escape = static fn(string $value): string => str_replace("'", "''", $value);
        $result = Application::getConnection()->query(
            "SELECT MODULE_ID, NAME, VALUE, SITE_ID FROM b_option WHERE MODULE_ID='" . $escape($moduleId)
             . "' AND UPPER(TRIM(NAME))='" . $escape($name) . "'"
             . ' ORDER BY MODULE_ID, NAME, SITE_ID'
             . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $rows = [];
        while (is_object($result) && method_exists($result, 'fetch') && ($row = $result->fetch())) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return self::normalizeOptionStateRows($moduleId, $name, $rows);
    }

    /** Read exactly one global row without Bitrix Option's process cache. */
    private function readOptionRaw(string $moduleId, string $name, bool $forUpdate): string
    {
        return (string)$this->readOptionState($moduleId, $name, $forUpdate)['value'];
    }

    private function setGlobalOption(string $name, string $value): void
    {
        $before = $this->readOptionState(self::MODULE_ID, $name, true);
        if (($before['exists'] ?? false) === true
            && in_array($name, self::IMMUTABLE_EVIDENCE_OPTION_NAMES, true)
            && !hash_equals((string)$before['value'], $value)) {
            throw new \RuntimeException('A different preset 12740 migration evidence option already exists.', 409);
        }
        Option::set(self::MODULE_ID, $name, $value);
        $after = $this->readOptionState(self::MODULE_ID, $name, true);
        $readBack = (string)$after['value'];
        if (($after['exists'] ?? false) !== true
            || !hash_equals(hash('sha256', $value), hash('sha256', $readBack))
            || (($before['exists'] ?? false) === true
                && (!hash_equals((string)$before['name'], (string)$after['name'])
                    || $before['siteId'] !== $after['siteId']))) {
            throw new \RuntimeException('Unable to verify the global preset 12740 migration option.');
        }
    }

    private function deleteGlobalOption(string $moduleId, string $name): void
    {
        $snapshot = $this->readOptionState($moduleId, $name, true);
        if (($snapshot['exists'] ?? false) !== true) {
            return;
        }
        $escape = static fn(string $value): string => str_replace("'", "''", $value);
        $sitePredicate = $snapshot['siteId'] === null ? 'SITE_ID IS NULL' : "SITE_ID=''";
        Application::getConnection()->queryExecute(
            "DELETE FROM b_option WHERE BINARY MODULE_ID='" . $escape((string)$snapshot['moduleId'])
            . "' AND BINARY NAME='" . $escape((string)$snapshot['name']) . "' AND " . $sitePredicate
        );
        if (($this->readOptionState($moduleId, $name, true)['exists'] ?? false) === true) {
            throw new \RuntimeException('Unable to delete the global preset 12740 migration option.');
        }
    }

    /** @param array<string,mixed> $backup */
    private function storeBackup(array $backup): string
    {
        $existingState = $this->readOptionState(self::MODULE_ID, self::BACKUP_OPTION, true);
        $existing = (string)$existingState['value'];
        $encoded = self::resolveBackupRaw($backup, $existing);
        if (($existingState['exists'] ?? false) === true) {
            if ($existing === '' || !hash_equals($encoded, $existing)) {
                throw new \RuntimeException('A retained preset 12740 neutral-input backup is invalid.');
            }
            return $existing;
        }
        $this->setGlobalOption(self::BACKUP_OPTION, $encoded);
        $readBack = $this->readOptionRaw(self::MODULE_ID, self::BACKUP_OPTION, true);
        if (!hash_equals($encoded, $readBack)) {
            throw new \RuntimeException('Unable to verify the preset 12740 neutral-input backup.');
        }
        return $readBack;
    }

    /** @param array<string,mixed> $backup */
    private static function resolveBackupRaw(array $backup, string $existing): string
    {
        $encoded = self::encodeCanonical($backup);
        if ($existing !== '' && !hash_equals($encoded, $existing)) {
            throw new \RuntimeException('A different preset 12740 neutral-input backup already exists.');
        }
        return $existing !== '' ? $existing : $encoded;
    }

    /** @return int[] */
    private static function elementPropertyIds(int $iblockId, int $elementId, string $propertyCode): array
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

    /**
     * Read referenced elements without an iblock filter so a moved/wrong-iblock
     * element cannot be mistaken for a missing optional branch.
     *
     * @param int[] $elementIds
     * @return array<int,array<string,mixed>>
     */
    private static function readElementMembershipRows(array $elementIds): array
    {
        if ($elementIds === []) {
            return [];
        }

        $rows = [];
        $cursor = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['ID' => $elementIds],
            false,
            false,
            ['ID', 'IBLOCK_ID']
        );
        while ($row = $cursor->Fetch()) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * @param int[] $expectedIds
     * @param array<int,array<string,mixed>> $rows
     */
    private static function assertExactElementMembership(
        array $expectedIds,
        array $rows,
        int $expectedIblockId,
        string $label
    ): void {
        $expectedIds = array_values(array_unique(array_filter(array_map('intval', $expectedIds))));
        sort($expectedIds, SORT_NUMERIC);
        if ($expectedIblockId <= 0 || !in_array($label, ['detail', 'stage'], true)) {
            throw new \RuntimeException('Preset 12740 referenced-element authority is invalid.', 409);
        }

        $actualIds = [];
        foreach ($rows as $row) {
            $elementId = (int)($row['ID'] ?? $row['id'] ?? 0);
            $iblockId = (int)($row['IBLOCK_ID'] ?? $row['iblock_id'] ?? 0);
            if ($elementId <= 0
                || !in_array($elementId, $expectedIds, true)
                || isset($actualIds[$elementId])
                || $iblockId !== $expectedIblockId) {
                throw new \RuntimeException(
                    'Preset 12740 references a missing or wrong-iblock ' . $label . ' element.',
                    409
                );
            }
            $actualIds[$elementId] = true;
        }
        $actualIds = array_map('intval', array_keys($actualIds));
        sort($actualIds, SORT_NUMERIC);
        if ($actualIds !== $expectedIds) {
            throw new \RuntimeException(
                'Preset 12740 references a missing or wrong-iblock ' . $label . ' element.',
                409
            );
        }
    }

    /** @return array<int,array{VALUE:string,DESCRIPTION:string}> */
    private static function readPropertyRows(int $iblockId, int $elementId, string $propertyCode): array
    {
        $rows = [];
        $cursor = \CIBlockElement::GetProperty(
            $iblockId,
            $elementId,
            ['sort' => 'asc', 'id' => 'asc'],
            ['CODE' => $propertyCode]
        );
        while ($row = $cursor->Fetch()) {
            if ($row['VALUE'] === null || $row['VALUE'] === '') {
                continue;
            }
            $value = $row['VALUE'];
            if (is_array($value)) {
                $value = (string)($value['TEXT'] ?? '');
            }
            $rows[] = [
                'VALUE' => (string)$value,
                'DESCRIPTION' => (string)($row['DESCRIPTION'] ?? ''),
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $state */
    private static function validateState(array $state): void
    {
        if ((int)($state['presetId'] ?? 0) !== self::PRESET_ID
            || (int)($state['presetIblockId'] ?? 0) <= 0
            || (int)($state['detailsIblockId'] ?? 0) <= 0
            || (int)($state['stagesIblockId'] ?? 0) <= 0
            || !is_array($state['bindingMap'] ?? null)
            || !is_array($state['globals'] ?? null)
            || !is_array($state['stages'] ?? null)) {
            throw new \InvalidArgumentException('Invalid preset 12740 neutral-input migration state.');
        }
        foreach ($state['bindingMap'] as $propertyCode => $fieldId) {
            if (preg_match('/^CALC_PROP_[A-Z0-9_]+$/D', (string)$propertyCode) !== 1
                || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', (string)$fieldId) !== 1) {
                throw new \InvalidArgumentException('Invalid BindingDefinition entry in migration state.');
            }
        }
    }

    /** @param array<string,mixed> $state */
    private static function fingerprint(array $state): string
    {
        return hash('sha256', self::encodeCanonical($state));
    }

    private static function assertFingerprint(string $fingerprint): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('A SHA-256 migration fingerprint is required.');
        }
    }

    /** @param mixed $value */
    private static function encodeCanonical($value): string
    {
        $normalized = self::canonicalize($value);
        $encoded = json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to encode preset 12740 migration state.');
        }
        return $encoded;
    }

    /** @param mixed $value @return mixed */
    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_values($value) === $value) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
