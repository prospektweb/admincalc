<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/PresetMutationCoordinatorService.php';

use Prospektweb\Calc\Services\PresetMutationCoordinatorService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$revision = 7;
$entity = [
    'revision' => 3,
    'value' => 'before',
    'product_ids' => [11],
    'secret' => 'must-never-be-audited',
];
$events = [];
$audits = [];
$held = false;

$coordinator = new PresetMutationCoordinatorService([
    'actor_id' => static fn(): int => 42,
    'audit' => static function (array $audit) use (&$audits): int {
        $audits[] = $audit;
        return count($audits);
    },
    'with_locked_revision' => static function (
        int $presetId,
        callable $criticalSection
    ) use (&$revision, &$entity, &$events, &$held) {
        if ($held) {
            throw new RuntimeException('simulated concurrent preset mutation rejected', 409);
        }
        $held = true;
        $snapshot = $entity;
        $events[] = 'lock:' . $presetId . ':' . $revision;
        try {
            $envelope = $criticalSection($revision);
            $revision = (int)$envelope['next_revision'];
            $events[] = 'commit:' . $revision;
            return $envelope;
        } catch (Throwable $error) {
            $entity = $snapshot;
            $events[] = 'rollback:' . $revision;
            throw $error;
        } finally {
            $held = false;
        }
    },
]);

$result = $coordinator->mutate(
    41,
    [
        'action' => 'storefront_save',
        'entity_type' => 'storefront',
        'entity_id' => 'main',
        'expected_revision' => 3,
        'product_ids' => [4403, 4267],
    ],
    static function () use (&$entity): array {
        $entity = [
            'revision' => 4,
            'value' => 'after',
            'product_ids' => [12],
            'secret' => 'new-secret',
        ];
        return ['public' => 'unchanged-api-result'];
    },
    static function () use (&$entity): array {
        return $entity;
    }
);

$assert($result === ['public' => 'unchanged-api-result'], 'coordinator must preserve the public domain result');
$assert($revision === 8 && $events === ['lock:41:7', 'commit:8'], 'coordinator revision advances once under one lock');
$assert(count($audits) === 1, 'successful mutation is audited exactly once');
$audit = $audits[0];
$assert(
    ($audit['actorId'] ?? null) === 42
        && ($audit['action'] ?? '') === 'storefront_save'
        && ($audit['entityType'] ?? '') === 'storefront'
        && ($audit['entityId'] ?? '') === 'main'
        && ($audit['presetId'] ?? null) === 41
        && ($audit['coordinatorRevisionBefore'] ?? null) === 7
        && ($audit['coordinatorRevisionAfter'] ?? null) === 8
        && ($audit['expectedEntityRevision'] ?? null) === 3
        && ($audit['resultEntityRevision'] ?? null) === 4
        && ($audit['productIds'] ?? []) === [11, 12, 4267, 4403]
        && ($audit['result'] ?? '') === 'success',
    'audit metadata identifies actor/action/entity/revisions/products/result'
);
$assert(
    preg_match('/^[a-f0-9]{64}$/D', (string)($audit['beforeSha256'] ?? '')) === 1
        && preg_match('/^[a-f0-9]{64}$/D', (string)($audit['afterSha256'] ?? '')) === 1
        && $audit['beforeSha256'] !== $audit['afterSha256'],
    'authoritative before/after readbacks are represented only by exact hashes'
);
$assert(
    !str_contains((string)json_encode($audit), 'must-never-be-audited')
        && !str_contains((string)json_encode($audit), 'new-secret'),
    'audit metadata must not contain entity payload or secrets'
);

$blocked = false;
$coordinator->mutate(
    41,
    [
        'action' => 'form_first_publish',
        'entity_type' => 'form_first',
        'entity_id' => '41',
        'expected_revision' => str_repeat('a', 64),
        'product_ids' => [],
    ],
    static function () use ($coordinator, &$blocked, &$entity): array {
        try {
            $coordinator->mutate(
                41,
                [
                    'action' => 'calculator_input_mapping_save',
                    'entity_type' => 'calculator_input_mapping',
                    'entity_id' => '41',
                    'expected_revision' => 0,
                    'product_ids' => [],
                ],
                static fn(): array => [],
                static fn(): array => []
            );
        } catch (RuntimeException $error) {
            $blocked = $error->getCode() === 409;
        }
        $entity['revision'] = 5;
        return ['revision' => 5];
    },
    static function () use (&$entity): array {
        return $entity;
    }
);
$assert($blocked, 'a second mutation of the same preset cannot interleave with the locked mutation');

