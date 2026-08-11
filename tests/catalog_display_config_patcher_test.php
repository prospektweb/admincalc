<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Install/CatalogDisplayConfigPatcher.php';

use Prospektweb\Calc\Install\CatalogDisplayConfigPatcher;

function catalogDisplayAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function catalogDisplayRemoveFixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target) && !is_link($target)) {
            catalogDisplayRemoveFixture($target);
        } else {
            @unlink($target);
        }
    }
    @rmdir($path);
}

function catalogDisplayFixtureSource(): string
{
    return <<<'PHP'
<?php
$APPLICATION->IncludeComponent('bitrix:catalog', 'main', [
    'FILTER_PROPERTY_CODE' => [
        0 => 'CALC_METHOD',
        1 => 'CALC_TYPE_PAPER',
        2 => 'COLOR_REF2',
    ],
    'FILTER_OFFERS_PROPERTY_CODE' => [
        0 => 'CALC_COLORS',
        1 => 'CALC_LAMINATION',
    ],
    'COMPARE_PROPERTY_CODE' => [
        0 => 'HIT',
        1 => 'CALC_METHOD',
        2 => 'CALC_FORMAT',
    ],
    'COMPARE_OFFERS_PROPERTY_CODE' => [
        0 => 'ARTICLE',
        1 => 'CALC_COLOR',
    ],
    'LIST_PROPERTY_CODE' => array(
        0 => 'CALC_METHOD',
        1 => 'CALC_PRESET',
    ),
    'DETAIL_PROPERTY_CODE' => [
        0 => 'HIT',
        1 => 'CALC_METHOD',
        2 => 'CALC_FILLING',
        3 => 'CALC_FORMAT',
        4 => 'CALC_TYPE_PAPER',
        5 => 'CALC_TYPE_BASE',
        6 => 'CALC_PROTECTION',
        7 => 'CALC_ADD_OPTIONS',
        8 => 'CALC_BINDING',
        9 => 'FRONTCALC_CONFIG',
    ],
    'LIST_OFFERS_PROPERTY_CODE' => [
        0 => '',
        1 => 'CALC_LAMINATION',
        2 => 'CALC_STATE_HASH',
    ],
    'DETAIL_OFFERS_PROPERTY_CODE' => [
        0 => 'CALC_PROP_FORMAT',
        1 => 'CALC_PROP_VOLUME',
        2 => 'CALC_LAMINATION_SIDES',
        3 => 'ARTICLE',
    ],
    'TOP_PROPERTY_CODE' => [
        0 => 'CALC_METHOD',
        1 => 'CALC_TYPE_PAPER',
    ],
    'TOP_OFFERS_PROPERTY_CODE' => [
        0 => 'CALC_TYPE_PAPER',
        1 => 'CALC_LAMINATION',
    ],
    'SKU_PROPERTY_CODE' => [
        0 => 'FORM_ORDER',
        1 => 'CALC_PROP_METHOD',
        2 => 'CALC_PROP_METHOD',
        3 => 'CALC_STATE_HASH',
    ],
    'SKU_TREE_PROPS' => [
        0 => 'COLOR_REF',
        1 => 'SIZES',
        2 => 'CALC_STATE_HASH',
    ],
    'OFFER_TREE_PROPS' => [
        0 => 'COLOR_REF',
        1 => 'SIZES',
    ],
    'OFFERS_CART_PROPERTIES' => [
        0 => 'CALC_PROP_FORMAT',
        1 => 'CALC_PROP_VOLUME',
        2 => 'CALC_STATE_HASH',
    ],
    'CUSTOM_PROPERTY_DATA' => [
        0 => 'PARAMETR_VALUES',
        1 => 'CALC_METHOD',
        2 => 'CALC_TYPE_PAPER',
    ],
]);
PHP;
}

$source = catalogDisplayFixtureSource();
$plan = CatalogDisplayConfigPatcher::patchSource($source);
catalogDisplayAssert($plan['changed'] === true, 'Legacy display config must require a patch.');

