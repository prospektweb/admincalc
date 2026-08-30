<?php

$source = file_get_contents(__DIR__ . '/../lib/Calculator/InitPayloadService.php');
if (!is_string($source)) {
    throw new RuntimeException('InitPayloadService source is unavailable.');
}

$start = strpos($source, 'public function prepareVersionEditorInitPayloadReadOnly(');
$end = strpos($source, 'public function prepareVersionSnapshotInitPayloadReadOnly(', $start ?: 0);
if ($start === false || $end === false || $end <= $start) {
    throw new RuntimeException('Version editor INIT boundary was not found.');
}
$versionEditorMethod = substr($source, $start, $end - $start);

$checks = [
    [$versionEditorMethod, '$this->projectVersionEditorStructuralGraphReadOnly($workingPresetId, $preset);', 'Version editor INIT must project the current isolated graph after loading its preset.'],
    [$source, '$authority->withAuthorityLock(', 'Structural projection must hold calculator authority while reading the graph.'],
    [$source, '$authority->readLockedPresetGraph($workingPresetId)', 'Structural projection must use the authoritative locked graph.'],
    [$source, "'CALC_DETAILS' => ['iblock' => 'CALC_DETAILS', 'ids' => \$graph['detailIds']]", 'Every detail in the graph must be loaded explicitly.'],
    [$source, "'CALC_STAGES' => ['iblock' => 'CALC_STAGES', 'ids' => \$graph['stageIds']]", 'Every stage in the graph must be loaded explicitly.'],
    [$source, "'CALC_SETTINGS' => ['iblock' => 'CALC_SETTINGS', 'ids' => \$graph['settingsIds']]", 'Every settings element in the graph must be loaded explicitly.'],
    [$source, "\$preset['properties']['CALC_DETAILS'] = \$graph['rootDetailIds'];", 'Preset detail roots must come from rootDetailIds.'],
    [$source, "\$preset['properties']['CALC_STAGES'] = \$graph['stageIds'];", 'Preset stage index must come from stageIds.'],
    [$source, "\$preset['properties']['CALC_SETTINGS'] = \$graph['directSettingsIds'];", 'Preset settings index must contain only direct settings.'],
];
foreach ($checks as [$haystack, $needle, $message]) {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message);
    }
}

$neutralStart = strpos($source, 'public function prepareNeutralInitPayloadReadOnly(');
$neutralEnd = strpos($source, 'public function prepareVersionEditorInitPayloadReadOnly(', $neutralStart ?: 0);
$neutralMethod = ($neutralStart !== false && $neutralEnd !== false)
    ? substr($source, $neutralStart, $neutralEnd - $neutralStart)
    : '';
if (str_contains($neutralMethod, 'projectVersionEditorStructuralGraphReadOnly')) {
    throw new RuntimeException('Public/neutral INIT must not use the version-editor graph projection.');
}

echo "Version editor graph projection static checks passed\n";
