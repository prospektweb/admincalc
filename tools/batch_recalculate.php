<?php
/**
 * AJAX-эндпоинт для пакетного пересчёта калькуляций
 */

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? '');
$requestContentType = strtolower(trim((string)strtok((string)($_SERVER['CONTENT_TYPE'] ?? ''), ';')));
$requestData = [];
$requestError = null;

$decodeJsonObject = static function ($value): ?array {
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '' || substr($value, 0, 1) !== '{') {
        return null;
    }

    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
};

if ($requestMethod === 'POST') {
    $isFormRequest = $requestContentType === 'application/x-www-form-urlencoded'
        || array_key_exists('payload', $_POST);

    if ($isFormRequest) {
        if (array_key_exists('payload', $_POST)) {
            $requestData = $decodeJsonObject($_POST['payload']);
            if ($requestData === null) {
                $requestData = [];
                $requestError = 'Request payload must be a JSON object';
            }
        } else {
            $requestData = $_POST;
        }
    } else {
        $requestBody = (string)file_get_contents('php://input');
        if (trim($requestBody) !== '') {
            $requestData = $decodeJsonObject($requestBody);
            if ($requestData === null) {
                $requestData = [];
                $requestError = 'Request body must be a JSON object';
            }
        }
    }
}

if (empty($_REQUEST['sessid']) && isset($requestData['sessid']) && is_scalar($requestData['sessid'])) {
    $requestSessid = (string)$requestData['sessid'];
    $_REQUEST['sessid'] = $requestSessid;
    if (empty($_POST['sessid'])) {
        $_POST['sessid'] = $requestSessid;
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Services\BatchPreviewFingerprintService;
use Prospektweb\Calc\Services\BatchRecalculateService;
use Prospektweb\Calc\Services\CatalogCalculationWriteService;
use Prospektweb\Calc\Services\CatalogRuntimeConfigAuthorityService;

global $APPLICATION;
global $USER;

$APPLICATION->RestartBuffer();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

function respondJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die();
}

function logAccessIssue(string $message): void
{
    if (function_exists('AddMessage2Log')) {
        AddMessage2Log($message, 'prospektweb.calc');
    }
}

function loadJobLimits(): array
{
    $moduleId = 'prospektweb.calc';

    $maxOffersPerJob = max(50, (int)Option::get($moduleId, 'BATCH_RECALC_MAX_OFFERS', '400'));
    $maxStepDurationSec = max(2, (int)Option::get($moduleId, 'BATCH_RECALC_MAX_STEP_DURATION', '12'));
    $maxBatchSize = max(1, (int)Option::get($moduleId, 'BATCH_RECALC_MAX_BATCH_SIZE', '10'));
    $jobTtlSec = max(60, (int)Option::get($moduleId, 'BATCH_RECALC_JOB_TTL', '1800'));
    $previewTtlSec = max(30, min(900, (int)Option::get($moduleId, 'BATCH_RECALC_PREVIEW_TTL', '300')));

    return [
        'maxOffersPerJob' => $maxOffersPerJob,
        'maxStepDurationSec' => $maxStepDurationSec,
        'maxBatchSize' => $maxBatchSize,
        'jobTtlSec' => $jobTtlSec,
        'previewTtlSec' => $previewTtlSec,
    ];
}

function validateProductIdsByPreset(array $requestData): array
{
    $rawMap = $requestData['productIdsByPreset'] ?? [];
    if (!is_array($rawMap)) {
        respondJson(400, [
            'success' => false,
            'errorCode' => 'INVALID_PRODUCT_IDS',
            'error' => 'productIdsByPreset must be an object',
        ]);
    }

    try {
        return BatchRecalculateService::normalizeProductIdsByPresetScope($rawMap);
    } catch (\Throwable $error) {
        $status = $error->getCode() === 409 ? 409 : 400;
        respondJson($status, [
            'success' => false,
            'errorCode' => $status === 409 ? 'UNSUPPORTED_PRESET_SCOPE' : 'INVALID_PRODUCT_IDS',
            'error' => $error->getMessage(),
        ]);
    }
}

function validateCommonParams(array $requestData): array
{
    $presetIds = $requestData['presetIds'] ?? [];
    $onlyChanged = (bool)($requestData['onlyChanged'] ?? true);
    if (!is_array($presetIds)) {
        respondJson(400, [
            'success' => false,
            'errorCode' => 'INVALID_PRESET_IDS',
            'error' => 'presetIds must be an array',
        ]);
    }
    try {
        $presetIds = BatchRecalculateService::normalizeRequestedPresetIds($presetIds);
    } catch (\Throwable $error) {
        $status = $error->getCode() === 409 ? 409 : 400;
        respondJson($status, [
            'success' => false,
            'errorCode' => $status === 409 ? 'UNSUPPORTED_PRESET_SCOPE' : 'INVALID_PRESET_IDS',
            'error' => $error->getMessage(),
        ]);
    }
    if (array_key_exists('calcServerUrl', $requestData)) {
        respondJson(400, [
            'success' => false,
            'errorCode' => 'CALC_SERVER_OVERRIDE_FORBIDDEN',
            'error' => 'The calc-server URL is controlled by the server configuration.',
        ]);
    }
    try {
        $runtimeConfig = (new CatalogCalculationWriteService())->captureRuntimeConfigSnapshot();
        $calcServerUrl = trim(CatalogRuntimeConfigAuthorityService::adminOptionValue(
            $runtimeConfig,
            'CALC_SERVER_URL'
        ));
        $calcServerUrl = BatchRecalculateService::normalizeCalcServerUrl(
            $calcServerUrl !== '' ? $calcServerUrl : 'https://pwrt.ru/calc-api'
        );
    } catch (\Throwable $error) {
        respondJson(400, [
            'success' => false,
            'errorCode' => 'INVALID_CALC_SERVER_URL',
            'error' => $error->getMessage(),
        ]);
    }
    $timeout = (int)($requestData['timeout'] ?? 30);

    if ($timeout < 1 || $timeout > 300) {
        $timeout = 30;
    }

    return [$presetIds, $onlyChanged, $calcServerUrl, $timeout];
}

function validateAnalysisContract(array $analysis): void
{
    foreach ($analysis as $row) {
        if (!is_array($row)
            || !isset($row['presetId'], $row['presetName'], $row['products'], $row['offerCount'])
            || !is_int($row['presetId'])
            || $row['presetId'] <= 0
            || !is_string($row['presetName'])
            || !is_array($row['products'])
            || !is_int($row['offerCount'])
            || $row['offerCount'] < 0) {
            respondJson(500, [
                'success' => false,
                'errorCode' => 'INVALID_ANALYSIS_CONTRACT',
                'error' => 'Сервер вернул неполную строку пресета',
            ]);
        }

        $productOfferCount = 0;
        foreach ($row['products'] as $product) {
            if (!is_array($product)
                || !isset($product['id'], $product['name'], $product['offerCount'])
                || !is_int($product['id'])
                || $product['id'] <= 0
                || !is_string($product['name'])
                || !is_int($product['offerCount'])
                || $product['offerCount'] < 0
                || (array_key_exists('editUrl', $product) && !is_string($product['editUrl']))) {
                respondJson(500, [
                    'success' => false,
                    'errorCode' => 'INVALID_ANALYSIS_CONTRACT',
                    'error' => 'В анализе отсутствует количество ТП по товару',
                ]);
            }
            $productOfferCount += $product['offerCount'];
        }

        if ($productOfferCount !== $row['offerCount']) {
            respondJson(500, [
                'success' => false,
                'errorCode' => 'INVALID_ANALYSIS_CONTRACT',
                'error' => 'Сумма ТП по товарам не совпадает с итогом пресета',
            ]);
        }
    }
}

/**
 * Resolve the exact preset-to-offer matrix once and reuse it for preview,
 * fingerprinting and job creation.
 *
 * @return array<int,array{presetId:int,presetName:string,offerIds:array<int,int>}>
 */
function buildScopedBatchRows(
    BatchRecalculateService $service,
    array $analysis,
    array $productIdsByPreset
): array {
    $rows = [];
    foreach ($analysis as $row) {
        $presetId = (int)$row['presetId'];
        $offerIds = isset($productIdsByPreset[$presetId])
            ? $service->getOfferIdsForPresetProducts($presetId, $productIdsByPreset[$presetId])
            : $service->getOfferIdsForPreset($presetId);
        $offerIds = array_values(array_unique(array_filter(array_map('intval', $offerIds), static function (int $offerId): bool {
            return $offerId > 0;
        })));
        sort($offerIds, SORT_NUMERIC);
        $rows[] = [
            'presetId' => $presetId,
            'presetName' => (string)$row['presetName'],
            'offerIds' => $offerIds,
        ];
    }
    usort($rows, static function (array $left, array $right): int {
        return $left['presetId'] <=> $right['presetId'];
    });

    return $rows;
}

/** @return int[] */
function flattenScopedOfferIds(array $rows): array
{
    $offerIds = [];
    foreach ($rows as $row) {
        $offerIds = array_merge($offerIds, is_array($row['offerIds'] ?? null) ? $row['offerIds'] : []);
    }
    $offerIds = array_values(array_unique(array_filter(array_map('intval', $offerIds), static function (int $offerId): bool {
        return $offerId > 0;
    })));
    sort($offerIds, SORT_NUMERIC);

    return $offerIds;
}

/** @return array<string,mixed> */
function buildBatchPreviewScope(
    array $presetIds,
    array $productIdsByPreset,
    bool $onlyChanged,
    string $calcServerUrl,
    int $timeout,
    array $rows
): array {
    return [
        'presetIds' => $presetIds,
        'productIdsByPreset' => $productIdsByPreset,
        'onlyChanged' => $onlyChanged,
        'calcServerUrl' => $calcServerUrl,
        'timeout' => $timeout,
        'rows' => array_map(static function (array $row): array {
            return [
                'presetId' => (int)($row['presetId'] ?? 0),
                'offerIds' => is_array($row['offerIds'] ?? null) ? $row['offerIds'] : [],
            ];
        }, $rows),
    ];
}

function getJobStorageDirectory(): string
{
    $documentRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $siteNamespace = substr(hash('sha256', $documentRoot . '|prospektweb.calc|batch-recalculate'), 0, 24);
    $private = rtrim(sys_get_temp_dir(), '/\\')
        . DIRECTORY_SEPARATOR
        . 'prospektweb-calc-'
        . $siteNamespace;
    if (!is_dir($private) && !@mkdir($private, 0700, true) && !is_dir($private)) {
        respondJson(500, [
            'success' => false,
            'errorCode' => 'JOB_STORAGE_ERROR',
            'error' => 'Unable to create private recalculate job storage',
        ]);
    }
    @chmod($private, 0700);

    return $private;
}

function getJobFilePath(int $userId): string
{
    return getJobStorageDirectory() . '/batch_recalc_job_user_' . $userId . '.json';
}

function getPreviewFilePath(int $userId): string
{
    return getJobStorageDirectory() . '/batch_recalc_preview_user_' . $userId . '.json';
}

function loadPreviewState(int $userId): ?array
{
    $path = getPreviewFilePath($userId);
    if (!is_file($path)) {
        return null;
    }
    $content = file_get_contents($path);
    if (!is_string($content) || $content === '') {
        return null;
    }
    $decoded = json_decode($content, true);

    return is_array($decoded) ? $decoded : null;
}

function savePreviewState(int $userId, array $state): void
{
    $path = getPreviewFilePath($userId);
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        respondJson(500, [
            'success' => false,
            'errorCode' => 'PREVIEW_SERIALIZATION_ERROR',
            'error' => 'Unable to serialize batch preview state',
        ]);
    }
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
    if (file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, $path)) {
        @unlink($temporary);
        respondJson(500, [
            'success' => false,
            'errorCode' => 'PREVIEW_STORAGE_ERROR',
            'error' => 'Unable to persist batch preview state',
        ]);
    }
    @chmod($path, 0600);
}

