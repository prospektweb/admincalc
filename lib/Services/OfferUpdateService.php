<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;

class OfferUpdateService
{
    private CatalogPriceService $priceService;

    public function __construct()
    {
        if (!Loader::includeModule('catalog')) {
            throw new \RuntimeException('Требуется модуль Bitrix catalog');
        }

        $this->priceService = new CatalogPriceService();
    }

    public function updateOffersFromCalculation(array $offers): array
    {
        $results = [];
        $errors = [];
        $updated = 0;

        foreach ($offers as $offer) {
            $offerId = (int)($offer['offerId'] ?? 0);
            if ($offerId <= 0) {
                $errors[] = ['offerId' => $offerId, 'message' => 'Некорректный offerId'];
                $results[] = [
                    'offerId' => $offerId,
                    'status' => 'error',
                    'message' => 'Некорректный offerId',
                ];
                continue;
            }

            try {
                $elementData = \CIBlockElement::GetByID($offerId)->Fetch();
                $offerIblockId = (int)($elementData['IBLOCK_ID'] ?? 0);
                $offerName = trim((string)($offer['offerName'] ?? ''));
                $parametrValues = $this->buildValueDescriptionList($offer['parametrValues'] ?? [], 'name', 'value');

                if ($offerIblockId > 0 && $parametrValues !== null) {
                    \CIBlockElement::SetPropertyValuesEx($offerId, $offerIblockId, [
                        'PARAMETR_VALUES' => $parametrValues ?: false,
                    ]);
                }

                if ($offerName !== '') {
                    $element = new \CIBlockElement();
                    $element->Update($offerId, ['NAME' => $offerName]);
                }

                $purchasePrice = $this->normalizeNumber($offer['purchasePrice'] ?? null);
                $currency = (string)($offer['currency'] ?? 'RUB');

                $purchasingUpdated = false;
                if ($purchasePrice !== null) {
                    $purchasingUpdated = $this->priceService->updatePurchasingPrice($offerId, $purchasePrice, $currency);
                }

                $dimensions = $this->extractDimensions($offer);
                $dimensionsUpdated = false;
                if (!empty($dimensions)) {
                    $dimensionsUpdated = $this->priceService->updateProductParams($offerId, $dimensions);
                }

                $rangesByType = $this->buildPriceRangesByType($offer);
                $pricesUpdated = false;

                if (!empty($rangesByType)) {
                    $pricesUpdated = $this->priceService->syncPriceRangesMultiType($offerId, $rangesByType);
                }

                $results[] = [
                    'offerId' => $offerId,
                    'status' => 'ok',
                    'updatedPurchasingPrice' => $purchasingUpdated,
                    'updatedDimensions' => $dimensionsUpdated,
                    'updatedPrices' => $pricesUpdated,
                ];
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = ['offerId' => $offerId, 'message' => $e->getMessage()];
                $results[] = [
                    'offerId' => $offerId,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        $status = 'ok';
        if (!empty($errors) && $updated > 0) {
            $status = 'partial';
        } elseif (!empty($errors)) {
            $status = 'error';
        }

        return [
            'status' => $status,
            'total' => count($offers),
            'updated' => $updated,
            'errors' => $errors,
            'offers' => $results,
        ];
    }

    private function buildPriceRangesByType(array $offer): array
    {
        $rangesByType = [];
        $ranges = $offer['priceRangesWithMarkup'] ?? [];

        if (!is_array($ranges)) {
            return $rangesByType;
        }

        foreach ($ranges as $range) {
            $quantityFrom = $this->normalizeQuantity($range['quantityFrom'] ?? null);
            $quantityTo = $this->normalizeQuantity($range['quantityTo'] ?? null);
            $prices = $range['prices'] ?? [];

            if (!is_array($prices)) {
                continue;
            }

            foreach ($prices as $price) {
                $typeId = (int)($price['typeId'] ?? 0);
                if ($typeId <= 0) {
                    continue;
                }

                $basePrice = $this->normalizeNumber($price['basePrice'] ?? null);
                if ($basePrice === null) {
                    continue;
                }

                $currency = (string)($price['currency'] ?? ($offer['currency'] ?? 'RUB'));

                $rangesByType[$typeId][] = [
                    'price' => $basePrice,
                    'currency' => $currency,
                    'quantityFrom' => $quantityFrom,
                    'quantityTo' => $quantityTo,
                ];
            }
        }

        return $rangesByType;
    }

    private function extractDimensions(array $offer): array
    {
        $details = $offer['details'] ?? [];
        $detail = is_array($details) && !empty($details) ? $details[0] : [];
        $outputs = $detail['outputs'] ?? [];

        $width = $this->normalizeNumber($outputs['width'] ?? ($detail['width'] ?? null));
        $length = $this->normalizeNumber($outputs['length'] ?? ($detail['length'] ?? null));
        $height = $this->normalizeNumber($outputs['height'] ?? ($detail['height'] ?? null));
        $weight = $this->normalizeNumber($outputs['weight'] ?? ($detail['weight'] ?? null));

        $dimensions = [];
        if ($width !== null) {
            $dimensions['WIDTH'] = $width;
        }
        if ($length !== null) {
            $dimensions['LENGTH'] = $length;
        }
        if ($height !== null) {
            $dimensions['HEIGHT'] = $height;
        }
        if ($weight !== null) {
            $dimensions['WEIGHT'] = $weight;
        }

        return $dimensions;
    }

    private function normalizeNumber($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float)$value;
        }

        return null;
    }

    private function normalizeQuantity($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        return null;
    }

    private function buildValueDescriptionList($items, string $valueKey, string $descriptionKey): ?array
    {
        if (!is_array($items)) {
            return null;
        }

        if (count($items) === 0) {
            return [];
        }

        return array_map(
            static fn($item) => [
                'VALUE' => $item[$valueKey] ?? '',
                'DESCRIPTION' => $item[$descriptionKey] ?? '',
            ],
            $items
        );
    }
}
