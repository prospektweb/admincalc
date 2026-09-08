<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/BitrixConnection.php';
require_once __DIR__ . '/DocumentCatalogWritePlan.php';
require_once __DIR__ . '/DocumentRepository.php';

/** Mutation half of the native catalog port; never owns/commits a transaction. */
final class BitrixCatalogStateWriter
{
    private SqlConnection $db;
    private $mutate;
    private const PRODUCT_FIELDS = ['PURCHASING_PRICE','PURCHASING_CURRENCY','WIDTH','LENGTH','HEIGHT','WEIGHT'];

    /** The injected mutation function is a test/infrastructure seam, not HTTP input. */
    public function __construct(SqlConnection $db, ?callable $mutate = null)
    {
        $this->db = $db;
        if ($mutate === null) {
            if (!$db instanceof BitrixConnection || $db->nativeConnection() !== \Bitrix\Main\Application::getConnection()
                || !\Bitrix\Main\Loader::includeModule('catalog')) {
                throw new \LogicException('Catalog APIs must use the same Bitrix connection as the coordinator.');
            }
            $mutate = static function(string $action, int $id, array $fields) {
                return match ($action) {
                    'product.update' => \CCatalogProduct::Update($id, $fields),
                    'price.update' => \CPrice::Update($id, $fields),
                    'price.delete' => \CPrice::Delete($id),
                    'price.add' => \CPrice::Add($fields),
                    default => throw new \LogicException('Unknown catalog mutation.'),
                };
            };
        }
        $this->mutate = $mutate;
    }