function deletePreviewState(int $userId): void
{
    $path = getPreviewFilePath($userId);
    if (is_file($path)) {
        @unlink($path);
    }
}

/** @return resource */
function acquireJobLock(int $userId)
{
    $handle = @fopen(getJobStorageDirectory() . '/batch_recalc_job_user_' . $userId . '.lock', 'c+');
    if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
        respondJson(503, [
            'success' => false,
            'errorCode' => 'JOB_LOCK_UNAVAILABLE',
            'error' => 'Unable to lock recalculate job',
        ]);
    }
    @chmod(getJobStorageDirectory() . '/batch_recalc_job_user_' . $userId . '.lock', 0600);

    return $handle;
}

function loadJobState(int $userId): ?array
{
    $path = getJobFilePath($userId);
    if (!is_file($path)) {
        return null;
    }

    $content = file_get_contents($path);
    if ($content === false || $content === '') {
        return null;
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : null;
}

function saveJobState(int $userId, array $state): void
{
    $path = getJobFilePath($userId);
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        respondJson(500, [
            'success' => false,
            'errorCode' => 'JOB_SERIALIZATION_ERROR',
            'error' => 'Unable to serialize recalculate job',
        ]);
    }

    $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
    if (file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, $path)) {
        @unlink($temporary);
        respondJson(500, [
            'success' => false,
            'errorCode' => 'JOB_STORAGE_ERROR',
            'error' => 'Unable to persist recalculate job',
        ]);
    }
    @chmod($path, 0600);
}

