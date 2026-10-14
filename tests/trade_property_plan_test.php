<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/Catalog/TradePropertyPlan.php';
use Prospektweb\Calc\Catalog\TradePropertyPlan;
$count=0;function ok($v,$message){global$count;$count++;if(!$v)throw new RuntimeException($message);}
$manifest=TradePropertyPlan::manifest();ok(count($manifest['properties'])===40,'supported composition');ok(count(array_filter($manifest['properties'],fn($x)=>in_array($x['fields']['CODE'],['BRAND','PARTNER_GROUPS','TR_CASE','CALC_PRESET'],true)||str_starts_with($x['fields']['CODE'],'FRONTCALC_')))===0,'foreign and legacy properties excluded');
$snapshot=['catalogs'=>['products'=>14,'offers'=>15],'properties'=>[],'tracked'=>[],'initialized'=>false,'dependencies'=>[],'links'=>[]];
$empty=TradePropertyPlan::build($manifest,$snapshot);ok($empty['summary']['create']===40,'all roles present without optional modules');ok(!$empty['initialized'],'create label before explicit operation');
foreach($manifest['properties']as$i=>$item){$key=$item['role'].':'.$item['fields']['CODE'];$row=$item['fields']+['ID'=>$i+100,'IBLOCK_ID'=>$snapshot['catalogs'][$item['role']],'enums'=>[]];foreach($item['enums']as$j=>$e)$row['enums'][]=$e+['ID'=>1000+$i*100+$j];$snapshot['properties'][$key]=[$row];$snapshot['tracked'][$key]=$i+100;}
$ready=TradePropertyPlan::build($manifest,$snapshot);ok($ready['summary']['ready']===40,'idempotent exact metadata');ok(!$ready['operations'],'no writes for ready state');
$key='products:CALC_PROP_URGENCY';$old=$snapshot['properties'][$key][0];$snapshot['properties'][$key][0]['NAME']='Изменено';$repair=TradePropertyPlan::build($manifest,$snapshot);$op=array_values(array_filter($repair['operations'],fn($r)=>$r['key']===$key))[0];ok($op['id']===$old['ID'],'preserve property ID');ok(!$op['blocked'],'metadata repair allowed');
$snapshot['properties'][$key]=[$old];$snapshot['properties'][$key][0]['enums'][0]['VALUE']='Изменено';$repair=TradePropertyPlan::build($manifest,$snapshot);$op=array_values(array_filter($repair['operations'],fn($r)=>$r['key']===$key))[0];ok($op['enums'][0]['id']===$old['enums'][0]['ID'],'preserve enum ID/XML_ID');
$snapshot['properties'][$key]=[$old];$snapshot['properties'][$key][0]['enums'][]=['ID'=>99999,'XML_ID'=>'CUSTOM','VALUE'=>'Пользовательское','SORT'=>999,'DEF'=>'N'];ok(!TradePropertyPlan::build($manifest,$snapshot)['operations'],'unknown enum preserved');
$snapshot['properties'][$key]=[$old];$snapshot['properties'][$key][0]['PROPERTY_TYPE']='S';ok(TradePropertyPlan::build($manifest,$snapshot)['summary']['blocked']===1,'type conversion blocked');
$snapshot['hasValues'][$key]=false;ok(TradePropertyPlan::build($manifest,$snapshot)['summary']['update']===1,'empty property type can be restored');unset($snapshot['hasValues']);
$snapshot['properties'][$key]=[$old];$snapshot['properties'][$key][0]['MULTIPLE']='Y';ok(TradePropertyPlan::build($manifest,$snapshot)['summary']['blocked']===1,'multiple to single blocked');
$snapshot['properties'][$key]=[$old,$old];ok(TradePropertyPlan::build($manifest,$snapshot)['summary']['blocked']===1,'duplicate code blocked');
$snapshot['properties'][$key]=[$old];$snapshot['properties'][$key][0]['enums'][]=$old['enums'][0];ok(TradePropertyPlan::build($manifest,$snapshot)['summary']['blocked']===1,'duplicate enum blocked');
$snapshot['properties'][$key]=[$old];unset($snapshot['properties'][$key]);$snapshot['properties']['products:RENAMED']=[$old];$snapshot['properties']['products:RENAMED'][0]['CODE']='RENAMED';$op=array_values(array_filter(TradePropertyPlan::build($manifest,$snapshot)['operations'],fn($r)=>$r['key']===$key))[0];ok($op['id']===$old['ID'],'registry restores renamed same ID');
unset($snapshot['properties']['products:RENAMED']);$snapshot['initialized']=true;$deleted=TradePropertyPlan::build($manifest,$snapshot);ok($deleted['initialized'],'update remains after deletion');ok($deleted['summary']['create']===1,'missing property restored');
$snapshot['dependencies'][$key]='Связанный справочник отсутствует';ok(TradePropertyPlan::build($manifest,$snapshot)['summary']['blocked']===1,'missing dependency explicit');
ok($empty['hash']!==$ready['hash'],'CAS captures schema');ok($repair['hash']!==$ready['hash'],'CAS captures enum edits');
foreach($empty['rows']as$row){ok(isset($row['name'],$row['code'],$row['values']), 'details complete');foreach($row['values']as$v)ok($v['XML_ID']!==''&&$v['VALUE']!=='','table enum code/name complete');}
echo $count." trade property plan checks PASS\n";
