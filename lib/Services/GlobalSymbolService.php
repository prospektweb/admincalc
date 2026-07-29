<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Prospektweb\Calc\Config\ConfigManager;

final class GlobalSymbolService
{
    private const IBLOCK_CODE = 'CALC_GLOBAL_VALUES';
    private const TYPES = ['auto', 'string', 'number', 'boolean', 'array', 'object'];
    private const KINDS = ['constant', 'variable'];

    public function list(): array
    {
        $iblockId = $this->ensureStorage();
        $result = [];
        $iterator = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
            false,
            false,
            ['ID', 'NAME', 'CODE', 'PREVIEW_TEXT', 'IBLOCK_ID']
        );
        while ($element = $iterator->GetNextElement()) {
            $fields = $element->GetFields();
            $properties = $element->GetProperties();
            $kind = (string)($properties['KIND']['VALUE'] ?? 'constant');
            $dataType = (string)($properties['DATA_TYPE']['VALUE'] ?? 'auto');
            $result[] = [
                'id' => (int)$fields['ID'],
                'code' => (string)$fields['CODE'],
                'title' => (string)$fields['NAME'],
                'description' => (string)($fields['~PREVIEW_TEXT'] ?? $fields['PREVIEW_TEXT'] ?? ''),
                'kind' => in_array($kind, self::KINDS, true) ? $kind : 'constant',
                'dataType' => in_array($dataType, self::TYPES, true) ? $dataType : 'auto',
                'initialValue' => (string)($properties['INITIAL_VALUE']['~VALUE']['TEXT']
                    ?? $properties['INITIAL_VALUE']['VALUE']['TEXT']
                    ?? $properties['INITIAL_VALUE']['VALUE']
                    ?? ''),
            ];
        }
        return $result;
    }

    public function save(array $rows): array
    {
        global $USER;
        if (!$USER || !$USER->IsAdmin()) {
            throw new \RuntimeException('Недостаточно прав для изменения глобального реестра');
        }
        if (count($rows) > 500) {
            throw new \InvalidArgumentException('Слишком много глобальных значений');
        }
        $iblockId = $this->ensureStorage();
        $reservedCodes = $this->collectCalculatorNamespaceCodes();
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Глобальное значение должно быть объектом');
            }
            $id = (int)($row['id'] ?? 0);
            $title = trim((string)($row['title'] ?? ''));
            $description = trim((string)($row['description'] ?? ''));
            $kind = (string)($row['kind'] ?? 'constant');
            $dataType = (string)($row['dataType'] ?? 'auto');
            $initialValue = (string)($row['initialValue'] ?? '');
            if ($title === '') {
                throw new \InvalidArgumentException('Укажите название глобального значения');
            }
            if (!in_array($kind, self::KINDS, true)) {
                throw new \InvalidArgumentException('Некорректный вид глобального значения');
            }
            if (!in_array($dataType, self::TYPES, true)) {
                throw new \InvalidArgumentException('Некорректный тип глобального значения');
            }
            if (mb_strlen($title) > 250 || mb_strlen($description) > 4000 || mb_strlen($initialValue) > 4000) {
                throw new \InvalidArgumentException('Превышена допустимая длина полей глобального значения');
            }
            $elementApi = new \CIBlockElement();
            $fields = [
                'IBLOCK_ID' => $iblockId,
                'NAME' => $title,
                'PREVIEW_TEXT' => $description,
                'PREVIEW_TEXT_TYPE' => 'text',
                'ACTIVE' => 'Y',
            ];
            if ($id > 0) {
                $existing = \CIBlockElement::GetList([], ['ID' => $id, 'IBLOCK_ID' => $iblockId], false, ['nTopCount' => 1], ['ID'])->Fetch();
                if (!$existing || !$elementApi->Update($id, $fields)) {
                    throw new \RuntimeException('Не удалось обновить глобальное значение');
                }
            } else {
                $fields['CODE'] = $this->generateCode($title, $iblockId, $reservedCodes);
                $id = (int)$elementApi->Add($fields);
                if ($id <= 0) {
                    throw new \RuntimeException('Не удалось создать глобальное значение: ' . trim((string)$elementApi->LAST_ERROR));
                }
                $reservedCodes[strtolower($fields['CODE'])] = true;
            }
            \CIBlockElement::SetPropertyValuesEx($id, $iblockId, [
                'KIND' => $kind,
                'DATA_TYPE' => $dataType,
                'INITIAL_VALUE' => ['VALUE' => ['TEXT' => $initialValue, 'TYPE' => 'TEXT']],
            ]);
        }
        $connection->commitTransaction();
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw $error;
        }
        return ['status' => 'ok', 'symbols' => $this->list()];
    }

    public function ensureStorage(): int
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Требуется модуль iblock');
        }
        $config = new ConfigManager();
        $iblockId = $config->getIblockId(self::IBLOCK_CODE);
        if ($iblockId <= 0) {
            $type = \CIBlockType::GetByID('calculator')->Fetch();
            if (!$type) {
                throw new \RuntimeException('Тип инфоблока calculator не найден');
            }
            $iblock = new \CIBlock();
            $iblockId = (int)$iblock->Add([
                'ACTIVE' => 'Y',
                'NAME' => 'Глобальные значения',
                'CODE' => self::IBLOCK_CODE,
                'IBLOCK_TYPE_ID' => 'calculator',
                'SITE_ID' => [defined('SITE_ID') ? SITE_ID : 's1'],
                'WORKFLOW' => 'N',
                'VERSION' => 2,
                'INDEX_ELEMENT' => 'N',
                'INDEX_SECTION' => 'N',
                'GROUP_ID' => [1 => 'R', 2 => 'R'],
            ]);
            if ($iblockId <= 0) {
                throw new \RuntimeException('Не удалось создать реестр глобальных значений: ' . trim((string)$iblock->LAST_ERROR));
            }
            $config->setIblockId(self::IBLOCK_CODE, $iblockId);
        }
        $this->ensureProperty($iblockId, 'KIND', 'Вид значения', 'S', null, 100);
        $this->ensureProperty($iblockId, 'DATA_TYPE', 'Тип данных', 'S', null, 110);
        $this->ensureProperty($iblockId, 'INITIAL_VALUE', 'Начальное значение или формула', 'S', 'HTML', 120);
        return $iblockId;
    }

    private function ensureProperty(int $iblockId, string $code, string $name, string $type, ?string $userType, int $sort): void
    {
        if (\CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, '=CODE' => $code])->Fetch()) {
            return;
        }
        $property = new \CIBlockProperty();
        $fields = [
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'CODE' => $code,
            'NAME' => $name,
            'PROPERTY_TYPE' => $type,
            'SORT' => $sort,
        ];
        if ($userType) {
            $fields['USER_TYPE'] = $userType;
        }
        if (!$property->Add($fields)) {
            throw new \RuntimeException('Не удалось создать свойство ' . $code . ': ' . trim((string)$property->LAST_ERROR));
        }
    }

    private function generateCode(string $hint, int $iblockId, array $reservedCodes = []): string
    {
        $transliterated = class_exists('\CUtil')
            ? \CUtil::translit($hint, 'ru', ['replace_space' => '_', 'replace_other' => '_', 'change_case' => 'L'])
            : $hint;
        $slug = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '_', $transliterated));
        $slug = trim($slug, '_');
        $slug = $slug !== '' ? substr($slug, 0, 80) : 'global_value';
        if (preg_match('/^[0-9]/', $slug)) $slug = 'value_' . $slug;
        if (in_array($slug, [
            'if', 'round', 'ceil', 'floor', 'min', 'max', 'abs', 'trim', 'lower', 'upper',
            'len', 'contains', 'replace', 'tonumber', 'tostring', 'split', 'join', 'get',
            'getprice', 'regexmatch', 'regexextract', 'true', 'false', 'null', 'undefined',
            'offer', 'product', 'calculator', 'operation', 'operationvariant', 'equipment',
            'material', 'materialvariant', 'stage', 'preset',
        ], true)) $slug = 'global_' . $slug;
        $code = $slug;
        $suffix = 2;
        do {
            $exists = isset($reservedCodes[strtolower($code)])
                || \CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, '=CODE' => $code], false, ['nTopCount' => 1], ['ID'])->Fetch();
            if ($exists) $code = substr($slug, 0, 110) . '_' . $suffix++;
        } while ($exists);
        return $code;
    }

    /**
     * Global identifiers share the formula namespace with every calculator
     * input and local variable. Legacy preset declarations also reserve names.
     */
    private function collectCalculatorNamespaceCodes(): array
    {
        $used = [];
        $config = new ConfigManager();
        $settingsIblockId = $config->getIblockId('CALC_SETTINGS');
        if ($settingsIblockId > 0) {
            $iterator = \CIBlockElement::GetList([], ['IBLOCK_ID' => $settingsIblockId], false, false, ['ID']);
            while ($element = $iterator->Fetch()) {
                $elementId = (int)$element['ID'];
                $params = \CIBlockElement::GetProperty($settingsIblockId, $elementId, [], ['CODE' => 'PARAMS']);
                while ($property = $params->Fetch()) {
                    $code = trim((string)($property['VALUE'] ?? ''));
                    if ($code !== '') $used[strtolower($code)] = true;
                }
                $logicRows = \CIBlockElement::GetProperty($settingsIblockId, $elementId, [], ['CODE' => 'LOGIC_JSON']);
                while ($property = $logicRows->Fetch()) {
                    $raw = $property['~VALUE'] ?? $property['VALUE'] ?? '';
                    $json = is_array($raw) ? (string)($raw['TEXT'] ?? '') : (string)$raw;
                    $logic = json_decode($json, true);
                    foreach (is_array($logic['vars'] ?? null) ? $logic['vars'] : [] as $variable) {
                        if (($variable['scope'] ?? 'local') === 'global') continue;
                        $code = trim((string)($variable['name'] ?? ''));
                        if ($code !== '') $used[strtolower($code)] = true;
                    }
                }
            }
        }
        $presetsIblockId = $config->getIblockId('CALC_PRESETS');
        if ($presetsIblockId > 0) {
            $iterator = \CIBlockElement::GetList([], ['IBLOCK_ID' => $presetsIblockId], false, false, ['ID']);
            while ($element = $iterator->Fetch()) {
                foreach (['GLOBAL_CONSTANTS', 'GLOBAL_VARIABLES'] as $propertyCode) {
                    $rows = \CIBlockElement::GetProperty($presetsIblockId, (int)$element['ID'], [], ['CODE' => $propertyCode]);
                    while ($property = $rows->Fetch()) {
                        $code = trim((string)($property['VALUE'] ?? ''));
                        if ($code !== '') $used[strtolower($code)] = true;
                    }
                }
            }
        }
        return $used;
    }
}
