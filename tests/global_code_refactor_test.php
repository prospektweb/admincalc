<?php

require_once dirname(__DIR__) . '/lib/Services/GlobalCodeRefactorService.php';

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

echo "Global code refactor token tests passed\n";
