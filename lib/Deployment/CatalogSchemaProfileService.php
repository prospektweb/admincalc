<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Deployment;

final class CatalogSchemaProfileService
{
    public const CONTRACT = 'prospektweb.bitrix.catalog-profile/v1';

    /** @var array<int,string> */
    private const COMPARED_FIELDS = [
        'NAME',
        'ACTIVE',
        'SORT',
        'CODE',
        'DEFAULT_VALUE',
        'PROPERTY_TYPE',
        'ROW_COUNT',
        'COL_COUNT',
        'LIST_TYPE',
        'MULTIPLE',
        'XML_ID',
        'FILE_TYPE',
        'MULTIPLE_CNT',
        'WITH_DESCRIPTION',
        'SEARCHABLE',
        'FILTRABLE',
        'IS_REQUIRED',
        'VERSION',
        'USER_TYPE',
        'USER_TYPE_SETTINGS',
        'HINT',
    ];

    /** @var array<string,array<int,string>> */
    private const PROTECTED_CODES = [
        'products' => [
            'CALC_PRESET',
            'FRONTCALC_CONFIG',
            'PARTNER_GROUPS',
            'TR_CASE',
            'PARAMETR_VALUES',
        ],
        'offers' => [
            'COMPLETED_CALCS',
            'MIN_TIME_PRODUCTION_IN_WORK_HOURS',
            'PARAMETR_VALUES',
            'CALC_STATE_HASH',
        ],
    ];

    /** @return array<string,mixed> */
    public function load(string $filePath): array
    {
        $json = file_get_contents($filePath);
        if ($json === false) {
            throw new \RuntimeException('Не удалось прочитать профиль каталога: ' . $filePath);
        }

        try {
            $profile = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Профиль каталога содержит некорректный JSON.', 0, $error);
        }
        if (!is_array($profile)) {
            throw new \RuntimeException('Профиль каталога должен быть JSON-объектом.');
        }

        $this->assertValid($profile);
        return $profile;
    }