function deleteJobState(int $userId): void
{
    $path = getJobFilePath($userId);
    if (is_file($path)) {
        @unlink($path);
    }
}

function isJobExpired(array $job, int $jobTtlSec): bool
{
    return (microtime(true) - (float)($job['startedAt'] ?? 0)) > $jobTtlSec;
}

function expireJobAndRespond(int $userId): void
{
    deleteJobState($userId);
    respondJson(410, [
        'success' => false,
        'errorCode' => 'JOB_EXPIRED',
        'error' => 'Recalculate job expired',
    ]);
}

function loadRequestedJobOrRespond(int $userId, array $requestData, int $jobTtlSec): array
{
    $requestedJobId = trim((string)($requestData['jobId'] ?? ''));
    if ($requestedJobId === '') {
        respondJson(400, [
            'success' => false,
            'errorCode' => 'MISSING_JOB_ID',
            'error' => 'jobId is required',
        ]);
    }

    $job = loadJobState($userId);
    if (!is_array($job)) {
        respondJson(404, [
            'success' => false,
            'errorCode' => 'JOB_NOT_FOUND',
            'error' => 'No active recalculate job',
        ]);
    }
    if (isJobExpired($job, $jobTtlSec)) {
        expireJobAndRespond($userId);
    }
    if (!hash_equals((string)($job['jobId'] ?? ''), $requestedJobId)) {
        respondJson(409, [
            'success' => false,
            'errorCode' => 'JOB_ID_MISMATCH',
            'error' => 'The requested job is no longer active',
        ]);
    }

    return $job;
}

