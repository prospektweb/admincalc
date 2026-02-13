<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Highloadblock\HighloadBlockTable;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Обработчик сохранения истории расчётов
 */
class CalculationHistoryHandler
{
    private const MODULE_ID = 'prospektweb.calc';
    
    private ConfigManager $configManager;
    
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
        
        $offers = $payload['offers'] ?? [];
        
        if (!is_array($offers) || empty($offers)) {
            return [
                'status' => 'error',
                'message' => 'Некорректный payload: offers должен быть непустым массивом',
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
            $offerId = (int)($offerData['offerId'] ?? 0);
            $json = $offerData['json'] ?? null;
            
            if ($offerId <= 0) {
                $results[] = [
                    'offerId' => $offerId,
                    'historyId' => null,
                    'status' => 'error',
                    'message' => 'Некорректный offerId',
                ];
                continue;
            }
            
            if ($json === null) {
                $results[] = [
                    'offerId' => $offerId,
                    'historyId' => null,
                    'status' => 'error',
                    'message' => 'Отсутствует json',
                ];
                continue;
            }
            
            // Конвертируем в JSON-строку, если передан массив
            $jsonString = is_string($json) ? $json : json_encode($json, JSON_UNESCAPED_UNICODE);
            
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
                $addResult = $entityClass::add([
                    'UF_DATETIME' => new \Bitrix\Main\Type\DateTime(),
                    'UF_USER_ID' => $userId,
                    'UF_OFFER_ID' => $offerId,
                    'UF_JSON' => $jsonString,
                ]);
                
                if ($addResult->isSuccess()) {
                    $historyId = $addResult->getId();
                    
                    // Обновляем свойство COMPLETED_CALCS в инфоблоке ТП
                    $this->updateCompletedCalcs($offerId, $historyId, $skuIblockId);
                    
                    $results[] = [
                        'offerId' => $offerId,
                        'historyId' => $historyId,
                        'status' => 'ok',
                    ];
                    $savedCount++;
                } else {
                    $results[] = [
                        'offerId' => $offerId,
                        'historyId' => null,
                        'status' => 'error',
                        'message' => implode(', ', $addResult->getErrorMessages()),
                    ];
                }
            } catch (\Exception $e) {
                $results[] = [
                    'offerId' => $offerId,
                    'historyId' => null,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }
        
        return [
            'status' => 'ok',
            'results' => $results,
            'total' => count($offers),
            'saved' => $savedCount,
        ];
    }
    
    /**
     * Обновить свойство COMPLETED_CALCS в элементе инфоблока ТП
     * 
     * @param int $offerId ID торгового предложения
     * @param int $historyId ID записи истории
     * @param int $skuIblockId ID инфоблока ТП
     */
    private function updateCompletedCalcs(int $offerId, int $historyId, int $skuIblockId): void
    {
        // Получаем текущие значения свойства
        $rsElement = \CIBlockElement::GetList(
            [],
            ['ID' => $offerId, 'IBLOCK_ID' => $skuIblockId],
            false,
            false,
            ['ID', 'PROPERTY_COMPLETED_CALCS']
        );
        
        $existingValues = [];
        while ($element = $rsElement->Fetch()) {
            // Получаем все значения множественного свойства
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
        }
        
        // Добавляем новый ID
        $existingValues[] = $historyId;
        
        // Обновляем свойство
        \CIBlockElement::SetPropertyValuesEx($offerId, $skuIblockId, [
            'COMPLETED_CALCS' => $existingValues,
        ]);
    }
}
