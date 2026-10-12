<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Install;

/** Unique CODE owns identity; the historical material repair never recreates data. */
final class ResourceDirectoryIdentity
{
    public static function reconcile(string $code, array $rows): ?int
    {
        if (count($rows) > 1) { throw new \RuntimeException('Duplicate resource directory: ' . $code); }
        if (!$rows) { return null; }
        $row = $rows[0];
        if (($row['CODE'] ?? '') !== $code || (int)($row['ID'] ?? 0) <= 0) { throw new \RuntimeException('Resource directory identity conflicts: ' . $code); }
        $id = (int)$row['ID'];
        if (($row['IBLOCK_TYPE_ID'] ?? '') !== 'calculator_catalog') {
            if (!in_array($code, ['CALC_MATERIALS', 'CALC_MATERIALS_VARIANTS'], true)) { throw new \RuntimeException('Resource directory conflicts: ' . $code); }
            if (!(new \CIBlock())->Update($id, ['IBLOCK_TYPE_ID' => 'calculator_catalog'])) { throw new \RuntimeException('Cannot repair material directory type: ' . $code); }
            $after = \CIBlock::GetByID($id)->Fetch();
            if (!$after || (int)$after['ID'] !== $id || $after['CODE'] !== $code || $after['IBLOCK_TYPE_ID'] !== 'calculator_catalog') { throw new \RuntimeException('Material directory repair readback failed: ' . $code); }
        }
        return $id;
    }
}
