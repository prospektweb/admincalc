<?php

namespace Prospektweb\Frontcalc\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class CalculatorAvailability
{
    private const MODULE_ID = 'prospektweb.calc';

    public function isAvailableForProduct(int $productId, int $iblockId): bool
    {
        if ($productId <= 0 || $iblockId <= 0) {
            return false;
        }

        if (!Loader::includeModule('iblock')) {
            return false;
        }

        return $this->isSchemaAvailable($this->getProductSchema($productId, $iblockId));
    }

    public function getLightPayload(int $productId, int $iblockId, string $ajaxUrl = ''): array
    {
        $schema = $this->getProductSchema($productId, $iblockId);

        $accessResolver = new AccessScenarioResolver();

        return [
            'is_available' => $this->isSchemaAvailable($schema),
            'product_id' => max(0, $productId),
            'ajax_url' => $ajaxUrl !== ''
                ? $ajaxUrl
                : (string)Option::get(self::MODULE_ID, 'CALC_AJAX_URL', '/local/ajax/frontcalc.php'),
            'open_popup_chips' => $this->getOpenPopupChips($schema),
            'access' => $accessResolver->getPublicPayload(),
        ];
    }

    private function getProductSchema(int $productId, int $iblockId): array
    {
        if ($productId <= 0 || $iblockId <= 0) {
            return [];
        }

        $propertyCode = trim((string)Option::get(self::MODULE_ID, 'CALC_PROPERTY_CODE', 'FRONTCALC_CONFIG'));
        if ($propertyCode === '') {
            return [];
        }

        $propertyRes = \CIBlockElement::GetProperty($iblockId, $productId, [], ['CODE' => $propertyCode]);
        $property = $propertyRes ? $propertyRes->Fetch() : false;
        if (!$property) {
            return [];
        }

        $rawSchema = $this->extractPropertyValue($property);
        if ($rawSchema === '') {
            return [];
        }

        $decoded = json_decode($rawSchema, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isSchemaAvailable(array $schema): bool
    {
        if (!isset($schema['fields']) || !is_array($schema['fields']) || empty($schema['fields'])) {
            return false;
        }

        foreach ($schema['fields'] as $field) {
            if (is_array($field) && trim((string)($field['property_code'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function getOpenPopupChips(array $schema): array
    {
        if (!isset($schema['fields']) || !is_array($schema['fields'])) {
            return [];
        }

        $propertyNames = $this->getOfferPropertyNames();
        $chips = [];

        foreach ($schema['fields'] as $field) {
            if (!is_array($field)) {
                continue;
            }

            $propertyCode = trim((string)($field['property_code'] ?? ''));
            $label = trim((string)($field['open_popup_chip_label'] ?? ''));
            if ($propertyCode === '' || $label === '') {
                continue;
            }

            $chips[] = [
                'property_code' => $propertyCode,
                'property_name' => (string)($propertyNames[$propertyCode] ?? ''),
                'label' => $label,
            ];
        }

        return $chips;
    }

    private function getOfferPropertyNames(): array
    {
        $iblockIds = array_filter(array_unique([
            (int)Option::get(self::MODULE_ID, 'OFFERS_IBLOCK_ID', '0'),
            (int)Option::get(self::MODULE_ID, 'PRODUCTS_IBLOCK_ID', '0'),
        ]));
        if (!$iblockIds || !Loader::includeModule('iblock')) {
            return [];
        }

        $names = [];
        foreach ($iblockIds as $iblockId) {
            $res = \CIBlockProperty::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['IBLOCK_ID' => $iblockId]);
            while ($row = $res->Fetch()) {
                $code = trim((string)($row['CODE'] ?? ''));
                if ($code !== '' && !isset($names[$code])) {
                    $names[$code] = (string)($row['NAME'] ?? '');
                }
            }
        }

        return $names;
    }

    private function extractPropertyValue(array $property): string
    {
        $value = $property['VALUE'] ?? '';

        if (is_array($value)) {
            return trim((string)($value['TEXT'] ?? ''));
        }

        return trim((string)$value);
    }
}
