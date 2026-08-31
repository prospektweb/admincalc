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
        && substr_count($bridge, 'this.presetMutationActions()') === 2
        && str_contains($bridge, "formData.append('expectedSemanticRevision', expectedSemanticRevision)")
        && str_contains($bridge, 'this.initData.semanticRevision = resultingSemanticRevision'),
    'all registered mutations must pin the INIT preset, submit CAS and advance exact aggregate revision'
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
    str_contains($aggregate, 'new ElementDataService([], $authority, true)')
        && str_contains($element, '$this->mutationAuthority()'),
    'structural mutations must reuse the authority held by the aggregate coordinator'
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
