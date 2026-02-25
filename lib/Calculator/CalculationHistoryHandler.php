<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Loader;
use Bitrix\Highloadblock\HighloadBlockTable;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Обработчик сохранения истории расчётов
 */
class CalculationHistoryHandler
{
    private const MODULE_ID = 'prospektweb.calc';
    private const LOG_FILE = '/local/logs/prospektweb.calc.calculation_history.log';

    private ConfigManager $configManager;
    private bool $supportsXmlId = false;
    private bool $supportsName = false;

    public function __construct()
    {
        if (!Loader::includeModule('highloadblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix highloadblock');
        }
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix iblock');
        }
        $this->configManager = new ConfigManager();
    }

    /**
     * @param array $payload Массив с ключом offers/results
     */
    public function handle(array $payload): array
    {
        global $USER;

        $userId = $USER ? (int)$USER->GetID() : 0;
        if ($userId <= 0) {
            return [
                'status' => 'error',
                'message' => 'Пользователь не авторизован',
                'results' => [],
                'total' => 0,
                'saved' => 0,
            ];
        }

        $offers = $this->normalizeOffers($payload);
        if (empty($offers)) {
            return [
                'status' => 'error',
                'message' => 'Пустой payload: ожидается непустой массив offers или results',
                'results' => [],
                'total' => 0,
                'saved' => 0,
            ];
        }

        $hlblockId = (int)Option::get(self::MODULE_ID, 'HIGHLOAD_CALC_HISTORY_ID', 0);
        if ($hlblockId <= 0) {
            throw new \Exception('HighloadBlock для истории расчётов не создан');
        }

        $hlblock = HighloadBlockTable::getById($hlblockId)->fetch();
        if (!$hlblock) {
            throw new \Exception('HighloadBlock не найден');
        }

        $entity = HighloadBlockTable::compileEntity($hlblock);
        $entityClass = $entity->getDataClass();
        $fields = $entity->getFields();
        $this->supportsXmlId = isset($fields['UF_XML_ID']);
        $this->supportsName = isset($fields['UF_NAME']);

        $historyLimit = max(1, (int)Option::get(self::MODULE_ID, 'CALC_HISTORY_LIMIT', 10));
        $skuIblockId = $this->configManager->getSkuIblockId();
        if ($skuIblockId <= 0) {
            throw new \Exception('ID инфоблока ТП не настроен');
        }

        $results = [];
        $savedCount = 0;

        foreach ($offers as $offerData) {
            $validation = $this->validateOfferPayload($offerData);
            $offerId = $validation['offerId'];

            if (!$validation['isValid']) {
                $reason = implode('; ', $validation['errors']);
                $this->logOfferRejection($offerId, $reason, $offerData);
                $results[] = [
                    'offerId' => $offerId,
                    'historyId' => null,
                    'status' => 'error',
                    'message' => $reason,
                ];
                continue;
            }

            $json = $validation['json'];
            $sanitizedJson = $this->sanitizeHistoryPayload($json);
            if (empty($sanitizedJson)) {
                $message = 'Не удалось подготовить расчётный snapshot для сохранения';
                $this->logOfferRejection($offerId, $message, $offerData);
                $results[] = [
                    'offerId' => $offerId,
                    'historyId' => null,
                    'status' => 'error',
                    'message' => $message,
                ];
                continue;
            }

            $jsonString = json_encode($sanitizedJson, JSON_UNESCAPED_UNICODE);
            if (!is_string($jsonString) || $jsonString === '') {
                $message = 'Не удалось подготовить расчётные данные для сохранения';
                $this->logOfferRejection($offerId, $message, $offerData);
                $results[] = [
                    'offerId' => $offerId,
                    'historyId' => null,
                    'status' => 'error',
                    'message' => $message,
                ];
                continue;
            }

            try {
                $this->pruneExcessHistory($entityClass, $offerId, $historyLimit);

                $historyXmlId = $this->supportsXmlId ? $this->buildHistoryXmlId($offerId, $offerData) : null;
                $addData = [
                    'UF_DATETIME' => new \Bitrix\Main\Type\DateTime(),
                    'UF_USER_ID' => $userId,
                    'UF_OFFER_ID' => $offerId,
                    'UF_JSON' => $jsonString,
                ];

                if ($this->supportsXmlId && $historyXmlId !== null) {
                    $addData['UF_XML_ID'] = $historyXmlId;
                }

                if ($this->supportsName) {
                    $addData['UF_NAME'] = 'Расчёт ТП #' . $offerId;
                }

                $addResult = $entityClass::add($addData);
                if ($addResult->isSuccess()) {
                    $historyId = $addResult->getId();
                    $historyLinks = $this->collectHistoryLinks($entityClass, $offerId, $historyLimit);
                    $this->updateCompletedCalcs($offerId, $skuIblockId, $historyLinks);

                    $results[] = [
                        'offerId' => $offerId,
                        'historyId' => $historyId,
                        'historyXmlId' => $historyXmlId,
                        'status' => 'ok',
                    ];
                    $savedCount++;
                } else {
                    $errorMessage = implode(', ', $addResult->getErrorMessages());
                    $this->logOfferRejection($offerId, $errorMessage, $offerData);
                    $results[] = [
                        'offerId' => $offerId,
                        'historyId' => null,
                        'status' => 'error',
                        'message' => $errorMessage,
                    ];
                }
            } catch (\Exception $e) {
                $this->logOfferRejection($offerId, $e->getMessage(), $offerData);
                $results[] = [
                    'offerId' => $offerId,
                    'historyId' => null,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'status' => $savedCount > 0 ? 'ok' : 'error',
            'results' => $results,
            'total' => count($offers),
            'saved' => $savedCount,
        ];
    }

    private function normalizeOffers(array $payload): array
    {
        if (isset($payload['offers']) && is_array($payload['offers'])) {
            return $payload['offers'];
        }

        if (!isset($payload['results']) || !is_array($payload['results'])) {
            return [];
        }

        $offers = [];
        $timestamp = $payload['timestamp'] ?? null;

        foreach ($payload['results'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ($timestamp !== null && !array_key_exists('timestamp', $item)) {
                $item['timestamp'] = $timestamp;
            }

            $offers[] = [
                'offerId' => (int)($item['offerId'] ?? $item['offerID'] ?? $item['id'] ?? 0),
                'json' => array_key_exists('json', $item) ? $item['json'] : $item,
            ];
        }

        return $offers;
    }

    private function buildHistoryXmlId(int $offerId, array $offerData): string
    {
        $jsonData = $offerData['json'] ?? [];

        if (is_string($jsonData)) {
            $decoded = json_decode($jsonData, true);
            $jsonData = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($jsonData)) {
            $jsonData = [];
        }

        $presetId = (int)($jsonData['presetId'] ?? $offerData['presetId'] ?? 0);
        $timestamp = (string)($jsonData['timestamp'] ?? $offerData['timestamp'] ?? time());

        return sprintf('%d_%d_%s', $offerId, $presetId, preg_replace('/[^0-9]/', '', $timestamp) ?: (string)time());
    }

    private function sanitizeHistoryPayload($json)
    {
        if (is_string($json)) {
            $decoded = json_decode($json, true);
            $json = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($json)) {
            return [];
        }

        return $this->removeKeysRecursively($json, ['inputs', 'logicApplied', 'logs', 'variables']);
    }

    private function removeKeysRecursively(array $payload, array $keysToRemove): array
    {
        foreach ($payload as $key => $value) {
            if (in_array((string)$key, $keysToRemove, true)) {
                unset($payload[$key]);
                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->removeKeysRecursively($value, $keysToRemove);
            }
        }

        return $payload;
    }

    private function validateOfferPayload($offerData): array
    {
        $errors = [];

        if (!is_array($offerData)) {
            return [
                'offerId' => 0,
                'json' => null,
                'isValid' => false,
                'errors' => ['Некорректный формат offer: ожидается объект'],
            ];
        }

        $offerId = (int)($offerData['offerId'] ?? 0);
        $json = $offerData['json'] ?? null;

        if ($offerId <= 0) {
            $errors[] = 'Некорректный offerId';
        }

        if ($json === null) {
            $errors[] = 'Отсутствует обязательное поле json';
        } elseif (is_array($json) && empty($json)) {
            $errors[] = 'Пустые расчётные данные: json не должен быть пустым объектом';
        } elseif (is_string($json)) {
            $decoded = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'Поле json должно содержать валидный JSON';
            } elseif (is_array($decoded) && empty($decoded)) {
                $errors[] = 'Пустые расчётные данные: json не должен быть пустым объектом';
            }
        }

        return [
            'offerId' => $offerId,
            'json' => $json,
            'isValid' => empty($errors),
            'errors' => $errors,
        ];
    }

    private function logOfferRejection(int $offerId, string $reason, array $offerData = []): void
    {
        Debug::writeToFile([
            'offerId' => $offerId,
            'reason' => $reason,
            'offerData' => $offerData,
        ], 'CalculationHistoryHandler rejection', self::LOG_FILE);
    }

    private function pruneExcessHistory(string $entityClass, int $offerId, int $historyLimit): void
    {
        $rows = $entityClass::getList([
            'filter' => ['UF_OFFER_ID' => $offerId],
            'order' => ['UF_DATETIME' => 'DESC', 'ID' => 'DESC'],
            'select' => ['ID'],
        ]);

        $index = 0;
        while ($row = $rows->fetch()) {
            $index++;
            if ($index < $historyLimit) {
                continue;
            }

            $historyId = (int)($row['ID'] ?? 0);
            if ($historyId > 0) {
                $entityClass::delete($historyId);
            }
        }
    }

    private function collectHistoryLinks(string $entityClass, int $offerId, int $historyLimit): array
    {
        $historyLinks = [];

        $rows = $entityClass::getList([
            'filter' => ['UF_OFFER_ID' => $offerId],
            'order' => ['UF_DATETIME' => 'DESC', 'ID' => 'DESC'],
            'limit' => $historyLimit,
            'select' => ['ID', 'UF_XML_ID'],
        ]);

        while ($row = $rows->fetch()) {
            $link = '';
            if ($this->supportsXmlId && isset($row['UF_XML_ID']) && (string)$row['UF_XML_ID'] !== '') {
                $link = (string)$row['UF_XML_ID'];
            } else {
                $link = (string)($row['ID'] ?? '');
            }

            if ($link !== '') {
                $historyLinks[] = $link;
            }
        }

        return array_values(array_unique($historyLinks));
    }

    /**
     * @param string[] $historyLinks
     */
    private function updateCompletedCalcs(int $offerId, int $skuIblockId, array $historyLinks): void
    {
        \CIBlockElement::SetPropertyValuesEx($offerId, $skuIblockId, [
            'COMPLETED_CALCS' => empty($historyLinks) ? false : $historyLinks,
        ]);
    }
}
