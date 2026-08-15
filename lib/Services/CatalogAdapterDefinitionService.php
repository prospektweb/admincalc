<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Application;

/**
 * Preset-owned, versioned catalog adapter for the standalone preset 12740.
 *
 * Catalog entities are an optional launch/write envelope. Input mappings may
 * only produce declared calculation inputs; output mappings may only project
 * calculation results to the explicitly supported Bitrix catalog sinks.
 */
final class CatalogAdapterDefinitionService
{
    public const CONTRACT = 'prospektweb.calc.catalog-adapter/v1';
    public const EDITOR_RUNTIME_CONTRACT = 'prospektweb.calc.editor-runtime/v1';
    public const LAUNCH_CONTEXT_CONTRACT = 'prospektweb.calc.launch-context/v1';
    public const SCENARIO_CONTRACT = 'prospektweb.calc.catalog-scenario/v1';
    public const PRESET_ID = 12740;

    private const MODULE_ID = 'prospektweb.calc';
    private const OPTION_PREFIX = 'CATALOG_ADAPTER_';
    private const MAX_DEFINITION_BYTES = 65536;
    private const MAX_MAPPINGS = 32;
    private const MAX_PROFILES = 50;
    private const LOCK_TIMEOUT_SECONDS = 5.0;

    /** @var string[] */
    private const INPUT_TARGETS = [
        'calculation.inputs.CALC_PROP_METHOD',
        'calculation.inputs.CALC_PROP_TYPE_PAPER',
        'calculation.inputs.CALC_PROP_FORMAT',
        'calculation.inputs.CALC_PROP_DENSITY_PAPER',
        'calculation.inputs.CALC_PROP_FILLING',
        'calculation.inputs.CALC_PROP_COLOR_SCHEME',
        'calculation.inputs.CALC_PROP_VOLUME',
        'calculation.inputs.CALC_PROP_OPTIONS',
        'calculation.inputs.CALC_PROP_PROTECTION',
        'calculation.inputs.CALC_PROP_LAMINATION',
        'calculation.inputs.CALC_PROP_LAMINATION_SIDES',
    ];

    /** @var string[] */
    private const REQUIRED_INPUT_TARGETS = [
        'calculation.inputs.CALC_PROP_METHOD',
        'calculation.inputs.CALC_PROP_TYPE_PAPER',
        'calculation.inputs.CALC_PROP_FORMAT',
        'calculation.inputs.CALC_PROP_DENSITY_PAPER',
        'calculation.inputs.CALC_PROP_FILLING',
        'calculation.inputs.CALC_PROP_COLOR_SCHEME',
        'calculation.inputs.CALC_PROP_VOLUME',
    ];

    /** @var string[] */
    private const INPUT_SOURCES = [
        'literal',
        'catalog.offer.properties.CALC_PROP_COLOR_SCHEME.xmlId',
        'catalog.offer.properties.CALC_PROP_VOLUME.xmlId',
    ];

    /** @var array<string,string> */
    private const OUTPUT_PAIRS = [
        'result.purchasePrice' => 'catalog.offer.purchasingPrice',
        'result.priceTypes' => 'catalog.offer.priceTypes',
        'result.dimensions.weight' => 'catalog.offer.weight',
        'result.dimensions.length' => 'catalog.offer.length',
        'result.dimensions.width' => 'catalog.offer.width',
        'result.dimensions.height' => 'catalog.offer.height',
        'runtime.provenance' => 'catalog.offer.provenance',
    ];

    /** @var array<string,callable> */
    private array $adapters;

    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /** @return array<string,mixed> */
    public function load(int $presetId = self::PRESET_ID): array
    {
        $this->assertPresetId($presetId);
        $raw = $this->getRaw($presetId);
        return $this->loadFromRaw($presetId, $raw);
    }

    /**
     * Validate an explicitly read option value without consulting Bitrix's
     * process-level Option cache. Catalog writers use this after SELECT ...
     * FOR UPDATE on the exact b_option row.
     *
     * @return array<string,mixed>
     */
    public function loadFromRaw(int $presetId, string $raw): array
    {
        $this->assertPresetId($presetId);
        if ($raw === '') {
            return $this->withRevision($this->normalizeDefinition($this->defaultDocument()));
        }
        if (strlen($raw) > self::MAX_DEFINITION_BYTES) {
            throw new \RuntimeException('Хранилище адаптера каталога превышает допустимый размер.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Хранилище адаптера каталога повреждено.');
        }
        $storedRevision = trim((string)($decoded['revision'] ?? ''));
        unset($decoded['revision']);
        $normalized = $this->normalizeDefinition($decoded);
        $actualRevision = $this->revisionFor($normalized);
        if (preg_match('/^[a-f0-9]{64}$/D', $storedRevision) !== 1
            || !hash_equals($actualRevision, $storedRevision)) {
            throw new \RuntimeException('Контрольная ревизия адаптера каталога не совпадает с содержимым.');
        }
        $normalized['revision'] = $actualRevision;
        return $normalized;
    }

    /**
     * Normalize an untrusted authoring candidate without reading or writing
     * the option store. Transactional callers can therefore resolve catalog
     * targets against the candidate before performing the locked CAS.
     *
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    public function normalizeCandidate(int $presetId, array $definition): array
    {
        $this->assertPresetId($presetId);
        unset($definition['revision']);
        return $this->withRevision($this->normalizeDefinition($definition));
    }

    /**
     * Validate a CAS update against a raw option value already protected by
     * the caller's transaction. No Option cache or write is performed here.
     *
     * @param array<string,mixed> $definition
     * @return array{definition:array<string,mixed>,raw:string}
     */
    public function prepareSaveFromRaw(
        int $presetId,
        string $expectedRevision,
        array $definition,
        string $currentRaw
    ): array {
        $this->assertPresetId($presetId);
        $this->assertRevision($expectedRevision);
        $current = $this->loadFromRaw($presetId, $currentRaw);
        if (!hash_equals((string)$current['revision'], $expectedRevision)) {
            throw new \RuntimeException('Catalog adapter changed concurrently; reload before saving.', 409);
        }
        $stored = $this->normalizeCandidate($presetId, $definition);
        $encoded = self::encodeCanonical($stored);
        if (strlen($encoded) > self::MAX_DEFINITION_BYTES) {
            throw new \InvalidArgumentException('Catalog adapter exceeds the maximum stored size.');
        }
        return ['definition' => $stored, 'raw' => $encoded];
    }

