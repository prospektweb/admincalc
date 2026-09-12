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
        $affected = array_filter($rows, fn($row) => self::signature($before, $row['storefront_id']) !== self::signature($after, $row['storefront_id']));
        if ($affected && !$confirmed) throw new DocumentConflict('CALCULATION_SNAPSHOTS_RESET_REQUIRED: Форма несовместима с сохранёнными расчётами. Ваш затронутый список будет очищен. Передача в подготовку товара пока недоступна.');
        foreach ($affected as $row) $this->db->execute('DELETE FROM b_pw_calc_snapshot WHERE id = ? AND actor_id = ? AND scope_id = ?', [$row['id'], $this->actor, $this->scope]);
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
            $rows = $this->db->rows('SELECT * FROM b_pw_calc_snapshot WHERE ' . $where . ' ORDER BY created_at DESC, id DESC', $params);
            $signature = self::signature($source['bodyJson'], $storefront); $items = [];
            foreach ($rows as $row) {
                if (!hash_equals($row['payload_hash'], hash('sha256', $row['payload_json']))) throw new \RuntimeException('Snapshot integrity check failed.');
                $payload = json_decode($row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
                $compatible = hash_equals($signature, $row['form_hash']);
                $item = ['id' => $row['id'], 'name' => $payload['response']['result']['name'], 'createdAt' => $row['created_at'], 'compatible' => $compatible,
                    'revision' => $payload['response']['source']['revision'], 'purchasingPrice' => $payload['response']['result']['purchasingPrice'],
                    'basePrice' => $payload['response']['result']['basePrice'], 'currency' => $payload['response']['result']['currency']];
                if ($action === 'loadCalculationSnapshot' && $row['id'] === $snapshotId) {
                    if (!$compatible) throw new DocumentConflict('Форма изменилась. Снимок сохранён, но восстановить ввод в несовместимую форму нельзя.');
                    return $item + ['payload' => $payload, 'currentSource' => ['revision' => $source['revision'], 'bodyHash' => $source['bodyHash']]];
                }
                $items[] = $item;
            }
            if ($action === 'loadCalculationSnapshot') throw new \RuntimeException('Снимок не найден.', 404);
            return ['items' => $items];
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
            $key = 'cs_' . bin2hex(random_bytes(16));
            $this->db->execute('INSERT INTO b_pw_calc_snapshot (id, scope_id, document_id, version_id, actor_id, storefront_id, form_hash, payload_json, payload_hash, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$key, $this->scope, $id, $version, $this->actor, $view, self::signature($source['bodyJson'], $view), $payload, hash('sha256', $payload), gmdate('Y-m-d\TH:i:s\Z')]);
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
