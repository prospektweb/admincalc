<?php

namespace Prospektweb\Calc\Config;

require_once dirname(__DIR__) . '/Services/CatalogRuntimeConfigAuthorityService.php';

use Bitrix\Main\Config\Option;
use Prospektweb\Calc\Services\CatalogRuntimeConfigAuthorityService;

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

    private CatalogRuntimeConfigAuthorityService $runtimeConfigAuthority;

    /** @var array<string,string>|null */
    private ?array $catalogRuntimeSnapshot = null;

    /** @var array<string,int>|null */
    private ?array $calculatorIblockIds = null;

    /** @param array<string,callable> $adapters */
    public function __construct(array $adapters = [])
    {
        $authority = $adapters['runtime_config_authority'] ?? null;
        $this->runtimeConfigAuthority = $authority instanceof CatalogRuntimeConfigAuthorityService
            ? $authority
            : new CatalogRuntimeConfigAuthorityService($adapters);
    }

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

        if ($this->calculatorIblockIds === null) {
            $this->calculatorIblockIds = $this->runtimeConfigAuthority->resolveCalculatorIblockIds(
                self::IBLOCK_TYPES
            );
        }
        return $this->calculatorIblockIds[$code];
    }

    /**
     * Получает ID инфоблока товаров.
     *
     * @return int
     */
    public function getProductIblockId(): int
    {
        return CatalogRuntimeConfigAuthorityService::runtimeIblockId(
            $this->catalogRuntimeSnapshot(),
            'PRODUCTS'
        );
    }

    /**
     * Получает ID инфоблока торговых предложений.
     *
     * @return int
     */
    public function getSkuIblockId(): int
    {
        return CatalogRuntimeConfigAuthorityService::runtimeIblockId(
            $this->catalogRuntimeSnapshot(),
            'OFFERS'
        );
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

    /** @return array<string,string> */
    private function catalogRuntimeSnapshot(): array
    {
        if ($this->catalogRuntimeSnapshot === null) {
            $this->catalogRuntimeSnapshot = $this->runtimeConfigAuthority->captureCatalogSnapshot();
        }
        return $this->catalogRuntimeSnapshot;
    }

}
