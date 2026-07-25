<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Modules;

final class ModuleAccess
{
    private const RANK = ['D' => 0, 'R' => 1, 'W' => 2, 'P' => 3];

    private const REQUIRED = [
        'view' => 'R',
        'draft.create' => 'W',
        'draft.edit' => 'W',
        'instance.bind' => 'W',
        'version.publish' => 'P',
        'version.deprecate' => 'P',
        'version.archive' => 'P',
        'migration.apply' => 'P',
        'snapshot.rollback' => 'P',
    ];

    public static function canByRights(
        string $operation,
        bool $canEditCatalog,
        string $moduleRight,
        bool $isAdmin
    ): bool {
        if ($isAdmin) {
            return true;
        }
        $required = self::REQUIRED[$operation] ?? null;
        if ($required === null) {
            return false;
        }
        if ($operation !== 'view' && !$canEditCatalog) {
            return false;
        }
        return (self::RANK[$moduleRight] ?? 0) >= self::RANK[$required];
    }

    public static function assertCurrentUser(string $operation): void
    {
        global $APPLICATION, $USER;
        $isAdmin = is_object($USER) && method_exists($USER, 'IsAdmin') && $USER->IsAdmin();
        $canEditCatalog = is_object($USER)
            && method_exists($USER, 'CanDoOperation')
            && $USER->CanDoOperation('edit_catalog');
        $moduleRight = is_object($APPLICATION) && method_exists($APPLICATION, 'GetGroupRight')
            ? (string)$APPLICATION->GetGroupRight('prospektweb.calc')
            : 'D';
        if (!self::canByRights($operation, $canEditCatalog, $moduleRight, $isAdmin)) {
            throw new \RuntimeException("Access denied for module operation: {$operation}");
        }
    }
}
