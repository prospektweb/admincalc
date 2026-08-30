<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

/** Readback-first repair for an already applied AI pilot. Never creates/deletes entities. */
final class AiLogicPilotRepairService
{
    public const REPORT_CONTRACT = 'prospektweb.calc.ai-logic-pilot-readback/v1';
    public const REPAIR_CONTRACT = 'prospektweb.calc.ai-logic-pilot-repair/v1';
    private const MODULE_ID = 'prospektweb.calc';
    private const TARGET_PRESET_ID = 16488;
    private const FORBIDDEN_PRESET_ID = 12740;
    private const IBLOCK_BY_KIND = [
        'material' => 'CALC_MATERIALS', 'materialVariant' => 'CALC_MATERIALS_VARIANTS',
        'operation' => 'CALC_OPERATIONS', 'operationVariant' => 'CALC_OPERATIONS_VARIANTS',
        'equipment' => 'CALC_EQUIPMENT', 'customField' => 'CALC_CUSTOM_FIELDS',
        'calculator' => 'CALC_SETTINGS', 'detail' => 'CALC_DETAILS', 'stage' => 'CALC_STAGES',
    ];

    /** @var array<string,callable> */
    private array $adapters;

    public function __construct(array $adapters = []) { $this->adapters = $adapters; }

    /** @return array<string,mixed> */
    public function inspect(array $request): array
    {
        $this->assertAdmin();
        $context = $this->context($request);
        $bundle = $this->bundle($context);
        $receipt = $this->latestReceipt($context);
        $stored = $this->draft($context, $receipt);
        $plan = $this->buildPlan($context, $bundle, $receipt, $stored);
        return [
            'status' => 'ok',
            'contract' => self::REPORT_CONTRACT,
            'receipt' => $this->publicReceipt($receipt),
            'entities' => $this->inventory($stored, $receipt, (int)($plan['workingPresetId'] ?? 0)),
        ] + $plan;
    }

    /** @return array<string,mixed> */
    public function repair(array $request): array
    {
        $this->assertAdmin();
        if (($request['explicitConfirm'] ?? false) !== true) throw new \InvalidArgumentException('Для восстановления связей требуется явное подтверждение.');
        $context = $this->context($request);
        $receipt = $this->latestReceipt($context);
        $repairKey = 'ai_logic_pilot_repair_' . substr(hash('sha256', self::TARGET_PRESET_ID . ':' . $context['versionId'] . ':' . (string)$receipt['manifestHash'] . ':' . $context['expectedContentHash']), 0, 40);
        $previous = $this->optionGet($repairKey);

        $critical = function () use ($context, $receipt, $repairKey, $previous): array {
            $bundle = $this->bundle($context);
            $stored = $this->draft($context, $receipt);
            $plan = $this->buildPlan($context, $bundle, $receipt, $stored);
            if ($plan['blockers'] !== []) throw new \RuntimeException('Readback обнаружил повреждение, которое нельзя безопасно исправить без создания или удаления сущностей.', 409);
            if ($previous !== null && $plan['operations'] === [] && ($plan['needsSnapshotRefresh'] ?? false) !== true) {
                return ['idempotentReplay' => true] + $previous;
            }
            $workingPresetId = (int)$plan['workingPresetId'];
            if ($workingPresetId <= 0 || $workingPresetId === self::FORBIDDEN_PRESET_ID) throw new \RuntimeException('Запрещённый рабочий граф.', 409);
            if ($plan['operations'] !== []) {
                if (isset($this->adapters['apply_operations'])) {
                    ($this->adapters['apply_operations'])($plan['operations'], $workingPresetId);
                } else {
                    $authority = new CalculatorMutationAuthorityService();
                    $authority->withAuthorityLock($workingPresetId, function (bool $_protected, array $iblocks) use ($plan): void {
                        $this->applyBitrixOperations($plan['operations'], $iblocks);
                    });
                }
            }
            if ($plan['operations'] !== [] || ($plan['needsSnapshotRefresh'] ?? false) === true) {
                if (!isset($this->adapters['skip_capture'])) {
                    $logic = (new CalculatorVersionSnapshotSourceService())->captureLogic($workingPresetId, self::TARGET_PRESET_ID, $context['versionId']);
                    $saved = (new CalculatorVersionComponentDocumentService())->saveDraft(
                        self::TARGET_PRESET_ID, $context['versionId'], 'logic', $context['expectedContentHash'],
                        (string)$bundle['componentHashes']['logic'], $logic
                    );
                    $afterContentHash = (string)$saved['contentHash'];
                } else {
                    $afterContentHash = (string)($this->adapters['after_content_hash'] ?? $context['expectedContentHash']);
                }
            } else {
                $afterContentHash = $context['expectedContentHash'];
            }
            $result = ['presetId' => self::TARGET_PRESET_ID, 'versionId' => $context['versionId'],
                'applicationManifestHash' => (string)$receipt['manifestHash'], 'beforeContentHash' => $context['expectedContentHash'],
                'afterContentHash' => $afterContentHash,
                'fixedIssues' => count($plan['operations']) + (($plan['needsSnapshotRefresh'] ?? false) === true ? 1 : 0),
                'repairedAt' => gmdate('c'), 'idempotentReplay' => false];
            $this->optionSet($repairKey, $result);
            return $result;
        };
        $result = isset($this->adapters['transaction'])
            ? ($this->adapters['transaction'])($critical)
            : (new CalculatorVersionRegistryService())->coordinateVersionMutation(self::TARGET_PRESET_ID, $critical);
        return ['status' => 'ok', 'contract' => self::REPAIR_CONTRACT,
            'message' => ($result['idempotentReplay'] ?? false) === true
                ? 'Связи AI-пилота уже восстановлены.'
                : ($result['fixedIssues'] > 0 ? 'Связи AI-пилота восстановлены.' : 'Связи AI-пилота уже корректны.')]
            + $result;
    }

