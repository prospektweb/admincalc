<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/BatchRecalculateService.php';

use Prospektweb\Calc\Services\BatchRecalculateService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expectFailure = static function (callable $callback, int $code, string $message) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $error) {
        $assert($error->getCode() === $code, $message . ' status');
        return;
    }
    $assert(false, $message);
};

$assert(BatchRecalculateService::normalizeRequestedPresetIds(['41']) === [41], 'any positive preset is accepted');
$assert(BatchRecalculateService::normalizeRequestedPresetIds([9876]) === [9876], 'batch scope has no pilot hardcode');
$expectFailure(static fn() => BatchRecalculateService::normalizeRequestedPresetIds([]), 400, 'preset is explicit');
$expectFailure(static fn() => BatchRecalculateService::normalizeRequestedPresetIds([41, 42]), 400, 'one batch targets one preset');
$expectFailure(static fn() => BatchRecalculateService::normalizeRequestedPresetIds(['bad']), 400, 'preset id is exact');

$assert(
    BatchRecalculateService::normalizeProductIdsByPresetScope([
        41 => [14380, '12727', 14380, 0],
        42 => [99],
    ]) === [41 => [12727, 14380], 42 => [99]],
    'product scope is normalized per generic preset'
);

$reflection = new ReflectionClass(BatchRecalculateService::class);
$service = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('buildCalculationPayloadFromCatalogPayload');
$method->setAccessible(true);
$expectFailure(static function () use ($method, $service): void {
    $method->invoke($service, [
        'editorRuntime' => [
            'catalogInputMapping' => [
                'ready' => false,
                'errors' => [['offerId' => 12, 'message' => 'missing']],
            ],
        ],
    ], 's1');
}, 409, 'automated batch fails only when its catalog input mapping is ineligible');

$neutralGuard = $reflection->getMethod('assertNeutralCalcServerPayload');
$neutralGuard->setAccessible(true);
$compileHash = str_repeat('a', 64);
$neutralPayload = [
    'neutralInputRequired' => true,
    'globalSymbols' => [],
    'preset' => ['id' => 41],
    'editorRuntime' => [
        'contract' => 'prospektweb.calc.editor-runtime/v2',
        'launchContext' => [
            'contract' => 'prospektweb.calc.launch-context/v2',
            'mode' => 'catalog',
            'presetId' => 41,
            'productIds' => [7],
            'offerIds' => [10],
        ],
        'formDefinition' => ['contract' => 'prospektweb.frontcalc.form-definition/v1'],
        'bindingDefinition' => ['contract' => 'prospektweb.frontcalc.binding-definition/v1'],
        'publication' => ['revision' => 3, 'compileHash' => $compileHash],
    ],
    'selectedOffers' => [[
        'id' => 10,
        'calculationInput' => [
            'contract' => 'prospektweb.calc.input-context/v1',
            'source' => 'catalog-input-mapping',
            'scenario' => ['id' => 'offer:10', 'target' => ['productId' => 7, 'offerId' => 10]],
            'preset' => ['id' => 41, 'revision' => 3, 'compileHash' => $compileHash],
            'values' => ['method' => 'digital'],
        ],
    ]],
];
$neutralGuard->invoke($service, $neutralPayload);
$expectFailure(static function () use ($neutralGuard, $service, $neutralPayload): void {
    unset($neutralPayload['neutralInputRequired']);
    $neutralGuard->invoke($service, $neutralPayload);
}, 409, 'calc-server payload always declares neutral input');
$expectFailure(static function () use ($neutralGuard, $service, $neutralPayload): void {
    $neutralPayload['selectedOffers'][0]['calculationInput']['preset']['revision'] = 2;
    $neutralGuard->invoke($service, $neutralPayload);
}, 409, 'per-offer input uses the exact publication revision');

$endpoint = (string)file_get_contents(dirname(__DIR__) . '/tools/batch_recalculate.php');
$source = (string)file_get_contents(dirname(__DIR__) . '/lib/Services/BatchRecalculateService.php');
$assert(str_contains($endpoint, 'BatchRecalculateService::normalizeRequestedPresetIds($presetIds)'), 'endpoint uses generic preset normalizer');
$assert(str_contains($endpoint, 'BatchRecalculateService::normalizeProductIdsByPresetScope($rawMap)'), 'endpoint uses generic product scope normalizer');
$assert(!str_contains($source, 'CatalogAdapterDefinitionService'), 'batch never reads the removed adapter');
$assert(!str_contains($source, 'StandalonePresetRuntime'), 'batch uses the form-first runtime catalog');
$assert(str_contains($source, 'PresetRuntimeCatalog'), 'batch derives enum authority from the published form');
$assert(str_contains($source, "'catalog-input-mapping'"), 'batch provenance names the input mapping authority');
$assert(str_contains($source, '$this->assertNeutralCalcServerPayload($initPayload);'), 'every calc-server call has a final neutral payload guard');

echo "Batch preset scope tests passed\n";
