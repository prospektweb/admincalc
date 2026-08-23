<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;

final class GlobalSymbolService
{
    private const IBLOCK_CODE = 'CALC_GLOBAL_VALUES';
    private const TYPES = ['auto', 'string', 'number', 'boolean', 'array', 'object'];
    private const KINDS = ['constant', 'variable'];
    private const CODE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * Read an explicitly pinned registry iblock. No CODE search is allowed:
     * duplicate/renamed storage must never silently redirect calculation.
     */
    public function listReadOnlyFromIblockId(int $iblockId, int $presetId = 0): array
    {
        if ($iblockId <= 0 || !Loader::includeModule('iblock')) {
            throw new \RuntimeException('A pinned global-symbol iblock is required.');
        }
        $row = \CIBlock::GetList(['ID' => 'ASC'], ['ID' => $iblockId])->Fetch();
        if (!is_array($row)
            || (int)($row['ID'] ?? 0) !== $iblockId
            || (string)($row['CODE'] ?? '') !== self::IBLOCK_CODE
            || (string)($row['ACTIVE'] ?? '') !== 'Y') {
            throw new \RuntimeException('The pinned global-symbol iblock identity is invalid.', 409);
        }
        return $this->readRows($iblockId, $presetId);
    }

    /** @return array<int,array<string,mixed>> */
    private function readRows(int $iblockId, int $presetId): array
    {
        $result = [];
        $filter = ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'];
        if ($presetId > 0) {
            $filter['=PROPERTY_PRESET_ID'] = $presetId;
        }
        $iterator = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            $filter,
            false,
            false,
            ['ID', 'NAME', 'CODE', 'PREVIEW_TEXT', 'IBLOCK_ID', 'ACTIVE']
        );
        while ($element = $iterator->GetNextElement()) {
            $fields = $element->GetFields();
            $properties = $element->GetProperties();
            $kind = (string)($properties['KIND']['VALUE'] ?? '');
            $dataType = (string)($properties['DATA_TYPE']['VALUE'] ?? '');
            $result[] = [
                'id' => (int)$fields['ID'],
                'iblockId' => $iblockId,
                'presetId' => (int)($properties['PRESET_ID']['VALUE'] ?? 0),
                'active' => (string)($fields['ACTIVE'] ?? ''),
                'code' => (string)$fields['CODE'],
                'title' => (string)$fields['NAME'],
                'description' => (string)($fields['~PREVIEW_TEXT'] ?? $fields['PREVIEW_TEXT'] ?? ''),
                // Runtime/save guards must observe the stored authority
                // exactly; normalizing corrupt metadata could make an invalid
                // required identity appear contract-compliant.
                'kind' => $kind,
                'dataType' => $dataType,
                'initialValue' => (string)($properties['INITIAL_VALUE']['~VALUE']['TEXT']
                    ?? $properties['INITIAL_VALUE']['VALUE']['TEXT']
                    ?? $properties['INITIAL_VALUE']['VALUE']
                    ?? ''),
            ];
        }
        return $result;
    }

    public function save(array $rows, int $presetId): array
    {
        global $USER;
        if (!$USER || !$USER->IsAdmin()) {
            throw new \RuntimeException('Недостаточно прав для изменения глобального реестра');
        }
        if (count($rows) > 500) {
            throw new \InvalidArgumentException('Слишком много глобальных значений');
        }
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Не указан пресет глобальных значений');
        }

        $authority = new CalculatorMutationAuthorityService();
        return $authority->withAuthorityLock($presetId, function (
            bool $_unusedProtection,
            array $pinnedIblockIds
        ) use ($rows, $presetId, $authority): array {
            return $this->saveLocked($rows, $presetId, $authority, $pinnedIblockIds);
        });
    }

    /**
     * Save under an already-held calculator authority. Aggregate semantic
     * mutations use this method so no inner transaction can commit partially.
     *
     * @param array<int,mixed> $rows
     * @param array<string,int> $pinnedIblockIds
     */
    public function saveLocked(
        array $rows,
        int $presetId,
        CalculatorMutationAuthorityService $authority,
        array $pinnedIblockIds
    ): array {
            global $USER;
            if (!$USER || !$USER->IsAdmin()) {
                throw new \RuntimeException('Недостаточно прав для изменения глобального реестра');
            }
            if (count($rows) > 500) {
                throw new \InvalidArgumentException('Слишком много глобальных значений');
            }
            if ($presetId <= 0) {
                throw new \InvalidArgumentException('Не указан пресет глобальных значений');
            }
            $iblockId = (int)($pinnedIblockIds['CALC_GLOBAL_VALUES'] ?? 0);
            if ($iblockId <= 0) {
                throw new \RuntimeException(
                    'Global-symbol storage must be provisioned before authoring.',
                    409
                );
            }
            // Validate the exact pinned iblock identity. Saving must not invoke
            // an installer or silently redirect to a same-code storage.
            $this->listReadOnlyFromIblockId($iblockId, $presetId);
            $propertyIds = [
                'KIND' => $this->propertyId($iblockId, 'KIND'),
                'DATA_TYPE' => $this->propertyId($iblockId, 'DATA_TYPE'),
                'INITIAL_VALUE' => $this->propertyId($iblockId, 'INITIAL_VALUE'),
                'PRESET_ID' => $this->propertyId($iblockId, 'PRESET_ID'),
            ];
            $reservedCodes = $this->collectCalculatorNamespaceCodes($pinnedIblockIds);
            foreach ($this->readRows($iblockId, $presetId) as $existingRow) {
                $reservedCodes[strtolower((string)$existingRow['code'])] = true;
            }
            foreach ($rows as $rowIndex => $row) {
                if (!is_array($row)) {
                    throw new \InvalidArgumentException('Глобальное значение должно быть объектом');
                }
                $id = (int)($row['id'] ?? 0);
                $requestedCode = $this->normalizeRequestedCode($row['code'] ?? '');
                $title = trim((string)($row['title'] ?? ''));
                $description = trim((string)($row['description'] ?? ''));
                $kind = (string)($row['kind'] ?? 'constant');
                $dataType = (string)($row['dataType'] ?? 'auto');
                $initialValue = (string)($row['initialValue'] ?? '');
                CalculatorMutationAuthorityService::assertFormula(
                    $initialValue,
                    'global symbol ' . ($requestedCode ?: '#' . ($rowIndex + 1))
                );
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
                    'SORT' => 100 + ((int)$rowIndex * 10),
                ];
                if ($id > 0) {
                    $existing = \CIBlockElement::GetList([], ['ID' => $id, 'IBLOCK_ID' => $iblockId, '=PROPERTY_PRESET_ID' => $presetId], false, ['nTopCount' => 1], ['ID', 'CODE'])->Fetch();
                    if (!$existing) {
                        throw new \RuntimeException('Глобальное значение для обновления не найдено');
                    }
                    if ($requestedCode !== '' && $requestedCode !== (string)($existing['CODE'] ?? '')) {
                        throw new \RuntimeException('Существующий код можно изменить только через безопасное переименование со списком затронутых ссылок');
                    }
                    if (!$elementApi->Update($id, $fields)) {
                        throw new \RuntimeException('Не удалось обновить глобальное значение');
                    }
                } else {
                    $fields['CODE'] = $requestedCode !== '' ? $requestedCode : $this->generateCode($title, $iblockId, $reservedCodes);
                    if ($requestedCode !== '' && isset($reservedCodes[strtolower($requestedCode)])) {
                        throw new \InvalidArgumentException('Код ' . $requestedCode . ' уже занят или конфликтует с формулой калькулятора');
                    }
                    $id = (int)$elementApi->Add($fields);
                    if ($id <= 0) {
                        throw new \RuntimeException('Не удалось создать глобальное значение: ' . trim((string)$elementApi->LAST_ERROR));
                    }
                    $reservedCodes[strtolower($fields['CODE'])] = true;
                }
                \CIBlockElement::SetPropertyValuesEx($id, $iblockId, [
                    'KIND' => $kind,
                    'DATA_TYPE' => $dataType,
                    'PRESET_ID' => $presetId,
                ]);
                // PHP 8 exposes a Bitrix comparison bug for an existing HTML user-type
                // value (strcmp receives the converted array). Clear it first inside
                // the transaction, then write the converted value into an empty slot.
                if ($initialValue === '') {
                    $this->clearInitialValueStorageDirect(
                        $iblockId,
                        (int)$propertyIds['INITIAL_VALUE'],
                        $id
                    );
                } else {
                    \CIBlockElement::SetPropertyValues($id, $iblockId, [], 'INITIAL_VALUE');
                    \CIBlockElement::SetPropertyValuesEx($id, $iblockId, [
                        'INITIAL_VALUE' => ['VALUE' => ['TEXT' => $initialValue, 'TYPE' => 'TEXT']],
                    ]);
                }
                $storedElement = \CIBlockElement::GetList(
                    [],
                    ['ID' => $id, 'IBLOCK_ID' => $iblockId],
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'IBLOCK_ID']
                )->GetNextElement();
                $storedProperties = $storedElement ? $storedElement->GetProperties() : [];
                $stored = [
                    'KIND' => (string)($storedProperties['KIND']['VALUE'] ?? ''),
                    'DATA_TYPE' => (string)($storedProperties['DATA_TYPE']['VALUE'] ?? ''),
                    'INITIAL_VALUE' => (string)($storedProperties['INITIAL_VALUE']['~VALUE']['TEXT']
                        ?? $storedProperties['INITIAL_VALUE']['VALUE']['TEXT']
                        ?? $storedProperties['INITIAL_VALUE']['VALUE']
                        ?? ''),
                ];
                if (($stored['KIND'] ?? '') !== $kind
                    || ($stored['DATA_TYPE'] ?? '') !== $dataType
                    || ($stored['INITIAL_VALUE'] ?? '') !== $initialValue) {
                    throw new \RuntimeException(sprintf(
                        'Глобальное значение не было полностью записано (вид: %s/%s; тип: %s/%s; длина значения: %d/%d)',
                        $stored['KIND'] ?? '',
                        $kind,
                        $stored['DATA_TYPE'] ?? '',
                        $dataType,
                        mb_strlen($stored['INITIAL_VALUE'] ?? ''),
                        mb_strlen($initialValue)
                    ));
                }
            }

            return ['status' => 'ok', 'symbols' => $this->readRows($iblockId, $presetId)];
        }

    private function clearInitialValueStorageDirect(int $iblockId, int $propertyId, int $elementId): void
    {
        if ($iblockId <= 0 || $propertyId <= 0 || $elementId <= 0) {
            throw new \RuntimeException('INITIAL_VALUE clear target is invalid.', 409);
        }
        $connection = Application::getConnection();
        $table = 'b_iblock_element_prop_s' . $iblockId;
        $column = 'PROPERTY_' . $propertyId;
        if (!method_exists($connection, 'isTableExists') || !$connection->isTableExists($table)) {
            throw new \RuntimeException('INITIAL_VALUE storage table is unavailable.', 409);
        }
        $fields = $connection->getTableFields($table);
        if (!is_array($fields)
            || !array_key_exists('IBLOCK_ELEMENT_ID', $fields)
            || !array_key_exists($column, $fields)) {
            throw new \RuntimeException('INITIAL_VALUE storage column is unavailable.', 409);
        }
        $connection->queryExecute(
            'UPDATE ' . $table . ' SET ' . $column . '=NULL WHERE IBLOCK_ELEMENT_ID=' . $elementId
        );
        $row = $connection->query(
            'SELECT IBLOCK_ELEMENT_ID, ' . $column . ' AS INITIAL_VALUE_RAW FROM ' . $table
            . ' WHERE IBLOCK_ELEMENT_ID=' . $elementId . ' FOR UPDATE'
        )->fetch();
        if (!is_array($row)
            || (int)($row['IBLOCK_ELEMENT_ID'] ?? 0) !== $elementId
            || !array_key_exists('INITIAL_VALUE_RAW', $row)
            || $row['INITIAL_VALUE_RAW'] !== null) {
            throw new \RuntimeException('INITIAL_VALUE clear read-back failed.');
        }
    }

    private function normalizeRequestedCode($value): string
    {
        $code = trim((string)$value);
        if ($code === '') return '';
        if (!preg_match(self::CODE_PATTERN, $code)) {
            throw new \InvalidArgumentException('Код должен начинаться с латинской буквы или _ и содержать только латиницу, цифры и _');
        }
        if (CalculatorMutationAuthorityService::isReservedIdentifier($code)) {
            throw new \InvalidArgumentException('Код ' . $code . ' зарезервирован языком формул');
        }
        return $code;
    }

    private function propertyId(int $iblockId, string $code): int
    {
        $property = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code])->Fetch();
        $propertyId = (int)($property['ID'] ?? 0);
        if ($propertyId <= 0) throw new \RuntimeException('Свойство ' . $code . ' не найдено');
        return $propertyId;
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
        if (CalculatorMutationAuthorityService::isReservedIdentifier($slug)) {
            $slug = 'global_' . $slug;
        }
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
     * input and local variable. Preset declarations also reserve names.
     */
    private function collectCalculatorNamespaceCodes(array $pinnedIblockIds): array
    {
        $used = [];
        $settingsIblockId = (int)($pinnedIblockIds['CALC_SETTINGS'] ?? 0);
        $presetsIblockId = (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0);
        if ($settingsIblockId <= 0 || $presetsIblockId <= 0) {
            throw new \RuntimeException('Pinned formula storages are invalid.', 409);
        }
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
