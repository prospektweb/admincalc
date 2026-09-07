<?php
/** Empty native installation: document tables and six resource directories, no content. */
declare(strict_types=1);
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }
global $APPLICATION, $USER;
if (!$USER || !$USER->IsAdmin() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !check_bitrix_sessid()) {
    http_response_code(403); ShowError('Установка требует администратора и подтверждённый POST.'); return;
}
require_once __DIR__ . '/../lib/Install/NativeInstallation.php';
require_once __DIR__ . '/index.php';
try {
    $ids = (new \Prospektweb\Calc\Install\NativeInstallation())->installSchema();
    $module = new \prospektweb_calc();
    if (!$module->installFiles()) { throw new \RuntimeException('Не удалось установить файлы модуля.'); }
    $module->installEvents();
    $module->registerModule();
    echo '<div class="adm-info-message adm-info-message-green">Документное хранилище и пустая схема справочников установлены. Калькуляторы, товары и другие данные не импортировались.</div>';
    echo '<a class="adm-btn" href="/bitrix/admin/prospektweb_calc_control_center.php#/presets">Открыть центр управления</a>';
} catch (\Throwable $error) {
    http_response_code(409);
    ShowError(htmlspecialcharsbx($error->getMessage()));
    echo '<p>Установка не завершена. Уже созданная схема сохранена; повторный запуск проверит её и продолжит. Данные не удалялись.</p>';
}
