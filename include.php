<?php
use Bitrix\Main\Loader;
Loader::registerAutoloadClasses('prospektweb.calc', [
    'Prospektweb\\Calc\\Calculator\\CalculatorInterface' => 'lib/Calculator/CalculatorInterface.php',
    'Prospektweb\\Calc\\Calculator\\BaseCalculator' => 'lib/Calculator/BaseCalculator.php',
    'Prospektweb\\Calc\\Calculator\\CalculatorRegistry' => 'lib/Calculator/CalculatorRegistry.php',
    'Prospektweb\\Calc\\Calculator\\Calculators\\DigitalSheet' => 'lib/Calculator/Calculators/DigitalSheet.php',
    'Prospektweb\\Calc\\Calculator\\Calculators\\RollLamination' => 'lib/Calculator/Calculators/RollLamination.php',
    'Prospektweb\\Calc\\Calculator\\Calculators\\DimensionsWeight' => 'lib/Calculator/Calculators/DimensionsWeight.php',
    'Prospektweb\\Calc\\Calculator\\Calculators\\PriceSettings' => 'lib/Calculator/Calculators/PriceSettings.php',
    'Prospektweb\\Calc\\Calculator\\InitPayloadService' => 'lib/Calculator/InitPayloadService.php',
    'Prospektweb\\Calc\\Calculator\\SaveHandler' => 'lib/Calculator/SaveHandler.php',
    'Prospektweb\\Calc\\Calculator\\CalculationHistoryHandler' => 'lib/Calculator/CalculationHistoryHandler.php',
    'Prospektweb\\Calc\\Config\\ConfigManager' => 'lib/Config/ConfigManager.php',
    'Prospektweb\\Calc\\Config\\SettingsManager' => 'lib/Config/SettingsManager.php',
    'Prospektweb\\Calc\\Install\\Installer' => 'lib/Install/Installer.php',
    'Prospektweb\\Calc\\Install\\SnapshotManager' => 'lib/Install/SnapshotManager.php',
    'Prospektweb\\Calc\\Services\\EntityLoader' => 'lib/Services/EntityLoader.php',
    'Prospektweb\\Calc\\Services\\ResultWriter' => 'lib/Services/ResultWriter.php',
    'Prospektweb\\Calc\\Services\\ValidationService' => 'lib/Services/ValidationService.php',
    'Prospektweb\\Calc\\Services\\DependencyTracker' => 'lib/Services/DependencyTracker.php',
    'Prospektweb\\Calc\\Services\\SyncVariantsHandler' => 'lib/Services/SyncVariantsHandler.php',
    'Prospektweb\\Calc\\Services\\DetailHandler' => 'lib/Services/DetailHandler.php',
    'Prospektweb\\Calc\\Services\\CustomFieldsService' => 'lib/Services/CustomFieldsService.php',
    'Prospektweb\\Calc\\Services\\PresetEnrichmentService' => 'lib/Services/PresetEnrichmentService.php',
    'Prospektweb\\Calc\\Services\\CatalogPriceService' => 'lib/Services/CatalogPriceService.php',
    'Prospektweb\\Calc\\Services\\OfferUpdateService' => 'lib/Services/OfferUpdateService.php',
    'Prospektweb\\Calc\\Services\\SaveAllService' => 'lib/Services/SaveAllService.php',
    'Prospektweb\\Calc\\Services\\BatchRecalculateService' => 'lib/Services/BatchRecalculateService.php',
    'Prospektweb\\Calc\\Handlers\\AdminHandler' => 'lib/Handlers/AdminHandler.php',
    'Prospektweb\\Calc\\Handlers\\DependencyHandler' => 'lib/Handlers/DependencyHandler.php',
]);

// Примечание: Обработчик OnBuildGlobalMenu регистрируется только через persistent handler
// в install/index.php->installEvents(). Runtime регистрация через addEventHandler убрана,
// чтобы избежать дублирования пунктов меню.
