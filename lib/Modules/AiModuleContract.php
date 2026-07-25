<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Modules;

final class AiModuleContract
{
    public const CATALOG_SCHEMA = 'prospektweb.calc.ai-module-catalog/v1';
    public const PROPOSAL_SCHEMA = 'prospektweb.calc.ai-module-attachment-proposal/v1';
    private const SYMBOL = '/^[A-Za-z_][A-Za-z0-9_.-]*$/D';
    private const VERSION = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D';
    private const HASH = '/^[a-f0-9]{64}$/D';
    private const FORBIDDEN_KEYS = [
        'sourcePath', 'settingsId', 'stageId', 'presetId', 'detailId',
        'calculatorId', 'elementId', 'iblockId', 'productId', 'id',
    ];

    public static function sanitizeCatalog(array $modules, ?string $kind = null): array
    {
        if (count($modules) > 50) {
            throw new \InvalidArgumentException('AI module catalog exceeds 50 compatible versions');
        }
        $result = [];
        foreach ($modules as $index => $module) {
            if (!is_array($module)) {
                throw new \InvalidArgumentException("compatibleModules[{$index}] must be an object");
            }
            self::assertKeys($module, "compatibleModules[{$index}]", [
                'familyId', 'version', 'kind', 'name', 'description', 'contentHash',
                'ports', 'entityRoles', 'constraints', 'tests',
            ]);
            $familyId = trim((string)($module['familyId'] ?? ''));
            $version = trim((string)($module['version'] ?? ''));
            $moduleKind = trim((string)($module['kind'] ?? ''));
            $hash = trim((string)($module['contentHash'] ?? ''));
            if (
                !preg_match(self::SYMBOL, $familyId)
                || !preg_match(self::VERSION, $version)
                || $version === 'latest'
                || !preg_match(self::HASH, $hash)
                || !in_array($moduleKind, ['stage', 'detail', 'binding-fragment'], true)
            ) {
                throw new \InvalidArgumentException("compatibleModules[{$index}] is not an exact module version");
            }
            if ($kind !== null && $moduleKind !== $kind) {
                continue;
            }
            $ports = [];
            foreach (array_values((array)($module['ports'] ?? [])) as $portIndex => $port) {
                if (!is_array($port)) {
                    throw new \InvalidArgumentException("compatibleModules[{$index}].ports[{$portIndex}] must be an object");
                }
                self::assertKeys($port, "compatibleModules[{$index}].ports[{$portIndex}]", [
                    'code', 'direction', 'valueType', 'required', 'unit',
                ]);
                $code = trim((string)($port['code'] ?? ''));
                $direction = trim((string)($port['direction'] ?? ''));
                $valueType = trim((string)($port['valueType'] ?? ''));
                if (
                    !preg_match(self::SYMBOL, $code)
                    || !in_array($direction, ['input', 'output', 'global-input', 'global-output'], true)
                    || !in_array($valueType, ['number', 'string', 'boolean'], true)
                    || !is_bool($port['required'] ?? null)
                ) {
                    throw new \InvalidArgumentException("compatibleModules[{$index}].ports[{$portIndex}] is invalid");
                }
                $ports[] = array_filter([
                    'code' => $code,
                    'direction' => $direction,
                    'valueType' => $valueType,
                    'required' => $port['required'],
                    'unit' => isset($port['unit']) ? mb_substr(trim((string)$port['unit']), 0, 40) : null,
                ], static fn(mixed $value): bool => $value !== null);
            }
            $roles = [];
            foreach (array_values((array)($module['entityRoles'] ?? [])) as $roleIndex => $role) {
                if (!is_array($role)) {
                    throw new \InvalidArgumentException("compatibleModules[{$index}].entityRoles[{$roleIndex}] must be an object");
                }
                self::assertKeys($role, "compatibleModules[{$index}].entityRoles[{$roleIndex}]", [
                    'code', 'entityType', 'cardinality', 'description',
                ]);
                $roleCode = trim((string)($role['code'] ?? ''));
                $entityType = trim((string)($role['entityType'] ?? ''));
                $cardinality = trim((string)($role['cardinality'] ?? ''));
                if (
                    !preg_match(self::SYMBOL, $roleCode)
                    || !in_array($entityType, ['material', 'materialVariant', 'operation', 'operationVariant', 'equipment', 'customField'], true)
                    || !in_array($cardinality, ['one', 'optional', 'many'], true)
                ) {
                    throw new \InvalidArgumentException("compatibleModules[{$index}].entityRoles[{$roleIndex}] is invalid");
                }
                $roles[] = [
                    'code' => $roleCode,
                    'entityType' => $entityType,
                    'cardinality' => $cardinality,
                    'description' => mb_substr(trim((string)($role['description'] ?? '')), 0, 500),
                ];
            }
            $tests = [];
            foreach (array_slice(array_values((array)($module['tests'] ?? [])), 0, 20) as $test) {
                if (!is_array($test)) {
                    continue;
                }
                self::assertKeys($test, 'module test', ['name', 'inputs', 'expectedOutputs']);
                $tests[] = [
                    'name' => mb_substr(trim((string)($test['name'] ?? '')), 0, 300),
                    'inputs' => (array)($test['inputs'] ?? []),
                    'expectedOutputs' => (array)($test['expectedOutputs'] ?? []),
                ];
            }
            $item = [
                'familyId' => $familyId,
                'version' => $version,
                'kind' => $moduleKind,
                'name' => mb_substr(trim((string)($module['name'] ?? '')), 0, 300),
                'description' => mb_substr(trim((string)($module['description'] ?? '')), 0, 1200),
                'contentHash' => $hash,
                'ports' => $ports,
                'entityRoles' => $roles,
                'constraints' => array_slice(array_values((array)($module['constraints'] ?? [])), 0, 50),
                'tests' => $tests,
            ];
            self::assertNoForbiddenKeys($item);
            $result[] = $item;
        }
        return $result;
    }

