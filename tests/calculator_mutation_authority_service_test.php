<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/CalculatorMutationAuthorityService.php';
require_once dirname(__DIR__) . '/lib/Services/GlobalSymbolService.php';

use Prospektweb\Calc\Services\CalculatorMutationAuthorityService;
use Prospektweb\Calc\Services\GlobalSymbolService;

final class CalculatorMutationAuthorityFakeConnection
{
    /** @var string[] */
    public array $events = [];

    public function startTransaction(): void
    {
        $this->events[] = 'begin';
    }

    public function commitTransaction(): void
    {
        $this->events[] = 'commit';
    }

    public function rollbackTransaction(): void
    {
        $this->events[] = 'rollback';
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$reserved = [
    'if', 'round', 'ceil', 'floor', 'min', 'max', 'abs', 'trim', 'lower', 'upper',
    'len', 'contains', 'replace', 'tonumber', 'tostring', 'split', 'join', 'get',
    'getprice', 'regexmatch', 'regexextract', 'true', 'false', 'null', 'undefined',
    'input', 'offer', 'product', 'calculator', 'operation', 'operationvariant',
    'equipment', 'material', 'materialvariant', 'stage', 'preset', 'selectedoffer',
    'selectedoffers', 'context', 'iblocks', 'elementsstore', 'pricetypes',
    'resources', 'globalsymbols', 'globalvalues', 'current_stage',
    '__proto__', 'prototype', 'constructor',
];

$normalize = new ReflectionMethod(GlobalSymbolService::class, 'normalizeRequestedCode');
$normalize->setAccessible(true);
$globalService = new GlobalSymbolService();
$normalizeManagedBy = new ReflectionMethod(GlobalSymbolService::class, 'normalizeManagedBy');
$normalizeManagedBy->setAccessible(true);
$assert(
    $normalizeManagedBy->invoke($globalService, 'form-option-constants/v1') === 'form-option-constants/v1',
    'form option constants have one explicit supported manager identity'
);
$normalizeManagedId = new ReflectionMethod(GlobalSymbolService::class, 'normalizeManagedId');
$normalizeManagedId->setAccessible(true);
$managedId = '37c8d13c-2f42-5b12-85ee-4179d3af235e';
$assert(
    $normalizeManagedId->invoke($globalService, strtoupper($managedId), 'form-option-constants/v1') === $managedId,
    'managed form option identity must be a canonical UUIDv5'
);
try {
    $normalizeManagedBy->invoke($globalService, 'unknown-generator/v1');
    throw new RuntimeException('Unknown global manager identity was accepted');
} catch (ReflectionException $error) {
    throw $error;
} catch (Throwable $error) {
    $assert($error instanceof InvalidArgumentException, 'unknown global manager identity must be rejected');
}

$removedElementIds = new ReflectionMethod(GlobalSymbolService::class, 'removedElementIds');
$removedElementIds->setAccessible(true);
$assert(
    $removedElementIds->invoke(null, [
        ['id' => 701, 'code' => 'keep'],
        ['id' => 702, 'code' => 'remove'],
        ['id' => 703, 'code' => 'remove_too'],
    ], [701]) === [702, 703],
    'omitted global symbols must be planned for deletion without a reference gate'
);

foreach ($reserved as $code) {
    $assert(CalculatorMutationAuthorityService::isReservedIdentifier($code), 'Authority must reserve ' . $code);
    try {
        $normalize->invoke($globalService, $code);
        throw new RuntimeException('GlobalSymbolService accepted reserved code ' . $code);
    } catch (ReflectionException $error) {
        throw $error;
    } catch (Throwable $error) {
        $assert(
            $error instanceof InvalidArgumentException && str_contains($error->getMessage(), 'зарезервирован'),
            'GlobalSymbolService must reject reserved code ' . $code
        );
    }
}

$assert(CalculatorMutationAuthorityService::isReservedIdentifier('Stage_42'), 'stage_N is reserved case-insensitively');
$assert(!CalculatorMutationAuthorityService::isReservedIdentifier('sheet_width'), 'ordinary calculator identifiers remain available');
$assert(CalculatorMutationAuthorityService::findForbiddenRoot('offer.price') === 'offer', 'offer is private');
$assert(CalculatorMutationAuthorityService::findForbiddenRoot('selectedOffers[0]') === 'selectedOffers', 'selectedOffers is private');
$assert(CalculatorMutationAuthorityService::findForbiddenRoot('"offer.price" + sheet_width') === null, 'quoted roots are data');

$graphs = [
    41 => [
        'presetId' => 41,
        'rootDetailIds' => [101],
        'detailIds' => [101, 102],
        'stageIds' => [201, 202],
        'settingsIds' => [301, 302, 303],
        'directSettingsIds' => [303],
        'detailChildren' => [101 => [102], 102 => []],
        'detailStages' => [101 => [201], 102 => [202]],
        'stageSettings' => [201 => [301], 202 => [302]],
    ],
    42 => [
        'presetId' => 42,
        'rootDetailIds' => [111],
        'detailIds' => [111],
        'stageIds' => [211],
        'settingsIds' => [311],
        'directSettingsIds' => [],
        'detailChildren' => [111 => []],
        'detailStages' => [111 => [211]],
        'stageSettings' => [211 => [311]],
    ],
];
$known = [
    'detail' => [101, 102, 103, 111],
    'stage' => [201, 202, 211],
    'settings' => [301, 302, 303, 311, 399],
];
$connection = new CalculatorMutationAuthorityFakeConnection();
$lockEvents = [];
$orphanReferences = ['detail' => [], 'stage' => [], 'settings' => []];
$mappingOnlySettings = [];
$authority = new CalculatorMutationAuthorityService([
    'connection_provider' => static fn() => $connection,
    'iblock_ids' => static fn(): array => [
        'CALC_DETAILS' => 10,
        'CALC_PRESETS' => 11,
        'CALC_SETTINGS' => 12,
        'CALC_STAGES' => 13,
        'CALC_GLOBAL_VALUES' => 14,
        'CALC_CUSTOM_FIELDS' => 18,
        'CALC_OPERATIONS' => 19,
        'CALC_OPERATIONS_VARIANTS' => 15,
        'CALC_MATERIALS' => 20,
        'CALC_MATERIALS_VARIANTS' => 16,
        'CALC_EQUIPMENT' => 17,
    ],
    'authority_locker' => static function (
        CalculatorMutationAuthorityFakeConnection $lockedConnection,
        int $presetId,
        array $iblockIds
    ) use (&$lockEvents, $connection): array {
        if ($lockedConnection !== $connection || (int)$iblockIds['CALC_PRESETS'] !== 11) {
            throw new RuntimeException('Wrong transaction authority passed to lock adapter.');
        }
        $lockEvents[] = $presetId;
        return ['presetRowRevision' => hash('sha256', (string)$presetId)];
    },
    'graphs_loader' => static function (array $iblockIds) use (&$graphs): array {
        if ((int)$iblockIds['CALC_PRESETS'] !== 11) {
            throw new RuntimeException('Graph read used stale iblock authority.');
        }
        return $graphs;
    },
    'structural_references_loader' => static function (array $iblockIds) use (
        &$graphs,
        &$orphanReferences,
        &$mappingOnlySettings
    ): array {
        if ((int)$iblockIds['CALC_DETAILS'] !== 10) {
            throw new RuntimeException('Reference scan used stale iblock authority.');
        }
        $references = ['detail' => [], 'stage' => [], 'settings' => []];
        foreach ($graphs as $presetId => $graph) {
            foreach ($graph['rootDetailIds'] as $detailId) {
                $references['detail'][(int)$detailId][] = [
                    'sourceKind' => 'preset',
                    'sourceId' => (int)$presetId,
                ];
            }
            foreach ($graph['stageIds'] as $stageId) {
                $references['stage'][(int)$stageId][] = [
                    'sourceKind' => 'preset',
                    'sourceId' => (int)$presetId,
                ];
            }
            foreach ($graph['detailChildren'] as $parentId => $children) {
                foreach ($children as $detailId) {
                    $references['detail'][(int)$detailId][] = [
                        'sourceKind' => 'detail',
                        'sourceId' => (int)$parentId,
                    ];
                }
            }
            foreach ($graph['detailStages'] as $detailId => $stages) {
                foreach ($stages as $stageId) {
                    $references['stage'][(int)$stageId][] = [
                        'sourceKind' => 'detail',
                        'sourceId' => (int)$detailId,
                    ];
                }
            }
            foreach ($graph['stageSettings'] as $stageId => $settingsRows) {
                foreach ($settingsRows as $settingsId) {
                    if (isset($mappingOnlySettings[(int)$settingsId])) {
                        continue;
                    }
                    $references['settings'][(int)$settingsId][] = [
                        'sourceKind' => 'stage',
                        'sourceId' => (int)$stageId,
                    ];
                }
            }
            // CALC_PRESETS.CALC_SETTINGS is a complete search index. Only
            // entries not linked by a stage are direct preset-owned settings.
            foreach ($graph['settingsIds'] ?? [] as $settingsId) {
                $references['settings'][(int)$settingsId][] = [
                    'sourceKind' => 'preset',
                    'sourceId' => (int)$presetId,
                ];
            }
        }
        foreach ($orphanReferences as $kind => $targets) {
            foreach ($targets as $targetId => $rows) {
                foreach ($rows as $row) {
                    $references[$kind][(int)$targetId][] = $row;
                }
            }
        }
        return $references;
    },
    'element_exists' => static function (string $surface, int $elementId) use (&$known): bool {
        return $surface === 'preset'
            ? in_array($elementId, [41, 42], true)
            : in_array($elementId, $known[$surface] ?? [], true);
    },
]);

$underLock = static function (int $presetId, callable $assertion) use ($authority) {
    return $authority->withAuthorityLock(
        $presetId,
        static fn(bool $protected, array $iblockIds, array $lock) => $assertion($protected, $iblockIds, $lock)
    );
};
$transactionEventsBeforeJoin = $connection->events;
$joinedGraph = $authority->withAuthorityInTransaction(
    $connection,
    42,
    static function () use ($authority): array {
        return $authority->readLockedPresetGraph(42);
    }
);
$assert(($joinedGraph['presetId'] ?? 0) === 42, 'external transaction can read the pinned graph');
$assert(
    $connection->events === $transactionEventsBeforeJoin,
    'joining a coordinator transaction must not begin, commit or roll it back'
);
$reentrantEventsBefore = $connection->events;
$reentrantPreset = $authority->withAuthorityInTransaction(
    $connection,
    41,
    static function () use ($authority): int {
        return $authority->withAuthorityLock(
            41,
            static fn(bool $protected, array $iblockIds): int => (int)$iblockIds['CALC_PRESETS']
        );
    }
);
$assert($reentrantPreset === 11, 'an injected element service can reuse the coordinator authority');
$assert(
    $connection->events === $reentrantEventsBefore,
    'reusing the coordinator authority must not open a nested transaction'
);
$expectConflict = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
        throw new RuntimeException('Expected conflict: ' . $message);
    } catch (RuntimeException $error) {
        $assert($error->getCode() === 409, $message . ' must fail with HTTP conflict semantics');
    }
};

