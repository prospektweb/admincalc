<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__).'/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__).'/lib/Documents/DocumentApplication.php';
require_once dirname(__DIR__).'/lib/Documents/BitrixRegistryOfferCounts.php';
use Prospektweb\Calc\Documents\{PdoConnection,SqlConnection,DocumentSchema,DocumentRepository,DocumentApplication,BitrixRegistryOfferCounts};
$checks=0;
function usage_check(bool $ok,string $message):void {global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function usage_reject(callable $fn):void {try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Invalid usage accepted.');}
foreach([1,2] as $storage){
    $db=new PdoConnection(new PDO('sqlite::memory:'));DocumentSchema::install($db);
    $repo=new DocumentRepository($db,'site:s1','user:1');
    $make=static fn($id)=>json_encode(['contract'=>'prospektweb.calculator/document-v1','schemaVersion'=>1,'id'=>$id,'name'=>$id],JSON_THROW_ON_ERROR);
    foreach(['a','b'] as $id)$repo->create($make($id));
    (new DocumentRepository($db,'site:s2','user:2'))->create($make('foreign'));
    foreach(['a','b','foreign'] as $id){
        $db->execute('INSERT INTO b_pw_calc_site_publication (id,document_id,source_revision,snapshot_json,snapshot_hash,actor_id,created_at) VALUES (?,?,?,?,?,?,?)',[$id.'-pub',$id,1,'{}',hash('sha256','{}'),'user:1','2026-01-01T00:00:00Z']);
        $db->execute('INSERT INTO b_pw_calc_site_active (document_id,publication_id) VALUES (?,?)',[$id,$id.'-pub']);
    }
    $bind=static function($scope,$document,$product,$provider='bitrix:test',$catalog='14',$publication=null)use($db):void{
        $db->execute('INSERT INTO b_pw_calc_product_binding (scope_id,provider,catalog_key,product_key,document_id,publication_id,presentation_id) VALUES (?,?,?,?,?,?,?)',[$scope,$provider,$catalog,$product,$document,$publication??$document.'-pub','BASE']);
    };
    $bind('site:s1','a','11');$bind('site:s1','a','12');$bind('site:s1','b','13');$bind('site:s2','foreign','11');
    $db->execute('CREATE TABLE b_catalog_iblock (IBLOCK_ID INTEGER,PRODUCT_IBLOCK_ID INTEGER,SKU_PROPERTY_ID INTEGER)');
    $db->execute('INSERT INTO b_catalog_iblock VALUES (14,0,0),(15,14,200)');
    $db->execute('CREATE TABLE b_iblock (ID INTEGER,VERSION INTEGER)');$db->execute('INSERT INTO b_iblock VALUES (?,?)',[15,$storage]);
    $db->execute('CREATE TABLE b_iblock_property (ID INTEGER,IBLOCK_ID INTEGER,ACTIVE TEXT,PROPERTY_TYPE TEXT,USER_TYPE TEXT,MULTIPLE TEXT,LINK_IBLOCK_ID INTEGER)');
    $db->execute("INSERT INTO b_iblock_property VALUES (200,15,'Y','E','SKU','N',14)");
    $db->execute('CREATE TABLE b_iblock_element (ID INTEGER PRIMARY KEY,IBLOCK_ID INTEGER,ACTIVE TEXT,ACTIVE_FROM TEXT,ACTIVE_TO TEXT)');
    foreach([[11,14,'Y',null,null],[12,14,'N',null,null],[13,14,'Y',null,null],
        [21,15,'Y',null,null],[22,15,'N',null,null],[23,15,'Y',null,'2000-01-01'],[24,15,'Y','2999-01-01',null],
        [25,15,'Y',null,null],[26,99,'Y',null,null],[27,15,'Y',null,null],[28,15,'Y',null,null]]as $row)
        $db->execute('INSERT INTO b_iblock_element VALUES (?,?,?,?,?)',$row);
    $db->execute($storage===1?'CREATE TABLE b_iblock_element_property (IBLOCK_ELEMENT_ID INTEGER,IBLOCK_PROPERTY_ID INTEGER,VALUE TEXT)':'CREATE TABLE b_iblock_element_prop_s15 (IBLOCK_ELEMENT_ID INTEGER,PROPERTY_200 INTEGER)');
    foreach([[21,11],[22,11],[23,11],[24,11],[25,12],[26,11],[27,99],[28,11]]as [$offer,$product])
        $db->execute($storage===1?'INSERT INTO b_iblock_element_property VALUES (?,200,?)':'INSERT INTO b_iblock_element_prop_s15 VALUES (?,?)',[$offer,(string)$product]);
    // Duplicate legacy V1 rows must not inflate the count.
    if($storage===1)$db->execute('INSERT INTO b_iblock_element_property VALUES (21,200,11)');
    $guard=new class($db) implements SqlConnection {
        public array $queries=[];
        public function __construct(private SqlConnection $inner){}
        public function dialect():string{return $this->inner->dialect();}
        public function inTransaction():bool{return $this->inner->inTransaction();}
        public function begin(bool $readSnapshot=false):void{if(!$readSnapshot)throw new RuntimeException('Not a read snapshot.');$this->inner->begin(true);}
        public function commit():void{$this->inner->commit();}
        public function rollback():void{$this->inner->rollback();}
        public function execute(string $sql,array $parameters=[]):void{throw new RuntimeException('Usage attempted a write.');}
        public function rows(string $sql,array $parameters=[]):array{
            if(!$this->inTransaction()||preg_match('/body_json|snapshot_json|b_pw_calc_revision/i',$sql))throw new RuntimeException('Read escaped snapshot or loaded a document.');
            $this->queries[]=$sql;return $this->inner->rows($sql,$parameters);
        }
    };
    $port=new BitrixRegistryOfferCounts($guard,'site:s1','bitrix:test',14,15);
    $never=static function():never{throw new RuntimeException('Unexpected core/resource invocation.');};
    $app=new DocumentApplication(new DocumentRepository($guard,'site:s1','user:1'),$never,$never,null,null,null,null,$port);
    $first=$app->command(['action'=>'registry','sort'=>'name_asc']);
    usage_check(count($first['rows'])===2&&$first['rows'][0]['id']==='a','Site scope filters rows.');
    usage_check($first['rows'][0]['offerCount']===3,'Only active date-valid offers in the configured catalog count; inactive parent remains included like legacy.');
    usage_check($first['rows'][1]['offerCount']===0,'Known empty offer scope is zero.');
    usage_check(count($guard->queries)===5,'Metadata2 + schema2 + aggregate1, not per product or document.');
    usage_check(!$db->inTransaction(),'Snapshot closes.');
    for($i=1;$i<=98;$i++)$repo->create($make('empty-'.$i));
    $guard->queries=[];$page=$app->command(['action'=>'registry','sort'=>'name_asc','pageSize'=>100]);
    usage_check(count($page['rows'])===100&&count($guard->queries)===5,'100-row page uses the same bounded query count.');
    usage_check($page['rows'][99]['offerCount']===0,'Unpublished unbound rows have zero usage.');
    $guard->queries=[];$empty=$app->command(['action'=>'registry','query'=>'not-found']);
    usage_check($empty['rows']===[]&&count($guard->queries)===2,'Empty page never reads the catalog.');
    $db->execute("UPDATE b_iblock_property SET MULTIPLE='Y' WHERE ID=200");
    $invalid=$app->command(['action'=>'registry','query'=>'a','sort'=>'name_asc']);
    usage_check($invalid['rows'][0]['offerCount']===null,'Invalid schema is unknown, never invented zero.');
    $db->execute("UPDATE b_iblock_property SET MULTIPLE='N' WHERE ID=200");
    $bind('site:s1','a','31','bitrix:other');
    usage_check($app->command(['action'=>'registry','query'=>'a','sort'=>'name_asc'])['rows'][0]['offerCount']===null,'Mixed provider bindings cannot silently produce an undercount.');
    $db->execute("DELETE FROM b_pw_calc_product_binding WHERE scope_id='site:s1' AND product_key='31'");
    $db->begin(true);
    try{
        $items=$first['rows'];$items[0]['activeSitePublication']='stale';
        usage_check($port($items)['a']===null,'Publication mismatch cannot reuse another snapshot count.');
        usage_check((new BitrixRegistryOfferCounts($guard,'site:s1','',0,0))($first['rows'])['a']===null,'Missing adapter settings stay unknown.');
    }finally{$db->rollback();}
    usage_reject(fn()=>$port($first['rows']));
    foreach([static fn($rows)=>[],static fn($rows)=>array_fill_keys(array_column($rows,'id'),'3'),static fn($rows)=>array_fill_keys(array_column($rows,'id'),-1)]as $bad){
        usage_reject(fn()=>(new DocumentRepository($guard,'site:s1','user:1'))->registry('a','all','name_asc',1,30,null,$bad));
        usage_check(!$db->inTransaction(),'Invalid port replies roll back the snapshot.');
    }
}
echo "Registry offer usage: $checks checks PASS\n";
