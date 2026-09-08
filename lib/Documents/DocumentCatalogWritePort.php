<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** Trusted catalog adapter. Implementations must share the coordinator's SQL connection. */
interface DocumentCatalogWritePort
{
    /**
     * Called inside a coordinator-owned snapshot/transaction, never over HTTP.
     * Return {authority: object, offers: [{offerId, productId, name, values: object,
     * execution: {unitCount, runCount, layoutCount, deadlineType}, current: catalog state}]}.
     * Authority pins all provider/catalog/schema/enum/config semantics used to resolve inputs.
     * When $lock is true, lock the exact input/schema/catalog rows and price ranges
     * before reading, including insertion gaps. Do not trust a cache for locked reads.
     */
    public function capture(object $sitePublication, array $offerIds, bool $lock): array;

    /**
     * Write only target state for the supplied exact offer IDs. No names, arbitrary
     * properties, commits or remote calls. Throw on any write failure. The coordinator
     * re-reads all targets and inputs before committing the immutable receipt.
     */
    public function write(array $targets): void;
}
