<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Config\Option;

/**
 * Preview/apply boundary for an AI structural pilot.
 *
 * The proposal is data only. No Bitrix row is written before apply(), and
 * apply() accepts only the explicitly scoped wide-format calculator. Prices,
 * formulas and destructive operations are deliberately outside this contract.
 */
final class AiLogicPilotMaterializationService
{
    public const MANIFEST_CONTRACT = 'prospektweb.calc.ai-logic-pilot-manifest/v1';
    public const CANDIDATES_CONTRACT = 'prospektweb.calc.ai-logic-pilot-replacement-candidates/v1';
    public const APPLY_CONTRACT = 'prospektweb.calc.ai-logic-pilot-apply-result/v1';
    private const TARGET_PRESET_ID = 16488;
    private const FORBIDDEN_PRESET_ID = 12740;
    private const MODULE_ID = 'prospektweb.calc';
    private const KINDS = ['directory', 'material', 'materialVariant', 'operation', 'operationVariant', 'equipment', 'customField', 'calculator'];
    private const IBLOCK_BY_KIND = [
        'material' => 'CALC_MATERIALS',
        'materialVariant' => 'CALC_MATERIALS_VARIANTS',
        'operation' => 'CALC_OPERATIONS',
        'operationVariant' => 'CALC_OPERATIONS_VARIANTS',
        'equipment' => 'CALC_EQUIPMENT',
        'customField' => 'CALC_CUSTOM_FIELDS',
        // A calculator catalog object is stage calculation logic, not CALC_PRESETS metadata.
        'calculator' => 'CALC_SETTINGS',
    ];

    /** @var array<string,callable> */
    private array $adapters;

    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /** @return array<string,mixed> */
    public function replacementCandidates(array $request): array
    {
        $this->assertAdmin();
        $context = $this->context($request, false);
        $kinds = is_array($request['kinds'] ?? null) ? $request['kinds'] : self::KINDS;
        $kinds = array_values(array_unique(array_filter(array_map('strval', $kinds), static fn(string $kind): bool => in_array($kind, self::KINDS, true))));
        $rows = isset($this->adapters['candidates'])
            ? ($this->adapters['candidates'])($kinds, $context)
            : $this->loadBitrixCandidates($kinds);
        return ['status' => 'ok', 'contract' => self::CANDIDATES_CONTRACT, 'context' => $context, 'candidates' => array_values($rows)];
    }

    /** @return array<string,mixed> */
    public function preview(array $request): array
    {
        $this->assertAdmin();
        $context = $this->context($request, true);
        $bundle = $this->loadBundle($context);
        $stored = $this->loadDraft($context);
        $expectedRevision = (int)($request['expectedDraftRevision'] ?? $request['draftRevision'] ?? 0);
        if ($expectedRevision <= 0 || $expectedRevision !== (int)($stored['revision'] ?? 0)) {
            throw new \RuntimeException('AI-черновик изменён в другой вкладке. Обновите его и повторите.', 409);
        }
        $context['draftRevision'] = $expectedRevision;
        $draft = is_array($stored['draft'] ?? null) ? $stored['draft'] : [];
        $decisions = is_array($request['decisions'] ?? null) ? $request['decisions'] : (is_array($stored['decisions'] ?? null) ? $stored['decisions'] : []);
        $replacements = is_array($request['replacements'] ?? null) ? $request['replacements'] : (is_array($stored['replacements'] ?? null) ? $stored['replacements'] : []);
        $manifest = $this->buildManifest($draft, $decisions, $replacements, $context, $bundle);
        $manifest['manifestHash'] = $this->hash($manifest);
        return ['status' => 'ok', 'manifest' => $manifest, 'manifestHash' => $manifest['manifestHash'], 'draftRevision' => $expectedRevision];
    }

