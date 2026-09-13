<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/native_catalog_write_fixture.php';
require_once __DIR__.'/../lib/Documents/DocumentPreparationCatalog.php';
use Prospektweb\Calc\Documents\{DocumentPreparationCatalog,DocumentProductPreparation,DocumentVersions,DocumentCatalogWritePlan as Hash};
$checks=0;function ok($v,$s){global $checks;$checks++;if(!$v)throw new RuntimeException($s);}
function reject($f){try{$f();}catch(Throwable $e){ok(true,$e->getMessage());return;}throw new RuntimeException('Expected rejection');}
$f=nativeWriteFixture();extract($f);$body=json_decode($body,true);$body['form']=['fields'=>[['fieldId'=>'qty','label'=>'Тираж','type'=>'number','systemKey'=>'volume']],'sections'=>[]];
$version=DocumentVersions::primaryId('sheet');$repo->versions()->listing('sheet');$head=$repo->versions()->load('sheet',$version);$repo->versions()->save('sheet',$version,$head['revision'],json_encode($body));
$source=$repo->versions()->load('sheet',$version);
$capture=function($qty)use($repo,$source,$version){$q=nativeWriteQuote();$q['name']='Тираж '.$qty;return $repo->snapshots()->capture('sheet',$version,$source,['source'=>['documentId'=>'sheet','versionId'=>$version,'revision'=>$source['revision']],'result'=>$q],['values'=>(object)['qty'=>$qty],'sectionActivation'=>(object)[],'execution'=>(object)['unitCount'=>$qty,'runCount'=>1,'layoutCount'=>1,'deadlineType'=>'strict']],[]);};
$s1=$capture(100);$s2=$capture(200);
$prep=new DocumentProductPreparation($db,'site:s1','user:1',$repo,'bitrix:test','14',fn($q,$ids)=>[['key'=>'42','name'=>'Product']]);
$base=['action'=>'productPreparation','id'=>'sheet','versionId'=>$version,'expectedRevision'=>$source['revision'],'storefrontId'=>'BASE','productKey'=>'42','groupId'=>null,'snapshotIds'=>[$s1,$s2]];
$preview=$prep->command($base+['operation'=>'preview']);$prep->command($base+['operation'=>'transfer','fingerprint'=>$preview['fingerprint'],'choices'=>(object)[]]);
$results=$db->rows('SELECT id FROM b_pw_calc_preparation_result ORDER BY id');
$db->execute('CREATE TABLE qa_generation (id INTEGER PRIMARY KEY, body TEXT)');$db->execute('INSERT INTO qa_generation VALUES (1,?)',['{}']);
$port=new class($db) {
    public bool $fail=false;public int $writes=0;public function __construct(public $db){}
    public function capture($site,$document,$product,$bindings,$lock){$rows=json_decode($this->db->rows('SELECT body FROM qa_generation')[0]['body'],true);$states=[];foreach($rows as $id=>$v)$states[$id]=['state'=>$v['state']];$missing=[];foreach($bindings as $key=>$b)if(!isset($states[$b['offer_id']]))$missing[$key]=(int)$b['offer_id'];return ['missingBindings'=>$missing,'schemas'=>[],'choices'=>[],'states'=>$states,'rows'=>$rows];}
    public function round($s,$t){return $s;}public function validate($c,$v,$key){}
    public function parentProjection($c,$p){return null;}
    public function assertRemoved($ids){if(json_decode($this->db->rows('SELECT body FROM qa_generation')[0]['body'],true))throw new RuntimeException('Catalog still exists');}
    public function diff($c,$plan){$rows=[];foreach($plan['variants'] as $key=>$v)$rows[$key]=['action'=>$v['offerId']?'unchanged':'create'];return ['productDiff'=>[],'variants'=>$rows];}
    public function write($before,$plan){$this->writes++;$rows=$before['rows'];$ids=[];foreach($plan['variants'] as $key=>$v){$id=$v['offerId']??(100+100*$this->writes+count($rows));$rows[$id]=$v;$ids[$key]=$id;$this->db->execute('UPDATE qa_generation SET body=?',[json_encode($rows)]);if($this->fail)throw new RuntimeException('Injected mid-write failure');}return $ids;}
    public function verify($before,$after,$plan,$ids){foreach($plan['variants'] as $key=>$v)if(Hash::hash($after['states'][$ids[$key]]['state'])!==Hash::hash($v['state']))throw new RuntimeException('Readback differs');}
};
$service=new DocumentPreparationCatalog($db,'site:s1','user:1',$repo,$port,fn()=>['qty'=>['visible'=>true]]);
$cmd=['action'=>'preparationCatalog','operation'=>'preview','id'=>'sheet','versionId'=>$version,'expectedRevision'=>$source['revision'],'storefrontId'=>'BASE','productKey'=>'42','resultIds'=>array_column($results,'id'),'newActive'=>false];
$plan=$service->command($cmd);ok($plan['ready']&&count($plan['rows'])===2,'Two immutable results ready');ok($port->writes===0,'Preview does not write');
$priceRow=$plan['rows'][0];$priceSlot=$priceRow['priceSlots'][0];
$settingsCmd=array_replace($cmd,['operation'=>'savePrices','expectedSettingsRevision'=>0,'edits'=>[['variantKey'=>$priceRow['variantKey'],'key'=>$priceSlot['key'],'mode'=>'custom','value'=>'123.456']]]);
$custom=$service->command($settingsCmd);ok($custom['settingsRevision']===1&&$port->writes===0,'Durable settings save without native catalog write');
$reopenedSettings=$service->command($cmd);$customRow=array_column($reopenedSettings['rows'],null,'variantKey')[$priceRow['variantKey']];
ok($customRow['state']['purchasingPrice']['value']===123.456&&$customRow['priceSlots'][0]['mode']==='custom','Reopen preserves manual amount/mode');
reject(fn()=>$service->command($settingsCmd));
reject(fn()=>$service->command(array_replace($cmd,['operation'=>'apply','fingerprint'=>$plan['fingerprint']])));
$reset=array_replace($settingsCmd,['expectedSettingsRevision'=>1,'edits'=>[['variantKey'=>$priceRow['variantKey'],'key'=>$priceSlot['key'],'mode'=>'calculated','value'=>null]]]);
$plan=$service->command($reset);ok($plan['settingsRevision']===2&&$plan['ready'],'Explicit reset restores calculated plan');
$apply=array_replace($cmd,['operation'=>'apply','fingerprint'=>$plan['fingerprint']]);
$port->fail=true;reject(fn()=>$service->command($apply));ok(!$db->rows('SELECT * FROM b_pw_calc_preparation_offer')&&!$db->rows('SELECT * FROM b_pw_calc_preparation_write'),'Partial failure rolls back bindings and receipts');ok($db->rows('SELECT body FROM qa_generation')[0]['body']==='{}','Catalog rollback');$port->fail=false;
$r=$service->command($apply);ok(count($r['offerIds'])===2,'Real returned IDs bound');$writes=$port->writes;
$again=$service->command($apply);ok($again['replayed']&&$port->writes===$writes,'Exact replay does not call writer');
ok(count($db->rows('SELECT * FROM b_pw_calc_preparation_offer'))===2,'No duplicate binding');
$reopen=$service->command($cmd);ok($reopen['ready']&&array_column($reopen['rows'],'action')===['unchanged','unchanged'],'Reopen resolves linked IDs');
$oldReceipt=$db->rows('SELECT * FROM b_pw_calc_preparation_write');
$db->execute('UPDATE qa_generation SET body=?',['{}']);
reject(fn()=>$service->command($apply));
$allMissing=$service->command($cmd);ok($allMissing['ready']&&array_column($allMissing['rows'],'action')===['recreate','recreate'],'Deleted bindings become normal recreate rows');
ok(array_column($allMissing['rows'],'previousOfferId')===array_values($r['offerIds']),'Previous IDs visible');
$one=array_replace($cmd,['resultIds'=>[$cmd['resultIds'][0]]]);$onePlan=$service->command($one);
$oneApply=array_replace($one,['operation'=>'apply','fingerprint'=>$onePlan['fingerprint']]);$oneReceipt=$service->command($oneApply);
ok(count(json_decode($db->rows('SELECT body FROM qa_generation')[0]['body'],true))===1,'Only selected missing variant recreated');
ok($service->command($oneApply)['replayed'],'Recreate replay does not duplicate');
reject(fn()=>$service->command(array_replace($cmd,['operation'=>'apply','fingerprint'=>$allMissing['fingerprint']])));
$remaining=$service->command($cmd);ok($remaining['ready']&&count(array_filter($remaining['rows'],fn($r)=>$r['action']==='recreate'))===1,'Unselected missing binding remains actionable after partial apply');
$next=$service->command(array_replace($cmd,['operation'=>'apply','fingerprint'=>$remaining['fingerprint']]));
ok(count(json_decode($db->rows('SELECT body FROM qa_generation')[0]['body'],true))===2,'Remaining missing recreated without duplicate');
ok(count($db->rows('SELECT * FROM b_pw_calc_preparation_offer'))===2,'Stable variant binding count');
ok($db->rows('SELECT * FROM b_pw_calc_preparation_write WHERE id=?',[$oldReceipt[0]['id']])===$oldReceipt,'Original receipt retained exactly');
ok(!array_intersect(array_values($r['offerIds']),array_values($next['offerIds'])),'New IDs replace missing IDs');
echo "PASS $checks recreate SQL assertions\n";