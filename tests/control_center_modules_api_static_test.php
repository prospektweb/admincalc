<?php

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/tools/control_center_modules.php');
$service = file_get_contents($root . '/lib/Services/ModuleCapabilityRegistryService.php');
$autoload = file_get_contents($root . '/include.php');
$installer = file_get_contents($root . '/install/index.php');
$diagnostic = file_get_contents($root . '/lib/Diagnostic/ModuleDiagnostic.php');
$host = file_get_contents($root . '/admin/prospektweb_calc_control_center.php');
$version = file_get_contents($root . '/install/version.php');

foreach (compact('endpoint', 'service', 'autoload', 'installer', 'diagnostic', 'host', 'version') as $name => $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Missing control-plane source: ' . $name);
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(strpos($endpoint, "\$requestMethod !== 'POST'") !== false, 'Modules API must accept POST only');
$assert(strpos($endpoint, 'check_bitrix_sessid()') !== false, 'Modules API must enforce Bitrix CSRF protection');
$assert(strpos($endpoint, '$USER->IsAdmin()') !== false, 'Modules API must require an administrator');
$assert(strpos($endpoint, "'application/x-www-form-urlencoded'") !== false, 'Modules API must accept form-urlencoded requests');
$assert(strpos($endpoint, "array_key_exists('payload', \$_POST)") !== false, 'Modules API must accept the form payload envelope');
$assert(strpos($endpoint, "file_get_contents('php://input')") !== false, 'Modules API must preserve raw JSON compatibility');
$assert(strpos($endpoint, "substr(\$value, 0, 1) !== '{'") !== false, 'Modules API must reject non-object JSON roots');
$sessionHydration = strpos($endpoint, "\$_REQUEST['sessid'] = \$requestSessid");
$postSessionHydration = strpos($endpoint, "\$_POST['sessid'] = \$requestSessid");
$adminProlog = strpos($endpoint, "require_once \$_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php'");
$assert($sessionHydration !== false && $postSessionHydration !== false && $adminProlog !== false && $sessionHydration < $adminProlog && $postSessionHydration < $adminProlog, 'JSON sessid must hydrate request and POST before the Bitrix admin prolog');
$assert(strpos($endpoint, "'action'] ?? 'get'") !== false, 'Modules API must expose a get action');
$assert(strpos($endpoint, "if (\$action === 'set')") !== false, 'Modules API must expose a set action');
$assert(strpos($endpoint, "\$request['capabilityId']") !== false && strpos($endpoint, "\$request['revision']") !== false, 'Set must consume the synchronized capability and revision fields');
$assert(strpos($endpoint, "'REVISION_CONFLICT'") !== false, 'Modules API must expose optimistic concurrency conflicts');
$assert(strpos($endpoint, "header('Cache-Control: no-store, private')") !== false, 'Modules responses must not be cached');

$assert(strpos($service, "public const CONTRACT = 'prospektweb.control-plane/catalog/v1'") !== false, 'Registry contract must be versioned');
$assert(substr_count($service, "'id' => 'prospektweb.") >= 5, 'Registry must statically allowlist canonical module IDs');
$assert(strpos($service, "'id' => 'prospektweb.layoutfiles'") !== false, 'Registry must use the canonical layoutfiles module ID');
$assert(strpos($service, "'id' => 'storefront.property_descriptions'") !== false, 'Property description capability must be stable');
$assert(strpos($service, "'optionModule' => 'prospektweb.propvalmanager'") !== false && strpos($service, "'optionName' => 'ENABLED'") !== false && strpos($service, "'optionDefault' => 'Y'") !== false, 'Property descriptions must reuse the existing provider option and default');
$assert(strpos($service, "'id' => 'storefront.checkout.company_suggestions'") !== false, 'Company suggestions capability must be stable');
$assert(strpos($service, "'optionModule' => 'prospektweb.companyrequisites'") !== false && strpos($service, "'optionName' => 'enabled'") !== false, 'Company suggestions must reuse the existing provider option');
$assert(substr_count($service, "'mutable' => true") === 2, 'Only the two existing provider guards may be mutable');
$assert(strpos($service, "hash('sha256'") !== false && strpos($service, 'hash_equals(') !== false, 'Capability writes must use stable optimistic revisions');
$assert(strpos($service, 'flock($handle, LOCK_EX)') !== false && strpos($service, '@chmod($lockPath, 0600)') !== false, 'Capability CAS must run under a private process lock');
$assert(strpos($service, 'CEventLog::Add') !== false && strpos($service, 'PROSPEKTWEB_CONTROL_CENTER_CAPABILITY_CHANGED') !== false, 'Effective changes must be written to the Bitrix event log');

$assert(strpos($autoload, "'Prospektweb\\\\Calc\\\\Services\\\\ModuleCapabilityRegistryService'") !== false, 'Registry service must be registered in module autoload');
$assert(strpos($installer, "\$toolsDir . '/control_center_modules.php'") !== false, 'Installer integrity must verify the published modules endpoint');
$assert(strpos($diagnostic, "'lib/Services/ModuleCapabilityRegistryService.php'") !== false && substr_count($diagnostic, "'control_center_modules.php'") >= 1, 'Module diagnostics must verify registry service and endpoint');
$assert(strpos($host, "'modules' => '/bitrix/tools/prospektweb.calc/control_center_modules.php'") !== false, 'Control-center bootstrap must expose the modules endpoint');
$assert(strpos($host, "'modules' => true") !== false, 'Control-center bootstrap must advertise the modules capability');
$assert(strpos($version, "'VERSION' => '1.4.0'") !== false, 'Phase 3A must advance the admincalc module version');

echo "Control center modules API static tests passed\n";
