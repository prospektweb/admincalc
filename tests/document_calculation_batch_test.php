<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__).'/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__).'/lib/Documents/DocumentApplication.php';
$front = ($argv[1] ?? getenv('FRONTCALC_REPO') ?: dirname(__DIR__, 2).'/frontcalc').'/lib/Service/';
require_once $front.'CalculatorSchemaNormalizer.php';
require_once $front.'CalculatorConditionResolver.php';
require_once $front.'FormSectionState.php';
use Prospektweb\Calc\Documents\{PdoConnection,DocumentSchema,DocumentRepository,DocumentVersions};
$db=new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($db); DocumentSchema::install($db);
$repo=new DocumentRepository($db,'site:test','user:1');
$body=['contract'=>'prospektweb.calculator/document-v2','schemaVersion'=>2,'id'=>'batch-test','name'=>'Batch', 'stages'=>[],
 'form'=>['fields'=>[['fieldId'=>'qty','label'=>'Количество','type'=>'number','required'=>true,'options'=>[],'dimensionInputs'=>[]],['fieldId'=>'kind','label'=>'Основа','type'=>'select','required'=>true,'options'=>[['id'=>'a','label'=>'A'],['id'=>'b','label'=>'B']],'dimensionInputs'=>[]]],'sections'=>[['id'=>'main','fieldIds'=>['qty','kind']]]],
 'presentations'=>['views'=>[['id'=>'BASE','name'=>'Base']]]];
$repo->create(json_encode($body));$version=DocumentVersions::primaryId('batch-test');$repo->versions()->listing('batch-test');
$runtime=['formDefinition'=>$body['form'],'bindingDefinition'=>['bindings'=>[['fieldId'=>'qty','target'=>['propertyCode'=>'CALC_PROP_QTY']],['fieldId'=>'kind','target'=>['propertyCode'=>'CALC_PROP_KIND'],'optionMap'=>['a'=>'a','b'=>'b']]]],
 'storefronts'=>[['id'=>'BASE','runtimeSchema'=>['version'=>2,'fields'=>[
 ['property_code'=>'CALC_PROP_QTY','name'=>'Количество','selection_mode'=>'single','required'=>true,'inputs'=>[['code'=>'value','min'=>1,'max'=>1000]],'options'=>[]],
 ['property_code'=>'CALC_PROP_KIND','name'=>'Основа','selection_mode'=>'single','required'=>true,'inputs'=>[],'options'=>[['xml_id'=>'a','label'=>'A'],['xml_id'=>'b','label'=>'B']]]]]]]];
