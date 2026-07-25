<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Modules;

final class DependencyResolver
{
    public static function resolve(array $root, array $catalog): array
    {
        ModuleValidator::assertValid($root);
        $byFamily = [];
        foreach ($catalog as $module) {
            if (!is_array($module)) {
                throw new \InvalidArgumentException('Dependency catalog entries must be objects');
            }
            ModuleValidator::assertValid($module);
            if (($module['status'] ?? null) !== 'published') {
                continue;
            }
            $byFamily[$module['familyId']][] = $module;
        }
        foreach ($byFamily as &$versions) {
            usort($versions, static fn(array $a, array $b): int => version_compare($b['version'], $a['version']));
        }
        unset($versions);

        $locks = [];
        $graph = [];
        $visiting = [];
        $visited = [];
        $executionOrder = [];

        $visit = function (array $module, array $path) use (&$visit, &$byFamily, &$locks, &$graph, &$visiting, &$visited, &$executionOrder): void {
            $key = $module['familyId'] . '@' . $module['version'];
            if (isset($visiting[$key])) {
                throw new \RuntimeException('Dependency cycle: ' . implode(' -> ', [...$path, $key]));
            }
            if (isset($visited[$key])) {
                return;
            }
            $visiting[$key] = true;
            $dependencyKeys = [];

            foreach ($module['dependencies'] as $dependency) {
                $selected = self::selectVersion($byFamily[$dependency['familyId']] ?? [], $dependency['versionRange']);
                if ($selected === null) {
                    if (($dependency['optional'] ?? false) === true) {
                        continue;
                    }
                    throw new \RuntimeException(
                        "No published version satisfies {$dependency['familyId']} {$dependency['versionRange']}"
                    );
                }
                $dependencyKey = $selected['familyId'] . '@' . $selected['version'];
                $dependencyKeys[] = $dependencyKey;
                $lockKey = $key . ':' . $dependency['ref'];
                $locks[$lockKey] = [
                    'owner' => $key,
                    'ref' => $dependency['ref'],
                    'familyId' => $selected['familyId'],
                    'version' => $selected['version'],
                    'contentHash' => $selected['contentHash'],
                ];
                $visit($selected, [...$path, $key]);
            }

            unset($visiting[$key]);
            $visited[$key] = true;
            $graph[$key] = [
                'familyId' => $module['familyId'],
                'version' => $module['version'],
                'contentHash' => $module['contentHash'],
                'dependencies' => $dependencyKeys,
            ];
            $executionOrder[] = $key;
        };

        $visit($root, []);
        return [
            'root' => $root['familyId'] . '@' . $root['version'],
            'dependencyLock' => array_values($locks),
            'graph' => array_values($graph),
            'executionOrder' => $executionOrder,
        ];
    }

    public static function satisfies(string $version, string $range): bool
    {
        if (!preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $version)) {
            return false;
        }
        if ($range === '*') {
            return true;
        }
        if (preg_match('/^(\^|~)?((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))$/D', $range, $match)) {
            $operator = $match[1];
            $base = $match[2];
            if ($operator === '') {
                return version_compare($version, $base, '==');
            }
            [$major, $minor, $patch] = array_map('intval', explode('.', $base));
            if ($operator === '~') {
                $upper = $major . '.' . ($minor + 1) . '.0';
            } elseif ($major > 0) {
                $upper = ($major + 1) . '.0.0';
            } elseif ($minor > 0) {
                $upper = '0.' . ($minor + 1) . '.0';
            } else {
                $upper = '0.0.' . ($patch + 1);
            }
            return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
        }
        return false;
    }

    private static function selectVersion(array $versions, string $range): ?array
    {
        foreach ($versions as $module) {
            if (self::satisfies($module['version'], $range)) {
                return $module;
            }
        }
        return null;
    }
}
