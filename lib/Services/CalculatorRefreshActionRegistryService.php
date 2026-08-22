<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

/**
 * Closed registry for the legacy refresh transport. The transport is allowed
 * to dispatch only actions whose authority is explicitly named here.
 */
final class CalculatorRefreshActionRegistryService
{
    public const READ = 'read';
    public const PRESET_MUTATION = 'preset_mutation';
    public const GLOBAL_MUTATION = 'global_mutation';
    public const SELF_COORDINATED_MUTATION = 'self_coordinated_mutation';
    public const RETIRED = 'retired';

    /** @var string[] */
    private const READ_ACTIONS = [
        'generateAiText',
        'generateLogicAudit',
        'generateLogicProposal',
        'generateStageLogicProposal',
        'generateStagePreview',
        'getAiBaseProducts',
        'getAiSettings',
        'getCatalogEntityMeta',
        'getCatalogTree',
        'getDetailWithChildren',
        'getPresetLoadOptions',
        'inspectCalculatorContract',
        'previewGlobalCodeRefactor',
        'previewStageLogicPrompt',
    ];

    /** @var string[] */
    private const GLOBAL_MUTATION_ACTIONS = [
        'createCatalogSection',
        'deleteCatalogTreeNode',
        'deletePriceSettingsPreset',
        'moveCatalogEntitySection',
        'renamePriceSettingsPreset',
        'saveAiSettings',
        'saveCatalogEntityMeta',
        'saveCatalogTreeElement',
        'saveCatalogTreeSection',
        'savePriceSettingsPreset',
        'saveSettingsEquipment',
    ];

    /** @var string[] */
    private const SELF_COORDINATED_ACTIONS = [
        'applyGlobalCodeRefactor',
        'clonePreset',
    ];

    /** @var string[] */
    private const RETIRED_ACTIONS = [
        'updateOffersFromCalculation',
    ];

    public static function classify(string $action): ?string
    {
        if (CalculatorSemanticMutationService::isSemanticAction($action)) {
            return self::PRESET_MUTATION;
        }
        if (in_array($action, self::READ_ACTIONS, true)) {
            return self::READ;
        }
        if (in_array($action, self::GLOBAL_MUTATION_ACTIONS, true)) {
            return self::GLOBAL_MUTATION;
        }
        if (in_array($action, self::SELF_COORDINATED_ACTIONS, true)) {
            return self::SELF_COORDINATED_MUTATION;
        }
        if (in_array($action, self::RETIRED_ACTIONS, true)) {
            return self::RETIRED;
        }
        return null;
    }

    public static function isGlobalMutation(string $action): bool
    {
        return self::classify($action) === self::GLOBAL_MUTATION;
    }

    /** @return string[] */
    public static function globalMutationActions(): array
    {
        return self::GLOBAL_MUTATION_ACTIONS;
    }
}