    /** @return array<string,mixed> */
    private function buildPlan(array $context, array $bundle, array $receipt, array $stored): array
    {
        $draft = is_array($stored['draft'] ?? null) ? $stored['draft'] : [];
        if (($draft['schema'] ?? null) !== 'prospektweb.calc.ai-logic-pilot-draft/v1') throw new \RuntimeException('Сохранённый AI-черновик несовместим.', 409);
        $decisions = is_array($stored['decisions'] ?? null) ? $stored['decisions'] : [];
        $approved = static fn(string $draftId): bool => ($decisions[$draftId] ?? 'approved') !== 'rejected';
        $logic = is_array($bundle['documents']['logic'] ?? null) ? $bundle['documents']['logic'] : [];
        $workingPresetId = (int)($logic['workingPresetId'] ?? 0);
        if ($workingPresetId <= 0 || $workingPresetId === self::FORBIDDEN_PRESET_ID) {
            return ['workingPresetId' => $workingPresetId, 'healthy' => false, 'issues' => [],
                'blockers' => [['code' => 'UNSAFE_WORKING_GRAPH', 'message' => 'У версии нет безопасного изолированного рабочего графа.']], 'operations' => []];
        }
        if (!preg_match('/^[a-f0-9]{64}$/', (string)($receipt['manifestHash'] ?? ''))) {
            return ['workingPresetId' => $workingPresetId, 'healthy' => false, 'issues' => [],
                'blockers' => [['code' => 'INVALID_RECEIPT', 'message' => 'Receipt применения AI-пилота повреждён.']], 'operations' => []];
        }
        $ids = [];
        foreach (['created', 'reused'] as $bucket) foreach (is_array($receipt[$bucket] ?? null) ? $receipt[$bucket] : [] as $draftId => $entry) {
            $ids[(string)$draftId] = is_array($entry) ? (int)($entry['id'] ?? 0) : (int)$entry;
        }
        foreach (is_array($receipt['replaced'] ?? null) ? $receipt['replaced'] : [] as $draftId => $entry) $ids[(string)$draftId] = is_array($entry) ? (int)($entry['id'] ?? 0) : (int)$entry;
        $owned = array_fill_keys(array_keys(is_array($receipt['created'] ?? null) ? $receipt['created'] : []), true);
        $replaced = array_fill_keys(array_keys(is_array($receipt['replaced'] ?? null) ? $receipt['replaced'] : []), true);
        $operations = []; $issues = []; $blockers = [];
        $rowsById = [];
        foreach (['catalogFolders','catalogObjects','details','stages','globals'] as $collection) foreach (is_array($draft[$collection] ?? null) ? $draft[$collection] : [] as $row) {
            if (is_array($row) && isset($row['draftId'])) $rowsById[(string)$row['draftId']] = ['collection' => $collection, 'row' => $row];
        }
        foreach ($rowsById as $draftId => $entry) {
            if (!$approved($draftId)) continue;
            $row = $entry['row'];
            $kind = $entry['collection'] === 'catalogFolders' ? 'directory'
                : ($entry['collection'] === 'details' ? 'detail' : ($entry['collection'] === 'stages' ? 'stage' : ($entry['collection'] === 'globals' ? 'global' : (string)($row['kind'] ?? ''))));
            $id = (int)($ids[$draftId] ?? 0);
            if ($kind === 'global' && $id <= 0) $id = $this->findGlobalId($workingPresetId, (string)($row['code'] ?? ''));
            if ($id <= 0) { $blockers[] = ['code' => 'UNMAPPED_ENTITY', 'draftId' => $draftId, 'message' => 'Receipt не содержит ID утверждённой сущности.']; continue; }
            $spec = ['draftId' => $draftId, 'kind' => $kind, 'catalogKind' => (string)($row['kind'] ?? ''), 'id' => $id, 'workingPresetId' => $workingPresetId];
            $state = $this->readState($spec);
            if ($state === null) { $blockers[] = ['code' => 'MISSING_ENTITY', 'draftId' => $draftId, 'id' => $id, 'message' => 'Созданная сущность отсутствует; repair не создаёт дубликаты.']; continue; }
            if (isset($owned[$draftId]) || $kind === 'global') {
                $fields = [];
                if ((string)($state['name'] ?? '') !== (string)($row['title'] ?? '')) $fields['name'] = (string)$row['title'];
                if ((string)($state['description'] ?? '') !== (string)($row['description'] ?? '')) $fields['description'] = (string)($row['description'] ?? '');
                if ($kind !== 'directory' && $kind !== 'global') {
                    $expectedCode = $this->elementCode($kind, $draftId);
                    if ((string)($state['code'] ?? '') !== $expectedCode) $fields['code'] = $expectedCode;
                }
                if ($fields !== []) { $issues[] = ['code' => 'METADATA_MISMATCH', 'draftId' => $draftId]; $operations[] = ['type' => 'metadata', 'spec' => $spec, 'fields' => $fields]; }
            }
            // Replacement objects are external Bitrix authorities. Repair may
            // restore references to them, but must never rename, move or
            // re-parent those real catalog entities.
            if (isset($replaced[$draftId]) && ($kind === 'directory' || isset(self::IBLOCK_BY_KIND[$kind]))) {
                continue;
            }
            if ($kind === 'directory') {
                $parentDraftId = trim((string)($row['parentDraftId'] ?? '')); $expected = (int)($ids[$parentDraftId] ?? 0);
                if ($parentDraftId !== '' && $expected <= 0) { $blockers[] = ['code' => 'MISSING_FOLDER_PARENT', 'draftId' => $draftId]; continue; }
                if ((int)($state['parentId'] ?? 0) !== $expected) { $issues[] = ['code' => 'FOLDER_PARENT_MISMATCH', 'draftId' => $draftId]; $operations[] = ['type' => 'section_parent', 'spec' => $spec, 'parentId' => $expected]; }
            } elseif (isset(self::IBLOCK_BY_KIND[$kind])) {
                $folderDraftId = trim((string)($row['folderDraftId'] ?? '')); $expectedSection = (int)($ids[$folderDraftId] ?? 0);
                if (!in_array($kind, ['materialVariant','operationVariant'], true)) {
                    if ($folderDraftId !== '' && $expectedSection <= 0) { $blockers[] = ['code' => 'MISSING_OBJECT_FOLDER', 'draftId' => $draftId]; continue; }
                    if ($folderDraftId !== '' && (int)($state['sectionId'] ?? 0) !== $expectedSection) { $issues[] = ['code' => 'OBJECT_FOLDER_MISMATCH', 'draftId' => $draftId]; $operations[] = ['type' => 'section', 'spec' => $spec, 'sectionId' => $expectedSection]; }
                }
                if (in_array($kind, ['materialVariant','operationVariant'], true)) {
                    $expectedParent = (int)($ids[(string)($row['parentDraftId'] ?? '')] ?? 0);
                    if ($expectedParent <= 0) { $blockers[] = ['code' => 'MISSING_VARIANT_PARENT', 'draftId' => $draftId]; continue; }
                    if ((int)($state['properties']['CML2_LINK'] ?? 0) !== $expectedParent) { $issues[] = ['code' => 'VARIANT_PARENT_MISMATCH', 'draftId' => $draftId]; $operations[] = ['type' => 'variant_parent', 'spec' => $spec, 'parentId' => $expectedParent]; }
                }
            }
        }
        $expectedStagesByDetail = [];
        foreach (is_array($draft['stages'] ?? null) ? $draft['stages'] : [] as $stage) {
            $stageDraftId = (string)($stage['draftId'] ?? ''); if (!$approved($stageDraftId) || !isset($ids[$stageDraftId])) continue;
            $detailDraftId = (string)($stage['detailDraftId'] ?? '');
            if ($detailDraftId === '' || !isset($ids[$detailDraftId])) { $blockers[] = ['code'=>'MISSING_STAGE_DETAIL','draftId'=>$stageDraftId]; continue; }
            $expectedStagesByDetail[$detailDraftId][] = (int)$ids[$stageDraftId];
            $spec = ['draftId' => $stageDraftId, 'kind' => 'stage', 'id' => (int)$ids[$stageDraftId], 'workingPresetId' => $workingPresetId];
            $state = $this->readState($spec); if ($state === null) continue;
            $expectedProps = [];
            foreach (is_array($stage['catalogDraftIds'] ?? null) ? $stage['catalogDraftIds'] : [] as $catalogDraftId) {
                if (!isset($ids[$catalogDraftId], $rowsById[$catalogDraftId])) { $blockers[] = ['code'=>'MISSING_STAGE_CATALOG','draftId'=>$stageDraftId,'reference'=>$catalogDraftId]; continue; }
                $catalogKind = (string)($rowsById[$catalogDraftId]['row']['kind'] ?? '');
                $property = ['calculator'=>'CALC_SETTINGS','materialVariant'=>'MATERIAL_VARIANT','operationVariant'=>'OPERATION_VARIANT','equipment'=>'EQUIPMENT'][$catalogKind] ?? null;
                if ($property) $expectedProps[$property] = (int)$ids[$catalogDraftId];
            }
            $props = [];
            foreach ($expectedProps as $property => $expectedId) {
                if ((int)($state['properties'][$property] ?? 0) !== $expectedId) $props[$property] = $expectedId;
            }
            if ($props !== []) { $issues[] = ['code' => 'STAGE_CATALOG_LINK_MISMATCH', 'draftId' => $stageDraftId]; $operations[] = ['type' => 'properties', 'spec' => $spec, 'properties' => $props]; }
        }
        foreach ($expectedStagesByDetail as $detailDraftId => $stageIds) if (isset($ids[$detailDraftId])) {
            $spec = ['draftId' => $detailDraftId, 'kind' => 'detail', 'id' => (int)$ids[$detailDraftId], 'workingPresetId' => $workingPresetId];
            $state = $this->readState($spec); if ($state === null) { $blockers[]=['code'=>'MISSING_DETAIL','draftId'=>$detailDraftId]; continue; }
            $current = array_map('intval', (array)($state['properties']['CALC_STAGES'] ?? []));
            $missing = array_values(array_diff($stageIds, $current));
            if ($missing !== []) { $issues[] = ['code' => 'DETAIL_STAGE_LINK_MISSING', 'draftId' => $detailDraftId]; $operations[] = ['type' => 'append_property', 'spec' => $spec, 'property' => 'CALC_STAGES', 'values' => $missing]; }
        }
        $children = []; $roots = [];
        foreach (is_array($draft['details'] ?? null) ? $draft['details'] : [] as $detail) {
            $id = (string)($detail['draftId'] ?? ''); if (!$approved($id) || !isset($ids[$id])) continue;
            $parent = trim((string)($detail['parentDraftId'] ?? ''));
            if ($parent !== '' && !isset($ids[$parent])) { $blockers[]=['code'=>'MISSING_DETAIL_PARENT','draftId'=>$id,'reference'=>$parent]; continue; }
            if ($parent !== '') $children[$parent][] = (int)$ids[$id]; else $roots[] = (int)$ids[$id];
        }
        foreach ($children as $bindingDraftId => $childIds) if (isset($ids[$bindingDraftId])) {
            $spec = ['draftId' => $bindingDraftId, 'kind' => 'detail', 'id' => (int)$ids[$bindingDraftId], 'workingPresetId' => $workingPresetId];
            $state = $this->readState($spec); $current = array_map('intval', (array)($state['properties']['DETAILS'] ?? [])); $missing = array_values(array_diff($childIds, $current));
            if ($missing !== []) { $issues[] = ['code' => 'BINDING_CHILD_LINK_MISSING', 'draftId' => $bindingDraftId]; $operations[] = ['type' => 'append_property', 'spec' => $spec, 'property' => 'DETAILS', 'values' => $missing]; }
        }
        $presetSpec = ['draftId' => 'working_preset', 'kind' => 'preset', 'id' => $workingPresetId, 'workingPresetId' => $workingPresetId];
        $presetState = $this->readState($presetSpec);
        if ($presetState === null) { $blockers[]=['code'=>'MISSING_WORKING_PRESET','draftId'=>'working_preset']; return ['workingPresetId'=>$workingPresetId,'healthy'=>false,'issues'=>$issues,'blockers'=>$blockers,'operations'=>$operations,'needsSnapshotRefresh'=>false]; }
        $currentRoots = array_map('intval', (array)($presetState['properties']['CALC_DETAILS'] ?? [])); $missingRoots = array_values(array_diff($roots, $currentRoots));
        if ($missingRoots !== []) { $issues[] = ['code' => 'PRESET_ROOT_LINK_MISSING', 'draftId' => 'working_preset']; $operations[] = ['type' => 'append_property', 'spec' => $presetSpec, 'property' => 'CALC_DETAILS', 'values' => $missingRoots]; }
        $expectedDetails = [];
        foreach (is_array($draft['details'] ?? null) ? $draft['details'] : [] as $detail) {
            $draftId = (string)($detail['draftId'] ?? '');
            if ($approved($draftId) && isset($ids[$draftId])) $expectedDetails[] = (int)$ids[$draftId];
        }
        $expectedStages = [];
        $expectedSettings = [];
        foreach (is_array($draft['stages'] ?? null) ? $draft['stages'] : [] as $stage) {
            $stageDraftId = (string)($stage['draftId'] ?? '');
            if (!$approved($stageDraftId) || !isset($ids[$stageDraftId])) continue;
            $expectedStages[] = (int)$ids[$stageDraftId];
            foreach (is_array($stage['catalogDraftIds'] ?? null) ? $stage['catalogDraftIds'] : [] as $catalogDraftId) {
                if (($rowsById[$catalogDraftId]['row']['kind'] ?? null) === 'calculator' && isset($ids[$catalogDraftId])) {
                    $expectedSettings[] = (int)$ids[$catalogDraftId];
                }
            }
        }
        $graph = is_array($logic['graph'] ?? null) ? $logic['graph'] : [];
        $expectedDetails = array_values(array_unique($expectedDetails));
        $expectedStages = array_values(array_unique($expectedStages));
        $expectedSettings = array_values(array_unique($expectedSettings));
        $graphMismatch = array_diff($expectedDetails, array_map('intval', (array)($graph['detailIds'] ?? []))) !== []
            || array_diff($expectedStages, array_map('intval', (array)($graph['stageIds'] ?? []))) !== []
            || array_diff($expectedSettings, array_map('intval', (array)($graph['settingsIds'] ?? []))) !== [];
        if ($graphMismatch) $issues[] = ['code' => 'VERSION_GRAPH_SNAPSHOT_MISMATCH', 'draftId' => 'working_preset'];

        $runtimePayload = is_array($logic['runtimePayload'] ?? null) ? $logic['runtimePayload'] : [];
        $runtimePreset = is_array($runtimePayload['preset'] ?? null) ? $runtimePayload['preset'] : [];
        $runtimeProperties = is_array($runtimePreset['properties'] ?? null) ? $runtimePreset['properties'] : [];
        $runtimeRoots = $this->runtimeIds($runtimeProperties['CALC_DETAILS'] ?? ($runtimePreset['CALC_DETAILS'] ?? []));
        $runtimeStore = is_array($runtimePayload['elementsStore'] ?? null) ? $runtimePayload['elementsStore'] : [];
        $runtimeDetails = $this->runtimeIds($runtimeStore['CALC_DETAILS'] ?? []);
        $runtimeStages = $this->runtimeIds($runtimeStore['CALC_STAGES'] ?? []);
        $runtimeSettings = $this->runtimeIds($runtimeStore['CALC_SETTINGS'] ?? []);
        $runtimeMismatch = !$this->sameIdSet($roots, $runtimeRoots)
            || array_diff($expectedDetails, $runtimeDetails) !== []
            || array_diff($expectedStages, $runtimeStages) !== []
            || array_diff($expectedSettings, $runtimeSettings) !== [];
        if ($runtimeMismatch) $issues[] = ['code' => 'VERSION_RUNTIME_SNAPSHOT_MISMATCH', 'draftId' => 'working_preset'];
        $needsSnapshotRefresh = $graphMismatch || $runtimeMismatch;
        return ['workingPresetId' => $workingPresetId, 'healthy' => $issues === [] && $blockers === [],
            'issues' => $issues, 'blockers' => $blockers, 'operations' => $operations,
            'needsSnapshotRefresh' => $needsSnapshotRefresh];
    }

