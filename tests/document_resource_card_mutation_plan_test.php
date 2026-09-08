<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/resource_card_fixture.php';
require_once dirname(__DIR__).'/lib/Documents/ResourceCardMutationPlan.php';
use Prospektweb\Calc\Documents\{BitrixResourceCardSnapshot,ResourceCardMutationPlan};
$checks=0;
$check=static function(bool $ok,string $label) use(&$checks):void {++$checks;if(!$ok)throw new RuntimeException($label);};
$rejects=static function(callable $call,int $code=0) use($check):void {try{$call();}catch(Throwable $e){$check($e->getCode()===$code,$e->getMessage());return;}throw new RuntimeException('Expected rejection');};
foreach([1,2] as $version) {
    [$pdo,$db]=resourceCardFixture($version);$db->begin(true);
    $s=(new BitrixResourceCardSnapshot($db,'bitrix:test'))->capture(['provider'=>'bitrix:test','catalog'=>'CALC_MATERIALS_VARIANTS','key'=>'101']);$db->commit();
    $rows=array_map(static function(array $r):array {unset($r['code'],$r['adminUrl'],$r['sectionPath']);return $r;},[$s['data']['parent'],...$s['data']['variants']]);
    $before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
    $plan=ResourceCardMutationPlan::build($s,$rows);
    $check($plan['mutations']===[],'Unchanged card has no writes, not even timestamps or product creation');
    $check($plan['expectedRows']===array_map([ResourceCardMutationPlan::class,'comparable'],$rows),'Comparable after-image is deterministic');
    $rename=$rows;$rename[0]['name']=' Новая бумага ';$plan=ResourceCardMutationPlan::build($s,$rename);
    $check(count($plan['mutations'])===1 && $plan['mutations'][0]['id']===100 && $plan['mutations'][0]['fields']===['NAME'=>'Новая бумага'],'Rename touches only NAME of selected row');
    $check($plan['mutations'][0]['properties']===[] && $plan['mutations'][0]['product']===[] && $plan['mutations'][0]['price']===null,'Rename never rewrites parameters, price, VAT, HTML or other siblings');
    $legacy=$s;$legacy['data']['variants'][0]['parameters'][0]['code']='Старый код';$legacy['data']['variants'][0]['sourceLinks'][0]['url']='old-protocol:source';
    $legacyRows=$rows;$legacyRows[1]['parameters']=$legacy['data']['variants'][0]['parameters'];$legacyRows[1]['sourceLinks']=$legacy['data']['variants'][0]['sourceLinks'];
    $legacyRows[1]['parameters'][0]=array_reverse($legacyRows[1]['parameters'][0],true);
    $check(ResourceCardMutationPlan::build($legacy,$legacyRows)['mutations']===[],'Old invalid values survive unchanged, regardless of JSON object key order');
    $legacyRows[1]['name']='Rename with legacy values';
    $check(ResourceCardMutationPlan::build($legacy,$legacyRows)['mutations'][0]['fields']===['NAME'=>'Rename with legacy values'],'Unrelated rename never repairs historical parameters or sources');
    $legacyRows[1]['parameters'][0]['value']='99';$rejects(fn()=>ResourceCardMutationPlan::build($legacy,$legacyRows));
    $preview=$rows;$preview[1]['previewText']='Другое описание';$plan=ResourceCardMutationPlan::build($s,$preview);
    $check($plan['mutations'][0]['fields']===['PREVIEW_TEXT'=>'Другое описание','PREVIEW_TEXT_TYPE'=>'text'],'Explicit preview edit owns its text format');
    $detail=$rows;$detail[2]['detailText']='<p>New &amp;</p>';$plan=ResourceCardMutationPlan::build($s,$detail);
    $check($plan['mutations'][0]['fields']===['DETAIL_TEXT'=>'<p>New &amp;</p>','DETAIL_TEXT_TYPE'=>'html'],'Detailed HTML stays literal');
    $parameters=$rows;$parameters[1]['parameters']=array_reverse($parameters[1]['parameters']);$plan=ResourceCardMutationPlan::build($s,$parameters);
    $check($plan['mutations'][0]['properties']===['PARAMETRS'=>[['VALUE'=>'speed','DESCRIPTION'=>'0|Скорость|'],['VALUE'=>'rate','DESCRIPTION'=>'12.50|Цена &amp;|Описание']]],'Parameter ordering and adapter serialization only');
    $sources=$rows;$sources[1]['sourceLinks']=[];$plan=ResourceCardMutationPlan::build($s,$sources);
    $check($plan['mutations'][0]['properties']===['SOURCE_LINKS'=>false],'Explicit source removal does not clear parameters');
    $suppliers=$rows;$suppliers[0]['supplierIds']=[401];$plan=ResourceCardMutationPlan::build($s,$suppliers);
    $check($plan['mutations'][0]['properties']===['SUPPLIERS'=>[401]],'Supplier edit preserves supported inactive link');
    $catalog=$rows;$catalog[1]['catalog']['purchasingPrice']='12,500';$catalog[1]['catalog']['weight']=25;
    $check(ResourceCardMutationPlan::build($s,$catalog)['mutations']===[],'Numeric formatting is not a write');
    $catalog[1]['catalog']['width']='215.5';$plan=ResourceCardMutationPlan::build($s,$catalog);
    $check($plan['mutations'][0]['product']===['WIDTH'=>'215.5'] && $plan['mutations'][0]['price']===null,'Dimensions update exactly one owned catalog field');
    $price=$rows;$price[1]['catalog']['basePrice']='20';$plan=ResourceCardMutationPlan::build($s,$price);
    $check($plan['mutations'][0]['price']===['action'=>'update','id'=>1010,'fields'=>['CATALOG_GROUP_ID'=>1,'PRICE'=>'20','CURRENCY'=>'RUB']],'Base price update pins exact existing row');
    $price[1]['catalog']['basePrice']='';$plan=ResourceCardMutationPlan::build($s,$price);
    $check($plan['mutations'][0]['price']===['action'=>'delete','id'=>1010],'Clearing base price deletes only owned exact row');
    $percent=$s;$percent['data']['variants'][0]['catalog']['baseCurrency']='PRC';$percent['raw']['prices'][2]['CURRENCY']='PRC';$percentRows=$price;$percentRows[1]['catalog']['baseCurrency']='PRC';
    $percentPlan=ResourceCardMutationPlan::build($percent,$percentRows);
    $check($percentPlan['mutations'][0]['price']===['action'=>'delete','id'=>1010] && $percentPlan['expectedRows'][1]['catalog']['baseCurrency']==='RUB','Deleting a percentage price expects absent-row currency default, not a phantom retained price mode');
    $margin=$rows;$margin[2]['catalog']['basePrice']='15';$margin[2]['catalog']['baseCurrency']='MRG';$plan=ResourceCardMutationPlan::build($s,$margin);
    $check($plan['mutations'][0]['price']['fields']['CURRENCY']==='MRG','Existing margin/percent currency modes remain supported');
    $new=['id'=>0,'name'=>'Новая вариация','previewText'=>'','detailText'=>'','parameters'=>[],'sourceLinks'=>[],'supplierIds'=>[],'catalog'=>[]];
    $plan=ResourceCardMutationPlan::build($s,[...$rows,$new]);
    $check(count($plan['mutations'])===1 && $plan['mutations'][0]['create'] && $plan['mutations'][0]['iblock']===42 && $plan['parentId']===100,'New variant target comes from server catalog and parent');
    $check($plan['mutations'][0]['fields']===['NAME'=>'Новая вариация'] && $plan['mutations'][0]['product']===[],'New default variant does not invent price or product fields');
    $all=$rows;$all[1]['name']='Changed';$all[2]['catalog']['basePrice']='NaN';
    $rejects(fn()=>ResourceCardMutationPlan::build($s,$all));
    $check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,'Full-card validation/planning is pure even after an earlier valid row');
    foreach(['iblockId'=>42,'actor'=>'user:1','properties'=>['CML2_LINK'=>999]] as $key=>$value) { $invalid=$rows;$invalid[1][$key]=$value;$rejects(fn()=>ResourceCardMutationPlan::build($s,$invalid)); }
    foreach([999,'101',-1,1.5] as $id) { $invalid=$rows;$invalid[1]['id']=$id;$rejects(fn()=>ResourceCardMutationPlan::build($s,$invalid)); }
    $rejects(fn()=>ResourceCardMutationPlan::build($s,[$rows[0],$rows[1]]));
    $rejects(fn()=>ResourceCardMutationPlan::build($s,[$rows[1],$rows[0],$rows[2]]));
    $rejects(fn()=>ResourceCardMutationPlan::build($s,[$rows[0],$rows[1],$rows[1],$rows[2]]));
    foreach([['code'=>'bad-code','value'=>'1','title'=>'','description'=>''],['code'=>'valid','value'=>'1|2','title'=>'','description'=>''],['code'=>'valid','value'=>false,'title'=>'','description'=>'']] as $parameter) { $invalid=$rows;$invalid[1]['parameters']=[$parameter];$rejects(fn()=>ResourceCardMutationPlan::build($s,$invalid)); }
    $invalid=$rows;$invalid[1]['parameters'][]=$invalid[1]['parameters'][0];$rejects(fn()=>ResourceCardMutationPlan::build($s,$invalid));
    foreach(['javascript:alert(1)','file:///etc/passwd','https://'] as $url) { $invalid=$rows;$invalid[1]['sourceLinks'][0]['url']=$url;$rejects(fn()=>ResourceCardMutationPlan::build($s,$invalid)); }
    foreach([[-1],['400'],[400,400],[9999]] as $ids) { $invalid=$rows;$invalid[1]['supplierIds']=$ids;$rejects(fn()=>ResourceCardMutationPlan::build($s,$invalid)); }
    foreach(['-1','NaN','INF','1e309',true,[],new stdClass()] as $value) { $invalid=$rows;$invalid[1]['catalog']['width']=$value;$rejects(fn()=>ResourceCardMutationPlan::build($s,$invalid)); }
    foreach([['vatId',3],['vatIncluded','Y'],['baseCurrency','USD'],['QUANTITY','100']] as [$key,$value]) { $invalid=$rows;$invalid[1]['catalog'][$key]=$value;$rejects(fn()=>ResourceCardMutationPlan::build($s,$invalid)); }
    $tiered=$s;$tiered['raw']['prices'][2]['QUANTITY_FROM']=1;
    $rejects(fn()=>ResourceCardMutationPlan::build($tiered,$price),409);
    $check(ResourceCardMutationPlan::build($tiered,$rename)['mutations'][0]['fields']===['NAME'=>'Новая бумага'],'Tiered prices do not prevent a metadata-only edit');
    $missing=$s;$missing['raw']['properties'][42]['schema']=array_values(array_filter($missing['raw']['properties'][42]['schema'],static fn(array $p):bool=>$p['CODE']!=='SOURCE_LINKS'));
    $rejects(fn()=>ResourceCardMutationPlan::build($missing,$sources),409);
    $check(ResourceCardMutationPlan::build($missing,$rename)['mutations'][0]['fields']===['NAME'=>'Новая бумага'],'Missing source schema cannot trigger implicit schema mutation');
    $db->begin(true);$equipment=(new BitrixResourceCardSnapshot($db,'bitrix:test'))->capture(['provider'=>'bitrix:test','catalog'=>'CALC_EQUIPMENT','key'=>'300']);$db->commit();
    $equipmentRow=$equipment['data']['parent'];unset($equipmentRow['code'],$equipmentRow['adminUrl'],$equipmentRow['sectionPath']);
    $rejects(fn()=>ResourceCardMutationPlan::build($equipment,[$equipmentRow,$new]));
    $equipmentRow['supplierIds']=[400];$rejects(fn()=>ResourceCardMutationPlan::build($equipment,[$equipmentRow]));
}
echo "PASS $checks resource card mutation plan assertions\n";
