<?php

$integration = file_get_contents(__DIR__ . '/../install/assets/js/integration.js');
$calculator = file_get_contents(__DIR__ . '/../install/assets/js/calculator.js');
$elementDataService = file_get_contents(__DIR__ . '/../lib/Calculator/ElementDataService.php');
$detailHandler = file_get_contents(__DIR__ . '/../lib/Services/DetailHandler.php');
$customFieldsService = file_get_contents(__DIR__ . '/../lib/Services/CustomFieldsService.php');
$initPayloadService = file_get_contents(__DIR__ . '/../lib/Calculator/InitPayloadService.php');
$presetEnrichmentService = file_get_contents(__DIR__ . '/../lib/Services/PresetEnrichmentService.php');
$catalogMetaService = file_get_contents(__DIR__ . '/../lib/Services/CatalogMetaService.php');
$aiGatewayService = file_get_contents(__DIR__ . '/../lib/Services/AiGatewayService.php');
$installer = file_get_contents(__DIR__ . '/../install/step3.php');
$appBundle = file_get_contents(__DIR__ . '/../install/assets/apps_dist/assets/index.js');
$engineBundlePath = __DIR__ . '/../install/assets/apps_dist/assets/calculationEngine.js';
$engineBundle = is_file($engineBundlePath) ? file_get_contents($engineBundlePath) : $appBundle;

