<?php

namespace Prospektweb\Frontcalc\Service;

class CustomSelectionValidator
{
    public const INVALID = 'FRONTCALC_CUSTOM_VALUES_INVALID';
    public const NOT_ALLOWED = 'FRONTCALC_CUSTOM_VALUE_NOT_ALLOWED';
    public const OUT_OF_RANGE = 'FRONTCALC_CUSTOM_VALUE_OUT_OF_RANGE';
    public const TARGET_QUANTITY_INVALID = 'FRONTCALC_CUSTOM_TARGET_QUANTITY_INVALID';
    private const MAX_VALUE_LENGTH = 120;
    private const VOLUME_CODE = 'CALC_PROP_VOLUME';

    /** @param array<string,mixed> $config @param array<string,array<string,array<string,mixed>>> $propertyEnumValues @param array<string,array<string,array<string,mixed>>> $presetBuckets @return array{ok:bool,values?:array<string,array{value:string,xml_id:string,sort:int}>,error?:array{code:string,message:string}} */
    public function validate(array $config, array $selectedValues, array $propertyEnumValues, array $presetBuckets): array
    {
        $fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];
        $allowed = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $code = trim((string)($field['property_code'] ?? ''));
            if ($code !== '' && strpos($code, 'CALC_PROP_') === 0) {
                $allowed[$code] = $field;
            }
        }
        if (empty($allowed) || empty($selectedValues)) {
            return $this->error(self::INVALID, 'Не переданы значения для расчёта.');
        }
        foreach ($selectedValues as $code => $row) {
            if (!isset($allowed[$code]) || !is_array($row)) {
                return $this->error(self::INVALID, 'Передано неизвестное свойство.');
            }
            foreach ($row as $key => $unused) {
                if (!in_array($key, ['value', 'xmlId', 'xml_id'], true)) {
                    return $this->error(self::INVALID, 'Переданы недопустимые служебные данные.');
                }
            }
        }

        $result = [];
        foreach ($allowed as $code => $field) {
            if ($code === self::VOLUME_CODE) {
                continue;
            }
            if (!array_key_exists($code, $selectedValues)) {
                return $this->error(self::INVALID, 'Заполнены не все характеристики калькулятора.');
            }
            $row = $selectedValues[$code];
            $valueRaw = array_key_exists('value', $row) ? $row['value'] : '';
            $xmlIdRaw = array_key_exists('xmlId', $row) ? $row['xmlId'] : ($row['xml_id'] ?? '');
            if (!$this->isStringLike($valueRaw) || !$this->isStringLike($xmlIdRaw)) {
                return $this->error(self::INVALID, 'Значение свойства должно быть строкой или числом.');
            }
            $value = trim((string)$valueRaw);
            $xmlId = trim((string)$xmlIdRaw);
            if (($value === '' && $xmlId === '') || $this->stringLength($value) > self::MAX_VALUE_LENGTH || $this->stringLength($xmlId) > self::MAX_VALUE_LENGTH) {
                return $this->error(self::INVALID, 'Значение свойства пустое или слишком длинное.');
            }

            $known = $this->findKnownValue($field, $code, $xmlId, $propertyEnumValues, $presetBuckets);
            if ($xmlId !== '' && $known !== null) {
                $result[$code] = $known;
                continue;
            }

            if ((string)($field['display_mode'] ?? '') === 'chips_only' || !$this->hasInputs($field)) {
                return $this->error(self::NOT_ALLOWED, 'Произвольное значение для свойства недоступно.');
            }
            if (!$this->validateInputs($field, $value)) {
                return $this->error(self::OUT_OF_RANGE, 'Значение свойства вне допустимого диапазона.');
            }
            $normalizedValue = $this->normalizeNumericString($value);
            $result[$code] = ['value' => $value, 'xml_id' => $normalizedValue !== '' ? $normalizedValue : $value, 'sort' => 500];
        }
        return ['ok' => true, 'values' => $result];
    }

    /** @return array{ok:bool,value?:int,error?:array{code:string,message:string}} */
    public function parseTargetQuantity($value): array
    {
        $quantity = (new VolumeQuantityResolver())->parseStrictPositiveInt($value);
        if ($quantity === null) {
            return $this->error(self::TARGET_QUANTITY_INVALID, 'Укажите положительный целый тираж.');
        }
        return ['ok' => true, 'value' => $quantity];
    }

    /** @return array{ok:bool,error?:array{code:string,message:string}} */
    public function validateTargetQuantityAgainstContext(int $targetQuantity, array $context): array
    {
        $min = $this->num($context['min'] ?? null);
        $max = $this->num($context['max'] ?? null);
        $step = $this->num($context['step'] ?? null);
        if (($min !== null && $targetQuantity < $min) || ($max !== null && $targetQuantity > $max)) {
            return $this->error(self::OUT_OF_RANGE, 'Тираж вне допустимого диапазона.');
        }
        if ($step !== null && $step > 0) {
            $anchor = $min ?? 0.0;
            $q = ($targetQuantity - $anchor) / $step;
            if (abs($q - round($q)) > 0.000001) {
                return $this->error(self::OUT_OF_RANGE, 'Тираж не соответствует шагу.');
            }
        }
        return ['ok' => true];
    }

    private function findKnownValue(array $field, string $code, string $xmlId, array $propertyEnumValues, array $presetBuckets): ?array
    {
        if ($xmlId === '') {
            return null;
        }
        $allowedXmlIds = $this->getAllowedXmlIds($field);
        if (!isset($allowedXmlIds[$xmlId])) {
            return null;
        }
        foreach ([$propertyEnumValues[$code] ?? [], $presetBuckets[$code] ?? []] as $bucket) {
            if (isset($bucket[$xmlId]) && is_array($bucket[$xmlId])) {
                return [
                    'value' => (string)($bucket[$xmlId]['value'] ?? $allowedXmlIds[$xmlId]['value'] ?? $xmlId),
                    'xml_id' => (string)($bucket[$xmlId]['xml_id'] ?? $xmlId),
                    'sort' => (int)($bucket[$xmlId]['sort'] ?? $allowedXmlIds[$xmlId]['sort'] ?? 500),
                ];
            }
        }
        return $allowedXmlIds[$xmlId];
    }

    /** @return array<string,array{value:string,xml_id:string,sort:int}> */
    private function getAllowedXmlIds(array $field): array
    {
        $displayXmlIds = is_array($field['display_preset_xml_ids'] ?? null) ? $field['display_preset_xml_ids'] : [];
        if (!empty($displayXmlIds)) {
            $rows = [];
            foreach ($displayXmlIds as $idx => $xmlId) {
                $xmlId = trim((string)$xmlId);
                if ($xmlId !== '') {
                    $rows[$xmlId] = ['value' => $xmlId, 'xml_id' => $xmlId, 'sort' => 500 + $idx];
                }
            }
            return $rows;
        }
        $rows = [];
        foreach (['presets', 'values', 'options'] as $key) {
            if (!is_array($field[$key] ?? null)) {
                continue;
            }
            foreach ($field[$key] as $idx => $row) {
                $xmlId = is_array($row) ? trim((string)($row['xml_id'] ?? $row['id'] ?? $row['code'] ?? $row['value'] ?? '')) : trim((string)$row);
                if ($xmlId === '') {
                    continue;
                }
                $rows[$xmlId] = [
                    'value' => is_array($row) ? (string)($row['value'] ?? $row['label'] ?? $row['name'] ?? $xmlId) : $xmlId,
                    'xml_id' => $xmlId,
                    'sort' => (int)(is_array($row) ? ($row['sort'] ?? 500 + $idx) : 500 + $idx),
                ];
            }
        }
        return $rows;
    }

    private function hasInputs(array $field): bool
    {
        return !empty($field['inputs']) && is_array($field['inputs']);
    }

    private function validateInputs(array $field, string $value): bool
    {
        $inputs = array_values(array_filter($field['inputs'] ?? [], 'is_array'));
        $delimiter = (string)($field['group_delimiter'] ?? 'x');
        $parts = count($inputs) > 1 ? preg_split('/\s*' . preg_quote($delimiter, '/') . '\s*/u', $value) : [$value];
        if (!is_array($parts) || count($parts) !== count($inputs)) {
            return false;
        }
        foreach ($inputs as $i => $input) {
            $n = $this->num($parts[$i] ?? null);
            if ($n === null) {
                return false;
            }
            $min = $this->num($input['min'] ?? null);
            $max = $this->num($input['max'] ?? null);
            $step = $this->num($input['step'] ?? null);
            if (($min !== null && $n < $min) || ($max !== null && $n > $max)) {
                return false;
            }
            if ($step !== null && $step > 0) {
                $anchor = $min ?? 0.0;
                $q = ($n - $anchor) / $step;
                if (abs($q - round($q)) > 0.000001) {
                    return false;
                }
            }
        }
        return true;
    }

    private function isStringLike($value): bool
    {
        return is_string($value) || is_int($value) || is_float($value);
    }

    private function stringLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function normalizeNumericString($value): string
    {
        $normalized = preg_replace('/[\s\x{00A0}]+/u', '', trim((string)$value));
        $normalized = str_replace(',', '.', (string)$normalized);
        if ($normalized !== '' && is_numeric($normalized)) {
            $number = (float)$normalized;
            if (abs($number - round($number)) < 0.000001) {
                return (string)(int)round($number);
            }
        }
        return $normalized;
    }

    private function num($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = str_replace(',', '.', preg_replace('/[\s\x{00A0}]+/u', '', trim((string)$v)));
        return is_numeric($s) ? (float)$s : null;
    }

    private function error(string $code, string $message): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    }
}
