<?php

namespace Prospektweb\Calc\Handlers;

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\Page\AssetLocation;
use Bitrix\Main\Application;

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
            self::addCalculatorButton();
        }

        // Также добавляем на странице списка элементов (для кнопки в тулбаре)
        if ($isListPage || $isSidepanelList) {
            self::addCalculatorButton();
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
		
		// Настройка вкладки "Анализ" для инфоблока ТП
		self::configureAnalysisTab();
	}

	/**
	 * Настройка вкладки "Анализ" в карточке ТП через inline JS
	 */
	private static function configureAnalysisTab(): void
	{
		if (!Loader::includeModule('prospektweb.calc')) {
			return;
		}
		
		global $USER;
		$configManager = new \Prospektweb\Calc\Config\ConfigManager();
		$skuIblockId = $configManager->getSkuIblockId();
		
		if ($skuIblockId <= 0) {
			return;
		}
		
		$userId = $USER ? (int)$USER->GetID() : 0;
		if ($userId <= 0) {
			return;
		}
		
		// Проверяем, что мы на странице редактирования элемента нужного инфоблока
		$iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? $_REQUEST['iblock_id'] ?? 0);
		
		if ($iblockId !== $skuIblockId) {
			return;
		}
		
		// Добавляем inline JS для программной настройки вкладки "Анализ"
		$inlineJs = '<script>
		BX.ready(function() {
			// Функция для настройки вкладки "Анализ"
			function setupAnalysisTab() {
				// Ищем форму редактирования элемента
				var form = document.querySelector(\'form[name="form_element_' . $skuIblockId . '"]\');
				if (!form) {
					// Возможно, форма ещё не загружена, попробуем позже
					setTimeout(setupAnalysisTab, 500);
					return;
				}
				
				// Проверяем, существует ли уже вкладка "Анализ"
				var existingTab = null;
				var tabControl = document.querySelector(\'.adm-detail-tab-list\');
				if (tabControl) {
					var tabs = tabControl.querySelectorAll(\'.adm-detail-tab\');
					for (var i = 0; i < tabs.length; i++) {
						if (tabs[i].textContent.indexOf(\'Анализ\') !== -1) {
							existingTab = tabs[i];
							break;
						}
					}
				}
				
				// Если вкладка уже есть, ничего не делаем
				if (existingTab) {
					return;
				}
				
				// Ищем свойство COMPLETED_CALCS
				var completedCalcsRow = null;
				var allRows = form.querySelectorAll(\'tr[id^="tr_PROPERTY_"]\');
				for (var i = 0; i < allRows.length; i++) {
					if (allRows[i].id.indexOf(\'COMPLETED_CALCS\') !== -1) {
						completedCalcsRow = allRows[i];
						break;
					}
				}
				
				if (!completedCalcsRow) {
					// Свойство не найдено, возможно ещё не создано
					return;
				}
				
				// Создаём вкладку "Анализ" программно через добавление в DOM
				if (tabControl) {
					var newTab = document.createElement(\'span\');
					newTab.className = \'adm-detail-tab\';
					newTab.setAttribute(\'data-tab-id\', \'tab_analysis\');
					newTab.innerHTML = \'Анализ\';
					
					// Добавляем вкладку в конец списка
					tabControl.appendChild(newTab);
					
					// Создаём контейнер для содержимого вкладки
					var tabContent = document.createElement(\'div\');
					tabContent.id = \'tab_analysis_content\';
					tabContent.className = \'adm-detail-content\';
					tabContent.style.display = \'none\';
					
					// Переносим свойство COMPLETED_CALCS в новую вкладку
					tabContent.appendChild(completedCalcsRow);
					
					// Находим место для вставки контента вкладки
					var tabsContainer = form.querySelector(\'.adm-detail-tabs-block\');
					if (tabsContainer) {
						tabsContainer.appendChild(tabContent);
					}
					
					// Добавляем обработчик клика для переключения вкладок
					newTab.addEventListener(\'click\', function() {
						// Скрываем все вкладки
						var allTabs = tabControl.querySelectorAll(\'.adm-detail-tab\');
						var allContents = form.querySelectorAll(\'.adm-detail-content\');
						
						for (var i = 0; i < allTabs.length; i++) {
							allTabs[i].classList.remove(\'adm-detail-tab-active\');
						}
						for (var i = 0; i < allContents.length; i++) {
							allContents[i].style.display = \'none\';
						}
						
						// Показываем выбранную вкладку
						newTab.classList.add(\'adm-detail-tab-active\');
						tabContent.style.display = \'block\';
					});
				}
			}
			
			// Запускаем настройку вкладки
			setupAnalysisTab();
		});
		</script>';
		
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
