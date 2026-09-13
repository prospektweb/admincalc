<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** One projection shared by the table and property consensus panel. Never writes. */
final class PreparationCatalogView
{
    public static function build(array $catalog,array $mapped,array $consensus,array $variants):array
    {
        $columns=[];
        foreach($mapped as $rows)foreach($rows as $row){
            $id=$row['propertyId'];$s=$catalog['schemas'][$id]??null;if(!$s)continue;
            $columns[$id]=['id'=>$id,'iblockId'=>(int)$s['IBLOCK_ID'],'scope'=>$row['scope'],'name'=>$s['NAME']??$s['CODE'],
                'code'=>$s['CODE'],'sort'=>(int)($s['SORT']??500),'multiple'=>$s['MULTIPLE']==='Y','type'=>$s['PROPERTY_TYPE'],
                'derived'=>str_starts_with($row['fieldId'],'mapping:'),'choices'=>array_map(fn($c)=>['value'=>$s['PROPERTY_TYPE']==='L'?(int)$c['ID']:$c['XML_ID'],'label'=>$c['VALUE']],$catalog['choices'][$id]??[])];
        }
        uasort($columns,fn($a,$b)=>[$a['sort'],$a['id']]<=>[$b['sort'],$b['id']]);
        $products=[];$offers=[];
        foreach($columns as $id=>$column){
            if($column['scope']==='selected_offer'){$offers[]=$column;continue;}
            $groups=[];
            foreach($mapped as $variant=>$rows){$matches=array_values(array_filter($rows,fn($r)=>$r['propertyId']===$id));$values=$matches[0]['values']??null;
                $key=DocumentCatalogWritePlan::hash($values);$groups[$key]??=['values'=>$values,'variants'=>[]];$groups[$key]['variants'][]=$variant;}
            $products[]=$column+['groups'=>array_values($groups),'conflict'=>count($groups)!==1||isset($groups[DocumentCatalogWritePlan::hash(null)])];
        }
        $cells=[];
        foreach($mapped as $variant=>$rows){$cells[$variant]=[];foreach($rows as $r)if($r['scope']==='selected_offer')$cells[$variant][(string)$r['propertyId']]=$r['values'];}
        $types=[];foreach($variants as $v)foreach($v['state']['prices'] as $p)if(in_array($p['typeId'],$v['priceTypeIds'],true))$types[$p['typeId']]=['id'=>$p['typeId'],'name'=>$catalog['priceTypeNames'][$p['typeId']]??('Тип #'.$p['typeId'])];
        ksort($types,SORT_NUMERIC);
        return ['propertyColumns'=>$offers,'productProperties'=>$products,'propertyCells'=>$cells,'priceTypes'=>array_values($types)];
    }
}
