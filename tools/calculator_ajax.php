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
use Prospektweb\Calc\Calculator\SaveHandler;
use Prospektweb\Calc\Calculator\BundleHandler;
use Prospektweb\Calc\Services\SyncVariantsHandler;

// Constants
const LOG_FILE = '/local/logs/prospektweb.calc.ajax.log';

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
            'message' => 'A fatal error occurred',
            'details' => $error['message'] ?? ''
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
            case 'SYNC_VARIANTS_REQUEST':
                $handler = new SyncVariantsHandler();
                $result = $handler->handle($payload);
                
                $response = [
                    'protocol' => 'pwrt-v1',
                    'source' => 'bitrix',
                    'target' => 'prospektweb.calc',
                    'type' => 'SYNC_VARIANTS_RESPONSE',
                    'requestId' => $requestId,
                    'payload' => $result,
                    'timestamp' => time(),
                ];
                
                sendJsonResponse($response);
                break;
            
            case 'SAVE_CALCULATION_REQUEST':
                $handler = new \Prospektweb\Calc\Services\SaveAllService();
                $result = $handler->handle($payload);
                
                $response = [
                    'protocol' => 'pwrt-v1',
                    'source' => 'bitrix',
                    'target' => 'prospektweb.calc',
                    'type' => 'SAVE_CALCULATION_RESPONSE',
                    'requestId' => $requestId,
                    'payload' => $result,
                    'timestamp' => time(),
                ];
                
                sendJsonResponse($response);
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
        sendJsonResponse([
            'protocol' => 'pwrt-v1',
            'source' => 'bitrix',
            'target' => 'prospektweb.calc',
            'type' => 'ERROR',
            'requestId' => $requestId,
            'payload' => ['error' => 'Server error', 'message' => $e->getMessage()],
            'timestamp' => time(),
        ], 500);
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

        case 'checkPresets':
            handleCheckPresets($request);
            break;

        case 'createAndAssignPreset':
            handleCreateAndAssignPreset($request);
            break;

        case 'getMarkupSettings':
            handleGetMarkupSettings();
            break;

        case 'applyMarkups':
            handleApplyMarkups($request);
            break;

        case 'save':
            handleSave($request);
            break;

        case 'saveBundle':
            handleSaveBundle($request);
            break;

        case 'finalizeBundle':
            handleFinalizeBundle($request);
            break;

        case 'refreshData':
            handleRefreshData($request);
            break;


        case 'enrichPreset':
            handleEnrichPreset($request);
            break;

        case 'clearPreset':
            handleClearPreset($request);
            break;

        case 'clonePreset':
            handleClonePreset($request);
            break;

        default:
            sendJsonResponse(['error' => 'Invalid action', 'message' => 'Неизвестное действие'], 400);
    }
} catch (\Throwable $e) {
    logError('Exception in calculator_ajax.php: ' . $e->getMessage());
    sendJsonResponse(['error' => resolveErrorType($e), 'message' => $e->getMessage()], 500);
}
}

/**
 * Обработка запроса getInitData
 */
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
    $siteId = $request->get('siteId') ?: SITE_ID;
    $force = $request->get('force') === '1' || $request->get('force') === 'true';

    if (empty($offerIdsRaw)) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Параметр offerIds обязателен'], 400);
    }

    $offerIds = parseOfferIds($offerIdsRaw);

    if (empty($offerIds)) {
        sendJsonResponse(['error' => 'Invalid parameter', 'message' => 'Некорректные ID торговых предложений'], 400);
    }

    try {
        $service = new InitPayloadService();
        $payload = $service->prepareInitPayload($offerIds, $siteId, $force);

        logInfo('GetInitData success for offers: ' . implode(',', $offerIds));
        sendJsonResponse(['success' => true, 'data' => $payload]);
    } catch (\Throwable $e) {
        logError('GetInitData error: ' . $e->getMessage());
        sendJsonResponse(['error' => resolveErrorType($e), 'message' => $e->getMessage()], 500);
    }
}


/**
 * Возвращает настройки наценок и список типов цен.
 */
