<?php

$root = dirname(__DIR__);
$service = file_get_contents($root . '/lib/Calculator/ElementDataService.php');
$enrichment = file_get_contents($root . '/lib/Services/PresetEnrichmentService.php');
$integration = file_get_contents($root . '/install/assets/js/integration.js');
$installer = file_get_contents($root . '/install/step3.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(strpos($service, "case 'saveStageUsedEntities':") !== false, 'stage entity capabilities have a dedicated backend action');
$assert(strpos($service, "SetPropertyValuesEx(\$stageId, \$stagesIblockId") !== false, 'custom fields are written to the stage');
$assert(strpos($integration, "'SAVE_STAGE_USED_ENTITIES_REQUEST'") !== false, 'postMessage bridge handles stage entity capabilities');
$assert(
    strpos($integration, "action: 'selectFields'") !== false
    && strpos($integration, 'customFieldIds: selectedIds') !== false,
    'custom field selection no longer requires calculator settings'
);
$assert(strpos($enrichment, "\$stage['CUSTOM_FIELDS']") !== false, 'preset enrichment reads stage custom fields first');
$assert(strpos($enrichment, 'Legacy fallback') !== false, 'legacy calculator custom fields retain a fallback');

$stagesPos = strpos($installer, '$stagesProps = [');
$settingsPos = strpos($installer, '$settingsProps = [');
$logicPos = strpos($installer, "'LOGIC_JSON'", $settingsPos);
$assert($stagesPos !== false && $settingsPos !== false && $logicPos !== false, 'installer property sections are present');
$assert(strpos(substr($installer, $stagesPos, $settingsPos - $stagesPos), "'USED_ENTITY_CODES'") !== false, 'fresh installs put stable entity codes on stages');
$assert(strpos(substr($installer, $settingsPos, $logicPos - $settingsPos), "'USED_ENTITYS'") === false, 'fresh installs do not put USED_ENTITYS on calculators');
$assert(strpos(substr($installer, $settingsPos, $logicPos - $settingsPos), "'CUSTOM_FIELDS'") === false, 'fresh installs do not put CUSTOM_FIELDS on calculators');

echo "OK\n";
