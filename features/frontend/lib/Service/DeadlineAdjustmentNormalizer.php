<?php

namespace Prospektweb\Frontcalc\Service;

final class DeadlineAdjustmentNormalizer
{
    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $volumeEnumValues XML_ID => enum row or numeric display value
     * @return array<string,mixed>
     */
    public static function normalize(array $config, array $volumeEnumValues = []): array
    {
        $volumeField = [];
        foreach (is_array($config['fields'] ?? null) ? $config['fields'] : [] as $field) {
            if (is_array($field) && (string)($field['property_code'] ?? $field['code'] ?? '') === 'CALC_PROP_VOLUME') {
                $volumeField = $field;
                break;
            }
        }

        $source = is_array($volumeField['deadline_adjustments'] ?? null)
            ? $volumeField['deadline_adjustments']
            : (is_array($config['deadline_adjustments'] ?? null) ? $config['deadline_adjustments'] : []);

        $volumeMap = self::buildVolumeMap($volumeEnumValues);
        foreach (is_array($volumeField['presets'] ?? null) ? $volumeField['presets'] : [] as $preset) {
            if (!is_array($preset)) { continue; }
            $xmlId = (string)($preset['xml_id'] ?? $preset['xmlId'] ?? '');
            if ($xmlId === '' || isset($volumeMap[$xmlId])) { continue; }
            $quantity = self::parseStrictPositiveInteger($preset['value'] ?? $preset['VALUE'] ?? null);
            if ($quantity !== null) { $volumeMap[$xmlId] = $quantity; }
        }

        return [
            'mode' => (string)($source['mode'] ?? 'simple') === 'advanced' ? 'advanced' : 'simple',
            'urgent_markup' => self::parsePercent($source['urgent_markup'] ?? null),
            'flexible_discount' => self::parsePercent($source['flexible_discount'] ?? null),
            'advanced' => [
                'urgent_markup' => self::normalizeRows($source['advanced']['urgent_markup'] ?? [], $volumeMap),
                'flexible_discount' => self::normalizeRows($source['advanced']['flexible_discount'] ?? [], $volumeMap),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $deadline
     * @return array<string,mixed>
     */
    public static function sanitizeNormalized(array $deadline): array
    {
        return [
            'mode' => (string)($deadline['mode'] ?? 'simple') === 'advanced' ? 'advanced' : 'simple',
            'urgent_markup' => self::parsePercent($deadline['urgent_markup'] ?? null),
            'flexible_discount' => self::parsePercent($deadline['flexible_discount'] ?? null),
            'advanced' => [
                'urgent_markup' => self::sanitizeNormalizedRows($deadline['advanced']['urgent_markup'] ?? []),
                'flexible_discount' => self::sanitizeNormalizedRows($deadline['advanced']['flexible_discount'] ?? []),
            ],
        ];
    }

    /** @param array<string,mixed> $volumeEnumValues */
    private static function buildVolumeMap(array $volumeEnumValues): array
    {
        $map = [];
        foreach ($volumeEnumValues as $xmlId => $row) {
            $xmlId = (string)$xmlId;
            if ($xmlId === '') { continue; }
            $value = is_array($row) ? ($row['value'] ?? $row['VALUE'] ?? null) : $row;
            $quantity = self::parseStrictPositiveInteger($value);
            if ($quantity !== null) { $map[$xmlId] = $quantity; }
        }
        return $map;
    }

    private static function normalizeRows($rows, array $volumeMap): array
    {
        $byVolume = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) { continue; }
            $volumeRaw = $row['volume'] ?? null;
            $volume = null;
            if (is_string($volumeRaw) && array_key_exists($volumeRaw, $volumeMap)) {
                $volume = $volumeMap[$volumeRaw];
            } else {
                $volume = self::parseStrictPositiveInteger($volumeRaw);
            }
            $percent = self::parsePercentOrNull($row['percent'] ?? null);
            if ($volume === null || $percent === null) { continue; }
            $byVolume[$volume] = ['volume' => $volume, 'percent' => $percent];
        }
        ksort($byVolume, SORT_NUMERIC);
        return array_values($byVolume);
    }

    private static function sanitizeNormalizedRows($rows): array
    {
        $byVolume = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) { continue; }
            $volume = self::parseStrictPositiveInteger($row['volume'] ?? null);
            $percent = self::parsePercentOrNull($row['percent'] ?? null);
            if ($volume === null || $percent === null) { continue; }
            $byVolume[$volume] = ['volume' => $volume, 'percent' => $percent];
        }
        ksort($byVolume, SORT_NUMERIC);
        return array_values($byVolume);
    }

    private static function parsePercent($value): float
    {
        return self::parsePercentOrNull($value) ?? 0.0;
    }

    private static function parsePercentOrNull($value): ?float
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null || $value === '') { return null; }
        return is_numeric($value) ? (float)$value : null;
    }

    private static function parseStrictPositiveInteger($value): ?int
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null) { return null; }
        if (is_int($value)) { return $value > 0 ? $value : null; }
        if (is_float($value)) { return $value > 0 && floor($value) === $value ? (int)$value : null; }
        if (!is_string($value)) { return null; }
        $value = trim($value);
        if ($value === '' || !preg_match('/^[1-9][0-9]*(?: [0-9]{3})*$/', $value)) { return null; }
        return (int)str_replace(' ', '', $value);
    }
}
