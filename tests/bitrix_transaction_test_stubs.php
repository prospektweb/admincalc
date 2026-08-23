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
        }
    }
}
