<?php
/**
 * Административная страница массового пересчёта калькуляций
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Prospektweb\Calc\Services\BatchRecalculateService;

// Загружаем языковой файл из модуля, т.к. admin-страница копируется в /bitrix/admin/
$modulePath = getLocalPath('modules/prospektweb.calc');
if ($modulePath) {
    $langFile = $_SERVER['DOCUMENT_ROOT'] . $modulePath . '/admin/prospektweb_calc_recalculate.php';
    if (file_exists($langFile)) {
        Loc::loadMessages($langFile);
    } else {
        // Fallback: попробуем стандартный путь
        Loc::loadMessages(__FILE__);
    }
} else {
    Loc::loadMessages(__FILE__);
}

$module_id = 'prospektweb.calc';

global $USER, $APPLICATION;

// Проверка прав доступа
if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('PROSPEKTWEB_CALC_RECALC_ERROR_NO_ACCESS'));
    exit;
}

// Загрузка модуля
if (!Loader::includeModule($module_id)) {
    ShowError(Loc::getMessage('PROSPEKTWEB_CALC_RECALC_ERROR_NO_MODULE'));
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    exit;
}

CJSCore::Init(['core']);

$APPLICATION->SetTitle(Loc::getMessage('PROSPEKTWEB_CALC_RECALC_TITLE'));

// Получаем базовый путь для AJAX запросов
$ajaxEndpoint = '/bitrix/tools/prospektweb.calc/batch_recalculate.php';

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

// Получаем настройки по умолчанию
$calcServerUrl = Option::get($module_id, 'CALC_SERVER_URL', 'https://pwrt.ru/calc-api');
$defaultTimeout = 30;
$pageSessid = bitrix_sessid();

// Получаем список пресетов
$service = new BatchRecalculateService($calcServerUrl, $defaultTimeout);
$presets = $service->getPresetsWithOfferCount();

// Подготовка языковых констант для JavaScript
$jsMessages = [
    'SELECT_PRESET' => Loc::getMessage('PROSPEKTWEB_CALC_RECALC_SELECT_PRESETS'),
    'ENTER_URL' => 'Укажите URL calc-server',
    'STARTING' => 'Запуск пересчёта...',
    'COMPLETE' => Loc::getMessage('PROSPEKTWEB_CALC_RECALC_COMPLETE'),
    'ERROR' => 'Ошибка',
    'REQUEST_ERROR' => 'Ошибка запроса',
];

?>

<style>
.recalc-container {
    max-width: 1200px;
}
.recalc-section {
    background: #fff;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid #dce0e5;
}
.recalc-section h3 {
    margin-top: 0;
    margin-bottom: 15px;
    font-size: 14px;
    font-weight: bold;
}
.preset-list {
    max-height: 400px;
    overflow-y: auto;
    border: 1px solid #dce0e5;
    padding: 10px;
    background: #f8f9fa;
}
.preset-item {
    padding: 8px;
    margin-bottom: 5px;
    background: #fff;
    border: 1px solid #e0e0e0;
}
.preset-item:hover {
    background: #f0f0f0;
}
.progress-container {
    display: none;
    margin-top: 20px;
}
.progress-bar {
    width: 100%;
    height: 30px;
    background: #f0f0f0;
    border: 1px solid #dce0e5;
    border-radius: 3px;
    overflow: hidden;
    position: relative;
}
.progress-bar-fill {
    height: 100%;
    background: linear-gradient(to right, #4CAF50, #45a049);
    transition: width 0.3s ease;
    width: 0%;
}
.progress-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-weight: bold;
    color: #333;
}
.results-container {
    display: none;
    margin-top: 20px;
}
.results-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}
.results-table th,
.results-table td {
    padding: 8px;
    border: 1px solid #dce0e5;
    text-align: left;
}
.results-table th {
    background: #f0f0f0;
    font-weight: bold;
}
.stat-item {
    display: inline-block;
    margin-right: 20px;
    padding: 10px 15px;
    background: #f8f9fa;
    border-radius: 3px;
}
.stat-label {
    font-weight: bold;
    color: #666;
}
.stat-value {
    font-size: 18px;
    color: #333;
}
.error-list {
    background: #fff3cd;
    border: 1px solid #ffc107;
    padding: 10px;
    margin-top: 10px;
    max-height: 300px;
    overflow-y: auto;
}
.error-item {
    padding: 5px;
    margin-bottom: 5px;
    border-left: 3px solid #f44336;
    padding-left: 10px;
}
</style>

<div class="recalc-container">
    <!-- Описание -->
    <div class="adm-info-message">
        <?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_DESCRIPTION') ?>
    </div>

    <!-- Блок 1: Выбор области пересчёта -->
    <div class="recalc-section">
        <h3><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_SCOPE') ?></h3>
        
        <div style="margin-bottom: 15px;">
            <label>
                <input type="radio" name="scope" value="all" id="scope-all" checked>
                <?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_ALL_PRESETS') ?>
            </label>
            <br>
            <label>
                <input type="radio" name="scope" value="specific" id="scope-specific">
                <?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_SPECIFIC_PRESETS') ?>
            </label>
        </div>

        <div id="preset-selector" style="display: none;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                <?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_SELECT_PRESETS') ?>:
            </label>
            <?php if (empty($presets)): ?>
                <div class="adm-info-message">
                    <?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_NO_PRESETS') ?>
                </div>
            <?php else: ?>
                <div class="preset-list">
                    <?php foreach ($presets as $preset): ?>
                        <div class="preset-item">
                            <label>
                                <input type="checkbox" name="preset_ids[]" value="<?= $preset['id'] ?>">
                                <strong><?= htmlspecialcharsbx($preset['name']) ?></strong>
                                (ID: <?= $preset['id'] ?>, ТП: <?= $preset['offerCount'] ?>)
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Блок 2: Настройки пересчёта -->
    <div class="recalc-section">
        <h3><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_SETTINGS') ?></h3>
        
        <table class="adm-detail-content-table edit-table">
            <tr>
                <td width="40%" class="adm-detail-content-cell-l">
                    <?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_ONLY_CHANGED') ?>:
                </td>
                <td width="60%" class="adm-detail-content-cell-r">
                    <input type="checkbox" id="only-changed" checked>
                    <br><span style="color: #777; font-size: 11px;">
                        <?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_ONLY_CHANGED_HINT') ?>
                    </span>
                </td>
            </tr>
            <tr>
                <td class="adm-detail-content-cell-l">
                    <?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_SERVER_URL') ?>:
                </td>
                <td class="adm-detail-content-cell-r">
                    <input type="text" id="calc-server-url" value="<?= htmlspecialcharsbx($calcServerUrl) ?>" size="50" style="width: 400px;">
                    <br><span style="color: #777; font-size: 11px;">
                        <?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_SERVER_URL_HINT') ?>
                    </span>
                </td>
            </tr>
            <tr>
                <td class="adm-detail-content-cell-l">
                    <?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_TIMEOUT') ?>:
                </td>
                <td class="adm-detail-content-cell-r">
                    <input type="number" id="timeout" value="<?= $defaultTimeout ?>" min="5" max="300" style="width: 100px;">
                    <br><span style="color: #777; font-size: 11px;">
                        <?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_TIMEOUT_HINT') ?>
                    </span>
                </td>
            </tr>
        </table>
    </div>

    <!-- Блок 3: Кнопка запуска и прогресс -->
    <div class="recalc-section">
        <button type="button" id="start-recalc" class="adm-btn adm-btn-save">
            <?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_START') ?>
        </button>
        <button type="button" id="cancel-recalc" class="adm-btn" style="margin-left: 8px;">Остановить</button>

        <div class="progress-container" id="progress-container">
            <div class="progress-bar">
                <div class="progress-bar-fill" id="progress-bar-fill"></div>
                <div class="progress-text" id="progress-text">0%</div>
            </div>
            <div id="progress-message" style="margin-top: 10px; color: #666;"></div>
        </div>
    </div>

    <!-- Результаты -->
    <div class="results-container" id="results-container">
        <div class="recalc-section">
            <h3><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_RESULTS') ?></h3>
            
            <div id="summary-stats" style="margin-bottom: 20px;"></div>

            <div id="details-table"></div>

            <div id="errors-list"></div>

            <h4 style="margin-top: 20px;">Лог выполнения</h4>
            <div id="frontend-log" class="error-list" style="background: #f7fbff; border-color: #b6d4fe;"></div>
        </div>
    </div>
</div>

<script>
(function() {
    var config = {
        ajaxEndpoint: <?= json_encode($ajaxEndpoint, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        messages: <?= json_encode($jsMessages, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        sessid: <?= json_encode($pageSessid, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
    };

    var scopeAll = document.getElementById('scope-all');
    var scopeSpecific = document.getElementById('scope-specific');
    var presetSelector = document.getElementById('preset-selector');
    var startBtn = document.getElementById('start-recalc');
    var cancelBtn = document.getElementById('cancel-recalc');
    var progressContainer = document.getElementById('progress-container');
    var progressBarFill = document.getElementById('progress-bar-fill');
    var progressText = document.getElementById('progress-text');
    var progressMessage = document.getElementById('progress-message');
    var resultsContainer = document.getElementById('results-container');
    var frontendLog = document.getElementById('frontend-log');

    var requestInFlight = false;
    var pollingTimer = null;
    var lastRenderedLogCount = 0;

    cancelBtn.disabled = true;

    if (!startBtn || !cancelBtn || !scopeAll || !scopeSpecific) {
        return;
    }

    scopeAll.addEventListener('change', function() {
        if (this.checked) {
            presetSelector.style.display = 'none';
        }
    });

    scopeSpecific.addEventListener('change', function() {
        if (this.checked) {
            presetSelector.style.display = 'block';
        }
    });

    startBtn.addEventListener('click', function() {
        if (requestInFlight) {
            return;
        }

        try {
            startRecalculation();
        } catch (error) {
            appendFrontendLog('Критическая ошибка запуска: ' + (error.message || String(error)));
            alert((config.messages.ERROR || 'Ошибка') + ': ' + (error.message || 'Unknown error'));
            startBtn.disabled = false;
            requestInFlight = false;
        }
    });

    cancelBtn.addEventListener('click', function() {
        if (!requestInFlight) {
            return;
        }

        var runtimeSessid = config.sessid;
        if (window.BX && typeof window.BX.bitrix_sessid === 'function') {
            runtimeSessid = window.BX.bitrix_sessid();
        }

        clearPolling();
        sendApiRequest(runtimeSessid, { action: 'cancel', sessid: runtimeSessid }, function() {
            requestInFlight = false;
            startBtn.disabled = false;
            cancelBtn.disabled = true;
            appendFrontendLog('Задача отменена пользователем.');
            setProgress(0, 'Остановлено');
        });
    });

    function startRecalculation() {
        var scopeNode = document.querySelector('input[name="scope"]:checked');
        if (!scopeNode) {
            alert(config.messages.ERROR + ': Не выбрана область пересчёта');
            return;
        }

        var scope = scopeNode.value;
        var presetIds = [];

        if (scope === 'specific') {
            var checkboxes = document.querySelectorAll('input[name="preset_ids[]"]:checked');
            for (var i = 0; i < checkboxes.length; i++) {
                var parsedId = parseInt(checkboxes[i].value, 10);
                if (!isNaN(parsedId)) {
                    presetIds.push(parsedId);
                }
            }

            if (presetIds.length === 0) {
                alert(config.messages.SELECT_PRESET);
                return;
            }
        }

        var onlyChanged = document.getElementById('only-changed').checked;
        var calcServerUrl = document.getElementById('calc-server-url').value.replace(/^\s+|\s+$/g, '');
        var timeout = parseInt(document.getElementById('timeout').value, 10);

        if (!calcServerUrl) {
            alert(config.messages.ENTER_URL);
            return;
        }

        var runtimeSessid = config.sessid;
        if (window.BX && typeof window.BX.bitrix_sessid === 'function') {
            runtimeSessid = window.BX.bitrix_sessid();
        }

        if (!runtimeSessid) {
            alert('Не удалось получить sessid. Обновите страницу и повторите попытку.');
            return;
        }

        requestInFlight = true;
        startBtn.disabled = true;
        cancelBtn.disabled = false;
        resultsContainer.style.display = 'none';
        document.getElementById('details-table').innerHTML = '';
        document.getElementById('errors-list').innerHTML = '';
        frontendLog.innerHTML = '';
        lastRenderedLogCount = 0;

        setProgress(0, config.messages.STARTING);
        progressContainer.style.display = 'block';
        appendFrontendLog('Инициализация пакетного пересчёта...');

        sendApiRequest(runtimeSessid, {
            action: 'start',
            presetIds: presetIds,
            onlyChanged: onlyChanged,
            calcServerUrl: calcServerUrl,
            timeout: timeout,
            sessid: runtimeSessid
        }, function(err, response, data) {
            if (err) {
                requestInFlight = false;
                startBtn.disabled = false;
                cancelBtn.disabled = true;
                appendFrontendLog('Ошибка запуска: ' + err);
                alert((config.messages.REQUEST_ERROR || 'Ошибка запроса') + ': ' + err);
                return;
            }

            if (!response || response.status < 200 || response.status >= 300 || !data || !data.success) {
                requestInFlight = false;
                startBtn.disabled = false;
                handleApiError(data, response ? response.status : 0);
                return;
            }

            appendFrontendLog('Задача создана. Начинаем обработку...');
            renderServerLogs(data.logs || []);
            updateUiWithData(data);
            pollStep(runtimeSessid);
        });
    }

    function pollStep(sessid) {
        clearPolling();
        pollingTimer = setTimeout(function() {
            sendApiRequest(sessid, {
                action: 'step',
                sessid: sessid
            }, function(err, response, data) {
                if (err) {
                    requestInFlight = false;
                    startBtn.disabled = false;
                    cancelBtn.disabled = true;
                    appendFrontendLog('Ошибка шага пересчёта: ' + err);
                    alert((config.messages.REQUEST_ERROR || 'Ошибка запроса') + ': ' + err);
                    return;
                }

                if (!response || response.status < 200 || response.status >= 300 || !data || !data.success) {
                    requestInFlight = false;
                    startBtn.disabled = false;
                    cancelBtn.disabled = true;
                    handleApiError(data, response ? response.status : 0);
                    return;
                }

                renderServerLogs(data.logs || []);
                updateUiWithData(data);

                if (data.finished) {
                    requestInFlight = false;
                    startBtn.disabled = false;
                    cancelBtn.disabled = true;
                    appendFrontendLog('Пересчёт завершён.');
                    return;
                }

                pollStep(sessid);
            });
        }, 250);
    }

    function clearPolling() {
        if (pollingTimer) {
            clearTimeout(pollingTimer);
            pollingTimer = null;
        }
    }

    function updateUiWithData(data) {
        var summary = data.summary || {};
        var total = Number(summary.totalOffers || 0);
        var processed = Number(summary.processedOffers || 0);
        var percent = total > 0 ? Math.round((processed / total) * 100) : 100;
        setProgress(percent, 'Обработано ' + processed + ' из ' + total + ' ТП');
        displayResults(data);
    }

    function sendApiRequest(sessid, payload, callback) {
        var endpointWithSessid = config.ajaxEndpoint
            + (config.ajaxEndpoint.indexOf('?') >= 0 ? '&' : '?')
            + 'sessid=' + encodeURIComponent(sessid);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', endpointWithSessid, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.withCredentials = true;

        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) {
                return;
            }

            var data = null;
            if (xhr.responseText) {
                try {
                    data = JSON.parse(xhr.responseText);
                } catch (parseError) {
                    callback('Сервер вернул некорректный JSON (HTTP ' + xhr.status + ').', xhr, null);
                    return;
                }
            }

            callback(null, xhr, data);
        };

        xhr.onerror = function() {
            callback('Сетевая ошибка при обращении к endpoint пересчёта.', xhr, null);
        };

        xhr.send(JSON.stringify(payload));
    }

    function renderServerLogs(logs) {
        if (!logs || !logs.length) {
            return;
        }

        for (var i = lastRenderedLogCount; i < logs.length; i++) {
            var row = logs[i] || {};
            appendFrontendLog('[' + (row.ts || '--:--:--') + '] ' + (row.message || '...'));
        }

        lastRenderedLogCount = logs.length;
    }

    function appendFrontendLog(message) {
        if (!frontendLog) {
            return;
        }

        var item = document.createElement('div');
        item.className = 'error-item';
        item.style.borderLeftColor = '#0d6efd';
        item.textContent = message;
        frontendLog.appendChild(item);
        frontendLog.scrollTop = frontendLog.scrollHeight;
    }

    function handleApiError(data, statusCode) {
        if (data && data.errorCode === 'INVALID_SESSION') {
            alert('Сессия истекла. Обновите страницу и повторите запуск пересчёта.');
            return;
        }

        if (data && data.errorCode === 'ADMIN_REQUIRED') {
            alert('Недостаточно прав: требуется доступ администратора.');
            return;
        }

        if (data && data.errorCode === 'TOO_MANY_OFFERS') {
            var maxOffers = data.meta && data.meta.maxOffersPerJob ? data.meta.maxOffersPerJob : '';
            alert('Слишком много ТП для одного запуска' + (maxOffers ? ' (лимит: ' + maxOffers + ')' : '') + '. Ограничьте пресеты или запускайте частями.');
            return;
        }

        if (data && data.errorCode === 'JOB_EXPIRED') {
            alert('Задача пересчёта истекла по времени. Запустите пересчёт заново.');
            return;
        }

        var reason = (data && data.error) ? data.error : ('HTTP ' + statusCode);
        appendFrontendLog('Ошибка API: ' + reason);
        alert((config.messages.REQUEST_ERROR || 'Ошибка запроса') + ': ' + reason);
    }

    function setProgress(percent, text) {
        var safePercent = Number(percent);
        if (isNaN(safePercent)) {
            safePercent = 0;
        }

        if (safePercent < 0) {
            safePercent = 0;
        }

        if (safePercent > 100) {
            safePercent = 100;
        }

        progressBarFill.style.width = safePercent + '%';
        progressText.textContent = safePercent + '%';
        progressMessage.textContent = text || '';
    }

    function displayResults(data) {
        var summary = data.summary || {};
        var details = Object.prototype.toString.call(data.details) === '[object Array]' ? data.details : [];
        var errors = Object.prototype.toString.call(data.errors) === '[object Array]' ? data.errors : [];

        var summaryHtml = '';
        summaryHtml += '<div class="stat-item"><div class="stat-label"><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_TOTAL_PRESETS') ?>:</div><div class="stat-value">' + (summary.totalPresets || 0) + '</div></div>';
        summaryHtml += '<div class="stat-item"><div class="stat-label"><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_TOTAL_OFFERS') ?>:</div><div class="stat-value">' + (summary.totalOffers || 0) + '</div></div>';
        summaryHtml += '<div class="stat-item"><div class="stat-label">Обработано ТП:</div><div class="stat-value">' + (summary.processedOffers || 0) + '</div></div>';
        summaryHtml += '<div class="stat-item"><div class="stat-label"><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_SUCCESS') ?>:</div><div class="stat-value" style="color: #4CAF50;">' + (summary.recalculated || 0) + '</div></div>';
        summaryHtml += '<div class="stat-item"><div class="stat-label"><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_SKIPPED') ?>:</div><div class="stat-value" style="color: #FFC107;">' + (summary.skipped || 0) + '</div></div>';
        summaryHtml += '<div class="stat-item"><div class="stat-label"><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_ERRORS') ?>:</div><div class="stat-value" style="color: #f44336;">' + (summary.errors || 0) + '</div></div>';
        summaryHtml += '<div class="stat-item"><div class="stat-label"><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_DURATION') ?>:</div><div class="stat-value">' + (summary.duration || 0) + '</div></div>';
        document.getElementById('summary-stats').innerHTML = summaryHtml;

        if (details.length > 0) {
            var detailsHtml = '<h4><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_DETAILS') ?></h4>';
            detailsHtml += '<table class="results-table"><thead><tr>';
            detailsHtml += '<th><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_PRESET') ?></th>';
            detailsHtml += '<th><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_OFFER_COUNT') ?></th>';
            detailsHtml += '<th><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_SUCCESS') ?></th>';
            detailsHtml += '<th><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_SKIPPED') ?></th>';
            detailsHtml += '<th><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_ERRORS') ?></th>';
            detailsHtml += '</tr></thead><tbody>';

            for (var i = 0; i < details.length; i++) {
                var detail = details[i] || {};
                detailsHtml += '<tr>';
                detailsHtml += '<td>' + escapeHtml(String(detail.presetName)) + ' (ID: ' + escapeHtml(String(detail.presetId)) + ')</td>';
                detailsHtml += '<td>' + escapeHtml(String(detail.offerCount)) + '</td>';
                detailsHtml += '<td style="color: #4CAF50;">' + escapeHtml(String(detail.recalculated || 0)) + '</td>';
                detailsHtml += '<td style="color: #FFC107;">' + escapeHtml(String(detail.skipped || 0)) + '</td>';
                detailsHtml += '<td style="color: #f44336;">' + escapeHtml(String(detail.errors ? detail.errors.length : 0)) + '</td>';
                detailsHtml += '</tr>';
            }

            detailsHtml += '</tbody></table>';
            document.getElementById('details-table').innerHTML = detailsHtml;
        } else {
            document.getElementById('details-table').innerHTML = '';
        }

        if (errors.length > 0) {
            var errorsHtml = '<h4 style="color: #f44336;"><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_ERRORS') ?></h4>';
            errorsHtml += '<div class="error-list">';

            for (var j = 0; j < errors.length; j++) {
                var item = errors[j] || {};
                errorsHtml += '<div class="error-item">';
                errorsHtml += 'Пресет ID: ' + escapeHtml(String(item.presetId)) + ', ТП ID: ' + escapeHtml(String(item.offerId)) + '<br>';
                errorsHtml += 'Ошибка: ' + escapeHtml(String(item.error));
                errorsHtml += '</div>';
            }

            errorsHtml += '</div>';
            document.getElementById('errors-list').innerHTML = errorsHtml;
        } else {
            document.getElementById('errors-list').innerHTML = '';
        }

        resultsContainer.style.display = 'block';
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
</script>

<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