if ($requestMethod !== 'POST') {
    header('Allow: POST');
    respondJson(405, [
        'success' => false,
        'errorCode' => 'METHOD_NOT_ALLOWED',
        'error' => 'Only POST is allowed',
    ]);
}

if ($requestError !== null) {
    respondJson(400, [
        'success' => false,
        'errorCode' => 'INVALID_JSON',
        'error' => $requestError,
    ]);
}

if (!check_bitrix_sessid()) {
    logAccessIssue(
        'Batch recalculate denied: invalid sessid. User ID: '
        . (int)$USER->GetID()
        . '; sessid present: '
        . (!empty($_REQUEST['sessid']) ? 'Y' : 'N')
    );

    respondJson(403, [
        'success' => false,
        'errorCode' => 'INVALID_SESSION',
        'error' => 'Invalid session',
    ]);
}

if (!$USER->IsAdmin()) {
    logAccessIssue('Batch recalculate denied: non-admin user. User ID: ' . (int)$USER->GetID());

    respondJson(403, [
        'success' => false,
        'errorCode' => 'ADMIN_REQUIRED',
        'error' => 'Admin access required',
    ]);
}

if (!Loader::includeModule('prospektweb.calc')) {
    respondJson(500, [
        'success' => false,
        'errorCode' => 'MODULE_NOT_INSTALLED',
        'error' => 'Module not installed',
    ]);
}

$userId = (int)$USER->GetID();
$limits = loadJobLimits();
$maxOffersPerJob = (int)$limits['maxOffersPerJob'];
$maxStepDurationSec = (int)$limits['maxStepDurationSec'];
$maxBatchSize = (int)$limits['maxBatchSize'];
$jobTtlSec = (int)$limits['jobTtlSec'];
$previewTtlSec = (int)$limits['previewTtlSec'];
$action = (string)($requestData['action'] ?? 'run');

$jobLock = null;
if (in_array($action, ['preview', 'start', 'status', 'step', 'cancel', 'finish'], true)) {
    $jobLock = acquireJobLock($userId);
    register_shutdown_function(static function () use ($jobLock): void {
        if (is_resource($jobLock)) {
            flock($jobLock, LOCK_UN);
            fclose($jobLock);
        }
    });
}

if ($action === 'cancel' || $action === 'finish') {
    $job = loadRequestedJobOrRespond($userId, $requestData, $jobTtlSec);
    $jobId = (string)$job['jobId'];
    deleteJobState($userId);
    respondJson(200, [
        'success' => true,
        'jobId' => $jobId,
        'message' => 'Cancelled',
    ]);
}

if ($action === 'status') {
    $job = loadRequestedJobOrRespond($userId, $requestData, $jobTtlSec);

    $job['summary']['duration'] = round(microtime(true) - (float)$job['startedAt'], 2);
    $job['finished'] = empty($job['queue']);
    saveJobState($userId, $job);

    respondJson(200, [
        'success' => true,
        'jobId' => (string)$job['jobId'],
        'summary' => $job['summary'],
        'details' => array_values($job['details']),
        'errors' => $job['errors'],
        'finished' => $job['finished'],
        'logs' => $job['logs'],
    ]);
}

