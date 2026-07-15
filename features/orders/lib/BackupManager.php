<?php
namespace Prospektweb\LayoutFiles;

use Bitrix\Main\Config\Option;

class BackupManager
{
    private const BACKUP_ROOT = '/upload/prospekt_layoutfiles_backups';
    private const TEMPLATE_ROOT = '/bitrix/templates/aspro-premier/components/bitrix/sale.basket.basket/v2';

    public static function createBackupBeforeInstall(string $moduleVersion): void
    {
        $documentRoot = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/');
        if (self::isCurrentReleaseAlreadyInstalled($documentRoot, $moduleVersion)) {
            return;
        }

        $installId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $backupDir = $documentRoot . self::BACKUP_ROOT . '/' . $installId;
        self::ensureDirectory($backupDir);

        $manifest = [
            'install_id' => $installId,
            'module_version' => $moduleVersion,
            'created_at' => date('c'),
            'files' => [],
        ];

        foreach (self::getManagedFiles() as $relativePath => $handler) {
            $absolutePath = $documentRoot . $relativePath;
            $existsBefore = is_file($absolutePath);
            $backupRelative = null;
            $originalHash = null;

            if ($existsBefore) {
                $backupRelative = $relativePath;
                $backupPath = $backupDir . $backupRelative;
                self::ensureDirectory(dirname($backupPath));
                if (!copy($absolutePath, $backupPath)) {
                    throw new \RuntimeException('Не удалось создать backup файла ' . $relativePath);
                }
                $originalHash = hash_file('sha256', $absolutePath);
            } else {
                self::ensureDirectory(dirname($absolutePath));
            }

            self::applyHandler($handler, $absolutePath);

            $manifest['files'][$relativePath] = [
                'path' => $relativePath,
                'exists_before' => $existsBefore,
                'original_hash' => $originalHash,
                'installed_hash' => is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null,
                'backup_path' => $backupRelative,
                'created_at' => date('c'),
                'module_version' => $moduleVersion,
                'install_id' => $installId,
            ];
        }

        self::writeManifest($backupDir . '/manifest.php', $manifest);
        Option::set(Config::MODULE_ID, 'backup_install_id', $installId);
    }

    private static function isCurrentReleaseAlreadyInstalled(string $documentRoot, string $moduleVersion): bool
    {
        $installId = (string)Option::get(Config::MODULE_ID, 'backup_install_id', '');
        if ($installId === '') {
            return false;
        }

        $manifestPath = $documentRoot . self::BACKUP_ROOT . '/' . $installId . '/manifest.php';
        if (!is_file($manifestPath)) {
            return false;
        }

        $manifest = include $manifestPath;
        if (!is_array($manifest) || (string)($manifest['module_version'] ?? '') !== $moduleVersion) {
            return false;
        }

        $conflicts = [];
        foreach (($manifest['files'] ?? []) as $relativePath => $fileInfo) {
            $absolutePath = $documentRoot . $relativePath;
            $currentHash = is_file($absolutePath) ? (string)hash_file('sha256', $absolutePath) : null;
            $installedHash = $fileInfo['installed_hash'] ?? null;
            if ($currentHash !== $installedHash) {
                $conflicts[] = $relativePath;
            }
        }

        if ($conflicts !== []) {
            throw new \RuntimeException(
                'Файлы корзины изменены после установки шаблонного набора ' . $moduleVersion
                . ': ' . implode(', ', $conflicts) . '. Автоматическая повторная замена остановлена.'
            );
        }

        return true;
    }

