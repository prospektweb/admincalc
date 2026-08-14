<?php

namespace Bitrix\Main {
    final class Loader
    {
        public static function includeModule(string $moduleId): bool
        {
            return $moduleId === 'catalog';
        }
    }
}

namespace Prospektweb\Calc\Services {
    final class CatalogPriceService
    {
    }

    require_once __DIR__ . '/../lib/Services/OfferUpdateService.php';

    $source = file_get_contents(__DIR__ . '/../lib/Services/OfferUpdateService.php');
    foreach (['startTransaction()', 'commitTransaction()', 'rollbackTransaction()'] as $transactionCall) {
        if (!is_string($source) || strpos($source, $transactionCall) === false) {
            throw new \RuntimeException('Offer catalog writes must remain transactional: ' . $transactionCall);
        }
    }

    $service = new OfferUpdateService();
    $validOffer = [
        'offerId' => 15320,
        'offerName' => 'Визитки 100 шт., 4+0',
        'purchasePrice' => 420.5,
        'currency' => 'RUB',
        'details' => [[
            'outputs' => [
                'width' => 90,
                'length' => 50,
                'height' => 30,
                'weight' => 270,
            ],
        ]],
        'priceRangesWithMarkup' => [[
            'quantityFrom' => null,
            'quantityTo' => null,
            'prices' => [[
                'typeId' => 1,
                'basePrice' => 690,
                'currency' => 'RUB',
            ]],
        ]],
    ];

    $preview = $service->previewOffersFromCalculation([$validOffer], [15320]);
    if (empty($preview['ready']) || ($preview['summary']['valid'] ?? 0) !== 1) {
        throw new \RuntimeException('A complete positive calculation must pass the write preview');
    }

    $invalidOffer = $validOffer;
    $invalidOffer['offerId'] = 15321;
    $invalidOffer['purchasePrice'] = 0;
    $invalidOffer['details'][0]['outputs']['weight'] = null;
    $invalidOffer['priceRangesWithMarkup'][0]['prices'][0]['basePrice'] = 0;
    $invalidPreview = $service->previewOffersFromCalculation([$invalidOffer], [15321]);
    if (!empty($invalidPreview['ready']) || ($invalidPreview['summary']['invalid'] ?? 0) !== 1) {
        throw new \RuntimeException('Zero prices or a missing physical field must block catalog writes');
    }

    $missingPreview = $service->previewOffersFromCalculation([], [15322]);
    if (!empty($missingPreview['ready']) || ($missingPreview['offers'][0]['offerId'] ?? 0) !== 15322) {
        throw new \RuntimeException('A missing calc-server result must block the requested offer');
    }

    $unexpectedOffer = $validOffer;
    $unexpectedOffer['offerId'] = 99999;
    $unexpectedPreview = $service->previewOffersFromCalculation([$unexpectedOffer], [15320]);
    if (!empty($unexpectedPreview['ready']) || ($unexpectedPreview['summary']['invalid'] ?? 0) < 1) {
        throw new \RuntimeException('An unexpected calc-server offer must block the preview');
    }

    echo "Offer update preview tests passed\n";
}
