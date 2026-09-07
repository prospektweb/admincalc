<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Install;
require_once __DIR__ . '/ResourceDirectoryInstaller.php';
require_once dirname(__DIR__) . '/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/Documents/BitrixConnection.php';
require_once dirname(__DIR__) . '/Config/ModuleOptions.php';

/** Explicit empty installation; never invoked on a page read or module include. */
final class NativeInstallation
{
    public function installSchema(): array
    {
        $db = \Bitrix\Main\Application::getConnection();
        if (\Prospektweb\Calc\Services\BitrixTransactionStateAuthority::isActive($db)) { throw new \LogicException('Schema installation cannot join a runtime transaction.'); }
        $lock = $db->query("SELECT GET_LOCK('prospektweb.calc:native-install',5) AS ACQUIRED")->fetch();
        if ((int)($lock['ACQUIRED'] ?? 0) !== 1) { throw new \RuntimeException('Another native installation is running.', 409); }
        try { return $this->installLocked($db); }
        finally { $db->query("SELECT RELEASE_LOCK('prospektweb.calc:native-install')"); }
    }

    private function installLocked($db): array
    {
        \Prospektweb\Calc\Documents\DocumentSchema::install(new \Prospektweb\Calc\Documents\BitrixConnection($db));
        $ids = (new ResourceDirectoryInstaller())->install();
        $options = new \Prospektweb\Calc\Config\ModuleOptions($db);
        $options->mutate(static function () use ($options, $ids): void {
            foreach ($ids as $code => $id) {
                $before = $options->get('IBLOCK_' . $code, null);
                if ($before !== null && $before !== (string)$id) { throw new \RuntimeException('Resource option conflicts: ' . $code, 409); }
                $options->set('IBLOCK_' . $code, (string)$id);
            }
            // Existing installations are never silently activated by running the installer.
            foreach (['DOCUMENT_PUBLIC_RUNTIME' => 'Y', 'document_editor_enabled' => 'Y',
                'document_site_id' => (string)\CSite::GetDefSite(),
                'document_resource_provider' => 'bitrix:' . bin2hex(random_bytes(16)),
                'CALC_SERVER_URL' => ''] as $name => $value) {
                if ($options->get($name, null) === null) { $options->set($name, $value); }
            }
        }, true);
        return $ids;
    }
}
