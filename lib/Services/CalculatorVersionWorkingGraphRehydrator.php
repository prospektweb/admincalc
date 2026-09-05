<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

/**
 * Restore an exact saved version-logic document into a freshly deep-cloned
 * physical working graph.
 *
 * The caller owns the surrounding version/global-authority transaction. This
 * service deliberately does not create or delete a preset: it only rewrites
 * the already-created clone, materializes version-owned global symbols and
 * proves the resulting capture before it may be saved into the version bundle.
 */
final class CalculatorVersionWorkingGraphRehydrator
{
    public const CONTRACT = 'prospektweb.calc.version-working-graph-rehydration/v1';

    private const REQUIRED_IBLOCKS = [
        'preset' => 'CALC_PRESETS',
        'detail' => 'CALC_DETAILS',
        'stage' => 'CALC_STAGES',
        'settings' => 'CALC_SETTINGS',
    ];

    /** @var array<string,callable> */
    private array $adapters;

    /** @param array<string,callable> $adapters */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /**
     * @param array<string,mixed> $historicalLogic
     * @param array<string,int> $pinnedIblockIds
     * @return array<string,mixed>
     */
    public function rehydrateLocked(
        int $workingPresetId,
        int $calculatorPresetId,
        string $versionId,
        array $historicalLogic,
        CalculatorMutationAuthorityService $authority,
        array $pinnedIblockIds
    ): array {
        if ($workingPresetId <= 0 || $calculatorPresetId <= 0 || $workingPresetId === $calculatorPresetId) {
            throw new \InvalidArgumentException('Working and calculator preset identities are invalid.', 422);
        }
        if (preg_match('/^v_[a-f0-9]{16,40}$/D', $versionId) !== 1) {
            throw new \InvalidArgumentException('Calculator version identity is invalid.', 422);
        }
        foreach (self::REQUIRED_IBLOCKS as $iblockCode) {
            if ((int)($pinnedIblockIds[$iblockCode] ?? 0) <= 0) {
                throw new \RuntimeException('Pinned working-graph authority is incomplete.', 409);
            }
        }
        if ((int)($pinnedIblockIds['CALC_GLOBAL_VALUES'] ?? 0) <= 0) {
            throw new \RuntimeException('Pinned global-symbol authority is incomplete.', 409);
        }

        CalculatorVersionComponentDocumentService::validateLogicDocument(
            $historicalLogic,
            $calculatorPresetId
        );
        $workingLogic = $this->capturePhysical($workingPresetId);
        CalculatorVersionComponentDocumentService::validateLogicDocument($workingLogic, $workingPresetId);
        $plan = self::plan($historicalLogic, $workingLogic, $workingPresetId);

        foreach ($plan['mutations'] as $mutation) {
            $this->writeMutation($mutation, $pinnedIblockIds);
        }

        $currentGlobals = self::globalSemantics(
            is_array($workingLogic['runtimePayload']['globalSymbols'] ?? null)
                ? $workingLogic['runtimePayload']['globalSymbols']
                : []
        );
        $desiredGlobals = $plan['globals'];
        $globalsChanged = false;
        if ($currentGlobals !== $desiredGlobals) {
            if ($currentGlobals !== []) {
                throw new \RuntimeException(
                    'Fresh working graph already owns incompatible global symbols.',
                    409
                );
            }
            $this->materializeGlobals(
                $desiredGlobals,
                $workingPresetId,
                $authority,
                $pinnedIblockIds
            );
            $globalsChanged = $desiredGlobals !== [];
        }

        $readBackPhysical = $this->capturePhysical($workingPresetId);
        CalculatorVersionComponentDocumentService::validateLogicDocument(
            $readBackPhysical,
            $workingPresetId
        );
        self::assertReadBack($plan, $readBackPhysical);
        $logic = self::calculatorEnvelope(
            $readBackPhysical,
            $calculatorPresetId,
            $workingPresetId,
            $versionId
        );
        $logic['runtimePayload'] = self::projectHistoricalRuntime(
            $historicalLogic,
            $logic,
            $plan['maps'],
            $calculatorPresetId,
            $workingPresetId
        );
        CalculatorVersionComponentDocumentService::validateLogicDocument($logic, $calculatorPresetId);

        return [
            'contract' => self::CONTRACT,
            'calculatorPresetId' => $calculatorPresetId,
            'workingPresetId' => $workingPresetId,
            'versionId' => $versionId,
            'maps' => $plan['maps'],
            'mutationCount' => count($plan['mutations']),
            'globalSymbolCount' => count($desiredGlobals),
            'globalsChanged' => $globalsChanged,
            'logic' => $logic,
        ];
    }

