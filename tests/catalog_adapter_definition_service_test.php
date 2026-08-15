<?php

require_once dirname(__DIR__) . '/lib/Services/CatalogAdapterDefinitionService.php';
require_once dirname(__DIR__) . '/lib/Services/StandaloneCatalogSelectionMapper.php';

use Prospektweb\Calc\Services\CatalogAdapterDefinitionService;
use Prospektweb\Calc\Services\StandaloneCatalogSelectionMapper;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$expectFailure = static function (callable $callback, string $message, ?int $code = null) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $error) {
        if ($code !== null) {
            $assert($error->getCode() === $code, $message . ' has the expected error code');
        }
        return;
    }
    $assert(false, $message);
};

$storedRaw = '';
$semanticValues = static function (
    array $mapped,
    array $formDefinition,
    array $bindingDefinition,
    array $publication
): array {
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
};
$service = new CatalogAdapterDefinitionService([
    'get_option' => static function (string $_name, string $default) use (&$storedRaw): string {
        return $storedRaw !== '' ? $storedRaw : $default;
    },
    'set_option' => static function (string $_name, string $raw) use (&$storedRaw): void {
        $storedRaw = $raw;
    },
    'mutation_lock' => static function (int $_presetId, callable $callback) {
        return $callback();
    },
    'semantic_values' => $semanticValues,
]);

$definition = $service->load(12740);
$assert($definition['contract'] === 'prospektweb.calc.catalog-adapter/v1', 'default uses the versioned adapter contract');
$assert(preg_match('/^[a-f0-9]{64}$/D', $definition['revision']) === 1, 'default has a content-addressed revision');
$assert($service->supportedProductIds($definition) === [12727, 12764, 14379, 14380, 15344], 'default owns exactly the five prepared products');

$offer = [
    'id' => 15326,
    'name' => 'Rounded 4+4 / 500',
    'productId' => 12764,
    'properties' => [
        'CALC_PROP_COLOR_SCHEME' => ['VALUE_XML_ID' => '4+4'],
        'CALC_PROP_VOLUME' => ['VALUE_XML_ID' => '500'],
    ],
];
$mapped = $service->mapOffer($offer, $definition);
$assert($mapped['quantity'] === 500, 'offer volume remains the target quantity');
$assert($mapped['calculationInputs'] === [
    'CALC_PROP_COLOR_SCHEME' => '4+4',
    'CALC_PROP_DENSITY_PAPER' => 'MAX',
    'CALC_PROP_FILLING' => 'standart',
    'CALC_PROP_FORMAT' => '90x50',
    'CALC_PROP_METHOD' => 'DIGITAL',
    'CALC_PROP_TYPE_PAPER' => 'mel-paper',
    'CALC_PROP_VOLUME' => '500',
    'CALC_PROP_OPTIONS' => ['round-corners'],
], 'default adapter reproduces the prepared rounded-card selection');

$optionalDefinition = $definition;
unset($optionalDefinition['revision']);
$optionalDefinition['inputMappings'][] = [
    'sourcePath' => 'literal',
    'targetPath' => 'calculation.inputs.CALC_PROP_PROTECTION',
    'value' => 'lamination-rulon',
];
$optionalDefinition['inputMappings'][] = [
    'sourcePath' => 'literal',
    'targetPath' => 'calculation.inputs.CALC_PROP_LAMINATION',
    'value' => 'gloss-low',
];
$optionalDefinition['inputMappings'][] = [
    'sourcePath' => 'literal',
    'targetPath' => 'calculation.inputs.CALC_PROP_LAMINATION_SIDES',
    'value' => '2',
];
$optionalMapped = $service->mapOffer($offer, $optionalDefinition);
$assert(
    array_intersect_key($optionalMapped['calculationInputs'], array_flip([
        'CALC_PROP_PROTECTION',
        'CALC_PROP_LAMINATION',
        'CALC_PROP_LAMINATION_SIDES',
    ])) === [
        'CALC_PROP_LAMINATION' => 'gloss-low',
        'CALC_PROP_LAMINATION_SIDES' => '2',
        'CALC_PROP_PROTECTION' => 'lamination-rulon',
    ],
    'adapter can explicitly map all three optional published form fields'
);

$compatibility = (new StandaloneCatalogSelectionMapper($service))->map($offer);
$assert($compatibility['quantity'] === 500, 'legacy mapper quantity delegates to the adapter');
$assert(!isset($compatibility['selection']['CALC_PROP_VOLUME']), 'legacy mapper keeps volume outside its compatibility selection');
$assert($compatibility['selection']['CALC_PROP_OPTIONS'] === ['round-corners'], 'legacy mapper has no separate product-profile logic');

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
    // stdClass rows reproduce the canonical FrontCalc store's JSON boundary.
    $formFields[] = (object)['fieldId' => $fieldId];
    $bindings[] = (object)[
        'fieldId' => $fieldId,
        'target' => (object)['kind' => 'property', 'propertyCode' => $propertyCode],
    ];
}
$formDefinition = [
    'contract' => 'prospektweb.frontcalc.form-definition/v1',
    'fields' => $formFields,
];
$bindingDefinition = [
    'contract' => 'prospektweb.frontcalc.binding-definition/v1',
    'bindings' => $bindings,
];
$publication = ['revision' => 7, 'compileHash' => str_repeat('a', 64)];
$scenario = $service->buildScenario($offer, $formDefinition, $bindingDefinition, $publication, $definition);
$assert($scenario['contract'] === 'prospektweb.calc.catalog-scenario/v1', 'scenario uses the versioned neutral contract');
$assert($scenario['target'] === [
    'productId' => 12764,
    'offerId' => 15326,
    'name' => 'Rounded 4+4 / 500',
], 'catalog identity remains only the scenario target envelope');
$assert($scenario['values']['volume'] === 500, 'scenario exposes numeric FormValues');
$assert($scenario['values']['format'] === ['width' => 90, 'height' => 50], 'scenario exposes semantic dimensions rather than a CALC_PROP token');
$assert($scenario['publicationCompileHash'] === str_repeat('a', 64), 'scenario pins the exact published compile hash');
foreach (array_keys($scenario['values']) as $fieldId) {
    $assert(strpos($fieldId, 'CALC_PROP_') !== 0, 'scenario values are keyed only by semantic fieldId');
}

