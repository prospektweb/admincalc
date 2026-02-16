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

$APPLICATION->SetTitle(Loc::getMessage('PROSPEKTWEB_CALC_RECALC_TITLE'));

// Получаем базовый путь для AJAX запросов
$ajaxEndpoint = '/bitrix/tools/prospektweb.calc/batch_recalculate.php';

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

// Получаем настройки по умолчанию
$calcServerUrl = Option::get($module_id, 'CALC_SERVER_URL', 'https://pwrt.ru/calc-api');
$defaultTimeout = 30;

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
        </div>
    </div>
</div>

<script>
(function() {
    const ajaxEndpoint = <?= json_encode($ajaxEndpoint, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const messages = <?= json_encode($jsMessages, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const scopeAll = document.getElementById('scope-all');
    const scopeSpecific = document.getElementById('scope-specific');
    const presetSelector = document.getElementById('preset-selector');
    const startBtn = document.getElementById('start-recalc');
    const progressContainer = document.getElementById('progress-container');
    const progressBarFill = document.getElementById('progress-bar-fill');
    const progressText = document.getElementById('progress-text');
    const progressMessage = document.getElementById('progress-message');
    const resultsContainer = document.getElementById('results-container');

    // Переключение области пересчёта
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

    // Запуск пересчёта
    startBtn.addEventListener('click', function() {
        // Получаем параметры
        const scope = document.querySelector('input[name="scope"]:checked').value;
        let presetIds = [];
        
        if (scope === 'specific') {
            const checkboxes = document.querySelectorAll('input[name="preset_ids[]"]:checked');
            presetIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
            
            if (presetIds.length === 0) {
                alert(messages.SELECT_PRESET);
                return;
            }
        }

        const onlyChanged = document.getElementById('only-changed').checked;
        const calcServerUrl = document.getElementById('calc-server-url').value.trim();
        const timeout = parseInt(document.getElementById('timeout').value);

        if (!calcServerUrl) {
            alert(messages.ENTER_URL);
            return;
        }

        // Скрываем результаты предыдущего запуска
        resultsContainer.style.display = 'none';
        
        // Показываем прогресс
        progressContainer.style.display = 'block';
        progressBarFill.style.width = '0%';
        progressText.textContent = '0%';
        progressMessage.textContent = messages.STARTING;
        
        // Отключаем кнопку
        startBtn.disabled = true;

        // Отправляем запрос
        fetch(ajaxEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                presetIds: presetIds,
                onlyChanged: onlyChanged,
                calcServerUrl: calcServerUrl,
                timeout: timeout,
                sessid: BX.bitrix_sessid()
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Прогресс завершён
                progressBarFill.style.width = '100%';
                progressText.textContent = '100%';
                progressMessage.textContent = messages.COMPLETE;
                
                // Показываем результаты
                displayResults(data);
            } else {
                alert(messages.ERROR + ': ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            alert(messages.REQUEST_ERROR + ': ' + error.message);
        })
        .finally(() => {
            startBtn.disabled = false;
        });
    });

    function displayResults(data) {
        const summary = data.summary || {};
        const details = data.details || [];
        const errors = data.errors || [];

        // Сводка
        const summaryHtml = `
            <div class="stat-item">
                <div class="stat-label"><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_TOTAL_PRESETS') ?>:</div>
                <div class="stat-value">${summary.totalPresets || 0}</div>
            </div>
            <div class="stat-item">
                <div class="stat-label"><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_TOTAL_OFFERS') ?>:</div>
                <div class="stat-value">${summary.totalOffers || 0}</div>
            </div>
            <div class="stat-item">
                <div class="stat-label"><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_SUCCESS') ?>:</div>
                <div class="stat-value" style="color: #4CAF50;">${summary.recalculated || 0}</div>
            </div>
            <div class="stat-item">
                <div class="stat-label"><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_SKIPPED') ?>:</div>
                <div class="stat-value" style="color: #FFC107;">${summary.skipped || 0}</div>
            </div>
            <div class="stat-item">
                <div class="stat-label"><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_ERRORS') ?>:</div>
                <div class="stat-value" style="color: #f44336;">${summary.errors || 0}</div>
            </div>
            <div class="stat-item">
                <div class="stat-label"><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_DURATION') ?>:</div>
                <div class="stat-value">${summary.duration || 0}</div>
            </div>
        `;
        document.getElementById('summary-stats').innerHTML = summaryHtml;

        // Детализация
        if (details.length > 0) {
            let detailsHtml = '<h4><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_DETAILS') ?></h4>';
            detailsHtml += '<table class="results-table">';
            detailsHtml += '<thead><tr>';
            detailsHtml += '<th><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_PRESET') ?></th>';
            detailsHtml += '<th><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_OFFER_COUNT') ?></th>';
            detailsHtml += '<th><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_SUCCESS') ?></th>';
            detailsHtml += '<th><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_SKIPPED') ?></th>';
            detailsHtml += '<th><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_ERRORS') ?></th>';
            detailsHtml += '</tr></thead><tbody>';
            
            details.forEach(detail => {
                detailsHtml += '<tr>';
                detailsHtml += `<td>${escapeHtml(String(detail.presetName))} (ID: ${escapeHtml(String(detail.presetId))})</td>`;
                detailsHtml += `<td>${escapeHtml(String(detail.offerCount))}</td>`;
                detailsHtml += `<td style="color: #4CAF50;">${escapeHtml(String(detail.recalculated || 0))}</td>`;
                detailsHtml += `<td style="color: #FFC107;">${escapeHtml(String(detail.skipped || 0))}</td>`;
                detailsHtml += `<td style="color: #f44336;">${escapeHtml(String(detail.errors ? detail.errors.length : 0))}</td>`;
                detailsHtml += '</tr>';
            });
            
            detailsHtml += '</tbody></table>';
            document.getElementById('details-table').innerHTML = detailsHtml;
        }

        // Ошибки
        if (errors.length > 0) {
            let errorsHtml = '<h4 style="color: #f44336;"><?= Loc::getMessage('PROSPEKTWEB_CALC_RECALC_ERRORS') ?></h4>';
            errorsHtml += '<div class="error-list">';
            errors.forEach(error => {
                errorsHtml += `<div class="error-item">`;
                errorsHtml += `Пресет ID: ${escapeHtml(String(error.presetId))}, ТП ID: ${escapeHtml(String(error.offerId))}<br>`;
                errorsHtml += `Ошибка: ${escapeHtml(String(error.error))}`;
                errorsHtml += `</div>`;
            });
            errorsHtml += '</div>';
            document.getElementById('errors-list').innerHTML = errorsHtml;
        }

        resultsContainer.style.display = 'block';
    }

    // Helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
</script>

<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
