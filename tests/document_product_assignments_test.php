<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__).'/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__).'/lib/Documents/DocumentProductAssignments.php';
use Prospektweb\Calc\Documents\{PdoConnection,DocumentSchema,DocumentRepository,DocumentVersions,DocumentProductAssignments,SiteConnection,DocumentConflict};
$db=new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($db);
$repo=new DocumentRepository($db,'site:test','user:1');
$encode=static fn($v)=>json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$body=$encode(['contract'=>'prospektweb.calculator/document-v1','schemaVersion'=>1,'id'=>'sheet','name'=>'Sheet',
    'presentations'=>['views'=>[['id'=>'BASE','name'=>'Base','active'=>true],['id'=>'cards','name'=>'Cards','active'=>true]]],
    'pricing'=>['types'=>[['id'=>'retail']]],'unrelated'=>['keep'=>42]]);
$site=$encode(['contract'=>SiteConnection::CONTRACT,'provider'=>'bitrix:test','productsCatalog'=>'14','offersCatalog'=>'15',
    'products'=>[['key'=>'42','presentationId'=>'cards']],'presentationDefaults'=>[['presentationId'=>'cards','productKey'=>'42']],
    'priceTypes'=>[['key'=>'1','typeId'=>'retail']],'formBindings'=>(object)['keep'=>'exact'],'inputMappings'=>[],'outputMappings'=>[]]);
$repo->create($body); $repo->versions()->listing('sheet'); $repo->save('sheet',1,$body,$site,true);
$version=DocumentVersions::primaryId('sheet'); $items=[['key'=>'42','name'=>'Existing','active'=>true],['key'=>'43','name'=>'New','active'=>false]];
$hook=null; $reads=[];
$read=static function(string $query,?array $ids) use (&$items,&$hook,&$reads): array {
    $reads[]=[$query,$ids]; if($hook) { $callback=$hook;$hook=null;$callback(); }
    return array_values(array_filter($items,static fn($row)=>$ids!==null?in_array($row['key'],$ids,true):($query===''||str_contains($row['name'],$query))));
};
$app=new DocumentProductAssignments($repo,'bitrix:test','14',$read);
$checks=0; $ok=static function(bool $condition,string $label) use (&$checks) { $checks++; if(!$condition) throw new RuntimeException($label); };
$fails=static function(callable $action,string $class) use ($ok) { try{$action();}catch(Throwable $e){$ok($e instanceof $class,get_class($e).': '.$e->getMessage());return;}throw new RuntimeException('Expected rejection'); };
$command=static fn(string $action,array $fields=[])=>(['action'=>$action,'id'=>'sheet','versionId'=>$version,'expectedRevision'=>2]+$fields);
$before=$repo->versions()->load('sheet',$version); $history=$repo->history('sheet');
$catalog=$app->command($command('assignmentCatalog',['query'=>'']));
$ok(count($catalog['items'])===2&&$catalog['source']['connectionHash']===hash('sha256',$before['connectionJson']),'Catalog names exact source');
$plan=$app->command($command('previewProductAssignments',['productKeys'=>['43']]));
$ok($plan['addedProductIds']===['43']&&$plan['removedProductIds']===['42'],'Exact diff');
$ok($plan['affectedStorefronts'][0]['id']==='cards'&&$plan['clearedDefaultIds']===['cards'],'Affected view and default shown');
$ok($repo->history('sheet')===$history&&$repo->versions()->load('sheet',$version)===$before,'Read-only preview');
foreach ([['productKeys'=>['43','43']],['productKeys'=>[43]],['productKeys'=>['0']],['productKeys'=>['missing']],['productKeys'=>['43'],'scope'=>'other']] as $invalid)
    $fails(fn()=>$app->command($command('previewProductAssignments',$invalid)),InvalidArgumentException::class);
