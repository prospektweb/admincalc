<?php

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', 'Y');
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Prospektweb\Calc\Diagnostic\ModuleDiagnostic;

global $USER;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'errorCode' => 'METHOD_NOT_ALLOWED',
        'error' => 'Only POST is allowed',
    ], JSON_UNESCAPED_UNICODE);
    die();
}

// Проверка прав доступа
if (!check_bitrix_sessid() || !$USER->IsAdmin()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'errorCode' => 'ACCESS_DENIED',
        'error' => 'Access denied',
    ], JSON_UNESCAPED_UNICODE);
    die();
}

// Подключение модуля
if (!Loader::includeModule('prospektweb.calc')) {
    http_response_code(500);
    echo json_encode(['error' => 'Module prospektweb.calc not loaded'], JSON_UNESCAPED_UNICODE);
    die();
}

$action = (string)($_REQUEST['action'] ?? 'run');

try {
    switch ($action) {
        case 'run':
            $diagnostic = new ModuleDiagnostic();
            $result = $diagnostic->runFullDiagnostic();
            echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        case 'activate_assignment_guard':
            http_response_code(410);
            echo json_encode(['success' => false, 'errorCode' => 'LEGACY_STORAGE_RETIRED', 'error' => 'Привязки управляются документным хранилищем. Старый обработчик не включается.'], JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
die();
