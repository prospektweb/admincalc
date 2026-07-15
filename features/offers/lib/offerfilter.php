<?php

namespace Prospektweb\OfferFilter;

class OfferFilter
{
    public static function onBeforeProlog()
    {
        global $APPLICATION;

        if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true) {
            return;
        }

        if (!is_object($APPLICATION) || !method_exists($APPLICATION, 'GetCurPage')) {
            return;
        }

        $page = (string)$APPLICATION->GetCurPage();

        $modulePath = function_exists('getLocalPath') ? getLocalPath('modules/prospektweb.calc') : '';
        $modulePath = $modulePath ?: '/local/modules/prospektweb.calc';
        $offerGeneratorScript = $modulePath . '/features/offers/assets/js/offer_generator_ajax.js';

        // Страница штатного генератора ТП — подключаем только AJAX-кнопку
        if ($page === '/bitrix/tools/catalog/iblock_subelement_generator.php') {
            $APPLICATION->AddHeadScript($offerGeneratorScript);
            return;
        }

        // Страница редактирования товара с таблицей ТП
        $allowedPages = array(
            '/bitrix/admin/iblock_element_edit.php',
            '/bitrix/admin/cat_product_edit.php',
        );

        if (!in_array($page, $allowedPages, true)) {
            return;
        }

        $APPLICATION->SetAdditionalCSS($modulePath . '/features/offers/css/offerfilter.css');
        $APPLICATION->AddHeadScript($modulePath . '/features/offers/js/offerfilter.js');
        $APPLICATION->AddHeadScript($offerGeneratorScript);
    }
}
