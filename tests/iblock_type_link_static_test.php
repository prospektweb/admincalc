<?php

declare(strict_types=1);

$bundle = file_get_contents(__DIR__ . '/../install/assets/apps_dist/assets/index.js');
if (!is_string($bundle) || $bundle === '') {
    fwrite(STDERR, "Built calcconfig bundle is missing\n");
    exit(1);
}

// The standalone editor no longer renders the legacy left catalog menu, so Vite
// can legitimately tree-shake the URL builder out of the production bundle.
// Its exact contract is covered in calcconfig/bitrix-utils.test.mjs; this host-
// artifact guard only prevents the retired hard-coded calculator type from
// reappearing in a caller that survives bundling.

if (strpos($bundle, "openIblockEditPage(iblock.id,'calculator'") !== false) {
    fwrite(STDERR, "Sidebar must not force external iblocks into the calculator type\n");
    exit(1);
}

echo "OK\n";
