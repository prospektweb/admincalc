<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Calculator\InitPayloadService;
use Prospektweb\Calc\Services\OfferUpdateService;
use Prospektweb\Calc\Calculator\CalculationHistoryHandler;

/**
 * Сервис пакетного пересчёта калькуляций
 */
class BatchRecalculateService
{
    private const MODULE_ID = 'prospektweb.calc';
    
    private string $calcServerUrl;
    private int $timeout;
    private ConfigManager $configManager;
    private InitPayloadService $initPayloadService;
    private ?CalcServerRequestSigner $requestSigner;

    /**
     * @param string $calcServerUrl URL сервера расчётов
     * @param int $timeout Таймаут запроса в секундах
     */
    public function __construct(string $calcServerUrl, int $timeout = 30, ?CalcServerRequestSigner $requestSigner = null)
    {
        $this->calcServerUrl = rtrim($calcServerUrl, '/');
        $this->timeout = $timeout;
        $this->configManager = new ConfigManager();
        $this->initPayloadService = new InitPayloadService();
        $this->requestSigner = $requestSigner;
    }

    /**
     * Получить список всех пресетов с количеством ТП
     * 
     * @return array<int, array{id: int, name: string, offerCount: int}>
     */
    public function getPresetsWithOfferCount(): array
    {
        if (!Loader::includeModule('iblock')) {
            return [];
        }

        $presetIblockId = $this->configManager->getIblockId('CALC_PRESETS');
        if ($presetIblockId <= 0) {
            return [];
        }

        $skuIblockId = $this->configManager->getSkuIblockId();
        if ($skuIblockId <= 0) {
            return [];
        }

        $result = [];
        
        // Получаем все пресеты
        $rsPresets = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            ['IBLOCK_ID' => $presetIblockId, 'ACTIVE' => 'Y'],
            false,
            false,
            ['ID', 'NAME']
        );

        while ($preset = $rsPresets->Fetch()) {
            $presetId = (int)$preset['ID'];
            
            // Подсчитываем количество ТП для этого пресета
            $offerCount = $this->countOffersForPreset($presetId, $skuIblockId);
            
            $result[] = [
                'id' => $presetId,
                'name' => $preset['NAME'],
                'offerCount' => $offerCount,
            ];
        }

