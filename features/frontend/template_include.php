<?php

if (!function_exists('frontcalc_render_runtime_assets')) {
    function frontcalc_render_runtime_assets(): string
    {
        static $isRendered = false;
        if ($isRendered) {
            return '';
        }
        $isRendered = true;

        $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
        $moduleBasePath = '/local/modules/prospektweb.calc/features/frontend';
        if (!is_file($documentRoot . $moduleBasePath . '/assets/js/frontcalc-jqm-popup.js')) {
            $moduleBasePath = '/bitrix/modules/prospektweb.calc/features/frontend';
        }
        $moduleMathScriptPath = $moduleBasePath . '/assets/js/frontcalc-math.js';
        $moduleScriptPath = $moduleBasePath . '/assets/js/frontcalc-jqm-popup.js';
        $moduleStylePath = $moduleBasePath . '/assets/css/frontcalc-jqm-popup.css';
        $withVersion = static function (string $publicPath): string {
            $absolutePath = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . $publicPath;
            return is_file($absolutePath)
                ? $publicPath . '?v=' . (string)filemtime($absolutePath) . '-' . (string)filesize($absolutePath)
                : $publicPath;
        };

        return str_replace(
            ['{{MODULE_MATH_SCRIPT_PATH}}', '{{MODULE_SCRIPT_PATH}}', '{{MODULE_STYLE_PATH}}'],
            [$withVersion($moduleMathScriptPath), $withVersion($moduleScriptPath), $withVersion($moduleStylePath)],
            <<<'HTML'
<link rel="stylesheet" href="{{MODULE_STYLE_PATH}}">
<script src="{{MODULE_MATH_SCRIPT_PATH}}"></script>
<script src="{{MODULE_SCRIPT_PATH}}"></script>
HTML
        );
    }
}

if (!function_exists('frontcalc_get_light_payload')) {
    function frontcalc_get_light_payload(int $productId, int $iblockId, string $ajaxUrl = ''): array
    {
        $serviceClass = '\\Prospektweb\\Frontcalc\\Service\\CalculatorAvailability';

        if (!class_exists($serviceClass)) {
            if (class_exists('\\Bitrix\\Main\\Loader')) {
                \Bitrix\Main\Loader::includeModule('prospektweb.calc');
            } elseif (class_exists('\\CModule')) {
                \CModule::IncludeModule('prospektweb.calc');
            }
        }

        if (!class_exists($serviceClass)) {
            return [
                'is_available' => $productId > 0,
                'product_id' => $productId,
                'ajax_url' => $ajaxUrl !== '' ? $ajaxUrl : '/local/ajax/frontcalc.php',
                'open_popup_chips' => [],
            ];
        }

        $service = new $serviceClass();

        return $service->getLightPayload($productId, $iblockId, $ajaxUrl);
    }
}
