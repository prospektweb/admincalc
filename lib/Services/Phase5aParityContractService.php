<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

/**
 * Read-only, deterministic Phase 5A dependency and golden-capture contract.
 *
 * Runtime discovery is intentionally adapter based. Production uses Bitrix
 * reads only; unit tests can supply an equivalent graph without a Bitrix boot.
 * No snapshot file, catalog row or module option is written by this service.
 */
final class Phase5aParityContractService
{
    public const CONTRACT = 'prospektweb.calc.form-first-parity/v1';
    public const OBSERVATION_CONTRACT = 'prospektweb.calc.form-first-golden-observation/v1';
    public const COMPARISON_CONTRACT = 'prospektweb.calc.form-first-golden-comparison/v1';
    public const FOCUS_PRESET_ID = 12740;
    public const GOLDEN_PRODUCT_IDS = [4267, 4403, 5058, 12727, 12764];

    private const UNAVAILABLE_RUNTIME_PRODUCT_ID = 5058;
    private const AVAILABLE_RUNTIME_SOURCES = ['family', 'template', 'product', 'product_override', 'form_first'];

    private const REQUIRED_CATEGORIES = [
        'ui',
        'passive_context',
        'stage_inputs',
        'globals',
        'options_mappings',
        'routes',
        'basket',
        'seo_display',
    ];

    private const GOLDEN_BASELINE = [
        4267 => [
            'reopenSelection' => [
                'CALC_PROP_COLOR_SCHEME' => '4+0',
                'CALC_PROP_DENSITY_PAPER' => 'MAX',
                'CALC_PROP_FORMAT' => '90x50',
                'CALC_PROP_METHOD' => 'OFSET',
                'CALC_PROP_TYPE_PAPER' => 'mel-paper',
            ],
            'routeProductId' => 4267,
        ],
        4403 => [
            'reopenSelection' => [
                'CALC_PROP_COLOR_SCHEME' => '4+0',
                'CALC_PROP_DENSITY_PAPER' => 'MAX',
                'CALC_PROP_FILLING' => 'standart',
                'CALC_PROP_FORMAT' => '90x50',
                'CALC_PROP_LAMINATION' => 'gloss-low',
                'CALC_PROP_LAMINATION_SIDES' => '2',
                'CALC_PROP_METHOD' => 'DIGITAL',
                'CALC_PROP_PROTECTION' => 'lamination-rulon',
                'CALC_PROP_TYPE_PAPER' => 'mel-paper',
            ],
            'routeProductId' => 4403,
        ],
        5058 => [
            'runtimeState' => 'unavailable',
            'runtimeSource' => 'none',
            'prices' => [],
            'selectedStageIds' => [],
            'reopenSelection' => null,
            'routeProductId' => null,
            'basketReprice' => null,
            'basketFingerprint' => null,
            'schemaFingerprint' => null,
            'compileHash' => null,
            'formRevision' => null,
            'bindingRevision' => null,
            'publishedRevision' => null,
        ],
        12727 => [
            'reopenSelection' => [
                'CALC_PROP_COLOR_SCHEME' => '4+0',
                'CALC_PROP_DENSITY_PAPER' => 'MAX',
                'CALC_PROP_FILLING' => 'standart',
                'CALC_PROP_FORMAT' => '90x50',
                'CALC_PROP_METHOD' => 'DIGITAL',
                'CALC_PROP_TYPE_PAPER' => 'mel-paper',
            ],
            'routeProductId' => 12727,
            'prices' => [
                ['quantity' => 50, 'price' => 410.0, 'currency' => 'RUB'],
                ['quantity' => 100, 'price' => 530.0, 'currency' => 'RUB'],
                ['quantity' => 150, 'price' => 650.0, 'currency' => 'RUB'],
                ['quantity' => 200, 'price' => 770.0, 'currency' => 'RUB'],
                ['quantity' => 300, 'price' => 1010.0, 'currency' => 'RUB'],
            ],
        ],
        12764 => [
            'reopenSelection' => [
                'CALC_PROP_COLOR_SCHEME' => '4+0',
                'CALC_PROP_DENSITY_PAPER' => 'MAX',
                'CALC_PROP_FILLING' => 'standart',
                'CALC_PROP_FORMAT' => '90x50',
                'CALC_PROP_METHOD' => 'DIGITAL',
                'CALC_PROP_OPTIONS' => 'round-corners',
                'CALC_PROP_TYPE_PAPER' => 'mel-paper',
            ],
            'routeProductId' => 12764,
        ],
    ];

    /** @var callable */
    private $presetLoader;

    /** @var callable */
    private $dependencyLoader;

    /** @var callable */
    private $goldenLoader;

    public function __construct(
        ?callable $presetLoader = null,
        ?callable $dependencyLoader = null,
        ?callable $goldenLoader = null
    ) {
        $this->presetLoader = $presetLoader ?? static function (int $presetId): array {
            if (!\Bitrix\Main\Loader::includeModule('iblock')) {
                throw new \RuntimeException('The iblock module is not available');
            }

            return (new CatalogTreeService())->presetLoadOptions(['presetId' => $presetId]);
        };
        $this->dependencyLoader = $dependencyLoader ?? static function (int $presetId, array $allowedProductIds): array {
            return self::loadBitrixDependencyGraph($presetId, $allowedProductIds);
        };
        $this->goldenLoader = $goldenLoader ?? static function (int $presetId, array $productIds): array {
            if (\Bitrix\Main\Loader::includeModule('prospektweb.frontcalc')) {
                $providerClass = '\\Prospektweb\\Frontcalc\\Service\\ControlCenterStorefrontEditorService';
                if (class_exists($providerClass)) {
                    $provider = new $providerClass();
                    if (is_callable([$provider, 'capturePhase5aGoldenParity'])) {
                        try {
                            $result = $provider->capturePhase5aGoldenParity(
                                $presetId,
                                $productIds,
                                $productIds
                            );
                        } catch (\Throwable $exception) {
                            // Live capture is an optional read-only accelerator.
                            // A product-specific capture failure must fall back
                            // to the versioned partial fixture, whose invalid
                            // golden gate remains explicit and fail-closed.
                            $result = null;
                        }
                        if (is_array($result)
                            && (string)($result['contract'] ?? '')
                                === 'prospektweb.frontcalc.phase5a-golden-parity/v1'
                            && ($result['valid'] ?? false) === true) {
                            $captures = [];
                            foreach ((array)($result['products'] ?? []) as $product) {
                                if (!is_array($product) || (int)($product['productId'] ?? 0) <= 0) {
                                    continue;
                                }
                                $capture = is_array($product['capture'] ?? null)
                                    ? $product['capture']
                                    : $product;
                                $runtimeSource = trim((string)($product['source'] ?? ''));
                                if ($runtimeSource !== '') {
                                    $capture['runtimeState'] = $runtimeSource === 'none'
                                        ? 'unavailable'
                                        : 'available';
                                    $capture['runtimeSource'] = $runtimeSource;
                                }
                                if ($runtimeSource === 'none') {
                                    // The resolver proves an absent FrontCalc runtime. Hashing
                                    // an empty schema would incorrectly present it as a schema.
                                    $capture['schemaFingerprint'] = null;
                                } elseif (is_string($product['schemaFingerprint'] ?? null)) {
                                    $capture['schemaFingerprint'] = $product['schemaFingerprint'];
                                }
                                $captures[(int)$product['productId']] = $capture;
                            }
                            if (count($captures) === count(self::GOLDEN_PRODUCT_IDS)) {
                                return [
                                    'mode' => 'production_capture',
                                    'baselineKind' => 'production',
                                    'captures' => $captures,
                                ];
                            }
                        }
                    }
                }
            }

            $fixture = self::loadVersionedGoldenFixture();
            return [
                'mode' => 'versioned_fixture',
                'baselineKind' => (string)($fixture['baselineKind'] ?? 'unknown'),
                'captures' => $fixture['captures'],
            ];
        };
    }