    /** @return int[] */
    private function runtimeIds($value): array
    {
        if (is_numeric($value)) {
            $id = (int)$value;
            return $id > 0 ? [$id] : [];
        }
        if (!is_array($value)) return [];
        if (array_key_exists('VALUE', $value)) return $this->runtimeIds($value['VALUE']);
        foreach (['id', 'ID'] as $key) {
            if (isset($value[$key]) && is_numeric($value[$key])) {
                $id = (int)$value[$key];
                return $id > 0 ? [$id] : [];
            }
        }
        if (array_values($value) !== $value) return [];
        $ids = [];
        foreach ($value as $item) $ids = array_merge($ids, $this->runtimeIds($item));
        return array_values(array_unique($ids));
    }

    private function sameIdSet(array $expected, array $actual): bool
    {
        $normalize = static function (array $ids): array {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
            sort($ids, SORT_NUMERIC);
            return $ids;
        };
        return $normalize($expected) === $normalize($actual);
    }

    private function readState(array $spec): ?array
    {
        if (isset($this->adapters['read_state'])) return ($this->adapters['read_state'])($spec);
        $config = new \Prospektweb\Calc\Config\ConfigManager();
        $kind = (string)$spec['kind'];
        $iblockCode = $kind === 'directory' ? self::IBLOCK_BY_KIND[(string)$spec['catalogKind']] : ($kind === 'preset' ? 'CALC_PRESETS' : ($kind === 'global' ? 'CALC_GLOBAL_VALUES' : self::IBLOCK_BY_KIND[$kind]));
        $iblockId = (int)$config->getIblockId($iblockCode); $id = (int)$spec['id'];
        if ($kind === 'directory') {
            $row = \CIBlockSection::GetList([], ['ID' => $id, 'IBLOCK_ID' => $iblockId], false, ['ID','NAME','DESCRIPTION','IBLOCK_SECTION_ID'])->Fetch();
            return $row ? ['name'=>(string)$row['NAME'],'description'=>(string)$row['DESCRIPTION'],'parentId'=>(int)$row['IBLOCK_SECTION_ID'],'properties'=>[]] : null;
        }
        $element = \CIBlockElement::GetList([], ['ID'=>$id,'IBLOCK_ID'=>$iblockId], false, ['nTopCount'=>1], ['ID','CODE','NAME','PREVIEW_TEXT','IBLOCK_SECTION_ID'])->GetNextElement();
        if (!$element) return null; $fields=$element->GetFields(); $properties=$element->GetProperties(); $normalized=[];
        foreach ($properties as $code=>$property) { $value=$property['VALUE']??null; $normalized[$code]=is_array($value)?array_values(array_filter(array_map('intval',$value))):(is_numeric($value)?(int)$value:$value); }
        $authoritativeProperties = [
            'materialVariant' => ['CML2_LINK'], 'operationVariant' => ['CML2_LINK'],
            'stage' => ['MATERIAL_VARIANT','OPERATION_VARIANT','EQUIPMENT','CALC_SETTINGS'],
            'detail' => ['CALC_STAGES','DETAILS'], 'preset' => ['CALC_DETAILS'],
        ];
        foreach ($authoritativeProperties[$kind] ?? [] as $code) {
            $property = $this->propertyDefinition($iblockId, $code);
            $values = $this->readPropertyIntValues($id, $iblockId, (int)$property['ID']);
            $normalized[$code] = (string)($property['MULTIPLE'] ?? '') === 'Y' ? $values : (int)($values[0] ?? 0);
        }
        return ['code'=>(string)($fields['CODE']??''),'name'=>(string)$fields['NAME'],'description'=>(string)($fields['~PREVIEW_TEXT']??$fields['PREVIEW_TEXT']??''),'sectionId'=>(int)($fields['IBLOCK_SECTION_ID']??0),'properties'=>$normalized];
    }

