<?php

require_once dirname(__DIR__) . '/lib/Config/ConfigManager.php';
require_once dirname(__DIR__) . '/lib/Calculator/InitPayloadService.php';
require_once dirname(__DIR__) . '/lib/Services/CatalogAdapterDefinitionService.php';
require_once dirname(__DIR__) . '/lib/Services/StandaloneCatalogSelectionMapper.php';

if (!class_exists('Bitrix\\Main\\Loader')) {
    final class NeutralInitReadOnlyLoaderStub
    {
        public static function includeModule(string $_moduleId): bool
        {
            return true;
        }
    }
    class_alias(NeutralInitReadOnlyLoaderStub::class, 'Bitrix\\Main\\Loader');
}
if (!class_exists('Prospektweb\\Frontcalc\\Service\\NeutralCalculationInputBuilder')) {
    final class NeutralInitReadOnlyInputBuilderStub
    {
        public function decorateOffers(
            array $offers,
            array $publishedAuthoring,
            int $_presetId,
            string $_source = 'manual'
        ): array {
            $bindings = [];
            foreach ($publishedAuthoring['bindingDefinition']['bindings'] ?? [] as $binding) {
                $bindings[(string)$binding['fieldId']] = (string)($binding['target']['propertyCode'] ?? '');
            }
            foreach ($offers as &$offer) {
                $values = [];
                foreach ($bindings as $fieldId => $propertyCode) {
                    $value = $offer['properties'][$propertyCode]['VALUE'] ?? null;
                    if ($fieldId === 'method') {
                        $value = strtolower((string)$value);
                    } elseif ($fieldId === 'format') {
                        preg_match_all('/[0-9]+/', (string)$value, $matches);
                        $value = ['width' => (int)$matches[0][0], 'height' => (int)$matches[0][1]];
                    } elseif ($fieldId === 'volume') {
                        $value = (int)$value;
                    } elseif ($fieldId === 'options') {
                        $value = array_values((array)$value);
                    }
                    $values[$fieldId] = $value;
                }
                $offer['calculationInput'] = [
                    'contract' => 'prospektweb.calc.input-context/v1',
                    'source' => 'manual',
                    'values' => $values,
                ];
            }
            unset($offer);
            return $offers;
        }
    }
    class_alias(
        NeutralInitReadOnlyInputBuilderStub::class,
        'Prospektweb\\Frontcalc\\Service\\NeutralCalculationInputBuilder'
    );
}
if (!class_exists('Prospektweb\\Frontcalc\\Service\\FormFirstAuthoringStore')) {
    final class NeutralInitReadOnlyAuthoringStoreStub
    {
    }
    class_alias(
        NeutralInitReadOnlyAuthoringStoreStub::class,
        'Prospektweb\\Frontcalc\\Service\\FormFirstAuthoringStore'
    );
}

use Prospektweb\Calc\Calculator\InitPayloadService;
use Prospektweb\Calc\Services\CatalogAdapterDefinitionService;
use Prospektweb\Calc\Services\StandaloneCatalogSelectionMapper;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$expectFailure = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $error) {
        $assert($error->getCode() === 409 || $error instanceof InvalidArgumentException, $message . ' fails closed');
        return;
    }
    $assert(false, $message);
};

$service = new InitPayloadService();
$reflection = new ReflectionClass($service);
$normalizeTargets = $reflection->getMethod('normalizeNeutralCatalogTargets');
$normalizeTargets->setAccessible(true);

