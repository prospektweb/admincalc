<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/NeutralFormulaPolicy.php';

use Prospektweb\Calc\Services\NeutralFormulaPolicy;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$forbidden = [
    'product',
    'offer',
    'selectedOffer',
    'selectedOffers',
    'context',
    'iblocks',
    'elementsStore',
    'priceTypes',
    'resources',
    'globalSymbols',
];
foreach ($forbidden as $root) {
    $assert(
        NeutralFormulaPolicy::findForbiddenRoot('1 + get(' . $root . ', "id")') === $root,
        'token policy rejects executable root ' . $root
    );
}

$quoted = 'get(input, "values.offer") + "selectedOffer context" + \'globalSymbols resources\'';
$assert(
    NeutralFormulaPolicy::findForbiddenRoot($quoted) === null,
    'quoted paths and labels do not become executable identifiers'
);
$assert(
    NeutralFormulaPolicy::findForbiddenRoot('"escaped \\" offer" + selectedOffer') === 'selectedOffer',
    'escaped quotes do not hide a later executable identifier'
);
$assert(
    NeutralFormulaPolicy::findForbiddenRoot('offering + products + contextual') === null,
    'identifier matching is exact rather than substring based'
);

NeutralFormulaPolicy::assertLogicJson(json_encode([
    'version' => 2,
    'vars' => [['name' => 'safe', 'formula' => 'get(input, "values.product")']],
]));
try {
    NeutralFormulaPolicy::assertLogicJson(json_encode([
        'version' => 2,
        'vars' => [['name' => 'leak', 'formula' => 'len(selectedOffers)']],
    ]));
    $assert(false, 'LOGIC_JSON must reject a private root');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'LOGIC_JSON rejection is a conflict');
}

try {
    NeutralFormulaPolicy::assertGlobalAssignments(json_encode([
        'assignments' => [[
            'globalCode' => 'leak',
            'formula' => 'get(globalSymbols, "secret")',
        ]],
    ]));
    $assert(false, 'GLOBAL_ASSIGNMENTS must reject a private root');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'GLOBAL_ASSIGNMENTS rejection is a conflict');
}

NeutralFormulaPolicy::assertInputMappings([
    ['VALUE' => 'volume', 'DESCRIPTION' => 'input.values.volume'],
    ['VALUE' => 'width', 'DESCRIPTION' => 'stage_55.outputVar.width'],
    ['VALUE' => 'label', 'DESCRIPTION' => '__literal__:"offer is only text"'],
]);
foreach (['offer.properties.QTY.VALUE', 'product.id', 'selectedOffer.name', 'elementsStore.CALC_STAGES'] as $path) {
    try {
        NeutralFormulaPolicy::assertInputMappings([
            ['VALUE' => 'leak', 'DESCRIPTION' => $path],
        ]);
        $assert(false, 'INPUTS must reject private path ' . $path);
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, 'INPUTS private path rejection is a conflict');
    }
}
foreach (['offer', 'selectedOffer', 'globalSymbols', 'input', 'CURRENT_STAGE', 'stage_55', 'bad-name'] as $parameter) {
    try {
        NeutralFormulaPolicy::assertInputMappings([
            ['VALUE' => $parameter, 'DESCRIPTION' => 'input.values.volume'],
        ]);
        $assert(false, 'INPUTS must reject reserved/invalid parameter ' . $parameter);
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, 'INPUTS parameter rejection is a conflict');
    }
}

try {
    NeutralFormulaPolicy::assertLogicJson('{broken');
    $assert(false, 'invalid LOGIC_JSON must fail closed');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'invalid LOGIC_JSON is a conflict');
}

NeutralFormulaPolicy::assertCloneAllowed(12740, false, 'details');
NeutralFormulaPolicy::assertCloneAllowed(999, true, 'details');
try {
    NeutralFormulaPolicy::assertCloneAllowed(12740, true, 'details');
    $assert(false, 'protected preset clone must fail before any clone DML');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'protected preset clone is a fail-closed conflict');
}

