<?php

namespace Prospektweb\Calc\Services;

final class BatchJobLockUnavailable extends \RuntimeException
{
    public const ERROR_CODE = 'JOB_LOCK_UNAVAILABLE';

    public function __construct()
    {
        parent::__construct('Unable to lock recalculate job', 503);
    }
}
