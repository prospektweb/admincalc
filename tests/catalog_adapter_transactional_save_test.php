<?php

require_once dirname(__DIR__) . '/lib/Services/CatalogAdapterDefinitionService.php';
require_once dirname(__DIR__) . '/lib/Services/CatalogCalculationWriteService.php';

use Prospektweb\Calc\Services\CatalogAdapterDefinitionService;
use Prospektweb\Calc\Services\CatalogCalculationWriteService;

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

// The production implementation is provided by FrontCalc. This narrow fake
// exercises the real adapter semantic boundary without bootstrapping Bitrix.
if (!class_exists('\\Prospektweb\\Frontcalc\\Service\\NeutralCalculationInputBuilder')) {
    eval(<<<'PHP'
namespace Prospektweb\Frontcalc\Service;
final class NeutralCalculationInputBuilder
{
    public function decorateOffers(array $offers, array $authoring, int $presetId): array
    {
        foreach ($offers as &$offer) {
            $values = [];
            foreach ((array)($authoring['bindingDefinition']['bindings'] ?? []) as $binding) {
                $fieldId = (string)($binding['fieldId'] ?? '');
                $code = (string)($binding['target']['propertyCode'] ?? '');
                if ($fieldId !== '' && $code !== '') {
                    $values[$fieldId] = $offer['properties'][$code]['VALUE'] ?? null;
                }
            }
            $offer['calculationInput'] = [
                'contract' => 'prospektweb.calc.input-context/v1',
                'source' => 'manual',
                'presetId' => $presetId,
                'values' => $values,
            ];
        }
        unset($offer);
        return $offers;
    }
}
PHP);
}

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
    $formFields[] = ['fieldId' => $fieldId];
    $bindings[] = [
        'fieldId' => $fieldId,
        'target' => ['kind' => 'property', 'propertyCode' => $propertyCode],
    ];
}
$authoring = [
    'formDefinition' => [
        'contract' => 'prospektweb.frontcalc.form-definition/v1',
        'fields' => $formFields,
    ],
    'bindingDefinition' => [
        'contract' => 'prospektweb.frontcalc.binding-definition/v1',
        'bindings' => $bindings,
    ],
    'publication' => ['revision' => 9, 'compileHash' => str_repeat('b', 64)],
];
$offer = [
    'id' => 15320,
    'iblockId' => 15,
    'productId' => 12727,
    'name' => 'Business cards 100 4+0',
    'properties' => [
        'CALC_PROP_COLOR_SCHEME' => ['VALUE_XML_ID' => '4+0'],
        'CALC_PROP_VOLUME' => ['VALUE_XML_ID' => '100'],
    ],
    'purchasingPrice' => 400.0,
    'purchasingCurrency' => 'RUB',
    'attributes' => ['width' => 90.0, 'length' => 50.0, 'height' => 2.0, 'weight' => 100.0],
    'prices' => [],
];
$runtimeConfig = static function (): array {
    $groups = [
        'prospektweb.calc' => [
            'CALC_SERVER_URL', 'PRODUCT_IBLOCK_ID', 'SKU_IBLOCK_ID', 'IBLOCK_CALC_PRESETS',
            'IBLOCK_CALC_STAGES', 'IBLOCK_CALC_SETTINGS', 'IBLOCK_CALC_GLOBAL_VALUES',
            'IBLOCK_CALC_CUSTOM_FIELDS', 'IBLOCK_CALC_MATERIALS', 'IBLOCK_CALC_MATERIALS_VARIANTS',
            'IBLOCK_CALC_OPERATIONS', 'IBLOCK_CALC_OPERATIONS_VARIANTS', 'IBLOCK_CALC_EQUIPMENT',
            'IBLOCK_CALC_DETAILS',
        ],
        'prospektweb.frontcalc' => [
            'PRODUCTS_IBLOCK_ID', 'OFFERS_IBLOCK_ID', 'IBLOCK_CALC_PRESETS', 'IBLOCK_CALC_STAGES',
            'IBLOCK_CALC_SETTINGS', 'IBLOCK_CALC_GLOBAL_VALUES', 'IBLOCK_CALC_CUSTOM_FIELDS',
            'IBLOCK_CALC_MATERIALS', 'IBLOCK_CALC_MATERIALS_VARIANTS', 'IBLOCK_CALC_OPERATIONS',
            'IBLOCK_CALC_OPERATIONS_VARIANTS', 'IBLOCK_CALC_EQUIPMENT', 'IBLOCK_CALC_DETAILS',
        ],
    ];
    $snapshot = [];
    foreach ($groups as $moduleId => $names) {
        foreach ($names as $name) {
            $snapshot[$moduleId . ':' . $name] = null;
        }
    }
    $snapshot['prospektweb.calc:PRODUCT_IBLOCK_ID'] = '14';
    $snapshot['prospektweb.calc:SKU_IBLOCK_ID'] = '15';
    $snapshot['prospektweb.calc:IBLOCK_CALC_PRESETS'] = '41';
    $snapshot['prospektweb.calc:IBLOCK_CALC_GLOBAL_VALUES'] = '60';
    $snapshot['prospektweb.calc:CALC_SERVER_URL'] = 'https://calc.example.test';
    ksort($snapshot, SORT_STRING);
    return $snapshot;
};

