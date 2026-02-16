<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Application;

Loc::loadMessages(__FILE__);

class prospektweb_calc extends CModule
{
    public $MODULE_ID = 'prospektweb.calc';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    // ВАЖНО для Marketplace: объявляем без значения
    public $PARTNER_NAME;
    public $PARTNER_URI;
    public $MODULE_GROUP_RIGHTS = 'Y';

    /** @var string Путь к модулю */
    protected $modulePath;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION = $arModuleVersion['VERSION'] ?? '1.0.0';
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'] ?? date('Y-m-d');

        $this->MODULE_NAME = Loc::getMessage('PROSPEKTWEB_CALC_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('PROSPEKTWEB_CALC_MODULE_DESC');

        // ВАЖНО для Marketplace: явное присваивание через $this->
        $this->PARTNER_NAME = "PROSPEKT-WEB";
        $this->PARTNER_URI = "https://prospekt-web.ru";

        $this->modulePath = dirname(__DIR__);
    }

    public function DoInstall()
    {
        global $APPLICATION;
        
        if (!$this->checkDependencies()) {
            return false;
        }
        
        // Определяем текущий шаг установки
        $step = (int)($_REQUEST['step'] ?? 1);
        
        switch ($step) {
            case 1:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_TITLE'), 
                    __DIR__ . '/step1.php'
                );
                break;
                
            case 2:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_TITLE'), 
                    __DIR__ . '/step2.php'
                );
                break;
                
