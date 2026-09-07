<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once dirname(__DIR__) . '/Config/ModuleOptions.php';

/** External resource directories only. Resolving one resource never loads the legacy graph. */
final class ResourceCatalogRegistry
{
    public const LABELS = [
        'CALC_MATERIALS' => 'Материалы', 'CALC_MATERIALS_VARIANTS' => 'Варианты материалов',
        'CALC_OPERATIONS' => 'Операции', 'CALC_OPERATIONS_VARIANTS' => 'Варианты операций',
        'CALC_EQUIPMENT' => 'Оборудование', 'CALC_SUPPLIERS' => 'Поставщики материалов',
    ];
    public function getIblockId(string $code): int
    {
        if (!isset(self::LABELS[$code])) { throw new \InvalidArgumentException('Not a resource directory.'); }
        $db = \Bitrix\Main\Application::getConnection();
        $id = (string)(new \Prospektweb\Calc\Config\ModuleOptions($db))->get('IBLOCK_' . $code, '');
        if (!preg_match('/^[1-9][0-9]{0,8}$/D', $id)) { throw new \RuntimeException('Resource directory not configured: ' . $code, 409); }
        $cursor = $db->query("SELECT ID,CODE FROM b_iblock WHERE CODE='" . $code . "' OR ID=" . (int)$id . ' ORDER BY ID LIMIT 3');
        $rows = []; while ($row = $cursor->fetch()) { $rows[] = $row; }
        if (count($rows) !== 1 || $rows[0]['CODE'] !== $code || (string)$rows[0]['ID'] !== $id) { throw new \RuntimeException('Resource directory identity mismatch: ' . $code, 409); }
        return (int)$id;
    }
}
