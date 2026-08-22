<?php

require_once dirname(__DIR__) . '/lib/Services/BatchRecalculateService.php';
require_once dirname(__DIR__) . '/lib/Services/BatchPreviewFingerprintService.php';
require_once dirname(__DIR__) . '/lib/Services/CatalogCalculationWriteService.php';

use Prospektweb\Calc\Services\CatalogCalculationWriteService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$source = file_get_contents(dirname(__DIR__) . '/lib/Services/CatalogCalculationWriteService.php');
$assert(is_string($source), 'Catalog calculation write source is readable');
$assert(strpos($source, "public const AUDIT_CONTRACT = 'prospektweb.calc.catalog-write-audit/v1'") !== false,
    'Catalog writes expose a stable audit contract');
$assert(strpos($source, "private const AUDIT_TYPE_ID = 'PROSPEKTWEB_CATALOG_CALCULATION_WRITE'") !== false,
    'Catalog writes expose a stable Bitrix event type');
$assert(strpos($source, 'CEventLog::Add') !== false && strpos($source, "'SEVERITY' => 'SECURITY'") !== false,
    'Production catalog writes use the durable Bitrix event log');
$assert(strpos($source, '$this->transactionConnection === null') !== false,
    'Production audit refuses to run outside the catalog transaction');

$singleStart = strpos($source, 'private function applyUnderLocks(');
$singleEnd = strpos($source, 'private function buildPreview(', $singleStart ?: 0);
$single = $singleStart !== false && $singleEnd !== false
    ? substr($source, $singleStart, $singleEnd - $singleStart)
    : '';
$singleReceipt = strpos($single, '$this->saveReceiptWithAudit(');
$singleCommit = strpos($single, '$this->commitTransaction()', $singleReceipt ?: 0);
$singleRollback = strpos($single, '$this->rollbackTransaction()', $singleReceipt ?: 0);
$assert($singleReceipt !== false && $singleCommit !== false && $singleReceipt < $singleCommit,
    'Single catalog audit and receipt are written before commit');
$assert($singleRollback !== false,
    'Single catalog audit failure reaches the transaction rollback path');

$batchStart = strpos($source, 'public function applyAuthoritativeBatch(');
$batchEnd = strpos($source, 'public function replayAuthoritativeBatch(', $batchStart ?: 0);
$batch = $batchStart !== false && $batchEnd !== false
    ? substr($source, $batchStart, $batchEnd - $batchStart)
    : '';
$assert(substr_count($batch, '$this->saveBatchReceipt(') === 2,
    'Every new batch outcome has one completion receipt path');
$assert(substr_count($batch, '$this->buildCatalogWriteAudit(') === 2,
    'Every new batch outcome carries a durable audit payload');
$assert(strpos($batch, '$this->rollbackTransaction()') !== false,
    'Batch catalog audit failure reaches the transaction rollback path');

$reflection = new ReflectionClass(CatalogCalculationWriteService::class);
$saveWithAudit = $reflection->getMethod('saveReceiptWithAudit');
$saveWithAudit->setAccessible(true);
$receiptName = 'CATALOG_WRITE_RECEIPT_' . str_repeat('a', 24);
$audit = [
    'contract' => CatalogCalculationWriteService::AUDIT_CONTRACT,
    'actorUserId' => 7,
    'action' => 'apply',
    'requestId' => str_repeat('b', 64),
    'presetId' => 12740,
    'siteId' => 's1',
    'offerIds' => [15320],
    'productIds' => [12727],
    'expectedFingerprint' => str_repeat('c', 64),
    'beforeFingerprint' => str_repeat('d', 64),
    'afterFingerprint' => str_repeat('e', 64),
    'resultFingerprint' => str_repeat('f', 64),
    'result' => 'success',
];
$receipt = ['contract' => CatalogCalculationWriteService::RECEIPT_CONTRACT];

$events = [];
$service = new CatalogCalculationWriteService([
    'write_audit' => static function (array $payload) use (&$events, $audit): bool {
        $events[] = 'audit';
        return $payload === $audit;
    },
    'save_receipt' => static function () use (&$events): void {
        $events[] = 'receipt';
    },
]);
$saveWithAudit->invoke($service, $receiptName, $receipt, $audit);
$assert($events === ['audit', 'receipt'], 'Durable audit is written exactly once before its receipt');

$receiptWrites = 0;
$service = new CatalogCalculationWriteService([
    'write_audit' => static function (): bool {
        return false;
    },
    'save_receipt' => static function () use (&$receiptWrites): void {
        $receiptWrites++;
    },
]);
$failed = false;
try {
    $saveWithAudit->invoke($service, $receiptName, $receipt, $audit);
} catch (Throwable $error) {
    $failed = strpos($error->getMessage(), 'audit write failed') !== false;
}
$assert($failed, 'A failed durable audit aborts the completion path');
$assert($receiptWrites === 0, 'A failed durable audit cannot create a replay receipt');

echo "Catalog calculation write audit tests passed\n";
