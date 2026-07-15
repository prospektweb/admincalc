<?php

namespace Prospektweb\Frontcalc\Service;

class CalcServerBatchResultValidator
{
    /**
     * @param mixed $items
     * @param array<int,array<string,mixed>> $selectedById
     * @return array{validItems:array<int,array<string,mixed>>,validOfferIds:array<int,int>,missingOfferIds:array<int,int>,unknownOfferIds:array<int,int>,duplicateOfferIds:array<int,int>,invalidItems:array<int,array<string,mixed>>,isComplete:bool}
     */
    public function validate($items, array $selectedById): array
    {
        $validItems = [];
        $validOfferIds = [];
        $invalidItems = [];
        $unknownOfferIds = [];
        $duplicateOfferIds = [];
        $seenOfferIds = [];

        if (!is_array($items)) {
            $items = [];
            $invalidItems[] = ['index' => null, 'reason' => 'DATA_NOT_ARRAY'];
        } elseif (!$this->isList($items)) {
            $items = [];
            $invalidItems[] = ['index' => null, 'reason' => 'DATA_NOT_LIST'];
        }

        $offerIdCounts = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $offerId = $this->extractOfferId($item['offer_id'] ?? null);
            if ($offerId !== null) {
                $offerIdCounts[$offerId] = ($offerIdCounts[$offerId] ?? 0) + 1;
            }
        }

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $invalidItems[] = ['index' => $index, 'reason' => 'ITEM_NOT_ARRAY'];
                continue;
            }

            $offerId = $this->extractOfferId($item['offer_id'] ?? null);
            if ($offerId === null) {
                $invalidItems[] = ['index' => $index, 'reason' => 'OFFER_ID_INVALID'];
                continue;
            }
            if (!isset($selectedById[$offerId])) {
                $unknownOfferIds[$offerId] = $offerId;
                continue;
            }
            if (($offerIdCounts[$offerId] ?? 0) > 1) {
                $duplicateOfferIds[$offerId] = $offerId;
                continue;
            }
            $seenOfferIds[$offerId] = true;

            $reason = $this->getInvalidReason($item);
            if ($reason !== null) {
                $invalidItems[] = ['index' => $index, 'offer_id' => $offerId, 'reason' => $reason];
                continue;
            }

            $validItems[] = $item;
            $validOfferIds[$offerId] = $offerId;
        }

        $missingOfferIds = [];
        foreach ($selectedById as $offerId => $_selectedOffer) {
            $offerId = (int)$offerId;
            if (!isset($validOfferIds[$offerId])) {
                $missingOfferIds[$offerId] = $offerId;
            }
        }

        ksort($validOfferIds, SORT_NUMERIC);
        ksort($missingOfferIds, SORT_NUMERIC);
        ksort($unknownOfferIds, SORT_NUMERIC);
        ksort($duplicateOfferIds, SORT_NUMERIC);

        $isComplete = empty($missingOfferIds) && empty($unknownOfferIds) && empty($duplicateOfferIds) && empty($invalidItems);

        return [
            'validItems' => $validItems,
            'validOfferIds' => array_values($validOfferIds),
            'missingOfferIds' => array_values($missingOfferIds),
            'unknownOfferIds' => array_values($unknownOfferIds),
            'duplicateOfferIds' => array_values($duplicateOfferIds),
            'invalidItems' => $invalidItems,
            'isComplete' => $isComplete,
        ];
    }

    private function extractOfferId($value): ?int
    {
        if (is_int($value)) {
            return $value < 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^-\d+$/', trim($value))) {
            return (int)$value;
        }
        return null;
    }

    /** @param array<string,mixed> $item */
    private function getInvalidReason(array $item): ?string
    {
        if (!array_key_exists('purchase_price', $item) || !is_numeric($item['purchase_price']) || (float)$item['purchase_price'] < 0) {
            return 'PURCHASE_PRICE_INVALID';
        }
        if (!array_key_exists('currency', $item) || !is_string($item['currency']) || trim($item['currency']) === '') {
            return 'CURRENCY_INVALID';
        }
        foreach (['direct_purchase_price', 'width', 'length', 'height', 'weight'] as $key) {
            if (array_key_exists($key, $item) && $item[$key] !== null && !is_numeric($item[$key])) {
                return strtoupper($key) . '_INVALID';
            }
        }
        if (array_key_exists('parametr_values', $item) && $item['parametr_values'] !== null && !is_array($item['parametr_values'])) {
            return 'PARAMETR_VALUES_INVALID';
        }
        return null;
    }

    /** @param array<mixed> $items */
    private function isList(array $items): bool
    {
        $expectedKey = 0;
        foreach ($items as $key => $_item) {
            if ($key !== $expectedKey) {
                return false;
            }
            $expectedKey++;
        }
        return true;
    }
}
