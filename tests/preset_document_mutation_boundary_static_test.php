<?php

declare(strict_types=1);

$adminRoot = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$endpoint = (string)file_get_contents($adminRoot . '/tools/control_center_editors.php');
$editors = (string)file_get_contents($adminRoot . '/lib/Services/ControlCenterEditorsService.php');
$inputMapping = (string)file_get_contents($adminRoot . '/lib/Services/CalculatorInputMappingService.php');
$outputMapping = (string)file_get_contents($adminRoot . '/lib/Services/CatalogOutputMappingService.php');
$coordinator = (string)file_get_contents($adminRoot . '/lib/Services/PresetMutationCoordinatorService.php');
$lifecycle = (string)file_get_contents($adminRoot . '/lib/Services/PresetLifecycleMutationService.php');
$mutationAuthority = (string)file_get_contents($adminRoot . '/lib/Services/CalculatorMutationAuthorityService.php');
$catalog = (string)file_get_contents($adminRoot . '/lib/Services/CalculatorCatalogService.php');
$initPayload = (string)file_get_contents($adminRoot . '/lib/Calculator/InitPayloadService.php');

foreach ([
    "'action' => 'set_preset_products'",
    "'action' => 'form_first_save_draft'",
    "'action' => 'form_first_publish'",
    "'action' => 'form_first_rollback'",
    "'action' => 'set_preset_active'",
] as $coordinatedEditorAction) {
    $assert(
        str_contains($editors, $coordinatedEditorAction),
        'ControlCenterEditorsService bypasses coordinator for ' . $coordinatedEditorAction
    );
}
$assert(
    substr_count($editors, '$this->withPresetMutation(') >= 5,
    'product assignment, activation and all form document writes use the shared coordinator'
);
$assert(
    str_contains($inputMapping, 'new PresetMutationCoordinatorService()')
        && str_contains($inputMapping, "'action' => 'calculator_input_mapping_save'"),
    'input mapping save bypasses the shared coordinator'
);
$assert(
    str_contains($outputMapping, 'new PresetMutationCoordinatorService()')
        && str_contains($outputMapping, "'action' => 'catalog_output_mapping_save'"),
    'output mapping save bypasses the shared coordinator'
);
$assert(
    substr_count($endpoint, '$service->withPresetMutation(') === 2
        && str_contains($endpoint, "'action' => 'storefront_save'")
        && str_contains($endpoint, "'action' => 'storefront_delete'"),
    'storefront save/delete must be the only endpoint-owned coordinated document writes'
);

foreach ([
    'ControlCenterEditorsService' => $editors,
    'CalculatorInputMappingService' => $inputMapping,
    'CatalogOutputMappingService' => $outputMapping,
    'control_center_editors endpoint' => $endpoint,
] as $label => $source) {
    $assert(
        !str_contains($source, 'startTransaction()')
            && !str_contains($source, 'commitTransaction()')
            && !str_contains($source, 'rollbackTransaction()'),
        $label . ' must not open a nested transaction inside the coordinator'
    );
}
$assert(
    substr_count($coordinator, 'startTransaction()') === 1
        && substr_count($coordinator, 'commitTransaction()') === 1
        && substr_count($coordinator, 'rollbackTransaction()') === 1
        && str_contains($coordinator, 'FOR UPDATE'),
    'PresetMutationCoordinatorService must own the only target document transaction'
);
$assert(
    !str_contains($coordinator, 'Option::get')
        && !str_contains($coordinator, 'Option::set')
        && str_contains($coordinator, 'GET_LOCK')
        && str_contains($coordinator, 'INSERT INTO b_option'),
    'coordinator bootstrap and revision readback must use direct database authority'
);

foreach ([
    'CalculatorInputMappingService' => $inputMapping,
    'CatalogOutputMappingService' => $outputMapping,
] as $label => $source) {
    $assert(!str_contains($source, 'Option::get'), $label . ' readback must not use Bitrix Option cache');
    $assert(str_contains($source, 'SELECT '), $label . ' must read authoritative database state');
}

foreach ([
    'actorId',
    'action',
    'entityType',
    'entityId',
    'coordinatorRevisionBefore',
    'coordinatorRevisionAfter',
    'expectedEntityRevision',
    'resultEntityRevision',
    'beforeSha256',
    'afterSha256',
    'productIds',
    "'result' => 'success'",
    'CEventLog::Add',
] as $auditField) {
    $assert(str_contains($coordinator, $auditField), 'coordinator audit is missing ' . $auditField);
}
$assert(
    str_contains($coordinator, 'Registry creation/duplication')
        && !str_contains($coordinator, "'action' => 'create_preset'")
        && !str_contains($coordinator, "'action' => 'duplicate_preset'")
        && str_contains($lifecycle, "'action' => 'create_preset'")
        && str_contains($lifecycle, "'action' => 'duplicate_preset'")
        && str_contains($lifecycle, 'lockAllAuthority(')
        && str_contains($lifecycle, 'withAuthorityLock(')
        && str_contains($lifecycle, 'readLockedPresetGraph($sourcePresetId)')
        && str_contains($lifecycle, 'readLockedPresetGraph($newPresetId)')
        && str_contains($editors, "'action' => 'set_preset_active'"),
    'single-preset activation is coordinated while duplicate uses its dedicated lifecycle authority'
);
$assert(
    str_contains($lifecycle, 'BitrixTransactionStateAuthority::isActive($connection)')
        && str_contains($lifecycle, 'if ($ownsTransaction)')
        && substr_count($lifecycle, 'if ($ownsTransaction)') >= 3,
    'clean-version logic initialization must preserve an outer version transaction instead of opening a nested rollback boundary'
);
$assert(
    str_contains($mutationAuthority, 'BitrixTransactionStateAuthority::isActive($connection)')
        && str_contains($lifecycle, "public const VERSION_WORKING_CODE_PREFIX = 'prospektweb-version-work-'")
        && str_contains($lifecycle, 'deleteVersionWorkingGraphIfOwned(')
        && str_contains($lifecycle, 'discoverVersionWorkingPresetIdsLocked(')
        && str_contains($lifecycle, 'deleteVersionWorkingPreset('),
    'version working graphs must join the outer transaction and have exact-marker guarded lifecycle cleanup'
);
$assert(
    str_contains($editors, "'!%CODE' => PresetLifecycleMutationService::VERSION_WORKING_CODE_PREFIX")
        && str_contains($catalog, "'!%CODE' => PresetLifecycleMutationService::VERSION_WORKING_CODE_PREFIX")
        && str_contains($initPayload, 'assertVersionWorkingPresetAvailableReadOnly(')
        && str_contains($initPayload, '$legacyUnmarked = $active === \'Y\''),
    'technical working graphs must be hidden from calculator catalogs while remaining available to their exact version editor'
);

fwrite(STDOUT, "Preset document mutation boundary static tests passed\n");