if (!is_string($integration) || !is_string($calculator) || !is_string($elementDataService) || !is_string($detailHandler) || !is_string($customFieldsService) || !is_string($initPayloadService) || !is_string($presetEnrichmentService) || !is_string($catalogMetaService) || !is_string($aiGatewayService) || !is_string($installer) || !is_string($appBundle) || !is_string($engineBundle)) {
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
    [$elementDataService, "'PREVIEW_TEXT' => \$equipmentPreviewText", 'Equipment save must update its announcement together with the display name'],
    [$elementDataService, "\$prepared['PARAMETRS']", 'Equipment custom parameters must preserve Bitrix descriptions'],
    [$elementDataService, "explode('|', \$description, 3)", 'Equipment parameters must persist value, title and description through the reserved separator'],
    [$elementDataService, "\$prepared['SOURCE_LINKS']", 'Equipment source links must be persisted as a multiple Bitrix property'],
    [$elementDataService, "explode('|', \$description, 2)", 'Equipment source links must persist title and description through the reserved separator'],
    [$elementDataService, "'IBLOCK_SECTION_ID' => \$sectionId > 0 ? \$sectionId : false", 'New equipment must be created in the selected section'],
    [$elementDataService, "'CODE' => \$this->makeUniqueElementCode", 'New equipment must receive a unique symbolic code'],
    [$integration, "create,\n                    sectionId:", 'Equipment save bridge must support creation in a selected section'],
    [$elementDataService, "'PREVIEW_TEXT', 'DETAIL_TEXT'", 'Calculator context must expose its announcement and full description'],
    [$elementDataService, "strpos(\$value, '|')", 'Custom field values must reject the reserved visibility separator'],
    [$elementDataService, "\$value . '|' . (\$visible ? 'Y' : 'N')", 'Stage custom fields must persist their visibility marker'],
    [$initPayloadService, 'synchronizePresetCustomFields', 'INIT load must repair stale preset custom-field links'],
    [$presetEnrichmentService, "['CALC_DETAILS' => &\$rootIds, 'CALC_CUSTOM_FIELDS' => &\$actual]", 'Preset repair must inspect every linked root detail'],
    [$installer, "'GLOBAL_ASSIGNMENTS' => [", 'Installer must create stage-local global assignment storage'],
    [$installer, "'ACTIVATION_CONDITION' => [", 'Installer must create stage activation storage'],
    [$installer, "'OPTIONS_EQUIPMENT' => [", 'Installer must create equipment mapping storage'],
    [$installer, "'SOURCE_LINKS' => ['NAME' => 'Ссылки на источники данных'", 'Installer must create source-link properties for technical catalogs'],
    [$integration, 'stageWiring.globalAssignments', 'Logic save must send stage-local global assignments'],
    [$integration, "propertyCode: 'GLOBAL_ASSIGNMENTS'", 'Logic save must persist stage-local global assignments'],
    [$integration, "updateStagePropertyInInitDataWithRaw(\n                        stageId,\n                        'GLOBAL_ASSIGNMENTS'", 'INIT cache must receive saved global assignments without reload'],
    [$elementDataService, "\$propertyCode === 'GLOBAL_ASSIGNMENTS'", 'Existing installations must lazily create the global assignment property'],
    [$elementDataService, "'{StageDeleted}'", 'Deleting a stage must mark dependent global values explicitly'],
    [$integration, "case 'SAVE_STAGE_ACTIVATION_REQUEST'", 'Every stage must support an optional activation condition'],
    [$integration, "propertyCode: 'ACTIVATION_CONDITION'", 'Stage activation condition must be persisted in Bitrix'],
    [$integration, "case 'CREATE_CUSTOM_FIELD_REQUEST'", 'Stage settings must route inline custom-field creation through Bitrix'],
    [$integration, 'payload.customFieldIds', 'Custom fields must be selected by the internal UI instead of a Bitrix picker'],
    [$integration, "case 'CHANGE_ROOT_DETAIL_SORT_REQUEST'", 'Detail columns must support persisted root-level reordering'],
    [$elementDataService, "case 'createCustomField':", 'Bitrix handler must support inline custom-field creation'],
    [$elementDataService, "'PREVIEW_TEXT' => trim((string)(\$field['description'] ?? ''))", 'A created custom field must persist its description'],
    [$customFieldsService, "'description' => trim(strip_tags((string)(\$element['PREVIEW_TEXT'] ?? '')))", 'Custom-field metadata must expose descriptions to the internal selector'],
    [$elementDataService, "'DESCRIPTION' => (string)(\$field['defaultValue'] ?? '') . '|Y'", 'A newly created custom field must be activated for its stage immediately'],
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
    [$catalogMetaService, "'CALC_OPERATIONS_VARIANTS'", 'Operation variants must use the canonical configured iblock code'],
    [$catalogMetaService, "'CALC_MATERIALS_VARIANTS'", 'Material variants must use the canonical configured iblock code'],
    [$catalogMetaService, "'description' => trim", 'Catalog parameters must expose their human-readable descriptions'],
    [$catalogMetaService, "implode('|', [\$parameter['value'], \$parameter['title'], \$parameter['description']])", 'Catalog parameters must persist value, title and description in Bitrix DESCRIPTION'],
    [$aiGatewayService, "private const DEFAULT_MODEL = 'openai/gpt-5.4-mini'", 'AI prompt templates must default to GPT-5.4 mini'],
    [$appBundle, 'btn-operation-card', 'Operation row must expose one unified parent and variants card'],
    [$appBundle, 'btn-material-card', 'Material row must expose one unified parent and variants card'],
    [$appBundle, 'btn-create-operation', 'Operation row must support creation in the current section'],
    [$appBundle, 'btn-create-material', 'Material row must support creation in the current section'],
    [$catalogMetaService, "'DETAIL_TEXT_TYPE' => 'html'", 'Operation and material cards must persist full HTML descriptions'],
    [$catalogMetaService, "'SOURCE_LINKS' => \$sourceLinks", 'Operation and material cards must persist ordered source links'],
    [$catalogMetaService, "'createdVariantId' => \$createdVariantId", 'Catalog creation must return the new selectable variant'],
    [$catalogMetaService, "'adminUrl' => \$this->buildAdminUrl", 'Unified technical cards must expose their Bitrix element link beside the internal ID'],
    [$appBundle, 'Описание параметра', 'All parameter editors must expose the third human-readable description field'],
    [$appBundle, 'btn-stage-settings', 'Every stage tab must expose unified settings'],
    [$appBundle, 'detail-kanban-board', 'Published UI must expose the detail-column kanban board'],
    [$appBundle, 'virtual-detail-column', 'Published UI must expose virtual detail-column placeholders'],
    [$appBundle, 'stage-folder-body', 'Published UI must expose the attached stage-folder body'],
    [$appBundle, 'data-stage-drop-slot', 'Published UI must expose stable stage drop slots'],
    [$appBundle, 'stageDragOverlay', 'Published UI must move an exact stage-card clone during drag'],
    [$appBundle, 'Активировать этап по условию', 'Stage settings must expose conditional activation'],
    [$appBundle, 'stage-activation-condition-select', 'Stage settings must select a global activation flag'],
    [$appBundle, 'Дополнительные параметры этапа', 'Stage settings must own custom-field selection'],
    [$appBundle, 'Создать', 'Stage settings must create a custom field inline'],
    [$appBundle, 'Описание отсутствует', 'Equipment settings must show an explicit empty-description state'],
    [$appBundle, 'defaultValue', 'Calculation reports must open their first detail level by default'],
    [$engineBundle, 'OPTIONS_EQUIPMENT', 'Published calculation engine must apply equipment mapping'],
    [$engineBundle, 'OUTPUTS_RUNTIME', 'Published calculation engine must preserve legacy runtime output paths'],
    [$detailHandler, 'FOR UPDATE', 'Stage mutations must lock detail rows before validating their exact stage lists'],
    [$detailHandler, 'count($sorting) !== count(array_unique($sorting))', 'Same-detail reorder must reject duplicate stage IDs'],
    [$detailHandler, 'count($sourceSorting) !== count(array_unique($sourceSorting))', 'Cross-detail move must reject duplicate stage IDs'],
    [$detailHandler, '$connection->startTransaction()', 'Stage mutations must run inside a database transaction'],
    [$detailHandler, '$connection->rollbackTransaction()', 'Failed stage mutations must roll back'],
    [$integration, "this.sendPwrtMessage('PROCESS_MESSAGE', {", 'Committed stage mutations must have a correlated success acknowledgement even when enrichment fails'],
    [$elementDataService, "'enrichmentWarning'", 'Enrichment failure must not relabel an already committed stage mutation as failed'],
    [$elementDataService, "case 'changeRootDetailSort':", 'Root detail-column order must be handled by the server'],
    [$elementDataService, "'CALC_DETAILS' => \$sorting", 'Root detail-column order must be written exactly'],
];

foreach ($checks as [$source, $needle, $message]) {
    if (strpos($source, $needle) === false) {
        throw new RuntimeException($message);
    }
}

if (strpos($integration, 'saveCalculationForOffer') !== false) {
    throw new RuntimeException('Save flow must not make one HTTP request per offer');
}

if (strpos($appBundle, 'btn-generate-logic-prompt') !== false) {
    throw new RuntimeException('Deprecated calculator prompt generator must not remain in the published UI bundle');
}

foreach ([$calculator, $integration] as $source) {
    if (preg_match('/\b(?:alert|confirm|prompt)\s*\(/', $source) === 1) {
        throw new RuntimeException('Calculator UI must not use native browser dialogs');
    }
}

echo "Calculator UI static tests passed\n";