function handleGetMarkupSettings(): void
{
    $moduleId = 'prospektweb.calc';
    $priceTypes = [];

    $priceTypeList = \CCatalogGroup::GetListArray();
    foreach ($priceTypeList as $type) {
        $typeId = (int)($type['ID'] ?? 0);
        if ($typeId <= 0) {
            continue;
        }

        $priceTypes[] = [
            'id' => $typeId,
            'name' => (string)($type['NAME'] ?? ('ID ' . $typeId)),
        ];
    }

    $settingsRaw = Option::get($moduleId, 'MARKUP_SETTINGS', '');
    $settings = json_decode($settingsRaw, true);
    if (!is_array($settings)) {
        $settings = ['basePriceTypeId' => 0, 'rates' => []];
    }

    $settings['basePriceTypeId'] = (int)($settings['basePriceTypeId'] ?? 0);
    $settings['rates'] = is_array($settings['rates'] ?? null) ? $settings['rates'] : [];

    if ($settings['basePriceTypeId'] <= 0 && !empty($priceTypes)) {
        $settings['basePriceTypeId'] = (int)$priceTypes[0]['id'];
    }

    sendJsonResponse([
        'success' => true,
        'data' => [
            'priceTypes' => $priceTypes,
            'settings' => $settings,
        ],
    ]);
}

/**
 * Применяет наценки для выбранных торговых предложений.
 */
function handleApplyMarkups($request): void
{
    $offerIdsRaw = (string)$request->get('offerIds');
    $basePriceTypeId = (int)$request->get('basePriceTypeId');
    $ratesRaw = (string)$request->get('rates');

    if ($offerIdsRaw === '' || $basePriceTypeId <= 0 || $ratesRaw === '') {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Недостаточно параметров для наценки'], 400);
    }

    $offerIds = parseOfferIds($offerIdsRaw);
    if (empty($offerIds)) {
        sendJsonResponse(['error' => 'Invalid parameter', 'message' => 'Некорректные ID торговых предложений'], 400);
    }

    $rates = json_decode($ratesRaw, true);
    if (!is_array($rates) || empty($rates)) {
        sendJsonResponse(['error' => 'Invalid parameter', 'message' => 'Некорректные настройки наценок'], 400);
    }

    $rounding = (float)Option::get('prospektweb.calc', 'PRICE_ROUNDING', 1);
    if ($rounding <= 0) {
        $rounding = 1.0;
    }

    $result = [
        'updated' => 0,
        'skipped' => [],
    ];

    foreach ($offerIds as $offerId) {
        $basePrices = [];
        $basePriceRs = \CPrice::GetList(
            ['QUANTITY_FROM' => 'ASC', 'ID' => 'ASC'],
            ['PRODUCT_ID' => $offerId, 'CATALOG_GROUP_ID' => $basePriceTypeId]
        );

        while ($row = $basePriceRs->Fetch()) {
            $basePrices[] = $row;
        }

        if (empty($basePrices)) {
            $result['skipped'][] = ['offerId' => $offerId, 'reason' => 'Нет стартовой цены'];
            continue;
        }

        $basePriceRow = $basePrices[0];
        $baseValue = (float)$basePriceRow['PRICE'];
        $currency = (string)$basePriceRow['CURRENCY'];

        $existingPriceIds = [];
        $priceRs = \CPrice::GetList([], ['PRODUCT_ID' => $offerId]);
        while ($price = $priceRs->Fetch()) {
            $existingPriceIds[] = (int)$price['ID'];
        }
        foreach ($existingPriceIds as $priceId) {
            \CPrice::Delete($priceId);
        }

        foreach ($rates as $catalogGroupId => $rate) {
            $targetGroupId = (int)$catalogGroupId;
            if ($targetGroupId <= 0) {
                continue;
            }

            $rateValue = (float)str_replace(',', '.', (string)$rate);
            $computedPrice = $baseValue * (1 + ($rateValue / 100));
            $computedPrice = ceil($computedPrice / $rounding) * $rounding;

            \CPrice::Add([
                'PRODUCT_ID' => $offerId,
                'CATALOG_GROUP_ID' => $targetGroupId,
                'PRICE' => $computedPrice,
                'CURRENCY' => $currency,
            ]);
        }

        $result['updated']++;
    }

    sendJsonResponse([
        'success' => true,
        'data' => $result,
    ]);
}

/**
 * Обработка запроса checkPresets - проверка CALC_PRESET у товара
 */
