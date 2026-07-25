<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Modules;

final class LegacyV1MigrationAssistant
{
    private const MODULE_SCHEMA = 'prospektweb.calc.module/v1';

    public static function analyzePreset(array $preset, array $elementsStore): array
    {
        $details = self::indexById((array)($elementsStore['CALC_DETAILS'] ?? []));
        $stages = self::indexById((array)($elementsStore['CALC_STAGES'] ?? []));
        $roots = self::numericReferences($preset['properties']['CALC_DETAILS'] ?? []);
        $visited = [];
        $inventory = [];
        $blockers = [];
        $settingsUsage = [];

        $walk = static function (int $detailId, ?int $parentId = null) use (
            &$walk,
            &$visited,
            &$inventory,
            &$blockers,
            &$settingsUsage,
            $details,
            $stages
        ): void {
            if (isset($visited[$detailId])) {
                $blockers[] = [
                    'code' => 'recursive-detail-reference',
                    'message' => "Legacy detail reference {$detailId} is recursive or repeated",
                    'legacyId' => $detailId,
                ];
                return;
            }
            $detail = $details[$detailId] ?? null;
            if ($detail === null) {
                $blockers[] = [
                    'code' => 'missing-detail',
                    'message' => "Legacy detail {$detailId} was not found",
                    'legacyId' => $detailId,
                ];
                return;
            }
            $visited[$detailId] = true;
            $type = strtoupper((string)($detail['properties']['TYPE']['VALUE_XML_ID'] ?? 'DETAIL'));
            $stageRows = [];
            foreach (self::numericReferences($detail['properties']['CALC_STAGES']['VALUE'] ?? []) as $stageId) {
                $stage = $stages[$stageId] ?? null;
                if ($stage === null) {
                    $blockers[] = [
                        'code' => 'missing-stage',
                        'message' => "Legacy stage {$stageId} was not found",
                        'legacyId' => $stageId,
                    ];
                    continue;
                }
                $settingsIds = self::numericReferences($stage['properties']['CALC_SETTINGS']['VALUE'] ?? []);
                foreach ($settingsIds as $settingsId) {
                    $settingsUsage[$settingsId][] = $stageId;
                }
                $stageRows[] = [
                    'legacyId' => $stageId,
                    'name' => (string)($stage['name'] ?? ''),
                    'settingsLegacyIds' => $settingsIds,
                ];
            }
            $childIds = self::numericReferences($detail['properties']['DETAILS']['VALUE'] ?? []);
            $inventory[] = [
                'legacyId' => $detailId,
                'parentLegacyId' => $parentId,
                'name' => (string)($detail['name'] ?? ''),
                'kind' => $type === 'BINDING' ? 'binding-fragment' : 'detail',
                'stages' => $stageRows,
                'childLegacyIds' => $childIds,
            ];
            foreach ($childIds as $childId) {
                $walk($childId, $detailId);
            }
        };

        foreach ($roots as $rootId) {
            $walk($rootId);
        }
        foreach ($settingsUsage as $settingsId => $stageIds) {
            $settingsUsage[$settingsId] = array_values(array_unique($stageIds));
        }
        ksort($settingsUsage, SORT_NUMERIC);

        return [
            'schema' => 'prospektweb.calc.legacy-v1-analysis/v1',
            'preset' => [
                'legacyId' => (int)($preset['id'] ?? $preset['ID'] ?? 0),
                'name' => (string)($preset['name'] ?? $preset['NAME'] ?? ''),
            ],
            'rootLegacyIds' => $roots,
            'inventory' => $inventory,
            'sharedSettings' => array_values(array_map(
                static fn(int $settingsId, array $stageIds): array => [
                    'settingsLegacyId' => $settingsId,
                    'stageLegacyIds' => $stageIds,
                    'shared' => count($stageIds) > 1,
                ],
                array_keys($settingsUsage),
                array_values($settingsUsage)
            )),
            'blockers' => $blockers,
            'requiresExplicitReview' => [
                'ports',
                'entityRoles',
                'content',
                'tests',
                'version',
            ],
            'automaticWrites' => false,
        ];
    }

