<?php

namespace Prospektweb\Frontcalc\Service;

use Bitrix\Main\Loader;

class PresetPriceCalculator
{
    public const PRC_CURRENCY = 'PRC';
    public const MISSING_RULE_WARNING = 'PRESET_PRICE_RULE_MISSING';
    public const CURRENCY_CONVERSION_FAILED_WARNING = 'PRESET_PRICE_CURRENCY_CONVERSION_FAILED';

    /** @var bool|null */
    private static ?bool $currencyModuleAvailable = null;

    /** @var array<int,string> */
    private array $warnings = [];

    /** @var array<int,array<string,mixed>> */
    private array $diagnostics = [];

    public static function setCurrencyModuleAvailable(?bool $available): void
    {
        self::$currencyModuleAvailable = $available;
    }

    /**
     * @param array<int,array<string,mixed>> $presetPrices
     * @param array<int,array<string,mixed>> $priceTypes
     * @return array<int,array{typeId:int,typeName:string,quantityFrom:int,quantityTo:int|null,price:float,currency:string,formatted:string}>
     */
    public function calculate($baseCost, string $baseCurrency, array $presetPrices, array $priceTypes): array
    {
        $this->warnings = [];
        $this->diagnostics = [];
        $baseCost = (float)$baseCost;
        $baseCurrency = $this->normalizeCurrencyCode($baseCurrency !== '' ? $baseCurrency : 'RUB');

        $types = $this->normalizePriceTypes($priceTypes);
        $rules = [];
        $ranges = [];

        foreach ($presetPrices as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $typeId = (int)($rule['typeId'] ?? $rule['type_id'] ?? 0);
            if ($typeId <= 0) {
                continue;
            }
            $from = $this->normalizeQuantityFrom($rule['quantityFrom'] ?? $rule['quantity_from'] ?? null);
            $to = $this->normalizeQuantityTo($rule['quantityTo'] ?? $rule['quantity_to'] ?? null);
            $rangeKey = $this->rangeKey($from, $to);
            $rule['typeId'] = $typeId;
            $rule['quantityFrom'] = $from;
            $rule['quantityTo'] = $to;
            $rules[$typeId][$rangeKey] = $rule;
            $ranges[$rangeKey] = ['from' => $from, 'to' => $to];
        }

        if (empty($ranges)) {
            $ranges[$this->rangeKey(0, null)] = ['from' => 0, 'to' => null];
        }
        uasort($ranges, static function (array $a, array $b): int {
            $cmp = ((int)$a['from']) <=> ((int)$b['from']);
            if ($cmp !== 0) {
                return $cmp;
            }
            if ($a['to'] === $b['to']) {
                return 0;
            }
            if ($a['to'] === null) {
                return 1;
            }
            if ($b['to'] === null) {
                return -1;
            }
            return ((int)$a['to']) <=> ((int)$b['to']);
        });

        $result = [];
        foreach ($ranges as $rangeKey => $range) {
            foreach ($types as $typeId => $typeName) {
                $rule = $rules[$typeId][$rangeKey] ?? null;
                if ($rule === null) {
                    $finalPrice = $baseCost;
                    $this->addDiagnostic(self::MISSING_RULE_WARNING, [
                        'typeId' => $typeId,
                        'quantityFrom' => (int)$range['from'],
                        'quantityTo' => $range['to'] === null ? null : (int)$range['to'],
                    ]);
                } else {
                    $finalPrice = $this->applyRule($baseCost, $baseCurrency, $rule);
                }
                $roundedPrice = $this->roundPrice($typeId, $finalPrice, $baseCurrency);
                $result[] = [
                    'typeId' => $typeId,
                    'typeName' => $typeName,
                    'quantityFrom' => (int)$range['from'],
                    'quantityTo' => $range['to'] === null ? null : (int)$range['to'],
                    'price' => $roundedPrice,
                    'currency' => $baseCurrency,
                    'formatted' => $this->formatCurrency($roundedPrice, $baseCurrency),
                ];
            }
        }

        return $result;
    }

