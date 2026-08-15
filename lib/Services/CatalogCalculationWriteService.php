<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;
use Prospektweb\Calc\Calculator\InitPayloadService;

/**
 * Explicit preview/CAS/apply boundary for catalog-mode calculations.
 *
 * Client calculation results are never written directly. The service resolves
 * the current preset-owned runtime from the requested offer IDs, intersects
 * results with the current adapter output policy, previews the exact catalog
 * state transition and applies it only while the reviewed fingerprint is still
 * current under database row locks.
 */
final class CatalogCalculationWriteService
{
    public const PREVIEW_CONTRACT = 'prospektweb.calc.catalog-write-preview/v1';
    public const APPLY_CONTRACT = 'prospektweb.calc.catalog-write-apply/v1';
    public const BATCH_APPLY_CONTRACT = 'prospektweb.calc.catalog-batch-write-apply/v1';
    public const FINGERPRINT_CONTRACT = 'prospektweb.calc.catalog-write-fingerprint/v1';
    public const RECEIPT_CONTRACT = 'prospektweb.calc.catalog-write-receipt/v1';
    public const BATCH_RECEIPT_CONTRACT = 'prospektweb.calc.catalog-batch-write-receipt/v1';

    private const MAX_OFFERS = 100;
    private const RECEIPT_TTL_SECONDS = 604800;
    private const RECEIPT_MAX_COUNT_PER_TYPE = 256;
    private const RECEIPT_MAX_FUTURE_SKEW_SECONDS = 300;
    private const RECEIPT_MODULE_ID = 'prospektweb.calc';
    private const RECEIPT_PREFIX_CONTRACTS = [
        'CATALOG_WRITE_RECEIPT_' => self::RECEIPT_CONTRACT,
        'CATALOG_BATCH_RECEIPT_' => self::BATCH_RECEIPT_CONTRACT,
    ];

    /**
     * Every ConfigManager option that can redirect preset/catalog/runtime
     * reads. Missing rows are part of the snapshot as explicit null values.
     */
    private const RUNTIME_CONFIG_OPTION_KEYS = [
        'prospektweb.calc' => [
            'CALC_SERVER_URL',
            'PRODUCT_IBLOCK_ID',
            'SKU_IBLOCK_ID',
            'IBLOCK_CALC_PRESETS',
            'IBLOCK_CALC_STAGES',
            'IBLOCK_CALC_SETTINGS',
            'IBLOCK_CALC_GLOBAL_VALUES',
            'IBLOCK_CALC_CUSTOM_FIELDS',
            'IBLOCK_CALC_MATERIALS',
            'IBLOCK_CALC_MATERIALS_VARIANTS',
            'IBLOCK_CALC_OPERATIONS',
            'IBLOCK_CALC_OPERATIONS_VARIANTS',
            'IBLOCK_CALC_EQUIPMENT',
            'IBLOCK_CALC_DETAILS',
        ],
        'prospektweb.frontcalc' => [
            'PRODUCTS_IBLOCK_ID',
            'OFFERS_IBLOCK_ID',
            'IBLOCK_CALC_PRESETS',
            'IBLOCK_CALC_STAGES',
            'IBLOCK_CALC_SETTINGS',
            'IBLOCK_CALC_GLOBAL_VALUES',
            'IBLOCK_CALC_CUSTOM_FIELDS',
            'IBLOCK_CALC_MATERIALS',
            'IBLOCK_CALC_MATERIALS_VARIANTS',
            'IBLOCK_CALC_OPERATIONS',
            'IBLOCK_CALC_OPERATIONS_VARIANTS',
            'IBLOCK_CALC_EQUIPMENT',
            'IBLOCK_CALC_DETAILS',
        ],
    ];

    /** @var array<string,callable> */
    private array $adapters;

    /** @var mixed */
    private $transactionConnection;

    /** @var array<string,string|null>|null */
    private ?array $lockedRuntimeConfigSnapshot = null;

    private ?BatchRecalculateService $batchRecalculateService = null;

    /** @param array<string,callable> $adapters */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /**
     * Read ConfigManager authority directly from b_option, bypassing both
     * Bitrix Option cache and process-static ConfigManager caches.
     *
     * @return array<string,string|null>
     */
    public function captureRuntimeConfigSnapshot(): array
    {
        if ($this->lockedRuntimeConfigSnapshot !== null) {
            return $this->lockedRuntimeConfigSnapshot;
        }
        if (isset($this->adapters['capture_runtime_config'])) {
            $snapshot = call_user_func($this->adapters['capture_runtime_config']);
            return $this->normalizeRuntimeConfigSnapshot(is_array($snapshot) ? $snapshot : []);
        }

        $conditions = [];
        foreach (self::RUNTIME_CONFIG_OPTION_KEYS as $moduleId => $names) {
            foreach ($names as $name) {
                $conditions[] = "(MODULE_ID='" . $moduleId . "' AND NAME='" . $name . "')";
            }
        }
        $connection = $this->transactionConnection ?? Application::getConnection();
        $result = $connection->query(
            'SELECT MODULE_ID, NAME, VALUE, SITE_ID FROM b_option WHERE (' . implode(' OR ', $conditions)
            . ") AND (SITE_ID IS NULL OR SITE_ID='') ORDER BY MODULE_ID, NAME, SITE_ID"
        );
        $rows = [];
        while (is_object($result) && method_exists($result, 'fetch') && ($row = $result->fetch())) {
            $rows[] = $row;
        }
        return $this->runtimeConfigSnapshotFromRows($rows);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,string|null>
     */
    private function runtimeConfigSnapshotFromRows(array $rows): array
    {
        $snapshot = [];
        foreach (self::RUNTIME_CONFIG_OPTION_KEYS as $moduleId => $names) {
            foreach ($names as $name) {
                $snapshot[$moduleId . ':' . $name] = null;
            }
        }
        foreach ($rows as $row) {
            $moduleId = (string)($row['MODULE_ID'] ?? $row['module_id'] ?? '');
            $actualName = (string)($row['NAME'] ?? $row['name'] ?? '');
            // Bitrix option names in long-lived installations may be stored in
            // lowercase even though current ConfigManager constants are
            // uppercase. MySQL compares NAME case-insensitively here, so bind
            // every returned row to the canonical allowlist key explicitly.
            // Preserve all other bytes: whitespace aliases and an unexpected
            // module id must still fail closed.
            $key = $moduleId . ':' . strtoupper($actualName);
            if (!array_key_exists($key, $snapshot)) {
                throw new \RuntimeException('Unexpected runtime config option row.');
            }
            if ($snapshot[$key] !== null) {
                throw new \RuntimeException('Duplicate global runtime config option row.', 409);
            }
            $snapshot[$key] = (string)($row['VALUE'] ?? $row['value'] ?? '');
        }
        return $this->normalizeRuntimeConfigSnapshot($snapshot);
    }

    /**
     * @param int[] $offerIds
     * @param array<int,array<string,mixed>> $offerResults
     * @return array<string,mixed>
     */
    public function preview(
        int $presetId,
        array $offerIds,
        array $offerResults,
        string $siteId
    ): array {
        $this->assertPreset($presetId);
        $normalizedOfferIds = $this->normalizeOfferIds($offerIds);
        $siteId = $this->normalizeSiteId($siteId);
        $authoritativeCalculation = $this->calculateAuthoritativeResults($normalizedOfferIds, $siteId);

        return self::publicPreview(
            $this->buildPreview(
                $normalizedOfferIds,
                $authoritativeCalculation,
                $offerResults,
                $siteId,
                true,
                false
            )
        );
    }

    /**
     * @param int[] $offerIds
     * @param array<int,array<string,mixed>> $offerResults
     * @return array<string,mixed>
     */
    public function apply(
        int $presetId,
        array $offerIds,
        array $offerResults,
        string $siteId,
        string $expectedFingerprint,
        int $actorUserId
    ): array {
        $this->assertPreset($presetId);
        $normalizedOfferIds = $this->normalizeOfferIds($offerIds);
        $siteId = $this->normalizeSiteId($siteId);
        $expectedFingerprint = strtolower(trim($expectedFingerprint));
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedFingerprint) !== 1) {
            throw new \InvalidArgumentException('Передан некорректный отпечаток предпросмотра записи.');
        }
        if ($actorUserId <= 0) {
            throw new \InvalidArgumentException('Для записи каталога требуется авторизованный пользователь.');
        }

        $receiptName = $this->receiptName($actorUserId, $expectedFingerprint);
        $replayed = $this->tryReplayReceipt(
            $receiptName,
            $actorUserId,
            $normalizedOfferIds,
            $siteId,
            $expectedFingerprint
        );
        if (is_array($replayed)) {
            return $replayed;
        }

        // Remote calculation deliberately happens before any adapter/catalog
        // lock or DB transaction. The preflight binds the fresh server result
        // to the exact previewed catalog/runtime state.
        $authoritativeCalculation = $this->calculateAuthoritativeResults($normalizedOfferIds, $siteId);
        $preflightPreview = $this->buildPreview(
            $normalizedOfferIds,
            $authoritativeCalculation,
            $offerResults,
            $siteId,
            true,
            false
        );
        if (!hash_equals($expectedFingerprint, (string)$preflightPreview['fingerprint'])) {
            $replayed = $this->tryReplayReceipt(
                $receiptName,
                $actorUserId,
                $normalizedOfferIds,
                $siteId,
                $expectedFingerprint
            );
            if (is_array($replayed)) {
                return $replayed;
            }
            throw new \RuntimeException(
                'Каталог, расчёт calc-server, публикация формы или адаптер изменились после предпросмотра. Выполните предпросмотр заново.',
                409
            );
        }

