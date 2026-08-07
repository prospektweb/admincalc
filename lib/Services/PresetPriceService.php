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
    private const PRICE_LIMITS_PROPERTY = 'PRICE_LIMITS_JSON';
    private const PRICE_LIMITS_SCHEMA = 'prospektweb.calc.price-limits/v1';
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

            // Преобразовать payload в структуру [typeId => [ranges]]
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
                    'currency' => in_array(($range['currency'] ?? ''), ['RUB', 'PRC', 'MRG'], true)
                        ? $range['currency']
                        : 'PRC',
                    'quantityFrom' => isset($range['quantityFrom']) ? (int)$range['quantityFrom'] : null,
                    'quantityTo' => isset($range['quantityTo']) ? (int)$range['quantityTo'] : null,
                ];
            }

            if (!$catalogPriceService->syncPriceRangesMultiType($presetId, $pricesByType)) {
                throw new \RuntimeException('Не удалось синхронизировать диапазоны отпускных цен');
            }

            $this->savePriceLimits($presetId, $prices);

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

    private function savePriceLimits(int $presetId, array $prices): void
    {
        $limits = [];
        foreach ($prices as $range) {
            $typeId = (int)($range['typeId'] ?? 0);
            $limitRub = isset($range['limitRub']) ? max(0.0, (float)$range['limitRub']) : 0.0;
            if ($typeId <= 0 || $limitRub <= 0) {
                continue;
            }
            $limits[] = [
                'typeId' => $typeId,
                'quantityFrom' => $this->normalizeQuantityBound($range['quantityFrom'] ?? null),
                'quantityTo' => $this->normalizeQuantityBound($range['quantityTo'] ?? null),
                'limitRub' => $limitRub,
            ];
        }

        $json = json_encode([
            '$schema' => self::PRICE_LIMITS_SCHEMA,
            'version' => 1,
            'limits' => $limits,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Не удалось сериализовать ограничители отпускных цен');
        }

        \CIBlockElement::SetPropertyValuesEx($presetId, $this->presetsIblockId, [
            self::PRICE_LIMITS_PROPERTY => ['VALUE' => ['TEXT' => $json, 'TYPE' => 'text']],
        ]);
    }

    private function normalizeQuantityBound($value): ?int
    {
        if ($value === false || $value === null || $value === '' || (string)$value === '0') {
            return null;
        }
        return (int)$value;
    }

}
