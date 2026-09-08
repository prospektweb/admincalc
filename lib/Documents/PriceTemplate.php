<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/SiteConnection.php';

/** Portable reusable price grid. No document UUIDs, catalog IDs or mutable references. */
final class PriceTemplate
{
    public const CONTRACT = 'prospektweb.calculator/price-template-v1';

    public static function canonical(string $json): string
    {
        if (strlen($json) > 1000000) { throw new \InvalidArgumentException('Шаблон цен превышает допустимый размер.'); }
        $value = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        self::keys($value, ['contract', 'currency', 'mode', 'types', 'ranges']);
        if ($value->contract !== self::CONTRACT || !in_array($value->mode, ['markup', 'margin'], true)
            || !is_string($value->currency) || preg_match('/^[A-Z]{3}$/D', $value->currency) !== 1) {
            throw new \InvalidArgumentException('Некорректный формат или валюта шаблона цен.');
        }
        self::listing($value->types, 100); self::listing($value->ranges, 5000);
        $types = []; $base = 0;
        foreach ($value->types as $type) {
            self::keys($type, ['code', 'base', 'sort']);
            if (!is_string($type->code) || trim($type->code) === '' || mb_strlen($type->code, 'UTF-8') > 120
                || preg_match('/[\x00-\x1f\x7f]/', $type->code) || isset($types[$type->code])
                || !is_bool($type->base) || !is_int($type->sort) || $type->sort < 0 || $type->sort > 2147483646) {
                throw new \InvalidArgumentException('Типы цен шаблона должны быть уникальными и корректными.');
            }
            $types[$type->code] = []; $base += (int)$type->base;
        }
        if ($base !== 1) { throw new \InvalidArgumentException('Шаблон должен содержать ровно один базовый тип цены.'); }
        foreach ($value->ranges as $range) {
            self::keys($range, ['typeCode', 'price', 'mode', 'currency', 'quantityFrom', 'quantityTo', 'limitAmount', 'limitCurrency']);
            if (!is_string($range->typeCode) || !array_key_exists($range->typeCode, $types)
                || !in_array($range->mode, ['amount', $value->mode === 'margin' ? 'marginPercent' : 'markupPercent'], true)) {
                throw new \InvalidArgumentException('Неизвестный тип цены или режим диапазона.');
            }
            self::number($range->price);
            if ($range->mode === 'marginPercent' && $range->price >= 100) { throw new \InvalidArgumentException('Маржа должна быть меньше 100%.'); }
            if ($range->currency !== ($range->mode === 'amount' ? $value->currency : null)
                || $range->limitCurrency !== $value->currency) { throw new \InvalidArgumentException('Валюты диапазона не совпадают с валютой шаблона.'); }
            if ($range->limitAmount !== null) { self::number($range->limitAmount); }
            foreach (['quantityFrom', 'quantityTo'] as $key) {
                if ($range->$key !== null && (!is_int($range->$key) || $range->$key < 0 || $range->$key > 9007199254740991)) {
                    throw new \InvalidArgumentException('Границы диапазонов должны быть целыми неотрицательными числами.');
                }
            }
            if ($range->quantityTo !== null && $range->quantityTo < ($range->quantityFrom ?? 0)) { throw new \InvalidArgumentException('Обратный диапазон количества тиражей.'); }
            $types[$range->typeCode][] = $range;
        }
        $layout = null;
        foreach ($types as $ranges) {
            if (!$ranges) { throw new \InvalidArgumentException('Для каждого типа цены нужны диапазоны.'); }
            usort($ranges, static fn($a, $b) => ($a->quantityFrom ?? 0) <=> ($b->quantityFrom ?? 0));
            $end = -1; $signature = [];
            foreach ($ranges as $range) {
                if (($range->quantityFrom ?? 0) <= $end) { throw new \InvalidArgumentException('Диапазоны цен пересекаются.'); }
                $end = $range->quantityTo ?? INF;
                $signature[] = [$range->quantityFrom ?? 0, $range->quantityTo];
            }
            if ($layout !== null && $layout !== $signature) { throw new \InvalidArgumentException('Типы цен должны иметь одинаковые диапазоны.'); }
            $layout = $signature;
        }
        return SiteConnection::encode($value);
    }

    private static function number($value): void
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0) {
            throw new \InvalidArgumentException('Цена и ограничитель должны быть конечными неотрицательными числами.');
        }
    }
    private static function listing($value, int $max): void
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > $max) { throw new \InvalidArgumentException('Некорректный список в шаблоне цен.'); }
    }
    private static function keys($value, array $keys): void
    {
        if (!$value instanceof \stdClass) { throw new \InvalidArgumentException('Ожидается объект шаблона цен.'); }
        $actual = array_keys(get_object_vars($value)); sort($actual); sort($keys);
        if ($actual !== $keys) { throw new \InvalidArgumentException('Неизвестное или отсутствующее поле шаблона цен.'); }
    }
}
