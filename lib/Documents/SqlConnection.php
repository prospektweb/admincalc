<?php
declare(strict_types=1);

namespace Prospektweb\Calc\Documents;

/** Infrastructure port. All SQL templates are owned by this package. */
interface SqlConnection
{
    public function dialect(): string;
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function execute(string $sql, array $parameters = []): void;
    public function rows(string $sql, array $parameters = []): array;
}
