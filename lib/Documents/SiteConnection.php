<?php
declare(strict_types=1);

namespace Prospektweb\Calc\Documents;

/** Explicit site-adapter configuration; never interpreted by the portable core. */
final class SiteConnection
{
    public const CONTRACT = 'prospektweb.calculator/site-connection-v1';

    public static function canonical(string $json, array $document): string
    {
        if (strlen($json) > 2000000) { throw new \InvalidArgumentException('Site connection exceeds byte limit.'); }
        $value = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        if (!$value instanceof \stdClass) { throw new \InvalidArgumentException('Expected site connection object.'); }
        self::keys($value, ['contract', 'provider', 'productsCatalog', 'offersCatalog', 'products', 'priceTypes', 'formBindings', 'inputMappings', 'outputMappings']);
        if ($value->contract !== self::CONTRACT) { throw new \InvalidArgumentException('Unsupported site connection.'); }
        foreach (['provider', 'productsCatalog', 'offersCatalog'] as $key) { self::identity($value->$key); }
        if ($value->productsCatalog === $value->offersCatalog) { throw new \InvalidArgumentException('Product and offer catalogs must differ.'); }
        $views = [];
        foreach (($document['presentations']['views'] ?? []) as $view) { $views[$view['id']] = true; }
        $types = [];
        foreach (($document['pricing']['types'] ?? []) as $type) { $types[$type['id']] = true; }
        self::listing($value->products, 10000);
        $seen = [];
        foreach ($value->products as $product) {
            self::keys($product, ['key', 'presentationId']);
            self::identity($product->key); self::identity($product->presentationId);
            if (isset($seen[$product->key]) || !isset($views[$product->presentationId])) {
                throw new \InvalidArgumentException('Duplicate product or unknown presentation.');
            }
            $seen[$product->key] = true;
        }
        self::listing($value->priceTypes, 100);
        $seen = []; $mapped = [];
        foreach ($value->priceTypes as $type) {
            self::keys($type, ['key', 'typeId']);
            self::identity($type->key); self::identity($type->typeId);
            if (isset($seen[$type->key]) || isset($mapped[$type->typeId]) || !isset($types[$type->typeId])) {
                throw new \InvalidArgumentException('Duplicate or unknown price type binding.');
            }
            $seen[$type->key] = true; $mapped[$type->typeId] = true;
        }
        if (!$value->formBindings instanceof \stdClass) { throw new \InvalidArgumentException('Expected form bindings.'); }
        self::listing($value->inputMappings, 1000); self::listing($value->outputMappings, 1000);
        // Nested mappings are compiled and checked by the site form adapter at publication.
        usort($value->products, static fn($a, $b) => strcmp($a->key, $b->key));
        usort($value->priceTypes, static fn($a, $b) => strcmp($a->key, $b->key));
        return self::encode($value);
    }

    public static function encode($value): string
    {
        $sort = static function ($v) use (&$sort) {
            if ($v instanceof \stdClass) {
                $properties = get_object_vars($v); ksort($properties, SORT_STRING);
                $result = new \stdClass();
                foreach ($properties as $key => $item) {
                    if (in_array($key, ['__proto__', 'prototype', 'constructor'], true)) { throw new \InvalidArgumentException('Unsafe object key.'); }
                    $result->$key = $sort($item);
                }
                return $result;
            }
            if (is_array($v)) { return array_map($sort, $v); }
            return $v;
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function keys($value, array $keys): void
    {
        if (!$value instanceof \stdClass) { throw new \InvalidArgumentException('Expected connection object.'); }
        $actual = array_keys(get_object_vars($value)); sort($actual); sort($keys);
        if ($actual !== $keys) { throw new \InvalidArgumentException('Unknown or missing connection field.'); }
    }
    private static function listing($value, int $limit): void
    {
        if (!is_array($value) || count($value) > $limit) { throw new \InvalidArgumentException('Invalid connection list.'); }
    }
    private static function identity($value): void
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid connection identity.');
        }
    }
}
