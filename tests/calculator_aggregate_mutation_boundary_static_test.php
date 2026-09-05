<?php

declare(strict_types=1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = dirname(__DIR__);
$element = (string)file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$aggregate = (string)file_get_contents($root . '/lib/Services/CalculatorSemanticMutationService.php');
$rehydrator = (string)file_get_contents($root . '/lib/Services/CalculatorVersionWorkingGraphRehydrator.php');
$authority = (string)file_get_contents($root . '/lib/Services/CalculatorMutationAuthorityService.php');
$bridge = (string)file_get_contents($root . '/install/assets/js/integration.js');
$endpoint = (string)file_get_contents($root . '/tools/calculator_ajax.php');

preg_match('/private const ACTIONS = \[(.*?)\n    \];/s', $aggregate, $actionMatch);
preg_match_all("/'([^']+)'/", (string)($actionMatch[1] ?? ''), $actionRows);
$aggregateActions = array_fill_keys($actionRows[1] ?? [], true);
$assert($aggregateActions !== [], 'aggregate mutation action registry must be explicit');

preg_match_all("/case '([^']+)':/", $element, $caseMatches, PREG_OFFSET_CAPTURE);
foreach ($caseMatches[1] ?? [] as $index => $match) {
    $action = (string)$match[0];
    $start = (int)$caseMatches[0][$index][1];
    $end = isset($caseMatches[0][$index + 1]) ? (int)$caseMatches[0][$index + 1][1] : strlen($element);
    $slice = substr($element, $start, $end - $start);
    if (str_contains($slice, 'withAuthorityLock(')) {
        $assert(
            isset($aggregateActions[$action]),
            $action . ' must cross the aggregate CAS/audit/readback boundary'
        );
    }
}

foreach ($aggregateActions as $action => $_) {
    $assert(
        str_contains($bridge, "'" . $action . "',"),
        $action . ' must require the authoritative semantic revision in the bridge'
    );
}
$assert(
    str_contains($bridge, 'mutationItems.length > 0')
        && substr_count($bridge, 'this.presetMutationActions()') === 3
        && str_contains($bridge, "formData.append('expectedSemanticRevision', expectedSemanticRevision)")
        && str_contains($bridge, 'this.initData = Object.assign({}, this.initData, semanticReadback')
        && str_contains($bridge, 'semanticRevision: resultingSemanticRevision')
        && str_contains($bridge, 'data.data[0].initPayload = this.initData;'),
    'all registered mutations must pin the INIT preset, submit CAS and reconcile exact aggregate readback'
);
$assert(
    str_contains($bridge, 'this.calculatorMutationQueue = Promise.resolve()')
        && str_contains($bridge, 'const queued = this.calculatorMutationQueue.then(run, run)')
        && str_contains($bridge, 'this.calculatorMutationQueue = queued.catch(() => undefined)')
        && str_contains($bridge, 'const containsGlobalMutation = Array.isArray(preparedItems)')
        && str_contains($bridge, 'const containsCoordinatedMutation = Array.isArray(preparedItems)')
        && str_contains($bridge, "'applyGlobalCodeRefactor',")
        && str_contains($bridge, 'const refreshedInitData = await this.fetchInitData()')
        && str_contains($bridge, 'requestedGeneration === this.initDataGeneration')
        && str_contains($bridge, 'fetchRefreshDataWithStagePropertyConflictRetry(preparedItems)')
        && str_contains($bridge, "['OPTIONS_CALCULATOR', 'OPTIONS_OPERATION', 'OPTIONS_EQUIPMENT', 'OPTIONS_MATERIAL']")
        && str_contains($bridge, 'refreshedFingerprint !== retryContext.fingerprint')
        && str_contains($bridge, "(mutationItems.length === 1 || coordinatedMutationItems.length === 1)\n                    && this.config.versionMode === 'edit'")
        && str_contains($bridge, 'async fetchRefreshDataNow(items)'),
    'same-editor semantic/global writes must be serialized and global writes must refresh semantic state'
);
$assert(
    str_contains($bridge, "this.sendPwrtMessage('INIT', this.initData, message.requestId, origin);")
        && str_contains($bridge, "message: 'Не удалось переименовать элемент'")
        && str_contains($bridge, "details: error && error.message ? error.message : 'Unknown error'"),
    'detail rename must return a correlated INIT or ERROR so optimistic inline rename can be confirmed or rolled back'
);
$assert(
    str_contains($bridge, "formData.append('versionOriginalPresetId'")
        && str_contains($bridge, "formData.append('versionWorkingPresetId'")
        && str_contains($bridge, "formData.append('versionId'")
        && str_contains($endpoint, '$versionReadbackContext')
        && str_contains($aggregate, 'prepareVersionEditorSemanticReadbackReadOnly'),
    'version graph mutations must use isolated-graph semantic readback instead of public preset INIT'
);
$assert(
    str_contains($aggregate, 'new ElementDataService(')
        && str_contains($aggregate, '$authority,')
        && str_contains($aggregate, '$this->stageVariantSourceContext($request, $versionReadbackContext, $authority)')
        && str_contains($aggregate, 'StageVariantMappingService::ENTITY_PARAMETER_SELECTION_CONTRACT')
        && str_contains($element, '$this->mutationAuthority()'),
    'structural mutations must reuse the authority and version source context held by the aggregate coordinator'
);
$assert(
    str_contains($rehydrator, 'StageVariantMappingService::ENTITY_PARAMETER_SELECTION_CONTRACT')
        && str_contains($rehydrator, '$mappingService->normalizeMaterialJson($decodedRaw)'),
    'version graph rehydration must preserve parameter-selection documents for every entity target'
);
$assert(
    substr_count($element, 'return self::enrichStructuralResultPinned(') === 1
        && substr_count($element, 'return $this->completeStructuralMutationPinned(') >= 17,
    'every preset-owned mutation must defer public INIT to the aggregate readback boundary'
);
$assert(
    str_contains($authority, 'if ($this->lockedPresetId > 0)')
        && str_contains($authority, 'Nested calculator mutation targets a different preset.'),
    'the injected authority may be reused only for the exact locked preset'
);

foreach (['enrichPreset', 'clearPreset'] as $action) {
    $assert(isset($aggregateActions[$action]), $action . ' must be an aggregate mutation');
    $assert(
        !str_contains($endpoint, "case '" . $action . "':")
            && !str_contains($endpoint, 'function handle' . ucfirst($action)),
        $action . ' must not retain a direct non-CAS endpoint'
    );
}

echo "Calculator aggregate mutation boundary static tests passed\n";
