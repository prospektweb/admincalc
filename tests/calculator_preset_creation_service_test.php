<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/BitrixTransactionStateAuthority.php';
require_once dirname(__DIR__) . '/lib/Services/PresetMutationCoordinatorService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionBundleDocumentService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionFormDocumentService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionRegistryService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorInputMappingService.php';
require_once dirname(__DIR__) . '/lib/Services/CatalogOutputMappingService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionSnapshotSourceService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionComponentDocumentService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionWorkingGraphRehydrator.php';
require_once dirname(__DIR__) . '/lib/Services/PresetLifecycleMutationService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorPresetCreationService.php';

use Prospektweb\Calc\Services\CalculatorPresetCreationService;
use Prospektweb\Calc\Services\CalculatorVersionBundleDocumentService;
use Prospektweb\Calc\Services\CalculatorVersionFormDocumentService;
use Prospektweb\Calc\Services\CalculatorVersionRegistryService;
use Prospektweb\Calc\Services\CalculatorVersionSnapshotSourceService;
use Prospektweb\Calc\Services\PresetLifecycleMutationService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$systemKeys = ['volume', 'layoutCount', 'deadlineType', 'desiredDate'];
$template = static function (int $presetId) use ($systemKeys): array {
    $fieldIds = [];
    $fields = [];
    foreach ($systemKeys as $index => $systemKey) {
        $fieldId = 'system-' . str_replace('_', '-', $systemKey);
        $fieldIds[] = $fieldId;
        $fields[] = [
            'fieldId' => $fieldId,
            'systemKey' => $systemKey,
            'type' => $systemKey === 'desired_ready_date' ? 'date' : 'number',
            'sort' => ($index + 1) * 10,
        ];
    }
    return [
        'contract' => 'prospektweb.frontcalc.form-first-authoring/v1',
        'presetId' => $presetId,
        'operation' => 'new_version_template',
        'formDefinition' => [
            'contract' => 'prospektweb.frontcalc.form-definition/v1',
            'sections' => [[
                'sectionId' => 'system',
                'name' => 'Системные поля',
                'sort' => 10,
                'fieldIds' => $fieldIds,
            ]],
            'fields' => $fields,
        ],
        'bindingDefinition' => [
            'contract' => 'prospektweb.frontcalc.binding-definition/v1',
            'bindings' => [[
                'fieldId' => 'system-volume',
                'target' => ['kind' => 'property', 'propertyCode' => 'CALC_PROP_VOLUME'],
            ]],
        ],
    ];
};

$build = static function (bool $failBundleManifest = false) use ($template): array {
    $presets = [];
    $options = [];
    $audits = [];
    $events = [];
    $bundleService = null;

    $lifecycle = new PresetLifecycleMutationService([
        'with_global_authority' => static function (callable $criticalSection) use (
            &$presets,
            &$options,
            &$audits,
            &$events
        ): array {
            $snapshot = [$presets, $options, $audits];
            $events[] = 'transaction:begin';
            try {
                $result = $criticalSection([
                    'CALC_PRESETS' => 11,
                    'CALC_DETAILS' => 12,
                    'CALC_STAGES' => 13,
                    'CALC_SETTINGS' => 14,
                ]);
                $events[] = 'transaction:commit';
                return $result;
            } catch (Throwable $error) {
                [$presets, $options, $audits] = $snapshot;
                $events[] = 'transaction:rollback';
                throw $error;
            }
        },
        'create_locked' => static function (string $name, array $_pinned, int $sectionId) use (
            &$presets,
            &$events
        ): int {
            $events[] = 'preset:create';
            $presets[16001] = ['id' => 16001, 'name' => $name, 'sectionId' => $sectionId];
            return 16001;
        },
        'identity_loader' => static function (int $presetId) use (&$presets, &$events): array {
            $events[] = 'preset:readback';
            return $presets[$presetId] ?? [];
        },
        'audit' => static function (array $audit) use (&$audits, &$events): int {
            $events[] = 'audit';
            $audits[] = $audit;
            return count($audits);
        },
        'actor_id' => static fn(): int => 17,
    ]);

    $registry = new CalculatorVersionRegistryService([
        'get' => static function (string $name) use (&$options): string {
            return (string)($options[$name] ?? '');
        },
        'set' => static function (string $name, string $value) use (&$options, &$events): void {
            $events[] = 'registry:set';
            $options[$name] = $value;
        },
        'lock' => static fn(int $_presetId, callable $callback) => $callback(),
        'id' => static fn(): string => 'v_1111111111111111',
        'now' => static fn(): string => '2026-08-28T12:00:00+05:00',
        'runtime_meta' => static fn(int $_presetId): ?array => null,
        'bundle_meta' => static function (int $presetId, string $versionId) use (&$bundleService): ?array {
            if (!$bundleService instanceof CalculatorVersionBundleDocumentService) {
                return null;
            }
            $bundle = $bundleService->load($presetId, $versionId);
            return $bundle === null ? null : [
                'contentHash' => $bundle['contentHash'],
                'componentHashes' => $bundle['componentHashes'],
                'readiness' => $bundle['readiness'],
            ];
        },
    ]);
    $forms = new CalculatorVersionFormDocumentService([
        'get' => static function (string $name) use (&$options): string {
            return (string)($options[$name] ?? '');
        },
        'set' => static function (string $name, string $value) use (&$options, &$events): void {
            $events[] = 'form:set';
            $options[$name] = $value;
        },
        'delete' => static function (string $name) use (&$options): void { unset($options[$name]); },
        'now' => static fn(): string => '2026-08-28T12:00:01+05:00',
    ]);
    $bundleService = new CalculatorVersionBundleDocumentService([
        'get' => static function (string $name) use (&$options): string {
            return (string)($options[$name] ?? '');
        },
        'set' => static function (string $name, string $value) use (
            &$options,
            &$events,
            $failBundleManifest
        ): void {
            $events[] = str_starts_with($name, 'CALC_VERSION_BUNDLE_')
                ? 'bundle:manifest'
                : 'bundle:component';
            if ($failBundleManifest && str_starts_with($name, 'CALC_VERSION_BUNDLE_')) {
                throw new RuntimeException('simulated bundle manifest failure');
            }
            $options[$name] = $value;
        },
        'delete' => static function (string $name) use (&$options): void { unset($options[$name]); },
        'now' => static fn(): string => '2026-08-28T12:00:02+05:00',
    ]);
    $sources = new CalculatorVersionSnapshotSourceService([
        'publicationMetadata' => static fn(int $presetId): array => [
            'contract' => CalculatorVersionSnapshotSourceService::PUBLICATION_METADATA_CONTRACT,
            'presetId' => $presetId,
            'calculatorName' => 'Новый калькулятор',
            'sectionId' => 3,
            'sort' => 500,
            'active' => true,
        ],
    ]);
    $service = new CalculatorPresetCreationService(
        $lifecycle,
        $registry,
        $forms,
        $bundleService,
        $sources,
        $template,
        static fn(): array => ['id' => 17, 'name' => 'Администратор']
    );
    return [
        'service' => $service,
        'registry' => $registry,
        'forms' => $forms,
        'bundles' => $bundleService,
        'presets' => &$presets,
        'options' => &$options,
        'audits' => &$audits,
        'events' => &$events,
    ];
};

