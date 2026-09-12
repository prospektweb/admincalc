<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__.'/BitrixCatalogPropertySnapshot.php';
require_once __DIR__.'/BitrixCatalogStateWriter.php';

/** Bitrix boundary for exact prepared variants; never discovers ownership by names. */
final class BitrixPreparationCatalog
{
    private bool $lock=false;private array $evidence=[];private array $tables=[];
    private BitrixCatalogStateWriter $writer;
    public function __construct(private SqlConnection $db,private string $scope,private string $provider,private string $actor,?BitrixCatalogStateWriter $writer=null)
    {$this->writer=$writer??new BitrixCatalogStateWriter($db);}
    private function rows(string $table,string $where,array $args=[],int $limit=20000,string $columns='*'):array
    {
        $rows=$this->db->rows('SELECT '.$columns.' FROM '.$table.' WHERE '.$where.' LIMIT '.($limit+1).($this->lock&&$this->db->dialect()==='mysql'?' FOR UPDATE':''),$args);
        if(count($rows)>$limit)throw new DocumentConflict('Слишком большой состав каталога. Сократите подготовку.');
        usort($rows,fn($a,$b)=>strcmp(DocumentCatalogWritePlan::canonical($a),DocumentCatalogWritePlan::canonical($b)));
        $this->tables[$table]=true;$this->evidence[]=[$table,$rows];return $rows;
    }
    public function capture(array $site,object $document,int $product,array $bindings,bool $lock):array
    {
        if(!$this->db->inTransaction())throw new \LogicException('Catalog capture requires a transaction.');
        if($lock&&$this->db instanceof BitrixConnection)$this->db->assertCatalogWriteTransaction();
        $this->lock=$lock;$this->evidence=[];$this->tables=[];
        if(!preg_match('/^user:([1-9][0-9]{0,8})$/D',$this->actor,$actor))throw new DocumentConflict('Не определён автор записи.');
        $users=$this->rows('b_user','ID=?',[(int)$actor[1]],1,'ID,ACTIVE');
        $groups=$this->rows('b_user_group','USER_ID=? AND GROUP_ID=1 AND (DATE_ACTIVE_FROM IS NULL OR DATE_ACTIVE_FROM<=CURRENT_TIMESTAMP) AND (DATE_ACTIVE_TO IS NULL OR DATE_ACTIVE_TO>=CURRENT_TIMESTAMP)',[(int)$actor[1]],1);
        if(count($users)!==1||$users[0]['ACTIVE']!=='Y'||count($groups)!==1)throw new DocumentConflict('Права администратора изменились.');
        $siteRows=$this->rows('b_lang','LID=?',[substr($this->scope,5)],1);if(count($siteRows)!==1||$siteRows[0]['ACTIVE']!=='Y')throw new DocumentConflict('Сайт неактивен.');
        if($site['provider']!==$this->provider)throw new DocumentConflict('Provider подключения изменился.');
        $products=(int)$site['productsCatalog'];$offers=(int)$site['offersCatalog'];
        if($products<1||$offers<1||$products===$offers)throw new DocumentConflict('Неверные каталоги подключения.');
        $siteCatalogs=$this->rows('b_iblock_site','IBLOCK_ID IN (?,?) AND SITE_ID=?',[$products,$offers,substr($this->scope,5)],2);
        if(count($siteCatalogs)!==2)throw new DocumentConflict('Каталоги не относятся к текущему сайту.');
        $names=array_merge(\Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::canonicalSettingOptionNames(),[\Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::revisionOptionName()]);
        $settings=\Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::decodeCapturedRows($this->rows('b_option','MODULE_ID=? AND NAME IN ('.implode(',',array_fill(0,count($names),'?')).')',array_merge(['prospektweb.frontcalc'],$names)));
        if(($settings['settings']['PRODUCTS_IBLOCK_ID']??null)!==(string)$products||($settings['settings']['OFFERS_IBLOCK_ID']??null)!==(string)$offers)throw new DocumentConflict('Настройки каталогов изменились.');
        $provider=$this->rows('b_option','MODULE_ID=? AND LOWER(NAME)=?',['prospektweb.calc','document_resource_provider']);
        if(count($provider)!==1||$provider[0]['VALUE']!==$this->provider||$provider[0]['SITE_ID']!==null)throw new DocumentConflict('Provider сайта неоднозначен.');
        $owners=$this->rows('b_pw_calc_product_binding','scope_id=? AND provider=? AND catalog_key=? AND product_key=?',[$this->scope,$this->provider,(string)$products,(string)$product]);
        if($owners&&($owners[0]['document_id']!==$document->id||$owners[0]['presentation_id']!==array_values(array_filter($site['products'],fn($p)=>$p['key']===(string)$product))[0]['presentationId']))throw new DocumentConflict('Товар связан с другим калькулятором или витриной.');
        $pairs=$this->rows('b_catalog_iblock','IBLOCK_ID IN (?,?)',[$products,$offers]);$pair=array_column($pairs,null,'IBLOCK_ID');
        if(count($pair)!==2||!isset($pair[$products],$pair[$offers])||(int)$pair[$products]['PRODUCT_IBLOCK_ID']!==0||(int)$pair[$offers]['PRODUCT_IBLOCK_ID']!==$products)throw new DocumentConflict('Связь товарного каталога и SKU изменилась.');
        $parent=(int)$pair[$offers]['SKU_PROPERTY_ID'];$p=$this->rows('b_iblock_property','ID=?',[$parent]);
        if(count($p)!==1||(int)$p[0]['IBLOCK_ID']!==$offers||(int)$p[0]['LINK_IBLOCK_ID']!==$products||$p[0]['PROPERTY_TYPE']!=='E'||$p[0]['MULTIPLE']!=='N'||$p[0]['ACTIVE']!=='Y')throw new DocumentConflict('Некорректное свойство связи SKU.');
        $iblocks=array_column($this->rows('b_iblock','ID IN (?,?)',[$products,$offers]),null,'ID');
        $offerIds=[];
        if((int)$iblocks[$offers]['VERSION']===2)$parents=$this->rows('b_iblock_element_prop_s'.$offers,'PROPERTY_'.$parent.'=?',[(string)$product],500);
        else $parents=$this->rows('b_iblock_element_property','IBLOCK_PROPERTY_ID=? AND VALUE=?',[$parent,(string)$product],500);
        foreach($parents as $r){$id=(int)$r['IBLOCK_ELEMENT_ID'];if(isset($offerIds[$id]))throw new DocumentConflict('Неоднозначный родитель ТП.');$offerIds[$id]=$id;}
        $boundIds=[];foreach($bindings as $binding){$id=(int)$binding['offer_id'];if(!isset($offerIds[$id])||$binding['scope_id']!==$this->scope)throw new DocumentConflict('Связанное предложение удалено или перенесено к другому товару.');$boundIds[]=$id;}
        $inputs=(new BitrixCatalogPropertySnapshot($this->db))->capture($products,$offers,[$product],$boundIds,array_column($site['inputMappings'],'source'),$lock,true);
        require_once dirname(__DIR__).'/Services/CalculatorInputMappingService.php';
        (new \Prospektweb\Calc\Services\CalculatorInputMappingService())->validateDocumentMappings($site['inputMappings'],['formDefinition'=>json_decode(json_encode($document->form,JSON_THROW_ON_ERROR),true),'bindingDefinition'=>$site['formBindings']],$inputs['sourceAuthority']);
        // Include required unbound properties as schema authority; never create them.
        $schemas=$this->rows('b_iblock_property','IBLOCK_ID IN (?,?)',[$products,$offers]);
        $elements=[];$raw=[];$states=[];
        foreach(['product'=>[$product],'selected_offer'=>array_values($offerIds)] as $scope=>$ids){
            $iblock=$scope==='product'?$products:$offers;
            foreach($ids as $id){
                $e=$this->rows('b_iblock_element','ID=?',[$id],1);if(count($e)!==1||(int)$e[0]['IBLOCK_ID']!==$iblock)throw new DocumentConflict('Элемент перенесён или удалён.');$elements[$id]=$e[0];
                if((int)$iblocks[$iblock]['VERSION']===2){$raw[$id]=['single'=>$this->rows('b_iblock_element_prop_s'.$iblock,'IBLOCK_ELEMENT_ID=?',[$id]),'multiple'=>$this->rows('b_iblock_element_prop_m'.$iblock,'IBLOCK_ELEMENT_ID=?',[$id])];}
                else $raw[$id]=['common'=>$this->rows('b_iblock_element_property','IBLOCK_ELEMENT_ID=?',[$id])];
                $states[$id]=$this->writer->capture([$id],$lock)[$id];
            }
        }
        if((int)$states[$product]['product']['TYPE']!==3)throw new DocumentConflict('Товар должен иметь тип «Товар с предложениями».');
        foreach($offerIds as $id)if((int)$states[$id]['product']['TYPE']!==4)throw new DocumentConflict('Связанный элемент не является торговым предложением.');
        $types=$this->rows('b_catalog_group','1=1',[],100);$known=array_column($types,'ID');
        foreach($site['priceTypes'] as $t)if(!in_array($t['key'],$known,true))throw new DocumentConflict('Настроенный тип цены удалён.');
        $rounding=$this->rows('b_catalog_rounding','1=1');$currencies=$this->rows('b_catalog_currency','1=1',[],200);$measures=$this->rows('b_catalog_measure','1=1',[],200);
        $handlers=$this->rows('b_module_to_module',"FROM_MODULE_ID IN ('iblock','catalog')");
        $defaults=$this->rows('b_option',"MODULE_ID='catalog' AND NAME IN ('default_quantity_trace','default_can_buy_zero','default_subscribe','default_vat_included')");
        $sync=$this->rows('b_option',"MODULE_ID='aspro.premier' AND LOWER(NAME)='event_sync'");
        if(($_SESSION['CUSTOM_UPDATE']??'N')==='Y'||array_filter($sync,fn($r)=>$r['VALUE']==='Y'))throw new DocumentConflict('Включена синхронизация остатков Aspro: она может изменить другие ТП. Генерация остановлена.');
        if($this->db->dialect()==='mysql'){
            $tables=array_keys($this->tables);sort($tables);$engines=$this->db->rows('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',array_fill(0,count($tables),'?')).') ORDER BY TABLE_NAME',$tables);
            if(array_column($engines,'TABLE_NAME')!==$tables||count(array_filter($engines,fn($r)=>strtoupper($r['ENGINE'])==='INNODB'))!==count($tables))throw new DocumentConflict('Каталожная запись требует InnoDB.');
        }
        return ['productId'=>$product,'products'=>$products,'offers'=>$offers,'parentProperty'=>$parent,'schemas'=>$inputs['propertySchemas'],'choices'=>$inputs['propertyChoices'],
            'allSchemas'=>$schemas,'elements'=>$elements,'rawProperties'=>$raw,'states'=>$states,'offerIds'=>array_values($offerIds),
            'configuration'=>DocumentCatalogWritePlan::hash([$settings,$provider,$pairs,$p,$iblocks,$schemas,$types,$rounding,$currencies,$measures,$handlers,$defaults,$sync,$inputs['propertyChoices']]),
            'authority'=>DocumentCatalogWritePlan::hash([$this->evidence,$inputs['authority']])];
    }
    public function round(array $state,array $types):array
    {
        foreach($state['prices'] as &$price)if(in_array($price['typeId'],$types,true))$price['price']=(float)\Bitrix\Catalog\Product\Price::roundPrice($price['typeId'],$price['price'],$price['currency']);unset($price);
        return DocumentCatalogWritePlan::state($state);
    }
    public function assertRemoved(array $ids):void
    {
        $this->assertTransaction();$this->lock=true;
        if(!$ids)throw new \InvalidArgumentException('Exact offer IDs required.');
        foreach($ids as $id){
            if(!is_int($id)||$id<1)throw new \InvalidArgumentException('Invalid offer ID.');
            foreach(['b_iblock_element'=>['ID'],'b_catalog_product'=>['ID'],'b_catalog_price'=>['PRODUCT_ID']] as $table=>$columns)
                if($this->rows($table,$columns[0].'=?',[$id]))throw new DocumentConflict('Сначала удалите точное ТП #'.$id.' штатным жизненным циклом каталога.');
        }
    }
    private function propertyValues(array $catalog,int $element,int $id):array
    {
        $raw=$catalog['rawProperties'][$element];$values=[];
        $schema=$catalog['schemas'][$id]??array_column($catalog['allSchemas'],null,'ID')[$id];
        if(isset($raw['common']))foreach($raw['common'] as $r)if((int)$r['IBLOCK_PROPERTY_ID']===$id&&$r['VALUE']!==null&&$r['VALUE']!=='')$values[]=$r['VALUE'];
        if(isset($raw['single'][0]['PROPERTY_'.$id])&&$raw['single'][0]['PROPERTY_'.$id]!=='')$values[]=$raw['single'][0]['PROPERTY_'.$id];
        foreach($raw['multiple']??[] as $r)if((int)$r['IBLOCK_PROPERTY_ID']===$id){$v=$schema['PROPERTY_TYPE']==='L'?($r['VALUE_ENUM']??$r['VALUE']):$r['VALUE'];if($v!==null&&$v!=='')$values[]=$v;}
        if($schema['PROPERTY_TYPE']==='L')$values=array_map('intval',$values);
        elseif($schema['PROPERTY_TYPE']==='N')$values=array_map('floatval',$values);
        sort($values,SORT_REGULAR);return $values;
    }
    public function diff(array $catalog,array $plan):array
    {
        $properties=function(?int $element,array $rows)use($catalog){$out=[];foreach($rows as $r){$old=$element?$this->propertyValues($catalog,$element,$r['propertyId']):[];$new=$r['values'];$schema=$catalog['schemas'][$r['propertyId']];
            $display=function($values)use($catalog,$schema,$r){return array_map(function($v)use($catalog,$schema,$r){foreach($catalog['choices'][$r['propertyId']]??[] as $c)if(($schema['PROPERTY_TYPE']==='L'?(string)$c['ID']:$c['XML_ID'])===(string)$v)return $c['VALUE'];return $v;},$values);};
            $out[]=['label'=>$schema['NAME']??$schema['CODE'],'path'=>'property.'.$r['propertyId'],'old'=>$old,'new'=>$new,'oldDisplay'=>$display($old),'newDisplay'=>$display($new),'changed'=>DocumentCatalogWritePlan::hash($old)!==DocumentCatalogWritePlan::hash($new)];}return $out;};
        $productDiff=$properties($catalog['productId'],$plan['productProperties']);$variants=[];
        foreach($plan['variants'] as $key=>$v){$id=$v['offerId'];$diff=$properties($id,$v['properties']);
            $old=$id?$catalog['states'][$id]['state']:['purchasingPrice'=>['value'=>null,'currency'=>null],'dimensions'=>['width'=>null,'length'=>null,'height'=>null,'weight'=>null],'prices'=>[]];
            $diff=array_merge([['label'=>'Название','path'=>'name','old'=>$id?$catalog['elements'][$id]['NAME']:null,'new'=>$v['name'],'changed'=>!$id||$catalog['elements'][$id]['NAME']!==$v['name']]],$diff,DocumentCatalogWritePlan::diffs($old,$v['state']));
            $variants[$key]=['offerId'=>$id,'action'=>$id?(array_filter($diff,fn($r)=>$r['changed'])?'update':'unchanged'):'create','diff'=>$diff,'state'=>$v['state'],'active'=>$id?$catalog['elements'][$id]['ACTIVE']:($v['newActive']?'Y':'N')];
        }
        return compact('productDiff','variants');
    }
    private function assertTransaction():void{if($this->db instanceof BitrixConnection)$this->db->assertCatalogWriteTransaction();elseif(!$this->db->inTransaction())throw new \LogicException('Transaction lost.');}
    public function validate(array $catalog,array $variant,string $key):void
    {
        if($variant['offerId']===null){
            $xml='pwprep:'.hash('sha256',$catalog['productId'].':'.$key);
            if($this->rows('b_iblock_element','IBLOCK_ID=? AND XML_ID=?',[$catalog['offers'],$xml]))throw new DocumentConflict('Идентификатор варианта занят несвязанным ТП.');
        }
        $mapped=[];foreach($variant['properties'] as $row)$mapped[$row['propertyId']]=$row['values'];
        foreach($catalog['allSchemas'] as $p)if($p['ACTIVE']==='Y'&&($p['IS_REQUIRED']??'N')==='Y'&&(int)$p['ID']!==$catalog['parentProperty']){
            $id=(int)$p['ID'];$element=(int)$p['IBLOCK_ID']===$catalog['products']?$catalog['productId']:$variant['offerId'];
            $values=$mapped[$id]??($element?$this->propertyValues($catalog,$element,$id):[]);
            if(!$values)throw new DocumentConflict('Обязательное свойство «'.$p['NAME'].'» #'.$id.' не заполнено и не сопоставлено.');
        }
    }
    public function write(array $before,array $plan):array
    {
        $this->assertTransaction();$ids=[];$writes=[];
        $set=function(int $id,array $rows,bool $created=false)use($before){$props=[];foreach($rows as $r)if($created||DocumentCatalogWritePlan::hash($this->propertyValues($before,$id,$r['propertyId']))!==DocumentCatalogWritePlan::hash($r['values']))$props[$r['propertyId']]=$r['values']?:false;
            if($props){\CIBlockElement::SetPropertyValuesEx($id,false,$props);$this->assertTransaction();}};
        $set($before['productId'],$plan['productProperties']);
        foreach($plan['variants'] as $key=>$v){
            $id=$v['offerId'];$element=new \CIBlockElement();
            if($id===null){
                $xml='pwprep:'.hash('sha256',$before['productId'].':'.$key);
                if($this->rows('b_iblock_element','IBLOCK_ID=? AND XML_ID=?',[$before['offers'],$xml]))throw new DocumentConflict('Идентификатор варианта уже занят несвязанным предложением.');
                $props=[$before['parentProperty']=>$before['productId']];foreach($v['properties'] as $r)$props[$r['propertyId']]=$r['values']?:false;
                $id=$element->Add(['IBLOCK_ID'=>$before['offers'],'NAME'=>$v['name'],'ACTIVE'=>$v['newActive']?'Y':'N','XML_ID'=>$xml,'PROPERTY_VALUES'=>$props]);
                if(!$id)throw new DocumentConflict('Не удалось создать ТП: '.$element->LAST_ERROR);$id=(int)$id;$this->assertTransaction();
                if(!\CCatalogProduct::Add(['ID'=>$id,'TYPE'=>4,'QUANTITY'=>0,'QUANTITY_TRACE'=>'Y','CAN_BUY_ZERO'=>'N','SUBSCRIBE'=>'N']))throw new DocumentConflict('Не удалось создать запись товарного каталога.');
                $this->assertTransaction();
            }else{
                if($before['elements'][$id]['NAME']!==$v['name']&&!$element->Update($id,['NAME'=>$v['name']]))throw new DocumentConflict('Не удалось обновить название ТП: '.$element->LAST_ERROR);
                $this->assertTransaction();$set($id,$v['properties']);
            }
            $ids[$key]=$id;$writes[]=['offerId'=>$id,'priceTypeIds'=>$v['priceTypeIds'],'state'=>$v['state']];
        }
        if($writes)$this->writer->write($writes);return $ids;
    }
    public function verify(array $before,array $after,array $plan,array $ids):void
    {
        if($before['configuration']!==$after['configuration'])throw new DocumentConflict('Настройки или схема изменились во время записи.');
        $expectedIds=array_unique(array_merge($before['offerIds'],array_values($ids)));sort($expectedIds);$actual=$after['offerIds'];sort($actual);
        if($actual!==$expectedIds)throw new DocumentConflict('Обработчик изменил состав предложений.');
        $owned=[$before['productId']=>$plan['productProperties']];foreach($plan['variants'] as $key=>$v){$id=$ids[$key];$owned[$id]=$v['properties'];
            if($after['elements'][$id]['NAME']!==$v['name']||(!$v['offerId']&&$after['elements'][$id]['ACTIVE']!==($v['newActive']?'Y':'N'))||DocumentCatalogWritePlan::hash($after['states'][$id]['state'])!==DocumentCatalogWritePlan::hash($v['state']))throw new DocumentConflict('Контрольное чтение ТП не совпало с планом.');}
        foreach($owned as $id=>$rows)foreach($rows as $r)if(DocumentCatalogWritePlan::hash($this->propertyValues($after,$id,$r['propertyId']))!==DocumentCatalogWritePlan::hash($r['values']))throw new DocumentConflict('Свойство #'.$r['propertyId'].' не подтвердило записанное значение.');
        foreach($before['elements'] as $id=>$e){$new=$after['elements'][$id];foreach(['TIMESTAMP_X','MODIFIED_BY'] as $k){unset($e[$k],$new[$k]);}if(isset($owned[$id])&&$id!==$before['productId']){unset($e['NAME'],$new['NAME']);}
            if(DocumentCatalogWritePlan::hash($e)!==DocumentCatalogWritePlan::hash($new))throw new DocumentConflict('Изменено постороннее поле элемента #'.$id.'.');
            $clean=function(array $raw)use($owned,$id){$props=array_column($owned[$id]??[],'propertyId');
                foreach(['common','multiple'] as $group)if(isset($raw[$group]))$raw[$group]=array_values(array_filter($raw[$group],fn($r)=>!in_array((int)$r['IBLOCK_PROPERTY_ID'],$props,true)));
                if(isset($raw['single']))foreach($raw['single'] as &$r)foreach($props as $p){unset($r['PROPERTY_'.$p],$r['DESCRIPTION_'.$p]);}unset($r);return $raw;};
            if(DocumentCatalogWritePlan::hash($clean($before['rawProperties'][$id]))!==DocumentCatalogWritePlan::hash($clean($after['rawProperties'][$id])))throw new DocumentConflict('Изменены несвязанные свойства элемента #'.$id.'.');
            if(!isset($owned[$id])&&DocumentCatalogWritePlan::hash($before['states'][$id])!==DocumentCatalogWritePlan::hash($after['states'][$id]))throw new DocumentConflict('Изменено несвязанное предложение #'.$id.'.');
            if($id===$before['productId']){$old=$before['states'][$id];$new=$after['states'][$id];unset($old['product']['TIMESTAMP_X'],$new['product']['TIMESTAMP_X']);if(DocumentCatalogWritePlan::hash($old)!==DocumentCatalogWritePlan::hash($new))throw new DocumentConflict('Изменены посторонние каталожные параметры товара.');}
            elseif(isset($owned[$id])){
                $old=$before['states'][$id]['product'];$new=$after['states'][$id]['product'];foreach(['PURCHASING_PRICE','PURCHASING_CURRENCY','WIDTH','LENGTH','HEIGHT','WEIGHT','TIMESTAMP_X'] as $k)unset($old[$k],$new[$k]);
                if(DocumentCatalogWritePlan::hash($old)!==DocumentCatalogWritePlan::hash($new))throw new DocumentConflict('Изменены остатки, мера или другие посторонние параметры ТП #'.$id.'.');
                $v=array_values(array_filter($plan['variants'],fn($v)=>$v['offerId']===$id))[0];$unowned=fn($s)=>array_values(array_filter($s['prices'],fn($p)=>!in_array((int)$p['CATALOG_GROUP_ID'],$v['priceTypeIds'],true)));
                if(DocumentCatalogWritePlan::hash($unowned($before['states'][$id]))!==DocumentCatalogWritePlan::hash($unowned($after['states'][$id])))throw new DocumentConflict('Изменена посторонняя цена ТП #'.$id.'.');
            }
        }
    }
}
