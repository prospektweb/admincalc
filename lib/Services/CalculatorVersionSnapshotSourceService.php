<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Prospektweb\Calc\Calculator\ElementDataService;
use Prospektweb\Calc\Calculator\InitPayloadService;

/** Read-only assembler of the six authorities that make an executable calculator. */
final class CalculatorVersionSnapshotSourceService
{
    public const LOGIC_CONTRACT = 'prospektweb.calc.version-logic-snapshot/v1';
    public const LOGIC_RUNTIME_CONTRACT = 'prospektweb.calc.version-runtime-payload/v1';
    public const PRODUCT_ASSIGNMENTS_CONTRACT = 'prospektweb.calc.version-product-assignments/v1';
    public const PUBLICATION_METADATA_CONTRACT = 'prospektweb.calc.version-publication-metadata/v1';
    public const COMMERCIAL_POLICY_CONTRACT = 'prospektweb.calc.commercial-policy/v1';

    /** @var array<string,callable> */
    private array $adapters;

    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /**
     * @param array<string,mixed> $formDocument
     * @return array<string,mixed>
     */
    public function capture(int $presetId, array $formDocument): array
    {
        if ($presetId <= 0
            || !is_array($formDocument['formDefinition'] ?? null)
            || !is_array($formDocument['bindingDefinition'] ?? null)) {
            throw new \InvalidArgumentException('Для полного снимка требуется точный документ формы версии.');
        }
        $storefronts = $this->storefronts($presetId);
        $logic = $this->captureLogic($presetId);
        $inputMappings = $this->inputMappings($presetId);
        $outputMappings = $this->outputMappings($presetId);
        $productAssignments = $this->productAssignments($presetId, $storefronts);
        $versionedStorefronts = $this->withoutProductAssignmentCopies($storefronts);
        $publicationMetadata = $this->publicationMetadata($presetId);
        $commercialPolicy = $this->commercialPolicy($presetId);
        return [
            'form' => [
                'contract' => CalculatorVersionFormDocumentService::CONTRACT,
                'formDefinition' => $formDocument['formDefinition'],
                'bindingDefinition' => $formDocument['bindingDefinition'],
            ],
            'logic' => $logic,
            'storefronts' => $versionedStorefronts,
            'inputMappings' => $inputMappings,
            'outputMappings' => $outputMappings,
            'productAssignments' => $productAssignments,
            'publicationMetadata' => $publicationMetadata,
            'commercialPolicy' => $commercialPolicy,
        ];
    }

    /**
     * Materialize a clean editable version without reading mutable calculator
     * components. Only calculator identity is retained; every owned document
     * starts from its canonical default.
     *
     * @param array<string,mixed> $formDocument
     * @return array<string,mixed>
     */
    public function blankVersion(int $presetId, array $formDocument): array
    {
        if ($presetId <= 0
            || !is_array($formDocument['formDefinition'] ?? null)
            || !is_array($formDocument['bindingDefinition'] ?? null)) {
            throw new \InvalidArgumentException('Для чистой версии требуется канонический документ системной формы.');
        }
        return [
            'form' => [
                'contract' => CalculatorVersionFormDocumentService::CONTRACT,
                'formDefinition' => $formDocument['formDefinition'],
                'bindingDefinition' => $formDocument['bindingDefinition'],
            ],
            'logic' => [
                'contract' => self::LOGIC_CONTRACT,
                'presetId' => $presetId,
                'initializationMode' => 'blank',
                'graph' => [
                    'detailIds' => [],
                    'stageIds' => [],
                    'settingsIds' => [],
                    'detailStages' => [],
                    'stageSettings' => [],
                ],
                'elements' => [],
            ],
            'storefronts' => [
                'contract' => 'prospektweb.frontcalc.storefront-definition/v2',
                'preset_id' => $presetId,
                'base_public' => false,
                'base' => [
                    'contract' => 'prospektweb.frontcalc.storefront-definition/v2',
                    'id' => 'BASE',
                    'preset_id' => $presetId,
                    'name' => 'Базовая витрина',
                    'active' => true,
                    'public' => false,
                    'public_sort' => 100,
                    'default_product_id' => 0,
                    'revision' => 0,
                    'presentation' => ['field_patches' => new \stdClass()],
                    'product_ids' => [],
                ],
                'items' => [],
            ],
            'inputMappings' => CalculatorInputMappingService::initialDocument($presetId),
            'outputMappings' => CatalogOutputMappingService::initialDocument($presetId),
            'productAssignments' => [
                'contract' => self::PRODUCT_ASSIGNMENTS_CONTRACT,
                'presetId' => $presetId,
                'sourceRevision' => hash('sha256', '[]'),
                'assignments' => [],
            ],
            'publicationMetadata' => $this->publicationMetadata($presetId),
            'commercialPolicy' => self::defaultCommercialPolicy($presetId),
        ];
    }

