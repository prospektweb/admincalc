<?php
/**
 * AJAX endpoint для интеграции React-калькулятора с Bitrix
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', false);

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

// Set JSON Content-Type header early to ensure all responses are JSON
header('Content-Type: application/json; charset=utf-8');

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Prospektweb\Calc\Calculator\InitPayloadService;
use Prospektweb\Calc\Calculator\ElementDataService;

// Private diagnostics live outside the web document root.
const LOG_DIRECTORY = 'prospektweb-private-logs';
const LOG_FILENAME = 'prospektweb.calc.ajax.log';

// Global error handler to ensure JSON responses on fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Clear all output buffer levels (with safety limit)
        $maxLevels = 10;
        while (ob_get_level() > 0 && $maxLevels-- > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        // Only set header if not already sent
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        logError('Fatal shutdown error: ' . ($error['message'] ?? 'unknown'));
        echo json_encode([
            'error' => 'Internal Server Error',
            'message' => 'A fatal error occurred'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

// Проверка авторизации
global $USER;
if (!$USER->IsAuthorized()) {
    sendJsonResponse(['error' => 'Unauthorized', 'message' => 'Требуется авторизация'], 401);
}

// Проверка прав доступа
if (!$USER->CanDoOperation('edit_catalog')) {
    sendJsonResponse(['error' => 'Forbidden', 'message' => 'Недостаточно прав'], 403);
}

// CSRF защита
if (!check_bitrix_sessid()) {
    sendJsonResponse(['error' => 'Invalid session', 'message' => 'Неверная сессия'], 403);
}

// Загружаем модуль
if (!Loader::includeModule('prospektweb.calc')) {
    sendJsonResponse(['error' => 'Module error', 'message' => 'Модуль не загружен'], 500);
}

// Загружаем модуль iblock (необходим для работы с CIBlockElement)
if (!Loader::includeModule('iblock')) {
    sendJsonResponse(['error' => 'Module error', 'message' => 'Модуль iblock не загружен'], 500);
}

if (!Loader::includeModule('catalog')) {
    sendJsonResponse(['error' => 'Module error', 'message' => 'Модуль catalog не загружен'], 500);
}

// Получаем данные запроса
$request = Application::getInstance()->getContext()->getRequest();

// Проверяем, является ли это PWRT протокол сообщением
$rawInput = file_get_contents('php://input');
$pwrtMessage = null;
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded) && isset($decoded['protocol']) && $decoded['protocol'] === 'pwrt-v1') {
        $pwrtMessage = $decoded;
    }
}

// Определяем тип запроса
if ($pwrtMessage) {
    // Обработка PWRT протокола
    $messageType = $pwrtMessage['type'] ?? '';
    $requestId = $pwrtMessage['requestId'] ?? '';
    $payload = $pwrtMessage['payload'] ?? [];
    
    logRequest($messageType, $pwrtMessage);
    
    try {
        switch ($messageType) {
            case 'PREVIEW_CATALOG_WRITE_REQUEST':
                assertCatalogWritePwrtRequest($request, $payload);
                $catalogWriteService = new \Prospektweb\Calc\Services\CatalogCalculationWriteService();
                $catalogWritePreview = $catalogWriteService->preview(
                    (int)($payload['presetId'] ?? 0),
                    is_array($payload['offerIds'] ?? null) ? $payload['offerIds'] : [],
                    is_array($payload['offerResults'] ?? null) ? $payload['offerResults'] : [],
                    (string)(defined('SITE_ID') ? SITE_ID : 's1')
                );
                sendJsonResponse([
                    'protocol' => 'pwrt-v1',
                    'source' => 'bitrix',
                    'target' => 'prospektweb.calc',
                    'type' => 'PREVIEW_CATALOG_WRITE_RESULT',
                    'requestId' => $requestId,
                    'payload' => \Prospektweb\Calc\Services\CatalogCalculationWriteService::publicPreview(
                        $catalogWritePreview
                    ),
                    'timestamp' => time(),
                ]);
                break;

            case 'APPLY_CATALOG_WRITE_REQUEST':
                assertCatalogWritePwrtRequest($request, $payload, true);
                $catalogWriteService = new \Prospektweb\Calc\Services\CatalogCalculationWriteService();
                $catalogWriteResult = $catalogWriteService->apply(
                    (int)($payload['presetId'] ?? 0),
                    is_array($payload['offerIds'] ?? null) ? $payload['offerIds'] : [],
                    is_array($payload['offerResults'] ?? null) ? $payload['offerResults'] : [],
                    (string)(defined('SITE_ID') ? SITE_ID : 's1'),
                    (string)($payload['fingerprint'] ?? ''),
                    (int)$USER->GetID()
                );
                sendJsonResponse([
                    'protocol' => 'pwrt-v1',
                    'source' => 'bitrix',
                    'target' => 'prospektweb.calc',
                    'type' => 'APPLY_CATALOG_WRITE_RESULT',
                    'requestId' => $requestId,
                    'payload' => $catalogWriteResult,
                    'timestamp' => time(),
                ]);
                break;
            
            default:
                sendJsonResponse([
                    'protocol' => 'pwrt-v1',
                    'source' => 'bitrix',
                    'target' => 'prospektweb.calc',
                    'type' => 'ERROR',
                    'requestId' => $requestId,
                    'payload' => ['error' => 'Unknown message type', 'message' => 'Неизвестный тип сообщения'],
                    'timestamp' => time(),
                ], 400);
        }
    } catch (\Throwable $e) {
        logError('Exception in PWRT message handler: ' . $e->getMessage());
        $statusCode = (int)$e->getCode();
        if ($e instanceof \InvalidArgumentException && !in_array($statusCode, [400, 403, 405, 409], true)) {
            $statusCode = 400;
        }
        sendJsonResponse([
            'protocol' => 'pwrt-v1',
            'source' => 'bitrix',
            'target' => 'prospektweb.calc',
            'type' => 'ERROR',
            'requestId' => $requestId,
            'payload' => ['error' => 'Server error', 'message' => $e->getMessage()],
            'timestamp' => time(),
        ], in_array($statusCode, [400, 403, 405, 409], true) ? $statusCode : 500);
    }
} else {
    // Обработка старых action-based запросов
    $action = $request->get('action') ?? '';
    
    // Логирование запроса
    logRequest($action, $request->toArray());


try {
    switch ($action) {
        case 'getInitData':
            handleGetInitData($request);
            break;

        case 'saveUserTheme':
            handleSaveUserTheme($request);
            break;

        case 'refreshData':
            handleRefreshData($request);
            break;


        case 'clonePreset':
            handleClonePreset($request);
            break;

        default:
            sendJsonResponse(['error' => 'Invalid action', 'message' => 'Неизвестное действие'], 400);
    }
} catch (\Throwable $e) {
    logError('Exception in calculator_ajax.php: ' . $e->getMessage());
    $statusCode = (int)$e->getCode();
    if ($e instanceof \InvalidArgumentException && !in_array($statusCode, [400, 403, 405, 409], true)) {
        $statusCode = 400;
    }
    sendJsonResponse(
        ['error' => resolveErrorType($e), 'message' => $e->getMessage()],
        in_array($statusCode, [400, 403, 405, 409], true) ? $statusCode : 500
    );
}
}

/** Validate the strict POST-only PWRT envelope used by preview/apply. @param mixed $payload */
function assertCatalogWritePwrtRequest($request, $payload, bool $requireFingerprint = false): void
{
    if (!method_exists($request, 'isPost') || !$request->isPost()) {
        throw new \RuntimeException('Предпросмотр и запись каталога принимаются только методом POST.', 405);
    }
    if (!is_array($payload)) {
        throw new \InvalidArgumentException('PWRT payload записи каталога должен быть объектом.');
    }
    $allowedKeys = ['presetId', 'offerIds', 'offerResults', 'siteId'];
    if ($requireFingerprint) {
        $allowedKeys[] = 'fingerprint';
    }
    foreach (array_keys($payload) as $key) {
        if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
            throw new \InvalidArgumentException('PWRT payload записи каталога содержит неизвестное поле.');
        }
    }
    foreach (['presetId', 'offerIds', 'offerResults'] as $requiredKey) {
        if (!array_key_exists($requiredKey, $payload)) {
            throw new \InvalidArgumentException('PWRT payload записи каталога не содержит поле ' . $requiredKey . '.');
        }
    }
    if ($requireFingerprint && !array_key_exists('fingerprint', $payload)) {
        throw new \InvalidArgumentException('PWRT apply не содержит отпечаток подтверждённого предпросмотра.');
    }
}

