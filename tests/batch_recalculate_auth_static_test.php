<?php

$source = file_get_contents(__DIR__ . '/../lib/Services/BatchRecalculateService.php');
$fingerprintService = file_get_contents(__DIR__ . '/../lib/Services/BatchPreviewFingerprintService.php');
$endpoint = file_get_contents(__DIR__ . '/../tools/batch_recalculate.php');
$catalogWriter = file_get_contents(__DIR__ . '/../lib/Services/CatalogCalculationWriteService.php');
$page = file_get_contents(__DIR__ . '/../admin/prospektweb_calc_recalculate.php');
$include = file_get_contents(__DIR__ . '/../include.php');
if (!is_string($source) || !is_string($fingerprintService) || !is_string($endpoint)
    || !is_string($catalogWriter) || !is_string($page) || !is_string($include)) {
    throw new RuntimeException('BatchRecalculateService source is unavailable');
}

$checks = [
    "headers(\$requestBody, 'POST', '/calculate')" => 'Batch requests must be signed',
    "'X-Frontcalc-Signature: '" => 'Signer must emit the signature header',
    "\$serverError['message']" => 'Structured calc-server errors must use their message',
    "dirname(\$documentRoot) . '/.frontcalc-secret'" => 'Production secret must be loaded outside document root',
    "'offerCount' => 0" => 'Every product in batch analysis must expose an offer count',
    'countOffersByProductIds' => 'Product offer counts must come from the SKU relation',
    '$this->catalogAdapterService->supportedProductIds()' => 'Standalone batch analysis must use the versioned adapter target profiles',
    'preparePresetCalculationPayloadReadOnlyPinned(' => 'Preset 12740 catalog writes must use the mutation-free direct-option-pinned payload',
    '$catalogPayload[\'_publishedSnapshot\']' => 'Catalog calculation must use the exact raw-pinned published snapshot',
    'projectCatalogAdapterResults($offerResults, $requestPayload)' => 'Preview and write must project results through the adapter output allowlist',
    'normalizeStandaloneCatalogPrices($offerResults, $requestPayload)' => 'Catalog adapter prices must use storefront pricing parity',
    '$writeResultsByOfferId' => 'Per-offer write failures must not be reported as recalculated',
    'private const PREVIEW_CHUNK_SIZE = 6' => 'Preview requests must use a production-proven bounded chunk size',
    'array_chunk($offerIds, self::PREVIEW_CHUNK_SIZE)' => 'Preview must split larger selections before calling calc-server',
    '$preview[\'ready\'] = $preview[\'ready\'] && !empty($chunkPreview[\'ready\'])' => 'Every preview chunk must pass before writes are enabled',
    'captureOfferStateFingerprints(array $offerIds)' => 'Start must be able to re-read the exact calculation state without writing',
    'captureStateFingerprintsFromPayload($initPayload, $offerChunk)' => 'Preview must bind every offer to its calculation-affecting state',
    'array $expectedStateFingerprints = []' => 'Legacy callers must retain a compatible optional CAS argument',
    '!hash_equals($expectedHash, $currentHash)' => 'Actual writes must stop when state drifts after job start',
    "'globalSymbols' => \$initPayload['globalSymbols'] ?? []" => 'Calculation state must bind global symbols',
    "'elementsSiblings' => \$initPayload['elementsSiblings'] ?? []" => 'Calculation state must bind sibling variant authority',
    "'neutralInputRequired' => (\$initPayload['neutralInputRequired'] ?? false) === true" => 'Calculation state must bind the explicit neutral-input mode',
    "'runtimeConfigSnapshot' => \$initPayload['_runtimeConfigSnapshot'] ?? []" => 'Calculation state must bind direct ConfigManager and calc-server URL authority',
    "'measureRatioProductIds'" => 'Runtime locks must include measure-ratio authority',
    "'propertyIds'" => 'Runtime locks must include property definitions and enum authority',
    "'globalSymbolProperties'" => 'Runtime locks must include required global-symbol property definitions',
    "'sourceIblockIds' => \$sourceIblockIds" => 'Runtime locks must include full configured source membership authorities',
    'Calculation inputs changed while the reviewed batch preview was running.' => 'Preview must reject a calc-server result when inputs drift during network execution',
    'replayAuthoritativeBatch(' => 'Batch retries must probe their durable receipt before calc-server',
    'self::assertSupportedBatchPresetId($resolvedPresetId)' => 'Batch service must reject a foreign resolved preset before any processing',
];

foreach ($checks as $needle => $message) {
    if (strpos($source . file_get_contents(__DIR__ . '/../lib/Services/CalcServerRequestSigner.php'), $needle) === false) {
        throw new RuntimeException($message);
    }
}

$recalculateStart = strpos($source, 'public function recalculateOffers(');
$recalculateEnd = strpos($source, '    private function prepareCalculationPayload(', $recalculateStart ?: 0);
$recalculateMethod = $recalculateStart !== false && $recalculateEnd !== false
    ? substr($source, $recalculateStart, $recalculateEnd - $recalculateStart)
    : '';
