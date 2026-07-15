<?php

namespace Prospektweb\PropValManager\Service;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;

final class PropertyDescriptionService
{
    public const CACHE_DIR = '/prospektweb/propvalmanager/property_value_descriptions';
    public const CACHE_TTL = 86400;

    /**
     * @param array<int|string, mixed> $properties
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function getDescriptions(array $properties, int $iblockId = 0): array
    {
        $keys = self::collectKeys($properties, $iblockId);
        if (empty($keys)) {
            return [];
        }

        $cacheKey = 'descriptions_' . md5(serialize($keys));
        $cache = Cache::createInstance();
        if ($cache->initCache(self::CACHE_TTL, $cacheKey, self::CACHE_DIR)) {
            $vars = $cache->getVars();
            return is_array($vars) ? $vars : [];
        }

        $result = self::loadDescriptions($keys);
        if ($cache->startDataCache()) {
            $cache->endDataCache($result);
        }

        return $result;
    }

    /** @param array<int|string, mixed> $properties */
    public static function clearCache(): void
    {
        $cache = Cache::createInstance();
        $cache->cleanDir(self::CACHE_DIR);
    }

    /**
     * @param array<int|string, mixed> $properties
     * @return array<int, array{IBLOCK_ID:int,PROPERTY_ID:int,PROPERTY_CODE:string,VALUE_XML_ID:string}>
     */
    private static function collectKeys(array $properties, int $iblockId = 0): array
    {
        $keys = [];

        foreach ($properties as $code => $property) {
            if (!is_array($property)) {
                continue;
            }

            $propertyCode = (string)($property['CODE'] ?? (is_string($code) ? $code : ''));
            $propertyId = (int)($property['ID'] ?? $property['PROPERTY_ID'] ?? 0);
            $propertyIblockId = (int)($property['IBLOCK_ID'] ?? $iblockId);
            $xmlIds = self::extractXmlIds($property);

            if ($propertyIblockId <= 0 || $propertyId <= 0 || $propertyCode === '' || empty($xmlIds)) {
                continue;
            }

            foreach ($xmlIds as $xmlId) {
                $key = $propertyIblockId . ':' . $propertyId . ':' . $xmlId;
                $keys[$key] = [
                    'IBLOCK_ID' => $propertyIblockId,
                    'PROPERTY_ID' => $propertyId,
                    'PROPERTY_CODE' => $propertyCode,
                    'VALUE_XML_ID' => $xmlId,
                ];
            }
        }

        ksort($keys);
        return array_values($keys);
    }

    /** @param array<string, mixed> $property @return string[] */
    private static function extractXmlIds(array $property): array
    {
        $values = [];
        foreach (['VALUE_XML_ID', 'XML_ID', 'VALUE_ENUM_XML_ID'] as $field) {
            if (!array_key_exists($field, $property)) {
                continue;
            }

            $raw = $property[$field];
            if (is_array($raw)) {
                foreach ($raw as $item) {
                    $item = (string)$item;
                    if ($item !== '') {
                        $values[$item] = $item;
                    }
                }
            } else {
                $raw = (string)$raw;
                if ($raw !== '') {
                    $values[$raw] = $raw;
                }
            }
        }

        return array_values($values);
    }

    /** @param mixed $value @return mixed */
    private static function firstValue($value)
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ((string)$item !== '') {
                    return $item;
                }
            }

            return '';
        }

        return $value;
    }


    /** @param mixed $entity @param string[] $fields @return string[] */
    private static function filterSelectFields($entity, array $fields): array
    {
        if (!method_exists($entity, 'hasField')) {
            return $fields;
        }

        $result = [];
        foreach ($fields as $field) {
            if ($entity->hasField($field)) {
                $result[] = $field;
            }
        }

        return $result;
    }

    /**
     * @param array<int, array{IBLOCK_ID:int,PROPERTY_ID:int,PROPERTY_CODE:string,VALUE_XML_ID:string}> $keys
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function loadDescriptions(array $keys): array
    {
        if (!Loader::includeModule('highloadblock')) {
            return [];
        }

        $hlBlock = (new PropertyValueDescriptionInstaller())->getHighloadBlock();
        if (!$hlBlock) {
            return [];
        }

        $entity = HighloadBlockTable::compileEntity($hlBlock);
        $dataClass = $entity->getDataClass();
        $select = self::filterSelectFields($entity, [
            'ID', 'UF_IBLOCK_ID', 'UF_PROPERTY_ID', 'UF_PROPERTY_CODE', 'UF_VALUE_XML_ID', 'UF_TITLE',
            'UF_DESCRIPTION', 'UF_IMAGE', 'UF_LINK', 'UF_LINK_TEXT', 'UF_LINK_TARGET', 'UF_SORT',
        ]);

        $iblockIds = [];
        $propertyIds = [];
        $xmlIds = [];
        $wanted = [];
        foreach ($keys as $key) {
            $iblockIds[$key['IBLOCK_ID']] = $key['IBLOCK_ID'];
            $propertyIds[$key['PROPERTY_ID']] = $key['PROPERTY_ID'];
            $xmlIds[$key['VALUE_XML_ID']] = $key['VALUE_XML_ID'];
            $wanted[$key['IBLOCK_ID'] . ':' . $key['PROPERTY_ID'] . ':' . $key['VALUE_XML_ID']] = $key['PROPERTY_CODE'];
        }

        $rows = $dataClass::getList([
            'filter' => [
                '=UF_ACTIVE' => 1,
                '@UF_IBLOCK_ID' => array_values($iblockIds),
                '@UF_PROPERTY_ID' => array_values($propertyIds),
                '@UF_VALUE_XML_ID' => array_values($xmlIds),
            ],
            'select' => $select,
            'order' => ['UF_SORT' => 'ASC', 'ID' => 'ASC'],
        ]);

        $result = [];
        while ($row = $rows->fetch()) {
            $compound = (int)$row['UF_IBLOCK_ID'] . ':' . (int)$row['UF_PROPERTY_ID'] . ':' . (string)$row['UF_VALUE_XML_ID'];
            if (!isset($wanted[$compound])) {
                continue;
            }

            $propertyCode = (string)($row['UF_PROPERTY_CODE'] ?: $wanted[$compound]);
            $valueXmlId = (string)$row['UF_VALUE_XML_ID'];
            $link = (string)self::firstValue($row['UF_LINK'] ?? '');
            $linkText = (string)self::firstValue($row['UF_LINK_TEXT'] ?? '');
            $linkTarget = (string)self::firstValue($row['UF_LINK_TARGET'] ?? '');

            $result[$propertyCode][$valueXmlId] = [
                'TITLE' => (string)($row['UF_TITLE'] ?? ''),
                'DESCRIPTION' => (string)($row['UF_DESCRIPTION'] ?? ''),
                'IMAGE' => (int)($row['UF_IMAGE'] ?? 0),
                'LINK' => $link,
                'LINK_TEXT' => $linkText !== '' ? $linkText : $link,
                'LINK_TARGET' => $linkTarget === '_blank' ? '_blank' : '_self',
            ];
        }

        return $result;
    }
}