    /** @param array<string,mixed> $profile */
    public function assertValid(array $profile): void
    {
        if (($profile['contract'] ?? null) !== self::CONTRACT) {
            throw new \RuntimeException('Неподдерживаемый контракт профиля каталога.');
        }
        if (($profile['profile']['content_included'] ?? null) !== false) {
            throw new \RuntimeException('Профиль каталога не должен содержать элементы или разделы.');
        }
        if (($profile['profile']['mode'] ?? null) !== 'exact-properties') {
            throw new \RuntimeException('Профиль должен использовать режим exact-properties.');
        }

        $roles = $profile['roles'] ?? null;
        if (!is_array($roles) || count($roles) !== 2) {
            throw new \RuntimeException('Профиль должен содержать роли products и offers.');
        }

        $seenRoles = [];
        foreach ($roles as $roleDocument) {
            if (!is_array($roleDocument)) {
                throw new \RuntimeException('Описание роли профиля должно быть объектом.');
            }
            $role = (string)($roleDocument['role'] ?? '');
            if (!in_array($role, ['products', 'offers'], true) || isset($seenRoles[$role])) {
                throw new \RuntimeException('Роли профиля каталога неоднозначны.');
            }
            $seenRoles[$role] = true;

            $properties = $roleDocument['properties'] ?? null;
            if (!is_array($properties)) {
                throw new \RuntimeException('Роль ' . $role . ' не содержит массива свойств.');
            }
            $seenKeys = [];
            foreach ($properties as $property) {
                if (!is_array($property) || !is_array($property['fields'] ?? null)) {
                    throw new \RuntimeException('Некорректное свойство в роли ' . $role . '.');
                }
                $key = $this->propertyKey((array)$property['fields']);
                if ($key === '' || $key !== (string)($property['key'] ?? '') || isset($seenKeys[$key])) {
                    throw new \RuntimeException('Неоднозначный стабильный ключ свойства ' . $key . '.');
                }
                $seenKeys[$key] = true;
                if (isset($property['elements']) || isset($property['sections'])) {
                    throw new \RuntimeException('Свойство профиля не должно содержать контент.');
                }
            }
        }
        if (!isset($seenRoles['products'], $seenRoles['offers'])) {
            throw new \RuntimeException('Профиль должен содержать products и offers.');
        }
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    public function analyze(array $profile, array $current): array
    {
        $this->assertValid($profile);
        $this->assertValid($current);

        $operations = [];
        $summary = [
            'create' => 0,
            'update' => 0,
            'delete' => 0,
            'unchanged' => 0,
            'protected' => 0,
        ];

        $profileRoles = $this->indexRoles($profile);
        $currentRoles = $this->indexRoles($current);
        foreach (['products', 'offers'] as $role) {
            $desired = $this->indexProperties($profileRoles[$role]);
            $actual = $this->indexProperties($currentRoles[$role]);

            foreach ($desired as $key => $property) {
                if (!isset($actual[$key])) {
                    $operations[] = [
                        'action' => 'create',
                        'role' => $role,
                        'key' => $key,
                        'code' => (string)($property['fields']['CODE'] ?? ''),
                        'name' => (string)($property['fields']['NAME'] ?? ''),
                        'desired' => $property,
                    ];
                    $summary['create']++;
                    continue;
                }

                $changed = $this->changedParts($property, $actual[$key]);
                if ($changed === []) {
                    $summary['unchanged']++;
                    continue;
                }
                $operations[] = [
                    'action' => 'update',
                    'role' => $role,
                    'key' => $key,
                    'code' => (string)($property['fields']['CODE'] ?? ''),
                    'name' => (string)($property['fields']['NAME'] ?? ''),
                    'property_id' => (int)($actual[$key]['property_id'] ?? 0),
                    'changed_parts' => $changed,
                    'desired' => $property,
                ];
                $summary['update']++;
            }

            foreach ($actual as $key => $property) {
                if (isset($desired[$key])) {
                    continue;
                }
                $code = (string)($property['fields']['CODE'] ?? '');
                if ($this->isProtectedCode($role, $code)) {
                    $summary['protected']++;
                    continue;
                }
                $operations[] = [
                    'action' => 'delete',
                    'role' => $role,
                    'key' => $key,
                    'code' => $code,
                    'name' => (string)($property['fields']['NAME'] ?? ''),
                    'property_id' => (int)($property['property_id'] ?? 0),
                ];
                $summary['delete']++;
            }
        }

        $plan = [
            'contract' => 'prospektweb.bitrix.catalog-profile-plan/v1',
            'profile_id' => (string)($profile['profile']['id'] ?? ''),
            'profile_hash' => $this->hash($profile),
            'current_hash' => $this->hash($current),
            'summary' => $summary,
            'operations' => $operations,
        ];
        $plan['plan_hash'] = $this->hash($plan);
        return $plan;
    }

    /** @param array<string,mixed> $document */
    public function hash(array $document): string
    {
        if (isset($document['profile']['profile_sha256'])) {
            unset($document['profile']['profile_sha256']);
        }
        unset($document['plan_hash']);
        $json = json_encode(
            $this->canonicalize($document),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
        return hash('sha256', $json);
    }

    /** @param array<string,mixed> $fields */
    public function propertyKey(array $fields): string
    {
        $code = trim((string)($fields['CODE'] ?? ''));
        if ($code !== '') {
            return 'code:' . $code;
        }
        $xmlId = trim((string)($fields['XML_ID'] ?? ''));
        return $xmlId !== '' ? 'xml_id:' . $xmlId : '';
    }

    public function isProtectedCode(string $role, string $code): bool
    {
        if ($code === '') {
            return false;
        }
        if (in_array($code, self::PROTECTED_CODES[$role] ?? [], true)) {
            return true;
        }
        return str_starts_with($code, 'FRONTCALC_')
            || str_starts_with($code, 'PROSPEKT_')
            || str_starts_with($code, 'CALC_');
    }

    /** @param array<string,mixed> $document @return array<string,array<string,mixed>> */
    private function indexRoles(array $document): array
    {
        $indexed = [];
        foreach ((array)$document['roles'] as $role) {
            $indexed[(string)$role['role']] = $role;
        }
        return $indexed;
    }

    /** @param array<string,mixed> $role @return array<string,array<string,mixed>> */
    private function indexProperties(array $role): array
    {
        $indexed = [];
        foreach ((array)$role['properties'] as $property) {
            $indexed[(string)$property['key']] = $property;
        }
        return $indexed;
    }

    /** @param array<string,mixed> $desired @param array<string,mixed> $actual @return array<int,string> */
    private function changedParts(array $desired, array $actual): array
    {
        $parts = [];
        $desiredFields = [];
        $actualFields = [];
        foreach (self::COMPARED_FIELDS as $field) {
            $desiredFields[$field] = $desired['fields'][$field] ?? null;
            $actualFields[$field] = $actual['fields'][$field] ?? null;
        }
        if ($this->canonicalize($desiredFields) !== $this->canonicalize($actualFields)) {
            $parts[] = 'fields';
        }
        if ($this->canonicalize($desired['link_target'] ?? null) !== $this->canonicalize($actual['link_target'] ?? null)) {
            $parts[] = 'link_target';
        }
        if ($this->canonicalize($desired['enums'] ?? []) !== $this->canonicalize($actual['enums'] ?? [])) {
            $parts[] = 'enums';
        }
        if ($this->canonicalize($desired['features'] ?? []) !== $this->canonicalize($actual['features'] ?? [])) {
            $parts[] = 'features';
        }
        return $parts;
    }

    /** @return mixed */
    private function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
