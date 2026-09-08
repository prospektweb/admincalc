<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/DocumentCatalogWritePort.php';
require_once __DIR__ . '/BitrixCatalogPropertySnapshot.php';
require_once __DIR__ . '/BitrixCatalogStateWriter.php';
require_once dirname(__DIR__) . '/Services/CalculatorInputMappingService.php';

/** Native Bitrix capture/write adapter. The application owns ACL, transactions and core calls. */
final class BitrixDocumentCatalogWritePort implements DocumentCatalogWritePort
{
    private SqlConnection $db;
    private DocumentRepository $repository;
    private BitrixCatalogStateWriter $writer;
    private string $scope;
    private array $services;
    private bool $lock = false;
    private array $evidence = [];
    private array $tables = [];

    /** Services are internal test/infrastructure seams, never HTTP request fields. */
    public function __construct(SqlConnection $db, string $scope, string $actor, array $services = [])
    {
        if (!preg_match('/^site:[A-Za-z0-9]{1,2}$/D', $scope) || !preg_match('/^user:[1-9][0-9]{0,8}$/D', $actor)) throw new \InvalidArgumentException('Trusted site and actor required.');
        if (!$services) {
            if (!\Bitrix\Main\Loader::includeModule('prospektweb.frontcalc')) throw new \RuntimeException('FrontCalc input adapter unavailable.',503);
            $services = ['settings_names' => array_merge(\Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::canonicalSettingOptionNames(), [\Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::revisionOptionName()]),
                'settings_decode' => [\Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::class,'decodeCapturedRows'],
                'input_builder' => static function(array $properties, string $document, string $publication, array $runtime, array $mappings, int $product, int $offer, string $name): array {
                    $reader = static function(int $element,array $source) use($properties): ?array {return $properties[$source['scope']][$element][$source['property_id']]??null;};
                    return (new \Prospektweb\Frontcalc\Service\DocumentCatalogInputBuilder($reader))->build($document,$publication,$runtime,$mappings,$product,$offer,$name);
                }];
        }
        if (!is_array($services['settings_names']??null) || !is_callable($services['settings_decode']??null) || !is_callable($services['input_builder']??null)) throw new \InvalidArgumentException('Incomplete catalog adapter services.');
        $this->db=$db; $this->scope=$scope; $this->services=$services;
        $this->repository=new DocumentRepository($db,$scope,$actor);
        $this->writer=new BitrixCatalogStateWriter($db,$services['mutation']??null);
    }

