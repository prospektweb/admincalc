<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/Documents/BitrixCatalogStateWriter.php';
require_once dirname(__DIR__).'/lib/Documents/PdoConnection.php';
use Prospektweb\Calc\Documents\{BitrixCatalogStateWriter,PdoConnection,SqlConnection,DocumentCatalogWritePlan};

set_error_handler(static function(int $severity,string $message,string $file,int $line):never {throw new ErrorException($message,0,$severity,$file,$line);});
$assertions=0;
function check(bool $ok,string $message):void {global $assertions; ++$assertions; if(!$ok) throw new RuntimeException($message);}
function fixture():array {
    $db=new PdoConnection(new PDO('sqlite::memory:'));
    $db->execute('CREATE TABLE b_catalog_product (ID INTEGER PRIMARY KEY, PURCHASING_PRICE REAL, PURCHASING_CURRENCY TEXT, WIDTH REAL, LENGTH REAL, HEIGHT REAL, WEIGHT REAL, QUANTITY REAL, MEASURE INTEGER, TIMESTAMP_X TEXT)');
    $db->execute('CREATE TABLE b_catalog_price (ID INTEGER PRIMARY KEY AUTOINCREMENT, PRODUCT_ID INTEGER, CATALOG_GROUP_ID INTEGER, PRICE REAL, CURRENCY TEXT, QUANTITY_FROM INTEGER, QUANTITY_TO INTEGER, EXTRA_ID INTEGER, PRICE_SCALE REAL, TIMESTAMP_X TEXT)');
    foreach([101,102] as $id) {
        $db->execute('INSERT INTO b_catalog_product VALUES (?,10,?,1,1,1,1,50,796,?)',[$id,'RUB','before']);
        $db->execute('INSERT INTO b_catalog_price (PRODUCT_ID,CATALOG_GROUP_ID,PRICE,CURRENCY,QUANTITY_FROM,QUANTITY_TO,EXTRA_ID,PRICE_SCALE,TIMESTAMP_X) VALUES (?,1,15,?,NULL,10,7,15,?)',[$id,'RUB','before']);
        $db->execute('INSERT INTO b_catalog_price (PRODUCT_ID,CATALOG_GROUP_ID,PRICE,CURRENCY,QUANTITY_FROM,QUANTITY_TO,EXTRA_ID,PRICE_SCALE,TIMESTAMP_X) VALUES (?,1,14,?,11,NULL,8,14,?)',[$id,'RUB','before']);
        $db->execute('INSERT INTO b_catalog_price (PRODUCT_ID,CATALOG_GROUP_ID,PRICE,CURRENCY,QUANTITY_FROM,QUANTITY_TO,EXTRA_ID,PRICE_SCALE,TIMESTAMP_X) VALUES (?,99,333,?,NULL,NULL,9,20000,?)',[$id,'USD','before']);
    }
    $calls=(object)['rows'=>[],'hook'=>null];
    $mutation=static function(string $action,int $id,array $fields) use($db,$calls) {
        check($db->inTransaction(),'API mutation stays inside outer transaction');
        $calls->rows[]=[$action,$id,$fields];
        if($calls->hook && ($calls->hook)($action,$id,$fields)===false) return false;
        $allowed=['product.update'=>['PURCHASING_PRICE','PURCHASING_CURRENCY','WIDTH','LENGTH','HEIGHT','WEIGHT'],
            'price.update'=>['PRICE','CURRENCY'],'price.delete'=>[],'price.add'=>['PRODUCT_ID','CATALOG_GROUP_ID','PRICE','CURRENCY','QUANTITY_FROM','QUANTITY_TO']];
        check(isset($allowed[$action]) && !array_diff(array_keys($fields),$allowed[$action]),'Exact API field allowlist');
        if($action==='price.delete') {$db->execute('DELETE FROM b_catalog_price WHERE ID=?',[$id]);return true;}
        if($action==='price.add') {
            $fields['QUANTITY_FROM']=$fields['QUANTITY_FROM']===false?null:$fields['QUANTITY_FROM'];
            $fields['QUANTITY_TO']=$fields['QUANTITY_TO']===false?null:$fields['QUANTITY_TO'];
            $db->execute('INSERT INTO b_catalog_price ('.implode(',',array_keys($fields)).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')',array_values($fields));
            return (int)$db->rows('SELECT last_insert_rowid() AS id')[0]['id'];
        }
        $table=$action==='product.update'?'b_catalog_product':'b_catalog_price';
        $set=implode(',',array_map(static fn(string $key):string=>$key.'=?',array_keys($fields)));
        $db->execute('UPDATE '.$table.' SET '.$set.',TIMESTAMP_X=? WHERE ID=?',[...array_values($fields),'after',$id]);
        return true;
    };
    $writer=new BitrixCatalogStateWriter($db,$mutation);
    $state=DocumentCatalogWritePlan::state(['purchasingPrice'=>['value'=>100,'currency'=>'RUB'],'dimensions'=>['width'=>90,'length'=>50,'height'=>0.3,'weight'=>2],
        'prices'=>[['typeId'=>1,'quantityFrom'=>null,'quantityTo'=>10,'price'=>120,'currency'=>'RUB'],
            ['typeId'=>1,'quantityFrom'=>11,'quantityTo'=>20,'price'=>110,'currency'=>'RUB'],['typeId'=>1,'quantityFrom'=>21,'quantityTo'=>null,'price'=>105,'currency'=>'RUB'],
            ['typeId'=>99,'quantityFrom'=>null,'quantityTo'=>null,'price'=>333,'currency'=>'USD']]]);
    $targets=[['offerId'=>101,'priceTypeIds'=>[1],'state'=>$state],['offerId'=>102,'priceTypeIds'=>[1],'state'=>$state]];
    return compact('db','calls','mutation','writer','targets');
}
function snapshot(PdoConnection $db):array {return [$db->rows('SELECT * FROM b_catalog_product ORDER BY ID'),$db->rows('SELECT * FROM b_catalog_price ORDER BY ID')];}
function fails(array $f,callable $run,string $message):void {
    $before=snapshot($f['db']); $f['db']->begin(); $thrown=false;
    try {$run();} catch(InvalidArgumentException|RuntimeException|LogicException $e) {$thrown=true;}
    finally {$f['db']->rollback();}
    check($thrown,$message); check(snapshot($f['db'])===$before,'Actual SQL rollback: '.$message);
}

