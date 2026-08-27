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
$assert(strpos($service, 'new CalculatorVersionRuntimePublicationService()') !== false
    && strpos($service, "['documents']['logic']") !== false
    && strpos($service, "['documents']['commercialPolicy']") !== false,
    'Batch calculation must use the active complete bundle logic and commercial policy');
$assert(strpos($service, "'contract' => 'prospektweb.calc.execution-context/v1'") !== false
    && strpos($service, "'deadlineType' => 'strict'") !== false
    && strpos($service, "'unitCount' => max(1, (int)(\$scenario['quantity'] ?? 1))") !== false,
    'Batch calculation must send an explicit effort basis context to calc-server');
$assert(strpos($service, "\$versionContext['calculatorPresetId']") !== false,
    'Catalog output mapping must retain the calculator identity when the active logic uses an isolated working preset');

$assert(strpos($endpoint, 'getLegacyJobFilePaths') === false,
    'Private batch storage must not migrate or delete legacy upload jobs at request time');
$assert(strpos($endpoint, "'/upload/prospektweb.calc'") === false,
    'Batch jobs must not read from the public upload tree');
$assert(strpos($endpoint, 'getJobStorageDirectory()') !== false
    && strpos($endpoint, 'sys_get_temp_dir()') !== false
    && strpos($endpoint, '@chmod($private, 0700)') !== false,
    'Batch jobs must use only the private per-site runtime directory');

echo "Batch recalculate clean authority tests passed\n";
