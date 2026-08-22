<?php

declare(strict_types=1);

$installer = file_get_contents(__DIR__ . '/../install/index.php');
$diagnostic = file_get_contents(__DIR__ . '/../lib/Diagnostic/ModuleDiagnostic.php');
$readme = file_get_contents(__DIR__ . '/../README.MD');
if (!is_string($installer) || !is_string($diagnostic) || !is_string($readme)) {
    throw new RuntimeException('Unable to read module installer, diagnostic, or README');
}

$obsoletePaths = [
    "\$targetTools . '/calculate.php'",
    "\$targetTools . '/catalog_calc_property_migration.php'",
    "\$this->modulePath . '/tools/calculate.php'",
    "\$this->modulePath . '/tools/catalog_calc_property_migration.php'",
    "\$targetTools . '/config.php'",
    "\$this->modulePath . '/tools/config.php'",
    "\$this->modulePath . '/lib/Services/ResultWriter.php'",
    "\$this->modulePath . '/lib/Install/CatalogCalcPropertyMigrationService.php'",
    "\$this->modulePath . '/lib/Install/CatalogDisplayConfigPatcher.php'",
    "\$this->modulePath . '/lib/Install/Preset12740NeutralGlobalSymbolCorrectionMigrationService.php'",
    "\$this->modulePath . '/lib/Install/Preset12740NeutralGlobalSymbolMigrationService.php'",
    "\$this->modulePath . '/lib/Install/Preset12740NeutralInputMigrationService.php'",
    "\$this->modulePath . '/lib/Services/CatalogAdapterDefinitionService.php'",
    "\$this->modulePath . '/lib/Services/NeutralFormulaPolicy.php'",
    "\$this->modulePath . '/lib/Services/Phase5aParityContractService.php'",
    "\$this->modulePath . '/lib/Services/StandaloneCatalogSelectionMapper.php'",
    "\$this->modulePath . '/lib/Calculator/SaveHandler.php'",
    "\$this->modulePath . '/lib/Calculator/CalculationHistoryHandler.php'",
    "\$this->modulePath . '/lib/Services/SaveAllService.php'",
    "\$this->modulePath . '/lib/Install/Installer.php'",
    "\$this->modulePath . '/resources/phase5a_golden_capture_v1.json'",
];

$autoload = file_get_contents(__DIR__ . '/../include.php');
if (!is_string($autoload)) {
    throw new RuntimeException('Unable to read module autoload map');
}
if (strpos($autoload, 'Install\\\\Installer') !== false || strpos($autoload, 'lib/Install/Installer.php') !== false) {
    throw new RuntimeException('Deprecated Installer must not remain autoloadable');
}
if (is_file(__DIR__ . '/../lib/Install/Installer.php')) {
    throw new RuntimeException('Deprecated Installer source must be removed from the package');
}
if (strpos($readme, 'Prospektweb\\Calc\\Install\\Installer') !== false
    || strpos($readme, 'lib/Install/Installer.php') !== false) {
    throw new RuntimeException('README must not advertise the removed legacy installer API');
}

foreach ($obsoletePaths as $obsoletePath) {
    if (strpos($installer, $obsoletePath) === false) {
        throw new RuntimeException('Installer does not clean obsolete runtime path: ' . $obsoletePath);
    }
}

if (strpos($installer, 'is_file($obsoleteFile) && !unlink($obsoleteFile)') === false) {
    throw new RuntimeException('Installer cleanup must remove only exact existing files and surface failures');
}
if (strpos($diagnostic, "            'calculate.php',") !== false) {
    throw new RuntimeException('Diagnostic must not require the removed calculate.php endpoint');
}

echo "Installer obsolete-file cleanup static tests passed\n";
