<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;

/**
 * One durable mutation boundary for preset-owned calculator documents.
 *
 * The coordinator serializes form, mapping, storefront and product-assignment
 * document mutations and single-preset activation changes on a database row.
 * It first acquires the shared calculator graph authority and only then the
 * per-preset coordinator row, so graph validation and publication cannot race
 * logic/stage/global writers.
 * Registry creation/duplication remains a separate lifecycle authority.
 * The domain write and authoritative readback share one transaction, which
 * also advances a monotonic revision and persists a payload-free audit event.
 */
final class PresetMutationCoordinatorService
{
    public const CONTRACT = 'prospektweb.calc.preset-mutation-coordinator/v1';
    public const AUDIT_TYPE_ID = 'PROSPEKTWEB_PRESET_MUTATION_V2';

    private const MODULE_ID = 'prospektweb.calc';
    private const OPTION_PREFIX = 'PRESET_MUTATION_V2_';
    private const BOOTSTRAP_LOCK_PREFIX = 'prospektweb.calc.preset-mutation-bootstrap.';
    private const BOOTSTRAP_LOCK_TIMEOUT_SECONDS = 5;
    private const MAX_SAFE_INTEGER = 9007199254740991;
    private const MAX_PRODUCT_IDS = 10000;

    /** @var array<string,callable> */
    private array $adapters;

