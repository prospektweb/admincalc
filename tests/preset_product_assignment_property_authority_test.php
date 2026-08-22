<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/Services/PresetProductAssignmentPropertyAuthorityService.php';
require_once __DIR__ . '/../lib/Services/ControlCenterEditorsService.php';

use Prospektweb\Calc\Services\ControlCenterEditorsService;
use Prospektweb\Calc\Services\PresetProductAssignmentPropertyAuthorityService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$forUpdateSeen = false;
$presetIblockSeen = 0;
$authority = new PresetProductAssignmentPropertyAuthorityService([
    'read_rows' => static function (
        int $iblockId,
        int $presetIblockId,
        bool $forUpdate
    ) use (&$forUpdateSeen, &$presetIblockSeen): array {
        $forUpdateSeen = $forUpdate;
        $presetIblockSeen = $presetIblockId;
        return [[
            'ID' => 91,
            'IBLOCK_ID' => $iblockId,
            'CODE' => 'CALC_PRESET',
            'ACTIVE' => 'Y',
            'PROPERTY_TYPE' => 'E',
            'MULTIPLE' => 'N',
            'LINK_IBLOCK_ID' => $presetIblockId,
        ]];
    },
]);
$resolved = $authority->resolve(7, 41, true);
$assert(
    $forUpdateSeen
        && $presetIblockSeen === 41
        && $resolved === ['productIblockId' => 7, 'presetIblockId' => 41, 'propertyId' => 91],
    'The exact property row must be pinned under the requested transaction lock.'
);

$exactRow = [
    'ID' => 91,
    'IBLOCK_ID' => 7,
    'CODE' => 'CALC_PRESET',
    'ACTIVE' => 'Y',
    'PROPERTY_TYPE' => 'E',
    'MULTIPLE' => 'N',
    'LINK_IBLOCK_ID' => 41,
];
$invalidAuthorities = [
    'missing' => [],
    'case collision' => [[
        'ID' => 92,
        'IBLOCK_ID' => 7,
        'CODE' => 'calc_preset',
        'ACTIVE' => 'Y',
        'PROPERTY_TYPE' => 'E',
        'MULTIPLE' => 'N',
        'LINK_IBLOCK_ID' => 41,
    ]],
    'duplicate' => [
        $exactRow,
        array_replace($exactRow, ['ID' => 92]),
    ],
    'inactive' => [array_replace($exactRow, ['ACTIVE' => 'N'])],
    'wrong type' => [array_replace($exactRow, ['PROPERTY_TYPE' => 'S'])],
    'multiple' => [array_replace($exactRow, ['MULTIPLE' => 'Y'])],
    'wrong link' => [array_replace($exactRow, ['LINK_IBLOCK_ID' => 42])],
];
foreach ($invalidAuthorities as $label => $rows) {
    $rejected = false;
    try {
        (new PresetProductAssignmentPropertyAuthorityService([
            'read_rows' => static fn(): array => $rows,
        ]))->resolve(7, 41, true);
    } catch (RuntimeException $error) {
        $rejected = $error->getCode() === 409;
    }
    $assert($rejected, 'Invalid CALC_PRESET authority must fail closed: ' . $label);
}

$domainMutationCalls = 0;
$service = new ControlCenterEditorsService(
    productIblockIdResolver: static fn(): int => 7,
    presetProductMutationHandler: static function () use (&$domainMutationCalls): array {
        $domainMutationCalls++;
        return [];
    },
    presetProductAssignmentLocker: static fn(int $iblockId, callable $criticalSection) => $criticalSection($iblockId),
    presetMutationCoordinator: static fn(int $presetId, array $metadata, callable $mutation, callable $readback) => $mutation(),
    presetProductPropertyAuthority: static function (): array {
        throw new RuntimeException('CALC_PRESET property authority is missing or ambiguous.', 409);
    }
);
$mutationRejected = false;
try {
    $service->setPresetProducts(12740, [4267], str_repeat('a', 64), str_repeat('b', 64));
} catch (RuntimeException $error) {
    $mutationRejected = $error->getCode() === 409;
}
$assert($mutationRejected && $domainMutationCalls === 0, 'Ambiguous property authority must prevent every assignment write.');

$assignmentSources = '';
foreach ([
    'lib/Services/ControlCenterEditorsService.php',
    'lib/Services/CatalogTreeService.php',
    'lib/Services/BatchRecalculateService.php',
    'lib/Services/PresetSectionSelectorService.php',
    'lib/Services/PresetProductAssignmentMutationGuardService.php',
    'lib/Calculator/InitPayloadService.php',
    'admin/calculator.php',
] as $relativePath) {
    $assignmentSources .= (string)file_get_contents(dirname(__DIR__) . '/' . $relativePath);
}
foreach (['PROPERTY_CALC_PRESET', "['CODE' => 'CALC_PRESET']", "['CALC_PRESET' =>"] as $forbidden) {
    $assert(!str_contains($assignmentSources, $forbidden), 'Assignment code must use the pinned property ID: ' . $forbidden);
}
$authoritySource = (string)file_get_contents(
    dirname(__DIR__) . '/lib/Services/PresetProductAssignmentPropertyAuthorityService.php'
);
$editorsSource = (string)file_get_contents(
    dirname(__DIR__) . '/lib/Services/ControlCenterEditorsService.php'
);
$endpointSource = (string)file_get_contents(
    dirname(__DIR__) . '/tools/control_center_editors.php'
);
$assert(
    str_contains($authoritySource, 'FROM b_iblock_property')
        && str_contains($authoritySource, "AND CODE='")
        && str_contains($authoritySource, "' FOR UPDATE'")
        && str_contains($authoritySource, 'count($rows) !== 1')
        && str_contains($authoritySource, '$code !== self::PROPERTY_CODE')
        && str_contains($authoritySource, "!== 'Y'")
        && str_contains($authoritySource, "!== 'E'")
        && str_contains($authoritySource, "!== 'N'")
        && str_contains($authoritySource, '!== $presetIblockId'),
    'Property authority must lock collation-equivalent rows and require the exact active single element link.'
);
$assert(
    str_contains($endpointSource, "lockedIblockIds['CALC_PRESETS']")
        && str_contains($editorsSource, "lockedIblockIds['CALC_PRESETS']")
        && !str_contains($editorsSource, "['multiple']")
        && !str_contains($editorsSource, "? \$mutation['next']"),
    'Storefront and assignment mutations must consume the coordinator-pinned preset iblock and scalar property contract.'
);

echo "Preset product assignment property authority tests passed\n";
