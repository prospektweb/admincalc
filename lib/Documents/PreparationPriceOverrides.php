<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__.'/DocumentCatalogWritePlan.php';

/** Durable v1 address. Results/revisions and native price row IDs are not identity. */
final class PreparationPriceOverrides
{
    public static function slots(array $state,array $ownedTypes):array
    {
        $p=$state['purchasingPrice'];$out=[['kind'=>'purchase','typeId'=>null,'quantityFrom'=>null,'quantityTo'=>null,'currency'=>$p['currency'],'calculated'=>$p['value']]];
        foreach($state['prices'] as $p)if(in_array($p['typeId'],$ownedTypes,true))$out[]=['kind'=>'sale','typeId'=>$p['typeId'],'quantityFrom'=>$p['quantityFrom'],'quantityTo'=>$p['quantityTo'],'currency'=>$p['currency'],'calculated'=>$p['price']];
        usort($out,fn($a,$b)=>[($a['kind']==='purchase'?0:1),$a['typeId']??0,$a['quantityFrom']??0,$a['quantityTo']??PHP_INT_MAX]<=>[($b['kind']==='purchase'?0:1),$b['typeId']??0,$b['quantityFrom']??0,$b['quantityTo']??PHP_INT_MAX]);
        foreach($out as &$slot)$slot['key']=self::key($slot);unset($slot);return $out;
    }
    public static function key(array $slot):string
    {
        return DocumentCatalogWritePlan::hash(array_intersect_key($slot,array_flip(['kind','typeId','quantityFrom','quantityTo','currency'])));
    }
    public static function resolve(array $calculated,array $ownedTypes,array $overrides):array
    {
        $state=$calculated;$slots=self::slots($calculated,$ownedTypes);$seen=[];
        foreach($slots as &$slot){$key=$slot['key'];$seen[$key]=true;$custom=$overrides[$key]??null;
            $slot['mode']=$custom===null?'calculated':'custom';$slot['effective']=$custom===null?$slot['calculated']:(float)$custom;
            $base=$slot['calculated'];$slot['differencePercent']=$base==0?($slot['effective']==0?0:null):100*($slot['effective']-$base)/abs($base);
            if($slot['kind']==='purchase')$state['purchasingPrice']['value']=$slot['effective'];
            else foreach($state['prices'] as &$p)if(self::key(['kind'=>'sale']+$p)===$key)$p['price']=$slot['effective'];unset($p);
        }unset($slot);
        return ['state'=>DocumentCatalogWritePlan::state($state),'slots'=>$slots,'orphanKeys'=>array_values(array_diff(array_keys($overrides),array_keys($seen)))];
    }
    public static function amount(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,8})?$/D',$value))throw new \InvalidArgumentException('Цена: неотрицательное число, до 8 знаков после точки.');
        return $value;
    }
}
