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

$board = fn($owner = null) => ($owner ?? $repo)->snapshots()->groups('test', $version, []);
$change = function(string $operation, array $args = [], $owner = null) use ($repo, $version, $board) {
    $owner ??= $repo;
    return $owner->snapshots()->groups('test', $version, ['operation'=>$operation, 'expectedState'=>$board($owner)['stateHash']] + $args);
};
$a=$capture($repo); $b=$capture($repo,'other'); $private=$capture($other);
$beforePayload=$repo->snapshots()->command('loadCalculationSnapshot','test',$version,'BASE',$a)['payload'];
$first=$change('create'); $check(count($first['groups'])===2 && $first['createdGroups']===2,'all storefronts grouped atomically');
$check(count($board($other)['items'])===1 && !$board($other)['groups'],'actor isolation');
$reject(fn()=>$board($foreign),404);
$ga=$first['items'][array_search($a,array_column($first['items'],'id'))]['groupId'];
$gb=$first['items'][array_search($b,array_column($first['items'],'id'))]['groupId'];
$check($ga!==$gb,'separate groups per storefront');
$check($change('create')['createdGroups']===0,'no duplicate groups with no new receipts');
$c=$capture($repo);$check($board()['items'][array_search($c,array_column($board()['items'],'id'))]['groupId']===null,'new receipt always ungrouped');
$second=$change('create');$gc=$second['items'][array_search($c,array_column($second['items'],'id'))]['groupId'];
$check($gc!==$ga && count($second['groups'])===3,'multiple groups of same storefront');
$change('rename',['groupId'=>$ga,'name'=>'First group']);$change('collapse',['groupId'=>$ga,'collapsed'=>true]);
$order=[$gc,$gb,$ga];$changed=$change('reorder',['groupIds'=>$order]);
$check(array_column($changed['groups'],'id')===$order && $changed['groups'][2]['name']==='First group' && $changed['groups'][2]['collapsed'],'name/collapse/order persisted');
$reopened=new DocumentRepository($db,'site:test','user:1');$check($board($reopened)===$board(),'reopened repository reads same state');
$reject(fn()=>$change('reorder',['groupIds'=>[$ga,$ga,$gc]]),0);
$stale=$board()['stateHash'];$change('collapse',['groupId'=>$ga,'collapsed'=>false]);
$reject(fn()=>$repo->snapshots()->groups('test',$version,['operation'=>'reorder','expectedState'=>$stale,'groupIds'=>$order]),409);
$check(!$board()['groups'][2]['collapsed'],'stale tab cannot overwrite fresh state');
$reject(fn()=>$change('assign',['snapshotId'=>$a,'groupId'=>$gb]),409);
$reject(fn()=>$change('assign',['snapshotId'=>$private,'groupId'=>$ga]),404);
$reject(fn()=>$change('dissolve',['groupId'=>$ga],$other),404);
$change('exclude',['snapshotId'=>$a]);$check(count($db->rows('SELECT * FROM b_pw_calc_snapshot_member WHERE snapshot_id = ?',[$a]))===0,'exclude keeps receipt without member');
$change('assign',['snapshotId'=>$a,'groupId'=>$gc]);
$check($repo->snapshots()->command('loadCalculationSnapshot','test',$version,'BASE',$a)['payload']===$beforePayload,'group actions never rewrite immutable payload');
$db->execute("CREATE TRIGGER fail_member BEFORE INSERT ON b_pw_calc_snapshot_member BEGIN SELECT RAISE(ABORT, 'test rollback'); END");
$stable=$board();try{$change('assign',['snapshotId'=>$a,'groupId'=>$ga]);throw new RuntimeException('expected fail');}catch(PDOException $e){}
$check($board()===$stable,'failed member move restores previous membership');
$d=$capture($repo);$stable=$board();try{$change('create');throw new RuntimeException('expected fail');}catch(PDOException $e){}
$check($board()===$stable,'failed create rolls back new folder and membership');$db->execute('DROP TRIGGER fail_member');
$change('dissolve',['groupId'=>$gc]);$check(count($board()['items'])===4 && count($board()['groups'])===2,'dissolve retains all receipts');
$change('delete',['snapshotId'=>$b]);$check(!$db->rows('SELECT * FROM b_pw_calc_snapshot_member WHERE snapshot_id = ?',[$b]),'delete cascades membership');
// Capture interleaving makes an old create request stale rather than silently grouping hidden new rows.
$stale=$board()['stateHash'];$capture($repo);$reject(fn()=>$repo->snapshots()->groups('test',$version,['operation'=>'create','expectedState'=>$stale]),409);
$change('create');$old=$board();
$changedBody=$body;$changedBody['form']['fields'][0]['unit']='kg';
$reject(fn()=>$repo->versions()->save('test',$version,1,$json($changedBody)),409);
$check($board()===$old,'cancelled compatibility reset preserves groups');
$db->execute("CREATE TRIGGER fail_save BEFORE INSERT ON b_pw_calc_revision BEGIN SELECT RAISE(ABORT, 'test rollback'); END");
try{$repo->versions()->save('test',$version,1,$json($changedBody),null,false,true);throw new RuntimeException('expected fail');}catch(PDOException $e){}
$check($board()===$old,'failed version save restores groups/members/snapshots');$db->execute('DROP TRIGGER fail_save');
$repo->versions()->save('test',$version,1,$json($changedBody),null,false,true);
$check(!$board()['items'] && !$board()['groups'],'confirmed reset removes groups/members with receipts');
$check(count($board($other)['items'])===1 && !$board($other)['items'][0]['compatible'],'other actor receipts survive incompatible save');
$skip=$change('create',[],$other);$check($skip['createdGroups']===0 && count($skip['skippedStorefronts'])===1,'incompatible skips explicit, no empty groups');
$change('clearIncompatible',['storefrontId'=>'BASE'],$other);$check(!$board($other)['items'],'addressed incompatible cleanup');
$capture($repo);$change('create');$capture($other);
$change('clear');$check(!$board()['items'] && !$board()['groups'] && count($board($other)['items'])===1,'global clear isolated to owner');
$check(!$db->rows('SELECT * FROM b_pw_calc_snapshot_member'),'no dangling membership');
DocumentSchema::install($db);$check(count($board($other)['items'])===1,'schema reinstall preserves receipts');
// Empty folders retain user-authored metadata until the same reset confirmation.
$emptySnapshot=$capture($repo);$emptyBoard=$change('create');$emptyGroup=$emptyBoard['groups'][0]['id'];
$change('rename',['groupId'=>$emptyGroup,'name'=>'Keep empty metadata']);$change('collapse',['groupId'=>$emptyGroup,'collapsed'=>true]);
$change('delete',['snapshotId'=>$emptySnapshot]);$emptyBefore=$board();
$head=$repo->versions()->load('test',$version);$emptyChanged=json_decode($head['bodyJson'],true);$emptyChanged['form']['fields'][0]['unit']='mm';
$reject(fn()=>$repo->versions()->save('test',$version,$head['revision'],$json($emptyChanged)),409);
$check($board()===$emptyBefore && !$board()['items'],'unconfirmed reset preserves empty folder name/order/collapse');
$db->execute("CREATE TRIGGER fail_empty_save BEFORE INSERT ON b_pw_calc_revision BEGIN SELECT RAISE(ABORT, 'empty folder rollback'); END");
try{$repo->versions()->save('test',$version,$head['revision'],$json($emptyChanged),null,false,true);throw new RuntimeException('expected fail');}catch(PDOException $e){}
$check($board()===$emptyBefore,'failed confirmed save restores empty folder');$db->execute('DROP TRIGGER fail_empty_save');
$repo->versions()->save('test',$version,$head['revision'],$json($emptyChanged),null,false,true);
$check(!$board()['groups'],'confirmed empty-folder reset clears affected group');
// Legacy targeted cleanup must not dissolve compatible or mixed groups.
$compatible=$capture($repo);$goodGroup=$change('create')['groups'][0]['id'];
$stale=$capture($repo);$staleGroup=$change('create')['groups'][1]['id'];
$mixed=$capture($repo);$change('assign',['snapshotId'=>$mixed,'groupId'=>$goodGroup]);
$db->execute('UPDATE b_pw_calc_snapshot SET form_hash = ? WHERE id IN (?, ?)',[str_repeat('0',64),$stale,$mixed]);
$beforeLegacy=$board();
$db->execute("CREATE TRIGGER fail_legacy_cleanup BEFORE DELETE ON b_pw_calc_snapshot_group BEGIN SELECT RAISE(ABORT, 'legacy rollback'); END");
try{$repo->snapshots()->command('clearIncompatibleCalculationSnapshots','test',$version,'BASE');throw new RuntimeException('expected fail');}catch(PDOException $e){}
$check($board()===$beforeLegacy,'legacy cleanup failure rolls back snapshot/member deletions');$db->execute('DROP TRIGGER fail_legacy_cleanup');
$repo->snapshots()->command('clearIncompatibleCalculationSnapshots','test',$version,'BASE');$afterLegacy=$board();
$check(count($afterLegacy['items'])===1 && $afterLegacy['items'][0]['id']===$compatible && $afterLegacy['items'][0]['groupId']===$goodGroup,'legacy cleanup preserves compatible snapshot membership');
$check(count($afterLegacy['groups'])===1 && $afterLegacy['groups'][0]['id']===$goodGroup,'legacy cleanup deletes only emptied groups');
$check(!$db->rows('SELECT * FROM b_pw_calc_snapshot_member WHERE snapshot_id IN (?, ?)',[$stale,$mixed]),'legacy cleanup leaves no stale memberships');
$change('clear');
$capture($repo);$change('create');$preview=$repo->lifecycle()->preview('test');
$repo->lifecycle()->delete('test',$preview['revision'],$preview['name']);
$check(!$db->rows('SELECT * FROM b_pw_calc_snapshot_group') && !$db->rows('SELECT * FROM b_pw_calc_snapshot_member'),'lifecycle deletion removes every group/member');
$check(!$db->rows('SELECT * FROM b_pw_calc_snapshot'),'lifecycle deletion removes receipts of all actors');
echo "PASS {$checks} calculation group checks\n";
