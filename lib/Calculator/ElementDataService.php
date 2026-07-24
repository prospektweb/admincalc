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
                    case 'getAiSettings':
                        $result[] = (new \Prospektweb\Calc\Services\AiGatewayService())->getSettings();
                        continue 2;

                    case 'saveAiSettings':
                        $result[] = (new \Prospektweb\Calc\Services\AiGatewayService())->saveSettings($request);
                        continue 2;

                    case 'generateStagePreview':
                        $result[] = (new \Prospektweb\Calc\Services\AiGatewayService())->generateStagePreview($request);
                        continue 2;

                    case 'generateAiText':
                        $result[] = (new \Prospektweb\Calc\Services\AiGatewayService())->generateText($request);
                        continue 2;

                    case 'getCatalogEntityMeta':
                        $result[] = (new \Prospektweb\Calc\Services\CatalogMetaService())->get($request);
                        continue 2;

                    case 'saveCatalogEntityMeta':
                        $result[] = (new \Prospektweb\Calc\Services\CatalogMetaService())->save($request);
                        continue 2;

                    case 'moveCatalogEntitySection':
                        $result[] = (new \Prospektweb\Calc\Services\CatalogMetaService())->moveToSection($request);
                        continue 2;

                    case 'createCatalogSection':
                        $result[] = (new \Prospektweb\Calc\Services\CatalogMetaService())->createSection($request);
                        continue 2;

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

                            // Preserve declaration metadata, but make every global
                            // value that referenced the deleted stage explicitly stale.
                            if ($presetId > 0) {
                                $this->markDeletedStageGlobalReferences($presetId, $stageId);
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
                        
                        if ($stageId > 0 && is_array($customFieldsValue)) {
                            $stagesIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_STAGES', 0);
                            
                            $values = [];
                            foreach ($customFieldsValue as $field) {
                                $code = trim((string)($field['CODE'] ?? ''));
                                $value = (string)($field['VALUE'] ?? '');
                                if ($code === '') {
                                    continue;
                                }
                                if (strpos($value, '|') !== false) {
                                    $result[] = ['status' => 'error', 'message' => 'Значение дополнительного параметра не может содержать символ |'];
                                    continue 3;
                                }
                                $visible = !array_key_exists('VISIBLE', $field) || filter_var($field['VISIBLE'], FILTER_VALIDATE_BOOLEAN);
                                $values[] = [
                                    'VALUE' => $code,
                                    'DESCRIPTION' => $value . '|' . ($visible ? 'Y' : 'N'),
                                ];
                            }
                            
                            \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                                'CUSTOM_FIELDS_VALUE' => $values ?: false,
                            ]);
                            
                            $result[] = [
                                'status' => 'ok',
                                'stageId' => $stageId,
                            ];
                        } else {
                            $result[] = [
                                'status' => 'error',
                                'message' => 'Stage ID и массив customFieldsValue обязательны',
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

                                $existingDescription = (string)($prop['DESCRIPTION'] ?? '');
                                $visibilityMarker = 'Y';
                                if (preg_match('/^(.*)\|[YN]$/s', $existingDescription, $matches)) {
                                    $visibilityMarker = substr($existingDescription, -1);
                                    $existingDescription = $matches[1];
                                }
                                $existingValuesMap[$fieldCode] = [
                                    'VALUE' => $fieldCode,
                                    'DESCRIPTION' => $existingDescription . '|' . $visibilityMarker,
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
                                    $existingValuesMap[$fieldCode]['DESCRIPTION'] = preg_replace('/\|N$/', '|Y', $existingValuesMap[$fieldCode]['DESCRIPTION']);
                                    continue;
                                }

                                $existingValuesMap[$fieldCode] = [
                                    'VALUE' => $fieldCode,
                                    'DESCRIPTION' => $description . '|Y',
                                ];
                            }

                            \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                                'CUSTOM_FIELDS_VALUE' => array_values($existingValuesMap),
                            ]);
                        }

                        $selectResponse = ['status' => 'ok'];
                        $presetId = (int)($request['presetId'] ?? 0);
                        $offerIds = $this->normalizeIds($request['offerIds'] ?? []);
                        if ($presetId > 0) {
                            $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                            $enrichmentService->synchronizePresetCustomFields($presetId);
                            if (!empty($offerIds)) {
                                $selectResponse['initPayload'] = (new InitPayloadService())->prepareInitPayload($offerIds, SITE_ID, false);
                            }
                        }
                        $result[] = $selectResponse;
                        continue 2;

                    case 'createCustomField':
                        $settingsId = (int)($request['settingsId'] ?? 0);
                        $stageId = (int)($request['stageId'] ?? 0);
                        $field = is_array($request['field'] ?? null) ? $request['field'] : [];
                        $name = trim((string)($field['name'] ?? ''));
                        $type = trim((string)($field['type'] ?? 'text'));
                        $allowedTypes = ['number', 'text', 'checkbox', 'select'];
                        if ($settingsId <= 0 || $stageId <= 0 || $name === '' || !in_array($type, $allowedTypes, true)) {
                            $result[] = ['status' => 'error', 'message' => 'Укажите название и корректный тип дополнительного параметра'];
                            continue 2;
                        }

                        $customFieldsIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_CUSTOM_FIELDS', 0);
                        $settingsIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_SETTINGS', 0);
                        $stagesIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_STAGES', 0);
                        if ($customFieldsIblockId <= 0 || $settingsIblockId <= 0 || $stagesIblockId <= 0) {
                            $result[] = ['status' => 'error', 'message' => 'Инфоблок дополнительных параметров не настроен'];
                            continue 2;
                        }

                        $code = strtoupper(trim((string)($field['code'] ?? '')));
                        if ($code === '') {
                            $code = strtoupper((string)\CUtil::translit($name, 'ru', [
                                'replace_space' => '_',
                                'replace_other' => '_',
                                'change_case' => 'U',
                                'delete_repeat_replace' => true,
                            ]));
                        }
                        $code = trim((string)preg_replace('/[^A-Z0-9_]+/', '_', $code), '_');
                        if ($code === '' || !preg_match('/^[A-Z]/', $code)) {
                            $code = 'FIELD_' . ($code !== '' ? $code : date('Ymd_His'));
                        }
                        $baseCode = $code;
                        $suffix = 2;
                        while (\CIBlockElement::GetList([], ['IBLOCK_ID' => $customFieldsIblockId, '=CODE' => $code], false, ['nTopCount' => 1], ['ID'])->Fetch()) {
                            $code = $baseCode . '_' . $suffix++;
                        }

                        $enumId = static function (int $iblockId, string $propertyCode, string $xmlId): int {
                            $property = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, '=CODE' => $propertyCode])->Fetch();
                            if (!$property) {
                                return 0;
                            }
                            $enum = \CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => (int)$property['ID'], '=XML_ID' => $xmlId])->Fetch();
                            return $enum ? (int)$enum['ID'] : 0;
                        };
                        $fieldTypeEnumId = $enumId($customFieldsIblockId, 'FIELD_TYPE', $type);
                        $requiredEnumId = $enumId($customFieldsIblockId, 'IS_REQUIRED', !empty($field['required']) ? 'Y' : 'N');
                        if ($fieldTypeEnumId <= 0) {
                            $result[] = ['status' => 'error', 'message' => 'Не найден тип дополнительного параметра в инфоблоке'];
                            continue 2;
                        }

                        $element = new \CIBlockElement();
                        $fieldId = (int)$element->Add([
                            'IBLOCK_ID' => $customFieldsIblockId,
                            'ACTIVE' => 'Y',
                            'NAME' => $name,
                            'CODE' => $code,
                            'PREVIEW_TEXT' => trim((string)($field['description'] ?? '')),
                            'PREVIEW_TEXT_TYPE' => 'text',
                            'PROPERTY_VALUES' => [
                                'FIELD_TYPE' => $fieldTypeEnumId,
                                'DEFAULT_VALUE' => (string)($field['defaultValue'] ?? ''),
                                'IS_REQUIRED' => $requiredEnumId ?: false,
                                'UNIT' => $type === 'number' ? trim((string)($field['unit'] ?? '')) : '',
                                'SORT_ORDER' => 500,
                            ],
                        ]);
                        if ($fieldId <= 0) {
                            $result[] = ['status' => 'error', 'message' => $element->LAST_ERROR ?: 'Битрикс не создал дополнительный параметр'];
                            continue 2;
                        }

                        $existingCustomFields = [];
                        $settingsProps = \CIBlockElement::GetProperty($settingsIblockId, $settingsId, ['sort' => 'asc'], ['CODE' => 'CUSTOM_FIELDS']);
                        while ($prop = $settingsProps->Fetch()) {
                            if (!empty($prop['VALUE'])) {
                                $existingCustomFields[] = (int)$prop['VALUE'];
                            }
                        }
                        $existingCustomFields[] = $fieldId;
                        \CIBlockElement::SetPropertyValuesEx($settingsId, $settingsIblockId, [
                            'CUSTOM_FIELDS' => array_values(array_unique($existingCustomFields)),
                        ]);

                        $existingValues = [];
                        $stageProps = \CIBlockElement::GetProperty($stagesIblockId, $stageId, ['sort' => 'asc'], ['CODE' => 'CUSTOM_FIELDS_VALUE']);
                        while ($prop = $stageProps->Fetch()) {
                            if ((string)($prop['VALUE'] ?? '') !== '') {
                                $existingValues[] = ['VALUE' => (string)$prop['VALUE'], 'DESCRIPTION' => (string)($prop['DESCRIPTION'] ?? '')];
                            }
                        }
                        $existingValues[] = ['VALUE' => $code, 'DESCRIPTION' => (string)($field['defaultValue'] ?? '') . '|Y'];
                        \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                            'CUSTOM_FIELDS_VALUE' => $existingValues,
                        ]);

                        $response = ['status' => 'ok', 'fieldId' => $fieldId, 'code' => $code];
                        $presetId = (int)($request['presetId'] ?? 0);
                        $offerIds = $this->normalizeIds($request['offerIds'] ?? []);
                        if ($presetId > 0) {
                            $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                            $enrichmentService->synchronizePresetCustomFields($presetId);
                            if (!empty($offerIds)) {
                                $response['initPayload'] = (new InitPayloadService())->prepareInitPayload($offerIds, SITE_ID, false);
                            }
                        }
                        $result[] = $response;
                        continue 2;

                    case 'saveSettingsEquipment':
                        $equipmentId = (int)($request['equipmentId'] ?? 0);
                        $createEquipment = !empty($request['create']);
                        $sectionId = (int)($request['sectionId'] ?? 0);
                        $equipmentName = trim((string)($request['name'] ?? ''));
                        $equipmentPreviewText = trim((string)($request['previewText'] ?? ''));
                        $equipmentDetailText = (string)($request['detailText'] ?? '');
                        $image = is_array($request['image'] ?? null) ? $request['image'] : null;
                        $catalog = is_array($request['catalog'] ?? null) ? $request['catalog'] : [];
                        $properties = is_array($request['properties'] ?? null) ? $request['properties'] : [];
                        $equipmentIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_EQUIPMENT', 0);

                        if ((!$createEquipment && $equipmentId <= 0) || $equipmentIblockId <= 0 || $equipmentName === '') {
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
                            if (substr_count($description, '|') > 2) {
                                $result[] = ['status' => 'error', 'message' => 'Символ | разрешён только как разделитель значения, названия и описания параметра'];
                                continue 3;
                            }
                            $descriptionParts = array_pad(explode('|', $description, 3), 3, '');
                            $description = implode('|', array_map('trim', $descriptionParts));
                            $parametrs[] = ['VALUE' => $code, 'DESCRIPTION' => $description];
                            $parametrValues[] = $code;
                            $parametrDescriptions[] = $description;
                        }
                        $prepared['PARAMETRS'] = $parametrs ?: false;
                        $responseProperties['PARAMETRS'] = [
                            'VALUE' => $parametrValues,
                            'DESCRIPTION' => $parametrDescriptions,
                        ];

                        $sourceLinks = [];
                        $sourceValues = [];
                        $sourceDescriptions = [];
                        foreach ((array)($properties['SOURCE_LINKS'] ?? []) as $sourceLink) {
                            if (!is_array($sourceLink)) {
                                continue;
                            }
                            $url = trim((string)($sourceLink['VALUE'] ?? ''));
                            $description = trim((string)($sourceLink['DESCRIPTION'] ?? ''));
                            if ($url === '' && $description === '') {
                                continue;
                            }
                            if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url)) {
                                $result[] = ['status' => 'error', 'message' => 'Некорректная ссылка на источник данных'];
                                continue 3;
                            }
                            if (substr_count($description, '|') > 1) {
                                $result[] = ['status' => 'error', 'message' => 'Символ | разрешён только как разделитель названия и описания ссылки'];
                                continue 3;
                            }
                            $descriptionParts = array_pad(explode('|', $description, 2), 2, '');
                            $description = implode('|', array_map('trim', $descriptionParts));
                            $sourceLinks[] = ['VALUE' => $url, 'DESCRIPTION' => $description];
                            $sourceValues[] = $url;
                            $sourceDescriptions[] = $description;
                        }
                        $prepared['SOURCE_LINKS'] = $sourceLinks ?: false;
                        $responseProperties['SOURCE_LINKS'] = [
                            'VALUE' => $sourceValues,
                            'DESCRIPTION' => $sourceDescriptions,
                        ];

                        $element = new \CIBlockElement();
                        $elementFields = [
                            'NAME' => $equipmentName,
                            'PREVIEW_TEXT' => $equipmentPreviewText,
                            'PREVIEW_TEXT_TYPE' => 'text',
                            'DETAIL_TEXT' => $equipmentDetailText,
                            'DETAIL_TEXT_TYPE' => 'html',
                        ];
                        if ($image) {
                            try {
                                $elementFields = array_merge($elementFields, $this->prepareEquipmentImageFields($image));
                            } catch (\Throwable $exception) {
                                $result[] = ['status' => 'error', 'message' => $exception->getMessage()];
                                continue 2;
                            }
                        }
                        $temporaryImagePaths = [];
                        foreach (['PREVIEW_PICTURE', 'DETAIL_PICTURE'] as $pictureField) {
                            $temporaryPath = (string)($elementFields[$pictureField]['tmp_name'] ?? '');
                            if ($temporaryPath !== '') {
                                $temporaryImagePaths[] = $temporaryPath;
                            }
                        }
                        $createdEquipment = false;
                        if ($createEquipment) {
                            if ($sectionId > 0 && !\CIBlockSection::GetList([], [
                                'ID' => $sectionId,
                                'IBLOCK_ID' => $equipmentIblockId,
                            ], false, ['ID'])->Fetch()) {
                                foreach ($temporaryImagePaths as $temporaryImagePath) {
                                    @unlink($temporaryImagePath);
                                }
                                $result[] = ['status' => 'error', 'message' => 'Выбранный раздел оборудования не найден'];
                                continue 2;
                            }
                            $elementFields += [
                                'IBLOCK_ID' => $equipmentIblockId,
                                'IBLOCK_SECTION_ID' => $sectionId > 0 ? $sectionId : false,
                                'ACTIVE' => 'Y',
                                'CODE' => $this->makeUniqueElementCode($equipmentIblockId, $equipmentName),
                            ];
                            $equipmentId = (int)$element->Add($elementFields);
                            $createdEquipment = $equipmentId > 0;
                        } else {
                            $equipmentId = $element->Update($equipmentId, $elementFields) ? $equipmentId : 0;
                        }
                        foreach ($temporaryImagePaths as $temporaryImagePath) {
                            @unlink($temporaryImagePath);
                        }
                        if ($equipmentId <= 0) {
                            $result[] = ['status' => 'error', 'message' => $element->LAST_ERROR ?: 'Не удалось сохранить оборудование'];
                            continue 2;
                        }

                        \CIBlockElement::SetPropertyValuesEx($equipmentId, $equipmentIblockId, $prepared);
                        try {
                            $catalogResponse = $this->saveEquipmentCatalog($equipmentId, $catalog);
                        } catch (\Throwable $exception) {
                            if ($createdEquipment) {
                                \CIBlockElement::Delete($equipmentId);
                            }
                            $result[] = ['status' => 'error', 'message' => $exception->getMessage()];
                            continue 2;
                        }
                        $savedElement = $this->loadElements([$equipmentId])[0] ?? null;

                        $result[] = [
                            'status' => 'ok',
                            'equipmentId' => $equipmentId,
                            'name' => $equipmentName,
                            'previewText' => $equipmentPreviewText,
                            'detailText' => $equipmentDetailText,
                            'catalog' => $catalogResponse,
                            'properties' => $responseProperties,
                            'previewPicture' => $savedElement['previewPicture'] ?? null,
                            'detailPicture' => $savedElement['detailPicture'] ?? null,
                            'element' => $savedElement,
                        ];
                        continue 2;

                    case 'changeStageName':
                        $stageId = (int)($request['stageId'] ?? 0);
                        $name = trim((string)($request['name'] ?? ''));
                        $previewText = trim((string)($request['previewText'] ?? ''));

                        if ($stageId > 0 && $name !== '') {
                            $el = new \CIBlockElement();
                            if (!$el->Update($stageId, [
                                'NAME' => $name,
                                'PREVIEW_TEXT' => $previewText,
                                'PREVIEW_TEXT_TYPE' => 'text',
                            ])) {
                                $result[] = ['status' => 'error', 'message' => $el->LAST_ERROR ?: 'Не удалось сохранить этап'];
                                continue 2;
                            }
                        }

                        $result[] = ['status' => 'ok', 'id' => $stageId, 'name' => $name, 'previewText' => $previewText];
                        continue 2;

                    case 'changeEntityMeta':
                        $entityId = (int)($request['entityId'] ?? 0);
                        $entityType = (string)($request['entityType'] ?? '');
                        $name = trim((string)($request['name'] ?? ''));
                        $previewText = trim((string)($request['previewText'] ?? ''));
                        if ($entityId <= 0 || !in_array($entityType, ['detail', 'preset'], true) || $name === '') {
                            $result[] = ['status' => 'error', 'message' => 'Некорректные данные сущности'];
                            continue 2;
                        }
                        $el = new \CIBlockElement();
                        if (!$el->Update($entityId, [
                            'NAME' => $name,
                            'PREVIEW_TEXT' => $previewText,
                            'PREVIEW_TEXT_TYPE' => 'text',
                        ])) {
                            $result[] = ['status' => 'error', 'message' => $el->LAST_ERROR ?: 'Не удалось сохранить данные'];
                            continue 2;
                        }
                        $result[] = [
                            'status' => 'ok',
                            'entityType' => $entityType,
                            'id' => $entityId,
                            'name' => $name,
                            'previewText' => $previewText,
                        ];
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
                                try {
                                    $offerIds = $request['offerIds'] ?? [];
                                    $initPayload = $enrichmentService->enrichPresetFromDetails($presetId, $firstDetailId, $offerIds);
                                    $stageResult['initPayload'] = $initPayload;
                                } catch (\Throwable $enrichmentError) {
                                    $stageResult['enrichmentWarning'] = $enrichmentError->getMessage();
                                }
                            }
                        }
                        
                        $result[] = $stageResult;
                        continue 2;

                    case 'changeRootDetailSort':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $sorting = array_values(array_filter(array_map('intval', is_array($request['sorting'] ?? null) ? $request['sorting'] : [])));
                        $presetsIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_PRESETS', 0);
                        if ($presetId <= 0 || $presetsIblockId <= 0 || !$sorting) {
                            $result[] = ['status' => 'error', 'message' => 'Некорректные параметры сортировки колонок'];
                            continue 2;
                        }
                        if (count($sorting) !== count(array_unique($sorting))) {
                            $result[] = ['status' => 'error', 'message' => 'Порядок колонок содержит повторяющиеся детали'];
                            continue 2;
                        }

                        $readRootIds = static function () use ($presetsIblockId, $presetId): array {
                            $ids = [];
                            $rows = \CIBlockElement::GetProperty(
                                $presetsIblockId,
                                $presetId,
                                ['sort' => 'asc', 'id' => 'asc'],
                                ['CODE' => 'CALC_DETAILS']
                            );
                            while ($property = $rows->Fetch()) {
                                $id = (int)($property['VALUE'] ?? 0);
                                if ($id > 0) {
                                    $ids[] = $id;
                                }
                            }
                            return $ids;
                        };

                        $connection = \Bitrix\Main\Application::getConnection();
                        try {
                            $connection->startTransaction();
                            $connection->queryExecute(
                                'SELECT ID FROM b_iblock_element WHERE ID = ' . $presetId . ' FOR UPDATE'
                            );
                            $current = $readRootIds();
                            $expected = $current;
                            $submitted = $sorting;
                            sort($expected);
                            sort($submitted);
                            if ($expected !== $submitted) {
                                throw new \RuntimeException('Состав колонок изменился. Обновите данные и повторите операцию');
                            }
                            \CIBlockElement::SetPropertyValuesEx($presetId, $presetsIblockId, [
                                'CALC_DETAILS' => false,
                            ]);
                            \CIBlockElement::SetPropertyValuesEx($presetId, $presetsIblockId, [
                                'CALC_DETAILS' => $sorting,
                            ]);
                            if ($readRootIds() !== $sorting) {
                                throw new \RuntimeException('Битрикс не сохранил точный порядок колонок');
                            }
                            $connection->commitTransaction();
                            $sortResult = ['status' => 'ok', 'presetId' => $presetId, 'sorting' => $sorting];
                            if (!empty($request['offerIds'])) {
                                try {
                                    $sortResult['initPayload'] = (new InitPayloadService())->prepareInitPayload(
                                        $request['offerIds'],
                                        $request['siteId'] ?? SITE_ID,
                                        false
                                    );
                                } catch (\Throwable $enrichmentError) {
                                    $sortResult['enrichmentWarning'] = $enrichmentError->getMessage();
                                }
                            }
                            $result[] = $sortResult;
                        } catch (\Throwable $sortError) {
                            try {
                                $connection->rollbackTransaction();
                            } catch (\Throwable $rollbackError) {
                            }
                            $result[] = ['status' => 'error', 'message' => $sortError->getMessage()];
                        }
                        continue 2;

                    case 'moveStage':
                        $handler = new \Prospektweb\Calc\Services\DetailHandler();
                        $stageResult = $handler->moveStage(
                            (int)($request['stageId'] ?? 0),
                            (int)($request['sourceDetailId'] ?? 0),
                            (int)($request['targetDetailId'] ?? 0),
                            is_array($request['sourceSorting'] ?? null) ? $request['sourceSorting'] : [],
                            is_array($request['targetSorting'] ?? null) ? $request['targetSorting'] : []
                        );
                        $presetId = (int)($request['presetId'] ?? 0);
                        if ($stageResult['status'] === 'ok' && $presetId > 0) {
                            $enrichmentService = new \Prospektweb\Calc\Services\PresetEnrichmentService();
                            $firstDetailId = $enrichmentService->getFirstDetailFromPreset($presetId);
                            if ($firstDetailId) {
                                try {
                                    $stageResult['initPayload'] = $enrichmentService->enrichPresetFromDetails(
                                        $presetId,
                                        $firstDetailId,
                                        $request['offerIds'] ?? []
                                    );
                                } catch (\Throwable $enrichmentError) {
                                    $stageResult['enrichmentWarning'] = $enrichmentError->getMessage();
                                }
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
                                if (in_array($propertyCode, ['GLOBAL_ASSIGNMENTS', 'OPTIONS_EQUIPMENT'], true)) {
                                    $existingProperty = \CIBlockProperty::GetList([], [
                                        'IBLOCK_ID' => $stagesIblockId,
                                        'CODE' => $propertyCode,
                                    ])->Fetch();
                                    if (!$existingProperty) {
                                        $isGlobalAssignments = $propertyCode === 'GLOBAL_ASSIGNMENTS';
                                        $propertyApi = new \CIBlockProperty();
                                        $propertyId = $propertyApi->Add([
                                            'IBLOCK_ID' => $stagesIblockId,
                                            'ACTIVE' => 'Y',
                                            'CODE' => $propertyCode,
                                            'NAME' => $isGlobalAssignments
                                                ? 'Определения глобальных значений этапа'
                                                : 'Настройки выбора оборудования',
                                            'PROPERTY_TYPE' => 'S',
                                            'USER_TYPE' => $isGlobalAssignments ? 'HTML' : '',
                                            'MULTIPLE' => 'N',
                                            'SORT' => $isGlobalAssignments ? 180 : 820,
                                        ]);
                                        if (!$propertyId) {
                                            throw new \RuntimeException('Не удалось создать свойство ' . $propertyCode);
                                        }
                                    }
                                }
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

    private function makeUniqueElementCode(int $iblockId, string $name): string
    {
        $base = trim((string)\CUtil::translit($name, 'ru', [
            'replace_space' => '-',
            'replace_other' => '-',
            'change_case' => 'L',
            'delete_repeat_replace' => true,
        ]), '-');
        if ($base === '') {
            $base = 'equipment';
        }
        $code = $base;
        $suffix = 2;
        while (\CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, '=CODE' => $code], false, ['nTopCount' => 1], ['ID'])->Fetch()) {
            $code = $base . '-' . $suffix++;
        }
        return $code;
    }

    private function prepareEquipmentImageFields(array $image): array
    {
        $dataUrl = (string)($image['dataUrl'] ?? '');
        if (!preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,(.+)$#s', $dataUrl, $matches)) {
            throw new \RuntimeException('Некорректные данные изображения');
        }
        $binary = base64_decode($matches[1], true);
        if ($binary === false || strlen($binary) > 12 * 1024 * 1024) {
            throw new \RuntimeException('Изображение повреждено или превышает 12 МБ');
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            throw new \RuntimeException('На сервере недоступно преобразование изображений в WebP');
        }
        $resource = @imagecreatefromstring($binary);
        if (!$resource) {
            throw new \RuntimeException('Не удалось прочитать изображение');
        }
        $width = imagesx($resource);
        $height = imagesy($resource);
        $detailBasePath = tempnam(sys_get_temp_dir(), 'pw-equipment-');
        if ($detailBasePath === false) {
            imagedestroy($resource);
            throw new \RuntimeException('Не удалось подготовить временный файл изображения');
        }
        $detailPath = $detailBasePath . '.webp';
        @unlink($detailBasePath);
        if (!imagewebp($resource, $detailPath, 88)) {
            imagedestroy($resource);
            throw new \RuntimeException('Не удалось преобразовать изображение в WebP');
        }
        imagedestroy($resource);

        $previewBasePath = tempnam(sys_get_temp_dir(), 'pw-equipment-preview-');
        if ($previewBasePath === false) {
            @unlink($detailPath);
            throw new \RuntimeException('Не удалось подготовить превью изображения');
        }
        $previewPath = $previewBasePath . '.webp';
        @unlink($previewBasePath);
        $previewWidth = min(200, $width);
        $previewHeight = min(200, $height);
        $previewCreated = \CFile::ResizeImageFile(
            $detailPath,
            $previewPath,
            ['width' => $previewWidth, 'height' => $previewHeight],
            BX_RESIZE_IMAGE_PROPORTIONAL,
            [],
            false,
            88
        );
        if (!$previewCreated) {
            @copy($detailPath, $previewPath);
        }

        $previewFile = \CFile::MakeFileArray($previewPath);
        if (!is_array($previewFile)) {
            @unlink($previewPath);
            @unlink($detailPath);
            throw new \RuntimeException('Не удалось подготовить превью изображения');
        }
        $previewFile['name'] = 'equipment-preview.webp';
        $fields = ['PREVIEW_PICTURE' => $previewFile];
        if ($width >= 200 || $height >= 200) {
            $detailFile = \CFile::MakeFileArray($detailPath);
            if (!is_array($detailFile)) {
                @unlink($previewPath);
                @unlink($detailPath);
                throw new \RuntimeException('Не удалось подготовить детальное изображение');
            }
            $detailFile['name'] = 'equipment.webp';
            $fields['DETAIL_PICTURE'] = $detailFile;
        } else {
            @unlink($detailPath);
            $fields['DETAIL_PICTURE'] = ['del' => 'Y'];
        }
        return $fields;
    }

    private function saveEquipmentCatalog(int $equipmentId, array $catalog): array
    {
        $normalizeNumber = static function ($value): ?float {
            $value = trim(str_replace(',', '.', (string)$value));
            if ($value === '') {
                return null;
            }
            if (!is_numeric($value)) {
                throw new \RuntimeException('Параметр торгового каталога должен быть числом');
            }
            return (float)$value;
        };
        $productFields = [
            'VAT_ID' => (int)($catalog['vatId'] ?? 0),
            'VAT_INCLUDED' => !empty($catalog['vatIncluded']) ? 'Y' : 'N',
            'PURCHASING_PRICE' => $normalizeNumber($catalog['purchasingPrice'] ?? null),
            'PURCHASING_CURRENCY' => trim((string)($catalog['purchasingCurrency'] ?? 'RUB')) ?: 'RUB',
            'WEIGHT' => $normalizeNumber($catalog['weight'] ?? null),
            'LENGTH' => $normalizeNumber($catalog['length'] ?? null),
            'WIDTH' => $normalizeNumber($catalog['width'] ?? null),
            'HEIGHT' => $normalizeNumber($catalog['height'] ?? null),
        ];
        $existing = \CCatalogProduct::GetByID($equipmentId);
        $saved = $existing
            ? \CCatalogProduct::Update($equipmentId, $productFields)
            : \CCatalogProduct::Add(['ID' => $equipmentId] + $productFields);
        if (!$saved) {
            throw new \RuntimeException('Не удалось сохранить параметры торгового каталога');
        }

        $basePrice = $normalizeNumber($catalog['basePrice'] ?? null);
        $baseCurrency = trim((string)($catalog['baseCurrency'] ?? 'RUB')) ?: 'RUB';
        $baseGroup = \CCatalogGroup::GetBaseGroup();
        if ($basePrice !== null && !empty($baseGroup['ID'])) {
            $price = \CPrice::GetList([], ['PRODUCT_ID' => $equipmentId, 'CATALOG_GROUP_ID' => (int)$baseGroup['ID']])->Fetch();
            $priceFields = [
                'PRODUCT_ID' => $equipmentId,
                'CATALOG_GROUP_ID' => (int)$baseGroup['ID'],
                'PRICE' => $basePrice,
                'CURRENCY' => $baseCurrency,
            ];
            $priceSaved = $price ? \CPrice::Update((int)$price['ID'], $priceFields) : \CPrice::Add($priceFields);
            if (!$priceSaved) {
                throw new \RuntimeException('Не удалось сохранить базовую цену оборудования');
            }
        }
        return [
            'vatId' => $productFields['VAT_ID'],
            'vatIncluded' => $productFields['VAT_INCLUDED'] === 'Y',
            'purchasingPrice' => $productFields['PURCHASING_PRICE'],
            'purchasingCurrency' => $productFields['PURCHASING_CURRENCY'],
            'basePrice' => $basePrice,
            'baseCurrency' => $baseCurrency,
            'weight' => $productFields['WEIGHT'],
            'length' => $productFields['LENGTH'],
            'width' => $productFields['WIDTH'],
            'height' => $productFields['HEIGHT'],
        ];
    }

    private function getPicturePayload(int $fileId): ?array
    {
        if ($fileId <= 0) {
            return null;
        }
        $file = \CFile::GetFileArray($fileId);
        if (!$file) {
            return null;
        }
        return [
            'id' => $fileId,
            'url' => (string)($file['SRC'] ?? ''),
            'width' => (int)($file['WIDTH'] ?? 0),
            'height' => (int)($file['HEIGHT'] ?? 0),
        ];
    }

    private function getCatalogOptions(): array
    {
        static $options;
        if ($options !== null) {
            return $options;
        }
        $vatRates = [];
        $vatResult = \CCatalogVat::GetList(['SORT' => 'ASC'], ['ACTIVE' => 'Y']);
        while ($vat = $vatResult->Fetch()) {
            $vatRates[] = [
                'id' => (int)$vat['ID'],
                'name' => (string)$vat['NAME'],
                'value' => isset($vat['RATE']) ? (float)$vat['RATE'] : null,
            ];
        }
        $currencies = [];
        $currencyBy = 'sort';
        $currencyOrder = 'asc';
        $currencyResult = \CCurrency::GetList($currencyBy, $currencyOrder);
        while ($currency = $currencyResult->Fetch()) {
            $currencies[] = [
                'code' => (string)$currency['CURRENCY'],
                'name' => (string)($currency['FULL_NAME'] ?? $currency['CURRENCY']),
            ];
        }
        return $options = ['vatRates' => $vatRates, 'currencies' => $currencies];
    }

    private function loadElements(array $ids, bool $includeParent = false): array
    {
        $elements = [];
        $equipmentIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_EQUIPMENT', 0);

        foreach ($ids as $elementId) {
            $elementObject = \CIBlockElement::GetList(
                [],
                ['ID' => $elementId],
                false,
                false,
                ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'PREVIEW_TEXT', 'DETAIL_TEXT', 'PREVIEW_PICTURE', 'DETAIL_PICTURE', 'TIMESTAMP_X', 'MODIFIED_BY', 'PROPERTY_CML2_LINK']
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
            $basePrice = null;
            $baseCurrency = null;
            $baseGroup = \CCatalogGroup::GetBaseGroup();
            if (!empty($baseGroup['ID'])) {
                foreach ($prices as $priceRow) {
                    if ((int)($priceRow['typeId'] ?? 0) === (int)$baseGroup['ID']) {
                        $basePrice = isset($priceRow['price']) ? (float)$priceRow['price'] : null;
                        $baseCurrency = $priceRow['currency'] ?? null;
                        break;
                    }
                }
            }
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
                'sectionId' => isset($fields['IBLOCK_SECTION_ID']) ? (int)$fields['IBLOCK_SECTION_ID'] : 0,
                'code' => $fields['CODE'] ?? null,
                'productId' => $productId > 0 ? $productId : null,
                'name' => $fields['NAME'] ?? '',
                'previewText' => (string)($fields['PREVIEW_TEXT'] ?? ''),
                'detailText' => (string)($fields['DETAIL_TEXT'] ?? ''),
                'previewPicture' => $this->getPicturePayload((int)($fields['PREVIEW_PICTURE'] ?? 0)),
                'detailPicture' => $this->getPicturePayload((int)($fields['DETAIL_PICTURE'] ?? 0)),
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
                'catalog' => [
                    'vatId' => (int)($productData['VAT_ID'] ?? 0),
                    'vatIncluded' => ($productData['VAT_INCLUDED'] ?? 'N') === 'Y',
                    'purchasingPrice' => $purchasingPrice,
                    'purchasingCurrency' => $purchasingCurrency,
                    'basePrice' => $basePrice,
                    'baseCurrency' => $baseCurrency,
                    'weight' => isset($productData['WEIGHT']) ? (float)$productData['WEIGHT'] : null,
                    'length' => isset($productData['LENGTH']) ? (float)$productData['LENGTH'] : null,
                    'width' => isset($productData['WIDTH']) ? (float)$productData['WIDTH'] : null,
                    'height' => isset($productData['HEIGHT']) ? (float)$productData['HEIGHT'] : null,
                ],
                'properties' => $properties,
            ];
            if ((int)$fields['IBLOCK_ID'] === $equipmentIblockId) {
                $elementData['catalogOptions'] = $this->getCatalogOptions();
            }

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

    private function markDeletedStageGlobalReferences(int $presetId, int $stageId): void
    {
        $presetsIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_PRESETS', 0);
        if ($presetId <= 0 || $stageId <= 0 || $presetsIblockId <= 0) {
            return;
        }

        foreach (['GLOBAL_CONSTANTS', 'GLOBAL_VARIABLES'] as $propertyCode) {
            $rows = [];
            $iterator = \CIBlockElement::GetProperty(
                $presetsIblockId,
                $presetId,
                ['sort' => 'asc', 'id' => 'asc'],
                ['CODE' => $propertyCode]
            );

            while ($property = $iterator->Fetch()) {
                $description = (string)($property['DESCRIPTION'] ?? '');
                $separatorPosition = null;
                $escaped = false;
                $length = strlen($description);
                for ($index = 0; $index < $length; $index++) {
                    $character = $description[$index];
                    if ($character === '\\') {
                        $escaped = !$escaped;
                        continue;
                    }
                    if ($character === '|' && !$escaped) {
                        $separatorPosition = $index;
                        break;
                    }
                    $escaped = false;
                }

                $formula = $separatorPosition === null ? $description : substr($description, 0, $separatorPosition);
                if (preg_match('/(^|[^A-Za-z0-9_])stage_' . preg_quote((string)$stageId, '/') . '(?:\.|$)/', $formula)) {
                    $description = '{StageDeleted}' . ($separatorPosition === null ? '' : substr($description, $separatorPosition));
                }

                $rows[] = [
                    'VALUE' => (string)($property['VALUE'] ?? ''),
                    'DESCRIPTION' => $description,
                ];
            }

            if ($rows !== []) {
                \CIBlockElement::SetPropertyValuesEx($presetId, $presetsIblockId, [
                    $propertyCode => $rows,
                ]);
            }
        }
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
