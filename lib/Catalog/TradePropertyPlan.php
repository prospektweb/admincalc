<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Catalog;

/** Pure, additive plan. Unknown properties and enum IDs/values are never removed. */
final class TradePropertyPlan
{
    public static function manifest(?string $path = null): array
    {
        $value=json_decode(file_get_contents($path ?? dirname(__DIR__,2).'/resources/catalog/trade-properties.json'),true,32,JSON_THROW_ON_ERROR);
        if (($value['contract']??'')!=='prospektweb.calc.trade-properties/v1'||!is_int($value['revision']??null)||!is_array($value['properties']??null)) throw new \RuntimeException('Некорректный состав свойств Calc.');
        $seen=[];
        foreach($value['properties'] as $item){
            $f=$item['fields']??[];$key=($item['role']??'').':'.($f['CODE']??'');
            if(!in_array($item['role']??'', ['products','offers'],true)||!preg_match('/^CALC_[A-Z0-9_]+$/D',$f['CODE']??'')||isset($seen[$key])||trim($f['NAME']??'')==='') throw new \RuntimeException('Некорректная идентичность свойства '.$key);
            $seen[$key]=true;$enums=[];
            foreach($item['enums']??[] as $enum){$xml=$enum['XML_ID']??'';if($xml===''||isset($enums[$xml])||trim($enum['VALUE']??'')==='')throw new \RuntimeException('Некорректный код значения '.$key);$enums[$xml]=true;}
        }
        return $value;
    }
    public static function build(array $manifest,array $snapshot): array
    {
        $operations=[];$rows=[];$existing=0;
        foreach($manifest['properties'] as $item){
            $role=$item['role'];$f=$item['fields'];$code=$f['CODE'];$key=$role.':'.$code;
            $matches=$snapshot['properties'][$key]??[];$tracked=$snapshot['tracked'][$key]??0;
            if(!$matches)foreach($item['aliases']??[]as$alias)$matches=array_merge($matches,$snapshot['properties'][$role.':'.$alias]??[]);
            if(!$matches&&$tracked){foreach($snapshot['properties'] as $members)foreach($members as $member)if((int)$member['ID']===$tracked&&(int)$member['IBLOCK_ID']===$snapshot['catalogs'][$role])$matches[]=$member;}
            $changes=[];$blocked=[];$old=count($matches)===1?$matches[0]:null;
            if(count($matches)>1)$blocked[]='Несколько свойств с одним кодом.';
            if($old){$existing++;foreach($f as $name=>$expected){$actual=$old[$name]??($name==='USER_TYPE_SETTINGS'?[]:'');if($name==='WITH_DESCRIPTION'&&$actual==='')$actual='N';if($name==='USER_TYPE_SETTINGS'){$actual=is_array($actual)?$actual:[];$actual=array_intersect_key($actual,$expected);ksort($actual);ksort($expected);$same=$actual===$expected;}else $same=(string)$actual===(string)$expected;
                if(!$same){$changes[]=['field'=>$name,'before'=>$actual,'after'=>$expected];if(($name==='PROPERTY_TYPE'||($name==='MULTIPLE'&&$actual==='Y'&&$expected==='N'))&&($snapshot['hasValues'][$key]??true))$blocked[]='Свойство содержит значения: тип или множественность требуют отдельной безопасной миграции.';}}
            }else if(!$matches)$changes[]=['field'=>'Свойство','before'=>null,'after'=>'Создать'];
            $enumChanges=[];
            foreach($item['enums'] as $desired){$found=array_values(array_filter($old['enums']??[],fn($e)=>(string)$e['XML_ID']===$desired['XML_ID']));if(count($found)>1){$blocked[]='Повторный код значения '.$desired['XML_ID'];continue;}
                $delta=[];foreach($desired as $name=>$wanted)if(!$found||(string)($found[0][$name]??'')!==(string)$wanted)$delta[$name]=['before'=>$found[0][$name]??null,'after'=>$wanted];
                if($delta)$enumChanges[]=['id'=>$found?(int)$found[0]['ID']:0,'xmlId'=>$desired['XML_ID'],'fields'=>$desired,'changes'=>$delta];
            }
            $dependency=$snapshot['dependencies'][$key]??null;if($dependency)$blocked[]=$dependency;
            $row=['key'=>$key,'role'=>$role,'iblockId'=>$snapshot['catalogs'][$role],'code'=>$code,'name'=>$f['NAME'],'description'=>$item['description'],'type'=>$f['PROPERTY_TYPE'],'userType'=>$f['USER_TYPE']??'','id'=>$old?(int)$old['ID']:null,'values'=>$snapshot['directoryValues'][$key]??$item['enums'],'changes'=>$changes,'enumChanges'=>$enumChanges,'blocked'=>array_values(array_unique($blocked))];
            $row['status']=$blocked?'blocked':($changes||$enumChanges?($old?'update':'create'):'ready');$rows[]=$row;
            if($changes||$enumChanges)$operations[]=['key'=>$key,'role'=>$role,'id'=>$row['id'],'fields'=>$f,'enums'=>$enumChanges,'blocked'=>$row['blocked']];
        }
        return ['contract'=>'prospektweb.calc.trade-property-plan/v1','manifestRevision'=>$manifest['revision'],'hash'=>hash('sha256',json_encode([$manifest,$snapshot],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'catalogs'=>$snapshot['catalogs'],'initialized'=>($snapshot['initialized']??false)===true,'rows'=>$rows,'operations'=>$operations,'summary'=>['supported'=>count($rows),'existing'=>$existing,'create'=>count(array_filter($rows,fn($r)=>$r['status']==='create')),'update'=>count(array_filter($rows,fn($r)=>$r['status']==='update')),'blocked'=>count(array_filter($rows,fn($r)=>$r['status']==='blocked')),'ready'=>count(array_filter($rows,fn($r)=>$r['status']==='ready'))]];
    }
}