$productIds = [12727, 12764, 14379, 14380, 15344];
$volumes = [100, 200, 500];
$offers = [];
$offerId = 20000;
foreach ($productIds as $productId) {
    foreach ($volumes as $volume) {
        foreach (['4+0', '4+4'] as $colorScheme) {
            $offers[] = [
                'id' => $offerId++,
                'productId' => $productId,
                'name' => $productId . ' / ' . $colorScheme . ' / ' . $volume,
                'properties' => [
                    'CALC_PROP_COLOR_SCHEME' => ['VALUE_XML_ID' => $colorScheme],
                    'CALC_PROP_VOLUME' => ['VALUE_XML_ID' => (string)$volume],
                ],
            ];
        }
    }
}
$requestedOfferIds = array_reverse(array_column($offers, 'id'));
$canonical = $normalizeTargets->invoke($service, $requestedOfferIds, $offers, $productIds, false);
$expectedOfferIds = array_column($offers, 'id');
sort($expectedOfferIds, SORT_NUMERIC);
$assert(count($canonical['offers']) === 30, 'the read-only resolver keeps the full 5x6 target matrix');
$assert($canonical['offerIds'] === $expectedOfferIds, 'offer IDs are canonical and deterministic');
$assert($canonical['productIds'] === $productIds, 'all five parent products survive canonical resolution');
$assert(array_column($canonical['offers'], 'id') === $expectedOfferIds, 'offer payload order matches launchContext.offerIds');

$adapterService = new CatalogAdapterDefinitionService([
    'semantic_values' => static function (array $mapped): array {
        $inputs = $mapped['calculationInputs'];
        preg_match_all('/[0-9]+/', (string)$inputs['CALC_PROP_FORMAT'], $matches);
        return [
            'method' => strtolower((string)$inputs['CALC_PROP_METHOD']),
            'paperType' => (string)$inputs['CALC_PROP_TYPE_PAPER'],
            'format' => ['width' => (int)$matches[0][0], 'height' => (int)$matches[0][1]],
            'paperDensity' => (string)$inputs['CALC_PROP_DENSITY_PAPER'],
            'filling' => (string)$inputs['CALC_PROP_FILLING'],
            'colorScheme' => (string)$inputs['CALC_PROP_COLOR_SCHEME'],
            'volume' => (int)$inputs['CALC_PROP_VOLUME'],
            'options' => array_values((array)($inputs['CALC_PROP_OPTIONS'] ?? [])),
        ];
    },
]);
$adapter = $adapterService->loadFromRaw(12740, '');
$formFields = [];
$bindings = [];
foreach ([
    'method' => 'CALC_PROP_METHOD',
    'paperType' => 'CALC_PROP_TYPE_PAPER',
    'format' => 'CALC_PROP_FORMAT',
    'paperDensity' => 'CALC_PROP_DENSITY_PAPER',
    'filling' => 'CALC_PROP_FILLING',
    'colorScheme' => 'CALC_PROP_COLOR_SCHEME',
    'volume' => 'CALC_PROP_VOLUME',
    'options' => 'CALC_PROP_OPTIONS',
] as $fieldId => $propertyCode) {
    $formFields[] = (object)['fieldId' => $fieldId];
    $bindings[] = (object)[
        'fieldId' => $fieldId,
        'target' => (object)['kind' => 'property', 'propertyCode' => $propertyCode],
    ];
}
$preview = $adapterService->previewMappings(
    $canonical['offers'],
    ['contract' => 'prospektweb.frontcalc.form-definition/v1', 'fields' => $formFields],
    ['contract' => 'prospektweb.frontcalc.binding-definition/v1', 'bindings' => $bindings],
    ['revision' => 1, 'compileHash' => str_repeat('a', 64)],
    $adapter
);
$assert(
    $preview['ready'] === true,
    'the system adapter maps the complete matrix before ACTIVE cutover: '
        . json_encode($preview['errors'] ?? [], JSON_UNESCAPED_UNICODE)
);
$assert(count($preview['scenarios']) === 30, 'the editor receives one semantic scenario per requested offer');
$assert(
    array_column(array_column($preview['scenarios'], 'target'), 'offerId') === $expectedOfferIds,
    'scenario targets use the same canonical offer order'
);