if ($recalculateMethod === ''
    || strpos($recalculateMethod, '(new OfferUpdateService())->updateOffersFromCalculation') !== false
    || strpos($recalculateMethod, '$this->callCalcServer(') !== false
    || strpos($recalculateMethod, '$this->saveHash(') !== false) {
    throw new RuntimeException('Batch recalculation must not retain a legacy direct-result or post-commit hash writer');
}

$endpointChecks = [
    "header('Cache-Control: no-store, private')" => 'Batch responses must not be cached',
    "\$requestMethod !== 'POST'" => 'Batch endpoint must accept POST only',
    'check_bitrix_sessid()' => 'Batch endpoint must enforce CSRF protection',
    '$USER->IsAdmin()' => 'Batch endpoint must require an administrator',
    "'application/x-www-form-urlencoded'" => 'Batch endpoint must accept form-urlencoded requests',
    "array_key_exists('payload', \$_POST)" => 'Batch endpoint must accept the form payload envelope',
    "file_get_contents('php://input')" => 'Batch endpoint must preserve raw JSON compatibility',
    "substr(\$value, 0, 1) !== '{'" => 'Batch JSON transport must reject non-object roots',
    'sys_get_temp_dir()' => 'Batch state must live outside the web document root',
    "hash('sha256', \$documentRoot" => 'Batch storage must be namespaced per site',
    '@mkdir($private, 0700, true)' => 'Batch storage directory must be owner-only',
    '@chmod($path, 0600)' => 'Batch state files must be owner-only',
    'getLegacyJobFilePaths($userId)' => 'Legacy public job files must be migrated and removed',
    'LOCK_EX' => 'Batch job writes must be locked',
    'flock($handle, LOCK_EX)' => 'Batch requests must serialize job mutations',
    "'jobId' => bin2hex(random_bytes(16))" => 'Each batch job must have an unguessable identifier',
    "'MISSING_JOB_ID'" => 'Job actions must reject a missing job identifier',
    "'JOB_ID_MISMATCH'" => 'Job actions must reject a stale identifier',
    "'JOB_ALREADY_ACTIVE'" => 'Starting over an active job must require an explicit replacement',
    "empty(\$requestData['replace'])" => 'Active job replacement must be explicit',
    'validateAnalysisContract($analysis)' => 'Batch endpoint must reject an incomplete analysis contract',
    "'INVALID_ANALYSIS_CONTRACT'" => 'Incomplete product counts must return an explicit contract error',
    "array_key_exists('calcServerUrl', \$requestData)" => 'Client requests must not select the calc-server destination',
    "'CALC_SERVER_OVERRIDE_FORBIDDEN'" => 'Client calc-server overrides must fail closed',
    'BatchRecalculateService::normalizeRequestedPresetIds($presetIds)' => 'Empty/exact preset scope must normalize to preset 12740 only',
    'BatchRecalculateService::normalizeProductIdsByPresetScope($rawMap)' => 'Foreign product scopes must fail closed',
    "'UNSUPPORTED_PRESET_SCOPE'" => 'Foreign preset scope must return an explicit endpoint error',
    'captureRuntimeConfigSnapshot()' => 'The calc-server destination must come from direct b_option authority',
    'BatchRecalculateService::normalizeCalcServerUrl(' => 'The endpoint must reuse the centralized calc-server URL policy',
    "if (\$action === 'preview')" => 'Batch endpoint must support a non-writing preview action',
    '$service->previewOffers($offerIds)' => 'Preview must calculate the exact scoped offers',
    "'ready' => (bool)(\$preview['ready'] ?? false)" => 'Preview must expose a strict write-readiness decision',
    "in_array(\$action, ['preview', 'start', 'status', 'step', 'cancel', 'finish'], true)" => 'Preview issuance and start validation must share the per-user lock',
    "'BATCH_RECALC_PREVIEW_TTL'" => 'Preview proof freshness must be server configured and bounded',
    'savePreviewState($userId' => 'A successful preview must be persisted in private per-user storage',
    "'previewFingerprint' => \$previewFingerprint" => 'Preview must return its opaque server fingerprint to the UI',
    "'PREVIEW_REQUIRED'" => 'A direct start without preview must fail closed',
    "'PREVIEW_EXPIRED'" => 'Expired previews must fail closed',
    "'PREVIEW_MISMATCH'" => 'A foreign or replaced preview must fail closed',
    "'PREVIEW_STALE'" => 'Changed selection or calculation state must fail closed',
    '$service->captureOfferWriteStateFingerprints(flattenScopedOfferIds($rows))' => 'Start must re-read calculation and writable catalog state before creating a job',
    'BatchPreviewFingerprintService::scopeFingerprint($scope)' => 'Start must bind the exact preset/product/offer scope',
    'BatchPreviewFingerprintService::stateFingerprint($currentStateFingerprints)' => 'Start must bind current calculation state',
    'deletePreviewState($userId);' => 'Preview proofs must be invalidated and consumed server-side',
    "'approvedStateFingerprints' => \$currentStateFingerprints" => 'The job must carry its server-approved per-offer state',
    "'approvedResultFingerprints' => \$normalizedApprovedResults" => 'The job must carry its server-reviewed per-offer result hashes',
    '$batchExpectedState' => 'Every step must pass its approved state into the write service',
    '$batchExpectedResults' => 'Every step must pass its reviewed results into the authoritative writer',
    '$batchRequestId = hash(' => 'Every job chunk must derive a stable durable-replay identifier',
    '(string)$job[\'jobId\'] . \':\' . $presetId . \':\' . implode(\',\', $receiptOfferIds)' => 'Batch replay identity must bind the job, preset and exact offer set',
    '$userId,' => 'The authoritative writer must bind the receipt to the authenticated administrator',
    "'Задача не содержит подтверждённое состояние preview." => 'A resumed unbound job must fail closed before writes',
];
foreach ($endpointChecks as $needle => $message) {
    if (strpos($endpoint, $needle) === false) {
        throw new RuntimeException($message);
    }
}