    /** @param array<string,callable> $adapters */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return mixed exact domain mutation result (the public API is unchanged)
     */
    public function mutate(
        int $presetId,
        array $metadata,
        callable $mutation,
        callable $authoritativeReadback
    ) {
        $this->assertPresetId($presetId);
        $metadata = $this->normalizeMetadata($metadata);

        return $this->withLockedRevision(
            $presetId,
            function (int $coordinatorRevision) use (
                $presetId,
                $metadata,
                $mutation,
                $authoritativeReadback
            ): array {
                $authority = func_num_args() > 1 ? func_get_arg(1) : null;
                $before = $authoritativeReadback($authority);
                $beforeHash = self::hashCanonical($before);
                $expectedBeforeHash = $metadata['expected_before_sha256'];
                if (is_string($expectedBeforeHash) && !hash_equals($beforeHash, $expectedBeforeHash)) {
                    throw new \RuntimeException(
                        'Preset aggregate changed in another session. Refresh data and retry.',
                        409
                    );
                }
                $result = $mutation($authority);
                if ($authority instanceof CalculatorMutationAuthorityService) {
                    $authority->refreshLockedState($presetId);
                }
                $after = $authoritativeReadback($authority);
                $afterHash = self::hashCanonical($after);

                if ($coordinatorRevision >= self::MAX_SAFE_INTEGER) {
                    throw new \RuntimeException('Preset mutation revision is exhausted.', 409);
                }
                $nextCoordinatorRevision = $coordinatorRevision + 1;
                $audit = [
                    'contract' => self::CONTRACT,
                    'actorId' => $this->actorId(),
                    'action' => $metadata['action'],
                    'entityType' => $metadata['entity_type'],
                    'entityId' => $metadata['entity_id'],
                    'presetId' => $presetId,
                    'coordinatorRevisionBefore' => $coordinatorRevision,
                    'coordinatorRevisionAfter' => $nextCoordinatorRevision,
                    'expectedEntityRevision' => $metadata['expected_revision'],
                    'expectedBeforeSha256' => $metadata['expected_before_sha256'],
                    'resultEntityRevision' => $this->extractEntityRevision($after),
                    'beforeSha256' => $beforeHash,
                    'afterSha256' => $afterHash,
                    'productIds' => $this->affectedProductIds(
                        $metadata['product_ids'],
                        $before,
                        $after
                    ),
                    'result' => 'success',
                ];

                $this->writeAudit($audit);

                return [
                    'result' => $result,
                    'next_revision' => $nextCoordinatorRevision,
                    'audit' => $audit,
                ];
            }
        );
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function normalizeMetadata(array $metadata): array
    {
        foreach (array_keys($metadata) as $key) {
            if (!is_string($key)
                || !in_array($key, ['action', 'entity_type', 'entity_id', 'expected_revision', 'expected_before_sha256', 'product_ids'], true)) {
                throw new \InvalidArgumentException('Preset mutation metadata contains an unsupported field.');
            }
        }
        foreach (['action', 'entity_type', 'entity_id'] as $required) {
            if (!is_string($metadata[$required] ?? null)) {
                throw new \InvalidArgumentException('Preset mutation metadata is incomplete.');
            }
        }
        $action = $this->strictToken($metadata['action'], 80, 'action');
        $entityType = $this->strictToken($metadata['entity_type'], 80, 'entity_type');
        $entityId = trim((string)$metadata['entity_id']);
        if ($entityId === '' || strlen($entityId) > 160 || preg_match('/[\x00-\x1F\x7F]/', $entityId)) {
            throw new \InvalidArgumentException('Preset mutation entity_id is invalid.');
        }

        $expectedRevision = $metadata['expected_revision'] ?? null;
        if (!is_null($expectedRevision) && !is_int($expectedRevision) && !is_string($expectedRevision)) {
            throw new \InvalidArgumentException('Preset mutation expected_revision is invalid.');
        }
        if (is_string($expectedRevision)) {
            $expectedRevision = trim($expectedRevision);
            if ($expectedRevision === '' || strlen($expectedRevision) > 128) {
                throw new \InvalidArgumentException('Preset mutation expected_revision is invalid.');
            }
        } elseif (is_int($expectedRevision) && ($expectedRevision < 0 || $expectedRevision > self::MAX_SAFE_INTEGER)) {
            throw new \InvalidArgumentException('Preset mutation expected_revision is invalid.');
        }

        $rawProductIds = $metadata['product_ids'] ?? [];
        if (!is_array($rawProductIds) || !array_is_list($rawProductIds)
            || count($rawProductIds) > self::MAX_PRODUCT_IDS) {
            throw new \InvalidArgumentException('Preset mutation product_ids must be a bounded JSON array.');
        }
        $productIds = [];
        foreach ($rawProductIds as $productId) {
            if (!is_int($productId) || $productId <= 0 || $productId > self::MAX_SAFE_INTEGER) {
                throw new \InvalidArgumentException('Preset mutation product_ids contains an invalid ID.');
            }
            $productIds[$productId] = $productId;
        }
        ksort($productIds, SORT_NUMERIC);

        $expectedBeforeHash = $metadata['expected_before_sha256'] ?? null;
        if ($expectedBeforeHash !== null
            && (!is_string($expectedBeforeHash)
                || preg_match('/^[a-f0-9]{64}$/D', $expectedBeforeHash) !== 1)) {
            throw new \InvalidArgumentException('Preset mutation expected_before_sha256 is invalid.');
        }

        return [
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'expected_revision' => $expectedRevision,
            'expected_before_sha256' => $expectedBeforeHash,
            'product_ids' => array_values($productIds),
        ];
    }

    private function strictToken(string $value, int $maxLength, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength
            || preg_match('/^[a-z][a-z0-9._-]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Preset mutation ' . $label . ' is invalid.');
        }
        return $value;
    }

