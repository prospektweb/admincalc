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
    private const STAGE_OWNERSHIP_VERSION = 2;

    /**
     * Реестр свойств, добавленных в модуль после первых установок.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function getPropertySchema(): array
    {
        return [
            'CALC_SETTINGS' => [
                'AI_CONTEXT_JSON' => [
                    'NAME' => 'Контекст AI-конструктора',
                    'TYPE' => 'S',
                    'USER_TYPE' => 'HTML',
                    'SORT' => 820,
                    'HINT' => 'Базисные продукты, описания источников, ожидаемые результаты и инструкции AI',
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

    private function migrateLegacyStageOwnership(ConfigManager $configManager): int
    {
        $stagesIblockId = $configManager->getIblockId('CALC_STAGES');
        $settingsIblockId = $configManager->getIblockId('CALC_SETTINGS');
        if ($stagesIblockId <= 0 || $settingsIblockId <= 0) {
            return 0;
        }

        $stageEntityProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $stagesIblockId, '=CODE' => 'USED_ENTITYS'])->Fetch();
        if (!$stageEntityProperty) {
            return 0;
        }
        $stageEnumByXml = [];
        $stageEnums = \CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => (int)$stageEntityProperty['ID']]);
        while ($enum = $stageEnums->Fetch()) {
            $stageEnumByXml[(string)$enum['XML_ID']] = (int)$enum['ID'];
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
            $usedEntityEnumIds = array_values(array_filter(array_map(
                'intval',
                (array)($stageProps['USED_ENTITYS']['VALUE'] ?? [])
            )));
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
                    if (!$usedEntityEnumIds) {
                        $legacyXmlIds = $settingsProps['USED_ENTITYS']['VALUE_XML_ID'] ?? [];
                        foreach ((array)$legacyXmlIds as $xmlId) {
                            if (isset($stageEnumByXml[(string)$xmlId])) {
                                $usedEntityEnumIds[] = $stageEnumByXml[(string)$xmlId];
                            }
                        }
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
                'USED_ENTITYS' => $usedEntityEnumIds ?: false,
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
            $existingXmlIds[(string)$enum['XML_ID']] = true;
        }

        $enumProperty = new \CIBlockPropertyEnum();
        foreach ($definition['VALUES'] as $value) {
            $xmlId = (string)($value['XML_ID'] ?? '');
            if ($xmlId === '' || isset($existingXmlIds[$xmlId])) {
                continue;
            }
            $enumProperty->Add([
                'PROPERTY_ID' => $propertyId,
                'VALUE' => (string)($value['VALUE'] ?? $xmlId),
                'XML_ID' => $xmlId,
                'SORT' => (int)($value['SORT'] ?? 500),
                'DEF' => (string)($value['DEF'] ?? 'N'),
            ]);
        }
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
