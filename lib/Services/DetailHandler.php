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
    private int $detailsIblockId;
    private int $stagesIblockId;
    private int $presetsIblockId;
    
    public function __construct()
    {
        if (! Loader::includeModule('iblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix iblock');
        }
        
        $configManager = new \Prospektweb\Calc\Config\ConfigManager();
        
        $this->detailsIblockId = $configManager->getIblockId('CALC_DETAILS');
        $this->stagesIblockId = $configManager->getIblockId('CALC_STAGES');
        $this->presetsIblockId = $configManager->getIblockId('CALC_PRESETS');
    }

    /**
     * Логирование для отладки
     */
    private function logDebug(string $label, $data = null): void
    {
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/local/logs';
        $logFile = $logDir . '/detail_handler.log';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $str = date('c') . ' [' . $label .  "]\n";
        if ($data !== null) {
            $str .= print_r($data, true) . "\n";
        }
        $str .= "-----------------------------\n";

        file_put_contents($logFile, $str, FILE_APPEND | LOCK_EX);
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
            $name = ! empty($data['name']) ? $data['name'] : $this->generateDetailName();
            
            // 1. Создать элемент в CALC_DETAILS с TYPE = DETAIL
            $detailId = $this->createDetailElement($name, 'DETAIL');
            
            if (! $detailId) {
                return [
                    'status' => 'error',
                    'message' => 'Не удалось создать деталь',
                ];
            }
            
            // 2. Создать элемент в CALC_STAGES (пустой конфиг для первого этапа)
            $configId = $this->createConfigElement(date('dmY_His'));
            
            if (!$configId) {
                // Откатываем создание детали
                \CIBlockElement:: Delete($detailId);
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
     * Клонировать деталь (1:1 клон с позиционными правилами)
     *
     * @param array $data Данные запроса (detailId, presetId)
     * @return array Ответ с данными клонированной детали
     */
    public function cloneDetail(array $data): array
    {
        $createdDetailIds = [];
        $createdConfigIds = [];
        try {
            $detailId = (int)($data['detailId'] ?? 0);
            $presetId = (int)($data['presetId'] ?? 0);

            if ($detailId <= 0) {
                return ['status' => 'error', 'message' => 'Не указан ID детали для клонирования'];
            }

            $originalDetail = $this->getDetailById($detailId);
            if (!$originalDetail) {
                return ['status' => 'error', 'message' => 'Деталь не найдена'];
            }

            // Клонируем деталь 1:1
            $cloneResult = $this->cloneDetailRecursive($originalDetail, $createdDetailIds, $createdConfigIds);
            if (!$cloneResult || ($cloneResult['status'] ?? 'error') !== 'ok') {
                $this->rollbackCreated($createdDetailIds, $createdConfigIds);
                return ['status' => 'error', 'message' => 'Не удалось клонировать деталь'];
            }

            $newDetailId = $cloneResult['detail']['id'];
            $rootDetailId = $newDetailId;

            // Позиционные правила
            if ($presetId > 0) {
                $presetDetails = $this->getPresetDetails($presetId);
                $parent = $this->findParentOfDetail($detailId, $presetId);

                if ($parent !== null) {
                    // Деталь уже внутри скрепления — вставляем клон сразу после оригинала
                    $parentDetails = $parent['DETAIL_IDS'];
                    $pos = array_search($detailId, array_map('intval', $parentDetails));
                    if ($pos === false) {
                        $pos = count($parentDetails) - 1;
                    }
                    array_splice($parentDetails, $pos + 1, 0, [$newDetailId]);

                    // Сначала очищаем свойство, затем записываем новый порядок
                    \CIBlockElement::SetPropertyValuesEx($parent['ID'], $this->detailsIblockId, ['DETAILS' => false]);
                    \CIBlockElement::SetPropertyValuesEx($parent['ID'], $this->detailsIblockId, ['DETAILS' => $parentDetails]);

                    // rootDetailId для enrichPreset — корневой элемент пресета
                    $rootDetailId = !empty($presetDetails) ? (int)$presetDetails[0] : $newDetailId;
                } else {
                    // Деталь на верхнем уровне — создаём новое скрепление [оригинал, клон]
                    $bindingName = 'Группа скрепления ' . $originalDetail['NAME'];
                    $bindingId = $this->createDetailElement($bindingName, 'BINDING');
                    if (!$bindingId) {
                        $this->rollbackCreated($createdDetailIds, $createdConfigIds);
                        return ['status' => 'error', 'message' => 'Не удалось создать группу скрепления'];
                    }
                    $createdDetailIds[] = $bindingId;

                    $configId = $this->createConfigElement(date('dmY_His') . '_' . substr((string)microtime(true), -6));
                    if (!$configId) {
                        $this->rollbackCreated($createdDetailIds, $createdConfigIds);
                        return ['status' => 'error', 'message' => 'Не удалось создать конфигурацию скрепления'];
                    }
                    $createdConfigIds[] = $configId;

                    \CIBlockElement::SetPropertyValuesEx($bindingId, $this->detailsIblockId, [
                        'CALC_STAGES' => [$configId],
                        'DETAILS' => [$detailId, $newDetailId],
                    ]);

                    // Заменяем оригинальную деталь на скрепление в пресете
                    $updatedPresetDetails = $presetDetails;
                    $origPos = array_search($detailId, array_map('intval', $updatedPresetDetails));
                    if ($origPos !== false) {
                        $updatedPresetDetails[$origPos] = $bindingId;
                    } else {
                        $updatedPresetDetails[] = $bindingId;
                    }

                    // Сначала очищаем свойство, затем записываем обновлённый список
                    \CIBlockElement::SetPropertyValuesEx($presetId, $this->presetsIblockId, ['CALC_DETAILS' => false]);
                    \CIBlockElement::SetPropertyValuesEx($presetId, $this->presetsIblockId, [
                        'CALC_DETAILS' => array_values($updatedPresetDetails),
                    ]);

                    $rootDetailId = $bindingId;
                }
            }

            return [
                'status' => 'ok',
                'detail' => $cloneResult['detail'],
                'rootDetailId' => $rootDetailId,
                'newDetailId' => $newDetailId,
            ];

        } catch (\Exception $e) {
            $this->rollbackCreated($createdDetailIds, $createdConfigIds);
            return ['status' => 'error', 'message' => $e->getMessage()];
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
            $name = $data['name'] ??  ('Новая группа скрепления ' . $this->generateCosmicName());
            
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
            $configId = $this->createConfigElement(date('dmY_His'));
            
            if (! $configId) {
                \CIBlockElement:: Delete($groupId);
                return [
                    'status' => 'error',
                    'message' => 'Не удалось создать конфигурацию',
                ];
            }
            
            // 3. Заполнить свойство DETAILS массивом detailIds
            \CIBlockElement:: SetPropertyValuesEx($groupId, $this->detailsIblockId, [
                'DETAILS' => $detailIds,
            ]);
            
            // 4. Связать через CALC_STAGES (для всех типов деталей)
            \CIBlockElement:: SetPropertyValuesEx($groupId, $this->detailsIblockId, [
                'CALC_STAGES' => [$configId],
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
            $configName = date('dmY_His');
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
            
            \CIBlockElement:: SetPropertyValuesEx($detailId, $this->detailsIblockId, [
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
            $detailId = (int)($data['detailId'] ??  0);
            
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
            if (!\CIBlockElement:: Delete($configId)) {
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
            
            \CIBlockElement:: SetPropertyValuesEx($detailId, $this->detailsIblockId, [
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
                \CIBlockElement:: Delete($configId);
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
            if (! $el->Update($detailId, ['NAME' => $newName])) {
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
     * Переименовать деталь (используется для RENAME_DETAIL_REQUEST)
     * 
     * @param int $detailId ID детали
     * @param string $name Новое имя
     * @return array Ответ об успешности операции
     */
    public function renameDetail(int $detailId, string $name): array
    {
        try {
            if ($detailId <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'Не указан ID детали',
                ];
            }
            
            $name = trim($name);
            if (empty($name)) {
                return [
                    'status' => 'error',
                    'message' => 'Имя не может быть пустым',
                ];
            }
            
            // Обновить NAME элемента через CIBlockElement:: Update()
            $el = new \CIBlockElement();
            if (!$el->Update($detailId, ['NAME' => $name])) {
                return [
                    'status' => 'error',
                    'message' => 'Не удалось обновить имя детали:  ' . $el->LAST_ERROR,
                ];
            }
            
            return [
                'status' => 'ok',
                'detailId' => $detailId,
                'name' => $name,
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
    * Удалить деталь из скрепления с рекурсивной логикой чистки
    * 
    * @param int $parentId ID родительского скрепления
    * @param int $detailId ID детали для удаления
    * @param int $presetId ID пресета
    * @return array Ответ с информацией о результате операции
    */
    public function removeDetailFromBinding(int $parentId, int $detailId, int $presetId): array
    {
        $this->logDebug('removeDetailFromBinding START', [
            'parentId' => $parentId,
            'detailId' => $detailId,
            'presetId' => $presetId,
        ]);

        try {
            if ($parentId <= 0 || $detailId <= 0 || $presetId <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'Некорректные параметры',
                ];
            }

            // Получаем пресет для определения isRootParent
            $presetDetails = $this->getPresetDetails($presetId);
            $firstDetailId = $presetDetails[0] ?? 0;
            $isRootParent = ($parentId === $firstDetailId);

            $this->logDebug('removeDetailFromBinding presetDetails', [
                'presetDetails' => $presetDetails,
                'firstDetailId' => $firstDetailId,
                'isRootParent' => $isRootParent,
            ]);

            // Получаем родителя
            $parent = $this->getDetailById($parentId);
            
            $this->logDebug('removeDetailFromBinding parent', [
                'parent' => $parent,
            ]);

            if (!$parent || $parent['TYPE'] !== 'BINDING') {
                return [
                    'status' => 'error',
                    'message' => 'Родитель не найден или не является скреплением',
                ];
            }

            // Убираем detailId из DETAILS родителя
            $remainingDetails = array_filter($parent['DETAIL_IDS'], function($id) use ($detailId) {
                return (int)$id !== (int)$detailId;
            });
            $remainingDetails = array_values($remainingDetails);
            $remainingCount = count($remainingDetails);

            $this->logDebug('removeDetailFromBinding remaining', [
                'originalDetailIds' => $parent['DETAIL_IDS'],
                'detailIdToRemove' => $detailId,
                'remainingDetails' => $remainingDetails,
                'remainingCount' => $remainingCount,
            ]);

            // Логика в зависимости от количества оставшихся деталей
            if ($remainingCount === 1) {
                // А) Осталась 1 деталь
                $survivorId = $remainingDetails[0];
                
                $this->logDebug('removeDetailFromBinding remainingCount=1', [
                    'survivorId' => $survivorId,
                    'isRootParent' => $isRootParent,
                ]);

                // СНАЧАЛА находим родителя и сохраняем его данные (пока скрепление ещё существует)
                $grandParent = null;
                $grandParentDetailIds = null;
                if (! $isRootParent) {
                    $grandParent = $this->findParentOfDetail($parentId, $presetId);
                    if ($grandParent) {
                        $grandParentDetailIds = $grandParent['DETAIL_IDS'];  // Сохраняем ДО удаления
                    }
                    
                    $this->logDebug('removeDetailFromBinding grandParent BEFORE delete', [
                        'grandParent' => $grandParent,
                        'grandParentDetailIds' => $grandParentDetailIds,
                    ]);
                }

                // ПОТОМ удаляем скрепление физически
                $this->deleteDetailPhysically($parentId);

                if ($isRootParent) {
                    // Обогатить пресет на основе survivorId
                    return [
                        'status' => 'ok',
                        'action' => 'survivor_as_root',
                        'survivorId' => $survivorId,
                        'needsEnrichment' => true,
                        'enrichmentDetailId' => $survivorId,
                    ];
                } else {
                    // Заменить parentId на survivorId в родителе
                    if ($grandParent && $grandParentDetailIds) {
                        $this->logDebug('removeDetailFromBinding BEFORE replaceDetailInParent', [
                            'grandParentId' => $grandParent['ID'],
                            'grandParentDetailIds' => $grandParentDetailIds,
                            'oldDetailId' => $parentId,
                            'newDetailId' => $survivorId,
                        ]);

                        // Передаём сохранённые DETAIL_IDS, чтобы не читать из БД после удаления
                        $this->replaceDetailInParent(
                            $grandParent['ID'], 
                            $parentId, 
                            $survivorId, 
                            $grandParentDetailIds
                        );

                        // Проверяем результат
                        $updatedGrandParent = $this->getDetailById($grandParent['ID']);
                        $this->logDebug('removeDetailFromBinding AFTER replaceDetailInParent', [
                            'updatedGrandParentDetailIds' => $updatedGrandParent ?  $updatedGrandParent['DETAIL_IDS'] :  null,
                        ]);
                    }
                    
                    return [
                        'status' => 'ok',
                        'action' => 'survivor_replaced',
                        'survivorId' => $survivorId,
                        'needsEnrichment' => true,
                        'enrichmentDetailId' => $firstDetailId,
                    ];
                }
            } elseif ($remainingCount === 0) {
                // Б) Осталось 0 деталей
                
                $this->logDebug('removeDetailFromBinding remainingCount=0', [
                    'isRootParent' => $isRootParent,
                ]);

                // СНАЧАЛА находим родителя и сохраняем его данные (пока скрепление ещё существует)
                $grandParent = null;
                $grandParentDetailIds = null;
                if (!$isRootParent) {
                    $grandParent = $this->findParentOfDetail($parentId, $presetId);
                    if ($grandParent) {
                        $grandParentDetailIds = $grandParent['DETAIL_IDS'];
                    }
                    
                    $this->logDebug('removeDetailFromBinding grandParent BEFORE delete (empty)', [
                        'grandParent' => $grandParent,
                        'grandParentDetailIds' => $grandParentDetailIds,
                    ]);
                }

                // ПОТОМ удаляем скрепление физически
                $this->deleteDetailPhysically($parentId);

                if ($isRootParent) {
                    // Если удалили корневое скрепление и оно пустое — нужно очистить пресет
                    return [
                        'status' => 'ok',
                        'action' => 'binding_deleted_empty',
                        'needsEnrichment' => false,
                        'needsClear' => true,
                    ];
                }

                // Рекурсивно убрать parentId из его родителя
                if ($grandParent) {
                    return $this->removeDetailFromBinding($grandParent['ID'], $parentId, $presetId);
                }

                return [
                    'status' => 'ok',
                    'action' => 'binding_deleted_empty',
                    'needsEnrichment' => true,
                    'enrichmentDetailId' => $firstDetailId > 0 ? $firstDetailId : null,
                ];
            } else {
                // В) Осталось 2+ деталей
                
                $this->logDebug('removeDetailFromBinding remainingCount>=2', [
                    'remainingCount' => $remainingCount,
                    'remainingDetails' => $remainingDetails,
                ]);

                // Обновляем DETAILS родителя
                \CIBlockElement:: SetPropertyValuesEx($parentId, $this->detailsIblockId, [
                    'DETAILS' => $remainingDetails,
                ]);

                return [
                    'status' => 'ok',
                    'action' => 'detail_removed',
                    'remainingCount' => $remainingCount,
                    'needsEnrichment' => true,
                    'enrichmentDetailId' => $firstDetailId,
                ];
            }

        } catch (\Exception $e) {
            $this->logDebug('removeDetailFromBinding EXCEPTION', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Физически удалить деталь со всеми этапами
     */
    private function deleteDetailPhysically(int $detailId): void
    {
        $this->logDebug('deleteDetailPhysically', ['detailId' => $detailId]);

        $detail = $this->getDetailById($detailId);
        if ($detail) {
            // Удалить все конфигурации
            foreach ($detail['CONFIGS'] as $configId) {
                \CIBlockElement::Delete($configId);
            }
            
            // Удалить саму деталь
            \CIBlockElement::Delete($detailId);
        }
    }

    /**
     * Найти родителя детали в структуре пресета
     */
    private function findParentOfDetail(int $detailId, int $presetId): ?array
    {
        $this->logDebug('findParentOfDetail START', [
            'detailId' => $detailId,
            'presetId' => $presetId,
        ]);

        $presetDetails = $this->getPresetDetails($presetId);
        
        if (empty($presetDetails)) {
            $this->logDebug('findParentOfDetail presetDetails EMPTY');
            return null;
        }

        // Рекурсивный поиск родителя
        $rootDetailId = $presetDetails[0];
        
        $this->logDebug('findParentOfDetail searching from root', [
            'rootDetailId' => $rootDetailId,
        ]);

        $result = $this->findParentRecursive($rootDetailId, $detailId);
        
        $this->logDebug('findParentOfDetail RESULT', [
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * Рекурсивный поиск родителя
     */
    private function findParentRecursive(int $currentId, int $targetId): ?array
    {
        $current = $this->getDetailById($currentId);
        
        if (!$current || $current['TYPE'] !== 'BINDING') {
            return null;
        }

        // Проверяем, содержит ли текущий элемент целевую деталь
        if (in_array($targetId, $current['DETAIL_IDS'])) {
            return $current;
        }

        // Рекурсивно проверяем детей
        foreach ($current['DETAIL_IDS'] as $childId) {
            $result = $this->findParentRecursive($childId, $targetId);
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    /**
    * Заменить деталь в родителе
    * 
    * @param int $parentId ID родителя
    * @param int $oldDetailId ID старой детали
    * @param int $newDetailId ID новой детали
    * @param array|null $parentDetails Массив DETAIL_IDS родителя (если уже прочитан)
    */
    private function replaceDetailInParent(int $parentId, int $oldDetailId, int $newDetailId, ? array $parentDetails = null): void
    {
        $this->logDebug('replaceDetailInParent START', [
            'parentId' => $parentId,
            'oldDetailId' => $oldDetailId,
            'newDetailId' => $newDetailId,
            'parentDetailsProvided' => $parentDetails !== null,
        ]);

        // Если передан массив деталей — используем его, иначе читаем из БД
        if ($parentDetails === null) {
            $parent = $this->getDetailById($parentId);
            if (! $parent || $parent['TYPE'] !== 'BINDING') {
                $this->logDebug('replaceDetailInParent parent NOT BINDING or NOT FOUND');
                return;
            }
            $details = $parent['DETAIL_IDS'];
        } else {
            $details = $parentDetails;
        }

        $this->logDebug('replaceDetailInParent details before', [
            'details' => $details,
        ]);

        $oldDetailId = (int)$oldDetailId;
        $newDetailId = (int)$newDetailId;
        
        $replaced = false;
        foreach ($details as $key => $value) {
            if ((int)$value === $oldDetailId) {
                $details[$key] = $newDetailId;
                $replaced = true;
                break;
            }
        }
        
        $this->logDebug('replaceDetailInParent details after', [
            'details' => $details,
            'replaced' => $replaced,
        ]);

        if ($replaced) {
            // 1. Сначала очистить свойство DETAILS
            \CIBlockElement::SetPropertyValuesEx($parentId, $this->detailsIblockId, [
                'DETAILS' => false,
            ]);
            
            // 2. Записать обновленный массив деталей с нужным порядком
            \CIBlockElement::SetPropertyValuesEx($parentId, $this->detailsIblockId, [
                'DETAILS' => $details,
            ]);
            
            $this->logDebug('replaceDetailInParent SetPropertyValuesEx called');
        }
    }



    /**
     * Получить детали пресета
     */
    private function getPresetDetails(int $presetId): array
    {
        $element = \CIBlockElement:: GetList(
            [],
            ['ID' => $presetId, 'IBLOCK_ID' => $this->presetsIblockId],
            false,
            false,
            ['ID']
        )->Fetch();
    
        if (!$element) {
            return [];
        }
    
        // Получаем множественное свойство CALC_DETAILS
        $details = [];
        $rs = \CIBlockElement:: GetProperty(
            $this->presetsIblockId,
            $presetId,
            [],
            ['CODE' => 'CALC_DETAILS']
        );
        
        while ($prop = $rs->Fetch()) {
            if (! empty($prop['VALUE'])) {
                $details[] = (int)$prop['VALUE'];
            }
        }
    
        return $details;
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
        if ($detail['TYPE'] === 'BINDING' && ! empty($detail['DETAIL_IDS'])) {
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
     * Получить значение свойства TYPE для SetPropertyValuesEx по XML_ID (DETAIL/BINDING).
     */
    private function resolveDetailTypePropertyValue(string $type)
    {
        $type = strtoupper(trim($type));
        $enumId = $this->getListPropertyValueId($this->detailsIblockId, 'TYPE', $type);

        return $enumId ?: $type;
    }

    /**
     * Создать элемент детали
     */
    private function createDetailElement(string $name, string $type): ? int
    {
        $el = new \CIBlockElement();
        
        // Получаем ID значения свойства TYPE по XML_ID
        // XML_ID для детали:  "DETAIL", для группы скрепления: "BINDING"
        $typeValue = $this->resolveDetailTypePropertyValue($type);
        
        $fields = [
            'IBLOCK_ID' => $this->detailsIblockId,
            'NAME' => $name,
            'CODE' => $this->generateUniqueElementCode($this->detailsIblockId, $name),
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => [
                'TYPE' => $typeValue,
            ],
        ];
        
        $id = $el->Add($fields);
        
        return $id ?  (int)$id : null;
    }

    /**
     * Создать элемент конфигурации
     */
    private function createConfigElement(string $name): ?int
    {
        $el = new \CIBlockElement();
        
        $fields = [
            'IBLOCK_ID' => $this->stagesIblockId,
            'NAME' => $name,
            'CODE' => $this->generateUniqueElementCode($this->stagesIblockId, $name),
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
        \CIBlockElement:: SetPropertyValuesEx($detailId, $this->detailsIblockId, [
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
        
        $type = $properties['TYPE']['VALUE_XML_ID'] ?? 'DETAIL';
        $configIds = is_array($properties['CALC_STAGES']['VALUE']) 
            ? $properties['CALC_STAGES']['VALUE'] 
            : (! empty($properties['CALC_STAGES']['VALUE']) ? [$properties['CALC_STAGES']['VALUE']] : []);
        
        $detailIds = is_array($properties['DETAILS']['VALUE']) 
            ? $properties['DETAILS']['VALUE'] 
            : (! empty($properties['DETAILS']['VALUE']) ? [$properties['DETAILS']['VALUE']] : []);
        
        return [
            'ID' => (int)$fields['ID'],
            'NAME' => $fields['NAME'],
            'TYPE' => $type,
            'CONFIGS' => array_map('intval', $configIds),
            'DETAIL_IDS' => array_map('intval', $detailIds),
            'PROPERTY_VALUES' => $this->getElementPropertyValuesForClone((int)$fields['ID'], $this->detailsIblockId),
        ];
    }

    /**
     * Подготовить значения свойств элемента для полного клонирования через SetPropertyValuesEx.
     */
    private function getElementPropertyValuesForClone(int $elementId, int $iblockId): array
    {
        $result = [];

        $rsProperties = \CIBlockElement::GetProperty(
            $iblockId,
            $elementId,
            ['sort' => 'asc', 'id' => 'asc'],
            []
        );

        while ($property = $rsProperties->Fetch()) {
            $code = (string)($property['CODE'] ?? '');
            if ($code === '') {
                continue;
            }

            $value = $this->normalizePropertyValueForClone($property);
            $description = (string)($property['DESCRIPTION'] ?? '');
            $isMultiple = ($property['MULTIPLE'] ?? 'N') === 'Y';

            if ($isMultiple) {
                if (!isset($result[$code]) || !is_array($result[$code])) {
                    $result[$code] = [];
                }

                $result[$code][] = [
                    'VALUE' => $value,
                    'DESCRIPTION' => $description,
                ];

                continue;
            }

            $result[$code] = [
                'VALUE' => $value,
                'DESCRIPTION' => $description,
            ];
        }

        return $result;
    }

    /**
     * Нормализация значения свойства для корректного 1:1 клонирования.
     */
    private function normalizePropertyValueForClone(array $property)
    {
        if (($property['PROPERTY_TYPE'] ?? '') === 'L') {
            return $property['VALUE_ENUM_ID'] ?? null;
        }

        return $property['VALUE'] ?? null;
    }

    /**
     * Подготовить значения свойств из GetProperties для SetPropertyValuesEx.
     *
     * @deprecated Используйте getElementPropertyValuesForClone для 1:1 клонирования.
     */
    private function extractElementPropertyValues(array $properties): array
    {
        $result = [];

        foreach ($properties as $property) {
            $code = (string)($property['CODE'] ?? '');
            if ($code === '' || !array_key_exists('VALUE', $property)) {
                continue;
            }

            // For list (enum) properties use the numeric enum ID, not the display text
            $value = $this->normalizePropertyValueForClone($property);
            $description = (string)($property['DESCRIPTION'] ?? '');
            $isMultiple = ($property['MULTIPLE'] ?? 'N') === 'Y';

            if ($isMultiple) {
                if (!isset($result[$code]) || !is_array($result[$code])) {
                    $result[$code] = [];
                }

                $result[$code][] = [
                    'VALUE' => $value,
                    'DESCRIPTION' => $description,
                ];
                continue;
            }

            $result[$code] = [
                'VALUE' => $value,
                'DESCRIPTION' => $description,
            ];
        }

        return $result;
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
        
        $result = \CIBlockElement:: GetList(
            [],
            ['ID' => $configIds, 'IBLOCK_ID' => $this->stagesIblockId],
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
     * Клонировать деталь рекурсивно (1:1)
     */
    private function cloneDetailRecursive(array $originalDetail, array &$createdDetailIds, array &$createdConfigIds): array
    {
        $newDetailId = $this->createDetailElement($originalDetail['NAME'], $originalDetail['TYPE']);
        if (!$newDetailId) {
            return ['status' => 'error', 'message' => 'Не удалось создать клон детали'];
        }
        $createdDetailIds[] = $newDetailId;

        // Клонируем конфигурации
        $newConfigIds = [];
        foreach ($originalDetail['CONFIGS'] as $configId) {
            $newConfigId = $this->cloneConfig($configId, $createdConfigIds);
            if ($newConfigId) {
                $newConfigIds[] = $newConfigId;
            }
        }

        // Рекурсивно клонируем вложенные детали для BINDING
        $newDetailIds = [];
        if ($originalDetail['TYPE'] === 'BINDING' && !empty($originalDetail['DETAIL_IDS'])) {
            foreach ($originalDetail['DETAIL_IDS'] as $childId) {
                $childDetail = $this->getDetailById($childId);
                if ($childDetail) {
                    $childClone = $this->cloneDetailRecursive($childDetail, $createdDetailIds, $createdConfigIds);
                    if (($childClone['status'] ?? 'error') === 'ok') {
                        $newDetailIds[] = $childClone['detail']['id'];
                    }
                }
            }
        }

        // Копируем все свойства оригинала 1:1, перезаписываем только CALC_STAGES и DETAILS
        $propertyValues = $originalDetail['PROPERTY_VALUES'] ?? [];
        $propertyValues['TYPE'] = $this->resolveDetailTypePropertyValue($originalDetail['TYPE']);
        $propertyValues['CALC_STAGES'] = $newConfigIds;
        $propertyValues['DETAILS'] = $newDetailIds;

        \CIBlockElement::SetPropertyValuesEx($newDetailId, $this->detailsIblockId, $propertyValues);

        return [
            'status' => 'ok',
            'detail' => [
                'id' => $newDetailId,
                'name' => $originalDetail['NAME'],
                'type' => $originalDetail['TYPE'],
            ],
        ];
    }

    /**
     * Клонировать конфигурацию (1:1)
     */
    private function cloneConfig(int $configId, array &$createdConfigIds): ?int
    {
        $element = \CIBlockElement::GetList(
            [],
            ['ID' => $configId, 'IBLOCK_ID' => $this->stagesIblockId],
            false,
            false,
            ['ID', 'NAME', 'SORT', 'ACTIVE', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE']
        )->GetNextElement();

        if (!$element) {
            return null;
        }

        $fields = $element->GetFields();
        $properties = $element->GetProperties();

        $el = new \CIBlockElement();
        $newName = $fields['NAME'];
        $newFields = [
            'IBLOCK_ID' => $this->stagesIblockId,
            'NAME' => $newName,
            'CODE' => $this->generateUniqueElementCode($this->stagesIblockId, $newName),
            'ACTIVE' => $fields['ACTIVE'] ?? 'Y',
            'SORT' => $fields['SORT'] ?? 500,
        ];

        if (!empty($fields['PREVIEW_TEXT'])) {
            $newFields['PREVIEW_TEXT'] = $fields['PREVIEW_TEXT'];
            $newFields['PREVIEW_TEXT_TYPE'] = $fields['PREVIEW_TEXT_TYPE'] ?? 'text';
        }

        if (!empty($fields['DETAIL_TEXT'])) {
            $newFields['DETAIL_TEXT'] = $fields['DETAIL_TEXT'];
            $newFields['DETAIL_TEXT_TYPE'] = $fields['DETAIL_TEXT_TYPE'] ?? 'text';
        }

        $newId = $el->Add($newFields);
        if (!$newId) {
            return null;
        }

        $newId = (int)$newId;
        $createdConfigIds[] = $newId;

        $propValues = $this->getElementPropertyValuesForClone((int)$fields['ID'], $this->stagesIblockId);
        if (!empty($propValues)) {
            \CIBlockElement::SetPropertyValuesEx($newId, $this->stagesIblockId, $propValues);
        }

        return $newId;
    }

    /**
     * Откат созданных элементов при ошибке
     */
    private function rollbackCreated(array $detailIds, array $configIds): void
    {
        foreach (array_reverse($detailIds) as $id) {
            \CIBlockElement::Delete($id);
        }
        foreach (array_reverse($configIds) as $id) {
            \CIBlockElement::Delete($id);
        }
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
    private function generateUniqueElementCode(int $iblockId, string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'element';
        }

        $baseCode = (string)\CUtil::translit($name, 'ru', [
            'max_len' => 100,
            'change_case' => 'L',
            'replace_space' => '-',
            'replace_other' => '-',
            'delete_repeat_replace' => true,
            'use_google' => true,
        ]);

        if ($baseCode === '') {
            $baseCode = 'element';
        }

        $candidate = $baseCode;
        $suffix = 2;
        while ($this->isElementCodeExists($iblockId, $candidate)) {
            $suffixText = '-' . $suffix;
            $candidate = mb_substr($baseCode, 0, 100 - strlen($suffixText)) . $suffixText;
            $suffix++;
        }

        return $candidate;
    }

    private function isElementCodeExists(int $iblockId, string $code): bool
    {
        $exists = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, '=CODE' => $code],
            false,
            ['nTopCount' => 1],
            ['ID']
        )->Fetch();

        return (int)($exists['ID'] ?? 0) > 0;
    }

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

    /**
     * Добавить новую деталь в скрепление
     * 
     * @param int $parentId ID родительского скрепления
     * @return array Ответ с данными новой детали
     */
    public function addDetailToBinding(int $parentId): array
    {
        try {
            if ($parentId <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'Не указан ID родительского скрепления',
                ];
            }

            // Проверяем, что родитель существует и является скреплением
            $parent = $this->getDetailById($parentId);
            if (!$parent) {
                return [
                    'status' => 'error',
                    'message' => 'Родительское скрепление не найдено',
                ];
            }

            if ($parent['TYPE'] !== 'BINDING') {
                return [
                    'status' => 'error',
                    'message' => 'Родитель не является скреплением',
                ];
            }

            // 1. Создать новую деталь с TYPE = DETAIL и 1 пустым этапом
            $name = $this->generateDetailName();
            $detailId = $this->createDetailElement($name, 'DETAIL');
            
            if (!$detailId) {
                return [
                    'status' => 'error',
                    'message' => 'Не удалось создать деталь',
                ];
            }
            
            // Создать элемент в CALC_STAGES (пустой конфиг для первого этапа)
            $configId = $this->createConfigElement(date('dmY_His'));
            
            if (!$configId) {
                // Откатываем создание детали
                \CIBlockElement::Delete($detailId);
                return [
                    'status' => 'error',
                    'message' => 'Не удалось создать конфигурацию',
                ];
            }
            
            // Связать конфиг с деталью через свойство CALC_STAGES
            $this->linkConfigToDetail($detailId, [$configId]);
            
            // 2. Добавить ID новой детали в свойство DETAILS родителя
            $existingDetails = $parent['DETAIL_IDS'];
            $existingDetails[] = $detailId;
            
            \CIBlockElement::SetPropertyValuesEx($parentId, $this->detailsIblockId, [
                'DETAILS' => $existingDetails,
            ]);
            
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
     * Добавить выбранные детали в скрепление
     * 
     * @param int $parentId ID родительского скрепления
     * @param array $detailIds ID выбранных деталей
     * @return array Ответ об успешности операции
     */
    public function addDetailsToBinding(int $parentId, array $detailIds): array
    {
        try {
            if ($parentId <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'Не указан ID родительского скрепления',
                ];
            }

            if (empty($detailIds)) {
                return [
                    'status' => 'error',
                    'message' => 'Не указаны детали для добавления',
                ];
            }

            // Проверяем, что родитель существует и является скреплением
            $parent = $this->getDetailById($parentId);
            if (!$parent) {
                return [
                    'status' => 'error',
                    'message' => 'Родительское скрепление не найдено',
                ];
            }

            if ($parent['TYPE'] !== 'BINDING') {
                return [
                    'status' => 'error',
                    'message' => 'Родитель не является скреплением',
                ];
            }

            // 1. Получить текущие DETAILS родителя
            $existingDetails = $parent['DETAIL_IDS'];
            
            // 2. Добавить новые detailIds (избегаем дубликатов)
            foreach ($detailIds as $detailId) {
                if (!in_array($detailId, $existingDetails)) {
                    $existingDetails[] = (int)$detailId;
                }
            }
            
            // 3. Записать обновлённый массив
            \CIBlockElement::SetPropertyValuesEx($parentId, $this->detailsIblockId, [
                'DETAILS' => $existingDetails,
            ]);
            
            return [
                'status' => 'ok',
                'parentId' => $parentId,
                'addedDetails' => $detailIds,
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Изменить сортировку деталей в скреплении
     * 
     * @param int $parentId ID родительского скрепления
     * @param array $sorting Новый порядок ID деталей
     * @return array Ответ об успешности операции
     */
    public function changeDetailSort(int $parentId, array $sorting): array
    {
        try {
            if ($parentId <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'Не указан ID родительского скрепления',
                ];
            }

            if (empty($sorting)) {
                return [
                    'status' => 'error',
                    'message' => 'Не указан порядок сортировки',
                ];
            }

            // Проверяем, что родитель существует и является скреплением
            $parent = $this->getDetailById($parentId);
            if (!$parent) {
                return [
                    'status' => 'error',
                    'message' => 'Родительское скрепление не найдено',
                ];
            }

            if ($parent['TYPE'] !== 'BINDING') {
                return [
                    'status' => 'error',
                    'message' => 'Родитель не является скреплением',
                ];
            }

            // 1. Сначала очистить свойство DETAILS
            \CIBlockElement::SetPropertyValuesEx($parentId, $this->detailsIblockId, [
                'DETAILS' => false,
            ]);
            
            // 2. Записать sorting в DETAILS родителя с нужным порядком
            \CIBlockElement::SetPropertyValuesEx($parentId, $this->detailsIblockId, [
                'DETAILS' => array_map('intval', $sorting),
            ]);
            
            return [
                'status' => 'ok',
                'parentId' => $parentId,
                'sorting' => $sorting,
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Перенести деталь между скреплениями
     * 
     * @param int $fromParentId ID исходного скрепления
     * @param int $detailId ID переносимой детали
     * @param int $toParentId ID целевого скрепления
     * @param array $sorting Новый порядок деталей в целевом скреплении
     * @return array Ответ об успешности операции
     */
    public function changeDetailLevel(int $fromParentId, int $detailId, int $toParentId, array $sorting): array
    {
        try {
            if ($fromParentId <= 0 || $detailId <= 0 || $toParentId <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'Не указаны обязательные параметры',
                ];
            }

            // Проверяем существование родителей
            $fromParent = $this->getDetailById($fromParentId);
            $toParent = $this->getDetailById($toParentId);
            
            if (!$fromParent || !$toParent) {
                return [
                    'status' => 'error',
                    'message' => 'Одно из скреплений не найдено',
                ];
            }

            if ($fromParent['TYPE'] !== 'BINDING' || $toParent['TYPE'] !== 'BINDING') {
                return [
                    'status' => 'error',
                    'message' => 'Один из родителей не является скреплением',
                ];
            }

            // 1. Убрать detailId из свойства DETAILS у fromParentId
            $fromDetails = array_filter($fromParent['DETAIL_IDS'], function($id) use ($detailId) {
                return (int)$id !== (int)$detailId;
            });
            
            // Очистить и записать заново для fromParent
            \CIBlockElement::SetPropertyValuesEx($fromParentId, $this->detailsIblockId, [
                'DETAILS' => false,
            ]);
            
            \CIBlockElement::SetPropertyValuesEx($fromParentId, $this->detailsIblockId, [
                'DETAILS' => array_values($fromDetails),
            ]);
            
            // 2. В toParentId записать DETAILS = sorting (с очисткой)
            \CIBlockElement::SetPropertyValuesEx($toParentId, $this->detailsIblockId, [
                'DETAILS' => false,
            ]);
            
            \CIBlockElement::SetPropertyValuesEx($toParentId, $this->detailsIblockId, [
                'DETAILS' => array_map('intval', $sorting),
            ]);
            
            return [
                'status' => 'ok',
                'fromParentId' => $fromParentId,
                'toParentId' => $toParentId,
                'detailId' => $detailId,
                'sorting' => $sorting,
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Изменить сортировку этапов детали
     * 
     * @param int $detailId ID детали или детали-скрепления
     * @param array $sorting Новый порядок ID этапов
     * @return array Ответ об успешности операции
     */
    public function changeSortStage(int $detailId, array $sorting): array
    {
        try {
            if ($detailId <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'Не указан ID детали',
                ];
            }

            if (empty($sorting)) {
                return [
                    'status' => 'error',
                    'message' => 'Не указан порядок сортировки',
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

            // 1. Сначала очистить свойство CALC_STAGES
            \CIBlockElement::SetPropertyValuesEx($detailId, $this->detailsIblockId, [
                'CALC_STAGES' => false,
            ]);
            
            // 2. Записать sorting в CALC_STAGES детали с нужным порядком
            \CIBlockElement::SetPropertyValuesEx($detailId, $this->detailsIblockId, [
                'CALC_STAGES' => array_map('intval', $sorting),
            ]);
            
            return [
                'status' => 'ok',
                'detailId' => $detailId,
                'sorting' => $sorting,
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