$adapterCodec = new CatalogAdapterDefinitionService();
$adapterRaw = '';
$neutralExists = true;
$neutralValue = 'N';
$events = [];
$transactionBackup = null;
$mutateNeutralOnOptionLock = false;
$failFreshResolve = false;
$persistenceStates = [];
$makePayload = static function (array $adapter, bool $adapterPersisted) use (
    $adapterCodec,
    $authoring,
    $offer,
    $runtimeConfig,
    &$neutralExists,
    &$neutralValue,
    &$persistenceStates
): array {
    $persistenceStates[] = $adapterPersisted;
    $preview = $adapterCodec->previewMappings(
        [$offer],
        $authoring['formDefinition'],
        $authoring['bindingDefinition'],
        $authoring['publication'],
        $adapter
    );
    return [
        'presetId' => 12740,
        'selectedOffers' => [$offer],
        'priceTypes' => [],
        'editorRuntime' => [
            'contract' => CatalogAdapterDefinitionService::EDITOR_RUNTIME_CONTRACT,
            'launchContext' => [
                'contract' => CatalogAdapterDefinitionService::LAUNCH_CONTEXT_CONTRACT,
                'mode' => 'catalog',
                'presetId' => 12740,
                'productIds' => [12727],
                'offerIds' => [15320],
            ],
            'formDefinition' => $authoring['formDefinition'],
            'bindingDefinition' => $authoring['bindingDefinition'],
            'publication' => $authoring['publication'],
            'catalogAdapter' => $adapter,
            'catalogScenarios' => $preview['scenarios'],
            'catalogMapping' => [
                'adapterPersisted' => $adapterPersisted,
                'ready' => $preview['ready'],
                'hasTargets' => $preview['hasTargets'],
                'adapterRevision' => $preview['adapterRevision'],
                'errors' => $preview['errors'],
            ],
        ],
        '_publishedSnapshot' => [
            '_form_first' => [
                'publishedRevision' => $authoring['publication']['revision'],
                'compileHash' => $authoring['publication']['compileHash'],
            ],
        ],
        '_neutralInputRequired' => $neutralExists && $neutralValue === 'Y',
        '_globalSymbols' => [],
        '_globalSymbolIblockId' => 60,
        '_productIblockIds' => [12727 => 14],
        '_runtimeConfigSnapshot' => $runtimeConfig(),
    ];
};

