<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Install;

use Bitrix\Main\Application;
use Bitrix\Main\EventManager;
use Prospektweb\Calc\Services\PresetMutationCoordinatorService;

/** Idempotent activation for existing installations; no broad event rewrite. */
final class AssignmentGuardActivationService
{
    public const CONTRACT = 'prospektweb.calc.assignment-guard-activation/v1';
    public const AUDIT_TYPE_ID = 'PROSPEKTWEB_ASSIGNMENT_GUARD_V1';
    private const MODULE_ID = 'prospektweb.calc';
    private const HANDLER_CLASS = '\\Prospektweb\\Calc\\Services\\PresetProductAssignmentMutationGuardService';
    private const REQUIRED = [
        'OnBeforeIBlockElementAdd' => 'onBeforeElementAdd',
        'OnBeforeIBlockElementUpdate' => 'onBeforeElementUpdate',
        'OnBeforeIBlockElementSetPropertyValues' => 'onBeforeSetPropertyValues',
        'OnBeforeIBlockElementSetPropertyValuesEx' => 'onBeforeSetPropertyValuesEx',
    ];

    /** @var array<string,callable> */
    private array $adapters;

    /** @param array<string,callable> $adapters */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /** @return array<string,mixed> */
    public function activate(): array
    {
        return $this->withTransaction(function (): array {
            $this->lockScope();
            $before = $this->readRows(true);
            $this->assertExactRows($before, false);
            $beforeMap = $this->rowMap($before);
            $created = [];
            foreach (self::REQUIRED as $event => $method) {
                if (($beforeMap[$event] ?? null) === $method) {
                    continue;
                }
                $this->register($event, $method);
                $created[] = $event;
            }
            $after = $this->readRows(true);
            $this->assertExactRows($after, true);
            sort($created, SORT_STRING);
            $handlerSetSha256 = PresetMutationCoordinatorService::hashCanonical(self::REQUIRED);
            $preexistingRows = $this->normalizeRows($before);
            $insertedRows = array_values(array_filter(
                $this->normalizeRows($after),
                static fn(array $row): bool => in_array($row['event'], $created, true)
            ));
            $receiptCore = [
                'contract' => self::CONTRACT,
                'preexistingRows' => $preexistingRows,
                'insertedRows' => $insertedRows,
                'handlerSetSha256' => $handlerSetSha256,
            ];
            $activationReceiptSha256 = PresetMutationCoordinatorService::hashCanonical($receiptCore);
            $this->writeAudit([
                'contract' => self::CONTRACT,
                'actorId' => $this->actorId(),
                'action' => 'activate_assignment_guard',
                'createdEvents' => $created,
                'handlerSetSha256' => $handlerSetSha256,
                'activationReceiptSha256' => $activationReceiptSha256,
                'result' => 'success',
            ]);
            return $receiptCore + [
                'createdEvents' => $created,
                'activeEvents' => array_keys(self::REQUIRED),
                'activationReceiptSha256' => $activationReceiptSha256,
            ];
        });
    }

