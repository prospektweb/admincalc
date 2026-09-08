<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/DocumentRepository.php';
require_once __DIR__ . '/DocumentCatalogWritePort.php';
require_once __DIR__ . '/DocumentCatalogWritePlan.php';
require_once __DIR__ . '/DocumentQuotePricing.php';

/** Native preview -> fresh server calculation -> locked CAS -> verified write/receipt. */
final class DocumentCatalogWriteService
{
    public const PREVIEW = 'prospektweb.calculator/catalog-write-preview-v1';
    public const RECEIPT = 'prospektweb.calculator/catalog-write-receipt-v1';
    private SqlConnection $db;
    private DocumentRepository $repository;
    private DocumentCatalogWritePort $catalog;
    private $core;
    private string $scope;
    private string $actor;
    private string $provider;

    public function __construct(SqlConnection $db, string $scope, string $actor, string $provider, DocumentCatalogWritePort $catalog, callable $core)
    {
        if (!preg_match('/^site:[A-Za-z0-9]{1,2}$/D', $scope) || !preg_match('/^user:[1-9][0-9]{0,8}$/D', $actor)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $provider)) { throw new \InvalidArgumentException('Trusted site, actor and provider are required.'); }
        $this->db = $db; $this->scope = $scope; $this->actor = $actor; $this->provider = $provider;
        $this->repository = new DocumentRepository($db, $scope, $actor); $this->catalog = $catalog; $this->core = $core;
    }

    /** HTTP adapters may forward only these fields, never client results/inputs or actor identity. */
    public function command(array $request): array
    {
        $action = $request['action'] ?? null;
        $keys = array_keys($request); sort($keys);
        $expected = $action === 'applyCatalogWrite' ? ['action', 'expectedFingerprint', 'id', 'offerIds', 'publicationId']
            : ['action', 'id', 'offerIds', 'publicationId'];
        if (!in_array($action, ['previewCatalogWrite', 'applyCatalogWrite'], true) || $keys !== $expected
            || !is_string($request['id']) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $request['id'])
            || !is_string($request['publicationId']) || !preg_match('/^s_[a-f0-9]{64}$/D', $request['publicationId'])) {
            throw new \InvalidArgumentException('Invalid native catalog write command.');
        }
        $ids = $request['offerIds'];
        if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids) > 100) { throw new \InvalidArgumentException('Select between 1 and 100 offers.'); }
        foreach ($ids as $id) if (!is_int($id) || $id <= 0 || $id > 999999999) { throw new \InvalidArgumentException('Invalid offer identity.'); }
        if (count(array_unique($ids)) !== count($ids)) { throw new \InvalidArgumentException('Duplicate offer identity.'); }
        sort($ids, SORT_NUMERIC);
        $id = $request['id']; $publication = $request['publicationId'];
        $fingerprint = $request['expectedFingerprint'] ?? null;
        if ($action === 'applyCatalogWrite' && (!is_string($fingerprint) || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint))) { throw new \InvalidArgumentException('Preview fingerprint is required.'); }
        // Replaying the exact confirmed operation must not run the remote calculation again.
        if ($fingerprint !== null) {
            $receipt = $this->transaction(function () use ($id, $publication, $ids, $fingerprint): ?array {
                $this->repository->lockSitePublication($id, $publication);
                $receipt = $this->receipt($id, $publication, $ids, $fingerprint);
                if ($receipt !== null) $this->assertReceiptCurrent($receipt, $this->capture($id, $publication, $ids, true));
                return $receipt;
            });
            if ($receipt !== null) return $receipt;
        }
        $before = $this->transaction(fn(): array => $this->capture($id, $publication, $ids, false), true);
        $plan = $this->calculate($before);
        if ($fingerprint !== null && !hash_equals($fingerprint, $plan['public']['fingerprint'])) {
            // A simultaneous request may have committed this exact operation after
            // our first receipt read. Only its verified receipt can make this a replay.
            $receipt = $this->transaction(function () use ($id, $publication, $ids, $fingerprint): ?array {
                $this->repository->lockSitePublication($id, $publication);
                $receipt = $this->receipt($id, $publication, $ids, $fingerprint);
                if ($receipt !== null) $this->assertReceiptCurrent($receipt, $this->capture($id, $publication, $ids, true));
                return $receipt;
            });
            if ($receipt !== null) return $receipt;
            throw new DocumentConflict('Каталог, публикация или результат расчёта изменились после предпросмотра.');
        }
        if ($action === 'previewCatalogWrite') {
            $after = $this->transaction(fn(): array => $this->capture($id, $publication, $ids, false), true);
            $this->same($before, $after, 'Исходные данные изменились во время предпросмотра.');
            return $plan['public'];
        }
        return $this->transaction(function () use ($id, $publication, $ids, $fingerprint, $before, $plan): array {
            $this->repository->lockSitePublication($id, $publication);
            $current = $this->capture($id, $publication, $ids, true);
            $receipt = $this->receipt($id, $publication, $ids, $fingerprint);
            if ($receipt !== null) { $this->assertReceiptCurrent($receipt, $current); return $receipt; }
            $this->same($before, $current, 'Каталог изменился перед записью.');
            $writes = [];
            foreach ($plan['targets'] as $index => $target) {
                if ($plan['public']['offers'][$index]['changed']) $writes[] = ['offerId' => $ids[$index], 'priceTypeIds' => $plan['priceTypeIds'][$index], 'state' => $target];
            }
            if ($writes) $this->catalog->write($writes);
            // Fresh readback covers unowned price types and input mutations by event handlers too.
            $after = $this->capture($id, $publication, $ids, true);
            $expected = $current;
            foreach ($expected['catalog']['offers'] as $index => &$offer) $offer['current'] = $plan['targets'][$index];
            unset($offer);
            $this->same($expected, $after, 'Контрольное чтение не совпало с подтверждённой записью. Изменения отменены.');
            $receipt = ['contract' => self::RECEIPT, 'documentId' => $id, 'publicationId' => $publication,
                'scope' => $this->scope, 'actor' => $this->actor, 'offerIds' => $ids, 'fingerprint' => $fingerprint,
                'applied' => true, 'catalogFingerprintAfter' => DocumentCatalogWritePlan::hash($after),
                'summary' => ['total' => count($ids), 'updated' => count($writes)],
                'provenance' => ['snapshotHash' => $current['snapshotHash'], 'sourceRevision' => $current['snapshot']->sourceRevision,
                    'catalogAuthorityHash' => DocumentCatalogWritePlan::hash($current['catalog']['authority']),
                    'inputs' => array_map(static function (array $offer): array { unset($offer['current']); return $offer; }, $current['catalog']['offers']),
                    'resultHashes' => $plan['resultHashes']],
                'offers' => $plan['public']['offers'], 'createdAt' => gmdate('Y-m-d\TH:i:s\Z')];
            $json = DocumentCatalogWritePlan::canonical($receipt);
            $this->db->execute('INSERT INTO b_pw_calc_catalog_write (id, scope_id, document_id, publication_id, actor_id, fingerprint, receipt_json, receipt_hash, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$this->receiptId($id, $publication, $ids, $fingerprint), $this->scope, $id, $publication, $this->actor, $fingerprint, $json, hash('sha256', $json), $receipt['createdAt']]);
            return $receipt;
        });
    }

    private function capture(string $id, string $publication, array $ids, bool $lock): array
    {
        $stored = $this->repository->sitePublication($id);
        if ($stored['id'] !== $publication) throw new DocumentConflict('Публикация калькулятора изменилась.');
        $snapshot = json_decode($stored['snapshotJson'], false, 64, JSON_THROW_ON_ERROR);
        if (($snapshot->contract ?? null) !== 'prospektweb.calculator/site-publication-v1'
            || ($snapshot->documentId ?? null) !== $id || ($snapshot->sourceRevision ?? null) !== $stored['sourceRevision']
            || ($snapshot->core->calculatorId ?? null) !== $id || ($snapshot->connection->provider ?? null) !== $this->provider) {
            throw new \InvalidArgumentException('Invalid site publication authority.');
        }
        DocumentOutputMappings::validate($snapshot->connection->outputMappings ?? null);
        if (count($snapshot->connection->outputMappings) !== 7) { throw new \InvalidArgumentException('Выходные сопоставления не настроены.'); }
        $catalog = $this->catalog->capture($snapshot, $ids, $lock);
        $keys = array_keys($catalog); sort($keys);
        if ($keys !== ['authority', 'offers'] || !$catalog['authority'] instanceof \stdClass
            || !is_array($catalog['offers']) || !array_is_list($catalog['offers']) || count($catalog['offers']) !== count($ids)) {
            throw new \RuntimeException('Catalog adapter returned an invalid snapshot.');
        }
        foreach (['provider', 'productsCatalog', 'offersCatalog'] as $key) {
            if (($catalog['authority']->$key ?? null) !== ($snapshot->connection->$key ?? null)) throw new DocumentConflict('Catalog authority differs from the publication.');
        }
        usort($catalog['offers'], static fn(array $a, array $b): int => ($a['offerId'] ?? 0) <=> ($b['offerId'] ?? 0));
        foreach ($catalog['offers'] as $index => &$offer) {
            $keys = array_keys($offer); sort($keys);
            if ($keys !== ['current', 'execution', 'name', 'offerId', 'productId', 'values'] || $offer['offerId'] !== $ids[$index]
                || !is_int($offer['productId']) || $offer['productId'] <= 0 || $offer['productId'] > 999999999
                || !is_string($offer['name']) || trim($offer['name']) === '' || strlen($offer['name']) > 500 || preg_match('/[\x00-\x1F\x7F]/', $offer['name'])
                || !$offer['values'] instanceof \stdClass || !is_array($offer['execution']) || !is_array($offer['current'])) {
                throw new \RuntimeException('Invalid catalog target or calculation input.');
            }
            $binding = $this->repository->productBinding($this->provider, $snapshot->connection->productsCatalog, (string)$offer['productId']);
            $products = array_filter($snapshot->connection->products, static fn($product): bool => $product->key === (string)$offer['productId']);
            $product = $products ? array_values($products)[0] : null;
            if ($binding === null || $binding['document_id'] !== $id || $binding['publication_id'] !== $publication
                || $product === null || $binding['presentation_id'] !== $product->presentationId) { throw new DocumentConflict('ТП связано с другим товаром или публикацией.'); }
            $keys = array_keys($offer['execution']); sort($keys);
            if ($keys !== ['deadlineType', 'layoutCount', 'runCount', 'unitCount'] || !in_array($offer['execution']['deadlineType'], ['strict', 'urgent', 'flexible'], true)) throw new \InvalidArgumentException('Invalid catalog execution context.');
            foreach (['layoutCount', 'runCount', 'unitCount'] as $key) if (!is_int($offer['execution'][$key]) || $offer['execution'][$key] < 1 || $offer['execution'][$key] > 1000000000) throw new \InvalidArgumentException('Invalid catalog execution count.');
            $offer['current'] = DocumentCatalogWritePlan::state($offer['current']);
        }
        unset($offer);
        $result = ['scope' => $this->scope, 'actor' => $this->actor, 'publicationId' => $publication,
            'snapshotHash' => $stored['snapshotHash'], 'snapshot' => $snapshot, 'catalog' => $catalog];
        if (strlen(DocumentCatalogWritePlan::canonical($result)) > 16000000) throw new \InvalidArgumentException('Catalog snapshot exceeds size limit.');
        return $result;
    }

    private function calculate(array $capture): array
    {
        if ($this->db->inTransaction()) throw new \LogicException('Remote calculation must run outside a SQL transaction.');
        $snapshot = $capture['snapshot']; $offers = []; $targets = []; $results = []; $priceTypeIds = [];
        $changedOffers = 0; $changedFields = 0;
        $quotes = [];
        foreach (array_chunk($capture['catalog']['offers'], 20) as $batch) {
            $response = ($this->core)(['action' => 'executeBatch', 'publication' => $snapshot->core,
                'executions' => array_map(static fn(array $offer): array => ['id' => $offer['offerId'],
                    'values' => $offer['values'], 'execution' => $offer['execution'], 'name' => $offer['name']], $batch)]);
            if (!is_array($response['results'] ?? null) || !array_is_list($response['results']) || count($response['results']) !== count($batch)) throw new \RuntimeException('Core quote batch is incomplete.');
            foreach ($response['results'] as $index => $row) {
                if (!is_array($row) || ($row['id'] ?? null) !== $batch[$index]['offerId'] || !is_array($row['result'] ?? null)) throw new \RuntimeException('Core quote batch authority mismatch.');
                $quotes[$row['id']] = $row['result'];
            }
        }
        foreach ($capture['catalog']['offers'] as $offer) {
            $quote = $quotes[$offer['offerId']];
            $document = $snapshot->core->plan->document ?? null;
            if (!$document instanceof \stdClass) throw new \InvalidArgumentException('Отсутствует опубликованный источник правил цен.');
            $priceConnection = DocumentQuotePricing::connection($document, $quote, $snapshot->connection);
            $types = array_map(static fn($binding): int => (int)$binding->key, $priceConnection->priceTypes); sort($types, SORT_NUMERIC);
            $priceTypeIds[] = $types;
            $target = DocumentCatalogWritePlan::target($quote, $priceConnection, $offer['current']);
            $targets[] = $target; $results[] = ['offerId' => $offer['offerId'], 'hash' => DocumentCatalogWritePlan::hash($quote)];
            $diff = DocumentCatalogWritePlan::diffs($offer['current'], $target);
            $count = count(array_filter($diff, static fn(array $row): bool => $row['changed']));
            $changedFields += $count; if ($count > 0) $changedOffers++;
            $offers[] = ['offerId' => $offer['offerId'], 'name' => $offer['name'], 'changed' => $count > 0, 'changedFields' => $count, 'diff' => $diff];
        }
        return ['targets' => $targets, 'priceTypeIds' => $priceTypeIds, 'resultHashes' => $results, 'public' => ['contract' => self::PREVIEW, 'ready' => true,
            'documentId' => $snapshot->documentId, 'publicationId' => $capture['publicationId'],
            'offerIds' => array_column($offers, 'offerId'), 'fingerprint' => DocumentCatalogWritePlan::hash([$capture, $targets, $priceTypeIds, $results]),
            'summary' => ['total' => count($offers), 'changedOffers' => $changedOffers, 'unchangedOffers' => count($offers) - $changedOffers, 'changedFields' => $changedFields], 'offers' => $offers]];
    }

    private function receiptId(string $id, string $publication, array $ids, string $fingerprint): string
    {
        return 'w_' . DocumentCatalogWritePlan::hash([$this->scope, $this->actor, $id, $publication, $ids, $fingerprint]);
    }
    private function receipt(string $id, string $publication, array $ids, string $fingerprint): ?array
    {
        $rows = $this->db->rows('SELECT receipt_json, receipt_hash FROM b_pw_calc_catalog_write WHERE id = ? AND scope_id = ? AND document_id = ? AND publication_id = ? AND actor_id = ? AND fingerprint = ?',
            [$this->receiptId($id, $publication, $ids, $fingerprint), $this->scope, $id, $publication, $this->actor, $fingerprint]);
        if (!$rows) return null;
        if (!hash_equals($rows[0]['receipt_hash'], hash('sha256', $rows[0]['receipt_json']))) throw new \RuntimeException('Catalog receipt integrity failed.');
        $receipt = json_decode($rows[0]['receipt_json'], true, 64, JSON_THROW_ON_ERROR);
        if (($receipt['contract'] ?? null) !== self::RECEIPT || ($receipt['documentId'] ?? null) !== $id
            || ($receipt['publicationId'] ?? null) !== $publication || ($receipt['scope'] ?? null) !== $this->scope || ($receipt['actor'] ?? null) !== $this->actor
            || ($receipt['offerIds'] ?? null) !== $ids || ($receipt['fingerprint'] ?? null) !== $fingerprint || ($receipt['applied'] ?? null) !== true
            || !is_string($receipt['catalogFingerprintAfter'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $receipt['catalogFingerprintAfter'])) throw new \RuntimeException('Catalog receipt authority failed.');
        return $receipt;
    }
    private function assertReceiptCurrent(array $receipt, array $capture): void
    {
        if (!hash_equals($receipt['catalogFingerprintAfter'], DocumentCatalogWritePlan::hash($capture))) throw new DocumentConflict('После записи изменились каталог или исходные данные. Нужен новый предпросмотр.');
    }
    private function same(array $a, array $b, string $message): void
    {
        if (!hash_equals(DocumentCatalogWritePlan::hash($a), DocumentCatalogWritePlan::hash($b))) throw new DocumentConflict($message);
    }
    private function transaction(callable $work, bool $read = false)
    {
        if ($this->db->inTransaction()) throw new \LogicException('Catalog write must own its transaction.');
        $this->db->begin($read);
        try { $result = $work(); $this->db->commit(); return $result; }
        catch (\Throwable $error) { $this->db->rollback(); throw $error; }
    }
}