// A persisted adapter can be temporarily incomplete while an administrator is
// repairing it. INIT must keep every fixed-scope launch target visible, expose
// the missing profile as diagnostics and emit no scenario for that target.
$narrowDocument = $adapter;
unset($narrowDocument['revision']);
$narrowDocument['productProfiles'] = array_values(array_filter(
    $narrowDocument['productProfiles'],
    static fn(array $profile): bool => (int)$profile['productId'] === 12727
));
$narrowAdapter = $adapterService->normalizeCandidate(12740, $narrowDocument);
$narrowOffers = [$offers[0], $offers[6]];
$narrowOfferIds = array_column($narrowOffers, 'id');
$narrowTargets = $normalizeTargets->invoke(
    $service,
    $narrowOfferIds,
    $narrowOffers,
    StandaloneCatalogSelectionMapper::supportedProductIds(),
    false
);
$runtimeFields = [];
$runtimeBindings = [];
foreach ([
    'method' => 'CALC_PROP_METHOD',
    'paperType' => 'CALC_PROP_TYPE_PAPER',
    'format' => 'CALC_PROP_FORMAT',
    'paperDensity' => 'CALC_PROP_DENSITY_PAPER',
    'filling' => 'CALC_PROP_FILLING',
    'colorScheme' => 'CALC_PROP_COLOR_SCHEME',
    'volume' => 'CALC_PROP_VOLUME',
    'options' => 'CALC_PROP_OPTIONS',
] as $fieldId => $propertyCode) {
    $runtimeFields[] = ['fieldId' => $fieldId];
    $runtimeBindings[] = [
        'fieldId' => $fieldId,
        'target' => ['kind' => 'property', 'propertyCode' => $propertyCode],
    ];
}
$authoring = [
    'formDefinition' => [
        'contract' => 'prospektweb.frontcalc.form-definition/v1',
        'fields' => $runtimeFields,
    ],
    'bindingDefinition' => [
        'contract' => 'prospektweb.frontcalc.binding-definition/v1',
        'bindings' => $runtimeBindings,
    ],
    'publication' => ['revision' => 1, 'compileHash' => str_repeat('a', 64)],
];
$buildRuntime = $reflection->getMethod('buildEditorRuntime');
$buildRuntime->setAccessible(true);
$narrowRuntime = $buildRuntime->invoke(
    $service,
    12740,
    $narrowTargets['offers'],
    null,
    'catalog',
    $authoring,
    $narrowAdapter,
    true
);
$assert($narrowRuntime['launchContext']['productIds'] === [12727, 12764], 'incomplete adapter keeps both fixed-scope parents in launchContext');
$assert($narrowRuntime['launchContext']['offerIds'] === $narrowOfferIds, 'incomplete adapter keeps both exact offer IDs in launchContext');
$assert(count($narrowRuntime['catalogScenarios']) === 1, 'missing adapter profile cannot emit a calculation scenario');
$assert($narrowRuntime['catalogMapping']['ready'] === false, 'incomplete adapter remains blocked from calculation');
$assert(count($narrowRuntime['catalogMapping']['errors']) === 1
    && (int)$narrowRuntime['catalogMapping']['errors'][0]['offerId'] === $narrowOfferIds[1]
    && strpos((string)$narrowRuntime['catalogMapping']['errors'][0]['message'], 'не настроен профиль') !== false,
    'missing 12764 profile is returned as an actionable adapter error');

$expectFailure(
    static fn() => $normalizeTargets->invoke($service, $requestedOfferIds, array_slice($offers, 0, 29), $productIds, false),
    'a disappearing offer is never narrowed to 29 of 30'
);
$expectFailure(
    static fn() => $normalizeTargets->invoke($service, $requestedOfferIds, $offers, $productIds, true),
    'legacy product-form launch rejects mixed parent products'
);
$unsupported = $offers;
$unsupported[0]['productId'] = 99999;
$expectFailure(
    static fn() => $normalizeTargets->invoke($service, $requestedOfferIds, $unsupported, $productIds, false),
    'an unsupported parent product is rejected'
);

$normalizeIds = $reflection->getMethod('normalizeNeutralOfferIds');
$normalizeIds->setAccessible(true);
$expectFailure(
    static fn() => $normalizeIds->invoke($service, [20000, 20000]),
    'duplicate offer IDs are rejected rather than deduplicated'
);
$expectFailure(
    static fn() => $normalizeIds->invoke($service, [20000, '0']),
    'invalid offer IDs are rejected rather than filtered'
);

