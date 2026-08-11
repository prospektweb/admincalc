<?php

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? '');
$requestContentType = strtolower(trim((string)strtok((string)($_SERVER['CONTENT_TYPE'] ?? ''), ';')));
$request = [];
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
            $request = $decodeJsonObject($_POST['payload']);
            if ($request === null) {
                $request = [];
                $requestError = 'Request payload must be a JSON object';
            }
        } else {
            $request = $_POST;
            if ((string)($request['action'] ?? 'get') === 'save') {
                $settings = $decodeJsonObject($request['settings'] ?? null);
                if ($settings === null) {
                    $requestError = 'settings must be a JSON object string';
                } else {
                    $request['settings'] = $settings;
                }
            }
        }
    } else {
        $rawBody = (string)file_get_contents('php://input');
        $request = $decodeJsonObject($rawBody);
        if ($request === null) {
            $request = [];
            $requestError = 'Request body must be a JSON object';
        }
    }
}

if (empty($_REQUEST['sessid']) && isset($request['sessid']) && is_scalar($request['sessid'])) {
    $requestSessid = (string)$request['sessid'];
    $_REQUEST['sessid'] = $requestSessid;
    if (empty($_POST['sessid'])) {
        $_POST['sessid'] = $requestSessid;
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Prospektweb\Calc\Services\ControlCenterSettingsService;

global $APPLICATION, $USER;

$APPLICATION->RestartBuffer();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

$respond = static function (int $statusCode, array $payload): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die();
};

if ($requestMethod !== 'POST') {
    header('Allow: POST');
    $respond(405, [
        'success' => false,
        'errorCode' => 'METHOD_NOT_ALLOWED',
        'error' => 'Only POST is allowed',
    ]);
}

if ($requestError !== null) {
    $respond(400, [
        'success' => false,
        'errorCode' => 'INVALID_JSON',
        'error' => $requestError,
    ]);
}

if (!check_bitrix_sessid()) {
    $respond(403, [
        'success' => false,
        'errorCode' => 'INVALID_SESSION',
        'error' => 'Invalid session',
    ]);
}

if (!$USER || !$USER->IsAdmin()) {
    $respond(403, [
        'success' => false,
        'errorCode' => 'ADMIN_REQUIRED',
        'error' => 'Admin access required',
    ]);
}

if (!Loader::includeModule('prospektweb.calc')) {
    $respond(500, [
        'success' => false,
        'errorCode' => 'MODULE_NOT_INSTALLED',
        'error' => 'Module prospektweb.calc is not installed',
    ]);
}

$action = (string)($request['action'] ?? 'get');
$service = new ControlCenterSettingsService();

try {
    if ($action === 'get') {
        $respond(200, [
            'success' => true,
            'data' => $service->getSettings(),
        ]);
    }

    if ($action === 'save') {
        $settings = $request['settings'] ?? null;
        if (!is_array($settings)) {
            $respond(422, [
                'success' => false,
                'errorCode' => 'VALIDATION_ERROR',
                'error' => 'settings must be a JSON object',
            ]);
        }

        $respond(200, [
            'success' => true,
            'data' => $service->saveSettings($settings, (string)($request['revision'] ?? '')),
        ]);
    }

    $respond(400, [
        'success' => false,
        'errorCode' => 'UNSUPPORTED_ACTION',
        'error' => 'Unsupported action',
    ]);
} catch (\InvalidArgumentException $exception) {
    $respond(422, [
        'success' => false,
        'errorCode' => 'VALIDATION_ERROR',
        'error' => $exception->getMessage(),
    ]);
} catch (\RuntimeException $exception) {
    if ($exception->getCode() === 409 && $exception->getMessage() === 'SETTINGS_REVISION_CONFLICT') {
        $respond(409, [
            'success' => false,
            'errorCode' => 'REVISION_CONFLICT',
            'error' => 'Настройки были изменены в другой вкладке. Обновите данные и повторите сохранение.',
        ]);
    }

    $respond(500, [
        'success' => false,
        'errorCode' => 'SETTINGS_ERROR',
        'error' => $exception->getMessage(),
    ]);
} catch (\Throwable $exception) {
    $respond(500, [
        'success' => false,
        'errorCode' => 'INTERNAL_ERROR',
        'error' => 'Не удалось обработать настройки',
    ]);
}
