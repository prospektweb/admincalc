<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/Documents/DocumentPreparationPropertyPlan.php';
use Prospektweb\Calc\Documents\DocumentPreparationPropertyPlan as Plan;
$checks=0;
function check(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function rejects(callable $work):void{try{$work();}catch(InvalidArgumentException $e){check(true,$e->getMessage());return;}throw new RuntimeException('Expected conflict');}
$m=['source'=>['iblock_id'=>15,'property_id'=>705,'property_code'=>'VOLUME','scope'=>'selected_offer'],'target'=>['field_id'=>'volume'],'source_value'=>'xml_id','value_mode'=>'scalar'];
$p=['ID'=>'705','IBLOCK_ID'=>'15','CODE'=>'VOLUME','ACTIVE'=>'Y','MULTIPLE'=>'N','PROPERTY_TYPE'=>'L','USER_TYPE'=>''];
$choices=[['ID'=>'357','PROPERTY_ID'=>'705','XML_ID'=>'100','VALUE'=>'Hundred'],['ID'=>'359','PROPERTY_ID'=>'705','XML_ID'=>'200','VALUE'=>'Two hundred']];
$a=Plan::value($m,100,$p,$choices);$b=Plan::value($m,200,$p,$choices);
check($a['values']===[357]&&$b['values']===[359],'Exact enum IDs, never labels');
rejects(fn()=>Plan::value($m,'Hundred',$p,$choices));rejects(fn()=>Plan::value($m,300,$p,$choices));
foreach(['ID'=>'706','IBLOCK_ID'=>'14','ACTIVE'=>'N','CODE'=>'OTHER','USER_TYPE'=>'arbitrary'] as $k=>$v){$bad=$p;$bad[$k]=$v;if($k==='USER_TYPE')$bad['PROPERTY_TYPE']='S';rejects(fn()=>Plan::value($m,100,$bad,$choices));}
$bad=$choices;$bad[1]['XML_ID']='100';rejects(fn()=>Plan::value($m,100,$p,$bad));
$bad=$choices;$bad[0]['PROPERTY_ID']='706';rejects(fn()=>Plan::value($m,100,$p,$bad));
$mapped=$m;$mapped['option_map']=['100'=>'one','200'=>'one'];rejects(fn()=>Plan::value($mapped,'one',$p,$choices));
$mapped['option_map']=['100'=>'one','200'=>'two'];check(Plan::value($mapped,'two',$p,$choices)['values']===[359],'Reverse explicit option map');
$regex=$m;$regex['transform_regex']='[0-9]+';rejects(fn()=>Plan::value($regex,100,$p,$choices));
$format=$m;$format['value_mode']='dimension_xml_id';$fc=$choices;$fc[0]['XML_ID']='90x50';$fc[1]['XML_ID']='85x55';
check(Plan::value($format,(object)['width'=>90,'length'=>50],$p,$fc)['values']===[357],'Whole exact dimensions');
rejects(fn()=>Plan::value($format,(object)['width'=>50,'length'=>90],$p,$fc));
$fc[1]['XML_ID']='90.0x50';rejects(fn()=>Plan::value($format,(object)['width'=>90,'length'=>50],$p,$fc));
$multi=$p;$multi['MULTIPLE']='Y';check(Plan::value($m,[200,100],$multi,$choices)['values']===[357,359],'Canonical multivalue set');
rejects(fn()=>Plan::value($m,[100,200],$p,$choices));rejects(fn()=>Plan::value($m,[100,100],$multi,$choices));
$directory=$p;$directory['PROPERTY_TYPE']='S';$directory['USER_TYPE']='directory';
check(Plan::value($m,100,$directory,$choices)['values']===['100'],'Directory stores exact reference');
$number=$p;$number['PROPERTY_TYPE']='N';$numeric=$m;$numeric['source_value']='value';
check(Plan::value($numeric,12.5,$number,[])['values']===[12.5],'Numeric precision retained');
foreach(['12.5',INF,NAN,true] as $bad)rejects(fn()=>Plan::value($numeric,$bad,$number,[]));
$pa=$a;$pa['scope']='product';$pb=$b;$pb['scope']='product';
check(Plan::consensus(['a'=>[$a,$pa],'b'=>[$b,$pa]])['ready'],'SKU values can differ while product agrees');
check(!Plan::consensus(['a'=>[$pa],'b'=>[$pb]])['ready'],'Product mismatch is conflict');
check(!Plan::consensus(['a'=>[$pa],'b'=>[]])['ready'],'Missing product contribution is conflict');
check(!Plan::consensus(['a'=>[$a,$b]])['ready'],'Two active inputs cannot overwrite same SKU property');
$empty=$pa;$empty['values']=[];check(!Plan::consensus(['a'=>[$pa],'b'=>[$empty]])['ready'],'Explicit empty differs from nonempty');
check(Plan::consensus(['a'=>[$empty],'b'=>[$empty]])['productProperties'][0]['values']===[],'Agreed empty retained for diff');
echo "PASS $checks preparation property plan assertions\n";
