<?php

require_once __DIR__ . '/../lib/Services/BatchPreviewFingerprintService.php';

use Prospektweb\Calc\Services\BatchPreviewFingerprintService;

function batchPreviewAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$scope = [
    'presetIds' => [12740],
    'productIdsByPreset' => ['12740' => [14380, 12727]],
    'onlyChanged' => true,
    'calcServerUrl' => 'https://pwrt.ru/calc-api/',
    'timeout' => 30,
    'rows' => [[
        'presetId' => 12740,
        'offerIds' => [15322, 15320],
    ]],
];
$states = [
    15320 => ['calculation' => str_repeat('a', 64), 'catalog' => str_repeat('d', 64)],
    15322 => ['calculation' => str_repeat('b', 64), 'catalog' => str_repeat('e', 64)],
];
$preview = [
    'ready' => true,
    'summary' => ['total' => 2, 'valid' => 2, 'invalid' => 0],
    'offers' => [
        [
            'offerId' => 15322,
            'valid' => true,
            'purchasePrice' => 410.5,
            'currency' => 'RUB',
            'dimensions' => ['width' => 50, 'length' => 90, 'height' => 32, 'weight' => 270],
            'prices' => [['typeId' => 1, 'basePrice' => 1280.0, 'currency' => 'RUB']],
            'errors' => [],
        ],
        [
            'offerId' => 15320,
            'valid' => true,
            'purchasePrice' => 351.89,
            'currency' => 'RUB',
            'dimensions' => ['width' => 50, 'length' => 90, 'height' => 32, 'weight' => 135.516],
            'prices' => [['typeId' => 1, 'basePrice' => 880.0, 'currency' => 'RUB']],
            'errors' => [],
        ],
    ],
    'errors' => [],
];

$proof = BatchPreviewFingerprintService::issue($scope, $states, $preview);
$resultFingerprints = BatchPreviewFingerprintService::resultFingerprints($preview);
batchPreviewAssert(
    array_keys($resultFingerprints) === [15320, 15322]
        && BatchPreviewFingerprintService::isValidFingerprint($resultFingerprints[15320]),
    'Preview must expose private deterministic per-offer result fingerprints.'
);
batchPreviewAssert(
    BatchPreviewFingerprintService::isValidFingerprint($proof['fingerprint']),
    'Issued preview proof must expose a canonical SHA-256 fingerprint.'
);

$reorderedScope = $scope;
$reorderedScope['presetIds'] = ['12740', 12740];
$reorderedScope['productIdsByPreset'] = [12740 => [12727, 14380, 12727]];
$reorderedScope['rows'][0]['offerIds'] = [15320, 15322, 15320];
$reorderedStates = array_reverse($states, true);
$reorderedPreview = $preview;
$reorderedPreview['offers'] = array_reverse($preview['offers']);
$sameProof = BatchPreviewFingerprintService::issue($reorderedScope, $reorderedStates, $reorderedPreview);
batchPreviewAssert(
    $proof['fingerprint'] === $sameProof['fingerprint'],
    'Equivalent selection, state and preview ordering must produce one deterministic proof.'
);

$changedScope = $scope;
$changedScope['onlyChanged'] = false;
batchPreviewAssert(
    $proof['fingerprint'] !== BatchPreviewFingerprintService::issue($changedScope, $states, $preview)['fingerprint'],
    'Write options must be bound to the preview proof.'
);

$changedStates = $states;
$changedStates[15320]['calculation'] = str_repeat('c', 64);
batchPreviewAssert(
    $proof['fingerprint'] !== BatchPreviewFingerprintService::issue($scope, $changedStates, $preview)['fingerprint'],
    'A changed calculation state must invalidate the preview proof.'
);
$changedCatalogStates = $states;
$changedCatalogStates[15320]['catalog'] = str_repeat('f', 64);
batchPreviewAssert(
    $proof['fingerprint'] !== BatchPreviewFingerprintService::issue($scope, $changedCatalogStates, $preview)['fingerprint'],
    'A changed writable catalog state must invalidate the preview proof.'
);

$changedPreview = $preview;
$changedPreview['offers'][1]['prices'][0]['basePrice'] = 881.0;
batchPreviewAssert(
    $proof['fingerprint'] !== BatchPreviewFingerprintService::issue($scope, $states, $changedPreview)['fingerprint'],
    'A changed projected catalog result must invalidate the preview proof.'
);
batchPreviewAssert(
    $resultFingerprints[15320]
        !== BatchPreviewFingerprintService::resultFingerprints($changedPreview)[15320],
    'Per-offer result fingerprints must detect projected result drift.'
);

$failedPreviewRejected = false;
try {
    BatchPreviewFingerprintService::issue($scope, $states, ['ready' => false]);
} catch (InvalidArgumentException $error) {
    $failedPreviewRejected = true;
}
batchPreviewAssert($failedPreviewRejected, 'A failed preview must never issue a write proof.');

$emptyStateRejected = false;
try {
    BatchPreviewFingerprintService::issue($scope, [], $preview);
} catch (InvalidArgumentException $error) {
    $emptyStateRejected = true;
}
batchPreviewAssert($emptyStateRejected, 'A preview without offer-state fingerprints must fail closed.');
batchPreviewAssert(
    !BatchPreviewFingerprintService::isValidFingerprint('not-a-sha256'),
    'Malformed client fingerprints must be rejected.'
);

echo "Batch preview fingerprint service tests passed\n";
