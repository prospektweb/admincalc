<?php

namespace Prospektweb\PropValManager\Service;

use Bitrix\Main\Context;

final class PublicJsonConfigExtension
{
    public const EVENT_MODULE = 'main';
    public const EVENT_NAME = 'OnEndBufferContent';

    public static function onEndBufferContent(&$content): void
    {
        if (!is_string($content) || (defined('ADMIN_SECTION') && ADMIN_SECTION === true) || !ModuleConfig::isEnabled()) {
            return;
        }

        if (!self::canInjectIntoResponse($content)) {
            return;
        }

        $path = ModuleConfig::getPropertyDescriptionsJsonPath();
        $version = ModuleConfig::getPropertyDescriptionsJsonVersion();
        if ($path === '' || $version === '') {
            return;
        }

        $config = [
            'jsonUrl' => $path,
            'version' => $version,
        ];
        $assetRoot = '/local/modules/prospektweb.calc/features/property_values/assets';
        if (!is_dir((string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/modules/prospektweb.calc')) {
            $assetRoot = '/bitrix/modules/prospektweb.calc/features/property_values/assets';
        }
        $script = "\n" . '<link rel="stylesheet" href="' . $assetRoot . '/css/property-description-tooltips.css?v=' . rawurlencode($version) . '">' . "\n" .
            '<script data-prospektweb-propvalmanager="property-descriptions-config">' . "\n" .
            'window.ProspektPropValManagerDescriptions = ' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';' . "\n" .
            '</script>' . "\n" .
            '<script defer src="' . $assetRoot . '/js/property-description-tooltips.js?v=' . rawurlencode($version) . '"></script>' . "\n";

        $bodyPos = stripos($content, '</body>');
        if ($bodyPos === false) {
            return;
        }

        $content = substr($content, 0, $bodyPos) . $script . substr($content, $bodyPos);
    }

    private static function canInjectIntoResponse(string $content): bool
    {
        return self::isFullHtmlPage($content)
            && !self::isAjaxRequest()
            && !self::isScriptRequest()
            && self::hasHtmlContentType();
    }

    private static function isFullHtmlPage(string $content): bool
    {
        return stripos($content, '<body') !== false && stripos($content, '</body>') !== false;
    }

    private static function isAjaxRequest(): bool
    {
        $request = Context::getCurrent()->getRequest();
        if ($request->isAjaxRequest()) {
            return true;
        }

        if ((defined('BX_AJAX_REQUEST') && BX_AJAX_REQUEST === true)
            || (defined('PUBLIC_AJAX_MODE') && PUBLIC_AJAX_MODE === true)
        ) {
            return true;
        }

        return (string)$request->get('mode') === 'ajax';
    }

    private static function isScriptRequest(): bool
    {
        $request = Context::getCurrent()->getRequest();
        $fetchDestination = strtolower((string)$request->getServer()->get('HTTP_SEC_FETCH_DEST'));
        if ($fetchDestination === 'script') {
            return true;
        }

        $path = strtolower((string)$request->getRequestedPage());
        return (bool)preg_match('/\.(?:js|mjs|css|json|xml|txt|map)$/', $path);
    }

    private static function hasHtmlContentType(): bool
    {
        $contentType = self::getResponseContentType();
        if ($contentType === '') {
            return true;
        }

        return strpos($contentType, 'text/html') !== false || strpos($contentType, 'application/xhtml+xml') !== false;
    }

    private static function getResponseContentType(): string
    {
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                return strtolower(trim(substr($header, strlen('Content-Type:'))));
            }
        }

        return '';
    }
}