    /** @return mixed */
    private function withLockedRevision(int $presetId, callable $criticalSection)
    {
        if (isset($this->adapters['with_locked_revision'])) {
            $envelope = call_user_func(
                $this->adapters['with_locked_revision'],
                $presetId,
                $criticalSection
            );
            return $this->unwrapEnvelope($envelope);
        }
        if (!class_exists(Application::class)) {
            throw new \RuntimeException('Bitrix database is unavailable for preset mutation coordination.');
        }

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $optionName = self::OPTION_PREFIX . $presetId;
        $escapedModuleId = $helper->forSql(self::MODULE_ID);
        $escapedName = $helper->forSql($optionName);
        // The production collation is case-insensitive. Read the whole
        // collation-equivalent candidate set, then require one binary-exact
        // global row in PHP. A case alias or the alternate empty SITE_ID
        // representation must never become the coordinator authority.
        $candidateSelectSql = "SELECT MODULE_ID, NAME, SITE_ID, VALUE FROM b_option WHERE MODULE_ID='"
            . $escapedModuleId . "' AND NAME='" . $escapedName
            . "' AND (SITE_ID IS NULL OR SITE_ID='') ORDER BY BINARY MODULE_ID, BINARY NAME, SITE_ID";
        $selectSql = $candidateSelectSql . ' FOR UPDATE';
        $bootstrapSelectSql = $candidateSelectSql;
        $bootstrapLockName = self::BOOTSTRAP_LOCK_PREFIX . $presetId;
        $escapedBootstrapLockName = $helper->forSql($bootstrapLockName);
        $bootstrapLockHeld = false;
        $transactionStarted = false;
        try {
            $lockRows = $connection->query(
                "SELECT GET_LOCK('" . $escapedBootstrapLockName . "', "
                . self::BOOTSTRAP_LOCK_TIMEOUT_SECONDS . ') AS ACQUIRED'
            );
            $lockRow = is_object($lockRows) && method_exists($lockRows, 'fetch') ? $lockRows->fetch() : null;
            $bootstrapLockHeld = is_array($lockRow)
                && (int)($lockRow['ACQUIRED'] ?? $lockRow['acquired'] ?? 0) === 1;
            if (!$bootstrapLockHeld) {
                throw new \RuntimeException('Preset mutation bootstrap is busy.', 409);
            }

            // A connection-scoped database lock makes first-write row creation
            // deterministic even though MySQL permits duplicate NULL SITE_ID
            // values. No Bitrix Option cache participates in the authority.
            $bootstrapRow = $this->selectCanonicalCoordinatorRow(
                $this->fetchCandidateRows($connection->query($bootstrapSelectSql)),
                $optionName,
                true
            );
            if ($bootstrapRow === null) {
                $escapedInitialValue = $helper->forSql($this->encodeRevision(0));
                $connection->queryExecute(
                    "INSERT INTO b_option (MODULE_ID, NAME, VALUE, SITE_ID) VALUES ('"
                    . $escapedModuleId . "','" . $escapedName . "','" . $escapedInitialValue . "',NULL)"
                );
                $this->assertAffectedRows($connection, 1, 'Preset mutation lock row creation failed.');
                $this->selectCanonicalCoordinatorRow(
                    $this->fetchCandidateRows($connection->query($bootstrapSelectSql)),
                    $optionName,
                    false
                );
            }

            $connection->startTransaction();
            $transactionStarted = true;
            $calculatorAuthority = new CalculatorMutationAuthorityService();
            $envelope = $calculatorAuthority->withAuthorityInTransaction(
                $connection,
                $presetId,
                function () use (
                    $connection,
                    $selectSql,
                    $criticalSection,
                    $calculatorAuthority,
                    $helper,
                    $escapedModuleId,
                    $escapedName,
                    $optionName,
                    $escapedBootstrapLockName,
                    &$bootstrapLockHeld
                ): array {
                    $row = $this->selectCanonicalCoordinatorRow(
                        $this->fetchCandidateRows($connection->query($selectSql)),
                        $optionName,
                        false
                    );
                    $this->releaseBootstrapLock($connection, $escapedBootstrapLockName);
                    $bootstrapLockHeld = false;
                    $revision = $this->decodeRevision((string)($row['VALUE'] ?? $row['value'] ?? ''));
                    $envelope = $criticalSection($revision, $calculatorAuthority);
                    if (!is_array($envelope) || !array_key_exists('next_revision', $envelope)) {
                        throw new \RuntimeException('Preset mutation coordinator returned an invalid envelope.');
                    }
                    $nextRevision = (int)$envelope['next_revision'];
                    if ($nextRevision !== $revision + 1) {
                        throw new \RuntimeException('Preset mutation coordinator revision did not advance exactly once.');
                    }
                    $escapedValue = $helper->forSql($this->encodeRevision($nextRevision));
                    $connection->queryExecute(
                        "UPDATE b_option SET VALUE='" . $escapedValue . "' WHERE MODULE_ID='" . $escapedModuleId
                        . "' AND BINARY MODULE_ID=BINARY '" . $escapedModuleId
                        . "' AND NAME='" . $escapedName . "' AND BINARY NAME=BINARY '" . $escapedName
                        . "' AND SITE_ID IS NULL"
                    );
                    $this->assertAffectedRows($connection, 1, 'Preset mutation revision update was ambiguous.');
                    $readBackRow = $this->selectCanonicalCoordinatorRow(
                        $this->fetchCandidateRows($connection->query($selectSql)),
                        $optionName,
                        false
                    );
                    if ($this->decodeRevision((string)($readBackRow['VALUE'] ?? $readBackRow['value'] ?? '')) !== $nextRevision) {
                        throw new \RuntimeException('Preset mutation revision readback failed.');
                    }
                    return $envelope;
                }
            );
            $connection->commitTransaction();
            $transactionStarted = false;
            return $this->unwrapEnvelope($envelope);
        } catch (\Throwable $error) {
            if ($transactionStarted) {
                $connection->rollbackTransaction();
                $transactionStarted = false;
            }
            throw $error;
        } finally {
            if ($bootstrapLockHeld) {
                $this->releaseBootstrapLock($connection, $escapedBootstrapLockName);
            }
        }
    }

