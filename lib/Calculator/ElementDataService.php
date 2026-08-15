<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Loader;

class ElementDataService
{
    /** @var array<string,int> */
    private array $pinnedRuntimeIblockIds;

    /** @param array<string,int> $pinnedRuntimeIblockIds */
    public function __construct(array $pinnedRuntimeIblockIds = [])
    {
        $this->pinnedRuntimeIblockIds = $pinnedRuntimeIblockIds;
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

                    case 'generateLogicProposal':
                        $result[] = (new \Prospektweb\Calc\Services\AiGatewayService())->generateLogicProposal(
                            is_array($request['request'] ?? null) ? $request['request'] : []
                        );
                        continue 2;

                    case 'generateStageLogicProposal':
                        $result[] = (new \Prospektweb\Calc\Services\AiGatewayService())->generateStageLogicProposal(
                            is_array($request['request'] ?? null) ? $request['request'] : []
                        );
                        continue 2;

                    case 'generateLogicAudit':
                        $result[] = (new \Prospektweb\Calc\Services\AiGatewayService())->generateLogicAudit(
                            is_array($request['request'] ?? null) ? $request['request'] : []
                        );
                        continue 2;

                    case 'saveGlobalSymbols':
                        $result[] = (new \Prospektweb\Calc\Services\GlobalSymbolService())->save(
                            is_array($request['symbols'] ?? null) ? $request['symbols'] : [],
                            (int)($request['presetId'] ?? 0)
                        );
                        continue 2;

                    case 'previewGlobalCodeRefactor':
                        $result[] = (new \Prospektweb\Calc\Services\GlobalCodeRefactorService())->preview($request);
                        continue 2;

                    case 'applyGlobalCodeRefactor':
                        $result[] = (new \Prospektweb\Calc\Services\GlobalCodeRefactorService())->apply($request);
                        continue 2;

                    case 'saveStageGroups':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $result[] = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($request, $presetId): array {
                            if ($presetId === \Prospektweb\Calc\Services\NeutralFormulaPolicy::PRESET_ID
                                && $protected) {
                                throw new \RuntimeException(
                                    'Stage groups for preset 12740 are frozen after neutral migration begins.',
                                    409
                                );
                            }
                            return (new \Prospektweb\Calc\Services\StageGroupService($pinnedIblockIds))
                                ->save($request, false);
                        });
                        continue 2;

