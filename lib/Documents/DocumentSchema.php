<?php
declare(strict_types=1);

namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/SqlConnection.php';

/** Explicit, additive installation. Never called on a normal read/write request. */
final class DocumentSchema
{
    public const VERSION = 14;
    public static function install(SqlConnection $db): void
    {
        $mysql = $db->dialect() === 'mysql';
        $id = $mysql ? 'VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin' : 'TEXT';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_batch (
            id CHAR(64) NOT NULL PRIMARY KEY, scope_id $id NOT NULL, document_id $id NOT NULL,
            version_id $id NOT NULL, actor_id $id NOT NULL, request_hash CHAR(64) NOT NULL,
            artifact_json $text NOT NULL, state_json $text NOT NULL, created_at VARCHAR(30) NOT NULL
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_document (
            id $id NOT NULL PRIMARY KEY, scope_id $id NOT NULL,
            name VARCHAR(255) NOT NULL, current_revision INTEGER NOT NULL,
            active_publication $id NULL, archived INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_revision (
            document_id $id NOT NULL, revision INTEGER NOT NULL,
            body_json $text NOT NULL, body_hash CHAR(64) NOT NULL,
            actor_id $id NOT NULL, created_at VARCHAR(30) NOT NULL,
            PRIMARY KEY (document_id, revision),
            FOREIGN KEY (document_id) REFERENCES b_pw_calc_document(id)
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_publication (
            id $id NOT NULL PRIMARY KEY, document_id $id NOT NULL,
            source_revision INTEGER NOT NULL, engine_version VARCHAR(128) NOT NULL,
            snapshot_json $text NOT NULL, snapshot_hash CHAR(64) NOT NULL,
            actor_id $id NOT NULL, created_at VARCHAR(30) NOT NULL,
            FOREIGN KEY (document_id, source_revision) REFERENCES b_pw_calc_revision(document_id, revision)
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_snapshot (
            id $id NOT NULL PRIMARY KEY, scope_id $id NOT NULL, document_id $id NOT NULL,
            version_id $id NOT NULL, actor_id $id NOT NULL, storefront_id $id NOT NULL,
            form_hash CHAR(64) NOT NULL, summary_json $text NULL, payload_json $text NOT NULL, payload_hash CHAR(64) NOT NULL,
            created_at VARCHAR(30) NOT NULL
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_snapshot_group (
            id $id NOT NULL PRIMARY KEY, scope_id $id NOT NULL, document_id $id NOT NULL,
            version_id $id NOT NULL, actor_id $id NOT NULL, storefront_id $id NOT NULL,
            name VARCHAR(200) NOT NULL, sort INTEGER NOT NULL, collapsed INTEGER NOT NULL DEFAULT 0
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_snapshot_member (
            snapshot_id $id NOT NULL PRIMARY KEY, group_id $id NOT NULL,
            FOREIGN KEY (snapshot_id) REFERENCES b_pw_calc_snapshot(id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES b_pw_calc_snapshot_group(id) ON DELETE CASCADE
        )$suffix");
        // Preparation owns its copies. No foreign key to working receipts, groups, versions or documents.
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_preparation (
            id CHAR(64) NOT NULL PRIMARY KEY, scope_id $id NOT NULL, provider $id NOT NULL,
            catalog_id $id NOT NULL, product_key $id NOT NULL, document_id $id NOT NULL,
            storefront_id $id NOT NULL, revision INTEGER NOT NULL
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_preparation_result (
            id $id NOT NULL PRIMARY KEY, preparation_id CHAR(64) NOT NULL, variant_key CHAR(64) NOT NULL,
            snapshot_id $id NOT NULL, active INTEGER NOT NULL, form_hash CHAR(64) NOT NULL,
            payload_json $text NOT NULL, payload_hash CHAR(64) NOT NULL, summary_json $text NOT NULL,
            provenance_json $text NOT NULL, created_at VARCHAR(30) NOT NULL,
            UNIQUE (preparation_id, snapshot_id),
            FOREIGN KEY (preparation_id) REFERENCES b_pw_calc_preparation(id)
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_preparation_history (
            id $id NOT NULL PRIMARY KEY, preparation_id CHAR(64) NOT NULL,
            decision_json $text NOT NULL, actor_id $id NOT NULL, created_at VARCHAR(30) NOT NULL,
            FOREIGN KEY (preparation_id) REFERENCES b_pw_calc_preparation(id)
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_group_target (
            group_id $id NOT NULL PRIMARY KEY, product_key $id NOT NULL,
            FOREIGN KEY (group_id) REFERENCES b_pw_calc_snapshot_group(id) ON DELETE CASCADE
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_preparation_offer (
            preparation_id CHAR(64) NOT NULL, variant_key CHAR(64) NOT NULL,
            scope_id $id NOT NULL, offer_id INTEGER NOT NULL, result_id $id NOT NULL,
            mapping_hash CHAR(64) NOT NULL, receipt_id CHAR(64) NOT NULL,
            PRIMARY KEY (preparation_id, variant_key), UNIQUE (scope_id, offer_id),
            FOREIGN KEY (preparation_id) REFERENCES b_pw_calc_preparation(id),
            FOREIGN KEY (result_id) REFERENCES b_pw_calc_preparation_result(id)
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_preparation_write (
            id CHAR(64) NOT NULL PRIMARY KEY, preparation_id CHAR(64) NOT NULL,
            scope_id $id NOT NULL, actor_id $id NOT NULL, fingerprint CHAR(64) NOT NULL,
            receipt_json $text NOT NULL, receipt_hash CHAR(64) NOT NULL, created_at VARCHAR(30) NOT NULL,
            FOREIGN KEY (preparation_id) REFERENCES b_pw_calc_preparation(id)
        )$suffix");
        $snapshotColumns = $mysql ? array_column($db->rows('SHOW COLUMNS FROM b_pw_calc_snapshot'), 'Field') : array_column($db->rows('PRAGMA table_info(b_pw_calc_snapshot)'), 'name');
        if (!in_array('summary_json', $snapshotColumns, true)) $db->execute("ALTER TABLE b_pw_calc_snapshot ADD COLUMN summary_json $text NULL");
        foreach ($db->rows('SELECT id, payload_json FROM b_pw_calc_snapshot WHERE summary_json IS NULL') as $row) {
            $payload = json_decode($row['payload_json'], true, 64, JSON_THROW_ON_ERROR); $result = $payload['response']['result'];
            $summary = ['name' => $result['name'], 'revision' => $payload['response']['source']['revision'], 'purchasingPrice' => $result['purchasingPrice'], 'basePrice' => $result['basePrice'], 'currency' => $result['currency']];
            $db->execute('UPDATE b_pw_calc_snapshot SET summary_json = ? WHERE id = ?', [json_encode($summary, JSON_THROW_ON_ERROR), $row['id']]);
        }
        // Additive upgrade of existing installations; old revisions remain intact.
        $columns = $mysql ? array_column($db->rows('SHOW COLUMNS FROM b_pw_calc_revision'), 'Field')
            : array_column($db->rows('PRAGMA table_info(b_pw_calc_revision)'), 'name');
        if (!in_array('connection_json', $columns, true)) {
            $db->execute("ALTER TABLE b_pw_calc_revision ADD COLUMN connection_json $text NULL");
        }
        if (!in_array('connection_hash', $columns, true)) {
            $db->execute('ALTER TABLE b_pw_calc_revision ADD COLUMN connection_hash CHAR(64) NULL');
        }
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_site_publication (
            id $id NOT NULL PRIMARY KEY, document_id $id NOT NULL, source_revision INTEGER NOT NULL,
            snapshot_json $text NOT NULL, snapshot_hash CHAR(64) NOT NULL,
            actor_id $id NOT NULL, created_at VARCHAR(30) NOT NULL,
            FOREIGN KEY (document_id, source_revision) REFERENCES b_pw_calc_revision(document_id, revision)
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_site_active (
            document_id $id NOT NULL PRIMARY KEY, publication_id $id NOT NULL,
            FOREIGN KEY (document_id) REFERENCES b_pw_calc_document(id),
            FOREIGN KEY (publication_id) REFERENCES b_pw_calc_site_publication(id)
        )$suffix");
        // Stable numeric registry/public route key; unrelated to any iblock element ID.
        $publicKey = $mysql ? 'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_site_identity (
            public_id $publicKey, document_id $id NOT NULL UNIQUE,
            FOREIGN KEY (document_id) REFERENCES b_pw_calc_document(id)
        )$suffix");
        self::backfillRegistryIdentities($db);
        // This is a rebuildable projection of active publications, not another editable source.
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_product_binding (
            scope_id $id NOT NULL, provider $id NOT NULL, catalog_key $id NOT NULL,
            product_key $id NOT NULL, document_id $id NOT NULL, publication_id $id NOT NULL,
            presentation_id $id NOT NULL,
            PRIMARY KEY (scope_id, provider, catalog_key, product_key),
            FOREIGN KEY (document_id) REFERENCES b_pw_calc_document(id),
            FOREIGN KEY (publication_id) REFERENCES b_pw_calc_site_publication(id)
        )$suffix");
        // Immutable operation receipts/audit, not another editable calculator entity.
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_catalog_write (
            id $id NOT NULL PRIMARY KEY, scope_id $id NOT NULL, document_id $id NOT NULL,
            publication_id $id NOT NULL, actor_id $id NOT NULL, fingerprint CHAR(64) NOT NULL,
            receipt_json $text NOT NULL, receipt_hash CHAR(64) NOT NULL, created_at VARCHAR(30) NOT NULL,
            FOREIGN KEY (document_id) REFERENCES b_pw_calc_document(id),
            FOREIGN KEY (publication_id) REFERENCES b_pw_calc_site_publication(id)
        )$suffix");
        // Registry metadata is not a calculator body and never changes publications.
        // Reusable authoring records are not fake calculators or module-option JSON blobs.
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_library_catalog (
            scope_id $id NOT NULL, kind $id NOT NULL, revision INTEGER NOT NULL,
            PRIMARY KEY (scope_id, kind)
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_library_record (
            id $id NOT NULL PRIMARY KEY, scope_id $id NOT NULL, kind $id NOT NULL,
            name VARCHAR(200) NOT NULL, head_revision INTEGER NOT NULL, deleted INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL,
            created_by $id NOT NULL, updated_by $id NOT NULL,
            FOREIGN KEY (scope_id, kind) REFERENCES b_pw_calc_library_catalog(scope_id, kind)
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_library_revision (
            record_id $id NOT NULL, revision INTEGER NOT NULL, name VARCHAR(200) NOT NULL,
            deleted INTEGER NOT NULL, body_json $text NOT NULL, body_hash CHAR(64) NOT NULL,
            actor_id $id NOT NULL, created_at VARCHAR(30) NOT NULL,
            PRIMARY KEY (record_id, revision), FOREIGN KEY (record_id) REFERENCES b_pw_calc_library_record(id)
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_catalog (
            scope_id $id NOT NULL PRIMARY KEY, revision INTEGER NOT NULL
        )$suffix");
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_section (
            id $id NOT NULL PRIMARY KEY, scope_id $id NOT NULL, parent_id $id NULL,
            name VARCHAR(200) NOT NULL, sort INTEGER NOT NULL DEFAULT 500,
            FOREIGN KEY (scope_id) REFERENCES b_pw_calc_catalog(scope_id),
            FOREIGN KEY (parent_id) REFERENCES b_pw_calc_section(id)
        )$suffix");
        $documentColumns = $mysql ? array_column($db->rows('SHOW COLUMNS FROM b_pw_calc_document'), 'Field')
            : array_column($db->rows('PRAGMA table_info(b_pw_calc_document)'), 'name');
        if (!in_array('section_id', $documentColumns, true)) {
            $db->execute("ALTER TABLE b_pw_calc_document ADD COLUMN section_id $id NULL");
        }
        if (!in_array('versions_revision', $documentColumns, true)) {
            $db->execute('ALTER TABLE b_pw_calc_document ADD COLUMN versions_revision INTEGER NOT NULL DEFAULT 0');
        }
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_version (
            id $id NOT NULL PRIMARY KEY, document_id $id NOT NULL, version_no INTEGER NOT NULL,
            name VARCHAR(200) NOT NULL, head_revision INTEGER NOT NULL, based_on_version_id $id NULL,
            hidden INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL,
            created_by $id NOT NULL, updated_by $id NOT NULL,
            last_site_publication $id NULL, activated_at VARCHAR(30) NULL, activated_by $id NULL,
            UNIQUE (document_id, version_no),
            FOREIGN KEY (document_id, head_revision) REFERENCES b_pw_calc_revision(document_id, revision),
            FOREIGN KEY (last_site_publication) REFERENCES b_pw_calc_site_publication(id)
        )$suffix");
        $versionColumns = $mysql ? array_column($db->rows('SHOW COLUMNS FROM b_pw_calc_version'), 'Field')
            : array_column($db->rows('PRAGMA table_info(b_pw_calc_version)'), 'name');
        if (!in_array('activated_at', $versionColumns, true)) { $db->execute('ALTER TABLE b_pw_calc_version ADD COLUMN activated_at VARCHAR(30) NULL'); }
        if (!in_array('activated_by', $versionColumns, true)) { $db->execute("ALTER TABLE b_pw_calc_version ADD COLUMN activated_by $id NULL"); }
        $activeColumns = $mysql ? array_column($db->rows('SHOW COLUMNS FROM b_pw_calc_site_active'), 'Field')
            : array_column($db->rows('PRAGMA table_info(b_pw_calc_site_active)'), 'name');
        if (!in_array('version_id', $activeColumns, true)) {
            $db->execute("ALTER TABLE b_pw_calc_site_active ADD COLUMN version_id $id NULL");
        }
        // Published snapshots/receipts may outlive an authoring calculator. This
        // detached audit store has no runtime routes or editable versions.
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_deletion_audit (
            id $id NOT NULL PRIMARY KEY, scope_id $id NOT NULL, document_id $id NOT NULL,
            version_id $id NULL, body_json $text NOT NULL, body_hash CHAR(64) NOT NULL,
            actor_id $id NOT NULL, created_at VARCHAR(30) NOT NULL
        )$suffix");
        if (!in_array('enabled', $documentColumns, true)) {
            $db->execute('ALTER TABLE b_pw_calc_document ADD COLUMN enabled INTEGER NOT NULL DEFAULT 1');
            // Former archived records stay offline but become manageable.
            $db->execute('UPDATE b_pw_calc_document SET enabled = CASE WHEN archived = 1 THEN 0 ELSE 1 END, archived = 0');
            $db->execute('UPDATE b_pw_calc_version SET hidden = 0');
        }
        if (!in_array('next_version_no', $documentColumns, true)) {
            $db->execute('ALTER TABLE b_pw_calc_document ADD COLUMN next_version_no INTEGER NOT NULL DEFAULT 2');
            $db->execute('UPDATE b_pw_calc_document SET next_version_no = COALESCE((SELECT MAX(version_no) + 1 FROM b_pw_calc_version WHERE document_id = b_pw_calc_document.id), 2)');
        }
        // Explicit one-time metadata bootstrap, never a lazy write during reads.
        // It points at existing immutable revisions/publications without rewriting them.
        $unversioned = $db->rows('SELECT d.id, d.current_revision, d.created_at, d.updated_at, r.actor_id, a.publication_id, p.created_at AS activated_at, p.actor_id AS activated_by
            FROM b_pw_calc_document d JOIN b_pw_calc_revision r ON r.document_id = d.id AND r.revision = d.current_revision
            LEFT JOIN b_pw_calc_site_active a ON a.document_id = d.id
            LEFT JOIN b_pw_calc_site_publication p ON p.id = a.publication_id
            WHERE NOT EXISTS (SELECT 1 FROM b_pw_calc_version v WHERE v.document_id = d.id)
            AND NOT EXISTS (SELECT 1 FROM b_pw_calc_deletion_audit x WHERE x.document_id = d.id AND x.scope_id = d.scope_id)');
        foreach ($unversioned as $document) {
            $versionId = 'v_' . substr(hash('sha256', 'primary:' . $document['id']), 0, 40);
            $db->execute('INSERT INTO b_pw_calc_version (id, document_id, version_no, name, head_revision, created_at, updated_at, created_by, updated_by, last_site_publication, activated_at, activated_by) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$versionId, $document['id'], 'Версия 1', (int)$document['current_revision'], $document['created_at'], $document['updated_at'], $document['actor_id'], $document['actor_id'], $document['publication_id'], $document['activated_at'], $document['activated_by']]);
        }
        // Resume after an interrupted metadata bootstrap, without resurrecting a
        // deleted version or replacing an already assigned activation pointer.
        foreach ($db->rows('SELECT a.document_id, v.id FROM b_pw_calc_site_active a JOIN b_pw_calc_version v ON v.document_id = a.document_id AND v.version_no = 1 AND v.deleted = 0 AND v.last_site_publication = a.publication_id WHERE a.version_id IS NULL') as $activation) {
            $db->execute('UPDATE b_pw_calc_site_active SET version_id = ? WHERE document_id = ? AND version_id IS NULL', [$activation['id'], $activation['document_id']]);
        }
        foreach (['b_pw_calc_library_record' => ['ix_pw_calc_library_scope', 'scope_id, kind, deleted'],
            'b_pw_calc_document' => ['ix_pw_calc_document_section', 'scope_id, section_id'],
            'b_pw_calc_section' => ['ix_pw_calc_section_parent', 'scope_id, parent_id, sort']] as $table => [$index, $columns]) {
            if (!$mysql || !in_array($index, array_column($db->rows('SHOW INDEX FROM ' . $table), 'Key_name'), true)) {
                $db->execute('CREATE INDEX ' . ($mysql ? '' : 'IF NOT EXISTS ') . "$index ON $table($columns)");
            }
        }
        if ($mysql) {
            $indexes = $db->rows('SHOW INDEX FROM b_pw_calc_document');
            if (!in_array('ix_pw_calc_document_scope', array_column($indexes, 'Key_name'), true)) {
                $db->execute('CREATE INDEX ix_pw_calc_document_scope ON b_pw_calc_document(scope_id, archived, updated_at, id)');
            }
            foreach (['b_pw_calc_batch', 'b_pw_calc_deletion_audit', 'b_pw_calc_document', 'b_pw_calc_revision', 'b_pw_calc_publication', 'b_pw_calc_site_publication', 'b_pw_calc_site_active', 'b_pw_calc_site_identity', 'b_pw_calc_product_binding', 'b_pw_calc_catalog', 'b_pw_calc_section', 'b_pw_calc_version', 'b_pw_calc_catalog_write', 'b_pw_calc_library_catalog', 'b_pw_calc_library_record', 'b_pw_calc_library_revision'] as $table) {
                $status = $db->rows('SHOW TABLE STATUS WHERE Name = ?', [$table]);
                if (($status[0]['Engine'] ?? '') !== 'InnoDB') {
                    throw new \RuntimeException('Document tables must use InnoDB; installation stopped.');
                }
            }
        } else {
            $db->execute('CREATE INDEX IF NOT EXISTS ix_pw_calc_document_scope ON b_pw_calc_document(scope_id, archived, updated_at, id)');
        }
    }

    /** Explicit additive upgrade only; preserve every previously issued route ID. */
    public static function backfillRegistryIdentities(SqlConnection $db): void
    {
        $db->execute('INSERT INTO b_pw_calc_site_identity (document_id)
            SELECT d.id FROM b_pw_calc_document d
            LEFT JOIN b_pw_calc_site_identity i ON i.document_id = d.id
            WHERE i.document_id IS NULL ORDER BY d.created_at, d.id');
    }
}
