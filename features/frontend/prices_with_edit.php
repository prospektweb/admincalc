<?php

namespace Aspro\Premier\Product;

use Aspro\Premier\Vendor\Include;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use CPremier as Solution;

class Prices
{
    protected $options;
    protected $item;
    protected $params;

    protected $currentPrice;
    protected $itemPrices;
    protected $matrixPrices;
    protected $simplePrices;
    protected $customPrices;
    protected $propsPrices;

    protected bool $isPopupTemplateCaptured = false;
    protected static bool $frontcalcRuntimeAssetsCaptured = false;
    protected static bool $frontcalcIconSpriteCaptured = false;

    protected ?array $frontcalcLightPayload = null;

    public function __construct(?array $item = null, ?array $params = null, ?array $options = null)
    {
        if (isset($item) && $item) {
            $this->item = $item;
        }

        if (isset($params) && $params) {
            $this->params = $params;
        }

        $this->resetOptions();
        $this->setOptions($options);

        $this->mkPrices();

        return $this;
    }

    public function __get(string $variable)
    {
        return $this->$variable ?? null;
    }

    public function __set(string $variable, $value)
    {
        switch ($variable) {
            case 'item':
                if (isset($value) && is_array($value) && $value) {
                    $this->$variable = $value;
                    $this->mkPrices();
                }

                break;
            case 'params':
                if (isset($value) && is_array($value) && $value) {
                    $this->$variable = $value;
                }

                break;
            case 'options':
                $this->setOptions($value);

                break;
        }

        return $this->$variable ?? null;
    }

    public function resetOptions(): static
    {
        $this->options = [
            'WRAPPER_CLASS' => '',
            'PRICE_BLOCK_CLASS' => 'color_dark',
            'PRICE_FONT' => 16,
            'PRICE_WEIGHT' => 500,
            'PRICEOLD_FONT' => 12,
            'PRICEOLD_WEIGHT' => 500,
            'SHOW_SCHEMA' => true,
            'SHOW_TABLE' => true,
            'SHOW_POPUP_DETAIL_BUTTON' => true,
            'PRICES' => [], // for custom prices
            'POPOVER_POSITION' => 'bottom',
            'SHOW_POPUP_PRICE_TEMPLATE' => 'N',
            'EXTENDED_INFO' => '',
            'DISPLAY_VAT_INFO' => 'N',
        ];

        return $this;
    }

    public function setOptions(?array $options = []): static
    {
        if (isset($options) && is_array($options) && $options) {
            $this->options = array_merge($this->options, $options);
            $this->mkPrices(); // for custom prices
        }

        return $this;
    }

