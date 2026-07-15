<?php
use Bitrix\Main\Loader;
use Prospektweb\LayoutFiles\FileManager;
use Prospektweb\LayoutFiles\Logger;
use Prospektweb\LayoutFiles\Service\YandexDiskClient;

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
if (!Loader::includeModule('prospektweb.calc')) {
    http_response_code(404);
    exit;
}

try {
    $row = FileManager::getByIdHash((int)($_GET['id'] ?? 0), (string)($_GET['hash'] ?? ''));
    if (!FileManager::canDownload($row)) {
        http_response_code(403);
        exit;
    }
    LocalRedirect((new YandexDiskClient())->getDownloadHref($row['YADISK_PATH']), true, '302 Found');
} catch (Throwable $e) {
    Logger::error('download', $e, ['id' => $_GET['id'] ?? null]);
    http_response_code(404);
    echo htmlspecialcharsbx($e->getMessage());
}
