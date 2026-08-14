<?php

$source = file_get_contents(__DIR__ . '/../lib/Services/BatchRecalculateService.php');
$endpoint = file_get_contents(__DIR__ . '/../tools/batch_recalculate.php');
$page = file_get_contents(__DIR__ . '/../admin/prospektweb_calc_recalculate.php');
if (!is_string($source) || !is_string($endpoint) || !is_string($page)) {
    throw new RuntimeException('BatchRecalculateService source is unavailable');
}

$checks = [
    "headers(\$requestBody, 'POST', '/calculate')" => 'Batch requests must be signed',
    "'X-Frontcalc-Signature: '" => 'Signer must emit the signature header',
    "\$serverError['message']" => 'Structured calc-server errors must use their message',
    "dirname(\$documentRoot) . '/.frontcalc-secret'" => 'Production secret must be loaded outside document root',
    "'offerCount' => 0" => 'Every product in batch analysis must expose an offer count',
    'countOffersByProductIds' => 'Product offer counts must come from the SKU relation',
    'updateOffersFromCalculation($offerResults, true)' => 'Batch writes must require complete positive catalog values',
    'preparePresetInitPayload(' => 'Preset 12740 catalog writes must use the independent preset payload',
    'resolveForPreset(StandaloneCatalogSelectionMapper::PRESET_ID)' => 'Catalog adapter must read the published preset form',
    'normalizeStandaloneCatalogPrices($offerResults, $requestPayload)' => 'Catalog adapter prices must use storefront pricing parity',
    '$writeResultsByOfferId' => 'Per-offer write failures must not be reported as recalculated',
    'private const PREVIEW_CHUNK_SIZE = 6' => 'Preview requests must use a production-proven bounded chunk size',
    'array_chunk($offerIds, self::PREVIEW_CHUNK_SIZE)' => 'Preview must split larger selections before calling calc-server',
    '$preview[\'ready\'] = $preview[\'ready\'] && !empty($chunkPreview[\'ready\'])' => 'Every preview chunk must pass before writes are enabled',
];

foreach ($checks as $needle => $message) {
    if (strpos($source . file_get_contents(__DIR__ . '/../lib/Services/CalcServerRequestSigner.php'), $needle) === false) {
        throw new RuntimeException($message);
    }
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
    "if (\$action === 'preview')" => 'Batch endpoint must support a non-writing preview action',
    '$service->previewOffers($offerIds)' => 'Preview must calculate the exact scoped offers',
    "'ready' => (bool)(\$preview['ready'] ?? false)" => 'Preview must expose a strict write-readiness decision',
];
foreach ($endpointChecks as $needle => $message) {
    if (strpos($endpoint, $needle) === false) {
        throw new RuntimeException($message);
    }
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
] as $needle) {
    if (strpos($page, $needle) === false) {
        throw new RuntimeException('Legacy batch UI must preserve hardened job lifecycle: ' . $needle);
    }
}

echo "Batch recalculate auth static tests passed\n";
