<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$include = file_get_contents($root . '/include.php');
$diagnostic = file_get_contents($root . '/lib/Diagnostic/ModuleDiagnostic.php');
$installer = file_get_contents($root . '/install/index.php');
if (!is_string($include) || !is_string($diagnostic) || !is_string($installer)) {
    throw new RuntimeException('Retired config writer sources are unavailable');
}

foreach ([$root . '/tools/config.php', $root . '/lib/Services/ResultWriter.php'] as $retiredPath) {
    if (file_exists($retiredPath)) {
        throw new RuntimeException('Dead uncoordinated writer still exists: ' . $retiredPath);
    }
}
if (strpos($include, 'ResultWriter') !== false || strpos($diagnostic, "'tools/config.php'") !== false) {
    throw new RuntimeException('Dead config writer remains registered or required');
}
foreach ([
    "\$targetTools . '/config.php'",
    "\$this->modulePath . '/tools/config.php'",
    "\$this->modulePath . '/lib/Services/ResultWriter.php'",
] as $cleanupPath) {
    if (strpos($installer, $cleanupPath) === false) {
        throw new RuntimeException('Installer does not remove retired writer: ' . $cleanupPath);
    }
}

echo "Retired config writer static tests passed\n";
