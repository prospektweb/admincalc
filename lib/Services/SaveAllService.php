<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Config\Option;
use Prospektweb\Calc\Calculator\CalculationHistoryHandler;

/**
 * Единый orchestrator сохранения: история расчётов + обновление ТП.
 */
class SaveAllService
{
    private const MODULE_ID = 'prospektweb.calc';

    private OfferUpdateService $offerUpdateService;

    public function __construct()
    {
        $this->offerUpdateService = new OfferUpdateService();
    }

    /**
     * @param array $payload Данные PWRT payload (offers/results)
     * @return array
     */
    public function handle(array $payload): array
    {
        $historyResult = [
            'status' => 'skipped',
            'message' => 'Сохранение истории расчётов отключено в настройках модуля',
            'results' => [],
            'total' => 0,
            'saved' => 0,
        ];

        if (Option::get(self::MODULE_ID, 'SAVE_CALC_HISTORY', 'N') === 'Y') {
            $historyResult = (new CalculationHistoryHandler())->handle($payload);
        }

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
        if (($historyStatus === 'ok' || $historyStatus === 'skipped') && $offersStatus === 'ok') {
            return 'ok';
        }

        if ($historyStatus === 'error' && $offersStatus === 'error') {
            return 'error';
        }

        if ($historyStatus === 'skipped' && $offersStatus === 'error') {
            return 'error';
        }

        return 'partial';
    }
}
