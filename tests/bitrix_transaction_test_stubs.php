<?php

declare(strict_types=1);

namespace Bitrix\Main\DB {
    if (!class_exists(MysqlCommonConnection::class, false)) {
        abstract class MysqlCommonConnection
        {
            protected int $transactionLevel = 0;

            public function startTransaction(): void
            {
                $this->transactionLevel++;
            }

            public function commitTransaction(): void
            {
                $this->transactionLevel--;
            }

            public function rollbackTransaction(): void
            {
                $this->transactionLevel--;
            }

            public function setTransactionLevelForTest(int $level): void
            {
                $this->transactionLevel = $level;
            }

            public function unsetTransactionLevelForTest(): void
            {
                unset($this->transactionLevel);
            }
        }

        class MysqliConnection extends MysqlCommonConnection
        {
            /** @var list<string> */
            private array $transactionEvents = [];

            public function startTransaction(): void
            {
                $this->transactionEvents[] = 'start';
                parent::startTransaction();
            }

            public function commitTransaction(): void
            {
                $this->transactionEvents[] = 'commit';
                parent::commitTransaction();
            }

            public function rollbackTransaction(): void
            {
                $this->transactionEvents[] = 'rollback';
                parent::rollbackTransaction();
            }

            /** @return list<string> */
            public function transactionEvents(): array
            {
                return $this->transactionEvents;
            }

            public function clearTransactionEvents(): void
            {
                $this->transactionEvents = [];
            }

            public function getSqlHelper(): object
            {
                return new class {
                    public function forSql(string $value): string
                    {
                        return str_replace("'", "''", $value);
                    }
                };
            }

            public function query(string $sql): object
            {
                return new class($sql) {
                    public function __construct(private string $sql)
                    {
                    }

                    public function fetch(): ?array
                    {
                        return str_contains($this->sql, 'FROM b_module')
                            ? ['ID' => 'prospektweb.calc']
                            : null;
                    }
                };
            }
        }
    }
}
