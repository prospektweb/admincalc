<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Fuser;

const STOP_STATISTICS = true;
const NO_KEEP_STATISTIC = true;
const NO_AGENT_STATISTIC = true;
const NO_AGENT_CHECK = true;
const PUBLIC_AJAX_MODE = true;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: application/json; charset=UTF-8');

$moduleId = 'prospektweb.calc';

/**
 * @return int[]
 */
function frontcalc_get_current_user_groups(): array
{
    global $USER;
    if (is_object($USER) && method_exists($USER, 'GetUserGroupArray')) {
        $groups = $USER->GetUserGroupArray();
        if (is_array($groups)) {
            return array_values(array_unique(array_map('intval', $groups)));
        }
    }

    return [2];
}

/**
 * @param int[] $userGroups
 * @return array{view:int[],buy:int[]}
 */
function frontcalc_get_catalog_groups_by_rights(array $userGroups): array
{
    $view = [];
    $buy = [];

    if (class_exists('CCatalogGroup2Group')) {
        $groupRes = CCatalogGroup::GetList(['SORT' => 'ASC'], []);
        while ($group = $groupRes->Fetch()) {
            $catalogGroupId = (int)($group['ID'] ?? 0);
            if ($catalogGroupId <= 0) {
                continue;
            }

            $accessRes = CCatalogGroup2Group::GetList(['GROUP_ID' => 'ASC'], ['CATALOG_GROUP_ID' => $catalogGroupId]);
            while ($access = $accessRes->Fetch()) {
                $groupId = (int)($access['GROUP_ID'] ?? 0);
                if ($groupId <= 0 || !in_array($groupId, $userGroups, true)) {
                    continue;
                }
                if (($access['BUY'] ?? 'N') === 'Y') {
                    $buy[$catalogGroupId] = $catalogGroupId;
                }
                if (($access['LIST'] ?? 'N') === 'Y' || ($access['VIEW'] ?? 'N') === 'Y') {
                    $view[$catalogGroupId] = $catalogGroupId;
                }
            }
        }
    } else {
        global $DB;
        if (is_object($DB) && !empty($userGroups)) {
            $groupIdsSql = implode(',', array_map('intval', $userGroups));
            $sql = "SELECT CATALOG_GROUP_ID, BUY FROM b_catalog_group2group WHERE GROUP_ID IN (" . $groupIdsSql . ")";
            $res = $DB->Query($sql);
            while ($row = $res->Fetch()) {
                $catalogGroupId = (int)($row['CATALOG_GROUP_ID'] ?? 0);
                if ($catalogGroupId <= 0) {
                    continue;
                }
                $view[$catalogGroupId] = $catalogGroupId;
                if (($row['BUY'] ?? 'N') === 'Y') {
                    $buy[$catalogGroupId] = $catalogGroupId;
                }
            }
        }
    }

    return [
        'view' => array_values($view),
        'buy' => array_values($buy),
    ];
}


/**
 * Price range payload contract used by ajax/frontcalc.php and assets/js/frontcalc-jqm-popup.js.
 *
 * Each range is intentionally limited to these fields:
 * - catalog_group_id: Bitrix catalog price type ID;
 * - catalog_group_name: localized catalog price type name;
 * - price: rounded numeric price value;
 * - currency: Bitrix currency code;
 * - formatted: formatted price string;
 * - quantity_from: inclusive lower quantity bound, or null for an open lower bound;
 * - quantity_to: inclusive upper quantity bound, or null for an open upper bound.
 *
 * @phpstan-type FrontcalcPriceRange array{catalog_group_id:int,catalog_group_name:string,price:float,currency:string,formatted:string,quantity_from:int|null,quantity_to:int|null}
 */

/**
 * @param mixed $value
 */
function frontcalc_normalize_quantity_bound($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return (int)$value;
}

/**
 * @return FrontcalcPriceRange
 */
function frontcalc_make_price_range(
    int $catalogGroupId,
    string $catalogGroupName,
    float $price,
    string $currency,
    string $formatted,
    ?int $quantityFrom,
    ?int $quantityTo
): array
{
    return [
        'catalog_group_id' => $catalogGroupId,
        'catalog_group_name' => $catalogGroupName,
        'price' => $price,
        'currency' => $currency,
        'formatted' => $formatted,
        'quantity_from' => $quantityFrom,
        'quantity_to' => $quantityTo,
    ];
}

/**
 * @param array<int,FrontcalcPriceRange> $rows
 * @return array<string,array{catalog_group_id:int,catalog_group_name:string,prices:array<int,FrontcalcPriceRange>}>
 */

/**
 * @param array<int,array<string,mixed>> $ranges
 * @return array<int,array<string,mixed>>
 */

/**
 * @param array<int,array<string,mixed>> $diagnostics
 * @return array<int,array<string,mixed>>
 */
