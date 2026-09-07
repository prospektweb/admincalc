<?php
declare(strict_types=1);

namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/SqlConnection.php';

/** Explicit, additive installation. Never called on a normal read/write request. */
final class DocumentSchema
{
    public const VERSION = 1;
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
        if ($mysql) {
            $indexes = $db->rows('SHOW INDEX FROM b_pw_calc_document');
            if (!in_array('ix_pw_calc_document_scope', array_column($indexes, 'Key_name'), true)) {
                $db->execute('CREATE INDEX ix_pw_calc_document_scope ON b_pw_calc_document(scope_id, archived, updated_at, id)');
            }
            foreach (['b_pw_calc_document', 'b_pw_calc_revision', 'b_pw_calc_publication'] as $table) {
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
