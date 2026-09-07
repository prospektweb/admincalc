<?php
declare(strict_types=1);

namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/SqlConnection.php';
require_once dirname(__DIR__) . '/Services/BitrixTransactionStateAuthority.php';

use Prospektweb\Calc\Services\BitrixTransactionStateAuthority;

/** Bitrix is isolated here: no iblock or option access in the document repository. */
final class BitrixConnection implements SqlConnection
{
    private object $connection;
    public function __construct(object $connection) { $this->connection = $connection; }
    public function dialect(): string { return 'mysql'; }
    public function inTransaction(): bool { return BitrixTransactionStateAuthority::isActive($this->connection); }
    public function begin(): void
    {
        if (BitrixTransactionStateAuthority::isActive($this->connection)) {
            throw new \LogicException('Document repository must own its transaction.');
        }
        $this->connection->startTransaction();
    }
    public function commit(): void { $this->connection->commitTransaction(); }
    public function rollback(): void { $this->connection->rollbackTransaction(); }
    public function execute(string $sql, array $parameters = []): void
    {
        $this->connection->queryExecute($this->bind($sql, $parameters));
    }
    public function rows(string $sql, array $parameters = []): array
    {
        $result = $this->connection->query($this->bind($sql, $parameters));
        $rows = [];
        while ($row = $result->fetch()) { $rows[] = $row; }
        return $rows;
    }
    private function bind(string $sql, array $parameters): string
    {
        // Only fixed internal SQL is accepted. Parameters can never become SQL syntax.
        $parts = explode('?', $sql);
        if (count($parts) !== count($parameters) + 1) {
            throw new \LogicException('SQL parameter count mismatch.');
        }
        $result = array_shift($parts);
        foreach ($parameters as $index => $value) {
            if ($value === null) { $literal = 'NULL'; }
            elseif (is_int($value)) { $literal = (string)$value; }
            elseif (is_string($value)) { $literal = "'" . $this->connection->getSqlHelper()->forSql($value) . "'"; }
            else { throw new \InvalidArgumentException('Unsupported SQL parameter type.'); }
            $result .= $literal . $parts[$index];
        }
        return $result;
    }
}
