<?php

require_once dirname(__DIR__) . '/lib/Services/NeutralFormulaPolicy.php';
require_once dirname(__DIR__) . '/lib/Install/Preset12740NeutralGlobalSymbolMigrationService.php';
require_once dirname(__DIR__) . '/lib/Services/GlobalCodeRefactorService.php';
require_once dirname(__DIR__) . '/lib/Services/GlobalSymbolService.php';

$service = new \Prospektweb\Calc\Services\GlobalCodeRefactorService();
$replace = new ReflectionMethod($service, 'replaceIdentifiers');
$replace->setAccessible(true);

$formula = 'paper_price + get("paper_price") + \'paper_price\' + paper_price_extra';
$actual = $replace->invoke($service, $formula, ['paper_price' => 'paper_sheet_price']);
$expected = 'paper_sheet_price + get("paper_price") + \'paper_price\' + paper_price_extra';
if ($actual !== $expected) {
    fwrite(STDERR, "FAILED: identifier replacement must skip strings and longer identifiers\n");
    exit(1);
}

$split = new ReflectionMethod($service, 'splitDescription');
$split->setAccessible(true);
$parts = $split->invoke($service, 'paper_price + 1|Название\\|с разделителем|Описание');
if ($parts !== ['paper_price + 1', '|Название\\|с разделителем|Описание']) {
    fwrite(STDERR, "FAILED: legacy global formula metadata split changed\n");
    exit(1);
}

$rewriteCondition = new ReflectionMethod($service, 'rewriteCondition');
$rewriteCondition->setAccessible(true);
$condition = [
    'version' => 3,
    'groups' => [[
        'kind' => 'condition',
        'branches' => [[
            'operands' => [
                ['kind' => 'constant', 'code' => 'old_flag'],
                ['kind' => 'variable', 'code' => 'untouched_flag'],
            ],
        ]],
    ]],
];
$rewrittenCondition = $rewriteCondition->invoke($service, $condition, ['old_flag' => 'is_lamination_enabled']);
if (($rewrittenCondition['groups'][0]['branches'][0]['operands'][0]['code'] ?? '') !== 'is_lamination_enabled'
    || ($rewrittenCondition['groups'][0]['branches'][0]['operands'][1]['code'] ?? '') !== 'untouched_flag') {
    fwrite(STDERR, "FAILED: nested condition operands were not refactored safely\n");
    exit(1);
}

$symbols = new \Prospektweb\Calc\Services\GlobalSymbolService();
$normalize = new ReflectionMethod($symbols, 'normalizeRequestedCode');
$normalize->setAccessible(true);
if ($normalize->invoke($symbols, ' sheet_width_mm ') !== 'sheet_width_mm') {
    fwrite(STDERR, "FAILED: explicit global code must be normalized without changing its identifier\n");
    exit(1);
}
foreach (['1width', 'ширина', 'get', 'input', 'stage_42', '__Proto__'] as $invalidCode) {
    try {
        $normalize->invoke($symbols, $invalidCode);
        fwrite(STDERR, "FAILED: invalid or reserved global code {$invalidCode} was accepted\n");
        exit(1);
    } catch (ReflectionException $error) {
        throw $error;
    } catch (Throwable $error) {
        // Expected validation failure.
    }
}

echo "Global code refactor token tests passed\n";
