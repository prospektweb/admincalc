<?php

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/tools/control_center_deployment.php');

if (!is_string($endpoint)) {
    throw new RuntimeException('Missing catalog deployment endpoint source');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(strpos($endpoint, "\$requestMethod !== 'POST'") !== false, 'Deployment API must accept POST only');
$assert(strpos($endpoint, 'check_bitrix_sessid()') !== false, 'Deployment API must enforce Bitrix CSRF protection');
$assert(strpos($endpoint, '$USER->IsAdmin()') !== false, 'Deployment API must require an administrator');

$sessionHydration = strpos($endpoint, "\$_REQUEST['sessid'] = \$requestSessid");
$postSessionHydration = strpos($endpoint, "\$_POST['sessid'] = \$requestSessid");
$adminProlog = strpos(
    $endpoint,
    "require_once \$_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php'"
);
$assert(
    $sessionHydration !== false
        && $postSessionHydration !== false
        && $adminProlog !== false
        && $sessionHydration < $adminProlog
        && $postSessionHydration < $adminProlog,
    'JSON sessid must hydrate request and POST before the Bitrix admin prolog'
);

$assert(strpos($endpoint, "if (\$action === 'analyze')") !== false, 'Deployment API must expose dry-run analysis');
$assert(strpos($endpoint, "if (\$action === 'apply')") !== false, 'Deployment API must expose an explicit apply action');
$assert(strpos($endpoint, "\$request['expectedPlanHash']") !== false, 'Apply must consume the expected plan hash');
$assert(strpos($endpoint, "\$request['confirmation']") !== false, 'Apply must consume the exact confirmation phrase');
$assert(strpos($endpoint, "\$request['allowPopulatedCatalog']") !== false, 'Apply must require an explicit populated-catalog decision');
$assert(strpos($endpoint, "header('Cache-Control: no-store, private')") !== false, 'Deployment responses must not be cached');

echo "Control center deployment API static tests passed\n";
