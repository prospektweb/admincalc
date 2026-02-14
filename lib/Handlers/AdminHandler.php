<?php

namespace Prospektweb\Calc\Handlers;

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\Page\AssetLocation;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Highloadblock\HighloadBlockTable;

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
        if (!Loader::includeModule('prospektweb.calc') || !Loader::includeModule('iblock')) {
            return;
        }

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if (
            strpos($scriptName, '/bitrix/admin/iblock_element_edit.php') === false
            && strpos($scriptName, '/bitrix/admin/iblock_subelement_edit.php') === false
        ) {
            return;
        }

        $configManager = new \Prospektweb\Calc\Config\ConfigManager();
        $skuIblockId = $configManager->getSkuIblockId();
        $iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? $_REQUEST['iblock_id'] ?? 0);

        if ($skuIblockId <= 0 || $iblockId !== $skuIblockId) {
            return;
        }

        $property = \CIBlockProperty::GetList(
            [],
            [
                'IBLOCK_ID' => $skuIblockId,
                'CODE' => 'COMPLETED_CALCS',
                'ACTIVE' => 'Y',
            ]
        )->Fetch();

        if (!$property || (int)$property['ID'] <= 0) {
            return;
        }

        foreach ($tabControl->tabs as $tab) {
            if (($tab['DIV'] ?? '') === 'analysis') {
                return;
            }
        }

        $tabControl->tabs[] = [
            'DIV' => 'analysis',
            'TAB' => 'Анализ',
            'TITLE' => 'Анализ',
            'FIELDS' => [
                [
                    'id' => 'PROPERTY_' . (int)$property['ID'],
                ],
            ],
        ];
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

        if (!Loader::includeModule('prospektweb.calc') || !Loader::includeModule('iblock')) {
            return;
        }

        $configManager = new \Prospektweb\Calc\Config\ConfigManager();
        $skuIblockId = $configManager->getSkuIblockId();
        $iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? $_REQUEST['iblock_id'] ?? 0);
        $elementId = (int)($_REQUEST['ID'] ?? $_REQUEST['id'] ?? 0);

        if ($skuIblockId <= 0 || $iblockId !== $skuIblockId || $elementId <= 0) {
            return;
        }

		$iblockTypes = self::getModuleIblockTypes();

        $dashboardData = self::loadHistoryDataForOffer($elementId, $skuIblockId);

        $dashboardUrl = '/local/apps/prospektweb.calc/dashboard/index.html';
        if (!file_exists(Application::getDocumentRoot() . $dashboardUrl)) {
            $dashboardUrl = '/local/apps/prospektweb.calc/index.html';
        }

		$jsPath = '/local/js/prospektweb.calc/admin_element_links.js';
		
		$inlineJs = '<script>
			window. PROSPEKTWEB_CALC_IBLOCK_TYPES = ' . json_encode($iblockTypes, self::JSON_ENCODE_FLAGS) . ';
			window.PROSPEKTWEB_CALC_DASHBOARD_DATA = ' . json_encode($dashboardData, self::JSON_ENCODE_FLAGS) . ';
			window.PROSPEKTWEB_CALC_DASHBOARD_URL = ' . json_encode($dashboardUrl, self::JSON_ENCODE_FLAGS) . ';

			(function initCalcAnalysisTab(){
				if (window.__prospektwebAnalysisTabInitialized) {
					return;
				}
				window.__prospektwebAnalysisTabInitialized = true;

				function ensureTabContent() {
					var tab = document.getElementById("tab_cont_analysis");
					if (!tab) {
						return false;
					}

					if (document.getElementById("prospektweb-calc-analysis-dashboard")) {
						return true;
					}

					var wrapper = document.createElement("div");
					wrapper.id = "prospektweb-calc-analysis-dashboard";
					wrapper.style.marginTop = "12px";

					var title = document.createElement("div");
					title.style.marginBottom = "8px";
					title.style.fontWeight = "600";
					title.textContent = "Дашборд себестоимости";

					var iframe = document.createElement("iframe");
					iframe.id = "prospektweb-calc-analysis-iframe";
					iframe.src = window.PROSPEKTWEB_CALC_DASHBOARD_URL || "";
					iframe.style.width = "100%";
					iframe.style.minHeight = "760px";
					iframe.style.border = "1px solid #dce0e5";
					iframe.style.background = "#fff";

					iframe.addEventListener("load", function() {
						iframe.contentWindow.postMessage({
							type: "PROSPEKTWEB_CALC_DASHBOARD_INIT",
							offerId: window.PROSPEKTWEB_CALC_DASHBOARD_DATA.offerId || 0,
							history: window.PROSPEKTWEB_CALC_DASHBOARD_DATA.history || []
						}, "*");
					});

					wrapper.appendChild(title);
					wrapper.appendChild(iframe);
					tab.appendChild(wrapper);

					return true;
				}

				if (!ensureTabContent()) {
					var attempts = 0;
					var timer = setInterval(function() {
						attempts++;
						if (ensureTabContent() || attempts > 20) {
							clearInterval(timer);
						}
					}, 300);
				}
			})();
		</script>
		<script src="' . \CUtil::GetAdditionalFileURL($jsPath) . '"></script>';
		
		Asset::getInstance()->addString($inlineJs, false, AssetLocation::AFTER_JS);
		
	}

    /**
     * Получает историю расчётов из HL по ссылкам из свойства COMPLETED_CALCS.
     */
    private static function loadHistoryDataForOffer(int $offerId, int $skuIblockId): array
    {
        $result = [
            'offerId' => $offerId,
            'history' => [],
        ];

        if (!Loader::includeModule('highloadblock')) {
            return $result;
        }

        $hlblockId = (int)Option::get('prospektweb.calc', 'HIGHLOAD_CALC_HISTORY_ID', 0);
        if ($hlblockId <= 0) {
            return $result;
        }

        $propertyLinks = [];
        $rsProperty = \CIBlockElement::GetProperty(
            $skuIblockId,
            $offerId,
            ['SORT' => 'ASC'],
            ['CODE' => 'COMPLETED_CALCS']
        );

        while ($property = $rsProperty->Fetch()) {
            if (!empty($property['VALUE'])) {
                $propertyLinks[] = (string)$property['VALUE'];
            }
        }

        $propertyLinks = array_values(array_unique($propertyLinks));
        if (empty($propertyLinks)) {
            return $result;
        }

        $hlblock = HighloadBlockTable::getById($hlblockId)->fetch();
        if (!$hlblock) {
            return $result;
        }

        $entity = HighloadBlockTable::compileEntity($hlblock);
        $entityClass = $entity->getDataClass();

        $rows = $entityClass::getList([
            'filter' => [
                'UF_OFFER_ID' => $offerId,
            ],
            'order' => ['UF_DATETIME' => 'ASC', 'ID' => 'ASC'],
            'select' => ['ID', 'UF_XML_ID', 'UF_DATETIME', 'UF_USER_ID', 'UF_JSON'],
        ]);

        while ($row = $rows->fetch()) {
            $idLink = (string)($row['ID'] ?? '');
            $xmlLink = (string)($row['UF_XML_ID'] ?? '');
            if (!in_array($xmlLink, $propertyLinks, true) && !in_array($idLink, $propertyLinks, true)) {
                continue;
            }

            $json = [];
            if (!empty($row['UF_JSON']) && is_string($row['UF_JSON'])) {
                $decoded = json_decode($row['UF_JSON'], true);
                if (is_array($decoded)) {
                    $json = $decoded;
                }
            }

            $result['history'][] = [
                'id' => (int)$row['ID'],
                'xmlId' => (string)($row['UF_XML_ID'] ?? ''),
                'dateTime' => isset($row['UF_DATETIME']) ? (string)$row['UF_DATETIME'] : null,
                'userId' => isset($row['UF_USER_ID']) ? (int)$row['UF_USER_ID'] : null,
                'json' => $json,
            ];
        }

        return $result;
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
