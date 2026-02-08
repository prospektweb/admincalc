<?php

namespace Prospektweb\Calc\Install;

use Bitrix\Main\Loader;

/**
 * Класс для создания демо-данных.
 * Версия 2.0 - с разделами, SKU, системными полями и ценами
 */
class DemoDataCreator
{
    /** @var int Коэффициент конвертации для расчёта плотности */
    private const DENSITY_CONVERSION_FACTOR = 1000000;

    /** @var string Код валюты */
    private const CURRENCY_CODE = 'RUB';

    /** @var int ID базовой прайс-группы */
    private const BASE_PRICE_GROUP_ID = 1;

    /** @var float Минимальное значение для измерений (защита от деления на ноль) */
    private const MIN_DIMENSION_VALUE = 0.0001;

    /** @var array Созданные элементы */
    protected array $created = [];

    /** @var array Ошибки */
    protected array $errors = [];

    /** @var array Кэш ID разделов */
    protected array $sectionCache = [];

    /** @var array Кэш ID элементов */
    protected array $elementCache = [];

    /** @var array Кэш ID единиц измерения */
    protected array $measureCache = [];

    /**
     * Создаёт демо-данные.
     *
     * @param array $iblockIds Массив ID инфоблоков.
     *
     * @return array Результат.
     */
    public function create(array $iblockIds): array
    {
        $this->created = [];
        $this->errors = [];
        $this->sectionCache = [];
        $this->elementCache = [];
        $this->measureCache = [];

        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            $this->errors[] = 'Не удалось загрузить модули';
            return $this->getResult();
        }

        // Загружаем единицы измерения в кэш
        $this->loadMeasures();

        // Создаём оборудование (нужно для привязки к операциям)
        $this->createEquipment($iblockIds);

        // Создаём материалы
        $this->createMaterials($iblockIds);

        // Создаём операции (бывшие работы)
        $this->createOperations($iblockIds);

        // Создаём настройки калькуляторов (после оборудования)
        $this->createCalcSettings($iblockIds);

