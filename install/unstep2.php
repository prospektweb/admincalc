<?php
/**
 * Шаг 2 удаления модуля: Пошаговый процесс с детальным логированием
 */

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

global $APPLICATION;

$deleteData = ($_REQUEST['DELETE_DATA'] ?? '') === 'Y';
$moduleId = 'prospektweb.calc';

// Инициализация логов
$log = [];
$errors = [];

/**
 * Функция логирования
 */
function uninstallLog(string $message, string $type = 'info'): void
{
    global $log;
    $log[] = ['message' => $message, 'type' => $type];
}

/**
 * Получение ошибки Bitrix
 */
function getUninstallError(): string
{
    global $APPLICATION;
    $ex = $APPLICATION->GetException();
    return $ex ? $ex->GetString() : 'Неизвестная ошибка';
}

// ============= НАЧАЛО ПРОЦЕССА УДАЛЕНИЯ =============

uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_START'), 'header');
uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_MODULE_ID') . ': ' . $moduleId);
uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETE_DATA_OPTION') . ': ' . ($deleteData ? Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_YES') : Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_NO')));
uninstallLog('');

// ============= ШАГ 1: УДАЛЕНИЕ ДАННЫХ (ЕСЛИ ВЫБРАНО) =============

if ($deleteData) {
    uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_STEP1'), 'header');
    
    if (Loader::includeModule('iblock') && Loader::includeModule('catalog')) {
        
        // Порядок важен: сначала варианты (SKU), потом родительские
        $iblockCodes = [
            'CALC_MATERIALS_VARIANTS',
            'CALC_OPERATIONS_VARIANTS',
            'CALC_STAGES',
            'CALC_MATERIALS',
            'CALC_OPERATIONS',
            'CALC_DETAILS',
            'CALC_EQUIPMENT',
            'CALC_PRESETS',
            'CALC_CUSTOM_FIELDS',
            'CALC_SETTINGS',
        ];

        // Собираем ID инфоблоков
        $iblockIds = [];
        uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_COLLECTING_IBLOCKS'));
        
        foreach ($iblockCodes as $code) {
            $iblockId = (int)Option::get($moduleId, 'IBLOCK_' . $code, 0);
            if ($iblockId > 0) {
                $iblockIds[$code] = $iblockId;
                uninstallLog("  → {$code}: ID {$iblockId}", 'success');
            } else {
                uninstallLog("  → {$code}: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_NOT_FOUND'), 'warning');
            }
        }
        uninstallLog('');

        // Шаг 1.1: Удаляем все элементы инфоблоков
        uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_STEP1_1'), 'header');
        foreach ($iblockIds as $code => $iblockId) {
            $deletedElements = 0;
            $rsElements = \CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId], false, false, ['ID']);
            while ($arElement = $rsElements->Fetch()) {
                if (\CIBlockElement::Delete($arElement['ID'])) {
                    $deletedElements++;
                }
            }
            if ($deletedElements > 0) {
                uninstallLog("  → {$code}: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETED') . " {$deletedElements} " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_ELEMENTS'), 'success');
            } else {
                uninstallLog("  → {$code}: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_NO_ELEMENTS'), 'info');
            }
        }
        uninstallLog('');

        // Шаг 1.2: Удаляем разделы инфоблоков
        uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_STEP1_2'), 'header');
        foreach ($iblockIds as $code => $iblockId) {
            $deletedSections = 0;
            $rsSections = \CIBlockSection::GetList(
                ['DEPTH_LEVEL' => 'DESC'],
                ['IBLOCK_ID' => $iblockId],
                false,
                ['ID']
            );
            while ($arSection = $rsSections->Fetch()) {
                if (\CIBlockSection::Delete($arSection['ID'])) {
                    $deletedSections++;
                }
            }
            if ($deletedSections > 0) {
                uninstallLog("  → {$code}: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETED') . " {$deletedSections} " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_SECTIONS'), 'success');
            }
        }
        uninstallLog('');

        // Шаг 1.3: Разрываем SKU-связи
        uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_STEP1_3'), 'header');
        $skuRelations = [
            'CALC_MATERIALS' => 'CALC_MATERIALS_VARIANTS',
            'CALC_OPERATIONS' => 'CALC_OPERATIONS_VARIANTS',
        ];
        
        foreach ($skuRelations as $parentCode => $offersCode) {
            $offersIblockId = $iblockIds[$offersCode] ?? 0;
            if ($offersIblockId > 0) {
                if (\CCatalog::Delete($offersIblockId)) {
                    uninstallLog("  → {$parentCode} ↔ {$offersCode}: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_SKU_DELETED'), 'success');
                } else {
                    uninstallLog("  → {$parentCode} ↔ {$offersCode}: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_SKU_NOT_EXISTS'), 'warning');
                }
            }
        }
        uninstallLog('');

        // Шаг 1.4: Удаляем инфоблоки
        uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_STEP1_4'), 'header');
        foreach ($iblockIds as $code => $iblockId) {
            if (\CIBlock::Delete($iblockId)) {
                uninstallLog("  → {$code} (ID: {$iblockId}): " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETED_SUCCESS'), 'success');
            } else {
                $error = getUninstallError();
                uninstallLog("  → {$code} (ID: {$iblockId}): " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETE_ERROR') . " - {$error}", 'error');
                $errors[] = "Инфоблок {$code}: {$error}";
            }
        }
        uninstallLog('');

        // Шаг 1.5: Удаляем типы инфоблоков
        uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_STEP1_5'), 'header');
        $types = ['calculator', 'calculator_catalog'];
        foreach ($types as $type) {
            if (\CIBlockType::Delete($type)) {
                uninstallLog("  → {$type}: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETED_SUCCESS'), 'success');
            } else {
                uninstallLog("  → {$type}: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_TYPE_NOT_EXISTS'), 'warning');
            }
        }
        uninstallLog('');
        
    } else {
        uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_MODULES_NOT_LOADED'), 'error');
        $errors[] = Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_MODULES_NOT_LOADED');
    }

    // Удаление HighloadBlock для истории расчётов
    if (Loader::includeModule('highloadblock')) {
        uninstallLog('Удаление HighloadBlock для истории расчётов...', 'header');
        
        $hlblockId = (int)Option::get($moduleId, 'HIGHLOAD_CALC_HISTORY_ID', 0);
        
        if ($hlblockId > 0) {
            try {
                $result = \Bitrix\Highloadblock\HighloadBlockTable::delete($hlblockId);
                
                if ($result->isSuccess()) {
                    uninstallLog("  → HighloadBlock удалён (ID: {$hlblockId})", 'success');
                } else {
                    $errorMessages = $result->getErrorMessages();
                    $errorText = implode(', ', $errorMessages);
                    uninstallLog("  → Ошибка удаления HighloadBlock: {$errorText}", 'error');
                    $errors[] = "HighloadBlock: {$errorText}";
                }
            } catch (\Exception $e) {
                uninstallLog("  → Ошибка удаления HighloadBlock: " . $e->getMessage(), 'error');
                $errors[] = "HighloadBlock: " . $e->getMessage();
            }
        } else {
            uninstallLog("  → HighloadBlock не найден или не был создан", 'warning');
        }
        
        uninstallLog('');
    }

    // Удаление свойства CALC_PRESET из инфоблока товаров (Product)
    $productIblockId = (int)Option::get($moduleId, 'PRODUCT_IBLOCK_ID', 0);
    if ($productIblockId > 0) {
        uninstallLog('Удаление свойства CALC_PRESET из инфоблока товаров...', 'header');
        $rsProperty = \CIBlockProperty::GetList([], [
            'IBLOCK_ID' => $productIblockId,
            'CODE' => 'CALC_PRESET'
        ]);
        if ($arProperty = $rsProperty->Fetch()) {
            if (\CIBlockProperty::Delete($arProperty['ID'])) {
                uninstallLog("  → CALC_PRESET (ID: {$arProperty['ID']}): " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETED_SUCCESS'), 'success');
            } else {
                uninstallLog("  → CALC_PRESET: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETE_ERROR'), 'error');
            }
        } else {
            uninstallLog("  → CALC_PRESET: свойство не найдено", 'warning');
        }
        uninstallLog('');
    }
}

// ============= ШАГ 2: УДАЛЕНИЕ ФАЙЛОВ =============

uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_STEP2'), 'header');

$docRoot = Application::getDocumentRoot();
$jsDir = $docRoot . '/local/js/prospektweb.calc';
$cssDir = $docRoot . '/local/css/prospektweb.calc';
$toolsDir = $docRoot . '/bitrix/tools/prospektweb.calc';
$appsDir = $docRoot . '/local/apps/prospektweb.calc';

if (is_dir($jsDir)) {
    if (DeleteDirFilesEx('/local/js/prospektweb.calc')) {
        uninstallLog("  → JS: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETED_SUCCESS'), 'success');
    } else {
        uninstallLog("  → JS: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETE_ERROR'), 'error');
    }
} else {
    uninstallLog("  → JS: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DIR_NOT_EXISTS'), 'warning');
}

if (is_dir($cssDir)) {
    if (DeleteDirFilesEx('/local/css/prospektweb.calc')) {
        uninstallLog("  → CSS: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETED_SUCCESS'), 'success');
    } else {
        uninstallLog("  → CSS: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETE_ERROR'), 'error');
    }
} else {
    uninstallLog("  → CSS: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DIR_NOT_EXISTS'), 'warning');
}

if (is_dir($toolsDir)) {
    if (DeleteDirFilesEx('/bitrix/tools/prospektweb.calc')) {
        uninstallLog("  → Tools: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETED_SUCCESS'), 'success');
    } else {
        uninstallLog("  → Tools: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETE_ERROR'), 'error');
    }
} else {
    uninstallLog("  → Tools: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DIR_NOT_EXISTS'), 'warning');
}

if (is_dir($appsDir)) {
    if (DeleteDirFilesEx('/local/apps/prospektweb.calc')) {
        uninstallLog("  → Apps: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETED_SUCCESS'), 'success');
    } else {
        uninstallLog("  → Apps: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETE_ERROR'), 'error');
    }
} else {
    uninstallLog("  → Apps: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DIR_NOT_EXISTS'), 'warning');
}
uninstallLog('');

// ============= ШАГ 3: УДАЛЕНИЕ ОБРАБОТЧИКОВ СОБЫТИЙ =============

uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_STEP3'), 'header');

$em = EventManager::getInstance();

$events = [
    ['module' => 'main', 'event' => 'OnProlog', 'class' => '\\Prospektweb\\Calc\\Handlers\\AdminHandler', 'method' => 'onProlog'],
    ['module' => 'main', 'event' => 'OnAdminTabControlBegin', 'class' => '\\Prospektweb\\Calc\\Handlers\\AdminHandler', 'method' => 'onTabControlBegin'],
    ['module' => 'main', 'event' => 'OnAdminListDisplay', 'class' => '\\Prospektweb\\Calc\\Handlers\\AdminHandler', 'method' => 'onAdminListDisplay'],
    ['module' => 'iblock', 'event' => 'OnAfterIBlockElementUpdate', 'class' => '\\Prospektweb\\Calc\\Handlers\\DependencyHandler', 'method' => 'onElementUpdate'],
];

foreach ($events as $eventData) {
    $em->unRegisterEventHandler($eventData['module'], $eventData['event'], $moduleId, $eventData['class'], $eventData['method']);
    uninstallLog("  → {$eventData['module']}::{$eventData['event']}: " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETED_SUCCESS'), 'success');
}
uninstallLog('');

// ============= ШАГ 4: УДАЛЕНИЕ НАСТРОЕК МОДУЛЯ =============

uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_STEP4'), 'header');

Option::delete($moduleId);
uninstallLog("  → " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_OPTIONS_DELETED'), 'success');
uninstallLog('');

// ============= ШАГ 5: СНЯТИЕ МОДУЛЯ С РЕГИСТРАЦИИ =============

uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_STEP5'), 'header');

// Очищаем таблицу событий
global $DB;
$DB->Query("DELETE FROM b_module_to_module WHERE TO_MODULE_ID = '" . $DB->ForSql($moduleId) . "'");
uninstallLog("  → " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_EVENTS_TABLE_CLEARED'), 'success');

// Снимаем модуль с регистрации
ModuleManager::unRegisterModule($moduleId);
uninstallLog("  → " . Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_MODULE_UNREGISTERED'), 'success');
uninstallLog('');

// ============= ЗАВЕРШЕНИЕ =============

if (empty($errors)) {
    uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_COMPLETED'), 'header');
} else {
    uninstallLog(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_COMPLETED_WITH_ERRORS'), 'header');
}

?>

<style>
.uninstall-log {
    background: #0c0c0c;
    color: #d4d4d4;
    padding: 20px;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 13px;
    line-height: 1.8;
    border-radius: 4px;
    max-height: 600px;
    overflow-y: auto;
    margin: 20px 0;
    border: 1px solid #333;
}
.uninstall-log .log-info { color: #808080; }
.uninstall-log .log-info::before { content: '  '; }
.uninstall-log .log-success { color: #4ec9b0; }
.uninstall-log .log-success::before { content: '✓ '; }
.uninstall-log .log-warning { color: #dcdcaa; }
.uninstall-log .log-warning::before { content: '⚠ '; }
.uninstall-log .log-error { color: #f14c4c; }
.uninstall-log .log-error::before { content: '✗ '; }
.uninstall-log .log-header { 
    color: #569cd6; 
    font-weight: bold; 
    margin-top: 5px;
    border-bottom: 1px solid #333;
    padding-bottom: 5px;
}
.uninstall-log .log-header::before { content: ''; }
</style>

<div class="uninstall-log">
    <?php foreach ($log as $entry): ?>
    <?php if ($entry['message'] === ''): ?>
    <div>&nbsp;</div>
    <?php else: ?>
    <div class="log-<?= $entry['type'] ?>"><?= htmlspecialcharsbx($entry['message']) ?></div>
    <?php endif; ?>
    <?php endforeach; ?>
</div>

<?php if (!empty($errors)): ?>
<div class="adm-info-message adm-info-message-red">
    <strong><?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_ERRORS_FOUND') ?></strong>
    <ul>
        <?php foreach ($errors as $error): ?>
        <li><?= htmlspecialcharsbx($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php else: ?>
<div class="adm-info-message adm-info-message-green">
    <?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_SUCCESS') ?>
</div>
<?php endif; ?>

<div style="margin-top: 20px;">
    <a href="/bitrix/admin/partner_modules.php?lang=<?= LANGUAGE_ID ?>" class="adm-btn-save"><?= Loc::getMessage('PROSPEKTWEB_CALC_BACK_TO_MODULES') ?></a>
</div>
