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
     * Точные коды инфоблоков модуля. Тип инфоблока является только группировкой
     * в административном интерфейсе Bitrix и не участвует в runtime-идентичности.
     */
    private const IBLOCK_CODES = [
        'CALC_PRESETS',
        'CALC_STAGES',
        'CALC_SETTINGS',
        'CALC_GLOBAL_VALUES',
        'CALC_CUSTOM_FIELDS',
        'CALC_MATERIALS',
        'CALC_MATERIALS_VARIANTS',
        'CALC_SUPPLIERS',
        'CALC_OPERATIONS',
        'CALC_OPERATIONS_VARIANTS',
        'CALC_EQUIPMENT',
        'CALC_DETAILS',
    ];

    private CatalogRuntimeConfigAuthorityService $runtimeConfigAuthority;

    /** @var array<string,string>|null */
    private ?array $catalogRuntimeSnapshot = null;

    /** @var array<string,int> */
    private array $calculatorIblockIds = [];

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
        if (!in_array($code, self::IBLOCK_CODES, true)) {
            throw new \InvalidArgumentException('Unknown calculator iblock code: ' . $code . '.');
        }

        if (!array_key_exists($code, $this->calculatorIblockIds)) {
            $this->calculatorIblockIds[$code] = $this->runtimeConfigAuthority
                ->resolveCalculatorIblockId($code);
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
        foreach (self::IBLOCK_CODES as $code) {
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
