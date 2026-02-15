<?php

namespace Prospektweb\Calc\Services;

use Prospektweb\Calc\Calculator\CalculationHistoryHandler;

/**
 * Единый orchestrator сохранения: история расчётов + обновление ТП.
 */
class SaveAllService
{
    private CalculationHistoryHandler $historyHandler;
    private OfferUpdateService $offerUpdateService;

    public function __construct()
    {
        $this->historyHandler = new CalculationHistoryHandler();
        $this->offerUpdateService = new OfferUpdateService();
    }

    /**
     * @param array $payload Данные PWRT payload (offers/results)
     * @return array
     */
    public function handle(array $payload): array
    {
        $historyResult = $this->historyHandler->handle($payload);

        $offers = $this->normalizeOffersForUpdate($payload);
        if (empty($offers)) {
            return [
                'status' => $historyResult['status'] ?? 'error',
                'history' => $historyResult,
                'offersUpdate' => [
                    'status' => 'error',
                    'message' => 'Пустой payload: нет offers/results для обновления торговых предложений',
                    'total' => 0,
                    'updated' => 0,
                    'errors' => [],
                    'offers' => [],
                ],
                'total' => 0,
                'saved' => (int)($historyResult['saved'] ?? 0),
                'updated' => 0,
            ];
        }

        $offersUpdateResult = $this->offerUpdateService->updateOffersFromCalculation($offers);

        return [
            'status' => $this->resolveFinalStatus($historyResult['status'] ?? 'error', $offersUpdateResult['status'] ?? 'error'),
            'history' => $historyResult,
            'offersUpdate' => $offersUpdateResult,
            'results' => $historyResult['results'] ?? [],
            'total' => (int)($historyResult['total'] ?? count($offers)),
            'saved' => (int)($historyResult['saved'] ?? 0),
            'updated' => (int)($offersUpdateResult['updated'] ?? 0),
            'failed' => max(0, (int)($offersUpdateResult['total'] ?? 0) - (int)($offersUpdateResult['updated'] ?? 0)),
        ];
    }

    private function normalizeOffersForUpdate(array $payload): array
    {
        $source = [];

        if (isset($payload['offers']) && is_array($payload['offers'])) {
            $source = $payload['offers'];
        } elseif (isset($payload['results']) && is_array($payload['results'])) {
            $source = $payload['results'];
        }

        $offers = [];

        foreach ($source as $item) {
            if (!is_array($item)) {
                continue;
            }

            $offerId = (int)($item['offerId'] ?? $item['offerID'] ?? $item['id'] ?? 0);
            if ($offerId <= 0) {
                continue;
            }

            $candidate = $item;
            if (isset($item['json']) && is_array($item['json'])) {
                $candidate = $item['json'];
            }

            if (!is_array($candidate)) {
                continue;
            }

            $candidate['offerId'] = $offerId;
            if (!isset($candidate['offerName']) && isset($item['offerName'])) {
                $candidate['offerName'] = $item['offerName'];
            }

            $offers[] = $candidate;
        }

        return $offers;
    }

    private function resolveFinalStatus(string $historyStatus, string $offersStatus): string
    {
        if ($historyStatus === 'ok' && $offersStatus === 'ok') {
            return 'ok';
        }

        if ($historyStatus === 'error' && $offersStatus === 'error') {
            return 'error';
        }

        return 'partial';
    }
}