    public static function validateProposal(
        array $proposal,
        array $catalog,
        array $sourceRefs,
        array $globalRefs,
        array $entityRefs
    ): array {
        self::assertNoForbiddenKeys($proposal);
        self::assertKeys($proposal, 'moduleAttachment', [
            'schema', 'familyId', 'version', 'contentHash', 'summary', 'mappings',
            'previewRequired', 'applyAllowed', 'publishAllowed',
        ]);
        if (($proposal['schema'] ?? null) !== self::PROPOSAL_SCHEMA) {
            throw new \InvalidArgumentException('Unsupported AI module attachment proposal schema');
        }
        $familyId = trim((string)($proposal['familyId'] ?? ''));
        $version = trim((string)($proposal['version'] ?? ''));
        $hash = trim((string)($proposal['contentHash'] ?? ''));
        if ($version === 'latest' || !preg_match(self::VERSION, $version)) {
            throw new \InvalidArgumentException('AI must select an exact module version');
        }
        $module = null;
        foreach ($catalog as $candidate) {
            if (
                ($candidate['familyId'] ?? null) === $familyId
                && ($candidate['version'] ?? null) === $version
                && ($candidate['contentHash'] ?? null) === $hash
            ) {
                $module = $candidate;
                break;
            }
        }
        if ($module === null) {
            throw new \InvalidArgumentException('AI selected a module outside the compatible published catalog');
        }
        if (
            ($proposal['previewRequired'] ?? null) !== true
            || ($proposal['applyAllowed'] ?? null) !== false
            || ($proposal['publishAllowed'] ?? null) !== false
        ) {
            throw new \InvalidArgumentException('AI cannot publish or apply a module without preview');
        }
        $mappings = is_array($proposal['mappings'] ?? null) ? $proposal['mappings'] : [];
        self::assertKeys($mappings, 'moduleAttachment.mappings', ['ports', 'entityRoles']);
        $knownPorts = [];
        foreach ($module['ports'] as $port) {
            $knownPorts[$port['code']] = $port;
        }
        $ports = [];
        foreach (array_values((array)($mappings['ports'] ?? [])) as $index => $mapping) {
            if (!is_array($mapping)) {
                throw new \InvalidArgumentException("moduleAttachment.mappings.ports[{$index}] must be an object");
            }
            self::assertKeys($mapping, "moduleAttachment.mappings.ports[{$index}]", ['portCode', 'target']);
            $portCode = trim((string)($mapping['portCode'] ?? ''));
            $port = $knownPorts[$portCode] ?? null;
            if (!$port || $port['direction'] === 'output') {
                throw new \InvalidArgumentException("AI mapped unknown or output port {$portCode}");
            }
            $target = is_array($mapping['target'] ?? null) ? $mapping['target'] : [];
            $kind = trim((string)($target['kind'] ?? ''));
            if ($kind === 'source-ref') {
                self::assertKeys($target, 'module port target', ['kind', 'ref']);
                if (!in_array((string)($target['ref'] ?? ''), $sourceRefs, true)) {
                    throw new \InvalidArgumentException("AI used unavailable sourceRef for {$portCode}");
                }
            } elseif ($kind === 'global-ref') {
                self::assertKeys($target, 'module port target', ['kind', 'ref']);
                if (!in_array((string)($target['ref'] ?? ''), $globalRefs, true)) {
                    throw new \InvalidArgumentException("AI used unavailable globalRef for {$portCode}");
                }
            } elseif ($kind === 'literal') {
                self::assertKeys($target, 'module port target', ['kind', 'value']);
                if (!is_scalar($target['value'] ?? null)) {
                    throw new \InvalidArgumentException("AI used invalid literal for {$portCode}");
                }
            } else {
                throw new \InvalidArgumentException("AI used invalid target kind for {$portCode}");
            }
            if (isset($ports[$portCode])) {
                throw new \InvalidArgumentException("AI repeated port mapping {$portCode}");
            }
            $ports[$portCode] = ['portCode' => $portCode, 'target' => $target];
        }
        foreach ($module['ports'] as $port) {
            if (($port['required'] ?? false) && $port['direction'] !== 'output' && !isset($ports[$port['code']])) {
                throw new \InvalidArgumentException("Required module port is not mapped: {$port['code']}");
            }
            if (($port['required'] ?? false) && $port['direction'] === 'global-input') {
                $kind = $ports[$port['code']]['target']['kind'] ?? null;
                if (!in_array($kind, ['global-ref', 'literal'], true)) {
                    throw new \InvalidArgumentException("Required global port needs explicit mapping: {$port['code']}");
                }
            }
        }
        $knownRoles = [];
        foreach ($module['entityRoles'] as $role) {
            $knownRoles[$role['code']] = $role;
        }
        $roles = [];
        foreach (array_values((array)($mappings['entityRoles'] ?? [])) as $index => $mapping) {
            if (!is_array($mapping)) {
                throw new \InvalidArgumentException("moduleAttachment.mappings.entityRoles[{$index}] must be an object");
            }
            self::assertKeys($mapping, "moduleAttachment.mappings.entityRoles[{$index}]", ['roleCode', 'selectorRef']);
            $roleCode = trim((string)($mapping['roleCode'] ?? ''));
            $selectorRef = trim((string)($mapping['selectorRef'] ?? ''));
            if (!isset($knownRoles[$roleCode]) || !in_array($selectorRef, $entityRefs, true)) {
                throw new \InvalidArgumentException("AI used unknown role or selectorRef for {$roleCode}");
            }
            if (isset($roles[$roleCode])) {
                throw new \InvalidArgumentException("AI repeated entity role mapping {$roleCode}");
            }
            $roles[$roleCode] = ['roleCode' => $roleCode, 'selectorRef' => $selectorRef];
        }
        foreach ($module['entityRoles'] as $role) {
            if ($role['cardinality'] === 'one' && !isset($roles[$role['code']])) {
                throw new \InvalidArgumentException("Required entity role is not mapped: {$role['code']}");
            }
        }
        return [
            'schema' => self::PROPOSAL_SCHEMA,
            'familyId' => $familyId,
            'version' => $version,
            'contentHash' => $hash,
            'summary' => mb_substr(trim((string)($proposal['summary'] ?? '')), 0, 1200),
            'mappings' => ['ports' => array_values($ports), 'entityRoles' => array_values($roles)],
            'previewRequired' => true,
            'applyAllowed' => false,
            'publishAllowed' => false,
        ];
    }

    private static function assertNoForbiddenKeys(mixed $value): void
    {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                throw new \InvalidArgumentException('AI module contract rejects internal paths and IDs');
            }
            self::assertNoForbiddenKeys($child);
        }
    }

    private static function assertKeys(array $value, string $label, array $allowed): void
    {
        foreach (array_keys($value) as $key) {
            if (is_string($key) && in_array($key, $allowed, true)) {
                continue;
            }
            throw new \InvalidArgumentException("{$label} contains unknown field {$key}");
        }
    }
}
