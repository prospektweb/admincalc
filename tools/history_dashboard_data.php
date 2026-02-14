<?php
/**
 * Endpoint отдачи данных истории расчётов для iframe дашборда на вкладке Анализ.
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', false);

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

header('Content-Type: application/json; charset=utf-8');

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Highloadblock\HighloadBlockTable;

try {
    global $USER;

    if (!$USER || !$USER->IsAuthorized()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        die();
    }

    if (!check_bitrix_sessid()) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid session'], JSON_UNESCAPED_UNICODE);
        die();
    }

    if (!Loader::includeModule('prospektweb.calc') || !Loader::includeModule('iblock') || !Loader::includeModule('highloadblock')) {
        http_response_code(500);
        echo json_encode(['error' => 'Required modules are not loaded'], JSON_UNESCAPED_UNICODE);
        die();
    }

    $offerId = (int)($_REQUEST['offerId'] ?? 0);
    if ($offerId <= 0) {
        echo json_encode(['offerId' => 0, 'history' => []], JSON_UNESCAPED_UNICODE);
        die();
    }

    $configManager = new \Prospektweb\Calc\Config\ConfigManager();
    $skuIblockId = $configManager->getSkuIblockId();
    if ($skuIblockId <= 0) {
        echo json_encode(['offerId' => $offerId, 'history' => []], JSON_UNESCAPED_UNICODE);
        die();
    }

    $links = [];
    $rsProperty = \CIBlockElement::GetProperty(
        $skuIblockId,
        $offerId,
        ['SORT' => 'ASC'],
        ['CODE' => 'COMPLETED_CALCS']
    );

    while ($property = $rsProperty->Fetch()) {
        if (!empty($property['VALUE'])) {
            $links[] = (string)$property['VALUE'];
        }
    }

    $links = array_values(array_unique($links));
    if (empty($links)) {
        echo json_encode(['offerId' => $offerId, 'history' => []], JSON_UNESCAPED_UNICODE);
        die();
    }

    $hlblockId = (int)Option::get('prospektweb.calc', 'HIGHLOAD_CALC_HISTORY_ID', 0);
    if ($hlblockId <= 0) {
        echo json_encode(['offerId' => $offerId, 'history' => []], JSON_UNESCAPED_UNICODE);
        die();
    }

    $hlblock = HighloadBlockTable::getById($hlblockId)->fetch();
    if (!$hlblock) {
        echo json_encode(['offerId' => $offerId, 'history' => []], JSON_UNESCAPED_UNICODE);
        die();
    }

    $entity = HighloadBlockTable::compileEntity($hlblock);
    $entityClass = $entity->getDataClass();

    $history = [];
    $rows = $entityClass::getList([
        'filter' => ['UF_OFFER_ID' => $offerId],
        'order' => ['UF_DATETIME' => 'ASC', 'ID' => 'ASC'],
        'select' => ['ID', 'UF_XML_ID', 'UF_DATETIME', 'UF_USER_ID', 'UF_JSON'],
    ]);

    while ($row = $rows->fetch()) {
        $idLink = (string)($row['ID'] ?? '');
        $xmlLink = (string)($row['UF_XML_ID'] ?? '');
        if (!in_array($xmlLink, $links, true) && !in_array($idLink, $links, true)) {
            continue;
        }

        $json = [];
        if (!empty($row['UF_JSON']) && is_string($row['UF_JSON'])) {
            $decoded = json_decode($row['UF_JSON'], true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        $history[] = [
            'id' => (int)$row['ID'],
            'xmlId' => $xmlLink,
            'dateTime' => isset($row['UF_DATETIME']) ? (string)$row['UF_DATETIME'] : null,
            'userId' => isset($row['UF_USER_ID']) ? (int)$row['UF_USER_ID'] : null,
            'json' => $json,
        ];
    }

    echo json_encode([
        'offerId' => $offerId,
        'history' => $history,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
