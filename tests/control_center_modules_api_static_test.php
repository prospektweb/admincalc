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
$assert(substr_count($service, "'mutable' => true") === 15, 'Every mutable capability must be backed by a provider-owned runtime guard');
$assert(strpos($service, "'id' => 'prospektweb.storefrontui'") !== false && strpos($service, "'optionModule' => 'prospektweb.storefrontui'") !== false, 'Public cosmetics must have one dedicated provider module');
$assert(strpos($service, "'optionName' => 'CHARACTERISTICS_PRESENTATION'") !== false && strpos($service, "'optionName' => 'CATALOG_IMAGE_COVER'") !== false && strpos($service, "'optionName' => 'MOBILE_SECTION_DESCRIPTION'") !== false, 'Affected catalog UI features must expose separate control-center switches');
$assert(strpos($service, "'id' => 'prospektweb.partnermanager'") !== false, 'Partner manager must be present in the canonical module catalog');
$assert(strpos($service, "'id' => 'storefront.product.partners'") !== false, 'Partner storefront capability must be stable');
$assert(strpos($service, "'id' => 'storefront.contacts.gallery'") !== false, 'Contacts gallery capability must be stable');
$assert(strpos($service, "'optionName' => 'CONTACT_GALLERY_ENABLED'") !== false && strpos($service, "'optionDefault' => 'N'") !== false, 'Contacts gallery must be disabled by default and use its provider-owned option');
$assert(strpos($service, "'optionName' => 'MASS_PROPERTY_EDITOR_ENABLED'") !== false, 'Mass offer property editor must use its provider-owned guard');
$assert(strpos($service, "'group' => 'Мобильная версия'") !== false, 'Mobile features must be grouped separately');
$assert(strpos($service, "hash('sha256'") !== false && strpos($service, 'hash_equals(') !== false, 'Capability writes must use stable optimistic revisions');
$assert(strpos($service, 'flock($handle, LOCK_EX)') !== false && strpos($service, '@chmod($lockPath, 0600)') !== false, 'Capability CAS must run under a private process lock');
$assert(strpos($service, 'CEventLog::Add') !== false && strpos($service, 'PROSPEKTWEB_CONTROL_CENTER_CAPABILITY_CHANGED') !== false, 'Effective changes must be written to the Bitrix event log');
$assert(strpos($service, 'use Bitrix\\Main\\Loader;') !== false, 'Provider modules must be loaded through the Bitrix Loader import');
$assert(strpos($service, '$this->clearCapabilityPublicCache($capabilityId);') !== false, 'Public cache invalidation must run outside the audit rollback block');

$assert(strpos($autoload, "'Prospektweb\\\\Calc\\\\Services\\\\ModuleCapabilityRegistryService'") !== false, 'Registry service must be registered in module autoload');
$assert(strpos($installer, "\$toolsDir . '/control_center_modules.php'") !== false, 'Installer integrity must verify the published modules endpoint');
$assert(strpos($diagnostic, "'lib/Services/ModuleCapabilityRegistryService.php'") !== false && substr_count($diagnostic, "'control_center_modules.php'") >= 1, 'Module diagnostics must verify registry service and endpoint');
$assert(strpos($host, "'modules' => '/bitrix/tools/prospektweb.calc/control_center_modules.php'") !== false, 'Control-center bootstrap must expose the modules endpoint');
$assert(strpos($host, "'modules' => true") !== false, 'Control-center bootstrap must advertise the modules capability');
$assert(strpos($host, "'partners' => '/bitrix/tools/prospektweb/partnermanager/control_center.php'") !== false, 'Control-center bootstrap must expose partner manager');
$versionMatch = preg_match("/'VERSION'\\s*=>\\s*'([^']+)'/", $version, $versionParts);
$assert($versionMatch === 1 && version_compare($versionParts[1], '1.4.0', '>='), 'Phase 3A requires admincalc module version 1.4.0 or newer');

echo "Control center modules API static tests passed\n";
