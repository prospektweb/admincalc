<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Server-side authority for editor launches and the native storefront-editor
 * adapter used by the control center.
 *
 * The workspace remains intentionally focused on preset 12740. The browser can
 * submit only entity IDs and typed editor documents; this service re-resolves
 * the current Bitrix relations before delegating storefront work to the
 * FrontCalc-owned service.
 */
final class ControlCenterEditorsService
{
    public const CONTRACT = 'prospektweb.control-center.editors/v1';
    public const STOREFRONT_EDITOR_CONTRACT = 'prospektweb.frontcalc.storefront-editor/v1';
    public const FORM_FIRST_AUTHORING_CONTRACT = 'prospektweb.frontcalc.form-first-authoring/v1';
    public const FOCUS_PRESET_ID = 12740;

    private const MAX_CALCULATION_OFFERS = 500;
    private const MAX_EDITOR_DOCUMENT_BYTES = 60000;
    private const STOREFRONT_EDITOR_PROVIDER = '\\Prospektweb\\Frontcalc\\Service\\ControlCenterStorefrontEditorService';
    private const STOREFRONT_EDITOR_METHODS = [
        'loadWorkspace',
        'validateSchema',
        'saveTemplate',
        'saveProduct',
        'enableInheritance',
        'deleteTemplate',
    ];
    private const FORM_FIRST_AUTHORING_METHODS = [
        'loadFormFirstWorkspace',
        'saveFormFirstDraft',
        'previewFormFirst',
        'publishFormFirst',
        'rollbackFormFirst',
    ];

    /** @var callable */
    private $presetLoader;

    /** @var callable */
    private $productIblockIdResolver;

    /** @var callable */
    private $frontcalcAvailabilityResolver;

    /** @var callable */
    private $frontcalcEditorResolver;

    /** @var callable */
    private $dependencyContractResolver;

    public function __construct(
        ?callable $presetLoader = null,
        ?callable $productIblockIdResolver = null,
        ?callable $frontcalcAvailabilityResolver = null,
        ?callable $frontcalcEditorResolver = null,
        ?callable $dependencyContractResolver = null
    ) {
        $this->presetLoader = $presetLoader ?? static function (int $presetId): array {
            if (!Loader::includeModule('iblock')) {
                throw new \RuntimeException('The iblock module is not available');
            }

            return (new CatalogTreeService())->presetLoadOptions(['presetId' => $presetId]);
        };
        $this->productIblockIdResolver = $productIblockIdResolver ?? static function (): int {
            return (int)(new ConfigManager())->getProductIblockId();
        };
        $this->frontcalcAvailabilityResolver = $frontcalcAvailabilityResolver ?? static function (): bool {
            return ModuleManager::isModuleInstalled('prospektweb.frontcalc');
        };
        $this->frontcalcEditorResolver = $frontcalcEditorResolver ?? static function () {
            if (!Loader::includeModule('prospektweb.frontcalc')) {
                return null;
            }

            $providerClass = self::STOREFRONT_EDITOR_PROVIDER;
            return class_exists($providerClass) ? new $providerClass() : null;
        };
        $this->dependencyContractResolver = $dependencyContractResolver
            ?? static function (int $presetId, array $allowedProductIds): array {
                return (new Phase5aParityContractService())->buildPublicInputContract(
                    $presetId,
                    $allowedProductIds
                );
            };
    }

    public function getCatalog(): array
    {
        $snapshot = $this->loadSnapshot();
        $productIblockId = $this->resolveProductIblockId();
        $frontcalcAvailable = (bool)call_user_func($this->frontcalcAvailabilityResolver);
        $visualEditorAvailable = $frontcalcAvailable && $this->isStorefrontEditorAvailable();
        $formFirstAuthoringAvailable = $frontcalcAvailable && $this->isFormFirstAuthoringAvailable();

        $storefrontProducts = [];
        foreach ($snapshot['products'] as $product) {
            $storefrontProducts[] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'presetIds' => [self::FOCUS_PRESET_ID],
                'offerCount' => $product['offerCount'],
            ];
        }

