<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Services\PresetProductAssignmentPropertyAuthorityService;

/**
 * Сервис подготовки INIT payload для React-калькулятора
 */
class InitPayloadService
{
    /** @var string ID модуля */
    private const MODULE_ID = 'prospektweb.calc';

    /** @var array Кэш элементов для preset */
    private array $elementsStore = [];

    /**
     * Direct b_option authority used by catalog preview/apply. When present,
     * no ConfigManager/Option cache is allowed to choose a runtime source.
     *
     * @var array<string,int>|null
     */
    private ?array $pinnedRuntimeIblockIds = null;

    private const PINNED_RUNTIME_IBLOCK_CODES = [
        'PRODUCTS',
        'OFFERS',
        'CALC_PRESETS',
        'CALC_STAGES',
        'CALC_SETTINGS',
        'CALC_GLOBAL_VALUES',
        'CALC_CUSTOM_FIELDS',
        'CALC_MATERIALS',
        'CALC_MATERIALS_VARIANTS',
        'CALC_OPERATIONS',
        'CALC_OPERATIONS_VARIANTS',
        'CALC_EQUIPMENT',
        'CALC_DETAILS',
    ];

    /**
     * Подготовить INIT payload для отправки в iframe
     *
     * @param array $offerIds ID торговых предложений
     * @param string $siteId ID сайта
     * @param bool $forceCreatePreset Принудительное создание нового preset (после подтверждения пользователя)
     * @return array
     * @throws \Exception
     */
    public function prepareInitPayload(array $offerIds, string $siteId, bool $forceCreatePreset = false): array
    {
        if (empty($offerIds)) {
            throw new \Exception('Список торговых предложений не может быть пустым');
        }

        if ($forceCreatePreset) {
            throw new \RuntimeException(
                'Preset creation from offers is disabled; create and assign a preset explicitly.',
                409
            );
        }

        // An offer-backed launch derives one authoritative preset from the
        // parent product assignments and never creates or repairs one.
        return $this->prepareNeutralInitPayloadReadOnly(0, $offerIds, $siteId, false);
    }
    /**
     * Prepare the calculation-authoring payload directly from a preset.
     *
     * Product and SKU data are deliberately absent: they are catalog concerns
     * and must not be required to open or edit calculation logic.
     */
    public function preparePresetPayload(int $presetId, string $siteId): array
    {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Некорректный ID пресета');
        }