$service = new CatalogCalculationWriteService([
    'capture_runtime_config' => $runtimeConfig,
    'capture_neutral_input_state' => static function () use (&$neutralExists, &$neutralValue): string {
        return $neutralExists ? $neutralValue : 'MISSING';
    },
    'resolve_runtime_candidate' => static function (
        array $offerIds,
        string $siteId,
        array $adapter
    ) use ($makePayload, &$adapterRaw, &$events): array {
        if ($offerIds !== [15320] || $siteId !== 's1') {
            throw new RuntimeException('Unexpected adapter candidate targets');
        }
        $events[] = 'preflight';
        return $makePayload($adapter, $adapterRaw !== '');
    },
    'resolve_runtime_pinned' => static function (
        array $offerIds,
        string $siteId,
        ?array $adapterOverride
    ) use ($makePayload, $adapterCodec, &$adapterRaw, &$events, &$failFreshResolve): array {
        if ($offerIds !== [15320] || $siteId !== 's1') {
            throw new RuntimeException('Unexpected locked adapter targets');
        }
        if ($adapterOverride === null && $failFreshResolve) {
            $events[] = 'fresh-failure';
            throw new RuntimeException('Synthetic fresh resolver failure');
        }
        $events[] = $adapterOverride === null ? 'fresh' : 'locked-preview';
        return $makePayload(
            $adapterOverride ?? $adapterCodec->loadFromRaw(12740, $adapterRaw),
            $adapterRaw !== ''
        );
    },
    'adapter_mutation_lock' => static function (callable $callback) use (&$events) {
        $events[] = 'adapter-lock';
        return $callback();
    },
    'begin_transaction' => static function () use (
        &$events,
        &$transactionBackup,
        &$adapterRaw,
        &$neutralExists,
        &$neutralValue
    ): void {
        $events[] = 'begin';
        $transactionBackup = [$adapterRaw, $neutralExists, $neutralValue];
    },
    'lock_catalog_rows' => static function (array $offerIds, array $productIds) use (&$events): void {
        if ($offerIds !== [15320] || $productIds !== [12727]) {
            throw new RuntimeException('Unexpected catalog lock set');
        }
        $events[] = 'catalog-lock';
    },
    'lock_runtime_options' => static function () use (
        &$events,
        &$mutateNeutralOnOptionLock,
        &$neutralExists,
        &$neutralValue
    ): void {
        $events[] = 'option-lock';
        if ($mutateNeutralOnOptionLock) {
            $neutralExists = true;
            $neutralValue = 'Y';
        }
    },
    'read_locked_option_state' => static function (string $moduleId, string $name) use (
        &$adapterRaw,
        &$neutralExists,
        &$neutralValue
    ): array {
        if ($moduleId === 'prospektweb.calc' && $name === 'CATALOG_ADAPTER_12740') {
            return ['exists' => $adapterRaw !== '', 'value' => $adapterRaw];
        }
        if ($moduleId === 'prospektweb.calc' && $name === 'PRESET_12740_NEUTRAL_INPUT_ACTIVE') {
            return ['exists' => $neutralExists, 'value' => $neutralValue];
        }
        return ['exists' => false, 'value' => ''];
    },
    'write_locked_adapter_raw' => static function (string $raw) use (&$adapterRaw, &$events): void {
        $events[] = 'write-adapter';
        $adapterRaw = $raw;
    },
    'commit_transaction' => static function () use (&$events, &$transactionBackup): void {
        $events[] = 'commit';
        $transactionBackup = null;
    },
    'rollback_transaction' => static function () use (
        &$events,
        &$transactionBackup,
        &$adapterRaw,
        &$neutralExists,
        &$neutralValue
    ): void {
        $events[] = 'rollback';
        if (is_array($transactionBackup)) {
            [$adapterRaw, $neutralExists, $neutralValue] = $transactionBackup;
        }
        $transactionBackup = null;
    },
]);

$changeFormat = static function (array $definition, string $format): array {
    unset($definition['revision']);
    foreach ($definition['productProfiles'] as &$profile) {
        if ((int)$profile['productId'] === 12727) {
            $profile['overrides'] = [[
                'targetPath' => 'calculation.inputs.CALC_PROP_FORMAT',
                'value' => $format,
            ]];
        }
    }
    unset($profile);
    return $definition;
};

