<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** An expected calculation failure, not a transport/storage failure or a quote. */
final class CoreExecutionFailure extends \InvalidArgumentException
{
    public readonly array $failure;

    public function __construct(array $failure)
    {
        $text = static fn($value, int $limit): bool => is_string($value) && $value !== ''
            && strlen($value) <= $limit && !preg_match('/[\x00-\x1f\x7f]/', $value);
        $invalid = static function (): never { throw new \RuntimeException('Invalid core failure response.', 503); };
        if (array_diff(array_keys($failure), ['contract', 'code', 'message', 'location'])
            || ($failure['contract'] ?? '') !== 'prospektweb.calculator/execution-failure-v1'
            || ($failure['code'] ?? '') !== 'CALCULATOR_EXECUTION_INVALID'
            || !$text($failure['message'] ?? null, 8000)) $invalid();
        if (array_key_exists('location', $failure)) {
            $at = $failure['location'];
            if (!is_array($at) || array_diff(array_keys($at), ['stageId', 'calculationId', 'kind', 'code', 'formulaId'])
                || !$text($at['stageId'] ?? null, 1000)
                || !in_array($at['kind'] ?? null, ['stage', 'input', 'formula', 'output', 'template'], true)) $invalid();
            if (array_key_exists('calculationId', $at) && !$text($at['calculationId'], 1000)) $invalid();
            if ($at['kind'] === 'stage') {
                if (array_key_exists('code', $at) || array_key_exists('formulaId', $at)) $invalid();
            } else {
                if (!$text($at['calculationId'] ?? null, 1000) || !$text($at['code'] ?? null, 4000)) $invalid();
                if ($at['kind'] === 'formula' ? !$text($at['formulaId'] ?? null, 1000) : array_key_exists('formulaId', $at)) $invalid();
            }
        }
        $this->failure = $failure;
        parent::__construct($failure['message'], 422);
    }
}
