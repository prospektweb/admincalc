<?php

namespace Prospektweb\Frontcalc\Service {
    final class PresetPriceCalculator
    {
        /** @var string[] */
        private array $warnings = [];

        public function calculate($baseCost, string $currency, array $rules, array $priceTypes): array
        {
            $this->warnings = [];
            $rulesByType = [];
            foreach ($rules as $rule) {
                $rulesByType[(int)($rule['typeId'] ?? 0)] = $rule;
            }
            $result = [];
            foreach ($priceTypes as $type) {
                $typeId = (int)($type['id'] ?? 0);
                if (!isset($rulesByType[$typeId])) {
                    $this->warnings[] = 'PRESET_PRICE_RULE_MISSING';
                    continue;
                }
                $rule = $rulesByType[$typeId];
                $addition = (string)($rule['currency'] ?? '') === 'PRC'
                    ? (float)$baseCost * (float)$rule['price'] / 100
                    : (float)$rule['price'];
                $result[] = [
                    'typeId' => $typeId,
                    'quantityFrom' => $rule['quantityFrom'] ?? 0,
                    'quantityTo' => $rule['quantityTo'] ?? null,
                    'price' => (float)$baseCost + $addition,
                    'currency' => $currency,
                ];
            }
            return $result;
        }

        public function getWarnings(): array
        {
            return array_values(array_unique($this->warnings));
        }
    }
}

namespace {

require_once dirname(__DIR__) . '/lib/Services/StandaloneCatalogPriceNormalizer.php';

use Prospektweb\Calc\Services\StandaloneCatalogPriceNormalizer;

function standalone_price_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$normalizer = new StandaloneCatalogPriceNormalizer();
$result = $normalizer->normalize([
    [
        'offerId' => 15320,
        'purchasePrice' => 100,
        'currency' => 'RUB',
        'appliedPriceRules' => [
            ['typeId' => 1, 'quantityFrom' => 0, 'quantityTo' => 1, 'price' => 100, 'currency' => 'PRC'],
            ['typeId' => 2, 'quantityFrom' => 0, 'quantityTo' => 1, 'price' => 50, 'currency' => 'RUB'],
        ],
    ],
], [
    ['id' => 1, 'name' => 'BASE'],
    ['id' => 2, 'name' => 'PARTNER'],
]);

$ranges = $result[0]['priceRangesWithMarkup'] ?? [];
standalone_price_assert(count($ranges) === 1, 'one quantity range must stay grouped');
standalone_price_assert(count($ranges[0]['prices'] ?? []) === 2, 'all catalog price types must be present');
standalone_price_assert((float)$ranges[0]['prices'][0]['basePrice'] === 200.0, 'percentage rule must match FrontCalc pricing');
standalone_price_assert((float)$ranges[0]['prices'][1]['basePrice'] === 150.0, 'fixed RUB addition must match FrontCalc pricing');

$missingRuleRejected = false;
try {
    $normalizer->normalize([
        ['offerId' => 1, 'purchase_price' => 100, 'currency' => 'RUB'],
    ], [
        ['id' => 1, 'name' => 'BASE'],
        ['id' => 2, 'name' => 'PARTNER'],
    ], [
        ['typeId' => 1, 'quantityFrom' => 0, 'quantityTo' => null, 'price' => 20, 'currency' => 'PRC'],
    ]);
} catch (RuntimeException $exception) {
    $missingRuleRejected = strpos($exception->getMessage(), 'предупреждениями') !== false;
}
standalone_price_assert($missingRuleRejected, 'incomplete price profiles must fail closed');

echo "standalone_catalog_price_normalizer_test: OK\n";
}
