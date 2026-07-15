<?php

namespace Prospektweb\Frontcalc\Service;

final class ProductAccessValidator
{
    public const ERROR_CODE = 'FRONTCALC_PRODUCT_NOT_AVAILABLE';

    public function validate($productId, int $productsIblockId, string $siteId): bool
    {
        if (!$this->positiveInt($productId) || $productsIblockId <= 0 || $siteId === '') { return false; }
        if (!class_exists('CIBlockElement') || !class_exists('CIBlock')) { return false; }
        $sites = [];
        $siteResult = \CIBlock::GetSite($productsIblockId);
        while ($site = $siteResult->Fetch()) { $sites[] = (string)$site['SITE_ID']; }
        if (!in_array($siteId, $sites, true)) { return false; }
        $filter = [
            'ID' => (int)$productId,
            'IBLOCK_ID' => $productsIblockId,
            'ACTIVE' => 'Y',
            'ACTIVE_DATE' => 'Y',
            'CHECK_PERMISSIONS' => 'Y',
            'MIN_PERMISSION' => 'R',
        ];
        $result = \CIBlockElement::GetList([], $filter, false, ['nTopCount' => 1], ['ID']);
        return is_object($result) && (bool)$result->Fetch();
    }

    private function positiveInt($value): bool
    {
        return !is_bool($value) && preg_match('/^[1-9][0-9]*$/', trim((string)$value)) === 1;
    }
}
