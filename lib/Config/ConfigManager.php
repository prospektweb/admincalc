<?php

namespace Prospektweb\Calc\Config;

use Bitrix\Main\Config\Option;

/**
 * Менеджер конфигурации модуля.
 */
class ConfigManager
{
    /** @var string ID модуля */
    protected const MODULE_ID = 'prospektweb.calc';

    /** @var array Кеш ID инфоблоков */
    protected static array $iblockCache = [];

    /**
     * Карта кодов инфоблоков модуля и типов, в которых они создаются.
     * Используется как fallback, если ID не сохранён в настройках.
     */
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

    /**
     * Получает ID инфоблока по коду.
     *
     * @param string $code Код инфоблока.
     *
     * @return int ID инфоблока.
     */
    public function getIblockId(string $code): int
    {
        if (isset(self::$iblockCache[$code])) {
            return self::$iblockCache[$code];
        }

        $optionKey = 'IBLOCK_' . $code;
        $id = (int)Option::get(self::MODULE_ID, $optionKey, 0);

        if ($id <= 0) {
            $resolvedId = $this->findIblockId($code);
            if ($resolvedId > 0) {
                $id = $resolvedId;
                Option::set(self::MODULE_ID, $optionKey, $resolvedId);
            }
        }

        self::$iblockCache[$code] = $id;

        return $id;
    }

    /**
     * Устанавливает ID инфоблока.
     *
     * @param string $code Код инфоблока.
     * @param int    $id   ID инфоблока.
     */
    public function setIblockId(string $code, int $id): void
    {
        Option::set(self::MODULE_ID, 'IBLOCK_' . $code, $id);
        self::$iblockCache[$code] = $id;
    }

    /**
     * Получает ID инфоблока товаров.
     *
     * @return int
     */
    public function getProductIblockId(): int
    {
        return (int)Option::get(self::MODULE_ID, 'PRODUCT_IBLOCK_ID', 0);
    }

    /**
     * Получает ID инфоблока торговых предложений.
     *
     * @return int
     */
    public function getSkuIblockId(): int
    {
        return (int)Option::get(self::MODULE_ID, 'SKU_IBLOCK_ID', 0);
    }

    /**
     * Получает настройку модуля.
     *
     * @param string $name    Имя настройки.
     * @param mixed  $default Значение по умолчанию.
     *
     * @return mixed
     */
    public function getOption(string $name, $default = null)
    {
        return Option::get(self::MODULE_ID, $name, $default);
    }

    /**
     * Устанавливает настройку модуля.
     *
     * @param string $name  Имя настройки.
     * @param mixed  $value Значение.
     */
    public function setOption(string $name, $value): void
    {
        Option::set(self::MODULE_ID, $name, $value);
    }

    /**
     * Получает все ID инфоблоков модуля.
     *
     * @return array Массив [код => id].
     */
    public function getAllIblockIds(): array
    {
        $result = [];
        foreach (array_keys(self::IBLOCK_TYPES) as $code) {
            $result[$code] = $this->getIblockId($code);
        }

        return $result;
    }

    /**
     * Очищает кеш ID инфоблоков.
     */
    public function clearCache(): void
    {
        self::$iblockCache = [];
    }

    /**
     * Пытается определить ID инфоблока по его коду и типу.
     */
    private function findIblockId(string $code): int
    {
        $type = self::IBLOCK_TYPES[$code] ?? null;
        if ($type === null || !\Bitrix\Main\Loader::includeModule('iblock')) {
            return 0;
        }

        $iblock = \CIBlock::GetList([], ['CODE' => $code, 'TYPE' => $type])->Fetch();

        return $iblock ? (int)$iblock['ID'] : 0;
    }
}
