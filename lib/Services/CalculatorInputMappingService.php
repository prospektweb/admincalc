<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

/**
 * Preset-owned mapping from Bitrix catalog properties to calculator form inputs.
 *
 * The aggregate contains no concrete products or offers, no product profiles,
 * and no result/catalog-write projection. A selected offer is runtime context,
 * never part of the mapping identity.
 */
final class CalculatorInputMappingService
{
    public const CONTRACT = 'prospektweb.calc.calculator-input-mapping/v1';

    private const MODULE_ID = 'prospektweb.calc';
    private const OPTION_PREFIX = 'CALCULATOR_INPUT_MAPPING_';
    private const MAX_DOCUMENT_BYTES = 131072;
    private const MAX_MAPPINGS = 200;
    private const MAX_OPTION_MAP_ENTRIES = 500;
    private const MAX_INPUT_MAP_ENTRIES = 24;
    private const MAX_SAFE_INTEGER = 9007199254740991;
    private const LOCK_TIMEOUT_SECONDS = 5.0;

    /** @var array<string,callable> */
    private array $adapters;

    public function __construct(array $adapters = [])
    {
        $hasGetOption = isset($adapters['get_option']);
        $hasSetOption = isset($adapters['set_option']);
        if ($hasGetOption !== $hasSetOption) {
            throw new \InvalidArgumentException('Input mapping option adapters must be provided as a get/set pair.');
        }
        $this->adapters = $adapters;
    }

    /** @return array<string,mixed> */
    public function load(int $presetId): array
    {
        $this->assertPresetId($presetId);
        return $this->loadFromRaw($presetId, $this->getRaw($presetId));
    }

