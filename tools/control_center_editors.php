<?php

declare(strict_types=1);

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
        }
    } else {
        $request = $decodeJsonObject((string)file_get_contents('php://input'));
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
use Prospektweb\Calc\Services\ControlCenterEditorsService;

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

$action = (string)($request['action'] ?? 'catalog');
$service = new ControlCenterEditorsService();
$assertAllowedRequestKeys = static function (array $allowedKeys) use ($request): void {
    foreach (array_keys($request) as $requestKey) {
        if (!is_string($requestKey) || !in_array($requestKey, $allowedKeys, true)) {
            throw new \InvalidArgumentException('Request contains unsupported fields');
        }
    }
};

try {
    if ($action === 'catalog') {
        $assertAllowedRequestKeys(['action', 'sessid']);
        $respond(200, [
            'success' => true,
            'data' => $service->getCatalog(),
        ]);
    }

    if ($action === 'validate_calculation_launch') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'productId', 'offerIds']);
        $presetId = $request['presetId'] ?? null;
        $productId = $request['productId'] ?? null;
        $offerIds = $request['offerIds'] ?? null;
        if ((!is_int($presetId) && !(is_string($presetId) && preg_match('/^[1-9][0-9]*$/', $presetId)))
            || (!is_int($productId) && !(is_string($productId) && preg_match('/^[1-9][0-9]*$/', $productId)))
            || !is_array($offerIds)) {
            throw new \InvalidArgumentException('presetId, productId and offerIds are required');
        }

        $respond(200, [
            'success' => true,
            'data' => $service->validateCalculationLaunch((int)$presetId, (int)$productId, $offerIds),
        ]);
    }

    if ($action === 'validate_storefront_launch') {
        $assertAllowedRequestKeys(['action', 'sessid', 'productId']);
        $productId = $request['productId'] ?? null;
        if (!is_int($productId) && !(is_string($productId) && preg_match('/^[1-9][0-9]*$/', $productId))) {
            throw new \InvalidArgumentException('productId must be a positive integer');
        }

        $respond(200, [
            'success' => true,
            'data' => $service->validateStorefrontLaunch((int)$productId),
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
    $respond(409, [
        'success' => false,
        'errorCode' => 'EDITOR_UNAVAILABLE',
        'error' => $exception->getMessage(),
    ]);
} catch (\Throwable $exception) {
    $respond(500, [
        'success' => false,
        'errorCode' => 'INTERNAL_ERROR',
        'error' => 'Unable to prepare the editor workspace',
    ]);
}