    /** @return array<string,mixed> */
    public function captureLogic(
        int $sourcePresetId,
        ?int $calculatorPresetId = null,
        ?string $workingVersionId = null
    ): array
    {
        $calculatorPresetId = $calculatorPresetId ?? $sourcePresetId;
        if (isset($this->adapters['logic'])) {
            $value = call_user_func($this->adapters['logic'], $sourcePresetId);
            if (!is_array($value)) throw new \RuntimeException('Logic snapshot adapter returned invalid data.');
        } else {
            $authority = new CalculatorMutationAuthorityService();
            $value = $authority->withAuthorityLock(
                $sourcePresetId,
                static function (bool $_protection, array $iblockIds, array $_lockedAuthority) use ($sourcePresetId, $calculatorPresetId, $workingVersionId, $authority): array {
                    $graph = $authority->readLockedPresetGraph($sourcePresetId);
                    $loader = new ElementDataService($iblockIds);
                    $requests = [[
                        'iblockId' => (int)$iblockIds['CALC_PRESETS'],
                        'iblockType' => null,
                        'ids' => [$sourcePresetId],
                        'includeParent' => false,
                    ]];
                    foreach ([
                        'details' => ['CALC_DETAILS', $graph['detailIds']],
                        'stages' => ['CALC_STAGES', $graph['stageIds']],
                        'settings' => ['CALC_SETTINGS', $graph['settingsIds']],
                    ] as [$iblockCode, $ids]) {
                        if ($ids === []) continue;
                        $requests[] = [
                            'iblockId' => (int)$iblockIds[$iblockCode],
                            'iblockType' => null,
                            'ids' => $ids,
                            'includeParent' => false,
                        ];
                    }
                    $payload = $loader->prepareRefreshPayload($requests);
                    $runtimeConfigSnapshot = (new CatalogRuntimeConfigAuthorityService())
                        ->captureCalculatorSnapshot();
                    $globalSymbolIblockId = CatalogRuntimeConfigAuthorityService::runtimeIblockId(
                        $runtimeConfigSnapshot,
                        'CALC_GLOBAL_VALUES'
                    );
                    // A version working graph owns its mutable globals. They are
                    // projected back to the calculator identity only in the
                    // immutable document envelope.
                    $globalOwnerPresetId = $workingVersionId !== null
                        ? $sourcePresetId
                        : $calculatorPresetId;
                    $globalSymbols = (new GlobalSymbolService())
                        ->listReadOnlyFromIblockId($globalSymbolIblockId, $globalOwnerPresetId);
                    $runtimePayload = (new InitPayloadService())
                        ->preparePresetCalculationPayloadReadOnlyPinned(
                            $sourcePresetId,
                            [],
                            defined('SITE_ID') ? (string)SITE_ID : '',
                            array_values($globalSymbols),
                            $runtimeConfigSnapshot
                        );
                    unset($runtimePayload['context']);
                    $runtimePayload['contract'] = self::LOGIC_RUNTIME_CONTRACT;
                    $runtimePayload['selectedOffers'] = [];
                    $runtimePayload['product'] = null;
                    $runtimePayload['neutralInputRequired'] = true;
                    $runtimePayload['runtimeConfigSnapshot'] = $runtimeConfigSnapshot;
                    $runtimePayload['preset']['runtimePresetId'] = $sourcePresetId;
                    $runtimePayload['preset']['id'] = $calculatorPresetId;
                    $runtimePayload = self::projectLockedGraphRuntimePayload(
                        $runtimePayload,
                        $graph,
                        $payload,
                        $iblockIds
                    );
                    if (!is_array($runtimePayload['globalSymbols'] ?? null)) {
                        throw new \RuntimeException('Logic runtime global-symbol projection is invalid.', 409);
                    }
                    foreach ($runtimePayload['globalSymbols'] as &$symbol) {
                        if (!is_array($symbol)) {
                            throw new \RuntimeException('Logic runtime global-symbol row is invalid.', 409);
                        }
                        $symbol['presetId'] = $calculatorPresetId;
                    }
                    unset($symbol);
                    return [
                        'contract' => self::LOGIC_CONTRACT,
                        'presetId' => $sourcePresetId,
                        'graph' => $graph,
                        'elements' => array_values($payload),
                        'runtimePayload' => $runtimePayload,
                    ];
                }
            );
        }
        $value['presetId'] = $calculatorPresetId;
        if ($calculatorPresetId !== $sourcePresetId) {
            $value['workingPresetId'] = $sourcePresetId;
            if ($workingVersionId !== null) $value['workingVersionId'] = $workingVersionId;
        } else {
            unset($value['workingPresetId'], $value['workingVersionId']);
        }
        return $value;
    }

