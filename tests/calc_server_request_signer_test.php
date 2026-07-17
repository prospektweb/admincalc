<?php

require_once __DIR__ . '/../lib/Services/CalcServerRequestSigner.php';

use Prospektweb\Calc\Services\CalcServerRequestSigner;

$signer = new CalcServerRequestSigner('prospektprint-production', str_repeat('a', 64));
$body = '{"initPayload":{"selectedOffers":[{"id":12431}]}}';
$headers = $signer->headers($body, 'post', '/calculate', 1700000000, 'abcdefghijklmnop');
$canonical = CalcServerRequestSigner::canonical(
    'prospektprint-production',
    '1700000000',
    'abcdefghijklmnop',
    'POST',
    '/calculate',
    hash('sha256', $body)
);
$expected = hash_hmac('sha256', $canonical, str_repeat('a', 64));

if (!in_array('X-Frontcalc-Signature: ' . $expected, $headers, true)) {
    throw new RuntimeException('HMAC signature mismatch');
}
if (!in_array('X-Frontcalc-Client: prospektprint-production', $headers, true)) {
    throw new RuntimeException('Client header missing');
}

echo "CalcServerRequestSigner tests passed\n";
