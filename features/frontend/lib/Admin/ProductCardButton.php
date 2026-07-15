<?php

namespace Prospektweb\Frontcalc\Admin;

use Bitrix\Main\Config\Option;

class ProductCardButton
{
    public static function onAdminContextMenuShow(&$items)
    {
        if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true) {
            return;
        }

        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $isIblockEdit = mb_strpos($script, '/bitrix/admin/iblock_element_edit.php') !== false;
        $isCatalogEdit = mb_strpos($script, '/bitrix/admin/cat_product_edit.php') !== false;
        if (!$isIblockEdit && !$isCatalogEdit) {
            return;
        }

        $elementId = (int)($_REQUEST['ID'] ?? 0);
        $iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? 0);
        $productsIblockId = (int)Option::get('prospektweb.calc', 'PRODUCTS_IBLOCK_ID', '0');

        if ($elementId > 0 && $iblockId <= 0 && class_exists('\CIBlockElement')) {
            $iblockId = (int)\CIBlockElement::GetIBlockByID($elementId);
        }

        if ($elementId <= 0 || $iblockId <= 0 || $productsIblockId <= 0 || $iblockId !== $productsIblockId) {
            return;
        }

        $url = '/bitrix/admin/prospektweb_frontcalc_editor.php?IBLOCK_ID=' . $iblockId . '&ID=' . $elementId . '&lang=' . urlencode((string)($_REQUEST['lang'] ?? 'ru'));
        $jsOpen = "if (window.BX && BX.SidePanel && BX.SidePanel.Instance) { BX.SidePanel.Instance.open('{$url}', {cacheable:false, width:800}); return false; } window.location.href='{$url}'; return false;";

        $items[] = [
            'TEXT' => 'Настройки калькулятора',
            'TITLE' => 'Настройки калькулятора',
            'LINK' => $url,
            'ONCLICK' => $jsOpen,
            'ICON' => 'btn_new',
        ];
    }
}
