<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__).'/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__).'/lib/Documents/DocumentProductPreparation.php';
use Prospektweb\Calc\Documents\{PdoConnection,DocumentSchema,DocumentRepository,DocumentVersions,DocumentProductPreparation,SiteConnection};
$db=new PdoConnection(new PDO('sqlite::memory:'));DocumentSchema::install($db);
$repo=new DocumentRepository($db,'site:test','user:1');$other=new DocumentRepository($db,'site:test','user:2');
$json=fn($v)=>json_encode($v,JSON_THROW_ON_ERROR);$checks=0;
$ok=function($condition,$label)use(&$checks){$checks++;if(!$condition)throw new RuntimeException($label);};
$reject=function($fn,$code=409)use($ok){try{$fn();}catch(Throwable $e){$ok($e->getCode()===$code,$e->getMessage());return;}throw new RuntimeException('Expected rejection');};
$body=['contract'=>'prospektweb.calculator/document-v1','schemaVersion'=>1,'id'=>'sheet','name'=>'Sheet',
    'form'=>['fields'=>[['fieldId'=>'qty','type'=>'number','unit'=>'pcs']], 'sections'=>[]],
    'presentations'=>['views'=>[['id'=>'BASE','name'=>'Base'],['id'=>'other','name'=>'Other']]],'pricing'=>['types'=>[]]];
$site=['contract'=>SiteConnection::CONTRACT,'provider'=>'bitrix:test','productsCatalog'=>'14','offersCatalog'=>'15',
    'products'=>[['key'=>'42','presentationId'=>'BASE'],['key'=>'43','presentationId'=>'other']],
    'priceTypes'=>[],'formBindings'=>(object)[],'inputMappings'=>[],'outputMappings'=>[]];
$repo->create($json($body));$version=DocumentVersions::primaryId('sheet');$repo->versions()->listing('sheet');$repo->save('sheet',1,$json($body),$json($site),true);
$accessible=true;$read=function($q,$ids)use(&$accessible){return $accessible?array_map(fn($id)=>['key'=>$id,'name'=>'Product '.$id],$ids):[];};
$service=new DocumentProductPreparation($db,'site:test','user:1',$repo,'bitrix:test','14',$read);
$otherService=new DocumentProductPreparation($db,'site:test','user:2',$other,'bitrix:test','14',$read);
$command=function($op,$fields=[])use($repo,$version){return ['action'=>'productPreparation','operation'=>$op,'id'=>'sheet','versionId'=>$version,'expectedRevision'=>$repo->versions()->load('sheet',$version)['revision'],'storefrontId'=>'BASE']+$fields;};
$call=fn($op,$fields=[])=>$service->command($command($op,$fields));
$capture=function($qty=100,$price=10,$owner=null)use($repo,$version){$source=$repo->versions()->load('sheet',$version);return ($owner??$repo)->snapshots()->capture('sheet',$version,$source,
    ['source'=>['documentId'=>'sheet','versionId'=>$version,'revision'=>$source['revision'],'bodyHash'=>$source['bodyHash']], 'result'=>['name'=>'Result '.$qty,'purchasingPrice'=>$price,'basePrice'=>$price+3,'currency'=>'RUB']],
    ['values'=>(object)['qty'=>$qty],'sectionActivation'=>(object)['main'=>true],'execution'=>(object)['unitCount'=>$qty,'layoutCount'=>1,'runCount'=>1,'deadlineType'=>'urgent']],[]);};
