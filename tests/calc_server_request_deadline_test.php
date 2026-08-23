<?php

require_once __DIR__ . '/../lib/Services/CalcServerRequestDeadline.php';

use Prospektweb\Calc\Services\CalcServerRequestDeadline;
use Prospektweb\Calc\Services\CalcServerRequestDeadlineExceeded;

$now = 10_000_000_000;
$clock = static function () use (&$now): int {
    return $now;
};

$deadline = new CalcServerRequestDeadline(300000, $clock);
if ($deadline->capTimeoutMilliseconds(300000) !== 300000) {
    throw new RuntimeException('Initial request timeout was not capped to the exact request budget.');
}

$now += 299_500_000_000;
if ($deadline->capTimeoutMilliseconds(300000) !== 500) {
    throw new RuntimeException('A sequential request did not receive only the remaining monotonic budget.');
}

$now += 500_000_000;
try {
    $deadline->capTimeoutMilliseconds(1);
    throw new RuntimeException('An exhausted deadline accepted another calc-server call.');
} catch (CalcServerRequestDeadlineExceeded $expected) {
    if ($expected->getCode() !== 504
        || CalcServerRequestDeadlineExceeded::ERROR_CODE !== 'CALC_SERVER_REQUEST_DEADLINE_EXCEEDED') {
        throw new RuntimeException('Deadline exception contract is invalid.');
    }
}

$now = 20_000_000_000;
$startedAt = $now - 1_000_000_000;
$fromRequestStart = new CalcServerRequestDeadline(300000, $clock, $startedAt);
if ($fromRequestStart->capTimeoutMilliseconds(300000) !== 299000) {
    throw new RuntimeException('The deadline did not include time elapsed before service construction.');
}

if (count(array_chunk(range(1, 400), 6)) !== 67) {
    throw new RuntimeException('The maximum Admin preview is not the expected 67 sequential chunks.');
}

foreach ([0, 300001] as $invalidBudget) {
    try {
        new CalcServerRequestDeadline($invalidBudget, $clock);
        throw new RuntimeException('Invalid request budget was accepted.');
    } catch (InvalidArgumentException $expected) {
    }
}

echo "calc_server_request_deadline_test: OK\n";