foreach ([
    'FILTER_PROPERTY_CODE',
    'COMPARE_PROPERTY_CODE',
    'LIST_PROPERTY_CODE',
    'DETAIL_PROPERTY_CODE',
    'TOP_PROPERTY_CODE',
    'CUSTOM_PROPERTY_DATA',
] as $parameterName) {
    foreach (CatalogDisplayConfigPatcher::movedProductCodes() as $code) {
        catalogDisplayAssert(
            !in_array($code, $plan['parameters'][$parameterName], true),
            $parameterName . ' must not expose moved product property ' . $code . '.'
        );
    }
}
catalogDisplayAssert(
    in_array('CALC_PRESET', $plan['parameters']['LIST_PROPERTY_CODE'], true),
    'CALC_PRESET remains product-owned.'
);
catalogDisplayAssert(
    in_array('FRONTCALC_CONFIG', $plan['parameters']['DETAIL_PROPERTY_CODE'], true),
    'FRONTCALC_CONFIG remains product-owned.'
);

foreach ([
    'FILTER_OFFERS_PROPERTY_CODE',
    'COMPARE_OFFERS_PROPERTY_CODE',
    'LIST_OFFERS_PROPERTY_CODE',
    'DETAIL_OFFERS_PROPERTY_CODE',
    'TOP_OFFERS_PROPERTY_CODE',
    'SKU_PROPERTY_CODE',
] as $parameterName) {
    foreach (CatalogDisplayConfigPatcher::visibleOfferCodes() as $code) {
        catalogDisplayAssert(
            count(array_keys($plan['parameters'][$parameterName], $code, true)) === 1,
            $parameterName . ' must contain the visible offer property exactly once: ' . $code . '.'
        );
    }
    catalogDisplayAssert(
        !in_array('CALC_LAMINATION', $plan['parameters'][$parameterName], true)
            && !in_array('CALC_LAMINATION_SIDES', $plan['parameters'][$parameterName], true)
            && !in_array('CALC_COLORS', $plan['parameters'][$parameterName], true)
            && !in_array('CALC_COLOR', $plan['parameters'][$parameterName], true),
        $parameterName . ' must not retain obsolete semantic aliases.'
    );
    $visiblePositions = [];
    foreach (CatalogDisplayConfigPatcher::visibleOfferCodes() as $code) {
        $visiblePositions[] = array_search($code, $plan['parameters'][$parameterName], true);
    }
    $sortedPositions = $visiblePositions;
    sort($sortedPositions, SORT_NUMERIC);
    catalogDisplayAssert(
        $visiblePositions === $sortedPositions,
        $parameterName . ' must preserve canonical offer-property sort order.'
    );
}

catalogDisplayAssert(
    $plan['parameters']['SKU_TREE_PROPS'] === ['COLOR_REF', 'SIZES'],
    'SKU tree must preserve real dimensions and only remove the technical hash.'
);
catalogDisplayAssert(
    $plan['parameters']['OFFER_TREE_PROPS'] === ['COLOR_REF', 'SIZES'],
    'Aspro offer tree must preserve real dimensions without calculator expansion.'
);
catalogDisplayAssert(
    $plan['parameters']['OFFERS_CART_PROPERTIES'] === ['CALC_PROP_FORMAT', 'CALC_PROP_VOLUME'],
    'Basket identity properties must not receive all calculator characteristics.'
);
foreach ($plan['parameters'] as $parameterName => $values) {
    catalogDisplayAssert(
        !in_array('CALC_STATE_HASH', $values, true),
        $parameterName . ' must not expose the technical state hash.'
    );
}

$secondPlan = CatalogDisplayConfigPatcher::patchSource($plan['source']);
catalogDisplayAssert($secondPlan['changed'] === false, 'Display config patch must be idempotent.');
catalogDisplayAssert(
    $secondPlan['patchedSha256'] === $plan['patchedSha256'],
    'Idempotent patch must retain the same SHA-256.'
);

$shortTagSource = str_replace(
    '$APPLICATION->IncludeComponent',
    '?><?$APPLICATION->IncludeComponent',
    $source
);
$shortTagPlan = CatalogDisplayConfigPatcher::patchSource($shortTagSource);
catalogDisplayAssert(
    $shortTagPlan['changed'] === true
        && in_array('CALC_PROP_METHOD', $shortTagPlan['parameters']['SKU_PROPERTY_CODE'], true),
    'Bitrix short opening tags must be parsed even when local short_open_tag is disabled.'
);

