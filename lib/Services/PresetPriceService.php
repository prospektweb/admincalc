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
    private const PRICE_PROFILE_POLICY_PROPERTY = 'PRICE_PROFILE_POLICY_JSON';
    private const PRICE_PROFILE_POLICY_SCHEMA = 'prospektweb.calc.conditional-price-profiles/v1';
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
    public function changePricePreset(int $presetId, array $prices, ?array $priceProfilePolicy = null): array
    {
        try {
            if ($presetId <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'Не указан ID пресета',
                ];
            }

            $normalizedPolicy = $priceProfilePolicy === null ? null : $this->normalizePriceProfilePolicy($priceProfilePolicy);
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
            if ($normalizedPolicy !== null) {
                $this->savePriceProfilePolicy($presetId, $normalizedPolicy);
            }

            return [
                'status' => 'ok',
                'presetId' => $presetId,
                'priceProfilePolicy' => $normalizedPolicy,
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function normalizePriceProfilePolicy(?array $policy): array
    {
        $rules = [];
        $seenIds = [];
        $seenConditions = [];
        foreach ((array)($policy['rules'] ?? []) as $index => $rule) {
            if (!is_array($rule)) {
                throw new \RuntimeException('Некорректное правило условного профиля цен');
            }
            $id = trim((string)($rule['id'] ?? ''));
            $name = trim((string)($rule['name'] ?? ''));
            $condition = is_array($rule['condition'] ?? null) ? $rule['condition'] : [];
            $kind = (string)($condition['kind'] ?? '');
            $code = trim((string)($condition['code'] ?? ''));
            if ($id === '' || isset($seenIds[$id]) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id)) {
                throw new \RuntimeException('ID условного профиля цен должен быть уникальным');
            }
            if ($name === '' || mb_strlen($name) > 200) {
                throw new \RuntimeException('Укажите название условного профиля цен');
            }
            if (!in_array($kind, ['variable', 'constant'], true) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $code)) {
                throw new \RuntimeException('Выберите корректное глобальное логическое значение для профиля цен');
            }
            $conditionKey = $kind . ':' . $code;
            if (isset($seenConditions[$conditionKey])) {
                throw new \RuntimeException('Одно глобальное значение нельзя назначить нескольким профилям цен');
            }
            $prices = $this->normalizePolicyPrices((array)($rule['prices'] ?? []));
            if ($prices === []) {
                throw new \RuntimeException('Условный профиль цен не может быть пустым');
            }
            $mode = (string)($rule['mode'] ?? 'markup') === 'margin' ? 'margin' : 'markup';
            $normalized = [
                'id' => $id,
                'name' => $name,
                'condition' => ['kind' => $kind, 'code' => $code, 'equals' => true],
                'sourcePresetId' => trim((string)($rule['sourcePresetId'] ?? '')),
                'sourcePresetName' => trim((string)($rule['sourcePresetName'] ?? '')),
                'mode' => $mode,
                'prices' => $prices,
            ];
            $normalized['revision'] = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $rules[] = $normalized;
            $seenIds[$id] = true;
            $seenConditions[$conditionKey] = true;
            if (count($rules) > 50) {
                throw new \RuntimeException('Допускается не более 50 условных профилей цен');
            }
        }

        $normalizedPolicy = [
            '$schema' => self::PRICE_PROFILE_POLICY_SCHEMA,
            'version' => 1,
            'rules' => $rules,
        ];
        $normalizedPolicy['revision'] = hash('sha256', json_encode($normalizedPolicy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $normalizedPolicy;
    }

    private function normalizePolicyPrices(array $prices): array
    {
        $result = [];
        foreach ($prices as $range) {
            if (!is_array($range)) {
                continue;
            }
            $typeId = (int)($range['typeId'] ?? 0);
            $price = (float)($range['price'] ?? 0);
            $currency = strtoupper((string)($range['currency'] ?? ''));
            if ($typeId <= 0 || $price < 0 || !in_array($currency, ['RUB', 'PRC', 'MRG'], true)) {
                throw new \RuntimeException('Некорректное значение в условном профиле цен');
            }
            if ($currency === 'MRG' && $price >= 100) {
                throw new \RuntimeException('Маржа должна быть меньше 100%');
            }
            $result[] = [
                'typeId' => $typeId,
                'price' => $price,
                'currency' => $currency,
                'quantityFrom' => $this->normalizeQuantityBound($range['quantityFrom'] ?? null),
                'quantityTo' => $this->normalizeQuantityBound($range['quantityTo'] ?? null),
                'limitRub' => (float)($range['limitRub'] ?? 0) > 0 ? (float)$range['limitRub'] : null,
            ];
        }
        return $result;
    }

    private function savePriceProfilePolicy(int $presetId, array $policy): void
    {
        $json = json_encode($policy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Не удалось сериализовать условные профили цен');
        }
        \CIBlockElement::SetPropertyValuesEx($presetId, $this->presetsIblockId, [
            self::PRICE_PROFILE_POLICY_PROPERTY => ['VALUE' => ['TEXT' => $json, 'TYPE' => 'text']],
        ]);
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
