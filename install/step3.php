<?php
/**
 * Шаг 3 установки: Пошаговый процесс с детальным логированием
 * 
 * Этот файл содержит ВСЮ логику создания инфоблоков и свойств модуля.
 * Все определения инфоблоков и их свойств находятся в этом файле.
 * 
 * Структура:
 * - Функции: installLog, getBitrixError, createIblockTypeWithLog, createIblockWithLog, createSkuRelationWithLog
 * - Шаг 1: Создание типов инфоблоков (calculator, calculator_catalog)
 * - Шаг 2: Создание инфоблоков с свойствами
 * - Шаг 3: Настройка SKU-связей
 * - Шаг 4: Сохранение настроек + демо-данные
 * - Шаг 5: Установка файлов и событий
 * 
 * @version 2.0.0
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

global $APPLICATION;

Loader::includeModule('iblock');
Loader::includeModule('catalog');

$moduleId = 'prospektweb.calc';

// Инициализация сессии
if (! isset($_SESSION['PROSPEKTWEB_CALC_INSTALL'])) {
    $_SESSION['PROSPEKTWEB_CALC_INSTALL'] = [
        'product_iblock_id' => (int)($_REQUEST['PRODUCT_IBLOCK_ID'] ?? 0),
        'sku_iblock_id' => (int)($_REQUEST['SKU_IBLOCK_ID'] ?? 0),
        'create_demo_data' => ($_REQUEST['CREATE_DEMO_DATA'] ?? '') === 'Y',
        'current_step' => 1,
        'iblock_ids' => [],
        'log' => [],
        'errors' => [],
    ];
}

$installData = &$_SESSION['PROSPEKTWEB_CALC_INSTALL'];
$currentStep = (int)($_REQUEST['install_step'] ?? $installData['current_step']);

// Очищаем лог для нового шага
$installData['log'] = [];

// Функция логирования
function installLog(string $message, string $type = 'info'): void
{
    $_SESSION['PROSPEKTWEB_CALC_INSTALL']['log'][] = ['message' => $message, 'type' => $type];
}

// Функция получения ошибки Bitrix
function getBitrixError(): string
{
    global $APPLICATION;
    $ex = $APPLICATION->GetException();
    return $ex ? $ex->GetString() : 'Неизвестная ошибка';
}

// Создание типа инфоблоков
function createIblockTypeWithLog(string $id, string $name): bool
{
    $type = \CIBlockType::GetByID($id)->Fetch();
    if ($type) {
        installLog("Тип инфоблоков '{$id}' уже существует", 'warning');
        return true;
    }

    $arFields = [
        'ID' => $id,
        'SECTIONS' => 'Y',
        'IN_RSS' => 'N',
        'SORT' => 500,
        'LANG' => [
            'ru' => ['NAME' => $name, 'SECTION_NAME' => 'Разделы', 'ELEMENT_NAME' => 'Элементы'],
            'en' => ['NAME' => $name, 'SECTION_NAME' => 'Sections', 'ELEMENT_NAME' => 'Elements'],
        ],
    ];

    $obBlockType = new \CIBlockType();
    $result = $obBlockType->Add($arFields);
    
    if ($result) {
        installLog("Создан тип инфоблоков '{$id}'", 'success');
        return true;
    } else {
        $error = getBitrixError();
        installLog("Ошибка создания типа '{$id}': {$error}", 'error');
        $_SESSION['PROSPEKTWEB_CALC_INSTALL']['errors'][] = "Тип '{$id}': {$error}";
        return false;
    }
}

// Создание инфоблока
function createIblockWithLog(string $typeId, string $code, string $name, array $properties = [], array $options = []): int
{
    installLog("Обработка инфоблока '{$code}'.. .");
    
    $rsIBlock = \CIBlock::GetList([], ['CODE' => $code, 'TYPE' => $typeId]);
    if ($arIBlock = $rsIBlock->Fetch()) {
        $id = (int)$arIBlock['ID'];
        installLog("Инфоблок '{$code}' уже существует (ID: {$id})", 'warning');
        return $id;
    }

    $siteId = \CSite::GetDefSite();

    $arFields = [
        'ACTIVE' => 'Y',
        'NAME' => $name,
        'CODE' => $code,
        'IBLOCK_TYPE_ID' => $typeId,
        'SITE_ID' => [$siteId],
        'SORT' => 500,
        'VERSION' => 2,
        'GROUP_ID' => ['1' => 'X', '2' => 'R'],
    ];
    
    // Добавляем дополнительные опции (например, EDIT_FILE_AFTER)
    if (!empty($options)) {
        $arFields = array_merge($arFields, $options);
    }

    $iblock = new \CIBlock();
    $iblockId = $iblock->Add($arFields);

    if (! $iblockId) {
        $error = getBitrixError();
        installLog("ОШИБКА создания инфоблока '{$code}': {$error}", 'error');
        $_SESSION['PROSPEKTWEB_CALC_INSTALL']['errors'][] = "Инфоблок '{$code}': {$error}";
        return 0;
    }

    installLog("Создан инфоблок '{$code}' (ID: {$iblockId})", 'success');

    // Создаём свойства
    $propsCreated = 0;
    foreach ($properties as $propCode => $propData) {
        $arProperty = [
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'CODE' => $propCode,
            'NAME' => $propData['NAME'],
            'PROPERTY_TYPE' => $propData['TYPE'] ?? 'S',
            'MULTIPLE' => $propData['MULTIPLE'] ?? 'N',
            'SORT' => $propData['SORT'] ?? 500,
        ];

        // Add support for IS_REQUIRED
        if (isset($propData['IS_REQUIRED'])) {
            $arProperty['IS_REQUIRED'] = $propData['IS_REQUIRED'];
        }

        if (isset($propData['USER_TYPE'])) {
            $arProperty['USER_TYPE'] = $propData['USER_TYPE'];
        }
        
        if (isset($propData['COL_COUNT'])) {
            $arProperty['COL_COUNT'] = $propData['COL_COUNT'];
        }
        
        if (isset($propData['LINK_IBLOCK_ID'])) {
            $arProperty['LINK_IBLOCK_ID'] = $propData['LINK_IBLOCK_ID'];
        }
        if (isset($propData['VALUES'])) {
            $arProperty['VALUES'] = $propData['VALUES'];
        }
        if (isset($propData['DEFAULT_VALUE'])) {
            $arProperty['DEFAULT_VALUE'] = $propData['DEFAULT_VALUE'];
        }
        if (isset($propData['HINT'])) {
            $arProperty['HINT'] = $propData['HINT'];
        }
        if (isset($propData['MULTIPLE_CNT'])) {
         $arProperty['MULTIPLE_CNT'] = $propData['MULTIPLE_CNT'];
        }
        if (isset($propData['WITH_DESCRIPTION'])) {
            $arProperty['WITH_DESCRIPTION'] = $propData['WITH_DESCRIPTION'];
        }
        
        $ibp = new \CIBlockProperty();
        if ($ibp->Add($arProperty)) {
            $propsCreated++;
        }
    }
    
    if (count($properties) > 0) {
        installLog("  → Свойства: {$propsCreated}/" . count($properties), $propsCreated === count($properties) ?  'success' : 'warning');
    }

    return $iblockId;
}

// Создание SKU-связи
function createSkuRelationWithLog(int $productIblockId, int $offersIblockId, string $name): bool
{
    if ($productIblockId <= 0 || $offersIblockId <= 0) {
        installLog("Пропуск SKU-связи '{$name}': некорректные ID", 'warning');
        return false;
    }

    installLog("Настройка SKU-связи '{$name}' ({$productIblockId} → {$offersIblockId}).. .");

    $existingInfo = \CCatalogSKU::GetInfoByProductIBlock($productIblockId);
    if ($existingInfo && (int)$existingInfo['IBLOCK_ID'] === $offersIblockId) {
        installLog("SKU-связь '{$name}' уже существует", 'warning');
        return true;
    }

    $propertyCode = 'CML2_LINK';
    $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $offersIblockId, 'CODE' => $propertyCode]);
    
    if (! $rsProperty->Fetch()) {
        $arProperty = [
            'IBLOCK_ID' => $offersIblockId,
            'ACTIVE' => 'Y',
            'CODE' => $propertyCode,
            'NAME' => 'Элемент каталога',
            'PROPERTY_TYPE' => 'E',
            'MULTIPLE' => 'N',
            'LINK_IBLOCK_ID' => $productIblockId,
            'SORT' => 5,
        ];
        $ibp = new \CIBlockProperty();
        $propId = $ibp->Add($arProperty);
        if (! $propId) {
            installLog("Ошибка создания свойства CML2_LINK: " . getBitrixError(), 'error');
            return false;
        }
        installLog("  → Создано свойство CML2_LINK (ID: {$propId})", 'success');
    } else {
        $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $offersIblockId, 'CODE' => $propertyCode]);
        $arProp = $rsProperty->Fetch();
        $propId = $arProp['ID'];
        installLog("  → Свойство CML2_LINK существует (ID: {$propId})", 'warning');
    }

    $arCatalog = [
        'IBLOCK_ID' => $offersIblockId,
        'PRODUCT_IBLOCK_ID' => $productIblockId,
        'SKU_PROPERTY_ID' => $propId,
    ];

    $result = \CCatalog::Add($arCatalog);
    if ($result) {
        installLog("SKU-связь '{$name}' создана", 'success');
        return true;
    } else {
        installLog("Ошибка SKU-связи '{$name}': " . getBitrixError(), 'error');
        return false;
    }
}

// Создание единиц измерения
function createMeasuresWithLog(): bool
{
    if (!\Bitrix\Main\Loader::includeModule('catalog')) {
        installLog("ОШИБКА: Модуль catalog не загружен", 'error');
        return false;
    }

    installLog("Создание единиц измерения...", 'header');

    // ВАЖНО: Используем CCatalogMeasure вместо MeasureTable, 
    // потому что ORM не поддерживает поле SYMBOL_RUS
    $measures = [
        [
            'CODE' => 778,
            'MEASURE_TITLE' => 'Лист',
            'SYMBOL_RUS' => 'л.',
            'SYMBOL_INTL' => 'sheet',
            'SYMBOL_LETTER_INTL' => 'SHT',
            'IS_DEFAULT' => 'N',
        ],
        [
            'CODE' => 779,
            'MEASURE_TITLE' => 'Упаковка',
            'SYMBOL_RUS' => 'уп.',
            'SYMBOL_INTL' => 'pack',
            'SYMBOL_LETTER_INTL' => 'PCK',
            'IS_DEFAULT' => 'N',
        ],
        [
            'CODE' => 780,
            'MEASURE_TITLE' => 'Рулон',
            'SYMBOL_RUS' => 'рул.',
            'SYMBOL_INTL' => 'roll',
            'SYMBOL_LETTER_INTL' => 'ROL',
            'IS_DEFAULT' => 'N',
        ],
        [
            'CODE' => 781,
            'MEASURE_TITLE' => 'Роль',
            'SYMBOL_RUS' => 'роль',
            'SYMBOL_INTL' => 'role',
            'SYMBOL_LETTER_INTL' => 'RLE',
            'IS_DEFAULT' => 'N',
        ],
        [
            'CODE' => 55,
            'MEASURE_TITLE' => 'Квадратный метр',
            'SYMBOL_RUS' => 'м2',
            'SYMBOL_INTL' => 'm2',
            'SYMBOL_LETTER_INTL' => 'MTK',
            'IS_DEFAULT' => 'N',
        ],
        [
            'CODE' => 782,
            'MEASURE_TITLE' => 'Квадратный сантиметр',
            'SYMBOL_RUS' => 'см2',
            'SYMBOL_INTL' => 'cm2',
            'SYMBOL_LETTER_INTL' => 'CMK',
            'IS_DEFAULT' => 'N',
        ],
        [
            'CODE' => 783,
            'MEASURE_TITLE' => 'Квадратный дециметр',
            'SYMBOL_RUS' => 'дм2',
            'SYMBOL_INTL' => 'dm2',
            'SYMBOL_LETTER_INTL' => 'DMK',
            'IS_DEFAULT' => 'N',
        ],
        [
            'CODE' => 999,
            'MEASURE_TITLE' => 'Тираж',
            'SYMBOL_RUS' => 'тираж',
            'SYMBOL_INTL' => 'tir',
            'SYMBOL_LETTER_INTL' => 'CIR',
            'IS_DEFAULT' => 'N',
        ],
        [
            'CODE' => 784,
            'MEASURE_TITLE' => 'Прогон',
            'SYMBOL_RUS' => 'прогон',
            'SYMBOL_INTL' => 'run',
            'SYMBOL_LETTER_INTL' => 'RUN',
            'IS_DEFAULT' => 'N',
        ],
    ];

    $createdCount = 0;
    $updatedCount = 0;
    $skippedCount = 0;
    
    foreach ($measures as $measureData) {
        $symbolIntl = $measureData['SYMBOL_INTL'];
        $measureTitle = $measureData['MEASURE_TITLE'];
        $needCode = (int)$measureData['CODE'];
        
        // Ищем существующую единицу по SYMBOL_INTL
        $rsMeasure = \CCatalogMeasure::getList(
            [],
            ['SYMBOL_INTL' => $symbolIntl],
            false,
            false,
            ['ID', 'CODE', 'MEASURE_TITLE', 'SYMBOL_RUS', 'SYMBOL_INTL']
        );
        
        if ($existing = $rsMeasure->Fetch()) {
            $existingId = (int)$existing['ID'];
            $existingCode = (int)$existing['CODE'];
            $existingSymbolRus = $existing['SYMBOL_RUS'] ?? '';
            
            // Проверяем, нужно ли обновить
            $needUpdate = false;
            $updateFields = [];
            
            if ($existingCode === 0 || $existingCode !== $needCode) {
                $updateFields['CODE'] = $needCode;
                $needUpdate = true;
            }
            
            if ($existingSymbolRus !== $measureData['SYMBOL_RUS']) {
                $updateFields['SYMBOL_RUS'] = $measureData['SYMBOL_RUS'];
                $needUpdate = true;
            }
            
            if ($needUpdate) {
                $updateResult = \CCatalogMeasure::update($existingId, $updateFields);
                if ($updateResult) {
                    $updatedFields = implode(', ', array_keys($updateFields));
                    installLog("  → Обновлена: '{$measureTitle}' (ID: {$existingId}, поля: {$updatedFields})", 'success');
                    $updatedCount++;
                } else {
                    installLog("  → Ошибка обновления: '{$measureTitle}'", 'error');
                }
            } else {
                installLog("  → Существует: '{$measureTitle}' (ID: {$existingId})", 'warning');
                $skippedCount++;
            }
            continue;
        }
        
        // Ищем по числовому CODE
        if ($needCode > 0) {
            $rsByCode = \CCatalogMeasure::getList(
                [],
                ['CODE' => $needCode],
                false,
                false,
                ['ID', 'CODE', 'MEASURE_TITLE', 'SYMBOL_RUS', 'SYMBOL_INTL']
            );
            
            if ($existingByCode = $rsByCode->Fetch()) {
                $existingId = (int)$existingByCode['ID'];
                
                // Обновляем SYMBOL_INTL и SYMBOL_RUS
                $updateFields = [
                    'SYMBOL_INTL' => $measureData['SYMBOL_INTL'],
                    'SYMBOL_RUS' => $measureData['SYMBOL_RUS'],
                    'SYMBOL_LETTER_INTL' => $measureData['SYMBOL_LETTER_INTL'],
                ];
                
                $updateResult = \CCatalogMeasure::update($existingId, $updateFields);
                if ($updateResult) {
                    installLog("  → Обновлена по CODE: '{$measureTitle}' (ID: {$existingId})", 'success');
                    $updatedCount++;
                } else {
                    installLog("  → Ошибка обновления по CODE: '{$measureTitle}'", 'error');
                }
                continue;
            }
        }
        
        // Создаём новую единицу измерения
        $newId = \CCatalogMeasure::add($measureData);
        
        if ($newId) {
            installLog("  → Создана: '{$measureTitle}' (ID: {$newId}, CODE: {$needCode}, RUS: {$measureData['SYMBOL_RUS']})", 'success');
            $createdCount++;
        } else {
            global $APPLICATION;
            $error = $APPLICATION->GetException() ? $APPLICATION->GetException()->GetString() : 'Неизвестная ошибка';
            installLog("  → Ошибка создания '{$measureTitle}': {$error}", 'error');
        }
    }

    $total = count($measures);
    installLog("Итого: создано {$createdCount}, обновлено {$updatedCount}, пропущено {$skippedCount} из {$total}", 
        ($createdCount + $updatedCount + $skippedCount) === $total ? 'success' : 'warning');
    
    return true;
}

// ============= ВЫПОЛНЕНИЕ ШАГОВ =============

$totalSteps = 5;

switch ($currentStep) {
    case 1:
        installLog("ШАГ 1 из {$totalSteps}: СОЗДАНИЕ ТИПОВ ИНФОБЛОКОВ", 'header');
        installLog("Модуль: {$moduleId}");
        installLog("Сайт по умолчанию: " . \CSite::GetDefSite());
        
        createIblockTypeWithLog('calculator', 'Калькуляторы');
        createIblockTypeWithLog('calculator_catalog', 'Справочники калькуляторов');
        
        installLog("--- Шаг 1 выполнен ---", 'header');
        break;

    case 2:
        installLog("ШАГ 2 из {$totalSteps}: СОЗДАНИЕ ИНФОБЛОКОВ", 'header');
        
        $stagesProps = [
            'CALC_SETTINGS' => [
                'NAME' => 'Калькулятор',
                'TYPE' => 'E',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SORT' => 100,
            ],
            'COST' => [
                'NAME' => 'Стоимость этапа',
                'TYPE' => 'N',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SORT' => 150,
            ],
            'OPERATION_VARIANT' => [
                'NAME' => 'Вариант операции',
                'TYPE' => 'E',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'Y',
                'SORT' => 200,
            ],
            'OPERATION_QUANTITY' => [
                'NAME' => 'Операция | Количество',
                'TYPE' => 'N',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'Y',
                'DEFAULT_VALUE' => 1,
                'SORT' => 300,
            ],
            'EQUIPMENT' => [
                'NAME' => 'Оборудование',
                'TYPE' => 'E',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SORT' => 400,
            ],
            'MATERIAL_VARIANT' => [
                'NAME' => 'Вариант материала',
                'TYPE' => 'E',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SORT' => 500,
            ],
            'MATERIAL_QUANTITY' => [
                'NAME' => 'Материал | Количество',
                'TYPE' => 'N',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'Y',
                'DEFAULT_VALUE' => 1,
                'SORT' => 600,
            ],
            'CUSTOM_FIELDS_VALUE' => [
                'NAME' => 'Значения дополнительных полей',
                'TYPE' => 'S',
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 1,
                'WITH_DESCRIPTION' => 'Y',
                'IS_REQUIRED' => 'N',
                'SORT' => 700,
            ],
            'PREV_STAGE' => [
                'NAME' => 'Предыдущий этап',
                'TYPE' => 'E',
                'MULTIPLE' => 'N',
                'MULTIPLE_CNT' => 1,
                'SORT' => 800,
            ],
            'NEXT_STAGE' => [
                'NAME' => 'Следующий этап',
                'TYPE' => 'E',
                'MULTIPLE' => 'N',
                'MULTIPLE_CNT' => 1,
                'SORT' => 900,
            ],
            'OPTIONS_OPERATION' => [
                'NAME' => 'Настройки выбора варианта операции',
                'TYPE' => 'S',
                'SORT' => 1000,
            ],
            'OPTIONS_MATERIAL' => [
                'NAME' => 'Настройки выбора варианта материала',
                'TYPE' => 'S',
                'SORT' => 1010,
            ],
        ];
        
        $settingsProps = [
            'PATH_TO_SCRIPT' => [
                'NAME' => 'Скрипт калькуляции',
                'TYPE' => 'S',
                'USER_TYPE' => 'FileMan',
                'SORT' => 100,
            ],
            'USE_OPERATION_VARIANT' => [
                'NAME' => 'Активировать выбор варианта Операции',
                'TYPE' => 'L',
                'SORT' => 200,
                'IS_REQUIRED' => 'Y',
                'VALUES' => [
                    ['VALUE' => 'Да', 'XML_ID' => 'Y', 'DEF' => 'Y'],
                    ['VALUE' => 'Нет', 'XML_ID' => 'N'],
                ],
            ],
            'DEFAULT_OPERATION_VARIANT' => [
                'NAME' => 'Вариант операции по умолчанию',
                'TYPE' => 'E',
                'SORT' => 250,
            ],
            'USE_OPERATION_QUANTITY' => [
                'NAME' => 'Активировать количество для операций',
                'TYPE' => 'L',
                'SORT' => 300,
                'IS_REQUIRED' => 'Y',
                'VALUES' => [
                    ['VALUE' => 'Да', 'XML_ID' => 'Y', 'DEF' => 'Y'],
                    ['VALUE' => 'Нет', 'XML_ID' => 'N'],
                ],
            ],
            'USE_MATERIAL_VARIANT' => [
                'NAME' => 'Активировать выбор варианта Материала',
                'TYPE' => 'L',
                'SORT' => 400,
                'IS_REQUIRED' => 'Y',
                'VALUES' => [
                    ['VALUE' => 'Да', 'XML_ID' => 'Y', 'DEF' => 'Y'],
                    ['VALUE' => 'Нет', 'XML_ID' => 'N'],
                ],
            ],
            'DEFAULT_MATERIAL_VARIANT' => [
                'NAME' => 'Вариант материала по умолчанию',
                'TYPE' => 'E',
                'SORT' => 450,
            ],
            'USE_MATERIAL_QUANTITY' => [
                'NAME' => 'Активировать количество для материала',
                'TYPE' => 'L',
                'SORT' => 500,
                'IS_REQUIRED' => 'Y',
                'VALUES' => [
                    ['VALUE' => 'Да', 'XML_ID' => 'Y', 'DEF' => 'Y'],
                    ['VALUE' => 'Нет', 'XML_ID' => 'N'],
                ],
            ],
            'CAN_BE_FIRST' => [
                'NAME' => 'Может быть добавлен на первом этапе',
                'TYPE' => 'L',
                'SORT' => 550,
                'VALUES' => [
                    ['VALUE' => 'Да', 'XML_ID' => 'Y'],
                    ['VALUE' => 'Нет', 'XML_ID' => 'N'],
                ],
            ],
            'REQUIRES_BEFORE' => [
                'NAME' => 'Используется после калькулятора',
                'TYPE' => 'E',
                'SORT' => 600,
            ],
            'CUSTOM_FIELDS' => [
                'NAME' => 'Дополнительные поля',
                'TYPE' => 'E',
                'SORT' => 700,
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 3,
                // LINK_IBLOCK_ID будет установлен позже в секции обновления свойств
            ],
            'LOGIC' => [
                'NAME' => 'Логика калькулятора',
                'TYPE' => 'S',
                'SORT' => 800,
            ],
        ];
        
        $materialsProps = [
            'PARAMETRS' => ['NAME' => 'Параметры', 'TYPE' => 'S', 'MULTIPLE' => 'Y', 'MULTIPLE_CNT' => 1],
        ];

        $materialsVariantsProps = [
            'DENSITY' => ['NAME' => 'Плотность', 'TYPE' => 'N'],
            'PARAMETRS' => ['NAME' => 'Параметры', 'TYPE' => 'S', 'MULTIPLE' => 'Y', 'MULTIPLE_CNT' => 1],
        ];

        $operationsProps = [
            'SUPPORTED_EQUIPMENT_LIST' => [
                'NAME' => 'Поддерживаемое оборудование',
                'TYPE' => 'E',
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 1,
                'SORT' => 100,
            ],
            'SUPPORTED_MATERIALS_VARIANTS_LIST' => [
                'NAME' => 'Поддерживаемые варианты материалов',
                'TYPE' => 'E',
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 1,
                'SORT' => 200,
            ],
            'PARAMETRS' => ['NAME' => 'Параметры', 'TYPE' => 'S', 'MULTIPLE' => 'Y', 'MULTIPLE_CNT' => 1, 'SORT' => 500],
        ];

        $operationsVariantsProps = [
            'PARAMETRS' => ['NAME' => 'Параметры', 'TYPE' => 'S', 'MULTIPLE' => 'Y', 'MULTIPLE_CNT' => 1, 'SORT' => 500],
        ];

        $equipmentProps = [
            'FIELDS' => ['NAME' => 'Поля печатной машины', 'TYPE' => 'S'],
            'MIN_WIDTH' => ['NAME' => 'Мин. ширина, мм', 'TYPE' => 'N'],
            'MIN_LENGTH' => ['NAME' => 'Мин. длина, мм', 'TYPE' => 'N'],
            'MAX_WIDTH' => ['NAME' => 'Макс. ширина, мм', 'TYPE' => 'N'],
            'MAX_LENGTH' => ['NAME' => 'Макс. длина, мм', 'TYPE' => 'N'],
            'START_COST' => ['NAME' => 'Стоимость старта', 'TYPE' => 'N'],
            'PARAMETRS' => ['NAME' => 'Параметры', 'TYPE' => 'S', 'MULTIPLE' => 'Y', 'MULTIPLE_CNT' => 1],
        ];

        $customFieldsProps = [
            'FIELD_TYPE' => [
                'NAME' => 'Тип поля',
                'TYPE' => 'L',
                'IS_REQUIRED' => 'Y',
                'SORT' => 100,
                'VALUES' => [
                    ['XML_ID' => 'number', 'VALUE' => 'Число (number)'],
                    ['XML_ID' => 'text', 'VALUE' => 'Текст (text)'],
                    ['XML_ID' => 'checkbox', 'VALUE' => 'Чекбокс (checkbox)'],
                    ['XML_ID' => 'select', 'VALUE' => 'Выпадающий список (select)'],
                ],
            ],
            'DEFAULT_VALUE' => [
                'NAME' => 'Значение по умолчанию',
                'TYPE' => 'S',
                'SORT' => 200,
            ],
            'IS_REQUIRED' => [
                'NAME' => 'Обязательное',
                'TYPE' => 'L',
                'SORT' => 300,
                'VALUES' => [
                    ['XML_ID' => 'Y', 'VALUE' => 'Да'],
                    ['XML_ID' => 'N', 'VALUE' => 'Нет', 'DEF' => 'Y'],
                ],
            ],
            'UNIT' => [
                'NAME' => 'Единица измерения',
                'TYPE' => 'S',
                'SORT' => 400,
                'HINT' => 'Только для типа "Число": мм, шт, %',
            ],
            'MIN_VALUE' => [
                'NAME' => 'Минимальное значение',
                'TYPE' => 'N',
                'SORT' => 500,
                'HINT' => 'Только для типа "Число"',
            ],
            'MAX_VALUE' => [
                'NAME' => 'Максимальное значение',
                'TYPE' => 'N',
                'SORT' => 600,
                'HINT' => 'Только для типа "Число"',
            ],
            'STEP_VALUE' => [
                'NAME' => 'Шаг',
                'TYPE' => 'N',
                'SORT' => 700,
                'HINT' => 'Только для типа "Число"',
            ],
            'MAX_LENGTH' => [
                'NAME' => 'Максимальная длина',
                'TYPE' => 'N',
                'SORT' => 800,
                'HINT' => 'Только для типа "Текст"',
            ],
            'OPTIONS' => [
                'NAME' => 'Варианты выбора (для списка)',
                'TYPE' => 'S',
                'SORT' => 900,
                'MULTIPLE' => 'Y',
                'WITH_DESCRIPTION' => 'Y',
                'HINT' => 'Значение = код опции, Описание = отображаемый текст',
            ],
            'SORT_ORDER' => [
                'NAME' => 'Сортировка',
                'TYPE' => 'N',
                'SORT' => 1000,
                'HINT' => 'Порядок отображения поля',
            ],
        ];

        $detailsProps = [
            'TYPE' => [
                'NAME' => 'Тип',
                'TYPE' => 'L',
                'IS_REQUIRED' => 'Y',
                'SORT' => 100,
                'VALUES' => [
                    ['XML_ID' => 'DETAIL', 'VALUE' => 'Деталь'],
                    ['XML_ID' => 'BINDING', 'VALUE' => 'Скрепление'],
                ],
            ],
            'CALC_STAGES' => [
                'NAME' => 'Этапы калькуляций',
                'TYPE' => 'E',
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 1,
                'SORT' => 110,
                'COL_COUNT' => 1,
            ],
            'DETAILS' => [
                'NAME' => 'Детали группы скрепления',
                'TYPE' => 'E',
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 1,
                'SORT' => 200,
                'COL_COUNT' => 1,
            ],
            'PARAMETRS' => ['NAME' => 'Параметры', 'TYPE' => 'S', 'MULTIPLE' => 'Y', 'MULTIPLE_CNT' => 1, 'SORT' => 220],
        ];



        // Свойства инфоблока:  Сборки для расчётов
        $presetsProps = [
            // Привязки к catalog (CALC_STAGES, CALC_SETTINGS)
            'CALC_STAGES' => [
                'NAME' => 'Этапы калькуляций',
                'TYPE' => 'E',
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 1,
                'SORT' => 200,
            ],
            'CALC_SETTINGS' => [
                'NAME' => 'Настройки калькулятора',
                'TYPE' => 'E',
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 1,
                'SORT' => 300,
            ],
            // Привязки к calculator_catalog
            'CALC_MATERIALS' => [
                'NAME' => 'Материалы',
                'TYPE' => 'E',
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 1,
                'SORT' => 400,
            ],
            'CALC_MATERIALS_VARIANTS' => [
                'NAME' => 'Варианты материалов',
                'TYPE' => 'E',
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 1,
                'SORT' => 500,
            ],
            'CALC_OPERATIONS' => [
                'NAME' => 'Операции',
                'TYPE' => 'E',
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 1,
                'SORT' => 600,
            ],
            'CALC_OPERATIONS_VARIANTS' => [
                'NAME' => 'Варианты операций',
                'TYPE' => 'E',
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 1,
                'SORT' => 700,
            ],
            'CALC_EQUIPMENT' => [
                'NAME' => 'Оборудование',
                'TYPE' => 'E',
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 1,
                'SORT' => 800,
            ],
            'CALC_DETAILS' => [
                'NAME' => 'Детали',
                'TYPE' => 'E',
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 1,
                'SORT' => 900,
            ],
        ];

        $installData['iblock_ids']['CALC_PRESETS'] = createIblockWithLog('calculator', 'CALC_PRESETS', 'Пресеты', $presetsProps);
        $installData['iblock_ids']['CALC_STAGES'] = createIblockWithLog('calculator_catalog', 'CALC_STAGES', 'Этапы', $stagesProps);
        $installData['iblock_ids']['CALC_SETTINGS'] = createIblockWithLog('calculator', 'CALC_SETTINGS', 'Калькуляторы', $settingsProps);
        $installData['iblock_ids']['CALC_MATERIALS'] = createIblockWithLog('calculator_catalog', 'CALC_MATERIALS', 'Материалы', $materialsProps);
        $installData['iblock_ids']['CALC_MATERIALS_VARIANTS'] = createIblockWithLog('calculator_catalog', 'CALC_MATERIALS_VARIANTS', 'Варианты материалов', $materialsVariantsProps);
        $installData['iblock_ids']['CALC_OPERATIONS'] = createIblockWithLog('calculator_catalog', 'CALC_OPERATIONS', 'Операции', $operationsProps);
        $installData['iblock_ids']['CALC_OPERATIONS_VARIANTS'] = createIblockWithLog('calculator_catalog', 'CALC_OPERATIONS_VARIANTS', 'Варианты операций', $operationsVariantsProps);
        $installData['iblock_ids']['CALC_EQUIPMENT'] = createIblockWithLog('calculator_catalog', 'CALC_EQUIPMENT', 'Оборудование', $equipmentProps);
        $installData['iblock_ids']['CALC_CUSTOM_FIELDS'] = createIblockWithLog(
            'calculator', 
            'CALC_CUSTOM_FIELDS', 
            'Дополнительные поля', 
            $customFieldsProps,
            [
                'EDIT_FILE_AFTER' => '/bitrix/admin/prospektweb_calc_custom_field.php',
                'SORT' => 900,
            ]
        );
        $installData['iblock_ids']['CALC_DETAILS'] = createIblockWithLog('calculator_catalog', 'CALC_DETAILS', 'Детали', $detailsProps);

        $created = count(array_filter($installData['iblock_ids'], fn($id) => $id > 0));
        $expected = 10;
        installLog("Создано инфоблоков: {$created}/{$expected}", $created === $expected ? 'success' : 'warning');
        
        // Обновление свойств CALC_SETTINGS с привязками к инфоблокам
        if ($installData['iblock_ids']['CALC_SETTINGS'] > 0) {
            installLog("");
            installLog("Обновление свойств CALC_SETTINGS с привязками к инфоблокам...", 'header');
            
            $settingsIblockId = $installData['iblock_ids']['CALC_SETTINGS'];
            $ibp = new \CIBlockProperty();
            
            // Обновляем DEFAULT_OPERATION_VARIANT
            if ($installData['iblock_ids']['CALC_OPERATIONS'] > 0) {
                $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $settingsIblockId, 'CODE' => 'DEFAULT_OPERATION_VARIANT']);
                if ($arProperty = $rsProperty->Fetch()) {
                    $ibp->Update($arProperty['ID'], ['LINK_IBLOCK_ID' => $installData['iblock_ids']['CALC_OPERATIONS']]);
                    installLog("  → Обновлено свойство DEFAULT_OPERATION_VARIANT", 'success');
                }
            }
            
            // Обновляем DEFAULT_MATERIAL_VARIANT
            if ($installData['iblock_ids']['CALC_MATERIALS'] > 0) {
                $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $settingsIblockId, 'CODE' => 'DEFAULT_MATERIAL_VARIANT']);
                if ($arProperty = $rsProperty->Fetch()) {
                    $ibp->Update($arProperty['ID'], ['LINK_IBLOCK_ID' => $installData['iblock_ids']['CALC_MATERIALS']]);
                    installLog("  → Обновлено свойство DEFAULT_MATERIAL_VARIANT", 'success');
                }
            }
            
            // Обновляем REQUIRES_BEFORE
            $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $settingsIblockId, 'CODE' => 'REQUIRES_BEFORE']);
            if ($arProperty = $rsProperty->Fetch()) {
                $ibp->Update($arProperty['ID'], ['LINK_IBLOCK_ID' => $settingsIblockId]);
                installLog("  → Обновлено свойство REQUIRES_BEFORE", 'success');
            }
            
            // Обновляем CUSTOM_FIELDS
            if ($installData['iblock_ids']['CALC_CUSTOM_FIELDS'] > 0) {
                $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $settingsIblockId, 'CODE' => 'CUSTOM_FIELDS']);
                if ($arProperty = $rsProperty->Fetch()) {
                    $ibp->Update($arProperty['ID'], ['LINK_IBLOCK_ID' => $installData['iblock_ids']['CALC_CUSTOM_FIELDS']]);
                    installLog("  → Обновлено свойство CUSTOM_FIELDS", 'success');
                }
            }
        }
        
        // Обновление свойств CALC_OPERATIONS с привязками к инфоблокам
        if ($installData['iblock_ids']['CALC_OPERATIONS'] > 0) {
            installLog("");
            installLog("Обновление свойств CALC_OPERATIONS с привязками к инфоблокам...", 'header');
            
            $operationsIblockId = $installData['iblock_ids']['CALC_OPERATIONS'];
            $ibp = new \CIBlockProperty();
            
            // Обновляем SUPPORTED_EQUIPMENT_LIST
            if ($installData['iblock_ids']['CALC_EQUIPMENT'] > 0) {
                $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $operationsIblockId, 'CODE' => 'SUPPORTED_EQUIPMENT_LIST']);
                if ($arProperty = $rsProperty->Fetch()) {
                    $ibp->Update($arProperty['ID'], ['LINK_IBLOCK_ID' => $installData['iblock_ids']['CALC_EQUIPMENT']]);
                    installLog("  → Обновлено свойство SUPPORTED_EQUIPMENT_LIST", 'success');
                }
            }
            
            // Обновляем SUPPORTED_MATERIALS_VARIANTS_LIST
            if ($installData['iblock_ids']['CALC_MATERIALS_VARIANTS'] > 0) {
                $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $operationsIblockId, 'CODE' => 'SUPPORTED_MATERIALS_VARIANTS_LIST']);
                if ($arProperty = $rsProperty->Fetch()) {
                    $ibp->Update($arProperty['ID'], ['LINK_IBLOCK_ID' => $installData['iblock_ids']['CALC_MATERIALS_VARIANTS']]);
                    installLog("  → Обновлено свойство SUPPORTED_MATERIALS_VARIANTS_LIST", 'success');
                }
            }
        }
        
        // Обновление свойств CALC_STAGES с привязками к инфоблокам
        if ($installData['iblock_ids']['CALC_STAGES'] > 0) {
            installLog("");
            installLog("Обновление свойств CALC_STAGES с привязками к инфоблокам...", 'header');
            
            $stagesIblockId = $installData['iblock_ids']['CALC_STAGES'];
            $ibp = new \CIBlockProperty();
            
            // Обновляем CALC_SETTINGS
            if ($installData['iblock_ids']['CALC_SETTINGS'] > 0) {
                $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $stagesIblockId, 'CODE' => 'CALC_SETTINGS']);
                if ($arProperty = $rsProperty->Fetch()) {
                    $ibp->Update($arProperty['ID'], ['LINK_IBLOCK_ID' => $installData['iblock_ids']['CALC_SETTINGS']]);
                    installLog("  → Обновлено свойство CALC_SETTINGS", 'success');
                }
            }
            
            // Обновляем OPERATION_VARIANT
            if ($installData['iblock_ids']['CALC_OPERATIONS_VARIANTS'] > 0) {
                $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $stagesIblockId, 'CODE' => 'OPERATION_VARIANT']);
                if ($arProperty = $rsProperty->Fetch()) {
                    $ibp->Update($arProperty['ID'], ['LINK_IBLOCK_ID' => $installData['iblock_ids']['CALC_OPERATIONS_VARIANTS']]);
                    installLog("  → Обновлено свойство OPERATION_VARIANT", 'success');
                }
            }
            
            // Обновляем EQUIPMENT
            if ($installData['iblock_ids']['CALC_EQUIPMENT'] > 0) {
                $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $stagesIblockId, 'CODE' => 'EQUIPMENT']);
                if ($arProperty = $rsProperty->Fetch()) {
                    $ibp->Update($arProperty['ID'], ['LINK_IBLOCK_ID' => $installData['iblock_ids']['CALC_EQUIPMENT']]);
                    installLog("  → Обновлено свойство EQUIPMENT", 'success');
                }
            }
            
            // Обновляем MATERIAL_VARIANT
            if ($installData['iblock_ids']['CALC_MATERIALS_VARIANTS'] > 0) {
                $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $stagesIblockId, 'CODE' => 'MATERIAL_VARIANT']);
                if ($arProperty = $rsProperty->Fetch()) {
                    $ibp->Update($arProperty['ID'], ['LINK_IBLOCK_ID' => $installData['iblock_ids']['CALC_MATERIALS_VARIANTS']]);
                    installLog("  → Обновлено свойство MATERIAL_VARIANT", 'success');
                }
            }

            // Обновляем PREV_STAGE
            $rsPrevStage = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $stagesIblockId, 'CODE' => 'PREV_STAGE']);
            if ($arProperty = $rsPrevStage->Fetch()) {
                $ibp->Update($arProperty['ID'], ['LINK_IBLOCK_ID' => $stagesIblockId]);
                installLog("  → Обновлено свойство PREV_STAGE", 'success');
            }

            // Обновляем NEXT_STAGE
            $rsNextStage = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $stagesIblockId, 'CODE' => 'NEXT_STAGE']);
            if ($arProperty = $rsNextStage->Fetch()) {
                $ibp->Update($arProperty['ID'], ['LINK_IBLOCK_ID' => $stagesIblockId]);
                installLog("  → Обновлено свойство NEXT_STAGE", 'success');
            }
        }
        
        // Обновление свойств CALC_DETAILS с привязками к инфоблокам
        if ($installData['iblock_ids']['CALC_DETAILS'] > 0) {
            installLog("");
            installLog("Обновление свойств CALC_DETAILS с привязками к инфоблокам...", 'header');
            
            $detailsIblockId = $installData['iblock_ids']['CALC_DETAILS'];
            $ibp = new \CIBlockProperty();
            
            // Обновляем CALC_STAGES
            if ($installData['iblock_ids']['CALC_STAGES'] > 0) {
                $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $detailsIblockId, 'CODE' => 'CALC_STAGES']);
                if ($arProperty = $rsProperty->Fetch()) {
                    $ibp->Update($arProperty['ID'], ['LINK_IBLOCK_ID' => $installData['iblock_ids']['CALC_STAGES']]);
                    installLog("  → Обновлено свойство CALC_STAGES", 'success');
                }
            }
            
            // Обновляем DETAILS (self-reference)
            $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $detailsIblockId, 'CODE' => 'DETAILS']);
            if ($arProperty = $rsProperty->Fetch()) {
                $ibp->Update($arProperty['ID'], ['LINK_IBLOCK_ID' => $detailsIblockId]);
                installLog("  → Обновлено свойство DETAILS (self-reference)", 'success');
            }

            if ($installData['iblock_ids']['CALC_PRESETS'] > 0) {
                installLog("");
                installLog("Обновление свойств CALC_PRESETS с привязками к инфоблокам.. .", 'header');
                
                $presetsIblockId = $installData['iblock_ids']['CALC_PRESETS'];
                $ibp = new \CIBlockProperty();
                
                $presetsLinkProperties = [
                    'CALC_STAGES' => 'CALC_STAGES',
                    'CALC_SETTINGS' => 'CALC_SETTINGS',
                    'CALC_MATERIALS' => 'CALC_MATERIALS',
                    'CALC_MATERIALS_VARIANTS' => 'CALC_MATERIALS_VARIANTS',
                    'CALC_OPERATIONS' => 'CALC_OPERATIONS',
                    'CALC_OPERATIONS_VARIANTS' => 'CALC_OPERATIONS_VARIANTS',
                    'CALC_EQUIPMENT' => 'CALC_EQUIPMENT',
                    'CALC_DETAILS' => 'CALC_DETAILS',
                ];
                
                foreach ($presetsLinkProperties as $propCode => $linkIblockCode) {
                    if (isset($installData['iblock_ids'][$linkIblockCode]) && $installData['iblock_ids'][$linkIblockCode] > 0) {
                        $rsProperty = \CIBlockProperty:: GetList([], ['IBLOCK_ID' => $presetsIblockId, 'CODE' => $propCode]);
                        if ($arProperty = $rsProperty->Fetch()) {
                            $ibp->Update($arProperty['ID'], ['LINK_IBLOCK_ID' => $installData['iblock_ids'][$linkIblockCode]]);
                            installLog("  → Обновлено свойство {$propCode}", 'success');
                        }
                    }
                }
            }
        }
        
        // Создание единиц измерения
        installLog("");
        createMeasuresWithLog();
        
        // Включение торгового каталога для CALC_STAGES
        if ($installData['iblock_ids']['CALC_STAGES'] > 0) {
            installLog("");
            installLog("Включение торгового каталога для CALC_STAGES...", 'header');
            
            $stagesIblockId = $installData['iblock_ids']['CALC_STAGES'];
            
            // Проверяем, является ли уже каталогом
            $catalogInfo = \CCatalog::GetByID($stagesIblockId);
            if ($catalogInfo) {
                installLog("  → CALC_STAGES уже является торговым каталогом", 'warning');
            } else {
                // Добавляем в каталоги
                $result = \CCatalog::Add([
                    'IBLOCK_ID' => $stagesIblockId,
                    'YANDEX_EXPORT' => 'N',
                    'SUBSCRIPTION' => 'N',
                    'VAT_ID' => 0,
                ]);
                
                if ($result) {
                    installLog("  → CALC_STAGES успешно добавлен как торговый каталог", 'success');
                } else {
                    $error = getBitrixError();
                    installLog("  → Ошибка добавления CALC_STAGES в каталоги: {$error}", 'error');
                }
            }
        }
        
        // Включение торгового каталога для CALC_PRESETS (Пресеты)
        if ($installData['iblock_ids']['CALC_PRESETS'] > 0) {
            installLog("");
            installLog("Включение торгового каталога для CALC_PRESETS (Пресеты)...", 'header');
            
            $presetsIblockId = $installData['iblock_ids']['CALC_PRESETS'];
            
            // Проверяем, является ли уже каталогом
            $catalogInfo = \CCatalog::GetByID($presetsIblockId);
            if ($catalogInfo) {
                installLog("  → CALC_PRESETS уже является торговым каталогом", 'warning');
            } else {
                // Добавляем в каталоги
                $result = \CCatalog::Add([
                    'IBLOCK_ID' => $presetsIblockId,
                    'YANDEX_EXPORT' => 'N',
                    'SUBSCRIPTION' => 'N',
                    'VAT_ID' => 0,
                ]);
                
                if ($result) {
                    installLog("  → CALC_PRESETS успешно добавлен как торговый каталог", 'success');
                } else {
                    $error = getBitrixError();
                    installLog("  → Ошибка добавления CALC_PRESETS в каталоги: {$error}", 'error');
                }
            }
        }
        
        // Создание валюты PRC
        installLog("");
        installLog("Создание валюты PRC...", 'header');
        
        if (Loader::includeModule('currency')) {
            // Проверяем, существует ли валюта
            $currencyExists = \CCurrency::GetByID('PRC');
            
            if ($currencyExists) {
                installLog("  → Валюта PRC уже существует", 'warning');
                
                // Обновляем параметры валюты
                $updateResult = \CCurrency::Update('PRC', [
                    'SORT' => 999,
                    'AMOUNT_CNT' => 1,
                    'AMOUNT' => 1,
                ]);
                
                if ($updateResult) {
                    installLog("  → Параметры валюты PRC обновлены", 'success');
                }
            } else {
                // Создаём валюту
                $result = \CCurrency::Add([
                    'CURRENCY' => 'PRC',
                    'AMOUNT_CNT' => 1,
                    'AMOUNT' => 1,
                    'SORT' => 999,
                    'BASE' => 'N',
                ]);
                
                if ($result) {
                    installLog("  → Создана валюта PRC", 'success');
                    
                    // Устанавливаем названия для языков
                    $langs = ['ru', 'en'];
                    foreach ($langs as $lang) {
                        \CCurrencyLang::Add([
                            'CURRENCY' => 'PRC',
                            'LID' => $lang,
                            'FORMAT_STRING' => '#',
                            'FULL_NAME' => '%',
                            'DEC_POINT' => '.',
                            'THOUSANDS_SEP' => ' ',
                            'DECIMALS' => 2,
                        ]);
                    }
                    installLog("  → Названия валюты PRC установлены для всех языков", 'success');
                } else {
                    installLog("  → Ошибка создания валюты PRC", 'error');
                }
            }
        } else {
            installLog("  → Модуль currency не загружен, пропуск создания валюты", 'warning');
        }
        
        installLog("--- Шаг 2 выполнен ---", 'header');
        break;

    case 3:
        installLog("ШАГ 3 из {$totalSteps}: НАСТРОЙКА SKU-СВЯЗЕЙ", 'header');

        $ids = $installData['iblock_ids'];
        createSkuRelationWithLog($ids['CALC_MATERIALS'] ??  0, $ids['CALC_MATERIALS_VARIANTS'] ??  0, 'Материалы');
        createSkuRelationWithLog($ids['CALC_OPERATIONS'] ?? 0, $ids['CALC_OPERATIONS_VARIANTS'] ?? 0, 'Операции');
        
        installLog("--- Шаг 3 выполнен ---", 'header');
        break;

    case 4:
        installLog("ШАГ 4 из {$totalSteps}: СОХРАНЕНИЕ НАСТРОЕК", 'header');
        
        foreach ($installData['iblock_ids'] as $code => $id) {
            if ($id > 0) {
                Option::set($moduleId, 'IBLOCK_' . $code, $id);
                installLog("Сохранено: IBLOCK_{$code} = {$id}", 'success');
            }
        }
        
        Option::set($moduleId, 'PRODUCT_IBLOCK_ID', $installData['product_iblock_id']);
        Option::set($moduleId, 'SKU_IBLOCK_ID', $installData['sku_iblock_id']);
        installLog("Сохранено: PRODUCT_IBLOCK_ID = " . $installData['product_iblock_id'], 'success');
        installLog("Сохранено: SKU_IBLOCK_ID = " . $installData['sku_iblock_id'], 'success');

        // Добавление свойства CALC_PRESET в инфоблок товаров (Product)
        $productIblockId = $installData['product_iblock_id'];
        $presetsIblockId = $installData['iblock_ids']['CALC_PRESETS'] ?? 0;

        if ($productIblockId > 0 && $presetsIblockId > 0) {
            installLog("");
            installLog("Добавление свойства CALC_PRESET в инфоблок товаров...", 'header');
            
            $propertyCode = 'CALC_PRESET';
            
            // Проверяем, существует ли свойство
            $rsProperty = \CIBlockProperty::GetList(
                [],
                ['IBLOCK_ID' => $productIblockId, 'CODE' => $propertyCode]
            );
            
            if ($arProperty = $rsProperty->Fetch()) {
                installLog("  → Свойство {$propertyCode} уже существует в инфоблоке товаров (ID: {$arProperty['ID']})", 'warning');
            } else {
                // Создаём свойство
                $arNewProperty = [
                    'IBLOCK_ID' => $productIblockId,
                    'ACTIVE' => 'Y',
                    'CODE' => $propertyCode,
                    'NAME' => 'Пресет калькуляции',
                    'PROPERTY_TYPE' => 'E',
                    'MULTIPLE' => 'N',
                    'MULTIPLE_CNT' => 1,
                    'IS_REQUIRED' => 'N',
                    'SORT' => 500,
                    'COL_COUNT' => 1,
                    'LINK_IBLOCK_ID' => $presetsIblockId,
                ];
                
                $ibp = new \CIBlockProperty();
                $propId = $ibp->Add($arNewProperty);
                
                if ($propId) {
                    installLog("  → Создано свойство {$propertyCode} в инфоблоке товаров (ID: {$propId})", 'success');
                } else {
                    $error = getBitrixError();
                    installLog("  → Ошибка создания свойства {$propertyCode}: {$error}", 'error');
                    $_SESSION['PROSPEKTWEB_CALC_INSTALL']['errors'][] = "Свойство {$propertyCode}: {$error}";
                }
            }
        } else {
            if ($productIblockId <= 0) {
                installLog("  → Пропуск создания CALC_PRESET: Product Iblock ID не задан", 'warning');
            }
            if ($presetsIblockId <= 0) {
                installLog("  → Пропуск создания CALC_PRESET: CALC_PRESETS не создан", 'warning');
            }
        }
        
        // Создание демо-данных (если выбрано)
        if ($installData['create_demo_data']) {
            installLog("");
            installLog("Создание демо-данных...", 'header');
            
            // Добавляем прямой require для DemoDataCreator перед использованием
            $demoDataCreatorPath = __DIR__ . '/../lib/Install/DemoDataCreator.php';
            if (file_exists($demoDataCreatorPath)) {
                require_once $demoDataCreatorPath;
                
                if (class_exists('\\Prospektweb\\Calc\\Install\\DemoDataCreator')) {
                    if (empty($installData['iblock_ids'])) {
                        installLog("ОШИБКА: Инфоблоки не созданы", 'error');
                        $_SESSION['PROSPEKTWEB_CALC_INSTALL']['errors'][] = "Инфоблоки не созданы";
                    } else {
                        $demoCreator = new \Prospektweb\Calc\Install\DemoDataCreator();
                        $demoResult = $demoCreator->create($installData['iblock_ids']);
                        
                        foreach ($demoResult['created'] as $createdMessage) {
                            installLog($createdMessage, 'success');
                        }
                        
                        foreach ($demoResult['errors'] as $errorMessage) {
                            installLog($errorMessage, 'error');
                            $_SESSION['PROSPEKTWEB_CALC_INSTALL']['errors'][] = $errorMessage;
                        }
                        
                        $totalCreated = count($demoResult['created']);
                        installLog("Всего создано элементов: {$totalCreated}", 'success');
                    }
                } else {
                    installLog("Класс DemoDataCreator не найден после загрузки файла", 'error');
                    $_SESSION['PROSPEKTWEB_CALC_INSTALL']['errors'][] = "Класс DemoDataCreator не найден после загрузки файла";
                }
            } else {
                installLog("Файл DemoDataCreator.php не найден: " . $demoDataCreatorPath, 'error');
                $_SESSION['PROSPEKTWEB_CALC_INSTALL']['errors'][] = "Файл DemoDataCreator.php не найден: " . $demoDataCreatorPath;
            }
        }
        
        installLog("--- Шаг 4 выполнен ---", 'header');
        break;

    case 5:
        installLog("ШАГ 5 из {$totalSteps}: УСТАНОВКА ФАЙЛОВ И СОБЫТИЙ", 'header');
        
        if (!class_exists('prospektweb_calc')) {
            include_once __DIR__ . '/index.php';
        }
        
        $moduleClass = new prospektweb_calc();
        
        installLog("Проверка директорий assets...");
        $assetsJsDir = __DIR__ . '/assets/js';
        $assetsCssDir = __DIR__ . '/assets/css';
        $toolsDir = dirname(__DIR__) . '/tools';
        
        if (is_dir($assetsJsDir)) {
            installLog("  → Директория JS найдена: {$assetsJsDir}", 'success');
        } else {
            installLog("  → Директория JS не найдена: {$assetsJsDir}", 'warning');
        }
        
        if (is_dir($assetsCssDir)) {
            installLog("  → Директория CSS найдена: {$assetsCssDir}", 'success');
        } else {
            installLog("  → Директория CSS не найдена: {$assetsCssDir}", 'warning');
        }
        
        if (is_dir($toolsDir)) {
            installLog("  → Директория Tools найдена: {$toolsDir}", 'success');
        } else {
            installLog("  → Директория Tools не найдена: {$toolsDir}", 'warning');
        }
        
        installLog("Копирование файлов...");
        $filesResult = $moduleClass->installFiles();
        if ($filesResult) {
            installLog("  → JS скопированы в /local/js/prospektweb.calc/", 'success');
            installLog("  → CSS скопированы в /local/css/prospektweb.calc/", 'success');
            installLog("  → Tools скопированы в /bitrix/tools/prospektweb.calc/", 'success');
        } else {
            installLog("Некоторые файлы не были скопированы (возможно, отсутствуют исходные директории)", 'warning');
        }
        
        installLog("Регистрация обработчиков событий...");
        installLog("  → main::OnProlog → AdminHandler::onProlog", 'success');
        installLog("  → main::OnAdminTabControlBegin → AdminHandler::onTabControlBegin", 'success');
        installLog("  → main::OnAdminListDisplay → AdminHandler::onAdminListDisplay", 'success');
        installLog("  → iblock::OnAfterIBlockElementUpdate → DependencyHandler::onElementUpdate", 'success');
        $moduleClass->installEvents();
        installLog("Обработчики зарегистрированы", 'success');
        
        // Регистрируем модуль ТОЛЬКО после успешного завершения всех шагов
        installLog("Регистрация модуля в системе.");
        $moduleClass->registerModule();
        installLog("Модуль зарегистрирован", 'success');
        
        installLog("═══ УСТАНОВКА ЗАВЕРШЕНА! ═══", 'header');
        
        unset($_SESSION['PROSPEKTWEB_CALC_INSTALL']);
        break;
}

$installData['current_step'] = $currentStep + 1;

?>

<style>
.install-log {
    background: #1e1e1e;
    color: #d4d4d4;
    padding: 20px;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 13px;
    line-height: 1.8;
    border-radius: 4px;
    max-height: 500px;
    overflow-y: auto;
    margin: 20px 0;
}
.install-log .log-info { color: #d4d4d4; }
.install-log .log-info::before { content: '→ '; }
.install-log .log-success { color: #4ec9b0; }
.install-log .log-success::before { content: '✓ '; }
.install-log .log-warning { color: #dcdcaa; }
.install-log .log-warning::before { content: '⚠ '; }
.install-log .log-error { color: #f14c4c; }
.install-log .log-error::before { content: '✗ '; }
.install-log .log-header { color: #569cd6; font-weight: bold; margin-top: 10px; }
.install-log .log-header::before { content: ''; }
.install-buttons { margin-top: 20px; }
.install-buttons .adm-btn-save { margin-right: 10px; }
</style>

<div class="install-log">
    <?php foreach ($installData['log'] as $entry): ?>
    <div class="log-<?= $entry['type'] ?>"><?= htmlspecialcharsbx($entry['message']) ?></div>
    <?php endforeach; ?>
</div>

<?php if (!empty($installData['errors'])): ?>
<div class="adm-info-message adm-info-message-red">
    <strong>Обнаружены ошибки:</strong>
    <ul>
        <?php foreach ($installData['errors'] as $error): ?>
        <li><?= htmlspecialcharsbx($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="install-buttons">
    <?php if ($currentStep < 5): ?>
    <form action="<?= $APPLICATION->GetCurPage() ?>" method="post" style="display: inline;">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
        <input type="hidden" name="id" value="prospektweb.calc">
        <input type="hidden" name="install" value="Y">
        <input type="hidden" name="step" value="3">
        <input type="hidden" name="install_step" value="<?= $currentStep + 1 ?>">
        <input type="submit" value="Продолжить → Шаг <?= $currentStep + 1 ?>" class="adm-btn-save">
    </form>
    <?php else: ?>
    <form action="<?= $APPLICATION->GetCurPage() ?>" method="post" style="display: inline;">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
        <input type="hidden" name="id" value="prospektweb.calc">
        <input type="hidden" name="install" value="Y">
        <input type="hidden" name="step" value="4">
        <input type="submit" value="Завершить установку" class="adm-btn-save">
    </form>
    <?php endif; ?>
    
    <a href="/bitrix/admin/partner_modules.php?lang=<?= LANGUAGE_ID ?>" class="adm-btn">Отмена</a>
</div>