        $supportedCalculationProductIds = StandaloneCatalogSelectionMapper::supportedProductIds();
        $calculationProducts = array_values(array_filter(
            $snapshot['products'],
            static function (array $product) use ($supportedCalculationProductIds): bool {
                return in_array((int)$product['id'], $supportedCalculationProductIds, true);
            }
        ));
        $calculationOfferCount = array_sum(array_map(static function (array $product): int {
            return (int)$product['offerCount'];
        }, $calculationProducts));

        return [
            'contract' => self::CONTRACT,
            'focusPresetId' => self::FOCUS_PRESET_ID,
            'calculations' => [[
                'presetId' => self::FOCUS_PRESET_ID,
                'presetName' => $snapshot['presetName'],
                'offerCount' => $calculationOfferCount,
                'products' => $calculationProducts,
            ]],
            'storefront' => [
                'available' => $frontcalcAvailable,
                'visualEditorAvailable' => $visualEditorAvailable,
                'visualEditorContract' => self::STOREFRONT_EDITOR_CONTRACT,
                'formFirstAuthoringAvailable' => $formFirstAuthoringAvailable,
                'formFirstAuthoringContract' => self::FORM_FIRST_AUTHORING_CONTRACT,
                'formFirstPilotProductIds' => [4267],
                'productIblockId' => $productIblockId,
                'products' => $storefrontProducts,
            ],
        ];
    }

    /**
     * @param mixed[] $offerIds
     */
    public function validateCalculationLaunch(int $presetId, array $offerIds): array
    {
        if ($presetId !== self::FOCUS_PRESET_ID) {
            throw new \InvalidArgumentException('Only the focus preset can be opened from this workspace');
        }
        $requestedOfferIds = $this->normalizeRequestedOfferIds($offerIds);

        $snapshot = $this->loadSnapshot();
        $supportedProductIds = array_fill_keys(
            StandaloneCatalogSelectionMapper::supportedProductIds(),
            true
        );
        $serverOfferIds = [];
        $offerProductIds = [];
        foreach ($snapshot['products'] as $product) {
            $productId = (int)($product['id'] ?? 0);
            if ($productId <= 0
                || !isset($supportedProductIds[$productId])
                || !is_array($product['offers'] ?? null)) {
                continue;
            }
            foreach ($product['offers'] as $offer) {
                $offerId = (int)($offer['id'] ?? 0);
                if ($offerId <= 0 || isset($offerProductIds[$offerId])) {
                    throw new \RuntimeException('The authoritative catalog contains an invalid or duplicate offer');
                }
                $serverOfferIds[] = $offerId;
                $offerProductIds[$offerId] = $productId;
            }
        }
        if ($serverOfferIds === []) {
            throw new \InvalidArgumentException('Preset 12740 has no active catalog offers');
        }

        foreach ($requestedOfferIds as $offerId) {
            if (!isset($offerProductIds[$offerId])) {
                throw new \InvalidArgumentException(
                    'Offer ' . $offerId . ' is not active or does not belong to preset 12740 catalog scope'
                );
            }
        }

        // The selection is reconstructed in the authoritative server order;
        // the browser array is never copied into the editor URL directly.
        $requestedOfferMap = array_fill_keys($requestedOfferIds, true);
        $validatedOfferIds = array_values(array_filter(
            $serverOfferIds,
            static function (int $offerId) use ($requestedOfferMap): bool {
                return isset($requestedOfferMap[$offerId]);
            }
        ));
        $serverProductIds = [];
        foreach ($validatedOfferIds as $offerId) {
            $serverProductIds[$offerProductIds[$offerId]] = true;
        }

        return [
            'contract' => self::CONTRACT,
            'focusPresetId' => self::FOCUS_PRESET_ID,
            'presetName' => $snapshot['presetName'],
            'productIds' => array_map('intval', array_keys($serverProductIds)),
            'offerIds' => $validatedOfferIds,
        ];
    }

    public function validatePresetLaunch(int $presetId): array
    {
        if ($presetId !== self::FOCUS_PRESET_ID) {
            throw new \InvalidArgumentException('Only the focus preset can be opened from this workspace');
        }

        $snapshot = $this->loadSnapshot();

        return [
            'contract' => self::CONTRACT,
            'focusPresetId' => self::FOCUS_PRESET_ID,
            'presetName' => $snapshot['presetName'],
        ];
    }

    public function validateStorefrontLaunch(int $productId): array
    {
        $authority = $this->resolveStorefrontAuthority($productId);

        return [
            'contract' => self::CONTRACT,
            'focusPresetId' => self::FOCUS_PRESET_ID,
            'productIblockId' => $this->resolveProductIblockId(),
            'productId' => $productId,
            'productName' => $authority['productName'],
        ];
    }

    public function loadStorefrontWorkspace(int $productId, string $target = 'effective', string $templateId = ''): array
    {
        $authority = $this->resolveStorefrontAuthority($productId);
        if (!in_array($target, ['effective', 'product', 'template'], true)) {
            throw new \InvalidArgumentException('Unsupported storefront editor target');
        }
        if ($target === 'template' && $templateId === '') {
            throw new \InvalidArgumentException('templateId is required for the template target');
        }
        if ($target !== 'template' && $templateId !== '') {
            throw new \InvalidArgumentException('templateId is allowed only for the template target');
        }

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->loadWorkspace(
                $productId,
                $target,
                $templateId,
                $authority['allowedProductIds']
            )
        );
    }

    public function validateStorefrontSchema(int $productId, string $target, array $schema): array
    {
        $authority = $this->resolveStorefrontAuthority($productId);
        if (!in_array($target, ['product', 'template'], true)) {
            throw new \InvalidArgumentException('Unsupported storefront editor target');
        }
        $this->assertEditorDocument($schema, 'schema');

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->validateSchema(
                $productId,
                $target,
                $schema,
                $authority['allowedProductIds']
            )
        );
    }

    public function saveStorefrontTemplate(
        int $productId,
        string $templateId,
        int $expectedRevision,
        string $name,
        int $sectionId,
        array $schema
    ): array {
        $authority = $this->resolveStorefrontAuthority($productId);
        $this->assertEditorDocument($schema, 'schema');

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->saveTemplate(
                $productId,
                $templateId,
                $expectedRevision,
                $name,
                $sectionId,
                $schema,
                $authority['allowedProductIds']
            )
        );
    }

    public function saveStorefrontProduct(
        int $productId,
        string $expectedRevision,
        array $schema
    ): array {
        $authority = $this->resolveStorefrontAuthority($productId);
        $this->assertEditorDocument($schema, 'schema');

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->saveProduct(
                $productId,
                $expectedRevision,
                $schema,
                $authority['allowedProductIds']
            )
        );
    }

    public function enableStorefrontInheritance(int $productId, string $expectedRevision): array
    {
        $authority = $this->resolveStorefrontAuthority($productId);

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->enableInheritance(
                $productId,
                $expectedRevision,
                $authority['allowedProductIds']
            )
        );
    }

    public function deleteStorefrontTemplate(int $productId, string $templateId, int $expectedRevision): array
    {
        $authority = $this->resolveStorefrontAuthority($productId);

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->deleteTemplate(
                $productId,
                $templateId,
                $expectedRevision,
                $authority['allowedProductIds']
            )
        );
    }

    public function loadFormFirstWorkspace(int $productId, int $presetId): array
    {
        $this->assertFormFirstPilot($productId, $presetId);
        $authority = $this->resolveStorefrontAuthority($productId);
        $dependencyContract = $this->resolveDependencyContract($presetId, $authority['allowedProductIds']);

        return $this->assertFormFirstEditorResult(
            $this->requireFormFirstAuthoring()->loadFormFirstWorkspace(
                $productId,
                $presetId,
                $authority['allowedProductIds'],
                $dependencyContract
            ),
            $productId,
            $presetId,
            'load',
            $dependencyContract['fingerprint']
        );
    }

    public function saveFormFirstDraft(
        int $productId,
        int $presetId,
        string $expectedAggregateRevision,
        array $formDefinition,
        array $bindingDefinition
    ): array {
        $this->assertFormFirstPilot($productId, $presetId);
        $this->assertSha256($expectedAggregateRevision, 'expectedAggregateRevision');
        $this->assertEditorDocument($formDefinition, 'formDefinition');
        $this->assertEditorDocument($bindingDefinition, 'bindingDefinition');
        $authority = $this->resolveStorefrontAuthority($productId);
        $dependencyContract = $this->resolveDependencyContract($presetId, $authority['allowedProductIds']);

        return $this->assertFormFirstEditorResult(
            $this->requireFormFirstAuthoring()->saveFormFirstDraft(
                $productId,
                $presetId,
                $expectedAggregateRevision,
                $formDefinition,
                $bindingDefinition,
                $authority['allowedProductIds'],
                $dependencyContract
            ),
            $productId,
            $presetId,
            'save_draft',
            $dependencyContract['fingerprint']
        );
    }

    public function previewFormFirst(
        int $productId,
        int $presetId,
        array $formDefinition,
        array $bindingDefinition
    ): array {
        $this->assertFormFirstPilot($productId, $presetId);
        $this->assertEditorDocument($formDefinition, 'formDefinition');
        $this->assertEditorDocument($bindingDefinition, 'bindingDefinition');
        $authority = $this->resolveStorefrontAuthority($productId);
        $dependencyContract = $this->resolveDependencyContract($presetId, $authority['allowedProductIds']);

        return $this->assertFormFirstEditorResult(
            $this->requireFormFirstAuthoring()->previewFormFirst(
                $productId,
                $presetId,
                $formDefinition,
                $bindingDefinition,
                $authority['allowedProductIds'],
                $dependencyContract
            ),
            $productId,
            $presetId,
            'preview',
            $dependencyContract['fingerprint']
        );
    }

    public function publishFormFirst(
        int $productId,
        int $presetId,
        string $expectedAggregateRevision,
        string $expectedCompileHash
    ): array {
        $this->assertFormFirstPilot($productId, $presetId);
        $this->assertSha256($expectedAggregateRevision, 'expectedAggregateRevision');
        $this->assertSha256($expectedCompileHash, 'expectedCompileHash');
        $authority = $this->resolveStorefrontAuthority($productId);
        $dependencyContract = $this->resolveDependencyContract($presetId, $authority['allowedProductIds']);

        return $this->assertFormFirstEditorResult(
            $this->requireFormFirstAuthoring()->publishFormFirst(
                $productId,
                $presetId,
                $expectedAggregateRevision,
                $expectedCompileHash,
                $authority['allowedProductIds'],
                $dependencyContract
            ),
            $productId,
            $presetId,
            'publish',
            $dependencyContract['fingerprint']
        );
    }

    public function rollbackFormFirst(
        int $productId,
        int $presetId,
        string $expectedAggregateRevision,
        int $targetPublishedRevision
    ): array {
        $this->assertFormFirstPilot($productId, $presetId);
        $this->assertSha256($expectedAggregateRevision, 'expectedAggregateRevision');
        if ($targetPublishedRevision < 0) {
            throw new \InvalidArgumentException('targetPublishedRevision must be a non-negative integer');
        }
        $authority = $this->resolveStorefrontAuthority($productId);
        $dependencyContract = $this->resolveDependencyContract($presetId, $authority['allowedProductIds']);

        return $this->assertFormFirstEditorResult(
            $this->requireFormFirstAuthoring()->rollbackFormFirst(
                $productId,
                $presetId,
                $expectedAggregateRevision,
                $targetPublishedRevision,
                $authority['allowedProductIds'],
                $dependencyContract
            ),
            $productId,
            $presetId,
            'rollback',
            $dependencyContract['fingerprint']
        );
    }

    /**
     * @return array{presetName:string,offerCount:int,products:array<int,array{id:int,name:string,offerCount:int,offers:array<int,array{id:int,name:string}>}>}
     */
    private function loadSnapshot(): array
    {
        $raw = call_user_func($this->presetLoader, self::FOCUS_PRESET_ID);
        if (!is_array($raw) || (string)($raw['status'] ?? '') !== 'ok') {
            throw new \RuntimeException('Unable to load the focus preset');
        }
        if ((int)($raw['preset']['id'] ?? 0) !== self::FOCUS_PRESET_ID) {
            throw new \RuntimeException('The preset loader returned an unexpected preset');
        }

        $products = [];
        $offerCount = 0;
        foreach ((array)($raw['products'] ?? []) as $rawProduct) {
            if (!is_array($rawProduct)) {
                continue;
            }
            $productId = (int)($rawProduct['id'] ?? 0);
            $productName = trim((string)($rawProduct['name'] ?? ''));
            if ($productId <= 0 || $productName === '') {
                continue;
            }

            $offers = [];
            $seenOfferIds = [];
            foreach ((array)($rawProduct['offers'] ?? []) as $rawOffer) {
                if (!is_array($rawOffer)) {
                    continue;
                }
                $offerId = (int)($rawOffer['id'] ?? 0);
                if ($offerId <= 0 || isset($seenOfferIds[$offerId])) {
                    continue;
                }
                $seenOfferIds[$offerId] = true;
                $offers[] = [
                    'id' => $offerId,
                    'name' => trim((string)($rawOffer['name'] ?? '')) ?: 'ТП #' . $offerId,
                ];
            }

            $offerCount += count($offers);
            $products[] = [
                'id' => $productId,
                'name' => $productName,
                'offerCount' => count($offers),
                'offers' => $offers,
            ];
        }

        return [
            'presetName' => trim((string)($raw['preset']['name'] ?? '')) ?: 'Пресет #' . self::FOCUS_PRESET_ID,
            'offerCount' => $offerCount,
            'products' => $products,
        ];
    }

    private function resolveProductIblockId(): int
    {
        $productIblockId = (int)call_user_func($this->productIblockIdResolver);
        if ($productIblockId <= 0) {
            throw new \RuntimeException('The product iblock is not configured');
        }

        return $productIblockId;
    }

    /**
     * Resolve the current active product scope immediately before every
     * provider call. The allowlist is deliberately not cached between actions:
     * a product disabled or unlinked after a browser catalog load must fail
     * closed on the following read, validation or mutation.
     *
     * @return array{productName:string,allowedProductIds:int[]}
     */
    private function resolveStorefrontAuthority(int $productId): array
    {
        if ($productId <= 0) {
            throw new \InvalidArgumentException('Select a product');
        }
        if (!(bool)call_user_func($this->frontcalcAvailabilityResolver)) {
            throw new \RuntimeException('The storefront calculator module is not installed');
        }

        $snapshot = $this->loadSnapshot();
        $allowedProductIds = [];
        $productName = '';
        foreach ($snapshot['products'] as $product) {
            $allowedProductId = (int)$product['id'];
            $allowedProductIds[] = $allowedProductId;
            if ($allowedProductId === $productId) {
                $productName = (string)$product['name'];
            }
        }
        if ($productName === '') {
            throw new \InvalidArgumentException(
                'Product ' . $productId . ' is not linked to preset ' . self::FOCUS_PRESET_ID
            );
        }

        return [
            'productName' => $productName,
            'allowedProductIds' => $allowedProductIds,
        ];
    }

    private function assertFormFirstPilot(int $productId, int $presetId): void
    {
        if ($presetId !== self::FOCUS_PRESET_ID || $productId !== 4267) {
            throw new \InvalidArgumentException(
                'Form-first Phase 5A is limited to product 4267 / preset 12740'
            );
        }
    }

    private function assertSha256(string $value, string $field): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field . ' must be a lowercase SHA-256 revision');
        }
    }

    /** @param int[] $allowedProductIds @return array<string,mixed> */
    private function resolveDependencyContract(int $presetId, array $allowedProductIds): array
    {
        try {
            $contract = call_user_func(
                $this->dependencyContractResolver,
                $presetId,
                $allowedProductIds
            );
        } catch (\Throwable $exception) {
            throw new \RuntimeException('The current form-first dependency authority is unavailable');
        }
        if (!is_array($contract)
            || (string)($contract['contract'] ?? '') !== 'prospektweb.calc.preset-public-inputs/v1'
            || !is_int($contract['presetId'] ?? null)
            || (int)$contract['presetId'] !== $presetId
            || !is_array($contract['requiredPropertyCodes'] ?? null)
            || !is_array($contract['consumers'] ?? null)
            || !is_array($contract['categoryStatus'] ?? null)
            || !is_string($contract['fingerprint'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', (string)$contract['fingerprint']) !== 1) {
            throw new \RuntimeException('The current form-first dependency authority is invalid');
        }
        $requiredCategories = [
            'ui',
            'passive_context',
            'stage_inputs',
            'globals',
            'options_mappings',
            'routes',
            'basket',
            'seo_display',
        ];
        foreach ($requiredCategories as $category) {
            $status = $contract['categoryStatus'][$category] ?? null;
            if (!is_array($status)
                || ($status['scanned'] ?? false) !== true
                || !is_int($status['count'] ?? null)
                || (int)$status['count'] < 0
                || !in_array((string)($status['sourceMode'] ?? ''), ['discovered', 'declared'], true)) {
                throw new \RuntimeException('The current form-first dependency authority is incomplete');
            }
        }

        if (array_keys($contract['categoryStatus']) !== $requiredCategories) {
            throw new \RuntimeException('The current form-first dependency authority has unexpected categories');
        }
        $normalizedRequiredCodes = [];
        foreach ($contract['requiredPropertyCodes'] as $propertyCode) {
            if (!is_string($propertyCode)
                || preg_match('/^CALC_PROP_[A-Z0-9_]+$/D', $propertyCode) !== 1) {
                throw new \RuntimeException('The current form-first dependency authority has invalid required codes');
            }
            $normalizedRequiredCodes[$propertyCode] = true;
        }
        $normalizedRequiredCodes = array_keys($normalizedRequiredCodes);
        sort($normalizedRequiredCodes, SORT_STRING);
        if ($contract['requiredPropertyCodes'] !== $normalizedRequiredCodes) {
            throw new \RuntimeException('The current form-first dependency authority required codes are not canonical');
        }
        $consumerCounts = array_fill_keys($requiredCategories, 0);
        $previousConsumerKey = null;
        foreach ($contract['consumers'] as $consumer) {
            if (!is_array($consumer)
                || !is_string($consumer['propertyCode'] ?? null)
                || preg_match('/^CALC_PROP_[A-Z0-9_]+$/D', (string)$consumer['propertyCode']) !== 1
                || !in_array((string)($consumer['category'] ?? ''), $requiredCategories, true)
                || trim((string)($consumer['source'] ?? '')) === ''
                || trim((string)($consumer['path'] ?? '')) === ''
                || !in_array((string)($consumer['provenance'] ?? ''), ['discovered', 'declared'], true)) {
                throw new \RuntimeException('The current form-first dependency authority has an invalid consumer');
            }
            $consumerKey = implode('|', [
                $consumer['propertyCode'],
                $consumer['category'],
                $consumer['source'],
                $consumer['path'],
                $consumer['provenance'],
            ]);
            if ($previousConsumerKey !== null && strcmp($previousConsumerKey, $consumerKey) >= 0) {
                throw new \RuntimeException('The current form-first dependency authority consumers are not canonical');
            }
            $previousConsumerKey = $consumerKey;
            $consumerCounts[$consumer['category']]++;
        }
        foreach ($requiredCategories as $category) {
            if ($contract['categoryStatus'][$category]['count'] !== $consumerCounts[$category]) {
                throw new \RuntimeException('The current form-first dependency authority counts are inconsistent');
            }
        }

        $canonical = $contract;
        unset($canonical['fingerprint']);
        if (!hash_equals((string)$contract['fingerprint'], $this->canonicalHash($canonical))) {
            throw new \RuntimeException('The current form-first dependency authority fingerprint is invalid');
        }

        return $contract;
    }

    private function canonicalHash(array $value): string
    {
        $encoded = json_encode(
            $this->sortRecursively($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to fingerprint the form-first dependency authority');
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

    private function assertEditorDocument(array $document, string $field): void
    {
        if ($document === [] || array_keys($document) === range(0, count($document) - 1)) {
            throw new \InvalidArgumentException($field . ' must be a non-empty object');
        }
        $encoded = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \InvalidArgumentException($field . ' must be valid JSON data');
        }
        if (strlen($encoded) > self::MAX_EDITOR_DOCUMENT_BYTES) {
            throw new \InvalidArgumentException(
                $field . ' must not exceed ' . self::MAX_EDITOR_DOCUMENT_BYTES . ' bytes'
            );
        }
    }

    private function isStorefrontEditorAvailable(): bool
    {
        try {
            $this->requireProviderMethods(self::STOREFRONT_EDITOR_METHODS);
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /** @return object */
    private function requireStorefrontEditor()
    {
        return $this->requireProviderMethods(self::STOREFRONT_EDITOR_METHODS);
    }

    /** @return object */
    private function requireFormFirstAuthoring()
    {
        return $this->requireProviderMethods(array_merge(
            self::STOREFRONT_EDITOR_METHODS,
            self::FORM_FIRST_AUTHORING_METHODS
        ));
    }

    private function isFormFirstAuthoringAvailable(): bool
    {
        try {
            $this->requireFormFirstAuthoring();
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /** @param string[] $methods @return object */
    private function requireProviderMethods(array $methods)
    {
        try {
            $provider = call_user_func($this->frontcalcEditorResolver);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('The native storefront editor is unavailable');
        }

        if (!is_object($provider)) {
            throw new \RuntimeException('The native storefront editor is unavailable');
        }
        foreach ($methods as $method) {
            if (!is_callable([$provider, $method])) {
                throw new \RuntimeException('The native storefront editor is unavailable');
            }
        }

        return $provider;
    }

    private function assertStorefrontEditorResult($result): array
    {
        if (!is_array($result)
            || (string)($result['contract'] ?? '') !== self::STOREFRONT_EDITOR_CONTRACT) {
            throw new \RuntimeException('The native storefront editor returned an incompatible response');
        }

        return $result;
    }

    private function assertFormFirstEditorResult(
        $result,
        int $expectedProductId,
        int $expectedPresetId,
        string $expectedOperation,
        string $expectedDependencyFingerprint
    ): array
    {
        if (!is_array($result)
            || (string)($result['contract'] ?? '') !== self::FORM_FIRST_AUTHORING_CONTRACT) {
            throw new \RuntimeException('The form-first editor returned an incompatible response');
        }
        $product = $result['product'] ?? null;
        if (!is_array($product)
            || !is_int($product['id'] ?? null)
            || (int)$product['id'] !== $expectedProductId
            || !is_int($result['presetId'] ?? null)
            || (int)$result['presetId'] !== $expectedPresetId
            || !is_string($result['operation'] ?? null)
            || (string)$result['operation'] !== $expectedOperation
            || !is_string($result['dependencyFingerprint'] ?? null)
            || !hash_equals($expectedDependencyFingerprint, (string)$result['dependencyFingerprint'])
            || !is_string($result['aggregateRevision'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', (string)$result['aggregateRevision']) !== 1
            || !is_array($result['formDefinition'] ?? null)
            || (string)($result['formDefinition']['contract'] ?? '')
                !== 'prospektweb.frontcalc.form-definition/v1'
            || !is_array($result['bindingDefinition'] ?? null)
            || (string)($result['bindingDefinition']['contract'] ?? '')
                !== 'prospektweb.frontcalc.binding-definition/v1'
            || !is_array($result['history'] ?? null)
            || !is_array($result['compile'] ?? null)
            || !is_bool($result['compile']['valid'] ?? null)
            || !is_string($result['compile']['hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', (string)$result['compile']['hash']) !== 1) {
            throw new \RuntimeException('The form-first editor returned invalid revision or document types');
        }
        foreach ($result['history'] as $history) {
            if (!is_array($history)
                || !is_int($history['revision'] ?? null)
                || (int)$history['revision'] < 0
                || !is_int($history['formRevision'] ?? null)
                || (int)$history['formRevision'] < 0
                || !is_int($history['bindingRevision'] ?? null)
                || (int)$history['bindingRevision'] < 0
                || !is_string($history['compileHash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', (string)$history['compileHash']) !== 1) {
                throw new \RuntimeException('The form-first editor returned invalid history revision types');
            }
        }

        return $result;
    }

    /**
     * @param mixed[] $offerIds
     * @return int[]
     */
    private function normalizeRequestedOfferIds(array $offerIds): array
    {
        if ($offerIds === []) {
            throw new \InvalidArgumentException('Select at least one offer');
        }
        if (count($offerIds) > self::MAX_CALCULATION_OFFERS) {
            throw new \InvalidArgumentException('Too many offers selected for one editor session');
        }

        $normalized = [];
        foreach ($offerIds as $offerId) {
            if (!is_int($offerId) || $offerId <= 0 || $offerId > 9007199254740991) {
                throw new \InvalidArgumentException('Offer IDs must be safe positive integers');
            }
            if (isset($normalized[$offerId])) {
                throw new \InvalidArgumentException('Offer IDs must not contain duplicates');
            }
            $normalized[$offerId] = $offerId;
        }

        return array_values($normalized);
    }

}