$elementSource = file_get_contents(dirname(__DIR__) . '/lib/Calculator/ElementDataService.php');
$migrationSource = file_get_contents(dirname(__DIR__) . '/lib/Install/Preset12740NeutralInputMigrationService.php');
$globalRefactorSource = file_get_contents(dirname(__DIR__) . '/lib/Services/GlobalCodeRefactorService.php');
$globalSymbolSource = file_get_contents(dirname(__DIR__) . '/lib/Services/GlobalSymbolService.php');
$policySource = file_get_contents(dirname(__DIR__) . '/lib/Services/NeutralFormulaPolicy.php');
$initSource = file_get_contents(dirname(__DIR__) . '/lib/Calculator/InitPayloadService.php');
$ajaxSource = file_get_contents(dirname(__DIR__) . '/tools/calculator_ajax.php');
$enrichmentSource = file_get_contents(dirname(__DIR__) . '/lib/Services/PresetEnrichmentService.php');
$assert(
    is_string($elementSource)
        && strpos($elementSource, 'assertSettingsLinkToStage(') !== false
        && strpos($elementSource, 'assertStageLinkToPreset(') !== false
        && strpos($elementSource, 'assertStageInputsWrite(') !== false
        && substr_count($elementSource, 'withActiveAuthorityLock(') >= 5,
    'actual editor save/link routes serialize validation and writes with ACTIVE authority'
);
$cloneDetailStart = strpos((string)$elementSource, "case 'cloneDetail':");
$changeProductTypeStart = strpos((string)$elementSource, "case 'changeProductType':");
$duplicateStageStart = strpos((string)$elementSource, "case 'duplicateStage':");
$deleteStageStart = strpos((string)$elementSource, "case 'deleteStage':");
$addDetailsStart = strpos((string)$elementSource, "case 'addDetailsToBinding':");
$changePriceStart = strpos((string)$elementSource, "case 'changePricePreset':");
$changeDetailSortStart = strpos((string)$elementSource, "case 'changeDetailSort':");
$changeDetailLevelStart = strpos((string)$elementSource, "case 'changeDetailLevel':");
$changeSortStageStart = strpos((string)$elementSource, "case 'changeSortStage':");
$changeRootSortStart = strpos((string)$elementSource, "case 'changeRootDetailSort':");
$moveStageStart = strpos((string)$elementSource, "case 'moveStage':");
$cloneSlice = is_int($cloneDetailStart) && is_int($changeProductTypeStart)
    ? substr($elementSource, $cloneDetailStart, $changeProductTypeStart - $cloneDetailStart)
    : '';
$duplicateSlice = is_int($duplicateStageStart) && is_int($deleteStageStart)
    ? substr($elementSource, $duplicateStageStart, $deleteStageStart - $duplicateStageStart)
    : '';
$addDetailsSlice = is_int($addDetailsStart) && is_int($changePriceStart)
    ? substr($elementSource, $addDetailsStart, $changePriceStart - $addDetailsStart)
    : '';
$changeDetailSortSlice = is_int($changeDetailSortStart) && is_int($changeDetailLevelStart)
    ? substr($elementSource, $changeDetailSortStart, $changeDetailLevelStart - $changeDetailSortStart)
    : '';
$changeDetailLevelSlice = is_int($changeDetailLevelStart) && is_int($changeSortStageStart)
    ? substr($elementSource, $changeDetailLevelStart, $changeSortStageStart - $changeDetailLevelStart)
    : '';
$changeSortStageSlice = is_int($changeSortStageStart) && is_int($changeRootSortStart)
    ? substr($elementSource, $changeSortStageStart, $changeRootSortStart - $changeSortStageStart)
    : '';
$changeRootSortSlice = is_int($changeRootSortStart) && is_int($moveStageStart)
    ? substr($elementSource, $changeRootSortStart, $moveStageStart - $changeRootSortStart)
    : '';
$moveStageSlice = is_int($moveStageStart) && is_int($addDetailsStart)
    ? substr($elementSource, $moveStageStart, $addDetailsStart - $moveStageStart)
    : '';
$assert(
    substr_count($cloneSlice, 'assertStructuralMutationAllowed(') === 2
        && substr_count($cloneSlice, 'new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds)') === 2
        && strpos($cloneSlice, 'assertStructuralMutationAllowed(')
            < strpos($cloneSlice, 'new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds)')
        && substr_count($duplicateSlice, 'assertStructuralMutationAllowed(') === 1
        && strpos($duplicateSlice, 'assertStructuralMutationAllowed(')
            < strpos($duplicateSlice, 'new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds)')
        && substr_count($addDetailsSlice, 'assertStructuralMutationAllowed(') === 1
        && strpos($addDetailsSlice, 'is_array($detailIds) ? $detailIds : []') !== false,
    'clone, duplicate and binding attach derive locked detail ownership before DML, ignoring forged preset ids'
);
$detailHandlerSource = file_get_contents(dirname(__DIR__) . '/lib/Services/DetailHandler.php');
$handlerSortStart = strpos((string)$detailHandlerSource, 'public function changeSortStage(');
$handlerProductTypeStart = strpos((string)$detailHandlerSource, 'public function changeProductType(');
$handlerSortSlice = is_int($handlerSortStart) && is_int($handlerProductTypeStart)
    ? substr($detailHandlerSource, $handlerSortStart, $handlerProductTypeStart - $handlerSortStart)
    : '';
