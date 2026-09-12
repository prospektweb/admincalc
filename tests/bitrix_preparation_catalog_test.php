<?php
declare(strict_types=1);
namespace Prospektweb\Frontcalc\Service {
final class FrontcalcSettingsAuthority {
 public static function canonicalSettingOptionNames(){return ['products_iblock_id','offers_iblock_id'];}
 public static function revisionOptionName(){return 'revision';}
 public static function decodeCapturedRows($rows){$s=[];foreach($rows as $r)$s[strtoupper($r['NAME'])]=$r['VALUE'];return ['settings'=>$s];}
}}
namespace {
require_once __DIR__.'/bitrix_catalog_property_snapshot_test.php';
require_once __DIR__.'/../lib/Documents/BitrixPreparationCatalog.php';
use Prospektweb\Calc\Documents\{BitrixPreparationCatalog,BitrixCatalogStateWriter};
$f=fixture(1,2,1);$db=$f['db'];
$db->execute('ALTER TABLE b_iblock_property ADD COLUMN LINK_IBLOCK_ID INTEGER');
$db->execute('ALTER TABLE b_iblock_element ADD COLUMN XML_ID TEXT');
$db->execute("INSERT INTO b_iblock_property (ID,IBLOCK_ID,CODE,ACTIVE,PROPERTY_TYPE,USER_TYPE,MULTIPLE,VERSION,LINK_IBLOCK_ID) VALUES (279,15,'CML2_LINK','Y','E','','N',2,14)");
$db->execute('ALTER TABLE b_iblock_element_prop_s15 ADD COLUMN PROPERTY_279 TEXT');$db->execute("UPDATE b_iblock_element_prop_s15 SET PROPERTY_279='1001'");
foreach([
 'CREATE TABLE b_user (ID INTEGER, ACTIVE TEXT)',
 'CREATE TABLE b_user_group (USER_ID INTEGER, GROUP_ID INTEGER, DATE_ACTIVE_FROM TEXT, DATE_ACTIVE_TO TEXT)',
 'CREATE TABLE b_lang (LID TEXT, ACTIVE TEXT)',
 'CREATE TABLE b_iblock_site (IBLOCK_ID INTEGER,SITE_ID TEXT)',
 'CREATE TABLE b_option (MODULE_ID TEXT,NAME TEXT,VALUE TEXT,SITE_ID TEXT)',
 'CREATE TABLE b_pw_calc_product_binding (scope_id TEXT,provider TEXT,catalog_key TEXT,product_key TEXT,document_id TEXT,presentation_id TEXT)',
 'CREATE TABLE b_catalog_iblock (IBLOCK_ID INTEGER,PRODUCT_IBLOCK_ID INTEGER,SKU_PROPERTY_ID INTEGER)',
 'CREATE TABLE b_catalog_product (ID INTEGER PRIMARY KEY,TYPE INTEGER,PURCHASING_PRICE REAL,PURCHASING_CURRENCY TEXT,WIDTH REAL,LENGTH REAL,HEIGHT REAL,WEIGHT REAL,QUANTITY REAL,MEASURE INTEGER)',
 'CREATE TABLE b_catalog_price (ID INTEGER PRIMARY KEY,PRODUCT_ID INTEGER,CATALOG_GROUP_ID INTEGER,PRICE REAL,CURRENCY TEXT,QUANTITY_FROM INTEGER,QUANTITY_TO INTEGER)',
 'CREATE TABLE b_catalog_group (ID TEXT,NAME TEXT)',
 'CREATE TABLE b_catalog_rounding (ID INTEGER)',
 'CREATE TABLE b_catalog_currency (ID INTEGER)',
 'CREATE TABLE b_catalog_measure (ID INTEGER)',
 'CREATE TABLE b_module_to_module (ID INTEGER,FROM_MODULE_ID TEXT)'
] as $sql)$db->execute($sql);
$db->execute("INSERT INTO b_user VALUES (1,'Y')");$db->execute('INSERT INTO b_user_group VALUES (1,1,NULL,NULL)');$db->execute("INSERT INTO b_lang VALUES ('s1','Y')");$db->execute("INSERT INTO b_iblock_site VALUES (14,'s1'),(15,'s1')");
$db->execute("INSERT INTO b_option VALUES ('prospektweb.frontcalc','products_iblock_id','14',NULL),('prospektweb.frontcalc','offers_iblock_id','15',NULL),('prospektweb.calc','document_resource_provider','bitrix:test',NULL)");
$db->execute('INSERT INTO b_catalog_iblock VALUES (14,0,0),(15,14,279)');$db->execute('INSERT INTO b_catalog_product (ID,TYPE) VALUES (1001,3),(2001,4)');
$db->execute("INSERT INTO b_catalog_group VALUES ('1','BASE')");$db->execute("UPDATE b_iblock_element SET ACTIVE='N'");
$port=new BitrixPreparationCatalog($db,'site:s1','bitrix:test','user:1',new BitrixCatalogStateWriter($db,fn()=>throw new RuntimeException('Capture must not write')));
$site=['provider'=>'bitrix:test','productsCatalog'=>'14','offersCatalog'=>'15','products'=>[['key'=>'1001','presentationId'=>'BASE']],'inputMappings'=>[],'formBindings'=>['bindings'=>[]],'priceTypes'=>[['key'=>'1','typeId'=>'retail']]];
$document=(object)['id'=>'sheet','form'=>(object)['fields'=>[(object)['fieldId'=>'quantity','type'=>'number']]]];
$bindings=['variant'=>['offer_id'=>'2001','scope_id'=>'site:s1']];
$capture=fn($b=[])=>$port->capture($site,$document,1001,$b,true);
$db->begin();$first=$capture();check($first['offerIds']===[2001],'Unowned existing inactive SKU read without adoption');check($capture($bindings)['elements'][2001]['ACTIVE']==='N','Exact bound inactive SKU allowed');check($db->inTransaction(),'Capture retains caller transaction');$db->rollback();
foreach([
 "UPDATE b_iblock_element_prop_s15 SET PROPERTY_279='9999'"=>'foreign parent',
 'UPDATE b_iblock_element SET IBLOCK_ID=14 WHERE ID=2001'=>'foreign offer catalog',
 'UPDATE b_catalog_iblock SET PRODUCT_IBLOCK_ID=99 WHERE IBLOCK_ID=15'=>'foreign catalog topology',
 "UPDATE b_user SET ACTIVE='N'"=>'disabled actor',
 'DELETE FROM b_user_group'=>'revoked admin rights',
 "UPDATE b_option SET VALUE='16' WHERE NAME='offers_iblock_id'"=>'changed Frontcalc pair',
 "UPDATE b_option SET VALUE='other' WHERE NAME='document_resource_provider'"=>'changed provider',
 "DELETE FROM b_iblock_site WHERE IBLOCK_ID=15"=>'foreign site',
 "INSERT INTO b_option VALUES ('aspro.premier','event_sync','Y',NULL)"=>'sibling stock mutation mode'
] as $sql=>$label){$db->begin();$db->execute($sql);reject(fn()=>$capture($bindings),$label);$db->rollback();}
$db->begin();$new=$site;$new['inputMappings']=[['source'=>['scope'=>'selected_offer','iblock_id'=>15,'property_id'=>999,'property_code'=>'UNKNOWN'],'target'=>['field_id'=>'qty'],'value_mode'=>'scalar']];reject(fn()=>$port->capture($new,$document,1001,[],true),'unknown mapped property');$db->rollback();
echo "PASS $checks combined property and preparation catalog assertions\n";
}