function frontcalc_dedupe_preset_price_diagnostics(array $diagnostics): array
{
    $seen = [];
    $result = [];
    foreach ($diagnostics as $diagnostic) {
        if (!is_array($diagnostic)) {
            continue;
        }
        ksort($diagnostic);
        $key = json_encode($diagnostic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($key === false || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $result[] = $diagnostic;
    }
    return $result;
}

function frontcalc_write_preset_price_diagnostics_log(int $productId, array $diagnostics, int $affectedOffers): void
{
    $diagnostics = frontcalc_dedupe_preset_price_diagnostics($diagnostics);
    if (empty($diagnostics) || !class_exists('CEventLog')) {
        return;
    }

    CEventLog::Add([
        'SEVERITY' => 'WARNING',
        'AUDIT_TYPE_ID' => 'FRONTCALC_PRESET_PRICE_DIAGNOSTICS',
        'MODULE_ID' => 'prospektweb.calc',
        'ITEM_ID' => (string)$productId,
        'DESCRIPTION' => json_encode([
            'productId' => $productId,
            'warnings' => $diagnostics,
            'affectedOffers' => $affectedOffers,
        ], JSON_UNESCAPED_UNICODE),
    ]);
}


function frontcalc_write_calc_server_batch_diagnostics_log(int $productId, array $diagnostics): void
{
    if (empty($diagnostics) || !class_exists('CEventLog')) {
        return;
    }

    $byBatch = [];
    foreach ($diagnostics as $diagnostic) {
        if (!is_array($diagnostic)) { continue; }
        $batchKey = (string)($diagnostic['batch_index'] ?? count($byBatch));
        $byBatch[$batchKey] = array_merge($byBatch[$batchKey] ?? [], $diagnostic);
    }
    foreach ($byBatch as $diagnostic) {
        $type = (string)($diagnostic['type'] ?? 'batch');
        $auditType = $type === 'partial'
            ? 'FRONTCALC_CALC_SERVER_BATCH_VALIDATION'
            : 'FRONTCALC_CALC_SERVER_BATCH';

        CEventLog::Add([
            'SEVERITY' => 'WARNING',
            'AUDIT_TYPE_ID' => $auditType,
            'MODULE_ID' => 'prospektweb.calc',
            'ITEM_ID' => (string)$productId,
            'DESCRIPTION' => json_encode(array_merge(['product_id' => $productId], $diagnostic), JSON_UNESCAPED_UNICODE),
        ]);
    }
}

function frontcalc_filter_canonical_ranges_by_access(array $ranges, array $allowedTypeIds): array
{
    return array_values(array_filter($ranges, static function ($row) use ($allowedTypeIds) {
        return in_array((int)($row['typeId'] ?? 0), $allowedTypeIds, true);
    }));
}

/**
 * @param array<int,array<string,mixed>> $ranges
 * @return array<int,FrontcalcPriceRange>
 */
function frontcalc_canonical_ranges_to_legacy(array $ranges): array
{
    $legacy = [];
    foreach ($ranges as $row) {
        $legacy[] = frontcalc_make_price_range(
            (int)($row['typeId'] ?? 0),
            (string)($row['typeName'] ?? ('PRICE_' . (int)($row['typeId'] ?? 0))),
            (float)($row['price'] ?? 0),
            (string)($row['currency'] ?? ''),
            (string)($row['formatted'] ?? ''),
            isset($row['quantityFrom']) ? (int)$row['quantityFrom'] : null,
            array_key_exists('quantityTo', $row) && $row['quantityTo'] !== null ? (int)$row['quantityTo'] : null
        );
    }
    return $legacy;
}

/**
 * @param array<int,FrontcalcPriceRange> $ranges
 * @return array<int,array<string,mixed>>
 */
function frontcalc_legacy_ranges_to_canonical(array $ranges): array
{
    $canonical = [];
    foreach ($ranges as $row) {
        $canonical[] = [
            'typeId' => (int)($row['catalog_group_id'] ?? 0),
            'typeName' => (string)($row['catalog_group_name'] ?? ('PRICE_' . (int)($row['catalog_group_id'] ?? 0))),
            'quantityFrom' => array_key_exists('quantity_from', $row) && $row['quantity_from'] !== null ? (int)$row['quantity_from'] : 0,
            'quantityTo' => array_key_exists('quantity_to', $row) && $row['quantity_to'] !== null ? (int)$row['quantity_to'] : null,
            'price' => (float)($row['price'] ?? 0),
            'currency' => (string)($row['currency'] ?? ''),
            'formatted' => (string)($row['formatted'] ?? ''),
        ];
    }
    return $canonical;
}

function frontcalc_build_catalog_aliases_from_legacy_ranges(array $viewAll, array $buyAll, int $quantity): array
{
    $pricesViewByGroup = [];
    foreach ($viewAll as $row) {
        $groupId = (int)($row['catalog_group_id'] ?? 0);
        if ($groupId > 0) {
            $pricesViewByGroup[$groupId][] = $row;
        }
    }
    $pricesView = [];
    foreach ($pricesViewByGroup as $groupRows) {
        $picked = frontcalc_pick_price_for_quantity($groupRows, $quantity);
        if ($picked !== null) {
            $pricesView[] = $picked;
        }
    }

    $pricesBuyByGroup = [];
    foreach ($buyAll as $row) {
        $groupId = (int)($row['catalog_group_id'] ?? 0);
        if ($groupId > 0) {
            $pricesBuyByGroup[$groupId][] = $row;
        }
    }
    $pricesBuy = [];
    foreach ($pricesBuyByGroup as $groupRows) {
        $picked = frontcalc_pick_price_for_quantity($groupRows, $quantity);
        if ($picked !== null) {
            $pricesBuy[] = $picked;
        }
    }

    return [
        'prices' => $viewAll,
        'price_ranges' => $viewAll,
        'prices_view' => $pricesView,
        'prices_view_all' => $viewAll,
        'prices_buy' => $pricesBuy,
        'prices_buy_all' => $buyAll,
        'primary_buy_price' => $pricesBuy[0] ?? ($pricesView[0] ?? null),
    ];
}

function frontcalc_group_price_ranges_by_catalog_group(array $rows): array
{
    $grouped = [];

    foreach ($rows as $row) {
        $groupId = (int)($row['catalog_group_id'] ?? 0);
        if ($groupId <= 0) {
            continue;
        }

        $groupKey = (string)$groupId;
        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = [
                'catalog_group_id' => $groupId,
                'catalog_group_name' => (string)($row['catalog_group_name'] ?? ('PRICE_' . $groupId)),
                'prices' => [],
            ];
        }

        $grouped[$groupKey]['prices'][] = $row;
    }

    return $grouped;
}

function frontcalc_pick_price_for_quantity(array $rows, int $quantity = 1): ?array
{
    if (empty($rows)) {
        return null;
    }

    foreach ($rows as $row) {
        $from = $row['quantity_from'];
        $to = $row['quantity_to'];
        $fromOk = ($from === null) || ((int)$from <= $quantity);
        $toOk = ($to === null) || ((int)$to >= $quantity);
        if ($fromOk && $toOk) {
            return $row;
        }
    }

    return null;
}

function frontcalc_round_catalog_price(float $value, int $catalogGroupId, string $currency): float
{
    if (class_exists('\Bitrix\Catalog\Product\Price')) {
        return (float)\Bitrix\Catalog\Product\Price::roundPrice($catalogGroupId, $value, $currency);
    }
    return $value;
}

function frontcalc_get_volume_grid_config(): array
{
    $default = '1,2,3,4,5,10,15,20,30,40,50,100,150,200,300,400,500,1000,1500,2000,3000,4000,5000,10000,15000,20000,30000,40000,50000,100000,150000,200000';
    $raw = (string)Option::get('prospektweb.calc', 'VOLUME_GRID_VALUES', $default);
    $tokens = preg_split('/[\s,;]+/u', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
    $values = [];
    foreach (is_array($tokens) ? $tokens : [] as $token) {
        if (preg_match('/^[1-9][0-9]*$/', $token) === 1) {
            $value = (int)$token;
            $values[$value] = $value;
        }
    }
    if (count($values) < 5) {
        $fallback = array_map('intval', explode(',', $default));
        $values = array_combine($fallback, $fallback);
    }
    sort($values, SORT_NUMERIC);
    $tailStep = (int)Option::get('prospektweb.calc', 'VOLUME_GRID_TAIL_STEP', '50000');
    return ['values' => array_values($values), 'tail_step' => $tailStep > 0 ? $tailStep : 50000];
}

function frontcalc_get_price_rounding_rules(array $catalogGroupIds): array
{
    $result = [];
    if (!class_exists('\\Bitrix\\Catalog\\Product\\Price')) {
        return $result;
    }
    foreach (array_values(array_unique(array_map('intval', $catalogGroupIds))) as $catalogGroupId) {
        if ($catalogGroupId <= 0) { continue; }
        $rules = [];
        foreach (\Bitrix\Catalog\Product\Price::getRoundRules($catalogGroupId) as $rule) {
            $rules[] = [
                'price' => (float)($rule['PRICE'] ?? 0),
                'type' => (int)($rule['ROUND_TYPE'] ?? 0),
                'precision' => (float)($rule['ROUND_PRECISION'] ?? 0),
            ];
        }
        $result[(string)$catalogGroupId] = $rules;
    }
    return $result;
}

/**
 * @return array<string,mixed>
 */

function frontcalc_nullable_float($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $floatValue = (float)$value;
    return $floatValue > 0 ? $floatValue : null;
}

function frontcalc_nullable_non_negative_float($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $floatValue = (float)$value;
    return $floatValue >= 0 ? $floatValue : null;
}

function frontcalc_sanitize_public_calculation_meta(array $meta): array
{
    $allowedKeys = [
        'requested' => true,
        'calculated' => true,
        'batch_count' => true,
        'successful_batches' => true,
        'partial_batches' => true,
        'failed_batches' => true,
        'duration_ms' => true,
        'warnings' => true,
    ];

    $publicMeta = array_intersect_key($meta, $allowedKeys);
    if (isset($publicMeta['warnings']) && !is_array($publicMeta['warnings'])) {
        $publicMeta['warnings'] = [(string)$publicMeta['warnings']];
    }

    return $publicMeta;
}

function frontcalc_build_metrics($widthMm, $lengthMm, $heightMm, $weightG): array
{
    $widthMm = frontcalc_nullable_float($widthMm);
    $lengthMm = frontcalc_nullable_float($lengthMm);
    $heightMm = frontcalc_nullable_float($heightMm);
    $weightG = frontcalc_nullable_float($weightG);

    return [
        'widthMm' => $widthMm,
        'lengthMm' => $lengthMm,
        'heightMm' => $heightMm,
        'weightG' => $weightG,
        'weightKg' => $weightG === null ? null : round($weightG / 1000, 2),
        'volumeM3' => ($widthMm === null || $lengthMm === null || $heightMm === null) ? null : round($widthMm * $lengthMm * $heightMm / 1000000000, 5),
    ];
}

function frontcalc_normalize_public_properties(array $properties): array
{
    $result = [];
    foreach ($properties as $code => $property) {
        if (strpos((string)$code, 'CALC_PROP_') !== 0 || !is_array($property)) {
            continue;
        }
        $xmlId = (string)($property['xml_id'] ?? $property['VALUE_XML_ID'] ?? $property['VALUE'] ?? '');
        $result[$code] = [
            'value' => $property['value'] ?? $property['VALUE'] ?? '',
            'xmlId' => $xmlId,
            'sort' => (int)($property['sort'] ?? $property['SORT'] ?? 500),
            // Temporary compatibility alias for the current popup frontend.
            'xml_id' => $xmlId,
        ];
    }
    ksort($result);
    return $result;
}


function frontcalc_normalize_numeric_string($value): string
{
    if ($value === null) {
        return '';
    }
    $normalized = preg_replace('/[\s\x{00A0}]+/u', '', trim((string)$value));
    if ($normalized === '') {
        return '';
    }
    $normalized = str_replace(',', '.', $normalized);
    if (!is_numeric($normalized)) {
        return (string)$value;
    }
    $number = (float)$normalized;
    if (abs($number - round($number)) < 0.000001) {
        return (string)(int)round($number);
    }
    return rtrim(rtrim(sprintf('%.6F', $number), '0'), '.');
}

function frontcalc_extract_offer_quantity(array $properties, int $fallback = 1): int
{
    $quantity = (new \Prospektweb\Frontcalc\Service\VolumeQuantityResolver())->resolvePropertyQuantity($properties['CALC_PROP_VOLUME'] ?? null);
    return $quantity ?? $fallback;
}

function frontcalc_stable_virtual_offer_key(int $productId, array $selectedOffer, ?int $quantity = null): string
{
    $props = [];
    $resolver = new \Prospektweb\Frontcalc\Service\VolumeQuantityResolver();
    foreach (($selectedOffer['properties'] ?? []) as $code => $property) {
        if (strpos((string)$code, 'CALC_PROP_') !== 0 || !is_array($property)) { continue; }
        if ((string)$code === 'CALC_PROP_VOLUME') {
            $resolvedQuantity = $quantity ?? $resolver->resolvePropertyQuantity($property);
            if ($resolvedQuantity !== null) { $props[$code] = (string)$resolvedQuantity; }
            continue;
        }
        $token = '';
        foreach (['VALUE_XML_ID', 'xml_id', 'xmlId', 'VALUE', 'value'] as $key) {
            $candidate = trim((string)($property[$key] ?? ''));
            if ($candidate !== '') { $token = $candidate; break; }
        }
        $props[$code] = $token;
    }
    ksort($props);
    $tokens = [];
    foreach ($props as $code => $value) {
        $tokens[] = rawurlencode((string)$code) . '=' . rawurlencode((string)$value);
    }
    return 'calc:' . $productId . ':' . ($quantity ?? 1) . ':' . implode(';', $tokens);
}

function frontcalc_apply_canonical_offer_contract(array $offer, bool $canViewInternal): array
{
    $offer['offerKey'] = $offer['offerKey'] ?? (($offer['is_virtual'] ?? false) ? (string)($offer['virtual_id'] ?? '') : 'bitrix:' . (int)($offer['id'] ?? 0));
    $offer['source'] = (string)($offer['source'] ?? 'bitrix');
    $offer['isVirtual'] = (bool)($offer['isVirtual'] ?? $offer['is_virtual'] ?? false);
    $offer['xmlId'] = (string)($offer['xmlId'] ?? $offer['xml_id'] ?? '');
    $offer['quantity'] = (int)($offer['quantity'] ?? 1);
    $offer['properties'] = frontcalc_normalize_public_properties(is_array($offer['properties'] ?? null) ? $offer['properties'] : []);
    $offer['metrics'] = is_array($offer['metrics'] ?? null) ? $offer['metrics'] : frontcalc_build_metrics(null, null, null, null);
    $offer['pricing'] = is_array($offer['pricing'] ?? null) ? $offer['pricing'] : ['ranges' => []];
    if (!$canViewInternal) {
        unset($offer['internal']);
    }

    // Temporary compatibility aliases for the current popup frontend.
    $offer['xml_id'] = $offer['xmlId'];
    $offer['is_virtual'] = $offer['isVirtual'];
    return $offer;
}

function frontcalc_load_catalog_product_data(array $offerIds): array
{
    $result = [];
    $offerIds = array_values(array_filter(array_map('intval', $offerIds)));
    if (empty($offerIds)) {
        return $result;
    }
    $res = CCatalogProduct::GetList([], ['@ID' => $offerIds], false, false, ['ID', 'WIDTH', 'LENGTH', 'HEIGHT', 'WEIGHT', 'PURCHASING_PRICE', 'PURCHASING_CURRENCY']);
    while ($row = $res->Fetch()) {
        $id = (int)($row['ID'] ?? 0);
        if ($id > 0) {
            $result[$id] = [
                'metrics' => frontcalc_build_metrics($row['WIDTH'] ?? null, $row['LENGTH'] ?? null, $row['HEIGHT'] ?? null, $row['WEIGHT'] ?? null),
                'purchasePrice' => frontcalc_nullable_non_negative_float($row['PURCHASING_PRICE'] ?? null),
                'currency' => (string)($row['PURCHASING_CURRENCY'] ?? 'RUB'),
            ];
        }
    }
    return $result;
}

function frontcalc_make_calc_server_property(string $code, string $value, string $xmlId = ''): array
{
    $xmlId = $xmlId !== '' ? $xmlId : $value;

    return [
        'CODE' => $code,
        'VALUE' => $value,
        '~VALUE' => $value,
        'VALUE_XML_ID' => $xmlId,
    ];
}

/**
 * @return array<int,array{id:int,name:string}>
 */
function frontcalc_get_price_types_for_calc_server(array $priceGroupsView): array
{
    $result = [];
    foreach ($priceGroupsView as $group) {
        $id = (int)($group['id'] ?? 0);
        if ($id > 0) {
            $result[] = ['id' => $id, 'name' => (string)($group['name'] ?? ('PRICE_' . $id))];
        }
    }

    return $result;
}

function frontcalc_normalize_calc_server_result_to_offer(array $result, array $selectedOffer, int $productId, bool $canViewInternal, array $pricingRanges = [], array $catalogAliases = []): ?array
{
    $offerId = (int)($result['offer_id'] ?? 0);
    if ($offerId >= 0) {
        return null;
    }

    $properties = [];
    foreach (($selectedOffer['properties'] ?? []) as $code => $property) {
        if (strpos((string)$code, 'CALC_PROP_') !== 0 || !is_array($property)) {
            continue;
        }
        $properties[$code] = [
            'value' => (string)($property['VALUE'] ?? $property['value'] ?? ''),
            'xml_id' => (string)($property['VALUE_XML_ID'] ?? $property['xml_id'] ?? $property['VALUE'] ?? ''),
            'sort' => 500,
        ];
    }

    $quantity = frontcalc_extract_offer_quantity(is_array($selectedOffer['properties'] ?? null) ? $selectedOffer['properties'] : []);
    $offerKey = frontcalc_stable_virtual_offer_key($productId, $selectedOffer, $quantity);

    $internal = [
        'directPurchasePrice' => frontcalc_nullable_non_negative_float($result['direct_purchase_price'] ?? null),
        'purchasePrice' => frontcalc_nullable_non_negative_float($result['purchase_price'] ?? null),
        'currency' => (string)($result['currency'] ?? 'RUB'),
        'parametrValues' => is_array($result['parametr_values'] ?? null) ? $result['parametr_values'] : [],
    ];

    $offer = [
        'offerKey' => $offerKey,
        'id' => $offerId,
        'virtual_id' => $offerKey,
        'source' => 'calc-server',
        'isVirtual' => true,
        'is_virtual' => true,
        'name' => (string)($result['name'] ?? $selectedOffer['name'] ?? 'Рассчитанный вариант'),
        'xmlId' => 'virtual-' . abs($offerId),
        'xml_id' => 'virtual-' . abs($offerId),
        'sort' => 500,
        'quantity' => $quantity,
        'properties' => $properties,
        'metrics' => frontcalc_build_metrics($result['width'] ?? null, $result['length'] ?? null, $result['height'] ?? null, $result['weight'] ?? null),
        'pricing' => ['ranges' => $pricingRanges],
        // Temporary compatibility aliases for the current popup frontend.
        'catalog' => !empty($catalogAliases) ? $catalogAliases : [
            'prices' => [],
            'price_ranges' => [],
            'prices_view' => [],
            'prices_view_all' => [],
            'prices_buy' => [],
            'prices_buy_all' => [],
            'primary_buy_price' => null,
        ],
        'calculation' => [
            'dimensions' => [
                'width' => frontcalc_nullable_float($result['width'] ?? null),
                'length' => frontcalc_nullable_float($result['length'] ?? null),
                'height' => frontcalc_nullable_float($result['height'] ?? null),
                'unit' => 'mm',
            ],
            'weight' => ['value' => frontcalc_nullable_float($result['weight'] ?? null), 'unit' => 'g'],
        ],
    ];
    if ($canViewInternal) {
        $offer['internal'] = $internal;
    }

    return frontcalc_apply_canonical_offer_contract($offer, $canViewInternal);
}


function frontcalc_assemble_public_calc_offers(array $batchResult, array $baseInitPayload, array $priceAccess, int $productId, bool $canViewInternal, array &$calculationMeta): array
{
    $offersByKey = [];
    $warnings = [];
    $priceDiagnostics = [];
    $affectedOffers = 0;
    $presetPricesForCalculation = is_array($baseInitPayload['preset']['prices'] ?? null) ? $baseInitPayload['preset']['prices'] : [];
    $priceTypesForCalculation = is_array($baseInitPayload['priceTypes'] ?? null) ? $baseInitPayload['priceTypes'] : [];
    foreach (is_array($batchResult['items'] ?? null) ? $batchResult['items'] : [] as $batchItem) {
        $calcOfferResult = is_array($batchItem['result'] ?? null) ? $batchItem['result'] : [];
        $selectedOffer = is_array($batchItem['selectedOffer'] ?? null) ? $batchItem['selectedOffer'] : null;
        if ($selectedOffer === null) { continue; }
        $baseCost = frontcalc_nullable_non_negative_float($calcOfferResult['purchase_price'] ?? null);
        $baseCurrency = (string)($calcOfferResult['currency'] ?? 'RUB');
        $quantity = frontcalc_extract_offer_quantity(is_array($selectedOffer['properties'] ?? null) ? $selectedOffer['properties'] : []);
        $pricingRanges = [];
        $catalogAliases = [];
        if ($baseCost !== null) {
            $priceCalculator = new \Prospektweb\Frontcalc\Service\PresetPriceCalculator();
            $allPricingRanges = $priceCalculator->calculate($baseCost, $baseCurrency, $presetPricesForCalculation, $priceTypesForCalculation);
            $warnings = array_values(array_unique(array_merge($warnings, $priceCalculator->getWarnings())));
            $diagnostics = $priceCalculator->getDiagnostics();
            if (!empty($diagnostics)) { $priceDiagnostics = array_merge($priceDiagnostics, $diagnostics); $affectedOffers++; }
            $pricingRanges = frontcalc_filter_canonical_ranges_by_access($allPricingRanges, $priceAccess['view']);
            $viewLegacyRanges = frontcalc_canonical_ranges_to_legacy($pricingRanges);
            $buyLegacyRanges = frontcalc_canonical_ranges_to_legacy(frontcalc_filter_canonical_ranges_by_access($allPricingRanges, $priceAccess['buy']));
            $catalogAliases = frontcalc_build_catalog_aliases_from_legacy_ranges($viewLegacyRanges, $buyLegacyRanges, 1);
        }
        $virtualOffer = frontcalc_normalize_calc_server_result_to_offer($calcOfferResult, $selectedOffer, $productId, $canViewInternal, $pricingRanges, $catalogAliases);
        if ($virtualOffer !== null) { $offersByKey[(string)$virtualOffer['offerKey']] = $virtualOffer; }
    }
    $calculationMeta['warnings'] = array_values(array_unique(array_merge($calculationMeta['warnings'] ?? [], $warnings)));
    return ['offers'=>array_values($offersByKey),'warnings'=>$warnings,'priceDiagnostics'=>$priceDiagnostics,'affectedOffers'=>$affectedOffers];
}

/**
 * @return array<int,string>
 */
function frontcalc_get_catalog_group_names(): array
{
    $names = [];
    $groupRes = CCatalogGroup::GetList(['SORT' => 'ASC'], []);
    while ($group = $groupRes->Fetch()) {
        $id = (int)($group['ID'] ?? 0);
        if ($id > 0) {
            $names[$id] = (string)($group['NAME_LANG'] ?? $group['NAME'] ?? ('PRICE_' . $id));
        }
    }

    return $names;
}

if (!Loader::includeModule($moduleId) || !Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Не удалось подключить необходимые модули (prospektweb.calc, iblock, catalog).',
    ], JSON_UNESCAPED_UNICODE);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
    return;
}