$initial = $adapterCodec->loadFromRaw(12740, '');
$savedDefault = $service->saveValidatedAdapter(
    12740,
    [15320],
    's1',
    $initial['revision'],
    $initial
);
$assert($adapterRaw !== '', 'an unchanged system template is persisted on the first explicit save');
$assert($savedDefault['catalogAdapter']['revision'] === $initial['revision'], 'first save preserves the canonical system-template revision');
$assert(($savedDefault['editorRuntime']['catalogMapping']['adapterPersisted'] ?? null) === true, 'first save response confirms exact persisted adapter state');
$assert($persistenceStates === [false, false, true], 'absent adapter stays explicit through preflight and becomes persisted only after locked readback');
$assert($events === [
    'preflight', 'adapter-lock', 'begin', 'option-lock', 'catalog-lock',
    'locked-preview', 'write-adapter', 'fresh', 'commit',
], 'unchanged first save uses the complete transactional validation path');

$events = [];
$persistenceStates = [];
$savedN = $service->saveValidatedAdapter(
    12740,
    [15320],
    's1',
    $initial['revision'],
    $changeFormat($initial, '90x51')
);
$assert($savedN['catalogAdapter']['revision'] !== $initial['revision'], 'adapter saves while neutral cutover is explicitly N');
$assert(($savedN['initData']['_neutralInputRequired'] ?? null) === false, 'inactive neutral state remains explicit in the validated INIT');
$assert(($savedN['editorRuntime']['catalogMapping']['adapterPersisted'] ?? null) === true, 'save response confirms exact persisted adapter state');
$assert($persistenceStates === [true, true, true], 'subsequent saves preserve persisted authority through every resolver pass');
$assert($events === [
    'preflight', 'adapter-lock', 'begin', 'option-lock', 'catalog-lock',
    'locked-preview', 'write-adapter', 'fresh', 'commit',
], 'candidate preview, option write and fresh readback share one catalog/options transaction');

$rawAfterN = $adapterRaw;
$events = [];
$expectFailure(
    static function () use ($service, $initial, $changeFormat): void {
        $service->saveValidatedAdapter(12740, [15320], 's1', $initial['revision'], $changeFormat($initial, '90x52'));
    },
    'stale adapter CAS is rejected inside the validated transaction',
    409
);
$assert($adapterRaw === $rawAfterN && !in_array('write-adapter', $events, true), 'stale CAS cannot mutate the adapter row');

$neutralExists = false;
$neutralValue = '';
$current = $adapterCodec->loadFromRaw(12740, $adapterRaw);
$events = [];
$savedMissing = $service->saveValidatedAdapter(
    12740,
    [15320],
    's1',
    $current['revision'],
    $changeFormat($current, '90x52')
);
$assert(($savedMissing['initData']['_neutralInputRequired'] ?? null) === false, 'missing legacy activation row does not deadlock adapter authoring');
$rawAfterMissing = $adapterRaw;

$current = $adapterCodec->loadFromRaw(12740, $adapterRaw);
$mutateNeutralOnOptionLock = true;
$events = [];
$expectFailure(
    static function () use ($service, $current, $changeFormat): void {
        $service->saveValidatedAdapter(12740, [15320], 's1', $current['revision'], $changeFormat($current, '90x53'));
    },
    'concurrent activation insertion invalidates adapter save',
    409
);
$mutateNeutralOnOptionLock = false;
$assert($adapterRaw === $rawAfterMissing && !in_array('write-adapter', $events, true), 'activation drift aborts before adapter mutation');
$assert($neutralExists === false, 'rollback restores the missing activation-row state in the transaction fake');

$neutralExists = true;
$neutralValue = 'N';
$current = $adapterCodec->loadFromRaw(12740, $adapterRaw);
$failFreshResolve = true;
$events = [];
$expectFailure(
    static function () use ($service, $current, $changeFormat): void {
        $service->saveValidatedAdapter(12740, [15320], 's1', $current['revision'], $changeFormat($current, '90x54'));
    },
    'fresh pinned validation failure rolls the adapter write back'
);
$failFreshResolve = false;
$assert($adapterRaw === $rawAfterMissing, 'post-write validation failure cannot commit an incompatible adapter');
$assert(in_array('write-adapter', $events, true) && end($events) === 'rollback', 'post-write failure rolls back in the same transaction');

echo "Catalog adapter transactional save tests passed\n";