$fails(fn()=>$app->command($command('previewProductAssignments',['productKeys'=>['99']])),DocumentConflict::class);
$fails(fn()=>$app->command($command('saveProductAssignments',['productKeys'=>['43'],'impactFingerprint'=>'wrong'])),DocumentConflict::class);
$items[1]['name']='Changed';
$fails(fn()=>$app->command($command('saveProductAssignments',['productKeys'=>['43'],'impactFingerprint'=>$plan['impactFingerprint']])),DocumentConflict::class);
$items[1]['name']='New';
$hook=static fn()=>$repo->versions()->save('sheet',$version,2,str_replace('"Sheet"','"Concurrent"',$body),$site,true);
$fails(fn()=>$app->command($command('previewProductAssignments',['productKeys'=>['43']])),DocumentConflict::class);
$fresh=$repo->versions()->load('sheet',$version);
$current=static fn(string $action,array $fields=[])=>(['action'=>$action,'id'=>'sheet','versionId'=>$version,'expectedRevision'=>$fresh['revision']]+$fields);
$plan=$app->command($current('previewProductAssignments',['productKeys'=>['43']]));
$saved=$app->command($current('saveProductAssignments',['productKeys'=>['43'],'impactFingerprint'=>$plan['impactFingerprint']]));
$next=json_decode($saved['connectionJson'],true);
$ok($next['products']===[['key'=>'43','presentationId'=>'BASE']]&&$next['presentationDefaults'][0]['productKey']===null,'New products use BASE, orphan default cleared');
$ok($saved['bodyJson']===$fresh['bodyJson']&&$next['formBindings']===['keep'=>'exact'],'Only site products/defaults changed');
$ok($repo->productBinding('bitrix:test','14','43')===null&&$db->rows('SELECT * FROM b_pw_calc_site_active')===[],'No publication or active binding writes');
$sameCommand=['id'=>'sheet','versionId'=>$version,'expectedRevision'=>$saved['revision'],'productKeys'=>['43']];
$samePlan=$app->command(['action'=>'previewProductAssignments']+$sameCommand);
$same=$app->command(['action'=>'saveProductAssignments','impactFingerprint'=>$samePlan['impactFingerprint']]+$sameCommand);
$ok($same['revision']===$saved['revision'],'No-op does not append revision');
$foreign=new DocumentRepository($db,'site:foreign','user:2');
$fails(fn()=>(new DocumentProductAssignments($foreign,'bitrix:test','14',$read))->command($current('assignmentCatalog',['query'=>''])),RuntimeException::class);
$fails(fn()=>(new DocumentProductAssignments($repo,'bitrix:foreign','14',$read))->command(['action'=>'assignmentCatalog','id'=>'sheet','versionId'=>$version,'expectedRevision'=>$saved['revision'],'query'=>'']),DocumentConflict::class);
$repo->create(str_replace(['"sheet"','"Sheet"'],['"other"','"Other"'],$body));
$db->execute('INSERT INTO b_pw_calc_site_publication(id,document_id,source_revision,snapshot_json,snapshot_hash,actor_id,created_at) VALUES(?,?,?,?,?,?,?)',['pub:other','other',1,'{}',hash('sha256','{}'),'user:1','2026-09-09']);
$db->execute('INSERT INTO b_pw_calc_site_active(document_id,publication_id) VALUES(?,?)',['other','pub:other']);
$db->execute('INSERT INTO b_pw_calc_product_binding(scope_id,provider,catalog_key,product_key,document_id,publication_id,presentation_id) VALUES(?,?,?,?,?,?,?)',['site:test','bitrix:test','14','42','other','pub:other','BASE']);
$catalog=$app->command(['action'=>'assignmentCatalog','id'=>'sheet','versionId'=>$version,'expectedRevision'=>$saved['revision'],'query'=>'Existing']);
$ok($catalog['items'][0]['owner']['documentId']==='other','Published foreign ownership shown');
$fails(fn()=>$app->command(['action'=>'previewProductAssignments','id'=>'sheet','versionId'=>$version,'expectedRevision'=>$saved['revision'],'productKeys'=>['42']]),DocumentConflict::class);
$db->execute('UPDATE b_pw_calc_document SET archived=1 WHERE id=?',['other']);
$ok($repo->productBindings('bitrix:test','14',['42'])===[],'Archived owner is not active authority');
$items=array_map(static fn($n)=>['key'=>(string)$n,'name'=>'P'.$n,'active'=>true],range(100,200));$reads=[];
$batchPlan=$app->command(['action'=>'previewProductAssignments','id'=>'sheet','versionId'=>$version,'expectedRevision'=>$saved['revision'],'productKeys'=>array_column($items,'key')]);
$ok(count($batchPlan['nextProductIds'])===101&&count($reads)===2&&count($reads[0][1])===100&&count($reads[1][1])===1,'Large selections use bounded batches without truncation');
$ok($repo->versions()->load('sheet',$version)['revision']===$saved['revision'],'Batch preview remains read-only');
$fails(fn()=>$app->command(['action'=>'assignmentCatalog','id'=>'sheet','versionId'=>$version,'expectedRevision'=>$saved['revision'],'query'=>str_repeat('x',101)]),InvalidArgumentException::class);
$bad=new DocumentProductAssignments($repo,'bitrix:test','14',static fn()=>[['key'=>'999','name'=>'Wrong','active'=>true]]);
$fails(fn()=>$bad->command(['action'=>'previewProductAssignments','id'=>'sheet','versionId'=>$version,'expectedRevision'=>$saved['revision'],'productKeys'=>['43']]),RuntimeException::class);
$state=$repo->versions()->listing('sheet'); $repo->versions()->change('sheet',$version,$state['registryRevision'],'archiveVersion',['archived'=>true]);
$fails(fn()=>$app->command(['action'=>'previewProductAssignments']+$sameCommand),DocumentConflict::class);
echo 'Product assignments: '.$checks." checks PASS\n";