$malformed = str_replace("    'SKU_PROPERTY_CODE' => [", "    'SKU_PROPERTY_CODE_MISSING' => [", $source);
$malformedBlocked = false;
try {
    CatalogDisplayConfigPatcher::patchSource($malformed);
} catch (RuntimeException $error) {
    $malformedBlocked = strpos($error->getMessage(), 'SKU_PROPERTY_CODE') !== false;
}
catalogDisplayAssert($malformedBlocked, 'Missing explicit parameter arrays must block the patch.');

$fixtureRoot = sys_get_temp_dir() . '/pwcalc-catalog-display-' . bin2hex(random_bytes(8));
$catalogDirectory = $fixtureRoot . '/catalog';
$storageRoot = $fixtureRoot . '/module-var';
$fixtureSource = $shortTagSource;
$fixturePlan = $shortTagPlan;
try {
    if (!mkdir($catalogDirectory, 0777, true) && !is_dir($catalogDirectory)) {
        throw new RuntimeException('Unable to create catalog display test fixture.');
    }
    $target = $catalogDirectory . '/index.php';
    file_put_contents($target, $fixtureSource);
    $patcher = new CatalogDisplayConfigPatcher($fixtureRoot, $storageRoot, PHP_BINARY);
    $audit = $patcher->audit();
    catalogDisplayAssert($audit['changed'] === true, 'File audit must expose a pending change.');

    $fingerprintBlocked = false;
    try {
        $patcher->apply(str_repeat('0', 64));
    } catch (RuntimeException $error) {
        $fingerprintBlocked = strpos($error->getMessage(), 'changed after audit') !== false;
    }
    catalogDisplayAssert($fingerprintBlocked, 'A stale SHA-256 must block the write.');
    catalogDisplayAssert(file_get_contents($target) === $fixtureSource, 'Blocked write must preserve the source byte-for-byte.');

    $applied = $patcher->apply($audit['currentSha256']);
    catalogDisplayAssert($applied['changed'] === true, 'Matching SHA-256 must apply the display config patch.');
    catalogDisplayAssert(
        hash_file('sha256', $target) === $fixturePlan['patchedSha256'],
        'Applied file must match the pure transformation SHA-256.'
    );
    catalogDisplayAssert(is_file((string)$applied['backupFile']), 'Apply must retain a recoverable backup.');
    catalogDisplayAssert(is_file($storageRoot . '/state.json'), 'Apply must persist managed patch state.');
    catalogDisplayAssert($patcher->audit()['managed'] === true, 'Applied file must be recognized as managed.');

    $rolledBack = $patcher->rollback($fixturePlan['patchedSha256']);
    catalogDisplayAssert($rolledBack['changed'] === true, 'Rollback must restore the managed source.');
    catalogDisplayAssert(file_get_contents($target) === $fixtureSource, 'Rollback must restore the exact original bytes.');
    catalogDisplayAssert(!is_file($storageRoot . '/state.json'), 'Rollback must clear managed state.');

    $defaultStorage = $fixtureRoot . '/bitrix/backup/prospektweb.calc/catalog-display-config';
    $defaultPatcher = new CatalogDisplayConfigPatcher($fixtureRoot, null, PHP_BINARY);
    $defaultAudit = $defaultPatcher->audit();
    $defaultApplied = $defaultPatcher->apply($defaultAudit['currentSha256']);
    catalogDisplayAssert(
        strncmp(
            str_replace('\\', '/', (string)$defaultApplied['backupFile']),
            str_replace('\\', '/', $defaultStorage . '/backups/'),
            strlen(str_replace('\\', '/', $defaultStorage . '/backups/'))
        ) === 0,
        'Default backups must live under the protected Bitrix backup directory.'
    );
    catalogDisplayAssert(
        trim((string)file_get_contents($defaultStorage . '/.htaccess')) === 'Deny from all',
        'Default backup storage must deny direct web access.'
    );
    $defaultPatcher->rollback($fixturePlan['patchedSha256']);
    catalogDisplayAssert(
        file_get_contents($target) === $fixtureSource,
        'Default-storage rollback must restore the exact original bytes.'
    );

    echo "Catalog display config patcher tests passed.\n";
} finally {
    catalogDisplayRemoveFixture($fixtureRoot);
}
