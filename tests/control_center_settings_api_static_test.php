<?php

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/tools/control_center_settings.php');
$service = file_get_contents($root . '/lib/Services/ControlCenterSettingsService.php');
$options = file_get_contents($root . '/options.php');
$autoload = file_get_contents($root . '/include.php');
$installer = file_get_contents($root . '/install/index.php');
$diagnostic = file_get_contents($root . '/lib/Diagnostic/ModuleDiagnostic.php');
$diagnosticEndpoint = file_get_contents($root . '/tools/diagnostic.php');

foreach (compact('endpoint', 'service', 'options', 'autoload', 'installer', 'diagnostic', 'diagnosticEndpoint') as $name => $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Missing control-center settings source: ' . $name);
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(strpos($endpoint, "REQUEST_METHOD'] ?? '') !== 'POST'") !== false, 'Settings API must accept POST only');
$assert(strpos($endpoint, 'check_bitrix_sessid()') !== false, 'Settings API must enforce Bitrix CSRF protection');
$assert(strpos($endpoint, '$USER->IsAdmin()') !== false, 'Settings API must require an administrator');
$assert(strpos($endpoint, "'action'] ?? 'get'") !== false, 'Settings API must expose a read action');
$assert(strpos($endpoint, "if (\$action === 'save')") !== false, 'Settings API must expose a save action');
$assert(strpos($endpoint, "'REVISION_CONFLICT'") !== false, 'Settings API must expose optimistic concurrency conflicts');
$assert(strpos($endpoint, "'VALIDATION_ERROR'") !== false, 'Settings API must expose validation errors');
$assert(strpos($endpoint, "header('Cache-Control: no-store, private')") !== false, 'Settings responses must not be cached');

foreach ([
    'defaultExtraValue',
    'defaultExtraCurrency',
    'loggingEnabled',
    'basePriceTypeId',
    'priceTypes',
    'calcServerUrl',
    'asproAiEnabled',
    'asproAiBaseUrl',
    'patchStatus',
    'directories',
    'revision',
] as $field) {
    $assert(strpos($service, "'{$field}'") !== false, 'Settings service must own field ' . $field);
}

$assert(strpos($service, "hash('sha256'") !== false && strpos($service, 'hash_equals(') !== false, 'Settings writes must use an optimistic revision');
$assert(strpos($service, 'flock($handle, LOCK_EX)') !== false, 'Settings revision check and writes must be serialized');
$assert(strpos($service, 'normalizeSettings(') !== false, 'Settings must be normalized before persistence');
$assert(strpos($service, 'persistSettings($this->normalizeSettings($settings, $current))') !== false, 'Legacy writes must reuse modern normalization and persistence');
$assert(strpos($options, '$controlCenterSettingsService->saveLegacyPost($_POST)') !== false, 'Legacy Bitrix settings must delegate to the shared service');
$assert(strpos($options, '$controlCenterSettingsService->getSettings()') !== false, 'Legacy Bitrix settings must read from the shared service');
$assert(strpos($options, '$controlCenterSettingsService->saveAsproIntegration(') !== false, 'Legacy patch actions must reuse integration validation');

$assert(strpos($autoload, "'Prospektweb\\\\Calc\\\\Services\\\\ControlCenterSettingsService'") !== false, 'Settings service must be registered in module autoload');
$assert(strpos($installer, 'CopyDirFiles($sourceTools, $targetTools, true, true)') !== false, 'Installer must publish the settings endpoint with owned tools');
$assert(strpos($installer, "\$toolsDir . '/control_center_settings.php'") !== false, 'Installer integrity must verify the published settings endpoint');
$assert(substr_count($diagnostic, "'tools/control_center_settings.php'") >= 1, 'Module diagnostics must verify the settings endpoint source');
$assert(strpos($diagnostic, "'control_center_settings.php'") !== false, 'Module diagnostics must verify the public settings endpoint');
$assert(strpos($diagnosticEndpoint, "REQUEST_METHOD'] ?? '') !== 'POST'") !== false, 'Diagnostics API must accept POST only');
$assert(strpos($diagnosticEndpoint, 'check_bitrix_sessid()') !== false && strpos($diagnosticEndpoint, '$USER->IsAdmin()') !== false, 'Diagnostics API must require CSRF and administrator access');
$assert(strpos($diagnosticEndpoint, "header('Cache-Control: no-store, private')") !== false, 'Diagnostics responses must not be cached');

echo "Control center settings API static tests passed\n";