$fixture = $build();
$created = $fixture['service']->create('Новый калькулятор', 3);
$assert(($created['presetId'] ?? 0) === 16001, 'created preset identity mismatch');
$assert(($created['versionId'] ?? '') === 'v_1111111111111111', 'Version 1 identity mismatch');
$assert(($created['versionNo'] ?? 0) === 1, 'first version number mismatch');
$assert(($created['snapshotReadiness']['complete'] ?? true) === false, 'blank logic must block activation');
$assert(
    ($created['snapshotReadiness']['missingComponents'] ?? null) === ['logic.runtimePayload'],
    'blank Version 1 must report only the exact missing runtime payload'
);
$assert(count((array)($created['componentHashes'] ?? [])) === 8, 'all eight bundle documents must exist');
$unexpectedOptionNames = array_values(array_filter(
    array_keys($fixture['options']),
    static fn(string $name): bool => !str_starts_with($name, 'CALC_VERSIONS_')
        && !str_starts_with($name, 'CALC_VERSION_FORM_')
        && !str_starts_with($name, 'CALC_VERSION_COMPONENT_')
        && !str_starts_with($name, 'CALC_VERSION_BUNDLE_')
));
$assert(
    $unexpectedOptionNames === [],
    'new calculator creation must not materialize or consult a legacy form/publication document'
);

$bundle = $fixture['bundles']->load(16001, 'v_1111111111111111');
$assert(is_array($bundle), 'Version 1 bundle readback is absent');
$formFields = (array)($bundle['documents']['form']['formDefinition']['fields'] ?? []);
$actualSystemKeys = array_map(static fn(array $field): string => (string)($field['systemKey'] ?? ''), $formFields);
$assert(
    $actualSystemKeys === ['volume', 'layoutCount', 'deadlineType', 'desiredDate'],
    'Version 1 must contain the canonical system fields on first read'
);
$assert(
    (array)($bundle['documents']['logic']['graph']['stageIds'] ?? null) === [],
    'new calculator logic must be explicitly blank instead of inherited'
);
$assert(count($fixture['audits']) === 1, 'successful creation must write one lifecycle audit');
$assert(
    ($fixture['audits'][0]['initialVersionId'] ?? '') === 'v_1111111111111111'
        && ($fixture['audits'][0]['initialBundleContentHash'] ?? '') === $created['contentHash'],
    'lifecycle audit must bind the preset to its exact initial version and bundle'
);
$assert(
    array_search('audit', $fixture['events'], true) < array_search('transaction:commit', $fixture['events'], true),
    'preset, version documents, readbacks and audit must complete before commit'
);

$failed = $build(true);
$failedRaised = false;
try {
    $failed['service']->create('Rollback calculator', 0);
} catch (RuntimeException $error) {
    $failedRaised = str_contains($error->getMessage(), 'simulated bundle manifest failure');
}
$assert($failedRaised, 'bundle failure must abort calculator creation');
$assert($failed['presets'] === [], 'failed creation left an orphan Bitrix preset');
$assert($failed['options'] === [], 'failed creation left registry/form/bundle documents');
$assert($failed['audits'] === [], 'failed creation left a success audit');
$assert(
    end($failed['events']) === 'transaction:rollback',
    'failed creation did not roll back the shared lifecycle transaction'
);

fwrite(STDOUT, "Calculator preset creation service tests passed\n");
