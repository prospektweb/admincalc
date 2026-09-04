<?php

namespace Prospektweb\Calc\Services;

/**
 * Canonical preset-form input mapping used to select a stage entity variant.
 *
 * The document deliberately contains no Bitrix product/offer property codes.
 * OPTIONS_OPERATION, OPTIONS_MATERIAL and OPTIONS_EQUIPMENT are only storage
 * slots; the owning property determines the target entity type.
 */
final class StageVariantMappingService
{
    public const CONTRACT = 'prospektweb.calc.stage-variant-mapping/v1';
    public const MATERIAL_DECISION_TREE_CONTRACT = 'prospektweb.calc.stage-material-selection/v4';

    private const MAX_SAFE_INTEGER = 9007199254740991;
    private const MAX_FIELD_IDS = 50;
    private const MAX_METRIC_KEYS = 50;
    private const MAX_RULES = 500;
    private const MAX_OPTION_ID_BYTES = 250;
    private const MAX_DOCUMENT_BYTES = 1048576;

    /** @var string[] */
    private const DOCUMENT_KEYS = [
        'contract',
        'input_field_ids',
        'metric_source',
        'metric_keys',
        'rules',
    ];

    /** @var string[] */
    private const RULE_KEYS = ['input_values', 'metric_ranges', 'variant_id'];

    /**
     * Validate and canonicalize the JSON written to an OPTIONS_* stage property.
     * An empty string is the only supported representation for "no mapping".
     */
    public function normalizeJson(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        if (trim($raw) === '') {
            throw new \InvalidArgumentException('Stage variant mapping must be canonical JSON or an empty string.');
        }
        if (strlen($raw) > self::MAX_DOCUMENT_BYTES) {
            throw new \InvalidArgumentException('Stage variant mapping exceeds the maximum document size.');
        }

        $decodedNode = json_decode($raw);
        if (!$decodedNode instanceof \stdClass) {
            throw new \InvalidArgumentException('Stage variant mapping must be a JSON object.');
        }
        if (($decodedNode->contract ?? null) !== self::CONTRACT) {
            throw new \InvalidArgumentException('Unsupported stage variant mapping contract.');
        }
        $this->assertJsonNodeShape($decodedNode);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || self::isList($decoded)) {
            throw new \InvalidArgumentException('Stage variant mapping must be a JSON object.');
        }