$reads=0;$price=10;$executions=0;$failure=false;$hook=null;
$core=function($c)use(&$executions,&$failure,&$hook){
 if($c['action']==='compile')return ['snapshotJson'=>json_encode(['resources'=>$c['resources'],'plan'=>['document'=>$c['document'],'emptyObject'=>(object)[],'emptyArray'=>[]]]),'runtimeFingerprint'=>str_repeat('a',64)];
 if(!is_object($c['publication']->plan->emptyObject)||!is_array($c['publication']->plan->emptyArray))throw new RuntimeException('JSON shape lost across packet persistence');
 $executions++; if($hook){$fn=$hook;$hook=null;$fn();} if($failure)throw new RuntimeException('QA execution failure');
 return ['result'=>['name'=>'Result','purchasingPrice'=>$c['publication']->resources[0]->price,'basePrice'=>20,'currency'=>'RUB']];
};
$resources=function()use(&$reads,&$price){$reads++;return [['price'=>$price]];};
$form=fn()=>$runtime;
$command=fn($r)=>$repo->batches()->command(['id'=>'batch-test','versionId'=>$version]+$r,$core,$resources,$form);
$candidate=fn($qty)=>json_decode(json_encode(['values'=>['qty'=>$qty,'kind'=>'a','section:main'=>true],'activation'=>(object)[],'execution'=>['unitCount'=>1,'layoutCount'=>1,'runCount'=>1,'deadlineType'=>'strict']]));
$n=0;$check=function($ok,$message)use(&$n){$n++;if(!$ok)throw new RuntimeException($message);};
$reject=function($fn)use($check){try{$fn();}catch(Throwable $e){$check(true,'rejected');return;}throw new RuntimeException('Expected rejection');};
$conditional=$runtime;
$conditional['storefronts'][0]['runtimeSchema']['fields'][0]['visible_when']=['mode'=>'all','conditions'=>[['property_code'=>'CALC_PROP_KIND','operator'=>'equals','values'=>['a']]]];
$conditional['storefronts'][]=['id'=>'other','runtimeSchema'=>['version'=>2,'fields'=>[['property_code'=>'CALC_PROP_KIND','visible_when'=>['mode'=>'all','conditions'=>[['property_code'=>'CALC_PROP_QTY','operator'=>'equals','values'=>['1']]]]]]]];
$check(\Prospektweb\Calc\Documents\DocumentBatchForm::controllers($conditional,'BASE')===['kind'],'controllers derived from selected storefront only');
$bad=$candidate(2);$bad->values->kind='b';
$reject(fn()=>\Prospektweb\Calc\Documents\DocumentBatchForm::validate($conditional,'BASE',$bad->values,$bad->activation,$bad->execution));
$bad=$candidate(2);$bad->values->{'unknown.input'}=1;
$reject(fn()=>\Prospektweb\Calc\Documents\DocumentBatchForm::validate($runtime,'BASE',$bad->values,$bad->activation,$bad->execution));
$bad=$candidate(2);$bad->values->{'section:main'}=false;
$reject(fn()=>\Prospektweb\Calc\Documents\DocumentBatchForm::validate($runtime,'BASE',$bad->values,$bad->activation,$bad->execution));
$bad=$candidate(2);$bad->activation->main='Y';
$reject(fn()=>\Prospektweb\Calc\Documents\DocumentBatchForm::validate($runtime,'BASE',$bad->values,$bad->activation,$bad->execution));
$bad=$candidate(2);$bad->values->kind='not-an-option';
$reject(fn()=>\Prospektweb\Calc\Documents\DocumentBatchForm::validate($runtime,'BASE',$bad->values,$bad->activation,$bad->execution));
$vary=$candidate(2);$vary->values->kind='b';
$restricted=$repo->batches()->command(['id'=>'batch-test','versionId'=>$version,'operation'=>'prepare','packetId'=>'controller-test-01','revision'=>1,'candidates'=>[$candidate(1),$vary]],$core,$resources,fn()=>$conditional);
$check($restricted['valid']===1&&$restricted['items'][1]['status']==='invalid','controller-dependent candidate excluded before start');
$interaction=$runtime;
$interaction['formDefinition']['fields'][]=['fieldId'=>'extra','label'=>'Зависимое поле','type'=>'select','required'=>false,'options'=>[['id'=>'x','label'=>'X']],'dimensionInputs'=>[]];
$interaction['formDefinition']['sections'][0]['fieldIds'][]='extra';
$interaction['bindingDefinition']['bindings'][]=['fieldId'=>'extra','target'=>['propertyCode'=>'CALC_PROP_EXTRA']];
$interaction['storefronts'][0]['runtimeSchema']['fields'][]=['property_code'=>'CALC_PROP_EXTRA','name'=>'Зависимое поле','required'=>false,'selection_mode'=>'single','options'=>[['xml_id'=>'x','label'=>'X']],
 'visible_when'=>['mode'=>'all','conditions'=>[['property_code'=>'CALC_PROP_QTY','operator'=>'equals','values'=>['2']],['property_code'=>'CALC_PROP_KIND','operator'=>'equals','values'=>['b']]]]];
