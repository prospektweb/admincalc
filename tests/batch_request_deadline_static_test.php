<?php

$endpoint = (string)file_get_contents(__DIR__ . '/../tools/batch_recalculate.php');
$service = (string)file_get_contents(__DIR__ . '/../lib/Services/BatchRecalculateService.php');

$requiredEndpointFragments = [
    '$calcServerRequestStartedAtNanoseconds = hrtime(true);',
    'CalcServerRequestDeadline::MAX_BUDGET_MILLISECONDS,',
    '$calcServerRequestStartedAtNanoseconds',
    'new BatchRecalculateService($calcServerUrl, $timeout, null, $requestDeadline)',
    '$requestDeadline->assertAvailable();',
    'function acquireJobLock(int $userId, CalcServerRequestDeadline $requestDeadline)',
    'LOCK_EX | LOCK_NB',
    'flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)',
    'if ($wouldBlock !== 1)',
    'throw new BatchJobLockUnavailable();',
    'catch (BatchJobLockUnavailable $error)',
    '$remainingMilliseconds = $requestDeadline->remainingMilliseconds();',
    '$jobLock = acquireJobLock($userId, $requestDeadline);',
    'while waiting for its job lock',
    '$job[\'queue\'] = array_merge($batchItems, $job[\'queue\']);',
    'respondJson(504, [',
    'CalcServerRequestDeadlineExceeded::ERROR_CODE',
    "min(400, (int)Option::get(\$moduleId, 'BATCH_RECALC_MAX_OFFERS'",
];
foreach ($requiredEndpointFragments as $fragment) {
    if (strpos($endpoint, $fragment) === false) {
        throw new RuntimeException('Missing batch endpoint deadline guard: ' . $fragment);
    }
}
if (strpos($endpoint, '$calcServerRequestStartedAtNanoseconds = hrtime(true);')
    >= strpos($endpoint, 'prolog_admin_before.php')) {
    throw new RuntimeException('The Admin monotonic request budget starts after Bitrix bootstrap.');
}
$lockFunctionStart = strpos($endpoint, 'function acquireJobLock(');
$lockFunctionEnd = strpos($endpoint, 'function loadJobState(', $lockFunctionStart);
if ($lockFunctionStart === false || $lockFunctionEnd === false) {
    throw new RuntimeException('Unable to isolate the batch job lock boundary.');
}
$lockFunction = substr($endpoint, $lockFunctionStart, $lockFunctionEnd - $lockFunctionStart);
if (preg_match('/flock\s*\([^;]+,\s*LOCK_EX\s*\)/', $lockFunction) === 1) {
    throw new RuntimeException('A blocking batch job flock remains active.');
}
$nonBlockingAcquire = strpos($lockFunction, 'flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)');
$postAcquireCheckpoint = strpos(
    $lockFunction,
    '$requestDeadline->assertAvailable();',
    $nonBlockingAcquire === false ? 0 : $nonBlockingAcquire
);
$firstPostAcquireSideEffect = strpos($lockFunction, '@chmod(', $nonBlockingAcquire === false ? 0 : $nonBlockingAcquire);
if ($nonBlockingAcquire === false
    || $postAcquireCheckpoint === false
    || $firstPostAcquireSideEffect === false
    || $postAcquireCheckpoint >= $firstPostAcquireSideEffect) {
    throw new RuntimeException('The batch lock lacks an immediate post-acquisition deadline checkpoint.');
}
if (preg_match(
    '/if \(flock\(\$handle, LOCK_EX \| LOCK_NB, \$wouldBlock\)\) \{\s*(?:(?:\/\/)[^\r\n]*\R\s*)*\$requestDeadline->assertAvailable\(\);/',
    $lockFunction
) !== 1) {
    throw new RuntimeException('Work exists between batch lock acquisition and its deadline checkpoint.');
}
foreach (['@flock($handle, LOCK_UN);', 'fclose($handle);', 'throw $error;'] as $cleanupFragment) {
    if (strpos($lockFunction, $cleanupFragment) === false) {
        throw new RuntimeException('The deadline-aware batch lock lacks cleanup: ' . $cleanupFragment);
    }
}
$realFailureGuard = strpos($lockFunction, 'if ($wouldBlock !== 1)');
$realFailureThrow = strpos(
    $lockFunction,
    'throw new BatchJobLockUnavailable();',
    $realFailureGuard === false ? 0 : $realFailureGuard
);
if ($realFailureGuard === false || $realFailureThrow === false) {
    throw new RuntimeException('A real flock failure does not fail closed with the typed 503 exception.');
}
if (strpos($lockFunction, 'return null;') !== false) {
    throw new RuntimeException('The batch lock can return to action dispatch without authority.');
}
preg_match_all('/new BatchRecalculateService\((.*?)\);/s', $endpoint, $serviceConstructions);
if (empty($serviceConstructions[1])) {
    throw new RuntimeException('No Admin batch service construction was found.');
}
foreach ($serviceConstructions[1] as $arguments) {
    if (strpos($arguments, '$requestDeadline') === false) {
        throw new RuntimeException('An Admin batch service construction bypasses the shared request deadline.');
    }
}

$requiredServiceFragments = [
    '$this->requestDeadline->assertAvailable();',
    '$this->requestDeadline->capTimeoutMilliseconds($requestedTimeoutMilliseconds)',
    'CURLOPT_TIMEOUT_MS',
    'CURLOPT_CONNECTTIMEOUT_MS',
    'catch (CalcServerRequestDeadlineExceeded $e)',
];
foreach ($requiredServiceFragments as $fragment) {
    if (strpos($service, $fragment) === false) {
        throw new RuntimeException('Missing batch service deadline guard: ' . $fragment);
    }
}

if (strpos($service, 'CURLOPT_TIMEOUT, $this->timeout') !== false) {
    throw new RuntimeException('Uncapped second-resolution calc-server timeout remains active.');
}

echo "batch_request_deadline_static_test: OK\n";