    private function applyBitrixOperations(array $operations, array $iblocks): void
    {
        foreach ($operations as $operation) {
            $spec=$operation['spec']; $kind=(string)$spec['kind']; $id=(int)$spec['id'];
            $iblockCode=$kind==='directory'?self::IBLOCK_BY_KIND[(string)$spec['catalogKind']]:($kind==='preset'?'CALC_PRESETS':($kind==='global'?'CALC_GLOBAL_VALUES':self::IBLOCK_BY_KIND[$kind]));
            $iblockId=(int)($iblocks[$iblockCode]??0); if($iblockId<=0) throw new \RuntimeException('Не закреплён инфоблок '.$iblockCode,409);
            if($operation['type']==='metadata') {
                $fields=[];
                if(isset($operation['fields']['name']))$fields['NAME']=$operation['fields']['name'];
                if(isset($operation['fields']['code']))$fields['CODE']=$operation['fields']['code'];
                if(array_key_exists('description',$operation['fields'])) {
                    if ($kind === 'directory') { $fields['DESCRIPTION']=$operation['fields']['description']; $fields['DESCRIPTION_TYPE']='text'; }
                    else { $fields['PREVIEW_TEXT']=$operation['fields']['description']; $fields['PREVIEW_TEXT_TYPE']='text'; }
                }
                if ($kind === 'directory') { $api=new \CIBlockSection(); if(!$api->Update($id,$fields))throw new \RuntimeException('Не удалось восстановить метаданные папки: '.$api->LAST_ERROR); }
                else { $api=new \CIBlockElement(); if(!$api->Update($id,$fields))throw new \RuntimeException('Не удалось восстановить метаданные: '.$api->LAST_ERROR); }
            }
            elseif($operation['type']==='section_parent') { $api=new \CIBlockSection(); if(!$api->Update($id,['IBLOCK_SECTION_ID'=>(int)$operation['parentId']]))throw new \RuntimeException('Не удалось восстановить родительскую папку: '.$api->LAST_ERROR); }
            elseif($operation['type']==='section') { $api=new \CIBlockElement(); if(!$api->Update($id,['IBLOCK_SECTION_ID'=>(int)$operation['sectionId']]))throw new \RuntimeException('Не удалось восстановить папку объекта: '.$api->LAST_ERROR); }
            elseif($operation['type']==='properties')foreach($operation['properties'] as $code=>$value)$this->writePropertyByCode($id,$iblockId,(string)$code,$value);
            elseif($operation['type']==='variant_parent') {
                $parentIblockCode=$kind==='materialVariant'?'CALC_MATERIALS':($kind==='operationVariant'?'CALC_OPERATIONS':'');
                $parentIblockId=(int)($iblocks[$parentIblockCode]??0);
                $property=$this->skuLinkProperty($iblockId,$parentIblockId);
                \CIBlockElement::SetPropertyValues($id,$iblockId,(int)$operation['parentId'],(int)$property['ID']);
            }
            elseif($operation['type']==='append_property') { $property=$this->propertyDefinition($iblockId,(string)$operation['property']);$current=$this->readPropertyIntValues($id,$iblockId,(int)$property['ID']);$values=array_values(array_unique(array_merge($current,array_map('intval',$operation['values']))));\CIBlockElement::SetPropertyValues($id,$iblockId,$values,(int)$property['ID']); }
        }
        foreach ($operations as $operation) {
            $spec=$operation['spec'];$kind=(string)$spec['kind'];$iblockCode=$kind==='directory'?self::IBLOCK_BY_KIND[(string)$spec['catalogKind']]:($kind==='preset'?'CALC_PRESETS':($kind==='global'?'CALC_GLOBAL_VALUES':self::IBLOCK_BY_KIND[$kind]));$iblockId=(int)($iblocks[$iblockCode]??0);
            $after=$this->readState($spec); if($after===null)throw new \RuntimeException('Сущность исчезла во время readback.',409);
            if($operation['type']==='metadata'){
                foreach($operation['fields'] as $field=>$value){$stateKey=$field==='name'?'name':($field==='description'?'description':'code');if((string)($after[$stateKey]??'')!==(string)$value)throw new \RuntimeException('Bitrix не сохранил метаданные '.$stateKey.'.',409);}
            }
            if($operation['type']==='section'&&(int)($after['sectionId']??0)!==(int)$operation['sectionId'])throw new \RuntimeException('Bitrix не сохранил путь объекта.',409);
            if($operation['type']==='section_parent'&&(int)($after['parentId']??0)!==(int)$operation['parentId'])throw new \RuntimeException('Bitrix не сохранил родительский путь.',409);
            if($operation['type']==='properties')foreach($operation['properties'] as $code=>$value){$property=$this->propertyDefinition($iblockId,(string)$code);$actual=$this->readPropertyIntValues((int)$operation['spec']['id'],$iblockId,(int)$property['ID']);$expected=array_values(array_filter(array_map('intval',is_array($value)?$value:[$value])));sort($actual);sort($expected);if($actual!==$expected)throw new \RuntimeException('Bitrix не сохранил связь '.$code.'.',409);}
            if($operation['type']==='variant_parent'){
                $kind=(string)$operation['spec']['kind'];$iblockCode=self::IBLOCK_BY_KIND[$kind]??'';$iblockId=(int)($iblocks[$iblockCode]??0);
                $parentIblockCode=$kind==='materialVariant'?'CALC_MATERIALS':($kind==='operationVariant'?'CALC_OPERATIONS':'');
                $property=$this->skuLinkProperty($iblockId,(int)($iblocks[$parentIblockCode]??0));
                if($this->readSkuParentId((int)$operation['spec']['id'],$iblockId,(int)$property['ID'])!==(int)$operation['parentId'])throw new \RuntimeException('Bitrix не сохранил родительскую SKU-связь варианта.',409);
                if(!$this->skuRelationVisible((int)$operation['spec']['id'],$iblockId,(int)$operation['parentId']))throw new \RuntimeException('Вариант не виден через канонический SKU-фильтр.',409);
            }
            if($operation['type']==='append_property'){ $property=$this->propertyDefinition($iblockId,(string)$operation['property']);$actual=$this->readPropertyIntValues((int)$operation['spec']['id'],$iblockId,(int)$property['ID']);if(array_diff(array_map('intval',$operation['values']),$actual)!==[])throw new \RuntimeException('Bitrix не сохранил связь '.$operation['property'].'.',409); }
        }
    }