function handleCheckPresets($request): void
{
    $offerIdsRaw = $request->get('offerIds');

    if (empty($offerIdsRaw)) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Параметр offerIds обязателен'], 400);
    }

    $offerIds = parseOfferIds($offerIdsRaw);

    if (empty($offerIds)) {
        sendJsonResponse(['error' => 'Invalid parameter', 'message' => 'Некорректные ID торговых предложений'], 400);
    }

    try {
        $configManager = new \Prospektweb\Calc\Config\ConfigManager();
        $skuIblockId = $configManager->getSkuIblockId();
        $productIblockId = $configManager->getProductIblockId();
        
        // 1. Получить productId из первого ТП
        $rsOffer = \CIBlockElement::GetList(
            [],
            ['ID' => $offerIds[0], 'IBLOCK_ID' => $skuIblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'PROPERTY_CML2_LINK']
        );
        
        $productId = null;
        if ($offer = $rsOffer->Fetch()) {
            $productId = (int)($offer['PROPERTY_CML2_LINK_VALUE'] ?? 0);
        }
        
        if (!$productId) {
            sendJsonResponse(['error' => 'Product not found', 'message' => 'Не удалось определить товар'], 400);
        }
        
        // 2. Получить CALC_PRESET из товара
        $rsProduct = \CIBlockElement::GetList(
            [],
            ['ID' => $productId, 'IBLOCK_ID' => $productIblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'PROPERTY_CALC_PRESET']
        );
        
        $presetId = null;
        if ($product = $rsProduct->Fetch()) {
            $presetId = $product['PROPERTY_CALC_PRESET_VALUE'] ?? null;
            $presetId = $presetId ? (int)$presetId : null;
        }
        
        // 3. Возвращаем результат
        $hasPreset = $presetId !== null && $presetId > 0;
        
        logInfo(sprintf(
            'CheckPresets for offers: %s. productId=%d, presetId=%s, hasPreset=%s',
            implode(',', $offerIds),
            $productId,
            $presetId ?? 'null',
            $hasPreset ? 'yes' : 'no'
        ));

        sendJsonResponse([
            'success' => true,
            'data' => [
                'productId' => $productId,
                'presetId' => $presetId,
                'hasPreset' => $hasPreset,
                'needsConfirmation' => !$hasPreset,
                'samePresetForAll' => $hasPreset,
                // Конфликтов больше нет - один пресет на товар
                'uniquePresets' => $hasPreset ? [$presetId] : [],
                'offersWithoutPreset' => $hasPreset ? [] : $offerIds,
            ],
        ]);
    } catch (\Throwable $e) {
        logError('CheckPresets error: ' . $e->getMessage());
        sendJsonResponse(['error' => resolveErrorType($e), 'message' => $e->getMessage()], 500);
    }
}

/**
 * Обработка запроса createAndAssignPreset - создание CALC_PRESET и привязка к торговым предложениям
 */
function handleCreateAndAssignPreset($request): void
{
    $offerIdsRaw = $request->get('offerIds');
    $siteId = $request->get('siteId') ?: SITE_ID;

    if (empty($offerIdsRaw)) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Параметр offerIds обязателен'], 400);
    }

    $offerIds = parseOfferIds($offerIdsRaw);

    if (empty($offerIds)) {
        sendJsonResponse(['error' => 'Invalid parameter', 'message' => 'Некорректные ID торговых предложений'], 400);
    }

    try {
        $bundleHandler = new BundleHandler();
        $presetId = $bundleHandler->createPreset($offerIds);
        
        logInfo('CreateAndAssignPreset success for offers: ' . implode(',', $offerIds) . ', presetId=' . $presetId);
        
        sendJsonResponse([
            'success' => true,
            'data' => [
                'presetId' => $presetId,
                'offerIds' => $offerIds,
            ],
        ]);
    } catch (\Throwable $e) {
        logError('CreateAndAssignPreset error: ' . $e->getMessage());
        sendJsonResponse(['error' => resolveErrorType($e), 'message' => $e->getMessage()], 500);
    }
}

/**
 * Обработка запроса save
 */
function handleSave($request): void
{
    $payloadRaw = $request->get('payload');

    if (empty($payloadRaw)) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Параметр payload обязателен'], 400);
    }

    // Если payload передан как JSON-строка
    if (is_string($payloadRaw)) {
        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            sendJsonResponse(['error' => 'Invalid parameter', 'message' => 'Некорректный формат payload'], 400);
        }
    } else {
        $payload = $payloadRaw;
    }

    try {
        $handler = new SaveHandler();
        $result = $handler->handleSaveRequest($payload);

        logInfo('Save request processed. Status: ' . $result['status']);
        sendJsonResponse(['success' => $result['status'] !== 'error', 'data' => $result]);
    } catch (\Throwable $e) {
        logError('Save error: ' . $e->getMessage());
        sendJsonResponse(['error' => resolveErrorType($e), 'message' => $e->getMessage()], 500);
    }
}

/**
 * Обработка запроса saveBundle
 */