$assert(
    substr_count($changeDetailSortSlice, 'assertStructuralMutationAllowed(') === 1
        && strpos($changeDetailSortSlice, 'assertStructuralMutationAllowed(')
            < strpos($changeDetailSortSlice, '->changeDetailSort(')
        && substr_count($changeDetailLevelSlice, 'assertStructuralMutationAllowed(') === 1
        && strpos($changeDetailLevelSlice, 'assertStructuralMutationAllowed(')
            < strpos($changeDetailLevelSlice, '->changeDetailLevel(')
        && substr_count($moveStageSlice, 'assertStageMoveAllowed(') === 1
        && strpos($moveStageSlice, 'assertStageMoveAllowed(') < strpos($moveStageSlice, '->moveStage(')
        && preg_match('/->moveStage\([\s\S]*?false\s*\)/', $moveStageSlice) === 1
        && substr_count($changeSortStageSlice, 'withActiveAuthorityLock(') === 1
        && strpos($changeSortStageSlice, 'assertStructuralMutationAllowed(')
            < strpos($changeSortStageSlice, '->changeSortStage(')
        && strpos($changeSortStageSlice, 'new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds)') !== false
        && preg_match('/->changeSortStage\([\s\S]*?false\s*\)/', $changeSortStageSlice) === 1
        && strpos($changeSortStageSlice, 'enrichStructuralResultPinned(') !== false
        && strpos($changeSortStageSlice, 'enrichmentWarning') === false
        && substr_count($changeRootSortSlice, 'withActiveAuthorityLock(') === 1
        && strpos($changeRootSortSlice, "\$pinnedIblockIds['CALC_PRESETS']") !== false
        && strpos($changeRootSortSlice, 'assertStructuralMutationAllowed(')
            < strpos($changeRootSortSlice, '\CIBlockElement::SetPropertyValuesEx(')
        && strpos($changeRootSortSlice, 'AND IBLOCK_ID = ') !== false
        && strpos($changeRootSortSlice, 'FOR UPDATE') !== false
        && strpos($changeRootSortSlice, 'if ($readRootIds() !== $sorting)')
            < strpos($changeRootSortSlice, 'enrichStructuralResultPinned(')
        && strpos($changeRootSortSlice, 'startTransaction(') === false
        && strpos($changeRootSortSlice, 'commitTransaction(') === false
        && strpos($changeRootSortSlice, 'rollbackTransaction(') === false
        && strpos($changeRootSortSlice, 'Option::get(') === false
        && is_string($detailHandlerSource)
        && strpos($handlerSortSlice, 'bool $manageTransaction = true') !== false
        && strpos($handlerSortSlice, 'if ($manageTransaction) {') !== false
        && strpos($handlerSortSlice, 'if (!$manageTransaction) {') !== false
        && strpos($handlerSortSlice, 'AND IBLOCK_ID = ') !== false
        && strpos($handlerSortSlice, 'FOR UPDATE') !== false
        && strpos($handlerSortSlice, ')->fetch()') !== false
        && strpos($handlerSortSlice, 'if (!is_array($lockedDetail))') !== false,
    'topology moves and both sort routes validate actual ownership and finish readback/enrichment under one authority transaction'
);
$assert(
    substr_count($elementSource, 'array $pinnedIblockIds') >= 8
        && strpos($elementSource, 'Protected preset property {$propertyCode} must be provisioned') !== false
        && strpos($elementSource, 'Stage property ') !== false
        && strpos($elementSource, 'Calculator property ') !== false,
    'protected formula writes receive locked iblock ids and never provision schema under the authority transaction'
);
$assert(
    is_string($migrationSource)
        && substr_count($migrationSource, '->assertCurrentPresetAuthoringStateSafe(') === 2
        && substr_count($migrationSource, '$lockedConfigSnapshot,') >= 2
        && substr_count($migrationSource, "(int)(\$globalActivationPlan['globalIblockId'] ?? 0)") === 2,
    'both activation paths re-audit formulas under the activation transaction'
);
$assert(
    is_string($globalRefactorSource)
        && strpos($globalRefactorSource, 'lockNeutralContractAuthority(') !== false
        && strpos($globalRefactorSource, 'lockRegistryRows($connection, $lockedGlobalIblockId);') !== false
        && strpos($globalRefactorSource, 'requiredSymbolIdentities()') !== false
        && substr_count($globalRefactorSource, 'readNeutralContractAuthority()') === 2
        && strpos($globalRefactorSource, 'ConfigManager') === false
        && strpos($globalRefactorSource, 'listReadOnlyFromIblockId(') !== false
        && strpos($globalRefactorSource, '::isReservedGlobalCode($new)') !== false
        && substr_count($globalRefactorSource, '::assertNeutralRuntimeRows(') === 2
        && strpos($globalRefactorSource, '$prospectiveNeutralRows = $this->buildProspectiveNeutralRows(')
            < strpos($globalRefactorSource, "foreach (\$lockedPlan['mutations'] as \$mutation)")
        && strpos($globalRefactorSource, '$readBackNeutralRows = $this->neutralRegistryRows(')
            < strpos($globalRefactorSource, '$connection->commitTransaction();'),
    'global refactor uses pinned locks, shared namespace rules, prospective validation and exact read-back'
);
$listStart = strpos((string)$globalSymbolSource, 'public function list(');
$listReadOnlyStart = strpos((string)$globalSymbolSource, 'public function listReadOnly(');
$neutralListStart = strpos((string)$globalSymbolSource, 'private function listNeutralPresetReadOnly(');
$pinnedListStart = strpos((string)$globalSymbolSource, 'public function listReadOnlyFromIblockId(');
$assert(
    is_int($listStart) && is_int($listReadOnlyStart) && is_int($neutralListStart) && is_int($pinnedListStart)
        && strpos(substr($globalSymbolSource, $listStart, $listReadOnlyStart - $listStart), 'listNeutralPresetReadOnly()')
            < strpos(substr($globalSymbolSource, $listStart, $listReadOnlyStart - $listStart), 'ensureStorage()')
        && strpos(substr($globalSymbolSource, $neutralListStart, $pinnedListStart - $neutralListStart), 'readNeutralContractAuthority()') !== false
        && strpos(substr($globalSymbolSource, $neutralListStart, $pinnedListStart - $neutralListStart), 'claimLegacyRows(') === false,
    'preset-12740 list paths resolve the frontcalc/calc pinned authority without provisioning or claiming rows'
);
$assert(
    is_string($policySource)
        && strpos($policySource, 'public function readNeutralContractAuthority(): array') !== false
        && strpos($policySource, "(\$forUpdate ? ' FOR UPDATE' : '')") !== false,
    'read-only and locked consumers share one duplicate-rejecting neutral authority parser'
);
$delegate = strpos((string)$initSource, 'return $this->prepareNeutralInitPayloadReadOnly(0, $offerIds, $siteId, false);');
$legacyRepair = strpos((string)$initSource, 'ensureOfferNamingAndMarginSchema();');
$assert(
    $delegate !== false && $legacyRepair === false,
    'legacy offer INIT is only a pinned neutral read-only delegation with no unreachable repair path'
);
$enrichStart = strpos((string)$ajaxSource, 'function handleEnrichPreset(');
$clearStart = strpos((string)$ajaxSource, 'function handleClearPreset(');
$clonePresetStart = strpos((string)$ajaxSource, 'function handleClonePreset(');
$enrichRoute = is_int($enrichStart) && is_int($clearStart)
    ? substr($ajaxSource, $enrichStart, $clearStart - $enrichStart)
    : '';
