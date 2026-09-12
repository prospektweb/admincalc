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
    public function capture($site,$document,$product,$bindings,$lock){$rows=json_decode($this->db->rows('SELECT body FROM qa_generation')[0]['body'],true);$states=[];foreach($rows as $id=>$v)$states[$id]=['state'=>$v['state']];return ['schemas'=>[],'choices'=>[],'states'=>$states,'rows'=>$rows];}
    public function round($s,$t){return $s;}public function validate($c,$v,$key){}
    public function assertRemoved($ids){if(json_decode($this->db->rows('SELECT body FROM qa_generation')[0]['body'],true))throw new RuntimeException('Catalog still exists');}
    public function diff($c,$plan){$rows=[];foreach($plan['variants'] as $key=>$v)$rows[$key]=['action'=>$v['offerId']?'unchanged':'create'];return ['productDiff'=>[],'variants'=>$rows];}
    public function write($before,$plan){$this->writes++;$rows=$before['rows'];$ids=[];foreach($plan['variants'] as $key=>$v){$id=$v['offerId']??(100+count($rows));$rows[$id]=$v;$ids[$key]=$id;$this->db->execute('UPDATE qa_generation SET body=?',[json_encode($rows)]);if($this->fail)throw new RuntimeException('Injected mid-write failure');}return $ids;}
    public function verify($before,$after,$plan,$ids){foreach($plan['variants'] as $key=>$v)if(Hash::hash($after['states'][$ids[$key]]['state'])!==Hash::hash($v['state']))throw new RuntimeException('Readback differs');}
};
$service=new DocumentPreparationCatalog($db,'site:s1','user:1',$repo,$port,fn()=>['qty'=>['visible'=>true]]);
$cmd=['action'=>'preparationCatalog','operation'=>'preview','id'=>'sheet','versionId'=>$version,'expectedRevision'=>$source['revision'],'storefrontId'=>'BASE','productKey'=>'42','resultIds'=>array_column($results,'id'),'newActive'=>false];
$plan=$service->command($cmd);ok($plan['ready']&&count($plan['rows'])===2,'Two immutable results ready');ok($port->writes===0,'Preview does not write');
$apply=array_replace($cmd,['operation'=>'apply','fingerprint'=>$plan['fingerprint']]);
$port->fail=true;reject(fn()=>$service->command($apply));ok(!$db->rows('SELECT * FROM b_pw_calc_preparation_offer')&&!$db->rows('SELECT * FROM b_pw_calc_preparation_write'),'Partial failure rolls back bindings and receipts');ok($db->rows('SELECT body FROM qa_generation')[0]['body']==='{}','Catalog rollback');$port->fail=false;
$r=$service->command($apply);ok(count($r['offerIds'])===2,'Real returned IDs bound');$writes=$port->writes;
$again=$service->command($apply);ok($again['replayed']&&$port->writes===$writes,'Exact replay does not call writer');
ok(count($db->rows('SELECT * FROM b_pw_calc_preparation_offer'))===2,'No duplicate binding');
$reopen=$service->command($cmd);ok($reopen['ready']&&array_column($reopen['rows'],'action')===['unchanged','unchanged'],'Reopen resolves linked IDs');
$preparation=$db->rows('SELECT * FROM b_pw_calc_preparation')[0];
$maintenanceHash=Hash::hash([$db->rows('SELECT * FROM b_pw_calc_preparation_offer ORDER BY variant_key'),$db->rows('SELECT * FROM b_pw_calc_preparation_write ORDER BY id')]);
reject(fn()=>$prep->removeOwnedPreparation($preparation['id'],(int)$preparation['revision'],$cmd['resultIds']));
reject(fn()=>$service->removeOwnedGeneration($preparation['id'],(int)$preparation['revision'],$maintenanceHash));
ok(count($db->rows('SELECT * FROM b_pw_calc_preparation_write'))===1,'Failed cleanup retains audit');
$v=array_values($r['variants'])[0];ok($v['state']['dimensions']['weight']===2.5432109876543,'Canonical output retained, no unitCount multiplier');ok($v['state']['purchasingPrice']['value']===100.12345679,'Production cost is purchase price, not direct80');
ok(in_array(10,array_column($v['state']['prices'],'quantityTo'),true),'Price ranges retained');
$other=new DocumentPreparationCatalog($db,'site:s1','user:2',$repo,$port,fn()=>['qty'=>['visible'=>true]]);reject(fn()=>$other->command($apply));
$db->execute('UPDATE b_pw_calc_preparation SET revision=revision+1');reject(fn()=>$service->command($apply));
$fresh=$service->command($cmd);$stale=array_replace($cmd,['operation'=>'apply','fingerprint'=>$fresh['fingerprint']]);
$db->execute('UPDATE b_pw_calc_preparation_result SET active=0 WHERE id=?',[$cmd['resultIds'][0]]);reject(fn()=>$service->command($stale));$db->execute('UPDATE b_pw_calc_preparation_result SET active=1');
$fresh=$service->command($cmd);$db->execute('UPDATE b_pw_calc_preparation_result SET payload_hash=? WHERE id=?',[str_repeat('0',64),$cmd['resultIds'][0]]);reject(fn()=>$service->command($cmd));
reject(fn()=>$service->command($cmd+['actor'=>'user:3']));reject(fn()=>$service->command(array_replace($cmd,['resultIds'=>[]])));
$db->begin();reject(fn()=>$service->command($cmd));ok($db->inTransaction(),'Nested call does not roll back caller transaction');$db->rollback();
$db->execute('UPDATE qa_generation SET body=?',['{}']);
reject(fn()=>$service->removeOwnedGeneration($preparation['id'],(int)$preparation['revision'],$maintenanceHash));
$service->removeOwnedGeneration($preparation['id'],(int)$preparation['revision']+1,$maintenanceHash);
ok(!$db->rows('SELECT * FROM b_pw_calc_preparation_offer')&&!$db->rows('SELECT * FROM b_pw_calc_preparation_write'),'Exact owner cleanup after native removal');
echo "PASS $checks preparation catalog SQL assertions\n";