    /** @return array<int,string> */
    public function getWarnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /** @return array<int,array<string,mixed>> */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    private function applyRule(float $baseCost, string $baseCurrency, array $rule): float
    {
        $markup = (float)($rule['price'] ?? 0);
        $currency = $this->normalizeCurrencyCode((string)($rule['currency'] ?? ''));
        if ($currency === self::PRC_CURRENCY) {
            return $baseCost * (1 + $markup / 100);
        }
        if ($currency !== '' && $currency !== $baseCurrency) {
            $converted = $this->convertCurrency($markup, $currency, $baseCurrency);
            if ($converted === null) {
                $this->addDiagnostic(self::CURRENCY_CONVERSION_FAILED_WARNING, [
                    'typeId' => (int)($rule['typeId'] ?? 0),
                    'quantityFrom' => (int)($rule['quantityFrom'] ?? 0),
                    'quantityTo' => array_key_exists('quantityTo', $rule) && $rule['quantityTo'] !== null ? (int)$rule['quantityTo'] : null,
                    'fromCurrency' => $currency,
                    'toCurrency' => $baseCurrency,
                ]);
                return $baseCost;
            }
            $markup = $converted;
        }
        return $baseCost + $markup;
    }

    private function convertCurrency(float $value, string $fromCurrency, string $toCurrency): ?float
    {
        if ($fromCurrency === $toCurrency || $fromCurrency === '' || $toCurrency === '') {
            return $value;
        }
        if (!$this->isCurrencyModuleAvailable() || !class_exists('\\CCurrencyRates')) {
            return null;
        }
        try {
            $converted = \CCurrencyRates::ConvertCurrency($value, $fromCurrency, $toCurrency);
        } catch (\Throwable $exception) {
            return null;
        }
        if ($converted === false || $converted === null || !is_numeric($converted)) {
            return null;
        }
        return (float)$converted;
    }

    private function isCurrencyModuleAvailable(): bool
    {
        if (self::$currencyModuleAvailable !== null) {
            return self::$currencyModuleAvailable;
        }
        if (class_exists('\\Bitrix\\Main\\Loader')) {
            self::$currencyModuleAvailable = Loader::includeModule('currency');
            return self::$currencyModuleAvailable;
        }
        self::$currencyModuleAvailable = false;
        return false;
    }

    private function roundPrice(int $typeId, float $value, string $currency): float
    {
        if (class_exists('\\Bitrix\\Catalog\\Product\\Price')) {
            return (float)\Bitrix\Catalog\Product\Price::roundPrice($typeId, $value, $currency);
        }
        return $value;
    }

    private function formatCurrency(float $value, string $currency): string
    {
        if (class_exists('\\CCurrencyLang')) {
            return html_entity_decode((string)\CCurrencyLang::CurrencyFormat($value, $currency, true), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return number_format($value, 2, '.', ' ') . ' ' . $currency;
    }

    /** @return array<int,string> */
    private function normalizePriceTypes(array $priceTypes): array
    {
        $types = [];
        foreach ($priceTypes as $type) {
            if (!is_array($type)) {
                continue;
            }
            $id = (int)($type['id'] ?? $type['ID'] ?? 0);
            if ($id > 0) {
                $types[$id] = (string)($type['name'] ?? $type['NAME_LANG'] ?? $type['NAME'] ?? ('PRICE_' . $id));
            }
        }
        return $types;
    }

    private function normalizeCurrencyCode(string $currency): string { return strtoupper(trim($currency)); }
    private function normalizeQuantityFrom($value): int { return ($value === null || $value === '') ? 0 : (int)$value; }
    private function normalizeQuantityTo($value): ?int { return ($value === null || $value === '') ? null : (int)$value; }
    private function rangeKey(int $from, ?int $to): string { return $from . ':' . ($to === null ? '*' : $to); }

    /** @param array<string,mixed> $payload */
    private function addDiagnostic(string $code, array $payload): void
    {
        $this->warnings[] = $code;
        $this->diagnostics[] = array_merge(['code' => $code], $payload);
    }
}
