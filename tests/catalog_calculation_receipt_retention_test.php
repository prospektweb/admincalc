<?php

require_once dirname(__DIR__) . '/lib/Services/CatalogAdapterDefinitionService.php';
require_once dirname(__DIR__) . '/lib/Services/BatchRecalculateService.php';
require_once dirname(__DIR__) . '/lib/Services/BatchPreviewFingerprintService.php';
require_once dirname(__DIR__) . '/lib/Services/CatalogCalculationWriteService.php';

use Prospektweb\Calc\Services\CatalogAdapterDefinitionService;
use Prospektweb\Calc\Services\CatalogCalculationWriteService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$expect409 = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, $message . ' fails closed with 409');
        return;
    }
    $assert(false, $message);
};

$reflection = new ReflectionClass(CatalogCalculationWriteService::class);
$ttl = (int)$reflection->getConstant('RECEIPT_TTL_SECONDS');
$maxCount = (int)$reflection->getConstant('RECEIPT_MAX_COUNT_PER_TYPE');
$assert($ttl === 7 * 86400, 'receipt TTL is deterministic at seven days');
$assert($maxCount === 256, 'each receipt family has a deterministic 256-row cap');

$method = static function (string $name) use ($reflection): ReflectionMethod {
    $value = $reflection->getMethod($name);
    $value->setAccessible(true);
    return $value;
};
$prune = $method('pruneReceiptRows');
$save = $method('saveReceipt');
$validateReplay = $method('validateReplayReceiptUnderLocks');
$validateBatchReplay = $method('validateBatchReplayReceiptUnderLocks');
$withLock = $method('withAdapterMutationLock');
$begin = $method('beginTransaction');
$commit = $method('commitTransaction');

$writePrefix = 'CATALOG_WRITE_RECEIPT_';
$batchPrefix = 'CATALOG_BATCH_RECEIPT_';
$name = static function (string $prefix, string $seed): string {
    return $prefix . substr(hash('sha256', $seed), 0, 24);
};
$createdAt = static function (int $timestamp): string {
    return gmdate('c', $timestamp);
};
$row = static function (
    string $name,
    string $contract,
    int $timestamp,
    string $moduleId = 'prospektweb.calc',
    $siteId = null
) use ($createdAt): array {
    return [
        'moduleId' => $moduleId,
        'siteId' => $siteId,
        'name' => $name,
        'value' => ['contract' => $contract, 'createdAt' => $createdAt($timestamp)],
    ];
};
$storageService = static function (
    array &$rows,
    array &$deleted,
    int $now,
    ?array &$events = null
): CatalogCalculationWriteService {
    return new CatalogCalculationWriteService([
        'receipt_now' => static function () use ($now): int {
            return $now;
        },
        'list_receipts' => static function (string $prefix) use (&$rows, &$events): array {
            if (is_array($events)) {
                $events[] = 'list:' . $prefix;
            }
            return $rows;
        },
        'delete_receipts' => static function (array $names) use (&$rows, &$deleted, &$events): void {
            if (is_array($events)) {
                $events[] = 'delete';
            }
            $deleted = array_merge($deleted, $names);
            $rows = array_values(array_filter($rows, static function (array $row) use ($names): bool {
                return !((string)($row['moduleId'] ?? '') === 'prospektweb.calc'
                    && in_array($row['siteId'] ?? null, [null, ''], true)
                    && in_array((string)($row['name'] ?? ''), $names, true));
            }));
        },
        'save_receipt' => static function (string $name, array $receipt) use (&$rows, &$events): void {
            if (is_array($events)) {
                $events[] = 'save';
            }
            $rows[] = [
                'moduleId' => 'prospektweb.calc',
                'siteId' => null,
                'name' => $name,
                'value' => $receipt,
            ];
        },
        'adapter_mutation_lock' => static function (callable $callback) use (&$events) {
            if (is_array($events)) {
                $events[] = 'lock-enter';
            }
            try {
                return $callback();
            } finally {
                if (is_array($events)) {
                    $events[] = 'lock-exit';
                }
            }
        },
        'begin_transaction' => static function () use (&$events): void {
            if (is_array($events)) {
                $events[] = 'begin';
            }
        },
        'commit_transaction' => static function () use (&$events): void {
            if (is_array($events)) {
                $events[] = 'commit';
            }
        },
    ]);
};

