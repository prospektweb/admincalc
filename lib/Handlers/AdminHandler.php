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
        $iblockId = self::resolveCurrentIblockId();

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
        $iblockId = self::resolveCurrentIblockId();
        $elementId = (int)($_REQUEST['ID'] ?? $_REQUEST['id'] ?? 0);

        if ($skuIblockId <= 0 || $iblockId !== $skuIblockId || $elementId <= 0) {
            return;
        }

		$iblockTypes = self::getModuleIblockTypes();

        $dashboardUrl = '/local/apps/prospektweb.calc/dashboard.html';
        $dashboardDataEndpoint = '/bitrix/tools/prospektweb.calc/history_dashboard_data.php';
        $sessid = bitrix_sessid();

		$jsPath = '/local/js/prospektweb.calc/admin_element_links.js';
		
		$inlineJs = '<script>
			window. PROSPEKTWEB_CALC_IBLOCK_TYPES = ' . json_encode($iblockTypes, self::JSON_ENCODE_FLAGS) . ';
			window.PROSPEKTWEB_CALC_DASHBOARD_URL = ' . json_encode($dashboardUrl, self::JSON_ENCODE_FLAGS) . ';
			window.PROSPEKTWEB_CALC_DASHBOARD_ENDPOINT = ' . json_encode($dashboardDataEndpoint, self::JSON_ENCODE_FLAGS) . ';
			window.PROSPEKTWEB_CALC_ELEMENT_ID = ' . json_encode($elementId, self::JSON_ENCODE_FLAGS) . ';
			window.PROSPEKTWEB_CALC_SESSID = ' . json_encode($sessid, self::JSON_ENCODE_FLAGS) . ';

			(function initCalcAnalysisTab(){
				if (window.__prospektwebAnalysisTabInitialized) {
					return;
				}
				window.__prospektwebAnalysisTabInitialized = true;

				var isLoading = false;
				var lastPayloadHash = "";

				function getAnalysisBlock() {
					var root = document.getElementById("analysis") || document.getElementById("tab_cont_analysis");
					if (!root) {
						return false;
					}
					var block = root.querySelector(".adm-detail-content-item-block") || root;
					return block;
				}

				function isAnalysisTabOpen() {
					var analysis = document.getElementById("analysis") || document.getElementById("tab_cont_analysis");
					if (!analysis) {
						return false;
					}

					var style = window.getComputedStyle(analysis);
					return style.display !== "none" && style.visibility !== "hidden";
				}

				function ensureTabContent() {
					var block = getAnalysisBlock();
					if (!block) {
						return false;
					}

					if (document.getElementById("prospektweb-calc-analysis-dashboard")) {
						return true;
					}

					var wrapper = document.createElement("div");
					wrapper.id = "prospektweb-calc-analysis-dashboard";
					wrapper.style.marginBottom = "12px";

					var iframe = document.createElement("iframe");
					iframe.id = "prospektweb-calc-analysis-iframe";
					iframe.style.width = "100%";
					iframe.style.minHeight = "760px";
					iframe.style.border = "1px solid #dce0e5";
					iframe.style.background = "#fff";
					iframe.loading = "lazy";
					wrapper.appendChild(iframe);
					block.insertBefore(wrapper, block.firstChild);

					return true;
				}

				function loadDashboardDataAndRender() {
					if (!isAnalysisTabOpen()) {
						return;
					}

					if (!ensureTabContent()) {
						return;
					}

					if (isLoading) {
						return;
					}

					var iframe = document.getElementById("prospektweb-calc-analysis-iframe");
					if (!iframe) {
						return;
					}

					isLoading = true;
					var endpoint = window.PROSPEKTWEB_CALC_DASHBOARD_ENDPOINT || "";
					var elementId = window.PROSPEKTWEB_CALC_ELEMENT_ID || 0;
					var sessid = window.PROSPEKTWEB_CALC_SESSID || "";
					var url = endpoint + "?offerId=" + encodeURIComponent(elementId) + "&sessid=" + encodeURIComponent(sessid);

					fetch(url, { credentials: "same-origin" })
						.then(function(response){ return response.json(); })
						.then(function(payload){
							var payloadHash = JSON.stringify(payload || {});
							if (iframe.getAttribute("src") !== (window.PROSPEKTWEB_CALC_DASHBOARD_URL || "")) {
								iframe.setAttribute("src", window.PROSPEKTWEB_CALC_DASHBOARD_URL || "");
							}

							iframe.onload = function() {
								console.log("[PROSPEKTWEB][ANALYSIS_IFRAME_PAYLOAD]", payload);
								iframe.contentWindow.postMessage({
									type: "PROSPEKTWEB_CALC_DASHBOARD_INIT",
									offerId: payload.offerId || 0,
									history: payload.history || []
								}, "*");
							};

							if (lastPayloadHash === payloadHash && iframe.contentWindow) {
								console.log("[PROSPEKTWEB][ANALYSIS_IFRAME_PAYLOAD]", payload);
								iframe.contentWindow.postMessage({
									type: "PROSPEKTWEB_CALC_DASHBOARD_INIT",
									offerId: payload.offerId || 0,
									history: payload.history || []
								}, "*");
							}

							lastPayloadHash = payloadHash;
						})
						.catch(function(error){
							console.error("[PROSPEKTWEB][ANALYSIS_IFRAME_ERROR]", error);
						})
						.finally(function(){
							isLoading = false;
						});
				}

				document.addEventListener("click", function() {
					setTimeout(loadDashboardDataAndRender, 120);
				});

				var observer = new MutationObserver(function() {
					loadDashboardDataAndRender();
				});
				var analysisNode = document.getElementById("analysis") || document.getElementById("tab_cont_analysis");
				if (analysisNode) {
					observer.observe(analysisNode, { attributes: true, attributeFilter: ["style", "class"] });
				}

				setTimeout(loadDashboardDataAndRender, 200);
			})();
		</script>
		<script src="' . \CUtil::GetAdditionalFileURL($jsPath) . '"></script>';
		
		Asset::getInstance()->addString($inlineJs, false, AssetLocation::AFTER_JS);
		
	}

    private static function resolveCurrentIblockId(): int
    {
        $iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? $_REQUEST['iblock_id'] ?? 0);
        if ($iblockId > 0) {
            return $iblockId;
        }

        $query = (string)($_SERVER['QUERY_STRING'] ?? '');
        if ($query !== '') {
            parse_str($query, $queryParams);
            $iblockId = (int)($queryParams['IBLOCK_ID'] ?? $queryParams['iblock_id'] ?? 0);
            if ($iblockId > 0) {
                return $iblockId;
            }
        }

        $elementId = (int)($_REQUEST['ID'] ?? $_REQUEST['id'] ?? 0);
        if ($elementId > 0 && Loader::includeModule('iblock')) {
            $element = \CIBlockElement::GetList([], ['ID' => $elementId], false, false, ['ID', 'IBLOCK_ID'])->Fetch();
            if (!empty($element['IBLOCK_ID'])) {
                return (int)$element['IBLOCK_ID'];
            }
        }

        return 0;
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

    /**
     * Обработчик события OnBuildGlobalMenu для добавления пункта меню
     * 
     * @param array $aGlobalMenu Глобальное меню
     * @param array $aModuleMenu Меню модулей
     */
    public static function onBuildGlobalMenu(&$aGlobalMenu, &$aModuleMenu): void
    {
        if (!Loader::includeModule('prospektweb.calc')) {
            return;
        }

        global $USER;
        if (!$USER || !$USER->IsAdmin()) {
            return;
        }

        $aModuleMenu[] = [
            'parent_menu' => 'global_menu_services',
            'sort' => 500,
            'text' => 'Пересчёт калькуляций',
            'title' => 'Массовый пересчёт калькуляций торговых предложений',
            'url' => 'prospektweb_calc_recalculate.php',
            'icon' => 'util_menu_icon',
            'more_url' => [],
            'items_id' => 'menu_prospektweb_calc_recalculate',
        ];
    }
}