$underLock(41, static function (bool $protected) use ($authority): void {
    $authority->assertStructuralMutationAllowed(41, [101, 102], $protected, 'valid topology');
    $authority->assertStageStructuralMutationAllowed(41, 201, $protected, 'valid stage');
    $authority->assertSettingsMutationAllowed(41, 301, $protected);
    $authority->assertStageMoveAllowed(41, 201, 101, 102, $protected);
    $authority->assertDetailDeletionCascadeAllowed(41, 102, $protected, 'valid leaf cascade');
    $authority->assertSettingsLogicWrite(41, 301, json_encode([
        'version' => 2,
        'vars' => [['name' => 'sheet_cost', 'formula' => 'volume * 2']],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $protected);
    $authority->assertSettingsLogicWrite(41, 303, '{"version":2,"vars":[]}', $protected);
    $authority->assertContractCloneAllowed(41, 201, 301);
});

$mappingOnlySettings[301] = true;
$underLock(41, static function (bool $protected) use ($authority): void {
    $authority->assertSettingsMutationAllowed(41, 301, $protected);
    $authority->assertSettingsLinkToStage(41, 201, 301, $protected);
});
unset($mappingOnlySettings[301]);

$graphs[41]['stageSettings'][202][] = 301;
$expectConflict(
    static fn() => $underLock(41, static fn(bool $protected) =>
        $authority->assertSettingsLinkToStage(41, 201, 301, $protected)),
    'Calculator settings linked through another stage tree cannot be attached to the current stage'
);
$graphs[41]['stageSettings'][202] = [302];

$orphanReferences['detail'][102][] = ['sourceKind' => 'detail', 'sourceId' => 999];
$expectConflict(
    static fn() => $underLock(41, static fn(bool $protected) =>
        $authority->assertStructuralMutationAllowed(41, [102], $protected, 'orphan detail reference')),
    'An incoming detail reference from an unattached binding is rejected'
);
$orphanReferences['detail'] = [];

$orphanReferences['stage'][201][] = ['sourceKind' => 'detail', 'sourceId' => 998];
$expectConflict(
    static fn() => $underLock(41, static fn(bool $protected) =>
        $authority->assertStageStructuralMutationAllowed(41, 201, $protected, 'orphan stage reference')),
    'An incoming stage reference from an unattached detail is rejected'
);
$orphanReferences['stage'] = [];

$orphanReferences['settings'][301][] = ['sourceKind' => 'stage', 'sourceId' => 997];
$expectConflict(
    static fn() => $underLock(41, static fn(bool $protected) =>
        $authority->assertSettingsMutationAllowed(41, 301, $protected)),
    'An incoming settings reference from an unattached stage is rejected'
);
$orphanReferences['settings'] = [];

$orphanReferences['settings'][399][] = ['sourceKind' => 'stage', 'sourceId' => 997];
$expectConflict(
    static fn() => $underLock(41, static fn(bool $protected) =>
        $authority->assertSettingsLinkToStage(41, 201, 399, $protected)),
    'Orphan-referenced settings cannot be attached to an owned stage'
);
$orphanReferences['settings'] = [];

$expectConflict(
    static fn() => $underLock(41, static fn(bool $protected) =>
        $authority->assertStructuralMutationAllowed(41, [111], $protected, 'cross-preset clone')),
    'Preset A cannot clone preset B detail'
);
$expectConflict(
    static fn() => $underLock(41, static fn(bool $protected) =>
        $authority->assertStageStructuralMutationAllowed(41, 211, $protected, 'cross-preset delete')),
    'Preset A cannot delete preset B stage'
);
$expectConflict(
    static fn() => $underLock(41, static fn(bool $protected) =>
        $authority->assertSettingsLogicWrite(41, 311, '{"version":2,"vars":[]}', $protected)),
    'Preset A cannot modify preset B settings'
);
$expectConflict(
    static fn() => $underLock(41, static fn(bool $protected) =>
        $authority->assertSettingsLinkToStage(41, 201, 311, $protected)),
    'Preset A cannot link preset B settings'
);
$expectConflict(
    static fn() => $underLock(41, static fn(bool $protected) =>
        $authority->assertStageMoveAllowed(41, 211, 101, 102, $protected)),
    'Preset A cannot move preset B stage'
);

$graphs[42]['detailIds'][] = 102;
$graphs[42]['rootDetailIds'][] = 102;
$graphs[42]['detailChildren'][102] = [];
$graphs[42]['detailStages'][102] = [];
$expectConflict(
    static fn() => $underLock(41, static fn(bool $protected) =>
        $authority->assertDetailDeletionCascadeAllowed(41, 102, $protected, 'shared cross-preset node')),
    'Cross-preset shared detail cascade is forbidden'
);
$graphs[42]['detailIds'] = [111];
$graphs[42]['rootDetailIds'] = [111];
unset($graphs[42]['detailChildren'][102], $graphs[42]['detailStages'][102]);

$graphs[41]['rootDetailIds'][] = 103;
$graphs[41]['detailIds'][] = 103;
$graphs[41]['detailChildren'][103] = [102];
$graphs[41]['detailStages'][103] = [];
$expectConflict(
    static fn() => $underLock(41, static fn(bool $protected) =>
        $authority->assertDetailDeletionCascadeAllowed(41, 102, $protected, 'shared parent node')),
    'A detail referenced by two parents cannot be cascade-deleted'
);
$graphs[41]['rootDetailIds'] = [101];
$graphs[41]['detailIds'] = [101, 102];
unset($graphs[41]['detailChildren'][103], $graphs[41]['detailStages'][103]);

$graphs[41]['rootDetailIds'] = [101, 103];
$graphs[41]['detailIds'] = [101, 102, 103];
$graphs[41]['detailChildren'] = [101 => [102], 102 => [], 103 => []];
$graphs[41]['detailStages'][103] = [];
$underLock(41, static function (bool $protected) use ($authority, &$graphs, $assert): void {
    $source = $authority->assertDetailMoveIntoBindingAllowed(41, 102, 103, $protected);
    $assert(
        $source === ['sourceKind' => 'detail', 'sourceId' => 101],
        'Cross-parent detail move resolves its one authoritative source'
    );
    $graphs[41]['detailChildren'][101] = [];
    $graphs[41]['detailChildren'][103] = [102];
    $authority->assertDetailMoveIntoBindingApplied(41, 102, 103, $source);
});

$graphs[41]['rootDetailIds'] = [101, 103];
$graphs[41]['detailChildren'] = [101 => [102], 102 => [], 103 => []];
$underLock(41, static function (bool $protected) use ($authority, &$graphs, $assert): void {
    $source = $authority->assertDetailMoveIntoBindingAllowed(41, 103, 101, $protected);
    $assert(
        $source === ['sourceKind' => 'preset', 'sourceId' => 41],
        'Root-to-binding move resolves the preset root edge'
    );
    $graphs[41]['rootDetailIds'] = [101];
    $graphs[41]['detailChildren'][101] = [102, 103];
    $authority->assertDetailMoveIntoBindingApplied(41, 103, 101, $source);
});
$graphs[41]['rootDetailIds'] = [101];
$graphs[41]['detailIds'] = [101, 102];
$graphs[41]['detailChildren'] = [101 => [102], 102 => []];
unset($graphs[41]['detailStages'][103]);

$underLock(41, static fn(bool $protected) =>
    $authority->assertStageMoveAllowed(41, 201, 101, 102, $protected));
$graphs[41]['detailStages'] = [101 => [], 102 => [201, 202]];
$expectConflict(
    static fn() => $underLock(41, static fn(bool $protected) =>
        $authority->assertStageMoveAllowed(41, 201, 101, 102, $protected)),
    'A stale move source is rejected after the locked graph changes'
);
$graphs[41]['detailStages'] = [101 => [201], 102 => [202]];

$graphs[42]['settingsIds'][] = 301;
$graphs[42]['stageSettings'][211][] = 301;
$deletePlan = $underLock(41, static fn(bool $protected) =>
    $authority->assertLockedPresetGraphDeletable(41));
$assert(
    !in_array(301, $deletePlan['deletionSettingsIds'], true)
        && in_array(301, $deletePlan['preservedSettingsIds'], true),
    'Cascade deletion preserves settings referenced by another calculator'
);
$assert(
    $deletePlan['deletionDetailIds'] === [101, 102]
        && $deletePlan['deletionStageIds'] === [201, 202]
        && $deletePlan['deletionSettingsIds'] === [302, 303],
    'Cascade deletion removes the target-owned graph while retaining shared descendants'
);
$graphs[42]['settingsIds'] = [311];
$graphs[42]['stageSettings'][211] = [311];

$invalidLogic = [
    ['', 'non-empty JSON string'],
    ['{broken', 'invalid JSON'],
    ['[]', 'must be an object'],
    [json_encode(['version' => 1, 'vars' => []]), 'must use version 2'],
    [json_encode(['version' => 2]), 'explicit vars array'],
    [json_encode(['version' => 2, 'vars' => [null]]), 'vars[0] must be an object'],
    [json_encode(['version' => 2, 'vars' => [['name' => 'cost']]]), 'formula must be a string'],
];
foreach ($invalidLogic as [$payload, $messagePart]) {
    try {
        $underLock(41, static fn(bool $protected) =>
            $authority->assertSettingsLogicWrite(41, 301, $payload, $protected));
        throw new RuntimeException('Invalid LOGIC_JSON was accepted: ' . $messagePart);
    } catch (InvalidArgumentException $error) {
        $assert(str_contains($error->getMessage(), $messagePart), 'Unexpected LOGIC_JSON validation error: ' . $error->getMessage());
    }
}

$assert(in_array('commit', $connection->events, true), 'successful authority mutation commits');
$assert(in_array('rollback', $connection->events, true), 'rejected authority mutation rolls back');
$assert($lockEvents !== [] && array_diff($lockEvents, [41, 42]) === [], 'locking is generic and follows requested preset IDs');
$source = (string)file_get_contents(dirname(__DIR__) . '/lib/Services/CalculatorMutationAuthorityService.php');
$assert(
    str_contains($source, 'SELECT ID, CODE FROM b_iblock WHERE ')
    && !str_contains($source, "IBLOCK_TYPE_ID='")
    && str_contains($source, 'CODE IN ('),
    'production authority locks every row matching each canonical code independent of admin grouping'
);
$assert(str_contains($source, 'FROM b_option') && str_contains($source, 'BINARY NAME IN ('), 'production authority locks exact code-to-ID option rows');
$assert(str_contains($source, 'withAuthorityInTransaction'), 'wider coordinators can join the same graph authority');
$assert(str_contains($source, 'FROM b_iblock_element') && str_contains($source, 'FOR UPDATE'), 'production authority locks the requested preset row');
$assert(str_contains($source, 'loadStructuralReferenceIndex'), 'production authority scans global reverse relationships');
$assert(
    str_contains($source, "readPropertyIds(\$presetIblockId, \$presetId, 'CALC_SETTINGS')")
        && str_contains($source, "['preset', 'CALC_PRESETS', 'CALC_SETTINGS', 'settings']")
        && str_contains($source, "'directSettingsIds' => \$directSettingsIds")
        && str_contains($source, "'settings' => ['stage' => true, 'preset' => true]")
        && str_contains($source, "\$kind === 'settings' && \$sourceKind === 'preset'"),
    'preset-owned settings must participate in graph ownership, reverse references and cascade deletion'
);
foreach (['CALC_DETAILS', 'DETAILS', 'CALC_STAGES', 'CALC_SETTINGS'] as $relationshipCode) {
    $assert(
        str_contains($source, "'" . $relationshipCode . "'"),
        'reverse-reference scan must cover ' . $relationshipCode
    );
}
$assert(!str_contains($source, '12740'), 'mutation authority must not contain a magic preset ID');
$assert(!method_exists(CalculatorMutationAuthorityService::class, 'assertStageAssignmentsWrite'), 'retired stage assignment write boundary must remain removed');

$elementSource = (string)file_get_contents(dirname(__DIR__) . '/lib/Calculator/ElementDataService.php');
$caseSlice = static function (string $case) use ($elementSource): string {
    $start = strpos($elementSource, "case '" . $case . "':");
    if ($start === false) {
        return '';
    }
    $end = strpos($elementSource, "case '", $start + strlen($case) + 8);
    return $end === false ? substr($elementSource, $start) : substr($elementSource, $start, $end - $start);
};
foreach ([
    'renameDetail',
    'changeCustomFieldsValue',
    'selectFields',
    'createCustomField',
    'changeStageName',
    'changeEntityMeta',
    'changeNameDetail',
    'resolveCalculatorContract',
    'saveStageUsedEntities',
] as $case) {
    $slice = $caseSlice($case);
    $assert($slice !== '', $case . ' action must exist');
    $assert(str_contains($slice, 'withAuthorityLock('), $case . ' must use the preset authority transaction');
}
$bindingSlice = $caseSlice('addDetailsToBinding');
$assert(
    str_contains($bindingSlice, 'assertDetailMoveIntoBindingAllowed')
        && str_contains($bindingSlice, 'assertDetailMoveIntoBindingApplied')
        && str_contains($bindingSlice, 'moveDetailIntoBindingPinned')
        && !str_contains($bindingSlice, '->addDetailsToBinding('),
    'binding selection must move one owned edge and verify its post-state instead of appending a second edge'
);
$globalSource = (string)file_get_contents(dirname(__DIR__) . '/lib/Services/GlobalSymbolService.php');
$saveStart = strpos($globalSource, 'public function save(');
$saveEnd = strpos($globalSource, 'private function clearInitialValueStorageDirect', $saveStart ?: 0);
$saveSlice = $saveStart !== false && $saveEnd !== false
    ? substr($globalSource, $saveStart, $saveEnd - $saveStart)
    : '';
$assert(
    str_contains($saveSlice, 'withAuthorityLock($presetId')
        && !str_contains($saveSlice, 'startTransaction()'),
    'global symbol saves must share the preset authority transaction without nesting another transaction'
);
$assert(
    str_contains($globalSource, "\$fields['XML_ID'] = self::managedXmlId(\$managedBy, \$managedId)")
        && str_contains($globalSource, "self::managedMetadataFromXmlId")
        && str_contains($globalSource, "private const FORM_OPTION_MANAGER = 'form-option-constants/v1'"),
    'managed form constants must persist explicit owner and stable UUIDv5 identity in the registry element XML_ID'
);

echo "Calculator mutation authority tests passed\n";