$board=fn($owner=null)=>($owner??$repo)->snapshots()->groups('sheet',$version,[]);
$groupChange=function($op,$args=[])use($repo,$version,$board){return $repo->snapshots()->groups('sheet',$version,['operation'=>$op,'expectedState'=>$board()['stateHash']]+$args);};
$a=$capture();$g=$groupChange('create')['groups'][0]['id'];$fields=['productKey'=>'42','groupId'=>$g,'snapshotIds'=>[$a]];
$targets=$call('targets',['groupId'=>$g,'query'=>'42']);$ok(count($targets['items'])===1&&$targets['items'][0]['key']==='42','only linked same-view product');
$ok(!$call('targets',['groupId'=>$g,'query'=>'43'])['items'],'other-view product excluded');
$plan=$call('preview',$fields);$ok($plan['counts']===['new'=>1,'already'=>0,'conflicts'=>0],'initial counters');
$foreign=new DocumentProductPreparation($db,'site:foreign','user:1',new DocumentRepository($db,'site:foreign','user:1'),'bitrix:test','14',$read);
$reject(fn()=>$foreign->command($command('list',['productKey'=>'42'])),404);
$reject(fn()=>$otherService->command($command('preview',$fields)),404);
$reject(fn()=>$call('list',['productKey'=>'43']));
$accessible=false;$reject(fn()=>$call('transfer',$fields+['fingerprint'=>$plan['fingerprint'],'choices'=>(object)[]]));$accessible=true;
$db->execute("CREATE TRIGGER fail_copy BEFORE INSERT ON b_pw_calc_preparation_result BEGIN SELECT RAISE(ABORT, 'copy rollback'); END");
try{$call('transfer',$fields+['fingerprint'=>$plan['fingerprint'],'choices'=>(object)[]]);throw new RuntimeException('Expected SQL failure');}catch(PDOException $e){}
$ok(!$db->rows('SELECT * FROM b_pw_calc_preparation')&&!$db->rows('SELECT * FROM b_pw_calc_group_target'),'SQL rollback leaves no preparation or link');$db->execute('DROP TRIGGER fail_copy');
$before=$repo->versions()->load('sheet',$version);$call('transfer',$fields+['fingerprint'=>$plan['fingerprint'],'choices'=>(object)[]]);
$ok($before===$repo->versions()->load('sheet',$version),'transfer never saves form/connection');
$list=$call('list',['productKey'=>'42']);$ok(count($list['items'])===1&&$list['items'][0]['active'],'first active');
$loaded=$call('load',['productKey'=>'42','resultId'=>$list['items'][0]['id']]);$ok($loaded['payload']->values->qty===100,'full input copied');
$ok($call('targets',['groupId'=>$g,'query'=>''])['linkedProductKey']==='42','group target persisted');
$repeat=$call('preview',$fields);$ok($repeat['counts']===['new'=>0,'already'=>1,'conflicts'=>0],'repeat no duplicate');
$call('transfer',$fields+['fingerprint'=>$repeat['fingerprint'],'choices'=>(object)[]]);$ok(count($call('list',['productKey'=>'42'])['items'])===1,'repeat idempotent');
$shared=$otherService->command($command('list',['productKey'=>'42']));$ok($shared['items']===$list['items'],'preparation shared while groups remain private');
// Formula/tariff/source revision change is provenance, not variant identity.
$body['calculations']=['formula'=>'different tariff'];$head=$repo->versions()->load('sheet',$version);$repo->versions()->save('sheet',$version,$head['revision'],$json($body));
$b=$capture(100,25);$groupChange('assign',['snapshotId'=>$b,'groupId'=>$g]);$fields['snapshotIds']=[$b];
$conflict=$call('preview',$fields);$ok($conflict['counts']['conflicts']===1&&$conflict['variants'][0]['key']===$list['items'][0]['variantKey'],'same input new formula/price is same variant conflict');
$reject(fn()=>$call('transfer',$fields+['fingerprint'=>$conflict['fingerprint'],'choices'=>(object)[]]),0);
$key=$conflict['variants'][0]['key'];
$call('transfer',$fields+['fingerprint'=>$conflict['fingerprint'],'choices'=>[$key=>$a]]);
$kept=$call('list',['productKey'=>'42']);$ok(count($kept['items'])===2&&array_values(array_filter($kept['items'],fn($r)=>$r['active']))[0]['snapshotId']===$a,'keep archives candidate without replacing active');
$c=$capture(100,30);$groupChange('assign',['snapshotId'=>$c,'groupId'=>$g]);$fields['snapshotIds']=[$c];$replace=$call('preview',$fields);
$stable=$db->rows('SELECT * FROM b_pw_calc_preparation_result ORDER BY id');
$db->execute("CREATE TRIGGER fail_history BEFORE INSERT ON b_pw_calc_preparation_history BEGIN SELECT RAISE(ABORT, 'history rollback'); END");
try{$call('transfer',$fields+['fingerprint'=>$replace['fingerprint'],'choices'=>[$key=>$c]]);throw new RuntimeException('Expected SQL failure');}catch(PDOException $e){}
$ok($stable===$db->rows('SELECT * FROM b_pw_calc_preparation_result ORDER BY id'),'failed replace restores old active/full history');$db->execute('DROP TRIGGER fail_history');
$call('transfer',$fields+['fingerprint'=>$replace['fingerprint'],'choices'=>[$key=>$c]]);
$latest=$call('list',['productKey'=>'42']);$ok(count($latest['items'])===3&&array_values(array_filter($latest['items'],fn($r)=>$r['active']))[0]['snapshotId']===$c,'explicit replace keeps all old results');
$ok(count($db->rows('SELECT * FROM b_pw_calc_preparation_history'))===3,'decision history retained');
$d=$capture(200);$groupChange('assign',['snapshotId'=>$d,'groupId'=>$g]);$fields['snapshotIds']=[$d];$p=$call('preview',$fields);
$ok($p['counts']['new']===1&&$p['variants'][0]['key']!==$key,'new input is new variant');
$groupChange('rename',['groupId'=>$g,'name'=>'Changed elsewhere']);$reject(fn()=>$call('transfer',$fields+['fingerprint'=>$p['fingerprint'],'choices'=>(object)[]]));
$p=$call('preview',$fields);$call('transfer',$fields+['fingerprint'=>$p['fingerprint'],'choices'=>(object)[]]);
// Another actor's preparation update invalidates an old preview.
$e=$capture(300);$fields['groupId']=null;$fields['snapshotIds']=[$e];$stale=$call('preview',$fields);
$f=$capture(400,12,$other);$their=['productKey'=>'42','groupId'=>null,'snapshotIds'=>[$f]];
$p2=$otherService->command($command('preview',$their));$otherService->command($command('transfer',$their+['fingerprint'=>$p2['fingerprint'],'choices'=>(object)[]]));
$reject(fn()=>$call('transfer',$fields+['fingerprint'=>$stale['fingerprint'],'choices'=>(object)[]]));
// Sources removed or incompatible: copied records survive; foreign old receipts can be rescued.
$oldOther=$capture(500,12,$other);$beforeCount=count($call('list',['productKey'=>'42'])['items']);
$body['form']['fields'][0]['unit']='kg';$head=$repo->versions()->load('sheet',$version);$repo->versions()->save('sheet',$version,$head['revision'],$json($body),null,false,true);
$ok(!$board()['items']&&!$board()['groups'],'reset clears own source');
$after=$call('list',['productKey'=>'42']);$ok(count($after['items'])===$beforeCount&&count(array_filter($after['items'],fn($r)=>$r['needsReview']))===$beforeCount,'independent preparation survives incompatible reset');
$rescue=['productKey'=>'42','groupId'=>null,'snapshotIds'=>[$oldOther]];$rp=$otherService->command($command('preview',$rescue));
$ok($rp['variants'][0]['candidates'][0]['needsReview'],'old incompatible source marked review');
$otherService->command($command('transfer',$rescue+['fingerprint'=>$rp['fingerprint'],'choices'=>(object)[]]));
$ok(count($call('list',['productKey'=>'42'])['items'])===$beforeCount+1,'old revision transfer accepted');
$other->snapshots()->groups('sheet',$version,['operation'=>'clear','expectedState'=>$board($other)['stateHash']]);
$ok(count($call('list',['productKey'=>'42'])['items'])===$beforeCount+1,'clear sources retains copies');
$preserved=$db->rows('SELECT * FROM b_pw_calc_preparation_result ORDER BY id');
$site['products'][0]['presentationId']='other';$head=$repo->versions()->load('sheet',$version);$repo->versions()->save('sheet',$version,$head['revision'],$head['bodyJson'],$json($site),true);
$reject(fn()=>$call('list',['productKey'=>'42']));$ok($preserved===$db->rows('SELECT * FROM b_pw_calc_preparation_result ORDER BY id'),'reassignment denies access without deleting or moving preparation');
DocumentSchema::install($db);$ok($preserved===$db->rows('SELECT * FROM b_pw_calc_preparation_result ORDER BY id'),'schema reinstall preserves preparation');
$p=(object)['values'=>(object)['b'=>2,'a'=>1],'activation'=>(object)[],'execution'=>(object)['unitCount'=>1,'layoutCount'=>1,'runCount'=>1,'deadlineType'=>'urgent','revision'=>9,'runtimeId'=>'first']];
$k=DocumentProductPreparation::variantKey('form',$p);$p->values=(object)['a'=>1,'b'=>2];$p->execution->revision=10;$p->execution->runtimeId='second';
$ok($k===DocumentProductPreparation::variantKey('form',$p),'object order and runtime metadata cannot change variant');
$p->values->sequence=[1,2];$k=DocumentProductPreparation::variantKey('form',$p);$p->values->sequence=[2,1];$ok($k!==DocumentProductPreparation::variantKey('form',$p),'ordered arrays preserved');
$mixed=$db->rows('SELECT * FROM b_pw_calc_preparation')[0];
$reject(fn()=>$service->removeOwnedPreparation($mixed['id'],(int)$mixed['revision'],array_column($preserved,'id')));
$source=$repo->versions()->load('sheet',$version);
$qaCapture=function($price)use($repo,$version,$source){return $repo->snapshots()->capture('sheet',$version,$source,
    ['source'=>['documentId'=>'sheet','versionId'=>$version,'revision'=>$source['revision'],'bodyHash'=>$source['bodyHash']],'result'=>['name'=>'QA','purchasingPrice'=>$price,'basePrice'=>$price+2,'currency'=>'RUB']],
    ['values'=>(object)['qty'=>10],'sectionActivation'=>(object)[],'storefrontId'=>'other','execution'=>(object)['unitCount'=>10,'layoutCount'=>1,'runCount'=>1,'deadlineType'=>'urgent']],[]);};
