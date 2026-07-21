<?php

$integration = file_get_contents(__DIR__ . '/../install/assets/js/integration.js');
$calculator = file_get_contents(__DIR__ . '/../install/assets/js/calculator.js');
$elementDataService = file_get_contents(__DIR__ . '/../lib/Calculator/ElementDataService.php');
$initPayloadService = file_get_contents(__DIR__ . '/../lib/Calculator/InitPayloadService.php');
$presetEnrichmentService = file_get_contents(__DIR__ . '/../lib/Services/PresetEnrichmentService.php');
$installer = file_get_contents(__DIR__ . '/../install/step3.php');
$appBundle = file_get_contents(__DIR__ . '/../install/assets/apps_dist/assets/index.js');
$engineBundlePath = __DIR__ . '/../install/assets/apps_dist/assets/calculationEngine.js';
$engineBundle = is_file($engineBundlePath) ? file_get_contents($engineBundlePath) : $appBundle;

if (!is_string($integration) || !is_string($calculator) || !is_string($elementDataService) || !is_string($initPayloadService) || !is_string($presetEnrichmentService) || !is_string($installer) || !is_string($appBundle) || !is_string($engineBundle)) {
    throw new RuntimeException('Calculator JavaScript sources are unavailable');
}

$checks = [
    [$integration, "offers: offers", 'Save request must submit every offer as one batch'],
    [$integration, "normalizeBatchSaveResults", 'Batch save response must be mapped back to individual offers'],
    [$calculator, "this.expandCalculatorDialog(dialog);", 'Calculator dialog must request expanded mode after Show'],
    [$calculator, ".bx-core-adm-icon-expand", 'Calculator dialog must use the native Bitrix expand action'],
    [$integration, "SAVE_SETTINGS_EQUIPMENT_RESPONSE", 'Equipment saves must report completion to the iframe'],
    [$elementDataService, "['MAX_LENGTH', 'MAX_WIDTH', 'MIN_WIDTH', 'MIN_LENGTH', 'START_COST']", 'Equipment scalar properties must be allowlisted'],
    [$elementDataService, "count(\$fieldParts) !== 4", 'Equipment fields must contain four sides'],
    [$elementDataService, "\$value !== '' && !preg_match", 'Equipment fields must allow individually empty sides'],
    [$elementDataService, "['NAME' => \$equipmentName]", 'Equipment save must update its display name'],
    [$elementDataService, "\$prepared['PARAMETRS']", 'Equipment custom parameters must preserve Bitrix descriptions'],
    [$elementDataService, "'PREVIEW_TEXT', 'DETAIL_TEXT'", 'Calculator context must expose its announcement and full description'],
    [$elementDataService, "strpos(\$value, '|')", 'Custom field values must reject the reserved visibility separator'],
    [$elementDataService, "\$value . '|' . (\$visible ? 'Y' : 'N')", 'Stage custom fields must persist their visibility marker'],
    [$initPayloadService, 'synchronizePresetCustomFields', 'INIT load must repair stale preset custom-field links'],
    [$presetEnrichmentService, "['CALC_DETAILS' => &\$rootIds, 'CALC_CUSTOM_FIELDS' => &\$actual]", 'Preset repair must inspect every linked root detail'],
    [$installer, "'GLOBAL_ASSIGNMENTS' => [", 'Installer must create stage-local global assignment storage'],
    [$installer, "'ACTIVATION_CONDITION' => [", 'Installer must create optional-stage activation storage'],
    [$installer, "'OPTIONS_EQUIPMENT' => [", 'Installer must create equipment mapping storage'],
    [$integration, 'stageWiring.globalAssignments', 'Logic save must send stage-local global assignments'],
    [$integration, "propertyCode: 'GLOBAL_ASSIGNMENTS'", 'Logic save must persist stage-local global assignments'],
    [$integration, "updateStagePropertyInInitDataWithRaw(\n                        stageId,\n                        'GLOBAL_ASSIGNMENTS'", 'INIT cache must receive saved global assignments without reload'],
    [$elementDataService, "\$propertyCode === 'GLOBAL_ASSIGNMENTS'", 'Existing installations must lazily create the global assignment property'],
    [$elementDataService, "'{StageDeleted}'", 'Deleting a stage must mark dependent global values explicitly'],
    [$elementDataService, "'enabled' => true", 'Creating an optional stage must persist its activation marker'],
    [$elementDataService, "\$initPayload['elementsStore']['CALC_STAGES'] as &\$stagePayload", 'Optional stage marker must be reflected in the immediate INIT payload'],
    [$integration, "propertyCode: 'OPTIONS_EQUIPMENT'", 'Equipment matching must persist through the iframe bridge'],
    [$appBundle, 'DESCRIPTION.CODE.', 'Published UI bundle must use stable described-property selectors'],
    [$appBundle, 'FIELDS.VIRTUAL.', 'Published UI bundle must expose virtual printing margin paths'],
    [$appBundle, 'prospektweb.calc.logic-import/v1', 'Published UI bundle must include the versioned logic import contract'],
    [$appBundle, 'Импорт логики', 'Published UI bundle must expose the logic import action'],
    [$appBundle, 'Все этапы пресета', 'Global formula context must expose every preset stage'],
    [$appBundle, 'prospektweb:context-visibility-changed', 'Context visibility settings must synchronize between editors'],
    [$appBundle, 'prospektweb:open-calculation-logic', 'Report rows must open the corresponding calculation logic item'],
    [$appBundle, 'data-logic-target', 'Calculation logic items must expose stable report navigation targets'],
    [$appBundle, 'Схема', 'Published UI bundle must include the visual formula mode'],
    [$appBundle, 'Назад по истории формулы', 'Formula cards must keep undo and redo history for the current editor session'],
    [$appBundle, 'logic-btn-export', 'Logic editor must export the current versioned JSON contract'],
    [$appBundle, 'prospektweb.calc.global-values/v1', 'Global values must use a versioned import and export contract'],
    [$appBundle, 'global-values-import', 'Global values editor must expose JSON import'],
    [$appBundle, 'global-values-export', 'Global values editor must expose JSON export'],
    [$appBundle, 'btn-generate-logic-prompt', 'Stage calculator must expose the AI logic prompt action'],
    [$appBundle, 'prospektweb.calc.logic-import/v1', 'AI prompt must require the supported logic import contract'],
    [$appBundle, 'defaultValue', 'Calculation reports must open their first detail level by default'],
    [$engineBundle, 'OPTIONS_EQUIPMENT', 'Published calculation engine must apply equipment mapping'],
    [$engineBundle, 'OUTPUTS_RUNTIME', 'Published calculation engine must preserve legacy runtime output paths'],
];

foreach ($checks as [$source, $needle, $message]) {
    if (strpos($source, $needle) === false) {
        throw new RuntimeException($message);
    }
}

if (strpos($integration, 'saveCalculationForOffer') !== false) {
    throw new RuntimeException('Save flow must not make one HTTP request per offer');
}

foreach ([$calculator, $integration] as $source) {
    if (preg_match('/\b(?:alert|confirm|prompt)\s*\(/', $source) === 1) {
        throw new RuntimeException('Calculator UI must not use native browser dialogs');
    }
}

echo "Calculator UI static tests passed\n";