    public function build(): array
    {
        $catalog = call_user_func($this->presetLoader, self::FOCUS_PRESET_ID);
        if (!is_array($catalog)
            || (string)($catalog['status'] ?? '') !== 'ok'
            || (int)($catalog['preset']['id'] ?? 0) !== self::FOCUS_PRESET_ID) {
            throw new \RuntimeException('Unable to load the Phase 5A focus preset');
        }

        $allowedProductIds = [];
        foreach ((array)($catalog['products'] ?? []) as $product) {
            if (!is_array($product)) {
                continue;
            }
            $productId = (int)($product['id'] ?? 0);
            if ($productId > 0) {
                $allowedProductIds[$productId] = true;
            }
        }
        $allowedProductIds = array_map('intval', array_keys($allowedProductIds));
        sort($allowedProductIds, SORT_NUMERIC);

        $dependency = $this->buildDependencyArtifacts(self::FOCUS_PRESET_ID, $allowedProductIds);

        $goldenCaptures = call_user_func(
            $this->goldenLoader,
            self::FOCUS_PRESET_ID,
            self::GOLDEN_PRODUCT_IDS
        );
        if (!is_array($goldenCaptures)) {
            throw new \RuntimeException('Unable to load the Phase 5A golden captures');
        }
        $goldenCaptureMode = 'injected_capture';
        $goldenBaselineKind = 'injected';
        if (array_key_exists('captures', $goldenCaptures)) {
            $goldenCaptureMode = (string)($goldenCaptures['mode'] ?? 'injected_capture');
            $goldenBaselineKind = (string)($goldenCaptures['baselineKind'] ?? 'injected');
            $goldenCaptures = is_array($goldenCaptures['captures'] ?? null)
                ? $goldenCaptures['captures']
                : [];
        }
        $golden = [];
        $goldenValid = in_array($goldenBaselineKind, ['production', 'injected'], true);
        foreach (self::GOLDEN_PRODUCT_IDS as $productId) {
            $capture = $goldenCaptures[$productId] ?? $goldenCaptures[(string)$productId] ?? null;
            $goldenProduct = $this->normalizeGoldenProduct(
                $productId,
                in_array($productId, $allowedProductIds, true),
                is_array($capture) ? $capture : []
            );
            $golden[] = $goldenProduct;
            if (($goldenProduct['status'] ?? '') !== 'matched') {
                $goldenValid = false;
            }
        }

        $payload = [
            'contract' => self::CONTRACT,
            'presetId' => self::FOCUS_PRESET_ID,
            'readOnly' => true,
            'allowedProductIds' => $allowedProductIds,
            'dependencyMatrix' => $dependency['matrix'],
            'publicInputContract' => $dependency['publicInputContract'],
            'goldenParity' => [
                'contract' => 'prospektweb.calc.form-first-golden/v1',
                'readOnly' => true,
                'captureMode' => $goldenCaptureMode,
                'baselineKind' => $goldenBaselineKind,
                'productionCaptureRequiredBeforeRelease' => $goldenBaselineKind !== 'production',
                'valid' => $goldenValid,
                'products' => $golden,
                'requiredAssertions' => [
                    'runtimeState/runtimeSource (product 5058 is exact unavailable/none; available elsewhere)',
                    'prices',
                    'selectedStageIds',
                    'routeProductId',
                    'reopenSelection',
                    'basketReprice',
                    'basketFingerprint',
                    'schemaFingerprint',
                    'compileHash (product 4267 only; null otherwise)',
                    'formRevision',
                    'bindingRevision',
                    'publishedRevision',
                ],
                'authoringAssertionsPilotProductId' => 4267,
            ],
            'runtimeBoundary' => [
                'runtimeSchemaVersion' => 2,
                'syntheticOfferProperties' => 'CALC_PROP_*',
                'calcServerChangeRequired' => $dependency['matrix']['valid'] && $goldenValid ? false : null,
                'calcServerDecision' => $dependency['matrix']['valid'] && $goldenValid
                    ? 'current_contract_parity_proven'
                    : 'undetermined_until_parity',
            ],
        ];
        $payload['fingerprint'] = $this->canonicalHash($payload);

        return $payload;
    }

    /**
     * Resolve the current preset authority independently of browser input.
     *
     * @param int[]|null $allowedProductIds
     * @return array<string,mixed>
     */
    public function buildPublicInputContract(
        int $presetId = self::FOCUS_PRESET_ID,
        ?array $allowedProductIds = null
    ): array {
        if ($presetId !== self::FOCUS_PRESET_ID) {
            throw new \InvalidArgumentException('Only preset 12740 is supported');
        }
        if ($allowedProductIds === null) {
            $catalog = call_user_func($this->presetLoader, $presetId);
            if (!is_array($catalog)
                || (string)($catalog['status'] ?? '') !== 'ok'
                || (int)($catalog['preset']['id'] ?? 0) !== $presetId) {
                throw new \RuntimeException('Unable to load the Phase 5A focus preset');
            }
            $allowedProductIds = [];
            foreach ((array)($catalog['products'] ?? []) as $product) {
                if (is_array($product) && (int)($product['id'] ?? 0) > 0) {
                    $allowedProductIds[] = (int)$product['id'];
                }
            }
        }
        $allowedProductIds = $this->normalizeProductIds($allowedProductIds);
        if ($allowedProductIds === []) {
            throw new \RuntimeException('The Phase 5A preset has no authorized products');
        }
        $artifacts = $this->buildDependencyArtifacts($presetId, $allowedProductIds);
        if (($artifacts['matrix']['valid'] ?? false) !== true) {
            throw new \RuntimeException('The current Phase 5A dependency authority is incomplete');
        }

        return $artifacts['publicInputContract'];
    }

