<?php

namespace Prospektweb\Calc\Install;

use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Безопасно восстанавливает отсутствующие свойства инфоблоков модуля.
 *
 * Существующие свойства, их настройки и данные элементов не изменяются.
 */
class SchemaRepairService
{
    private const STAGE_OWNERSHIP_VERSION = 5;

    /** @var array<int, array<string, int>> */
    private array $listEnumIdsByProperty = [];

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
                'GLOBAL_ASSIGNMENTS' => [
                    'NAME' => 'Определения глобальных значений этапа',
                    'TYPE' => 'S',
                    'USER_TYPE' => 'HTML',
                    'SORT' => 180,
                    'HINT' => 'JSON назначений глобальных переменных и однократных определений констант',
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
     * Создаёт только отсутствующие свойства.
     *
     * @return array<string, mixed>
     */
    public function repairMissingProperties(): array
    {
        $result = [
            'created' => [],
            'existing' => [],
            'errors' => [],
        ];

        if (!Loader::includeModule('iblock')) {
            $result['errors'][] = 'Модуль «Информационные блоки» не подключён';

            return $this->withCounts($result);
        }

        $configManager = new ConfigManager();

        foreach (self::getPropertySchema() as $iblockCode => $properties) {
            $iblockId = $configManager->getIblockId($iblockCode);
            if ($iblockId <= 0) {
                $result['errors'][] = sprintf('Инфоблок %s не найден', $iblockCode);
                continue;
            }

            foreach ($properties as $propertyCode => $definition) {
                if (!empty($definition['LINK_IBLOCK_CODE'])) {
                    $definition['LINK_IBLOCK_ID'] = $configManager->getIblockId((string)$definition['LINK_IBLOCK_CODE']);
                }
                $propertyLabel = $iblockCode . '.' . $propertyCode;
                $existing = \CIBlockProperty::GetList(
                    [],
                    ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode]
                )->Fetch();

                if ($existing) {
                    $this->ensureListPropertyValues((int)$existing['ID'], $definition);
                    $result['existing'][] = $propertyLabel;
                    continue;
                }

                $property = new \CIBlockProperty();
                $propertyId = $property->Add($this->buildPropertyFields(
                    $iblockId,
                    $propertyCode,
                    $definition
                ));

                if ($propertyId) {
                    $this->ensureListPropertyValues((int)$propertyId, $definition);
                    $result['created'][] = $propertyLabel;
                    continue;
                }

                $result['errors'][] = sprintf(
                    'Не удалось создать %s: %s',
                    $propertyLabel,
                    trim((string)$property->LAST_ERROR) ?: 'неизвестная ошибка'
                );
            }
        }

        $result['migratedStageCount'] = empty($result['errors'])
            ? $this->migrateLegacyStageOwnership($configManager)
            : 0;

        return $this->withCounts($result);
    }

