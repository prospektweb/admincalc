<?php

namespace Prospektweb\Calc\Install;

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Главный класс установки модуля.
 * 
 * @deprecated Этот класс устарел и не используется в стандартном процессе установки.
 *             Все определения инфоблоков и свойств теперь находятся в install/step3.php.
 *             Этот класс оставлен для обратной совместимости, но требует обновления
 *             для работы, так как зависимые классы IblockCreator и PropertyCreator удалены.
 * 
 * @see install/step3.php - актуальная логика установки модуля
 */
class Installer
{
    /** @var string ID модуля */
    protected const MODULE_ID = 'prospektweb.calc';

    /** @var IblockCreator */
    protected IblockCreator $iblockCreator;

    /** @var PropertyCreator */
    protected PropertyCreator $propertyCreator;

    /** @var ConfigManager */
    protected ConfigManager $configManager;

    /** @var array Лог установки */
    protected array $log = [];

    /** @var array Ошибки установки */
    protected array $errors = [];

    public function __construct()
    {
        $this->iblockCreator = new IblockCreator();
        $this->propertyCreator = new PropertyCreator();
        $this->configManager = new ConfigManager();
    }

    /**
     * Выполняет полную установку модуля.
     *
     * @param int  $productIblockId ID инфоблока товаров.
     * @param int  $skuIblockId     ID инфоблока ТП.
         *
     * @return array Результат установки.
     */
    public function install(int $productIblockId, int $skuIblockId): array
    {
        $this->log = [];
        $this->errors = [];

        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            $this->errors[] = 'Не удалось загрузить модули iblock и catalog';
            return $this->getResult();
        }

        // Шаг 1: Создаём типы инфоблоков
        $this->log[] = 'Создание типов инфоблоков...';
        $this->createIblockTypes();

        if (!empty($this->errors)) {
            return $this->getResult();
        }

        // Шаг 2: Создаём инфоблоки
        $this->log[] = 'Создание инфоблоков...';
        $iblockIds = $this->createIblocks();

        if (!empty($this->errors)) {
            return $this->getResult();
        }

        // Шаг 3: Создаём SKU-связи
        $this->log[] = 'Настройка SKU-связей...';
        $this->createSkuRelations($iblockIds);

        // Шаг 4: Добавляем свойства в существующие инфоблоки
        $this->log[] = 'Добавление свойств в инфоблок товаров...';
        $this->addPropertiesToProductIblock($skuIblockId, $iblockIds);

        // Шаг 5: Сохраняем настройки
        $this->log[] = 'Сохранение настроек модуля...';
        $this->saveSettings($productIblockId, $skuIblockId, $iblockIds);

        $this->log[] = 'Установка завершена успешно';

