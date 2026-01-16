<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Config\SettingsManager;
use Prospektweb\Calc\Services\CatalogPriceService;

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

            $catalogPriceService = new CatalogPriceService();

            // 1. Очистить ВСЕ текущие цены пресета
            $catalogPriceService->deleteAllPrices($presetId);

            // 2. Преобразовать payload в структуру [typeId => [ranges]]
            $pricesByType = [];
            
            foreach ($prices as $range) {
                $typeId = (int)($range['typeId'] ?? 0);
                
                if ($typeId <= 0) {
                    continue;
                }

                if (!isset($pricesByType[$typeId])) {
                    $pricesByType[$typeId] = [];
                }

                $pricesByType[$typeId][] = [
                    'price' => isset($range['price']) ? (float)$range['price'] : 0,
                    'currency' => $range['currency'] ?? 'PRC',
                    'quantityFrom' => isset($range['quantityFrom']) ? (int)$range['quantityFrom'] : null,
                    'quantityTo' => isset($range['quantityTo']) ? (int)$range['quantityTo'] : null,
                ];
            }

            // 3. Записать новые цены
            $catalogPriceService->writePriceRangesMultiType($presetId, $pricesByType);

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

}