    /** @param object $connection */
    private function releaseBootstrapLock($connection, string $escapedLockName): void
    {
        try {
            $connection->query("SELECT RELEASE_LOCK('" . $escapedLockName . "') AS RELEASED");
        } catch (\Throwable $releaseError) {
            // The lock is connection-scoped and is released when the request
            // connection closes. Never hide the domain mutation outcome.
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchCandidateRows($rows): array
    {
        if (!is_object($rows) || !method_exists($rows, 'fetch')) {
            return [];
        }
        $result = [];
        for ($index = 0; $index < 2; $index++) {
            $row = $rows->fetch();
            if (!is_array($row)) {
                break;
            }
            $result[] = $row;
        }
        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function selectCanonicalCoordinatorRow(array $rows, string $optionName, bool $allowMissing): ?array
    {
        if ($rows === []) {
            if ($allowMissing) {
                return null;
            }
            throw new \RuntimeException('Preset mutation lock row is absent.', 409);
        }
        if (count($rows) !== 1) {
            throw new \RuntimeException('Preset mutation lock row is ambiguous.', 409);
        }
        $row = $rows[0];
        $moduleId = (string)($row['MODULE_ID'] ?? $row['module_id'] ?? '');
        $name = (string)($row['NAME'] ?? $row['name'] ?? '');
        $hasSiteId = array_key_exists('SITE_ID', $row) || array_key_exists('site_id', $row);
        $siteId = array_key_exists('SITE_ID', $row)
            ? $row['SITE_ID']
            : ($row['site_id'] ?? null);
        if ($moduleId !== self::MODULE_ID || $name !== $optionName || !$hasSiteId || $siteId !== null) {
            throw new \RuntimeException('Preset mutation lock row identity is not canonical.', 409);
        }
        return $row;
    }

    private function assertAffectedRows($connection, int $expected, string $message): void
    {
        $cursor = $connection->query('SELECT ROW_COUNT() AS AFFECTED');
        $row = is_object($cursor) && method_exists($cursor, 'fetch') ? $cursor->fetch() : null;
        if (!is_array($row)
            || (int)($row['AFFECTED'] ?? $row['affected'] ?? -1) !== $expected) {
            throw new \RuntimeException($message, 409);
        }
    }

    /** @param mixed $envelope @return mixed */
    private function unwrapEnvelope($envelope)
    {
        if (!is_array($envelope) || !array_key_exists('result', $envelope)) {
            throw new \RuntimeException('Preset mutation coordinator returned an invalid result.');
        }
        return $envelope['result'];
    }

    private function encodeRevision(int $revision): string
    {
        $raw = json_encode(
            ['contract' => self::CONTRACT, 'revision' => $revision],
            JSON_UNESCAPED_SLASHES
        );
        if (!is_string($raw)) {
            throw new \RuntimeException('Unable to encode preset mutation revision.');
        }
        return $raw;
    }

    private function decodeRevision(string $raw): int
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)
            || array_keys($decoded) !== ['contract', 'revision']
            || ($decoded['contract'] ?? null) !== self::CONTRACT
            || !is_int($decoded['revision'] ?? null)
            || $decoded['revision'] < 0
            || $decoded['revision'] > self::MAX_SAFE_INTEGER) {
            throw new \UnexpectedValueException('Preset mutation revision row is corrupted.');
        }
        return $decoded['revision'];
    }