$replayProbe = strpos($source, '$catalogWriter->replayAuthoritativeBatch(');
$networkCalculation = strpos($source, '$this->calculateOffersForPreview($offersToProcess, $siteId)', $replayProbe !== false ? $replayProbe : 0);
if ($replayProbe === false || $networkCalculation === false || $replayProbe >= $networkCalculation) {
    throw new RuntimeException('Durable batch replay must be resolved before any calc-server call.');
}
foreach (['BATCH_RECEIPT_CONTRACT', 'saveBatchReceipt(', 'targetStateFingerprint', 'validateBatchReplayReceiptUnderLocks'] as $needle) {
    if (strpos($catalogWriter, $needle) === false) {
        throw new RuntimeException('Durable in-transaction batch receipt is incomplete: ' . $needle);
    }
}
if (strpos($source, '$preview[\'stateFingerprints\'][$offerId][\'calculation\']') === false
    || strpos($source, '$postNetworkState[$offerId][\'calculation\']') === false) {
    throw new RuntimeException('Reviewed batch results must compare pre-network and post-network calculation fingerprints.');
}

$sessionHydration = strpos($endpoint, "\$_REQUEST['sessid'] = \$requestSessid");
$postSessionHydration = strpos($endpoint, "\$_POST['sessid'] = \$requestSessid");
$adminProlog = strpos($endpoint, "require_once \$_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php'");
if ($sessionHydration === false || $postSessionHydration === false || $adminProlog === false || $sessionHydration >= $adminProlog || $postSessionHydration >= $adminProlog) {
    throw new RuntimeException('Batch JSON sessid must hydrate request and POST before the Bitrix admin prolog');
}

foreach ([
    "jobStorageKey:",
    "jobId: currentJobId",
    "setCurrentJobId(data.jobId || '')",
    "data.errorCode === 'JOB_ALREADY_ACTIVE'",
    "action: 'preview'",
    'previewSelectionSignature !== getSelectionSignature()',
    'confirmBtn.disabled = !previewPassed',
    "var previewFingerprint = '';",
    "previewFingerprint: previewFingerprint",
    "data.errorCode === 'PREVIEW_REQUIRED'",
    "data.errorCode === 'PREVIEW_EXPIRED'",
    "data.errorCode === 'PREVIEW_MISMATCH'",
    "data.errorCode === 'PREVIEW_STALE'",
] as $needle) {
    if (strpos($page, $needle) === false) {
        throw new RuntimeException('Legacy batch UI must preserve hardened job lifecycle: ' . $needle);
    }
}

if (strpos($page, 'id="calc-server-url"') !== false || strpos($page, 'calcServerUrl:') !== false) {
    throw new RuntimeException('Legacy batch UI must not expose or submit calc-server routing authority.');
}

$startPosition = strpos($endpoint, "if (\$action === 'start')");
$previewRequiredPosition = strpos($endpoint, "'PREVIEW_REQUIRED'", $startPosition !== false ? $startPosition : 0);
$jobSavePosition = strpos($endpoint, 'saveJobState($userId, $jobState)', $startPosition !== false ? $startPosition : 0);
if ($startPosition === false || $previewRequiredPosition === false || $jobSavePosition === false
    || $previewRequiredPosition <= $startPosition || $previewRequiredPosition >= $jobSavePosition) {
    throw new RuntimeException('Start must reject a missing preview fingerprint before persisting any write job.');
}

foreach (['CONTRACT', 'scopeFingerprint', 'stateFingerprint', 'previewFingerprint', 'resultFingerprints', 'isValidFingerprint'] as $needle) {
    if (strpos($fingerprintService, $needle) === false) {
        throw new RuntimeException('Batch preview fingerprint contract is incomplete: ' . $needle);
    }
}
if (strpos($include, "'Prospektweb\\\\Calc\\\\Services\\\\BatchPreviewFingerprintService'") === false) {
    throw new RuntimeException('Batch preview fingerprint service must be registered in the module autoloader.');
}

echo "Batch recalculate auth static tests passed\n";