    public static function rollbackOnUninstall(): void
    {
        $documentRoot = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/');
        $installId = (string)Option::get(Config::MODULE_ID, 'backup_install_id', '');
        if ($installId === '') {
            $installId = self::findLatestInstallId($documentRoot);
        }

        if ($installId === '') {
            self::cleanupLegacyPatches();
            return;
        }

        $backupDir = $documentRoot . self::BACKUP_ROOT . '/' . $installId;
        $manifestPath = $backupDir . '/manifest.php';
        $report = [
            'install_id: ' . $installId,
            'created_at: ' . date('c'),
            '',
            'RESTORED:',
        ];
        $removed = ['REMOVED NEW FILES:'];
        $conflicts = ['CONFLICTS / LEFT UNTOUCHED:'];
        $missing = ['MISSING BACKUPS:'];
        $errors = ['ERRORS:'];

        if (!is_file($manifestPath)) {
            $errors[] = 'Manifest not found: ' . $manifestPath;
            $cleanupReport = self::cleanupLegacyPatches();
            self::writeReport($backupDir, array_merge($report, $removed, $conflicts, $missing, $errors, [''], self::formatCleanupReport($cleanupReport)));
            return;
        }

        $manifest = include $manifestPath;
        foreach (($manifest['files'] ?? []) as $relativePath => $fileInfo) {
            $absolutePath = $documentRoot . $relativePath;
            $currentHash = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null;
            $installedHash = $fileInfo['installed_hash'] ?? null;

            if (!empty($fileInfo['exists_before'])) {
                if ($currentHash !== $installedHash) {
                    $conflicts[] = $relativePath . ' current_hash != installed_hash';
                    continue;
                }

                $backupPath = $backupDir . ($fileInfo['backup_path'] ?? '');
                if (!is_file($backupPath)) {
                    $missing[] = $relativePath . ' backup not found: ' . $backupPath;
                    continue;
                }

                try {
                    self::ensureDirectory(dirname($absolutePath));
                    if (!copy($backupPath, $absolutePath)) {
                        throw new \RuntimeException('copy() returned false');
                    }
                    $report[] = $relativePath;
                } catch (\Throwable $e) {
                    $errors[] = $relativePath . ' ' . $e->getMessage();
                }
            } else {
                if ($currentHash !== $installedHash) {
                    $conflicts[] = $relativePath . ' new file changed after install';
                    continue;
                }

                if (is_file($absolutePath) && !unlink($absolutePath)) {
                    $errors[] = $relativePath . ' cannot delete new file';
                } else {
                    $removed[] = $relativePath;
                }
            }
        }

        $cleanupReport = self::cleanupLegacyPatches();
        self::writeReport($backupDir, array_merge($report, [''], $removed, [''], $conflicts, [''], $missing, [''], $errors, [''], self::formatCleanupReport($cleanupReport)));
    }

    public static function cleanupLegacyPatches(): array
    {
        $documentRoot = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/');
        $report = [];
        $cleanupMap = [
            self::TEMPLATE_ROOT . '/js-templates/basket-item.php' => 'cleanupLegacyBasketItemTemplate',
            self::TEMPLATE_ROOT . '/style.css' => 'cleanupLegacyStyleCss',
            self::TEMPLATE_ROOT . '/mutator.php' => 'cleanupLegacyMutator',
            self::TEMPLATE_ROOT . '/template.php' => 'cleanupLegacyTemplate',
        ];

        foreach ($cleanupMap as $relativePath => $handler) {
            $absolutePath = $documentRoot . $relativePath;
            if (!is_file($absolutePath)) {
                $report[$relativePath] = 'missing';
                continue;
            }

            try {
                $before = (string)file_get_contents($absolutePath);
                $after = self::$handler($before);
                if ($after !== $before) {
                    file_put_contents($absolutePath, $after);
                    $report[$relativePath] = 'cleaned';
                } else {
                    $report[$relativePath] = 'unchanged';
                }
            } catch (\Throwable $e) {
                $report[$relativePath] = 'error: ' . $e->getMessage();
            }
        }

        return $report;
    }

    private static function cleanupLegacyBasketItemTemplate(string $content): string
    {
        return (string)preg_replace('/\s*<div[^>]*class=(?:"[^"]*prospekt-desired-date-item[^"]*"|\'[^\']*prospekt-desired-date-item[^\']*\')[^>]*data-prospekt-desired-date-item[^>]*>.*?<\/div>\s*/s', "\n", $content);
    }

    private static function cleanupLegacyStyleCss(string $content): string
    {
        return (string)preg_replace('/\s*\.prospekt-desired-date-item\s*\{[^}]*\}\s*/s', "\n", $content);
    }

    private static function cleanupLegacyMutator(string $content): string
    {
        return self::replaceAllIfExists($content, "foreach (\\Prospektweb\\LayoutFiles\\Config::getHiddenBasketPropertyCodes() as \$prospektHiddenCode) {
    \$prospektLayoutPropCodes[\$prospektHiddenCode] = true;
}", "foreach (array_map('trim', explode(',', (string)\\Bitrix\\Main\\Config\\Option::get('prospektweb.calc', 'hidden_basket_property_codes', ''))) as \$prospektHiddenCode) {
    if (\$prospektHiddenCode !== '') {
        \$prospektLayoutPropCodes[\$prospektHiddenCode] = true;
    }
}");
    }