    /** Exact targets supplied by DocumentCatalogWriteService after quote/CAS. */
    public function write(array $targets): void
    {
        $this->assertTransaction();
        if (!array_is_list($targets) || !$targets || count($targets) > 100) throw new \InvalidArgumentException('Expected 1..100 catalog write targets.');
        $normalized = []; $ids = [];
        foreach ($targets as $target) {
            if (!is_array($target)) throw new \InvalidArgumentException('Invalid catalog target.');
            $keys = array_keys($target); sort($keys);
            $id = $target['offerId'] ?? null; $types = $target['priceTypeIds'] ?? null;
            if ($keys !== ['offerId','priceTypeIds','state'] || !is_int($id) || $id < 1 || $id > 999999999 || isset($ids[$id])
                || !is_array($types) || !array_is_list($types) || !$types || count($types) > 100) throw new \InvalidArgumentException('Invalid or duplicate catalog target.');
            $owned = [];
            foreach ($types as $type) {
                if (!is_int($type) || $type < 1 || $type > 999999999 || isset($owned[$type])) throw new \InvalidArgumentException('Invalid owned price types.');
                $owned[$type] = true;
            }
            if (!is_array($target['state'])) throw new \InvalidArgumentException('Invalid catalog target state.');
            $state = DocumentCatalogWritePlan::state($target['state']);
            if ($state['purchasingPrice']['value'] === null || $state['purchasingPrice']['value'] <= 0 || $state['purchasingPrice']['currency'] === null) throw new \InvalidArgumentException('Complete purchase price is required.');
            foreach ($state['dimensions'] as $number) if ($number === null || $number <= 0) throw new \InvalidArgumentException('Complete positive dimensions are required.');
            $found = []; $ranges = [];
            foreach ($state['prices'] as $price) if (isset($owned[$price['typeId']])) {
                if ($price['price'] === null || $price['price'] <= 0) throw new \InvalidArgumentException('Complete positive prices are required.');
                $found[$price['typeId']] = true; $ranges[$price['typeId']][] = $price;
            }
            if (array_diff_key($owned,$found)) throw new \InvalidArgumentException('Every owned price type requires ranges.');
            foreach ($ranges as $rows) {
                usort($rows,static fn(array $a,array $b):int=>($a['quantityFrom']??0)<=>($b['quantityFrom']??0));
                $end = -1;
                foreach ($rows as $row) {
                    if (($row['quantityFrom']??0) <= $end) throw new \InvalidArgumentException('Overlapping target price ranges.');
                    $end = $row['quantityTo']??PHP_INT_MAX;
                }
            }
            $ids[$id] = $id; $normalized[$id] = ['owned'=>$owned,'state'=>$state];
        }
        ksort($ids,SORT_NUMERIC); ksort($normalized,SORT_NUMERIC);
        $before = $this->read(array_values($ids));
        $operations = [];
        // Validate every target before the first API write, including unowned prices.
        foreach ($normalized as $id => $target) {
            $owned = $target['owned']; $state = $target['state']; $current = $before[$id];
            $unowned = static fn(array $rows):array=>array_values(array_filter($rows,static fn(array $price):bool=>!isset($owned[$price['typeId']])));
            if (DocumentCatalogWritePlan::hash($unowned($state['prices'])) !== DocumentCatalogWritePlan::hash($unowned($current['state']['prices']))) {
                throw new DocumentConflict('Подтверждённая запись затрагивает посторонний тип цены.');
            }
            $values = ['PURCHASING_PRICE'=>$state['purchasingPrice']['value'],'PURCHASING_CURRENCY'=>$state['purchasingPrice']['currency']];
            foreach ($state['dimensions'] as $key=>$value) $values[strtoupper($key)]=$value;
            $changed = [];
            foreach ($values as $key=>$value) {
                $old=$current['product'][$key]??null;
                if ($key==='PURCHASING_CURRENCY' ? $old!==$value : ($old===null || (float)$old!==$value)) $changed[$key]=$value;
            }
            if ($changed) $operations[]=['product.update',$id,$changed];
            $desired=[];
            foreach ($state['prices'] as $price) if (isset($owned[$price['typeId']])) $desired[self::priceKey($price)]=$price;
            foreach ($current['prices'] as $row) {
                $type=(int)$row['CATALOG_GROUP_ID']; if (!isset($owned[$type])) continue;
                $old=self::priceState($row); $key=self::priceKey($old); $new=$desired[$key]??null;
                if ($new===null) { $operations[]=['price.delete',(int)$row['ID'],[]]; continue; }
                if ($old['price']!==$new['price'] || $old['currency']!==$new['currency']) $operations[]=['price.update',(int)$row['ID'],['PRICE'=>$new['price'],'CURRENCY'=>$new['currency']]];
                unset($desired[$key]);
            }
            foreach ($desired as $price) $operations[]=['price.add',0,['PRODUCT_ID'=>$id,'CATALOG_GROUP_ID'=>$price['typeId'],'PRICE'=>$price['price'],
                'CURRENCY'=>$price['currency'],'QUANTITY_FROM'=>$price['quantityFrom']??false,'QUANTITY_TO'=>$price['quantityTo']??false]];
        }
        $deletedIds=[];
        foreach ($operations as [$action,$id,$fields]) {
            if ($action==='price.delete') $deletedIds[$id]=true;
            $this->assertTransaction();
            if (!(($this->mutate)($action,$id,$fields))) throw new \RuntimeException('Bitrix catalog write failed; outer transaction must roll back.',409);
            $this->assertTransaction();
        }
        $after=$this->read(array_values($ids));
        foreach ($normalized as $id=>$target) {
            if (DocumentCatalogWritePlan::hash($after[$id]['state'])!==DocumentCatalogWritePlan::hash($target['state'])) throw new DocumentConflict('Каталог не подтвердил записанные величины.');
            $beforeProduct=$before[$id]['product']; $afterProduct=$after[$id]['product'];
            // Bitrix may update its own modification timestamp for these fields.
            foreach (array_merge(self::PRODUCT_FIELDS,['TIMESTAMP_X']) as $key) { unset($beforeProduct[$key],$afterProduct[$key]); }
            if ($beforeProduct!==$afterProduct) throw new DocumentConflict('Запись изменила постороннее поле товара.');
            $unowned=static fn(array $rows):array=>array_values(array_filter($rows,static fn(array $row):bool=>!isset($target['owned'][(int)$row['CATALOG_GROUP_ID']])));
            if ($unowned($before[$id]['prices'])!==$unowned($after[$id]['prices'])) throw new DocumentConflict('Запись изменила постороннюю строку цены.');
            $updated=[];
            foreach ($after[$id]['prices'] as $row) $updated[(int)$row['ID']]=$row;
            // Existing owned ranges retain IDs and other operator-owned metadata.
            foreach ($before[$id]['prices'] as $row) {
                if (!isset($target['owned'][(int)$row['CATALOG_GROUP_ID']]) || isset($deletedIds[(int)$row['ID']])) continue;
                if (!isset($updated[(int)$row['ID']])) throw new DocumentConflict('Запись пересоздала существующий диапазон цены.');
                $new=$updated[(int)$row['ID']];
                foreach (['PRICE','CURRENCY','PRICE_SCALE','TIMESTAMP_X'] as $key) { unset($row[$key],$new[$key]); }
                if ($row!==$new) throw new DocumentConflict('Запись изменила метаданные диапазона цены.');
            }
        }
    }

