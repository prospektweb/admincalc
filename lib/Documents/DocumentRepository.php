<?php
declare(strict_types=1);

namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/SqlConnection.php';
require_once __DIR__ . '/SiteConnection.php';
require_once __DIR__ . '/DocumentCatalog.php';
require_once __DIR__ . '/DocumentVersions.php';
require_once __DIR__ . '/DocumentLifecycle.php';
require_once __DIR__ . '/DocumentCalculationSnapshots.php';
require_once __DIR__ . '/DocumentCalculationBatch.php';

final class DocumentConflict extends \RuntimeException
{
    public function __construct(string $message = 'Document revision changed.') { parent::__construct($message, 409); }
}

/**
 * Storage of validated documents. Domain validation belongs to the caller/core,
 * never to SQL. Application commands validate through the portable core.
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

    public function snapshots(): DocumentCalculationSnapshots { return new DocumentCalculationSnapshots($this->db, $this->scope, $this->actor, $this); }
    public function batches(): DocumentCalculationBatch { return new DocumentCalculationBatch($this->db, $this->scope, $this->actor, $this); }

    public function versions(): DocumentVersions { return new DocumentVersions($this->db, $this->scope, $this->actor, $this); }
    public function lifecycle(): DocumentLifecycle { return new DocumentLifecycle($this->db, $this->scope, $this->actor); }

    /** The canonical JSON bytes are supplied by the shared core and hashed unchanged. */
    public function create(string $json, ?string $sectionId = null, ?int $expectedCatalogRevision = null): array
    {
        $document = self::body($json);
        if ($sectionId !== null && $expectedCatalogRevision === null) { throw new \InvalidArgumentException('Expected catalog revision is required.'); }
        return $this->transaction(function () use ($json, $document, $sectionId, $expectedCatalogRevision): array {
            $catalog = new DocumentCatalog($this->db, $this->scope);
            if ($expectedCatalogRevision !== null) { $catalog->lock($expectedCatalogRevision); $catalog->requireSection($sectionId); }
            if ($this->metadata($document['id'], true) !== null) { throw new DocumentConflict('Document already exists.'); }
            $now = self::now();
            $this->db->execute('INSERT INTO b_pw_calc_document (id, scope_id, name, current_revision, active_publication, archived, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$document['id'], $this->scope, $document['name'], 1, null, 0, $now, $now]);
            // Allocate the existing stable numeric identity before the first publication.
            $this->db->execute('INSERT INTO b_pw_calc_site_identity (document_id) VALUES (?)', [$document['id']]);
            $this->appendRevision($document['id'], 1, $json, $now);
            if ($sectionId !== null) { $this->db->execute('UPDATE b_pw_calc_document SET section_id = ? WHERE id = ? AND scope_id = ?', [$sectionId, $document['id'], $this->scope]); }
            if ($expectedCatalogRevision !== null) { $catalog->touch(); }
            return $this->load($document['id']);
        });
    }

    public function save(string $id, int $expectedRevision, string $json, ?string $connectionJson = null, bool $replaceConnection = false, bool $resetSnapshots = false): array
    {
        self::identity($id); self::revision($expectedRevision);
        $document = self::body($json);
        if ($document['id'] !== $id) { throw new \InvalidArgumentException('Document identity is immutable.'); }
        return $this->transaction(function () use ($id, $expectedRevision, $json, $document, $connectionJson, $replaceConnection, $resetSnapshots): array {
            $meta = $this->requireMetadata($id, true);
            $this->expectRevision($meta, $expectedRevision);
            $this->versions()->assertPrimaryWritable($id);
            $current = $this->load($id);
            $connection = $replaceConnection ? $connectionJson : $current['connectionJson'];
            if ($connection !== null) { $connection = SiteConnection::canonical($connection, $document); }
            if (hash_equals($current['bodyHash'], hash('sha256', $json)) && $connection === $current['connectionJson']) { return $current; }
            $this->snapshots()->guardSave($id, DocumentVersions::primaryId($id), $current['bodyJson'], $json, $resetSnapshots);
            $next = $this->nextRevision($id); $now = self::now();
            $this->appendRevision($id, $next, $json, $now, $connection);
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
        $rows = $this->db->rows('SELECT d.id, d.name, d.current_revision, d.active_publication, d.archived, r.revision, r.body_json, r.body_hash, r.connection_json, r.connection_hash, r.actor_id, r.created_at, s.publication_id AS site_publication FROM b_pw_calc_document d JOIN b_pw_calc_revision r ON r.document_id = d.id AND r.revision = ' . ($revision === null ? 'd.current_revision' : '?') . ' LEFT JOIN b_pw_calc_site_active s ON s.document_id = d.id WHERE d.id = ? AND d.scope_id = ?',
            $revision === null ? [$id, $this->scope] : [$revision, $id, $this->scope]);
        if (!$rows) { throw new \RuntimeException('Document not found.', 404); }
        $row = $rows[0];
        if (!hash_equals($row['body_hash'], hash('sha256', $row['body_json']))) {
            throw new \RuntimeException('Document integrity check failed.');
        }
        if (($row['connection_json'] === null) !== ($row['connection_hash'] === null)
            || ($row['connection_json'] !== null && !hash_equals($row['connection_hash'], hash('sha256', $row['connection_json'])))) {
            throw new \RuntimeException('Site connection integrity check failed.');
        }
        return ['id' => $row['id'], 'revision' => (int)$row['revision'], 'currentRevision' => (int)$row['current_revision'],
            'bodyJson' => $row['body_json'], 'bodyHash' => $row['body_hash'], 'activePublication' => $row['active_publication'],
            'connectionJson' => $row['connection_json'], 'connectionHash' => $row['connection_hash'], 'activeSitePublication' => $row['site_publication'],
            'archived' => (bool)$row['archived'], 'actorId' => $row['actor_id'], 'createdAt' => $row['created_at']];
    }

    /** List reads indexed metadata only. No graph JSON is loaded. */
    public function listing(int $limit = 50, int $offset = 0, bool $archived = false): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 100000) { throw new \InvalidArgumentException('Invalid pagination.'); }
        return $this->db->rows('SELECT id, name, current_revision, active_publication, archived, created_at, updated_at FROM b_pw_calc_document WHERE scope_id = ? AND archived = ? ORDER BY updated_at DESC, id LIMIT ' . $limit . ' OFFSET ' . $offset,
            [$this->scope, (int)$archived]);
    }

    /** Metadata-only index; an optional site usage port runs inside the same read snapshot. */
    public function registry(string $query = '', string $status = 'all', string $sort = 'updated_desc', int $page = 1, int $pageSize = 30, ?string $sectionId = null, ?callable $offerCounts = null): array
    {
        if (mb_strlen($query) > 100 || !in_array($status, ['all', 'active', 'inactive', 'archived'], true)
            || $page < 1 || $page > 10000 || $pageSize < 1 || $pageSize > 100) { throw new \InvalidArgumentException('Invalid registry filters.'); }
        $orders = ['updated_desc' => 'd.updated_at DESC, d.id ASC', 'created_desc' => 'd.created_at DESC, d.id DESC',
            'name_asc' => 'LOWER(d.name) ASC, d.id ASC', 'name_desc' => 'LOWER(d.name) DESC, d.id ASC'];
        if (!isset($orders[$sort])) { throw new \InvalidArgumentException('Invalid registry sort.'); }
        $where = 'd.scope_id = ?'; $parameters = [$this->scope];
        if ($status === 'archived') { $where .= ' AND d.archived = 1'; }
        elseif ($status === 'active') { $where .= ' AND d.enabled = 1 AND d.archived = 0 AND EXISTS (SELECT 1 FROM b_pw_calc_site_active a WHERE a.document_id = d.id)'; }
        elseif ($status === 'inactive') { $where .= ' AND (d.enabled = 0 OR d.archived = 1 OR NOT EXISTS (SELECT 1 FROM b_pw_calc_site_active a WHERE a.document_id = d.id))'; }
        $query = trim($query);
        if ($query !== '') {
            $needle = '%' . strtr(mb_strtolower($query, 'UTF-8'), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where .= " AND (LOWER(d.name) LIKE ? ESCAPE '!'";
            $parameters[] = $needle;
            // IDs admit ASCII identity characters only. A Unicode name query
            // cannot match an ID and would mix ascii_bin with utf8mb4 in MySQL.
            if (ctype_digit($query)) {
                $where .= ' OR EXISTS (SELECT 1 FROM b_pw_calc_site_identity i WHERE i.document_id = d.id AND i.public_id = ?)';
                $parameters[] = $query;
            } elseif (preg_match('/^[A-Za-z0-9_.:-]+$/D', $query) === 1) {
                $where .= " OR LOWER(d.id) LIKE ? ESCAPE '!'"; $parameters[] = $needle;
            }
            $where .= ')';
        }
        // Keep count, page bounds, and rows in one consistent transaction snapshot.
        return $this->transaction(function () use ($where, $parameters, $sort, $orders, $page, $pageSize, $sectionId, $offerCounts): array {
            if ($sectionId === '') { $where .= ' AND d.section_id IS NULL'; }
            elseif ($sectionId !== null) {
                $ids = (new DocumentCatalog($this->db, $this->scope))->descendants($sectionId);
                $where .= ' AND d.section_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $parameters = array_merge($parameters, $ids);
            }
            $total = (int)$this->db->rows('SELECT COUNT(*) AS total FROM b_pw_calc_document d WHERE ' . $where, $parameters)[0]['total'];
            $pageCount = max(1, (int)ceil($total / $pageSize)); $page = min($page, $pageCount); $offset = ($page - 1) * $pageSize;
            $rows = $this->db->rows('SELECT d.id, i.public_id, d.name, d.section_id, d.current_revision, d.active_publication, d.archived, d.enabled, d.created_at, d.updated_at, a.publication_id AS site_publication,
                (SELECT COUNT(*) FROM b_pw_calc_product_binding b WHERE b.scope_id = d.scope_id AND b.document_id = d.id AND b.publication_id = a.publication_id) AS product_count
                FROM b_pw_calc_document d LEFT JOIN b_pw_calc_site_identity i ON i.document_id = d.id LEFT JOIN b_pw_calc_site_active a ON a.document_id = d.id WHERE ' . $where
                . ' ORDER BY ' . $orders[$sort] . ' LIMIT ' . $pageSize . ' OFFSET ' . $offset, $parameters);
            $items = array_map(static fn(array $row): array => ['id' => $row['id'], 'publicId' => $row['public_id'] === null ? null : (int)$row['public_id'], 'name' => $row['name'], 'revision' => (int)$row['current_revision'],
                    'enabled' => (bool)$row['enabled'], 'sectionId' => $row['section_id'], 'archived' => (bool)$row['archived'], 'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'],
                    'activePublication' => $row['active_publication'], 'activeSitePublication' => $row['site_publication'], 'productCount' => (int)$row['product_count']], $rows);
            $counts = $offerCounts === null ? array_fill_keys(array_column($items, 'id'), null) : $offerCounts($items);
            if (!is_array($counts) || count($counts) !== count($items)) { throw new \RuntimeException('Incomplete registry usage response.'); }
            foreach ($items as &$item) {
                if (!array_key_exists($item['id'], $counts) || ($counts[$item['id']] !== null && (!is_int($counts[$item['id']]) || $counts[$item['id']] < 0))) {
                    throw new \RuntimeException('Invalid registry offer count.');
                }
                $item['offerCount'] = $counts[$item['id']];
            }
            unset($item);
            return ['contract' => 'prospektweb.calculator/registry-v1', 'total' => $total, 'page' => $page, 'pageSize' => $pageSize, 'pageCount' => $pageCount, 'rows' => $items];
        }, true);
    }

    public function catalog(): array
    {
        return $this->transaction(fn(): array => (new DocumentCatalog($this->db, $this->scope))->snapshot(), true);
    }

    public function changeCatalog(string $action, int $expectedRevision, array $values): array
    {
        return $this->transaction(function () use ($action, $expectedRevision, $values): array {
            $catalog = new DocumentCatalog($this->db, $this->scope); $catalog->lock($expectedRevision); $createdId = null;
            switch ($action) {
                case 'createSection': $createdId = $catalog->create($values['name'], $values['parentId']); break;
                case 'renameSection': $catalog->rename($values['id'], $values['name']); break;
                case 'deleteSection': $catalog->remove($values['id']); break;
                case 'moveToSection': $catalog->move($values['id'], $values['sectionId']); break;
                default: throw new \InvalidArgumentException('Unknown catalog command.');
            }
            return $catalog->snapshot() + ['createdId' => $createdId];
        });
    }

    /** History reads metadata, not all past graphs. Restoring is a new CAS revision. */
    public function history(string $id, int $limit = 50, int $beforeRevision = 2147483647): array
    {
        self::identity($id);
        if ($limit < 1 || $limit > 100 || $beforeRevision < 1) { throw new \InvalidArgumentException('Invalid history pagination.'); }
        $this->requireMetadata($id, false);
        return $this->db->rows('SELECT r.revision, r.body_hash, r.actor_id, r.created_at FROM b_pw_calc_revision r JOIN b_pw_calc_document d ON d.id = r.document_id WHERE d.id = ? AND d.scope_id = ? AND r.revision < ? ORDER BY r.revision DESC LIMIT ' . $limit,
            [$id, $this->scope, $beforeRevision]);
    }

    /** Archive is recoverable and cannot silently unpublish a public calculator. */
    public function archive(string $id, int $expectedRevision, bool $archived): array
    {
        self::identity($id); self::revision($expectedRevision);
        return $this->transaction(function () use ($id, $expectedRevision, $archived): array {
            $meta = $this->requireMetadata($id, true);
            if ((int)$meta['current_revision'] !== $expectedRevision) { throw new DocumentConflict(); }
            if ($meta['active_publication'] !== null || $this->sitePointer($id) !== null) { throw new DocumentConflict('Unpublish before archiving.'); }
            if ((bool)$meta['archived'] === $archived) { return $this->load($id); }
            $current = $this->load($id); $now = self::now(); $next = $this->nextRevision($id);
            $this->appendRevision($id, $next, $current['bodyJson'], $now, $current['connectionJson']);
            $this->db->execute('UPDATE b_pw_calc_document SET archived = ?, current_revision = ?, updated_at = ? WHERE id = ? AND scope_id = ?', [(int)$archived, $next, $now, $id, $this->scope]);
            return $this->load($id);
        });
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

    /** Site compiler output is prepared outside the transaction; all authorities are rechecked here. */
    public function publishSite(string $id, int $expectedRevision, ?string $expectedSitePublication, string $snapshotJson, ?string $versionId = null, ?int $expectedVersionsRevision = null): array
    {
        self::identity($id); self::revision($expectedRevision);
        if ($expectedSitePublication !== null) { self::identity($expectedSitePublication); }
        $snapshot = self::json($snapshotJson, 33554432);
        $keys = array_keys($snapshot); sort($keys);
        if ($keys !== ['connection', 'connectionHash', 'contract', 'core', 'documentHash', 'documentId', 'runtime', 'sourceRevision']
            || ($snapshot['contract'] ?? '') !== 'prospektweb.calculator/site-publication-v1'
            || ($snapshot['documentId'] ?? '') !== $id || ($snapshot['sourceRevision'] ?? null) !== $expectedRevision
            || !is_string($snapshot['connectionHash'] ?? null) || !is_string($snapshot['documentHash'] ?? null)
            || !is_array($snapshot['runtime'] ?? null) || !is_array($snapshot['connection'] ?? null)
            || ($snapshot['core']['contract'] ?? '') !== 'prospektweb.calculator/publication-v1'
            || ($snapshot['core']['calculatorId'] ?? '') !== $id
            || ($snapshot['core']['documentHash'] ?? '') !== $snapshot['documentHash']) {
            throw new \InvalidArgumentException('Invalid site publication envelope.');
        }
        return $this->transaction(function () use ($id, $expectedRevision, $expectedSitePublication, $snapshot, $snapshotJson, $versionId, $expectedVersionsRevision): array {
            $meta = $this->requireMetadata($id, true);
            if ($versionId === null) { $this->expectRevision($meta, $expectedRevision); $versionId = DocumentVersions::primaryId($id); }
            elseif ($expectedVersionsRevision === null) { throw new \InvalidArgumentException('Expected versions registry revision required.'); }
            $this->versions()->expectLocked($id, $versionId, $expectedRevision, $expectedVersionsRevision);
            if ($this->sitePointer($id) !== $expectedSitePublication) { throw new DocumentConflict('Site publication changed.'); }
            $source = $this->load($id, $expectedRevision);
            $connectionObject = json_decode($snapshotJson, false, 64, JSON_THROW_ON_ERROR)->connection;
            $connectionJson = SiteConnection::canonical(SiteConnection::encode($connectionObject), json_decode($source['bodyJson'], true, 64, JSON_THROW_ON_ERROR));
            if ($source['connectionJson'] === null || !hash_equals($source['bodyHash'], $snapshot['documentHash'])
                || !hash_equals($source['connectionHash'], $snapshot['connectionHash'])
                || !hash_equals($source['connectionHash'], hash('sha256', $connectionJson))) {
                throw new DocumentConflict('Site publication was compiled from another revision.');
            }
            $connection = json_decode($connectionJson, true, 64, JSON_THROW_ON_ERROR);
            $hash = hash('sha256', $snapshotJson); $publicationId = 's_' . $hash;
            $existing = $this->db->rows('SELECT id FROM b_pw_calc_site_publication WHERE id = ? AND document_id = ?', [$publicationId, $id]);
            if (!$existing) {
                $this->db->execute('INSERT INTO b_pw_calc_site_publication (id, document_id, source_revision, snapshot_json, snapshot_hash, actor_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$publicationId, $id, $expectedRevision, $snapshotJson, $hash, $this->actor, self::now()]);
            }
            // Rebuild only this document's projection. Another calculator's product cannot be stolen.
            $this->db->execute('DELETE FROM b_pw_calc_product_binding WHERE scope_id = ? AND document_id = ?', [$this->scope, $id]);
            foreach ($connection['products'] as $product) {
                $existingBinding = $this->db->rows('SELECT document_id FROM b_pw_calc_product_binding WHERE scope_id = ? AND provider = ? AND catalog_key = ? AND product_key = ?',
                    [$this->scope, $connection['provider'], $connection['productsCatalog'], $product['key']]);
                if ($existingBinding) { throw new DocumentConflict('Product is already assigned to another calculator.'); }
                try {
                    $this->db->execute('INSERT INTO b_pw_calc_product_binding (scope_id, provider, catalog_key, product_key, document_id, publication_id, presentation_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$this->scope, $connection['provider'], $connection['productsCatalog'], $product['key'], $id, $publicationId, $product['presentationId']]);
                } catch (\Throwable $error) {
                    // Concurrent unique-key contenders must retry from current state; the entire publication rolls back.
                    throw new DocumentConflict('Product assignment could not be published; reload and retry.');
                }
            }
            if ($expectedSitePublication === null) {
                if (!$this->db->rows('SELECT public_id FROM b_pw_calc_site_identity WHERE document_id = ?', [$id])) {
                    $this->db->execute('INSERT INTO b_pw_calc_site_identity (document_id) VALUES (?)', [$id]);
                }
                $this->db->execute('INSERT INTO b_pw_calc_site_active (document_id, publication_id) VALUES (?, ?)', [$id, $publicationId]);
            } else {
                $this->db->execute('UPDATE b_pw_calc_site_active SET publication_id = ? WHERE document_id = ?', [$publicationId, $id]);
            }
            $this->versions()->activated($id, $versionId, $publicationId);
            return $this->sitePublication($id, false);
        });
    }

    /** Indexed, exact lookup; reads no graph and never consults a preset iblock. */
    public function productBinding(string $provider, string $catalog, string $product): ?array
    {
        self::identity($provider); self::identity($catalog); self::identity($product);
        $rows = $this->db->rows('SELECT b.document_id, b.publication_id, b.presentation_id FROM b_pw_calc_product_binding b JOIN b_pw_calc_document d ON d.id = b.document_id AND d.scope_id = b.scope_id JOIN b_pw_calc_site_active a ON a.document_id = b.document_id AND a.publication_id = b.publication_id WHERE b.scope_id = ? AND b.provider = ? AND b.catalog_key = ? AND b.product_key = ? AND d.archived = 0 AND d.enabled = 1',
            [$this->scope, $provider, $catalog, $product]);
        return $rows[0] ?? null;
    }

    /** Batched read-only ownership for the product picker; never infer it from draft versions. */
    public function productBindings(string $provider, string $catalog, array $products): array
    {
        self::identity($provider); self::identity($catalog);
        if (count($products) > 10000) { throw new \InvalidArgumentException('Too many products.'); }
        foreach ($products as $product) { self::identity($product); }
        $result = [];
        foreach (array_chunk(array_values(array_unique($products)), 100) as $batch) {
            $marks = implode(',', array_fill(0, count($batch), '?'));
            $rows = $this->db->rows('SELECT b.product_key, b.document_id, b.publication_id, d.name FROM b_pw_calc_product_binding b JOIN b_pw_calc_document d ON d.id=b.document_id AND d.scope_id=b.scope_id JOIN b_pw_calc_site_active a ON a.document_id=b.document_id AND a.publication_id=b.publication_id WHERE b.scope_id=? AND b.provider=? AND b.catalog_key=? AND d.archived=0 AND b.product_key IN ('.$marks.')', array_merge([$this->scope, $provider, $catalog], $batch));
            foreach ($rows as $row) { $result[(string)$row['product_key']] = $row; }
        }
        return $result;
    }

    public function sitePublication(string $id, bool $requireEnabled = true): array
    {
        self::identity($id);
        $rows = $this->db->rows('SELECT p.*, i.public_id FROM b_pw_calc_document d JOIN b_pw_calc_site_active a ON a.document_id = d.id JOIN b_pw_calc_site_identity i ON i.document_id = d.id JOIN b_pw_calc_site_publication p ON p.id = a.publication_id AND p.document_id = d.id WHERE d.id = ? AND d.scope_id = ? AND d.archived = 0' . ($requireEnabled ? ' AND d.enabled = 1' : ''), [$id, $this->scope]);
        if (!$rows) { throw new \RuntimeException('Site publication not found.', 404); }
        $row = $rows[0];
        if (!hash_equals($row['snapshot_hash'], hash('sha256', $row['snapshot_json'])) || $row['id'] !== 's_' . $row['snapshot_hash']) {
            throw new \RuntimeException('Site publication integrity check failed.');
        }
        return ['id' => $row['id'], 'documentId' => $id, 'publicId' => (int)$row['public_id'], 'sourceRevision' => (int)$row['source_revision'], 'snapshotJson' => $row['snapshot_json'], 'snapshotHash' => $row['snapshot_hash']];
    }

    public function siteDocumentId(int $publicId): ?string
    {
        self::revision($publicId);
        $rows = $this->db->rows('SELECT i.document_id FROM b_pw_calc_site_identity i JOIN b_pw_calc_document d ON d.id = i.document_id WHERE i.public_id = ? AND d.scope_id = ?', [$publicId, $this->scope]);
        return $rows[0]['document_id'] ?? null;
    }

    public function siteListing(): array
    {
        return $this->db->rows('SELECT i.public_id, d.id FROM b_pw_calc_document d JOIN b_pw_calc_site_identity i ON i.document_id = d.id JOIN b_pw_calc_site_active a ON a.document_id = d.id WHERE d.scope_id = ? AND d.archived = 0 AND d.enabled = 1 ORDER BY i.public_id LIMIT 1000', [$this->scope]);
    }

    /** Caller must already own the basket transaction; do not commit or begin here. */
    public function lockSitePublication(string $id, string $expectedPublication): void
    {
        self::identity($id); self::identity($expectedPublication);
        if (!$this->db->inTransaction()) { throw new \LogicException('A basket transaction is required to hold publication authority.'); }
        $meta = $this->requireMetadata($id, true);
        if ((int)$meta['archived'] !== 0 || (int)$meta['enabled'] !== 1 || $this->sitePointer($id) !== $expectedPublication) {
            throw new DocumentConflict('Site publication is stale.');
        }
    }

    private function sitePointer(string $id): ?string
    {
        $rows = $this->db->rows('SELECT publication_id FROM b_pw_calc_site_active WHERE document_id = ?' . ($this->db->inTransaction() && $this->db->dialect() === 'mysql' ? ' FOR UPDATE' : ''), [$id]);
        return $rows[0]['publication_id'] ?? null;
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
    private function appendRevision(string $id, int $revision, string $json, string $now, ?string $connectionJson = null): void
    {
        $this->db->execute('INSERT INTO b_pw_calc_revision (document_id, revision, body_json, body_hash, actor_id, created_at, connection_json, connection_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $revision, $json, hash('sha256', $json), $this->actor, $now, $connectionJson, $connectionJson === null ? null : hash('sha256', $connectionJson)]);
        $this->versions()->recordPrimary($id, $revision, $now);
    }
    private function nextRevision(string $id): int
    {
        // Branch edits also append immutable revisions while the default working
        // pointer can stay behind. The document lock serializes allocation.
        $next = 1 + (int)$this->db->rows('SELECT MAX(revision) AS maximum FROM b_pw_calc_revision WHERE document_id = ?', [$id])[0]['maximum'];
        self::revision($next); return $next;
    }
    private function transaction(callable $operation, bool $readSnapshot = false): array
    {
        $this->db->begin($readSnapshot);
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
        if (!in_array([$document['contract'] ?? null, $document['schemaVersion'] ?? null], [
            ['prospektweb.calculator/document-v1', 1], ['prospektweb.calculator/document-v2', 2],
        ], true)) {
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
