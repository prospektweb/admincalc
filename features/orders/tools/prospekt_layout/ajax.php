<?php
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Prospektweb\LayoutFiles\FileManager;
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
        case 'init':
            $data = FileManager::init([
                'basketId' => (int)$request->getPost('basketId'),
                'productId' => (int)$request->getPost('productId'),
                'name' => (string)$request->getPost('name'),
                'size' => (int)$request->getPost('size'),
            ]);
            break;
        case 'complete':
            $data = FileManager::complete((int)$request->getPost('fileId'), (string)$request->getPost('hash'));
            break;
        case 'delete':
            FileManager::delete((int)$request->getPost('fileId'), (string)$request->getPost('hash'));
            $data = ['deleted' => true];
            break;
        case 'list':
            $data = FileManager::listForBasket((int)$request->getPost('basketId'));
            break;
        default:
            throw new RuntimeException('Неизвестное действие.');
    }

    echo Json::encode(['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    if (class_exists(Logger::class)) {
        Logger::error('ajax.' . ($action ?: 'unknown'), $e, ['post' => $_POST]);
    }
    echo Json::encode(['success' => false, 'error' => $e->getMessage()]);
}
