<?php

declare(strict_types=1);

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);

$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
$request = [];
if ($method === 'POST') {
    try {
        $decoded = json_decode((string)file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
        $request = is_array($decoded) ? $decoded : [];
    } catch (\JsonException $error) {
        $request = [];
    }
}
if (isset($request['sessid']) && is_scalar($request['sessid'])) {
    $_REQUEST['sessid'] = (string)$request['sessid'];
    $_POST['sessid'] = (string)$request['sessid'];
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Prospektweb\Calc\Install\SupplierDirectorySchemaService;

global $APPLICATION, $USER;

$APPLICATION->RestartBuffer();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die();
};

if ($method !== 'POST') {
    header('Allow: POST');
    $respond(405, ['success' => false, 'errorCode' => 'METHOD_NOT_ALLOWED']);
}
if (!check_bitrix_sessid()) {
    $respond(403, ['success' => false, 'errorCode' => 'INVALID_SESSION']);
}
if (!$USER || !$USER->IsAdmin()) {
    $respond(403, ['success' => false, 'errorCode' => 'ADMIN_REQUIRED']);
}
if (!Loader::includeModule('prospektweb.calc')) {
    $respond(500, ['success' => false, 'errorCode' => 'MODULE_NOT_INSTALLED']);
}

$service = new SupplierDirectorySchemaService();
$action = (string)($request['action'] ?? 'analyze');

try {
    if ($action === 'analyze') {
        $respond(200, ['success' => true, 'data' => $service->analyze()]);
    }
    if ($action === 'apply') {
        $respond(200, [
            'success' => true,
            'data' => $service->apply(
                (string)($request['expectedPlanHash'] ?? ''),
                (int)$USER->GetID()
            ),
        ]);
    }
    $respond(404, ['success' => false, 'errorCode' => 'UNKNOWN_ACTION']);
} catch (\Throwable $error) {
    $status = $error->getCode() === 409 ? 409 : 500;
    $respond($status, [
        'success' => false,
        'errorCode' => $status === 409 ? 'STALE_OR_UNSAFE_STATE' : 'SUPPLIER_SCHEMA_ERROR',
        'error' => $error->getMessage(),
    ]);
}
