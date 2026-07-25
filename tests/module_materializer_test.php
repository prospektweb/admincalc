<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Modules/CanonicalJson.php';
require_once dirname(__DIR__) . '/lib/Modules/ModuleValidator.php';
require_once dirname(__DIR__) . '/lib/Modules/ModuleMaterializer.php';

use Prospektweb\Calc\Modules\CanonicalJson;
use Prospektweb\Calc\Modules\ModuleMaterializer;
use Prospektweb\Calc\Modules\ModuleValidator;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$fixturePath = dirname(__DIR__) . '/contracts/fixtures/digital-sheet-print-stage-v1.json';
$module = json_decode((string)file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
$assert(ModuleValidator::validate($module) === [], 'pilot module fixture must be valid');
$assert(
    CanonicalJson::moduleContentHash($module) === '79cfe4fd843259e19ea1c144656a67800721b08c4a73bf7a6eeb2ce9305e9e62',
    'pilot module content hash must stay cross-runtime stable'
);

$makeInstance = static function (string $instanceId, int $presetId, string $prefix) use ($module): array {
    $bindings = [];
    foreach (['quantity', 'itemWidthMm', 'itemLengthMm', 'bleedMm', 'twoSided'] as $code) {
        $bindings[] = [
            'portCode' => $code,
            'target' => ['kind' => 'source-path', 'value' => "{$prefix}.product.{$code}"],
        ];
    }
    foreach ([
        'sheetWidthMm',
        'sheetLengthMm',
        'fieldsWidthMm',
        'fieldsLengthMm',
        'setupCost',
        'impressionPurchasingPrice',
        'impressionBasePrice',
    ] as $code) {
        $bindings[] = [
            'portCode' => $code,
            'target' => ['kind' => 'global', 'value' => "{$prefix}.{$code}"],
        ];
    }
    return [
        'schema' => 'prospektweb.calc.module-instance/v1',
        'instanceId' => $instanceId,
        'presetId' => $presetId,
        'familyId' => $module['familyId'],
        'version' => $module['version'],
        'contentHash' => $module['contentHash'],
        'revision' => 1,
        'bindings' => $bindings,
        'entityBindings' => [
            ['roleCode' => 'printOperation', 'entityType' => 'operation', 'localElementIds' => [$presetId * 10 + 1]],
            ['roleCode' => 'paper', 'entityType' => 'material', 'localElementIds' => [$presetId * 10 + 2]],
        ],
        'dependencyLock' => [],
        'provenance' => [
            'createdAt' => '2026-07-25T00:00:00Z',
            'createdBy' => 'test',
            'legacyElementIds' => [],
        ],
    ];
};

$card = $makeInstance('business-card-print', 4592, 'businessCard');
$brochure = $makeInstance('brochure-print', 7001, 'brochure');
$assert(ModuleMaterializer::validateInstance($module, $card) === [], 'business-card instance rejected');
$assert(ModuleMaterializer::validateInstance($module, $brochure) === [], 'brochure instance rejected');

$options = [
    'snapshotId' => 'snapshot-card-v1',
    'presetRevision' => 1,
    'createdAt' => '2026-07-25T00:00:00Z',
    'resolvedBy' => 'admincalc',
    'resolverVersion' => ModuleMaterializer::RESOLVER_VERSION,
];
$cardSnapshot = ModuleMaterializer::materialize($module, $card, $options);
$options['snapshotId'] = 'snapshot-brochure-v1';
$brochureSnapshot = ModuleMaterializer::materialize($module, $brochure, $options);
$assert($cardSnapshot['contentHash'] === $brochureSnapshot['contentHash'], 'instances must share exact module content');
$assert($cardSnapshot['snapshotHash'] !== $brochureSnapshot['snapshotHash'], 'instance mappings must produce independent snapshots');
$assert(
    $cardSnapshot['resolvedGraph']['nodes'][0]['instanceNodeId'] === 'business-card-print:digitalPrint',
    'materialized node provenance missing'
);

$invalid = $card;
$invalid['bindings'] = array_values(array_filter(
    $invalid['bindings'],
    static fn(array $binding): bool => $binding['portCode'] !== 'sheetWidthMm'
));
$assert(
    str_contains(implode('; ', ModuleMaterializer::validateInstance($module, $invalid)), 'Required port is not bound'),
    'missing required global must block before runtime'
);

$nextModule = $module;
$nextModule['version'] = '1.1.0';
$nextModule['content']['nodes'][0]['logic']['vars'][9]['formula'] .= ' + 1';
$nextModule['contentHash'] = CanonicalJson::moduleContentHash($nextModule);
$nextInstance = $makeInstance('business-card-print', 4592, 'after');
$nextInstance['version'] = $nextModule['version'];
$nextInstance['contentHash'] = $nextModule['contentHash'];
$nextOptions = $options;
$nextOptions['snapshotId'] = 'snapshot-card-v2';
$nextOptions['presetRevision'] = 2;
$candidate = ModuleMaterializer::materialize($nextModule, $nextInstance, $nextOptions);
$preview = ModuleMaterializer::preview($cardSnapshot, $candidate);
$assert($preview['formulasChanged'] === ['digitalPrint'], 'preview must show changed formulas');
$assert(in_array('sheetWidthMm', $preview['ports']['changed'], true), 'preview must show changed mappings');
$assert($preview['expectedResultsChanged'] === true, 'preview must flag changed result contract');

echo "Module materializer tests passed\n";
