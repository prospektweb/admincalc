<?php
declare(strict_types=1);

namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/SqlConnection.php';

final class DocumentConflict extends \RuntimeException
{
    public function __construct(string $message = 'Document revision changed.') { parent::__construct($message, 409); }
}

/**
 * Storage of validated documents. Domain validation belongs to the caller/core,
 * never to SQL. No HTTP endpoint is exposed until that boundary is connected.
 * Scope and actor must be obtained from trusted authentication, not request JSON.
 */
final class DocumentRepository
{
    private SqlConnection $db;
    private string $scope;
    private string $actor;

    public function __construct(SqlConnection $db, string $scope, string $actor)
    {
        self::identity($scope); self::identity($actor);
        $this->db = $db; $this->scope = $scope; $this->actor = $actor;
    }

    /** The canonical JSON bytes are supplied by the shared core and hashed unchanged. */
    public function create(string $json): array
    {
        $document = self::body($json);
        return $this->transaction(function () use ($json, $document): array {
            if ($this->metadata($document['id'], true) !== null) { throw new DocumentConflict('Document already exists.'); }
            $now = self::now();
            $this->db->execute('INSERT INTO b_pw_calc_document (id, scope_id, name, current_revision, active_publication, archived, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$document['id'], $this->scope, $document['name'], 1, null, 0, $now, $now]);
            $this->appendRevision($document['id'], 1, $json, $now);
            return $this->load($document['id']);
        });
    }

    public function save(string $id, int $expectedRevision, string $json): array
    {
        self::identity($id); self::revision($expectedRevision);
        $document = self::body($json);
        if ($document['id'] !== $id) { throw new \InvalidArgumentException('Document identity is immutable.'); }
        return $this->transaction(function () use ($id, $expectedRevision, $json, $document): array {
            $meta = $this->requireMetadata($id, true);
            $this->expectRevision($meta, $expectedRevision);
            $current = $this->load($id);
            if (hash_equals($current['bodyHash'], hash('sha256', $json))) { return $current; }
            $next = $expectedRevision + 1; $now = self::now();
            $this->appendRevision($id, $next, $json, $now);
            $this->db->execute('UPDATE b_pw_calc_document SET name = ?, current_revision = ?, updated_at = ? WHERE id = ? AND scope_id = ?',
                [$document['name'], $next, $now, $id, $this->scope]);
            return $this->load($id);
        });
    }

    public function load(string $id, ?int $revision = null): array
    {
        self::identity($id);
        if ($revision !== null) { self::revision($revision); }
        // Single statement: readers never combine metadata and body from different revisions.
        $rows = $this->db->rows('SELECT d.id, d.name, d.current_revision, d.active_publication, d.archived, r.revision, r.body_json, r.body_hash, r.actor_id, r.created_at FROM b_pw_calc_document d JOIN b_pw_calc_revision r ON r.document_id = d.id AND r.revision = ' . ($revision === null ? 'd.current_revision' : '?') . ' WHERE d.id = ? AND d.scope_id = ?',
            $revision === null ? [$id, $this->scope] : [$revision, $id, $this->scope]);
        if (!$rows) { throw new \RuntimeException('Document not found.', 404); }
        $row = $rows[0];
        if (!hash_equals($row['body_hash'], hash('sha256', $row['body_json']))) {
            throw new \RuntimeException('Document integrity check failed.');
        }
        return ['id' => $row['id'], 'revision' => (int)$row['revision'], 'currentRevision' => (int)$row['current_revision'],
            'bodyJson' => $row['body_json'], 'bodyHash' => $row['body_hash'], 'activePublication' => $row['active_publication'],
            'archived' => (bool)$row['archived'], 'actorId' => $row['actor_id'], 'createdAt' => $row['created_at']];
    }

    /** List reads indexed metadata only. No graph JSON is loaded. */
    public function listing(int $limit = 50, int $offset = 0, bool $archived = false): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 100000) { throw new \InvalidArgumentException('Invalid pagination.'); }
        return $this->db->rows('SELECT id, name, current_revision, active_publication, archived, created_at, updated_at FROM b_pw_calc_document WHERE scope_id = ? AND archived = ? ORDER BY updated_at DESC, id LIMIT ' . $limit . ' OFFSET ' . $offset,
            [$this->scope, (int)$archived]);
    }

    /**
     * A compiled snapshot must be supplied by the domain compiler. The repository
     * cannot turn an editable document into an executable publication by itself.
     */
    public function publish(string $id, int $expectedRevision, ?string $expectedPublication, string $engineVersion, string $snapshotJson): array
    {
        self::identity($id); self::identity($engineVersion); self::revision($expectedRevision);
        if ($expectedPublication !== null) { self::identity($expectedPublication); }
        $snapshot = self::json($snapshotJson, 33554432);
        if (($snapshot['contract'] ?? '') !== 'prospektweb.calculator/publication-v1'
            || ($snapshot['calculatorId'] ?? '') !== $id
            || ($snapshot['engineVersion'] ?? '') !== $engineVersion
            || !is_string($snapshot['documentHash'] ?? null)
            || !is_array($snapshot['resources'] ?? null) || !isset($snapshot['plan'])) {
            throw new \InvalidArgumentException('Invalid compiled publication envelope.');
        }
        return $this->transaction(function () use ($id, $expectedRevision, $expectedPublication, $engineVersion, $snapshotJson, $snapshot): array {
            $meta = $this->requireMetadata($id, true);
            $this->expectRevision($meta, $expectedRevision);
            if ($meta['active_publication'] !== $expectedPublication) { throw new DocumentConflict('Active publication changed.'); }
            $document = $this->load($id, $expectedRevision);
            if (!hash_equals($document['bodyHash'], $snapshot['documentHash'])) { throw new DocumentConflict('Snapshot was compiled from another document.'); }
            $hash = hash('sha256', $snapshotJson); $publicationId = 'p_' . $hash;
            $existing = $this->db->rows('SELECT id FROM b_pw_calc_publication WHERE id = ? AND document_id = ?', [$publicationId, $id]);
            $now = self::now();
            if (!$existing) {
                $this->db->execute('INSERT INTO b_pw_calc_publication (id, document_id, source_revision, engine_version, snapshot_json, snapshot_hash, actor_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$publicationId, $id, $expectedRevision, $engineVersion, $snapshotJson, $hash, $this->actor, $now]);
            }
            $this->db->execute('UPDATE b_pw_calc_document SET active_publication = ?, updated_at = ? WHERE id = ? AND scope_id = ?', [$publicationId, $now, $id, $this->scope]);
            return $this->publication($id, $publicationId);
        });
    }

    public function publication(string $id, ?string $publicationId = null): array
    {
        self::identity($id);
        if ($publicationId !== null) { self::identity($publicationId); }
        $rows = $this->db->rows('SELECT p.* FROM b_pw_calc_document d JOIN b_pw_calc_publication p ON p.document_id = d.id AND p.id = ' . ($publicationId === null ? 'd.active_publication' : '?') . ' WHERE d.id = ? AND d.scope_id = ?',
            $publicationId === null ? [$id, $this->scope] : [$publicationId, $id, $this->scope]);
        if (!$rows) { throw new \RuntimeException('Publication not found.', 404); }
        $row = $rows[0];
        if (!hash_equals($row['snapshot_hash'], hash('sha256', $row['snapshot_json']))) { throw new \RuntimeException('Publication integrity check failed.'); }
        return ['id' => $row['id'], 'documentId' => $id, 'sourceRevision' => (int)$row['source_revision'],
            'engineVersion' => $row['engine_version'], 'snapshotJson' => $row['snapshot_json'], 'snapshotHash' => $row['snapshot_hash']];
    }

    private function metadata(string $id, bool $lock = false): ?array
    {
        $rows = $this->db->rows('SELECT * FROM b_pw_calc_document WHERE id = ? AND scope_id = ?' . ($lock && $this->db->dialect() === 'mysql' ? ' FOR UPDATE' : ''), [$id, $this->scope]);
        return $rows[0] ?? null;
    }
    private function requireMetadata(string $id, bool $lock): array
    {
        $meta = $this->metadata($id, $lock);
        if ($meta === null) { throw new \RuntimeException('Document not found.', 404); }
        return $meta;
    }
    private function expectRevision(array $meta, int $revision): void
    {
        if ((int)$meta['current_revision'] !== $revision) { throw new DocumentConflict(); }
        if ((int)$meta['archived'] !== 0) { throw new DocumentConflict('Document is archived.'); }
    }
    private function appendRevision(string $id, int $revision, string $json, string $now): void
    {
        $this->db->execute('INSERT INTO b_pw_calc_revision (document_id, revision, body_json, body_hash, actor_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $revision, $json, hash('sha256', $json), $this->actor, $now]);
    }
    private function transaction(callable $operation): array
    {
        $this->db->begin();
        try { $result = $operation(); $this->db->commit(); return $result; }
        catch (\Throwable $error) { $this->db->rollback(); throw $error; }
    }
    private static function json(string $json, int $limit): array
    {
        if (strlen($json) > $limit) { throw new \InvalidArgumentException('Document exceeds byte limit.'); }
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value) || !str_starts_with(ltrim($json), '{')) { throw new \InvalidArgumentException('Expected JSON object.'); }
        return $value;
    }
    private static function body(string $json): array
    {
        $document = self::json($json, 8388608);
        if (($document['contract'] ?? '') !== 'prospektweb.calculator/document-v1' || ($document['schemaVersion'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Unsupported document contract.');
        }
        self::identity($document['id'] ?? '');
        if (!is_string($document['name'] ?? null) || trim($document['name']) === '' || mb_strlen($document['name']) > 255) { throw new \InvalidArgumentException('Invalid document name.'); }
        return $document;
    }
    private static function identity(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $value) !== 1) { throw new \InvalidArgumentException('Invalid identity.'); }
    }
    private static function revision(int $revision): void
    {
        if ($revision < 1 || $revision >= 2147483647) { throw new \InvalidArgumentException('Invalid revision.'); }
    }
    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
}
