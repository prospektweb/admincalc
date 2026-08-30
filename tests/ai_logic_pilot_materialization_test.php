<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/AiLogicPilotMaterializationService.php';

use Prospektweb\Calc\Services\AiLogicPilotMaterializationService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$hash = str_repeat('a', 64);
$versionId = 'v_1234567890abcdef';
$draft = [
    'schema' => 'prospektweb.calc.ai-logic-pilot-draft/v1',
    'context' => ['presetId' => 16488, 'versionKey' => $versionId, 'baseCompileHash' => $hash],
    'catalogFolders' => [
        ['draftId' => 'draft_folder_material', 'kind' => 'material', 'title' => 'Материалы AI', 'description' => 'Папка материалов', 'parentDraftId' => null],
        ['draftId' => 'draft_folder_calculator', 'kind' => 'calculator', 'title' => 'Расчёты AI', 'description' => 'Папка CALC_SETTINGS', 'parentDraftId' => null],
    ],
    'catalogObjects' => [
        ['draftId' => 'draft_material', 'kind' => 'material', 'title' => 'Виртуальный материал: Баннер', 'description' => 'Материал', 'folderDraftId' => 'draft_folder_material', 'parentDraftId' => null],
        ['draftId' => 'draft_material_variant', 'kind' => 'materialVariant', 'title' => 'Баннер 440', 'description' => 'Вариант материала', 'folderDraftId' => null, 'parentDraftId' => 'draft_material'],
        ['draftId' => 'draft_operation', 'kind' => 'operation', 'title' => 'Печать', 'description' => 'Операция', 'folderDraftId' => null, 'parentDraftId' => null],
        ['draftId' => 'draft_operation_variant', 'kind' => 'operationVariant', 'title' => 'Печать 720', 'description' => 'Вариант операции', 'folderDraftId' => null, 'parentDraftId' => 'draft_operation'],
        ['draftId' => 'draft_equipment', 'kind' => 'equipment', 'title' => 'Плоттер', 'description' => 'Оборудование', 'folderDraftId' => null, 'parentDraftId' => null],
        ['draftId' => 'draft_custom_field', 'kind' => 'customField', 'title' => 'Площадь', 'description' => 'Допполе', 'folderDraftId' => null, 'parentDraftId' => null],
        ['draftId' => 'draft_calculator', 'kind' => 'calculator', 'title' => 'Расчёт печати', 'description' => 'CALC_SETTINGS без формулы', 'folderDraftId' => 'draft_folder_calculator', 'parentDraftId' => null],
    ],
    'globals' => [['draftId' => 'draft_global', 'kind' => 'variable', 'dataType' => 'boolean', 'code' => 'needs_cut', 'title' => 'Нужна резка', 'description' => 'Глобальное значение']],
    'details' => [['draftId' => 'draft_detail', 'kind' => 'detail', 'title' => 'Изделие', 'description' => 'Деталь', 'parentDraftId' => null]],
    'stages' => [['draftId' => 'draft_stage', 'detailDraftId' => 'draft_detail', 'title' => 'Печать', 'description' => 'Этап', 'catalogDraftIds' => ['draft_calculator', 'draft_material_variant', 'draft_operation_variant', 'draft_equipment'], 'requiresConfiguration' => true]],
    'groups' => [],
];
$bundle = ['contentHash' => $hash, 'componentHashes' => ['logic' => str_repeat('b', 64)], 'documents' => ['logic' => [
    'workingPresetId' => 20001,
    'graph' => ['detailIds' => [777], 'stageIds' => [], 'settingsIds' => []],
]]];
$draftStore = ['status' => 'ok', 'found' => true, 'revision' => 7, 'draft' => $draft, 'decisions' => []];
$candidates = [[
    'realKind' => 'equipment', 'realId' => 901, 'title' => 'Реальный плоттер', 'path' => ['Оборудование', 'Реальный плоттер'], 'expectedRevision' => str_repeat('c', 64),
]];
$receipts = [];
$materializeCalls = 0;
$createdState = [];
$service = new AiLogicPilotMaterializationService([
    'assert_admin' => static fn() => null,
    'bundle' => static fn(array $_context): array => $bundle,
    'draft' => static fn(array $_context): array => $draftStore,
    'candidates' => static fn(array $_kinds, array $_context): array => $candidates,
    'receipt_get' => static function (string $key) use (&$receipts) { return $receipts[$key] ?? ''; },
    'receipt_set' => static function (string $key, string $raw) use (&$receipts): void { $receipts[$key] = $raw; },
    'transaction' => static function (callable $callback) use (&$createdState) {
        $snapshot = $createdState;
        try { return $callback(); } catch (Throwable $error) { $createdState = $snapshot; throw $error; }
    },
    'materialize' => static function (array $manifest) use (&$materializeCalls, &$createdState): array {
        $materializeCalls++;
        foreach ($manifest['groups'] as $rows) foreach ($rows as $row) if ($row['action'] === 'create') $createdState[$row['draftId']] = $row['kind'];
        return ['created' => $createdState, 'replaced' => ['draft_equipment' => 901]];
    },
]);
$request = ['presetId' => 16488, 'versionId' => $versionId, 'versionKey' => $versionId, 'baseCompileHash' => $hash,
    'expectedContentHash' => $hash, 'expectedDraftRevision' => 7, 'decisions' => [],
    'replacements' => ['draft_equipment' => ['realKind' => 'equipment', 'realId' => 901, 'expectedRevision' => str_repeat('c', 64)]]];
