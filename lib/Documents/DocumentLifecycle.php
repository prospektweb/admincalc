<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** Authoring deletion and public availability share the publication row lock. */
final class DocumentLifecycle
{
    public function __construct(private SqlConnection $db, private string $scope, private string $actor) {}

    public function preview(string $id): array
    {
        return $this->transaction(fn(): array => $this->snapshot($id), true);
    }

    public function setEnabled(string $id, string $expected, bool $enabled): array
    {
        return $this->transaction(function () use ($id, $expected, $enabled): array {
            $this->metadata($id, true); $before = $this->expect($id, $expected);
            $this->db->execute('UPDATE b_pw_calc_document SET enabled = ?, versions_revision = versions_revision + 1, updated_at = ? WHERE id = ? AND scope_id = ?', [(int)$enabled, gmdate('c'), $id, $this->scope]);
            $after = $this->snapshot($id, true);
            if ($after['enabled'] !== $enabled || $after['publicationId'] !== $before['publicationId']) throw new \RuntimeException('Изменение доступности не подтверждено.');
            return $after;
        });
    }

    public function delete(string $id, string $expected, string $name): array
    {
        return $this->transaction(function () use ($id, $expected, $name): array {
            // Same order as placement writers: catalog, then document.
            $catalog = new DocumentCatalog($this->db, $this->scope);
            $catalog->lock($catalog->snapshot()['revision']);
            $this->metadata($id, true); $before = $this->expect($id, $expected);
            if (!hash_equals($before['name'], $name)) throw new \InvalidArgumentException('Введите точное название калькулятора.');
            $this->audit($id, null, ['action' => 'deleteCalculator', 'name' => $name, 'counts' => $before['counts'],
                'publications' => $this->read('SELECT * FROM b_pw_calc_site_publication WHERE document_id = ? ORDER BY id', [$id], true),
                'corePublications' => $this->read('SELECT * FROM b_pw_calc_publication WHERE document_id = ? ORDER BY id', [$id], true),
                'catalogReceipts' => $this->read('SELECT * FROM b_pw_calc_catalog_write WHERE document_id = ? ORDER BY id', [$id], true)]);
            // No iblock, product, offer, resource, basket or order writes.
            foreach (['snapshot', 'product_binding', 'site_active', 'site_identity', 'version', 'catalog_write', 'site_publication', 'publication', 'revision'] as $table) {
                $this->db->execute('DELETE FROM b_pw_calc_' . $table . ' WHERE document_id = ?', [$id]);
            }
            $this->db->execute('DELETE FROM b_pw_calc_document WHERE id = ? AND scope_id = ?', [$id, $this->scope]);
            if ($this->db->rows('SELECT id FROM b_pw_calc_document WHERE id = ?', [$id])) throw new \RuntimeException('Удаление не подтверждено.');
            $catalog->touch();
            return ['id' => $id, 'deleted' => true, 'counts' => $before['counts']];
        });
    }

    /** Caller holds the document lock and version-registry CAS. */
    public function deleteVersionLocked(string $id, array $version): void
    {
        if (!$this->db->inTransaction()) throw new \LogicException('Deletion requires transaction.');
        $this->metadata($id, true);
        $versionId = $version['id'];
        $active = $this->db->rows('SELECT version_id FROM b_pw_calc_site_active WHERE document_id = ?', [$id])[0]['version_id'] ?? null;
        $this->audit($id, $versionId, ['action' => 'deleteVersion', 'name' => $version['name'], 'versionNo' => (int)$version['version_no'], 'unpublished' => $active === $versionId]);
        if ($active === $versionId) {
            $this->db->execute('DELETE FROM b_pw_calc_product_binding WHERE scope_id = ? AND document_id = ?', [$this->scope, $id]);
            $this->db->execute('DELETE FROM b_pw_calc_site_active WHERE document_id = ?', [$id]);
        }
        $this->db->execute('UPDATE b_pw_calc_version SET based_on_version_id = NULL WHERE document_id = ? AND based_on_version_id = ?', [$id, $versionId]);
        $this->db->execute('DELETE FROM b_pw_calc_snapshot WHERE document_id = ? AND version_id = ?', [$id, $versionId]);
        $this->db->execute('DELETE FROM b_pw_calc_version WHERE document_id = ? AND id = ?', [$id, $versionId]);
        if ($this->db->rows('SELECT id FROM b_pw_calc_version WHERE document_id = ? AND id = ?', [$id, $versionId])) throw new \RuntimeException('Удаление версии не подтверждено.');
        // Revisions belong to the document and may be shared by cloned versions,
        // the document head or published calculations. Never cascade through them.
    }

