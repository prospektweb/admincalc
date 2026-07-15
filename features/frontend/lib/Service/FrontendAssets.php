<?php

namespace Prospektweb\Frontcalc\Service;

use Bitrix\Main\Page\Asset;

class FrontendAssets
{
    public static function onEpilog(): void
    {
        if (defined('ADMIN_SECTION') && ADMIN_SECTION === true) {
            return;
        }

        $asset = Asset::getInstance();
        $modulePath = function_exists('getLocalPath') ? getLocalPath('modules/prospektweb.calc') : '';
        $modulePath = $modulePath ?: '/local/modules/prospektweb.calc';
        $asset->addCss($modulePath . '/features/frontend/assets/css/prices-popup-ext.css');
    }
}
