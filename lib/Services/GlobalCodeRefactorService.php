<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;

/**
 * Plans and atomically applies reviewed global-code renames.
 *
 * A preview fingerprint contains the exact before/after mutation set. Apply
 * rebuilds the plan, so a concurrent edit can never be overwritten silently.
 */
final class GlobalCodeRefactorService
{
    private const CODE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function preview(array $request): array
    {
        $this->assertAdmin();
        $plan = $this->buildPlan(
            $request,
            (new NeutralFormulaPolicy())->readNeutralContractAuthority()
        );
        return [
            'status' => 'ok',
            'fingerprint' => $plan['fingerprint'],
            'renames' => $plan['renames'],
            'summary' => $plan['summary'],
            'impacts' => $plan['impacts'],
        ];
    }

    public function apply(array $request): array
    {
        $this->assertAdmin();
        $expected = trim((string)($request['fingerprint'] ?? ''));
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $expected)) {
            throw new \InvalidArgumentException('Не передан корректный fingerprint предварительной проверки');
        }
        $plan = $this->buildPlan(
            $request,
            (new NeutralFormulaPolicy())->readNeutralContractAuthority()
        );
        if (!hash_equals($plan['fingerprint'], $expected)) {
            throw new \RuntimeException('Данные изменились после предварительной проверки. Повторите проверку влияния переименования.');
        }

        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $authority = (new NeutralFormulaPolicy())->lockNeutralContractAuthority($connection);
            if (($authority['recoveryProtected'] ?? false) === true) {
                throw new \RuntimeException(
                    'Preset 12740 global registry is frozen while retained rollback evidence is pending reapply.',
                    409
                );
            }
            $lockedGlobalIblockId = (int)($authority['globalIblockId'] ?? 0);
            if ($lockedGlobalIblockId <= 0) {
                throw new \RuntimeException('Pinned neutral refactor registry is invalid.', 409);
            }
            $this->lockRegistryRows($connection, $lockedGlobalIblockId);
            $lockedPlan = $this->buildPlan($request, $authority);
            if (!hash_equals($plan['fingerprint'], $lockedPlan['fingerprint'])) {
                throw new \RuntimeException(
                    'Global values changed while locking the neutral formula contract. Repeat the preview.',
                    409
                );
            }
            $this->assertRequiredNeutralRenamesAllowed($lockedPlan['renames'], $authority);
            $lockedRegistry = $this->loadRegistry($lockedGlobalIblockId);
            $prospectiveNeutralRows = $this->buildProspectiveNeutralRows(
                $this->neutralRegistryRows($lockedRegistry['rows']),
                $lockedPlan['mutations']
            );
            if ($authority['active'] || $authority['markerExists']) {
                \Prospektweb\Calc\Install\Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows(
                    $prospectiveNeutralRows
                );
            }
            foreach ($lockedPlan['mutations'] as $mutation) {
                if ($mutation['kind'] === 'element_code') {
                    $element = new \CIBlockElement();
                    if (!$element->Update((int)$mutation['elementId'], ['CODE' => $mutation['after']])) {
                        throw new \RuntimeException('Не удалось изменить код глобального значения: ' . trim((string)$element->LAST_ERROR));
                    }
                    $stored = \CIBlockElement::GetList([], [
                        'ID' => (int)$mutation['elementId'],
                        'IBLOCK_ID' => (int)$mutation['iblockId'],
                    ], false, ['nTopCount' => 1], ['CODE'])->Fetch();
                    if ((string)($stored['CODE'] ?? '') !== (string)$mutation['after']) {
                        throw new \RuntimeException('Проверка записи нового глобального кода не пройдена');
                    }
                    continue;
                }
                $this->writePropertyMutation($mutation);
            }
            $readBackNeutralRows = $this->neutralRegistryRows(
                $this->loadRegistry($lockedGlobalIblockId)['rows']
            );
            if ($authority['active'] || $authority['markerExists']) {
                \Prospektweb\Calc\Install\Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows(
                    $readBackNeutralRows
                );
            }
            if (!hash_equals(
                $this->neutralRegistryFingerprint($prospectiveNeutralRows),
                $this->neutralRegistryFingerprint($readBackNeutralRows)
            )) {
                throw new \RuntimeException('Neutral global-symbol refactor read-back verification failed.', 409);
            }
            $connection->commitTransaction();
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw $error;
        }

        return [
            'status' => 'ok',
            'renames' => $plan['renames'],
            'summary' => $plan['summary'],
            'symbols' => (new GlobalSymbolService())->listReadOnlyFromIblockId(
                $lockedGlobalIblockId,
                NeutralFormulaPolicy::PRESET_ID
            ),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $renames
     * @param array{active:bool,markerExists:bool,recoveryProtected?:bool} $authority
     */
    private function assertRequiredNeutralRenamesAllowed(array $renames, array $authority): void
    {
        if (!$authority['active'] && !$authority['markerExists'] && !($authority['recoveryProtected'] ?? false)) {
            return;
        }
        $required = \Prospektweb\Calc\Install\Preset12740NeutralGlobalSymbolMigrationService::requiredSymbolIdentities();
        $requiredIds = [];
        foreach ($required as $code => $identity) {
            $requiredIds[(int)$identity['id']] = (string)$code;
        }
        foreach ($renames as $rename) {
            if (($rename['source'] ?? '') !== 'registry'
                || (string)($rename['oldCode'] ?? '') === (string)($rename['newCode'] ?? '')) {
                continue;
            }
            $oldCode = (string)($rename['oldCode'] ?? '');
            $registryId = (int)($rename['registryId'] ?? 0);
            if (isset($required[$oldCode]) || isset($requiredIds[$registryId])) {
                throw new \RuntimeException(
                    'Required preset-12740 global symbol ' . $oldCode
                        . ' cannot be renamed while the neutral contract is migrated or active.',
                    409
                );
            }
        }
    }

    private function buildPlan(array $request, ?array $pinnedAuthority = null): array
    {
        $renames = $this->normalizeRenames($request['renames'] ?? []);
        $map = [];
        foreach ($renames as $rename) $map[$rename['oldCode']] = $rename['newCode'];

        if ($pinnedAuthority === null) {
            throw new \RuntimeException('Direct pinned neutral refactor authority is required.', 409);
        }
        $iblockIds = (array)($pinnedAuthority['iblockIds'] ?? []);
        $registryId = (int)($pinnedAuthority['globalIblockId'] ?? 0);
        $presetId = (int)($iblockIds['CALC_PRESETS'] ?? 0);
        $settingsId = (int)($iblockIds['CALC_SETTINGS'] ?? 0);
        $stagesId = (int)($iblockIds['CALC_STAGES'] ?? 0);
        if ($registryId <= 0 || $presetId <= 0 || $settingsId <= 0 || $stagesId <= 0) {
            throw new \RuntimeException('Pinned neutral refactor storages are invalid.', 409);
        }
        $mutations = [];

        $registry = $this->loadRegistry($registryId);
        $legacyCodes = $this->loadLegacyCodes($presetId);
        $allGlobalCodes = [];
        $registryCodeOwners = [];
        foreach ($registry['rows'] as $row) {
            $canonicalCode = strtolower((string)$row['code']);
            $allGlobalCodes[$canonicalCode] = true;
            $registryCodeOwners[$canonicalCode][(int)$row['id']] = true;
        }
        foreach ($registryCodeOwners as $canonicalCode => $owners) {
            if (count($owners) > 1) {
                throw new \RuntimeException(
                    'Global registry contains duplicate case-insensitive code ' . $canonicalCode . '.',
                    409
                );
            }
        }
        foreach (array_keys($legacyCodes) as $legacyCode) {
            $allGlobalCodes[strtolower((string)$legacyCode)] = true;
        }
        foreach ($renames as $rename) {
            $old = $rename['oldCode'];
            $new = $rename['newCode'];
            $oldKey = strtolower($old);
            $newKey = strtolower($new);
            $sourceExists = $rename['source'] === 'registry'
                ? isset($registry['byCode'][$old])
                : isset($legacyCodes[$old]);
            if (!$sourceExists || !isset($allGlobalCodes[$oldKey])) {
                throw new \RuntimeException('Глобальный код ' . $old . ' больше не существует');
            }
            if ($rename['source'] === 'registry') {
                $row = $registry['byId'][$rename['registryId']] ?? null;
                if (!$row || $row['code'] !== $old) {
                    throw new \RuntimeException('Запись реестра для кода ' . $old . ' была изменена');
                }
            }
            if ($old !== $new && $oldKey === $newKey) {
                throw new \InvalidArgumentException(
                    'Case-only global-code renames are not supported by the neutral contract.'
                );
            }
            if (isset($allGlobalCodes[$newKey]) && $newKey !== $oldKey) {
                throw new \InvalidArgumentException('Код ' . $new . ' уже занят другим глобальным значением');
            }
        }
        $this->assertNoCalculatorNamespaceConflicts($settingsId, $map);

        foreach ($renames as $rename) {
            if ($rename['source'] !== 'registry' || $rename['oldCode'] === $rename['newCode']) continue;
            $mutations[] = [
                'kind' => 'element_code',
                'storage' => 'registry',
                'iblockId' => $registryId,
                'elementId' => $rename['registryId'],
                'propertyCode' => 'CODE',
                'before' => $rename['oldCode'],
                'after' => $rename['newCode'],
                'label' => $registry['byId'][$rename['registryId']]['title'] ?? $rename['oldCode'],
            ];
        }

        // Registry variables may reference any renamed symbol. String constants
        // are deliberately not treated as formulas.
        foreach ($registry['rows'] as $row) {
            if ($row['kind'] !== 'variable') continue;
            $this->planTextProperty($mutations, 'registry', $registryId, $row['id'], 'INITIAL_VALUE', $map, 'formula', $row['title']);
        }

        foreach ($this->elementIds($presetId) as $elementId) {
            foreach (['GLOBAL_CONSTANTS', 'GLOBAL_VARIABLES'] as $propertyCode) {
                $this->planDescribedGlobals($mutations, $presetId, $elementId, $propertyCode, $map);
            }
            $this->planJsonProperty($mutations, 'presets', $presetId, $elementId, 'STAGE_GROUPS', $map, 'condition');
        }
        foreach ($this->elementIds($settingsId) as $elementId) {
            $this->planJsonProperty($mutations, 'calculators', $settingsId, $elementId, 'LOGIC_JSON', $map, 'logic');
            $this->planExactListProperty($mutations, 'calculators', $settingsId, $elementId, 'GLOBAL_DEPENDENCIES', $map);
        }
        foreach ($this->elementIds($stagesId) as $elementId) {
            $this->planJsonProperty($mutations, 'stages', $stagesId, $elementId, 'GLOBAL_ASSIGNMENTS', $map, 'logic');
            $this->planJsonProperty($mutations, 'stages', $stagesId, $elementId, 'ACTIVATION_CONDITION', $map, 'condition', 'scalar');
            $this->planDescribedSources($mutations, $stagesId, $elementId, 'OUTPUTS', $map);
            $this->planDescribedSources($mutations, $stagesId, $elementId, 'REFERENCE', $map);
        }

        usort($mutations, static function (array $left, array $right): int {
            return [$left['storage'], $left['iblockId'], $left['elementId'], $left['propertyCode']]
                <=> [$right['storage'], $right['iblockId'], $right['elementId'], $right['propertyCode']];
        });
        $impacts = array_map(static fn(array $mutation): array => [
            'storage' => $mutation['storage'],
            'elementId' => $mutation['elementId'],
            'propertyCode' => $mutation['propertyCode'],
            'label' => $mutation['label'],
        ], $mutations);
        $byStorage = [];
        foreach ($impacts as $impact) $byStorage[$impact['storage']] = ($byStorage[$impact['storage']] ?? 0) + 1;
        $fingerprintPayload = ['renames' => $renames, 'mutations' => $mutations];

        return [
            'renames' => $renames,
            'mutations' => $mutations,
            'impacts' => $impacts,
            'summary' => ['total' => count($mutations), 'byStorage' => $byStorage],
            'fingerprint' => 'sha256:' . hash('sha256', json_encode($fingerprintPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    private function normalizeRenames($raw): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > 50) {
            throw new \InvalidArgumentException('Для безопасного рефакторинга требуется от 1 до 50 переименований');
        }
        $result = [];
        $oldCodes = [];
        $newCodes = [];
        foreach ($raw as $index => $row) {
            if (!is_array($row)) throw new \InvalidArgumentException('Некорректное переименование #' . ($index + 1));
            $source = (string)($row['source'] ?? '');
            $old = trim((string)($row['oldCode'] ?? ''));
            $new = trim((string)($row['newCode'] ?? ''));
            $registryId = (int)($row['registryId'] ?? 0);
            if (!in_array($source, ['registry', 'legacy'], true) || !preg_match(self::CODE_PATTERN, $old) || !preg_match(self::CODE_PATTERN, $new)) {
                throw new \InvalidArgumentException('Некорректные параметры переименования #' . ($index + 1));
            }
            if ($source === 'registry' && $registryId <= 0) throw new \InvalidArgumentException('Не указан ID записи реестра');
            if (\Prospektweb\Calc\Install\Preset12740NeutralGlobalSymbolMigrationService::isReservedGlobalCode($new)) {
                throw new \InvalidArgumentException('Код ' . $new . ' зарезервирован языком формул');
            }
            $oldKey = strtolower($old);
            $newKey = strtolower($new);
            if (isset($oldCodes[$oldKey]) || isset($newCodes[$newKey])) throw new \InvalidArgumentException('Коды в одном рефакторинге не должны повторяться');
            if ($oldKey !== $newKey && isset($oldCodes[$newKey])) throw new \InvalidArgumentException('Циклическое переименование кодов не поддерживается');
            $oldCodes[$oldKey] = true;
            $newCodes[$newKey] = true;
            $result[] = ['source' => $source, 'registryId' => $registryId, 'oldCode' => $old, 'newCode' => $new];
        }
        return $result;
    }

    private function assertNoCalculatorNamespaceConflicts(int $iblockId, array $map): void
    {
        if ($iblockId <= 0) return;
        $targets = [];
        foreach (array_values($map) as $target) {
            $targets[strtolower((string)$target)] = true;
        }
        foreach ($this->elementIds($iblockId) as $elementId) {
            foreach ($this->readPropertyRows($iblockId, $elementId, 'PARAMS') as $row) {
                $code = trim($row['value']);
                if (isset($targets[strtolower($code)])) throw new \RuntimeException('Новый глобальный код ' . $code . ' конфликтует с входным параметром калькулятора #' . $elementId);
            }
            foreach ($this->readPropertyRows($iblockId, $elementId, 'LOGIC_JSON') as $row) {
                $logic = json_decode($row['value'], true);
                foreach (is_array($logic['vars'] ?? null) ? $logic['vars'] : [] as $variable) {
                    $code = trim((string)($variable['name'] ?? ''));
                    if (($variable['scope'] ?? 'local') !== 'global' && isset($targets[strtolower($code)])) {
                        throw new \RuntimeException('Новый глобальный код ' . $code . ' конфликтует с внутренней переменной калькулятора #' . $elementId);
                    }
                }
            }
        }
    }

    private function loadRegistry(int $iblockId): array
    {
        $rows = [];
        $byId = [];
        $byCode = [];
        if ($iblockId <= 0) return compact('rows', 'byId', 'byCode');
        $iterator = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
            false,
            false,
            ['ID', 'NAME', 'CODE', 'ACTIVE']
        );
        while ($element = $iterator->GetNextElement()) {
            $fields = $element->GetFields();
            $properties = $element->GetProperties();
            $initialValue = $properties['INITIAL_VALUE']['~VALUE']['TEXT']
                ?? $properties['INITIAL_VALUE']['VALUE']['TEXT']
                ?? $properties['INITIAL_VALUE']['~VALUE']
                ?? $properties['INITIAL_VALUE']['VALUE']
                ?? '';
            if (is_array($initialValue) || is_object($initialValue)) {
                throw new \RuntimeException('Global registry INITIAL_VALUE is invalid.', 409);
            }
            $row = [
                'id' => (int)$fields['ID'],
                'code' => (string)$fields['CODE'],
                'title' => (string)$fields['NAME'],
                'presetId' => (int)($properties['PRESET_ID']['VALUE'] ?? 0),
                'active' => (string)($fields['ACTIVE'] ?? ''),
                'kind' => (string)($properties['KIND']['VALUE'] ?? ''),
                'dataType' => (string)($properties['DATA_TYPE']['VALUE'] ?? ''),
                'initialValue' => (string)$initialValue,
            ];
            $rows[] = $row;
            $byId[$row['id']] = $row;
            $byCode[$row['code']] = $row;
        }
        return compact('rows', 'byId', 'byCode');
    }

    private function loadLegacyCodes(int $iblockId): array
    {
        $codes = [];
        foreach ($this->elementIds($iblockId) as $elementId) {
            foreach (['GLOBAL_CONSTANTS', 'GLOBAL_VARIABLES'] as $propertyCode) {
                foreach ($this->readPropertyRows($iblockId, $elementId, $propertyCode) as $row) {
                    $code = trim($row['value']);
                    if (preg_match(self::CODE_PATTERN, $code)) $codes[$code] = true;
                }
            }
        }
        return $codes;
    }

    private function planDescribedGlobals(array &$mutations, int $iblockId, int $elementId, string $propertyCode, array $map): void
    {
        $rows = $this->readPropertyRows($iblockId, $elementId, $propertyCode);
        if ($rows === []) return;
        $after = [];
        foreach ($rows as $row) {
            $value = $map[$row['value']] ?? $row['value'];
            [$formula, $suffix] = $this->splitDescription($row['description']);
            $after[] = ['value' => $value, 'description' => $this->replaceIdentifiers($formula, $map) . $suffix];
        }
        $this->appendPropertyMutation($mutations, 'presets', $iblockId, $elementId, $propertyCode, $rows, $after, 'described');
    }

    private function planDescribedSources(array &$mutations, int $iblockId, int $elementId, string $propertyCode, array $map): void
    {
        $rows = $this->readPropertyRows($iblockId, $elementId, $propertyCode);
        if ($rows === []) return;
        $after = array_map(fn(array $row): array => [
            'value' => $row['value'],
            'description' => $this->replaceIdentifiers($row['description'], $map),
        ], $rows);
        $this->appendPropertyMutation($mutations, 'stages', $iblockId, $elementId, $propertyCode, $rows, $after, 'described');
    }

    private function planExactListProperty(array &$mutations, string $storage, int $iblockId, int $elementId, string $propertyCode, array $map): void
    {
        $rows = $this->readPropertyRows($iblockId, $elementId, $propertyCode);
        if ($rows === []) return;
        $after = array_map(fn(array $row): array => ['value' => $map[$row['value']] ?? $row['value'], 'description' => $row['description']], $rows);
        $this->appendPropertyMutation($mutations, $storage, $iblockId, $elementId, $propertyCode, $rows, $after, 'list');
    }

    private function planTextProperty(array &$mutations, string $storage, int $iblockId, int $elementId, string $propertyCode, array $map, string $mode, string $label = ''): void
    {
        $rows = $this->readPropertyRows($iblockId, $elementId, $propertyCode);
        if ($rows === []) return;
        $after = $rows;
        $after[0]['value'] = $this->replaceIdentifiers($rows[0]['value'], $map);
        $this->appendPropertyMutation($mutations, $storage, $iblockId, $elementId, $propertyCode, $rows, $after, $mode, $label);
    }

    private function planJsonProperty(array &$mutations, string $storage, int $iblockId, int $elementId, string $propertyCode, array $map, string $mode, string $storageMode = 'html'): void
    {
        $rows = $this->readPropertyRows($iblockId, $elementId, $propertyCode);
        if ($rows === [] || trim($rows[0]['value']) === '') return;
        $value = json_decode($rows[0]['value'], true);
        if (!is_array($value)) return;
        $afterValue = $mode === 'condition' ? $this->rewriteCondition($value, $map) : $this->rewriteLogic($value, $map);
        $after = $rows;
        $after[0]['value'] = json_encode($afterValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->appendPropertyMutation($mutations, $storage, $iblockId, $elementId, $propertyCode, $rows, $after, $storageMode);
    }

    private function rewriteCondition(array $value, array $map): array
    {
        foreach ($value as $key => &$nested) {
            if ($key === 'code' && is_string($nested)) {
                $nested = $map[$nested] ?? $nested;
            } elseif (is_array($nested)) {
                $nested = $this->rewriteCondition($nested, $map);
            }
        }
        unset($nested);
        return $value;
    }

    private function rewriteLogic(array $value, array $map): array
    {
        foreach ($value as $key => &$nested) {
            if ($key === 'formula' && is_string($nested)) {
                $nested = $this->replaceIdentifiers($nested, $map);
            } elseif ($key === 'globalCode' && is_string($nested)) {
                $nested = $map[$nested] ?? $nested;
            } elseif (is_array($nested)) {
                $nested = $this->rewriteLogic($nested, $map);
            }
        }
        unset($nested);
        if (($value['scope'] ?? null) === 'global' && isset($value['name']) && is_string($value['name'])) {
            $value['name'] = $map[$value['name']] ?? $value['name'];
        }
        return $value;
    }

    private function replaceIdentifiers(string $formula, array $map): string
    {
        $result = '';
        $quote = null;
        $escaped = false;
        $length = strlen($formula);
        for ($index = 0; $index < $length;) {
            $character = $formula[$index];
            if ($quote !== null) {
                $result .= $character;
                if ($escaped) $escaped = false;
                elseif ($character === '\\') $escaped = true;
                elseif ($character === $quote) $quote = null;
                $index++;
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                $result .= $character;
                $index++;
                continue;
            }
            if (preg_match('/[A-Za-z_]/', $character)) {
                $end = $index + 1;
                while ($end < $length && preg_match('/[A-Za-z0-9_]/', $formula[$end])) $end++;
                $token = substr($formula, $index, $end - $index);
                $result .= $map[$token] ?? $token;
                $index = $end;
                continue;
            }
            $result .= $character;
            $index++;
        }
        return $result;
    }

    private function splitDescription(string $description): array
    {
        $escaped = false;
        $length = strlen($description);
        for ($index = 0; $index < $length; $index++) {
            $character = $description[$index];
            if ($character === '\\') { $escaped = !$escaped; continue; }
            if ($character === '|' && !$escaped) return [substr($description, 0, $index), substr($description, $index)];
            $escaped = false;
        }
        return [$description, ''];
    }

    private function appendPropertyMutation(array &$mutations, string $storage, int $iblockId, int $elementId, string $propertyCode, array $before, array $after, string $mode, string $label = ''): void
    {
        if ($before === $after) return;
        $mutations[] = compact('storage', 'iblockId', 'elementId', 'propertyCode', 'before', 'after', 'mode', 'label') + ['kind' => 'property'];
    }

    private function readPropertyRows(int $iblockId, int $elementId, string $propertyCode): array
    {
        if ($iblockId <= 0 || $elementId <= 0) return [];
        $rows = [];
        $iterator = \CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc', 'id' => 'asc'], ['CODE' => $propertyCode]);
        while ($property = $iterator->Fetch()) {
            $raw = $property['~VALUE'] ?? $property['VALUE'] ?? '';
            $value = is_array($raw) ? (string)($raw['TEXT'] ?? '') : (string)$raw;
            if ($value === '' && (string)($property['DESCRIPTION'] ?? '') === '') continue;
            $rows[] = ['value' => $value, 'description' => (string)($property['DESCRIPTION'] ?? '')];
        }
        return $rows;
    }

    private function writePropertyMutation(array $mutation): void
    {
        $after = $mutation['after'];
        if ($mutation['mode'] === 'html' || $mutation['mode'] === 'formula') {
            $value = ['VALUE' => ['TEXT' => (string)($after[0]['value'] ?? ''), 'TYPE' => 'TEXT']];
        } elseif ($mutation['mode'] === 'scalar') {
            $value = (string)($after[0]['value'] ?? '');
        } elseif ($mutation['mode'] === 'described') {
            $value = array_map(static fn(array $row): array => ['VALUE' => $row['value'], 'DESCRIPTION' => $row['description']], $after);
        } else {
            $value = array_map(static fn(array $row): string => $row['value'], $after);
        }
        // PHP 8 exposes a Bitrix comparison bug when an existing HTML user-type
        // property is replaced with its converted array value: the core passes
        // that array to strcmp(). Clear the exact property first inside the
        // surrounding transaction, then write the reviewed replacement.
        if ($mutation['mode'] === 'html' || $mutation['mode'] === 'formula') {
            \CIBlockElement::SetPropertyValues(
                (int)$mutation['elementId'],
                (int)$mutation['iblockId'],
                [],
                (string)$mutation['propertyCode']
            );
        }
        \CIBlockElement::SetPropertyValuesEx((int)$mutation['elementId'], (int)$mutation['iblockId'], [
            $mutation['propertyCode'] => $value,
        ]);
        $stored = $this->readPropertyRows((int)$mutation['iblockId'], (int)$mutation['elementId'], $mutation['propertyCode']);
        $expected = $after;
        if ($mutation['mode'] === 'list') {
            $stored = array_map(static fn(array $row): array => ['value' => $row['value'], 'description' => ''], $stored);
            $expected = array_map(static fn(array $row): array => ['value' => $row['value'], 'description' => ''], $expected);
        }
        if ($stored !== $expected) {
            throw new \RuntimeException('Проверка записи ' . $mutation['propertyCode'] . ' элемента #' . $mutation['elementId'] . ' не пройдена');
        }
    }

    /** @param object $connection */
    private function lockRegistryRows($connection, int $iblockId): void
    {
        if ($iblockId <= 0) {
            throw new \RuntimeException('Pinned neutral refactor registry is invalid.', 409);
        }
        $elementIds = [];
        $elements = $connection->query(
            'SELECT ID FROM b_iblock_element WHERE IBLOCK_ID=' . $iblockId . ' ORDER BY ID FOR UPDATE'
        );
        while ($row = $elements->fetch()) {
            $elementIds[] = (int)$row['ID'];
        }
        if ($elementIds === []) {
            throw new \RuntimeException('Pinned neutral refactor registry is empty.', 409);
        }
        $idList = implode(',', $elementIds);
        $connection->queryExecute(
            'SELECT ID FROM b_iblock_element_property WHERE IBLOCK_ELEMENT_ID IN ('
            . $idList . ') ORDER BY ID FOR UPDATE'
        );
        foreach (['b_iblock_element_prop_s' . $iblockId, 'b_iblock_element_prop_m' . $iblockId] as $tableName) {
            if (method_exists($connection, 'isTableExists') && !$connection->isTableExists($tableName)) {
                continue;
            }
            $connection->queryExecute(
                'SELECT IBLOCK_ELEMENT_ID FROM ' . $tableName
                . ' WHERE IBLOCK_ELEMENT_ID IN (' . $idList . ') ORDER BY IBLOCK_ELEMENT_ID FOR UPDATE'
            );
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function neutralRegistryRows(array $rows): array
    {
        $owned = array_values(array_filter(
            $rows,
            static fn($row): bool => is_array($row)
                && (int)($row['presetId'] ?? 0) === NeutralFormulaPolicy::PRESET_ID
        ));
        usort($owned, static fn(array $left, array $right): int =>
            (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0)
        );
        return $owned;
    }

    /**
     * Materialize the exact protected registry state before any write. This is
     * deliberately independent of the formula-reference mutations in other
     * iblocks: only registry identity and INITIAL_VALUE mutations belong here.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $mutations
     * @return array<int,array<string,mixed>>
     */
    private function buildProspectiveNeutralRows(array $rows, array $mutations): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 || isset($byId[$id])) {
                throw new \RuntimeException('Neutral global-symbol registry identity is invalid.', 409);
            }
            $byId[$id] = $row;
        }
        foreach ($mutations as $mutation) {
            if (($mutation['storage'] ?? '') !== 'registry') {
                continue;
            }
            $elementId = (int)($mutation['elementId'] ?? 0);
            if (!isset($byId[$elementId])) {
                // The registry can contain other preset namespaces. Their
                // formula references may be rewritten by the global refactor,
                // but they are outside preset 12740's runtime identity set.
                continue;
            }
            if (($mutation['kind'] ?? '') === 'element_code') {
                $byId[$elementId]['code'] = (string)($mutation['after'] ?? '');
                continue;
            }
            if (($mutation['propertyCode'] ?? '') === 'INITIAL_VALUE') {
                $after = (array)($mutation['after'] ?? []);
                $byId[$elementId]['initialValue'] = (string)($after[0]['value'] ?? '');
            }
        }
        ksort($byId, SORT_NUMERIC);
        return array_values($byId);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function neutralRegistryFingerprint(array $rows): string
    {
        $canonical = [];
        foreach ($rows as $row) {
            $canonical[] = [
                'id' => (int)($row['id'] ?? 0),
                'code' => (string)($row['code'] ?? ''),
                'presetId' => (int)($row['presetId'] ?? 0),
                'active' => (string)($row['active'] ?? ''),
                'kind' => (string)($row['kind'] ?? ''),
                'dataType' => (string)($row['dataType'] ?? ''),
                'initialValue' => (string)($row['initialValue'] ?? ''),
            ];
        }
        usort($canonical, static fn(array $left, array $right): int => $left['id'] <=> $right['id']);
        $encoded = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Neutral registry fingerprint encoding failed.', 409);
        }
        return hash('sha256', $encoded);
    }

    private function elementIds(int $iblockId): array
    {
        if ($iblockId <= 0) return [];
        $ids = [];
        $iterator = \CIBlockElement::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $iblockId], false, false, ['ID']);
        while ($row = $iterator->Fetch()) $ids[] = (int)$row['ID'];
        return $ids;
    }

    private function assertAdmin(): void
    {
        global $USER;
        if (!$USER || !$USER->IsAdmin()) throw new \RuntimeException('Недостаточно прав для безопасного рефакторинга глобальных кодов');
    }
}
