<?php

namespace Prospektweb\Calc\Install;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * One-off, restart-safe migration of calculator catalogue properties from
 * products to SKU offers.  The source properties are deliberately retained:
 * execute() prepares and verifies the new storage, while cutover() is the only
 * operation allowed to deactivate the product properties.
 */
final class CatalogCalcPropertyMigrationService
{
    public const DEFAULT_PRESET_ID = 12740;

    private const MODULE_ID = 'prospektweb.calc';
    private const SNAPSHOT_SCHEMA = 'prospektweb.calc.catalog-calc-property-migration/v1';
    private const SETTINGS_ID = 12743;
    private const EQUIPMENT_MAPPING_STAGE_ID = 12758;
    private const BASE_OFFER_MARKER_PREFIX = 'prospektweb-calc-migration-base-offer-v1-p';
    private const BASE_OFFER_OPTION = 'catalog_calc_property_migration_base_offers_v1';
    private const MIGRATION_PHASE_OPTION = 'catalog_calc_property_migration_phase_v1';
    private const AI_CONTEXT_SELECTION_OPTION = 'catalog_calc_property_migration_ai_context_selection_v1';
    private const MIGRATION_LOCK_NAME = 'prospektweb.calc.catalog_calc_property_migration_v1';
    private const EXPECTED_PRESET_GLOBAL_COUNT = 37;
    private const EXPECTED_BASE_OFFER_PRODUCTS = [10508, 12779, 13095, 13096];

    /** @var array<string,string> */
    private const PROPERTY_MAP = [
        'CALC_METHOD' => 'CALC_PROP_METHOD',
        'CALC_FILLING' => 'CALC_PROP_FILLING',
        'CALC_FORMAT' => 'CALC_PROP_FORMAT',
        'CALC_TYPE_PAPER' => 'CALC_PROP_TYPE_PAPER',
        'CALC_TYPE_BASE' => 'CALC_PROP_TYPE_BASE',
        'CALC_PROTECTION' => 'CALC_PROP_PROTECTION',
        'CALC_ADD_OPTIONS' => 'CALC_PROP_OPTIONS',
        'CALC_BINDING' => 'CALC_PROP_BINDING',
    ];

    /**
     * Product and offer dictionaries historically used opposite orientation
     * labels for the same finished format.  The offer dictionary is canonical;
     * never create a second semantic value while moving product data.
     *
     * @var array<string,array<string,string>>
     */
    private const ENUM_XML_ID_ALIASES = [
        'CALC_FORMAT' => [
            '99x210' => '210x99',
        ],
    ];

    /** @var array<string,int> */
    private const PROPERTY_SORTS = [
        'CALC_PROP_VOLUME' => 10,
        'CALC_PROP_METHOD' => 100,
        'CALC_PROP_COLOR_SCHEME' => 110,
        'CALC_PROP_BLOCK_COLOR_SCHEME' => 111,
        'CALC_PROP_COVER_COLOR_SCHEME' => 112,
        'CALC_PROP_TYPE_PAPER' => 120,
        'CALC_PROP_TYPE_BASE' => 130,
        'CALC_PROP_DENSITY_PAPER' => 140,
        'CALC_PROP_BLOCK_DENSITY_PAPER' => 141,
        'CALC_PROP_COVER_DENSITY_PAPER' => 142,
        'CALC_PROP_COLOR' => 150,
        'CALC_PROP_FORMAT' => 160,
        'CALC_PROP_FILLING' => 170,
        'CALC_PROP_SHEETS' => 200,
        'CALC_PROP_STRIPS' => 210,
        'CALC_QTY_ITEMS' => 220,
        'CALC_PROP_BINDING' => 230,
        'CALC_PROP_PROTECTION' => 300,
        'CALC_PROP_LAMINATION' => 310,
        'CALC_PROP_LAMINATION_SIDES' => 320,
        'CALC_PROP_OPTIONS' => 330,
        'CALC_STATE_HASH' => 900,
    ];

    /** @var array<int,array{code:string,formula:string}> */
    private const GLOBAL_FORMULAS = [
        12777 => [
            'code' => 'is_roll_lamination',
            'formula' => 'contains(get(offer, "properties.CALC_PROP_PROTECTION.VALUE_XML_ID"), "lamination-rulon")',
        ],
        12780 => [
            'code' => 'is_offset_printing',
            'formula' => 'get(offer, "properties.CALC_PROP_METHOD.VALUE_XML_ID") == "OFSET"',
        ],
        12787 => [
            'code' => 'is_pouch_lamination',
            'formula' => 'contains(get(offer, "properties.CALC_PROP_PROTECTION.VALUE_XML_ID"), "lamination-pocket")',
        ],
        12790 => [
            'code' => 'is_digital_printing',
            'formula' => 'get(offer, "properties.CALC_PROP_METHOD.VALUE_XML_ID") == "DIGITAL"',
        ],
        12791 => [
            'code' => 'is_coated_paper',
            'formula' => 'get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "mel-paper"',
        ],
        12792 => [
            'code' => 'is_offset_paper',
            'formula' => 'get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "vhi-paper"',
        ],
        12793 => [
            'code' => 'is_designer_paper',
            'formula' => 'get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "shyne"'
                . ' || get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "plake"'
                . ' || get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "gmund"'
                . ' || get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "aquarello"'
                . ' || get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "design-paper"',
        ],
        12794 => [
            'code' => 'is_self_adhesive_paper',
            'formula' => 'get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "sticker-paper"',
        ],
        12796 => [
            'code' => 'is_uv_printing',
            'formula' => 'get(offer, "properties.CALC_PROP_METHOD.VALUE_XML_ID") == "UF_PECHAT"',
        ],
        12797 => [
            'code' => 'has_rounded_corners',
            'formula' => 'contains(get(offer, "properties.CALC_PROP_OPTIONS.VALUE_XML_ID"), "round-corners")',
        ],
        12976 => [
            'code' => 'has_holes',
            'formula' => 'contains(get(offer, "properties.CALC_PROP_OPTIONS.VALUE_XML_ID"), "round-holes")',
        ],
        13085 => [
            'code' => 'is_text_filling_printing',
            'formula' => 'get(offer, "properties.CALC_PROP_FILLING.VALUE_XML_ID") == "text"',
        ],
        13093 => [
            'code' => 'is_standart_filling_printing',
            'formula' => '(get(offer, "properties.CALC_PROP_FILLING.VALUE_XML_ID") == "standart")'
                . ' || (get(offer, "properties.CALC_PROP_FILLING.VALUE_XML_ID") != "text")',
        ],
    ];

    /** @var array<string,mixed>|null */
    private $catalogStateCache;

    /** @return array<string,string> */
    public static function propertyMap(): array
    {
        return self::PROPERTY_MAP;
    }

    /** @return array<string,int> */
    public static function propertySorts(): array
    {
        return self::PROPERTY_SORTS;
    }

    /**
     * Keep deprecated-preset breakage fail-closed unless an administrator has
     * explicitly accepted it for the current audited migration fingerprint.
     * The original conflict payload is retained verbatim for recovery/audit;
     * warnings add a stable wrapper type without hiding the source conflict.
     *
     * @param array<int,array<string,mixed>> $consumers
     * @return array{
     *     conflicts:array<int,array<string,mixed>>,
     *     acceptedDeprecatedPresetConsumers:array<int,array<string,mixed>>,
     *     warnings:array<int,array<string,mixed>>
     * }
     */
    public static function classifyDeprecatedPresetConsumers(
        array $consumers,
        bool $allowLegacyPresetBreakage
    ): array {
        $conflicts = [];
        $accepted = [];
        $warnings = [];
        foreach ($consumers as $consumer) {
            $type = (string)($consumer['type'] ?? '');
            $consumerPresetId = (int)($consumer['presetId'] ?? 0);
            $isSourceAssignment = $type === 'migrated_source_used_by_other_preset';
            $eligible = $allowLegacyPresetBreakage
                && in_array($type, [
                    'migrated_source_used_by_other_preset',
                    'other_preset_legacy_reference',
                ], true)
                && $consumerPresetId > 0
                && $consumerPresetId !== self::DEFAULT_PRESET_ID
                && (!$isSourceAssignment
                    || (int)($consumer['migrationPresetId'] ?? 0) === self::DEFAULT_PRESET_ID);
            if (!$eligible) {
                $conflicts[] = $consumer;
                continue;
            }
            $accepted[] = $consumer;
            $warning = $consumer;
            $warning['legacyConflictType'] = $type;
            $warning['type'] = 'deprecated_preset_consumer_explicitly_accepted';
            $warnings[] = $warning;
        }
        return [
            'conflicts' => $conflicts,
            'acceptedDeprecatedPresetConsumers' => $accepted,
            'warnings' => $warnings,
        ];
    }

    public static function canonicalTargetXmlId(string $sourceCode, string $xmlId): string
    {
        $xmlId = trim($xmlId);
        return self::ENUM_XML_ID_ALIASES[$sourceCode][$xmlId] ?? $xmlId;
    }

    /**
     * Existing offer enum labels are canonical when the XML_ID is the same.
     * Treat only typographic whitespace and an optional space before the
     * numeric millimetre suffix as insignificant; wording and measurements
     * must otherwise remain byte-for-byte equivalent after normalization.
     */
    public static function enumValuesEquivalent(string $sourceValue, string $targetValue): bool
    {
        return self::normalizeEnumValue($sourceValue) === self::normalizeEnumValue($targetValue);
    }

    private static function normalizeEnumValue(string $value): string
    {
        $normalized = preg_replace('/[\p{Z}\x{0009}-\x{000D}]+/u', ' ', $value);
        if (!is_string($normalized)) {
            $normalized = $value;
        }
        $normalized = trim($normalized);

        // CALC_FORMAT historically used both "90x50 мм" and "90x50мм".
        // Restrict unit folding to a unit token directly following a digit so
        // substantive text (including other units) cannot be conflated.
        $unitNormalized = preg_replace('/(?<=[0-9]) ?мм(?=\z|[^\p{L}\p{N}_])/iu', 'мм', $normalized);
        return is_string($unitNormalized) ? $unitNormalized : $normalized;
    }

    /** @param string[] $xmlIds @return string[] */
    public static function canonicalTargetXmlIds(string $sourceCode, array $xmlIds): array
    {
        $result = [];
        foreach ($xmlIds as $xmlId) {
            $canonical = self::canonicalTargetXmlId($sourceCode, (string)$xmlId);
            if ($canonical !== '') {
                $result[$canonical] = $canonical;
            }
        }
        return array_values($result);
    }

    /** @param string[] $linkedOfferXmlIds @param string[] $activeLinkedOfferXmlIds */
    public static function baseOfferRetryAction(
        array $linkedOfferXmlIds,
        array $activeLinkedOfferXmlIds,
        string $markerXmlId
    ): string
    {
        if (in_array($markerXmlId, array_map('strval', $linkedOfferXmlIds), true)) {
            return 'reconcile_base_offer';
        }
        return $activeLinkedOfferXmlIds === [] ? 'create_base_offer' : 'skip_foreign_linked_offer';
    }

    /**
     * Move structured product matching clauses to the offer channel while
     * preserving unrelated product properties (for example TR_CASE).
     *
     * @param mixed $value
     * @param bool  $changed
     * @return mixed
     */
    public static function rewriteStructuredReferences($value, bool &$changed)
    {
        if (is_string($value)) {
            $rewritten = self::rewriteReferenceString($value);
            if ($rewritten !== $value) {
                $changed = true;
            }
            return $rewritten;
        }
        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $nested) {
            $result[$key] = self::rewriteStructuredReferences($nested, $changed);
        }

        if (isset($result['seed_property_code']) && is_string($result['seed_property_code'])) {
            $seedCode = $result['seed_property_code'];
            if (isset(self::PROPERTY_MAP[$seedCode])) {
                $result['seed_property_code'] = self::PROPERTY_MAP[$seedCode];
                $result['seed_property_source'] = 'offer';
                $changed = true;
            } elseif (in_array($seedCode, array_values(self::PROPERTY_MAP), true)
                && trim((string)($result['seed_property_source'] ?? 'product')) !== 'offer') {
                $result['seed_property_source'] = 'offer';
                $changed = true;
            }
        }

        if (isset($result['productPropertyCodes']) && is_array($result['productPropertyCodes'])) {
            $offerCodes = isset($result['offerPropertyCodes']) && is_array($result['offerPropertyCodes'])
                ? array_values(array_map('strval', $result['offerPropertyCodes']))
                : [];
            $productCodes = [];
            foreach ($result['productPropertyCodes'] as $rawCode) {
                $code = (string)$rawCode;
                if (isset(self::PROPERTY_MAP[$code])) {
                    $offerCodes[] = self::PROPERTY_MAP[$code];
                    $changed = true;
                } else {
                    $productCodes[] = $code;
                }
            }
            $result['productPropertyCodes'] = array_values(array_unique($productCodes));
            $result['offerPropertyCodes'] = array_values(array_unique($offerCodes));
        }

        if (isset($result['productValues']) && is_array($result['productValues'])) {
            $offerValues = isset($result['offerValues']) && is_array($result['offerValues'])
                ? $result['offerValues']
                : [];
            $productValues = $result['productValues'];
            foreach (self::PROPERTY_MAP as $sourceCode => $targetCode) {
                if (!array_key_exists($sourceCode, $productValues)) {
                    continue;
                }
                $sourceValue = self::canonicalizeStructuredPropertyValue(
                    $sourceCode,
                    $productValues[$sourceCode]
                );
                $targetValue = array_key_exists($targetCode, $offerValues)
                    ? self::canonicalizeStructuredPropertyValue($sourceCode, $offerValues[$targetCode])
                    : null;
                if (array_key_exists($targetCode, $offerValues)
                    && self::canonicalJson($targetValue) !== self::canonicalJson($sourceValue)) {
                    throw new \RuntimeException(
                        'Conflicting structured values for ' . $sourceCode . ' and ' . $targetCode
                    );
                }
                $offerValues[$targetCode] = $sourceValue;
                unset($productValues[$sourceCode]);
                $changed = true;
            }
            if ($productValues === []) {
                // Keep the explicit object-shaped field: calcconfig persists
                // this mapping shape and hashes it as part of the stage state.
                $result['productValues'] = new \stdClass();
            } else {
                $result['productValues'] = $productValues;
            }
            if ($offerValues !== []) {
                $result['offerValues'] = $offerValues;
            }
        }

