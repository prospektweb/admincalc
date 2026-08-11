<?php

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', false);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Prospektweb\Calc\Install\CatalogCalcPropertyMigrationService;

header('Cache-Control: no-store, private');

$respond = static function (array $payload, int $status = 200): void {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

global $USER;
$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));

if ($requestMethod === 'GET') {
    if (!$USER || !$USER->IsAdmin()) {
        $respond(['status' => 'error', 'error' => 'access_denied'], 403);
    }
    if (!Loader::includeModule('prospektweb.calc')) {
        $respond(['status' => 'error', 'error' => 'module_not_loaded'], 503);
    }

    header('Content-Type: text/html; charset=UTF-8');
    $presetId = CatalogCalcPropertyMigrationService::DEFAULT_PRESET_ID;
    $actions = [
        'audit' => '1. Аудит',
        'snapshot' => '2. Снимок',
        'materialize_base_offers' => '3. Создать базовые ТП',
        'execute' => '4. Перенести свойства',
        'verify' => '5. Проверить',
        'cutover' => '6. Отключить свойства товаров',
    ];
    $semanticActions = [
        'apply_semantic_fixes' => 'Применить смысловые исправления',
        'rollback_semantic_fixes' => 'Откатить смысловые исправления',
    ];
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Миграция CALC-свойств</title>
        <style>
            body { margin: 0; padding: 24px; background: #f5f7f9; color: #1f2d3d; font: 14px/1.45 Arial, sans-serif; }
            main { max-width: 920px; margin: 0 auto; padding: 24px; background: #fff; border: 1px solid #dce3e8; border-radius: 8px; }
            h1 { margin: 0 0 8px; font-size: 24px; }
            h2 { margin: 28px 0 12px; font-size: 18px; }
            p { margin: 8px 0 18px; }
            .warning { padding: 12px; border-left: 4px solid #f0ad4e; background: #fff8e5; }
            .shared { display: grid; grid-template-columns: 180px 1fr; gap: 12px; margin: 20px 0; }
            label { display: grid; gap: 5px; font-weight: 600; }
            input[type="number"], input[type="text"] { box-sizing: border-box; width: 100%; padding: 9px 10px; border: 1px solid #aab7c2; border-radius: 4px; }
            .actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; }
            form { margin: 0; }
            button { width: 100%; min-height: 42px; padding: 9px 12px; border: 1px solid #7d8d9b; border-radius: 4px; background: #eef2f5; cursor: pointer; font-weight: 600; }
            button:hover { background: #e1e8ed; }
            .danger button { border-color: #b94a48; color: #8b2624; background: #fff5f5; }
            .semantic { padding-top: 16px; border-top: 1px solid #dce3e8; }
            .checkbox { display: flex; align-items: center; gap: 8px; margin: 0 0 16px; font-weight: 400; }
            .confirmation { margin-top: 20px; padding: 12px; border: 1px solid #e0b4b4; border-radius: 4px; background: #fff8f8; }
            .legacy-breakage { padding: 12px; border: 1px solid #d88989; border-radius: 4px; background: #fff0f0; color: #7d2020; }
            .rollback { margin-top: 18px; padding-top: 16px; border-top: 1px dashed #d9a0a0; }
            .hint { color: #566673; font-size: 13px; }
        </style>
    </head>
    <body>
    <main>
        <h1>Миграция CALC-свойств в торговые предложения</h1>
        <p class="warning">Действия не запускаются автоматически. Выполняйте этапы по порядку и проверяйте JSON-ответ после каждого POST. Для возврата к панели используйте кнопку браузера «Назад».</p>

        <form method="post" action="">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="migrationUiSubmission" value="1">
            <input id="action-value" type="hidden" name="action" value="">
            <input id="confirm-action" type="hidden" name="confirmAction" value="">
            <input id="semantic-value" type="hidden" name="applySemanticFixes" value="false">
            <input id="legacy-breakage-value" type="hidden" name="allowLegacyPresetBreakage" value="false">
            <div class="shared">
                <label>Preset ID
                    <input type="number" name="presetId" value="<?= (int)$presetId ?>" min="1" required>
                </label>
                <label>Expected fingerprint
                    <input id="expected-fingerprint" type="text" name="expectedFingerprint" value="" autocomplete="off" spellcheck="false" placeholder="Вставьте fingerprint из предыдущего результата">
                </label>
            </div>
            <label class="checkbox">
                <input id="semantic-fixes" type="checkbox" value="true">
                Учитывать смысловые исправления только в audit / verify / cutover
            </label>
            <p class="hint">Snapshot, создание базовых ТП и перенос свойств всегда выполняются без смысловых исправлений.</p>
            <label class="checkbox legacy-breakage">
                <input id="allow-legacy-preset-breakage" type="checkbox" value="true">
                Я явно принимаю, что устаревшие пресеты, кроме #12740, перестанут работать после отключения свойств товаров
            </label>
            <p class="hint">По умолчанию такие потребители блокируют миграцию. Выбор включается в audit fingerprint и должен оставаться одинаковым на всех этапах.</p>

            <h2>Основные этапы</h2>
            <div class="actions">
                <?php foreach ($actions as $action => $label): ?>
                    <button type="submit" name="action" value="<?= htmlspecialcharsbx($action) ?>"><?= htmlspecialcharsbx($label) ?></button>
                <?php endforeach; ?>
            </div>

            <div class="semantic">
                <h2>Опциональные смысловые действия</h2>
                <div class="actions">
                    <button type="submit" name="action" value="apply_semantic_fixes"><?= htmlspecialcharsbx($semanticActions['apply_semantic_fixes']) ?></button>
                </div>
                <div class="rollback danger">
                    <strong>Откат смысловых исправлений</strong>
                    <p class="hint">Используйте только для возврата ранее применённых смысловых изменений.</p>
                    <button type="submit" name="action" value="rollback_semantic_fixes"><?= htmlspecialcharsbx($semanticActions['rollback_semantic_fixes']) ?></button>
                </div>
            </div>

            <label class="checkbox confirmation">
                <input id="confirm-mutation" type="checkbox">
                Я подтверждаю выбранное изменяющее действие
            </label>
            <pre id="migration-result" hidden aria-live="polite"></pre>
        </form>
    </main>
    <script>
    (function () {
        var form = document.querySelector('main form');
        var semanticActions = ['audit', 'verify', 'cutover'];
        var mutatingActions = [
            'materialize_base_offers',
            'execute',
            'cutover',
            'apply_semantic_fixes',
            'rollback_semantic_fixes'
        ];
        var result = document.getElementById('migration-result');
        var submitButtons = form.querySelectorAll('button[type="submit"]');
        form.addEventListener('submit', async function (event) {
            var action = event.submitter ? event.submitter.value : '';
            var isMutation = mutatingActions.indexOf(action) !== -1;
            document.getElementById('action-value').value = action;
            document.getElementById('semantic-value').value =
                document.getElementById('semantic-fixes').checked && semanticActions.indexOf(action) !== -1
                    ? 'true'
                    : 'false';
            document.getElementById('legacy-breakage-value').value =
                document.getElementById('allow-legacy-preset-breakage').checked ? 'true' : 'false';
            document.getElementById('confirm-action').value = '';
            if (isMutation) {
                if (!document.getElementById('confirm-mutation').checked) {
                    event.preventDefault();
                    window.alert('Подтвердите изменяющее действие перед отправкой.');
                    return;
                }
                document.getElementById('confirm-action').value = action;
            }
            event.preventDefault();
            result.hidden = false;
            result.textContent = 'Выполняется ' + action + '…';
            submitButtons.forEach(function (button) {
                button.disabled = true;
            });
            try {
                var response = await fetch(form.action || window.location.href, {
                    method: 'POST',
                    body: new URLSearchParams(new FormData(form)),
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                });
                var responseText = await response.text();
                result.textContent = responseText;
                result.dataset.httpStatus = String(response.status);
            } catch (error) {
                result.textContent = JSON.stringify({
                    status: 'error',
                    error: 'request_failed',
                    message: error instanceof Error ? error.message : String(error)
                });
                result.dataset.httpStatus = '0';
            } finally {
                submitButtons.forEach(function (button) {
                    button.disabled = false;
                });
                document.getElementById('confirm-mutation').checked = false;
            }
        });
    }());
    </script>
    </body>
    </html>
    <?php
    exit;
}

if ($requestMethod !== 'POST') {
    $respond(['status' => 'error', 'error' => 'method_not_allowed'], 405);
}

$request = $_POST;
if ($request === []) {
    $rawBody = file_get_contents('php://input');
    $decoded = json_decode((string)$rawBody, true);
    if (is_array($decoded)) {
        $request = $decoded;
    }
}
if (isset($request['sessid']) && is_scalar($request['sessid'])) {
    // check_bitrix_sessid() reads the named request variable.  Copy only this
    // one non-secret token from a JSON body; it is never returned or logged.
    $_REQUEST['sessid'] = (string)$request['sessid'];
}

if (!$USER || !$USER->IsAdmin() || !check_bitrix_sessid()) {
    $respond(['status' => 'error', 'error' => 'access_denied'], 403);
}
if (!Loader::includeModule('prospektweb.calc')) {
    $respond(['status' => 'error', 'error' => 'module_not_loaded'], 503);
}

$action = strtolower(trim((string)($request['action'] ?? '')));
$isMigrationUiSubmission = (string)($request['migrationUiSubmission'] ?? '') === '1';
$mutatingUiActions = [
    'materialize_base_offers',
    'execute',
    'cutover',
    'apply_semantic_fixes',
    'rollback_semantic_fixes',
    'rollback_base_offers',
    'rollback_catalog_display',
];
if (
    $isMigrationUiSubmission
    && in_array($action, $mutatingUiActions, true)
    && !hash_equals($action, trim((string)($request['confirmAction'] ?? '')))
) {
    $respond(['status' => 'error', 'error' => 'confirmation_required'], 400);
}
$presetId = isset($request['presetId'])
    ? (int)$request['presetId']
    : CatalogCalcPropertyMigrationService::DEFAULT_PRESET_ID;
$semanticFixValue = $request['applySemanticFixes'] ?? false;
if ($isMigrationUiSubmission && !in_array($action, ['audit', 'verify', 'cutover'], true)) {
    $semanticFixValue = false;
}
$semanticFixes = filter_var(
    $semanticFixValue,
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE
);
if ($semanticFixes === null) {
    $respond(['status' => 'error', 'error' => 'invalid_semantic_fix_flag'], 400);
}
$legacyBreakageValue = $request['allowLegacyPresetBreakage'] ?? false;
if (is_bool($legacyBreakageValue)) {
    $allowLegacyPresetBreakage = $legacyBreakageValue;
} elseif (is_string($legacyBreakageValue)
    && in_array($legacyBreakageValue, ['true', 'false'], true)) {
    $allowLegacyPresetBreakage = $legacyBreakageValue === 'true';
} else {
    $respond(['status' => 'error', 'error' => 'invalid_legacy_preset_breakage_flag'], 400);
}

try {
    $service = new CatalogCalcPropertyMigrationService();
    switch ($action) {
        case 'audit':
            $result = $service->audit($presetId, $semanticFixes, $allowLegacyPresetBreakage);
            break;
        case 'snapshot':
            $result = $service->snapshot($presetId, $semanticFixes, $allowLegacyPresetBreakage);
            break;
        case 'audit_catalog_display':
            $result = $service->auditCatalogDisplay($presetId);
            break;
        case 'rollback_catalog_display':
            $result = $service->rollbackCatalogDisplay(
                $presetId,
                trim((string)($request['expectedPatchedSha256'] ?? ''))
            );
            break;
        case 'execute':
            $result = $service->execute(
                $presetId,
                trim((string)($request['expectedFingerprint'] ?? '')),
                $semanticFixes,
                $allowLegacyPresetBreakage
            );
            break;
        case 'apply_semantic_fixes':
            $result = $service->applySemanticFixes(
                $presetId,
                trim((string)($request['expectedFingerprint'] ?? '')),
                $allowLegacyPresetBreakage
            );
            break;
        case 'rollback_semantic_fixes':
            $result = $service->rollbackSemanticFixes(
                $presetId,
                trim((string)($request['expectedFingerprint'] ?? '')),
                $allowLegacyPresetBreakage
            );
            break;
        case 'materialize_base_offers':
            $result = $service->materializeBaseOffers(
                $presetId,
                trim((string)($request['expectedFingerprint'] ?? '')),
                $allowLegacyPresetBreakage
            );
            break;
        case 'verify':
            $result = $service->verify($presetId, $semanticFixes, $allowLegacyPresetBreakage);
            break;
        case 'cutover':
            $result = $service->cutover(
                $presetId,
                trim((string)($request['expectedFingerprint'] ?? '')),
                $semanticFixes,
                $allowLegacyPresetBreakage
            );
            break;
        case 'rollback_base_offers':
            $result = $service->rollbackBaseOffers(
                $presetId,
                trim((string)($request['expectedFingerprint'] ?? '')),
                $allowLegacyPresetBreakage
            );
            break;
        default:
            $respond(['status' => 'error', 'error' => 'unknown_action'], 400);
    }
    $respond(['status' => 'ok', 'data' => $result]);
} catch (InvalidArgumentException $error) {
    $respond(['status' => 'error', 'error' => 'invalid_request', 'message' => $error->getMessage()], 400);
} catch (Throwable $error) {
    $respond(['status' => 'error', 'error' => 'migration_failed', 'message' => $error->getMessage()], 409);
}
