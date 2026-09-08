<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/native_catalog_write_fixture.php';
require_once dirname(__DIR__).'/lib/Documents/BitrixDocumentCatalogTargets.php';
use Prospektweb\Calc\Documents\BitrixDocumentCatalogTargets;
set_error_handler(static function(int $severity,string $message,string $file,int $line):void {if(error_reporting() & $severity)throw new ErrorException($message,0,$severity,$file,$line);});
$checks=0;
function check(bool $ok,string $message):void {global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function rejected(callable $call,string $message):void {try{$call();}catch(Throwable $error){check(true,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
function targetsFixture(int $version=2):array {
    $f=nativeWriteFixture();$db=$f['db'];
    $db->execute('CREATE TABLE b_iblock (ID INTEGER PRIMARY KEY,VERSION INTEGER)');$db->execute('INSERT INTO b_iblock VALUES (14,1),(15,?)',[$version]);
    $db->execute('CREATE TABLE b_catalog_iblock (IBLOCK_ID INTEGER PRIMARY KEY,PRODUCT_IBLOCK_ID INTEGER,SKU_PROPERTY_ID INTEGER)');$db->execute('INSERT INTO b_catalog_iblock VALUES (14,0,0),(15,14,279)');
    $db->execute('CREATE TABLE b_iblock_property (ID INTEGER PRIMARY KEY,IBLOCK_ID INTEGER,ACTIVE TEXT,PROPERTY_TYPE TEXT,USER_TYPE TEXT,MULTIPLE TEXT,LINK_IBLOCK_ID INTEGER)');$db->execute('INSERT INTO b_iblock_property VALUES (279,15,?,?,?,?,14)',['Y','E','SKU','N']);
    $db->execute('CREATE TABLE b_iblock_element (ID INTEGER PRIMARY KEY,IBLOCK_ID INTEGER,NAME TEXT,ACTIVE TEXT,ACTIVE_FROM TEXT,ACTIVE_TO TEXT)');
    $db->execute('INSERT INTO b_iblock_element VALUES (42,14,?,?,NULL,NULL)',['Product','Y']);
    $db->execute('CREATE TABLE b_iblock_element_prop_s15 (IBLOCK_ELEMENT_ID INTEGER PRIMARY KEY,PROPERTY_279 INTEGER)');
    $db->execute('CREATE TABLE b_iblock_element_property (ID INTEGER PRIMARY KEY,IBLOCK_ELEMENT_ID INTEGER,IBLOCK_PROPERTY_ID INTEGER,VALUE TEXT)');
    $db->execute('CREATE TABLE b_catalog_product (ID INTEGER PRIMARY KEY,TYPE INTEGER)');
    foreach(range(101,205) as $id){
        $db->execute('INSERT INTO b_iblock_element VALUES (?,15,?,?,NULL,NULL)',[$id,'Offer '.$id,'Y']);
        $db->execute('INSERT INTO b_catalog_product VALUES (?,4)',[$id]);
        $db->execute('INSERT INTO b_iblock_element_prop_s15 VALUES (?,42)',[$id]);
        $db->execute('INSERT INTO b_iblock_element_property (IBLOCK_ELEMENT_ID,IBLOCK_PROPERTY_ID,VALUE) VALUES (?,279,?)',[$id,'42']);
    }
    $f['selector']=new BitrixDocumentCatalogTargets($db,'site:s1','user:1','bitrix:test');
    $f['query']=['action'=>'catalogWriteTargets','id'=>'sheet','publicationId'=>$f['published']['id'],'productId'=>42,'afterId'=>0];
    return $f;
}
foreach([1,2] as $version){
    $f=targetsFixture($version);$first=$f['selector']->command($f['query']);
    check(count($first['offers'])===100 && $first['hasMore'] && $first['nextAfterId']===200,'V'.$version.' bounded keyset page');
    $last=$f['selector']->command(array_replace($f['query'],['afterId'=>200]));
    check(array_column($last['offers'],'id')===range(201,205) && !$last['hasMore'] && $last['nextAfterId']===205,'Complete tail with no truncation');
    $empty=$f['selector']->command(array_replace($f['query'],['afterId'=>205]));check($empty['offers']===[] && !$empty['hasMore'] && $empty['nextAfterId']===205,'Empty tail');
    check($first['documentId']==='sheet' && $first['publicationId']===$f['published']['id'] && $first['productId']===42 && $first['afterId']===0,'Native identity in response');
    check(!$f['db']->inTransaction() && $f['db']->rows('SELECT * FROM b_pw_calc_catalog_write')===[] && $f['coreState']->calls===0,'Selector never calculates or writes receipt');
    $f['db']->execute('UPDATE b_iblock_element SET ACTIVE=? WHERE ID=101',['N']);
    $f['db']->execute('UPDATE b_iblock_element SET ACTIVE_FROM=? WHERE ID=102',['2099-01-01 00:00:00']);
    $f['db']->execute('UPDATE b_iblock_element SET ACTIVE_TO=? WHERE ID=103',['2000-01-01 00:00:00']);
    $f['db']->execute('UPDATE b_catalog_product SET TYPE=1 WHERE ID=104');
    $f['db']->execute('UPDATE b_iblock_element_prop_s15 SET PROPERTY_279=43 WHERE IBLOCK_ELEMENT_ID=105');
    $f['db']->execute('UPDATE b_iblock_element_property SET VALUE=? WHERE IBLOCK_ELEMENT_ID=105',['43']);
    $filtered=$f['selector']->command($f['query']);check(array_column($filtered['offers'],'id')===range(106,205) && !$filtered['hasMore'],'Inactive/outdated/foreign/non-SKU offers excluded');
}
foreach([
    'binding'=>['UPDATE b_pw_calc_product_binding SET presentation_id=?',['other']],
    'product-inactive'=>['UPDATE b_iblock_element SET ACTIVE=? WHERE ID=42',['N']],
    'pair'=>['UPDATE b_catalog_iblock SET PRODUCT_IBLOCK_ID=16 WHERE IBLOCK_ID=15',[]],
    'property'=>['UPDATE b_iblock_property SET MULTIPLE=?',['Y']],
    'version'=>['UPDATE b_iblock SET VERSION=3 WHERE ID=15',[]],
    'archived'=>['UPDATE b_pw_calc_document SET archived=1',[]],
    'corrupt'=>['UPDATE b_pw_calc_site_publication SET snapshot_json=?',['{}']],
] as $name=>[$sql,$params]){
    $f=targetsFixture();$f['db']->execute($sql,$params);rejected(fn()=>$f['selector']->command($f['query']),$name);check(!$f['db']->inTransaction(),'Failure ends own snapshot');
}
$f=targetsFixture(1);$f['db']->execute('INSERT INTO b_iblock_element_property (IBLOCK_ELEMENT_ID,IBLOCK_PROPERTY_ID,VALUE) VALUES (101,279,?)',['42']);rejected(fn()=>$f['selector']->command($f['query']),'Duplicate V1 link rejected');
$f=targetsFixture();
foreach([['productId'=>43],['productId'=>'42'],['afterId'=>-1],['afterId'=>'0'],['publicationId'=>'s_'.str_repeat('0',64)],['provider'=>'bitrix:other'],['actor'=>'user:2'],['action'=>'applyCatalogWrite']] as $patch)rejected(fn()=>$f['selector']->command(array_replace($f['query'],$patch)),'Invalid or foreign selector input');
rejected(fn()=>(new BitrixDocumentCatalogTargets($f['db'],'site:s2','user:1','bitrix:test'))->command($f['query']),'Site scope enforced');
rejected(fn()=>(new BitrixDocumentCatalogTargets($f['db'],'site:s1','user:1','bitrix:other'))->command($f['query']),'Provider scope enforced');
$f['db']->begin();rejected(fn()=>$f['selector']->command($f['query']),'Nested transaction refused');check($f['db']->inTransaction(),'Foreign transaction retained');$f['db']->rollback();
echo "PASS $checks native catalog selector assertions\n";
restore_error_handler();
