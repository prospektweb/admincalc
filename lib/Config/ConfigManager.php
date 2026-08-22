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

    /**
     * Карта точных кодов и типов инфоблоков модуля.
     * Runtime не ищет и не перепривязывает инфоблоки по коду: такая
     * операция допустима только в явном installer/activation boundary.
     */
    private const IBLOCK_TYPES = [
        'CALC_PRESETS' => 'calculator',
        'CALC_STAGES' => 'calculator_catalog',
        'CALC_SETTINGS' => 'calculator',
        'CALC_GLOBAL_VALUES' => 'calculator',
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
        $expectedType = self::IBLOCK_TYPES[$code] ?? null;
        if ($expectedType === null) {
            throw new \InvalidArgumentException('Unknown calculator iblock code: ' . $code . '.');
        }

        $optionKey = 'IBLOCK_' . $code;
        $rawId = Option::get(self::MODULE_ID, $optionKey, '');
        if (!is_string($rawId)
            || preg_match('/^[1-9][0-9]*$/D', $rawId) !== 1
            || (string)(int)$rawId !== $rawId) {
            throw new \RuntimeException(
                'Calculator iblock authority is not configured: ' . $code . '.',
                409
            );
        }
        $id = (int)$rawId;

        $this->assertExactIblockTarget($code, $expectedType, $id);

        return $id;
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

    private function assertExactIblockTarget(string $code, string $expectedType, int $id): void
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            throw new \RuntimeException('The iblock module is unavailable.', 409);
        }
        $rows = \CIBlock::GetList(
            ['ID' => 'ASC'],
            ['CODE' => $code, 'TYPE' => $expectedType]
        );
        $first = $rows ? $rows->Fetch() : false;
        $duplicate = $rows ? $rows->Fetch() : false;
        if (!is_array($first)
            || $duplicate !== false
            || (int)($first['ID'] ?? 0) !== $id
            || (string)($first['CODE'] ?? '') !== $code
            || (string)($first['IBLOCK_TYPE_ID'] ?? '') !== $expectedType) {
            throw new \RuntimeException(
                'Calculator iblock authority does not match the exact target: ' . $code . '.',
                409
            );
        }
    }

}
