<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$init = file_get_contents($root . '/lib/Calculator/InitPayloadService.php');
$elements = file_get_contents($root . '/lib/Calculator/ElementDataService.php');

if ($init === false || $elements === false) {
    fwrite(STDERR, "Unable to read catalog context services\n");
    exit(1);
}

foreach ([
    "'PREVIEW_TEXT', 'DETAIL_TEXT'" => 'offer announcement and detail text',
    "'vatIncluded'" => 'VAT inclusion flag',
    "'vat' => \$vatInfo" => 'VAT rate payload',
    "'extendedPriceMode' => \$extendedPriceMode" => 'extended price mode',
    "'basePrice' => \$basePrice" => 'base price',
    "'baseCurrency' => \$baseCurrency" => 'base currency',
    "'quantityFrom'" => 'price range lower bound',
    "'quantityTo'" => 'price range upper bound',
] as $needle => $label) {
    if (strpos($init, $needle) === false && strpos($elements, $needle) === false) {
        fwrite(STDERR, "Missing {$label}: {$needle}\n");
        exit(1);
    }
}

echo "AI source catalog context checks passed\n";
