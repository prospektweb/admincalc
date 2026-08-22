<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/PresetProductAssignmentMutationGuardService.php';

use Prospektweb\Calc\Services\PresetProductAssignmentMutationGuardService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$guard = new PresetProductAssignmentMutationGuardService([
    'product_iblock_id' => static fn(): int => 7,
    'property_id' => static fn(int $iblockId): int => 91,
    'element_iblock_id' => static fn(int $elementId): int => 7,
    'current_preset_ids' => static fn(int $iblockId, int $elementId): array => [12740],
]);

$guard->assertElementUpdateAllowed([
    'ID' => 4267,
    'IBLOCK_ID' => 7,
    'PROPERTY_VALUES' => [91 => [['VALUE' => '12740']]],
]);

$addRejected = false;
try {
    $guard->assertElementAddAllowed([
        'IBLOCK_ID' => 7,
        'PROPERTY_VALUES' => [91 => [['VALUE' => 12740]]],
    ]);
} catch (RuntimeException $error) {
    $addRejected = $error->getCode() === 409;
}
$assert($addRejected, 'direct product Add with CALC_PRESET must be rejected');
$guard->assertElementAddAllowed([
    'IBLOCK_ID' => 7,
    'PROPERTY_VALUES' => [91 => false],
]);

foreach ([
    [91 => false],
    ['CALC_PRESET' => 12741],
] as $directWrite) {
    $rejected = false;
    try {
        $guard->assertElementUpdateAllowed([
            'ID' => 4267,
            'IBLOCK_ID' => 7,
            'PROPERTY_VALUES' => $directWrite,
        ]);
    } catch (RuntimeException $error) {
        $rejected = $error->getCode() === 409
            && str_contains($error->getMessage(), 'Центре управления');
    }
    $assert($rejected, 'direct product-card CALC_PRESET change/removal must be rejected');
}

$internalApplied = false;
PresetProductAssignmentMutationGuardService::runInternal(
    static function () use ($guard, &$internalApplied): void {
        $guard->assertPropertyWriteAllowed(4267, 7, ['CALC_PRESET' => false]);
        $internalApplied = true;
    }
);
$assert($internalApplied, 'scoped assignment authority may change CALC_PRESET');

$install = (string)file_get_contents(dirname(__DIR__) . '/install/index.php');
$adminHandler = (string)file_get_contents(dirname(__DIR__) . '/lib/Handlers/AdminHandler.php');
$editors = (string)file_get_contents(dirname(__DIR__) . '/lib/Services/ControlCenterEditorsService.php');
foreach ([
    'OnBeforeIBlockElementAdd',
    'OnBeforeIBlockElementUpdate',
    'OnBeforeIBlockElementSetPropertyValues',
    'OnBeforeIBlockElementSetPropertyValuesEx',
] as $event) {
    $assert(substr_count($install, "'" . $event . "'") === 2, $event . ' must register and unregister');
}
$assert(
    str_contains($adminHandler, 'makeCalcPresetAssignmentReadOnly')
        && str_contains($adminHandler, 'node.style.pointerEvents="none"'),
    'ordinary product card exposes CALC_PRESET as managed read-only state'
);
$assert(
    substr_count($editors, 'PresetProductAssignmentMutationGuardService::runInternal') >= 2,
    'assignment apply and rollback must use only the scoped internal guard'
);

$linkedStart = strpos($editors, 'private function loadLinkedProductIds');
$linkedEnd = strpos($editors, 'private function loadProductPresetIds', $linkedStart ?: 0);
$linkedSlice = $linkedStart !== false && $linkedEnd !== false
    ? substr($editors, $linkedStart, $linkedEnd - $linkedStart)
    : '';
$assert(
    $linkedSlice !== ''
        && !str_contains($linkedSlice, "'ACTIVE' => 'Y'")
        && !str_contains($linkedSlice, "'ACTIVE_DATE' => 'Y'"),
    'canonical assignment CAS/readback includes inactive and expired linked products'
);

fwrite(STDOUT, "Preset product assignment mutation guard tests passed\n");
