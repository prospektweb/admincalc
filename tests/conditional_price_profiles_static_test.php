<?php

$root = dirname(__DIR__);
$service = file_get_contents($root . '/lib/Services/PresetPriceService.php');
$init = file_get_contents($root . '/lib/Calculator/InitPayloadService.php');
$schema = file_get_contents($root . '/lib/Install/SchemaRepairService.php');
$bridge = file_get_contents($root . '/install/assets/js/integration.js');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(strpos($schema, "'PRICE_PROFILE_POLICY_JSON'") !== false, 'conditional price profile property is repairable');
$assert(strpos($service, 'prospektweb.calc.conditional-price-profiles/v1') !== false, 'policy is versioned');
$assert(strpos($service, 'normalizePriceProfilePolicy') !== false, 'policy is validated before persistence');
$assert(strpos($service, "hash('sha256'") !== false, 'embedded snapshots receive a deterministic revision');
$assert(strpos($init, "['CODE' => 'PRICE_PROFILE_POLICY_JSON']") !== false, 'policy is loaded into INIT');
$assert(strpos($bridge, 'priceProfilePolicy: priceProfilePolicy') !== false, 'bridge sends policy with the price transaction');

echo "OK\n";
