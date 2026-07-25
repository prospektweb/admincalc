<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Modules;

final class ModuleLifecyclePolicy
{
    private const TRANSITIONS = [
        'draft' => ['published', 'archived'],
        'published' => ['deprecated', 'archived'],
        'deprecated' => ['archived'],
        'archived' => [],
        'withdrawn' => [],
    ];

    public static function assertTransition(string $from, string $to): void
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new \DomainException("Module lifecycle transition is forbidden: {$from} -> {$to}");
        }
    }

    public static function assertContentMutable(string $status): void
    {
        if ($status !== 'draft') {
            throw new \DomainException("Module content is immutable in status {$status}");
        }
    }

    public static function assertPublishable(array $module, array $testResults): void
    {
        if (($module['status'] ?? null) !== 'draft') {
            throw new \DomainException('Only a draft module version can be published');
        }
        ModuleValidator::assertValid($module);
        $tests = $module['tests'] ?? [];
        if (count($tests) !== count($testResults)) {
            throw new \DomainException('Every declared module test must have a result');
        }
        foreach ($testResults as $index => $result) {
            if (!is_array($result) || ($result['passed'] ?? false) !== true) {
                $name = is_array($tests[$index] ?? null) ? ($tests[$index]['name'] ?? $index) : $index;
                throw new \DomainException("Module test failed: {$name}");
            }
        }
    }

    public static function assertRevision(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw new \RuntimeException("Revision conflict: expected {$expected}, actual {$actual}");
        }
    }
}
