<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

/**
 * Read-only dependency authority for a preset-owned form-first calculator.
 *
 * The contract deliberately has no product, family or route authority.
 * Products merely launch a preset; dependency discovery belongs to the preset
 * form, its calculation graph, input mapping and optional storefront patches.
 */
final class FormFirstDependencyContractService
{
    public const CONTRACT = 'prospektweb.calc.preset-public-inputs/v1';

    private const CATEGORIES = [
        'ui',
        'catalog_input_mapping',
        'stage_inputs',
        'globals',
        'options_mappings',
        'basket',
        'storefront_presentation',
    ];

    private const REQUIRED_INPUT_CATEGORIES = [
        'stage_inputs',
        'globals',
        'options_mappings',
    ];

    /** @var callable */
    private $dependencyLoader;

    /** @var callable */
    private $fieldReferenceLoader;

    public function __construct(?callable $dependencyLoader = null, ?callable $fieldReferenceLoader = null)
    {
        $this->fieldReferenceLoader = $fieldReferenceLoader
            ?? static function (int $presetId): array {
                return self::loadPresetFieldReferences($presetId);
            };
        $this->dependencyLoader = $dependencyLoader
            ?? function (int $presetId): array {
                return self::loadBitrixDependencyGraph($presetId, $this->fieldReferenceLoader);
            };
    }

    /** @return array<int,array{fieldId:string,category:string,source:string,path:string,provenance:string}> */
    public function fieldReferences(int $presetId, string $fieldId): array
    {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Preset ID must be positive');
        }
        $fieldId = trim($fieldId);
        if ($fieldId === '' || strlen($fieldId) > 100 || preg_match('/[\x00-\x1F\x7F]/', $fieldId) === 1) {
            throw new \InvalidArgumentException('Field ID is invalid');
        }
        $references = call_user_func($this->fieldReferenceLoader, $presetId);
        if (!is_array($references)) {
            throw new \RuntimeException('Unable to load preset field references');
        }