$now = strtotime('2026-08-15T12:00:00+00:00');
$current = $name($writePrefix, 'current');
$expiredWrite = $name($writePrefix, 'expired-write');
$expiredBatch = $name($batchPrefix, 'expired-batch');
$fresh = $name($writePrefix, 'fresh');
$rows = [
    $row($current, CatalogCalculationWriteService::RECEIPT_CONTRACT, $now),
    $row($fresh, CatalogCalculationWriteService::RECEIPT_CONTRACT, $now - $ttl + 1),
    $row($expiredWrite, CatalogCalculationWriteService::RECEIPT_CONTRACT, $now - $ttl),
    $row($expiredBatch, CatalogCalculationWriteService::BATCH_RECEIPT_CONTRACT, $now - $ttl - 1),
    $row($expiredWrite, CatalogCalculationWriteService::RECEIPT_CONTRACT, $now - $ttl - 1, 'prospektweb.calc', 's1'),
    $row($name($writePrefix, 'other-module'), CatalogCalculationWriteService::RECEIPT_CONTRACT, $now - $ttl, 'other.module'),
    ['moduleId' => 'prospektweb.calc', 'siteId' => null, 'name' => 'CATALOG_WRITE_RECEIPT_not-exact', 'value' => '{malformed'],
];
$deleted = [];
$service = $storageService($rows, $deleted, $now);
$prune->invoke($service, $current, $now);
sort($deleted, SORT_STRING);
$expectedDeleted = [$expiredBatch, $expiredWrite];
sort($expectedDeleted, SORT_STRING);
$assert($deleted === $expectedDeleted, 'TTL deletes only exact global rows in the two receipt families');
$assert(count(array_filter($rows, static function (array $candidate) use ($expiredWrite): bool {
    return ($candidate['name'] ?? '') === $expiredWrite && ($candidate['siteId'] ?? null) === 's1';
})) === 1, 'site-scoped receipt with the same name is untouched');
$assert(count(array_filter($rows, static function (array $candidate) use ($current): bool {
    return ($candidate['name'] ?? '') === $current;
})) === 1, 'the current receipt is never deleted');
$assert(count(array_filter($rows, static function (array $candidate) use ($fresh): bool {
    return ($candidate['name'] ?? '') === $fresh;
})) === 1, 'a receipt one second inside the TTL is retained');

$rows = [];
$current = $name($writePrefix, 'max-current');
for ($index = 0; $index <= $maxCount; $index++) {
    $candidateName = $index === 0 ? $current : $name($writePrefix, 'max-' . $index);
    $rows[] = $row(
        $candidateName,
        CatalogCalculationWriteService::RECEIPT_CONTRACT,
        $now - $index
    );
}
$oldest = $name($writePrefix, 'max-' . $maxCount);
$deleted = [];
$service = $storageService($rows, $deleted, $now);
$prune->invoke($service, $current, $now);
$globalWrites = array_values(array_filter($rows, static function (array $candidate) use ($writePrefix): bool {
    return strpos((string)($candidate['name'] ?? ''), $writePrefix) === 0
        && ($candidate['moduleId'] ?? '') === 'prospektweb.calc'
        && ($candidate['siteId'] ?? null) === null;
}));
$assert(count($globalWrites) === $maxCount, 'max-count pruning leaves exactly the configured bound');
$assert($deleted === [$oldest], 'max-count pruning deterministically deletes the oldest receipt');
$assert(count(array_filter($globalWrites, static function (array $candidate) use ($current): bool {
    return ($candidate['name'] ?? '') === $current;
})) === 1, 'the new current receipt survives max-count pruning');

$malformed = $name($writePrefix, 'malformed');
$expired = $name($writePrefix, 'would-delete');
$current = $name($writePrefix, 'malformed-current');
$rows = [
    $row($current, CatalogCalculationWriteService::RECEIPT_CONTRACT, $now),
    $row($expired, CatalogCalculationWriteService::RECEIPT_CONTRACT, $now - $ttl - 1),
    ['moduleId' => 'prospektweb.calc', 'siteId' => null, 'name' => $malformed, 'value' => '{broken'],
];
$deleted = [];
$service = $storageService($rows, $deleted, $now);
$expect409(static function () use ($prune, $service, $current, $now): void {
    $prune->invoke($service, $current, $now);
}, 'malformed exact-scope JSON aborts retention');
$assert($deleted === [] && count($rows) === 3, 'malformed retention plans delete nothing conservatively');
$rows[2]['value'] = [
    'contract' => CatalogCalculationWriteService::RECEIPT_CONTRACT,
    'createdAt' => '2026-02-30T00:00:00+00:00',
];
$expect409(static function () use ($prune, $service, $current, $now): void {
    $prune->invoke($service, $current, $now);
}, 'invalid createdAt aborts retention');
$assert($deleted === [], 'invalid timestamp cannot trigger a partial cleanup');