    public function compare(array $baseline, array $candidate): array
    {
        $catalog = call_user_func($this->presetLoader, self::FOCUS_PRESET_ID);
        if (!is_array($catalog)
            || (string)($catalog['status'] ?? '') !== 'ok'
            || (int)($catalog['preset']['id'] ?? 0) !== self::FOCUS_PRESET_ID) {
            throw new \RuntimeException('Unable to load the Phase 5A focus preset');
        }
        $currentProductIds = [];
        foreach ((array)($catalog['products'] ?? []) as $product) {
            if (is_array($product) && (int)($product['id'] ?? 0) > 0) {
                $currentProductIds[] = (int)$product['id'];
            }
        }
        foreach (self::GOLDEN_PRODUCT_IDS as $productId) {
            if (!in_array($productId, $currentProductIds, true)) {
                throw new \InvalidArgumentException(
                    'Golden product ' . $productId . ' is outside the current preset allowlist'
                );
            }
        }

        $normalizedBaseline = $this->normalizeObservation($baseline, 'baseline');
        $normalizedCandidate = $this->normalizeObservation($candidate, 'candidate');
        foreach ($normalizedBaseline['products'] as $baselineProduct) {
            $productId = (int)($baselineProduct['productId'] ?? 0);
            $expectedRuntimeState = $productId === self::UNAVAILABLE_RUNTIME_PRODUCT_ID
                ? 'unavailable'
                : 'available';
            if (($baselineProduct['runtimeState'] ?? '') !== $expectedRuntimeState) {
                throw new \InvalidArgumentException(
                    'baseline.products.' . $productId . '.runtimeState does not match the authoritative runtime baseline'
                );
            }
        }
        $productResults = [];
        $valid = true;
        $authoringTransition = null;
        $semanticFields = [
            'runtimeState',
            'prices',
            'selectedStageIds',
            'routeProductId',
            'reopenSelection',
            'basketReprice',
            'basketFingerprint',
        ];
        foreach (self::GOLDEN_PRODUCT_IDS as $index => $productId) {
            $left = $normalizedBaseline['products'][$index];
            $right = $normalizedCandidate['products'][$index];
            $mismatches = [];
            foreach ($semanticFields as $semanticField) {
                foreach ($this->differencePaths(
                    $left[$semanticField],
                    $right[$semanticField],
                    'products.' . $productId . '.' . $semanticField
                ) as $difference) {
                    $mismatches[] = $difference;
                }
            }
            if ($productId !== 4267) {
                foreach ($this->differencePaths(
                    $left['runtimeSource'],
                    $right['runtimeSource'],
                    'products.' . $productId . '.runtimeSource'
                ) as $difference) {
                    $mismatches[] = $difference;
                }
                foreach ($this->differencePaths(
                    $left['schemaFingerprint'],
                    $right['schemaFingerprint'],
                    'products.' . $productId . '.schemaFingerprint'
                ) as $difference) {
                    $mismatches[] = $difference;
                }
            } else {
                $publicationAdvanced = $right['publishedRevision'] > $left['publishedRevision'];
                $schemaFingerprintChanged = !hash_equals(
                    $left['schemaFingerprint'],
                    $right['schemaFingerprint']
                );
                $revisionMonotonic = $right['formRevision'] >= $left['formRevision']
                    && $right['bindingRevision'] >= $left['bindingRevision']
                    && $right['publishedRevision'] >= $left['publishedRevision'];
                $schemaTransitionValid = $publicationAdvanced
                    ? $schemaFingerprintChanged
                    : !$schemaFingerprintChanged;
                $runtimeSourceTransitionValid = $publicationAdvanced
                    ? $right['runtimeSource'] === 'form_first'
                    : $right['runtimeSource'] === $left['runtimeSource'];
                $transitionIssues = [];
                if (!$revisionMonotonic) {
                    $transitionIssues[] = 'authoring_revisions_regressed';
                }
                if (!$schemaTransitionValid) {
                    $transitionIssues[] = $publicationAdvanced
                        ? 'published_schema_fingerprint_did_not_change'
                        : 'public_schema_changed_without_publication';
                }
                if (!$runtimeSourceTransitionValid) {
                    $transitionIssues[] = $publicationAdvanced
                        ? 'published_runtime_source_is_not_form_first'
                        : 'runtime_source_changed_without_publication';
                }
                $authoringTransition = [
                    'productId' => 4267,
                    'valid' => $revisionMonotonic
                        && $schemaTransitionValid
                        && $runtimeSourceTransitionValid,
                    'publicationAdvanced' => $publicationAdvanced,
                    'schemaFingerprintChanged' => $schemaFingerprintChanged,
                    'revisionsMonotonic' => $revisionMonotonic,
                    'runtimeSourceTransitionValid' => $runtimeSourceTransitionValid,
                    'baseline' => [
                        'formRevision' => $left['formRevision'],
                        'bindingRevision' => $left['bindingRevision'],
                        'publishedRevision' => $left['publishedRevision'],
                        'compileHash' => $left['compileHash'],
                        'schemaFingerprint' => $left['schemaFingerprint'],
                        'runtimeSource' => $left['runtimeSource'],
                    ],
                    'candidate' => [
                        'formRevision' => $right['formRevision'],
                        'bindingRevision' => $right['bindingRevision'],
                        'publishedRevision' => $right['publishedRevision'],
                        'compileHash' => $right['compileHash'],
                        'schemaFingerprint' => $right['schemaFingerprint'],
                        'runtimeSource' => $right['runtimeSource'],
                    ],
                    'issues' => $transitionIssues,
                ];
                if (!$authoringTransition['valid']) {
                    $valid = false;
                }
            }
            if ($mismatches !== []) {
                $valid = false;
            }
            $productResults[] = [
                'productId' => $productId,
                'valid' => $mismatches === []
                    && ($productId !== 4267 || ($authoringTransition['valid'] ?? false) === true),
                'mismatches' => $mismatches,
                'baselineFingerprint' => $this->canonicalHash($left),
                'candidateFingerprint' => $this->canonicalHash($right),
            ];
        }

        return [
            'contract' => self::COMPARISON_CONTRACT,
            'presetId' => self::FOCUS_PRESET_ID,
            'readOnly' => true,
            'valid' => $valid,
            'products' => $productResults,
            'authoringTransition' => $authoringTransition,
            'baselineFingerprint' => $this->canonicalHash($normalizedBaseline),
            'candidateFingerprint' => $this->canonicalHash($normalizedCandidate),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeObservation(array $observation, string $field): array
    {
        foreach (array_keys($observation) as $key) {
            if (!is_string($key)
                || !in_array($key, ['contract', 'presetId', 'provenance', 'products'], true)) {
                throw new \InvalidArgumentException($field . ' contains unsupported fields');
            }
        }
        if ((string)($observation['contract'] ?? '') !== self::OBSERVATION_CONTRACT) {
            throw new \InvalidArgumentException($field . '.contract is not supported');
        }
        if ((int)($observation['presetId'] ?? 0) !== self::FOCUS_PRESET_ID) {
            throw new \InvalidArgumentException($field . '.presetId must be 12740');
        }
        if (array_key_exists('provenance', $observation)) {
            $this->normalizeObservedObject($observation['provenance'], $field . '.provenance');
        }
        $products = $observation['products'] ?? null;
        if (!is_array($products) || count($products) !== count(self::GOLDEN_PRODUCT_IDS)) {
            throw new \InvalidArgumentException($field . '.products must contain the exact five-product pilot');
        }

        $normalizedProducts = [];
        foreach (self::GOLDEN_PRODUCT_IDS as $index => $expectedProductId) {
            $product = $products[$index] ?? null;
            if (!is_array($product) || (int)($product['productId'] ?? 0) !== $expectedProductId) {
                throw new \InvalidArgumentException(
                    $field . '.products must use the canonical pilot product order'
                );
            }
            $normalizedProducts[] = $this->normalizeObservedProduct($product, $field . '.products.' . $expectedProductId);
        }

        return [
            'contract' => self::OBSERVATION_CONTRACT,
            'presetId' => self::FOCUS_PRESET_ID,
            'products' => $normalizedProducts,
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeObservedProduct(array $product, string $field): array
    {
        $allowedKeys = [
            'productId',
            'runtimeState',
            'runtimeSource',
            'prices',
            'selectedStageIds',
            'routeProductId',
            'reopenSelection',
            'basketReprice',
            'basketFingerprint',
            'schemaFingerprint',
            'compileHash',
            'formRevision',
            'bindingRevision',
            'publishedRevision',
        ];
        foreach (array_keys($product) as $key) {
            if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
                throw new \InvalidArgumentException($field . ' contains unsupported fields');
            }
        }

        $productId = (int)($product['productId'] ?? 0);
        if (array_key_exists('runtimeState', $product) && !is_string($product['runtimeState'])) {
            throw new \InvalidArgumentException($field . '.runtimeState must be a string');
        }
        $runtimeState = array_key_exists('runtimeState', $product)
            ? (string)$product['runtimeState']
            : 'available';
        if (!in_array($runtimeState, ['available', 'unavailable'], true)) {
            throw new \InvalidArgumentException($field . '.runtimeState is not supported');
        }
        $runtimeSource = array_key_exists('runtimeSource', $product)
            ? $product['runtimeSource']
            : null;
        if ($runtimeState === 'unavailable') {
            if ($productId !== self::UNAVAILABLE_RUNTIME_PRODUCT_ID) {
                throw new \InvalidArgumentException(
                    $field . '.runtimeState=unavailable is supported only for product 5058'
                );
            }
            if ($runtimeSource !== 'none') {
                throw new \InvalidArgumentException($field . '.runtimeSource must be none when unavailable');
            }
            foreach (['prices', 'selectedStageIds'] as $emptyListField) {
                if (!array_key_exists($emptyListField, $product) || $product[$emptyListField] !== []) {
                    throw new \InvalidArgumentException(
                        $field . '.' . $emptyListField . ' must be an explicit empty list when unavailable'
                    );
                }
            }
            foreach ([
                'routeProductId',
                'reopenSelection',
                'basketReprice',
                'basketFingerprint',
                'schemaFingerprint',
                'compileHash',
                'formRevision',
                'bindingRevision',
                'publishedRevision',
            ] as $nullField) {
                if (!array_key_exists($nullField, $product) || $product[$nullField] !== null) {
                    throw new \InvalidArgumentException(
                        $field . '.' . $nullField . ' must be explicit null when unavailable'
                    );
                }
            }

            return [
                'productId' => $productId,
                'runtimeState' => 'unavailable',
                'runtimeSource' => 'none',
                'prices' => [],
                'selectedStageIds' => [],
                'routeProductId' => null,
                'reopenSelection' => null,
                'basketReprice' => null,
                'basketFingerprint' => null,
                'schemaFingerprint' => null,
                'compileHash' => null,
                'formRevision' => null,
                'bindingRevision' => null,
                'publishedRevision' => null,
            ];
        }
        if ($runtimeSource !== null
            && (!is_string($runtimeSource)
                || !in_array($runtimeSource, self::AVAILABLE_RUNTIME_SOURCES, true))) {
            throw new \InvalidArgumentException($field . '.runtimeSource is not supported for an available runtime');
        }

        $prices = $product['prices'] ?? null;
        if (!is_array($prices) || $prices === [] || count($prices) > 100) {
            throw new \InvalidArgumentException($field . '.prices must be a non-empty bounded list');
        }
        $normalizedPrices = [];
        foreach ($prices as $price) {
            if (!is_array($price)
                || !is_int($price['quantity'] ?? null)
                || (int)$price['quantity'] <= 0
                || (!is_int($price['price'] ?? null) && !is_float($price['price'] ?? null))
                || !is_finite((float)$price['price'])
                || (float)$price['price'] < 0
                || preg_match('/^[A-Z]{3}$/D', (string)($price['currency'] ?? '')) !== 1) {
                throw new \InvalidArgumentException($field . '.prices contains an invalid quote');
            }
            $normalizedPrices[] = [
                'quantity' => (int)$price['quantity'],
                'price' => (float)$price['price'],
                'currency' => (string)$price['currency'],
            ];
        }
        usort($normalizedPrices, static function (array $left, array $right): int {
            return $left['quantity'] <=> $right['quantity'];
        });

        $stageIds = $product['selectedStageIds'] ?? null;
        if (!is_array($stageIds) || $stageIds === []) {
            throw new \InvalidArgumentException($field . '.selectedStageIds must be a non-empty list');
        }
        $normalizedStageIds = [];
        foreach ($stageIds as $stageId) {
            if (!is_int($stageId) || $stageId <= 0) {
                throw new \InvalidArgumentException($field . '.selectedStageIds contains an invalid ID');
            }
            $normalizedStageIds[$stageId] = $stageId;
        }
        $normalizedStageIds = array_values($normalizedStageIds);
        sort($normalizedStageIds, SORT_NUMERIC);

        $routeProductId = $product['routeProductId'] ?? null;
        if (!is_int($routeProductId) || $routeProductId <= 0) {
            throw new \InvalidArgumentException($field . '.routeProductId must be a positive integer');
        }
        $reopenSelection = $this->normalizeObservedObject(
            $product['reopenSelection'] ?? null,
            $field . '.reopenSelection'
        );
        $basketReprice = $this->normalizeObservedObject(
            $product['basketReprice'] ?? null,
            $field . '.basketReprice'
        );

        foreach (['basketFingerprint', 'schemaFingerprint'] as $hashField) {
            if (!is_string($product[$hashField] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', (string)$product[$hashField]) !== 1) {
                throw new \InvalidArgumentException($field . '.' . $hashField . ' must be lowercase SHA-256');
            }
        }
        $isPilot = (int)$product['productId'] === 4267;
        if ($isPilot) {
            if (!is_string($product['compileHash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', (string)$product['compileHash']) !== 1) {
                throw new \InvalidArgumentException($field . '.compileHash must be lowercase SHA-256 for product 4267');
            }
            foreach (['formRevision', 'bindingRevision', 'publishedRevision'] as $revisionField) {
                if (!is_int($product[$revisionField] ?? null) || (int)$product[$revisionField] < 0) {
                    throw new \InvalidArgumentException(
                        $field . '.' . $revisionField . ' must be a non-negative integer for product 4267'
                    );
                }
            }
        } else {
            foreach (['compileHash', 'formRevision', 'bindingRevision', 'publishedRevision'] as $notApplicableField) {
                if (!array_key_exists($notApplicableField, $product) || $product[$notApplicableField] !== null) {
                    throw new \InvalidArgumentException(
                        $field . '.' . $notApplicableField . ' must be null outside product 4267'
                    );
                }
            }
        }

        return [
            'productId' => $productId,
            'runtimeState' => 'available',
            'runtimeSource' => $runtimeSource,
            'prices' => $normalizedPrices,
            'selectedStageIds' => $normalizedStageIds,
            'routeProductId' => $routeProductId,
            'reopenSelection' => $reopenSelection,
            'basketReprice' => $basketReprice,
            'basketFingerprint' => (string)$product['basketFingerprint'],
            'schemaFingerprint' => (string)$product['schemaFingerprint'],
            'compileHash' => $isPilot ? (string)$product['compileHash'] : null,
            'formRevision' => $isPilot ? (int)$product['formRevision'] : null,
            'bindingRevision' => $isPilot ? (int)$product['bindingRevision'] : null,
            'publishedRevision' => $isPilot ? (int)$product['publishedRevision'] : null,
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeObservedObject($value, string $field): array
    {
        if (!is_array($value)
            || $value === []
            || array_keys($value) === range(0, count($value) - 1)) {
            throw new \InvalidArgumentException($field . ' must be a non-empty object');
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > 60000) {
            throw new \InvalidArgumentException($field . ' must be valid JSON no larger than 60000 bytes');
        }

        return $this->sortRecursively($value);
    }

    /** @return string[] */
    private function differencePaths($baseline, $candidate, string $path): array
    {
        if ((is_int($baseline) || is_float($baseline))
            && (is_int($candidate) || is_float($candidate))) {
            return (float)$baseline === (float)$candidate ? [] : [$path];
        }
        if (gettype($baseline) !== gettype($candidate)) {
            return [$path];
        }
        if (!is_array($baseline)) {
            return $baseline === $candidate ? [] : [$path];
        }

        $differences = [];
        $keys = array_values(array_unique(array_merge(array_keys($baseline), array_keys($candidate))));
        foreach ($keys as $key) {
            $nextPath = $path . '.' . (string)$key;
            if (!array_key_exists($key, $baseline) || !array_key_exists($key, $candidate)) {
                $differences[] = $nextPath;
                continue;
            }
            foreach ($this->differencePaths($baseline[$key], $candidate[$key], $nextPath) as $difference) {
                $differences[] = $difference;
            }
        }

        return $differences;
    }

    /**
     * @param int[] $allowedProductIds
     * @return array{matrix:array<string,mixed>,publicInputContract:array<string,mixed>}
     */
    private function buildDependencyArtifacts(int $presetId, array $allowedProductIds): array
    {
        $graph = call_user_func($this->dependencyLoader, $presetId, $allowedProductIds);
        if (!is_array($graph) || (int)($graph['presetId'] ?? 0) !== $presetId) {
            throw new \RuntimeException('Unable to load the Phase 5A dependency graph');
        }

        $consumers = $this->normalizeConsumers((array)($graph['consumers'] ?? []));
        $propertyCodes = array_values(array_unique(array_column($consumers, 'propertyCode')));
        sort($propertyCodes, SORT_STRING);
        $publicUiPropertyCodes = [];
        foreach ($consumers as $consumer) {
            if ($consumer['category'] === 'ui') {
                $publicUiPropertyCodes[$consumer['propertyCode']] = true;
            }
        }
        // Only effective RuntimeSchema v2 fields marked required=true are
        // public input obligations. Stage/global/passive/system consumers stay
        // visible in the matrix, but cannot promote an internal CALC_PROP into
        // a form field unless the same code is an effective public UI field.
        $requiredPropertyCodes = array_values(array_filter(
            $this->normalizePropertyCodes((array)($graph['requiredPropertyCodes'] ?? [])),
            static function (string $propertyCode) use ($publicUiPropertyCodes): bool {
                return isset($publicUiPropertyCodes[$propertyCode]);
            }
        ));
        sort($requiredPropertyCodes, SORT_STRING);
        $unresolvedSources = [];
        foreach ((array)($graph['unresolvedSources'] ?? []) as $unresolvedSource) {
            $unresolvedSource = trim((string)$unresolvedSource);
            if ($unresolvedSource !== '') {
                $unresolvedSources[$unresolvedSource] = true;
            }
        }

        $byProperty = [];
        foreach ($propertyCodes as $propertyCode) {
            $categories = [];
            $propertyConsumers = [];
            foreach ($consumers as $consumer) {
                if ($consumer['propertyCode'] !== $propertyCode) {
                    continue;
                }
                $categories[$consumer['category']] = true;
                $propertyConsumers[] = $consumer;
            }
            $categoryNames = array_keys($categories);
            sort($categoryNames, SORT_STRING);
            $byProperty[] = [
                'propertyCode' => $propertyCode,
                'categories' => $categoryNames,
                'consumers' => $propertyConsumers,
            ];
        }

        $rawCategoryStatus = is_array($graph['categoryStatus'] ?? null)
            ? $graph['categoryStatus']
            : [];
        $categoryCoverage = [];
        $categoryStatus = [];
        $matrixValid = true;
        foreach (self::REQUIRED_CATEGORIES as $category) {
            $count = 0;
            foreach ($consumers as $consumer) {
                if ($consumer['category'] === $category) {
                    $count++;
                }
            }
            $status = is_array($rawCategoryStatus[$category] ?? null)
                ? $rawCategoryStatus[$category]
                : [];
            $scanned = ($status['scanned'] ?? false) === true;
            $sourceMode = (string)($status['sourceMode'] ?? '');
            if (!in_array($sourceMode, ['discovered', 'declared'], true)) {
                $scanned = false;
                $sourceMode = 'discovered';
            }
            if (!$scanned) {
                $matrixValid = false;
                $unresolvedSources['category_not_scanned:' . $category] = true;
            }
            $categoryCoverage[$category] = $count;
            $categoryStatus[$category] = [
                'scanned' => $scanned,
                'count' => $count,
                'sourceMode' => $sourceMode,
            ];
        }
        $unresolvedSources = array_keys($unresolvedSources);
        sort($unresolvedSources, SORT_STRING);
        $matrixValid = $matrixValid && $unresolvedSources === [];

        $publicInputContract = [
            'contract' => 'prospektweb.calc.preset-public-inputs/v1',
            'presetId' => $presetId,
            'requiredPropertyCodes' => $requiredPropertyCodes,
            'consumers' => $consumers,
            'categoryStatus' => $categoryStatus,
        ];
        $publicInputContract['fingerprint'] = $this->canonicalHash($publicInputContract);

        return [
            'matrix' => [
                'valid' => $matrixValid,
                'requiredCategories' => self::REQUIRED_CATEGORIES,
                'categoryCoverage' => $categoryCoverage,
                'categoryStatus' => $categoryStatus,
                'propertyCodes' => $propertyCodes,
                'requiredPropertyCodes' => $requiredPropertyCodes,
                'byProperty' => $byProperty,
                'consumers' => $consumers,
                'unresolvedSources' => $unresolvedSources,
                'sourceFingerprint' => $this->canonicalHash($graph),
            ],
            'publicInputContract' => $publicInputContract,
        ];
    }

    /** @param mixed[] $productIds @return int[] */
    private function normalizeProductIds(array $productIds): array
    {
        $normalized = [];
        foreach ($productIds as $productId) {
            if (is_int($productId) && $productId > 0) {
                $normalized[$productId] = true;
            }
        }
        $result = array_map('intval', array_keys($normalized));
        sort($result, SORT_NUMERIC);

        return $result;
    }

    /** @param mixed[] $propertyCodes @return string[] */
    private function normalizePropertyCodes(array $propertyCodes): array
    {
        $normalized = [];
        foreach ($propertyCodes as $propertyCode) {
            $propertyCode = strtoupper(trim((string)$propertyCode));
            if (preg_match('/^CALC_PROP_[A-Z0-9_]+$/D', $propertyCode) === 1) {
                $normalized[$propertyCode] = true;
            }
        }
        $result = array_keys($normalized);
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * @param array<int,mixed> $rawConsumers
     * @return array<int,array{propertyCode:string,category:string,source:string,path:string,provenance:string}>
     */
    private function normalizeConsumers(array $rawConsumers): array
    {
        $normalized = [];
        foreach ($rawConsumers as $consumer) {
            if (!is_array($consumer)) {
                continue;
            }
            $propertyCode = strtoupper(trim((string)($consumer['propertyCode'] ?? '')));
            $category = trim((string)($consumer['category'] ?? ''));
            $source = trim((string)($consumer['source'] ?? ''));
            $path = trim((string)($consumer['path'] ?? ''));
            $provenance = trim((string)($consumer['provenance'] ?? ''));
            if (preg_match('/^CALC_PROP_[A-Z0-9_]+$/D', $propertyCode) !== 1
                || !in_array($category, self::REQUIRED_CATEGORIES, true)
                || $source === ''
                || $path === ''
                || !in_array($provenance, ['discovered', 'declared'], true)) {
                continue;
            }
            $key = implode('|', [$propertyCode, $category, $source, $path, $provenance]);
            $normalized[$key] = compact('propertyCode', 'category', 'source', 'path', 'provenance');
        }
        ksort($normalized, SORT_STRING);

        return array_values($normalized);
    }

    /** @return array{baselineKind:string,captures:array<int,array<string,mixed>>} */
    private static function loadVersionedGoldenFixture(): array
    {
        $path = dirname(__DIR__, 2) . '/resources/phase5a_golden_capture_v1.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        $fixture = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($fixture)
            || (string)($fixture['contract'] ?? '') !== self::OBSERVATION_CONTRACT
            || (int)($fixture['presetId'] ?? 0) !== self::FOCUS_PRESET_ID) {
            throw new \RuntimeException('The versioned Phase 5A golden fixture is unavailable');
        }
        $captures = [];
        foreach ((array)($fixture['products'] ?? []) as $product) {
            if (!is_array($product) || !is_int($product['productId'] ?? null)) {
                continue;
            }
            $productId = (int)$product['productId'];
            $capture = $product;
            unset($capture['productId']);
            $captures[$productId] = $capture;
        }
        if (array_keys($captures) !== self::GOLDEN_PRODUCT_IDS) {
            throw new \RuntimeException('The versioned Phase 5A golden fixture is incomplete');
        }

        return [
            'baselineKind' => (string)($fixture['provenance']['kind'] ?? 'unknown'),
            'captures' => $captures,
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeGoldenProduct(int $productId, bool $allowed, array $capture): array
    {
        $required = [
            'runtimeState',
            'runtimeSource',
            'prices',
            'selectedStageIds',
            'routeProductId',
            'reopenSelection',
            'basketReprice',
            'basketFingerprint',
            'schemaFingerprint',
            'compileHash',
            'formRevision',
            'bindingRevision',
            'publishedRevision',
        ];
        $missing = [];
        foreach ($required as $field) {
            if (!$this->hasGoldenAssertion($productId, $field, $capture)) {
                $missing[] = $field;
            }
        }

        $baseline = self::GOLDEN_BASELINE[$productId] ?? [];
        $captureStatus = trim((string)($capture['captureStatus'] ?? ''));
        $captureError = trim((string)($capture['captureError'] ?? ''));
        $mismatches = [];
        foreach ($baseline as $field => $expectedValue) {
            if (!array_key_exists($field, $capture)) {
                continue;
            }
            if ($this->differencePaths($expectedValue, $capture[$field], $field) !== []) {
                $mismatches[] = $field;
            }
        }

        $status = 'matched';
        if (!$allowed) {
            $status = 'outside_current_preset';
        } elseif ($captureStatus === 'blocked') {
            $status = 'blocked';
        } elseif ($mismatches !== []) {
            $status = 'mismatch';
        } elseif ($missing !== []) {
            $status = 'capture_required';
        }

        return [
            'productId' => $productId,
            'allowedByCurrentPreset' => $allowed,
            'status' => $status,
            'missingAssertions' => $missing,
            'mismatches' => $mismatches,
            'captureError' => $captureError !== '' ? $captureError : null,
            'baseline' => $baseline,
            'capture' => $capture,
        ];
    }

    /** @param array<string,mixed> $capture */
    private function hasGoldenAssertion(int $productId, string $field, array $capture): bool
    {
        $hasValue = array_key_exists($field, $capture);
        $value = $hasValue ? $capture[$field] : null;
        $runtimeState = array_key_exists('runtimeState', $capture)
            ? (string)$capture['runtimeState']
            : 'available';
        $runtimeSource = $capture['runtimeSource'] ?? null;
        $isUnavailable = $productId === self::UNAVAILABLE_RUNTIME_PRODUCT_ID
            && $runtimeState === 'unavailable'
            && $runtimeSource === 'none';

        if ($field === 'runtimeState') {
            return $productId === self::UNAVAILABLE_RUNTIME_PRODUCT_ID
                ? $hasValue && $value === 'unavailable'
                : (!$hasValue || $value === 'available');
        }
        if ($field === 'runtimeSource') {
            if ($productId === self::UNAVAILABLE_RUNTIME_PRODUCT_ID) {
                return $hasValue && $value === 'none';
            }
            return !$hasValue
                || (is_string($value) && in_array($value, self::AVAILABLE_RUNTIME_SOURCES, true));
        }
        if ($runtimeState === 'unavailable' && !$isUnavailable) {
            return false;
        }
        if ($isUnavailable) {
            if ($field === 'prices' || $field === 'selectedStageIds') {
                return $hasValue && $value === [];
            }
            return $hasValue && $value === null;
        }
        if ($field === 'prices' || $field === 'selectedStageIds') {
            return is_array($value) && $value !== [];
        }
        if ($field === 'routeProductId') {
            return is_int($value) && $value > 0;
        }
        if ($field === 'reopenSelection' || $field === 'basketReprice') {
            return is_array($value)
                && $value !== []
                && array_keys($value) !== range(0, count($value) - 1);
        }
        if (in_array($field, ['basketFingerprint', 'schemaFingerprint'], true)) {
            return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
        }
        if ($field === 'compileHash') {
            return $productId === 4267
                ? is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1
                : $value === null;
        }
        if (in_array($field, ['formRevision', 'bindingRevision', 'publishedRevision'], true)) {
            return $productId === 4267
                ? is_int($value) && $value >= 0
                : $value === null;
        }

        return $value !== null;
    }

    /** @return array<string,mixed> */
    /** @param int[] $allowedProductIds @return array<string,mixed> */
    private static function loadBitrixDependencyGraph(int $presetId, array $allowedProductIds): array
    {
        if ($presetId !== self::FOCUS_PRESET_ID) {
            throw new \InvalidArgumentException('Only preset 12740 is supported');
        }
        if (!class_exists('CIBlockElement')) {
            throw new \RuntimeException('The Bitrix iblock API is not available');
        }

        $consumers = [];
        $requiredPropertyCodes = [];
        $unresolvedSources = [];
        $categoryStatus = [];
        foreach (self::REQUIRED_CATEGORIES as $category) {
            $categoryStatus[$category] = [
                'scanned' => false,
                'sourceMode' => $category === 'basket' ? 'declared' : 'discovered',
            ];
        }
        $presetIblockId = self::readOnlyIblockId('CALC_PRESETS', 'calculator');
        $detailsIblockId = self::readOnlyIblockId('CALC_DETAILS', 'calculator_catalog');
        $stagesIblockId = self::readOnlyIblockId('CALC_STAGES', 'calculator_catalog');
        $globalsIblockId = self::readOnlyIblockId('CALC_GLOBAL_VALUES', 'calculator');
        if ($presetIblockId <= 0) {
            $unresolvedSources[] = 'iblock_not_configured:CALC_PRESETS';
        }
        if ($detailsIblockId <= 0) {
            $unresolvedSources[] = 'iblock_not_configured:CALC_DETAILS';
        }
        if ($stagesIblockId <= 0) {
            $unresolvedSources[] = 'iblock_not_configured:CALC_STAGES';
        }
        if ($globalsIblockId <= 0) {
            $unresolvedSources[] = 'iblock_not_configured:CALC_GLOBAL_VALUES';
        }
        $elementScopes = [];
        if ($presetIblockId > 0) {
            $elementScopes[] = [
                'iblockCode' => 'CALC_PRESETS',
                'iblockId' => $presetIblockId,
                'elementIds' => [$presetId],
                'category' => 'stage_inputs',
            ];
        }
        $presetDetailIds = $presetIblockId > 0
            ? self::elementPropertyIds($presetIblockId, $presetId, 'CALC_DETAILS')
            : [];
        $presetStageIds = $presetIblockId > 0
            ? self::elementPropertyIds($presetIblockId, $presetId, 'CALC_STAGES')
            : [];
        $seenDetailIds = [];
        $pendingDetailIds = $presetDetailIds;
        while ($pendingDetailIds !== []) {
            $detailId = (int)array_shift($pendingDetailIds);
            if ($detailId <= 0 || isset($seenDetailIds[$detailId])) {
                continue;
            }
            $seenDetailIds[$detailId] = true;
            if ($detailsIblockId <= 0) {
                continue;
            }
            foreach (self::elementPropertyIds($detailsIblockId, $detailId, 'DETAILS') as $childId) {
                $pendingDetailIds[] = $childId;
            }
            foreach (self::elementPropertyIds($detailsIblockId, $detailId, 'CALC_STAGES') as $stageId) {
                $presetStageIds[] = $stageId;
            }
        }
        if ($detailsIblockId > 0 && $seenDetailIds !== []) {
            $elementScopes[] = [
                'iblockCode' => 'CALC_DETAILS',
                'iblockId' => $detailsIblockId,
                'elementIds' => array_map('intval', array_keys($seenDetailIds)),
                'category' => 'stage_inputs',
            ];
        }
        $presetStageIds = array_values(array_unique(array_filter(array_map('intval', $presetStageIds))));
        if ($stagesIblockId > 0 && $presetStageIds !== []) {
            $elementScopes[] = [
                'iblockCode' => 'CALC_STAGES',
                'iblockId' => $stagesIblockId,
                'elementIds' => $presetStageIds,
                'category' => 'stage_inputs',
            ];
        }

        foreach ($elementScopes as $scope) {
            $iblockCode = (string)$scope['iblockCode'];
            $iblockId = (int)$scope['iblockId'];
            $defaultCategory = (string)$scope['category'];
            $cursor = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                ['IBLOCK_ID' => $iblockId, 'ID' => $scope['elementIds']],
                false,
                false,
                ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'PREVIEW_TEXT', 'DETAIL_TEXT']
            );
            while ($element = $cursor->GetNextElement()) {
                $fields = $element->GetFields();
                $elementId = (int)($fields['ID'] ?? 0);
                self::scanValueForConsumers(
                    $fields,
                    $defaultCategory,
                    $iblockCode,
                    'element:' . $elementId . '.fields',
                    $consumers
                );
                self::scanValueForConsumers(
                    $element->GetProperties(),
                    $defaultCategory,
                    $iblockCode,
                    'element:' . $elementId . '.properties',
                    $consumers
                );
            }
        }
        if ($presetIblockId > 0 && $detailsIblockId > 0 && $stagesIblockId > 0) {
            $categoryStatus['stage_inputs']['scanned'] = true;
            $categoryStatus['options_mappings']['scanned'] = true;
        }

        if ($globalsIblockId > 0) {
            $cursor = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                ['IBLOCK_ID' => $globalsIblockId, '=PROPERTY_PRESET_ID' => $presetId],
                false,
                false,
                ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'PREVIEW_TEXT', 'DETAIL_TEXT']
            );
            while ($element = $cursor->GetNextElement()) {
                $fields = $element->GetFields();
                $elementId = (int)($fields['ID'] ?? 0);
                self::scanValueForConsumers(
                    $fields,
                    'globals',
                    'CALC_GLOBAL_VALUES',
                    'element:' . $elementId . '.fields',
                    $consumers
                );
                self::scanValueForConsumers(
                    $element->GetProperties(),
                    'globals',
                    'CALC_GLOBAL_VALUES',
                    'element:' . $elementId . '.properties',
                    $consumers
                );
            }
            $categoryStatus['globals']['scanned'] = true;
        }

        self::scanEffectiveRuntimeSchemas(
            $presetId,
            $allowedProductIds,
            $consumers,
            $requiredPropertyCodes,
            $categoryStatus,
            $unresolvedSources
        );

        return [
            'presetId' => $presetId,
            'consumers' => $consumers,
            'requiredPropertyCodes' => array_values(array_unique($requiredPropertyCodes)),
            'categoryStatus' => $categoryStatus,
            'unresolvedSources' => $unresolvedSources,
        ];
    }

    /**
     * CalculatorTemplateManager is the exact published-aware RuntimeSchema v2
     * resolver used by the public AJAX/runtime. Empty sources are rejected:
     * they do not prove a public runtime dependency.
     *
     * @param int[] $allowedProductIds
     * @param array<int,array<string,string>> $consumers
     * @param string[] $requiredPropertyCodes
     * @param array<string,array<string,mixed>> $categoryStatus
     * @param string[] $unresolvedSources
     */
    private static function scanEffectiveRuntimeSchemas(
        int $presetId,
        array $allowedProductIds,
        array &$consumers,
        array &$requiredPropertyCodes,
        array &$categoryStatus,
        array &$unresolvedSources
    ): void {
        $runtimeCategories = ['ui', 'passive_context', 'routes', 'basket', 'seo_display'];
        $normalizedProductIds = [];
        foreach ($allowedProductIds as $productId) {
            if (is_int($productId) && $productId > 0) {
                $normalizedProductIds[$productId] = true;
            }
        }
        $allowedProductIds = array_map('intval', array_keys($normalizedProductIds));
        sort($allowedProductIds, SORT_NUMERIC);
        if ($allowedProductIds === []) {
            $unresolvedSources[] = 'effective_runtime:no_allowed_products';
            return;
        }
        if (!\Bitrix\Main\Loader::includeModule('prospektweb.frontcalc')) {
            $unresolvedSources[] = 'effective_runtime:frontcalc_unavailable';
            return;
        }
        $resolverClass = '\\Prospektweb\\Frontcalc\\Service\\CalculatorTemplateManager';
        if (!class_exists($resolverClass)) {
            $unresolvedSources[] = 'effective_runtime:resolver_unavailable';
            return;
        }
        // The public FrontCalc runtime owns this context and stores it under
        // PRODUCTS_IBLOCK_ID. AdminCalc's legacy singular PRODUCT_IBLOCK_ID is
        // unrelated and can legitimately be unset on the same installation.
        $productsIblockId = (int)\Bitrix\Main\Config\Option::get(
            'prospektweb.frontcalc',
            'PRODUCTS_IBLOCK_ID',
            '0'
        );
        $propertyCode = trim((string)\Bitrix\Main\Config\Option::get(
            'prospektweb.frontcalc',
            'CALC_PROPERTY_CODE',
            'FRONTCALC_CONFIG'
        ));
        if ($productsIblockId <= 0 || $propertyCode === '') {
            $unresolvedSources[] = 'effective_runtime:resolver_context_unavailable';
            return;
        }
        $resolver = new $resolverClass();

        $complete = true;
        foreach ($allowedProductIds as $productId) {
            try {
                $resolved = $resolver->resolveForProduct($productId, $productsIblockId, $propertyCode);
            } catch (\Throwable $exception) {
                $complete = false;
                $unresolvedSources[] = 'effective_runtime:product:' . $productId . ':load_failed';
                continue;
            }
            $runtime = self::classifyEffectiveRuntimeResult(is_array($resolved) ? $resolved : []);
            if ($runtime['state'] === 'empty') {
                // A product can be in the preset allowlist without an
                // individual/template runtime. The exact public resolver has
                // proved that there are no UI/runtime consumers to scan; this
                // is not an unknown dependency source.
                continue;
            }
            if ($runtime['state'] !== 'supported') {
                $complete = false;
                $unresolvedSources[] = 'effective_runtime:product:' . $productId . ':invalid_workspace';
                continue;
            }
            self::scanRuntimeSchema(
                $productId,
                $runtime['source'],
                $runtime['schema'],
                $consumers,
                $requiredPropertyCodes
            );
        }

        if ($complete) {
            foreach ($runtimeCategories as $category) {
                $categoryStatus[$category]['scanned'] = true;
            }
        }
    }

    /**
     * RuntimeSchema v1 remains active for part of preset 12740. Its public
     * fields are still dependency consumers and must be scanned instead of
     * making the v2 authoring pilot unavailable. Only the resolver's exact
     * `source=none` + empty-schema result is a proven zero-consumer state.
     *
     * @param array<string,mixed> $resolved
     * @return array{state:string,source:string,schema:array<string,mixed>}
     */
    private static function classifyEffectiveRuntimeResult(array $resolved): array
    {
        $source = (string)($resolved['source'] ?? '');
        $schema = is_array($resolved['schema'] ?? null) ? $resolved['schema'] : [];
        if ($source === 'none' && $schema === []) {
            return ['state' => 'empty', 'source' => $source, 'schema' => $schema];
        }

        $supportedSources = ['family', 'template', 'product', 'product_override', 'form_first'];
        $version = (int)($schema['version'] ?? 0);
        if (in_array($source, $supportedSources, true)
            && in_array($version, [1, 2], true)
            && is_array($schema['fields'] ?? null)) {
            return ['state' => 'supported', 'source' => $source, 'schema' => $schema];
        }

        return ['state' => 'invalid', 'source' => $source, 'schema' => $schema];
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<int,array<string,string>> $consumers
     * @param string[] $requiredPropertyCodes
     */
    private static function scanRuntimeSchema(
        int $productId,
        string $runtimeSource,
        array $schema,
        array &$consumers,
        array &$requiredPropertyCodes
    ): void {
        $source = 'frontcalc.effective_runtime_v'
            . (int)($schema['version'] ?? 0)
            . ':' . $runtimeSource;
        foreach ($schema['fields'] as $fieldIndex => $field) {
            if (!is_array($field)) {
                continue;
            }
            $propertyCode = strtoupper(trim((string)($field['property_code'] ?? '')));
            if (!self::isCalcPropertyCode($propertyCode)) {
                continue;
            }
            $basePath = 'products.' . $productId . '.schema.fields.' . (int)$fieldIndex;
            self::appendConsumer($consumers, $propertyCode, 'ui', $source, $basePath . '.property_code', 'discovered');
            self::appendConsumer(
                $consumers,
                $propertyCode,
                'basket',
                'frontcalc.calculation_session',
                'products.' . $productId . '.FRONTCALC_SELECTION_JSON.' . $propertyCode,
                'declared'
            );
            $isRequired = ($field['required'] ?? false) === true
                || ((int)($schema['version'] ?? 0) === 1
                    && !array_key_exists('required', $field));
            if ($isRequired) {
                $requiredPropertyCodes[] = $propertyCode;
            }
            if (trim((string)($field['seed_property_code'] ?? '')) !== '') {
                self::appendConsumer(
                    $consumers,
                    $propertyCode,
                    'passive_context',
                    $source,
                    $basePath . '.seed_property_code',
                    'discovered'
                );
            }
            if ((string)($field['match_role'] ?? 'ignore') !== 'ignore') {
                self::appendConsumer(
                    $consumers,
                    $propertyCode,
                    'passive_context',
                    $source,
                    $basePath . '.match_role',
                    'discovered'
                );
            }
            if (is_array($field['display_preset_xml_ids'] ?? null)
                && $field['display_preset_xml_ids'] !== []) {
                self::appendConsumer(
                    $consumers,
                    $propertyCode,
                    'seo_display',
                    $source,
                    $basePath . '.display_preset_xml_ids',
                    'discovered'
                );
            }
            if (trim((string)($field['display_mode'] ?? '')) !== '') {
                self::appendConsumer(
                    $consumers,
                    $propertyCode,
                    'seo_display',
                    $source,
                    $basePath . '.display_mode',
                    'discovered'
                );
            }
        }

        foreach ((array)($schema['family']['routes'] ?? []) as $routeIndex => $route) {
            if (!is_array($route) || !array_key_exists('when', $route)) {
                continue;
            }
            self::scanRouteCondition(
                $route['when'],
                'products.' . $productId . '.schema.family.routes.' . (int)$routeIndex . '.when',
                $source,
                $consumers
            );
        }
    }

    /** @param array<int,array<string,string>> $consumers */
    private static function scanRouteCondition($node, string $path, string $source, array &$consumers): void
    {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            $nextPath = $path . '.' . (string)$key;
            if ((string)$key === 'property_code' && is_scalar($value)) {
                $propertyCode = strtoupper(trim((string)$value));
                if (self::isCalcPropertyCode($propertyCode)) {
                    self::appendConsumer(
                        $consumers,
                        $propertyCode,
                        'routes',
                        $source,
                        $nextPath,
                        'discovered'
                    );
                }
                continue;
            }
            self::scanRouteCondition($value, $nextPath, $source, $consumers);
        }
    }

    /** @param array<int,array<string,string>> $consumers */
    private static function appendConsumer(
        array &$consumers,
        string $propertyCode,
        string $category,
        string $source,
        string $path,
        string $provenance
    ): void {
        $consumers[] = compact('propertyCode', 'category', 'source', 'path', 'provenance');
    }

    private static function isCalcPropertyCode(string $propertyCode): bool
    {
        return preg_match('/^CALC_PROP_[A-Z0-9_]+$/D', $propertyCode) === 1;
    }

    /**
     * Unlike ConfigManager::getIblockId(), dependency capture must not persist
     * a discovered fallback ID into module options.
     */
    private static function readOnlyIblockId(string $code, string $type): int
    {
        $configured = (int)\Bitrix\Main\Config\Option::get(
            'prospektweb.calc',
            'IBLOCK_' . $code,
            0
        );
        if ($configured > 0) {
            return $configured;
        }
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return 0;
        }
        $row = \CIBlock::GetList([], ['CODE' => $code, 'TYPE' => $type])->Fetch();

        return is_array($row) ? (int)($row['ID'] ?? 0) : 0;
    }

    /** @return int[] */
    private static function elementPropertyIds(int $iblockId, int $elementId, string $propertyCode): array
    {
        if ($iblockId <= 0 || $elementId <= 0) {
            return [];
        }
        $ids = [];
        $cursor = \CIBlockElement::GetProperty(
            $iblockId,
            $elementId,
            ['sort' => 'asc', 'id' => 'asc'],
            ['CODE' => $propertyCode]
        );
        while ($row = $cursor->Fetch()) {
            $id = (int)($row['VALUE'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /** @param array<int,array<string,string>> $consumers */
    private static function scanValueForConsumers(
        $value,
        string $defaultCategory,
        string $source,
        string $path,
        array &$consumers
    ): void {
        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                self::scanValueForConsumers(
                    $nested,
                    self::categoryForPath($defaultCategory, (string)$key),
                    $source,
                    $path . '.' . (string)$key,
                    $consumers
                );
            }
            return;
        }
        if (!is_scalar($value)) {
            return;
        }
        if (preg_match_all('/\bCALC_PROP_[A-Z0-9_]+\b/', (string)$value, $matches) < 1) {
            return;
        }
        foreach (array_unique($matches[0]) as $propertyCode) {
            $consumers[] = [
                'propertyCode' => $propertyCode,
                'category' => $defaultCategory,
                'source' => $source,
                'path' => $path,
                'provenance' => 'discovered',
            ];
        }
    }

    private static function categoryForPath(string $defaultCategory, string $key): string
    {
        $key = strtoupper($key);
        if (in_array($key, ['OPTIONS_OPERATION', 'OPTIONS_MATERIAL', 'OPTIONS_EQUIPMENT'], true)) {
            return 'options_mappings';
        }

        return $defaultCategory;
    }

    private function canonicalHash(array $value): string
    {
        $normalized = $this->sortRecursively($value);
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to fingerprint the Phase 5A parity contract');
        }

        return hash('sha256', $encoded);
    }

    private function sortRecursively($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