        return $this->prepareNeutralInitPayloadReadOnly($presetId, [], $siteId, false);
    }

    /**
     * Build a preset-owned editor INIT without any repair, migration,
     * enrichment, preset/detail creation or option mutation.
     *
     * A zero requested preset ID is accepted only for an offer-backed launch;
     * the authoritative preset is then derived from the products. An explicit
     * preset ID with offers is revalidated against those same assignments.
     *
     * @param int[] $offerIds
     * @return array<string,mixed>
     */
    public function prepareNeutralInitPayloadReadOnly(
        int $requestedPresetId,
        array $offerIds,
        string $siteId,
        bool $requireActiveCatalogTargets
    ): array {
        if ($requestedPresetId < 0) {
            throw new \InvalidArgumentException('Некорректный ID пресета.', 409);
        }

        $offerIds = $this->normalizeNeutralOfferIds($offerIds);
        if ($offerIds === [] && $requestedPresetId <= 0) {
            throw new \InvalidArgumentException('Для запуска требуется пресет или список торговых предложений.');
        }

        $this->ensureBitrixModulesLoaded();
        $runtimeConfigSnapshot = $this->captureRuntimeConfigSnapshotDirect();
        $this->pinnedRuntimeIblockIds = $this->buildPinnedRuntimeIblockMap($runtimeConfigSnapshot);

        $selectedOffers = [];
        $productIds = [];
        if ($offerIds !== []) {
            $selectedOffers = $this->loadOffers(
                $offerIds,
                $this->runtimeIblockId('OFFERS'),
                $requireActiveCatalogTargets
            );
            $targets = $this->normalizeNeutralCatalogTargets(
                $offerIds,
                $selectedOffers,
                $requestedPresetId === 0
            );
            $selectedOffers = $targets['offers'];
            $offerIds = $targets['offerIds'];
            $productIds = $targets['productIds'];
        }

        $presetId = $requestedPresetId > 0
            ? $requestedPresetId
            : $this->resolveOnePresetForProducts($productIds);
        $this->assertNeutralPresetAvailableReadOnly($presetId);
        if ($productIds !== []) {
            $this->assertNeutralParentProductsReadOnly($presetId, $productIds, $requireActiveCatalogTargets);
        }

        $published = $this->readNeutralPublishedRuntimeDirect($presetId);

        $globalSymbolIblockId = $this->resolvePinnedGlobalSymbolIblockId($runtimeConfigSnapshot);
        $globalSymbols = (new \Prospektweb\Calc\Services\GlobalSymbolService())
            ->listReadOnlyFromIblockId($globalSymbolIblockId, $presetId);

        $this->elementsStore = [];
        $preset = $this->loadPreset($presetId);
        if (!is_array($preset) || (int)($preset['id'] ?? 0) !== $presetId) {
            throw new \RuntimeException('Пресет отсутствует в настроенном инфоблоке пресетов.', 409);
        }

        $editorRuntime = $this->buildEditorRuntime(
            $presetId,
            $selectedOffers,
            null,
            $offerIds === [] ? 'manual' : 'catalog',
            $published['authoring'],
            $published['snapshot']
        );
        $launchContext = is_array($editorRuntime['launchContext'] ?? null)
            ? $editorRuntime['launchContext']
            : [];
        if (($launchContext['productIds'] ?? null) !== $productIds
            || ($launchContext['offerIds'] ?? null) !== $offerIds) {
            throw new \RuntimeException('INIT сформировал неканонический контекст запуска каталога.', 409);
        }

        $payload = [
            'context' => $this->buildContext($siteId),
            'iblocks' => $this->getIblocks(),
            'iblocksTree' => $this->buildIblocksTree(),
            'selectedOffers' => $selectedOffers,
            'priceTypes' => $this->getPriceTypes(),
            'preset' => $preset,
            'product' => null,
            'elementsStore' => $this->elementsStore,
            'elementsSiblings' => $this->buildElementsSiblings($preset),
            'globalSymbols' => array_values($globalSymbols),
            'editorRuntime' => $editorRuntime,
            'neutralInputRequired' => true,
        ];
        $payload['semanticRevision'] = \Prospektweb\Calc\Services\CalculatorSemanticMutationService::revisionFromInitPayload(
            $payload
        );
        $globalAuthority = (new \Prospektweb\Calc\Services\CalculatorGlobalMutationService())
            ->currentAuthority();
        $payload['globalMutationRevision'] = $globalAuthority['revision'];
        $payload['globalMutationFingerprint'] = $globalAuthority['fingerprint'];
        return $payload;
    }

    /**
     * Build the preset-owned calc-server payload from an explicit direct
     * b_option snapshot. This path is intentionally read-only and never asks
     * ConfigManager (or Bitrix Option) which source iblocks should be used.
     *
     * @param array<int,array<string,mixed>> $virtualSelectedOffers
     * @param array<int,array<string,mixed>> $globalSymbols
     * @param array<string,string|null> $runtimeConfigSnapshot
     * @return array<string,mixed>
     */
    public function preparePresetCalculationPayloadReadOnlyPinned(
        int $presetId,
        array $virtualSelectedOffers,
        string $siteId,
        array $globalSymbols,
        array $runtimeConfigSnapshot
    ): array {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Pinned preset ID must be positive.');
        }

        $this->ensureBitrixModulesLoaded();
        $this->pinnedRuntimeIblockIds = $this->buildPinnedRuntimeIblockMap($runtimeConfigSnapshot);
        $this->elementsStore = [];

        $preset = $this->loadPreset($presetId);
        if (!is_array($preset) || (int)($preset['id'] ?? 0) !== $presetId) {
            throw new \RuntimeException('Preset is absent from the pinned preset iblock.', 409);
        }

        return [
            'context' => $this->buildContext($siteId),
            'iblocks' => [],
            'iblocksTree' => [],
            'selectedOffers' => array_values($virtualSelectedOffers),
            'priceTypes' => $this->getPriceTypes(),
            'preset' => $preset,
            'product' => null,
            'elementsStore' => $this->elementsStore,
            'elementsSiblings' => $this->buildElementsSiblings($preset),
            'globalSymbols' => array_values($globalSymbols),
        ];
    }

    /**
     * Resolve the catalog-write envelope without creating presets, repairing
     * schema or migrating catalog data. Preview/apply must be read-only until
     * the explicitly confirmed writer transaction starts.
     *
     * @param int[] $offerIds
     * @return array<string,mixed>
     */
    public function prepareCatalogWritePayload(
        array $offerIds,
        string $siteId,
        ?array $pinnedAuthoring = null,
        ?array $pinnedInputMapping = null,
        ?array $pinnedPublishedSnapshot = null,
        ?array $pinnedGlobalSymbols = null,
        ?int $pinnedGlobalSymbolIblockId = null,
        ?array $pinnedRuntimeConfigSnapshot = null,
        ?array $pinnedOutputMapping = null
    ): array
    {
        $offerIds = array_values(array_map('intval', $offerIds));
        if ($offerIds === [] || count($offerIds) !== count(array_unique($offerIds))) {
            throw new \InvalidArgumentException('Для записи требуется непустой список уникальных ID торговых предложений.');
        }
        foreach ($offerIds as $offerId) {
            if ($offerId <= 0) {
                throw new \InvalidArgumentException('Передан некорректный ID торгового предложения.');
            }
        }

        $this->ensureBitrixModulesLoaded();
        if ($pinnedRuntimeConfigSnapshot !== null) {
            $this->pinnedRuntimeIblockIds = $this->buildPinnedRuntimeIblockMap(
                $pinnedRuntimeConfigSnapshot
            );
        }
        $hasAnyRuntimePin = $pinnedAuthoring !== null
            || $pinnedPublishedSnapshot !== null
            || $pinnedGlobalSymbols !== null
            || $pinnedGlobalSymbolIblockId !== null
            || $pinnedInputMapping !== null
            || $pinnedOutputMapping !== null;
        $hasAllRuntimePins = $pinnedAuthoring !== null
            && $pinnedPublishedSnapshot !== null
            && $pinnedGlobalSymbols !== null
            && $pinnedGlobalSymbolIblockId !== null
            && $pinnedInputMapping !== null
            && $pinnedOutputMapping !== null;
        if ($hasAnyRuntimePin && !$hasAllRuntimePins) {
            throw new \InvalidArgumentException('Pinned catalog runtime must provide one complete snapshot.');
        }
        if ($hasAllRuntimePins && $pinnedRuntimeConfigSnapshot === null) {
            throw new \InvalidArgumentException(
                'Pinned catalog runtime must include the direct ConfigManager option snapshot.'
            );
        }

        $selectedOffers = $this->loadOffers($offerIds);
        $resolvedOfferIds = [];
        foreach ($selectedOffers as $offer) {
            $offerId = is_array($offer) ? (int)($offer['id'] ?? 0) : 0;
            if ($offerId > 0) {
                $resolvedOfferIds[] = $offerId;
            }
        }
        sort($offerIds, SORT_NUMERIC);
        sort($resolvedOfferIds, SORT_NUMERIC);
        if ($resolvedOfferIds !== $offerIds) {
            throw new \RuntimeException('Не все выбранные торговые предложения существуют в текущем каталоге.');
        }

        $productIds = [];
        $presetIds = [];
        foreach ($selectedOffers as $offer) {
            $offerId = (int)($offer['id'] ?? 0);
            $productId = $this->getProductIdFromOffer($offer);
            $presetId = $productId > 0 ? $this->getPresetFromProduct($productId) : null;
            if ($productId <= 0 || $presetId === null || $presetId <= 0) {
                throw new \RuntimeException(
                    'Торговое предложение #' . $offerId . ' не связано с пресетом через свой товар.'
                );
            }
            $productIds[$productId] = true;
            $presetIds[$presetId] = true;
        }
        if (count($presetIds) !== 1) {
            throw new \RuntimeException('Выбранные торговые предложения относятся к разным пресетам.', 409);
        }
        $presetId = (int)array_key_first($presetIds);

        if (!$hasAllRuntimePins) {
            $published = $this->readNeutralPublishedRuntimeDirect($presetId);
            $pinnedAuthoring = $published['authoring'];
            $pinnedPublishedSnapshot = $published['snapshot'];
            $pinnedInputMapping = (new \Prospektweb\Calc\Services\CalculatorInputMappingService())->load($presetId);
            $pinnedOutputMapping = (new \Prospektweb\Calc\Services\CatalogOutputMappingService())->load($presetId);
            $pinnedGlobalSymbolIblockId = $this->resolvePinnedGlobalSymbolIblockId($pinnedRuntimeConfigSnapshot);
            $pinnedGlobalSymbols = (new \Prospektweb\Calc\Services\GlobalSymbolService())
                ->listReadOnlyFromIblockId($pinnedGlobalSymbolIblockId, $presetId);
        }

        $pinnedPublication = is_array($pinnedAuthoring['publication'] ?? null)
            ? $pinnedAuthoring['publication']
            : [];
        $snapshotMeta = is_array($pinnedPublishedSnapshot['_form_first'] ?? null)
            ? $pinnedPublishedSnapshot['_form_first']
            : [];
        if ((int)($pinnedPublication['revision'] ?? 0) <= 0
            || (int)($snapshotMeta['publishedRevision'] ?? 0) !== (int)$pinnedPublication['revision']
            || !hash_equals(
                (string)($pinnedPublication['compileHash'] ?? ''),
                (string)($snapshotMeta['compileHash'] ?? '')
            )) {
            throw new \RuntimeException('Published authoring and runtime snapshot revisions do not match.');
        }
        foreach ($pinnedGlobalSymbols as $symbol) {
            if (!is_array($symbol)
                || (int)($symbol['id'] ?? 0) <= 0
                || (int)($symbol['iblockId'] ?? 0) !== (int)$pinnedGlobalSymbolIblockId) {
                throw new \RuntimeException('The global symbol registry snapshot is not lock-addressable.');
            }
        }

        $productIblockIds = [];
        if ($productIds !== []) {
            ksort($productIds, SORT_NUMERIC);
            $iterator = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                ['ID' => array_map('intval', array_keys($productIds))],
                false,
                false,
                ['ID', 'IBLOCK_ID']
            );
            while ($row = $iterator->Fetch()) {
                $productIblockIds[(int)$row['ID']] = (int)$row['IBLOCK_ID'];
            }
            ksort($productIblockIds, SORT_NUMERIC);
            if (array_map('intval', array_keys($productIblockIds))
                !== array_map('intval', array_keys($productIds))) {
                throw new \RuntimeException('Not every parent product is lock-addressable.');
            }
        }

        $this->elementsStore = [];

        return [
            'presetId' => $presetId,
            'selectedOffers' => $selectedOffers,
            'priceTypes' => $this->getPriceTypes(),
            'editorRuntime' => $this->buildEditorRuntime(
                $presetId,
                $selectedOffers,
                null,
                'catalog',
                $pinnedAuthoring,
                $pinnedPublishedSnapshot,
                $pinnedInputMapping,
                $pinnedOutputMapping
            ),
            // Private read-only pins consumed only while building the neutral
            // calc-server request. They never become formula context.
            '_publishedSnapshot' => $pinnedPublishedSnapshot,
            '_neutralInputRequired' => true,
            '_inputMapping' => $pinnedInputMapping,
            '_outputMapping' => $pinnedOutputMapping,
            '_globalSymbols' => array_values($pinnedGlobalSymbols),
            '_globalSymbolIblockId' => (int)$pinnedGlobalSymbolIblockId,
            '_productIblockIds' => $productIblockIds,
            '_runtimeConfigSnapshot' => is_array($pinnedRuntimeConfigSnapshot)
                ? $pinnedRuntimeConfigSnapshot
                : [],
        ];
    }

    /**
     * Read-only catalog resolver pinned to raw option values already read
     * under database row locks by the writer.
     *
     * @param int[] $offerIds
     * @param array<string,mixed> $publishedAuthoring
     * @param array<string,mixed> $calculatorInputMapping
     * @param array<string,mixed> $catalogOutputMapping
     * @return array<string,mixed>
     */
    public function prepareCatalogWritePayloadPinned(
        array $offerIds,
        string $siteId,
        array $publishedAuthoring,
        array $calculatorInputMapping,
        array $publishedSnapshot,
        array $globalSymbols,
        int $globalSymbolIblockId,
        array $runtimeConfigSnapshot,
        ?array $catalogOutputMapping = null
    ): array {
        return $this->prepareCatalogWritePayload(
            $offerIds,
            $siteId,
            $publishedAuthoring,
            $calculatorInputMapping,
            $publishedSnapshot,
            $globalSymbols,
            $globalSymbolIblockId,
            $runtimeConfigSnapshot,
            $catalogOutputMapping
        );
    }

    /** @param mixed[] $offerIds @return int[] */
    private function normalizeNeutralOfferIds(array $offerIds): array
    {
        if (count($offerIds) > 500) {
            throw new \InvalidArgumentException('За один запуск можно выбрать не более 500 торговых предложений.');
        }
        $normalized = [];
        foreach ($offerIds as $offerId) {
            if ((is_int($offerId) || (is_string($offerId) && preg_match('/^[1-9][0-9]*$/D', $offerId) === 1))
                && (int)$offerId > 0) {
                $normalized[] = (int)$offerId;
                continue;
            }
            throw new \InvalidArgumentException('Передан некорректный ID торгового предложения.');
        }
        if (count($normalized) !== count(array_unique($normalized))) {
            throw new \InvalidArgumentException('ID торговых предложений не должны повторяться.');
        }
        sort($normalized, SORT_NUMERIC);
        return $normalized;
    }

    /**
     * Canonicalize and verify the complete requested/resolved target set.
     * Kept data-only so the 5x6 matrix and fail-closed cases are regression
     * tested without a mutable Bitrix fixture.
     *
     * @param int[] $requestedOfferIds
     * @param array<int,array<string,mixed>> $selectedOffers
     * @return array{offerIds:int[],productIds:int[],offers:array<int,array<string,mixed>>}
     */
    private function normalizeNeutralCatalogTargets(
        array $requestedOfferIds,
        array $selectedOffers,
        bool $legacySingleProduct
    ): array {
        $requestedOfferIds = $this->normalizeNeutralOfferIds($requestedOfferIds);

        $offersById = [];
        $productIds = [];
        foreach ($selectedOffers as $offer) {
            if (!is_array($offer)) {
                throw new \RuntimeException('Сервер вернул некорректное торговое предложение.', 409);
            }
            $offerId = (int)($offer['id'] ?? 0);
            $productId = (int)($offer['productId'] ?? 0);
            if ($offerId <= 0 || $productId <= 0 || isset($offersById[$offerId])) {
                throw new \RuntimeException('Набор торговых предложений не имеет однозначной каталоговой связи.', 409);
            }
            $offersById[$offerId] = $offer;
            $productIds[$productId] = true;
        }

        $resolvedOfferIds = array_map('intval', array_keys($offersById));
        sort($resolvedOfferIds, SORT_NUMERIC);
        if ($resolvedOfferIds !== $requestedOfferIds) {
            throw new \RuntimeException(
                'Не все выбранные торговые предложения доступны в текущем каталоге.',
                409
            );
        }

        $productIds = array_map('intval', array_keys($productIds));
        sort($productIds, SORT_NUMERIC);
        if ($legacySingleProduct && count($productIds) !== 1) {
            throw new \RuntimeException(
                'Запуск из формы товара должен содержать торговые предложения ровно одного товара.',
                409
            );
        }

        $canonicalOffers = [];
        foreach ($requestedOfferIds as $offerId) {
            $canonicalOffers[] = $offersById[$offerId];
        }
        return [
            'offerIds' => $requestedOfferIds,
            'productIds' => $productIds,
            'offers' => $canonicalOffers,
        ];
    }

    /**
     * Read every ConfigManager source selector directly from global b_option.
     * Bitrix option names are case-insensitive in production, while duplicate
     * mixed-case rows remain ambiguous and are rejected.
     *
     * @return array<string,string|null>
     */
    private function captureRuntimeConfigSnapshotDirect(): array
    {
        $namesByModule = [
            'prospektweb.calc' => ['CALC_SERVER_URL', 'PRODUCT_IBLOCK_ID', 'SKU_IBLOCK_ID'],
            'prospektweb.frontcalc' => ['PRODUCTS_IBLOCK_ID', 'OFFERS_IBLOCK_ID'],
        ];
        foreach (self::PINNED_RUNTIME_IBLOCK_CODES as $code) {
            if (in_array($code, ['PRODUCTS', 'OFFERS'], true)) {
                continue;
            }
            $namesByModule['prospektweb.calc'][] = 'IBLOCK_' . $code;
            $namesByModule['prospektweb.frontcalc'][] = 'IBLOCK_' . $code;
        }

        $snapshot = [];
        $canonicalByLookup = [];
        $conditions = [];
        foreach ($namesByModule as $moduleId => $names) {
            foreach ($names as $name) {
                $key = $moduleId . ':' . $name;
                $snapshot[$key] = null;
                $canonicalByLookup[strtolower($moduleId) . ':' . strtoupper($name)] = $key;
                $conditions[] = "(MODULE_ID='" . $moduleId . "' AND NAME='" . $name . "')";
            }
        }

        $result = Application::getConnection()->query(
            'SELECT MODULE_ID, NAME, VALUE FROM b_option WHERE (' . implode(' OR ', $conditions)
            . ") AND (SITE_ID IS NULL OR SITE_ID='') ORDER BY MODULE_ID, NAME"
        );
        while (is_object($result) && method_exists($result, 'fetch') && ($row = $result->fetch())) {
            $actualModuleId = (string)($row['MODULE_ID'] ?? $row['module_id'] ?? '');
            $actualName = (string)($row['NAME'] ?? $row['name'] ?? '');
            $lookup = strtolower($actualModuleId) . ':' . strtoupper($actualName);
            $canonicalKey = $canonicalByLookup[$lookup] ?? null;
            if (!is_string($canonicalKey) || !array_key_exists($canonicalKey, $snapshot)) {
                throw new \RuntimeException('Unexpected neutral INIT runtime option row.', 409);
            }
            if ($snapshot[$canonicalKey] !== null) {
                throw new \RuntimeException('Duplicate global neutral INIT runtime option row.', 409);
            }
            $snapshot[$canonicalKey] = (string)($row['VALUE'] ?? $row['value'] ?? '');
        }
        return $snapshot;
    }

    /** @return array{authoring:array<string,mixed>,snapshot:array<string,mixed>} */
    private function readNeutralPublishedRuntimeDirect(int $presetId): array
    {
        if (!Loader::includeModule('prospektweb.frontcalc')) {
            throw new \RuntimeException('Для editorRuntime требуется модуль prospektweb.frontcalc.');
        }
        $storeClass = '\\Prospektweb\\Frontcalc\\Service\\FormFirstAuthoringStore';
        if (!class_exists($storeClass)
            || !method_exists($storeClass, 'publishedAuthoringFromRaw')
            || !method_exists($storeClass, 'publishedSnapshotFromRaw')) {
            throw new \RuntimeException('FrontCalc не предоставляет read-only публикацию выбранного пресета.', 409);
        }
        $raw = $this->readOptionValueDirect('prospektweb.frontcalc', 'FORM_FIRST_PRESET_' . $presetId);
        $authoring = $storeClass::publishedAuthoringFromRaw($presetId, $raw);
        $snapshot = $storeClass::publishedSnapshotFromRaw($presetId, $raw);
        if (!is_array($authoring) || !is_array($snapshot)) {
            throw new \RuntimeException('Опубликованный form-first пресет отсутствует или повреждён.', 409);
        }
        $publication = is_array($authoring['publication'] ?? null) ? $authoring['publication'] : [];
        $snapshotMeta = is_array($snapshot['_form_first'] ?? null) ? $snapshot['_form_first'] : [];
        if ((int)($publication['revision'] ?? 0) <= 0
            || (int)($snapshotMeta['publishedRevision'] ?? 0) !== (int)$publication['revision']
            || !hash_equals(
                (string)($publication['compileHash'] ?? ''),
                (string)($snapshotMeta['compileHash'] ?? '')
            )) {
            throw new \RuntimeException('Публикация формы и runtime-снимок пресета не совпадают.', 409);
        }
        return ['authoring' => $authoring, 'snapshot' => $snapshot];
    }

    private function assertNeutralPresetAvailableReadOnly(int $presetId): void
    {
        $row = \CIBlockElement::GetList(
            [],
            [
                'ID' => $presetId,
                'IBLOCK_ID' => $this->runtimeIblockId('CALC_PRESETS'),
                'ACTIVE' => 'Y',
            ],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID']
        )->Fetch();
        if (!is_array($row) || (int)($row['ID'] ?? 0) !== $presetId) {
            throw new \RuntimeException('Активный пресет не найден в настроенном инфоблоке.', 409);
        }
    }

    /** @param int[] $productIds */
    private function assertNeutralParentProductsReadOnly(int $presetId, array $productIds, bool $requireActive): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        sort($productIds, SORT_NUMERIC);
        if ($productIds === []) {
            throw new \RuntimeException('У выбранных торговых предложений не найдены товары.', 409);
        }

        $productIblockId = $this->runtimeIblockId('PRODUCTS');
        $filter = ['ID' => $productIds, 'IBLOCK_ID' => $productIblockId];
        if ($requireActive) {
            $filter['ACTIVE'] = 'Y';
            $filter['ACTIVE_DATE'] = 'Y';
        }
        $found = [];
        $cursor = \CIBlockElement::GetList([], $filter, false, false, ['ID', 'IBLOCK_ID']);
        while ($row = $cursor->Fetch()) {
            $productId = (int)($row['ID'] ?? 0);
            if ($productId > 0) {
                $found[$productId] = true;
            }
        }
        $resolvedProductIds = array_map('intval', array_keys($found));
        sort($resolvedProductIds, SORT_NUMERIC);
        if ($resolvedProductIds !== $productIds) {
            throw new \RuntimeException('Не все товары доступны в текущем каталоге.', 409);
        }

        $calcPresetPropertyId = (int)(new PresetProductAssignmentPropertyAuthorityService())
            ->resolve($productIblockId, $this->pinnedRuntimeIblockIds !== null)['propertyId'];

        foreach ($productIds as $productId) {
            $presetIds = [];
            $properties = \CIBlockElement::GetProperty(
                $productIblockId,
                $productId,
                ['ID' => 'ASC'],
                ['ID' => $calcPresetPropertyId]
            );
            while ($property = $properties->Fetch()) {
                $value = (int)($property['VALUE'] ?? 0);
                if ($value > 0) {
                    $presetIds[$value] = true;
                }
            }
            $presetIds = array_map('intval', array_keys($presetIds));
            sort($presetIds, SORT_NUMERIC);
            if ($presetIds !== [$presetId]) {
                throw new \RuntimeException(
                    'Товар #' . $productId . ' не имеет однозначной привязки к пресету #' . $presetId . '.',
                    409
                );
            }
        }
    }

    /** @param int[] $productIds */
    private function resolveOnePresetForProducts(array $productIds): int
    {
        if ($productIds === []) {
            throw new \RuntimeException('У выбранных торговых предложений не найдены товары.', 409);
        }
        $presetIds = [];
        foreach ($productIds as $productId) {
            $presetId = $this->getPresetFromProduct((int)$productId);
            if ($presetId === null || $presetId <= 0) {
                throw new \RuntimeException('Товар #' . (int)$productId . ' не имеет пресета.', 409);
            }
            $presetIds[$presetId] = true;
        }
        if (count($presetIds) !== 1) {
            throw new \RuntimeException('Выбранные товары относятся к разным пресетам.', 409);
        }
        return (int)array_key_first($presetIds);
    }

    private function resolvePinnedGlobalSymbolIblockId(?array $runtimeConfigSnapshot): int
    {
        $keys = [
            'prospektweb.frontcalc:IBLOCK_CALC_GLOBAL_VALUES',
            'prospektweb.calc:IBLOCK_CALC_GLOBAL_VALUES',
        ];
        if ($runtimeConfigSnapshot === null) {
            $runtimeConfigSnapshot = array_fill_keys($keys, null);
            $result = Application::getConnection()->query(
                "SELECT MODULE_ID, NAME, VALUE, SITE_ID FROM b_option WHERE "
                . "((MODULE_ID='prospektweb.frontcalc' OR MODULE_ID='prospektweb.calc') "
                . "AND UPPER(NAME)='IBLOCK_CALC_GLOBAL_VALUES') "
                . "AND (SITE_ID IS NULL OR SITE_ID='') ORDER BY MODULE_ID, NAME"
            );
            while (is_object($result) && method_exists($result, 'fetch') && ($row = $result->fetch())) {
                $moduleId = (string)($row['MODULE_ID'] ?? $row['module_id'] ?? '');
                $actualName = (string)($row['NAME'] ?? $row['name'] ?? '');
                $siteId = array_key_exists('SITE_ID', $row)
                    ? $row['SITE_ID']
                    : ($row['site_id'] ?? null);
                $canonicalName = strtoupper($actualName);
                if (!in_array($moduleId, ['prospektweb.frontcalc', 'prospektweb.calc'], true)
                    || $canonicalName !== 'IBLOCK_CALC_GLOBAL_VALUES'
                    || $actualName !== trim($actualName)
                    || !in_array($siteId, [null, ''], true)) {
                    throw new \RuntimeException('Global-symbol iblock option authority is invalid.', 409);
                }
                $key = $moduleId . ':' . $canonicalName;
                if (!array_key_exists($key, $runtimeConfigSnapshot)
                    || $runtimeConfigSnapshot[$key] !== null) {
                    throw new \RuntimeException('Global-symbol iblock option authority is ambiguous.', 409);
                }
                $runtimeConfigSnapshot[$key] = (string)($row['VALUE'] ?? $row['value'] ?? '');
            }
        }
        $resolvedIds = [];
        foreach ($keys as $key) {
            $raw = $runtimeConfigSnapshot[$key] ?? null;
            if ($raw === null) {
                continue;
            }
            $value = (string)$raw;
            if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
                throw new \RuntimeException('IBLOCK_CALC_GLOBAL_VALUES authority is invalid.', 409);
            }
            $resolvedIds[(int)$value] = true;
        }
        if (count($resolvedIds) > 1) {
            throw new \RuntimeException('IBLOCK_CALC_GLOBAL_VALUES authorities disagree.', 409);
        }
        if ($resolvedIds !== []) {
            return (int)array_key_first($resolvedIds);
        }
        throw new \RuntimeException('IBLOCK_CALC_GLOBAL_VALUES is not configured for catalog calculation.');
    }

    /**
     * @param array<string,string|null> $snapshot
     * @return array<string,int>
     */
    private function buildPinnedRuntimeIblockMap(array $snapshot): array
    {
        $map = [];
        foreach (self::PINNED_RUNTIME_IBLOCK_CODES as $code) {
            if ($code === 'PRODUCTS') {
                $keys = [
                    'prospektweb.frontcalc:PRODUCTS_IBLOCK_ID',
                    'prospektweb.calc:PRODUCT_IBLOCK_ID',
                ];
            } elseif ($code === 'OFFERS') {
                $keys = [
                    'prospektweb.frontcalc:OFFERS_IBLOCK_ID',
                    'prospektweb.calc:SKU_IBLOCK_ID',
                ];
            } else {
                $keys = [
                    'prospektweb.frontcalc:IBLOCK_' . $code,
                    'prospektweb.calc:IBLOCK_' . $code,
                ];
            }

            $iblockId = 0;
            if ($code === 'CALC_GLOBAL_VALUES') {
                $resolvedIds = [];
                foreach ($keys as $key) {
                    $raw = $snapshot[$key] ?? null;
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
                $iblockId = $resolvedIds === [] ? 0 : (int)array_key_first($resolvedIds);
            } else {
                foreach ($keys as $key) {
                    $candidate = (int)($snapshot[$key] ?? 0);
                    if ($candidate > 0) {
                        $iblockId = $candidate;
                        break;
                    }
                }
            }
            if ($iblockId <= 0) {
                throw new \RuntimeException(
                    'Runtime source ' . $code . ' is not pinned by direct b_option authority.',
                    409
                );
            }
            $map[$code] = $iblockId;
        }
        return $map;
    }

    private function runtimeIblockId(string $code): int
    {
        if ($this->pinnedRuntimeIblockIds !== null) {
            if (!array_key_exists($code, $this->pinnedRuntimeIblockIds)) {
                throw new \RuntimeException('Unpinned runtime iblock source requested: ' . $code, 409);
            }
            return (int)$this->pinnedRuntimeIblockIds[$code];
        }

        $configManager = new ConfigManager();
        if ($code === 'PRODUCTS') {
            return $configManager->getProductIblockId();
        }
        if ($code === 'OFFERS') {
            return $configManager->getSkuIblockId();
        }
        return $configManager->getIblockId($code);
    }

    private function elementDataService(): ElementDataService
    {
        return new ElementDataService($this->pinnedRuntimeIblockIds ?? []);
    }

    private function readOptionValueDirect(string $moduleId, string $name): string
    {
        $state = $this->readOptionStateDirect($moduleId, $name);
        return $state['exists'] ? $state['value'] : '';
    }

    /** @return array{exists:bool,value:string} */
    private function readOptionStateDirect(string $moduleId, string $name): array
    {
        if ($moduleId !== 'prospektweb.frontcalc'
            || preg_match('/^FORM_FIRST_PRESET_[1-9][0-9]*$/D', $name) !== 1) {
            throw new \RuntimeException('Attempted to read an unsupported catalog runtime option.');
        }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $result = $connection->query(
            "SELECT VALUE FROM b_option WHERE MODULE_ID='" . $helper->forSql($moduleId)
            . "' AND NAME='" . $helper->forSql($name)
            . "' AND (SITE_ID IS NULL OR SITE_ID='')"
        );
        $row = is_object($result) && method_exists($result, 'fetch') ? $result->fetch() : null;
        $duplicate = is_object($result) && method_exists($result, 'fetch') ? $result->fetch() : null;
        if (is_array($duplicate)) {
            throw new \RuntimeException('Duplicate global catalog runtime option row.');
        }
        return [
            'exists' => is_array($row),
            'value' => is_array($row) ? (string)($row['VALUE'] ?? $row['value'] ?? '') : '',
        ];
    }

    /**
     * Build the product-neutral editor runtime from the exact published
     * FrontCalc authoring revision. Product/SKU data remains launch metadata;
     * calculation scenarios contain only stable semantic form values.
     *
     * @param array<int,array<string,mixed>> $selectedOffers
     * @param array<string,mixed>|null $product
     * @return array<string,mixed>
     */
    private function buildEditorRuntime(
        int $presetId,
        array $selectedOffers,
        ?array $product,
        string $mode,
        ?array $pinnedAuthoring = null,
        ?array $pinnedPublishedSnapshot = null,
        ?array $pinnedInputMapping = null,
        ?array $pinnedOutputMapping = null
    ): array {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Некорректный ID пресета editorRuntime.');
        }
        if (!in_array($mode, ['manual', 'catalog'], true)) {
            throw new \InvalidArgumentException('Некорректный режим запуска editorRuntime.');
        }
        if (!Loader::includeModule('prospektweb.frontcalc')) {
            throw new \RuntimeException('Для editorRuntime требуется модуль prospektweb.frontcalc.');
        }

        $storeClass = '\\Prospektweb\\Frontcalc\\Service\\FormFirstAuthoringStore';
        $needsPublishedBundle = $pinnedAuthoring === null || $pinnedPublishedSnapshot === null;
        if (!class_exists($storeClass)
            || ($needsPublishedBundle && !method_exists($storeClass, 'publishedBundleForPreset'))) {
            throw new \RuntimeException(
                'Установленный FrontCalc не предоставляет preset-owned публикацию формы пресета.'
            );
        }
        $publishedBundle = $needsPublishedBundle ? $storeClass::publishedBundleForPreset($presetId) : null;
        $authoring = $pinnedAuthoring
            ?? (is_array($publishedBundle['authoring'] ?? null) ? $publishedBundle['authoring'] : null);
        $publishedSnapshot = $pinnedPublishedSnapshot
            ?? (is_array($publishedBundle['snapshot'] ?? null) ? $publishedBundle['snapshot'] : null);
        $authoringKeys = is_array($authoring) ? array_keys($authoring) : [];
        sort($authoringKeys, SORT_STRING);
        $publicationKeys = is_array($authoring['publication'] ?? null)
            ? array_keys($authoring['publication'])
            : [];
        sort($publicationKeys, SORT_STRING);
        if (!is_array($authoring)
            || $authoringKeys !== ['bindingDefinition', 'formDefinition', 'publication']
            || !is_array($authoring['formDefinition'] ?? null)
            || !is_array($authoring['bindingDefinition'] ?? null)
            || !is_array($authoring['publication'] ?? null)
            || $publicationKeys !== ['compileHash', 'revision']) {
            throw new \RuntimeException('Опубликованный form-first контракт пресета отсутствует или повреждён.');
        }
        $formDefinition = $authoring['formDefinition'];
        $bindingDefinition = $authoring['bindingDefinition'];
        $publication = $authoring['publication'];
        if ((string)($formDefinition['contract'] ?? '') !== 'prospektweb.frontcalc.form-definition/v1'
            || (string)($bindingDefinition['contract'] ?? '') !== 'prospektweb.frontcalc.binding-definition/v1'
            || (int)($publication['revision'] ?? 0) <= 0
            || preg_match('/^[a-f0-9]{64}$/D', (string)($publication['compileHash'] ?? '')) !== 1) {
            throw new \RuntimeException('Опубликованный form-first контракт пресета не прошёл проверку целостности.');
        }

        $snapshotMeta = is_array($publishedSnapshot['_form_first'] ?? null)
            ? $publishedSnapshot['_form_first']
            : [];
        if ((int)($snapshotMeta['publishedRevision'] ?? 0) !== (int)$publication['revision']
            || !hash_equals(
                (string)$publication['compileHash'],
                (string)($snapshotMeta['compileHash'] ?? '')
            )) {
            throw new \RuntimeException('Опубликованная форма и runtime-снимок относятся к разным ревизиям.', 409);
        }
        $inputMapping = $pinnedInputMapping;
        $mappingErrors = [];
        try {
            $inputMapping = $inputMapping
                ?? (new \Prospektweb\Calc\Services\CalculatorInputMappingService())->load($presetId);
            $preview = (new \Prospektweb\Calc\Services\CatalogCalculationScenarioService())->preview(
                $presetId,
                $selectedOffers,
                $authoring,
                $publishedSnapshot,
                $inputMapping
            );
        } catch (\Throwable $error) {
            $preview = [
                'ready' => false,
                'hasTargets' => $selectedOffers !== [],
                'revision' => is_array($inputMapping) ? (int)($inputMapping['revision'] ?? 0) : 0,
                'scenarios' => [],
                'errors' => [],
            ];
            if ($selectedOffers === []) {
                $preview['errors'][] = ['offerId' => 0, 'message' => $error->getMessage()];
            } else {
                foreach ($selectedOffers as $offer) {
                    $preview['errors'][] = [
                        'offerId' => is_array($offer) ? (int)($offer['id'] ?? 0) : 0,
                        'message' => $error->getMessage(),
                    ];
                }
            }
        }
        $outputMapping = $pinnedOutputMapping;
        $writebackErrors = [];
        try {
            $outputMapping = $outputMapping
                ?? (new \Prospektweb\Calc\Services\CatalogOutputMappingService())->load($presetId);
            if ((int)($outputMapping['revision'] ?? 0) <= 0) {
                $writebackErrors[] = 'Автоматическая запись результатов не настроена для пресета.';
            }
        } catch (\Throwable $error) {
            $outputMapping = null;
            $writebackErrors[] = $error->getMessage();
        }

        $productIds = [];
        foreach ($selectedOffers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $productId = (int)($offer['productId'] ?? 0);
            if ($productId > 0) {
                $productIds[$productId] = true;
            }
        }
        if (is_array($product) && (int)($product['id'] ?? 0) > 0) {
            $productIds[(int)$product['id']] = true;
        }
        $productIds = array_keys($productIds);
        sort($productIds, SORT_NUMERIC);
        $offerIds = [];
        foreach ($selectedOffers as $offer) {
            $offerId = is_array($offer) ? (int)($offer['id'] ?? 0) : 0;
            if ($offerId > 0) {
                $offerIds[] = $offerId;
            }
        }
        sort($offerIds, SORT_NUMERIC);

        return [
            'contract' => 'prospektweb.calc.editor-runtime/v2',
            'launchContext' => [
                'contract' => 'prospektweb.calc.launch-context/v2',
                'mode' => $mode,
                'presetId' => $presetId,
                'productIds' => $productIds,
                'offerIds' => $offerIds,
            ],
            'formDefinition' => $formDefinition,
            'bindingDefinition' => $bindingDefinition,
            'publication' => $publication,
            'calculatorInputMapping' => $inputMapping,
            'catalogScenarios' => $preview['scenarios'],
            'catalogInputMapping' => [
                'ready' => ($preview['ready'] ?? false) === true,
                'hasTargets' => ($preview['hasTargets'] ?? false) === true,
                'revision' => (int)($preview['revision'] ?? 0),
                'errors' => is_array($preview['errors'] ?? null) ? $preview['errors'] : [],
            ],
            'catalogOutputMapping' => $outputMapping,
            'catalogWriteback' => [
                'ready' => $writebackErrors === [],
                'revision' => is_array($outputMapping) ? (int)($outputMapping['revision'] ?? 0) : 0,
                'errors' => $writebackErrors,
            ],
        ];
    }

    /**
     * Проверяет наличие необходимых модулей Bitrix
     *
     * @throws \RuntimeException
     */
    private function ensureBitrixModulesLoaded(): void
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix iblock');
        }

        if (!Loader::includeModule('catalog')) {
            throw new \RuntimeException('Требуется модуль Bitrix catalog');
        }
    }

    /**
     * Загрузить информацию о торговых предложениях
     *
     * @param array $offerIds
     * @return array
     */
    private function loadOffers(
        array $offerIds,
        ?int $requiredOfferIblockId = null,
        bool $requireActive = false
    ): array
    {
        $offers = [];

        foreach ($offerIds as $offerId) {
            $offerId = (int)$offerId;
            if ($offerId <= 0) {
                continue;
            }

            $filter = ['ID' => $offerId];
            if ($requiredOfferIblockId !== null) {
                if ($requiredOfferIblockId <= 0) {
                    throw new \RuntimeException('Инфоблок торговых предложений не настроен.', 409);
                }
                $filter['IBLOCK_ID'] = $requiredOfferIblockId;
            }
            if ($requireActive) {
                $filter['ACTIVE'] = 'Y';
                $filter['ACTIVE_DATE'] = 'Y';
            }

            $elementObject = \CIBlockElement::GetList(
                [],
                $filter,
                false,
                false,
                ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'PREVIEW_TEXT', 'DETAIL_TEXT', 'TIMESTAMP_X', 'MODIFIED_BY', 'PROPERTY_*']
            )->GetNextElement();

            if (!$elementObject) {
                continue;
            }

            $element = $elementObject->GetFields();
            $properties = PropertyPayloadLoader::loadElementProperties((int)$element['IBLOCK_ID'], $offerId);

            $productData = \CCatalogProduct::GetByID($offerId) ?: [];
            $measureInfo = $this->getMeasureInfo((int)($productData['MEASURE'] ?? 0));
            $measureRatio = $this->getMeasureRatio($offerId);
            $prices = $this->getPrices($offerId);
            $vatInfo = $this->getVatInfo((int)($productData['VAT_ID'] ?? 0));
            $extendedPriceMode = $this->hasExtendedPriceMode($prices);
            $purchasingPrice = isset($productData['PURCHASING_PRICE']) ? (float)$productData['PURCHASING_PRICE'] : null;
            $purchasingCurrency = $productData['PURCHASING_CURRENCY'] ?? null;
            $basePrice = null;
            $baseCurrency = null;
            $baseGroup = \CCatalogGroup::GetBaseGroup();
            if (!empty($baseGroup['ID'])) {
                foreach ($prices as $priceRow) {
                    if ((int)($priceRow['typeId'] ?? 0) === (int)$baseGroup['ID']) {
                        $basePrice = isset($priceRow['price']) ? (float)$priceRow['price'] : null;
                        $baseCurrency = $priceRow['currency'] ?? null;
                        break;
                    }
                }
            }

            $productId = (int)($element['PROPERTY_CML2_LINK_VALUE'] ?? 0);
            if ($productId <= 0) {
                $skuParent = \CCatalogSku::GetProductInfo($offerId);
                if (!empty($skuParent['ID'])) {
                    $productId = (int)$skuParent['ID'];
                }
            }
            if ($requiredOfferIblockId !== null) {
                $skuParent = \CCatalogSku::GetProductInfo($offerId);
                $catalogParentId = (int)($skuParent['ID'] ?? 0);
                if ($catalogParentId <= 0 || $productId <= 0 || $catalogParentId !== $productId) {
                    throw new \RuntimeException(
                        'Торговое предложение #' . $offerId . ' не имеет однозначной связи с товаром.',
                        409
                    );
                }
            }

            $offers[] = [
                'id' => $offerId,
                'iblockId' => (int)$element['IBLOCK_ID'],
                'productId' => $productId,
                'name' => $element['NAME'] ?? '',
                'code' => $element['CODE'] ?? null,
                'previewText' => (string)($element['PREVIEW_TEXT'] ?? ''),
                'detailText' => (string)($element['DETAIL_TEXT'] ?? ''),
                'timestampX' => $element['TIMESTAMP_X'] ?? null,
                'modifiedBy' => isset($element['MODIFIED_BY']) ? (int)$element['MODIFIED_BY'] : null,
                'timestamp_x' => $element['TIMESTAMP_X'] ?? null,
                'modified_by' => isset($element['MODIFIED_BY']) ? (int)$element['MODIFIED_BY'] : null,
                'attributes' => [
                    'width' => isset($productData['WIDTH']) ? (float)$productData['WIDTH'] : null,
                    'height' => isset($productData['HEIGHT']) ? (float)$productData['HEIGHT'] :  null,
                    'length' => isset($productData['LENGTH']) ? (float)$productData['LENGTH'] : null,
                    'weight' => isset($productData['WEIGHT']) ? (float)$productData['WEIGHT'] : null,
                ],
                'measure' => $measureInfo,
                'measureRatio' => $measureRatio,
                'prices' => $prices,
                'purchasingPrice' => $purchasingPrice,
                'purchasingCurrency' => $purchasingCurrency,
                'catalog' => [
                    'vatId' => (int)($productData['VAT_ID'] ?? 0),
                    'vatIncluded' => ($productData['VAT_INCLUDED'] ?? 'N') === 'Y',
                    'vat' => $vatInfo,
                    'extendedPriceMode' => $extendedPriceMode,
                    'basePrice' => $basePrice,
                    'baseCurrency' => $baseCurrency,
                ],
                'properties' => $properties,
            ];
        }

        return $offers;
    }

    /**
     * Получить коэффициент единицы измерения для товара
     */
    private function getMeasureRatio(int $productId): float
    {
        if ($productId <= 0) {
            return 1.0;
        }

        $ratioIterator = \CCatalogMeasureRatio::getList(
            [],
            ['PRODUCT_ID' => $productId]
        );

        if ($ratio = $ratioIterator->Fetch()) {
            return (float)($ratio['RATIO'] ?? 1);
        }

        return 1.0;
    }

    /**
     * Получить информацию о единице измерения
     */
    private function getMeasureInfo(int $measureId): ?array
    {
        if ($measureId <= 0) {
            return null;
        }

        $measureIterator = \CCatalogMeasure::getList(
            ['ID' => 'ASC'],
            ['=ID' => $measureId]
        );

        if ($measure = $measureIterator->Fetch()) {
            return [
                'id' => (int)$measure['ID'],
                'code' => $measure['CODE'] ?? null,
                'symbol' => $measure['SYMBOL'] ?? null,
                'symbolInt' => $measure['SYMBOL_INTL'] ?? null,
                'title' => $measure['MEASURE_TITLE'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Получить цены для торгового предложения
     */
    private function getPrices(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $prices = [];
        $priceIterator = \CPrice::GetList(
            [],
            ['PRODUCT_ID' => $productId]
        );

        while ($price = $priceIterator->Fetch()) {
            $prices[] = [
                'typeId' => (int)$price['CATALOG_GROUP_ID'],
                'price' => (float)$price['PRICE'],
                'currency' => $price['CURRENCY'] ?? null,
                'quantityFrom' => isset($price['QUANTITY_FROM']) ? (int)$price['QUANTITY_FROM'] : null,
                'quantityTo' => isset($price['QUANTITY_TO']) ? (int)$price['QUANTITY_TO'] : null,
            ];
        }

        return $prices;
    }

    private function getVatInfo(int $vatId): ?array
    {
        if ($vatId <= 0 || !class_exists('\CCatalogVat')) {
            return null;
        }

        $iterator = \CCatalogVat::GetByID($vatId);
        if (!is_object($iterator) || !($vat = $iterator->Fetch())) {
            return null;
        }

        return [
            'id' => (int)($vat['ID'] ?? $vatId),
            'name' => (string)($vat['NAME'] ?? ''),
            'rate' => isset($vat['RATE']) ? (float)$vat['RATE'] : null,
        ];
    }

    private function hasExtendedPriceMode(array $prices): bool
    {
        foreach ($prices as $price) {
            if (($price['quantityFrom'] ?? null) !== null || ($price['quantityTo'] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Анализировать состояние PRESET у торговых предложений
     * Все ТП принадлежат одному товару, берём productId из первого
     * 
     * @param array $offers Массив ТП
     * @return array Результат анализа
     */
    private function analyzeBundles(array $offers): array
    {
        if (empty($offers)) {
            return [
                'scenario' => 'NEW_BUNDLE',
                'bundleId' => null,
                'productId' => 0,
            ];
        }
        
        // Все ТП принадлежат одному товару, берём productId из первого
        $productId = $this->getProductIdFromOffer($offers[0]);
        
        if ($productId <= 0) {
            return [
                'scenario' => 'NEW_BUNDLE',
                'bundleId' => null,
                'productId' => 0,
            ];
        }
        
        // Получаем CALC_PRESET из товара
        $presetId = $this->getPresetFromProduct($productId);
        
        if ($presetId !== null && $presetId > 0) {
            // У товара есть preset → используем существующий
            return [
                'scenario' => 'EXISTING_PRESET',
                'bundleId' => $presetId,
                'productId' => $productId,
            ];
        }
        
        // У товара нет preset → создаём новый
        return [
            'scenario' => 'NEW_BUNDLE',
            'bundleId' => null,
            'productId' => $productId,
        ];
    }
    
    /**
     * Получить ID товара из offer
     * 
     * @param array $offer Данные ТП
     * @return int ID товара
     */
    private function getProductIdFromOffer(array $offer): int
    {
        // Сначала проверяем прямое поле (если добавлено)
        if (!empty($offer['productId'])) {
            return (int)$offer['productId'];
        }
        
        // Затем ищем в properties
        if (isset($offer['properties']['CML2_LINK']['VALUE'])) {
            return (int)$offer['properties']['CML2_LINK']['VALUE'];
        }
        
        // Fallback через CCatalogSku
        if (! empty($offer['id'])) {
            $skuParent = \CCatalogSku::GetProductInfo((int)$offer['id']);
            if (!empty($skuParent['ID'])) {
                return (int)$skuParent['ID'];
            }
        }
        
        return 0;
    }
    
    /**
     * Получить presetId из товара
     * 
     * @param int $productId ID товара
     * @return int|null ID пресета или null
     */
    private function getPresetFromProduct(int $productId): ?int
    {
        if ($productId <= 0) {
            return null;
        }
        
        $productIblockId = $this->runtimeIblockId('PRODUCTS');
        
        if ($productIblockId <= 0) {
            return null;
        }
        
        $rsProduct = \CIBlockElement::GetList(
            [],
            ['ID' => $productId, 'IBLOCK_ID' => $productIblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID']
        );
        
        if ($product = $rsProduct->Fetch()) {
            $calcPresetPropertyId = (int)(new PresetProductAssignmentPropertyAuthorityService())
                ->resolve($productIblockId, $this->pinnedRuntimeIblockIds !== null)['propertyId'];
            $presetIds = [];
            $properties = \CIBlockElement::GetProperty(
                $productIblockId,
                $productId,
                ['ID' => 'ASC'],
                ['ID' => $calcPresetPropertyId]
            );
            while ($properties && ($property = $properties->Fetch())) {
                $value = (int)($property['VALUE'] ?? 0);
                if ($value > 0) {
                    $presetIds[$value] = $value;
                }
            }
            if (count($presetIds) > 1) {
                throw new \RuntimeException('Product CALC_PRESET assignment is ambiguous.', 409);
            }
            return $presetIds === [] ? null : (int)reset($presetIds);
        }
        
        return null;
    }

    /**
     * Собрать контекст запроса
     *
     * @param string $siteId
     * @return array
     */
    private function buildContext(string $siteId): array
    {
        global $USER;

        $context = Application::getInstance()->getContext();

        $resolvedSiteId = $context->getSite() ?: (defined('SITE_ID') ? SITE_ID : null);
        if (empty($resolvedSiteId)) {
            $resolvedSiteId = $siteId;
        }

        $languageId = $context->getLanguage() ?: (defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru');

        $siteUrl = $this->buildSiteUrl($context->getRequest()->getHttpHost());

        $userId = '0';
        if (is_object($USER) && method_exists($USER, 'GetID')) {
            $userIdValue = $USER->GetID();
            if ($userIdValue !== null) {
                $userId = (string)$userIdValue;
            }
        }

        $settingsManager = new \Prospektweb\Calc\Config\SettingsManager();
        $editorTheme = \CUserOptions::GetOption(
            self::MODULE_ID,
            'editor_theme',
            'dark',
            (int)$userId
        );
        if (!in_array($editorTheme, ['dark', 'cream', 'monolith', 'obsidian', 'soft-graphite'], true)) {
            $editorTheme = 'dark';
        }

        return [
            'siteId' => (string)$resolvedSiteId,
            'userId' => $userId,
            'lang' => $languageId,
            'timestamp' => time(),
            'url' => $siteUrl,
            'defaultExtraValue' => $settingsManager->getDefaultExtraValue(),
            'defaultExtraCurrency' => $settingsManager->getDefaultExtraCurrency(),
            'editorTheme' => $editorTheme,
            'saveCalculationHistory' => Option::get(self::MODULE_ID, 'SAVE_CALC_HISTORY', 'N') === 'Y',
            'priceSettingsPresets' => (new \Prospektweb\Calc\Services\PriceSettingsPresetService())->list(),
        ];
    }

    private function buildSiteUrl(?string $host): string
    {
        if (empty($host)) {
            $host = (string)Option::get('main', 'server_name', '');
        }

        $host = trim((string)$host);

        if ($host === '') {
            return '';
        }

        return sprintf('https://%s', $host);
    }

    /**
     * Получить ID инфоблоков из настроек
     *
     * @return array
     */
    private function getIblocks(): array
    {
        $map = [
            'PRODUCTS' => $this->runtimeIblockId('PRODUCTS'),
            'OFFERS' => $this->runtimeIblockId('OFFERS'),
            'CALC_PRESETS' => $this->runtimeIblockId('CALC_PRESETS'),
            'CALC_STAGES' => $this->runtimeIblockId('CALC_STAGES'),
            'CALC_SETTINGS' => $this->runtimeIblockId('CALC_SETTINGS'),
            'CALC_CUSTOM_FIELDS' => $this->runtimeIblockId('CALC_CUSTOM_FIELDS'),
            'CALC_MATERIALS' => $this->runtimeIblockId('CALC_MATERIALS'),
            'CALC_MATERIALS_VARIANTS' => $this->runtimeIblockId('CALC_MATERIALS_VARIANTS'),
            'CALC_OPERATIONS' => $this->runtimeIblockId('CALC_OPERATIONS'),
            'CALC_OPERATIONS_VARIANTS' => $this->runtimeIblockId('CALC_OPERATIONS_VARIANTS'),
            'CALC_EQUIPMENT' => $this->runtimeIblockId('CALC_EQUIPMENT'),
            'CALC_DETAILS' => $this->runtimeIblockId('CALC_DETAILS'),
        ];

        $parentMap = [
            'CALC_MATERIALS_VARIANTS' => 'CALC_MATERIALS',
            'CALC_OPERATIONS_VARIANTS' => 'CALC_OPERATIONS',
        ];

        $result = [];

        foreach ($map as $code => $id) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }

            $iblock = \CIBlock::GetArrayByID($id) ?: [];
            
            // Получаем свойства инфоблока
            $properties = $this->getIblockProperties($id);
            
            $result[] = [
                'id' => $id,
                'code' => $code,
                'type' => $iblock['IBLOCK_TYPE_ID'] ?? null,
                'name' => $iblock['NAME'] ?? $code,
                'parent' => isset($parentMap[$code], $map[$parentMap[$code]]) && (int)$map[$parentMap[$code]] > 0
                    ? (int)$map[$parentMap[$code]]
                    : null,
                'properties' => $properties,
            ];
        }

        return $result;
    }

    /**
     * Получить свойства инфоблока
     *
     * @param int $iblockId ID инфоблока
     * @return array Массив свойств
     */
    private function getIblockProperties(int $iblockId): array
    {
        $properties = [];
        
        $rsProperties = \CIBlockProperty::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y']
        );
        
        while ($prop = $rsProperties->Fetch()) {
            $userTypeSettings = $this->normalizeUserTypeSettings($prop['USER_TYPE_SETTINGS'] ?? null);
            $property = [
                'ID' => (int)$prop['ID'],
                'CODE' => $prop['CODE'] ?? '',
                'NAME' => $prop['NAME'] ?? '',
                'PROPERTY_TYPE' => $prop['PROPERTY_TYPE'] ?? '',
                'MULTIPLE' => $prop['MULTIPLE'] ?? 'N',
                'IS_REQUIRED' => $prop['IS_REQUIRED'] ?? 'N',
                'SORT' => (int)($prop['SORT'] ?? 500),
                'DEFAULT_VALUE' => $prop['DEFAULT_VALUE'] ?? '',
                'LINK_IBLOCK_ID' => $prop['LINK_IBLOCK_ID'] ? (int)$prop['LINK_IBLOCK_ID'] : null,
                'USER_TYPE' => $prop['USER_TYPE'] ?? null,
                'USER_TYPE_SETTINGS' => $userTypeSettings,
                'WITH_DESCRIPTION' => $prop['WITH_DESCRIPTION'] ?? 'N',
                'MULTIPLE_CNT' => $prop['MULTIPLE_CNT'] ?? 5,
                'ROW_COUNT' => $prop['ROW_COUNT'] ?? 1,
                'COL_COUNT' => $prop['COL_COUNT'] ?? 30,
            ];
            
            // Если тип свойства - список (L), получаем варианты значений
            if ($prop['PROPERTY_TYPE'] === 'L') {
                $enums = [];
                $rsEnums = \CIBlockPropertyEnum::GetList(
                    ['SORT' => 'ASC', 'ID' => 'ASC'],
                    ['PROPERTY_ID' => $prop['ID']]
                );
                
                while ($enum = $rsEnums->Fetch()) {
                    $enums[] = [
                        'ID' => (int)$enum['ID'],
                        'VALUE' => $enum['VALUE'] ?? '',
                        'XML_ID' => $enum['XML_ID'] ?? '',
                        'DEF' => $enum['DEF'] ?? 'N',
                        'SORT' => (int)($enum['SORT'] ?? 500),
                    ];
                }
                
                $property['ENUMS'] = $enums;
            }

            if (
                ($prop['PROPERTY_TYPE'] ?? '') === 'S'
                && ($prop['USER_TYPE'] ?? '') === 'directory'
            ) {
                $property['DIRECTORY_ITEMS'] = $this->loadDirectoryItems(
                    (string)($userTypeSettings['TABLE_NAME'] ?? '')
                );
            }
            
            $properties[] = $property;
        }
        
        return $properties;
    }

    /**
     * CIBlockProperty may return directory settings either as an array or as a
     * serialized value depending on the Bitrix execution path.
     */
    private function normalizeUserTypeSettings($settings): array
    {
        if (is_array($settings)) {
            return $settings;
        }

        if (!is_string($settings) || trim($settings) === '') {
            return [];
        }

        $decoded = @unserialize($settings, ['allowed_classes' => false]);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Resolve a Bitrix directory property into stable XML_ID/name/image
     * options for the calculator mapping UI.
     */
    private function loadDirectoryItems(string $tableName): array
    {
        static $cache = [];

        $tableName = trim($tableName);
        if ($tableName === '') {
            return [];
        }
        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }
        if (!Loader::includeModule('highloadblock')) {
            return $cache[$tableName] = [];
        }

        $hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getList([
            'filter' => ['=TABLE_NAME' => $tableName],
            'limit' => 1,
        ])->fetch();
        if (!$hlblock) {
            return $cache[$tableName] = [];
        }

        $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
        $dataClass = $entity->getDataClass();
        $fields = $entity->getFields();
        $order = isset($fields['UF_SORT']) ? ['UF_SORT' => 'ASC', 'ID' => 'ASC'] : ['ID' => 'ASC'];
        $rows = $dataClass::getList([
            'select' => ['*'],
            'order' => $order,
        ])->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            $id = (int)($row['ID'] ?? 0);
            $xmlId = trim((string)($row['UF_XML_ID'] ?? ''));
            if ($xmlId === '') {
                $xmlId = (string)$id;
            }
            $name = trim((string)($row['UF_NAME'] ?? $row['UF_DESCRIPTION'] ?? $xmlId));
            $fileId = (int)($row['UF_FILE'] ?? 0);
            $image = null;
            if ($fileId > 0) {
                $file = \CFile::GetFileArray($fileId);
                if (is_array($file) && !empty($file['SRC'])) {
                    $image = [
                        'SRC' => (string)$file['SRC'],
                        'WIDTH' => (int)($file['WIDTH'] ?? 0),
                        'HEIGHT' => (int)($file['HEIGHT'] ?? 0),
                    ];
                }
            }

            $items[] = [
                'ID' => $id,
                'VALUE' => $name !== '' ? $name : $xmlId,
                'XML_ID' => $xmlId,
                'SORT' => (int)($row['UF_SORT'] ?? 500),
                'IMAGE' => $image,
            ];
        }

        return $cache[$tableName] = $items;
    }

    /**
     * Найти ID инфоблока по его коду в массиве объектов.
     */
    private function findIblockIdByCode(array $iblocks, string $code): int
    {
        foreach ($iblocks as $iblock) {
            if (($iblock['code'] ?? null) === $code) {
                return (int)($iblock['id'] ?? 0);
            }
        }

        return 0;
    }


    /**
    * Загрузить preset со всеми данными
    * 
    * @param int $presetId ID пресета
    * @return array|null
    */
    private function loadPreset(int $presetId): ?array
    {
        if ($presetId <= 0) {
            return null;
        }

        $iblockId = $this->runtimeIblockId('CALC_PRESETS');
        
        if ($iblockId <= 0) {
            return null;
        }

        // Получаем основные поля элемента
        $rsElement = \CIBlockElement:: GetList(
            [],
            ['ID' => $presetId, 'IBLOCK_ID' => $iblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME', 'CODE', 'IBLOCK_SECTION_ID']
        );

        $fields = $rsElement->Fetch();
        if (! $fields) {
            return null;
        }

        $elementDataService = $this->elementDataService();
        $presetElement = $elementDataService->loadSingleElement($iblockId, $presetId, null, true);
        if (!$presetElement) {
            return null;
        }

        $presetElement['prices'] = $this->mergePriceLimits(
            (array)($presetElement['prices'] ?? []),
            $this->loadPriceLimits($iblockId, $presetId)
        );
        $presetElement['priceProfilePolicy'] = $this->loadPriceProfilePolicy($iblockId, $presetId);

        $propertiesRaw = $this->loadPresetProperties($iblockId, $presetId);
        $presetElement['properties'] = [];
        foreach ($propertiesRaw as $code => $property) {
            if (in_array($code, ['GLOBAL_VARIABLES', 'GLOBAL_CONSTANTS'], true)) {
                $presetElement['properties'][$code] = [
                    'VALUE' => $property['values'] ?? [],
                    'DESCRIPTION' => $property['descriptions'] ?? [],
                ];
            } elseif ($code === 'STAGE_GROUPS') {
                $value = $property['values'][0] ?? null;
                $presetElement['properties'][$code] = [
                    'VALUE' => $value,
                    '~VALUE' => $value,
                ];
            } else {
                $presetElement['properties'][$code] = $property['values'] ?? [];
            }
        }
        $presetElement['iblockId'] = $iblockId;

        $this->elementsStore = $this->buildElementsStore($propertiesRaw);

        return $presetElement;
    }

    /**
    * Загрузить товар со всеми данными для INIT payload
    *
    * @param int $productId ID товара
    * @return array|null
    */
    private function loadProduct(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        $configManager = new ConfigManager();
        $iblockId = $configManager->getProductIblockId();
        if ($iblockId <= 0) {
            return null;
        }

        $elementDataService = $this->elementDataService();
        $productElement = $elementDataService->loadSingleElement($iblockId, $productId, null, true);

        if (!$productElement) {
            return null;
        }

        $publicElement = \CIBlockElement::GetList(
            [],
            ['ID' => $productId, 'IBLOCK_ID' => $iblockId],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'DETAIL_PAGE_URL']
        )->GetNext();
        $productElement['detailPageUrl'] = trim((string)($publicElement['DETAIL_PAGE_URL'] ?? ''));

        return $productElement;
    }

    /**
    * Загрузить свойства preset через GetProperty (для инфоблоков версии 2)
    * 
    * @param int $iblockId ID инфоблока
    * @param int $elementId ID элемента
    * @return array Массив [CODE => [values]]
    */
    private function loadPresetProperties(int $iblockId, int $elementId): array
    {
        $result = [];

        $rsProperty = \CIBlockElement::GetProperty(
            $iblockId,
            $elementId,
            [],
            []
        );

        while ($arProp = $rsProperty->Fetch()) {
            $code = $arProp['CODE'] ?: (string)$arProp['ID'];

            if (in_array($code, ['JSON', 'CALC_DIMENSIONS_WEIGHT', 'PRICE_LIMITS_JSON', 'PRICE_PROFILE_POLICY_JSON'], true)) {
                continue;
            }

            if (!isset($result[$code])) {
                $result[$code] = [
                    'property' => $arProp,
                    'values' => [],
                    'descriptions' => [],
                ];
            }

            if ($arProp['VALUE'] !== null && $arProp['VALUE'] !== '') {
                $result[$code]['values'][] = $arProp['VALUE'];
                $result[$code]['descriptions'][] = (string)($arProp['DESCRIPTION'] ?? '');
            }
        }

        return $result;
    }

    private function loadPriceLimits(int $iblockId, int $presetId): array
    {
        $property = \CIBlockElement::GetProperty(
            $iblockId,
            $presetId,
            [],
            ['CODE' => 'PRICE_LIMITS_JSON']
        )->Fetch();
        $raw = $property['VALUE'] ?? '';
        if (is_array($raw)) {
            $raw = $raw['TEXT'] ?? '';
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded) || (int)($decoded['version'] ?? 0) !== 1) {
            return [];
        }
        return array_values(array_filter((array)($decoded['limits'] ?? []), static function ($limit): bool {
            return is_array($limit)
                && (int)($limit['typeId'] ?? 0) > 0
                && (float)($limit['limitRub'] ?? 0) > 0;
        }));
    }

    private function loadPriceProfilePolicy(int $iblockId, int $presetId): ?array
    {
        $property = \CIBlockElement::GetProperty(
            $iblockId,
            $presetId,
            [],
            ['CODE' => 'PRICE_PROFILE_POLICY_JSON']
        )->Fetch();
        $raw = $property['VALUE'] ?? '';
        if (is_array($raw)) {
            $raw = $raw['TEXT'] ?? '';
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)
            || (int)($decoded['version'] ?? 0) !== 1
            || ($decoded['$schema'] ?? '') !== 'prospektweb.calc.conditional-price-profiles/v1'
            || !is_array($decoded['rules'] ?? null)) {
            return null;
        }
        return $decoded;
    }

    private function mergePriceLimits(array $prices, array $limits): array
    {
        $indexed = [];
        foreach ($limits as $limit) {
            $key = $this->priceRangeKey(
                (int)$limit['typeId'],
                isset($limit['quantityFrom']) ? (int)$limit['quantityFrom'] : null,
                isset($limit['quantityTo']) ? (int)$limit['quantityTo'] : null
            );
            $indexed[$key] = max(0.0, (float)$limit['limitRub']);
        }

        foreach ($prices as &$price) {
            $key = $this->priceRangeKey(
                (int)($price['typeId'] ?? 0),
                isset($price['quantityFrom']) ? (int)$price['quantityFrom'] : null,
                isset($price['quantityTo']) ? (int)$price['quantityTo'] : null
            );
            $price['limitRub'] = ($indexed[$key] ?? 0) > 0 ? $indexed[$key] : null;
        }
        unset($price);
        return $prices;
    }

    private function priceRangeKey(int $typeId, ?int $quantityFrom, ?int $quantityTo): string
    {
        $quantityFrom = ($quantityFrom !== null && $quantityFrom > 0) ? $quantityFrom : null;
        $quantityTo = ($quantityTo !== null && $quantityTo > 0) ? $quantityTo : null;
        return $typeId . ':' . ($quantityFrom === null ? 'n' : $quantityFrom)
            . ':' . ($quantityTo === null ? 'n' : $quantityTo);
    }


    /**
     * Собирает элементы в elementsStore по коду свойства.
     */
    private function buildElementsStore(array $propertiesRaw): array
    {
        $elementDataService = $this->elementDataService();
        $store = [];
        $linkedIblockIds = [];

        foreach ($propertiesRaw as $code => $propertyData) {
            $values = $propertyData['values'] ?? [];
            $ids = array_filter(array_map('intval', $values), static fn($id) => $id > 0);

            $linkIblockId = isset($propertyData['property']['LINK_IBLOCK_ID'])
                ? (int)$propertyData['property']['LINK_IBLOCK_ID']
                : 0;

            if ($linkIblockId <= 0) {
                continue;
            }
            if ($this->pinnedRuntimeIblockIds !== null
                && isset($this->pinnedRuntimeIblockIds[$code])
                && $linkIblockId !== (int)$this->pinnedRuntimeIblockIds[$code]) {
                throw new \RuntimeException(
                    'Preset source link ' . $code . ' differs from direct b_option authority.',
                    409
                );
            }

            $linkedIblockIds[$code] = $linkIblockId;

            if (empty($ids)) {
                if ($code === 'CALC_CUSTOM_FIELDS') {
                    $store[$code] = [];
                }
                continue;
            }

            $payload = $elementDataService->prepareRefreshPayload([
                [
                    'iblockId' => $linkIblockId,
                    'iblockType' => null,
                    'ids' => $ids,
                    'includeParent' => true,
                ],
            ]);

            $store[$code] = $payload[0]['data'] ?? [];
        }

        // Equipment selected through OPTIONS_EQUIPMENT is not necessarily the
        // stage's static EQUIPMENT value. Preload every mapped candidate so the
        // calculation runtime can resolve stable stage equipment paths after it
        // chooses a mapping for the current product/offer.
        $mappedEquipmentIds = self::extractMappedVariantIdsFromStages(
            $store['CALC_STAGES'] ?? [],
            'OPTIONS_EQUIPMENT'
        );
        $loadedEquipmentIds = array_map(
            static fn(array $item): int => (int)($item['id'] ?? 0),
            is_array($store['CALC_EQUIPMENT'] ?? null) ? $store['CALC_EQUIPMENT'] : []
        );
        $missingEquipmentIds = array_values(array_diff($mappedEquipmentIds, $loadedEquipmentIds));
        $equipmentIblockId = (int)($linkedIblockIds['CALC_EQUIPMENT'] ?? 0);

        if ($equipmentIblockId > 0 && !empty($missingEquipmentIds)) {
            $payload = $elementDataService->prepareRefreshPayload([
                [
                    'iblockId' => $equipmentIblockId,
                    'iblockType' => null,
                    'ids' => $missingEquipmentIds,
                    'includeParent' => true,
                ],
            ]);
            $mappedEquipment = $payload[0]['data'] ?? [];
            $equipmentById = [];
            foreach (array_merge($store['CALC_EQUIPMENT'] ?? [], $mappedEquipment) as $equipment) {
                $equipmentId = (int)($equipment['id'] ?? 0);
                if ($equipmentId > 0) {
                    $equipmentById[$equipmentId] = $equipment;
                }
            }
            $store['CALC_EQUIPMENT'] = array_values($equipmentById);
        }

        return $store;
    }

    /**
     * Collect target entity IDs referenced by a stage OPTIONS_* mapping.
     *
     * @param array<int, array<string, mixed>> $stages
     * @return int[]
     */
    private static function extractMappedVariantIdsFromStages(array $stages, string $propertyCode): array
    {
        $ids = [];
        $mappingService = new \Prospektweb\Calc\Services\StageVariantMappingService();

        foreach ($stages as $stage) {
            $property = $stage['properties'][$propertyCode] ?? null;
            if (!is_array($property)) {
                continue;
            }

            $rawValue = $property['~VALUE'] ?? $property['VALUE'] ?? null;
            if (is_array($rawValue) && array_key_exists('TEXT', $rawValue)) {
                $rawValue = $rawValue['TEXT'];
            }
            if (!is_string($rawValue) || trim($rawValue) === '') {
                continue;
            }

            $decoded = html_entity_decode($rawValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            try {
                $variantIds = $mappingService->variantIdsFromJson($decoded);
            } catch (\InvalidArgumentException $error) {
                // Old property-code mappings are intentionally not interpreted.
                // The static entity remains the safe fallback until an author
                // saves the canonical preset-form mapping.
                continue;
            }
            foreach ($variantIds as $variantId) {
                $ids[$variantId] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * Собрать дерево данных всех инфоблоков модуля для MultiLevelSelect
     * 
     * @return array Массив деревьев по ключам инфоблоков
     */
    private function buildIblocksTree(): array
    {
        $iblocks = $this->getIblocks();
        $trees = [];

        // CALC_SETTINGS
        $calcSettingsId = $this->findIblockIdByCode($iblocks, 'CALC_SETTINGS');
        if ($calcSettingsId > 0) {
            $trees['calcSettings'] = $this->buildIblockTree($calcSettingsId);
        }

        // CALC_EQUIPMENT
        $calcEquipmentId = $this->findIblockIdByCode($iblocks, 'CALC_EQUIPMENT');
        if ($calcEquipmentId > 0) {
            $trees['calcEquipment'] = $this->buildIblockTree($calcEquipmentId);
        }

        // CALC_MATERIALS с variants
        $calcMaterialsId = $this->findIblockIdByCode($iblocks, 'CALC_MATERIALS');
        $calcMaterialsVariantsId = $this->findIblockIdByCode($iblocks, 'CALC_MATERIALS_VARIANTS');
        if ($calcMaterialsId > 0) {
            $trees['calcMaterials'] = $this->buildCatalogTree(
                $calcMaterialsId,
                $calcMaterialsVariantsId
            );
        }
        
        // CALC_OPERATIONS с variants
        $calcOperationsId = $this->findIblockIdByCode($iblocks, 'CALC_OPERATIONS');
        $calcOperationsVariantsId = $this->findIblockIdByCode($iblocks, 'CALC_OPERATIONS_VARIANTS');
        if ($calcOperationsId > 0) {
            $trees['calcOperations'] = $this->buildCatalogTree(
                $calcOperationsId,
                $calcOperationsVariantsId
            );
        }

        return $trees;
    }

    /**
     * Строит дерево разделов и элементов для одного инфоблока (без дочерних элементов)
     *
     * @param int $iblockId ID инфоблока
     * @return array
     */
    private function buildIblockTree(int $iblockId): array
    {
        if ($iblockId <= 0) {
            return [];
        }

        $sections = $this->getSections($iblockId);
        $elements = $this->getElements($iblockId);

        return $this->assembleTree($sections, $elements);
    }

    /**
     * Строит дерево товаров с торговыми предложениями
     *
     * @param int $productIblockId ID инфоблока товаров
     * @param int $offersIblockId ID инфоблока торговых предложений
     * @return array
     */
    private function buildProductsTree(int $productIblockId, int $offersIblockId): array
    {
        if ($productIblockId <= 0) {
            return [];
        }

        $sections = $this->getSections($productIblockId);
        $elements = $this->getElements($productIblockId);

        // Получаем торговые предложения для товаров
        $productIds = array_column($elements, 'id');
        $offers = [];
        
        if ($offersIblockId > 0 && !empty($productIds)) {
            $offersData = \CCatalogSKU::getOffersList(
                $productIds,
                $productIblockId,
                [],
                ['ID', 'NAME', 'CODE'],
                ['ID', 'NAME', 'CODE']
            );
            
            if (is_array($offersData)) {
                foreach ($offersData as $productId => $productOffers) {
                    $offers[$productId] = [];
                    foreach ($productOffers as $offer) {
                        $offers[$productId][] = [
                            'type' => 'child',
                            'id' => (int)$offer['ID'],
                            'name' => $offer['NAME'] ?? '',
                            'code' => $offer['CODE'] ?? '',
                            'iblockId' => $offersIblockId,
                            'parentId' => $productId,
                        ];
                    }
                }
            }
        }

        // Добавляем торговые предложения к элементам
        foreach ($elements as &$element) {
            if (!empty($offers[$element['id']])) {
                $element['children'] = $offers[$element['id']];
            }
        }
        unset($element);

        return $this->assembleTree($sections, $elements);
    }

    /**
     * Строит дерево для каталогов со SKU-связью (materials, operations, details)
     *
     * @param int $parentIblockId ID основного инфоблока
     * @param int $variantsIblockId ID инфоблока вариантов
     * @return array
     */
    private function buildCatalogTree(int $parentIblockId, int $variantsIblockId): array
    {
        if ($parentIblockId <= 0) {
            return [];
        }

        $sections = $this->getSections($parentIblockId);
        $elements = $this->getElements($parentIblockId);

        // Получаем варианты для элементов
        $parentIds = array_column($elements, 'id');
        $variants = [];
        
        if ($variantsIblockId > 0 && !empty($parentIds)) {
            $variantsData = $this->getVariants($variantsIblockId, $parentIds);
            
            foreach ($variantsData as $variant) {
                $parentId = $variant['parentId'];
                if (!isset($variants[$parentId])) {
                    $variants[$parentId] = [];
                }
                $variants[$parentId][] = $variant;
            }
        }

        // Добавляем варианты к элементам
        foreach ($elements as &$element) {
            if (!empty($variants[$element['id']])) {
                $element['children'] = $variants[$element['id']];
            }
        }
        unset($element);

        return $this->assembleTree($sections, $elements);
    }

    /**
     * Получает разделы и строит иерархию
     *
     * @param int $iblockId ID инфоблока
     * @return array
     */
    private function getSections(int $iblockId): array
    {
        if ($iblockId <= 0) {
            return [];
        }

        $sections = [];
        $res = \CIBlockSection::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
            false,
            ['ID', 'NAME', 'CODE', 'DESCRIPTION', 'IBLOCK_SECTION_ID', 'IBLOCK_ID', 'SORT', 'DEPTH_LEVEL']
        );

        while ($section = $res->Fetch()) {
            $sections[] = [
                'type' => 'section',
                'id' => (int)$section['ID'],
                'name' => $section['NAME'] ?? '',
                'code' => $section['CODE'] ?? '',
                'description' => trim(strip_tags((string)($section['DESCRIPTION'] ?? ''))),
                'iblockId' => (int)$section['IBLOCK_ID'],
                'parentId' => !empty($section['IBLOCK_SECTION_ID']) ? (int)$section['IBLOCK_SECTION_ID'] : null,
                'depth' => (int)($section['DEPTH_LEVEL'] ?? 1),
            ];
        }

        return $sections;
    }

    /**
     * Получает элементы с их свойствами
     *
     * @param int $iblockId ID инфоблока
     * @return array
     */
    private function getElements(int $iblockId): array
    {
        if ($iblockId <= 0) {
            return [];
        }

        $elements = [];
        $res = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
            false,
            false,
            ['ID', 'NAME', 'CODE', 'PREVIEW_TEXT', 'IBLOCK_SECTION_ID', 'IBLOCK_ID', 'TIMESTAMP_X', 'MODIFIED_BY']
        );

        while ($fields = $res->Fetch()) {
            $elements[] = [
                'type' => 'element',
                'id' => (int)$fields['ID'],
                'name' => $fields['NAME'] ?? '',
                'code' => $fields['CODE'] ?? '',
                'description' => trim(strip_tags((string)($fields['PREVIEW_TEXT'] ?? ''))),
                'iblockId' => (int)$fields['IBLOCK_ID'],
                'sectionId' => !empty($fields['IBLOCK_SECTION_ID']) ? (int)$fields['IBLOCK_SECTION_ID'] : 0,
                'timestampX' => $fields['TIMESTAMP_X'] ?? null,
                'modifiedBy' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
                'timestamp_x' => $fields['TIMESTAMP_X'] ?? null,
                'modified_by' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
            ];
        }

        return $elements;
    }

    /**
     * Получает варианты для списка родительских элементов
     *
     * @param int $variantsIblockId ID инфоблока вариантов
     * @param array $parentIds Массив ID родительских элементов
     * @return array
     */
    private function getVariants(int $variantsIblockId, array $parentIds): array
    {
        if ($variantsIblockId <= 0 || empty($parentIds)) {
            return [];
        }

        $variants = [];
        $res = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            [
                'IBLOCK_ID' => $variantsIblockId,
                'ACTIVE' => 'Y',
                'PROPERTY_CML2_LINK' => $parentIds,
            ],
            false,
            false,
            ['ID', 'NAME', 'CODE', 'PREVIEW_TEXT', 'IBLOCK_ID', 'TIMESTAMP_X', 'MODIFIED_BY', 'PROPERTY_CML2_LINK']
        );

        while ($fields = $res->Fetch()) {
            $parentId = !empty($fields['PROPERTY_CML2_LINK_VALUE'])
                ? (int)$fields['PROPERTY_CML2_LINK_VALUE']
                : 0;

            $variants[] = [
                'type' => 'child',
                'id' => (int)$fields['ID'],
                'name' => $fields['NAME'] ?? '',
                'code' => $fields['CODE'] ?? '',
                'description' => trim(strip_tags((string)($fields['PREVIEW_TEXT'] ?? ''))),
                'iblockId' => $variantsIblockId,
                'parentId' => $parentId,
                'timestampX' => $fields['TIMESTAMP_X'] ?? null,
                'modifiedBy' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
                'timestamp_x' => $fields['TIMESTAMP_X'] ?? null,
                'modified_by' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
            ];
        }

        return $variants;
    }

    /**
     * Собирает дерево из разделов и элементов
     *
     * @param array $sections Массив разделов
     * @param array $elements Массив элементов
     * @return array
     */
    private function assembleTree(array $sections, array $elements): array
    {
        // Распределяем элементы по разделам
        $sectionElements = [];
        $rootElements = [];

        foreach ($elements as $element) {
            $sectionId = $element['sectionId'];
            if ($sectionId > 0) {
                if (!isset($sectionElements[$sectionId])) {
                    $sectionElements[$sectionId] = [];
                }
                $sectionElements[$sectionId][] = $element;
            } else {
                $rootElements[] = $element;
            }
        }

        // Функция для построения дерева рекурсивно
        $buildTree = function ($parentId) use (&$buildTree, &$sections, &$sectionElements) {
            $result = [];

            foreach ($sections as $section) {
                if ($section['parentId'] === $parentId) {
                    $sectionNode = $section;
                    
                    // Добавляем дочерние разделы
                    $children = $buildTree($section['id']);
                    
                    // Добавляем элементы текущего раздела
                    if (!empty($sectionElements[$section['id']])) {
                        foreach ($sectionElements[$section['id']] as $element) {
                            $children[] = $element;
                        }
                    }
                    
                    if (!empty($children)) {
                        $sectionNode['children'] = $children;
                    }
                    
                    $result[] = $sectionNode;
                }
            }

            return $result;
        };

        $tree = $buildTree(null);

        // Добавляем элементы без раздела в конец
        if (!empty($rootElements)) {
            $tree = array_merge($tree, $rootElements);
        }

        return $tree;
    }

    /**
     * Получить список типов цен из каталога Bitrix
     *
     * @return array
     */
    private function getPriceTypes(): array
    {
        $priceTypes = [];

        if ($this->pinnedRuntimeIblockIds !== null) {
            $rows = Application::getConnection()->query(
                'SELECT ID, NAME, BASE, SORT FROM b_catalog_group ORDER BY SORT, ID'
            );
            while (is_object($rows) && method_exists($rows, 'fetch') && ($type = $rows->fetch())) {
                $priceTypes[] = [
                    'id' => (int)($type['ID'] ?? $type['id'] ?? 0),
                    'name' => (string)($type['NAME'] ?? $type['name'] ?? ''),
                    'base' => (string)($type['BASE'] ?? $type['base'] ?? 'N') === 'Y',
                    'sort' => (int)($type['SORT'] ?? $type['sort'] ?? 100),
                ];
            }
            return $priceTypes;
        }

        // Проверяем, что модуль catalog загружен
        if (!Loader::includeModule('catalog')) {
            return $priceTypes;
        }

        try {
            $result = \CCatalogGroup::GetListArray();
            
            if (is_array($result)) {
                foreach ($result as $type) {
                    $priceTypes[] = [
                        'id' => (int)$type['ID'],
                        'name' => $type['NAME'] ?? '',
                        'base' => ($type['BASE'] ?? 'N') === 'Y',
                        'sort' => (int)($type['SORT'] ?? 100),
                    ];
                }
            }
        } catch (\Exception $e) {
            // В случае ошибки возвращаем пустой массив
            return [];
        }

        return $priceTypes;
    }

    /**
     * Собирает "соседние" варианты операций/материалов для всех этапов пресета
     * 
     * @param array|null $preset Данные пресета
     * @return array Массив соседних вариантов по этапам
     */
    private function buildElementsSiblings(?array $preset): array
    {
        if (!$preset || empty($preset['properties']['CALC_STAGES'])) {
            return [];
        }
        
        $stageIds = $preset['properties']['CALC_STAGES'];
        $operationsIblockId = $this->runtimeIblockId('CALC_OPERATIONS');
        $operationsVariantsIblockId = $this->runtimeIblockId('CALC_OPERATIONS_VARIANTS');
        $materialsIblockId = $this->runtimeIblockId('CALC_MATERIALS');
        $materialsVariantsIblockId = $this->runtimeIblockId('CALC_MATERIALS_VARIANTS');
        
        $result = [];
        
        foreach ($stageIds as $stageId) {
            $stageId = (int)$stageId;
            if ($stageId <= 0) continue;
            
            $siblings = [
                'stageId' => $stageId,
                'CALC_OPERATIONS_VARIANTS' => [],
                'CALC_MATERIALS_VARIANTS' => [],
            ];
            
            $operationVariantsSelected = $this->getStageSelectedVariants(
                $stageId,
                'OPERATION_VARIANT',
                $operationsVariantsIblockId
            );
            $materialVariantsSelected = $this->getStageSelectedVariants(
                $stageId,
                'MATERIAL_VARIANT',
                $materialsVariantsIblockId
            );

            $operationReason = null;
            if (empty($operationVariantsSelected)) {
                $operationReason = 'Нет выбранных ТП в этапе ' . $stageId;
            }

            $materialReason = null;
            if (empty($materialVariantsSelected)) {
                $materialReason = 'Нет выбранных ТП в этапе ' . $stageId;
            }

            $operationParentIds = $this->collectParentIdsFromOffers($operationVariantsSelected);
            $materialParentIds = $this->collectParentIdsFromOffers($materialVariantsSelected);

            if ($operationReason === null && empty($operationParentIds)) {
                $operationReason = 'Не найден parentId у выбранных ТП';
            }

            if ($materialReason === null && empty($materialParentIds)) {
                $materialReason = 'Не найден parentId у выбранных ТП';
            }

            $operationSiblingIds = $this->loadSiblingOfferIds(
                $operationsIblockId,
                $operationParentIds
            );
            $materialSiblingIds = $this->loadSiblingOfferIds(
                $materialsIblockId,
                $materialParentIds
            );

            if ($operationReason === null && empty($operationSiblingIds)) {
                $operationReason = 'Не найдены соседи для parentId (проверьте SKU‑связь/инфоблок)';
            }

            if ($materialReason === null && empty($materialSiblingIds)) {
                $materialReason = 'Не найдены соседи для parentId (проверьте SKU‑связь/инфоблок)';
            }

            if (!empty($operationSiblingIds)) {
                $siblings['CALC_OPERATIONS_VARIANTS'] = $this->loadOfferElements(
                    $operationsVariantsIblockId,
                    $operationSiblingIds
                );
            }

            if (!empty($materialSiblingIds)) {
                $siblings['CALC_MATERIALS_VARIANTS'] = $this->loadOfferElements(
                    $materialsVariantsIblockId,
                    $materialSiblingIds
                );
            }

            if ($operationReason !== null) {
                $siblings['CALC_OPERATIONS_VARIANTS_REASON'] = $operationReason;
            }

            if ($materialReason !== null) {
                $siblings['CALC_MATERIALS_VARIANTS_REASON'] = $materialReason;
            }
            
            $result[] = $siblings;
        }
        
        return $result;
    }

    /**
     * Получить выбранные варианты для этапа.
     */
    private function getStageSelectedVariants(int $stageId, string $propertyCode, int $offersIblockId): array
    {
        $stageData = $this->elementsStore[$stageId] ?? null;
        if (is_array($stageData) && isset($stageData[$propertyCode])) {
            return $this->normalizeVariantsFromStore($stageData[$propertyCode], $offersIblockId);
        }

        $stageFromStore = $this->findStageInStore($stageId);
        if ($stageFromStore === null) {
            return [];
        }
        
        $properties = $stageFromStore['properties'] ?? [];
        if (!isset($properties[$propertyCode])) {
            return [];
        }

        return $this->normalizeVariantsFromStore($properties[$propertyCode]['VALUE'] ?? [], $offersIblockId);
    }

    private function normalizeVariantsFromStore($value, int $offersIblockId): array
    {
        if (is_array($value) && isset($value[0]) && is_array($value[0]) && isset($value[0]['id'])) {
            return $value;
        }

        $ids = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                $itemId = (int)$item;
                if ($itemId > 0) {
                    $ids[] = $itemId;
                }
            }
        } else {
            $itemId = (int)$value;
            if ($itemId > 0) {
                $ids[] = $itemId;
            }
        }

        if (empty($ids) || $offersIblockId <= 0) {
            return [];
        }

        return $this->loadOfferElements($offersIblockId, $ids);
    }

    /**
     * Резервный сбор parentId из списка элементов.
     */
    private function collectParentIdsFromStore(array $elements): array
    {
        $parentIds = [];

        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $parentId = (int)($element['productId'] ?? 0);
            if ($parentId <= 0) {
                $parentId = (int)($element['id'] ?? 0);
            }

            if ($parentId > 0) {
                $parentIds[] = $parentId;
            }
        }

        return array_values(array_unique($parentIds));
    }

    private function findStageInStore(int $stageId): ?array
    {
        foreach ($this->elementsStore['CALC_STAGES'] ?? [] as $stage) {
            if ((int)($stage['id'] ?? 0) === $stageId) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Получить список родительских элементов для выбранных ТП.
     */
    private function collectParentIdsFromOffers(array $offers): array
    {
        $parentIds = [];

        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }

            $parentId = $this->getParentIdFromOffer($offer);
            if ($parentId > 0) {
                $parentIds[] = $parentId;
            }
        }

        return array_values(array_unique($parentIds));
    }

    /**
     * Получить parentId из элемента ТП.
     */
    private function getParentIdFromOffer(array $offer): int
    {
        $parentId = (int)($offer['productId'] ?? 0);
        if ($parentId > 0) {
            return $parentId;
        }

        $properties = $offer['properties'] ?? [];
        if (isset($properties['CML2_LINK']['VALUE'])) {
            $parentId = (int)$properties['CML2_LINK']['VALUE'];
            if ($parentId > 0) {
                return $parentId;
            }
        }

        if (isset($properties['CML2_LINK']['VALUE_ID'])) {
            $parentId = (int)$properties['CML2_LINK']['VALUE_ID'];
            if ($parentId > 0) {
                return $parentId;
            }
        }

        $fields = $offer['fields'] ?? [];
        if (isset($fields['CML2_LINK'])) {
            $parentId = (int)$fields['CML2_LINK'];
            if ($parentId > 0) {
                return $parentId;
            }
        }

        return 0;
    }

    /**
     * Получить список offerId для parentId (объединенный).
     */
    private function loadSiblingOfferIds(int $productIblockId, array $parentIds): array
    {
        if ($productIblockId <= 0 || empty($parentIds)) {
            return [];
        }

        $offersData = \CCatalogSKU::getOffersList(
            $parentIds,
            $productIblockId,
            [],
            ['ID'],
            ['ID']
        );

        $offerIds = [];
        if (is_array($offersData)) {
            foreach ($offersData as $offersByParent) {
                foreach ($offersByParent as $offer) {
                    $offerId = (int)($offer['ID'] ?? 0);
                    if ($offerId > 0) {
                        $offerIds[] = $offerId;
                    }
                }
            }
        }

        return array_values(array_unique($offerIds));
    }

    /**
     * Загрузить элементы ТП в формате elementsStore.
     */
    private function loadOfferElements(int $offersIblockId, array $offerIds): array
    {
        if ($offersIblockId <= 0 || empty($offerIds)) {
            return [];
        }
        
        $elementDataService = $this->elementDataService();
        
        // Загружаем данные через ElementDataService (формат как в elementsStore)
        $payload = $elementDataService->prepareRefreshPayload([
            [
                'iblockId' => $offersIblockId,
                'iblockType' => null,
                'ids' => $offerIds,
                'includeParent' => false,
            ],
        ]);
        
        return $payload[0]['data'] ?? [];
    }
}