function handleSaveUserTheme($request): void
{
    global $USER;

    $theme = (string)$request->get('theme');
    if (!in_array($theme, ['dark', 'cream', 'monolith', 'obsidian', 'soft-graphite'], true)) {
        sendJsonResponse(['success' => false, 'message' => 'Недопустимая тема редактора'], 400);
    }

    \CUserOptions::SetOption(
        'prospektweb.calc',
        'editor_theme',
        $theme,
        false,
        (int)$USER->GetID()
    );

    sendJsonResponse(['success' => true, 'theme' => $theme]);
}

function handleGetInitData($request): void
{
    $offerIdsRaw = $request->get('offerIds');
    $presetId = (int)($request->get('presetId') ?? 0);
    $siteId = $request->get('siteId') ?: SITE_ID;
    $force = $request->get('force') === '1' || $request->get('force') === 'true';

    if (empty($offerIdsRaw) && $presetId <= 0) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Укажите presetId или offerIds'], 400);
    }

    if ($force) {
        sendJsonResponse([
            'error' => 'Read-only boundary',
            'message' => 'INIT не создаёт и не переназначает пресеты.',
        ], 409);
    }

    try {
        $offerIds = parseStrictOfferIds($offerIdsRaw);
        $service = new InitPayloadService();
        $payload = $service->prepareNeutralInitPayloadReadOnly(
            $presetId,
            $offerIds,
            $siteId,
            $presetId > 0 && $offerIds !== []
        );

        logInfo($offerIds !== []
            ? 'GetInitData success for offers: ' . implode(',', $offerIds)
            : 'GetInitData success for standalone preset: ' . $presetId);
        sendJsonResponse(['success' => true, 'data' => $payload]);
    } catch (\Throwable $e) {
        logError('GetInitData error: ' . $e->getMessage());
        $statusCode = (int)$e->getCode();
        if ($e instanceof \InvalidArgumentException && !in_array($statusCode, [400, 403, 405, 409], true)) {
            $statusCode = 400;
        }
        sendJsonResponse(
            ['error' => resolveErrorType($e), 'message' => $e->getMessage()],
            in_array($statusCode, [400, 403, 405, 409], true) ? $statusCode : 500
        );
    }
}

