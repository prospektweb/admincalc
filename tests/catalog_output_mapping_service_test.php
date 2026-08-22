<?php

require_once dirname(__DIR__) . '/lib/Services/CatalogOutputMappingService.php';

use Prospektweb\Calc\Services\CatalogOutputMappingService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (callable $callback, string $messagePart) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $error) {
        $assert(strpos($error->getMessage(), $messagePart) !== false, 'Unexpected error: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected exception containing: ' . $messagePart);
};

$storage = [];
$service = new CatalogOutputMappingService([
    'get_option' => static function (string $name, string $default) use (&$storage): string {
        return $storage[$name] ?? $default;
    },
    'set_option' => static function (string $name, string $raw) use (&$storage): void {
        $storage[$name] = $raw;
    },
    'mutation_lock' => static fn(int $presetId, callable $callback) => $callback(),
]);

$initial = $service->load(23);
$assert($initial['contract'] === CatalogOutputMappingService::CONTRACT, 'contract is exact');
$assert($initial['preset_id'] === 23 && $initial['revision'] === 0, 'default is generic and unsaved');
$assert(count($initial['mappings']) === 7, 'all safe output pairs are explicit');
$assert(!array_key_exists('inputMappings', $initial) && !array_key_exists('productProfiles', $initial), 'input and product profiles are absent');

$saved = $service->save(23, 0, $initial);
$assert($saved['revision'] === 1, 'save increments integer CAS');
$assert($service->load(23) === $saved, 'saved mapping round-trips');
$throws(static fn() => $service->save(23, 0, $initial), 'другой сессии');

$missing = $saved;
array_pop($missing['mappings']);
$throws(static fn() => $service->validate(23, $missing), 'все разрешённые пары');
$foreign = $saved;
$foreign['preset_id'] = 24;
$throws(static fn() => $service->validate(23, $foreign), 'не совпадает');
$legacy = $saved;
$legacy['productProfiles'] = [];
$throws(static fn() => $service->validate(23, $legacy), 'неизвестные или отсутствующие');

$projected = $service->projectPinnedResultsForWrite(
    23,
    [[
        'offerId' => 77,
        'currency' => 'RUB',
        'purchasePrice' => 120.5,
        'priceRangesWithMarkup' => [[
            'quantityFrom' => 1,
            'quantityTo' => null,
            'prices' => [['typeId' => 4, 'basePrice' => 180, 'currency' => 'RUB']],
        ]],
        'details' => [['outputs' => ['weight' => 1.2, 'length' => 3, 'width' => 4, 'height' => 5]]],
    ]],
    [['id' => 4]],
    $saved,
    ['revision' => 2, 'compileHash' => str_repeat('a', 64)]
);
$assert(($projected[0]['purchasePrice'] ?? null) === 120.5, 'purchase price is projected');
$assert(($projected[0]['details'][0]['outputs']['height'] ?? null) === 5, 'dimensions are projected');
$assert(($projected[0]['catalogOutputMappingProvenance']['preset_id'] ?? null) === 23, 'provenance is preset-owned');
$assert(($projected[0]['catalogOutputMappingProvenance']['revision'] ?? null) === 1, 'provenance pins integer output revision');

$throws(
    static fn() => $service->projectPinnedResultsForWrite(24, [], [], $service->load(24)),
    'не настроена'
);

$source = (string)file_get_contents(dirname(__DIR__) . '/lib/Services/CatalogOutputMappingService.php');
$assert(
    strpos($source, 'new PresetMutationCoordinatorService()') !== false
        && strpos($source, "'action' => 'catalog_output_mapping_save'") !== false
        && strpos($source, 'saveDirectUnderCoordinatorTransaction(') !== false
        && strpos($source, 'startTransaction()') === false,
    'production output mapping CAS and readback are audited inside the shared preset transaction'
);

echo "Catalog output mapping service tests passed\n";