    /** @param array<string,mixed> $audit */
    private function writeAudit(array $audit): void
    {
        if (isset($this->adapters['audit'])) {
            $result = call_user_func($this->adapters['audit'], $audit);
        } else {
            if (!class_exists('CEventLog')) {
                throw new \RuntimeException('Bitrix audit log is unavailable.');
            }
            $description = json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($description)) {
                throw new \RuntimeException('Unable to encode preset mutation audit metadata.');
            }
            $result = \CEventLog::Add([
                'SEVERITY' => 'SECURITY',
                'AUDIT_TYPE_ID' => self::AUDIT_TYPE_ID,
                'MODULE_ID' => self::MODULE_ID,
                'ITEM_ID' => (string)$audit['presetId'],
                'DESCRIPTION' => $description,
            ]);
        }
        if ($result === false) {
            throw new \RuntimeException('Preset mutation audit write failed.');
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

    /**
     * @param int[] $declared
     * @param mixed $before
     * @param mixed $after
     * @return int[]
     */
    private function affectedProductIds(array $declared, $before, $after): array
    {
        $ids = array_fill_keys($declared, true);
        foreach ([$before, $after] as $readback) {
            if (!is_array($readback)) {
                continue;
            }
            foreach (['product_ids', 'linkedProductIds'] as $key) {
                foreach (is_array($readback[$key] ?? null) ? $readback[$key] : [] as $productId) {
                    if (is_int($productId) && $productId > 0 && $productId <= self::MAX_SAFE_INTEGER) {
                        $ids[$productId] = true;
                    }
                }
            }
        }
        $productIds = array_map('intval', array_keys($ids));
        sort($productIds, SORT_NUMERIC);
        return $productIds;
    }

    /** @param mixed $value */
    private function extractEntityRevision($value)
    {
        if (!is_array($value)) {
            return null;
        }
        foreach (['revision', 'aggregateRevision', 'expectedRevision'] as $key) {
            if (isset($value[$key]) && (is_int($value[$key]) || is_string($value[$key]))) {
                return $value[$key];
            }
        }
        foreach (['workspace', 'published', 'data'] as $key) {
            if (is_array($value[$key] ?? null)) {
                $nested = $this->extractEntityRevision($value[$key]);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }
        return null;
    }

    /** @param mixed $value */
    public static function hashCanonical($value): string
    {
        $raw = json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($raw)) {
            throw new \RuntimeException('Unable to hash preset mutation readback.');
        }
        return hash('sha256', $raw);
    }

    /** @param mixed $value @return mixed */
    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    private function assertPresetId(int $presetId): void
    {
        if ($presetId <= 0 || $presetId > self::MAX_SAFE_INTEGER) {
            throw new \InvalidArgumentException('Preset ID must be a safe positive integer.');
        }
    }
}
