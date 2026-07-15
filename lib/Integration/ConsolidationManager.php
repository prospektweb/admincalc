<?php

namespace Prospektweb\Calc\Integration;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use RuntimeException;

final class ConsolidationManager
{
    public const MODULE_ID = 'prospektweb.calc';
    public const VERSION = '1';

    /** @return array<string, mixed> */
    public function apply(): array
    {
        $report = [];
        $report['options'] = (new OptionMigrator())->migrate();
        $report['files'] = (new ManagedFileInstaller())->install($this->getPublicWrappers());
        $report['events'] = $this->registerEvents();
        $report['database'] = $this->ensureDatabase();
        $report['agent'] = $this->ensureCleanupAgent();

        Option::set(self::MODULE_ID, 'CONSOLIDATION_VERSION', self::VERSION);
        Option::set(self::MODULE_ID, 'CONSOLIDATION_APPLIED_AT', date('c'));

        return $report;
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return [
            'version' => (string)Option::get(self::MODULE_ID, 'CONSOLIDATION_VERSION', ''),
            'applied_at' => (string)Option::get(self::MODULE_ID, 'CONSOLIDATION_APPLIED_AT', ''),
            'managed_files' => (new ManagedFileInstaller())->getManifest(),
        ];
    }

    /** @return array<string, string> */
    private function getPublicWrappers(): array
    {
        return [
            '/local/ajax/frontcalc.php' => $this->wrapper('features/frontend/ajax/frontcalc.php'),
            '/bitrix/admin/prospektweb_frontcalc_editor.php' => $this->wrapper('features/frontend/admin/editor.php'),
            '/bitrix/admin/prospektweb_calc_property_values.php' => $this->wrapper('features/property_values/admin/property_values.php'),
            '/bitrix/admin/prospektweb_calc_orders.php' => $this->wrapper('features/orders/admin/orders.php'),
            '/local/tools/prospekt_layout/ajax.php' => $this->wrapper('features/orders/tools/prospekt_layout/ajax.php'),
            '/local/tools/prospekt_layout/download.php' => $this->wrapper('features/orders/tools/prospekt_layout/download.php'),
            '/local/tools/prospekt_layout/oauth_start.php' => $this->wrapper('features/orders/tools/prospekt_layout/oauth_start.php'),
            '/local/tools/prospekt_layout/desired_receive_date.php' => $this->wrapper('features/orders/tools/prospekt_layout/desired_receive_date.php'),
        ];
    }

    private function wrapper(string $relativeModuleFile): string
    {
        $relativeModuleFile = ltrim($relativeModuleFile, '/');

        return "<?php\n"
            . "\$local = \$_SERVER['DOCUMENT_ROOT'] . '/local/modules/prospektweb.calc/{$relativeModuleFile}';\n"
            . "\$bitrix = \$_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/prospektweb.calc/{$relativeModuleFile}';\n"
            . "\$source = is_file(\$local) ? \$local : \$bitrix;\n"
            . "if (!is_file(\$source)) { http_response_code(500); die('prospektweb.calc endpoint not found'); }\n"
            . "require \$source;\n";
    }

    /** @return string[] */
    private function registerEvents(): array
    {
        $events = [
            ['main', 'OnAdminContextMenuShow', '\\Prospektweb\\Frontcalc\\Admin\\ProductCardButton', 'onAdminContextMenuShow'],
            ['main', 'OnEpilog', '\\Prospektweb\\Frontcalc\\Service\\FrontendAssets', 'onEpilog'],
            ['main', 'OnEndBufferContent', '\\Prospektweb\\PropValManager\\Service\\AdminPropertySettingsExtension', 'onEndBufferContent'],
            ['main', 'OnEndBufferContent', '\\Prospektweb\\PropValManager\\Service\\PublicJsonConfigExtension', 'onEndBufferContent'],
            ['main', 'OnBeforeProlog', '\\Prospektweb\\OfferFilter\\OfferFilter', 'onBeforeProlog'],
            ['main', 'OnEpilog', '\\Prospektweb\\LayoutFiles\\BasketAssets', 'onEpilog'],
            ['sale', 'OnSaleOrderSaved', '\\Prospektweb\\LayoutFiles\\EventHandlers', 'onSaleOrderSaved'],
            ['sale', 'OnSaleBasketItemSaved', '\\Prospektweb\\LayoutFiles\\EventHandlers', 'onSaleBasketItemSaved'],
        ];

        $legacyModules = [
            'prospektweb.frontcalc',
            'prospektweb.propvalmanager',
            'prospektweb.offerfilter',
            'prospektweb.layoutfiles',
        ];
        $eventManager = EventManager::getInstance();
        $report = [];

        foreach ($events as [$fromModule, $event, $class, $method]) {
            foreach ($legacyModules as $legacyModule) {
                $eventManager->unRegisterEventHandler($fromModule, $event, $legacyModule, $class, $method);
            }
            $eventManager->unRegisterEventHandler($fromModule, $event, self::MODULE_ID, $class, $method);
            $eventManager->registerEventHandlerCompatible($fromModule, $event, self::MODULE_ID, $class, $method);
            $report[] = $fromModule . ':' . $event . ' -> ' . $class . '::' . $method;
        }

        return $report;
    }

