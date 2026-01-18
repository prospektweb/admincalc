<?php

namespace Prospektweb\Calc\Handlers;

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\Page\AssetLocation;
use Bitrix\Main\Application;
use Prospektweb\Calc\Services\HeaderTabsService;

/**
 * Обработчик для добавления кнопки/вкладки в админку.
 */
class AdminHandler
{
    /**
     * Флаги для безопасного JSON кодирования в HTML/JS контексте
     */
    private const JSON_ENCODE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

    /**
     * Обработчик события OnProlog.
     * Добавляет JS для кнопки "Калькуляция" в админку.
     */
    public static function onProlog(): void
    {
        if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true) {
            return;
        }

        $asset = Asset::getInstance();

        // Проверяем, что мы на странице редактирования элемента инфоблока
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $requestUrl = $_REQUEST['url'] ?? '';
        $gridId = $_REQUEST['grid_id'] ?? '';
        $iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? $_REQUEST['iblock_id'] ?? $_REQUEST['PARENT'] ?? 0);

        // Диагностическое логирование
        $debugInfo = [
            'scriptName' => $scriptName,
            'requestUrl' => $requestUrl,
            'gridId' => $gridId,
            'iblockId' => $iblockId,
        ];
        
        $asset->addString(
            '<script>console.group("ProspektwebCalc Debug - onProlog"); console.log(' . json_encode($debugInfo, self::JSON_ENCODE_FLAGS) . '); console.groupEnd();</script>',
            false,
            AssetLocation::AFTER_JS
        );

        // Проверяем страницы редактирования элемента
        $isEditPage = strpos($scriptName, '/bitrix/admin/iblock_element_edit.php') !== false;
        $isListPage = strpos($scriptName, '/bitrix/admin/iblock_list_admin.php') !== false;
        
        // Проверяем сайдпанель
        $isSidepanel = strpos($scriptName, 'ui_sidepanel') !== false 
            || strpos($scriptName, 'ui_sidepanel_workarea.php') !== false;
        
        $isSidepanelEdit = $isSidepanel && (
            strpos($requestUrl, 'iblock_element_edit.php') !== false ||
            strpos($gridId, 'iblock_element_edit') !== false
        );
        
        $isSidepanelList = $isSidepanel && (
            strpos($requestUrl, 'iblock_list_admin.php') !== false ||
            strpos($gridId, 'iblock_list_admin') !== false
        );

        if ($isEditPage || $isSidepanelEdit) {
            if (self::isHeaderIblockPage()) {
                self::addHeaderTabsAction();
            }

            self::addCalculatorButton();
        }

        // Также добавляем на странице списка элементов (для кнопки в тулбаре)
        if ($isListPage || $isSidepanelList) {
            self::addCalculatorButton();
            self::addHeaderTabsAction();
        }
    }

    /**
     * Добавляет JS и CSS для кнопки калькуляции.
     */
    protected static function addCalculatorButton(): void
    {
        $asset = Asset::getInstance();
        
        // Безопасное экранирование SITE_ID для JavaScript через JSON
        $siteId = json_encode(SITE_ID, self::JSON_ENCODE_FLAGS);
        $asset->addString('<script>BX.message({ SITE_ID: ' . $siteId . ' });</script>', 
            false, \Bitrix\Main\Page\AssetLocation::AFTER_JS_KERNEL);
        
        // Добавляем CSS
        $cssPath = '/local/css/prospektweb.calc/calculator.css';
        if (file_exists(Application::getDocumentRoot() . $cssPath)) {
            $asset->addCss($cssPath);
        }

        // Добавляем integration.js перед calculator.js (для поддержки нового протокола postMessage)
        $jsIntegrationPath = '/local/js/prospektweb.calc/integration.js';
        if (file_exists(Application::getDocumentRoot() . $jsIntegrationPath)) {
            $asset->addJs($jsIntegrationPath);
        }

        // Добавляем JS
        $jsPath = '/local/js/prospektweb.calc/calculator.js';
        if (file_exists(Application::getDocumentRoot() . $jsPath)) {
            $asset->addJs($jsPath);
        }

        // Добавляем встроенный JS для инициализации кнопки
        $asset->addString('<script>
            BX.ready(function() {
                if (typeof window.ProspekwebCalc !== "undefined" && window.ProspekwebCalc.init) {
                    window.ProspekwebCalc.init();
                }
            });
        </script>', false, AssetLocation::AFTER_JS);
    }

    /**
     * Добавляет JS для массового действия "Использовать в калькуляции".
     */
    protected static function addHeaderTabsAction(): void
    {
        $asset = Asset::getInstance();
        
        // Собираем отладочную информацию
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? $_REQUEST['iblock_id'] ?? $_REQUEST['PARENT'] ?? 0);
        
        $debugLog = [
            'method' => 'addHeaderTabsAction',
            'scriptName' => $scriptName,
            'iblockId' => $iblockId,
        ];

        $service = new HeaderTabsService();
        $entityMap = $service->getHeaderIblockMap();
        
        $debugLog['entityMap'] = $entityMap;
        $debugLog['entityMapEmpty'] = empty($entityMap);

        if (empty($entityMap)) {
            $debugLog['exitReason'] = 'entityMap is empty';
            $asset->addString(
                '<script>console.group("ProspektwebCalc Debug - addHeaderTabsAction"); console.log(' . json_encode($debugLog, self::JSON_ENCODE_FLAGS) . '); console.groupEnd();</script>',
                false,
                AssetLocation::AFTER_JS
            );
            return;
        }

        // Определяем тип сущности для текущего инфоблока
        $currentEntity = null;
        foreach ($entityMap as $entityType => $entityIblockId) {
            if ((int)$entityIblockId === $iblockId) {
                $currentEntity = $entityType;
                break;
            }
        }

        $debugLog['currentEntity'] = $currentEntity;

        if (!$currentEntity) {
            $debugLog['exitReason'] = 'Current iblock not found in entityMap';
            $asset->addString(
                '<script>console.group("ProspektwebCalc Debug - addHeaderTabsAction"); console.log(' . json_encode($debugLog, self::JSON_ENCODE_FLAGS) . '); console.groupEnd();</script>',
                false,
                AssetLocation::AFTER_JS
            );
            return;
        }

        // Определяем текущую страницу
        $isListPage = strpos($scriptName, 'iblock_list_admin.php') !== false;
        $isEditPage = strpos($scriptName, 'iblock_element_edit.php') !== false;

        $debugLog['isListPage'] = $isListPage;
        $debugLog['isEditPage'] = $isEditPage;

        $iblockCode = null;
        $iblockType = null;

        if (Loader::includeModule('iblock')) {
            $iblockData = \CIBlock::GetByID($iblockId)->Fetch();
            if ($iblockData) {
                $iblockCode = $iblockData['CODE'] ?? null;
                $iblockType = $iblockData['IBLOCK_TYPE_ID'] ?? null;
            } else {
                $debugLog['iblockLookupReason'] = 'CIBlock::GetByID returned no data';
            }
        } else {
            $debugLog['iblockLookupReason'] = 'iblock module is not installed';
        }

        $debugLog['iblockCode'] = $iblockCode;
        $debugLog['iblockType'] = $iblockType;

        // Список типов сущностей, для которых показываем кнопку в списке
        // Варианты и оборудование
        $variantEntityTypes = [
            'detailsVariants',
            'materialsVariants',
            'operationsVariants',
            'equipment',
        ];

        // Список родительских типов сущностей (детали, материалы, операции)
        // Для них кнопка показывается только на странице редактирования (в табе ТП)
        $parentEntityTypes = [
            'details',
            'materials',
            'operations',
        ];

        $allowedCodesForList = [
            'CALC_MATERIALS_VARIANTS',
            'CALC_OPERATIONS_VARIANTS',
            'CALC_EQUIPMENT',
        ];

        $allowedCodesForEdit = [
            'CALC_DETAILS',
            'CALC_MATERIALS',
            'CALC_OPERATIONS',
        ];

        $showButton = false;

        if ($isListPage) {
            // В списке показываем только для вариантов и оборудования
            $isAllowedIblock = in_array($iblockCode, $allowedCodesForList, true)
                || in_array($iblockType, $allowedCodesForList, true);

            if (!$isAllowedIblock) {
                $debugLog['exitReason'] = 'List page - iblock code/type is not allowed';
                $asset->addString(
                    '<script>console.group("ProspektwebCalc Debug - addHeaderTabsAction"); console.log(' . json_encode($debugLog, self::JSON_ENCODE_FLAGS) . '); console.groupEnd();</script>',
                    false,
                    AssetLocation::AFTER_JS
                );
                return;
            }

            $showButton = in_array($currentEntity, $variantEntityTypes, true);
            if ($showButton) {
                $debugLog['showButtonReason'] = 'List page - entity is variant or equipment';
            } else {
                $debugLog['showButtonReason'] = 'List page - entity is parent (not showing)';
            }
        } elseif ($isEditPage) {
            // На странице редактирования показываем для родительских сущностей
            // (кнопка будет во вкладке "Торговые предложения")
            $isAllowedIblock = in_array($iblockCode, $allowedCodesForEdit, true)
                || in_array($iblockType, $allowedCodesForEdit, true);

            if (!$isAllowedIblock) {
                $debugLog['exitReason'] = 'Edit page - iblock code/type is not allowed';
                $asset->addString(
                    '<script>console.group("ProspektwebCalc Debug - addHeaderTabsAction"); console.log(' . json_encode($debugLog, self::JSON_ENCODE_FLAGS) . '); console.groupEnd();</script>',
                    false,
                    AssetLocation::AFTER_JS
                );
                return;
            }

            $showButton = in_array($currentEntity, $parentEntityTypes, true);
            if ($showButton) {
                $debugLog['showButtonReason'] = 'Edit page - entity is parent (show for SKU tab)';
            } else {
                $debugLog['showButtonReason'] = 'Edit page - entity is not parent';
            }
        }

        $debugLog['showButton'] = $showButton;

        if (!$showButton) {
            $debugLog['exitReason'] = 'Button should not be shown for this page/entity combination';
            $asset->addString(
                '<script>console.group("ProspektwebCalc Debug - addHeaderTabsAction"); console.log(' . json_encode($debugLog, self::JSON_ENCODE_FLAGS) . '); console.groupEnd();</script>',
                false,
                AssetLocation::AFTER_JS
            );
            return;
        }

        $jsPath = '/local/js/prospektweb.calc/header-tabs-sync.js';
        $jsFullPath = Application::getDocumentRoot() . $jsPath;
        $jsFileExists = file_exists($jsFullPath);
        
        $debugLog['jsPath'] = $jsPath;
        $debugLog['jsFullPath'] = $jsFullPath;
        $debugLog['jsFileExists'] = $jsFileExists;

        // Проверяем установку модуля (для диагностики)
        // Помогает убедиться, что модуль корректно зарегистрирован в системе
        $moduleInstalled = Loader::includeModule('prospektweb.calc');
        $debugLog['moduleInstalled'] = $moduleInstalled;

        $config = [
            'ajaxEndpoint' => '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
            'entityMap' => $entityMap,
            'actionValue' => 'calc_use_in_header',
            'messages' => [
                'actionTitle' => 'Использовать в калькуляции',
            ],
            'sessid' => bitrix_sessid(),
        ];

        // Добавляем skuIblockId для страниц редактирования родительских сущностей
        if ($isEditPage && in_array($currentEntity, $parentEntityTypes, true)) {
            // Определяем инфоблок вариантов по текущему родительскому
            // Используем стандартный паттерн именования: {entityName}Variants
            $skuEntityType = $currentEntity . 'Variants';
            if (isset($entityMap[$skuEntityType]) && (int)$entityMap[$skuEntityType] > 0) {
                $config['skuIblockId'] = (int)$entityMap[$skuEntityType];
                $debugLog['skuIblockId'] = $config['skuIblockId'];
                $debugLog['skuEntityType'] = $skuEntityType;
            } else {
                $debugLog['skuEntityType'] = $skuEntityType . ' (not found in entityMap)';
            }
        }

        $debugLog['configAdded'] = true;
        $debugLog['exitReason'] = 'success - config and JS added';

        // СНАЧАЛА добавляем конфиг (должен быть в DOM раньше чем JS-файл)
        // Используем AssetLocation::AFTER_JS_KERNEL - это гарантирует вывод сразу после ядра Bitrix,
        // до обычных JS-файлов подключенных через addJs() (которые выводятся позже в зоне AFTER_JS)
        $asset->addString(
            '<script>window.ProspektwebCalcHeaderTabsConfig = ' .
            json_encode($config, self::JSON_ENCODE_FLAGS) .
            ';</script>',
            false,
            AssetLocation::AFTER_JS_KERNEL
        );

        // ПОТОМ подключаем JS-файл через addJs() без явного AssetLocation
        // Он будет выведен в дефолтной зоне (после AFTER_JS_KERNEL), поэтому конфиг уже будет доступен
        if ($jsFileExists) {
            $asset->addJs($jsPath);
        }

        // Выводим полный лог в консоль
        $asset->addString(
            '<script>console.group("ProspektwebCalc Debug - addHeaderTabsAction"); console.log(' . json_encode($debugLog, self::JSON_ENCODE_FLAGS) . '); console.groupEnd();</script>',
            false,
            AssetLocation::AFTER_JS
        );
    }

    /**
     * Получает параметры для инициализации калькулятора.
     *
     * @return array
     */
    public static function getCalculatorParams(): array
    {
        if (!Loader::includeModule('prospektweb.calc')) {
            return [];
        }

        return [
            'moduleInstalled' => true,
            'apiEndpoint' => '/local/modules/prospektweb.calc/tools/',
        ];
    }

    /**
     * Обработчик события OnAdminTabControlBegin.
     *
     * @param \CAdminTabControl $tabControl Объект управления вкладками.
     */
    public static function onTabControlBegin(\CAdminTabControl &$tabControl): void
    {
        // Можно добавить вкладку калькулятора при редактировании товара
    }

    /**
     * Обработчик события OnAdminListDisplay.
     *
     * @param \CAdminList $adminList Объект списка.
     */
    public static function onAdminListDisplay(\CAdminList &$adminList): void
    {
        // Можно добавить кнопку массовой калькуляции в список элементов
    }

    /**
     * Проверяет, относится ли текущая страница к поддерживаемым инфоблокам шапки калькуляции.
     */
    protected static function isHeaderIblockPage(): bool
    {
        $asset = Asset::getInstance();
        
        $service = new HeaderTabsService();
        $iblockMap = $service->getHeaderIblockMap();

        $iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? $_REQUEST['iblock_id'] ?? $_REQUEST['PARENT'] ?? 0);
        
        $debugLog = [
            'method' => 'isHeaderIblockPage',
            'iblockId' => $iblockId,
            'iblockMap' => $iblockMap,
            'iblockMapEmpty' => empty($iblockMap),
        ];

        if (empty($iblockMap)) {
            $debugLog['result'] = false;
            $debugLog['reason'] = 'iblockMap is empty';
            $asset->addString(
                '<script>console.group("ProspektwebCalc Debug - isHeaderIblockPage"); console.log(' . json_encode($debugLog, self::JSON_ENCODE_FLAGS) . '); console.groupEnd();</script>',
                false,
                AssetLocation::AFTER_JS
            );
            return false;
        }

        $result = in_array($iblockId, array_map('intval', $iblockMap), true);
        $debugLog['result'] = $result;
        $debugLog['reason'] = $result ? 'iblockId found in map' : 'iblockId NOT found in map';
        
        $asset->addString(
            '<script>console.group("ProspektwebCalc Debug - isHeaderIblockPage"); console.log(' . json_encode($debugLog, self::JSON_ENCODE_FLAGS) . '); console.groupEnd();</script>',
            false,
            AssetLocation::AFTER_JS
        );

        return $result;
    }

    /**
     * Подключение JS для улучшения формы редактирования элементов
     * Добавляет ссылки на связанные элементы (свойства типа E)
     */
	public static function onBeforeEndBufferContent(): void
	{
		global $APPLICATION;
		
		if (! defined('ADMIN_SECTION') || ADMIN_SECTION !== true) {
			return;
		}
		
		$currentPage = $APPLICATION->GetCurPage();
		if (
			strpos($currentPage, 'iblock_element_edit.php') === false
			&& strpos($currentPage, 'iblock_subelement_edit.php') === false
		) {
			return;
		}

		$iblockTypes = self::getModuleIblockTypes();

		$jsPath = '/local/js/prospektweb.calc/admin_element_links.js';
		
		$inlineJs = '<script>
			window. PROSPEKTWEB_CALC_IBLOCK_TYPES = ' . json_encode($iblockTypes, self::JSON_ENCODE_FLAGS) . ';
		</script>
		<script src="' . \CUtil::GetAdditionalFileURL($jsPath) . '"></script>';
		
		Asset::getInstance()->addString($inlineJs, false, AssetLocation::AFTER_JS);
	}

    /**
     * Получить типы инфоблоков модуля
     * 
     * @return array Массив [iblock_id => type]
     */
    private static function getModuleIblockTypes(): array
    {
        if (!Loader::includeModule('prospektweb.calc')) {
            return [];
        }

        $types = [];
        $configManager = new \Prospektweb\Calc\Config\ConfigManager();
        
        // Получаем все ID инфоблоков модуля
        $moduleIblocks = $configManager->getAllIblockIds();
        
        // Карта типов инфоблоков (соответствует ConfigManager::IBLOCK_TYPES)
        $iblockTypes = [
            'CALC_PRESETS' => 'calculator',
            'CALC_STAGES' => 'calculator_catalog',
            'CALC_SETTINGS' => 'calculator',
            'CALC_CUSTOM_FIELDS' => 'calculator',
            'CALC_MATERIALS' => 'calculator_catalog',
            'CALC_MATERIALS_VARIANTS' => 'calculator_catalog',
            'CALC_OPERATIONS' => 'calculator_catalog',
            'CALC_OPERATIONS_VARIANTS' => 'calculator_catalog',
            'CALC_EQUIPMENT' => 'calculator_catalog',
            'CALC_DETAILS' => 'calculator_catalog',
        ];
        
        foreach ($moduleIblocks as $code => $iblockId) {
            if ($iblockId > 0 && isset($iblockTypes[$code])) {
                $types[$iblockId] = $iblockTypes[$code];
            }
        }
        
        return $types;
    }
}