    /**
     * Runtime-safe repair for installations upgraded without rerunning the installer.
     * Does not migrate elements and never changes an existing property or currency.
     */
    public function ensureOfferNamingAndMarginSchema(): array
    {
        $result = ['created' => [], 'existing' => [], 'errors' => []];
        if (!Loader::includeModule('iblock')) {
            $result['errors'][] = 'Модуль «Информационные блоки» не подключён';
            return $this->withCounts($result);
        }

        $definitions = self::getPropertySchema()['CALC_PRESETS'];
        $iblockId = (new ConfigManager())->getIblockId('CALC_PRESETS');
        if ($iblockId <= 0) {
            $result['errors'][] = 'Инфоблок CALC_PRESETS не найден';
        } else {
            foreach ($definitions as $propertyCode => $definition) {
                $label = 'CALC_PRESETS.' . $propertyCode;
                $existing = \CIBlockProperty::GetList([], [
                    'IBLOCK_ID' => $iblockId,
                    'CODE' => $propertyCode,
                ])->Fetch();
                if ($existing) {
                    $result['existing'][] = $label;
                    continue;
                }
                $property = new \CIBlockProperty();
                $propertyId = $property->Add($this->buildPropertyFields(
                    $iblockId,
                    $propertyCode,
                    $definition
                ));
                if ($propertyId) {
                    $result['created'][] = $label;
                } else {
                    $result['errors'][] = 'Не удалось создать ' . $label . ': '
                        . (trim((string)$property->LAST_ERROR) ?: 'неизвестная ошибка');
                }
            }
        }

        if (Loader::includeModule('currency')) {
            if (\CCurrency::GetByID('MRG')) {
                $result['existing'][] = 'CURRENCY.MRG';
            } else {
                $currencyId = \CCurrency::Add([
                    'CURRENCY' => 'MRG',
                    'SORT' => 998,
                    'AMOUNT_CNT' => 1,
                    'AMOUNT' => 1,
                ]);
                if ($currencyId) {
                    foreach (['ru', 'en'] as $languageId) {
                        \CCurrencyLang::Add([
                            'CURRENCY' => 'MRG',
                            'LID' => $languageId,
                            'FORMAT_STRING' => '#',
                            'FULL_NAME' => '% margin',
                            'DEC_POINT' => '.',
                            'THOUSANDS_SEP' => ' ',
                            'DECIMALS' => 2,
                        ]);
                    }
                    $result['created'][] = 'CURRENCY.MRG';
                } else {
                    $result['errors'][] = 'Не удалось создать валюту MRG';
                }
            }
        } else {
            $result['errors'][] = 'Модуль «Валюты» не подключён';
        }

        return $this->withCounts($result);
    }

