<?php

require_once __DIR__ . '/../lib/Services/CalcServerRequestDeadline.php';
require_once __DIR__ . '/../lib/Services/BatchJobLockUnavailable.php';

use Prospektweb\Calc\Services\BatchJobLockUnavailable;
use Prospektweb\Calc\Services\CalcServerRequestDeadline;
use Prospektweb\Calc\Services\CalcServerRequestDeadlineExceeded;

$source = (string)file_get_contents(__DIR__ . '/../tools/batch_recalculate.php');
$functionStart = strpos($source, 'function acquireJobLock(');
$functionEnd = strpos($source, 'function loadJobState(', $functionStart === false ? 0 : $functionStart);
if ($functionStart === false || $functionEnd === false) {
    throw new RuntimeException('Unable to isolate the production job-lock function.');
}

// The endpoint imports these names; aliases let this isolated behavioral test
// execute the exact production function without bootstrapping Bitrix.
class_alias(CalcServerRequestDeadline::class, 'CalcServerRequestDeadline');
class_alias(CalcServerRequestDeadlineExceeded::class, 'CalcServerRequestDeadlineExceeded');
class_alias(BatchJobLockUnavailable::class, 'BatchJobLockUnavailable');

$GLOBALS['batch_job_lock_test_directory'] = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'prospektweb-batch-job-lock-'
    . bin2hex(random_bytes(8));
if (!mkdir($GLOBALS['batch_job_lock_test_directory'], 0700, true)) {
    throw new RuntimeException('Unable to create the isolated job-lock test directory.');
}

function getJobStorageDirectory(): string
{
    return (string)$GLOBALS['batch_job_lock_test_directory'];
}

eval(substr($source, $functionStart, $functionEnd - $functionStart));

/** @return int */
function streamResourceCount(): int
{
    return count(get_resources('stream'));
}

$lockPath = getJobStorageDirectory() . '/batch_recalc_job_user_17.lock';

try {
    $owner = fopen($lockPath, 'c+');
    if (!is_resource($owner) || !flock($owner, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Unable to establish the held-lock fixture.');
    }
    $resourcesWhileOwned = streamResourceCount();
    $now = 0;
    $contendedDeadline = new CalcServerRequestDeadline(3, static function () use (&$now): int {
        $current = $now;
        $now += 1000000;
        return $current;
    });
    try {
        acquireJobLock(17, $contendedDeadline);
        throw new RuntimeException('Contended lock unexpectedly crossed its deadline.');
    } catch (CalcServerRequestDeadlineExceeded $error) {
        // Expected: the nonblocking retry loop consumes the fake budget.
    }
    if (streamResourceCount() !== $resourcesWhileOwned) {
        throw new RuntimeException('Contended deadline leaked its secondary lock handle.');
    }
    flock($owner, LOCK_UN);
    fclose($owner);

    $ticks = [0, 0, 2000000];
    $tick = 0;
    $postAcquireDeadline = new CalcServerRequestDeadline(1, static function () use (&$ticks, &$tick): int {
        return $ticks[min($tick++, count($ticks) - 1)];
    });
    $resourcesBeforePostAcquire = streamResourceCount();
    try {
        acquireJobLock(17, $postAcquireDeadline);
        throw new RuntimeException('Post-acquisition checkpoint unexpectedly accepted an expired lock.');
    } catch (CalcServerRequestDeadlineExceeded $error) {
        // Expected: acquisition succeeds, its immediate checkpoint expires,
        // and the catch boundary unlocks and closes before rethrowing.
    }
    if (streamResourceCount() !== $resourcesBeforePostAcquire) {
        throw new RuntimeException('Post-acquisition deadline leaked its acquired lock handle.');
    }

    $probe = fopen($lockPath, 'c+');
    if (!is_resource($probe) || !flock($probe, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Post-acquisition deadline did not release the file lock.');
    }
    flock($probe, LOCK_UN);
    fclose($probe);
} finally {
    if (isset($owner) && is_resource($owner)) {
        @flock($owner, LOCK_UN);
        fclose($owner);
    }
    if (isset($probe) && is_resource($probe)) {
        @flock($probe, LOCK_UN);
        fclose($probe);
    }
    if (is_file($lockPath)) {
        unlink($lockPath);
    }
    rmdir(getJobStorageDirectory());
}

echo "batch_job_lock_deadline_test: OK\n";
