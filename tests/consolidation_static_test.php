<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$include = (string)file_get_contents($root . '/include.php');
$options = (string)file_get_contents($root . '/options.php');
$manager = (string)file_get_contents($root . '/lib/Integration/ConsolidationManager.php');
$patcher = (string)file_get_contents($root . '/lib/Integration/TemplatePatchCoordinator.php');
$basketBackupManager = (string)file_get_contents($root . '/features/orders/lib/BackupManager.php');
$version = (string)file_get_contents($root . '/install/version.php');

function consolidation_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

foreach ([
    'Prospektweb\\Frontcalc\\Service\\CalculatorAvailability',
    'Prospektweb\\PropValManager\\Service\\PropertyDescriptionService',
    'Prospektweb\\OfferFilter\\OfferFilter',
    'Prospektweb\\LayoutFiles\\FileManager',
] as $class) {
    consolidation_assert(strpos($include, str_replace('\\', '\\\\', $class)) !== false, 'autoload missing: ' . $class);
}

consolidation_assert(strpos($options, 'APPLY_CONSOLIDATION') !== false, 'safe update action missing');
consolidation_assert(strpos($options, 'APPROVED_ASPRO_PRICES_HASH') !== false, 'Aspro hash approval missing');
consolidation_assert(strpos($manager, "'/local/ajax/frontcalc.php'") !== false, 'legacy FrontCalc endpoint compatibility missing');
consolidation_assert(strpos($manager, "'/local/tools/prospekt_layout/ajax.php'") !== false, 'layout endpoint compatibility missing');
consolidation_assert(strpos($patcher, 'hash_equals($currentHash, $approvedCurrentHash)') !== false, 'template hash guard missing');
consolidation_assert(strpos($patcher, "TEMPLATE_RELEASE = 'prospektweb.calc-2.0.0'") !== false, 'template release marker missing');
consolidation_assert(strpos($basketBackupManager, 'isCurrentReleaseAlreadyInstalled') !== false, 'one-time basket template guard missing');
consolidation_assert(strpos($version, "'VERSION' => '2.0.0'") !== false, 'consolidated version missing');
consolidation_assert(!is_dir($root . '/features/property_values/samples-aspro'), 'sample templates must not be shipped');
consolidation_assert(!is_file($root . '/features/property_values/lib/Service/AsproTemplatePatcher.php'), 'full-file property template patcher must be removed');

echo "Consolidation static tests passed\n";
