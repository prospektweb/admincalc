<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

/**
 * CAS boundary for one non-form component inside a complete version bundle.
 *
 * Editors never receive authority to replace the manifest or another
 * component. The aggregate hash and the selected component hash must both
 * match the last read before a draft mutation is committed.
 */
final class CalculatorVersionComponentDocumentService
{
    public const CONTRACT = 'prospektweb.calc.calculator-version-component/v1';

    private const EDITABLE_COMPONENTS = [
        'logic',
        'storefronts',
        'inputMappings',
        'outputMappings',
        'productAssignments',
        'commercialPolicy',
    ];

    private CalculatorVersionBundleDocumentService $bundles;

    public function __construct(?CalculatorVersionBundleDocumentService $bundles = null)
    {
        $this->bundles = $bundles ?? new CalculatorVersionBundleDocumentService();
    }

    /** @return array<string,mixed> */
    public function load(int $presetId, string $versionId, string $component): array
    {
        $this->assertComponent($component);
        $bundle = $this->bundles->load($presetId, $versionId);
        if ($bundle === null) {
            throw new \RuntimeException('Полный снимок выбранной версии отсутствует.', 409);
        }
        return $this->response($bundle, $component);
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    public function saveDraft(
        int $presetId,
        string $versionId,
        string $component,
        string $expectedContentHash,
        string $expectedComponentHash,
        array $document
    ): array {
        $this->assertComponent($component);
        $this->assertSha256($expectedContentHash, 'expectedContentHash');
        $this->assertSha256($expectedComponentHash, 'expectedComponentHash');
        $bundle = $this->bundles->load($presetId, $versionId);
        if ($bundle === null) {
            throw new \RuntimeException('Полный снимок выбранной версии отсутствует.', 409);
        }
        if (!hash_equals((string)$bundle['contentHash'], $expectedContentHash)
            || !hash_equals((string)$bundle['componentHashes'][$component], $expectedComponentHash)) {
            throw new \RuntimeException(
                'Компонент версии изменён в другой вкладке. Загрузите актуальный снимок и повторите изменение.',
                409
            );
        }
        $this->assertDocument($presetId, $component, $document, $bundle['documents']);
        $components = $bundle['documents'];
        $components[$component] = $document;
        $saved = $this->bundles->save($presetId, $versionId, $components);
        return $this->response($saved, $component);
    }

    /** @param array<string,mixed> $bundle @return array<string,mixed> */
    private function response(array $bundle, string $component): array
    {
        return [
            'contract' => self::CONTRACT,
            'presetId' => (int)$bundle['presetId'],
            'versionId' => (string)$bundle['versionId'],
            'component' => $component,
            'contentHash' => (string)$bundle['contentHash'],
            'componentHash' => (string)$bundle['componentHashes'][$component],
            'updatedAt' => (string)$bundle['updatedAt'],
            'document' => $bundle['documents'][$component],
        ];
    }

    private function assertComponent(string $component): void
    {
        if (!in_array($component, self::EDITABLE_COMPONENTS, true)) {
            throw new \InvalidArgumentException('Компонент версии не поддерживает отдельное редактирование.');
        }
    }

    /** @param array<string,mixed> $document */
    private function assertDocument(int $presetId, string $component, array $document, array $bundleDocuments = []): void
    {
        $contract = (string)($document['contract'] ?? '');
        $documentPresetId = (int)($document['presetId'] ?? $document['preset_id'] ?? 0);
        $expectedContract = [
            'logic' => CalculatorVersionSnapshotSourceService::LOGIC_CONTRACT,
            'storefronts' => 'prospektweb.frontcalc.storefront-definition/v2',
            'inputMappings' => CalculatorInputMappingService::CONTRACT,
            'outputMappings' => CatalogOutputMappingService::CONTRACT,
            'productAssignments' => CalculatorVersionSnapshotSourceService::PRODUCT_ASSIGNMENTS_CONTRACT,
            'commercialPolicy' => CalculatorVersionSnapshotSourceService::COMMERCIAL_POLICY_CONTRACT,
        ][$component];
        if ($contract !== $expectedContract || $documentPresetId !== $presetId) {
            throw new \InvalidArgumentException('Документ компонента не соответствует выбранной версии калькулятора.');
        }
        if ($component === 'storefronts'
            && (!is_array($document['items'] ?? null)
                || (array_key_exists('base_public', $document) && !is_bool($document['base_public'])))) {
            throw new \InvalidArgumentException('Снимок витрин должен содержать items и настройку базовой публичной витрины.');
        }
        if ($component === 'productAssignments') {
            $assignments = $document['assignments'] ?? null;
            if (!is_array($assignments) || !array_is_list($assignments)) {
                throw new \InvalidArgumentException('Снимок товарных назначений должен содержать список assignments.');
            }
            $knownStorefronts = ['BASE' => true];
            foreach ((array)($bundleDocuments['storefronts']['items'] ?? []) as $storefront) {
                if (is_array($storefront) && is_string($storefront['id'] ?? null) && trim($storefront['id']) !== '') {
                    $knownStorefronts[trim($storefront['id'])] = true;
                }
            }
            $seenProductIds = [];
            foreach ($assignments as $assignment) {
                $productId = is_array($assignment) ? ($assignment['productId'] ?? null) : null;
                $storefrontId = is_array($assignment) ? ($assignment['storefrontId'] ?? null) : null;
                if (!is_int($productId) || $productId <= 0 || isset($seenProductIds[$productId])
                    || !is_string($storefrontId) || !isset($knownStorefronts[$storefrontId])) {
                    throw new \InvalidArgumentException('Каждому товару должна быть назначена существующая витрина или BASE.');
                }
                $seenProductIds[$productId] = true;
            }
        }
        if ($component === 'logic'
            && (!is_array($document['graph'] ?? null) || !is_array($document['elements'] ?? null))) {
            throw new \InvalidArgumentException('Снимок логики должен содержать graph и elements.');
        }
        if ($component === 'commercialPolicy') {
            $this->assertCommercialPolicy($document);
        }
    }

    /** @param array<string,mixed> $document */
    private function assertCommercialPolicy(array $document): void
    {
        $policy = $document['deadlinePolicy'] ?? null;
        if (!is_array($policy)
            || !in_array($policy['mode'] ?? null, ['basic', 'advanced'], true)
            || !in_array($policy['effortBasis'] ?? null, ['laborMinutes', 'productionMinutes'], true)
            || !in_array($policy['fallback'] ?? null, ['basic', 'error'], true)
            || !is_array($policy['basic'] ?? null)
            || !is_array($policy['ranges'] ?? null)) {
            throw new \InvalidArgumentException('Политика сроков имеет несовместимую структуру.');
        }
        $effort = [];
        foreach (['urgent', 'strict', 'flexible'] as $deadline) {
            $row = $policy['basic'][$deadline] ?? null;
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Базовая политика сроков должна содержать все три режима.');
            }
            foreach (['effortPercent', 'markupPercent', 'discountPercent'] as $field) {
                if (!is_int($row[$field] ?? null) && !is_float($row[$field] ?? null)) {
                    throw new \InvalidArgumentException('Проценты политики сроков должны быть числами.');
                }
                $number = (float)$row[$field];
                if (!is_finite($number) || $number < 0 || ($field === 'discountPercent' && $number >= 100)) {
                    throw new \InvalidArgumentException('Проценты политики сроков выходят за допустимые границы.');
                }
            }
            $effort[$deadline] = (float)$row['effortPercent'];
        }
        if ($effort['urgent'] < $effort['strict'] || $effort['strict'] < $effort['flexible']) {
            throw new \InvalidArgumentException('Коэффициенты сроков должны соблюдать urgent >= strict >= flexible >= 0.');
        }

        $previousMax = null;
        $rangeCount = count($policy['ranges']);
        foreach ($policy['ranges'] as $rangeIndex => $range) {
            if (!is_array($range)
                || !is_int($range['minMinutes'] ?? null) && !is_float($range['minMinutes'] ?? null)
                || (($range['maxMinutes'] ?? null) !== null
                    && !is_int($range['maxMinutes']) && !is_float($range['maxMinutes']))) {
                throw new \InvalidArgumentException('Диапазон политики сроков имеет несовместимую структуру.');
            }
            $min = (float)$range['minMinutes'];
            $max = $range['maxMinutes'] === null ? null : (float)$range['maxMinutes'];
            if (!is_finite($min) || $min < 0 || ($max !== null && (!is_finite($max) || $max <= $min))
                || ($previousMax !== null && $min < $previousMax)) {
                throw new \InvalidArgumentException('Диапазоны политики сроков должны быть непересекающимися и возрастающими.');
            }
            $previousMax = $max;
            if ($max === null && (int)$rangeIndex !== $rangeCount - 1) {
                throw new \InvalidArgumentException('Открытый диапазон политики сроков может быть только последним.');
            }
        }
    }

    private function assertSha256(string $value, string $field): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field . ' must be a SHA-256 hash');
        }
    }
}