$f=fixture(); $unowned=$f['db']->rows('SELECT * FROM b_catalog_price WHERE CATALOG_GROUP_ID=99 ORDER BY ID');
$f['db']->begin();$f['writer']->write($f['targets']);check($f['db']->inTransaction(),'Writer never commits');$f['db']->commit();
check($f['db']->rows('SELECT * FROM b_catalog_price WHERE CATALOG_GROUP_ID=99 ORDER BY ID')===$unowned,'Unowned price rows and metadata remain byte-identical');
check(count($f['calls']->rows)===10,'One product update, one price update, one delete, two adds per target');
check($f['db']->rows('SELECT EXTRA_ID FROM b_catalog_price WHERE ID=1')[0]['EXTRA_ID']===7,'Existing price row retains ID and metadata');
check($f['db']->rows('SELECT QUANTITY,MEASURE FROM b_catalog_product WHERE ID=101')[0]===['QUANTITY'=>50.0,'MEASURE'=>796],'Stock and measure unchanged');
$f['calls']->rows=[];$before=snapshot($f['db']);$f['db']->begin();$f['writer']->write($f['targets']);$f['db']->commit();
check($f['calls']->rows===[] && snapshot($f['db'])===$before,'No-op never calls mutation APIs');
try {$f['writer']->write($f['targets']);check(false,'Outside transaction accepted');} catch(LogicException $expected){check(true,'Outside transaction rejected');}

foreach(['unowned-price','unowned-currency','unowned-missing','duplicate-target','string-id','duplicate-type','foreign-field','missing-owned-range','zero-dimension','overlap','missing-product'] as $case) {
    $f=fixture();$targets=$f['targets'];
    if($case==='unowned-price') foreach($targets[1]['state']['prices'] as &$p) {if($p['typeId']===99)$p['price']=334;} unset($p);
    if($case==='unowned-currency') foreach($targets[1]['state']['prices'] as &$p) {if($p['typeId']===99)$p['currency']='RUB';} unset($p);
    if($case==='unowned-missing') $targets[1]['state']['prices']=array_values(array_filter($targets[1]['state']['prices'],static fn($p)=>$p['typeId']!==99));
    if($case==='duplicate-target') $targets[1]['offerId']=101;
    if($case==='string-id') $targets[1]['offerId']='102';
    if($case==='duplicate-type') $targets[1]['priceTypeIds']=[1,1];
    if($case==='foreign-field') $targets[1]['state']['NAME']='injected';
    if($case==='missing-owned-range') $targets[1]['priceTypeIds']=[1,2];
    if($case==='zero-dimension') $targets[1]['state']['dimensions']['width']=0;
    if($case==='overlap') $targets[1]['state']['prices'][]=['typeId'=>1,'quantityFrom'=>2,'quantityTo'=>5,'price'=>1,'currency'=>'RUB'];
    if($case==='missing-product') $targets[1]['offerId']=999;
    fails($f,static fn()=>$f['writer']->write($targets),$case);
    check($f['calls']->rows===[],'All targets validated before any API call: '.$case);
}

