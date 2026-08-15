<?php

require_once dirname(__DIR__) . '/lib/Services/CatalogCalculationWriteService.php';

use Prospektweb\Calc\Services\CatalogCalculationWriteService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$expectFailure = static function (callable $callback, string $message, ?int $code = null) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $error) {
        if ($code !== null) {
            $assert($error->getCode() === $code, $message . ' has the expected error code');
        }
        return;
    }
    $assert(false, $message);
};

final class RuntimeConfigOptionCaseResult
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
        if (!isset($this->rows[$this->offset])) {
            return false;
        }
        return $this->rows[$this->offset++];
    }
}

final class RuntimeConfigOptionCaseConnection
{
    /** @var array<int,array<string,mixed>> */
    private array $rows;
    public string $sql = '';

    /** @param array<int,array<string,mixed>> $rows */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function query(string $sql): RuntimeConfigOptionCaseResult
    {
        $this->sql = $sql;
        return new RuntimeConfigOptionCaseResult($this->rows);
    }
}

$capture = static function (array $rows): array {
    $service = new CatalogCalculationWriteService();
    $connection = new RuntimeConfigOptionCaseConnection($rows);
    $property = (new ReflectionClass($service))->getProperty('transactionConnection');
    $property->setAccessible(true);
    $property->setValue($service, $connection);
    $snapshot = $service->captureRuntimeConfigSnapshot();
    return [$snapshot, $connection->sql];
};

[$productionSnapshot, $productionSql] = $capture([
    [
        'MODULE_ID' => 'prospektweb.calc',
        'NAME' => 'iblock_calc_presets',
        'VALUE' => '41',
    ],
    [
        'MODULE_ID' => 'prospektweb.calc',
        'NAME' => 'iblock_calc_stages',
        'VALUE' => '42',
    ],
    [
        'MODULE_ID' => 'prospektweb.calc',
        'NAME' => 'iblock_calc_details',
        'VALUE' => '50',
    ],
]);
$assert(
    $productionSnapshot['prospektweb.calc:IBLOCK_CALC_PRESETS'] === '41'
    && $productionSnapshot['prospektweb.calc:IBLOCK_CALC_STAGES'] === '42'
    && $productionSnapshot['prospektweb.calc:IBLOCK_CALC_DETAILS'] === '50',
    'production lowercase Bitrix option names bind to canonical runtime keys'
);
$assert(
    strpos($productionSql, "(SITE_ID IS NULL OR SITE_ID='')") !== false,
    'runtime option authority remains restricted to exact global rows'
);

[$mixedSnapshot] = $capture([
    [
        'MODULE_ID' => 'prospektweb.frontcalc',
        'NAME' => 'Products_Iblock_Id',
        'VALUE' => '14',
    ],
]);
$assert(
    $mixedSnapshot['prospektweb.frontcalc:PRODUCTS_IBLOCK_ID'] === '14',
    'mixed-case legacy option names bind to the same canonical authority'
);

$expectFailure(
    static function () use ($capture): void {
        $capture([
            ['MODULE_ID' => 'prospektweb.calc', 'NAME' => 'iblock_calc_presets', 'VALUE' => '41'],
            ['MODULE_ID' => 'prospektweb.calc', 'NAME' => 'IBLOCK_CALC_PRESETS', 'VALUE' => '99'],
        ]);
    },
    'canonical duplicate option authorities fail closed',
    409
);
$expectFailure(
    static function () use ($capture): void {
        $capture([
            ['MODULE_ID' => 'prospektweb.calc', 'NAME' => 'iblock_calc_presets ', 'VALUE' => '41'],
        ]);
    },
    'whitespace aliases are not normalized into the allowlist'
);
$expectFailure(
    static function () use ($capture): void {
        $capture([
            ['MODULE_ID' => 'PROSPEKTWEB.CALC', 'NAME' => 'IBLOCK_CALC_PRESETS', 'VALUE' => '41'],
        ]);
    },
    'unexpected module-id casing remains fail closed'
);

echo "Runtime config option case tests passed\n";
