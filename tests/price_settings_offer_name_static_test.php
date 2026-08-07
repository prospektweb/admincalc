<?php

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$schema = $read('lib/Install/SchemaRepairService.php');
$installer = $read('install/step3.php');
$payload = $read('lib/Calculator/InitPayloadService.php');
$priceService = $read('lib/Services/PresetPriceService.php');
$presetService = $read('lib/Services/PriceSettingsPresetService.php');
$bridge = $read('install/assets/js/integration.js');
$actions = $read('lib/Calculator/ElementDataService.php');
$propertyPayload = $read('lib/Calculator/PropertyPayloadLoader.php');

$assert(strpos($schema, "'OFFER_NAME_TEMPLATE'") !== false, 'Runtime schema must include OFFER_NAME_TEMPLATE');
$assert(strpos($schema, "'PRICE_LIMITS_JSON'") !== false, 'Runtime schema must include PRICE_LIMITS_JSON');
$assert(strpos($installer, "'PRICE_LIMITS_JSON' => [") !== false, 'Installer must create PRICE_LIMITS_JSON');
$assert(strpos($installer, "'CURRENCY' => 'MRG'") !== false, 'Installer must create MRG currency');
$assert(strpos($payload, "'priceSettingsPresets'") !== false, 'INIT context must expose saved price settings presets');
$assert(strpos($priceService, 'syncPriceRangesMultiType') !== false, 'Price changes must use non-destructive range synchronization');
$assert(strpos($priceService, 'savePriceLimits') !== false, 'Preset price save must persist RUB limits');
$assert(strpos($priceService, 'prospektweb.calc.price-limits/v1') !== false, 'RUB limits need a versioned payload');
$assert(strpos($presetService, 'PRICE_SETTINGS_PRESETS_JSON') !== false, 'Named price presets must be stored in module options');
$assert(strpos($presetService, "'limitRub'") !== false, 'Named price templates must preserve RUB limits');
$assert(strpos($payload, 'mergePriceLimits') !== false, 'INIT prices must restore RUB limits');
$assert(strpos($payload, '$quantityFrom > 0') !== false, 'Open range zero must match Bitrix null when restoring RUB limits');
$assert(strpos($bridge, 'SAVE_PRICE_SETTINGS_PRESET_REQUEST') !== false, 'Integration bridge must handle named price presets');
$assert(strpos($actions, "case 'savePriceSettingsPreset'") !== false, 'AJAX handler must persist named price presets');
$assert(strpos($actions, 'OFFER_NAME_TEMPLATE') === false, 'Preset meta save must not persist the retired offer name template');
$assert(strpos($bridge, 'offerNameTemplate') === false, 'Integration bridge must not send the retired offer name template');
$assert(substr_count($propertyPayload, "'VALUE_ENUM'") >= 4, 'Property payload must preserve readable enum values');
$assert(substr_count($propertyPayload, "'WITH_DESCRIPTION'") >= 3, 'Property payload must preserve the description capability used by parameter menus');
$assert(substr_count($propertyPayload, "'USER_TYPE'") >= 3, 'Property payload must preserve property user types');

echo "Price settings and stage offer name static tests passed\n";