    private function elementCode(string $kind, string $draftId): string
    {
        $kind = strtolower((string)(preg_replace('/[^a-z0-9]+/i', '_', $kind) ?? 'entity'));
        $kind = trim($kind, '_') ?: 'entity';
        return 'ai_pilot_' . $kind . '_' . substr(hash('sha256', $draftId), 0, 16);
    }

    /** @return array<string,mixed> */
    private function skuLinkProperty(int $variantsIblockId, int $parentIblockId): array
    {
        $property=$this->propertyDefinition($variantsIblockId,'CML2_LINK');
        if((int)($property['LINK_IBLOCK_ID']??0)!==$parentIblockId||(string)($property['PROPERTY_TYPE']??'')!=='E'||(string)($property['MULTIPLE']??'')!=='N')throw new \RuntimeException('Свойство CML2_LINK не соответствует одиночной SKU-связи с родительским инфоблоком.',409);
        $sku=\CCatalogSKU::GetInfoByProductIBlock($parentIblockId);
        if(!is_array($sku)||(int)($sku['PRODUCT_IBLOCK_ID']??0)!==$parentIblockId||(int)($sku['IBLOCK_ID']??0)!==$variantsIblockId||(int)($sku['SKU_PROPERTY_ID']??0)!==(int)$property['ID'])throw new \RuntimeException('Пара инфоблоков вариантов не зарегистрирована с ожидаемым SKU-свойством.',409);
        return $property;
    }