    public function mkPrices(): static
    {
        $this->currentPrice = $this->itemPrices = $this->matrixPrices = $this->simplePrices = $this->customPrices = $this->propsPrices = null;

        // collect prices
        if (
            $this->item
            && !empty($this->item['ITEM_PRICES'])
            && is_array($this->item['ITEM_PRICES'])
        ) {
            $this->itemPrices = $this->item['ITEM_PRICES'];

            // sort by QUANTITY_FROM => asc
            uasort($this->itemPrices, function ($a, $b) {
                return $a['QUANTITY_FROM'] <=> $b['QUANTITY_FROM'];
            });
        }

        if (
            $this->item
            && !empty($this->item['PRICES'])
            && is_array($this->item['PRICES'])
        ) {
            $this->simplePrices = $this->item['PRICES'];

            // only accessible prices
            $this->simplePrices = array_filter($this->simplePrices, function ($price) {
                return $price['CAN_ACCESS'] === 'Y';
            });

            // fill item PRICE_MATRIX
            if (
                $this->isUseCount()
                && $this->item
                && (
                    !array_key_exists('ITEM_PRICE_MODE', $this->item)
                    || $this->item['ITEM_PRICE_MODE'] === 'Q'
                )
                && empty($this->item['PRICE_MATRIX'])
                && function_exists('\CatalogGetPriceTableEx')
            ) {
                $simplePricesTypes = array_column($this->simplePrices, 'PRICE_ID');
                if ($simplePricesTypes) {
                    // convert currency
                    $convertCurrency = $this->getConvertCurrency();

                    $this->item['PRICE_MATRIX'] = \CatalogGetPriceTableEx(
                        $this->item['ID'],
                        0,
                        $simplePricesTypes,
                        $this->params['PRICE_VAT_INCLUDE'] ? 'Y' : 'N',
                        $convertCurrency ? ['CURRENCY_ID' => $convertCurrency['CURRENCY']] : []
                    );
                }
            }
        }

        if (
            $this->item
            && !empty($this->item['PRICE_MATRIX'])
            && is_array($this->item['PRICE_MATRIX'])
        ) {
            $this->matrixPrices = $this->item['PRICE_MATRIX'];

            // sort ROWS (range) by QUANTITY_FROM => asc
            uasort($this->matrixPrices['ROWS'], function ($a, $b) {
                return $a['QUANTITY_FROM'] <=> $b['QUANTITY_FROM'];
            });
        }

        if (
            $this->options
            && !empty($this->options['PRICES'])
            && is_array($this->options['PRICES'])
        ) {
            $this->customPrices = $this->options['PRICES'];

            if (array_key_exists('VALUE', $this->customPrices)) {
                $this->customPrices = [$this->customPrices];
            }

            // only accessible prices
            $this->customPrices = array_filter(
                $this->customPrices,
                function ($price) {
                    if (
                        (
                            !array_key_exists('CAN_ACCESS', $price)
                            || $price['CAN_ACCESS'] === 'Y'
                        )
                        && array_key_exists('VALUE', $price)
                        && strlen($price['VALUE'])
                    ) {
                        return true;
                    }

                    return false;
                }
            );
        }

        if (
            $this->item
            && ($props = $this->item['PROPERTIES'] ?? $this->item['DISPLAY_PROPERTIES'])
            && !empty($props)
            && is_array($props)
            && array_key_exists('PRICE', $props)
            && is_array($props['PRICE'])
            && array_key_exists('VALUE', $props['PRICE'])
        ) {
            $this->propsPrices = [
                'PRICE_CURRENCY' => $props['PRICE_CURRENCY'] ?? $props['CURRENCY'] ?? [],
                'PRICE' => $props['PRICE'] ?? [],
                'PRICEOLD' => $props['PRICEOLD'] ?? [],
                'FILTER_PRICE' => $props['FILTER_PRICE'] ?? [],
                'ECONOMY' => $props['ECONOMY'] ?? [],
            ];
        }

        $showCount = $this->getShowCount();

        // make current price to buy for count = $showCount
        if ($this->hasOffers()) {
            if (empty($this->item['MIN_PRICE'])) {
                if (!empty($this->item['OFFERS'])) {
                    $this->item['MIN_PRICE'] = Price::getMinPriceFromOffersExt($this->item['OFFERS']);
                } elseif (!empty($this->item['SKU']['OFFERS'])) {
                    $this->item['MIN_PRICE'] = Price::getMinPriceFromOffersExt($this->item['SKU']['OFFERS']);
                }
            }

            $minPrice = empty($this->item['MIN_PRICE']) ? [] : $this->item['MIN_PRICE'];
            if ($minPrice) {
                $measureId = $this->item['ITEM_MEASURE']['ID'] ?? $this->item['CATALOG_MEASURE'] ?? '';
                $measure = $this->item['ITEM_MEASURE']['NAME'] ?? trim($measureId ? Common::showMeasure(Common::getMeasureById($measureId)) : '', '/');

                $this->currentPrice = [
                    'CURRENCY' => $minPrice['CURRENCY'],
                    'VALUE' => $minPrice['VALUE'],
                    'PRINT_VALUE' => $minPrice['PRINT_VALUE'],
                    'DISCOUNT_VALUE' => $minPrice['DISCOUNT_VALUE'],
                    'PRINT_DISCOUNT_VALUE' => $minPrice['PRINT_DISCOUNT_VALUE'],
                    'DISCOUNT_DIFF' => $minPrice['DISCOUNT_DIFF'],
                    'PRINT_DISCOUNT_DIFF' => $minPrice['PRINT_DISCOUNT_DIFF'],
                    'DISCOUNT_DIFF_PERCENT' => $minPrice['DISCOUNT_DIFF_PERCENT'],
                    'PRICE_ID' => $minPrice['ID'],
                    'PRICE_TYPE_ID' => $minPrice['PRICE_ID'],
                    'CATALOG_MEASURE' => $measureId,
                    'MEASURE' => $measure,
                ];
            }
        } elseif ($prices = $this->getItemPrices()) {
            foreach ($prices as $price) {
                if (
                    $price['QUANTITY_FROM'] <= $showCount
                    && (
                        !$price['QUANTITY_TO']
                        || $price['QUANTITY_TO'] >= $showCount
                    )
                ) {
                    $measureId = $this->item['ITEM_MEASURE']['ID'] ?? $this->item['CATALOG_MEASURE'] ?? '';
                    $measure = $this->item['ITEM_MEASURE']['NAME'] ?? trim($measureId ? Common::showMeasure(Common::getMeasureById($measureId)) : '', '/');

                    $this->currentPrice = [
                        'CURRENCY' => $price['CURRENCY'],
                        'VALUE' => $price['BASE_PRICE'],
                        'PRINT_VALUE' => $price['PRINT_BASE_PRICE'],
                        'DISCOUNT_VALUE' => $price['PRICE'],
                        'PRINT_DISCOUNT_VALUE' => $price['PRINT_PRICE'],
                        'DISCOUNT_DIFF' => $price['DISCOUNT'],
                        'PRINT_DISCOUNT_DIFF' => $price['PRINT_DISCOUNT'],
                        'DISCOUNT_DIFF_PERCENT' => $price['PERCENT'],
                        'PRICE_ID' => $price['ID'],
                        'PRICE_TYPE_ID' => $price['PRICE_TYPE_ID'],
                        'CATALOG_MEASURE' => $measureId,
                        'MEASURE' => $measure,
                    ];

                    break;
                }
            }
        } elseif ($prices = $this->getMatrixPrices()) {
            if ($minPrice = $this->getMinMatrixPrice($showCount)) {
                $measureId = $this->item['ITEM_MEASURE']['ID'] ?? $this->item['CATALOG_MEASURE'] ?? '';
                $measure = $this->item['ITEM_MEASURE']['NAME'] ?? trim($measureId ? Common::showMeasure(Common::getMeasureById($measureId)) : '', '/');

                $discountDiff = $discountPercent = 0;
                if ($minPrice['PRICE'] > 0) {
                    $discountDiff = $minPrice['PRICE'] - $minPrice['DISCOUNT_PRICE'];
                    $discountPercent = round(($discountDiff / $minPrice['PRICE']) * 100, 0);
                }

                $this->currentPrice = [
                    'CURRENCY' => $minPrice['CURRENCY'],
                    'VALUE' => $minPrice['PRICE'],
                    'PRINT_VALUE' => \CCurrencyLang::CurrencyFormat($minPrice['PRICE'], $minPrice['CURRENCY'], true),
                    'DISCOUNT_VALUE' => $minPrice['DISCOUNT_PRICE'],
                    'PRINT_DISCOUNT_VALUE' => \CCurrencyLang::CurrencyFormat($minPrice['DISCOUNT_PRICE'], $minPrice['CURRENCY'], true),
                    'DISCOUNT_DIFF' => $discountDiff,
                    'PRINT_DISCOUNT_DIFF' => \CCurrencyLang::CurrencyFormat($discountDiff, $minPrice['CURRENCY'], true),
                    'DISCOUNT_DIFF_PERCENT' => $discountPercent,
                    'PRICE_ID' => $minPrice['ID'],
                    'PRICE_TYPE_ID' => $minPrice['PRICE_ID'],
                    'CATALOG_MEASURE' => $measureId,
                    'MEASURE' => $measure,
                ];
            }
        } elseif ($prices = $this->getSimplePrices()) {
            if ($minPrice = $this->getMinSimplePrice()) {
                $measureId = $this->item['ITEM_MEASURE']['ID'] ?? $this->item['CATALOG_MEASURE'] ?? '';
                $measure = $this->item['ITEM_MEASURE']['NAME'] ?? trim($measureId ? Common::showMeasure(Common::getMeasureById($measureId)) : '', '/');

                $this->currentPrice = [
                    'CURRENCY' => $minPrice['CURRENCY'],
                    'VALUE' => $minPrice['VALUE'],
                    'PRINT_VALUE' => $minPrice['PRINT_VALUE'],
                    'DISCOUNT_VALUE' => $minPrice['DISCOUNT_VALUE'],
                    'PRINT_DISCOUNT_VALUE' => $minPrice['PRINT_DISCOUNT_VALUE'],
                    'DISCOUNT_DIFF' => $minPrice['DISCOUNT_DIFF'],
                    'PRINT_DISCOUNT_DIFF' => $minPrice['PRINT_DISCOUNT_DIFF'],
                    'DISCOUNT_DIFF_PERCENT' => $minPrice['DISCOUNT_DIFF_PERCENT'],
                    'PRICE_ID' => $minPrice['ID'],
                    'PRICE_TYPE_ID' => $minPrice['PRICE_ID'],
                    'CATALOG_MEASURE' => $measureId,
                    'MEASURE' => $measure,
                ];
            }
        } elseif ($prices = $this->getCustomPrices()) {
            if ($minPrice = $this->getMinCustomPrice()) {
                $currency = $minPrice['CURRENCY'] ?? $minPrice['PRICE_CURRENCY'] ?? '';

                $discountDiff = $minPrice['DISCOUNT_DIFF'] ?? (($minPrice['VALUE'] * 1) > 0 ? ($minPrice['VALUE'] - $minPrice['DISCOUNT_VALUE']) : 0);
                $discountPercent = $minPrice['DISCOUNT_DIFF_PERCENT'] ?? (($minPrice['VALUE'] * 1) > 0 ? round(($discountDiff / $minPrice['VALUE']) * 100, 0) : 0);

                if (Loader::includeModule('currency')) {
                    $minPrice['PRINT_VALUE'] = $minPrice['PRINT_VALUE'] ?? \CCurrencyLang::CurrencyFormat($minPrice['VALUE'], $currency, true);
                    $minPrice['PRINT_DISCOUNT_VALUE'] = $minPrice['PRINT_DISCOUNT_VALUE'] ?? \CCurrencyLang::CurrencyFormat($minPrice['DISCOUNT_VALUE'], $currency, true);
                    $minPrice['PRINT_DISCOUNT_DIFF'] = $minPrice['PRINT_DISCOUNT_DIFF'] ?? \CCurrencyLang::CurrencyFormat($discountDiff, $currency, true);
                }

                $this->currentPrice = [
                    'CURRENCY' => $currency,
                    'VALUE' => $minPrice['VALUE'],
                    'PRINT_VALUE' => $minPrice['PRINT_VALUE'],
                    'DISCOUNT_VALUE' => $minPrice['DISCOUNT_VALUE'],
                    'PRINT_DISCOUNT_VALUE' => $minPrice['PRINT_DISCOUNT_VALUE'],
                    'DISCOUNT_DIFF' => $discountDiff,
                    'PRINT_DISCOUNT_DIFF' => $minPrice['PRINT_DISCOUNT_DIFF'],
                    'DISCOUNT_DIFF_PERCENT' => $discountPercent,
                ];

                if (isset($this->item['ITEM_MEASURE']['ID']) || isset($this->item['CATALOG_MEASURE'])) {
                    $measureId = $this->item['ITEM_MEASURE']['ID'] ?? $this->item['CATALOG_MEASURE'] ?? '';
                    $measure = $this->item['ITEM_MEASURE']['NAME'] ?? trim($measureId ? Common::showMeasure(Common::getMeasureById($measureId)) : '', '/');

                    $this->currentPrice['CATALOG_MEASURE'] = $measureId;
                    $this->currentPrice['MEASURE'] = $measure;
                }
            }
        } elseif ($prices = $this->getPropsPrices()) {
            $this->currentPrice = [
                'VALUE' => $prices['PRICEOLD']['DISPLAY_VALUE'] ?? $prices['PRICEOLD']['~VALUE'] ?? $prices['PRICEOLD']['VALUE'],
                'PRINT_VALUE' => $prices['PRICEOLD']['DISPLAY_VALUE'] ?? $prices['PRICEOLD']['~VALUE'] ?? $prices['PRICEOLD']['VALUE'],
                'DISCOUNT_VALUE' => $prices['PRICE']['DISPLAY_VALUE'] ?? $prices['PRICE']['~VALUE'] ?? $prices['PRICE']['VALUE'],
                'PRINT_DISCOUNT_VALUE' => $prices['PRICE']['DISPLAY_VALUE'] ?? $prices['PRICE']['~VALUE'] ?? $prices['PRICE']['VALUE'],
                'DISCOUNT_DIFF_PERCENT' => $prices['ECONOMY']['DISPLAY_VALUE'] ?? $prices['ECONOMY']['~VALUE'] ?? $prices['ECONOMY']['VALUE'],
            ];

            if (!strlen($this->currentPrice['VALUE']) && strlen($this->currentPrice['DISCOUNT_VALUE'])) {
                $this->currentPrice['VALUE'] = $this->currentPrice['DISCOUNT_VALUE'];
                $this->currentPrice['PRINT_VALUE'] = $this->currentPrice['PRINT_DISCOUNT_VALUE'];
            }
        }

        return $this;
    }

