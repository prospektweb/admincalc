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

$assert(strpos($schema, "'OFFER_NAME_TEMPLATE'") !== false, 'Runtime schema must include OFFER_NAME_TEMPLATE');
$assert(strpos($installer, "'CURRENCY' => 'MRG'") !== false, 'Installer must create MRG currency');
$assert(strpos($payload, "'priceSettingsPresets'") !== false, 'INIT context must expose saved price settings presets');
$assert(strpos($priceService, 'syncPriceRangesMultiType') !== false, 'Price changes must use non-destructive range synchronization');
$assert(strpos($presetService, 'PRICE_SETTINGS_PRESETS_JSON') !== false, 'Named price presets must be stored in module options');
$assert(strpos($bridge, 'SAVE_PRICE_SETTINGS_PRESET_REQUEST') !== false, 'Integration bridge must handle named price presets');
$assert(strpos($actions, "case 'savePriceSettingsPreset'") !== false, 'AJAX handler must persist named price presets');
$assert(strpos($actions, "'OFFER_NAME_TEMPLATE'") !== false, 'Preset meta save must persist offer name template separately');

echo "Price settings and offer name static tests passed\n";
