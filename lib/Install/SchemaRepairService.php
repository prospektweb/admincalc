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
    /**
     * Реестр свойств, добавленных в модуль после первых установок.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function getPropertySchema(): array
    {
        return [
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
                $propertyLabel = $iblockCode . '.' . $propertyCode;
                $existing = \CIBlockProperty::GetList(
                    [],
                    ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode]
                )->Fetch();

                if ($existing) {
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

        return $this->withCounts($result);
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
