<?php

namespace Prospektweb\PropValManager\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\UserFieldTable;
use CIBlockProperty;
use CUserTypeEntity;
use Exception;

final class PropertyManager
{
    private const TRACKED_OPTION_KEY = 'MANAGED_PROPERTIES';
    private const TRACKED_UF_OPTION_KEY = 'MANAGED_SECTION_UF';

    /** @return array<string, mixed> */
    public function ensureProperties(int $productsIblockId, int $offersIblockId = 0): array
    {
        $action = $this->ensureTrCaseProperty($productsIblockId);

        return [
            'created' => $action === 'created' ? ['TR_CASE'] : [],
            'updated' => [],
            'exists' => $action === 'exists' ? ['TR_CASE'] : [],
            'offers_iblock_id' => $offersIblockId,
        ];
    }

    public function ensureTrCaseProperty(int $productsIblockId): string
    {
        $productsIblockId = (int)$productsIblockId;

        if ($productsIblockId <= 0 || !Loader::includeModule('iblock')) {
            return 'skipped';
        }

        $existing = CIBlockProperty::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => $productsIblockId,
                'CODE' => 'TR_CASE',
            ]
        )->Fetch();

        if ($existing) {
            return 'exists';
        }

        $property = new CIBlockProperty();
        $propertyId = $property->Add([
            'IBLOCK_ID' => $productsIblockId,
            'NAME' => 'Склонение наименования',
            'ACTIVE' => 'Y',
            'SORT' => 500,
            'CODE' => 'TR_CASE',
            'PROPERTY_TYPE' => 'S',
            'MULTIPLE' => 'Y',
            'IS_REQUIRED' => 'N',
        ]);

        if (!$propertyId) {
            throw new Exception('Не удалось создать свойство TR_CASE: ' . (string)$property->LAST_ERROR);
        }

        $this->trackProperty($productsIblockId, 'TR_CASE');

        return 'created';
    }

    public function removeManagedProperties(int $productsIblockId, int $offersIblockId = 0): void
    {
        if (!Loader::includeModule('iblock')) {
            throw new Exception('Модуль iblock не подключен.');
        }

        $tracked = $this->getTrackedProperties();
        foreach ($tracked as $item) {
            $iblockId = (int)($item['IBLOCK_ID'] ?? 0);
            $code = (string)($item['CODE'] ?? '');
            if ($iblockId <= 0 || $code === '') {
                continue;
            }
            $property = $this->findProperty($iblockId, $code);
            if (!empty($property['ID'])) {
                CIBlockProperty::Delete((int)$property['ID']);
            }
        }

        $ufId = (int)\Bitrix\Main\Config\Option::get(ModuleConfig::MODULE_ID, self::TRACKED_UF_OPTION_KEY, '0');
        if ($ufId > 0) {
            $entity = new CUserTypeEntity();
            $entity->Delete($ufId);
        }

        \Bitrix\Main\Config\Option::delete(ModuleConfig::MODULE_ID, ['name' => self::TRACKED_OPTION_KEY]);
        \Bitrix\Main\Config\Option::delete(ModuleConfig::MODULE_ID, ['name' => self::TRACKED_UF_OPTION_KEY]);
    }

    private function ensureSectionUserField(int $iblockId): string
    {
        $entityId = 'IBLOCK_' . $iblockId . '_SECTION';
        $existing = UserFieldTable::getList([
            'filter' => ['=ENTITY_ID' => $entityId, '=FIELD_NAME' => 'UF_TR_CASE'],
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();

        if ($existing) {
            \Bitrix\Main\Config\Option::set(ModuleConfig::MODULE_ID, self::TRACKED_UF_OPTION_KEY, (string)$existing['ID']);
            return 'updated';
        }

        $entity = new CUserTypeEntity();
        $id = $entity->Add([
            'ENTITY_ID' => $entityId,
            'FIELD_NAME' => 'UF_TR_CASE',
            'USER_TYPE_ID' => 'string',
            'MULTIPLE' => 'Y',
            'MANDATORY' => 'N',
            'EDIT_FORM_LABEL' => ['ru' => 'TR CASE (падежи товара)'],
            'LIST_COLUMN_LABEL' => ['ru' => 'TR CASE'],
            'LIST_FILTER_LABEL' => ['ru' => 'TR CASE'],
        ]);

        if (!$id) {
            throw new Exception('Не удалось создать UF_TR_CASE для разделов инфоблока товаров.');
        }

        \Bitrix\Main\Config\Option::set(ModuleConfig::MODULE_ID, self::TRACKED_UF_OPTION_KEY, (string)$id);
        return 'created';
    }

    /** @param array<string, string> $fields */
    private function ensureProperty(int $iblockId, array $fields): array
    {
        $code = (string)$fields['CODE'];
        $property = $this->findProperty($iblockId, $code);
        $iblockProperty = new CIBlockProperty();

        if (!empty($property['ID'])) {
            $ok = $iblockProperty->Update((int)$property['ID'], array_merge($fields, ['IBLOCK_ID' => $iblockId]));
            if (!$ok) {
                throw new Exception('Не удалось обновить свойство ' . $code . ': ' . (string)$iblockProperty->LAST_ERROR);
            }
            $this->trackProperty($iblockId, $code);
            return ['action' => 'updated', 'code' => $code];
        }

        $newId = $iblockProperty->Add(array_merge($fields, ['IBLOCK_ID' => $iblockId]));
        if (!$newId) {
            throw new Exception('Не удалось создать свойство ' . $code . ': ' . (string)$iblockProperty->LAST_ERROR);
        }
        $this->trackProperty($iblockId, $code);
        return ['action' => 'created', 'code' => $code];
    }

    /** @return array<string, mixed> */
    private function findProperty(int $iblockId, string $code): array
    {
        $res = CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, '=CODE' => $code]);
        $property = $res ? $res->Fetch() : false;
        return is_array($property) ? $property : [];
    }

    private function trackProperty(int $iblockId, string $code): void
    {
        $tracked = $this->getTrackedProperties();
        foreach ($tracked as $item) {
            if ((int)$item['IBLOCK_ID'] === $iblockId && (string)$item['CODE'] === $code) {
                return;
            }
        }
        $tracked[] = ['IBLOCK_ID' => $iblockId, 'CODE' => $code];
        \Bitrix\Main\Config\Option::set(ModuleConfig::MODULE_ID, self::TRACKED_OPTION_KEY, json_encode($tracked, JSON_THROW_ON_ERROR));
    }

    /** @return array<int, array<string, mixed>> */
    private function getTrackedProperties(): array
    {
        $raw = \Bitrix\Main\Config\Option::get(ModuleConfig::MODULE_ID, self::TRACKED_OPTION_KEY, '[]');
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        } catch (ArgumentException|Exception) {
            return [];
        }
    }
}