    private static function cleanupLegacyTemplate(string $content): string
    {
        $content = self::replaceAllIfExists($content, "\\Prospektweb\\LayoutFiles\\Config::getHiddenBasketPropertyCodes()", "array_map('trim', explode(',', (string)\\Bitrix\\Main\\Config\\Option::get('prospektweb.calc', 'hidden_basket_property_codes', '')))");
        return self::replaceAllIfExists($content, "\\Prospektweb\\LayoutFiles\\Config::DEFAULT_DESIRED_RECEIVE_TOOLTIP_TEXT", "'Дата ориентировочная. Точный график производства утвердим после проверки состава заказа. Любые изменения проводим только по согласованию с Вами.'");
    }

    private static function formatCleanupReport(array $cleanupReport): array
    {
        $lines = ['LEGACY CLEANUP:'];
        foreach ($cleanupReport as $path => $status) {
            $lines[] = $path . ' ' . $status;
        }
        return $lines;
    }

    private static function getManagedFiles(): array
    {
        return [
            self::TEMPLATE_ROOT . '/template.php' => 'patchTemplate',
            self::TEMPLATE_ROOT . '/js-templates/basket-item.php' => 'patchBasketItemTemplate',
            self::TEMPLATE_ROOT . '/js-templates/basket-total.php' => 'patchBasketTotalTemplate',
            self::TEMPLATE_ROOT . '/js/component.js' => 'patchComponentJs',
            self::TEMPLATE_ROOT . '/mutator.php' => 'patchMutator',
            self::TEMPLATE_ROOT . '/style.css' => 'patchStyleCss',
            self::TEMPLATE_ROOT . '/js/prospekt_layout_files.js' => 'copyClientJs',
            self::TEMPLATE_ROOT . '/js/prospekt_desired_receive_date.js' => 'copyDesiredReceiveDateJs',
            self::TEMPLATE_ROOT . '/js/air-datepicker.js' => 'copyAirDatepickerJs',
            self::TEMPLATE_ROOT . '/css/air-datepicker.css' => 'copyAirDatepickerCss',
        ];
    }

    private static function applyHandler(string $handler, string $absolutePath): void
    {
        self::$handler($absolutePath);
    }

