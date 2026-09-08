<?php
declare(strict_types=1);
/** Native API stand-in for isolated SQLite integration tests only. */
function resourceCardMutationFixture(\Prospektweb\Calc\Documents\SqlConnection $db, array &$calls, ?callable $after = null): callable
{
    $setProperties = static function(int $id, int $iblock, array $values) use ($db): void {
        $version=(int)$db->rows('SELECT VERSION FROM b_iblock WHERE ID=?',[$iblock])[0]['VERSION'];
        foreach($values as $code=>$values) {
            $p=$db->rows('SELECT * FROM b_iblock_property WHERE IBLOCK_ID=? AND CODE=?',[$iblock,$code])[0];
            if($values===false)$values=[];
            elseif($p['MULTIPLE']==='N')$values=[$values];
            if($version===2 && $p['MULTIPLE']==='N') {
                $db->execute('UPDATE b_iblock_element_prop_s'.$iblock.' SET PROPERTY_'.$p['ID'].'=? WHERE IBLOCK_ELEMENT_ID=?',[isset($values[0])?(string)$values[0]:null,$id]);continue;
            }
            $table=$version===2?'b_iblock_element_prop_m'.$iblock:'b_iblock_element_property';
            $db->execute('DELETE FROM '.$table.' WHERE IBLOCK_ELEMENT_ID=? AND IBLOCK_PROPERTY_ID=?',[$id,(int)$p['ID']]);
            foreach($values as $value) {
                $next=1+(int)$db->rows('SELECT COALESCE(MAX(ID),0) AS last_id FROM '.$table)[0]['last_id'];
                $db->execute('INSERT INTO '.$table.' VALUES (?,?,?,?,?)',[$next,$id,(int)$p['ID'],is_array($value)?$value['VALUE']:(string)$value,is_array($value)?($value['DESCRIPTION']??''):'']);
            }
        }
    };
    return static function(string $action,int $id,array $fields) use ($db,&$calls,$after,$setProperties) {
        $calls[]=[$action,$id,$fields];$result=true;
        if($action==='element.add') {
            $id=1+(int)$db->rows('SELECT MAX(ID) AS last_id FROM b_iblock_element')[0]['last_id'];
            $iblock=$fields['IBLOCK_ID'];
            $db->execute("INSERT INTO b_iblock_element VALUES (?,?,NULL,?,?,'Y',500,?,?,?,?,?,?)",[$id,$iblock,$fields['NAME'],$fields['CODE'],$fields['PREVIEW_TEXT']??'',$fields['PREVIEW_TEXT_TYPE']??'text',$fields['DETAIL_TEXT']??'',$fields['DETAIL_TEXT_TYPE']??'text','2026-09-09 00:00:01','']);
            $db->execute('INSERT INTO b_iblock_element_prop_s'.$iblock.' (IBLOCK_ELEMENT_ID) VALUES (?)',[$id]);
            $setProperties($id,$iblock,$fields['PROPERTY_VALUES']);$result=$id;
        } elseif($action==='element.update' || $action==='product.update') {
            $table=$action==='element.update'?'b_iblock_element':'b_catalog_product';
            $sets=[];$params=[];foreach($fields as $key=>$value){$sets[]=$key.'=?';$params[]=$value;}$params[]=$id;
            $db->execute('UPDATE '.$table.' SET '.implode(',',$sets).',TIMESTAMP_X=\'2026-09-09 00:00:01\' WHERE ID=?',$params);
        } elseif($action==='properties.set')$setProperties($id,$fields['iblock'],$fields['values']);
        elseif($action==='product.add') {
            if(isset($fields['PURCHASING_PRICE']) && empty($fields['PURCHASING_CURRENCY']))throw new RuntimeException('Empty purchase currency',409);
            $db->execute("INSERT INTO b_catalog_product VALUES (?,1,'Y',NULL,'RUB','0',NULL,NULL,NULL,'0',4,'2026-09-09 00:00:01')",[$id]);
            $sets=[];$params=[];foreach($fields as $key=>$value){$sets[]=$key.'=?';$params[]=$value;}$params[]=$id;
            $db->execute('UPDATE b_catalog_product SET '.implode(',',$sets).' WHERE ID=?',$params);
        } elseif($action==='price.update') {
            $db->execute("UPDATE b_catalog_price SET PRICE=?,CURRENCY=?,CATALOG_GROUP_ID=?,TIMESTAMP_X='2026-09-09 00:00:01' WHERE ID=?",[$fields['PRICE'],$fields['CURRENCY'],$fields['CATALOG_GROUP_ID'],$id]);
        } elseif($action==='price.delete')$db->execute('DELETE FROM b_catalog_price WHERE ID=?',[$id]);
        elseif($action==='price.add') {
            $id=1+(int)$db->rows('SELECT MAX(ID) AS last_id FROM b_catalog_price')[0]['last_id'];
            $db->execute("INSERT INTO b_catalog_price VALUES (?,?,?,?,?,NULL,NULL,'2026-09-09 00:00:01')",[$id,$fields['PRODUCT_ID'],$fields['CATALOG_GROUP_ID'],$fields['PRICE'],$fields['CURRENCY']]);$result=$id;
        } else throw new RuntimeException('Unexpected fixture mutation '.$action);
        if($after)$after($action,$id,$fields);
        return $result;
    };
}
