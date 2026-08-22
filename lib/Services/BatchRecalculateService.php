<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Calculator\InitPayloadService;
use Prospektweb\Calc\Services\OfferUpdateService;

/**
 * Сервис пакетного пересчёта калькуляций
 */
class BatchRecalculateService
{
    private const MODULE_ID = 'prospektweb.calc';
    private const PREVIEW_CHUNK_SIZE = 6;
    public const SERVER_CALCULATION_CONTRACT = 'prospektweb.calc.server-calculation/v1';
    
    private string $calcServerUrl;
    private int $timeout;
    private ConfigManager $configManager;
    private InitPayloadService $initPayloadService;
    private ?CalcServerRequestSigner $requestSigner;
    private CatalogOutputMappingService $catalogOutputMappingService;

    /**
     * @param string $calcServerUrl URL сервера расчётов
     * @param int $timeout Таймаут запроса в секундах
     */
    public function __construct(string $calcServerUrl, int $timeout = 30, ?CalcServerRequestSigner $requestSigner = null)
    {
        $this->calcServerUrl = self::normalizeCalcServerUrl($calcServerUrl);
        $this->timeout = $timeout;
        $this->configManager = new ConfigManager();
        $this->initPayloadService = new InitPayloadService();
        $this->requestSigner = $requestSigner;
        $this->catalogOutputMappingService = new CatalogOutputMappingService();
    }

