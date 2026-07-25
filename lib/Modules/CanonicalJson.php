<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Modules;

final class CanonicalJson
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): string
    {
        return self::encodeValue($value);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function moduleContentPayload(array $module): array
    {
        $fields = [
            'schema',
            'familyId',
            'version',
            'kind',
            'content',
            'ports',
            'entityRoles',
            'dependencies',
            'tests',
        ];
        $payload = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $module)) {
                throw new \InvalidArgumentException("Module content field is missing: {$field}");
            }
            $payload[$field] = $module[$field];
        }
        return $payload;
    }

    public static function moduleContentHash(array $module): string
    {
        return self::hash(self::moduleContentPayload($module));
    }

    private static function encodeValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (is_int($value)) {
            if (abs($value) > 9007199254740991) {
                return self::encodeNumber((float)$value);
            }
            return (string)$value;
        }
        if (is_float($value)) {
            return self::encodeNumber($value);
        }
        if (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                throw new \InvalidArgumentException('JCS strings must contain valid UTF-8');
            }
            return json_encode($value, self::FLAGS);
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('JCS supports only JSON-compatible values');
        }
        if (array_is_list($value)) {
            return '[' . implode(',', array_map([self::class, 'encodeValue'], $value)) . ']';
        }

        $keys = array_keys($value);
        foreach ($keys as $key) {
            if (!is_string($key) || !mb_check_encoding($key, 'UTF-8')) {
                throw new \InvalidArgumentException('JCS object keys must be valid UTF-8 strings');
            }
        }
        usort($keys, static function (string $left, string $right): int {
            return strcmp(
                mb_convert_encoding($left, 'UTF-16BE', 'UTF-8'),
                mb_convert_encoding($right, 'UTF-16BE', 'UTF-8')
            );
        });

        $parts = [];
        foreach ($keys as $key) {
            $parts[] = json_encode($key, self::FLAGS) . ':' . self::encodeValue($value[$key]);
        }
        return '{' . implode(',', $parts) . '}';
    }

    private static function encodeNumber(float $value): string
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException('JCS does not support NaN or Infinity');
        }
        if ($value == 0.0) {
            return '0';
        }

        $encoded = strtolower(json_encode($value, self::FLAGS));
        if (!str_contains($encoded, 'e')) {
            return $encoded;
        }

        [$mantissa, $exponentRaw] = explode('e', $encoded, 2);
        $exponent = (int)$exponentRaw;
        $negative = str_starts_with($mantissa, '-');
        $unsigned = $negative ? substr($mantissa, 1) : $mantissa;
        $digits = str_replace('.', '', $unsigned);
        $digits = rtrim($digits, '0');
        if ($digits === '') {
            return '0';
        }
        $decimalPosition = (strpos($unsigned, '.') === false ? strlen($unsigned) : strpos($unsigned, '.')) + $exponent;
        $scientificExponent = $decimalPosition - 1;
        $sign = $negative ? '-' : '';

        if ($scientificExponent >= 21 || $scientificExponent <= -7) {
            $coefficient = $digits[0];
            if (strlen($digits) > 1) {
                $coefficient .= '.' . substr($digits, 1);
            }
            return $sign . $coefficient . 'e' . ($scientificExponent >= 0 ? '+' : '') . $scientificExponent;
        }
        if ($decimalPosition <= 0) {
            return $sign . '0.' . str_repeat('0', -$decimalPosition) . $digits;
        }
        if ($decimalPosition >= strlen($digits)) {
            return $sign . $digits . str_repeat('0', $decimalPosition - strlen($digits));
        }
        return $sign . substr($digits, 0, $decimalPosition) . '.' . substr($digits, $decimalPosition);
    }
}
