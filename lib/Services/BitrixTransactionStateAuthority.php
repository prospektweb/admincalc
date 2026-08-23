<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

/**
 * Fail-closed authority for the transaction nesting owned by Bitrix.
 *
 * The production Bitrix connection maintains its transaction/savepoint state
 * in MysqlCommonConnection::$transactionLevel. SQL server variables are not a
 * portable authority for that framework-owned nesting state.
 */
final class BitrixTransactionStateAuthority
{
    public const CONTRACT = 'prospektweb.calc.bitrix-transaction-state/v1';

    private const CONNECTION_CLASS = 'Bitrix\\Main\\DB\\MysqliConnection';
    private const DECLARING_CLASS = 'Bitrix\\Main\\DB\\MysqlCommonConnection';
    private const PROPERTY = 'transactionLevel';

    public static function level(object $connection): int
    {
        $connectionClass = self::CONNECTION_CLASS;
        $declaringClass = self::DECLARING_CLASS;
        if (!class_exists($connectionClass, false)
            || !class_exists($declaringClass, false)
            || !$connection instanceof $connectionClass
            || !is_subclass_of($connectionClass, $declaringClass, true)) {
            throw new \RuntimeException('Unsupported Bitrix transaction connection lineage.', 409);
        }

        try {
            $property = new \ReflectionProperty($declaringClass, self::PROPERTY);
        } catch (\ReflectionException $error) {
            throw new \RuntimeException('Bitrix transaction state property is unavailable.', 409, $error);
        }
        $type = $property->getType();
        if ($property->getDeclaringClass()->getName() !== $declaringClass
            || !$property->isProtected()
            || $property->isStatic()
            || !$type instanceof \ReflectionNamedType
            || !$type->isBuiltin()
            || $type->getName() !== 'int'
            || $type->allowsNull()
            || !$property->isInitialized($connection)) {
            throw new \RuntimeException('Bitrix transaction state property contract drifted.', 409);
        }

        $reader = \Closure::bind(
            static function (object $target) {
                return $target->transactionLevel;
            },
            null,
            $declaringClass
        );
        if (!$reader instanceof \Closure) {
            throw new \RuntimeException('Bitrix transaction state reader could not be bound.', 409);
        }
        try {
            $level = $reader($connection);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Bitrix transaction state could not be read.', 409, $error);
        }
        if (!is_int($level) || $level < 0) {
            throw new \RuntimeException('Bitrix transaction nesting level is invalid.', 409);
        }
        return $level;
    }

    public static function isActive(object $connection): bool
    {
        return self::level($connection) > 0;
    }
}
