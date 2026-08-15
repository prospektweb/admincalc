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
        'IBLOCK_CALC_STAGES' => 'stagesIblockId',
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
            $this->storeBackup($backup);
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
                'backupHash' => hash('sha256', self::encodeCanonical($backup)),
                'appliedAt' => gmdate('c'),
            ]));
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
            \CIBlockElement::SetPropertyValuesEx(self::PRESET_ID, $presetIblockId, [
                (string)$propertyCode => $rows !== [] ? array_values($rows) : false,
            ]);
        }
        foreach ($state['stages'] as $stage) {
            if (!isset($stageIds[(int)$stage['id']])) {
                continue;
            }
            \CIBlockElement::SetPropertyValuesEx((int)$stage['id'], $stagesIblockId, [
                'INPUTS' => $stage['rows'] !== [] ? array_values($stage['rows']) : false,
            ]);
        }
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

        $connection->queryExecute(
            "SELECT MODULE_ID, NAME, SITE_ID FROM b_option WHERE ("
            . "(MODULE_ID='prospektweb.frontcalc' AND NAME='FORM_FIRST_PRESET_12740') OR "
            . "(MODULE_ID='prospektweb.calc' AND NAME IN ('PRESET_12740_NEUTRAL_INPUT_BACKUP_V1',"
            . "'PRESET_12740_NEUTRAL_INPUT_MIGRATION_V1','PRESET_12740_NEUTRAL_INPUT_ACTIVE',"
            . "'IBLOCK_CALC_DETAILS','IBLOCK_CALC_PRESETS','IBLOCK_CALC_STAGES'))) "
            . "AND (SITE_ID IS NULL OR SITE_ID='') "
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
            || !is_array($backup['state'] ?? null)) {
            throw new \RuntimeException(
                'Preset 12740 is neutral but has no matching verified migration marker and backup.'
            );
        }

        $beforeFingerprint = (string)($marker['beforeFingerprint'] ?? '');
        $backupHash = (string)($marker['backupHash'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $beforeFingerprint) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $backupHash) !== 1
            || !hash_equals($beforeFingerprint, (string)($backup['fingerprint'] ?? ''))
            || !hash_equals($backupHash, hash('sha256', self::encodeCanonical($backup)))) {
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
     * Read the three migration topology authorities without either Bitrix
     * Option's process cache or ConfigManager's process-static cache.
     *
     * @return array<string,mixed>
     */
    private function readConfigSnapshot(bool $forUpdate): array
    {
        $rows = [];
        $result = Application::getConnection()->query(
            "SELECT NAME, VALUE, SITE_ID FROM b_option WHERE MODULE_ID='prospektweb.calc' "
            . "AND NAME IN ('IBLOCK_CALC_DETAILS','IBLOCK_CALC_PRESETS','IBLOCK_CALC_STAGES') "
            . "AND (SITE_ID IS NULL OR SITE_ID='') ORDER BY NAME, SITE_ID"
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
            $name = (string)($row['NAME'] ?? $row['name'] ?? '');
            if (!array_key_exists($name, self::CONFIG_OPTION_TO_STATE_KEY)) {
                throw new \RuntimeException('Unexpected preset 12740 migration config option row.', 409);
            }
            if (array_key_exists($name, $rawByName)) {
                throw new \RuntimeException('Duplicate global preset 12740 migration config option row.', 409);
            }
            $rawByName[$name] = (string)($row['VALUE'] ?? $row['value'] ?? '');
        }

        $snapshot = ['options' => []];
        foreach (self::CONFIG_OPTION_TO_STATE_KEY as $name => $stateKey) {
            if (!array_key_exists($name, $rawByName)) {
                throw new \RuntimeException('Preset 12740 migration iblock topology is incomplete.', 409);
            }
            $raw = $rawByName[$name];
            $normalized = trim($raw);
            $iblockId = (int)$normalized;
            if (preg_match('/^[1-9][0-9]*$/D', $normalized) !== 1
                || $iblockId <= 0
                || (string)$iblockId !== $normalized) {
                throw new \RuntimeException('Preset 12740 migration iblock topology is invalid.', 409);
            }
            $snapshot['options'][$name] = $raw;
            $snapshot[$stateKey] = $iblockId;
        }
        ksort($snapshot['options'], SORT_STRING);
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

    /** Read exactly one global row without Bitrix Option's process cache. */
    private function readOptionRaw(string $moduleId, string $name, bool $forUpdate): string
    {
        $escape = static fn(string $value): string => str_replace("'", "''", $value);
        $result = Application::getConnection()->query(
            "SELECT VALUE FROM b_option WHERE MODULE_ID='" . $escape($moduleId)
             . "' AND NAME='" . $escape($name) . "' AND (SITE_ID IS NULL OR SITE_ID='')"
             . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $row = is_object($result) && method_exists($result, 'fetch') ? $result->fetch() : null;
        $duplicate = is_object($result) && method_exists($result, 'fetch') ? $result->fetch() : null;
        if (is_array($duplicate)) {
            throw new \RuntimeException('Duplicate global preset 12740 migration option row.', 409);
        }
        return is_array($row) ? (string)($row['VALUE'] ?? $row['value'] ?? '') : '';
    }

    private function setGlobalOption(string $name, string $value): void
    {
        Option::set(self::MODULE_ID, $name, $value);
        $readBack = $this->readOptionRaw(self::MODULE_ID, $name, true);
        if (!hash_equals(hash('sha256', $value), hash('sha256', $readBack))) {
            throw new \RuntimeException('Unable to verify the global preset 12740 migration option.');
        }
    }

    private function deleteGlobalOption(string $moduleId, string $name): void
    {
        $escape = static fn(string $value): string => str_replace("'", "''", $value);
        Application::getConnection()->queryExecute(
            "DELETE FROM b_option WHERE MODULE_ID='" . $escape($moduleId)
            . "' AND NAME='" . $escape($name) . "' AND (SITE_ID IS NULL OR SITE_ID='')"
        );
        if ($this->readOptionRaw($moduleId, $name, true) !== '') {
            throw new \RuntimeException('Unable to delete the global preset 12740 migration option.');
        }
    }

    /** @param array<string,mixed> $backup */
    private function storeBackup(array $backup): void
    {
        $encoded = self::encodeCanonical($backup);
        $existing = $this->readOptionRaw(self::MODULE_ID, self::BACKUP_OPTION, true);
        if ($existing !== '') {
            $decoded = json_decode($existing, true);
            if (!is_array($decoded)
                || (string)($decoded['fingerprint'] ?? '') !== (string)$backup['fingerprint']) {
                throw new \RuntimeException('A different preset 12740 neutral-input backup already exists.');
            }
            return;
        }
        $this->setGlobalOption(self::BACKUP_OPTION, $encoded);
        $readBack = $this->readOptionRaw(self::MODULE_ID, self::BACKUP_OPTION, true);
        if (!hash_equals(hash('sha256', $encoded), hash('sha256', $readBack))) {
            throw new \RuntimeException('Unable to verify the preset 12740 neutral-input backup.');
        }
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
