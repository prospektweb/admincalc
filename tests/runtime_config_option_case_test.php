<?php

declare(strict_types=1);

require_once __DIR__ . '/bitrix_transaction_test_stubs.php';
require_once dirname(__DIR__) . '/lib/Services/CatalogRuntimeConfigAuthorityService.php';

use Prospektweb\Calc\Services\CatalogRuntimeConfigAuthorityService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$expectConflict = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (RuntimeException $error) {
        $assert($error->getCode() === 409, $message . ' has conflict status');
        return;
    }
    $assert(false, $message);
};

$canonicalNames = [
    'CALC_SERVER_URL',
    'IBLOCK_CALC_PRESETS',
    'IBLOCK_CALC_STAGES',
    'IBLOCK_CALC_SETTINGS',
    'IBLOCK_CALC_GLOBAL_VALUES',
    'IBLOCK_CALC_CUSTOM_FIELDS',
    'IBLOCK_CALC_MATERIALS',
    'IBLOCK_CALC_MATERIALS_VARIANTS',
    'IBLOCK_CALC_SUPPLIERS',
    'IBLOCK_CALC_OPERATIONS',
    'IBLOCK_CALC_OPERATIONS_VARIANTS',
    'IBLOCK_CALC_EQUIPMENT',
    'IBLOCK_CALC_DETAILS',
];
$assert(
    CatalogRuntimeConfigAuthorityService::canonicalAdminOptionNames() === $canonicalNames,
    'source-derived canonical Admin option order is exact'
);
$assert(
    CatalogRuntimeConfigAuthorityService::legacyAdminCatalogOptionNames()
        === ['PRODUCT_IBLOCK_ID', 'SKU_IBLOCK_ID'],
    'source-derived legacy deletion list is exact'
);

$rows = [];
foreach ($canonicalNames as $index => $name) {
    $rows[] = [
        'MODULE_ID' => 'prospektweb.calc',
        'NAME' => $name,
        'VALUE' => $name === 'CALC_SERVER_URL' ? 'https://pwrt.ru/calc-api' : (string)(41 + $index),
        'SITE_ID' => null,
    ];
}
$selected = CatalogRuntimeConfigAuthorityService::selectExactAdminOptions($rows);
$assert(array_keys($selected) === $canonicalNames, 'canonical Admin rows retain contract ordering');

$withLegacyOutsideQuery = array_merge($rows, [[
    'MODULE_ID' => 'prospektweb.calc',
    'NAME' => 'PRODUCT_IBLOCK_ID',
    'VALUE' => '14',
    'SITE_ID' => null,
]]);
$assert(
    CatalogRuntimeConfigAuthorityService::selectExactAdminOptions($withLegacyOutsideQuery) === $selected,
    'legacy rows outside the canonical query do not participate in runtime selection'
);

$lowercaseAlias = $rows;
$lowercaseAlias[1]['NAME'] = 'iblock_calc_presets';
$expectConflict(
    static fn() => CatalogRuntimeConfigAuthorityService::selectExactAdminOptions($lowercaseAlias),
    'lowercase Admin option alias fails closed'
);
$moduleAlias = $rows;
$moduleAlias[1]['MODULE_ID'] = 'PROSPEKTWEB.CALC';
$expectConflict(
    static fn() => CatalogRuntimeConfigAuthorityService::selectExactAdminOptions($moduleAlias),
    'module casing alias fails closed'
);
$siteEmpty = $rows;
$siteEmpty[1]['SITE_ID'] = '';
$expectConflict(
    static fn() => CatalogRuntimeConfigAuthorityService::selectExactAdminOptions($siteEmpty),
    'empty SITE_ID shadow fails closed'
);
$siteMissing = $rows;
unset($siteMissing[1]['SITE_ID']);
$expectConflict(
    static fn() => CatalogRuntimeConfigAuthorityService::selectExactAdminOptions($siteMissing),
    'missing SITE_ID provenance fails closed'
);
$duplicate = $rows;
$duplicate[] = $rows[1];
$expectConflict(
    static fn() => CatalogRuntimeConfigAuthorityService::selectExactAdminOptions($duplicate),
    'duplicate canonical row fails closed'
);
$missing = $rows;
array_pop($missing);
$expectConflict(
    static fn() => CatalogRuntimeConfigAuthorityService::selectExactAdminOptions($missing),
    'incomplete Admin aggregate fails closed'
);

