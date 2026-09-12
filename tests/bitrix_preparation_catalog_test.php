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
foreach(['b_sale_basket'=>'PRODUCT_ID','b_catalog_store_product'=>'PRODUCT_ID','b_catalog_store_barcode'=>'PRODUCT_ID','b_catalog_product_sets'=>'OWNER_ID,ITEM_ID,SET_ID','b_catalog_docs_element'=>'ELEMENT_ID','b_catalog_product2group'=>'PRODUCT_ID','b_catalog_subscribe'=>'ITEM_ID'] as $table=>$columns){$db->execute('CREATE TABLE '.$table.' ('.implode(',',array_map(fn($c)=>$c.' INTEGER',explode(',',$columns))).')');}
$empty=function()use($db){$db->execute('UPDATE b_catalog_product SET TYPE=1 WHERE ID=1001');$db->execute('UPDATE b_iblock_element_prop_s15 SET PROPERTY_279=NULL');};
$db->begin();$empty();$plain=$capture();check($plain['parentType']===1&&$plain['offerIds']===[],'Native empty simple parent allowed');check($port->diff($plain,['productProperties'=>[],'variants'=>[]])['productDiff'][0]['path']==='productType','First-SKU conversion explicit in preview');$db->rollback();
foreach(['UPDATE b_catalog_product SET QUANTITY=1 WHERE ID=1001','UPDATE b_catalog_product SET PURCHASING_PRICE=10 WHERE ID=1001','UPDATE b_catalog_product SET TYPE=2 WHERE ID=1001','INSERT INTO b_sale_basket VALUES (1001)','INSERT INTO b_catalog_store_product VALUES (1001)','INSERT INTO b_catalog_product_sets VALUES (1001,NULL,NULL)','INSERT INTO b_catalog_docs_element VALUES (1001)','INSERT INTO b_catalog_subscribe VALUES (1001)'] as $sql){$db->begin();$empty();$db->execute($sql);reject(fn()=>$capture(),'commercial or unknown simple parent');$db->rollback();}
$price=fn($amount,$from=null,$to=1)=>['typeId'=>1,'quantityFrom'=>$from,'quantityTo'=>$to,'price'=>(float)$amount,'currency'=>'RUB'];
$plain['currencyRates']=['RUB'=>'1'];$plan=['variants'=>['a'=>['offerId'=>null,'state'=>['prices'=>[$price(590),$price(100,2,2)]]],'b'=>['offerId'=>null,'state'=>['prices'=>[$price(830)]]]],'productProperties'=>[]];
$projection=$port->parentProjection($plain,$plan);check($projection===[array_replace($price(590),['quantityTo'=>null])],'Native minimum for one set, not cheapest bulk range');
$diff=$port->diff($plain,['variants'=>[],'productProperties'=>[],'parentProjection'=>$projection]);check(end($diff['productDiff'])['path']==='parentPrices','Native parent prices explicitly previewed');
$owned=$first;$owned['currencyRates']=['RUB'=>'1'];$owned['derivedParentOwned']=true;$owned['states'][2001]['state']['prices']=[$price(900)];$owned['states'][2001]['prices']=[];
check($port->parentProjection($owned,$plan)[0]['price']===590.0,'Full final SKU set included');
$owned['elements'][2001]['ACTIVE']='Y';$owned['states'][2001]['product']['AVAILABLE']='Y';
check($port->parentProjection($owned,$plan)[0]['price']===900.0,'Available active SKU takes precedence over unavailable new ones');
$owned['derivedParentOwned']=false;reject(fn()=>$port->parentProjection($owned,$plan),'Unowned parent prices protected before write');
$db->begin();$hash=\Prospektweb\Calc\Documents\DocumentCatalogWritePlan::hash($first['states'][1001]['prices']);$ownedBindings=$bindings;$ownedBindings['variant']['parent_price_hash']=$hash;check($capture($ownedBindings)['derivedParentOwned'],'Exact persisted parent price provenance');$ownedBindings['variant']['parent_price_hash']=str_repeat('0',64);reject(fn()=>$capture($ownedBindings),'Manual parent price change invalidates authority');$db->rollback();
echo "PASS $checks combined property and preparation catalog assertions\n";
}
