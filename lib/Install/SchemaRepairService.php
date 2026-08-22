<?php

namespace Prospektweb\Calc\Install;

/**
 * Read-only schema registry used by diagnostics and explicit installer migrations.
 * Runtime HTTP repair and legacy element migration are intentionally absent.
 */
class SchemaRepairService
{
    /**
     * Реестр свойств, добавленных в модуль после первых установок.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function getPropertySchema(): array
    {
        return [
            'CALC_PRESETS' => [
                'OFFER_NAME_TEMPLATE' => [
                    'NAME' => 'Шаблон названия торгового предложения',
                    'TYPE' => 'S',
                    'USER_TYPE' => 'HTML',
                    'SORT' => 1120,
                    'HINT' => 'Шаблон формируется после выполнения всех этапов расчёта',
                ],
                'PRICE_LIMITS_JSON' => [
                    'NAME' => 'Ограничители наценки и маржи',
                    'TYPE' => 'S',
                    'USER_TYPE' => 'HTML',
                    'SORT' => 1130,
                    'HINT' => 'Версионированный JSON с RUB-ограничителями по типу цены и диапазону количества тиражей',
                ],
                'PRICE_PROFILE_POLICY_JSON' => [
                    'NAME' => 'Условные профили отпускных цен',
                    'TYPE' => 'S',
                    'USER_TYPE' => 'HTML',
                    'SORT' => 1140,
                    'HINT' => 'Версионированные снимки сеток цен, выбираемые по глобальным логическим значениям',
                ],
            ],
            'CALC_SETTINGS' => [
                'AI_CONTEXT_JSON' => [
                    'NAME' => 'Контекст AI-конструктора',
                    'TYPE' => 'S',
                    'USER_TYPE' => 'HTML',
                    'SORT' => 820,
                    'HINT' => 'Базисные продукты, описания источников, ожидаемые результаты и инструкции AI',
                ],
                'GLOBAL_DEPENDENCIES' => [
                    'NAME' => 'Контракт глобальных значений',
                    'TYPE' => 'S',
                    'MULTIPLE' => 'Y',
                    'MULTIPLE_CNT' => 1,
                    'SORT' => 830,
                    'HINT' => 'Коды глобальных значений, на которые ссылаются формулы калькулятора',
                ],
            ],
            'CALC_STAGES' => [
                'OPTIONS_OPERATION' => [
                    'NAME' => 'Сопоставление варианта операции по входам формы',
                    'TYPE' => 'S',
                    'USER_TYPE' => 'HTML',
                    'SORT' => 800,
                    'HINT' => 'prospektweb.calc.stage-variant-mapping/v1; только ID полей и вариантов формы',
                ],
                'OPTIONS_MATERIAL' => [
                    'NAME' => 'Сопоставление варианта материала по входам формы',
                    'TYPE' => 'S',
                    'USER_TYPE' => 'HTML',
                    'SORT' => 810,
                    'HINT' => 'prospektweb.calc.stage-variant-mapping/v1; только ID полей и вариантов формы',
                ],
                'OPTIONS_EQUIPMENT' => [
                    'NAME' => 'Сопоставление оборудования по входам формы',
                    'TYPE' => 'S',
                    'USER_TYPE' => 'HTML',
                    'SORT' => 820,
                    'HINT' => 'prospektweb.calc.stage-variant-mapping/v1; только ID полей и вариантов формы',
                ],
                'ACTIVATION_CONDITION' => [
                    'NAME' => 'Условие активации этапа',
                    'TYPE' => 'S',
                    'SORT' => 190,
                    'HINT' => 'Версионированный JSON со ссылкой на глобальную переменную или константу',
                ],
                'USED_ENTITYS' => [
                    'NAME' => 'Используемые сущности этапа',
                    'TYPE' => 'L',
                    'MULTIPLE' => 'Y',
                    'MULTIPLE_CNT' => 3,
                    'SORT' => 600,
                    'VALUES' => [
                        ['VALUE' => 'Операция', 'XML_ID' => 'VARIANT_OPERATION'],
                        ['VALUE' => 'Оборудование', 'XML_ID' => 'EQUIPMENT'],
                        ['VALUE' => 'Материал', 'XML_ID' => 'VARIANT_MATERIAL'],
                    ],
                ],
                'USED_ENTITY_CODES' => [
                    'NAME' => 'Коды используемых сущностей этапа',
                    'TYPE' => 'S',
                    'MULTIPLE' => 'Y',
                    'MULTIPLE_CNT' => 3,
                    'SORT' => 605,
                    'HINT' => 'Стабильные XML-коды типов сущностей; не зависят от ID значений списка Bitrix',
                ],
                'CUSTOM_FIELDS' => [
                    'NAME' => 'Дополнительные поля этапа',
                    'TYPE' => 'E',
                    'MULTIPLE' => 'Y',
                    'MULTIPLE_CNT' => 3,
                    'SORT' => 690,
                    'LINK_IBLOCK_CODE' => 'CALC_CUSTOM_FIELDS',
                ],
                'STAGE_OWNERSHIP_VERSION' => [
                    'NAME' => 'Версия владения конфигурацией этапа',
                    'TYPE' => 'N',
                    'SORT' => 695,
                ],
                'CONTRACT_ISSUE' => [
                    'NAME' => 'Нарушение контракта калькулятора',
                    'TYPE' => 'S',
                    'SORT' => 700,
                    'HINT' => 'Причина блокировки расчёта после несовместимого изменения общего калькулятора',
                ],
            ],
            'CALC_MATERIALS' => [
                'SOURCE_LINKS' => self::sourceLinksProperty(),
            ],
            'CALC_MATERIALS_VARIANTS' => [
                'SOURCE_LINKS' => self::sourceLinksProperty(),
            ],
            'CALC_EQUIPMENT' => [
                'SOURCE_LINKS' => self::sourceLinksProperty(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function sourceLinksProperty(): array
    {
        return [
            'NAME' => 'Ссылки на источники данных',
            'TYPE' => 'S',
            'MULTIPLE' => 'Y',
            'MULTIPLE_CNT' => 1,
            'WITH_DESCRIPTION' => 'Y',
            'SORT' => 510,
        ];
    }

}
