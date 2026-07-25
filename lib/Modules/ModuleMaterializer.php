<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Modules;

final class ModuleMaterializer
{
    public const RESOLVER_VERSION = '1.0.0';

    public static function validateInstance(array $module, array $instance): array
    {
        $errors = [];
        if (($instance['schema'] ?? null) !== 'prospektweb.calc.module-instance/v1') {
            $errors[] = 'instance schema is invalid';
        }
        if (($instance['familyId'] ?? null) !== ($module['familyId'] ?? null)) {
            $errors[] = 'instance familyId does not match module';
        }
        if (($instance['version'] ?? null) !== ($module['version'] ?? null)) {
            $errors[] = 'instance version does not match module';
        }
        if (($instance['contentHash'] ?? null) !== ($module['contentHash'] ?? null)) {
            $errors[] = 'instance contentHash does not match module';
        }
        if (!is_int($instance['revision'] ?? null) || $instance['revision'] < 1) {
            $errors[] = 'instance revision is invalid';
        }

        $portBindings = [];
        foreach (($instance['bindings'] ?? []) as $binding) {
            $code = is_array($binding) ? ($binding['portCode'] ?? null) : null;
            if (!is_string($code) || $code === '') {
                $errors[] = 'instance binding portCode is invalid';
                continue;
            }
            if (isset($portBindings[$code])) {
                $errors[] = "Duplicate binding: {$code}";
            }
            $portBindings[$code] = $binding;
        }
        $knownPorts = [];
        foreach (($module['ports'] ?? []) as $port) {
            $code = is_array($port) ? ($port['code'] ?? null) : null;
            if (!is_string($code)) {
                continue;
            }
            $knownPorts[$code] = true;
            if (
                ($port['required'] ?? false) === true
                && ($port['direction'] ?? null) !== 'output'
                && !isset($portBindings[$code])
            ) {
                $errors[] = "Required port is not bound: {$code}";
            }
        }
        foreach (array_keys($portBindings) as $code) {
            if (!isset($knownPorts[$code])) {
                $errors[] = "Unknown port binding: {$code}";
            }
        }

        $roleBindings = [];
        foreach (($instance['entityBindings'] ?? []) as $binding) {
            $code = is_array($binding) ? ($binding['roleCode'] ?? null) : null;
            if (!is_string($code) || $code === '') {
                $errors[] = 'instance entity binding roleCode is invalid';
                continue;
            }
            if (isset($roleBindings[$code])) {
                $errors[] = "Duplicate entity binding: {$code}";
            }
            $roleBindings[$code] = $binding;
        }
        $knownRoles = [];
        foreach (($module['entityRoles'] ?? []) as $role) {
            $code = is_array($role) ? ($role['code'] ?? null) : null;
            if (!is_string($code)) {
                continue;
            }
            $knownRoles[$code] = true;
            $binding = $roleBindings[$code] ?? null;
            $ids = is_array($binding) && is_array($binding['localElementIds'] ?? null)
                ? $binding['localElementIds']
                : [];
            if (
                is_array($binding)
                && ($binding['entityType'] ?? null) !== ($role['entityType'] ?? null)
            ) {
                $errors[] = "Entity type does not match role {$code}";
            }
            if (($role['cardinality'] ?? null) === 'one' && count($ids) !== 1) {
                $errors[] = "Entity role requires exactly one binding: {$code}";
            }
            if (($role['cardinality'] ?? null) === 'optional' && count($ids) > 1) {
                $errors[] = "Entity role allows at most one binding: {$code}";
            }
        }
        foreach (array_keys($roleBindings) as $code) {
            if (!isset($knownRoles[$code])) {
                $errors[] = "Unknown entity role binding: {$code}";
            }
        }
        return array_values(array_unique($errors));
    }

