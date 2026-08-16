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
    private const CODE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function list(int $presetId = 0): array
    {
        if ($presetId === NeutralFormulaPolicy::PRESET_ID) {
            return $this->listNeutralPresetReadOnly();
        }
        $iblockId = $this->ensureStorage();
        if ($presetId > 0) {
            $this->claimLegacyRows($iblockId, $presetId);
        }
        return $this->readRows($iblockId, $presetId);
    }

    /**
     * Read the current registry without creating an infoblock, properties or
     * claiming legacy rows. Calculation preview/apply paths must remain free
     * of hidden schema/data mutations.
     */
    public function listReadOnly(int $presetId = 0): array
    {
        if ($presetId === NeutralFormulaPolicy::PRESET_ID) {
            return $this->listNeutralPresetReadOnly();
        }
        $iblockId = $this->storageIblockIdReadOnly();
        if ($iblockId <= 0) {
            return [];
        }

        return $this->readRows($iblockId, $presetId);
    }

    /** @return array<int,array<string,mixed>> */
    private function listNeutralPresetReadOnly(): array
    {
        $authority = (new NeutralFormulaPolicy())->readNeutralContractAuthority();
        $iblockId = (int)($authority['globalIblockId'] ?? 0);
        if ($iblockId <= 0) {
            return [];
        }
        return $this->listReadOnlyFromIblockId($iblockId, NeutralFormulaPolicy::PRESET_ID);
    }

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
            || (string)($row['IBLOCK_TYPE_ID'] ?? '') !== 'calculator'
            || (string)($row['ACTIVE'] ?? '') !== 'Y') {
            throw new \RuntimeException('The pinned global-symbol iblock identity is invalid.', 409);
        }
        return $this->readRows($iblockId, $presetId);
    }

    /** Resolve the registry storage without consulting a mutating installer. */
    public function storageIblockIdReadOnly(): int
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('The iblock module is required.');
        }
        $row = \CIBlock::GetList(
            ['ID' => 'ASC'],
            ['CODE' => self::IBLOCK_CODE, 'TYPE' => 'calculator', 'ACTIVE' => 'Y']
        )->Fetch();

        return is_array($row) ? (int)($row['ID'] ?? 0) : 0;
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
        // Schema provisioning is an installer responsibility. Legacy
        // non-12740 callers may still provision before the write transaction;
        // preset 12740 must resolve only its directly locked option authority.
        $preprovisionedIblockId = $presetId === NeutralFormulaPolicy::PRESET_ID
            ? 0
            : $this->ensureStorage();
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $authority = null;
            $neutralContractProtected = false;
            if ($presetId === NeutralFormulaPolicy::PRESET_ID) {
                $authority = (new NeutralFormulaPolicy())->lockNeutralContractAuthority($connection);
                if (($authority['recoveryProtected'] ?? false) === true) {
                    throw new \RuntimeException(
                        'Preset 12740 global registry is frozen while retained rollback evidence is pending reapply.',
                        409
                    );
                }
                $neutralContractProtected = $authority['active'] || $authority['markerExists'];
            }
            if ($neutralContractProtected) {
                $iblockId = (int)($authority['globalIblockId'] ?? 0);
                if ($iblockId <= 0) {
                    throw new \RuntimeException('Protected neutral registry authority is missing.', 409);
                }
                (new \Prospektweb\Calc\Install\Preset12740NeutralGlobalSymbolCorrectionMigrationService())
                    ->assertActivationReadyLocked(true);
            } else {
                $iblockId = is_array($authority) ? (int)($authority['globalIblockId'] ?? 0) : 0;
                if ($iblockId <= 0) {
                    $iblockId = $preprovisionedIblockId;
                }
                if ($iblockId <= 0) {
                    throw new \RuntimeException(
                        'Preset 12740 global-symbol storage must be provisioned and pinned before authoring.',
                        409
                    );
                }
                $this->claimLegacyRows($iblockId, $presetId);
            }
            $propertyIds = [
                'KIND' => $this->propertyId($iblockId, 'KIND'),
                'DATA_TYPE' => $this->propertyId($iblockId, 'DATA_TYPE'),
                'INITIAL_VALUE' => $this->propertyId($iblockId, 'INITIAL_VALUE'),
                'PRESET_ID' => $this->propertyId($iblockId, 'PRESET_ID'),
            ];
            $reservedCodes = $this->collectCalculatorNamespaceCodes(
                is_array($authority)
                    ? (array)$authority['iblockIds']
                    : null
            );
            foreach ($this->readRows($iblockId, $presetId) as $existingRow) {
                $reservedCodes[strtolower((string)$existingRow['code'])] = true;
            }
            if ($neutralContractProtected) {
                $this->assertNeutralRowsBeforeWrite($rows, $iblockId, $presetId);
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
            if ($neutralContractProtected) {
                NeutralFormulaPolicy::assertFormula($initialValue, 'global symbol ' . ($requestedCode ?: '#' . ($rowIndex + 1)));
            }
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
            if ($neutralContractProtected && $initialValue === '') {
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
        $storedSymbols = $this->readRows($iblockId, $presetId);
        if ($neutralContractProtected) {
            \Prospektweb\Calc\Install\Preset12740NeutralGlobalSymbolCorrectionMigrationService::assertNeutralRuntimeRows(
                $storedSymbols
            );
        }
        $connection->commitTransaction();
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw $error;
        }
        return ['status' => 'ok', 'symbols' => $storedSymbols];
    }

    /** @param array<int,mixed> $rows */
    private function assertNeutralRowsBeforeWrite(array $rows, int $iblockId, int $presetId): void
    {
        $currentRows = $this->readRows($iblockId, $presetId);
        $prospectiveById = [];
        foreach ($currentRows as $currentRow) {
            $currentId = (int)($currentRow['id'] ?? 0);
            if ($currentId <= 0 || isset($prospectiveById[$currentId])) {
                throw new \RuntimeException('Neutral global-symbol registry identity is invalid.', 409);
            }
            $prospectiveById[$currentId] = $currentRow;
        }
        $submittedIds = [];
        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Neutral global-symbol row must be an object.', 409);
            }
            $initialValue = (string)($row['initialValue'] ?? '');
            NeutralFormulaPolicy::assertFormula($initialValue, 'global symbol #' . ($rowIndex + 1));
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                throw new \RuntimeException(
                    'Protected preset 12740 global registry must remain exactly 37 rows.',
                    409
                );
            }
            if (isset($submittedIds[$id])) {
                throw new \InvalidArgumentException('Neutral global-symbol row is submitted more than once.', 409);
            }
            $submittedIds[$id] = true;
            $existing = $prospectiveById[$id] ?? null;
            if (!is_array($existing)) {
                throw new \RuntimeException('Neutral global-symbol row for update was not found.', 409);
            }
            $requestedCode = $this->normalizeRequestedCode($row['code'] ?? '');
            $storedCode = (string)($existing['code'] ?? '');
            if ($requestedCode !== '' && $requestedCode !== $storedCode) {
                throw new \RuntimeException('Required neutral global-symbol identities cannot be renamed by save.', 409);
            }
            $prospectiveById[$id] = array_merge($existing, [
                'code' => $storedCode,
                'kind' => (string)($row['kind'] ?? 'constant'),
                'dataType' => (string)($row['dataType'] ?? 'auto'),
                'initialValue' => $initialValue,
            ]);
        }
        \Prospektweb\Calc\Install\Preset12740NeutralGlobalSymbolCorrectionMigrationService::assertNeutralRuntimeRows(
            array_values($prospectiveById)
        );
    }

    private function clearInitialValueStorageDirect(int $iblockId, int $propertyId, int $elementId): void
    {
        if ($iblockId <= 0 || $propertyId <= 0 || $elementId <= 0) {
            throw new \RuntimeException('Protected INITIAL_VALUE clear target is invalid.', 409);
        }
        $connection = Application::getConnection();
        $table = 'b_iblock_element_prop_s' . $iblockId;
        $column = 'PROPERTY_' . $propertyId;
        if (!method_exists($connection, 'isTableExists') || !$connection->isTableExists($table)) {
            throw new \RuntimeException('Protected INITIAL_VALUE storage table is unavailable.', 409);
        }
        $fields = $connection->getTableFields($table);
        if (!is_array($fields)
            || !array_key_exists('IBLOCK_ELEMENT_ID', $fields)
            || !array_key_exists($column, $fields)) {
            throw new \RuntimeException('Protected INITIAL_VALUE storage column is unavailable.', 409);
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
            throw new \RuntimeException('Protected INITIAL_VALUE clear read-back failed.');
        }
    }

    private function normalizeRequestedCode($value): string
    {
        $code = trim((string)$value);
        if ($code === '') return '';
        if (!preg_match(self::CODE_PATTERN, $code)) {
            throw new \InvalidArgumentException('Код должен начинаться с латинской буквы или _ и содержать только латиницу, цифры и _');
        }
        if (\Prospektweb\Calc\Install\Preset12740NeutralGlobalSymbolMigrationService::isReservedGlobalCode($code)) {
            throw new \InvalidArgumentException('Код ' . $code . ' зарезервирован языком формул');
        }
        return $code;
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
        $this->ensureProperty($iblockId, 'PRESET_ID', 'Пресет', 'N', null, 130);
        return $iblockId;
    }

    /**
     * The registry used to be shared. On the first scoped load, preserve those
     * rows by assigning them to the preset from which the migration is opened.
     */
    private function claimLegacyRows(int $iblockId, int $presetId): void
    {
        $optionName = 'global_symbols_scope_migrated';
        if ((string)\Bitrix\Main\Config\Option::get('prospektweb.calc', $optionName, '') !== '') {
            return;
        }
        $iterator = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', 'PROPERTY_PRESET_ID' => false],
            false,
            false,
            ['ID']
        );
        while ($row = $iterator->Fetch()) {
            \CIBlockElement::SetPropertyValuesEx((int)$row['ID'], $iblockId, ['PRESET_ID' => $presetId]);
        }
        \Bitrix\Main\Config\Option::set('prospektweb.calc', $optionName, (string)$presetId);
    }

    private function ensureProperty(int $iblockId, string $code, string $name, string $type, ?string $userType, int $sort): void
    {
        if (\CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code])->Fetch()) {
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
        if (\Prospektweb\Calc\Install\Preset12740NeutralGlobalSymbolMigrationService::isReservedGlobalCode($slug)) {
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
     * input and local variable. Legacy preset declarations also reserve names.
     */
    private function collectCalculatorNamespaceCodes(?array $pinnedIblockIds = null): array
    {
        $used = [];
        $config = $pinnedIblockIds === null ? new ConfigManager() : null;
        $settingsIblockId = $pinnedIblockIds === null
            ? $config->getIblockId('CALC_SETTINGS')
            : (int)($pinnedIblockIds['CALC_SETTINGS'] ?? 0);
        $presetsIblockId = $pinnedIblockIds === null
            ? $config->getIblockId('CALC_PRESETS')
            : (int)($pinnedIblockIds['CALC_PRESETS'] ?? 0);
        if ($pinnedIblockIds !== null && ($settingsIblockId <= 0 || $presetsIblockId <= 0)) {
            throw new \RuntimeException('Pinned neutral formula storages are invalid.', 409);
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