if ($action === 'analyze') {
    [$presetIds, $onlyChanged, $calcServerUrl, $timeout] = validateCommonParams($requestData);
    $service = new BatchRecalculateService($calcServerUrl, $timeout);
    $analysis = $service->getPresetAnalysis($presetIds);
    validateAnalysisContract($analysis);

    $totalOffers = 0;
    foreach ($analysis as $row) {
        $totalOffers += (int)$row['offerCount'];
    }

    respondJson(200, [
        'success' => true,
        'analysis' => $analysis,
        'meta' => [
            'totalPresets' => count($analysis),
            'totalOffers' => $totalOffers,
            'onlyChanged' => $onlyChanged,
            'calcServerUrl' => $calcServerUrl,
            'timeout' => $timeout,
        ],
    ]);
}

if ($action === 'preview') {
    [$presetIds, $onlyChanged, $calcServerUrl, $timeout] = validateCommonParams($requestData);
    $productIdsByPreset = validateProductIdsByPreset($requestData);
    // A new attempt invalidates any older proof before doing remote work.
    deletePreviewState($userId);
    $service = new BatchRecalculateService($calcServerUrl, $timeout);
    $analysis = $service->getPresetAnalysis($presetIds);
    validateAnalysisContract($analysis);
    $rows = buildScopedBatchRows($service, $analysis, $productIdsByPreset);
    $offerIds = flattenScopedOfferIds($rows);

    if (count($offerIds) > $maxOffersPerJob) {
        respondJson(429, [
            'success' => false,
            'errorCode' => 'TOO_MANY_OFFERS',
            'error' => 'Too many offers for one preview. Narrow scope and retry.',
            'meta' => [
                'maxOffersPerJob' => $maxOffersPerJob,
                'requestedOffers' => count($offerIds),
            ],
        ]);
    }

    $preview = $service->previewOffers($offerIds);
    $stateFingerprints = is_array($preview['stateFingerprints'] ?? null)
        ? $preview['stateFingerprints']
        : [];
    unset($preview['stateFingerprints']);

    $previewFingerprint = null;
    $previewExpiresAt = null;
    if (($preview['ready'] ?? false) === true) {
        try {
            $resultFingerprints = BatchPreviewFingerprintService::resultFingerprints($preview);
            $proof = BatchPreviewFingerprintService::issue(
                buildBatchPreviewScope(
                    $presetIds,
                    $productIdsByPreset,
                    $onlyChanged,
                    $calcServerUrl,
                    $timeout,
                    $rows
                ),
                $stateFingerprints,
                $preview
            );
            $issuedAt = time();
            $previewExpiresAt = $issuedAt + $previewTtlSec;
            savePreviewState($userId, [
                'contract' => (string)$proof['contract'],
                'fingerprint' => (string)$proof['fingerprint'],
                'scopeFingerprint' => (string)$proof['scopeFingerprint'],
                'stateFingerprint' => (string)$proof['stateFingerprint'],
                'previewFingerprint' => (string)$proof['previewFingerprint'],
                'resultFingerprints' => $resultFingerprints,
                'issuedAt' => $issuedAt,
                'expiresAt' => $previewExpiresAt,
            ]);
            $previewFingerprint = (string)$proof['fingerprint'];
        } catch (\Throwable $error) {
            deletePreviewState($userId);
            respondJson(500, [
                'success' => false,
                'errorCode' => 'PREVIEW_FINGERPRINT_ERROR',
                'error' => $error->getMessage(),
            ]);
        }
    }

    respondJson(200, [
        'success' => true,
        'ready' => (bool)($preview['ready'] ?? false),
        'previewFingerprint' => $previewFingerprint,
        'previewExpiresAt' => $previewExpiresAt,
        'summary' => $preview['summary'] ?? [],
        'offers' => $preview['offers'] ?? [],
        'errors' => $preview['errors'] ?? [],
        'meta' => [
            'onlyChanged' => $onlyChanged,
            'offerIds' => $offerIds,
        ],
    ]);
}