$qa1=$qaCapture(1);$qa2=$qaCapture(2);
$qaCommand=fn($op,$f)=>array_replace($command($op,$f),['storefrontId'=>'other']);
$qf=['productKey'=>'43','groupId'=>null,'snapshotIds'=>[$qa1,$qa2]];$qp=$service->command($qaCommand('preview',$qf));
$ok($qp['counts']['conflicts']===2&&count($qp['variants'])===1,'same-input candidates in one batch require one explicit winner');
$service->command($qaCommand('transfer',$qf+['fingerprint'=>$qp['fingerprint'],'choices'=>[$qp['variants'][0]['key']=>$qa2]]));
$ql=$service->command($qaCommand('list',['productKey'=>'43']));$ok(count($ql['items'])===2&&count(array_filter($ql['items'],fn($i)=>$i['active']))===1,'one active result per variant');
$reject(fn()=>$service->removeOwnedPreparation($ql['preparationId'],$ql['revision'],[$ql['items'][0]['id']]));
$db->execute("CREATE TRIGGER fail_remove BEFORE DELETE ON b_pw_calc_preparation_result BEGIN SELECT RAISE(ABORT, 'remove rollback'); END");
$history=$db->rows('SELECT * FROM b_pw_calc_preparation_history ORDER BY id');
try{$service->removeOwnedPreparation($ql['preparationId'],$ql['revision'],array_column($ql['items'],'id'));throw new RuntimeException('Expected SQL failure');}catch(PDOException $e){}
$ok($history===$db->rows('SELECT * FROM b_pw_calc_preparation_history ORDER BY id'),'internal cleanup rollback restores history');$db->execute('DROP TRIGGER fail_remove');
$service->removeOwnedPreparation($ql['preparationId'],$ql['revision'],array_column($ql['items'],'id'));
$ok(!$service->command($qaCommand('list',['productKey'=>'43']))['items'],'internal exact cleanup succeeds');
$preserved=$db->rows('SELECT * FROM b_pw_calc_preparation_result ORDER BY id');
$preview=$repo->lifecycle()->preview('sheet');$repo->lifecycle()->delete('sheet',$preview['revision'],$preview['name']);
$ok($preserved===$db->rows('SELECT * FROM b_pw_calc_preparation_result ORDER BY id'),'document/version lifecycle has no cascade into independent preparation');
echo "PASS $checks product preparation checks\n";
