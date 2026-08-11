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
    public const FOCUS_PRESET_ID = 12740;

    private const MAX_CALCULATION_OFFERS = 500;
    private const STOREFRONT_EDITOR_PROVIDER = '\\Prospektweb\\Frontcalc\\Service\\ControlCenterStorefrontEditorService';
    private const STOREFRONT_EDITOR_METHODS = [
        'loadWorkspace',
        'validateSchema',
        'saveTemplate',
        'saveProduct',
        'enableInheritance',
        'deleteTemplate',
    ];

    /** @var callable */
    private $presetLoader;

    /** @var callable */
    private $productIblockIdResolver;

    /** @var callable */
    private $frontcalcAvailabilityResolver;

    /** @var callable */
    private $frontcalcEditorResolver;

    public function __construct(
        ?callable $presetLoader = null,
        ?callable $productIblockIdResolver = null,
        ?callable $frontcalcAvailabilityResolver = null,
        ?callable $frontcalcEditorResolver = null
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
    }

    public function getCatalog(): array
    {
        $snapshot = $this->loadSnapshot();
        $productIblockId = $this->resolveProductIblockId();
        $frontcalcAvailable = (bool)call_user_func($this->frontcalcAvailabilityResolver);
        $visualEditorAvailable = $frontcalcAvailable && $this->isStorefrontEditorAvailable();

        $storefrontProducts = [];
        foreach ($snapshot['products'] as $product) {
            $storefrontProducts[] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'presetIds' => [self::FOCUS_PRESET_ID],
                'offerCount' => $product['offerCount'],
            ];
        }

        return [
            'contract' => self::CONTRACT,
            'focusPresetId' => self::FOCUS_PRESET_ID,
            'calculations' => [[
                'presetId' => self::FOCUS_PRESET_ID,
                'presetName' => $snapshot['presetName'],
                'offerCount' => $snapshot['offerCount'],
                'products' => $snapshot['products'],
            ]],
            'storefront' => [
                'available' => $frontcalcAvailable,
                'visualEditorAvailable' => $visualEditorAvailable,
                'visualEditorContract' => self::STOREFRONT_EDITOR_CONTRACT,
                'productIblockId' => $productIblockId,
                'products' => $storefrontProducts,
            ],
        ];
    }

    /**
     * @param mixed[] $offerIds
     */
    public function validateCalculationLaunch(int $presetId, int $productId, array $offerIds): array
    {
        if ($presetId !== self::FOCUS_PRESET_ID) {
            throw new \InvalidArgumentException('Only the focus preset can be opened from this workspace');
        }
        if ($productId <= 0) {
            throw new \InvalidArgumentException('Select a product');
        }
        $requestedOfferIds = $this->normalizeRequestedOfferIds($offerIds);

        $snapshot = $this->loadSnapshot();
        $productName = '';
        $serverOfferIds = [];
        foreach ($snapshot['products'] as $product) {
            if ((int)$product['id'] !== $productId) {
                continue;
            }
            $productName = (string)$product['name'];
            foreach ($product['offers'] as $offer) {
                $serverOfferIds[] = (int)$offer['id'];
            }
            break;
        }
        if ($productName === '') {
            throw new \InvalidArgumentException(
                'Product ' . $productId . ' is not linked to preset ' . self::FOCUS_PRESET_ID
            );
        }
        if ($serverOfferIds === []) {
            throw new \InvalidArgumentException('The selected product has no active offers');
        }

        $allowedOfferIds = array_fill_keys($serverOfferIds, true);
        foreach ($requestedOfferIds as $offerId) {
            if (!isset($allowedOfferIds[$offerId])) {
                throw new \InvalidArgumentException(
                    'Offer ' . $offerId . ' is not active or does not belong to the selected product'
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

        return [
            'contract' => self::CONTRACT,
            'focusPresetId' => self::FOCUS_PRESET_ID,
            'presetName' => $snapshot['presetName'],
            'productId' => $productId,
            'productName' => $productName,
            'offerIds' => $validatedOfferIds,
        ];
    }

    public function validateStorefrontLaunch(int $productId): array
    {
        if ($productId <= 0) {
            throw new \InvalidArgumentException('Select a product');
        }
        if (!(bool)call_user_func($this->frontcalcAvailabilityResolver)) {
            throw new \RuntimeException('The storefront calculator module is not installed');
        }

        $snapshot = $this->loadSnapshot();
        $productName = '';
        foreach ($snapshot['products'] as $product) {
            if ((int)$product['id'] === $productId) {
                $productName = (string)$product['name'];
                break;
            }
        }
        if ($productName === '') {
            throw new \InvalidArgumentException(
                'Product ' . $productId . ' is not linked to preset ' . self::FOCUS_PRESET_ID
            );
        }

        return [
            'contract' => self::CONTRACT,
            'focusPresetId' => self::FOCUS_PRESET_ID,
            'productIblockId' => $this->resolveProductIblockId(),
            'productId' => $productId,
            'productName' => $productName,
        ];
    }

    public function loadStorefrontWorkspace(int $productId, string $target = 'effective', string $templateId = ''): array
    {
        $this->validateStorefrontLaunch($productId);
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
            $this->requireStorefrontEditor()->loadWorkspace($productId, $target, $templateId)
        );
    }

    public function validateStorefrontSchema(int $productId, string $target, array $schema): array
    {
        $this->validateStorefrontLaunch($productId);
        if (!in_array($target, ['product', 'template'], true)) {
            throw new \InvalidArgumentException('Unsupported storefront editor target');
        }

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->validateSchema($productId, $target, $schema)
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
        $this->validateStorefrontLaunch($productId);

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->saveTemplate(
                $productId,
                $templateId,
                $expectedRevision,
                $name,
                $sectionId,
                $schema
            )
        );
    }

    public function saveStorefrontProduct(
        int $productId,
        string $expectedRevision,
        array $schema
    ): array {
        $this->validateStorefrontLaunch($productId);

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->saveProduct($productId, $expectedRevision, $schema)
        );
    }

    public function enableStorefrontInheritance(int $productId, string $expectedRevision): array
    {
        $this->validateStorefrontLaunch($productId);

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->enableInheritance($productId, $expectedRevision)
        );
    }

    public function deleteStorefrontTemplate(int $productId, string $templateId, int $expectedRevision): array
    {
        $this->validateStorefrontLaunch($productId);

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->deleteTemplate($productId, $templateId, $expectedRevision)
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

    private function isStorefrontEditorAvailable(): bool
    {
        try {
            $this->requireStorefrontEditor();
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /** @return object */
    private function requireStorefrontEditor()
    {
        try {
            $provider = call_user_func($this->frontcalcEditorResolver);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('The native storefront editor is unavailable');
        }

        if (!is_object($provider)) {
            throw new \RuntimeException('The native storefront editor is unavailable');
        }
        foreach (self::STOREFRONT_EDITOR_METHODS as $method) {
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