    /**
     * The price is filled, you need to show it, for example 0.00.
     */
    public function isFilled(): bool
    {
        return (bool) $this->currentPrice && strlen($this->currentPrice['VALUE'] ?? '');
    }

    /**
     * The price is not filled.
     */
    public function isEmpty(): bool
    {
        return !$this->isFilled();
    }

    /**
     * The price is filled and greater than zero.
     */
    public function isGreaterThanZero(): bool
    {
        return $this->isFilled() && $this->currentPrice['VALUE'] > 0;
    }

    public function isCatalogFilled(): bool
    {
        return $this->isFilled() && ($this->hasItemPrices() || $this->hasMatrixPrices() || $this->hasSimplePrices());
    }

    public function getCurrentPrice(): ?array
    {
        return $this->currentPrice;
    }

    public function hasOffers(): bool
    {
        return $this->item && (!empty($this->item['OFFERS']) || !empty($this->item['SKU']['OFFERS']));
    }

    public function getItemPrices(): ?array
    {
        return $this->itemPrices;
    }

    public function hasItemPrices(): bool
    {
        return (bool) $this->getItemPrices();
    }

    public function getMatrixPrices(): ?array
    {
        return $this->matrixPrices;
    }

    public function getMinMatrixPrice(int $showCount = 1): ?array
    {
        $minPrice = null;

        if ($prices = $this->getMatrixPrices()) {
            foreach ($prices['ROWS'] as $rowKey => $row) {
                if (
                    $row['QUANTITY_FROM'] <= $showCount
                    && (
                        !$row['QUANTITY_TO']
                        || $row['QUANTITY_TO'] >= $showCount
                    )
                ) {
                    $min = 0;
                    foreach ($prices['MATRIX'] as $colkey => $matrixCol) {
                        if (in_array($colkey, $prices['CAN_BUY'])) {
                            if (isset($matrixCol[$rowKey])) {
                                $matrixCol[$rowKey]['PRICE_ID'] = $colkey;
                                $compare = $matrixCol[$rowKey]['DISCOUNT_PRICE'] ?? $matrixCol[$rowKey]['PRICE'];

                                if (
                                    !$min
                                    || $min > $compare
                                ) {
                                    $min = $compare;
                                    $minPrice = $matrixCol[$rowKey];
                                }
                            }
                        }
                    }

                    break;
                }
            }
        }

        return $minPrice;
    }

    public function hasMatrixPrices(): bool
    {
        return (bool) $this->getMatrixPrices();
    }

    public function getSimplePrices(): ?array
    {
        return $this->simplePrices;
    }

    public function getMinSimplePrice(): ?array
    {
        $minPrice = null;

        if ($prices = $this->getSimplePrices()) {
            foreach ($prices as $i => $price) {
                if ($price['CAN_BUY'] === 'Y') {
                    if (isset($price['MIN_PRICE']) && $price['MIN_PRICE'] === 'Y') {
                        $minPrice = $price[$i];
                    }
                }
            }

            if (!$minPrice) {
                // convert currency
                $convertCurrency = $this->getConvertCurrency();

                $min = 0;
                foreach ($prices as $i => $price) {
                    if ($price['CAN_BUY'] === 'Y') {
                        $compare = $price['DISCOUNT_VALUE'] ?? $price['VALUE'];

                        if (
                            $convertCurrency
                            && is_array($convertCurrency)
                            && isset($price['CURRENCY'])
                            && $price['CURRENCY']
                            && $price['CURRENCY'] != $convertCurrency['CURRENCY']
                        ) {
                            $compare = \CCurrencyRates::ConvertCurrency($compare, $price['CURRENCY'], $convertCurrency['CURRENCY']);
                        }

                        if (
                            !$min
                            || $min > $compare
                        ) {
                            $min = $compare;
                            $minPrice = $prices[$i];
                        }
                    }
                }
            }
        }

        return $minPrice;
    }

    public function hasSimplePrices(): bool
    {
        return (bool) $this->getSimplePrices();
    }

    public function getCustomPrices(): ?array
    {
        return $this->customPrices;
    }

    public function getMinCustomPrice(): ?array
    {
        $minPrice = null;

        if ($prices = $this->getCustomPrices()) {
            foreach ($prices as $i => $price) {
                if (
                    !array_key_exists('CAN_BUY', $price)
                    || $price['CAN_BUY'] === 'Y'
                ) {
                    if (isset($price['MIN_PRICE']) && $price['MIN_PRICE'] === 'Y') {
                        $minPrice = $price[$i];
                    }
                }
            }

            if (!$minPrice) {
                $min = 0;
                foreach ($prices as $i => $price) {
                    if (
                        !array_key_exists('CAN_BUY', $price)
                        || $price['CAN_BUY'] === 'Y'
                    ) {
                        $compare = $price['DISCOUNT_VALUE'] ?? $price['VALUE'];

                        if (
                            !$min
                            || $min > $compare
                        ) {
                            $min = $compare;
                            $minPrice = $prices[$i];
                        }
                    }
                }
            }
        }

        return $minPrice;
    }

    public function hasCustomPrices(): bool
    {
        return (bool) $this->getCustomPrices();
    }

    public function getPropsPrices(): ?array
    {
        return $this->propsPrices;
    }

    public function hasPropsPrices(): bool
    {
        return (bool) $this->getPropsPrices();
    }