    /**
     * Compare-and-swap save. The absent store is represented by the immutable
     * revision of defaultDocument(), so the first edit is protected as well.
     *
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    public function save(int $presetId, string $expectedRevision, array $definition): array
    {
        $this->assertPresetId($presetId);
        $this->assertRevision($expectedRevision);

        return $this->withLock($presetId, function () use ($presetId, $expectedRevision, $definition): array {
            if (!isset($this->adapters['get_option']) && !isset($this->adapters['set_option'])) {
                return $this->saveDirectUnderLock($presetId, $expectedRevision, $definition);
            }
            $current = $this->load($presetId);
            if (!hash_equals((string)$current['revision'], $expectedRevision)) {
                throw new \RuntimeException(
                    'Адаптер каталога уже изменён в другой вкладке. Обновите данные перед сохранением.',
                    409
                );
            }
            unset($definition['revision']);
            $normalized = $this->normalizeDefinition($definition);
            $stored = $this->withRevision($normalized);
            $encoded = self::encodeCanonical($stored);
            if (strlen($encoded) > self::MAX_DEFINITION_BYTES) {
                throw new \InvalidArgumentException('Адаптер каталога превышает допустимый размер.');
            }
            $this->setRaw($presetId, $encoded);
            $readBack = $this->load($presetId);
            if (!hash_equals((string)$stored['revision'], (string)$readBack['revision'])) {
                throw new \RuntimeException('Не удалось подтвердить сохранение адаптера каталога.');
            }
            return $readBack;
        });
    }

    /**
     * Coordinate a result write with the same lock used by adapter CAS saves.
     * This closes the interval between revision validation and catalog commit.
     *
     * @return mixed
     */
    public function withMutationLock(int $presetId, callable $callback)
    {
        $this->assertPresetId($presetId);
        return $this->withLock($presetId, $callback);
    }

