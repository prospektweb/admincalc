<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;

/** Builds automated catalog scenarios from published form defaults + explicit input mappings. */
final class CatalogCalculationScenarioService
{
    public const CONTRACT = 'prospektweb.calc.catalog-scenario/v2';

    /** @var callable */
    private $mappingLoader;

    /** @var callable */
    private $prefillResolverFactory;

    public function __construct(array $adapters = [])
    {
        $this->mappingLoader = $adapters['mapping'] ?? static fn(int $presetId): array =>
            (new CalculatorInputMappingService())->load($presetId);
        $this->prefillResolverFactory = $adapters['prefill_resolver'] ?? static function (array $mapping) {
            if (!Loader::includeModule('prospektweb.frontcalc')) {
                throw new \RuntimeException('The prospektweb.frontcalc module is required for catalog input mapping.');
            }
            $class = '\\Prospektweb\\Frontcalc\\Service\\CatalogInputPrefillResolver';
            if (!class_exists($class)) {
                throw new \RuntimeException('FrontCalc catalog input prefill resolver is unavailable.');
            }
            return new $class(['mapping' => static fn(int $presetId): array => $mapping]);
        };
    }

    /** @return array<string,mixed> */
    public function preview(
        int $presetId,
        array $offers,
        array $publishedAuthoring,
        array $runtimeSchema,
        ?array $mapping = null
    ): array {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('preset_id must be positive.');
        }
        $mapping = $mapping ?? call_user_func($this->mappingLoader, $presetId);
        if (!is_array($mapping)
            || (string)($mapping['contract'] ?? '') !== CalculatorInputMappingService::CONTRACT
            || (int)($mapping['preset_id'] ?? 0) !== $presetId
            || !is_int($mapping['revision'] ?? null)
            || !is_array($mapping['mappings'] ?? null)) {
            throw new \RuntimeException('Calculator input mapping is absent or invalid.', 409);
        }
        $form = is_array($publishedAuthoring['formDefinition'] ?? null)
            ? $publishedAuthoring['formDefinition']
            : [];
        $bindings = is_array($publishedAuthoring['bindingDefinition'] ?? null)
            ? $publishedAuthoring['bindingDefinition']
            : [];
        $publication = is_array($publishedAuthoring['publication'] ?? null)
            ? $publishedAuthoring['publication']
            : [];
        if ((int)($form['presetId'] ?? 0) !== $presetId
            || (int)($bindings['presetId'] ?? 0) !== $presetId
            || (int)($publication['revision'] ?? 0) <= 0
            || preg_match('/^[a-f0-9]{64}$/D', (string)($publication['compileHash'] ?? '')) !== 1) {
            throw new \RuntimeException('Published preset form is absent or invalid.', 409);
        }

        $fields = [];
        foreach (is_array($form['fields'] ?? null) ? $form['fields'] : [] as $field) {
            $fieldId = is_array($field) ? trim((string)($field['fieldId'] ?? '')) : '';
            if ($fieldId === '' || isset($fields[$fieldId])) {
                throw new \RuntimeException('Published form contains an invalid or duplicate field.');
            }
            $fields[$fieldId] = $field;
        }
        $volumeFieldId = '';
        foreach (is_array($bindings['bindings'] ?? null) ? $bindings['bindings'] : [] as $binding) {
            if (!is_array($binding) || !is_array($binding['target'] ?? null)) {
                continue;
            }
            if ((string)($binding['target']['kind'] ?? '') === 'property'
                && (string)($binding['target']['propertyCode'] ?? '') === 'CALC_PROP_VOLUME') {
                $volumeFieldId = (string)($binding['fieldId'] ?? '');
            }
        }
        if ($volumeFieldId === '' || !isset($fields[$volumeFieldId])) {
            throw new \RuntimeException('Published form does not bind the calculation quantity field.', 409);
        }

        $resolver = call_user_func($this->prefillResolverFactory, $mapping);
        if (!is_object($resolver) || !is_callable([$resolver, 'resolve'])) {
            throw new \RuntimeException('Catalog input prefill resolver is invalid.');
        }
        $scenarios = [];
        $errors = [];
        foreach ($offers as $offer) {
            $offerId = is_array($offer) ? (int)($offer['id'] ?? 0) : 0;
            $productId = is_array($offer) ? (int)($offer['productId'] ?? 0) : 0;
            if ($offerId <= 0 || $productId <= 0) {
                $errors[] = ['offerId' => $offerId, 'message' => 'Торговое предложение не связано с товаром.'];
                continue;
            }
            try {
                $prefill = $resolver->resolve($presetId, $productId, $offerId, $publishedAuthoring, $runtimeSchema);
                $values = [];
                foreach ($fields as $fieldId => $field) {
                    if (array_key_exists('defaultValue', $field)
                        && !$this->isEmptyValue($field['defaultValue'])) {
                        $values[$fieldId] = $field['defaultValue'];
                    }
                }
                foreach ((array)($prefill['values_by_field'] ?? []) as $fieldId => $value) {
                    if (is_string($fieldId) && isset($fields[$fieldId]) && !$this->isEmptyValue($value)) {
                        $values[$fieldId] = $value;
                    }
                }
                $missing = [];
                foreach ($fields as $fieldId => $field) {
                    if (($field['required'] ?? false) === true
                        && (!array_key_exists($fieldId, $values) || $this->isEmptyValue($values[$fieldId]))) {
                        $missing[] = $fieldId;
                    }
                }
                if ($missing !== []) {
                    throw new \RuntimeException('Не заполнены обязательные входы: ' . implode(', ', $missing) . '.');
                }
                $quantity = $values[$volumeFieldId] ?? null;
                if (!is_int($quantity)
                    && !(is_string($quantity) && preg_match('/^[1-9][0-9]*$/D', $quantity))) {
                    throw new \RuntimeException('Не задан корректный тираж для выбора результата.');
                }
                $quantity = (int)$quantity;
                if ($quantity <= 0) {
                    throw new \RuntimeException('Не задан корректный тираж для выбора результата.');
                }
                ksort($values, SORT_STRING);
                $scenarios[] = [
                    'contract' => self::CONTRACT,
                    'scenarioId' => 'offer:' . $offerId,
                    'presetId' => $presetId,
                    'source' => 'catalog-input-mapping',
                    'publicationRevision' => (int)$publication['revision'],
                    'publicationCompileHash' => (string)$publication['compileHash'],
                    'inputMappingRevision' => (int)$mapping['revision'],
                    'target' => [
                        'productId' => $productId,
                        'offerId' => $offerId,
                        'name' => trim((string)($offer['name'] ?? '')) ?: ('ТП #' . $offerId),
                    ],
                    'quantity' => $quantity,
                    'values' => $values,
                ];
            } catch (\Throwable $error) {
                $errors[] = ['offerId' => $offerId, 'message' => $error->getMessage()];
            }
        }
        return [
            'ready' => count($scenarios) === count($offers) && $errors === [],
            'hasTargets' => $offers !== [],
            'revision' => (int)$mapping['revision'],
            'scenarios' => $scenarios,
            'errors' => $errors,
        ];
    }

    /** @param mixed $value */
    private function isEmptyValue($value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
