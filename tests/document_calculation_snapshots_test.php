<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentApplication.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentRepository, DocumentVersions, DocumentCalculationSnapshots};
$db = new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($db);
$repo = new DocumentRepository($db, 'site:test', 'user:1');
$other = new DocumentRepository($db, 'site:test', 'user:2');
$foreign = new DocumentRepository($db, 'site:other', 'user:1');
$checks = 0;
$check = function(bool $ok, string $message) use (&$checks) { $checks++; if (!$ok) throw new RuntimeException($message); };
$reject = function(callable $fn, int $code) use ($check) { try { $fn(); } catch (Throwable $e) { $check($e->getCode() === $code, $e->getMessage()); return; } throw new RuntimeException('Expected rejection'); };
$body = ['contract'=>'prospektweb.calculator/document-v1','schemaVersion'=>1,'id'=>'test','name'=>'Test',
    'form'=>['fields'=>[['fieldId'=>'qty','label'=>'Quantity','type'=>'number','unit'=>'pcs','required'=>true,'options'=>[], 'control'=>['min'=>1]], ['fieldId'=>'paper','type'=>'select','options'=>[['id'=>'a','label'=>'A'],['id'=>'b','label'=>'B']]]],
        'sections'=>[['id'=>'main','title'=>'Main','fieldIds'=>['qty','paper']]]],
    'presentations'=>['views'=>[['id'=>'BASE','name'=>'Base'], ['id'=>'other','name'=>'Other']]], 'calculations'=>[['formulas'=>['price'=>10]]]];
