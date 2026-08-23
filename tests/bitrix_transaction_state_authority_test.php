<?php

declare(strict_types=1);

require_once __DIR__ . '/bitrix_transaction_test_stubs.php';
require_once dirname(__DIR__) . '/lib/Services/BitrixTransactionStateAuthority.php';

use Prospektweb\Calc\Services\BitrixTransactionStateAuthority;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expect409 = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (RuntimeException $error) {
        $assert($error->getCode() === 409, $message . ': wrong status code');
        return;
    }
    $assert(false, $message . ': refusal expected');
};

$connection = new \Bitrix\Main\DB\MysqliConnection();
$assert(BitrixTransactionStateAuthority::level($connection) === 0, 'level zero is inactive');
$connection->startTransaction();
$assert(BitrixTransactionStateAuthority::level($connection) === 1, 'outer transaction is active');
$connection->startTransaction();
$assert(BitrixTransactionStateAuthority::level($connection) === 2, 'nested transaction is active');
$connection->commitTransaction();
$assert(BitrixTransactionStateAuthority::level($connection) === 1, 'nested commit retains the outer transaction');
$connection->rollbackTransaction();
$assert(!BitrixTransactionStateAuthority::isActive($connection), 'outer rollback returns to zero');
$expect409(
    static fn(): int => BitrixTransactionStateAuthority::level(new stdClass()),
    'wrong connection lineage fails closed'
);
$connection->setTransactionLevelForTest(-1);
$expect409(
    static fn(): int => BitrixTransactionStateAuthority::level($connection),
    'negative transaction nesting fails closed'
);

$source = (string)file_get_contents(dirname(__DIR__) . '/lib/Services/BitrixTransactionStateAuthority.php');
$assert(
    str_contains($source, 'MysqlCommonConnection')
        && str_contains($source, 'transactionLevel')
        && str_contains($source, 'Closure::bind')
        && !str_contains($source, '@@session.' . 'in_transaction')
        && !str_contains($source, 'SAVEPOINT'),
    'authority reads only the exact framework-owned transaction nesting state'
);

echo "Bitrix transaction state authority tests passed\n";
