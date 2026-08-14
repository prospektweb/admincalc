<?php

namespace Prospektweb\Calc\Services;

/**
 * Applies the same Bitrix-side preset pricing used by the public FrontCalc.
 */
final class StandaloneCatalogPriceNormalizer
{
    /**
     * @param array<int,array<string,mixed>> $offerResults
     * @param array<int,array<string,mixed>> $priceTypes
     * @param array<int,array<string,mixed>> $fallbackRules
     * @return array<int,array<string,mixed>>
     */
    public function normalize(array $offerResults, array $priceTypes, array $fallbackRules = []): array
    {
        if (!class_exists(\Prospektweb\Frontcalc\Service\PresetPriceCalculator::class)) {
            throw new \RuntimeException('Не подключён единый расчёт отпускных цен FrontCalc.');
        }
        if ($priceTypes === []) {
            throw new \RuntimeException('Для автономной записи не настроены типы цен каталога.');
        }

        foreach ($offerResults as &$offerResult) {
            if (!is_array($offerResult)) {
                throw new \RuntimeException('calc-server вернул некорректный результат автономного расчёта.');
            }
            $purchasePrice = $this->number(
                $offerResult['purchasePrice'] ?? $offerResult['purchase_price'] ?? null
            );
            if ($purchasePrice === null || $purchasePrice <= 0) {
                throw new \RuntimeException('Автономный расчёт не вернул положительную производственную цену.');
            }
            $currency = trim((string)($offerResult['currency'] ?? 'RUB')) ?: 'RUB';
            $rules = $offerResult['appliedPriceRules'] ?? $offerResult['applied_price_rules'] ?? $fallbackRules;
            if (!is_array($rules) || $rules === []) {
                throw new \RuntimeException('Автономный расчёт не вернул правила отпускных цен.');
            }

            $calculator = new \Prospektweb\Frontcalc\Service\PresetPriceCalculator();
            $canonicalRanges = $calculator->calculate($purchasePrice, $currency, $rules, $priceTypes);
            $warnings = $calculator->getWarnings();
            if ($warnings !== []) {
                throw new \RuntimeException(
                    'Отпускные цены рассчитаны с предупреждениями: ' . implode(', ', $warnings)
                );
            }

            $grouped = [];
            foreach ($canonicalRanges as $range) {
                $typeId = (int)($range['typeId'] ?? 0);
                $price = $this->number($range['price'] ?? null);
                if ($typeId <= 0 || $price === null || $price <= 0) {
                    throw new \RuntimeException('FrontCalc вернул некорректную отпускную цену.');
                }
                $quantityFrom = $this->quantity($range['quantityFrom'] ?? null);
                $quantityTo = $this->quantity($range['quantityTo'] ?? null);
                $key = ($quantityFrom === null ? 'n' : (string)$quantityFrom)
                    . ':' . ($quantityTo === null ? 'n' : (string)$quantityTo);
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'quantityFrom' => $quantityFrom,
                        'quantityTo' => $quantityTo,
                        'prices' => [],
                    ];
                }
                $grouped[$key]['prices'][] = [
                    'typeId' => $typeId,
                    'basePrice' => $price,
                    'currency' => (string)($range['currency'] ?? $currency),
                ];
            }
            if ($grouped === []) {
                throw new \RuntimeException('FrontCalc не сформировал отпускные цены для записи.');
            }
            $offerResult['priceRangesWithMarkup'] = array_values($grouped);
        }
        unset($offerResult);

        return $offerResults;
    }

    /** @param mixed $value */
    private function number($value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    /** @param mixed $value */
    private function quantity($value): ?int
    {
        return ($value === null || $value === '') ? null : (is_numeric($value) ? (int)$value : null);
    }
}
