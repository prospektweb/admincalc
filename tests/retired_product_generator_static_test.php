<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$installer = (string)file_get_contents($root . '/install/index.php');
$handler = (string)file_get_contents($root . '/lib/Handlers/AdminHandler.php');
$diagnostic = (string)file_get_contents($root . '/lib/Diagnostic/ModuleDiagnostic.php');

foreach ([
    $root . '/tools/product_generator.php',
    $root . '/install/assets/js/product_generator.js',
] as $retiredPath) {
    if (is_file($retiredPath)) {
        throw new RuntimeException('Retired uncoordinated product generator remains: ' . $retiredPath);
    }
}
foreach ([
    "\$targetTools . '/product_generator.php'",
    "\$this->modulePath . '/tools/product_generator.php'",
    "\$targetJs . '/product_generator.js'",
    "\$this->modulePath . '/install/assets/js/product_generator.js'",
] as $cleanupAuthority) {
    if (!str_contains($installer, $cleanupAuthority)) {
        throw new RuntimeException('Installer does not retire product generator target: ' . $cleanupAuthority);
    }
}
if (str_contains($handler, 'product_generator.js') || str_contains($diagnostic, 'product_generator.php')) {
    throw new RuntimeException('Active runtime still advertises the retired product generator.');
}

echo "Retired product generator static contract: OK\n";
