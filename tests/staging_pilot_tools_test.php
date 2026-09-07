<?php
declare(strict_types=1);
$root = dirname(__DIR__) . '/tools/document-migration/';
foreach (['import-staging-pilot.php', 'prepare-staging-product.php', 'prepare-staging-prices.php', 'verify-staging-pilot.php'] as $file) {
    $source = file_get_contents($root . $file);
    foreach (["PHP_SAPI !== 'cli'", "'/home/bitrix/www'", "'/etc/prospekt-calc-stage/service.env'", "str_starts_with(\$private . '/', \$root . '/')"] as $guard) {
        if (!str_contains($source, $guard)) { throw new RuntimeException($file . ' missing staging guard: ' . $guard); }
    }
    if (str_contains($source, 'CIBlock::Delete') || str_contains($source, 'publishSite(')) { throw new RuntimeException('Pilot utilities cannot delete catalogs or publish the site.'); }
    $output = []; $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . $file) . ' 2>&1', $output, $status);
    if ($status === 0 || !str_contains(implode("\n", $output), 'Isolated staging')) { throw new RuntimeException($file . ' did not reject a non-staging invocation.'); }
}
$productSetup = file_get_contents($root . 'prepare-staging-product.php');
if (!str_contains($productSetup, 'SiteConnection::canonical(') || !str_contains($productSetup, "\$draft['connectionJson'] !== \$connectionJson")) {
    throw new RuntimeException('Product setup must compare the same canonical connection as repository storage.');
}
echo "Staging pilot command safety tests passed\n";