    /** @return array<string, string> */
    private function ensureDatabase(): array
    {
        $report = [];
        $connection = Application::getConnection();

        if (!$connection->isTableExists('b_prospekt_layout_files')) {
            $connection->queryExecute("CREATE TABLE b_prospekt_layout_files (
                ID int(11) unsigned NOT NULL AUTO_INCREMENT,
                SITE_ID varchar(2) NOT NULL,
                FUSER_ID int(11) unsigned NOT NULL DEFAULT 0,
                USER_ID int(11) unsigned NOT NULL DEFAULT 0,
                BASKET_ID int(11) unsigned NOT NULL DEFAULT 0,
                ORDER_ID int(11) unsigned NOT NULL DEFAULT 0,
                ORDER_BASKET_ID int(11) unsigned NOT NULL DEFAULT 0,
                PRODUCT_ID int(11) unsigned NOT NULL DEFAULT 0,
                ORIGINAL_NAME varchar(255) NOT NULL,
                STORAGE_NAME varchar(255) NOT NULL,
                YADISK_PATH varchar(1024) NOT NULL,
                FILE_SIZE bigint unsigned NOT NULL DEFAULT 0,
                EXTENSION varchar(20) NOT NULL,
                STATUS varchar(32) NOT NULL DEFAULT 'created',
                DOWNLOAD_HASH varchar(64) NOT NULL,
                CREATED_AT datetime NOT NULL,
                UPDATED_AT datetime NOT NULL,
                PRIMARY KEY (ID),
                KEY IX_PROSPEKT_LAYOUT_BASKET (FUSER_ID, BASKET_ID, STATUS),
                KEY IX_PROSPEKT_LAYOUT_ORDER (ORDER_ID, ORDER_BASKET_ID),
                KEY IX_PROSPEKT_LAYOUT_HASH (DOWNLOAD_HASH)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $report['layout_files'] = 'created';
        } else {
            $report['layout_files'] = 'existing';
        }

        if (Loader::includeModule('highloadblock')) {
            (new \Prospektweb\PropValManager\Service\PropertyValueDescriptionInstaller())->ensure();
            (new \Prospektweb\PropValManager\Service\PropertyDescriptionJsonExporter())->export();
            $report['property_descriptions'] = 'ready';
        } else {
            $report['property_descriptions'] = 'highloadblock unavailable';
        }

        $productsIblockId = (int)Option::get(self::MODULE_ID, 'PRODUCTS_IBLOCK_ID', Option::get(self::MODULE_ID, 'PRODUCT_IBLOCK_ID', '0'));
        if ($productsIblockId > 0 && Loader::includeModule('iblock')) {
            $propertyCode = trim((string)Option::get(self::MODULE_ID, 'CALC_PROPERTY_CODE', 'FRONTCALC_CONFIG'));
            $this->ensureFrontendProperty($productsIblockId, $propertyCode);
            (new \Prospektweb\PropValManager\Service\PropertyManager())->ensureTrCaseProperty($productsIblockId);
            $report['product_properties'] = 'ready';
        }

        return $report;
    }

    private function ensureFrontendProperty(int $iblockId, string $propertyCode): void
    {
        if ($propertyCode === '') {
            throw new RuntimeException('Не задан код свойства конфигурации публичного калькулятора.');
        }

        $existing = \CIBlockProperty::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode])->Fetch();
        if ($existing) {
            return;
        }

        $property = new \CIBlockProperty();
        if (!$property->Add([
            'IBLOCK_ID' => $iblockId,
            'NAME' => 'Конфигурация публичного калькулятора',
            'ACTIVE' => 'Y',
            'SORT' => 500,
            'CODE' => $propertyCode,
            'PROPERTY_TYPE' => 'S',
            'USER_TYPE' => 'HTML',
            'MULTIPLE' => 'N',
            'IS_REQUIRED' => 'N',
        ])) {
            throw new RuntimeException('Не удалось создать свойство ' . $propertyCode . ': ' . $property->LAST_ERROR);
        }
    }

    private function ensureCleanupAgent(): string
    {
        if (!class_exists('CAgent')) {
            return 'CAgent unavailable';
        }

        \CAgent::RemoveModuleAgents('prospektweb.layoutfiles');
        $name = '\\Prospektweb\\LayoutFiles\\Agent::cleanup();';
        $existing = \CAgent::GetList(['ID' => 'ASC'], ['MODULE_ID' => self::MODULE_ID, 'NAME' => $name])->Fetch();
        if ($existing) {
            return 'existing';
        }

        $id = \CAgent::AddAgent($name, self::MODULE_ID, 'N', 3600);
        if (!$id) {
            throw new RuntimeException('Не удалось зарегистрировать агент очистки временных макетов.');
        }

        return 'created';
    }
}