$grid=[];foreach([1,2]as$qty)foreach(['a','b']as$kind){$c=$candidate($qty);$c->values->kind=$kind;$c->values->extra='';$grid[]=$c;}
$inter=$repo->batches()->command(['id'=>'batch-test','versionId'=>$version,'operation'=>'prepare','packetId'=>'interaction-test1','revision'=>1,'candidates'=>$grid],$core,$resources,fn()=>$interaction);
$check($inter['valid']===3&&$inter['items'][3]['status']==='invalid'&&str_contains($inter['items'][3]['error'],'Зависимое поле'),'all cross-product projections checked including joint interaction');
$reads=0;
$prepare=function($key,$items)use($command,$repo,$version){return $command(['operation'=>'prepare','packetId'=>$key,'revision'=>$repo->versions()->load('batch-test',$version)['revision'],'storefrontId'=>'BASE','candidates'=>$items]);};
$p=$prepare('packet-key-000001',[$candidate(1),$candidate(1),$candidate(2),$candidate(-1)]);
$check($p['total']===3&&$p['valid']===2&&$p['items'][2]['status']==='invalid','server candidate validation and typed dedup: '.json_encode($p));
$again=$prepare('packet-key-000001',[$candidate(1),$candidate(1),$candidate(2),$candidate(-1)]);$check($again===$p&&$reads===1,'duplicate prepare no resource reload');
$reject(fn()=>$prepare('packet-key-000001',[$candidate(3)]));
$run=fn($op)=>$command(['operation'=>$op,'packetId'=>$p['id']]);
$run('start');$price=900;$s=$run('step');$check($s['items'][0]['status']==='success','first success');
$snap=$repo->snapshots()->command('loadCalculationSnapshot','batch-test',$version,'BASE',$s['items'][0]['snapshotId']);
$check($snap['payload']['response']['result']['purchasingPrice']===10&&$reads===1,'frozen resource retained');
$failure=true;$s=$run('step');$check($s['status']==='completed'&&$s['items'][1]['status']==='error','partial failure');
$count=$executions;$run('step');$check($executions===$count,'completed delivery no replay');
$command(['operation'=>'retry','packetId'=>$p['id'],'itemIds'=>[1]]);$failure=false;$run('resume');$s=$run('step');
$check($s['items'][1]['status']==='success'&&$s['items'][0]['snapshotId']===$snap['id']&&$reads===1,'retry only failed and retain frozen resources');
$other=new DocumentRepository($db,'site:test','user:2');$reject(fn()=>$other->batches()->command(['id'=>'batch-test','versionId'=>$version,'operation'=>'status','packetId'=>$p['id']],$core,$resources,$form));
$p2=$prepare('packet-key-000002',[$candidate(1),$candidate(2)]);$command(['operation'=>'start','packetId'=>$p2['id']]);
$hook=fn()=>$command(['operation'=>'cancel','packetId'=>$p2['id']]);$s=$command(['operation'=>'step','packetId'=>$p2['id']]);
$check($s['status']==='cancelled'&&$s['items'][0]['status']==='success'&&$s['items'][1]['status']==='cancelled','cancel permits in-flight and forbids next');
$count=$executions;$command(['operation'=>'step','packetId'=>$p2['id']]);$check($executions===$count,'cancel stops claim');
$pc=$prepare('cancel-retry-0001',[$candidate(1),$candidate(2),$candidate(3)]);$command(['operation'=>'start','packetId'=>$pc['id']]);
$failure=true;$command(['operation'=>'step','packetId'=>$pc['id']]);$failure=false;
$command(['operation'=>'cancel','packetId'=>$pc['id']]);$command(['operation'=>'retry','packetId'=>$pc['id'],'itemIds'=>[0]]);
$command(['operation'=>'resume','packetId'=>$pc['id']]);$count=$executions;$s=$command(['operation'=>'step','packetId'=>$pc['id']]);
$check($executions===$count+1&&$s['status']==='completed'&&$s['items'][1]['status']==='cancelled'&&$s['items'][2]['status']==='cancelled','retry after cancellation executes only errors');
$p3=$prepare('packet-key-000003',[$candidate(1)]);$command(['operation'=>'start','packetId'=>$p3['id']]);
$hook=function()use($db,$p3,$command){$row=$db->rows('SELECT state_json FROM b_pw_calc_batch WHERE id = ?',[$p3['id']])[0];$s=json_decode($row['state_json'],true);$s['items'][0]['lease']=0;$db->execute('UPDATE b_pw_calc_batch SET state_json = ? WHERE id = ?',[json_encode($s),$p3['id']]);$command(['operation'=>'step','packetId'=>$p3['id']]);};
$before=count($repo->snapshots()->command('calculationSnapshots','batch-test',$version,'BASE')['items']);$s=$command(['operation'=>'step','packetId'=>$p3['id']]);
$check(count($repo->snapshots()->command('calculationSnapshots','batch-test',$version,'BASE')['items'])===$before+1,'expired worker cannot commit after new claim');
$p4=$prepare('packet-key-000004',[$candidate(1)]);$command(['operation'=>'start','packetId'=>$p4['id']]);
$hook=function()use($repo,$version,$body){$body['name']='Changed formulas revision';$repo->versions()->save('batch-test',$version,1,json_encode($body));};
$s=$command(['operation'=>'step','packetId'=>$p4['id']]);$check($s['status']==='stopped'&&$s['items'][0]['status']==='error','revision race stops without wrong snapshot');
$check(count($repo->snapshots()->command('calculationSnapshots','batch-test',$version,'BASE')['items'])===$before+1,'prior snapshots retained');
$p5=$prepare('packet-key-000005',[$candidate(1)]);$command(['operation'=>'start','packetId'=>$p5['id']]);
$before=count($repo->snapshots()->command('calculationSnapshots','batch-test',$version,'BASE')['items']);
$db->execute("CREATE TRIGGER fail_batch_completion BEFORE UPDATE ON b_pw_calc_batch WHEN NEW.state_json LIKE '%success%' AND NEW.id = '".$p5['id']."' BEGIN SELECT RAISE(ABORT, 'completion failure'); END");
$reject(fn()=>$command(['operation'=>'step','packetId'=>$p5['id']]));
$check(count($repo->snapshots()->command('calculationSnapshots','batch-test',$version,'BASE')['items'])===$before,'snapshot insert rolled back with completion failure');
$db->execute('DROP TRIGGER fail_batch_completion');
$s=$command(['operation'=>'status','packetId'=>$p5['id']]);$check($s['items'][0]['status']==='running','recoverable claim retained after transaction rollback');
$p6=$prepare('packet-key-000006',[$candidate(1)]);
$check($command(['operation'=>'latest'])['packet']['id']===$p5['id'],'reload prioritizes unfinished packet over a newer preparation');
$reject(fn()=>$command(['operation'=>'start','packetId'=>$p6['id']]));
$command(['operation'=>'cancel','packetId'=>$p5['id']]);$command(['operation'=>'start','packetId'=>$p6['id']]);
$hook=function()use($db,$version){
 $row=$db->rows('SELECT * FROM b_pw_calc_snapshot LIMIT 1')[0];
 $count=(int)$db->rows('SELECT COUNT(*) total FROM b_pw_calc_snapshot')[0]['total'];
 for($i=$count;$i<500;$i++){$copy=$row;$copy['id']='capacity-'.$i;$db->execute('INSERT INTO b_pw_calc_snapshot ('.implode(',',array_keys($copy)).') VALUES ('.implode(',',array_fill(0,count($copy),'?')).')',array_values($copy));}
};
$s=$command(['operation'=>'step','packetId'=>$p6['id']]);
$check($s['status']==='stopped'&&$s['items'][0]['status']==='error','capacity race checked on commit');
$check((int)$db->rows('SELECT COUNT(*) total FROM b_pw_calc_snapshot')[0]['total']===500,'capacity never exceeded');
$reject(fn()=>$prepare('packet-key-000007',[$candidate(1)]));
$check($command(['operation'=>'latest'])['packet']!==null,'reload discovers packet without browser storage');
echo "PASS $n batch checks\n";