$preview = $service->previewMappings([$offer], $formDefinition, $bindingDefinition, $publication, $definition);
$assert($preview['ready'] === true && $preview['hasTargets'] === true && count($preview['scenarios']) === 1, 'dry-run validates all selected targets');
$emptyPreview = $service->previewMappings([], $formDefinition, $bindingDefinition, $publication, $definition);
$assert($emptyPreview['ready'] === true && $emptyPreview['hasTargets'] === false, 'standalone dry-run validates a definition without requiring catalog targets');
$missingBinding = $bindingDefinition;
array_pop($missingBinding['bindings']);
$expectFailure(
    static function () use ($service, $formDefinition, $missingBinding, $publication, $definition): void {
        $service->previewMappings([], $formDefinition, $missingBinding, $publication, $definition);
    },
    'standalone preview fails closed when an adapter input has no semantic binding'
);

$changed = $definition;
foreach ($changed['productProfiles'] as &$profile) {
    if ($profile['productId'] === 15344) {
        $profile['overrides'][0]['value'] = '85x54';
    }
}
unset($profile);
$saved = $service->save(12740, $definition['revision'], $changed);
$assert($saved['revision'] !== $definition['revision'] && $storedRaw !== '', 'CAS save persists and changes the content revision');
$expectFailure(
    static function () use ($service, $definition, $changed): void {
        $service->save(12740, $definition['revision'], $changed);
    },
    'a stale CAS save is rejected',
    409
);
$expectFailure(
    static function () use ($service, $definition): void {
        $service->projectResultsForWrite([], [], $definition);
    },
    'results from a stale adapter revision cannot reach the writer',
    409
);
$assert(
    $service->projectPinnedResultsForWrite([], [], $definition) === [],
    'raw-pinned writer projection does not consult a stale process Option cache'
);

$badInput = $saved;
$badInput['inputMappings'][0]['sourcePath'] = 'catalog.offer.arbitrary';
$expectFailure(
    static function () use ($service, $badInput): void {
        $service->supportedProductIds($badInput);
    },
    'arbitrary input source paths are rejected'
);
$badOutput = $saved;
$badOutput['outputMappings'][0]['targetPath'] = 'catalog.offer.arbitrary';
$expectFailure(
    static function () use ($service, $badOutput): void {
        $service->supportedProductIds($badOutput);
    },
    'arbitrary output write paths are rejected'
);
$unknownRoot = $saved;
$unknownRoot['writeAnything'] = true;
$expectFailure(
    static function () use ($service, $unknownRoot): void {
        $service->supportedProductIds($unknownRoot);
    },
    'unknown adapter fields are rejected'
);

$projected = $service->projectResultsForWrite([[
    'offerId' => 15326,
    'offerName' => 'must not be written',
    'parametrValues' => [['name' => 'must not', 'value' => 'write']],
    'purchasePrice' => 410.25,
    'currency' => 'rub',
    'priceRangesWithMarkup' => [[
        'quantityFrom' => 1,
        'quantityTo' => null,
        'arbitraryRangeField' => true,
        'prices' => [
            ['typeId' => 1, 'basePrice' => 530, 'currency' => 'RUB', 'arbitraryPriceField' => true],
            ['typeId' => 999, 'basePrice' => 1, 'currency' => 'RUB'],
        ],
    ]],
    'details' => [[
        'outputs' => ['weight' => 120, 'length' => 90, 'width' => 50, 'height' => 3, 'arbitrary' => 999],
        'arbitraryDetailField' => true,
    ]],
    'arbitraryRootField' => true,
]], [['id' => 1]], $saved, $publication);
$row = $projected[0];
$assert(!isset($row['offerName'], $row['parametrValues'], $row['arbitraryRootField']), 'output policy strips non-whitelisted root writes');
$assert($row['currency'] === 'RUB' && $row['purchasePrice'] === 410.25, 'purchasing price survives the output policy');
$assert(count($row['priceRangesWithMarkup'][0]['prices']) === 1, 'price type IDs are intersected with the server catalog');
$assert(!isset($row['priceRangesWithMarkup'][0]['prices'][0]['arbitraryPriceField']), 'price rows are projected to exact allowed fields');
$assert($row['details'][0]['outputs'] === ['weight' => 120, 'length' => 90, 'width' => 50, 'height' => 3], 'only whitelisted dimensions survive');
$assert($row['catalogAdapterProvenance']['revision'] === $saved['revision'], 'persisted history receives the exact adapter provenance');
$assert($row['catalogAdapterProvenance']['publicationCompileHash'] === str_repeat('a', 64), 'persisted history pins the exact form publication');

echo "Catalog adapter definition service tests passed\n";
