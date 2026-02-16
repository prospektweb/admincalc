<?php
/**
 * AJAX-эндпоинт для пакетного пересчёта калькуляций
 */

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

global $APPLICATION;
$APPLICATION->RestartBuffer();

header('Content-Type: application/json; charset=UTF-8');

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Prospektweb\Calc\Services\BatchRecalculateService;

global $USER;

// Читаем JSON-тело запроса О��ИН РАЗ
$requestBody = file_get_contents('php://input');
$requestData = json_decode($requestBody, true);

// Подставляем sessid из JSON в $_REQUEST
if (is_array($requestData) && isset($requestData['sessid'])) {
    $_REQUEST['sessid'] = $requestData['sessid'];
}

// Проверка сессии + права администратора
if (!check_bitrix_sessid() || !$USER->IsAdmin()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Access denied or invalid session',
    ]);
    die();
}

// Загрузка модуля
if (!Loader::includeModule('prospektweb.calc')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Module not installed',
    ]);
    die();
}

if ($requestData === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON',
    ]);
    die();
}

// Параметры запроса
$presetIds = $requestData['presetIds'] ?? [];
$onlyChanged = (bool)($requestData['onlyChanged'] ?? true);
$calcServerUrl = (string)($requestData['calcServerUrl'] ?? Option::get('prospektweb.calc', 'CALC_SERVER_URL', 'https://pwrt.ru/calc-api'));
$timeout = (int)($requestData['timeout'] ?? 30);

// Валидация URL
if (!filter_var($calcServerUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid calc server URL',
    ]);
    die();
}

$urlParts = parse_url($calcServerUrl);
if (!in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid URL scheme. Only http and https are allowed.',
    ]);
    die();
}

if (!is_array($presetIds)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'presetIds must be an array',
    ]);
    die();
}

if ($timeout < 1 || $timeout > 300) {
    $timeout = 30;
}

try {
    $service = new BatchRecalculateService($calcServerUrl, $timeout);
    $result = $service->recalculate($presetIds, $onlyChanged);
    
    echo json_encode([
        'success' => true,
        'summary' => $result['summary'] ?? [],
        'details' => $result['details'] ?? [],
        'errors' => $result['errors'] ?? [],
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Recalculation failed: ' . $e->getMessage(),
    ]);
}
