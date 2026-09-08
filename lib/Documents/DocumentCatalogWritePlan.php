<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/DocumentOutputMappings.php';
require_once __DIR__ . '/SiteConnection.php';

/** Pure projection of a server quote. No preset IDs, options, SQL or CMS writes. */
final class DocumentCatalogWritePlan
{
    /** The published seven sinks deliberately exclude names and arbitrary iblock properties. */
    public static function target(array $quote, object $connection, array $current): array
    {
        DocumentOutputMappings::validate($connection->outputMappings ?? null);
        if (count($connection->outputMappings) !== 7) { throw new \InvalidArgumentException('Запись результатов не настроена в опубликованной версии.'); }
        $current = self::state($current);
        $currency = self::currency($quote['currency'] ?? null);
        $bindings = [];
        foreach ($connection->priceTypes ?? [] as $binding) {
            if (!$binding instanceof \stdClass || !is_string($binding->typeId ?? null)
                || !is_string($binding->key ?? null) || preg_match('/^[1-9][0-9]{0,8}$/D', $binding->key) !== 1
                || isset($bindings[$binding->typeId]) || in_array((int)$binding->key, $bindings, true)) {
                throw new \InvalidArgumentException('Некорректная привязка типа цены.');
            }
            $bindings[$binding->typeId] = (int)$binding->key;
        }
        if (!$bindings) { throw new \InvalidArgumentException('Не заданы типы цен записи.'); }
        $ranges = $quote['priceRanges'] ?? null;
        if (!is_array($ranges) || !array_is_list($ranges) || !$ranges || count($ranges) > 5000) {
            throw new \InvalidArgumentException('Сервер не вернул диапазоны цен.');
        }
        $prices = []; $ends = [];
        foreach ($ranges as $range) {
            if (!is_array($range) || !array_key_exists('quantityFrom', $range) || !array_key_exists('quantityTo', $range)
                || !is_array($range['prices'] ?? null) || !array_is_list($range['prices'])) {
                throw new \InvalidArgumentException('Некорректный диапазон цен сервера.');
            }
            $from = self::quantity($range['quantityFrom'] ?? null); $to = self::quantity($range['quantityTo'] ?? null);
            if ($to !== null && $to < ($from ?? 0)) { throw new \InvalidArgumentException('Некорректные границы диапазона.'); }
            $seen = [];
            foreach ($range['prices'] as $price) {
                if (!is_array($price) || !is_string($price['typeId'] ?? null) || !isset($bindings[$price['typeId']])
                    || isset($seen[$price['typeId']]) || self::currency($price['currency'] ?? null) !== $currency) {
                    throw new \InvalidArgumentException('Сервер вернул посторонний, повторный тип цены или другую валюту.');
                }
                $type = $bindings[$price['typeId']]; $seen[$price['typeId']] = true;
                if (isset($ends[$type]) && ($from ?? 0) <= $ends[$type]) { throw new \InvalidArgumentException('Диапазоны цен пересекаются или не упорядочены.'); }
                $ends[$type] = $to ?? PHP_INT_MAX;
                $prices[] = ['typeId' => $type, 'quantityFrom' => $from, 'quantityTo' => $to,
                    'price' => self::number($price['basePrice'] ?? null, true, true), 'currency' => $currency];
            }
            if (count($seen) !== count($bindings)) { throw new \InvalidArgumentException('Не все типы цен рассчитаны в диапазоне.'); }
        }
        // Unowned price types are outside this command's write scope and survive unchanged.
        foreach ($current['prices'] as $price) if (!in_array($price['typeId'], $bindings, true)) $prices[] = $price;
        $dimensions = [];
        foreach (['width', 'length', 'height', 'weight'] as $key) {
            $dimensions[$key] = self::number($quote['parts'][0]['outputs'][$key] ?? null, true, false);
        }
        // Matches the existing site adapter: basePrice is catalog purchase cost;
        // raw purchasingPrice is direct technical cost, a different quantity.
        return self::state(['purchasingPrice' => ['value' => self::number($quote['basePrice'] ?? null, true, true), 'currency' => $currency],
            'dimensions' => $dimensions, 'prices' => $prices]);
    }

