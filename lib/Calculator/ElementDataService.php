<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Loader;

class ElementDataService
{
    /** @var array<string,int> */
    private array $pinnedRuntimeIblockIds;

    private ?\Prospektweb\Calc\Services\CalculatorMutationAuthorityService $mutationAuthority;

    private bool $deferInitPayloadToSemanticReadback;

    /** @param array<string,int> $pinnedRuntimeIblockIds */
    public function __construct(
        array $pinnedRuntimeIblockIds = [],
        ?\Prospektweb\Calc\Services\CalculatorMutationAuthorityService $mutationAuthority = null,
        bool $deferInitPayloadToSemanticReadback = false
    ) {
        $this->pinnedRuntimeIblockIds = $pinnedRuntimeIblockIds;
        $this->mutationAuthority = $mutationAuthority;
        $this->deferInitPayloadToSemanticReadback = $deferInitPayloadToSemanticReadback;
        $this->ensureBitrixModulesLoaded();
    }

    private function mutationAuthority(): \Prospektweb\Calc\Services\CalculatorMutationAuthorityService
    {
        return $this->mutationAuthority
            ?? new \Prospektweb\Calc\Services\CalculatorMutationAuthorityService();
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

                    case 'loadAiLogicPilotDraft':
                        $result[] = (new \Prospektweb\Calc\Services\AiLogicPilotDraftStore())->load($request);
                        continue 2;

                    case 'saveAiLogicPilotDraft':
                        $result[] = (new \Prospektweb\Calc\Services\AiLogicPilotDraftStore())->save($request);
                        continue 2;

                    case 'loadAiLogicPilotReplacementCandidates':
                        $result[] = (new \Prospektweb\Calc\Services\AiLogicPilotMaterializationService())->replacementCandidates($request);
                        continue 2;

                    case 'previewAiLogicPilotManifest':
                        $result[] = (new \Prospektweb\Calc\Services\AiLogicPilotMaterializationService())->preview($request);
                        continue 2;

                    case 'applyAiLogicPilotManifest':
                        $result[] = (new \Prospektweb\Calc\Services\AiLogicPilotMaterializationService())->apply($request);
                        continue 2;

                    case 'inspectAiLogicPilotApplication':
                        $result[] = (new \Prospektweb\Calc\Services\AiLogicPilotRepairService())->inspect($request);
                        continue 2;

                    case 'repairAiLogicPilotApplication':
                        $result[] = (new \Prospektweb\Calc\Services\AiLogicPilotRepairService())->repair($request);
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
                        $mutationAuthority = $this->mutationAuthority();
                        $result[] = $mutationAuthority->withAuthorityLock($presetId, static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($request, $presetId): array {
                            return (new \Prospektweb\Calc\Services\StageGroupService($pinnedIblockIds))
                                ->save($request, false);
                        });
                        continue 2;

                    case 'enrichPreset':
                        $result[] = $this->enrichPreset($request);
                        continue 2;

                    case 'clearPreset':
                        $result[] = $this->clearPreset($request);
                        continue 2;

                    case 'clonePreset':
                        global $USER;
                        if (!$USER || !$USER->IsAdmin()) {
                            throw new \RuntimeException('Недостаточно прав для клонирования пресета');
                        }
                        $presetId = (int)($request['presetId'] ?? 0);
                        if ($presetId <= 0) {
                            throw new \InvalidArgumentException('Для клонирования нужен пресет');
                        }
                        $lifecycleReceipt = (new \Prospektweb\Calc\Services\PresetLifecycleMutationService())
                            ->duplicatePreset($presetId);
                        $newPresetId = (int)($lifecycleReceipt['newPresetId'] ?? 0);
                        $siteId = (string)($request['siteId'] ?? (defined('SITE_ID') ? SITE_ID : 's1'));
                        $initPayload = (new InitPayloadService())->preparePresetPayload($newPresetId, $siteId);
                        if ((int)($initPayload['preset']['id'] ?? 0) !== $newPresetId) {
                            throw new \RuntimeException('После клонирования редактор не получил новый пресет');
                        }
                        $result[] = [
                            'status' => 'ok',
                            'sourcePresetId' => $presetId,
                            'newPresetId' => $newPresetId,
                            'sourceRevision' => (string)($lifecycleReceipt['sourceRevision'] ?? ''),
                            'cloneRevision' => (string)($lifecycleReceipt['cloneRevision'] ?? ''),
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
                        $result[] = (new \Prospektweb\Calc\Services\CatalogMetaService(
                            $this->pinnedRuntimeIblockIds
                        ))->get($request);
                        continue 2;

                    case 'saveCatalogEntityMeta':
                        $result[] = (new \Prospektweb\Calc\Services\CatalogMetaService(
                            $this->pinnedRuntimeIblockIds
                        ))->save($request);
                        continue 2;

                    case 'moveCatalogEntitySection':
                        $result[] = (new \Prospektweb\Calc\Services\CatalogMetaService(
                            $this->pinnedRuntimeIblockIds
                        ))->moveToSection($request);
                        continue 2;

                    case 'createCatalogSection':
                        $result[] = (new \Prospektweb\Calc\Services\CatalogMetaService(
                            $this->pinnedRuntimeIblockIds
                        ))->createSection($request);
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

                    case 'addNewDetail':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $mutationAuthority = $this->mutationAuthority();
                        $addResult = $mutationAuthority
                            ->withAuthorityLock($presetId, function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($request, $presetId, $mutationAuthority): array {
                                $mutationAuthority->assertStructuralMutationAllowed(
                                    $presetId,
                                    [],
                                    $protected,
                                    'details'
                                );
                                $created = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                    ->addDetail($request);
                                return $this->completeStructuralMutationPinned(
                                    $created,
                                    $presetId,
                                    $pinnedIblockIds
                                );
                            });
                        $result[] = $addResult;
                        continue 2;
                        
                    case 'cloneDetail':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $mutationAuthority = $this->mutationAuthority();
                        $cloneResult = $mutationAuthority->withAuthorityLock($presetId, function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($request, $presetId, $mutationAuthority): array {
                            $mutationAuthority->assertStructuralMutationAllowed(
                                $presetId,
                                [(int)($request['detailId'] ?? 0)],
                                $protected,
                                'details'
                            );
                            $clone = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                ->cloneDetail($request);
                            return $this->completeStructuralMutationPinned(
                                $clone,
                                $presetId,
                                $pinnedIblockIds
                            );
                        });
                        $result[] = $cloneResult;
                        continue 2;

                    case 'cloneDetails':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $mutationAuthority = $this->mutationAuthority();
                        $cloneResult = $mutationAuthority->withAuthorityLock($presetId, function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($request, $presetId, $mutationAuthority): array {
                            $mutationAuthority->assertStructuralMutationAllowed(
                                $presetId,
                                is_array($request['detailIds'] ?? null) ? $request['detailIds'] : [],
                                $protected,
                                'details'
                            );
                            $clone = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                ->cloneDetails($request);
                            return $this->completeStructuralMutationPinned(
                                $clone,
                                $presetId,
                                $pinnedIblockIds
                            );
                        });
                        $result[] = $cloneResult;
                        continue 2;

                    case 'changeProductType':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $mutationAuthority = $this->mutationAuthority();
                        $changeResult = $mutationAuthority
                            ->withAuthorityLock($presetId, function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($request, $presetId, $mutationAuthority): array {
                                $affectedDetailIds = $mutationAuthority->presetRootDetailIds($presetId);
                                $basisDetailId = (int)($request['basisDetailId'] ?? 0);
                                if ($basisDetailId > 0) {
                                    $affectedDetailIds[] = $basisDetailId;
                                }
                                $mutationAuthority->assertStructuralMutationAllowed(
                                    $presetId,
                                    $affectedDetailIds,
                                    $protected,
                                    'detail type'
                                );
                                if (!empty($request['deleteOthers'])) {
                                    foreach ($affectedDetailIds as $affectedDetailId) {
                                        if ((int)$affectedDetailId !== $basisDetailId) {
                                            $mutationAuthority->assertDetailDeletionCascadeAllowed(
                                                $presetId,
                                                (int)$affectedDetailId,
                                                $protected,
                                                'detail type cleanup'
                                            );
                                        }
                                    }
                                }
                                $changed = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                    ->changeProductType($request);
                                return $this->completeStructuralMutationPinned(
                                    $changed,
                                    $presetId,
                                    $pinnedIblockIds
                                );
                            });

                        $result[] = $changeResult;
                        continue 2;
                        
                    case 'addNewGroup':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $mutationAuthority = $this->mutationAuthority();
                        $result[] = $mutationAuthority
                            ->withAuthorityLock($presetId, static function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($request, $mutationAuthority, $presetId): array {
                                $mutationAuthority->assertStructuralMutationAllowed(
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
                        $presetId = (int)($request['presetId'] ?? 0);
                        $mutationAuthority = $this->mutationAuthority();
                        $result[] = $mutationAuthority
                            ->withAuthorityLock($presetId, static function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($request, $mutationAuthority): array {
                                $mutationAuthority->assertStructuralMutationAllowed(
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
                        $mutationAuthority = $this->mutationAuthority();
                        $addResult = $mutationAuthority->withAuthorityLock($presetId, function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($request, $presetId, $mutationAuthority): array {
                            $mutationAuthority->assertStructuralMutationAllowed(
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
                                $mutationAuthority->refreshLockedState($presetId);
                                $mutationAuthority->assertStageLinkToPreset($presetId, $stageId, $protected);
                                \Prospektweb\Calc\Services\PresetEnrichmentService::addStageToPresetPinned(
                                    $presetId,
                                    $stageId,
                                    (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0)
                                );
                            }
                            return $this->completeStructuralMutationPinned(
                                $created,
                                $presetId,
                                $pinnedIblockIds
                            );
                        });

                        $result[] = $addResult;
                        continue 2;

                    case 'duplicateStage':
                        $presetId = (int)($request['presetId'] ?? 0);
                        $mutationAuthority = $this->mutationAuthority();
                        $duplicateResult = $mutationAuthority->withAuthorityLock($presetId, function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($request, $presetId, $mutationAuthority): array {
                            $mutationAuthority->assertStructuralMutationAllowed(
                                $presetId,
                                [(int)($request['detailId'] ?? 0)],
                                $protected,
                                'stages'
                            );
                            $mutationAuthority->assertStageStructuralMutationAllowed(
                                $presetId,
                                (int)($request['stageId'] ?? 0),
                                $protected,
                                'stage duplication'
                            );
                            $duplicate = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                ->duplicateStage($request);
                            $stageId = (int)($duplicate['config']['id'] ?? 0);
                            if (($duplicate['status'] ?? 'error') === 'ok' && $presetId > 0 && $stageId > 0) {
                                $mutationAuthority->refreshLockedState($presetId);
                                $mutationAuthority->assertStageLinkToPreset(
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
                            return $this->completeStructuralMutationPinned(
                                $duplicate,
                                $presetId,
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
                        $mutationAuthority = $this->mutationAuthority();
                        $deleteResult = $mutationAuthority
                            ->withAuthorityLock($presetId, function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($stageId, $presetId, $request, $mutationAuthority): array {
                                $mutationAuthority->assertStageStructuralMutationAllowed(
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
                                return $this->completeStructuralMutationPinned(
                                    ['status' => 'ok', 'stageId' => $stageId],
                                    $presetId,
                                    $pinnedIblockIds
                                );
                            });
                        $result[] = $deleteResult;
                        continue 2;
                    
                    case 'removeDetail':
                        $parentId = (int)($request['parentId'] ?? 0);
                        $detailId = (int)($request['detailId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        $mutationAuthority = $this->mutationAuthority();
                        $removeResult = $mutationAuthority
                            ->withAuthorityLock($presetId, function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($parentId, $detailId, $presetId, $request, $mutationAuthority): array {
                                $mutationAuthority->assertStructuralMutationAllowed(
                                    $presetId,
                                    [$parentId, $detailId],
                                    $protected,
                                    'detail removal'
                                );
                                foreach ([$parentId, $detailId] as $deletedDetailId) {
                                    if ($deletedDetailId > 0) {
                                        $mutationAuthority->assertDetailDeletionCascadeAllowed(
                                            $presetId,
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
                                return $this->completeStructuralMutationPinned(
                                    $removed,
                                    $presetId,
                                    $pinnedIblockIds
                                );
                            });
                        $result[] = $removeResult;
                        continue 2;
                    
                    case 'renameDetail':
                        $detailId = (int)($request['detailId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        $name = (string)($request['name'] ?? '');
                        $mutationAuthority = $this->mutationAuthority();
                        $result[] = $mutationAuthority->withAuthorityLock($presetId, static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($mutationAuthority, $presetId, $detailId, $name): array {
                            $mutationAuthority->assertStructuralMutationAllowed(
                                $presetId,
                                [$detailId],
                                $protected,
                                'detail rename'
                            );
                            return (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                ->renameDetail($detailId, $name);
                        });
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
                            $mutationAuthority = $this->mutationAuthority();
                            $settingsResult = $mutationAuthority->withAuthorityLock($presetId, function (
                                bool $active,
                                array $pinnedIblockIds
                                ) use (
                                    $mutationAuthority,
                                    $presetId,
                                $stageId,
                                $settingsId,
                                $request
                            ): array {
                                $mutationAuthority->assertSettingsLinkToStage(
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
                                return $this->completeStructuralMutationPinned(
                                    ['status' => 'ok'],
                                    $presetId,
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
                        $mutationAuthority = $this->mutationAuthority();
                        $result[] = $mutationAuthority->withAuthorityLock($presetId, function (
                                bool $_unusedProtection,
                                array $pinnedIblockIds
                            ) use ($mutationAuthority, $stageId, $operationVariantId, $presetId, $request): array {
                                $mutationAuthority->assertStageStructuralMutationAllowed(
                                    $presetId,
                                    $stageId,
                                    false,
                                    'operation variant'
                                );
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
                                $stagesIblockId = (int)($pinnedIblockIds['CALC_STAGES'] ?? 0);
                                self::assertPinnedPropertyCodesExist(
                                    $stagesIblockId,
                                    ['OPERATION_VARIANT', 'OPTIONS_OPERATION'],
                                    'calculator stage'
                                );
                                \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                                    'OPERATION_VARIANT' => $operationVariantId > 0 ? $operationVariantId : false,
                                    'OPTIONS_OPERATION' => false,
                                ]);
                                return $this->completeStructuralMutationPinned(
                                    ['status' => 'ok'],
                                    $presetId,
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
                        $mutationAuthority = $this->mutationAuthority();
                        $result[] = $mutationAuthority->withAuthorityLock($presetId, function (
                                bool $_unusedProtection,
                                array $pinnedIblockIds
                            ) use ($mutationAuthority, $stageId, $equipmentId, $presetId, $request): array {
                                $mutationAuthority->assertStageStructuralMutationAllowed(
                                    $presetId,
                                    $stageId,
                                    false,
                                    'equipment selection'
                                );
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
                                $stagesIblockId = (int)($pinnedIblockIds['CALC_STAGES'] ?? 0);
                                self::assertPinnedPropertyCodesExist(
                                    $stagesIblockId,
                                    ['EQUIPMENT', 'OPTIONS_EQUIPMENT'],
                                    'calculator stage'
                                );
                                \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                                    'EQUIPMENT' => $equipmentId > 0 ? $equipmentId : false,
                                    'OPTIONS_EQUIPMENT' => false,
                                ]);
                                return $this->completeStructuralMutationPinned(
                                    ['status' => 'ok'],
                                    $presetId,
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
                        $mutationAuthority = $this->mutationAuthority();
                        $result[] = $mutationAuthority->withAuthorityLock($presetId, function (
                                bool $_unusedProtection,
                                array $pinnedIblockIds
                            ) use ($mutationAuthority, $stageId, $materialVariantId, $presetId, $request): array {
                                $mutationAuthority->assertStageStructuralMutationAllowed(
                                    $presetId,
                                    $stageId,
                                    false,
                                    'material variant'
                                );
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
                                $stagesIblockId = (int)($pinnedIblockIds['CALC_STAGES'] ?? 0);
                                self::assertPinnedPropertyCodesExist(
                                    $stagesIblockId,
                                    ['MATERIAL_VARIANT', 'OPTIONS_MATERIAL'],
                                    'calculator stage'
                                );
                                \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                                    'MATERIAL_VARIANT' => $materialVariantId > 0 ? $materialVariantId : false,
                                    'OPTIONS_MATERIAL' => false,
                                ]);
                                return $this->completeStructuralMutationPinned(
                                    ['status' => 'ok'],
                                    $presetId,
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

                            $mutationAuthority = $this->mutationAuthority();
                            $globalsResult = $mutationAuthority->withAuthorityLock($presetId, function (
                                bool $active,
                                array $pinnedIblockIds
                            ) use (
                                $mutationAuthority,
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
                                $mutationAuthority->assertPresetGlobalsWrite(
                                    $presetId,
                                    $preparedVariables,
                                    $preparedConstants,
                                    $active
                                );
                                \CIBlockElement::SetPropertyValuesEx($presetId, $presetsIblockId, [
                                    'GLOBAL_VARIABLES' => $preparedVariables ?: false,
                                    'GLOBAL_CONSTANTS' => $preparedConstants ?: false,
                                ]);
                                return $this->completeStructuralMutationPinned(
                                    ['status' => 'ok'],
                                    $presetId,
                                    $pinnedIblockIds
                                );
                            });
                            $result[] = $globalsResult;
                        } catch (\Throwable $error) {
                            $result[] = ['status' => 'error', 'message' => $error->getMessage()];
                        }
                        continue 2;

                    case 'changeCustomFieldsValue':
                        $stageId = (int)($request['stageId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        $customFieldsValue = $request['customFieldsValue'] ?? [];
                        if ($stageId <= 0 || !is_array($customFieldsValue)) {
                            $result[] = [
                                'status' => 'error',
                                'message' => 'Stage ID и массив customFieldsValue обязательны',
                            ];
                            continue 2;
                        }
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
                            $visible = !array_key_exists('VISIBLE', $field)
                                || filter_var($field['VISIBLE'], FILTER_VALIDATE_BOOLEAN);
                            $values[] = [
                                'VALUE' => $code,
                                'DESCRIPTION' => $value . '|' . ($visible ? 'Y' : 'N'),
                            ];
                        }
                        $mutationAuthority = $this->mutationAuthority();
                        $changeResponse = $mutationAuthority->withAuthorityLock($presetId, static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($mutationAuthority, $presetId, $stageId, $values): array {
                            $mutationAuthority->assertStageStructuralMutationAllowed(
                                $presetId,
                                $stageId,
                                $protected,
                                'stage custom-field values'
                            );
                            $stagesIblockId = (int)($pinnedIblockIds['CALC_STAGES'] ?? 0);
                            $property = \CIBlockProperty::GetList([], [
                                'IBLOCK_ID' => $stagesIblockId,
                                '=CODE' => 'CUSTOM_FIELDS_VALUE',
                            ])->Fetch();
                            if (!is_array($property)) {
                                throw new \RuntimeException(
                                    'Stage CUSTOM_FIELDS_VALUE property is not provisioned.',
                                    409
                                );
                            }
                            \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                                'CUSTOM_FIELDS_VALUE' => $values ?: false,
                            ]);
                            return ['status' => 'ok', 'stageId' => $stageId];
                        });
                        $result[] = $this->completePresetOwnedMutation($changeResponse, $presetId);
                        continue 2;
                        
                    case 'selectFields':
                        $stageId = (int)($request['stageId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
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
                            $mutationAuthority = $this->mutationAuthority();
                            $mutationAuthority->withAuthorityLock($presetId, static function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use (
                                $mutationAuthority,
                                $presetId,
                                $stageId,
                                $customFieldIds,
                                $submittedValues,
                                $replaceCustomFields
                            ): void {
                                $mutationAuthority->assertStageStructuralMutationAllowed(
                                    $presetId,
                                    $stageId,
                                    $protected,
                                    'stage custom-field selection'
                                );
                                self::writeStageCustomFieldSelectionPinned(
                                    $stageId,
                                    $customFieldIds,
                                    $submittedValues,
                                    $replaceCustomFields,
                                    $pinnedIblockIds
                                );
                                (new \Prospektweb\Calc\Services\PresetEnrichmentService($pinnedIblockIds))
                                    ->synchronizePresetCustomFields($presetId);
                            });
                        }

                        $result[] = $this->completePresetOwnedMutation(['status' => 'ok'], $presetId);
                        continue 2;

                    case 'createCustomField':
                        $stageId = (int)($request['stageId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        $field = is_array($request['field'] ?? null) ? $request['field'] : [];
                        $name = trim((string)($field['name'] ?? ''));
                        $type = trim((string)($field['type'] ?? 'text'));
                        $allowedTypes = ['number', 'text', 'checkbox', 'select'];
                        if ($stageId <= 0 || $name === '' || !in_array($type, $allowedTypes, true)) {
                            $result[] = ['status' => 'error', 'message' => 'Укажите название и корректный тип дополнительного параметра'];
                            continue 2;
                        }
                        $mutationAuthority = $this->mutationAuthority();
                        $response = $mutationAuthority->withAuthorityLock($presetId, static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($mutationAuthority, $presetId, $stageId, $field): array {
                            $mutationAuthority->assertStageStructuralMutationAllowed(
                                $presetId,
                                $stageId,
                                $protected,
                                'stage custom-field creation'
                            );
                            $created = self::createStageCustomFieldPinned(
                                $stageId,
                                $field,
                                $pinnedIblockIds
                            );
                            (new \Prospektweb\Calc\Services\PresetEnrichmentService($pinnedIblockIds))
                                ->synchronizePresetCustomFields($presetId);
                            return ['status' => 'ok'] + $created;
                        });
                        $result[] = $this->completePresetOwnedMutation($response, $presetId);
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
                        $equipmentIblockId = (int)($this->pinnedRuntimeIblockIds['CALC_EQUIPMENT'] ?? 0);

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
                        $presetId = (int)($request['presetId'] ?? 0);
                        $name = trim((string)($request['name'] ?? ''));
                        $previewText = trim((string)($request['previewText'] ?? ''));
                        if ($stageId <= 0 || $name === '') {
                            throw new \InvalidArgumentException('Stage ID and name are required.', 422);
                        }
                        $mutationAuthority = $this->mutationAuthority();
                        $result[] = $mutationAuthority->withAuthorityLock($presetId, static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($mutationAuthority, $presetId, $stageId, $name, $previewText): array {
                            $mutationAuthority->assertStageStructuralMutationAllowed(
                                $presetId,
                                $stageId,
                                $protected,
                                'stage metadata'
                            );
                            self::assertPinnedElementExists(
                                $stageId,
                                (int)($pinnedIblockIds['CALC_STAGES'] ?? 0),
                                'calculator stage'
                            );
                            $el = new \CIBlockElement();
                            if (!$el->Update($stageId, [
                                'NAME' => $name,
                                'PREVIEW_TEXT' => $previewText,
                                'PREVIEW_TEXT_TYPE' => 'text',
                            ])) {
                                throw new \RuntimeException(
                                    $el->LAST_ERROR ?: 'Не удалось сохранить этап',
                                    409
                                );
                            }
                            return [
                                'status' => 'ok',
                                'id' => $stageId,
                                'name' => $name,
                                'previewText' => $previewText,
                            ];
                        });
                        continue 2;

                    case 'changeEntityMeta':
                        $entityId = (int)($request['entityId'] ?? 0);
                        $entityType = (string)($request['entityType'] ?? '');
                        $presetId = (int)($request['presetId'] ?? 0);
                        $name = trim((string)($request['name'] ?? ''));
                        $previewText = trim((string)($request['previewText'] ?? ''));
                        if ($entityId <= 0 || !in_array($entityType, ['detail', 'preset'], true) || $name === '') {
                            $result[] = ['status' => 'error', 'message' => 'Некорректные данные сущности'];
                            continue 2;
                        }
                        $mutationAuthority = $this->mutationAuthority();
                        $result[] = $mutationAuthority->withAuthorityLock($presetId, static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $mutationAuthority,
                            $presetId,
                            $entityId,
                            $entityType,
                            $name,
                            $previewText
                        ): array {
                            if ($entityType === 'detail') {
                                $mutationAuthority->assertStructuralMutationAllowed(
                                    $presetId,
                                    [$entityId],
                                    $protected,
                                    'detail metadata'
                                );
                                $iblockId = (int)($pinnedIblockIds['CALC_DETAILS'] ?? 0);
                            } else {
                                $mutationAuthority->assertPresetMutationAllowed($presetId, $entityId);
                                $iblockId = (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0);
                            }
                            self::assertPinnedElementExists($entityId, $iblockId, $entityType);
                            $el = new \CIBlockElement();
                            if (!$el->Update($entityId, [
                                'NAME' => $name,
                                'PREVIEW_TEXT' => $previewText,
                                'PREVIEW_TEXT_TYPE' => 'text',
                            ])) {
                                throw new \RuntimeException(
                                    $el->LAST_ERROR ?: 'Не удалось сохранить данные',
                                    409
                                );
                            }
                            return [
                                'status' => 'ok',
                                'entityType' => $entityType,
                                'id' => $entityId,
                                'name' => $name,
                                'previewText' => $previewText,
                            ];
                        });
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
                        $presetId = (int)($request['presetId'] ?? 0);
                        $mutationAuthority = $this->mutationAuthority();
                        $result[] = $mutationAuthority
                            ->withAuthorityLock($presetId, static function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($request, $mutationAuthority, $presetId): array {
                                $mutationAuthority->assertStructuralMutationAllowed(
                                    (int)($request['presetId'] ?? 0),
                                    [(int)($request['detailId'] ?? 0)],
                                    $protected,
                                    'detail deletion'
                                );
                                $mutationAuthority->assertDetailDeletionCascadeAllowed(
                                    $presetId,
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
                        $detailId = (int)($request['detailId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        $mutationAuthority = $this->mutationAuthority();
                        $result[] = $mutationAuthority->withAuthorityLock($presetId, static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($mutationAuthority, $presetId, $detailId, $request): array {
                            $mutationAuthority->assertStructuralMutationAllowed(
                                $presetId,
                                [$detailId],
                                $protected,
                                'detail name'
                            );
                            return (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                ->changeName($request);
                        });
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
                        $mutationAuthority = $this->mutationAuthority();
                        $addResult = $mutationAuthority
                            ->withAuthorityLock($presetId, function (
                                bool $protected,
                                array $pinnedIblockIds
                            ) use ($parentId, $presetId, $name, $request, $mutationAuthority): array {
                                $mutationAuthority->assertStructuralMutationAllowed(
                                    $presetId,
                                    [$parentId],
                                    $protected,
                                    'binding details'
                                );
                                $created = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                    ->addDetailToBinding($parentId, $name);
                                return $this->completeStructuralMutationPinned(
                                    $created,
                                    $presetId,
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
                        $mutationAuthority = $this->mutationAuthority();
                        $sortResult = $mutationAuthority->withAuthorityLock($presetId, function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $mutationAuthority,
                            $presetId,
                            $parentId,
                            $sorting,
                            $request
                        ): array {
                            $mutationAuthority->assertStructuralMutationAllowed(
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
                            return $this->completeStructuralMutationPinned(
                                $changed,
                                $presetId,
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
                        
                        $mutationAuthority = $this->mutationAuthority();
                        $levelResult = $mutationAuthority->withAuthorityLock($presetId, function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $mutationAuthority,
                            $presetId,
                            $fromParentId,
                            $detailId,
                            $toParentId,
                            $sorting,
                            $request
                        ): array {
                            $mutationAuthority->assertStructuralMutationAllowed(
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
                            return $this->completeStructuralMutationPinned(
                                $changed,
                                $presetId,
                                $pinnedIblockIds
                            );
                        });
                        
                        $result[] = $levelResult;
                        continue 2;
                    
                    case 'changeSortStage':
                        $detailId = (int)($request['detailId'] ?? 0);
                        $sorting = is_array($request['sorting'] ?? null) ? $request['sorting'] : [];
                        $presetId = (int)($request['presetId'] ?? 0);
                        $mutationAuthority = $this->mutationAuthority();
                        $stageResult = $mutationAuthority->withAuthorityLock($presetId, function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $mutationAuthority,
                            $detailId,
                            $sorting,
                            $presetId,
                            $request
                        ): array {
                            $mutationAuthority->assertStructuralMutationAllowed(
                                $presetId,
                                [$detailId],
                                $protected,
                                'stage order'
                            );
                            $changed = (new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds))
                                ->changeSortStage($detailId, $sorting, false);
                            return $this->completeStructuralMutationPinned(
                                $changed,
                                $presetId,
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

                        $mutationAuthority = $this->mutationAuthority();
                        $sortResult = $mutationAuthority->withAuthorityLock($presetId, function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $mutationAuthority,
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
                            $mutationAuthority->assertStructuralMutationAllowed(
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
                            return $this->completeStructuralMutationPinned(
                                [
                                    'status' => 'ok',
                                    'presetId' => $presetId,
                                    'sorting' => $sorting,
                                    'rootDetailIds' => $sorting,
                                ],
                                $presetId,
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
                        $mutationAuthority = $this->mutationAuthority();
                        $stageResult = $mutationAuthority->withAuthorityLock($presetId, function (
                            bool $protected,
                            array $lockedIblockIds
                        ) use (
                            $mutationAuthority,
                            $presetId,
                            $stageId,
                            $sourceDetailId,
                            $targetDetailId,
                            $sourceSorting,
                            $targetSorting,
                            $request
                        ): array {
                            $mutationAuthority->assertStageMoveAllowed(
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
                            return $this->completeStructuralMutationPinned(
                                $moved,
                                $presetId,
                                $lockedIblockIds
                            );
                        });
                        $result[] = $stageResult;
                        continue 2;
                    
                    case 'addDetailsToBinding':
                        $parentId = (int)($request['parentId'] ?? 0);
                        $detailIds = $this->normalizeIds($request['detailIds'] ?? []);
                        $presetId = (int)($request['presetId'] ?? 0);
                        if ($parentId <= 0 || $detailIds === []) {
                            throw new \InvalidArgumentException(
                                'Binding target and moved detail IDs are required.',
                                422
                            );
                        }
                        $mutationAuthority = $this->mutationAuthority();
                        $addDetailsResult = $mutationAuthority->withAuthorityLock($presetId, function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                             $mutationAuthority,
                             $presetId,
                             $parentId,
                             $detailIds
                         ): array {
                            foreach ($detailIds as $detailId) {
                                $source = $mutationAuthority->assertDetailMoveIntoBindingAllowed(
                                    $presetId,
                                    (int)$detailId,
                                    $parentId,
                                    $protected
                                );
                                self::moveDetailIntoBindingPinned(
                                    $presetId,
                                    (int)$detailId,
                                    $parentId,
                                    $source,
                                    $pinnedIblockIds
                                );
                                $mutationAuthority->assertDetailMoveIntoBindingApplied(
                                    $presetId,
                                    (int)$detailId,
                                    $parentId,
                                    $source
                                );
                            }
                            $attached = [
                                'status' => 'ok',
                                'parentId' => $parentId,
                                'detailIds' => $detailIds,
                                'rootDetailIds' => $mutationAuthority->presetRootDetailIds($presetId),
                            ];
                            return $this->completeStructuralMutationPinned(
                                $attached,
                                $presetId,
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
                        $mutationAuthority = $this->mutationAuthority();
                        $pricesResult = $mutationAuthority->withAuthorityLock($presetId, function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $mutationAuthority,
                            $presetId,
                            $prices,
                            $priceProfilePolicy,
                            $request
                        ): array {
                            $changed = (new \Prospektweb\Calc\Services\PresetPriceService($pinnedIblockIds))
                                ->changePricePreset(
                                    $presetId,
                                    is_array($prices) ? $prices : [],
                                    $priceProfilePolicy
                                );
                            return $this->completeStructuralMutationPinned(
                                $changed,
                                $presetId,
                                $pinnedIblockIds
                            );
                        });
                        $result[] = $pricesResult;
                        continue 2;

                    case 'updateOffersFromCalculation':
                        throw new \RuntimeException('USE_CATALOG_WRITE_PREVIEW_APPLY', 409);

                    case 'saveCalcLogic':
                        self::assertExactRequestKeys($request, [
                            'action',
                            'presetId',
                            'settingsId',
                            'stageId',
                            'calcSettings',
                            'stageWiring',
                            'stageParametrValuesScheme',
                        ], 'saveCalcLogic');
                        $presetId = (int)($request['presetId'] ?? 0);
                        $settingsId = (int)($request['settingsId'] ?? 0);
                        $stageId = (int)($request['stageId'] ?? 0);
                        if ($presetId <= 0 || $settingsId <= 0 || $stageId <= 0) {
                            throw new \InvalidArgumentException(
                                'saveCalcLogic requires exact preset, settings and stage IDs.',
                                422
                            );
                        }
                        $calcSettings = is_array($request['calcSettings'] ?? null)
                            ? $request['calcSettings']
                            : null;
                        $stageWiring = is_array($request['stageWiring'] ?? null)
                            ? $request['stageWiring']
                            : null;
                        $stageScheme = is_array($request['stageParametrValuesScheme'] ?? null)
                            ? $request['stageParametrValuesScheme']
                            : null;
                        if ($calcSettings === null || $stageWiring === null || $stageScheme === null) {
                            throw new \InvalidArgumentException('saveCalcLogic documents must be objects.', 422);
                        }
                        self::assertExactRequestKeys(
                            $calcSettings,
                            ['logicJson', 'params', 'globalDependencies'],
                            'saveCalcLogic.calcSettings'
                        );
                        self::assertExactRequestKeys(
                            $stageWiring,
                            ['inputs', 'outputs'],
                            'saveCalcLogic.stageWiring'
                        );
                        self::assertExactRequestKeys(
                            $stageScheme,
                            ['offer'],
                            'saveCalcLogic.stageParametrValuesScheme'
                        );
                        $logicJson = $calcSettings['logicJson'] ?? null;
                        if (!is_string($logicJson)) {
                            throw new \InvalidArgumentException('saveCalcLogic.logicJson must be a string.', 422);
                        }
                        $params = self::normalizeValueDescriptionRows(
                            $calcSettings['params'] ?? null,
                            'name',
                            'type',
                            'saveCalcLogic.calcSettings.params'
                        );
                        $globalDependencies = self::normalizeStringList(
                            $calcSettings['globalDependencies'] ?? null,
                            'saveCalcLogic.calcSettings.globalDependencies'
                        );
                        $inputs = self::normalizeValueDescriptionRows(
                            $stageWiring['inputs'] ?? null,
                            'name',
                            'path',
                            'saveCalcLogic.stageWiring.inputs'
                        );
                        $outputs = self::normalizeValueDescriptionRows(
                            $stageWiring['outputs'] ?? null,
                            'key',
                            'var',
                            'saveCalcLogic.stageWiring.outputs'
                        );
                        $schemeOffer = self::normalizeValueDescriptionRows(
                            $stageScheme['offer'] ?? null,
                            'name',
                            'template',
                            'saveCalcLogic.stageParametrValuesScheme.offer'
                        );
                        $mutationAuthority = $this->mutationAuthority();
                        $result[] = $mutationAuthority->withAuthorityLock($presetId, static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $mutationAuthority,
                            $presetId,
                            $settingsId,
                            $stageId,
                            $logicJson,
                            $params,
                            $globalDependencies,
                            $inputs,
                            $outputs,
                            $schemeOffer
                        ): array {
                            $mutationAuthority->assertSettingsMutationAllowed(
                                $presetId,
                                $settingsId,
                                $protected
                            );
                            $mutationAuthority->assertStageStructuralMutationAllowed(
                                $presetId,
                                $stageId,
                                $protected,
                                'atomic calculator logic write'
                            );
                            $mutationAuthority->assertSettingsLinkToStage(
                                $presetId,
                                $stageId,
                                $settingsId,
                                $protected
                            );
                            $mutationAuthority->assertSettingsLogicWrite(
                                $presetId,
                                $settingsId,
                                $logicJson,
                                $protected
                            );
                            $inputExpressions = array_map(
                                static fn(array $row): array => [
                                    'expression' => (string)$row['DESCRIPTION'],
                                ],
                                $inputs
                            );
                            $encodedInputs = json_encode(
                                $inputExpressions,
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                            );
                            $mutationAuthority->assertStageInputsWrite(
                                $presetId,
                                $stageId,
                                $encodedInputs,
                                $protected
                            );
                            $settingsIblockId = (int)($pinnedIblockIds['CALC_SETTINGS'] ?? 0);
                            $stagesIblockId = (int)($pinnedIblockIds['CALC_STAGES'] ?? 0);
                            self::assertPinnedPropertyCodesExist(
                                $settingsIblockId,
                                ['LOGIC_JSON', 'PARAMS', 'GLOBAL_DEPENDENCIES'],
                                'calculator settings'
                            );
                            self::assertPinnedPropertyCodesExist(
                                $stagesIblockId,
                                ['INPUTS', 'OUTPUTS', 'SCHEME_PARAMETR_VALUES'],
                                'calculator stage'
                            );
                            \CIBlockElement::SetPropertyValuesEx($settingsId, $settingsIblockId, [
                                'LOGIC_JSON' => $logicJson,
                                'PARAMS' => $params ?: false,
                                'GLOBAL_DEPENDENCIES' => $globalDependencies ?: false,
                            ]);
                            \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                                'INPUTS' => $inputs ?: false,
                                'OUTPUTS' => $outputs ?: false,
                                'SCHEME_PARAMETR_VALUES' => $schemeOffer ?: false,
                            ]);
                            return [
                                'status' => 'ok',
                                'presetId' => $presetId,
                                'settingsId' => $settingsId,
                                'stageId' => $stageId,
                            ];
                        });
                        continue 2;
                        
                    case 'updateStageProperty':
                        // Handler for CHANGE_OPTIONS_OPERATION and CHANGE_OPTIONS_MATERIAL
                        $presetId = (int)($request['presetId'] ?? 0);
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
                        ];
                        if ($stageId <= 0 || !in_array($propertyCode, $allowedStageProperties, true)) {
                            throw new \RuntimeException(
                                'Unsupported stage property write: ' . ($propertyCode !== '' ? $propertyCode : '<invalid>'),
                                409
                            );
                        }
                        if (in_array($propertyCode, [
                            'OPTIONS_OPERATION',
                            'OPTIONS_MATERIAL',
                            'OPTIONS_EQUIPMENT',
                        ], true)) {
                            if (!is_string($value)) {
                                throw new \InvalidArgumentException(
                                    'Stage variant mapping must be a JSON string.',
                                    422
                                );
                            }
                            try {
                                $value = (new \Prospektweb\Calc\Services\StageVariantMappingService())
                                    ->normalizeJson($value);
                            } catch (\InvalidArgumentException $error) {
                                throw new \InvalidArgumentException($error->getMessage(), 422, $error);
                            }
                        }
                        $clearDirectMaterialSelection = false;
                        if ($propertyCode === 'OPTIONS_MATERIAL' && $value !== '') {
                            $normalizedMapping = json_decode($value, true);
                            $clearDirectMaterialSelection = is_array($normalizedMapping)
                                && ($normalizedMapping['contract'] ?? '') === 'prospektweb.calc.stage-material-selection/v3';
                        }
                        $mutationAuthority = $this->mutationAuthority();
                        $mutationAuthority->withAuthorityLock($presetId, static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $mutationAuthority,
                            $presetId,
                            $stageId,
                            $propertyCode,
                            $value,
                            $clearDirectMaterialSelection
                        ): void {
                            $mutationAuthority->assertStageStructuralMutationAllowed(
                                $presetId,
                                $stageId,
                                $protected,
                                'stage property ' . $propertyCode
                            );
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
                            if ($propertyCode === 'OPTIONS_MATERIAL' && $value !== '') {
                                self::assertPinnedPropertyCodesExist(
                                    $stagesIblockId,
                                    ['MATERIAL_VARIANT', 'OPTIONS_MATERIAL'],
                                    'calculator stage'
                                );
                            }
                            if ($propertyCode === 'INPUTS') {
                                $mutationAuthority->assertStageInputsWrite(
                                    $presetId,
                                    $stageId,
                                    $value,
                                    $protected
                                );
                            } elseif ($propertyCode === 'ACTIVATION_CONDITION') {
                                $mutationAuthority->assertStageActivationConditionWrite(
                                    $presetId,
                                    $stageId,
                                    $value,
                                    $protected,
                                    (int)($pinnedIblockIds['CALC_GLOBAL_VALUES'] ?? 0)
                                );
                            }
                            // Bitrix does not reliably remove a single-value property when an
                            // empty string is written.  A mapping reset is a deletion, so use the
                            // canonical false sentinel that SetPropertyValuesEx understands.
                            $propertyValues = [$propertyCode => $value === '' ? false : $value];
                            // OPTIONS_MATERIAL is an alternative selection mode, not an
                            // additional fallback. Persisting it must atomically remove the
                            // direct variant reference so runtime has one authoritative source.
                            if ($clearDirectMaterialSelection) {
                                $propertyValues['MATERIAL_VARIANT'] = false;
                            }
                            \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, $propertyValues);
                        });
                        $result[] = [
                            'status' => 'ok',
                            'propertyCode' => $propertyCode,
                            'value' => $value,
                            'clearedPropertyCode' => $clearDirectMaterialSelection
                                ? 'MATERIAL_VARIANT'
                                : null,
                        ];
                        continue 2;

                    case 'inspectCalculatorContract':
                        $handler = new \Prospektweb\Calc\Services\CalculatorContractService();
                        $result[] = $handler->inspect((int)($request['settingsId'] ?? 0));
                        continue 2;

                    case 'resolveCalculatorContract':
                        $settingsId = (int)($request['settingsId'] ?? 0);
                        $stageId = (int)($request['stageId'] ?? 0);
                        $currentPresetId = (int)($request['currentPresetId'] ?? 0);
                        $mode = (string)($request['mode'] ?? '');
                        if ($mode !== 'clone') {
                            throw new \RuntimeException(
                                'Shared calculator contracts may only be resolved by cloning.',
                                409
                            );
                        }
                        $mutationAuthority = $this->mutationAuthority();
                        $response = $mutationAuthority->withAuthorityLock($currentPresetId, static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $mutationAuthority,
                            $settingsId,
                            $stageId,
                            $currentPresetId,
                            $mode,
                            $request
                        ): array {
                            $mutationAuthority->assertContractCloneAllowed(
                                $currentPresetId,
                                $stageId,
                                $settingsId
                            );
                            return (new \Prospektweb\Calc\Services\CalculatorContractService($pinnedIblockIds))
                                ->resolve(
                                    $settingsId,
                                    $stageId,
                                    $currentPresetId,
                                    $mode,
                                    (string)($request['message'] ?? '')
                                );
                        });
                        $result[] = ($response['status'] ?? null) === 'ok'
                            ? $this->completePresetOwnedMutation($response, $currentPresetId)
                            : $response;
                        continue 2;

                    case 'saveStageUsedEntities':
                        $stageId = (int)($request['stageId'] ?? 0);
                        $presetId = (int)($request['presetId'] ?? 0);
                        $requestedXmlIds = array_values(array_intersect(
                            array_map('strval', is_array($request['usedEntities'] ?? null) ? $request['usedEntities'] : []),
                            ['VARIANT_OPERATION', 'EQUIPMENT', 'VARIANT_MATERIAL']
                        ));
                        if ($stageId <= 0) {
                            $result[] = ['status' => 'error', 'message' => 'Этап или инфоблок этапов не найден'];
                            continue 2;
                        }
                        $mutationAuthority = $this->mutationAuthority();
                        $response = $mutationAuthority->withAuthorityLock($presetId, static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use ($mutationAuthority, $presetId, $stageId, $requestedXmlIds): array {
                            $mutationAuthority->assertStageStructuralMutationAllowed(
                                $presetId,
                                $stageId,
                                $protected,
                                'stage used entities'
                            );
                            $stagesIblockId = (int)($pinnedIblockIds['CALC_STAGES'] ?? 0);
                            foreach (['USED_ENTITY_CODES', 'STAGE_OWNERSHIP_VERSION'] as $code) {
                                $property = \CIBlockProperty::GetList([], [
                                    'IBLOCK_ID' => $stagesIblockId,
                                    '=CODE' => $code,
                                ])->Fetch();
                                if (!is_array($property)) {
                                    throw new \RuntimeException(
                                        'Stage property ' . $code . ' is not provisioned.',
                                        409
                                    );
                                }
                            }
                            \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
                                'USED_ENTITY_CODES' => $requestedXmlIds ?: false,
                                'STAGE_OWNERSHIP_VERSION' => 5,
                            ]);
                            return ['status' => 'ok', 'stageId' => $stageId];
                        });
                        $result[] = $this->completePresetOwnedMutation($response, $presetId);
                        continue 2;
                        
                    case 'updateSettingsProperty':
                        // Handler for CHANGE_LOGIC
                        $presetId = (int)($request['presetId'] ?? 0);
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
                        $mutationAuthority = $this->mutationAuthority();
                        $mutationAuthority->withAuthorityLock($presetId, static function (
                            bool $protected,
                            array $pinnedIblockIds
                        ) use (
                            $mutationAuthority,
                            $presetId,
                            $settingsId,
                            $propertyCode,
                            $value
                        ): void {
                            $mutationAuthority->assertSettingsMutationAllowed(
                                $presetId,
                                $settingsId,
                                $protected
                            );
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
                                $mutationAuthority->assertSettingsLogicWrite(
                                    $presetId,
                                    $settingsId,
                                    $value,
                                    $protected
                                );
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

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function enrichPreset(array $request): array
    {
        $presetId = (int)($request['presetId'] ?? 0);
        $detailIdsRaw = $request['detailIds'] ?? null;
        $binding = $request['binding'] ?? null;
        $existingDetailId = (int)($request['existingDetailId'] ?? 0);
        if ($presetId <= 0 || !is_array($detailIdsRaw) || !array_is_list($detailIdsRaw)) {
            throw new \InvalidArgumentException('Preset enrichment requires exact preset and detail IDs.', 422);
        }
        if (!is_bool($binding)) {
            throw new \InvalidArgumentException('Preset enrichment binding must be boolean.', 422);
        }
        $detailIds = [];
        foreach ($detailIdsRaw as $detailId) {
            if (!is_int($detailId) || $detailId <= 0) {
                throw new \InvalidArgumentException('Preset enrichment detail ID is invalid.', 422);
            }
            $detailIds[$detailId] = $detailId;
        }
        $detailIds = array_values($detailIds);
        if ($detailIds === []) {
            throw new \InvalidArgumentException('Preset enrichment requires at least one detail.', 422);
        }

        $mutationAuthority = $this->mutationAuthority();
        return $mutationAuthority->withAuthorityLock($presetId, static function (
            bool $protected,
            array $pinnedIblockIds
        ) use (
            $mutationAuthority,
            $presetId,
            $detailIds,
            $binding,
            $existingDetailId
        ): array {
            $subjectDetailIds = $detailIds;
            if ($existingDetailId > 0) {
                $subjectDetailIds[] = $existingDetailId;
            }
            $mutationAuthority->assertStructuralMutationAllowed(
                $presetId,
                $subjectDetailIds,
                $protected,
                'preset enrichment'
            );

            $detailHandler = new \Prospektweb\Calc\Services\DetailHandler($pinnedIblockIds);
            $rootDetailId = 0;
            if (count($detailIds) === 1 && !$binding) {
                $rootDetailId = $detailIds[0];
            } elseif ($binding) {
                $allDetailIds = $existingDetailId > 0
                    ? array_merge([$existingDetailId], $detailIds)
                    : $detailIds;
                $allDetailIds = array_values(array_unique($allDetailIds));
                if (count($allDetailIds) === 1) {
                    $rootDetailId = $allDetailIds[0];
                } else {
                    $groupResult = $detailHandler->addGroup(['detailIds' => $allDetailIds]);
                    if (($groupResult['status'] ?? '') !== 'ok') {
                        throw new \RuntimeException(
                            (string)($groupResult['message'] ?? 'Unable to create preset binding.')
                        );
                    }
                    $rootDetailId = (int)($groupResult['group']['id'] ?? 0);
                }
            } else {
                $groupResult = $detailHandler->addGroup(['detailIds' => $detailIds]);
                if (($groupResult['status'] ?? '') !== 'ok') {
                    throw new \RuntimeException(
                        (string)($groupResult['message'] ?? 'Unable to create preset group.')
                    );
                }
                $rootDetailId = (int)($groupResult['group']['id'] ?? 0);
            }
            if ($rootDetailId <= 0) {
                throw new \RuntimeException('Preset enrichment did not create an exact root.', 409);
            }

            return [
                'status' => 'ok',
                'rootDetailId' => $rootDetailId,
                'initPayload' => (new \Prospektweb\Calc\Services\PresetEnrichmentService($pinnedIblockIds))
                    ->rebuildPresetFromRoot($presetId, $rootDetailId),
            ];
        });
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function clearPreset(array $request): array
    {
        $presetId = (int)($request['presetId'] ?? 0);
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Preset clearing requires an exact preset ID.', 422);
        }
        $siteId = trim((string)($request['siteId'] ?? (defined('SITE_ID') ? SITE_ID : 's1')));
        if ($siteId === '') {
            $siteId = 's1';
        }
        $mutationAuthority = $this->mutationAuthority();
        $response = $mutationAuthority->withAuthorityLock($presetId, static function (
            bool $protected,
            array $pinnedIblockIds
        ) use ($mutationAuthority, $presetId): array {
            $mutationAuthority->assertStructuralMutationAllowed(
                $presetId,
                [],
                $protected,
                'preset clearing'
            );
            (new \Prospektweb\Calc\Services\PresetEnrichmentService($pinnedIblockIds))
                ->clearPreset($presetId);
            return ['status' => 'ok'];
        });
        return $this->completePresetOwnedMutation($response, $presetId, $siteId);
    }

    /**
     * Attach the legacy public INIT only for product-neutral direct callers.
     * Version-working mutations receive an exact version-aware readback from
     * CalculatorSemanticMutationService and must never invoke the public INIT
     * loader for an inactive working preset.
     *
     * @param array<string,mixed> $operationResult
     * @return array<string,mixed>
     */
    private function completePresetOwnedMutation(
        array $operationResult,
        int $presetId,
        ?string $siteId = null
    ): array {
        if ($this->deferInitPayloadToSemanticReadback || $presetId <= 0) {
            return $operationResult;
        }
        $resolvedSiteId = trim((string)($siteId ?? (defined('SITE_ID') ? SITE_ID : 's1')));
        $operationResult['initPayload'] = (new InitPayloadService())->preparePresetPayload(
            $presetId,
            $resolvedSiteId !== '' ? $resolvedSiteId : 's1'
        );
        return $operationResult;
    }

    /**
     * Complete a structural mutation and its derived preset rebuild while the
     * same calculator authority transaction is still held.
     *
     * @param array<string,mixed> $operationResult
     * @param array<string,int> $pinnedIblockIds
     * @return array<string,mixed>
     */
    private static function enrichStructuralResultPinned(
        array $operationResult,
        int $presetId,
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
                : $enrichment->getRootsFromPreset($presetId)
        ))));
        if ($rootDetailIds !== []) {
            $operationResult['initPayload'] = $enrichment->rebuildPresetFromRoots(
                $presetId,
                $rootDetailIds
            );
        } else {
            $enrichment->clearPreset($presetId);
            $operationResult['initPayload'] = (new InitPayloadService())->preparePresetPayload(
                $presetId,
                defined('SITE_ID') ? (string)SITE_ID : 's1'
            );
        }
        return $operationResult;
    }

    /**
     * Finish a preset-owned mutation without invoking the public INIT loader
     * for an inactive version-working preset. The semantic mutation boundary
     * performs one exact version-aware readback after this callback. Legacy
     * direct callers retain their product-neutral INIT response.
     *
     * @param array<string,mixed> $operationResult
     * @param array<string,int> $pinnedIblockIds
     * @return array<string,mixed>
     */
    private function completeStructuralMutationPinned(
        array $operationResult,
        int $presetId,
        array $pinnedIblockIds
    ): array {
        if (!$this->deferInitPayloadToSemanticReadback) {
            return self::enrichStructuralResultPinned($operationResult, $presetId, $pinnedIblockIds);
        }
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
                : $enrichment->getRootsFromPreset($presetId)
        ))));
        if ($rootDetailIds !== []) {
            $enrichment->rebuildPresetIndexesFromRoots($presetId, $rootDetailIds);
        } else {
            $enrichment->clearPreset($presetId);
        }
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

    /**
     * @param array{sourceKind:string,sourceId:int} $source
     * @param array<string,int> $pinnedIblockIds
     */
    private static function moveDetailIntoBindingPinned(
        int $presetId,
        int $detailId,
        int $targetBindingId,
        array $source,
        array $pinnedIblockIds
    ): void {
        $presetsIblockId = (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0);
        $detailsIblockId = (int)($pinnedIblockIds['CALC_DETAILS'] ?? 0);
        self::assertPinnedElementExists($presetId, $presetsIblockId, 'calculator preset');
        self::assertPinnedElementExists($detailId, $detailsIblockId, 'moved calculator detail');
        self::assertPinnedElementExists($targetBindingId, $detailsIblockId, 'target binding detail');

        $sourceKind = (string)($source['sourceKind'] ?? '');
        $sourceId = (int)($source['sourceId'] ?? 0);
        if ($sourceKind === 'preset' && $sourceId === $presetId) {
            $sourceIblockId = $presetsIblockId;
            $sourcePropertyCode = 'CALC_DETAILS';
        } elseif ($sourceKind === 'detail' && $sourceId > 0) {
            self::assertPinnedElementExists($sourceId, $detailsIblockId, 'source calculator detail');
            $sourceIblockId = $detailsIblockId;
            $sourcePropertyCode = 'DETAILS';
        } else {
            throw new \RuntimeException('Detail move source authority is invalid.', 409);
        }

        $sourceIds = self::readPinnedLinkIds($sourceIblockId, $sourceId, $sourcePropertyCode);
        $sourcePositions = array_keys($sourceIds, $detailId, true);
        if (count($sourcePositions) !== 1) {
            throw new \RuntimeException('Detail move source changed before detach.', 409);
        }
        $targetIds = self::readPinnedLinkIds($detailsIblockId, $targetBindingId, 'DETAILS');
        if (in_array($detailId, $targetIds, true)) {
            throw new \RuntimeException('Target binding already contains the moved detail.', 409);
        }

        $sourceIds = array_values(array_filter(
            $sourceIds,
            static fn(int $id): bool => $id !== $detailId
        ));
        self::replacePinnedLinkIds(
            $sourceIblockId,
            $sourceId,
            $sourcePropertyCode,
            $sourceIds
        );
        $targetIds[] = $detailId;
        self::replacePinnedLinkIds(
            $detailsIblockId,
            $targetBindingId,
            'DETAILS',
            array_values($targetIds)
        );
    }

    /** @return int[] */
    private static function readPinnedLinkIds(
        int $iblockId,
        int $elementId,
        string $propertyCode
    ): array {
        self::assertPinnedElementExists($elementId, $iblockId, 'relationship source');
        $property = \CIBlockProperty::GetList([], [
            'IBLOCK_ID' => $iblockId,
            '=CODE' => $propertyCode,
        ])->Fetch();
        if (!is_array($property)) {
            throw new \RuntimeException('Relationship property ' . $propertyCode . ' is not provisioned.', 409);
        }
        $ids = [];
        $rows = \CIBlockElement::GetProperty(
            $iblockId,
            $elementId,
            ['sort' => 'asc', 'id' => 'asc'],
            ['CODE' => $propertyCode]
        );
        while ($row = $rows->Fetch()) {
            $id = (int)($row['VALUE'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** @param int[] $ids */
    private static function replacePinnedLinkIds(
        int $iblockId,
        int $elementId,
        string $propertyCode,
        array $ids
    ): void {
        $ids = array_values(array_map('intval', $ids));
        if (array_filter($ids, static fn(int $id): bool => $id <= 0) !== []) {
            throw new \InvalidArgumentException('Relationship IDs must be positive.', 422);
        }
        \CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [
            $propertyCode => false,
        ]);
        if ($ids !== []) {
            \CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [
                $propertyCode => $ids,
            ]);
        }
        if (self::readPinnedLinkIds($iblockId, $elementId, $propertyCode) !== $ids) {
            throw new \RuntimeException(
                'Relationship ' . $propertyCode . ' read-back failed.',
                409
            );
        }
    }

    /** @param array<string,mixed> $field @param array<string,int> $pinnedIblockIds */
    private static function createStageCustomFieldPinned(
        int $stageId,
        array $field,
        array $pinnedIblockIds
    ): array {
        $customFieldsIblockId = (int)($pinnedIblockIds['CALC_CUSTOM_FIELDS'] ?? 0);
        $stagesIblockId = (int)($pinnedIblockIds['CALC_STAGES'] ?? 0);
        self::assertPinnedElementExists($stageId, $stagesIblockId, 'calculator stage');
        if ($customFieldsIblockId <= 0) {
            throw new \RuntimeException('Pinned custom-field authority is invalid.', 409);
        }
        $name = trim((string)($field['name'] ?? ''));
        $type = trim((string)($field['type'] ?? 'text'));
        if ($name === '' || !in_array($type, ['number', 'text', 'checkbox', 'select'], true)) {
            throw new \InvalidArgumentException('Custom-field name or type is invalid.', 422);
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
        if ($code === '' || preg_match('/^[A-Z]/D', $code) !== 1) {
            $code = 'FIELD_' . ($code !== '' ? $code : 'VALUE');
        }
        if (\Prospektweb\Calc\Services\CalculatorMutationAuthorityService::isReservedIdentifier($code)) {
            $code = 'FIELD_' . $code;
        }
        $baseCode = substr($code, 0, 220);
        $code = $baseCode;
        $suffix = 2;
        while (\CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $customFieldsIblockId, '=CODE' => $code],
            false,
            ['nTopCount' => 1],
            ['ID']
        )->Fetch()) {
            $code = substr($baseCode, 0, 210) . '_' . $suffix++;
        }

        $enumId = static function (int $iblockId, string $propertyCode, string $xmlId): int {
            $property = \CIBlockProperty::GetList([], [
                'IBLOCK_ID' => $iblockId,
                '=CODE' => $propertyCode,
            ])->Fetch();
            if (!is_array($property)) {
                return 0;
            }
            $enumCursor = \CIBlockPropertyEnum::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], [
                'PROPERTY_ID' => (int)$property['ID'],
            ]);
            while ($enum = $enumCursor->Fetch()) {
                if ((string)($enum['XML_ID'] ?? '') === $xmlId) {
                    return (int)$enum['ID'];
                }
            }
            return 0;
        };
        $fieldTypeEnumId = $enumId($customFieldsIblockId, 'FIELD_TYPE', $type);
        $requiredEnumId = $enumId(
            $customFieldsIblockId,
            'IS_REQUIRED',
            !empty($field['required']) ? 'Y' : 'N'
        );
        if ($fieldTypeEnumId <= 0 || $requiredEnumId <= 0) {
            throw new \RuntimeException('Custom-field enum schema is not provisioned.', 409);
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
                'IS_REQUIRED' => $requiredEnumId,
                'UNIT' => $type === 'number' ? trim((string)($field['unit'] ?? '')) : '',
                'SORT_ORDER' => 500,
            ],
        ]);
        if ($fieldId <= 0) {
            throw new \RuntimeException(
                $element->LAST_ERROR ?: 'Bitrix did not create the custom field.',
                409
            );
        }
        try {
            self::assertPinnedElementExists(
                $fieldId,
                $customFieldsIblockId,
                'new calculator custom field'
            );
            self::writeStageCustomFieldSelectionPinned(
                $stageId,
                [$fieldId],
                [[
                    'CODE' => $code,
                    'VALUE' => (string)($field['defaultValue'] ?? ''),
                    'VISIBLE' => true,
                ]],
                false,
                $pinnedIblockIds
            );
        } catch (\Throwable $error) {
            if (!\CIBlockElement::Delete($fieldId)) {
                throw new \RuntimeException(
                    'Custom-field attachment failed and compensating deletion also failed.',
                    409,
                    $error
                );
            }
            throw $error;
        }

        return ['fieldId' => $fieldId, 'code' => $code];
    }

    /**
     * @param int[] $requestedCustomFieldIds
     * @param array<int,mixed> $submittedValues
     * @param array<string,int> $pinnedIblockIds
     */
    private static function writeStageCustomFieldSelectionPinned(
        int $stageId,
        array $requestedCustomFieldIds,
        array $submittedValues,
        bool $replaceCustomFields,
        array $pinnedIblockIds
    ): void {
        $stagesIblockId = (int)($pinnedIblockIds['CALC_STAGES'] ?? 0);
        $customFieldsIblockId = (int)($pinnedIblockIds['CALC_CUSTOM_FIELDS'] ?? 0);
        self::assertPinnedElementExists($stageId, $stagesIblockId, 'calculator stage');
        if ($customFieldsIblockId <= 0) {
            throw new \RuntimeException('Pinned custom-field authority is invalid.', 409);
        }
        foreach (['CUSTOM_FIELDS', 'CUSTOM_FIELDS_VALUE', 'STAGE_OWNERSHIP_VERSION'] as $code) {
            $property = \CIBlockProperty::GetList([], [
                'IBLOCK_ID' => $stagesIblockId,
                '=CODE' => $code,
            ])->Fetch();
            if (!is_array($property)) {
                throw new \RuntimeException('Stage property ' . $code . ' is not provisioned.', 409);
            }
        }

        $existingCustomFields = [];
        $stageCustomFieldProps = \CIBlockElement::GetProperty(
            $stagesIblockId,
            $stageId,
            ['sort' => 'asc'],
            ['CODE' => 'CUSTOM_FIELDS']
        );
        while ($property = $stageCustomFieldProps->Fetch()) {
            $fieldId = (int)($property['VALUE'] ?? 0);
            if ($fieldId > 0) {
                $existingCustomFields[] = $fieldId;
            }
        }
        $customFieldIds = array_values(array_unique(array_map('intval', $requestedCustomFieldIds)));
        $mergedCustomFields = $replaceCustomFields
            ? $customFieldIds
            : array_values(array_unique(array_merge($existingCustomFields, $customFieldIds)));
        foreach ($mergedCustomFields as $fieldId) {
            self::assertPinnedElementExists(
                (int)$fieldId,
                $customFieldsIblockId,
                'calculator custom field'
            );
        }

        $fieldsConfig = (new \Prospektweb\Calc\Services\CustomFieldsService())
            ->getFieldsConfig($mergedCustomFields);
        if (count($fieldsConfig) !== count($mergedCustomFields)) {
            throw new \RuntimeException('One or more selected custom fields are inactive or unavailable.', 409);
        }
        $selectedCodes = [];
        foreach ($fieldsConfig as $fieldConfig) {
            $fieldCode = trim((string)($fieldConfig['code'] ?? ''));
            if ($fieldCode === '' || isset($selectedCodes[$fieldCode])) {
                throw new \RuntimeException('Selected custom-field codes are empty or duplicated.', 409);
            }
            $selectedCodes[$fieldCode] = true;
        }

        \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
            'CUSTOM_FIELDS' => $mergedCustomFields ?: false,
            'STAGE_OWNERSHIP_VERSION' => 4,
        ]);
        $storedCustomFields = [];
        $storedFieldRows = \CIBlockElement::GetProperty(
            $stagesIblockId,
            $stageId,
            ['sort' => 'asc'],
            ['CODE' => 'CUSTOM_FIELDS']
        );
        while ($property = $storedFieldRows->Fetch()) {
            $fieldId = (int)($property['VALUE'] ?? 0);
            if ($fieldId > 0) {
                $storedCustomFields[] = $fieldId;
            }
        }
        $expectedFieldIds = $mergedCustomFields;
        sort($expectedFieldIds, SORT_NUMERIC);
        $storedCustomFields = array_values(array_unique($storedCustomFields));
        sort($storedCustomFields, SORT_NUMERIC);
        if ($storedCustomFields !== $expectedFieldIds) {
            throw new \RuntimeException('Custom-field selection read-back failed.', 409);
        }

        $existingValuesMap = [];
        $stageValueRows = \CIBlockElement::GetProperty(
            $stagesIblockId,
            $stageId,
            ['sort' => 'asc'],
            ['CODE' => 'CUSTOM_FIELDS_VALUE']
        );
        while ($property = $stageValueRows->Fetch()) {
            $fieldCode = (string)($property['VALUE'] ?? '');
            if ($fieldCode === '') {
                continue;
            }
            $description = (string)($property['DESCRIPTION'] ?? '');
            $visibilityMarker = 'Y';
            if (preg_match('/^(.*)\|[YN]$/s', $description, $matches)) {
                $visibilityMarker = substr($description, -1);
                $description = $matches[1];
            }
            $existingValuesMap[$fieldCode] = [
                'VALUE' => $fieldCode,
                'DESCRIPTION' => $description . '|' . $visibilityMarker,
            ];
        }
        if ($replaceCustomFields) {
            $existingValuesMap = array_filter(
                $existingValuesMap,
                static fn(array $value): bool => isset($selectedCodes[(string)($value['VALUE'] ?? '')])
            );
        }
        foreach ($fieldsConfig as $fieldConfig) {
            $fieldCode = (string)$fieldConfig['code'];
            $description = '';
            if (array_key_exists('default', $fieldConfig)) {
                $defaultValue = $fieldConfig['default'];
                if (is_bool($defaultValue)) {
                    $defaultValue = $defaultValue ? 'Y' : 'N';
                }
                $description = (string)$defaultValue;
            }
            if (isset($existingValuesMap[$fieldCode])) {
                $existingValuesMap[$fieldCode]['DESCRIPTION'] = (string)preg_replace(
                    '/\|N$/',
                    '|Y',
                    (string)$existingValuesMap[$fieldCode]['DESCRIPTION']
                );
                continue;
            }
            $existingValuesMap[$fieldCode] = [
                'VALUE' => $fieldCode,
                'DESCRIPTION' => $description . '|Y',
            ];
        }
        foreach ($submittedValues as $field) {
            if (!is_array($field)) {
                throw new \InvalidArgumentException('Custom-field value must be an object.', 422);
            }
            $fieldCode = trim((string)($field['CODE'] ?? ''));
            if ($fieldCode === '' || !isset($selectedCodes[$fieldCode])) {
                continue;
            }
            $fieldValue = (string)($field['VALUE'] ?? '');
            if (strpos($fieldValue, '|') !== false) {
                throw new \InvalidArgumentException('Custom-field value cannot contain |.', 422);
            }
            $fieldVisible = !array_key_exists('VISIBLE', $field)
                || filter_var($field['VISIBLE'], FILTER_VALIDATE_BOOLEAN);
            $existingValuesMap[$fieldCode] = [
                'VALUE' => $fieldCode,
                'DESCRIPTION' => $fieldValue . '|' . ($fieldVisible ? 'Y' : 'N'),
            ];
        }
        \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
            'CUSTOM_FIELDS_VALUE' => $existingValuesMap !== []
                ? array_values($existingValuesMap)
                : false,
        ]);
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

    /** @param array<string,mixed> $value @param string[] $expected */
    private static function assertExactRequestKeys(array $value, array $expected, string $surface): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException($surface . ' contains unsupported or missing fields.', 422);
        }
    }

    /** @return array<int,array{VALUE:string,DESCRIPTION:string}> */
    private static function normalizeValueDescriptionRows(
        $raw,
        string $valueKey,
        string $descriptionKey,
        string $surface
    ): array {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > 500) {
            throw new \InvalidArgumentException($surface . ' must be a bounded JSON array.', 422);
        }
        $result = [];
        foreach ($raw as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException($surface . '[' . $index . '] must be an object.', 422);
            }
            self::assertExactRequestKeys($row, [$valueKey, $descriptionKey], $surface . '[' . $index . ']');
            if (!is_string($row[$valueKey] ?? null) || !is_string($row[$descriptionKey] ?? null)) {
                throw new \InvalidArgumentException($surface . '[' . $index . '] values must be strings.', 422);
            }
            $result[] = [
                'VALUE' => $row[$valueKey],
                'DESCRIPTION' => $row[$descriptionKey],
            ];
        }
        return $result;
    }

    /** @return string[] */
    private static function normalizeStringList($raw, string $surface): array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > 500) {
            throw new \InvalidArgumentException($surface . ' must be a bounded JSON array.', 422);
        }
        $result = [];
        foreach ($raw as $index => $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException($surface . '[' . $index . '] must be a non-empty string.', 422);
            }
            $normalized = trim($value);
            if (isset($result[$normalized])) {
                throw new \InvalidArgumentException($surface . ' must not contain duplicates.', 422);
            }
            $result[$normalized] = $normalized;
        }
        ksort($result, SORT_STRING);
        return array_values($result);
    }

    /** @param string[] $codes */
    private static function assertPinnedPropertyCodesExist(int $iblockId, array $codes, string $surface): void
    {
        if ($iblockId <= 0) {
            throw new \RuntimeException('Pinned ' . $surface . ' iblock authority is invalid.', 409);
        }
        foreach ($codes as $code) {
            $property = \CIBlockProperty::GetList([], [
                'IBLOCK_ID' => $iblockId,
                '=CODE' => $code,
            ])->Fetch();
            if (!is_array($property)) {
                throw new \RuntimeException(
                    ucfirst($surface) . ' property ' . $code . ' must be provisioned before authoring.',
                    409
                );
            }
        }
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

    /**
     * Persist preset globals under an authority owned by the semantic
     * aggregate coordinator. No transaction is opened here.
     *
     * @param array<string,mixed> $request
     * @param array<string,int> $pinnedIblockIds
     * @return array<string,mixed>
     */
    public function savePresetGlobalsLocked(
        array $request,
        \Prospektweb\Calc\Services\CalculatorMutationAuthorityService $authority,
        array $pinnedIblockIds
    ): array {
        $presetId = (int)($request['presetId'] ?? 0);
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('savePresetGlobals requires an exact preset ID.', 422);
        }
        $variables = is_array($request['variables'] ?? null) ? $request['variables'] : [];
        $constants = is_array($request['constants'] ?? null) ? $request['constants'] : [];
        $prepare = static function (array $rows): array {
            $prepared = [];
            $seen = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new \InvalidArgumentException('Preset global value must be an object.', 422);
                }
                $code = trim((string)($row['VALUE'] ?? ''));
                if ($code === '') {
                    continue;
                }
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $code) !== 1) {
                    throw new \InvalidArgumentException('Invalid preset global code: ' . $code, 422);
                }
                if (isset($seen[$code])) {
                    throw new \InvalidArgumentException('Duplicate preset global code: ' . $code, 422);
                }
                $seen[$code] = true;
                $prepared[] = [
                    'VALUE' => $code,
                    'DESCRIPTION' => (string)($row['DESCRIPTION'] ?? ''),
                ];
            }
            return $prepared;
        };
        $preparedVariables = $prepare($variables);
        $preparedConstants = $prepare($constants);
        $allCodes = array_merge(
            array_column($preparedVariables, 'VALUE'),
            array_column($preparedConstants, 'VALUE')
        );
        if (count($allCodes) !== count(array_unique($allCodes))) {
            throw new \InvalidArgumentException('Variable and constant codes must be unique.', 422);
        }

        $presetsIblockId = (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0);
        self::assertPinnedPropertyCodesExist(
            $presetsIblockId,
            ['GLOBAL_VARIABLES', 'GLOBAL_CONSTANTS'],
            'calculator preset'
        );
        $authority->assertPresetGlobalsWrite(
            $presetId,
            $preparedVariables,
            $preparedConstants,
            false
        );
        \CIBlockElement::SetPropertyValuesEx($presetId, $presetsIblockId, [
            'GLOBAL_VARIABLES' => $preparedVariables ?: false,
            'GLOBAL_CONSTANTS' => $preparedConstants ?: false,
        ]);

        // Global declarations do not change the structural graph. Their
        // authoritative state is read back once by
        // CalculatorSemanticMutationService under the same transaction. Do
        // not invoke the public active-preset INIT loader here: an isolated
        // working preset of a calculator version is deliberately inactive.
        return [
            'status' => 'ok',
            'presetId' => $presetId,
        ];
    }

    /**
     * Persist the complete calculator-logic semantic document under an
     * already-held authority. All six properties succeed or roll back as one.
     *
     * @param array<string,mixed> $request
     * @param array<string,int> $pinnedIblockIds
     * @return array<string,mixed>
     */
    public function saveCalcLogicLocked(
        array $request,
        \Prospektweb\Calc\Services\CalculatorMutationAuthorityService $authority,
        array $pinnedIblockIds
    ): array {
        self::assertExactRequestKeys($request, [
            'action',
            'presetId',
            'settingsId',
            'stageId',
            'calcSettings',
            'stageWiring',
            'stageParametrValuesScheme',
        ], 'saveCalcLogic');
        $presetId = (int)($request['presetId'] ?? 0);
        $settingsId = (int)($request['settingsId'] ?? 0);
        $stageId = (int)($request['stageId'] ?? 0);
        if ($presetId <= 0 || $settingsId <= 0 || $stageId <= 0) {
            throw new \InvalidArgumentException(
                'saveCalcLogic requires exact preset, settings and stage IDs.',
                422
            );
        }
        $calcSettings = is_array($request['calcSettings'] ?? null) ? $request['calcSettings'] : null;
        $stageWiring = is_array($request['stageWiring'] ?? null) ? $request['stageWiring'] : null;
        $stageScheme = is_array($request['stageParametrValuesScheme'] ?? null)
            ? $request['stageParametrValuesScheme']
            : null;
        if ($calcSettings === null || $stageWiring === null || $stageScheme === null) {
            throw new \InvalidArgumentException('saveCalcLogic documents must be objects.', 422);
        }
        self::assertExactRequestKeys(
            $calcSettings,
            ['logicJson', 'params', 'globalDependencies'],
            'saveCalcLogic.calcSettings'
        );
        self::assertExactRequestKeys($stageWiring, ['inputs', 'outputs'], 'saveCalcLogic.stageWiring');
        self::assertExactRequestKeys($stageScheme, ['offer'], 'saveCalcLogic.stageParametrValuesScheme');
        $logicJson = $calcSettings['logicJson'] ?? null;
        if (!is_string($logicJson)) {
            throw new \InvalidArgumentException('saveCalcLogic.logicJson must be a string.', 422);
        }
        $params = self::normalizeValueDescriptionRows(
            $calcSettings['params'] ?? null,
            'name',
            'type',
            'saveCalcLogic.calcSettings.params'
        );
        $globalDependencies = self::normalizeStringList(
            $calcSettings['globalDependencies'] ?? null,
            'saveCalcLogic.calcSettings.globalDependencies'
        );
        $inputs = self::normalizeValueDescriptionRows(
            $stageWiring['inputs'] ?? null,
            'name',
            'path',
            'saveCalcLogic.stageWiring.inputs'
        );
        $outputs = self::normalizeValueDescriptionRows(
            $stageWiring['outputs'] ?? null,
            'key',
            'var',
            'saveCalcLogic.stageWiring.outputs'
        );
        $schemeOffer = self::normalizeValueDescriptionRows(
            $stageScheme['offer'] ?? null,
            'name',
            'template',
            'saveCalcLogic.stageParametrValuesScheme.offer'
        );

        $authority->assertSettingsMutationAllowed($presetId, $settingsId, false);
        $authority->assertStageStructuralMutationAllowed(
            $presetId,
            $stageId,
            false,
            'atomic calculator logic write'
        );
        $authority->assertSettingsLinkToStage($presetId, $stageId, $settingsId, false);
        $authority->assertSettingsLogicWrite($presetId, $settingsId, $logicJson, false);
        $encodedInputs = json_encode(
            array_map(
                static fn(array $row): array => ['expression' => (string)$row['DESCRIPTION']],
                $inputs
            ),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $authority->assertStageInputsWrite($presetId, $stageId, $encodedInputs, false);
        $settingsIblockId = (int)($pinnedIblockIds['CALC_SETTINGS'] ?? 0);
        $stagesIblockId = (int)($pinnedIblockIds['CALC_STAGES'] ?? 0);
        self::assertPinnedPropertyCodesExist(
            $settingsIblockId,
            ['LOGIC_JSON', 'PARAMS', 'GLOBAL_DEPENDENCIES'],
            'calculator settings'
        );
        self::assertPinnedPropertyCodesExist(
            $stagesIblockId,
            ['INPUTS', 'OUTPUTS', 'SCHEME_PARAMETR_VALUES'],
            'calculator stage'
        );
        \CIBlockElement::SetPropertyValuesEx($settingsId, $settingsIblockId, [
            'LOGIC_JSON' => $logicJson,
            'PARAMS' => $params ?: false,
            'GLOBAL_DEPENDENCIES' => $globalDependencies ?: false,
        ]);
        \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, [
            'INPUTS' => $inputs ?: false,
            'OUTPUTS' => $outputs ?: false,
            'SCHEME_PARAMETR_VALUES' => $schemeOffer ?: false,
        ]);

        return [
            'status' => 'ok',
            'presetId' => $presetId,
            'settingsId' => $settingsId,
            'stageId' => $stageId,
        ];
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