    public static function materialize(array $module, array $instance, array $options): array
    {
        $errors = array_merge(
            ModuleValidator::validate($module),
            self::validateInstance($module, $instance)
        );
        $status = $module['status'] ?? null;
        if (!in_array($status, ['published', 'deprecated'], true)) {
            $errors[] = "Module status cannot be materialized: {$status}";
        }
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode('; ', array_values(array_unique($errors))));
        }

        $instanceId = (string)$instance['instanceId'];
        $nodes = [];
        foreach ($module['content']['nodes'] as $node) {
            $node['instanceNodeId'] = $instanceId . ':' . $node['nodeId'];
            $nodes[] = $node;
        }
        $payload = [
            'schema' => 'prospektweb.calc.resolved-snapshot/v1',
            'snapshotId' => (string)$options['snapshotId'],
            'presetId' => $instance['presetId'],
            'presetRevision' => (int)$options['presetRevision'],
            'instanceId' => $instanceId,
            'familyId' => $module['familyId'],
            'version' => $module['version'],
            'contentHash' => $module['contentHash'],
            'dependencyLock' => $instance['dependencyLock'] ?? [],
            'resolvedGraph' => [
                'rootNodeId' => $instanceId . ':' . $module['content']['rootNodeId'],
                'nodes' => $nodes,
                'executionOrder' => $options['executionOrder']
                    ?? [$module['familyId'] . '@' . $module['version']],
            ],
            'materialization' => [
                'bindings' => $instance['bindings'] ?? [],
                'entityBindings' => $instance['entityBindings'] ?? [],
                'activationCondition' => $instance['activationCondition'] ?? null,
                'globalAssignments' => $instance['globalAssignments'] ?? [],
                'customFieldValues' => $instance['customFieldValues'] ?? (object)[],
            ],
            'provenance' => [
                'resolvedBy' => (string)$options['resolvedBy'],
                'resolverVersion' => (string)($options['resolverVersion'] ?? self::RESOLVER_VERSION),
                'instanceRevision' => (int)$instance['revision'],
            ],
            'createdAt' => (string)$options['createdAt'],
        ];
        if (!empty($options['legacySnapshotHash'])) {
            $payload['provenance']['legacySnapshotHash'] = (string)$options['legacySnapshotHash'];
        }
        $payload['snapshotHash'] = CanonicalJson::hash($payload);
        return $payload;
    }

    public static function preview(array $current, array $candidate): array
    {
        $currentNodes = self::indexBy($current['resolvedGraph']['nodes'] ?? [], 'nodeId');
        $candidateNodes = self::indexBy($candidate['resolvedGraph']['nodes'] ?? [], 'nodeId');
        $currentBindings = self::indexBy($current['materialization']['bindings'] ?? [], 'portCode', 'target');
        $candidateBindings = self::indexBy($candidate['materialization']['bindings'] ?? [], 'portCode', 'target');

        $addedNodes = array_values(array_diff(array_keys($candidateNodes), array_keys($currentNodes)));
        $removedNodes = array_values(array_diff(array_keys($currentNodes), array_keys($candidateNodes)));
        $addedPorts = array_values(array_diff(array_keys($candidateBindings), array_keys($currentBindings)));
        $removedPorts = array_values(array_diff(array_keys($currentBindings), array_keys($candidateBindings)));
        $changedPorts = [];
        foreach ($candidateBindings as $code => $target) {
            if (array_key_exists($code, $currentBindings) && CanonicalJson::encode($target) !== CanonicalJson::encode($currentBindings[$code])) {
                $changedPorts[] = $code;
            }
        }
        $formulasChanged = [];
        foreach ($candidateNodes as $code => $node) {
            if (
                isset($currentNodes[$code])
                && CanonicalJson::encode($node['logic'] ?? null)
                    !== CanonicalJson::encode($currentNodes[$code]['logic'] ?? null)
            ) {
                $formulasChanged[] = $code;
            }
        }
        sort($addedNodes);
        sort($removedNodes);
        sort($addedPorts);
        sort($removedPorts);
        sort($changedPorts);
        sort($formulasChanged);
        return [
            'from' => $current['familyId'] . '@' . $current['version'],
            'to' => $candidate['familyId'] . '@' . $candidate['version'],
            'structure' => ['added' => $addedNodes, 'removed' => $removedNodes],
            'ports' => ['added' => $addedPorts, 'removed' => $removedPorts, 'changed' => $changedPorts],
            'formulasChanged' => $formulasChanged,
            'expectedResultsChanged' => $current['contentHash'] !== $candidate['contentHash'],
        ];
    }

    private static function indexBy(array $rows, string $key, ?string $valueKey = null): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row[$key])) {
                continue;
            }
            $result[(string)$row[$key]] = $valueKey === null ? $row : ($row[$valueKey] ?? null);
        }
        return $result;
    }
}
