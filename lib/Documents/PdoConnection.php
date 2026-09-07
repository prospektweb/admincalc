<?php
declare(strict_types=1);

namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/SqlConnection.php';

/** Standalone adapter; useful without a CMS and for real transactional tests. */
final class PdoConnection implements SqlConnection
{
    private \PDO $pdo;
    private bool $active = false;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        if (!in_array($this->dialect(), ['mysql', 'sqlite'], true)) {
            throw new \InvalidArgumentException('Unsupported document SQL dialect.');
        }
        if ($this->dialect() === 'sqlite') {
            // SQLite's built-in LOWER only folds ASCII. Match the production Unicode registry search.
            $pdo->sqliteCreateFunction('lower', static fn(?string $value): ?string => $value === null ? null : mb_strtolower($value, 'UTF-8'), 1, \PDO::SQLITE_DETERMINISTIC);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }

    public function dialect(): string { return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME); }
    public function inTransaction(): bool { return $this->active || $this->pdo->inTransaction(); }
    public function begin(bool $readSnapshot = false): void
    {
        if ($this->active || $this->pdo->inTransaction()) {
            throw new \LogicException('Document repository must own its transaction.');
        }
        if ($readSnapshot && $this->dialect() === 'mysql') {
            // Applies only to the next transaction, not the connection/session default.
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }
        $this->pdo->exec($this->dialect() === 'sqlite' ? ($readSnapshot ? 'BEGIN' : 'BEGIN IMMEDIATE') : 'START TRANSACTION');
        $this->active = true;
    }
    public function commit(): void { $this->pdo->exec('COMMIT'); $this->active = false; }
    public function rollback(): void { $this->pdo->exec('ROLLBACK'); $this->active = false; }
    public function execute(string $sql, array $parameters = []): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }
    public function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
