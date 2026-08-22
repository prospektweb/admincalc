<?php

$root = dirname(__DIR__);
$service = file_get_contents($root . '/lib/Services/BatchRecalculateService.php');
$endpoint = file_get_contents($root . '/tools/batch_recalculate.php');

if (!is_string($service) || !is_string($endpoint)) {
    throw new RuntimeException('Batch recalculate sources are unavailable');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(strpos($service, 'CalculationHistoryHandler') === false,
    'Batch recalculation must not perform a post-commit calculation-history write');
$assert(strpos($service, 'successfulOfferResults') === false,
    'Batch recalculation must not accumulate a second write payload after catalog commit');
$assert(strpos($service, 'applyAuthoritativeBatch(') !== false
    && strpos($service, 'replayAuthoritativeBatch(') !== false,
    'Batch recalculation must use the idempotent transactional catalog writer');
$assert(strpos($service, 'public function recalculateOffer(') === false
    && strpos($service, 'public function recalculate(') === false,
    'Retired non-preview batch writer wrappers must not remain callable');

$assert(strpos($endpoint, 'getLegacyJobFilePaths') === false,
    'Private batch storage must not migrate or delete legacy upload jobs at request time');
$assert(strpos($endpoint, "'/upload/prospektweb.calc'") === false,
    'Batch jobs must not read from the public upload tree');
$assert(strpos($endpoint, 'getJobStorageDirectory()') !== false
    && strpos($endpoint, 'sys_get_temp_dir()') !== false
    && strpos($endpoint, '@chmod($private, 0700)') !== false,
    'Batch jobs must use only the private per-site runtime directory');

echo "Batch recalculate clean authority tests passed\n";