$clearRoute = is_int($clearStart) && is_int($clonePresetStart)
    ? substr($ajaxSource, $clearStart, $clonePresetStart - $clearStart)
    : '';
$assert(
    strpos($enrichRoute, 'withActiveAuthorityLock(') !== false
        && strpos($enrichRoute, 'assertStructuralMutationAllowed(')
            < strpos($enrichRoute, 'new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds)')
        && strpos($enrichRoute, 'new \Prospektweb\Calc\Services\PresetEnrichmentService($pinnedIblockIds)') !== false
        && strpos($clearRoute, 'withActiveAuthorityLock(') !== false
        && strpos($clearRoute, 'assertStructuralMutationAllowed(')
            < strpos($clearRoute, '->clearPreset($presetId)')
        && is_string($enrichmentSource)
        && strpos($enrichmentSource, 'public function __construct(?array $pinnedIblockIds = null)') !== false
        && strpos($enrichmentSource, 'private bool $neutralAuthorityPinned = false;') !== false
        && substr_count($enrichmentSource, 'withActiveAuthorityLock(') >= 3
        && substr_count($enrichmentSource, 'new self($pinnedIblockIds)') >= 3,
    'live and indirect enrich/clear writes self-serialize, reject protected topology and use locked pinned iblocks'
);

fwrite(STDOUT, "OK\n");
