<?php
/**
 * Central PROSPEKT-WEB administration workspace.
 *
 * The iframe is intentionally independent from a selected product or offer.
 * Contextual calculator entry points continue to use calculator.php.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Prospektweb\Calc\Config\ConfigManager;

global $USER, $APPLICATION;

if (!$USER || !$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ запрещён');
    exit;
}

if (!Loader::includeModule('prospektweb.calc')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    ShowError('Модуль prospektweb.calc не установлен');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    exit;
}

$APPLICATION->SetTitle('ПРОСПЕКТ — центр управления');

$languageId = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$languageId = preg_replace('/[^a-z]/i', '', $languageId) ?: 'ru';
$configManager = new ConfigManager();
$settingsUrl = '/bitrix/admin/settings.php?' . http_build_query([
    'lang' => $languageId,
    'mid' => 'prospektweb.calc',
    'mid_menu' => 1,
]);
$diagnosticsUrl = '/bitrix/admin/settings.php?' . http_build_query([
    'lang' => $languageId,
    'mid' => 'prospektweb.calc',
    'mid_menu' => 1,
    'tabControl_active_tab' => 'edit5',
]);
$moduleVersion = trim((string)ModuleManager::getVersion('prospektweb.calc')) ?: 'unknown';
$controlCenterInstanceId = bin2hex(random_bytes(16));
$controlCenterEndpoints = [
    'settings' => '/bitrix/tools/prospektweb.calc/control_center_settings.php',
    'diagnostics' => '/bitrix/tools/prospektweb.calc/diagnostic.php',
    'batch' => '/bitrix/tools/prospektweb.calc/batch_recalculate.php',
    'modules' => '/bitrix/tools/prospektweb.calc/control_center_modules.php',
    'editors' => '/bitrix/tools/prospektweb.calc/control_center_editors.php',
    'partners' => '/bitrix/tools/prospektweb/partnermanager/control_center.php',
];
$controlCenterCapabilities = [
    'settings' => true,
    'diagnostics' => true,
    'batch' => true,
    'modules' => true,
    'editors' => true,
    'partners' => ModuleManager::isModuleInstalled('prospektweb.partnermanager'),
];

$resolveIblockType = static function (int $iblockId, string $fallback): string {
    if ($iblockId <= 0 || !Loader::includeModule('iblock')) {
        return $fallback;
    }

    $iblock = \CIBlock::GetByID($iblockId)->Fetch();
    $type = trim((string)($iblock['IBLOCK_TYPE_ID'] ?? ''));

    return $type !== '' ? $type : $fallback;
};

$buildIblockListUrl = static function (int $iblockId, string $fallbackType) use (
    $languageId,
    $settingsUrl,
    $resolveIblockType
): string {
    if ($iblockId <= 0) {
        return $settingsUrl;
    }

    return '/bitrix/admin/iblock_list_admin.php?' . http_build_query([
        'IBLOCK_ID' => $iblockId,
        'type' => $resolveIblockType($iblockId, $fallbackType),
        'lang' => $languageId,
        'find_section_section' => 0,
    ]);
};

$presetsUrl = $buildIblockListUrl($configManager->getIblockId('CALC_PRESETS'), 'calculator');
$productsUrl = $buildIblockListUrl($configManager->getProductIblockId(), 'catalog');

// This is the complete navigation authority for the control-center iframe.
// The iframe sends a route key only; it never supplies a URL.
$routeMap = [
    'presets' => $presetsUrl,
    'products' => $productsUrl,
    'storefront-calculators' => $productsUrl,
    'batch-recalculation' => '/bitrix/admin/prospektweb_calc_recalculate.php?' . http_build_query([
        'lang' => $languageId,
    ]),
    'directories' => '/bitrix/admin/iblock_admin.php?' . http_build_query([
        'type' => 'calculator_catalog',
        'lang' => $languageId,
        'admin' => 'Y',
    ]),
    'diagnostics' => $diagnosticsUrl,
    'settings' => $settingsUrl,
];

$allowedAdminPaths = [
    '/bitrix/admin/iblock_admin.php',
    '/bitrix/admin/iblock_list_admin.php',
    '/bitrix/admin/prospektweb_calc_recalculate.php',
    '/bitrix/admin/settings.php',
];

$appIndexPath = $_SERVER['DOCUMENT_ROOT'] . '/local/apps/prospektweb.calc/index.html';
$appVersion = is_file($appIndexPath) ? (string)filemtime($appIndexPath) : '1';
$requestedEmbeddedRoute = trim((string)($_GET['pwRoute'] ?? ''));
$allowedEmbeddedRoutes = [
    'presets',
    'partners',
    'storefront-calculators',
    'settings',
    'diagnostics',
    'batch-recalculation',
    'capabilities',
];
$embeddedRoute = in_array($requestedEmbeddedRoute, $allowedEmbeddedRoutes, true)
    ? $requestedEmbeddedRoute
    : '';
$iframeUrl = '/local/apps/prospektweb.calc/index.html?' . http_build_query([
    'mode' => 'control-center',
    'v' => $appVersion,
    'pwProductId' => max(0, (int)($_GET['pwProductId'] ?? 0)),
]);
if ($embeddedRoute !== '') {
    $iframeUrl .= '#/' . rawurlencode($embeddedRoute);
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>

<style>
html,
body {
    width: 100% !important;
    height: 100% !important;
    margin: 0 !important;
    overflow: hidden !important;
}

.adm-workarea {
    padding: 0 !important;
}

#adm-title,
.adm-title {
    display: none !important;
}

#prospektweb-control-center {
    position: fixed;
    inset: 0;
    z-index: 100000;
    width: 100vw;
    height: 100vh;
    height: 100dvh;
    min-height: 0;
    background: #0b121a;
    isolation: isolate;
}

#prospektweb-control-center-iframe {
    display: block;
    width: 100%;
    height: 100%;
    border: 0;
}

#prospektweb-control-center-editor[hidden] {
    display: none !important;
}

#prospektweb-control-center-editor {
    position: absolute;
    inset: 0;
    z-index: 50;
    display: block;
    min-width: 0;
    min-height: 0;
    background: #0b121a;
}

#prospektweb-control-center-editor-iframe {
    display: block;
    width: 100%;
    height: 100%;
    min-width: 0;
    min-height: 0;
    border: 0;
    background: #ffffff;
}
</style>

<div id="prospektweb-control-center">
    <iframe
        id="prospektweb-control-center-iframe"
        src="<?= htmlspecialcharsbx($iframeUrl) ?>"
        title="ПРОСПЕКТ — центр управления"
        referrerpolicy="same-origin"
    ></iframe>
    <section id="prospektweb-control-center-editor" aria-hidden="true" hidden>
        <iframe
            id="prospektweb-control-center-editor-iframe"
            src="about:blank"
            title="Редактор ПРОСПЕКТ"
            referrerpolicy="same-origin"
        ></iframe>
    </section>
</div>

<script>
(function () {
    'use strict';

    var container = document.getElementById('prospektweb-control-center');
    var iframe = document.getElementById('prospektweb-control-center-iframe');
    var editorOverlay = document.getElementById('prospektweb-control-center-editor');
    var editorIframe = document.getElementById('prospektweb-control-center-editor-iframe');
    var routeMap = <?= json_encode($routeMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var allowedAdminPaths = <?= json_encode($allowedAdminPaths, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var controlCenterInstanceId = <?= json_encode($controlCenterInstanceId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var controlCenterInitPayload = <?= json_encode([
        'sessid' => bitrix_sessid(),
        'endpoints' => $controlCenterEndpoints,
        'moduleVersion' => $moduleVersion,
        'capabilities' => $controlCenterCapabilities,
        'controlCenterInstanceId' => $controlCenterInstanceId,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var activeEditor = null;
    var launchPending = false;
    var calculatorWorkspaceHashPattern = /^#\/presets(?:\/[1-9]\d*\/(?:overview|form|logic|storefront|usage|products|versions))?(?:\?.*)?$/;

    function normalizeCalculatorWorkspaceHash(hash) {
        if (typeof hash !== 'string' || hash.length === 0 || hash.length > 1200
            || !calculatorWorkspaceHashPattern.test(hash)) {
            return '';
        }

        var queryStart = hash.indexOf('?');
        var params = new URLSearchParams(queryStart >= 0 ? hash.slice(queryStart + 1) : '');
        var allowedKeys = ['q', 'status', 'sort', 'field', 'version'];
        var valid = true;
        params.forEach(function (value, key) {
            if (allowedKeys.indexOf(key) === -1 || value.length > (key === 'q' ? 160 : 128)) {
                valid = false;
            }
        });
        if (params.getAll('status').length > 1
            || (params.has('status') && ['all', 'active', 'archived'].indexOf(params.get('status')) === -1)
            || params.getAll('sort').length > 1
            || (params.has('sort') && ['updated_desc', 'name_asc', 'name_desc', 'id_desc'].indexOf(params.get('sort')) === -1)
            || params.getAll('q').length > 1
            || params.getAll('field').length > 1
            || params.getAll('version').length > 1
            || (params.has('version') && !/^v_[a-f0-9]{16,40}$/.test(params.get('version')))) {
            valid = false;
        }

        return valid ? hash : '';
    }

    function syncCalculatorWorkspaceFromHost() {
        if (!iframe || !iframe.contentWindow) {
            return;
        }
        var hash = window.location.hash;
        if (hash !== '' && normalizeCalculatorWorkspaceHash(hash) === '') {
            return;
        }
        var childLocation = iframe.contentWindow.location;
        childLocation.replace(childLocation.pathname + childLocation.search + hash);
    }

    var initialCalculatorWorkspaceHash = normalizeCalculatorWorkspaceHash(window.location.hash);
    if (initialCalculatorWorkspaceHash !== '' && iframe) {
        var initialIframeUrl = new URL(iframe.src, window.location.origin);
        initialIframeUrl.hash = initialCalculatorWorkspaceHash;
        iframe.src = initialIframeUrl.pathname + initialIframeUrl.search + initialIframeUrl.hash;
    }

    function createEditorInstanceId() {
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.prototype.map.call(bytes, function (byte) {
            return byte.toString(16).padStart(2, '0');
        }).join('');
    }

    function sendToControlCenter(type, payload) {
        if (!iframe || !iframe.contentWindow) {
            return;
        }

        iframe.contentWindow.postMessage({
            protocol: 'pwrt-v1',
            source: 'bitrix',
            target: 'prospektweb.calc',
            type: type,
            payload: Object.assign({controlCenterInstanceId: controlCenterInstanceId}, payload || {}),
            timestamp: Date.now()
        }, window.location.origin);
    }

    function closeOwnedEditor(reason) {
        if (!activeEditor || !editorOverlay || !editorIframe) {
            return;
        }

        var closedEditor = activeEditor;
        activeEditor = null;
        editorOverlay.hidden = true;
        editorOverlay.setAttribute('aria-hidden', 'true');
        editorIframe.src = 'about:blank';

        sendToControlCenter('EDITOR_CLOSED', {
            editorType: closedEditor.type,
            reason: reason || 'closed'
        });
    }

    function openOwnedEditor(editorType, targetUrl) {
        if (!editorOverlay || !editorIframe || activeEditor) {
            throw new Error('Другой редактор уже открыт');
        }
        if (!(targetUrl instanceof URL)
            || targetUrl.origin !== window.location.origin
            || targetUrl.pathname !== '/bitrix/admin/prospektweb_calc_calculator.php') {
            throw new Error('Недопустимый адрес редактора');
        }

        var editorInstanceId = createEditorInstanceId();
        targetUrl.searchParams.set('editor_instance_id', editorInstanceId);
        targetUrl.searchParams.set('_cc_nonce', editorInstanceId);
        activeEditor = {id: editorInstanceId, type: editorType};
        editorIframe.src = targetUrl.pathname + targetUrl.search;
        editorOverlay.hidden = false;
        editorOverlay.setAttribute('aria-hidden', 'false');
    }

    function postEditorAction(action, payload) {
        var endpoint = controlCenterInitPayload.endpoints.editors;
        var form = new URLSearchParams();
        form.set('sessid', controlCenterInitPayload.sessid);
        form.set('payload', JSON.stringify(Object.assign({action: action}, payload || {})));

        return window.fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: form.toString(),
            cache: 'no-store'
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error('Сервер вернул некорректный ответ');
            }).then(function (result) {
                if (!response.ok || !result || result.success !== true || !result.data) {
                    throw new Error(result && result.error ? result.error : 'Не удалось открыть редактор');
                }
                return result.data;
            });
        });
    }

    function reportEditorError(error) {
        var message = error && error.message ? error.message : 'Не удалось открыть редактор';
        window.alert(message);
    }

    function hasExactPayloadKeys(payload, expectedKeys) {
        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
            return false;
        }
        var keys = Object.keys(payload).sort();
        var expected = expectedKeys.slice().sort();
        return keys.length === expected.length && keys.every(function (key, index) {
            return key === expected[index];
        });
    }

    function launchCalculationEditor(payload) {
        if (launchPending || activeEditor) {
            return;
        }
        var standaloneLaunch = hasExactPayloadKeys(payload, ['controlCenterInstanceId', 'presetId']);
        var catalogLaunch = hasExactPayloadKeys(payload, ['controlCenterInstanceId', 'offerIds', 'presetId']);
        var versionLaunch = hasExactPayloadKeys(payload, ['controlCenterInstanceId', 'mode', 'presetId', 'returnRoute', 'versionId'])
            || (payload.openCalculationPanel === true
                && hasExactPayloadKeys(payload, ['controlCenterInstanceId', 'mode', 'openCalculationPanel', 'presetId', 'returnRoute', 'versionId']));
        if (!standaloneLaunch && !catalogLaunch && !versionLaunch) {
            return;
        }
        var presetId = Number.isSafeInteger(payload.presetId) ? payload.presetId : 0;
        var versionId = versionLaunch && typeof payload.versionId === 'string' ? payload.versionId : '';
        var editorMode = versionLaunch && (payload.mode === 'edit' || payload.mode === 'readonly') ? payload.mode : '';
        var returnRoute = versionLaunch && typeof payload.returnRoute === 'string' ? payload.returnRoute : '';
        var openCalculationPanel = versionLaunch && payload.openCalculationPanel === true;
        var offerIds = catalogLaunch && Array.isArray(payload.offerIds)
            ? payload.offerIds.slice()
            : [];
        if (presetId <= 0
            || (versionLaunch && (!/^v_[a-f0-9]{16,40}$/.test(versionId)
                || editorMode === ''
                || !/^#\/presets\/\d+\/versions(?:\?version=v_[a-f0-9]{16,40})?$/.test(returnRoute)))
            || (catalogLaunch && (offerIds.length === 0
                || offerIds.length > 500
                || offerIds.some(function (offerId) {
                    return !Number.isSafeInteger(offerId) || offerId <= 0;
                })
                || (new Set(offerIds)).size !== offerIds.length))) {
            return;
        }

        launchPending = true;
        var validation = versionLaunch
            ? postEditorAction('version_logic_launch', {
                presetId: presetId,
                versionId: versionId,
                mode: editorMode
            })
            : catalogLaunch
            ? postEditorAction('validate_calculation_launch', {
                presetId: presetId,
                offerIds: offerIds
            })
            : postEditorAction('validate_preset_launch', {
                presetId: presetId
            });
        validation.then(function (data) {
            var focusPresetId = Number(data.focusPresetId || 0);
            if (!Number.isSafeInteger(focusPresetId)
                || focusPresetId <= 0
                || (versionLaunch ? data.presetId !== presetId : focusPresetId !== presetId)
                || typeof data.presetName !== 'string'
                || data.presetName === '') {
                throw new Error('Сервер вернул некорректный пресет');
            }
            if (catalogLaunch
                && (!Array.isArray(data.offerIds)
                    || data.offerIds.length === 0
                    || data.offerIds.length > 500
                    || !Array.isArray(data.productIds)
                    || data.productIds.length === 0)) {
                throw new Error('Сервер вернул некорректную каталоговую выборку');
            }
            var targetUrl = new URL('/bitrix/admin/prospektweb_calc_calculator.php', window.location.origin);
            targetUrl.searchParams.set('preset_id', String(focusPresetId));
            if (catalogLaunch) {
                targetUrl.searchParams.set('offer_ids', data.offerIds.join(','));
            }
            targetUrl.searchParams.set('control_center', 'Y');
            if (versionLaunch) {
                targetUrl.searchParams.set('original_preset_id', String(presetId));
                targetUrl.searchParams.set('version_id', versionId);
                targetUrl.searchParams.set('version_mode', editorMode);
                targetUrl.searchParams.set('version_content_hash', data.contentHash);
                targetUrl.searchParams.set('version_logic_hash', data.logicHash);
                targetUrl.searchParams.set('return_route', returnRoute);
                if (openCalculationPanel) {
                    targetUrl.searchParams.set('open_calculation_panel', 'Y');
                }
            }
            targetUrl.searchParams.set('lang', <?= json_encode($languageId) ?>);
            targetUrl.searchParams.set('IFRAME', 'Y');
            targetUrl.searchParams.set('IFRAME_TYPE', 'SIDE_SLIDER');
            openOwnedEditor('calculation', targetUrl);
        }).catch(reportEditorError).finally(function () {
            launchPending = false;
        });
    }

    function resizeControlCenter() {
        if (!container) {
            return;
        }

        var rect = container.getBoundingClientRect();
        var availableHeight = Math.max(1, window.innerHeight - Math.max(0, rect.top));
        container.style.height = availableHeight + 'px';
    }

    resizeControlCenter();
    window.addEventListener('resize', resizeControlCenter);
    window.addEventListener('popstate', syncCalculatorWorkspaceFromHost);
    window.addEventListener('hashchange', syncCalculatorWorkspaceFromHost);

    window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin) {
            return;
        }

        var message = event.data;
        if (!message || typeof message !== 'object' || Array.isArray(message)
            || message.protocol !== 'pwrt-v1'
            || message.source !== 'prospektweb.calc'
            || message.target !== 'bitrix') {
            return;
        }

        if (editorIframe && event.source === editorIframe.contentWindow) {
            if (message.type === 'CLOSE_CONTROL_CENTER_EDITOR'
                && activeEditor
                && hasExactPayloadKeys(message.payload, ['editorInstanceId'])
                && message.payload.editorInstanceId === activeEditor.id) {
                closeOwnedEditor('editor-close');
            }
            return;
        }

        if (!iframe || event.source !== iframe.contentWindow) {
            return;
        }

        if (message.type === 'READY') {
            if (!message.payload || message.payload.mode !== 'control-center') {
                return;
            }

            iframe.contentWindow.postMessage({
                protocol: 'pwrt-v1',
                source: 'bitrix',
                target: 'prospektweb.calc',
                type: 'CONTROL_CENTER_INIT',
                payload: controlCenterInitPayload,
                timestamp: Date.now()
            }, window.location.origin);
            return;
        }

        if (message.type === 'OPEN_CALC_EDITOR') {
            if (!message.payload || message.payload.controlCenterInstanceId !== controlCenterInstanceId) {
                return;
            }
            launchCalculationEditor(message.payload);
            return;
        }

        if (message.type === 'CONTROL_CENTER_ROUTE_CHANGED') {
            if (!hasExactPayloadKeys(message.payload, ['hash', 'replace'])
                || typeof message.payload.replace !== 'boolean') {
                return;
            }
            var workspaceHash = normalizeCalculatorWorkspaceHash(message.payload.hash);
            if (workspaceHash === '') {
                return;
            }
            var workspaceUrl = new URL(window.location.href);
            workspaceUrl.hash = workspaceHash;
            window.history[message.payload.replace ? 'replaceState' : 'pushState'](
                {calculatorWorkspaceHash: workspaceHash},
                '',
                workspaceUrl
            );
            return;
        }

        if (message.type !== 'OPEN_ADMIN_URL') {
            return;
        }

        var route = message.payload && typeof message.payload.route === 'string'
            ? message.payload.route
            : '';
        if (!Object.prototype.hasOwnProperty.call(routeMap, route)) {
            return;
        }

        var targetUrl;
        try {
            targetUrl = new URL(routeMap[route], window.location.origin);
        } catch (error) {
            return;
        }

        if (targetUrl.origin !== window.location.origin
            || allowedAdminPaths.indexOf(targetUrl.pathname) === -1) {
            return;
        }

        window.location.assign(targetUrl.pathname + targetUrl.search);
    });
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
