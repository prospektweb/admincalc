<?php

namespace Prospektweb\Calc\Services;

require_once __DIR__ . '/CatalogAdapterDefinitionService.php';

/**
 * Compatibility projection for callers that still expect selection+quantity.
 *
 * The mapping/profile authority belongs exclusively to
 * CatalogAdapterDefinitionService; this class deliberately owns no business
 * rules of its own.
 */
final class StandaloneCatalogSelectionMapper
{
    public const PRESET_ID = CatalogAdapterDefinitionService::PRESET_ID;

    private CatalogAdapterDefinitionService $definitionService;

    public function __construct(?CatalogAdapterDefinitionService $definitionService = null)
    {
        $this->definitionService = $definitionService ?: new CatalogAdapterDefinitionService();
    }

    /** @return int[] */
    public static function supportedProductIds(): array
    {
        return (new CatalogAdapterDefinitionService())->supportedProductIds();
    }

    /**
     * @param array<string,mixed> $offer Target catalog offer from InitPayloadService.
     * @return array{selection:array<string,mixed>,quantity:int,productId:int,offerId:int,offerName:string}
     */
    public function map(array $offer): array
    {
        $mapped = $this->definitionService->mapOffer($offer);
        $selection = $mapped['calculationInputs'];
        unset($selection['CALC_PROP_VOLUME']);

        return [
            'selection' => $selection,
            'quantity' => (int)$mapped['quantity'],
            'productId' => (int)$mapped['productId'],
            'offerId' => (int)$mapped['offerId'],
            'offerName' => (string)$mapped['offerName'],
        ];
    }
}
