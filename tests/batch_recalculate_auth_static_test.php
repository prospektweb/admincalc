<?php

$source = file_get_contents(__DIR__ . '/../lib/Services/BatchRecalculateService.php');
if (!is_string($source)) {
    throw new RuntimeException('BatchRecalculateService source is unavailable');
}

$checks = [
    "headers(\$requestBody, 'POST', '/calculate')" => 'Batch requests must be signed',
    "'X-Frontcalc-Signature: '" => 'Signer must emit the signature header',
    "\$serverError['message']" => 'Structured calc-server errors must use their message',
    "dirname(\$documentRoot) . '/.frontcalc-secret'" => 'Production secret must be loaded outside document root',
];

foreach ($checks as $needle => $message) {
    if (strpos($source . file_get_contents(__DIR__ . '/../lib/Services/CalcServerRequestSigner.php'), $needle) === false) {
        throw new RuntimeException($message);
    }
}

echo "Batch recalculate auth static tests passed\n";
