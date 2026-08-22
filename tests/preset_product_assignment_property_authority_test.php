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
$authority = new PresetProductAssignmentPropertyAuthorityService([
    'read_rows' => static function (int $iblockId, bool $forUpdate) use (&$forUpdateSeen): array {
        $forUpdateSeen = $forUpdate;
        return [[
            'ID' => 91,
            'IBLOCK_ID' => $iblockId,
            'CODE' => 'CALC_PRESET',
            'MULTIPLE' => 'N',
        ]];
    },
]);
$resolved = $authority->resolve(7, true);
$assert(
    $forUpdateSeen
        && $resolved === ['productIblockId' => 7, 'propertyId' => 91, 'multiple' => false],
    'The exact property row must be pinned under the requested transaction lock.'
);

foreach ([
    [],
    [[
        'ID' => 92,
        'IBLOCK_ID' => 7,
        'CODE' => 'calc_preset',
        'MULTIPLE' => 'N',
    ]],
    [
        ['ID' => 91, 'IBLOCK_ID' => 7, 'CODE' => 'CALC_PRESET', 'MULTIPLE' => 'N'],
        ['ID' => 92, 'IBLOCK_ID' => 7, 'CODE' => 'calc_preset', 'MULTIPLE' => 'N'],
    ],
] as $rows) {
    $rejected = false;
    try {
        (new PresetProductAssignmentPropertyAuthorityService([
            'read_rows' => static fn(): array => $rows,
        ]))->resolve(7, true);
    } catch (RuntimeException $error) {
        $rejected = $error->getCode() === 409;
    }
    $assert($rejected, 'Missing, case-colliding or duplicate CALC_PRESET rows must fail closed.');
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
$assert(
    str_contains($authoritySource, 'FROM b_iblock_property')
        && str_contains($authoritySource, "AND CODE='")
        && str_contains($authoritySource, "' FOR UPDATE'")
        && str_contains($authoritySource, 'count($rows) !== 1')
        && str_contains($authoritySource, '$code !== self::PROPERTY_CODE'),
    'Property authority must enumerate collation-equivalent rows, lock them and require one binary-exact code.'
);

echo "Preset product assignment property authority tests passed\n";
