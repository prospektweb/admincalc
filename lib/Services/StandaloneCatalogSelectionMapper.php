<?php

namespace Prospektweb\Calc\Services;

/**
 * Maps a prepared catalog target to the independent preset-12740 selection.
 *
 * Catalog products and offers are output carriers. Only the two coordinates
 * that identify a carrier inside the prepared matrix (colour and circulation)
 * are read from the offer. All calculation-driving defaults and product
 * variants are owned by this explicit adapter profile.
 */
final class StandaloneCatalogSelectionMapper
{
    public const PRESET_ID = 12740;

    /** @var array<string,mixed> */
    private const BASE_SELECTION = [
        'CALC_PROP_METHOD' => 'DIGITAL',
        'CALC_PROP_TYPE_PAPER' => 'mel-paper',
        'CALC_PROP_FORMAT' => '90x50',
        'CALC_PROP_DENSITY_PAPER' => 'MAX',
        'CALC_PROP_FILLING' => 'standart',
    ];

    /**
     * These are target profiles, not calculator inputs read from products.
     * QR and variable-data groups intentionally use the current standard-print
     * logic until their own operations are authored in preset 12740.
     *
     * @var array<int,array<string,mixed>>
     */
    private const PRODUCT_PROFILES = [
        12727 => [],
        12764 => ['CALC_PROP_OPTIONS' => ['round-corners']],
        14379 => [],
        14380 => [],
        15344 => ['CALC_PROP_FORMAT' => '85x55'],
    ];

    /**
     * @param array<string,mixed> $offer Target catalog offer from InitPayloadService.
     * @return array{selection:array<string,mixed>,quantity:int,productId:int,offerId:int,offerName:string}
     */
    public function map(array $offer): array
    {
        $offerId = (int)($offer['id'] ?? 0);
        $productId = (int)($offer['productId'] ?? 0);
        if ($offerId <= 0 || $productId <= 0) {
            throw new \InvalidArgumentException('Целевое торговое предложение не связано с товаром.');
        }
        if (!array_key_exists($productId, self::PRODUCT_PROFILES)) {
            throw new \InvalidArgumentException(
                'Для товара #' . $productId . ' не настроен профиль автономной записи пресета 12740.'
            );
        }

        $properties = is_array($offer['properties'] ?? null) ? $offer['properties'] : [];
        $colour = $this->singleXmlId($properties['CALC_PROP_COLOR_SCHEME'] ?? null);
        $quantityText = $this->singleXmlId($properties['CALC_PROP_VOLUME'] ?? null);
        if ($colour === '') {
            throw new \InvalidArgumentException('У ТП #' . $offerId . ' не задана красочность для выбора целевого результата.');
        }
        if (!preg_match('/^[1-9][0-9]*$/D', $quantityText)) {
            throw new \InvalidArgumentException('У ТП #' . $offerId . ' не задан корректный тираж для выбора целевого результата.');
        }

        $selection = array_replace(self::BASE_SELECTION, self::PRODUCT_PROFILES[$productId]);
        $selection['CALC_PROP_COLOR_SCHEME'] = $colour;

        return [
            'selection' => $selection,
            'quantity' => (int)$quantityText,
            'productId' => $productId,
            'offerId' => $offerId,
            'offerName' => trim((string)($offer['name'] ?? '')),
        ];
    }

    /** @param mixed $property */
    private function singleXmlId($property): string
    {
        if (!is_array($property)) {
            return '';
        }
        $value = $property['VALUE_XML_ID'] ?? $property['valueXmlId'] ?? '';
        if (is_array($value)) {
            $value = reset($value);
        }
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