    public function isWithTable(): bool
    {
        if (
            $this->options
            && isset($this->options['SHOW_TABLE'])
            && (
                $this->options['SHOW_TABLE'] === 'N'
                || $this->options['SHOW_TABLE'] === false
            )
        ) {
            return false;
        }

        if (!$this->hasOffers()) {
            if ($this->isUseCompatible()) {
                if ($prices = $this->getMatrixPrices()) {
                    return count($prices['COLS']) > 1 || count($prices['ROWS']) > 1;
                } elseif ($prices = $this->getSimplePrices()) {
                    return count($prices) > 1;
                }
            } else {
                if (
                    $this->isUseCount()
                    && $prices = $this->getItemPrices()
                ) {
                    return count($prices) > 1;
                }
            }
        }

        return false;
    }

    public function isWithPopupTable(): bool
    {
        if ($this->isWithTable()) {
            return static::getPopupPriceShowConditionFromParams($this->params);
        }

        return false;
    }

    public static function getPopupPriceShowConditionFromParams(array $params): bool
    {
        if ($params && array_key_exists('SHOW_POPUP_PRICE', $params)) {
            return $params['SHOW_POPUP_PRICE'] !== false && $params['SHOW_POPUP_PRICE'] !== 'N' && $params['SHOW_POPUP_PRICE'] !== 'NO';
        } else {
            return Solution::getFrontParametrValue('SHOW_POPUP_PRICE') != 'NO';
        }
    }

    public function isWithInlineTable(): bool
    {
        return $this->isWithTable() && !$this->isWithPopupTable();
    }

    public function isUseCompatible(): bool
    {
        // false by default
        if (
            $this->params
            && array_key_exists('COMPATIBLE_MODE', $this->params)
        ) {
            return $this->params['COMPATIBLE_MODE'] === true || $this->params['COMPATIBLE_MODE'] === 'Y';
        } else {
            return Solution::getFrontParametrValue('CATALOG_COMPATIBLE_MODE') === 'Y';
        }
    }

    public function isUseCount(): bool
    {
        // true by default
        if (
            $this->params
            && array_key_exists('USE_PRICE_COUNT', $this->params)
        ) {
            return $this->params['USE_PRICE_COUNT'] !== false && $this->params['USE_PRICE_COUNT'] !== 'N';
        } else {
            return Solution::getFrontParametrValue('USE_PRICE_COUNT') !== 'N';
        }
    }

    public function getShowCount(): int
    {
        $count = 1;

        if ($this->isUseCount()) {
            if (
                $this->params
                && array_key_exists('SHOW_PRICE_COUNT', $this->params)
            ) {
                $tmp = intval($this->params['SHOW_PRICE_COUNT'] ?? 1);
            } else {
                $tmp = intval(Solution::getFrontParametrValue('SHOW_PRICE_COUNT') ?? 1);
            }

            if ($tmp > 1) {
                $count = $tmp;
            }
        }

        return $count;
    }

    public function isShowSchema(): bool
    {
        if ($this->params && ($this->params['SHOW_PRICE'] === true || $this->params['SHOW_PRICE'] === 'Y')) {
            return false;
        }

        // true by default
        return array_key_exists('SHOW_SCHEMA', $this->options) && $this->options['SHOW_SCHEMA'] !== false && $this->options['SHOW_SCHEMA'] !== 'N';
    }

    public function isShowOldPrice(): bool
    {
        // true by default
        if (
            $this->params
            && array_key_exists('SHOW_OLD_PRICE', $this->params)
        ) {
            return $this->params['SHOW_OLD_PRICE'] !== false && $this->params['SHOW_OLD_PRICE'] !== 'N';
        } else {
            return Solution::getFrontParametrValue('SHOW_OLD_PRICE') != 'N';
        }
    }

    public function isShowDiscountDiff(): bool
    {
        // false by default
        if (
            $this->params
            && array_key_exists('SHOW_DISCOUNT_DIFF', $this->params)
        ) {
            return $this->params['SHOW_DISCOUNT_DIFF'] === true || $this->params['SHOW_DISCOUNT_DIFF'] === 'Y';
        } else {
            return Solution::getFrontParametrValue('SHOW_DISCOUNT_DIFF') === 'Y';
        }
    }

    public function isShowDiscountPercent(): bool
    {
        // true by default
        if (
            $this->params
            && array_key_exists('SHOW_DISCOUNT_PERCENT', $this->params)
        ) {
            return $this->params['SHOW_DISCOUNT_PERCENT'] !== false && $this->params['SHOW_DISCOUNT_PERCENT'] !== 'N';
        } else {
            return Solution::getFrontParametrValue('SHOW_DISCOUNT_PERCENT') != 'N';
        }
    }

    public function isConvertCurrency(): bool
    {
        if (
            $this->params
            && array_key_exists('CONVERT_CURRENCY', $this->params)
        ) {
            return $this->params['CONVERT_CURRENCY'] === true || $this->params['CONVERT_CURRENCY'] === 'Y';
        } else {
            return Solution::getFrontParametrValue('CONVERT_CURRENCY') != 'N';
        }
    }

    public function getConvertCurrencyId(): ?string
    {
        if (
            $this->params
            && array_key_exists('CURRENCY_ID', $this->params)
        ) {
            return $this->params['CURRENCY_ID'] ?: null;
        } else {
            return Solution::getFrontParametrValue('CURRENCY_ID') ?: null;
        }
    }

    public function getConvertCurrency(): ?array
    {
        if ($this->isConvertCurrency()) {
            $convertCurrencyId = $this->getConvertCurrencyId();
            if ($convertCurrencyId) {
                $convertCurrency = static::getCurrency($convertCurrencyId);
                if ($convertCurrency && is_array($convertCurrency)) {
                    return $convertCurrency;
                }
            }
        }

        return null;
    }

    public function isDetailPage(): bool
    {
        return $this->params
            && (
                (array_key_exists('ELEMENT_ID', $this->params) && array_key_exists('ELEMENT_CODE', $this->params))
                || (array_key_exists('IS_DETAIL', $this->params) && $this->params['IS_DETAIL'])
            );
    }

    public function isDetailPageSku2List(): bool
    {
        return $this->params && array_key_exists('REPLACED_DETAIL_LINK', $this->params);
    }

    public function isShowPopupDetailButton(): bool
    {
        if (
            $this->item
            && $this->item['DETAIL_PAGE_URL']
            && !$this->isDetailPage()
            && !$this->isDetailPageSku2List()
        ) {
            if (
                $this->options
                && isset($this->options['SHOW_POPUP_DETAIL_BUTTON'])
            ) {
                return $this->options['SHOW_POPUP_DETAIL_BUTTON'] !== false && $this->options['SHOW_POPUP_DETAIL_BUTTON'] !== 'N';
            }
        }

        return false;
    }

    public function showPopupDetailButton()
    {
        ?>
        <a href="<?=$this->item['DETAIL_PAGE_URL'];?>" class="btn btn-default btn-sm width-100"><?=Loc::getMessage('PRICE_POPUP_BUTTON_DETAIL');?></a>
        <?php
    }

    protected function getFrontcalcLightPayload(): array
    {
        if ($this->frontcalcLightPayload !== null) {
            return $this->frontcalcLightPayload;
        }

        [$productId, $iblockId] = $this->resolveFrontcalcProductContext();

        $serviceClass = '\Prospektweb\Frontcalc\Service\CalculatorAvailability';
        if (!class_exists($serviceClass)) {
            Loader::includeModule('prospektweb.calc');
        }

        if (!class_exists($serviceClass)) {
            return $this->frontcalcLightPayload = [
                'is_available' => false,
                'product_id' => $productId,
                'ajax_url' => '/local/ajax/frontcalc.php',
                'open_popup_chips' => [],
            ];
        }

        $service = new $serviceClass();

        return $this->frontcalcLightPayload = $service->getLightPayload($productId, $iblockId, '/local/ajax/frontcalc.php');
    }

