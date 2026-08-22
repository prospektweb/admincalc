<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/PresetMutationCoordinatorService.php';
require_once dirname(__DIR__) . '/lib/Install/AssignmentGuardActivationService.php';

use Prospektweb\Calc\Install\AssignmentGuardActivationService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$rows = [];
$audits = [];
$failAudit = false;
$scopeLocks = 0;
$service = new AssignmentGuardActivationService([
    'with_transaction' => static function (callable $criticalSection) use (&$rows): array {
        $snapshot = $rows;
        try {
            return $criticalSection();
        } catch (Throwable $error) {
            $rows = $snapshot;
            throw $error;
        }
    },
    'read_rows' => static function (bool $forUpdate) use (&$rows): array {
        if (!$forUpdate) {
            throw new RuntimeException('activation readback must be locked');
        }
        return $rows;
    },
    'lock_scope' => static function () use (&$scopeLocks): void {
        $scopeLocks++;
    },
    'register' => static function (string $event, string $method) use (&$rows): void {
        $rows[] = ['event' => $event, 'method' => $method];
    },
    'unregister' => static function (string $event, string $method) use (&$rows): void {
        $rows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => $row['event'] !== $event || $row['method'] !== $method
        ));
    },
    'audit' => static function (array $audit) use (&$audits, &$failAudit) {
        if ($failAudit) {
            return false;
        }
        $audits[] = $audit;
        return count($audits);
    },
]);

$first = $service->activate();
$assert(
    count($first['createdEvents'] ?? []) === 4
        && count($first['insertedRows'] ?? []) === 4
        && ($first['preexistingRows'] ?? null) === []
        && preg_match('/^[a-f0-9]{64}$/D', (string)($first['activationReceiptSha256'] ?? '')) === 1
        && count($rows) === 4,
    'first activation creates exact guard rows and a rollback receipt'
);
$second = $service->activate();
$assert(
    ($second['createdEvents'] ?? null) === []
        && count($second['preexistingRows'] ?? []) === 4
        && ($second['insertedRows'] ?? null) === []
        && count($rows) === 4,
    'second activation is idempotent and does not claim pre-existing rows for rollback'
);
$assert(count($audits) === 2, 'every verified activation is payload-free audited');
$deactivated = $service->deactivate($first);
$assert(
    count($deactivated['removedEvents'] ?? []) === 4 && $rows === [],
    'receipt rollback removes only rows inserted by that activation'
);
$assert($scopeLocks === 3 && count($audits) === 3, 'activation and rollback serialize and audit inside their transaction');

$rows = [];
$failAudit = true;
$failed = false;
try {
    $service->activate();
} catch (RuntimeException $error) {
    $failed = str_contains($error->getMessage(), 'audit');
}
$assert($failed && $rows === [], 'audit failure rolls back all newly registered handlers');

$failAudit = false;
$rows = [[
    'event' => 'OnBeforeIBlockElementAdd',
    'method' => 'onBeforeElementAdd',
]];
$partialReceipt = $service->activate();
$assert(count($partialReceipt['insertedRows'] ?? []) === 3, 'activation receipt distinguishes prior rows');
$failAudit = true;
$rollbackFailed = false;
try {
    $service->deactivate($partialReceipt);
} catch (RuntimeException $error) {
    $rollbackFailed = str_contains($error->getMessage(), 'audit');
}
$assert($rollbackFailed && count($rows) === 4, 'rollback audit failure restores every removed row');
$failAudit = false;
$service->deactivate($partialReceipt);
$assert(
    $rows === [[
        'event' => 'OnBeforeIBlockElementAdd',
        'method' => 'onBeforeElementAdd',
    ]],
    'successful rollback preserves the exact pre-existing handler row'
);

$install = (string)file_get_contents(dirname(__DIR__) . '/install/index.php');
$diagnostic = (string)file_get_contents(dirname(__DIR__) . '/tools/diagnostic.php');
$moduleDiagnostic = (string)file_get_contents(dirname(__DIR__) . '/lib/Diagnostic/ModuleDiagnostic.php');
$assert(
    str_contains($diagnostic, "case 'activate_assignment_guard':")
        && str_contains($diagnostic, 'AssignmentGuardActivationService())->activate()')
        && !str_contains($diagnostic, "case 'deactivate_assignment_guard':")
        && !str_contains($diagnostic, "case 'fix_events':")
        && !str_contains($diagnostic, 'DELETE FROM b_module_to_module'),
    'production HTTP diagnostics may activate the invariant but cannot disable or delete event authority'
);
foreach ([
    'OnBeforeIBlockElementAdd',
    'OnBeforeIBlockElementUpdate',
    'OnBeforeIBlockElementSetPropertyValues',
    'OnBeforeIBlockElementSetPropertyValuesEx',
] as $event) {
    $assert(substr_count($install, "'" . $event . "'") === 2, $event . ' install/uninstall mismatch');
    $assert(str_contains($moduleDiagnostic, $event), $event . ' is absent from diagnostic readback');
}

fwrite(STDOUT, "Assignment guard activation service tests passed\n");
