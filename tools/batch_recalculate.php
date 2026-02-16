<?php
/**
 * AJAX-эндпоинт для пакетного пересчёта калькуляций
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', false);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: application/json; charset=UTF-8');

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Prospektweb\Calc\Services\BatchRecalculateService;

global $USER;

// Проверка авторизации и прав доступа
if (!$USER || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Access denied',
    ]);
    exit;
}

// Читаем JSON-тело запроса ОДИН РАЗ (php://input — одноразовый поток)
$requestBody = file_get_contents('php://input');
$requestData = json_decode($requestBody, true);

// Подставляем sessid из JSON в $_REQUEST,
// т.к. PHP не парсит application/json в $_POST,
// и check_bitrix_sessid() не найдёт его без этого
if (is_array($requestData) && isset($requestData['sessid'])) {
    $_REQUEST['sessid'] = $requestData['sessid'];
}

// Проверка сессии
if (!check_bitrix_sessid()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid session',
    ]);
    exit;
}

// Загрузка модуля
if (!Loader::includeModule('prospektweb.calc')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Module not installed',
    ]);
    exit;
}

// requestData уже распарсен выше, проверяем
if ($requestData === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON',
    ]);
    exit;
}

// Параметры запроса
$presetIds = $requestData['presetIds'] ?? [];
$onlyChanged = (bool)($requestData['onlyChanged'] ?? true);
$calcServerUrl = (string)($requestData['calcServerUrl'] ?? Option::get('prospektweb.calc', 'CALC_SERVER_URL', 'https://pwrt.ru/calc-api'));
$timeout = (int)($requestData['timeout'] ?? 30);

// Валидация URL для предотвращения SSRF атак
if (!filter_var($calcServerUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid calc server URL',
    ]);
    exit;
}

// Проверка, что URL использует допустимые схемы
$urlParts = parse_url($calcServerUrl);
if (!in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid URL scheme. Only http and https are allowed.',
    ]);
    exit;
}

// Валидация остальных параметров
if (!is_array($presetIds)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'presetIds must be an array',
    ]);
    exit;
}

if ($timeout < 1 || $timeout > 300) {
    $timeout = 30;
}

try {
    // Создаём сервис пересчёта
    $service = new BatchRecalculateService($calcServerUrl, $timeout);
    
    // Выполняем пересчёт
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