    private function readSkuParentId(int $elementId,int $variantsIblockId,int $propertyId): int
    {
        $cursor=\CIBlockElement::GetProperty($variantsIblockId,$elementId,['sort'=>'asc'],['ID'=>$propertyId]);
        while($property=$cursor->Fetch()){ $value=(int)($property['VALUE']??0);if($value>0)return$value; }
        return 0;
    }

    /** @return array<string,mixed> */
    private function propertyDefinition(int $iblockId,string $code): array
    {
        $property=\CIBlockProperty::GetList(['ID'=>'ASC'],['IBLOCK_ID'=>$iblockId,'CODE'=>$code,'ACTIVE'=>'Y'])->Fetch();
        if(!$property)throw new \RuntimeException('В инфоблоке не настроено активное свойство '.$code.'.',409);
        return$property;
    }

    private function writePropertyByCode(int $elementId,int $iblockId,string $code,$value): void
    {
        $property=$this->propertyDefinition($iblockId,$code);
        \CIBlockElement::SetPropertyValues($elementId,$iblockId,$value,(int)$property['ID']);
    }

    /** @return int[] */
    private function readPropertyIntValues(int $elementId,int $iblockId,int $propertyId): array
    {
        $cursor=\CIBlockElement::GetProperty($iblockId,$elementId,['sort'=>'asc'],['ID'=>$propertyId]);$values=[];
        while($property=$cursor->Fetch()){ $value=(int)($property['VALUE']??0);if($value>0)$values[]=$value; }
        return array_values(array_unique($values));
    }