        return $this->getResult();
    }

    /**
     * Загружает единицы измерения в кэш.
     */
    protected function loadMeasures(): void
    {
        $this->measureCache = [];
        
        $rsMeasures = \CCatalogMeasure::getList();
        while ($measure = $rsMeasures->Fetch()) {
            $id = (int)$measure['ID'];
            $codeKeys = [];

            // Числовой CODE (если не 0)
            if (!empty($measure['CODE']) && (int)$measure['CODE'] > 0) {
                $codeKey = (string)$measure['CODE'];
                if (!isset($this->measureCache[$codeKey])) {
                    $this->measureCache[$codeKey] = $id;
                }
            }
            
            // SYMBOL_INTL - международный символ (основной ключ)
            if (!empty($measure['SYMBOL_INTL'])) {
                $symbolIntl = strtoupper(trim((string)$measure['SYMBOL_INTL']));
                $codeKeys[] = $symbolIntl;
            }
            
            // Добавляем по SYMBOL_LETTER_INTL
            if (!empty($measure['SYMBOL_LETTER_INTL'])) {
                $codeKeys[] = strtoupper(trim((string)$measure['SYMBOL_LETTER_INTL']));
            }

            foreach ($codeKeys as $key) {
                if (!empty($key) && !isset($this->measureCache[$key])) {
                    $this->measureCache[$key] = $id;
                }
            }
        }
        
        // Добавляем алиасы для кодов, используемых в демо-данных
        // Формат: 'КОД_В_ДЕМО_ДАННЫХ' => 'SYMBOL_INTL_В_БАЗЕ'
        $aliases = [
            'SHEET' => 'SHEET',    // Лист
            'ROLE' => 'ROLE',      // Роль
            'RUN' => 'RUN',        // Прогон
            'ROLL' => 'ROLL',      // Рулон
            'PACK' => 'PACK',      // Упаковка
            'PACKAGE' => 'PACK',   // Упаковка (альтернативное имя)
            'SQM' => 'M2',         // Квадратный метр
            'SQCM' => 'CM2',       // Квадратный сантиметр
            'SQDM' => 'DM2',       // Квадратный дециметр
            'CIRCULATION' => 'TIR', // Тираж
        ];
        
        foreach ($aliases as $alias => $target) {
            if (!isset($this->measureCache[$alias]) && isset($this->measureCache[$target])) {
                $this->measureCache[$alias] = $this->measureCache[$target];
            }
        }
    }

    /**
     * Получает ID единицы измерения по коду.
     *
     * @param string $code Код единицы измерения (например, 'SHEET', 'ROLE', 'RUN')
     * @return int ID единицы измерения или 0, если не найдена
     */
    protected function getMeasureId(string $code): int
    {
        $key = strtoupper(trim($code));
        
        // Сначала ищем в кэше
        if (isset($this->measureCache[$key])) {
            return $this->measureCache[$key];
        }
        
        // Пробуем найти напрямую в базе по SYMBOL_INTL (регистронезависимо)
        $rsMeasure = \CCatalogMeasure::getList(
            [],
            ['SYMBOL_INTL' => strtolower($code)],
            false,
            false,
            ['ID', 'SYMBOL_INTL']
        );
        if ($measure = $rsMeasure->Fetch()) {
            $measureId = (int)$measure['ID'];
            $this->measureCache[$key] = $measureId;
            return $measureId;
        }
        
        // Не нашли - логируем ошибку с доступными ключами
        $this->errors[] = "Единица измерения с кодом '{$code}' не найдена. Доступные ключи: " . implode(', ', array_keys($this->measureCache));
        
        return 0;
    }

    /**
     * Создаёт оборудование.
     *
     * @param array $iblockIds ID инфоблоков.
     */
    protected function createEquipment(array $iblockIds): void
    {
        $equipmentIblockId = $iblockIds['CALC_EQUIPMENT'] ?? 0;

        if ($equipmentIblockId <= 0) {
            return;
        }

        // Структура: Разделы → Элементы оборудования
        $structure = [
            'Печатное оборудование' => [
                'Цифровые лазерные принтеры' => [
                    'Konica Minolta' => [
                        ['NAME' => '2060L', 'CODE' => '2060L'],
                        ['NAME' => '3070L', 'CODE' => '3070L'],
                    ],
                ],
                'Офсетные станки' => [
                    'Ryoby' => [
                        ['NAME' => 'B2', 'CODE' => 'ryoby_b2'],
                    ],
                ],
            ],
            'Постпечатное оборудование' => [
                'Бумагорезательные станки' => [
                    'Wohlenberg' => [
                        ['NAME' => '72', 'CODE' => 'wohlenberg_72'],
                    ],
                ],
                'Ламинаторы' => [
                    'GMP' => [
                        ['NAME' => 'PD480C', 'CODE' => 'pd480c'],
                    ],
                ],
            ],
        ];

        foreach ($structure as $section1Name => $section1Data) {
            $section1Id = $this->getOrCreateSection($equipmentIblockId, $section1Name, 0);

            foreach ($section1Data as $section2Name => $section2Data) {
                $section2Id = $this->getOrCreateSection($equipmentIblockId, $section2Name, $section1Id);

                foreach ($section2Data as $section3Name => $equipmentItems) {
                    $section3Id = $this->getOrCreateSection($equipmentIblockId, $section3Name, $section2Id);

                    foreach ($equipmentItems as $equipment) {
                        $elementId = $this->createOrUpdateProduct(
                            $equipmentIblockId,
                            $equipment['CODE'],
                            $equipment['NAME'],
                            $section3Id,
                            [],
                            [
                                'MEASURE' => 5, // шт. - стандартный ID в Bitrix (TODO: использовать динамическую загрузку)
                            ]
                        );

                        if ($elementId) {
                            $this->elementCache[$equipment['CODE']] = $elementId;
                            $this->created[] = "Оборудование: {$equipment['NAME']} (ID: {$elementId}, CODE: {$equipment['CODE']})";
                        }
                    }
                }
            }
        }
    }

    /**
     * Создаёт материалы.
     *
     * @param array $iblockIds ID инфоблоков.
     */
    protected function createMaterials(array $iblockIds): void
    {
        $materialsIblockId = $iblockIds['CALC_MATERIALS'] ?? 0;
        $variantsIblockId = $iblockIds['CALC_MATERIALS_VARIANTS'] ?? 0;

        if ($materialsIblockId <= 0 || $variantsIblockId <= 0) {
            return;
        }

        // Регистрируем инфоблоки как каталоги
        $this->ensureCatalog($materialsIblockId);
        $this->ensureCatalog($variantsIblockId);

        // Материалы: Бумага
        $paperSection = $this->getOrCreateSection($materialsIblockId, 'Бумага', 0);
        
        // Бумага → Мелованная
        $coatedSection = $this->getOrCreateSection($materialsIblockId, 'Мелованная', $paperSection);
        
        // Бумага → Мелованная → Глянцевая
        $glossySection = $this->getOrCreateSection($materialsIblockId, 'Глянцевая', $coatedSection);
        
        // Товары и SKU для Мелованной глянцевой бумаги
        $this->createMaterialProduct($materialsIblockId, $variantsIblockId, $glossySection, [
            'PRODUCT_NAME' => '150 г/м2',
            'PRODUCT_CODE' => 'coated_gloss_150',
            'VARIANTS' => [
                [
                    'NAME' => '320x470мм',
                    'CODE' => 'coated_gloss_150_320x470',
                    'WIDTH' => 320,
                    'LENGTH' => 470,
                    'HEIGHT' => 0.105,
                    'WEIGHT' => 15,
                    'PURCHASING_PRICE' => 3,
                    'MARKUP' => 1.3,
                    'MEASURE' => 'SHEET',
                ],
            ],
        ]);

        $this->createMaterialProduct($materialsIblockId, $variantsIblockId, $glossySection, [
            'PRODUCT_NAME' => '200 г/м2',
            'PRODUCT_CODE' => 'coated_gloss_200',
            'VARIANTS' => [
                [
                    'NAME' => '320x470мм',
                    'CODE' => 'coated_gloss_200_320x470',
                    'WIDTH' => 320,
                    'LENGTH' => 470,
                    'HEIGHT' => 0.14,
                    'WEIGHT' => 30,
                    'PURCHASING_PRICE' => 5,
                    'MARKUP' => 1.3,
                    'MEASURE' => 'SHEET',
                ],
            ],
        ]);

        // Бумага → ВХИ
        $vhiSection = $this->getOrCreateSection($materialsIblockId, 'ВХИ', $paperSection);
        
        $this->createMaterialProduct($materialsIblockId, $variantsIblockId, $vhiSection, [
            'PRODUCT_NAME' => '80 г/м2',
            'PRODUCT_CODE' => 'vhi_80',
            'VARIANTS' => [
                [
                    'NAME' => '320x470мм',
                    'CODE' => 'vhi_80_320x470',
                    'WIDTH' => 320,
                    'LENGTH' => 470,
                    'HEIGHT' => 0.1,
                    'WEIGHT' => 10.8,
                    'PURCHASING_PRICE' => 3,
                    'MARKUP' => 1.3,
                    'MEASURE' => 'SHEET',
                ],
            ],
        ]);

        $this->createMaterialProduct($materialsIblockId, $variantsIblockId, $vhiSection, [
            'PRODUCT_NAME' => '160 г/м2',
            'PRODUCT_CODE' => 'vhi_160',
            'VARIANTS' => [
                [
                    'NAME' => '320x470мм',
                    'CODE' => 'vhi_160_320x470',
                    'WIDTH' => 320,
                    'LENGTH' => 470,
                    'HEIGHT' => 0.16,
                    'WEIGHT' => 21.6,
                    'PURCHASING_PRICE' => 4,
                    'MARKUP' => 1.3,
                    'MEASURE' => 'SHEET',
                ],
            ],
        ]);

        // Бумага → Самоклеящаяся
        $selfAdhesiveSection = $this->getOrCreateSection($materialsIblockId, 'Самоклеящаяся', $paperSection);
        
        // Бумага → Самоклеящаяся → Матовая
        $matteSection = $this->getOrCreateSection($materialsIblockId, 'Матовая', $selfAdhesiveSection);
        
        $this->createMaterialProduct($materialsIblockId, $variantsIblockId, $matteSection, [
            'PRODUCT_NAME' => '80(180) г/м2',
            'PRODUCT_CODE' => 'self_adhesive_80_180',
            'VARIANTS' => [
                [
                    'NAME' => '320x470мм',
                    'CODE' => 'self_adhesive_80_180_320x470',
                    'WIDTH' => 320,
                    'LENGTH' => 470,
                    'HEIGHT' => 0.13,
                    'WEIGHT' => 28.08,
                    'PURCHASING_PRICE' => 10,
                    'MARKUP' => 1.3,
                    'MEASURE' => 'SHEET',
                ],
            ],
        ]);

        // Плёнка
        $filmSection = $this->getOrCreateSection($materialsIblockId, 'Плёнка', 0);
        
        // Плёнка → Ламинационная
        $laminationSection = $this->getOrCreateSection($materialsIblockId, 'Ламинационная', $filmSection);
        
        // Плёнка → Ламинационная → Рулонная
        $rollSection = $this->getOrCreateSection($materialsIblockId, 'Рулонная', $laminationSection);
        
        // Плёнка → Ламинационная → Рулонная → Матовая
        $matteFilmSection = $this->getOrCreateSection($materialsIblockId, 'Матовая', $rollSection);
        
        $this->createMaterialProduct($materialsIblockId, $variantsIblockId, $matteFilmSection, [
            'PRODUCT_NAME' => '30мкм',
            'PRODUCT_CODE' => 'film_lamination_matte_30',
            'VARIANTS' => [
                [
                    'NAME' => '305мм',
                    'CODE' => 'film_lamination_matte_30_305',
                    'WIDTH' => 305,
                    'LENGTH' => 300000,
                    'HEIGHT' => 0.03,
                    'WEIGHT' => 2000,
                    'PURCHASING_PRICE' => 1504,
                    'MARKUP' => 1.3,
                    'MEASURE' => 'ROLE',
                ],
                [
                    'NAME' => '457мм',
                    'CODE' => 'film_lamination_matte_30_457',
                    'WIDTH' => 457,
                    'LENGTH' => 300000,
                    'HEIGHT' => 0.03,
                    'WEIGHT' => 3000,
                    'PURCHASING_PRICE' => 2588,
                    'MARKUP' => 1.3,
                    'MEASURE' => 'ROLE',
                ],
            ],
        ]);

        // Плёнка → Ламинационная → Рулонная → Глянцевая
        $glossyFilmSection = $this->getOrCreateSection($materialsIblockId, 'Глянцевая', $rollSection);
        
        $this->createMaterialProduct($materialsIblockId, $variantsIblockId, $glossyFilmSection, [
            'PRODUCT_NAME' => '30мкм',
            'PRODUCT_CODE' => 'film_lamination_gloss_30',
            'VARIANTS' => [
                [
                    'NAME' => '305мм',
                    'CODE' => 'film_lamination_gloss_30_305',
                    'WIDTH' => 305,
                    'LENGTH' => 300000,
                    'HEIGHT' => 0.03,
                    'WEIGHT' => 2000,
                    'PURCHASING_PRICE' => 1204,
                    'MARKUP' => 1.3,
                    'MEASURE' => 'ROLE',
                ],
                [
                    'NAME' => '457мм',
                    'CODE' => 'film_lamination_gloss_30_457',
                    'WIDTH' => 457,
                    'LENGTH' => 300000,
                    'HEIGHT' => 0.03,
                    'WEIGHT' => 3000,
                    'PURCHASING_PRICE' => 2188,
                    'MARKUP' => 1.3,
                    'MEASURE' => 'ROLE',
                ],
            ],
        ]);
    }

    /**
     * Создаёт операции.
     *
     * @param array $iblockIds ID инфоблоков.
     */
    protected function createOperations(array $iblockIds): void
    {
        $operationsIblockId = $iblockIds['CALC_OPERATIONS'] ?? 0;
        $variantsIblockId = $iblockIds['CALC_OPERATIONS_VARIANTS'] ?? 0;
        $equipmentIblockId = $iblockIds['CALC_EQUIPMENT'] ?? 0;
        $materialsVariantsIblockId = $iblockIds['CALC_MATERIALS_VARIANTS'] ?? 0;

        if ($operationsIblockId <= 0 || $variantsIblockId <= 0) {
            return;
        }

        // Регистрируем инфоблоки как каталоги
        $this->ensureCatalog($operationsIblockId);
        $this->ensureCatalog($variantsIblockId);

        // Создаём единую структуру разделов
        // Печать → Цифровая лазерная
        $printSection = $this->getOrCreateSection($operationsIblockId, 'Печать', 0);
        $digitalLaserSection = $this->getOrCreateSection($operationsIblockId, 'Цифровая лазерная', $printSection);
        
        // Получаем ID оборудования для привязки
        $equipment2060L = $this->elementCache['2060L'] ?? $this->findElementByCode($equipmentIblockId, '2060L');
        $equipment3070L = $this->elementCache['3070L'] ?? $this->findElementByCode($equipmentIblockId, '3070L');
        
        // Проверяем наличие оборудования
        $printEquipment = array_filter([$equipment3070L, $equipment2060L], fn($id) => $id > 0);
        
        if (empty($printEquipment)) {
            $this->errors[] = 'Оборудование для печати не создано, операции будут созданы без привязки';
        }
        
        // Создаём элемент "Листовая печать" с привязкой к оборудованию
        $sheetPrintingId = $this->createOrUpdateProduct(
            $operationsIblockId,
            'sheet_printing',
            'Листовая печать',
            $digitalLaserSection,
            ['SUPPORTED_EQUIPMENT_LIST' => $printEquipment]
        );
        
        if ($sheetPrintingId) {
            $this->elementCache['sheet_printing'] = $sheetPrintingId;
            $this->created[] = "Операция: Листовая печать (ID: {$sheetPrintingId})";
            
            // Создаём варианты для "Листовая печать": 4+0, 4+4, 4+1, 1+0, 1+1
            $variants = [
                ['NAME' => '4+0', 'CODE' => 'sheet_printing_4_0', 'PRICE' => 10],
                ['NAME' => '4+4', 'CODE' => 'sheet_printing_4_4', 'PRICE' => 18],
                ['NAME' => '4+1', 'CODE' => 'sheet_printing_4_1', 'PRICE' => 14],
                ['NAME' => '1+0', 'CODE' => 'sheet_printing_1_0', 'PRICE' => 3],
                ['NAME' => '1+1', 'CODE' => 'sheet_printing_1_1', 'PRICE' => 6],
            ];
            
            $measureId = $this->getMeasureId('RUN');
            
            foreach ($variants as $variant) {
                if ($measureId === 0) {
                    $this->errors[] = "Пропуск SKU '{$variant['NAME']}': единица измерения 'RUN' не найдена";
                    continue;
                }
                
                $variantId = $this->createOrUpdateOffer(
                    $variantsIblockId,
                    $variant['CODE'],
                    $variant['NAME'],
                    $sheetPrintingId,
                    [
                        'WIDTH' => 0,
                        'LENGTH' => 0,
                        'HEIGHT' => 0,
                        'WEIGHT' => 0,
                        'MEASURE' => $measureId,
                    ],
                    [
                        'PURCHASING_PRICE' => $variant['PRICE'],
                        'BASE_PRICE' => $variant['PRICE'] * 3.0,
                    ],
                    0,
                    0,
                    0
                );
                
                if ($variantId) {
                    $this->elementCache[$variant['CODE']] = $variantId;
                    $this->created[] = "  → Вариант: {$variant['NAME']} (ID: {$variantId})";
                }
            }
        }

        // Постпечать → Ламинирование → Рулонное
        $postPrintSection = $this->getOrCreateSection($operationsIblockId, 'Постпечать', 0);
        $laminationSection = $this->getOrCreateSection($operationsIblockId, 'Ламинирование', $postPrintSection);
        $rollSection = $this->getOrCreateSection($operationsIblockId, 'Рулонное', $laminationSection);
        
        // Получаем ID оборудования и материалов для ламинатора
        $equipmentPD480C = $this->elementCache['pd480c'] ?? $this->findElementByCode($equipmentIblockId, 'PD480C');
        $filmGloss = $this->findElementByCode($materialsVariantsIblockId, 'film_lamination_gloss_30_305');
        $filmMatte = $this->findElementByCode($materialsVariantsIblockId, 'film_lamination_matte_30_305');
        
        // Проверяем наличие оборудования
        $laminationEquipment = array_filter([$equipmentPD480C], fn($id) => $id > 0);
        $supportedMaterials = array_filter([$filmGloss, $filmMatte], fn($id) => $id > 0);
        
        if (empty($laminationEquipment)) {
            $this->errors[] = 'Оборудование для ламинирования не создано, операции будут созданы без привязки';
        }
        
        // Создаём элемент "A4+" с привязкой к оборудованию и материалам
        $a4PlusId = $this->createOrUpdateProduct(
            $operationsIblockId,
            'a4_plus_lamination',
            'A4+',
            $rollSection,
            [
                'SUPPORTED_EQUIPMENT_LIST' => $laminationEquipment,
                'SUPPORTED_MATERIALS_VARIANTS_LIST' => $supportedMaterials,
            ]
        );
        
        if ($a4PlusId) {
            $this->elementCache['a4_plus_lamination'] = $a4PlusId;
            $this->created[] = "Операция: A4+ (ID: {$a4PlusId})";
            
            // Создаём варианты для "A4+": для толщин до 60 мкм, для толщин до 120 мкм
            $laminationVariants = [
                ['NAME' => 'для толщин до 60 мкм', 'CODE' => 'a4_plus_60', 'PRICE' => 4],
                ['NAME' => 'для толщин до 120 мкм', 'CODE' => 'a4_plus_120', 'PRICE' => 5],
            ];
            
            $measureId = $this->getMeasureId('SQM');
            
            foreach ($laminationVariants as $variant) {
                if ($measureId === 0) {
                    $this->errors[] = "Пропуск SKU '{$variant['NAME']}': единица измерения 'SQM' не найдена";
                    continue;
                }
                
                $variantId = $this->createOrUpdateOffer(
                    $variantsIblockId,
                    $variant['CODE'],
                    $variant['NAME'],
                    $a4PlusId,
                    [
                        'WIDTH' => 0,
                        'LENGTH' => 0,
                        'HEIGHT' => 0,
                        'WEIGHT' => 0,
                        'MEASURE' => $measureId,
                    ],
                    [
                        'PURCHASING_PRICE' => $variant['PRICE'],
                        'BASE_PRICE' => $variant['PRICE'] * 2.0,
                    ],
                    0,
                    0,
                    0
                );
                
                if ($variantId) {
                    $this->elementCache[$variant['CODE']] = $variantId;
                    $this->created[] = "  → Вариант: {$variant['NAME']} (ID: {$variantId})";
                }
            }
        }
    }

    /**
     * Создаёт настройки калькуляторов (CALC_SETTINGS).
     *
     * @param array $iblockIds ID инфоблоков.
     */
    protected function createCalcSettings(array $iblockIds): void
    {
        $settingsIblockId = $iblockIds['CALC_SETTINGS'] ?? 0;
        $operationsVariantsIblockId = $iblockIds['CALC_OPERATIONS_VARIANTS'] ?? 0;
        $materialsVariantsIblockId = $iblockIds['CALC_MATERIALS_VARIANTS'] ?? 0;

        if ($settingsIblockId <= 0) {
            return;
        }

        // Получаем ID варианта операции "4+0"
        $variant4_0_Id = $this->elementCache['sheet_printing_4_0'] ?? $this->findElementByCode($operationsVariantsIblockId, 'sheet_printing_4_0');
        
        // Получаем ID варианта материала "200 г/м2 → 320x470мм"
        $material200_Id = $this->findElementByCode($materialsVariantsIblockId, 'coated_gloss_200_320x470');

        // Раздел "Цифровая лазерная"
        $digitalLaserSection = $this->getOrCreateSection($settingsIblockId, 'Цифровая лазерная', 0);
        
        // Элемент "Листовая печать" для цифровой лазерной
        $properties = [
            'USE_OPERATION_VARIANT' => $this->getListPropertyValueId($settingsIblockId, 'USE_OPERATION_VARIANT', 'Y'),
            'USE_OPERATION_QUANTITY' => $this->getListPropertyValueId($settingsIblockId, 'USE_OPERATION_QUANTITY', 'Y'),
            'USE_MATERIAL_VARIANT' => $this->getListPropertyValueId($settingsIblockId, 'USE_MATERIAL_VARIANT', 'Y'),
            'USE_MATERIAL_QUANTITY' => $this->getListPropertyValueId($settingsIblockId, 'USE_MATERIAL_QUANTITY', 'Y'),
            'CAN_BE_FIRST' => $this->getListPropertyValueId($settingsIblockId, 'CAN_BE_FIRST', 'Y'),
        ];
        
        // Добавляем ID вариантов, если они найдены
        if ($variant4_0_Id) {
            $properties['DEFAULT_OPERATION_VARIANT'] = $variant4_0_Id;
        }
        if ($material200_Id) {
            $properties['DEFAULT_MATERIAL_VARIANT'] = $material200_Id;
        }
        
        $elementId = $this->createOrUpdateElement(
            $settingsIblockId,
            'digital_laser_sheet',
            'Листовая печать',
            $digitalLaserSection,
            $properties
        );
        
        if ($elementId) {
            $this->created[] = "Настройка калькулятора: Цифровая лазерная → Листовая печать (ID: {$elementId})";
        }

        // Раздел "Ламинация"
        $laminationSection = $this->getOrCreateSection($settingsIblockId, 'Ламинация', 0);
        
        // Элемент "Рулонное ламинирование" для ламинации
        $elementId = $this->createOrUpdateElement(
            $settingsIblockId,
            'roll_lamination',
            'Рулонное ламинирование',
            $laminationSection,
            []
        );
        
        if ($elementId) {
            $this->created[] = "Настройка калькулятора: Ламинация → Рулонное ламинирование (ID: {$elementId})";
        }
    }
    
    /**
     * Получает ID значения списочного свойства по XML_ID.
     *
     * @param int $iblockId ID инфоблока.
     * @param string $propertyCode Код свойства.
     * @param string $xmlId XML_ID значения.
     * @return int|null ID значения или null, если не найдено.
     */
    protected function getListPropertyValueId(int $iblockId, string $propertyCode, string $xmlId): ?int
    {
        $rsProperty = \CIBlockProperty::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode]
        );
        
        if ($arProperty = $rsProperty->Fetch()) {
            $rsPropertyEnum = \CIBlockPropertyEnum::GetList(
                [],
                ['IBLOCK_ID' => $iblockId, 'PROPERTY_ID' => $arProperty['ID'], 'XML_ID' => $xmlId]
            );
            
            if ($arEnum = $rsPropertyEnum->Fetch()) {
                return (int)$arEnum['ID'];
            }
        }
        
        return null;
    }

    /**
     * Создаёт или обновляет элемент инфоблока (без каталога).
     */
    protected function createOrUpdateElement(
        int $iblockId,
        string $code,
        string $name,
        int $sectionId,
        array $properties = []
    ): int {
        // Ищем существующий элемент
        $rsElement = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $code],
            false,
            ['nTopCount' => 1],
            ['ID']
        );

        $el = new \CIBlockElement();

        if ($arElement = $rsElement->Fetch()) {
            // Обновляем существующий
            $elementId = (int)$arElement['ID'];
            
            $fields = [
                'NAME' => $name,
                'IBLOCK_SECTION_ID' => $sectionId,
                'ACTIVE' => 'Y',
            ];

            if (!empty($properties)) {
                $fields['PROPERTY_VALUES'] = $properties;
            }

            $el->Update($elementId, $fields);
        } else {
            // Создаём новый
            $fields = [
                'IBLOCK_ID' => $iblockId,
                'NAME' => $name,
                'CODE' => $code,
                'IBLOCK_SECTION_ID' => $sectionId,
                'ACTIVE' => 'Y',
            ];

            if (!empty($properties)) {
                $fields['PROPERTY_VALUES'] = $properties;
            }

            $elementId = $el->Add($fields);
            
            if (!$elementId) {
                $this->errors[] = "Ошибка создания элемента '{$name}': " . $el->LAST_ERROR;
                return 0;
            }

            $elementId = (int)$elementId;
        }

        return $elementId;
    }

    /**
     * Создаёт товар материала со всеми SKU.
     */
    protected function createMaterialProduct(int $productIblockId, int $variantsIblockId, int $sectionId, array $data): void
    {
        $productId = $this->createOrUpdateProduct(
            $productIblockId,
            $data['PRODUCT_CODE'],
            $data['PRODUCT_NAME'],
            $sectionId
        );

        if (!$productId) {
            return;
        }

        $this->created[] = "Материал: {$data['PRODUCT_NAME']} (ID: {$productId}, CODE: {$data['PRODUCT_CODE']})";

        // Создаём варианты (SKU)
        foreach ($data['VARIANTS'] as $variant) {
            $measureId = $this->getMeasureId($variant['MEASURE']);
            
            // Пропускаем вариант, если единица измерения не найдена
            if ($measureId === 0) {
                $this->errors[] = "Пропуск SKU '{$variant['NAME']}': единица измерения '{$variant['MEASURE']}' не найдена";
                continue;
            }
            
            $variantId = $this->createOrUpdateOffer(
                $variantsIblockId,
                $variant['CODE'],
                $variant['NAME'],
                $productId,
                [
                    'WIDTH' => $variant['WIDTH'],
                    'LENGTH' => $variant['LENGTH'],
                    'HEIGHT' => $variant['HEIGHT'],
                    'WEIGHT' => $variant['WEIGHT'],
                    'MEASURE' => $measureId,
                ],
                [
                    'PURCHASING_PRICE' => $variant['PURCHASING_PRICE'],
                    'BASE_PRICE' => $variant['PURCHASING_PRICE'] * $variant['MARKUP'],
                ],
                $variant['WIDTH'],
                $variant['LENGTH'],
                $variant['WEIGHT']
            );

            if ($variantId) {
                $this->created[] = "  → SKU: {$variant['NAME']} (ID: {$variantId}, CODE: {$variant['CODE']})";
            }
        }
    }

    /**
     * Создаёт товар операции со всеми SKU.
     */
    protected function createOperationProduct(int $productIblockId, int $variantsIblockId, int $sectionId, array $data): void
    {
        $productId = $this->createOrUpdateProduct(
            $productIblockId,
            $data['PRODUCT_CODE'],
            $data['PRODUCT_NAME'],
            $sectionId,
            ['EQUIPMENTS' => $data['EQUIPMENT']]
        );

        if (!$productId) {
            return;
        }

        $this->created[] = "Операция: {$data['PRODUCT_NAME']} (ID: {$productId}, CODE: {$data['PRODUCT_CODE']})";

        // Создаём варианты (SKU)
        foreach ($data['VARIANTS'] as $variant) {
            $measureId = $this->getMeasureId($variant['MEASURE']);
            
            // Пропускаем вариант, если единица измерения не найдена
            if ($measureId === 0) {
                $this->errors[] = "Пропуск SKU '{$variant['NAME']}': единица измерения '{$variant['MEASURE']}' не найдена";
                continue;
            }
            
            $variantId = $this->createOrUpdateOffer(
                $variantsIblockId,
                $variant['CODE'],
                $variant['NAME'],
                $productId,
                [
                    'WIDTH' => $variant['WIDTH'],
                    'LENGTH' => $variant['LENGTH'],
                    'HEIGHT' => $variant['HEIGHT'],
                    'WEIGHT' => 0, // Операции не имеют веса
                    'MEASURE' => $measureId,
                ],
                [
                    'PURCHASING_PRICE' => $variant['PURCHASING_PRICE'],
                    'BASE_PRICE' => $variant['PURCHASING_PRICE'] * $variant['MARKUP'],
                ],
                0, // width - не используется для расчёта плотности операций
                0, // length - не используется для расчёта плотности операций
                0  // weight - операции не имеют веса
            );

            if ($variantId) {
                $this->created[] = "  → SKU: {$variant['NAME']} (ID: {$variantId}, CODE: {$variant['CODE']})";
            }
        }
    }

    /**
     * Создаёт или обновляет раздел инфоблока.
     */
    protected function getOrCreateSection(int $iblockId, string $name, int $parentId): int
    {
        $cacheKey = "{$iblockId}_{$parentId}_{$name}";
        
        if (isset($this->sectionCache[$cacheKey])) {
            return $this->sectionCache[$cacheKey];
        }

        $rsSection = \CIBlockSection::GetList(
            [],
            [
                'IBLOCK_ID' => $iblockId,
                'NAME' => $name,
                'SECTION_ID' => $parentId > 0 ? $parentId : false,
            ],
            false,
            ['ID']
        );

        if ($section = $rsSection->Fetch()) {
            $sectionId = (int)$section['ID'];
            $this->sectionCache[$cacheKey] = $sectionId;
            return $sectionId;
        }

        $bs = new \CIBlockSection();
        $sectionId = $bs->Add([
            'IBLOCK_ID' => $iblockId,
            'NAME' => $name,
            'ACTIVE' => 'Y',
            'IBLOCK_SECTION_ID' => $parentId > 0 ? $parentId : false,
        ]);

        if ($sectionId) {
            $this->sectionCache[$cacheKey] = (int)$sectionId;
            return (int)$sectionId;
        }

        return 0;
    }

    /**
     * Создаёт или обновляет товар (продукт).
     */
    protected function createOrUpdateProduct(
        int $iblockId,
        string $code,
        string $name,
        int $sectionId,
        array $properties = [],
        array $catalogFields = []
    ): int {
        // Ищем существующий элемент
        $rsElement = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $code],
            false,
            ['nTopCount' => 1],
            ['ID']
        );

        $el = new \CIBlockElement();

        if ($arElement = $rsElement->Fetch()) {
            // Обновляем существующий
            $elementId = (int)$arElement['ID'];
            
            $fields = [
                'NAME' => $name,
                'IBLOCK_SECTION_ID' => $sectionId,
                'ACTIVE' => 'Y',
            ];

            if (!empty($properties)) {
                $fields['PROPERTY_VALUES'] = $properties;
            }

            $el->Update($elementId, $fields);
        } else {
            // Создаём новый
            $fields = [
                'IBLOCK_ID' => $iblockId,
                'NAME' => $name,
                'CODE' => $code,
                'IBLOCK_SECTION_ID' => $sectionId,
                'ACTIVE' => 'Y',
            ];

            if (!empty($properties)) {
                $fields['PROPERTY_VALUES'] = $properties;
            }

            $elementId = $el->Add($fields);
            
            if (!$elementId) {
                $this->errors[] = "Ошибка создания товара '{$name}': " . $el->LAST_ERROR;
                return 0;
            }

            $elementId = (int)$elementId;
        }

        // Регистрируем в каталоге
        $arProduct = \CCatalogProduct::GetByID($elementId);
        
        $productFields = [
            'ID' => $elementId,
        ];
        
        if (!empty($catalogFields)) {
            $productFields = array_merge($productFields, $catalogFields);
        }

        if ($arProduct) {
            \CCatalogProduct::Update($elementId, $productFields);
        } else {
            \CCatalogProduct::Add($productFields);
        }

        return $elementId;
    }

    /**
     * Создаёт или обновляет торговое предложение (SKU).
     */
    protected function createOrUpdateOffer(
        int $iblockId,
        string $code,
        string $name,
        int $productId,
        array $catalogFields,
        array $prices,
        float $width,
        float $length,
        float $weight
    ): int {
        // Ищем существующее торговое предложение
        $rsElement = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $code],
            false,
            ['nTopCount' => 1],
            ['ID']
        );

        $el = new \CIBlockElement();

        // Вычисляем плотность (только для материалов с весом)
        $density = 0;
        if ($weight > 0 && $width > self::MIN_DIMENSION_VALUE && $length > self::MIN_DIMENSION_VALUE) {
            $density = round($weight * self::DENSITY_CONVERSION_FACTOR / ($width * $length), 2);
        }

        $properties = [
            'CML2_LINK' => $productId,
        ];
        
        if ($density > 0) {
            $properties['DENSITY'] = $density;
        }

        if ($arElement = $rsElement->Fetch()) {
            // Обновляем существующий
            $elementId = (int)$arElement['ID'];
            
            $fields = [
                'NAME' => $name,
                'ACTIVE' => 'Y',
                'PROPERTY_VALUES' => $properties,
            ];

            $el->Update($elementId, $fields);
        } else {
            // Создаём новый
            $fields = [
                'IBLOCK_ID' => $iblockId,
                'NAME' => $name,
                'CODE' => $code,
                'ACTIVE' => 'Y',
                'PROPERTY_VALUES' => $properties,
            ];

            $elementId = $el->Add($fields);
            
            if (!$elementId) {
                $this->errors[] = "Ошибка создания SKU '{$name}': " . $el->LAST_ERROR;
                return 0;
            }

            $elementId = (int)$elementId;
        }

        // Обновляем каталожные поля
        $arProduct = \CCatalogProduct::GetByID($elementId);
        
        $productFields = array_merge([
            'ID' => $elementId,
        ], $catalogFields);

        if ($arProduct) {
            \CCatalogProduct::Update($elementId, $productFields);
        } else {
            \CCatalogProduct::Add($productFields);
        }

        // Устанавливаем цены
        if (!empty($prices)) {
            $this->setPrice($elementId, $prices['PURCHASING_PRICE'], $prices['BASE_PRICE']);
        }

        return $elementId;
    }

    /**
     * Устанавливает цены для элемента.
     */
    protected function setPrice(int $productId, float $purchasingPrice, float $basePrice): void
    {
        // Базовая цена (используется константа BASE_PRICE_GROUP_ID = 1)
        \CPrice::SetBasePrice($productId, $basePrice, self::CURRENCY_CODE);
        
        // Можно также установить закупочную цену, если есть соответствующий тип цены
        // В Bitrix нет стандартного типа для закупочной цены, используем дополнительные поля каталога
    }

    /**
     * Проверяет и регистрирует инфоблок как каталог.
     */
    protected function ensureCatalog(int $iblockId): void
    {
        $catalog = \CCatalog::GetByID($iblockId);
        if (!$catalog) {
            \CCatalog::Add(['IBLOCK_ID' => $iblockId]);
        }
    }

    /**
     * Находит элемент по коду.
     *
     * @param int $iblockId ID инфоблока.
     * @param string $code Код элемента.
     * @return int|null ID элемента или null, если не найден.
     */
    protected function findElementByCode(int $iblockId, string $code): ?int
    {
        if ($iblockId <= 0) {
            return null;
        }
        
        $rsElement = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $code],
            false,
            ['nTopCount' => 1],
            ['ID']
        );
        
        if ($arElement = $rsElement->Fetch()) {
            return (int)$arElement['ID'];
        }
        
        return null;
    }

    /**
     * Возвращает результат.
     *
     * @return array
     */
    protected function getResult(): array
    {
        return [
            'created' => $this->created,
            'errors' => $this->errors,
        ];
    }
}
