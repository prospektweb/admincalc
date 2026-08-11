<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Install/CatalogCalcPropertyMigrationService.php';

use Prospektweb\Calc\Install\CatalogCalcPropertyMigrationService;

function migration_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAILED: {$message}\n");
        exit(1);
    }
}

function migration_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAILED: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$expectedMap = [
    'CALC_METHOD' => 'CALC_PROP_METHOD',
    'CALC_FILLING' => 'CALC_PROP_FILLING',
    'CALC_FORMAT' => 'CALC_PROP_FORMAT',
    'CALC_TYPE_PAPER' => 'CALC_PROP_TYPE_PAPER',
    'CALC_TYPE_BASE' => 'CALC_PROP_TYPE_BASE',
    'CALC_PROTECTION' => 'CALC_PROP_PROTECTION',
    'CALC_ADD_OPTIONS' => 'CALC_PROP_OPTIONS',
    'CALC_BINDING' => 'CALC_PROP_BINDING',
];
migration_same($expectedMap, CatalogCalcPropertyMigrationService::propertyMap(), 'canonical property map');
migration_assert(!isset($expectedMap['CALC_PRESET']), 'CALC_PRESET must remain on products');
migration_assert(!isset($expectedMap['FRONTCALC_CONFIG']), 'FRONTCALC_CONFIG must remain on products');

$sorts = CatalogCalcPropertyMigrationService::propertySorts();
migration_same(10, $sorts['CALC_PROP_VOLUME'] ?? null, 'volume sort');
migration_same(100, $sorts['CALC_PROP_METHOD'] ?? null, 'method sort');
migration_same(110, $sorts['CALC_PROP_COLOR_SCHEME'] ?? null, 'colour scheme sort');
migration_same(120, $sorts['CALC_PROP_TYPE_PAPER'] ?? null, 'paper type sort');
migration_same(160, $sorts['CALC_PROP_FORMAT'] ?? null, 'format sort');
migration_same(330, $sorts['CALC_PROP_OPTIONS'] ?? null, 'options sort');
migration_same(900, $sorts['CALC_STATE_HASH'] ?? null, 'technical state hash sort');
migration_same(
    '210x99',
    CatalogCalcPropertyMigrationService::canonicalTargetXmlId('CALC_FORMAT', '99x210'),
    'legacy portrait format must reuse the canonical offer enum'
);
migration_same(
    '90x50',
    CatalogCalcPropertyMigrationService::canonicalTargetXmlId('CALC_FORMAT', '90x50'),
    'unaliased format XML_ID must remain unchanged'
);
migration_same(
    ['210x99', '90x50'],
    CatalogCalcPropertyMigrationService::canonicalTargetXmlIds(
        'CALC_FORMAT',
        ['99x210', '210x99', '90x50']
    ),
    'value-copy normalization must reuse and deduplicate the canonical format enum'
);

$stage = [
    'offerPropertyCodes' => [],
    'productPropertyCodes' => ['CALC_METHOD', 'TR_CASE'],
    'mappings' => [
        [
            'productValues' => [
                'CALC_METHOD' => ['xmlId' => 'DIGITAL'],
                'TR_CASE' => ['xmlId' => 'NOMINATIVE'],
            ],
            'variantId' => 1083,
            'sourcePath' => 'product.properties.CALC_TYPE_PAPER.VALUE_XML_ID',
        ],
    ],
];
$changed = false;
$rewritten = CatalogCalcPropertyMigrationService::rewriteStructuredReferences($stage, $changed);
migration_assert($changed, 'structured stage mapping must change');
migration_same(['TR_CASE'], $rewritten['productPropertyCodes'] ?? null, 'unrelated product property must remain');
migration_same(['CALC_PROP_METHOD'], $rewritten['offerPropertyCodes'] ?? null, 'migrated property must move to offers');
migration_same(
    ['xmlId' => 'DIGITAL'],
    $rewritten['mappings'][0]['offerValues']['CALC_PROP_METHOD'] ?? null,
    'mapping value must move to offerValues'
);
migration_same(
    ['xmlId' => 'NOMINATIVE'],
    $rewritten['mappings'][0]['productValues']['TR_CASE'] ?? null,
    'non-CALC productValues entry must be preserved'
);
migration_same(
    'offer.properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID',
    $rewritten['mappings'][0]['sourcePath'] ?? null,
    'formula path must move from product to offer'
);