$root = dirname(__DIR__);
$initSource = file_get_contents($root . '/lib/Calculator/InitPayloadService.php');
$ajaxSource = file_get_contents($root . '/tools/calculator_ajax.php');
$integrationSource = file_get_contents($root . '/install/assets/js/integration.js');
$neutralStart = strpos($initSource, 'public function prepareNeutralInitPayloadReadOnly');
$neutralEnd = strpos($initSource, 'public function preparePresetCalculationPayloadReadOnlyPinned', $neutralStart);
$assert($neutralStart !== false && $neutralEnd !== false, 'dedicated neutral INIT method is present');
$neutralSource = substr($initSource, $neutralStart, $neutralEnd - $neutralStart);
foreach ([
    'SchemaRepairService',
    'CatalogPropertyCodeMigrationService',
    'PresetEnrichmentService',
    'createPreset(',
    'createAndAssignPreset',
    'ensureDefaultPresetDetail',
    'addNewDetail',
    'Option::set(',
    '->save(',
] as $mutationMarker) {
    $assert(strpos($neutralSource, $mutationMarker) === false, 'neutral INIT has no mutator marker ' . $mutationMarker);
}
$assert(strpos($neutralSource, 'captureRuntimeConfigSnapshotDirect') !== false, 'neutral INIT pins direct runtime configuration');
$assert(strpos($neutralSource, "runtimeIblockId('OFFERS')") !== false, 'neutral INIT restricts targets to configured OFFERS');
$assert(strpos($neutralSource, 'assertNeutralParentProductsReadOnly') !== false, 'neutral INIT revalidates every parent product');
$assert(strpos($neutralSource, 'StandaloneCatalogSelectionMapper::supportedProductIds()') !== false
    && strpos($neutralSource, "'CATALOG_ADAPTER_12740'") < strpos($neutralSource, 'normalizeNeutralCatalogTargets('),
    'authoring target support uses the fixed prepared-product allowlist');
$assert(strpos($initSource, "'ACTIVE_DATE' =") === false, 'guard against an accidental assignment typo in active filters');
$assert(strpos($initSource, "\$filter['ACTIVE_DATE'] = 'Y'") !== false, 'control-center catalog INIT requires active/date-valid targets');
$assert(strpos($initSource, "['CODE' => 'CALC_PRESET']") !== false, 'every parent preset link is reread server-side');

$handlerStart = strpos($ajaxSource, 'function handleGetInitData');
$handlerEnd = strpos($ajaxSource, 'function handlePreviewCatalogAdapter', $handlerStart);
$handlerSource = substr($ajaxSource, $handlerStart, $handlerEnd - $handlerStart);
$assert(strpos($handlerSource, 'parseStrictNeutralOfferIds') !== false, 'GET INIT uses exact ID parsing');
$assert(strpos($handlerSource, 'prepareNeutralInitPayloadReadOnly') !== false, 'GET INIT routes through the read-only neutral service');
$assert(strpos($handlerSource, 'prepareInitPayload(') === false, 'GET INIT cannot reach the legacy schema/preset mutator');
$assert(strpos($handlerSource, 'preparePresetPayload(') === false, 'GET INIT cannot reach legacy standalone enrichment');
$assert(strpos($handlerSource, '$presetId === \\Prospektweb\\Calc\\Services\\CatalogAdapterDefinitionService::PRESET_ID') !== false, 'explicit catalog launch conveys active/date authority');
$assert(strpos($ajaxSource, "function parseStrictNeutralOfferIds") !== false, 'strict raw parser is part of the endpoint contract');
$assert(strpos($integrationSource, 'initData && initData.editorRuntime') !== false
    && strpos($integrationSource, ': await this.ensureDefaultPresetDetail(initData)') !== false,
    'published editorRuntime skips implicit default-detail creation');

echo "Neutral INIT read-only tests passed\n";
