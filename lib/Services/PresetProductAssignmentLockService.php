<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;

/**
 * One database-wide lock authority for CALC_PRESET product assignments.
 *
 * The lock is intentionally scoped by the configured product iblock rather
 * than by preset: two presets may compete for the same product, so their
 * assignment mutations and storefront assignment proofs must serialize.
 */
final class PresetProductAssignmentLockService
{
    private const LOCK_PREFIX = 'prospektweb.calc.preset-products.';
    private const LOCK_TIMEOUT_SECONDS = 5;

    /** @var callable|null */
    private $adapter;

    public function __construct(?callable $adapter = null)
    {
        $this->adapter = $adapter;
    }

    /** @return mixed */
    public function withLock(int $productIblockId, callable $criticalSection)
    {
        if ($productIblockId <= 0 || $productIblockId > 9007199254740991) {
            throw new \InvalidArgumentException('Product iblock ID must be a safe positive integer.');
        }
        if ($this->adapter !== null) {
            return call_user_func($this->adapter, $productIblockId, $criticalSection);
        }

        if (!class_exists(Application::class)) {
            throw new \RuntimeException('База данных Bitrix недоступна для блокировки привязок товаров.');
        }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $lockName = self::LOCK_PREFIX . $productIblockId;
        $escapedLockName = $helper->forSql($lockName);
        $rows = $connection->query(
            "SELECT GET_LOCK('" . $escapedLockName . "', " . self::LOCK_TIMEOUT_SECONDS . ') AS ACQUIRED'
        );
        $row = is_object($rows) && method_exists($rows, 'fetch') ? $rows->fetch() : null;
        $acquired = is_array($row) ? (int)($row['ACQUIRED'] ?? $row['acquired'] ?? 0) : 0;
        if ($acquired !== 1) {
            throw new \RuntimeException('Привязки товаров заняты другой операцией. Повторите сохранение.', 409);
        }

        try {
            return $criticalSection($productIblockId);
        } finally {
            try {
                $connection->query("SELECT RELEASE_LOCK('" . $escapedLockName . "') AS RELEASED");
            } catch (\Throwable $releaseError) {
                // GET_LOCK is connection-scoped and is also released when the
                // request connection closes. Never mask the domain outcome.
            }
        }
    }
}