$accessResolver = new \Prospektweb\Frontcalc\Service\AccessScenarioResolver();

function frontcalc_access_denied_response(): void
{
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => \Prospektweb\Frontcalc\Service\AccessScenarioResolver::DENIED_CODE,
            'message' => \Prospektweb\Frontcalc\Service\AccessScenarioResolver::DENIED_MESSAGE,
        ],
        'message' => \Prospektweb\Frontcalc\Service\AccessScenarioResolver::DENIED_MESSAGE,
    ], JSON_UNESCAPED_UNICODE);
}

function frontcalc_error_response(string $code, string $message, array $data = []): void
{
    $payload = ['success' => false, 'error' => ['code' => $code, 'message' => $message]];
    if (!empty($data)) {
        $payload['data'] = $data;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function frontcalc_deadline_label(string $code): string
{
    return ['strict' => 'Строгий срок', 'urgent' => 'Срочный срок', 'flexible' => 'Гибкий срок'][$code] ?? $code;
}

function frontcalc_log_displayed_price_mismatch(int $productId, float $displayedPrice, float $serverPrice): void
{
    if (abs($displayedPrice - $serverPrice) <= 0.01 || !class_exists('CEventLog')) { return; }
    CEventLog::Add([
        'SEVERITY' => 'WARNING',
        'AUDIT_TYPE_ID' => 'FRONTCALC_DISPLAYED_PRICE_MISMATCH',
        'MODULE_ID' => 'prospektweb.calc',
        'ITEM_ID' => (string)$productId,
        'DESCRIPTION' => json_encode(['productId'=>$productId,'displayedPrice'=>$displayedPrice,'serverPrice'=>$serverPrice], JSON_UNESCAPED_UNICODE),
    ]);
}

function frontcalc_parse_displayed_price($value): ?float
{
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }
    if (!is_string($value)) {
        return null;
    }
    $text = trim($value);
    if ($text === '') {
        return null;
    }
    $normalized = str_replace(["\xc2\xa0", ' '], '', $text);
    if (!preg_match('/^-?\d+(?:[,.]\d+)?$/', $normalized)) {
        return null;
    }
    return (float)str_replace(',', '.', $normalized);
}


$frontcalcAction = trim((string)($_POST['action'] ?? ''));
$deferCalculation = $frontcalcAction === 'load' && strtoupper(trim((string)($_POST['defer_calculation'] ?? ''))) === 'Y';
$allowedActions = ['load', 'calculate_custom', 'add_to_basket'];
if (!in_array($frontcalcAction, $allowedActions, true)) {
    frontcalc_error_response('FRONTCALC_ACTION_INVALID', 'Некорректное действие калькулятора.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
    return;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    frontcalc_error_response('FRONTCALC_METHOD_NOT_ALLOWED', 'Калькулятор доступен только POST-запросом.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
    return;
}
if (!function_exists('check_bitrix_sessid') || !check_bitrix_sessid()) {
    frontcalc_error_response('FRONTCALC_INVALID_SESSID', 'Сессия устарела. Обновите страницу и повторите действие.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
    return;
}
if ($frontcalcAction === 'add_to_basket') {
    if (!$accessResolver->canAddToBasket()) {
        frontcalc_access_denied_response();
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
} elseif (!$accessResolver->canOpenCalculator() || !$accessResolver->canCalculate()) {
    frontcalc_access_denied_response();
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
    return;
}

$productsIblockId = (int)Option::get($moduleId, 'PRODUCTS_IBLOCK_ID', '0');
$offersIblockId = (int)Option::get($moduleId, 'OFFERS_IBLOCK_ID', '0');
$propertyCode = trim((string)Option::get($moduleId, 'CALC_PROPERTY_CODE', 'FRONTCALC_CONFIG'));
$calcServerDebugConsole = Option::get($moduleId, 'CALC_SERVER_DEBUG_CONSOLE', 'N') === 'Y';

if ($productsIblockId <= 0 || $offersIblockId <= 0 || $propertyCode === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Не настроены параметры инфоблока/свойства калькулятора.',
    ], JSON_UNESCAPED_UNICODE);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
    return;
}

$productIdRaw = $_POST['product_id'] ?? '';
$productId = (new \Prospektweb\Frontcalc\Service\VolumeQuantityResolver())->parseStrictPositiveInt($productIdRaw) ?? 0;
$requestedOfferId = $frontcalcAction === 'add_to_basket' ? 0 : ((new \Prospektweb\Frontcalc\Service\VolumeQuantityResolver())->parseStrictPositiveInt($_POST['offer_id'] ?? 0) ?? 0);
if (!(new \Prospektweb\Frontcalc\Service\ProductAccessValidator())->validate($productIdRaw, $productsIblockId, defined('SITE_ID') ? (string)SITE_ID : '')) {
    frontcalc_error_response('FRONTCALC_PRODUCT_NOT_AVAILABLE', 'Товар недоступен.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
    return;
}
if ($frontcalcAction === 'calculate_custom') {
    $calculationSessionId = trim((string)($_POST['calculation_session_id'] ?? ''));
    $calculationSessionStore = new \Prospektweb\Frontcalc\Service\CalculationSessionStore($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($calculationSessionId === '' || $calculationSessionStore->load($calculationSessionId, $productId) === null) {
        frontcalc_error_response('FRONTCALC_CALCULATION_SESSION_INVALID', 'Сессия расчёта устарела. Откройте калькулятор заново.');
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
}



if ($frontcalcAction === 'add_to_basket') {
    if (!Loader::includeModule('sale')) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Не удалось подключить модуль sale.'], JSON_UNESCAPED_UNICODE);
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }

    $sessionId = trim((string)($_POST['calculation_session_id'] ?? ''));
    $sessionStore = new \Prospektweb\Frontcalc\Service\CalculationSessionStore($_SERVER['DOCUMENT_ROOT'] ?? '');
    $session = $sessionStore->load($sessionId, $productId);
    if ($session === null) {
        frontcalc_error_response('FRONTCALC_CALCULATION_SESSION_INVALID', 'Сессия расчёта устарела. Закройте и заново откройте калькулятор.');
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }

    $catalogGroupId = (int)($_POST['catalog_group_id'] ?? 0);
    $priceAccessForBasket = frontcalc_get_catalog_groups_by_rights(frontcalc_get_current_user_groups());
    if ($catalogGroupId <= 0 || !in_array($catalogGroupId, $priceAccessForBasket['buy'], true)) {
        frontcalc_error_response('FRONTCALC_PRICE_TYPE_NOT_ALLOWED', 'Выбранный тип цен недоступен для покупки.');
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
    $targetQuantityRaw = $_POST['target_quantity'] ?? '';
    $targetQuantityText = is_scalar($targetQuantityRaw) ? trim((string)$targetQuantityRaw) : '';
    if (strlen($targetQuantityText) > strlen((string)PHP_INT_MAX)) {
        frontcalc_error_response('FRONTCALC_QUOTE_QUANTITY_INVALID', 'Некорректный тираж.');
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
    $targetQuantity = (new \Prospektweb\Frontcalc\Service\VolumeQuantityResolver())->parseStrictPositiveInt($targetQuantityRaw);
    if ($targetQuantity === null) {
        frontcalc_error_response('FRONTCALC_QUOTE_QUANTITY_INVALID', 'Некорректный тираж.');
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
    $deadlineType = trim((string)($_POST['deadline_type'] ?? 'strict'));
    if (!in_array($deadlineType, ['strict','urgent','flexible'], true)) {
        frontcalc_error_response('FRONTCALC_QUOTE_NOT_FOUND', 'Некорректный срок.');
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
    $selectedValues = json_decode((string)($_POST['selected_values'] ?? ''), true);
    if (!is_array($selectedValues) || array_values($selectedValues) === $selectedValues) {
        frontcalc_error_response('FRONTCALC_QUOTE_SELECTION_INVALID', 'Некорректный набор свойств.');
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }

    $quote = (new \Prospektweb\Frontcalc\Service\ServerQuoteCalculator())->calculate($session['offers'], $selectedValues, $targetQuantity, $catalogGroupId, $deadlineType, $session['config']);
    if (($quote['success'] ?? false) !== true) {
        frontcalc_error_response((string)($quote['code'] ?? 'FRONTCALC_QUOTE_NOT_FOUND'), 'Не удалось рассчитать цену для корзины.');
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
    $quantityCheck = (new \Prospektweb\Frontcalc\Service\VirtualOfferBatchBuilder())->validateQuantityConstraints($targetQuantity, is_array($session['config']['volumeConstraints'] ?? null) ? $session['config']['volumeConstraints'] : [], is_array($quote['normalizedSelectedValues'] ?? null) ? $quote['normalizedSelectedValues'] : []);
    if (empty($quantityCheck['ok'])) {
        frontcalc_error_response('FRONTCALC_QUOTE_QUANTITY_INVALID', 'Некорректный тираж.');
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }

    $sourceKeys = is_array($quote['sourceOfferKeys'] ?? null) ? $quote['sourceOfferKeys'] : [];
    $sourceOffer = null;
    foreach ($session['offers'] as $offer) {
        if (in_array((string)($offer['offerKey'] ?? ''), $sourceKeys, true)) { $sourceOffer = $offer; break; }
    }
    $basketProductId = 0;
    if (($quote['mode'] ?? '') === 'exact' && count($sourceKeys) === 1 && is_array($sourceOffer) && (string)($sourceOffer['source'] ?? '') === 'bitrix' && empty($sourceOffer['isVirtual']) && (int)($sourceOffer['id'] ?? 0) > 0) {
        $basketProductId = (int)$sourceOffer['id'];
    } else {
        $serviceOfferId = (int)Option::get($moduleId, 'SERVICE_OFFER_ID', '0');
        $basketProductId = $serviceOfferId > 0 && CIBlockElement::GetByID($serviceOfferId)->Fetch() ? $serviceOfferId : $productId;
    }
    if ($basketProductId <= 0 || !($element = CIBlockElement::GetByID($basketProductId)->Fetch())) {
        frontcalc_error_response('FRONTCALC_BASKET_CARRIER_NOT_FOUND', 'Не найден товар для добавления рассчитанной позиции в корзину.');
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }

    if (array_key_exists('displayed_price', $_POST)) {
        $displayedPrice = frontcalc_parse_displayed_price($_POST['displayed_price']);
        if ($displayedPrice !== null) {
            frontcalc_log_displayed_price_mismatch($productId, $displayedPrice, (float)$quote['price']);
        }
    }

    $calculatedName = trim((string)($quote['name'] ?? ''));
    if ($calculatedName === '') { $calculatedName = (string)($element['NAME'] ?? ('Товар #' . $basketProductId)); }
    $props = [
        ['NAME'=>'Тираж','CODE'=>'FRONTCALC_QUANTITY','VALUE'=>(string)$targetQuantity,'SORT'=>100],
        ['NAME'=>'Код срока','CODE'=>'FRONTCALC_DEADLINE_CODE','VALUE'=>$deadlineType,'SORT'=>110],
        ['NAME'=>'Срок','CODE'=>'FRONTCALC_DEADLINE','VALUE'=>frontcalc_deadline_label($deadlineType),'SORT'=>120],
        ['NAME'=>'Тип цены','CODE'=>'FRONTCALC_CATALOG_GROUP_ID','VALUE'=>(string)$catalogGroupId,'SORT'=>130],
        ['NAME'=>'Исходный товар калькулятора','CODE'=>'FRONTCALC_SOURCE_PRODUCT_ID','VALUE'=>(string)$productId,'SORT'=>140],
        ['NAME'=>'Режим расчёта','CODE'=>'FRONTCALC_QUOTE_MODE','VALUE'=>(string)$quote['mode'],'SORT'=>150],
        ['NAME'=>'Source offer keys','CODE'=>'FRONTCALC_SOURCE_OFFER_KEYS','VALUE'=>implode(',', $sourceKeys),'SORT'=>160],
        ['NAME'=>'Название рассчитанной позиции','CODE'=>'FRONTCALC_CALCULATED_NAME','VALUE'=>$calculatedName,'SORT'=>170],
    ];
    $sort = 200;
    foreach (is_array($quote['normalizedSelectedValues'] ?? null) ? $quote['normalizedSelectedValues'] : [] as $code => $row) {
        if (strpos((string)$code, 'CALC_PROP_') !== 0 || !is_array($row)) { continue; }
        $value = trim((string)($row['value'] ?? '')); $xml = trim((string)($row['xmlId'] ?? ''));
        $props[] = ['NAME'=>(string)$code,'CODE'=>(string)$code,'VALUE'=>$value . ($xml !== '' && $xml !== $value ? ' [' . $xml . ']' : ''),'SORT'=>$sort++];
    }
    $isStandardCatalogOffer = ($quote['mode'] ?? '') === 'exact'
        && count($sourceKeys) === 1
        && is_array($sourceOffer)
        && (string)($sourceOffer['source'] ?? '') === 'bitrix'
        && empty($sourceOffer['isVirtual'])
        && (int)($sourceOffer['id'] ?? 0) === $basketProductId;
    if ($isStandardCatalogOffer && class_exists('\\Bitrix\\Catalog\\Product\\Basket')) {
        $addResult = \Bitrix\Catalog\Product\Basket::addProduct([
            'PRODUCT_ID' => $basketProductId,
            'QUANTITY' => 1,
            'LID' => SITE_ID,
            'PROPS' => $props,
        ]);
        if (!$addResult->isSuccess()) {
            http_response_code(500);
            echo json_encode(['success'=>false,'message'=>implode('; ', $addResult->getErrorMessages())], JSON_UNESCAPED_UNICODE);
            require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
            return;
        }
        echo json_encode([
            'success'=>true,
            'basket_item_id'=>(int)($addResult->getData()['BASKET_ITEM_ID'] ?? 0),
            'product_id'=>$basketProductId,
            'standard_catalog_offer'=>true,
            'price'=>(float)$quote['price'],
            'currency'=>(string)$quote['currency'],
            'quote_mode'=>(string)$quote['mode'],
        ], JSON_UNESCAPED_UNICODE);
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }

    $basket = Basket::loadItemsForFUser(Fuser::getId(), SITE_ID);
    $basketItem = $basket->createItem('catalog', $basketProductId);
    $basketItem->setFields(['QUANTITY'=>1,'CURRENCY'=>(string)$quote['currency'],'LID'=>SITE_ID,'PRODUCT_PROVIDER_CLASS'=>'','PRICE'=>(float)$quote['price'],'CUSTOM_PRICE'=>'Y','NAME'=>$calculatedName]);
    $basketItem->getPropertyCollection()->setProperty($props);
    $saveResult = $basket->save();
    if (!$saveResult->isSuccess()) {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>implode('; ', $saveResult->getErrorMessages())], JSON_UNESCAPED_UNICODE);
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
    echo json_encode(['success'=>true,'basket_item_id'=>$basketItem->getId(),'price'=>(float)$quote['price'],'currency'=>(string)$quote['currency'],'quote_mode'=>(string)$quote['mode']], JSON_UNESCAPED_UNICODE);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
    return;
}

if (!$accessResolver->canOpenCalculator() || !$accessResolver->canCalculate()) {
    frontcalc_access_denied_response();
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
    return;
}

$schemaText = '';
$propertyRes = CIBlockElement::GetProperty($productsIblockId, $productId, [], ['CODE' => $propertyCode]);
if ($propertyRes && ($property = $propertyRes->Fetch())) {
    $value = $property['VALUE'] ?? '';
    $schemaText = is_array($value) ? trim((string)($value['TEXT'] ?? '')) : trim((string)$value);
}

$config = [];
if ($schemaText !== '') {
    $decoded = json_decode($schemaText, true);
    if (is_array($decoded)) {
        $config = $decoded;
    }
}


$productName = '';
$productRes = CIBlockElement::GetList([], ['IBLOCK_ID' => $productsIblockId, 'ID' => $productId], false, ['nTopCount' => 1], ['ID', 'NAME']);
if ($productRes && ($productRow = $productRes->Fetch())) {
    $productName = (string)($productRow['NAME'] ?? '');
}

$calcPropertyCodes = [];
$propertyMeta = [];
$propertyEnumNames = [];
$propertyEnumValues = [];
$propertyListRes = CIBlockProperty::GetList(['SORT' => 'ASC'], ['IBLOCK_ID' => $offersIblockId, 'ACTIVE' => 'Y']);
while ($prop = $propertyListRes->Fetch()) {
    $code = trim((string)($prop['CODE'] ?? ''));
    if ($code === '' || strpos($code, 'CALC_PROP_') !== 0) {
        continue;
    }

    $calcPropertyCodes[$code] = $code;
    $propertyId = (int)($prop['ID'] ?? 0);
    $propertyMeta[$code] = [
        'code' => $code,
        'name' => (string)($prop['NAME'] ?? $code),
        'sort' => (int)($prop['SORT'] ?? 500),
    ];

    if ($propertyId > 0) {
        $enumNames = [];
        $enumValues = [];
        $enumRes = CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => $propertyId]);
        while ($enum = $enumRes->Fetch()) {
            $enumXmlId = trim((string)($enum['XML_ID'] ?? ''));
            if ($enumXmlId === '') {
                continue;
            }
            $enumValue = (string)($enum['VALUE'] ?? '');
            $enumSort = (int)($enum['SORT'] ?? 500);
            $enumNames[$enumXmlId] = $enumValue;
            $enumValues[$enumXmlId] = ['value' => $enumValue, 'xml_id' => $enumXmlId, 'sort' => $enumSort];
        }
        if (!empty($enumNames)) {
            $propertyEnumNames[$code] = $enumNames;
            $propertyEnumValues[$code] = $enumValues;
        }
    }
}


if (isset($config['fields']) && is_array($config['fields'])) {
    foreach ($config['fields'] as &$field) {
        $code = trim((string)($field['property_code'] ?? ''));
        $displayXmlIds = (isset($field['display_preset_xml_ids']) && is_array($field['display_preset_xml_ids'])) ? $field['display_preset_xml_ids'] : [];
        if ($code === '' || empty($displayXmlIds) || empty($propertyEnumValues[$code])) {
            continue;
        }
        $field['presets'] = [];
        foreach ($displayXmlIds as $xmlId) {
            $xmlId = trim((string)$xmlId);
            if ($xmlId !== '' && isset($propertyEnumValues[$code][$xmlId])) {
                $field['presets'][] = $propertyEnumValues[$code][$xmlId];
            }
        }
    }
    unset($field);
}
$volumeEnumValues = is_array($propertyEnumValues['CALC_PROP_VOLUME'] ?? null) ? $propertyEnumValues['CALC_PROP_VOLUME'] : [];
$config['deadline_adjustments'] = \Prospektweb\Frontcalc\Service\DeadlineAdjustmentNormalizer::normalize($config, $volumeEnumValues);

$offers = [];
$presetBuckets = [];
$hasXmlIdErrors = false;
$xmlIdErrors = [];
$userGroups = frontcalc_get_current_user_groups();
$priceAccess = frontcalc_get_catalog_groups_by_rights($userGroups);
$catalogGroupNames = frontcalc_get_catalog_group_names();
$priceGroupsView = [];
foreach ($priceAccess['view'] as $catalogGroupId) {
    $catalogGroupId = (int)$catalogGroupId;
    if ($catalogGroupId <= 0) {
        continue;
    }
    $priceGroupsView[] = [
        'id' => $catalogGroupId,
        'name' => (string)($catalogGroupNames[$catalogGroupId] ?? ('PRICE_' . $catalogGroupId)),
    ];
}

$offersMap = CCatalogSKU::getOffersList(
    [$productId],
    $productsIblockId,
    ['ACTIVE' => 'Y'],
    ['ID', 'IBLOCK_ID', 'NAME', 'XML_ID', 'SORT'],
    array_values($calcPropertyCodes)
);

$catalogProductDataByOfferId = [];
if (!empty($offersMap[$productId]) && is_array($offersMap[$productId])) {
    $catalogProductDataByOfferId = frontcalc_load_catalog_product_data(array_column($offersMap[$productId], 'ID'));
    foreach ($offersMap[$productId] as $offerRow) {
        $offerId = (int)($offerRow['ID'] ?? 0);
        if ($offerId <= 0) {
            continue;
        }

        $offerProps = [];
        $offerPropRes = CIBlockElement::GetProperty($offersIblockId, $offerId, ['SORT' => 'ASC'], []);
        while ($offerProp = $offerPropRes->Fetch()) {
            $code = trim((string)($offerProp['CODE'] ?? ''));
            if ($code === '' || strpos($code, 'CALC_PROP_') !== 0) {
                continue;
            }

            $value = trim((string)($offerProp['VALUE'] ?? ''));
            $xmlId = trim((string)($offerProp['VALUE_XML_ID'] ?? ''));
            if ($value === '' && $xmlId === '') {
                continue;
            }

            if ($xmlId === '') {
                $hasXmlIdErrors = true;
                $xmlIdErrors[] = [
                    'offer_id' => $offerId,
                    'property_code' => $code,
                    'message' => 'У свойства отсутствует VALUE_XML_ID',
                ];
                continue;
            }

            $sort = (int)($offerProp['VALUE_SORT'] ?? $offerProp['SORT'] ?? 500);

            $presetText = trim((string)($propertyEnumNames[$code][$xmlId] ?? ''));
            if ($presetText === '') {
                $presetText = $value;
            }

            $offerProps[$code] = [
                'value' => $presetText,
                'xml_id' => $xmlId,
                'sort' => $sort,
            ];

            if (!isset($presetBuckets[$code])) {
                $presetBuckets[$code] = [];
            }
            $presetBuckets[$code][$xmlId] = [
                'value' => $presetText,
                'xml_id' => $xmlId,
                'sort' => $sort,
            ];
        }

        $pricesRaw = [];
        $priceRes = CPrice::GetListEx(
            ['CATALOG_GROUP_ID' => 'ASC', 'QUANTITY_FROM' => 'ASC'],
            ['PRODUCT_ID' => $offerId],
            false,
            false,
            ['ID', 'CATALOG_GROUP_ID', 'PRICE', 'CURRENCY', 'QUANTITY_FROM', 'QUANTITY_TO']
        );
        while ($price = $priceRes->Fetch()) {
            $catalogGroupId = (int)($price['CATALOG_GROUP_ID'] ?? 0);
            $priceValue = (float)($price['PRICE'] ?? 0);
            $currency = (string)($price['CURRENCY'] ?? '');
            $roundedValue = frontcalc_round_catalog_price($priceValue, $catalogGroupId, $currency);
            $pricesRaw[] = frontcalc_make_price_range(
                $catalogGroupId,
                (string)($catalogGroupNames[$catalogGroupId] ?? ('PRICE_' . $catalogGroupId)),
                $roundedValue,
                $currency,
                html_entity_decode((string)CCurrencyLang::CurrencyFormat($roundedValue, $currency, true), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                frontcalc_normalize_quantity_bound($price['QUANTITY_FROM'] ?? null),
                frontcalc_normalize_quantity_bound($price['QUANTITY_TO'] ?? null)
            );
        }

        $pricesViewAll = array_values(array_filter($pricesRaw, static function ($row) use ($priceAccess) {
            return in_array((int)($row['catalog_group_id'] ?? 0), $priceAccess['view'], true);
        }));

        $pricesBuyAll = array_values(array_filter($pricesRaw, static function ($row) use ($priceAccess) {
            return in_array((int)($row['catalog_group_id'] ?? 0), $priceAccess['buy'], true);
        }));

        $offerQuantity = frontcalc_extract_offer_quantity($offerProps);

        $pricesViewByGroup = [];
        foreach ($pricesViewAll as $row) {
            $groupId = (int)($row['catalog_group_id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }
            $pricesViewByGroup[$groupId][] = $row;
        }
        $pricesViewRangesByGroup = frontcalc_group_price_ranges_by_catalog_group($pricesViewAll);
        $pricesView = [];
        foreach ($pricesViewByGroup as $groupRows) {
            $picked = frontcalc_pick_price_for_quantity($groupRows, 1);
            if ($picked !== null) {
                $pricesView[] = $picked;
            }
        }

        $pricesBuyByGroup = [];
        foreach ($pricesBuyAll as $row) {
            $groupId = (int)($row['catalog_group_id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }
            $pricesBuyByGroup[$groupId][] = $row;
        }
        $pricesBuyRangesByGroup = frontcalc_group_price_ranges_by_catalog_group($pricesBuyAll);
        $pricesBuy = [];
        foreach ($pricesBuyByGroup as $groupRows) {
            $picked = frontcalc_pick_price_for_quantity($groupRows, 1);
            if ($picked !== null) {
                $pricesBuy[] = $picked;
            }
        }

        $primaryBuyPrice = null;
        $optimalPrice = CCatalogProduct::GetOptimalPrice($offerId, 1, $userGroups, 'N', [], SITE_ID);
        if (is_array($optimalPrice) && !empty($optimalPrice['RESULT_PRICE'])) {
            $resultPrice = $optimalPrice['RESULT_PRICE'];
            $optValue = (float)($resultPrice['DISCOUNT_PRICE'] ?? $resultPrice['BASE_PRICE'] ?? 0);
            $optCurrency = (string)($resultPrice['CURRENCY'] ?? '');
            $optGroupId = (int)($resultPrice['PRICE_TYPE_ID'] ?? 0);
            if (in_array($optGroupId, $priceAccess['view'], true)) {
                $optRounded = frontcalc_round_catalog_price($optValue, $optGroupId, $optCurrency);
                $primaryBuyPrice = [
                    'id' => (int)($resultPrice['PRICE_ID'] ?? 0),
                    'catalog_group_id' => $optGroupId,
                    'catalog_group_name' => (string)($catalogGroupNames[$optGroupId] ?? ('PRICE_' . $optGroupId)),
                    'price' => $optRounded,
                    'currency' => $optCurrency,
                    'formatted' => html_entity_decode((string)CCurrencyLang::CurrencyFormat($optRounded, $optCurrency, true), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'quantity_from' => null,
                    'quantity_to' => null,
                ];
            }
        }
        if ($primaryBuyPrice === null && !empty($pricesBuy)) {
            $primaryBuyPrice = $pricesBuy[0];
        } elseif ($primaryBuyPrice === null && !empty($pricesView)) {
            $primaryBuyPrice = $pricesView[0];
        }


        $offers[] = frontcalc_apply_canonical_offer_contract([
            'offerKey' => 'bitrix:' . $offerId,
            'id' => $offerId,
            'source' => 'bitrix',
            'isVirtual' => false,
            'name' => (string)($offerRow['NAME'] ?? ''),
            'xml_id' => (string)($offerRow['XML_ID'] ?? ''),
            'sort' => (int)($offerRow['SORT'] ?? 500),
            'quantity' => $offerQuantity,
            'properties' => $offerProps,
            'metrics' => $catalogProductDataByOfferId[$offerId]['metrics'] ?? frontcalc_build_metrics(null, null, null, null),
            'internal' => [
                'directPurchasePrice' => null,
                'purchasePrice' => $catalogProductDataByOfferId[$offerId]['purchasePrice'] ?? null,
                'currency' => $catalogProductDataByOfferId[$offerId]['currency'] ?? 'RUB',
                'parametrValues' => [],
            ],
            'pricing' => ['ranges' => frontcalc_legacy_ranges_to_canonical($pricesViewAll)],
            'catalog' => [
                'prices' => $pricesViewAll,
                'price_ranges' => $pricesViewAll,
                'prices_view' => $pricesView,
                'prices_view_all' => $pricesViewAll,
                'prices_buy' => $pricesBuy,
                'prices_buy_all' => $pricesBuyAll,
                'primary_buy_price' => $primaryBuyPrice,
            ],
        ], $accessResolver->canViewInternalCalculationData());
    }
}


if ($frontcalcAction === 'calculate_custom') {
    $rawSelectedValues = $_POST['selected_values'] ?? [];
    if (is_string($rawSelectedValues)) {
        $decodedSelectedValues = json_decode($rawSelectedValues, true);
        if (!is_array($decodedSelectedValues)) {
            frontcalc_error_response('FRONTCALC_CUSTOM_VALUES_INVALID', 'Некорректные значения расчёта.');
            require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
            return;
        }
        $rawSelectedValues = $decodedSelectedValues;
    }
    if (!is_array($rawSelectedValues)) {
        frontcalc_error_response('FRONTCALC_CUSTOM_VALUES_INVALID', 'Некорректные значения расчёта.');
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
    $validation = (new \Prospektweb\Frontcalc\Service\CustomSelectionValidator())->validate($config, $rawSelectedValues, $propertyEnumValues, $presetBuckets);
    if (empty($validation['ok'])) {
        $error = $validation['error'] ?? ['code' => 'FRONTCALC_CUSTOM_VALUES_INVALID', 'message' => 'Некорректные значения расчёта.'];
        frontcalc_error_response((string)$error['code'], (string)$error['message']);
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
    $validator = new \Prospektweb\Frontcalc\Service\CustomSelectionValidator();
    $targetValidation = $validator->parseTargetQuantity($_POST['target_quantity'] ?? null);
    if (empty($targetValidation['ok'])) {
        $error = $targetValidation['error'] ?? ['code' => 'FRONTCALC_CUSTOM_TARGET_QUANTITY_INVALID', 'message' => 'Укажите положительный целый тираж.'];
        frontcalc_error_response((string)$error['code'], (string)$error['message']);
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
    $targetQuantity = (int)$targetValidation['value'];
    $builder = new \Prospektweb\Frontcalc\Service\VirtualOfferBatchBuilder();
    $volumeContext = $builder->resolveVolumeContext($config, $validation['values'] ?? []);
    $targetRangeValidation = $validator->validateTargetQuantityAgainstContext($targetQuantity, $volumeContext);
    if (empty($targetRangeValidation['ok'])) {
        $error = $targetRangeValidation['error'] ?? ['code' => 'FRONTCALC_CUSTOM_VALUE_OUT_OF_RANGE', 'message' => 'Тираж вне допустимого диапазона.'];
        frontcalc_error_response((string)$error['code'], (string)$error['message']);
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
    $selectedOffers = $builder->buildForSelection($config, $validation['values'] ?? [], $productId, $offersIblockId, $targetQuantity);
    if (empty($selectedOffers)) {
        frontcalc_error_response('FRONTCALC_CUSTOM_NO_REFERENCE_VOLUMES', 'Не найдены тиражи для выбранной комбинации.');
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
    $calcServerUrl = trim((string)Option::get($moduleId, 'CALC_SERVER_URL', ''));
    $calcServerTimeout = 10;
    $virtualBatchLimit = max(1, (int)Option::get($moduleId, 'CALC_SERVER_BATCH_LIMIT', '200'));
    $calculationMeta = ['requested' => count($selectedOffers), 'calculated' => 0, 'batch_count' => (int)ceil(count($selectedOffers) / $virtualBatchLimit), 'successful_batches' => 0, 'partial_batches' => 0, 'failed_batches' => 0, 'warnings' => []];
    $publicOffers = [];
    $presetPriceDiagnostics = [];
    $affectedOffers = 0;
    if ($calcServerUrl === '') {
        $calculationMeta['failed_batches'] = $calculationMeta['batch_count'];
        $calculationMeta['warnings'][] = 'CALC_SERVER_BATCH_FAILED';
    } else {
        \Prospektweb\Frontcalc\Service\PresetPriceCalculator::setCurrencyModuleAvailable(Loader::includeModule('currency'));
        try {
            $baseInitPayload = (new \Prospektweb\Frontcalc\Calculator\InitPayloadService())->prepareProductInitPayload($productId, [], defined('SITE_ID') ? (string)SITE_ID : '');
            $batchResult = (new \Prospektweb\Frontcalc\Service\CalcServerBatchProcessor())->process($baseInitPayload, $selectedOffers, $calcServerUrl, $calcServerTimeout, $virtualBatchLimit, new \Prospektweb\Frontcalc\Service\CalcServerClient());
            $calculationMeta = array_merge($calculationMeta, is_array($batchResult['meta'] ?? null) ? $batchResult['meta'] : []);
            $calculationMeta['warnings'] = array_values(array_unique(array_merge($calculationMeta['warnings'], is_array($batchResult['warnings'] ?? null) ? $batchResult['warnings'] : [])));
            frontcalc_write_calc_server_batch_diagnostics_log($productId, is_array($batchResult['diagnostics'] ?? null) ? $batchResult['diagnostics'] : []);
            $assembled = frontcalc_assemble_public_calc_offers($batchResult, $baseInitPayload, $priceAccess, $productId, $accessResolver->canViewInternalCalculationData(), $calculationMeta);
            $publicOffers = $assembled['offers'];
            $presetPriceDiagnostics = $assembled['priceDiagnostics'];
            $affectedOffers = (int)$assembled['affectedOffers'];
        } catch (\Throwable $exception) {
            $calculationMeta['failed_batches'] = $calculationMeta['batch_count'];
            $calculationMeta['warnings'][] = 'CALC_SERVER_BATCH_FAILED';
            if (class_exists('CEventLog')) {
                CEventLog::Add(['SEVERITY' => 'ERROR', 'AUDIT_TYPE_ID' => 'FRONTCALC_CUSTOM_CALC_FAILED', 'MODULE_ID' => $moduleId, 'ITEM_ID' => (string)$productId, 'DESCRIPTION' => json_encode(['product_id' => $productId, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE)]);
            }
        }
    }
    frontcalc_write_preset_price_diagnostics_log($productId, $presetPriceDiagnostics, $affectedOffers);
    $calculationMeta['calculated'] = count($publicOffers);
    $calculationMeta['warnings'] = array_values(array_unique($calculationMeta['warnings']));
    if (empty($publicOffers)) {
        frontcalc_error_response('CALC_SERVER_BATCH_FAILED', 'Не удалось выполнить расчёт. Повторите попытку позже.', ['calculation_meta' => frontcalc_sanitize_public_calculation_meta($calculationMeta)]);
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
    if (!$calculationSessionStore->mergeOffers($calculationSessionId, $productId, $publicOffers)) {
        frontcalc_error_response('FRONTCALC_CALCULATION_SESSION_INVALID', 'Сессия расчёта устарела. Откройте калькулятор заново.');
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
    echo json_encode(['success' => true, 'data' => ['calculation_session_id' => $calculationSessionId, 'offers' => $publicOffers, 'calculation_meta' => frontcalc_sanitize_public_calculation_meta($calculationMeta), 'access' => $accessResolver->getPublicPayload()]], JSON_UNESCAPED_UNICODE);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
    return;
}

if ($hasXmlIdErrors) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Технические неполадки: у одного или нескольких CALC_PROP_* отсутствует VALUE_XML_ID.',
        'errors' => $xmlIdErrors,
    ], JSON_UNESCAPED_UNICODE);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
    return;
}

foreach ($presetBuckets as $code => $rows) {
    $presets = array_values($rows);
    usort($presets, static function ($a, $b) {
        return ($a['sort'] ?? 500) <=> ($b['sort'] ?? 500);
    });
    $propertyMeta[$code]['presets'] = $presets;
}

$priceGroupsView = [];
foreach ($priceAccess['view'] as $sort => $catalogGroupId) {
    $catalogGroupId = (int)$catalogGroupId;
    if ($catalogGroupId <= 0) {
        continue;
    }

    $priceGroupsView[] = [
        'id' => $catalogGroupId,
        'name' => (string)($catalogGroupNames[$catalogGroupId] ?? ('PRICE_' . $catalogGroupId)),
        'sort' => $sort,
    ];
}

$calculationMeta = [
    'requested' => 0,
    'calculated' => 0,
    'error' => '',
    'warnings' => [],
    'batch_count' => 0,
    'successful_batches' => 0,
    'partial_batches' => 0,
    'failed_batches' => 0,
];
$calcServerUrl = trim((string)Option::get($moduleId, 'CALC_SERVER_URL', ''));
$calcServerTimeout = 10;
$virtualBatchLimit = (int)Option::get($moduleId, 'CALC_SERVER_BATCH_LIMIT', '200');
$virtualBatchLimit = $virtualBatchLimit > 0 ? $virtualBatchLimit : 200;
$presetPriceDiagnostics = [];
$presetPriceDiagnosticsAffectedOffers = 0;

if ($calcServerUrl !== '' && !$deferCalculation) {
    $virtualSelectedOffers = (new \Prospektweb\Frontcalc\Service\VirtualOfferBatchBuilder())->build(
        $config,
        $propertyEnumValues,
        $presetBuckets,
        $offers,
        $productId,
        $offersIblockId
    );
    $calculationMeta['requested'] = count($virtualSelectedOffers);

    if (!empty($virtualSelectedOffers)) {
        \Prospektweb\Frontcalc\Service\PresetPriceCalculator::setCurrencyModuleAvailable(Loader::includeModule('currency'));
        $calculationMeta['batch_count'] = (int)ceil(count($virtualSelectedOffers) / $virtualBatchLimit);
        $calculationMeta['successful_batches'] = 0;
        $calculationMeta['partial_batches'] = 0;
        $calculationMeta['failed_batches'] = 0;
        $calculationMeta['duration_ms'] = 0;
        $initPayloadService = new \Prospektweb\Frontcalc\Calculator\InitPayloadService();
        $calcServerClient = new \Prospektweb\Frontcalc\Service\CalcServerClient();
        $offersByKey = [];
        $siteId = defined('SITE_ID') ? (string)SITE_ID : '';

        try {
            $baseInitPayload = $initPayloadService->prepareProductInitPayload($productId, [], $siteId);
        } catch (\Throwable $exception) {
            $baseInitPayload = null;
            $calculationMeta['failed_batches'] = (int)ceil(count($virtualSelectedOffers) / $virtualBatchLimit);
            $calculationMeta['warnings'][] = 'CALC_SERVER_BATCH_FAILED';
            $calculationMeta['error'] = 'Не удалось собрать base initPayload.';
            if (class_exists('CEventLog')) {
                CEventLog::Add([
                    'SEVERITY' => 'ERROR',
                    'AUDIT_TYPE_ID' => 'FRONTCALC_INIT_PAYLOAD_BASE',
                    'MODULE_ID' => $moduleId,
                    'ITEM_ID' => (string)$productId,
                    'DESCRIPTION' => json_encode([
                        'product_id' => $productId,
                        'batch_count' => (int)ceil(count($virtualSelectedOffers) / $virtualBatchLimit),
                        'error' => $exception->getMessage(),
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        if (is_array($baseInitPayload)) {
            $batchResult = (new \Prospektweb\Frontcalc\Service\CalcServerBatchProcessor())->process(
                $baseInitPayload,
                $virtualSelectedOffers,
                $calcServerUrl,
                $calcServerTimeout,
                $virtualBatchLimit,
                $calcServerClient
            );

            $calculationMeta = array_merge($calculationMeta, is_array($batchResult['meta'] ?? null) ? $batchResult['meta'] : []);
            $calculationMeta['warnings'] = array_values(array_unique(array_merge(
                $calculationMeta['warnings'] ?? [],
                is_array($batchResult['warnings'] ?? null) ? $batchResult['warnings'] : []
            )));
            frontcalc_write_calc_server_batch_diagnostics_log($productId, is_array($batchResult['diagnostics'] ?? null) ? $batchResult['diagnostics'] : []);

            $assembled = frontcalc_assemble_public_calc_offers($batchResult, $baseInitPayload, $priceAccess, $productId, $accessResolver->canViewInternalCalculationData(), $calculationMeta);
            foreach ($assembled['offers'] as $virtualOffer) {
                $offersByKey[(string)$virtualOffer['offerKey']] = $virtualOffer;
            }
            $presetPriceDiagnostics = array_merge($presetPriceDiagnostics, $assembled['priceDiagnostics']);
            $presetPriceDiagnosticsAffectedOffers += (int)$assembled['affectedOffers'];

            if ($calcServerDebugConsole && $accessResolver->canViewInternalCalculationData()) {
                $calculationMeta['debug_console_enabled'] = true;
            }
            $calcServerDebugResults = is_array($batchResult['debugResults'] ?? null) ? $batchResult['debugResults'] : [];

        }

        foreach ($offersByKey as $virtualOffer) {
            $offers[] = $virtualOffer;
        }
        $calculationMeta['calculated'] = count($offersByKey);
        $calculationMeta['warnings'] = array_values(array_unique($calculationMeta['warnings'] ?? []));
    }
}
frontcalc_write_preset_price_diagnostics_log($productId, $presetPriceDiagnostics, $presetPriceDiagnosticsAffectedOffers);
$calculationSessionId = null;
if ($accessResolver->canOpenCalculator() && $accessResolver->canCalculate()) {
    try {
        $calculationSessionId = (new \Prospektweb\Frontcalc\Service\CalculationSessionStore($_SERVER['DOCUMENT_ROOT'] ?? ''))->create($productId, $offers, $config);
    } catch (\RuntimeException $e) {
        frontcalc_error_response('FRONTCALC_CALCULATION_SESSION_FAILED', 'Не удалось создать сессию расчёта.');
        require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
        return;
    }
}

echo json_encode([
    'success' => true,
    'data' => [
        'calculation_session_id' => $calculationSessionId,
        'calculation_pending' => $deferCalculation && $calcServerUrl !== '',
        'product_id' => $productId,
        'product_name' => $productName,
        'requested_offer_id' => $requestedOfferId,
        'access' => $accessResolver->getPublicPayload(),
        'price_access' => $priceAccess,
        'price_groups_view' => $priceGroupsView,
        'price_rounding_rules' => frontcalc_get_price_rounding_rules($priceAccess['view']),
        'volume_grid' => frontcalc_get_volume_grid_config(),
        'config' => $config,
        'deadline_texts' => [
            'urgent' => (string)Option::get($moduleId, 'DEADLINE_URGENT_TEXT', 'Компенсируем 100% стоимости при нарушении договоренностей.'),
            'strict' => (string)Option::get($moduleId, 'DEADLINE_STRICT_TEXT', 'Гарантируем выполнение в согласованный срок.'),
            'flexible' => (string)Option::get($moduleId, 'DEADLINE_FLEXIBLE_TEXT', 'Сроки могут быть скорректированы, но не более чем на 10 рабочих дней.'),
        ],
        'property_meta' => array_values($propertyMeta),
        'offers' => $offers,
        'calculation_meta' => frontcalc_sanitize_public_calculation_meta($calculationMeta),
        'calc_server_raw' => $calcServerDebugConsole && $accessResolver->canViewInternalCalculationData() && isset($calcServerDebugResults) && is_array($calcServerDebugResults) ? $calcServerDebugResults : null,
    ],
], JSON_UNESCAPED_UNICODE);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
