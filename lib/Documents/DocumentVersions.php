<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** Named editable branches over immutable document revisions.
 * A clone shares immutable bytes until edited; activating a branch is separate.
 * Scope/actor are supplied by authentication. No Bitrix or resource I/O here. */
final class DocumentVersions
{
    public function __construct(private SqlConnection $db, private string $scope, private string $actor, private DocumentRepository $documents) {}

    public static function primaryId(string $documentId): string { return 'v_' . substr(hash('sha256', 'primary:' . $documentId), 0, 40); }

    /** Called by the repository in its existing document-write transaction. */
    public function recordPrimary(string $id, int $revision, string $at): void
    {
        $this->requireTransaction();
        $versionId = self::primaryId($id);
        $existing = $this->db->rows('SELECT id FROM b_pw_calc_version WHERE id = ? AND document_id = ?', [$versionId, $id]);
        if (!$existing) {
            $this->db->execute('INSERT INTO b_pw_calc_version (id, document_id, version_no, name, head_revision, created_at, updated_at, created_by, updated_by) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?)',
                [$versionId, $id, 'Версия 1', $revision, $at, $at, $this->actor, $this->actor]);
        } else {
            $this->db->execute('UPDATE b_pw_calc_version SET head_revision = ?, updated_at = ?, updated_by = ? WHERE id = ? AND document_id = ? AND deleted = 0', [$revision, $at, $this->actor, $versionId, $id]);
        }
        $this->touch($id);
    }

    public function listing(string $id): array
    {
        return $this->transaction(fn(): array => $this->snapshot($id), true);
    }

    public function load(string $id, string $versionId): array
    {
        return $this->transaction(function () use ($id, $versionId): array {
            $version = $this->requireVersion($id, $versionId);
            return $this->envelope($id, $version);
        }, true);
    }

    /** Explicit read port for a coordinator-owned snapshot. Normal public load
     * keeps owning its transaction and does not silently join ambient writes. */
    public function loadInTransaction(string $id, string $versionId): array
    {
        $this->requireTransaction();
        return $this->envelope($id, $this->requireVersion($id, $versionId));
    }

