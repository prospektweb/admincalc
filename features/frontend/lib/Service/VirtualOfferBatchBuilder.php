<?php

namespace Prospektweb\Frontcalc\Service;

require_once __DIR__ . '/VolumeQuantityResolver.php';

class VirtualOfferBatchBuilder
{
    private const VOLUME_CODE = 'CALC_PROP_VOLUME';

    /**
     * @param array<string,mixed> $config
     * @param array<string,array<string,array<string,mixed>>> $propertyEnumValues
     * @param array<string,array<string,array<string,mixed>>> $presetBuckets
     * @param array<int,array<string,mixed>> $existingOffers
     * @return array<int,array<string,mixed>>
     */
    public function build(array $config, array $propertyEnumValues, array $presetBuckets, array $existingOffers, int $productId, int $offersIblockId): array
    {
        $fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];
        if (empty($fields)) {
            return [];
        }

        $volumeField = null;
        $areaFieldCode = '';
        $batchFields = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $code = trim((string)($field['property_code'] ?? ''));
            if ($code === '' || strpos($code, 'CALC_PROP_') !== 0) {
                continue;
            }
            if ($code === self::VOLUME_CODE) {
                $volumeField = $field;
                continue;
            }
            if (($field['use_for_area_dependency'] ?? false) === true) {
                $areaFieldCode = $code;
            }
            $values = $this->getFieldValues($field, $propertyEnumValues, $presetBuckets);
            if (!empty($values)) {
                $batchFields[] = ['code' => $code, 'values' => $values];
            }
        }

        if (!is_array($volumeField)) {
            return [];
        }

        $existingPriced = $this->buildExistingPricedCombinationMap($existingOffers);
        $realVolumesByNonVolumeKey = $this->buildRealVolumesByNonVolumeKey($existingOffers);
        $combinations = $this->cartesianPropertyCombinations($batchFields);
        $virtualOffers = [];
        $virtualId = -100001;

        foreach ($combinations as $combination) {
            $volumeRows = $this->selectVolumeRows($volumeField, $combination, $areaFieldCode, $realVolumesByNonVolumeKey);
            foreach ($volumeRows as $volumeRow) {
                $fullCombination = $combination;
                $fullCombination[self::VOLUME_CODE] = $volumeRow;
                $fullKey = $this->makeCombinationKey($fullCombination, false);
                if ($fullKey === '' || isset($existingPriced[$fullKey])) {
                    continue;
                }

                $properties = [
                    'CML2_LINK' => [
                        'CODE' => 'CML2_LINK',
                        'VALUE' => (string)$productId,
                        '~VALUE' => (string)$productId,
                    ],
                ];
                foreach ($fullCombination as $code => $row) {
                    $properties[$code] = $this->makeCalcServerProperty($code, (string)$row['value'], (string)$row['xml_id']);
                }

                $virtualOffers[] = [
                    'id' => $virtualId--,
                    'name' => 'Виртуальный расчёт',
                    'iblockId' => $offersIblockId,
                    'productId' => $productId,
                    'properties' => $properties,
                ];
            }
        }

        return $virtualOffers;
    }


    /** @param array<string,mixed> $config @param array<string,array{value:string,xml_id:string,sort:int}> $selection @return array<string,mixed> */
    public function resolveVolumeContext(array $config, array $selection): array
    {
        $fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];
        $volumeField = null;
        $areaFieldCode = '';
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $code = trim((string)($field['property_code'] ?? ''));
            if ($code === self::VOLUME_CODE) {
                $volumeField = $field;
            }
            if ($code !== '' && ($field['use_for_area_dependency'] ?? false) === true) {
                $areaFieldCode = $code;
            }
        }
        if (!is_array($volumeField)) {
            return ['referenceVolumes' => [], 'min' => null, 'max' => null, 'step' => null, 'areaRangeIndex' => null, 'volumeField' => null, 'areaFieldCode' => $areaFieldCode];
        }

        $range = null;
        $referenceVolumes = [];
        if ($areaFieldCode !== '' && isset($selection[$areaFieldCode])) {
            $areaXmlId = trim((string)($selection[$areaFieldCode]['xml_id'] ?? ''));
            // Presets are parsed strictly by XML_ID. The value fallback is only
            // for a genuinely custom selection that has no XML_ID.
            $area = $this->parseAreaMm2($areaXmlId !== '' ? $areaXmlId : ($selection[$areaFieldCode]['value'] ?? ''));
            if ($area !== null) {
                $range = $this->findAreaRange($volumeField, $area);
                if ($range !== null) {
                    $referenceVolumes = $this->getAreaReferenceVolumes($volumeField, (int)$range['index']);
                }
            }
        }
        if (empty($referenceVolumes)) {
            $referenceVolumes = $this->getBaseReferenceVolumes($volumeField);
        }

        $primaryInput = isset($volumeField['inputs'][0]) && is_array($volumeField['inputs'][0]) ? $volumeField['inputs'][0] : [];
        $min = $range !== null ? ($this->positiveInt($range['min'] ?? $range['volume_min'] ?? null) ?? $this->positiveInt($primaryInput['min'] ?? null)) : $this->positiveInt($primaryInput['min'] ?? null);
        $max = $range !== null ? ($this->positiveInt($range['max'] ?? $range['volume_max'] ?? null) ?? $this->positiveInt($primaryInput['max'] ?? null)) : $this->positiveInt($primaryInput['max'] ?? null);
        $step = $this->positiveInt(($range !== null ? ($range['step'] ?? null) : null) ?? ($primaryInput['step'] ?? null));

        return [
            'referenceVolumes' => $referenceVolumes,
            'min' => $min,
            'max' => $max,
            'step' => $step,
            'areaRangeIndex' => $range !== null ? (int)$range['index'] : null,
            'volumeField' => $volumeField,
            'areaFieldCode' => $areaFieldCode,
        ];
    }

    /** @param array<string,mixed> $config @param array<string,array{value:string,xml_id:string,sort:int}> $selection @return array<int,array<string,mixed>> */
    public function buildForSelection(array $config, array $selection, int $productId, int $offersIblockId, int $targetQuantity = 0): array
    {
        $context = $this->resolveVolumeContext($config, $selection);
        if (!is_array($context['volumeField'] ?? null)) {
            return [];
        }
        $volumes = is_array($context['referenceVolumes'] ?? null) ? $context['referenceVolumes'] : [];
        foreach (['min', 'max'] as $key) {
            if (($context[$key] ?? null) !== null) {
                $volumes[] = (int)$context[$key];
            }
        }
        if ($targetQuantity > 0) {
            $volumes[] = $targetQuantity;
        }
        $seen = [];
        foreach ($volumes as $volume) {
            $n = $this->positiveInt($volume);
            if ($n !== null) {
                $seen[$n] = ['value' => (string)$n, 'xml_id' => (string)$n, 'sort' => 500];
            }
        }
        ksort($seen, SORT_NUMERIC);
        $offers = [];
        // Custom request virtual IDs intentionally restart per request; frontend must use offerKey, not this ID, as the stable cross-request key.
        $virtualId = -200000001;
        foreach ($seen as $volumeRow) {
            $fullCombination = $selection;
            $fullCombination[self::VOLUME_CODE] = $volumeRow;
            $properties = ['CML2_LINK' => ['CODE' => 'CML2_LINK', 'VALUE' => (string)$productId, '~VALUE' => (string)$productId]];
            foreach ($fullCombination as $code => $row) {
                $properties[$code] = $this->makeCalcServerProperty($code, (string)$row['value'], (string)$row['xml_id']);
            }
            $offers[] = ['id' => $virtualId--, 'name' => 'Виртуальный расчёт', 'iblockId' => $offersIblockId, 'productId' => $productId, 'properties' => $properties];
        }
        return $offers;
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public function buildVolumeConstraints(array $config): array
    {
        $fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];
        $volumeField = null; $areaCode = '';
        foreach ($fields as $field) {
            if (!is_array($field)) { continue; }
            $code = (string)($field['property_code'] ?? '');
            if ($code === self::VOLUME_CODE) { $volumeField = $field; }
            if ($code !== '' && ($field['use_for_area_dependency'] ?? false) === true) { $areaCode = $code; }
        }
        $base = ['min'=>null,'max'=>null,'step'=>null];
        $ranges = [];
        if (is_array($volumeField)) {
            $input = isset($volumeField['inputs'][0]) && is_array($volumeField['inputs'][0]) ? $volumeField['inputs'][0] : [];
            $base = ['min'=>$this->positiveInt($input['min'] ?? null),'max'=>$this->positiveInt($input['max'] ?? null),'step'=>$this->positiveInt($input['step'] ?? null)];
            foreach (is_array($volumeField['area_ranges'] ?? null) ? $volumeField['area_ranges'] : [] as $range) {
                if (!is_array($range)) { continue; }
                $ranges[] = [
                    'areaFromMm2'=>$this->nullableFloat($range['area_from_mm2'] ?? $range['from'] ?? null) ?? 0.0,
                    'areaToMm2'=>$this->nullableFloat($range['area_to_mm2'] ?? $range['to'] ?? null),
                    'min'=>$this->positiveInt($range['min'] ?? $range['volume_min'] ?? null),
                    'max'=>$this->positiveInt($range['max'] ?? $range['volume_max'] ?? null),
                    'step'=>$this->positiveInt($range['step'] ?? null),
                ];
            }
        }
        return ['volumeCode'=>self::VOLUME_CODE,'areaPropertyCode'=>$areaCode,'base'=>$base,'areaRanges'=>$ranges];
    }

    /** @param array<string,mixed> $constraints @param array<string,mixed> $normalizedSelectedValues @return array{ok:bool} */
    public function validateQuantityConstraints(int $quantity, array $constraints, array $normalizedSelectedValues): array
    {
        $range = is_array($constraints['base'] ?? null) ? $constraints['base'] : [];
        $areaCode = (string)($constraints['areaPropertyCode'] ?? '');
        if ($areaCode !== '' && isset($normalizedSelectedValues[$areaCode]) && is_array($normalizedSelectedValues[$areaCode])) {
            $area = $this->parseAreaMm2($normalizedSelectedValues[$areaCode]['value'] ?? $normalizedSelectedValues[$areaCode]['xmlId'] ?? '');
            if ($area !== null) {
                foreach (is_array($constraints['areaRanges'] ?? null) ? $constraints['areaRanges'] : [] as $candidate) {
                    if (!is_array($candidate)) { continue; }
                    $from = $this->nullableFloat($candidate['areaFromMm2'] ?? null) ?? 0.0;
                    $to = $this->nullableFloat($candidate['areaToMm2'] ?? null);
                    if ($area >= $from && ($to === null || $area <= $to)) { $range = $candidate; break; }
                }
            }
        }
        $min = $this->positiveInt($range['min'] ?? null); $max = $this->positiveInt($range['max'] ?? null); $step = $this->positiveInt($range['step'] ?? null);
        if ($min !== null && $quantity < $min) { return ['ok'=>false]; }
        if ($max !== null && $quantity > $max) { return ['ok'=>false]; }
        if ($step !== null) { $anchor = $min ?? 0; if (($quantity - $anchor) % $step !== 0) { return ['ok'=>false]; } }
        return ['ok'=>true];
    }

    /** @param array<int,array<string,mixed>> $offers @return array<int,array<int,array<string,mixed>>> */
    public function splitIntoBatches(array $offers, int $batchLimit): array
    {
        $batchLimit = $batchLimit > 0 ? $batchLimit : 200;
        return array_chunk($offers, $batchLimit);
    }

    public function parseAreaMm2($value): ?float
    {
        $text = str_replace("\xc2\xa0", ' ', (string)$value);
        if (!preg_match('/(?:^|[^\d.,])(\d+(?:[.,]\d+)?)\s*[xX×*хХ]\s*(\d+(?:[.,]\d+)?)(?:[^\d.,]|$)/u', $text, $matches)) {
            return null;
        }

        $width = $this->nullableFloat($matches[1] ?? null);
        $height = $this->nullableFloat($matches[2] ?? null);
        if ($width === null || $height === null || $width <= 0 || $height <= 0) {
            return null;
        }

        return $width * $height;
    }

    /** @param array<string,mixed> $volumeField @param array<string,array<string,mixed>> $combination @param array<string,array<int>> $realVolumesByNonVolumeKey @return array<int,array{value:string,xml_id:string,sort:int}> */
    private function selectVolumeRows(array $volumeField, array $combination, string $areaFieldCode, array $realVolumesByNonVolumeKey): array
    {
        $context = $this->resolveVolumeContext(['fields' => [array_merge($volumeField, ['property_code' => self::VOLUME_CODE]), ['property_code' => $areaFieldCode, 'use_for_area_dependency' => true]]], $combination);
        $volumes = is_array($context['referenceVolumes'] ?? null) ? $context['referenceVolumes'] : [];
        foreach (['min', 'max'] as $boundKey) {
            if (($context[$boundKey] ?? null) !== null) {
                $volumes[] = (int)$context[$boundKey];
            }
        }
        $nonVolumeKey = $this->makeCombinationKey($combination, true);
        foreach ($realVolumesByNonVolumeKey[$nonVolumeKey] ?? [] as $realVolume) {
            $volumes[] = $realVolume;
        }

        $volumes = $this->normalizeVolumeList($volumes);
        $rows = [];
        foreach ($volumes as $idx => $volume) {
            $value = (string)$volume;
            $rows[] = ['value' => $value, 'xml_id' => $value, 'sort' => 100 + $idx];
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    private function findAreaRange(array $volumeField, float $area): ?array
    {
        $ranges = is_array($volumeField['area_ranges'] ?? null) ? $volumeField['area_ranges'] : [];
        foreach ($ranges as $idx => $range) {
            if (!is_array($range)) {
                continue;
            }
            $from = $this->nullableFloat($range['area_from_mm2'] ?? $range['from'] ?? null) ?? 0.0;
            $to = $this->nullableFloat($range['area_to_mm2'] ?? $range['to'] ?? null);
            if ($area >= $from && ($to === null || $area <= $to)) {
                $range['index'] = (int)($range['index'] ?? $idx);
                return $range;
            }
        }
        return null;
    }

    /** @return array<int,int> */
    private function getBaseReferenceVolumes(array $volumeField): array
    {
        $reference = is_array($volumeField['reference_volumes'] ?? null) ? $volumeField['reference_volumes'] : [];
        return $this->normalizeVolumeList(is_array($reference['base'] ?? null) ? $reference['base'] : []);
    }

    /** @return array<int,int> */
    private function getAreaReferenceVolumes(array $volumeField, int $index): array
    {
        $reference = is_array($volumeField['reference_volumes'] ?? null) ? $volumeField['reference_volumes'] : [];
        $areaRows = is_array($reference['area'] ?? null) ? $reference['area'] : [];
        foreach ($areaRows as $row) {
            if (!is_array($row) || (int)($row['index'] ?? -1) !== $index) {
                continue;
            }
            return $this->normalizeVolumeList(is_array($row['volumes'] ?? null) ? $row['volumes'] : []);
        }
        return [];
    }

    /** @return array<int,int> */
    private function extractInputBounds(array $field): array
    {
        $bounds = [];
        $primaryInput = null;
        if (isset($field['inputs'][0]) && is_array($field['inputs'][0])) {
            $primaryInput = $field['inputs'][0];
        }

        foreach (['min', 'max'] as $key) {
            $value = $this->positiveInt(is_array($primaryInput) ? ($primaryInput[$key] ?? null) : null);
            if ($value === null) {
                $value = $this->positiveInt($field[$key] ?? null);
            }
            if ($value !== null) {
                $bounds[] = $value;
            }
        }
        return $bounds;
    }

    /** @return array<int,int> */
    private function extractRangeBounds(array $range): array
    {
        $bounds = [];
        foreach (['min', 'max', 'volume_min', 'volume_max'] as $key) {
            $value = $this->positiveInt($range[$key] ?? null);
            if ($value !== null) {
                $bounds[] = $value;
            }
        }
        return $bounds;
    }

    /** @param array<int,mixed> $values @return array<int,int> */
    private function normalizeVolumeList(array $values): array
    {
        $seen = [];
        foreach ($values as $value) {
            $intValue = $this->positiveInt($value);
            if ($intValue !== null) {
                $seen[$intValue] = $intValue;
            }
        }
        ksort($seen, SORT_NUMERIC);
        return array_values($seen);
    }

    /** @param mixed $value */
    private function positiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (new VolumeQuantityResolver())->parseStrictPositiveInt($value);
    }

    /** @param mixed $value */
    private function nullableFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = preg_replace('/[\s\x{00A0}]+/u', '', trim((string)$value));
        $normalized = str_replace(',', '.', (string)$normalized);
        return is_numeric($normalized) ? (float)$normalized : null;
    }

    /** @param array<int,array<string,mixed>> $offers @return array<string,bool> */
    private function buildExistingPricedCombinationMap(array $offers): array
    {
        $map = [];
        foreach ($offers as $offer) {
            $properties = is_array($offer['properties'] ?? null) ? $offer['properties'] : [];
            $key = $this->makeCombinationKey($properties, false);
            if ($key === '') {
                continue;
            }
            if ($this->hasApplicableViewPrice($offer)) {
                $map[$key] = true;
            }
        }
        return $map;
    }


    /** @param array<string,mixed> $offer */
    private function hasApplicableViewPrice(array $offer): bool
    {
        $catalog = is_array($offer['catalog'] ?? null) ? $offer['catalog'] : [];
        $pricesView = is_array($catalog['prices_view'] ?? null) ? $catalog['prices_view'] : [];
        foreach ($pricesView as $row) {
            if (is_array($row) && $this->priceRangeMatchesQuantity($row, 1)) {
                return true;
            }
        }

        $pricesViewAll = is_array($catalog['prices_view_all'] ?? null) ? $catalog['prices_view_all'] : [];
        foreach ($pricesViewAll as $row) {
            if (is_array($row) && $this->priceRangeMatchesQuantity($row, 1)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $row */
    private function priceRangeMatchesQuantity(array $row, int $quantity): bool
    {
        $from = array_key_exists('quantity_from', $row) ? $this->positiveInt($row['quantity_from']) : null;
        $to = array_key_exists('quantity_to', $row) ? $this->positiveInt($row['quantity_to']) : null;
        return ($from === null || $from <= $quantity) && ($to === null || $quantity <= $to);
    }

    /** @param array<int,array<string,mixed>> $offers @return array<string,array<int,int>> */
    private function buildRealVolumesByNonVolumeKey(array $offers): array
    {
        $map = [];
        foreach ($offers as $offer) {
            $properties = is_array($offer['properties'] ?? null) ? $offer['properties'] : [];
            $key = $this->makeCombinationKey($properties, true);
            $volume = (new VolumeQuantityResolver())->resolveOfferQuantity($offer);
            if ($key !== '' && $volume !== null) {
                $map[$key][$volume] = $volume;
            }
        }
        return $map;
    }

    /** @param array<string,array<string,mixed>> $properties */
    private function makeCombinationKey(array $properties, bool $excludeVolume): string
    {
        ksort($properties);
        $parts = [];
        foreach ($properties as $code => $row) {
            if (strpos((string)$code, 'CALC_PROP_') !== 0 || ($excludeVolume && $code === self::VOLUME_CODE)) {
                continue;
            }
            if ($code === self::VOLUME_CODE) {
                $quantity = (new VolumeQuantityResolver())->resolvePropertyQuantity($row);
                if ($quantity === null) { continue; }
                $parts[] = $code . '=' . $quantity;
                continue;
            }
            $xmlId = trim((string)$this->extractPropertyXmlId($row));
            if ($xmlId === '') {
                continue;
            }
            $parts[] = $code . '=' . $this->normalizeNumericString($xmlId);
        }
        return implode('|', $parts);
    }

    /** @param mixed $row */
    private function extractPropertyXmlId($row): string
    {
        if (!is_array($row)) {
            return '';
        }
        return (string)($row['xml_id'] ?? $row['VALUE_XML_ID'] ?? $row['xmlId'] ?? $row['VALUE'] ?? $row['value'] ?? '');
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

    /** @param array<string,mixed> $field @param array<string,array<string,array<string,mixed>>> $propertyEnumValues @param array<string,array<string,array<string,mixed>>> $presetBuckets @return array<int,array{value:string,xml_id:string,sort:int}> */
    private function getFieldValues(array $field, array $propertyEnumValues, array $presetBuckets): array
    {
        $code = trim((string)($field['property_code'] ?? ''));
        if ($code === '') {
            return [];
        }
        $displayXmlIds = is_array($field['display_preset_xml_ids'] ?? null) ? $field['display_preset_xml_ids'] : [];
        $rows = [];
        if (!empty($displayXmlIds) && !empty($propertyEnumValues[$code])) {
            foreach ($displayXmlIds as $idx => $xmlId) {
                $xmlId = trim((string)$xmlId);
                if ($xmlId !== '' && isset($propertyEnumValues[$code][$xmlId])) {
                    $enum = $propertyEnumValues[$code][$xmlId];
                    $rows[$xmlId] = [
                        'value' => (string)($enum['value'] ?? $xmlId),
                        'xml_id' => $xmlId,
                        'sort' => (int)($enum['sort'] ?? (500 + $idx)),
                    ];
                }
            }
        }
        foreach (['presets', 'values', 'options'] as $sourceKey) {
            if (!is_array($field[$sourceKey] ?? null)) {
                continue;
            }
            foreach ($field[$sourceKey] as $idx => $row) {
                if (is_array($row)) {
                    $xmlId = trim((string)($row['xml_id'] ?? $row['id'] ?? $row['code'] ?? $row['value'] ?? ''));
                    $value = trim((string)($row['value'] ?? $row['label'] ?? $row['name'] ?? $xmlId));
                    $sort = (int)($row['sort'] ?? (500 + $idx));
                } else {
                    $xmlId = trim((string)$row);
                    $value = $xmlId;
                    $sort = 500 + $idx;
                }
                if ($xmlId !== '') {
                    $rows[$xmlId] = ['value' => $value !== '' ? $value : $xmlId, 'xml_id' => $xmlId, 'sort' => $sort];
                }
            }
        }
        if (empty($rows) && !empty($presetBuckets[$code])) {
            foreach ($presetBuckets[$code] as $xmlId => $row) {
                $xmlId = trim((string)$xmlId);
                if ($xmlId !== '') {
                    $rows[$xmlId] = [
                        'value' => (string)($row['value'] ?? $xmlId),
                        'xml_id' => $xmlId,
                        'sort' => (int)($row['sort'] ?? 500),
                    ];
                }
            }
        }
        $result = array_values($rows);
        usort($result, static function ($a, $b) { return ((int)($a['sort'] ?? 500)) <=> ((int)($b['sort'] ?? 500)); });
        return $result;
    }

    /** @param array<int,array{code:string,values:array<int,array{value:string,xml_id:string,sort:int}>}> $fields @return array<int,array<string,array{value:string,xml_id:string,sort:int}>> */
    private function cartesianPropertyCombinations(array $fields): array
    {
        $combinations = [[]];
        foreach ($fields as $field) {
            $code = $field['code'];
            $values = $field['values'];
            if ($code === '' || empty($values)) {
                continue;
            }
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($values as $value) {
                    $row = $combination;
                    $row[$code] = $value;
                    $next[] = $row;
                }
            }
            $combinations = $next;
        }
        return $combinations;
    }

    private function makeCalcServerProperty(string $code, string $value, string $xmlId = ''): array
    {
        $xmlId = $xmlId !== '' ? $xmlId : $value;
        return [
            'CODE' => $code,
            'VALUE' => $value,
            '~VALUE' => $value,
            'VALUE_XML_ID' => $xmlId,
        ];
    }
}