    /** @return array<string,mixed> */
    public function loadFromRaw(int $presetId, string $raw): array
    {
        $this->assertPresetId($presetId);
        if ($raw === '') {
            return $this->defaultDocument($presetId);
        }
        if (strlen($raw) > self::MAX_DOCUMENT_BYTES) {
            throw new \RuntimeException('Хранилище сопоставлений входов превышает допустимый размер.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $this->isList($decoded)) {
            throw new \RuntimeException('Хранилище сопоставлений входов повреждено.');
        }
        try {
            return $this->normalizeDefinition($presetId, $decoded);
        } catch (\InvalidArgumentException $error) {
            throw new \RuntimeException('Хранилище сопоставлений входов повреждено: ' . $error->getMessage(), 0, $error);
        }
    }

    /**
     * Return the exact validation envelope consumed by CalcConfig.
     * Structural contract violations remain request errors; semantic warnings
     * can be added to issues without changing the stored document shape.
     *
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    public function validate(int $presetId, array $definition): array
    {
        $mapping = $this->normalizeDefinition($presetId, $definition);
        $issues = $this->assertSemanticAuthority($presetId, $mapping);
        return [
            'contract' => self::CONTRACT,
            'preset_id' => $presetId,
            'valid' => true,
            'mapping' => $mapping,
            'issues' => $issues,
        ];
    }

    /**
     * Compare-and-swap save. The candidate carries the same revision as the
     * expected_revision request field; a successful save increments it once.
     *
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    public function save(int $presetId, int $expectedRevision, array $definition): array
    {
        $this->assertPresetId($presetId);
        $this->assertRevision($expectedRevision);

        if (!isset($this->adapters['get_option'])) {
            return (new PresetMutationCoordinatorService())->mutate(
                $presetId,
                [
                    'action' => 'calculator_input_mapping_save',
                    'entity_type' => 'calculator_input_mapping',
                    'entity_id' => (string)$presetId,
                    'expected_revision' => $expectedRevision,
                    'product_ids' => [],
                ],
                function () use ($presetId, $expectedRevision, $definition): array {
                    return $this->saveDirectUnderCoordinatorTransaction(
                        $presetId,
                        $expectedRevision,
                        $definition
                    );
                },
                function () use ($presetId): array {
                    return $this->load($presetId);
                }
            );
        }

        return $this->withLock($presetId, function () use ($presetId, $expectedRevision, $definition): array {
            $current = $this->load($presetId);
            $candidate = $this->normalizeDefinition($presetId, $definition);
            $this->assertSemanticAuthority($presetId, $candidate);
            $stored = $this->prepareNextDocument($presetId, $expectedRevision, $definition, $current);
            $raw = self::encodeCanonical($stored);
            $this->assertStoredSize($raw);
            $this->setRaw($presetId, $raw);
            $readBack = $this->load($presetId);
            if ($readBack !== $stored) {
                throw new \RuntimeException('Контрольное чтение сопоставлений входов не совпало с записью.');
            }
            return $readBack;
        });
    }

    /** @return array<string,mixed> */
    private function defaultDocument(int $presetId): array
    {
        return [
            'contract' => self::CONTRACT,
            'preset_id' => $presetId,
            'revision' => 0,
            'mappings' => [],
        ];
    }

    /**
     * @param array<string,mixed> $definition
     * @return array<int,array{severity:string,code:string,path:string,message:string}>
     */
    private function assertSemanticAuthority(int $presetId, array $definition): array
    {
        $context = $this->semanticContext($presetId);
        $fields = is_array($context['fields'] ?? null) ? $context['fields'] : [];
        $productIblockId = (int)($context['product_iblock_id'] ?? 0);
        $offerIblockId = (int)($context['offer_iblock_id'] ?? 0);
        $properties = is_array($context['properties'] ?? null) ? $context['properties'] : [];
        $bindingModes = is_array($context['binding_modes'] ?? null) ? $context['binding_modes'] : [];
        if ($productIblockId <= 0 || $offerIblockId <= 0 || $fields === []) {
            throw new \RuntimeException('Form or catalog authority for input mappings is unavailable.', 409);
        }

        $issues = [];
        $mappingsByField = [];
        foreach ($definition['mappings'] as $mapping) {
            $mappingsByField[(string)$mapping['target']['field_id']][] = $mapping;
        }
        foreach ($mappingsByField as $fieldId => $fieldMappings) {
            $field = is_array($fields[$fieldId] ?? null) ? $fields[$fieldId] : null;
            if ($field === null || !is_array($field['dimensionInputs'] ?? null) || $field['dimensionInputs'] === []) {
                continue;
            }
            $whole = [];
            $parts = [];
            foreach ($fieldMappings as $fieldMapping) {
                if ((string)($fieldMapping['target']['input_id'] ?? '') === '') {
                    $whole[] = $fieldMapping;
                } else {
                    $parts[] = $fieldMapping;
                }
            }
            if ($whole !== [] && $parts !== []) {
                throw new \InvalidArgumentException(
                    'Составное поле ' . $fieldId . ' нельзя сопоставлять одновременно целиком и по отдельным входам.'
                );
            }
            if ($whole !== []
                && ((string)$whole[0]['value_mode'] !== 'dimensions'
                    || !is_array($whole[0]['input_map'] ?? null)
                    || $whole[0]['input_map'] === [])) {
                throw new \InvalidArgumentException(
                    'Сопоставление составного поля ' . $fieldId . ' целиком требует dimensions и input_map.'
                );
            }
            foreach ($parts as $part) {
                if ((string)$part['value_mode'] === 'dimensions') {
                    throw new \InvalidArgumentException(
                        'Отдельный вход составного поля ' . $fieldId . ' не может использовать dimensions.'
                    );
                }
            }
        }

        foreach ($definition['mappings'] as $index => $mapping) {
            $path = 'calculator_input_mapping.mappings[' . $index . ']';
            $fieldId = (string)$mapping['target']['field_id'];
            $field = is_array($fields[$fieldId] ?? null) ? $fields[$fieldId] : null;
            if ($field === null) {
                throw new \InvalidArgumentException($path . '.target.field_id отсутствует в текущей форме.');
            }
            $fieldType = trim((string)($field['type'] ?? ''));
            if (!in_array($fieldType, ['number', 'select', 'checkbox', 'dimensions'], true)) {
                throw new \InvalidArgumentException($path . '.target.field_id имеет неизвестный тип формы.');
            }
            $optionIds = [];
            foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
                $optionId = is_array($option) ? trim((string)($option['id'] ?? '')) : '';
                if ($optionId !== '') {
                    $optionIds[$optionId] = true;
                }
            }
            if ($fieldType === 'checkbox' && $optionIds === []) {
                $optionIds = ['Y' => true, 'N' => true];
            }
            $inputIds = [];
            foreach (is_array($field['dimensionInputs'] ?? null) ? $field['dimensionInputs'] : [] as $input) {
                $inputId = is_array($input) ? trim((string)($input['id'] ?? '')) : '';
                if ($inputId !== '') {
                    $inputIds[$inputId] = true;
                }
            }
            $targetInputId = (string)($mapping['target']['input_id'] ?? '');
            if ($targetInputId !== '' && !isset($inputIds[$targetInputId])) {
                throw new \InvalidArgumentException($path . '.target.input_id отсутствует в составном поле формы.');
            }

            $source = $mapping['source'];
            $sourceScope = (string)$source['scope'];
            $expectedIblockId = $sourceScope === 'product' ? $productIblockId : $offerIblockId;
            $iblockId = (int)$source['iblock_id'];
            $propertyId = (int)$source['property_id'];
            $propertyCode = (string)$source['property_code'];
            if ($iblockId !== $expectedIblockId) {
                throw new \InvalidArgumentException($path . '.source.iblock_id не соответствует выбранной области.');
            }
            $property = is_array($properties[$sourceScope][$iblockId][$propertyId] ?? null)
                ? $properties[$sourceScope][$iblockId][$propertyId]
                : null;
            if ($property === null
                || (string)($property['scope'] ?? '') !== $sourceScope
                || (string)($property['code'] ?? '') !== $propertyCode
                || ($property['active'] ?? false) !== true) {
                throw new \InvalidArgumentException($path . '.source не соответствует активному свойству инфоблока.');
            }
            $propertyType = trim((string)($property['property_type'] ?? ''));
            $sourceMultiple = ($property['multiple'] ?? null) === true;
            if ($propertyType === '' || !is_bool($property['multiple'] ?? null)) {
                throw new \RuntimeException($path . '.source не содержит авторитетную форму значения свойства.', 409);
            }
            $enumXmlIds = [];
            foreach ((array)($property['enum_xml_ids'] ?? []) as $xmlId) {
                if (is_string($xmlId) && $xmlId !== '') {
                    $enumXmlIds[$xmlId] = true;
                }
            }
            $isEnum = $propertyType === 'L';
            if ($isEnum !== ($enumXmlIds !== [])) {
                throw new \RuntimeException($path . '.source содержит неполную enum provenance.', 409);
            }

            $valueMode = (string)$mapping['value_mode'];
            $targetMode = trim((string)($bindingModes[$fieldId] ?? ''));
            if ($targetMode === '') {
                $targetMode = $fieldType === 'checkbox'
                    ? 'boolean_yn'
                    : ($fieldType === 'dimensions' ? 'dimensions' : 'scalar');
            }
            if ($targetInputId !== '') {
                if ($fieldType !== 'dimensions' || $valueMode !== 'scalar' || $sourceMultiple || $isEnum) {
                    throw new \InvalidArgumentException(
                        $path . ' отдельный вход dimensions требует одиночный scalar источник.'
                    );
                }
            } elseif ($fieldType === 'dimensions') {
                if ($valueMode !== 'dimensions' || !$sourceMultiple || !$isEnum) {
                    throw new \InvalidArgumentException(
                        $path . ' поле dimensions целиком требует множественное enum-свойство и value_mode dimensions.'
                    );
                }
            } elseif ($fieldType === 'select') {
                $expectedMode = $sourceMultiple ? 'multiple' : 'scalar';
                if ($valueMode !== $expectedMode || $targetMode !== $expectedMode || !$isEnum) {
                    throw new \InvalidArgumentException(
                        $path . ' select требует enum-свойство и value_mode, совпадающий с multiplicity источника и binding.'
                    );
                }
            } elseif ($fieldType === 'checkbox') {
                if ($valueMode !== 'boolean_yn' || $targetMode !== 'boolean_yn' || $sourceMultiple) {
                    throw new \InvalidArgumentException(
                        $path . ' checkbox требует одиночный источник и value_mode boolean_yn.'
                    );
                }
            } elseif ($valueMode !== 'scalar' || $targetMode !== 'scalar' || $sourceMultiple || $isEnum) {
                throw new \InvalidArgumentException(
                    $path . ' number требует одиночный scalar источник без enum.'
                );
            }

            if (isset($mapping['option_map'])) {
                if ($enumXmlIds === [] || $optionIds === []) {
                    throw new \InvalidArgumentException($path . '.option_map требует enum-свойство и варианты формы.');
                }
                foreach ($mapping['option_map'] as $xmlId => $optionId) {
                    if (!isset($enumXmlIds[(string)$xmlId]) || !isset($optionIds[(string)$optionId])) {
                        throw new \InvalidArgumentException($path . '.option_map ссылается на неизвестный XML_ID или вариант формы.');
                    }
                }
            }
            if ($isEnum && in_array($fieldType, ['select', 'checkbox'], true)) {
                $optionMap = is_array($mapping['option_map'] ?? null) ? $mapping['option_map'] : [];
                if ($optionMap === []) {
                    throw new \InvalidArgumentException(
                        $path . '.option_map должен явно сопоставлять хотя бы один поддерживаемый XML_ID enum-свойства.'
                    );
                }
                foreach (array_keys($enumXmlIds) as $xmlId) {
                    if (!array_key_exists($xmlId, $optionMap)) {
                        $issues[] = [
                            'severity' => 'warning',
                            'code' => 'source_value_unmapped',
                            'path' => $path . '.option_map.' . $xmlId,
                            'message' => 'Значение источника ' . $xmlId
                                . ' не сопоставлено: оно не будет подставлено автоматически, поле останется для ручного выбора.',
                        ];
                    }
                }
            } elseif (isset($mapping['option_map'])) {
                throw new \InvalidArgumentException($path . '.option_map допустим только для enum -> select/checkbox.');
            }
            if (isset($mapping['input_map'])) {
                if ($enumXmlIds === [] || $inputIds === []) {
                    throw new \InvalidArgumentException($path . '.input_map требует enum-свойство и входы составного поля.');
                }
                foreach ($mapping['input_map'] as $xmlId => $inputId) {
                    if (!isset($enumXmlIds[(string)$xmlId]) || !isset($inputIds[(string)$inputId])) {
                        throw new \InvalidArgumentException($path . '.input_map ссылается на неизвестный XML_ID или вход формы.');
                    }
                }
                if (array_diff_key($enumXmlIds, $mapping['input_map']) !== []
                    || count(array_unique(array_values($mapping['input_map']))) !== count($mapping['input_map'])
                    || array_diff_key($inputIds, array_flip(array_values($mapping['input_map']))) !== []) {
                    throw new \InvalidArgumentException(
                        $path . '.input_map должен полностью и однозначно покрывать XML_ID свойства и входы dimensions.'
                    );
                }
            }
        }

        foreach ($fields as $fieldId => $field) {
            if (!isset($mappingsByField[$fieldId])) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'target_field_unmapped',
                    'path' => 'form.fields.' . $fieldId,
                    'message' => 'Поле формы «' . trim((string)($field['label'] ?? $fieldId))
                        . '» не имеет источника автоподстановки и заполняется пользователем вручную.',
                ];
            }
        }

        return $issues;
    }

    /** @return array<string,mixed> */
    private function semanticContext(int $presetId): array
    {
        if (isset($this->adapters['semantic_context'])) {
            $context = call_user_func($this->adapters['semantic_context'], $presetId);
            if (!is_array($context)) {
                throw new \RuntimeException('Input mapping semantic context adapter returned invalid data.');
            }
            return $context;
        }
        if (!Loader::includeModule('prospektweb.frontcalc')) {
            throw new \RuntimeException('Form module is unavailable for input mapping validation.', 409);
        }
        $storeClass = '\\Prospektweb\\Frontcalc\\Service\\FormFirstAuthoringStore';
        if (!class_exists($storeClass)) {
            throw new \RuntimeException('FrontCalc form-first store is unavailable.', 409);
        }
        $aggregate = (new $storeClass())->load($presetId);
        $form = is_array($aggregate['formDefinition'] ?? null) ? $aggregate['formDefinition'] : [];
        $bindingDefinition = is_array($aggregate['bindingDefinition'] ?? null)
            ? $aggregate['bindingDefinition']
            : [];
        $fields = [];
        foreach (is_array($form['fields'] ?? null) ? $form['fields'] : [] as $field) {
            $fieldId = is_array($field) ? trim((string)($field['fieldId'] ?? '')) : '';
            if ($fieldId !== '' && !isset($fields[$fieldId])) {
                $fields[$fieldId] = $field;
            }
        }
        $bindingModes = [];
        foreach (is_array($bindingDefinition['bindings'] ?? null) ? $bindingDefinition['bindings'] : [] as $binding) {
            $fieldId = is_array($binding) ? trim((string)($binding['fieldId'] ?? '')) : '';
            $valueMode = is_array($binding) ? trim((string)($binding['valueMode'] ?? '')) : '';
            if ($fieldId !== '' && $valueMode !== '' && !isset($bindingModes[$fieldId])) {
                $bindingModes[$fieldId] = $valueMode;
            }
        }
        $sourceAuthority = (new CalculatorInputSourceCatalogService())->validationAuthority($presetId);
        return [
            'fields' => $fields,
            'binding_modes' => $bindingModes,
            'product_iblock_id' => (int)$sourceAuthority['product_iblock_id'],
            'offer_iblock_id' => (int)$sourceAuthority['offer_iblock_id'],
            'properties' => $sourceAuthority['properties'],
        ];
    }

    /**
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    private function normalizeDefinition(int $presetId, array $definition): array
    {
        if ($this->isList($definition)) {
            throw new \InvalidArgumentException('Документ сопоставлений входов должен быть JSON-объектом.');
        }
        $this->assertAllowedKeys(
            $definition,
            ['contract', 'preset_id', 'revision', 'mappings'],
            'calculator_input_mapping'
        );
        if (($definition['contract'] ?? null) !== self::CONTRACT) {
            throw new \InvalidArgumentException('calculator_input_mapping.contract имеет неизвестную версию.');
        }
        $documentPresetId = $this->integer($definition['preset_id'] ?? null, 1, 'calculator_input_mapping.preset_id');
        if ($documentPresetId !== $presetId) {
            throw new \InvalidArgumentException('calculator_input_mapping.preset_id не совпадает с целевым пресетом.');
        }
        $revision = $this->integer($definition['revision'] ?? null, 0, 'calculator_input_mapping.revision');
        $mappings = $definition['mappings'] ?? null;
        if (!is_array($mappings) || !$this->isList($mappings)) {
            throw new \InvalidArgumentException('calculator_input_mapping.mappings должен быть JSON-массивом.');
        }
        if (count($mappings) > self::MAX_MAPPINGS) {
            throw new \InvalidArgumentException('calculator_input_mapping.mappings содержит более 200 элементов.');
        }

        $normalizedMappings = [];
        $targets = [];
        foreach ($mappings as $index => $mapping) {
            if (!is_array($mapping) || $this->isList($mapping)) {
                throw new \InvalidArgumentException('calculator_input_mapping.mappings[' . $index . '] должен быть JSON-объектом.');
            }
            $normalized = $this->normalizeMapping($mapping, $index);
            $targetKey = $normalized['target']['field_id'] . '|' . ($normalized['target']['input_id'] ?? '');
            if (isset($targets[$targetKey])) {
                throw new \InvalidArgumentException(
                    'Каждый вход поля формы может иметь только один источник: ' . $targetKey . '.'
                );
            }
            $targets[$targetKey] = true;
            $normalizedMappings[] = $normalized;
        }

        return [
            'contract' => self::CONTRACT,
            'preset_id' => $presetId,
            'revision' => $revision,
            'mappings' => $normalizedMappings,
        ];
    }

    /**
     * @param array<string,mixed> $mapping
     * @return array<string,mixed>
     */
    private function normalizeMapping(array $mapping, int $index): array
    {
        $path = 'calculator_input_mapping.mappings[' . $index . ']';
        $this->assertAllowedKeys(
            $mapping,
            ['target', 'source', 'value_mode', 'option_map', 'input_map'],
            $path
        );
        if (!is_array($mapping['target'] ?? null) || $this->isList($mapping['target'])) {
            throw new \InvalidArgumentException($path . '.target должен быть JSON-объектом.');
        }
        if (!is_array($mapping['source'] ?? null) || $this->isList($mapping['source'])) {
            throw new \InvalidArgumentException($path . '.source должен быть JSON-объектом.');
        }
        $valueMode = $this->strictString($mapping['value_mode'] ?? null, 1, 32, $path . '.value_mode');
        if (!in_array($valueMode, ['scalar', 'multiple', 'boolean_yn', 'dimensions'], true)) {
            throw new \InvalidArgumentException($path . '.value_mode не поддерживается.');
        }
        if ($valueMode === 'dimensions' && array_key_exists('option_map', $mapping)) {
            throw new \InvalidArgumentException($path . '.option_map недопустим для dimensions.');
        }
        if ($valueMode !== 'dimensions' && array_key_exists('input_map', $mapping)) {
            throw new \InvalidArgumentException($path . '.input_map допустим только для dimensions.');
        }

        $normalized = [
            'target' => $this->normalizeTarget($mapping['target'], $path . '.target'),
            'source' => $this->normalizeSource($mapping['source'], $path . '.source'),
            'value_mode' => $valueMode,
        ];
        if (array_key_exists('option_map', $mapping)) {
            $optionMap = $this->normalizeStringMap(
                $mapping['option_map'],
                self::MAX_OPTION_MAP_ENTRIES,
                160,
                160,
                $path . '.option_map'
            );
            if ($optionMap !== []) {
                $normalized['option_map'] = $optionMap;
            }
        }
        if (array_key_exists('input_map', $mapping)) {
            $inputMap = $this->normalizeStringMap(
                $mapping['input_map'],
                self::MAX_INPUT_MAP_ENTRIES,
                100,
                100,
                $path . '.input_map'
            );
            if ($inputMap !== []) {
                $normalized['input_map'] = $inputMap;
            }
        }
        return $normalized;
    }

    /** @param array<string,mixed> $target @return array<string,string> */
    private function normalizeTarget(array $target, string $path): array
    {
        $this->assertAllowedKeys($target, ['field_id', 'input_id'], $path);
        $normalized = ['field_id' => $this->stableFieldId($target['field_id'] ?? null, $path . '.field_id')];
        if (array_key_exists('input_id', $target)) {
            $normalized['input_id'] = $this->stableFieldId($target['input_id'], $path . '.input_id');
        }
        return $normalized;
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function normalizeSource(array $source, string $path): array
    {
        $this->assertAllowedKeys(
            $source,
            ['scope', 'iblock_id', 'property_id', 'property_code'],
            $path
        );
        $scope = $this->strictString($source['scope'] ?? null, 1, 32, $path . '.scope');
        if (!in_array($scope, ['product', 'selected_offer'], true)) {
            throw new \InvalidArgumentException($path . '.scope не поддерживается.');
        }
        $propertyCode = $this->strictString($source['property_code'] ?? null, 1, 100, $path . '.property_code');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $propertyCode) !== 1) {
            throw new \InvalidArgumentException($path . '.property_code имеет некорректный формат.');
        }
        return [
            'scope' => $scope,
            'iblock_id' => $this->integer($source['iblock_id'] ?? null, 1, $path . '.iblock_id'),
            'property_id' => $this->integer($source['property_id'] ?? null, 1, $path . '.property_id'),
            'property_code' => $propertyCode,
        ];
    }

    /** @param mixed $value @return array<string,string> */
    private function normalizeStringMap(
        $value,
        int $maxEntries,
        int $maxKeyLength,
        int $maxValueLength,
        string $path
    ): array {
        if (!is_array($value)) {
            throw new \InvalidArgumentException($path . ' должен быть JSON-объектом.');
        }
        // json_decode(..., true) cannot distinguish {} from []; an empty map is
        // semantically equivalent to an omitted optional map and is omitted.
        if ($value === []) {
            return [];
        }
        if ($this->isList($value) || count($value) > $maxEntries) {
            throw new \InvalidArgumentException($path . ' содержит некорректное количество элементов.');
        }
        $normalized = [];
        foreach ($value as $key => $rawValue) {
            // PHP converts numeric JSON object keys to integer array keys. They
            // remain valid catalog tokens (for example quantity XML_ID values).
            if (!is_string($key) && !is_int($key)) {
                throw new \InvalidArgumentException($path . ' содержит небезопасный ключ.');
            }
            $key = (string)$key;
            if ($this->isUnsafePathSegment($key)) {
                throw new \InvalidArgumentException($path . ' содержит небезопасный ключ.');
            }
            $parsedKey = $this->strictString($key, 1, $maxKeyLength, $path . '.' . $key);
            $normalized[$parsedKey] = $this->strictString(
                $rawValue,
                1,
                $maxValueLength,
                $path . '.' . $key
            );
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    /** @param mixed $value */
    private function stableFieldId($value, string $path): string
    {
        $fieldId = $this->strictString($value, 1, 100, $path);
        foreach (explode('.', $fieldId) as $segment) {
            if ($this->isUnsafePathSegment($segment)) {
                throw new \InvalidArgumentException($path . ' содержит небезопасный сегмент.');
            }
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $fieldId) !== 1) {
            throw new \InvalidArgumentException($path . ' имеет некорректный формат.');
        }
        return $fieldId;
    }

    /** @param mixed $value */
    private function strictString($value, int $minLength, int $maxLength, string $path): string
    {
        if (!is_string($value)
            || $this->stringLength($value) < $minLength
            || $this->stringLength($value) > $maxLength
            || $value !== trim($value)) {
            throw new \InvalidArgumentException($path . ' содержит некорректную строку.');
        }
        return $value;
    }

    private function stringLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int)mb_strlen($value, 'UTF-8');
        }
        $matched = preg_match_all('/./us', $value, $characters);
        return $matched === false ? strlen($value) : $matched;
    }

    /** @param mixed $value */
    private function integer($value, int $minimum, string $path): int
    {
        if (!is_int($value) || $value < $minimum || $value > self::MAX_SAFE_INTEGER) {
            throw new \InvalidArgumentException($path . ' должен быть безопасным целым числом не меньше ' . $minimum . '.');
        }
        return $value;
    }

    /** @param array<string,mixed> $value @param string[] $allowed */
    private function assertAllowedKeys(array $value, array $allowed, string $path): void
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException($path . ' содержит неизвестное поле.');
            }
        }
    }

    private function isUnsafePathSegment(string $value): bool
    {
        return in_array($value, ['__proto__', 'prototype', 'constructor'], true);
    }

    private function assertPresetId(int $presetId): void
    {
        if ($presetId <= 0 || $presetId > self::MAX_SAFE_INTEGER) {
            throw new \InvalidArgumentException('preset_id должен быть безопасным положительным целым числом.');
        }
    }

    private function assertRevision(int $revision): void
    {
        if ($revision < 0 || $revision > self::MAX_SAFE_INTEGER) {
            throw new \InvalidArgumentException('expected_revision должен быть безопасным неотрицательным целым числом.');
        }
    }

    /**
     * @param array<string,mixed> $definition
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    private function prepareNextDocument(
        int $presetId,
        int $expectedRevision,
        array $definition,
        array $current
    ): array {
        if ((int)$current['revision'] !== $expectedRevision) {
            throw new \RuntimeException(
                'Сопоставления входов уже изменены в другой сессии. Перезагрузите данные.',
                409
            );
        }
        $candidate = $this->normalizeDefinition($presetId, $definition);
        if ((int)$candidate['revision'] !== $expectedRevision) {
            throw new \RuntimeException('revision документа не совпадает с expected_revision.', 409);
        }
        if ($expectedRevision >= self::MAX_SAFE_INTEGER) {
            throw new \RuntimeException('Ревизия сопоставлений входов исчерпана.', 409);
        }
        $candidate['revision'] = $expectedRevision + 1;
        return $candidate;
    }

    private function assertStoredSize(string $raw): void
    {
        if (strlen($raw) > self::MAX_DOCUMENT_BYTES) {
            throw new \InvalidArgumentException('Сопоставления входов превышают допустимый размер.');
        }
    }

    /** @param mixed $value */
    public static function encodeCanonical($value): string
    {
        $encoded = json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($encoded)) {
            throw new \RuntimeException('Не удалось сериализовать сопоставления входов.');
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

    private function isList(array $value): bool
    {
        $keys = array_keys($value);
        return $keys === [] || $keys === range(0, count($keys) - 1);
    }

    private function optionName(int $presetId): string
    {
        return self::OPTION_PREFIX . $presetId;
    }

    private function getRaw(int $presetId): string
    {
        $name = $this->optionName($presetId);
        if (isset($this->adapters['get_option'])) {
            return (string)call_user_func($this->adapters['get_option'], $name, '');
        }
        if (!class_exists(Application::class)) {
            return '';
        }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $escapedName = $helper->forSql($name);
        $escapedModuleId = $helper->forSql(self::MODULE_ID);
        $rows = $connection->query(
            "SELECT MODULE_ID, NAME, VALUE FROM b_option WHERE MODULE_ID='" . $escapedModuleId
            . "' AND NAME='" . $escapedName . "' AND (SITE_ID IS NULL OR SITE_ID='') ORDER BY MODULE_ID, NAME"
        );
        $row = is_object($rows) && method_exists($rows, 'fetch') ? $rows->fetch() : null;
        $duplicate = is_object($rows) && method_exists($rows, 'fetch') ? $rows->fetch() : null;
        if (is_array($duplicate)) {
            throw new \RuntimeException('Обнаружены дублирующиеся строки сопоставлений входов.', 409);
        }
        if (!is_array($row)) {
            return '';
        }
        if ((string)($row['MODULE_ID'] ?? $row['module_id'] ?? '') !== self::MODULE_ID
            || (string)($row['NAME'] ?? $row['name'] ?? '') !== $name) {
            throw new \RuntimeException('Прочитана неожиданная строка сопоставлений входов.');
        }
        return (string)($row['VALUE'] ?? $row['value'] ?? '');
    }

    private function setRaw(int $presetId, string $raw): void
    {
        if (!isset($this->adapters['set_option'])) {
            throw new \LogicException('Direct input mapping writes must use the transactional CAS path.');
        }
        call_user_func($this->adapters['set_option'], $this->optionName($presetId), $raw);
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    private function saveDirectUnderCoordinatorTransaction(
        int $presetId,
        int $expectedRevision,
        array $definition
    ): array
    {
        if (!class_exists(Application::class)) {
            throw new \RuntimeException('Bitrix database connection is unavailable for input mapping CAS.');
        }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $name = $this->optionName($presetId);
        $escapedName = $helper->forSql($name);
        $escapedModuleId = $helper->forSql(self::MODULE_ID);
        $selectSql = "SELECT VALUE FROM b_option WHERE MODULE_ID='" . $escapedModuleId
            . "' AND NAME='" . $escapedName . "' AND (SITE_ID IS NULL OR SITE_ID='') FOR UPDATE";

        $rows = $connection->query($selectSql);
        $row = is_object($rows) && method_exists($rows, 'fetch') ? $rows->fetch() : null;
        $duplicate = is_object($rows) && method_exists($rows, 'fetch') ? $rows->fetch() : null;
        if (is_array($duplicate)) {
            throw new \RuntimeException('Обнаружены дублирующиеся строки сопоставлений входов.', 409);
        }
        $raw = is_array($row) ? (string)($row['VALUE'] ?? $row['value'] ?? '') : '';
        $current = $this->loadFromRaw($presetId, $raw);
        $candidate = $this->normalizeDefinition($presetId, $definition);
        $this->assertSemanticAuthority($presetId, $candidate);
        $stored = $this->prepareNextDocument($presetId, $expectedRevision, $definition, $current);
        $encoded = self::encodeCanonical($stored);
        $this->assertStoredSize($encoded);
        $escapedEncoded = $helper->forSql($encoded);
        if (is_array($row)) {
            $connection->queryExecute(
                "UPDATE b_option SET VALUE='" . $escapedEncoded . "' WHERE MODULE_ID='" . $escapedModuleId
                . "' AND NAME='" . $escapedName . "' AND (SITE_ID IS NULL OR SITE_ID='')"
            );
        } else {
            $connection->queryExecute(
                "INSERT INTO b_option (MODULE_ID, NAME, VALUE, SITE_ID) VALUES ('"
                . $escapedModuleId . "','" . $escapedName . "','" . $escapedEncoded . "',NULL)"
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
            throw new \RuntimeException('Контрольное чтение сопоставлений входов отсутствует или неоднозначно.');
        }
        $readBack = $this->loadFromRaw(
            $presetId,
            (string)($readBackRow['VALUE'] ?? $readBackRow['value'] ?? '')
        );
        if ($readBack !== $stored) {
            throw new \RuntimeException('Контрольное чтение сопоставлений входов не совпало с записью.');
        }
        return $readBack;
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
            throw new \RuntimeException('Не удалось создать каталог блокировки сопоставлений входов.');
        }
        $path = rtrim($directory, '/\\') . '/prospektweb.calc.calculator-input-mapping.' . $presetId . '.lock';
        $handle = @fopen($path, 'c');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Не удалось открыть блокировку сопоставлений входов.');
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
        throw new \RuntimeException('Хранилище сопоставлений входов занято. Повторите операцию.');
    }
}