    public static function normalizeCalcServerUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('CALC_SERVER_URL is not a valid URL.');
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('CALC_SERVER_URL contains forbidden URL components.');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        $path = (string)($parts['path'] ?? '');
        if ($host === '' || ($port !== null && ($port < 1 || $port > 65535))
            || ($scheme !== 'https' && $scheme !== 'http')) {
            throw new \InvalidArgumentException('CALC_SERVER_URL has an unsupported origin.');
        }
        $loopbackHosts = ['localhost', '127.0.0.1', '::1'];
        if ($scheme === 'http' && !in_array($host, $loopbackHosts, true)) {
            throw new \InvalidArgumentException('Plain HTTP calc-server is allowed only on exact loopback hosts.');
        }
        if ($scheme === 'https') {
            if (in_array($host, $loopbackHosts, true)
                || (filter_var($host, FILTER_VALIDATE_IP) !== false
                    && filter_var(
                        $host,
                        FILTER_VALIDATE_IP,
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                    ) === false)) {
                throw new \InvalidArgumentException('HTTPS calc-server cannot target a local or reserved address.');
            }
        }
        $renderedHost = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
        $normalized = $scheme . '://' . $renderedHost;
        if ($port !== null) {
            $normalized .= ':' . $port;
        }
        $normalized .= $path === '' ? '' : '/' . ltrim($path, '/');
        return rtrim($normalized, '/');
    }

    /** @param mixed[] $presetIds @return int[] */
    public static function normalizeRequestedPresetIds(array $presetIds): array
    {
        if ($presetIds === []) {
            throw new \InvalidArgumentException('Batch recalculation requires exactly one preset.', 400);
        }
        if (array_keys($presetIds) !== range(0, count($presetIds) - 1)
            || count($presetIds) !== 1) {
            throw new \InvalidArgumentException(
                'Batch recalculation accepts exactly one preset.',
                400
            );
        }
        $rawPresetId = reset($presetIds);
        if ((!is_int($rawPresetId)
                && !(is_string($rawPresetId) && preg_match('/^[1-9][0-9]*$/D', $rawPresetId)))
            || (int)$rawPresetId <= 0) {
            throw new \InvalidArgumentException('Batch preset ID is invalid.', 400);
        }
        self::assertSupportedBatchPresetId((int)$rawPresetId);
        return [(int)$rawPresetId];
    }

    public static function assertSupportedBatchPresetId(int $presetId): void
    {
        if ($presetId <= 0) {
            throw new \RuntimeException('Batch recalculation requires a positive preset ID.', 409);
        }
    }

    /**
     * @param array<int|string,mixed> $rawMap
     * @return array<int,int[]>
     */
    public static function normalizeProductIdsByPresetScope(array $rawMap): array
    {
        $result = [];
        foreach ($rawMap as $rawPresetId => $productIds) {
            if ((!is_int($rawPresetId)
                    && !(is_string($rawPresetId) && preg_match('/^[1-9][0-9]*$/D', $rawPresetId)))
                || (int)$rawPresetId <= 0) {
                throw new \InvalidArgumentException('productIdsByPreset contains an invalid preset ID.', 400);
            }
            self::assertSupportedBatchPresetId((int)$rawPresetId);
            if (!is_array($productIds)) {
                throw new \InvalidArgumentException('product IDs must be arrays.', 400);
            }
            $normalizedProductIds = array_values(array_unique(array_filter(
                array_map('intval', $productIds),
                static function (int $productId): bool {
                    return $productId > 0;
                }
            )));
            sort($normalizedProductIds, SORT_NUMERIC);
            $result[(int)$rawPresetId] = $normalizedProductIds;
        }
        return $result;
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
        $propertyAuthority = (new PresetProductAssignmentPropertyAuthorityService())->resolve(
            $productIblockId
        );
        $propertyId = (int)$propertyAuthority['propertyId'];

        $products = [];
        $res = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => $productIblockId,
                'ACTIVE' => 'Y',
                'ACTIVE_DATE' => 'Y',
                'PROPERTY_' . $propertyId => $presetId,
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
     * Рассчитать и проверить набор ТП без изменения каталога, истории и хешей состояния.
     *
     * @param int[] $offerIds
     */
    public function previewOffers(array $offerIds): array
    {
        $offerIds = array_values(array_unique(array_filter(array_map('intval', $offerIds), static function (int $offerId): bool {
            return $offerId > 0;
        })));

        if (empty($offerIds)) {
            return [
                'ready' => false,
                'summary' => ['total' => 0, 'valid' => 0, 'invalid' => 0],
                'offers' => [],
                'errors' => [['offerId' => 0, 'message' => 'Не выбраны торговые предложения']],
            ];
        }

        $offerUpdateService = new OfferUpdateService();

        $preview = [
            'ready' => true,
            'summary' => ['total' => 0, 'valid' => 0, 'invalid' => 0],
            'offers' => [],
            'errors' => [],
            // Private endpoint handoff used to bind a successful preview to
            // the exact calculation-affecting state seen by calc-server.
            'stateFingerprints' => [],
        ];
        $siteId = defined('SITE_ID') ? SITE_ID : $this->getFirstAvailableSiteId();

        foreach (array_chunk($offerIds, self::PREVIEW_CHUNK_SIZE) as $offerChunk) {
            try {
                $initPayload = $this->prepareCalculationPayload($offerChunk, $siteId);
                $preview['stateFingerprints'] += $this->captureStateFingerprintsFromPayload($initPayload, $offerChunk);
                $requestPayload = $this->buildPayloadForOffers($initPayload, $offerChunk);
                $calcResult = $this->callCalcServer($requestPayload);

                if (!$calcResult || !isset($calcResult['success']) || !$calcResult['success']) {
                    $error = $calcResult['error'] ?? 'Ошибка расчёта на сервере';
                    if (is_array($error)) {
                        $error = $error['message'] ?? $error['code'] ?? 'Ошибка расчёта на сервере';
                    }
                    throw new \RuntimeException((string)$error);
                }

                $offerResults = $calcResult['data'] ?? [];
                if (!is_array($offerResults)) {
                    throw new \RuntimeException('calc-server вернул некорректный список результатов');
                }
                $offerResults = $this->normalizeStandaloneCatalogPrices($offerResults, $requestPayload);
                $offerResults = $this->projectCatalogOutputResults($offerResults, $requestPayload);

                $chunkPreview = $offerUpdateService->previewOffersFromCalculation($offerResults, $offerChunk);
            } catch (\Throwable $e) {
                $chunkPreview = $offerUpdateService->previewOffersFromCalculation([], $offerChunk);
                array_unshift($chunkPreview['errors'], ['offerId' => 0, 'message' => $e->getMessage()]);
                $chunkPreview['ready'] = false;
            }

            $preview['ready'] = $preview['ready'] && !empty($chunkPreview['ready']);
            foreach (['total', 'valid', 'invalid'] as $summaryKey) {
                $preview['summary'][$summaryKey] += (int)($chunkPreview['summary'][$summaryKey] ?? 0);
            }
            $preview['offers'] = array_merge($preview['offers'], $chunkPreview['offers'] ?? []);
            $preview['errors'] = array_merge($preview['errors'], $chunkPreview['errors'] ?? []);
        }

        // Bind the reviewed result to both formula inputs and the complete
        // writable catalog state after all remote preview chunks finish. The
        // calculation half must still equal the exact payload sent before the
        // network calls; otherwise a response for state A could be approved
        // together with a post-network state B fingerprint.
        $postNetworkState = $this->captureOfferWriteStateFingerprintsAtSite(
            $offerIds,
            $siteId
        );
        foreach ($offerIds as $offerId) {
            $beforeHash = strtolower(trim((string)(
                $preview['stateFingerprints'][$offerId]['calculation'] ?? ''
            )));
            $afterHash = strtolower(trim((string)(
                $postNetworkState[$offerId]['calculation'] ?? ''
            )));
            if (preg_match('/^[a-f0-9]{64}$/D', $beforeHash) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $afterHash) !== 1
                || !hash_equals($beforeHash, $afterHash)) {
                throw new \RuntimeException(
                    'Calculation inputs changed while the reviewed batch preview was running. Repeat preview.',
                    409
                );
            }
        }
        $preview['stateFingerprints'] = $postNetworkState;

        return $preview;
    }

    /**
     * Re-read the calculation-affecting state without calling calc-server.
     * Used by the batch start CAS gate to prove that the reviewed preview is
     * still current for every selected offer.
     *
     * @param int[] $offerIds
     * @return array<int,array{calculation:string}>
     */
    public function captureOfferStateFingerprints(array $offerIds): array
    {
        $siteId = defined('SITE_ID') ? SITE_ID : $this->getFirstAvailableSiteId();
        return $this->captureOfferStateFingerprintsForSite($offerIds, $siteId);
    }

    /** @param int[] $offerIds @return array<int,array{calculation:string}> */
    public function captureOfferStateFingerprintsAtSite(array $offerIds, string $siteId): array
    {
        $siteId = trim($siteId);
        if (preg_match('/^[A-Za-z0-9_-]{1,10}$/D', $siteId) !== 1) {
            throw new \InvalidArgumentException('Передан некорректный siteId для проверки расчётного состояния.');
        }
        return $this->captureOfferStateFingerprintsForSite($offerIds, $siteId);
    }

    /**
     * @param int[] $offerIds
     * @return array<int,array{calculation:string,catalog:string}>
     */
    public function captureOfferWriteStateFingerprints(array $offerIds): array
    {
        $siteId = defined('SITE_ID') ? SITE_ID : $this->getFirstAvailableSiteId();
        return $this->captureOfferWriteStateFingerprintsAtSite($offerIds, $siteId);
    }

    /**
     * @param int[] $offerIds
     * @return array<int,array{calculation:string,catalog:string}>
     */
    public function captureOfferWriteStateFingerprintsAtSite(array $offerIds, string $siteId): array
    {
        $calculation = $this->captureOfferStateFingerprintsAtSite($offerIds, $siteId);
        $catalogPayload = $this->initPayloadService->prepareCatalogWritePayload($offerIds, $siteId);
        $presetId = (int)($catalogPayload['presetId'] ?? 0);
        self::assertSupportedBatchPresetId($presetId);
        $catalog = (new CatalogCalculationWriteService())->captureCatalogWriteStateFingerprints(
            $presetId,
            $offerIds,
            $siteId
        );
        foreach ($calculation as $offerId => &$state) {
            $catalogState = $catalog[$offerId] ?? $catalog[(string)$offerId] ?? null;
            $catalogHash = is_array($catalogState)
                ? strtolower(trim((string)($catalogState['catalog'] ?? '')))
                : '';
            if (preg_match('/^[a-f0-9]{64}$/D', $catalogHash) !== 1) {
                throw new \RuntimeException('Не удалось подтвердить состояние записи ТП #' . (int)$offerId . '.');
            }
            $state['catalog'] = $catalogHash;
        }
        unset($state);
        ksort($calculation, SORT_NUMERIC);
        return $calculation;
    }

    /**
     * Execute a read-only, server-authoritative calculation for an exact set
     * of offers. The returned state pins are captured from the payload sent to
     * calc-server and re-read after the network calls, so a response produced
     * while calculation inputs were changing is rejected before it can reach
     * any catalog writer.
     *
     * @param int[] $offerIds
     * @return array{
     *   contract:string,
     *   results:array<int,array<string,mixed>>,
     *   stateFingerprints:array<int,array{calculation:string}>,
     *   provenance:array<string,mixed>
     * }
     */
    public function calculateOffersForPreview(array $offerIds, string $siteId): array
    {
        $offerIds = array_values(array_unique(array_filter(array_map('intval', $offerIds), static function (int $offerId): bool {
            return $offerId > 0;
        })));
        sort($offerIds, SORT_NUMERIC);

        if ($offerIds === []) {
            throw new \InvalidArgumentException('Не выбраны торговые предложения для серверного расчёта.');
        }

        $siteId = trim($siteId);
        if (preg_match('/^[A-Za-z0-9_-]{1,10}$/D', $siteId) !== 1) {
            throw new \InvalidArgumentException('Передан некорректный siteId для серверного расчёта.');
        }

        $fingerprints = [];
        $results = [];
        $requestHashes = [];
        $sourceVersions = [];
        $sourcePins = null;
        $runtimeLocks = [
            'elements' => [],
            'sourceIblockIds' => [],
            'priceTypeIds' => [],
            'globalSymbolIblockIds' => [],
            'globalSymbolProperties' => [],
            'measureRatioProductIds' => [],
            'measureIds' => [],
            'propertyIds' => [],
        ];
        foreach (array_chunk($offerIds, self::PREVIEW_CHUNK_SIZE) as $offerChunk) {
            $initPayload = $this->prepareCalculationPayload($offerChunk, $siteId);
            $fingerprints += $this->captureStateFingerprintsFromPayload($initPayload, $offerChunk);
            $requestPayload = $this->buildPayloadForOffers($initPayload, $offerChunk);
            $this->assertNeutralCalcServerPayload($requestPayload);
            $runtimeLocks = $this->mergeRuntimeLocks(
                $runtimeLocks,
                $this->extractRuntimeLocks($requestPayload)
            );
            // Provenance must be stable across preview/apply retries. The raw
            // INIT envelope contains timestamp/user/theme/UI configuration,
            // none of which is calculation authority and would otherwise make
            // an unchanged second server calculation produce a new write
            // fingerprint every time.
            $requestHashes[] = $this->computeStateHash($requestPayload);

            $runtime = is_array($requestPayload['editorRuntime'] ?? null)
                ? $requestPayload['editorRuntime']
                : [];
            $publication = is_array($runtime['publication'] ?? null) ? $runtime['publication'] : [];
            $inputMapping = is_array($runtime['calculatorInputMapping'] ?? null)
                ? $runtime['calculatorInputMapping']
                : [];
            $outputMapping = is_array($runtime['catalogOutputMapping'] ?? null)
                ? $runtime['catalogOutputMapping']
                : [];
            $chunkPins = [
                'publication' => [
                    'revision' => (int)($publication['revision'] ?? 0),
                    'compileHash' => strtolower(trim((string)($publication['compileHash'] ?? ''))),
                ],
                'inputMappingRevision' => (int)($inputMapping['revision'] ?? 0),
                'outputMappingRevision' => (int)($outputMapping['revision'] ?? 0),
                'presetId' => (int)($requestPayload['preset']['id'] ?? 0),
                'neutralInputRequired' => true,
                'runtimeConfigSnapshot' => is_array($requestPayload['_runtimeConfigSnapshot'] ?? null)
                    ? $requestPayload['_runtimeConfigSnapshot']
                    : [],
            ];
            if ($sourcePins === null) {
                $sourcePins = $chunkPins;
            } elseif ($this->canonicalizeStateHashValue($sourcePins)
                !== $this->canonicalizeStateHashValue($chunkPins)) {
                throw new \RuntimeException('Публикация формы или сопоставления изменились между частями серверного расчёта.');
            }

            $calcResult = $this->callCalcServer($requestPayload);
            if (!$calcResult || !isset($calcResult['success']) || !$calcResult['success']) {
                $error = $calcResult['error'] ?? 'Ошибка расчёта на сервере';
                if (is_array($error)) {
                    $error = $error['message'] ?? $error['code'] ?? 'Ошибка расчёта на сервере';
                }
                throw new \RuntimeException((string)$error);
            }
            $chunkResults = $calcResult['data'] ?? [];
            if (!is_array($chunkResults)) {
                throw new \RuntimeException('calc-server вернул некорректный список результатов.');
            }
            $chunkResults = $this->normalizeStandaloneCatalogPrices($chunkResults, $requestPayload);
            $this->assertServerResultTargets($chunkResults, $offerChunk);
            $results = array_merge($results, $chunkResults);

            $sourceVersion = trim((string)($calcResult['meta']['sourceVersion'] ?? ''));
            if ($sourceVersion !== '') {
                $sourceVersions[] = substr($sourceVersion, 0, 128);
            }
        }
        ksort($fingerprints, SORT_NUMERIC);

        if (array_map('intval', array_keys($fingerprints)) !== $offerIds) {
            throw new \RuntimeException('Не удалось подтвердить текущее состояние всех выбранных ТП.');
        }

        $currentFingerprints = $this->captureOfferStateFingerprintsForSite($offerIds, $siteId);
        if ($this->canonicalizeStateHashValue($currentFingerprints)
            !== $this->canonicalizeStateHashValue($fingerprints)) {
            throw new \RuntimeException(
                'Расчётные данные изменились во время обращения к calc-server. Повторите расчёт.'
            );
        }

        usort($results, static function (array $left, array $right): int {
            return ((int)($left['offerId'] ?? 0)) <=> ((int)($right['offerId'] ?? 0));
        });
        $this->assertServerResultTargets($results, $offerIds);
        $sourceVersions = array_values(array_unique($sourceVersions));

        return [
            'contract' => self::SERVER_CALCULATION_CONTRACT,
            'results' => $results,
            'stateFingerprints' => $fingerprints,
            'provenance' => [
                'contract' => self::SERVER_CALCULATION_CONTRACT . '/provenance',
                'presetId' => (int)($sourcePins['presetId'] ?? 0),
                'publication' => is_array($sourcePins['publication'] ?? null)
                    ? $sourcePins['publication']
                    : [],
                'inputMappingRevision' => (int)($sourcePins['inputMappingRevision'] ?? 0),
                'outputMappingRevision' => (int)($sourcePins['outputMappingRevision'] ?? 0),
                'neutralInputRequired' => true,
                'runtimeConfigSnapshot' => is_array($sourcePins['runtimeConfigSnapshot'] ?? null)
                    ? $sourcePins['runtimeConfigSnapshot']
                    : [],
                'requestHashes' => $requestHashes,
                'sourceVersions' => $sourceVersions,
                'runtimeLocks' => $runtimeLocks,
            ],
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function extractRuntimeLocks(array $payload): array
    {
        $elements = [];
        $propertyIds = [];
        $measureIds = [];
        $collect = static function ($value) use (&$collect, &$elements, &$propertyIds, &$measureIds): void {
            if (!is_array($value)) {
                return;
            }
            $id = (int)($value['id'] ?? $value['ID'] ?? 0);
            $iblockId = (int)($value['iblockId'] ?? $value['IBLOCK_ID'] ?? 0);
            if ($id > 0 && $iblockId > 0) {
                $elements[$id] = ['id' => $id, 'iblockId' => $iblockId];
            }
            $measure = is_array($value['measure'] ?? null) ? $value['measure'] : [];
            $measureId = (int)($measure['id'] ?? $measure['ID'] ?? 0);
            if ($measureId > 0) {
                $measureIds[$measureId] = true;
            }
            if (is_array($value['properties'] ?? null)) {
                foreach ($value['properties'] as $property) {
                    $propertyId = is_array($property)
                        ? (int)($property['ID'] ?? $property['id'] ?? 0)
                        : 0;
                    if ($propertyId > 0) {
                        $propertyIds[$propertyId] = true;
                    }
                }
            }
            foreach ($value as $item) {
                if (is_array($item)) {
                    $collect($item);
                }
            }
        };
        $collect(is_array($payload['preset'] ?? null) ? $payload['preset'] : []);
        $collect(is_array($payload['elementsStore'] ?? null) ? $payload['elementsStore'] : []);
        $collect(is_array($payload['elementsSiblings'] ?? null) ? $payload['elementsSiblings'] : []);
        $collect(is_array($payload['globalSymbols'] ?? null) ? $payload['globalSymbols'] : []);
        ksort($elements, SORT_NUMERIC);
        $propertyIds = array_map('intval', array_keys($propertyIds));
        sort($propertyIds, SORT_NUMERIC);
        $measureIds = array_map('intval', array_keys($measureIds));
        sort($measureIds, SORT_NUMERIC);
        $measureRatioProductIds = array_map('intval', array_keys($elements));
        sort($measureRatioProductIds, SORT_NUMERIC);
        $sourceIblockIds = [];
        foreach ($elements as $element) {
            $sourceIblockIds[(int)$element['iblockId']] = true;
        }
        $runtimeConfigSnapshot = is_array($payload['_runtimeConfigSnapshot'] ?? null)
            ? $payload['_runtimeConfigSnapshot']
            : [];
        foreach ([
            'CALC_PRESETS',
            'CALC_STAGES',
            'CALC_SETTINGS',
            'CALC_GLOBAL_VALUES',
            'CALC_CUSTOM_FIELDS',
            'CALC_MATERIALS',
            'CALC_MATERIALS_VARIANTS',
            'CALC_OPERATIONS',
            'CALC_OPERATIONS_VARIANTS',
            'CALC_EQUIPMENT',
            'CALC_DETAILS',
        ] as $sourceCode) {
            $configuredSourceIblockId = $this->effectiveRuntimeConfigIblockId(
                $runtimeConfigSnapshot,
                $sourceCode
            );
            if ($configuredSourceIblockId <= 0) {
                throw new \RuntimeException(
                    'Runtime source ' . $sourceCode . ' is not pinned by direct configuration.',
                    409
                );
            }
            $sourceIblockIds[$configuredSourceIblockId] = true;
        }
        $sourceIblockIds = array_map('intval', array_keys($sourceIblockIds));
        sort($sourceIblockIds, SORT_NUMERIC);
        $configuredGlobalSymbolIblockId = $this->effectiveRuntimeConfigIblockId(
            $runtimeConfigSnapshot,
            'CALC_GLOBAL_VALUES'
        );
        if ($configuredGlobalSymbolIblockId <= 0) {
            throw new \RuntimeException(
                'IBLOCK_CALC_GLOBAL_VALUES is not pinned by direct runtime configuration.',
                409
            );
        }
        $globalSymbolIblockIds = [$configuredGlobalSymbolIblockId => true];
        foreach ((array)($payload['globalSymbols'] ?? []) as $symbol) {
            $iblockId = is_array($symbol) ? (int)($symbol['iblockId'] ?? 0) : 0;
            if ($iblockId > 0) {
                if ($iblockId !== $configuredGlobalSymbolIblockId) {
                    throw new \RuntimeException(
                        'Global-symbol payload differs from direct runtime configuration.',
                        409
                    );
                }
                $globalSymbolIblockIds[$iblockId] = true;
            }
        }
        $globalSymbolIblockIds = array_map('intval', array_keys($globalSymbolIblockIds));
        sort($globalSymbolIblockIds, SORT_NUMERIC);
        $globalSymbolProperties = $this->loadGlobalSymbolPropertyAuthority($globalSymbolIblockIds);
        foreach ($globalSymbolProperties as $authority) {
            foreach ((array)($authority['properties'] ?? []) as $propertyId) {
                $propertyIds[] = (int)$propertyId;
            }
        }
        $propertyIds = array_values(array_unique(array_filter(array_map('intval', $propertyIds))));
        sort($propertyIds, SORT_NUMERIC);

        $priceTypeIds = [];
        foreach ((array)($payload['priceTypes'] ?? []) as $priceType) {
            $priceTypeId = is_array($priceType) ? (int)($priceType['id'] ?? $priceType['ID'] ?? 0) : 0;
            if ($priceTypeId > 0) {
                $priceTypeIds[$priceTypeId] = true;
            }
        }
        $priceTypeIds = array_map('intval', array_keys($priceTypeIds));
        sort($priceTypeIds, SORT_NUMERIC);

        return [
            'elements' => array_values($elements),
            'sourceIblockIds' => $sourceIblockIds,
            'priceTypeIds' => $priceTypeIds,
            'globalSymbolIblockIds' => $globalSymbolIblockIds,
            'globalSymbolProperties' => $globalSymbolProperties,
            'measureRatioProductIds' => $measureRatioProductIds,
            'measureIds' => $measureIds,
            'propertyIds' => $propertyIds,
        ];
    }

    /** @param int[] $iblockIds @return array<int,array<string,mixed>> */
    private function loadGlobalSymbolPropertyAuthority(array $iblockIds): array
    {
        if ($iblockIds === []) {
            return [];
        }
        $requiredCodes = ['DATA_TYPE', 'INITIAL_VALUE', 'KIND', 'PRESET_ID'];
        $result = Application::getConnection()->query(
            'SELECT ID, IBLOCK_ID, CODE FROM b_iblock_property WHERE IBLOCK_ID IN ('
            . implode(',', array_map('intval', $iblockIds))
            . ") AND CODE IN ('DATA_TYPE','INITIAL_VALUE','KIND','PRESET_ID') "
            . 'ORDER BY IBLOCK_ID, CODE, ID'
        );
        $byIblock = [];
        while (is_object($result) && method_exists($result, 'fetch') && ($row = $result->fetch())) {
            $iblockId = (int)($row['IBLOCK_ID'] ?? $row['iblock_id'] ?? 0);
            $code = (string)($row['CODE'] ?? $row['code'] ?? '');
            $id = (int)($row['ID'] ?? $row['id'] ?? 0);
            if ($iblockId <= 0 || $id <= 0 || !in_array($code, $requiredCodes, true)
                || isset($byIblock[$iblockId][$code])) {
                throw new \RuntimeException('Global-symbol property authority is ambiguous.', 409);
            }
            $byIblock[$iblockId][$code] = $id;
        }
        $authority = [];
        foreach ($iblockIds as $iblockId) {
            $properties = $byIblock[$iblockId] ?? [];
            ksort($properties, SORT_STRING);
            if (array_keys($properties) !== $requiredCodes) {
                throw new \RuntimeException('Global-symbol property authority is incomplete.', 409);
            }
            $authority[] = ['iblockId' => $iblockId, 'properties' => $properties];
        }
        return $authority;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right @return array<string,mixed> */
    private function mergeRuntimeLocks(array $left, array $right): array
    {
        $elements = [];
        foreach (array_merge((array)($left['elements'] ?? []), (array)($right['elements'] ?? [])) as $element) {
            if (is_array($element) && (int)($element['id'] ?? 0) > 0 && (int)($element['iblockId'] ?? 0) > 0) {
                $elements[(int)$element['id']] = [
                    'id' => (int)$element['id'],
                    'iblockId' => (int)$element['iblockId'],
                ];
            }
        }
        ksort($elements, SORT_NUMERIC);
        $sourceIblockIds = array_values(array_unique(array_filter(array_map(
            'intval',
            array_merge(
                (array)($left['sourceIblockIds'] ?? []),
                (array)($right['sourceIblockIds'] ?? [])
            )
        ), static function (int $id): bool {
            return $id > 0;
        })));
        sort($sourceIblockIds, SORT_NUMERIC);
        $priceTypeIds = array_values(array_unique(array_filter(array_map(
            'intval',
            array_merge((array)($left['priceTypeIds'] ?? []), (array)($right['priceTypeIds'] ?? []))
        ), static function (int $id): bool {
            return $id > 0;
        })));
        sort($priceTypeIds, SORT_NUMERIC);
        $globalSymbolIblockIds = array_values(array_unique(array_filter(array_map(
            'intval',
            array_merge(
                (array)($left['globalSymbolIblockIds'] ?? []),
                (array)($right['globalSymbolIblockIds'] ?? [])
            )
        ), static function (int $id): bool {
            return $id > 0;
        })));
        sort($globalSymbolIblockIds, SORT_NUMERIC);
        $globalSymbolPropertiesByIblock = [];
        foreach (array_merge(
            (array)($left['globalSymbolProperties'] ?? []),
            (array)($right['globalSymbolProperties'] ?? [])
        ) as $authority) {
            $iblockId = is_array($authority) ? (int)($authority['iblockId'] ?? 0) : 0;
            $properties = is_array($authority['properties'] ?? null) ? $authority['properties'] : [];
            ksort($properties, SORT_STRING);
            if ($iblockId <= 0
                || (isset($globalSymbolPropertiesByIblock[$iblockId])
                    && $globalSymbolPropertiesByIblock[$iblockId] !== $properties)) {
                throw new \RuntimeException('Global-symbol property authority changed between calculation chunks.', 409);
            }
            $globalSymbolPropertiesByIblock[$iblockId] = $properties;
        }
        ksort($globalSymbolPropertiesByIblock, SORT_NUMERIC);
        $globalSymbolProperties = [];
        foreach ($globalSymbolPropertiesByIblock as $iblockId => $properties) {
            $globalSymbolProperties[] = [
                'iblockId' => (int)$iblockId,
                'properties' => $properties,
            ];
        }
        $measureRatioProductIds = array_values(array_unique(array_filter(array_map(
            'intval',
            array_merge(
                (array)($left['measureRatioProductIds'] ?? []),
                (array)($right['measureRatioProductIds'] ?? [])
            )
        ), static function (int $id): bool {
            return $id > 0;
        })));
        sort($measureRatioProductIds, SORT_NUMERIC);
        $measureIds = array_values(array_unique(array_filter(array_map(
            'intval',
            array_merge((array)($left['measureIds'] ?? []), (array)($right['measureIds'] ?? []))
        ), static function (int $id): bool {
            return $id > 0;
        })));
        sort($measureIds, SORT_NUMERIC);
        $propertyIds = array_values(array_unique(array_filter(array_map(
            'intval',
            array_merge((array)($left['propertyIds'] ?? []), (array)($right['propertyIds'] ?? []))
        ), static function (int $id): bool {
            return $id > 0;
        })));
        sort($propertyIds, SORT_NUMERIC);
        return [
            'elements' => array_values($elements),
            'sourceIblockIds' => $sourceIblockIds,
            'priceTypeIds' => $priceTypeIds,
            'globalSymbolIblockIds' => $globalSymbolIblockIds,
            'globalSymbolProperties' => $globalSymbolProperties,
            'measureRatioProductIds' => $measureRatioProductIds,
            'measureIds' => $measureIds,
            'propertyIds' => $propertyIds,
        ];
    }

    /**
     * @param int[] $offerIds
     * @return array<int,array{calculation:string}>
     */
    private function captureOfferStateFingerprintsForSite(array $offerIds, string $siteId): array
    {
        $offerIds = array_values(array_unique(array_filter(array_map('intval', $offerIds), static function (int $offerId): bool {
            return $offerId > 0;
        })));
        sort($offerIds, SORT_NUMERIC);
        if ($offerIds === []) {
            return [];
        }

        $fingerprints = [];
        foreach (array_chunk($offerIds, self::PREVIEW_CHUNK_SIZE) as $offerChunk) {
            $initPayload = $this->prepareCalculationPayload($offerChunk, $siteId);
            $fingerprints += $this->captureStateFingerprintsFromPayload($initPayload, $offerChunk);
        }
        ksort($fingerprints, SORT_NUMERIC);
        if (array_map('intval', array_keys($fingerprints)) !== $offerIds) {
            throw new \RuntimeException('Не удалось подтвердить текущее состояние всех выбранных ТП.');
        }

        return $fingerprints;
    }

    /**
     * Выполнить пересчёт группы торговых предложений одним запросом к calc-server
     *
     * @param int[] $offerIds ID торговых предложений
     * @param bool $onlyChanged Пропускать неизменившиеся
     * @param array<int|string,array{calculation:string,catalog?:string}> $expectedStateFingerprints
     *        Optional preview-approved CAS state. Legacy callers may omit it;
     *        the batch endpoint always supplies it before catalog writes.
     * @param array<int|string,string> $expectedResultFingerprints
     * @param int $actorUserId Authenticated batch owner for durable replay.
     * @param string $requestId Stable SHA-256 job chunk identifier.
     * @return array<int, array{status: string, error?: string, resultCount?: int}>
     */
    public function recalculateOffers(
        array $offerIds,
        bool $onlyChanged = true,
        array $expectedStateFingerprints = [],
        array $expectedResultFingerprints = [],
        int $actorUserId = 0,
        string $requestId = ''
    ): array
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
            $initPayload = $this->prepareCalculationPayload($offerIds, $siteId);
            $resolvedPresetId = (int)($initPayload['preset']['id'] ?? 0);
            // Resolve the server-owned payload first, then fail closed before
            // fingerprints, network or the authoritative writer.
            self::assertSupportedBatchPresetId($resolvedPresetId);

            $offersToProcess = [];
            foreach ($offerIds as $offerId) {
                $currentHash = $this->computeStateHashForOffer($initPayload, $offerId);

                if ($expectedStateFingerprints !== []) {
                    $expectedState = $expectedStateFingerprints[$offerId]
                        ?? $expectedStateFingerprints[(string)$offerId]
                        ?? null;
                    $expectedHash = is_array($expectedState)
                        ? strtolower(trim((string)($expectedState['calculation'] ?? '')))
                        : '';
                    if (preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1
                        || !hash_equals($expectedHash, $currentHash)) {
                        $resultsByOfferId[$offerId] = [
                            'status' => 'error',
                            'error' => 'Состояние ТП изменилось после предварительной проверки. Повторите проверку без записи.',
                        ];
                        continue;
                    }
                }

                $offersToProcess[] = $offerId;
            }

            if (empty($offersToProcess)) {
                return $resultsByOfferId;
            }

            $requestPayload = $this->buildPayloadForOffers($initPayload, $offersToProcess);
            self::assertSupportedBatchPresetId((int)($requestPayload['preset']['id'] ?? 0));
            $approvedStates = [];
            $approvedResults = [];
            foreach ($offersToProcess as $offerId) {
                $state = $expectedStateFingerprints[$offerId]
                    ?? $expectedStateFingerprints[(string)$offerId]
                    ?? null;
                $resultFingerprint = $expectedResultFingerprints[$offerId]
                    ?? $expectedResultFingerprints[(string)$offerId]
                    ?? null;
                if (!is_array($state)
                    || preg_match('/^[a-f0-9]{64}$/D', strtolower(trim((string)($state['catalog'] ?? '')))) !== 1
                    || !is_string($resultFingerprint)
                    || preg_match('/^[a-f0-9]{64}$/D', strtolower(trim($resultFingerprint))) !== 1) {
                    throw new \RuntimeException(
                        'Пакетная запись пресета не содержит подтверждённые catalog/result fingerprints.'
                    );
                }
                $approvedStates[$offerId] = $state;
                $approvedResults[$offerId] = strtolower(trim($resultFingerprint));
            }

            $catalogWriter = new CatalogCalculationWriteService();
            $presetId = (int)($requestPayload['preset']['id'] ?? 0);
            $writeResult = $catalogWriter->replayAuthoritativeBatch(
                $presetId,
                $offersToProcess,
                $siteId,
                $approvedStates,
                $approvedResults,
                $actorUserId,
                $requestId
            );
            if (is_array($writeResult)) {
                // Receipt replay is deliberately resolved before any
                // calc-server call. Only offer IDs are needed below to
                // rebuild the job result; the transaction audit is not duplicated.
                $offerResults = array_map(static function (array $row): array {
                    return ['offerId' => (int)($row['offerId'] ?? 0)];
                }, is_array($writeResult['offers'] ?? null) ? $writeResult['offers'] : []);
            } else {
                $authoritativeCalculation = $this->calculateOffersForPreview($offersToProcess, $siteId);
                $offerResults = is_array($authoritativeCalculation['results'] ?? null)
                    ? $authoritativeCalculation['results']
                    : [];
                $writeResult = $catalogWriter->applyAuthoritativeBatch(
                    $presetId,
                    $offersToProcess,
                    $authoritativeCalculation,
                    $siteId,
                    $approvedStates,
                    $approvedResults,
                    $onlyChanged,
                    $actorUserId,
                    $requestId
                );
            }

            $writeResultsByOfferId = [];
            foreach (($writeResult['offers'] ?? []) as $offerWriteResult) {
                $writeOfferId = (int)($offerWriteResult['offerId'] ?? 0);
                if ($writeOfferId > 0) {
                    $writeResultsByOfferId[$writeOfferId] = $offerWriteResult;
                }
            }

            $returnedOfferIds = [];
            foreach ($offerResults as $offerResult) {
                $returnedOfferId = (int)($offerResult['offerId'] ?? 0);
                if ($returnedOfferId <= 0) {
                    continue;
                }

                $returnedOfferIds[$returnedOfferId] = true;
                $offerWriteResult = $writeResultsByOfferId[$returnedOfferId] ?? null;
                if (is_array($offerWriteResult) && ($offerWriteResult['status'] ?? '') === 'skipped') {
                    $resultsByOfferId[$returnedOfferId] = ['status' => 'skipped'];
                    continue;
                }
                if (!is_array($offerWriteResult) || ($offerWriteResult['status'] ?? 'error') !== 'ok') {
                    $resultsByOfferId[$returnedOfferId] = [
                        'status' => 'error',
                        'error' => (string)($offerWriteResult['message'] ?? 'Результат расчёта не был полностью записан в ТП'),
                    ];
                    continue;
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
     * Every preset is calculated from its published standalone form. Real
     * products and offers are read only to resolve the output target matrix;
     * calc-server receives product=null and virtual offers.
     *
     * @param int[] $offerIds
     * @return array<string,mixed>
     */
    private function prepareCalculationPayload(array $offerIds, string $siteId): array
    {
        // Catalog preview/recalculation must be strictly read-only until
        // the explicit writer transaction. prepareInitPayload() performs
        // repair/migration/enrichment and is intentionally not used here.
        $configAuthority = new CatalogCalculationWriteService();
        $configBefore = $configAuthority->captureRuntimeConfigSnapshot();
        $configuredUrl = trim((string)($configBefore['prospektweb.calc:CALC_SERVER_URL'] ?? ''));
        $configuredUrl = self::normalizeCalcServerUrl(
            $configuredUrl !== '' ? $configuredUrl : 'https://pwrt.ru/calc-api'
        );
        if (!hash_equals($configuredUrl, $this->calcServerUrl)) {
            throw new \RuntimeException('calc-server endpoint differs from direct b_option authority.', 409);
        }
        $catalogPayload = $this->initPayloadService->prepareCatalogWritePayload(
            $offerIds,
            $siteId,
            null,
            null,
            null,
            null,
            null,
            $configBefore
        );
        $configAfter = $configAuthority->captureRuntimeConfigSnapshot();
        if ($this->canonicalizeStateHashValue($configBefore)
            !== $this->canonicalizeStateHashValue($configAfter)) {
            throw new \RuntimeException('ConfigManager options changed while preparing calc-server input.', 409);
        }
        $catalogPayload['_runtimeConfigSnapshot'] = $configBefore;
        return $this->buildCalculationPayloadFromCatalogPayload($catalogPayload, $siteId);
    }

    /**
     * Compute the same per-offer input fingerprints from a caller-owned,
     * read-only catalog payload. The catalog writer uses this with raw option
     * values resolved under row locks, avoiding Bitrix Option cache reuse.
     *
     * @param array<string,mixed> $catalogPayload
     * @param int[] $offerIds
     * @return array<int,array{calculation:string}>
     */
    public function captureOfferStateFingerprintsFromResolvedCatalogPayload(
        array $catalogPayload,
        array $offerIds,
        string $siteId
    ): array {
        $payload = $this->buildCalculationPayloadFromCatalogPayload($catalogPayload, $siteId);
        return $this->captureStateFingerprintsFromPayload($payload, $offerIds);
    }

    /** @param array<string,mixed> $catalogPayload @return array<string,mixed> */
    private function buildCalculationPayloadFromCatalogPayload(array $catalogPayload, string $siteId): array
    {
        $editorRuntime = is_array($catalogPayload['editorRuntime'] ?? null)
            ? $catalogPayload['editorRuntime']
            : [];
        $catalogMapping = is_array($editorRuntime['catalogInputMapping'] ?? null)
            ? $editorRuntime['catalogInputMapping']
            : [];
        if (($catalogMapping['ready'] ?? null) !== true) {
            $errors = is_array($catalogMapping['errors'] ?? null) ? $catalogMapping['errors'] : [];
            throw new \RuntimeException(
                'Catalog input mapping is not ready for every selected offer: '
                    . json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                409
            );
        }

        if (!Loader::includeModule('prospektweb.frontcalc')) {
            throw new \RuntimeException('Для автономной записи требуется модуль prospektweb.frontcalc.');
        }

        $schema = is_array($catalogPayload['_publishedSnapshot'] ?? null)
            ? $catalogPayload['_publishedSnapshot']
            : [];
        if ($schema === []) {
            throw new \RuntimeException('Не найдена опубликованная форма пресета.');
        }
        $presetId = (int)($catalogPayload['presetId'] ?? 0);
        self::assertSupportedBatchPresetId($presetId);

        $expectedOfferIds = [];
        foreach ((array)($catalogPayload['selectedOffers'] ?? []) as $targetOffer) {
            $offerId = is_array($targetOffer) ? (int)($targetOffer['id'] ?? 0) : 0;
            if ($offerId > 0) {
                $expectedOfferIds[] = $offerId;
            }
        }
        $expectedOfferIds = array_values(array_unique($expectedOfferIds));
        sort($expectedOfferIds, SORT_NUMERIC);

        $runtimeCatalog = new \Prospektweb\Frontcalc\Service\PresetRuntimeCatalog();
        $schemaCatalog = $runtimeCatalog->build($schema, $presetId);
        $validator = new \Prospektweb\Frontcalc\Service\CustomSelectionValidator();
        $builder = new \Prospektweb\Frontcalc\Service\VirtualOfferBatchBuilder();
        $virtualOffers = [];
        $scenariosByOfferId = [];
        foreach ((array)($editorRuntime['catalogScenarios'] ?? []) as $scenario) {
            $scenarioOfferId = is_array($scenario) ? (int)($scenario['target']['offerId'] ?? 0) : 0;
            if ($scenarioOfferId > 0 && !isset($scenariosByOfferId[$scenarioOfferId])) {
                $scenariosByOfferId[$scenarioOfferId] = $scenario;
            }
        }

        foreach ((array)($catalogPayload['selectedOffers'] ?? []) as $targetOffer) {
            if (!is_array($targetOffer)) {
                continue;
            }
            $offerId = (int)($targetOffer['id'] ?? 0);
            $productId = (int)($targetOffer['productId'] ?? 0);
            $scenario = is_array($scenariosByOfferId[$offerId] ?? null)
                ? $scenariosByOfferId[$offerId]
                : null;
            if ($scenario === null
                || (int)($scenario['presetId'] ?? 0) !== $presetId
                || (string)($scenario['source'] ?? '') !== 'catalog-input-mapping') {
                throw new \RuntimeException('ТП #' . $offerId . ': отсутствует допустимый сценарий сопоставления входов.');
            }
            $validated = $validator->validate(
                $schema,
                is_array($scenario['values'] ?? null) ? $scenario['values'] : [],
                is_array($schemaCatalog['enumValues'] ?? null) ? $schemaCatalog['enumValues'] : [],
                is_array($schemaCatalog['presetBuckets'] ?? null) ? $schemaCatalog['presetBuckets'] : []
            );
            if (empty($validated['ok'])) {
                $error = is_array($validated['error'] ?? null) ? $validated['error'] : [];
                throw new \RuntimeException(
                    'ТП #' . $offerId . ': '
                    . (string)($error['message'] ?? 'не удалось сопоставить с опубликованной формой пресета')
                );
            }
            $built = $builder->buildForQuantities(
                $schema,
                is_array($validated['values'] ?? null) ? $validated['values'] : [],
                0,
                0,
                [(int)($scenario['quantity'] ?? 0)]
            );
            if (count($built) !== 1) {
                throw new \RuntimeException('ТП #' . $offerId . ': не удалось построить точный виртуальный расчёт.');
            }
            $virtualOffer = $built[0];
            $virtualOffer['id'] = $offerId;
            $offerName = trim((string)($scenario['target']['name'] ?? $targetOffer['name'] ?? ''));
            $virtualOffer['name'] = $offerName !== '' ? $offerName : 'Расчётное ТП #' . $offerId;
            // Catalog identity remains transport/provenance only. Neutral
            // calc-server execution strips it from the formula context.
            $virtualOffer['productId'] = $productId;
            $virtualOffer['iblockId'] = 0;
            $virtualOffers[] = $virtualOffer;
        }

        if (count($virtualOffers) !== count($expectedOfferIds)) {
            throw new \RuntimeException('Не все выбранные ТП сопоставлены с пресетом.');
        }

        if (!array_key_exists('_neutralInputRequired', $catalogPayload)
            || $catalogPayload['_neutralInputRequired'] !== true) {
            throw new \RuntimeException('Catalog payload is not bound to neutral-input mode.', 409);
        }
        $neutralInputRequired = true;
        $publishedAuthoring = [
            'formDefinition' => is_array($editorRuntime['formDefinition'] ?? null)
                ? $editorRuntime['formDefinition']
                : [],
            'bindingDefinition' => is_array($editorRuntime['bindingDefinition'] ?? null)
                ? $editorRuntime['bindingDefinition']
                : [],
            'publication' => is_array($editorRuntime['publication'] ?? null)
                ? $editorRuntime['publication']
                : [],
        ];
        $virtualOffers = (new \Prospektweb\Frontcalc\Service\NeutralCalculationInputBuilder())
            ->decorateOffers(
                $virtualOffers,
                $publishedAuthoring,
                $presetId,
                'catalog-input-mapping'
            );

        $globalSymbols = is_array($catalogPayload['_globalSymbols'] ?? null)
            ? array_values($catalogPayload['_globalSymbols'])
            : null;
        if ($globalSymbols === null) {
            throw new \RuntimeException('Catalog payload does not pin the global symbol registry.');
        }
        $runtimeConfigSnapshot = is_array($catalogPayload['_runtimeConfigSnapshot'] ?? null)
            ? $catalogPayload['_runtimeConfigSnapshot']
            : [];
        if ($runtimeConfigSnapshot === []) {
            throw new \RuntimeException('Catalog payload does not pin ConfigManager option authority.');
        }
        $payload = (new InitPayloadService())->preparePresetCalculationPayloadReadOnlyPinned(
            $presetId,
            $virtualOffers,
            $siteId,
            $globalSymbols,
            $runtimeConfigSnapshot
        );
        $payload['globalSymbols'] = $globalSymbols;
        $payload['editorRuntime'] = $catalogPayload['editorRuntime'] ?? null;
        $payload['neutralInputRequired'] = $neutralInputRequired;
        $payload['_runtimeConfigSnapshot'] = $runtimeConfigSnapshot;
        $this->assertPayloadMatchesRuntimeConfig($catalogPayload, $payload);
        return $payload;
    }

    /**
     * Detect a process-static/Option-cache split brain before calc-server can
     * see a payload. Direct b_option rows are authority; cached IDs may only be
     * used when the loaded entities prove that they resolve to the same IDs.
     *
     * @param array<string,mixed> $catalogPayload
     * @param array<string,mixed> $calculationPayload
     */
    private function assertPayloadMatchesRuntimeConfig(array $catalogPayload, array $calculationPayload): void
    {
        $snapshot = is_array($catalogPayload['_runtimeConfigSnapshot'] ?? null)
            ? $catalogPayload['_runtimeConfigSnapshot']
            : [];
        if ($snapshot === []) {
            throw new \RuntimeException('Catalog payload does not pin ConfigManager option authority.');
        }
        $effectiveId = static function (array $rows, string $code): int {
            $candidates = [];
            if ($code === 'PRODUCTS') {
                $candidates = [
                    'prospektweb.frontcalc:PRODUCTS_IBLOCK_ID',
                    'prospektweb.calc:PRODUCT_IBLOCK_ID',
                ];
            } elseif ($code === 'OFFERS') {
                $candidates = [
                    'prospektweb.frontcalc:OFFERS_IBLOCK_ID',
                    'prospektweb.calc:SKU_IBLOCK_ID',
                ];
            } else {
                $candidates = [
                    'prospektweb.frontcalc:IBLOCK_' . $code,
                    'prospektweb.calc:IBLOCK_' . $code,
                ];
            }
            foreach ($candidates as $candidate) {
                $value = (int)($rows[$candidate] ?? 0);
                if ($value > 0) {
                    return $value;
                }
            }
            return 0;
        };
        $assertId = static function (int $expected, int $actual, string $code): void {
            if ($actual > 0 && ($expected <= 0 || $actual !== $expected)) {
                throw new \RuntimeException(
                    'Cached ConfigManager mapping for ' . $code . ' differs from direct b_option authority.',
                    409
                );
            }
        };

        $expectedOfferIblockId = $effectiveId($snapshot, 'OFFERS');
        foreach ((array)($catalogPayload['selectedOffers'] ?? []) as $offer) {
            if (is_array($offer)) {
                $assertId($expectedOfferIblockId, (int)($offer['iblockId'] ?? 0), 'OFFERS');
            }
        }
        $expectedProductIblockId = $effectiveId($snapshot, 'PRODUCTS');
        foreach ((array)($catalogPayload['_productIblockIds'] ?? []) as $productIblockId) {
            $assertId($expectedProductIblockId, (int)$productIblockId, 'PRODUCTS');
        }

        $runtimeIblocks = [];
        $collect = static function (string $code, $rows) use (&$runtimeIblocks): void {
            foreach ((array)$rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $iblockId = (int)($row['iblockId'] ?? 0);
                if ($iblockId > 0) {
                    $runtimeIblocks[$code][$iblockId] = true;
                }
            }
        };
        $preset = is_array($calculationPayload['preset'] ?? null) ? $calculationPayload['preset'] : [];
        $collect('CALC_PRESETS', $preset === [] ? [] : [$preset]);
        foreach ((array)($calculationPayload['elementsStore'] ?? []) as $code => $rows) {
            if (is_string($code) && strpos($code, 'CALC_') === 0) {
                $collect($code, $rows);
            }
        }
        foreach ((array)($calculationPayload['elementsSiblings'] ?? []) as $code => $rows) {
            if (is_string($code) && strpos($code, 'CALC_') === 0) {
                $collect($code, $rows);
            }
        }
        $collect('CALC_GLOBAL_VALUES', $calculationPayload['globalSymbols'] ?? []);
        $globalStorageId = (int)($catalogPayload['_globalSymbolIblockId'] ?? 0);
        if ($globalStorageId > 0) {
            $runtimeIblocks['CALC_GLOBAL_VALUES'][$globalStorageId] = true;
        }
        foreach ($runtimeIblocks as $code => $ids) {
            $expected = $effectiveId($snapshot, $code);
            foreach (array_map('intval', array_keys($ids)) as $actual) {
                $assertId($expected, $actual, $code);
            }
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function effectiveRuntimeConfigIblockId(array $snapshot, string $code): int
    {
        if ($code === 'PRODUCTS') {
            $keys = [
                'prospektweb.frontcalc:PRODUCTS_IBLOCK_ID',
                'prospektweb.calc:PRODUCT_IBLOCK_ID',
            ];
        } elseif ($code === 'OFFERS') {
            $keys = [
                'prospektweb.frontcalc:OFFERS_IBLOCK_ID',
                'prospektweb.calc:SKU_IBLOCK_ID',
            ];
        } else {
            $keys = [
                'prospektweb.frontcalc:IBLOCK_' . $code,
                'prospektweb.calc:IBLOCK_' . $code,
            ];
        }
        foreach ($keys as $key) {
            $id = (int)($snapshot[$key] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }
        return 0;
    }

    /**
     * @param array<int,array<string,mixed>> $offerResults
     * @param array<string,mixed> $requestPayload
     * @return array<int,array<string,mixed>>
     */
    private function normalizeStandaloneCatalogPrices(array $offerResults, array $requestPayload): array
    {
        if (array_key_exists('product', $requestPayload) && $requestPayload['product'] !== null) {
            return $offerResults;
        }
        if (!Loader::includeModule('prospektweb.frontcalc')) {
            throw new \RuntimeException('Для автономной записи требуется модуль prospektweb.frontcalc.');
        }

        return (new StandaloneCatalogPriceNormalizer())->normalize(
            $offerResults,
            is_array($requestPayload['priceTypes'] ?? null) ? $requestPayload['priceTypes'] : [],
            is_array($requestPayload['preset']['prices'] ?? null) ? $requestPayload['preset']['prices'] : []
        );
    }

    /**
     * Apply the preset-owned output allowlist to preview and write flows.
     *
     * @param array<int,array<string,mixed>> $offerResults
     * @param array<string,mixed> $requestPayload
     * @return array<int,array<string,mixed>>
     */
    private function projectCatalogOutputResults(array $offerResults, array $requestPayload): array
    {
        $presetId = (int)($requestPayload['preset']['id'] ?? 0);
        self::assertSupportedBatchPresetId($presetId);
        $editorRuntime = is_array($requestPayload['editorRuntime'] ?? null)
            ? $requestPayload['editorRuntime']
            : [];
        $definition = is_array($editorRuntime['catalogOutputMapping'] ?? null)
            ? $editorRuntime['catalogOutputMapping']
            : null;
        $publication = is_array($editorRuntime['publication'] ?? null)
            ? $editorRuntime['publication']
            : null;
        if (!is_array($definition) || !is_array($publication)) {
            throw new \RuntimeException('Расчёт пресета не содержит подтверждённые ревизии формы и записи каталога.');
        }
        return $this->catalogOutputMappingService->projectResultsForWrite(
            $presetId,
            $offerResults,
            is_array($requestPayload['priceTypes'] ?? null) ? $requestPayload['priceTypes'] : [],
            $definition,
            $publication
        );
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
        $this->assertNeutralCalcServerPayload($initPayload);
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
     * @param array<string,mixed> $initPayload
     * @param int[] $expectedOfferIds
     * @return array<int,array{calculation:string}>
     */
    private function captureStateFingerprintsFromPayload(array $initPayload, array $expectedOfferIds): array
    {
        $expectedOfferIds = array_values(array_unique(array_filter(array_map('intval', $expectedOfferIds), static function (int $offerId): bool {
            return $offerId > 0;
        })));
        sort($expectedOfferIds, SORT_NUMERIC);

        $available = [];
        foreach ((array)($initPayload['selectedOffers'] ?? []) as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $offerId = (int)($offer['id'] ?? 0);
            if ($offerId > 0) {
                $available[$offerId] = true;
            }
        }

        $fingerprints = [];
        foreach ($expectedOfferIds as $offerId) {
            if (!isset($available[$offerId])) {
                throw new \RuntimeException('Расчётное состояние не содержит выбранное ТП #' . $offerId . '.');
            }
            $fingerprints[$offerId] = [
                'calculation' => $this->computeStateHashForOffer($initPayload, $offerId),
            ];
        }

        return $fingerprints;
    }

    /**
     * @param array<int,array<string,mixed>> $results
     * @param int[] $expectedOfferIds
     */
    private function assertServerResultTargets(array $results, array $expectedOfferIds): void
    {
        $expectedOfferIds = array_values(array_unique(array_filter(array_map('intval', $expectedOfferIds), static function (int $offerId): bool {
            return $offerId > 0;
        })));
        sort($expectedOfferIds, SORT_NUMERIC);

        $resultOfferIds = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                throw new \RuntimeException('calc-server вернул некорректную запись результата.');
            }
            $offerId = (int)($result['offerId'] ?? 0);
            if ($offerId <= 0) {
                throw new \RuntimeException('calc-server вернул результат без корректного offerId.');
            }
            $resultOfferIds[] = $offerId;
        }
        sort($resultOfferIds, SORT_NUMERIC);
        if ($resultOfferIds !== $expectedOfferIds) {
            throw new \RuntimeException('calc-server вернул не тот набор торговых предложений, который был запрошен.');
        }
    }

    /**
     * Вычислить хеш состояния для набора offer IDs
     * 
     * @param array $initPayload Полные данные для расчёта
     * @return string SHA-256 хеш состояния
     */
    public function computeStateHash(array $initPayload): string
    {
        // Сериализуем весь payload, который влияет на расчёт
        $selectedOffers = [];
        foreach ((array)($initPayload['selectedOffers'] ?? []) as $offer) {
            if (is_array($offer)) {
                $selectedOffers[] = $this->normalizeOfferForStateHash($offer);
            }
        }

        $stateData = [
            'schemaVersion' => 3,
            'elementsStore' => $initPayload['elementsStore'] ?? [],
            'elementsSiblings' => $initPayload['elementsSiblings'] ?? [],
            'selectedOffers' => $selectedOffers,
            'preset' => $initPayload['preset'] ?? [],
            'priceTypes' => $initPayload['priceTypes'] ?? [],
            'globalSymbols' => $initPayload['globalSymbols'] ?? [],
            'neutralInputRequired' => $initPayload['neutralInputRequired'] ?? null,
            'runtimeConfigSnapshot' => $initPayload['_runtimeConfigSnapshot'] ?? [],
            'editorRuntime' => $initPayload['editorRuntime'] ?? null,
        ];

        return hash('sha256', json_encode(
            $this->canonicalizeStateHashValue($stateData),
            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    /**
     * Exclude catalog fields written by the recalculation itself. Otherwise
     * every successful write changes the next input hash and onlyChanged can
     * never skip an unchanged offer.
     *
     * @param array<string,mixed> $offer
     * @return array<string,mixed>
     */
    private function normalizeOfferForStateHash(array $offer): array
    {
        foreach ([
            'timestampX',
            'timestamp_x',
            'modifiedBy',
            'modified_by',
            'attributes',
            'prices',
            'purchasingPrice',
            'purchasingCurrency',
        ] as $outputKey) {
            unset($offer[$outputKey]);
        }

        if (isset($offer['catalog']) && is_array($offer['catalog'])) {
            unset($offer['catalog']['basePrice'], $offer['catalog']['baseCurrency']);
        }

        if (isset($offer['properties']) && is_array($offer['properties'])) {
            foreach (['CALC_STATE_HASH', 'COMPLETED_CALCS', 'PARAMETR_VALUES'] as $outputPropertyCode) {
                unset($offer['properties'][$outputPropertyCode]);
            }
        }

        return $offer;
    }

    /**
     * Canonicalize associative key order and remove volatile edit metadata.
     * List order remains significant because calculator arrays are ordered.
     *
     * @param mixed $value
     * @return mixed
     */
    private function canonicalizeStateHashValue($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalizedKey = strtolower((string)$key);
            if (in_array($normalizedKey, ['timestampx', 'timestamp_x', 'modifiedby', 'modified_by'], true)) {
                continue;
            }
            $normalized[$key] = $this->canonicalizeStateHashValue($item);
        }

        $keys = array_keys($normalized);
        $isList = empty($keys) || $keys === range(0, count($keys) - 1);
        if (!$isList) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
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

        if (isset($payload['editorRuntime']) && is_array($payload['editorRuntime'])) {
            $offerIdSet = [];
            foreach ($selectedOffers as $offer) {
                $offerId = (int)($offer['id'] ?? 0);
                if ($offerId > 0) {
                    $offerIdSet[$offerId] = true;
                }
            }

            $catalogScenarios = [];
            $productIdSet = [];
            foreach ((array)($payload['editorRuntime']['catalogScenarios'] ?? []) as $scenario) {
                if (!is_array($scenario)) {
                    continue;
                }
                $target = is_array($scenario['target'] ?? null) ? $scenario['target'] : [];
                $offerId = (int)($target['offerId'] ?? 0);
                if ($offerId <= 0 || !isset($offerIdSet[$offerId])) {
                    continue;
                }
                $catalogScenarios[] = $scenario;
                $productId = (int)($target['productId'] ?? 0);
                if ($productId > 0) {
                    $productIdSet[$productId] = true;
                }
            }
            $payload['editorRuntime']['catalogScenarios'] = $catalogScenarios;
            if (isset($payload['editorRuntime']['launchContext'])
                && is_array($payload['editorRuntime']['launchContext'])) {
                $payload['editorRuntime']['launchContext']['offerIds'] = array_map('intval', array_keys($offerIdSet));
                $payload['editorRuntime']['launchContext']['productIds'] = array_map('intval', array_keys($productIdSet));
            }
        }

        return $payload;
    }

    /**
     * Calc-server accepts only the published neutral form contract. This guard
     * sits immediately before every network call, so no legacy payload branch
     * can silently omit form provenance or per-offer semantic input.
     *
     * @param array<string,mixed> $payload
     */
    private function assertNeutralCalcServerPayload(array $payload): void
    {
        if (($payload['neutralInputRequired'] ?? null) !== true) {
            throw new \RuntimeException('calc-server payload must require neutral input.', 409);
        }
        if (!is_array($payload['globalSymbols'] ?? null)) {
            throw new \RuntimeException('calc-server payload must carry the global symbol registry.', 409);
        }

        $preset = is_array($payload['preset'] ?? null) ? $payload['preset'] : [];
        $presetId = (int)($preset['id'] ?? 0);
        $runtime = is_array($payload['editorRuntime'] ?? null) ? $payload['editorRuntime'] : [];
        $launch = is_array($runtime['launchContext'] ?? null) ? $runtime['launchContext'] : [];
        $publication = is_array($runtime['publication'] ?? null) ? $runtime['publication'] : [];
        if ($presetId <= 0
            || (string)($runtime['contract'] ?? '') !== 'prospektweb.calc.editor-runtime/v2'
            || (string)($launch['contract'] ?? '') !== 'prospektweb.calc.launch-context/v2'
            || (int)($launch['presetId'] ?? 0) !== $presetId
            || !in_array((string)($launch['mode'] ?? ''), ['manual', 'catalog'], true)
            || !is_array($runtime['formDefinition'] ?? null)
            || !is_array($runtime['bindingDefinition'] ?? null)
            || (int)($publication['revision'] ?? 0) <= 0
            || preg_match('/^[a-f0-9]{64}$/D', (string)($publication['compileHash'] ?? '')) !== 1) {
            throw new \RuntimeException('calc-server payload has invalid published editor runtime provenance.', 409);
        }

        $offers = $payload['selectedOffers'] ?? null;
        if (!is_array($offers) || $offers === []) {
            throw new \RuntimeException('calc-server payload must contain neutral calculation offers.', 409);
        }
        $offerIds = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                throw new \RuntimeException('calc-server payload contains an invalid offer.', 409);
            }
            $offerId = (int)($offer['id'] ?? 0);
            $input = is_array($offer['calculationInput'] ?? null) ? $offer['calculationInput'] : [];
            $inputPreset = is_array($input['preset'] ?? null) ? $input['preset'] : [];
            if ($offerId <= 0
                || (string)($input['contract'] ?? '') !== 'prospektweb.calc.input-context/v1'
                || !in_array((string)($input['source'] ?? ''), ['manual', 'catalog-input-mapping'], true)
                || !is_array($input['scenario'] ?? null)
                || !is_array($input['values'] ?? null)
                || (int)($inputPreset['id'] ?? 0) !== $presetId
                || (int)($inputPreset['revision'] ?? 0) !== (int)$publication['revision']
                || !hash_equals(
                    (string)$publication['compileHash'],
                    (string)($inputPreset['compileHash'] ?? '')
                )) {
                throw new \RuntimeException(
                    'Offer #' . $offerId . ' lacks exact neutral calculation input provenance.',
                    409
                );
            }
            $offerIds[] = $offerId;
        }
        sort($offerIds, SORT_NUMERIC);
        $launchOfferIds = array_values(array_map('intval', (array)($launch['offerIds'] ?? [])));
        sort($launchOfferIds, SORT_NUMERIC);
        if ($offerIds !== $launchOfferIds) {
            throw new \RuntimeException('calc-server payload launch context does not match its offers.', 409);
        }
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
