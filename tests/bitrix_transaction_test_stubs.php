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
            /** @var array<string,array{MODULE_ID:string,NAME:string,VALUE:string,SITE_ID:?string}> */
            private array $optionRows = [];
            /** @var list<array<string,array{MODULE_ID:string,NAME:string,VALUE:string,SITE_ID:?string}>> */
            private array $optionSnapshots = [];
            /** @var list<string> */
            private array $queries = [];

            public function startTransaction(): void
            {
                $this->transactionEvents[] = 'start';
                $this->optionSnapshots[] = $this->optionRows;
                parent::startTransaction();
            }

            public function commitTransaction(): void
            {
                $this->transactionEvents[] = 'commit';
                array_pop($this->optionSnapshots);
                parent::commitTransaction();
            }

            public function rollbackTransaction(): void
            {
                $this->transactionEvents[] = 'rollback';
                $snapshot = array_pop($this->optionSnapshots);
                if (is_array($snapshot)) {
                    $this->optionRows = $snapshot;
                }
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
                $this->queries[] = $sql;
                $rows = [];
                if (str_contains($sql, 'FROM b_module')) {
                    $rows = str_contains($sql, 'prospektweb.frontcalc')
                        ? [['ID' => 'prospektweb.calc'], ['ID' => 'prospektweb.frontcalc']]
                        : [['ID' => 'prospektweb.calc']];
                } elseif (str_contains($sql, 'FROM b_option')) {
                    [$moduleId, $name] = $this->optionIdentityFromSql($sql);
                    foreach ([null, ''] as $siteId) {
                        $key = $this->optionKey($moduleId, $name, $siteId);
                        if (isset($this->optionRows[$key])) {
                            $rows[] = ['VALUE' => $this->optionRows[$key]['VALUE']];
                        }
                    }
                }
                return new class($rows) {
                    private int $index = 0;

                    public function __construct(private array $rows)
                    {
                    }

                    public function fetch(): ?array
                    {
                        return $this->rows[$this->index++] ?? null;
                    }
                };
            }

            public function queryExecute(string $sql): void
            {
                $this->queries[] = $sql;
                if (preg_match(
                    "/^INSERT INTO b_option .* VALUES \\('((?:''|[^'])*)','((?:''|[^'])*)','((?:''|[^'])*)',''\\)$/s",
                    $sql,
                    $match
                ) === 1) {
                    $this->seedOption($this->unescape($match[1]), $this->unescape($match[2]), $this->unescape($match[3]), '');
                    return;
                }
                if (preg_match(
                    "/^UPDATE b_option SET VALUE='((?:''|[^'])*)' WHERE BINARY MODULE_ID='((?:''|[^'])*)' AND BINARY NAME='((?:''|[^'])*)' AND \\(SITE_ID IS NULL OR SITE_ID=''\\)$/s",
                    $sql,
                    $match
                ) === 1) {
                    $moduleId = $this->unescape($match[2]);
                    $name = $this->unescape($match[3]);
                    foreach ([null, ''] as $siteId) {
                        $key = $this->optionKey($moduleId, $name, $siteId);
                        if (isset($this->optionRows[$key])) {
                            $this->optionRows[$key]['VALUE'] = $this->unescape($match[1]);
                        }
                    }
                    return;
                }
                if (preg_match(
                    "/^DELETE FROM b_option WHERE BINARY MODULE_ID='((?:''|[^'])*)' AND BINARY NAME='((?:''|[^'])*)' AND \\(SITE_ID IS NULL OR SITE_ID=''\\)$/s",
                    $sql,
                    $match
                ) === 1) {
                    foreach ([null, ''] as $siteId) {
                        unset($this->optionRows[$this->optionKey($this->unescape($match[1]), $this->unescape($match[2]), $siteId)]);
                    }
                    return;
                }
                throw new \RuntimeException('Unsupported test SQL: ' . $sql);
            }

            public function seedOption(string $moduleId, string $name, string $value, ?string $siteId): void
            {
                $this->optionRows[$this->optionKey($moduleId, $name, $siteId)] = [
                    'MODULE_ID' => $moduleId,
                    'NAME' => $name,
                    'VALUE' => $value,
                    'SITE_ID' => $siteId,
                ];
            }

            public function optionValue(string $moduleId, string $name, ?string $siteId): ?string
            {
                return $this->optionRows[$this->optionKey($moduleId, $name, $siteId)]['VALUE'] ?? null;
            }

            /** @return list<string> */
            public function queries(): array
            {
                return $this->queries;
            }

            /** @return array{0:string,1:string} */
            private function optionIdentityFromSql(string $sql): array
            {
                if (preg_match(
                    "/BINARY MODULE_ID='((?:''|[^'])*)' AND BINARY NAME='((?:''|[^'])*)'/s",
                    $sql,
                    $match
                ) !== 1) {
                    throw new \RuntimeException('Unsupported option SELECT: ' . $sql);
                }
                return [$this->unescape($match[1]), $this->unescape($match[2])];
            }

            private function optionKey(string $moduleId, string $name, ?string $siteId): string
            {
                return $moduleId . "\0" . $name . "\0" . ($siteId ?? '<NULL>');
            }

            private function unescape(string $value): string
            {
                return str_replace("''", "'", $value);
            }
        }
    }
}