    private function migrateLegacyStageOwnership(ConfigManager $configManager): int
    {
        $stagesIblockId = $configManager->getIblockId('CALC_STAGES');
        $settingsIblockId = $configManager->getIblockId('CALC_SETTINGS');
        if ($stagesIblockId <= 0 || $settingsIblockId <= 0) {
            return 0;
        }

        $stageEntityCodesProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $stagesIblockId, '=CODE' => 'USED_ENTITY_CODES'])->Fetch();
        if (!$stageEntityCodesProperty) {
            return 0;
        }

        $migrated = 0;
        $stages = \CIBlockElement::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $stagesIblockId], false, false, ['ID', 'IBLOCK_ID']);
        while ($stageElement = $stages->GetNextElement()) {
            $stageFields = $stageElement->GetFields();
            $stageProps = $stageElement->GetProperties();
            $ownershipVersion = (int)($stageProps['STAGE_OWNERSHIP_VERSION']['VALUE'] ?? 0);
            if ($ownershipVersion >= self::STAGE_OWNERSHIP_VERSION) {
                continue;
            }
            $settingsId = (int)($stageProps['CALC_SETTINGS']['VALUE'] ?? 0);
            $usedEntityCodes = array_values(array_intersect(
                array_map('strval', (array)($stageProps['USED_ENTITY_CODES']['VALUE'] ?? [])),
                ['VARIANT_OPERATION', 'EQUIPMENT', 'VARIANT_MATERIAL']
            ));
            if (!$usedEntityCodes) {
                $usedEntityCodes = array_values(array_intersect(
                    array_map('strval', (array)($stageProps['USED_ENTITYS']['VALUE_XML_ID'] ?? [])),
                    ['VARIANT_OPERATION', 'EQUIPMENT', 'VARIANT_MATERIAL']
                ));
            }
            $customFieldIds = array_values(array_filter(array_map(
                'intval',
                (array)($stageProps['CUSTOM_FIELDS']['VALUE'] ?? [])
            )));
            if ($settingsId > 0) {
                $settingsElement = \CIBlockElement::GetList([], [
                    'ID' => $settingsId,
                    'IBLOCK_ID' => $settingsIblockId,
                ], false, false, ['ID', 'IBLOCK_ID'])->GetNextElement();
                if ($settingsElement) {
                    $settingsProps = $settingsElement->GetProperties();
                    if (!$usedEntityCodes) {
                        $usedEntityCodes = array_values(array_intersect(
                            array_map('strval', (array)($settingsProps['USED_ENTITYS']['VALUE_XML_ID'] ?? [])),
                            ['VARIANT_OPERATION', 'EQUIPMENT', 'VARIANT_MATERIAL']
                        ));
                    }
                    if (!$customFieldIds) {
                        $customFieldIds = array_values(array_filter(array_map(
                            'intval',
                            (array)($settingsProps['CUSTOM_FIELDS']['VALUE'] ?? [])
                        )));
                    }
                }
            }
            \CIBlockElement::SetPropertyValuesEx((int)$stageFields['ID'], $stagesIblockId, [
                'USED_ENTITY_CODES' => $usedEntityCodes ?: false,
                'CUSTOM_FIELDS' => $customFieldIds ?: false,
                'STAGE_OWNERSHIP_VERSION' => self::STAGE_OWNERSHIP_VERSION,
            ]);
            $migrated++;
        }

        return $migrated;
    }

    /**
     * Add only missing enum values. Existing values and element assignments stay untouched.
     *
     * @param array<string, mixed> $definition
     */
    private function ensureListPropertyValues(int $propertyId, array $definition): void
    {
        if (($definition['TYPE'] ?? null) !== 'L' || empty($definition['VALUES']) || !is_array($definition['VALUES'])) {
            return;
        }

        $existingXmlIds = [];
        $existing = \CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => $propertyId]);
        while ($enum = $existing->Fetch()) {
            $existingXmlIds[(string)$enum['XML_ID']] = (int)$enum['ID'];
        }

        foreach ($definition['VALUES'] as $value) {
            $xmlId = (string)($value['XML_ID'] ?? '');
            if ($xmlId === '' || isset($existingXmlIds[$xmlId])) {
                continue;
            }
            $enumId = \CIBlockPropertyEnum::Add([
                'PROPERTY_ID' => $propertyId,
                'VALUE' => (string)($value['VALUE'] ?? $xmlId),
                'XML_ID' => $xmlId,
                'SORT' => (int)($value['SORT'] ?? 500),
                'DEF' => (string)($value['DEF'] ?? 'N'),
            ]);
            if ($enumId) {
                $existingXmlIds[$xmlId] = (int)$enumId;
            }
        }
        $this->listEnumIdsByProperty[$propertyId] = $existingXmlIds;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPropertyFields(int $iblockId, string $code, array $definition): array
    {
        $fields = [
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'CODE' => $code,
            'NAME' => (string)$definition['NAME'],
            'PROPERTY_TYPE' => (string)($definition['TYPE'] ?? 'S'),
            'MULTIPLE' => (string)($definition['MULTIPLE'] ?? 'N'),
            'IS_REQUIRED' => (string)($definition['IS_REQUIRED'] ?? 'N'),
            'SORT' => (int)($definition['SORT'] ?? 500),
            'WITH_DESCRIPTION' => (string)($definition['WITH_DESCRIPTION'] ?? 'N'),
        ];

        foreach (['MULTIPLE_CNT', 'USER_TYPE', 'HINT', 'LINK_IBLOCK_ID'] as $fieldName) {
            if (array_key_exists($fieldName, $definition)) {
                $fields[$fieldName] = $definition[$fieldName];
            }
        }
        if (!empty($definition['VALUES']) && is_array($definition['VALUES'])) {
            $fields['VALUES'] = $definition['VALUES'];
        }

        return $fields;
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

    /**
     * @param array<string, array<int, string>> $result
     *
     * @return array<string, mixed>
     */
    private function withCounts(array $result): array
    {
        $result['createdCount'] = count($result['created']);
        $result['existingCount'] = count($result['existing']);
        $result['errorCount'] = count($result['errors']);

        return $result;
    }
}
