<?php
declare(strict_types=1);
define('STOP_STATISTICS', true);
define('PUBLIC_AJAX_MODE', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
global $APPLICATION, $USER;
$APPLICATION->RestartBuffer();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
$respond = static function (int $status, array $body): void {
    http_response_code($status); echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit;
};
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); $respond(405, ['success' => false, 'error' => 'POST_REQUIRED']); }
    if (!$USER || !$USER->IsAdmin()) { $respond(403, ['success' => false, 'error' => 'ADMIN_REQUIRED']); }
    if (!check_bitrix_sessid()) { $respond(403, ['success' => false, 'error' => 'INVALID_SESSION']); }
    if (!\Bitrix\Main\Loader::includeModule('prospektweb.calc')) { throw new \RuntimeException('Calculator module unavailable.'); }
    // Form envelope survives Bitrix input filters and keeps JSON object/array types intact.
    $payload = $_POST['payload'] ?? '';
    if (!is_string($payload) || strlen($payload) > 8500000) { throw new \InvalidArgumentException('Invalid command size.'); }
    $request = json_decode($payload, false, 64, JSON_THROW_ON_ERROR);
    if (!$request instanceof \stdClass || !is_string($request->siteId ?? null) || !(($request->command ?? null) instanceof \stdClass)
        || array_diff(array_keys(get_object_vars($request)), ['siteId', 'command'])) { throw new \InvalidArgumentException('Invalid command envelope.'); }
    $siteId = $request->siteId;
    $siteRow = preg_match('/^[A-Za-z0-9]{1,2}$/D', $siteId) ? \CSite::GetByID($siteId)->Fetch() : false;
    if (!$siteRow) { throw new \InvalidArgumentException('Unknown site scope.'); }
    $siteServer = trim((string)($siteRow['SERVER_NAME'] ?? ''));
    $siteOrigin = $siteServer !== '' && preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/D', $siteServer)
        ? ((\Bitrix\Main\Context::getCurrent()->getRequest()->isHttps() ? 'https' : 'http').'://'.$siteServer) : '';
    $publicProductUrl = static function (string $url) use ($siteOrigin): string {
        $url = trim($url);
        if ($url === '' || preg_match('/^https?:\/\//i', $url)) return $url;
        return $siteOrigin !== '' && str_starts_with($url, '/') ? $siteOrigin.$url : $url;
    };
    $module = dirname(__DIR__);
    // Installed tools are copied outside the module. Resolve the real module path through Loader.
    $module = \Bitrix\Main\Loader::getLocal('modules/prospektweb.calc');
    if (!$module) { throw new \RuntimeException('Module path unavailable.'); }
    require_once $module . '/lib/Documents/BitrixConnection.php';
    require_once $module . '/lib/Documents/DocumentApplication.php';
    require_once $module . '/lib/Documents/BitrixCoreGateway.php';
    require_once $module . '/lib/Documents/BitrixResourceProvider.php';
    $catalogWrite = in_array($request->command->action ?? '', ['previewCatalogWrite', 'applyCatalogWrite'], true);
    $resourceCardWrite = ($request->command->action ?? '') === 'saveResourceCard';
    $preparationWrite = ($request->command->action ?? '') === 'productPreparation' && ($request->command->operation ?? '') === 'transfer';
    $scope = 'site:' . $siteId;
    $actor = 'user:' . (int)$USER->GetID();
    $connection = new \Prospektweb\Calc\Documents\BitrixConnection(\Bitrix\Main\Application::getConnection(), $catalogWrite || $resourceCardWrite || $preparationWrite);
    $repository = new \Prospektweb\Calc\Documents\DocumentRepository($connection, $scope, $actor);
    if (in_array($request->command->action ?? '', ['priceTemplates', 'loadPriceTemplate', 'createPriceTemplate', 'savePriceTemplate', 'renamePriceTemplate', 'deletePriceTemplate'], true)) {
        require_once $module . '/lib/Documents/PriceTemplateApplication.php';
        $templates = new \Prospektweb\Calc\Documents\PriceTemplateApplication($connection, $scope, $actor);
        $respond(200, ['success' => true, 'data' => $templates->command(get_object_vars($request->command))]);
    }
    $provider = (string)(new \Prospektweb\Calc\Config\ConfigManager())->getOption('DOCUMENT_RESOURCE_PROVIDER', '');
    if (in_array($request->command->action ?? '', ['loadResourceCard', 'saveResourceCard'], true)) {
        require_once $module . '/lib/Documents/DocumentResourceCard.php';
        $card = new \Prospektweb\Calc\Documents\DocumentResourceCard($connection, $scope, $actor, $provider);
        $respond(200, ['success' => true, 'data' => $card->commandFromJson($request->command)]);
    }
    if (in_array($request->command->action ?? '', ['resourceDescriptionTemplates', 'generateResourceDescription'], true)) {
        require_once $module . '/lib/Documents/DocumentResourceCard.php';
        require_once $module . '/lib/Documents/DocumentResourceDescription.php';
        $card = new \Prospektweb\Calc\Documents\DocumentResourceCard($connection, $scope, $actor, $provider);
        $gateway = new \Prospektweb\Calc\Services\AiGatewayService();
        $description = new \Prospektweb\Calc\Documents\DocumentResourceDescription([$card, 'commandFromJson'], [$gateway, 'getSettings'], [$gateway, 'generateText']);
        $respond(200, ['success' => true, 'data' => $description->command(get_object_vars($request->command))]);
    }
    if (($request->command->action ?? '') === 'resourceCatalogVersion') {
        require_once $module . '/lib/Documents/DocumentResourceCatalog.php';
        require_once $module . '/lib/Documents/BitrixResourceCatalog.php';
        $browser = new \Prospektweb\Calc\Documents\DocumentResourceCatalog($repository, new \Prospektweb\Calc\Documents\BitrixResourceCatalog($provider));
        $respond(200, ['success' => true, 'data' => $browser->command(get_object_vars($request->command))]);
    }
    if (($request->command->action ?? '') === 'catalogWriteTargets') {
        require_once $module . '/lib/Documents/BitrixDocumentCatalogTargets.php';
        $targets = new \Prospektweb\Calc\Documents\BitrixDocumentCatalogTargets($connection, $scope, $actor, $provider);
        $respond(200, ['success' => true, 'data' => $targets->command(get_object_vars($request->command))]);
    }
    if ($catalogWrite) {
        require_once $module . '/lib/Documents/DocumentCatalogWriteService.php';
        require_once $module . '/lib/Documents/BitrixDocumentCatalogWritePort.php';
        // Forward the complete command: the service rejects extra client fields.
        // Site/actor/provider and the shared repeatable-write connection are server-owned.
        $catalog = new \Prospektweb\Calc\Documents\BitrixDocumentCatalogWritePort($connection, $scope, $actor);
        $service = new \Prospektweb\Calc\Documents\DocumentCatalogWriteService($connection, $scope, $actor, $provider, $catalog, new \Prospektweb\Calc\Documents\BitrixCoreGateway());
        $respond(200, ['success' => true, 'data' => $service->command(get_object_vars($request->command))]);
    }
    if (in_array($request->command->action ?? '', ['calculationDescriptionTemplates', 'generateCalculationDescription'], true)) {
        require_once $module . '/lib/Documents/DocumentCalculationDescription.php';
        $gateway = new \Prospektweb\Calc\Services\AiGatewayService();
        $description = new \Prospektweb\Calc\Documents\DocumentCalculationDescription($repository, [$gateway, 'getSettings'], [$gateway, 'generateText']);
        $respond(200, ['success' => true, 'data' => $description->command(get_object_vars($request->command))]);
    }
    if (in_array($request->command->action ?? '', ['stageDescriptionTemplates', 'generateStageDescription'], true)) {
        require_once $module . '/lib/Documents/DocumentStageDescription.php';
        $gateway = new \Prospektweb\Calc\Services\AiGatewayService();
        $description = new \Prospektweb\Calc\Documents\DocumentStageDescription($repository, [$gateway, 'getSettings'], [$gateway, 'generateText']);
        $respond(200, ['success' => true, 'data' => $description->command(get_object_vars($request->command))]);
    }
    if (($request->command->action ?? '') === 'auditVersionLogic') {
        require_once $module . '/lib/Documents/DocumentLogicAudit.php';
        $audit = new \Prospektweb\Calc\Documents\DocumentLogicAudit($repository, static function (array $payload): array {
            return (new \Prospektweb\Calc\Services\AiGatewayService())->generateLogicAudit($payload);
        });
        $respond(200, ['success' => true, 'data' => $audit->command(get_object_vars($request->command))]);
    }
    if (in_array($request->command->action ?? '', ['assignmentCatalog', 'previewProductAssignments', 'saveProductAssignments', 'productPreparation'], true)) {
        if (!\Bitrix\Main\Loader::includeModule('prospektweb.frontcalc') || !\Bitrix\Main\Loader::includeModule('iblock')) throw new \RuntimeException('Site catalog adapter unavailable.',503);
        require_once $module.'/lib/Documents/DocumentProductAssignments.php';
        $config=new \Prospektweb\Frontcalc\Config\ConfigManager(); $catalog=$config->getProductIblockId();
        $iblockType=(string)\CIBlock::GetArrayByID($catalog,'IBLOCK_TYPE_ID');
        $language=defined('LANGUAGE_ID')?(string)LANGUAGE_ID:'ru';
        $readProducts=static function(array $queries,?array $ids) use($catalog,$iblockType,$language,$publicProductUrl,$preparationWrite,$connection): array {
            if ($preparationWrite && $ids) {
                if (!$connection->inTransaction()) throw new \LogicException('Preparation product read requires its transaction.');
                $lockedProducts=$connection->rows('SELECT ID FROM b_iblock_element WHERE IBLOCK_ID = ? AND ID IN ('.implode(',',array_fill(0,count($ids),'?')).') FOR UPDATE',array_merge([(int)$catalog],array_map('intval',$ids)));
                if (count($lockedProducts)!==count($ids)) throw new \Prospektweb\Calc\Documents\DocumentConflict('Товар удалён или перемещён в другой каталог.');
            }
            $filter=['IBLOCK_ID'=>$catalog,'CHECK_PERMISSIONS'=>'Y'];
            if ($ids!==null) $filter['ID']=array_map('intval',$ids);
            $rows=[]; $cursor=\CIBlockElement::GetList(['NAME'=>'ASC','ID'=>'ASC'],$filter,false,$ids===null?false:['nTopCount'=>100],['ID','NAME','ACTIVE','DETAIL_PAGE_URL']);
            while($row=$cursor->GetNext()) {
                $haystack=mb_strtolower((string)$row['NAME'].' '.(string)$row['ID'],'UTF-8');
                if($ids===null&&array_filter($queries,static fn(string $term):bool=>!str_contains($haystack,mb_strtolower($term,'UTF-8'))))continue;
                $rows[]=['key'=>(string)$row['ID'],'name'=>(string)$row['NAME'],'active'=>$row['ACTIVE']==='Y',
                    'adminUrl'=>'/bitrix/admin/iblock_element_edit.php?'.http_build_query(['IBLOCK_ID'=>$catalog,'type'=>$iblockType,'lang'=>$language,'ID'=>(int)$row['ID']]),
                    'siteUrl'=>$publicProductUrl((string)($row['DETAIL_PAGE_URL']??''))];
                if($ids===null&&count($rows)>=50)break;
            }
            return $rows;
        };
        if ($request->command->action === 'productPreparation') {
            require_once $module.'/lib/Documents/DocumentProductPreparation.php';
            $assignments=new \Prospektweb\Calc\Documents\DocumentProductPreparation($connection,$scope,$actor,$repository,$provider,(string)$catalog,$readProducts);
        } else $assignments=new \Prospektweb\Calc\Documents\DocumentProductAssignments($repository,$provider,(string)$catalog,$readProducts);
        $respond(200,['success'=>true,'data'=>$assignments->command(get_object_vars($request->command))]);
    }
    if (in_array($request->command->action ?? '', ['siteOptions', 'sourceCatalog', 'searchProducts', 'catalogProducts', 'catalogProductSections'], true)) {
        if (!\Bitrix\Main\Loader::includeModule('prospektweb.frontcalc') || !\Bitrix\Main\Loader::includeModule('iblock')) { throw new \RuntimeException('Site catalog adapter unavailable.', 503); }
        $keys = array_keys(get_object_vars($request->command)); sort($keys);
        $action = $request->command->action;
        if ($keys !== (in_array($action, ['siteOptions', 'sourceCatalog'], true) ? ['action'] : (in_array($action, ['catalogProducts', 'catalogProductSections'], true) ? ['action', 'ids'] : ['action', 'query']))) { throw new \InvalidArgumentException('Unknown catalog command field.'); }
        $config = new \Prospektweb\Frontcalc\Config\ConfigManager();
        if ($action === 'catalogProductSections') {
            require_once $module . '/lib/Documents/BitrixProductSections.php';
            $respond(200, ['success'=>true,'data'=>(new \Prospektweb\Calc\Documents\BitrixProductSections())->load($provider, $config->getProductIblockId(), $request->command->ids)]);
        }
        if ($action === 'sourceCatalog') {
            require_once $module . '/lib/Services/CalculatorInputSourceCatalogService.php';
            $catalog = (new \Prospektweb\Calc\Services\CalculatorInputSourceCatalogService())->loadCatalogs($config->getProductIblockId(), $config->getSkuIblockId());
            $respond(200, ['success' => true, 'data' => ['contract' => 'prospektweb.calculator/site-input-catalog-v1', 'provider' => $provider] + $catalog]);
        }
        if ($action === 'siteOptions') {
            $priceTypes = []; $cursor = \Bitrix\Main\Application::getConnection()->query('SELECT ID, NAME FROM b_catalog_group ORDER BY SORT, ID');
            while ($row = $cursor->fetch()) { $priceTypes[] = ['key' => (string)$row['ID'], 'name' => (string)$row['NAME']]; }
            $respond(200, ['success' => true, 'data' => ['provider' => $provider, 'productsCatalog' => (string)$config->getProductIblockId(), 'offersCatalog' => (string)$config->getSkuIblockId(), 'priceTypes' => $priceTypes]]);
        }
        $filter = ['IBLOCK_ID' => $config->getProductIblockId(), 'CHECK_PERMISSIONS' => 'Y'];
        if ($action === 'catalogProducts') {
            $ids = $request->command->ids ?? null;
            if (!is_array($ids) || count($ids) < 1 || count($ids) > 100) { throw new \InvalidArgumentException('Invalid product batch.'); }
            foreach ($ids as $id) { if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,8}$/D', $id)) { throw new \InvalidArgumentException('Invalid product identity.'); } }
            $filter['ID'] = array_map('intval', $ids);
        } else {
            $query = $request->command->query ?? null;
            if (!is_string($query) || mb_strlen($query) < 2 || mb_strlen($query) > 100) { throw new \InvalidArgumentException('Введите от 2 до 100 символов.'); }
            if (ctype_digit($query)) { $filter['ID'] = (int)$query; } else { $filter['%NAME'] = $query; }
        }
        $items = []; $cursor = \CIBlockElement::GetList(['NAME' => 'ASC', 'ID' => 'ASC'], $filter, false, ['nTopCount' => $action === 'catalogProducts' ? 100 : 30], ['ID', 'NAME', 'ACTIVE', 'DETAIL_PAGE_URL']);
        $iblockType=(string)\CIBlock::GetArrayByID($config->getProductIblockId(),'IBLOCK_TYPE_ID');
        $language=defined('LANGUAGE_ID')?(string)LANGUAGE_ID:'ru';
        while ($row = $cursor->GetNext()) { $items[] = ['key' => (string)$row['ID'], 'name' => (string)$row['NAME'], 'active' => $row['ACTIVE'] === 'Y',
            'adminUrl'=>'/bitrix/admin/iblock_element_edit.php?'.http_build_query(['IBLOCK_ID'=>$config->getProductIblockId(),'type'=>$iblockType,'lang'=>$language,'ID'=>(int)$row['ID']]),
            'siteUrl'=>$publicProductUrl((string)($row['DETAIL_PAGE_URL']??''))]; }
        $respond(200, ['success' => true, 'data' => ['items' => $items]]);
    }
    $siteCompiler = static function (object $document, object $connection, int $revision) use ($provider): array {
        if (!\Bitrix\Main\Loader::includeModule('prospektweb.frontcalc')) { throw new \RuntimeException('FrontCalc site adapter unavailable.', 503); }
        return (new \Prospektweb\Frontcalc\Service\BitrixDocumentSiteCompiler($provider))($document, $connection, $revision);
    };
    require_once $module . '/lib/Documents/BitrixInputMappingValidator.php';
    require_once $module . '/lib/Documents/BitrixOutputMappingValidator.php';
    $formRuntime = static function (object $document, int $revision) use ($module): array {
        if (!\Bitrix\Main\Loader::includeModule('prospektweb.frontcalc')) throw new \RuntimeException('Form adapter unavailable.', 503);
        $sectionState = \Bitrix\Main\Loader::getLocal('modules/prospektweb.frontcalc/lib/Service/FormSectionState.php');
        if (!$sectionState) throw new \RuntimeException('Section form adapter unavailable.', 503);
        require_once $sectionState;
        require_once $module . '/lib/Documents/DocumentFormRuntime.php';
        return (new \Prospektweb\Calc\Documents\DocumentFormRuntime())($document, $revision);
    };
    $registryOfferCounts = null;
    if (($request->command->action ?? '') === 'registry' && \Bitrix\Main\Loader::includeModule('prospektweb.frontcalc') && \Bitrix\Main\Loader::includeModule('iblock')) {
        require_once $module.'/lib/Documents/BitrixRegistryOfferCounts.php';
        $config=new \Prospektweb\Frontcalc\Config\ConfigManager();
        $registryOfferCounts=new \Prospektweb\Calc\Documents\BitrixRegistryOfferCounts($connection,$scope,$provider,$config->getProductIblockId(),$config->getSkuIblockId());
    }
    $application = new \Prospektweb\Calc\Documents\DocumentApplication($repository, new \Prospektweb\Calc\Documents\BitrixCoreGateway(), new \Prospektweb\Calc\Documents\BitrixResourceProvider($provider), $siteCompiler, new \Prospektweb\Calc\Documents\BitrixInputMappingValidator($provider), new \Prospektweb\Calc\Documents\BitrixOutputMappingValidator($provider), $formRuntime, $registryOfferCounts);
    $respond(200, ['success' => true, 'data' => $application->command(get_object_vars($request->command))]);
} catch (\InvalidArgumentException | \JsonException $error) {
    $respond(422, ['success' => false, 'error' => 'DOCUMENT_INVALID', 'message' => $error->getMessage()]);
} catch (\Throwable $error) {
    // Keep private request data, credentials and exception messages out of logs.
    // Class and origin identify transport/storage failures hidden by the public response.
    error_log('[prospektweb.documents] ' . json_encode([
        'type' => get_class($error), 'code' => $error->getCode(),
        'file' => basename($error->getFile()), 'line' => $error->getLine(),
    ], JSON_UNESCAPED_SLASHES));
    $status = in_array($error->getCode(), [404, 409, 503], true) ? $error->getCode() : 500;
    $respond($status, ['success' => false, 'error' => $status === 409 ? 'REVISION_CONFLICT' : 'DOCUMENT_UNAVAILABLE',
        'message' => $status === 409 ? $error->getMessage() : (in_array($request->command->action ?? '', ['stageDescriptionTemplates', 'generateStageDescription', 'calculationDescriptionTemplates', 'generateCalculationDescription', 'resourceDescriptionTemplates', 'generateResourceDescription'], true)
            ? 'AI-заполнение недоступно. Проверьте настройки AI Gateway и шаблона описания. Данные не изменены.'
            : (($request->command->action ?? '') === 'auditVersionLogic'
            ? 'AI-анализ недоступен. Проверьте настройки AI Gateway и шаблона анализа. Данные не изменены.'
            : 'Документ недоступен. Изменения не сохранены.'))]);
}