foreach(['api-false','api-throw','silent-no-write','foreign-price-metadata','foreign-product-field','owned-price-metadata','replace-owned-row'] as $case) {
    $f=fixture();$db=$f['db'];$originalMutation=$f['mutation'];$count=0;
    $mutation=static function($action,$id,$fields)use($case,$db,$originalMutation,&$count) {
        ++$count;
        if($case==='api-false' && $count===3) return false;
        if($case==='api-throw' && $count===3) throw new RuntimeException('event failed');
        if($case==='silent-no-write') return true;
        $result=$originalMutation($action,$id,$fields);
        if($count===1 && $case==='foreign-price-metadata') $db->execute('UPDATE b_catalog_price SET EXTRA_ID=88 WHERE CATALOG_GROUP_ID=99');
        if($count===1 && $case==='foreign-product-field') $db->execute('UPDATE b_catalog_product SET QUANTITY=900 WHERE ID=101');
        if($action==='price.update' && $case==='owned-price-metadata') $db->execute('UPDATE b_catalog_price SET EXTRA_ID=88 WHERE ID=?',[$id]);
        if($action==='price.update' && $case==='replace-owned-row') {$db->execute('INSERT INTO b_catalog_price (PRODUCT_ID,CATALOG_GROUP_ID,PRICE,CURRENCY,QUANTITY_FROM,QUANTITY_TO,EXTRA_ID,PRICE_SCALE,TIMESTAMP_X) SELECT PRODUCT_ID,CATALOG_GROUP_ID,PRICE,CURRENCY,QUANTITY_FROM,QUANTITY_TO,EXTRA_ID,PRICE_SCALE,TIMESTAMP_X FROM b_catalog_price WHERE ID=?',[$id]);$db->execute('DELETE FROM b_catalog_price WHERE ID=?',[$id]);}
        return $result;
    };
    $writer=new BitrixCatalogStateWriter($db,$mutation);
    fails($f,static fn()=>$writer->write($f['targets']),$case);
}
foreach(['InnoDB','MyISAM'] as $engine) {
    $f=fixture();
    $mysql=new class($f['db'],$engine) implements SqlConnection {
        public array $queries=[];
        public function __construct(private PdoConnection $db,private string $engine){}
        public function dialect():string{return 'mysql';}
        public function inTransaction():bool{return $this->db->inTransaction();}
        public function begin(bool $readSnapshot=false):void{$this->db->begin($readSnapshot);}
        public function commit():void{$this->db->commit();}
        public function rollback():void{$this->db->rollback();}
        public function execute(string $sql,array $parameters=[]):void{$this->db->execute($sql,$parameters);}
        public function rows(string $sql,array $parameters=[]):array {
            $this->queries[]=$sql;
            if(str_contains($sql,'information_schema.TABLES')) return [['TABLE_NAME'=>'b_catalog_price','ENGINE'=>$this->engine],['TABLE_NAME'=>'b_catalog_product','ENGINE'=>$this->engine]];
            return $this->db->rows(str_replace(' FOR UPDATE','',$sql),$parameters);
        }
    };
    $writer=new BitrixCatalogStateWriter($mysql,$f['mutation']);
    if($engine==='MyISAM') {fails($f,static fn()=>$writer->write($f['targets']),'Nontransactional catalog rejected');check($f['calls']->rows===[],'Engine check precedes mutation');}
    else {$mysql->begin();$writer->write($f['targets']);$mysql->rollback();}
    foreach($mysql->queries as $sql) if(!str_contains($sql,'information_schema')) check(str_ends_with($sql,' FOR UPDATE') && str_contains($sql,' LIMIT '),'Every MySQL data read is locked and bounded');
}
$f=fixture();$reader=new BitrixCatalogStateWriter($f['db'],$f['mutation']);
try{$reader->capture([101],false);throw new RuntimeException('Expected outside-snapshot rejection');}catch(LogicException $expected){check(true,'State projection requires outer snapshot');}
foreach([false,true] as $lock){
    $f['db']->begin(!$lock);$captured=$reader->capture([102,101],$lock);
    check(array_keys($captured)===[101,102] && $f['calls']->rows===[],'State capture is ordered and never mutates');
    check($captured[101]['state']['purchasingPrice']['value']===10.0,'Same state normalization as writer readback');$f['db']->rollback();
}
echo "PASS Bitrix catalog state writer: $assertions assertions\n";
restore_error_handler();
