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
        $this->assertDocument($presetId, $component, $document);
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
    private function assertDocument(int $presetId, string $component, array $document): void
    {
        $contract = (string)($document['contract'] ?? '');
        $documentPresetId = (int)($document['presetId'] ?? $document['preset_id'] ?? 0);
        $expectedContract = [
            'logic' => CalculatorVersionSnapshotSourceService::LOGIC_CONTRACT,
            'storefronts' => 'prospektweb.frontcalc.storefront-definition/v2',
            'inputMappings' => CalculatorInputMappingService::CONTRACT,
            'outputMappings' => CatalogOutputMappingService::CONTRACT,
            'productAssignments' => CalculatorVersionSnapshotSourceService::PRODUCT_ASSIGNMENTS_CONTRACT,
        ][$component];
        if ($contract !== $expectedContract || $documentPresetId !== $presetId) {
            throw new \InvalidArgumentException('Документ компонента не соответствует выбранной версии калькулятора.');
        }
        if ($component === 'storefronts' && !is_array($document['items'] ?? null)) {
            throw new \InvalidArgumentException('Снимок витрин должен содержать список items.');
        }
        if ($component === 'productAssignments' && !is_array($document['assignments'] ?? null)) {
            throw new \InvalidArgumentException('Снимок товарных назначений должен содержать список assignments.');
        }
        if ($component === 'logic'
            && (!is_array($document['graph'] ?? null) || !is_array($document['elements'] ?? null))) {
            throw new \InvalidArgumentException('Снимок логики должен содержать graph и elements.');
        }
    }

    private function assertSha256(string $value, string $field): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field . ' must be a SHA-256 hash');
        }
    }
}
