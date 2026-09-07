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
    if (!preg_match('/^[A-Za-z0-9]{1,2}$/D', $siteId) || !\CSite::GetByID($siteId)->Fetch()) { throw new \InvalidArgumentException('Unknown site scope.'); }
    $module = dirname(__DIR__);
    // Installed tools are copied outside the module. Resolve the real module path through Loader.
    $module = \Bitrix\Main\Loader::getLocal('modules/prospektweb.calc');
    if (!$module) { throw new \RuntimeException('Module path unavailable.'); }
    require_once $module . '/lib/Documents/BitrixConnection.php';
    require_once $module . '/lib/Documents/DocumentApplication.php';
    require_once $module . '/lib/Documents/BitrixCoreGateway.php';
    require_once $module . '/lib/Documents/BitrixResourceProvider.php';
    $repository = new \Prospektweb\Calc\Documents\DocumentRepository(new \Prospektweb\Calc\Documents\BitrixConnection(\Bitrix\Main\Application::getConnection()), 'site:' . $siteId, 'user:' . (int)$USER->GetID());
    $provider = (string)(new \Prospektweb\Calc\Config\ConfigManager())->getOption('DOCUMENT_RESOURCE_PROVIDER', '');
    $application = new \Prospektweb\Calc\Documents\DocumentApplication($repository, new \Prospektweb\Calc\Documents\BitrixCoreGateway(), new \Prospektweb\Calc\Documents\BitrixResourceProvider($provider));
    $respond(200, ['success' => true, 'data' => $application->command(get_object_vars($request->command))]);
} catch (\InvalidArgumentException | \JsonException $error) {
    $respond(422, ['success' => false, 'error' => 'DOCUMENT_INVALID', 'message' => $error->getMessage()]);
} catch (\Throwable $error) {
    $status = in_array($error->getCode(), [404, 409, 503], true) ? $error->getCode() : 500;
    $respond($status, ['success' => false, 'error' => $status === 409 ? 'REVISION_CONFLICT' : 'DOCUMENT_UNAVAILABLE',
        'message' => $status === 409 ? $error->getMessage() : 'Документ недоступен. Изменения не сохранены.']);
}