                    case 'clonePreset':
                        global $USER;
                        if (!$USER || !$USER->IsAdmin()) {
                            throw new \RuntimeException('Недостаточно прав для клонирования пресета');
                        }
                        $presetId = (int)($request['presetId'] ?? 0);
                        $offerIds = array_values(array_filter(array_map(
                            'intval',
                            is_array($request['offerIds'] ?? null) ? $request['offerIds'] : []
                        )));
                        if ($presetId <= 0 || $offerIds === []) {
                            throw new \InvalidArgumentException('Для клонирования нужны пресет и торговые предложения текущего товара');
                        }
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $newPresetId = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected
                        ) use ($presetId, $offerIds): int {
                            \Prospektweb\Calc\Services\NeutralFormulaPolicy::assertCloneAllowed(
                                $presetId,
                                $protected,
                                'preset graph'
                            );
                            return (new BundleHandler())->clonePreset($presetId, $offerIds);
                        });
                        $siteId = (string)($request['siteId'] ?? (defined('SITE_ID') ? SITE_ID : 's1'));
                        $initPayload = (new InitPayloadService())->prepareInitPayload($offerIds, $siteId, false);
                        if ((int)($initPayload['preset']['id'] ?? 0) !== $newPresetId) {
                            throw new \RuntimeException('После клонирования редактор не получил новый пресет');
                        }
                        $result[] = [
                            'status' => 'ok',
                            'sourcePresetId' => $presetId,
                            'newPresetId' => $newPresetId,
                            'initPayload' => $initPayload,
                        ];
                        continue 2;

                    case 'previewStageLogicPrompt':
                        $result[] = (new \Prospektweb\Calc\Services\AiGatewayService())->previewStageLogicPrompt(
                            is_array($request['request'] ?? null) ? $request['request'] : []
                        );
                        continue 2;

                    case 'getAiBaseProducts':
                        $result[] = (new \Prospektweb\Calc\Services\AiCalculatorContextService())->getBaseProducts($request);
                        continue 2;

                    case 'saveAiCalculatorContext':
                        $result[] = (new \Prospektweb\Calc\Services\AiCalculatorContextService())->save($request);
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

                    case 'getCatalogTree':
                        $result[] = (new \Prospektweb\Calc\Services\CatalogTreeService())->tree($request);
                        continue 2;

                    case 'getPresetLoadOptions':
                        $result[] = (new \Prospektweb\Calc\Services\CatalogTreeService())->presetLoadOptions($request);
                        continue 2;

                    case 'saveCatalogTreeElement':
                        $result[] = (new \Prospektweb\Calc\Services\CatalogTreeService())->saveElement($request);
                        continue 2;

                    case 'saveCatalogTreeSection':
                        $result[] = (new \Prospektweb\Calc\Services\CatalogTreeService())->saveSection($request);
                        continue 2;

                    case 'deleteCatalogTreeNode':
                        $result[] = (new \Prospektweb\Calc\Services\CatalogTreeService())->deleteNode($request);
                        continue 2;

                    case 'syncVariants':
                        throw new \RuntimeException(
                            'Legacy syncVariants mutation is disabled; use explicit editor operations.',
                            409
                        );
                        
                    case 'addNewDetail':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $addResult = $neutralFormulaPolicy
                            ->withActiveAuthorityLock(static function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($request, $presetId, $neutralFormulaPolicy): array {
                                $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                    $presetId,
                                    [],
                                    $protected,
                                    'details'
                                );
                                $created = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                    ->addDetail($request);
                                return self::enrichStructuralResultPinned(
                                    $created,
                                    $presetId,
                                    $request['offerIds'] ?? [],
                                    $pinnedIblockIds
                                );
                            });
                        $result[] = $addResult;
                        continue 2;
                        
                    case 'cloneDetail':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $cloneResult = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($request, $presetId, $neutralFormulaPolicy): array {
                            $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                $presetId,
                                [(int)($request['detailId'] ?? 0)],
                                $protected,
                                'details'
                            );
                            $clone = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                ->cloneDetail($request);
                            return self::enrichStructuralResultPinned(
                                $clone,
                                $presetId,
                                $request['offerIds'] ?? [],
                                $pinnedIblockIds
                            );
                        });
                        $result[] = $cloneResult;
                        continue 2;

                    case 'cloneDetails':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $cloneResult = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($request, $presetId, $neutralFormulaPolicy): array {
                            $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                $presetId,
                                is_array($request['detailIds'] ?? null) ? $request['detailIds'] : [],
                                $protected,
                                'details'
                            );
                            $clone = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                ->cloneDetails($request);
                            return self::enrichStructuralResultPinned(
                                $clone,
                                $presetId,
                                $request['offerIds'] ?? [],
                                $pinnedIblockIds
                            );
                        });
                        $result[] = $cloneResult;
                        continue 2;

                    case 'changeProductType':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $changeResult = $neutralFormulaPolicy
                            ->withActiveAuthorityLock(static function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($request, $presetId, $neutralFormulaPolicy): array {
                                $affectedDetailIds = $neutralFormulaPolicy->presetRootDetailIds($presetId);
                                $basisDetailId = (int)($request['basisDetailId'] ?? 0);
                                if ($basisDetailId > 0) {
                                    $affectedDetailIds[] = $basisDetailId;
                                }
                                $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                    $presetId,
                                    $affectedDetailIds,
                                    $protected,
                                    'detail type'
                                );
                                if (!empty($request['deleteOthers'])) {
                                    foreach ($affectedDetailIds as $affectedDetailId) {
                                        if ((int)$affectedDetailId !== $basisDetailId) {
                                            $neutralFormulaPolicy->assertDetailDeletionCascadeAllowed(
                                                (int)$affectedDetailId,
                                                $protected,
                                                'detail type cleanup'
                                            );
                                        }
                                    }
                                }
                                $changed = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                    ->changeProductType($request);
                                return self::enrichStructuralResultPinned(
                                    $changed,
                                    $presetId,
                                    $request['offerIds'] ?? [],
                                    $pinnedIblockIds
                                );
                            });

                        $result[] = $changeResult;
                        continue 2;
                        
                    case 'addNewGroup':
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $result[] = $neutralFormulaPolicy
                            ->withActiveAuthorityLock(static function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($request, $neutralFormulaPolicy): array {
                                $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                    (int)($request['presetId'] ?? 0),
                                    is_array($request['detailIds'] ?? null) ? $request['detailIds'] : [],
                                    $protected,
                                    'detail groups'
                                );
                                return (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                    ->addGroup($request);
                            });
                        continue 2;
                        
                    case 'addNewStage':
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $result[] = $neutralFormulaPolicy
                            ->withActiveAuthorityLock(static function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($request, $neutralFormulaPolicy): array {
                                $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                    (int)($request['presetId'] ?? 0),
                                    [(int)($request['detailId'] ?? 0)],
                                    $protected,
                                    'stages'
                                );
                                return (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                    ->addStage($request);
                            });
                        continue 2;
                        
                    case 'addStage':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $addResult = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($request, $presetId, $neutralFormulaPolicy): array {
                            $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                $presetId,
                                [(int)($request['detailId'] ?? 0)],
                                $protected,
                                'stages'
                            );
                            $created = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                ->addStage($request);
                            $stageId = (int)($created['config']['id'] ?? 0);
                            if (($created['status'] ?? 'error') === 'ok' && $presetId > 0 && $stageId > 0) {
                                $stagesIblockId = (int)($pinnedIblockIds['CALC_STAGES'] ?? 0);
                                \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                                    'STAGE_OWNERSHIP_VERSION' => 4,
                                ]);
                                $neutralFormulaPolicy->assertStageLinkToPreset($presetId, $stageId, $protected);
                                \Prospektweb\Calc\Services\PresetEnrichmentService::addStageToPresetPinned(
                                    $presetId,
                                    $stageId,
                                    (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0)
                                );
                            }
                            return self::enrichStructuralResultPinned(
                                $created,
                                $presetId,
                                $request['offerIds'] ?? [],
                                $pinnedIblockIds
                            );
                        });
                        
                        $result[] = $addResult;
                        continue 2;

                    case 'duplicateStage':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $duplicateResult = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($request, $presetId, $neutralFormulaPolicy): array {
                            $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                $presetId,
                                [(int)($request['detailId'] ?? 0)],
                                $protected,
                                'stages'
                            );
                            $neutralFormulaPolicy->assertStageStructuralMutationAllowed(
                                $presetId,
                                (int)($request['stageId'] ?? 0),
                                $protected,
                                'stage duplication'
                            );
                            $duplicate = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                ->duplicateStage($request);
                            $stageId = (int)($duplicate['config']['id'] ?? 0);
                            if (($duplicate['status'] ?? 'error') === 'ok' && $presetId > 0 && $stageId > 0) {
                                $neutralFormulaPolicy->assertStageLinkToPreset(
                                    $presetId,
                                    $stageId,
                                    $protected
                                );
                                \Prospektweb\Calc\Services\PresetEnrichmentService::addStageToPresetPinned(
                                    $presetId,
                                    $stageId,
                                    (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0)
                                );
                            }
                            return self::enrichStructuralResultPinned(
                                $duplicate,
                                $presetId,
                                $request['offerIds'] ?? [],
                                $pinnedIblockIds
                            );
                        });
                        $result[] = $duplicateResult;
                        continue 2;
                        
                    case 'deleteStage':
                        // Updated handler for DELETE_STAGE_REQUEST
                        $stageId = (int)($request['stageId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        if ($stageId <= 0) {
                            throw new \RuntimeException('Stage ID is required.', 409);
                        }
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $deleteResult = $neutralFormulaPolicy
                            ->withActiveAuthorityLock(function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($stageId, $presetId, $request, $neutralFormulaPolicy): array {
                                $neutralFormulaPolicy->assertStageStructuralMutationAllowed(
                                    $presetId,
                                    $stageId,
                                    $protected,
                                    'stage deletion'
                                );
                                $stagesIblockId = (int)($pinnedIblockIds['CALC_STAGES'] ?? 0);
                                $stage = \CIBlockElement::GetList(
                                    [],
                                    ['ID' => $stageId, 'IBLOCK_ID' => $stagesIblockId],
                                    false,
                                    ['nTopCount' => 1],
                                    ['ID']
                                )->Fetch();
                                if (!$stage || !\CIBlockElement::Delete($stageId)) {
                                    throw new \RuntimeException('Failed to delete calculator stage.', 409);
                                }
                                if ($presetId > 0) {
                                    $this->markDeletedStageGlobalReferences(
                                        $presetId,
                                        $stageId,
                                        (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0)
                                    );
                                }
                                return self::enrichStructuralResultPinned(
                                    ['status' => 'ok', 'stageId' => $stageId],
                                    $presetId,
                                    $request['offerIds'] ?? [],
                                    $pinnedIblockIds
                                );
                            });
                        $result[] = $deleteResult;
                        continue 2;
                    
                    case 'removeDetail':
                        $parentId = (int)($request['parentId'] ?? 0);
                        $detailId = (int)($request['detailId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $removeResult = $neutralFormulaPolicy
                            ->withActiveAuthorityLock(static function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($parentId, $detailId, $presetId, $request, $neutralFormulaPolicy): array {
                                $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                    $presetId,
                                    [$parentId, $detailId],
                                    $protected,
                                    'detail removal'
                                );
                                foreach ([$parentId, $detailId] as $deletedDetailId) {
                                    if ($deletedDetailId > 0) {
                                        $neutralFormulaPolicy->assertDetailDeletionCascadeAllowed(
                                            $deletedDetailId,
                                            $protected,
                                            'detail removal'
                                        );
                                    }
                                }
                                $handler = new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds);
                                $removed = $parentId > 0
                                    ? $handler->removeDetailFromBinding($parentId, $detailId, $presetId)
                                    : $handler->removeTopLevelDetail($detailId, $presetId);
                                if (($removed['status'] ?? 'error') === 'ok') {
                                    $removed['rootDetailIds'] = $handler->getPresetRootDetailIds($presetId);
                                }
                                return self::enrichStructuralResultPinned(
                                    $removed,
                                    $presetId,
                                    $request['offerIds'] ?? [],
                                    $pinnedIblockIds
                                );
                            });
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
                        $settingsId = (int)($request['settingsId'] ?? 0);
                        $stageId = (int)($request['stageId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        
                        if ($stageId > 0) {
                            // A dormant calculator or assignment becomes executable at
                            // this exact link. Validate and write under the same ACTIVE
                            // authority lock so cut-over cannot race the attachment.
                            $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                            $settingsResult = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                                bool $active,
                                array $pinnedIblockIds
                                ) use (
                                    $neutralFormulaPolicy,
                                    $presetId,
                                $stageId,
                                $settingsId,
                                $request
                            ): array {
                                $neutralFormulaPolicy->assertSettingsLinkToStage(
                                    $presetId,
                                    $stageId,
                                    $settingsId,
                                    $active
                                );
                                \Prospektweb\Calc\Services\PresetEnrichmentService::updateStagePropertyPinned(
                                    $stageId,
                                    'CALC_SETTINGS',
                                    $settingsId,
                                    (int)($pinnedIblockIds['CALC_STAGES'] ?? 0)
                                );
                                return self::enrichStructuralResultPinned(
                                    ['status' => 'ok'],
                                    $presetId,
                                    $request['offerIds'] ?? [],
                                    $pinnedIblockIds
                                );
                            });
                            $result[] = $settingsResult;
                            continue 2;
                        } else {
                            $result[] = [
                                'status' => 'error',
                                'message' => 'Stage ID обязателен',
                            ];
                        }
                        continue 2;
                    
                    case 'changeOperationVariant':
                        // New handler for CHANGE_OPERATION_VARIANT_REQUEST
                        $operationVariantId = (int)($request['operationVariantId'] ?? 0);
                        $stageId = (int)($request['stageId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        if ($stageId <= 0) {
                            throw new \RuntimeException('Stage ID is required.', 409);
                        }
                        $neutralPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $result[] = $neutralPolicy->withActiveAuthorityLock(static function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($neutralPolicy, $stageId, $operationVariantId, $presetId, $request): array {
                                if ($protected && $neutralPolicy->neutralPresetContainsStage($stageId)) {
                                    throw new \RuntimeException(
                                        'Operation variant swaps are frozen for protected preset 12740.',
                                        409
                                    );
                                }
                                self::assertPinnedElementExists(
                                    $stageId,
                                    (int)($pinnedIblockIds['CALC_STAGES'] ?? 0),
                                    'calculator stage'
                                );
                                if ($operationVariantId > 0) {
                                    self::assertPinnedElementExists(
                                        $operationVariantId,
                                        (int)($pinnedIblockIds['CALC_OPERATIONS_VARIANTS'] ?? 0),
                                        'operation variant'
                                    );
                                }
                                \Prospektweb\Calc\Services\PresetEnrichmentService::updateStagePropertyPinned(
                                    $stageId,
                                    'OPERATION_VARIANT',
                                    $operationVariantId,
                                    (int)($pinnedIblockIds['CALC_STAGES'] ?? 0)
                                );
                                return self::enrichStructuralResultPinned(
                                    ['status' => 'ok'],
                                    $presetId,
                                    $request['offerIds'] ?? [],
                                    $pinnedIblockIds
                                );
                            });
                        continue 2;
                    
                    case 'changeEquipment':
                        // New handler for CHANGE_EQUIPMENT_REQUEST
                        $equipmentId = (int)($request['equipmentId'] ?? 0);
                        $stageId = (int)($request['stageId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        if ($stageId <= 0) {
                            throw new \RuntimeException('Stage ID is required.', 409);
                        }
                        $neutralPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $result[] = $neutralPolicy->withActiveAuthorityLock(static function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($neutralPolicy, $stageId, $equipmentId, $presetId, $request): array {
                                if ($protected && $neutralPolicy->neutralPresetContainsStage($stageId)) {
                                    throw new \RuntimeException(
                                        'Equipment swaps are frozen for protected preset 12740.',
                                        409
                                    );
                                }
                                self::assertPinnedElementExists(
                                    $stageId,
                                    (int)($pinnedIblockIds['CALC_STAGES'] ?? 0),
                                    'calculator stage'
                                );
                                if ($equipmentId > 0) {
                                    self::assertPinnedElementExists(
                                        $equipmentId,
                                        (int)($pinnedIblockIds['CALC_EQUIPMENT'] ?? 0),
                                        'equipment'
                                    );
                                }
                                \Prospektweb\Calc\Services\PresetEnrichmentService::updateStagePropertyPinned(
                                    $stageId,
                                    'EQUIPMENT',
                                    $equipmentId,
                                    (int)($pinnedIblockIds['CALC_STAGES'] ?? 0)
                                );
                                return self::enrichStructuralResultPinned(
                                    ['status' => 'ok'],
                                    $presetId,
                                    $request['offerIds'] ?? [],
                                    $pinnedIblockIds
                                );
                            });
                        continue 2;
                    
                    case 'changeMaterialVariant':
                        // New handler for CHANGE_MATERIAL_VARIANT_REQUEST
                        $materialVariantId = (int)($request['materialVariantId'] ?? 0);
                        $stageId = (int)($request['stageId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        if ($stageId <= 0) {
                            throw new \RuntimeException('Stage ID is required.', 409);
                        }
                        $neutralPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $result[] = $neutralPolicy->withActiveAuthorityLock(static function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($neutralPolicy, $stageId, $materialVariantId, $presetId, $request): array {
                                if ($protected && $neutralPolicy->neutralPresetContainsStage($stageId)) {
                                    throw new \RuntimeException(
                                        'Material variant swaps are frozen for protected preset 12740.',
                                        409
                                    );
                                }
                                self::assertPinnedElementExists(
                                    $stageId,
                                    (int)($pinnedIblockIds['CALC_STAGES'] ?? 0),
                                    'calculator stage'
                                );
                                if ($materialVariantId > 0) {
                                    self::assertPinnedElementExists(
                                        $materialVariantId,
                                        (int)($pinnedIblockIds['CALC_MATERIALS_VARIANTS'] ?? 0),
                                        'material variant'
                                    );
                                }
                                \Prospektweb\Calc\Services\PresetEnrichmentService::updateStagePropertyPinned(
                                    $stageId,
                                    'MATERIAL_VARIANT',
                                    $materialVariantId,
                                    (int)($pinnedIblockIds['CALC_STAGES'] ?? 0)
                                );
                                return self::enrichStructuralResultPinned(
                                    ['status' => 'ok'],
                                    $presetId,
                                    $request['offerIds'] ?? [],
                                    $pinnedIblockIds
                                );
                            });
                        continue 2;
                    
                    case 'savePresetGlobals':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $variables = is_array($request['variables'] ?? null) ? $request['variables'] : [];
                        $constants = is_array($request['constants'] ?? null) ? $request['constants'] : [];
                        if ($presetId <= 0) {
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
                            $preparedVariables = $prepareGlobals($variables);
                            $preparedConstants = $prepareGlobals($constants);
                            $allCodes = array_merge(
                                array_column($preparedVariables, 'VALUE'),
                                array_column($preparedConstants, 'VALUE')
                            );
                            if (count($allCodes) !== count(array_unique($allCodes))) {
                                throw new \InvalidArgumentException('Коды переменных и констант не должны повторяться');
                            }

                            $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                            $globalsResult = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                                bool $active,
                                array $pinnedIblockIds
                            ) use (
                                $neutralFormulaPolicy,
                                $presetId,
                                $preparedVariables,
                                $preparedConstants,
                                $request
                            ): array {
                                $presetsIblockId = (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0);
                                foreach (['GLOBAL_VARIABLES', 'GLOBAL_CONSTANTS'] as $propertyCode) {
                                    $existingProperty = \CIBlockProperty::GetList([], [
                                        'IBLOCK_ID' => $presetsIblockId,
                                        'CODE' => $propertyCode,
                                    ])->Fetch();
                                    if (!$existingProperty) {
                                        throw new \RuntimeException(
                                            "Protected preset property {$propertyCode} must be provisioned before authoring.",
                                            409
                                        );
                                    }
                                }
                                $neutralFormulaPolicy->assertPresetGlobalsWrite(
                                    $presetId,
                                    $preparedVariables,
                                    $preparedConstants,
                                    $active
                                );
                                \CIBlockElement::SetPropertyValuesEx($presetId, $presetsIblockId, [
                                    'GLOBAL_VARIABLES' => $preparedVariables ?: false,
                                    'GLOBAL_CONSTANTS' => $preparedConstants ?: false,
                                ]);
                                return self::enrichStructuralResultPinned(
                                    ['status' => 'ok'],
                                    $presetId,
                                    $request['offerIds'] ?? [],
                                    $pinnedIblockIds
                                );
                            });
                            $result[] = $globalsResult;
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
                            
                            $changeResponse = [
                                'status' => 'ok',
                                'stageId' => $stageId,
                            ];
                            $offerIds = $this->normalizeIds($request['offerIds'] ?? []);
                            if (!empty($offerIds)) {
                                $changeResponse['initPayload'] = (new InitPayloadService())->prepareInitPayload($offerIds, SITE_ID, false);
                            }
                            $result[] = $changeResponse;
                        } else {
                            $result[] = [
                                'status' => 'error',
                                'message' => 'Stage ID и массив customFieldsValue обязательны',
                            ];
                        }
                        continue 2;
                        
                    case 'selectFields':
                        $stageId = (int)($request['stageId'] ?? 0);
                        $customFieldIds = $this->normalizeIds($request['customFieldIds'] ?? []);
                        $submittedValues = is_array($request['customFieldsValue'] ?? null)
                            ? $request['customFieldsValue']
                            : [];
                        $replaceCustomFields = !empty($request['replace']);

                        foreach ($submittedValues as $field) {
                            if (strpos((string)($field['VALUE'] ?? ''), '|') !== false) {
                                $result[] = ['status' => 'error', 'message' => 'Значение дополнительного параметра не может содержать символ |'];
                                continue 3;
                            }
                        }

                        if ($stageId > 0 && ($replaceCustomFields || !empty($customFieldIds))) {
                            $stagesIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_STAGES', 0);

                            $existingCustomFields = [];
                            $stageCustomFieldProps = \CIBlockElement::GetProperty($stagesIblockId, $stageId, ['sort' => 'asc'], ['CODE' => 'CUSTOM_FIELDS']);
                            while ($prop = $stageCustomFieldProps->Fetch()) {
                                if (!empty($prop['VALUE'])) {
                                    $existingCustomFields[] = (int)$prop['VALUE'];
                                }
                            }

                            $mergedCustomFields = $replaceCustomFields
                                ? $customFieldIds
                                : array_values(array_unique(array_merge($existingCustomFields, $customFieldIds)));
                            \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                                'CUSTOM_FIELDS' => $mergedCustomFields,
                                'STAGE_OWNERSHIP_VERSION' => 4,
                            ]);

                            $customFieldsService = new \Prospektweb\Calc\Services\CustomFieldsService();
                            $fieldsConfig = $customFieldsService->getFieldsConfig($mergedCustomFields);

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
                            if ($replaceCustomFields) {
                                $selectedCodes = array_fill_keys(array_map(
                                    static fn(array $fieldConfig): string => (string)($fieldConfig['code'] ?? ''),
                                    $fieldsConfig
                                ), true);
                                $existingValuesMap = array_filter(
                                    $existingValuesMap,
                                    static fn(array $value): bool => isset($selectedCodes[(string)($value['VALUE'] ?? '')])
                                );
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

                            $selectedCodes = array_fill_keys(array_filter(array_map(
                                static fn(array $fieldConfig): string => (string)($fieldConfig['code'] ?? ''),
                                $fieldsConfig
                            )), true);
                            foreach ($submittedValues as $field) {
                                $fieldCode = trim((string)($field['CODE'] ?? ''));
                                if ($fieldCode === '' || !isset($selectedCodes[$fieldCode])) {
                                    continue;
                                }
                                $fieldValue = (string)($field['VALUE'] ?? '');
                                $fieldVisible = !array_key_exists('VISIBLE', $field)
                                    || filter_var($field['VISIBLE'], FILTER_VALIDATE_BOOLEAN);
                                $existingValuesMap[$fieldCode] = [
                                    'VALUE' => $fieldCode,
                                    'DESCRIPTION' => $fieldValue . '|' . ($fieldVisible ? 'Y' : 'N'),
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
                        $stageId = (int)($request['stageId'] ?? 0);
                        $field = is_array($request['field'] ?? null) ? $request['field'] : [];
                        $name = trim((string)($field['name'] ?? ''));
                        $type = trim((string)($field['type'] ?? 'text'));
                        $allowedTypes = ['number', 'text', 'checkbox', 'select'];
                        if ($stageId <= 0 || $name === '' || !in_array($type, $allowedTypes, true)) {
                            $result[] = ['status' => 'error', 'message' => 'Укажите название и корректный тип дополнительного параметра'];
                            continue 2;
                        }

                        $customFieldsIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_CUSTOM_FIELDS', 0);
                        $stagesIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_STAGES', 0);
                        if ($customFieldsIblockId <= 0 || $stagesIblockId <= 0) {
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
                            $enumCursor = \CIBlockPropertyEnum::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['PROPERTY_ID' => (int)$property['ID']]);
                            while ($enum = $enumCursor->Fetch()) {
                                if ((string)($enum['XML_ID'] ?? '') === $xmlId) {
                                    return (int)$enum['ID'];
                                }
                            }
                            return 0;
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
                        $stageCustomFieldProps = \CIBlockElement::GetProperty($stagesIblockId, $stageId, ['sort' => 'asc'], ['CODE' => 'CUSTOM_FIELDS']);
                        while ($prop = $stageCustomFieldProps->Fetch()) {
                            if (!empty($prop['VALUE'])) {
                                $existingCustomFields[] = (int)$prop['VALUE'];
                            }
                        }
                        $existingCustomFields[] = $fieldId;
                        \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                            'CUSTOM_FIELDS' => array_values(array_unique($existingCustomFields)),
                            'STAGE_OWNERSHIP_VERSION' => 4,
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
                            self::assertPinnedElementExists(
                                $equipmentId,
                                $equipmentIblockId,
                                'equipment'
                            );
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

                    case 'savePriceSettingsPreset':
                        $priceSettingsService = new \Prospektweb\Calc\Services\PriceSettingsPresetService();
                        $result[] = $priceSettingsService->save(
                            (string)($request['name'] ?? ''),
                            (string)($request['mode'] ?? 'markup'),
                            is_array($request['prices'] ?? null) ? $request['prices'] : []
                        );
                        continue 2;

                    case 'renamePriceSettingsPreset':
                        $priceSettingsService = new \Prospektweb\Calc\Services\PriceSettingsPresetService();
                        $result[] = $priceSettingsService->rename(
                            (string)($request['id'] ?? ''),
                            (string)($request['name'] ?? '')
                        );
                        continue 2;

                    case 'deletePriceSettingsPreset':
                        $priceSettingsService = new \Prospektweb\Calc\Services\PriceSettingsPresetService();
                        $result[] = $priceSettingsService->delete((string)($request['id'] ?? ''));
                        continue 2;


                    case 'deleteDetail':
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $result[] = $neutralFormulaPolicy
                            ->withActiveAuthorityLock(static function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($request, $neutralFormulaPolicy): array {
                                $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                    (int)($request['presetId'] ?? 0),
                                    [(int)($request['detailId'] ?? 0)],
                                    $protected,
                                    'detail deletion'
                                );
                                $neutralFormulaPolicy->assertDetailDeletionCascadeAllowed(
                                    (int)($request['detailId'] ?? 0),
                                    $protected,
                                    'detail deletion'
                                );
                                $deleted = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                    ->deleteDetail($request);
                                if (($deleted['status'] ?? 'error') !== 'ok') {
                                    throw new \RuntimeException(
                                        trim((string)($deleted['message'] ?? 'Detail deletion failed.')),
                                        409
                                    );
                                }
                                return $deleted;
                            });
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
                        
                    case 'addDetailToBinding':
                        // New handler for ADD_DETAIL_TO_BINDING_REQUEST
                        $parentId = (int)($request['parentId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        $name = trim((string)($request['name'] ?? ''));
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $addResult = $neutralFormulaPolicy
                            ->withActiveAuthorityLock(static function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($parentId, $presetId, $name, $request, $neutralFormulaPolicy): array {
                                $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                    $presetId,
                                    [$parentId],
                                    $protected,
                                    'binding details'
                                );
                                $created = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                    ->addDetailToBinding($parentId, $name);
                                return self::enrichStructuralResultPinned(
                                    $created,
                                    $presetId,
                                    $request['offerIds'] ?? [],
                                    $pinnedIblockIds
                                );
                            });
                        
                        $result[] = $addResult;
                        continue 2;
                    
                    case 'changeDetailSort':
                        // New handler for CHANGE_DETAIL_SORT_REQUEST
                        $parentId = (int)($request['parentId'] ?? 0);
                        $sorting = $request['sorting'] ?? [];
                        $presetId = (int)($request['presetId'] ?? 0);
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $sortResult = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $neutralFormulaPolicy,
                            $presetId,
                            $parentId,
                            $sorting,
                            $request
                        ): array {
                            $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                $presetId,
                                array_merge(
                                    [$parentId],
                                    is_array($sorting) ? $sorting : []
                                ),
                                $protected,
                                'detail sorting'
                            );
                            $changed = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                ->changeDetailSort(
                                    $parentId,
                                    is_array($sorting) ? $sorting : []
                                );
                            return self::enrichStructuralResultPinned(
                                $changed,
                                $presetId,
                                $request['offerIds'] ?? [],
                                $pinnedIblockIds
                            );
                        });
                        
                        $result[] = $sortResult;
                        continue 2;
                    
                    case 'changeDetailLevel':
                        // New handler for CHANGE_DETAIL_LEVEL_REQUEST
                        $fromParentId = (int)($request['fromParentId'] ?? 0);
                        $detailId = (int)($request['detailId'] ?? 0);
                        $toParentId = (int)($request['toParentId'] ?? 0);
                        $sorting = $request['sorting'] ?? [];
                        $presetId = (int)($request['presetId'] ?? 0);
                        
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $levelResult = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $neutralFormulaPolicy,
                            $presetId,
                            $fromParentId,
                            $detailId,
                            $toParentId,
                            $sorting,
                            $request
                        ): array {
                            $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                $presetId,
                                [$fromParentId, $detailId, $toParentId],
                                $protected,
                                'detail levels'
                            );
                            $changed = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                ->changeDetailLevel(
                                    $fromParentId,
                                    $detailId,
                                    $toParentId,
                                    is_array($sorting) ? $sorting : []
                                );
                            return self::enrichStructuralResultPinned(
                                $changed,
                                $presetId,
                                $request['offerIds'] ?? [],
                                $pinnedIblockIds
                            );
                        });
                        
                        $result[] = $levelResult;
                        continue 2;
                    
                    case 'changeSortStage':
                        $detailId = (int)($request['detailId'] ?? 0);
                        $sorting = is_array($request['sorting'] ?? null) ? $request['sorting'] : [];
                        $presetId = (int)($request['presetId'] ?? 0);
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $stageResult = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $neutralFormulaPolicy,
                            $detailId,
                            $sorting,
                            $presetId,
                            $request
                        ): array {
                            $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                $presetId,
                                [$detailId],
                                $protected,
                                'stage order'
                            );
                            $changed = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                ->changeSortStage($detailId, $sorting, false);
                            return self::enrichStructuralResultPinned(
                                $changed,
                                $presetId,
                                $request['offerIds'] ?? [],
                                $pinnedIblockIds
                            );
                        });
                        $result[] = $stageResult;
                        continue 2;

                    case 'changeRootDetailSort':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $sorting = array_values(array_filter(array_map('intval', is_array($request['sorting'] ?? null) ? $request['sorting'] : [])));
                        if ($presetId <= 0 || !$sorting) {
                            $result[] = ['status' => 'error', 'message' => 'Некорректные параметры сортировки колонок'];
                            continue 2;
                        }
                        if (count($sorting) !== count(array_unique($sorting))) {
                            $result[] = ['status' => 'error', 'message' => 'Порядок колонок содержит повторяющиеся детали'];
                            continue 2;
                        }

                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $sortResult = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $neutralFormulaPolicy,
                            $presetId,
                            $sorting,
                            $request
                        ): array {
                            $presetsIblockId = (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0);
                            if ($presetsIblockId <= 0) {
                                throw new \RuntimeException('Pinned preset authority is invalid.', 409);
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
                            $lockedPreset = $connection->query(
                                'SELECT ID FROM b_iblock_element WHERE ID = ' . $presetId
                                    . ' AND IBLOCK_ID = ' . $presetsIblockId . ' FOR UPDATE'
                            )->fetch();
                            if (!is_array($lockedPreset)) {
                                throw new \RuntimeException(
                                    'Preset must belong to the exact pinned CALC_PRESETS iblock.',
                                    409
                                );
                            }
                            $current = $readRootIds();
                            $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                $presetId,
                                array_merge($current, $sorting),
                                $protected,
                                'root-detail order'
                            );
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
                            return self::enrichStructuralResultPinned(
                                [
                                    'status' => 'ok',
                                    'presetId' => $presetId,
                                    'sorting' => $sorting,
                                    'rootDetailIds' => $sorting,
                                ],
                                $presetId,
                                $request['offerIds'] ?? [],
                                $pinnedIblockIds
                            );
                        });
                        $result[] = $sortResult;
                        continue 2;

                    case 'moveStage':
                        $stageId = (int)($request['stageId'] ?? 0);
                        $sourceDetailId = (int)($request['sourceDetailId'] ?? 0);
                        $targetDetailId = (int)($request['targetDetailId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        $sourceSorting = is_array($request['sourceSorting'] ?? null)
                            ? $request['sourceSorting']
                            : [];
                        $targetSorting = is_array($request['targetSorting'] ?? null)
                            ? $request['targetSorting']
                            : [];
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $stageResult = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected,
                            array $lockedIblockIds
                        ) use (
                            $neutralFormulaPolicy,
                            $presetId,
                            $stageId,
                            $sourceDetailId,
                            $targetDetailId,
                            $sourceSorting,
                            $targetSorting,
                            $request
                        ): array {
                            $neutralFormulaPolicy->assertStageMoveAllowed(
                                $presetId,
                                $stageId,
                                $sourceDetailId,
                                $targetDetailId,
                                $protected
                            );
                            $moved = (new \Prospektweb\Calc\Services\DetailHandler($lockedIblockIds))->moveStage(
                                $stageId,
                                $sourceDetailId,
                                $targetDetailId,
                                $sourceSorting,
                                $targetSorting,
                                false
                            );
                            return self::enrichStructuralResultPinned(
                                $moved,
                                $presetId,
                                $request['offerIds'] ?? [],
                                $lockedIblockIds
                            );
                        });
                        $result[] = $stageResult;
                        continue 2;
                    
                    case 'addDetailsToBinding':
                        // New handler for adding selected details to binding
                        $parentId = (int)($request['parentId'] ?? 0);
                        $detailIds = $request['detailIds'] ?? [];
                        $presetId = (int)($request['presetId'] ?? 0);
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $addDetailsResult = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $neutralFormulaPolicy,
                            $presetId,
                            $parentId,
                            $detailIds,
                            $request
                        ): array {
                            $neutralFormulaPolicy->assertStructuralMutationAllowed(
                                $presetId,
                                array_merge(
                                    [$parentId],
                                    is_array($detailIds) ? $detailIds : []
                                ),
                                $protected,
                                'binding details'
                            );
                            $attached = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                ->addDetailsToBinding(
                                    $parentId,
                                    is_array($detailIds) ? $detailIds : []
                                );
                            return self::enrichStructuralResultPinned(
                                $attached,
                                $presetId,
                                $request['offerIds'] ?? [],
                                $pinnedIblockIds
                            );
                        });
                        
                        $result[] = $addDetailsResult;
                        continue 2;
                    

                    case 'changePricePreset':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $prices = $request['prices'] ?? [];
                        $priceProfilePolicy = is_array($request['priceProfilePolicy'] ?? null)
                            ? $request['priceProfilePolicy']
                            : null;
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $pricesResult = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected,
                            array $pinnedIblockIds,
                            array $authority
                        ) use (
                            $neutralFormulaPolicy,
                            $presetId,
                            $prices,
                            $priceProfilePolicy,
                            $request
                        ): array {
                            if ($protected
                                && $presetId === \Prospektweb\Calc\Services\NeutralFormulaPolicy::PRESET_ID
                                && $priceProfilePolicy !== null) {
                                $operands = [];
                                foreach ((array)($priceProfilePolicy['rules'] ?? []) as $rule) {
                                    $condition = is_array($rule) && is_array($rule['condition'] ?? null)
                                        ? $rule['condition']
                                        : [];
                                    $operands[] = [
                                        'kind' => $condition['kind'] ?? null,
                                        'code' => $condition['code'] ?? null,
                                    ];
                                }
                                $neutralFormulaPolicy->assertNeutralGlobalOperands(
                                    $operands,
                                    (int)($authority['globalIblockId'] ?? 0),
                                    'conditional price policy'
                                );
                            }
                            $changed = (new \Prospektweb\Calc\Services\PresetPriceService($pinnedIblockIds))
                                ->changePricePreset(
                                    $presetId,
                                    is_array($prices) ? $prices : [],
                                    $priceProfilePolicy
                                );
                            return self::enrichStructuralResultPinned(
                                $changed,
                                $presetId,
                                $request['offerIds'] ?? [],
                                $pinnedIblockIds
                            );
                        });
                        $result[] = $pricesResult;
                        continue 2;

                    case 'updateOffersFromCalculation':
                        throw new \RuntimeException('USE_CATALOG_WRITE_PREVIEW_APPLY', 409);
                        
                    case 'updateStageProperty':
                        // Handler for CHANGE_OPTIONS_OPERATION and CHANGE_OPTIONS_MATERIAL
                        $stageId = (int)($request['stageId'] ?? 0);
                        $propertyCode = is_string($request['propertyCode'] ?? null)
                            ? $request['propertyCode']
                            : '';
                        $value = $request['value'] ?? '';
                        
                        $allowedStageProperties = [
                            'OPTIONS_OPERATION',
                            'OPTIONS_MATERIAL',
                            'OPTIONS_EQUIPMENT',
                            'ACTIVATION_CONDITION',
                            'INPUTS',
                            'OUTPUTS',
                            'SCHEME_PARAMETR_VALUES',
                            'GLOBAL_ASSIGNMENTS',
                        ];
                        if ($stageId <= 0 || !in_array($propertyCode, $allowedStageProperties, true)) {
                            throw new \RuntimeException(
                                'Unsupported stage property write: ' . ($propertyCode !== '' ? $propertyCode : '<invalid>'),
                                409
                            );
                        }
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected,
                            array $pinnedIblockIds,
                            array $authority
                        ) use (
                            $neutralFormulaPolicy,
                            $stageId,
                            $propertyCode,
                            $value
                        ): void {
                            $stagesIblockId = (int)($pinnedIblockIds['CALC_STAGES'] ?? 0);
                            $existingProperty = \CIBlockProperty::GetList([], [
                                'IBLOCK_ID' => $stagesIblockId,
                                '=CODE' => $propertyCode,
                            ])->Fetch();
                            if (!$existingProperty) {
                                throw new \RuntimeException(
                                    'Stage property ' . $propertyCode . ' must be provisioned before authoring.',
                                    409
                                );
                            }
                            if ($propertyCode === 'GLOBAL_ASSIGNMENTS') {
                                $neutralFormulaPolicy->assertStageAssignmentsWrite($stageId, $value, $protected);
                            } elseif ($propertyCode === 'INPUTS') {
                                $neutralFormulaPolicy->assertStageInputsWrite($stageId, $value, $protected);
                            } elseif ($propertyCode === 'ACTIVATION_CONDITION') {
                                $neutralFormulaPolicy->assertStageActivationConditionWrite(
                                    $stageId,
                                    $value,
                                    $protected,
                                    (int)($authority['globalIblockId'] ?? 0)
                                );
                            }
                            \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                                $propertyCode => $value,
                            ]);
                        });
                        $result[] = ['status' => 'ok'];
                        continue 2;

                    case 'inspectCalculatorContract':
                        $handler = new \Prospektweb\Calc\Services\CalculatorContractService();
                        $result[] = $handler->inspect((int)($request['settingsId'] ?? 0));
                        continue 2;

                    case 'resolveCalculatorContract':
                        $settingsId = (int)($request['settingsId'] ?? 0);
                        $stageId = (int)($request['stageId'] ?? 0);
                        $currentPresetId = (int)($request['currentPresetId'] ?? 0);
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $response = $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $neutralFormulaPolicy,
                            $settingsId,
                            $stageId,
                            $currentPresetId,
                            $request
                        ): array {
                            if ($protected
                                && ($currentPresetId === \Prospektweb\Calc\Services\NeutralFormulaPolicy::PRESET_ID
                                    || $neutralFormulaPolicy->neutralPresetContainsStage($stageId))) {
                                throw new \RuntimeException(
                                    'Calculator contract resolution is frozen for protected preset 12740.',
                                    409
                                );
                            }
                            return (new \Prospektweb\Calc\Services\CalculatorContractService($pinnedIblockIds))
                                ->resolve(
                                    $settingsId,
                                    $stageId,
                                    $currentPresetId,
                                    (string)($request['mode'] ?? ''),
                                    (string)($request['message'] ?? '')
                                );
                        });
                        $offerIds = $this->normalizeIds($request['offerIds'] ?? []);
                        if (($response['status'] ?? null) === 'ok' && !empty($offerIds)) {
                            $response['initPayload'] = (new InitPayloadService())->prepareInitPayload($offerIds, SITE_ID, false);
                        }
                        $result[] = $response;
                        continue 2;

                    case 'saveStageUsedEntities':
                        $stageId = (int)($request['stageId'] ?? 0);
                        $requestedXmlIds = array_values(array_intersect(
                            array_map('strval', is_array($request['usedEntities'] ?? null) ? $request['usedEntities'] : []),
                            ['VARIANT_OPERATION', 'EQUIPMENT', 'VARIANT_MATERIAL']
                        ));
                        $stagesIblockId = (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_STAGES', 0);
                        if ($stageId <= 0 || $stagesIblockId <= 0) {
                            $result[] = ['status' => 'error', 'message' => 'Этап или инфоблок этапов не найден'];
                            continue 2;
                        }
                        $property = \CIBlockProperty::GetList([], [
                            'IBLOCK_ID' => $stagesIblockId,
                            '=CODE' => 'USED_ENTITY_CODES',
                        ])->Fetch();
                        if (!$property) {
                            $result[] = ['status' => 'error', 'message' => 'Свойство USED_ENTITY_CODES этапа не установлено'];
                            continue 2;
                        }
                        \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                            'USED_ENTITY_CODES' => $requestedXmlIds ?: false,
                            'STAGE_OWNERSHIP_VERSION' => 5,
                        ]);
                        $response = ['status' => 'ok', 'stageId' => $stageId];
                        $offerIds = $this->normalizeIds($request['offerIds'] ?? []);
                        if (!empty($offerIds)) {
                            $response['initPayload'] = (new InitPayloadService())->prepareInitPayload($offerIds, SITE_ID, false);
                        }
                        $result[] = $response;
                        continue 2;
                        
                    case 'updateSettingsProperty':
                        // Handler for CHANGE_LOGIC
                        $settingsId = (int)($request['settingsId'] ?? 0);
                        $propertyCode = is_string($request['propertyCode'] ?? null)
                            ? $request['propertyCode']
                            : '';
                        $value = $request['value'] ?? '';
                        $allowedSettingsProperties = ['LOGIC_JSON', 'PARAMS', 'GLOBAL_DEPENDENCIES'];
                        if ($settingsId <= 0 || !in_array($propertyCode, $allowedSettingsProperties, true)) {
                            throw new \RuntimeException(
                                'Unsupported calculator property write: '
                                    . ($propertyCode !== '' ? $propertyCode : '<invalid>'),
                                409
                            );
                        }
                        $neutralFormulaPolicy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
                        $neutralFormulaPolicy->withActiveAuthorityLock(static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $neutralFormulaPolicy,
                            $settingsId,
                            $propertyCode,
                            $value
                        ): void {
                            $settingsIblockId = (int)($pinnedIblockIds['CALC_SETTINGS'] ?? 0);
                            $existingProperty = \CIBlockProperty::GetList([], [
                                'IBLOCK_ID' => $settingsIblockId,
                                '=CODE' => $propertyCode,
                            ])->Fetch();
                            if (!$existingProperty) {
                                throw new \RuntimeException(
                                    'Calculator property ' . $propertyCode . ' must be provisioned before authoring.',
                                    409
                                );
                            }
                            if ($propertyCode === 'LOGIC_JSON') {
                                $neutralFormulaPolicy->assertSettingsLogicWrite($settingsId, $value, $protected);
                            }
                            \CIBlockElement::SetPropertyValuesEx($settingsId, $settingsIblockId, [
                                $propertyCode => $value,
                            ]);
                        });
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

    /**
     * Complete a structural mutation and its derived preset rebuild while the
     * same neutral option authority transaction is still held.
     *
     * @param array<string,mixed> $operationResult
     * @param mixed $offerIds
     * @param array<string,int> $pinnedIblockIds
     * @return array<string,mixed>
     */
    private static function enrichStructuralResultPinned(
        array $operationResult,
        int $presetId,
        $offerIds,
        array $pinnedIblockIds
    ): array {
        if (($operationResult['status'] ?? 'error') !== 'ok') {
            throw new \RuntimeException(
                trim((string)($operationResult['message'] ?? 'Structural preset mutation failed.')),
                409
            );
        }
        if ($presetId <= 0) {
            return $operationResult;
        }
        $enrichment = new \Prospektweb\Calc\Services\PresetEnrichmentService($pinnedIblockIds);
        $rootDetailIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($operationResult['rootDetailIds'] ?? null)
                ? $operationResult['rootDetailIds']
                : $enrichment->getProductRootsFromPreset($presetId)
        ))));
        if ($rootDetailIds === []) {
            return $operationResult;
        }
        $operationResult['initPayload'] = $enrichment->enrichPresetFromProductRoots(
            $presetId,
            $rootDetailIds,
            is_array($offerIds) ? $offerIds : []
        );
        return $operationResult;
    }

    private static function assertPinnedElementExists(int $elementId, int $iblockId, string $surface): void
    {
        if ($elementId <= 0 || $iblockId <= 0) {
            throw new \RuntimeException('Pinned ' . $surface . ' authority is invalid.', 409);
        }
        $row = \CIBlockElement::GetList(
            [],
            ['ID' => $elementId, 'IBLOCK_ID' => $iblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID']
        )->Fetch();
        if (!is_array($row)) {
            throw new \RuntimeException(
                ucfirst($surface) . ' must belong to its exact pinned iblock.',
                409
            );
        }
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
        $equipmentIblockId = isset($this->pinnedRuntimeIblockIds['CALC_EQUIPMENT'])
            ? (int)$this->pinnedRuntimeIblockIds['CALC_EQUIPMENT']
            : (int)\Bitrix\Main\Config\Option::get(
                'prospektweb.calc',
                'IBLOCK_CALC_EQUIPMENT',
                0
            );

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
            $vatInfo = $this->getVatInfo((int)($productData['VAT_ID'] ?? 0));
            $extendedPriceMode = $this->hasExtendedPriceMode($prices);
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
                    'vat' => $vatInfo,
                    'extendedPriceMode' => $extendedPriceMode,
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

    private function markDeletedStageGlobalReferences(
        int $presetId,
        int $stageId,
        ?int $pinnedPresetsIblockId = null
    ): void
    {
        $presetsIblockId = $pinnedPresetsIblockId !== null
            ? $pinnedPresetsIblockId
            : (int)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'IBLOCK_CALC_PRESETS', 0);
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

    private function getVatInfo(int $vatId): ?array
    {
        if ($vatId <= 0 || !class_exists('\CCatalogVat')) {
            return null;
        }

        $iterator = \CCatalogVat::GetByID($vatId);
        if (!is_object($iterator) || !($vat = $iterator->Fetch())) {
            return null;
        }

        return [
            'id' => (int)($vat['ID'] ?? $vatId),
            'name' => (string)($vat['NAME'] ?? ''),
            'rate' => isset($vat['RATE']) ? (float)$vat['RATE'] : null,
        ];
    }

    private function hasExtendedPriceMode(array $prices): bool
    {
        foreach ($prices as $price) {
            if (($price['quantityFrom'] ?? null) !== null || ($price['quantityTo'] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }
}