/**
 * Обработка запроса refreshData
 */
function handleRefreshData($request): void
{
    $payloadRaw = $request->get('payload');

    if (empty($payloadRaw)) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Параметр payload обязателен'], 400);
    }

    if (is_string($payloadRaw)) {
        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            sendJsonResponse(['error' => 'Invalid parameter', 'message' => 'Некорректный формат payload'], 400);
        }
    } else {
        $payload = $payloadRaw;
    }

    try {
        if (!array_is_list($payload) || count($payload) !== 1 || !is_array($payload[0] ?? null)) {
            throw new \InvalidArgumentException(
                'refreshData accepts exactly one preclassified action per request.',
                422
            );
        }
        $action = is_string($payload[0]['action'] ?? null) ? (string)$payload[0]['action'] : '';
        $classification = \Prospektweb\Calc\Services\CalculatorRefreshActionRegistryService::classify($action);
        if ($classification === null) {
            throw new \InvalidArgumentException('Unsupported refreshData action.', 422);
        }
        if ($classification === \Prospektweb\Calc\Services\CalculatorRefreshActionRegistryService::RETIRED) {
            throw new \RuntimeException('This refreshData mutation was retired by the clean-cut contract.', 409);
        }
        $siteId = defined('SITE_ID') ? (string)SITE_ID : 's1';
        if ($classification === \Prospektweb\Calc\Services\CalculatorRefreshActionRegistryService::PRESET_MUTATION) {
            $expectedSemanticRevision = strtolower(trim((string)($request->get('expectedSemanticRevision') ?? '')));
            $result = (new \Prospektweb\Calc\Services\CalculatorSemanticMutationService())->mutatePayload(
                $payload,
                $expectedSemanticRevision,
                $siteId
            );
        } elseif ($classification === \Prospektweb\Calc\Services\CalculatorRefreshActionRegistryService::GLOBAL_MUTATION) {
            $rawRevision = $request->get('expectedGlobalRevision');
            $revisionText = is_int($rawRevision) ? (string)$rawRevision : trim((string)$rawRevision);
            if (preg_match('/^(0|[1-9][0-9]{0,15})$/D', $revisionText) !== 1
                || (string)(int)$revisionText !== $revisionText) {
                throw new \InvalidArgumentException('expectedGlobalRevision must be an exact safe integer.', 422);
            }
            $expectedGlobalRevision = (int)$revisionText;
            if ($expectedGlobalRevision > 9007199254740991) {
                throw new \InvalidArgumentException('expectedGlobalRevision is outside the safe range.', 422);
            }
            $expectedGlobalFingerprint = strtolower(trim((string)($request->get('expectedGlobalFingerprint') ?? '')));
            $result = (new \Prospektweb\Calc\Services\CalculatorGlobalMutationService())->mutatePayload(
                $payload,
                $expectedGlobalRevision,
                $expectedGlobalFingerprint,
                $siteId
            );
        } else {
            $service = new ElementDataService();
            $result = $service->prepareRefreshPayload($payload);
        }

        logInfo('RefreshData success for ' . count($payload) . ' groups');
        sendJsonResponse(['success' => true, 'data' => $result]);
    } catch (\Throwable $e) {
        logError('RefreshData error: ' . $e->getMessage());
        $statusCode = (int)$e->getCode();
        sendJsonResponse(
            ['error' => resolveErrorType($e), 'message' => $e->getMessage()],
            in_array($statusCode, [400, 403, 405, 409, 422], true) ? $statusCode : 500
        );
    }
}



