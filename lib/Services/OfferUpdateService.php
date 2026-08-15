<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;
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

    /**
     * @param array<int,array<string,mixed>> $offers
     * @param bool $requireCompleteCatalogValues Fail closed unless every catalog sink is present and positive.
     * @param bool $manageTransactions When false, the caller owns one surrounding all-or-nothing transaction.
     */
    public function updateOffersFromCalculation(
        array $offers,
        bool $requireCompleteCatalogValues = false,
        bool $manageTransactions = true
    ): array
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

            $transactionStarted = false;
            $connection = null;

            try {
                if ($requireCompleteCatalogValues) {
                    $preview = $this->inspectOfferCalculation($offer);
                    if (!$preview['valid']) {
                        throw new \RuntimeException(implode('; ', $preview['errors']));
                    }
                }

                $elementData = \CIBlockElement::GetByID($offerId)->Fetch();
                $offerIblockId = (int)($elementData['IBLOCK_ID'] ?? 0);
                $offerName = trim((string)($offer['offerName'] ?? ''));
                $parametrValues = $this->buildValueDescriptionList(
                    array_values(array_filter(
                        is_array($offer['parametrValues'] ?? null) ? $offer['parametrValues'] : [],
                        static fn($entry): bool => is_array($entry) && ($entry['writeToOffer'] ?? true) !== false
                    )),
                    'name',
                    'value'
                );

                if ($offerIblockId <= 0) {
                    throw new \RuntimeException('Торговое предложение не найдено');
                }

                if ($manageTransactions) {
                    $connection = Application::getConnection();
                    $connection->startTransaction();
                    $transactionStarted = true;
                }

                if ($parametrValues !== null) {
                    \CIBlockElement::SetPropertyValuesEx($offerId, $offerIblockId, [
                        'PARAMETR_VALUES' => $parametrValues ?: false,
                    ]);
                }

                $nameUpdated = true;
                if ($offerName !== '') {
                    $element = new \CIBlockElement();
                    $nameUpdated = (bool)$element->Update($offerId, ['NAME' => $offerName]);
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
                    if ($this->isSimpleSinglePricePayload($rangesByType)) {
                        $simplePrice = $this->extractSimpleSinglePrice($rangesByType);
                        if ($simplePrice !== null) {
                            $pricesUpdated = $this->priceService->writePrice(
                                $offerId,
                                $simplePrice['typeId'],
                                $simplePrice['price'],
                                $simplePrice['currency']
                            );
                        }
                    } else {
                        $pricesUpdated = $this->priceService->syncPriceRangesMultiType($offerId, $rangesByType);
                    }
                }

                $writeErrors = [];
                if (!$nameUpdated) {
                    $writeErrors[] = 'название';
                }
                if ($purchasePrice !== null && !$purchasingUpdated) {
                    $writeErrors[] = 'закупочная цена';
                }
                if (!empty($dimensions) && !$dimensionsUpdated) {
                    $writeErrors[] = 'габариты';
                }
                if (!empty($rangesByType) && !$pricesUpdated) {
                    $writeErrors[] = 'диапазоны цен';
                }

                if (!empty($writeErrors)) {
                    throw new \RuntimeException('Не сохранены: ' . implode(', ', $writeErrors));
                }

                if ($manageTransactions && $connection !== null) {
                    $connection->commitTransaction();
                    $transactionStarted = false;
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
                if ($transactionStarted && $connection !== null) {
                    try {
                        $connection->rollbackTransaction();
                    } catch (\Throwable $rollbackError) {
                        error_log('Offer #' . $offerId . ' rollback failed: ' . $rollbackError->getMessage());
                    }
                }
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

    /**
     * Проверить расчётные результаты без изменения каталога.
     *
     * @param array<int, array<string, mixed>> $offers
     * @param int[] $expectedOfferIds
     */
    public function previewOffersFromCalculation(array $offers, array $expectedOfferIds = []): array
    {
        $expectedOfferIds = array_values(array_unique(array_filter(array_map('intval', $expectedOfferIds), static function (int $offerId): bool {
            return $offerId > 0;
        })));

        $previewOffers = [];
        $seenOfferIds = [];
        $errors = [];

        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                $errors[] = ['offerId' => 0, 'message' => 'calc-server вернул некорректный результат ТП'];
                continue;
            }

            $preview = $this->inspectOfferCalculation($offer);
            $offerId = (int)$preview['offerId'];
            if ($offerId > 0) {
                if (!empty($expectedOfferIds) && !in_array($offerId, $expectedOfferIds, true)) {
                    $preview['valid'] = false;
                    $preview['errors'][] = 'calc-server вернул результат незапрошенного ТП';
                }
                if (isset($seenOfferIds[$offerId])) {
                    $preview['valid'] = false;
                    $preview['errors'][] = 'calc-server вернул дублирующий результат ТП';
                }
                $seenOfferIds[$offerId] = true;
            }

            if (!$preview['valid']) {
                foreach ($preview['errors'] as $message) {
                    $errors[] = ['offerId' => $offerId, 'message' => $message];
                }
            }

            $previewOffers[] = $preview;
        }

        foreach ($expectedOfferIds as $expectedOfferId) {
            if (!isset($seenOfferIds[$expectedOfferId])) {
                $message = 'В ответе calc-server отсутствует результат ТП';
                $errors[] = ['offerId' => $expectedOfferId, 'message' => $message];
                $previewOffers[] = [
                    'offerId' => $expectedOfferId,
                    'offerName' => '',
                    'valid' => false,
                    'purchasePrice' => null,
                    'currency' => 'RUB',
                    'dimensions' => [],
                    'prices' => [],
                    'errors' => [$message],
                ];
            }
        }

        usort($previewOffers, static function (array $left, array $right): int {
            return ((int)$left['offerId']) <=> ((int)$right['offerId']);
        });

        $valid = count(array_filter($previewOffers, static function (array $offer): bool {
            return !empty($offer['valid']);
        }));

        return [
            'ready' => count($previewOffers) > 0 && $valid === count($previewOffers) && empty($errors),
            'summary' => [
                'total' => count($previewOffers),
                'valid' => $valid,
                'invalid' => count($previewOffers) - $valid,
            ],
            'offers' => $previewOffers,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectOfferCalculation(array $offer): array
    {
        $offerId = (int)($offer['offerId'] ?? 0);
        $purchasePrice = $this->normalizeNumber($offer['purchasePrice'] ?? null);
        $dimensions = $this->extractDimensions($offer);
        $rangesByType = $this->buildPriceRangesByType($offer);
        $prices = [];

        foreach ($rangesByType as $typeId => $ranges) {
            foreach ($ranges as $range) {
                $prices[] = [
                    'typeId' => (int)$typeId,
                    'basePrice' => (float)$range['price'],
                    'currency' => (string)$range['currency'],
                    'quantityFrom' => $range['quantityFrom'],
                    'quantityTo' => $range['quantityTo'],
                ];
            }
        }

        $errors = [];
        if ($offerId <= 0) {
            $errors[] = 'Некорректный offerId';
        }
        if ($purchasePrice === null || !is_finite($purchasePrice) || $purchasePrice <= 0) {
            $errors[] = 'Закупочная цена должна быть положительным конечным числом';
        }
        if (empty($prices)) {
            $errors[] = 'Не рассчитана ни одна положительная цена каталога';
        }
        foreach ($prices as $price) {
            if (!is_finite((float)$price['basePrice']) || (float)$price['basePrice'] <= 0) {
                $errors[] = 'Цена каталога должна быть положительным конечным числом';
                break;
            }
        }

        foreach (['WIDTH' => 'ширина', 'LENGTH' => 'длина', 'HEIGHT' => 'высота', 'WEIGHT' => 'вес'] as $field => $label) {
            $value = $dimensions[$field] ?? null;
            if ($value === null || !is_finite((float)$value) || (float)$value <= 0) {
                $errors[] = 'Не рассчитан положительный параметр: ' . $label;
            }
        }

        return [
            'offerId' => $offerId,
            'offerName' => trim((string)($offer['offerName'] ?? '')),
            'valid' => empty($errors),
            'purchasePrice' => $purchasePrice,
            'currency' => (string)($offer['currency'] ?? 'RUB'),
            'dimensions' => [
                'width' => $dimensions['WIDTH'] ?? null,
                'length' => $dimensions['LENGTH'] ?? null,
                'height' => $dimensions['HEIGHT'] ?? null,
                'weight' => $dimensions['WEIGHT'] ?? null,
            ],
            'prices' => $prices,
            'errors' => $errors,
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

    private function isSimpleSinglePricePayload(array $rangesByType): bool
    {
        if (count($rangesByType) !== 1) {
            return false;
        }

        $typeId = (int)array_key_first($rangesByType);
        $ranges = $rangesByType[$typeId] ?? [];

        if ($typeId <= 0 || !is_array($ranges) || count($ranges) !== 1) {
            return false;
        }

        $range = $ranges[0] ?? [];
        if (!is_array($range)) {
            return false;
        }

        $quantityFrom = $range['quantityFrom'] ?? null;
        $quantityTo = $range['quantityTo'] ?? null;

        // Fast-path: единичная «базовая» цена без реальных диапазонов.
        // quantityFrom=0 в payload трактуем как отсутствие нижней границы.
        $isOpenStart = $quantityFrom === null || (int)$quantityFrom === 0;
        $isOpenEnd = $quantityTo === null;

        return $isOpenStart && $isOpenEnd;
    }

    private function extractSimpleSinglePrice(array $rangesByType): ?array
    {
        if (count($rangesByType) !== 1) {
            return null;
        }

        $typeId = (int)array_key_first($rangesByType);
        $ranges = $rangesByType[$typeId] ?? [];
        $range = is_array($ranges) ? ($ranges[0] ?? null) : null;

        if ($typeId <= 0 || !is_array($range)) {
            return null;
        }

        if (!isset($range['price']) || !is_numeric($range['price'])) {
            return null;
        }

        return [
            'typeId' => $typeId,
            'price' => (float)$range['price'],
            'currency' => (string)($range['currency'] ?? 'RUB'),
        ];
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
