<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/DocumentRepository.php';

/** Read-only selector, never write authority: preview/apply recapture every target. */
final class BitrixDocumentCatalogTargets
{
    public function __construct(private SqlConnection $db, private string $scope, private string $actor, private string $provider)
    {
        if (!preg_match('/^site:[A-Za-z0-9]{1,2}$/D', $scope) || !preg_match('/^user:[1-9][0-9]{0,8}$/D', $actor)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $provider)) throw new \InvalidArgumentException('Trusted catalog scope required.');
    }

    public function command(array $request): array
    {
        $keys = array_keys($request); sort($keys);
        if ($keys !== ['action','afterId','id','productId','publicationId'] || $request['action'] !== 'catalogWriteTargets'
            || !is_string($request['id']) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $request['id'])
            || !is_string($request['publicationId']) || !preg_match('/^s_[a-f0-9]{64}$/D', $request['publicationId'])
            || !is_int($request['productId']) || $request['productId'] < 1 || $request['productId'] > 999999999
            || !is_int($request['afterId']) || $request['afterId'] < 0 || $request['afterId'] > 999999999) throw new \InvalidArgumentException('Invalid catalog selector command.');
        if ($this->db->inTransaction()) throw new \LogicException('Catalog selector must own its read snapshot.');
        $this->db->begin(true);
        try {
            $result = $this->read($request);
            $this->db->commit(); return $result;
        } catch (\Throwable $error) { $this->db->rollback(); throw $error; }
    }

    private function read(array $request): array
    {
        $repo = new DocumentRepository($this->db, $this->scope, $this->actor);
        $publication = $repo->sitePublication($request['id']);
        if ($publication['id'] !== $request['publicationId']) throw new DocumentConflict('Публикация калькулятора изменилась.');
        $snapshot = json_decode($publication['snapshotJson'], false, 64, JSON_THROW_ON_ERROR);
        $connection = $snapshot->connection ?? null;
        if (($snapshot->contract ?? null) !== 'prospektweb.calculator/site-publication-v1' || ($snapshot->documentId ?? null) !== $request['id']
            || ($connection->provider ?? null) !== $this->provider) throw new DocumentConflict('Подключение публикации изменилось.');
        $products = self::id($connection->productsCatalog ?? null); $offers = self::id($connection->offersCatalog ?? null);
        if ($products === $offers) throw new DocumentConflict('Каталоги товара и ТП совпадают.');
        $matches = array_values(array_filter($connection->products, static fn($row): bool => $row->key === (string)$request['productId']));
        $binding = $repo->productBinding($this->provider, (string)$products, (string)$request['productId']);
        if (count($matches) !== 1 || !$binding || $binding['document_id'] !== $request['id'] || $binding['publication_id'] !== $publication['id']
            || $binding['presentation_id'] !== $matches[0]->presentationId) throw new DocumentConflict('Товар не привязан к этой публикации.');
        $product = $this->db->rows("SELECT ID,NAME FROM b_iblock_element WHERE ID=? AND IBLOCK_ID=? AND ACTIVE='Y' AND (ACTIVE_FROM IS NULL OR ACTIVE_FROM<=CURRENT_TIMESTAMP) AND (ACTIVE_TO IS NULL OR ACTIVE_TO>=CURRENT_TIMESTAMP)", [$request['productId'],$products]);
        if (count($product) !== 1) throw new DocumentConflict('Связанный товар отсутствует или неактивен.');
        $pairs = $this->db->rows('SELECT IBLOCK_ID,PRODUCT_IBLOCK_ID,SKU_PROPERTY_ID FROM b_catalog_iblock WHERE IBLOCK_ID IN (?,?) ORDER BY IBLOCK_ID', [$products,$offers]);
        $byId = []; foreach ($pairs as $row) $byId[self::id($row['IBLOCK_ID'])] = $row;
        if (count($byId) !== 2 || !isset($byId[$products],$byId[$offers]) || (int)$byId[$products]['PRODUCT_IBLOCK_ID'] !== 0
            || (int)$byId[$offers]['PRODUCT_IBLOCK_ID'] !== $products) throw new DocumentConflict('Связь каталогов товара и ТП изменилась.');
        $sku = self::id($byId[$offers]['SKU_PROPERTY_ID']);
        $properties = $this->db->rows('SELECT IBLOCK_ID,ACTIVE,PROPERTY_TYPE,USER_TYPE,MULTIPLE,LINK_IBLOCK_ID FROM b_iblock_property WHERE ID=?', [$sku]);
        $iblock = $this->db->rows('SELECT VERSION FROM b_iblock WHERE ID=?', [$offers]);
        if (count($properties) !== 1 || count($iblock) !== 1 || (int)$properties[0]['IBLOCK_ID'] !== $offers || $properties[0]['ACTIVE'] !== 'Y'
            || $properties[0]['PROPERTY_TYPE'] !== 'E' || !in_array((string)($properties[0]['USER_TYPE'] ?? ''), ['', 'SKU'], true)
            || $properties[0]['MULTIPLE'] !== 'N' || (int)$properties[0]['LINK_IBLOCK_ID'] !== $products
            || !in_array((int)$iblock[0]['VERSION'], [1,2], true)) throw new DocumentConflict('Схема связи SKU не подтверждена.');
        // Identifiers originate only from strictly validated positive schema IDs.
        $join = (int)$iblock[0]['VERSION'] === 2
            ? 'JOIN b_iblock_element_prop_s'.$offers.' s ON s.IBLOCK_ELEMENT_ID=e.ID AND s.PROPERTY_'.$sku.'=?'
            : 'JOIN b_iblock_element_property s ON s.IBLOCK_ELEMENT_ID=e.ID AND s.IBLOCK_PROPERTY_ID='.$sku.' AND s.VALUE=?';
        $rows = $this->db->rows("SELECT e.ID,e.NAME FROM b_iblock_element e $join JOIN b_catalog_product c ON c.ID=e.ID AND c.TYPE=4 WHERE e.IBLOCK_ID=? AND e.ID>? AND e.ACTIVE='Y' AND (e.ACTIVE_FROM IS NULL OR e.ACTIVE_FROM<=CURRENT_TIMESTAMP) AND (e.ACTIVE_TO IS NULL OR e.ACTIVE_TO>=CURRENT_TIMESTAMP) ORDER BY e.ID LIMIT 101", [(string)$request['productId'],$offers,$request['afterId']]);
        $items = []; $last = $request['afterId'];
        foreach ($rows as $row) {
            $id = self::id($row['ID']);
            if ($id <= $last || !is_string($row['NAME']) || trim($row['NAME']) === '' || strlen($row['NAME']) > 500
                || preg_match('/[\x00-\x1F\x7F]/', $row['NAME'])) throw new DocumentConflict('Некорректное или неоднозначное ТП.');
            $last = $id; $items[] = ['id' => $id, 'name' => $row['NAME']];
        }
        $hasMore = count($items) > 100; $items = array_slice($items,0,100);
        return ['contract' => 'prospektweb.calculator/catalog-targets-v1', 'documentId' => $request['id'], 'publicationId' => $publication['id'],
            'productId' => $request['productId'], 'afterId' => $request['afterId'], 'offers' => $items, 'hasMore' => $hasMore,
            'nextAfterId' => $items ? $items[count($items)-1]['id'] : $request['afterId']];
    }

    private static function id($value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$value)) throw new DocumentConflict('Некорректный ID каталога.');
        return (int)$value;
    }
}
