<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Loader;

class ElementDataService
{
    public function __construct()
    {
        $this->ensureBitrixModulesLoaded();
    }

    /**
     * Проверяет, что модули Bitrix загружены перед использованием API
     *
     * @throws \RuntimeException
     */
    private function ensureBitrixModulesLoaded(): void
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix iblock');
        }

        if (!Loader::includeModule('catalog')) {
            throw new \RuntimeException('Требуется модуль Bitrix catalog');
        }
    }

    public function prepareRefreshPayload(array $requests): array
    {
        $result = [];

        foreach ($requests as $request) {
            // Проверяем специальные actions
            if (isset($request['action'])) {
                switch ($request['action']) {
                    case 'syncVariants':
                        $handler = new \Prospektweb\Calc\Services\SyncVariantsHandler();
                        $result[] = $handler->handle($request);
                        continue 2;
                        
                    case 'addNewDetail':
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $result[] = $handler->addDetail($request);
                        continue 2;
                        
                    case 'cloneDetail':
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $result[] = $handler->cloneDetail($request);
                        continue 2;
                        
                    case 'addNewGroup':
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $result[] = $handler->addGroup($request);
                        continue 2;
                        
                    case 'addNewStage':
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $result[] = $handler->addStage($request);
                        continue 2;
                        
                    case 'addStage':
                        // New handler for ADD_STAGE_REQUEST
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $detailHandler = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                        
                        $addResult = $handler->addStage($request);
                        if ($addResult['status'] === 'ok') {
                            // Add stage to preset
                            $presetId = (int)($request['presetId'] ?? 0);
                            $stageId = $addResult['config']['id'] ?? 0;
                            
                            if ($presetId > 0 && $stageId > 0) {
                                $detailHandler->addStageToPreset($presetId, $stageId);
                                
                                // Enrich preset based on first detail
                                $firstDetailId = $detailHandler->getFirstDetailFromPreset($presetId);
                                if ($firstDetailId) {
                                    $offerIds = $request['offerIds'] ?? [];
                                    $siteId = $request['siteId'] ?? SITE_ID;
                                    $initPayload = $detailHandler->enrichPresetFromDetails($presetId, $firstDetailId, $offerIds);
                                    
                                    $addResult['initPayload'] = $initPayload;
                                }
                            }
                        }
                        
                        $result[] = $addResult;
                        continue 2;
                        
                    case 'deleteStage':
                        // Updated handler for DELETE_STAGE_REQUEST
                        $stageId = (int)($request['stageId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        
                        if ($stageId > 0) {
                            // Physically delete stage element
                            if (!\CIBlockElement::Delete($stageId)) {
                                $result[] = [
                                    'status' => 'error',
                                    'message' => 'Не удалось удалить этап',
                                ];
                                continue 2;
                            }
                            
                            // Enrich preset based on first detail
                            if ($presetId > 0) {
                                $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                                $firstDetailId = $enrichmentService->getFirstDetailFromPreset($presetId);
                                
                                if ($firstDetailId) {
                                    $offerIds = $request['offerIds'] ?? [];
                                    $siteId = $request['siteId'] ?? SITE_ID;
                                    $initPayload = $enrichmentService->enrichPresetFromDetails($presetId, $firstDetailId, $offerIds);
                                    
                                    $result[] = [
                                        'status' => 'ok',
                                        'stageId' => $stageId,
                                        'initPayload' => $initPayload,
                                    ];
                                } else {
                                    $result[] = [
                                        'status' => 'ok',
                                        'stageId' => $stageId,
                                    ];
                                }
                            } else {
                                $result[] = [
                                    'status' => 'ok',
                                    'stageId' => $stageId,
                                ];
                            }
                        } else {
                            $result[] = [
                                'status' => 'error',
                                'message' => 'Stage ID обязателен',
                            ];
                        }
                        continue 2;
                    
                    case 'removeDetail':
                        // New handler for REMOVE_DETAIL_REQUEST with recursive logic
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $parentId = (int)($request['parentId'] ?? 0);
                        $detailId = (int)($request['detailId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        
                        $removeResult = $handler->removeDetailFromBinding($parentId, $detailId, $presetId);
                        
                        if ($removeResult['status'] === 'ok' && !empty($removeResult['needsEnrichment'])) {
                            // Enrich preset
                            $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                            $enrichDetailId = $removeResult['enrichmentDetailId'] ?? null;
                            
                            if ($enrichDetailId) {
                                $offerIds = $request['offerIds'] ?? [];
                                $siteId = $request['siteId'] ?? SITE_ID;
                                $initPayload = $enrichmentService->enrichPresetFromDetails($presetId, $enrichDetailId, $offerIds);
                                
                                $removeResult['initPayload'] = $initPayload;
                            }
                        }
                        
                        $result[] = $removeResult;
                        continue 2;
                    
                    case 'renameDetail':
                        // New handler for RENAME_DETAIL_REQUEST (silent mode)
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $detailId = (int)($request['detailId'] ?? 0);
                        $name = $request['name'] ?? '';
                        
                        $result[] = $handler->renameDetail($detailId, $name);
                        continue 2;
                    
                    case 'changeSettings':
                        // New handler for CHANGE_SETTINGS_REQUEST
                        $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                        $settingsId = (int)($request['settingsId'] ?? 0);
                        $stageId = (int)($request['stageId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        
                        if ($stageId > 0) {
                            // Update CALC_SETTINGS property
                            $enrichmentService->updateStageProperty($stageId, 'CALC_SETTINGS', $settingsId);
                            
                            // Enrich preset based on first detail
                            if ($presetId > 0) {
                                $firstDetailId = $enrichmentService->getFirstDetailFromPreset($presetId);
                                
                                if ($firstDetailId) {
                                    $offerIds = $request['offerIds'] ?? [];
                                    $siteId = $request['siteId'] ?? SITE_ID;
                                    $initPayload = $enrichmentService->enrichPresetFromDetails($presetId, $firstDetailId, $offerIds);
                                    
                                    $result[] = [
                                        'status' => 'ok',
                                        'initPayload' => $initPayload,
                                    ];
                                } else {
                                    $result[] = [
                                        'status' => 'ok',
                                    ];
                                }
                            } else {
                                $result[] = [
                                    'status' => 'ok',
                                ];
                            }
                        } else {
                            $result[] = [
                                'status' => 'error',
                                'message' => 'Stage ID обязателен',
                            ];
                        }
                        continue 2;
                    
                    case 'changeOperationVariant':
                        // New handler for CHANGE_OPERATION_VARIANT_REQUEST
                        $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                        $operationVariantId = (int)($request['operationVariantId'] ?? 0);
                        $stageId = (int)($request['stageId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        
                        if ($stageId > 0) {
                            // Update OPERATION_VARIANT property
                            $enrichmentService->updateStageProperty($stageId, 'OPERATION_VARIANT', $operationVariantId);
                            
                            // Enrich preset based on first detail
                            if ($presetId > 0) {
                                $firstDetailId = $enrichmentService->getFirstDetailFromPreset($presetId);
                                
                                if ($firstDetailId) {
                                    $offerIds = $request['offerIds'] ?? [];
                                    $siteId = $request['siteId'] ?? SITE_ID;
                                    $initPayload = $enrichmentService->enrichPresetFromDetails($presetId, $firstDetailId, $offerIds);
                                    
                                    $result[] = [
                                        'status' => 'ok',
                                        'initPayload' => $initPayload,
                                    ];
                                } else {
                                    $result[] = [
                                        'status' => 'ok',
                                    ];
                                }
                            } else {
                                $result[] = [
                                    'status' => 'ok',
                                ];
                            }
                        } else {
                            $result[] = [
                                'status' => 'error',
                                'message' => 'Stage ID обязателен',
                            ];
                        }
                        continue 2;
                    
                    case 'changeEquipment':
                        // New handler for CHANGE_EQUIPMENT_REQUEST
                        $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                        $equipmentId = (int)($request['equipmentId'] ?? 0);
                        $stageId = (int)($request['stageId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        
                        if ($stageId > 0) {
                            // Update EQUIPMENT property
                            $enrichmentService->updateStageProperty($stageId, 'EQUIPMENT', $equipmentId);
                            
                            // Enrich preset based on first detail
                            if ($presetId > 0) {
                                $firstDetailId = $enrichmentService->getFirstDetailFromPreset($presetId);
                                
                                if ($firstDetailId) {
                                    $offerIds = $request['offerIds'] ?? [];
                                    $siteId = $request['siteId'] ?? SITE_ID;
                                    $initPayload = $enrichmentService->enrichPresetFromDetails($presetId, $firstDetailId, $offerIds);
                                    
                                    $result[] = [
                                        'status' => 'ok',
                                        'initPayload' => $initPayload,
                                    ];
                                } else {
                                    $result[] = [
                                        'status' => 'ok',
                                    ];
                                }
                            } else {
                                $result[] = [
                                    'status' => 'ok',
                                ];
                            }
                        } else {
                            $result[] = [
                                'status' => 'error',
                                'message' => 'Stage ID обязателен',
                            ];
                        }
                        continue 2;
                    
                    case 'changeMaterialVariant':
                        // New handler for CHANGE_MATERIAL_VARIANT_REQUEST
                        $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                        $materialVariantId = (int)($request['materialVariantId'] ?? 0);
                        $stageId = (int)($request['stageId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        
                        if ($stageId > 0) {
                            // Update MATERIAL_VARIANT property
                            $enrichmentService->updateStageProperty($stageId, 'MATERIAL_VARIANT', $materialVariantId);
                            
                            // Enrich preset based on first detail
                            if ($presetId > 0) {
                                $firstDetailId = $enrichmentService->getFirstDetailFromPreset($presetId);
                                
                                if ($firstDetailId) {
                                    $offerIds = $request['offerIds'] ?? [];
                                    $siteId = $request['siteId'] ?? SITE_ID;
                                    $initPayload = $enrichmentService->enrichPresetFromDetails($presetId, $firstDetailId, $offerIds);
                                    
                                    $result[] = [
                                        'status' => 'ok',
                                        'initPayload' => $initPayload,
                                    ];
                                } else {
                                    $result[] = [
                                        'status' => 'ok',
                                    ];
                                }
                            } else {
                                $result[] = [
                                    'status' => 'ok',
                                ];
                            }
                        } else {
                            $result[] = [
                                'status' => 'error',
                                'message' => 'Stage ID обязателен',
                            ];
                        }
                        continue 2;
                    
                    case 'savePresetGlobals':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $variables = is_array($request['variables'] ?? null) ? $request['variables'] : [];
                        $constants = is_array($request['constants'] ?? null) ? $request['constants'] : [];
                        $presetsIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_PRESETS', 0);

                        if ($presetId <= 0 || $presetsIblockId <= 0) {
                            $result[] = ['status' => 'error', 'message' => 'Пресет или его инфоблок не найден'];
                            continue 2;
                        }

                        $prepareGlobals = static function (array $rows): array {
                            $prepared = [];
                            $seen = [];
                            foreach ($rows as $row) {
                                $code = trim((string)($row['VALUE'] ?? ''));
                                if ($code === '') {
                                    continue;
                                }
                                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $code)) {
                                    throw new \InvalidArgumentException("Некорректный код глобального значения: {$code}");
                                }
                                if (isset($seen[$code])) {
                                    throw new \InvalidArgumentException("Код глобального значения повторяется: {$code}");
                                }
                                $seen[$code] = true;
                                $prepared[] = [
                                    'VALUE' => $code,
                                    'DESCRIPTION' => (string)($row['DESCRIPTION'] ?? ''),
                                ];
                            }
                            return $prepared;
                        };

                        try {
                            foreach ([
                                'GLOBAL_VARIABLES' => ['NAME' => 'Глобальные переменные', 'SORT' => 1100],
                                'GLOBAL_CONSTANTS' => ['NAME' => 'Глобальные константы', 'SORT' => 1110],
                            ] as $propertyCode => $propertyConfig) {
                                $existingProperty = \CIBlockProperty::GetList([], [
                                    'IBLOCK_ID' => $presetsIblockId,
                                    'CODE' => $propertyCode,
                                ])->Fetch();
                                if (!$existingProperty) {
                                    $propertyApi = new \CIBlockProperty();
                                    $propertyId = $propertyApi->Add([
                                        'IBLOCK_ID' => $presetsIblockId,
                                        'ACTIVE' => 'Y',
                                        'CODE' => $propertyCode,
                                        'NAME' => $propertyConfig['NAME'],
                                        'PROPERTY_TYPE' => 'S',
                                        'MULTIPLE' => 'Y',
                                        'MULTIPLE_CNT' => 1,
                                        'WITH_DESCRIPTION' => 'Y',
                                        'SORT' => $propertyConfig['SORT'],
                                    ]);
                                    if (!$propertyId) {
                                        throw new \RuntimeException("Не удалось создать свойство {$propertyCode}");
                                    }
                                }
                            }

                            $preparedVariables = $prepareGlobals($variables);
                            $preparedConstants = $prepareGlobals($constants);
                            $allCodes = array_merge(
                                array_column($preparedVariables, 'VALUE'),
                                array_column($preparedConstants, 'VALUE')
                            );
                            if (count($allCodes) !== count(array_unique($allCodes))) {
                                throw new \InvalidArgumentException('Коды переменных и констант не должны повторяться');
                            }

                            \CIBlockElement::SetPropertyValuesEx($presetId, $presetsIblockId, [
                                'GLOBAL_VARIABLES' => $preparedVariables ?: false,
                                'GLOBAL_CONSTANTS' => $preparedConstants ?: false,
                            ]);

                            $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                            $firstDetailId = $enrichmentService->getFirstDetailFromPreset($presetId);
                            $initPayload = $firstDetailId
                                ? $enrichmentService->enrichPresetFromDetails($presetId, $firstDetailId, $request['offerIds'] ?? [])
                                : null;
                            $result[] = ['status' => 'ok', 'initPayload' => $initPayload];
                        } catch (\Throwable $error) {
                            $result[] = ['status' => 'error', 'message' => $error->getMessage()];
                        }
                        continue 2;

                    case 'changeCustomFieldsValue':
                        // New handler for CHANGE_CUSTOM_FIELDS_VALUE_REQUEST (silent mode)
                        $stageId = (int)($request['stageId'] ?? 0);
                        $customFieldsValue = $request['customFieldsValue'] ?? [];
                        
                        if ($stageId > 0 && !empty($customFieldsValue)) {
                            $stagesIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_STAGES', 0);
                            
                            $values = [];
                            foreach ($customFieldsValue as $field) {
                                $values[] = [
                                    'VALUE' => $field['CODE'],        // CODE идёт в VALUE
                                    'DESCRIPTION' => $field['VALUE'], // VALUE идёт в DESCRIPTION
                                ];
                            }
                            
                            \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                                'CUSTOM_FIELDS_VALUE' => $values,
                            ]);
                            
                            $result[] = [
                                'status' => 'ok',
                                'stageId' => $stageId,
                            ];
                        } else {
                            $result[] = [
                                'status' => 'error',
                                'message' => 'Stage ID и customFieldsValue обязательны',
                            ];
                        }
                        continue 2;
                        
                    case 'selectFields':
                        $settingsId = (int)($request['settingsId'] ?? 0);
                        $stageId = (int)($request['stageId'] ?? 0);
                        $customFieldIds = $this->normalizeIds($request['customFieldIds'] ?? []);

                        if ($settingsId > 0 && $stageId > 0 && !empty($customFieldIds)) {
                            $settingsIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_SETTINGS', 0);
                            $stagesIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_STAGES', 0);

                            $existingCustomFields = [];
                            $settingsProps = \CIBlockElement::GetProperty($settingsIblockId, $settingsId, ['sort' => 'asc'], ['CODE' => 'CUSTOM_FIELDS']);
                            while ($prop = $settingsProps->Fetch()) {
                                if (!empty($prop['VALUE'])) {
                                    $existingCustomFields[] = (int)$prop['VALUE'];
                                }
                            }

                            $mergedCustomFields = array_values(array_unique(array_merge($existingCustomFields, $customFieldIds)));
                            \CIBlockElement::SetPropertyValuesEx($settingsId, $settingsIblockId, [
                                'CUSTOM_FIELDS' => $mergedCustomFields,
                            ]);

                            $customFieldsService = new \Prospektweb\Calc\Services\CustomFieldsService();
                            $fieldsConfig = $customFieldsService->getFieldsConfig($customFieldIds);

                            $existingValuesMap = [];
                            $stageProps = \CIBlockElement::GetProperty($stagesIblockId, $stageId, ['sort' => 'asc'], ['CODE' => 'CUSTOM_FIELDS_VALUE']);
                            while ($prop = $stageProps->Fetch()) {
                                $fieldCode = (string)($prop['VALUE'] ?? '');
                                if ($fieldCode === '') {
                                    continue;
                                }

                                $existingValuesMap[$fieldCode] = [
                                    'VALUE' => $fieldCode,
                                    'DESCRIPTION' => (string)($prop['DESCRIPTION'] ?? ''),
                                ];
                            }

                            foreach ($fieldsConfig as $fieldConfig) {
                                $fieldCode = (string)($fieldConfig['code'] ?? '');
                                if ($fieldCode === '') {
                                    continue;
                                }

                                $description = '';
                                if (array_key_exists('default', $fieldConfig)) {
                                    $defaultValue = $fieldConfig['default'];
                                    if (is_bool($defaultValue)) {
                                        $defaultValue = $defaultValue ? 'Y' : 'N';
                                    }
                                    $description = (string)$defaultValue;
                                }

                                if (isset($existingValuesMap[$fieldCode])) {
                                    continue;
                                }

                                $existingValuesMap[$fieldCode] = [
                                    'VALUE' => $fieldCode,
                                    'DESCRIPTION' => $description,
                                ];
                            }

                            \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                                'CUSTOM_FIELDS_VALUE' => array_values($existingValuesMap),
                            ]);
                        }

                        $result[] = ['status' => 'ok'];
                        continue 2;

                    case 'saveSettingsEquipment':
                        $equipmentId = (int)($request['equipmentId'] ?? 0);
                        $equipmentName = trim((string)($request['name'] ?? ''));
                        $properties = is_array($request['properties'] ?? null) ? $request['properties'] : [];
                        $equipmentIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_EQUIPMENT', 0);

                        if ($equipmentId <= 0 || $equipmentIblockId <= 0) {
                            $result[] = ['status' => 'error', 'message' => 'Оборудование или его инфоблок не найдены'];
                            continue 2;
                        }

                        $prepared = [];
                        $responseProperties = [];
                        foreach (['MAX_LENGTH', 'MAX_WIDTH', 'MIN_WIDTH', 'MIN_LENGTH', 'START_COST'] as $code) {
                            $value = trim((string)($properties[$code] ?? ''));
                            if ($value !== '' && !is_numeric(str_replace(',', '.', $value))) {
                                $result[] = ['status' => 'error', 'message' => "Свойство {$code} должно быть числом"];
                                continue 3;
                            }
                            $normalizedValue = $value === '' ? false : str_replace(',', '.', $value);
                            $prepared[$code] = $normalizedValue;
                            $responseProperties[$code] = ['VALUE' => $normalizedValue];
                        }

                        $fieldParts = array_map('trim', explode(',', (string)($properties['FIELDS'] ?? '')));
                        if (count($fieldParts) !== 4 || array_filter($fieldParts, static function ($value): bool {
                            return $value !== '' && !preg_match('/^\d+$/', (string)$value);
                        })) {
                            $result[] = ['status' => 'error', 'message' => 'FIELDS должен содержать четыре пустых или целых значения'];
                            continue 2;
                        }
                        $prepared['FIELDS'] = implode(',', array_map(static function ($value): string {
                            return $value === '' ? '' : (string)(int)$value;
                        }, $fieldParts));
                        $responseProperties['FIELDS'] = ['VALUE' => $prepared['FIELDS']];

                        $parametrs = [];
                        $parametrValues = [];
                        $parametrDescriptions = [];
                        foreach ((array)($properties['PARAMETRS'] ?? []) as $parameter) {
                            if (!is_array($parameter)) {
                                continue;
                            }
                            $code = trim((string)($parameter['VALUE'] ?? ''));
                            $description = trim((string)($parameter['DESCRIPTION'] ?? ''));
                            if ($code === '' && $description === '') {
                                continue;
                            }
                            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $code)) {
                                $result[] = ['status' => 'error', 'message' => 'Некорректный дополнительный параметр оборудования'];
                                continue 3;
                            }
                            $parametrs[] = ['VALUE' => $code, 'DESCRIPTION' => $description];
                            $parametrValues[] = $code;
                            $parametrDescriptions[] = $description;
                        }
                        $prepared['PARAMETRS'] = $parametrs ?: false;
                        $responseProperties['PARAMETRS'] = [
                            'VALUE' => $parametrValues,
                            'DESCRIPTION' => $parametrDescriptions,
                        ];

                        if ($equipmentName !== '') {
                            $element = new \CIBlockElement();
                            if (!$element->Update($equipmentId, ['NAME' => $equipmentName])) {
                                $result[] = ['status' => 'error', 'message' => 'Не удалось сохранить название оборудования'];
                                continue 2;
                            }
                        }

                        \CIBlockElement::SetPropertyValuesEx($equipmentId, $equipmentIblockId, $prepared);

                        $result[] = [
                            'status' => 'ok',
                            'equipmentId' => $equipmentId,
                            'name' => $equipmentName,
                            'properties' => $responseProperties,
                        ];
                        continue 2;

                    case 'changeStageName':
                        $stageId = (int)($request['stageId'] ?? 0);
                        $name = trim((string)($request['name'] ?? ''));

                        if ($stageId > 0 && $name !== '') {
                            $el = new \CIBlockElement();
                            $el->Update($stageId, ['NAME' => $name]);
                        }

                        $result[] = ['status' => 'ok'];
                        continue 2;


                    case 'deleteDetail':
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $result[] = $handler->deleteDetail($request);
                        continue 2;
                        
                    case 'changeNameDetail':
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $result[] = $handler->changeName($request);
                        continue 2;
                        
                    case 'getDetailWithChildren':
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $detailId = (int)($request['detailId'] ?? 0);
                        $detailData = $handler->getDetailWithChildren($detailId);
                        if ($detailData) {
                            $result[] = [
                                'status' => 'ok',
                                'detail' => $detailData,
                            ];
                        } else {
                            $result[] = [
                                'status' => 'error',
                                'message' => 'Деталь не найдена',
                            ];
                        }
                        continue 2;
                        
                    case 'activatePricePanel':
                        $handler = new \Prospektweb\Calc\Services\PricePanelHandler();
                        $result[] = $handler->handleActivation($request);
                        continue 2;
                    
                    case 'addDetailToBinding':
                        // New handler for ADD_DETAIL_TO_BINDING_REQUEST
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $parentId = (int)($request['parentId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        
                        $addResult = $handler->addDetailToBinding($parentId);
                        
                        if ($addResult['status'] === 'ok' && $presetId > 0) {
                            // Enrich preset based on CALC_DETAILS[0]
                            $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                            $firstDetailId = $enrichmentService->getFirstDetailFromPreset($presetId);
                            
                            if ($firstDetailId) {
                                $offerIds = $request['offerIds'] ?? [];
                                $initPayload = $enrichmentService->enrichPresetFromDetails($presetId, $firstDetailId, $offerIds);
                                $addResult['initPayload'] = $initPayload;
                            }
                        }
                        
                        $result[] = $addResult;
                        continue 2;
                    
                    case 'changeDetailSort':
                        // New handler for CHANGE_DETAIL_SORT_REQUEST
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $parentId = (int)($request['parentId'] ?? 0);
                        $sorting = $request['sorting'] ?? [];
                        $presetId = (int)($request['presetId'] ?? 0);
                        
                        $sortResult = $handler->changeDetailSort($parentId, $sorting);
                        
                        if ($sortResult['status'] === 'ok' && $presetId > 0) {
                            // Enrich preset based on CALC_DETAILS[0]
                            $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                            $firstDetailId = $enrichmentService->getFirstDetailFromPreset($presetId);
                            
                            if ($firstDetailId) {
                                $offerIds = $request['offerIds'] ?? [];
                                $initPayload = $enrichmentService->enrichPresetFromDetails($presetId, $firstDetailId, $offerIds);
                                $sortResult['initPayload'] = $initPayload;
                            }
                        }
                        
                        $result[] = $sortResult;
                        continue 2;
                    
                    case 'changeDetailLevel':
                        // New handler for CHANGE_DETAIL_LEVEL_REQUEST
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $fromParentId = (int)($request['fromParentId'] ?? 0);
                        $detailId = (int)($request['detailId'] ?? 0);
                        $toParentId = (int)($request['toParentId'] ?? 0);
                        $sorting = $request['sorting'] ?? [];
                        $presetId = (int)($request['presetId'] ?? 0);
                        
                        $levelResult = $handler->changeDetailLevel($fromParentId, $detailId, $toParentId, $sorting);
                        
                        if ($levelResult['status'] === 'ok' && $presetId > 0) {
                            // Enrich preset based on CALC_DETAILS[0]
                            $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                            $firstDetailId = $enrichmentService->getFirstDetailFromPreset($presetId);
                            
                            if ($firstDetailId) {
                                $offerIds = $request['offerIds'] ?? [];
                                $initPayload = $enrichmentService->enrichPresetFromDetails($presetId, $firstDetailId, $offerIds);
                                $levelResult['initPayload'] = $initPayload;
                            }
                        }
                        
                        $result[] = $levelResult;
                        continue 2;
                    
                    case 'changeSortStage':
                        // New handler for CHANGE_SORT_STAGE_REQUEST
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $detailId = (int)($request['detailId'] ?? 0);
                        $sorting = $request['sorting'] ?? [];
                        $presetId = (int)($request['presetId'] ?? 0);
                        
                        $stageResult = $handler->changeSortStage($detailId, $sorting);
                        
                        if ($stageResult['status'] === 'ok' && $presetId > 0) {
                            // Enrich preset based on CALC_DETAILS[0]
                            $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                            $firstDetailId = $enrichmentService->getFirstDetailFromPreset($presetId);
                            
                            if ($firstDetailId) {
                                $offerIds = $request['offerIds'] ?? [];
                                $initPayload = $enrichmentService->enrichPresetFromDetails($presetId, $firstDetailId, $offerIds);
                                $stageResult['initPayload'] = $initPayload;
                            }
                        }
                        
                        $result[] = $stageResult;
                        continue 2;
                    
                    case 'addDetailsToBinding':
                        // New handler for adding selected details to binding
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $parentId = (int)($request['parentId'] ?? 0);
                        $detailIds = $request['detailIds'] ?? [];
                        $presetId = (int)($request['presetId'] ?? 0);
                        
                        $addDetailsResult = $handler->addDetailsToBinding($parentId, $detailIds);
                        
                        if ($addDetailsResult['status'] === 'ok' && $presetId > 0) {
                            // Enrich preset based on CALC_DETAILS[0]
                            $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                            $firstDetailId = $enrichmentService->getFirstDetailFromPreset($presetId);
                            
                            if ($firstDetailId) {
                                $offerIds = $request['offerIds'] ?? [];
                                $initPayload = $enrichmentService->enrichPresetFromDetails($presetId, $firstDetailId, $offerIds);
                                $addDetailsResult['initPayload'] = $initPayload;
                            }
                        }
                        
                        $result[] = $addDetailsResult;
                        continue 2;
                    

                    case 'changePricePreset':
                        // Handler for CHANGE_PRICE_PRESET_REQUEST
                        $priceService = new \Prospektweb\Calc\Services\PresetPriceService();
                        $presetId = (int)($request['presetId'] ?? 0);
                        $prices = $request['prices'] ?? [];
                        
                        $pricesResult = $priceService->changePricePreset($presetId, $prices);
                        
                        if ($pricesResult['status'] === 'ok') {
                            // Enrich preset
                            $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                            $firstDetailId = $enrichmentService->getFirstDetailFromPreset($presetId);
                            
                            if ($firstDetailId) {
                                $offerIds = $request['offerIds'] ?? [];
                                $siteId = $request['siteId'] ?? SITE_ID;
                                $initPayload = $enrichmentService->enrichPresetFromDetails($presetId, $firstDetailId, $offerIds);
                                $pricesResult['initPayload'] = $initPayload;
                            }
                        }
                        
                        $result[] = $pricesResult;
                        continue 2;

                    case 'updateOffersFromCalculation':
                        $offers = $request['results'] ?? [];
                        $service = new \Prospektweb\Calc\Services\OfferUpdateService();
                        $result[] = $service->updateOffersFromCalculation($offers);
                        continue 2;
                        
                    case 'updateStageProperty':
                        // Handler for CHANGE_OPTIONS_OPERATION and CHANGE_OPTIONS_MATERIAL
                        $stageId = (int)($request['stageId'] ?? 0);
                        $propertyCode = $request['propertyCode'] ?? '';
                        $value = $request['value'] ?? '';
                        
                        if ($stageId > 0 && !empty($propertyCode)) {
                            $stagesIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_STAGES', 0);
                            if ($stagesIblockId > 0) {
                                \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                                    $propertyCode => $value
                                ]);
                            }
                        }
                        $result[] = ['status' => 'ok'];
                        continue 2;
                        
                    case 'updateSettingsProperty':
                        // Handler for CHANGE_LOGIC
                        $settingsId = (int)($request['settingsId'] ?? 0);
                        $propertyCode = $request['propertyCode'] ?? '';
                        $value = $request['value'] ?? '';
                        
                        if ($settingsId > 0 && !empty($propertyCode)) {
                            $settingsIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_SETTINGS', 0);
                            if ($settingsIblockId > 0) {
                                \CIBlockElement::SetPropertyValuesEx($settingsId, $settingsIblockId, [
                                    $propertyCode => $value
                                ]);
                            }
                        }
                        $result[] = ['status' => 'ok'];
                        continue 2;
                }
            }

            $iblockId = isset($request['iblockId']) ? (int)$request['iblockId'] : 0;
            $iblockType = isset($request['iblockType']) ? (string)$request['iblockType'] : null;
            $ids = $this->normalizeIds($request['ids'] ?? []);
            
            // Новый параметр:  включать ли данные родительского элемента
            $includeParent = ! empty($request['includeParent']);

            $data = $ids ?  $this->loadElements($ids, $includeParent) : [];

            $result[] = [
                'iblockId' => $iblockId,
                'iblockType' => $iblockType,
                'ids' => $ids,
                'data' => $data,
            ];
        }

        return $result;
    }

    public function loadSingleElement(int $iblockId, int $id, ? string $iblockType = null, bool $includeParent = false): ?array
    {
        $payload = $this->prepareRefreshPayload([
            [
                'iblockId' => $iblockId,
                'iblockType' => $iblockType,
                'ids' => [$id],
                'includeParent' => $includeParent,
            ],
        ]);

        if (! empty($payload[0]['data'][0])) {
            return $payload[0]['data'][0];
        }

        return null;
    }

    private function loadElements(array $ids, bool $includeParent = false): array
    {
        $elements = [];

        foreach ($ids as $elementId) {
            $elementObject = \CIBlockElement::GetList(
                [],
                ['ID' => $elementId],
                false,
                false,
                ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'TIMESTAMP_X', 'MODIFIED_BY', 'PROPERTY_CML2_LINK']
            )->GetNextElement();

            if (! $elementObject) {
                continue;
            }

            $fields = $elementObject->GetFields();
            $properties = PropertyPayloadLoader::loadElementProperties((int)$fields['IBLOCK_ID'], (int)$fields['ID']);

            $productData = \CCatalogProduct::GetByID($elementId) ?: [];
            $measureInfo = $this->getMeasureInfo((int)($productData['MEASURE'] ?? 0));
            $measureRatio = $this->getMeasureRatio($elementId);
            $prices = $this->getPrices($elementId);
            $purchasingPrice = isset($productData['PURCHASING_PRICE'])
                ? (float)$productData['PURCHASING_PRICE']
                : null;
            $purchasingCurrency = $productData['PURCHASING_CURRENCY'] ?? null;

            // Определяем productId (ID родительского элемента)
            $productId = (int)($fields['PROPERTY_CML2_LINK_VALUE'] ?? 0);
            if ($productId <= 0) {
                $skuParent = \CCatalogSku::GetProductInfo($elementId);
                if (! empty($skuParent['ID'])) {
                    $productId = (int)$skuParent['ID'];
                }
            }

            $elementData = [
                'id' => (int)$fields['ID'],
                'iblockId' => (int)$fields['IBLOCK_ID'],
                'code' => $fields['CODE'] ?? null,
                'productId' => $productId > 0 ? $productId : null,
                'name' => $fields['NAME'] ?? '',
                'timestampX' => $fields['TIMESTAMP_X'] ?? null,
                'modifiedBy' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
                'timestamp_x' => $fields['TIMESTAMP_X'] ?? null,
                'modified_by' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
                'attributes' => [
                    'width' => isset($productData['WIDTH']) ? (float)$productData['WIDTH'] : null,
                    'height' => isset($productData['HEIGHT']) ? (float)$productData['HEIGHT'] :  null,
                    'length' => isset($productData['LENGTH']) ? (float)$productData['LENGTH'] : null,
                    'weight' => isset($productData['WEIGHT']) ? (float)$productData['WEIGHT'] : null,
                ],
                'measure' => $measureInfo,
                'measureRatio' => $measureRatio,
                'purchasingPrice' => $purchasingPrice,
                'purchasingCurrency' => $purchasingCurrency,
                'prices' => $prices,
                'properties' => $properties,
            ];

            // Если элемент имеет свойство CUSTOM_FIELDS, загружаем конфигурацию полей
            if (isset($properties['CUSTOM_FIELDS']) && !empty($properties['CUSTOM_FIELDS']['VALUE'])) {
                $customFieldsService = new \Prospektweb\Calc\Services\CustomFieldsService();
                $customFieldIds = is_array($properties['CUSTOM_FIELDS']['VALUE']) 
                    ? $properties['CUSTOM_FIELDS']['VALUE'] 
                    : [$properties['CUSTOM_FIELDS']['VALUE']];
                
                // Фильтруем пустые значения
                $customFieldIds = array_filter($customFieldIds, function($id) {
                    return !empty($id);
                });
                
                if (!empty($customFieldIds)) {
                    $elementData['customFields'] = $customFieldsService->getFieldsConfig($customFieldIds);
                }
            }
            // =====================================================

            // ========== Загрузка родительского элемента ==========
            if ($includeParent && $productId > 0) {
                $parentData = $this->loadParentElement($productId);
                if ($parentData !== null) {
                    $elementData['itemParent'] = $parentData;
                }
            }
            // ============================================================

            $elements[] = $elementData;
        }

        return $elements;
    }

    /**
     * Загружает данные родительского элемента (для SKU/вариантов).
     * 
     * @param int $parentId ID родительского элемента
     * @return array|null Данные родителя или null если не найден
     */
    private function loadParentElement(int $parentId): ?array
    {
        if ($parentId <= 0) {
            return null;
        }

        $elementObject = \CIBlockElement::GetList(
            [],
            ['ID' => $parentId],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'TIMESTAMP_X', 'MODIFIED_BY']
        )->GetNextElement();

        if (!$elementObject) {
            return null;
        }

        $fields = $elementObject->GetFields();
        $properties = PropertyPayloadLoader::loadElementProperties((int)$fields['IBLOCK_ID'], (int)$fields['ID']);

        return [
            'id' => (int)$fields['ID'],
            'iblockId' => (int)$fields['IBLOCK_ID'],
            'code' => $fields['CODE'] ?? null,
            'name' => $fields['NAME'] ?? '',
            'timestampX' => $fields['TIMESTAMP_X'] ?? null,
            'modifiedBy' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
            'timestamp_x' => $fields['TIMESTAMP_X'] ?? null,
            'modified_by' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
            'properties' => $properties,
        ];
    }

    private function normalizeIds($ids): array
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $normalized = [];
        foreach ($ids as $id) {
            $value = (int)$id;
            if ($value > 0) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }


    private function getMeasureRatio(int $productId): ?float
    {
        if ($productId <= 0) {
            return null;
        }

        $ratioIterator = \CCatalogMeasureRatio::getList(
            [],
            ['PRODUCT_ID' => $productId]
        );

        if ($ratio = $ratioIterator->Fetch()) {
            return isset($ratio['RATIO']) ? (float)$ratio['RATIO'] : null;
        }

        return null;
    }

    private function getMeasureInfo(int $measureId): ?array
    {
        if ($measureId <= 0) {
            return null;
        }

        $measureIterator = \CCatalogMeasure::getList(
            ['ID' => 'ASC'],
            ['=ID' => $measureId]
        );

        if ($measure = $measureIterator->Fetch()) {
            return [
                'id' => (int)$measure['ID'],
                'code' => $measure['CODE'] ?? null,
                'symbol' => $measure['SYMBOL'] ?? null,
                'symbolInt' => $measure['SYMBOL_INTL'] ?? null,
                'title' => $measure['MEASURE_TITLE'] ??  null,
            ];
        }

        return null;
    }

    private function getPrices(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $prices = [];
        $priceIterator = \CPrice::GetList(
            [],
            ['PRODUCT_ID' => $productId]
        );

        while ($price = $priceIterator->Fetch()) {
            $prices[] = [
                'typeId' => (int)$price['CATALOG_GROUP_ID'],
                'price' => (float)$price['PRICE'],
                'currency' => $price['CURRENCY'] ?? null,
                'quantityFrom' => isset($price['QUANTITY_FROM']) ? (int)$price['QUANTITY_FROM'] : null,
                'quantityTo' => isset($price['QUANTITY_TO']) ? (int)$price['QUANTITY_TO'] : null,
            ];
        }

        return $prices;
    }
}
