<?php

namespace Prospektweb\Calc\Services;

/**
 * Canonical, server-owned proof that a batch write was explicitly previewed.
 *
 * The endpoint persists the returned fingerprints in private per-user storage.
 * A client only receives the aggregate fingerprint, so it cannot manufacture a
 * valid start precondition without first completing the server preview.
 */
final class BatchPreviewFingerprintService
{
    public const CONTRACT = 'prospektweb.calc.batch-preview-fingerprint/v1';

    /**
     * @param array<string,mixed> $scope
     * @param array<int|string,array<string,mixed>> $stateFingerprints
     * @param array<string,mixed> $preview
     * @return array{contract:string,fingerprint:string,scopeFingerprint:string,stateFingerprint:string,previewFingerprint:string}
     */
    public static function issue(array $scope, array $stateFingerprints, array $preview): array
    {
        if (($preview['ready'] ?? false) !== true) {
            throw new \InvalidArgumentException('A failed batch preview cannot authorize catalog writes.');
        }

        $normalizedScope = self::normalizeScope($scope);
        $normalizedState = self::normalizeStateFingerprints($stateFingerprints);
        $normalizedPreview = self::normalizePreview($preview);

        $scopeFingerprint = self::hashPayload([
            'contract' => self::CONTRACT . '/scope',
            'scope' => $normalizedScope,
        ]);
        $stateFingerprint = self::hashPayload([
            'contract' => self::CONTRACT . '/state',
            'state' => $normalizedState,
        ]);
        $previewFingerprint = self::hashPayload([
            'contract' => self::CONTRACT . '/result',
            'preview' => $normalizedPreview,
        ]);
        $fingerprint = self::hashPayload([
            'contract' => self::CONTRACT,
            'scopeFingerprint' => $scopeFingerprint,
            'stateFingerprint' => $stateFingerprint,
            'previewFingerprint' => $previewFingerprint,
        ]);

        return [
            'contract' => self::CONTRACT,
            'fingerprint' => $fingerprint,
            'scopeFingerprint' => $scopeFingerprint,
            'stateFingerprint' => $stateFingerprint,
            'previewFingerprint' => $previewFingerprint,
        ];
    }

    /** @param array<string,mixed> $scope */
    public static function scopeFingerprint(array $scope): string
    {
        return self::hashPayload([
            'contract' => self::CONTRACT . '/scope',
            'scope' => self::normalizeScope($scope),
        ]);
    }

    /** @param array<int|string,array<string,mixed>> $stateFingerprints */
    public static function stateFingerprint(array $stateFingerprints): string
    {
        return self::hashPayload([
            'contract' => self::CONTRACT . '/state',
            'state' => self::normalizeStateFingerprints($stateFingerprints),
        ]);
    }

    /**
     * Per-offer proof of the exact projected result reviewed by the operator.
     * These hashes stay server-side and are carried into the background job.
     *
     * @param array<string,mixed> $preview
     * @return array<int,string>
     */
    public static function resultFingerprints(array $preview): array
    {
        $normalizedPreview = self::normalizePreview($preview);
        $fingerprints = [];
        foreach ($normalizedPreview['offers'] as $offer) {
            $offerId = (int)($offer['offerId'] ?? 0);
            if ($offerId <= 0 || isset($fingerprints[$offerId])) {
                throw new \InvalidArgumentException('Batch preview contains an invalid or duplicate result target.');
            }
            $fingerprints[$offerId] = self::hashPayload([
                'contract' => self::CONTRACT . '/offer-result',
                'offer' => $offer,
            ]);
        }
        ksort($fingerprints, SORT_NUMERIC);
        if ($fingerprints === []) {
            throw new \InvalidArgumentException('Batch preview contains no projected offer results.');
        }
        return $fingerprints;
    }

