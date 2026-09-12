<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** Immutable preview receipts. Every operation serializes with version saves. */
final class DocumentCalculationSnapshots
{
    public function __construct(private SqlConnection $db, private string $scope, private string $actor, private DocumentRepository $documents) {}

    /** Exclude presentation metadata only. Unknown semantic attributes fail closed.
     * Form conditions reference form field IDs, not execution/global outputs.
     * Arrays representing identified sets are sorted; ordered rule arrays stay ordered. */
    public static function signature(string $json, ?string $storefront = null): string
    {
        $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if ($storefront !== null) $document['presentations']['views'] = array_values(array_filter($document['presentations']['views'] ?? [], fn($view) => $view['id'] === $storefront));
        $clean = static function ($value, string $parent = '') use (&$clean) {
            if (!is_array($value)) return $value;
            if (array_is_list($value)) {
                $result = array_map(fn($item) => $clean($item, $parent), $value);
                if (in_array($parent, ['fields', 'sections', 'options', 'presets', 'values', 'views', 'fieldIds', 'sectionIds', 'hidden_value_ids', 'linkedFieldIds', 'dependentFieldIds'], true)) {
                    usort($result, fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
                }
                return $result;
            }
            $result = [];
            foreach ($value as $key => $item) {
                if (in_array($key, ['name', 'label', 'title', 'description', 'help', 'hint', 'tooltip', 'sort', 'order', 'layout', 'columns', 'linkedColumns', 'showHelpOnSite', 'showLabel', 'showUnit', 'pdfContourHint', 'image', 'icon', 'placeholder', 'className', 'style', 'displayMode', 'initiallyOpen', 'showTitle', 'showModeFrame', 'displayKeys', 'display_preset_xml_ids', 'display_mode', 'value_labels', 'show_label', 'show_unit', 'show_presets', 'pdf_contour_hint', 'open_popup_chip_label', 'group_delimiter', 'deadline_adjustments'], true)) continue;
                $next = $clean($item, $key);
                if ($parent === 'field_patches' && $next === []) continue;
                $result[$key] = $next;
            }
            ksort($result); return $result;
        };
        return hash('sha256', json_encode($clean(['form' => $document['form'] ?? [], 'presentations' => $document['presentations'] ?? []]), JSON_THROW_ON_ERROR));
    }

    /** Called only inside the existing document mutation transaction, after CAS. */
    public function guardSave(string $id, string $version, string $before, string $after, bool $confirmed): void
    {
        if (!$this->db->inTransaction()) throw new \LogicException('Snapshot guard requires document lock.');
        $rows = $this->db->rows('SELECT id, storefront_id FROM b_pw_calc_snapshot WHERE scope_id = ? AND document_id = ? AND version_id = ? AND actor_id = ?', [$this->scope, $id, $version, $this->actor]);
        $changed = [];
        $groupViews = $this->db->rows('SELECT storefront_id FROM b_pw_calc_snapshot_group WHERE scope_id = ? AND document_id = ? AND version_id = ? AND actor_id = ?', [$this->scope, $id, $version, $this->actor]);
        foreach (array_unique([...array_column($rows, 'storefront_id'), ...array_column($groupViews, 'storefront_id')]) as $view) $changed[$view] = self::signature($before, $view) !== self::signature($after, $view);
        $affected = array_filter($rows, fn($row) => $changed[$row['storefront_id']]);
        $affectedGroups = array_filter($groupViews, fn($row) => $changed[$row['storefront_id']]);
        if (($affected || $affectedGroups) && !$confirmed) throw new DocumentConflict('CALCULATION_SNAPSHOTS_RESET_REQUIRED: Форма несовместима с сохранёнными расчётами. Передайте нужные снимки в подготовку товара до подтверждения очистки.');
        foreach ($affected as $row) $this->db->execute('DELETE FROM b_pw_calc_snapshot WHERE id = ? AND actor_id = ? AND scope_id = ?', [$row['id'], $this->actor, $this->scope]);
        foreach ($changed as $view => $reset) if ($reset) $this->db->execute('DELETE FROM b_pw_calc_snapshot_group WHERE scope_id = ? AND document_id = ? AND version_id = ? AND actor_id = ? AND storefront_id = ?', [$this->scope, $id, $version, $this->actor, $view]);
        // Other actors retain their receipts; compatibility is checked on every read.
    }

    public function command(string $action, string $id, string $version, string $storefront, ?string $snapshotId = null): array
    {
        return $this->locked($id, $version, function (array $source) use ($action, $id, $version, $storefront, $snapshotId): array {
            $params = [$this->scope, $id, $version, $this->actor, $storefront];
            $where = 'scope_id = ? AND document_id = ? AND version_id = ? AND actor_id = ? AND storefront_id = ?';
            if ($action === 'deleteCalculationSnapshot' || $action === 'clearCalculationSnapshots') {
                $this->db->execute('DELETE FROM b_pw_calc_snapshot WHERE ' . $where . ($snapshotId !== null ? ' AND id = ?' : ''), $snapshotId !== null ? [...$params, $snapshotId] : $params);
            }
            if ($action === 'clearIncompatibleCalculationSnapshots') $this->db->execute('DELETE FROM b_pw_calc_snapshot WHERE ' . $where . ' AND form_hash <> ?', [...$params, self::signature($source['bodyJson'], $storefront)]);
            if ($action === 'clearCalculationSnapshots') $this->db->execute('DELETE FROM b_pw_calc_snapshot_group WHERE ' . $where, $params);
            if ($action === 'clearIncompatibleCalculationSnapshots') $this->db->execute('DELETE FROM b_pw_calc_snapshot_group WHERE ' . $where . ' AND NOT EXISTS (SELECT 1 FROM b_pw_calc_snapshot_member m WHERE m.group_id = b_pw_calc_snapshot_group.id)', $params);
            $load = $action === 'loadCalculationSnapshot';
            $columns = 'id, created_at, form_hash, summary_json' . ($load ? ', payload_json, payload_hash' : '');
            $rows = $this->db->rows('SELECT ' . $columns . ' FROM b_pw_calc_snapshot WHERE ' . $where . ($load ? ' AND id = ?' : '') . ' ORDER BY created_at DESC, id DESC', $load ? [...$params, $snapshotId] : $params);
            $signature = self::signature($source['bodyJson'], $storefront); $items = [];
            foreach ($rows as $row) {
                $compatible = hash_equals($signature, $row['form_hash']);
                $item = ['id' => $row['id'], 'createdAt' => $row['created_at'], 'compatible' => $compatible] + json_decode($row['summary_json'], true, 64, JSON_THROW_ON_ERROR);
                if ($load) {
                    if (!$compatible) throw new DocumentConflict('Форма изменилась. Снимок сохранён, но восстановить ввод в несовместимую форму нельзя.');
                    if (!hash_equals($row['payload_hash'], hash('sha256', $row['payload_json']))) throw new \RuntimeException('Snapshot integrity check failed.');
                    return $item + ['payload' => json_decode($row['payload_json'], true, 64, JSON_THROW_ON_ERROR), 'currentSource' => ['revision' => $source['revision'], 'bodyHash' => $source['bodyHash']]];
                }
                $items[] = $item;
            }
            if ($action === 'loadCalculationSnapshot') throw new \RuntimeException('Снимок не найден.', 404);
            return ['items' => $items];
        });
    }

    /** All-storefront board. Mutations serialize with capture/save and reject stale tabs. */
    public function groups(string $id, string $version, array $request): array
    {
        return $this->locked($id, $version, function(array $source) use ($id, $version, $request): array {
            $where = 'scope_id = ? AND document_id = ? AND version_id = ? AND actor_id = ?';
            $params = [$this->scope, $id, $version, $this->actor];
            $read = function() use ($source, $where, $params): array {
                $body = json_decode($source['bodyJson'], true, 64, JSON_THROW_ON_ERROR);
                $views = ['BASE' => $body['name'] ?? 'BASE'];
                foreach ($body['presentations']['views'] ?? [] as $view) $views[$view['id']] = $view['name'] ?? $view['id'];
                $views['BASE'] = $body['name'] ?? 'BASE';
                $rows = $this->db->rows('SELECT id, storefront_id, created_at, form_hash, summary_json FROM b_pw_calc_snapshot WHERE ' . $where . ' ORDER BY created_at DESC, id DESC', $params);
                $groups = $this->db->rows('SELECT id, storefront_id, name, sort, collapsed FROM b_pw_calc_snapshot_group WHERE ' . $where . ' ORDER BY sort, id', $params);
                $members = $this->db->rows('SELECT m.snapshot_id, m.group_id FROM b_pw_calc_snapshot_member m JOIN b_pw_calc_snapshot_group g ON g.id = m.group_id WHERE g.' . str_replace(' AND ', ' AND g.', $where), $params);
                $membership = array_column($members, 'group_id', 'snapshot_id'); $items = []; $hashes = [];
                foreach ($rows as $row) {
                    $view = $row['storefront_id']; $hashes[$view] ??= self::signature($source['bodyJson'], $view);
                    $items[] = ['id' => $row['id'], 'storefrontId' => $view, 'storefrontName' => $views[$view] ?? $view,
                        'groupId' => $membership[$row['id']] ?? null, 'createdAt' => $row['created_at'],
                        'compatible' => isset($views[$view]) && hash_equals($hashes[$view], $row['form_hash'])] + json_decode($row['summary_json'], true, 64, JSON_THROW_ON_ERROR);
                }
                $groups = array_map(fn($g) => ['id' => $g['id'], 'storefrontId' => $g['storefront_id'], 'storefrontName' => $views[$g['storefront_id']] ?? $g['storefront_id'], 'name' => $g['name'], 'collapsed' => (bool)$g['collapsed']], $groups);
                return ['items' => $items, 'groups' => $groups, 'stateHash' => hash('sha256', json_encode([$items, $groups, $source['bodyHash']], JSON_THROW_ON_ERROR))];
            };
            $board = $read(); $operation = $request['operation'] ?? 'list';
            if ($operation === 'list') return $board;
            if (!is_string($request['expectedState'] ?? null) || !hash_equals($board['stateHash'], $request['expectedState'])) throw new DocumentConflict('Список изменился в другой вкладке. Он обновлён; повторите действие.');
            $items = array_column($board['items'], null, 'id'); $groups = array_column($board['groups'], null, 'id');
            $skipped = []; $created = 0;
            if ($operation === 'create') {
                $buckets = [];
                foreach ($items as $item) {
                    if ($item['groupId'] !== null) continue;
                    if (!$item['compatible']) { $skipped[$item['storefrontId']] = $item['storefrontName']; continue; }
                    $buckets[$item['storefrontId']][] = $item;
                }
                if ($buckets) foreach (array_keys($groups) as $sort => $group) $this->db->execute('UPDATE b_pw_calc_snapshot_group SET sort = ? WHERE id = ?', [$sort, $group]);
                foreach ($buckets as $view => $receipts) {
                    $group = 'cg_' . bin2hex(random_bytes(16));
                    $this->db->execute('INSERT INTO b_pw_calc_snapshot_group (id, scope_id, document_id, version_id, actor_id, storefront_id, name, sort, collapsed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)', [$group, ...$params, $view, 'Группа ' . (count($groups) + ++$created), count($groups) + $created]);
                    foreach ($receipts as $item) $this->db->execute('INSERT INTO b_pw_calc_snapshot_member (snapshot_id, group_id) VALUES (?, ?)', [$item['id'], $group]);
                }
            } elseif ($operation === 'clear') {
                $this->db->execute('DELETE FROM b_pw_calc_snapshot_group WHERE ' . $where, $params);
                $this->db->execute('DELETE FROM b_pw_calc_snapshot WHERE ' . $where, $params);
            } elseif ($operation === 'clearIncompatible') {
                $view = $request['storefrontId'] ?? null;
                if (!is_string($view)) throw new \InvalidArgumentException('Expected storefrontId.');
                foreach ($items as $item) if ($item['storefrontId'] === $view && !$item['compatible']) $this->db->execute('DELETE FROM b_pw_calc_snapshot WHERE id = ?', [$item['id']]);
                $this->db->execute('DELETE FROM b_pw_calc_snapshot_group WHERE ' . $where . ' AND storefront_id = ? AND NOT EXISTS (SELECT 1 FROM b_pw_calc_snapshot_member m WHERE m.group_id = b_pw_calc_snapshot_group.id)', [...$params, $view]);
            } elseif ($operation === 'reorder') {
                $order = $request['groupIds'] ?? null;
                if (!is_array($order) || !array_is_list($order) || count($order) !== count($groups) || count(array_filter($order, 'is_string')) !== count($order) || count(array_unique($order)) !== count($order) || array_diff($order, array_keys($groups))) throw new \InvalidArgumentException('Expected each current group exactly once.');
                foreach ($order as $sort => $group) $this->db->execute('UPDATE b_pw_calc_snapshot_group SET sort = ? WHERE id = ?', [$sort, $group]);
            } elseif (in_array($operation, ['rename', 'collapse', 'dissolve'], true)) {
                $group = $request['groupId'] ?? null;
                if (!is_string($group) || !isset($groups[$group])) throw new \RuntimeException('Группа не найдена.', 404);
                if ($operation === 'dissolve') $this->db->execute('DELETE FROM b_pw_calc_snapshot_group WHERE id = ?', [$group]);
                if ($operation === 'collapse') {
                    if (!is_bool($request['collapsed'] ?? null)) throw new \InvalidArgumentException('Expected collapsed boolean.');
                    $this->db->execute('UPDATE b_pw_calc_snapshot_group SET collapsed = ? WHERE id = ?', [(int)$request['collapsed'], $group]);
                }
                if ($operation === 'rename') {
                    $name = $request['name'] ?? null;
                    if (!is_string($name) || trim($name) === '' || preg_match('/[\x00-\x1F\x7F]/u', $name) || preg_match_all('/./us', trim($name)) > 200) throw new \InvalidArgumentException('Название: от 1 до 200 символов без управляющих знаков.');
                    $this->db->execute('UPDATE b_pw_calc_snapshot_group SET name = ? WHERE id = ?', [trim($name), $group]);
                }
            } elseif (in_array($operation, ['assign', 'exclude', 'delete'], true)) {
                $snapshot = $request['snapshotId'] ?? null;
                if (!is_string($snapshot) || !isset($items[$snapshot])) throw new \RuntimeException('Снимок не найден.', 404);
                if ($operation === 'delete') $this->db->execute('DELETE FROM b_pw_calc_snapshot WHERE id = ?', [$snapshot]);
                else {
                    if (!$items[$snapshot]['compatible']) throw new DocumentConflict('Форма снимка несовместима.');
                    $group = $request['groupId'] ?? null;
                    if ($operation === 'assign' && (!is_string($group) || !isset($groups[$group]) || $groups[$group]['storefrontId'] !== $items[$snapshot]['storefrontId'])) throw new DocumentConflict('Можно добавить расчёт только в группу той же витрины.');
                    $this->db->execute('DELETE FROM b_pw_calc_snapshot_member WHERE snapshot_id = ?', [$snapshot]);
                    if ($operation === 'assign') $this->db->execute('INSERT INTO b_pw_calc_snapshot_member (snapshot_id, group_id) VALUES (?, ?)', [$snapshot, $group]);
                }
            } else throw new \InvalidArgumentException('Unknown group operation.');
            return $read() + ['createdGroups' => $created, 'skippedStorefronts' => array_values($skipped)];
        });
    }

    public function capture(string $id, string $version, array $source, array $response, array $request, array $resources): string
    {
        return $this->locked($id, $version, function (array $current) use ($id, $version, $source, $response, $request, $resources): string {
            if ($current['revision'] !== $source['revision'] || $current['bodyHash'] !== $source['bodyHash']) throw new DocumentConflict('Версия изменилась во время расчёта. Снимок не добавлен.');
            $view = $request['storefrontId'] ?? 'BASE';
            $document = json_decode($source['bodyJson'], false, 64, JSON_THROW_ON_ERROR);
            if ($view !== 'BASE' && !in_array($view, array_map(fn($item) => $item->id, $document->presentations->views ?? []), true)) throw new \InvalidArgumentException('Unknown storefront.');
            $payload = json_encode(['response' => $response, 'document' => $document, 'values' => $request['values'] ?? new \stdClass(),
                'activation' => $request['sectionActivation'] ?? new \stdClass(), 'execution' => $request['execution'], 'storefrontId' => $view,
                'resourcesHash' => hash('sha256', json_encode($resources, JSON_THROW_ON_ERROR))], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($payload) > 8000000) throw new \RuntimeException('Снимок превышает 8 МБ. Расчёт выполнен, но не сохранён.');
            $incompatible = $this->db->rows('SELECT id FROM b_pw_calc_snapshot WHERE scope_id = ? AND document_id = ? AND version_id = ? AND actor_id = ? AND storefront_id = ? AND form_hash <> ?', [$this->scope, $id, $version, $this->actor, $view, self::signature($source['bodyJson'], $view)]);
            if ($incompatible) throw new DocumentConflict('Форма изменена другим пользователем. Сначала откройте список и подтвердите очистку несовместимых расчётов.');
            $count = (int)$this->db->rows('SELECT COUNT(*) AS total FROM b_pw_calc_snapshot WHERE scope_id = ? AND document_id = ? AND version_id = ? AND actor_id = ?', [$this->scope, $id, $version, $this->actor])[0]['total'];
            if ($count >= 500) throw new \RuntimeException('В списке 500 расчётов. Удалите ненужные снимки.');
            $result = $response['result'];
            $summary = json_encode(['name' => $result['name'], 'revision' => $source['revision'], 'purchasingPrice' => $result['purchasingPrice'], 'basePrice' => $result['basePrice'], 'currency' => $result['currency']], JSON_THROW_ON_ERROR);
            $key = 'cs_' . bin2hex(random_bytes(16));
            $this->db->execute('INSERT INTO b_pw_calc_snapshot (id, scope_id, document_id, version_id, actor_id, storefront_id, form_hash, payload_json, payload_hash, created_at, summary_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$key, $this->scope, $id, $version, $this->actor, $view, self::signature($source['bodyJson'], $view), $payload, hash('sha256', $payload), gmdate('Y-m-d\TH:i:s\Z'), $summary]);
            return $key;
        });
    }

    private function locked(string $id, string $version, callable $operation): mixed
    {
        $this->db->begin();
        try {
            $row = $this->db->rows('SELECT id FROM b_pw_calc_document WHERE id = ? AND scope_id = ?' . ($this->db->dialect() === 'mysql' ? ' FOR UPDATE' : ''), [$id, $this->scope]);
            if (!$row) throw new \RuntimeException('Document not found.', 404);
            $source = $this->documents->versions()->loadInTransaction($id, $version);
            $result = $operation($source); $this->db->commit(); return $result;
        } catch (\Throwable $e) { $this->db->rollback(); throw $e; }
    }
}
