<?php

declare(strict_types=1);

namespace Bitrix\Main {
    final class Application
    {
        private static object $connection;

        public static function setConnection(object $connection): void
        {
            self::$connection = $connection;
        }

        public static function getConnection(): object
        {
            return self::$connection;
        }
    }
}

namespace Bitrix\Main\Config {
    final class Option
    {
        /** @var array<string,string> */
        private static array $values = [];

        public static function get(string $moduleId, string $name, string $default = ''): string
        {
            return self::$values[$moduleId . ':' . $name] ?? $default;
        }

        public static function set(string $moduleId, string $name, string $value): void
        {
            self::$values[$moduleId . ':' . $name] = $value;
        }
    }
}

namespace {
    require_once __DIR__ . '/bitrix_transaction_test_stubs.php';
    require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionBundleDocumentService.php';
    require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionRegistryService.php';

    use Bitrix\Main\Application;
    use Bitrix\Main\DB\MysqliConnection;
    use Prospektweb\Calc\Services\BitrixTransactionStateAuthority;
    use Prospektweb\Calc\Services\CalculatorVersionBundleDocumentService;
    use Prospektweb\Calc\Services\CalculatorVersionRegistryService;

    $assert = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };

    $legacy = ['published' => null, 'history' => [], 'compile' => ['diff' => []]];
    $actor = ['id' => 1, 'name' => 'admin'];
    $connection = new MysqliConnection();
    Application::setConnection($connection);
    $registryStorage = [];
    $service = new CalculatorVersionRegistryService([
        'get' => static fn(string $name): string => (string)($GLOBALS['transaction_registry_storage'][$name] ?? ''),
        'set' => static function (string $name, string $value): void { $GLOBALS['transaction_registry_storage'][$name] = $value; },
        'id' => static fn(): string => 'v_1111111111111111',
        'now' => static fn(): string => '2026-08-27T12:00:00+05:00',
    ]);
    $GLOBALS['transaction_registry_storage'] = &$registryStorage;

    $service->loadWorkspace(15576, 'Широкоформатная печать', $legacy, $actor);
    $assert(
        $connection->transactionEvents() === ['start', 'commit'],
        'standalone version registry request must own exactly one transaction'
    );
    $assert(BitrixTransactionStateAuthority::level($connection) === 0, 'standalone transaction must close');

    $bundleService = new CalculatorVersionBundleDocumentService();
    foreach (['rawSet', 'rawDelete'] as $methodName) {
        $method = new ReflectionMethod(CalculatorVersionBundleDocumentService::class, $methodName);
        $method->setAccessible(true);
        try {
            $arguments = $methodName === 'rawSet'
                ? ['CALC_VERSION_TEST', '{}']
                : ['CALC_VERSION_TEST'];
            $method->invokeArgs($bundleService, $arguments);
            $assert(false, $methodName . ' must reject a bundle write outside the shared transaction');
        } catch (ReflectionException $error) {
            throw $error;
        } catch (Throwable $error) {
            $assert(
                $error instanceof RuntimeException && $error->getCode() === 409,
                $methodName . ' must fail closed outside the shared transaction'
            );
        }
    }

    $connection->startTransaction();
    $connection->clearTransactionEvents();
    $service->loadWorkspace(15576, 'Широкоформатная печать', $legacy, $actor);
    $assert(
        $connection->transactionEvents() === [],
        'version registry must not start or commit a nested transaction'
    );
    $assert(BitrixTransactionStateAuthority::level($connection) === 1, 'caller transaction must remain active');

    $withLock = new ReflectionMethod(CalculatorVersionRegistryService::class, 'withLock');
    $withLock->setAccessible(true);
    $connection->clearTransactionEvents();
    $originalError = new RuntimeException('original publication failure');
    try {
        $withLock->invoke($service, 15576, static function () use ($originalError): void {
            throw $originalError;
        });
        $assert(false, 'nested failure must be rethrown');
    } catch (ReflectionException $error) {
        throw $error;
    } catch (Throwable $error) {
        $assert($error === $originalError, 'nested failure must not be masked by rollback handling');
    }
    $assert(
        $connection->transactionEvents() === [],
        'version registry must not roll back a caller-owned transaction'
    );
    $assert(BitrixTransactionStateAuthority::level($connection) === 1, 'failed nested call must preserve caller transaction');
    $connection->rollbackTransaction();

    $registrySource = (string)file_get_contents(dirname(__DIR__) . '/lib/Services/CalculatorVersionRegistryService.php');
    $coordinatorSource = (string)file_get_contents(dirname(__DIR__) . '/lib/Services/PresetMutationCoordinatorService.php');
    foreach (['CalculatorVersionRegistryService' => $registrySource, 'PresetMutationCoordinatorService' => $coordinatorSource] as $label => $source) {
        $assert(
            str_contains($source, 'BitrixTransactionStateAuthority::isActive($connection)')
                && str_contains($source, 'if ($ownsTransaction)'),
            $label . ' must participate in an existing Bitrix transaction without owning its rollback'
        );
    }

    echo "Calculator version transaction boundary tests passed\n";
}
