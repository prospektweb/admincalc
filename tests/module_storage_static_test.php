<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$installer = file_get_contents($root . '/lib/Install/ModuleStorageInstaller.php');
$service = file_get_contents($root . '/lib/Modules/ModuleLifecycleService.php');
$moduleInstaller = file_get_contents($root . '/install/index.php');
$installStep = file_get_contents($root . '/install/step3.php');
$include = file_get_contents($root . '/include.php');
$storageDefinitions = '';
foreach (glob($root . '/lib/Modules/Storage/*.php') ?: [] as $storageFile) {
    $storageDefinitions .= file_get_contents($storageFile) ?: '';
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert($installer !== false && $service !== false && $moduleInstaller !== false && $installStep !== false && $include !== false, 'storage files are readable');
foreach ([
    'b_pw_calc_module_family',
    'b_pw_calc_module_version',
    'b_pw_calc_module_instance',
    'b_pw_calc_module_snapshot',
    'b_pw_calc_module_audit',
] as $table) {
    $assert(strpos($storageDefinitions, $table) !== false, "storage table is declared: {$table}");
}
$assert(strpos($installer, '$unique ? \'UNIQUE \'') !== false, 'storage creates unique indexes');
$assert(strpos($installer, 'DROP ') === false, 'storage installer never drops tables or data');
$assert(strpos($service, 'FOR UPDATE') !== false, 'lifecycle locks revisions transactionally');
$assert(strpos($service, 'startTransaction') !== false, 'lifecycle uses transactions');
$assert(strpos($service, 'ModuleAuditTable::add') !== false, 'lifecycle appends audit records');
$assert(strpos($service, 'listVersionUsage') !== false, 'lifecycle exposes usage inventory');
$assert(strpos($service, 'applyInstance') !== false, 'lifecycle atomically applies instances and snapshots');
$assert(strpos($service, 'installPilotStage') !== false, 'lifecycle can idempotently publish the reviewed pilot fixture');
$assert(strpos($service, 'ModuleMaterializer::materialize') !== false, 'lifecycle validates before persistence');
$assert(strpos($service, "'instance.update.apply'") !== false, 'lifecycle audits applied updates');
$assert(strpos($moduleInstaller, "'reference_id' => ['D', 'R', 'W', 'P']") !== false, 'module declares publication right');
$assert(strpos($installStep, 'ModuleStorageInstaller') !== false, 'fresh install provisions module storage');
$assert(strpos($include, "'Prospektweb\\\\Calc\\\\Modules\\\\ModuleMaterializer'") !== false, 'materializer autoload is registered');

echo "Calculation module storage static checks passed\n";
