<?php

namespace Prospektweb\Calc\Config;

use Bitrix\Main\Config\Option;

/**
 * Менеджер глобальных настроек калькулятора.
 */
class SettingsManager
{
    /** @var string ID модуля */
    protected const MODULE_ID = 'prospektweb.calc';

    /** @var ConfigManager */
    protected ConfigManager $configManager;

    public function __construct()
    {
        $this->configManager = new ConfigManager();
    }

    /**
     * Получает ID типа цены по умолчанию.
     *
     * @return int
     */
    public function getDefaultPriceTypeId(): int
    {
        return (int)$this->configManager->getOption('DEFAULT_PRICE_TYPE_ID', 1);
    }

    /**
     * Устанавливает ID типа цены по умолчанию.
     *
     * @param int $priceTypeId ID типа цены.
     */
    public function setDefaultPriceTypeId(int $priceTypeId): void
    {
        $this->configManager->setOption('DEFAULT_PRICE_TYPE_ID', $priceTypeId);
    }

    /**
     * Получает валюту по умолчанию.
     *
     * @return string
     */
    public function getDefaultCurrency(): string
    {
        return (string)$this->configManager->getOption('DEFAULT_CURRENCY', 'RUB');
    }

    /**
     * Устанавливает валюту по умолчанию.
     *
     * @param string $currency Код валюты.
     */
    public function setDefaultCurrency(string $currency): void
    {
        $this->configManager->setOption('DEFAULT_CURRENCY', $currency);
    }

    /**
     * Включено ли логирование.
     *
     * @return bool
     */
    public function isLoggingEnabled(): bool
    {
        return $this->configManager->getOption('LOGGING_ENABLED', 'N') === 'Y';
    }

    /**
     * Включает/выключает логирование.
     *
     * @param bool $enabled Включено ли.
     */
    public function setLoggingEnabled(bool $enabled): void
    {
        $this->configManager->setOption('LOGGING_ENABLED', $enabled ? 'Y' : 'N');
    }

    /**
     * Получает настройки округления цен.
     *
     * @return array
     */
    public function getPriceRoundingSettings(): array
    {
        $raw = $this->configManager->getOption('PRICE_ROUNDING', '');
        if (!$raw) {
            return [
                'enabled' => true,
                'precision' => 10, // округление до 10
                'method' => 'ceil', // ceil, floor, round
            ];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Устанавливает настройки округления цен.
     *
     * @param array $settings Настройки.
     */
    public function setPriceRoundingSettings(array $settings): void
    {
        $this->configManager->setOption('PRICE_ROUNDING', json_encode($settings));
    }

    /**
     * Получает настройки маркетинговых цен.
     *
     * @return array
     */
    public function getMarketingPriceSettings(): array
    {
        $raw = $this->configManager->getOption('MARKETING_PRICE', '');
        if (!$raw) {
            return [
                'enabled' => false,
                'suffix' => 90, // цены типа 990, 1990
            ];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Устанавливает настройки маркетинговых цен.
     *
     * @param array $settings Настройки.
     */
    public function setMarketingPriceSettings(array $settings): void
    {
        $this->configManager->setOption('MARKETING_PRICE', json_encode($settings));
    }

    /**
     * Получает все настройки для страницы опций.
     *
     * @return array
     */
    public function getAllSettings(): array
    {
        return [
            'priceTypeId' => $this->getDefaultPriceTypeId(),
            'currency' => $this->getDefaultCurrency(),
            'loggingEnabled' => $this->isLoggingEnabled(),
            'priceRounding' => $this->getPriceRoundingSettings(),
            'marketingPrice' => $this->getMarketingPriceSettings(),
        ];
    }

    /**
     * Сохраняет все настройки со страницы опций.
     *
     * @param array $settings Массив настроек.
     */
    public function saveAllSettings(array $settings): void
    {
        if (isset($settings['priceTypeId'])) {
            $this->setDefaultPriceTypeId((int)$settings['priceTypeId']);
        }

        if (isset($settings['currency'])) {
            $this->setDefaultCurrency((string)$settings['currency']);
        }

        if (isset($settings['loggingEnabled'])) {
            $this->setLoggingEnabled((bool)$settings['loggingEnabled']);
        }

        if (isset($settings['priceRounding']) && is_array($settings['priceRounding'])) {
            $this->setPriceRoundingSettings($settings['priceRounding']);
        }

        if (isset($settings['marketingPrice']) && is_array($settings['marketingPrice'])) {
            $this->setMarketingPriceSettings($settings['marketingPrice']);
        }
    }

    /**
     * Получить значение наценки по умолчанию
     *
     * @return int
     */
    public function getDefaultExtraValue(): int
    {
        $value = (int)$this->configManager->getOption('DEFAULT_EXTRA_VALUE', '10');
        return $value >= 0 ? $value : 10;
    }

    /**
     * Установить значение наценки по умолчанию
     *
     * @param int $value Значение наценки (только положительные и 0)
     */
    public function setDefaultExtraValue(int $value): void
    {
        $this->configManager->setOption('DEFAULT_EXTRA_VALUE', (string)max(0, $value));
    }

    /**
     * Получить валюту наценки по умолчанию
     *
     * @return string RUB или PRC
     */
    public function getDefaultExtraCurrency(): string
    {
        $value = $this->configManager->getOption('DEFAULT_EXTRA_CURRENCY_VALUE', 'PRC');
        return in_array($value, ['RUB', 'PRC']) ? $value : 'PRC';
    }

    /**
     * Установить валюту наценки по умолчанию
     *
     * @param string $currency Код валюты (RUB или PRC)
     */
    public function setDefaultExtraCurrency(string $currency): void
    {
        if (in_array($currency, ['RUB', 'PRC'])) {
            $this->configManager->setOption('DEFAULT_EXTRA_CURRENCY_VALUE', $currency);
        }
    }
}
