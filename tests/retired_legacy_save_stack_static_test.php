<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$retiredFiles = [
    'lib/Calculator/SaveHandler.php',
    'lib/Calculator/CalculationHistoryHandler.php',
    'lib/Services/SaveAllService.php',
];

foreach ($retiredFiles as $relativePath) {
    $assert(!is_file($root . '/' . $relativePath), 'Retired legacy save file remains: ' . $relativePath);
}

$endpoint = file_get_contents($root . '/tools/calculator_ajax.php');
$autoload = file_get_contents($root . '/include.php');
$bundle = file_get_contents($root . '/lib/Calculator/BundleHandler.php');
$diagnostic = file_get_contents($root . '/lib/Diagnostic/ModuleDiagnostic.php');
$installer = file_get_contents($root . '/install/index.php');

foreach ([$endpoint, $autoload, $bundle, $diagnostic, $installer] as $source) {
    $assert(is_string($source), 'Unable to read a clean-cut save-stack source');
}

foreach ([
    'use Prospektweb\\Calc\\Calculator\\SaveHandler;',
    'use Prospektweb\\Calc\\Calculator\\BundleHandler;',
    'function handleSave(',
    'function handleSaveBundle(',
    'function handleFinalizeBundle(',
] as $retiredEndpointToken) {
    $assert(strpos($endpoint, $retiredEndpointToken) === false, 'Legacy AJAX save entry remains: ' . $retiredEndpointToken);
}

foreach (['SaveHandler', 'CalculationHistoryHandler', 'SaveAllService'] as $retiredClass) {
    $assert(strpos($autoload, $retiredClass) === false, 'Retired save class remains autoloaded: ' . $retiredClass);
    $assert(strpos($diagnostic, $retiredClass) === false, 'Diagnostic still requires retired save class: ' . $retiredClass);
}

foreach ([
    'public function savePreset(',
    'public function finalizePreset(',
    'public function deletePreset(',
    'public function loadPresetsSummary(',
    'function buildPropertyValues(',
    'linkedElements',
] as $retiredBundleToken) {
    $assert(strpos($bundle, $retiredBundleToken) === false, 'BundleHandler still exposes a legacy direct writer: ' . $retiredBundleToken);
}

$assert(strpos($bundle, 'public function createStandalonePreset(') !== false, 'Canonical standalone-preset primitive was removed');
$assert(strpos($bundle, 'public function clonePresetLocked(') !== false, 'Canonical locked clone primitive was removed');

foreach ($retiredFiles as $relativePath) {
    $cleanup = "\$this->modulePath . '/" . $relativePath . "'";
    $assert(strpos($installer, $cleanup) !== false, 'Installer does not delete retired save file: ' . $relativePath);
}

echo "Retired legacy save-stack static tests passed\n";
