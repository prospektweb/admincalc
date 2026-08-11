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
use Prospektweb\Calc\Services\BatchRecalculateService;

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

    return [
        'maxOffersPerJob' => $maxOffersPerJob,
        'maxStepDurationSec' => $maxStepDurationSec,
        'maxBatchSize' => $maxBatchSize,
        'jobTtlSec' => $jobTtlSec,
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

    $result = [];
    foreach ($rawMap as $presetId => $productIds) {
        $presetId = (int)$presetId;
        if ($presetId <= 0) {
            continue;
        }

        if (!is_array($productIds)) {
            respondJson(400, [
                'success' => false,
                'errorCode' => 'INVALID_PRODUCT_IDS',
                'error' => 'product IDs must be arrays',
            ]);
        }

        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static function (int $productId): bool {
            return $productId > 0;
        })));

        $result[$presetId] = $productIds;
    }

    return $result;
}

function validateCommonParams(array $requestData): array
{
    $presetIds = $requestData['presetIds'] ?? [];
    $onlyChanged = (bool)($requestData['onlyChanged'] ?? true);
    $calcServerUrl = (string)($requestData['calcServerUrl'] ?? Option::get('prospektweb.calc', 'CALC_SERVER_URL', 'https://pwrt.ru/calc-api'));
    $timeout = (int)($requestData['timeout'] ?? 30);

    if (!is_array($presetIds)) {
        respondJson(400, [
            'success' => false,
            'errorCode' => 'INVALID_PRESET_IDS',
            'error' => 'presetIds must be an array',
        ]);
    }

    if (!filter_var($calcServerUrl, FILTER_VALIDATE_URL)) {
        respondJson(400, [
            'success' => false,
            'errorCode' => 'INVALID_CALC_SERVER_URL',
            'error' => 'Invalid calc server URL',
        ]);
    }

    $urlParts = parse_url($calcServerUrl);
    if (!in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)) {
        respondJson(400, [
            'success' => false,
            'errorCode' => 'INVALID_URL_SCHEME',
            'error' => 'Invalid URL scheme. Only http and https are allowed.',
        ]);
    }

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

function getLegacyJobFilePaths(int $userId): array
{
    $base = $_SERVER['DOCUMENT_ROOT'] . '/upload/prospektweb.calc';
    return [
        $base . '/batch_recalc_job_user_' . $userId . '.json',
        $base . '/private/batch_recalc_job_user_' . $userId . '.json',
    ];
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
    foreach (getLegacyJobFilePaths($userId) as $legacyPath) {
        if (!is_file($path) && is_file($legacyPath)) {
            $legacyContent = file_get_contents($legacyPath);
            $legacyState = is_string($legacyContent) ? json_decode($legacyContent, true) : null;
            if (is_array($legacyState)) {
                $legacyState['jobId'] = (string)($legacyState['jobId'] ?? bin2hex(random_bytes(16)));
                saveJobState($userId, $legacyState);
            }
        }
        if (is_file($legacyPath)) {
            @unlink($legacyPath);
        }
    }
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
    foreach (getLegacyJobFilePaths($userId) as $legacyPath) {
        if (is_file($legacyPath)) {
            @unlink($legacyPath);
        }
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
$action = (string)($requestData['action'] ?? 'run');

$jobLock = null;
if (in_array($action, ['start', 'status', 'step', 'cancel', 'finish'], true)) {
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

if ($action === 'start') {
    [$presetIds, $onlyChanged, $calcServerUrl, $timeout] = validateCommonParams($requestData);
    $productIdsByPreset = validateProductIdsByPreset($requestData);

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
    if (is_array($existingJob)) {
        deleteJobState($userId);
    }

    $service = new BatchRecalculateService($calcServerUrl, $timeout);
    $analysis = $service->getPresetAnalysis($presetIds);

    $queue = [];
    $details = [];
    foreach ($analysis as $row) {
        $presetId = (int)$row['presetId'];
        $presetName = (string)$row['presetName'];
        $offerIds = isset($productIdsByPreset[$presetId])
            ? $service->getOfferIdsForPresetProducts($presetId, $productIdsByPreset[$presetId])
            : $service->getOfferIdsForPreset($presetId);

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

        $batchResults = $service->recalculateOffers($offerIds, (bool)$params['onlyChanged']);

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