/**
 * Обработка запроса clonePreset - клонирование пресета вместе с деталями/этапами
 */
function handleClonePreset($request): void
{
    $presetId = (int)($request->get('presetId') ?? 0);

    if ($presetId <= 0) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Параметр presetId обязателен'], 400);
    }

    try {
        $receipt = (new \Prospektweb\Calc\Services\PresetLifecycleMutationService())
            ->duplicatePreset($presetId);
        $newPresetId = (int)($receipt['newPresetId'] ?? 0);

        logInfo(sprintf('ClonePreset success: presetId=%d, newPresetId=%d', $presetId, $newPresetId));

        sendJsonResponse([
            'success' => true,
            'data' => [
                'presetId' => $presetId,
                'newPresetId' => $newPresetId,
                'sourceRevision' => (string)($receipt['sourceRevision'] ?? ''),
                'cloneRevision' => (string)($receipt['cloneRevision'] ?? ''),
            ],
        ]);
    } catch (\Throwable $e) {
        logError('ClonePreset error: ' . $e->getMessage());
        $statusCode = (int)$e->getCode();
        sendJsonResponse(
            ['error' => resolveErrorType($e), 'message' => $e->getMessage()],
            in_array($statusCode, [400, 403, 405, 409], true) ? $statusCode : 500
        );
    }
}

/**
 * Парсинг и валидация offer IDs
 * 
 * @param mixed $offerIdsRaw Raw offer IDs (string or array)
 * @return array Validated array of offer IDs
 */
function parseOfferIds($offerIdsRaw): array
{
    if (empty($offerIdsRaw)) {
        return [];
    }
    
    // Парсим offerIds (может быть строка или массив)
    $offerIds = is_array($offerIdsRaw) ? $offerIdsRaw : explode(',', $offerIdsRaw);
    $offerIds = array_map('intval', $offerIds);
    $offerIds = array_filter($offerIds, function($id) { return $id > 0; });
    
    return $offerIds;
}

/**
 * Exact parser for the read-only calculator INIT boundary.
 * invalid, duplicate or oversized input cannot be silently narrowed.
 *
 * @param mixed $offerIdsRaw
 * @return int[]
 */
