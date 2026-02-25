<?php
/**
 * AJAX-эндпоинт для пакетного пересчёта калькуляций
 */

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Services\BatchRecalculateService;

global $APPLICATION;
global $USER;

$APPLICATION->RestartBuffer();
header('Content-Type: application/json; charset=UTF-8');

function respondJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
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

function getJobFilePath(int $userId): string
{
    $base = $_SERVER['DOCUMENT_ROOT'] . '/upload/prospektweb.calc';
    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
    }

    return $base . '/batch_recalc_job_user_' . $userId . '.json';
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
    file_put_contents(getJobFilePath($userId), json_encode($state, JSON_UNESCAPED_UNICODE));
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

$requestBody = file_get_contents('php://input');
$requestData = json_decode((string)$requestBody, true);

if ($requestData === null && !empty($requestBody)) {
    respondJson(400, [
        'success' => false,
        'errorCode' => 'INVALID_JSON',
        'error' => 'Invalid JSON',
    ]);
}

if ($requestData === null) {
    $requestData = [];
}

if (empty($_REQUEST['sessid']) && isset($requestData['sessid'])) {
    $_REQUEST['sessid'] = (string)$requestData['sessid'];
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

if ($action === 'cancel' || $action === 'finish') {
    deleteJobState($userId);
    respondJson(200, ['success' => true, 'message' => 'Cancelled']);
}

if ($action === 'status') {
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

    $job['summary']['duration'] = round(microtime(true) - (float)$job['startedAt'], 2);
    $job['finished'] = empty($job['queue']);
    saveJobState($userId, $job);

    respondJson(200, [
        'success' => true,
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

    $service = new BatchRecalculateService($calcServerUrl, $timeout);
    $analysis = $service->getPresetAnalysis($presetIds);

    $queue = [];
    $details = [];
    foreach ($analysis as $row) {
        $presetId = (int)$row['presetId'];
        $presetName = (string)$row['presetName'];
        $offerIds = $service->getOfferIdsForPreset($presetId);

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
        'summary' => $jobState['summary'],
        'details' => array_values($jobState['details']),
        'errors' => $jobState['errors'],
        'finished' => $jobState['finished'],
        'logs' => $jobState['logs'],
    ]);
}

if ($action === 'step') {
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

    if (empty($job['queue'])) {
        $job['finished'] = true;
        $job['summary']['duration'] = round(microtime(true) - (float)$job['startedAt'], 2);
        saveJobState($userId, $job);

        respondJson(200, [
            'success' => true,
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
