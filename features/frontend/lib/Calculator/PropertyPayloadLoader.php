<?php

namespace Prospektweb\Frontcalc\Calculator;

class PropertyPayloadLoader
{
    public static function loadElementProperties(int $iblockId, int $elementId): array
    {
        $properties = [];

        if ($iblockId <= 0 || $elementId <= 0) {
            return $properties;
        }

        $select = [
            'ID',
            'CODE',
            'DEFAULT_VALUE',
            'DESCRIPTION',
            'HINT',
            'IS_REQUIRED',
            'MULTIPLE',
            'NAME',
            'PROPERTY_TYPE',
            'SORT',
            'WITH_DESCRIPTION',
            'VALUE',
            '~VALUE',
            'VALUE_XML_ID',
        ];

        $rsProperty = \CIBlockElement::GetProperty(
            $iblockId,
            $elementId,
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['ACTIVE' => 'Y'],
            false,
            $select
        );

        while ($prop = $rsProperty->Fetch()) {
            $code = $prop['CODE'] ?: (string)$prop['ID'];
            if (!isset($properties[$code])) {
                $isMultiple = ($prop['MULTIPLE'] ?? 'N') === 'Y';
                $properties[$code] = [
                    'ID' => isset($prop['ID']) ? (int)$prop['ID'] : null,
                    'CODE' => $prop['CODE'] ?? '',
                    'DEFAULT_VALUE' => $prop['DEFAULT_VALUE'] ?? '',
                    'DESCRIPTION' => $isMultiple ? [] : null,
                    'HINT' => $prop['HINT'] ?? null,
                    'IS_REQUIRED' => $prop['IS_REQUIRED'] ?? 'N',
                    'MULTIPLE' => $prop['MULTIPLE'] ?? 'N',
                    'NAME' => $prop['NAME'] ?? '',
                    'PROPERTY_TYPE' => $prop['PROPERTY_TYPE'] ?? '',
                    'SORT' => isset($prop['SORT']) ? (int)$prop['SORT'] : 500,
                    'WITH_DESCRIPTION' => $prop['WITH_DESCRIPTION'] ?? 'N',
                    'VALUE' => $isMultiple ? [] : null,
                    '~VALUE' => $isMultiple ? [] : null,
                    'VALUE_XML_ID' => $isMultiple ? [] : null,
                ];
            }

            $hasValue = $prop['VALUE'] !== null
                || $prop['~VALUE'] !== null
                || $prop['DESCRIPTION'] !== null
                || $prop['VALUE_XML_ID'] !== null;

            if (!$hasValue) {
                continue;
            }

            if ($properties[$code]['MULTIPLE'] === 'Y') {
                $properties[$code]['VALUE'][] = $prop['VALUE'];
                $properties[$code]['~VALUE'][] = $prop['~VALUE'] ?? $prop['VALUE'];
                $properties[$code]['DESCRIPTION'][] = $prop['DESCRIPTION'] ?? null;
                $properties[$code]['VALUE_XML_ID'][] = $prop['VALUE_XML_ID'] ?? null;
            } else {
                $properties[$code]['VALUE'] = $prop['VALUE'];
                $properties[$code]['~VALUE'] = $prop['~VALUE'] ?? $prop['VALUE'];
                $properties[$code]['DESCRIPTION'] = $prop['DESCRIPTION'] ?? null;
                $properties[$code]['VALUE_XML_ID'] = $prop['VALUE_XML_ID'] ?? null;
            }
        }

        return $properties;
    }
}
