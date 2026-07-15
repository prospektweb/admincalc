<?php

namespace Bitrix\Catalog\Product {
    class Price
    {
        public static array $calls = [];

        public static function roundPrice($typeId, $price, $currency)
        {
            self::$calls[] = ['typeId' => $typeId, 'price' => $price, 'currency' => $currency];
            return round($price, 0);
        }
    }
}

namespace {
    class CCurrencyRates
    {
        public static $nextResult = null;
        public static array $calls = [];

        public static function ConvertCurrency($value, $fromCurrency, $toCurrency)
        {
            self::$calls[] = ['value' => $value, 'fromCurrency' => $fromCurrency, 'toCurrency' => $toCurrency];
            return self::$nextResult;
        }
    }

    class CEventLog
    {
        public static int $calls = 0;

        public static function Add($payload): void
        {
            self::$calls++;
        }
    }

    require_once __DIR__ . '/../lib/Service/PresetPriceCalculator.php';

    use Bitrix\Catalog\Product\Price;
    use Prospektweb\Frontcalc\Service\PresetPriceCalculator;

    function assert_same_value($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
            exit(1);
        }
    }

    function assert_contains_value($needle, array $haystack, string $message): void
    {
        if (!in_array($needle, $haystack, true)) {
            fwrite(STDERR, $message . ': missing ' . var_export($needle, true) . ' in ' . var_export($haystack, true) . PHP_EOL);
            exit(1);
        }
    }

    PresetPriceCalculator::setCurrencyModuleAvailable(true);

    $types = [
        ['id' => 1, 'name' => 'BASE'],
        ['id' => 2, 'name' => 'WHOLESALE'],
    ];

    $calculator = new PresetPriceCalculator();
    $rows = $calculator->calculate(1000, 'rub', [
        ['typeId' => 1, 'price' => 50, 'currency' => 'prc', 'quantityFrom' => 0, 'quantityTo' => null],
        ['typeId' => 2, 'price' => 30, 'currency' => 'PRC', 'quantityFrom' => 0, 'quantityTo' => null],
    ], $types);
    assert_same_value(1500.0, $rows[0]['price'], '50 PRC should apply percent markup');
    assert_same_value('RUB', $rows[0]['currency'], 'Base currency should be normalized to uppercase');
    assert_same_value(1300.0, $rows[1]['price'], '30 PRC should apply percent markup');

    $calculator = new PresetPriceCalculator();
    $rows = $calculator->calculate(1000, 'RUB', [
        ['typeId' => 1, 'price' => 300, 'currency' => 'RUB', 'quantityFrom' => 0, 'quantityTo' => null],
    ], [['id' => 1, 'name' => 'BASE']]);
    assert_same_value(1300.0, $rows[0]['price'], 'Fixed RUB markup should be added to base cost');

    CCurrencyRates::$nextResult = 900;
    CCurrencyRates::$calls = [];
    $calculator = new PresetPriceCalculator();
    $rows = $calculator->calculate(1000, 'rub', [
        ['typeId' => 1, 'price' => 10, 'currency' => 'usd', 'quantityFrom' => 0, 'quantityTo' => null],
    ], [['id' => 1, 'name' => 'BASE']]);
    assert_same_value(1900.0, $rows[0]['price'], 'Converted fixed markup should be added to base cost');
    assert_same_value('USD', CCurrencyRates::$calls[0]['fromCurrency'], 'Conversion from currency should be uppercase');
    assert_same_value('RUB', CCurrencyRates::$calls[0]['toCurrency'], 'Conversion to currency should be uppercase');

    CCurrencyRates::$nextResult = false;
    $calculator = new PresetPriceCalculator();
    $rows = $calculator->calculate(1000, 'RUB', [
        ['typeId' => 1, 'price' => 10, 'currency' => 'USD', 'quantityFrom' => 100, 'quantityTo' => 499],
    ], [['id' => 1, 'name' => 'BASE']]);
    assert_same_value(1000.0, $rows[0]['price'], 'Conversion failure should fall back to base cost');
    assert_contains_value(PresetPriceCalculator::CURRENCY_CONVERSION_FAILED_WARNING, $calculator->getWarnings(), 'Conversion failure warning should be public-safe');
    assert_same_value(PresetPriceCalculator::CURRENCY_CONVERSION_FAILED_WARNING, $calculator->getDiagnostics()[0]['code'], 'Conversion diagnostic code should be present');
    assert_same_value('USD', $calculator->getDiagnostics()[0]['fromCurrency'], 'Conversion diagnostic should include source currency');

    $calculator = new PresetPriceCalculator();
    $rows = $calculator->calculate(1000, 'RUB', [
        ['typeId' => 1, 'price' => 60, 'currency' => 'PRC', 'quantityFrom' => 1, 'quantityTo' => 99],
        ['typeId' => 1, 'price' => 45, 'currency' => 'PRC', 'quantityFrom' => 100, 'quantityTo' => 499],
        ['typeId' => 1, 'price' => 30, 'currency' => 'PRC', 'quantityFrom' => 500, 'quantityTo' => null],
    ], [['id' => 1, 'name' => 'BASE']]);
    assert_same_value(1600.0, $rows[0]['price'], '1-99 range should use 60%');
    assert_same_value(1450.0, $rows[1]['price'], '100-499 range should use 45%');
    assert_same_value(1300.0, $rows[2]['price'], '500-infinity range should use 30%');
    assert_same_value(null, $rows[2]['quantityTo'], 'Open range should keep null quantityTo');

    CEventLog::$calls = 0;
    $calculator = new PresetPriceCalculator();
    $rows = $calculator->calculate(1000, 'RUB', [
        ['typeId' => 1, 'price' => 50, 'currency' => 'PRC', 'quantityFrom' => 0, 'quantityTo' => null],
    ], $types);
    assert_same_value(1000.0, $rows[1]['price'], 'Missing rule should fall back to base cost');
    assert_contains_value(PresetPriceCalculator::MISSING_RULE_WARNING, $calculator->getWarnings(), 'Missing rule warning should be present');
    assert_same_value(PresetPriceCalculator::MISSING_RULE_WARNING, $calculator->getDiagnostics()[0]['code'], 'Missing rule diagnostic code should be present');
    assert_same_value(0, CEventLog::$calls, 'PresetPriceCalculator must not write to CEventLog');

    Price::$calls = [];
    $calculator = new PresetPriceCalculator();
    $rows = $calculator->calculate(1000, 'RUB', [
        ['typeId' => 1, 'price' => 33.333, 'currency' => 'PRC', 'quantityFrom' => 0, 'quantityTo' => null],
    ], [['id' => 1, 'name' => 'BASE']]);
    assert_same_value(1333.0, $rows[0]['price'], 'Rounder result should be used after markup');
    assert_same_value(1333.33, Price::$calls[0]['price'], 'Bitrix rounding should receive post-markup price');

    echo "PresetPriceCalculator tests passed\n";
}