    public function capture(object $sitePublication, array $offerIds, bool $lock): array
    {
        if (!$this->db->inTransaction()) throw new \LogicException('Catalog capture requires the application snapshot.');
        if ($lock && $this->db instanceof BitrixConnection) $this->db->assertCatalogWriteTransaction();
        $ids=self::ids($offerIds); $this->lock=$lock; $this->evidence=[]; $this->tables=[];
        $id=$sitePublication->documentId??null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$id)) throw new \InvalidArgumentException('Invalid publication identity.');
        $stored=$this->repository->sitePublication($id);
        if ($lock) $this->repository->lockSitePublication($id,$stored['id']);
        $publication=$this->rows('b_pw_calc_site_publication','id,snapshot_hash,snapshot_json','id=? AND document_id=?',[$stored['id'],$id],'id',1);
        if (count($publication)!==1 || $publication[0]['snapshot_hash']!==$stored['snapshotHash']
            || !hash_equals($stored['snapshotHash'],hash('sha256',$publication[0]['snapshot_json']))
            || DocumentCatalogWritePlan::hash(json_decode($publication[0]['snapshot_json'],false,64,JSON_THROW_ON_ERROR))!==DocumentCatalogWritePlan::hash($sitePublication)) throw new DocumentConflict('Публикация изменилась.');
        // The repository locks metadata/pointer during apply. Include their engines,
        // but not mutable draft revision/timestamps in the published input fingerprint.
        foreach (['b_pw_calc_document','b_pw_calc_site_active','b_pw_calc_site_identity'] as $table) $this->tables[$table]=true;
        $connection=$sitePublication->connection;
        $products=self::id($connection->productsCatalog??null); $offers=self::id($connection->offersCatalog??null);
        if ($products===$offers) throw new DocumentConflict('Каталоги товара и ТП совпадают.');
        $provider=$connection->provider??null;
        if (!is_string($provider) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$provider)) throw new DocumentConflict('Некорректный provider.');
        $modules=$this->rows('b_module','ID','LOWER(ID) IN (?,?)',['prospektweb.calc','prospektweb.frontcalc'],'ID',2);
        if (array_column($modules,'ID')!==['prospektweb.calc','prospektweb.frontcalc']) throw new DocumentConflict('Модули каталога не зарегистрированы.');
        $names=$this->services['settings_names'];
        $options=$this->rows('b_option','MODULE_ID,NAME,VALUE,SITE_ID','LOWER(MODULE_ID)=? AND LOWER(NAME) IN ('.self::marks($names).')',
            array_merge(['prospektweb.frontcalc'],array_map('strtolower',$names)),'MODULE_ID,NAME,SITE_ID',count($names)*4);
        $settings=($this->services['settings_decode'])($options);
        if (($settings['settings']['PRODUCTS_IBLOCK_ID']??null)!==(string)$products || ($settings['settings']['OFFERS_IBLOCK_ID']??null)!==(string)$offers) throw new DocumentConflict('Пара каталогов изменилась в настройках FrontCalc.');
        $providerRows=$this->rows('b_option','MODULE_ID,NAME,VALUE,SITE_ID','LOWER(MODULE_ID)=? AND LOWER(NAME)=?', ['prospektweb.calc','document_resource_provider'],'MODULE_ID,NAME,SITE_ID',4);
        if (count($providerRows)!==1 || $providerRows[0]['MODULE_ID']!=='prospektweb.calc'
            || $providerRows[0]['NAME']!=='DOCUMENT_RESOURCE_PROVIDER' || $providerRows[0]['SITE_ID']!==null || $providerRows[0]['VALUE']!==$provider) throw new DocumentConflict('Provider сайта изменился или неоднозначен.');
        $site=$this->rows('b_lang','LID,ACTIVE','LID=?',[substr($this->scope,5)],'LID',1);
        if (count($site)!==1 || $site[0]['LID']!==substr($this->scope,5) || $site[0]['ACTIVE']!=='Y') throw new DocumentConflict('Сайт неактивен.');
        $pairs=$this->rows('b_catalog_iblock','*','IBLOCK_ID IN (?,?)',[$products,$offers],'IBLOCK_ID',2);
        $byIblock=[];foreach($pairs as $row)$byIblock[self::id($row['IBLOCK_ID'])]=$row;
        if (count($byIblock)!==2 || !isset($byIblock[$products],$byIblock[$offers]) || (int)$byIblock[$products]['PRODUCT_IBLOCK_ID']!==0
            || self::id($byIblock[$offers]['PRODUCT_IBLOCK_ID'])!==$products) throw new DocumentConflict('Связь каталогов товара и ТП изменилась.');
        $skuProperty=self::id($byIblock[$offers]['SKU_PROPERTY_ID']);
        $skuRows=$this->rows('b_iblock_property','*','ID=?',[$skuProperty],'ID',1);
        $iblocks=$this->rows('b_iblock','ID,VERSION','ID IN (?,?)',[$products,$offers],'ID',2);
        $versions=[];foreach($iblocks as $row)$versions[self::id($row['ID'])]=(int)$row['VERSION'];
        if (count($skuRows)!==1 || self::id($skuRows[0]['IBLOCK_ID'])!==$offers || $skuRows[0]['ACTIVE']!=='Y'
            || $skuRows[0]['PROPERTY_TYPE']!=='E' || !in_array((string)($skuRows[0]['USER_TYPE']??''),['','SKU'],true)
            || $skuRows[0]['MULTIPLE']!=='N' || self::id($skuRows[0]['LINK_IBLOCK_ID'])!==$products
            || !in_array($versions[$offers]??null,[1,2],true)) throw new DocumentConflict('Схема связи SKU не подтверждена.');
        if ($versions[$offers]===2) {
            $parents=$this->rows('b_iblock_element_prop_s'.$offers,'IBLOCK_ELEMENT_ID,PROPERTY_'.$skuProperty.' AS PARENT_ID',
                'IBLOCK_ELEMENT_ID IN ('.self::marks($ids).')',$ids,'IBLOCK_ELEMENT_ID',100);
        } else {
            $parents=$this->rows('b_iblock_element_property','ID,IBLOCK_ELEMENT_ID,VALUE AS PARENT_ID','IBLOCK_ELEMENT_ID IN ('.self::marks($ids).') AND IBLOCK_PROPERTY_ID=?',
                array_merge($ids,[$skuProperty]),'IBLOCK_ELEMENT_ID,ID',100);
        }
        $parentByOffer=[];
        foreach($parents as $row) {
            $offer=self::id($row['IBLOCK_ELEMENT_ID']);
            if (!in_array($offer,$ids,true) || isset($parentByOffer[$offer])) throw new DocumentConflict('Неоднозначная связь ТП с товаром.');
            $parentByOffer[$offer]=self::id($row['PARENT_ID']);
        }
        if (count($parentByOffer)!==count($ids)) throw new DocumentConflict('У ТП отсутствует связанный товар.');
        $productIds=array_values(array_unique(array_values($parentByOffer)));sort($productIds,SORT_NUMERIC);
        $bindings=$this->rows('b_pw_calc_product_binding','*','scope_id=? AND provider=? AND catalog_key=? AND product_key IN ('.self::marks($productIds).')',
            array_merge([$this->scope,$provider,(string)$products],array_map('strval',$productIds)),'product_key',100);
        $presentations=[];foreach($connection->products as $row)$presentations[$row->key]=$row->presentationId;
        if (count($bindings)!==count($productIds)) throw new DocumentConflict('Товар не привязан к этой публикации.');
        foreach($bindings as $binding) {
            if ($binding['scope_id']!==$this->scope || $binding['provider']!==$provider || $binding['catalog_key']!==(string)$products
                || $binding['document_id']!==$id || $binding['publication_id']!==$stored['id']
                || !isset($presentations[$binding['product_key']]) || $presentations[$binding['product_key']]!==$binding['presentation_id']) throw new DocumentConflict('Привязка товара к калькулятору изменилась.');
        }
        $runtime=json_decode(json_encode($sitePublication->runtime,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR);
        $mappings=json_decode(json_encode($connection->inputMappings,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR);
        $inputs=(new BitrixCatalogPropertySnapshot($this->db))->capture($products,$offers,$productIds,$ids,array_column($mappings,'source'),$lock);
        (new \Prospektweb\Calc\Services\CalculatorInputMappingService())->validateDocumentMappings($mappings,$runtime,$inputs['sourceAuthority']);
        $states=$this->writer->capture($ids,$lock);
        $priceTypes=[];foreach($connection->priceTypes as $binding)$priceTypes[]=self::id($binding->key);
        if (!$priceTypes || count($priceTypes)>100 || count(array_unique($priceTypes))!==count($priceTypes)) throw new DocumentConflict('Неоднозначные типы цен.');
        sort($priceTypes,SORT_NUMERIC);
        $groups=$this->rows('b_catalog_group','*','ID IN ('.self::marks($priceTypes).')',$priceTypes,'ID',100);
        if (array_map(static fn(array $row):int=>self::id($row['ID']),$groups)!==$priceTypes) throw new DocumentConflict('Привязанный тип цены удалён.');
        $currency=$sitePublication->core->plan->document->execution->currency??null;
        if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D',$currency)) throw new DocumentConflict('Валюта публикации некорректна.');
        $currencies=[$currency];$protected=[];$result=[];
        foreach($ids as $offer) {
            $state=$states[$offer];
            if ((int)($state['product']['TYPE']??0)!==4) throw new DocumentConflict('Целевой элемент не является товарным предложением каталога.');
            // Owned currencies may legitimately change to the publication's
            // currency. Only unowned prices add stable read-only currency authority.
            foreach($state['state']['prices'] as $row)if(!in_array($row['typeId'],$priceTypes,true))$currencies[]=$row['currency'];
            $product=$state['product'];foreach(['PURCHASING_PRICE','PURCHASING_CURRENCY','WIDTH','LENGTH','HEIGHT','WEIGHT','TIMESTAMP_X'] as $key)unset($product[$key]);
            $protected[$offer]=['product'=>$product,'unownedPrices'=>array_values(array_filter($state['prices'],static fn(array $row):bool=>!in_array((int)$row['CATALOG_GROUP_ID'],$priceTypes,true)))];
            $name=$inputs['elements']['selected_offer'][$offer]['NAME'];
            $resolved=($this->services['input_builder'])($inputs['properties'],$id,$stored['id'],$runtime,$mappings,$parentByOffer[$offer],$offer,$name);
            $result[]=['offerId'=>$offer,'productId'=>$parentByOffer[$offer],'name'=>$name,'values'=>$resolved['values'],
                'execution'=>$resolved['execution'],'current'=>$state['state']];
        }
        $currencies=array_values(array_unique($currencies));sort($currencies,SORT_STRING);
        $currencyRows=$this->rows('b_catalog_currency','*','CURRENCY IN ('.self::marks($currencies).") OR BASE='Y'",$currencies,'CURRENCY',200);
        if (array_diff($currencies,array_column($currencyRows,'CURRENCY')) || count(array_filter($currencyRows,static fn(array $row):bool=>$row['BASE']==='Y'))!==1) throw new DocumentConflict('Валютная схема каталога неполна.');
        $rateCurrencies=array_column($currencyRows,'CURRENCY');
        $this->rows('b_catalog_currency_rate','*','CURRENCY IN ('.self::marks($rateCurrencies).')',$rateCurrencies,'CURRENCY,DATE_RATE,ID',20000);
        $this->assertEngines();
        return ['authority'=>(object)['provider'=>$provider,'productsCatalog'=>(string)$products,'offersCatalog'=>(string)$offers,
            'fingerprint'=>DocumentCatalogWritePlan::hash([$this->evidence,$inputs['authority'],$protected])],'offers'=>$result];
    }

    public function write(array $targets): void { $this->writer->write($targets); }

    private function rows(string $table,string $columns,string $where,array $parameters,string $order,int $limit): array
    {
        $rows=$this->db->rows('SELECT '.$columns.' FROM '.$table.' WHERE '.$where.' ORDER BY '.$order.' LIMIT '.($limit+1).($this->lock && $this->db->dialect()==='mysql'?' FOR UPDATE':''),$parameters);
        if(count($rows)>$limit)throw new DocumentConflict('Источник каталога неоднозначен или слишком велик.');
        $this->tables[$table]=true;$this->evidence[]=[$table,$rows];return $rows;
    }
    private function assertEngines(): void
    {
        if($this->db->dialect()!=='mysql')return;
        $tables=array_keys($this->tables);sort($tables);
        $rows=$this->db->rows('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.self::marks($tables).') ORDER BY TABLE_NAME',$tables);
        if(array_column($rows,'TABLE_NAME')!==$tables || count(array_filter($rows,static fn(array $row):bool=>strtoupper((string)$row['ENGINE'])==='INNODB'))!==count($tables))throw new DocumentConflict('Вся цепочка каталога должна поддерживать транзакции.');
    }
    private static function id($value): int
    {
        if((!is_int($value)&&!is_string($value))||!preg_match('/^[1-9][0-9]{0,8}$/D',(string)$value))throw new DocumentConflict('Некорректный ID каталога.');
        return (int)$value;
    }
    private static function ids(array $ids): array
    {
        if(!array_is_list($ids)||!$ids||count($ids)>100||count(array_unique($ids,SORT_REGULAR))!==count($ids))throw new \InvalidArgumentException('Invalid offer targets.');
        foreach($ids as $id){if(!is_int($id))throw new \InvalidArgumentException('Invalid offer ID.');self::id($id);}
        sort($ids,SORT_NUMERIC);return $ids;
    }
    private static function marks(array $values): string {return implode(',',array_fill(0,count($values),'?'));}
}
