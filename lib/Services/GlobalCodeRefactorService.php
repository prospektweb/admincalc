<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Plans and atomically applies AI-approved global-code renames.
 *
 * A preview fingerprint contains the exact before/after mutation set. Apply
 * rebuilds the plan, so a concurrent edit can never be overwritten silently.
 */
final class GlobalCodeRefactorService
{
    private const CODE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const RESERVED = [
        'if', 'round', 'ceil', 'floor', 'min', 'max', 'abs', 'trim', 'lower', 'upper',
        'len', 'contains', 'replace', 'tonumber', 'tostring', 'split', 'join', 'get',
        'getprice', 'regexmatch', 'regexextract', 'true', 'false', 'null', 'undefined',
        'offer', 'product', 'calculator', 'operation', 'operationvariant', 'equipment',
        'material', 'materialvariant', 'stage', 'preset',
    ];

    public function preview(array $request): array
    {
        $this->assertAdmin();
        $plan = $this->buildPlan($request);
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
        $plan = $this->buildPlan($request);
        if (!hash_equals($plan['fingerprint'], $expected)) {
            throw new \RuntimeException('Данные изменились после предварительной проверки. Выполните AI-анализ и проверку влияния повторно.');
        }

        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            foreach ($plan['mutations'] as $mutation) {
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
            $connection->commitTransaction();
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw $error;
        }

        return [
            'status' => 'ok',
            'renames' => $plan['renames'],
            'summary' => $plan['summary'],
            'symbols' => (new GlobalSymbolService())->list(),
        ];
    }

    private function buildPlan(array $request): array
    {
        $renames = $this->normalizeRenames($request['renames'] ?? []);
        $map = [];
        foreach ($renames as $rename) $map[$rename['oldCode']] = $rename['newCode'];

        $config = new ConfigManager();
        $registryId = $config->getIblockId('CALC_GLOBAL_VALUES');
        $presetId = $config->getIblockId('CALC_PRESETS');
        $settingsId = $config->getIblockId('CALC_SETTINGS');
        $stagesId = $config->getIblockId('CALC_STAGES');
        $mutations = [];

        $registry = $this->loadRegistry($registryId);
        $legacyCodes = $this->loadLegacyCodes($presetId);
        $allGlobalCodes = array_fill_keys(array_merge(array_keys($registry['byCode']), array_keys($legacyCodes)), true);
        foreach ($renames as $rename) {
            $old = $rename['oldCode'];
            $new = $rename['newCode'];
            if (!isset($allGlobalCodes[$old])) {
                throw new \RuntimeException('Глобальный код ' . $old . ' больше не существует');
            }
            if ($rename['source'] === 'registry') {
                $row = $registry['byId'][$rename['registryId']] ?? null;
                if (!$row || $row['code'] !== $old) {
                    throw new \RuntimeException('Запись реестра для кода ' . $old . ' была изменена');
                }
            }
            if (isset($allGlobalCodes[$new]) && $new !== $old) {
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
            $this->planJsonProperty($mutations, 'stages', $stagesId, $elementId, 'ACTIVATION_CONDITION', $map, 'condition');
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
            if (in_array(strtolower($new), self::RESERVED, true)) throw new \InvalidArgumentException('Код ' . $new . ' зарезервирован языком формул');
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
        $targets = array_fill_keys(array_values($map), true);
        foreach ($this->elementIds($iblockId) as $elementId) {
            foreach ($this->readPropertyRows($iblockId, $elementId, 'PARAMS') as $row) {
                $code = trim($row['value']);
                if (isset($targets[$code])) throw new \RuntimeException('Новый глобальный код ' . $code . ' конфликтует с входным параметром калькулятора #' . $elementId);
            }
            foreach ($this->readPropertyRows($iblockId, $elementId, 'LOGIC_JSON') as $row) {
                $logic = json_decode($row['value'], true);
                foreach (is_array($logic['vars'] ?? null) ? $logic['vars'] : [] as $variable) {
                    $code = trim((string)($variable['name'] ?? ''));
                    if (($variable['scope'] ?? 'local') !== 'global' && isset($targets[$code])) {
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
        $iterator = \CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'], false, false, ['ID', 'NAME', 'CODE']);
        while ($element = $iterator->GetNextElement()) {
            $fields = $element->GetFields();
            $properties = $element->GetProperties();
            $row = [
                'id' => (int)$fields['ID'],
                'code' => (string)$fields['CODE'],
                'title' => (string)$fields['NAME'],
                'kind' => (string)($properties['KIND']['VALUE'] ?? 'constant'),
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

    private function planJsonProperty(array &$mutations, string $storage, int $iblockId, int $elementId, string $propertyCode, array $map, string $mode): void
    {
        $rows = $this->readPropertyRows($iblockId, $elementId, $propertyCode);
        if ($rows === [] || trim($rows[0]['value']) === '') return;
        $value = json_decode($rows[0]['value'], true);
        if (!is_array($value)) return;
        $afterValue = $mode === 'condition' ? $this->rewriteCondition($value, $map) : $this->rewriteLogic($value, $map);
        $after = $rows;
        $after[0]['value'] = json_encode($afterValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->appendPropertyMutation($mutations, $storage, $iblockId, $elementId, $propertyCode, $rows, $after, 'html');
    }

    private function rewriteCondition(array $value, array $map): array
    {
        if (isset($value['code']) && is_string($value['code'])) $value['code'] = $map[$value['code']] ?? $value['code'];
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
        } elseif ($mutation['mode'] === 'described') {
            $value = array_map(static fn(array $row): array => ['VALUE' => $row['value'], 'DESCRIPTION' => $row['description']], $after);
        } else {
            $value = array_map(static fn(array $row): string => $row['value'], $after);
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
