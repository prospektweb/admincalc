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

// Module-owned layout workflow enforces its configured reviewer/admin roles.
if (($request['action'] ?? '') === 'layout') {
    try {
        if (!Loader::includeModule('prospektweb.layoutfiles')) throw new \DomainException('MODULE_NOT_INSTALLED');
        $respond(200, ['success'=>true,'data'=>\Prospektweb\LayoutFiles\LayoutAdmin::dispatch((array)($request['layout'] ?? []))]);
    } catch (\DomainException $e) {
        $code=$e->getMessage();
        $status=$code==='ACCESS_DENIED'?403:(in_array($code,['REVISION_CONFLICT','IDEMPOTENCY_CONFLICT','BUSY'],true)?409:422);
        $messages=['ACCESS_DENIED'=>'Недостаточно прав на это действие.','REVISION_CONFLICT'=>'Данные изменились. Перечитайте комплект и повторите действие.',
            'OAUTH_CLIENT_ID_REQUIRED'=>'Укажите корректный Client ID приложения Яндекса.','OAUTH_SECRET_REQUIRED'=>'Введите Client Secret для этого приложения.',
            'OAUTH_SECRET_INVALID'=>'Проверьте Client Secret: он не должен содержать пробелы.','OAUTH_FLOW_EXPIRED'=>'Подключение истекло. Начните заново.',
            'OAUTH_CODE_REQUIRED'=>'Введите код со страницы Яндекса.','OAUTH_CODE_REJECTED'=>'Код не принят Яндексом. Получите новый код и повторите подключение.',
            'INVALID_STORAGE_PATH'=>'Укажите абсолютный путь папки без переходов на уровень выше.','TOTAL_SMALLER_THAN_FILE'=>'Общий объём должен быть не меньше размера одного файла.',
            'INVALID_maxSize'=>'Укажите размер файла, соответствующий целому числу байт.','INVALID_maxTotalSize'=>'Укажите объём, соответствующий целому числу байт.','INVALID_maxFiles'=>'Укажите целое число файлов больше нуля.',
            'IDEMPOTENCY_CONFLICT'=>'Этот повтор содержит другие данные. Перечитайте состояние.','BUSY'=>'Позиция сейчас изменяется. Повторите действие.',
            'FILE_REVALIDATION_REQUIRED'=>'Сначала проверьте содержимое выбранного файла.','FILE_NOT_AVAILABLE'=>'Файл недоступен для этого сайта или состояния.',
            'FILE_LINE_MISMATCH'=>'Файл относится к другой позиции.','SET_LINE_ID_MISMATCH'=>'Комплект относится к другой позиции.',
            'INVALID_TRANSITION'=>'Сначала отправьте версию на проверку.','REASON_REQUIRED'=>'Укажите причину действия.',
            'MIME_MISMATCH'=>'Содержимое файла не соответствует разрешённому формату.','EXECUTABLE_FILE'=>'Исполняемое содержимое запрещено.',
            'SUPERSEDED_VERSION'=>'Выберите актуальную версию файла.','INVALID_PAGE_RANGE'=>'Проверьте диапазон страниц.',
            'UPLOAD_POLICY_REQUIRED'=>'Сначала настройте правила загрузки.','UPLOAD_DISABLED_FOR_LINE'=>'Правила запрещают загрузку на этом этапе заказа.',
            'POSITION_FILE_LIMIT'=>'Превышен лимит числа или объёма файлов позиции.','RETENTION_STALE'=>'Список очистки изменился. Выполните новый предпросмотр.',
            'RETENTION_PENDING'=>'Диск ещё выполняет очистку. Повторите проверку позже.'];
        $respond($status,['success'=>false,'errorCode'=>$code,'error'=>'Операция с макетами не выполнена. '.($messages[$code]??'Проверьте значения ('.$code.').')]);
    } catch (\Throwable $e) {
        $respond(503,['success'=>false,'errorCode'=>'LAYOUT_UNAVAILABLE','error'=>'Операция с макетами недоступна. Проверьте подключение и повторите.']);
    }
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

    if ($action === 'contact_gallery_get') {
        $respond(200, [
            'success' => true,
            'data' => $service->getContactGallery(),
        ]);
    }

    if ($action === 'contact_gallery_set_enabled') {
        if (!is_bool($request['enabled'] ?? null)) {
            $respond(422, [
                'success' => false,
                'errorCode' => 'VALIDATION_ERROR',
                'error' => 'enabled must be boolean',
            ]);
        }
        $respond(200, [
            'success' => true,
            'data' => $service->setContactGalleryEnabled(
                (bool)$request['enabled'],
                (string)($request['revision'] ?? ''),
                (int)$USER->GetID()
            ),
        ]);
    }

    if ($action === 'contact_gallery_upload') {
        $files = $_FILES['photos'] ?? null;
        if (!is_array($files)) {
            $respond(422, [
                'success' => false,
                'errorCode' => 'VALIDATION_ERROR',
                'error' => 'photos must contain uploaded files',
            ]);
        }
        $respond(200, [
            'success' => true,
            'data' => $service->uploadContactGallery(
                $files,
                (string)($request['revision'] ?? ''),
                (int)$USER->GetID()
            ),
        ]);
    }

    if ($action === 'contact_gallery_remove') {
        $fileId = filter_var($request['fileId'] ?? null, FILTER_VALIDATE_INT);
        if ($fileId === false || (int)$fileId <= 0) {
            $respond(422, [
                'success' => false,
                'errorCode' => 'VALIDATION_ERROR',
                'error' => 'fileId must be a positive integer',
            ]);
        }
        $respond(200, [
            'success' => true,
            'data' => $service->removeContactGalleryFile(
                (int)$fileId,
                (string)($request['revision'] ?? ''),
                (int)$USER->GetID()
            ),
        ]);
    }

    if ($action === 'contact_gallery_reorder') {
        if (!is_array($request['fileIds'] ?? null)) {
            $respond(422, [
                'success' => false,
                'errorCode' => 'VALIDATION_ERROR',
                'error' => 'fileIds must be an array',
            ]);
        }
        $respond(200, [
            'success' => true,
            'data' => $service->reorderContactGallery(
                (array)$request['fileIds'],
                (string)($request['revision'] ?? ''),
                (int)$USER->GetID()
            ),
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

    if ($exception->getCode() === 409 && $exception->getMessage() === 'CONTACT_GALLERY_REVISION_CONFLICT') {
        $respond(409, [
            'success' => false,
            'errorCode' => 'REVISION_CONFLICT',
            'error' => 'Галерея была изменена в другой вкладке. Обновите данные и повторите действие.',
        ]);
    }

    if ($exception->getCode() === 503 && $exception->getMessage() === 'CONTACT_GALLERY_UNAVAILABLE') {
        $respond(503, [
            'success' => false,
            'errorCode' => 'CONTACT_GALLERY_UNAVAILABLE',
            'error' => 'Модуль галереи контактов недоступен',
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
