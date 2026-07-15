<?php

namespace Prospektweb\PropValManager\Service;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Loader;
use Exception;

final class PropertyValueDescriptionRepository
{

    /** @return array<string, mixed>|null */
    public function getById(int $id): ?array
    {
        $dataClass = $this->getDataClass();
        if ($dataClass === '' || $id <= 0) {
            return null;
        }

        $row = $dataClass::getById($id)->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findLinked(int $iblockId, int $propertyId, string $valueXmlId): ?array
    {
        $dataClass = $this->getDataClass();
        if ($dataClass === '') {
            return null;
        }

        $row = $dataClass::getList([
            'filter' => [
                '=UF_IBLOCK_ID' => $iblockId,
                '=UF_PROPERTY_ID' => $propertyId,
                '=UF_VALUE_XML_ID' => $valueXmlId,
            ],
            'order' => ['ID' => 'ASC'],
            'limit' => 1,
        ])->fetch();

        return $row ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public function getLinkedMap(array $values): array
    {
        $dataClass = $this->getDataClass();
        if ($dataClass === '' || empty($values)) {
            return [];
        }

        $iblockIds = [];
        $propertyIds = [];
        $xmlIds = [];
        $wanted = [];
        foreach ($values as $value) {
            $iblockId = (int)($value['IBLOCK_ID'] ?? 0);
            $propertyId = (int)($value['PROPERTY_ID'] ?? 0);
            $xmlId = (string)($value['XML_ID'] ?? '');
            if ($iblockId <= 0 || $propertyId <= 0 || $xmlId === '') {
                continue;
            }
            $iblockIds[$iblockId] = $iblockId;
            $propertyIds[$propertyId] = $propertyId;
            $xmlIds[$xmlId] = $xmlId;
            $wanted[$this->makeKey($iblockId, $propertyId, $xmlId)] = true;
        }

        if (empty($wanted)) {
            return [];
        }

        $result = [];
        $rows = $dataClass::getList([
            'filter' => [
                '@UF_IBLOCK_ID' => array_values($iblockIds),
                '@UF_PROPERTY_ID' => array_values($propertyIds),
                '@UF_VALUE_XML_ID' => array_values($xmlIds),
            ],
            'select' => ['*'],
            'order' => ['ID' => 'ASC'],
        ]);

        while ($row = $rows->fetch()) {
            $key = $this->makeKey((int)$row['UF_IBLOCK_ID'], (int)$row['UF_PROPERTY_ID'], (string)$row['UF_VALUE_XML_ID']);
            if (isset($wanted[$key])) {
                $result[$key] = $row;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $binding @param array<string, mixed> $content */
    public function create(array $binding, array $content): int
    {
        $this->validateBinding($binding);
        $dataClass = $this->requireDataClass();
        $result = $dataClass::add(array_merge($binding, $content, ['UF_ACTIVE' => (int)($content['UF_ACTIVE'] ?? 1)]));
        if (!$result->isSuccess()) {
            throw new Exception('Не удалось создать описание: ' . implode('; ', $result->getErrorMessages()));
        }

        $this->refreshPublicData();
        return (int)$result->getId();
    }

    /** @param array<string, mixed> $content */
    public function update(int $id, array $content): void
    {
        $dataClass = $this->requireDataClass();
        $result = $dataClass::update($id, $content);
        if (!$result->isSuccess()) {
            throw new Exception('Не удалось обновить описание: ' . implode('; ', $result->getErrorMessages()));
        }

        $this->refreshPublicData();
    }

    /** @param array<string, mixed> $binding */
    public function link(int $id, array $binding): void
    {
        $this->validateBinding($binding);
        $this->update($id, $binding + ['UF_ACTIVE' => 1]);
    }

    public function unlink(int $id): void
    {
        $this->update($id, [
            'UF_IBLOCK_ID' => 0,
            'UF_PROPERTY_ID' => 0,
            'UF_PROPERTY_CODE' => '',
            'UF_VALUE_ID' => 0,
            'UF_VALUE_XML_ID' => '',
            'UF_VALUE_NAME' => '',
        ]);
    }


    public function delete(int $id): void
    {
        $dataClass = $this->requireDataClass();
        $result = $dataClass::delete($id);
        if (!$result->isSuccess()) {
            throw new Exception('Не удалось удалить описание: ' . implode('; ', $result->getErrorMessages()));
        }

        $this->refreshPublicData();
    }

    /** @return array<int, array<string, mixed>> */
    public function getAvailableDescriptions(): array
    {
        $dataClass = $this->getDataClass();
        if ($dataClass === '') {
            return [];
        }

        $items = [];
        $rows = $dataClass::getList([
            'select' => ['ID', 'UF_TITLE', 'UF_VALUE_NAME', 'UF_PROPERTY_CODE'],
            'order' => ['ID' => 'DESC'],
            'limit' => 100,
        ]);
        while ($row = $rows->fetch()) {
            $items[] = $row;
        }

        return $items;
    }

    public function makeKey(int $iblockId, int $propertyId, string $valueXmlId): string
    {
        return $iblockId . ':' . $propertyId . ':' . $valueXmlId;
    }


    /** @param array<string, mixed> $binding */
    private function validateBinding(array $binding): void
    {
        if ((int)($binding['UF_IBLOCK_ID'] ?? 0) <= 0
            || (int)($binding['UF_PROPERTY_ID'] ?? 0) <= 0
            || (string)($binding['UF_VALUE_XML_ID'] ?? '') === ''
        ) {
            throw new Exception('Для связи описания обязательны IBLOCK_ID, PROPERTY_ID и VALUE_XML_ID.');
        }
    }

    private function requireDataClass(): string
    {
        $dataClass = $this->getDataClass();
        if ($dataClass === '') {
            throw new Exception('HL-блок описаний значений свойств не найден. Переустановите модуль или сохраните настройки.');
        }

        return $dataClass;
    }

    private function refreshPublicData(): void
    {
        PropertyDescriptionService::clearCache();
        (new PropertyDescriptionJsonExporter())->export();
    }

    private function getDataClass(): string
    {
        if (!Loader::includeModule('highloadblock')) {
            return '';
        }

        $hlBlock = (new PropertyValueDescriptionInstaller())->getHighloadBlock();
        if (!$hlBlock) {
            return '';
        }

        $entity = HighloadBlockTable::compileEntity($hlBlock);
        return $entity->getDataClass();
    }
}
