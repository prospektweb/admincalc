<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Install;

use Bitrix\Main\Application;
use Prospektweb\Calc\Modules\Storage\ModuleAuditTable;
use Prospektweb\Calc\Modules\Storage\ModuleFamilyTable;
use Prospektweb\Calc\Modules\Storage\ModuleInstanceTable;
use Prospektweb\Calc\Modules\Storage\ModuleSnapshotTable;
use Prospektweb\Calc\Modules\Storage\ModuleVersionTable;

final class ModuleStorageInstaller
{
    private const TABLES = [
        ModuleFamilyTable::class,
        ModuleVersionTable::class,
        ModuleInstanceTable::class,
        ModuleSnapshotTable::class,
        ModuleAuditTable::class,
    ];

    private const INDEXES = [
        ['b_pw_calc_module_family', 'ux_pw_calc_module_family_code', ['CODE'], true],
        ['b_pw_calc_module_version', 'ux_pw_calc_module_version_family_version', ['FAMILY_ID', 'VERSION'], true],
        ['b_pw_calc_module_version', 'ix_pw_calc_module_version_hash', ['CONTENT_HASH'], false],
        ['b_pw_calc_module_instance', 'ux_pw_calc_module_instance_uid', ['INSTANCE_UID'], true],
        ['b_pw_calc_module_instance', 'ix_pw_calc_module_instance_preset', ['PRESET_ID'], false],
        ['b_pw_calc_module_instance', 'ix_pw_calc_module_instance_version', ['VERSION_ID'], false],
        ['b_pw_calc_module_snapshot', 'ux_pw_calc_module_snapshot_uid', ['SNAPSHOT_UID'], true],
        ['b_pw_calc_module_snapshot', 'ix_pw_calc_module_snapshot_instance', ['INSTANCE_ID'], false],
        ['b_pw_calc_module_audit', 'ix_pw_calc_module_audit_family', ['FAMILY_ID'], false],
        ['b_pw_calc_module_audit', 'ix_pw_calc_module_audit_instance', ['INSTANCE_ID'], false],
    ];

    public function ensureSchema(): array
    {
        $connection = Application::getConnection();
        $createdTables = [];
        $existingTables = [];
        $createdIndexes = [];

        foreach (self::TABLES as $dataClass) {
            $table = $dataClass::getTableName();
            if ($connection->isTableExists($table)) {
                $existingTables[] = $table;
                continue;
            }
            $dataClass::getEntity()->createDbTable();
            $createdTables[] = $table;
        }

        foreach (self::INDEXES as [$table, $index, $columns, $unique]) {
            if ($this->hasIndex($table, $index)) {
                continue;
            }
            $columnSql = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $columns));
            $uniqueSql = $unique ? 'UNIQUE ' : '';
            $connection->queryExecute(
                "CREATE {$uniqueSql}INDEX `{$index}` ON `{$table}` ({$columnSql})"
            );
            $createdIndexes[] = $index;
        }

        return [
            'createdTables' => $createdTables,
            'existingTables' => $existingTables,
            'createdIndexes' => $createdIndexes,
        ];
    }

    public function inspectSchema(): array
    {
        $connection = Application::getConnection();
        $tables = [];
        foreach (self::TABLES as $dataClass) {
            $table = $dataClass::getTableName();
            $tables[$table] = $connection->isTableExists($table);
        }
        return ['tables' => $tables, 'complete' => !in_array(false, $tables, true)];
    }

    private function hasIndex(string $table, string $index): bool
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $safeIndex = $helper->forSql($index);
        $row = $connection->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$safeIndex}'")->fetch();
        return is_array($row);
    }
}
