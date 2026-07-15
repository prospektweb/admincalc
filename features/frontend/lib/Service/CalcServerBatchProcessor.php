<?php

namespace Prospektweb\Frontcalc\Service;

class CalcServerBatchProcessor
{
    public function process(array $baseInitPayload, array $selectedOffers, string $calcServerUrl, int $timeout, int $batchLimit, CalcServerClient $client): array
    {
        $startedAt = microtime(true);
        $batches = array_chunk(array_values($selectedOffers), $batchLimit > 0 ? $batchLimit : 200);
        $validator = new CalcServerBatchResultValidator();
        $items = $warnings = $diagnostics = $debugResults = [];
        $meta = ['requested' => count($selectedOffers), 'calculated' => 0, 'batch_count' => count($batches), 'successful_batches' => 0, 'partial_batches' => 0, 'failed_batches' => 0, 'duration_ms' => 0, 'error' => ''];
        foreach ($batches as $batchIndex => $batch) {
            $selectedById = [];
            foreach ($batch as $selectedOffer) $selectedById[(int)($selectedOffer['id'] ?? 0)] = $selectedOffer;
            $initPayload = $baseInitPayload;
            $initPayload['selectedOffers'] = array_values($batch);
            $calcResult = $client->calculate($calcServerUrl, $timeout, $initPayload);
            $debugResults[] = $calcResult;
            $meta['duration_ms'] += (int)($calcResult['meta']['duration_ms'] ?? 0);
            $warnings = array_merge($warnings, is_array($calcResult['warnings'] ?? null) ? $calcResult['warnings'] : []);
            if (($calcResult['success'] ?? false) !== true) {
                $meta['failed_batches']++;
                $meta['error'] = (string)($calcResult['error']['message'] ?? $calcResult['message'] ?? 'calc-server batch error');
                $warnings[] = 'CALC_SERVER_BATCH_FAILED';
                $diagnostics[] = ['type' => 'failed', 'batch_index' => $batchIndex, 'batch_size' => count($selectedById), 'http_status' => (int)($calcResult['meta']['http_status'] ?? 0), 'error' => $meta['error'], 'technical_message' => (string)($calcResult['technical_message'] ?? ''), 'duration_ms' => (int)($calcResult['meta']['duration_ms'] ?? 0)];
                continue;
            }
            $validation = $validator->validate($calcResult['data'] ?? null, $selectedById);
            if (($validation['isComplete'] ?? false) === true) $meta['successful_batches']++;
            else {
                $meta['partial_batches']++;
                $warnings[] = 'CALC_SERVER_BATCH_PARTIAL';
                $diagnostics[] = ['type' => 'partial', 'batch_index' => $batchIndex, 'expected_count' => count($selectedById), 'valid_count' => count($validation['validOfferIds'] ?? []), 'missing_offer_ids' => $this->limitedIntList($validation['missingOfferIds'] ?? []), 'unknown_offer_ids' => $this->limitedIntList($validation['unknownOfferIds'] ?? []), 'duplicate_offer_ids' => $this->limitedIntList($validation['duplicateOfferIds'] ?? []), 'invalid_count' => count($validation['invalidItems'] ?? [])];
            }
            foreach ($validation['validItems'] as $result) {
                $offerId = (int)($result['offer_id'] ?? 0);
                if (isset($selectedById[$offerId])) $items[] = ['result' => $result, 'selectedOffer' => $selectedById[$offerId], 'batchIndex' => $batchIndex];
            }
        }
        $meta['calculated'] = count($items);
        $meta['duration_ms'] = (int)max($meta['duration_ms'], round((microtime(true) - $startedAt) * 1000));
        return ['items' => $items, 'meta' => $meta, 'warnings' => array_values(array_unique($warnings)), 'diagnostics' => $diagnostics, 'debugResults' => $debugResults];
    }

    private function limitedIntList(array $values, int $limit = 20): array
    {
        return array_slice(array_values(array_map('intval', $values)), 0, $limit);
    }
}