    /**
     * Roll back only rows inserted by one exact activation receipt.
     * Pre-existing guard rows and every unrelated event registration are preserved.
     *
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    public function deactivate(array $receipt): array
    {
        $validated = $this->validateReceipt($receipt);
        return $this->withTransaction(function () use ($validated): array {
            $this->lockScope();
            $before = $this->readRows(true);
            $this->assertExactRows($before, true);
            $expectedActiveRows = $this->normalizeRows(array_merge(
                $validated['preexistingRows'],
                $validated['insertedRows']
            ));
            if ($this->normalizeRows($before) !== $expectedActiveRows) {
                throw new \RuntimeException('Assignment guard rollback authority changed after activation.', 409);
            }

            $removedEvents = [];
            foreach ($validated['insertedRows'] as $row) {
                $this->unregister((string)$row['event'], (string)$row['method']);
                $removedEvents[] = (string)$row['event'];
            }
            sort($removedEvents, SORT_STRING);

            $after = $this->readRows(true);
            $this->assertExactRows($after, false);
            if ($this->normalizeRows($after) !== $validated['preexistingRows']) {
                throw new \RuntimeException('Assignment guard rollback readback mismatch.', 409);
            }
            $this->writeAudit([
                'contract' => self::CONTRACT,
                'actorId' => $this->actorId(),
                'action' => 'deactivate_assignment_guard',
                'removedEvents' => $removedEvents,
                'activationReceiptSha256' => $validated['activationReceiptSha256'],
                'handlerSetSha256' => $validated['handlerSetSha256'],
                'result' => 'success',
            ]);
            return [
                'contract' => self::CONTRACT,
                'removedEvents' => $removedEvents,
                'activeEvents' => array_column($validated['preexistingRows'], 'event'),
                'handlerSetSha256' => $validated['handlerSetSha256'],
                'activationReceiptSha256' => $validated['activationReceiptSha256'],
            ];
        });
    }

    /** @return mixed */
    private function withTransaction(callable $criticalSection)
    {
        if (isset($this->adapters['with_transaction'])) {
            return call_user_func($this->adapters['with_transaction'], $criticalSection);
        }
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $result = $criticalSection();
            $connection->commitTransaction();
            return $result;
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw $error;
        }
    }

    private function lockScope(): void
    {
        if (isset($this->adapters['lock_scope'])) {
            call_user_func($this->adapters['lock_scope']);
            return;
        }
        $connection = Application::getConnection();
        $moduleId = $connection->getSqlHelper()->forSql(self::MODULE_ID);
        $row = $connection->query("SELECT ID FROM b_module WHERE ID='" . $moduleId . "' FOR UPDATE")->fetch();
        if (!is_array($row) || (string)($row['ID'] ?? $row['id'] ?? '') !== self::MODULE_ID) {
            throw new \RuntimeException('Assignment guard activation authority is unavailable.', 409);
        }
    }

    /** @return array<int,array{event:string,method:string}> */
    private function readRows(bool $forUpdate): array
    {
        if (isset($this->adapters['read_rows'])) {
            $rows = call_user_func($this->adapters['read_rows'], $forUpdate);
            return is_array($rows) ? $rows : [];
        }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $class = $helper->forSql(ltrim(self::HANDLER_CLASS, '\\'));
        $slashClass = $helper->forSql(self::HANDLER_CLASS);
        $cursor = $connection->query(
            "SELECT MESSAGE_ID, TO_METHOD FROM b_module_to_module WHERE FROM_MODULE_ID='iblock'"
            . " AND TO_MODULE_ID='" . self::MODULE_ID . "'"
            . " AND TO_CLASS IN ('" . $class . "','" . $slashClass . "')"
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $result = [];
        while (is_object($cursor) && method_exists($cursor, 'fetch') && ($row = $cursor->fetch())) {
            $result[] = [
                'event' => (string)($row['MESSAGE_ID'] ?? $row['message_id'] ?? ''),
                'method' => (string)($row['TO_METHOD'] ?? $row['to_method'] ?? ''),
            ];
        }
        return $result;
    }

    private function register(string $event, string $method): void
    {
        if (isset($this->adapters['register'])) {
            call_user_func($this->adapters['register'], $event, $method);
            return;
        }
        EventManager::getInstance()->registerEventHandler(
            'iblock',
            $event,
            self::MODULE_ID,
            self::HANDLER_CLASS,
            $method
        );
    }

    private function unregister(string $event, string $method): void
    {
        if (isset($this->adapters['unregister'])) {
            call_user_func($this->adapters['unregister'], $event, $method);
            return;
        }
        EventManager::getInstance()->unRegisterEventHandler(
            'iblock',
            $event,
            self::MODULE_ID,
            self::HANDLER_CLASS,
            $method
        );
    }

    /** @param array<int,array{event:string,method:string}> $rows */
    private function assertExactRows(array $rows, bool $complete): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $event = (string)($row['event'] ?? '');
            $method = (string)($row['method'] ?? '');
            if (!isset(self::REQUIRED[$event]) || self::REQUIRED[$event] !== $method || isset($seen[$event])) {
                throw new \RuntimeException('Assignment guard event rows are ambiguous.', 409);
            }
            $seen[$event] = true;
        }
        if ($complete) {
            $map = $this->rowMap($rows);
            foreach (self::REQUIRED as $event => $method) {
                if (($map[$event] ?? null) !== $method) {
                    throw new \RuntimeException('Assignment guard activation readback mismatch.', 409);
                }
            }
            if (count($map) !== count(self::REQUIRED)) {
                throw new \RuntimeException('Assignment guard activation readback mismatch.', 409);
            }
        }
    }

    /** @param array<int,array{event:string,method:string}> $rows @return array<string,string> */
    private function rowMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['event']] = (string)$row['method'];
        }
        return $map;
    }

    /** @param array<int,array{event:string,method:string}> $rows @return array<int,array{event:string,method:string}> */
    private function normalizeRows(array $rows): array
    {
        $this->assertExactRows($rows, false);
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = [
                'event' => (string)$row['event'],
                'method' => (string)$row['method'],
            ];
        }
        usort($normalized, static fn(array $left, array $right): int => strcmp($left['event'], $right['event']));
        return $normalized;
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function validateReceipt(array $receipt): array
    {
        $handlerSetSha256 = PresetMutationCoordinatorService::hashCanonical(self::REQUIRED);
        if (($receipt['contract'] ?? null) !== self::CONTRACT
            || ($receipt['handlerSetSha256'] ?? null) !== $handlerSetSha256
            || !is_array($receipt['preexistingRows'] ?? null)
            || !is_array($receipt['insertedRows'] ?? null)) {
            throw new \InvalidArgumentException('Assignment guard activation receipt is invalid.', 422);
        }
        $preexistingRows = $this->normalizeRows($receipt['preexistingRows']);
        $insertedRows = $this->normalizeRows($receipt['insertedRows']);
        $preexistingEvents = array_column($preexistingRows, 'event');
        foreach ($insertedRows as $row) {
            if (in_array($row['event'], $preexistingEvents, true)) {
                throw new \InvalidArgumentException('Assignment guard activation receipt overlaps rows.', 422);
            }
        }
        $combined = $this->normalizeRows(array_merge($preexistingRows, $insertedRows));
        $this->assertExactRows($combined, true);
        $core = [
            'contract' => self::CONTRACT,
            'preexistingRows' => $preexistingRows,
            'insertedRows' => $insertedRows,
            'handlerSetSha256' => $handlerSetSha256,
        ];
        $receiptSha256 = strtolower(trim((string)($receipt['activationReceiptSha256'] ?? '')));
        $expectedSha256 = PresetMutationCoordinatorService::hashCanonical($core);
        if (preg_match('/^[a-f0-9]{64}$/D', $receiptSha256) !== 1
            || !hash_equals($expectedSha256, $receiptSha256)) {
            throw new \InvalidArgumentException('Assignment guard activation receipt fingerprint is invalid.', 422);
        }
        return $core + ['activationReceiptSha256' => $receiptSha256];
    }

    /** @param array<string,mixed> $audit */
    private function writeAudit(array $audit): void
    {
        if (isset($this->adapters['audit'])) {
            $result = call_user_func($this->adapters['audit'], $audit);
        } else {
            $description = json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $result = class_exists('CEventLog') && is_string($description) ? \CEventLog::Add([
                'SEVERITY' => 'SECURITY',
                'AUDIT_TYPE_ID' => self::AUDIT_TYPE_ID,
                'MODULE_ID' => self::MODULE_ID,
                'ITEM_ID' => 'CALC_PRESET',
                'DESCRIPTION' => $description,
            ]) : false;
        }
        if ($result === false) {
            throw new \RuntimeException('Assignment guard activation audit failed.');
        }
    }

    private function actorId(): int
    {
        if (isset($this->adapters['actor_id'])) {
            return max(0, (int)call_user_func($this->adapters['actor_id']));
        }
        $user = $GLOBALS['USER'] ?? null;
        return is_object($user) && method_exists($user, 'GetID') ? max(0, (int)$user->GetID()) : 0;
    }
}