    /** @return array<string,mixed> */
    public function apply(array $request): array
    {
        $this->assertAdmin();
        if (($request['explicitConfirm'] ?? false) !== true) {
            throw new \InvalidArgumentException('Для создания сущностей требуется явное подтверждение финального списка.');
        }
        $idempotencyKey = trim((string)($request['idempotencyKey'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.:-]{16,180}$/', $idempotencyKey)) {
            throw new \InvalidArgumentException('Некорректный ключ идемпотентности AI-пилота.');
        }
        $initialContext = $this->context($request, true);
        $receiptKey = 'ai_logic_pilot_receipt_' . substr(hash('sha256', self::TARGET_PRESET_ID . ':' . $initialContext['versionId'] . ':' . $idempotencyKey), 0, 40);
        $receipt = $this->receiptGet($receiptKey);
        if ($receipt !== null) {
            $requestedManifestHash = strtolower(trim((string)($request['manifestHash'] ?? '')));
            if ($requestedManifestHash === '' || !hash_equals((string)($receipt['manifestHash'] ?? ''), $requestedManifestHash)) {
                throw new \RuntimeException('Ключ идемпотентности уже использован для другого финального списка.', 409);
            }
            return ['status' => 'ok', 'contract' => self::APPLY_CONTRACT, 'message' => 'AI-пилот уже был применён.', 'idempotentReplay' => true] + $receipt;
        }
        $preview = $this->preview($request);
        $manifest = $preview['manifest'];
        if (($manifest['ready'] ?? false) !== true) {
            throw new \RuntimeException('Финальный список содержит блокирующие ошибки и не может быть применён.', 409);
        }
        $expectedManifestHash = strtolower(trim((string)($request['manifestHash'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedManifestHash) || !hash_equals((string)$manifest['manifestHash'], $expectedManifestHash)) {
            throw new \RuntimeException('Финальный список AI-пилота изменился. Проверьте его повторно.', 409);
        }
        $runner = $this->adapters['transaction'] ?? null;
        $critical = function () use ($manifest, $receiptKey, $idempotencyKey): array {
            // Re-read both authorities inside the transaction; preview is never write authority.
            $bundle = $this->loadBundle($manifest['context']);
            $stored = $this->loadDraft($manifest['context']);
            if ((int)($stored['revision'] ?? 0) !== (int)$manifest['context']['draftRevision']) {
                throw new \RuntimeException('AI-черновик изменился до применения. Проверьте финальный список повторно.', 409);
            }
            $this->assertManifestReplacementsCurrent($manifest);
            $mapping = isset($this->adapters['materialize'])
                ? ($this->adapters['materialize'])($manifest)
                : $this->materializeBitrix($manifest);
            if (!isset($this->adapters['materialize'])) {
                $logic = (new CalculatorVersionSnapshotSourceService())->captureLogic(
                    (int)$manifest['context']['workingPresetId'],
                    self::TARGET_PRESET_ID,
                    (string)$manifest['context']['versionId']
                );
                (new CalculatorVersionComponentDocumentService())->saveDraft(
                    self::TARGET_PRESET_ID,
                    (string)$manifest['context']['versionId'],
                    'logic',
                    (string)$manifest['context']['expectedContentHash'],
                    (string)$bundle['componentHashes']['logic'],
                    $logic
                );
            }
            $receipt = [
                'manifestHash' => $manifest['manifestHash'],
                'idempotencyKey' => $idempotencyKey,
                'presetId' => self::TARGET_PRESET_ID,
                'versionId' => $manifest['context']['versionId'],
                'created' => $mapping['created'] ?? [],
                'reused' => $mapping['reused'] ?? [],
                'replaced' => $mapping['replaced'] ?? [],
                'appliedAt' => gmdate('c'),
            ];
            $this->receiptSet($receiptKey, $receipt);
            return $receipt;
        };
        $receipt = $runner
            ? $runner($critical)
            : (new CalculatorVersionRegistryService())->coordinateVersionMutation(self::TARGET_PRESET_ID, $critical);
        return ['status' => 'ok', 'contract' => self::APPLY_CONTRACT, 'message' => 'AI-пилот применён.', 'idempotentReplay' => false] + $receipt;
    }

    /** @return array<string,mixed> */
    private function buildManifest(array $draft, array $decisions, array $replacements, array $context, array $bundle): array
    {
        if (($draft['schema'] ?? null) !== 'prospektweb.calc.ai-logic-pilot-draft/v1') {
            throw new \InvalidArgumentException('Несовместимая схема AI-черновика.');
        }
        if (!in_array((string)($draft['mode'] ?? 'create'), ['create', 'augment', 'replace'], true)) {
            throw new \InvalidArgumentException('AI-пилот поддерживает только безопасное создание, дополнение или подстановку без удаления.');
        }
        $all = [];
        foreach (['catalogFolders', 'catalogObjects', 'globals', 'details', 'stages', 'groups'] as $collection) {
            foreach (is_array($draft[$collection] ?? null) ? $draft[$collection] : [] as $row) {
                if (!is_array($row)) throw new \InvalidArgumentException('AI-черновик содержит некорректный объект.');
                $id = trim((string)($row['draftId'] ?? ''));
                if (!preg_match('/^draft_[a-z0-9][a-z0-9_-]*$/i', $id) || isset($all[$id])) throw new \InvalidArgumentException('AI-черновик содержит повторный или некорректный draftId.');
                $all[$id] = ['collection' => $collection, 'row' => $row];
            }
        }
        $groups = array_fill_keys(self::KINDS, []);
        $structure = ['globals' => [], 'details' => [], 'stages' => [], 'groups' => []];
        $blockers = [];
        $warnings = [];
        foreach ($decisions as $draftId => $decision) {
            if (!is_string($draftId) || !preg_match('/^draft_[a-z0-9][a-z0-9_-]*$/i', $draftId)
                || !in_array($decision, ['approved', 'rejected'], true)) {
                throw new \InvalidArgumentException('Некорректное решение по финальному списку AI-пилота.');
            }
        }
        $approved = static fn(string $id): bool => ($decisions[$id] ?? 'approved') !== 'rejected';
        $knownDecisionIds = array_fill_keys(array_keys($all), true);
        foreach ($all as $entry) if ($entry['collection'] === 'groups') {
            foreach (is_array($entry['row']['branches'] ?? null) ? $entry['row']['branches'] : [] as $branch) {
                $branchId = trim((string)($branch['draftId'] ?? ''));
                if ($branchId !== '') $knownDecisionIds[$branchId] = true;
            }
        }
        $pathMemo = [];
        $pathFor = function (string $id) use (&$pathFor, &$pathMemo, $all, &$blockers): array {
            if (isset($pathMemo[$id])) return $pathMemo[$id];
            $item = $all[$id]['row'] ?? null;
            if (!is_array($item)) return [];
            $parent = trim((string)($item['parentDraftId'] ?? $item['folderDraftId'] ?? ''));
            if ($parent !== '' && !isset($all[$parent])) { $blockers[] = 'Не найден родитель ' . $parent . ' для ' . $id . '.'; return []; }
            $title = $this->text($item['title'] ?? '', 'Название ' . $id, 250);
            return $pathMemo[$id] = array_merge($parent !== '' ? $pathFor($parent) : [], [$title]);
        };
        foreach ($all as $id => $entry) {
            if (!$approved($id)) continue;
            $row = $entry['row'];
            $replacement = is_array($replacements[$id] ?? null) ? $this->replacement($id, $replacements[$id]) : null;
            if ($entry['collection'] === 'catalogFolders') {
                $catalogKind = trim((string)($row['kind'] ?? ''));
                if (!isset(self::IBLOCK_BY_KIND[$catalogKind])) $blockers[] = 'Каталог папки ' . $id . ' не поддерживается.';
                $groups['directory'][] = $this->manifestRow($id, 'directory', $row, $pathFor($id), $replacement, [
                    'catalogKind' => $catalogKind,
                    'parentDraftId' => $row['parentDraftId'] ?? null,
                ]);
            } elseif ($entry['collection'] === 'catalogObjects') {
                $kind = trim((string)($row['kind'] ?? ''));
                if (!isset(self::IBLOCK_BY_KIND[$kind])) { $blockers[] = 'Тип объекта ' . $kind . ' не поддерживается.'; continue; }
                $parent = trim((string)($row['parentDraftId'] ?? ''));
                if (in_array($kind, ['materialVariant', 'operationVariant'], true) && ($parent === '' || !isset($all[$parent]) || !$approved($parent))) {
                    $blockers[] = 'Вариант ' . $id . ' требует утверждённый родительский объект.';
                }
                $groups[$kind][] = $this->manifestRow($id, $kind, $row, $pathFor($id), $replacement, [
                    'folderDraftId' => $row['folderDraftId'] ?? null,
                    'parentDraftId' => $row['parentDraftId'] ?? null,
                ]);
            } else {
                $row['title'] = $this->text($row['title'] ?? '', 'Название ' . $id, 250);
                foreach (['parentDraftId', 'detailDraftId'] as $refKey) {
                    $ref = trim((string)($row[$refKey] ?? ''));
                    if ($ref !== '' && (!isset($all[$ref]) || !$approved($ref))) $blockers[] = $id . ' ссылается на неутверждённый ' . $ref . '.';
                }
                foreach (['catalogDraftIds', 'stageDraftIds'] as $refsKey) {
                    foreach (is_array($row[$refsKey] ?? null) ? $row[$refsKey] : [] as $ref) {
                        if (!isset($all[$ref]) || !$approved((string)$ref)) $blockers[] = $id . ' ссылается на неутверждённый ' . $ref . '.';
                    }
                }
                if ($entry['collection'] === 'groups' && is_array($row['branches'] ?? null)) {
                    $row['branches'] = array_values(array_filter($row['branches'], static fn(array $branch): bool => $approved((string)($branch['draftId'] ?? ''))));
                    foreach ($row['branches'] as $branch) foreach (is_array($branch['stageDraftIds'] ?? null) ? $branch['stageDraftIds'] : [] as $ref) {
                        if (!isset($all[$ref]) || !$approved((string)$ref)) $blockers[] = $id . ' содержит ветку со ссылкой на неутверждённый ' . $ref . '.';
                    }
                    if (($row['kind'] ?? '') === 'condition') {
                        $elseCount = count(array_filter($row['branches'], static fn(array $branch): bool => ($branch['isElse'] ?? false) === true));
                        if (count($row['branches']) < 2 || $elseCount !== 1) $blockers[] = 'Условие ' . $id . ' после решений должно иметь обычную ветку и одну ветку «Иначе».';
                    }
                }
                $structure[$entry['collection']][] = ['draftId' => $id, 'action' => 'create', 'data' => $row];
            }
        }
        $catalogKindByDraftId = [];
        foreach ($groups as $kind => $rows) foreach ($rows as $row) $catalogKindByDraftId[(string)$row['draftId']] = $kind;
        foreach ($structure['stages'] as $item) {
            $stageName = (string)($item['data']['title'] ?? $item['draftId']);
            $refs = is_array($item['data']['catalogDraftIds'] ?? null) ? $item['data']['catalogDraftIds'] : [];
            $calculatorCount = count(array_filter($refs, static fn($ref): bool => ($catalogKindByDraftId[(string)$ref] ?? '') === 'calculator'));
            if ($calculatorCount !== 1) $blockers[] = 'Этап «' . $stageName . '» должен иметь ровно один утверждённый калькулятор логики.';
            foreach ($refs as $ref) {
                $kind = $catalogKindByDraftId[(string)$ref] ?? '';
                if (in_array($kind, ['directory', 'material', 'operation'], true)) {
                    $blockers[] = 'Этап «' . $stageName . '» должен ссылаться на вид материала/операции, оборудование и калькулятор, а не на базовый объект или путь.';
                }
            }
        }
        foreach ($decisions as $draftId => $_decision) if (!isset($knownDecisionIds[(string)$draftId])) $blockers[] = 'Решение относится к неизвестному объекту ' . $draftId . '.';
        foreach ($replacements as $id => $_replacement) if (!isset($all[$id])) $blockers[] = 'Замена относится к неизвестному объекту ' . $id . '.';
        if ($replacements !== []) {
            $candidateKinds = array_values(array_unique(array_map(static fn(array $replacement): string => (string)($replacement['realKind'] ?? ''), array_values($replacements))));
            $candidateRows = isset($this->adapters['candidates'])
                ? ($this->adapters['candidates'])($candidateKinds, $context)
                : $this->loadBitrixCandidates($candidateKinds);
            $candidateIndex = [];
            foreach ($candidateRows as $candidate) {
                $candidateKey = (string)($candidate['realKind'] ?? '') . ':'
                    . ((string)($candidate['realKind'] ?? '') === 'directory' ? (string)($candidate['catalogKind'] ?? '') . ':' : '')
                    . (int)($candidate['realId'] ?? 0);
                $candidateIndex[$candidateKey] = $candidate;
            }
            foreach ($replacements as $draftId => $replacement) {
                $key = (string)($replacement['realKind'] ?? '') . ':'
                    . ((string)($replacement['realKind'] ?? '') === 'directory' ? (string)($replacement['catalogKind'] ?? '') . ':' : '')
                    . (int)($replacement['realId'] ?? 0);
                $candidate = $candidateIndex[$key] ?? null;
                $draftEntry = $all[(string)$draftId] ?? null;
                $expectedKind = ($draftEntry['collection'] ?? '') === 'catalogFolders'
                    ? 'directory'
                    : (string)($draftEntry['row']['kind'] ?? '');
                if ($expectedKind !== '' && $expectedKind !== (string)($replacement['realKind'] ?? '')) {
                    $blockers[] = 'Тип реального объекта замены для ' . $draftId . ' не совпадает с AI-объектом.';
                }
                if ($expectedKind === 'directory'
                    && (string)($draftEntry['row']['kind'] ?? '') !== (string)($replacement['catalogKind'] ?? '')) {
                    $blockers[] = 'Реальный путь замены для ' . $draftId . ' относится к другому каталогу Bitrix.';
                }
                if (!$candidate) { $blockers[] = 'Реальный объект замены для ' . $draftId . ' больше не существует.'; continue; }
                $expectedRevision = trim((string)($replacement['expectedRevision'] ?? ''));
                if ($expectedRevision !== '' && !hash_equals((string)($candidate['expectedRevision'] ?? ''), $expectedRevision)) {
                    $blockers[] = 'Реальный объект замены для ' . $draftId . ' изменился.';
                }
            }
        }
        $counts = [];
        foreach ($groups as $kind => $rows) $counts[$kind] = count($rows);
        foreach ($structure as $kind => $rows) $counts[$kind] = count($rows);
        $logic = is_array($bundle['documents']['logic'] ?? null) ? $bundle['documents']['logic'] : [];
        $workingPresetId = (int)($logic['workingPresetId'] ?? 0);
        if ($workingPresetId <= 0 || $workingPresetId === self::FORBIDDEN_PRESET_ID) $blockers[] = 'У версии нет безопасного изолированного рабочего графа.';
        $graph = is_array($logic['graph'] ?? null) ? $logic['graph'] : [];
        $existingDetailIds = array_values(array_filter(array_map('intval', is_array($graph['detailIds'] ?? null) ? $graph['detailIds'] : [])));
        $existingStageIds = array_values(array_filter(array_map('intval', is_array($graph['stageIds'] ?? null) ? $graph['stageIds'] : [])));
        $existingSettingsIds = array_values(array_filter(array_map('intval', is_array($graph['settingsIds'] ?? null) ? $graph['settingsIds'] : [])));
        if (count($existingDetailIds) === 1 && $existingStageIds === [] && $existingSettingsIds === []) {
            foreach ($structure['details'] as &$detailItem) {
                if (($detailItem['action'] ?? '') === 'create' && trim((string)($detailItem['data']['parentDraftId'] ?? '')) === '') {
                    $detailItem['action'] = 'reuse';
                    $detailItem['realId'] = $existingDetailIds[0];
                    break;
                }
            }
            unset($detailItem);
        }
        $manifest = [
            'contract' => self::MANIFEST_CONTRACT,
            'context' => $context + ['workingPresetId' => $workingPresetId],
            'groups' => $groups,
            'structure' => $structure,
            'counts' => $counts,
            'warnings' => array_values(array_unique($warnings)),
            'blockers' => array_values(array_unique($blockers)),
            'ready' => $blockers === [],
        ];
        return $manifest;
    }

    private function manifestRow(string $id, string $kind, array $row, array $path, ?array $replacement, array $extra = []): array
    {
        return ['draftId' => $id, 'kind' => $kind, 'action' => $replacement ? 'replace' : 'create',
            'name' => $this->text($row['title'] ?? '', 'Название ' . $id, 250),
            'description' => mb_substr(trim((string)($row['description'] ?? '')), 0, 4000),
            'path' => $path, 'replacement' => $replacement] + $extra;
    }

    private function replacement(string $draftId, array $row): array
    {
        $kind = trim((string)($row['realKind'] ?? ''));
        $id = (int)($row['realId'] ?? 0);
        if (!in_array($kind, self::KINDS, true) || $id <= 0) throw new \InvalidArgumentException('Некорректная замена для ' . $draftId . '.');
        if ($kind === 'directory' && !isset(self::IBLOCK_BY_KIND[trim((string)($row['catalogKind'] ?? ''))])) {
            throw new \InvalidArgumentException('Для замены каталога ' . $draftId . ' требуется catalogKind.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)($row['expectedRevision'] ?? ''))))) {
            throw new \InvalidArgumentException('Для замены ' . $draftId . ' требуется актуальная expectedRevision.');
        }
        return ['realKind' => $kind, 'realId' => $id, 'catalogKind' => trim((string)($row['catalogKind'] ?? '')),
            'iblockCode' => trim((string)($row['iblockCode'] ?? '')), 'expectedRevision' => trim((string)($row['expectedRevision'] ?? ''))];
    }

    private function assertManifestReplacementsCurrent(array $manifest): void
    {
        $expected = [];
        foreach ($manifest['groups'] as $rows) foreach ($rows as $row) {
            if (($row['action'] ?? '') !== 'replace') continue;
            $replacement = $row['replacement'];
            $key = (string)$replacement['realKind'] . ':'
                . ((string)$replacement['realKind'] === 'directory' ? (string)($replacement['catalogKind'] ?? '') . ':' : '')
                . (int)$replacement['realId'];
            $expected[$key] = (string)($replacement['expectedRevision'] ?? '');
        }
        if ($expected === []) return;
        $kinds = array_values(array_unique(array_map(static fn(string $key): string => explode(':', $key, 2)[0], array_keys($expected))));
        $rows = isset($this->adapters['candidates'])
            ? ($this->adapters['candidates'])($kinds, $manifest['context'])
            : $this->loadBitrixCandidates($kinds);
        foreach ($rows as $candidate) {
            $key = (string)($candidate['realKind'] ?? '') . ':'
                . ((string)($candidate['realKind'] ?? '') === 'directory' ? (string)($candidate['catalogKind'] ?? '') . ':' : '')
                . (int)($candidate['realId'] ?? 0);
            if (!array_key_exists($key, $expected)) continue;
            if ($expected[$key] === '' || hash_equals($expected[$key], (string)($candidate['expectedRevision'] ?? ''))) unset($expected[$key]);
        }
        if ($expected !== []) throw new \RuntimeException('Один из реальных объектов замены изменён или удалён. Проверьте финальный список повторно.', 409);
    }

    /** @return array<string,mixed> */
    private function context(array $request, bool $requireExpectedHash): array
    {
        $presetId = (int)($request['presetId'] ?? 0);
        if ($presetId !== self::TARGET_PRESET_ID) throw new \RuntimeException('AI-пилот разрешён только для калькулятора широкоформатной печати №16488.', 403);
        $versionId = trim((string)($request['versionId'] ?? $request['versionKey'] ?? ''));
        $baseCompileHash = strtolower(trim((string)($request['baseCompileHash'] ?? '')));
        $expectedContentHash = strtolower(trim((string)($request['expectedContentHash'] ?? '')));
        if (!preg_match('/^v_[a-f0-9]{16,40}$/', $versionId) || !preg_match('/^[a-f0-9]{64}$/', $baseCompileHash)
            || ($requireExpectedHash && !preg_match('/^[a-f0-9]{64}$/', $expectedContentHash))) {
            throw new \InvalidArgumentException('Некорректный контекст версии AI-пилота.');
        }
        return ['presetId' => $presetId, 'versionId' => $versionId, 'versionKey' => $versionId,
            'baseCompileHash' => $baseCompileHash, 'expectedContentHash' => $expectedContentHash];
    }

    private function loadBundle(array $context): array
    {
        $bundle = isset($this->adapters['bundle'])
            ? ($this->adapters['bundle'])($context)
            : (new CalculatorVersionBundleDocumentService())->load(self::TARGET_PRESET_ID, (string)$context['versionId']);
        if (!is_array($bundle)) throw new \RuntimeException('Полный bundle выбранной версии отсутствует.', 409);
        if (!hash_equals((string)($bundle['contentHash'] ?? ''), (string)$context['expectedContentHash'])) throw new \RuntimeException('Версия изменилась. Обновите AI-черновик.', 409);
        return $bundle;
    }

    private function loadDraft(array $context): array
    {
        $stored = isset($this->adapters['draft'])
            ? ($this->adapters['draft'])($context)
            : (new AiLogicPilotDraftStore())->load($context);
        if (!is_array($stored) || ($stored['found'] ?? false) !== true) throw new \RuntimeException('Сохранённый AI-черновик не найден.', 409);
        return $stored;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadBitrixCandidates(array $kinds): array
    {
        $config = new \Prospektweb\Calc\Config\ConfigManager();
        $result = [];
        $includeDirectories = in_array('directory', $kinds, true);
        $catalogKinds = array_values(array_filter($kinds, static fn(string $kind): bool => $kind !== 'directory'));
        if ($includeDirectories && $catalogKinds === []) $catalogKinds = array_keys(self::IBLOCK_BY_KIND);
        foreach ($catalogKinds as $kind) {
            $iblockCode = self::IBLOCK_BY_KIND[$kind] ?? null;
            if ($iblockCode === null) continue;
            $iblockId = (int)$config->getIblockId($iblockCode);
            $sections = [];
            $rsSection = \CIBlockSection::GetList(['LEFT_MARGIN' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'], false, ['ID', 'NAME', 'DESCRIPTION', 'IBLOCK_SECTION_ID', 'TIMESTAMP_X', 'ACTIVE']);
            while ($section = $rsSection->Fetch()) $sections[(int)$section['ID']] = $section;
            $path = static function (int $id) use (&$path, $sections): array {
                if ($id <= 0 || !isset($sections[$id])) return [];
                return array_merge($path((int)$sections[$id]['IBLOCK_SECTION_ID']), [(string)$sections[$id]['NAME']]);
            };
            if ($includeDirectories) foreach ($sections as $section) $result[] = ['realKind' => 'directory', 'catalogKind' => $kind, 'iblockCode' => $iblockCode,
                'realId' => (int)$section['ID'], 'title' => (string)$section['NAME'], 'path' => $path((int)$section['ID']),
                'expectedRevision' => hash('sha256', implode(':', [$iblockCode, 'section', $section['ID'], $section['TIMESTAMP_X'], $section['ACTIVE'], $section['IBLOCK_SECTION_ID'], $section['NAME'], $section['DESCRIPTION']]))];
            $rs = \CIBlockElement::GetList(['NAME' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'], false, false, ['ID', 'NAME', 'PREVIEW_TEXT', 'IBLOCK_SECTION_ID', 'TIMESTAMP_X', 'ACTIVE']);
            while ($row = $rs->Fetch()) $result[] = ['realKind' => $kind, 'realId' => (int)$row['ID'], 'title' => (string)$row['NAME'],
                'description' => (string)$row['PREVIEW_TEXT'], 'path' => array_merge($path((int)$row['IBLOCK_SECTION_ID']), [(string)$row['NAME']]),
                'expectedRevision' => hash('sha256', implode(':', [$iblockCode, 'element', $row['ID'], $row['TIMESTAMP_X'], $row['ACTIVE'], $row['IBLOCK_SECTION_ID'], $row['NAME'], $row['PREVIEW_TEXT']]))];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function materializeBitrix(array $manifest): array
    {
        $workingPresetId = (int)$manifest['context']['workingPresetId'];
        if ($workingPresetId <= 0 || $workingPresetId === self::FORBIDDEN_PRESET_ID) throw new \RuntimeException('Запрещённый рабочий граф.', 409);
        $authority = new CalculatorMutationAuthorityService();
        return $authority->withAuthorityLock($workingPresetId, function (bool $_protected, array $iblocks) use ($manifest, $workingPresetId, $authority): array {
            $created = []; $reused = []; $replaced = []; $ids = [];
            foreach ($manifest['groups'] as $rows) foreach ($rows as $row) if (($row['action'] ?? '') === 'replace') {
                $id = (int)$row['replacement']['realId']; $ids[$row['draftId']] = $id; $replaced[$row['draftId']] = $id;
            }
            $directoryRows = $manifest['groups']['directory'];
            usort($directoryRows, static fn(array $left, array $right): int => count($left['path'] ?? []) <=> count($right['path'] ?? []));
            foreach ($directoryRows as $row) {
                if ($row['action'] !== 'create') continue;
                $iblockCode = self::IBLOCK_BY_KIND[$row['catalogKind']] ?? '';
                $iblockId = (int)($iblocks[$iblockCode] ?? 0);
                $parentId = isset($ids[(string)($row['parentDraftId'] ?? '')]) ? (int)$ids[(string)$row['parentDraftId']] : 0;
                $draftId = $row['draftId'];
                $section = new \CIBlockSection();
                $id = (int)$section->Add(['IBLOCK_ID' => $iblockId, 'IBLOCK_SECTION_ID' => $parentId, 'ACTIVE' => 'Y', 'NAME' => $row['name'], 'DESCRIPTION' => $row['description']]);
                if ($id <= 0) throw new \RuntimeException('Не удалось создать каталог «' . $row['name'] . '»: ' . $section->LAST_ERROR);
                $ids[$draftId] = $id; $created[$draftId] = ['kind' => 'directory', 'id' => $id, 'iblockCode' => $iblockCode];
            }
            foreach (['material','operation','equipment','customField','calculator','materialVariant','operationVariant'] as $kind) {
                foreach ($manifest['groups'][$kind] as $row) {
                    if ($row['action'] !== 'create') continue;
                    $iblockCode = self::IBLOCK_BY_KIND[$kind]; $iblockId = (int)($iblocks[$iblockCode] ?? 0);
                    $fields = [
                        'IBLOCK_ID' => $iblockId,
                        'ACTIVE' => 'Y',
                        'NAME' => $row['name'],
                        'CODE' => $this->elementCode($kind, (string)$row['draftId']),
                        'PREVIEW_TEXT' => $row['description'],
                        'PREVIEW_TEXT_TYPE' => 'text',
                    ];
                    $folderDraftId = (string)($row['folderDraftId'] ?? '');
                    if ($folderDraftId !== '' && isset($ids[$folderDraftId])) $fields['IBLOCK_SECTION_ID'] = $ids[$folderDraftId];
                    $element = new \CIBlockElement(); $id = (int)$element->Add($fields);
                    if ($id <= 0) throw new \RuntimeException('Не удалось создать «' . $row['name'] . '»: ' . $element->LAST_ERROR);
                    $parentDraftId = (string)($row['parentDraftId'] ?? '');
                    if ($parentDraftId !== '' && isset($ids[$parentDraftId]) && in_array($kind, ['materialVariant','operationVariant'], true)) {
                        \CIBlockElement::SetPropertyValuesEx($id, $iblockId, ['CML2_LINK' => $ids[$parentDraftId]]);
                    }
                    $ids[$row['draftId']] = $id; $created[$row['draftId']] = ['kind' => $kind, 'id' => $id, 'iblockCode' => $iblockCode];
                }
            }
            // Structural entities are intentionally created without formulas or prices.
            foreach ($manifest['structure']['details'] as $item) {
                if (($item['action'] ?? '') !== 'reuse') continue;
                $row = $item['data'];
                $id = (int)($item['realId'] ?? 0);
                if ($id <= 0) throw new \RuntimeException('Не определена существующая основа расчётной схемы.', 409);
                $element = new \CIBlockElement();
                if (!$element->Update($id, ['NAME' => (string)$row['title'], 'PREVIEW_TEXT' => (string)($row['description'] ?? ''), 'PREVIEW_TEXT_TYPE' => 'text'])) {
                    throw new \RuntimeException('Не удалось подготовить основу AI-пилота: ' . $element->LAST_ERROR);
                }
                $type = strtoupper((string)($row['kind'] ?? 'detail')) === 'BINDING' ? 'BINDING' : 'DETAIL';
                $enum = \CIBlockPropertyEnum::GetList([], ['IBLOCK_ID' => (int)$iblocks['CALC_DETAILS'], 'CODE' => 'TYPE', 'XML_ID' => $type])->Fetch();
                if (!$enum) throw new \RuntimeException('Не настроен тип детали ' . $type . '.', 409);
                \CIBlockElement::SetPropertyValuesEx($id, (int)$iblocks['CALC_DETAILS'], ['TYPE' => (int)$enum['ID']]);
                $ids[$item['draftId']] = $id;
                $reused[$item['draftId']] = ['kind' => 'detail', 'id' => $id];
            }
            foreach ($manifest['structure']['details'] as $item) {
                if (($item['action'] ?? '') !== 'create') continue;
                $row = $item['data']; $iblockId = (int)$iblocks['CALC_DETAILS']; $element = new \CIBlockElement();
                $id = (int)$element->Add([
                    'IBLOCK_ID' => $iblockId,
                    'ACTIVE' => 'Y',
                    'NAME' => (string)$row['title'],
                    'CODE' => $this->elementCode('detail', (string)$item['draftId']),
                    'PREVIEW_TEXT' => (string)($row['description'] ?? ''),
                    'PREVIEW_TEXT_TYPE' => 'text',
                ]);
                if ($id <= 0) throw new \RuntimeException('Не удалось создать деталь AI-пилота: ' . $element->LAST_ERROR);
                $type = strtoupper((string)($row['kind'] ?? 'detail')) === 'BINDING' ? 'BINDING' : 'DETAIL';
                $enum = \CIBlockPropertyEnum::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => 'TYPE', 'XML_ID' => $type])->Fetch();
                if (!$enum) throw new \RuntimeException('Не настроен тип детали ' . $type . '.', 409);
                \CIBlockElement::SetPropertyValuesEx($id, $iblockId, ['TYPE' => (int)$enum['ID']]);
                $ids[$item['draftId']] = $id; $created[$item['draftId']] = ['kind' => 'detail', 'id' => $id];
            }
            foreach ($manifest['structure']['stages'] as $item) {
                $row = $item['data']; $iblockId = (int)$iblocks['CALC_STAGES']; $element = new \CIBlockElement();
                $id = (int)$element->Add([
                    'IBLOCK_ID' => $iblockId,
                    'ACTIVE' => 'Y',
                    'NAME' => (string)$row['title'],
                    'CODE' => $this->elementCode('stage', (string)$item['draftId']),
                    'PREVIEW_TEXT' => (string)($row['description'] ?? ''),
                    'PREVIEW_TEXT_TYPE' => 'text',
                ]);
                if ($id <= 0) throw new \RuntimeException('Не удалось создать этап AI-пилота: ' . $element->LAST_ERROR);
                $properties = [];
                foreach (is_array($row['catalogDraftIds'] ?? null) ? $row['catalogDraftIds'] : [] as $catalogDraftId) {
                    $catalogKind = null;
                    foreach ($manifest['groups'] as $kind => $catalogRows) foreach ($catalogRows as $catalogRow) if ($catalogRow['draftId'] === $catalogDraftId) $catalogKind = $kind;
                    $property = ['calculator'=>'CALC_SETTINGS','materialVariant'=>'MATERIAL_VARIANT','operationVariant'=>'OPERATION_VARIANT','equipment'=>'EQUIPMENT'][$catalogKind] ?? null;
                    if ($property && isset($ids[$catalogDraftId])) $properties[$property] = $ids[$catalogDraftId];
                }
                if ($properties) \CIBlockElement::SetPropertyValuesEx($id, $iblockId, $properties);
                $ids[$item['draftId']] = $id; $created[$item['draftId']] = ['kind' => 'stage', 'id' => $id];
            }
            $stagesByDetail = [];
            foreach ($manifest['structure']['stages'] as $item) {
                $detailDraftId = (string)($item['data']['detailDraftId'] ?? '');
                if (isset($ids[$detailDraftId], $ids[$item['draftId']])) $stagesByDetail[$detailDraftId][] = $ids[$item['draftId']];
            }
            foreach ($stagesByDetail as $detailDraftId => $stageIds) {
                \CIBlockElement::SetPropertyValuesEx((int)$ids[$detailDraftId], (int)$iblocks['CALC_DETAILS'], ['CALC_STAGES' => array_values($stageIds)]);
            }
            $childrenByBinding = [];
            $topLevelDetails = [];
            foreach ($manifest['structure']['details'] as $item) {
                $parentDraftId = trim((string)($item['data']['parentDraftId'] ?? ''));
                if ($parentDraftId !== '' && isset($ids[$parentDraftId])) $childrenByBinding[$parentDraftId][] = $ids[$item['draftId']];
                else $topLevelDetails[] = $ids[$item['draftId']];
            }
            foreach ($childrenByBinding as $bindingDraftId => $children) {
                \CIBlockElement::SetPropertyValuesEx((int)$ids[$bindingDraftId], (int)$iblocks['CALC_DETAILS'], ['DETAILS' => array_values($children)]);
            }
            if ($topLevelDetails !== []) {
                $existingElement = \CIBlockElement::GetList([], ['ID' => $workingPresetId, 'IBLOCK_ID' => (int)$iblocks['CALC_PRESETS']], false, ['nTopCount' => 1], ['ID'])->GetNextElement();
                $existingProperties = $existingElement ? $existingElement->GetProperties() : [];
                $existingRoots = is_array($existingProperties['CALC_DETAILS']['VALUE'] ?? null)
                    ? array_map('intval', $existingProperties['CALC_DETAILS']['VALUE'])
                    : array_filter([(int)($existingProperties['CALC_DETAILS']['VALUE'] ?? 0)]);
                \CIBlockElement::SetPropertyValuesEx($workingPresetId, (int)$iblocks['CALC_PRESETS'], ['CALC_DETAILS' => array_values(array_unique(array_merge($existingRoots, $topLevelDetails)))]);
            }
            if ($manifest['structure']['globals'] !== []) {
                $globalsService = new GlobalSymbolService();
                $existing = $globalsService->listReadOnlyFromIblockId((int)$iblocks['CALC_GLOBAL_VALUES'], $workingPresetId);
                $newGlobals = [];
                foreach ($manifest['structure']['globals'] as $item) {
                    $row = $item['data'];
                    $newGlobals[] = ['id' => 0, 'code' => (string)($row['code'] ?? ''), 'title' => (string)$row['title'],
                        'description' => (string)($row['description'] ?? ''), 'kind' => ($row['kind'] ?? '') === 'variable' ? 'variable' : 'constant',
                        'dataType' => (string)($row['dataType'] ?? 'auto'), 'initialValue' => ''];
                }
                $globalsService->saveLocked(array_merge($existing, $newGlobals), $workingPresetId, $authority, $iblocks);
            }
            if ($manifest['structure']['groups'] !== []) {
                $groups = [];
                foreach ($manifest['structure']['groups'] as $item) {
                    $row = $item['data'];
                    $convertStageIds = static function (array $draftIds) use ($ids): array {
                        return array_values(array_filter(array_map(static fn($draftId): int => (int)($ids[(string)$draftId] ?? 0), $draftIds)));
                    };
                    $branches = [];
                    foreach (is_array($row['branches'] ?? null) ? $row['branches'] : [] as $branch) {
                        $branches[] = ['id' => (string)($branch['draftId'] ?? ''), 'title' => (string)($branch['title'] ?? ''),
                            'mode' => (string)($branch['mode'] ?? 'and'), 'operands' => is_array($branch['operands'] ?? null) ? $branch['operands'] : [],
                            'stageIds' => $convertStageIds(is_array($branch['stageDraftIds'] ?? null) ? $branch['stageDraftIds'] : []), 'isElse' => ($branch['isElse'] ?? false) === true];
                    }
                    $groups[] = ['id' => (string)$item['draftId'], 'kind' => (string)($row['kind'] ?? 'group'), 'title' => (string)$row['title'],
                        'description' => (string)($row['description'] ?? ''), 'parentId' => $row['parentDraftId'] ?? null,
                        'stageIds' => $convertStageIds(is_array($row['stageDraftIds'] ?? null) ? $row['stageDraftIds'] : []), 'branches' => $branches];
                }
                (new StageGroupService($iblocks))->save(['presetId' => $workingPresetId, 'groups' => $groups], false);
            }
            return ['created' => $created, 'reused' => $reused, 'replaced' => $replaced];
        });
    }

    private function receiptGet(string $key): ?array
    {
        $raw = isset($this->adapters['receipt_get']) ? ($this->adapters['receipt_get'])($key) : Option::get(self::MODULE_ID, $key, '');
        if (is_array($raw)) return $raw;
        if (!is_string($raw) || $raw === '') return null;
        $value = json_decode($raw, true);
        return is_array($value) ? $value : null;
    }

    private function receiptSet(string $key, array $value): void
    {
        $raw = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (isset($this->adapters['receipt_set'])) { ($this->adapters['receipt_set'])($key, $raw); return; }
        Option::set(self::MODULE_ID, $key, $raw);
    }

    private function assertAdmin(): void
    {
        if (isset($this->adapters['assert_admin'])) { ($this->adapters['assert_admin'])(); return; }
        global $USER;
        if (!$USER || !$USER->IsAdmin()) throw new \RuntimeException('Недостаточно прав для AI-пилота.', 403);
    }

    private function text($value, string $label, int $max): string
    {
        $value = trim((string)$value);
        $value = preg_replace('/^Виртуальн(?:ый|ая|ое|ые)\s+(?:вид материала|вид операции|материал|операция|оборудование|дополнительное поле|калькулятор|раздел|папка)\s*:\s*/ui', '', $value) ?? $value;
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) throw new \InvalidArgumentException($label . ' не заполнено или слишком длинное.');
        return $value;
    }

    private function elementCode(string $kind, string $draftId): string
    {
        $kind = strtolower((string)(preg_replace('/[^a-z0-9]+/i', '_', $kind) ?? 'entity'));
        $kind = trim($kind, '_') ?: 'entity';
        return 'ai_pilot_' . $kind . '_' . substr(hash('sha256', $draftId), 0, 16);
    }

    private function hash(array $value): string
    {
        unset($value['manifestHash']);
        $normalize = function ($item) use (&$normalize) {
            if (!is_array($item)) return $item;
            if (array_keys($item) !== range(0, count($item) - 1)) ksort($item, SORT_STRING);
            foreach ($item as $key => $nested) $item[$key] = $normalize($nested);
            return $item;
        };
        return hash('sha256', json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