    protected function resolveFrontcalcProductContext(): array
    {
        $productId = (int)($this->item['ID'] ?? 0);
        $iblockId = (int)($this->item['IBLOCK_ID'] ?? $this->params['IBLOCK_ID'] ?? $this->params['CATALOG_IBLOCK_ID'] ?? 0);
        $productsIblockId = (int)Option::get('prospektweb.calc', 'PRODUCTS_IBLOCK_ID', '0');
        $offersIblockId = (int)Option::get('prospektweb.calc', 'OFFERS_IBLOCK_ID', '0');

        if ($productId > 0 && $productsIblockId > 0 && $iblockId === $productsIblockId) {
            return [$productId, $productsIblockId];
        }

        if ($productId > 0 && $offersIblockId > 0 && $iblockId === $offersIblockId) {
            $parentProduct = $this->getFrontcalcParentProduct($productId, $offersIblockId);
            if ($parentProduct) {
                return $parentProduct;
            }
        }

        $detailProductId = (int)($this->params['ELEMENT_ID'] ?? 0);
        if ($detailProductId > 0 && $productsIblockId > 0) {
            return [$detailProductId, $productsIblockId];
        }

        if ($productId > 0 && $productsIblockId > 0 && $iblockId <= 0) {
            return [$productId, $productsIblockId];
        }

        if ($productId > 0 && $iblockId > 0) {
            return [$productId, $iblockId];
        }

        return [$productId, $productsIblockId];
    }

    protected function getFrontcalcParentProduct(int $offerId, int $offersIblockId): ?array
    {
        if ($offerId <= 0 || $offersIblockId <= 0 || !Loader::includeModule('catalog') || !class_exists('\CCatalogSku')) {
            return null;
        }

        $parentProduct = \CCatalogSku::GetProductInfo($offerId, $offersIblockId);
        if (!is_array($parentProduct)) {
            return null;
        }

        $productId = (int)($parentProduct['ID'] ?? 0);
        $iblockId = (int)($parentProduct['IBLOCK_ID'] ?? 0);

        return $productId > 0 && $iblockId > 0 ? [$productId, $iblockId] : null;
    }

    protected function isFrontcalcAvailable(): bool
    {
        $payload = $this->getFrontcalcLightPayload();

        return !empty($payload['is_available']);
    }

    protected function renderFrontcalcRuntimeAssets(): string
    {
        if (self::$frontcalcRuntimeAssetsCaptured) {
            return '';
        }

        if (!function_exists('frontcalc_render_runtime_assets')) {
            $templateIncludeLocal = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/prospektweb.calc/features/frontend/template_include.php';
            $templateIncludeBitrix = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/prospektweb.calc/features/frontend/template_include.php';

            if (is_file($templateIncludeLocal)) {
                require_once $templateIncludeLocal;
            } elseif (is_file($templateIncludeBitrix)) {
                require_once $templateIncludeBitrix;
            }
        }

        if (!function_exists('frontcalc_render_runtime_assets')) {
            return '';
        }

        self::$frontcalcRuntimeAssetsCaptured = true;

        return frontcalc_render_runtime_assets();
    }

    protected function renderFrontcalcIconSprite(): string
    {
        if (self::$frontcalcIconSpriteCaptured) {
            return '';
        }

        self::$frontcalcIconSpriteCaptured = true;

        return <<<'HTML'
<svg class="frontcalc-icon-sprite" aria-hidden="true" focusable="false" width="0" height="0" style="position:absolute;width:0;height:0;overflow:hidden">
    <symbol id="frontcalc-icon-calc" viewBox="0 0 1250 1250">
        <path fill-rule="evenodd" d="M230 38H970A92 92 0 0 1 1062 130V1070A92 92 0 0 1 970 1162H230A92 92 0 0 1 138 1070V130A92 92 0 0 1 230 38Z M230 75A55 55 0 0 0 175 130V1070A55 55 0 0 0 230 1125H970A55 55 0 0 0 1025 1070V130A55 55 0 0 0 970 75H230Z M262 150H938A20 20 0 0 1 958 170V392A20 20 0 0 1 938 412H262A20 20 0 0 1 242 392V170A20 20 0 0 1 262 150Z M280 188V375H920V188H280Z M338 207A19 19 0 0 1 357 226V337A19 19 0 0 1 319 337V226A19 19 0 0 1 338 207Z M413 207A19 19 0 0 1 432 226V337A19 19 0 0 1 394 337V226A19 19 0 0 1 413 207Z M488 207A19 19 0 0 1 507 226V337A19 19 0 0 1 469 337V226A19 19 0 0 1 488 207Z M563 207A19 19 0 0 1 582 226V337A19 19 0 0 1 544 337V226A19 19 0 0 1 563 207Z M262 506H376A20 20 0 0 1 396 526V638A20 20 0 0 1 376 658H262A20 20 0 0 1 242 638V526A20 20 0 0 1 262 506Z M282 543V620H356V543H282Z M448 506H562A20 20 0 0 1 582 526V638A20 20 0 0 1 562 658H448A20 20 0 0 1 428 638V526A20 20 0 0 1 448 506Z M468 543V620H542V543H468Z M634 506H748A20 20 0 0 1 768 526V638A20 20 0 0 1 748 658H634A20 20 0 0 1 614 638V526A20 20 0 0 1 634 506Z M654 543V620H728V543H654Z M820 506H934A20 20 0 0 1 954 526V638A20 20 0 0 1 934 658H820A20 20 0 0 1 800 638V526A20 20 0 0 1 820 506Z M840 543V620H914V543H840Z M262 694H376A20 20 0 0 1 396 714V826A20 20 0 0 1 376 846H262A20 20 0 0 1 242 826V714A20 20 0 0 1 262 694Z M282 731V808H356V731H282Z M448 694H562A20 20 0 0 1 582 714V826A20 20 0 0 1 562 846H448A20 20 0 0 1 428 826V714A20 20 0 0 1 448 694Z M468 731V808H542V731H468Z M634 694H748A20 20 0 0 1 768 714V826A20 20 0 0 1 748 846H634A20 20 0 0 1 614 826V714A20 20 0 0 1 634 694Z M654 731V808H728V731H654Z M262 882H376A20 20 0 0 1 396 902V1014A20 20 0 0 1 376 1034H262A20 20 0 0 1 242 1014V902A20 20 0 0 1 262 882Z M282 919V996H356V919H282Z M448 882H562A20 20 0 0 1 582 902V1014A20 20 0 0 1 562 1034H448A20 20 0 0 1 428 1014V902A20 20 0 0 1 448 882Z M468 919V996H542V919H468Z M634 882H748A20 20 0 0 1 768 902V1014A20 20 0 0 1 748 1034H634A20 20 0 0 1 614 1014V902A20 20 0 0 1 634 882Z M654 919V996H728V919H654Z M820 694H934A20 20 0 0 1 954 714V1014A20 20 0 0 1 934 1034H820A20 20 0 0 1 800 1014V714A20 20 0 0 1 820 694Z M840 731V996H914V731H840Z"></path>
    </symbol>
</svg>
HTML;
    }

