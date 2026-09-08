<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/DocumentRepository.php';

/** Module-owned reusable records with immutable revisions. The caller owns body validation.
 * Lists read metadata only; mutation CAS is independent of calculator/version revisions. */
final class DocumentLibrary
{
    public function __construct(private SqlConnection $db, private string $scope, private string $actor, private string $kind)
    {
        foreach ([$scope, $actor, $kind] as $identity) { self::identity($identity); }
    }

    public function listing(): array
    {
        return $this->transaction(fn() => $this->snapshot(), true);
    }

    public function load(string $id, ?int $revision = null): array
    {
        self::identity($id); if ($revision !== null) { self::revision($revision, false); }
        $rows = $this->db->rows('SELECT e.id, e.name AS current_name, e.head_revision, e.deleted AS current_deleted, r.revision, r.name, r.deleted, r.body_json, r.body_hash, r.actor_id, r.created_at
            FROM b_pw_calc_library_record e JOIN b_pw_calc_library_revision r ON r.record_id = e.id AND r.revision = ' . ($revision === null ? 'e.head_revision' : '?') . '
            WHERE e.id = ? AND e.scope_id = ? AND e.kind = ?', $revision === null ? [$id, $this->scope, $this->kind] : [$revision, $id, $this->scope, $this->kind]);
        $row = $rows[0] ?? null;
        if ($row === null || ($revision === null && (int)$row['current_deleted'] === 1)) { throw new \RuntimeException('Шаблон не найден.', 404); }
        if ((int)$row['revision'] === (int)$row['head_revision'] && ($row['current_name'] !== $row['name'] || (int)$row['current_deleted'] !== (int)$row['deleted'])) { throw new \RuntimeException('Нарушена целостность метаданных шаблона.'); }
        if (!hash_equals((string)$row['body_hash'], hash('sha256', (string)$row['body_json']))) { throw new \RuntimeException('Нарушена целостность шаблона.'); }
        return ['contract' => 'prospektweb.calculator/library-record-v1', 'kind' => $this->kind, 'id' => $id,
            'revision' => (int)$row['revision'], 'name' => $row['name'], 'deleted' => (bool)$row['deleted'],
            'bodyJson' => $row['body_json'], 'bodyHash' => $row['body_hash'], 'actor' => $row['actor_id'], 'createdAt' => $row['created_at']];
    }

    /** Body is canonical and already validated outside the SQL transaction. */
    public function create(int $expectedCatalogRevision, string $name, string $json): array
    {
        $name = self::name($name); self::body($json);
        return $this->transaction(function () use ($expectedCatalogRevision, $name, $json): array {
            $this->lock($expectedCatalogRevision);
            $items = $this->snapshot()['items'];
            if (count($items) >= 1000) { throw new \InvalidArgumentException('Достигнут лимит шаблонов.'); }
            $this->uniqueName($name, null, $items);
            $id = 'tpl_' . bin2hex(random_bytes(16)); $now = self::now();
            $this->db->execute('INSERT INTO b_pw_calc_library_record (id, scope_id, kind, name, head_revision, deleted, created_at, updated_at, created_by, updated_by) VALUES (?, ?, ?, ?, 1, 0, ?, ?, ?, ?)',
                [$id, $this->scope, $this->kind, $name, $now, $now, $this->actor, $this->actor]);
            $this->append($id, 1, $name, false, $json, $now); $this->touch();
            return ['catalog' => $this->snapshot(), 'record' => $this->load($id)];
        });
    }

    public function change(string $action, string $id, int $expectedCatalogRevision, int $expectedRevision, ?string $json = null, ?string $name = null): array
    {
        if (!in_array($action, ['save', 'rename', 'delete'], true)) { throw new \InvalidArgumentException('Unknown library mutation.'); }
        self::identity($id); self::revision($expectedRevision, false);
        if ($action === 'save') { if ($json === null) { throw new \InvalidArgumentException('Missing template body.'); } self::body($json); }
        if ($action === 'rename') { $name = self::name($name ?? ''); }
        return $this->transaction(function () use ($action, $id, $expectedCatalogRevision, $expectedRevision, $json, $name): array {
            $this->lock($expectedCatalogRevision);
            $before = $this->load($id);
            if ($before['revision'] !== $expectedRevision) { throw new DocumentConflict('Шаблон изменился. Обновите список.'); }
            $nextName = $action === 'rename' ? $name : $before['name'];
            $nextJson = $action === 'save' ? $json : $before['bodyJson'];
            $deleted = $action === 'delete';
            $this->uniqueName($nextName, $id, $this->snapshot()['items']);
            if (!$deleted && $nextName === $before['name'] && $nextJson === $before['bodyJson']) { return ['catalog' => $this->snapshot(), 'record' => $before]; }
            if ($expectedRevision >= 2147483646) { throw new \RuntimeException('Достигнут лимит ревизий шаблона.'); }
            $nextRevision = $expectedRevision + 1; $now = self::now();
            $this->append($id, $nextRevision, $nextName, $deleted, $nextJson, $now);
            $this->db->execute('UPDATE b_pw_calc_library_record SET name = ?, head_revision = ?, deleted = ?, updated_at = ?, updated_by = ? WHERE id = ? AND scope_id = ? AND kind = ?',
                [$nextName, $nextRevision, (int)$deleted, $now, $this->actor, $id, $this->scope, $this->kind]);
            $this->touch();
            return ['catalog' => $this->snapshot(), 'record' => $this->load($id, $nextRevision)];
        });
    }

    private function snapshot(): array
    {
        $revision = (int)($this->db->rows('SELECT revision FROM b_pw_calc_library_catalog WHERE scope_id = ? AND kind = ?', [$this->scope, $this->kind])[0]['revision'] ?? 0);
        $rows = $this->db->rows('SELECT e.id, e.name, e.head_revision, e.updated_at, e.updated_by, r.name AS revision_name, r.deleted AS revision_deleted, r.body_hash
            FROM b_pw_calc_library_record e LEFT JOIN b_pw_calc_library_revision r ON r.record_id = e.id AND r.revision = e.head_revision
            WHERE e.scope_id = ? AND e.kind = ? AND e.deleted = 0 ORDER BY e.name, e.id LIMIT 1001', [$this->scope, $this->kind]);
        if (count($rows) > 1000) { throw new \RuntimeException('Library record limit exceeded.'); }
        foreach ($rows as $row) {
            if ($row['body_hash'] === null || $row['revision_name'] !== $row['name'] || (int)$row['revision_deleted'] !== 0) { throw new \RuntimeException('Нарушена целостность списка шаблонов.'); }
        }
        return ['contract' => 'prospektweb.calculator/library-v1', 'kind' => $this->kind, 'revision' => $revision,
            'items' => array_map(static fn(array $row) => ['id' => $row['id'], 'name' => $row['name'], 'revision' => (int)$row['head_revision'],
                'bodyHash' => $row['body_hash'], 'updatedAt' => $row['updated_at'], 'updatedBy' => $row['updated_by']], $rows)];
    }
    private function lock(int $expected): void
    {
        self::revision($expected, true);
        $insert = 'INSERT INTO b_pw_calc_library_catalog (scope_id, kind, revision) VALUES (?, ?, 0)';
        $this->db->execute($insert . ($this->db->dialect() === 'mysql' ? ' ON DUPLICATE KEY UPDATE revision = revision' : ' ON CONFLICT(scope_id, kind) DO NOTHING'), [$this->scope, $this->kind]);
        $row = $this->db->rows('SELECT revision FROM b_pw_calc_library_catalog WHERE scope_id = ? AND kind = ?' . ($this->db->dialect() === 'mysql' ? ' FOR UPDATE' : ''), [$this->scope, $this->kind])[0];
        if ((int)$row['revision'] !== $expected) { throw new DocumentConflict('Список шаблонов изменился. Обновите его и повторите действие.'); }
        if ($expected >= 2147483646) { throw new \RuntimeException('Достигнут лимит ревизий списка шаблонов.'); }
    }
    private function touch(): void { $this->db->execute('UPDATE b_pw_calc_library_catalog SET revision = revision + 1 WHERE scope_id = ? AND kind = ?', [$this->scope, $this->kind]); }
    private function append(string $id, int $revision, string $name, bool $deleted, string $json, string $now): void
    {
        $this->db->execute('INSERT INTO b_pw_calc_library_revision (record_id, revision, name, deleted, body_json, body_hash, actor_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $revision, $name, (int)$deleted, $json, hash('sha256', $json), $this->actor, $now]);
    }
    private function uniqueName(string $name, ?string $id, array $items): void
    {
        foreach ($items as $item) {
            if ($item['id'] !== $id && mb_strtolower($name, 'UTF-8') === mb_strtolower($item['name'], 'UTF-8')) { throw new DocumentConflict('Шаблон с таким названием уже существует.'); }
        }
    }
    private function transaction(callable $operation, bool $read = false): array
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Library must own its transaction.'); }
        $this->db->begin($read);
        try { $result = $operation(); $this->db->commit(); return $result; }
        catch (\Throwable $error) { if ($this->db->inTransaction()) { $this->db->rollback(); } throw $error; }
    }
    private static function body(string $json): void
    {
        if (strlen($json) > 1000000 || !(json_decode($json, false, 32, JSON_THROW_ON_ERROR) instanceof \stdClass)) { throw new \InvalidArgumentException('Invalid library record body.'); }
    }
    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 120 || preg_match('/[\x00-\x1f\x7f]/', $name)) { throw new \InvalidArgumentException('Введите название шаблона длиной до 120 символов.'); }
        return $name;
    }
    private static function identity(string $id): void { if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $id) !== 1) { throw new \InvalidArgumentException('Invalid library identity.'); } }
    private static function revision(int $revision, bool $zero): void { if ($revision < ($zero ? 0 : 1) || $revision > 2147483646) { throw new \InvalidArgumentException('Invalid library revision.'); } }
    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
}