function handleSaveBundle($request): void
{
    $payloadRaw = $request->get('payload');

    if (empty($payloadRaw)) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Параметр payload обязателен'], 400);
    }

    // Если payload передан как JSON-строка
    if (is_string($payloadRaw)) {
        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            sendJsonResponse(['error' => 'Invalid parameter', 'message' => 'Некорректный формат payload'], 400);
        }
    } else {
        $payload = $payloadRaw;
    }

    try {
        $handler = new BundleHandler();
        $result = $handler->saveBundle($payload);

        logInfo('SaveBundle request processed. BundleId: ' . $result['bundleId']);
        sendJsonResponse(['success' => true, 'data' => $result]);
    } catch (\Throwable $e) {
        logError('SaveBundle error: ' . $e->getMessage());
        sendJsonResponse(['error' => resolveErrorType($e), 'message' => $e->getMessage()], 500);
    }
}

/**
 * Обработка запроса finalizeBundle
 */
function handleFinalizeBundle($request): void
{
    $bundleId = (int)$request->get('bundleId');
    $name = $request->get('name');

    if ($bundleId <= 0) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'bundleId обязателен'], 400);
    }

    try {
        $handler = new BundleHandler();
        $result = $handler->finalizeBundle($bundleId, $name);

        logInfo('FinalizeBundle success for bundle: ' . $bundleId);
        sendJsonResponse(['success' => true, 'data' => $result]);
    } catch (\Throwable $e) {
        logError('FinalizeBundle error: ' . $e->getMessage());
        sendJsonResponse(['error' => resolveErrorType($e), 'message' => $e->getMessage()], 500);
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
        $service = new ElementDataService();
        $result = $service->prepareRefreshPayload($payload);

        logInfo('RefreshData success for ' . count($payload) . ' groups');
        sendJsonResponse(['success' => true, 'data' => $result]);
    } catch (\Throwable $e) {
        logError('RefreshData error: ' . $e->getMessage());
        sendJsonResponse(['error' => resolveErrorType($e), 'message' => $e->getMessage()], 500);
    }
}



/**
 * Обработка запроса enrichPreset - обогащение пресета связями на основе выбранных деталей
 */
function handleEnrichPreset($request): void
{
    $presetId = (int)($request->get('presetId') ?? 0);
    $detailIdsRaw = $request->get('detailIds');
    $binding = $request->get('binding') === 'true' || $request->get('binding') === true;
    $existingDetailId = (int)($request->get('existingDetailId') ?? 0);
    $offerIdsRaw = $request->get('offerIds');
    $siteId = $request->get('siteId') ?: SITE_ID;

    if ($presetId <= 0) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Параметр presetId обязателен'], 400);
    }

    if (empty($detailIdsRaw)) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Параметр detailIds обязателен'], 400);
    }

    $detailIds = is_array($detailIdsRaw) ? $detailIdsRaw : explode(',', (string)$detailIdsRaw);
    $detailIds = array_map('intval', $detailIds);
    $detailIds = array_filter($detailIds, function($id) { return $id > 0; });

    if (empty($detailIds)) {
        sendJsonResponse(['error' => 'Invalid parameter', 'message' => 'Некорректные ID деталей'], 400);
    }

    try {
        $detailHandler = new \Prospektweb\Calc\Services\DetailHandler();
        $rootDetailId = null;

        // Логика определения корневой детали
        if (count($detailIds) === 1 && !$binding) {
            // 1 деталь + binding=false → используем выбранную деталь
            $rootDetailId = $detailIds[0];
        } elseif (count($detailIds) >= 1 && $binding) {
            // 1+ деталей + binding=true → создаём скрепление (старая + новые)
            $allDetailIds = $detailIds;
            
            // Добавляем существующую деталь, если она есть
            if ($existingDetailId > 0) {
                $allDetailIds = array_merge([$existingDetailId], $detailIds);
            }
            
            $allDetailIds = array_unique($allDetailIds);
            
            // Если только одна деталь, не создаём скрепление
            if (count($allDetailIds) === 1) {
                $rootDetailId = $allDetailIds[0];
            } else {
                $groupResult = $detailHandler->addGroup([
                    'detailIds' => $allDetailIds,
                    'offerIds' => [],
                ]);
                
                if ($groupResult['status'] !== 'ok') {
                    sendJsonResponse([
                        'error' => 'Group creation failed',
                        'message' => $groupResult['message'] ?? 'Не удалось создать скрепление'
                    ], 500);
                }
                
                $rootDetailId = $groupResult['group']['id'];
            }
        } elseif (count($detailIds) >= 2 && !$binding) {
            // 2+ деталей + binding=false → создаём скрепление (только новые)
            $groupResult = $detailHandler->addGroup([
                'detailIds' => $detailIds,
                'offerIds' => [],
            ]);
            
            if ($groupResult['status'] !== 'ok') {
                sendJsonResponse([
                    'error' => 'Group creation failed',
                    'message' => $groupResult['message'] ?? 'Не удалось создать скрепление'
                ], 500);
            }
            
            $rootDetailId = $groupResult['group']['id'];
        } else {
            sendJsonResponse([
                'error' => 'Invalid parameters',
                'message' => 'Некорректные параметры для создания/обогащения'
            ], 400);
        }

        // Обогащаем пресет
        $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
        $offerIds = parseOfferIds($offerIdsRaw);
        $initPayload = $enrichmentService->enrichPresetFromDetails($presetId, $rootDetailId, $offerIds);

        logInfo(sprintf(
            'EnrichPreset success: presetId=%d, rootDetailId=%d, binding=%s',
            $presetId,
            $rootDetailId,
            $binding ? 'true' : 'false'
        ));

        sendJsonResponse([
            'success' => true,
            'data' => $initPayload,
        ]);
    } catch (\Throwable $e) {
        logError('EnrichPreset error: ' . $e->getMessage());
        sendJsonResponse(['error' => resolveErrorType($e), 'message' => $e->getMessage()], 500);
    }
}

