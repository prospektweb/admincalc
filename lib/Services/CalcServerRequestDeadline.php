<?php

namespace Prospektweb\Calc\Services;

final class CalcServerRequestDeadlineExceeded extends \RuntimeException
{
    public const ERROR_CODE = 'CALC_SERVER_REQUEST_DEADLINE_EXCEEDED';

    public function __construct()
    {
        parent::__construct('The calc-server request budget has been exhausted.', 504);
    }
}

/**
 * A single monotonic wall-clock budget shared by every calc-server call made
 * by one HTTP operation.  Millisecond cURL limits prevent a final request from
 * rounding past the overall deadline.
 */
final class CalcServerRequestDeadline
{
    public const MAX_BUDGET_MILLISECONDS = 300000;

    /** @var callable():int */
    private $clock;
    private int $deadlineNanoseconds;

    /** @param callable():int|null $clock Returns monotonic nanoseconds. */
    public function __construct(
        int $budgetMilliseconds = self::MAX_BUDGET_MILLISECONDS,
        ?callable $clock = null,
        ?int $startedAtNanoseconds = null
    ) {
        if ($budgetMilliseconds < 1 || $budgetMilliseconds > self::MAX_BUDGET_MILLISECONDS) {
            throw new \InvalidArgumentException('Calc-server request budget must be between 1 and 300000 milliseconds.');
        }
        $this->clock = $clock ?? static function (): int {
            return hrtime(true);
        };
        $nowNanoseconds = $this->nowNanoseconds();
        if ($startedAtNanoseconds !== null
            && ($startedAtNanoseconds < 0 || $startedAtNanoseconds > $nowNanoseconds)) {
            throw new \InvalidArgumentException('Calc-server request start must be a valid monotonic timestamp.');
        }
        $this->deadlineNanoseconds = ($startedAtNanoseconds ?? $nowNanoseconds)
            + ($budgetMilliseconds * 1000000);
    }

    public function remainingMilliseconds(): int
    {
        $remainingNanoseconds = $this->deadlineNanoseconds - $this->nowNanoseconds();
        return $remainingNanoseconds > 0 ? intdiv($remainingNanoseconds, 1000000) : 0;
    }

    public function assertAvailable(): void
    {
        if ($this->remainingMilliseconds() < 1) {
            throw new CalcServerRequestDeadlineExceeded();
        }
    }

    public function capTimeoutMilliseconds(int $requestedMilliseconds): int
    {
        if ($requestedMilliseconds < 1) {
            throw new \InvalidArgumentException('Requested calc-server timeout must be positive.');
        }
        $remaining = $this->remainingMilliseconds();
        if ($remaining < 1) {
            throw new CalcServerRequestDeadlineExceeded();
        }
        return min($requestedMilliseconds, $remaining);
    }

    private function nowNanoseconds(): int
    {
        $value = ($this->clock)();
        if (!is_int($value) || $value < 0) {
            throw new \RuntimeException('Monotonic clock returned an invalid value.');
        }
        return $value;
    }
}
