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
$changeStart = strpos($service, 'public function changePricePreset(');
$membershipStart = strpos($service, 'private function assertAndLockPreset(');
$normalizeStart = strpos($service, 'private function normalizePriceProfilePolicy(');
$changeSource = is_int($changeStart) && is_int($membershipStart)
    ? substr($service, $changeStart, $membershipStart - $changeStart)
    : '';
$membershipSource = is_int($membershipStart) && is_int($normalizeStart)
    ? substr($service, $membershipStart, $normalizeStart - $membershipStart)
    : '';
$assert(
    $changeSource !== ''
        && strpos($changeSource, '$this->assertAndLockPreset($presetId);')
            < strpos($changeSource, 'new CatalogPriceService()')
        && strpos($changeSource, '$this->assertAndLockPreset($presetId);')
            < strpos($changeSource, 'syncPriceRangesMultiType(')
        && strpos($changeSource, '$this->assertAndLockPreset($presetId);')
            < strpos($changeSource, '$this->savePriceLimits('),
    'price writes require exact preset membership before catalog or property DML'
);
$assert(
    $membershipSource !== ''
        && strpos($membershipSource, 'Application::getConnection()->query(') !== false
        && strpos($membershipSource, 'WHERE ID = ') !== false
        && strpos($membershipSource, 'AND IBLOCK_ID = ') !== false
        && strpos($membershipSource, 'FOR UPDATE') !== false
        && strpos($membershipSource, ')->fetch()') !== false
        && strpos($membershipSource, 'exact pinned CALC_PRESETS iblock') !== false,
    'preset-price membership is locked and verified against the pinned presets iblock'
);
$assert(strpos($init, "['CODE' => 'PRICE_PROFILE_POLICY_JSON']") !== false, 'policy is loaded into INIT');
$assert(strpos($bridge, 'priceProfilePolicy: priceProfilePolicy') !== false, 'bridge sends policy with the price transaction');

echo "OK\n";