/**
 * Обработка запроса clearPreset - очистка свойств пресета
 */
function handleClearPreset($request): void
{
    $presetId = (int)($request->get('presetId') ?? 0);
    $offerIdsRaw = $request->get('offerIds');
    $siteId = $request->get('siteId') ?: SITE_ID;

    if ($presetId <= 0) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Параметр presetId обязателен'], 400);
    }

    try {
        $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
        $enrichmentService->clearPreset($presetId);

        logInfo(sprintf('ClearPreset success: presetId=%d', $presetId));

        // Получаем обновленный INIT payload после очистки
        $offerIds = parseOfferIds($offerIdsRaw);
        $initPayloadService = new InitPayloadService();
        $initPayload = $initPayloadService->prepareInitPayload($offerIds, $siteId, false);

        sendJsonResponse([
            'success' => true,
            'data' => $initPayload,
        ]);
    } catch (\Throwable $e) {
        logError('ClearPreset error: ' . $e->getMessage());
        sendJsonResponse(['error' => resolveErrorType($e), 'message' => $e->getMessage()], 500);
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
        $bundleHandler = new BundleHandler();
        $newPresetId = $bundleHandler->clonePreset($presetId);

        logInfo(sprintf('ClonePreset success: presetId=%d, newPresetId=%d', $presetId, $newPresetId));

        sendJsonResponse([
            'success' => true,
            'data' => [
                'presetId' => $presetId,
                'newPresetId' => $newPresetId,
            ],
        ]);
    } catch (\Throwable $e) {
        logError('ClonePreset error: ' . $e->getMessage());
        sendJsonResponse(['error' => resolveErrorType($e), 'message' => $e->getMessage()], 500);
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
function resolveErrorType(\Exception $e): string
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
    return $_SERVER['DOCUMENT_ROOT'] . LOG_FILE;
}

/**
 * Логирование запроса
 */
function logRequest(string $action, array $data): void
{
    $loggingEnabled = Option::get('prospektweb.calc', 'LOGGING_ENABLED', 'N') === 'Y';
    if (!$loggingEnabled) {
        return;
    }

    $logFile = getLogFilePath();
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $message = "[{$timestamp}] REQUEST: action={$action}, data=" . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents($logFile, $message, FILE_APPEND);
}

/**
 * Логирование информации
 */
function logInfo(string $message): void
{
    $loggingEnabled = Option::get('prospektweb.calc', 'LOGGING_ENABLED', 'N') === 'Y';
    if (!$loggingEnabled) {
        return;
    }

    $logFile = getLogFilePath();
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$timestamp}] INFO: {$message}\n", FILE_APPEND);
}

/**
 * Логирование ошибок
 */
function logError(string $message): void
{
    $loggingEnabled = Option::get('prospektweb.calc', 'LOGGING_ENABLED', 'N') === 'Y';
    if (!$loggingEnabled) {
        return;
    }

    $logFile = getLogFilePath();
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$timestamp}] ERROR: {$message}\n", FILE_APPEND);
}
