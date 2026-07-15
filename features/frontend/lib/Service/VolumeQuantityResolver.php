<?php

namespace Prospektweb\Frontcalc\Service;

final class VolumeQuantityResolver
{
    public function resolveOfferQuantity(array $offer, array $enumValueByXmlId = []): ?int
    {
        $quantity = $this->parseStrictPositiveInt($offer['quantity'] ?? null);
        if ($quantity !== null) { return $quantity; }
        $properties = is_array($offer['properties'] ?? null) ? $offer['properties'] : [];
        return $this->resolvePropertyQuantity($properties['CALC_PROP_VOLUME'] ?? null, $enumValueByXmlId);
    }

    public function resolvePropertyQuantity($property, array $enumValueByXmlId = []): ?int
    {
        if (!is_array($property)) { return null; }
        foreach (['value', 'VALUE', 'VALUE_ENUM'] as $key) {
            $quantity = $this->parseStrictPositiveInt($property[$key] ?? null);
            if ($quantity !== null) { return $quantity; }
        }
        $xmlId = trim((string)($property['xml_id'] ?? $property['VALUE_XML_ID'] ?? $property['xmlId'] ?? ''));
        if ($xmlId !== '' && array_key_exists($xmlId, $enumValueByXmlId)) {
            $quantity = $this->parseStrictPositiveInt($enumValueByXmlId[$xmlId]);
            if ($quantity !== null) { return $quantity; }
        }
        return $this->parseStrictPositiveInt($xmlId);
    }

    public function parseStrictPositiveInt($value): ?int
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null) { return null; }
        $text = preg_replace('/[\s\x{00A0}]+/u', '', trim((string)$value));
        if ($text === '' || preg_match('/^[1-9][0-9]*$/', $text) !== 1) { return null; }
        if (strlen($text) > strlen((string)PHP_INT_MAX) || strcmp($text, (string)PHP_INT_MAX) > 0 && strlen($text) === strlen((string)PHP_INT_MAX)) { return null; }
        $int = (int)$text;
        return (string)$int === $text && $int > 0 ? $int : null;
    }
}