    public static function isValidFingerprint(string $fingerprint): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', strtolower(trim($fingerprint))) === 1;
    }

    /** @param array<string,mixed> $scope */
    private static function normalizeScope(array $scope): array
    {
        $presetIds = self::normalizePositiveIds(is_array($scope['presetIds'] ?? null) ? $scope['presetIds'] : []);

        $products = [];
        foreach ((array)($scope['productIdsByPreset'] ?? []) as $presetId => $productIds) {
            $presetId = (int)$presetId;
            if ($presetId <= 0 || !is_array($productIds)) {
                continue;
            }
            $products[(string)$presetId] = self::normalizePositiveIds($productIds);
        }
        ksort($products, SORT_NUMERIC);

        $rows = [];
        foreach ((array)($scope['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $presetId = (int)($row['presetId'] ?? 0);
            if ($presetId <= 0) {
                continue;
            }
            $rows[] = [
                'presetId' => $presetId,
                'offerIds' => self::normalizePositiveIds(is_array($row['offerIds'] ?? null) ? $row['offerIds'] : []),
            ];
        }
        usort($rows, static function (array $left, array $right): int {
            return $left['presetId'] <=> $right['presetId'];
        });

        return [
            'presetIds' => $presetIds,
            'productIdsByPreset' => $products,
            'onlyChanged' => (bool)($scope['onlyChanged'] ?? true),
            'calcServerUrl' => rtrim(trim((string)($scope['calcServerUrl'] ?? '')), '/'),
            'timeout' => (int)($scope['timeout'] ?? 0),
            'rows' => $rows,
        ];
    }

    /** @param array<int|string,array<string,mixed>> $stateFingerprints */
    private static function normalizeStateFingerprints(array $stateFingerprints): array
    {
        $normalized = [];
        foreach ($stateFingerprints as $offerId => $state) {
            $offerId = (int)$offerId;
            if ($offerId <= 0 || !is_array($state)) {
                continue;
            }
            $calculation = strtolower(trim((string)($state['calculation'] ?? '')));
            $catalog = strtolower(trim((string)($state['catalog'] ?? '')));
            if (!self::isValidFingerprint($calculation) || !self::isValidFingerprint($catalog)) {
                throw new \InvalidArgumentException('Batch preview contains an invalid calculation or catalog-state fingerprint.');
            }
            $normalized[(string)$offerId] = [
                'calculation' => $calculation,
                'catalog' => $catalog,
            ];
        }
        ksort($normalized, SORT_NUMERIC);

        if ($normalized === []) {
            throw new \InvalidArgumentException('Batch preview contains no offer-state fingerprints.');
        }

        return $normalized;
    }

    /** @param array<string,mixed> $preview */
    private static function normalizePreview(array $preview): array
    {
        $offers = [];
        foreach ((array)($preview['offers'] ?? []) as $offer) {
            if (is_array($offer)) {
                $offers[] = self::canonicalize($offer);
            }
        }
        usort($offers, static function (array $left, array $right): int {
            $idOrder = ((int)($left['offerId'] ?? 0)) <=> ((int)($right['offerId'] ?? 0));
            return $idOrder !== 0
                ? $idOrder
                : strcmp(self::canonicalEncode($left), self::canonicalEncode($right));
        });

        $errors = [];
        foreach ((array)($preview['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $errors[] = self::canonicalize($error);
            }
        }
        usort($errors, static function (array $left, array $right): int {
            return strcmp(self::canonicalEncode($left), self::canonicalEncode($right));
        });

        return [
            'ready' => true,
            'summary' => self::canonicalize(is_array($preview['summary'] ?? null) ? $preview['summary'] : []),
            'offers' => $offers,
            'errors' => $errors,
        ];
    }

    /** @param mixed $value @return mixed */
    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = self::canonicalize($item);
        }

        $keys = array_keys($normalized);
        $isList = $keys === [] || $keys === range(0, count($keys) - 1);
        if (!$isList) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }

    /** @param array<int,mixed> $values @return int[] */
    private static function normalizePositiveIds(array $values): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $values), static function (int $id): bool {
            return $id > 0;
        })));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<string,mixed> $payload */
    private static function hashPayload(array $payload): string
    {
        return hash('sha256', self::canonicalEncode($payload));
    }

    /** @param mixed $payload */
    private static function canonicalEncode($payload): string
    {
        $json = json_encode(
            self::canonicalize($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to encode the batch preview fingerprint payload.');
        }
        return $json;
    }
}