if ($action === 'start') {
    [$presetIds, $onlyChanged, $calcServerUrl, $timeout] = validateCommonParams($requestData);
    $productIdsByPreset = validateProductIdsByPreset($requestData);
    $requestedPreviewFingerprint = strtolower(trim((string)($requestData['previewFingerprint'] ?? '')));
    if (!BatchPreviewFingerprintService::isValidFingerprint($requestedPreviewFingerprint)) {
        respondJson(428, [
            'success' => false,
            'errorCode' => 'PREVIEW_REQUIRED',
            'error' => 'A fresh server preview fingerprint is required before starting catalog writes.',
        ]);
    }

    $previewState = loadPreviewState($userId);
    if (!is_array($previewState)) {
        respondJson(428, [
            'success' => false,
            'errorCode' => 'PREVIEW_REQUIRED',
            'error' => 'Run the server preview before starting catalog writes.',
        ]);
    }
    $previewIssuedAt = (int)($previewState['issuedAt'] ?? 0);
    $previewExpiresAt = (int)($previewState['expiresAt'] ?? 0);
    if ($previewIssuedAt <= 0 || $previewIssuedAt > time() + 5 || $previewExpiresAt <= time()) {
        deletePreviewState($userId);
        respondJson(410, [
            'success' => false,
            'errorCode' => 'PREVIEW_EXPIRED',
            'error' => 'The server preview expired. Run it again before writing to the catalog.',
        ]);
    }
    if ((string)($previewState['contract'] ?? '') !== BatchPreviewFingerprintService::CONTRACT
        || !BatchPreviewFingerprintService::isValidFingerprint((string)($previewState['fingerprint'] ?? ''))
        || !hash_equals((string)$previewState['fingerprint'], $requestedPreviewFingerprint)) {
        deletePreviewState($userId);
        respondJson(409, [
            'success' => false,
            'errorCode' => 'PREVIEW_MISMATCH',
            'error' => 'The supplied preview does not match the current administrator preview.',
        ]);
    }

    $existingJob = loadJobState($userId);
    if (is_array($existingJob) && isJobExpired($existingJob, $jobTtlSec)) {
        deleteJobState($userId);
        $existingJob = null;
    }
    if (is_array($existingJob) && empty($existingJob['finished']) && empty($requestData['replace'])) {
        respondJson(409, [
            'success' => false,
            'errorCode' => 'JOB_ALREADY_ACTIVE',
            'error' => 'A recalculate job is already active',
            'meta' => [
                'jobId' => (string)($existingJob['jobId'] ?? ''),
                'summary' => $existingJob['summary'] ?? [],
            ],
        ]);
    }
    $service = new BatchRecalculateService($calcServerUrl, $timeout);
    $analysis = $service->getPresetAnalysis($presetIds);
    validateAnalysisContract($analysis);
    $rows = buildScopedBatchRows($service, $analysis, $productIdsByPreset);

    $queue = [];
    $details = [];
    foreach ($rows as $row) {
        $presetId = (int)$row['presetId'];
        $presetName = (string)$row['presetName'];
        $offerIds = is_array($row['offerIds'] ?? null) ? $row['offerIds'] : [];

        $details[$presetId] = [
            'presetId' => $presetId,
            'presetName' => $presetName,
            'offerCount' => count($offerIds),
            'recalculated' => 0,
            'skipped' => 0,
            'errors' => [],
            'processed' => 0,
        ];

        foreach ($offerIds as $offerId) {
            $queue[] = [
                'presetId' => $presetId,
                'presetName' => $presetName,
                'offerId' => (int)$offerId,
            ];
        }
    }

    if (count($queue) > $maxOffersPerJob) {
        respondJson(429, [
            'success' => false,
            'errorCode' => 'TOO_MANY_OFFERS',
            'error' => 'Too many offers for one run. Narrow scope and retry.',
            'meta' => [
                'maxOffersPerJob' => $maxOffersPerJob,
                'requestedOffers' => count($queue),
            ],
        ]);
    }

    $scope = buildBatchPreviewScope(
        $presetIds,
        $productIdsByPreset,
        $onlyChanged,
        $calcServerUrl,
        $timeout,
        $rows
    );
    $scopeFingerprint = BatchPreviewFingerprintService::scopeFingerprint($scope);
    try {
        $currentStateFingerprints = $service->captureOfferWriteStateFingerprints(flattenScopedOfferIds($rows));
        $stateFingerprint = BatchPreviewFingerprintService::stateFingerprint($currentStateFingerprints);
    } catch (\Throwable $error) {
        deletePreviewState($userId);
        respondJson(409, [
            'success' => false,
            'errorCode' => 'PREVIEW_STALE',
            'error' => 'The selected offers or calculation state changed after preview. Run the preview again.',
            'meta' => ['reason' => $error->getMessage()],
        ]);
    }
    if (!hash_equals((string)($previewState['scopeFingerprint'] ?? ''), $scopeFingerprint)
        || !hash_equals((string)($previewState['stateFingerprint'] ?? ''), $stateFingerprint)) {
        deletePreviewState($userId);
        respondJson(409, [
            'success' => false,
            'errorCode' => 'PREVIEW_STALE',
            'error' => 'The selection or calculation state changed after preview. Run the preview again.',
        ]);
    }

    $approvedResultFingerprints = is_array($previewState['resultFingerprints'] ?? null)
        ? $previewState['resultFingerprints']
        : [];
    $expectedOfferIds = flattenScopedOfferIds($rows);
    $normalizedApprovedResults = [];
    foreach ($expectedOfferIds as $offerId) {
        $fingerprint = $approvedResultFingerprints[$offerId]
            ?? $approvedResultFingerprints[(string)$offerId]
            ?? null;
        if (!is_string($fingerprint) || !BatchPreviewFingerprintService::isValidFingerprint($fingerprint)) {
            deletePreviewState($userId);
            respondJson(409, [
                'success' => false,
                'errorCode' => 'PREVIEW_STALE',
                'error' => 'The reviewed preview result set is incomplete. Run the preview again.',
            ]);
        }
        $normalizedApprovedResults[(int)$offerId] = strtolower(trim($fingerprint));
    }
    ksort($normalizedApprovedResults, SORT_NUMERIC);
    if (count($normalizedApprovedResults) !== count($approvedResultFingerprints)) {
        deletePreviewState($userId);
        respondJson(409, [
            'success' => false,
            'errorCode' => 'PREVIEW_STALE',
            'error' => 'The reviewed preview result set contains unexpected offers.',
        ]);
    }

    // The proof is single-use. Consume it only after every CAS check passes.
    deletePreviewState($userId);
    if (is_array($existingJob)) {
        deleteJobState($userId);
    }

    $jobState = [
        'jobId' => bin2hex(random_bytes(16)),
        'params' => [
            'onlyChanged' => $onlyChanged,
            'calcServerUrl' => $calcServerUrl,
            'timeout' => $timeout,
        ],
        'startedAt' => microtime(true),
        'summary' => [
            'totalPresets' => count($analysis),
            'processedPresets' => 0,
            'totalOffers' => count($queue),
            'processedOffers' => 0,
            'recalculated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'duration' => 0,
        ],
        'details' => $details,
        'errors' => [],
        'logs' => [
            ['ts' => date('H:i:s'), 'message' => 'Запущена задача пакетного пересчёта'],
        ],
        'previewFingerprint' => $requestedPreviewFingerprint,
        'approvedStateFingerprints' => $currentStateFingerprints,
        'approvedResultFingerprints' => $normalizedApprovedResults,
        'queue' => $queue,
        'finished' => empty($queue),
    ];

    saveJobState($userId, $jobState);

    respondJson(200, [
        'success' => true,
        'jobId' => (string)$jobState['jobId'],
        'summary' => $jobState['summary'],
        'details' => array_values($jobState['details']),
        'errors' => $jobState['errors'],
        'finished' => $jobState['finished'],
        'logs' => $jobState['logs'],
    ]);
}