        return $this->getResult();
    }

    /**
     * Создаёт типы инфоблоков.
     */
    protected function createIblockTypes(): void
    {
        $types = [
            ['id' => 'calculator', 'name' => 'Калькуляторы'],
            ['id' => 'calculator_catalog', 'name' => 'Справочники калькуляторов'],
        ];

        foreach ($types as $type) {
            $result = $this->iblockCreator->createIblockType($type['id'], $type['name']);
            if (!$result) {
                $this->errors[] = "Не удалось создать тип инфоблоков {$type['id']}";
            }
        }
    }

    /**
     * Создаёт инфоблоки модуля.
     *
     * @return array Массив [код => id].
     */
    protected function createIblocks(): array
    {
        $iblocks = [];

        // Инфоблоки калькулятора
        $iblocks['CALC_STAGES'] = $this->iblockCreator->createCalcConfigIblock();
        $iblocks['CALC_SETTINGS'] = $this->iblockCreator->createCalcSettingsIblock();

        // Справочники
        $iblocks['CALC_MATERIALS'] = $this->iblockCreator->createMaterialsIblock();
        $iblocks['CALC_MATERIALS_VARIANTS'] = $this->iblockCreator->createMaterialsVariantsIblock();
        $iblocks['CALC_OPERATIONS'] = $this->iblockCreator->createOperationsIblock();
        $iblocks['CALC_OPERATIONS_VARIANTS'] = $this->iblockCreator->createOperationsVariantsIblock();
        $iblocks['CALC_EQUIPMENT'] = $this->iblockCreator->createEquipmentIblock();
        $iblocks['CALC_DETAILS'] = $this->iblockCreator->createDetailsIblock();

        foreach ($iblocks as $code => $id) {
            if ($id <= 0) {
                $this->errors[] = "Не удалось создать инфоблок {$code}";
            } else {
                $this->log[] = "Создан инфоблок {$code} (ID: {$id})";
            }
        }

        return $iblocks;
    }

    /**
     * Создаёт SKU-связи между инфоблоками.
     *
     * @param array $iblockIds Массив ID инфоблоков.
     */
    protected function createSkuRelations(array $iblockIds): void
    {
        $relations = [
            ['CALC_MATERIALS', 'CALC_MATERIALS_VARIANTS'],
            ['CALC_OPERATIONS', 'CALC_OPERATIONS_VARIANTS'],
        ];

        foreach ($relations as [$parentCode, $offersCode]) {
            $parentId = $iblockIds[$parentCode] ?? 0;
            $offersId = $iblockIds[$offersCode] ?? 0;

            if ($parentId > 0 && $offersId > 0) {
                $result = $this->iblockCreator->createSkuRelation($parentId, $offersId);
                if ($result) {
                    $this->log[] = "Создана SKU-связь {$parentCode} -> {$offersCode}";
                }
            }
        }
    }

    /**
     * Добавляет свойства в инфоблок товаров.
     *
     * @param int $skuIblockId ID инфоблока ТП.
     * @param array $iblockIds Массив ID инфоблоков модуля.
     */
    protected function addPropertiesToProductIblock(int $skuIblockId, array $iblockIds): void
    {
        if ($skuIblockId <= 0) {
            $this->errors[] = 'SKU Iblock ID is 0 or negative: ' . $skuIblockId;
            return;
        }

        // Добавляем свойство PRESET
        $presetsId = (int)($iblockIds['CALC_PRESETS'] ?? 0);
        
        $this->log[] = "Попытка создания свойства PRESET: SKU ID={$skuIblockId}, CALC_PRESETS ID={$presetsId}";
        
        if ($presetsId > 0) {
            $propId = $this->propertyCreator->addDetailsVariantsProperty($skuIblockId, $presetsId);
            
            if ($propId > 0) {
                $this->log[] = "Добавлено свойство PRESET в инфоблок ТП (ID свойства: {$propId})";
            } else {
                $this->errors[] = "Не удалось создать свойство PRESET (SKU ID={$skuIblockId}, Link ID={$presetsId})";
            }
        } else {
            $this->errors[] = "CALC_PRESETS iblock ID is empty or 0. Available iblock_ids: " . json_encode(array_keys($iblockIds));
        }
    }

    /**
     * Сохраняет настройки модуля.
     *
     * @param int   $productIblockId ID инфоблока товаров.
     * @param int   $skuIblockId     ID инфоблока ТП.
     * @param array $iblockIds       Массив ID инфоблоков.
     */
    protected function saveSettings(int $productIblockId, int $skuIblockId, array $iblockIds): void
    {
        Option::set(self::MODULE_ID, 'PRODUCT_IBLOCK_ID', $productIblockId);
        Option::set(self::MODULE_ID, 'SKU_IBLOCK_ID', $skuIblockId);

        foreach ($iblockIds as $code => $id) {
            Option::set(self::MODULE_ID, 'IBLOCK_' . $code, $id);
        }
    }

    /**
     * Возвращает результат установки.
     *
     * @return array
     */
    protected function getResult(): array
    {
        return [
            'success' => empty($this->errors),
            'log' => $this->log,
            'errors' => $this->errors,
        ];
    }

    /**
     * Выполняет удаление данных модуля.
     *
     * @return array Результат удаления.
     */
    public function uninstall(): array
    {
        $this->log = [];
        $this->errors = [];

        if (!Loader::includeModule('iblock')) {
            $this->errors[] = 'Не удалось загрузить модуль iblock';
            return $this->getResult();
        }

        // Удаляем инфоблоки
        $iblockCodes = [
            'CALC_STAGES',
            'CALC_SETTINGS',
            'CALC_MATERIALS',
            'CALC_MATERIALS_VARIANTS',
            'CALC_OPERATIONS',
            'CALC_OPERATIONS_VARIANTS',
            'CALC_EQUIPMENT',
            'CALC_DETAILS',
        ];

        foreach ($iblockCodes as $code) {
            $iblockId = (int)Option::get(self::MODULE_ID, 'IBLOCK_' . $code, 0);
            if ($iblockId > 0) {
                if (\CIBlock::Delete($iblockId)) {
                    $this->log[] = "Удалён инфоблок {$code} (ID: {$iblockId})";
                } else {
                    $this->errors[] = "Не удалось удалить инфоблок {$code}";
                }
            }
        }

        // Удаляем типы инфоблоков
        $types = ['calculator', 'calculator_catalog'];
        foreach ($types as $type) {
            if (\CIBlockType::Delete($type)) {
                $this->log[] = "Удалён тип инфоблоков {$type}";
            }
        }

        // Удаляем настройки
        Option::delete(self::MODULE_ID);
        $this->log[] = 'Удалены настройки модуля';

        return $this->getResult();
    }
}