    private function assertTransaction(): void
    {
        if (!$this->db->inTransaction()) throw new \LogicException('Catalog write requires the coordinator transaction.');
        if ($this->db instanceof BitrixConnection) $this->db->assertCatalogWriteTransaction();
    }

    private function read(array $ids): array
    {
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $lock=$this->db->dialect()==='mysql'?' FOR UPDATE':'';
        $products=$this->db->rows('SELECT * FROM b_catalog_product WHERE ID IN ('.$marks.') ORDER BY ID LIMIT 101'.$lock,$ids);
        $prices=$this->db->rows('SELECT * FROM b_catalog_price WHERE PRODUCT_ID IN ('.$marks.') ORDER BY PRODUCT_ID, ID LIMIT 10001'.$lock,$ids);
        if ($this->db->dialect()==='mysql') {
            $engines=$this->db->rows("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('b_catalog_product','b_catalog_price') ORDER BY TABLE_NAME");
            if (count($engines)!==2 || array_column($engines,'TABLE_NAME')!==['b_catalog_price','b_catalog_product']
                || count(array_filter($engines,static fn(array $row):bool=>strtoupper((string)$row['ENGINE'])==='INNODB'))!==2) throw new \RuntimeException('Transactional catalog tables are required.',409);
        }
        if (count($products)!==count($ids) || count($prices)>10000) throw new DocumentConflict('Товары каталога отсутствуют или превышен лимит цен.');
        $result=[];
        foreach ($products as $row) {
            $id=(int)$row['ID']; if (!in_array($id,$ids,true) || isset($result[$id])) throw new DocumentConflict('Неоднозначный товар каталога.');
            $dimensions=[];
            foreach (['width','length','height','weight'] as $key) $dimensions[$key]=self::number($row[strtoupper($key)]??null);
            $result[$id]=['product'=>$row,'prices'=>[],'state'=>['purchasingPrice'=>['value'=>self::number($row['PURCHASING_PRICE']??null),'currency'=>$row['PURCHASING_CURRENCY']??null],
                'dimensions'=>$dimensions,'prices'=>[]]];
        }
        foreach ($prices as $row) {
            $id=(int)$row['PRODUCT_ID']; if (!isset($result[$id]) || !isset($row['ID']) || (int)$row['ID']<1) throw new DocumentConflict('Неоднозначная строка цены.');
            $result[$id]['prices'][]=$row; $result[$id]['state']['prices'][]=self::priceState($row);
        }
        foreach ($result as &$row) $row['state']=DocumentCatalogWritePlan::state($row['state']);
        unset($row); return $result;
    }
    private static function priceState(array $row): array
    {
        return ['typeId'=>(int)$row['CATALOG_GROUP_ID'],'quantityFrom'=>self::bound($row['QUANTITY_FROM']??null),'quantityTo'=>self::bound($row['QUANTITY_TO']??null),
            'price'=>self::number($row['PRICE']??null),'currency'=>$row['CURRENCY']??null];
    }
    private static function number($value): ?float
    {
        if ($value===null) return null;
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value) || !is_finite((float)$value)) throw new DocumentConflict('Некорректное число в каталоге.');
        return (float)$value;
    }
    private static function bound($value): ?int
    {
        if ($value===null || $value===0 || $value==='0') return null;
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value) || (int)$value>2147483647) throw new DocumentConflict('Некорректный диапазон в каталоге.');
        return (int)$value;
    }
    private static function priceKey(array $row): string { return $row['typeId'].':'.($row['quantityFrom']??'n').':'.($row['quantityTo']??'n'); }
}
