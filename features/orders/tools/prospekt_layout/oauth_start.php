<?php
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Random;
use Prospektweb\LayoutFiles\Config;

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

if (!Loader::includeModule('prospektweb.calc')) {
    http_response_code(404);
    exit;
}

global $USER;
if (!is_object($USER) || !$USER->IsAdmin()) {
    http_response_code(403);
    exit;
}

if (!check_bitrix_sessid()) {
    http_response_code(403);
    echo 'Сессия истекла. Вернитесь в настройки модуля и повторите подключение.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Option::set(Config::MODULE_ID, 'yadisk_client_id', trim((string)($_POST['yadisk_client_id'] ?? '')));
    $secret = trim((string)($_POST['yadisk_client_secret'] ?? ''));
    if ($secret !== '') {
        Option::set(Config::MODULE_ID, 'yadisk_client_secret', $secret);
    }
}

$clientId = Config::getClientId();
if ($clientId === '') {
    echo 'Укажите Client ID OAuth-приложения Яндекса.';
    exit;
}
if (Config::getClientSecret() === '') {
    echo 'Укажите Client Secret OAuth-приложения Яндекса.';
    exit;
}

$state = bin2hex(Random::getBytes(16));
$_SESSION['PROSPEKTWEB_LAYOUTFILES_OAUTH_STATE'] = $state;

$url = 'https://oauth.yandex.ru/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id' => $clientId,
    'redirect_uri' => Config::OAUTH_VERIFICATION_URI,
    'state' => $state,
    'force_confirm' => 'yes',
]);

LocalRedirect($url, true, '302 Found');
