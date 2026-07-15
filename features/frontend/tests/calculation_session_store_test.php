<?php
require_once __DIR__ . '/../lib/Service/DeadlineAdjustmentNormalizer.php';
require_once __DIR__ . '/../lib/Service/CalculationSessionStore.php';
use Prospektweb\Frontcalc\Service\CalculationSessionStore;
use Prospektweb\Frontcalc\Service\DeadlineAdjustmentNormalizer;
function ok($v,$m){ if(!$v){fwrite(STDERR,$m."\n"); exit(1);} }
$root=sys_get_temp_dir().'/frontcalc_session_test_'.bin2hex(random_bytes(4)); $now=1000; $ctx=['siteId'=>'s1','userId'=>10,'sessionBinding'=>'sess'];
$store=new CalculationSessionStore($root,3600,fn()=>$ctx,function() use (&$now) { return $now; });
$offers=[['offerKey'=>'a','id'=>-1,'source'=>'calc-server','isVirtual'=>true,'quantity'=>100,'properties'=>['CALC_PROP_FORMAT'=>['value'=>'A','xml_id'=>'A','evil'=>'x'],'BAD'=>1],'pricing'=>['ranges'=>[['typeId'=>1,'price'=>1,'currency'=>'RUB','evil'=>'x']]],'internal'=>['x'=>1],'purchasePrice'=>9,'directPurchasePrice'=>8,'parametrValues'=>[1],'calc_server_raw'=>[2]]];
$config=['fields'=>[
 ['property_code'=>'CALC_PROP_FORMAT'],['property_code'=>'CALC_PROP_MATERIAL'],['property_code'=>'CALC_PROP_FORMAT'],
 ['property_code'=>'CALC_PROP_VOLUME','deadline_adjustments'=>['mode'=>'advanced','urgent_markup'=>'20','flexible_discount'=>'10','advanced'=>['urgent_markup'=>[['volume'=>'VOLUME_30000','percent'=>'15'],['volume'=>1000,'percent'=>'5'],['volume'=>1000,'percent'=>'7']],'flexible_discount'=>[['volume'=>'VOLUME_30000','percent'=>'5']]]]]],
 'deadline_adjustments'=>['mode'=>'simple','urgent_markup'=>99], 'x'=>'drop'];
