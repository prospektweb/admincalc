<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Config;

require_once dirname(__DIR__) . '/Services/BitrixTransactionStateAuthority.php';

/** Small module configuration, never calculator documents. No iblock dependency. */
final class ModuleOptions
{
    private const MODULE = 'prospektweb.calc';
    private $db;
    public function __construct($db = null) { $this->db = $db ?? \Bitrix\Main\Application::getConnection(); }

    public function get(string $name, $default = '')
    {
        $row = $this->row($name);
        return $row === null ? $default : (string)$row['VALUE'];
    }

    public function set(string $name, string $value): void
    {
        if (!\Prospektweb\Calc\Services\BitrixTransactionStateAuthority::isActive($this->db)) {
            throw new \LogicException('Module option writes require a transaction.');
        }
        $row = $this->row($name, true);
        $sqlName = $this->quote($row['NAME'] ?? $name);
        if ($row === null) {
            $this->db->queryExecute("INSERT INTO b_option (MODULE_ID,NAME,VALUE,SITE_ID) VALUES ('" . self::MODULE . "',$sqlName," . $this->quote($value) . ',NULL)');
        } elseif (!hash_equals((string)$row['VALUE'], $value)) {
            $this->db->queryExecute("UPDATE b_option SET VALUE=" . $this->quote($value) . " WHERE BINARY MODULE_ID=BINARY '" . self::MODULE . "' AND BINARY NAME=BINARY $sqlName AND SITE_ID IS NULL");
        }
        if ($this->get($name) !== $value) { throw new \RuntimeException('Module option readback mismatch.', 409); }
    }

    /** Serialize this module's settings, revalidate CAS under the lock, then commit. */
    public function mutate(callable $mutation, bool $installation = false)
    {
        if (\Prospektweb\Calc\Services\BitrixTransactionStateAuthority::isActive($this->db)) { throw new \LogicException('Module settings own their transaction.'); }
        foreach (['b_module', 'b_option'] as $table) {
            $status = $this->db->query("SHOW TABLE STATUS WHERE Name='" . $table . "'")->fetch();
            if (($status['Engine'] ?? '') !== 'InnoDB') { throw new \RuntimeException('Transactional module settings require InnoDB.', 409); }
        }
        $this->db->startTransaction();
        try {
            $module = $this->db->query("SELECT ID FROM b_module WHERE ID='" . self::MODULE . "' FOR UPDATE")->fetch();
            if (($module['ID'] ?? '') !== self::MODULE && !$installation) { throw new \RuntimeException('Module settings authority unavailable.', 409); }
            $result = $mutation();
            if (is_array($result) && isset($result['before'], $result['after'])) {
                $audit = json_encode(['action' => 'save_module_settings', 'beforeHash' => hash('sha256', json_encode($result['before'], JSON_THROW_ON_ERROR)), 'afterHash' => hash('sha256', json_encode($result['after'], JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR);
                if (!class_exists('CEventLog') || \CEventLog::Add(['SEVERITY' => 'SECURITY', 'AUDIT_TYPE_ID' => 'PROSPEKTWEB_MODULE_SETTINGS', 'MODULE_ID' => self::MODULE, 'ITEM_ID' => 'settings', 'DESCRIPTION' => $audit]) === false) { throw new \RuntimeException('Module settings audit failed.', 409); }
            }
            $this->db->commitTransaction();
        } catch (\Throwable $error) { $this->db->rollbackTransaction(); $this->clearCache(); throw $error; }
        $this->clearCache();
        return $result;
    }

    private function row(string $name, bool $lock = false): ?array
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,99}$/D', $name)) { throw new \InvalidArgumentException('Invalid option name.'); }
        $cursor = $this->db->query("SELECT MODULE_ID,NAME,VALUE,SITE_ID FROM b_option WHERE MODULE_ID='" . self::MODULE . "' AND LOWER(NAME)=" . $this->quote(strtolower($name)) . ' LIMIT 3' . ($lock ? ' FOR UPDATE' : ''));
        $rows = []; while ($row = $cursor->fetch()) { $rows[] = $row; }
        if ($rows === []) { return null; }
        if (count($rows) !== 1 || $rows[0]['MODULE_ID'] !== self::MODULE || strtolower($rows[0]['NAME']) !== strtolower($name) || $rows[0]['SITE_ID'] !== null) {
            throw new \RuntimeException('Ambiguous module option: ' . $name, 409);
        }
        return $rows[0];
    }
    private function quote(string $value): string { return "'" . $this->db->getSqlHelper()->forSql($value) . "'"; }
    private function clearCache(): void { \Bitrix\Main\Application::getInstance()->getManagedCache()->clean('b_option:' . self::MODULE, 'b_option'); }
}