    /**
     * Snapshot capture already owns one authority lock and one exact structural
     * read. Reuse that read instead of following the preset's denormalized links
     * a second time through InitPayloadService.
     *
     * @param array<string,mixed> $runtimePayload
     * @param array<string,mixed> $graph
     * @param array<int,array<string,mixed>> $structuralPayload
     * @param array<string,int> $iblockIds
     * @return array<string,mixed>
     */
    private static function projectLockedGraphRuntimePayload(
        array $runtimePayload,
        array $graph,
        array $structuralPayload,
        array $iblockIds
    ): array {
        if (!is_array($runtimePayload['preset'] ?? null)) {
            throw new \RuntimeException('Logic runtime preset projection is invalid.', 409);
        }
        if (!is_array($runtimePayload['preset']['properties'] ?? null)) {
            $runtimePayload['preset']['properties'] = [];
        }
        if (!is_array($runtimePayload['elementsStore'] ?? null)) {
            throw new \RuntimeException('Logic runtime structural store is invalid.', 409);
        }

        $propertyDefinitions = [
            'CALC_DETAILS' => 'rootDetailIds',
            'CALC_STAGES' => 'stageIds',
            'CALC_SETTINGS' => 'directSettingsIds',
        ];
        foreach ($propertyDefinitions as $propertyCode => $graphKey) {
            $runtimePayload['preset']['properties'][$propertyCode] = self::exactGraphIds($graph, $graphKey);
        }

        $storeDefinitions = [
            'CALC_DETAILS' => 'detailIds',
            'CALC_STAGES' => 'stageIds',
            'CALC_SETTINGS' => 'settingsIds',
        ];
        foreach ($storeDefinitions as $iblockCode => $graphKey) {
            $expectedIds = self::exactGraphIds($graph, $graphKey);
            $rows = [];
            $matchedPayloads = [];
            foreach ($structuralPayload as $entry) {
                if ((int)($entry['iblockId'] ?? 0) === (int)($iblockIds[$iblockCode] ?? 0)) {
                    $matchedPayloads[] = $entry;
                }
            }

            if ($expectedIds !== []) {
                if (count($matchedPayloads) !== 1) {
                    throw new \RuntimeException(
                        'Locked logic snapshot has no exact structural payload for ' . $iblockCode . '.',
                        409
                    );
                }
                $declaredIds = array_values(array_map('intval', (array)($matchedPayloads[0]['ids'] ?? [])));
                $rows = is_array($matchedPayloads[0]['data'] ?? null) ? $matchedPayloads[0]['data'] : [];
                $actualIds = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        throw new \RuntimeException('Locked logic structural row is invalid.', 409);
                    }
                    $actualIds[] = (int)($row['id'] ?? 0);
                }
                if ($declaredIds !== $expectedIds || $actualIds !== $expectedIds) {
                    throw new \RuntimeException(
                        'Locked logic graph changed while its runtime snapshot was being captured.',
                        409
                    );
                }
            } elseif ($matchedPayloads !== []) {
                throw new \RuntimeException(
                    'Locked logic snapshot contains an unexpected structural payload for ' . $iblockCode . '.',
                    409
                );
            }

