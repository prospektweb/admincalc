<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Loader;
use Bitrix\Highloadblock\HighloadBlockTable;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Обработчик сохранения истории расчётов
 */
class CalculationHistoryHandler
{
    private const MODULE_ID = 'prospektweb.calc';
    private const LOG_FILE = '/local/logs/prospektweb.calc.calculation_history.log';
    
    private ConfigManager $configManager;
    private bool $supportsXmlId = false;
    private bool $supportsName = false;
    
    public function __construct()
    {
        if (!Loader::includeModule('highloadblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix highloadblock');
        }
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix iblock');
        }
        $this->configManager = new ConfigManager();
    }
    
    /**
     * Обработать запрос на сохранение расчётов
     * 
     * @param array $payload Массив с ключом 'offers', содержащий массив объектов с offerId и json
     * @return array Результат выполнения
     * @throws \Exception
     */
    public function handle(array $payload): array
    {
        global $USER;
        
        $userId = $USER ? (int)$USER->GetID() : 0;
        
        if ($userId <= 0) {
            return [
                'status' => 'error',
                'message' => 'Пользователь не авторизован',
                'results' => [],
                'total' => 0,
                'saved' => 0,
            ];
        }
        
        $offers = $this->normalizeOffers($payload);

        if (empty($offers)) {
            return [
                'status' => 'error',
                'message' => 'Пустой payload: ожидается непустой массив offers или results',
                'results' => [],
                'total' => 0,
                'saved' => 0,
            ];
        }
        
        // Получаем ID HighloadBlock
        $hlblockId = (int)Option::get(self::MODULE_ID, 'HIGHLOAD_CALC_HISTORY_ID', 0);
        
        if ($hlblockId <= 0) {
            throw new \Exception('HighloadBlock для истории расчётов не создан');
        }
        
        // Получаем entity class
        $hlblock = HighloadBlockTable::getById($hlblockId)->fetch();
        
        if (!$hlblock) {
            throw new \Exception('HighloadBlock не найден');
        }
        
        $entity = HighloadBlockTable::compileEntity($hlblock);
        $entityClass = $entity->getDataClass();
        $fields = $entity->getFields();
        $this->supportsXmlId = isset($fields['UF_XML_ID']);
        $this->supportsName = isset($fields['UF_NAME']);
        
        // Получаем лимит истории
        $historyLimit = (int)Option::get(self::MODULE_ID, 'CALC_HISTORY_LIMIT', 10);
        
        // Получаем ID инфоблока ТП
        $skuIblockId = $this->configManager->getSkuIblockId();
        
        if ($skuIblockId <= 0) {
            throw new \Exception('ID инфоблока ТП не настроен');
        }
        
        $results = [];
        $savedCount = 0;
        
        foreach ($offers as $offerData) {
            $validation = $this->validateOfferPayload($offerData);
            $offerId = $validation['offerId'];

            if (!$validation['isValid']) {
                $reason = implode('; ', $validation['errors']);
                $this->logOfferRejection($offerId, $reason, $offerData);
                $results[] = [
                    'offerId' => $offerId,
                    'historyId' => null,
                    'status' => 'error',
                    'message' => $reason,
                ];
                continue;
            }

            $json = $validation['json'];
            
            // Конвертируем в JSON-строку, если передан массив
            $jsonString = is_string($json) ? $json : json_encode($json, JSON_UNESCAPED_UNICODE);

            if (!is_string($jsonString) || $jsonString === '') {
                $message = 'Не удалось подготовить расчётные данные для сохранения';
                $this->logOfferRejection($offerId, $message, $offerData);
                $results[] = [
                    'offerId' => $offerId,
                    'historyId' => null,
                    'status' => 'error',
                    'message' => $message,
                ];
                continue;
            }
            
            try {
                // Проверяем количество существующих записей
                $existingCount = $entityClass::getCount([
                    'filter' => ['UF_OFFER_ID' => $offerId],
                ]);
                
                // Если количество >= лимита, удаляем самую старую
                if ($existingCount >= $historyLimit) {
                    $oldest = $entityClass::getList([
                        'filter' => ['UF_OFFER_ID' => $offerId],
                        'order' => ['UF_DATETIME' => 'ASC'],
                        'limit' => 1,
                        'select' => ['ID'],
                    ])->fetch();
                    
                    if ($oldest) {
                        $entityClass::delete($oldest['ID']);
                    }
                }
                
                // Добавляем новую запись
                $historyXmlId = $this->supportsXmlId ? $this->buildHistoryXmlId($offerId) : null;
                $addData = [
                    'UF_DATETIME' => new \Bitrix\Main\Type\DateTime(),
                    'UF_USER_ID' => $userId,
                    'UF_OFFER_ID' => $offerId,
                    'UF_JSON' => $jsonString,
                ];

                if ($this->supportsXmlId && $historyXmlId !== null) {
                    $addData['UF_XML_ID'] = $historyXmlId;
                }

                if ($this->supportsName) {
                    $addData['UF_NAME'] = 'Расчёт ТП #' . $offerId;
                }

                $addResult = $entityClass::add($addData);
                
                if ($addResult->isSuccess()) {
                    $historyId = $addResult->getId();
                    
                    // Обновляем свойство COMPLETED_CALCS в инфоблоке ТП
                    $this->updateCompletedCalcs($offerId, $historyId, $historyXmlId, $skuIblockId);
                    
                    $results[] = [
                        'offerId' => $offerId,
                        'historyId' => $historyId,
                        'historyXmlId' => $historyXmlId,
                        'status' => 'ok',
                    ];
                    $savedCount++;
                } else {
                    $errorMessage = implode(', ', $addResult->getErrorMessages());
                    $this->logOfferRejection($offerId, $errorMessage, $offerData);
                    $results[] = [
                        'offerId' => $offerId,
                        'historyId' => null,
                        'status' => 'error',
                        'message' => $errorMessage,
                    ];
                }
            } catch (\Exception $e) {
                $this->logOfferRejection($offerId, $e->getMessage(), $offerData);
                $results[] = [
                    'offerId' => $offerId,
                    'historyId' => null,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }
        
        return [
            'status' => $savedCount > 0 ? 'ok' : 'error',
            'results' => $results,
            'total' => count($offers),
            'saved' => $savedCount,
        ];
    }

    /**
     * Нормализует входной payload к единому формату offers.
     */
    private function normalizeOffers(array $payload): array
    {
        if (isset($payload['offers']) && is_array($payload['offers'])) {
            return $payload['offers'];
        }

        if (!isset($payload['results']) || !is_array($payload['results'])) {
            return [];
        }

        $offers = [];
        $timestamp = $payload['timestamp'] ?? null;

        foreach ($payload['results'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ($timestamp !== null && !array_key_exists('timestamp', $item)) {
                $item['timestamp'] = $timestamp;
            }

            $offers[] = [
                'offerId' => (int)($item['offerId'] ?? $item['offerID'] ?? $item['id'] ?? 0),
                'json' => array_key_exists('json', $item) ? $item['json'] : $item,
            ];
        }

        return $offers;
    }

    private function buildHistoryXmlId(int $offerId): string
    {
        return sprintf('calc_%d_%s', $offerId, bin2hex(random_bytes(6)));
    }

    /**
     * Валидирует расчётные данные по конкретному offer.
     */
    private function validateOfferPayload($offerData): array
    {
        $errors = [];

        if (!is_array($offerData)) {
            return [
                'offerId' => 0,
                'json' => null,
                'isValid' => false,
                'errors' => ['Некорректный формат offer: ожидается объект'],
            ];
        }

        $offerId = (int)($offerData['offerId'] ?? 0);
        $json = $offerData['json'] ?? null;

        if ($offerId <= 0) {
            $errors[] = 'Некорректный offerId';
        }

        if ($json === null) {
            $errors[] = 'Отсутствует обязательное поле json';
        } elseif (is_array($json) && empty($json)) {
            $errors[] = 'Пустые расчётные данные: json не должен быть пустым объектом';
        } elseif (is_string($json)) {
            $decoded = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'Поле json должно содержать валидный JSON';
            } elseif (is_array($decoded) && empty($decoded)) {
                $errors[] = 'Пустые расчётные данные: json не должен быть пустым объектом';
            }
        }

        return [
            'offerId' => $offerId,
            'json' => $json,
            'isValid' => empty($errors),
            'errors' => $errors,
        ];
    }

    private function logOfferRejection(int $offerId, string $reason, array $offerData = []): void
    {
        Debug::writeToFile([
            'offerId' => $offerId,
            'reason' => $reason,
            'offerData' => $offerData,
        ], 'CalculationHistoryHandler rejection', self::LOG_FILE);
    }
    
    /**
     * Обновить свойство COMPLETED_CALCS в элементе инфоблока ТП
     * 
     * @param int $offerId ID торгового предложения
     * @param int $historyId ID записи истории
     * @param int $skuIblockId ID инфоблока ТП
     */
    private function updateCompletedCalcs(int $offerId, int $historyId, ?string $historyXmlId, int $skuIblockId): void
    {
        $linkValue = $historyXmlId ?: (string)$historyId;

        // Получаем текущие значения свойства напрямую
        $existingValues = [];
        
        $rsProperty = \CIBlockElement::GetProperty(
            $skuIblockId,
            $offerId,
            [],
            ['CODE' => 'COMPLETED_CALCS']
        );
        
        while ($property = $rsProperty->Fetch()) {
            if (!empty($property['VALUE'])) {
                $existingValues[] = $property['VALUE'];
            }
        }

        // Добавляем новую ссылку (UF_XML_ID для directory, fallback на ID)
        if (!in_array($linkValue, $existingValues, true)) {
            $existingValues[] = $linkValue;
        }
        
        // Обновляем свойство
        \CIBlockElement::SetPropertyValuesEx($offerId, $skuIblockId, [
            'COMPLETED_CALCS' => $existingValues,
        ]);
    }
}