        return self::encodeCanonical($this->normalize($decoded));
    }

    /** Validate an OPTIONS_MATERIAL value. It supports both the ordered rule
     *  mapping used by operations and the dedicated material decision tree. */
    public function normalizeMaterialJson(string $raw): string
    {
        if ($raw === '') return '';
        if (trim($raw) === '' || strlen($raw) > self::MAX_DOCUMENT_BYTES) {
            throw new \InvalidArgumentException('Material decision tree must be canonical JSON or an empty string.');
        }
        $decodedNode = json_decode($raw);
        if ($decodedNode instanceof \stdClass && ($decodedNode->contract ?? null) === self::CONTRACT) {
            return $this->normalizeJson($raw);
        }
        if (!$decodedNode instanceof \stdClass
            || ($decodedNode->contract ?? null) !== self::MATERIAL_DECISION_TREE_CONTRACT
            || !($decodedNode->tree ?? null) instanceof \stdClass) {
            throw new \InvalidArgumentException(
                'OPTIONS_MATERIAL supports only ' . self::CONTRACT . ' or ' . self::MATERIAL_DECISION_TREE_CONTRACT . '.'
            );
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || self::isList($decoded)) {
            throw new \InvalidArgumentException('Material decision tree must be a JSON object.');
        }
        return self::encodeCanonical($this->normalize($decoded));
    }

    /** @param array<string,mixed> $document */
    public function encode(array $document): string
    {
        return self::encodeCanonical($this->normalize($document));
    }

    /** @param array<string,mixed> $document
     *  @return array<string,mixed>
     */
    public function normalize(array $document): array
    {
        if (($document['contract'] ?? null) === self::MATERIAL_DECISION_TREE_CONTRACT) {
            return $this->normalizeMaterialDecisionTree($document);
        }
        self::assertExactKeys($document, self::DOCUMENT_KEYS, 'mapping');
        if (($document['contract'] ?? null) !== self::CONTRACT) {
            throw new \InvalidArgumentException('Unsupported stage variant mapping contract.');
        }

        $inputFieldIds = self::normalizeIdentifierList(
            $document['input_field_ids'],
            self::MAX_FIELD_IDS,
            'input_field_ids',
            static fn(string $value): bool => preg_match('/^(?:[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*|global\.(?:constant|variable)\.[A-Za-z_][A-Za-z0-9_]*)$/D', $value) === 1
        );
        $metricKeys = self::normalizeIdentifierList(
            $document['metric_keys'],
            self::MAX_METRIC_KEYS,
            'metric_keys',
            static fn(string $value): bool => in_array($value, ['width', 'length', 'height', 'weight'], true)
                || preg_match('/^output:[A-Za-z0-9_.-]+$/D', $value) === 1
        );
        if ($inputFieldIds === [] && $metricKeys === []) {
            throw new \InvalidArgumentException(
                'Stage variant mapping must contain at least one semantic input or metric criterion.'
            );
        }

        $metricSource = $document['metric_source'];
        if ($metricKeys === []) {
            if ($metricSource !== null) {
                throw new \InvalidArgumentException('metric_source must be null when metric_keys is empty.');
            }
        } else {
            if (!is_array($metricSource) || self::isList($metricSource)) {
                throw new \InvalidArgumentException('metric_source is required when metric_keys is not empty.');
            }
            self::assertExactKeys($metricSource, ['detail_id', 'stage_id'], 'metric_source');
            $metricSource = [
                'detail_id' => self::positiveSafeInteger($metricSource['detail_id'], 'metric_source.detail_id'),
                'stage_id' => self::positiveSafeInteger($metricSource['stage_id'], 'metric_source.stage_id'),
            ];
        }

        $rules = $document['rules'];
        if (!is_array($rules) || !self::isList($rules) || $rules === [] || count($rules) > self::MAX_RULES) {
            throw new \InvalidArgumentException('rules must contain between 1 and 500 entries.');
        }

        $normalizedRules = [];
        foreach ($rules as $index => $rule) {
            $path = 'rules[' . $index . ']';
            if (!is_array($rule) || self::isList($rule)) {
                throw new \InvalidArgumentException($path . ' must be an object.');
            }
            self::assertExactKeys($rule, self::RULE_KEYS, $path);

            $inputValues = $rule['input_values'];
            if (!is_array($inputValues) || (self::isList($inputValues) && $inputValues !== [])) {
                throw new \InvalidArgumentException($path . '.input_values must be an object.');
            }
            self::assertSameKeySet($inputValues, $inputFieldIds, $path . '.input_values');
            $normalizedInputValues = [];
            foreach ($inputFieldIds as $fieldId) {
                $value = $inputValues[$fieldId];
                if (!is_string($value) || $value === '' || strlen($value) > self::MAX_OPTION_ID_BYTES) {
                    throw new \InvalidArgumentException(
                        $path . '.input_values.' . $fieldId . ' must be a non-empty option ID up to 250 bytes.'
                    );
                }
                $normalizedInputValues[$fieldId] = $value;
            }

            $metricRanges = $rule['metric_ranges'];
            if (!is_array($metricRanges) || (self::isList($metricRanges) && $metricRanges !== [])) {
                throw new \InvalidArgumentException($path . '.metric_ranges must be an object.');
            }
            self::assertSameKeySet($metricRanges, $metricKeys, $path . '.metric_ranges');
            $normalizedMetricRanges = [];
            foreach ($metricKeys as $metricKey) {
                $range = $metricRanges[$metricKey];
                if (!is_array($range) || self::isList($range)) {
                    throw new \InvalidArgumentException($path . '.metric_ranges.' . $metricKey . ' must be an object.');
                }
                self::assertExactKeys($range, ['min', 'max'], $path . '.metric_ranges.' . $metricKey);
                $min = self::finiteNumber($range['min'], $path . '.metric_ranges.' . $metricKey . '.min');
                $max = self::finiteNumber($range['max'], $path . '.metric_ranges.' . $metricKey . '.max');
                if ($min < 0 || $min > $max) {
                    throw new \InvalidArgumentException(
                        $path . '.metric_ranges.' . $metricKey . ' must satisfy 0 <= min <= max.'
                    );
                }
                $normalizedMetricRanges[$metricKey] = ['min' => $min, 'max' => $max];
            }

            $normalizedRules[] = [
                'input_values' => $normalizedInputValues,
                'metric_ranges' => $normalizedMetricRanges,
                'variant_id' => self::positiveSafeInteger($rule['variant_id'], $path . '.variant_id'),
            ];
        }

        return [
            'contract' => self::CONTRACT,
            'input_field_ids' => $inputFieldIds,
            'metric_source' => $metricSource,
            'metric_keys' => $metricKeys,
            'rules' => $normalizedRules,
        ];
    }

    /** @return int[] */
    public function variantIdsFromJson(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($this->normalizeJson($raw), true);
        $ids = [];
        if (($decoded['contract'] ?? null) === self::MATERIAL_DECISION_TREE_CONTRACT) {
            foreach ($this->materialReferencesFromTree((array)($decoded['tree'] ?? [])) as $reference) {
                if ($reference['entity_type'] === 'variant') $ids[$reference['entity_id']] = true;
            }
        } else {
            foreach ((array)($decoded['rules'] ?? []) as $rule) {
                $ids[(int)$rule['variant_id']] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /** @return array<int,array{entity_type:string,entity_id:int}> */
    public function materialReferencesFromJson(string $raw): array
    {
        if ($raw === '') return [];
        $decoded = json_decode($this->normalizeMaterialJson($raw), true);
        if (($decoded['contract'] ?? null) === self::CONTRACT) {
            return array_map(
                static fn(int $id): array => ['entity_type' => 'variant', 'entity_id' => $id],
                $this->variantIdsFromJson($raw)
            );
        }
        return $this->materialReferencesFromTree((array)($decoded['tree'] ?? []));
    }

    /**
     * Fail closed when a persisted rule references a field, option or scalar
     * global that does not belong to the current preset authoring document.
     *
     * @param array<int,array<string,mixed>> $formFields
     * @param array<int,array<string,mixed>> $globalSymbols
     */
    public function assertSemanticSources(string $raw, array $formFields, array $globalSymbols): void
    {
        if ($raw === '') return;
        $mapping = json_decode($this->normalizeJson($raw), true);
        $formOptions = [];
        foreach ($formFields as $field) {
            $fieldId = is_array($field) ? (string)($field['fieldId'] ?? '') : '';
            $options = is_array($field['options'] ?? null) ? $field['options'] : [];
            if ($fieldId === '' || $options === []) continue;
            $formOptions[$fieldId] = array_fill_keys(array_values(array_filter(
                array_map(static fn($option): string => is_array($option) ? (string)($option['id'] ?? '') : '', $options),
                static fn(string $optionId): bool => $optionId !== ''
            )), true);
        }
        $globals = [];
        foreach ($globalSymbols as $symbol) {
            if (!is_array($symbol)) continue;
            $kind = (string)($symbol['kind'] ?? '');
            $code = (string)($symbol['code'] ?? '');
            $dataType = (string)($symbol['dataType'] ?? 'auto');
            if (!in_array($kind, ['constant', 'variable'], true)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $code) !== 1
                || in_array($dataType, ['array', 'object'], true)) continue;
            $globals['global.' . $kind . '.' . $code] = $dataType;
        }
        foreach ((array)$mapping['input_field_ids'] as $fieldId) {
            if (isset($formOptions[$fieldId])) {
                foreach ((array)$mapping['rules'] as $rule) {
                    if (!isset($formOptions[$fieldId][(string)$rule['input_values'][$fieldId]])) {
                        throw new \InvalidArgumentException('Stage variant mapping references a missing form option.');
                    }
                }
                continue;
            }
            if (isset($globals[$fieldId])) {
                if ($globals[$fieldId] === 'boolean') {
                    foreach ((array)$mapping['rules'] as $rule) {
                        if (!in_array((string)$rule['input_values'][$fieldId], ['true', 'false'], true)) {
                            throw new \InvalidArgumentException('Stage variant mapping contains an invalid boolean global value.');
                        }
                    }
                }
                continue;
            }
            throw new \InvalidArgumentException('Stage variant mapping references a missing semantic source.');
        }
    }

    /** @param array<string,mixed> $document
     *  @return array<string,mixed>
     */
    private function normalizeMaterialDecisionTree(array $document): array
    {
        self::assertExactKeys($document, ['contract', 'tree'], 'mapping');
        $nodes = 0;
        return [
            'contract' => self::MATERIAL_DECISION_TREE_CONTRACT,
            'tree' => $this->normalizeMaterialDecisionNode($document['tree'], 'tree', 1, $nodes, []),
        ];
    }

    /** @param mixed $node
     *  @param array<string,bool> $sourcePath
     *  @return array<string,mixed>
     */
    private function normalizeMaterialDecisionNode($node, string $path, int $depth, int &$nodes, array $sourcePath): array
    {
        $nodes++;
        if ($nodes > 2000) throw new \InvalidArgumentException('Material decision tree contains too many nodes.');
        if ($depth > 12) throw new \InvalidArgumentException('Material decision tree exceeds maximum depth.');
        if (!is_array($node) || self::isList($node)) throw new \InvalidArgumentException($path . ' must be an object.');
        $kind = (string)($node['kind'] ?? '');
        if ($kind === 'result') {
            self::assertExactKeys($node, ['kind', 'result', 'resolution'], $path);
            $resolution = (string)$node['resolution'];
            if ($resolution !== 'manual') throw new \InvalidArgumentException($path . '.resolution is invalid.');
            return [
                'kind' => 'result',
                'result' => self::normalizeMaterialReference($node['result'], $path . '.result'),
                'resolution' => $resolution,
            ];
        }
        if ($kind !== 'condition') throw new \InvalidArgumentException($path . '.kind is invalid.');
        self::assertExactKeys($node, ['kind', 'source', 'branches'], $path);
        $source = $node['source'];
        if (!is_array($source) || self::isList($source)) throw new \InvalidArgumentException($path . '.source must be an object.');
        self::assertExactKeys($source, ['kind', 'field_id'], $path . '.source');
        $fieldId = (string)($source['field_id'] ?? '');
        if (($source['kind'] ?? null) !== 'form_field' || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $fieldId) !== 1) {
            throw new \InvalidArgumentException($path . '.source is invalid.');
        }
        if (isset($sourcePath[$fieldId])) throw new \InvalidArgumentException($path . ' repeats source field ' . $fieldId . '.');
        $sourcePath[$fieldId] = true;

        $branches = $node['branches'];
        if (!is_array($branches) || !self::isList($branches) || $branches === [] || count($branches) > self::MAX_RULES) {
            throw new \InvalidArgumentException($path . '.branches must contain between 1 and 500 entries.');
        }
        $seen = [];
        $normalizedBranches = [];
        foreach ($branches as $index => $branch) {
            $branchPath = $path . '.branches[' . $index . ']';
            if (!is_array($branch) || self::isList($branch)) throw new \InvalidArgumentException($branchPath . ' must be an object.');
            self::assertExactKeys($branch, ['option_id', 'child'], $branchPath);
            $optionId = (string)$branch['option_id'];
            if (trim($optionId) === '' || strlen($optionId) > self::MAX_OPTION_ID_BYTES) throw new \InvalidArgumentException($branchPath . '.option_id is invalid.');
            if (isset($seen[$optionId])) throw new \InvalidArgumentException($path . '.branches contains duplicate ' . $optionId . '.');
            $seen[$optionId] = true;
            $normalizedBranches[] = [
                'option_id' => $optionId,
                'child' => $this->normalizeMaterialDecisionNode($branch['child'], $branchPath . '.child', $depth + 1, $nodes, $sourcePath),
            ];
        }
        return [
            'kind' => 'condition',
            'source' => ['kind' => 'form_field', 'field_id' => $fieldId],
            'branches' => $normalizedBranches,
        ];
    }

    /** @param array<string,mixed> $tree
     *  @return array<int,array{entity_type:string,entity_id:int}>
     */
    private function materialReferencesFromTree(array $tree): array
    {
        $references = [];
        $visit = function (array $node) use (&$visit, &$references): void {
            if (($node['kind'] ?? null) === 'result') {
                $reference = (array)($node['result'] ?? []);
                $key = ($reference['entity_type'] ?? '') . ':' . (int)($reference['entity_id'] ?? 0);
                if (($reference['entity_id'] ?? 0) > 0) $references[$key] = $reference;
                return;
            }
            foreach ((array)($node['branches'] ?? []) as $branch) $visit((array)($branch['child'] ?? []));
        };
        $visit($tree);
        return array_values($references);
    }

    /** @param mixed $reference
     *  @return array{entity_type:string,entity_id:int}
     */
    private static function normalizeMaterialReference($reference, string $path): array
    {
        if (!is_array($reference) || self::isList($reference)) throw new \InvalidArgumentException($path . ' must be an object.');
        self::assertExactKeys($reference, ['entity_type', 'entity_id'], $path);
        $type = (string)$reference['entity_type'];
        if (!in_array($type, ['material', 'variant', 'operation', 'equipment', 'calculator'], true)) throw new \InvalidArgumentException($path . '.entity_type is invalid.');
        return ['entity_type' => $type, 'entity_id' => self::positiveSafeInteger($reference['entity_id'], $path . '.entity_id')];
    }

    /** @param mixed $value */
    private static function positiveSafeInteger($value, string $path): int
    {
        if (!is_int($value) || $value <= 0 || $value > self::MAX_SAFE_INTEGER) {
            throw new \InvalidArgumentException($path . ' must be a positive safe integer.');
        }
        return $value;
    }

    /** @param mixed $value */
    private static function finiteNumber($value, string $path)
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
            throw new \InvalidArgumentException($path . ' must be a finite number.');
        }
        return $value;
    }

    /** @param mixed $value
     *  @return string[]
     */
    private static function normalizeIdentifierList($value, int $maximum, string $path, callable $accept): array
    {
        if (!is_array($value) || !self::isList($value) || count($value) > $maximum) {
            throw new \InvalidArgumentException($path . ' must be a list with at most ' . $maximum . ' entries.');
        }
        $seen = [];
        $result = [];
        foreach ($value as $index => $identifier) {
            if (!is_string($identifier) || $identifier === '' || !$accept($identifier)) {
                throw new \InvalidArgumentException($path . '[' . $index . '] is invalid.');
            }
            if (isset($seen[$identifier])) {
                throw new \InvalidArgumentException($path . ' contains duplicate identifier ' . $identifier . '.');
            }
            $seen[$identifier] = true;
            $result[] = $identifier;
        }
        return $result;
    }

    /** @param array<string,mixed> $actual
     *  @param string[] $expected
     */
    private static function assertExactKeys(array $actual, array $expected, string $path): void
    {
        $actualKeys = array_keys($actual);
        sort($actualKeys, SORT_STRING);
        $expectedKeys = $expected;
        sort($expectedKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            throw new \InvalidArgumentException($path . ' must contain exact keys: ' . implode(', ', $expected) . '.');
        }
    }

    /** @param array<string,mixed> $actual
     *  @param string[] $expectedKeys
     */
    private static function assertSameKeySet(array $actual, array $expectedKeys, string $path): void
    {
        $actualKeys = array_keys($actual);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            throw new \InvalidArgumentException($path . ' keys must exactly match the declared identifiers.');
        }
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /** @param array<string,mixed> $value */
    private static function encodeCanonical(array $value): string
    {
        // PHP associative decoding represents both {} and [] as an empty
        // array. These two rule members are always JSON objects, including
        // when their declared key set is empty.
        foreach (($value['rules'] ?? []) as $index => $rule) {
            if ($rule['input_values'] === []) {
                $value['rules'][$index]['input_values'] = new \stdClass();
            }
            if ($rule['metric_ranges'] === []) {
                $value['rules'][$index]['metric_ranges'] = new \stdClass();
            }
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to encode stage variant mapping.');
        }
        if (strlen($encoded) > self::MAX_DOCUMENT_BYTES) {
            throw new \InvalidArgumentException('Stage variant mapping exceeds the maximum document size.');
        }
        return $encoded;
    }

    /** @param mixed $node */
    private function assertJsonNodeShape($node): void
    {
        if (!$node instanceof \stdClass
            || !is_array($node->input_field_ids ?? null)
            || !is_array($node->metric_keys ?? null)
            || !is_array($node->rules ?? null)) {
            throw new \InvalidArgumentException('Stage variant mapping contains an invalid JSON object/list shape.');
        }
        if (($node->metric_source ?? null) !== null && !($node->metric_source instanceof \stdClass)) {
            throw new \InvalidArgumentException('metric_source must be a JSON object or null.');
        }
        foreach ($node->rules as $index => $rule) {
            if (!$rule instanceof \stdClass
                || !($rule->input_values ?? null) instanceof \stdClass
                || !($rule->metric_ranges ?? null) instanceof \stdClass) {
                throw new \InvalidArgumentException(
                    'rules[' . $index . '] input_values and metric_ranges must be JSON objects.'
                );
            }
            foreach (get_object_vars($rule->metric_ranges) as $metricKey => $range) {
                if (!$range instanceof \stdClass) {
                    throw new \InvalidArgumentException(
                        'rules[' . $index . '].metric_ranges.' . $metricKey . ' must be a JSON object.'
                    );
                }
            }
        }
    }
}
