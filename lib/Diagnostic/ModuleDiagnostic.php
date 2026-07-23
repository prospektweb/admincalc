<?php

namespace Prospektweb\Calc\Diagnostic;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Install\SchemaRepairService;

/**
 * Главный класс диагностики модуля.
 */
class ModuleDiagnostic
{
    private const MODULE_ID = 'prospektweb.calc';

    private const IBLOCK_CODES = [
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

    private const IBLOCK_REQUIRED_PROPERTIES = [
        'CALC_SETTINGS' => [
            'CALCULATOR_NAME',
            'DESCRIPTION',
            'DEFAULT_OPERATION_VARIANT',
            'SUPPORTED_EQUIPMENT_LIST',
            'DEFAULT_MATERIAL_VARIANT',
            'REQUIRES_BEFORE',
            'MIN_QUANTITY',
            'FILE_PREVIEW',
            'SORT_ORDER',
        ],
        'CALC_STAGES' => ['CALCULATOR', 'SORT_ORDER', 'SYNC_VARIANTS'],
        'CALC_MATERIALS' => ['UNIT', 'DESCRIPTION'],
        'CALC_OPERATIONS' => ['UNIT', 'DESCRIPTION'],
    ];

    private const CRITICAL_FILES = [
        'install/index.php',
        'install/version.php',
        'include.php',
        'options.php',
        'default_option.php',
        'lib/Handlers/AdminHandler.php',
        'lib/Handlers/DependencyHandler.php',
        'lib/Config/ConfigManager.php',
        'lib/Config/SettingsManager.php',
        'lib/Calculator/InitPayloadService.php',
        'lib/Calculator/SaveHandler.php',
        'lib/Calculator/ElementDataService.php',
        'tools/calculator_ajax.php',
        'tools/config.php',
        'tools/elements.php',
        'tools/calculator_config.php',
        'tools/calculate.php',
        'tools/save_result.php',
        'tools/batch_recalculate.php',
        'tools/product_generator.php',
        'install/assets/js/calculator.js',
        'install/assets/js/integration.js',
        'install/step1.php',
        'install/step3.php',
    ];

    private const EXPECTED_EVENTS = [
        ['FROM_MODULE_ID' => 'main', 'MESSAGE_ID' => 'OnProlog', 'TO_CLASS' => 'AdminHandler', 'TO_METHOD' => 'onProlog'],
        ['FROM_MODULE_ID' => 'main', 'MESSAGE_ID' => 'OnAdminTabControlBegin', 'TO_CLASS' => 'AdminHandler', 'TO_METHOD' => 'onTabControlBegin'],
        ['FROM_MODULE_ID' => 'main', 'MESSAGE_ID' => 'OnAdminListDisplay', 'TO_CLASS' => 'AdminHandler', 'TO_METHOD' => 'onAdminListDisplay'],
        ['FROM_MODULE_ID' => 'iblock', 'MESSAGE_ID' => 'OnAfterIBlockElementUpdate', 'TO_CLASS' => 'DependencyHandler', 'TO_METHOD' => 'onElementUpdate'],
        ['FROM_MODULE_ID' => 'main', 'MESSAGE_ID' => 'OnBeforeEndBufferContent', 'TO_CLASS' => 'AdminHandler', 'TO_METHOD' => 'onBeforeEndBufferContent'],
        ['FROM_MODULE_ID' => 'main', 'MESSAGE_ID' => 'OnBuildGlobalMenu', 'TO_CLASS' => 'AdminHandler', 'TO_METHOD' => 'onBuildGlobalMenu'],
    ];

    /** @var string Путь к директории модуля */
    private string $modulePath;

    public function __construct()
    {
        $this->modulePath = dirname(__DIR__, 2);
    }

    /**
     * Запускает полную диагностику и возвращает структурированный массив.
     */
    public function runFullDiagnostic(): array
    {
        $sections = [];

        $sections[] = $this->checkModuleRegistration();
        $sections[] = $this->checkModuleVersion();
        $sections[] = $this->checkDependencies();
        $sections[] = $this->checkInstalledFiles();
        $sections[] = $this->checkIblockTypes();
        $sections[] = $this->checkIblocks();
        $sections[] = $this->checkIblockProperties();
        $sections[] = $this->checkEventHandlers();
        $sections[] = $this->checkModuleOptions();
        $sections[] = $this->checkHighloadBlock();
        $sections[] = $this->checkGitHubComparison();

        return ['sections' => $sections];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Регистрация модуля
    // ─────────────────────────────────────────────────────────────────────────

    private function checkModuleRegistration(): array
    {
        $checks = [];
        $errors = [];

        $isInstalled = ModuleManager::isModuleInstalled(self::MODULE_ID);
        $checks[] = [
            'label' => 'ModuleManager::isModuleInstalled()',
            'status' => $isInstalled ? 'ok' : 'error',
            'value' => $isInstalled ? 'Установлен' : 'Не установлен',
        ];
        if (!$isInstalled) {
            $errors[] = 'Модуль не зарегистрирован в системе';
        }

        $isLoaded = Loader::includeModule(self::MODULE_ID);
        $checks[] = [
            'label' => 'Loader::includeModule()',
            'status' => $isLoaded ? 'ok' : 'error',
            'value' => $isLoaded ? 'Подключён' : 'Не удалось подключить',
        ];
        if (!$isLoaded) {
            $errors[] = 'Модуль не удалось подключить через Loader';
        }

        return $this->buildSection('Регистрация модуля', '🔌', $checks, $errors);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Версия модуля
    // ─────────────────────────────────────────────────────────────────────────

    private function checkModuleVersion(): array
    {
        $checks = [];
        $errors = [];

        $versionFile = $this->modulePath . '/install/version.php';
        if (!file_exists($versionFile)) {
            $errors[] = 'Файл install/version.php не найден';
            return $this->buildSection('Версия модуля', '📋', $checks, $errors);
        }

        $arModuleVersion = [];
        include $versionFile;

        $version = $arModuleVersion['VERSION'] ?? 'не определена';
        $versionDate = $arModuleVersion['VERSION_DATE'] ?? 'не определена';

        $checks[] = ['label' => 'VERSION', 'status' => 'ok', 'value' => $version];
        $checks[] = ['label' => 'VERSION_DATE', 'status' => 'ok', 'value' => $versionDate];

        return $this->buildSection('Версия модуля', '📋', $checks, $errors);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Зависимости
    // ─────────────────────────────────────────────────────────────────────────

    private function checkDependencies(): array
    {
        $checks = [];
        $errors = [];

        foreach (['iblock', 'catalog', 'highloadblock'] as $module) {
            $installed = ModuleManager::isModuleInstalled($module);
            $checks[] = [
                'label' => 'Модуль ' . $module,
                'status' => $installed ? 'ok' : 'error',
                'value' => $installed ? 'Установлен' : 'Не установлен',
            ];
            if (!$installed) {
                $errors[] = 'Зависимый модуль "' . $module . '" не установлен';
            }
        }

        return $this->buildSection('Зависимости', '📦', $checks, $errors);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Установленные файлы
    // ─────────────────────────────────────────────────────────────────────────

    private function checkInstalledFiles(): array
    {
        $checks = [];
        $errors = [];
        $docRoot = Application::getDocumentRoot();

        $dirs = [
            '/local/js/prospektweb.calc',
            '/local/css/prospektweb.calc',
            '/bitrix/tools/prospektweb.calc',
            '/local/apps/prospektweb.calc',
        ];

        foreach ($dirs as $dir) {
            $exists = is_dir($docRoot . $dir);
            $checks[] = [
                'label' => 'Директория ' . $dir,
                'status' => $exists ? 'ok' : 'warning',
                'value' => $exists ? 'Существует' : 'Отсутствует',
            ];
            if (!$exists) {
                $errors[] = 'Директория ' . $dir . ' не найдена';
            }
        }

        $adminFiles = [
            '/bitrix/admin/prospektweb_calc_calculator.php',
            '/bitrix/admin/prospektweb_calc_custom_field.php',
            '/bitrix/admin/prospektweb_calc_recalculate.php',
        ];

        foreach ($adminFiles as $file) {
            $exists = file_exists($docRoot . $file);
            $checks[] = [
                'label' => 'Файл ' . $file,
                'status' => $exists ? 'ok' : 'warning',
                'value' => $exists ? 'Существует' : 'Отсутствует',
            ];
            if (!$exists) {
                $errors[] = 'Файл ' . $file . ' не найден';
            }
        }

        $toolFiles = [
            'calculator_ajax.php',
            'config.php',
            'elements.php',
            'calculator_config.php',
            'calculate.php',
            'save_result.php',
            'batch_recalculate.php',
            'product_generator.php',
        ];

        foreach ($toolFiles as $file) {
            $fullPath = $docRoot . '/bitrix/tools/prospektweb.calc/' . $file;
            $exists = file_exists($fullPath);
            $checks[] = [
                'label' => 'tools/' . $file,
                'status' => $exists ? 'ok' : 'warning',
                'value' => $exists ? 'Существует' : 'Отсутствует',
            ];
            if (!$exists) {
                $errors[] = 'Файл /bitrix/tools/prospektweb.calc/' . $file . ' не найден';
            }
        }

        return $this->buildSection('Установленные файлы', '📁', $checks, $errors);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Типы инфоблоков
    // ─────────────────────────────────────────────────────────────────────────

    private function checkIblockTypes(): array
    {
        $checks = [];
        $errors = [];

        if (!Loader::includeModule('iblock')) {
            $errors[] = 'Модуль iblock не доступен';
            return $this->buildSection('Типы инфоблоков', '🗂️', $checks, $errors);
        }

        foreach (['calculator', 'calculator_catalog'] as $typeId) {
            $type = \CIBlockType::GetByID($typeId)->Fetch();
            $exists = !empty($type);
            $checks[] = [
                'label' => 'Тип инфоблока "' . $typeId . '"',
                'status' => $exists ? 'ok' : 'error',
                'value' => $exists ? 'Существует' : 'Не найден',
            ];
            if (!$exists) {
                $errors[] = 'Тип инфоблока "' . $typeId . '" не найден';
            }
        }

        return $this->buildSection('Типы инфоблоков', '🗂️', $checks, $errors);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Инфоблоки
    // ─────────────────────────────────────────────────────────────────────────

    private function checkIblocks(): array
    {
        $checks = [];
        $errors = [];

        if (!Loader::includeModule('iblock')) {
            $errors[] = 'Модуль iblock не доступен';
            return $this->buildSection('Инфоблоки', '📚', $checks, $errors);
        }

        foreach (self::IBLOCK_CODES as $code) {
            $optionKey = 'IBLOCK_' . $code;
            $iblockId = (int)Option::get(self::MODULE_ID, $optionKey, 0);

            // Проверка наличия ID в Option
            if ($iblockId <= 0) {
                $checks[] = [
                    'label' => $code . ' (Option)',
                    'status' => 'error',
                    'value' => 'ID не сохранён',
                ];
                $errors[] = 'Инфоблок ' . $code . ': ID не сохранён в настройках';
                continue;
            }

            $checks[] = [
                'label' => $code . ' (Option)',
                'status' => 'ok',
                'value' => 'ID = ' . $iblockId,
            ];

            // Проверка реального существования инфоблока
            $iblock = \CIBlock::GetByID($iblockId)->Fetch();
            if (!$iblock) {
                $checks[] = [
                    'label' => $code . ' (DB)',
                    'status' => 'error',
                    'value' => 'Инфоблок ID=' . $iblockId . ' не найден в БД',
                ];
                $errors[] = 'Инфоблок ' . $code . ' (ID=' . $iblockId . ') не найден в базе данных';
                continue;
            }

            $checks[] = [
                'label' => $code . ' (DB)',
                'status' => 'ok',
                'value' => 'Найден: ' . ($iblock['NAME'] ?? ''),
            ];

            // Проверка совпадения CODE
            $actualCode = $iblock['CODE'] ?? '';
            $codeMatches = $actualCode === $code;
            $checks[] = [
                'label' => $code . ' (CODE)',
                'status' => $codeMatches ? 'ok' : 'warning',
                'value' => $codeMatches
                    ? 'Совпадает: ' . $actualCode
                    : 'Расхождение: ожидался "' . $code . '", найден "' . $actualCode . '"',
            ];
            if (!$codeMatches) {
                $errors[] = 'Инфоблок ' . $code . ': CODE не совпадает (ожидался "' . $code . '", найден "' . $actualCode . '")';
            }
        }

        return $this->buildSection('Инфоблоки', '📚', $checks, $errors);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Свойства инфоблоков
    // ─────────────────────────────────────────────────────────────────────────

    private function checkIblockProperties(): array
    {
        $checks = [];
        $errors = [];

        if (!Loader::includeModule('iblock')) {
            $errors[] = 'Модуль iblock не доступен';
            return $this->buildSection('Свойства инфоблоков', '🏷️', $checks, $errors);
        }

        $requiredPropertiesByIblock = self::IBLOCK_REQUIRED_PROPERTIES;
        foreach (SchemaRepairService::getPropertySchema() as $iblockCode => $definitions) {
            $requiredPropertiesByIblock[$iblockCode] = array_values(array_unique(array_merge(
                $requiredPropertiesByIblock[$iblockCode] ?? [],
                array_keys($definitions)
            )));
        }

        $configManager = new ConfigManager();
        foreach ($requiredPropertiesByIblock as $iblockCode => $requiredProps) {
            $iblockId = $configManager->getIblockId($iblockCode);
            if ($iblockId <= 0) {
                $checks[] = [
                    'label' => $iblockCode . ': свойства',
                    'status' => 'warning',
                    'value' => 'Инфоблок не найден, пропуск проверки',
                ];
                continue;
            }

            // Получаем все существующие свойства инфоблока
            $existingProps = [];
            $rsProps = \CIBlockProperty::GetList(
                [],
                ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y']
            );
            while ($prop = $rsProps->Fetch()) {
                $existingProps[$prop['CODE']] = true;
            }

            foreach ($requiredProps as $propCode) {
                $exists = isset($existingProps[$propCode]);
                $checks[] = [
                    'label' => $iblockCode . '.' . $propCode,
                    'status' => $exists ? 'ok' : 'error',
                    'value' => $exists ? 'Найдено' : 'Отсутствует',
                ];
                if (!$exists) {
                    $errors[] = 'Инфоблок ' . $iblockCode . ': отсутствует свойство "' . $propCode . '"';
                }
            }
        }

        return $this->buildSection('Свойства инфоблоков', '🏷️', $checks, $errors);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. Обработчики событий
    // ─────────────────────────────────────────────────────────────────────────

    private function checkEventHandlers(): array
    {
        $checks = [];
        $errors = [];
        $warnings = [];

        $connection = Application::getConnection();
        $sql = "SELECT FROM_MODULE_ID, MESSAGE_ID, TO_CLASS, TO_METHOD
                FROM b_module_to_module
                WHERE TO_MODULE_ID = '" . $connection->getSqlHelper()->forSql(self::MODULE_ID) . "'";

        $installedHandlers = [];
        $rs = $connection->query($sql);
        while ($row = $rs->fetch()) {
            $installedHandlers[] = $row;
        }

        foreach (self::EXPECTED_EVENTS as $expected) {
            $found = false;
            foreach ($installedHandlers as $handler) {
                if (
                    $handler['FROM_MODULE_ID'] === $expected['FROM_MODULE_ID'] &&
                    $handler['MESSAGE_ID'] === $expected['MESSAGE_ID'] &&
                    stripos($handler['TO_CLASS'], $expected['TO_CLASS']) !== false &&
                    $handler['TO_METHOD'] === $expected['TO_METHOD']
                ) {
                    $found = true;
                    break;
                }
            }

            $label = $expected['FROM_MODULE_ID'] . '::' . $expected['MESSAGE_ID'];
            $checks[] = [
                'label' => $label . ' → ' . $expected['TO_CLASS'] . '::' . $expected['TO_METHOD'],
                'status' => $found ? 'ok' : 'error',
                'value' => $found ? 'Зарегистрирован' : 'Отсутствует',
            ];
            if (!$found) {
                $errors[] = 'Обработчик ' . $label . ' не зарегистрирован';
            }
        }

        // Поиск "лишних" обработчиков
        foreach ($installedHandlers as $handler) {
            $isExpected = false;
            foreach (self::EXPECTED_EVENTS as $expected) {
                if (
                    $handler['FROM_MODULE_ID'] === $expected['FROM_MODULE_ID'] &&
                    $handler['MESSAGE_ID'] === $expected['MESSAGE_ID'] &&
                    stripos($handler['TO_CLASS'], $expected['TO_CLASS']) !== false &&
                    $handler['TO_METHOD'] === $expected['TO_METHOD']
                ) {
                    $isExpected = true;
                    break;
                }
            }

            if (!$isExpected) {
                $warnings[] = 'Лишний обработчик: ' . $handler['FROM_MODULE_ID'] . '::' . $handler['MESSAGE_ID'] .
                    ' → ' . $handler['TO_CLASS'] . '::' . $handler['TO_METHOD'];
            }
        }

        return $this->buildSection('Обработчики событий', '⚡', $checks, $errors, $warnings);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9. Настройки модуля
    // ─────────────────────────────────────────────────────────────────────────

    private function checkModuleOptions(): array
    {
        $checks = [];
        $errors = [];

        $productIblockId = (int)Option::get(self::MODULE_ID, 'PRODUCT_IBLOCK_ID', 0);
        $checks[] = [
            'label' => 'PRODUCT_IBLOCK_ID',
            'status' => $productIblockId > 0 ? 'ok' : 'warning',
            'value' => $productIblockId > 0 ? 'ID = ' . $productIblockId : 'Не задан',
        ];
        if ($productIblockId <= 0) {
            $errors[] = 'PRODUCT_IBLOCK_ID не настроен';
        }

        $skuIblockId = (int)Option::get(self::MODULE_ID, 'SKU_IBLOCK_ID', 0);
        $checks[] = [
            'label' => 'SKU_IBLOCK_ID',
            'status' => $skuIblockId > 0 ? 'ok' : 'warning',
            'value' => $skuIblockId > 0 ? 'ID = ' . $skuIblockId : 'Не задан',
        ];
        if ($skuIblockId <= 0) {
            $errors[] = 'SKU_IBLOCK_ID не настроен';
        }

        return $this->buildSection('Настройки модуля', '⚙️', $checks, $errors);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10. HighloadBlock
    // ─────────────────────────────────────────────────────────────────────────

    private function checkHighloadBlock(): array
    {
        $checks = [];
        $errors = [];

        if (!Loader::includeModule('highloadblock')) {
            $checks[] = [
                'label' => 'Модуль highloadblock',
                'status' => 'error',
                'value' => 'Не установлен',
            ];
            $errors[] = 'Модуль highloadblock не доступен';
            return $this->buildSection('HighloadBlock', '🗄️', $checks, $errors);
        }

        $hlblockId = (int)Option::get(self::MODULE_ID, 'HIGHLOAD_CALC_HISTORY_ID', 0);
        $checks[] = [
            'label' => 'HIGHLOAD_CALC_HISTORY_ID (Option)',
            'status' => $hlblockId > 0 ? 'ok' : 'warning',
            'value' => $hlblockId > 0 ? 'ID = ' . $hlblockId : 'Не задан',
        ];

        if ($hlblockId > 0) {
            $hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById($hlblockId)->fetch();
            $exists = !empty($hlblock);
            $checks[] = [
                'label' => 'HLBLOCK_CALC_HISTORY (DB)',
                'status' => $exists ? 'ok' : 'error',
                'value' => $exists
                    ? 'Найден: ' . ($hlblock['NAME'] ?? '')
                    : 'HighloadBlock ID=' . $hlblockId . ' не найден в БД',
            ];
            if (!$exists) {
                $errors[] = 'HighloadBlock CALC_HISTORY (ID=' . $hlblockId . ') не найден в базе данных';
            }
        } else {
            $errors[] = 'HIGHLOAD_CALC_HISTORY_ID не настроен';
        }

        return $this->buildSection('HighloadBlock', '🗄️', $checks, $errors);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11. Сравнение с GitHub
    // ─────────────────────────────────────────────────────────────────────────

    private function checkGitHubComparison(): array
    {
        $checks = [];
        $errors = [];
        $warnings = [];

        // Проверка возможностей сервера
        $capabilities = GitHubClient::checkServerCapabilities();
        $capChecks = [
            ['label' => 'cURL доступен', 'status' => $capabilities['curl_available'] ? 'ok' : 'warning', 'value' => $capabilities['curl_available'] ? 'Да' : 'Нет'],
            ['label' => 'allow_url_fopen', 'status' => $capabilities['allow_url_fopen'] ? 'ok' : 'warning', 'value' => $capabilities['allow_url_fopen'] ? 'Включён' : 'Отключён'],
            ['label' => 'OpenSSL', 'status' => $capabilities['openssl_available'] ? 'ok' : 'warning', 'value' => $capabilities['openssl_available'] ? 'Доступен' : 'Недоступен'],
            ['label' => 'Возможность HTTP-запросов', 'status' => $capabilities['can_make_requests'] ? 'ok' : 'error', 'value' => $capabilities['can_make_requests'] ? 'Да' : 'Нет'],
        ];

        foreach ($capChecks as $check) {
            $checks[] = $check;
        }

        if (!$capabilities['can_make_requests']) {
            $errors[] = 'Сервер не может выполнять HTTP-запросы. Проверка с GitHub невозможна.';
            return $this->buildSection('Сравнение с GitHub', '🌐', $checks, $errors, $warnings);
        }

        // Пробуем получить дерево из GitHub (с кешем)
        $cache = new DiagnosticCache();
        $treeResult = $cache->get();

        $method = 'cache';
        $rateLimitRemaining = null;
        $commitSha = '';

        if ($treeResult === null) {
            try {
                $client = new GitHubClient();
                $treeResult = $client->fetchRepositoryTree();
                $cache->set($treeResult);
                $method = $treeResult['method'] ?? 'unknown';
                $rateLimitRemaining = $treeResult['rate_limit_remaining'] ?? null;
                $commitSha = $treeResult['commit_sha'] ?? '';
            } catch (\Throwable $e) {
                $errors[] = 'Ошибка получения данных из GitHub: ' . $e->getMessage();
                return $this->buildSection('Сравнение с GitHub', '🌐', $checks, $errors, $warnings);
            }
        } else {
            $method = 'cache (' . ($treeResult['method'] ?? 'unknown') . ')';
            $rateLimitRemaining = $treeResult['rate_limit_remaining'] ?? null;
            $commitSha = $treeResult['commit_sha'] ?? '';
        }

        $githubFiles = $treeResult['files'] ?? [];

        $checks[] = [
            'label' => 'Метод HTTP-запроса',
            'status' => 'ok',
            'value' => $method,
        ];

        if ($rateLimitRemaining !== null) {
            $checks[] = [
                'label' => 'Rate Limit Remaining',
                'status' => $rateLimitRemaining < 5 ? 'warning' : 'ok',
                'value' => (string)$rateLimitRemaining,
            ];
        }

        if ($commitSha) {
            $checks[] = [
                'label' => 'Commit SHA',
                'status' => 'ok',
                'value' => substr($commitSha, 0, 12) . '...',
            ];
        }

        // Сравниваем критические файлы
        $matched = 0;
        $modified = 0;
        $missing = 0;

        foreach (self::CRITICAL_FILES as $relativePath) {
            $localPath = $this->modulePath . '/' . $relativePath;

            if (!file_exists($localPath)) {
                $checks[] = [
                    'label' => $relativePath,
                    'status' => 'warning',
                    'value' => 'Файл отсутствует локально',
                ];
                $missing++;
                $warnings[] = 'Файл отсутствует локально: ' . $relativePath;
                continue;
            }

            $expectedSha = $githubFiles[$relativePath] ?? null;
            if ($expectedSha === null) {
                $checks[] = [
                    'label' => $relativePath,
                    'status' => 'warning',
                    'value' => 'Файл не найден в GitHub репозитории',
                ];
                $warnings[] = 'Файл не найден в GitHub: ' . $relativePath;
                continue;
            }

            $content = file_get_contents($localPath);
            $localSha = sha1('blob ' . strlen($content) . "\0" . $content);

            if ($localSha === $expectedSha) {
                $checks[] = [
                    'label' => $relativePath,
                    'status' => 'ok',
                    'value' => 'Совпадает',
                ];
                $matched++;
            } else {
                $checks[] = [
                    'label' => $relativePath,
                    'status' => 'warning',
                    'value' => 'Изменён (local: ' . substr($localSha, 0, 8) . ', github: ' . substr($expectedSha, 0, 8) . ')',
                ];
                $modified++;
                $warnings[] = 'Файл изменён относительно GitHub: ' . $relativePath;
            }
        }

        $total = $matched + $modified + $missing;
        $checks[] = [
            'label' => 'Итого',
            'status' => ($modified > 0 || $missing > 0) ? 'warning' : 'ok',
            'value' => sprintf('Совпадает: %d / Изменено: %d / Отсутствует: %d (всего: %d)', $matched, $modified, $missing, $total),
        ];

        return $this->buildSection('Сравнение с GitHub', '🌐', $checks, $errors, $warnings);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Вспомогательный метод построения секции
    // ─────────────────────────────────────────────────────────────────────────

    private function buildSection(
        string $name,
        string $icon,
        array $checks,
        array $errors = [],
        array $warnings = []
    ): array {
        return [
            'name' => $name,
            'icon' => $icon,
            'checks' => $checks,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