            $runtimePayload['elementsStore'][$iblockCode] = array_values($rows);
        }

        return $runtimePayload;
    }

    /** @param array<string,mixed> $graph @return int[] */
    private static function exactGraphIds(array $graph, string $key): array
    {
        if (!is_array($graph[$key] ?? null) || !array_is_list($graph[$key])) {
            throw new \RuntimeException('Locked logic graph has an invalid ' . $key . ' list.', 409);
        }
        $ids = [];
        foreach ($graph[$key] as $rawId) {
            $id = (int)$rawId;
            if ($id <= 0 || isset($ids[$id])) {
                throw new \RuntimeException('Locked logic graph has invalid or duplicate structural IDs.', 409);
            }
            $ids[$id] = true;
        }
        return array_map('intval', array_keys($ids));
    }

    /**
     * Match a newly cloned graph to the exact stage order stored by an older
     * version. Duplicate stage descriptors are consumed in their current
     * order, so equivalent repeated stages remain deterministic.
     *
     * @return list<array{detailId:int,stageIds:list<int>,alreadyOrdered:bool}>
     */
    public static function recoveryStageOrderPlan(array $historicalLogic, array $workingLogic): array
    {
        $historical = self::logicTopology($historicalLogic);
        $working = self::logicTopology($workingLogic);
        $workingDetails = [];
        foreach ($working as $detail) {
            $workingDetails[$detail['name']][] = $detail;
        }

        $plan = [];
        foreach ($historical as $historicalDetail) {
            $name = $historicalDetail['name'];
            if (($workingDetails[$name] ?? []) === []) {
                throw new \RuntimeException('Исторический порядок логики нельзя восстановить: состав деталей изменился.', 409);
            }
            $workingDetail = array_shift($workingDetails[$name]);
            $stageBuckets = [];
            foreach ($workingDetail['stages'] as $stage) {
                $stageBuckets[$stage['descriptor']][] = $stage['id'];
            }
            $targetStageIds = [];
            foreach ($historicalDetail['stages'] as $stage) {
                $descriptor = $stage['descriptor'];
                if (($stageBuckets[$descriptor] ?? []) === []) {
                    throw new \RuntimeException('Исторический порядок логики нельзя восстановить: состав этапов изменился.', 409);
                }
                $targetStageIds[] = (int)array_shift($stageBuckets[$descriptor]);
            }
            foreach ($stageBuckets as $ids) {
                if ($ids !== []) {
                    throw new \RuntimeException('Исторический порядок логики нельзя восстановить: появились дополнительные этапы.', 409);
                }
            }
            $currentStageIds = array_column($workingDetail['stages'], 'id');
            $plan[] = [
                'detailId' => (int)$workingDetail['id'],
                'stageIds' => $targetStageIds,
                'alreadyOrdered' => $currentStageIds === $targetStageIds,
            ];
        }
        foreach ($workingDetails as $details) {
            if ($details !== []) {
                throw new \RuntimeException('Исторический порядок логики нельзя восстановить: появились дополнительные детали.', 409);
            }
        }
        return $plan;
    }

    /** @return list<array{id:int,name:string,stages:list<array{id:int,descriptor:string}>}> */
    private static function logicTopology(array $logic): array
    {
        $graph = is_array($logic['graph'] ?? null) ? $logic['graph'] : [];
        $rows = [];
        foreach ((array)($logic['elements'] ?? []) as $batch) {
            foreach ((array)($batch['data'] ?? []) as $row) {
                if (is_array($row) && (int)($row['id'] ?? 0) > 0) {
                    $rows[(int)$row['id']] = $row;
                }
            }
        }
        $topology = [];
        $seenStageIds = [];
        foreach ((array)($graph['detailIds'] ?? []) as $detailId) {
            $detailId = (int)$detailId;
            if ($detailId <= 0 || !isset($rows[$detailId])) {
                throw new \RuntimeException('Снимок логики не содержит данные одной из деталей.', 409);
            }
            $stages = [];
            foreach ((array)($graph['detailStages'][$detailId] ?? []) as $stageId) {
                $stageId = (int)$stageId;
                if ($stageId <= 0 || !isset($rows[$stageId]) || isset($seenStageIds[$stageId])) {
                    throw new \RuntimeException('Снимок логики содержит неоднозначную структуру этапов.', 409);
                }
                $seenStageIds[$stageId] = true;
                $settingsIds = array_values(array_map('intval', (array)($graph['stageSettings'][$stageId] ?? [])));
                $stageName = trim((string)($rows[$stageId]['name'] ?? ''));
                $stages[] = [
                    'id' => $stageId,
                    'descriptor' => json_encode([$stageName, $settingsIds], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
            $topology[] = [
                'id' => $detailId,
                'name' => trim((string)($rows[$detailId]['name'] ?? '')),
                'stages' => $stages,
            ];
        }
        $expectedStageIds = array_values(array_unique(array_map('intval', (array)($graph['stageIds'] ?? []))));
        sort($expectedStageIds, SORT_NUMERIC);
        $actualStageIds = array_map('intval', array_keys($seenStageIds));
        sort($actualStageIds, SORT_NUMERIC);
        if ($expectedStageIds !== $actualStageIds) {
            throw new \RuntimeException('Снимок логики содержит этапы вне структуры деталей.', 409);
        }
        return $topology;
    }

    /** @return array<string,mixed> */
    private function storefronts(int $presetId): array
    {
        if (isset($this->adapters['storefronts'])) {
            $value = call_user_func($this->adapters['storefronts'], $presetId);
            if (!is_array($value)) throw new \RuntimeException('Storefront snapshot adapter returned invalid data.');
            return $this->normalizeStorefronts($presetId, $value, (bool)($value['base_public'] ?? true));
        }
        if (!Loader::includeModule('prospektweb.frontcalc')) {
            throw new \RuntimeException('Модуль prospektweb.frontcalc недоступен для полного снимка витрин.');
        }
        $class = '\\Prospektweb\\Frontcalc\\Service\\StorefrontRepository';
        if (!class_exists($class)) throw new \RuntimeException('Хранилище витрин недоступно.');
        $listing = (new $class())->listStorefronts($presetId);
        $settingsClass = '\\Prospektweb\\Frontcalc\\Service\\PublicCalculatorCatalogService';
        $basePublic = class_exists($settingsClass)
            ? (bool)(new $settingsClass())->settings($presetId)['show_base']
            : true;
        return $this->normalizeStorefronts($presetId, $listing, $basePublic);
    }

    /** @param array<string,mixed> $listing @return array<string,mixed> */
    private function normalizeStorefronts(int $presetId, array $listing, bool $basePublic): array
    {
        $normalize = static function (array $row, bool $base = false) use ($presetId, $basePublic): array {
            $active = $base ? true : (bool)($row['active'] ?? false);
            return [
                'contract' => 'prospektweb.frontcalc.storefront-definition/v2',
                'id' => $base ? 'BASE' : trim((string)($row['id'] ?? '')),
                'preset_id' => $presetId,
                'name' => trim((string)($row['name'] ?? ($base ? 'Базовая витрина' : ''))),
                'active' => $active,
                'public' => $base ? (bool)($row['public'] ?? $basePublic) : (bool)($row['public'] ?? $active),
                'public_sort' => max(0, (int)($row['public_sort'] ?? ($base ? 100 : 500))),
                'default_product_id' => max(0, (int)($row['default_product_id'] ?? 0)),
                'revision' => max(0, (int)($row['revision'] ?? 0)),
                'presentation' => is_array($row['presentation'] ?? null)
                    ? $row['presentation']
                    : ['field_patches' => new \stdClass()],
                'product_ids' => array_values(array_unique(array_map('intval', (array)($row['product_ids'] ?? [])))),
            ];
        };
        $items = [];
        foreach ((array)($listing['items'] ?? []) as $row) {
            if (!is_array($row) || trim((string)($row['id'] ?? '')) === '') continue;
            $items[] = $normalize($row);
        }
        $base = $normalize(is_array($listing['base'] ?? null) ? $listing['base'] : [], true);
        return [
            'contract' => 'prospektweb.frontcalc.storefront-definition/v2',
            'preset_id' => $presetId,
            'base_public' => $base['public'],
            'base' => $base,
            'items' => $items,
        ];
    }

    /** @param array<string,mixed> $storefronts @return array<string,mixed> */
    private function withoutProductAssignmentCopies(array $storefronts): array
    {
        if (is_array($storefronts['base'] ?? null)) $storefronts['base']['product_ids'] = [];
        foreach ((array)($storefronts['items'] ?? []) as $index => $row) {
            if (is_array($row)) $storefronts['items'][$index]['product_ids'] = [];
        }
        return $storefronts;
    }

    /** @return array<string,mixed> */
    private function inputMappings(int $presetId): array
    {
        if (isset($this->adapters['inputMappings'])) {
            $value = call_user_func($this->adapters['inputMappings'], $presetId);
            if (!is_array($value)) throw new \RuntimeException('Input mapping snapshot adapter returned invalid data.');
            return $value;
        }
        return (new CalculatorInputMappingService())->load($presetId);
    }

    /** @return array<string,mixed> */
    private function outputMappings(int $presetId): array
    {
        if (isset($this->adapters['outputMappings'])) {
            $value = call_user_func($this->adapters['outputMappings'], $presetId);
            if (!is_array($value)) throw new \RuntimeException('Output mapping snapshot adapter returned invalid data.');
            return $value;
        }
        return (new CatalogOutputMappingService())->load($presetId);
    }

    /** @param array<string,mixed> $storefronts @return array<string,mixed> */
    private function productAssignments(int $presetId, array $storefronts): array
    {
        if (isset($this->adapters['productAssignments'])) {
            $value = call_user_func($this->adapters['productAssignments'], $presetId, $storefronts);
            if (!is_array($value)) throw new \RuntimeException('Product assignment snapshot adapter returned invalid data.');
            return $value;
        }
        $catalog = (new ControlCenterEditorsService())->getPresetProductCatalog($presetId, '', 1, 1);
        $storefrontByProduct = [];
        foreach ((array)($storefronts['items'] ?? []) as $storefront) {
            if (!is_array($storefront) || empty($storefront['active'])) continue;
            $storefrontId = (string)($storefront['id'] ?? '');
            foreach ((array)($storefront['product_ids'] ?? []) as $productId) {
                $productId = (int)$productId;
                if ($productId <= 0 || isset($storefrontByProduct[$productId])) {
                    throw new \RuntimeException('Товар имеет неоднозначное назначение активной витрины.', 409);
                }
                $storefrontByProduct[$productId] = $storefrontId;
            }
        }
        $assignments = [];
        foreach ((array)($catalog['linkedProductIds'] ?? []) as $productId) {
            $productId = (int)$productId;
            if ($productId > 0) {
                $assignments[] = [
                    'productId' => $productId,
                    'storefrontId' => $storefrontByProduct[$productId] ?? 'BASE',
                ];
            }
        }
        usort($assignments, static fn(array $left, array $right): int => $left['productId'] <=> $right['productId']);
        return [
            'contract' => self::PRODUCT_ASSIGNMENTS_CONTRACT,
            'presetId' => $presetId,
            'sourceRevision' => (string)($catalog['revision'] ?? ''),
            'assignments' => $assignments,
        ];
    }

    /** @return array<string,mixed> */
    public function publicationMetadata(int $presetId): array
    {
        if (isset($this->adapters['publicationMetadata'])) {
            $value = call_user_func($this->adapters['publicationMetadata'], $presetId);
            if (!is_array($value)) {
                throw new \RuntimeException('Publication metadata snapshot adapter returned invalid data.');
            }
            return $value;
        }
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Инфоблоки недоступны для снимка публичных метаданных калькулятора.');
        }
        $config = new \Prospektweb\Calc\Config\ConfigManager();
        $iblockId = (int)$config->getIblockId('CALC_PRESETS');
        $cursor = $iblockId > 0 ? \CIBlockElement::GetList(
            [],
            ['ID' => $presetId, 'IBLOCK_ID' => $iblockId],
            false,
            ['nTopCount' => 2],
            ['ID', 'IBLOCK_ID', 'NAME', 'SORT', 'ACTIVE', 'IBLOCK_SECTION_ID']
        ) : null;
        $row = $cursor ? $cursor->Fetch() : false;
        $duplicate = $cursor ? $cursor->Fetch() : false;
        if (!is_array($row) || $duplicate !== false || (int)($row['ID'] ?? 0) !== $presetId) {
            throw new \RuntimeException('Не удалось получить однозначные публичные метаданные калькулятора.', 409);
        }
        $name = trim((string)($row['NAME'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Название калькулятора не заполнено.', 409);
        }
        return [
            'contract' => self::PUBLICATION_METADATA_CONTRACT,
            'presetId' => $presetId,
            'calculatorName' => $name,
            'sectionId' => max(0, (int)($row['IBLOCK_SECTION_ID'] ?? 0)),
            'sort' => (int)($row['SORT'] ?? 500),
            'active' => (string)($row['ACTIVE'] ?? 'N') === 'Y',
        ];
    }

    /** @return array<string,mixed> */
    public function commercialPolicy(int $presetId): array
    {
        if (isset($this->adapters['commercialPolicy'])) {
            $value = call_user_func($this->adapters['commercialPolicy'], $presetId);
            if (!is_array($value)) {
                throw new \RuntimeException('Commercial policy snapshot adapter returned invalid data.');
            }
            return $value;
        }
        return self::defaultCommercialPolicy($presetId);
    }

    /** @return array<string,mixed> */
    public static function defaultCommercialPolicy(int $presetId): array
    {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Commercial policy presetId must be positive.');
        }
        return [
            'contract' => self::COMMERCIAL_POLICY_CONTRACT,
            'presetId' => $presetId,
            'deadlinePolicy' => [
                'mode' => 'basic',
                'effortBasis' => 'productionMinutes',
                'basic' => [
                    'urgent' => ['effortPercent' => 0.0, 'markupPercent' => 0.0, 'discountPercent' => 0.0],
                    'strict' => ['effortPercent' => 0.0, 'markupPercent' => 0.0, 'discountPercent' => 0.0],
                    'flexible' => ['effortPercent' => 0.0, 'markupPercent' => 0.0, 'discountPercent' => 0.0],
                ],
                'ranges' => [],
                'fallback' => 'basic',
            ],
        ];
    }
}