    public function create(string $id, int $expectedRegistry, string $name, ?string $basedOn, ?string $expectedHash, ?string $blankJson): array
    {
        $name = self::name($name);
        return $this->transaction(function () use ($id, $expectedRegistry, $name, $basedOn, $expectedHash, $blankJson): array {
            $meta = $this->lock($id, $expectedRegistry);
            $number = max((int)$meta['next_version_no'], 1 + (int)$this->db->rows('SELECT COALESCE(MAX(version_no), 0) AS maximum FROM b_pw_calc_version WHERE document_id = ?', [$id])[0]['maximum']);
            if ($number > 10000) { throw new \InvalidArgumentException('Достигнут лимит версий калькулятора.'); }
            $this->db->execute('UPDATE b_pw_calc_document SET next_version_no = ? WHERE id = ? AND scope_id = ?', [$number + 1, $id, $this->scope]);
            if ($basedOn !== null) {
                if ($blankJson !== null) { throw new \InvalidArgumentException('Clone cannot include a blank document.'); }
                $source = $this->requireVersion($id, $basedOn); $envelope = $this->envelope($id, $source);
                if ($expectedHash === null || !hash_equals(self::contentHash($envelope), $expectedHash)) { throw new DocumentConflict('Исходная версия изменилась. Обновите список.'); }
                $revision = (int)$source['head_revision'];
            } else {
                if ($blankJson === null || $expectedHash !== null) { throw new \InvalidArgumentException('Blank version document required.'); }
                $revision = $this->append($id, $blankJson, null);
            }
            $versionId = 'v_' . bin2hex(random_bytes(16)); $at = self::now();
            $this->db->execute('INSERT INTO b_pw_calc_version (id, document_id, version_no, name, head_revision, based_on_version_id, created_at, updated_at, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$versionId, $id, $number, $name, $revision, $basedOn, $at, $at, $this->actor, $this->actor]);
            $this->touch($id);
            return $this->snapshot($id) + ['createdVersionId' => $versionId];
        });
    }

    public function save(string $id, string $versionId, int $expectedRevision, string $json, ?string $connectionJson = null, bool $replaceConnection = false, bool $resetSnapshots = false): array
    {
        return $this->transaction(function () use ($id, $versionId, $expectedRevision, $json, $connectionJson, $replaceConnection, $resetSnapshots): array {
            $meta = $this->lock($id); $version = $this->requireWritableVersion($id, $versionId);
            if ((int)$version['head_revision'] !== $expectedRevision) { throw new DocumentConflict('Версия изменилась. Ваши правки не перезаписаны.'); }
            $current = $this->envelope($id, $version);
            $connection = $replaceConnection ? $connectionJson : $current['connectionJson'];
            $body = self::body($id, $json);
            if ($connection !== null) { $connection = SiteConnection::canonical($connection, $body); }
            $receipt = ['fromRevision' => $expectedRevision, 'fromRegistryRevision' => (int)$meta['versions_revision']];
            if (hash_equals($current['bodyHash'], hash('sha256', $json)) && $connection === $current['connectionJson']) {
                return $current + ['saveReceipt' => $receipt + ['toRevision' => $expectedRevision, 'toRegistryRevision' => (int)$meta['versions_revision']]];
            }
            $this->documents->snapshots()->guardSave($id, $versionId, $current['bodyJson'], $json, $resetSnapshots);
            $revision = $this->append($id, $json, $connection); $at = self::now();
            $this->db->execute('UPDATE b_pw_calc_version SET head_revision = ?, updated_at = ?, updated_by = ? WHERE id = ? AND document_id = ?', [$revision, $at, $this->actor, $versionId, $id]);
            if ($versionId === self::primaryId($id)) {
                $this->db->execute('UPDATE b_pw_calc_document SET current_revision = ?, name = ?, updated_at = ? WHERE id = ? AND scope_id = ?', [$revision, $body['name'], $at, $id, $this->scope]);
            }
            $this->touch($id);
            return $this->envelope($id, $this->requireVersion($id, $versionId)) + ['saveReceipt' => $receipt
                + ['toRevision' => $revision, 'toRegistryRevision' => (int)$this->metadata($id)['versions_revision']]];
        });
    }

    public function change(string $id, string $versionId, int $expectedRegistry, string $action, array $values = []): array
    {
        return $this->transaction(function () use ($id, $versionId, $expectedRegistry, $action, $values): array {
            $this->lock($id, $expectedRegistry); $version = $this->requireVersion($id, $versionId);
            if ($action === 'renameVersion') {
                $name = self::name($values['name']);
                if ($version['name'] === $name) { return $this->snapshot($id); }
                $this->db->execute('UPDATE b_pw_calc_version SET name = ?, updated_at = ?, updated_by = ? WHERE id = ? AND document_id = ?', [$name, self::now(), $this->actor, $versionId, $id]);
            } elseif ($action === 'deleteVersion') {
                $this->documents->lifecycle()->deleteVersionLocked($id, $version);
            } elseif ($action === 'archiveVersion') {
                $active = $this->db->rows('SELECT version_id FROM b_pw_calc_site_active WHERE document_id = ?', [$id])[0]['version_id'] ?? null;
                if ($active === $versionId) { throw new DocumentConflict('Версию на сайте нельзя скрыть или удалить. Сначала активируйте другую.'); }
                $column = $action === 'deleteVersion' ? 'deleted' : 'hidden'; $value = $action === 'deleteVersion' ? 1 : (int)$values['archived'];
                if ((int)$version[$column] === $value) { return $this->snapshot($id); }
                // Tombstones prevent reuse of version numbers. Immutable historical
                // revisions/publications survive for audit and previous calculations.
                $this->db->execute("UPDATE b_pw_calc_version SET $column = ?, updated_at = ?, updated_by = ? WHERE id = ? AND document_id = ?", [$value, self::now(), $this->actor, $versionId, $id]);
            } else { throw new \InvalidArgumentException('Unknown version action.'); }
            $this->touch($id); return $this->snapshot($id);
        });
    }

    /** Publication calls this after taking the common calculator row lock. */
    public function expectLocked(string $id, string $versionId, int $revision, ?int $expectedRegistry = null): array
    {
        $this->lock($id, $expectedRegistry); $version = $this->requireVersion($id, $versionId);
        if ((int)$version['head_revision'] !== $revision || (int)$version['hidden'] !== 0) { throw new DocumentConflict('Версия изменилась или скрыта.'); }
        return $version;
    }

    public function activated(string $id, string $versionId, string $publicationId): void
    {
        $this->requireTransaction();
        $this->db->execute('UPDATE b_pw_calc_version SET last_site_publication = ?, activated_at = ?, activated_by = ? WHERE id = ? AND document_id = ?', [$publicationId, self::now(), $this->actor, $versionId, $id]);
        $this->db->execute('UPDATE b_pw_calc_site_active SET version_id = ? WHERE document_id = ?', [$versionId, $id]);
        $this->touch($id);
    }

    public function assertPrimaryWritable(string $id): void { $this->requireWritableVersion($id, self::primaryId($id)); }

    public static function contentHash(array $envelope): string { return hash('sha256', $envelope['bodyHash'] . ':' . ($envelope['connectionHash'] ?? '')); }

    private function snapshot(string $id): array
    {
        $meta = $this->metadata($id);
        $active = $this->db->rows('SELECT a.version_id, a.publication_id, p.source_revision, p.created_at FROM b_pw_calc_site_active a JOIN b_pw_calc_site_publication p ON p.id = a.publication_id WHERE a.document_id = ?', [$id])[0] ?? null;
        $rows = $this->db->rows('SELECT v.*, r.body_hash, r.connection_hash, p.source_revision AS deployed_revision,
            dr.body_hash AS deployed_body_hash, dr.connection_hash AS deployed_connection_hash
            FROM b_pw_calc_version v JOIN b_pw_calc_revision r ON r.document_id = v.document_id AND r.revision = v.head_revision
            LEFT JOIN b_pw_calc_site_publication p ON p.id = v.last_site_publication AND p.document_id = v.document_id
            LEFT JOIN b_pw_calc_revision dr ON dr.document_id = p.document_id AND dr.revision = p.source_revision
            WHERE v.document_id = ? AND v.deleted = 0 ORDER BY v.version_no DESC', [$id]);
        return ['contract' => 'prospektweb.calculator/versions-v1', 'documentId' => $id, 'calculatorName' => $meta['name'], 'siteEnabled' => (bool)$meta['enabled'],
            'registryRevision' => (int)$meta['versions_revision'], 'archived' => (bool)$meta['archived'],
            'activeVersionId' => $active['version_id'] ?? null, 'activePublication' => $active['publication_id'] ?? null,
            'versions' => array_map(static function (array $row) use ($active): array {
                $workHash = self::contentHash(['bodyHash' => $row['body_hash'], 'connectionHash' => $row['connection_hash']]);
                $deployedHash = $row['deployed_body_hash'] === null ? null : self::contentHash(['bodyHash' => $row['deployed_body_hash'], 'connectionHash' => $row['deployed_connection_hash']]);
                return ['versionId' => $row['id'], 'versionNo' => (int)$row['version_no'], 'name' => $row['name'], 'headRevision' => (int)$row['head_revision'],
                    'basedOnVersionId' => $row['based_on_version_id'], 'archived' => (bool)$row['hidden'], 'active' => ($active['version_id'] ?? null) === $row['id'],
                    'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'], 'createdBy' => $row['created_by'], 'updatedBy' => $row['updated_by'],
                    'lastActivatedAt' => $row['activated_at'], 'lastActivatedBy' => $row['activated_by'], 'lastSitePublication' => $row['last_site_publication'],
                    'hasConnection' => $row['connection_hash'] !== null,
                    'workContentHash' => $workHash, 'deployedContentHash' => $deployedHash, 'hasUnactivatedChanges' => $workHash !== $deployedHash];
            }, $rows)];
    }

    /** Checked after the common document row lock, also used by primary saves.
     * Hiding a version and writing its body/connections must serialize together. */
    private function requireWritableVersion(string $id, string $versionId): array
    {
        $this->requireTransaction();
        $version = $this->requireVersion($id, $versionId);
        if ((int)$version['hidden'] !== 0) { throw new DocumentConflict('Скрытая версия доступна только для просмотра. Сначала восстановите её из архива.'); }
        return $version;
    }

    private function requireVersion(string $id, string $versionId): array
    {
        $this->metadata($id);
        $row = $this->db->rows('SELECT * FROM b_pw_calc_version WHERE id = ? AND document_id = ? AND deleted = 0', [$versionId, $id])[0] ?? null;
        if ($row === null) { throw new \RuntimeException('Версия не найдена.', 404); }
        return $row;
    }
    private function metadata(string $id, bool $lock = false): array
    {
        $row = $this->db->rows('SELECT id, name, archived, enabled, next_version_no, current_revision, versions_revision FROM b_pw_calc_document WHERE id = ? AND scope_id = ?' . ($lock && $this->db->dialect() === 'mysql' ? ' FOR UPDATE' : ''), [$id, $this->scope])[0] ?? null;
        if ($row === null) { throw new \RuntimeException('Document not found.', 404); } return $row;
    }
    private function lock(string $id, ?int $expectedRegistry = null): array
    {
        $this->requireTransaction();
        $meta = $this->metadata($id, true);
        if ((int)$meta['archived'] !== 0) { throw new DocumentConflict('Калькулятор находится в архиве.'); }
        if ($expectedRegistry !== null && ($expectedRegistry < 0 || (int)$meta['versions_revision'] !== $expectedRegistry)) { throw new DocumentConflict('Список версий изменился. Обновите его и повторите действие.'); }
        return $meta;
    }
    private function envelope(string $id, array $version): array
    {
        return array_replace($this->documents->load($id, (int)$version['head_revision']), ['versionId' => $version['id'], 'versionName' => $version['name'],
            'versionArchived' => (bool)$version['hidden'], 'currentRevision' => (int)$version['head_revision']]);
    }
    private function append(string $id, string $json, ?string $connection): int
    {
        self::body($id, $json);
        $revision = 1 + (int)$this->db->rows('SELECT MAX(revision) AS maximum FROM b_pw_calc_revision WHERE document_id = ?', [$id])[0]['maximum'];
        if ($revision >= 2147483647) { throw new \RuntimeException('Document revision limit reached.'); }
        $this->db->execute('INSERT INTO b_pw_calc_revision (document_id, revision, body_json, body_hash, actor_id, created_at, connection_json, connection_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $revision, $json, hash('sha256', $json), $this->actor, self::now(), $connection, $connection === null ? null : hash('sha256', $connection)]);
        return $revision;
    }
    private function requireTransaction(): void { if (!$this->db->inTransaction()) { throw new \LogicException('Version mutation requires transaction.'); } }
    private function touch(string $id): void
    {
        $this->requireTransaction();
        if ((int)$this->metadata($id)['versions_revision'] >= 2147483646) { throw new \RuntimeException('Version registry revision limit reached.'); }
        $this->db->execute('UPDATE b_pw_calc_document SET versions_revision = versions_revision + 1 WHERE id = ? AND scope_id = ?', [$id, $this->scope]);
    }
    private function transaction(callable $operation, bool $readSnapshot = false): array
    {
        $this->db->begin($readSnapshot);
        try { $result = $operation(); $this->db->commit(); return $result; } catch (\Throwable $e) { $this->db->rollback(); throw $e; }
    }
    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 200 || preg_match('/[\x00-\x1f\x7f]/', $name)) { throw new \InvalidArgumentException('Введите название версии длиной до 200 символов.'); }
        return $name;
    }
    private static function body(string $id, string $json): array
    {
        if (strlen($json) > 8388608) { throw new \InvalidArgumentException('Document exceeds byte limit.'); }
        $body = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($body) || ($body['id'] ?? null) !== $id || !in_array([$body['contract'] ?? null, $body['schemaVersion'] ?? null], [
            ['prospektweb.calculator/document-v1', 1], ['prospektweb.calculator/document-v2', 2],
        ], true)
            || !is_string($body['name'] ?? null) || trim($body['name']) === '' || mb_strlen($body['name']) > 255) { throw new \InvalidArgumentException('Invalid version document.'); }
        return $body;
    }
    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
}
