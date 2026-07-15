<?php
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Prospektweb\LayoutFiles\DesiredReceiveDateManager;
use Prospektweb\LayoutFiles\Logger;

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
header('Content-Type: application/json; charset=UTF-8');

$action = '';
try {
    if (!check_bitrix_sessid()) {
        throw new RuntimeException('Сессия истекла. Обновите страницу.');
    }
    if (!Loader::includeModule('prospektweb.calc')) {
        throw new RuntimeException('Модуль загрузки макетов не установлен.');
    }
    $request = Context::getCurrent()->getRequest();
    $action = (string)$request->get('action');
    switch ($action) {
        case 'get': $data = DesiredReceiveDateManager::getCurrent(); break;
        case 'set': $data = DesiredReceiveDateManager::set((string)$request->getPost('value')); break;
        case 'clear': $data = DesiredReceiveDateManager::clear(); break;
        case 'sync': $data = DesiredReceiveDateManager::sync(); break;
        default: throw new RuntimeException('Неизвестное действие.');
    }
    echo Json::encode(['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    if (class_exists(Logger::class)) {
        Logger::error('desired_receive_date.' . ($action ?: 'unknown'), $e, ['post' => $_POST]);
    }
    echo Json::encode(['success' => false, 'error' => $e->getMessage()]);
}