$enumMap=['VOLUME_30000'=>['value'=>'30 000','xml_id'=>'VOLUME_30000']];
$config['deadline_adjustments']=DeadlineAdjustmentNormalizer::normalize($config,$enumMap);
$t=$store->create(123,$offers,$config);
$loaded=$store->load($t,123); ok(is_array($loaded)&&$loaded['productId']===123,'create load'); ok(!isset($loaded['offers'][0]['internal'],$loaded['offers'][0]['purchasePrice'],$loaded['config']['x']),'sanitize');
ok($loaded['config']['requiredPropertyCodes']===['CALC_PROP_FORMAT','CALC_PROP_MATERIAL','CALC_PROP_VOLUME'],'required codes');
ok($loaded['config']['deadline_adjustments']['mode']==='advanced' && $loaded['config']['deadline_adjustments']['advanced']['urgent_markup'][1]['volume']===30000,'deadline from enum map xml normalized');
ok($loaded['config']['deadline_adjustments']['advanced']['urgent_markup'][0]['percent']==7.0,'advanced dedupe last wins sorted');
ok(isset($loaded['offers'][0]['properties']['CALC_PROP_FORMAT']) && !isset($loaded['offers'][0]['properties']['BAD'],$loaded['offers'][0]['properties']['CALC_PROP_FORMAT']['evil'],$loaded['offers'][0]['pricing']['ranges'][0]['evil']),'nested sanitize');
$q=DeadlineAdjustmentNormalizer::normalize(['fields'=>[['property_code'=>'CALC_PROP_VOLUME','deadline_adjustments'=>['mode'=>'advanced','advanced'=>['urgent_markup'=>[['volume'=>'QTY_30K','percent'=>1]]]]]]],['QTY_30K'=>['value'=>'30 000']]); ok($q['advanced']['urgent_markup'][0]['volume']===30000,'QTY_30K resolved through backend map');
$q=DeadlineAdjustmentNormalizer::normalize(['fields'=>[['property_code'=>'CALC_PROP_VOLUME','deadline_adjustments'=>['mode'=>'advanced','advanced'=>['urgent_markup'=>[['volume'=>'QTY_30K','percent'=>1]]]]]]],[]); ok($q['advanced']['urgent_markup']===[],'QTY_30K without map skipped');
$q=DeadlineAdjustmentNormalizer::normalize(['fields'=>[['property_code'=>'CALC_PROP_VOLUME','deadline_adjustments'=>['mode'=>'advanced','advanced'=>['urgent_markup'=>[['volume'=>'VOLUME_30000','percent'=>1],['volume'=>'30 000','percent'=>2],['volume'=>'1000.5','percent'=>3]]]]]]],['VOLUME_30000'=>['value'=>'30 000']]); ok(count($q['advanced']['urgent_markup'])===1 && $q['advanced']['urgent_markup'][0]['volume']===30000 && $q['advanced']['urgent_markup'][0]['percent']==2.0,'enum map direct spaced int and decimal rejected');
$normalizedRoot=['mode'=>'advanced','urgent_markup'=>0,'flexible_discount'=>0,'advanced'=>['urgent_markup'=>[['volume'=>30000,'percent'=>11]],'flexible_discount'=>[]]]; $tNorm=$store->create(123,[],['fields'=>[['property_code'=>'CALC_PROP_VOLUME','deadline_adjustments'=>['mode'=>'advanced','advanced'=>['urgent_markup'=>[['volume'=>'QTY_30K','percent'=>99]]]]]],'deadline_adjustments'=>$normalizedRoot]); $lNorm=$store->load($tNorm,123); ok($lNorm['config']['deadline_adjustments']['advanced']['urgent_markup'][0]['volume']===30000 && $lNorm['config']['deadline_adjustments']['advanced']['urgent_markup'][0]['percent']==11,'normalized root not renormalized from raw field');
$other=new CalculationSessionStore($root,3600,fn()=>['siteId'=>'s1','userId'=>11,'sessionBinding'=>'sess'],function() use (&$now) { return $now; }); ok($other->load($t,123)===null,'other user blocked');
$otherSess=new CalculationSessionStore($root,3600,fn()=>['siteId'=>'s1','userId'=>10,'sessionBinding'=>'other'],function() use (&$now) { return $now; }); ok($otherSess->load($t,123)===null,'other session blocked'); ok($store->load($t,124)===null,'other product blocked');
$store->mergeOffers($t,123,[['offerKey'=>'a','id'=>-999,'source'=>'bitrix','quantity'=>200,'properties'=>[],'pricing'=>['ranges'=>[]]],['offerKey'=>'b','id'=>-1,'quantity'=>300]]); $m=$store->load($t,123); ok(count($m['offers'])===2 && $m['offers'][0]['id']===-999,'merge by offerKey not id');
$store->mergeOffers($t,123,[['offerKey'=>'c','id'=>3,'quantity'=>400]]); $m=$store->load($t,123); ok(count($m['offers'])===3,'merge lock keeps offerKey');
$path=$root.'/bitrix/cache/prospektweb.calc/calculation_sessions/'.$t.'.json'; file_put_contents($path,'{bad'); ok($store->load($t,123)===null && !file_exists($path),'bad json deleted');
$t2=$store->create(123,[],[]); $path2=$root.'/bitrix/cache/prospektweb.calc/calculation_sessions/'.$t2.'.json'; $bad=json_decode(file_get_contents($path2),true); $bad['version']=999; file_put_contents($path2,json_encode($bad)); ok($store->load($t2,123)===null && !file_exists($path2),'bad version deleted');
$blockingRoot = tempnam(sys_get_temp_dir(), 'frontcalc_session_file_');
ok(is_string($blockingRoot) && $blockingRoot !== '', 'write failure fixture created');
$writeFailed = false;
try {
    (new CalculationSessionStore($blockingRoot, 3600, fn() => $ctx, fn() => 1000))->create(1, [], []);
} catch (RuntimeException $e) {
    $writeFailed = true;
} finally {
    @unlink($blockingRoot);
}
ok($writeFailed, 'write failure throws');
$t3=$store->create(123,[],[]); $now=5000; ok($store->load($t3,123)===null,'expired deleted');
$now=10000; $cleanupRoot=sys_get_temp_dir().'/frontcalc_cleanup_test_'.bin2hex(random_bytes(4)); $cleanup=new CalculationSessionStore($cleanupRoot,60,fn()=>$ctx,function() use (&$now){return $now;}); $cleanupToken=$cleanup->create(1,[],[]); $dir=$cleanupRoot.'/bitrix/cache/prospektweb.calc/calculation_sessions'; $expiredToken=str_repeat('A',32); file_put_contents($dir.'/'.$expiredToken.'.json', json_encode(['expiresAt'=>1])); ok($cleanup->cleanupExpired(100)>0 && !file_exists($dir.'/'.$expiredToken.'.json'),'cleanup removes expired json'); $orphanToken=str_repeat('B',32); file_put_contents($dir.'/'.$orphanToken.'.json.lock',''); touch($dir.'/'.$orphanToken.'.json.lock',$now-1000); $cleanup->cleanupExpired(100); ok(!file_exists($dir.'/'.$orphanToken.'.json.lock'),'cleanup removes orphan lock'); $deleteToken=$cleanup->create(2,[],[]); file_put_contents($dir.'/'.$deleteToken.'.json.lock',''); ok($cleanup->delete($deleteToken) && !file_exists($dir.'/'.$deleteToken.'.json') && !file_exists($dir.'/'.$deleteToken.'.json.lock'),'delete removes json and lock'); for($i=0;$i<5;$i++){ file_put_contents($dir.'/'.str_repeat((string)$i,32).'.json', json_encode(['expiresAt'=>1])); } ok($cleanup->cleanupExpired(2)===2,'cleanup processing limited');
echo "OK\n";