$onlyMigrated = json_encode([
    'offerPropertyCodes' => [],
    'productPropertyCodes' => ['CALC_METHOD'],
    'mappings' => [[
        'productValues' => ['CALC_METHOD' => ['xmlId' => 'OFSET']],
        'variantId' => 1085,
    ]],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$json = CatalogCalcPropertyMigrationService::rewriteJsonReferences((string)$onlyMigrated);
migration_assert(is_string($json), 'JSON mapping must be rewritten');
migration_assert(strpos($json, '"productValues":{}') !== false, 'empty productValues object shape must be retained');
migration_assert(strpos($json, '"CALC_PROP_METHOD"') !== false, 'target offer code must be serialized');

$shapeJson = <<<'JSON'
{"offerPropertyCodes":[],"productPropertyCodes":["CALC_METHOD"],"dimensions":{},"sourceAnnotations":{},"nested":{"numericObject":{"0":"zero","2":"two"},"emptyObject":{},"emptyList":[]},"mappings":[{"productValues":{"CALC_METHOD":{"xmlId":"DIGITAL"}},"offerValues":{},"variantId":1083,"formula":"get ( product , 'properties.CALC_METHOD.VALUE_XML_ID' )"}]}
JSON;
$shapeRewritten = CatalogCalcPropertyMigrationService::rewriteJsonReferences($shapeJson);
migration_assert(is_string($shapeRewritten), 'shape-rich JSON must be rewritten');
$shape = json_decode((string)$shapeRewritten);
migration_assert($shape instanceof stdClass, 'root JSON object shape must remain an object');
migration_assert($shape->dimensions instanceof stdClass, 'dimensions empty object must remain an object');
migration_assert($shape->sourceAnnotations instanceof stdClass, 'annotations empty object must remain an object');
migration_assert($shape->nested->numericObject instanceof stdClass, 'numeric-key object must remain an object');
migration_assert($shape->nested->emptyObject instanceof stdClass, 'nested empty object must remain an object');
migration_assert(is_array($shape->nested->emptyList), 'empty list must remain a list');
migration_assert($shape->mappings[0]->productValues instanceof stdClass, 'empty productValues must remain an object');
migration_assert($shape->mappings[0]->offerValues instanceof stdClass, 'offerValues dictionary must remain an object');
migration_same(
    "get(offer, 'properties.CALC_PROP_METHOD.VALUE_XML_ID')",
    $shape->mappings[0]->formula ?? null,
    'get(product, property) formula syntax must move to offer storage'
);

$formatMapping = [
    'productValues' => ['CALC_FORMAT' => ['xmlId' => '99x210']],
    'offerValues' => ['CALC_PROP_FORMAT' => ['xmlId' => '210x99']],
];
$changed = false;
$formatRewritten = CatalogCalcPropertyMigrationService::rewriteStructuredReferences($formatMapping, $changed);
migration_assert($changed, 'format mapping must move to the offer channel');
migration_same(
    ['xmlId' => '210x99'],
    $formatRewritten['offerValues']['CALC_PROP_FORMAT'] ?? null,
    'structured format aliases must canonicalize before compare/write'
);

$prefixOnly = [
    'direct' => 'product.properties.CALC_METHODICAL.VALUE_XML_ID',
    'formula' => 'get(product, "properties.CALC_METHODICAL.VALUE_XML_ID")',
    'seed_property_code' => 'CALC_METHODICAL',
];
$changed = false;
migration_same(
    $prefixOnly,
    CatalogCalcPropertyMigrationService::rewriteStructuredReferences($prefixOnly, $changed),
    'property-code prefixes must not be mistaken for exact migrated codes'
);
migration_assert(!$changed, 'prefix-only references must not trigger a rewrite');

migration_same(
    'reconcile_base_offer',
    CatalogCalcPropertyMigrationService::baseOfferRetryAction(['marker'], [], 'marker'),
    'marker-owned linked offers must be reconciled on retry'
);
migration_same(
    'create_base_offer',
    CatalogCalcPropertyMigrationService::baseOfferRetryAction(['foreign-inactive'], [], 'marker'),
    'inactive foreign offers must not satisfy the active FrontCalc seed contract'
);
migration_same(
    'skip_foreign_linked_offer',
    CatalogCalcPropertyMigrationService::baseOfferRetryAction(['foreign'], ['foreign'], 'marker'),
    'an active foreign offer may satisfy the base-offer requirement'
);

$conflict = [
    'productValues' => ['CALC_METHOD' => ['xmlId' => 'DIGITAL']],
    'offerValues' => ['CALC_PROP_METHOD' => ['xmlId' => 'OFSET']],
];
$thrown = false;
try {
    $changed = false;
    CatalogCalcPropertyMigrationService::rewriteStructuredReferences($conflict, $changed);
} catch (RuntimeException $error) {
    $thrown = strpos($error->getMessage(), 'Conflicting structured values') !== false;
}
migration_assert($thrown, 'structured value conflicts must never be overwritten');

migration_assert(
    strpos(CatalogCalcPropertyMigrationService::expectedGlobalFormula(12797), 'contains(get(offer,') === 0,
    'multiple options globals must use contains on offer values'
);
migration_assert(
    strpos(CatalogCalcPropertyMigrationService::expectedGlobalFormula(12780), 'CALC_PROP_METHOD') !== false,
    'printing method global must use offer property'
);
$designerFormula = CatalogCalcPropertyMigrationService::expectedGlobalFormula(12793);
foreach (['shyne', 'plake', 'gmund', 'aquarello', 'design-paper'] as $xmlId) {
    migration_assert(strpos($designerFormula, '"' . $xmlId . '"') !== false, 'designer paper formula must include ' . $xmlId);
}

$root = dirname(__DIR__);
$serviceSource = file_get_contents($root . '/lib/Install/CatalogCalcPropertyMigrationService.php');
$toolSource = file_get_contents($root . '/tools/catalog_calc_property_migration.php');
$includeSource = file_get_contents($root . '/include.php');
foreach ([$serviceSource, $toolSource, $includeSource] as $source) {
    migration_assert(is_string($source), 'migration source file must be readable');
}
foreach ([
    'materializeBaseOffers', 'rollbackBaseOffers', 'cutover', 'productsWithoutOffers',
    'BASE_OFFER_MARKER_PREFIX', "'QUANTITY' => 0", "'CAN_BUY_ZERO' => 'N'",
    'CPrice::GetList', 'seed_property_source', "= 'offer'", 'CalculatorTemplateManager',
    'prospektweb.propvalmanager', 'AI_CONTEXT_JSON', 'CALC_STATE_HASH',
    "'DISPLAY_EXPANDED' => 'N'", 'canonicalTargetXmlId',
    'CatalogDisplayConfigPatcher', 'createIndexer', 'continueIndex', 'markAsInvalid',
    '/prospektweb/propvalmanager/property_value_descriptions',
    'source_target_description_conflict', 'divergent_target_description_duplicates',
    'UF_VALUE_ID', 'UF_VALUE_XML_ID', 'UF_VALUE_NAME',
    'publishDescriptionArtifacts', 'PropertyDescriptionJsonExporter',
    'GET_LOCK', 'RELEASE_LOCK', 'MIGRATION_LOCK_NAME',
    'activeOffersByProduct', "'ACTIVE_DATE' => 'Y'", 'activeLinkedOfferCount',
    'already_complete', 'reactivateSourcesAfterFailedCutover', 'assertPreParityStructure',
    'auditOtherPresetConsumers', 'EXPECTED_PRESET_GLOBAL_COUNT', 'globalWritePlans',
    'sourceSchemaSha256', 'rewrittenSchemaSha256', 'AI_CONTEXT_SELECTION_OPTION',
    'verifyMaterializedBaseOffers', 'markerSafety', 'unexpectedProductCalcProperties',
    'migrateProductFrontcalcConfigs', 'FRONTCALC_CONFIG', 'restoreJsonContainerShapes',
    'safeLinkedElementRewritePlans', 'sourceSha256', 'AI_CONTEXT_JSON CAS mismatch',
] as $token) {
    migration_assert(strpos($serviceSource, $token) !== false, 'service contract token missing: ' . $token);
}
foreach ([
    "\$requestMethod !== 'POST'", 'check_bitrix_sessid()', '$USER->IsAdmin()',
    "case 'audit'", "case 'materialize_base_offers'", "case 'execute'", "case 'verify'",
    "case 'cutover'", "case 'rollback_base_offers'", 'expectedFingerprint',
    "case 'apply_semantic_fixes'", "case 'rollback_semantic_fixes'",
    "case 'audit_catalog_display'", "case 'rollback_catalog_display'", 'expectedPatchedSha256',
] as $token) {
    migration_assert(strpos($toolSource, $token) !== false, 'guarded tool token missing: ' . $token);
}
migration_assert(
    strpos($serviceSource, "'DISPLAY_EXPANDED' => 'Y'") === false,
    'migrated target properties must use collapsed list display'
);
migration_assert(
    strpos($serviceSource, '$targetDescription . "\\n\\n"') === false,
    'divergent PropValManager descriptions must be blocked instead of concatenated'
);
migration_assert(
    preg_match('/continueIndex\(0\);\s*\$indexer->endIndex\(\);\s*} catch/s', $serviceSource) === 1,
    'facet index must only be marked complete after successful indexing'
);
migration_assert(
    strpos($serviceSource, 'publishDescriptionArtifacts()')
        > strpos($serviceSource, '$connection->commitTransaction();'),
    'PropValManager JSON must only be published after the DB transaction commits'
);
migration_assert(
    strpos($includeSource, 'CatalogCalcPropertyMigrationService') !== false,
    'migration service must be registered in module autoload'
);
migration_assert(
    strpos($includeSource, 'CatalogDisplayConfigPatcher') !== false,
    'catalog display config patcher must be registered in module autoload'
);

echo "Catalog CALC property migration tests passed\n";