final class RuntimeConfigCaseResult
{
    /** @var array<int,array<string,mixed>> */
    private array $rows;
    private int $offset = 0;

    /** @param array<int,array<string,mixed>> $rows */
    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
    }

    /** @return array<string,mixed>|false */
    public function fetch()
    {
        return $this->rows[$this->offset++] ?? false;
    }
}

final class RuntimeConfigCaseSqlHelper
{
    public function forSql(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}

final class RuntimeConfigCaseConnection extends \Bitrix\Main\DB\MysqliConnection
{
    /** @var array<int,array<string,mixed>> */
    private array $rows;
    /** @var string[] */
    public array $queries = [];

    /** @param array<int,array<string,mixed>> $rows */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function getSqlHelper(): RuntimeConfigCaseSqlHelper
    {
        return new RuntimeConfigCaseSqlHelper();
    }

    public function query(string $sql): RuntimeConfigCaseResult
    {
        $this->queries[] = $sql;
        return new RuntimeConfigCaseResult($this->rows);
    }

    public function startTransaction(): void { $this->transactionLevel++; }
    public function commitTransaction(): void { $this->transactionLevel--; }
    public function rollbackTransaction(): void { $this->transactionLevel--; }
}

$connection = new RuntimeConfigCaseConnection($rows);
$snapshot = (new CatalogRuntimeConfigAuthorityService())->captureCalculatorSnapshot($connection);
$assert(
    CatalogRuntimeConfigAuthorityService::adminOptionValue($snapshot, 'IBLOCK_CALC_PRESETS') === '42',
    'production snapshot uses the exact canonical Admin row'
);
$query = implode("\n", $connection->queries);
$assert(
    str_contains($query, 'LOWER(NAME) IN')
        && !str_contains($query, 'product_iblock_id')
        && !str_contains($query, 'sku_iblock_id'),
    'runtime SQL queries only the canonical Admin contract and never legacy names'
);

$source = (string)file_get_contents(
    dirname(__DIR__) . '/lib/Services/CatalogRuntimeConfigAuthorityService.php'
);
$captureStart = strpos($source, 'private function captureProduction');
$captureEnd = strpos($source, 'public static function selectExactAdminOptions', $captureStart ?: 0);
$captureBody = substr($source, $captureStart ?: 0, ($captureEnd ?: strlen($source)) - ($captureStart ?: 0));
$assert(
    !str_contains($captureBody, 'legacyAdminCatalogOptionNames')
        && !str_contains($captureBody, 'legacyFrontCalculatorOptionNames'),
    'runtime capture does not consult source-derived legacy cutover lists'
);

$resolverCalls = 0;
$resolverAuthority = new CatalogRuntimeConfigAuthorityService([
    'resolve_calculator_iblock' => static function () use (&$resolverCalls): int {
        $resolverCalls++;
        return 41;
    },
]);
try {
    $resolverAuthority->resolveCalculatorIblockId('UNKNOWN');
    $assert(false, 'single-target adapter must not bypass the exact calculator source contract');
} catch (InvalidArgumentException $error) {
    $assert($resolverCalls === 0, 'invalid single-target authority is rejected before adapter invocation');
}

$installerAuthority = new CatalogRuntimeConfigAuthorityService([
    'initialize_admin' => static fn(array $options): array => $options,
]);
$assert(
    $installerAuthority->initializeAdminOptionsForInstall($selected) === $selected,
    'installer accepts only the complete ordered canonical Admin aggregate'
);
try {
    $reordered = array_reverse($selected, true);
    $installerAuthority->initializeAdminOptionsForInstall($reordered);
    $assert(false, 'reordered installer contract must be rejected');
} catch (InvalidArgumentException $error) {
    $assert(true, 'reordered installer contract rejected');
}
$installerSource = (string)file_get_contents(dirname(__DIR__) . '/install/step3.php');
$assert(
    str_contains($installerSource, 'initializeAdminOptionsForInstall($runtimeOptions)')
        && str_contains($installerSource, "['iblock_ids']['CALC_GLOBAL_VALUES']")
        && !str_contains($installerSource, "Option::set(\$moduleId, 'IBLOCK_'")
        && !str_contains($installerSource, "Option::set(\$moduleId, 'PRODUCT_IBLOCK_ID'")
        && !str_contains($installerSource, "Option::set(\$moduleId, 'SKU_IBLOCK_ID'"),
    'fresh installer creates the global registry and exact Admin aggregate without Bitrix Option aliases'
);

echo "Runtime config exact option tests passed\n";
