<?php

require_once dirname(__DIR__) . '/lib/Services/CatalogAdapterDefinitionService.php';
require_once dirname(__DIR__) . '/lib/Services/BatchRecalculateService.php';

use Prospektweb\Calc\Services\BatchRecalculateService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expectFailure = static function (
    callable $callback,
    int $expectedCode,
    string $message
) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $error) {
        $assert($error->getCode() === $expectedCode, $message . ' returns the expected status code');
        return;
    }
    $assert(false, $message);
};

$assert(
    BatchRecalculateService::normalizeRequestedPresetIds([]) === [12740],
    'empty endpoint preset scope defaults only to preset 12740'
);
$assert(
    BatchRecalculateService::normalizeRequestedPresetIds(['12740']) === [12740],
    'the exact preset 12740 scope is normalized'
);
$expectFailure(
    static function (): void {
        BatchRecalculateService::normalizeRequestedPresetIds([12740, 12740]);
    },
    400,
    'multiple preset entries are rejected even when duplicated'
);
$expectFailure(
    static function (): void {
        BatchRecalculateService::normalizeRequestedPresetIds([12741]);
    },
    409,
    'foreign endpoint preset scope is rejected'
);
$expectFailure(
    static function (): void {
        BatchRecalculateService::normalizeRequestedPresetIds(['preset' => 12740]);
    },
    400,
    'preset scope must be a JSON-style list'
);

$assert(
    BatchRecalculateService::normalizeProductIdsByPresetScope([
        12740 => [14380, '12727', 14380, 0],
    ]) === [12740 => [12727, 14380]],
    'product scope is normalized only inside preset 12740'
);
$assert(
    BatchRecalculateService::normalizeProductIdsByPresetScope([]) === [],
    'an omitted product scope remains empty inside the supported preset scope'
);
$expectFailure(
    static function (): void {
        BatchRecalculateService::normalizeProductIdsByPresetScope([
            12740 => [12727],
            12741 => [999],
        ]);
    },
    409,
    'foreign productIdsByPreset keys are rejected'
);

BatchRecalculateService::assertSupportedBatchPresetId(12740);
$expectFailure(
    static function (): void {
        BatchRecalculateService::assertSupportedBatchPresetId(12741);
    },
    409,
    'service rejects a foreign preset resolved from server-owned offer data'
);

$endpoint = file_get_contents(dirname(__DIR__) . '/tools/batch_recalculate.php');
$service = file_get_contents(dirname(__DIR__) . '/lib/Services/BatchRecalculateService.php');
$assert(is_string($endpoint) && is_string($service), 'batch sources are readable');
$commonStart = strpos($endpoint, 'function validateCommonParams');
$commonEnd = strpos($endpoint, 'function validateAnalysisContract', $commonStart ?: 0);
$common = $commonStart !== false && $commonEnd !== false
    ? substr($endpoint, $commonStart, $commonEnd - $commonStart)
    : '';
$productStart = strpos($endpoint, 'function validateProductIdsByPreset');
$productEnd = strpos($endpoint, 'function validateCommonParams', $productStart ?: 0);
$product = $productStart !== false && $productEnd !== false
    ? substr($endpoint, $productStart, $productEnd - $productStart)
    : '';
$assert(
    strpos($common, 'BatchRecalculateService::normalizeRequestedPresetIds($presetIds)') !== false
        && strpos($common, "'UNSUPPORTED_PRESET_SCOPE'") !== false,
    'endpoint common validation uses the exact 12740 scope normalizer'
);
$assert(
    strpos($product, 'BatchRecalculateService::normalizeProductIdsByPresetScope($rawMap)') !== false
        && strpos($product, "'UNSUPPORTED_PRESET_SCOPE'") !== false,
    'endpoint rejects foreign productIdsByPreset before analysis or job creation'
);

$methodStart = strpos($service, 'public function recalculateOffers(');
$methodEnd = strpos($service, '    private function prepareCalculationPayload(', $methodStart ?: 0);
$method = $methodStart !== false && $methodEnd !== false
    ? substr($service, $methodStart, $methodEnd - $methodStart)
    : '';
$guard = strpos($method, 'self::assertSupportedBatchPresetId($resolvedPresetId)');
$fingerprint = strpos($method, '$this->computeStateHashForOffer($initPayload, $offerId)');
$assert(
    $method !== '' && $guard !== false && $fingerprint !== false && $guard < $fingerprint,
    'service fail-closed preset guard runs before fingerprints, calc-server or writes'
);
$assert(
    strpos($method, '(new OfferUpdateService())->updateOffersFromCalculation') === false
        && strpos($method, '$this->callCalcServer(') === false
        && strpos($method, '$this->saveHash(') === false,
    'batch recalculation has no legacy client-result writer, direct network path or post-commit state-hash write'
);
$assert(
    strpos($method, '$this->calculateOffersForPreview($offersToProcess, $siteId)') !== false
        && strpos($method, '$catalogWriter->applyAuthoritativeBatch(') !== false,
    'supported batch recalculation uses only the authoritative preset 12740 writer'
);

echo "Batch preset scope tests passed\n";
