<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Loader;

/**
 * Реестр калькуляторов.
 */
class CalculatorRegistry
{
    /** @var CalculatorInterface[] */
    protected static array $list = [];

    /** @var bool */
    protected static bool $loaded = false;

    /**
     * Группы калькуляторов.
     *
     * @var array
     */
    protected static array $groups = [
        [
            'id' => 'common',
            'title' => 'Общие',
            'calculators' => ['dimensions_weight', 'price_settings'],
        ],
        [
            'id' => 'digital_print',
            'title' => 'Цифровая печать',
            'calculators' => ['digital_sheet'],
        ],
        [
            'id' => 'postpress',
            'title' => 'Постпечатные работы',
            'calculators' => ['roll_lamination'],
        ],
    ];

    /**
     * Загрузить все калькуляторы.
     */
    protected static function loadCalculators(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        // Загружаем встроенные калькуляторы из модуля
        $calculatorsDir = __DIR__ . '/Calculators';

        if (is_dir($calculatorsDir)) {
            foreach (glob($calculatorsDir . '/*.php') as $file) {
                $className = 'Prospektweb\\Calc\\Calculator\\Calculators\\' . pathinfo($file, PATHINFO_FILENAME);
                if (class_exists($className)) {
                    $calc = new $className();
                    if ($calc instanceof CalculatorInterface) {
                        self::$list[$calc->getCode()] = $calc;
                    }
                }
            }
        }

        // Сортировка: dimensions_weight первый
        uasort(self::$list, static function (CalculatorInterface $a, CalculatorInterface $b): int {
            $priorityA = $a->getCode() === 'dimensions_weight' ? 0 : 1;
            $priorityB = $b->getCode() === 'dimensions_weight' ? 0 : 1;

            if ($priorityA === $priorityB) {
                return 0;
            }

            return ($priorityA < $priorityB) ? -1 : 1;
        });
    }

    /**
     * Зарегистрировать калькулятор.
     *
     * @param CalculatorInterface $calculator Калькулятор для регистрации.
     */
    public static function register(CalculatorInterface $calculator): void
    {
        self::loadCalculators();
        self::$list[$calculator->getCode()] = $calculator;
    }

    /**
     * Получить все калькуляторы.
     *
     * @return CalculatorInterface[]
     */
    public static function getAll(): array
    {
        self::loadCalculators();
        return array_values(self::$list);
    }

    /**
     * Получить калькулятор по его коду.
     *
     * @param string $code Код калькулятора.
     *
     * @return CalculatorInterface|null
     */
    public static function getByCode(string $code): ?CalculatorInterface
    {
        self::loadCalculators();
        return self::$list[$code] ?? null;
    }

    /**
     * Возвращает группу калькулятора по его коду.
     *
     * @param string $code Код калькулятора.
     *
     * @return string|null ID группы или null.
     */
    public static function getGroupByCode(string $code): ?string
    {
        foreach (self::$groups as $group) {
            if (in_array($code, $group['calculators'], true)) {
                return $group['id'];
            }
        }

        // Проверяем у самого калькулятора
        $calc = self::getByCode($code);
        if ($calc) {
            return $calc->getGroup();
        }

        return null;
    }

    /**
     * Возвращает список групп калькуляторов с их калькуляторами.
     *
     * @return array Массив групп с калькуляторами.
     */
    public static function getGroupedList(): array
    {
        $allCalculators = self::getAll();
        $calcIndex = [];

        foreach ($allCalculators as $calc) {
            $calcIndex[$calc->getCode()] = $calc;
        }

        $result = [];

        foreach (self::$groups as $group) {
            $groupData = [
                'id' => $group['id'],
                'title' => $group['title'],
                'calculators' => [],
            ];

            foreach ($group['calculators'] as $calcCode) {
                if (isset($calcIndex[$calcCode])) {
                    $calc = $calcIndex[$calcCode];
                    $groupData['calculators'][] = [
                        'CODE' => $calc->getCode(),
                        'TITLE' => $calc->getTitle(),
                        'GROUP' => $group['id'],
                        'OPTIONS' => $calc->getOptionsSpec(),
                        'SUPPORTS_CHAIN' => $calc->supportsChain(),
                        'SUPPORTS_FINALIZATION' => $calc->supportsFinalization(),
                    ];
                }
            }

            if (!empty($groupData['calculators'])) {
                $result[] = $groupData;
            }
        }

        return $result;
    }

    /**
     * Список калькуляторов для JSON API.
     *
     * @return array
     */
    public static function getListForJson(): array
    {
        $result = [];
        foreach (self::getAll() as $calc) {
            $result[] = [
                'CODE' => $calc->getCode(),
                'TITLE' => $calc->getTitle(),
                'GROUP' => $calc->getGroup(),
                'OPTIONS' => $calc->getOptionsSpec(),
                'SUPPORTS_CHAIN' => $calc->supportsChain(),
                'SUPPORTS_FINALIZATION' => $calc->supportsFinalization(),
                'CAN_CHANGE_PRICE' => $calc->canChangePrice(),
                'IS_SYSTEM' => $calc->isSystem(),
            ];
        }
        return $result;
    }

    /**
     * Возвращает информацию о группах для API.
     *
     * @return array Массив групп.
     */
    public static function getGroupsForJson(): array
    {
        $groups = [];

        foreach (self::$groups as $group) {
            $groups[] = [
                'id' => $group['id'],
                'title' => $group['title'],
            ];
        }

        return $groups;
    }

    /**
     * Получить конфигурацию калькулятора для UI.
     *
     * @param string $code Код калькулятора.
     *
     * @return array|null Конфигурация или null.
     */
    public static function getCalculatorConfig(string $code): ?array
    {
        $calc = self::getByCode($code);
        if (!$calc) {
            return null;
        }

        return [
            'code' => $calc->getCode(),
            'title' => $calc->getTitle(),
            'group' => $calc->getGroup(),
            'fields' => $calc->getFieldsConfig(),
            'extraOptions' => $calc->getExtraOptions(),
            'positionConstraints' => $calc->getPositionConstraints(),
            'supportsChain' => $calc->supportsChain(),
            'supportsFinalization' => $calc->supportsFinalization(),
            'canChangePrice' => $calc->canChangePrice(),
            'isSystem' => $calc->isSystem(),
        ];
    }
}