            case 3:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_TITLE'), 
                    __DIR__ . '/step3.php'
                );
                break;
                
            case 4:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_TITLE'), 
                    __DIR__ . '/step4.php'
                );
                break;
                
            default:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_TITLE'), 
                    __DIR__ . '/step1.php'
                );
        }
        
        return true;
    }

    public function DoUninstall()
    {
        global $APPLICATION;
        
        $step = (int)($_REQUEST['step'] ?? 1);
        
        switch ($step) {
            case 1:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_TITLE'), 
                    __DIR__ . '/unstep1.php'
                );
                break;
            case 2:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_TITLE'), 
                    __DIR__ . '/unstep2.php'
                );
                break;
            default:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_TITLE'), 
                    __DIR__ . '/unstep1.php'
                );
        }
    }

    private function checkDependencies(): bool
    {
        if (!ModuleManager::isModuleInstalled('iblock')) {
            $GLOBALS['APPLICATION']->ThrowException(Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_REQUIRED'));
            return false;
        }
        if (!ModuleManager::isModuleInstalled('catalog')) {
            $GLOBALS['APPLICATION']->ThrowException(Loc::getMessage('PROSPEKTWEB_CALC_CATALOG_REQUIRED'));
            return false;
        }
        return true;
    }

    public function installFiles(): bool
    {
        $docRoot = Application::getDocumentRoot();
        
        // Путь к assets относительно install директории
        $sourceJs = __DIR__ . '/assets/js';
        $sourceCss = __DIR__ . '/assets/css';
        
        // НОВОЕ: Путь к tools (относительно корня модуля)
        $sourceTools = dirname(__DIR__) . '/tools';
        
        // НОВОЕ: Путь к admin (относительно корня модуля)
        $sourceAdmin = dirname(__DIR__) . '/admin';
        
        // НОВОЕ: Путь к React-билду (относительно install директории)
        $sourceApps = __DIR__ . '/assets/apps_dist';
        
        // Целевые директории в Bitrix
        $targetJs = $docRoot . '/local/js/prospektweb.calc';
        $targetCss = $docRoot . '/local/css/prospektweb.calc';
        
        // НОВОЕ: Публичная директория для API в /bitrix/tools
        $targetTools = $docRoot . '/bitrix/tools/prospektweb.calc';
        
        // НОВОЕ: Директория для React-приложения
        $targetApps = $docRoot . '/local/apps/prospektweb.calc';
        
        // НОВОЕ: Директория для админских файлов
        $targetAdmin = $docRoot . '/bitrix/admin';
        
        $success = true;
        $errors = [];
        
        // Создаём директории если не существуют
        foreach ([$targetJs, $targetCss, $targetTools, $targetApps] as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                    $errors[] = "Не удалось создать директорию: {$dir}";
                    $success = false;
                }
            }
        }
        
        // Копируем JS
        if (is_dir($sourceJs)) {
            if (is_dir($targetJs)) {
                CopyDirFiles($sourceJs, $targetJs, true, true);
            } else {
                $errors[] = "Целевая директория JS не существует: {$targetJs}";
                $success = false;
            }
        } else {
            $errors[] = "Исходная директория JS не найдена: {$sourceJs}";
            $success = false;
        }
        
        // Копируем CSS
        if (is_dir($sourceCss)) {
            if (is_dir($targetCss)) {
                CopyDirFiles($sourceCss, $targetCss, true, true);
            } else {
                $errors[] = "Целевая директория CSS не существует: {$targetCss}";
                $success = false;
            }
        } else {
            $errors[] = "Исходная директория CSS не найдена: {$sourceCss}";
            $success = false;
        }
        
        // НОВОЕ: Копируем Tools (API endpoints) в /bitrix/tools
        if (is_dir($sourceTools)) {
            if (is_dir($targetTools)) {
                CopyDirFiles($sourceTools, $targetTools, true, true);
            } else {
                $errors[] = "Целевая директория Tools не существует: {$targetTools}";
                $success = false;
            }
        } else {
            $errors[] = "Исходная директория Tools не найдена: {$sourceTools}";
            $success = false;
        }
        
        // НОВОЕ: Копируем админский файл калькулятора
        if (is_dir($sourceAdmin)) {
            $adminCalcFile = $sourceAdmin . '/calculator.php';
            if (file_exists($adminCalcFile)) {
                if (!copy($adminCalcFile, $targetAdmin . '/prospektweb_calc_calculator.php')) {
                    $errors[] = "Не удалось скопировать админский файл калькулятора";
                    $success = false;
                }
            }
            
            // Копируем админский файл для кастомных полей
            $adminCustomFieldFile = $sourceAdmin . '/prospektweb_calc_custom_field.php';
            if (file_exists($adminCustomFieldFile)) {
                if (!copy($adminCustomFieldFile, $targetAdmin . '/prospektweb_calc_custom_field.php')) {
                    $errors[] = "Не удалось скопировать админский файл кастомных полей";
                    $success = false;
                }
            }
            
            // Копируем админский файл пересчёта
            $adminRecalculateFile = $sourceAdmin . '/prospektweb_calc_recalculate.php';
            if (file_exists($adminRecalculateFile)) {
                if (!copy($adminRecalculateFile, $targetAdmin . '/prospektweb_calc_recalculate.php')) {
                    $errors[] = "Не удалось скопировать админский файл пересчёта";
                    $success = false;
                }
            }
        }
        
        // НОВОЕ: Копируем компоненты
        $sourceComponents = __DIR__ . '/components';
        $targetComponents = $docRoot . '/local/components';
        
        if (is_dir($sourceComponents)) {
            if (!is_dir($targetComponents)) {
                mkdir($targetComponents, 0755, true);
            }
            
            // Создаём директорию для компонентов prospektweb
            $targetProspektweb = $targetComponents . '/prospektweb';
            if (!is_dir($targetProspektweb)) {
                mkdir($targetProspektweb, 0755, true);
            }
            
            // Копируем компонент calc.custom_field.edit
            $sourceComponent = $sourceComponents . '/prospektweb/calc.custom_field.edit';
            $targetComponent = $targetProspektweb . '/calc.custom_field.edit';
            
            if (is_dir($sourceComponent)) {
                CopyDirFiles($sourceComponent, $targetComponent, true, true);
            } else {
                $errors[] = "Исходный компонент не найден: {$sourceComponent}";
            }
        }
        
        // НОВОЕ: Копируем React-приложение из install/apps_dist
        if (is_dir($sourceApps)) {
            if (is_dir($targetApps)) {
                CopyDirFiles($sourceApps, $targetApps, true, true);
            } else {
                $errors[] = "Целевая директория Apps не существует: {$targetApps}";
                $success = false;
            }
        }
        // Не устанавливаем $success = false при отсутствии apps_dist, так как это не критично
        
        // Логирование ошибок
        if (!empty($errors)) {
            foreach ($errors as $error) {
                error_log("[prospektweb.calc] installFiles error: {$error}");
            }
        }
        
        return $success;
    }

    public function uninstallFiles(): void
    {
        $jsDir = Application::getDocumentRoot() . '/local/js/prospektweb.calc';
        $cssDir = Application::getDocumentRoot() . '/local/css/prospektweb.calc';
        $toolsDir = Application::getDocumentRoot() . '/bitrix/tools/prospektweb.calc';
        $appsDir = Application::getDocumentRoot() . '/local/apps/prospektweb.calc';
        $adminFile = Application::getDocumentRoot() . '/bitrix/admin/prospektweb_calc_calculator.php';
        $adminCustomFieldFile = Application::getDocumentRoot() . '/bitrix/admin/prospektweb_calc_custom_field.php';
        $adminRecalculateFile = Application::getDocumentRoot() . '/bitrix/admin/prospektweb_calc_recalculate.php';

        if (is_dir($jsDir)) {
            DeleteDirFilesEx('/local/js/prospektweb.calc');
        }
        if (is_dir($cssDir)) {
            DeleteDirFilesEx('/local/css/prospektweb.calc');
        }
        // НОВОЕ: Удаляем tools
        if (is_dir($toolsDir)) {
            DeleteDirFilesEx('/bitrix/tools/prospektweb.calc');
        }
        // НОВОЕ: Удаляем apps
        if (is_dir($appsDir)) {
            DeleteDirFilesEx('/local/apps/prospektweb.calc');
        }
        // НОВОЕ: Удаляем админские файлы
        if (file_exists($adminFile)) {
            unlink($adminFile);
        }
        if (file_exists($adminCustomFieldFile)) {
            unlink($adminCustomFieldFile);
        }
        if (file_exists($adminRecalculateFile)) {
            unlink($adminRecalculateFile);
        }
    }

    public function installEvents(): void
    {
        $em = EventManager::getInstance();
        $em->registerEventHandler(
            'main',
            'OnProlog',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\AdminHandler',
            'onProlog'
        );
        $em->registerEventHandler(
            'main',
            'OnAdminTabControlBegin',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\AdminHandler',
            'onTabControlBegin'
        );
        $em->registerEventHandler(
            'main',
            'OnAdminListDisplay',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\AdminHandler',
            'onAdminListDisplay'
        );
        $em->registerEventHandler(
            'iblock',
            'OnAfterIBlockElementUpdate',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\DependencyHandler',
            'onElementUpdate'
        );
        $em->registerEventHandler(
            'main',
            'OnBeforeEndBufferContent',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\AdminHandler',
            'onBeforeEndBufferContent'
        );
        $em->registerEventHandler(
            'main',
            'OnBuildGlobalMenu',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\AdminHandler',
            'onBuildGlobalMenu'
        );
    }

    public function uninstallEvents(): void
    {
        $eventManager = EventManager::getInstance();
        
        $eventManager->unRegisterEventHandler(
            'main',
            'OnProlog',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\AdminHandler',
            'onProlog'
        );
        
        $eventManager->unRegisterEventHandler(
            'main',
            'OnAdminTabControlBegin',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\AdminHandler',
            'onTabControlBegin'
        );
        
        $eventManager->unRegisterEventHandler(
            'main',
            'OnAdminListDisplay',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\AdminHandler',
            'onAdminListDisplay'
        );
        
        $eventManager->unRegisterEventHandler(
            'iblock',
            'OnAfterIBlockElementUpdate',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\DependencyHandler',
            'onElementUpdate'
        );
        
        $eventManager->unRegisterEventHandler(
            'main',
            'OnBeforeEndBufferContent',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\AdminHandler',
            'onBeforeEndBufferContent'
        );
        
        $eventManager->unRegisterEventHandler(
            'main',
            'OnBuildGlobalMenu',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\AdminHandler',
            'onBuildGlobalMenu'
        );
    }

    /**
     * Удаление инфоблоков модуля
     */
    public function deleteIblocks(): void
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return;
        }

        $iblockCodes = [
            'CALC_PRESETS',
            'CALC_STAGES',
            'CALC_SETTINGS',
            'CALC_MATERIALS',
            'CALC_MATERIALS_VARIANTS',
            'CALC_OPERATIONS',
            'CALC_OPERATIONS_VARIANTS',
            'CALC_EQUIPMENT',
            'CALC_DETAILS',
            'CALC_CUSTOM_FIELDS',
        ];

        foreach ($iblockCodes as $code) {
            $iblockId = Option::get($this->MODULE_ID, 'IBLOCK_' . $code, 0);

            if ((int)$iblockId > 0) {
                \CIBlock::Delete((int)$iblockId);
            }
        }

        // Удаляем типы инфоблоков
        $types = ['calculator', 'calculator_catalog'];
        foreach ($types as $type) {
            \CIBlockType::Delete($type);
        }
    }

    /**
     * Удаление настроек модуля
     */
    public function deleteOptions(): void
    {
        Option::delete($this->MODULE_ID);
    }

    /**
     * Удаление HighloadBlock истории расчётов.
     */
    public function deleteHighloadBlocks(): void
    {
        if (!\Bitrix\Main\Loader::includeModule('highloadblock')) {
            return;
        }

        $hlblockId = (int)Option::get($this->MODULE_ID, 'HIGHLOAD_CALC_HISTORY_ID', 0);
        if ($hlblockId <= 0) {
            $hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getList([
                'filter' => ['=TABLE_NAME' => 'prospektcalc_offer_history'],
                'select' => ['ID'],
                'limit' => 1,
            ])->fetch();
            $hlblockId = (int)($hlblock['ID'] ?? 0);
        }

        if ($hlblockId > 0) {
            \Bitrix\Highloadblock\HighloadBlockTable::delete($hlblockId);
        }
    }

    /**
     * Регистрация модуля
     */
    public function registerModule(): void
    {
        ModuleManager::registerModule($this->MODULE_ID);
    }

    /**
     * Проверка целостности установки
     * @return array Массив с результатами проверки
     */
    public function checkInstallationIntegrity(): array
    {
        $result = [
            'success' => true,
            'errors' => [],
            'warnings' => [],
        ];

        // Проверяем регистрацию модуля
        if (!ModuleManager::isModuleInstalled($this->MODULE_ID)) {
            $result['errors'][] = 'Модуль не зарегистрирован';
            $result['success'] = false;
        }

        // Проверяем наличие инфоблоков
        $iblockCodes = [
            'CALC_PRESETS',
            'CALC_STAGES',
            'CALC_SETTINGS',
            'CALC_MATERIALS',
            'CALC_MATERIALS_VARIANTS',
            'CALC_OPERATIONS',
            'CALC_OPERATIONS_VARIANTS',
            'CALC_EQUIPMENT',
            'CALC_DETAILS',
        ];

        foreach ($iblockCodes as $code) {
            $iblockId = (int)Option::get($this->MODULE_ID, 'IBLOCK_' . $code, 0);
            if ($iblockId <= 0) {
                $result['warnings'][] = "Инфоблок {$code} не найден в настройках";
            }
        }

        // Проверяем наличие файлов
        $docRoot = Application::getDocumentRoot();
        $jsDir = $docRoot . '/local/js/prospektweb.calc';
        $cssDir = $docRoot . '/local/css/prospektweb.calc';
        $toolsDir = $docRoot . '/bitrix/tools/prospektweb.calc';
        $appsDir = $docRoot . '/local/apps/prospektweb.calc';
        $adminFile = $docRoot . '/bitrix/admin/prospektweb_calc_calculator.php';

        if (!is_dir($jsDir)) {
            $result['warnings'][] = 'Директория JS не найдена';
        }
        if (!is_dir($cssDir)) {
            $result['warnings'][] = 'Директория CSS не найдена';
        }
        if (!is_dir($toolsDir)) {
            $result['warnings'][] = 'Директория Tools не найдена';
        }
        if (!is_dir($appsDir)) {
            $result['warnings'][] = 'Директория Apps не найдена';
        }
        if (!file_exists($adminFile)) {
            $result['warnings'][] = 'Админский файл калькулятора не найден';
        }

        return $result;
    }

    /**
     * Получение ID инфоблока по коду
     *
     * @param string $code Код инфоблока
     * @return int ID инфоблока
     */
    public static function getIblockId(string $code): int
    {
        return (int)Option::get('prospektweb.calc', 'IBLOCK_' . $code, 0);
    }
}
