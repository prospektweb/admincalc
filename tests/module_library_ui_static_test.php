<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/admin/modules.php');
$api = file_get_contents($root . '/tools/modules.php');
$installer = file_get_contents($root . '/install/index.php');
$menu = file_get_contents($root . '/lib/Handlers/AdminHandler.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert($page !== false && $api !== false && $installer !== false && $menu !== false, 'module library UI files are readable');
$assert(strpos($page, 'Мастер подключения') !== false, 'UI exposes an explicit binding wizard');
$assert(strpos($page, "data-action=\"preview\"") !== false, 'UI requires preview before apply');
$assert(strpos($page, "data-action=\"apply\"") !== false, 'UI can apply a validated snapshot');
$assert(strpos($page, "data-action=\"rollback\"") !== false, 'UI exposes rollback from snapshot history');
$assert(strpos($page, "data-action=\"edit-instance\"") !== false, 'UI exposes versioned instance updates');
$assert(strpos($page, "data-action=\"migration-analyze\"") !== false, 'UI exposes read-only legacy migration analysis');
$assert(strpos($page, "data-action=\"migration-extract\"") !== false, 'UI requires explicit reviewed extraction');
$assert(strpos($page, "data-action=\"migration-create-draft\"") !== false, 'UI creates a draft only after differential review');
$assert(strpos($page, '${esc(x.VERSION_ID)}') === false, 'UI does not display internal version IDs');
$assert(strpos($page, 'data-role=') !== false, 'UI binds dynamic entity roles by visible names');
$assert(strpos($page, 'crypto.randomUUID()') !== false, 'UI creates local instance identifiers without Bitrix IDs');
$assert(strpos($api, "case 'instance.preview':") !== false, 'API exposes preview');
$assert(strpos($api, "case 'instance.apply':") !== false, 'API exposes atomic apply');
$assert(strpos($api, "case 'instance.rollback':") !== false, 'API exposes rollback');
$assert(strpos($api, "case 'pilot.install':") !== false, 'API exposes idempotent pilot publication');
$assert(strpos($api, "case 'vertical.install':") !== false, 'API exposes vertical fixture publication');
$assert(strpos($api, "case 'migration.analyze':") !== false, 'API exposes read-only legacy analysis');
$assert(strpos($api, "case 'migration.extract':") !== false, 'API exposes reviewed draft preview');
$assert(strpos($api, "case 'migration.compare':") !== false, 'API exposes differential comparison');
$assert(strpos($api, "case 'migration.draft.create':") !== false, 'API creates reviewed migration drafts without publishing');
$assert(strpos($api, 'check_bitrix_sessid()') !== false, 'API enforces CSRF protection');
$assert(strpos($installer, 'prospektweb_calc_modules.php') !== false, 'installer owns the admin page');
$assert(strpos($menu, 'menu_prospektweb_calc_modules') !== false, 'admin menu exposes the library');

echo "Module library UI static checks passed\n";