    private static function patchTemplate(string $path): void
    {
        $content = self::readFile($path);
        $content = self::replaceAllIfExists($content, "\Prospektweb\LayoutFiles\Config::getHiddenBasketPropertyCodes()", "array_map('trim', explode(',', (string)\Bitrix\Main\Config\Option::get('prospektweb.calc', 'hidden_basket_property_codes', '')))");
        $layoutJs = '$this->addExternalJs($templateFolder.\'/js/prospekt_layout_files.js\');';
        $airDatepickerJs = '$this->addExternalJs($templateFolder.\'/js/air-datepicker.js\');';
        $desiredDateJs = '$this->addExternalJs($templateFolder.\'/js/prospekt_desired_receive_date.js\');';
        $airDatepickerCss = '$this->addExternalCss($templateFolder.\'/css/air-datepicker.css\');';
        if (strpos($content, 'PROSPEKT_LAYOUTFILES_TEMPLATE_JS_START') === false) {
            $jsBlock = "// PROSPEKT_LAYOUTFILES_TEMPLATE_JS_START\n" . $layoutJs . "\n" . $airDatepickerJs . "\n" . $desiredDateJs . "\n// PROSPEKT_LAYOUTFILES_TEMPLATE_JS_END";
            if (strpos($content, $layoutJs) !== false) {
                $content = self::replaceOnce($content, $layoutJs, $jsBlock);
            } else {
                $componentJs = '$this->addExternalJs($templateFolder.\'/js/component.js\');';
                $content = self::replaceOnce($content, $componentJs, $componentJs . "\n" . $jsBlock);
            }
        } elseif (strpos($content, 'prospekt_desired_receive_date.js') === false) {
            $content = self::replaceOnce($content, $layoutJs, $layoutJs . "\n" . $airDatepickerJs . "\n" . $desiredDateJs);
        }
        if (strpos($content, 'air-datepicker.css') === false) {
            $bootstrapCss = '$this->addExternalCss(\'/bitrix/css/main/bootstrap.css\');';
            $content = self::replaceOnce($content, $bootstrapCss, $bootstrapCss . "\n" . $airDatepickerCss);
        }
        // Layout upload help is shown per item in the attach tooltip.
        if (strpos($content, 'PROSPEKT_LAYOUTFILES_CONFIG_START') === false) {
            $content = self::replaceOnce($content, "    \$messages = Loc::loadLanguageFile(__FILE__);", "    \$messages = Loc::loadLanguageFile(__FILE__);
    \$prospektLayoutTooltipText = Main\Config\Option::get('prospektweb.calc', 'tooltip_text', 'Прикрепите 1 графический файл (.pdf, .cdr, .tiff) или архив до 100 МБ. Если файл крупнее, укажите ссылку в комментарии к заказу или отправьте на mail@prospekt-print.ru (укажите номер заказа).');");
            $content = self::replaceOnce($content, "        BX.message(<?= CUtil::PhpToJSObject(\$messages);?>);", "        BX.message(<?= CUtil::PhpToJSObject(\$messages);?>);
        // PROSPEKT_LAYOUTFILES_CONFIG_START
        window.ProspektLayoutFilesConfig = {ajaxUrl: '/local/tools/prospekt_layout/ajax.php', sessid: BX.bitrix_sessid(), maxSize: 104857600, extensions: ['pdf','ai','eps','cdr','psd','tif','tiff','jpg','jpeg','png','zip','rar','7z'], tooltipText: <?= CUtil::PhpToJSObject(\$prospektLayoutTooltipText); ?>};
        // PROSPEKT_LAYOUTFILES_CONFIG_END");
        }
        if (strpos($content, 'PROSPEKT_LAYOUTFILES_HIDDEN_PROPS_STYLE_START') === false) {
            $hiddenStyleBlock = <<<'PHP'
    <?php
    $prospektHiddenOption = \Bitrix\Main\Config\Option::get('prospektweb.calc', 'hidden_basket_property_codes', '');
    $prospektHiddenCodes = array_merge(array_map('trim', explode(',', $prospektHiddenOption)), ['LAYOUT_FILE_ID', 'LAYOUT_FILE_LINK', 'LAYOUT_FILE_NAME', 'PROSPEKT_DESIRED_RECEIVE_DATE']);
    $prospektHiddenSelectors = [];
    foreach (array_unique(array_filter($prospektHiddenCodes)) as $prospektHiddenCode) {
        $prospektHiddenCode = htmlspecialcharsbx($prospektHiddenCode);
        $prospektHiddenSelectors[] = '.basket-item-property[data-property-code="' . $prospektHiddenCode . '"]';
        $prospektHiddenSelectors[] = '.basket-item-property-value[data-property-code="' . $prospektHiddenCode . '"]';
    }
    if ($prospektHiddenSelectors) { ?>
        <!-- PROSPEKT_LAYOUTFILES_HIDDEN_PROPS_STYLE_START -->
        <style><?=implode(',', $prospektHiddenSelectors)?>{display:none!important;}</style>
        <!-- PROSPEKT_LAYOUTFILES_HIDDEN_PROPS_STYLE_END -->
    <?php } ?>
    <script>
PHP;
            $content = self::replaceOnce($content, "    <script>", $hiddenStyleBlock);
        }
        file_put_contents($path, $content);
    }

    private static function patchBasketItemTemplate(string $path): void
    {
        $content = self::readFile($path);
        $content = preg_replace('/\s*<div class="prospekt-desired-date-item" data-prospekt-desired-date-item[^>]*>.*?<\/div>\s*/s', "\n", $content);
        $content = self::replaceAllIfExists($content, '<div class="basket-item-property">', '<div class="basket-item-property" data-property-code="{{CODE}}">');
        $content = self::replaceAllIfExists($content, '<div class="basket-item-property basket-item-property-scu-image" data-entity="basket-item-sku-block">', '<div class="basket-item-property basket-item-property-scu-image" data-entity="basket-item-sku-block" data-property-code="{{PROP_CODE}}">');
        $content = self::replaceAllIfExists($content, '<div class="basket-item-property basket-item-property-scu-text" data-entity="basket-item-sku-block">', '<div class="basket-item-property basket-item-property-scu-text" data-entity="basket-item-sku-block" data-property-code="{{PROP_CODE}}">');
        if (strpos($content, 'PROSPEKT_LAYOUTFILES_ITEM_START') !== false) {
            file_put_contents($path, $content);
            return;
        }
        if (strpos($content, 'data-prospekt-layout data-basket-id') !== false) {
            file_put_contents($path, $content);
            return;
        }

        $block = <<<'HTML'
                    <!-- PROSPEKT_LAYOUTFILES_ITEM_START -->
                    <div class="prospekt-layout-file" data-prospekt-layout data-basket-id="{{ID}}" data-product-id="{{PRODUCT_ID}}">
                        <input class="prospekt-layout-file__input" type="file" data-prospekt-layout-input>
                        <div class="prospekt-layout-file__progress-wrap" data-prospekt-layout-progress hidden><div class="prospekt-layout-file__loading">Загрузка...</div><div class="prospekt-layout-file__progress"><span></span></div></div>
                        <div class="prospekt-layout-file__result" data-prospekt-layout-result hidden>
                            <span class="prospekt-layout-file__status">Макет:</span>
                            <span class="prospekt-layout-file__name" data-prospekt-layout-name></span>
                            <span class="prospekt-layout-file__size" data-prospekt-layout-size></span>
                            <button class="prospekt-layout-file__delete" type="button" data-prospekt-layout-delete aria-label="Удалить файл"><i class="fa fa-remove" data-toggle="tooltip" title="Удалить файл"></i></button>
                        </div>
                        <span class="original-maket-upload-btn" data-toggle="tooltip" title="<?=htmlspecialcharsbx(\Bitrix\Main\Config\Option::get('prospektweb.calc', 'tooltip_text', 'Прикрепите 1 графический файл (.pdf, .cdr, .tiff) или архив до 100 МБ. Если файл крупнее, укажите ссылку в комментарии к заказу или отправьте на mail@prospekt-print.ru (укажите номер заказа).'))?>" data-prospekt-layout-attach><button type="button" class="btn btn-default mb-10 mr-10 btn-transparent-bg"><i class="fa fa-upload"></i> Прикрепить макет</button></span>
                        <div class="prospekt-layout-file__error" data-prospekt-layout-error hidden></div>
                    </div>
                    <!-- PROSPEKT_LAYOUTFILES_ITEM_END -->
HTML;
        $content = self::replaceOnce($content, "                    </div>\n                </div>\n\n                {{#SHOW_LOADING}}", "                    </div>\n" . $block . "\n                </div>\n\n                {{#SHOW_LOADING}}");
        file_put_contents($path, $content);
    }


    private static function patchBasketTotalTemplate(string $path): void
    {
        $content = self::readFile($path);
        $content = self::replaceAllIfExists($content, "\Prospektweb\LayoutFiles\Config::DEFAULT_DESIRED_RECEIVE_TOOLTIP_TEXT", "'Дата ориентировочная. Точный график производства утвердим после проверки состава заказа. Любые изменения проводим только по согласованию с Вами.'");
        $content = self::replaceAllIfExists($content, '<button type="button" class="prospekt-desired-date__clear" data-prospekt-desired-date-clear hidden aria-label="Сбросить желаемую дату"><i class="fa fa-remove"></i></button>', '<button type="button" class="prospekt-desired-date__clear" data-prospekt-desired-date-clear hidden aria-label="Сбросить желаемую дату"><span aria-hidden="true">&times;</span></button>');
        $emptyDateInput = '<input type="text" class="form-control prospekt-desired-date__input" style="cursor: pointer !important;" placeholder="Желаемая дата получения" data-prospekt-desired-date-input readonly>';
        $initialDateInput = '<input type="text" class="form-control prospekt-desired-date__input" style="cursor: pointer !important;" placeholder="Желаемая дата получения" value="<?=htmlspecialcharsbx(\\Prospektweb\\LayoutFiles\\DesiredReceiveDateManager::getInitialDisplayValue())?>" data-prospekt-desired-date-input readonly>';
        $content = self::replaceAllIfExists($content, $emptyDateInput, $initialDateInput);
        if (strpos($content, 'PROSPEKT_DESIRED_RECEIVE_DATE_TOTAL_START') !== false) {
            file_put_contents($path, $content);
            return;
        }
        $block = <<<'HTML'
                        <!-- PROSPEKT_DESIRED_RECEIVE_DATE_TOTAL_START -->
                        <div class="prospekt-desired-date" data-prospekt-desired-date>
                            <span data-toggle="tooltip" title="<?=htmlspecialcharsbx(\Bitrix\Main\Config\Option::get('prospektweb.calc', 'desired_receive_tooltip_text', 'Дата ориентировочная. Точный график производства утвердим после проверки состава заказа. Любые изменения проводим только по согласованию с Вами.'))?>">
                                <div class="form">
                                    <div class="form-group" style="position: relative;">
                                        <input type="text" class="form-control prospekt-desired-date__input" style="cursor: pointer !important;" placeholder="Желаемая дата получения" value="<?=htmlspecialcharsbx(\Prospektweb\LayoutFiles\DesiredReceiveDateManager::getInitialDisplayValue())?>" data-prospekt-desired-date-input readonly>
                                        <button type="button" class="prospekt-desired-date__clear" data-prospekt-desired-date-clear hidden aria-label="Сбросить желаемую дату"><span aria-hidden="true">&times;</span></button>
                                        <span class="basket-coupon-block-coupon-btn prospekt-desired-date__btn"><i class="fa fa-calendar"></i></span>
                                    </div>
                                </div>
                            </span>
                        </div>
                        <!-- PROSPEKT_DESIRED_RECEIVE_DATE_TOTAL_END -->

HTML;
        $content = self::replaceOnce($content, "\t\t\t\t\t<? if (\$arParams['HIDE_COUPON'] !== 'Y') { ?>", $block . "\t\t\t\t\t<? if (\$arParams['HIDE_COUPON'] !== 'Y') { ?>");
        file_put_contents($path, $content);
    }

    private static function patchMutator(string $path): void
    {
        $content = self::readFile($path);
        $content = self::replaceAllIfExists($content, "foreach (\\Prospektweb\\LayoutFiles\\Config::getHiddenBasketPropertyCodes() as \$prospektHiddenCode) {
    \$prospektLayoutPropCodes[\$prospektHiddenCode] = true;
}", "foreach (array_map('trim', explode(',', (string)\\Bitrix\\Main\\Config\\Option::get('prospektweb.calc', 'hidden_basket_property_codes', ''))) as \$prospektHiddenCode) {
    if (\$prospektHiddenCode !== '') {
        \$prospektLayoutPropCodes[\$prospektHiddenCode] = true;
    }
}");
        if (strpos($content, 'PROSPEKT_LAYOUTFILES_FILTER_PROPS_START') === false) {
            $content = self::replaceOnce($content, "\$result['BASKET_ITEM_RENDER_DATA'] = [];", "\$result['BASKET_ITEM_RENDER_DATA'] = [];\n// PROSPEKT_LAYOUTFILES_FILTER_PROPS_START\n\$prospektLayoutPropCodes = [\n    'LAYOUT_FILE_ID' => true,\n    'LAYOUT_FILE_LINK' => true,\n    'LAYOUT_FILE_NAME' => true,\n    'PROSPEKT_DESIRED_RECEIVE_DATE' => true,\n];\nforeach (array_map('trim', explode(',', (string)\\Bitrix\\Main\\Config\\Option::get('prospektweb.calc', 'hidden_basket_property_codes', ''))) as \$prospektHiddenCode) {\n    if (\$prospektHiddenCode !== '') {\n        \$prospektLayoutPropCodes[\$prospektHiddenCode] = true;\n    }\n}\n\$filterProspektLayoutProps = static function (\$props) use (\$prospektLayoutPropCodes) {\n    if (!is_array(\$props)) {\n        return \$props;\n    }\n\n    return array_values(array_filter(\$props, static function (\$prop) use (\$prospektLayoutPropCodes) {\n        return empty(\$prospektLayoutPropCodes[(string)(\$prop['CODE'] ?? '')]);\n    }));\n};\n// PROSPEKT_LAYOUTFILES_FILTER_PROPS_END");
        }
        $content = self::replaceOnce($content, "        'PROPS' => \$row['PROPS'],\n        'PROPS_ALL' => \$row['PROPS_ALL'],", "        'PROPS' => \$filterProspektLayoutProps(\$row['PROPS']),\n        'PROPS_ALL' => \$filterProspektLayoutProps(\$row['PROPS_ALL']),");
        file_put_contents($path, $content);
    }

    private static function patchComponentJs(string $path): void
    {
        $content = self::readFile($path);
        if (strpos($content, 'PROSPEKT_LAYOUTFILES_INIT_START') !== false) {
            return;
        }
        $needle = "        this.bindBasketItemEvents(this.items[itemId]);\n\n        if (this.filter.isActive()) {";
        $replacement = "        this.bindBasketItemEvents(this.items[itemId]);\n        // PROSPEKT_LAYOUTFILES_INIT_START\n        if (window.ProspektLayoutFiles) { window.ProspektLayoutFiles.init(BX(this.ids.item + itemId)); }\n        // PROSPEKT_LAYOUTFILES_INIT_END\n\n        if (this.filter.isActive()) {";
        $content = self::replaceAll($content, $needle, $replacement, 2);
        file_put_contents($path, $content);
    }

    private static function patchStyleCss(string $path): void
    {
        $content = self::readFile($path);
        if (strpos($content, 'PROSPEKT_LAYOUTFILES_CSS_START') !== false) {
            $content = self::replaceAllIfExists($content, '.prospekt-desired-date__input.form-control { height: auto; padding: 9px 64px 9px 12px; cursor: pointer; }', '.prospekt-desired-date__input.form-control { height: auto; padding: 9px 64px 9px 12px; cursor: pointer !important; }');
            $content = self::replaceAllIfExists($content, '.prospekt-desired-date__clear { position: absolute; right: 34px; top: 50%; transform: translateY(-50%); border: 0; background: none; color: #aaa; cursor: pointer; padding: 0; z-index: 2; }', '.prospekt-desired-date__clear { position: absolute; right: 44px; top: 32%; border: 0; background: none; color: #aaa; cursor: pointer; padding: 0; z-index: 2; font-size: 18px; font-weight: 300; line-height: 1; }');
            $content = self::replaceAllIfExists($content, '.prospekt-desired-date-item { margin: 12px 0 8px; color: #555; font-size: 13px; line-height: 18px; }', '.prospekt-desired-date-item { display: none !important; }');
            file_put_contents($path, $content);
            return;
        }
        $content .= <<<'CSS'

/* PROSPEKT_LAYOUTFILES_CSS_START */
.prospekt-layout-file { margin-top: 12px; font-size: 13px; }
.prospekt-layout-file__input { display: none !important; }
.prospekt-layout-file [data-prospekt-layout-attach] { cursor: pointer; }
.original-maket-upload-btn { display: inline-block; }
.prospekt-layout-file__loading { margin-bottom: 6px; color: #666; }
.prospekt-layout-file__progress { position: relative; height: 6px; margin: 8px 0; overflow: hidden; border-radius: 6px; background: #eef1f4; }
.prospekt-layout-file__progress span { display: block; width: 0; height: 100%; background: #2fc6a4; transition: width .2s ease; }
.prospekt-layout-file__progress-wrap { max-width: 460px; margin-bottom: 8px; }
.prospekt-layout-file__result { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; max-width: 460px; margin-bottom: 8px; }
.prospekt-layout-file__result[hidden], .prospekt-layout-file__progress-wrap[hidden], .prospekt-layout-file__error[hidden] { display: none !important; }
.prospekt-layout-file__name { flex: 1 1 auto; min-width: 0; overflow-wrap: anywhere; color: #333; }
.prospekt-layout-file__size { flex: 0 0 auto; color: #666; }
.prospekt-layout-file__delete { flex: 0 0 auto; margin-left: auto; padding: 0; border: 0; background: none; color: #999; cursor: pointer; line-height: 1; }
.prospekt-layout-file__delete:hover { color: #d0021b; }
.prospekt-layout-file__error { margin-top: 6px; color: #d0021b; }
.prospekt-desired-date { margin: 0 0 28px; position: relative; }
.prospekt-desired-date--empty { max-width: 420px; }
.prospekt-desired-date .form-group { margin-bottom: 0; }
.prospekt-desired-date__input.form-control { height: auto; padding: 9px 64px 9px 12px; cursor: pointer !important; }
.prospekt-desired-date__input.form-control[readonly] { background: #fff; }
.prospekt-desired-date__btn { pointer-events: none; }
.prospekt-desired-date__clear { position: absolute; right: 44px; top: 32%; border: 0; background: none; color: #aaa; cursor: pointer; padding: 0; z-index: 2; font-size: 18px; font-weight: 300; line-height: 1; }
.prospekt-desired-date__clear[hidden] { display: none !important; }
.prospekt-desired-date__clear:hover { color: #333; }
.prospekt-desired-date__status { margin-top: 6px; color: #999; font-size: 12px; line-height: 16px; }
.prospekt-desired-date__status--success { color: #59b615; }
.prospekt-desired-date__status--error { color: #e62222; }
.prospekt-desired-date__status--muted { color: #999; }
.prospekt-desired-date i { line-height: 10px; }
.prospekt-desired-date i:hover { color: #333; }
.prospekt-desired-date-item { display: none !important; }
.air-datepicker-cell.-selected- { background-color: var(--theme-base-color); border-color: var(--theme-base-color); color: var(--button_color_text); }
.air-datepicker-button { color: var(--theme-base-color); }
/* PROSPEKT_LAYOUTFILES_CSS_END */
CSS;
        file_put_contents($path, $content);
    }

    private static function copyClientJs(string $path): void
    {
        $source = dirname(__DIR__) . '/assets/js/prospekt_layout_files.js';
        if (!is_file($source)) {
            throw new \RuntimeException('Не найден JS-asset модуля: ' . $source);
        }
        self::ensureDirectory(dirname($path));
        if (!copy($source, $path)) {
            throw new \RuntimeException('Не удалось скопировать JS-файл в шаблон: ' . $path);
        }
    }

    private static function copyDesiredReceiveDateJs(string $path): void
    {
        $source = dirname(__DIR__) . '/assets/js/prospekt_desired_receive_date.js';
        if (!is_file($source)) {
            throw new \RuntimeException('Не найден JS-asset модуля: ' . $source);
        }
        self::ensureDirectory(dirname($path));
        if (!copy($source, $path)) {
            throw new \RuntimeException('Не удалось скопировать JS-файл в шаблон: ' . $path);
        }
    }


    private static function copyAirDatepickerJs(string $path): void
    {
        self::copyAsset(dirname(__DIR__) . '/assets/vendor/air-datepicker/air-datepicker.js', $path, 'JS Air Datepicker');
    }

    private static function copyAirDatepickerCss(string $path): void
    {
        self::copyAsset(dirname(__DIR__) . '/assets/vendor/air-datepicker/air-datepicker.css', $path, 'CSS Air Datepicker');
    }

    private static function copyAsset(string $source, string $path, string $label): void
    {
        if (!is_file($source)) {
            throw new \RuntimeException('Не найден asset модуля: ' . $label . ' ' . $source);
        }
        self::ensureDirectory(dirname($path));
        if (!copy($source, $path)) {
            throw new \RuntimeException('Не удалось скопировать asset в шаблон: ' . $path);
        }
    }

    private static function readFile(string $path): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Не найден файл для патча: ' . $path);
        }
        return (string)file_get_contents($path);
    }

    private static function replaceOnce(string $content, string $needle, string $replacement): string
    {
        $position = strpos($content, $needle);
        if ($position === false) {
            throw new \RuntimeException('Не найден фрагмент для патча.');
        }
        return substr_replace($content, $replacement, $position, strlen($needle));
    }

    private static function replaceAllIfExists(string $content, string $needle, string $replacement): string
    {
        return strpos($content, $needle) === false ? $content : str_replace($needle, $replacement, $content);
    }

    private static function replaceAll(string $content, string $needle, string $replacement, int $expectedCount): string
    {
        $count = substr_count($content, $needle);
        if ($count < $expectedCount) {
            throw new \RuntimeException('Не найдены все фрагменты для патча. Найдено: ' . $count . ', ожидалось: ' . $expectedCount);
        }
        return str_replace($needle, $replacement, $content);
    }

    private static function writeManifest(string $path, array $manifest): void
    {
        file_put_contents($path, "<?php\nreturn " . var_export($manifest, true) . ";\n");
    }

    private static function writeReport(string $backupDir, array $lines): void
    {
        self::ensureDirectory($backupDir);
        file_put_contents($backupDir . '/uninstall_report.txt', implode(PHP_EOL, $lines) . PHP_EOL);
    }

    private static function findLatestInstallId(string $documentRoot): string
    {
        $root = $documentRoot . self::BACKUP_ROOT;
        if (!is_dir($root)) {
            return '';
        }
        $dirs = array_filter(glob($root . '/*') ?: [], 'is_dir');
        rsort($dirs, SORT_STRING);
        return $dirs ? basename($dirs[0]) : '';
    }

    private static function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, BX_DIR_PERMISSIONS, true) && !is_dir($path)) {
            throw new \RuntimeException('Не удалось создать директорию: ' . $path);
        }
    }
}