    private function snapshot(string $id, bool $locked = false): array
    {
        $meta = $this->metadata($id, $locked);
        $versions = $this->read('SELECT id, version_no, name, head_revision, hidden, deleted, last_site_publication FROM b_pw_calc_version WHERE document_id = ? ORDER BY id', [$id], $locked);
        $revisions = $this->read('SELECT revision, body_hash, connection_hash FROM b_pw_calc_revision WHERE document_id = ? ORDER BY revision', [$id], $locked);
        $site = $this->read('SELECT id, snapshot_hash FROM b_pw_calc_site_publication WHERE document_id = ? ORDER BY id', [$id], $locked);
        $core = $this->read('SELECT id, snapshot_hash FROM b_pw_calc_publication WHERE document_id = ? ORDER BY id', [$id], $locked);
        $bindings = $this->read('SELECT provider, catalog_key, product_key, publication_id, presentation_id FROM b_pw_calc_product_binding WHERE document_id = ? ORDER BY provider, catalog_key, product_key', [$id], $locked);
        $receipts = $this->read('SELECT id, receipt_hash FROM b_pw_calc_catalog_write WHERE document_id = ? ORDER BY id', [$id], $locked);
        $active = $this->read('SELECT publication_id, version_id FROM b_pw_calc_site_active WHERE document_id = ?', [$id], $locked);
        $fingerprint = hash('sha256', json_encode([$meta, $versions, $revisions, $site, $core, $bindings, $receipts, $active], JSON_THROW_ON_ERROR));
        return ['contract' => 'prospektweb.calculator/lifecycle-v1', 'id' => $id, 'name' => $meta['name'],
            'enabled' => (bool)$meta['enabled'], 'publicationId' => $active[0]['publication_id'] ?? null, 'revision' => $fingerprint,
            'counts' => ['versions' => count($versions), 'revisions' => count($revisions), 'publications' => count($site) + count($core), 'products' => count($bindings), 'receipts' => count($receipts)]];
    }

    private function read(string $sql, array $parameters, bool $locked): array
    {
        return $this->db->rows($sql . ($locked && $this->db->dialect() === 'mysql' ? ' FOR UPDATE' : ''), $parameters);
    }

    private function expect(string $id, string $expected): array
    {
        $snapshot = $this->snapshot($id, true);
        if (!preg_match('/^[a-f0-9]{64}$/D', $expected) || !hash_equals($snapshot['revision'], $expected)) throw new DocumentConflict('Калькулятор изменился. Закройте подтверждение и откройте его заново.');
        return $snapshot;
    }

    private function metadata(string $id, bool $lock = false): array
    {
        $row = $this->db->rows('SELECT * FROM b_pw_calc_document WHERE id = ? AND scope_id = ?' . ($lock && $this->db->dialect() === 'mysql' ? ' FOR UPDATE' : ''), [$id, $this->scope])[0] ?? null;
        if ($row === null) throw new \RuntimeException('Калькулятор не найден.', 404);
        return $row;
    }

    private function audit(string $id, ?string $versionId, array $body): void
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->db->execute('INSERT INTO b_pw_calc_deletion_audit (id, scope_id, document_id, version_id, body_json, body_hash, actor_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            ['del_' . bin2hex(random_bytes(16)), $this->scope, $id, $versionId, $json, hash('sha256', $json), $this->actor, gmdate('c')]);
    }

    private function transaction(callable $operation, bool $read = false): array
    {
        $this->db->begin($read);
        try { $result = $operation(); $this->db->commit(); return $result; }
        catch (\Throwable $e) { $this->db->rollback(); throw $e; }
    }
}
