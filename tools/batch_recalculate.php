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

/**
 * @param int   $statusCode
 * @param array $payload
 */
function respondJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    die();
}

/**
 * @param string $message
 */
function logAccessIssue(string $message): void
{
    if (function_exists('AddMessage2Log')) {
        AddMessage2Log($message, 'prospektweb.calc');
    }
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

try {
    $service = new BatchRecalculateService($calcServerUrl, $timeout);
    $result = $service->recalculate($presetIds, $onlyChanged);

    respondJson(200, [
        'success' => true,
        'summary' => $result['summary'] ?? [],
        'details' => $result['details'] ?? [],
        'errors' => $result['errors'] ?? [],
    ]);
} catch (\Throwable $e) {
    respondJson(500, [
        'success' => false,
        'errorCode' => 'RECALCULATION_FAILED',
        'error' => 'Recalculation failed: ' . $e->getMessage(),
    ]);
}
