<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;

/**
 * Serializes cross-preset calculator mutations on one explicit global revision.
 */
final class GlobalCalculatorMutationCoordinatorService
{
    public const CONTRACT = 'prospektweb.calc.global-calculator-mutation/v1';

    private const MODULE_ID = 'prospektweb.calc';
    private const OPTION_NAME = 'GLOBAL_CALCULATOR_MUTATION_REVISION';
    private const AUDIT_TYPE_ID = 'PROSPEKTWEB_CALC_GLOBAL_MUTATION';
    private const MAX_SAFE_INTEGER = 9007199254740991;

    /** @var array<string,callable> */
    private array $adapters;

    /** @param array<string,callable> $adapters */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    public function revision(): int
    {
        if (isset($this->adapters['read_revision'])) {
            return $this->normalizeRevision(call_user_func($this->adapters['read_revision']));
        }
        if (!class_exists(Application::class)) {
            throw new \RuntimeException('Bitrix database is unavailable for global mutation revision.');
        }
        $connection = Application::getConnection();
        [$row, $duplicate] = $this->fetchTwo($connection->query($this->selectRevisionSql($connection, false)));
        if (is_array($duplicate)) {
            throw new \RuntimeException('Global mutation revision row is ambiguous.', 409);
        }
        return is_array($row)
            ? $this->decodeRevision((string)($row['VALUE'] ?? $row['value'] ?? ''))
            : 0;
    }