    private function skuRelationVisible(int $elementId,int $variantsIblockId,int $parentId): bool
    {
        $visible=(bool)\CIBlockElement::GetList([],['ID'=>$elementId,'IBLOCK_ID'=>$variantsIblockId,'PROPERTY_CML2_LINK'=>$parentId],false,['nTopCount'=>1],['ID'])->Fetch();
        if(!$visible)return false;
        $product=\CCatalogSKU::GetProductInfo($elementId,$variantsIblockId);
        return is_array($product)&&(int)($product['ID']??0)===$parentId;
    }

    /**
     * Authoritative post-apply inventory for the acceptance UI. Every row is
     * read back from Bitrix; a successful receipt alone is not evidence that
     * the entity still exists or that its metadata is visible.
     *
     * @return array<int,array<string,mixed>>
     */
    private function inventory(array $stored, array $receipt, int $workingPresetId): array
    {
        $draft = is_array($stored['draft'] ?? null) ? $stored['draft'] : [];
        $decisions = is_array($stored['decisions'] ?? null) ? $stored['decisions'] : [];
        $approved = static fn(string $draftId): bool => ($decisions[$draftId] ?? 'approved') !== 'rejected';
        $ids = [];
        $sources = [];
        foreach (['created', 'reused', 'replaced'] as $bucket) {
            foreach (is_array($receipt[$bucket] ?? null) ? $receipt[$bucket] : [] as $draftId => $entry) {
                $ids[(string)$draftId] = is_array($entry) ? (int)($entry['id'] ?? 0) : (int)$entry;
                $sources[(string)$draftId] = $bucket === 'created' ? 'created' : ($bucket === 'replaced' ? 'replaced' : 'reused');
            }
        }

        $rows = [];
        $append = static function (string $collection, array $row) use (&$rows, $approved): void {
            $draftId = trim((string)($row['draftId'] ?? ''));
            if ($draftId === '' || !$approved($draftId)) return;
            $kind = $collection === 'catalogFolders' ? 'directory'
                : ($collection === 'details' ? 'detail'
                    : ($collection === 'stages' ? 'stage'
                        : ($collection === 'globals' ? 'global' : (string)($row['kind'] ?? ''))));
            $parentDraftId = '';
            if ($collection === 'catalogFolders' || $collection === 'details') {
                $parentDraftId = trim((string)($row['parentDraftId'] ?? ''));
            } elseif ($collection === 'catalogObjects') {
                $parentDraftId = trim((string)($row['parentDraftId'] ?? ''));
                if ($parentDraftId === '') $parentDraftId = trim((string)($row['folderDraftId'] ?? ''));
            } elseif ($collection === 'stages') {
                $parentDraftId = trim((string)($row['detailDraftId'] ?? ''));
            }
            $rows[$draftId] = [
                'draftId' => $draftId,
                'kind' => $kind,
                'catalogKind' => $collection === 'catalogFolders' ? (string)($row['kind'] ?? '') : '',
                'title' => (string)($row['title'] ?? ($row['code'] ?? $draftId)),
                'description' => (string)($row['description'] ?? ''),
                'code' => (string)($row['code'] ?? ''),
                'parentDraftId' => $parentDraftId,
            ];
        };
        foreach (['catalogFolders', 'catalogObjects', 'details', 'stages', 'globals'] as $collection) {
            foreach (is_array($draft[$collection] ?? null) ? $draft[$collection] : [] as $row) {
                if (is_array($row)) $append($collection, $row);
            }
        }

        $pathFor = static function (string $draftId) use (&$rows): array {
            $path = [];
            $visited = [];
            $cursor = $draftId;
            while ($cursor !== '' && isset($rows[$cursor]) && !isset($visited[$cursor])) {
                $visited[$cursor] = true;
                array_unshift($path, (string)$rows[$cursor]['title']);
                $cursor = (string)$rows[$cursor]['parentDraftId'];
            }
            return $path;
        };

        $inventory = [];
        foreach ($rows as $draftId => $row) {
            $id = (int)($ids[$draftId] ?? 0);
            if ($row['kind'] === 'global' && $id <= 0 && $workingPresetId > 0) {
                $id = $this->findGlobalId($workingPresetId, (string)$row['code']);
            }
            $spec = [
                'draftId' => $draftId,
                'kind' => $row['kind'],
                'catalogKind' => $row['catalogKind'],
                'id' => $id,
                'workingPresetId' => $workingPresetId,
            ];
            $state = $id > 0 ? $this->readState($spec) : null;
            $path = $pathFor($draftId);
            $actualName = trim((string)($state['name'] ?? ''));
            if ($actualName !== '' && $path !== []) $path[count($path) - 1] = $actualName;
            $inventory[] = [
                'draftId' => $draftId,
                'kind' => $row['kind'],
                'catalogKind' => $row['catalogKind'],
                'id' => $id,
                'name' => $actualName !== '' ? $actualName : $row['title'],
                'description' => $state !== null ? (string)($state['description'] ?? '') : $row['description'],
                'path' => $path,
                'source' => (string)($sources[$draftId] ?? ($row['kind'] === 'global' ? 'reused' : 'unknown')),
                'exists' => $state !== null,
            ];
        }
        return $inventory;
    }