$preview = $service->preview($request)['manifest'];
$assert($preview['ready'] === true && preg_match('/^[a-f0-9]{64}$/', $preview['manifestHash']) === 1, 'manifest must be ready and content-addressed');
foreach (['directory', 'material', 'materialVariant', 'operation', 'operationVariant', 'equipment', 'customField', 'calculator'] as $kind) {
    $assert(array_key_exists($kind, $preview['groups']), 'manifest misses group ' . $kind);
}
$assert($preview['groups']['calculator'][0]['name'] === 'Расчёт печати'
    && $preview['groups']['calculator'][0]['description'] === 'CALC_SETTINGS без формулы'
    && $preview['groups']['calculator'][0]['path'] === ['Расчёты AI', 'Расчёт печати'], 'calculator must be a described CALC_SETTINGS path object');
$assert($preview['groups']['equipment'][0]['action'] === 'replace', 'approved replacement must not become create');
$assert($preview['groups']['material'][0]['name'] === 'Баннер', 'legacy virtual prefix must not reach preview or created entity');
$assert($preview['structure']['details'][0]['action'] === 'reuse' && $preview['structure']['details'][0]['realId'] === 777,
    'blank working graph foundation must be reused instead of duplicated');
$codeMethod = new ReflectionMethod($service, 'elementCode');
$materialCode = $codeMethod->invoke($service, 'material', 'draft_material');
$assert(preg_match('/^ai_pilot_material_[a-f0-9]{16}$/', $materialCode) === 1
    && $materialCode === $codeMethod->invoke($service, 'material', 'draft_material'),
    'created catalog entities need a deterministic Bitrix symbolic code');
$assert(preg_match('/^ai_pilot_stage_[a-f0-9]{16}$/', $codeMethod->invoke($service, 'stage', 'draft_stage')) === 1
    && preg_match('/^ai_pilot_detail_[a-f0-9]{16}$/', $codeMethod->invoke($service, 'detail', 'draft_detail')) === 1,
    'created structural entities need deterministic Bitrix symbolic codes');

$applyRequest = $request + ['explicitConfirm' => true, 'manifestHash' => $preview['manifestHash'], 'idempotencyKey' => 'pilot-16488-test-0001'];
$first = $service->apply($applyRequest);
$second = $service->apply($applyRequest);
$assert($first['idempotentReplay'] === false && $second['idempotentReplay'] === true && $materializeCalls === 1, 'apply must be exactly-once for one idempotency key');
$assert(!isset($createdState['draft_equipment']), 'replacement must not create a duplicate object');

$rollbackState = [];
$failing = new AiLogicPilotMaterializationService([
    'assert_admin' => static fn() => null,
    'bundle' => static fn(array $_context): array => $bundle,
    'draft' => static fn(array $_context): array => $draftStore,
    'candidates' => static fn(array $_kinds, array $_context): array => [],
    'receipt_get' => static fn(string $_key): string => '',
    'receipt_set' => static fn(string $_key, string $_raw) => null,
    'transaction' => static function (callable $callback) use (&$rollbackState) {
        $snapshot = $rollbackState;
        try { return $callback(); } catch (Throwable $error) { $rollbackState = $snapshot; throw $error; }
    },
    'materialize' => static function (array $_manifest) use (&$rollbackState): array { $rollbackState[] = 'partial'; throw new RuntimeException('injected failure'); },
]);
$plainRequest = $request; $plainRequest['replacements'] = [];
$plainPreview = $failing->preview($plainRequest)['manifest'];
$rolledBack = false;
try { $failing->apply($plainRequest + ['explicitConfirm' => true, 'manifestHash' => $plainPreview['manifestHash'], 'idempotencyKey' => 'pilot-16488-rollback-1']); }
catch (RuntimeException $error) { $rolledBack = $error->getMessage() === 'injected failure'; }
$assert($rolledBack && $rollbackState === [], 'failed apply must roll back all created entities');

$forbidden = false;
try { $service->preview(array_merge($request, ['presetId' => 12740])); } catch (RuntimeException $error) { $forbidden = $error->getCode() === 403; }
$assert($forbidden, 'preset 12740 must be impossible to mutate through AI pilot');

$draftWithoutCalculator = $draft;
$draftWithoutCalculator['stages'][0]['catalogDraftIds'] = ['draft_material_variant', 'draft_operation_variant', 'draft_equipment'];
$blockedStore = $draftStore;
$blockedStore['draft'] = $draftWithoutCalculator;
$blockedService = new AiLogicPilotMaterializationService([
    'assert_admin' => static fn() => null,
    'bundle' => static fn(array $_context): array => $bundle,
    'draft' => static fn(array $_context): array => $blockedStore,
    'candidates' => static fn(array $_kinds, array $_context): array => [],
]);
$blocked = $blockedService->preview($plainRequest)['manifest'];
$assert($blocked['ready'] === false && str_contains(implode("\n", $blocked['blockers']), 'ровно один утверждённый калькулятор'),
    'every stage must have exactly one calculator entity before apply');

echo "AI logic pilot materialization tests passed\n";
