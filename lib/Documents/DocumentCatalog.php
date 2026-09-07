<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/SqlConnection.php';

/** Scope-local registry tree. All placement writers lock its CAS row first.
 * Snapshot/count queries load metadata only, not documents or resource catalogs. */
final class DocumentCatalog
{
    public function __construct(private SqlConnection $db, private string $scope) {}

    public function snapshot(): array
    {
        $revision = (int)($this->db->rows('SELECT revision FROM b_pw_calc_catalog WHERE scope_id = ?', [$this->scope])[0]['revision'] ?? 0);
        $sections = $this->sections(); $byId = [];
        foreach ($sections as $section) { $byId[$section['id']] = $section; }
        $counts = $this->db->rows('SELECT section_id, COUNT(*) AS total FROM b_pw_calc_document WHERE scope_id = ? GROUP BY section_id', [$this->scope]);
        $total = 0; $unsectioned = 0;
        foreach ($counts as $row) {
            $count = (int)$row['total']; $total += $count;
            if ($row['section_id'] === null) { $unsectioned += $count; continue; }
            if (!isset($byId[$row['section_id']])) { throw new \RuntimeException('Calculator section integrity failed.'); }
            $byId[$row['section_id']]['directCalculatorCount'] = $count;
            foreach ($this->ancestors($row['section_id'], $byId) as $id) { $byId[$id]['calculatorCount'] += $count; }
        }
        foreach ($byId as $section) {
            // Validate empty branches as well as populated ones.
            $this->ancestors($section['id'], $byId);
            if ($section['parentId'] !== '') { $byId[$section['parentId']]['childSectionCount']++; }
        }
        return ['contract' => 'prospektweb.calculator/catalog-v1', 'revision' => $revision,
            'sections' => array_values($byId), 'calculatorCount' => $total, 'unsectionedCount' => $unsectioned];
    }

    /** Called inside the caller's transaction, before any document lock. */
    public function lock(int $expectedRevision): void
    {
        if (!$this->db->inTransaction()) { throw new \LogicException('Catalog writes require a transaction.'); }
        if ($expectedRevision < 0 || $expectedRevision >= 2147483646) { throw new \InvalidArgumentException('Invalid catalog revision.'); }
        $insert = 'INSERT INTO b_pw_calc_catalog (scope_id, revision) VALUES (?, 0)';
        $this->db->execute($insert . ($this->db->dialect() === 'mysql' ? ' ON DUPLICATE KEY UPDATE scope_id = scope_id' : ' ON CONFLICT(scope_id) DO NOTHING'), [$this->scope]);
        $rows = $this->db->rows('SELECT revision FROM b_pw_calc_catalog WHERE scope_id = ?' . ($this->db->dialect() === 'mysql' ? ' FOR UPDATE' : ''), [$this->scope]);
        if ((int)$rows[0]['revision'] !== $expectedRevision) { throw new DocumentConflict('Структура разделов изменилась. Обновите список и повторите действие.'); }
    }

    public function touch(): void
    {
        $this->db->execute('UPDATE b_pw_calc_catalog SET revision = revision + 1 WHERE scope_id = ?', [$this->scope]);
    }

    public function requireSection(?string $id): ?array
    {
        if ($id === null) { return null; }
        self::identity($id);
        $row = $this->db->rows('SELECT id, parent_id, name FROM b_pw_calc_section WHERE id = ? AND scope_id = ?', [$id, $this->scope])[0] ?? null;
        if ($row === null) { throw new \RuntimeException('Раздел не найден. Обновите список.', 404); }
        return $row;
    }

    public function create(string $name, ?string $parentId): string
    {
        $name = self::name($name); $this->requireSection($parentId);
        $sections = $this->sections();
        if (count($sections) >= 10000) { throw new \InvalidArgumentException('Достигнут лимит разделов.'); }
        $byId = array_column($sections, null, 'id');
        if ($parentId !== null && count($this->ancestors($parentId, $byId)) >= 32) { throw new \InvalidArgumentException('Максимальная глубина разделов — 32.'); }
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes); $id = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
        $this->db->execute('INSERT INTO b_pw_calc_section (id, scope_id, parent_id, name, sort) VALUES (?, ?, ?, ?, 500)', [$id, $this->scope, $parentId, $name]);
        $this->touch(); return $id;
    }

    public function rename(string $id, string $name): void
    {
        $row = $this->requireSection($id); $name = self::name($name);
        if ($row['name'] === $name) { return; }
        $this->db->execute('UPDATE b_pw_calc_section SET name = ? WHERE id = ? AND scope_id = ?', [$name, $id, $this->scope]); $this->touch();
    }

    /** Preserve legacy semantics: children/calculators move up, never disappear. */
    public function remove(string $id): void
    {
        $row = $this->requireSection($id);
        $this->db->execute('UPDATE b_pw_calc_document SET section_id = ? WHERE scope_id = ? AND section_id = ?', [$row['parent_id'], $this->scope, $id]);
        $this->db->execute('UPDATE b_pw_calc_section SET parent_id = ? WHERE scope_id = ? AND parent_id = ?', [$row['parent_id'], $this->scope, $id]);
        $this->db->execute('DELETE FROM b_pw_calc_section WHERE id = ? AND scope_id = ?', [$id, $this->scope]); $this->touch();
    }

    public function move(string $id, ?string $sectionId): void
    {
        self::identity($id); $this->requireSection($sectionId);
        $row = $this->db->rows('SELECT section_id FROM b_pw_calc_document WHERE id = ? AND scope_id = ?' . ($this->db->dialect() === 'mysql' ? ' FOR UPDATE' : ''), [$id, $this->scope])[0] ?? null;
        if ($row === null) { throw new \RuntimeException('Document not found.', 404); }
        if ($row['section_id'] === $sectionId) { return; }
        $this->db->execute('UPDATE b_pw_calc_document SET section_id = ? WHERE id = ? AND scope_id = ?', [$sectionId, $id, $this->scope]); $this->touch();
    }

    /** The selected branch includes descendants, as in the old registry. */
    public function descendants(string $id): array
    {
        $this->requireSection($id); $sections = $this->sections(); $ids = [$id => true];
        do {
            $before = count($ids);
            foreach ($sections as $section) { if (isset($ids[$section['parentId']])) { $ids[$section['id']] = true; } }
        } while (count($ids) !== $before);
        return array_keys($ids);
    }

    private function sections(): array
    {
        return array_map(static fn(array $row): array => ['id' => $row['id'], 'parentId' => $row['parent_id'] ?? '', 'name' => $row['name'],
            'sort' => (int)$row['sort'], 'active' => true, 'directCalculatorCount' => 0, 'calculatorCount' => 0, 'childSectionCount' => 0],
            $this->db->rows('SELECT id, parent_id, name, sort FROM b_pw_calc_section WHERE scope_id = ? ORDER BY sort, name, id', [$this->scope]));
    }
    private function ancestors(string $id, array $sections): array
    {
        $ids = [];
        while ($id !== '') {
            if (!isset($sections[$id]) || isset($ids[$id]) || count($ids) >= 32) { throw new \RuntimeException('Section tree integrity failed.'); }
            $ids[$id] = true; $id = $sections[$id]['parentId'];
        }
        return array_keys($ids);
    }
    private static function name(string $value): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > 200 || preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new \InvalidArgumentException('Введите название раздела длиной до 200 символов.'); }
        return $value;
    }
    private static function identity(string $id): void
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $id)) { throw new \InvalidArgumentException('Invalid identity.'); }
    }
}