$rows = [];
$deleted = [];
$events = [];
$service = $storageService($rows, $deleted, $now, $events);
$current = $name($writePrefix, 'trusted-time');
$withLock->invoke($service, static function () use (
    $begin,
    $commit,
    $save,
    $service,
    $current
): void {
    $begin->invoke($service);
    $save->invoke($service, $current, [
        'contract' => CatalogCalculationWriteService::RECEIPT_CONTRACT,
        'createdAt' => '2000-01-01T00:00:00+00:00',
    ]);
    $commit->invoke($service);
});
$assert(($rows[0]['value']['createdAt'] ?? '') === $createdAt($now), 'save replaces caller time with a trusted server createdAt');
$assert($events === [
    'lock-enter',
    'begin',
    'save',
    'list:CATALOG_WRITE_RECEIPT_',
    'list:CATALOG_BATCH_RECEIPT_',
    'commit',
    'lock-exit',
], 'retention executes inside the serialized mutation lock and catalog transaction');

$resolverCalls = 0;
$writeCalls = 0;
$service = new CatalogCalculationWriteService([
    'receipt_now' => static function () use ($now): int {
        return $now;
    },
    'resolve_runtime_pinned' => static function () use (&$resolverCalls): array {
        $resolverCalls++;
        throw new RuntimeException('expired replay reached the resolver');
    },
    'write_projected' => static function () use (&$writeCalls): array {
        $writeCalls++;
        return [];
    },
]);
$fingerprint = str_repeat('a', 64);
$expiredReceipt = [
    'contract' => CatalogCalculationWriteService::RECEIPT_CONTRACT,
    'actorUserId' => 1,
    'presetId' => CatalogAdapterDefinitionService::PRESET_ID,
    'siteId' => 's1',
    'offerIds' => [15320],
    'productIds' => [12727],
    'expectedFingerprint' => $fingerprint,
    'createdAt' => $createdAt($now - $ttl),
];
$expect409(static function () use ($validateReplay, $service, $expiredReceipt, $fingerprint): void {
    $validateReplay->invoke($service, $expiredReceipt, 1, [15320], 's1', $fingerprint);
}, 'expired single-write replay');

$approvedStates = [15320 => ['calculation' => str_repeat('b', 64), 'catalog' => str_repeat('c', 64)]];
$approvedResults = [15320 => str_repeat('d', 64)];
$requestId = str_repeat('e', 64);
$expiredBatchReceipt = [
    'contract' => CatalogCalculationWriteService::BATCH_RECEIPT_CONTRACT,
    'actorUserId' => 1,
    'presetId' => CatalogAdapterDefinitionService::PRESET_ID,
    'siteId' => 's1',
    'offerIds' => [15320],
    'productIds' => [12727],
    'requestId' => $requestId,
    'approvedStateFingerprint' => hash('sha256', CatalogCalculationWriteService::canonicalEncode($approvedStates)),
    'approvedResultFingerprint' => hash('sha256', CatalogCalculationWriteService::canonicalEncode($approvedResults)),
    'createdAt' => $createdAt($now - $ttl - 1),
];
$expect409(static function () use (
    $validateBatchReplay,
    $service,
    $expiredBatchReceipt,
    $requestId,
    $approvedStates,
    $approvedResults
): void {
    $validateBatchReplay->invoke(
        $service,
        $expiredBatchReceipt,
        1,
        $requestId,
        [15320],
        's1',
        $approvedStates,
        $approvedResults
    );
}, 'expired batch-write replay');
$assert($resolverCalls === 0 && $writeCalls === 0, 'expired replay fails before any resolver or catalog writer call');

$source = file_get_contents(dirname(__DIR__) . '/lib/Services/CatalogCalculationWriteService.php');
$assert(is_string($source), 'receipt writer source is readable');
$assert(strpos($source, "NAME REGEXP '") !== false
    && strpos($source, 'RECEIPT_MAX_COUNT_PER_TYPE + 2') !== false
    && strpos($source, "ORDER BY MODULE_ID, NAME, SITE_ID LIMIT ") !== false
    && strpos($source, ' FOR UPDATE') !== false,
    'production retention reads are exact-prefix, row-locked and hard-capped');
$assert(strpos($source, "DELETE FROM b_option WHERE MODULE_ID='") !== false
    && strpos($source, "AND (SITE_ID IS NULL OR SITE_ID='') AND NAME IN (") !== false,
    'production retention deletes only exact global module rows');
$saveStart = strpos($source, 'private function saveReceipt');
$saveEnd = strpos($source, 'private function assertReceiptFresh', $saveStart ?: 0);
$saveSource = $saveStart !== false && $saveEnd !== false
    ? substr($source, $saveStart, $saveEnd - $saveStart)
    : '';
$assert($saveSource !== '' && strpos($saveSource, 'pruneReceiptRows(') !== false,
    'every durable receipt save invokes bounded retention before its caller commits');

echo "Catalog calculation receipt retention tests passed\n";
