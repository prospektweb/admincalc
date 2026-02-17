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

    $maxOffersPerJob = (int)Option::get($moduleId, 'BATCH_RECALC_MAX_OFFERS', '400');
    if ($maxOffersPerJob < 50) {
        $maxOffersPerJob = 50;
    }

    $maxStepDurationSec = (int)Option::get($moduleId, 'BATCH_RECALC_MAX_STEP_DURATION', '6');
    if ($maxStepDurationSec < 2) {
        $maxStepDurationSec = 2;
    }

    $maxBatchSize = (int)Option::get($moduleId, 'BATCH_RECALC_MAX_BATCH_SIZE', '3');
    if ($maxBatchSize < 1) {
        $maxBatchSize = 1;
    }

    $jobTtlSec = (int)Option::get($moduleId, 'BATCH_RECALC_JOB_TTL', '1800');
    if ($jobTtlSec < 60) {
        $jobTtlSec = 60;
    }

    return [
        'maxOffersPerJob' => $maxOffersPerJob,
        'maxStepDurationSec' => $maxStepDurationSec,
        'maxBatchSize' => $maxBatchSize,
        'jobTtlSec' => $jobTtlSec,
    ];
}

function isJobExpired(array $job, int $jobTtlSec): bool
{
    return (microtime(true) - (float)($job['startedAt'] ?? 0)) > $jobTtlSec;
}

function expireJobAndRespond(string $jobKey): void
{
    unset($_SESSION[$jobKey]);
    respondJson(410, [
        'success' => false,
        'errorCode' => 'JOB_EXPIRED',
        'error' => 'Recalculate job expired',
    ]);
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

$jobKey = 'PROSPEKTWEB_CALC_BATCH_RECALC_JOB';
$limits = loadJobLimits();
$maxOffersPerJob = (int)$limits['maxOffersPerJob'];
$maxStepDurationSec = (int)$limits['maxStepDurationSec'];
$maxBatchSize = (int)$limits['maxBatchSize'];
$jobTtlSec = (int)$limits['jobTtlSec'];
$action = (string)($requestData['action'] ?? 'run');

if ($action === 'cancel') {
    unset($_SESSION[$jobKey]);
    respondJson(200, ['success' => true, 'message' => 'Cancelled']);
}

if ($action === 'status') {
    $job = $_SESSION[$jobKey] ?? null;
    if (!is_array($job)) {
        respondJson(404, [
            'success' => false,
            'errorCode' => 'JOB_NOT_FOUND',
            'error' => 'No active recalculate job',
        ]);
    }

    if (isJobExpired($job, $jobTtlSec)) {
        expireJobAndRespond($jobKey);
    }

    $job['summary']['duration'] = round(microtime(true) - (float)$job['startedAt'], 2);
    $job['finished'] = empty($job['queue']);
    $_SESSION[$jobKey] = $job;

    respondJson(200, [
        'success' => true,
        'summary' => $job['summary'],
        'details' => array_values($job['details']),
        'errors' => $job['errors'],
        'finished' => $job['finished'],
        'logs' => $job['logs'],
    ]);
}

if ($action === 'start') {
    [$presetIds, $onlyChanged, $calcServerUrl, $timeout] = validateCommonParams($requestData);

    $service = new BatchRecalculateService($calcServerUrl, $timeout);
    $presets = $service->getPresetsWithOfferCount();
    if (!empty($presetIds)) {
        $presets = array_values(array_filter($presets, static function (array $preset) use ($presetIds): bool {
            return in_array((int)$preset['id'], $presetIds, true);
        }));
    }

    $queue = [];
    $details = [];
    foreach ($presets as $preset) {
        $presetId = (int)$preset['id'];
        $presetName = (string)$preset['name'];
        $offerIds = $service->getOfferIdsForPreset($presetId);

        $details[$presetId] = [
            'presetId' => $presetId,
            'presetName' => $presetName,
            'offerCount' => count($offerIds),
            'recalculated' => 0,
            'skipped' => 0,
            'errors' => [],
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

    $_SESSION[$jobKey] = [
        'params' => [
            'onlyChanged' => $onlyChanged,
            'calcServerUrl' => $calcServerUrl,
            'timeout' => $timeout,
        ],
        'startedAt' => microtime(true),
        'summary' => [
            'totalPresets' => count($presets),
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

    respondJson(200, [
        'success' => true,
        'summary' => $_SESSION[$jobKey]['summary'],
        'details' => array_values($_SESSION[$jobKey]['details']),
        'errors' => $_SESSION[$jobKey]['errors'],
        'finished' => $_SESSION[$jobKey]['finished'],
        'logs' => $_SESSION[$jobKey]['logs'],
    ]);
}

if ($action === 'step') {
    $job = $_SESSION[$jobKey] ?? null;
    if (!is_array($job)) {
        respondJson(404, [
            'success' => false,
            'errorCode' => 'JOB_NOT_FOUND',
            'error' => 'No active recalculate job',
        ]);
    }

    if (isJobExpired($job, $jobTtlSec)) {
        expireJobAndRespond($jobKey);
    }

    if (empty($job['queue'])) {
        $job['finished'] = true;
        $job['summary']['duration'] = round(microtime(true) - (float)$job['startedAt'], 2);
        $_SESSION[$jobKey] = $job;

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
    $batchSize = $maxBatchSize;
    $service = new BatchRecalculateService((string)$params['calcServerUrl'], (int)$params['timeout']);

    $stepStartedAt = microtime(true);

    for ($i = 0; $i < $batchSize && !empty($job['queue']); $i++) {
        if ((microtime(true) - $stepStartedAt) >= $maxStepDurationSec) {
            $job['logs'][] = ['ts' => date('H:i:s'), 'message' => 'Шаг остановлен по лимиту времени'];
            break;
        }
        $item = array_shift($job['queue']);
        $presetId = (int)$item['presetId'];
        $offerId = (int)$item['offerId'];
        $presetName = (string)$item['presetName'];

        $result = $service->recalculateOffer($offerId, (bool)$params['onlyChanged']);
        $status = (string)($result['status'] ?? 'error');
        $job['summary']['processedOffers']++;

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

    $processedPresetCount = 0;
    foreach ($job['details'] as $detail) {
        $processed = (int)$detail['recalculated'] + (int)$detail['skipped'] + count($detail['errors']);
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

    if (count($job['logs']) > 300) {
        $job['logs'] = array_slice($job['logs'], -300);
    }

    $_SESSION[$jobKey] = $job;

    respondJson(200, [
        'success' => true,
        'summary' => $job['summary'],
        'details' => array_values($job['details']),
        'errors' => $job['errors'],
        'finished' => $job['finished'],
        'logs' => $job['logs'],
    ]);
}

// legacy fallback (single-shot mode)
[$presetIds, $onlyChanged, $calcServerUrl, $timeout] = validateCommonParams($requestData);

try {
    $service = new BatchRecalculateService($calcServerUrl, $timeout);
    $result = $service->recalculate($presetIds, $onlyChanged);

    respondJson(200, [
        'success' => true,
        'summary' => $result['summary'] ?? [],
        'details' => $result['details'] ?? [],
        'errors' => $result['errors'] ?? [],
        'finished' => true,
        'logs' => [
            ['ts' => date('H:i:s'), 'message' => 'Пересчёт завершён в режиме совместимости'],
        ],
    ]);
} catch (\Throwable $e) {
    respondJson(500, [
        'success' => false,
        'errorCode' => 'RECALCULATION_FAILED',
        'error' => 'Recalculation failed: ' . $e->getMessage(),
    ]);
}
