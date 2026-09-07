<?php
declare(strict_types=1);

namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/SqlConnection.php';

/** Explicit, additive installation. Never called on a normal read/write request. */
final class DocumentSchema
{
    public const VERSION = 3;
    public static function install(SqlConnection $db): void
    {
        $mysql = $db->dialect() === 'mysql';
        $id = $mysql ? 'VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin' : 'TEXT';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
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
        // Adapter-local public route key; unrelated to any iblock element ID.
        $publicKey = $mysql ? 'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_site_identity (
            public_id $publicKey, document_id $id NOT NULL UNIQUE,
            FOREIGN KEY (document_id) REFERENCES b_pw_calc_document(id)
        )$suffix");
        // This is a rebuildable projection of active publications, not another editable source.
        $db->execute("CREATE TABLE IF NOT EXISTS b_pw_calc_product_binding (
            scope_id $id NOT NULL, provider $id NOT NULL, catalog_key $id NOT NULL,
            product_key $id NOT NULL, document_id $id NOT NULL, publication_id $id NOT NULL,
            presentation_id $id NOT NULL,
            PRIMARY KEY (scope_id, provider, catalog_key, product_key),
            FOREIGN KEY (document_id) REFERENCES b_pw_calc_document(id),
            FOREIGN KEY (publication_id) REFERENCES b_pw_calc_site_publication(id)
        )$suffix");
        // Registry metadata is not a calculator body and never changes publications.
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
        foreach (['b_pw_calc_document' => ['ix_pw_calc_document_section', 'scope_id, section_id'],
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
            foreach (['b_pw_calc_document', 'b_pw_calc_revision', 'b_pw_calc_publication', 'b_pw_calc_site_publication', 'b_pw_calc_site_active', 'b_pw_calc_site_identity', 'b_pw_calc_product_binding', 'b_pw_calc_catalog', 'b_pw_calc_section'] as $table) {
                $status = $db->rows('SHOW TABLE STATUS WHERE Name = ?', [$table]);
                if (($status[0]['Engine'] ?? '') !== 'InnoDB') {
                    throw new \RuntimeException('Document tables must use InnoDB; installation stopped.');
                }
            }
        } else {
            $db->execute('CREATE INDEX IF NOT EXISTS ix_pw_calc_document_scope ON b_pw_calc_document(scope_id, archived, updated_at, id)');
        }
    }
}
