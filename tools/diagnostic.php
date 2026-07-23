<?php

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', 'Y');
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Diagnostic\ModuleDiagnostic;
use Prospektweb\Calc\Install\SchemaRepairService;

global $USER;

// Проверка прав доступа
if (!check_bitrix_sessid() || !$USER->IsAdmin()) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(403);
    echo json_encode(['error' => 'Access denied'], JSON_UNESCAPED_UNICODE);
    die();
}

// Подключение модуля
if (!Loader::includeModule('prospektweb.calc')) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode(['error' => 'Module prospektweb.calc not loaded'], JSON_UNESCAPED_UNICODE);
    die();
}

$action = (string)($_REQUEST['action'] ?? 'run');

header('Content-Type: application/json; charset=UTF-8');

try {
    switch ($action) {
        case 'run':
            $diagnostic = new ModuleDiagnostic();
            $result = $diagnostic->runFullDiagnostic();
            echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        case 'fix_events':
            $connection = Application::getConnection();
            $moduleId = 'prospektweb.calc';
            $connection->queryExecute(
                "DELETE FROM b_module_to_module WHERE TO_MODULE_ID = '" .
                $connection->getSqlHelper()->forSql($moduleId) . "'"
            );

            // Переустанавливаем обработчики событий через класс из install/index.php
            $installIndexPath = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/' . $moduleId . '/install/index.php';
            if (file_exists($installIndexPath)) {
                require_once $installIndexPath;
                $installer = new prospektweb_calc();
                $installer->installEvents();
            }

            echo json_encode(['success' => true, 'message' => 'Обработчики событий восстановлены'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        case 'fix_files':
            $installIndexPath = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/prospektweb.calc/install/index.php';
            if (!file_exists($installIndexPath)) {
                throw new \RuntimeException('Файл install/index.php не найден');
            }

            require_once $installIndexPath;
            $installer = new prospektweb_calc();
            $success = $installer->installFiles();

            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Файлы переустановлены' : 'Переустановка файлов завершилась с ошибками (см. error_log)',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        case 'fix_schema':
            $repairResult = (new SchemaRepairService())->repairMissingProperties();
            $success = $repairResult['errorCount'] === 0;
            $message = sprintf(
                'Проверка завершена. Создано свойств: %d; уже существовало: %d',
                $repairResult['createdCount'],
                $repairResult['existingCount']
            );
            if (!$success) {
                $message .= '; ошибок: ' . $repairResult['errorCount'];
                $message .= '. ' . implode(' ', $repairResult['errors']);
            }

            echo json_encode([
                'success' => $success,
                'message' => $message,
                'data' => $repairResult,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
die();