function parseStrictOfferIds($offerIdsRaw): array
{
    if ($offerIdsRaw === null || $offerIdsRaw === '' || $offerIdsRaw === []) {
        return [];
    }
    $rawIds = is_array($offerIdsRaw) ? array_values($offerIdsRaw) : explode(',', (string)$offerIdsRaw);
    if ($rawIds === [] || count($rawIds) > 500) {
        throw new \InvalidArgumentException('Некорректное количество торговых предложений.');
    }
    $offerIds = [];
    foreach ($rawIds as $rawId) {
        if ((!is_int($rawId) && !is_string($rawId))
            || preg_match('/^[1-9][0-9]*$/D', (string)$rawId) !== 1) {
            throw new \InvalidArgumentException('Некорректные ID торговых предложений.');
        }
        $offerIds[] = (int)$rawId;
    }
    if (count($offerIds) !== count(array_unique($offerIds))) {
        throw new \InvalidArgumentException('ID торговых предложений не должны повторяться.');
    }
    sort($offerIds, SORT_NUMERIC);
    return $offerIds;
}

/**
 * Отправить JSON ответ
 */
function sendJsonResponse(array $data, int $statusCode = 200): void
{
    if ($statusCode !== 200) {
        http_response_code($statusCode);
    }

    // Explicitly set Content-Type header (defensive practice, also set globally at line 12)
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    die();
}

/**
 * Определить тип ошибки для JSON-ответа
 */
function resolveErrorType(\Throwable $e): string
{
    $message = $e->getMessage();

    if (stripos($message, 'модуль Bitrix') !== false || stripos($message, 'Bitrix module') !== false) {
        return 'Module error';
    }

    return 'Processing error';
}

/**
 * Получить путь к лог-файлу
 */
function getLogFilePath(): string
{
    $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    if ($documentRoot === '') {
        throw new \RuntimeException('Document root is unavailable for private log placement.');
    }
    return dirname($documentRoot) . DIRECTORY_SEPARATOR . LOG_DIRECTORY
        . DIRECTORY_SEPARATOR . LOG_FILENAME;
}

/**
 * @return array<string,int|string>
 */
function requestLogMetadata(array $data): array
{
    $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
    $metadata = [];
    foreach (['protocol', 'source', 'target', 'type', 'requestId'] as $key) {
        $value = $data[$key] ?? null;
        if (is_string($value) && $value !== '') {
            $metadata[$key] = substr(preg_replace('/[\x00-\x1F\x7F]+/', '', $value) ?? '', 0, 128);
        }
    }
    $presetId = (int)($payload['presetId'] ?? $data['presetId'] ?? 0);
    if ($presetId > 0) {
        $metadata['presetId'] = $presetId;
    }
    $offerIds = $payload['offerIds'] ?? $data['offerIds'] ?? [];
    if (is_string($offerIds)) {
        $offerIds = array_filter(array_map('trim', explode(',', $offerIds)), 'strlen');
    }
    if (is_array($offerIds)) {
        $metadata['offerCount'] = count($offerIds);
    }
    return $metadata;
}

function appendPrivateLog(string $level, string $message): void
{
    if (Option::get('prospektweb.calc', 'LOGGING_ENABLED', 'N') !== 'Y') {
        return;
    }
    $logFile = getLogFilePath();
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0700, true);
    }
    if (!is_dir($logDir)) {
        return;
    }
    $level = preg_replace('/[^A-Z]/', '', strtoupper($level)) ?: 'INFO';
    $message = substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $message) ?? '', 0, 8192);
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$timestamp}] {$level}: {$message}\n", FILE_APPEND | LOCK_EX);
    if (is_file($logFile)) {
        @chmod($logFile, 0600);
    }
}

/**
 * Логирование запроса без sessid, формул, значений и результатов расчёта.
 */
function logRequest(string $action, array $data): void
{
    $safeAction = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', $action) ?? '', 0, 128);
    $metadata = json_encode(requestLogMetadata($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    appendPrivateLog('REQUEST', 'action=' . $safeAction . ', metadata=' . ($metadata ?: '{}'));
}

function logInfo(string $message): void
{
    appendPrivateLog('INFO', $message);
}

function logError(string $message): void
{
    appendPrivateLog('ERROR', $message);
}
