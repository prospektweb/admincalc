<?php

$options = file_get_contents(__DIR__ . '/../options.php');
$defaults = file_get_contents(__DIR__ . '/../default_option.php');
$messages = file_get_contents(__DIR__ . '/../lang/ru/options.php');
$initPayload = file_get_contents(__DIR__ . '/../lib/Calculator/InitPayloadService.php');

if (!is_string($options) || !is_string($defaults) || !is_string($messages) || !is_string($initPayload)) {
    throw new RuntimeException('Module settings sources are unavailable');
}

$removedFields = [
    'DEFAULT_PRICE_TYPE_ID',
    'DEFAULT_CURRENCY',
    'FORMAT_FIELD_CODE',
    'VOLUME_FIELD_CODE',
    'IBLOCK_MATERIALS',
    'IBLOCK_OPERATIONS',
    'IBLOCK_EQUIPMENT',
    'IBLOCK_DETAILS',
    'IBLOCK_CALCULATORS',
    'IBLOCK_CONFIGURATIONS',
];

foreach ($removedFields as $field) {
    if (strpos($options, 'name="' . $field . '"') !== false) {
        throw new RuntimeException("Unused admin setting {$field} must not be rendered");
    }
    if (strpos($options, "Option::set(\$module_id, '{$field}'") !== false) {
        throw new RuntimeException("Unused admin setting {$field} must not be persisted");
    }
    if (strpos($defaults, "'{$field}' =>") !== false) {
        throw new RuntimeException("Unused admin setting {$field} must not be registered as a default");
    }
}

$dialogOwnedFields = [
    'PRICE_ROUNDING',
];

foreach ($dialogOwnedFields as $field) {
    if (strpos($options, 'name="' . $field . '"') !== false) {
        throw new RuntimeException("Dialog-owned setting {$field} must not be rendered on the module settings page");
    }
    if (strpos($options, "Option::set(\$module_id, '{$field}'") !== false) {
        throw new RuntimeException("Dialog-owned setting {$field} must not be persisted by the module settings page");
    }
}

$activeFields = [
    'DEFAULT_EXTRA_VALUE',
    'DEFAULT_EXTRA_CURRENCY_VALUE',
    'SAVE_CALC_HISTORY',
    'CALC_HISTORY_LIMIT',
    'LOGGING_ENABLED',
    'MARKUP_BASE_PRICE_TYPE_ID',
    'CALC_SERVER_URL',
    'ASPRO_AI_TIMEWEB_ENABLED',
    'ASPRO_AI_TIMEWEB_BASE_URL',
];

foreach ($activeFields as $field) {
    if (strpos($options, 'name="' . $field . '"') === false) {
        throw new RuntimeException("Active admin setting {$field} must remain available");
    }
}

if (substr_count($options, "['DIV' => 'edit") !== 5) {
    throw new RuntimeException('Settings UI must expose exactly five task-oriented tabs');
}

$tabOrder = [
    'PROSPEKTWEB_CALC_TAB_MAIN',
    'PROSPEKTWEB_CALC_TAB_SERVICE',
    'PROSPEKTWEB_CALC_TAB_IBLOCKS',
    'PROSPEKTWEB_CALC_TAB_INTEGRATION',
    'PROSPEKTWEB_CALC_TAB_DIAGNOSTIC',
];
$lastPosition = -1;
foreach ($tabOrder as $messageKey) {
    $position = strpos($options, "Loc::getMessage('{$messageKey}')");
    if ($position === false || $position <= $lastPosition) {
        throw new RuntimeException('Settings tabs must follow the intended task order');
    }
    $lastPosition = $position;
}

if (
    strpos($options, "SetTitle(Loc::getMessage('PROSPEKTWEB_CALC_OPTIONS_TITLE'))") === false
    || strpos($options, "pwcalc-history-limit-row") === false
    || strpos($options, 'syncHistoryLimitVisibility') === false
) {
    throw new RuntimeException('Settings page must have a specific title and contextual history-limit visibility');
}

if (
    strpos($initPayload, "'defaultExtraValue' => \$settingsManager->getDefaultExtraValue()") === false
    || strpos($initPayload, "'defaultExtraCurrency' => \$settingsManager->getDefaultExtraCurrency()") === false
) {
    throw new RuntimeException('Default new-price markup settings must remain connected to the calculator editor');
}

foreach ([
    'PROSPEKTWEB_CALC_ACTIVE_SETTINGS_HINT',
    'PROSPEKTWEB_CALC_TAB_SERVICE',
    'PROSPEKTWEB_CALC_SNAPSHOT_HEADING',
] as $messageKey) {
    if (strpos($messages, "\$MESS['{$messageKey}']") === false) {
        throw new RuntimeException("Missing settings UI localization: {$messageKey}");
    }
}

echo "Options UI static tests passed\n";
