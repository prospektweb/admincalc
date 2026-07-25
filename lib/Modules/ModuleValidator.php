<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Modules;

final class ModuleValidator
{
    private const SCHEMA = 'prospektweb.calc.module/v1';
    private const FAMILY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_.-]*$/D';
    private const VERSION_PATTERN = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D';

    public static function validate(array $module, bool $verifyHash = true): array
    {
        $errors = [];
        self::requireExact($module, 'schema', self::SCHEMA, $errors);
        self::requirePattern($module, 'familyId', self::FAMILY_PATTERN, $errors);
        self::requirePattern($module, 'version', self::VERSION_PATTERN, $errors);
        self::requireEnum($module, 'kind', ['stage', 'detail', 'binding-fragment'], $errors);
        self::requireEnum($module, 'status', ['draft', 'published', 'deprecated', 'withdrawn'], $errors);

        foreach (['content', 'ports', 'entityRoles', 'dependencies', 'tests', 'contentHash', 'provenance'] as $field) {
            if (!array_key_exists($field, $module)) {
                $errors[] = "Missing field: {$field}";
            }
        }
        $content = is_array($module['content'] ?? null) ? $module['content'] : [];
        $rootNodeId = $content['rootNodeId'] ?? null;
        $nodes = is_array($content['nodes'] ?? null) ? $content['nodes'] : [];
        if (!is_string($rootNodeId) || !preg_match(self::FAMILY_PATTERN, $rootNodeId)) {
            $errors[] = 'content.rootNodeId is invalid';
        }

        $nodeIds = [];
        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                $errors[] = "content.nodes[{$index}] must be an object";
                continue;
            }
            $nodeId = $node['nodeId'] ?? null;
            if (!is_string($nodeId) || !preg_match(self::FAMILY_PATTERN, $nodeId)) {
                $errors[] = "content.nodes[{$index}].nodeId is invalid";
                continue;
            }
            if (isset($nodeIds[$nodeId])) {
                $errors[] = "Duplicate nodeId: {$nodeId}";
            }
            $nodeIds[$nodeId] = true;
        }
        if (is_string($rootNodeId) && !isset($nodeIds[$rootNodeId])) {
            $errors[] = "Root node does not exist: {$rootNodeId}";
        }
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            foreach (($node['childNodeIds'] ?? []) as $childId) {
                if (!is_string($childId) || !isset($nodeIds[$childId])) {
                    $errors[] = 'Unknown child node: ' . (is_scalar($childId) ? (string)$childId : gettype($childId));
                }
            }
        }

        self::validateUniqueCodes($module['ports'] ?? null, 'ports', $errors);
        self::validateUniqueCodes($module['entityRoles'] ?? null, 'entityRoles', $errors);
        self::validateUniqueCodes($module['dependencies'] ?? null, 'dependencies', $errors, 'ref');
        self::validatePorts($module['ports'] ?? null, $errors);
        self::validateEntityRoles($module['entityRoles'] ?? null, $errors);
        self::validateDependencies($module['dependencies'] ?? null, $errors);

        try {
            self::findLegacyStageAliases(CanonicalJson::moduleContentPayload($module), '$', $errors);
        } catch (\InvalidArgumentException $error) {
            $errors[] = $error->getMessage();
        }
        if ($verifyHash && isset($module['contentHash'])) {
            if (!is_string($module['contentHash']) || !preg_match('/^[a-f0-9]{64}$/D', $module['contentHash'])) {
                $errors[] = 'contentHash must be a lowercase SHA-256';
            } else {
                try {
                    if (!hash_equals(CanonicalJson::moduleContentHash($module), $module['contentHash'])) {
                        $errors[] = 'contentHash does not match canonical module content';
                    }
                } catch (\InvalidArgumentException $error) {
                    $errors[] = $error->getMessage();
                }
            }
        }
        return array_values(array_unique($errors));
    }

    public static function assertValid(array $module, bool $verifyHash = true): void
    {
        $errors = self::validate($module, $verifyHash);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
    }

    private static function requireExact(array $value, string $field, string $expected, array &$errors): void
    {
        if (($value[$field] ?? null) !== $expected) {
            $errors[] = "{$field} must equal {$expected}";
        }
    }

    private static function requirePattern(array $value, string $field, string $pattern, array &$errors): void
    {
        if (!is_string($value[$field] ?? null) || !preg_match($pattern, $value[$field])) {
            $errors[] = "{$field} is invalid";
        }
    }

    private static function requireEnum(array $value, string $field, array $allowed, array &$errors): void
    {
        if (!in_array($value[$field] ?? null, $allowed, true)) {
            $errors[] = "{$field} is invalid";
        }
    }

    private static function validateUniqueCodes(mixed $items, string $field, array &$errors, string $codeField = 'code'): void
    {
        if (!is_array($items)) {
            $errors[] = "{$field} must be an array";
            return;
        }
        $seen = [];
        foreach ($items as $index => $item) {
            $code = is_array($item) ? ($item[$codeField] ?? null) : null;
            if (!is_string($code) || !preg_match(self::FAMILY_PATTERN, $code)) {
                $errors[] = "{$field}[{$index}].{$codeField} is invalid";
                continue;
            }
            if (isset($seen[$code])) {
                $errors[] = "Duplicate {$field} code: {$code}";
            }
            $seen[$code] = true;
        }
    }

    private static function validatePorts(mixed $ports, array &$errors): void
    {
        if (!is_array($ports)) {
            return;
        }
        foreach ($ports as $index => $port) {
            if (!is_array($port)) {
                continue;
            }
            if (!in_array($port['direction'] ?? null, ['input', 'output', 'global-input', 'global-output'], true)) {
                $errors[] = "ports[{$index}].direction is invalid";
            }
            if (!in_array($port['valueType'] ?? null, ['number', 'string', 'boolean'], true)) {
                $errors[] = "ports[{$index}].valueType is invalid";
            }
            if (!is_bool($port['required'] ?? null)) {
                $errors[] = "ports[{$index}].required must be boolean";
            }
        }
    }

    private static function validateEntityRoles(mixed $roles, array &$errors): void
    {
        if (!is_array($roles)) {
            return;
        }
        $types = ['material', 'materialVariant', 'operation', 'operationVariant', 'equipment', 'customField'];
        foreach ($roles as $index => $role) {
            if (!is_array($role)) {
                continue;
            }
            if (!in_array($role['entityType'] ?? null, $types, true)) {
                $errors[] = "entityRoles[{$index}].entityType is invalid";
            }
            if (!in_array($role['cardinality'] ?? null, ['one', 'optional', 'many'], true)) {
                $errors[] = "entityRoles[{$index}].cardinality is invalid";
            }
        }
    }

    private static function validateDependencies(mixed $dependencies, array &$errors): void
    {
        if (!is_array($dependencies)) {
            return;
        }
        $rangePattern = '/^(?:\*|[\^~]?(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))$/D';
        foreach ($dependencies as $index => $dependency) {
            if (!is_array($dependency)) {
                continue;
            }
            if (!is_string($dependency['familyId'] ?? null) || !preg_match(self::FAMILY_PATTERN, $dependency['familyId'])) {
                $errors[] = "dependencies[{$index}].familyId is invalid";
            }
            if (!is_string($dependency['versionRange'] ?? null) || !preg_match($rangePattern, $dependency['versionRange'])) {
                $errors[] = "dependencies[{$index}].versionRange is invalid";
            }
            if (array_key_exists('optional', $dependency) && !is_bool($dependency['optional'])) {
                $errors[] = "dependencies[{$index}].optional must be boolean";
            }
        }
    }

    private static function findLegacyStageAliases(mixed $value, string $path, array &$errors): void
    {
        if (is_string($value) && preg_match('/\bstage_[1-9][0-9]*\b/u', $value, $match)) {
            $errors[] = "Legacy stage ID {$match[0]} is not allowed in reusable content at {$path}";
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            self::findLegacyStageAliases($child, $path . '.' . $key, $errors);
        }
    }
}