if ($action === 'step') {
    $job = loadRequestedJobOrRespond($userId, $requestData, $jobTtlSec);

    if (empty($job['queue'])) {
        $job['finished'] = true;
        $job['summary']['duration'] = round(microtime(true) - (float)$job['startedAt'], 2);
        saveJobState($userId, $job);

        respondJson(200, [
            'success' => true,
            'jobId' => (string)$job['jobId'],
            'summary' => $job['summary'],
            'details' => array_values($job['details']),
            'errors' => $job['errors'],
            'finished' => true,
            'logs' => $job['logs'],
        ]);
    }

    $params = $job['params'];
    $service = new BatchRecalculateService((string)$params['calcServerUrl'], (int)$params['timeout']);
    $stepStartedAt = microtime(true);

    while (!empty($job['queue'])) {
        if ((microtime(true) - $stepStartedAt) >= $maxStepDurationSec) {
            $job['logs'][] = ['ts' => date('H:i:s'), 'message' => 'Шаг остановлен по лимиту времени'];
            break;
        }

        $firstItem = array_shift($job['queue']);
        $presetId = (int)$firstItem['presetId'];
        $presetName = (string)$firstItem['presetName'];
        $batchItems = [$firstItem];

        while (count($batchItems) < $maxBatchSize && !empty($job['queue'])) {
            $nextItem = $job['queue'][0];
            if ((int)$nextItem['presetId'] !== $presetId) {
                break;
            }
            $batchItems[] = array_shift($job['queue']);
        }

        $offerIds = array_map(static function (array $item): int {
            return (int)$item['offerId'];
        }, $batchItems);
        $approvedStateFingerprints = is_array($job['approvedStateFingerprints'] ?? null)
            ? $job['approvedStateFingerprints']
            : [];
        $approvedResultFingerprints = is_array($job['approvedResultFingerprints'] ?? null)
            ? $job['approvedResultFingerprints']
            : [];
        $batchExpectedState = [];
        $batchExpectedResults = [];
        $missingApprovedState = false;
        foreach ($offerIds as $offerId) {
            $approvedState = $approvedStateFingerprints[$offerId]
                ?? $approvedStateFingerprints[(string)$offerId]
                ?? null;
            if (!is_array($approvedState)) {
                $missingApprovedState = true;
                break;
            }
            $approvedResult = $approvedResultFingerprints[$offerId]
                ?? $approvedResultFingerprints[(string)$offerId]
                ?? null;
            if (!is_string($approvedResult)
                || !BatchPreviewFingerprintService::isValidFingerprint($approvedResult)) {
                $missingApprovedState = true;
                break;
            }
            $batchExpectedState[$offerId] = $approvedState;
            $batchExpectedResults[$offerId] = strtolower(trim($approvedResult));
        }

        if ($missingApprovedState) {
            $batchResults = [];
            foreach ($offerIds as $offerId) {
                $batchResults[$offerId] = [
                    'status' => 'error',
                    'error' => 'Задача не содержит подтверждённое состояние preview. Запустите новый пересчёт через предварительную проверку.',
                ];
            }
        } else {
            $receiptOfferIds = $offerIds;
            sort($receiptOfferIds, SORT_NUMERIC);
            $batchRequestId = hash(
                'sha256',
                (string)$job['jobId'] . ':' . $presetId . ':' . implode(',', $receiptOfferIds)
            );
            $batchResults = $service->recalculateOffers(
                $offerIds,
                (bool)$params['onlyChanged'],
                $batchExpectedState,
                $batchExpectedResults,
                $userId,
                $batchRequestId
            );
        }

        foreach ($batchItems as $batchItem) {
            $offerId = (int)$batchItem['offerId'];
            $result = $batchResults[$offerId] ?? [
                'status' => 'error',
                'error' => 'Не удалось получить результат пересчёта',
            ];
            $status = (string)($result['status'] ?? 'error');

            $job['summary']['processedOffers']++;
            $job['details'][$presetId]['processed']++;

            if ($status === 'recalculated') {
                $job['summary']['recalculated']++;
                $job['details'][$presetId]['recalculated']++;
                $job['logs'][] = ['ts' => date('H:i:s'), 'message' => 'ТП #' . $offerId . ' пересчитан (' . $presetName . ')'];
            } elseif ($status === 'skipped') {
                $job['summary']['skipped']++;
                $job['details'][$presetId]['skipped']++;
                $job['logs'][] = ['ts' => date('H:i:s'), 'message' => 'ТП #' . $offerId . ' пропущен (без изменений)'];
            } else {
                $errorMessage = (string)($result['error'] ?? 'Неизвестная ошибка');
                $job['summary']['errors']++;
                $job['details'][$presetId]['errors'][] = $errorMessage;
                $job['errors'][] = [
                    'presetId' => $presetId,
                    'offerId' => $offerId,
                    'error' => $errorMessage,
                ];
                $job['logs'][] = ['ts' => date('H:i:s'), 'message' => 'ТП #' . $offerId . ' ошибка: ' . $errorMessage];
            }
        }
    }

    $processedPresetCount = 0;
    foreach ($job['details'] as $detail) {
        $processed = (int)$detail['processed'];
        if ($processed >= (int)$detail['offerCount']) {
            $processedPresetCount++;
        }
    }

    $job['summary']['processedPresets'] = $processedPresetCount;
    $job['summary']['duration'] = round(microtime(true) - (float)$job['startedAt'], 2);
    $job['finished'] = empty($job['queue']);

    if ($job['finished']) {
        $job['logs'][] = ['ts' => date('H:i:s'), 'message' => 'Пересчёт завершён'];
    }

    if (count($job['logs']) > 400) {
        $job['logs'] = array_slice($job['logs'], -400);
    }

    saveJobState($userId, $job);

    respondJson(200, [
        'success' => true,
        'jobId' => (string)$job['jobId'],
        'summary' => $job['summary'],
        'details' => array_values($job['details']),
        'errors' => $job['errors'],
        'finished' => $job['finished'],
        'logs' => $job['logs'],
    ]);
}

respondJson(400, [
    'success' => false,
    'errorCode' => 'UNSUPPORTED_ACTION',
    'error' => 'Unsupported action',
]);
