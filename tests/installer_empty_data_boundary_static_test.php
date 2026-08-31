<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$installerFiles = [
    $root . '/install/index.php',
    $root . '/install/step1.php',
    $root . '/install/step2.php',
    $root . '/install/step3.php',
    $root . '/install/step4.php',
];
$source = '';
foreach ($installerFiles as $file) {
    $assert(is_file($file), 'Missing installer source: ' . basename($file));
    $source .= "\n" . file_get_contents($file);
}

foreach ([
    'IMPORT_SNAPSHOT',
    'import_snapshot',
    'SnapshotManager',
    'DemoDataCreator',
    'CatalogSchemaDeploymentService',
    'control_center_deployment',
] as $forbidden) {
    $assert(strpos($source, $forbidden) === false, 'Installer must not invoke controlled deployment path: ' . $forbidden);
}

$assert(
    preg_match('/CIBlockElement\s*::\s*Add|new\s+\\?CIBlockElement\b[\s\S]{0,300}->Add\s*\(/', $source) !== 1,
    'Installer must not create iblock elements or demo content'
);
$assert(
    strpos(file_get_contents($root . '/tools/control_center_deployment.php'), "\$action === 'apply'") !== false,
    'Controlled apply must live in the control-center endpoint'
);
$assert(
    strpos(file_get_contents($root . '/admin/prospektweb_calc_control_center.php'), "'deployment' =>") !== false,
    'Control center must expose the deployment endpoint explicitly'
);

echo "installer_empty_data_boundary_static_test: OK\n";
