<?php
declare(strict_types=1);
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }
global $USER;
if (!$USER || !$USER->IsAdmin() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !check_bitrix_sessid()) {
    http_response_code(403); ShowError('Удаление модуля требует подтверждённый POST администратора.'); return;
}
if (\Bitrix\Main\ModuleManager::isModuleInstalled('prospektweb.frontcalc')) {
    ShowError('Сначала отключите зависимый модуль FrontCalc. Документы и настройки не изменены.'); return;
}
require_once __DIR__ . '/index.php';
try {
    if (\Bitrix\Main\Loader::includeModule('prospektweb.calc')) {
        $patch = (new \Prospektweb\Calc\Services\AsproAiPatchManager())->remove();
        if (!in_array($patch['state'] ?? '', ['not_installed', 'module_missing'], true)) { throw new \RuntimeException('Снятие управляемого патча не подтверждено; требуется проверка перед удалением модуля.'); }
    }
    $module = new \prospektweb_calc();
    $module->uninstallEvents();
    $module->uninstallFiles();
    \Bitrix\Main\ModuleManager::unRegisterModule('prospektweb.calc');
    echo '<div class="adm-info-message">Модуль отключён. Документы, справочники, привязки и настройки сохранены.</div>';
} catch (\Throwable $error) { ShowError(htmlspecialcharsbx($error->getMessage())); }