$json = fn($value) => json_encode($value, JSON_THROW_ON_ERROR);
$repo->create($json($body)); $version=DocumentVersions::primaryId('test');
$capture = function($owner, string $view='BASE') use ($repo,$version) {
    $source=$repo->versions()->load('test',$version);
    return $owner->snapshots()->capture('test',$version,$source,['source'=>['documentId'=>'test','versionId'=>$version,'revision'=>$source['revision'],'bodyHash'=>$source['bodyHash']],
        'result'=>['name'=>'Exact report title','purchasingPrice'=>12,'basePrice'=>17,'currency'=>'RUB']],
        ['values'=>(object)['qty'=>100], 'sectionActivation'=>(object)['main'=>true], 'execution'=>(object)['unitCount'=>100], 'storefrontId'=>$view], []);
};
$list = fn($owner, $view='BASE') => $owner->snapshots()->command('calculationSnapshots','test',$version,$view)['items'];
$key=$capture($repo);$otherKey=$capture($other);$capture($repo,'other');
$check(count($list($repo))===1 && count($list($repo,'other'))===1 && count($list($other))===1,'actor/storefront isolation');
$reject(fn()=>$list($foreign),404);
$reject(fn()=>$other->snapshots()->command('loadCalculationSnapshot','test',$version,'BASE',$key),404);
$saved=$repo->snapshots()->command('loadCalculationSnapshot','test',$version,'BASE',$key);
$check($saved['payload']['values']['qty']===100 && $saved['payload']['response']['result']['basePrice']===17,'full input/result preserved');
$check($saved['name']==='Exact report title','name taken from server report');
$cosmetic=$body;$cosmetic['name']='Renamed';$cosmetic['form']['fields'][0]['label']='New label';$cosmetic['form']['fields'][0]['help']='Help';
$cosmetic['form']['fields']=array_reverse($cosmetic['form']['fields']);$cosmetic['form']['sections'][0]['fieldIds']=array_reverse($cosmetic['form']['sections'][0]['fieldIds']);
$cosmetic['calculations'][0]['formulas']['price']=20;
$repo->versions()->save('test',$version,1,$json($cosmetic));
$check(count($list($repo))===1 && $list($repo)[0]['compatible'],'cosmetic and formula edits preserve snapshots');
$check($repo->snapshots()->command('loadCalculationSnapshot','test',$version,'BASE',$key)['payload']===$saved['payload'],'saved report bytes unchanged after formula edit');
foreach (['type','unit','multiple','required','min','visibleWhen','requiredWhen','options'] as $attribute) {
    $changed=$body;$changed['form']['fields'][0][$attribute]=['changed'];
    $check(DocumentCalculationSnapshots::signature($json($body))!==DocumentCalculationSnapshots::signature($json($changed)), 'semantic guard: '.$attribute);
}
$changed=$cosmetic;$changed['form']['fields'][1]['control']['min']=2;
$reject(fn()=>$repo->versions()->save('test',$version,2,$json($changed)),409);
$check(count($list($repo))===1 && $repo->versions()->load('test',$version)['revision']===2,'cancelled save preserves list/head');
$reject(fn()=>$repo->versions()->save('test',$version,1,$json($changed),null,false,true),409);
$check(count($list($repo))===1,'failed CAS preserves list');
$db->execute("CREATE TRIGGER fail_snapshot_save BEFORE INSERT ON b_pw_calc_revision BEGIN SELECT RAISE(ABORT, 'test failure after snapshot guard'); END");
try { $repo->versions()->save('test',$version,2,$json($changed),null,false,true); throw new RuntimeException('Expected SQL failure'); } catch (PDOException $e) {}
$check(count($list($repo))===1 && $repo->versions()->load('test',$version)['revision']===2,'SQL failure after guard rolls back deletion');
$db->execute('DROP TRIGGER fail_snapshot_save');
$late=$repo->versions()->load('test',$version);
$repo->versions()->save('test',$version,2,$json($changed),null,false,true);
$check(!$list($repo) && !$list($repo,'other'),'confirmed save atomically clears affected owner lists');
$check(count($list($other))===1 && !$list($other)[0]['compatible'],'other actor retains incompatible receipt');
$reject(fn()=>$other->snapshots()->command('loadCalculationSnapshot','test',$version,'BASE',$otherKey),409);
$reject(fn()=>$repo->snapshots()->capture('test',$version,$late,[],['execution'=>[]],[]),409);
$reject(fn()=>$capture($other),409);
$other->snapshots()->command('clearIncompatibleCalculationSnapshots','test',$version,'BASE');
$check(!$list($other),'explicit stale cleanup');
$capture($other);
$check(!$list($repo),'late result cannot enter new list');
$key=$capture($repo);$capture($repo);
$repo->snapshots()->command('deleteCalculationSnapshot','test',$version,'BASE',$key);
$check(count($list($repo))===1,'delete only selected receipt');
$repo->snapshots()->command('clearCalculationSnapshots','test',$version,'BASE');
$check(!$list($repo) && count($list($other))===1,'clear is actor scoped');
// The application returns a successful report even when capture loses a race.
$core = function(array $command) use ($repo,$version) {
    $head=$repo->versions()->load('test',$version);
    $body=json_decode($head['bodyJson'],true);$body['name'].=' edited during preview';
    $repo->versions()->save('test',$version,$head['revision'],json_encode($body,JSON_THROW_ON_ERROR));
    return ['result'=>['calculatorId'=>'test','name'=>'Successful old result','purchasingPrice'=>12,'basePrice'=>17,'currency'=>'RUB']];
};
$app=new \Prospektweb\Calc\Documents\DocumentApplication($repo,$core,fn()=>[]);
$head=$repo->versions()->load('test',$version);
$response=$app->command(['action'=>'previewVersion','id'=>'test','versionId'=>$version,'revision'=>$head['revision'],'captureSnapshot'=>true,'execution'=>(object)['unitCount'=>100],'values'=>(object)[]]);
$check(isset($response['snapshotError']) && $response['result']['basePrice']===17 && !isset($response['failure']),'capture race preserves successful result and reports save error separately');
$check(!$list($repo),'failed capture inserts nothing');
$key=$capture($repo);$second=$capture($repo);
$db->execute('UPDATE b_pw_calc_snapshot SET payload_json = ? WHERE id = ?', ['corrupted test payload',$second]);
$check(count($list($repo))===2,'list reads compact metadata, no payload parsing');
$check($repo->snapshots()->command('loadCalculationSnapshot','test',$version,'BASE',$key)['payload']['response']['result']['basePrice']===17,'load selects only its own payload');
$reject(fn()=>$repo->snapshots()->command('loadCalculationSnapshot','test',$version,'BASE',$second),0);
$repo->snapshots()->command('clearCalculationSnapshots','test',$version,'BASE');
$hugeCore=fn()=>['result'=>['calculatorId'=>'test','name'=>str_repeat('x',8000001),'purchasingPrice'=>12,'basePrice'=>17,'currency'=>'RUB']];
$app=new \Prospektweb\Calc\Documents\DocumentApplication($repo,$hugeCore,fn()=>[]);
$head=$repo->versions()->load('test',$version);
$response=$app->command(['action'=>'previewVersion','id'=>'test','versionId'=>$version,'revision'=>$head['revision'],'captureSnapshot'=>true,'execution'=>(object)['unitCount'=>100],'values'=>(object)[]]);
$check(isset($response['snapshotError']) && $response['result']['basePrice']===17 && !$list($repo),'size limit preserves result without partial insert');
echo "PASS $checks snapshot checks\n";
