<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$read = static fn(string $path): string => file_get_contents($root . '/' . $path);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$source = $read('lib/Install/NativeInstallation.php') . $read('lib/Install/ResourceDirectoryInstaller.php') . $read('install/step3.php');
foreach (['CALC_PRESETS','CALC_SETTINGS','CALC_STAGES','CALC_GLOBAL_VALUES','CALC_DETAILS','CALC_CUSTOM_FIELDS','HIGHLOAD_CALC_HISTORY_ID','COMPLETED_CALCS','PARAMETR_VALUES'] as $old) {
    $assert(!str_contains($source, $old), 'Installer resurrects legacy schema: ' . $old);
}
$assert(str_contains($source, 'GET_LOCK') && str_contains($source, 'RELEASE_LOCK'), 'Concurrent installations need one scoped lock');
$assert(str_contains($source, 'check_bitrix_sessid()') && str_contains($source, '$USER->IsAdmin()'), 'Schema writes require authenticated admin POST');
$assert(str_contains($source, 'DocumentSchema::install'), 'Native document schema must be installed explicitly');
$assert(!str_contains($source, 'CIBlockElement') && !str_contains($source, 'CIBlockSection'), 'No content operations during installation');
$uninstall = $read('install/unstep2.php');
foreach (['CIBlock::Delete', 'CIBlockElement::Delete', 'Option::delete', 'HighloadBlockTable::delete', 'DROP TABLE'] as $forbidden) {
    $assert(!str_contains($uninstall, $forbidden), 'Uninstall destroys retained data');
}
$assert(str_contains($uninstall, "isModuleInstalled('prospektweb.frontcalc')"), 'Uninstall must enforce the dependent public adapter');
$events = $read('install/index.php');
$installEvents = substr($events, strpos($events, 'public function installEvents'), strpos($events, 'public function uninstallEvents') - strpos($events, 'public function installEvents'));
$assert(!str_contains($installEvents, 'DependencyHandler') && !str_contains($installEvents, 'PresetProductAssignmentMutationGuardService'), 'Fresh installation must not register legacy graph events');
$provider = $read('lib/Documents/BitrixResourceProvider.php');
$assert(str_contains($provider, 'new ResourceCatalogRegistry') && !str_contains($provider, 'new \\Prospektweb\\Calc\\Config\\ConfigManager'), 'Resource snapshot must not require legacy graph options');
$page = $read('admin/prospektweb_calc_control_center.php');
$assert(!str_contains($page, '$configManager') && str_contains($page, "->get('document_site_id', '')"), 'Control center bridge must use native module options without an undefined legacy manager');
echo "native_installation_boundary_test: PASS\n";