    /** @return int[] */
    public function supportedProductIds(?array $definition = null): array
    {
        $definition = $definition === null ? $this->load() : $this->normalizeLoadedDefinition($definition);
        $ids = array_map(static fn(array $profile): int => (int)$profile['productId'], $definition['productProfiles']);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * Execute the input adapter without form-authoring concerns.
     *
     * @param array<string,mixed> $offer
     * @return array{calculationInputs:array<string,mixed>,quantity:int,productId:int,offerId:int,offerName:string,adapterRevision:string}
     */
    public function mapOffer(array $offer, ?array $definition = null): array
    {
        $definition = $definition === null ? $this->load() : $this->normalizeLoadedDefinition($definition);
        $offerId = (int)($offer['id'] ?? 0);
        $properties = is_array($offer['properties'] ?? null) ? $offer['properties'] : [];
        $productId = (int)($offer['productId'] ?? 0);
        if ($productId <= 0) {
            $productId = (int)$this->singleValue($properties['CML2_LINK'] ?? null);
        }
        if ($offerId <= 0 || $productId <= 0) {
            throw new \InvalidArgumentException('Целевое торговое предложение не связано с товаром.');
        }

        $profile = null;
        foreach ($definition['productProfiles'] as $candidate) {
            if ((int)$candidate['productId'] === $productId) {
                $profile = $candidate;
                break;
            }
        }
        if (!is_array($profile)) {
            throw new \InvalidArgumentException(
                'Для товара #' . $productId . ' не настроен профиль автономной записи пресета 12740.'
            );
        }

        $inputsByTarget = [];
        foreach ($definition['inputMappings'] as $mapping) {
            $sourcePath = (string)$mapping['sourcePath'];
            $value = $sourcePath === 'literal'
                ? $mapping['value']
                : $this->readOfferSource($offer, $sourcePath);
            if ($value === '' || $value === null || $value === []) {
                throw new \InvalidArgumentException(
                    'ТП #' . $offerId . ': источник ' . $sourcePath . ' не содержит значения.'
                );
            }
            $inputsByTarget[(string)$mapping['targetPath']] = $value;
        }
        foreach ($profile['overrides'] as $override) {
            $inputsByTarget[(string)$override['targetPath']] = $override['value'];
        }

        $calculationInputs = [];
        foreach ($inputsByTarget as $targetPath => $value) {
            $propertyCode = substr($targetPath, strlen('calculation.inputs.'));
            $calculationInputs[$propertyCode] = $value;
        }
        foreach (self::REQUIRED_INPUT_TARGETS as $requiredTarget) {
            $propertyCode = substr($requiredTarget, strlen('calculation.inputs.'));
            if (!array_key_exists($propertyCode, $calculationInputs)
                || $calculationInputs[$propertyCode] === ''
                || $calculationInputs[$propertyCode] === []) {
                throw new \InvalidArgumentException(
                    'ТП #' . $offerId . ': не сопоставлен обязательный вход ' . $propertyCode . '.'
                );
            }
        }
        $quantityText = trim((string)$calculationInputs['CALC_PROP_VOLUME']);
        if (preg_match('/^[1-9][0-9]*$/D', $quantityText) !== 1) {
            throw new \InvalidArgumentException(
                'У ТП #' . $offerId . ' не задан корректный тираж для выбора целевого результата.'
            );
        }

        return [
            'calculationInputs' => $calculationInputs,
            'quantity' => (int)$quantityText,
            'productId' => $productId,
            'offerId' => $offerId,
            'offerName' => trim((string)($offer['name'] ?? '')),
            'adapterRevision' => (string)$definition['revision'],
        ];
    }

    /**
     * Project canonical calculation inputs to stable semantic form field IDs
     * through the inverse of the published BindingDefinition.
     *
     * @param array<string,mixed> $offer
     * @param array<string,mixed> $formDefinition
     * @param array<string,mixed> $bindingDefinition
     * @param array<string,mixed> $publication
     * @return array<string,mixed>
     */
    public function buildScenario(
        array $offer,
        array $formDefinition,
        array $bindingDefinition,
        array $publication,
        ?array $definition = null
    ): array {
        $formDefinition = $this->deepArray($formDefinition);
        $bindingDefinition = $this->deepArray($bindingDefinition);
        $publication = $this->deepArray($publication);
        $this->assertPublishedAuthoring($formDefinition, $bindingDefinition, $publication);
        $mapped = $this->mapOffer($offer, $definition);
        $definition = $definition === null ? $this->load() : $this->normalizeLoadedDefinition($definition);
        $this->assertAuthoringCoverage($formDefinition, $bindingDefinition, $definition);
        $fieldIds = [];
        foreach ($formDefinition['fields'] as $field) {
            $fieldId = trim((string)($field['fieldId'] ?? ''));
            if ($fieldId === '' || isset($fieldIds[$fieldId])) {
                throw new \RuntimeException('Опубликованный FormDefinition содержит пустой или повторный fieldId.');
            }
            $fieldIds[$fieldId] = true;
        }
        $values = $this->buildSemanticValues(
            $mapped,
            $formDefinition,
            $bindingDefinition,
            $publication
        );
        foreach ($values as $fieldId => $_value) {
            if (!is_string($fieldId) || !isset($fieldIds[$fieldId]) || strpos($fieldId, 'CALC_PROP_') === 0) {
                throw new \RuntimeException('Адаптер вернул значение вне опубликованной семантической формы.');
            }
        }
        ksort($values, SORT_STRING);

        return [
            'contract' => self::SCENARIO_CONTRACT,
            'scenarioId' => 'offer:' . $mapped['offerId'],
            'presetId' => self::PRESET_ID,
            'publicationRevision' => (int)($publication['revision'] ?? 0),
            'publicationCompileHash' => (string)($publication['compileHash'] ?? ''),
            'adapterRevision' => $mapped['adapterRevision'],
            'target' => [
                'productId' => $mapped['productId'],
                'offerId' => $mapped['offerId'],
                'name' => $mapped['offerName'] !== ''
                    ? $mapped['offerName']
                    : ('ТП #' . $mapped['offerId']),
            ],
            'values' => $values,
        ];
    }

    /** @return array<string,mixed> */
    public function previewMappings(
        array $offers,
        array $formDefinition,
        array $bindingDefinition,
        array $publication,
        ?array $definition = null
    ): array {
        $definition = $definition === null ? $this->load() : $this->normalizeLoadedDefinition($definition);
        $formDefinition = $this->deepArray($formDefinition);
        $bindingDefinition = $this->deepArray($bindingDefinition);
        $publication = $this->deepArray($publication);
        $this->assertPublishedAuthoring($formDefinition, $bindingDefinition, $publication);
        $this->assertAuthoringCoverage($formDefinition, $bindingDefinition, $definition);
        $scenarios = [];
        $errors = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                $errors[] = ['offerId' => 0, 'message' => 'Получена некорректная запись ТП.'];
                continue;
            }
            try {
                $scenarios[] = $this->buildScenario(
                    $offer,
                    $formDefinition,
                    $bindingDefinition,
                    $publication,
                    $definition
                );
            } catch (\Throwable $error) {
                $errors[] = [
                    'offerId' => (int)($offer['id'] ?? 0),
                    'message' => $error->getMessage(),
                ];
            }
        }
        return [
            'ready' => count($scenarios) === count($offers) && $errors === [],
            'hasTargets' => $offers !== [],
            'adapterRevision' => (string)$definition['revision'],
            'scenarios' => $scenarios,
            'errors' => $errors,
        ];
    }

    /**
     * Remove every write-capable result path that is not explicitly declared
     * by the adapter. Price type IDs are additionally intersected with the
     * server-provided Bitrix price type catalog.
     *
     * @param array<int,array<string,mixed>> $offerResults
     * @param array<int,array<string,mixed>> $priceTypes
     * @param array<string,mixed>|null $publication
     * @return array<int,array<string,mixed>>
     */
    public function projectResultsForWrite(
        array $offerResults,
        array $priceTypes,
        ?array $definition = null,
        ?array $publication = null,
        bool $definitionIsPinned = false
    ): array {
        if ($definition === null) {
            $definition = $this->load();
        } else {
            $definition = $this->normalizeLoadedDefinition($definition);
            $current = $definitionIsPinned ? $definition : $this->load();
            if (!hash_equals((string)$current['revision'], (string)$definition['revision'])) {
                throw new \RuntimeException(
                    'Ревизия адаптера изменилась во время расчёта; результат не будет записан.',
                    409
                );
            }
        }
        if ($publication !== null
            && ((int)($publication['revision'] ?? 0) <= 0
                || preg_match('/^[a-f0-9]{64}$/D', (string)($publication['compileHash'] ?? '')) !== 1)) {
            throw new \RuntimeException('Результат не содержит подтверждённую публикацию формы пресета 12740.');
        }
        $enabledTargets = [];
        foreach ($definition['outputMappings'] as $mapping) {
            $enabledTargets[(string)$mapping['targetPath']] = true;
        }
        $allowedPriceTypes = [];
        foreach ($priceTypes as $priceType) {
            $typeId = is_array($priceType) ? (int)($priceType['id'] ?? $priceType['ID'] ?? 0) : 0;
            if ($typeId > 0) {
                $allowedPriceTypes[$typeId] = true;
            }
        }

        $projectedResults = [];
        foreach ($offerResults as $result) {
            if (!is_array($result)) {
                throw new \RuntimeException('calc-server вернул некорректный результат адаптера каталога.');
            }
            $currency = strtoupper(trim((string)($result['currency'] ?? 'RUB')));
            if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new \RuntimeException('calc-server вернул некорректную валюту результата адаптера каталога.');
            }
            $projected = [
                'offerId' => (int)($result['offerId'] ?? 0),
                'currency' => $currency,
                'catalogAdapterProvenance' => [
                    'contract' => self::CONTRACT,
                    'presetId' => self::PRESET_ID,
                    'revision' => (string)$definition['revision'],
                ],
            ];
            if ($publication !== null) {
                $projected['catalogAdapterProvenance']['publicationRevision'] = (int)$publication['revision'];
                $projected['catalogAdapterProvenance']['publicationCompileHash'] = (string)$publication['compileHash'];
            }
            if (isset($enabledTargets['catalog.offer.purchasingPrice'])) {
                $projected['purchasePrice'] = $result['purchasePrice'] ?? $result['purchase_price'] ?? null;
            }
            if (isset($enabledTargets['catalog.offer.priceTypes'])) {
                $ranges = [];
                foreach (is_array($result['priceRangesWithMarkup'] ?? null) ? $result['priceRangesWithMarkup'] : [] as $range) {
                    if (!is_array($range)) {
                        continue;
                    }
                    $prices = [];
                    foreach (is_array($range['prices'] ?? null) ? $range['prices'] : [] as $price) {
                        $typeId = is_array($price) ? (int)($price['typeId'] ?? 0) : 0;
                        if ($typeId > 0 && isset($allowedPriceTypes[$typeId])) {
                            $priceCurrency = strtoupper(trim((string)($price['currency'] ?? $currency)));
                            if (preg_match('/^[A-Z]{3}$/D', $priceCurrency) !== 1) {
                                throw new \RuntimeException('calc-server вернул некорректную валюту цены адаптера каталога.');
                            }
                            $prices[] = [
                                'typeId' => $typeId,
                                'basePrice' => $price['basePrice'] ?? null,
                                'currency' => $priceCurrency,
                            ];
                        }
                    }
                    if ($prices !== []) {
                        $ranges[] = [
                            'quantityFrom' => $range['quantityFrom'] ?? null,
                            'quantityTo' => $range['quantityTo'] ?? null,
                            'prices' => $prices,
                        ];
                    }
                }
                $projected['priceRangesWithMarkup'] = $ranges;
            }

            $detail = is_array($result['details'][0] ?? null) ? $result['details'][0] : [];
            $outputs = is_array($detail['outputs'] ?? null) ? $detail['outputs'] : [];
            $dimensionOutputs = [];
            foreach (['weight', 'length', 'width', 'height'] as $dimension) {
                if (!isset($enabledTargets['catalog.offer.' . $dimension])) {
                    continue;
                }
                $dimensionOutputs[$dimension] = $outputs[$dimension] ?? $detail[$dimension] ?? null;
            }
            $projected['details'] = [['outputs' => $dimensionOutputs]];
            $projectedResults[] = $projected;
        }
        return $projectedResults;
    }

    /**
     * Project against a definition read from the locked raw option row. The
     * caller owns freshness/CAS, so this path must not consult Option cache.
     *
     * @param array<int,array<string,mixed>> $offerResults
     * @param array<int,array<string,mixed>> $priceTypes
     * @param array<string,mixed> $definition
     * @param array<string,mixed>|null $publication
     * @return array<int,array<string,mixed>>
     */
    public function projectPinnedResultsForWrite(
        array $offerResults,
        array $priceTypes,
        array $definition,
        ?array $publication = null
    ): array {
        return $this->projectResultsForWrite(
            $offerResults,
            $priceTypes,
            $definition,
            $publication,
            true
        );
    }

    /**
     * Reuse FrontCalc's published binding semantics instead of treating
     * CALC_PROP_* XML_IDs as FormValues. In particular, this converts enum
     * XML_IDs to semantic option IDs, dimensions to objects, and numeric
     * fields to numbers.
     *
     * @param array<string,mixed> $mapped
     * @return array<string,mixed>
     */
    private function buildSemanticValues(
        array $mapped,
        array $formDefinition,
        array $bindingDefinition,
        array $publication
    ): array {
        if (isset($this->adapters['semantic_values'])) {
            $values = call_user_func(
                $this->adapters['semantic_values'],
                $mapped,
                $formDefinition,
                $bindingDefinition,
                $publication
            );
            if (!is_array($values)) {
                throw new \RuntimeException('Преобразователь адаптера не вернул FormValues.');
            }
            return $values;
        }

        $builderClass = '\\Prospektweb\\Frontcalc\\Service\\NeutralCalculationInputBuilder';
        if (!class_exists($builderClass)) {
            throw new \RuntimeException('FrontCalc не предоставляет преобразователь опубликованных FormValues.');
        }
        $properties = [];
        foreach ($mapped['calculationInputs'] as $propertyCode => $value) {
            $properties[$propertyCode] = [
                'VALUE' => $value,
                '~VALUE' => $value,
                'VALUE_XML_ID' => $value,
            ];
        }
        $decorated = (new $builderClass())->decorateOffers(
            [[
                'id' => (int)$mapped['offerId'],
                'productId' => (int)$mapped['productId'],
                'properties' => $properties,
            ]],
            [
                'formDefinition' => $formDefinition,
                'bindingDefinition' => $bindingDefinition,
                'publication' => $publication,
            ],
            self::PRESET_ID
        );
        $calculationInput = is_array($decorated[0]['calculationInput'] ?? null)
            ? $decorated[0]['calculationInput']
            : [];
        if ((string)($calculationInput['contract'] ?? '') !== 'prospektweb.calc.input-context/v1'
            || (string)($calculationInput['source'] ?? '') !== 'manual'
            || !is_array($calculationInput['values'] ?? null)) {
            throw new \RuntimeException('FrontCalc вернул некорректный контракт FormValues.');
        }
        return $calculationInput['values'];
    }

    /**
     * The FrontCalc store already validates the full aggregate and compiled
     * snapshot. The adapter repeats the boundary checks it relies on so a
     * malformed in-process caller cannot bypass fail-closed scenario mapping.
     *
     * @param array<string,mixed> $formDefinition
     * @param array<string,mixed> $bindingDefinition
     * @param array<string,mixed> $publication
     */
    private function assertPublishedAuthoring(
        array $formDefinition,
        array $bindingDefinition,
        array $publication
    ): void {
        if ((string)($formDefinition['contract'] ?? '') !== 'prospektweb.frontcalc.form-definition/v1'
            || (string)($bindingDefinition['contract'] ?? '') !== 'prospektweb.frontcalc.binding-definition/v1'
            || !is_array($formDefinition['fields'] ?? null)
            || !$this->isList($formDefinition['fields'])
            || $formDefinition['fields'] === []
            || !is_array($bindingDefinition['bindings'] ?? null)
            || !$this->isList($bindingDefinition['bindings'])
            || $bindingDefinition['bindings'] === []
            || (int)($publication['revision'] ?? 0) <= 0
            || preg_match('/^[a-f0-9]{64}$/D', (string)($publication['compileHash'] ?? '')) !== 1) {
            throw new \RuntimeException('Опубликованный form-first контракт не прошёл проверку адаптера каталога.');
        }
    }

    /**
     * Every canonical adapter input must have one unambiguous semantic field
     * in the exact published BindingDefinition, even when a standalone launch
     * currently has no catalog scenarios to dry-run.
     *
     * @param array<string,mixed> $formDefinition
     * @param array<string,mixed> $bindingDefinition
     * @param array<string,mixed> $definition
     */
    private function assertAuthoringCoverage(
        array $formDefinition,
        array $bindingDefinition,
        array $definition
    ): void {
        $fieldIds = [];
        foreach ($formDefinition['fields'] as $field) {
            if (!is_array($field)) {
                throw new \RuntimeException('Опубликованный FormDefinition содержит некорректное поле.');
            }
            $fieldId = trim((string)($field['fieldId'] ?? ''));
            if ($fieldId === '' || isset($fieldIds[$fieldId])) {
                throw new \RuntimeException('Опубликованный FormDefinition содержит пустой или повторный fieldId.');
            }
            $fieldIds[$fieldId] = true;
        }

        $propertyToField = [];
        foreach ($bindingDefinition['bindings'] as $binding) {
            if (!is_array($binding)) {
                throw new \RuntimeException('Опубликованный BindingDefinition содержит некорректную привязку.');
            }
            $target = is_array($binding['target'] ?? null) ? $binding['target'] : [];
            if ((string)($target['kind'] ?? '') !== 'property') {
                continue;
            }
            $fieldId = trim((string)($binding['fieldId'] ?? ''));
            $propertyCode = trim((string)($target['propertyCode'] ?? ''));
            if ($fieldId === '' || $propertyCode === '' || !isset($fieldIds[$fieldId])) {
                throw new \RuntimeException('Опубликованный BindingDefinition содержит неполную property-привязку.');
            }
            if (isset($propertyToField[$propertyCode])) {
                throw new \RuntimeException(
                    'Опубликованный BindingDefinition неоднозначно сопоставляет свойство ' . $propertyCode . '.'
                );
            }
            $propertyToField[$propertyCode] = $fieldId;
        }

        $targetPaths = [];
        foreach ($definition['inputMappings'] as $mapping) {
            $targetPaths[(string)$mapping['targetPath']] = true;
        }
        foreach ($definition['productProfiles'] as $profile) {
            foreach ($profile['overrides'] as $override) {
                $targetPaths[(string)$override['targetPath']] = true;
            }
        }
        foreach (array_keys($targetPaths) as $targetPath) {
            $propertyCode = substr($targetPath, strlen('calculation.inputs.'));
            if ($propertyCode === '' || !isset($propertyToField[$propertyCode])) {
                throw new \RuntimeException(
                    'Опубликованный BindingDefinition не сопоставляет вход адаптера ' . $propertyCode . '.'
                );
            }
        }
    }

    /** @return array<string,mixed> */
    private function defaultDocument(): array
    {
        return [
            'contract' => self::CONTRACT,
            'presetId' => self::PRESET_ID,
            'inputMappings' => [
                ['sourcePath' => 'literal', 'targetPath' => 'calculation.inputs.CALC_PROP_METHOD', 'value' => 'DIGITAL'],
                ['sourcePath' => 'literal', 'targetPath' => 'calculation.inputs.CALC_PROP_TYPE_PAPER', 'value' => 'mel-paper'],
                ['sourcePath' => 'literal', 'targetPath' => 'calculation.inputs.CALC_PROP_FORMAT', 'value' => '90x50'],
                ['sourcePath' => 'literal', 'targetPath' => 'calculation.inputs.CALC_PROP_DENSITY_PAPER', 'value' => 'MAX'],
                ['sourcePath' => 'literal', 'targetPath' => 'calculation.inputs.CALC_PROP_FILLING', 'value' => 'standart'],
                [
                    'sourcePath' => 'catalog.offer.properties.CALC_PROP_COLOR_SCHEME.xmlId',
                    'targetPath' => 'calculation.inputs.CALC_PROP_COLOR_SCHEME',
                ],
                [
                    'sourcePath' => 'catalog.offer.properties.CALC_PROP_VOLUME.xmlId',
                    'targetPath' => 'calculation.inputs.CALC_PROP_VOLUME',
                ],
            ],
            'outputMappings' => array_map(
                static fn(string $sourcePath, string $targetPath): array => compact('sourcePath', 'targetPath'),
                array_keys(self::OUTPUT_PAIRS),
                array_values(self::OUTPUT_PAIRS)
            ),
            'productProfiles' => [
                ['productId' => 12727, 'overrides' => []],
                [
                    'productId' => 12764,
                    'overrides' => [[
                        'targetPath' => 'calculation.inputs.CALC_PROP_OPTIONS',
                        'value' => ['round-corners'],
                    ]],
                ],
                ['productId' => 14379, 'overrides' => []],
                ['productId' => 14380, 'overrides' => []],
                [
                    'productId' => 15344,
                    'overrides' => [[
                        'targetPath' => 'calculation.inputs.CALC_PROP_FORMAT',
                        'value' => '85x55',
                    ]],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    private function normalizeLoadedDefinition(array $definition): array
    {
        $revision = trim((string)($definition['revision'] ?? ''));
        unset($definition['revision']);
        $normalized = $this->normalizeDefinition($definition);
        $actualRevision = $this->revisionFor($normalized);
        if ($revision !== '' && !hash_equals($actualRevision, $revision)) {
            throw new \InvalidArgumentException('Передана устаревшая или повреждённая ревизия адаптера каталога.');
        }
        $normalized['revision'] = $actualRevision;
        return $normalized;
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    private function normalizeDefinition(array $definition): array
    {
        $allowedRootKeys = ['contract', 'presetId', 'inputMappings', 'outputMappings', 'productProfiles'];
        $this->assertExactKeys($definition, $allowedRootKeys, 'CatalogAdapterDefinition');
        if ((string)($definition['contract'] ?? '') !== self::CONTRACT) {
            throw new \InvalidArgumentException('Неизвестная версия контракта адаптера каталога.');
        }
        $this->assertPresetId((int)($definition['presetId'] ?? 0));

        $inputMappings = is_array($definition['inputMappings'] ?? null) ? $definition['inputMappings'] : null;
        $outputMappings = is_array($definition['outputMappings'] ?? null) ? $definition['outputMappings'] : null;
        $productProfiles = is_array($definition['productProfiles'] ?? null) ? $definition['productProfiles'] : null;
        if ($inputMappings === null || !$this->isList($inputMappings)
            || count($inputMappings) < 1 || count($inputMappings) > self::MAX_MAPPINGS) {
            throw new \InvalidArgumentException('inputMappings адаптера каталога некорректен.');
        }
        if ($outputMappings === null || !$this->isList($outputMappings)
            || count($outputMappings) < 1 || count($outputMappings) > self::MAX_MAPPINGS) {
            throw new \InvalidArgumentException('outputMappings адаптера каталога некорректен.');
        }
        if ($productProfiles === null || !$this->isList($productProfiles)
            || count($productProfiles) < 1 || count($productProfiles) > self::MAX_PROFILES) {
            throw new \InvalidArgumentException('productProfiles адаптера каталога некорректен.');
        }

        $normalizedInputs = [];
        $seenTargets = [];
        foreach ($inputMappings as $index => $mapping) {
            if (!is_array($mapping)) {
                throw new \InvalidArgumentException('inputMappings[' . $index . '] должен быть объектом.');
            }
            $sourcePath = trim((string)($mapping['sourcePath'] ?? ''));
            $targetPath = trim((string)($mapping['targetPath'] ?? ''));
            if (!in_array($sourcePath, self::INPUT_SOURCES, true)
                || !in_array($targetPath, self::INPUT_TARGETS, true)) {
                throw new \InvalidArgumentException('inputMappings[' . $index . '] использует запрещённый путь.');
            }
            $expectedKeys = $sourcePath === 'literal'
                ? ['sourcePath', 'targetPath', 'value']
                : ['sourcePath', 'targetPath'];
            $this->assertExactKeys($mapping, $expectedKeys, 'inputMappings[' . $index . ']');
            if (isset($seenTargets[$targetPath])) {
                throw new \InvalidArgumentException('inputMappings содержит повторную цель ' . $targetPath . '.');
            }
            $seenTargets[$targetPath] = true;
            $normalized = compact('sourcePath', 'targetPath');
            if ($sourcePath === 'literal') {
                $normalized['value'] = $this->normalizeMappingValue($mapping['value'], 'inputMappings[' . $index . '].value');
            }
            $normalizedInputs[] = $normalized;
        }
        foreach (self::REQUIRED_INPUT_TARGETS as $requiredTarget) {
            if (!isset($seenTargets[$requiredTarget])) {
                throw new \InvalidArgumentException('Не сопоставлен обязательный вход ' . $requiredTarget . '.');
            }
        }
        usort($normalizedInputs, static fn(array $left, array $right): int => strcmp($left['targetPath'], $right['targetPath']));

        $normalizedOutputs = [];
        $seenOutputTargets = [];
        foreach ($outputMappings as $index => $mapping) {
            if (!is_array($mapping)) {
                throw new \InvalidArgumentException('outputMappings[' . $index . '] должен быть объектом.');
            }
            $this->assertExactKeys($mapping, ['sourcePath', 'targetPath'], 'outputMappings[' . $index . ']');
            $sourcePath = trim((string)($mapping['sourcePath'] ?? ''));
            $targetPath = trim((string)($mapping['targetPath'] ?? ''));
            if (!isset(self::OUTPUT_PAIRS[$sourcePath]) || self::OUTPUT_PAIRS[$sourcePath] !== $targetPath) {
                throw new \InvalidArgumentException('outputMappings[' . $index . '] использует запрещённый путь записи.');
            }
            if (isset($seenOutputTargets[$targetPath])) {
                throw new \InvalidArgumentException('outputMappings содержит повторную цель ' . $targetPath . '.');
            }
            $seenOutputTargets[$targetPath] = true;
            $normalizedOutputs[] = compact('sourcePath', 'targetPath');
        }
        foreach (self::OUTPUT_PAIRS as $requiredTarget) {
            if (!isset($seenOutputTargets[$requiredTarget])) {
                throw new \InvalidArgumentException('Не сопоставлен обязательный выход ' . $requiredTarget . '.');
            }
        }
        usort($normalizedOutputs, static fn(array $left, array $right): int => strcmp($left['targetPath'], $right['targetPath']));

        $normalizedProfiles = [];
        $seenProductIds = [];
        foreach ($productProfiles as $index => $profile) {
            if (!is_array($profile)) {
                throw new \InvalidArgumentException('productProfiles[' . $index . '] должен быть объектом.');
            }
            $this->assertExactKeys($profile, ['productId', 'overrides'], 'productProfiles[' . $index . ']');
            $productId = (int)($profile['productId'] ?? 0);
            if ($productId <= 0 || isset($seenProductIds[$productId])) {
                throw new \InvalidArgumentException('productProfiles содержит некорректный или повторный productId.');
            }
            $seenProductIds[$productId] = true;
            $overrides = is_array($profile['overrides'] ?? null) ? $profile['overrides'] : null;
            if ($overrides === null || !$this->isList($overrides) || count($overrides) > self::MAX_MAPPINGS) {
                throw new \InvalidArgumentException('productProfiles[' . $index . '].overrides некорректен.');
            }
            $normalizedOverrides = [];
            $seenOverrideTargets = [];
            foreach ($overrides as $overrideIndex => $override) {
                if (!is_array($override)) {
                    throw new \InvalidArgumentException('Переопределение профиля товара должно быть объектом.');
                }
                $this->assertExactKeys($override, ['targetPath', 'value'], 'productProfiles override');
                $targetPath = trim((string)($override['targetPath'] ?? ''));
                if (!in_array($targetPath, self::INPUT_TARGETS, true) || isset($seenOverrideTargets[$targetPath])) {
                    throw new \InvalidArgumentException('Профиль товара использует запрещённую или повторную цель.');
                }
                $seenOverrideTargets[$targetPath] = true;
                $normalizedOverrides[] = [
                    'targetPath' => $targetPath,
                    'value' => $this->normalizeMappingValue(
                        $override['value'],
                        'productProfiles[' . $index . '].overrides[' . $overrideIndex . '].value'
                    ),
                ];
            }
            usort($normalizedOverrides, static fn(array $left, array $right): int => strcmp($left['targetPath'], $right['targetPath']));
            $normalizedProfiles[] = ['productId' => $productId, 'overrides' => $normalizedOverrides];
        }
        usort($normalizedProfiles, static fn(array $left, array $right): int => $left['productId'] <=> $right['productId']);

        $normalized = [
            'contract' => self::CONTRACT,
            'presetId' => self::PRESET_ID,
            'inputMappings' => $normalizedInputs,
            'outputMappings' => $normalizedOutputs,
            'productProfiles' => $normalizedProfiles,
        ];
        if (strlen(self::encodeCanonical($normalized)) > self::MAX_DEFINITION_BYTES) {
            throw new \InvalidArgumentException('Адаптер каталога превышает допустимый размер.');
        }
        return $normalized;
    }

    /** @param mixed $value @return mixed */
    private function normalizeMappingValue($value, string $path)
    {
        if (is_array($value)) {
            if (!$this->isList($value) || $value === [] || count($value) > 20) {
                throw new \InvalidArgumentException($path . ' должен быть непустым списком не более 20 значений.');
            }
            $normalized = [];
            foreach ($value as $index => $item) {
                $normalized[] = $this->normalizeScalar($item, $path . '[' . $index . ']');
            }
            return $normalized;
        }
        return $this->normalizeScalar($value, $path);
    }

    /** @param mixed $value @return string|int|float|bool */
    private function normalizeScalar($value, string $path)
    {
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            throw new \InvalidArgumentException($path . ' должен быть скалярным значением.');
        }
        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            throw new \InvalidArgumentException($path . ' должен быть конечным числом.');
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || strlen($value) > 120 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new \InvalidArgumentException($path . ' содержит недопустимую строку.');
            }
        }
        return $value;
    }

    /** @param array<string,mixed> $value */
    private function assertExactKeys(array $value, array $expectedKeys, string $path): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys) {
            throw new \InvalidArgumentException($path . ' содержит неизвестные или отсутствующие поля.');
        }
    }

    /** @return mixed */
    private function readOfferSource(array $offer, string $sourcePath)
    {
        $properties = is_array($offer['properties'] ?? null) ? $offer['properties'] : [];
        if ($sourcePath === 'catalog.offer.properties.CALC_PROP_COLOR_SCHEME.xmlId') {
            return $this->singleXmlId($properties['CALC_PROP_COLOR_SCHEME'] ?? null);
        }
        if ($sourcePath === 'catalog.offer.properties.CALC_PROP_VOLUME.xmlId') {
            return $this->singleXmlId($properties['CALC_PROP_VOLUME'] ?? null);
        }
        throw new \InvalidArgumentException('Запрещённый источник адаптера каталога.');
    }

    /** @param mixed $property */
    private function singleXmlId($property): string
    {
        if (!is_array($property)) {
            return '';
        }
        $value = $property['VALUE_XML_ID'] ?? $property['valueXmlId'] ?? $property['xmlId'] ?? '';
        if (is_array($value)) {
            $value = reset($value);
        }
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /** @param mixed $property */
    private function singleValue($property): string
    {
        if (!is_array($property)) {
            return '';
        }
        $value = $property['VALUE'] ?? $property['value'] ?? '';
        if (is_array($value)) {
            $value = reset($value);
        }
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /** @param mixed $value @return mixed */
    private function deepArray($value)
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->deepArray($item);
        }
        return $value;
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function withRevision(array $document): array
    {
        $document['revision'] = $this->revisionFor($document);
        return $document;
    }

    /** @param array<string,mixed> $document */
    private function revisionFor(array $document): string
    {
        unset($document['revision']);
        return hash('sha256', self::encodeCanonical($document));
    }

    /** @param mixed $value */
    public static function encodeCanonical($value): string
    {
        $encoded = json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($encoded)) {
            throw new \RuntimeException('Не удалось сериализовать адаптер каталога.');
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

    private function assertPresetId(int $presetId): void
    {
        if ($presetId !== self::PRESET_ID) {
            throw new \InvalidArgumentException('Адаптер каталога доступен только для пресета 12740.');
        }
    }

    private function assertRevision(string $revision): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new \InvalidArgumentException('Некорректная CAS-ревизия адаптера каталога.');
        }
    }

    private function isList(array $value): bool
    {
        $keys = array_keys($value);
        return $keys === [] || $keys === range(0, count($keys) - 1);
    }

    /**
     * Persist adapter CAS against the global b_option row directly. This runs
     * inside the process mutation lock; the DB transaction provides the
     * cross-process row/gap lock and prevents stale Option cache authority.
     *
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    private function saveDirectUnderLock(
        int $presetId,
        string $expectedRevision,
        array $definition
    ): array {
        if (!class_exists(Application::class)) {
            throw new \RuntimeException('Bitrix database connection is unavailable for adapter CAS.');
        }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $name = self::OPTION_PREFIX . $presetId;
        $escapedName = $helper->forSql($name);
        $connection->startTransaction();
        try {
            $selectSql = "SELECT VALUE FROM b_option WHERE MODULE_ID='" . self::MODULE_ID
                . "' AND NAME='" . $escapedName
                . "' AND (SITE_ID IS NULL OR SITE_ID='') FOR UPDATE";
            $rows = $connection->query($selectSql);
            $row = is_object($rows) && method_exists($rows, 'fetch') ? $rows->fetch() : null;
            $duplicate = is_object($rows) && method_exists($rows, 'fetch') ? $rows->fetch() : null;
            if (is_array($duplicate)) {
                throw new \RuntimeException('Duplicate global catalog adapter option row.', 409);
            }
            $raw = is_array($row) ? (string)($row['VALUE'] ?? $row['value'] ?? '') : '';
            $current = $this->loadFromRaw($presetId, $raw);
            if (!hash_equals((string)$current['revision'], $expectedRevision)) {
                throw new \RuntimeException('Catalog adapter changed concurrently; reload before saving.', 409);
            }

            unset($definition['revision']);
            $stored = $this->withRevision($this->normalizeDefinition($definition));
            $encoded = self::encodeCanonical($stored);
            if (strlen($encoded) > self::MAX_DEFINITION_BYTES) {
                throw new \InvalidArgumentException('Catalog adapter exceeds the maximum stored size.');
            }
            $escapedEncoded = $helper->forSql($encoded);
            if (is_array($row)) {
                $connection->queryExecute(
                    "UPDATE b_option SET VALUE='" . $escapedEncoded . "' WHERE MODULE_ID='"
                    . self::MODULE_ID . "' AND NAME='" . $escapedName
                    . "' AND (SITE_ID IS NULL OR SITE_ID='')"
                );
            } else {
                $connection->queryExecute(
                    "INSERT INTO b_option (MODULE_ID, NAME, VALUE, SITE_ID) VALUES ('"
                    . self::MODULE_ID . "','" . $escapedName . "','" . $escapedEncoded . "',NULL)"
                );
            }

            $readBackRows = $connection->query($selectSql);
            $readBackRow = is_object($readBackRows) && method_exists($readBackRows, 'fetch')
                ? $readBackRows->fetch()
                : null;
            $readBackDuplicate = is_object($readBackRows) && method_exists($readBackRows, 'fetch')
                ? $readBackRows->fetch()
                : null;
            if (!is_array($readBackRow) || is_array($readBackDuplicate)) {
                throw new \RuntimeException('Catalog adapter direct readback is missing or ambiguous.');
            }
            $readBack = $this->loadFromRaw(
                $presetId,
                (string)($readBackRow['VALUE'] ?? $readBackRow['value'] ?? '')
            );
            if (!hash_equals((string)$stored['revision'], (string)$readBack['revision'])) {
                throw new \RuntimeException('Catalog adapter direct readback revision mismatch.');
            }
            $connection->commitTransaction();
            return $readBack;
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw $error;
        }
    }

    private function getRaw(int $presetId): string
    {
        if (isset($this->adapters['get_option'])) {
            return (string)call_user_func($this->adapters['get_option'], self::OPTION_PREFIX . $presetId, '');
        }
        return class_exists(Option::class)
            ? (string)Option::get(self::MODULE_ID, self::OPTION_PREFIX . $presetId, '')
            : '';
    }

    private function setRaw(int $presetId, string $raw): void
    {
        if (isset($this->adapters['set_option'])) {
            call_user_func($this->adapters['set_option'], self::OPTION_PREFIX . $presetId, $raw);
            return;
        }
        if (!class_exists(Option::class)) {
            throw new \RuntimeException('Bitrix Option недоступен для сохранения адаптера каталога.');
        }
        Option::set(self::MODULE_ID, self::OPTION_PREFIX . $presetId, $raw);
    }

    /** @return mixed */
    private function withLock(int $presetId, callable $callback)
    {
        if (isset($this->adapters['mutation_lock'])) {
            return call_user_func($this->adapters['mutation_lock'], $presetId, $callback);
        }
        $directory = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . '/bitrix/managed_cache';
        if ($directory === '/bitrix/managed_cache' || $directory === '\\bitrix\\managed_cache') {
            $directory = sys_get_temp_dir();
        }
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать каталог блокировки адаптера каталога.');
        }
        $path = rtrim($directory, '/\\') . '/prospektweb.calc.catalog-adapter.' . $presetId . '.lock';
        $handle = @fopen($path, 'c');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Не удалось открыть блокировку адаптера каталога.');
        }
        $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;
        try {
            do {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    try {
                        return $callback();
                    } finally {
                        flock($handle, LOCK_UN);
                    }
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
        } finally {
            fclose($handle);
        }
        throw new \RuntimeException('Хранилище адаптера каталога занято. Повторите операцию.');
    }
}
