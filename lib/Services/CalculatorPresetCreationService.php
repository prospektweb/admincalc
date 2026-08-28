<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

/**
 * Atomic creation boundary for a calculator and its first version workspace.
 *
 * The Bitrix preset is not allowed to become visible without Version 1, its
 * standalone form document and the complete set of version bundle documents.
 * The intentionally blank logic has no immutable runtimePayload yet, so the
 * bundle is structurally complete but remains blocked from activation until
 * the administrator saves valid calculation logic.
 */
final class CalculatorPresetCreationService
{
    public const CONTRACT = 'prospektweb.calc.calculator-preset-creation/v1';

    private PresetLifecycleMutationService $lifecycle;
    private CalculatorVersionRegistryService $registry;
    private CalculatorVersionFormDocumentService $forms;
    private CalculatorVersionBundleDocumentService $bundles;
    private CalculatorVersionSnapshotSourceService $sources;

    /** @var callable(int):array<string,mixed> */
    private $formTemplateFactory;

    /** @var callable():array<string,mixed> */
    private $actorProvider;

    public function __construct(
        PresetLifecycleMutationService $lifecycle,
        CalculatorVersionRegistryService $registry,
        CalculatorVersionFormDocumentService $forms,
        CalculatorVersionBundleDocumentService $bundles,
        CalculatorVersionSnapshotSourceService $sources,
        callable $formTemplateFactory,
        callable $actorProvider
    ) {
        $this->lifecycle = $lifecycle;
        $this->registry = $registry;
        $this->forms = $forms;
        $this->bundles = $bundles;
        $this->sources = $sources;
        $this->formTemplateFactory = $formTemplateFactory;
        $this->actorProvider = $actorProvider;
    }

    /** @return array<string,mixed> */
    public function create(string $name, int $sectionId = 0): array
    {
        $actor = call_user_func($this->actorProvider);
        if (!is_array($actor)) {
            throw new \RuntimeException('Не удалось определить автора нового калькулятора.', 409);
        }

        $receipt = $this->lifecycle->createPresetWithVersionWorkspace(
            $name,
            $sectionId,
            function (int $presetId, string $presetName) use ($actor): array {
                $template = call_user_func($this->formTemplateFactory, $presetId);
                if (!is_array($template)
                    || (int)($template['presetId'] ?? 0) !== $presetId
                    || (string)($template['operation'] ?? '') !== 'new_version_template'
                    || !is_array($template['formDefinition'] ?? null)
                    || !is_array($template['bindingDefinition'] ?? null)) {
                    throw new \RuntimeException(
                        'Канонический шаблон формы нового калькулятора имеет несовместимый контракт.',
                        409
                    );
                }

                $workspace = $this->registry->initializeNewPreset(
                    $presetId,
                    $presetName,
                    $actor
                );
                $versionId = (string)($workspace['createdVersionId'] ?? '');
                if (preg_match('/^v_[a-f0-9]{16,40}$/D', $versionId) !== 1) {
                    throw new \RuntimeException('Version 1 получила недопустимый идентификатор.', 409);
                }

                $form = $this->forms->create(
                    $presetId,
                    $versionId,
                    $template['formDefinition'],
                    $template['bindingDefinition']
                );
                $bundle = $this->bundles->save(
                    $presetId,
                    $versionId,
                    $this->sources->blankVersion($presetId, $form)
                );
                $this->bundles->formForActivation($bundle, $form);

                $missing = is_array($bundle['readiness']['missingComponents'] ?? null)
                    ? array_values($bundle['readiness']['missingComponents'])
                    : [];
                sort($missing, SORT_STRING);
                if (($bundle['readiness']['complete'] ?? true) !== false
                    || $missing !== ['logic.runtimePayload']
                    || count((array)($bundle['componentHashes'] ?? []))
                        !== count(CalculatorVersionBundleDocumentService::COMPONENTS)) {
                    throw new \RuntimeException(
                        'Начальный bundle должен содержать все документы и ожидать только сохранения логики.',
                        409
                    );
                }

                $readback = $this->registry->loadWorkspace(
                    $presetId,
                    $presetName,
                    self::emptyLegacyWorkspace(),
                    $actor
                );
                $row = self::findVersion($readback, $versionId);
                if ((int)($row['versionNo'] ?? 0) !== 1
                    || ($row['active'] ?? true) !== false
                    || (string)($row['workContentHash'] ?? '') !== (string)$bundle['contentHash']) {
                    throw new \RuntimeException(
                        'Контрольное чтение Version 1 не совпало с созданным bundle.',
                        409
                    );
                }

                return [
                    'versionId' => $versionId,
                    'versionNo' => 1,
                    'registryRevision' => (string)$readback['registryRevision'],
                    'contentHash' => (string)$bundle['contentHash'],
                    'componentHashes' => $bundle['componentHashes'],
                    'snapshotReadiness' => $bundle['readiness'],
                ];
            }
        );

        $workspace = is_array($receipt['workspace'] ?? null) ? $receipt['workspace'] : null;
        if ($workspace === null
            || preg_match('/^v_[a-f0-9]{16,40}$/D', (string)($workspace['versionId'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string)($workspace['registryRevision'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string)($workspace['contentHash'] ?? '')) !== 1) {
            throw new \RuntimeException('Атомарное создание Version 1 не подтверждено.', 409);
        }

        return [
            'contract' => self::CONTRACT,
            'presetId' => (int)$receipt['presetId'],
            'presetName' => (string)$receipt['presetName'],
            'identityRevision' => (string)$receipt['identityRevision'],
            'versionId' => (string)$workspace['versionId'],
            'versionNo' => (int)$workspace['versionNo'],
            'registryRevision' => (string)$workspace['registryRevision'],
            'contentHash' => (string)$workspace['contentHash'],
            'componentHashes' => $workspace['componentHashes'],
            'snapshotReadiness' => $workspace['snapshotReadiness'],
        ];
    }

    /** @return array<string,mixed> */
    private static function emptyLegacyWorkspace(): array
    {
        return [
            'published' => null,
            'history' => [],
            'compile' => ['diff' => []],
        ];
    }

    /** @return array<string,mixed> */
    private static function findVersion(array $workspace, string $versionId): array
    {
        foreach ((array)($workspace['versions'] ?? []) as $row) {
            if (is_array($row) && (string)($row['versionId'] ?? '') === $versionId) {
                return $row;
            }
        }
        throw new \RuntimeException('Version 1 отсутствует в контрольном чтении registry.', 409);
    }
}