    /**
     * The mutation callback must return exact before/after readbacks from the
     * same transaction plus the public result.
     *
     * @return array<string,mixed>
     */
    public function mutate(
        int $expectedRevision,
        string $expectedFingerprint,
        callable $mutation,
        array $metadata = []
    ): array {
        $expectedRevision = $this->normalizeRevision($expectedRevision);
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $expectedFingerprint) !== 1) {
            throw new \InvalidArgumentException('Global mutation fingerprint is invalid.', 422);
        }

        return $this->withLockedRevision(
            $expectedRevision,
            function (int $revision, $authority, $connection) use (
                $expectedFingerprint,
                $mutation,
                $metadata
            ): array {
                $outcome = $mutation($authority, $connection);
                if (!is_array($outcome)
                    || !is_array($outcome['result'] ?? null)
                    || !is_array($outcome['before'] ?? null)
                    || !is_array($outcome['after'] ?? null)) {
                    throw new \RuntimeException('Global calculator mutation returned an invalid outcome.', 409);
                }
                if (($outcome['result']['status'] ?? null) !== 'ok') {
                    throw new \RuntimeException(
                        trim((string)($outcome['result']['message'] ?? ''))
                            ?: 'Global calculator mutation failed.',
                        409
                    );
                }
                $affectedPresetIds = $this->normalizePresetIds(
                    $outcome['affected_preset_ids'] ?? []
                );
                $nextRevision = $revision + 1;
                if ($nextRevision > self::MAX_SAFE_INTEGER) {
                    throw new \RuntimeException('Global calculator mutation revision is exhausted.', 409);
                }
                $audit = [
                    'contract' => self::CONTRACT,
                    'actorId' => $this->actorId(),
                    'action' => $this->normalizeAuditToken(
                        $metadata['action'] ?? 'global_code_refactor',
                        'action'
                    ),
                    'entityType' => $this->normalizeAuditToken(
                        $metadata['entity_type'] ?? 'calculator_global_aggregate',
                        'entity_type'
                    ),
                    'entityId' => $this->normalizeEntityId($metadata['entity_id'] ?? 'global'),
                    'globalRevisionBefore' => $revision,
                    'globalRevisionAfter' => $nextRevision,
                    'expectedFingerprint' => $expectedFingerprint,
                    'beforeSha256' => self::hashCanonical($outcome['before']),
                    'afterSha256' => self::hashCanonical($outcome['after']),
                    'affectedPresetIds' => $affectedPresetIds,
                    'result' => 'success',
                ];
                $this->writeAudit($audit);

                $result = $outcome['result'];
                $result['globalRevision'] = $nextRevision;
                $result['audit'] = $audit;
                return [
                    'result' => $result,
                    'next_revision' => $nextRevision,
                ];
            }
        );
    }

    /** @param mixed $value */
    private function normalizeAuditToken($value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Global mutation ' . $field . ' is invalid.', 422);
        }
        return $value;
    }

    /** @param mixed $value */
    private function normalizeEntityId($value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 120
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Global mutation entity_id is invalid.', 422);
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function withLockedRevision(int $expectedRevision, callable $criticalSection): array
    {
        if (isset($this->adapters['with_locked_revision'])) {
            $envelope = call_user_func(
                $this->adapters['with_locked_revision'],
                $expectedRevision,
                $criticalSection
            );
            return $this->unwrapEnvelope($envelope);
        }
        if (!class_exists(Application::class)) {
            throw new \RuntimeException('Bitrix database is unavailable for global mutation coordination.');
        }

        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $authority = (new CalculatorMutationAuthorityService())->lockAllAuthority($connection);
            $selectSql = $this->selectRevisionSql($connection, true);
            [$row, $duplicate] = $this->fetchTwo($connection->query($selectSql));
            if (is_array($duplicate)) {
                throw new \RuntimeException('Global mutation revision row is ambiguous.', 409);
            }
            if (!is_array($row)) {
                $helper = $connection->getSqlHelper();
                $connection->queryExecute(
                    "INSERT INTO b_option (MODULE_ID, NAME, VALUE, SITE_ID) VALUES ('"
                    . $helper->forSql(self::MODULE_ID) . "','"
                    . $helper->forSql(self::OPTION_NAME) . "','"
                    . $helper->forSql($this->encodeRevision(0)) . "',NULL)"
                );
                [$row, $duplicate] = $this->fetchTwo($connection->query($selectSql));
            }
            if (!is_array($row) || is_array($duplicate)) {
                throw new \RuntimeException('Global mutation revision row could not be locked.', 409);
            }
            $revision = $this->decodeRevision((string)($row['VALUE'] ?? $row['value'] ?? ''));
            if ($revision !== $expectedRevision) {
                throw new \RuntimeException(
                    'Global calculator data changed in another session. Repeat the preview.',
                    409
                );
            }

            $envelope = $criticalSection($revision, $authority, $connection);
            if (!is_array($envelope)
                || (int)($envelope['next_revision'] ?? -1) !== $revision + 1) {
                throw new \RuntimeException('Global mutation revision did not advance exactly once.', 409);
            }
            $nextRevision = (int)$envelope['next_revision'];
            $helper = $connection->getSqlHelper();
            $connection->queryExecute(
                "UPDATE b_option SET VALUE='" . $helper->forSql($this->encodeRevision($nextRevision))
                . "' WHERE MODULE_ID='" . $helper->forSql(self::MODULE_ID)
                . "' AND NAME='" . $helper->forSql(self::OPTION_NAME)
                . "' AND (SITE_ID IS NULL OR SITE_ID='')"
            );
            [$readback, $readbackDuplicate] = $this->fetchTwo($connection->query($selectSql));
            if (!is_array($readback) || is_array($readbackDuplicate)
                || $this->decodeRevision((string)($readback['VALUE'] ?? $readback['value'] ?? '')) !== $nextRevision) {
                throw new \RuntimeException('Global mutation revision readback failed.', 409);
            }
            $result = $this->unwrapEnvelope($envelope);
            $connection->commitTransaction();
            return $result;
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw $error;
        }
    }

    private function selectRevisionSql($connection, bool $forUpdate): string
    {
        $helper = $connection->getSqlHelper();
        return "SELECT VALUE FROM b_option WHERE MODULE_ID='" . $helper->forSql(self::MODULE_ID)
            . "' AND NAME='" . $helper->forSql(self::OPTION_NAME)
            . "' AND (SITE_ID IS NULL OR SITE_ID='')"
            . ($forUpdate ? ' FOR UPDATE' : '');
    }

    private function encodeRevision(int $revision): string
    {
        $encoded = json_encode(
            ['contract' => self::CONTRACT, 'revision' => $revision],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to encode global mutation revision.', 409);
        }
        return $encoded;
    }

    private function decodeRevision(string $encoded): int
    {
        $decoded = json_decode($encoded, true);
        if (!is_array($decoded)
            || array_keys($decoded) !== ['contract', 'revision']
            || ($decoded['contract'] ?? null) !== self::CONTRACT) {
            throw new \UnexpectedValueException('Global mutation revision is corrupted.', 409);
        }
        return $this->normalizeRevision($decoded['revision'] ?? null);
    }

    /** @param mixed $revision */
    private function normalizeRevision($revision): int
    {
        if (!is_int($revision) || $revision < 0 || $revision > self::MAX_SAFE_INTEGER) {
            throw new \InvalidArgumentException('Global mutation revision is invalid.', 422);
        }
        return $revision;
    }

    /** @param mixed $ids @return int[] */
    private function normalizePresetIds($ids): array
    {
        if (!is_array($ids) || !array_is_list($ids)) {
            throw new \RuntimeException('Affected preset IDs are invalid.', 409);
        }
        $normalized = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new \RuntimeException('Affected preset ID is invalid.', 409);
            }
            $normalized[$id] = $id;
        }
        ksort($normalized, SORT_NUMERIC);
        return array_values($normalized);
    }

    /** @param mixed $envelope @return array<string,mixed> */
    private function unwrapEnvelope($envelope): array
    {
        if (!is_array($envelope) || !is_array($envelope['result'] ?? null)) {
            throw new \RuntimeException('Global mutation coordinator returned an invalid result.', 409);
        }
        return $envelope['result'];
    }

    /** @return array{0:mixed,1:mixed} */
    private function fetchTwo($rows): array
    {
        if (!is_object($rows) || !method_exists($rows, 'fetch')) {
            return [null, null];
        }
        return [$rows->fetch(), $rows->fetch()];
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
                throw new \RuntimeException('Unable to encode global mutation audit metadata.');
            }
            $result = \CEventLog::Add([
                'SEVERITY' => 'SECURITY',
                'AUDIT_TYPE_ID' => self::AUDIT_TYPE_ID,
                'MODULE_ID' => self::MODULE_ID,
                'ITEM_ID' => 'global',
                'DESCRIPTION' => $description,
            ]);
        }
        if ($result === false) {
            throw new \RuntimeException('Global calculator mutation audit write failed.');
        }
    }

    private function actorId(): int
    {
        if (isset($this->adapters['actor_id'])) {
            return max(0, (int)call_user_func($this->adapters['actor_id']));
        }
        global $USER;
        return is_object($USER) && method_exists($USER, 'GetID') ? max(0, (int)$USER->GetID()) : 0;
    }

    /** @param mixed $value */
    public static function hashCanonical($value): string
    {
        $normalize = static function ($item) use (&$normalize) {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }
            return $item;
        };
        $encoded = json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to hash global mutation readback.', 409);
        }
        return hash('sha256', $encoded);
    }
}
