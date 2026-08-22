<?php

require_once __DIR__ . '/../lib/Config/ConfigManager.php';
require_once __DIR__ . '/../lib/Calculator/InitPayloadService.php';
require_once __DIR__ . '/../lib/Services/CatalogOutputMappingService.php';
require_once __DIR__ . '/../lib/Services/BatchRecalculateService.php';

use Prospektweb\Calc\Services\BatchRecalculateService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

foreach ([
    'file:///tmp/calc.sock',
    'http://169.254.169.254/latest/meta-data',
    'https://169.254.169.254/latest/meta-data',
    'http://10.0.0.2/calc-api',
    'http://example.test/calc-api',
    'https://user:secret@example.test/calc-api',
    'https://example.test/calc-api?redirect=http://127.0.0.1',
] as $url) {
    $rejected = false;
    try {
        // Exercise the public constructor, not only a tools-endpoint guard.
        new BatchRecalculateService($url);
    } catch (\InvalidArgumentException $error) {
        $rejected = true;
    }
    $assert($rejected, 'constructor must reject unsafe calc-server URL: ' . $url);
}

$https = new BatchRecalculateService('HTTPS://calc.example.test:443/calc-api/');
$loopbackV4 = new BatchRecalculateService('http://127.0.0.1:3000/calc-api/');
$loopbackName = new BatchRecalculateService('http://localhost:3000/calc-api');
$assert(
    BatchRecalculateService::normalizeCalcServerUrl('HTTPS://calc.example.test:443/calc-api/')
        === 'https://calc.example.test:443/calc-api',
    'HTTPS URL must be canonicalized deterministically'
);
$assert(
    BatchRecalculateService::normalizeCalcServerUrl('http://127.0.0.1:3000/calc-api/')
        === 'http://127.0.0.1:3000/calc-api',
    'exact IPv4 loopback must remain available for local development'
);
$assert(
    BatchRecalculateService::normalizeCalcServerUrl('http://localhost:3000/calc-api')
        === 'http://localhost:3000/calc-api',
    'exact localhost must remain available for local development'
);

unset($https, $loopbackV4, $loopbackName);
echo "Batch calc-server URL policy tests passed\n";
