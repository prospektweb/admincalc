<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__.'/SqlConnection.php';

/** Read model only. Counts active/date-valid offers, matching the former registry semantics. */
final class BitrixRegistryOfferCounts
{
    public function __construct(private SqlConnection $db, private string $scope, private string $provider, private int $products, private int $offers)
    {
        if (!preg_match('/^site:[A-Za-z0-9]{1,2}$/D', $scope)) throw new \InvalidArgumentException('Trusted site scope required.');
    }

    /** Called by the repository within its metadata read snapshot, once for the visible page. */
    public function __invoke(array $items): array
    {
        if (!$this->db->inTransaction()) throw new \LogicException('Registry usage requires a shared read snapshot.');
        if (count($items)>100) throw new \InvalidArgumentException('Invalid registry page size.');
        $counts=[]; $pending=[];
        foreach ($items as $item) {
            if (!is_string($item['id']??null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$item['id'])
                || !is_int($item['productCount']??null) || $item['productCount']<0 || array_key_exists($item['id'],$counts)) throw new \InvalidArgumentException('Invalid registry metadata.');
            // No published bindings means zero offers; lack of a usable catalog means unknown, not zero.
            $counts[$item['id']]=$item['productCount']===0?0:null;
            if ($item['productCount']>0) $pending[$item['id']]=$item;
        }
        if (!$pending || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$this->provider)
            || $this->products<1 || $this->offers<1 || $this->products>999999999 || $this->offers>999999999 || $this->products===$this->offers) return $counts;
        $catalogs=$this->db->rows('SELECT IBLOCK_ID,PRODUCT_IBLOCK_ID,SKU_PROPERTY_ID FROM b_catalog_iblock WHERE IBLOCK_ID IN (?,?)',[$this->products,$this->offers]);
        $pairs=[]; foreach($catalogs as $row) $pairs[(int)$row['IBLOCK_ID']]=$row;
        if (count($pairs)!==2 || !isset($pairs[$this->products],$pairs[$this->offers]) || (int)$pairs[$this->products]['PRODUCT_IBLOCK_ID']!==0
            || (int)$pairs[$this->offers]['PRODUCT_IBLOCK_ID']!==$this->products) return $counts;
        $sku=(int)$pairs[$this->offers]['SKU_PROPERTY_ID'];
        if ($sku<1 || $sku>999999999) return $counts;
        $properties=$this->db->rows('SELECT p.IBLOCK_ID,p.ACTIVE,p.PROPERTY_TYPE,p.USER_TYPE,p.MULTIPLE,p.LINK_IBLOCK_ID,i.VERSION FROM b_iblock_property p JOIN b_iblock i ON i.ID=p.IBLOCK_ID WHERE p.ID=?',[$sku]);
        if (count($properties)!==1) return $counts;
        $p=$properties[0];
        if ((int)$p['IBLOCK_ID']!==$this->offers || $p['ACTIVE']!=='Y' || $p['PROPERTY_TYPE']!=='E' || !in_array((string)($p['USER_TYPE']??''),['','SKU'],true)
            || $p['MULTIPLE']!=='N' || (int)$p['LINK_IBLOCK_ID']!==$this->products || !in_array((int)$p['VERSION'],[1,2],true)) return $counts;
        // Only validated schema IDs enter identifiers; all identities and scope remain parameters.
        $join=(int)$p['VERSION']===2
            ? 'LEFT JOIN b_iblock_element_prop_s'.$this->offers.' s ON s.PROPERTY_'.$sku.'=p.ID'
            : 'LEFT JOIN b_iblock_element_property s ON s.IBLOCK_PROPERTY_ID='.$sku.' AND s.VALUE=p.ID';
        $rows=$this->db->rows("SELECT d.id,a.publication_id,COUNT(DISTINCT b.product_key) AS binding_count,COUNT(DISTINCT e.ID) AS offer_count
            FROM b_pw_calc_document d JOIN b_pw_calc_site_active a ON a.document_id=d.id
            JOIN b_pw_calc_product_binding b ON b.document_id=d.id AND b.scope_id=d.scope_id AND b.publication_id=a.publication_id AND b.provider=? AND b.catalog_key=?
            LEFT JOIN b_iblock_element p ON p.ID=b.product_key AND p.IBLOCK_ID=?
            $join
            LEFT JOIN b_iblock_element e ON e.ID=s.IBLOCK_ELEMENT_ID AND e.IBLOCK_ID=? AND e.ACTIVE='Y' AND (e.ACTIVE_FROM IS NULL OR e.ACTIVE_FROM<=CURRENT_TIMESTAMP) AND (e.ACTIVE_TO IS NULL OR e.ACTIVE_TO>=CURRENT_TIMESTAMP)
            WHERE d.scope_id=? AND d.id IN (".implode(',',array_fill(0,count($pending),'?')).') GROUP BY d.id,a.publication_id',
            array_merge([$this->provider,(string)$this->products,$this->products,$this->offers,$this->scope],array_keys($pending)));
        foreach ($rows as $row) {
            $source=$pending[$row['id']]??null;
            if (!$source || $source['activeSitePublication']!==$row['publication_id'] || $source['productCount']!==(int)$row['binding_count']) continue;
            $value=filter_var($row['offer_count'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if ($value===false) throw new \RuntimeException('Invalid offer aggregate.');
            $counts[$row['id']]=$value;
        }
        return $counts;
    }
}