    /**
     * Pure planner used by executable regression tests and by the locked writer.
     *
     * @param array<string,mixed> $historicalLogic
     * @param array<string,mixed> $workingLogic
     * @return array<string,mixed>
     */
    public static function plan(
        array $historicalLogic,
        array $workingLogic,
        int $workingPresetId
    ): array {
        $historicalGraph = self::graph($historicalLogic, 'Historical');
        $workingGraph = self::graph($workingLogic, 'Working');
        if ((int)$workingGraph['presetId'] !== $workingPresetId) {
            throw new \RuntimeException('Working capture belongs to another preset.', 409);
        }

        $historicalRows = self::structuralRows($historicalLogic, $historicalGraph, 'Historical');
        $workingRows = self::structuralRows($workingLogic, $workingGraph, 'Working');
        $maps = self::topologyMaps(
            $historicalGraph,
            $workingGraph,
            $historicalRows,
            $workingRows
        );
        $mutations = [];
        foreach (['settings', 'stage', 'detail', 'preset'] as $kind) {
            foreach ($maps[$kind] as $oldId => $newId) {
                $historicalRow = $historicalRows[$kind][$oldId] ?? null;
                $workingRow = $workingRows[$kind][$newId] ?? null;
                if (!is_array($historicalRow) || !is_array($workingRow)) {
                    throw new \RuntimeException('Version graph row mapping is incomplete.', 409);
                }
                if ($kind === 'preset'
                    && self::canonical(self::presetCatalogInvariant($historicalRow))
                        !== self::canonical(self::presetCatalogInvariant($workingRow))) {
                    throw new \RuntimeException(
                        'Saved preset catalog semantics differ from the current calculator; exact recovery is unavailable.',
                        409
                    );
                }
                $mutations[] = [
                    'kind' => $kind,
                    'sourceId' => (int)$oldId,
                    'targetId' => (int)$newId,
                    'fields' => self::savedFields($historicalRow),
                    'properties' => self::remapProperties(
                        is_array($historicalRow['properties'] ?? null)
                            ? $historicalRow['properties']
                            : [],
                        $maps
                    ),
                    'derived' => array_key_exists('customFields', $historicalRow)
                        ? ['customFields' => $historicalRow['customFields']]
                        : [],
                    'catalog' => $kind === 'preset'
                        ? self::presetCatalogSemantics($historicalRow)
                        : [],
                ];
            }
        }

        $globals = self::globalSemantics(
            is_array($historicalLogic['runtimePayload']['globalSymbols'] ?? null)
                ? $historicalLogic['runtimePayload']['globalSymbols']
                : []
        );

        return [
            'maps' => $maps,
            'expectedGraph' => self::remapGraph($historicalGraph, $maps, $workingPresetId),
            'mutations' => $mutations,
            'globals' => $globals,
        ];
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $readBack */
    public static function assertReadBack(array $plan, array $readBack): void
    {
        $actualGraph = self::graph($readBack, 'Read-back');
        if (self::canonical(self::graphWithoutRevision($actualGraph))
            !== self::canonical(self::graphWithoutRevision($plan['expectedGraph'] ?? []))) {
            throw new \RuntimeException('Rehydrated working graph topology read-back mismatch.', 409);
        }

        $rows = self::structuralRows($readBack, $actualGraph, 'Read-back');
        foreach ((array)($plan['mutations'] ?? []) as $mutation) {
            if (!is_array($mutation)) {
                throw new \RuntimeException('Rehydration plan contains an invalid mutation.', 409);
            }
            $kind = (string)($mutation['kind'] ?? '');
            $targetId = (int)($mutation['targetId'] ?? 0);
            $row = $rows[$kind][$targetId] ?? null;
            if (!is_array($row)) {
                throw new \RuntimeException('Rehydrated working element is absent from read-back.', 409);
            }
            if (self::canonical(self::savedFields($row))
                !== self::canonical(is_array($mutation['fields'] ?? null) ? $mutation['fields'] : [])) {
                throw new \RuntimeException('Rehydrated working element fields differ from the saved version.', 409);
            }
            $expectedProperties = self::propertySetSemantics(
                is_array($mutation['properties'] ?? null) ? $mutation['properties'] : []
            );
            $actualProperties = self::propertySetSemantics(
                is_array($row['properties'] ?? null) ? $row['properties'] : []
            );
            if (self::canonical($actualProperties) !== self::canonical($expectedProperties)) {
                throw new \RuntimeException(
                    'Rehydrated working element properties differ from the saved version.',
                    409
                );
            }
            $derived = is_array($mutation['derived'] ?? null) ? $mutation['derived'] : [];
            if (array_key_exists('customFields', $derived)
                && self::canonical(self::normalizeNumericRepresentations($row['customFields'] ?? null))
                    !== self::canonical(self::normalizeNumericRepresentations($derived['customFields']))) {
                throw new \RuntimeException(
                    'Rehydrated stage custom-field snapshot differs from the saved version.',
                    409
                );
            }
            $expectedCatalog = is_array($mutation['catalog'] ?? null)
                ? $mutation['catalog']
                : [];
            if ($kind === 'preset'
                && self::canonical(self::presetCatalogSemantics($row))
                    !== self::canonical($expectedCatalog)) {
                throw new \RuntimeException(
                    'Rehydrated preset catalog semantics differ from the saved version.',
                    409
                );
            }
        }

        $actualGlobals = self::globalSemantics(
            is_array($readBack['runtimePayload']['globalSymbols'] ?? null)
                ? $readBack['runtimePayload']['globalSymbols']
                : []
        );
        if (self::canonical($actualGlobals)
            !== self::canonical(is_array($plan['globals'] ?? null) ? $plan['globals'] : [])) {
            throw new \RuntimeException('Rehydrated global-symbol registry read-back mismatch.', 409);
        }
    }

    /** @return array<string,mixed> */
    private function capturePhysical(int $workingPresetId): array
    {
        $logic = isset($this->adapters['capture'])
            ? call_user_func($this->adapters['capture'], $workingPresetId)
            : (new CalculatorVersionSnapshotSourceService())->captureLogic($workingPresetId);
        if (!is_array($logic)) {
            throw new \RuntimeException('Working graph capture adapter returned invalid data.', 409);
        }
        return $logic;
    }

    /** @param array<string,mixed> $mutation @param array<string,int> $pinnedIblockIds */
    private function writeMutation(array $mutation, array $pinnedIblockIds): void
    {
        if (isset($this->adapters['write_element'])) {
            call_user_func($this->adapters['write_element'], $mutation, $pinnedIblockIds);
            return;
        }

        $kind = (string)($mutation['kind'] ?? '');
        $targetId = (int)($mutation['targetId'] ?? 0);
        $iblockCode = self::REQUIRED_IBLOCKS[$kind] ?? null;
        $iblockId = is_string($iblockCode) ? (int)($pinnedIblockIds[$iblockCode] ?? 0) : 0;
        if ($targetId <= 0 || $iblockId <= 0) {
            throw new \RuntimeException('Working element mutation target is invalid.', 409);
        }
        $row = \CIBlockElement::GetList(
            [],
            ['ID' => $targetId, 'IBLOCK_ID' => $iblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID']
        )->Fetch();
        if (!is_array($row)) {
            throw new \RuntimeException('Working element mutation target was not found.', 409);
        }
        $fields = is_array($mutation['fields'] ?? null) ? $mutation['fields'] : [];
        if ($fields !== [] && !(new \CIBlockElement())->Update($targetId, $fields)) {
            throw new \RuntimeException('Failed to restore saved working element fields.', 409);
        }
        foreach ((array)($mutation['properties'] ?? []) as $code => $property) {
            if (!is_string($code) || $code === '' || !is_array($property)) {
                throw new \RuntimeException('Saved working property payload is invalid.', 409);
            }
            $schema = \CIBlockProperty::GetList(
                [],
                ['IBLOCK_ID' => $iblockId, 'CODE' => $code]
            )->Fetch();
            if (!is_array($schema)
                || (string)($schema['PROPERTY_TYPE'] ?? '') !== (string)($property['PROPERTY_TYPE'] ?? '')
                || (string)($schema['MULTIPLE'] ?? 'N') !== (string)($property['MULTIPLE'] ?? 'N')
                || (string)($schema['USER_TYPE'] ?? '') !== (string)($property['USER_TYPE'] ?? '')) {
                throw new \RuntimeException('Working property schema differs from the saved version: ' . $code, 409);
            }
            $prepared = $this->propertyWriteValue($property, $iblockId, $code);
            if ((string)($property['MULTIPLE'] ?? 'N') === 'Y') {
                \CIBlockElement::SetPropertyValuesEx($targetId, $iblockId, [$code => false]);
                if ($prepared !== false && $prepared !== []) {
                    \CIBlockElement::SetPropertyValuesEx($targetId, $iblockId, [$code => $prepared]);
                }
            } else {
                \CIBlockElement::SetPropertyValuesEx($targetId, $iblockId, [
                    $code => $this->normalizeSinglePropertyWriteValue($property, $prepared),
                ]);
            }
        }
        if ($kind === 'preset') {
            $this->writePresetCatalogSemantics(
                $targetId,
                is_array($mutation['catalog'] ?? null) ? $mutation['catalog'] : []
            );
        }
    }

    /** @param array<string,mixed> $catalog */
    private function writePresetCatalogSemantics(int $presetId, array $catalog): void
    {
        if ($presetId <= 0 || array_keys($catalog) !== ['measureRatio']) {
            throw new \RuntimeException('Saved preset catalog semantics are invalid.', 409);
        }
        if (!class_exists('\\CCatalogMeasureRatio')) {
            throw new \RuntimeException('Catalog measure-ratio authority is unavailable.', 409);
        }
        $ratio = $catalog['measureRatio'];
        $rows = [];
        $cursor = \CCatalogMeasureRatio::getList(['ID' => 'ASC'], ['PRODUCT_ID' => $presetId]);
        while ($row = $cursor->Fetch()) {
            $rowId = (int)($row['ID'] ?? 0);
            if ($rowId <= 0) {
                throw new \RuntimeException('Catalog measure-ratio row is invalid.', 409);
            }
            $rows[] = $rowId;
        }
        if ($ratio === null) {
            foreach ($rows as $rowId) {
                $this->assertCatalogMutationResult(
                    \CCatalogMeasureRatio::delete($rowId),
                    'Unable to delete inherited preset measure ratio.'
                );
            }
        } else {
            $ratio = (float)$ratio;
            if ($rows === []) {
                $this->assertCatalogMutationResult(
                    \CCatalogMeasureRatio::add(['PRODUCT_ID' => $presetId, 'RATIO' => $ratio]),
                    'Unable to create saved preset measure ratio.'
                );
            } else {
                $primaryId = array_shift($rows);
                $this->assertCatalogMutationResult(
                    \CCatalogMeasureRatio::update($primaryId, ['RATIO' => $ratio]),
                    'Unable to update saved preset measure ratio.'
                );
                foreach ($rows as $rowId) {
                    $this->assertCatalogMutationResult(
                        \CCatalogMeasureRatio::delete($rowId),
                        'Unable to remove duplicate preset measure ratio.'
                    );
                }
            }
        }

        $readBack = [];
        $cursor = \CCatalogMeasureRatio::getList(['ID' => 'ASC'], ['PRODUCT_ID' => $presetId]);
        while ($row = $cursor->Fetch()) {
            $readBack[] = isset($row['RATIO']) ? (float)$row['RATIO'] : null;
        }
        $expected = $ratio === null ? [] : [(float)$ratio];
        if ($readBack !== $expected) {
            throw new \RuntimeException('Preset measure-ratio write-back mismatch.', 409);
        }
    }

    /** @param mixed $result */
    private function assertCatalogMutationResult($result, string $message): void
    {
        if ($result === false
            || (is_object($result) && method_exists($result, 'isSuccess') && !$result->isSuccess())) {
            throw new \RuntimeException($message, 409);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,int> $pinnedIblockIds
     */
    private function materializeGlobals(
        array $rows,
        int $workingPresetId,
        CalculatorMutationAuthorityService $authority,
        array $pinnedIblockIds
    ): void {
        if ($rows === []) {
            return;
        }
        if (isset($this->adapters['write_globals'])) {
            call_user_func(
                $this->adapters['write_globals'],
                $rows,
                $workingPresetId,
                $authority,
                $pinnedIblockIds
            );
            return;
        }
        (new GlobalSymbolService())->saveLocked(
            $rows,
            $workingPresetId,
            $authority,
            $pinnedIblockIds
        );
    }

    /** @param array<string,mixed> $property @return mixed */
    private function propertyWriteValue(array $property, int $iblockId, string $code)
    {
        $multiple = (string)($property['MULTIPLE'] ?? 'N') === 'Y';
        $withDescription = (string)($property['WITH_DESCRIPTION'] ?? 'N') === 'Y';
        $type = (string)($property['PROPERTY_TYPE'] ?? '');
        $raw = array_key_exists('~VALUE', $property) ? $property['~VALUE'] : ($property['VALUE'] ?? null);
        $values = $multiple ? self::listValue($raw) : [$raw];
        if ($type === 'L') {
            $xmlIds = $multiple
                ? self::listValue($property['VALUE_XML_ID'] ?? [])
                : [$property['VALUE_XML_ID'] ?? null];
            $values = [];
            foreach ($xmlIds as $xmlId) {
                $xmlId = trim((string)$xmlId);
                if ($xmlId === '') {
                    $values[] = false;
                    continue;
                }
                $enum = \CIBlockPropertyEnum::GetList(
                    ['SORT' => 'ASC', 'ID' => 'ASC'],
                    ['IBLOCK_ID' => $iblockId, 'CODE' => $code, 'XML_ID' => $xmlId]
                )->Fetch();
                $enumId = (int)($enum['ID'] ?? 0);
                if ($enumId <= 0) {
                    throw new \RuntimeException('Saved enum value is absent: ' . $code . '/' . $xmlId, 409);
                }
                $values[] = $enumId;
            }
        }
        $descriptions = $multiple
            ? self::listValue($property['DESCRIPTION'] ?? [])
            : [$property['DESCRIPTION'] ?? ''];
        $prepared = [];
        foreach ($values as $index => $value) {
            if ($type === 'S' && (string)($property['USER_TYPE'] ?? '') === 'HTML') {
                $html = is_array($value) ? $value : ['TEXT' => (string)($value ?? ''), 'TYPE' => 'HTML'];
                $value = [
                    'TEXT' => (string)($html['TEXT'] ?? ''),
                    'TYPE' => (string)($html['TYPE'] ?? 'HTML'),
                ];
            }
            $prepared[] = $withDescription
                ? ['VALUE' => $value, 'DESCRIPTION' => (string)($descriptions[$index] ?? '')]
                : $value;
        }
        if ($multiple) {
            return $prepared === [] ? false : $prepared;
        }
        return $prepared[0] ?? false;
    }

    /** @param mixed $prepared @return mixed */
    private function normalizeSinglePropertyWriteValue(array $property, $prepared)
    {
        if ((string)($property['PROPERTY_TYPE'] ?? '') !== 'S'
            || (string)($property['USER_TYPE'] ?? '') !== 'HTML') {
            return $prepared;
        }
        $value = (string)($property['WITH_DESCRIPTION'] ?? 'N') === 'Y'
            && is_array($prepared)
            ? ($prepared['VALUE'] ?? null)
            : $prepared;
        if (is_array($value)
            && array_key_exists('TEXT', $value)
            && (string)$value['TEXT'] === '') {
            return false;
        }
        return $prepared;
    }

    /** @param array<string,mixed> $logic @return array<string,mixed> */
    private static function graph(array $logic, string $label): array
    {
        $graph = is_array($logic['graph'] ?? null) ? $logic['graph'] : null;
        if ($graph === null || (int)($graph['presetId'] ?? 0) <= 0) {
            throw new \RuntimeException($label . ' logic graph is missing.', 409);
        }
        foreach (['rootDetailIds', 'detailIds', 'stageIds', 'settingsIds'] as $key) {
            $graph[$key] = self::positiveIds($graph[$key] ?? null, $label . ' ' . $key);
        }
        foreach (['detailChildren', 'detailStages', 'stageSettings'] as $key) {
            if (!is_array($graph[$key] ?? null)) {
                throw new \RuntimeException($label . ' graph adjacency is invalid: ' . $key, 409);
            }
            $normalized = [];
            foreach ($graph[$key] as $ownerId => $ids) {
                $ownerId = (int)$ownerId;
                if ($ownerId <= 0) {
                    throw new \RuntimeException($label . ' graph adjacency owner is invalid.', 409);
                }
                $normalized[$ownerId] = self::positiveIds($ids, $label . ' ' . $key);
            }
            $graph[$key] = $normalized;
        }
        $stageLinkedSettings = [];
        foreach ($graph['stageSettings'] as $settingsIds) {
            foreach ($settingsIds as $settingsId) {
                $stageLinkedSettings[(int)$settingsId] = true;
            }
        }
        // CALC_PRESETS.CALC_SETTINGS is historically a complete search index,
        // not proof of direct ownership. Old bundles therefore derive the
        // direct-only subset from graph topology.
        $graph['directSettingsIds'] = array_key_exists('directSettingsIds', $graph)
            ? self::positiveIds($graph['directSettingsIds'], $label . ' directSettingsIds')
            : array_values(array_filter(
                $graph['settingsIds'],
                static fn(int $settingsId): bool => !isset($stageLinkedSettings[$settingsId])
            ));
        return $graph;
    }

    /** @return array<string,array<int,int>> */
    private static function topologyMaps(
        array $historical,
        array $working,
        array $historicalRows,
        array $workingRows
    ): array
    {
        $maps = [
            'preset' => [(int)$historical['presetId'] => (int)$working['presetId']],
            'detail' => [],
            'stage' => [],
            'settings' => [],
        ];
        $reverse = ['detail' => [], 'stage' => [], 'settings' => []];
        $mapOne = static function (string $kind, int $oldId, int $newId) use (&$maps, &$reverse): void {
            if ($oldId <= 0 || $newId <= 0
                || (isset($maps[$kind][$oldId]) && $maps[$kind][$oldId] !== $newId)
                || (isset($reverse[$kind][$newId]) && $reverse[$kind][$newId] !== $oldId)) {
                throw new \RuntimeException('Historical graph cannot be mapped one-to-one: ' . $kind, 409);
            }
            $maps[$kind][$oldId] = $newId;
            $reverse[$kind][$newId] = $oldId;
        };

        $pairNamed = static function (
            string $kind,
            array $oldIds,
            array $newIds
        ) use ($mapOne, $historicalRows, $workingRows): void {
            if (count($oldIds) !== count($newIds)) {
                throw new \RuntimeException('Historical and working ' . $kind . ' topology differs.', 409);
            }
            $buckets = [];
            foreach ($newIds as $newId) {
                $newId = (int)$newId;
                $row = $workingRows[$kind][$newId] ?? null;
                if (!is_array($row)) {
                    throw new \RuntimeException('Working named topology row is absent: ' . $kind, 409);
                }
                $buckets[(string)($row['name'] ?? '')][] = $newId;
            }
            foreach ($oldIds as $oldId) {
                $oldId = (int)$oldId;
                $row = $historicalRows[$kind][$oldId] ?? null;
                $name = is_array($row) ? (string)($row['name'] ?? '') : '';
                if (!is_array($row) || ($buckets[$name] ?? []) === []) {
                    throw new \RuntimeException(
                        'Historical and working ' . $kind . ' names/occurrences differ.',
                        409
                    );
                }
                $mapOne($kind, $oldId, (int)array_shift($buckets[$name]));
            }
            foreach ($buckets as $remaining) {
                if ($remaining !== []) {
                    throw new \RuntimeException(
                        'Historical and working ' . $kind . ' names/occurrences differ.',
                        409
                    );
                }
            }
        };

        $historicalRootIds = self::orderedStructuralPropertyIds(
            $historicalRows['preset'][(int)$historical['presetId']],
            'CALC_DETAILS',
            $historical['rootDetailIds'],
            'historical root details'
        );
        $workingRootIds = self::orderedStructuralPropertyIds(
            $workingRows['preset'][(int)$working['presetId']],
            'CALC_DETAILS',
            $working['rootDetailIds'],
            'working root details'
        );
        $pairNamed('detail', $historicalRootIds, $workingRootIds);

        $visitedDetails = [];
        $walk = null;
        $walk = static function (int $oldId, int $newId) use (
            &$walk,
            &$visitedDetails,
            &$maps,
            $historical,
            $working,
            $historicalRows,
            $workingRows,
            $mapOne,
            $pairNamed
        ): void {
            if (isset($visitedDetails[$oldId])) {
                throw new \RuntimeException(
                    'Historical graph contains a duplicate or cyclic detail reference.',
                    409
                );
            }
            $visitedDetails[$oldId] = true;
            $mapOne('detail', $oldId, $newId);
            $oldChildren = self::orderedStructuralPropertyIds(
                $historicalRows['detail'][$oldId],
                'DETAILS',
                $historical['detailChildren'][$oldId] ?? [],
                'historical detail children'
            );
            $newChildren = self::orderedStructuralPropertyIds(
                $workingRows['detail'][$newId],
                'DETAILS',
                $working['detailChildren'][$newId] ?? [],
                'working detail children'
            );
            $oldStages = self::orderedStructuralPropertyIds(
                $historicalRows['detail'][$oldId],
                'CALC_STAGES',
                $historical['detailStages'][$oldId] ?? [],
                'historical detail stages'
            );
            $newStages = self::orderedStructuralPropertyIds(
                $workingRows['detail'][$newId],
                'CALC_STAGES',
                $working['detailStages'][$newId] ?? [],
                'working detail stages'
            );
            $pairNamed('stage', $oldStages, $newStages);
            $pairNamed('detail', $oldChildren, $newChildren);
            foreach ($oldChildren as $index => $oldChildId) {
                $walk((int)$oldChildId, (int)$maps['detail'][(int)$oldChildId]);
            }
        };
        foreach ($historicalRootIds as $oldRootId) {
            $walk((int)$oldRootId, (int)$maps['detail'][(int)$oldRootId]);
        }
        if (count($maps['detail']) !== count($historical['detailIds'])
            || count($maps['detail']) !== count($working['detailIds'])) {
            throw new \RuntimeException('Historical graph contains orphan or duplicate details.', 409);
        }

        $oldDirectStages = array_values(array_diff($historical['stageIds'], array_keys($maps['stage'])));
        $newDirectStages = array_values(array_diff($working['stageIds'], array_values($maps['stage'])));
        $oldPresetStageOrder = self::orderedStructuralPropertyIdsSubset(
            $historicalRows['preset'][(int)$historical['presetId']],
            'CALC_STAGES',
            $oldDirectStages,
            $historical['stageIds'],
            'historical preset stages'
        );
        $newPresetStageOrder = self::orderedStructuralPropertyIdsSubset(
            $workingRows['preset'][(int)$working['presetId']],
            'CALC_STAGES',
            $newDirectStages,
            $working['stageIds'],
            'working preset stages'
        );
        $pairNamed('stage', $oldPresetStageOrder, $newPresetStageOrder);
        if (count($maps['stage']) !== count($historical['stageIds'])
            || count($maps['stage']) !== count($working['stageIds'])) {
            throw new \RuntimeException('Historical graph contains orphan or duplicate stages.', 409);
        }

        foreach ($maps['stage'] as $oldStageId => $newStageId) {
            $oldSettings = self::orderedStructuralPropertyIds(
                $historicalRows['stage'][$oldStageId],
                'CALC_SETTINGS',
                $historical['stageSettings'][$oldStageId] ?? [],
                'historical stage settings'
            );
            $newSettings = self::orderedStructuralPropertyIds(
                $workingRows['stage'][$newStageId],
                'CALC_SETTINGS',
                $working['stageSettings'][$newStageId] ?? [],
                'working stage settings'
            );
            if (count($oldSettings) !== count($newSettings)) {
                throw new \RuntimeException('Historical and working stage/settings topology differs.', 409);
            }
            foreach ($oldSettings as $index => $oldSettingsId) {
                $mapOne('settings', (int)$oldSettingsId, (int)$newSettings[$index]);
            }
        }
        $historicalDirectSettings = self::orderedStructuralPropertyIdsSubset(
            $historicalRows['preset'][(int)$historical['presetId']],
            'CALC_SETTINGS',
            $historical['directSettingsIds'],
            $historical['settingsIds'],
            'historical preset settings'
        );
        $workingDirectSettings = self::orderedStructuralPropertyIdsSubset(
            $workingRows['preset'][(int)$working['presetId']],
            'CALC_SETTINGS',
            $working['directSettingsIds'],
            $working['settingsIds'],
            'working preset settings'
        );
        if (count($historicalDirectSettings) !== count($workingDirectSettings)) {
            throw new \RuntimeException(
                'Historical and working preset/settings topology differs.',
                409
            );
        }
        foreach ($historicalDirectSettings as $index => $oldSettingsId) {
            $mapOne(
                'settings',
                (int)$oldSettingsId,
                (int)$workingDirectSettings[$index]
            );
        }
        if (count($maps['settings']) !== count($historical['settingsIds'])
            || count($maps['settings']) !== count($working['settingsIds'])) {
            throw new \RuntimeException('Historical graph contains orphan or incompatible settings.', 409);
        }
        return $maps;
    }

    /** @return int[] */
    private static function orderedStructuralPropertyIds(
        array $row,
        string $propertyCode,
        array $expectedIds,
        string $context
    ): array {
        $ids = self::structuralPropertyIds($row, $propertyCode, $context);
        self::assertSameIdSet($ids, $expectedIds, $context);
        return $ids;
    }

    /** @return int[] */
    private static function orderedStructuralPropertyIdsSubset(
        array $row,
        string $propertyCode,
        array $requiredSubset,
        array $allowedIds,
        string $context
    ): array {
        $ids = self::structuralPropertyIds($row, $propertyCode, $context);
        $allowed = array_fill_keys(array_map('intval', $allowedIds), true);
        foreach ($ids as $id) {
            if (!isset($allowed[$id])) {
                throw new \RuntimeException($context . ' references an entity outside its graph.', 409);
            }
        }
        $required = array_fill_keys(array_map('intval', $requiredSubset), true);
        $ordered = array_values(array_filter(
            $ids,
            static fn(int $id): bool => isset($required[$id])
        ));
        self::assertSameIdSet($ordered, $requiredSubset, $context . ' direct subset');
        return $ordered;
    }

    /** @return int[] */
    private static function structuralPropertyIds(
        array $row,
        string $propertyCode,
        string $context
    ): array {
        $property = $row['properties'][$propertyCode] ?? null;
        if (!is_array($property)) {
            return [];
        }
        $raw = array_key_exists('~VALUE', $property)
            ? $property['~VALUE']
            : ($property['VALUE'] ?? null);
        $values = is_array($raw) && array_is_list($raw) ? $raw : [$raw];
        $ids = [];
        $seen = [];
        foreach ($values as $value) {
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            $id = (int)$value;
            if ($id <= 0 || isset($seen[$id])) {
                throw new \RuntimeException($context . ' contains an invalid or duplicate ID.', 409);
            }
            $seen[$id] = true;
            $ids[] = $id;
        }
        return $ids;
    }

    private static function assertSameIdSet(array $actual, array $expected, string $context): void
    {
        $actual = array_values(array_unique(array_map('intval', $actual)));
        $expected = array_values(array_unique(array_map('intval', $expected)));
        sort($actual, SORT_NUMERIC);
        sort($expected, SORT_NUMERIC);
        if ($actual !== $expected) {
            throw new \RuntimeException($context . ' differs from the locked graph.', 409);
        }
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private static function structuralRows(array $logic, array $graph, string $label): array
    {
        $sets = [
            'preset' => [(int)$graph['presetId'] => true],
            'detail' => array_fill_keys($graph['detailIds'], true),
            'stage' => array_fill_keys($graph['stageIds'], true),
            'settings' => array_fill_keys($graph['settingsIds'], true),
        ];
        $rows = ['preset' => [], 'detail' => [], 'stage' => [], 'settings' => []];
        foreach ((array)($logic['elements'] ?? []) as $batch) {
            foreach ((array)(is_array($batch) ? ($batch['data'] ?? []) : []) as $row) {
                if (!is_array($row)) {
                    throw new \RuntimeException($label . ' logic contains an invalid element row.', 409);
                }
                $id = (int)($row['id'] ?? 0);
                $kind = null;
                foreach ($sets as $candidate => $ids) {
                    if (isset($ids[$id])) {
                        if ($kind !== null) {
                            throw new \RuntimeException($label . ' structural identity is ambiguous.', 409);
                        }
                        $kind = $candidate;
                    }
                }
                if ($kind === null) {
                    throw new \RuntimeException($label . ' logic contains a structural row outside its graph.', 409);
                }
                if (isset($rows[$kind][$id])) {
                    throw new \RuntimeException($label . ' logic contains a duplicate structural row.', 409);
                }
                $rows[$kind][$id] = $row;
            }
        }
        foreach ($sets as $kind => $ids) {
            if (count($rows[$kind]) !== count($ids)) {
                throw new \RuntimeException($label . ' logic omits a structural element row.', 409);
            }
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    private static function savedFields(array $row): array
    {
        return [
            'NAME' => (string)($row['name'] ?? ''),
            'PREVIEW_TEXT' => (string)($row['previewText'] ?? ''),
            'DETAIL_TEXT' => (string)($row['detailText'] ?? ''),
        ];
    }

    /** @return array{measureRatio:?float} */
    private static function presetCatalogSemantics(array $row): array
    {
        $raw = $row['measureRatio'] ?? null;
        if ($raw === null || $raw === '') {
            return ['measureRatio' => null];
        }
        if ((!is_int($raw) && !is_float($raw) && !is_string($raw))
            || !is_numeric($raw)
            || !is_finite((float)$raw)
            || (float)$raw <= 0) {
            throw new \RuntimeException('Saved preset measure ratio is invalid.', 409);
        }
        return ['measureRatio' => (float)$raw];
    }

    /** @return array<string,mixed> */
    private static function presetCatalogInvariant(array $row): array
    {
        return self::normalizeNumericRepresentations([
            'measure' => $row['measure'] ?? null,
            'attributes' => $row['attributes'] ?? null,
            'purchasingPrice' => $row['purchasingPrice'] ?? null,
            'purchasingCurrency' => $row['purchasingCurrency'] ?? null,
            'catalog' => $row['catalog'] ?? null,
            'prices' => is_array($row['prices'] ?? null) ? $row['prices'] : [],
        ]);
    }

    /** @return mixed */
    private static function normalizeNumericRepresentations($value)
    {
        if (is_int($value) || is_float($value)) {
            $number = (float)$value;
            if (!is_finite($number)) {
                throw new \RuntimeException('Preset catalog semantics contain a non-finite number.', 409);
            }
            return $number;
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::normalizeNumericRepresentations($child);
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private static function remapProperties(array $properties, array $maps): array
    {
        $result = [];
        foreach ($properties as $code => $property) {
            if (!is_string($code) || $code === '' || !is_array($property)) {
                throw new \RuntimeException('Saved version contains an invalid property payload.', 409);
            }
            // STAGE_GROUPS owns a structured JSON remapper below; applying the
            // generic token pass first would remap its stage_<id> strings twice.
            if ($code !== 'STAGE_GROUPS') {
                $property = self::replaceStageTokensRecursive($property, $maps['stage']);
            }
            $linkMap = match ($code) {
                'CALC_DETAILS', 'DETAILS' => $maps['detail'],
                'CALC_STAGES' => $maps['stage'],
                'CALC_SETTINGS' => $maps['settings'],
                default => null,
            };
            if (is_array($linkMap)) {
                foreach (['VALUE', '~VALUE'] as $valueKey) {
                    if (array_key_exists($valueKey, $property)) {
                        $property[$valueKey] = self::remapLinkValue(
                            $property[$valueKey],
                            $linkMap,
                            $code
                        );
                    }
                }
            }
            if ($code === 'STAGE_GROUPS') {
                foreach (['VALUE', '~VALUE'] as $valueKey) {
                    if (array_key_exists($valueKey, $property)) {
                        $property[$valueKey] = self::remapStageGroupsValue(
                            $property[$valueKey],
                            $maps['stage']
                        );
                    }
                }
            }
            if (in_array($code, ['OPTIONS_OPERATION', 'OPTIONS_MATERIAL', 'OPTIONS_EQUIPMENT', 'OPTIONS_CALCULATOR'], true)) {
                foreach (['VALUE', '~VALUE', 'DESCRIPTION'] as $valueKey) {
                    if (array_key_exists($valueKey, $property)) {
                        $property[$valueKey] = self::remapStageVariantMappingValue(
                            $property[$valueKey],
                            $maps['detail'],
                            $maps['stage'],
                            $maps['settings'],
                            $code
                        );
                    }
                }
            }
            $result[$code] = $property;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @return mixed */
    private static function remapLinkValue($value, array $map, string $code)
    {
        $multiple = is_array($value) && array_is_list($value);
        $values = $multiple ? $value : [$value];
        $result = [];
        foreach ($values as $item) {
            if ($item === null || $item === '' || $item === false) {
                continue;
            }
            $oldId = (int)$item;
            if ($oldId <= 0 || !isset($map[$oldId])) {
                throw new \RuntimeException('Saved internal reference cannot be remapped: ' . $code, 409);
            }
            $result[] = (int)$map[$oldId];
        }
        if ($multiple) {
            return $result;
        }
        return $result[0] ?? null;
    }

    /** @return mixed */
    private static function remapStageGroupsValue($value, array $stageMap)
    {
        if (is_array($value) && array_key_exists('TEXT', $value)) {
            $value['TEXT'] = self::remapStageGroupsJson((string)$value['TEXT'], $stageMap);
            return $value;
        }
        if ($value === null || $value === '') {
            return $value;
        }
        return self::remapStageGroupsJson((string)$value, $stageMap);
    }

    private static function remapStageGroupsJson(string $json, array $stageMap): string
    {
        if (trim($json) === '') {
            return $json;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Saved STAGE_GROUPS JSON is invalid.', 409);
        }
        $walk = null;
        $walk = static function ($node) use (&$walk, $stageMap) {
            if (!is_array($node)) {
                return self::replaceStageTokensRecursive($node, $stageMap);
            }
            foreach ($node as $key => $child) {
                if ($key === 'stageIds') {
                    $node[$key] = self::remapLinkValue($child, $stageMap, 'STAGE_GROUPS.stageIds');
                } else {
                    $node[$key] = $walk($child);
                }
            }
            return $node;
        };
        $encoded = json_encode($walk($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Saved STAGE_GROUPS JSON cannot be encoded.', 409);
        }
        return $encoded;
    }

    /** @return mixed */
    private static function remapStageVariantMappingValue(
        $value,
        array $detailMap,
        array $stageMap,
        array $settingsMap,
        string $propertyCode
    ) {
        if (is_array($value) && array_is_list($value)) {
            return array_map(
                static fn($item) => self::remapStageVariantMappingValue(
                    $item,
                    $detailMap,
                    $stageMap,
                    $settingsMap,
                    $propertyCode
                ),
                $value
            );
        }
        if (is_array($value) && array_key_exists('TEXT', $value)) {
            $value['TEXT'] = self::remapStageVariantMappingJson(
                (string)$value['TEXT'],
                $detailMap,
                $stageMap,
                $settingsMap,
                $propertyCode
            );
            return $value;
        }
        if ($value === null || $value === '' || $value === false) {
            return $value;
        }
        if (!is_string($value)) {
            throw new \RuntimeException(
                'Saved stage variant mapping value is invalid: ' . $propertyCode,
                409
            );
        }
        return self::remapStageVariantMappingJson($value, $detailMap, $stageMap, $settingsMap, $propertyCode);
    }

    private static function remapStageVariantMappingJson(
        string $raw,
        array $detailMap,
        array $stageMap,
        array $settingsMap,
        string $propertyCode
    ): string {
        if ($raw === '') {
            return '';
        }
        try {
            $mappingService = new StageVariantMappingService();
            $decodedRaw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $header = json_decode($decodedRaw, true);
            $canonical = $propertyCode === 'OPTIONS_MATERIAL'
                || in_array(($header['contract'] ?? ''), [
                    StageVariantMappingService::MATERIAL_DECISION_TREE_CONTRACT,
                    StageVariantMappingService::ENTITY_PARAMETER_SELECTION_CONTRACT,
                ], true)
                ? $mappingService->normalizeMaterialJson($decodedRaw)
                : $mappingService->normalizeJson($decodedRaw);
            $data = json_decode($canonical, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            if ($propertyCode === 'OPTIONS_MATERIAL') {
                // Material v1-v3 were deliberately retired. Rehydration must
                // not restore an executable-looking legacy document.
                return '';
            }
            throw new \RuntimeException(
                'Saved stage variant mapping is invalid: ' . $propertyCode,
                409,
                $error
            );
        }
        if (is_array($data['metric_source'] ?? null)) {
            $oldDetailId = (int)($data['metric_source']['detail_id'] ?? 0);
            $oldStageId = (int)($data['metric_source']['stage_id'] ?? 0);
            if ($oldDetailId <= 0 || !isset($detailMap[$oldDetailId])
                || $oldStageId <= 0 || !isset($stageMap[$oldStageId])) {
                throw new \RuntimeException(
                    'Saved stage variant mapping references topology outside the version graph: '
                    . $propertyCode,
                    409
                );
            }
            $data['metric_source']['detail_id'] = (int)$detailMap[$oldDetailId];
            $data['metric_source']['stage_id'] = (int)$stageMap[$oldStageId];
        }
        if ($propertyCode === 'OPTIONS_CALCULATOR'
            && ($data['contract'] ?? '') === StageVariantMappingService::MATERIAL_DECISION_TREE_CONTRACT) {
            $remapTree = function (array $node) use (&$remapTree, $settingsMap): array {
                if (($node['kind'] ?? '') === 'result') {
                    $oldId = (int)($node['result']['entity_id'] ?? 0);
                    if ($oldId <= 0 || !isset($settingsMap[$oldId])) {
                        throw new \RuntimeException('Saved calculator selection tree references settings outside the version graph.', 409);
                    }
                    $node['result']['entity_id'] = (int)$settingsMap[$oldId];
                    return $node;
                }
                foreach ((array)($node['branches'] ?? []) as $index => $branch) {
                    $node['branches'][$index]['child'] = $remapTree((array)($branch['child'] ?? []));
                }
                return $node;
            };
            $data['tree'] = $remapTree((array)($data['tree'] ?? []));
        }
        try {
            return $mappingService->encode($data);
        } catch (\InvalidArgumentException $error) {
            throw new \RuntimeException(
                'Saved stage variant mapping cannot be remapped: ' . $propertyCode,
                409,
                $error
            );
        }
    }

    /** @return mixed */
    private static function replaceStageTokensRecursive($value, array $stageMap)
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = self::replaceStageTokensRecursive($child, $stageMap);
            }
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }
        $replaced = preg_replace_callback(
            '/stage_([1-9][0-9]*)(?![0-9])/',
            static function (array $match) use ($stageMap): string {
                $oldId = (int)$match[1];
                if (!isset($stageMap[$oldId])) {
                    // Field codes and external identifiers may legitimately use
                    // the stage_<number> shape. Only proven graph identities are
                    // rewritten; typed structural references are validated by
                    // their dedicated remappers.
                    return (string)$match[0];
                }
                return 'stage_' . (int)$stageMap[$oldId];
            },
            $value
        );
        if (!is_string($replaced)) {
            throw new \RuntimeException('Saved stage reference cannot be remapped.', 409);
        }
        return $replaced;
    }

    /** @return array<string,mixed> */
    private static function remapGraph(array $graph, array $maps, int $workingPresetId): array
    {
        $mapped = [
            'presetId' => $workingPresetId,
            'rootDetailIds' => self::mapSortedIdList($graph['rootDetailIds'], $maps['detail'], 'root details'),
            'detailIds' => self::mapSortedIdList($graph['detailIds'], $maps['detail'], 'details'),
            'stageIds' => self::mapSortedIdList($graph['stageIds'], $maps['stage'], 'stages'),
            'settingsIds' => self::mapSortedIdList($graph['settingsIds'], $maps['settings'], 'settings'),
            'directSettingsIds' => self::mapSortedIdList(
                $graph['directSettingsIds'],
                $maps['settings'],
                'preset settings'
            ),
            'detailChildren' => [],
            'detailStages' => [],
            'stageSettings' => [],
        ];
        foreach ($graph['detailChildren'] as $oldId => $ids) {
            $mapped['detailChildren'][$maps['detail'][$oldId]] = self::mapSortedIdList(
                $ids,
                $maps['detail'],
                'detail children'
            );
        }
        foreach ($graph['detailStages'] as $oldId => $ids) {
            $mapped['detailStages'][$maps['detail'][$oldId]] = self::mapSortedIdList(
                $ids,
                $maps['stage'],
                'detail stages'
            );
        }
        foreach ($graph['stageSettings'] as $oldId => $ids) {
            $mapped['stageSettings'][$maps['stage'][$oldId]] = self::mapSortedIdList(
                $ids,
                $maps['settings'],
                'stage settings'
            );
        }
        return $mapped;
    }

    /** @return int[] */
    private static function mapIdList(array $ids, array $map, string $context): array
    {
        $result = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if (!isset($map[$id])) {
                throw new \RuntimeException('Saved graph mapping is incomplete: ' . $context, 409);
            }
            $result[] = (int)$map[$id];
        }
        return $result;
    }

    /** @return int[] */
    private static function mapSortedIdList(array $ids, array $map, string $context): array
    {
        $result = self::mapIdList($ids, $map, $context);
        sort($result, SORT_NUMERIC);
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private static function globalSemantics(array $rows): array
    {
        if (!array_is_list($rows) || count($rows) > 500) {
            throw new \RuntimeException('Saved global-symbol registry is invalid.', 409);
        }
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('Saved global symbol must be an object.', 409);
            }
            $code = trim((string)($row['code'] ?? ''));
            $kind = (string)($row['kind'] ?? '');
            $dataType = (string)($row['dataType'] ?? '');
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $code) !== 1
                || isset($seen[strtolower($code)])
                || !in_array($kind, ['constant', 'variable'], true)
                || !in_array($dataType, ['auto', 'string', 'number', 'boolean', 'array', 'object'], true)
                || trim((string)($row['title'] ?? '')) === ''
                || (array_key_exists('active', $row) && (string)$row['active'] !== 'Y')) {
                throw new \RuntimeException('Saved global-symbol registry contains an incompatible row.', 409);
            }
            $seen[strtolower($code)] = true;
            $result[] = [
                'code' => $code,
                'title' => trim((string)$row['title']),
                'description' => trim((string)($row['description'] ?? '')),
                'kind' => $kind,
                'dataType' => $dataType,
                'initialValue' => (string)($row['initialValue'] ?? ''),
            ];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private static function calculatorEnvelope(
        array $logic,
        int $calculatorPresetId,
        int $workingPresetId,
        string $versionId
    ): array {
        $logic['presetId'] = $calculatorPresetId;
        $logic['workingPresetId'] = $workingPresetId;
        $logic['workingVersionId'] = $versionId;
        $logic['runtimePayload']['preset']['id'] = $calculatorPresetId;
        $logic['runtimePayload']['preset']['runtimePresetId'] = $workingPresetId;
        if (!is_array($logic['runtimePayload']['globalSymbols'] ?? null)) {
            throw new \RuntimeException('Rehydrated global-symbol envelope is invalid.', 409);
        }
        foreach ($logic['runtimePayload']['globalSymbols'] as &$symbol) {
            if (!is_array($symbol)) {
                throw new \RuntimeException('Rehydrated global-symbol row is invalid.', 409);
            }
            $symbol['presetId'] = $calculatorPresetId;
        }
        unset($symbol);
        return $logic;
    }

    /**
     * Preserve immutable external catalog/runtime bytes from the saved version
     * while replacing only the physical structural graph projection.
     *
     * @return array<string,mixed>
     */
    private static function projectHistoricalRuntime(
        array $historicalLogic,
        array $readBackLogic,
        array $maps,
        int $calculatorPresetId,
        int $workingPresetId
    ): array {
        $historical = is_array($historicalLogic['runtimePayload'] ?? null)
            ? $historicalLogic['runtimePayload']
            : null;
        $readBack = is_array($readBackLogic['runtimePayload'] ?? null)
            ? $readBackLogic['runtimePayload']
            : null;
        if ($historical === null || $readBack === null) {
            throw new \RuntimeException('Version runtime projection source is missing.', 409);
        }

        $runtime = $historical;
        $historicalStore = is_array($historical['elementsStore'] ?? null)
            ? $historical['elementsStore']
            : [];
        $readBackStore = is_array($readBack['elementsStore'] ?? null)
            ? $readBack['elementsStore']
            : [];
        $runtime['elementsStore'] = $historicalStore;
        foreach (['CALC_PRESETS', 'CALC_DETAILS', 'CALC_STAGES', 'CALC_SETTINGS'] as $code) {
            if (array_key_exists($code, $historicalStore) || array_key_exists($code, $readBackStore)) {
                if (!is_array($readBackStore[$code] ?? null)) {
                    throw new \RuntimeException(
                        'Physical structural runtime projection is incomplete: ' . $code,
                        409
                    );
                }
                $runtime['elementsStore'][$code] = $readBackStore[$code];
            }
        }

        $preset = is_array($historical['preset'] ?? null) ? $historical['preset'] : null;
        if ($preset === null) {
            throw new \RuntimeException('Saved runtime preset projection is missing.', 409);
        }
        $preset['id'] = $calculatorPresetId;
        $preset['runtimePresetId'] = $workingPresetId;
        $preset['properties'] = self::remapRuntimePresetProperties(
            is_array($preset['properties'] ?? null) ? $preset['properties'] : [],
            $maps
        );
        $runtime['preset'] = $preset;
        $runtime['elementsSiblings'] = self::remapElementsSiblingStageIds(
            is_array($historical['elementsSiblings'] ?? null)
                ? $historical['elementsSiblings']
                : [],
            $maps['stage']
        );
        // Storage identities are newly allocated and must come only from the
        // successful physical read-back; semantic fields were already proven.
        $runtime['globalSymbols'] = is_array($readBack['globalSymbols'] ?? null)
            ? $readBack['globalSymbols']
            : [];
        $runtime['selectedOffers'] = [];
        $runtime['product'] = null;
        $runtime['neutralInputRequired'] = true;
        return $runtime;
    }

    /** @return array<string,mixed> */
    private static function remapRuntimePresetProperties(array $properties, array $maps): array
    {
        $result = [];
        foreach ($properties as $code => $property) {
            if (!is_string($code) || $code === '') {
                throw new \RuntimeException('Saved runtime preset property is invalid.', 409);
            }
            if (is_array($property)
                && (array_key_exists('PROPERTY_TYPE', $property)
                    || array_key_exists('MULTIPLE', $property)
                    || array_key_exists('VALUE', $property)
                    || array_key_exists('~VALUE', $property))) {
                $result[$code] = self::remapProperties([$code => $property], $maps)[$code];
                continue;
            }
            $linkMap = match ($code) {
                'CALC_DETAILS', 'DETAILS' => $maps['detail'],
                'CALC_STAGES' => $maps['stage'],
                'CALC_SETTINGS' => $maps['settings'],
                default => null,
            };
            if (is_array($linkMap)) {
                $result[$code] = self::remapLinkValue($property, $linkMap, 'runtime.' . $code);
            } else {
                $result[$code] = self::replaceStageTokensRecursive($property, $maps['stage']);
            }
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @return mixed */
    private static function remapElementsSiblingStageIds($node, array $stageMap)
    {
        if (!is_array($node)) {
            return $node;
        }
        foreach ($node as $key => $value) {
            if ($key === 'stageId' && ($value !== null && $value !== '')) {
                $oldId = (int)$value;
                if ($oldId <= 0 || !isset($stageMap[$oldId])) {
                    throw new \RuntimeException(
                        'Saved elementsSiblings references a stage outside the version graph.',
                        409
                    );
                }
                $node[$key] = (int)$stageMap[$oldId];
            } else {
                $node[$key] = self::remapElementsSiblingStageIds($value, $stageMap);
            }
        }
        return $node;
    }

    /** @return array<string,mixed> */
    private static function propertySetSemantics(array $properties): array
    {
        $result = [];
        foreach ($properties as $code => $property) {
            if (is_string($code) && $code !== '' && is_array($property)) {
                $result[$code] = self::propertySemantics($property);
            }
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @return array<string,mixed> */
    private static function propertySemantics(array $property): array
    {
        $type = (string)($property['PROPERTY_TYPE'] ?? '');
        $multiple = (string)($property['MULTIPLE'] ?? 'N') === 'Y';
        $raw = array_key_exists('~VALUE', $property) ? $property['~VALUE'] : ($property['VALUE'] ?? null);
        if ($type === 'L') {
            $raw = $property['VALUE_XML_ID'] ?? null;
        }
        $values = $multiple ? self::listValue($raw) : [$raw];
        if ($type === 'E') {
            $values = array_values(array_map(static fn($value): int => (int)$value, $values));
        } elseif ($type === 'S' && (string)($property['USER_TYPE'] ?? '') === 'HTML') {
            $values = array_map(static function ($value): array {
                $value = is_array($value) ? $value : ['TEXT' => (string)($value ?? ''), 'TYPE' => 'HTML'];
                $marker = strtoupper((string)($value['TYPE'] ?? 'HTML'));
                return [
                    'TEXT' => (string)($value['TEXT'] ?? ''),
                    'TYPE' => in_array($marker, ['TEXT', 'HTML'], true) ? 'HTML' : $marker,
                ];
            }, $values);
        }
        $descriptions = $multiple
            ? array_map(static fn($value): string => (string)($value ?? ''), self::listValue($property['DESCRIPTION'] ?? []))
            : [(string)($property['DESCRIPTION'] ?? '')];
        return [
            'propertyType' => $type,
            'userType' => (string)($property['USER_TYPE'] ?? ''),
            'multiple' => $multiple,
            'withDescription' => (string)($property['WITH_DESCRIPTION'] ?? 'N') === 'Y',
            'values' => $values,
            'descriptions' => $descriptions,
        ];
    }

    /** @return array<mixed> */
    private static function listValue($value): array
    {
        if ($value === null || $value === false || $value === '') {
            return [];
        }
        return is_array($value) && array_is_list($value) ? array_values($value) : [$value];
    }

    /** @return int[] */
    private static function positiveIds($value, string $context): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException($context . ' must be a list.', 409);
        }
        $result = [];
        $seen = [];
        foreach ($value as $id) {
            $id = (int)$id;
            if ($id <= 0 || isset($seen[$id])) {
                throw new \RuntimeException($context . ' contains an invalid or duplicate ID.', 409);
            }
            $seen[$id] = true;
            $result[] = $id;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private static function graphWithoutRevision(array $graph): array
    {
        unset($graph['revision']);
        return $graph;
    }

    /** @return mixed */
    private static function canonical($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonical'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) {
            $value[$key] = self::canonical($child);
        }
        return $value;
    }
}
