<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Read-only launch catalog and server-side launch authority for the editors
 * embedded by the control-center host.
 *
 * Phase 4A is intentionally focused on preset 12740. The browser can submit
 * only entity IDs; this service re-resolves their current Bitrix relations
 * before the host constructs an allowlisted same-origin editor URL.
 */
final class ControlCenterEditorsService
{
    public const CONTRACT = 'prospektweb.control-center.editors/v1';
    public const FOCUS_PRESET_ID = 12740;

    private const MAX_CALCULATION_OFFERS = 500;

    /** @var callable */
    private $presetLoader;

    /** @var callable */
    private $productIblockIdResolver;

    /** @var callable */
    private $frontcalcAvailabilityResolver;

    public function __construct(
        ?callable $presetLoader = null,
        ?callable $productIblockIdResolver = null,
        ?callable $frontcalcAvailabilityResolver = null
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
    }

    public function getCatalog(): array
    {
        $snapshot = $this->loadSnapshot();
        $productIblockId = $this->resolveProductIblockId();
        $frontcalcAvailable = (bool)call_user_func($this->frontcalcAvailabilityResolver);

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
