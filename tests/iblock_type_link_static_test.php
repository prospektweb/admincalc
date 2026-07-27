<?php

declare(strict_types=1);

$bundle = file_get_contents(__DIR__ . '/../install/assets/apps_dist/assets/index.js');
if (!is_string($bundle) || $bundle === '') {
    fwrite(STDERR, "Built calcconfig bundle is missing\n");
    exit(1);
}

$checks = [
    'cS(v.id,v.type,"ru")' => 'Sidebar must pass the actual iblock type to the Bitrix edit page',
    'if(!s)throw new Error(' => 'Iblock edit URL builder must reject a missing type',
];

foreach ($checks as $needle => $message) {
    if (strpos($bundle, $needle) === false) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

if (strpos($bundle, "openIblockEditPage(iblock.id,'calculator'") !== false) {
    fwrite(STDERR, "Sidebar must not force external iblocks into the calculator type\n");
    exit(1);
}

echo "OK\n";
