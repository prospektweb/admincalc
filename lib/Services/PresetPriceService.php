<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Config\SettingsManager;

/**
 * Сервис управления ценами пресета
 */
class PresetPriceService
{
    private int $presetsIblockId;
    private ConfigManager $configManager;
    private SettingsManager $settingsManager;

    public function __construct()
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix iblock');
        }

        if (!Loader::includeModule('catalog')) {
            throw new \RuntimeException('Требуется модуль Bitrix catalog');
        }

        $this->configManager = new ConfigManager();
        $this->settingsManager = new SettingsManager();
        $this->presetsIblockId = $this->configManager->getIblockId('CALC_PRESETS');
    }



    /**
     * Обработка изменения диапазонов цен (CHANGE_PRICE_PRESET_REQUEST)
     *
     * @param int $presetId ID пресета
     * @param array $prices Массив диапазонов цен
     * @return array Результат операции
     */
    public function changePricePreset(int $presetId, array $prices): array
    {
        try {
            if ($presetId <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'Не указан ID пресета',
                ];
            }

            // 1. Очистить все текущие цены пресета
            $this->clearPresetPrices($presetId);

            // 2. Преобразовать payload в структуру цен пресета и записать новые цены
            $pricesData = [];
            
            foreach ($prices as $range) {
                $typeId = (int)($range['typeId'] ?? 0);
                
                if ($typeId <= 0) {
                    continue;
                }

                if (!isset($pricesData[$typeId])) {
                    $pricesData[$typeId] = [];
                }

                $pricesData[$typeId][] = [
                    'price' => isset($range['price']) ? (float)$range['price'] : 0,
                    'currency' => $range['currency'] ?? 'PRC',
                    'quantityFrom' => isset($range['quantityFrom']) ? (int)$range['quantityFrom'] : 0,
                    'quantityTo' => isset($range['quantityTo']) ? (int)$range['quantityTo'] : null,
                ];
            }

            // 3. Сохранить новые цены
            $this->savePresetPrices($presetId, $pricesData);

            return [
                'status' => 'ok',
                'presetId' => $presetId,
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить цены пресета
     *
     * @param int $presetId ID пресета
     * @return array Массив цен по типам [typeId => [диапазоны]]
     */
    private function getPresetPrices(int $presetId): array
    {
        if ($presetId <= 0 || $this->presetsIblockId <= 0) {
            return [];
        }

        // Получаем свойство PRICES пресета
        $rsProperty = \CIBlockElement::GetProperty(
            $this->presetsIblockId,
            $presetId,
            [],
            ['CODE' => 'PRICES']
        );

        if ($prop = $rsProperty->Fetch()) {
            $value = $prop['VALUE'] ?? '';
            
            if (!empty($value)) {
                $decoded = json_decode($value, true);
                
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    /**
     * Очистить цены пресета
     *
     * @param int $presetId ID пресета
     */
    private function clearPresetPrices(int $presetId): void
    {
        if ($presetId <= 0 || $this->presetsIblockId <= 0) {
            return;
        }

        // Очистить свойство PRICES у пресета
        // Используем паттерн: сначала false, потом новые данные
        \CIBlockElement::SetPropertyValuesEx($presetId, $this->presetsIblockId, [
            'PRICES' => false,
        ]);
    }

    /**
     * Сохранить цены пресета
     *
     * @param int $presetId ID пресета
     * @param array $prices Массив цен по типам
     */
    private function savePresetPrices(int $presetId, array $prices): void
    {
        if ($presetId <= 0 || $this->presetsIblockId <= 0) {
            return;
        }

        $encoded = json_encode($prices, JSON_UNESCAPED_UNICODE);

        \CIBlockElement::SetPropertyValuesEx($presetId, $this->presetsIblockId, [
            'PRICES' => $encoded,
        ]);
    }

    /**
     * Получить ID базового типа цены
     *
     * @return int
     */
    private function getBasePriceGroupId(): int
    {
        try {
            $baseGroup = \CCatalogGroup::GetBaseGroup();
            
            if ($baseGroup && isset($baseGroup['ID'])) {
                return (int)$baseGroup['ID'];
            }

            // Если не найден базовый, берем первый доступный
            $result = \CCatalogGroup::GetListArray();
            
            if (is_array($result) && !empty($result)) {
                return (int)$result[0]['ID'];
            }

        } catch (\Exception $e) {
            // Fallback на ID = 1
        }

        return 1;
    }
}