    public function showRow(?array $row, ?bool $bWithPopup = false)
    {
        if ($row && is_array($row)) {
            $bWithTitle = isset($row['TITLE']) && strlen($row['TITLE']);
            $bWithRange = isset($row['RANGE_TITLE']) && strlen($row['RANGE_TITLE']);
            $bDel = isset($row['IS_DISCOUNT']) && $row['IS_DISCOUNT'];
            $isCurrent = $this->currentPrice['PRICE_ID'] === $row['PRICE_ID'];

            $priceClasses = ['price', $this->options['PRICE_BLOCK_CLASS']];
            if ($bWithPopup) {
                $priceClasses[] = 'price--with-popup price__row';
            }
            if ($bWithTitle) {
                $priceClasses[] = 'price--with-title';
            }
            if ($bWithRange) {
                $priceClasses[] = 'price--with-range';
            }
            if ($this->isDetailPage() && $isCurrent) {
                $priceClasses[] = 'price--current';
            }
            $priceClasses = trim(implode(' ', array_unique($priceClasses)));

            $bCatalogPrices = isset($row['CATALOG_MEASURE']);
            ?>
            <div class="<?=$priceClasses;?>">
                <?if ($bWithTitle):?>
                    <div class="price__title font_12 <?= $bWithRange ? 'dark-color fw-500 mb mb--8' : 'secondary-color';?>"><?=$row['TITLE'];?></div>
                <?endif;?>

                <?if ($bWithRange):?>
                    <div class="price__range font_12 secondary-color"><span><?=$row['RANGE_TITLE'];?></span></div>
                <?endif;?>

                <?if (!$bWithPopup):?>
                    <div class="price__row">
                <?endif;?>

                    <?if ($bWithPopup):?>
                        <?php
                        $pricesTablePopover = new \Aspro\Premier\Popover\PricesTable($this);
                        ?>
                        <button
                            title="Расширенная информация о ценах"
                            type="button"
                            class="price__popup-toggle xpopover-toggle secondary-color rounded-4"
                            <?$pricesTablePopover->showToggleAttrs();?>
                        >
                            <?$pricesTablePopover->showContent();?>
                        </button>
                    <?endif;?>

                    <div class="price__new fw-<?=$this->options['PRICE_WEIGHT'];?>">
                        <?='<'.($bDel ? 'del' : 'span').' class="price__new-val font_'.($bDel ? $this->options['PRICEOLD_FONT'] : $this->options['PRICE_FONT']).'">';?>
                            <?if ($bCatalogPrices):?>
                                <?=Price::formatWithSchemaByTypes([
                                    'PRICE' => $row,
                                    'CATALOG_MEASURE' => $row['CATALOG_MEASURE'],
                                    'SHOW_SCHEMA' => $this->isShowSchema(),
                                ]);?>
                            <?else:?>
                                <?=Price::formatWithSchemaByProps(
                                    $row['DISCOUNT_VALUE'],
                                    $this->isShowSchema(),
                                    $this->getPropsPrices() ?: [],
                                    $row
                                );?>
                            <?endif;?>
                        <?='</'.($bDel ? 'del' : 'span').'>';?>
                    </div>

                    <?if (
                        isset($row['VALUE'])
                        && (
                            $bCatalogPrices
                            && $row['VALUE'] > $row['DISCOUNT_VALUE']
                        )
                        || (
                            !$bCatalogPrices
                            && isset($row['DISCOUNT_VALUE'])
                            && $row['VALUE'] != $row['DISCOUNT_VALUE']
                        )
                    ):?>
                        <?if ($this->isShowOldPrice()):?>
                            <div class="price__old fw-<?=$this->options['PRICEOLD_WEIGHT'];?>">
                                <del class="price__old-val font_<?=$this->options['PRICEOLD_FONT'];?> secondary-color">
                                    <?if ($bCatalogPrices):?>
                                        <?=$row['PRINT_VALUE'];?>
                                    <?else:?>
                                        <?=Price::formatWithSchemaByProps(
                                            $row['VALUE'],
                                            false,
                                            $this->item ? ($this->item['PROPERTIES'] ?: $this->item['DISPLAY_PROPERTIES'] ?: $this->item) : [],
                                            $row
                                        );?>
                                    <?endif;?>
                                </del>
                            </div>
                        <?endif;?>

                        <?if ($this->isShowDiscountPercent()):?>
                            <?php
                            $discountDiff = $row['DISCOUNT_DIFF'] ?? ($bCatalogPrices && $row['VALUE'] > 0 ? ($row['VALUE'] - $row['DISCOUNT_VALUE']) : 0);
                            $discountPercent = $row['DISCOUNT_DIFF_PERCENT'] ?? ($bCatalogPrices && $row['VALUE'] > 0 ? round(($discountDiff / $row['VALUE']) * 100, 0) : 0);
                            ?>
                            <?if ($this->isShowDiscountDiff()):?>
                                <?if ($discountPercent || $discountDiff):?>
                                    <?if (!$bWithPopup):?>
                                        </div><?// class="price__row"?>
                                        <div class="price__row">
                                    <?endif;?>

                                    <div class="price__economy sticker price__economy--with-diff">
                                        <?if ($discountPercent):?>
                                            <span class="price__economy-percent sticker__item sticker__item--sale-text font_12">-<?= $discountPercent.' %';?></span>
                                        <?endif;?>

                                        <?if ($discountDiff):?>
                                            <span class="price__economy-val sticker__item sticker__item--sale font_12">
                                                <?if ($bCatalogPrices):?>
                                                    -<?=$row['PRINT_DISCOUNT_DIFF'];?>
                                                <?else:?>
                                                    -<?=Price::formatWithSchemaByProps(
                                                        $discountDiff,
                                                        false,
                                                        $this->item ? ($this->item['PROPERTIES'] ?: $this->item['DISPLAY_PROPERTIES'] ?: $this->item) : [],
                                                        $row
                                                    );?>
                                                <?endif;?>
                                            </span>
                                        <?endif;?>
                                    </div>
                                <?endif;?>
                            <?else:?>
                                <?if ($discountPercent):?>
                                    <div class="price__economy sticker">
                                        <span class="price__economy-percent sticker__item sticker__item--sale-text font_12">
                                            <?if ($bCatalogPrices):?>
                                                -<?= $discountPercent.'%';?>
                                            <?else:?>
                                                -<?=Price::formatWithSchemaByProps(
                                                    $discountPercent,
                                                    false,
                                                    $this->item ? ($this->item['PROPERTIES'] ?: $this->item['DISPLAY_PROPERTIES'] ?: $this->item) : [],
                                                    $row
                                                );?>
                                            <?endif;?>
                                        </span>
                                    </div>
                                <?endif;?>
                            <?endif;?>
                        <?else:?>

                        <?endif;?>
                    <?endif;?>

                <?if (!$bWithPopup):?>
                        <?=$this->options['EXTENDED_INFO'];?>
                    </div><?// class="price__row"?>
                <?endif;?>
                <?if($this->options['DISPLAY_VAT_INFO'] === 'Y'):?>
                    <?$this->showVat();?>
                <?endif;?>
            </div>
            <?php
        }

    }

    protected function getCalcPropVolumeValue(): ?string
    {
        $props = $this->item['PROPERTIES'] ?? $this->item['DISPLAY_PROPERTIES'] ?? [];

        if (!is_array($props) || !isset($props['CALC_PROP_VOLUME'])) {
            return null;
        }

        $prop = $props['CALC_PROP_VOLUME'];
        $value = $prop['DISPLAY_VALUE'] ?? $prop['~VALUE'] ?? $prop['VALUE'] ?? null;

        if (is_array($value)) {
            $value = implode(', ', array_filter($value, static fn($v) => (string)$v !== ''));
        }

        $value = trim((string)$value);

        return $value !== '' ? $value : null;
    }

    protected function formatRepeatCondition(int $from, int $to = 0): string
    {
        if ($from <= 1) {
            return '1 тираж';
        }

        return 'от '.$from.' тиражей';
    }

    protected function formatPriceExt($value, ?string $currency): string
    {
        if ($value === null || $value === '' || !$currency) {
            return '—';
        }

        return \CCurrencyLang::CurrencyFormat((float)$value, $currency, true);
    }

    protected function getMatrixUnitPrice(array $cell)
    {
        return $cell['DISCOUNT_PRICE'] ?? $cell['PRICE'] ?? null;
    }

