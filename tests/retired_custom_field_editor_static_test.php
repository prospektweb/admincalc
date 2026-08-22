<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$installer = (string)file_get_contents($root . '/install/index.php');
$step3 = (string)file_get_contents($root . '/install/step3.php');

$retiredPaths = [
    $root . '/admin/prospektweb_calc_custom_field.php',
    $root . '/install/components/prospektweb/calc.custom_field.edit/.description.php',
    $root . '/install/components/prospektweb/calc.custom_field.edit/.parameters.php',
    $root . '/install/components/prospektweb/calc.custom_field.edit/class.php',
    $root . '/install/components/prospektweb/calc.custom_field.edit/templates/.default/script.js',
    $root . '/install/components/prospektweb/calc.custom_field.edit/templates/.default/style.css',
    $root . '/install/components/prospektweb/calc.custom_field.edit/templates/.default/template.php',
];
foreach ($retiredPaths as $retiredPath) {
    if (file_exists($retiredPath)) {
        throw new RuntimeException('Retired direct custom-field writer remains: ' . $retiredPath);
    }
}

foreach ([
    "\$targetAdmin . '/prospektweb_calc_custom_field.php'",
    "\$this->modulePath . '/admin/prospektweb_calc_custom_field.php'",
    "\$docRoot . '/local/components/prospektweb/calc.custom_field.edit'",
    "\$this->modulePath . '/install/components/prospektweb/calc.custom_field.edit'",
] as $cleanupAuthority) {
    if (!str_contains($installer, $cleanupAuthority)) {
        throw new RuntimeException('Installer does not remove retired custom-field writer: ' . $cleanupAuthority);
    }
}
if (str_contains($installer, '$sourceAdmin . \'/prospektweb_calc_custom_field.php\'')
    || str_contains($installer, "CopyDirFiles(\$sourceComponent, \$targetComponent")
    || str_contains($step3, "'EDIT_FILE_AFTER' => '/bitrix/admin/prospektweb_calc_custom_field.php'")) {
    throw new RuntimeException('Installer still publishes the retired custom-field editor.');
}

echo "Retired custom-field editor static contract: OK\n";