    private function latestReceipt(array $context): array
    {
        $rows=isset($this->adapters['receipts'])?($this->adapters['receipts'])($context):$this->receiptRows(); $matches=[];
        foreach($rows as $row){$receipt=is_array($row)?$row:json_decode((string)$row,true);if(is_array($receipt)&&(int)($receipt['presetId']??0)===self::TARGET_PRESET_ID&&(string)($receipt['versionId']??'')===$context['versionId'])$matches[]=$receipt;}
        usort($matches,static fn(array $a,array $b):int=>strcmp((string)($b['appliedAt']??''),(string)($a['appliedAt']??'')));
        if($matches===[])throw new \RuntimeException('Receipt применённого AI-пилота для версии не найден.',409); return $matches[0];
    }
    private function receiptRows(): array { $connection=Application::getConnection();$helper=$connection->getSqlHelper();$sql="SELECT VALUE FROM b_option WHERE MODULE_ID='".$helper->forSql(self::MODULE_ID)."' AND NAME LIKE 'ai_logic_pilot_receipt_%'";$rows=[];$rs=$connection->query($sql);while($row=$rs->fetch())$rows[]=(string)$row['VALUE'];return $rows; }
    private function findGlobalId(int $presetId,string $code): int { if(isset($this->adapters['find_global']))return(int)($this->adapters['find_global'])($presetId,$code);$config=new \Prospektweb\Calc\Config\ConfigManager();$id=(int)$config->getIblockId('CALC_GLOBAL_VALUES');$row=\CIBlockElement::GetList([],['IBLOCK_ID'=>$id,'=CODE'=>$code,'=PROPERTY_PRESET_ID'=>$presetId],false,['nTopCount'=>1],['ID'])->Fetch();return(int)($row['ID']??0); }
    private function bundle(array $context): array { $bundle=isset($this->adapters['bundle'])?($this->adapters['bundle'])($context):(new CalculatorVersionBundleDocumentService())->load(self::TARGET_PRESET_ID,$context['versionId']);if(!is_array($bundle)||!hash_equals((string)($bundle['contentHash']??''),$context['expectedContentHash']))throw new \RuntimeException('Версия изменилась. Повторите readback.',409);return$bundle; }
    private function draft(array $context,array $receipt): array { $lookup=$context+['appliedAt'=>(string)($receipt['appliedAt']??'')];$stored=isset($this->adapters['draft'])?($this->adapters['draft'])($lookup):(new AiLogicPilotDraftStore())->loadLatestForRepair($lookup);if(!is_array($stored)||($stored['found']??false)!==true)throw new \RuntimeException('Сохранённый AI-черновик не найден.',409);return$stored; }
    private function context(array $request): array { $presetId=(int)($request['presetId']??0);if($presetId!==self::TARGET_PRESET_ID)throw new \RuntimeException('Repair разрешён только для №16488; №12740 и другие пресеты запрещены.',403);$versionId=trim((string)($request['versionId']??$request['versionKey']??''));$base=strtolower(trim((string)($request['baseCompileHash']??'')));$hash=strtolower(trim((string)($request['expectedContentHash']??'')));if(!preg_match('/^v_[a-f0-9]{16,40}$/',$versionId)||!preg_match('/^[a-f0-9]{64}$/',$base)||!preg_match('/^[a-f0-9]{64}$/',$hash))throw new \InvalidArgumentException('Некорректный контекст repair.');return['presetId'=>$presetId,'versionId'=>$versionId,'versionKey'=>$versionId,'baseCompileHash'=>$base,'expectedContentHash'=>$hash]; }
    private function publicReceipt(array $receipt): array { return['manifestHash'=>(string)($receipt['manifestHash']??''),'appliedAt'=>(string)($receipt['appliedAt']??''),'createdCount'=>count((array)($receipt['created']??[])),'reusedCount'=>count((array)($receipt['reused']??[])),'replacedCount'=>count((array)($receipt['replaced']??[]))]; }
    private function optionGet(string $key): ?array { $raw=isset($this->adapters['option_get'])?($this->adapters['option_get'])($key):Option::get(self::MODULE_ID,$key,'');if(is_array($raw))return$raw;if(!is_string($raw)||$raw==='')return null;$value=json_decode($raw,true);return is_array($value)?$value:null; }
    private function optionSet(string $key,array $value): void { $raw=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(isset($this->adapters['option_set'])){($this->adapters['option_set'])($key,$raw);return;}Option::set(self::MODULE_ID,$key,$raw); }
    private function assertAdmin(): void { if(isset($this->adapters['assert_admin'])){($this->adapters['assert_admin'])();return;}global$USER;if(!$USER||!$USER->IsAdmin())throw new \RuntimeException('Недостаточно прав для repair AI-пилота.',403); }
}
