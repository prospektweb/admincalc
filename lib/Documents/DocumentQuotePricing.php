<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/DocumentCatalogWritePlan.php';

/** Derive write authority from the published grid selected by the authenticated core.
 * Never infer it from the prices returned (a partial result must fail, not reduce scope). */
final class DocumentQuotePricing
{
    public static function connection(object $document, array $quote, object $connection): object
    {
        if (($quote['calculatorId'] ?? null) !== ($document->id ?? null) || !is_string($document->id ?? null)
            || ($quote['currency'] ?? null) !== ($document->execution->currency ?? null)
            || !is_string($quote['currency'] ?? null) || !array_key_exists('priceProfile', $quote)) {
            throw new \InvalidArgumentException('Источник цен не соответствует опубликованному калькулятору.');
        }
        $types = $document->pricing->types ?? null;
        if (!is_array($types) || !array_is_list($types) || !$types || count($types) > 100) throw new \InvalidArgumentException('Отсутствует реестр типов цен.');
        $known = []; $base = [];
        foreach ($types as $type) {
            if (!$type instanceof \stdClass || !is_string($type->id ?? null) || $type->id === '' || isset($known[$type->id])
                || !is_bool($type->base ?? null)) throw new \InvalidArgumentException('Некорректный реестр типов цен.');
            $known[$type->id] = true; if ($type->base) $base[] = $type->id;
        }
        if (count($base) !== 1) throw new \InvalidArgumentException('Требуется единственный базовый тип цены.');
        $bindings = $connection->priceTypes ?? null; $byId = []; $byKey = [];
        if (!is_array($bindings) || !array_is_list($bindings) || count($bindings) !== count($types)) throw new \InvalidArgumentException('Неполные привязки реестра типов цен.');
        foreach ($bindings as $binding) {
            if (!$binding instanceof \stdClass || !is_string($binding->typeId ?? null) || !isset($known[$binding->typeId])
                || !is_string($binding->key ?? null) || preg_match('/^[1-9][0-9]{0,8}$/D', $binding->key) !== 1
                || isset($byId[$binding->typeId]) || isset($byKey[$binding->key])) throw new \InvalidArgumentException('Некорректная привязка типа цены.');
            $byId[$binding->typeId] = true; $byKey[$binding->key] = true;
        }
        $rules = $document->pricing->ranges ?? null;
        if ($quote['priceProfile'] !== null) {
            $selected = $quote['priceProfile']; $profiles = $document->pricing->profiles ?? null;
            if (!is_array($selected) || !is_string($selected['id'] ?? null) || !is_array($profiles) || !array_is_list($profiles)) throw new \InvalidArgumentException('Некорректный ценовой профиль результата.');
            $matches = array_values(array_filter($profiles, static fn($profile): bool => $profile instanceof \stdClass && ($profile->id ?? null) === $selected['id']));
            $profile = count($matches) === 1 ? $matches[0] : null;
            if ($profile === null || ($profile->enabled ?? null) !== true || ($selected['name'] ?? null) !== ($profile->name ?? null)
                || ($selected['conditionCode'] ?? null) !== ($profile->condition->code ?? null)) throw new \InvalidArgumentException('Результат ссылается на посторонний или выключенный ценовой профиль.');
            $rules = $profile->prices ?? null;
        }
        if (!is_array($rules) || !array_is_list($rules) || !$rules || count($rules) > 5000
            || !is_array($quote['appliedPriceRules'] ?? null)
            || DocumentCatalogWritePlan::canonical($quote['appliedPriceRules']) !== DocumentCatalogWritePlan::canonical($rules)) {
            throw new \InvalidArgumentException('Сервер не подтвердил полный снимок опубликованных правил цен.');
        }
        $grids = [];
        foreach ($rules as $rule) {
            if (!$rule instanceof \stdClass || !is_string($rule->typeId ?? null) || !isset($known[$rule->typeId])) throw new \InvalidArgumentException('Посторонний тип в ценовой сетке.');
            $key = self::interval(get_object_vars($rule));
            if (isset($grids[$rule->typeId][$key])) throw new \InvalidArgumentException('Повторный диапазон опубликованной сетки.');
            $grids[$rule->typeId][$key] = ($rule->quantityFrom ?? 0);
        }
        if (!isset($grids[$base[0]])) throw new \InvalidArgumentException('Базовый тип цены нельзя выключить.');
        $expected = null;
        foreach ($grids as $grid) {
            asort($grid, SORT_NUMERIC); $keys = array_keys($grid);
            if ($expected !== null && $expected !== $keys) throw new \InvalidArgumentException('Несогласованные интервалы опубликованных типов цен.');
            $expected = $keys;
        }
        $ranges = $quote['priceRanges'] ?? null;
        if (!is_array($ranges) || !array_is_list($ranges)) throw new \InvalidArgumentException('Отсутствуют рассчитанные диапазоны цен.');
        $actual = [];
        foreach ($ranges as $range) { if (!is_array($range)) throw new \InvalidArgumentException('Некорректный диапазон результата.'); $actual[] = self::interval($range); }
        if ($actual !== $expected) throw new \InvalidArgumentException('Сервер вернул неполные или посторонние диапазоны цен.');
        // Ephemeral execution projection; the stored connection and its hash stay exact.
        $result = clone $connection;
        $result->priceTypes = array_values(array_filter($bindings, static fn($binding): bool => isset($grids[$binding->typeId])));
        return $result;
    }

    private static function interval(array $range): string
    {
        foreach (['quantityFrom', 'quantityTo'] as $key) {
            if (!array_key_exists($key, $range) || ($range[$key] !== null && (!is_int($range[$key]) || $range[$key] < 0 || $range[$key] > 9007199254740991))) throw new \InvalidArgumentException('Некорректная граница ценовой сетки.');
        }
        if ($range['quantityTo'] !== null && $range['quantityTo'] < ($range['quantityFrom'] ?? 0)) throw new \InvalidArgumentException('Обратный диапазон цен.');
        return ($range['quantityFrom'] ?? 0) . ':' . ($range['quantityTo'] ?? 'end');
    }
}