        return array_values(array_filter(
            self::normalizeFieldReferences($references),
            static fn(array $reference): bool => $reference['fieldId'] === $fieldId
        ));
    }

    /** @return array<string,mixed> */
    public function buildPublicInputContract(int $presetId): array
    {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Preset ID must be positive');
        }

        $graph = call_user_func($this->dependencyLoader, $presetId);
        if (!is_array($graph) || (int)($graph['presetId'] ?? 0) !== $presetId) {
            throw new \RuntimeException('Unable to load the form-first dependency graph');
        }

        $consumers = $this->normalizeConsumers((array)($graph['consumers'] ?? []));
        $requiredPropertyCodes = [];
        foreach ($consumers as $consumer) {
            if (in_array($consumer['category'], self::REQUIRED_INPUT_CATEGORIES, true)) {
                $requiredPropertyCodes[$consumer['propertyCode']] = true;
            }
        }
        $requiredPropertyCodes = array_keys($requiredPropertyCodes);
        sort($requiredPropertyCodes, SORT_STRING);

        $rawCategoryStatus = is_array($graph['categoryStatus'] ?? null)
            ? $graph['categoryStatus']
            : [];
        $categoryStatus = [];
        $unresolved = [];
        foreach ((array)($graph['unresolvedSources'] ?? []) as $source) {
            $source = trim((string)$source);
            if ($source !== '') {
                $unresolved[$source] = true;
            }
        }
        foreach (self::CATEGORIES as $category) {
            $count = 0;
            foreach ($consumers as $consumer) {
                if ($consumer['category'] === $category) {
                    $count++;
                }
            }
            $status = is_array($rawCategoryStatus[$category] ?? null)
                ? $rawCategoryStatus[$category]
                : [];
            $sourceMode = (string)($status['sourceMode'] ?? '');
            $scanned = ($status['scanned'] ?? false) === true
                && in_array($sourceMode, ['discovered', 'declared'], true);
            if (!$scanned) {
                $unresolved['category_not_scanned:' . $category] = true;
            }
            $categoryStatus[$category] = [
                'scanned' => $scanned,
                'count' => $count,
                'sourceMode' => in_array($sourceMode, ['discovered', 'declared'], true)
                    ? $sourceMode
                    : 'discovered',
            ];
        }
        if ($unresolved !== []) {
            $sources = array_keys($unresolved);
            sort($sources, SORT_STRING);
            throw new \RuntimeException(
                'The current form-first dependency authority is incomplete: ' . implode(', ', $sources)
            );
        }

        $contract = [
            'contract' => self::CONTRACT,
            'presetId' => $presetId,
            'requiredPropertyCodes' => $requiredPropertyCodes,
            'consumers' => $consumers,
            'categoryStatus' => $categoryStatus,
        ];
        $contract['fingerprint'] = $this->canonicalHash($contract);

        return $contract;
    }

    /**
     * @param array<int,mixed> $rawConsumers
     * @return array<int,array{propertyCode:string,category:string,source:string,path:string,provenance:string}>
     */
    private function normalizeConsumers(array $rawConsumers): array
    {
        $normalized = [];
        foreach ($rawConsumers as $consumer) {
            if (!is_array($consumer)) {
                continue;
            }
            $propertyCode = strtoupper(trim((string)($consumer['propertyCode'] ?? '')));
            $category = trim((string)($consumer['category'] ?? ''));
            $source = trim((string)($consumer['source'] ?? ''));
            $path = trim((string)($consumer['path'] ?? ''));
            $provenance = trim((string)($consumer['provenance'] ?? ''));
            if (preg_match('/^CALC_PROP_[A-Z0-9_]{1,100}$/D', $propertyCode) !== 1
                || !in_array($category, self::CATEGORIES, true)
                || $source === ''
                || strlen($source) > 500
                || $path === ''
                || strlen($path) > 1000
                || !in_array($provenance, ['discovered', 'declared'], true)) {
                continue;
            }
            $key = implode('|', [$propertyCode, $category, $source, $path, $provenance]);
            $normalized[$key] = compact('propertyCode', 'category', 'source', 'path', 'provenance');
        }
        ksort($normalized, SORT_STRING);

        return array_values($normalized);
    }

    /** @return array<string,mixed> */
    private static function loadBitrixDependencyGraph(int $presetId, callable $fieldReferenceLoader): array
    {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Preset ID must be positive');
        }
        if (!class_exists('CIBlockElement')) {
            throw new \RuntimeException('The Bitrix iblock API is not available');
        }

        $consumers = [];
        $unresolvedSources = [];
        $categoryStatus = [];
        foreach (self::CATEGORIES as $category) {
            $categoryStatus[$category] = [
                'scanned' => false,
                'sourceMode' => $category === 'basket' ? 'declared' : 'discovered',
            ];
        }

        $presetIblockId = self::readOnlyIblockId('CALC_PRESETS', 'calculator');
        $detailsIblockId = self::readOnlyIblockId('CALC_DETAILS', 'calculator_catalog');
        $stagesIblockId = self::readOnlyIblockId('CALC_STAGES', 'calculator_catalog');
        $globalsIblockId = self::readOnlyIblockId('CALC_GLOBAL_VALUES', 'calculator');
        foreach ([
            'CALC_PRESETS' => $presetIblockId,
            'CALC_DETAILS' => $detailsIblockId,
            'CALC_STAGES' => $stagesIblockId,
            'CALC_GLOBAL_VALUES' => $globalsIblockId,
        ] as $code => $iblockId) {
            if ($iblockId <= 0) {
                $unresolvedSources[] = 'iblock_not_configured:' . $code;
            }
        }

        $elementScopes = [];
        if ($presetIblockId > 0) {
            $elementScopes[] = [
                'iblockCode' => 'CALC_PRESETS',
                'iblockId' => $presetIblockId,
                'elementIds' => [$presetId],
                'category' => 'stage_inputs',
            ];
        }
        $presetDetailIds = $presetIblockId > 0
            ? self::elementPropertyIds($presetIblockId, $presetId, 'CALC_DETAILS')
            : [];
        $presetStageIds = $presetIblockId > 0
            ? self::elementPropertyIds($presetIblockId, $presetId, 'CALC_STAGES')
            : [];
        $seenDetailIds = [];
        $pendingDetailIds = $presetDetailIds;
        while ($pendingDetailIds !== []) {
            $detailId = (int)array_shift($pendingDetailIds);
            if ($detailId <= 0 || isset($seenDetailIds[$detailId])) {
                continue;
            }
            $seenDetailIds[$detailId] = true;
            if ($detailsIblockId <= 0) {
                continue;
            }
            foreach (self::elementPropertyIds($detailsIblockId, $detailId, 'DETAILS') as $childId) {
                $pendingDetailIds[] = $childId;
            }
            foreach (self::elementPropertyIds($detailsIblockId, $detailId, 'CALC_STAGES') as $stageId) {
                $presetStageIds[] = $stageId;
            }
        }
        if ($detailsIblockId > 0 && $seenDetailIds !== []) {
            $elementScopes[] = [
                'iblockCode' => 'CALC_DETAILS',
                'iblockId' => $detailsIblockId,
                'elementIds' => array_map('intval', array_keys($seenDetailIds)),
                'category' => 'stage_inputs',
            ];
        }
        $presetStageIds = array_values(array_unique(array_filter(array_map('intval', $presetStageIds))));
        if ($stagesIblockId > 0 && $presetStageIds !== []) {
            $elementScopes[] = [
                'iblockCode' => 'CALC_STAGES',
                'iblockId' => $stagesIblockId,
                'elementIds' => $presetStageIds,
                'category' => 'stage_inputs',
            ];
        }

        foreach ($elementScopes as $scope) {
            $iblockCode = (string)$scope['iblockCode'];
            $iblockId = (int)$scope['iblockId'];
            $defaultCategory = (string)$scope['category'];
            $cursor = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                ['IBLOCK_ID' => $iblockId, 'ID' => $scope['elementIds']],
                false,
                false,
                ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'PREVIEW_TEXT', 'DETAIL_TEXT']
            );
            while ($element = $cursor->GetNextElement()) {
                $fields = $element->GetFields();
                $elementId = (int)($fields['ID'] ?? 0);
                self::scanValueForConsumers(
                    $fields,
                    $defaultCategory,
                    $iblockCode,
                    'element:' . $elementId . '.fields',
                    $consumers
                );
                self::scanValueForConsumers(
                    $element->GetProperties(),
                    $defaultCategory,
                    $iblockCode,
                    'element:' . $elementId . '.properties',
                    $consumers
                );
            }
        }
        if ($presetIblockId > 0 && $detailsIblockId > 0 && $stagesIblockId > 0) {
            $categoryStatus['stage_inputs']['scanned'] = true;
            $categoryStatus['options_mappings']['scanned'] = true;
        }

        if ($globalsIblockId > 0) {
            $cursor = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                ['IBLOCK_ID' => $globalsIblockId, '=PROPERTY_PRESET_ID' => $presetId],
                false,
                false,
                ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'PREVIEW_TEXT', 'DETAIL_TEXT']
            );
            while ($element = $cursor->GetNextElement()) {
                $fields = $element->GetFields();
                $elementId = (int)($fields['ID'] ?? 0);
                self::scanValueForConsumers(
                    $fields,
                    'globals',
                    'CALC_GLOBAL_VALUES',
                    'element:' . $elementId . '.fields',
                    $consumers
                );
                self::scanValueForConsumers(
                    $element->GetProperties(),
                    'globals',
                    'CALC_GLOBAL_VALUES',
                    'element:' . $elementId . '.properties',
                    $consumers
                );
            }
            $categoryStatus['globals']['scanned'] = true;
        }

        self::scanPresetForm(
            $presetId,
            $consumers,
            $categoryStatus,
            $unresolvedSources,
            $fieldReferenceLoader
        );

        return [
            'presetId' => $presetId,
            'consumers' => $consumers,
            'categoryStatus' => $categoryStatus,
            'unresolvedSources' => $unresolvedSources,
        ];
    }

    private static function scanPresetForm(
        int $presetId,
        array &$consumers,
        array &$categoryStatus,
        array &$unresolvedSources,
        callable $fieldReferenceLoader
    ): void {
        $formCategories = ['ui', 'catalog_input_mapping', 'basket', 'storefront_presentation'];
        if (!\Bitrix\Main\Loader::includeModule('prospektweb.frontcalc')) {
            $unresolvedSources[] = 'preset_form:frontcalc_unavailable';
            return;
        }
        $storeClass = '\\Prospektweb\\Frontcalc\\Service\\FormFirstAuthoringStore';
        if (!class_exists($storeClass)) {
            $unresolvedSources[] = 'preset_form:store_unavailable';
            return;
        }
        try {
            $aggregate = (new $storeClass())->load($presetId);
        } catch (\Throwable $error) {
            $unresolvedSources[] = 'preset_form:load_failed';
            return;
        }
        if ($aggregate === null) {
            foreach ($formCategories as $category) {
                $categoryStatus[$category]['scanned'] = true;
            }
            return;
        }

        $form = is_array($aggregate['formDefinition'] ?? null) ? $aggregate['formDefinition'] : [];
        $bindings = is_array($aggregate['bindingDefinition'] ?? null) ? $aggregate['bindingDefinition'] : [];
        $fields = [];
        foreach (is_array($form['fields'] ?? null) ? $form['fields'] : [] as $index => $field) {
            $fieldId = is_array($field) ? trim((string)($field['fieldId'] ?? '')) : '';
            if ($fieldId !== '') {
                $fields[$fieldId] = ['index' => (int)$index, 'field' => $field];
            }
        }
        $propertyByField = [];
        foreach (is_array($bindings['bindings'] ?? null) ? $bindings['bindings'] : [] as $index => $binding) {
            if (!is_array($binding) || !is_array($binding['target'] ?? null)) {
                continue;
            }
            $fieldId = trim((string)($binding['fieldId'] ?? ''));
            $propertyCode = strtoupper(trim((string)($binding['target']['propertyCode'] ?? '')));
            if ((string)($binding['target']['kind'] ?? '') !== 'property'
                || !isset($fields[$fieldId])
                || !self::isCalcPropertyCode($propertyCode)) {
                continue;
            }
            $field = $fields[$fieldId]['field'];
            $propertyByField[$fieldId] = $propertyCode;
            $basePath = 'preset.' . $presetId . '.bindings.' . (int)$index;
            self::appendConsumer($consumers, $propertyCode, 'ui', 'frontcalc.preset_form', $basePath, 'discovered');
            self::appendConsumer(
                $consumers,
                $propertyCode,
                'basket',
                'frontcalc.calculation_session',
                'preset.' . $presetId . '.selection.' . $fieldId,
                'declared'
            );
        }
        try {
            $fieldReferences = self::normalizeFieldReferences(
                call_user_func($fieldReferenceLoader, $presetId)
            );
        } catch (\Throwable $error) {
            $unresolvedSources[] = 'preset_field_references:load_failed';
            return;
        }
        foreach ($fieldReferences as $reference) {
            $propertyCode = $propertyByField[$reference['fieldId']] ?? '';
            if (!self::isCalcPropertyCode($propertyCode)) {
                continue;
            }
            self::appendConsumer(
                $consumers,
                $propertyCode,
                $reference['category'],
                $reference['source'],
                $reference['path'],
                $reference['provenance']
            );
        }
        foreach ($formCategories as $category) {
            $categoryStatus[$category]['scanned'] = true;
        }
    }

    /** @return array<int,array{fieldId:string,category:string,source:string,path:string,provenance:string}> */
    private static function loadPresetFieldReferences(int $presetId): array
    {
        $references = [];
        $mapping = (new CalculatorInputMappingService())->load($presetId);
        foreach ((array)($mapping['mappings'] ?? []) as $index => $row) {
            $fieldId = is_array($row) && is_array($row['target'] ?? null)
                ? trim((string)($row['target']['field_id'] ?? ''))
                : '';
            if ($fieldId !== '') {
                $references[] = [
                    'fieldId' => $fieldId,
                    'category' => 'catalog_input_mapping',
                    'source' => CalculatorInputMappingService::CONTRACT,
                    'path' => 'calculator_input_mapping.mappings.' . (int)$index . '.target.field_id',
                    'provenance' => 'declared',
                ];
            }
        }

        if (!\Bitrix\Main\Loader::includeModule('prospektweb.frontcalc')) {
            throw new \RuntimeException('The storefront module is unavailable');
        }
        $repositoryClass = '\\Prospektweb\\Frontcalc\\Service\\StorefrontRepository';
        if (!class_exists($repositoryClass)) {
            throw new \RuntimeException('The storefront repository is unavailable');
        }
        $catalog = (new $repositoryClass())->listStorefronts($presetId);
        foreach ((array)($catalog['items'] ?? []) as $storefront) {
            if (!is_array($storefront)) {
                continue;
            }
            $storefrontId = trim((string)($storefront['id'] ?? ''));
            $patches = $storefront['presentation']['field_patches'] ?? [];
            if ($patches instanceof \stdClass) {
                $patches = get_object_vars($patches);
            }
            if (!is_array($patches)) {
                throw new \RuntimeException('The storefront field patch authority is invalid');
            }
            foreach (array_keys($patches) as $fieldId) {
                if (is_string($fieldId) && $fieldId !== '') {
                    $references[] = [
                        'fieldId' => $fieldId,
                        'category' => 'storefront_presentation',
                        'source' => 'prospektweb.frontcalc.storefront-definition/v2',
                        'path' => 'storefront.' . $storefrontId . '.presentation.field_patches.' . $fieldId,
                        'provenance' => 'declared',
                    ];
                }
            }
        }

        return self::normalizeFieldReferences($references);
    }

    /**
     * @param mixed $references
     * @return array<int,array{fieldId:string,category:string,source:string,path:string,provenance:string}>
     */
    private static function normalizeFieldReferences($references): array
    {
        if (!is_array($references)) {
            throw new \RuntimeException('The preset field reference authority is invalid');
        }
        $normalized = [];
        foreach ($references as $reference) {
            if (!is_array($reference)) {
                throw new \RuntimeException('The preset field reference authority is invalid');
            }
            $fieldId = trim((string)($reference['fieldId'] ?? ''));
            $category = trim((string)($reference['category'] ?? ''));
            $source = trim((string)($reference['source'] ?? ''));
            $path = trim((string)($reference['path'] ?? ''));
            $provenance = trim((string)($reference['provenance'] ?? ''));
            if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $fieldId) !== 1
                || in_array('__proto__', explode('.', $fieldId), true)
                || in_array('prototype', explode('.', $fieldId), true)
                || in_array('constructor', explode('.', $fieldId), true)
                || !in_array($category, ['catalog_input_mapping', 'storefront_presentation'], true)
                || $source === ''
                || strlen($source) > 500
                || $path === ''
                || strlen($path) > 1000
                || !in_array($provenance, ['discovered', 'declared'], true)) {
                throw new \RuntimeException('The preset field reference authority is invalid');
            }
            $key = implode('|', [$fieldId, $category, $source, $path, $provenance]);
            $normalized[$key] = compact('fieldId', 'category', 'source', 'path', 'provenance');
        }
        ksort($normalized, SORT_STRING);

        return array_values($normalized);
    }

    private static function appendConsumer(
        array &$consumers,
        string $propertyCode,
        string $category,
        string $source,
        string $path,
        string $provenance
    ): void {
        $consumers[] = compact('propertyCode', 'category', 'source', 'path', 'provenance');
    }

    private static function isCalcPropertyCode(string $propertyCode): bool
    {
        return preg_match('/^CALC_PROP_[A-Z0-9_]{1,100}$/D', $propertyCode) === 1;
    }

    private static function readOnlyIblockId(string $code, string $type): int
    {
        $configured = (int)\Bitrix\Main\Config\Option::get(
            'prospektweb.calc',
            'IBLOCK_' . $code,
            0
        );
        if ($configured > 0) {
            return $configured;
        }
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return 0;
        }
        $row = \CIBlock::GetList([], ['CODE' => $code, 'TYPE' => $type])->Fetch();

        return is_array($row) ? (int)($row['ID'] ?? 0) : 0;
    }

    /** @return int[] */
    private static function elementPropertyIds(int $iblockId, int $elementId, string $propertyCode): array
    {
        if ($iblockId <= 0 || $elementId <= 0) {
            return [];
        }
        $ids = [];
        $cursor = \CIBlockElement::GetProperty(
            $iblockId,
            $elementId,
            ['sort' => 'asc', 'id' => 'asc'],
            ['CODE' => $propertyCode]
        );
        while ($row = $cursor->Fetch()) {
            $id = (int)($row['VALUE'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    private static function scanValueForConsumers(
        $value,
        string $defaultCategory,
        string $source,
        string $path,
        array &$consumers
    ): void {
        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                self::scanValueForConsumers(
                    $nested,
                    self::categoryForPath($defaultCategory, (string)$key),
                    $source,
                    $path . '.' . (string)$key,
                    $consumers
                );
            }
            return;
        }
        if (!is_scalar($value)
            || preg_match_all('/\bCALC_PROP_[A-Z0-9_]+\b/', (string)$value, $matches) < 1) {
            return;
        }
        foreach (array_unique($matches[0]) as $propertyCode) {
            self::appendConsumer(
                $consumers,
                $propertyCode,
                $defaultCategory,
                $source,
                $path,
                'discovered'
            );
        }
    }

    private static function categoryForPath(string $defaultCategory, string $key): string
    {
        return in_array(strtoupper($key), ['OPTIONS_OPERATION', 'OPTIONS_MATERIAL', 'OPTIONS_EQUIPMENT'], true)
            ? 'options_mappings'
            : $defaultCategory;
    }

    private function canonicalHash(array $value): string
    {
        $encoded = json_encode(
            $this->sortRecursively($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to fingerprint the form-first dependency contract');
        }

        return hash('sha256', $encoded);
    }

    private function sortRecursively($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