$failedEntity = ['revision' => 1, 'value' => 'stable'];
$failedRevision = 2;
$failedCoordinator = new PresetMutationCoordinatorService([
    'audit' => static fn(array $audit): bool => false,
    'with_locked_revision' => static function (
        int $presetId,
        callable $criticalSection
    ) use (&$failedRevision, &$failedEntity) {
        $snapshot = $failedEntity;
        try {
            $envelope = $criticalSection($failedRevision);
            $failedRevision = (int)$envelope['next_revision'];
            return $envelope;
        } catch (Throwable $error) {
            $failedEntity = $snapshot;
            throw $error;
        }
    },
]);
$failed = false;
try {
    $failedCoordinator->mutate(
        41,
        [
            'action' => 'storefront_delete',
            'entity_type' => 'storefront',
            'entity_id' => 'main',
            'expected_revision' => 5,
            'product_ids' => [],
        ],
        static function () use (&$failedEntity): array {
            $failedEntity = ['revision' => 2, 'value' => 'changed'];
            return $failedEntity;
        },
        static function () use (&$failedEntity): array {
            return $failedEntity;
        }
    );
} catch (RuntimeException $error) {
    $failed = str_contains($error->getMessage(), 'audit');
}
$assert(
    $failed && $failedEntity === ['revision' => 1, 'value' => 'stable'] && $failedRevision === 2,
    'audit failure aborts and rolls back both entity and coordinator revision'
);

$publishedForm = ['revision' => 7, 'compileHash' => str_repeat('7', 64)];
$publicationCoordinatorRevision = 11;
$publicationAudits = [];
$publicationCoordinator = new PresetMutationCoordinatorService([
    'audit' => static function (array $audit) use (&$publicationAudits): int {
        $publicationAudits[] = $audit;
        return count($publicationAudits);
    },
    'with_locked_revision' => static function (
        int $presetId,
        callable $criticalSection
    ) use (&$publishedForm, &$publicationCoordinatorRevision) {
        $snapshot = $publishedForm;
        try {
            $envelope = $criticalSection($publicationCoordinatorRevision);
            $publicationCoordinatorRevision = (int)$envelope['next_revision'];
            return $envelope;
        } catch (Throwable $error) {
            $publishedForm = $snapshot;
            throw $error;
        }
    },
]);
$publicationBlocked = false;
try {
    $publicationCoordinator->mutate(
        12740,
        [
            'action' => 'form_first_rollback',
            'entity_type' => 'form_first',
            'entity_id' => '12740',
            'expected_revision' => str_repeat('a', 64),
            'product_ids' => [],
        ],
        static function () use (&$publishedForm): array {
            $publishedForm = ['revision' => 4, 'compileHash' => str_repeat('4', 64)];
            throw new InvalidArgumentException(
                'Витринный калькулятор cards несовместим с публикацией пресета #12740: '
                . 'активная витрина не содержит отличий от базовой формы'
            );
        },
        static function () use (&$publishedForm): array {
            return $publishedForm;
        }
    );
} catch (InvalidArgumentException $error) {
    $publicationBlocked = str_contains($error->getMessage(), 'cards')
        && str_contains($error->getMessage(), 'отличий');
}
$assert(
    $publicationBlocked
        && $publishedForm === ['revision' => 7, 'compileHash' => str_repeat('7', 64)]
        && $publicationCoordinatorRevision === 11
        && $publicationAudits === [],
    'an active storefront no-op aborts rollback and persists neither the form mutation nor audit'
);

$source = (string)file_get_contents(dirname(__DIR__) . '/lib/Services/PresetMutationCoordinatorService.php');
$editorsSource = (string)file_get_contents(dirname(__DIR__) . '/lib/Services/ControlCenterEditorsService.php');
$assert(
    substr_count($editorsSource, 'call_user_func($this->activeStorefrontPublicationValidator, $presetId);') === 2,
    'publish and rollback must run active storefront validation inside their coordinated mutation callbacks'
);
foreach (['GET_LOCK', 'RELEASE_LOCK', 'INSERT INTO b_option', 'FOR UPDATE', 'startTransaction()', 'commitTransaction()', 'rollbackTransaction()', 'CEventLog::Add'] as $needle) {
    $assert(str_contains($source, $needle), 'production coordinator must contain ' . $needle);
}
$authorityLockPosition = strpos($source, 'withAuthorityInTransaction(');
$coordinatorRowPosition = strpos($source, '$connection->query($selectSql)', $authorityLockPosition ?: 0);
$assert(
    $authorityLockPosition !== false
        && $coordinatorRowPosition !== false
        && $authorityLockPosition < $coordinatorRowPosition,
    'coordinator must acquire global/source graph authority before its per-preset revision row'
);
$assert(
    str_contains($source, "'expected_before_sha256'")
        && str_contains($source, 'hash_equals($beforeHash, $expectedBeforeHash)'),
    'coordinator must enforce aggregate SHA CAS before the domain mutation'
);
$assert(
    !str_contains($source, 'Option::get') && !str_contains($source, 'Option::set'),
    'coordinator bootstrap and revision readback must bypass the Bitrix Option cache'
);
$include = (string)file_get_contents(dirname(__DIR__) . '/include.php');
$diagnostic = (string)file_get_contents(dirname(__DIR__) . '/lib/Diagnostic/ModuleDiagnostic.php');
$assert(
    str_contains($include, "'Prospektweb\\\\Calc\\\\Services\\\\PresetMutationCoordinatorService' => 'lib/Services/PresetMutationCoordinatorService.php'")
        && str_contains($diagnostic, "'lib/Services/PresetMutationCoordinatorService.php'"),
    'coordinator must be autoloaded and covered by module integrity diagnostics'
);

fwrite(STDOUT, "Preset mutation coordinator tests passed\n");
