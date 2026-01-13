<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Iblock\ElementTable;

/**
 * Обработчик операций с деталями и группами скрепления
 */
class DetailHandler
{
    private const MODULE_ID = 'prospektweb.calc';

    private int $detailsIblockId;
    private int $configIblockId;

    public function __construct()
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix iblock');
        }
        
        $this->detailsIblockId = (int)Option::get(self::MODULE_ID, 'IBLOCK_CALC_DETAILS', 0);
        $this->configIblockId = (int)Option::get(self::MODULE_ID, 'IBLOCK_CALC_STAGES', 0);
    }

    /**
     * Добавить новую деталь
     * 
     * @param array $data Данные запроса
     * @return array Ответ с данными новой детали
     */
    public function addDetail(array $data): array
    {
        try {
            $offerIds = $data['offerIds'] ?? [];
            $name = !empty($data['name']) ? $data['name'] : $this->generateDetailName();
            
            // 1. Создать элемент в CALC_DETAILS с TYPE = DETAIL
            $detailId = $this->createDetailElement($name, 'DETAIL');
            
            if (!$detailId) {
                return [
                    'status' => 'error',
                    'message' => 'Не удалось создать деталь',
                ];
            }
            
            // 2. Создать элемент в CALC_STAGES (пустой конфиг для первого этапа)
            $configId = $this->createConfigElement('Этап #' . date('dmY_His'));
            
            if (!$configId) {
                // Откатываем создание детали
                \CIBlockElement::Delete($detailId);
                return [
                    'status' => 'error',
                    'message' => 'Не удалось создать конфигурацию',
                ];
            }
            
            // 3. Связать конфиг с деталью через свойство CALC_STAGES
            $this->linkConfigToDetail($detailId, [$configId]);
            
            // 4. Вернуть данные
            return [
                'status' => 'ok',
                'detail' => [
                    'id' => $detailId,
                    'name' => $name,
                    'type' => 'DETAIL',
                ],
                'config' => [
                    'id' => $configId,
                ],
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Копировать деталь
     * 
     * @param array $data Данные запроса
     * @return array Ответ с данными скопированной детали
     */
    public function copyDetail(array $data): array
    {
        try {
            $detailId = (int)($data['detailId'] ?? 0);
            $offerIds = $data['offerIds'] ?? [];
            
            if ($detailId <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'Не указан ID детали для копирования',
                ];
            }
            
            // Получаем оригинальную деталь
            $originalDetail = $this->getDetailById($detailId);
            
            if (!$originalDetail) {
                return [
                    'status' => 'error',
                    'message' => 'Деталь не найдена',
                ];
            }
            
            // Копируем деталь рекурсивно
            $result = $this->copyDetailRecursive($originalDetail);
            
            if (!$result || $result['status'] !== 'ok') {
                return [
                    'status' => 'error',
                    'message' => 'Не удалось скопировать деталь',
                ];
            }
            
            return $result;
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Добавить группу скрепления
     * 
     * @param array $data Данные запроса
     * @return array Ответ с данными новой группы
     */
    public function addGroup(array $data): array
    {
        try {
            $offerIds = $data['offerIds'] ?? [];
            $detailIds = $data['detailIds'] ?? [];
            $name = $data['name'] ?? ('Новая группа скрепления ' . $this->generateCosmicName());
            
            if (empty($detailIds)) {
                return [
                    'status' => 'error',
                    'message' => 'Не указаны детали для группировки',
                ];
            }
            
            // 1. Создать элемент в CALC_DETAILS с TYPE = BINDING
            $groupId = $this->createDetailElement($name, 'BINDING');
            
            if (!$groupId) {
                return [
                    'status' => 'error',
                    'message' => 'Не удалось создать группу',
                ];
            }
            
            // 2. Создать конфиг для этапов скрепления
            $configId = $this->createConfigElement('Этап #' . date('dmY_His'));
            
            if (!$configId) {
                \CIBlockElement::Delete($groupId);
                return [
                    'status' => 'error',
                    'message' => 'Не удалось создать конфигурацию',
                ];
            }
            
            // 3. Заполнить свойство DETAILS массивом detailIds
            \CIBlockElement::SetPropertyValuesEx($groupId, $this->detailsIblockId, [
                'DETAILS' => $detailIds,
            ]);
            
            // 4. Связать через CALC_STAGES (для групп используем CALC_STAGES_BINDINGS)
            \CIBlockElement::SetPropertyValuesEx($groupId, $this->detailsIblockId, [
                'CALC_STAGES_BINDINGS' => [$configId],
            ]);
            
            return [
                'status' => 'ok',
                'group' => [
                    'id' => $groupId,
                    'name' => $name,
                    'type' => 'BINDING',
                    'detailIds' => $detailIds,
                ],
                'config' => [
                    'id' => $configId,
                ],
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Добавить новый этап (конфигурацию)
     * 
     * @param array $data Данные запроса
     * @return array Ответ с данными нового этапа
     */
    public function addStage(array $data): array
    {
        try {
            $detailId = (int)($data['detailId'] ?? 0);
            
            if ($detailId <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'Не указан ID детали',
                ];
            }
            
            // Проверяем существование детали
            $detail = $this->getDetailById($detailId);
            
            if (!$detail) {
                return [
                    'status' => 'error',
                    'message' => 'Деталь не найдена',
                ];
            }
            
            // 1. Создать новый элемент в CALC_STAGES
            $configName = 'Этап #' . date('dmY_His');
            $configId = $this->createConfigElement($configName);
            
            if (!$configId) {
                return [
                    'status' => 'error',
                    'message' => 'Не удалось создать конфигурацию',
                ];
            }
            
            // 2. Добавить его ID в свойство CALC_STAGES детали
            $existingConfigs = $detail['CONFIGS'];
            $existingConfigs[] = $configId;
            
            \CIBlockElement::SetPropertyValuesEx($detailId, $this->detailsIblockId, [
                'CALC_STAGES' => $existingConfigs,
            ]);
            
            return [
                'status' => 'ok',
                'config' => [
                    'id' => $configId,
                    'detailId' => $detailId,
                ],
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Удалить этап (конфигурацию)
     * 
     * @param array $data Данные запроса
     * @return array Ответ об успешности операции
     */
    public function deleteStage(array $data): array
    {
        try {
            $configId = (int)($data['configId'] ?? 0);
            $detailId = (int)($data['detailId'] ?? 0);
            
            if ($configId <= 0 || $detailId <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'Не указаны ID конфигурации или детали',
                ];
            }
            
            // Получаем деталь
            $detail = $this->getDetailById($detailId);
            
            if (!$detail) {
                return [
                    'status' => 'error',
                    'message' => 'Деталь не найдена',
                ];
            }
            
            // 1. Удалить элемент из CALC_STAGES
            if (!\CIBlockElement::Delete($configId)) {
                return [
                    'status' => 'error',
                    'message' => 'Не удалось удалить конфигурацию',
                ];
            }
            
            // 2. Убрать ID из свойства CALC_STAGES детали
            $existingConfigs = $detail['CONFIGS'];
            $newConfigs = array_filter($existingConfigs, function($id) use ($configId) {
                return $id != $configId;
            });
            
            \CIBlockElement::SetPropertyValuesEx($detailId, $this->detailsIblockId, [
                'CALC_STAGES' => array_values($newConfigs),
            ]);
            
            return [
                'status' => 'ok',
                'configId' => $configId,
                'detailId' => $detailId,
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Удалить деталь
     * 
     * @param array $data Данные запроса
     * @return array Ответ об успешности операции
     */
    public function deleteDetail(array $data): array
    {
        try {
            $detailId = (int)($data['detailId'] ?? 0);
            
            if ($detailId <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'Не указан ID детали',
                ];
            }
            
            // Получаем деталь
            $detail = $this->getDetailById($detailId);
            
            if (!$detail) {
                return [
                    'status' => 'error',
                    'message' => 'Деталь не найдена',
                ];
            }
            
            // 1. Получить все конфиги детали
            $configIds = $detail['CONFIGS'];
            
            // 2. Удалить все конфиги
            foreach ($configIds as $configId) {
                \CIBlockElement::Delete($configId);
            }
            
            // 3. Удалить деталь
            if (!\CIBlockElement::Delete($detailId)) {
                return [
                    'status' => 'error',
                    'message' => 'Не удалось удалить деталь',
                ];
            }
            
            return [
                'status' => 'ok',
                'detailId' => $detailId,
                'deletedConfigIds' => $configIds,
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Изменить имя детали
     * 
     * @param array $data Данные запроса
     * @return array Ответ об успешности операции
     */
    public function changeName(array $data): array
    {
        try {
            $detailId = (int)($data['detailId'] ?? 0);
            $newName = trim($data['newName'] ?? '');
            
            if ($detailId <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'Не указан ID детали',
                ];
            }
            
            if (empty($newName)) {
                return [
                    'status' => 'error',
                    'message' => 'Имя не может быть пустым',
                ];
            }
            
            // 1. Обновить NAME элемента в CALC_DETAILS
            $el = new \CIBlockElement();
            if (!$el->Update($detailId, ['NAME' => $newName])) {
                return [
                    'status' => 'error',
                    'message' => 'Не удалось обновить имя детали',
                ];
            }
            
            return [
                'status' => 'ok',
                'detailId' => $detailId,
                'newName' => $newName,
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить деталь с вложенными элементами (рекурсивно)
     * 
     * @param int $detailId ID детали
     * @return array|null Данные детали с вложенными элементами
     */
    public function getDetailWithChildren(int $detailId): ?array
    {
        $detail = $this->getDetailById($detailId);
        
        if (!$detail) {
            return null;
        }
        
        $result = [
            'id' => $detail['ID'],
            'name' => $detail['NAME'],
            'type' => $detail['TYPE'],
            'configs' => $this->getConfigsByIds($detail['CONFIGS']),
        ];
        
        // Если это группа (BINDING), загружаем вложенные детали
        if ($detail['TYPE'] === 'BINDING' && !empty($detail['DETAIL_IDS'])) {
            $result['detailIds'] = $detail['DETAIL_IDS'];
            $result['children'] = [];
            
            foreach ($detail['DETAIL_IDS'] as $childId) {
                $childDetail = $this->getDetailWithChildren($childId);
                if ($childDetail) {
                    $result['children'][] = $childDetail;
                }
            }
        }
        
        return $result;
    }

    // ========== Вспомогательные методы ==========

    /**
     * Получить ID значения списочного свойства по XML_ID
     * 
     * @param int $iblockId ID инфоблока
     * @param string $propertyCode Код свойства
     * @param string $xmlId XML_ID значения
     * @return int|null ID значения или null
     */
    private function getListPropertyValueId(int $iblockId, string $propertyCode, string $xmlId): ?int
    {
        $rsProperty = \CIBlockProperty::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode]
        );
        
        if ($arProperty = $rsProperty->Fetch()) {
            // Проверяем, что это свойство типа "Список"
            if ($arProperty['PROPERTY_TYPE'] === 'L') {
                $rsPropertyEnum = \CIBlockPropertyEnum::GetList(
                    [],
                    ['IBLOCK_ID' => $iblockId, 'PROPERTY_ID' => $arProperty['ID'], 'XML_ID' => $xmlId]
                );
                
                if ($arEnum = $rsPropertyEnum->Fetch()) {
                    return (int)$arEnum['ID'];
                }
            }
        }
        
        return null;
    }

    /**
     * Создать элемент детали
     */
    private function createDetailElement(string $name, string $type): ?int
    {
        $el = new \CIBlockElement();
        
        // Получаем ID значения свойства TYPE по XML_ID
        // XML_ID для детали: "DETAIL", для группы скрепления: "BINDING"
        $typeValueId = $this->getListPropertyValueId($this->detailsIblockId, 'TYPE', $type);
        
        $fields = [
            'IBLOCK_ID' => $this->detailsIblockId,
            'NAME' => $name,
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => [
                'TYPE' => $typeValueId ?: $type, // Если не нашли ID, используем строку (для совместимости)
            ],
        ];
        
        $id = $el->Add($fields);
        
        return $id ? (int)$id : null;
    }

    /**
     * Создать элемент конфигурации
     */
    private function createConfigElement(string $name): ?int
    {
        $el = new \CIBlockElement();
        
        $fields = [
            'IBLOCK_ID' => $this->configIblockId,
            'NAME' => $name,
            'ACTIVE' => 'Y',
        ];
        
        $id = $el->Add($fields);
        
        return $id ? (int)$id : null;
    }

    /**
     * Связать конфигурации с деталью
     */
    private function linkConfigToDetail(int $detailId, array $configIds): void
    {
        \CIBlockElement::SetPropertyValuesEx($detailId, $this->detailsIblockId, [
            'CALC_STAGES' => $configIds,
        ]);
    }

    /**
     * Получить деталь по ID
     */
    private function getDetailById(int $detailId): ?array
    {
        $element = \CIBlockElement::GetList(
            [],
            ['ID' => $detailId, 'IBLOCK_ID' => $this->detailsIblockId],
            false,
            false,
            ['ID', 'NAME', 'IBLOCK_ID']
        )->GetNextElement();
        
        if (!$element) {
            return null;
        }
        
        $fields = $element->GetFields();
        $properties = $element->GetProperties();
        
        $type = $properties['TYPE']['VALUE'] ?? 'DETAIL';
        $configIds = is_array($properties['CALC_STAGES']['VALUE']) 
            ? $properties['CALC_STAGES']['VALUE'] 
            : (!empty($properties['CALC_STAGES']['VALUE']) ? [$properties['CALC_STAGES']['VALUE']] : []);
        
        $detailIds = is_array($properties['DETAILS']['VALUE']) 
            ? $properties['DETAILS']['VALUE'] 
            : (!empty($properties['DETAILS']['VALUE']) ? [$properties['DETAILS']['VALUE']] : []);
        
        return [
            'ID' => (int)$fields['ID'],
            'NAME' => $fields['NAME'],
            'TYPE' => $type,
            'CONFIGS' => array_map('intval', $configIds),
            'DETAIL_IDS' => array_map('intval', $detailIds),
        ];
    }

    /**
     * Получить конфигурации по ID
     */
    private function getConfigsByIds(array $configIds): array
    {
        if (empty($configIds)) {
            return [];
        }
        
        $configs = [];
        
        $result = \CIBlockElement::GetList(
            [],
            ['ID' => $configIds, 'IBLOCK_ID' => $this->configIblockId],
            false,
            false,
            ['ID', 'NAME']
        );
        
        while ($config = $result->Fetch()) {
            $configs[] = [
                'id' => (int)$config['ID'],
                'name' => $config['NAME'],
            ];
        }
        
        return $configs;
    }

    /**
     * Копировать деталь рекурсивно
     */
    private function copyDetailRecursive(array $originalDetail): array
    {
        $newName = $originalDetail['NAME'] . ' (копия)';
        
        // Создаем копию детали
        $newDetailId = $this->createDetailElement($newName, $originalDetail['TYPE']);
        
        if (!$newDetailId) {
            return [
                'status' => 'error',
                'message' => 'Не удалось создать копию детали',
            ];
        }
        
        // Копируем конфигурации
        $newConfigIds = [];
        foreach ($originalDetail['CONFIGS'] as $configId) {
            $newConfigId = $this->copyConfig($configId);
            if ($newConfigId) {
                $newConfigIds[] = $newConfigId;
            }
        }
        
        // Связываем конфигурации с новой деталью
        if ($originalDetail['TYPE'] === 'DETAIL') {
            $this->linkConfigToDetail($newDetailId, $newConfigIds);
        } else {
            // Для групп используем CALC_STAGES_BINDINGS
            \CIBlockElement::SetPropertyValuesEx($newDetailId, $this->detailsIblockId, [
                'CALC_STAGES_BINDINGS' => $newConfigIds,
            ]);
        }
        
        // Рекурсивно копируем вложенные детали для групп
        $children = [];
        if ($originalDetail['TYPE'] === 'BINDING' && !empty($originalDetail['DETAIL_IDS'])) {
            $newDetailIds = [];
            
            foreach ($originalDetail['DETAIL_IDS'] as $childId) {
                $childDetail = $this->getDetailById($childId);
                if ($childDetail) {
                    $childCopy = $this->copyDetailRecursive($childDetail);
                    if ($childCopy['status'] === 'ok') {
                        $newDetailIds[] = $childCopy['detail']['id'];
                        $children[] = $childCopy;
                    }
                }
            }
            
            // Связываем вложенные детали
            \CIBlockElement::SetPropertyValuesEx($newDetailId, $this->detailsIblockId, [
                'DETAILS' => $newDetailIds,
            ]);
        }
        
        $configs = [];
        foreach ($newConfigIds as $configId) {
            $configs[] = ['id' => $configId];
        }
        
        return [
            'status' => 'ok',
            'detail' => [
                'id' => $newDetailId,
                'name' => $newName,
                'type' => $originalDetail['TYPE'],
            ],
            'configs' => $configs,
            'children' => $children,
        ];
    }

    /**
     * Копировать конфигурацию
     */
    private function copyConfig(int $configId): ?int
    {
        $element = \CIBlockElement::GetList(
            [],
            ['ID' => $configId, 'IBLOCK_ID' => $this->configIblockId],
            false,
            false,
            ['ID', 'NAME']
        )->GetNextElement();
        
        if (!$element) {
            return null;
        }
        
        $fields = $element->GetFields();
        $properties = $element->GetProperties();
        
        $el = new \CIBlockElement();
        
        $newFields = [
            'IBLOCK_ID' => $this->configIblockId,
            'NAME' => $fields['NAME'] . ' (копия)',
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => [],
        ];
        
        // Копируем значения свойств
        foreach ($properties as $prop) {
            if (!empty($prop['VALUE'])) {
                $newFields['PROPERTY_VALUES'][$prop['CODE']] = $prop['VALUE'];
            }
        }
        
        $newId = $el->Add($newFields);
        
        return $newId ? (int)$newId : null;
    }

    /**
     * Генерировать имя детали
     */
    private function generateDetailName(): string
    {
        // Получаем количество существующих деталей
        $count = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $this->detailsIblockId],
            []
        );
        
        return 'Деталь #' . ($count + 1);
    }

    /**
     * Генерировать космическое имя для скрепления
     */
    private function generateCosmicName(): string
    {
        $cosmicNames = [
            'Andromeda', 'Orion', 'Nebula', 'Quasar', 'Pulsar', 
            'Nova', 'Helios', 'Cosmos', 'Vega', 'Sirius',
            'Altair', 'Rigel', 'Antares', 'Proxima', 'Kepler',
            'Titan', 'Europa', 'Ganymede', 'Callisto', 'Triton',
            'Hydrogen', 'Carbon', 'Oxygen', 'Neon', 'Argon'
        ];
        
        return $cosmicNames[array_rand($cosmicNames)];
    }
}
