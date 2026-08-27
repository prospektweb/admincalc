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

namespace {
    require_once __DIR__ . '/bitrix_transaction_test_stubs.php';
    require_once dirname(__DIR__) . '/lib/Services/BitrixTransactionStateAuthority.php';
    require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionBundleDocumentService.php';
    require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionFormDocumentService.php';

    use Bitrix\Main\Application;
    use Bitrix\Main\DB\MysqliConnection;
    use Prospektweb\Calc\Services\CalculatorVersionBundleDocumentService;
    use Prospektweb\Calc\Services\CalculatorVersionFormDocumentService;

    $assert = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };

    $connection = new MysqliConnection();
    Application::setConnection($connection);
    $presetId = 15576;
    $versionId = 'v_1111111111111111';
    $formOptionName = 'CALC_VERSION_FORM_' . $presetId . '_' . $versionId;
    $storedFormOptionName = mb_strtolower($formOptionName);
    $legacy = [
        'formDefinition' => ['contract' => 'form/v1', 'fields' => [['fieldId' => 'volume']]],
        'bindingDefinition' => ['contract' => 'binding/v1', 'bindings' => []],
    ];
    $forms = new CalculatorVersionFormDocumentService([
        'now' => static fn(): string => '2026-08-27T12:00:00+05:00',
    ]);
    $bundles = new CalculatorVersionBundleDocumentService();

    $originalForm = $forms->ensure($presetId, $versionId, null, $legacy);
    $cleanVersionId = 'v_2222222222222222';
    $cleanForm = $forms->create(
        $presetId,
        $cleanVersionId,
        ['contract' => 'form/v1', 'fields' => [['fieldId' => 'system.volume']]],
        ['contract' => 'binding/v1', 'bindings' => [['fieldId' => 'system.volume']]]
    );
    $assert(
        ($cleanForm['formDefinition']['fields'][0]['fieldId'] ?? '') === 'system.volume',
        'explicit clean form creation must not inherit the legacy form'
    );
    $documents = [];
    foreach (CalculatorVersionBundleDocumentService::COMPONENTS as $component) {
        $documents[$component] = ['contract' => 'test/' . $component, 'marker' => 'original'];
    }
    $connection->startTransaction();
    $originalBundle = $bundles->save($presetId, $versionId, $documents);
    $connection->commitTransaction();

    $connection->startTransaction();
    try {
        $changedDefinition = $originalForm['formDefinition'];
        $changedDefinition['fields'][0]['fieldId'] = 'changed';
        $forms->saveDraft(
            $presetId,
            $versionId,
            $originalForm['revision'],
            $changedDefinition,
            $originalForm['bindingDefinition']
        );
        $changedDocuments = $documents;
        $changedDocuments['form']['marker'] = 'changed';
        $bundles->save($presetId, $versionId, $changedDocuments);
        throw new RuntimeException('simulated aggregate failure');
    } catch (RuntimeException $error) {
        $connection->rollbackTransaction();
        $assert($error->getMessage() === 'simulated aggregate failure', 'test must roll back the simulated aggregate failure');
    }

    $rolledBackForm = $forms->ensure($presetId, $versionId, null, $legacy);
    $rolledBackBundle = $bundles->load($presetId, $versionId);
    $assert(
        ($rolledBackForm['formDefinition']['fields'][0]['fieldId'] ?? '') === 'volume',
        'form document must roll back with the failed aggregate mutation'
    );
    $assert(
        ($rolledBackBundle['documents']['form']['marker'] ?? '') === 'original'
            && hash_equals((string)$originalBundle['contentHash'], (string)($rolledBackBundle['contentHash'] ?? '')),
        'bundle document must roll back together with the form document'
    );

    $connection->seedOption('prospektweb.calc', $storedFormOptionName, 'site-specific-sentinel', 's1');
    $forms->delete($presetId, $versionId);
    $assert(
        $connection->optionValue('prospektweb.calc', $storedFormOptionName, '') === null,
        'delete must remove the exact default-site form row'
    );
    $assert(
        $connection->optionValue('prospektweb.calc', $storedFormOptionName, 's1') === 'site-specific-sentinel',
        'delete must preserve same-name site-scoped rows'
    );

    $queries = implode("\n", $connection->queries());
    $assert(
        str_contains($queries, "BINARY MODULE_ID='prospektweb.calc'")
            && str_contains($queries, "BINARY NAME='" . $storedFormOptionName . "'")
            && str_contains($queries, "(SITE_ID IS NULL OR SITE_ID='')")
            && str_contains($queries, 'FOR UPDATE'),
        'form storage must use exact default-site SQL and row locks'
    );

    echo "Calculator version form transaction tests passed\n";
}
