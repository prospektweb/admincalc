<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/native_catalog_write_fixture.php';
require_once dirname(__DIR__).'/lib/Documents/BitrixDocumentCatalogWritePort.php';
use Prospektweb\Calc\Documents\{BitrixDocumentCatalogWritePort,DocumentCatalogWriteService,DocumentCatalogWritePlan as Plan,SiteConnection};
set_error_handler(static function(int $severity,string $message,string $file,int $line):void {if(error_reporting() & $severity)throw new ErrorException($message,0,$severity,$file,$line);});
$checks=0;
function check(bool $ok,string $message):void {global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function reject(callable $work,string $message):void {try{$work();}catch(Throwable $error){check(true,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
function portFixture(int $version=2):array {
    $f=nativeWriteFixture();extract($f);
    $bodyData=json_decode($body,true);$bodyData['execution']=['currency'=>'RUB'];
    $bodyData['form']=['fields'=>[['fieldId'=>'qty','type'=>'number','label'=>'Тираж']], 'sections'=>[]];
    $connection->inputMappings=[(object)['target'=>(object)['field_id'=>'qty'],'source'=>(object)['scope'=>'selected_offer','iblock_id'=>15,'property_id'=>722,'property_code'=>'CALC_PROP_VOLUME'],'value_mode'=>'scalar']];
    $connection->formBindings=(object)['bindings'=>[(object)['fieldId'=>'qty','valueMode'=>'scalar','target'=>(object)['kind'=>'property','propertyCode'=>'CALC_PROP_VOLUME']]]];
    $draft=$repo->save('sheet',2,json_encode($bodyData,JSON_THROW_ON_ERROR),json_encode($connection,JSON_THROW_ON_ERROR),true);
    $snapshot=(object)['contract'=>'prospektweb.calculator/site-publication-v1','documentId'=>'sheet','sourceRevision'=>3,
        'documentHash'=>$draft['bodyHash'],'connectionHash'=>$draft['connectionHash'],'connection'=>json_decode($draft['connectionJson']),
        'core'=>(object)['contract'=>'prospektweb.calculator/publication-v1','calculatorId'=>'sheet','documentHash'=>$draft['bodyHash'],'plan'=>(object)['document'=>(object)$bodyData]],
        'runtime'=>(object)['contract'=>'prospektweb.calculator/site-runtime-v1','formDefinition'=>(object)$bodyData['form'],'bindingDefinition'=>$connection->formBindings,'snapshot'=>(object)[]]];
    $published=$repo->publishSite('sheet',3,$published['id'],SiteConnection::encode($snapshot));
    $snapshot=json_decode($published['snapshotJson'],false,64,JSON_THROW_ON_ERROR);
    $db->execute('CREATE TABLE b_module (ID TEXT PRIMARY KEY)');
    $db->execute('INSERT INTO b_module VALUES (?),(?)',['prospektweb.calc','prospektweb.frontcalc']);
    $db->execute('CREATE TABLE b_option (MODULE_ID TEXT,NAME TEXT,VALUE TEXT,SITE_ID TEXT)');
    $settings=['PRODUCTS_IBLOCK_ID'=>'14','OFFERS_IBLOCK_ID'=>'15','SETTINGS_AGGREGATE_REVISION'=>'1'];
    foreach($settings as $name=>$value)$db->execute('INSERT INTO b_option VALUES (?,?,?,NULL)',['prospektweb.frontcalc',$name,$value]);
    $db->execute('INSERT INTO b_option VALUES (?,?,?,NULL)',['prospektweb.calc','DOCUMENT_RESOURCE_PROVIDER','bitrix:test']);
    $db->execute('CREATE TABLE b_lang (LID TEXT PRIMARY KEY,ACTIVE TEXT)');$db->execute('INSERT INTO b_lang VALUES (?,?)',['s1','Y']);
    $db->execute('CREATE TABLE b_iblock (ID INTEGER PRIMARY KEY,VERSION INTEGER,ACTIVE TEXT)');$db->execute('INSERT INTO b_iblock VALUES (14,1,?),(15,?,?)',['Y',$version,'Y']);
    $db->execute('CREATE TABLE b_catalog_iblock (IBLOCK_ID INTEGER PRIMARY KEY,PRODUCT_IBLOCK_ID INTEGER,SKU_PROPERTY_ID INTEGER)');$db->execute('INSERT INTO b_catalog_iblock VALUES (14,0,0),(15,14,279)');
    $db->execute('CREATE TABLE b_iblock_property (ID INTEGER PRIMARY KEY,IBLOCK_ID INTEGER,CODE TEXT,ACTIVE TEXT,PROPERTY_TYPE TEXT,USER_TYPE TEXT,MULTIPLE TEXT,VERSION INTEGER,USER_TYPE_SETTINGS TEXT,LINK_IBLOCK_ID INTEGER)');
    $db->execute('INSERT INTO b_iblock_property VALUES (279,15,?,?,?,?,?,?,NULL,14),(722,15,?,?,?,?,?,?,NULL,0)', ['CML2_LINK','Y','E','SKU','N',$version,'CALC_PROP_VOLUME','Y','N','','N',$version]);
    $db->execute('CREATE TABLE b_iblock_element (ID INTEGER PRIMARY KEY,IBLOCK_ID INTEGER,NAME TEXT,ACTIVE TEXT,ACTIVE_FROM TEXT,ACTIVE_TO TEXT)');
    $db->execute('INSERT INTO b_iblock_element VALUES (42,14,?,?,NULL,NULL)',['Товар','Y']);
    $db->execute('CREATE TABLE b_iblock_element_property (ID INTEGER PRIMARY KEY,IBLOCK_ELEMENT_ID INTEGER,IBLOCK_PROPERTY_ID INTEGER,VALUE TEXT,VALUE_ENUM INTEGER)');
    $db->execute('CREATE TABLE b_iblock_element_prop_s15 (IBLOCK_ELEMENT_ID INTEGER PRIMARY KEY,PROPERTY_279 INTEGER,PROPERTY_722 TEXT)');
    $db->execute('CREATE TABLE b_catalog_product (ID INTEGER PRIMARY KEY,TYPE INTEGER,PURCHASING_PRICE REAL,PURCHASING_CURRENCY TEXT,WIDTH REAL,LENGTH REAL,HEIGHT REAL,WEIGHT REAL,QUANTITY REAL,MEASURE INTEGER)');
    $db->execute('CREATE TABLE b_catalog_price (ID INTEGER PRIMARY KEY,PRODUCT_ID INTEGER,CATALOG_GROUP_ID INTEGER,PRICE REAL,CURRENCY TEXT,QUANTITY_FROM INTEGER,QUANTITY_TO INTEGER,EXTRA_ID INTEGER)');
    foreach([101,102] as $offer) {
        $db->execute('INSERT INTO b_iblock_element VALUES (?,15,?,?,NULL,NULL)',[$offer,'ТП '.$offer,'Y']);
        $db->execute('INSERT INTO b_iblock_element_prop_s15 VALUES (?,42,?)',[$offer,'100']);
        $db->execute('INSERT INTO b_iblock_element_property (IBLOCK_ELEMENT_ID,IBLOCK_PROPERTY_ID,VALUE) VALUES (?,279,?),(?,722,?)',[$offer,'42',$offer,'100']);
        $db->execute('INSERT INTO b_catalog_product VALUES (?,4,10,?,1,1,1,1,50,796)',[$offer,'RUB']);
        $db->execute('INSERT INTO b_catalog_price (PRODUCT_ID,CATALOG_GROUP_ID,PRICE,CURRENCY,QUANTITY_FROM,QUANTITY_TO,EXTRA_ID) VALUES (?,1,15,?,NULL,NULL,7),(?,99,333,?,NULL,NULL,9)',[$offer,'RUB',$offer,'USD']);
    }
    $db->execute('CREATE TABLE b_catalog_group (ID INTEGER PRIMARY KEY,NAME TEXT,BASE TEXT)');$db->execute('INSERT INTO b_catalog_group VALUES (1,?,?),(99,?,?)',['BASE','Y','OTHER','N']);
    $db->execute('CREATE TABLE b_catalog_currency (CURRENCY TEXT PRIMARY KEY,BASE TEXT,AMOUNT REAL,AMOUNT_CNT INTEGER)');$db->execute('INSERT INTO b_catalog_currency VALUES (?,?,1,1),(?,?,90,1)',['RUB','Y','USD','N']);
    $db->execute('CREATE TABLE b_catalog_currency_rate (ID INTEGER PRIMARY KEY,CURRENCY TEXT,DATE_RATE TEXT,RATE REAL,RATE_CNT INTEGER)');$db->execute('INSERT INTO b_catalog_currency_rate VALUES (1,?,?,90,1)',['USD','2026-09-08']);
    $calls=(object)['inputs'=>0,'writes'=>0,'hook'=>null];
    $services=['settings_names'=>array_keys($settings),
        'settings_decode'=>static function(array $rows) use($settings):array {
            $values=[];foreach($rows as $row){if($row['MODULE_ID']!=='prospektweb.frontcalc'||!isset($settings[$row['NAME']])||$row['SITE_ID']!==null||isset($values[$row['NAME']]))throw new RuntimeException('Ambiguous settings');$values[$row['NAME']]=$row['VALUE'];}
            if(count($values)!==3)throw new RuntimeException('Incomplete settings');return ['settings'=>$values];
        },
        'input_builder'=>static function(array $properties,string $document,string $publication,array $runtime,array $mappings,int $product,int $offer,string $name)use($calls):array {
            $calls->inputs++;check($document==='sheet' && str_starts_with($publication,'s_') && $product===42 && $name==='ТП '.$offer,'Builder receives exact native/catalog identities');
            check($runtime['contract']==='prospektweb.calculator/site-runtime-v1' && $mappings[0]['target']['field_id']==='qty','Builder receives immutable form/mapping, not legacy preset');
            $quantity=(int)$properties['selected_offer'][$offer][722]['values'][0]['value'];
            return ['values'=>(object)['qty'=>$quantity],'execution'=>['unitCount'=>$quantity,'runCount'=>1,'layoutCount'=>1,'deadlineType'=>'strict']];
        },
        'mutation'=>static function(string $action,int $id,array $fields) use($db,$calls) {
            check($db->inTransaction(),'Real SQL mutation remains in outer transaction');$calls->writes++;
            if($action==='price.delete'){$db->execute('DELETE FROM b_catalog_price WHERE ID=?',[$id]);}
            elseif($action==='price.add'){
                foreach(['QUANTITY_FROM','QUANTITY_TO'] as $key)if($fields[$key]===false)$fields[$key]=null;
                $db->execute('INSERT INTO b_catalog_price ('.implode(',',array_keys($fields)).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')',array_values($fields));
            }else{
                $table=$action==='product.update'?'b_catalog_product':'b_catalog_price';
                $db->execute('UPDATE '.$table.' SET '.implode(',',array_map(static fn(string $key):string=>$key.'=?',array_keys($fields))).' WHERE ID=?',array_merge(array_values($fields),[$id]));
            }
            if($calls->hook)($calls->hook)($action);return true;
        }];
    $port=new BitrixDocumentCatalogWritePort($db,'site:s1','user:1',$services);
    $service=new DocumentCatalogWriteService($db,'site:s1','user:1','bitrix:test',$port,$core);
    $request=['action'=>'previewCatalogWrite','id'=>'sheet','publicationId'=>$published['id'],'offerIds'=>[102,101]];
    return compact('db','repo','snapshot','services','port','service','request','calls','coreState');
}
function take(array $f,bool $lock=false):array{$f['db']->begin(!$lock);try{return $f['port']->capture($f['snapshot'],[101,102],$lock);}finally{$f['db']->rollback();}}
function tables(array $f):array {return array_map(fn(string $table):array=>$f['db']->rows('SELECT * FROM '.$table.' ORDER BY ID'),['b_catalog_product','b_catalog_price']);}
foreach([1,2] as $version){
    $f=portFixture($version);$before=take($f);check($before==take($f,true),'V'.$version.' snapshot and locking projections match');
    check($before['offers'][0]['values']->qty===100 && $before['offers'][0]['productId']===42,'V'.$version.' exact SKU inputs');
    $preview=$f['service']->command($f['request']);check($preview['summary']['changedOffers']===2 && $f['calls']->writes===0,'Real port preview never writes');
    $apply=array_replace($f['request'],['action'=>'applyCatalogWrite','expectedFingerprint'=>$preview['fingerprint']]);
    $receipt=$f['service']->command($apply);check($receipt['applied'] && $receipt['summary']['updated']===2,'Coordinator and real SQL port apply together');
    $after=take($f);check($before['authority']==$after['authority'],'Owned changes do not invalidate source authority');
    check($after['offers'][0]['current']['purchasingPrice']['value']===100.12345679,'Exact target readback');
    check($f['db']->rows('SELECT PRICE,EXTRA_ID FROM b_catalog_price WHERE CATALOG_GROUP_ID=99')===[['PRICE'=>333.0,'EXTRA_ID'=>9],['PRICE'=>333.0,'EXTRA_ID'=>9]],'Unowned price rows and metadata unchanged');
    $writes=$f['calls']->writes;check(Plan::hash($f['service']->command($apply))===Plan::hash($receipt) && $f['calls']->writes===$writes,'Receipt replay makes no second write');
    $noop=$f['service']->command($f['request']);check($noop['summary']['changedOffers']===0,'Post-apply preview is a no-op');
    $f['service']->command(array_replace($apply,['expectedFingerprint'=>$noop['fingerprint']]));check($f['calls']->writes===$writes,'No-op apply keeps catalog untouched');
}
foreach([
    'provider-drift'=>['UPDATE b_option SET VALUE=? WHERE NAME=?',['bitrix:other','DOCUMENT_RESOURCE_PROVIDER']],
    'provider-shadow'=>['INSERT INTO b_option VALUES (?,?,?,?)',['prospektweb.calc','DOCUMENT_RESOURCE_PROVIDER','bitrix:test','s1']],
    'provider-case-shadow'=>['INSERT INTO b_option VALUES (?,?,?,NULL)',['prospektweb.calc','document_resource_provider','bitrix:test']],
    'settings-shadow'=>['INSERT INTO b_option VALUES (?,?,?,?)',['prospektweb.frontcalc','PRODUCTS_IBLOCK_ID','14','']],
    'settings-case'=>['UPDATE b_option SET NAME=? WHERE NAME=?',['products_iblock_id','PRODUCTS_IBLOCK_ID']],
    'settings-missing'=>['DELETE FROM b_option WHERE NAME=?',['OFFERS_IBLOCK_ID']],
    'settings-pair'=>['UPDATE b_option SET VALUE=? WHERE NAME=?',['16','OFFERS_IBLOCK_ID']],
    'module-missing'=>['DELETE FROM b_module WHERE ID=?',['prospektweb.frontcalc']],
    'site-inactive'=>['UPDATE b_lang SET ACTIVE=?',['N']],
    'catalog-parent'=>['UPDATE b_catalog_iblock SET PRODUCT_IBLOCK_ID=16 WHERE IBLOCK_ID=15',[]],
    'sku-property-multiple'=>['UPDATE b_iblock_property SET MULTIPLE=? WHERE ID=279',['Y']],
    'sku-property-target'=>['UPDATE b_iblock_property SET LINK_IBLOCK_ID=16 WHERE ID=279',[]],
    'sku-parent'=>['UPDATE b_iblock_element_prop_s15 SET PROPERTY_279=43 WHERE IBLOCK_ELEMENT_ID=101',[]],
    'sku-empty'=>['UPDATE b_iblock_element_prop_s15 SET PROPERTY_279=NULL WHERE IBLOCK_ELEMENT_ID=101',[]],
    'product-binding'=>['UPDATE b_pw_calc_product_binding SET presentation_id=?',['wrong']],
    'product-inactive'=>['UPDATE b_iblock_element SET ACTIVE=? WHERE ID=42',['N']],
    'offer-type'=>['UPDATE b_catalog_product SET TYPE=1 WHERE ID=101',[]],
    'price-group-missing'=>['DELETE FROM b_catalog_group WHERE ID=1',[]],
    'currency-missing'=>['DELETE FROM b_catalog_currency WHERE CURRENCY=?',['RUB']],
    'currency-base'=>['UPDATE b_catalog_currency SET BASE=?',['Y']],
    'input-schema'=>['UPDATE b_iblock_property SET CODE=? WHERE ID=722',['RENAMED']],
    'publication-corrupt'=>['UPDATE b_pw_calc_site_publication SET snapshot_json=?',['{}']],
] as $name=>[$sql,$parameters]){
    $f=portFixture();$f['db']->execute($sql,$parameters);$before=tables($f);reject(fn()=>$f['service']->command($f['request']),$name);
    check($f['calls']->writes===0 && tables($f)===$before && !$f['db']->inTransaction(),'Fail closed without writes: '.$name);
}
foreach(['inputs','rate','binding','foreign-price','exception'] as $event){
    $f=portFixture();$preview=$f['service']->command($f['request']);$before=tables($f);
    $f['calls']->hook=static function()use($event,$f):void{
        if($event==='inputs')$f['db']->execute('UPDATE b_iblock_element_prop_s15 SET PROPERTY_722=?',['200']);
        elseif($event==='rate')$f['db']->execute('UPDATE b_catalog_currency_rate SET RATE=120');
        elseif($event==='binding')$f['db']->execute('UPDATE b_pw_calc_product_binding SET presentation_id=?',['other']);
        elseif($event==='foreign-price')$f['db']->execute('UPDATE b_catalog_price SET EXTRA_ID=700 WHERE CATALOG_GROUP_ID=99');
        else throw new RuntimeException('Simulated Bitrix event failure');
    };
    reject(fn()=>$f['service']->command(array_replace($f['request'],['action'=>'applyCatalogWrite','expectedFingerprint'=>$preview['fingerprint']])), 'Event drift '.$event);
    check(tables($f)===$before && $f['db']->rows('SELECT * FROM b_pw_calc_catalog_write')===[],'Full rollback including immutable receipt: '.$event);
}
foreach(['document_resource_provider','Document_Resource_Provider'] as $optionName){
    $f=portFixture();$f['db']->execute('UPDATE b_option SET NAME=? WHERE MODULE_ID=?',[$optionName,'prospektweb.calc']);
    $preview=$f['service']->command($f['request']);
    $receipt=$f['service']->command(array_replace($f['request'],['action'=>'applyCatalogWrite','expectedFingerprint'=>$preview['fingerprint']]));
    check($receipt['applied'],'Single Bitrix-normalized provider name accepts preview and apply');
}
$f=portFixture();$f['db']->execute('DELETE FROM b_catalog_price WHERE CATALOG_GROUP_ID=99');$f['db']->execute('UPDATE b_catalog_product SET PURCHASING_CURRENCY=?',['USD']);$f['db']->execute('UPDATE b_catalog_price SET CURRENCY=? WHERE CATALOG_GROUP_ID=1',['USD']);
$preview=$f['service']->command($f['request']);$receipt=$f['service']->command(array_replace($f['request'],['action'=>'applyCatalogWrite','expectedFingerprint'=>$preview['fingerprint']]));
check($receipt['applied'],'Owned currency can change to exact publication currency without false authority drift');
$f=portFixture();reject(fn()=>$f['port']->capture($f['snapshot'],[101],false),'Outer snapshot required');
$f['db']->begin();foreach([[],[101,101],['101'],range(1,101)] as $ids)reject(fn()=>$f['port']->capture($f['snapshot'],$ids,true),'Invalid target list');check($f['db']->inTransaction(),'Invalid target retains caller transaction');$f['db']->rollback();
$f=portFixture(1);$f['db']->execute('INSERT INTO b_iblock_element_property (IBLOCK_ELEMENT_ID,IBLOCK_PROPERTY_ID,VALUE) VALUES (101,279,?)',['42']);
reject(fn()=>$f['service']->command($f['request']),'Duplicate V1 SKU relation rejected');
foreach(['input','settings','rate'] as $drift){
    $f=portFixture();$preview=$f['service']->command($f['request']);$before=tables($f);
    $f['coreState']->hook=static function()use($f,$drift):void {
        if($drift==='input')$f['db']->execute('UPDATE b_iblock_element_prop_s15 SET PROPERTY_722=?',['200']);
        elseif($drift==='settings')$f['db']->execute('UPDATE b_option SET VALUE=? WHERE NAME=?',['2','SETTINGS_AGGREGATE_REVISION']);
        else $f['db']->execute('UPDATE b_catalog_currency_rate SET RATE=120');
    };
    reject(fn()=>$f['service']->command(array_replace($f['request'],['action'=>'applyCatalogWrite','expectedFingerprint'=>$preview['fingerprint']])), 'Concurrent '.$drift.' drift during core calculation');
    check($f['calls']->writes===0 && tables($f)===$before,'Concurrent authority drift rejected before mutation');
}
foreach([false,true] as $lock){
    $f=portFixture();
    $mysql=new class($f['db']) implements \Prospektweb\Calc\Documents\SqlConnection {
        public array $queries=[];public bool $badEngine=false;
        public function __construct(private \Prospektweb\Calc\Documents\PdoConnection $db){}
        public function dialect():string{return 'mysql';}
        public function inTransaction():bool{return $this->db->inTransaction();}
        public function begin(bool $readSnapshot=false):void{$this->db->begin($readSnapshot);}
        public function commit():void{$this->db->commit();}
        public function rollback():void{$this->db->rollback();}
        public function execute(string $sql,array $parameters=[]):void{$this->db->execute($sql,$parameters);}
        public function rows(string $sql,array $parameters=[]):array{
            $this->queries[]=$sql;
            if(str_contains($sql,'information_schema.TABLES')){
                $tables=$parameters?:['b_catalog_price','b_catalog_product'];
                return array_map(fn(string $table):array=>['TABLE_NAME'=>$table,'ENGINE'=>$this->badEngine && $table==='b_option'?'MyISAM':'InnoDB'],$tables);
            }
            return $this->db->rows(str_replace(' FOR UPDATE','',$sql),$parameters);
        }
    };
    $port=new BitrixDocumentCatalogWritePort($mysql,'site:s1','user:1',$f['services']);$mysql->begin(!$lock);$captured=$port->capture($f['snapshot'],[101,102],$lock);
    foreach($mysql->queries as $sql)if(str_contains($sql,' LIMIT ')&&!str_contains($sql,'information_schema'))check(str_ends_with($sql,' FOR UPDATE')===$lock,'All bounded adapter reads have correct locking mode');
    $mysql->badEngine=true;reject(fn()=>$port->capture($f['snapshot'],[101,102],$lock),'Nontransactional settings invalidate whole chain');$mysql->rollback();
}
echo "PASS $checks native Bitrix catalog port assertions\n";
