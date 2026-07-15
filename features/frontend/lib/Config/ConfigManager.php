<?php

namespace Prospektweb\Frontcalc\Config;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class ConfigManager
{
    private const MODULE_ID = 'prospektweb.calc';
    private const ADMIN_MODULE_ID = 'prospektweb.calc';

    private const IBLOCK_TYPES = [
        'CALC_PRESETS' => 'calculator',
        'CALC_STAGES' => 'calculator_catalog',
        'CALC_SETTINGS' => 'calculator',
        'CALC_CUSTOM_FIELDS' => 'calculator',
        'CALC_MATERIALS' => 'calculator_catalog',
        'CALC_MATERIALS_VARIANTS' => 'calculator_catalog',
        'CALC_OPERATIONS' => 'calculator_catalog',
        'CALC_OPERATIONS_VARIANTS' => 'calculator_catalog',
        'CALC_EQUIPMENT' => 'calculator_catalog',
        'CALC_DETAILS' => 'calculator_catalog',
    ];

    private static array $iblockCache = [];

    public function getProductIblockId(): int
    {
        return (int)Option::get(self::MODULE_ID, 'PRODUCTS_IBLOCK_ID', Option::get(self::ADMIN_MODULE_ID, 'PRODUCT_IBLOCK_ID', 0));
    }

    public function getSkuIblockId(): int
    {
        return (int)Option::get(self::MODULE_ID, 'OFFERS_IBLOCK_ID', Option::get(self::ADMIN_MODULE_ID, 'SKU_IBLOCK_ID', 0));
    }

    public function getIblockId(string $code): int
    {
        if (isset(self::$iblockCache[$code])) {
            return self::$iblockCache[$code];
        }

        $id = (int)Option::get(self::MODULE_ID, 'IBLOCK_' . $code, 0);
        if ($id <= 0) {
            $id = (int)Option::get(self::ADMIN_MODULE_ID, 'IBLOCK_' . $code, 0);
        }
        if ($id <= 0) {
            $id = $this->findIblockId($code);
        }

        self::$iblockCache[$code] = $id;
        return $id;
    }

    public function getAllIblockIds(): array
    {
        $result = [];
        foreach (array_keys(self::IBLOCK_TYPES) as $code) {
            $result[$code] = $this->getIblockId($code);
        }
        return $result;
    }

    private function findIblockId(string $code): int
    {
        $type = self::IBLOCK_TYPES[$code] ?? null;
        if ($type === null || !Loader::includeModule('iblock')) {
            return 0;
        }
        $iblock = \CIBlock::GetList([], ['CODE' => $code, 'TYPE' => $type])->Fetch();
        return $iblock ? (int)$iblock['ID'] : 0;
    }
}