        return $this->withAdapterMutationLock(function () use (
            $normalizedOfferIds,
            $authoritativeCalculation,
            $offerResults,
            $siteId,
            $expectedFingerprint,
            $preflightPreview,
            $actorUserId,
            $receiptName
        ): array {
            return $this->applyUnderLocks(
                $normalizedOfferIds,
                $authoritativeCalculation,
                $offerResults,
                $siteId,
                $expectedFingerprint,
                is_array($preflightPreview['_productIds'] ?? null)
                    ? $preflightPreview['_productIds']
                    : [],
                $actorUserId,
                $receiptName
            );
        });
    }

    /**
     * Capture the complete writable catalog state (purchase price/currency,
     * four dimensions and every price row) for preview/start CAS.
     *
     * @param int[] $offerIds
     * @return array<int,array{catalog:string}>
     */
    public function captureCatalogWriteStateFingerprints(
        int $presetId,
        array $offerIds,
        string $siteId
    ): array {
        $this->assertPreset($presetId);
        $offerIds = $this->normalizeOfferIds($offerIds);
        $siteId = $this->normalizeSiteId($siteId);
        $resolved = $this->validateResolvedPayload(
            $this->resolveRuntime($offerIds, $siteId),
            $offerIds
        );

        $fingerprints = [];
        foreach ($resolved['selectedOffers'] as $offer) {
            $offerId = (int)($offer['id'] ?? 0);
            if ($offerId > 0) {
                $fingerprints[$offerId] = [
                    'catalog' => hash('sha256', self::canonicalEncode($this->catalogStateFromOffer($offer))),
                ];
            }
        }
        ksort($fingerprints, SORT_NUMERIC);
        if (array_map('intval', array_keys($fingerprints)) !== $offerIds) {
            throw new \RuntimeException('Не удалось подтвердить состояние записи всех выбранных ТП.');
        }
        return $fingerprints;
    }

    /**
     * Validate and save the preset 12740 catalog adapter as one atomic
     * operation. The candidate is resolved against the current publication
     * and selected catalog targets, then written and resolved again before
     * commit. A failed post-write validation therefore rolls the option row
     * back instead of leaving an incompatible adapter behind.
     *
     * @param int[] $offerIds
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    public function saveValidatedAdapter(
        int $presetId,
        array $offerIds,
        string $siteId,
        string $expectedRevision,
        array $definition
    ): array {
        $this->assertPreset($presetId);
        $offerIds = $this->normalizeOptionalOfferIds($offerIds);
        $siteId = $this->normalizeSiteId($siteId);
        $expectedRevision = strtolower(trim($expectedRevision));
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedRevision) !== 1) {
            throw new \InvalidArgumentException('Catalog adapter expected revision is invalid.');
        }

        $adapterService = new CatalogAdapterDefinitionService();
        $candidate = $adapterService->normalizeCandidate($presetId, $definition);
        $preflightNeutralInputState = $this->captureAdapterAuthoringNeutralInputState();
        $preflightProductIds = [];
        if ($offerIds !== []) {
            // This read-only pass identifies the finite parent rows that must
            // be locked. It deliberately resolves with the candidate, so an
            // incomplete currently stored adapter cannot prevent its repair.
            $preflight = $this->resolveRuntimeWithAdapter($offerIds, $siteId, $candidate);
            $preflightResolved = $this->validateResolvedPayload($preflight, $offerIds, false);
            if ($preflightResolved['neutralInputRequired'] !== ($preflightNeutralInputState === 'Y')) {
                throw new \RuntimeException(
                    'Neutral-input activation changed while resolving the adapter candidate.',
                    409
                );
            }
            $this->assertAdapterPreviewMatchesPayload($preflight, $candidate);
            $preflightProductIds = $preflightResolved['productIds'];
        }

        return $this->withAdapterMutationLock(function () use (
            $presetId,
            $offerIds,
            $siteId,
            $expectedRevision,
            $candidate,
            $preflightProductIds,
            $preflightNeutralInputState,
            $adapterService
        ): array {
            $transactionStarted = false;
            try {
                $this->beginTransaction();
                $transactionStarted = true;
                $this->lockRuntimeOptionRows();
                if ($offerIds !== []) {
                    $this->lockCatalogRows($offerIds, $preflightProductIds);
                }
                $lockedNeutralInputState = $this->readLockedNeutralInputState();
                if ($lockedNeutralInputState !== $preflightNeutralInputState) {
                    throw new \RuntimeException(
                        'Neutral-input activation changed before adapter save.',
                        409
                    );
                }

                $currentRaw = $this->readLockedOptionValue(
                    'prospektweb.calc',
                    'CATALOG_ADAPTER_12740'
                );
                $prepared = $adapterService->prepareSaveFromRaw(
                    $presetId,
                    $expectedRevision,
                    $candidate,
                    $currentRaw
                );
                $candidate = $prepared['definition'];

                if ($offerIds !== []) {
                    $lockedPayload = $this->resolveRuntimePinned($offerIds, $siteId, $candidate, true);
                    $lockedResolved = $this->validateResolvedPayload($lockedPayload, $offerIds, false);
                    if ($lockedResolved['productIds'] !== $preflightProductIds) {
                        throw new \RuntimeException(
                            'Catalog offer parent membership changed before adapter save.',
                            409
                        );
                    }
                    $this->assertAdapterPreviewMatchesPayload($lockedPayload, $candidate);
                } else {
                    $lockedAuthoring = $this->readLockedPublishedAuthoring();
                    $this->assertAdapterPreviewReady([], $lockedAuthoring, $candidate);
                    $lockedPayload = [];
                }

                $this->writeLockedAdapterOptionRaw((string)$prepared['raw']);
                $readBackRaw = $this->readLockedOptionValue(
                    'prospektweb.calc',
                    'CATALOG_ADAPTER_12740'
                );
                $saved = $adapterService->loadFromRaw($presetId, $readBackRaw);
                if (!hash_equals((string)$candidate['revision'], (string)$saved['revision'])) {
                    throw new \RuntimeException('Catalog adapter transactional readback revision mismatch.');
                }

                // Resolve from the just-written raw row again while every
                // catalog/publication/config authority row is still locked.
                if ($offerIds !== []) {
                    $freshPayload = $this->resolveRuntimePinned($offerIds, $siteId, null, true);
                    $freshResolved = $this->validateResolvedPayload($freshPayload, $offerIds, false);
                    if ($freshResolved['productIds'] !== $preflightProductIds
                        || $freshResolved['adapterPersisted'] !== true
                        || !hash_equals(
                            (string)$saved['revision'],
                            (string)$freshResolved['adapterRevision']
                        )) {
                        throw new \RuntimeException(
                            'Catalog adapter readback no longer matches the locked launch targets.',
                            409
                        );
                    }
                    $freshPreview = $this->assertAdapterPreviewMatchesPayload($freshPayload, $saved);
                    $freshRuntime = is_array($freshPayload['editorRuntime'] ?? null)
                        ? $freshPayload['editorRuntime']
                        : [];
                } else {
                    $freshAuthoring = $this->readLockedPublishedAuthoring();
                    $freshPreview = $this->assertAdapterPreviewReady([], $freshAuthoring, $saved);
                    $freshRuntime = $this->buildManualAdapterRuntime(
                        $freshAuthoring,
                        $saved,
                        $freshPreview
                    );
                    // The bridge merges this read-only delta into its current
                    // INIT instead of replacing the standalone preset payload.
                    $freshPayload = [
                        'presetId' => $presetId,
                        'selectedOffers' => [],
                        'editorRuntime' => $freshRuntime,
                        '_neutralInputRequired' => ($freshAuthoring['neutralInputRequired'] ?? null) === true,
                        '_runtimeConfigSnapshot' => $freshAuthoring['runtimeConfigSnapshot'],
                    ];
                }

                $freshCatalogMapping = is_array($freshRuntime['catalogMapping'] ?? null)
                    ? $freshRuntime['catalogMapping']
                    : [];
                if (($freshCatalogMapping['adapterPersisted'] ?? null) !== true
                    || !hash_equals(
                        (string)$saved['revision'],
                        (string)($freshCatalogMapping['adapterRevision'] ?? '')
                    )) {
                    throw new \RuntimeException(
                        'Catalog adapter save did not return a persisted matching runtime revision.',
                        409
                    );
                }

                $response = [
                    'catalogAdapter' => $saved,
                    'catalogScenarios' => is_array($freshPreview['scenarios'] ?? null)
                        ? $freshPreview['scenarios']
                        : [],
                    'editorRuntime' => $freshRuntime,
                    'initData' => $freshPayload,
                ];
                $this->commitTransaction();
                $transactionStarted = false;
                return $response;
            } catch (\Throwable $error) {
                if ($transactionStarted) {
                    try {
                        $this->rollbackTransaction();
                    } catch (\Throwable $rollbackError) {
                        error_log('Catalog adapter validated save rollback failed: ' . $rollbackError->getMessage());
                    }
                }
                throw $error;
            }
        });
    }

    /**
     * Trusted background-job boundary. The network calculation is supplied by
     * BatchRecalculateService and has already completed; this method compares
     * it with the operator-reviewed result and performs only snapshot/lock/CAS
     * work before writing.
     *
     * @param int[] $offerIds
     * @param array<string,mixed> $authoritativeCalculation
     * @param array<int|string,array<string,mixed>> $expectedStateFingerprints
     * @param array<int|string,string> $expectedResultFingerprints
     * @return array<string,mixed>
     */
    public function applyAuthoritativeBatch(
        int $presetId,
        array $offerIds,
        array $authoritativeCalculation,
        string $siteId,
        array $expectedStateFingerprints,
        array $expectedResultFingerprints,
        bool $onlyChanged = false,
        int $actorUserId = 0,
        string $requestId = ''
    ): array {
        $this->assertPreset($presetId);
        $offerIds = $this->normalizeOfferIds($offerIds);
        $siteId = $this->normalizeSiteId($siteId);
        $requestId = $this->normalizeBatchRequestId($actorUserId, $requestId);
        $approvedStates = $this->normalizeApprovedWriteStates($expectedStateFingerprints, $offerIds);
        $approvedResults = $this->normalizeResultFingerprints($expectedResultFingerprints, $offerIds);
        $receiptName = $this->batchReceiptName($actorUserId, $requestId);
        $replayed = $this->tryReplayBatchReceipt(
            $receiptName,
            $actorUserId,
            $requestId,
            $offerIds,
            $siteId,
            $approvedStates,
            $approvedResults
        );
        if (is_array($replayed)) {
            return $replayed;
        }

        $authoritative = $this->validateAuthoritativeCalculation($authoritativeCalculation, $offerIds);
        if (!hash_equals(
            self::canonicalEncode($authoritative['stateFingerprints']),
            self::canonicalEncode($this->calculationStatesOnly($approvedStates))
        )) {
            throw new \RuntimeException('Свежий расчёт выполнен не по подтверждённому состоянию preview.', 409);
        }
        $preflight = $this->buildPreview($offerIds, $authoritative, [], $siteId, true, false);
        $this->assertApprovedBatchSnapshot($preflight, $approvedStates, $approvedResults);

        return $this->withAdapterMutationLock(function () use (
            $offerIds,
            $authoritative,
            $siteId,
            $approvedStates,
            $approvedResults,
            $preflight,
            $onlyChanged,
            $actorUserId,
            $requestId,
            $receiptName
        ): array {
            $transactionStarted = false;
            try {
                $this->beginTransaction();
                $transactionStarted = true;
                $this->lockRuntimeOptionRows($authoritative['provenance']['runtimeConfigSnapshot']);
                $existingReceipt = $this->loadReceipt($receiptName, true);
                $this->lockCatalogRows(
                    $offerIds,
                    is_array($preflight['_productIds'] ?? null) ? $preflight['_productIds'] : []
                );
                $this->lockRuntimeSourceRows($authoritative['provenance']['runtimeLocks']);
                if (is_array($existingReceipt)) {
                    $response = $this->validateBatchReplayReceiptUnderLocks(
                        $existingReceipt,
                        $actorUserId,
                        $requestId,
                        $offerIds,
                        $siteId,
                        $approvedStates,
                        $approvedResults
                    );
                    $this->commitTransaction();
                    $transactionStarted = false;
                    return $response;
                }
                $locked = $this->buildPreview($offerIds, $authoritative, [], $siteId, true, true);
                $this->assertApprovedBatchSnapshot($locked, $approvedStates, $approvedResults);

                if ($onlyChanged && (int)($locked['summary']['changedFields'] ?? -1) === 0) {
                    $response = [
                        'contract' => self::BATCH_APPLY_CONTRACT,
                        'status' => 'ok',
                        'replayed' => false,
                        'updated' => 0,
                        'errors' => [],
                        'offers' => array_map(static function (int $offerId): array {
                            return ['offerId' => $offerId, 'status' => 'skipped'];
                        }, $offerIds),
                    ];
                    $this->saveBatchReceipt(
                        $receiptName,
                        $actorUserId,
                        $requestId,
                        $offerIds,
                        $siteId,
                        $approvedStates,
                        $approvedResults,
                        $locked,
                        $response
                    );
                    $this->commitTransaction();
                    $transactionStarted = false;
                    return $response;
                }

                $writeResult = $this->writeProjectedResults($locked['_projectedResults']);
                if (($writeResult['status'] ?? 'error') !== 'ok'
                    || (int)($writeResult['updated'] ?? -1) !== count($offerIds)
                    || !empty($writeResult['errors'])) {
                    throw new \RuntimeException('Пакетная запись каталога вернула неполный результат.');
                }

                $verified = $this->buildPreview($offerIds, $authoritative, [], $siteId, false, true);
                if ((int)($verified['summary']['changedFields'] ?? -1) !== 0) {
                    throw new \RuntimeException('Проверка пакетной записи цен и габаритов не прошла.');
                }
                $writeResult['contract'] = self::BATCH_APPLY_CONTRACT;
                $writeResult['replayed'] = false;
                $this->saveBatchReceipt(
                    $receiptName,
                    $actorUserId,
                    $requestId,
                    $offerIds,
                    $siteId,
                    $approvedStates,
                    $approvedResults,
                    $verified,
                    $writeResult
                );
                $this->commitTransaction();
                $transactionStarted = false;
                return $writeResult;
            } catch (\Throwable $error) {
                if ($transactionStarted) {
                    try {
                        $this->rollbackTransaction();
                    } catch (\Throwable $rollbackError) {
                        error_log('Catalog batch write rollback failed: ' . $rollbackError->getMessage());
                    }
                }
                throw $error;
            }
        });
    }

    /**
     * Probe the durable batch receipt before a retry performs any network
     * calculation. Null means no completed transaction exists for this exact
     * authenticated job chunk.
     *
     * @param int[] $offerIds
     * @param array<int|string,array<string,mixed>> $expectedStateFingerprints
     * @param array<int|string,string> $expectedResultFingerprints
     * @return array<string,mixed>|null
     */
    public function replayAuthoritativeBatch(
        int $presetId,
        array $offerIds,
        string $siteId,
        array $expectedStateFingerprints,
        array $expectedResultFingerprints,
        int $actorUserId,
        string $requestId
    ): ?array {
        $this->assertPreset($presetId);
        $offerIds = $this->normalizeOfferIds($offerIds);
        $siteId = $this->normalizeSiteId($siteId);
        $requestId = $this->normalizeBatchRequestId($actorUserId, $requestId);
        $approvedStates = $this->normalizeApprovedWriteStates($expectedStateFingerprints, $offerIds);
        $approvedResults = $this->normalizeResultFingerprints($expectedResultFingerprints, $offerIds);

        return $this->tryReplayBatchReceipt(
            $this->batchReceiptName($actorUserId, $requestId),
            $actorUserId,
            $requestId,
            $offerIds,
            $siteId,
            $approvedStates,
            $approvedResults
        );
    }

    /**
     * @param int[] $offerIds
     * @param array<int,array<string,mixed>> $offerResults
     * @return array<string,mixed>
     */
    private function applyUnderLocks(
        array $offerIds,
        array $authoritativeCalculation,
        array $offerResults,
        string $siteId,
        string $expectedFingerprint,
        array $productIds,
        int $actorUserId,
        string $receiptName
    ): array {
        $normalizedCalculation = $this->validateAuthoritativeCalculation(
            $authoritativeCalculation,
            $offerIds
        );
        $transactionStarted = false;
        try {
            $this->beginTransaction();
            $transactionStarted = true;
            $this->lockRuntimeOptionRows($normalizedCalculation['provenance']['runtimeConfigSnapshot']);
            $existingReceipt = $this->loadReceipt($receiptName, true);
            $this->lockCatalogRows($offerIds, $productIds);
            $this->lockRuntimeSourceRows($normalizedCalculation['provenance']['runtimeLocks']);
            if (is_array($existingReceipt)) {
                $response = $this->validateReplayReceiptUnderLocks(
                    $existingReceipt,
                    $actorUserId,
                    $offerIds,
                    $siteId,
                    $expectedFingerprint
                );
                $this->commitTransaction();
                $transactionStarted = false;
                return $response;
            }

            // Resolve and project again only after offer, parent, input and
            // publication rows are locked. No network call occurs here.
            $lockedPreview = $this->buildPreview(
                $offerIds,
                $authoritativeCalculation,
                $offerResults,
                $siteId,
                true,
                true
            );
            if (!hash_equals($expectedFingerprint, (string)$lockedPreview['fingerprint'])) {
                throw new \RuntimeException(
                    'Каталог, публикация формы или адаптер изменились после предпросмотра. Выполните предпросмотр заново.',
                    409
                );
            }

            $writeResult = $this->writeProjectedResults($lockedPreview['_projectedResults']);
            if (($writeResult['status'] ?? 'error') !== 'ok'
                || (int)($writeResult['updated'] ?? -1) !== count($offerIds)
                || !empty($writeResult['errors'])) {
                $firstError = is_array($writeResult['errors'][0] ?? null)
                    ? (string)($writeResult['errors'][0]['message'] ?? '')
                    : '';
                throw new \RuntimeException(
                    $firstError !== ''
                        ? 'Запись каталога отменена: ' . $firstError
                        : 'Не все торговые предложения были записаны; транзакция отменена.'
                );
            }

            // Read through the same resolver before commit, reusing the
            // precomputed authoritative result. Catalog outputs changed by the
            // write are intentionally not re-sent to calc-server.
            $verifiedPreview = $this->buildPreview(
                $offerIds,
                $authoritativeCalculation,
                $offerResults,
                $siteId,
                false,
                true
            );
            if ((string)$verifiedPreview['adapterRevision'] !== (string)$lockedPreview['adapterRevision']
                || self::canonicalEncode($verifiedPreview['publication'])
                    !== self::canonicalEncode($lockedPreview['publication'])) {
                throw new \RuntimeException(
                    'Публикация формы или адаптер изменились во время записи; транзакция отменена.',
                    409
                );
            }
            if ((int)($verifiedPreview['summary']['changedFields'] ?? -1) !== 0) {
                throw new \RuntimeException('Проверка записанных цен и габаритов не прошла; транзакция отменена.');
            }

            $response = [
                'contract' => self::APPLY_CONTRACT,
                'presetId' => CatalogAdapterDefinitionService::PRESET_ID,
                'applied' => true,
                'replayed' => false,
                'fingerprint' => $expectedFingerprint,
                'catalogFingerprintAfter' => (string)$verifiedPreview['fingerprint'],
                'publication' => $lockedPreview['publication'],
                'adapterRevision' => $lockedPreview['adapterRevision'],
                'calculation' => $lockedPreview['calculation'],
                'summary' => [
                    'total' => count($offerIds),
                    'updated' => (int)$writeResult['updated'],
                ],
                'offers' => is_array($writeResult['offers'] ?? null) ? $writeResult['offers'] : [],
            ];
            $this->saveReceipt($receiptName, [
                'contract' => self::RECEIPT_CONTRACT,
                'actorUserId' => $actorUserId,
                'presetId' => CatalogAdapterDefinitionService::PRESET_ID,
                'siteId' => $siteId,
                'offerIds' => $offerIds,
                'productIds' => is_array($verifiedPreview['_productIds'] ?? null)
                    ? $verifiedPreview['_productIds']
                    : [],
                'expectedFingerprint' => $expectedFingerprint,
                'targetStateFingerprint' => hash(
                    'sha256',
                    self::canonicalEncode($verifiedPreview['_catalogStateFingerprints'] ?? [])
                ),
                'resultFingerprint' => hash(
                    'sha256',
                    self::canonicalEncode($lockedPreview['_batchResultFingerprints'] ?? [])
                ),
                'response' => $response,
            ]);

            $this->commitTransaction();
            $transactionStarted = false;
            return $response;
        } catch (\Throwable $error) {
            if ($transactionStarted) {
                try {
                    $this->rollbackTransaction();
                } catch (\Throwable $rollbackError) {
                    error_log('Catalog calculation write rollback failed: ' . $rollbackError->getMessage());
                }
            }
            throw $error;
        }
    }

    /**
     * @param int[] $offerIds
     * @param array<int,array<string,mixed>> $offerResults
     * @return array<string,mixed>
     */
    private function buildPreview(
        array $offerIds,
        array $authoritativeCalculation,
        array $clientOfferResults,
        string $siteId,
        bool $verifyCalculationState,
        bool $usePinnedRuntime
    ): array
    {
        $authoritative = $this->validateAuthoritativeCalculation($authoritativeCalculation, $offerIds);
        $payload = $usePinnedRuntime
            ? $this->resolveRuntimePinned($offerIds, $siteId)
            : $this->resolveRuntime($offerIds, $siteId);
        if ($verifyCalculationState) {
            $this->assertCalculationStateMatches(
                $authoritative['stateFingerprints'],
                $offerIds,
                $siteId,
                $usePinnedRuntime ? $payload : null
            );
        }
        $resolved = $this->validateResolvedPayload($payload, $offerIds);
        $this->assertCalculationProvenanceMatchesRuntime($authoritative['provenance'], $resolved);
        $projectedResults = $this->projectResults(
            $authoritative['results'],
            $resolved['priceTypes'],
            $resolved['adapter'],
            $resolved['publication']
        );
        $this->assertResultAllowlist($projectedResults, $offerIds, 'проекции адаптера');

        $validation = $this->validateProjectedResults($projectedResults, $offerIds);
        if (($validation['ready'] ?? false) !== true) {
            $firstError = is_array($validation['errors'][0] ?? null)
                ? trim((string)($validation['errors'][0]['message'] ?? ''))
                : '';
            throw new \InvalidArgumentException(
                $firstError !== ''
                    ? $firstError
                    : 'Результат расчёта не содержит полный набор положительных цен, веса и габаритов.'
            );
        }

        $currentById = [];
        $offerNames = [];
        $catalogStateFingerprints = [];
        foreach ($resolved['selectedOffers'] as $offer) {
            $offerId = (int)$offer['id'];
            $currentById[$offerId] = $this->catalogStateFromOffer($offer);
            $catalogStateFingerprints[$offerId] = hash(
                'sha256',
                self::canonicalEncode($currentById[$offerId])
            );
            $offerNames[$offerId] = trim((string)($offer['name'] ?? ''));
        }
        ksort($catalogStateFingerprints, SORT_NUMERIC);
        $projectedById = [];
        foreach ($projectedResults as $projectedResult) {
            $projectedById[(int)$projectedResult['offerId']] = $projectedResult;
        }

        $offers = [];
        $catalogState = [];
        $normalizedProjected = [];
        $changedOffers = 0;
        $changedFields = 0;
        foreach ($offerIds as $offerId) {
            $current = $currentById[$offerId];
            $target = $this->catalogTargetFromProjected($projectedById[$offerId], $current);
            $fieldDiffs = $this->buildFieldDiffs($current, $target);
            $offerChangedFields = count(array_filter($fieldDiffs, static function (array $diff): bool {
                return !empty($diff['changed']);
            }));
            if ($offerChangedFields > 0) {
                $changedOffers++;
            }
            $changedFields += $offerChangedFields;
            $offers[] = [
                'offerId' => $offerId,
                'name' => $offerNames[$offerId] !== '' ? $offerNames[$offerId] : ('ТП #' . $offerId),
                'changed' => $offerChangedFields > 0,
                'changedFields' => $offerChangedFields,
                'diff' => $fieldDiffs,
            ];
            $catalogState[] = $current;
            $normalizedProjected[] = $this->normalizeProjectedResult($projectedById[$offerId]);
        }

        $fingerprintPayload = [
            'contract' => self::FINGERPRINT_CONTRACT,
            'presetId' => CatalogAdapterDefinitionService::PRESET_ID,
            'offerIds' => $offerIds,
            'publication' => $resolved['publication'],
            'adapterRevision' => $resolved['adapterRevision'],
            'catalogScenarios' => $resolved['catalogScenarios'],
            'catalogState' => $catalogState,
            'projectedResults' => $normalizedProjected,
            'calculation' => [
                'stateFingerprints' => $authoritative['stateFingerprints'],
                'provenance' => $authoritative['provenance'],
            ],
        ];

        $clientComparison = $this->compareClientResults(
            $clientOfferResults,
            $projectedResults,
            $offerIds,
            $resolved
        );

        return [
            'contract' => self::PREVIEW_CONTRACT,
            'presetId' => CatalogAdapterDefinitionService::PRESET_ID,
            'ready' => true,
            'offerIds' => $offerIds,
            'publication' => $resolved['publication'],
            'adapterRevision' => $resolved['adapterRevision'],
            'calculation' => $authoritative['provenance'],
            'clientResultComparison' => $clientComparison,
            'fingerprint' => hash('sha256', self::canonicalEncode($fingerprintPayload)),
            'summary' => [
                'total' => count($offerIds),
                'changedOffers' => $changedOffers,
                'unchangedOffers' => count($offerIds) - $changedOffers,
                'changedFields' => $changedFields,
            ],
            'offers' => $offers,
            // Private in-process handoff to apply(); endpoint serializers remove
            // it from ordinary preview responses via publicPreview().
            '_projectedResults' => $projectedResults,
            '_productIds' => $resolved['productIds'],
            '_catalogStateFingerprints' => $catalogStateFingerprints,
            '_batchResultFingerprints' => BatchPreviewFingerprintService::resultFingerprints($validation),
        ];
    }

    /** Remove the internal write handoff before returning a preview to a client. */
    public static function publicPreview(array $preview): array
    {
        unset(
            $preview['_projectedResults'],
            $preview['_productIds'],
            $preview['_catalogStateFingerprints'],
            $preview['_batchResultFingerprints']
        );
        return $preview;
    }

    /**
     * @param array<string,mixed> $payload
     * @param int[] $offerIds
     * @return array<string,mixed>
     */
    private function validateResolvedPayload(
        array $payload,
        array $offerIds,
        bool $requireNeutralInputActive = true
    ): array
    {
        if ((int)($payload['presetId'] ?? 0) !== CatalogAdapterDefinitionService::PRESET_ID) {
            throw new \RuntimeException('Сервер разрешил торговые предложения не в пресет 12740.');
        }
        $runtime = is_array($payload['editorRuntime'] ?? null) ? $payload['editorRuntime'] : [];
        $launch = is_array($runtime['launchContext'] ?? null) ? $runtime['launchContext'] : [];
        $publication = is_array($runtime['publication'] ?? null) ? $runtime['publication'] : [];
        $adapter = is_array($runtime['catalogAdapter'] ?? null) ? $runtime['catalogAdapter'] : [];
        $catalogMapping = is_array($runtime['catalogMapping'] ?? null)
            ? $runtime['catalogMapping']
            : [];
        $adapterRevision = strtolower(trim((string)($adapter['revision'] ?? '')));
        $mappingAdapterRevision = strtolower(trim((string)($catalogMapping['adapterRevision'] ?? '')));
        $publishedSnapshot = is_array($payload['_publishedSnapshot'] ?? null)
            ? $payload['_publishedSnapshot']
            : [];
        $snapshotMeta = is_array($publishedSnapshot['_form_first'] ?? null)
            ? $publishedSnapshot['_form_first']
            : [];
        $runtimeConfigSnapshot = is_array($payload['_runtimeConfigSnapshot'] ?? null)
            ? $this->normalizeRuntimeConfigSnapshot($payload['_runtimeConfigSnapshot'])
            : [];
        if ((string)($runtime['contract'] ?? '') !== CatalogAdapterDefinitionService::EDITOR_RUNTIME_CONTRACT
            || (string)($launch['contract'] ?? '') !== CatalogAdapterDefinitionService::LAUNCH_CONTEXT_CONTRACT
            || (string)($launch['mode'] ?? '') !== 'catalog'
            || (int)($launch['presetId'] ?? 0) !== CatalogAdapterDefinitionService::PRESET_ID
            || (int)($publication['revision'] ?? 0) <= 0
            || preg_match('/^[a-f0-9]{64}$/D', (string)($publication['compileHash'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $adapterRevision) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $mappingAdapterRevision) !== 1
            || !hash_equals($adapterRevision, $mappingAdapterRevision)
            || !array_key_exists('adapterPersisted', $catalogMapping)
            || !is_bool($catalogMapping['adapterPersisted'])) {
            throw new \RuntimeException('Текущий editorRuntime каталога не прошёл проверку целостности.');
        }
        if ($requireNeutralInputActive && $catalogMapping['adapterPersisted'] !== true) {
            throw new \RuntimeException(
                'Catalog calculation requires an explicitly persisted adapter.',
                409
            );
        }

        if ($publishedSnapshot === []
            || (int)($snapshotMeta['publishedRevision'] ?? 0) !== (int)$publication['revision']
            || !hash_equals(
                (string)$publication['compileHash'],
                (string)($snapshotMeta['compileHash'] ?? '')
            )
            || !array_key_exists('_neutralInputRequired', $payload)
            || ($requireNeutralInputActive
                ? $payload['_neutralInputRequired'] !== true
                : !is_bool($payload['_neutralInputRequired']))
            || !is_array($payload['_globalSymbols'] ?? null)
            || (int)($payload['_globalSymbolIblockId'] ?? 0) <= 0
            || $runtimeConfigSnapshot === []) {
            throw new \RuntimeException('The catalog runtime pins do not match the published form snapshot.');
        }

        $launchOfferIds = $this->normalizeOfferIds(is_array($launch['offerIds'] ?? null) ? $launch['offerIds'] : []);
        if ($launchOfferIds !== $offerIds) {
            throw new \RuntimeException('Текущий editorRuntime разрешил другой набор торговых предложений.');
        }

        $selectedOffers = is_array($payload['selectedOffers'] ?? null) ? $payload['selectedOffers'] : [];
        $selectedIds = [];
        foreach ($selectedOffers as $offer) {
            if (!is_array($offer)) {
                throw new \RuntimeException('Сервер вернул некорректное торговое предложение.');
            }
            $selectedIds[] = (int)($offer['id'] ?? 0);
        }
        if ($this->normalizeOfferIds($selectedIds) !== $offerIds) {
            throw new \RuntimeException('Текущий каталог не содержит точный запрошенный набор торговых предложений.');
        }

        $scenarioIds = [];
        $productIds = [];
        $normalizedScenarios = [];
        $scenarios = is_array($runtime['catalogScenarios'] ?? null) ? $runtime['catalogScenarios'] : [];
        foreach ($scenarios as $scenario) {
            if (!is_array($scenario)) {
                throw new \RuntimeException('CatalogScenario содержит некорректную запись.');
            }
            $target = is_array($scenario['target'] ?? null) ? $scenario['target'] : [];
            $values = is_array($scenario['values'] ?? null) ? $scenario['values'] : null;
            $scenarioOfferId = (int)($target['offerId'] ?? 0);
            if ((string)($scenario['contract'] ?? '') !== CatalogAdapterDefinitionService::SCENARIO_CONTRACT
                || (string)($scenario['scenarioId'] ?? '') !== 'offer:' . $scenarioOfferId
                || (int)($scenario['presetId'] ?? 0) !== CatalogAdapterDefinitionService::PRESET_ID
                || (int)($scenario['publicationRevision'] ?? 0) !== (int)$publication['revision']
                || !hash_equals((string)$publication['compileHash'], (string)($scenario['publicationCompileHash'] ?? ''))
                || !hash_equals($adapterRevision, (string)($scenario['adapterRevision'] ?? ''))
                || (int)($target['productId'] ?? 0) <= 0
                || $scenarioOfferId <= 0
                || $values === null) {
                throw new \RuntimeException('CatalogScenario не совпадает с текущей публикацией и адаптером.');
            }
            $scenarioIds[] = $scenarioOfferId;
            $productIds[] = (int)$target['productId'];
            $normalizedScenarios[] = [
                'scenarioId' => (string)$scenario['scenarioId'],
                'target' => [
                    'productId' => (int)$target['productId'],
                    'offerId' => $scenarioOfferId,
                    'name' => trim((string)($target['name'] ?? '')),
                ],
                'values' => $values,
            ];
        }
        if ($this->normalizeOfferIds($scenarioIds) !== $offerIds) {
            throw new \RuntimeException('CatalogScenario не разрешает точный запрошенный набор целей записи.');
        }
        usort($normalizedScenarios, static function (array $left, array $right): int {
            return ((int)$left['target']['offerId']) <=> ((int)$right['target']['offerId']);
        });
        $productIds = array_values(array_unique(array_filter($productIds, static function (int $productId): bool {
            return $productId > 0;
        })));
        sort($productIds, SORT_NUMERIC);
        $productIblockIds = [];
        foreach ((array)($payload['_productIblockIds'] ?? []) as $productId => $iblockId) {
            $productId = (int)$productId;
            $iblockId = (int)$iblockId;
            if ($productId <= 0 || $iblockId <= 0) {
                throw new \RuntimeException('Catalog product iblock provenance is invalid.');
            }
            $productIblockIds[$productId] = $iblockId;
        }
        ksort($productIblockIds, SORT_NUMERIC);
        if (array_map('intval', array_keys($productIblockIds)) !== $productIds) {
            throw new \RuntimeException('Catalog product iblock provenance is incomplete.');
        }
        $expectedOfferIblockId = $this->effectiveRuntimeConfigIblockId($runtimeConfigSnapshot, 'OFFERS');
        if ($expectedOfferIblockId <= 0) {
            throw new \RuntimeException('SKU iblock is not pinned by direct b_option authority.', 409);
        }
        foreach ($selectedOffers as $offer) {
            if ((int)($offer['iblockId'] ?? 0) !== $expectedOfferIblockId) {
                throw new \RuntimeException('Cached SKU iblock mapping differs from direct b_option authority.', 409);
            }
        }
        $expectedProductIblockId = $this->effectiveRuntimeConfigIblockId($runtimeConfigSnapshot, 'PRODUCTS');
        if ($expectedProductIblockId <= 0) {
            throw new \RuntimeException('Product iblock is not pinned by direct b_option authority.', 409);
        }
        foreach ($productIblockIds as $productIblockId) {
            if ($productIblockId !== $expectedProductIblockId) {
                throw new \RuntimeException('Cached product iblock mapping differs from direct b_option authority.', 409);
            }
        }
        $expectedGlobalIblockId = $this->effectiveRuntimeConfigIblockId(
            $runtimeConfigSnapshot,
            'CALC_GLOBAL_VALUES'
        );
        if ($expectedGlobalIblockId <= 0
            || (int)$payload['_globalSymbolIblockId'] !== $expectedGlobalIblockId) {
            throw new \RuntimeException(
                'Global-symbol iblock differs from direct b_option authority.',
                409
            );
        }
        foreach ($payload['_globalSymbols'] as $globalSymbol) {
            if (!is_array($globalSymbol)
                || (int)($globalSymbol['iblockId'] ?? 0) !== $expectedGlobalIblockId) {
                throw new \RuntimeException('Global-symbol registry snapshot is not pinned to its option row.', 409);
            }
        }

        return [
            'selectedOffers' => $selectedOffers,
            'priceTypes' => is_array($payload['priceTypes'] ?? null) ? $payload['priceTypes'] : [],
            'publication' => [
                'revision' => (int)$publication['revision'],
                'compileHash' => (string)$publication['compileHash'],
            ],
            'adapter' => $adapter,
            'adapterRevision' => $adapterRevision,
            'adapterPersisted' => $catalogMapping['adapterPersisted'],
            'catalogScenarios' => $normalizedScenarios,
            'productIds' => $productIds,
            'productIblockIds' => $productIblockIds,
            'neutralInputRequired' => $payload['_neutralInputRequired'],
            'runtimeConfigSnapshot' => $runtimeConfigSnapshot,
        ];
    }

    /** @param array<int,array<string,mixed>> $results @param int[] $offerIds */
    private function assertResultAllowlist(array $results, array $offerIds, string $source): void
    {
        if (!$this->isList($results) || count($results) !== count($offerIds)) {
            throw new \InvalidArgumentException('В ' . $source . ' отсутствует точный набор выбранных торговых предложений.');
        }
        $resultIds = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                throw new \InvalidArgumentException('В ' . $source . ' передана некорректная запись.');
            }
            $resultIds[] = (int)($result['offerId'] ?? 0);
        }
        if ($this->normalizeOfferIds($resultIds) !== $offerIds) {
            throw new \InvalidArgumentException('В ' . $source . ' присутствует незапрошенная, повторная или отсутствующая цель.');
        }
    }

    /** @param array<string,mixed> $offer @return array<string,mixed> */
    private function catalogStateFromOffer(array $offer): array
    {
        $attributes = is_array($offer['attributes'] ?? null) ? $offer['attributes'] : [];
        return [
            'offerId' => (int)($offer['id'] ?? 0),
            'purchasingPrice' => [
                'value' => $this->nullableNumber($offer['purchasingPrice'] ?? null),
                'currency' => $this->nullableCurrency($offer['purchasingCurrency'] ?? null),
            ],
            'dimensions' => [
                'width' => $this->nullableNumber($attributes['width'] ?? null),
                'length' => $this->nullableNumber($attributes['length'] ?? null),
                'height' => $this->nullableNumber($attributes['height'] ?? null),
                'weight' => $this->nullableNumber($attributes['weight'] ?? null),
            ],
            'prices' => $this->normalizeCurrentPrices(is_array($offer['prices'] ?? null) ? $offer['prices'] : []),
        ];
    }

    /** @param array<string,mixed> $projected @param array<string,mixed> $current @return array<string,mixed> */
    private function catalogTargetFromProjected(array $projected, array $current): array
    {
        $detail = is_array($projected['details'][0] ?? null) ? $projected['details'][0] : [];
        $outputs = is_array($detail['outputs'] ?? null) ? $detail['outputs'] : [];
        $projectedPrices = $this->normalizeProjectedPrices($projected);
        return [
            'offerId' => (int)($projected['offerId'] ?? 0),
            'purchasingPrice' => [
                'value' => $this->positiveNumber($projected['purchasePrice'] ?? null, 'Закупочная цена'),
                'currency' => $this->nullableCurrency($projected['currency'] ?? null),
            ],
            'dimensions' => [
                'width' => $this->positiveNumber($outputs['width'] ?? null, 'Ширина'),
                'length' => $this->positiveNumber($outputs['length'] ?? null, 'Длина'),
                'height' => $this->positiveNumber($outputs['height'] ?? null, 'Высота'),
                'weight' => $this->positiveNumber($outputs['weight'] ?? null, 'Вес'),
            ],
            'prices' => $this->predictWrittenPrices($current['prices'], $projectedPrices),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeCurrentPrices(array $prices): array
    {
        $normalized = [];
        $seen = [];
        foreach ($prices as $price) {
            if (!is_array($price)) {
                throw new \RuntimeException('Каталог содержит некорректную строку цены.');
            }
            $row = $this->normalizePriceRow([
                'typeId' => $price['typeId'] ?? null,
                'price' => $price['price'] ?? null,
                'currency' => $price['currency'] ?? null,
                'quantityFrom' => $price['quantityFrom'] ?? null,
                'quantityTo' => $price['quantityTo'] ?? null,
            ], false);
            $key = $this->priceKey($row);
            if (isset($seen[$key])) {
                throw new \RuntimeException('Каталог содержит повторный диапазон цены ' . $key . '.');
            }
            $seen[$key] = true;
            $normalized[] = $row;
        }
        $this->sortPrices($normalized);
        return $normalized;
    }

    /** @param array<string,mixed> $projected @return array<int,array<string,mixed>> */
    private function normalizeProjectedPrices(array $projected): array
    {
        $normalized = [];
        $seen = [];
        foreach (is_array($projected['priceRangesWithMarkup'] ?? null) ? $projected['priceRangesWithMarkup'] : [] as $range) {
            if (!is_array($range)) {
                continue;
            }
            foreach (is_array($range['prices'] ?? null) ? $range['prices'] : [] as $price) {
                if (!is_array($price)) {
                    continue;
                }
                $row = $this->normalizePriceRow([
                    'typeId' => $price['typeId'] ?? null,
                    'price' => $price['basePrice'] ?? null,
                    'currency' => $price['currency'] ?? ($projected['currency'] ?? null),
                    'quantityFrom' => $range['quantityFrom'] ?? null,
                    'quantityTo' => $range['quantityTo'] ?? null,
                ], true);
                $key = $this->priceKey($row);
                if (isset($seen[$key])) {
                    throw new \InvalidArgumentException('Результат содержит повторный диапазон цены ' . $key . '.');
                }
                $seen[$key] = true;
                $normalized[] = $row;
            }
        }
        $this->sortPrices($normalized);
        return $normalized;
    }

    /** @return array<string,mixed> */
    private function normalizePriceRow(array $row, bool $requirePositive): array
    {
        $typeId = (int)($row['typeId'] ?? 0);
        $price = $this->nullableNumber($row['price'] ?? null);
        $currency = $this->nullableCurrency($row['currency'] ?? null);
        $quantityFrom = $this->nullableQuantity($row['quantityFrom'] ?? null);
        $quantityTo = $this->nullableQuantity($row['quantityTo'] ?? null);
        if ($typeId <= 0 || $price === null || ($requirePositive && $price <= 0) || $currency === null) {
            throw new \InvalidArgumentException('Цена каталога содержит неполные данные.');
        }
        if ($quantityFrom !== null && $quantityTo !== null && $quantityFrom > $quantityTo) {
            throw new \InvalidArgumentException('Нижняя граница диапазона цены превышает верхнюю.');
        }
        return [
            'typeId' => $typeId,
            'quantityFrom' => $quantityFrom,
            'quantityTo' => $quantityTo,
            'price' => $price,
            'currency' => $currency,
        ];
    }

    /**
     * Mirror OfferUpdateService's two price write paths so preview also shows
     * deletions. A simple open price preserves other types; ranged/multi-type
     * synchronization replaces the complete price set.
     *
     * @param array<int,array<string,mixed>> $current
     * @param array<int,array<string,mixed>> $projected
     * @return array<int,array<string,mixed>>
     */
    private function predictWrittenPrices(array $current, array $projected): array
    {
        if (count($projected) === 1
            && $projected[0]['quantityFrom'] === null
            && $projected[0]['quantityTo'] === null) {
            $targetTypeId = (int)$projected[0]['typeId'];
            $matchingIndexes = [];
            foreach ($current as $index => $row) {
                if ((int)$row['typeId'] === $targetTypeId) {
                    $matchingIndexes[] = $index;
                }
            }
            if (count($matchingIndexes) > 1) {
                throw new \RuntimeException(
                    'Для простого типа цены #' . $targetTypeId . ' найдено несколько диапазонов; безопасная запись неоднозначна.'
                );
            }
            if ($matchingIndexes !== []) {
                $existing = $current[$matchingIndexes[0]];
                if ($existing['quantityFrom'] !== null || $existing['quantityTo'] !== null) {
                    throw new \RuntimeException(
                        'Простая цена не может молча перезаписать существующий диапазон типа #' . $targetTypeId . '.'
                    );
                }
                $current[$matchingIndexes[0]] = $projected[0];
            } else {
                $current[] = $projected[0];
            }
            $this->sortPrices($current);
            return $current;
        }

        return $projected;
    }

    /** @return array<int,array<string,mixed>> */
    private function buildFieldDiffs(array $current, array $target): array
    {
        $fields = [
            'purchasingPrice' => [$current['purchasingPrice'], $target['purchasingPrice']],
            'dimensions.width' => [$current['dimensions']['width'], $target['dimensions']['width']],
            'dimensions.length' => [$current['dimensions']['length'], $target['dimensions']['length']],
            'dimensions.height' => [$current['dimensions']['height'], $target['dimensions']['height']],
            'dimensions.weight' => [$current['dimensions']['weight'], $target['dimensions']['weight']],
            'prices' => [$current['prices'], $target['prices']],
        ];
        $diffs = [];
        foreach ($fields as $path => [$old, $new]) {
            $diffs[] = [
                'path' => $path,
                'old' => $old,
                'new' => $new,
                'changed' => self::canonicalEncode($old) !== self::canonicalEncode($new),
            ];
        }
        return $diffs;
    }

    /** @param array<string,mixed> $projected @return array<string,mixed> */
    private function normalizeProjectedResult(array $projected): array
    {
        $detail = is_array($projected['details'][0] ?? null) ? $projected['details'][0] : [];
        $outputs = is_array($detail['outputs'] ?? null) ? $detail['outputs'] : [];
        $provenance = is_array($projected['catalogAdapterProvenance'] ?? null)
            ? $projected['catalogAdapterProvenance']
            : [];
        return [
            'offerId' => (int)($projected['offerId'] ?? 0),
            'purchasePrice' => $this->positiveNumber($projected['purchasePrice'] ?? null, 'Закупочная цена'),
            'currency' => $this->nullableCurrency($projected['currency'] ?? null),
            'dimensions' => [
                'width' => $this->positiveNumber($outputs['width'] ?? null, 'Ширина'),
                'length' => $this->positiveNumber($outputs['length'] ?? null, 'Длина'),
                'height' => $this->positiveNumber($outputs['height'] ?? null, 'Высота'),
                'weight' => $this->positiveNumber($outputs['weight'] ?? null, 'Вес'),
            ],
            'prices' => $this->normalizeProjectedPrices($projected),
            'provenance' => $provenance,
        ];
    }

    /**
     * @param array<int|string,array<string,mixed>> $states
     * @param int[] $offerIds
     * @return array<int,array{calculation:string,catalog:string}>
     */
    private function normalizeApprovedWriteStates(array $states, array $offerIds): array
    {
        $normalized = [];
        foreach ($states as $offerId => $state) {
            $offerId = (int)$offerId;
            $calculation = is_array($state)
                ? strtolower(trim((string)($state['calculation'] ?? '')))
                : '';
            $catalog = is_array($state)
                ? strtolower(trim((string)($state['catalog'] ?? '')))
                : '';
            if ($offerId <= 0
                || preg_match('/^[a-f0-9]{64}$/D', $calculation) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $catalog) !== 1) {
                throw new \InvalidArgumentException('Preview содержит некорректный отпечаток расчёта или каталога.');
            }
            $normalized[$offerId] = [
                'calculation' => $calculation,
                'catalog' => $catalog,
            ];
        }
        ksort($normalized, SORT_NUMERIC);
        if (array_map('intval', array_keys($normalized)) !== $offerIds) {
            throw new \InvalidArgumentException('Preview не содержит состояние всех целей пакетной записи.');
        }
        return $normalized;
    }

    /** @param array<int,array{calculation:string,catalog:string}> $states */
    private function calculationStatesOnly(array $states): array
    {
        $result = [];
        foreach ($states as $offerId => $state) {
            $result[(int)$offerId] = ['calculation' => (string)$state['calculation']];
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    /**
     * @param array<int|string,string> $fingerprints
     * @param int[] $offerIds
     * @return array<int,string>
     */
    private function normalizeResultFingerprints(array $fingerprints, array $offerIds): array
    {
        $normalized = [];
        foreach ($fingerprints as $offerId => $fingerprint) {
            $offerId = (int)$offerId;
            $fingerprint = strtolower(trim((string)$fingerprint));
            if ($offerId <= 0 || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
                throw new \InvalidArgumentException('Preview содержит некорректный отпечаток результата ТП.');
            }
            $normalized[$offerId] = $fingerprint;
        }
        ksort($normalized, SORT_NUMERIC);
        if (array_map('intval', array_keys($normalized)) !== $offerIds) {
            throw new \InvalidArgumentException('Preview не содержит результаты всех целей пакетной записи.');
        }
        return $normalized;
    }

    /**
     * @param array<string,mixed> $preview
     * @param array<int,array{calculation:string,catalog:string}> $approvedStates
     * @param array<int,string> $approvedResults
     */
    private function assertApprovedBatchSnapshot(
        array $preview,
        array $approvedStates,
        array $approvedResults
    ): void {
        $approvedCatalog = [];
        foreach ($approvedStates as $offerId => $state) {
            $approvedCatalog[(int)$offerId] = (string)$state['catalog'];
        }
        ksort($approvedCatalog, SORT_NUMERIC);
        $currentCatalog = is_array($preview['_catalogStateFingerprints'] ?? null)
            ? $preview['_catalogStateFingerprints']
            : [];
        ksort($currentCatalog, SORT_NUMERIC);
        $currentResults = is_array($preview['_batchResultFingerprints'] ?? null)
            ? $preview['_batchResultFingerprints']
            : [];
        ksort($currentResults, SORT_NUMERIC);

        if (!hash_equals(self::canonicalEncode($approvedCatalog), self::canonicalEncode($currentCatalog))) {
            throw new \RuntimeException(
                'Цены, вес или габариты каталога изменились после подтверждённого preview.',
                409
            );
        }
        if (!hash_equals(self::canonicalEncode($approvedResults), self::canonicalEncode($currentResults))) {
            throw new \RuntimeException(
                'Свежий результат calc-server отличается от подтверждённого preview.',
                409
            );
        }
    }

    /** @return array<string,mixed> */
    private function calculateAuthoritativeResults(array $offerIds, string $siteId): array
    {
        if (isset($this->adapters['calculate_results'])) {
            $calculation = call_user_func($this->adapters['calculate_results'], $offerIds, $siteId);
            if (!is_array($calculation)) {
                throw new \RuntimeException('Серверный расчёт вернул некорректный контракт результата.');
            }
            return $calculation;
        }

        return $this->getBatchRecalculateService()->calculateOffersForPreview($offerIds, $siteId);
    }

    /**
     * @param array<string,mixed> $calculation
     * @param int[] $offerIds
     * @return array{results:array<int,array<string,mixed>>,stateFingerprints:array<int,array{calculation:string}>,provenance:array<string,mixed>}
     */
    private function validateAuthoritativeCalculation(array $calculation, array $offerIds): array
    {
        if ((string)($calculation['contract'] ?? '') !== BatchRecalculateService::SERVER_CALCULATION_CONTRACT) {
            throw new \RuntimeException('Результат не подтверждён серверным контрактом расчёта.');
        }

        $results = is_array($calculation['results'] ?? null) ? $calculation['results'] : [];
        $this->assertResultAllowlist($results, $offerIds, 'серверных результатах calc-server');
        usort($results, static function (array $left, array $right): int {
            return ((int)($left['offerId'] ?? 0)) <=> ((int)($right['offerId'] ?? 0));
        });

        $stateFingerprints = $this->normalizeCalculationStateFingerprints(
            is_array($calculation['stateFingerprints'] ?? null)
                ? $calculation['stateFingerprints']
                : [],
            $offerIds
        );
        $provenance = is_array($calculation['provenance'] ?? null)
            ? $calculation['provenance']
            : [];
        $publication = is_array($provenance['publication'] ?? null) ? $provenance['publication'] : [];
        $compileHash = strtolower(trim((string)($publication['compileHash'] ?? '')));
        $adapterRevision = strtolower(trim((string)($provenance['adapterRevision'] ?? '')));
        $requestHashes = is_array($provenance['requestHashes'] ?? null)
            ? array_values($provenance['requestHashes'])
            : [];
        foreach ($requestHashes as $requestHash) {
            if (!is_string($requestHash) || preg_match('/^[a-f0-9]{64}$/D', strtolower($requestHash)) !== 1) {
                throw new \RuntimeException('Серверный расчёт содержит некорректный отпечаток запроса.');
            }
        }
        if ((string)($provenance['contract'] ?? '')
                !== BatchRecalculateService::SERVER_CALCULATION_CONTRACT . '/provenance'
            || (int)($provenance['presetId'] ?? 0) !== CatalogAdapterDefinitionService::PRESET_ID
            || (int)($publication['revision'] ?? 0) <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $compileHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $adapterRevision) !== 1
            || $requestHashes === []) {
            throw new \RuntimeException('Серверный расчёт не содержит полную версию источника и запроса.');
        }
        $sourceVersions = [];
        foreach ((array)($provenance['sourceVersions'] ?? []) as $sourceVersion) {
            $sourceVersion = trim((string)$sourceVersion);
            if ($sourceVersion !== '') {
                $sourceVersions[] = substr($sourceVersion, 0, 128);
            }
        }
        $sourceVersions = array_values(array_unique($sourceVersions));
        sort($sourceVersions, SORT_STRING);
        $runtimeLocks = is_array($provenance['runtimeLocks'] ?? null)
            ? $this->normalizeRuntimeLocks($provenance['runtimeLocks'])
            : [];
        $runtimeConfigSnapshot = is_array($provenance['runtimeConfigSnapshot'] ?? null)
            ? $this->normalizeRuntimeConfigSnapshot($provenance['runtimeConfigSnapshot'])
            : [];
        if (!array_key_exists('neutralInputRequired', $provenance)
            || $provenance['neutralInputRequired'] !== true) {
            throw new \RuntimeException('The server calculation is not bound to neutral-input mode.', 409);
        }
        if ($runtimeLocks === []) {
            throw new \RuntimeException('Серверный расчёт не перечисляет источники runtime для блокировки.');
        }
        if ($runtimeConfigSnapshot === []) {
            throw new \RuntimeException('The server calculation does not pin ConfigManager option authority.');
        }

        return [
            'contract' => BatchRecalculateService::SERVER_CALCULATION_CONTRACT,
            'results' => $results,
            'stateFingerprints' => $stateFingerprints,
            'provenance' => [
                'contract' => BatchRecalculateService::SERVER_CALCULATION_CONTRACT . '/provenance',
                'presetId' => CatalogAdapterDefinitionService::PRESET_ID,
                'publication' => [
                    'revision' => (int)$publication['revision'],
                    'compileHash' => $compileHash,
                ],
                'adapterRevision' => $adapterRevision,
                'neutralInputRequired' => true,
                'requestHashes' => array_map('strtolower', $requestHashes),
                'sourceVersions' => $sourceVersions,
                'runtimeLocks' => $runtimeLocks,
                'runtimeConfigSnapshot' => $runtimeConfigSnapshot,
            ],
        ];
    }

    /** @param array<string,mixed> $snapshot @return array<string,string|null> */
    private function normalizeRuntimeConfigSnapshot(array $snapshot): array
    {
        $expectedKeys = [];
        foreach (self::RUNTIME_CONFIG_OPTION_KEYS as $moduleId => $names) {
            foreach ($names as $name) {
                $expectedKeys[] = $moduleId . ':' . $name;
            }
        }
        sort($expectedKeys, SORT_STRING);
        $actualKeys = array_map('strval', array_keys($snapshot));
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            throw new \RuntimeException('Runtime ConfigManager snapshot does not match the strict option allowlist.');
        }
        $normalized = [];
        foreach ($expectedKeys as $key) {
            $value = $snapshot[$key];
            if ($value !== null && !is_scalar($value)) {
                throw new \RuntimeException('Runtime ConfigManager snapshot contains a non-scalar value.');
            }
            $normalized[$key] = $value === null ? null : (string)$value;
        }
        return $normalized;
    }

    /** @param array<string,string|null> $snapshot */
    private function effectiveRuntimeConfigIblockId(array $snapshot, string $code): int
    {
        if ($code === 'PRODUCTS') {
            $candidates = [
                'prospektweb.frontcalc:PRODUCTS_IBLOCK_ID',
                'prospektweb.calc:PRODUCT_IBLOCK_ID',
            ];
        } elseif ($code === 'OFFERS') {
            $candidates = [
                'prospektweb.frontcalc:OFFERS_IBLOCK_ID',
                'prospektweb.calc:SKU_IBLOCK_ID',
            ];
        } else {
            $candidates = [
                'prospektweb.frontcalc:IBLOCK_' . $code,
                'prospektweb.calc:IBLOCK_' . $code,
            ];
        }
        if ($code !== 'CALC_GLOBAL_VALUES') {
            foreach ($candidates as $candidate) {
                $value = (int)($snapshot[$candidate] ?? 0);
                if ($value > 0) {
                    return $value;
                }
            }
            return 0;
        }

        $resolvedIds = [];
        foreach ($candidates as $candidate) {
            $raw = $snapshot[$candidate] ?? null;
            if ($raw === null) {
                continue;
            }
            $value = (string)$raw;
            if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
                throw new \RuntimeException('Runtime source ' . $code . ' has an invalid option authority.', 409);
            }
            $resolvedIds[(int)$value] = true;
        }
        if (count($resolvedIds) > 1) {
            throw new \RuntimeException('Runtime source ' . $code . ' option authorities disagree.', 409);
        }
        return $resolvedIds === [] ? 0 : (int)array_key_first($resolvedIds);
    }

    /** @param array<string,mixed> $locks @return array<string,mixed> */
    private function normalizeRuntimeLocks(array $locks): array
    {
        $elements = [];
        foreach ((array)($locks['elements'] ?? []) as $element) {
            $id = is_array($element) ? (int)($element['id'] ?? 0) : 0;
            $iblockId = is_array($element) ? (int)($element['iblockId'] ?? 0) : 0;
            if ($id <= 0 || $iblockId <= 0 || isset($elements[$id])) {
                throw new \RuntimeException('Список runtime-элементов для блокировки повреждён.');
            }
            $elements[$id] = ['id' => $id, 'iblockId' => $iblockId];
        }
        ksort($elements, SORT_NUMERIC);
        if (!isset($elements[CatalogAdapterDefinitionService::PRESET_ID])) {
            throw new \RuntimeException('Список runtime-блокировок не содержит пресет 12740.');
        }
        $sourceIblockIds = [];
        foreach ($elements as $element) {
            $sourceIblockIds[(int)$element['iblockId']] = true;
        }
        $sourceIblockIds = array_map('intval', array_keys($sourceIblockIds));
        sort($sourceIblockIds, SORT_NUMERIC);
        $providedSourceIblockIds = [];
        foreach ((array)($locks['sourceIblockIds'] ?? []) as $iblockId) {
            $iblockId = (int)$iblockId;
            if ($iblockId <= 0) {
                throw new \RuntimeException('The source-iblock membership lock set is invalid.');
            }
            $providedSourceIblockIds[$iblockId] = true;
        }
        $providedSourceIblockIds = array_map('intval', array_keys($providedSourceIblockIds));
        sort($providedSourceIblockIds, SORT_NUMERIC);
        if (array_values(array_diff($sourceIblockIds, $providedSourceIblockIds)) !== []) {
            throw new \RuntimeException(
                'The source-iblock membership lock set does not cover every runtime element.'
            );
        }
        $sourceIblockIds = $providedSourceIblockIds;
        $priceTypeIds = [];
        foreach ((array)($locks['priceTypeIds'] ?? []) as $priceTypeId) {
            $priceTypeId = (int)$priceTypeId;
            if ($priceTypeId <= 0) {
                throw new \RuntimeException('Список типов цен для блокировки повреждён.');
            }
            $priceTypeIds[$priceTypeId] = true;
        }
        $priceTypeIds = array_map('intval', array_keys($priceTypeIds));
        sort($priceTypeIds, SORT_NUMERIC);
        $normalizeIds = static function ($values, string $label): array {
            $normalized = [];
            foreach ((array)$values as $value) {
                $id = (int)$value;
                if ($id <= 0) {
                    throw new \RuntimeException($label . ' contains an invalid ID.');
                }
                $normalized[$id] = true;
            }
            $normalized = array_map('intval', array_keys($normalized));
            sort($normalized, SORT_NUMERIC);
            return $normalized;
        };
        $globalSymbolIblockIds = $normalizeIds(
            $locks['globalSymbolIblockIds'] ?? [],
            'The global-symbol runtime lock set'
        );
        $globalSymbolPropertiesByIblock = [];
        $requiredGlobalPropertyCodes = ['DATA_TYPE', 'INITIAL_VALUE', 'KIND', 'PRESET_ID'];
        foreach ((array)($locks['globalSymbolProperties'] ?? []) as $authority) {
            $iblockId = is_array($authority) ? (int)($authority['iblockId'] ?? 0) : 0;
            $properties = is_array($authority['properties'] ?? null) ? $authority['properties'] : [];
            ksort($properties, SORT_STRING);
            $propertyCodes = array_keys($properties);
            $propertyValues = array_map('intval', array_values($properties));
            if ($iblockId <= 0 || isset($globalSymbolPropertiesByIblock[$iblockId])
                || $propertyCodes !== $requiredGlobalPropertyCodes
                || count(array_filter($propertyValues, static function (int $id): bool {
                    return $id > 0;
                })) !== count($requiredGlobalPropertyCodes)
                || count(array_unique($propertyValues)) !== count($propertyValues)) {
                throw new \RuntimeException('Global-symbol property authority is invalid.');
            }
            $globalSymbolPropertiesByIblock[$iblockId] = array_combine($propertyCodes, $propertyValues);
        }
        ksort($globalSymbolPropertiesByIblock, SORT_NUMERIC);
        if (array_map('intval', array_keys($globalSymbolPropertiesByIblock)) !== $globalSymbolIblockIds) {
            throw new \RuntimeException('Global-symbol property authority does not cover every registry iblock.');
        }
        $globalSymbolProperties = [];
        foreach ($globalSymbolPropertiesByIblock as $iblockId => $properties) {
            $globalSymbolProperties[] = [
                'iblockId' => (int)$iblockId,
                'properties' => $properties,
            ];
        }
        $measureRatioProductIds = $normalizeIds(
            $locks['measureRatioProductIds'] ?? [],
            'The measure-ratio runtime lock set'
        );
        $elementIds = array_map('intval', array_keys($elements));
        sort($elementIds, SORT_NUMERIC);
        if ($measureRatioProductIds !== $elementIds) {
            throw new \RuntimeException('The measure-ratio lock set does not cover every runtime element.');
        }
        $measureIds = $normalizeIds($locks['measureIds'] ?? [], 'The measure runtime lock set');
        $propertyIds = $normalizeIds($locks['propertyIds'] ?? [], 'The property runtime lock set');
        foreach ($globalSymbolPropertiesByIblock as $properties) {
            foreach ($properties as $propertyId) {
                if (!in_array($propertyId, $propertyIds, true)) {
                    throw new \RuntimeException('Global-symbol metadata is absent from the property lock set.');
                }
            }
        }
        return [
            'elements' => array_values($elements),
            'sourceIblockIds' => $sourceIblockIds,
            'priceTypeIds' => $priceTypeIds,
            'globalSymbolIblockIds' => $globalSymbolIblockIds,
            'globalSymbolProperties' => $globalSymbolProperties,
            'measureRatioProductIds' => $measureRatioProductIds,
            'measureIds' => $measureIds,
            'propertyIds' => $propertyIds,
        ];
    }

    /**
     * @param array<int|string,array<string,mixed>> $states
     * @param int[] $offerIds
     * @return array<int,array{calculation:string}>
     */
    private function normalizeCalculationStateFingerprints(array $states, array $offerIds): array
    {
        $normalized = [];
        foreach ($states as $offerId => $state) {
            $offerId = (int)$offerId;
            $hash = is_array($state) ? strtolower(trim((string)($state['calculation'] ?? ''))) : '';
            if ($offerId <= 0 || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \RuntimeException('Серверный расчёт содержит некорректный отпечаток состояния ТП.');
            }
            $normalized[$offerId] = ['calculation' => $hash];
        }
        ksort($normalized, SORT_NUMERIC);
        if (array_map('intval', array_keys($normalized)) !== $offerIds) {
            throw new \RuntimeException('Серверный расчёт не подтверждает состояние всех выбранных ТП.');
        }
        return $normalized;
    }

    /** @param array<int,array{calculation:string}> $expected */
    private function assertCalculationStateMatches(
        array $expected,
        array $offerIds,
        string $siteId,
        ?array $resolvedCatalogPayload = null
    ): void {
        if (isset($this->adapters['capture_calculation_state'])) {
            $current = call_user_func(
                $this->adapters['capture_calculation_state'],
                $offerIds,
                $siteId
            );
            if (!is_array($current)) {
                throw new \RuntimeException('Проверка текущего расчётного состояния вернула некорректный контракт.');
            }
        } elseif ($resolvedCatalogPayload !== null) {
            $current = $this->getBatchRecalculateService()
                ->captureOfferStateFingerprintsFromResolvedCatalogPayload(
                    $resolvedCatalogPayload,
                    $offerIds,
                    $siteId
                );
        } else {
            $current = $this->getBatchRecalculateService()
                ->captureOfferStateFingerprintsAtSite($offerIds, $siteId);
        }
        $current = $this->normalizeCalculationStateFingerprints($current, $offerIds);
        if (!hash_equals(self::canonicalEncode($expected), self::canonicalEncode($current))) {
            throw new \RuntimeException(
                'Расчётные входные данные изменились после обращения к calc-server. Повторите предпросмотр.',
                409
            );
        }
    }

    /** @param array<string,mixed> $provenance @param array<string,mixed> $resolved */
    private function assertCalculationProvenanceMatchesRuntime(array $provenance, array $resolved): void
    {
        if ((int)($provenance['publication']['revision'] ?? 0)
                !== (int)($resolved['publication']['revision'] ?? 0)
            || !hash_equals(
                (string)($provenance['publication']['compileHash'] ?? ''),
                (string)($resolved['publication']['compileHash'] ?? '')
            )
            || !hash_equals(
                (string)($provenance['adapterRevision'] ?? ''),
                (string)($resolved['adapterRevision'] ?? '')
            )
            || ($provenance['neutralInputRequired'] ?? null)
                !== ($resolved['neutralInputRequired'] ?? null)
            || !hash_equals(
                self::canonicalEncode($provenance['runtimeConfigSnapshot'] ?? []),
                self::canonicalEncode($resolved['runtimeConfigSnapshot'] ?? [])
            )) {
            throw new \RuntimeException(
                'Результат calc-server относится к другой публикации формы или ревизии адаптера.',
                409
            );
        }
    }

    /** @param array<string,mixed> $resolved @return array<string,mixed> */
    private function compareClientResults(
        array $clientResults,
        array $authoritativeProjected,
        array $offerIds,
        array $resolved
    ): array {
        if ($clientResults === []) {
            return ['provided' => false, 'valid' => false, 'matchesAuthoritative' => false];
        }

        try {
            $this->assertResultAllowlist($clientResults, $offerIds, 'клиентских результатах');
            $clientProjected = $this->projectResults(
                $clientResults,
                $resolved['priceTypes'],
                $resolved['adapter'],
                $resolved['publication']
            );
            $this->assertResultAllowlist($clientProjected, $offerIds, 'клиентской проекции');
            $normalize = function (array $results): array {
                $normalized = [];
                foreach ($results as $result) {
                    $normalized[] = $this->normalizeProjectedResult($result);
                }
                usort($normalized, static function (array $left, array $right): int {
                    return ((int)$left['offerId']) <=> ((int)$right['offerId']);
                });
                return $normalized;
            };
            return [
                'provided' => true,
                'valid' => true,
                'matchesAuthoritative' => hash_equals(
                    self::canonicalEncode($normalize($authoritativeProjected)),
                    self::canonicalEncode($normalize($clientProjected))
                ),
            ];
        } catch (\Throwable $error) {
            return [
                'provided' => true,
                'valid' => false,
                'matchesAuthoritative' => false,
            ];
        }
    }

    /** @return array<string,mixed>|null */
    private function tryReplayReceipt(
        string $receiptName,
        int $actorUserId,
        array $offerIds,
        string $siteId,
        string $expectedFingerprint
    ): ?array {
        $preflightReceipt = $this->loadReceipt($receiptName, false);
        if (!is_array($preflightReceipt)) {
            return null;
        }
        $preflightProductIds = is_array($preflightReceipt['productIds'] ?? null)
            ? $preflightReceipt['productIds']
            : [];

        return $this->withAdapterMutationLock(function () use (
            $receiptName,
            $actorUserId,
            $offerIds,
            $siteId,
            $expectedFingerprint,
            $preflightProductIds
        ): ?array {
            $transactionStarted = false;
            try {
                $this->beginTransaction();
                $transactionStarted = true;
                $this->lockRuntimeOptionRows();
                $receipt = $this->loadReceipt($receiptName, true);
                if (!is_array($receipt)) {
                    $this->commitTransaction();
                    $transactionStarted = false;
                    return null;
                }
                $productIds = is_array($receipt['productIds'] ?? null) ? $receipt['productIds'] : [];
                if ($productIds !== $preflightProductIds) {
                    throw new \RuntimeException(
                        'Catalog write receipt product membership changed before replay.',
                        409
                    );
                }
                $this->lockCatalogRows($offerIds, $productIds);
                if (!is_array($receipt)) {
                    throw new \RuntimeException('Идемпотентная квитанция исчезла во время проверки.', 409);
                }
                $response = $this->validateReplayReceiptUnderLocks(
                    $receipt,
                    $actorUserId,
                    $offerIds,
                    $siteId,
                    $expectedFingerprint
                );
                $this->commitTransaction();
                $transactionStarted = false;
                return $response;
            } catch (\Throwable $error) {
                if ($transactionStarted) {
                    try {
                        $this->rollbackTransaction();
                    } catch (\Throwable $rollbackError) {
                        error_log('Catalog replay rollback failed: ' . $rollbackError->getMessage());
                    }
                }
                throw $error;
            }
        });
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function validateReplayReceiptUnderLocks(
        array $receipt,
        int $actorUserId,
        array $offerIds,
        string $siteId,
        string $expectedFingerprint
    ): array {
        $receiptOfferIds = is_array($receipt['offerIds'] ?? null)
            ? $this->normalizeOfferIds($receipt['offerIds'])
            : [];
        $productIds = is_array($receipt['productIds'] ?? null)
            ? array_values(array_unique(array_filter(array_map('intval', $receipt['productIds']), static function (int $id): bool {
                return $id > 0;
            })))
            : [];
        sort($productIds, SORT_NUMERIC);
        if ((string)($receipt['contract'] ?? '') !== self::RECEIPT_CONTRACT
            || (int)($receipt['actorUserId'] ?? 0) !== $actorUserId
            || (int)($receipt['presetId'] ?? 0) !== CatalogAdapterDefinitionService::PRESET_ID
            || (string)($receipt['siteId'] ?? '') !== $siteId
            || $receiptOfferIds !== $offerIds
            || $productIds === []
            || !hash_equals((string)($receipt['expectedFingerprint'] ?? ''), $expectedFingerprint)) {
            throw new \RuntimeException('Идемпотентная квитанция не соответствует текущему запросу.', 409);
        }

        $this->assertReceiptFresh($receipt);
        $payload = $this->resolveRuntimePinned($offerIds, $siteId);
        $resolved = $this->validateResolvedPayload($payload, $offerIds);
        if ($resolved['productIds'] !== $productIds) {
            throw new \RuntimeException('Связь ТП с родительским товаром изменилась после записи.', 409);
        }
        $catalogFingerprints = [];
        foreach ($resolved['selectedOffers'] as $offer) {
            $offerId = (int)($offer['id'] ?? 0);
            if ($offerId > 0) {
                $catalogFingerprints[$offerId] = hash(
                    'sha256',
                    self::canonicalEncode($this->catalogStateFromOffer($offer))
                );
            }
        }
        ksort($catalogFingerprints, SORT_NUMERIC);
        $targetHash = hash('sha256', self::canonicalEncode($catalogFingerprints));
        if (!hash_equals((string)($receipt['targetStateFingerprint'] ?? ''), $targetHash)) {
            throw new \RuntimeException(
                'Каталог изменился после уже выполненной записи; повтор не может быть подтверждён.',
                409
            );
        }

        $response = is_array($receipt['response'] ?? null) ? $receipt['response'] : [];
        if ((string)($response['contract'] ?? '') !== self::APPLY_CONTRACT
            || ($response['applied'] ?? false) !== true
            || !hash_equals((string)($response['fingerprint'] ?? ''), $expectedFingerprint)) {
            throw new \RuntimeException('Идемпотентная квитанция содержит некорректный ответ.', 409);
        }
        $response['replayed'] = true;
        return $response;
    }

    private function receiptName(int $actorUserId, string $expectedFingerprint): string
    {
        return 'CATALOG_WRITE_RECEIPT_'
            . substr(hash('sha256', $actorUserId . ':' . $expectedFingerprint), 0, 24);
    }

    private function normalizeBatchRequestId(int $actorUserId, string $requestId): string
    {
        $requestId = strtolower(trim($requestId));
        if ($actorUserId <= 0 || preg_match('/^[a-f0-9]{64}$/D', $requestId) !== 1) {
            throw new \InvalidArgumentException(
                'An authenticated actor and a stable SHA-256 batch request ID are required.'
            );
        }
        return $requestId;
    }

    private function batchReceiptName(int $actorUserId, string $requestId): string
    {
        return 'CATALOG_BATCH_RECEIPT_'
            . substr(hash('sha256', $actorUserId . ':' . $requestId), 0, 24);
    }

    /** @return array<string,mixed>|null */
    private function tryReplayBatchReceipt(
        string $receiptName,
        int $actorUserId,
        string $requestId,
        array $offerIds,
        string $siteId,
        array $approvedStates,
        array $approvedResults
    ): ?array {
        $preflightReceipt = $this->loadReceipt($receiptName, false);
        if (!is_array($preflightReceipt)) {
            return null;
        }
        $preflightProductIds = is_array($preflightReceipt['productIds'] ?? null)
            ? $preflightReceipt['productIds']
            : [];

        return $this->withAdapterMutationLock(function () use (
            $receiptName,
            $actorUserId,
            $requestId,
            $offerIds,
            $siteId,
            $approvedStates,
            $approvedResults,
            $preflightProductIds
        ): ?array {
            $transactionStarted = false;
            try {
                $this->beginTransaction();
                $transactionStarted = true;
                $this->lockRuntimeOptionRows();
                $receipt = $this->loadReceipt($receiptName, true);
                if (!is_array($receipt)) {
                    $this->commitTransaction();
                    $transactionStarted = false;
                    return null;
                }
                $productIds = is_array($receipt['productIds'] ?? null) ? $receipt['productIds'] : [];
                if ($productIds !== $preflightProductIds) {
                    throw new \RuntimeException(
                        'Catalog batch receipt product membership changed before replay.',
                        409
                    );
                }
                $this->lockCatalogRows($offerIds, $productIds);
                if (!is_array($receipt)) {
                    throw new \RuntimeException('The batch write receipt disappeared during replay.', 409);
                }
                $response = $this->validateBatchReplayReceiptUnderLocks(
                    $receipt,
                    $actorUserId,
                    $requestId,
                    $offerIds,
                    $siteId,
                    $approvedStates,
                    $approvedResults
                );
                $this->commitTransaction();
                $transactionStarted = false;
                return $response;
            } catch (\Throwable $error) {
                if ($transactionStarted) {
                    try {
                        $this->rollbackTransaction();
                    } catch (\Throwable $rollbackError) {
                        error_log('Catalog batch replay rollback failed: ' . $rollbackError->getMessage());
                    }
                }
                throw $error;
            }
        });
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function validateBatchReplayReceiptUnderLocks(
        array $receipt,
        int $actorUserId,
        string $requestId,
        array $offerIds,
        string $siteId,
        array $approvedStates,
        array $approvedResults
    ): array {
        $receiptOfferIds = is_array($receipt['offerIds'] ?? null)
            ? $this->normalizeOfferIds($receipt['offerIds'])
            : [];
        $productIds = is_array($receipt['productIds'] ?? null)
            ? array_values(array_unique(array_filter(array_map('intval', $receipt['productIds']), static function (int $id): bool {
                return $id > 0;
            })))
            : [];
        sort($productIds, SORT_NUMERIC);
        $approvedStateFingerprint = hash('sha256', self::canonicalEncode($approvedStates));
        $approvedResultFingerprint = hash('sha256', self::canonicalEncode($approvedResults));
        if ((string)($receipt['contract'] ?? '') !== self::BATCH_RECEIPT_CONTRACT
            || (int)($receipt['actorUserId'] ?? 0) !== $actorUserId
            || (int)($receipt['presetId'] ?? 0) !== CatalogAdapterDefinitionService::PRESET_ID
            || (string)($receipt['siteId'] ?? '') !== $siteId
            || $receiptOfferIds !== $offerIds
            || $productIds === []
            || !hash_equals((string)($receipt['requestId'] ?? ''), $requestId)
            || !hash_equals((string)($receipt['approvedStateFingerprint'] ?? ''), $approvedStateFingerprint)
            || !hash_equals((string)($receipt['approvedResultFingerprint'] ?? ''), $approvedResultFingerprint)) {
            throw new \RuntimeException('The batch write receipt does not match the current request.', 409);
        }

        $this->assertReceiptFresh($receipt);
        $payload = $this->resolveRuntimePinned($offerIds, $siteId);
        $resolved = $this->validateResolvedPayload($payload, $offerIds);
        if ($resolved['productIds'] !== $productIds) {
            throw new \RuntimeException('The offer-to-product relationship changed after the batch write.', 409);
        }
        $targetHash = $this->catalogTargetFingerprint($resolved['selectedOffers']);
        if (!hash_equals((string)($receipt['targetStateFingerprint'] ?? ''), $targetHash)) {
            throw new \RuntimeException('The catalog target changed after the completed batch write.', 409);
        }

        $response = is_array($receipt['response'] ?? null) ? $receipt['response'] : [];
        if ((string)($response['contract'] ?? '') !== self::BATCH_APPLY_CONTRACT
            || (string)($response['status'] ?? '') !== 'ok'
            || !is_array($response['offers'] ?? null)) {
            throw new \RuntimeException('The batch write receipt contains an invalid response.', 409);
        }
        $response['replayed'] = true;
        return $response;
    }

    /** @param array<string,mixed> $preview @param array<string,mixed> $response */
    private function saveBatchReceipt(
        string $receiptName,
        int $actorUserId,
        string $requestId,
        array $offerIds,
        string $siteId,
        array $approvedStates,
        array $approvedResults,
        array $preview,
        array $response
    ): void {
        $productIds = is_array($preview['_productIds'] ?? null) ? $preview['_productIds'] : [];
        $this->saveReceipt($receiptName, [
            'contract' => self::BATCH_RECEIPT_CONTRACT,
            'actorUserId' => $actorUserId,
            'presetId' => CatalogAdapterDefinitionService::PRESET_ID,
            'siteId' => $siteId,
            'offerIds' => $offerIds,
            'productIds' => $productIds,
            'requestId' => $requestId,
            'approvedStateFingerprint' => hash('sha256', self::canonicalEncode($approvedStates)),
            'approvedResultFingerprint' => hash('sha256', self::canonicalEncode($approvedResults)),
            'targetStateFingerprint' => hash(
                'sha256',
                self::canonicalEncode($preview['_catalogStateFingerprints'] ?? [])
            ),
            'response' => $response,
        ]);
    }

    /** @param array<int,array<string,mixed>> $offers */
    private function catalogTargetFingerprint(array $offers): string
    {
        $catalogFingerprints = [];
        foreach ($offers as $offer) {
            $offerId = (int)($offer['id'] ?? 0);
            if ($offerId > 0) {
                $catalogFingerprints[$offerId] = hash(
                    'sha256',
                    self::canonicalEncode($this->catalogStateFromOffer($offer))
                );
            }
        }
        ksort($catalogFingerprints, SORT_NUMERIC);
        return hash('sha256', self::canonicalEncode($catalogFingerprints));
    }

    /** @return array<string,mixed>|null */
    private function loadReceipt(string $name, bool $forUpdate): ?array
    {
        if (isset($this->adapters['load_receipt'])) {
            $receipt = call_user_func($this->adapters['load_receipt'], $name, $forUpdate);
            return is_array($receipt) ? $receipt : null;
        }
        if (preg_match('/^CATALOG_(?:WRITE|BATCH)_RECEIPT_[a-f0-9]{24}$/D', $name) !== 1) {
            throw new \RuntimeException('Некорректное имя квитанции записи каталога.');
        }
        $connection = $this->transactionConnection ?? Application::getConnection();
        $sql = "SELECT VALUE FROM b_option WHERE MODULE_ID='prospektweb.calc' AND NAME='"
            . $name . "' AND (SITE_ID IS NULL OR SITE_ID='')"
            . ' ORDER BY MODULE_ID, NAME, SITE_ID'
            . ($forUpdate ? ' FOR UPDATE' : '');
        $result = $connection->query($sql);
        $row = is_object($result) && method_exists($result, 'fetch') ? $result->fetch() : null;
        $duplicate = is_object($result) && method_exists($result, 'fetch') ? $result->fetch() : null;
        if (is_array($duplicate)) {
            throw new \RuntimeException('Duplicate global catalog write receipt row.', 409);
        }
        $raw = is_array($row) ? (string)($row['VALUE'] ?? $row['value'] ?? '') : '';
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Квитанция записи каталога повреждена.', 409);
        }
        return $decoded;
    }

    /** @param array<string,mixed> $receipt */
    private function saveReceipt(string $name, array $receipt): void
    {
        if (preg_match('/^CATALOG_(?:WRITE|BATCH)_RECEIPT_[a-f0-9]{24}$/D', $name) !== 1) {
            throw new \RuntimeException('Invalid catalog write receipt name.');
        }
        $createdAtTimestamp = $this->receiptNow();
        // Receipt time is server authority. Never persist a caller-supplied or
        // missing timestamp because replay expiry and pruning depend on it.
        $receipt['createdAt'] = gmdate('c', $createdAtTimestamp);
        if (isset($this->adapters['save_receipt'])) {
            call_user_func($this->adapters['save_receipt'], $name, $receipt);
            $this->pruneReceiptRows($name, $createdAtTimestamp);
            return;
        }
        if ($this->transactionConnection === null) {
            throw new \RuntimeException('Квитанцию записи можно сохранить только в активной транзакции.');
        }
        $raw = json_encode(
            $receipt,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($raw)) {
            throw new \RuntimeException('Не удалось сериализовать квитанцию записи каталога.');
        }
        $helper = $this->transactionConnection->getSqlHelper();
        $escapedName = $helper->forSql($name);
        $escapedRaw = $helper->forSql($raw);
        $this->transactionConnection->queryExecute(
            "DELETE FROM b_option WHERE MODULE_ID='prospektweb.calc' AND NAME='" . $escapedName
            . "' AND (SITE_ID IS NULL OR SITE_ID='')"
        );
        $this->transactionConnection->queryExecute(
            "INSERT INTO b_option (MODULE_ID, NAME, VALUE, SITE_ID) VALUES "
            . "('prospektweb.calc','" . $escapedName . "','" . $escapedRaw . "',NULL)"
        );
        $this->pruneReceiptRows($name, $createdAtTimestamp);
    }

    private function assertReceiptFresh(array $receipt): void
    {
        $now = $this->receiptNow();
        $createdAt = $this->parseReceiptCreatedAt($receipt['createdAt'] ?? null, $now);
        if ($createdAt <= $now - self::RECEIPT_TTL_SECONDS) {
            throw new \RuntimeException(
                'The catalog write receipt has expired; repeat the preview before writing again.',
                409
            );
        }
    }

    /**
     * Bound both receipt families while the caller holds the preset mutation
     * lock and the catalog transaction. The production reader is capped at
     * MAX+2 rows per family; an unexpectedly larger store fails closed instead
     * of issuing an unbounded cleanup query during an operator write.
     */
    private function pruneReceiptRows(string $currentName, int $now): void
    {
        $deletions = [];
        foreach (self::RECEIPT_PREFIX_CONTRACTS as $prefix => $contract) {
            $rows = $this->receiptRowsForPruning($prefix);
            if ($rows === null) {
                // Custom test storage without retention adapters remains
                // compatible. Production never takes this branch.
                continue;
            }

            $scopedRows = [];
            foreach ($rows as $row) {
                if (!is_array($row)
                    || (string)($row['moduleId'] ?? '') !== self::RECEIPT_MODULE_ID
                    || !array_key_exists('siteId', $row)
                    || !in_array($row['siteId'], [null, ''], true)) {
                    continue;
                }
                $name = (string)($row['name'] ?? '');
                if (preg_match('/^' . preg_quote($prefix, '/') . '[a-f0-9]{24}$/D', $name) !== 1) {
                    continue;
                }
                $scopedRows[] = $row;
            }
            if (count($scopedRows) > self::RECEIPT_MAX_COUNT_PER_TYPE + 1) {
                throw new \RuntimeException(
                    'Catalog write receipt retention exceeded its bounded cleanup window.',
                    409
                );
            }

            $normalized = [];
            foreach ($scopedRows as $row) {
                $name = (string)$row['name'];
                if (isset($normalized[$name])) {
                    throw new \RuntimeException('Duplicate global catalog write receipt row.', 409);
                }
                $payload = $this->decodeReceiptRetentionPayload($row['value'] ?? null);
                if ((string)($payload['contract'] ?? '') !== $contract) {
                    throw new \RuntimeException('Catalog write receipt has an invalid retention contract.', 409);
                }
                $normalized[$name] = [
                    'name' => $name,
                    'createdAt' => $this->parseReceiptCreatedAt($payload['createdAt'] ?? null, $now),
                ];
            }

            if (strpos($currentName, $prefix) === 0 && !isset($normalized[$currentName])) {
                throw new \RuntimeException('The current catalog write receipt is missing during retention.', 409);
            }

            $survivors = [];
            foreach ($normalized as $name => $row) {
                if ($name !== $currentName
                    && $row['createdAt'] <= $now - self::RECEIPT_TTL_SECONDS) {
                    $deletions[$name] = true;
                    continue;
                }
                $survivors[] = $row;
            }
            usort($survivors, static function (array $left, array $right): int {
                $createdOrder = ((int)$left['createdAt']) <=> ((int)$right['createdAt']);
                return $createdOrder !== 0
                    ? $createdOrder
                    : strcmp((string)$left['name'], (string)$right['name']);
            });
            $excess = count($survivors) - self::RECEIPT_MAX_COUNT_PER_TYPE;
            foreach ($survivors as $row) {
                if ($excess <= 0) {
                    break;
                }
                $name = (string)$row['name'];
                if ($name === $currentName) {
                    continue;
                }
                $deletions[$name] = true;
                $excess--;
            }
            if ($excess > 0) {
                throw new \RuntimeException('The current receipt cannot be retained within the receipt limit.', 409);
            }
        }

        if ($deletions !== []) {
            $this->deleteReceiptRows(array_keys($deletions));
        }
    }

    /** @return array<int,array<string,mixed>>|null */
    private function receiptRowsForPruning(string $prefix): ?array
    {
        if (isset($this->adapters['list_receipts'])) {
            $rows = call_user_func($this->adapters['list_receipts'], $prefix);
            if (!is_array($rows)) {
                throw new \RuntimeException('Receipt retention reader returned an invalid row set.', 409);
            }
            return array_values($rows);
        }
        if (isset($this->adapters['save_receipt']) || isset($this->adapters['load_receipt'])) {
            return null;
        }
        if ($this->transactionConnection === null) {
            throw new \RuntimeException('Receipt retention requires an active catalog transaction.');
        }
        if (!isset(self::RECEIPT_PREFIX_CONTRACTS[$prefix])) {
            throw new \RuntimeException('Invalid catalog receipt retention prefix.');
        }
        $pattern = '^' . $prefix . '[a-f0-9]{24}$';
        $result = $this->transactionConnection->query(
            "SELECT MODULE_ID, NAME, VALUE, SITE_ID FROM b_option WHERE MODULE_ID='" . self::RECEIPT_MODULE_ID
            . "' AND (SITE_ID IS NULL OR SITE_ID='') AND BINARY NAME REGEXP '" . $pattern
            . "' ORDER BY MODULE_ID, NAME, SITE_ID LIMIT "
            . (self::RECEIPT_MAX_COUNT_PER_TYPE + 2) . ' FOR UPDATE'
        );
        $rows = [];
        while (is_object($result) && method_exists($result, 'fetch') && ($row = $result->fetch())) {
            $rows[] = [
                'moduleId' => self::RECEIPT_MODULE_ID,
                'siteId' => $row['SITE_ID'] ?? $row['site_id'] ?? null,
                'name' => (string)($row['NAME'] ?? $row['name'] ?? ''),
                'value' => (string)($row['VALUE'] ?? $row['value'] ?? ''),
            ];
        }
        return $rows;
    }

    /** @param string[] $names */
    private function deleteReceiptRows(array $names): void
    {
        $names = array_values(array_unique(array_filter($names, static function ($name): bool {
            return is_string($name)
                && preg_match('/^CATALOG_(?:WRITE|BATCH)_RECEIPT_[a-f0-9]{24}$/D', $name) === 1;
        })));
        sort($names, SORT_STRING);
        if ($names === []) {
            return;
        }
        if (isset($this->adapters['delete_receipts'])) {
            call_user_func($this->adapters['delete_receipts'], $names);
            return;
        }
        if ($this->transactionConnection === null) {
            throw new \RuntimeException('Receipt retention delete requires an active catalog transaction.');
        }
        $helper = $this->transactionConnection->getSqlHelper();
        $escapedNames = array_map(static function (string $name) use ($helper): string {
            return "'" . $helper->forSql($name) . "'";
        }, $names);
        $this->transactionConnection->queryExecute(
            "DELETE FROM b_option WHERE MODULE_ID='" . self::RECEIPT_MODULE_ID
            . "' AND (SITE_ID IS NULL OR SITE_ID='') AND NAME IN (" . implode(',', $escapedNames) . ')'
        );
    }

    /** @param mixed $raw @return array<string,mixed> */
    private function decodeReceiptRetentionPayload($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('Catalog write receipt retention payload is malformed.', 409);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Catalog write receipt retention payload is malformed.', 409);
        }
        return $decoded;
    }

    /** @param mixed $raw */
    private function parseReceiptCreatedAt($raw, int $now): int
    {
        if (!is_string($raw)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $raw) !== 1) {
            throw new \RuntimeException('Catalog write receipt has an invalid createdAt timestamp.', 409);
        }
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $raw);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$value
            || (is_array($errors) && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
            || $value->format('Y-m-d\TH:i:sP') !== $raw) {
            throw new \RuntimeException('Catalog write receipt has an invalid createdAt timestamp.', 409);
        }
        $timestamp = $value->getTimestamp();
        if ($timestamp > $now + self::RECEIPT_MAX_FUTURE_SKEW_SECONDS) {
            throw new \RuntimeException('Catalog write receipt createdAt is unexpectedly in the future.', 409);
        }
        return $timestamp;
    }

    private function receiptNow(): int
    {
        if (isset($this->adapters['receipt_now'])) {
            $now = call_user_func($this->adapters['receipt_now']);
            if (!is_int($now) || $now <= 0) {
                throw new \RuntimeException('Receipt retention clock returned an invalid timestamp.', 409);
            }
            return $now;
        }
        return time();
    }

    private function getBatchRecalculateService(): BatchRecalculateService
    {
        if ($this->batchRecalculateService === null) {
            $snapshot = $this->captureRuntimeConfigSnapshot();
            $url = trim((string)($snapshot['prospektweb.calc:CALC_SERVER_URL'] ?? ''));
            if ($url === '') {
                $url = 'https://pwrt.ru/calc-api';
            }
            $this->batchRecalculateService = new BatchRecalculateService($url, 30);
        }
        return $this->batchRecalculateService;
    }

    /** @return array<string,mixed> */
    private function resolveRuntime(array $offerIds, string $siteId): array
    {
        if (isset($this->adapters['resolve_runtime'])) {
            $payload = call_user_func($this->adapters['resolve_runtime'], $offerIds, $siteId);
            if (!is_array($payload)) {
                throw new \RuntimeException('Серверный resolver вернул некорректный payload.');
            }
            return $payload;
        }
        $configBefore = $this->captureRuntimeConfigSnapshot();
        $payload = (new InitPayloadService())->prepareCatalogWritePayload(
            $offerIds,
            $siteId,
            null,
            null,
            null,
            null,
            null,
            null,
            $configBefore
        );
        $configAfter = $this->captureRuntimeConfigSnapshot();
        if (!hash_equals(self::canonicalEncode($configBefore), self::canonicalEncode($configAfter))) {
            throw new \RuntimeException('ConfigManager options changed while resolving catalog runtime.', 409);
        }
        $payload['_runtimeConfigSnapshot'] = $configBefore;
        return $payload;
    }

    /** @param array<string,mixed> $adapter @return array<string,mixed> */
    private function resolveRuntimeWithAdapter(array $offerIds, string $siteId, array $adapter): array
    {
        if (isset($this->adapters['resolve_runtime_candidate'])) {
            $payload = call_user_func(
                $this->adapters['resolve_runtime_candidate'],
                $offerIds,
                $siteId,
                $adapter
            );
            if (!is_array($payload)) {
                throw new \RuntimeException('Candidate adapter resolver returned an invalid payload.');
            }
            return $payload;
        }
        $configBefore = $this->captureRuntimeConfigSnapshot();
        $payload = (new InitPayloadService())->prepareCatalogWritePayload(
            $offerIds,
            $siteId,
            null,
            $adapter,
            null,
            null,
            null,
            null,
            $configBefore,
            true
        );
        $configAfter = $this->captureRuntimeConfigSnapshot();
        if (!hash_equals(self::canonicalEncode($configBefore), self::canonicalEncode($configAfter))) {
            throw new \RuntimeException(
                'ConfigManager options changed while resolving the adapter candidate.',
                409
            );
        }
        $payload['_runtimeConfigSnapshot'] = $configBefore;
        return $payload;
    }

    /** @return array<string,mixed> */
    private function resolveRuntimePinned(
        array $offerIds,
        string $siteId,
        ?array $adapterOverride = null,
        bool $allowInactiveNeutralInputForAdapterAuthoring = false
    ): array
    {
        if (isset($this->adapters['resolve_runtime_pinned'])) {
            $payload = call_user_func(
                $this->adapters['resolve_runtime_pinned'],
                $offerIds,
                $siteId,
                $adapterOverride,
                $allowInactiveNeutralInputForAdapterAuthoring
            );
            if (!is_array($payload)) {
                throw new \RuntimeException('Pinned resolver вернул некорректный payload.');
            }
            return $payload;
        }
        // Unit-test adapters have no Bitrix connection. Reusing their explicit
        // resolver still exercises the locked snapshot/CAS sequence.
        if (isset($this->adapters['resolve_runtime'])) {
            return $this->resolveRuntime($offerIds, $siteId);
        }
        if ($this->transactionConnection === null) {
            throw new \RuntimeException('Pinned runtime можно читать только внутри транзакции записи.');
        }
        if (!class_exists('\Prospektweb\Frontcalc\Service\FormFirstAuthoringStore')
            || !method_exists(
                '\Prospektweb\Frontcalc\Service\FormFirstAuthoringStore',
                'publishedAuthoringFromRaw'
            )
            || !method_exists(
                '\Prospektweb\Frontcalc\Service\FormFirstAuthoringStore',
                'publishedSnapshotFromRaw'
            )) {
            throw new \RuntimeException('FrontCalc не предоставляет raw resolver публикации формы.');
        }

        $formRaw = $this->readLockedOptionValue('prospektweb.frontcalc', 'FORM_FIRST_PRESET_12740');
        $adapterState = $this->readLockedOptionState(
            'prospektweb.calc',
            'CATALOG_ADAPTER_12740'
        );
        $adapterRaw = $adapterState['value'];
        $adapterPersisted = $adapterState['exists'] === true && $adapterRaw !== '';
        $neutralInputState = $this->readLockedOptionState(
            'prospektweb.calc',
            'PRESET_12740_NEUTRAL_INPUT_ACTIVE'
        );
        $authoring = \Prospektweb\Frontcalc\Service\FormFirstAuthoringStore::publishedAuthoringFromRaw(
            CatalogAdapterDefinitionService::PRESET_ID,
            $formRaw
        );
        if (!is_array($authoring)) {
            throw new \RuntimeException('Заблокированная публикация формы пресета 12740 отсутствует или повреждена.');
        }
        $publishedSnapshot = \Prospektweb\Frontcalc\Service\FormFirstAuthoringStore::publishedSnapshotFromRaw(
            CatalogAdapterDefinitionService::PRESET_ID,
            $formRaw
        );
        if (!is_array($publishedSnapshot)) {
            throw new \RuntimeException('The locked published preset snapshot is absent or corrupt.');
        }
        $storedAdapter = (new CatalogAdapterDefinitionService())->loadFromRaw(
            CatalogAdapterDefinitionService::PRESET_ID,
            $adapterRaw
        );
        $adapter = $adapterOverride ?? $storedAdapter;
        $neutralInputRequired = $allowInactiveNeutralInputForAdapterAuthoring
            ? $this->parseAdapterAuthoringNeutralInputOption($neutralInputState)
            : $this->parseNeutralInputOption($neutralInputState);
        $runtimeConfigSnapshot = $this->captureRuntimeConfigSnapshot();
        $globalSymbolService = new GlobalSymbolService();
        $globalSymbolIblockId = $this->effectiveRuntimeConfigIblockId(
            $runtimeConfigSnapshot,
            'CALC_GLOBAL_VALUES'
        );
        if ($globalSymbolIblockId <= 0) {
            throw new \RuntimeException('IBLOCK_CALC_GLOBAL_VALUES is not pinned by runtime configuration.');
        }
        $globalSymbols = $globalSymbolService->listReadOnlyFromIblockId(
            $globalSymbolIblockId,
            CatalogAdapterDefinitionService::PRESET_ID
        );

        $payloadService = new InitPayloadService();
        $payload = $allowInactiveNeutralInputForAdapterAuthoring
            ? $payloadService->prepareCatalogWritePayload(
                $offerIds,
                $siteId,
                $authoring,
                $adapter,
                $publishedSnapshot,
                $neutralInputRequired,
                $globalSymbols,
                $globalSymbolIblockId,
                $runtimeConfigSnapshot,
                true,
                $adapterPersisted
            )
            : $payloadService->prepareCatalogWritePayloadPinned(
                $offerIds,
                $siteId,
                $authoring,
                $adapter,
                $publishedSnapshot,
                $neutralInputRequired,
                $globalSymbols,
                $globalSymbolIblockId,
                $runtimeConfigSnapshot,
                $adapterPersisted
            );
        $payload['_runtimeConfigSnapshot'] = $runtimeConfigSnapshot;
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $adapter
     * @return array<string,mixed>
     */
    private function assertAdapterPreviewMatchesPayload(array $payload, array $adapter): array
    {
        $runtime = is_array($payload['editorRuntime'] ?? null) ? $payload['editorRuntime'] : [];
        $preview = $this->assertAdapterPreviewReady(
            is_array($payload['selectedOffers'] ?? null) ? $payload['selectedOffers'] : [],
            [
                'formDefinition' => is_array($runtime['formDefinition'] ?? null)
                    ? $runtime['formDefinition']
                    : [],
                'bindingDefinition' => is_array($runtime['bindingDefinition'] ?? null)
                    ? $runtime['bindingDefinition']
                    : [],
                'publication' => is_array($runtime['publication'] ?? null)
                    ? $runtime['publication']
                    : [],
            ],
            $adapter
        );
        $runtimeAdapter = is_array($runtime['catalogAdapter'] ?? null)
            ? $runtime['catalogAdapter']
            : [];
        if (!hash_equals(
            (string)($adapter['revision'] ?? ''),
            (string)($runtimeAdapter['revision'] ?? '')
        ) || !hash_equals(
            self::canonicalEncode($preview['scenarios']),
            self::canonicalEncode(
                is_array($runtime['catalogScenarios'] ?? null) ? $runtime['catalogScenarios'] : []
            )
        )) {
            throw new \RuntimeException(
                'Catalog adapter candidate does not match the resolved editor runtime.',
                409
            );
        }
        return $preview;
    }

    /**
     * @param array<int,array<string,mixed>> $offers
     * @param array<string,mixed> $authoring
     * @param array<string,mixed> $adapter
     * @return array<string,mixed>
     */
    private function assertAdapterPreviewReady(
        array $offers,
        array $authoring,
        array $adapter
    ): array {
        $preview = (new CatalogAdapterDefinitionService())->previewMappings(
            $offers,
            is_array($authoring['formDefinition'] ?? null) ? $authoring['formDefinition'] : [],
            is_array($authoring['bindingDefinition'] ?? null) ? $authoring['bindingDefinition'] : [],
            is_array($authoring['publication'] ?? null) ? $authoring['publication'] : [],
            $adapter
        );
        if (($preview['ready'] ?? false) !== true) {
            $firstError = is_array($preview['errors'][0] ?? null)
                ? trim((string)($preview['errors'][0]['message'] ?? ''))
                : '';
            throw new \InvalidArgumentException(
                $firstError !== ''
                    ? $firstError
                    : 'Catalog adapter does not map every selected offer.'
            );
        }
        return $preview;
    }

    /** @return array<string,mixed> */
    private function readLockedPublishedAuthoring(): array
    {
        if (isset($this->adapters['read_locked_authoring'])) {
            $authoring = call_user_func($this->adapters['read_locked_authoring']);
            if (!is_array($authoring)) {
                throw new \RuntimeException('Locked form authoring adapter returned invalid data.');
            }
            $authoring['neutralInputRequired'] = $this->parseAdapterAuthoringNeutralInputOption(
                $this->readLockedOptionState(
                    'prospektweb.calc',
                    'PRESET_12740_NEUTRAL_INPUT_ACTIVE'
                )
            );
            $authoring['runtimeConfigSnapshot'] = $this->captureRuntimeConfigSnapshot();
            return $authoring;
        }
        if (!class_exists('\Prospektweb\Frontcalc\Service\FormFirstAuthoringStore')
            || !method_exists(
                '\Prospektweb\Frontcalc\Service\FormFirstAuthoringStore',
                'publishedAuthoringFromRaw'
            )
            || !method_exists(
                '\Prospektweb\Frontcalc\Service\FormFirstAuthoringStore',
                'publishedSnapshotFromRaw'
            )) {
            throw new \RuntimeException('FrontCalc does not expose the locked publication resolver.');
        }
        $formRaw = $this->readLockedOptionValue(
            'prospektweb.frontcalc',
            'FORM_FIRST_PRESET_12740'
        );
        $neutralInputRequired = $this->parseAdapterAuthoringNeutralInputOption($this->readLockedOptionState(
            'prospektweb.calc',
            'PRESET_12740_NEUTRAL_INPUT_ACTIVE'
        ));
        $authoring = \Prospektweb\Frontcalc\Service\FormFirstAuthoringStore::publishedAuthoringFromRaw(
            CatalogAdapterDefinitionService::PRESET_ID,
            $formRaw
        );
        $snapshot = \Prospektweb\Frontcalc\Service\FormFirstAuthoringStore::publishedSnapshotFromRaw(
            CatalogAdapterDefinitionService::PRESET_ID,
            $formRaw
        );
        if (!is_array($authoring) || !is_array($snapshot)) {
            throw new \RuntimeException('The locked published form is absent or corrupt.', 409);
        }
        $publication = is_array($authoring['publication'] ?? null)
            ? $authoring['publication']
            : [];
        $snapshotMeta = is_array($snapshot['_form_first'] ?? null)
            ? $snapshot['_form_first']
            : [];
        if ((int)($publication['revision'] ?? 0) <= 0
            || (int)($snapshotMeta['publishedRevision'] ?? 0) !== (int)$publication['revision']
            || !hash_equals(
                (string)($publication['compileHash'] ?? ''),
                (string)($snapshotMeta['compileHash'] ?? '')
            )) {
            throw new \RuntimeException('Locked form publication and runtime snapshot do not match.', 409);
        }
        $authoring['neutralInputRequired'] = $neutralInputRequired;
        $authoring['runtimeConfigSnapshot'] = $this->captureRuntimeConfigSnapshot();
        return $authoring;
    }

    /**
     * @param array<string,mixed> $authoring
     * @param array<string,mixed> $adapter
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    private function buildManualAdapterRuntime(
        array $authoring,
        array $adapter,
        array $preview
    ): array {
        return [
            'contract' => CatalogAdapterDefinitionService::EDITOR_RUNTIME_CONTRACT,
            'launchContext' => [
                'contract' => CatalogAdapterDefinitionService::LAUNCH_CONTEXT_CONTRACT,
                'mode' => 'manual',
                'presetId' => CatalogAdapterDefinitionService::PRESET_ID,
                'productIds' => [],
                'offerIds' => [],
            ],
            'formDefinition' => $authoring['formDefinition'],
            'bindingDefinition' => $authoring['bindingDefinition'],
            'publication' => $authoring['publication'],
            'catalogAdapter' => $adapter,
            'catalogScenarios' => is_array($preview['scenarios'] ?? null)
                ? $preview['scenarios']
                : [],
            'catalogMapping' => [
                'adapterPersisted' => true,
                'ready' => ($preview['ready'] ?? false) === true,
                'hasTargets' => ($preview['hasTargets'] ?? false) === true,
                'adapterRevision' => (string)($preview['adapterRevision'] ?? ''),
                'errors' => is_array($preview['errors'] ?? null) ? $preview['errors'] : [],
            ],
        ];
    }

    private function writeLockedAdapterOptionRaw(string $raw): void
    {
        if (isset($this->adapters['write_locked_adapter_raw'])) {
            call_user_func($this->adapters['write_locked_adapter_raw'], $raw);
            return;
        }
        if ($this->transactionConnection === null) {
            throw new \RuntimeException('Catalog adapter option cannot be written outside a transaction.');
        }
        $helper = $this->transactionConnection->getSqlHelper();
        $escapedRaw = $helper->forSql($raw);
        $result = $this->transactionConnection->query(
            "SELECT VALUE FROM b_option WHERE MODULE_ID='prospektweb.calc' "
            . "AND NAME='CATALOG_ADAPTER_12740' AND (SITE_ID IS NULL OR SITE_ID='') "
            . 'ORDER BY MODULE_ID, NAME, SITE_ID FOR UPDATE'
        );
        $row = is_object($result) && method_exists($result, 'fetch') ? $result->fetch() : null;
        $duplicate = is_object($result) && method_exists($result, 'fetch') ? $result->fetch() : null;
        if (is_array($duplicate)) {
            throw new \RuntimeException('Duplicate global catalog adapter option row.', 409);
        }
        if (is_array($row)) {
            $this->transactionConnection->queryExecute(
                "UPDATE b_option SET VALUE='" . $escapedRaw . "' "
                . "WHERE MODULE_ID='prospektweb.calc' AND NAME='CATALOG_ADAPTER_12740' "
                . "AND (SITE_ID IS NULL OR SITE_ID='')"
            );
            return;
        }
        $this->transactionConnection->queryExecute(
            "INSERT INTO b_option (MODULE_ID, NAME, VALUE, SITE_ID) VALUES "
            . "('prospektweb.calc','CATALOG_ADAPTER_12740','" . $escapedRaw . "',NULL)"
        );
    }

    private function readLockedOptionValue(string $moduleId, string $name): string
    {
        $state = $this->readLockedOptionState($moduleId, $name);
        return $state['exists'] ? $state['value'] : '';
    }

    /** @return array{exists:bool,value:string} */
    private function readLockedOptionState(string $moduleId, string $name): array
    {
        $allowed = [
            'prospektweb.frontcalc:FORM_FIRST_PRESET_12740' => true,
            'prospektweb.calc:CATALOG_ADAPTER_12740' => true,
            'prospektweb.calc:PRESET_12740_NEUTRAL_INPUT_ACTIVE' => true,
        ];
        if (!isset($allowed[$moduleId . ':' . $name])) {
            throw new \RuntimeException('Запрошено неподтверждённое option-значение runtime.');
        }
        if (isset($this->adapters['read_locked_option_state'])) {
            $state = call_user_func($this->adapters['read_locked_option_state'], $moduleId, $name);
            if (!is_array($state)
                || !array_key_exists('exists', $state)
                || !array_key_exists('value', $state)) {
                throw new \RuntimeException('Locked option-state adapter returned invalid data.');
            }
            return ['exists' => $state['exists'] === true, 'value' => (string)$state['value']];
        }
        if (isset($this->adapters['read_locked_option'])) {
            return [
                'exists' => true,
                'value' => (string)call_user_func($this->adapters['read_locked_option'], $moduleId, $name),
            ];
        }
        if ($this->transactionConnection === null) {
            throw new \RuntimeException('Locked runtime option cannot be read outside a transaction.');
        }
        $result = $this->transactionConnection->query(
            "SELECT VALUE FROM b_option WHERE MODULE_ID='" . $moduleId
            . "' AND NAME='" . $name
            . "' AND (SITE_ID IS NULL OR SITE_ID='') "
            . 'ORDER BY MODULE_ID, NAME, SITE_ID FOR UPDATE'
        );
        $row = is_object($result) && method_exists($result, 'fetch') ? $result->fetch() : null;
        $duplicate = is_object($result) && method_exists($result, 'fetch') ? $result->fetch() : null;
        if (is_array($duplicate)) {
            throw new \RuntimeException('Duplicate global runtime option row.', 409);
        }
        return [
            'exists' => is_array($row),
            'value' => is_array($row) ? (string)($row['VALUE'] ?? $row['value'] ?? '') : '',
        ];
    }

    /** @param array{exists:bool,value:string} $state */
    private function parseNeutralInputOption(array $state): bool
    {
        if ($state['exists'] === true && $state['value'] === 'Y') {
            return true;
        }
        throw new \RuntimeException(
            'Catalog calculation requires PRESET_12740_NEUTRAL_INPUT_ACTIVE=Y.',
            409
        );
    }

    /** @param array{exists:bool,value:string} $state */
    private function parseAdapterAuthoringNeutralInputOption(array $state): bool
    {
        if ($state['exists'] !== true) {
            return false;
        }
        if ($state['value'] === 'Y') {
            return true;
        }
        if ($state['value'] === 'N') {
            return false;
        }
        throw new \RuntimeException(
            'PRESET_12740_NEUTRAL_INPUT_ACTIVE must be Y, N or absent for adapter authoring.',
            409
        );
    }

    private function captureAdapterAuthoringNeutralInputState(): string
    {
        if (isset($this->adapters['capture_neutral_input_state'])) {
            return $this->normalizeAdapterAuthoringNeutralInputState(
                call_user_func($this->adapters['capture_neutral_input_state'])
            );
        }
        if (isset($this->adapters['capture_neutral_input_mode'])) {
            $raw = call_user_func($this->adapters['capture_neutral_input_mode']);
            return $this->normalizeAdapterAuthoringNeutralInputState(
                is_bool($raw) ? ($raw ? 'Y' : 'N') : $raw
            );
        }
        $connection = Application::getConnection();
        $result = $connection->query(
            "SELECT VALUE FROM b_option WHERE MODULE_ID='prospektweb.calc' "
            . "AND NAME='PRESET_12740_NEUTRAL_INPUT_ACTIVE' "
            . "AND (SITE_ID IS NULL OR SITE_ID='') ORDER BY MODULE_ID, NAME, SITE_ID"
        );
        $row = is_object($result) && method_exists($result, 'fetch') ? $result->fetch() : null;
        $duplicate = is_object($result) && method_exists($result, 'fetch') ? $result->fetch() : null;
        if (is_array($duplicate)) {
            throw new \RuntimeException(
                'Neutral-input activation option is ambiguous for adapter authoring.',
                409
            );
        }
        return is_array($row)
            ? $this->normalizeAdapterAuthoringNeutralInputState(
                (string)($row['VALUE'] ?? $row['value'] ?? '')
            )
            : 'MISSING';
    }

    private function readLockedNeutralInputState(): string
    {
        $state = $this->readLockedOptionState(
            'prospektweb.calc',
            'PRESET_12740_NEUTRAL_INPUT_ACTIVE'
        );
        return $state['exists']
            ? $this->normalizeAdapterAuthoringNeutralInputState($state['value'])
            : 'MISSING';
    }

    /** @param mixed $raw */
    private function normalizeAdapterAuthoringNeutralInputState($raw): string
    {
        $raw = (string)$raw;
        if (in_array($raw, ['Y', 'N', 'MISSING'], true)) {
            return $raw;
        }
        throw new \RuntimeException(
            'PRESET_12740_NEUTRAL_INPUT_ACTIVE has an invalid adapter-authoring state.',
            409
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function projectResults(array $results, array $priceTypes, array $adapter, array $publication): array
    {
        if (isset($this->adapters['project_results'])) {
            $projected = call_user_func(
                $this->adapters['project_results'],
                $results,
                $priceTypes,
                $adapter,
                $publication
            );
            if (!is_array($projected)) {
                throw new \RuntimeException('Адаптер результатов вернул некорректную проекцию.');
            }
            return $projected;
        }
        return (new CatalogAdapterDefinitionService())->projectPinnedResultsForWrite(
            $results,
            $priceTypes,
            $adapter,
            $publication
        );
    }

    /** @return array<string,mixed> */
    private function validateProjectedResults(array $projected, array $offerIds): array
    {
        if (isset($this->adapters['validate_projected'])) {
            $result = call_user_func($this->adapters['validate_projected'], $projected, $offerIds);
            return is_array($result) ? $result : ['ready' => false, 'errors' => []];
        }
        return (new OfferUpdateService())->previewOffersFromCalculation($projected, $offerIds);
    }

    /** @return array<string,mixed> */
    private function writeProjectedResults(array $projected): array
    {
        if (isset($this->adapters['write_projected'])) {
            $result = call_user_func($this->adapters['write_projected'], $projected);
            return is_array($result) ? $result : ['status' => 'error', 'errors' => []];
        }
        return (new OfferUpdateService())->updateOffersFromCalculation($projected, true, false);
    }

    /** @return mixed */
    private function withAdapterMutationLock(callable $callback)
    {
        if (isset($this->adapters['adapter_mutation_lock'])) {
            return call_user_func($this->adapters['adapter_mutation_lock'], $callback);
        }
        return (new CatalogAdapterDefinitionService())->withMutationLock(
            CatalogAdapterDefinitionService::PRESET_ID,
            $callback
        );
    }

    private function beginTransaction(): void
    {
        $this->lockedRuntimeConfigSnapshot = null;
        if (isset($this->adapters['begin_transaction'])) {
            call_user_func($this->adapters['begin_transaction']);
            return;
        }
        $this->transactionConnection = Application::getConnection();
        $this->transactionConnection->startTransaction();
    }

    private function commitTransaction(): void
    {
        if (isset($this->adapters['commit_transaction'])) {
            call_user_func($this->adapters['commit_transaction']);
            $this->lockedRuntimeConfigSnapshot = null;
            return;
        }
        if ($this->transactionConnection === null) {
            throw new \RuntimeException('Транзакция записи каталога не была начата.');
        }
        $this->transactionConnection->commitTransaction();
        $this->transactionConnection = null;
        $this->lockedRuntimeConfigSnapshot = null;
    }

    private function rollbackTransaction(): void
    {
        if (isset($this->adapters['rollback_transaction'])) {
            call_user_func($this->adapters['rollback_transaction']);
            $this->lockedRuntimeConfigSnapshot = null;
            return;
        }
        if ($this->transactionConnection !== null) {
            $this->transactionConnection->rollbackTransaction();
            $this->transactionConnection = null;
        }
        $this->lockedRuntimeConfigSnapshot = null;
    }

    /** @param int[] $offerIds @param int[] $productIds */
    private function lockCatalogRows(array $offerIds, array $productIds): void
    {
        if (isset($this->adapters['lock_catalog_rows'])) {
            call_user_func($this->adapters['lock_catalog_rows'], $offerIds, $productIds);
            return;
        }
        if ($this->transactionConnection === null) {
            throw new \RuntimeException('Нельзя заблокировать каталог вне транзакции.');
        }
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static function (int $productId): bool {
            return $productId > 0;
        })));
        sort($productIds, SORT_NUMERIC);
        if ($productIds === []) {
            throw new \RuntimeException('Не удалось определить родительские товары для блокировки каталога.');
        }
        $elementIds = array_values(array_unique(array_merge($offerIds, $productIds)));
        sort($elementIds, SORT_NUMERIC);
        $elementIdList = implode(',', array_map('intval', $elementIds));
        $offerIdList = implode(',', array_map('intval', $offerIds));
        $elementRows = $this->selectForUpdate(
            'SELECT ID, IBLOCK_ID FROM b_iblock_element WHERE ID IN (' . $elementIdList . ') ORDER BY ID FOR UPDATE'
        );
        $iblockIds = [];
        foreach ($elementRows as $row) {
            $iblockId = (int)($row['IBLOCK_ID'] ?? $row['iblock_id'] ?? 0);
            if ($iblockId > 0) {
                $iblockIds[$iblockId] = true;
            }
        }
        if (count($elementRows) !== count($elementIds)) {
            throw new \RuntimeException('Не все торговые предложения и родительские товары существуют в момент блокировки каталога.');
        }

        $catalogProductRows = $this->selectForUpdate(
            'SELECT ID, MEASURE FROM b_catalog_product WHERE ID IN (' . $elementIdList . ') ORDER BY ID FOR UPDATE'
        );
        $this->selectForUpdate(
            'SELECT ID FROM b_catalog_measure_ratio WHERE PRODUCT_ID IN (' . $elementIdList
            . ') ORDER BY PRODUCT_ID, ID FOR UPDATE'
        );
        $measureIds = [];
        foreach ($catalogProductRows as $catalogProductRow) {
            $measureId = (int)($catalogProductRow['MEASURE'] ?? $catalogProductRow['measure'] ?? 0);
            if ($measureId > 0) {
                $measureIds[$measureId] = true;
            }
        }
        if ($measureIds !== []) {
            $this->selectForUpdate(
                'SELECT ID FROM b_catalog_measure WHERE ID IN ('
                . implode(',', array_map('intval', array_keys($measureIds))) . ') ORDER BY ID FOR UPDATE'
            );
        }
        $this->selectForUpdate(
            'SELECT ID FROM b_catalog_price WHERE PRODUCT_ID IN (' . $offerIdList
            . ') ORDER BY PRODUCT_ID, ID FOR UPDATE'
        );

        // CatalogScenario values come from offer properties. Lock both legacy
        // and separate-property storage rows to pin those semantic inputs too.
        $this->selectForUpdate(
            'SELECT ID FROM b_iblock_element_property WHERE IBLOCK_ELEMENT_ID IN (' . $elementIdList
            . ') ORDER BY IBLOCK_ELEMENT_ID, ID FOR UPDATE'
        );
        if ($iblockIds !== []) {
            $iblockIdList = implode(',', array_map('intval', array_keys($iblockIds)));
            $iblockRows = $this->selectForUpdate(
                'SELECT ID, VERSION FROM b_iblock WHERE ID IN (' . $iblockIdList . ') ORDER BY ID FOR UPDATE'
            );
            foreach ($iblockRows as $iblockRow) {
                $iblockId = (int)($iblockRow['ID'] ?? $iblockRow['id'] ?? 0);
                $version = (int)($iblockRow['VERSION'] ?? $iblockRow['version'] ?? 1);
                if ($iblockId <= 0 || $version !== 2) {
                    continue;
                }
                $this->selectForUpdate(
                    'SELECT IBLOCK_ELEMENT_ID FROM b_iblock_element_prop_s' . $iblockId
                    . ' WHERE IBLOCK_ELEMENT_ID IN (' . $elementIdList
                    . ') ORDER BY IBLOCK_ELEMENT_ID FOR UPDATE'
                );
                $this->selectForUpdate(
                    'SELECT ID FROM b_iblock_element_prop_m' . $iblockId
                    . ' WHERE IBLOCK_ELEMENT_ID IN (' . $elementIdList
                    . ') ORDER BY IBLOCK_ELEMENT_ID, ID FOR UPDATE'
                );
            }
            $propertyRows = $this->selectForUpdate(
                'SELECT ID FROM b_iblock_property WHERE IBLOCK_ID IN (' . $iblockIdList
                . ') ORDER BY ID FOR UPDATE'
            );
            $propertyIds = [];
            foreach ($propertyRows as $propertyRow) {
                $propertyId = (int)($propertyRow['ID'] ?? $propertyRow['id'] ?? 0);
                if ($propertyId > 0) {
                    $propertyIds[] = $propertyId;
                }
            }
            if ($propertyIds !== []) {
                $this->selectForUpdate(
                    'SELECT ID FROM b_iblock_property_enum WHERE PROPERTY_ID IN ('
                    . implode(',', $propertyIds) . ') ORDER BY PROPERTY_ID, ID FOR UPDATE'
                );
            }
        }
    }

    /** Lock option-backed runtime pins before every mutable/source row. */
    private function lockRuntimeOptionRows(array $expectedConfigSnapshot = []): array
    {
        $lockedSnapshot = null;
        if (isset($this->adapters['lock_runtime_options'])) {
            $adapterSnapshot = call_user_func($this->adapters['lock_runtime_options'], $expectedConfigSnapshot);
            if (is_array($adapterSnapshot)) {
                $lockedSnapshot = $this->normalizeRuntimeConfigSnapshot($adapterSnapshot);
            } elseif (isset($this->adapters['capture_runtime_config'])) {
                $adapterSnapshot = call_user_func($this->adapters['capture_runtime_config']);
                $lockedSnapshot = $this->normalizeRuntimeConfigSnapshot(is_array($adapterSnapshot) ? $adapterSnapshot : []);
            } elseif ($expectedConfigSnapshot !== []) {
                // A test/integration adapter owns the physical lock and the
                // comparison. Retain the exact reviewed snapshot so later
                // pinned resolvers cannot perform an ordinary read in the
                // transaction.
                $lockedSnapshot = $this->normalizeRuntimeConfigSnapshot($expectedConfigSnapshot);
            }
        } else {
            if ($this->transactionConnection === null) {
                throw new \RuntimeException('Runtime options cannot be locked outside a transaction.');
            }
            $conditions = [
                "(MODULE_ID='prospektweb.frontcalc' AND NAME='FORM_FIRST_PRESET_12740')",
                "(MODULE_ID='prospektweb.calc' AND NAME='CATALOG_ADAPTER_12740')",
                "(MODULE_ID='prospektweb.calc' AND NAME='PRESET_12740_NEUTRAL_INPUT_ACTIVE')",
            ];
            foreach (self::RUNTIME_CONFIG_OPTION_KEYS as $moduleId => $names) {
                foreach ($names as $name) {
                    $conditions[] = "(MODULE_ID='" . $moduleId . "' AND NAME='" . $name . "')";
                }
            }
            $lockedRows = $this->selectForUpdate(
                'SELECT MODULE_ID, NAME, VALUE, SITE_ID FROM b_option WHERE (' . implode(' OR ', $conditions)
                . ") AND (SITE_ID IS NULL OR SITE_ID='') ORDER BY MODULE_ID, NAME, SITE_ID FOR UPDATE"
            );
            $lockedSnapshot = $this->runtimeConfigSnapshotFromRows($lockedRows);
        }
        if ($lockedSnapshot === null) {
            throw new \RuntimeException('Locked runtime configuration snapshot is unavailable.', 409);
        }
        $this->lockedRuntimeConfigSnapshot = $lockedSnapshot;
        if ($expectedConfigSnapshot !== []) {
            $expectedConfigSnapshot = $this->normalizeRuntimeConfigSnapshot($expectedConfigSnapshot);
            if (!hash_equals(
                self::canonicalEncode($expectedConfigSnapshot),
                self::canonicalEncode($lockedSnapshot)
            )) {
                throw new \RuntimeException('ConfigManager options changed after calc-server calculation.', 409);
            }
        }
        return $lockedSnapshot;
    }

    /** @param array<string,mixed> $runtimeLocks */
    private function lockRuntimeSourceRows(array $runtimeLocks): void
    {
        $runtimeLocks = $this->normalizeRuntimeLocks($runtimeLocks);
        if (isset($this->adapters['lock_runtime_rows'])) {
            call_user_func($this->adapters['lock_runtime_rows'], $runtimeLocks);
            return;
        }
        if ($this->transactionConnection === null) {
            throw new \RuntimeException('Runtime-источники нельзя заблокировать вне транзакции.');
        }

        // elementsSiblings and directory stores are membership-sensitive. A
        // newly inserted variant can change calc-server selection even when
        // every previously seen element is unchanged, so lock each complete
        // finite source-iblock range before the locked re-resolution.
        $sourceIblockIds = array_map('intval', $runtimeLocks['sourceIblockIds']);
        $sourceIblockIdList = implode(',', $sourceIblockIds);
        $sourceIblockRows = $this->selectForUpdate(
            'SELECT ID FROM b_iblock WHERE ID IN (' . $sourceIblockIdList . ') ORDER BY ID FOR UPDATE'
        );
        $actualSourceIblockIds = [];
        foreach ($sourceIblockRows as $sourceIblockRow) {
            $sourceIblockId = (int)($sourceIblockRow['ID'] ?? $sourceIblockRow['id'] ?? 0);
            if ($sourceIblockId > 0) {
                $actualSourceIblockIds[] = $sourceIblockId;
            }
        }
        sort($actualSourceIblockIds, SORT_NUMERIC);
        if ($actualSourceIblockIds !== $sourceIblockIds) {
            throw new \RuntimeException('The runtime source iblock membership changed before catalog write.', 409);
        }
        $membershipRows = $this->selectForUpdate(
            'SELECT ID, IBLOCK_ID FROM b_iblock_element WHERE IBLOCK_ID IN ('
            . $sourceIblockIdList . ') ORDER BY IBLOCK_ID, ID FOR UPDATE'
        );
        $membershipElementIds = [];
        foreach ($membershipRows as $membershipRow) {
            $membershipElementId = (int)($membershipRow['ID'] ?? $membershipRow['id'] ?? 0);
            if ($membershipElementId > 0) {
                $membershipElementIds[] = $membershipElementId;
            }
        }
        sort($membershipElementIds, SORT_NUMERIC);
        if ($membershipElementIds === []) {
            throw new \RuntimeException('The runtime source iblock ranges are empty.', 409);
        }
        $membershipElementIdList = implode(',', $membershipElementIds);
        $membershipCatalogRows = $this->selectForUpdate(
            'SELECT ID, MEASURE FROM b_catalog_product WHERE ID IN (' . $membershipElementIdList
            . ') ORDER BY ID FOR UPDATE'
        );
        $membershipMeasureIds = [];
        foreach ($membershipCatalogRows as $membershipCatalogRow) {
            $measureId = (int)($membershipCatalogRow['MEASURE'] ?? $membershipCatalogRow['measure'] ?? 0);
            if ($measureId > 0) {
                $membershipMeasureIds[$measureId] = true;
            }
        }
        $this->selectForUpdate(
            'SELECT ID FROM b_catalog_measure_ratio WHERE PRODUCT_ID IN (' . $membershipElementIdList
            . ') ORDER BY PRODUCT_ID, ID FOR UPDATE'
        );
        $this->selectForUpdate(
            'SELECT ID FROM b_catalog_price WHERE PRODUCT_ID IN (' . $membershipElementIdList
            . ') ORDER BY PRODUCT_ID, ID FOR UPDATE'
        );
        $this->selectForUpdate(
            'SELECT ID FROM b_iblock_element_property WHERE IBLOCK_ELEMENT_ID IN ('
            . $membershipElementIdList . ') ORDER BY IBLOCK_ELEMENT_ID, ID FOR UPDATE'
        );
        if ($membershipMeasureIds !== []) {
            $this->selectForUpdate(
                'SELECT ID FROM b_catalog_measure WHERE ID IN ('
                . implode(',', array_map('intval', array_keys($membershipMeasureIds)))
                . ') ORDER BY ID FOR UPDATE'
            );
        }

        $expectedById = [];
        foreach ($runtimeLocks['elements'] as $element) {
            $expectedById[(int)$element['id']] = (int)$element['iblockId'];
        }
        $elementIds = array_map('intval', array_keys($expectedById));
        $elementIdList = implode(',', $elementIds);
        $rows = $this->selectForUpdate(
            'SELECT ID, IBLOCK_ID FROM b_iblock_element WHERE ID IN (' . $elementIdList . ') ORDER BY ID FOR UPDATE'
        );
        $actualById = [];
        foreach ($rows as $row) {
            $id = (int)($row['ID'] ?? $row['id'] ?? 0);
            $actualById[$id] = (int)($row['IBLOCK_ID'] ?? $row['iblock_id'] ?? 0);
        }
        ksort($actualById, SORT_NUMERIC);
        if ($actualById !== $expectedById) {
            throw new \RuntimeException('Набор runtime-элементов изменился до блокировки записи.', 409);
        }

        $this->selectForUpdate(
            'SELECT ID FROM b_catalog_product WHERE ID IN (' . $elementIdList . ') ORDER BY ID FOR UPDATE'
        );
        $ratioProductIds = array_map('intval', $runtimeLocks['measureRatioProductIds']);
        $this->selectForUpdate(
            'SELECT ID FROM b_catalog_measure_ratio WHERE PRODUCT_ID IN ('
            . implode(',', $ratioProductIds) . ') ORDER BY PRODUCT_ID, ID FOR UPDATE'
        );
        $measureIds = array_map('intval', $runtimeLocks['measureIds']);
        if ($measureIds !== []) {
            $measureRows = $this->selectForUpdate(
                'SELECT ID FROM b_catalog_measure WHERE ID IN (' . implode(',', $measureIds)
                . ') ORDER BY ID FOR UPDATE'
            );
            $actualMeasureIds = [];
            foreach ($measureRows as $measureRow) {
                $measureId = (int)($measureRow['ID'] ?? $measureRow['id'] ?? 0);
                if ($measureId > 0) {
                    $actualMeasureIds[] = $measureId;
                }
            }
            sort($actualMeasureIds, SORT_NUMERIC);
            if ($actualMeasureIds !== $measureIds) {
                throw new \RuntimeException('The runtime measure set changed before catalog write.', 409);
            }
        }
        $this->selectForUpdate(
            'SELECT ID FROM b_catalog_price WHERE PRODUCT_ID IN (' . $elementIdList
            . ') ORDER BY PRODUCT_ID, ID FOR UPDATE'
        );
        $this->selectForUpdate(
            'SELECT ID FROM b_iblock_element_property WHERE IBLOCK_ELEMENT_ID IN (' . $elementIdList
            . ') ORDER BY IBLOCK_ELEMENT_ID, ID FOR UPDATE'
        );
        $iblockIds = $sourceIblockIds;
        foreach ($iblockIds as $iblockId) {
            $iblockRows = $this->selectForUpdate(
                'SELECT ID, VERSION FROM b_iblock WHERE ID=' . $iblockId . ' ORDER BY ID FOR UPDATE'
            );
            $sourcePropertyRows = $this->selectForUpdate(
                'SELECT ID FROM b_iblock_property WHERE IBLOCK_ID=' . $iblockId
                . ' ORDER BY ID FOR UPDATE'
            );
            $sourcePropertyIds = [];
            foreach ($sourcePropertyRows as $sourcePropertyRow) {
                $sourcePropertyId = (int)($sourcePropertyRow['ID'] ?? $sourcePropertyRow['id'] ?? 0);
                if ($sourcePropertyId > 0) {
                    $sourcePropertyIds[] = $sourcePropertyId;
                }
            }
            if ($sourcePropertyIds !== []) {
                $this->selectForUpdate(
                    'SELECT ID FROM b_iblock_property_enum WHERE PROPERTY_ID IN ('
                    . implode(',', $sourcePropertyIds) . ') ORDER BY PROPERTY_ID, ID FOR UPDATE'
                );
            }
            $version = (int)($iblockRows[0]['VERSION'] ?? $iblockRows[0]['version'] ?? 1);
            if ($version === 2) {
                $this->selectForUpdate(
                    'SELECT IBLOCK_ELEMENT_ID FROM b_iblock_element_prop_s' . $iblockId
                    . ' WHERE IBLOCK_ELEMENT_ID IN (' . $membershipElementIdList
                    . ') ORDER BY IBLOCK_ELEMENT_ID FOR UPDATE'
                );
                $this->selectForUpdate(
                    'SELECT ID FROM b_iblock_element_prop_m' . $iblockId
                    . ' WHERE IBLOCK_ELEMENT_ID IN (' . $membershipElementIdList
                    . ') ORDER BY IBLOCK_ELEMENT_ID, ID FOR UPDATE'
                );
            }
        }

        $propertyIds = array_map('intval', $runtimeLocks['propertyIds']);
        if ($propertyIds !== []) {
            $propertyRows = $this->selectForUpdate(
                'SELECT ID FROM b_iblock_property WHERE ID IN (' . implode(',', $propertyIds)
                . ') ORDER BY ID FOR UPDATE'
            );
            $actualPropertyIds = [];
            foreach ($propertyRows as $propertyRow) {
                $propertyId = (int)($propertyRow['ID'] ?? $propertyRow['id'] ?? 0);
                if ($propertyId > 0) {
                    $actualPropertyIds[] = $propertyId;
                }
            }
            sort($actualPropertyIds, SORT_NUMERIC);
            if ($actualPropertyIds !== $propertyIds) {
                throw new \RuntimeException('The runtime property set changed before catalog write.', 409);
            }
            $this->selectForUpdate(
                'SELECT ID FROM b_iblock_property_enum WHERE PROPERTY_ID IN (' . implode(',', $propertyIds)
                . ') ORDER BY PROPERTY_ID, ID FOR UPDATE'
            );
        }

        $globalSymbolIblockIds = array_map('intval', $runtimeLocks['globalSymbolIblockIds']);
        if ($globalSymbolIblockIds !== []) {
            $globalIblockList = implode(',', $globalSymbolIblockIds);
            $globalIblockRows = $this->selectForUpdate(
                'SELECT ID, CODE, IBLOCK_TYPE_ID, ACTIVE FROM b_iblock WHERE ID IN ('
                . $globalIblockList . ') ORDER BY ID FOR UPDATE'
            );
            $actualGlobalIblockIds = [];
            foreach ($globalIblockRows as $globalIblockRow) {
                $id = (int)($globalIblockRow['ID'] ?? $globalIblockRow['id'] ?? 0);
                if ($id > 0) {
                    $actualGlobalIblockIds[] = $id;
                }
                if ((string)($globalIblockRow['CODE'] ?? $globalIblockRow['code'] ?? '')
                        !== 'CALC_GLOBAL_VALUES'
                    || (string)($globalIblockRow['IBLOCK_TYPE_ID']
                        ?? $globalIblockRow['iblock_type_id'] ?? '') !== 'calculator'
                    || (string)($globalIblockRow['ACTIVE'] ?? $globalIblockRow['active'] ?? '') !== 'Y') {
                    throw new \RuntimeException(
                        'The global-symbol storage identity changed after calc-server calculation.',
                        409
                    );
                }
            }
            sort($actualGlobalIblockIds, SORT_NUMERIC);
            if ($actualGlobalIblockIds !== $globalSymbolIblockIds) {
                throw new \RuntimeException('The global-symbol storage changed before catalog write.', 409);
            }
            $globalPropertyRows = $this->selectForUpdate(
                'SELECT ID, IBLOCK_ID, CODE FROM b_iblock_property WHERE IBLOCK_ID IN ('
                . $globalIblockList . ') ORDER BY IBLOCK_ID, ID FOR UPDATE'
            );
            $actualGlobalProperties = [];
            $allGlobalPropertyIds = [];
            foreach ($globalPropertyRows as $propertyRow) {
                $iblockId = (int)($propertyRow['IBLOCK_ID'] ?? $propertyRow['iblock_id'] ?? 0);
                $propertyId = (int)($propertyRow['ID'] ?? $propertyRow['id'] ?? 0);
                $code = (string)($propertyRow['CODE'] ?? $propertyRow['code'] ?? '');
                if ($propertyId > 0) {
                    $allGlobalPropertyIds[] = $propertyId;
                }
                if (in_array($code, ['DATA_TYPE', 'INITIAL_VALUE', 'KIND', 'PRESET_ID'], true)) {
                    if (isset($actualGlobalProperties[$iblockId][$code])) {
                        throw new \RuntimeException('Global-symbol property authority became ambiguous.', 409);
                    }
                    $actualGlobalProperties[$iblockId][$code] = $propertyId;
                }
            }
            foreach ($actualGlobalProperties as &$properties) {
                ksort($properties, SORT_STRING);
            }
            unset($properties);
            ksort($actualGlobalProperties, SORT_NUMERIC);
            $expectedGlobalProperties = [];
            foreach ($runtimeLocks['globalSymbolProperties'] as $authority) {
                $expectedGlobalProperties[(int)$authority['iblockId']] = $authority['properties'];
            }
            ksort($expectedGlobalProperties, SORT_NUMERIC);
            if ($actualGlobalProperties !== $expectedGlobalProperties) {
                throw new \RuntimeException('Global-symbol property metadata changed after calc-server calculation.', 409);
            }
            if ($allGlobalPropertyIds !== []) {
                sort($allGlobalPropertyIds, SORT_NUMERIC);
                $this->selectForUpdate(
                    'SELECT ID FROM b_iblock_property_enum WHERE PROPERTY_ID IN ('
                    . implode(',', $allGlobalPropertyIds) . ') ORDER BY PROPERTY_ID, ID FOR UPDATE'
                );
            }
            $this->selectForUpdate(
                'SELECT ID FROM b_iblock_element WHERE IBLOCK_ID IN (' . $globalIblockList
                . ') ORDER BY IBLOCK_ID, ID FOR UPDATE'
            );
        }

        $priceTypeIds = array_map('intval', $runtimeLocks['priceTypeIds']);
        $priceTypeRows = $this->selectForUpdate(
            'SELECT ID FROM b_catalog_group ORDER BY ID FOR UPDATE'
        );
        $actualPriceTypeIds = [];
        foreach ($priceTypeRows as $row) {
            $id = (int)($row['ID'] ?? $row['id'] ?? 0);
            if ($id > 0) {
                $actualPriceTypeIds[] = $id;
            }
        }
        sort($actualPriceTypeIds, SORT_NUMERIC);
        if ($actualPriceTypeIds !== $priceTypeIds) {
            throw new \RuntimeException('Набор типов цен runtime изменился до блокировки записи.', 409);
        }
        $this->selectForUpdate(
            'SELECT CATALOG_GROUP_ID, LANG FROM b_catalog_group_lang '
            . 'ORDER BY CATALOG_GROUP_ID, LANG FOR UPDATE'
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function selectForUpdate(string $sql): array
    {
        if ($this->transactionConnection === null) {
            throw new \RuntimeException('Запрос блокировки выполнен вне транзакции.');
        }
        $result = $this->transactionConnection->query($sql);
        $rows = [];
        while (is_object($result) && method_exists($result, 'fetch') && ($row = $result->fetch())) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function assertPreset(int $presetId): void
    {
        if ($presetId !== CatalogAdapterDefinitionService::PRESET_ID) {
            throw new \InvalidArgumentException('Запись результатов доступна только для пресета 12740.');
        }
    }

    /** @param mixed[] $offerIds @return int[] */
    private function normalizeOfferIds(array $offerIds): array
    {
        if ($offerIds === [] || count($offerIds) > self::MAX_OFFERS) {
            throw new \InvalidArgumentException('Выберите от 1 до ' . self::MAX_OFFERS . ' торговых предложений.');
        }
        $normalized = [];
        foreach ($offerIds as $offerId) {
            if ((!is_int($offerId) && !(is_string($offerId) && preg_match('/^[1-9][0-9]*$/D', $offerId)))
                || (int)$offerId <= 0) {
                throw new \InvalidArgumentException('Передан некорректный ID торгового предложения.');
            }
            $normalized[] = (int)$offerId;
        }
        if (count($normalized) !== count(array_unique($normalized))) {
            throw new \InvalidArgumentException('Список торговых предложений содержит повторные ID.');
        }
        sort($normalized, SORT_NUMERIC);
        return $normalized;
    }

    /** @param mixed[] $offerIds @return int[] */
    private function normalizeOptionalOfferIds(array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }
        return $this->normalizeOfferIds($offerIds);
    }

    private function normalizeSiteId(string $siteId): string
    {
        $siteId = trim($siteId);
        if (preg_match('/^[A-Za-z0-9_-]{1,10}$/D', $siteId) !== 1) {
            throw new \InvalidArgumentException('Передан некорректный siteId.');
        }
        return $siteId;
    }

    /** @param mixed $value */
    private function nullableNumber($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('Числовое значение каталога имеет некорректный формат.');
        }
        $number = (float)$value;
        if (!is_finite($number)) {
            throw new \InvalidArgumentException('Числовое значение каталога должно быть конечным.');
        }
        return $number;
    }

    /** @param mixed $value */
    private function nullableQuantity($value): ?int
    {
        if ($value === null || $value === '' || $value === false || (string)$value === '0') {
            return null;
        }
        if ((!is_int($value)
                && !(is_float($value) && is_finite($value) && floor($value) === $value)
                && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)))
            || (int)$value <= 0) {
            throw new \InvalidArgumentException('Граница диапазона цены имеет некорректный формат.');
        }
        return (int)$value;
    }

    /** @param mixed $value */
    private function positiveNumber($value, string $label): float
    {
        $number = $this->nullableNumber($value);
        if ($number === null || $number <= 0) {
            throw new \InvalidArgumentException($label . ' должна быть положительным конечным числом.');
        }
        return $number;
    }

    /** @param mixed $value */
    private function nullableCurrency($value): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $currency = strtoupper(trim((string)$value));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new \InvalidArgumentException('Валюта каталога имеет некорректный формат.');
        }
        return $currency;
    }

    /** @param array<string,mixed> $price */
    private function priceKey(array $price): string
    {
        return (int)$price['typeId']
            . ':' . ($price['quantityFrom'] === null ? 'n' : (int)$price['quantityFrom'])
            . ':' . ($price['quantityTo'] === null ? 'n' : (int)$price['quantityTo']);
    }

    /** @param array<int,array<string,mixed>> $prices */
    private function sortPrices(array &$prices): void
    {
        usort($prices, function (array $left, array $right): int {
            return strcmp($this->priceKey($left), $this->priceKey($right));
        });
    }

    private function isList(array $value): bool
    {
        $keys = array_keys($value);
        return $keys === [] || $keys === range(0, count($keys) - 1);
    }

    /** @param mixed $value */
    public static function canonicalEncode($value): string
    {
        $encoded = json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($encoded)) {
            throw new \RuntimeException('Не удалось сериализовать отпечаток записи каталога.');
        }
        return $encoded;
    }

    /** @param mixed $value @return mixed */
    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $keys = array_keys($value);
        $isList = $keys === [] || $keys === range(0, count($keys) - 1);
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
