<?php

namespace Prospektweb\PropValManager\Service;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Application;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\Loader;
use CFile;
use Exception;

final class PropertyDescriptionJsonExporter
{
    private const PUBLIC_DIR = '/upload/prospektweb.propvalmanager';
    private const FILE_PREFIX = 'property-descriptions-';
    private const FILE_EXTENSION = '.json';

    /** @return array{path:string,version:string,url:string} */
    public function export(): array
    {
        if (!Loader::includeModule('highloadblock')) {
            throw new Exception('Модуль highloadblock не подключен.');
        }

        $documentRoot = rtrim((string)Application::getDocumentRoot(), '/');
        if ($documentRoot === '') {
            $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        }

        if ($documentRoot === '') {
            throw new Exception('Не удалось определить DOCUMENT_ROOT для выгрузки JSON описаний.');
        }

        $directoryPath = $documentRoot . self::PUBLIC_DIR;
        Directory::createDirectory($directoryPath);

        $version = date('YmdHis') . '-' . substr(md5((string)microtime(true)), 0, 8);
        $publicPath = self::PUBLIC_DIR . '/' . self::FILE_PREFIX . $version . self::FILE_EXTENSION;
        $filePath = $documentRoot . $publicPath;
        $tmpPath = $filePath . '.tmp';
        $payload = $this->buildPayload($version);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (!is_string($json)) {
            throw new Exception('Не удалось сериализовать JSON описаний значений свойств.');
        }

        if (file_put_contents($tmpPath, $json) === false) {
            throw new Exception('Не удалось записать временный JSON-файл описаний: ' . $tmpPath);
        }

        if (!rename($tmpPath, $filePath)) {
            @unlink($tmpPath);
            throw new Exception('Не удалось заменить публичный JSON-файл описаний: ' . $filePath);
        }

        ModuleConfig::setPropertyDescriptionsJsonPath($publicPath);
        ModuleConfig::setPropertyDescriptionsJsonVersion($version);
        $this->deleteOldVersions($directoryPath, basename($filePath));

        return [
            'path' => $publicPath,
            'version' => $version,
            'url' => $publicPath,
        ];
    }

    /** @return array<string, mixed> */
    private function buildPayload(string $version): array
    {
        $items = [];
        $byCode = [];
        $byPropertyId = [];
        $enum = [];

        foreach ($this->loadRows() as $row) {
            $item = $this->formatRow($row);
            $key = $item['iblockId'] . ':' . $item['propertyId'] . ':' . $item['valueXmlId'];
            $items[$key] = $item;
            $byCode[(string)$item['iblockId']][$item['propertyCode']][$item['valueXmlId']] = $key;
            $byPropertyId[(string)$item['iblockId']][(string)$item['propertyId']][$item['valueXmlId']] = $key;

            if ($item['valueId'] > 0) {
                $enum[(string)$item['valueId']] = [
                    'key' => $key,
                    'iblockId' => $item['iblockId'],
                    'propertyId' => $item['propertyId'],
                    'propertyCode' => $item['propertyCode'],
                    'valueXmlId' => $item['valueXmlId'],
                ];
            }
        }

        return [
            'version' => $version,
            'generatedAt' => date('c'),
            'items' => $items,
            'byCode' => $byCode,
            'byPropertyId' => $byPropertyId,
            'enum' => $enum,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function loadRows(): array
    {
        $hlBlock = (new PropertyValueDescriptionInstaller())->getHighloadBlock();
        if (!$hlBlock) {
            return [];
        }

        $entity = HighloadBlockTable::compileEntity($hlBlock);
        $dataClass = $entity->getDataClass();
        $select = $this->filterSelectFields($entity, [
            'ID', 'UF_ACTIVE', 'UF_IBLOCK_ID', 'UF_PROPERTY_ID', 'UF_PROPERTY_CODE', 'UF_VALUE_ID', 'UF_VALUE_XML_ID',
            'UF_VALUE_NAME', 'UF_TITLE', 'UF_DESCRIPTION', 'UF_IMAGE', 'UF_LINK', 'UF_LINK_TEXT', 'UF_LINK_TARGET',
            'UF_SORT',
        ]);

        $rows = $dataClass::getList([
            'filter' => [
                '=UF_ACTIVE' => 1,
                '>UF_IBLOCK_ID' => 0,
                '>UF_PROPERTY_ID' => 0,
                '!=UF_VALUE_XML_ID' => '',
            ],
            'select' => $select,
            'order' => ['UF_SORT' => 'ASC', 'ID' => 'ASC'],
        ]);

        $result = [];
        while ($row = $rows->fetch()) {
            $result[] = $row;
        }

        return $result;
    }

    /** @param mixed $entity @param string[] $fields @return string[] */
    private function filterSelectFields($entity, array $fields): array
    {
        if (!method_exists($entity, 'hasField')) {
            return $fields;
        }

        $result = [];
        foreach ($fields as $field) {
            if ($entity->hasField($field)) {
                $result[] = $field;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatRow(array $row): array
    {
        $link = trim((string)$this->firstValue($row['UF_LINK'] ?? ''));
        $linkText = trim((string)$this->firstValue($row['UF_LINK_TEXT'] ?? ''));
        $linkTarget = $this->normalizeLinkTarget((string)$this->firstValue($row['UF_LINK_TARGET'] ?? ''));
        $item = [
            'id' => (int)($row['ID'] ?? 0),
            'iblockId' => (int)($row['UF_IBLOCK_ID'] ?? 0),
            'propertyId' => (int)($row['UF_PROPERTY_ID'] ?? 0),
            'propertyCode' => (string)($row['UF_PROPERTY_CODE'] ?? ''),
            'valueId' => (int)($row['UF_VALUE_ID'] ?? 0),
            'valueXmlId' => (string)($row['UF_VALUE_XML_ID'] ?? ''),
            'valueName' => (string)($row['UF_VALUE_NAME'] ?? ''),
            'title' => (string)($row['UF_TITLE'] ?? ''),
            'description' => (string)($row['UF_DESCRIPTION'] ?? ''),
            'image' => $this->getImagePath((int)($row['UF_IMAGE'] ?? 0)),
            'link' => null,
            'sort' => (int)($row['UF_SORT'] ?? 0),
        ];

        if ($link !== '') {
            $item['link'] = [
                'url' => $link,
                'text' => $linkText !== '' ? $linkText : $link,
                'target' => $linkTarget,
            ];
        }

        return $item;
    }

    /** @param mixed $value @return mixed */
    private function firstValue($value)
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ((string)$item !== '') {
                    return $item;
                }
            }

            return '';
        }

        return $value;
    }

    private function normalizeLinkTarget(string $value): string
    {
        return $value === '_blank' ? '_blank' : '_self';
    }

    private function getImagePath(int $fileId): string
    {
        if ($fileId <= 0 || !class_exists(CFile::class)) {
            return '';
        }

        return (string)CFile::GetPath($fileId);
    }

    private function deleteOldVersions(string $directoryPath, string $currentFileName): void
    {
        $files = glob($directoryPath . '/' . self::FILE_PREFIX . '*' . self::FILE_EXTENSION);
        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            if (basename($file) === $currentFileName) {
                continue;
            }

            @unlink($file);
        }
    }
}
