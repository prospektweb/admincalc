<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/CalculatorMutationAuthorityService.php';

use Prospektweb\Calc\Services\CalculatorMutationAuthorityService;

final class CalculatorIblockIdentityCursor
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

final class CalculatorIblockIdentitySqlHelper
{
    public function forSql(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}

final class CalculatorIblockIdentityConnection
{
    /** @var array<int,array<string,mixed>> */
    public array $optionRows;
    /** @var array<int,array<string,mixed>> */
    public array $iblockRows;
    /** @var string[] */
    public array $queries = [];

    /**
     * @param array<int,array<string,mixed>> $optionRows
     * @param array<int,array<string,mixed>> $iblockRows
     */
    public function __construct(array $optionRows, array $iblockRows)
    {
        $this->optionRows = $optionRows;
        $this->iblockRows = $iblockRows;
    }

    public function getSqlHelper(): CalculatorIblockIdentitySqlHelper
    {
        return new CalculatorIblockIdentitySqlHelper();
    }

    public function query(string $sql): CalculatorIblockIdentityCursor
    {
        $this->queries[] = $sql;
        return new CalculatorIblockIdentityCursor(
            strpos($sql, 'FROM b_option') !== false ? $this->optionRows : $this->iblockRows
        );
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expectConflict = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message);
    } catch (RuntimeException $error) {
        $assert($error->getCode() === 409, $message . ' (expected conflict status)');
    }
};

$types = [
    'CALC_DETAILS' => 'calculator_catalog',
    'CALC_PRESETS' => 'calculator',
    'CALC_SETTINGS' => 'calculator',
    'CALC_STAGES' => 'calculator_catalog',
    'CALC_GLOBAL_VALUES' => 'calculator',
    'CALC_CUSTOM_FIELDS' => 'calculator',
    'CALC_OPERATIONS' => 'calculator_catalog',
    'CALC_OPERATIONS_VARIANTS' => 'calculator_catalog',
    'CALC_MATERIALS' => 'calculator_catalog',
    'CALC_MATERIALS_VARIANTS' => 'calculator_catalog',
    'CALC_EQUIPMENT' => 'calculator_catalog',
];
$optionRows = [];
$iblockRows = [];
$expectedIds = [];
$id = 101;
foreach ($types as $code => $type) {
    $optionRows[] = ['NAME' => 'IBLOCK_' . $code, 'VALUE' => (string)$id];
    $iblockRows[] = ['ID' => $id, 'CODE' => $code, 'IBLOCK_TYPE_ID' => $type];
    $expectedIds[$code] = $id;
    $id++;
}

$method = new ReflectionMethod(CalculatorMutationAuthorityService::class, 'lockConfiguredIblockIds');
$method->setAccessible(true);
$authority = new CalculatorMutationAuthorityService();
$connection = new CalculatorIblockIdentityConnection($optionRows, $iblockRows);
$actualIds = $method->invoke($authority, $connection);
ksort($expectedIds, SORT_STRING);
$assert($actualIds === $expectedIds, 'complete exact code/type authority is accepted');
$assert(count($connection->queries) === 2, 'authority uses one option lock and one iblock identity-set lock');
$iblockQuery = $connection->queries[1] ?? '';
$assert(
    strpos($iblockQuery, 'SELECT ID, CODE, IBLOCK_TYPE_ID FROM b_iblock WHERE ') !== false
    && strpos($iblockQuery, 'IBLOCK_TYPE_ID=') !== false
    && strpos($iblockQuery, 'CODE IN (') !== false
    && str_ends_with($iblockQuery, 'FOR UPDATE'),
    'the complete canonical code/type row set is locked for update'
);
$assert(strpos($iblockQuery, 'WHERE ID IN (') === false, 'identity locking is not limited to configured IDs');

$duplicateRows = $iblockRows;
$duplicateRows[] = [
    'ID' => 999,
    'CODE' => 'CALC_PRESETS',
    'IBLOCK_TYPE_ID' => 'calculator',
];
$expectConflict(
    static fn() => $method->invoke(
        $authority,
        new CalculatorIblockIdentityConnection($optionRows, $duplicateRows)
    ),
    'a second row for one canonical code/type identity fails closed'
);

$caseCollisionRows = $iblockRows;
$caseCollisionRows[] = [
    'ID' => 998,
    'CODE' => 'calc_presets',
    'IBLOCK_TYPE_ID' => 'calculator',
];
$expectConflict(
    static fn() => $method->invoke(
        $authority,
        new CalculatorIblockIdentityConnection($optionRows, $caseCollisionRows)
    ),
    'a collation-equivalent non-canonical identity fails closed'
);

$repointedOptions = $optionRows;
foreach ($repointedOptions as &$row) {
    if (($row['NAME'] ?? '') === 'IBLOCK_CALC_PRESETS') {
        $row['VALUE'] = '999';
    }
}
unset($row);
$expectConflict(
    static fn() => $method->invoke(
        $authority,
        new CalculatorIblockIdentityConnection($repointedOptions, $iblockRows)
    ),
    'a configured ID that differs from the sole canonical identity fails closed'
);

fwrite(STDOUT, "Calculator mutation iblock identity lock tests passed\n");