    public static function buildDraft(array $legacySelection, array $review): array
    {
        foreach (['familyId', 'version', 'kind', 'name', 'content', 'ports', 'entityRoles', 'tests'] as $field) {
            if (!array_key_exists($field, $review)) {
                throw new \InvalidArgumentException("Explicit migration review field is required: {$field}");
            }
        }
        foreach (['content', 'ports', 'entityRoles', 'tests'] as $field) {
            if (!is_array($review[$field])) {
                throw new \InvalidArgumentException("Migration review {$field} must be an array/object");
            }
        }
        if ($review['ports'] === [] || $review['tests'] === []) {
            throw new \InvalidArgumentException('Migration requires explicit ports and differential tests');
        }
        $portablePayload = [
            'content' => $review['content'],
            'ports' => $review['ports'],
            'entityRoles' => $review['entityRoles'],
            'tests' => $review['tests'],
        ];
        self::assertPortable($portablePayload);

        $module = [
            'schema' => self::MODULE_SCHEMA,
            'familyId' => trim((string)$review['familyId']),
            'version' => trim((string)$review['version']),
            'kind' => trim((string)$review['kind']),
            'status' => 'draft',
            'name' => trim((string)$review['name']),
            'description' => trim((string)($review['description'] ?? '')),
            'content' => $review['content'],
            'ports' => array_values($review['ports']),
            'entityRoles' => array_values($review['entityRoles']),
            'dependencies' => array_values((array)($review['dependencies'] ?? [])),
            'tests' => array_values($review['tests']),
            'contentHash' => '',
            'provenance' => [
                'createdAt' => (string)($review['createdAt'] ?? gmdate('c')),
                'createdBy' => (string)($review['createdBy'] ?? 'legacy-v1-migration-assistant'),
                'source' => 'legacy-v1-reviewed-extraction',
            ],
        ];
        $module['contentHash'] = CanonicalJson::moduleContentHash($module);
        ModuleValidator::assertValid($module);

        return [
            'schema' => 'prospektweb.calc.legacy-v1-draft-preview/v1',
            'module' => $module,
            'legacyProvenance' => [
                'presetLegacyId' => (int)($legacySelection['presetLegacyId'] ?? 0),
                'detailLegacyIds' => self::numericReferences($legacySelection['detailLegacyIds'] ?? []),
                'stageLegacyIds' => self::numericReferences($legacySelection['stageLegacyIds'] ?? []),
                'settingsLegacyIds' => self::numericReferences($legacySelection['settingsLegacyIds'] ?? []),
            ],
            'publishAllowed' => false,
            'nextStep' => 'Run differential comparison and create a library draft; publication remains a separate reviewed action.',
        ];
    }

    public static function compareResults(array $expected, array $actual, float $absoluteTolerance = 0.0): array
    {
        if ($absoluteTolerance < 0 || !is_finite($absoluteTolerance)) {
            throw new \InvalidArgumentException('absoluteTolerance must be a finite non-negative number');
        }
        $differences = [];
        self::compareValue($expected, $actual, '$', $absoluteTolerance, $differences);
        return [
            'schema' => 'prospektweb.calc.module-differential-result/v1',
            'passed' => $differences === [],
            'absoluteTolerance' => $absoluteTolerance,
            'differences' => $differences,
            'blocksPublication' => $differences !== [],
        ];
    }

    private static function compareValue(
        mixed $expected,
        mixed $actual,
        string $path,
        float $tolerance,
        array &$differences
    ): void {
        if (is_array($expected)) {
            if (!is_array($actual)) {
                $differences[] = ['path' => $path, 'expected' => $expected, 'actual' => $actual];
                return;
            }
            foreach ($expected as $key => $value) {
                if (!array_key_exists($key, $actual)) {
                    $differences[] = ['path' => "{$path}.{$key}", 'expected' => $value, 'actual' => null];
                    continue;
                }
                self::compareValue($value, $actual[$key], "{$path}.{$key}", $tolerance, $differences);
            }
            foreach ($actual as $key => $value) {
                if (!array_key_exists($key, $expected)) {
                    $differences[] = ['path' => "{$path}.{$key}", 'expected' => null, 'actual' => $value];
                }
            }
            return;
        }
        if (is_numeric($expected) && is_numeric($actual)) {
            if (abs((float)$expected - (float)$actual) > $tolerance) {
                $differences[] = ['path' => $path, 'expected' => $expected, 'actual' => $actual];
            }
            return;
        }
        if ($expected !== $actual) {
            $differences[] = ['path' => $path, 'expected' => $expected, 'actual' => $actual];
        }
    }

    private static function assertPortable(mixed $value, string $path = '$'): void
    {
        if (is_string($value) && preg_match('/\bstage_[1-9][0-9]*\b/u', $value, $match)) {
            throw new \InvalidArgumentException("Legacy stage alias {$match[0]} is not portable at {$path}");
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            $normalized = strtolower((string)$key);
            if (in_array($normalized, ['sourcepath', 'localelementids', 'legacyelementids', 'legacyid'], true)) {
                throw new \InvalidArgumentException("Live preset identifier {$key} is not allowed in reusable content at {$path}");
            }
            self::assertPortable($child, "{$path}.{$key}");
        }
    }

    private static function indexById(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row['id'] ?? $row['ID'] ?? 0);
            if ($id > 0) {
                $result[$id] = $row;
            }
        }
        return $result;
    }

    private static function numericReferences(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($values as $item) {
            if (is_array($item) && array_key_exists('VALUE', $item)) {
                foreach (self::numericReferences($item['VALUE']) as $nested) {
                    $result[] = $nested;
                }
                continue;
            }
            if (is_scalar($item) && ctype_digit(trim((string)$item)) && (int)$item > 0) {
                $result[] = (int)$item;
            }
        }
        return array_values(array_unique($result));
    }
}