        return $result;
    }

    private static function rewriteReferenceString(string $value): string
    {
        $rewritten = $value;
        foreach (self::PROPERTY_MAP as $sourceCode => $targetCode) {
            $rewritten = (string)preg_replace(
                '/\bproduct\.properties\.' . preg_quote($sourceCode, '/') . '(?![A-Za-z0-9_])/',
                'offer.properties.' . $targetCode,
                $rewritten
            );
            $rewritten = (string)preg_replace(
                '/\bproductProperties:' . preg_quote($sourceCode, '/') . '(?![A-Za-z0-9_])/',
                'offerProperties:' . $targetCode,
                $rewritten
            );
            $pattern = '/\bget\s*\(\s*product\s*,\s*([\'\"])properties\.'
                . preg_quote($sourceCode, '/') . '((?:\.[^\'\"]*)?)\1\s*\)/i';
            $rewritten = (string)preg_replace_callback(
                $pattern,
                static function (array $matches) use ($targetCode): string {
                    $quote = (string)$matches[1];
                    return 'get(offer, ' . $quote . 'properties.' . $targetCode
                        . (string)$matches[2] . $quote . ')';
                },
                $rewritten
            );
        }
        return $rewritten;
    }

    private static function stringHasLegacyReference(string $value): bool
    {
        foreach (array_keys(self::PROPERTY_MAP) as $sourceCode) {
            if (preg_match(
                '/\bproduct\.properties\.' . preg_quote($sourceCode, '/') . '(?![A-Za-z0-9_])/',
                $value
            ) === 1) {
                return true;
            }
            if (preg_match(
                '/\bget\s*\(\s*product\s*,\s*([\'\"])properties\.'
                    . preg_quote($sourceCode, '/') . '(?:\.[^\'\"]*)?\1\s*\)/i',
                $value
            ) === 1) {
                return true;
            }
            if (preg_match(
                '/\bproductProperties:' . preg_quote($sourceCode, '/') . '(?![A-Za-z0-9_])/',
                $value
            ) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @param mixed $value @return mixed */
    private static function canonicalizeStructuredPropertyValue(string $sourceCode, $value)
    {
        if (is_string($value)) {
            return self::canonicalTargetXmlId($sourceCode, $value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $nested) {
            $result[$key] = self::canonicalizeStructuredPropertyValue($sourceCode, $nested);
        }
        return $result;
    }

    /**
     * Reapply the object/list container shape from a non-associative JSON
     * decode after values were deliberately rewritten through associative
     * arrays.  Newly introduced dictionaries are objects; newly introduced
     * sequential collections remain arrays.
     *
     * @param mixed $rewritten
     * @param mixed $originalShape
     * @return mixed
     */
    public static function restoreJsonContainerShapes($rewritten, $originalShape)
    {
        if ($originalShape instanceof \stdClass) {
            $rewrittenValues = $rewritten instanceof \stdClass
                ? get_object_vars($rewritten)
                : (is_array($rewritten) ? $rewritten : []);
            $originalValues = get_object_vars($originalShape);
            $result = new \stdClass();
            foreach ($rewrittenValues as $key => $nested) {
                $result->{$key} = array_key_exists($key, $originalValues)
                    ? self::restoreJsonContainerShapes($nested, $originalValues[$key])
                    : self::naturalJsonContainerShape($nested);
            }
            return $result;
        }
        if (is_array($originalShape)) {
            if ($rewritten instanceof \stdClass) {
                return self::naturalJsonContainerShape($rewritten);
            }
            if (!is_array($rewritten)) {
                return $rewritten;
            }
            $result = [];
            foreach ($rewritten as $key => $nested) {
                $result[$key] = array_key_exists($key, $originalShape)
                    ? self::restoreJsonContainerShapes($nested, $originalShape[$key])
                    : self::naturalJsonContainerShape($nested);
            }
            return $result;
        }
        return self::naturalJsonContainerShape($rewritten);
    }

    /** @param mixed $value @return mixed */
    private static function naturalJsonContainerShape($value)
    {
        if ($value instanceof \stdClass) {
            $result = new \stdClass();
            foreach (get_object_vars($value) as $key => $nested) {
                $result->{$key} = self::naturalJsonContainerShape($nested);
            }
            return $result;
        }
        if (!is_array($value)) {
            return $value;
        }
        if (self::isSequentialList($value)) {
            return array_map([self::class, 'naturalJsonContainerShape'], $value);
        }
        $result = new \stdClass();
        foreach ($value as $key => $nested) {
            $result->{$key} = self::naturalJsonContainerShape($nested);
        }
        return $result;
    }

    private static function isSequentialList(array $value): bool
    {
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    public static function rewriteJsonReferences(string $raw): ?string
    {
        $normalized = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = json_decode($normalized, true);
        $originalShape = json_decode($normalized, false);
        if (!is_array($decoded)) {
            return null;
        }
        $changed = false;
        $rewritten = self::rewriteStructuredReferences($decoded, $changed);
        if (!$changed) {
            return null;
        }
        $shapeSafe = self::restoreJsonContainerShapes($rewritten, $originalShape);
        $json = json_encode($shapeSafe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to serialize migrated property references');
        }
        return $json;
    }

    public static function expectedGlobalFormula(int $elementId): string
    {
        return (string)(self::GLOBAL_FORMULAS[$elementId]['formula'] ?? '');
    }

    /** @return array<string,mixed> */
    public function audit(
        int $presetId = self::DEFAULT_PRESET_ID,
        bool $applySemanticFixes = false,
        bool $allowLegacyPresetBreakage = false
    ): array {
        $this->assertEnvironment($presetId);
        $this->resetCaches();
        $state = $this->catalogState();
        $conflicts = [];
        $warnings = [];
        $properties = [];

        $unexpectedProductCalcProperties = $this->unexpectedProductCalcProperties(
            (int)$state['productIblockId']
        );
        foreach ($unexpectedProductCalcProperties as $property) {
            $conflicts[] = ['type' => 'unexpected_product_calc_property'] + $property;
        }

        foreach (self::PROPERTY_MAP as $sourceCode => $targetCode) {
            $source = $this->propertyByCode((int)$state['productIblockId'], $sourceCode);
            $target = $this->propertyByCode((int)$state['offerIblockId'], $targetCode);
            if (!$source) {
                $conflicts[] = ['type' => 'missing_source_property', 'sourceCode' => $sourceCode];
                continue;
            }
            if ((string)$source['PROPERTY_TYPE'] !== 'L') {
                $conflicts[] = [
                    'type' => 'unsupported_source_property_type',
                    'sourceCode' => $sourceCode,
                    'propertyType' => (string)$source['PROPERTY_TYPE'],
                ];
                continue;
            }
            if ($target && ((string)$target['PROPERTY_TYPE'] !== 'L'
                || (string)$target['MULTIPLE'] !== (string)$source['MULTIPLE'])) {
                $conflicts[] = [
                    'type' => 'target_definition_mismatch',
                    'sourceCode' => $sourceCode,
                    'targetCode' => $targetCode,
                ];
            }
            $sourceEnums = $this->enumsByXmlId((int)$source['ID'], $conflicts, $sourceCode);
            $targetEnums = $target
                ? $this->enumsByXmlId((int)$target['ID'], $conflicts, $targetCode)
                : [];
            $expectedTargetXmlIds = [];
            foreach ($sourceEnums as $xmlId => $enum) {
                $canonicalXmlId = self::canonicalTargetXmlId($sourceCode, (string)$xmlId);
                $expectedTargetXmlIds[$canonicalXmlId] = true;
                $isAlias = $canonicalXmlId !== (string)$xmlId;
                if ($isAlias && isset($targetEnums[$xmlId])) {
                    $conflicts[] = [
                        'type' => 'target_enum_semantic_alias_duplicate',
                        'sourceCode' => $sourceCode,
                        'targetCode' => $targetCode,
                        'aliasXmlId' => $xmlId,
                        'canonicalXmlId' => $canonicalXmlId,
                    ];
                }
                if (isset($targetEnums[$canonicalXmlId]) && !$isAlias
                    && !self::enumValuesEquivalent(
                        (string)$enum['VALUE'],
                        (string)$targetEnums[$canonicalXmlId]['VALUE']
                    )) {
                    $conflicts[] = [
                        'type' => 'enum_value_mismatch',
                        'sourceCode' => $sourceCode,
                        'targetCode' => $targetCode,
                        'xmlId' => $canonicalXmlId,
                        'sourceValue' => (string)$enum['VALUE'],
                        'targetValue' => (string)$targetEnums[$canonicalXmlId]['VALUE'],
                    ];
                }
            }
            $properties[] = [
                'sourceCode' => $sourceCode,
                'sourceId' => (int)$source['ID'],
                'sourceActive' => (string)$source['ACTIVE'],
                'targetCode' => $targetCode,
                'targetId' => (int)($target['ID'] ?? 0),
                'targetActive' => (string)($target['ACTIVE'] ?? ''),
                'sourceEnumCount' => count($sourceEnums),
                'targetEnumCount' => count($targetEnums),
                'missingTargetEnumCount' => count(array_diff_key($expectedTargetXmlIds, $targetEnums)),
                'expectedSort' => self::PROPERTY_SORTS[$targetCode] ?? 500,
            ];
        }

        foreach ((array)$state['valueConflicts'] as $conflict) {
            $conflicts[] = $conflict;
        }
        if (!empty($state['productsWithoutOffers'])) {
            $warnings[] = [
                'type' => 'cutover_blocked_products_without_offers',
                'count' => count($state['productsWithoutOffers']),
                'productIds' => array_values($state['productsWithoutOffers']),
            ];
        }
        if ((int)$state['unlinkedOfferCount'] > 0) {
            $warnings[] = [
                'type' => 'unlinked_offers_preserved',
                'count' => (int)$state['unlinkedOfferCount'],
            ];
        }
        $baseOfferPlan = $this->baseOfferPlan($state);
        $baseOfferVerification = $this->verifyMaterializedBaseOffers();
        foreach ($baseOfferPlan as $baseOffer) {
            if (in_array(($baseOffer['action'] ?? ''), ['create_base_offer', 'reconcile_base_offer'], true)
                && (int)($baseOffer['productPriceCount'] ?? 0) > 0) {
                $conflicts[] = [
                    'type' => 'base_product_has_prices',
                    'productId' => (int)$baseOffer['productId'],
                    'priceCount' => (int)$baseOffer['productPriceCount'],
                ];
            }
        }

        $acceptedDeprecatedPresetConsumers = [];
        $sourcePresetConsumers = $this->auditSourcePresetConsumers(
            $state,
            $presetId,
            $allowLegacyPresetBreakage
        );
        foreach ($sourcePresetConsumers['conflicts'] as $conflict) {
            $conflicts[] = $conflict;
        }
        foreach ($sourcePresetConsumers['warnings'] as $warning) {
            $warnings[] = $warning;
        }
        foreach ($sourcePresetConsumers['acceptedDeprecatedPresetConsumers'] as $consumer) {
            $acceptedDeprecatedPresetConsumers[] = $consumer;
        }
        $otherPresetConsumers = $this->auditOtherPresetConsumers(
            $presetId,
            $allowLegacyPresetBreakage
        );
        foreach ($otherPresetConsumers['conflicts'] as $conflict) {
            $conflicts[] = $conflict;
        }
        foreach ($otherPresetConsumers['warnings'] as $warning) {
            $warnings[] = $warning;
        }
        foreach ($otherPresetConsumers['acceptedDeprecatedPresetConsumers'] as $consumer) {
            $acceptedDeprecatedPresetConsumers[] = $consumer;
        }

        $presetAudit = $this->auditPresetRefactor($presetId, $applySemanticFixes);
        foreach ($presetAudit['conflicts'] as $conflict) {
            $conflicts[] = $conflict;
        }
        foreach ($presetAudit['warnings'] as $warning) {
            $warnings[] = $warning;
        }
        $descriptionAudit = $this->auditDescriptions();
        foreach ((array)($descriptionAudit['conflicts'] ?? []) as $conflict) {
            $conflicts[] = $conflict;
        }
        foreach ((array)($descriptionAudit['warnings'] ?? []) as $warning) {
            $warnings[] = $warning;
        }
        $familyAudit = $this->auditFrontcalcFamilies();
        foreach ($familyAudit['conflicts'] as $conflict) {
            $conflicts[] = $conflict;
        }
        $productFrontcalcAudit = $this->auditProductFrontcalcConfigs();
        foreach ($productFrontcalcAudit['conflicts'] as $conflict) {
            $conflicts[] = $conflict;
        }
        $displayAudit = $this->auditCatalogDisplayConfig();
        if (($displayAudit['status'] ?? '') !== 'ok') {
            $conflicts[] = [
                'type' => 'catalog_display_config_unavailable',
                'message' => (string)($displayAudit['message'] ?? 'Unknown catalog display configuration error'),
            ];
        }

        $snapshot = $this->buildSnapshotPayload(
            $presetId,
            $displayAudit,
            $allowLegacyPresetBreakage
        );
        $snapshot['intent'] = [
            'applySemanticFixes' => $applySemanticFixes,
            'allowLegacyPresetBreakage' => $allowLegacyPresetBreakage,
        ];
        $fingerprint = 'sha256:' . hash('sha256', self::canonicalJson($snapshot));

        return [
            'status' => $conflicts === [] ? 'ok' : 'blocked',
            'phase' => 'audit',
            'presetId' => $presetId,
            'migrationPhase' => $this->migrationPhase(),
            'productIblockId' => (int)$state['productIblockId'],
            'offerIblockId' => (int)$state['offerIblockId'],
            'fingerprint' => $fingerprint,
            'properties' => $properties,
            'unexpectedProductCalcProperties' => $unexpectedProductCalcProperties,
            'transferCounts' => $state['transferCounts'],
            'linkedOfferCount' => (int)$state['linkedOfferCount'],
            'activeLinkedOfferCount' => (int)$state['activeLinkedOfferCount'],
            'unlinkedOfferCount' => (int)$state['unlinkedOfferCount'],
            'productsWithoutOffers' => array_values($state['productsWithoutOffers']),
            'baseOfferPlan' => $baseOfferPlan,
            'baseOfferVerification' => $baseOfferVerification,
            'baseOfferMarkerOption' => (string)\Bitrix\Main\Config\Option::get(
                self::MODULE_ID,
                self::BASE_OFFER_OPTION,
                ''
            ),
            'sourcePresetConsumers' => $sourcePresetConsumers,
            'otherPresetConsumers' => $otherPresetConsumers,
            'allowLegacyPresetBreakage' => $allowLegacyPresetBreakage,
            'acceptedDeprecatedPresetConsumers' => $acceptedDeprecatedPresetConsumers,
            'cutoverReady' => $conflicts === [] && $state['productsWithoutOffers'] === [],
            'presetRefactor' => $presetAudit['summary'],
            'applySemanticFixes' => $applySemanticFixes,
            'descriptions' => $descriptionAudit,
            'frontcalcFamilies' => $familyAudit['summary'],
            'productFrontcalcConfigs' => $productFrontcalcAudit,
            'catalogDisplayConfig' => $displayAudit,
            'conflicts' => $conflicts,
            'warnings' => $warnings,
        ];
    }

    /** @return array<string,mixed> */
    public function snapshot(
        int $presetId = self::DEFAULT_PRESET_ID,
        bool $applySemanticFixes = false,
        bool $allowLegacyPresetBreakage = false
    ): array {
        $audit = $this->audit($presetId, $applySemanticFixes, $allowLegacyPresetBreakage);
        $payload = $this->buildSnapshotPayload(
            $presetId,
            (array)($audit['catalogDisplayConfig'] ?? []),
            $allowLegacyPresetBreakage
        );
        $payload['intent'] = [
            'applySemanticFixes' => $applySemanticFixes,
            'allowLegacyPresetBreakage' => $allowLegacyPresetBreakage,
        ];
        $payload['fingerprint'] = $audit['fingerprint'];
        return $this->writeSnapshot($payload);
    }

    /** @return array<string,mixed> */
    public function auditCatalogDisplay(int $presetId = self::DEFAULT_PRESET_ID): array
    {
        $this->assertEnvironment($presetId);
        return $this->auditCatalogDisplayConfig();
    }

    /** @return array<string,mixed> */
    public function rollbackCatalogDisplay(
        int $presetId,
        string $expectedPatchedSha256
    ): array {
        return $this->withMigrationLock(function () use ($presetId, $expectedPatchedSha256): array {
            return $this->rollbackCatalogDisplayUnlocked($presetId, $expectedPatchedSha256);
        });
    }

    /** @return array<string,mixed> */
    private function rollbackCatalogDisplayUnlocked(
        int $presetId,
        string $expectedPatchedSha256
    ): array {
        $this->assertEnvironment($presetId);
        $this->assertAllSourcesActive('Catalog display rollback');
        if ($this->migrationPhase() === 'complete') {
            throw new \RuntimeException('Catalog display rollback is blocked after source cutover');
        }
        $rollback = (new CatalogDisplayConfigPatcher((string)Application::getDocumentRoot()))
            ->rollback($expectedPatchedSha256);
        return [
            'status' => 'ok',
            'phase' => 'rollback_catalog_display',
            'catalogDisplayConfig' => $rollback,
            'catalogRefresh' => $this->refreshCatalogSurfaces(),
        ];
    }

    /** @return array<string,mixed> */
    public function execute(
        int $presetId,
        string $expectedFingerprint,
        bool $applySemanticFixes = false,
        bool $allowLegacyPresetBreakage = false
    ): array {
        return $this->withMigrationLock(function () use (
            $presetId,
            $expectedFingerprint,
            $applySemanticFixes,
            $allowLegacyPresetBreakage
        ): array {
            return $this->executeUnlocked(
                $presetId,
                $expectedFingerprint,
                $applySemanticFixes,
                $allowLegacyPresetBreakage
            );
        });
    }

    /** @return array<string,mixed> */
    private function executeUnlocked(
        int $presetId,
        string $expectedFingerprint,
        bool $applySemanticFixes = false,
        bool $allowLegacyPresetBreakage = false
    ): array {
        if ($applySemanticFixes) {
            throw new \InvalidArgumentException(
                'Semantic fixes require the separate apply_semantic_fixes phase and a new audited fingerprint'
            );
        }
        $audit = $this->audit($presetId, $applySemanticFixes, $allowLegacyPresetBreakage);
        $this->assertFingerprint($expectedFingerprint, (string)$audit['fingerprint']);
        if ($audit['conflicts'] !== []) {
            throw new \RuntimeException('Migration audit contains blocking conflicts');
        }
        $snapshot = $this->snapshot($presetId, $applySemanticFixes, $allowLegacyPresetBreakage);
        $audit = $this->assertFreshAudit(
            $presetId,
            $expectedFingerprint,
            $applySemanticFixes,
            true,
            $allowLegacyPresetBreakage
        );
        if (!in_array($this->migrationPhase(), ['materialized', 'parity_started', 'parity_complete'], true)) {
            throw new \RuntimeException('Execute requires a completed materialize_base_offers phase');
        }
        $baseOfferVerification = $this->verifyMaterializedBaseOffers();
        if ($baseOfferVerification['errors'] !== []) {
            throw new \RuntimeException(
                'Execute is blocked by base offer verification: '
                . self::canonicalJson($baseOfferVerification['errors'])
            );
        }
        $this->setMigrationPhase('parity_started');
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $this->ensureTargetProperties();
            $this->copyProductValuesToOffers();
            $this->migrateDescriptions();
            $this->applyPresetRefactor(
                $presetId,
                false,
                (array)($audit['presetRefactor']['globalWritePlans'] ?? []),
                (array)($audit['presetRefactor']['safeLinkedElementRewritePlans'] ?? []),
                (string)($audit['presetRefactor']['aiContextPlan']['sourceSha256'] ?? '')
            );
            $this->migrateProductFrontcalcConfigs(
                (array)($audit['productFrontcalcConfigs']['plans'] ?? [])
            );
            $this->updateFrontcalcFamilies(
                (array)($audit['frontcalcFamilies']['templatesToUpdate'] ?? [])
            );
            $connection->commitTransaction();
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            try {
                $this->clearDescriptionCache();
            } catch (\Throwable $ignored) {
                // Preserve the migration failure; cache clearing is a
                // best-effort rollback hygiene step only.
            }
            $this->resetCaches();
            throw $error;
        }
        $descriptionPublication = $this->publishDescriptionArtifacts();
        $displayPatch = $this->applyCatalogDisplayConfig((array)($audit['catalogDisplayConfig'] ?? []));
        $refresh = $this->refreshCatalogSurfaces();
        $this->resetCaches();
        $verification = $this->verify(
            $presetId,
            $applySemanticFixes,
            $allowLegacyPresetBreakage
        );
        if ($verification['errors'] !== []) {
            throw new \RuntimeException('Post-migration verification failed: ' . self::canonicalJson($verification['errors']));
        }
        $this->setMigrationPhase('parity_complete');
        return [
            'status' => empty($verification['cutoverReady']) ? 'migrated_pending_cutover' : 'ready_for_cutover',
            'phase' => 'execute',
            'snapshot' => $snapshot,
            'propertyValueDescriptions' => $descriptionPublication,
            'catalogDisplayConfig' => $displayPatch,
            'catalogRefresh' => $refresh,
            'verification' => $verification,
        ];
    }

    /** @return array<string,mixed> */
    public function applySemanticFixes(
        int $presetId,
        string $expectedFingerprint,
        bool $allowLegacyPresetBreakage = false
    ): array {
        return $this->withMigrationLock(function () use (
            $presetId,
            $expectedFingerprint,
            $allowLegacyPresetBreakage
        ): array {
            return $this->applySemanticFixesUnlocked(
                $presetId,
                $expectedFingerprint,
                $allowLegacyPresetBreakage
            );
        });
    }

    /** @return array<string,mixed> */
    private function applySemanticFixesUnlocked(
        int $presetId,
        string $expectedFingerprint,
        bool $allowLegacyPresetBreakage = false
    ): array {
        $audit = $this->audit($presetId, true, $allowLegacyPresetBreakage);
        $this->assertFingerprint($expectedFingerprint, (string)$audit['fingerprint']);
        if ($audit['conflicts'] !== []) {
            throw new \RuntimeException('Migration audit contains blocking conflicts');
        }
        $parityVerification = $this->verify($presetId, false, $allowLegacyPresetBreakage);
        if ($parityVerification['errors'] !== []) {
            throw new \RuntimeException('Parity migration must be verified before semantic fixes');
        }
        $snapshot = $this->snapshot($presetId, true, $allowLegacyPresetBreakage);
        $this->assertFreshAudit($presetId, $expectedFingerprint, true, true, $allowLegacyPresetBreakage);
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $this->writeSemanticFormulas(
                true,
                (array)($audit['presetRefactor']['globalWritePlans'] ?? [])
            );
            $connection->commitTransaction();
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw $error;
        }
        $verification = $this->verify($presetId, true, $allowLegacyPresetBreakage);
        if ($verification['errors'] !== []) {
            throw new \RuntimeException('Semantic fix verification failed');
        }
        return [
            'status' => 'ok',
            'phase' => 'apply_semantic_fixes',
            'snapshot' => $snapshot,
            'verification' => $verification,
        ];
    }

    /** @return array<string,mixed> */
    public function rollbackSemanticFixes(
        int $presetId,
        string $expectedFingerprint,
        bool $allowLegacyPresetBreakage = false
    ): array {
        return $this->withMigrationLock(function () use (
            $presetId,
            $expectedFingerprint,
            $allowLegacyPresetBreakage
        ): array {
            return $this->rollbackSemanticFixesUnlocked(
                $presetId,
                $expectedFingerprint,
                $allowLegacyPresetBreakage
            );
        });
    }

    /** @return array<string,mixed> */
    private function rollbackSemanticFixesUnlocked(
        int $presetId,
        string $expectedFingerprint,
        bool $allowLegacyPresetBreakage = false
    ): array {
        $audit = $this->audit($presetId, true, $allowLegacyPresetBreakage);
        $this->assertFingerprint($expectedFingerprint, (string)$audit['fingerprint']);
        $semanticVerification = $this->verify($presetId, true, $allowLegacyPresetBreakage);
        if ($semanticVerification['errors'] !== []) {
            throw new \RuntimeException('Semantic fixes are not in their verified state');
        }
        $snapshot = $this->snapshot($presetId, true, $allowLegacyPresetBreakage);
        $this->assertFreshAudit($presetId, $expectedFingerprint, true, true, $allowLegacyPresetBreakage);
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $this->writeSemanticFormulas(
                false,
                (array)($audit['presetRefactor']['globalWritePlans'] ?? [])
            );
            $connection->commitTransaction();
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw $error;
        }
        $verification = $this->verify($presetId, false, $allowLegacyPresetBreakage);
        if ($verification['errors'] !== []) {
            throw new \RuntimeException('Semantic rollback verification failed');
        }
        return [
            'status' => 'ok',
            'phase' => 'rollback_semantic_fixes',
            'snapshot' => $snapshot,
            'verification' => $verification,
        ];
    }

    /** @return array<string,mixed> */
    public function materializeBaseOffers(
        int $presetId,
        string $expectedFingerprint,
        bool $allowLegacyPresetBreakage = false
    ): array {
        return $this->withMigrationLock(function () use (
            $presetId,
            $expectedFingerprint,
            $allowLegacyPresetBreakage
        ): array {
            return $this->materializeBaseOffersUnlocked(
                $presetId,
                $expectedFingerprint,
                $allowLegacyPresetBreakage
            );
        });
    }

    /** @return array<string,mixed> */
    private function materializeBaseOffersUnlocked(
        int $presetId,
        string $expectedFingerprint,
        bool $allowLegacyPresetBreakage = false
    ): array {
        $audit = $this->audit($presetId, false, $allowLegacyPresetBreakage);
        $this->assertFingerprint($expectedFingerprint, (string)$audit['fingerprint']);
        if ($audit['conflicts'] !== []) {
            throw new \RuntimeException('Migration audit contains blocking conflicts');
        }
        if (!in_array($this->migrationPhase(), ['', 'rolled_back', 'materialized'], true)) {
            throw new \RuntimeException('Base offers cannot be materialized after parity refactor has started');
        }
        $this->assertPreParityStructure($presetId, 'Base offer materialization');
        $state = $this->catalogState();
        $unknown = array_values(array_diff(
            array_values($state['productsWithoutOffers']),
            self::EXPECTED_BASE_OFFER_PRODUCTS
        ));
        if ($unknown !== []) {
            throw new \RuntimeException('Unexpected products without offers: ' . implode(',', $unknown));
        }
        $snapshot = $this->snapshot($presetId, false, $allowLegacyPresetBreakage);
        $this->assertFreshAudit($presetId, $expectedFingerprint, false, true, $allowLegacyPresetBreakage);
        $created = [];
        $reconciled = [];
        $skipped = [];
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $this->ensureTargetProperties();
            $this->resetCaches();
            foreach (self::EXPECTED_BASE_OFFER_PRODUCTS as $productId) {
                $freshState = $this->catalogState();
                $linkedOfferIds = array_values(array_map(
                    'intval',
                    (array)($freshState['offersByProduct'][$productId] ?? [])
                ));
                $markerXmlId = self::BASE_OFFER_MARKER_PREFIX . $productId;
                $linkedOfferXmlIds = array_values(array_map(
                    static fn(int $offerId): string => (string)($freshState['offerXmlIds'][$offerId] ?? ''),
                    $linkedOfferIds
                ));
                $activeLinkedOfferIds = array_values(array_map(
                    'intval',
                    (array)($freshState['activeOffersByProduct'][$productId] ?? [])
                ));
                $activeLinkedOfferXmlIds = array_values(array_map(
                    static fn(int $offerId): string => (string)($freshState['offerXmlIds'][$offerId] ?? ''),
                    $activeLinkedOfferIds
                ));
                $retryAction = self::baseOfferRetryAction(
                    $linkedOfferXmlIds,
                    $activeLinkedOfferXmlIds,
                    $markerXmlId
                );
                if ($retryAction === 'skip_foreign_linked_offer') {
                    $skipped[] = [
                        'productId' => $productId,
                        'reason' => 'foreign_linked_offer_exists',
                        'offerIds' => $linkedOfferIds,
                    ];
                    continue;
                }
                if (empty($freshState['productValues'][$productId])) {
                    throw new \RuntimeException('Expected base product has no source CALC values: #' . $productId);
                }
                $offerId = $this->createBaseOffer($productId, $freshState['productValues'][$productId]);
                $resultRow = ['productId' => $productId, 'offerId' => $offerId];
                if ($retryAction === 'reconcile_base_offer') {
                    $reconciled[] = $resultRow;
                } else {
                    $created[] = $resultRow;
                }
                $this->rememberCreatedBaseOffer($productId, $offerId);
                $this->resetCaches();
            }
            $connection->commitTransaction();
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            $this->resetCaches();
            throw $error;
        }
        $this->resetCaches();
        $verification = $this->verifyMaterializedBaseOffers();
        if ($verification['errors'] !== []) {
            throw new \RuntimeException('Base offer verification failed: ' . self::canonicalJson($verification['errors']));
        }
        $this->setMigrationPhase('materialized');
        return [
            'status' => 'ok',
            'phase' => 'materialize_base_offers',
            'snapshot' => $snapshot,
            'created' => $created,
            'reconciled' => $reconciled,
            'skipped' => $skipped,
            'verification' => $verification,
        ];
    }

    /** @return array<string,mixed> */
    public function rollbackBaseOffers(
        int $presetId,
        string $expectedFingerprint,
        bool $allowLegacyPresetBreakage = false
    ): array {
        return $this->withMigrationLock(function () use (
            $presetId,
            $expectedFingerprint,
            $allowLegacyPresetBreakage
        ): array {
            return $this->rollbackBaseOffersUnlocked(
                $presetId,
                $expectedFingerprint,
                $allowLegacyPresetBreakage
            );
        });
    }

    /** @return array<string,mixed> */
    private function rollbackBaseOffersUnlocked(
        int $presetId,
        string $expectedFingerprint,
        bool $allowLegacyPresetBreakage = false
    ): array {
        $audit = $this->audit($presetId, false, $allowLegacyPresetBreakage);
        $this->assertFingerprint($expectedFingerprint, (string)$audit['fingerprint']);
        $this->assertAllSourcesActive('Base offer rollback');
        if ($this->migrationPhase() !== 'materialized') {
            throw new \RuntimeException('Base offer rollback is allowed only before parity refactor');
        }
        $this->assertPreParityStructure($presetId, 'Base offer rollback');
        $snapshot = $this->snapshot($presetId, false, $allowLegacyPresetBreakage);
        $this->assertFreshAudit($presetId, $expectedFingerprint, false, false, $allowLegacyPresetBreakage);
        $markers = $this->createdBaseOfferMarkers();
        $rolledBack = [];
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $offerIblockId = (new ConfigManager())->getSkuIblockId();
            foreach ($markers as $productId => $marker) {
                $offerId = (int)($marker['offerId'] ?? 0);
                if ($offerId <= 0) {
                    continue;
                }
                $element = \CIBlockElement::GetList(
                    [],
                    ['ID' => $offerId, 'IBLOCK_ID' => $offerIblockId],
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'XML_ID', 'ACTIVE']
                )->Fetch();
                if (!$element
                    || (string)($element['XML_ID'] ?? '') !== self::BASE_OFFER_MARKER_PREFIX . (int)$productId) {
                    continue;
                }
                $api = new \CIBlockElement();
                if (!$api->Update($offerId, ['ACTIVE' => 'N'])) {
                    throw new \RuntimeException('Unable to deactivate marker-owned offer #' . $offerId);
                }
                \CIBlockElement::SetPropertyValuesEx($offerId, $offerIblockId, ['CML2_LINK' => false]);
                $rolledBack[] = ['productId' => (int)$productId, 'offerId' => $offerId];
            }
            \Bitrix\Main\Config\Option::set(self::MODULE_ID, self::BASE_OFFER_OPTION, json_encode([
                'version' => 1,
                'status' => 'rolled_back',
                'offers' => $markers,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $connection->commitTransaction();
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw $error;
        }
        $this->resetCaches();
        $this->setMigrationPhase('rolled_back');
        return [
            'status' => 'ok',
            'phase' => 'rollback_base_offers',
            'snapshot' => $snapshot,
            'rolledBack' => $rolledBack,
        ];
    }

    /** @return array<string,mixed> */
    public function cutover(
        int $presetId,
        string $expectedFingerprint,
        bool $requireSemanticFixes = false,
        bool $allowLegacyPresetBreakage = false
    ): array {
        return $this->withMigrationLock(function () use (
            $presetId,
            $expectedFingerprint,
            $requireSemanticFixes,
            $allowLegacyPresetBreakage
        ): array {
            return $this->cutoverUnlocked(
                $presetId,
                $expectedFingerprint,
                $requireSemanticFixes,
                $allowLegacyPresetBreakage
            );
        });
    }

    /** @return array<string,mixed> */
    private function cutoverUnlocked(
        int $presetId,
        string $expectedFingerprint,
        bool $requireSemanticFixes = false,
        bool $allowLegacyPresetBreakage = false
    ): array {
        $audit = $this->audit(
            $presetId,
            $requireSemanticFixes,
            $allowLegacyPresetBreakage
        );
        $this->assertFingerprint($expectedFingerprint, (string)$audit['fingerprint']);
        if ($audit['conflicts'] !== []) {
            throw new \RuntimeException(
                'Source deactivation audit contains blocking conflicts: ' . self::canonicalJson($audit['conflicts'])
            );
        }
        $verification = $this->verify(
            $presetId,
            $requireSemanticFixes,
            $allowLegacyPresetBreakage
        );
        if ($verification['errors'] === [] && ($verification['phase'] ?? '') === 'complete') {
            // This invocation did not deactivate the sources.  Persist the
            // completed state before refreshing so a retry can never attempt
            // compensation against an already-live cutover.
            $this->setMigrationPhase('complete');
            try {
                $catalogRefresh = $this->refreshCatalogSurfaces();
                $final = $this->verify(
                    $presetId,
                    $requireSemanticFixes,
                    $allowLegacyPresetBreakage
                );
                if ($final['errors'] !== [] || ($final['phase'] ?? '') !== 'complete') {
                    throw new \RuntimeException('Repeated cutover verification failed');
                }
                return [
                    'status' => 'ok',
                    'phase' => 'already_complete',
                    'catalogRefresh' => $catalogRefresh,
                    'verification' => $final,
                ];
            } catch (\Throwable $error) {
                throw new \RuntimeException(
                    'Already-complete cutover refresh failed; sources remain inactive: ' . $error->getMessage(),
                    0,
                    $error
                );
            }
        }
        if ($verification['errors'] !== [] || empty($verification['cutoverReady'])) {
            throw new \RuntimeException('Source deactivation is blocked until every source product has a linked offer');
        }
        if (!in_array($this->migrationPhase(), ['parity_complete'], true)) {
            throw new \RuntimeException('Source deactivation requires durable parity_complete phase');
        }
        $snapshot = $this->snapshot(
            $presetId,
            $requireSemanticFixes,
            $allowLegacyPresetBreakage
        );
        $this->assertFreshAudit(
            $presetId,
            $expectedFingerprint,
            $requireSemanticFixes,
            true,
            $allowLegacyPresetBreakage
        );
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $state = $this->catalogState();
            foreach (array_keys(self::PROPERTY_MAP) as $sourceCode) {
                $property = $this->propertyByCode((int)$state['productIblockId'], $sourceCode);
                if (!$property) {
                    throw new \RuntimeException('Source property disappeared before cutover: ' . $sourceCode);
                }
                if ((string)$property['ACTIVE'] === 'Y') {
                    $api = new \CIBlockProperty();
                    if (!$api->Update((int)$property['ID'], ['ACTIVE' => 'N'])) {
                        throw new \RuntimeException('Unable to deactivate ' . $sourceCode . ': ' . trim((string)$api->LAST_ERROR));
                    }
                }
            }
            $connection->commitTransaction();
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            $this->resetCaches();
            throw $error;
        }
        $this->resetCaches();
        try {
            $catalogRefresh = $this->refreshCatalogSurfaces();
            $final = $this->verify(
                $presetId,
                $requireSemanticFixes,
                $allowLegacyPresetBreakage
            );
            if ($final['errors'] !== [] || ($final['phase'] ?? '') !== 'complete') {
                throw new \RuntimeException('Cutover verification failed');
            }
        } catch (\Throwable $error) {
            $this->throwAfterFailedCutover(
                $presetId,
                $requireSemanticFixes,
                $allowLegacyPresetBreakage,
                $error
            );
        }
        $this->setMigrationPhase('complete');
        return [
            'status' => 'ok',
            'phase' => 'cutover',
            'snapshot' => $snapshot,
            'catalogRefresh' => $catalogRefresh,
            'verification' => $final,
        ];
    }

    private function throwAfterFailedCutover(
        int $presetId,
        bool $requireSemanticFixes,
        bool $allowLegacyPresetBreakage,
        \Throwable $cutoverError
    ): void {
        try {
            $rollback = $this->reactivateSourcesAfterFailedCutover(
                $presetId,
                $requireSemanticFixes,
                $allowLegacyPresetBreakage
            );
        } catch (\Throwable $rollbackError) {
            throw new \RuntimeException(
                'Cutover failed and automatic source reactivation failed: '
                . $cutoverError->getMessage() . '; rollback: ' . $rollbackError->getMessage(),
                0,
                $cutoverError
            );
        }
        throw new \RuntimeException(
            'Cutover failed; source properties were reactivated: '
            . $cutoverError->getMessage() . '; recovery=' . self::canonicalJson($rollback),
            0,
            $cutoverError
        );
    }

    /** @return array<string,mixed> */
    private function reactivateSourcesAfterFailedCutover(
        int $presetId,
        bool $requireSemanticFixes,
        bool $allowLegacyPresetBreakage
    ): array {
        $state = $this->catalogState();
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            foreach (array_keys(self::PROPERTY_MAP) as $sourceCode) {
                $property = $this->propertyByCode((int)$state['productIblockId'], $sourceCode);
                if (!$property) {
                    throw new \RuntimeException('Unable to recover missing source property ' . $sourceCode);
                }
                if ((string)$property['ACTIVE'] !== 'Y') {
                    $api = new \CIBlockProperty();
                    if (!$api->Update((int)$property['ID'], ['ACTIVE' => 'Y'])) {
                        throw new \RuntimeException('Unable to reactivate ' . $sourceCode);
                    }
                }
            }
            $connection->commitTransaction();
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            $this->resetCaches();
            throw $error;
        }

        $this->resetCaches();
        $refresh = null;
        $refreshError = '';
        try {
            $refresh = $this->refreshCatalogSurfaces();
        } catch (\Throwable $error) {
            $refreshError = $error->getMessage();
        }
        $this->resetCaches();
        $states = $this->sourcePropertyStates();
        $allActive = $states !== [] && array_values(array_unique($states)) === ['Y'];
        if (!$allActive) {
            throw new \RuntimeException('Automatic source reactivation did not restore all source properties');
        }
        $this->setMigrationPhase('parity_complete');
        $verification = $this->verify(
            $presetId,
            $requireSemanticFixes,
            $allowLegacyPresetBreakage
        );
        return [
            'status' => $verification['errors'] === [] && ($verification['phase'] ?? '') === 'ready_for_cutover'
                ? 'ready_for_cutover'
                : 'reactivated_with_verification_errors',
            'sourceStates' => $states,
            'catalogRefresh' => $refresh,
            'catalogRefreshError' => $refreshError,
            'verification' => $verification,
        ];
    }

    /** @return array<string,mixed> */
    public function verify(
        int $presetId = self::DEFAULT_PRESET_ID,
        bool $requireSemanticFixes = false,
        bool $allowLegacyPresetBreakage = false
    ): array {
        $this->assertEnvironment($presetId);
        $this->resetCaches();
        $state = $this->catalogState();
        $errors = [];
        $warnings = [];
        $sourceActive = [];

        foreach (self::PROPERTY_MAP as $sourceCode => $targetCode) {
            $source = $this->propertyByCode((int)$state['productIblockId'], $sourceCode);
            $target = $this->propertyByCode((int)$state['offerIblockId'], $targetCode);
            if (!$source || !$target) {
                $errors[] = ['type' => 'property_missing', 'sourceCode' => $sourceCode, 'targetCode' => $targetCode];
                continue;
            }
            $sourceActive[$sourceCode] = (string)$source['ACTIVE'];
            if ((string)$target['ACTIVE'] !== 'Y'
                || (int)$target['SORT'] !== (int)(self::PROPERTY_SORTS[$targetCode] ?? 500)
                || (string)($target['SECTION_PROPERTY'] ?? 'N') !== 'Y'
                || (string)($target['SMART_FILTER'] ?? 'N') !== 'Y'
                || (string)($target['DISPLAY_TYPE'] ?? '') !== 'F'
                || (string)($target['DISPLAY_EXPANDED'] ?? '') !== 'N') {
                $errors[] = ['type' => 'target_flags_or_sort_invalid', 'targetCode' => $targetCode];
            }
            $targetEnums = $this->enumsByXmlId((int)$target['ID']);
            foreach ($this->enumsByXmlId((int)$source['ID']) as $xmlId => $sourceEnum) {
                $canonicalXmlId = self::canonicalTargetXmlId($sourceCode, (string)$xmlId);
                $isAlias = $canonicalXmlId !== (string)$xmlId;
                if ($isAlias && isset($targetEnums[$xmlId])) {
                    $errors[] = [
                        'type' => 'target_enum_semantic_alias_duplicate',
                        'targetCode' => $targetCode,
                        'aliasXmlId' => $xmlId,
                        'canonicalXmlId' => $canonicalXmlId,
                    ];
                }
                if (!isset($targetEnums[$canonicalXmlId])
                    || (!$isAlias
                        && !self::enumValuesEquivalent(
                            (string)$sourceEnum['VALUE'],
                            (string)$targetEnums[$canonicalXmlId]['VALUE']
                        ))) {
                    $errors[] = [
                        'type' => 'target_enum_missing_or_different',
                        'targetCode' => $targetCode,
                        'xmlId' => $canonicalXmlId,
                    ];
                }
            }
        }
        foreach ((array)$state['valueConflicts'] as $conflict) {
            $errors[] = $conflict;
        }
        foreach ((array)$state['missingTargetValues'] as $missing) {
            $errors[] = $missing;
        }
        foreach ($this->unexpectedProductCalcProperties((int)$state['productIblockId']) as $property) {
            $errors[] = ['type' => 'unexpected_product_calc_property'] + $property;
        }
        foreach ($this->verifyMaterializedBaseOffers()['errors'] as $baseOfferError) {
            $errors[] = $baseOfferError;
        }
        $acceptedDeprecatedPresetConsumers = [];
        $sourcePresetConsumers = $this->auditSourcePresetConsumers(
            $state,
            $presetId,
            $allowLegacyPresetBreakage
        );
        foreach ($sourcePresetConsumers['conflicts'] as $conflict) {
            $errors[] = $conflict;
        }
        foreach ($sourcePresetConsumers['warnings'] as $warning) {
            $warnings[] = $warning;
        }
        foreach ($sourcePresetConsumers['acceptedDeprecatedPresetConsumers'] as $consumer) {
            $acceptedDeprecatedPresetConsumers[] = $consumer;
        }
        $otherPresetConsumers = $this->auditOtherPresetConsumers(
            $presetId,
            $allowLegacyPresetBreakage
        );
        foreach ($otherPresetConsumers['conflicts'] as $conflict) {
            $errors[] = $conflict;
        }
        foreach ($otherPresetConsumers['warnings'] as $warning) {
            $warnings[] = $warning;
        }
        foreach ($otherPresetConsumers['acceptedDeprecatedPresetConsumers'] as $consumer) {
            $acceptedDeprecatedPresetConsumers[] = $consumer;
        }
        foreach ($this->verifyPropertySorts() as $error) {
            $errors[] = $error;
        }
        foreach ($this->verifyPresetRefactor($presetId, $requireSemanticFixes) as $error) {
            $errors[] = $error;
        }
        foreach ($this->verifyDescriptions() as $error) {
            $errors[] = $error;
        }
        foreach ($this->verifyFrontcalcFamilies() as $error) {
            $errors[] = $error;
        }
        foreach ($this->verifyProductFrontcalcConfigs() as $error) {
            $errors[] = $error;
        }
        foreach ($this->verifyCatalogDisplayConfig() as $error) {
            $errors[] = $error;
        }

        $activeStates = array_values(array_unique($sourceActive));
        $allSourceInactive = $sourceActive !== [] && $activeStates === ['N'];
        $allSourceActive = $sourceActive !== [] && $activeStates === ['Y'];
        if (!$allSourceInactive && !$allSourceActive) {
            $errors[] = ['type' => 'partial_source_cutover', 'sourceStates' => $sourceActive];
        }
        if ($allSourceInactive && $state['productsWithoutOffers'] !== []) {
            $errors[] = [
                'type' => 'inactive_sources_with_unmigratable_products',
                'productIds' => array_values($state['productsWithoutOffers']),
            ];
        }
        if ($state['productsWithoutOffers'] !== []) {
            $warnings[] = [
                'type' => 'cutover_blocked_products_without_offers',
                'productIds' => array_values($state['productsWithoutOffers']),
            ];
        }
        if ((int)$state['unlinkedOfferCount'] > 0) {
            $warnings[] = ['type' => 'unlinked_offers_preserved', 'count' => (int)$state['unlinkedOfferCount']];
        }

        $cutoverReady = $errors === [] && $allSourceActive && $state['productsWithoutOffers'] === [];
        $phase = $allSourceInactive && $errors === []
            ? 'complete'
            : ($cutoverReady ? 'ready_for_cutover' : 'pending_cutover');
        return [
            'status' => $errors === [] ? 'ok' : 'failed',
            'phase' => $phase,
            'presetId' => $presetId,
            'semanticFixesRequired' => $requireSemanticFixes,
            'allowLegacyPresetBreakage' => $allowLegacyPresetBreakage,
            'acceptedDeprecatedPresetConsumers' => $acceptedDeprecatedPresetConsumers,
            'cutoverReady' => $cutoverReady,
            'sourceStates' => $sourceActive,
            'transferCounts' => $state['transferCounts'],
            'linkedOfferCount' => (int)$state['linkedOfferCount'],
            'activeLinkedOfferCount' => (int)$state['activeLinkedOfferCount'],
            'productsWithoutOffers' => array_values($state['productsWithoutOffers']),
            'unlinkedOfferCount' => (int)$state['unlinkedOfferCount'],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    private function assertEnvironment(int $presetId): void
    {
        global $USER;
        if (!$USER || !$USER->IsAdmin()) {
            throw new \RuntimeException('Administrator privileges are required');
        }
        if ($presetId !== self::DEFAULT_PRESET_ID) {
            throw new \InvalidArgumentException('This migration is scoped to preset #' . self::DEFAULT_PRESET_ID);
        }
        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            throw new \RuntimeException('The iblock and catalog modules are required');
        }
        $config = new ConfigManager();
        if ($config->getProductIblockId() <= 0 || $config->getSkuIblockId() <= 0) {
            throw new \RuntimeException('PRODUCT_IBLOCK_ID and SKU_IBLOCK_ID must be configured');
        }
        $presetIblockId = $config->getIblockId('CALC_PRESETS');
        if ($presetIblockId <= 0 || !\CIBlockElement::GetList(
            [],
            ['ID' => $presetId, 'IBLOCK_ID' => $presetIblockId],
            false,
            ['nTopCount' => 1],
            ['ID']
        )->Fetch()) {
            throw new \RuntimeException('Preset #' . $presetId . ' was not found in configured CALC_PRESETS');
        }
    }

    private function assertFingerprint(string $expected, string $actual): void
    {
        if (!preg_match('/^sha256:[a-f0-9]{64}$/D', $expected)) {
            throw new \InvalidArgumentException('A valid audit fingerprint is required');
        }
        if (!hash_equals($actual, $expected)) {
            throw new \RuntimeException('Migration state changed after audit; run audit again');
        }
    }

    /** @return array<string,mixed> */
    private function assertFreshAudit(
        int $presetId,
        string $expectedFingerprint,
        bool $semanticFixes,
        bool $requireConflictFree = true,
        bool $allowLegacyPresetBreakage = false
    ): array
    {
        $audit = $this->audit($presetId, $semanticFixes, $allowLegacyPresetBreakage);
        $this->assertFingerprint($expectedFingerprint, (string)$audit['fingerprint']);
        if ($requireConflictFree && $audit['conflicts'] !== []) {
            throw new \RuntimeException('Migration audit contains blocking conflicts');
        }
        return $audit;
    }

    private function resetCaches(): void
    {
        $this->catalogStateCache = null;
    }

    /** @param callable():mixed $operation @return mixed */
    private function withMigrationLock(callable $operation)
    {
        $connection = Application::getConnection();
        $lockName = $connection->getSqlHelper()->forSql(self::MIGRATION_LOCK_NAME);
        $lockRow = $connection->query(
            "SELECT GET_LOCK('" . $lockName . "', 0) AS MIGRATION_LOCKED"
        )->fetch();
        if ((int)($lockRow['MIGRATION_LOCKED'] ?? 0) !== 1) {
            throw new \RuntimeException('Another catalogue CALC property migration operation is running');
        }

        $result = null;
        $failure = null;
        try {
            $result = $operation();
        } catch (\Throwable $error) {
            $failure = $error;
        }

        try {
            $releaseRow = $connection->query(
                "SELECT RELEASE_LOCK('" . $lockName . "') AS MIGRATION_RELEASED"
            )->fetch();
            if ((int)($releaseRow['MIGRATION_RELEASED'] ?? 0) !== 1) {
                throw new \RuntimeException('Migration lock release was not acknowledged');
            }
        } catch (\Throwable $releaseError) {
            if ($failure !== null) {
                throw new \RuntimeException(
                    $failure->getMessage() . '; migration lock release failed: ' . $releaseError->getMessage(),
                    0,
                    $failure
                );
            }
            throw $releaseError;
        }
        if ($failure !== null) {
            throw $failure;
        }
        return $result;
    }

    private function migrationPhase(): string
    {
        return trim((string)\Bitrix\Main\Config\Option::get(
            self::MODULE_ID,
            self::MIGRATION_PHASE_OPTION,
            ''
        ));
    }

    private function setMigrationPhase(string $phase): void
    {
        \Bitrix\Main\Config\Option::set(self::MODULE_ID, self::MIGRATION_PHASE_OPTION, $phase);
    }

    /** @return array<string,string> */
    private function sourcePropertyStates(): array
    {
        $productIblockId = (new ConfigManager())->getProductIblockId();
        $states = [];
        foreach (array_keys(self::PROPERTY_MAP) as $sourceCode) {
            $property = $this->propertyByCode($productIblockId, $sourceCode);
            if (!$property) {
                throw new \RuntimeException('Source property missing: ' . $sourceCode);
            }
            $states[$sourceCode] = (string)($property['ACTIVE'] ?? '');
        }
        return $states;
    }

    private function assertAllSourcesActive(string $operation): void
    {
        $states = $this->sourcePropertyStates();
        if ($states === [] || array_values(array_unique($states)) !== ['Y']) {
            throw new \RuntimeException($operation . ' requires all migrated source properties ACTIVE=Y');
        }
    }

    private function assertPreParityStructure(int $presetId, string $operation): void
    {
        $presetAudit = $this->auditPresetRefactor($presetId, false);
        if ($presetAudit['conflicts'] !== []) {
            throw new \RuntimeException(
                $operation . ' is blocked by preset conflicts: ' . self::canonicalJson($presetAudit['conflicts'])
            );
        }
        $actualParityIds = array_values(array_map(
            static fn(array $row): int => (int)($row['elementId'] ?? 0),
            (array)$presetAudit['summary']['parityChanges']
        ));
        sort($actualParityIds, SORT_NUMERIC);
        $expectedParityIds = array_values(array_filter(
            array_map('intval', array_keys(self::GLOBAL_FORMULAS)),
            static fn(int $id): bool => !in_array($id, [12794, 12796], true)
        ));
        sort($expectedParityIds, SORT_NUMERIC);
        if ($actualParityIds !== $expectedParityIds) {
            throw new \RuntimeException(
                $operation . ' is blocked because global parity refactor is partially applied'
            );
        }

        $graph = $this->collectPresetGraph($presetId);
        $stageRows = $this->elementPropertyRows(
            (int)$graph['stagesIblockId'],
            self::EQUIPMENT_MAPPING_STAGE_ID
        );
        $stageRaw = isset($stageRows['OPTIONS_EQUIPMENT'][0])
            ? $this->propertyRowText($stageRows['OPTIONS_EQUIPMENT'][0])
            : '';
        $stage = json_decode(html_entity_decode($stageRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        $productCodes = is_array($stage)
            ? array_values(array_map('strval', (array)($stage['productPropertyCodes'] ?? [])))
            : [];
        $offerCodes = is_array($stage)
            ? array_values(array_map('strval', (array)($stage['offerPropertyCodes'] ?? [])))
            : [];
        $legacyVariants = [];
        foreach ((array)(is_array($stage) ? ($stage['mappings'] ?? []) : []) as $mapping) {
            $xmlId = (string)($mapping['productValues']['CALC_METHOD']['xmlId'] ?? '');
            if ($xmlId !== '') {
                $legacyVariants[$xmlId] = (int)($mapping['variantId'] ?? 0);
            }
            if (isset($mapping['offerValues']['CALC_PROP_METHOD'])) {
                throw new \RuntimeException($operation . ' is blocked by a partially migrated stage mapping');
            }
        }
        ksort($legacyVariants);
        if (!in_array('CALC_METHOD', $productCodes, true)
            || in_array('CALC_PROP_METHOD', $offerCodes, true)
            || $legacyVariants !== ['DIGITAL' => 1083, 'OFSET' => 1085]) {
            throw new \RuntimeException($operation . ' requires the exact pre-parity equipment mapping');
        }

        $settingsRows = $this->elementPropertyRows(
            (int)$graph['settingsIblockId'],
            self::SETTINGS_ID
        );
        $contextRaw = isset($settingsRows['AI_CONTEXT_JSON'][0])
            ? $this->propertyRowText($settingsRows['AI_CONTEXT_JSON'][0])
            : '';
        $context = json_decode(html_entity_decode($contextRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        $baseProduct = null;
        foreach ((array)(is_array($context) ? ($context['baseProducts'] ?? []) : []) as $product) {
            if (is_array($product) && (int)($product['productId'] ?? 0) === 12727) {
                $baseProduct = $product;
                break;
            }
        }
        $legacyProductCodes = [];
        $offerPropertyCodes = [];
        foreach ((array)($baseProduct['productProperties'] ?? []) as $property) {
            $legacyProductCodes[(string)($property['code'] ?? '')] = true;
        }
        foreach ((array)($baseProduct['offerProperties'] ?? []) as $property) {
            $offerPropertyCodes[(string)($property['code'] ?? '')] = true;
        }
        if (!isset($legacyProductCodes['CALC_METHOD'], $legacyProductCodes['CALC_TYPE_PAPER'])
            || isset($offerPropertyCodes['CALC_PROP_METHOD'], $offerPropertyCodes['CALC_PROP_TYPE_PAPER'])) {
            throw new \RuntimeException($operation . ' is blocked by a partially migrated AI context');
        }

        $seedState = $this->frontcalcTemplateSeedState();
        if ((int)$seedState['legacy'] <= 0
            || (int)$seedState['migrated'] > 0
            || (int)$seedState['invalidTarget'] > 0) {
            throw new \RuntimeException(
                $operation . ' is blocked by a partial FrontCalc template seed refactor'
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function propertyByCode(int $iblockId, string $code): ?array
    {
        if ($iblockId <= 0 || $code === '') {
            return null;
        }
        $row = \CIBlockProperty::GetList([], [
            'IBLOCK_ID' => $iblockId,
            'CODE' => $code,
        ])->Fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    private function unexpectedProductCalcProperties(int $productIblockId): array
    {
        $allowed = array_fill_keys(array_merge(
            array_keys(self::PROPERTY_MAP),
            ['CALC_PRESET', 'FRONTCALC_CONFIG']
        ), true);
        $unexpected = [];
        $cursor = \CIBlockProperty::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $productIblockId]
        );
        while ($property = $cursor->Fetch()) {
            $code = trim((string)($property['CODE'] ?? ''));
            if (strpos($code, 'CALC_') !== 0 || isset($allowed[$code])) {
                continue;
            }
            $unexpected[] = [
                'propertyId' => (int)($property['ID'] ?? 0),
                'code' => $code,
                'active' => (string)($property['ACTIVE'] ?? ''),
            ];
        }
        return $unexpected;
    }

    /**
     * @param array<int,array<string,mixed>>|null $conflicts
     * @return array<string,array<string,mixed>>
     */
    private function enumsByXmlId(int $propertyId, ?array &$conflicts = null, string $code = ''): array
    {
        $result = [];
        if ($propertyId <= 0) {
            return $result;
        }
        $cursor = \CIBlockPropertyEnum::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['PROPERTY_ID' => $propertyId]
        );
        while ($row = $cursor->Fetch()) {
            $xmlId = trim((string)($row['XML_ID'] ?? ''));
            if ($xmlId === '') {
                if ($conflicts !== null) {
                    $conflicts[] = [
                        'type' => 'enum_without_xml_id',
                        'propertyCode' => $code,
                        'enumId' => (int)($row['ID'] ?? 0),
                    ];
                }
                continue;
            }
            if (isset($result[$xmlId])) {
                if ($conflicts !== null) {
                    $conflicts[] = [
                        'type' => 'duplicate_enum_xml_id',
                        'propertyCode' => $code,
                        'xmlId' => $xmlId,
                    ];
                }
                continue;
            }
            $result[$xmlId] = $row;
        }
        return $result;
    }

    /** @return string[] */
    private function elementPropertyXmlIds(int $iblockId, int $elementId, string $code): array
    {
        $values = [];
        if ($iblockId <= 0 || $elementId <= 0 || $code === '') {
            return [];
        }
        $cursor = \CIBlockElement::GetProperty(
            $iblockId,
            $elementId,
            ['sort' => 'asc', 'id' => 'asc'],
            ['CODE' => $code]
        );
        while ($row = $cursor->Fetch()) {
            if ($row['VALUE'] === null || $row['VALUE'] === '') {
                continue;
            }
            $xmlId = trim((string)($row['VALUE_XML_ID'] ?? ''));
            if ($xmlId === '') {
                $enumId = (int)($row['VALUE_ENUM_ID'] ?? 0);
                if ($enumId <= 0 && ctype_digit((string)$row['VALUE'])) {
                    $enumId = (int)$row['VALUE'];
                }
                if ($enumId > 0) {
                    $enum = \CIBlockPropertyEnum::GetByID($enumId);
                    $xmlId = is_array($enum) ? trim((string)($enum['XML_ID'] ?? '')) : '';
                }
            }
            if ($xmlId !== '') {
                $values[$xmlId] = $xmlId;
            }
        }
        return array_values($values);
    }

    /** @return array<string,mixed> */
    private function catalogState(): array
    {
        if (is_array($this->catalogStateCache)) {
            return $this->catalogStateCache;
        }
        $config = new ConfigManager();
        $productIblockId = $config->getProductIblockId();
        $offerIblockId = $config->getSkuIblockId();
        $offersByProduct = [];
        $activeOffersByProduct = [];
        $offerLinks = [];
        $offerXmlIds = [];
        $unlinkedOfferCount = 0;
        $offers = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $offerIblockId],
            false,
            false,
            ['ID', 'XML_ID', 'PROPERTY_CML2_LINK']
        );
        while ($row = $offers->Fetch()) {
            $offerId = (int)($row['ID'] ?? 0);
            $productId = (int)($row['PROPERTY_CML2_LINK_VALUE'] ?? 0);
            if ($offerId <= 0) {
                continue;
            }
            $offerLinks[$offerId] = $productId;
            $offerXmlIds[$offerId] = (string)($row['XML_ID'] ?? '');
            if ($productId <= 0) {
                $unlinkedOfferCount++;
                continue;
            }
            $offersByProduct[$productId][] = $offerId;
        }
        $activeOffers = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $offerIblockId, 'ACTIVE' => 'Y', 'ACTIVE_DATE' => 'Y'],
            false,
            false,
            ['ID', 'PROPERTY_CML2_LINK']
        );
        $activeLinkedOfferCount = 0;
        while ($row = $activeOffers->Fetch()) {
            $offerId = (int)($row['ID'] ?? 0);
            $productId = (int)($row['PROPERTY_CML2_LINK_VALUE'] ?? 0);
            if ($offerId <= 0 || $productId <= 0) {
                continue;
            }
            $activeOffersByProduct[$productId][] = $offerId;
            $activeLinkedOfferCount++;
        }

        $targetProperties = [];
        foreach (self::PROPERTY_MAP as $targetCode) {
            $targetProperties[$targetCode] = $this->propertyByCode($offerIblockId, $targetCode);
        }

        $productValues = [];
        $offerValues = [];
        $transferCounts = array_fill_keys(array_values(self::PROPERTY_MAP), 0);
        $valueConflicts = [];
        $missingTargetValues = [];
        $productsWithoutOffers = [];
        $products = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $productIblockId],
            false,
            false,
            ['ID']
        );
        while ($row = $products->Fetch()) {
            $productId = (int)($row['ID'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $hasSourceValue = false;
            foreach (self::PROPERTY_MAP as $sourceCode => $targetCode) {
                $xmlIds = $this->elementPropertyXmlIds($productIblockId, $productId, $sourceCode);
                if ($xmlIds === []) {
                    continue;
                }
                $hasSourceValue = true;
                $productValues[$productId][$sourceCode] = $xmlIds;
                $expectedTargetXmlIds = self::canonicalTargetXmlIds($sourceCode, $xmlIds);
                foreach ($offersByProduct[$productId] ?? [] as $offerId) {
                    $transferCounts[$targetCode]++;
                    $existing = $targetProperties[$targetCode]
                        ? $this->elementPropertyXmlIds($offerIblockId, $offerId, $targetCode)
                        : [];
                    $offerValues[$offerId][$targetCode] = $existing;
                    if ($targetProperties[$targetCode] && $existing === []) {
                        $missingTargetValues[] = [
                            'type' => 'offer_target_value_missing',
                            'productId' => $productId,
                            'offerId' => $offerId,
                            'sourceCode' => $sourceCode,
                            'targetCode' => $targetCode,
                        ];
                    }
                    if ($existing !== [] && $existing !== $expectedTargetXmlIds) {
                        $valueConflicts[] = [
                            'type' => 'offer_value_conflict',
                            'productId' => $productId,
                            'offerId' => $offerId,
                            'sourceCode' => $sourceCode,
                            'targetCode' => $targetCode,
                            'sourceXmlIds' => $xmlIds,
                            'expectedTargetXmlIds' => $expectedTargetXmlIds,
                            'targetXmlIds' => $existing,
                        ];
                    }
                }
            }
            if ($hasSourceValue && empty($activeOffersByProduct[$productId])) {
                $productsWithoutOffers[$productId] = $productId;
            }
        }

        ksort($productValues, SORT_NUMERIC);
        ksort($offerValues, SORT_NUMERIC);
        ksort($offersByProduct, SORT_NUMERIC);
        ksort($activeOffersByProduct, SORT_NUMERIC);
        $this->catalogStateCache = [
            'productIblockId' => $productIblockId,
            'offerIblockId' => $offerIblockId,
            'offersByProduct' => $offersByProduct,
            'activeOffersByProduct' => $activeOffersByProduct,
            'offerLinks' => $offerLinks,
            'offerXmlIds' => $offerXmlIds,
            'linkedOfferCount' => count($offerLinks) - $unlinkedOfferCount,
            'activeLinkedOfferCount' => $activeLinkedOfferCount,
            'unlinkedOfferCount' => $unlinkedOfferCount,
            'productValues' => $productValues,
            'offerValues' => $offerValues,
            'transferCounts' => $transferCounts,
            'valueConflicts' => $valueConflicts,
            'missingTargetValues' => $missingTargetValues,
            'productsWithoutOffers' => $productsWithoutOffers,
        ];
        return $this->catalogStateCache;
    }

    /** @return array<int,array<string,mixed>> */
    private function baseOfferPlan(array $state): array
    {
        $plan = [];
        foreach (self::EXPECTED_BASE_OFFER_PRODUCTS as $productId) {
            $offerIds = array_values(array_map('intval', (array)($state['offersByProduct'][$productId] ?? [])));
            $markerXmlId = self::BASE_OFFER_MARKER_PREFIX . $productId;
            $linkedOfferXmlIds = array_values(array_map(
                static fn(int $offerId): string => (string)($state['offerXmlIds'][$offerId] ?? ''),
                $offerIds
            ));
            $activeOfferIds = array_values(array_map(
                'intval',
                (array)($state['activeOffersByProduct'][$productId] ?? [])
            ));
            $activeLinkedOfferXmlIds = array_values(array_map(
                static fn(int $offerId): string => (string)($state['offerXmlIds'][$offerId] ?? ''),
                $activeOfferIds
            ));
            $priceCount = 0;
            $prices = \CPrice::GetList([], ['PRODUCT_ID' => $productId]);
            while ($prices->Fetch()) {
                $priceCount++;
            }
            $plan[] = [
                'productId' => $productId,
                'action' => self::baseOfferRetryAction(
                    $linkedOfferXmlIds,
                    $activeLinkedOfferXmlIds,
                    $markerXmlId
                ),
                'linkedOfferIds' => $offerIds,
                'linkedOfferXmlIds' => $linkedOfferXmlIds,
                'activeLinkedOfferIds' => $activeOfferIds,
                'activeLinkedOfferXmlIds' => $activeLinkedOfferXmlIds,
                'markerXmlId' => $markerXmlId,
                'sourceValues' => $state['productValues'][$productId] ?? [],
                'productPriceCount' => $priceCount,
            ];
        }
        return $plan;
    }

    private function createBaseOffer(int $productId, array $sourceValues): int
    {
        $state = $this->catalogState();
        $product = \CIBlockElement::GetList(
            [],
            ['ID' => $productId, 'IBLOCK_ID' => (int)$state['productIblockId']],
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME', 'SORT']
        )->Fetch();
        if (!$product) {
            throw new \RuntimeException('Base product #' . $productId . ' was not found');
        }
        if (\CPrice::GetList([], ['PRODUCT_ID' => $productId])->Fetch()) {
            throw new \RuntimeException('Refusing to materialize a base SKU from priced product #' . $productId);
        }
        $marker = self::BASE_OFFER_MARKER_PREFIX . $productId;
        $code = 'prospektweb-calc-base-offer-' . $productId . '-v1';
        $markerCursor = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => (int)$state['offerIblockId'], '=XML_ID' => $marker],
            false,
            false,
            ['ID', 'CODE', 'XML_ID']
        );
        $markerRows = [];
        while ($markerRow = $markerCursor->Fetch()) {
            $markerRows[] = $markerRow;
        }
        if (count($markerRows) > 1) {
            throw new \RuntimeException('Duplicate deterministic base offer markers for product #' . $productId);
        }
        $existingMarker = $markerRows[0] ?? null;
        $existingCode = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => (int)$state['offerIblockId'], '=CODE' => $code],
            false,
            ['nTopCount' => 1],
            ['ID', 'XML_ID']
        )->Fetch();
        if ($existingCode && (string)($existingCode['XML_ID'] ?? '') !== $marker) {
            throw new \RuntimeException('Deterministic base offer code is occupied for product #' . $productId);
        }
        if ($existingMarker && $existingCode
            && (int)$existingMarker['ID'] !== (int)$existingCode['ID']) {
            throw new \RuntimeException('Deterministic base offer marker/code resolve to different elements');
        }

        $propertyValues = ['CML2_LINK' => $productId];
        foreach ($sourceValues as $sourceCode => $xmlIds) {
            $targetCode = self::PROPERTY_MAP[(string)$sourceCode] ?? '';
            if ($targetCode === '') {
                continue;
            }
            $target = $this->propertyByCode((int)$state['offerIblockId'], $targetCode);
            if (!$target) {
                throw new \RuntimeException('Target property missing for base offer: ' . $targetCode);
            }
            $enums = $this->enumsByXmlId((int)$target['ID']);
            $ids = [];
            foreach (self::canonicalTargetXmlIds((string)$sourceCode, (array)$xmlIds) as $xmlId) {
                if (!isset($enums[$xmlId])) {
                    throw new \RuntimeException('Target enum missing for base offer: ' . $targetCode . ':' . $xmlId);
                }
                $ids[] = (int)$enums[$xmlId]['ID'];
            }
            $propertyValues[$targetCode] = (string)$target['MULTIPLE'] === 'Y' ? $ids : ($ids[0] ?? null);
        }

        $offerId = (int)($existingMarker['ID'] ?? 0);
        if ($offerId > 0 && \CPrice::GetList([], ['PRODUCT_ID' => $offerId])->Fetch()) {
            throw new \RuntimeException('Refusing to reconcile priced marker-owned base offer #' . $offerId);
        }
        $elementApi = new \CIBlockElement();
        $fields = [
            'IBLOCK_ID' => (int)$state['offerIblockId'],
            'ACTIVE' => 'Y',
            'ACTIVE_FROM' => false,
            'ACTIVE_TO' => false,
            'NAME' => (string)$product['NAME'],
            'CODE' => $code,
            'XML_ID' => $marker,
            'SORT' => (int)($product['SORT'] ?? 500),
        ];
        if ($offerId > 0) {
            if (!$elementApi->Update($offerId, $fields)) {
                throw new \RuntimeException('Unable to reactivate marker-owned base offer #' . $offerId);
            }
            \CIBlockElement::SetPropertyValuesEx($offerId, (int)$state['offerIblockId'], $propertyValues);
        } else {
            $fields['PROPERTY_VALUES'] = $propertyValues;
            $offerId = (int)$elementApi->Add($fields);
            if ($offerId <= 0) {
                throw new \RuntimeException('Unable to create base offer for product #' . $productId . ': ' . trim((string)$elementApi->LAST_ERROR));
            }
        }

        if (\CPrice::GetList([], ['PRODUCT_ID' => $offerId])->Fetch()) {
            throw new \RuntimeException('Marker-owned base offer #' . $offerId . ' unexpectedly has prices');
        }
        $catalogFields = [
            'ID' => $offerId,
            'QUANTITY' => 0,
            'QUANTITY_TRACE' => 'Y',
            'CAN_BUY_ZERO' => 'N',
        ];
        if (defined('Bitrix\\Catalog\\ProductTable::TYPE_OFFER')) {
            $catalogFields['TYPE'] = constant('Bitrix\\Catalog\\ProductTable::TYPE_OFFER');
        }
        if (\CCatalogProduct::GetByID($offerId)) {
            if (!\CCatalogProduct::Update($offerId, $catalogFields)) {
                throw new \RuntimeException('Unable to update catalog row for base offer #' . $offerId);
            }
        } elseif (!\CCatalogProduct::Add($catalogFields)) {
            throw new \RuntimeException('Unable to create catalog row for base offer #' . $offerId);
        }
        return $offerId;
    }

    private function rememberCreatedBaseOffer(int $productId, int $offerId): void
    {
        $markers = $this->createdBaseOfferMarkers();
        $markers[$productId] = [
            'productId' => $productId,
            'offerId' => $offerId,
            'markerXmlId' => self::BASE_OFFER_MARKER_PREFIX . $productId,
        ];
        ksort($markers, SORT_NUMERIC);
        \Bitrix\Main\Config\Option::set(self::MODULE_ID, self::BASE_OFFER_OPTION, json_encode([
            'version' => 1,
            'status' => 'active',
            'offers' => $markers,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<int,array<string,mixed>> */
    private function createdBaseOfferMarkers(): array
    {
        $raw = (string)\Bitrix\Main\Config\Option::get(self::MODULE_ID, self::BASE_OFFER_OPTION, '');
        $decoded = json_decode($raw, true);
        $rows = is_array($decoded['offers'] ?? null) ? $decoded['offers'] : [];
        $result = [];
        foreach ($rows as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = (int)($row['productId'] ?? $key);
            $offerId = (int)($row['offerId'] ?? 0);
            if ($productId > 0 && $offerId > 0) {
                $result[$productId] = $row + ['productId' => $productId, 'offerId' => $offerId];
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function verifyMaterializedBaseOffers(): array
    {
        $errors = [];
        $satisfiedBy = [];
        $markerSafety = [];
        $state = $this->catalogState();
        foreach (self::EXPECTED_BASE_OFFER_PRODUCTS as $productId) {
            $activeOffers = array_values((array)($state['activeOffersByProduct'][$productId] ?? []));
            if ($activeOffers === []) {
                $errors[] = ['type' => 'base_offer_missing', 'productId' => $productId];
                continue;
            }
            $markerXmlId = self::BASE_OFFER_MARKER_PREFIX . $productId;
            $markerOffers = array_values(array_filter(
                $activeOffers,
                static fn(int $offerId): bool => (string)($state['offerXmlIds'][$offerId] ?? '') === $markerXmlId
            ));
            if ($markerOffers === []) {
                $satisfiedBy[] = [
                    'productId' => $productId,
                    'type' => 'foreign_active_offer',
                    'offerIds' => array_values(array_map('intval', $activeOffers)),
                ];
                continue;
            }
            if (count($markerOffers) > 1) {
                $errors[] = [
                    'type' => 'duplicate_marker_base_offers',
                    'productId' => $productId,
                    'offerIds' => array_values(array_map('intval', $markerOffers)),
                ];
            }
            foreach ($markerOffers as $offerId) {
                foreach ((array)($state['productValues'][$productId] ?? []) as $sourceCode => $xmlIds) {
                    $targetCode = self::PROPERTY_MAP[$sourceCode] ?? '';
                    $expectedTargetXmlIds = self::canonicalTargetXmlIds((string)$sourceCode, (array)$xmlIds);
                    if ($targetCode !== ''
                        && $this->elementPropertyXmlIds((int)$state['offerIblockId'], (int)$offerId, $targetCode) !== $expectedTargetXmlIds) {
                        $errors[] = [
                            'type' => 'base_offer_value_mismatch',
                            'productId' => $productId,
                            'offerId' => (int)$offerId,
                            'targetCode' => $targetCode,
                        ];
                    }
                }
                $element = \CIBlockElement::GetList(
                    [],
                    ['ID' => (int)$offerId, 'IBLOCK_ID' => (int)$state['offerIblockId']],
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'ACTIVE', 'XML_ID']
                )->Fetch();
                if ((string)($element['ACTIVE'] ?? '') !== 'Y') {
                    $errors[] = ['type' => 'base_offer_inactive', 'offerId' => (int)$offerId];
                }
                if ((string)($element['XML_ID'] ?? '') === $markerXmlId) {
                    $catalog = \CCatalogProduct::GetByID((int)$offerId);
                    if (!$catalog || (float)($catalog['QUANTITY'] ?? -1) !== 0.0) {
                        $errors[] = ['type' => 'base_offer_quantity_invalid', 'offerId' => (int)$offerId];
                    }
                    if ((string)($catalog['CAN_BUY_ZERO'] ?? '') !== 'N'
                        || (string)($catalog['QUANTITY_TRACE'] ?? '') !== 'Y') {
                        $errors[] = ['type' => 'base_offer_catalog_flags_invalid', 'offerId' => (int)$offerId];
                    }
                    $hasPrice = (bool)\CPrice::GetList([], ['PRODUCT_ID' => (int)$offerId])->Fetch();
                    if ($hasPrice) {
                        $errors[] = ['type' => 'base_offer_has_price', 'offerId' => (int)$offerId];
                    }
                    $markerSafety[] = [
                        'productId' => $productId,
                        'offerId' => (int)$offerId,
                        'quantity' => $catalog['QUANTITY'] ?? null,
                        'quantityTrace' => $catalog['QUANTITY_TRACE'] ?? null,
                        'canBuyZero' => $catalog['CAN_BUY_ZERO'] ?? null,
                        'hasPrice' => $hasPrice,
                    ];
                }
            }
        }
        if ($state['productsWithoutOffers'] !== []) {
            $errors[] = [
                'type' => 'products_without_offers_remain',
                'productIds' => array_values($state['productsWithoutOffers']),
            ];
        }
        return [
            'status' => $errors === [] ? 'ok' : 'failed',
            'linkedOfferCount' => (int)$state['linkedOfferCount'],
            'activeLinkedOfferCount' => (int)$state['activeLinkedOfferCount'],
            'unlinkedOfferCount' => (int)$state['unlinkedOfferCount'],
            'productsWithoutOffers' => array_values($state['productsWithoutOffers']),
            'satisfiedBy' => $satisfiedBy,
            'markerSafety' => $markerSafety,
            'errors' => $errors,
        ];
    }

    private function ensureTargetProperties(): void
    {
        $state = $this->catalogState();
        $productIblockId = (int)$state['productIblockId'];
        $offerIblockId = (int)$state['offerIblockId'];
        foreach (self::PROPERTY_MAP as $sourceCode => $targetCode) {
            $source = $this->propertyByCode($productIblockId, $sourceCode);
            if (!$source) {
                throw new \RuntimeException('Source property not found: ' . $sourceCode);
            }
            $target = $this->propertyByCode($offerIblockId, $targetCode);
            if (!$target) {
                $property = new \CIBlockProperty();
                $targetId = (int)$property->Add([
                    'IBLOCK_ID' => $offerIblockId,
                    'ACTIVE' => 'Y',
                    'NAME' => (string)$source['NAME'],
                    'CODE' => $targetCode,
                    'SORT' => (int)(self::PROPERTY_SORTS[$targetCode] ?? 500),
                    'PROPERTY_TYPE' => (string)$source['PROPERTY_TYPE'],
                    'MULTIPLE' => (string)$source['MULTIPLE'],
                    'LIST_TYPE' => (string)($source['LIST_TYPE'] ?? 'L'),
                    'IS_REQUIRED' => (string)($source['IS_REQUIRED'] ?? 'N'),
                    'WITH_DESCRIPTION' => (string)($source['WITH_DESCRIPTION'] ?? 'N'),
                    'MULTIPLE_CNT' => max(1, (int)($source['MULTIPLE_CNT'] ?? 5)),
                    'HINT' => (string)($source['HINT'] ?? ''),
                    'SEARCHABLE' => 'Y',
                    'FILTRABLE' => 'Y',
                    'SECTION_PROPERTY' => 'Y',
                    'SMART_FILTER' => 'Y',
                    'DISPLAY_TYPE' => 'F',
                    'DISPLAY_EXPANDED' => 'N',
                ]);
                if ($targetId <= 0) {
                    throw new \RuntimeException('Unable to create ' . $targetCode . ': ' . trim((string)$property->LAST_ERROR));
                }
                $target = $this->propertyByCode($offerIblockId, $targetCode);
            }
            if (!$target) {
                throw new \RuntimeException('Target property is unavailable after creation: ' . $targetCode);
            }
            $this->mergePropertyEnums((int)$source['ID'], (int)$target['ID'], $sourceCode, $targetCode);
        }
        foreach (self::PROPERTY_SORTS as $code => $sort) {
            $property = $this->propertyByCode($offerIblockId, $code);
            if (!$property) {
                continue;
            }
            $fields = ['SORT' => $sort];
            if (in_array($code, array_values(self::PROPERTY_MAP), true)) {
                $fields += [
                    'ACTIVE' => 'Y',
                    'SEARCHABLE' => 'Y',
                    'FILTRABLE' => 'Y',
                    'SECTION_PROPERTY' => 'Y',
                    'SMART_FILTER' => 'Y',
                    'DISPLAY_TYPE' => 'F',
                    'DISPLAY_EXPANDED' => 'N',
                ];
            } elseif ($code === 'CALC_STATE_HASH') {
                $fields += [
                    'SEARCHABLE' => 'N',
                    'FILTRABLE' => 'N',
                    'SECTION_PROPERTY' => 'N',
                    'SMART_FILTER' => 'N',
                    'DISPLAY_EXPANDED' => 'N',
                ];
            }
            $api = new \CIBlockProperty();
            if (!$api->Update((int)$property['ID'], $fields)) {
                throw new \RuntimeException('Unable to update ' . $code . ': ' . trim((string)$api->LAST_ERROR));
            }
        }
        $this->resetCaches();
    }

    private function mergePropertyEnums(int $sourcePropertyId, int $targetPropertyId, string $sourceCode, string $targetCode): void
    {
        $sourceEnums = $this->enumsByXmlId($sourcePropertyId);
        $targetEnums = $this->enumsByXmlId($targetPropertyId);
        $enumApi = new \CIBlockPropertyEnum();
        foreach ($sourceEnums as $xmlId => $sourceEnum) {
            $canonicalXmlId = self::canonicalTargetXmlId($sourceCode, (string)$xmlId);
            $isAlias = $canonicalXmlId !== (string)$xmlId;
            if ($isAlias && isset($targetEnums[$xmlId])) {
                throw new \RuntimeException(
                    'Target enum contains non-canonical semantic alias ' . $targetCode . ':' . $xmlId
                );
            }
            if (isset($targetEnums[$canonicalXmlId])) {
                if (!$isAlias
                    && !self::enumValuesEquivalent(
                        (string)$sourceEnum['VALUE'],
                        (string)$targetEnums[$canonicalXmlId]['VALUE']
                    )) {
                    throw new \RuntimeException(
                        'Enum conflict ' . $sourceCode . ' -> ' . $targetCode . ' for XML_ID ' . $canonicalXmlId
                    );
                }
                // For a semantic alias, the existing offer enum (including its
                // display label and order) is authoritative.
                if ($isAlias) {
                    continue;
                }
                if (!$enumApi->Update((int)$targetEnums[$canonicalXmlId]['ID'], [
                    // Preserve the canonical offer-facing label even when the
                    // product label differs only by normalized spacing.
                    'VALUE' => (string)$targetEnums[$canonicalXmlId]['VALUE'],
                    'SORT' => (int)$sourceEnum['SORT'],
                    'DEF' => (string)$sourceEnum['DEF'],
                    'XML_ID' => $canonicalXmlId,
                ])) {
                    throw new \RuntimeException('Unable to update target enum ' . $targetCode . ':' . $canonicalXmlId);
                }
                continue;
            }
            $newId = (int)$enumApi->Add([
                'PROPERTY_ID' => $targetPropertyId,
                'VALUE' => (string)$sourceEnum['VALUE'],
                'SORT' => (int)$sourceEnum['SORT'],
                'DEF' => (string)$sourceEnum['DEF'],
                'XML_ID' => $canonicalXmlId,
            ]);
            if ($newId <= 0) {
                throw new \RuntimeException('Unable to create target enum ' . $targetCode . ':' . $canonicalXmlId);
            }
            $targetEnums[$canonicalXmlId] = ['ID' => $newId] + $sourceEnum + ['XML_ID' => $canonicalXmlId];
        }
    }

    private function copyProductValuesToOffers(): void
    {
        $this->resetCaches();
        $state = $this->catalogState();
        if ($state['valueConflicts'] !== []) {
            throw new \RuntimeException('Offer value conflicts appeared before copy');
        }
        $targetEnumIds = [];
        foreach (self::PROPERTY_MAP as $targetCode) {
            $property = $this->propertyByCode((int)$state['offerIblockId'], $targetCode);
            if (!$property) {
                throw new \RuntimeException('Target property missing during copy: ' . $targetCode);
            }
            foreach ($this->enumsByXmlId((int)$property['ID']) as $xmlId => $enum) {
                $targetEnumIds[$targetCode][$xmlId] = (int)$enum['ID'];
            }
        }
        foreach ($state['productValues'] as $productId => $valuesByCode) {
            foreach ($state['offersByProduct'][$productId] ?? [] as $offerId) {
                foreach ($valuesByCode as $sourceCode => $xmlIds) {
                    $targetCode = self::PROPERTY_MAP[$sourceCode] ?? '';
                    if ($targetCode === '') {
                        continue;
                    }
                    $expectedTargetXmlIds = self::canonicalTargetXmlIds((string)$sourceCode, (array)$xmlIds);
                    $existing = $this->elementPropertyXmlIds((int)$state['offerIblockId'], (int)$offerId, $targetCode);
                    if ($existing !== [] && $existing !== $expectedTargetXmlIds) {
                        throw new \RuntimeException('Refusing to overwrite conflicting offer #' . $offerId . ' property ' . $targetCode);
                    }
                    if ($existing === $expectedTargetXmlIds) {
                        continue;
                    }
                    $enumIds = [];
                    foreach ($expectedTargetXmlIds as $xmlId) {
                        $enumId = (int)($targetEnumIds[$targetCode][$xmlId] ?? 0);
                        if ($enumId <= 0) {
                            throw new \RuntimeException('Target enum missing for ' . $targetCode . ':' . $xmlId);
                        }
                        $enumIds[] = $enumId;
                    }
                    $property = $this->propertyByCode((int)$state['offerIblockId'], $targetCode);
                    $value = (string)($property['MULTIPLE'] ?? 'N') === 'Y' ? $enumIds : ($enumIds[0] ?? null);
                    \CIBlockElement::SetPropertyValuesEx((int)$offerId, (int)$state['offerIblockId'], [
                        $targetCode => $value,
                    ]);
                    $stored = $this->elementPropertyXmlIds((int)$state['offerIblockId'], (int)$offerId, $targetCode);
                    if ($stored !== $expectedTargetXmlIds) {
                        throw new \RuntimeException('Failed to verify offer #' . $offerId . ' property ' . $targetCode);
                    }
                }
            }
        }
        $this->resetCaches();
    }

    /** @return array<int,array<string,mixed>> */
    private function verifyPropertySorts(): array
    {
        $errors = [];
        $offerIblockId = (new ConfigManager())->getSkuIblockId();
        foreach (self::PROPERTY_SORTS as $code => $sort) {
            $property = $this->propertyByCode($offerIblockId, $code);
            if ($property && (int)$property['SORT'] !== $sort) {
                $errors[] = ['type' => 'property_sort_mismatch', 'code' => $code, 'expected' => $sort, 'actual' => (int)$property['SORT']];
            }
            if ($code === 'CALC_STATE_HASH' && $property
                && ((string)($property['SMART_FILTER'] ?? 'N') === 'Y'
                    || (string)($property['SECTION_PROPERTY'] ?? 'N') === 'Y')) {
                $errors[] = ['type' => 'technical_property_visible', 'code' => $code];
            }
        }
        return $errors;
    }

    /** @return array<string,mixed> */
    private function auditCatalogDisplayConfig(): array
    {
        try {
            if (!class_exists(CatalogDisplayConfigPatcher::class)) {
                throw new \RuntimeException('CatalogDisplayConfigPatcher is unavailable');
            }
            return (new CatalogDisplayConfigPatcher((string)Application::getDocumentRoot()))->audit();
        } catch (\Throwable $error) {
            return [
                'status' => 'blocked',
                'patchId' => class_exists(CatalogDisplayConfigPatcher::class)
                    ? CatalogDisplayConfigPatcher::PATCH_ID
                    : 'prospektweb.calc.catalog-display-config/v1',
                'message' => $error->getMessage(),
            ];
        }
    }

    /** @return array<string,mixed> */
    private function applyCatalogDisplayConfig(array $audit): array
    {
        if (($audit['status'] ?? '') !== 'ok') {
            throw new \RuntimeException('Catalog display configuration audit is not applicable');
        }
        $expectedSha256 = trim((string)($audit['currentSha256'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/Di', $expectedSha256)) {
            throw new \RuntimeException('Catalog display configuration SHA-256 is invalid');
        }
        return (new CatalogDisplayConfigPatcher((string)Application::getDocumentRoot()))->apply($expectedSha256);
    }

    /** @return array<int,array<string,mixed>> */
    private function verifyCatalogDisplayConfig(): array
    {
        $audit = $this->auditCatalogDisplayConfig();
        if (($audit['status'] ?? '') !== 'ok') {
            return [[
                'type' => 'catalog_display_config_unavailable',
                'message' => (string)($audit['message'] ?? ''),
            ]];
        }
        $errors = [];
        if (!empty($audit['changed'])) {
            $errors[] = ['type' => 'catalog_display_config_pending'];
        }
        if (!hash_equals(
            (string)($audit['currentSha256'] ?? ''),
            (string)($audit['patchedSha256'] ?? '')
        )) {
            $errors[] = ['type' => 'catalog_display_config_hash_mismatch'];
        }
        return $errors;
    }

    /** @return array<string,mixed> */
    private function refreshCatalogSurfaces(): array
    {
        $config = new ConfigManager();
        $productIblockId = $config->getProductIblockId();
        $offerIblockId = $config->getSkuIblockId();
        if (!class_exists('\Bitrix\Iblock\PropertyIndex\Manager')) {
            throw new \RuntimeException('Bitrix property index manager is unavailable');
        }

        \Bitrix\Iblock\PropertyIndex\Manager::markAsInvalid($productIblockId);
        $indexer = \Bitrix\Iblock\PropertyIndex\Manager::createIndexer($productIblockId);
        if (!is_object($indexer)) {
            throw new \RuntimeException('Unable to create catalog property indexer');
        }
        $indexer->startIndex();
        try {
            $indexed = $indexer->continueIndex(0);
            $indexer->endIndex();
        } catch (\Throwable $error) {
            try {
                \Bitrix\Iblock\PropertyIndex\Manager::markAsInvalid($productIblockId);
            } catch (\Throwable $ignored) {
                // Preserve the indexing exception; the failed run must never
                // be reported as a valid facet index.
            }
            throw $error;
        }

        foreach ([$productIblockId, $offerIblockId] as $iblockId) {
            \CIBlock::CleanCache($iblockId);
            if (is_callable(['\CIBlock', 'clearIblockTagCache'])) {
                \CIBlock::clearIblockTagCache($iblockId);
            }
        }
        \Bitrix\Main\Data\Cache::createInstance()->cleanDir(
            '/prospektweb/propvalmanager/property_value_descriptions'
        );

        return [
            'status' => 'ok',
            'productIblockId' => $productIblockId,
            'offerIblockId' => $offerIblockId,
            'indexed' => $indexed,
            'descriptionCacheCleared' => true,
        ];
    }

    /** @return int[] */
    private function propertyElementIds(int $iblockId, int $elementId, string $code): array
    {
        $ids = [];
        if ($iblockId <= 0 || $elementId <= 0) {
            return [];
        }
        $cursor = \CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['CODE' => $code]);
        while ($row = $cursor->Fetch()) {
            $id = (int)($row['VALUE'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /**
     * @return array{
     *     assignments:array<int,array<string,mixed>>,
     *     conflicts:array<int,array<string,mixed>>,
     *     acceptedDeprecatedPresetConsumers:array<int,array<string,mixed>>,
     *     warnings:array<int,array<string,mixed>>
     * }
     */
    private function auditSourcePresetConsumers(
        array $state,
        int $presetId,
        bool $allowLegacyPresetBreakage = false
    ): array {
        $assignments = [];
        $deprecatedConsumers = [];
        foreach (array_keys((array)$state['productValues']) as $productId) {
            $presetIds = $this->propertyElementIds(
                (int)$state['productIblockId'],
                (int)$productId,
                'CALC_PRESET'
            );
            if ($presetIds === []) {
                continue;
            }
            $assignments[] = [
                'productId' => (int)$productId,
                'presetIds' => $presetIds,
            ];
            foreach ($presetIds as $assignedPresetId) {
                if ((int)$assignedPresetId !== $presetId) {
                    $deprecatedConsumers[] = [
                        'type' => 'migrated_source_used_by_other_preset',
                        'productId' => (int)$productId,
                        'presetId' => (int)$assignedPresetId,
                        'migrationPresetId' => $presetId,
                    ];
                }
            }
        }
        return ['assignments' => $assignments]
            + self::classifyDeprecatedPresetConsumers(
                $deprecatedConsumers,
                $allowLegacyPresetBreakage
            );
    }

    /** @return array<string,mixed> */
    private function collectPresetGraph(int $presetId): array
    {
        $config = new ConfigManager();
        $presetIblockId = $config->getIblockId('CALC_PRESETS');
        $detailsIblockId = $config->getIblockId('CALC_DETAILS');
        $stagesIblockId = $config->getIblockId('CALC_STAGES');
        $settingsIblockId = $config->getIblockId('CALC_SETTINGS');

        $stageIds = array_fill_keys($this->propertyElementIds($presetIblockId, $presetId, 'CALC_STAGES'), true);
        $detailIds = [];
        $queue = $this->propertyElementIds($presetIblockId, $presetId, 'CALC_DETAILS');
        while ($queue !== []) {
            $detailId = (int)array_shift($queue);
            if ($detailId <= 0 || isset($detailIds[$detailId])) {
                continue;
            }
            $detailIds[$detailId] = true;
            foreach ($this->propertyElementIds($detailsIblockId, $detailId, 'CALC_STAGES') as $stageId) {
                $stageIds[$stageId] = true;
            }
            foreach ($this->propertyElementIds($detailsIblockId, $detailId, 'DETAILS') as $childId) {
                if (!isset($detailIds[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        $settingsIds = [];
        foreach (array_keys($stageIds) as $stageId) {
            $cursor = \CIBlockElement::GetProperty($stagesIblockId, (int)$stageId, ['sort' => 'asc'], []);
            while ($row = $cursor->Fetch()) {
                if ((int)($row['LINK_IBLOCK_ID'] ?? 0) !== $settingsIblockId) {
                    continue;
                }
                $settingsId = (int)($row['VALUE'] ?? 0);
                if ($settingsId > 0) {
                    $settingsIds[$settingsId] = true;
                }
            }
        }
        $elements = [
            ['storage' => 'preset', 'iblockId' => $presetIblockId, 'elementId' => $presetId],
        ];
        foreach (array_keys($detailIds) as $id) {
            $elements[] = ['storage' => 'detail', 'iblockId' => $detailsIblockId, 'elementId' => (int)$id];
        }
        foreach (array_keys($stageIds) as $id) {
            $elements[] = ['storage' => 'stage', 'iblockId' => $stagesIblockId, 'elementId' => (int)$id];
        }
        foreach (array_keys($settingsIds) as $id) {
            $elements[] = ['storage' => 'settings', 'iblockId' => $settingsIblockId, 'elementId' => (int)$id];
        }
        usort($elements, static function (array $left, array $right): int {
            return [$left['iblockId'], $left['elementId']] <=> [$right['iblockId'], $right['elementId']];
        });
        return [
            'presetIblockId' => $presetIblockId,
            'detailsIblockId' => $detailsIblockId,
            'stagesIblockId' => $stagesIblockId,
            'settingsIblockId' => $settingsIblockId,
            'detailIds' => array_values(array_map('intval', array_keys($detailIds))),
            'stageIds' => array_values(array_map('intval', array_keys($stageIds))),
            'settingsIds' => array_values(array_map('intval', array_keys($settingsIds))),
            'elements' => $elements,
        ];
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private function elementPropertyRows(int $iblockId, int $elementId): array
    {
        $result = [];
        $cursor = \CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc', 'id' => 'asc'], []);
        while ($row = $cursor->Fetch()) {
            $code = trim((string)($row['CODE'] ?? ''));
            if ($code === '') {
                $code = (string)($row['ID'] ?? '');
            }
            $result[$code][] = $row;
        }
        return $result;
    }

    private function propertyRowText(array $row): string
    {
        $value = $row['~VALUE'] ?? $row['VALUE'] ?? '';
        if (is_array($value)) {
            return (string)($value['TEXT'] ?? '');
        }
        return is_scalar($value) ? (string)$value : '';
    }

    private function writeSinglePropertyText(int $iblockId, int $elementId, string $code, string $value): void
    {
        $property = $this->propertyByCode($iblockId, $code);
        if (!$property) {
            throw new \RuntimeException('Property ' . $code . ' is missing in iblock #' . $iblockId);
        }
        \CIBlockElement::SetPropertyValues($elementId, $iblockId, [], $code);
        if (strtoupper((string)($property['USER_TYPE'] ?? '')) === 'HTML') {
            $storedValue = ['VALUE' => ['TEXT' => $value, 'TYPE' => 'TEXT']];
        } else {
            $storedValue = $value;
        }
        \CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [$code => $storedValue]);
        $rows = $this->elementPropertyRows($iblockId, $elementId);
        $stored = isset($rows[$code][0]) ? $this->propertyRowText($rows[$code][0]) : '';
        if (html_entity_decode($stored, ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $value) {
            throw new \RuntimeException('Failed to verify ' . $code . ' on element #' . $elementId);
        }
    }

    private static function hasLegacyReference($value): bool
    {
        if (is_string($value)) {
            return self::stringHasLegacyReference($value);
        }
        if (!is_array($value)) {
            return false;
        }
        if (isset($value['productPropertyCodes']) && is_array($value['productPropertyCodes'])) {
            foreach ($value['productPropertyCodes'] as $code) {
                if (isset(self::PROPERTY_MAP[(string)$code])) {
                    return true;
                }
            }
        }
        if (isset($value['productValues']) && is_array($value['productValues'])) {
            foreach (array_keys($value['productValues']) as $code) {
                if (isset(self::PROPERTY_MAP[(string)$code])) {
                    return true;
                }
            }
        }
        if (isset($value['productProperties']) && is_array($value['productProperties'])) {
            foreach ($value['productProperties'] as $property) {
                if (is_array($property) && isset(self::PROPERTY_MAP[(string)($property['code'] ?? '')])) {
                    return true;
                }
            }
        }
        if (isset($value['seed_property_code'])) {
            $seed = (string)$value['seed_property_code'];
            $source = trim((string)($value['seed_property_source'] ?? 'product'));
            if (isset(self::PROPERTY_MAP[$seed])
                || (in_array($seed, array_values(self::PROPERTY_MAP), true) && $source !== 'offer')) {
                return true;
            }
        }
        foreach ($value as $nested) {
            if (self::hasLegacyReference($nested)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int,array<string,mixed>> */
    private function scanGraphLegacyReferences(array $graph): array
    {
        $references = [];
        foreach ($graph['elements'] as $element) {
            foreach ($this->elementPropertyRows((int)$element['iblockId'], (int)$element['elementId']) as $code => $rows) {
                foreach ($rows as $index => $row) {
                    $raw = $this->propertyRowText($row);
                    if ($raw === '') {
                        continue;
                    }
                    $decoded = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                    $hasReference = is_array($decoded)
                        ? self::hasLegacyReference($decoded)
                        : self::hasLegacyReference($raw);
                    if ($hasReference) {
                        $references[] = [
                            'storage' => (string)$element['storage'],
                            'iblockId' => (int)$element['iblockId'],
                            'elementId' => (int)$element['elementId'],
                            'propertyCode' => $code,
                            'row' => $index,
                        ];
                    }
                }
            }
        }
        return $references;
    }

    /** @return array{plans:array<int,array<string,mixed>>,conflicts:array<int,array<string,mixed>>} */
    private function auditGraphRewrites(array $graph): array
    {
        $plans = [];
        $conflicts = [];
        foreach ($graph['elements'] as $element) {
            foreach ($this->elementPropertyRows((int)$element['iblockId'], (int)$element['elementId']) as $code => $rows) {
                if ((string)$element['storage'] === 'settings'
                    && (int)$element['elementId'] === self::SETTINGS_ID
                    && (string)$code === 'AI_CONTEXT_JSON') {
                    continue;
                }
                $legacyRows = [];
                foreach ($rows as $index => $row) {
                    $raw = $this->propertyRowText($row);
                    if (self::rawHasLegacyReference($raw)) {
                        $legacyRows[(int)$index] = $raw;
                    }
                }
                if ($legacyRows === []) {
                    continue;
                }
                $identity = [
                    'storage' => (string)$element['storage'],
                    'iblockId' => (int)$element['iblockId'],
                    'elementId' => (int)$element['elementId'],
                    'propertyCode' => (string)$code,
                ];
                if (count($rows) !== 1 || count($legacyRows) !== 1) {
                    $conflicts[] = ['type' => 'legacy_graph_property_not_single_valued'] + $identity;
                    continue;
                }
                try {
                    $rewritten = self::rewriteJsonReferences((string)reset($legacyRows));
                } catch (\Throwable $error) {
                    $conflicts[] = [
                        'type' => 'legacy_graph_json_rewrite_conflict',
                        'message' => $error->getMessage(),
                    ] + $identity;
                    continue;
                }
                $decoded = is_string($rewritten) ? json_decode($rewritten, true) : null;
                if (!is_string($rewritten) || !is_array($decoded) || self::hasLegacyReference($decoded)) {
                    $conflicts[] = ['type' => 'legacy_graph_reference_not_safely_rewritable'] + $identity;
                    continue;
                }
                $plans[] = $identity + [
                    'sourceSha256' => hash('sha256', (string)reset($legacyRows)),
                    'rewrittenSha256' => hash('sha256', $rewritten),
                ];
            }
        }
        return ['plans' => $plans, 'conflicts' => $conflicts];
    }

    private static function rawHasLegacyReference(string $raw): bool
    {
        if ($raw === '') {
            return false;
        }
        $decoded = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        return is_array($decoded)
            ? self::hasLegacyReference($decoded)
            : self::hasLegacyReference($raw);
    }

    /** @return array{elementIds:int[],count:int,references:array<int,array<string,mixed>>} */
    private function scanPresetGlobalLegacyReferences(int $presetId): array
    {
        $iblockId = (new ConfigManager())->getIblockId('CALC_GLOBAL_VALUES');
        $elementIds = [];
        $references = [];
        $cursor = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, '=PROPERTY_PRESET_ID' => $presetId],
            false,
            false,
            ['ID', 'CODE', 'PREVIEW_TEXT', 'DETAIL_TEXT']
        );
        while ($element = $cursor->Fetch()) {
            $elementId = (int)($element['ID'] ?? 0);
            if ($elementId <= 0) {
                continue;
            }
            $elementIds[] = $elementId;
            foreach (['PREVIEW_TEXT', 'DETAIL_TEXT'] as $field) {
                $raw = (string)($element[$field] ?? '');
                if (self::rawHasLegacyReference($raw)) {
                    $references[] = [
                        'elementId' => $elementId,
                        'code' => (string)($element['CODE'] ?? ''),
                        'storage' => strtolower($field),
                    ];
                }
            }
            foreach ($this->elementPropertyRows($iblockId, $elementId) as $propertyCode => $rows) {
                foreach ($rows as $index => $row) {
                    if (self::rawHasLegacyReference($this->propertyRowText($row))) {
                        $references[] = [
                            'elementId' => $elementId,
                            'code' => (string)($element['CODE'] ?? ''),
                            'storage' => 'property',
                            'propertyCode' => (string)$propertyCode,
                            'row' => (int)$index,
                            'rowCount' => count($rows),
                        ];
                    }
                }
            }
        }
        sort($elementIds, SORT_NUMERIC);
        return [
            'elementIds' => $elementIds,
            'count' => count($elementIds),
            'references' => $references,
        ];
    }

    /**
     * @return array{
     *     presetIds:int[],
     *     references:array<int,array<string,mixed>>,
     *     conflicts:array<int,array<string,mixed>>,
     *     acceptedDeprecatedPresetConsumers:array<int,array<string,mixed>>,
     *     warnings:array<int,array<string,mixed>>
     * }
     */
    private function auditOtherPresetConsumers(
        int $migrationPresetId,
        bool $allowLegacyPresetBreakage = false
    ): array {
        $presetIblockId = (new ConfigManager())->getIblockId('CALC_PRESETS');
        $presetIds = [];
        $references = [];
        $deprecatedConsumers = [];
        $cursor = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $presetIblockId],
            false,
            false,
            ['ID', 'CODE', 'NAME', 'ACTIVE']
        );
        while ($preset = $cursor->Fetch()) {
            $presetId = (int)($preset['ID'] ?? 0);
            if ($presetId <= 0 || $presetId === $migrationPresetId) {
                continue;
            }
            $presetIds[] = $presetId;
            foreach ($this->scanGraphLegacyReferences($this->collectPresetGraph($presetId)) as $reference) {
                $entry = [
                    'presetId' => $presetId,
                    'presetActive' => (string)($preset['ACTIVE'] ?? 'N'),
                    'scope' => 'graph',
                ] + $reference;
                $references[] = $entry;
                $deprecatedConsumers[] = ['type' => 'other_preset_legacy_reference'] + $entry;
            }
            $globalAudit = $this->scanPresetGlobalLegacyReferences($presetId);
            foreach ($globalAudit['references'] as $reference) {
                $entry = [
                    'presetId' => $presetId,
                    'presetActive' => (string)($preset['ACTIVE'] ?? 'N'),
                    'scope' => 'global',
                ] + $reference;
                $references[] = $entry;
                $deprecatedConsumers[] = ['type' => 'other_preset_legacy_reference'] + $entry;
            }
        }
        return [
            'presetIds' => $presetIds,
            'references' => $references,
        ] + self::classifyDeprecatedPresetConsumers(
            $deprecatedConsumers,
            $allowLegacyPresetBreakage
        );
    }

    private function rewriteGraphJsonReferences(array $graph, array $plans): void
    {
        foreach ($plans as $plan) {
            $iblockId = (int)($plan['iblockId'] ?? 0);
            $elementId = (int)($plan['elementId'] ?? 0);
            $code = (string)($plan['propertyCode'] ?? '');
            $rows = $this->elementPropertyRows($iblockId, $elementId)[$code] ?? [];
            if (count($rows) !== 1) {
                throw new \RuntimeException(
                    'Preset graph CAS failed: ' . $code . ' on element #' . $elementId . ' is no longer single-valued'
                );
            }
            $raw = $this->propertyRowText($rows[0]);
            if (!hash_equals((string)($plan['sourceSha256'] ?? ''), hash('sha256', $raw))) {
                throw new \RuntimeException('Preset graph CAS mismatch for ' . $code . ' on element #' . $elementId);
            }
            $rewritten = self::rewriteJsonReferences($raw);
            if (!is_string($rewritten)
                || !hash_equals((string)($plan['rewrittenSha256'] ?? ''), hash('sha256', $rewritten))) {
                throw new \RuntimeException('Preset graph rewrite drift for ' . $code . ' on element #' . $elementId);
            }
            $this->writeSinglePropertyText($iblockId, $elementId, $code, $rewritten);
        }
    }

    /** @return array<string,mixed> */
    private function auditAiContextPlan(): array
    {
        $settingsIblockId = (new ConfigManager())->getIblockId('CALC_SETTINGS');
        $rows = $this->elementPropertyRows($settingsIblockId, self::SETTINGS_ID)['AI_CONTEXT_JSON'] ?? [];
        if (count($rows) !== 1) {
            return [
                'sourceSha256' => '',
                'conflicts' => [[
                    'type' => 'ai_context_not_single_valued',
                    'settingsId' => self::SETTINGS_ID,
                    'rowCount' => count($rows),
                ]],
            ];
        }
        $raw = $this->propertyRowText($rows[0]);
        $context = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if (!is_array($context)
            || (string)($context['schema'] ?? '') !== 'prospektweb.calc.ai-calculator-context/v1') {
            return [
                'sourceSha256' => hash('sha256', $raw),
                'conflicts' => [['type' => 'ai_context_invalid', 'settingsId' => self::SETTINGS_ID]],
            ];
        }
        $hasExpectedProduct = false;
        foreach ((array)($context['baseProducts'] ?? []) as $product) {
            if (is_array($product) && (int)($product['productId'] ?? 0) === 12727) {
                $hasExpectedProduct = true;
                break;
            }
        }
        return [
            'sourceSha256' => hash('sha256', $raw),
            'conflicts' => $hasExpectedProduct
                ? []
                : [['type' => 'ai_context_base_product_missing', 'productId' => 12727]],
        ];
    }

    /** @return array<string,mixed> */
    private function auditPresetRefactor(int $presetId, bool $applySemanticFixes): array
    {
        $config = new ConfigManager();
        $globalsIblockId = $config->getIblockId('CALC_GLOBAL_VALUES');
        $conflicts = [];
        $warnings = [];
        $parityChanges = [];
        $semanticChanges = [];
        $globalWritePlans = [];
        foreach (self::GLOBAL_FORMULAS as $id => $spec) {
            $element = \CIBlockElement::GetList(
                [],
                ['ID' => $id, 'IBLOCK_ID' => $globalsIblockId],
                false,
                ['nTopCount' => 1],
                ['ID', 'CODE', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE']
            )->Fetch();
            if (!$element || (string)($element['CODE'] ?? '') !== $spec['code']) {
                $conflicts[] = ['type' => 'global_missing_or_code_mismatch', 'elementId' => $id, 'expectedCode' => $spec['code']];
                continue;
            }
            $presetRows = $this->elementPropertyRows($globalsIblockId, $id);
            $owner = (int)($presetRows['PRESET_ID'][0]['VALUE'] ?? 0);
            if ($owner !== $presetId) {
                $conflicts[] = ['type' => 'global_preset_mismatch', 'elementId' => $id, 'actualPresetId' => $owner];
            }
            if (count($presetRows['INITIAL_VALUE'] ?? []) !== 1) {
                $conflicts[] = [
                    'type' => 'global_initial_value_not_single_valued',
                    'elementId' => $id,
                    'rowCount' => count($presetRows['INITIAL_VALUE'] ?? []),
                ];
                continue;
            }
            $formula = $this->propertyRowText($presetRows['INITIAL_VALUE'][0]);
            $description = (string)($element['PREVIEW_TEXT'] ?? '');
            $expectedFormula = $this->expectedFormulaForMode($id, $applySemanticFixes, $formula);
            $expectedDescription = $this->rewriteEntityDescription($description);
            $globalWritePlans[] = [
                'elementId' => $id,
                'code' => $spec['code'],
                'formulaSourceSha256' => hash('sha256', $formula),
                'expectedFormula' => $expectedFormula,
                'descriptionSourceSha256' => hash('sha256', $description),
                'expectedDescription' => $expectedDescription,
                'previewTextType' => (string)($element['PREVIEW_TEXT_TYPE'] ?? 'text'),
            ];
            if (in_array($id, [12794, 12796], true)) {
                if ($formula !== $spec['formula']) {
                    $semanticChanges[] = ['elementId' => $id, 'code' => $spec['code'], 'reason' => 'empty_formula_fix'];
                }
                continue;
            }
            $parityExpected = $this->expectedFormulaForMode($id, false, $formula);
            if ($formula !== $parityExpected) {
                $parityChanges[] = ['elementId' => $id, 'code' => $spec['code']];
            }
            if ($id === 12793 && strpos($formula, 'design-paper') === false) {
                $semanticChanges[] = ['elementId' => $id, 'code' => $spec['code'], 'reason' => 'add_design_paper'];
            }
        }
        if ($semanticChanges !== [] && !$applySemanticFixes) {
            $warnings[] = [
                'type' => 'semantic_fixes_require_explicit_flag',
                'changes' => $semanticChanges,
            ];
        }

        $globalAudit = $this->scanPresetGlobalLegacyReferences($presetId);
        if ((int)$globalAudit['count'] !== self::EXPECTED_PRESET_GLOBAL_COUNT) {
            $conflicts[] = [
                'type' => 'preset_global_count_mismatch',
                'presetId' => $presetId,
                'expected' => self::EXPECTED_PRESET_GLOBAL_COUNT,
                'actual' => (int)$globalAudit['count'],
            ];
        }
        $unknownGlobalReferences = [];
        foreach ($globalAudit['references'] as $reference) {
            $isExactKnownFormula = isset(self::GLOBAL_FORMULAS[(int)($reference['elementId'] ?? 0)])
                && (string)($reference['storage'] ?? '') === 'property'
                && (string)($reference['propertyCode'] ?? '') === 'INITIAL_VALUE'
                && (int)($reference['row'] ?? -1) === 0
                && (int)($reference['rowCount'] ?? 0) === 1;
            if ($isExactKnownFormula) {
                continue;
            }
            $unknownGlobalReferences[] = $reference;
            $conflicts[] = ['type' => 'unknown_global_legacy_reference'] + $reference;
        }

        $graph = $this->collectPresetGraph($presetId);
        if (!in_array(self::EQUIPMENT_MAPPING_STAGE_ID, $graph['stageIds'], true)) {
            $conflicts[] = ['type' => 'required_stage_not_linked', 'stageId' => self::EQUIPMENT_MAPPING_STAGE_ID];
        }
        if (!in_array(self::SETTINGS_ID, $graph['settingsIds'], true)) {
            $conflicts[] = ['type' => 'required_settings_not_linked', 'settingsId' => self::SETTINGS_ID];
        }
        $legacyReferences = $this->scanGraphLegacyReferences($graph);
        $graphRewriteAudit = $this->auditGraphRewrites($graph);
        foreach ($graphRewriteAudit['conflicts'] as $conflict) {
            $conflicts[] = $conflict;
        }
        $aiContextPlan = $this->auditAiContextPlan();
        foreach ($aiContextPlan['conflicts'] as $conflict) {
            $conflicts[] = $conflict;
        }
        return [
            'summary' => [
                'parityChanges' => $parityChanges,
                'semanticFixes' => $semanticChanges,
                'semanticFixesRequested' => $applySemanticFixes,
                'globalCount' => (int)$globalAudit['count'],
                'globalWritePlans' => $globalWritePlans,
                'allGlobalLegacyReferences' => $globalAudit['references'],
                'unknownGlobalLegacyReferences' => $unknownGlobalReferences,
                'legacyLinkedElementReferences' => $legacyReferences,
                'safeLinkedElementRewritePlans' => $graphRewriteAudit['plans'],
                'aiContextPlan' => $aiContextPlan,
                'stageCount' => count($graph['stageIds']),
                'settingsCount' => count($graph['settingsIds']),
            ],
            'conflicts' => $conflicts,
            'warnings' => $warnings,
        ];
    }

    private function expectedFormulaForMode(int $id, bool $semanticFixes, string $current): string
    {
        $full = self::expectedGlobalFormula($id);
        if ($semanticFixes) {
            return $full;
        }
        if (in_array($id, [12794, 12796], true)) {
            return $current;
        }
        if ($id === 12793 && strpos($current, 'design-paper') === false) {
            return str_replace(
                ' || get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "design-paper"',
                '',
                $full
            );
        }
        return $full;
    }

    private function applyPresetRefactor(
        int $presetId,
        bool $applySemanticFixes,
        array $globalWritePlans,
        array $graphRewritePlans,
        string $aiContextSourceSha256
    ): void
    {
        $config = new ConfigManager();
        $globalsIblockId = $config->getIblockId('CALC_GLOBAL_VALUES');
        foreach ($globalWritePlans as $plan) {
            $id = (int)($plan['elementId'] ?? 0);
            if (!isset(self::GLOBAL_FORMULAS[$id])) {
                throw new \RuntimeException('Unexpected global write plan #' . $id);
            }
            $rows = $this->elementPropertyRows($globalsIblockId, $id);
            if (count($rows['INITIAL_VALUE'] ?? []) !== 1) {
                throw new \RuntimeException('Global INITIAL_VALUE CAS failed on #' . $id);
            }
            $current = $this->propertyRowText($rows['INITIAL_VALUE'][0]);
            if (!hash_equals((string)($plan['formulaSourceSha256'] ?? ''), hash('sha256', $current))) {
                throw new \RuntimeException('Global formula CAS mismatch on #' . $id);
            }
            $expected = (string)($plan['expectedFormula'] ?? '');
            if ($expected !== $this->expectedFormulaForMode($id, $applySemanticFixes, $current)) {
                throw new \RuntimeException('Global formula rewrite drift on #' . $id);
            }
            if ($expected !== $current) {
                $this->writeSinglePropertyText($globalsIblockId, $id, 'INITIAL_VALUE', $expected);
            }
            $element = \CIBlockElement::GetList(
                [],
                ['ID' => $id, 'IBLOCK_ID' => $globalsIblockId],
                false,
                ['nTopCount' => 1],
                ['ID', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE']
            )->Fetch();
            $description = (string)($element['PREVIEW_TEXT'] ?? '');
            if ((string)($element['PREVIEW_TEXT_TYPE'] ?? 'text')
                !== (string)($plan['previewTextType'] ?? 'text')) {
                throw new \RuntimeException('Global description type CAS mismatch on #' . $id);
            }
            if (!hash_equals(
                (string)($plan['descriptionSourceSha256'] ?? ''),
                hash('sha256', $description)
            )) {
                throw new \RuntimeException('Global description CAS mismatch on #' . $id);
            }
            $rewrittenDescription = (string)($plan['expectedDescription'] ?? '');
            if ($rewrittenDescription !== $this->rewriteEntityDescription($description)) {
                throw new \RuntimeException('Global description rewrite drift on #' . $id);
            }
            if ($rewrittenDescription !== $description) {
                $api = new \CIBlockElement();
                if (!$api->Update($id, [
                    'PREVIEW_TEXT' => $rewrittenDescription,
                    'PREVIEW_TEXT_TYPE' => (string)($plan['previewTextType'] ?? 'text'),
                ])) {
                    throw new \RuntimeException('Unable to update description for global #' . $id);
                }
            }
        }

        $graph = $this->collectPresetGraph($presetId);
        $this->rewriteGraphJsonReferences($graph, $graphRewritePlans);
        $this->assertEquipmentMapping($graph, true);
        $this->refactorAiContext($aiContextSourceSha256);
    }

    private function writeSemanticFormulas(bool $enabled, array $globalWritePlans): void
    {
        $globalsIblockId = (new ConfigManager())->getIblockId('CALC_GLOBAL_VALUES');
        $designer = self::expectedGlobalFormula(12793);
        if (!$enabled) {
            $designer = str_replace(
                ' || get(offer, "properties.CALC_PROP_TYPE_PAPER.VALUE_XML_ID") == "design-paper"',
                '',
                $designer
            );
        }
        $formulas = [
            12793 => $designer,
            12794 => $enabled ? self::expectedGlobalFormula(12794) : '',
            12796 => $enabled ? self::expectedGlobalFormula(12796) : '',
        ];
        $plansById = [];
        foreach ($globalWritePlans as $plan) {
            $plansById[(int)($plan['elementId'] ?? 0)] = $plan;
        }
        foreach ($formulas as $elementId => $formula) {
            $rows = $this->elementPropertyRows($globalsIblockId, $elementId)['INITIAL_VALUE'] ?? [];
            $current = count($rows) === 1 ? $this->propertyRowText($rows[0]) : null;
            $plan = $plansById[$elementId] ?? [];
            if (!is_string($current)
                || !hash_equals((string)($plan['formulaSourceSha256'] ?? ''), hash('sha256', $current))) {
                throw new \RuntimeException('Semantic global formula CAS mismatch on #' . $elementId);
            }
            $this->writeSinglePropertyText($globalsIblockId, $elementId, 'INITIAL_VALUE', $formula);
        }
    }

    private function rewriteEntityDescription(string $description): string
    {
        $rewritten = str_ireplace(
            [
                'свойствам товара', 'свойству товара', 'свойства товара', 'свойство товара',
                'характеристикам товара', 'характеристике товара', 'характеристики товара',
            ],
            [
                'свойствам торгового предложения', 'свойству торгового предложения',
                'свойства торгового предложения', 'свойство торгового предложения',
                'характеристикам торгового предложения', 'характеристике торгового предложения',
                'характеристики торгового предложения',
            ],
            $description
        );
        return (string)preg_replace(
            ['/\bтовара\b/ui', '/\bтоваре\b/ui', '/\bтовар\b/ui'],
            ['торгового предложения', 'торговом предложении', 'торговое предложение'],
            $rewritten
        );
    }

    private function assertEquipmentMapping(array $graph, bool $rewrite): void
    {
        $rows = $this->elementPropertyRows((int)$graph['stagesIblockId'], self::EQUIPMENT_MAPPING_STAGE_ID);
        $raw = isset($rows['OPTIONS_EQUIPMENT'][0]) ? $this->propertyRowText($rows['OPTIONS_EQUIPMENT'][0]) : '';
        $normalized = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = json_decode($normalized, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Stage #' . self::EQUIPMENT_MAPPING_STAGE_ID . ' OPTIONS_EQUIPMENT is invalid JSON');
        }
        if ($rewrite) {
            $rewritten = self::rewriteJsonReferences($raw);
            $json = is_string($rewritten) ? $rewritten : $normalized;
            if (is_string($rewritten)) {
                $this->writeSinglePropertyText(
                    (int)$graph['stagesIblockId'],
                    self::EQUIPMENT_MAPPING_STAGE_ID,
                    'OPTIONS_EQUIPMENT',
                    $json
                );
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Unable to re-read stage equipment mapping');
            }
        }
        $offerCodes = array_values(array_map('strval', (array)($decoded['offerPropertyCodes'] ?? [])));
        $productCodes = array_values(array_map('strval', (array)($decoded['productPropertyCodes'] ?? [])));
        if (!in_array('CALC_PROP_METHOD', $offerCodes, true) || in_array('CALC_METHOD', $productCodes, true)) {
            throw new \RuntimeException('Stage equipment mapping did not move CALC_METHOD to offer values');
        }
        $expected = ['DIGITAL' => 1083, 'OFSET' => 1085];
        $found = [];
        foreach ((array)($decoded['mappings'] ?? []) as $mapping) {
            $xmlId = (string)($mapping['offerValues']['CALC_PROP_METHOD']['xmlId'] ?? '');
            $variantId = (int)($mapping['variantId'] ?? 0);
            if ($xmlId !== '') {
                $found[$xmlId] = $variantId;
            }
            if (isset($mapping['productValues']['CALC_METHOD'])) {
                throw new \RuntimeException('Legacy productValues.CALC_METHOD remains in equipment mapping');
            }
        }
        foreach ($expected as $xmlId => $variantId) {
            if ((int)($found[$xmlId] ?? 0) !== $variantId) {
                throw new \RuntimeException('Equipment mapping mismatch for ' . $xmlId);
            }
        }
    }

    private function refactorAiContext(string $expectedSourceSha256): void
    {
        $settingsIblockId = (new ConfigManager())->getIblockId('CALC_SETTINGS');
        $rows = $this->elementPropertyRows($settingsIblockId, self::SETTINGS_ID);
        if (count($rows['AI_CONTEXT_JSON'] ?? []) !== 1) {
            throw new \RuntimeException('AI_CONTEXT_JSON CAS failed because the property is no longer single-valued');
        }
        $raw = isset($rows['AI_CONTEXT_JSON'][0]) ? $this->propertyRowText($rows['AI_CONTEXT_JSON'][0]) : '';
        if ($expectedSourceSha256 === ''
            || !hash_equals($expectedSourceSha256, hash('sha256', $raw))) {
            throw new \RuntimeException('AI_CONTEXT_JSON CAS mismatch');
        }
        $normalized = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $context = json_decode($normalized, true);
        $originalShape = json_decode($normalized, false);
        if (!is_array($context)
            || (string)($context['schema'] ?? '') !== 'prospektweb.calc.ai-calculator-context/v1') {
            throw new \RuntimeException('Settings #' . self::SETTINGS_ID . ' AI_CONTEXT_JSON has an unsupported schema');
        }
        $productIds = [];
        foreach ((array)($context['baseProducts'] ?? []) as $product) {
            $productId = (int)($product['productId'] ?? 0);
            if ($productId > 0) {
                $productIds[$productId] = $productId;
            }
        }
        if (!isset($productIds[12727])) {
            throw new \RuntimeException('AI_CONTEXT_JSON does not contain expected base product #12727');
        }
        $service = new \Prospektweb\Calc\Services\AiCalculatorContextService();
        $freshResponse = $service->getBaseProducts([
            'mode' => 'details',
            'productIds' => array_values($productIds),
        ]);
        $freshProducts = is_array($freshResponse['products'] ?? null) ? $freshResponse['products'] : [];
        $freshById = [];
        foreach ($freshProducts as $freshProduct) {
            if (is_array($freshProduct)) {
                $freshById[(int)($freshProduct['productId'] ?? 0)] = $freshProduct;
            }
        }
        $clean = [];
        foreach ((array)$context['baseProducts'] as $old) {
            if (!is_array($old)) {
                continue;
            }
            $productId = (int)($old['productId'] ?? 0);
            $fresh = $freshById[$productId] ?? null;
            if (!is_array($fresh)) {
                throw new \RuntimeException('Fresh AI context product is missing for #' . $productId);
            }

            $freshOfferByCode = [];
            foreach (['availableOfferProperties', 'offerProperties'] as $collection) {
                foreach ((array)($fresh[$collection] ?? []) as $property) {
                    if (is_array($property) && (string)($property['code'] ?? '') !== '') {
                        $freshOfferByCode[(string)$property['code']] = $property;
                    }
                }
            }

            $productProperties = [];
            $movedOfferProperties = [];
            foreach ((array)($old['productProperties'] ?? []) as $property) {
                if (!is_array($property)) {
                    continue;
                }
                $sourceCode = (string)($property['code'] ?? '');
                $targetCode = self::PROPERTY_MAP[$sourceCode] ?? '';
                if ($targetCode === '') {
                    $productProperties[] = $property;
                    continue;
                }
                if (!isset($freshOfferByCode[$targetCode])) {
                    throw new \RuntimeException(
                        'Fresh AI context offer property ' . $targetCode . ' is missing for product #' . $productId
                    );
                }
                $movedOfferProperties[$targetCode] = self::mergeAiContextSelectedProperty(
                    $freshOfferByCode[$targetCode],
                    $property,
                    $targetCode
                );
            }

            $existingOfferProperties = [];
            foreach ((array)($old['offerProperties'] ?? []) as $property) {
                if (!is_array($property)) {
                    continue;
                }
                $code = (string)($property['code'] ?? '');
                if (isset($movedOfferProperties[$code])) {
                    $property = self::mergeAiContextSelectedProperty(
                        $movedOfferProperties[$code],
                        $property,
                        $code
                    );
                    unset($movedOfferProperties[$code]);
                }
                $existingOfferProperties[] = $property;
            }
            $updated = $old;
            $updated['productProperties'] = $productProperties;
            $updated['offerProperties'] = array_values(array_merge(
                array_values($movedOfferProperties),
                $existingOfferProperties
            ));
            $clean[] = $updated;
        }
        $context['baseProducts'] = $clean;
        $context = $this->rewriteAiContextAuxiliaryKeys($context);
        $shapeSafe = self::restoreJsonContainerShapes($context, $originalShape);
        if ($shapeSafe instanceof \stdClass) {
            // AiCalculatorContextService validates an array at the wrapper
            // level, while nested stdClass values retain the original JSON
            // object shape during its json_encode call.
            $context = get_object_vars($shapeSafe);
        } elseif (is_array($shapeSafe)) {
            $context = $shapeSafe;
        } else {
            throw new \RuntimeException('Unable to preserve AI_CONTEXT_JSON container shapes');
        }
        $latestRows = $this->elementPropertyRows($settingsIblockId, self::SETTINGS_ID)['AI_CONTEXT_JSON'] ?? [];
        $latestRaw = count($latestRows) === 1 ? $this->propertyRowText($latestRows[0]) : null;
        if (!is_string($latestRaw)
            || !hash_equals($expectedSourceSha256, hash('sha256', $latestRaw))) {
            throw new \RuntimeException('AI_CONTEXT_JSON CAS mismatch immediately before save');
        }
        $this->persistAiContextSelectionContract($clean);
        $service->save(['settingsId' => self::SETTINGS_ID, 'context' => $context]);
    }

    /** @param array<int,array<string,mixed>> $baseProducts */
    private function persistAiContextSelectionContract(array $baseProducts): void
    {
        $products = [];
        foreach ($baseProducts as $product) {
            $productId = (int)($product['productId'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $products[(string)$productId] = [
                'productPropertyCodes' => array_values(array_map(
                    static fn(array $property): string => (string)($property['code'] ?? ''),
                    array_values(array_filter(
                        (array)($product['productProperties'] ?? []),
                        'is_array'
                    ))
                )),
                'offerPropertyCodes' => array_values(array_map(
                    static fn(array $property): string => (string)($property['code'] ?? ''),
                    array_values(array_filter(
                        (array)($product['offerProperties'] ?? []),
                        'is_array'
                    ))
                )),
            ];
        }
        $contract = ['version' => 1, 'settingsId' => self::SETTINGS_ID, 'products' => $products];
        $encoded = json_encode($contract, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to serialize AI context selection contract');
        }
        $existing = trim((string)\Bitrix\Main\Config\Option::get(
            self::MODULE_ID,
            self::AI_CONTEXT_SELECTION_OPTION,
            ''
        ));
        if ($existing !== '') {
            $decoded = json_decode($existing, true);
            if (!is_array($decoded) || self::canonicalJson($decoded) !== self::canonicalJson($contract)) {
                throw new \RuntimeException('AI context selection contract mismatch');
            }
            return;
        }
        \Bitrix\Main\Config\Option::set(self::MODULE_ID, self::AI_CONTEXT_SELECTION_OPTION, $encoded);
    }

    private static function mergeAiContextSelectedProperty(
        array $fresh,
        array $selected,
        string $targetCode
    ): array {
        $result = $fresh;
        foreach ($selected as $key => $value) {
            if (in_array((string)$key, ['code', 'valueType', 'values'], true)) {
                continue;
            }
            $result[$key] = $value;
        }
        $result['code'] = $targetCode;
        return $result;
    }

    /** @return mixed */
    private function rewriteAiContextAuxiliaryKeys($value)
    {
        if (is_string($value)) {
            $value = self::rewriteReferenceString($value);
            foreach (self::PROPERTY_MAP as $sourceCode => $targetCode) {
                $value = (string)preg_replace(
                    '/\bproductProperties:' . preg_quote($sourceCode, '/') . '(?![A-Za-z0-9_])/',
                    'offerProperties:' . $targetCode,
                    $value
                );
            }
            return $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $nested) {
            $newKey = is_string($key) ? $this->rewriteAiContextAuxiliaryKeys($key) : $key;
            $result[$newKey] = $this->rewriteAiContextAuxiliaryKeys($nested);
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function verifyPresetRefactor(int $presetId, bool $requireSemanticFixes): array
    {
        $errors = [];
        $config = new ConfigManager();
        $globalsIblockId = $config->getIblockId('CALC_GLOBAL_VALUES');
        $globalAudit = $this->scanPresetGlobalLegacyReferences($presetId);
        if ((int)$globalAudit['count'] !== self::EXPECTED_PRESET_GLOBAL_COUNT) {
            $errors[] = [
                'type' => 'preset_global_count_mismatch',
                'presetId' => $presetId,
                'expected' => self::EXPECTED_PRESET_GLOBAL_COUNT,
                'actual' => (int)$globalAudit['count'],
            ];
        }
        foreach ($globalAudit['references'] as $reference) {
            $errors[] = ['type' => 'legacy_global_reference'] + $reference;
        }
        foreach (self::GLOBAL_FORMULAS as $id => $spec) {
            $rows = $this->elementPropertyRows($globalsIblockId, $id);
            $formula = isset($rows['INITIAL_VALUE'][0]) ? $this->propertyRowText($rows['INITIAL_VALUE'][0]) : '';
            if (!$requireSemanticFixes && in_array($id, [12794, 12796], true)) {
                if (self::hasLegacyReference($formula)) {
                    $errors[] = ['type' => 'legacy_global_formula', 'elementId' => $id];
                }
                continue;
            }
            $expected = $this->expectedFormulaForMode($id, $requireSemanticFixes, $formula);
            if ($formula !== $expected) {
                $errors[] = [
                    'type' => $id === 12793 || in_array($id, [12794, 12796], true)
                        ? 'semantic_global_formula_mismatch'
                        : 'global_formula_mismatch',
                    'elementId' => $id,
                    'code' => $spec['code'],
                ];
            }
        }
        $graph = $this->collectPresetGraph($presetId);
        foreach ($this->scanGraphLegacyReferences($graph) as $reference) {
            $errors[] = ['type' => 'legacy_linked_element_reference'] + $reference;
        }
        try {
            $this->assertEquipmentMapping($graph, false);
        } catch (\Throwable $error) {
            $errors[] = ['type' => 'equipment_mapping_invalid', 'message' => $error->getMessage()];
        }
        foreach ($this->verifyAiContext() as $error) {
            $errors[] = $error;
        }
        return $errors;
    }

    /** @return array<int,array<string,mixed>> */
    private function verifyAiContext(): array
    {
        $errors = [];
        $settingsIblockId = (new ConfigManager())->getIblockId('CALC_SETTINGS');
        $rows = $this->elementPropertyRows($settingsIblockId, self::SETTINGS_ID);
        $raw = isset($rows['AI_CONTEXT_JSON'][0]) ? $this->propertyRowText($rows['AI_CONTEXT_JSON'][0]) : '';
        $context = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if (!is_array($context) || ($context['schema'] ?? '') !== 'prospektweb.calc.ai-calculator-context/v1') {
            return [['type' => 'ai_context_invalid']];
        }
        $baseProduct = null;
        foreach ((array)($context['baseProducts'] ?? []) as $product) {
            if (is_array($product) && (int)($product['productId'] ?? 0) === 12727) {
                $baseProduct = $product;
                break;
            }
        }
        if (!$baseProduct) {
            return [['type' => 'ai_context_base_product_missing', 'productId' => 12727]];
        }
        foreach ((array)($baseProduct['productProperties'] ?? []) as $property) {
            $code = (string)($property['code'] ?? '');
            if (isset(self::PROPERTY_MAP[$code])) {
                $errors[] = ['type' => 'ai_context_legacy_product_property', 'code' => $code];
            }
        }
        if (self::hasLegacyReference($context)) {
            $errors[] = ['type' => 'ai_context_legacy_reference'];
        }
        $selectionRaw = trim((string)\Bitrix\Main\Config\Option::get(
            self::MODULE_ID,
            self::AI_CONTEXT_SELECTION_OPTION,
            ''
        ));
        $selection = json_decode($selectionRaw, true);
        if (!is_array($selection) || (int)($selection['settingsId'] ?? 0) !== self::SETTINGS_ID) {
            $errors[] = ['type' => 'ai_context_selection_contract_missing'];
            return $errors;
        }
        $actualSelections = [];
        foreach ((array)($context['baseProducts'] ?? []) as $product) {
            if (!is_array($product)) {
                continue;
            }
            $productId = (string)(int)($product['productId'] ?? 0);
            $actualSelections[$productId] = [
                'productPropertyCodes' => array_values(array_map(
                    static fn(array $property): string => (string)($property['code'] ?? ''),
                    array_values(array_filter((array)($product['productProperties'] ?? []), 'is_array'))
                )),
                'offerPropertyCodes' => array_values(array_map(
                    static fn(array $property): string => (string)($property['code'] ?? ''),
                    array_values(array_filter((array)($product['offerProperties'] ?? []), 'is_array'))
                )),
            ];
        }
        if (self::canonicalJson($actualSelections)
            !== self::canonicalJson((array)($selection['products'] ?? []))) {
            $errors[] = [
                'type' => 'ai_context_selected_property_set_mismatch',
                'expected' => $selection['products'] ?? [],
                'actual' => $actualSelections,
            ];
        }
        return $errors;
    }

    /** @return class-string|null */
    private function descriptionDataClass(): ?string
    {
        if (!Loader::includeModule('prospektweb.propvalmanager')
            || !Loader::includeModule('highloadblock')
            || !class_exists('\Prospektweb\PropValManager\Service\PropertyValueDescriptionInstaller')) {
            return null;
        }
        $installer = new \Prospektweb\PropValManager\Service\PropertyValueDescriptionInstaller();
        $hlBlock = $installer->getHighloadBlock();
        if (!is_array($hlBlock)) {
            return null;
        }
        $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlBlock);
        $dataClass = $entity->getDataClass();
        return is_string($dataClass) && $dataClass !== '' ? $dataClass : null;
    }

    /** @return array<int,array<string,mixed>> */
    private function descriptionRows(array $propertyIds = []): array
    {
        $dataClass = $this->descriptionDataClass();
        if ($dataClass === null) {
            return [];
        }
        $filter = [];
        $propertyIds = array_values(array_unique(array_filter(array_map('intval', $propertyIds))));
        if ($propertyIds !== []) {
            $filter['@UF_PROPERTY_ID'] = $propertyIds;
        }
        $result = [];
        $cursor = $dataClass::getList([
            'filter' => $filter,
            'select' => ['*'],
            'order' => ['ID' => 'ASC'],
        ]);
        while ($row = $cursor->fetch()) {
            $result[] = $row;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function auditDescriptions(): array
    {
        $state = $this->catalogState();
        $sourceIds = [];
        $targetIds = [];
        $propertyPairs = [];
        foreach (self::PROPERTY_MAP as $sourceCode => $targetCode) {
            $source = $this->propertyByCode((int)$state['productIblockId'], $sourceCode);
            $target = $this->propertyByCode((int)$state['offerIblockId'], $targetCode);
            if ($source) {
                $sourceIds[] = (int)$source['ID'];
            }
            if ($target) {
                $targetIds[] = (int)$target['ID'];
            }
            if ($source) {
                $propertyPairs[] = [
                    'sourceCode' => $sourceCode,
                    'targetCode' => $targetCode,
                    'sourceId' => (int)$source['ID'],
                    'targetId' => (int)($target['ID'] ?? 0),
                ];
            }
        }
        if ($this->descriptionDataClass() === null) {
            return [
                'status' => 'unavailable',
                'sourceBindingCount' => 0,
                'targetBindingCount' => 0,
                'conflicts' => [['type' => 'description_storage_unavailable']],
                'warnings' => [],
            ];
        }
        $rows = $this->descriptionRows(array_merge($sourceIds, $targetIds));
        $sourceLookup = array_fill_keys($sourceIds, true);
        $targetLookup = array_fill_keys($targetIds, true);
        $conflicts = [];
        $warnings = [];
        foreach ($propertyPairs as $pair) {
            $sourceGroups = [];
            $targetGroups = [];
            foreach ($rows as $row) {
                $propertyId = (int)($row['UF_PROPERTY_ID'] ?? 0);
                $xmlId = trim((string)($row['UF_VALUE_XML_ID'] ?? ''));
                if ($xmlId === '') {
                    if ($propertyId === $pair['sourceId'] || $propertyId === $pair['targetId']) {
                        $conflicts[] = [
                            'type' => 'description_binding_without_xml_id',
                            'descriptionId' => (int)$row['ID'],
                            'propertyId' => $propertyId,
                        ];
                    }
                    continue;
                }
                if ($propertyId === $pair['sourceId']) {
                    $xmlId = self::canonicalTargetXmlId($pair['sourceCode'], $xmlId);
                    $sourceGroups[$xmlId][] = $row;
                } elseif ($propertyId === $pair['targetId']) {
                    $targetGroups[$xmlId][] = $row;
                }
            }
            foreach ($targetGroups as $xmlId => $group) {
                if (count($group) <= 1) {
                    continue;
                }
                $signatures = array_values(array_unique(array_map(
                    fn(array $row): string => $this->descriptionContentSignature($row),
                    $group
                )));
                if (count($signatures) > 1) {
                    $conflicts[] = [
                        'type' => 'divergent_target_description_duplicates',
                        'targetCode' => $pair['targetCode'],
                        'xmlId' => $xmlId,
                        'descriptionIds' => array_values(array_map('intval', array_column($group, 'ID'))),
                    ];
                } else {
                    $warnings[] = [
                        'type' => 'exact_target_description_duplicates_will_merge',
                        'targetCode' => $pair['targetCode'],
                        'xmlId' => $xmlId,
                        'descriptionIds' => array_values(array_map('intval', array_column($group, 'ID'))),
                    ];
                }
            }
            foreach ($sourceGroups as $xmlId => $group) {
                if (count($group) <= 1) {
                    continue;
                }
                $canonicalSource = $group[0];
                foreach (array_slice($group, 1) as $duplicateSource) {
                    $fields = $this->descriptionContentConflicts(
                        $canonicalSource,
                        $duplicateSource
                    );
                    if ($fields !== []) {
                        $conflicts[] = [
                            'type' => 'divergent_source_description_duplicates',
                            'sourceCode' => $pair['sourceCode'],
                            'xmlId' => $xmlId,
                            'descriptionIds' => [
                                (int)$canonicalSource['ID'],
                                (int)$duplicateSource['ID'],
                            ],
                            'fields' => $fields,
                        ];
                    }
                }
            }
            foreach ($sourceGroups as $xmlId => $sourceGroup) {
                $targetRow = $targetGroups[$xmlId][0] ?? null;
                if (!is_array($targetRow)) {
                    continue;
                }
                foreach ($sourceGroup as $sourceRow) {
                    $fields = $this->descriptionContentConflicts(
                        $targetRow,
                        $sourceRow
                    );
                    if ($fields !== []) {
                        $conflicts[] = [
                            'type' => 'source_target_description_conflict',
                            'sourceCode' => $pair['sourceCode'],
                            'targetCode' => $pair['targetCode'],
                            'xmlId' => $xmlId,
                            'sourceDescriptionId' => (int)$sourceRow['ID'],
                            'targetDescriptionId' => (int)$targetRow['ID'],
                            'fields' => $fields,
                        ];
                    }
                }
            }
        }
        return [
            'status' => 'ok',
            'sourceBindingCount' => count(array_filter($rows, static fn(array $row): bool => isset($sourceLookup[(int)$row['UF_PROPERTY_ID']]))),
            'targetBindingCount' => count(array_filter($rows, static fn(array $row): bool => isset($targetLookup[(int)$row['UF_PROPERTY_ID']]))),
            'conflicts' => $conflicts,
            'warnings' => $warnings,
        ];
    }

    private function migrateDescriptions(): void
    {
        $dataClass = $this->descriptionDataClass();
        if ($dataClass === null) {
            throw new \RuntimeException('prospektweb.propvalmanager description storage is unavailable');
        }
        $state = $this->catalogState();
        foreach (self::PROPERTY_MAP as $sourceCode => $targetCode) {
            $source = $this->propertyByCode((int)$state['productIblockId'], $sourceCode);
            $target = $this->propertyByCode((int)$state['offerIblockId'], $targetCode);
            if (!$source || !$target) {
                throw new \RuntimeException('Description migration property missing for ' . $sourceCode);
            }
            $targetEnums = $this->enumsByXmlId((int)$target['ID']);
            $rows = $this->descriptionRows([(int)$source['ID'], (int)$target['ID']]);
            $sourceRows = [];
            $targetRows = [];
            foreach ($rows as $row) {
                $xmlId = trim((string)($row['UF_VALUE_XML_ID'] ?? ''));
                if ($xmlId === '') {
                    continue;
                }
                if ((int)$row['UF_PROPERTY_ID'] === (int)$source['ID']) {
                    $canonicalXmlId = self::canonicalTargetXmlId($sourceCode, $xmlId);
                    $sourceRows[$canonicalXmlId][] = $row;
                } elseif ((int)$row['UF_PROPERTY_ID'] === (int)$target['ID']) {
                    $targetRows[$xmlId][] = $row;
                }
            }
            foreach ($targetRows as $xmlId => $rowsForXml) {
                if (!isset($targetEnums[$xmlId])) {
                    throw new \RuntimeException('Description target enum missing for ' . $targetCode . ':' . $xmlId);
                }
                $binding = [
                    'UF_IBLOCK_ID' => (int)$state['offerIblockId'],
                    'UF_PROPERTY_ID' => (int)$target['ID'],
                    'UF_PROPERTY_CODE' => $targetCode,
                    'UF_VALUE_ID' => (int)$targetEnums[$xmlId]['ID'],
                    'UF_VALUE_XML_ID' => $xmlId,
                    'UF_VALUE_NAME' => (string)$targetEnums[$xmlId]['VALUE'],
                ];
                $canonical = array_shift($rowsForXml);
                $result = $dataClass::update((int)$canonical['ID'], $binding);
                if (!$result->isSuccess()) {
                    throw new \RuntimeException('Unable to normalize target property description #' . $canonical['ID']);
                }
                $canonical = array_replace($canonical, $binding);
                foreach ($rowsForXml as $duplicate) {
                    if ($this->descriptionContentSignature($duplicate)
                        !== $this->descriptionContentSignature($canonical)) {
                        throw new \RuntimeException(
                            'Divergent target property description duplicates for ' . $targetCode . ':' . $xmlId
                        );
                    }
                    $this->detachDescriptionBinding($dataClass, $duplicate);
                }
                $targetRows[$xmlId] = [$canonical];
            }
            foreach ($sourceRows as $xmlId => $rowsForXml) {
                if (!isset($targetEnums[$xmlId])) {
                    throw new \RuntimeException('Description target enum missing for ' . $targetCode . ':' . $xmlId);
                }
                $binding = [
                    'UF_IBLOCK_ID' => (int)$state['offerIblockId'],
                    'UF_PROPERTY_ID' => (int)$target['ID'],
                    'UF_PROPERTY_CODE' => $targetCode,
                    'UF_VALUE_ID' => (int)$targetEnums[$xmlId]['ID'],
                    'UF_VALUE_XML_ID' => $xmlId,
                    'UF_VALUE_NAME' => (string)$targetEnums[$xmlId]['VALUE'],
                ];
                $canonical = $targetRows[$xmlId][0] ?? null;
                foreach ($rowsForXml as $sourceRow) {
                    if ($canonical === null) {
                        $result = $dataClass::update((int)$sourceRow['ID'], $binding);
                        if (!$result->isSuccess()) {
                            throw new \RuntimeException('Unable to rebind property description #' . $sourceRow['ID']);
                        }
                        $canonical = array_replace($sourceRow, $binding);
                        continue;
                    }
                    $contentConflicts = $this->descriptionContentConflicts(
                        $canonical,
                        $sourceRow
                    );
                    if ($contentConflicts !== []) {
                        throw new \RuntimeException(
                            'Conflicting property description content for ' . $targetCode . ':' . $xmlId
                        );
                    }
                    $merged = $this->mergeDescriptionContent($canonical, $sourceRow);
                    $result = $dataClass::update((int)$canonical['ID'], array_merge($binding, $merged));
                    if (!$result->isSuccess()) {
                        throw new \RuntimeException('Unable to merge property description #' . $canonical['ID']);
                    }
                    $canonical = array_replace($canonical, $binding, $merged);
                    $this->detachDescriptionBinding($dataClass, $sourceRow);
                }
            }
        }
    }

    private function clearDescriptionCache(): void
    {
        if (class_exists('\Prospektweb\PropValManager\Service\PropertyDescriptionService')) {
            \Prospektweb\PropValManager\Service\PropertyDescriptionService::clearCache();
        }
    }

    /** @return array<string,mixed> */
    private function publishDescriptionArtifacts(): array
    {
        if (!class_exists('\Prospektweb\PropValManager\Service\PropertyDescriptionJsonExporter')) {
            throw new \RuntimeException('PropValManager JSON exporter is unavailable');
        }
        $this->clearDescriptionCache();
        $artifact = (new \Prospektweb\PropValManager\Service\PropertyDescriptionJsonExporter())->export();
        return ['status' => 'ok', 'exported' => true, 'artifact' => $artifact];
    }

    /** @return array<string,mixed> */
    private function mergeDescriptionContent(array $target, array $source): array
    {
        $merged = [];
        foreach (['UF_TITLE', 'UF_IMAGE', 'UF_LINK', 'UF_LINK_TEXT', 'UF_LINK_TARGET'] as $field) {
            if ($this->isEmptyDescriptionValue($target[$field] ?? null)
                && !$this->isEmptyDescriptionValue($source[$field] ?? null)) {
                $merged[$field] = $source[$field];
            }
        }
        $targetDescription = trim((string)($target['UF_DESCRIPTION'] ?? ''));
        $sourceDescription = trim((string)($source['UF_DESCRIPTION'] ?? ''));
        if ($targetDescription === '' && $sourceDescription !== '') {
            $merged['UF_DESCRIPTION'] = $sourceDescription;
        }
        $targetSort = (int)($target['UF_SORT'] ?? 0);
        $sourceSort = (int)($source['UF_SORT'] ?? 0);
        if ($targetSort <= 0 && $sourceSort > 0) {
            $merged['UF_SORT'] = $sourceSort;
        }
        if ((int)($source['UF_ACTIVE'] ?? 0) > (int)($target['UF_ACTIVE'] ?? 0)) {
            $merged['UF_ACTIVE'] = (int)$source['UF_ACTIVE'];
        }
        return $merged;
    }

    private function descriptionContentSignature(array $row): string
    {
        $content = [];
        foreach ([
            'UF_TITLE', 'UF_DESCRIPTION', 'UF_IMAGE', 'UF_LINK', 'UF_LINK_TEXT', 'UF_LINK_TARGET',
            'UF_SORT', 'UF_ACTIVE',
        ] as $field) {
            $content[$field] = $row[$field] ?? null;
        }
        return self::canonicalJson($content);
    }

    /** @return string[] */
    private function descriptionContentConflicts(array $target, array $source): array
    {
        $conflicts = [];
        foreach ([
            'UF_TITLE', 'UF_DESCRIPTION', 'UF_IMAGE', 'UF_LINK', 'UF_LINK_TEXT', 'UF_LINK_TARGET',
            'UF_SORT', 'UF_ACTIVE',
        ] as $field) {
            $targetValue = $target[$field] ?? null;
            $sourceValue = $source[$field] ?? null;
            if ($this->isEmptyDescriptionValue($targetValue)
                || $this->isEmptyDescriptionValue($sourceValue)) {
                continue;
            }
            if (self::canonicalJson($targetValue) !== self::canonicalJson($sourceValue)) {
                $conflicts[] = $field;
            }
        }
        return $conflicts;
    }

    /** @param class-string $dataClass */
    private function detachDescriptionBinding(string $dataClass, array $row): void
    {
        $result = $dataClass::update((int)$row['ID'], [
            'UF_IBLOCK_ID' => 0,
            'UF_PROPERTY_ID' => 0,
            'UF_PROPERTY_CODE' => '',
            'UF_VALUE_ID' => 0,
            'UF_VALUE_XML_ID' => '',
            'UF_VALUE_NAME' => '',
        ]);
        if (!$result->isSuccess()) {
            throw new \RuntimeException('Unable to detach merged description #' . (int)$row['ID']);
        }
    }

    private function isEmptyDescriptionValue($value): bool
    {
        if (is_array($value)) {
            return array_values(array_filter($value, static fn($item): bool => $item !== null && $item !== '' && $item !== 0)) === [];
        }
        return $value === null || $value === '' || $value === 0 || $value === '0';
    }

    /** @return array<int,array<string,mixed>> */
    private function verifyDescriptions(): array
    {
        $errors = [];
        if ($this->descriptionDataClass() === null) {
            return [['type' => 'description_storage_unavailable']];
        }
        $state = $this->catalogState();
        foreach (self::PROPERTY_MAP as $sourceCode => $targetCode) {
            $source = $this->propertyByCode((int)$state['productIblockId'], $sourceCode);
            $target = $this->propertyByCode((int)$state['offerIblockId'], $targetCode);
            if (!$source || !$target) {
                continue;
            }
            $targetEnums = $this->enumsByXmlId((int)$target['ID']);
            $seenTargetBindings = [];
            $rows = $this->descriptionRows([(int)$source['ID'], (int)$target['ID']]);
            foreach ($rows as $row) {
                if ((int)$row['UF_PROPERTY_ID'] === (int)$source['ID']
                    && (int)($row['UF_IBLOCK_ID'] ?? 0) === (int)$state['productIblockId']) {
                    $errors[] = [
                        'type' => 'description_still_bound_to_source',
                        'descriptionId' => (int)$row['ID'],
                        'sourceCode' => $sourceCode,
                    ];
                    continue;
                }
                if ((int)$row['UF_PROPERTY_ID'] !== (int)$target['ID']) {
                    continue;
                }
                $xmlId = trim((string)($row['UF_VALUE_XML_ID'] ?? ''));
                $enum = $targetEnums[$xmlId] ?? null;
                if (!is_array($enum)) {
                    $errors[] = [
                        'type' => 'description_target_enum_missing',
                        'descriptionId' => (int)$row['ID'],
                        'targetCode' => $targetCode,
                        'xmlId' => $xmlId,
                    ];
                    continue;
                }
                if (isset($seenTargetBindings[$xmlId])) {
                    $errors[] = [
                        'type' => 'duplicate_target_description_binding',
                        'targetCode' => $targetCode,
                        'xmlId' => $xmlId,
                        'descriptionIds' => [$seenTargetBindings[$xmlId], (int)$row['ID']],
                    ];
                } else {
                    $seenTargetBindings[$xmlId] = (int)$row['ID'];
                }
                $bindingValid = (int)($row['UF_IBLOCK_ID'] ?? 0) === (int)$state['offerIblockId']
                    && (string)($row['UF_PROPERTY_CODE'] ?? '') === $targetCode
                    && (int)($row['UF_VALUE_ID'] ?? 0) === (int)$enum['ID']
                    && $xmlId === (string)$enum['XML_ID']
                    && (string)($row['UF_VALUE_NAME'] ?? '') === (string)$enum['VALUE'];
                if (!$bindingValid) {
                    $errors[] = [
                        'type' => 'description_target_binding_invalid',
                        'descriptionId' => (int)$row['ID'],
                        'targetCode' => $targetCode,
                        'xmlId' => $xmlId,
                    ];
                }
            }
        }
        return $errors;
    }

    /** @return array<string,mixed> */
    private function auditProductFrontcalcConfigs(): array
    {
        $productIblockId = (new ConfigManager())->getProductIblockId();
        $productIds = [];
        $cursor = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $productIblockId, '!PROPERTY_FRONTCALC_CONFIG' => false],
            false,
            false,
            ['ID']
        );
        while ($row = $cursor->Fetch()) {
            $productId = (int)($row['ID'] ?? 0);
            if ($productId > 0) {
                $productIds[$productId] = $productId;
            }
        }
        $plans = [];
        $conflicts = [];
        foreach ($productIds as $productId) {
            $rows = $this->elementPropertyRows($productIblockId, $productId)['FRONTCALC_CONFIG'] ?? [];
            $legacyRows = [];
            foreach ($rows as $index => $row) {
                $raw = $this->propertyRowText($row);
                if (self::rawHasLegacyReference($raw)) {
                    $legacyRows[(int)$index] = $raw;
                }
            }
            if ($legacyRows === []) {
                continue;
            }
            if (count($rows) !== 1 || count($legacyRows) !== 1) {
                $conflicts[] = [
                    'type' => 'frontcalc_config_not_single_valued',
                    'productId' => $productId,
                    'rowCount' => count($rows),
                ];
                continue;
            }
            try {
                $rewritten = self::rewriteJsonReferences((string)reset($legacyRows));
            } catch (\Throwable $error) {
                $conflicts[] = [
                    'type' => 'frontcalc_config_rewrite_conflict',
                    'productId' => $productId,
                    'message' => $error->getMessage(),
                ];
                continue;
            }
            $decoded = is_string($rewritten) ? json_decode($rewritten, true) : null;
            if (!is_string($rewritten) || !is_array($decoded) || self::hasLegacyReference($decoded)) {
                $conflicts[] = [
                    'type' => 'frontcalc_config_not_safely_rewritable',
                    'productId' => $productId,
                ];
                continue;
            }
            $plans[] = [
                'productId' => $productId,
                'sourceSha256' => hash('sha256', (string)reset($legacyRows)),
                'rewrittenSha256' => hash('sha256', $rewritten),
            ];
        }
        return ['plans' => $plans, 'conflicts' => $conflicts];
    }

    private function migrateProductFrontcalcConfigs(array $plans): void
    {
        $productIblockId = (new ConfigManager())->getProductIblockId();
        foreach ($plans as $plan) {
            $productId = (int)$plan['productId'];
            $rows = $this->elementPropertyRows($productIblockId, $productId)['FRONTCALC_CONFIG'] ?? [];
            if (count($rows) !== 1) {
                throw new \RuntimeException('FRONTCALC_CONFIG changed before write on product #' . $productId);
            }
            $raw = $this->propertyRowText($rows[0]);
            if (!hash_equals((string)$plan['sourceSha256'], hash('sha256', $raw))) {
                throw new \RuntimeException('FRONTCALC_CONFIG CAS mismatch on product #' . $productId);
            }
            $rewritten = self::rewriteJsonReferences($raw);
            if (!is_string($rewritten)
                || !hash_equals((string)$plan['rewrittenSha256'], hash('sha256', $rewritten))) {
                throw new \RuntimeException('FRONTCALC_CONFIG rewrite drift on product #' . $productId);
            }
            $this->writeSinglePropertyText($productIblockId, $productId, 'FRONTCALC_CONFIG', $rewritten);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function verifyProductFrontcalcConfigs(): array
    {
        $audit = $this->auditProductFrontcalcConfigs();
        $errors = $audit['conflicts'];
        foreach ($audit['plans'] as $plan) {
            $errors[] = [
                'type' => 'frontcalc_config_legacy_reference',
                'productId' => (int)$plan['productId'],
            ];
        }
        return $errors;
    }

    /** @return array<int,array<string,mixed>> */
    private function snapshotProductFrontcalcConfigs(): array
    {
        $productIblockId = (new ConfigManager())->getProductIblockId();
        $snapshots = [];
        $cursor = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $productIblockId, '!PROPERTY_FRONTCALC_CONFIG' => false],
            false,
            false,
            ['ID']
        );
        $seen = [];
        while ($row = $cursor->Fetch()) {
            $productId = (int)($row['ID'] ?? 0);
            if ($productId <= 0 || isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;
            $rows = $this->elementPropertyRows($productIblockId, $productId)['FRONTCALC_CONFIG'] ?? [];
            $snapshots[] = [
                'productId' => $productId,
                'rows' => array_map(function (array $propertyRow): array {
                    return [
                        'valueId' => (int)($propertyRow['PROPERTY_VALUE_ID'] ?? 0),
                        'value' => $this->snapshotValue($propertyRow['~VALUE'] ?? $propertyRow['VALUE'] ?? null),
                        'description' => $this->snapshotValue(
                            $propertyRow['~DESCRIPTION'] ?? $propertyRow['DESCRIPTION'] ?? null
                        ),
                    ];
                }, $rows),
            ];
        }
        return $snapshots;
    }

    /** @return array{legacy:int,migrated:int,invalidTarget:int} */
    private function frontcalcTemplateSeedState(): array
    {
        if (!Loader::includeModule('prospektweb.frontcalc')
            || !class_exists('\Prospektweb\Frontcalc\Service\CalculatorTemplateManager')) {
            throw new \RuntimeException('FrontCalc CalculatorTemplateManager is unavailable');
        }
        $manager = new \Prospektweb\Frontcalc\Service\CalculatorTemplateManager();
        $productIblockId = (new ConfigManager())->getProductIblockId();
        $state = ['legacy' => 0, 'migrated' => 0, 'invalidTarget' => 0];
        foreach ($manager->listTemplates($productIblockId) as $metadata) {
            $template = $manager->getTemplate((string)($metadata['id'] ?? ''), $productIblockId);
            if (!is_array($template) || !is_array($template['schema'] ?? null)) {
                continue;
            }
            foreach ((array)($template['schema']['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $seed = (string)($field['seed_property_code'] ?? '');
                $source = trim((string)($field['seed_property_source'] ?? 'product'));
                if (isset(self::PROPERTY_MAP[$seed])) {
                    $state['legacy']++;
                } elseif (in_array($seed, array_values(self::PROPERTY_MAP), true)) {
                    if ($source === 'offer') {
                        $state['migrated']++;
                    } else {
                        $state['invalidTarget']++;
                    }
                }
            }
        }
        return $state;
    }

    /** @return array<string,mixed> */
    private function auditFrontcalcFamilies(): array
    {
        $conflicts = [];
        $summary = [
            'status' => 'unavailable',
            'templateCount' => 0,
            'activeFamilyCount' => 0,
            'templatesToUpdate' => [],
        ];
        if (!Loader::includeModule('prospektweb.frontcalc')
            || !class_exists('\Prospektweb\Frontcalc\Service\CalculatorTemplateManager')) {
            $conflicts[] = ['type' => 'frontcalc_template_manager_unavailable'];
            return ['summary' => $summary, 'conflicts' => $conflicts];
        }
        $productIblockId = (new ConfigManager())->getProductIblockId();
        $manager = new \Prospektweb\Frontcalc\Service\CalculatorTemplateManager();
        $active = 0;
        $templateCount = 0;
        $updates = [];
        foreach ($manager->listTemplates($productIblockId) as $metadata) {
            $template = $manager->getTemplate((string)($metadata['id'] ?? ''), $productIblockId);
            if (!is_array($template) || !is_array($template['schema'] ?? null)) {
                continue;
            }
            $templateCount++;
            $family = is_array($template['schema']['family'] ?? null) ? $template['schema']['family'] : [];
            if (($family['active'] ?? false) === true || (string)($family['active'] ?? '') === 'Y') {
                $active++;
            }
            $stale = [];
            foreach ((array)($template['schema']['fields'] ?? []) as $field) {
                $seed = is_array($field) ? (string)($field['seed_property_code'] ?? '') : '';
                $seedSource = is_array($field) ? trim((string)($field['seed_property_source'] ?? 'product')) : '';
                if (isset(self::PROPERTY_MAP[$seed])) {
                    $stale[] = [
                        'sourceCode' => $seed,
                        'targetCode' => self::PROPERTY_MAP[$seed],
                        'source' => $seedSource,
                    ];
                } elseif (in_array($seed, array_values(self::PROPERTY_MAP), true) && $seedSource !== 'offer') {
                    $stale[] = [
                        'sourceCode' => $seed,
                        'targetCode' => $seed,
                        'source' => $seedSource,
                    ];
                }
            }
            $changed = false;
            try {
                $rewrittenSchema = self::rewriteStructuredReferences($template['schema'], $changed);
            } catch (\Throwable $error) {
                $conflicts[] = [
                    'type' => 'frontcalc_template_rewrite_conflict',
                    'templateId' => (string)$template['id'],
                    'message' => $error->getMessage(),
                ];
                continue;
            }
            if (self::hasLegacyReference($template['schema']) && !$changed) {
                $conflicts[] = [
                    'type' => 'frontcalc_template_not_safely_rewritable',
                    'templateId' => (string)$template['id'],
                ];
                continue;
            }
            if ($changed) {
                $updates[] = [
                    'templateId' => (string)$template['id'],
                    'revision' => (int)$template['revision'],
                    'seedMap' => $stale,
                    'sourceSchemaSha256' => hash('sha256', self::canonicalJson($template['schema'])),
                    'rewrittenSchemaSha256' => hash('sha256', self::canonicalJson($rewrittenSchema)),
                ];
            }
        }
        return [
            'summary' => [
                'status' => 'ok',
                'templateCount' => $templateCount,
                'activeFamilyCount' => $active,
                'templatesToUpdate' => $updates,
            ],
            'conflicts' => $conflicts,
        ];
    }

    private function updateFrontcalcFamilies(array $plans): void
    {
        if (!Loader::includeModule('prospektweb.frontcalc')
            || !class_exists('\Prospektweb\Frontcalc\Service\CalculatorTemplateManager')) {
            throw new \RuntimeException('FrontCalc CalculatorTemplateManager is unavailable');
        }
        $productIblockId = (new ConfigManager())->getProductIblockId();
        $manager = new \Prospektweb\Frontcalc\Service\CalculatorTemplateManager();
        foreach ($plans as $plan) {
            $templateId = (string)($plan['templateId'] ?? '');
            $template = $manager->getTemplate($templateId, $productIblockId);
            if (!is_array($template) || !is_array($template['schema'] ?? null)) {
                throw new \RuntimeException('FrontCalc template CAS failed: ' . $templateId . ' disappeared');
            }
            if ((int)$template['revision'] !== (int)($plan['revision'] ?? -1)
                || !hash_equals(
                    (string)($plan['sourceSchemaSha256'] ?? ''),
                    hash('sha256', self::canonicalJson($template['schema']))
                )) {
                throw new \RuntimeException('FrontCalc template CAS mismatch: ' . $templateId);
            }
            $changed = false;
            $schema = self::rewriteStructuredReferences($template['schema'], $changed);
            if (!$changed
                || !hash_equals(
                    (string)($plan['rewrittenSchemaSha256'] ?? ''),
                    hash('sha256', self::canonicalJson($schema))
                )) {
                throw new \RuntimeException('FrontCalc template rewrite drift: ' . $templateId);
            }
            $manager->saveTemplate(
                $templateId,
                (string)$template['name'],
                (int)$template['section_id'],
                $productIblockId,
                $schema,
                (int)$plan['revision']
            );
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function verifyFrontcalcFamilies(): array
    {
        $audit = $this->auditFrontcalcFamilies();
        $errors = $audit['conflicts'];
        foreach ((array)($audit['summary']['templatesToUpdate'] ?? []) as $template) {
            $errors[] = [
                'type' => 'frontcalc_template_legacy_reference',
                'templateId' => (string)$template['templateId'],
                'seedMap' => $template['seedMap'],
            ];
        }
        return $errors;
    }

    /** @return array<string,mixed> */
    private function buildSnapshotPayload(
        int $presetId,
        ?array $displayAudit = null,
        bool $allowLegacyPresetBreakage = false
    ): array {
        $state = $this->catalogState();
        $propertyDefinitions = [];
        foreach (self::PROPERTY_MAP as $sourceCode => $targetCode) {
            $source = $this->propertyByCode((int)$state['productIblockId'], $sourceCode);
            $target = $this->propertyByCode((int)$state['offerIblockId'], $targetCode);
            $propertyDefinitions[$sourceCode] = $this->snapshotProperty($source);
            $propertyDefinitions[$targetCode] = $this->snapshotProperty($target);
        }
        foreach (self::PROPERTY_SORTS as $code => $sort) {
            if (!array_key_exists($code, $propertyDefinitions)) {
                $propertyDefinitions[$code] = $this->snapshotProperty(
                    $this->propertyByCode((int)$state['offerIblockId'], $code)
                );
            }
        }
        ksort($propertyDefinitions);

        $descriptionPropertyIds = [];
        foreach ($propertyDefinitions as $definition) {
            $id = (int)($definition['fields']['ID'] ?? 0);
            if ($id > 0) {
                $descriptionPropertyIds[] = $id;
            }
        }
        $graph = $this->collectPresetGraph($presetId);
        $graphSnapshot = [];
        foreach ($graph['elements'] as $element) {
            $properties = [];
            foreach ($this->elementPropertyRows((int)$element['iblockId'], (int)$element['elementId']) as $code => $rows) {
                $properties[$code] = array_map(function (array $row): array {
                    return [
                        'propertyId' => (int)($row['ID'] ?? 0),
                        'value' => $this->snapshotValue($row['~VALUE'] ?? $row['VALUE'] ?? null),
                        'description' => $this->snapshotValue($row['~DESCRIPTION'] ?? $row['DESCRIPTION'] ?? null),
                        'valueId' => (int)($row['PROPERTY_VALUE_ID'] ?? 0),
                    ];
                }, $rows);
            }
            $graphSnapshot[] = $element + ['properties' => $properties];
        }
        $sourcePresetConsumers = $this->auditSourcePresetConsumers(
            $state,
            $presetId,
            $allowLegacyPresetBreakage
        );
        $otherPresetConsumers = $this->auditOtherPresetConsumers(
            $presetId,
            $allowLegacyPresetBreakage
        );
        $acceptedDeprecatedPresetConsumers = array_merge(
            (array)($sourcePresetConsumers['acceptedDeprecatedPresetConsumers'] ?? []),
            (array)($otherPresetConsumers['acceptedDeprecatedPresetConsumers'] ?? [])
        );

        return [
            'schema' => self::SNAPSHOT_SCHEMA,
            'marker' => 'RECOVERABLE PRE-MIGRATION SNAPSHOT - DO NOT SERVE PUBLICLY',
            'moduleId' => self::MODULE_ID,
            'migrationPhase' => $this->migrationPhase(),
            'presetId' => $presetId,
            'allowLegacyPresetBreakage' => $allowLegacyPresetBreakage,
            'acceptedDeprecatedPresetConsumers' => $acceptedDeprecatedPresetConsumers,
            'productIblockId' => (int)$state['productIblockId'],
            'offerIblockId' => (int)$state['offerIblockId'],
            'propertyMap' => self::PROPERTY_MAP,
            'propertySorts' => self::PROPERTY_SORTS,
            'propertyDefinitions' => $propertyDefinitions,
            'unexpectedProductCalcProperties' => $this->unexpectedProductCalcProperties(
                (int)$state['productIblockId']
            ),
            'productValuesByXmlId' => $state['productValues'],
            'offerValuesByXmlId' => $state['offerValues'],
            'offerLinks' => $state['offerLinks'],
            'activeOffersByProduct' => $state['activeOffersByProduct'],
            'activeLinkedOfferCount' => (int)$state['activeLinkedOfferCount'],
            'productsWithoutOffers' => array_values($state['productsWithoutOffers']),
            'baseOfferPlan' => $this->baseOfferPlan($state),
            'baseOfferVerification' => $this->verifyMaterializedBaseOffers(),
            'baseOfferMarkerOption' => (string)\Bitrix\Main\Config\Option::get(
                self::MODULE_ID,
                self::BASE_OFFER_OPTION,
                ''
            ),
            'sourcePresetConsumers' => $sourcePresetConsumers,
            'otherPresetConsumers' => $otherPresetConsumers,
            'presetGraph' => $graphSnapshot,
            'globalSymbols' => $this->snapshotGlobalSymbols($presetId),
            'aiContextSelectionOption' => (string)\Bitrix\Main\Config\Option::get(
                self::MODULE_ID,
                self::AI_CONTEXT_SELECTION_OPTION,
                ''
            ),
            'propertyValueDescriptions' => $this->descriptionRows($descriptionPropertyIds),
            'productFrontcalcConfigs' => $this->snapshotProductFrontcalcConfigs(),
            'frontcalcTemplates' => $this->snapshotFrontcalcTemplates(),
            'catalogDisplayConfig' => $displayAudit ?? $this->auditCatalogDisplayConfig(),
        ];
    }

    /** @return array<string,mixed>|null */
    private function snapshotProperty(?array $property): ?array
    {
        if (!$property) {
            return null;
        }
        $fields = [];
        foreach ([
            'ID', 'IBLOCK_ID', 'NAME', 'ACTIVE', 'SORT', 'CODE', 'DEFAULT_VALUE', 'PROPERTY_TYPE',
            'ROW_COUNT', 'COL_COUNT', 'LIST_TYPE', 'MULTIPLE', 'XML_ID', 'FILE_TYPE', 'MULTIPLE_CNT',
            'LINK_IBLOCK_ID', 'WITH_DESCRIPTION', 'SEARCHABLE', 'FILTRABLE', 'IS_REQUIRED', 'USER_TYPE',
            'USER_TYPE_SETTINGS', 'HINT', 'SECTION_PROPERTY', 'SMART_FILTER', 'DISPLAY_TYPE',
            'DISPLAY_EXPANDED', 'FILTER_HINT',
        ] as $field) {
            if (array_key_exists($field, $property)) {
                $fields[$field] = $this->snapshotValue($property[$field]);
            }
        }
        return [
            'fields' => $fields,
            'enums' => array_values($this->enumsByXmlId((int)$property['ID'])),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function snapshotGlobalSymbols(int $presetId): array
    {
        $iblockId = (new ConfigManager())->getIblockId('CALC_GLOBAL_VALUES');
        $result = [];
        if ($iblockId <= 0) {
            return $result;
        }
        $cursor = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, '=PROPERTY_PRESET_ID' => $presetId],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'SORT', 'ACTIVE', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE']
        );
        while ($element = $cursor->GetNextElement()) {
            $fields = $element->GetFields();
            $id = (int)($fields['ID'] ?? 0);
            $properties = [];
            foreach ($this->elementPropertyRows($iblockId, $id) as $code => $rows) {
                $properties[$code] = array_map(function (array $row): array {
                    return [
                        'value' => $this->snapshotValue($row['~VALUE'] ?? $row['VALUE'] ?? null),
                        'description' => $this->snapshotValue($row['~DESCRIPTION'] ?? $row['DESCRIPTION'] ?? null),
                    ];
                }, $rows);
            }
            $result[] = [
                'fields' => [
                    'ID' => $id,
                    'IBLOCK_ID' => $iblockId,
                    'NAME' => (string)($fields['~NAME'] ?? $fields['NAME'] ?? ''),
                    'CODE' => (string)($fields['CODE'] ?? ''),
                    'SORT' => (int)($fields['SORT'] ?? 500),
                    'ACTIVE' => (string)($fields['ACTIVE'] ?? ''),
                    'PREVIEW_TEXT' => (string)($fields['~PREVIEW_TEXT'] ?? $fields['PREVIEW_TEXT'] ?? ''),
                    'PREVIEW_TEXT_TYPE' => (string)($fields['PREVIEW_TEXT_TYPE'] ?? 'text'),
                ],
                'properties' => $properties,
            ];
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function snapshotFrontcalcTemplates(): array
    {
        if (!Loader::includeModule('prospektweb.frontcalc')
            || !class_exists('\Prospektweb\Frontcalc\Service\CalculatorTemplateManager')) {
            return [];
        }
        $productIblockId = (new ConfigManager())->getProductIblockId();
        $manager = new \Prospektweb\Frontcalc\Service\CalculatorTemplateManager();
        $result = [];
        foreach ($manager->listTemplates($productIblockId) as $metadata) {
            $template = $manager->getTemplate((string)($metadata['id'] ?? ''), $productIblockId);
            if (is_array($template)) {
                $result[] = $template;
            }
        }
        return $result;
    }

    /** @return mixed */
    private function snapshotValue($value)
    {
        if (is_resource($value)) {
            return null;
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $nested) {
                $result[$key] = $this->snapshotValue($nested);
            }
            return $result;
        }
        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string)$value : null;
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function writeSnapshot(array $payload): array
    {
        $payload['createdAt'] = gmdate('c');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to serialize migration snapshot');
        }
        $documentRoot = rtrim((string)Application::getDocumentRoot(), '/\\');
        if ($documentRoot === '') {
            throw new \RuntimeException('Bitrix document root is unavailable');
        }
        $directory = $documentRoot . '/bitrix/backup/prospektweb.calc';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create protected migration backup directory');
        }
        @chmod($directory, 0700);
        $denyFile = $directory . '/.htaccess';
        if (!is_file($denyFile)) {
            file_put_contents($denyFile, "Deny from all\n", LOCK_EX);
            @chmod($denyFile, 0600);
        }
        $fingerprint = (string)($payload['fingerprint'] ?? ('sha256:' . hash('sha256', self::canonicalJson($payload))));
        $shortHash = substr(str_replace('sha256:', '', $fingerprint), 0, 12);
        $filename = 'catalog-calc-properties-' . gmdate('Ymd-His') . '-' . $shortHash . '.json';
        $path = $directory . '/' . $filename;
        $bytes = file_put_contents($path, $json . "\n", LOCK_EX);
        if ($bytes === false || $bytes < strlen($json)) {
            throw new \RuntimeException('Unable to write complete migration snapshot');
        }
        @chmod($path, 0600);
        $storedHash = hash_file('sha256', $path);
        if (!is_string($storedHash) || $storedHash === '') {
            throw new \RuntimeException('Unable to hash migration snapshot');
        }
        return [
            'status' => 'ok',
            'path' => '/bitrix/backup/prospektweb.calc/' . $filename,
            'sha256' => $storedHash,
            'bytes' => (int)$bytes,
            'fingerprint' => $fingerprint,
            'allowLegacyPresetBreakage' => !empty($payload['allowLegacyPresetBreakage']),
            'acceptedDeprecatedPresetConsumers' => array_values((array)(
                $payload['acceptedDeprecatedPresetConsumers'] ?? []
            )),
        ];
    }

    /** @param mixed $value */
    private static function canonicalJson($value): string
    {
        $normalize = static function ($item) use (&$normalize) {
            if (!is_array($item)) {
                return $item;
            }
            $keys = array_keys($item);
            $isList = $keys === range(0, count($item) - 1);
            if (!$isList) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }
            return $item;
        };
        $json = json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to canonicalize migration state');
        }
        return $json;
    }
}