        return $result;
    }

    /**
     * Получить ID всех ТП для данного пресета
     * 
     * @param int $presetId ID пресета
     * @return array Массив ID торговых предложений
     */
    public function getOfferIdsForPreset(int $presetId): array
    {
        if (!Loader::includeModule('iblock')) {
            return [];
        }

        $skuIblockId = $this->configManager->getSkuIblockId();
        if ($skuIblockId <= 0) {
            return [];
        }

        $productIds = array_column($this->getProductsForPreset($presetId), 'id');
        if (empty($productIds)) {
            return [];
        }

        $offerIds = [];
        $rsOffers = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => $skuIblockId,
                'ACTIVE' => 'Y',
                'PROPERTY_CML2_LINK' => $productIds,
            ],
            false,
            false,
            ['ID']
        );

        while ($offer = $rsOffers->Fetch()) {
            $offerIds[] = (int)$offer['ID'];
        }

        return $offerIds;
    }


    /**
     * Получить ID ТП для выбранных товаров, связанных с указанным пресетом.
     *
     * @param int $presetId ID пресета
     * @param int[] $productIds ID выбранных товаров
     * @return array<int, int> Массив ID торговых предложений
     */
    public function getOfferIdsForPresetProducts(int $presetId, array $productIds): array
    {
        if (!Loader::includeModule('iblock')) {
            return [];
        }

        $skuIblockId = $this->configManager->getSkuIblockId();
        if ($skuIblockId <= 0) {
            return [];
        }

        $allowedProductIds = array_column($this->getProductsForPreset($presetId), 'id');
        $productIds = array_values(array_intersect(
            array_values(array_unique(array_filter(array_map('intval', $productIds), static function (int $productId): bool {
                return $productId > 0;
            }))),
            $allowedProductIds
        ));

        if (empty($productIds)) {
            return [];
        }

        $offerIds = [];
        $rsOffers = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => $skuIblockId,
                'ACTIVE' => 'Y',
                'PROPERTY_CML2_LINK' => $productIds,
            ],
            false,
            false,
            ['ID']
        );

        while ($offer = $rsOffers->Fetch()) {
            $offerIds[] = (int)$offer['ID'];
        }

        return $offerIds;
    }


    /**
     * Получить товары, связанные с пресетом через свойство товара CALC_PRESET.
     *
     * @param int $presetId
     * @return array<int, array{id:int,name:string,editUrl:string,offerCount:int}>
     */
    public function getProductsForPreset(int $presetId): array
    {
        if (!Loader::includeModule('iblock')) {
            return [];
        }

        $productIblockId = $this->configManager->getProductIblockId();
        if ($productIblockId <= 0) {
            return [];
        }

        $products = [];
        $res = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => $productIblockId,
                'ACTIVE' => 'Y',
                'PROPERTY_CALC_PRESET' => $presetId,
            ],
            false,
            false,
            ['ID', 'NAME']
        );

        $languageId = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
        $productIblockType = 'catalog';
        if (Loader::includeModule('iblock')) {
            $rsIBlock = \CIBlock::GetByID($productIblockId);
            if ($arIBlock = $rsIBlock->Fetch()) {
                $productIblockType = (string)($arIBlock['IBLOCK_TYPE_ID'] ?? 'catalog');
            }
        }

        while ($row = $res->Fetch()) {
            $productId = (int)$row['ID'];
            $products[] = [
                'id' => $productId,
                'name' => (string)$row['NAME'],
                'editUrl' => '/bitrix/admin/iblock_element_edit.php?IBLOCK_ID='
                    . $productIblockId
                    . '&ID='
                    . $productId
                    . '&type='
                    . rawurlencode($productIblockType)
                    . '&lang='
                    . rawurlencode($languageId)
                    . '&find_section_section=0&WF=Y',
                'offerCount' => 0,
            ];
        }

        $offerCounts = $this->countOffersByProductIds(array_column($products, 'id'));
        foreach ($products as &$product) {
            $product['offerCount'] = $offerCounts[(int)$product['id']] ?? 0;
        }
        unset($product);

        return $products;
    }

    /**
     * Подготовить расширенный анализ для пресетов.
     *
     * @param int[] $presetIds
     * @return array<int, array{presetId:int,presetName:string,products:array<int,array{id:int,name:string,editUrl:string,offerCount:int}>,offerCount:int}>
     */
    public function getPresetAnalysis(array $presetIds = []): array
    {
        $presets = $this->getPresetsWithOfferCount();

        if (!empty($presetIds)) {
            $presets = array_values(array_filter($presets, static function (array $preset) use ($presetIds): bool {
                return in_array((int)$preset['id'], $presetIds, true);
            }));
        }

        $result = [];
        foreach ($presets as $preset) {
            $presetId = (int)$preset['id'];
            $products = $this->getProductsForPreset($presetId);
            $offerIds = $this->getOfferIdsForPreset($presetId);

            $result[] = [
                'presetId' => $presetId,
                'presetName' => (string)$preset['name'],
                'products' => $products,
                'offerCount' => count($offerIds),
            ];
        }

        return $result;
    }

    /**
     * Выполнить пересчёт группы торговых предложений одним запросом к calc-server
     *
     * @param int[] $offerIds ID торговых предложений
     * @param bool $onlyChanged Пропускать неизменившиеся
     * @return array<int, array{status: string, error?: string, resultCount?: int}>
     */
    public function recalculateOffers(array $offerIds, bool $onlyChanged = true): array
    {
        $offerIds = array_values(array_unique(array_map('intval', $offerIds)));
        $offerIds = array_values(array_filter($offerIds, static function (int $offerId): bool {
            return $offerId > 0;
        }));

        if (empty($offerIds)) {
            return [];
        }

        $resultsByOfferId = [];

        try {
            $siteId = defined('SITE_ID') ? SITE_ID : $this->getFirstAvailableSiteId();
            $initPayload = $this->initPayloadService->prepareInitPayload($offerIds, $siteId);

            $hashesByOfferId = [];
            $offersToProcess = [];
            foreach ($offerIds as $offerId) {
                $currentHash = $this->computeStateHashForOffer($initPayload, $offerId);
                $hashesByOfferId[$offerId] = $currentHash;

                if ($onlyChanged) {
                    $savedHash = $this->getSavedHash($offerId);
                    if ($savedHash !== null && $savedHash === $currentHash) {
                        $resultsByOfferId[$offerId] = ['status' => 'skipped'];
                        continue;
                    }
                }

                $offersToProcess[] = $offerId;
            }

            if (empty($offersToProcess)) {
                return $resultsByOfferId;
            }

            $requestPayload = $this->buildPayloadForOffers($initPayload, $offersToProcess);
            $calcResult = $this->callCalcServer($requestPayload);
            if (!$calcResult || !isset($calcResult['success']) || !$calcResult['success']) {
                throw new \Exception($calcResult['error'] ?? 'Ошибка расчёта на сервере');
            }

            $offerResults = $calcResult['data'] ?? [];
            if (empty($offerResults) || !is_array($offerResults)) {
                throw new \Exception('Пустой ответ от calc-server');
            }

            $offerUpdateService = new OfferUpdateService();
            $writeResult = $offerUpdateService->updateOffersFromCalculation($offerResults);
            if (($writeResult['status'] ?? 'error') === 'error') {
                throw new \Exception('Ошибка записи результатов: ' . ($writeResult['errors'][0]['message'] ?? 'Неизвестная ошибка'));
            }

            $returnedOfferIds = [];
            foreach ($offerResults as $offerResult) {
                $returnedOfferId = (int)($offerResult['offerId'] ?? 0);
                if ($returnedOfferId <= 0) {
                    continue;
                }

                $returnedOfferIds[$returnedOfferId] = true;
                if (isset($hashesByOfferId[$returnedOfferId])) {
                    $this->saveHash($returnedOfferId, $hashesByOfferId[$returnedOfferId]);
                }

                $resultsByOfferId[$returnedOfferId] = [
                    'status' => 'recalculated',
                    'resultCount' => 1,
                ];
            }

            foreach ($offersToProcess as $offerId) {
                if (!isset($returnedOfferIds[$offerId]) && !isset($resultsByOfferId[$offerId])) {
                    $resultsByOfferId[$offerId] = [
                        'status' => 'error',
                        'error' => 'В ответе calc-server отсутствуют данные по ТП',
                    ];
                }
            }

            try {
                $historyHandler = new CalculationHistoryHandler();
                $historyOffers = [];
                foreach ($offerResults as $offerResult) {
                    $historyOffers[] = [
                        'offerId' => (int)($offerResult['offerId'] ?? 0),
                        'json' => $offerResult,
                    ];
                }

                if (!empty($historyOffers)) {
                    $historyHandler->handle([
                        'offers' => $historyOffers,
                    ]);
                }
            } catch (\Exception $e) {
                error_log('Ошибка сохранения истории пакетного расчёта: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            $message = $e->getMessage();
            foreach ($offerIds as $offerId) {
                if (!isset($resultsByOfferId[$offerId])) {
                    $resultsByOfferId[$offerId] = [
                        'status' => 'error',
                        'error' => $message,
                    ];
                }
            }
        }

        return $resultsByOfferId;
    }

    /**
     * Выполнить пересчёт одного торгового предложения
     *
     * @param int $offerId ID торгового предложения
     * @param bool $onlyChanged Пропускать неизменившиеся
     * @return array{status: string, error?: string, resultCount?: int}
     */
    public function recalculateOffer(int $offerId, bool $onlyChanged = true): array
    {
        $results = $this->recalculateOffers([$offerId], $onlyChanged);

        return $results[$offerId] ?? [
            'status' => 'error',
            'error' => 'Не удалось получить результат пересчёта',
        ];
    }

    /**
     * Выполнить пересчёт для набора пресетов
     * 
     * @param int[] $presetIds Пустой массив = все пресеты
     * @param bool $onlyChanged Пропускать неизменившиеся
     * @param callable|null $progressCallback function(int $current, int $total, string $message)
     * @return array Сводка результатов
     */
    public function recalculate(
        array $presetIds = [],
        bool $onlyChanged = true,
        ?callable $progressCallback = null
    ): array {
        $startTime = microtime(true);
        
        // Получаем список пресетов для обработки
        $allPresets = $this->getPresetsWithOfferCount();
        
        if (!empty($presetIds)) {
            $allPresets = array_filter($allPresets, function($preset) use ($presetIds) {
                return in_array($preset['id'], $presetIds, true);
            });
        }

        $summary = [
            'totalPresets' => count($allPresets),
            'processedPresets' => 0,
            'totalOffers' => 0,
            'recalculated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'duration' => 0,
        ];

        $details = [];
        $errors = [];

        foreach ($allPresets as $index => $preset) {
            $presetId = $preset['id'];
            $presetName = $preset['name'];
            
            if ($progressCallback) {
                $progressCallback($index + 1, count($allPresets), "Обработка пресета: {$presetName}");
            }

            $offerIds = $this->getOfferIdsForPreset($presetId);
            $summary['totalOffers'] += count($offerIds);

            $presetStats = [
                'presetId' => $presetId,
                'presetName' => $presetName,
                'offerCount' => count($offerIds),
                'recalculated' => 0,
                'skipped' => 0,
                'errors' => [],
            ];

            foreach ($offerIds as $offerId) {
                $offerResult = $this->recalculateOffer((int)$offerId, $onlyChanged);

                if (($offerResult['status'] ?? '') === 'recalculated') {
                    $presetStats['recalculated']++;
                    $summary['recalculated']++;
                    continue;
                }

                if (($offerResult['status'] ?? '') === 'skipped') {
                    $presetStats['skipped']++;
                    $summary['skipped']++;
                    continue;
                }

                $errorMessage = (string)($offerResult['error'] ?? 'Неизвестная ошибка');
                $presetStats['errors'][] = $errorMessage;
                $errors[] = [
                    'presetId' => $presetId,
                    'offerId' => $offerId,
                    'error' => $errorMessage,
                ];
                $summary['errors']++;
            }

            $details[] = $presetStats;
            $summary['processedPresets']++;
        }

        $summary['duration'] = round(microtime(true) - $startTime, 2);

        return [
            'success' => true,
            'summary' => $summary,
            'details' => $details,
            'errors' => $errors,
        ];
    }

    /**
     * Отправить запрос на calc-server и получить результат
     * 
     * @param array $initPayload Данные для расчёта
     * @return array Результат расчёта
     * @throws \Exception
     */
    private function callCalcServer(array $initPayload): array
    {
        $baseUrl = rtrim($this->calcServerUrl, '/');
        $url = preg_match('#/calculate$#', $baseUrl) ? $baseUrl : $baseUrl . '/calculate';
        $requestBody = json_encode(['initPayload' => $initPayload], JSON_UNESCAPED_UNICODE);

        if ($requestBody === false) {
            throw new \Exception('Не удалось сериализовать payload для calc-server');
        }

        try {
            $signer = $this->requestSigner ?? $this->createRequestSigner();
            $authHeaders = $signer->headers($requestBody, 'POST', '/calculate');
        } catch (\Throwable $e) {
            throw new \Exception('Не настроена защищённая связь с сервером расчётов');
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
        ], $authHeaders));
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("calc-server connection error: {$error}");
            throw new \Exception('Ошибка соединения с сервером расчётов');
        }

        $responseBody = is_string($response) ? trim($response) : '';
        $decodedErrorResponse = $responseBody !== '' ? json_decode($responseBody, true) : null;

        if ($httpCode !== 200) {
            $serverMessage = '';
            if (is_array($decodedErrorResponse)) {
                $serverError = $decodedErrorResponse['error'] ?? null;
                if (is_array($serverError)) {
                    $serverMessage = (string)($serverError['message'] ?? $serverError['code'] ?? '');
                } elseif (is_scalar($serverError)) {
                    $serverMessage = (string)$serverError;
                } else {
                    $serverMessage = (string)($decodedErrorResponse['message'] ?? '');
                }
            }
            if ($serverMessage === '' && $responseBody !== '') {
                $serverMessage = substr($responseBody, 0, 400);
            }

            throw new \Exception(
                $serverMessage !== ''
                    ? "calc-server returned HTTP {$httpCode}: {$serverMessage}"
                    : "calc-server returned HTTP {$httpCode}"
            );
        }

        $result = is_array($decodedErrorResponse) ? $decodedErrorResponse : json_decode((string)$response, true);
        if (!is_array($result)) {
            throw new \Exception('calc-server returned invalid JSON');
        }

        return $result;
    }

    private function createRequestSigner(): CalcServerRequestSigner
    {
        $clientId = trim((string)(getenv('PROSPEKTWEB_CALC_SERVER_CLIENT_ID') ?: getenv('PROSPEKTWEB_FRONTCALC_CLIENT_ID') ?: 'prospektprint-production'));
        $secret = trim((string)(getenv('PROSPEKTWEB_CALC_SERVER_SHARED_SECRET') ?: getenv('PROSPEKTWEB_FRONTCALC_SHARED_SECRET') ?: ''));
        if ($secret === '') {
            $secretFile = trim((string)(getenv('PROSPEKTWEB_CALC_SERVER_SECRET_FILE') ?: getenv('PROSPEKTWEB_FRONTCALC_SECRET_FILE') ?: ''));
            if ($secretFile === '') {
                $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
                if ($documentRoot !== '') {
                    $secretFile = dirname($documentRoot) . '/.frontcalc-secret';
                }
            }
            if ($secretFile !== '' && is_file($secretFile) && is_readable($secretFile)) {
                $secret = trim((string)file_get_contents($secretFile));
            }
        }

        return new CalcServerRequestSigner($clientId, $secret);
    }

    /**
     * Вычислить хеш состояния для набора offer IDs
     * 
     * MD5 используется для быстрой проверки изменений данных.
     * Это НЕ криптографическая операция, а простая детекция изменений,
     * поэтому MD5 подходит (быстрый и достаточный для этой цели).
     * 
     * @param array $initPayload Полные данные для расчёта
     * @return string MD5 хеш состояния
     */
    public function computeStateHash(array $initPayload): string
    {
        // Сериализуем весь payload, который влияет на расчёт
        $stateData = [
            'elementsStore' => $initPayload['elementsStore'] ?? [],
            'selectedOffers' => $initPayload['selectedOffers'] ?? [],
            'preset' => $initPayload['preset'] ?? [],
            'priceTypes' => $initPayload['priceTypes'] ?? [],
        ];
        
        return md5(json_encode($stateData, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Вычислить хеш состояния для одного оффера на основании общего payload
     */
    private function computeStateHashForOffer(array $initPayload, int $offerId): string
    {
        $singlePayload = $this->buildPayloadForOffers($initPayload, [$offerId]);

        return $this->computeStateHash($singlePayload);
    }

    /**
     * Собрать payload только с указанными офферами
     *
     * @param int[] $offerIds
     */
    private function buildPayloadForOffers(array $initPayload, array $offerIds): array
    {
        $offerMap = [];
        foreach ($initPayload['selectedOffers'] ?? [] as $offer) {
            $id = (int)($offer['id'] ?? 0);
            if ($id > 0) {
                $offerMap[$id] = $offer;
            }
        }

        $selectedOffers = [];
        foreach ($offerIds as $offerId) {
            $offerId = (int)$offerId;
            if (isset($offerMap[$offerId])) {
                $selectedOffers[] = $offerMap[$offerId];
            }
        }

        $payload = $initPayload;
        $payload['selectedOffers'] = $selectedOffers;

        return $payload;
    }

    /**
     * Получить сохранённый хеш для оффера
     * 
     * @param int $offerId ID торгового предложения
     * @return string|null Сохранённый хеш или null
     */
    public function getSavedHash(int $offerId): ?string
    {
        if (!Loader::includeModule('iblock')) {
            return null;
        }

        $skuIblockId = $this->configManager->getSkuIblockId();
        if ($skuIblockId <= 0) {
            return null;
        }

        $this->ensureHashProperty($skuIblockId);

        $rsProperty = \CIBlockElement::GetProperty(
            $skuIblockId,
            $offerId,
            [],
            ['CODE' => 'CALC_STATE_HASH']
        );

        if ($property = $rsProperty->Fetch()) {
            $value = trim((string)$property['VALUE']);
            return $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * Сохранить хеш для оффера
     * 
     * @param int $offerId ID торгового предложения
     * @param string $hash Хеш состояния
     */
    public function saveHash(int $offerId, string $hash): void
    {
        if (!Loader::includeModule('iblock')) {
            return;
        }

        $skuIblockId = $this->configManager->getSkuIblockId();
        if ($skuIblockId <= 0) {
            return;
        }

        $this->ensureHashProperty($skuIblockId);

        \CIBlockElement::SetPropertyValuesEx(
            $offerId,
            $skuIblockId,
            ['CALC_STATE_HASH' => $hash]
        );
    }

    /**
     * Подсчитать количество ТП для пресета
     * 
     * @param int $presetId ID пресета
     * @param int $skuIblockId ID инфоблока ТП
     * @return int Количество ТП
     */
    private function countOffersForPreset(int $presetId, int $skuIblockId): int
    {
        unset($skuIblockId);
        return count($this->getOfferIdsForPreset($presetId));
    }

    /**
     * Подсчитать количество ТП для каждого товара через связь SKU PROPERTY_CML2_LINK.
     *
     * @param int[] $productIds
     * @return array<int, int>
     */
    private function countOffersByProductIds(array $productIds): array
    {
        if (!Loader::includeModule('iblock')) {
            return [];
        }

        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static function (int $productId): bool {
            return $productId > 0;
        })));

        if (empty($productIds)) {
            return [];
        }

        $skuIblockId = $this->configManager->getSkuIblockId();
        if ($skuIblockId <= 0) {
            return [];
        }

        $counts = array_fill_keys($productIds, 0);
        $rsOffers = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => $skuIblockId,
                'ACTIVE' => 'Y',
                'PROPERTY_CML2_LINK' => $productIds,
            ],
            false,
            false,
            ['ID', 'PROPERTY_CML2_LINK']
        );

        while ($offer = $rsOffers->Fetch()) {
            $linkedProductId = (int)($offer['PROPERTY_CML2_LINK_VALUE'] ?? 0);
            if ($linkedProductId > 0 && isset($counts[$linkedProductId])) {
                $counts[$linkedProductId]++;
            }
        }

        return $counts;
    }

    /**
     * Убедиться, что свойство CALC_STATE_HASH существует
     * 
     * @param int $iblockId ID инфоблока
     */
    private function ensureHashProperty(int $iblockId): void
    {
        static $checked = [];
        
        if (isset($checked[$iblockId])) {
            return;
        }

        $property = \CIBlockProperty::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'CODE' => 'CALC_STATE_HASH']
        )->Fetch();

        if (!$property) {
            $ibp = new \CIBlockProperty();
            $ibp->Add([
                'IBLOCK_ID' => $iblockId,
                'NAME' => 'Хеш состояния расчёта',
                'ACTIVE' => 'Y',
                'CODE' => 'CALC_STATE_HASH',
                'PROPERTY_TYPE' => 'S',
                'USER_TYPE' => null,
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SEARCHABLE' => 'N',
                'FILTERABLE' => 'N',
                'WITH_DESCRIPTION' => 'N',
            ]);
        }

        $checked[$iblockId] = true;
    }

    /**
     * Получить первый доступный ID сайта
     * 
     * @return string ID сайта
     */
    private function getFirstAvailableSiteId(): string
    {
        if (!Loader::includeModule('main')) {
            return 's1'; // fallback если модуль main не загружен (что маловероятно)
        }

        $rsSites = \CSite::GetList('sort', 'asc', ['ACTIVE' => 'Y']);
        if ($site = $rsSites->Fetch()) {
            return $site['LID'];
        }

        return 's1'; // fallback если нет активных сайтов
    }
}