    public static function state(array $state): array
    {
        self::keys($state, ['purchasingPrice', 'dimensions', 'prices']);
        if (!is_array($state['purchasingPrice']) || !is_array($state['dimensions']) || !is_array($state['prices']) || !array_is_list($state['prices'])) {
            throw new \InvalidArgumentException('Некорректный снимок каталога.');
        }
        self::keys($state['purchasingPrice'], ['value', 'currency']);
        self::keys($state['dimensions'], ['width', 'length', 'height', 'weight']);
        $dimensions = [];
        foreach (['width', 'length', 'height', 'weight'] as $key) $dimensions[$key] = self::number($state['dimensions'][$key], false, false);
        $prices = []; $seen = [];
        if (count($state['prices']) > 10000) { throw new \InvalidArgumentException('Слишком много строк цен.'); }
        foreach ($state['prices'] as $price) {
            if (!is_array($price)) { throw new \InvalidArgumentException('Некорректная строка цены.'); }
            self::keys($price, ['typeId', 'quantityFrom', 'quantityTo', 'price', 'currency']);
            if (!is_int($price['typeId']) || $price['typeId'] <= 0 || $price['typeId'] > 999999999) { throw new \InvalidArgumentException('Некорректный тип цены.'); }
            $row = ['typeId' => $price['typeId'], 'quantityFrom' => self::quantity($price['quantityFrom']), 'quantityTo' => self::quantity($price['quantityTo']),
                'price' => self::number($price['price'], false, true), 'currency' => self::currency($price['currency'])];
            $key = self::priceKey($row);
            if (isset($seen[$key]) || ($row['quantityTo'] !== null && $row['quantityTo'] < ($row['quantityFrom'] ?? 0))) { throw new \InvalidArgumentException('Повторный или некорректный диапазон каталога.'); }
            $seen[$key] = true; $prices[] = $row;
        }
        usort($prices, static fn(array $a, array $b): int => strcmp(self::priceKey($a), self::priceKey($b)));
        return ['purchasingPrice' => ['value' => self::number($state['purchasingPrice']['value'], false, true),
            'currency' => $state['purchasingPrice']['currency'] === null ? null : self::currency($state['purchasingPrice']['currency'])],
            'dimensions' => $dimensions, 'prices' => $prices];
    }

    public static function diffs(array $old, array $new): array
    {
        $old = self::state($old); $new = self::state($new); $diff = [];
        foreach (['purchasingPrice', 'dimensions.width', 'dimensions.length', 'dimensions.height', 'dimensions.weight', 'prices'] as $path) {
            $keys = explode('.', $path); $before = $old[$keys[0]]; $after = $new[$keys[0]];
            if (count($keys) === 2) { $before = $before[$keys[1]]; $after = $after[$keys[1]]; }
            $diff[] = ['path' => $path, 'old' => $before, 'new' => $after, 'changed' => self::hash($before) !== self::hash($after)];
        }
        return $diff;
    }

    public static function canonical($value): string
    {
        return SiteConnection::encode(json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR));
    }
    public static function hash($value): string { return hash('sha256', self::canonical($value)); }
    private static function keys(array $value, array $keys): void
    {
        $actual = array_keys($value); sort($actual); sort($keys);
        if ($actual !== $keys) { throw new \InvalidArgumentException('Неизвестные или отсутствующие поля снимка записи.'); }
    }
    private static function number($value, bool $positive, bool $decimal): ?float
    {
        if (!$positive && $value === null) return null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0) { throw new \InvalidArgumentException('Ожидается конечное неотрицательное число каталога.'); }
        // Bitrix converts doubles to PHP strings before SQL; DECIMAL columns use scale 8.
        $value = (float)(string)$value;
        if ($decimal) $value = round($value, 8, PHP_ROUND_HALF_UP);
        if ($positive && $value <= 0) { throw new \InvalidArgumentException('Все записываемые величины должны быть положительными.'); }
        if ($decimal && $value >= 1e18) { throw new \InvalidArgumentException('Цена не помещается в DECIMAL(26,8).'); }
        return $value;
    }
    private static function quantity($value): ?int
    {
        if ($value === null || $value === 0) return null;
        if (!is_int($value) || $value < 1 || $value > 2147483647) { throw new \InvalidArgumentException('Некорректная граница диапазона цены.'); }
        return $value;
    }
    private static function currency($value): string
    {
        if (!is_string($value) || preg_match('/^[A-Z]{3}$/D', $value) !== 1) { throw new \InvalidArgumentException('Некорректная валюта каталога.'); }
        return $value;
    }
    private static function priceKey(array $price): string { return $price['typeId'] . ':' . ($price['quantityFrom'] ?? 'n') . ':' . ($price['quantityTo'] ?? 'n'); }
}