    protected function getItemUnitPrice(array $price)
    {
        return $price['PRICE'] ?? $price['DISCOUNT_VALUE'] ?? $price['BASE_PRICE'] ?? $price['VALUE'] ?? null;
    }

    protected function getSimpleUnitPrice(array $price)
    {
        return $price['DISCOUNT_VALUE'] ?? $price['VALUE'] ?? null;
    }

    protected function getCustomUnitPrice(array $price)
    {
        return $price['DISCOUNT_VALUE'] ?? $price['VALUE'] ?? null;
    }

    public function captureTable(): string
    {
        $html = '';

        if ($this->item['PRODUCT_ANALOG']) {
            return $html;
        }

        ob_start();
        ?>
        <div
            class="prices-popup-ext"
            data-current-price-type="<?=htmlspecialcharsbx((string)($this->currentPrice['PRICE_TYPE_ID'] ?? ''));?>"
        >
            <?php
            $matrixData = $this->getMatrixPrices();
            $cols = $matrixData['COLS'] ?? [];
            $rows = $matrixData['ROWS'] ?? [];
            $matrix = $matrixData['MATRIX'] ?? [];
            ?>

            <?php if ($cols && $rows && $matrix): ?>

                <div class="prices-popup-ext__tabs">
                    <?php foreach ($cols as $colKey => $col): ?>
                        <?php
                        $priceTypeName = $col['NAME_LANG'] ?? $col['NAME'] ?? ('Тип цены #'.$colKey);

                        $hintText = '';
                        if (mb_stripos($priceTypeName, 'Корпоратив') !== false) {
                            $hintText = 'Цены для авторизованных пользователей';
                        } elseif (mb_stripos($priceTypeName, 'Контракт') !== false) {
                            $hintText = 'Цены для верифицированных пользователей';
                        }
                        ?>

                        <button
                            type="button"
                            class="prices-popup-ext__tab"
                            data-price-type="<?=htmlspecialcharsbx((string)$colKey);?>"
                            data-price-hint="<?=htmlspecialcharsbx($hintText);?>"
                        >
                            <?=htmlspecialcharsbx($priceTypeName);?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="prices-popup-ext__table-wrap">
                    <table class="prices-popup-ext__table prices-popup-ext__table--compact">
                        <colgroup>
                            <col style="width:38%">
                            <col style="width:31%">
                            <col style="width:31%">
                        </colgroup>

                        <thead>
                            <tr>
                                <th class="prices-popup-ext__th prices-popup-ext__th--condition">Условие</th>
                                <th class="prices-popup-ext__th prices-popup-ext__th--price">Цена за тираж</th>
                                <th class="prices-popup-ext__th prices-popup-ext__th--sum">Сумма</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($cols as $colKey => $col): ?>
                                <?php foreach ($rows as $rowKey => $rowMeta): ?>
                                    <?php
                                    $from = (int)($rowMeta['QUANTITY_FROM'] ?? 1);
                                    $repeatCount = max(1, $from);

                                    $cell = $matrix[$colKey][$rowKey] ?? null;
                                    $unitValue = is_array($cell) ? $this->getMatrixUnitPrice($cell) : null;
                                    $currency = is_array($cell) ? ($cell['CURRENCY'] ?? null) : null;
                                    $sumValue = $unitValue !== null ? ((float)$unitValue * $repeatCount) : null;
                                    ?>

                                    <tr
                                        class="prices-popup-ext__row"
                                        data-price-type="<?=htmlspecialcharsbx((string)$colKey);?>"
                                    >
                                        <td class="prices-popup-ext__cell prices-popup-ext__cell--condition">
                                            <?=htmlspecialcharsbx($this->formatRepeatCondition($from));?>
                                        </td>

                                        <td class="prices-popup-ext__cell prices-popup-ext__cell--price">
                                            <?=$this->formatPriceExt($unitValue, $currency);?>
                                        </td>

                                        <td class="prices-popup-ext__cell prices-popup-ext__cell--sum">
                                            <?=$this->formatPriceExt($sumValue, $currency);?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="prices-popup-ext__notice" style="display:none;">
                    <span class="prices-popup-ext__notice-icon">
                        <svg width="17" height="16">
                            <use xlink:href="/bitrix/templates/aspro-premier/images/svg/catalog/item_order_icons.svg?1774850114#attention-16-16"></use>
                        </svg>
                    </span>
                    <span class="prices-popup-ext__notice-text"></span>
                </div>

            <?php endif; ?>
        </div>

        <script>
        (function ($) {
            if (!window.PricesPopupExt) {
                window.PricesPopupExt = {};

                window.PricesPopupExt.activate = function ($root, type) {
                    type = String(type || '');

                    var $tab = $root.find('.prices-popup-ext__tab[data-price-type="' + type + '"]');
                    var hint = String($tab.attr('data-price-hint') || '');

                    $root.find('.prices-popup-ext__tab').removeClass('is-active');
                    $tab.addClass('is-active');

                    $root.find('.prices-popup-ext__row').removeClass('is-active');
                    $root.find('.prices-popup-ext__row[data-price-type="' + type + '"]').addClass('is-active');

                    var $notice = $root.find('.prices-popup-ext__notice');
                    var $noticeText = $root.find('.prices-popup-ext__notice-text');

                    if (hint) {
                        $noticeText.text(hint);
                        $notice.show();
                    } else {
                        $noticeText.text('');
                        $notice.hide();
                    }
                };

                window.PricesPopupExt.detect = function ($root) {
                    var currentType = String($root.attr('data-current-price-type') || '');

                    if (
                        currentType &&
                        $root.find('.prices-popup-ext__tab[data-price-type="' + currentType + '"]').length
                    ) {
                        return currentType;
                    }

                    return $root.find('.prices-popup-ext__tab').first().attr('data-price-type');
                };

                window.PricesPopupExt.init = function () {
                    $('.prices-popup-ext').each(function () {
                        var $root = $(this);

                        if (!$root.find('.prices-popup-ext__tab').length) {
                            return;
                        }

                        window.PricesPopupExt.activate(
                            $root,
                            window.PricesPopupExt.detect($root)
                        );
                    });
                };

                $(document).on('click', '.prices-popup-ext__tab', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    var $root = $(this).closest('.prices-popup-ext');
                    window.PricesPopupExt.activate($root, $(this).attr('data-price-type'));
                });

                $(document).on('click', '.price__popup-toggle, .xpopover-toggle', function () {
                    setTimeout(window.PricesPopupExt.init, 100);
                });
            }

            window.PricesPopupExt.init();
        })(jQuery);
        </script>

        <?php

