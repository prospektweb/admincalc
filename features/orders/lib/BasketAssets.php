<?php

namespace Prospektweb\LayoutFiles;

use Bitrix\Main\Page\Asset;
use Bitrix\Main\Web\Json;

final class BasketAssets
{
    public static function onEpilog(): void
    {
        if (defined('ADMIN_SECTION') && ADMIN_SECTION === true) {
            return;
        }

        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (strpos($path, '/basket/') === false && strpos($path, '/personal/cart/') === false) {
            return;
        }

        $base = '/bitrix/modules/prospektweb.calc/features/orders/assets';
        $config = [
            'ajaxUrl' => '/local/tools/prospekt_layout/ajax.php',
            'maxSize' => Config::getMaxSize(),
            'extensions' => Config::getExtensions(),
            'tooltipText' => Config::getTooltipText(),
            'hiddenPropertyCodes' => Config::getHiddenBasketPropertyCodes(),
            'desiredReceiveTooltipText' => Config::getDesiredReceiveTooltipText(),
        ];

        $asset = Asset::getInstance();
        $asset->addCss($base . '/vendor/air-datepicker/air-datepicker.css');
        $asset->addCss($base . '/css/basket-runtime.css');
        $asset->addString('<script>window.ProspektLayoutFilesConfig=' . Json::encode($config) . ';window.ProspektDesiredReceiveDateConfig={ajaxUrl:"/local/tools/prospekt_layout/desired_receive_date.php"};</script>');
        $asset->addJs($base . '/js/prospekt_layout_files.js');
        $asset->addJs($base . '/vendor/air-datepicker/air-datepicker.js');
        $asset->addJs($base . '/js/prospekt_desired_receive_date.js');
        $asset->addJs($base . '/js/basket-runtime.js');
    }
}
