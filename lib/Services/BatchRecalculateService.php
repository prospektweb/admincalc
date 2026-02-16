<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Calculator\InitPayloadService;
use Prospektweb\Calc\Services\ResultWriter;

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
    private ResultWriter $resultWriter;

    /**
     * @param string $calcServerUrl URL сервера расчётов
     * @param int $timeout Таймаут запроса в секундах
     */
    public function __construct(string $calcServerUrl, int $timeout = 30)
    {
        $this->calcServerUrl = rtrim($calcServerUrl, '/');
        $this->timeout = $timeout;
        $this->configManager = new ConfigManager();
        $this->initPayloadService = new InitPayloadService();
        $this->resultWriter = new ResultWriter();
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

        $offerIds = [];
        
        // Ищем ТП, которые ссылаются на этот пресет через свойство CALC_PRESET
        $rsOffers = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => $skuIblockId,
                'ACTIVE' => 'Y',
                'PROPERTY_CALC_PRESET' => $presetId,
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
                try {
                    // Собираем initPayload для этого ТП
                    $siteId = defined('SITE_ID') ? SITE_ID : 's1';
                    $initPayload = $this->initPayloadService->prepareInitPayload([$offerId], $siteId);
                    
                    // Вычисляем хеш текущего состояния
                    $currentHash = $this->computeStateHash($initPayload);
                    
                    // Проверяем, изменились ли данные
                    if ($onlyChanged) {
                        $savedHash = $this->getSavedHash($offerId);
                        if ($savedHash !== null && $savedHash === $currentHash) {
                            $presetStats['skipped']++;
                            $summary['skipped']++;
                            continue;
                        }
                    }
                    
                    // Отправляем запрос на calc-server
                    $calcResult = $this->callCalcServer($initPayload);
                    
                    // TODO: Реализовать запись результатов через SaveHandler или ResultWriter
                    // Результат от calc-server содержит массив CalculationOfferResult[]
                    // Необходимо сохранить цены и другие рассчитанные данные в базу
                    // Пример структуры calcResult:
                    // [
                    //   'offers' => [
                    //     ['offerId' => 123, 'price' => 1000, 'priceRanges' => [...], ...]
                    //   ]
                    // ]
                    // Для полной интеграции требуется:
                    // 1. Разобрать структуру ответа от calc-server
                    // 2. Извлечь цены для каждого offer
                    // 3. Записать через ResultWriter или CatalogPriceService
                    
                    // Сохраняем новый хеш
                    $this->saveHash($offerId, $currentHash);
                    
                    $presetStats['recalculated']++;
                    $summary['recalculated']++;
                    
                } catch (\Exception $e) {
                    $presetStats['errors'][] = $e->getMessage();
                    $errors[] = [
                        'presetId' => $presetId,
                        'offerId' => $offerId,
                        'error' => $e->getMessage(),
                    ];
                    $summary['errors']++;
                }
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
        $url = $this->calcServerUrl . '/calculate';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($initPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("calc-server connection error: {$error}");
        }
        
        if ($httpCode !== 200) {
            throw new \Exception("calc-server returned HTTP {$httpCode}");
        }
        
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("calc-server returned invalid JSON");
        }
        
        return $result;
    }

    /**
     * Вычислить хеш состояния для набора offer IDs
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
        $count = \CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $skuIblockId,
                'ACTIVE' => 'Y',
                'PROPERTY_CALC_PRESET' => $presetId,
            ],
            []
        );

        return (int)$count;
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
}