        return trim(ob_get_clean());
    }

    public function showTable(): static
    {
        echo $this->captureTable();

        return $this;
    }

    protected function checkOldPrice(string $priceCode) : bool
    {
        return $this->params['DISCOUNT_PRICE'] && $this->params['DISCOUNT_PRICE'] === $priceCode;
    }

    public function showPopup(): static
    {
        $popupClasses = ['price__popup'];
        if ($this->isDetailPage()) {
            $popupClasses[] = 'price__popup--detail';
        }
        $popupClasses = trim(implode(' ', $popupClasses));
        ?>
        <div class="<?=$popupClasses;?>">
            <div class="price__popup-inner">
                <?$this->showTable();?>
            </div>

            <?if ($this->isShowPopupDetailButton()):?>
                <div class="price__popup-bottom pt pt--16">
                    <?$this->showPopupDetailButton();?>
                </div>
            <?endif;?>
        </div>
        <?php

        return $this;
    }

    protected function getVatMessage(): string
    {
        $vatRate = $this->item['PRODUCT']['VAT_RATE'];
        $isVatIncluded = $this->params['PRICE_VAT_INCLUDE'];

        if ($vatRate <= 0) {
            return Loc::getMessage('VAT_NOT_INCLUDED');
        }

        if ($isVatIncluded) {
            return Loc::getMessage('VAT_INCLUDED', ['#VAT_RATE#' => $vatRate]);
        }

        return Loc::getMessage('VAT_DIFF_WARNING', ['#VAT_RATE#' => $vatRate]);
    }

    protected function showVat(): void
    {
        echo '<div class="vat font_13 color_555 mt mt--8 ">' . $this->getVatMessage() . '</div>';
    }


    public function capture(): string
    {
        $html = '';
        if ($this->item['PRODUCT_ANALOG']) {
            $html = Price::formatEmptyPriceWithSchema([
                'SHOW_SCHEMA' => $this->isShowSchema(),
            ]);

            return $html;
        }

        $html .= '<div class="hidden"></div>';

        if ($this->isFilled()) {
            $pricesClasses = ['prices'];
            if ($this->isWithTable()) {
                $pricesClasses[] = 'prices--with-table';
                $pricesClasses[] = $this->isWithPopupTable() ? 'prices--with-popup-table' : 'prices--with-inline-table';
                if ($this->isWithPopupTable()) {
                    $pricesClasses[] = 'prices--with-popup-table--'.($this->isDetailPage() ? 'detail' : 'list');
                }
            }
            $pricesClasses = trim(implode(' ', $pricesClasses));

            ob_start();
            ?>
            <?if (strlen($this->options['WRAPPER_CLASS'])):?>
                <div class="<?=$this->options['WRAPPER_CLASS'];?>">
            <?endif;?>

            <div class="<?=$pricesClasses;?>">
                <?if ($this->isWithInlineTable()):?>
                    <?$this->showTable();?>
                    <?if($this->options['DISPLAY_VAT_INFO'] === 'Y'):?>
                        <?$this->showVat();?>
                    <?endif;?>
                <?else:?>
                    <?if ($this->isFrontcalcAvailable()):?>
                        <?php
                        $frontcalcPayload = $this->getFrontcalcLightPayload();
                        echo $this->renderFrontcalcRuntimeAssets();
                        echo $this->renderFrontcalcIconSprite();
                        ?>
                        <span
                            class="frontcalc-open-popup-source"
                            hidden
                            aria-hidden="true"
                            data-frontcalc-product-id="<?= (int)$frontcalcPayload['product_id']; ?>"
                            data-frontcalc-ajax-url="<?= htmlspecialcharsbx((string)$frontcalcPayload['ajax_url']); ?>"
                            data-frontcalc-open-popup-chips="<?= htmlspecialcharsbx(json_encode($frontcalcPayload['open_popup_chips'] ?? [], JSON_UNESCAPED_UNICODE)); ?>"
                            data-frontcalc-access="<?= htmlspecialcharsbx(json_encode($frontcalcPayload['access'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>"
                        ></span>
                    <?endif;?>
                    <?$this->showRow($this->currentPrice, $this->isWithPopupTable());?>
                <?endif;?>
                <?Include\Component::bonusesShow(params: ['ID' => $this->item['ID']]);?>
            </div>

            <?if (strlen($this->options['WRAPPER_CLASS'])):?>
                </div>
            <?endif;?>

            <?php
            $html = trim(ob_get_clean());
        }
        $this->resolveWhenNullOrMissingPrice($html);

        $this->resolvePopupTemplate($html);

        return $html;
    }

    protected function isShowPopupPriceTemplate()
    {
        return array_key_exists('SHOW_POPUP_PRICE_TEMPLATE', $this->options)
            && $this->options['SHOW_POPUP_PRICE_TEMPLATE'] === 'Y';
    }

    protected function resolvePopupTemplate(string &$html)
    {
        if (!$this->isShowPopupPriceTemplate()) {
            return;
        }

        if ($this->isWithPopupTable() || $this->isPopupTemplateCaptured) {
            return;
        }

        $this->isPopupTemplateCaptured = true;

        $stashedParams = $this->params;

        $this->params['SHOW_POPUP_PRICE'] = 'Y';
        $this->params['SHOW_SCHEMA'] = false;

        $html .= $this->captureWithPopupTemplate();

        $this->params = $stashedParams;
        unset($stashedParams);
    }

    protected function captureWithPopupTemplate(): string
    {
        return '<!-- noindex --><div class="template-popup-price" hidden aria-hidden="true">'.$this->capture().'</div><!-- /noindex -->';
    }

    private function resolveWhenNullOrMissingPrice(&$html)
    {
        if ($this->hasOffers()) {
            return;
        }

        $missingGoodsPriceDisplay = Solution::GetFrontParametrValue('MISSING_GOODS_PRICE_DISPLAY');
        $arConfig = $this->options;

        $missingGoodsPriceDisplayText = '<div class="price '.$arConfig['PRICE_BLOCK_CLASS'].'"><div class="price__new"><span class="price__new-val font_'.$arConfig['PRICE_FONT'].'">'.Solution::GetFrontParametrValue('MISSING_GOODS_PRICE_TEXT').'</span></div></div>';

        if ($this->isFilled()) {
            if (!$this->isGreaterThanZero()) {
                if ($missingGoodsPriceDisplay === 'NOTHING') {
                    $html = '<div class="hidden"></div>';
                    $html .= Price::formatEmptyPriceWithSchema([
                        'SHOW_SCHEMA' => $this->isShowSchema(),
                    ]);
                }
                if ($missingGoodsPriceDisplay === 'TEXT') {
                    $html = $missingGoodsPriceDisplayText;
                    $html .= Price::formatEmptyPriceWithSchema([
                        'SHOW_SCHEMA' => $this->isShowSchema(),
                    ]);
                }
            }
        } elseif ($missingGoodsPriceDisplay === 'TEXT') {
            $html = $missingGoodsPriceDisplayText;
            $html .= Price::formatEmptyPriceWithSchema([
                'SHOW_SCHEMA' => $this->isShowSchema(),
            ]);
        } else {
            $html .= Price::formatEmptyPriceWithSchema([
                'SHOW_SCHEMA' => $this->isShowSchema(),
            ]);
        }
    }

    public function show(): static
    {
        echo $this->capture();

        return $this;
    }

    public static function getCurrency(string $currencyId): array
    {
        static $arCurrencies;

        if (!isset($arCurrencies)) {
            $arCurrencies = [];
        }

        if (
            !$currencyId
            || !Loader::includeModule('currency')
        ) {
            return [];
        }

        if (!isset($arCurrencies[$currencyId])) {
            $arCurrencies[$currencyId] = \CCurrency::GetByID($currencyId);
        }

        return $arCurrencies[$currencyId];
    }

    public static function fixOffersMinPrice(&$arOffers, array $arParams = [], ?bool $bUseCount = null, ?int $showCount = null)
    {
        if (!isset($bUseCount)) {
            if (
                array_key_exists('USE_PRICE_COUNT', $arParams)
            ) {
                $bUseCount = $arParams['USE_PRICE_COUNT'] !== false && $arParams['USE_PRICE_COUNT'] !== 'N';
            }
        }

        if (!isset($showCount)) {
            $showCount = 1;

            if (
                $bUseCount
                && array_key_exists('SHOW_PRICE_COUNT', $arParams)
            ) {
                $tmp = intval($arParams['SHOW_PRICE_COUNT'] ?? 1);
                if ($tmp > 1) {
                    $showCount = $tmp;
                }
            }
        }

        if (
            $arOffers
            && is_array($arOffers)
            && $bUseCount
            && $showCount > 1
        ) {
            foreach ($arOffers as &$arOffer) {
                $offerPrices = new static(
                    $arOffer,
                    $arParams
                );

                $arOffer['MIN_PRICE'] = $offerPrices->getCurrentPrice();
            }
            unset($arOffer);
        }
    }
}
