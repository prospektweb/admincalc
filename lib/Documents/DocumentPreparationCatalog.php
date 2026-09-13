<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__.'/DocumentRepository.php';
require_once __DIR__.'/DocumentProductPreparation.php';
require_once __DIR__.'/DocumentPreparationPropertyPlan.php';
require_once __DIR__.'/DocumentQuotePricing.php';
require_once __DIR__.'/PreparationCatalogView.php';
require_once __DIR__.'/PreparationPriceOverrides.php';
require_once __DIR__.'/PreparationPropertyLinks.php';

/** Saved-result generation only. All catalog IO is performed by the trusted port.
 * No execution gateway, implicit result replacement or nested transaction owner. */
final class DocumentPreparationCatalog
{
    public function __construct(private SqlConnection $db, private string $scope, private string $actor,
        private DocumentRepository $documents, private object $catalog, private $form)
    {
        if(!preg_match('/^site:[A-Za-z0-9]{1,2}$/D',$scope)||!preg_match('/^user:[1-9][0-9]{0,8}$/D',$actor))throw new \InvalidArgumentException('Trusted site and actor required.');
    }
    public function command(array $c):array
    {
        $keys=array_keys($c);sort($keys);$expected=['action','operation','id','versionId','expectedRevision','storefrontId','productKey','resultIds','newActive'];
        if(($c['operation']??null)==='apply')$expected[]='fingerprint';sort($expected);
        if(($c['operation']??null)==='savePrices'){$expected[]='expectedSettingsRevision';$expected[]='edits';sort($expected);}
        if(in_array($c['operation']??null,['mapping','saveMapping'],true)){$expected[]='sourceId';$expected[]='targetIds';if($c['operation']==='saveMapping'){$expected[]='links';$expected[]='expectedMappingRevision';$expected[]='mappingFingerprint';}sort($expected);}
        if($keys!==$expected||($c['action']??null)!=='preparationCatalog'||!in_array($c['operation'],['preview','apply','savePrices','mapping','saveMapping'],true))throw new \InvalidArgumentException('Некорректная команда генерации.');
        foreach(['id','versionId','storefrontId'] as $key)if(!is_string($c[$key])||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$c[$key]))throw new \InvalidArgumentException('Некорректный контекст генерации.');
        if(!is_int($c['expectedRevision'])||$c['expectedRevision']<1||!is_string($c['productKey'])||!preg_match('/^[1-9][0-9]{0,8}$/D',$c['productKey'])||!is_bool($c['newActive']))throw new \InvalidArgumentException('Некорректный товар, ревизия или активность.');
        if(!is_array($c['resultIds'])||!array_is_list($c['resultIds'])||!$c['resultIds']||count($c['resultIds'])>100||count(array_unique($c['resultIds']))!==count($c['resultIds']))throw new \InvalidArgumentException('Выберите от 1 до 100 текущих вариантов.');
        foreach($c['resultIds'] as $id)if(!is_string($id)||!preg_match('/^pr_[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Некорректный результат подготовки.');
        sort($c['resultIds']);$apply=$c['operation']==='apply';
        if($apply&&(!is_string($c['fingerprint'])||!preg_match('/^[a-f0-9]{64}$/D',$c['fingerprint'])))throw new \InvalidArgumentException('Сначала просмотрите точные изменения.');
        if($this->db->inTransaction())throw new \LogicException('Generation must own its transaction.');
        $this->db->begin();
        try{
            $before=$this->capture($c,!in_array($c['operation'],['preview','mapping'],true));
            if(in_array($c['operation'],['mapping','saveMapping'],true)){
                if($c['operation']==='saveMapping'){$this->saveMapping($before,$c);$before=$this->capture($c,true);}
                $result=$this->mappingView($before,$c);$this->db->commit();return $result;
            }
            $receiptId=$apply?DocumentCatalogWritePlan::hash([$this->scope,$this->actor,$before['preparation']['id'],$c['fingerprint']]):null;
            if($apply){
                $rows=$this->db->rows('SELECT receipt_json,receipt_hash FROM b_pw_calc_preparation_write WHERE id=? AND scope_id=? AND actor_id=?',[$receiptId,$this->scope,$this->actor]);
                if($rows){
                    if(!hash_equals($rows[0]['receipt_hash'],hash('sha256',$rows[0]['receipt_json'])))throw new \RuntimeException('Нарушена целостность подтверждения записи.');
                    $r=json_decode($rows[0]['receipt_json'],true,64,JSON_THROW_ON_ERROR);
                    if($r['resultIds']!==$c['resultIds']||$r['newActive']!==$c['newActive']||!hash_equals($r['afterHash'],DocumentCatalogWritePlan::hash($before)))throw new DocumentConflict('После записи изменились исходные данные или каталог. Нужен новый просмотр.');
                    $this->db->commit();return $r+['replayed'=>true];
                }
            }
            $plan=$this->plan($before,$c);
            if($c['operation']==='savePrices'){
                $this->savePrices($before,$plan,$c);
                $fresh=$this->capture($c,true);$result=$this->plan($fresh,$c)['public'];
                $this->db->commit();return $result;
            }
            if(!$apply){$this->db->commit();return $plan['public'];}
            if(!$plan['public']['ready'])throw new DocumentConflict('В плане есть конфликты. Исправьте их до записи.');
            if(!hash_equals($plan['public']['fingerprint'],$c['fingerprint']))throw new DocumentConflict('Подготовка, настройки или каталог изменились. Просмотрите изменения заново.');
            $ids=$this->catalog->write($before['catalog'],$plan);
            if(array_keys($ids)!==array_keys($plan['variants']))throw new \RuntimeException('Неполный ответ записи каталога.');
            $mappingHash=$plan['public']['mappingRevision'];
            $parentPriceHash=$plan['parentProjection']!==null?$this->catalog->parentPriceHash($before['catalog']['productId']):null;
            foreach($plan['variants'] as $variant=>$target){
                $offer=$ids[$variant];if(!is_int($offer)||$offer<1)throw new \RuntimeException('Каталог не вернул ID предложения.');
                if(isset($before['bindings'][$variant])){
                    if($offer!==(int)$before['bindings'][$variant]['offer_id'])throw new DocumentConflict('Связанное предложение заменено.');
                    $this->db->execute('UPDATE b_pw_calc_preparation_offer SET result_id=?,mapping_hash=?,receipt_id=?,parent_price_hash=? WHERE preparation_id=? AND variant_key=?',[$target['resultId'],$mappingHash,$receiptId,$parentPriceHash,$before['preparation']['id'],$variant]);
                }else $this->db->execute('INSERT INTO b_pw_calc_preparation_offer (preparation_id,variant_key,scope_id,offer_id,result_id,mapping_hash,receipt_id,parent_price_hash) VALUES (?,?,?,?,?,?,?,?)',[$before['preparation']['id'],$variant,$this->scope,$offer,$target['resultId'],$mappingHash,$receiptId,$parentPriceHash]);
            }
            if($parentPriceHash!==null)$this->db->execute('UPDATE b_pw_calc_preparation_offer SET parent_price_hash=? WHERE preparation_id=? AND scope_id=?',[$parentPriceHash,$before['preparation']['id'],$this->scope]);
            $after=$this->capture($c,true);
            $this->catalog->verify($before['catalog'],$after['catalog'],$plan,$ids);
            $r=['contract'=>'prospektweb.calculator/preparation-write-v1','preparationId'=>$before['preparation']['id'],'resultIds'=>$c['resultIds'],'newActive'=>$c['newActive'],
                'fingerprint'=>$c['fingerprint'],'afterHash'=>DocumentCatalogWritePlan::hash($after),'mappingRevision'=>$mappingHash,'offerIds'=>$ids,
                'variants'=>$plan['variants'],'productProperties'=>$plan['productProperties'],'parentProjection'=>$plan['parentProjection'],'source'=>['documentId'=>$c['id'],'versionId'=>$c['versionId'],'revision'=>$c['expectedRevision']],
                'createdAt'=>gmdate('Y-m-d\TH:i:s\Z'),'applied'=>true];
            $json=DocumentCatalogWritePlan::canonical($r);
            $this->db->execute('INSERT INTO b_pw_calc_preparation_write (id,preparation_id,scope_id,actor_id,fingerprint,receipt_json,receipt_hash,created_at) VALUES (?,?,?,?,?,?,?,?)',[$receiptId,$before['preparation']['id'],$this->scope,$this->actor,$c['fingerprint'],$json,hash('sha256',$json),$r['createdAt']]);
            $this->db->commit();return $r;
        }catch(\Throwable $e){$this->db->rollback();throw $e;}
    }
    /** Internal maintenance only, deliberately not exposed by command(). Catalog elements
     * must already have been removed through their native lifecycle by the caller. */
    public function removeOwnedGeneration(string $preparationId,int $expectedRevision,string $expectedHash,?string $expectedPricesHash=null):void
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$preparationId)||$expectedRevision<1||!preg_match('/^[a-f0-9]{64}$/D',$expectedHash))throw new \InvalidArgumentException('Exact generation maintenance identity required.');
        if($this->db->inTransaction())throw new \LogicException('Maintenance must own its transaction.');
        $this->db->begin();
        try{
            $lock=$this->db->dialect()==='mysql'?' FOR UPDATE':'';
            $heads=$this->db->rows('SELECT document_id,revision FROM b_pw_calc_preparation WHERE id=? AND scope_id=?'.$lock,[$preparationId,$this->scope]);
            if(count($heads)!==1||(int)$heads[0]['revision']!==$expectedRevision)throw new DocumentConflict('Preparation changed.');
            $bindings=$this->db->rows('SELECT * FROM b_pw_calc_preparation_offer WHERE preparation_id=? ORDER BY variant_key'.$lock,[$preparationId]);
            $receipts=$this->db->rows('SELECT * FROM b_pw_calc_preparation_write WHERE preparation_id=? ORDER BY id'.$lock,[$preparationId]);
            if(!$bindings||!$receipts||!hash_equals($expectedHash,DocumentCatalogWritePlan::hash([$bindings,$receipts])))throw new DocumentConflict('Generation composition changed.');
            foreach($receipts as $r)if($r['scope_id']!==$this->scope||$r['actor_id']!==$this->actor||!hash_equals($r['receipt_hash'],hash('sha256',$r['receipt_json'])))throw new DocumentConflict('Generation contains another author or invalid receipt.');
            foreach($bindings as $b)if($b['scope_id']!==$this->scope)throw new DocumentConflict('Generation scope changed.');
            $prices=$this->db->rows('SELECT * FROM b_pw_calc_preparation_prices WHERE preparation_id=?'.$lock,[$preparationId]);
            if($prices&&($expectedPricesHash===null||!hash_equals($expectedPricesHash,DocumentCatalogWritePlan::hash($prices))||$prices[0]['scope_id']!==$this->scope||$prices[0]['actor_id']!==$this->actor))throw new DocumentConflict('Price settings changed or belong to another author.');
            $this->catalog->assertRemoved(array_map('intval',array_column($bindings,'offer_id')));
            $this->db->execute('DELETE FROM b_pw_calc_preparation_offer WHERE preparation_id=?',[$preparationId]);
            $this->db->execute('DELETE FROM b_pw_calc_preparation_write WHERE preparation_id=?',[$preparationId]);
            $this->db->execute('DELETE FROM b_pw_calc_preparation_prices WHERE preparation_id=?',[$preparationId]);
            $this->db->commit();
        }catch(\Throwable $e){$this->db->rollback();throw $e;}
    }
    private function capture(array $c,bool $lock):array
    {
        $suffix=$lock&&$this->db->dialect()==='mysql'?' FOR UPDATE':'';
        if($this->db->dialect()==='mysql'){
            $tables=['b_pw_calc_document','b_pw_calc_preparation','b_pw_calc_preparation_offer','b_pw_calc_preparation_prices','b_pw_calc_preparation_result','b_pw_calc_preparation_write','b_pw_calc_property_links','b_pw_calc_revision','b_pw_calc_version'];
            $engines=$this->db->rows('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',array_fill(0,count($tables),'?')).') ORDER BY TABLE_NAME',$tables);
            if(array_column($engines,'TABLE_NAME')!==$tables||count(array_filter($engines,fn($r)=>strtoupper($r['ENGINE'])==='INNODB'))!==count($tables))throw new DocumentConflict('Подготовка и журнал записи должны поддерживать транзакции.');
        }
        $rows=$this->db->rows('SELECT id FROM b_pw_calc_document WHERE id=? AND scope_id=?'.$suffix,[$c['id'],$this->scope]);
        if(!$rows)throw new DocumentConflict('Калькулятор недоступен.');
        $source=$this->documents->versions()->loadInTransaction($c['id'],$c['versionId']);
        if($source['revision']!==$c['expectedRevision']||$source['archived']||($source['versionArchived']??false)||!is_string($source['connectionJson']))throw new DocumentConflict('Версия или подключение изменились. Откройте подготовку заново.');
        $document=json_decode($source['bodyJson'],false,64,JSON_THROW_ON_ERROR);
        $site=json_decode(SiteConnection::canonical($source['connectionJson'],json_decode($source['bodyJson'],true)),true,64,JSON_THROW_ON_ERROR);
        $linked=array_values(array_filter($site['products'],fn($p)=>$p['key']===$c['productKey']&&$p['presentationId']===$c['storefrontId']));
        if(count($linked)!==1)throw new DocumentConflict('Товар больше не связан с этой витриной.');
        $rows=$this->db->rows('SELECT * FROM b_pw_calc_preparation WHERE scope_id=? AND document_id=? AND storefront_id=? AND product_key=? AND provider=? AND catalog_id=?'.$suffix,[$this->scope,$c['id'],$c['storefrontId'],$c['productKey'],$site['provider'],$site['productsCatalog']]);
        if(count($rows)!==1)throw new DocumentConflict('Подготовка товара отсутствует.');$preparation=$rows[0];
        $all=$this->db->rows('SELECT * FROM b_pw_calc_preparation_result WHERE preparation_id=? ORDER BY id'.$suffix,[$preparation['id']]);$results=[];
        foreach($all as $row)if(in_array($row['id'],$c['resultIds'],true)){
            if(!(bool)$row['active']||!hash_equals(DocumentCalculationSnapshots::signature($source['bodyJson'],$c['storefrontId']),$row['form_hash']))throw new DocumentConflict('Результат '.$row['id'].' не текущий или форма несовместима. Выберите актуальную подготовку.');
            if(!hash_equals($row['payload_hash'],hash('sha256',$row['payload_json'])))throw new \RuntimeException('Нарушена целостность результата.');
            $results[$row['id']]=$row;
        }
        if(count($results)!==count($c['resultIds']))throw new DocumentConflict('Состав подготовки изменился.');
        $bindings=[];foreach($this->db->rows('SELECT * FROM b_pw_calc_preparation_offer WHERE preparation_id=? ORDER BY variant_key'.$suffix,[$preparation['id']]) as $row)$bindings[$row['variant_key']]=$row;
        $linkId=DocumentCatalogWritePlan::hash([$this->scope,$site['provider'],$site['productsCatalog'],$site['offersCatalog']]);
        $storedLinks=$this->db->rows('SELECT * FROM b_pw_calc_property_links WHERE id=? AND scope_id=?'.$suffix,[$linkId,$this->scope]);
        $propertyLinks=['id'=>$linkId,'revision'=>0,'values'=>[]];
        if($storedLinks){$l=$storedLinks[0];if(!hash_equals($l['settings_hash'],hash('sha256',$l['settings_json'])))throw new \RuntimeException('Нарушена целостность сопоставлений.');$propertyLinks['revision']=(int)$l['revision'];$propertyLinks['values']=json_decode($l['settings_json'],true,64,JSON_THROW_ON_ERROR);}
        $usedPropertyIds=array_map('intval',array_column(array_column($site['inputMappings'],'source'),'property_id'));
        if($propertyLinks['values']||isset($c['targetIds'])){
            $connections=$this->db->rows('SELECT r.connection_json FROM b_pw_calc_version v JOIN b_pw_calc_document d ON d.id=v.document_id JOIN b_pw_calc_revision r ON r.document_id=v.document_id AND r.revision=v.head_revision WHERE d.scope_id=? AND d.archived=0 AND v.deleted=0 ORDER BY v.id LIMIT 1001'.$suffix,[$this->scope]);
            if(count($connections)>1000)throw new DocumentConflict('Слишком много версий для проверки сопоставлений.');
            foreach($connections as $entry){if(!$entry['connection_json'])continue;$connection=json_decode($entry['connection_json'],true,64,JSON_THROW_ON_ERROR);
                if(($connection['provider']??null)===$site['provider']&&($connection['productsCatalog']??null)===$site['productsCatalog']&&($connection['offersCatalog']??null)===$site['offersCatalog'])$usedPropertyIds=array_merge($usedPropertyIds,array_map('intval',array_column(array_column($connection['inputMappings']??[],'source'),'property_id')));}
        }
        $usedPropertyIds=array_values(array_unique($usedPropertyIds));sort($usedPropertyIds);
        $extra=array_merge(array_column($propertyLinks['values'],'sourceId'),array_column($propertyLinks['values'],'targetId'));
        if(isset($c['targetIds'])){
            if(!is_int($c['sourceId'])||!is_array($c['targetIds'])||!array_is_list($c['targetIds'])||count($c['targetIds'])>100)throw new \InvalidArgumentException('Некорректные свойства сопоставления.');
            foreach($c['targetIds'] as $id)if(!is_int($id)||$id<1)throw new \InvalidArgumentException('Некорректный ID свойства.');
            $extra=array_merge($extra,$c['targetIds'],[$c['sourceId']]);
        }
        $catalog=$this->catalog->capture($site,$document,(int)$c['productKey'],$bindings,$lock,array_values(array_unique($extra)));
        $stored=$this->db->rows('SELECT * FROM b_pw_calc_preparation_prices WHERE preparation_id=? AND scope_id=?'.$suffix,[$preparation['id'],$this->scope]);
        $priceSettings=['revision'=>0,'values'=>[]];
        if($stored){$r=$stored[0];if(!hash_equals($r['settings_hash'],hash('sha256',$r['settings_json'])))throw new \RuntimeException('Нарушена целостность настроек цен.');$priceSettings=['revision'=>(int)$r['revision'],'values'=>json_decode($r['settings_json'],true,64,JSON_THROW_ON_ERROR)];}
        return compact('source','preparation','results','bindings','catalog','priceSettings','propertyLinks','usedPropertyIds');
    }
    private function plan(array $before,array $c):array
    {
        $site=json_decode($before['source']['connectionJson'],false,64,JSON_THROW_ON_ERROR);$variants=[];$mapped=[];$errors=[];$rows=[];$priceViews=[];
        foreach($before['results'] as $result){
            $key=$result['variant_key'];$payload=json_decode($result['payload_json'],false,64,JSON_THROW_ON_ERROR);$target=null;$properties=[];$rowErrors=[];
            try{
                if(($payload->response->source->documentId??null)!==$c['id']||($payload->document->id??null)!==$c['id']
                    ||!hash_equals($key,DocumentProductPreparation::variantKey($result['form_hash'],$payload)))throw new \InvalidArgumentException('Происхождение или идентичность варианта не подтверждены.');
                $shape=($this->form)($payload,$c['storefrontId']);
                $fieldMaps=[];
                foreach($site->inputMappings as $mapping){$m=json_decode(json_encode($mapping,JSON_THROW_ON_ERROR),true);$field=$m['target']['field_id'];
                    $saved=DocumentPreparationPropertyPlan::savedInput($payload->values,$field,$shape);
                    if(!$saved['intended'])continue;
                    $fieldMaps[$field]=true;$id=$m['source']['property_id'];
                    $properties[]=DocumentPreparationPropertyPlan::value($m,$saved['value'],$before['catalog']['schemas'][$id]??[],$before['catalog']['choices'][$id]??[]);
                }
                foreach($payload->document->form->fields as $field){$id=$field->fieldId;$v=$payload->values->$id??null;
                    if(($shape[$id]['visible']??false)&&!isset($fieldMaps[$id])&&!isset($field->systemKey)&&!in_array($v,[null,'',[],false],true))throw new \InvalidArgumentException('Для поля «'.$field->label.'» не настроено свойство товара или ТП.');
                }
                foreach($before['propertyLinks']['values'] as $link)if(in_array($link['targetId'],$before['usedPropertyIds'],true))throw new \InvalidArgumentException('Целевое свойство #'.$link['targetId'].' уже используется в расчёте.');
                $properties=PreparationPropertyLinks::expand($properties,$before['propertyLinks']['values'],$before['catalog']);
                $quote=json_decode(json_encode($payload->response->result,JSON_THROW_ON_ERROR),true);
                if(count($quote['parts']??[])!==1)throw new \InvalidArgumentException('Для нескольких деталей требуется явное правило итоговых размеров.');
                $priceConnection=DocumentQuotePricing::connection($payload->document,$quote,$site);
                $offerId=isset($before['bindings'][$key])?(int)$before['bindings'][$key]['offer_id']:null;
                $current=$offerId?$before['catalog']['states'][$offerId]['state']:['purchasingPrice'=>['value'=>null,'currency'=>null],'dimensions'=>['width'=>null,'length'=>null,'height'=>null,'weight'=>null],'prices'=>[]];
                $target=DocumentCatalogWritePlan::target($quote,$priceConnection,$current);
                $target=$this->catalog->round($target,array_map(fn($t)=>(int)$t->key,$priceConnection->priceTypes));
                $prices=PreparationPriceOverrides::resolve($target,array_map(fn($t)=>(int)$t->key,$priceConnection->priceTypes),$before['priceSettings']['values'][$key]??[]);
                $target=$prices['state'];$priceViews[$key]=$prices;
                if($prices['orphanKeys'])$rowErrors[]='Сохранённая ручная цена относится к другому диапазону или валюте. Сбросьте неприменимые цены.';
                $name=$quote['name']??null;if(!is_string($name)||trim($name)===''||mb_strlen($name)>255)throw new \InvalidArgumentException('Название результата должно содержать от 1 до 255 символов.');
                $variants[$key]=['resultId'=>$result['id'],'payloadHash'=>$result['payload_hash'],'provenance'=>json_decode($result['provenance_json'],true),'offerId'=>$offerId,'name'=>$name,'newActive'=>$c['newActive'],
                    'state'=>$target,'priceTypeIds'=>array_map(fn($t)=>(int)$t->key,$priceConnection->priceTypes),'properties'=>$properties];
                $this->catalog->validate($before['catalog'],$variants[$key],$key);
            }catch(\InvalidArgumentException|DocumentConflict $e){$rowErrors[]=$e->getMessage();}
            $mapped[$key]=$properties;$rows[$key]=['variantKey'=>$key,'resultId'=>$result['id'],'name'=>json_decode($result['summary_json'],true)['name'],'errors'=>$rowErrors];
        }
        $consensus=DocumentPreparationPropertyPlan::consensus($mapped);$errors=array_column($consensus['conflicts'],'message');
        // Keep variant lookup explicit; product properties are written exactly once.
        foreach($variants as $key=>&$variant)$variant['properties']=$consensus['offerProperties'][$key]??[];unset($variant);
        $plan=['variants'=>$variants,'productProperties'=>$consensus['productProperties']];
        $plan['parentProjection']=null;
        try{$plan['parentProjection']=$this->catalog->parentProjection($before['catalog'],$plan);}
        catch(\InvalidArgumentException|DocumentConflict $e){$errors[]=$e->getMessage();}
        $display=$this->catalog->diff($before['catalog'],$plan);
        foreach($rows as $key=>&$row){$row+=($display['variants'][$key]??[]);if($row['errors']||$errors)$row['action']='conflict';}unset($row);
        $view=PreparationCatalogView::build($before['catalog'],$mapped,$consensus,$variants);
        foreach($rows as $key=>&$row){$row['properties']=$view['propertyCells'][$key]??[];$row['state']=$variants[$key]['state']??null;$row['priceSlots']=$priceViews[$key]['slots']??[];$row['orphanPriceKeys']=$priceViews[$key]['orphanKeys']??[];}unset($row);
        unset($view['propertyCells']);
        $ready=!$errors&&!array_filter($rows,fn($r)=>$r['errors']);
        $public=['ready'=>$ready,'rows'=>array_values($rows),'productDiff'=>$display['productDiff'],'errors'=>$errors,'newActive'=>$c['newActive'],'preparationRevision'=>(int)$before['preparation']['revision'],
            'mappingRevision'=>DocumentCatalogWritePlan::hash([$site,$before['propertyLinks']]),'fingerprint'=>DocumentCatalogWritePlan::hash([$before,$plan,$c['newActive']])];
        return $plan+['public'=>$public+$view+['settingsRevision'=>$before['priceSettings']['revision']]];
    }

    private function savePrices(array $before,array $plan,array $c):void
    {
        if(!is_int($c['expectedSettingsRevision'])||$c['expectedSettingsRevision']!==$before['priceSettings']['revision'])throw new DocumentConflict('Настройки цен изменились. Обновите таблицу.');
        if(!is_array($c['edits'])||!array_is_list($c['edits'])||!$c['edits']||count($c['edits'])>1000)throw new \InvalidArgumentException('Некорректный список цен.');
        $rows=array_column($plan['public']['rows'],null,'variantKey');$values=$before['priceSettings']['values'];$seen=[];
        foreach($c['edits'] as $edit){$e=(array)$edit;$keys=array_keys($e);sort($keys);
            if($keys!==['key','mode','value','variantKey']||!is_string($e['variantKey'])||!is_string($e['key'])||!in_array($e['mode'],['custom','calculated'],true))throw new \InvalidArgumentException('Некорректный адрес цены.');
            $v=$e['variantKey'];$key=$e['key'];$identity=$v.':'.$key;if(isset($seen[$identity]))throw new \InvalidArgumentException('Цена повторяется.');$seen[$identity]=true;
            $row=$rows[$v]??null;if(!$row)throw new DocumentConflict('Вариант цены больше не выбран.');
            $slot=array_column($row['priceSlots'],null,'key')[$key]??null;
            if(!$slot&&!(in_array($key,$row['orphanPriceKeys'],true)&&$e['mode']==='calculated'))throw new DocumentConflict('Диапазон, тип или валюта цены изменились.');
            if($e['mode']==='calculated'){if($e['value']!==null)throw new \InvalidArgumentException('Для сброса значение должно быть null.');unset($values[$v][$key]);}
            else $values[$v][$key]=PreparationPriceOverrides::amount($e['value']);
            if(empty($values[$v]))unset($values[$v]);
        }
        $json=DocumentCatalogWritePlan::canonical($values);$revision=$before['priceSettings']['revision']+1;$stamp=gmdate('Y-m-d\TH:i:s\Z');
        if($before['priceSettings']['revision']===0)$this->db->execute('INSERT INTO b_pw_calc_preparation_prices (preparation_id,scope_id,revision,settings_json,settings_hash,actor_id,updated_at) VALUES (?,?,?,?,?,?,?)',[$before['preparation']['id'],$this->scope,$revision,$json,hash('sha256',$json),$this->actor,$stamp]);
        else $this->db->execute('UPDATE b_pw_calc_preparation_prices SET revision=?,settings_json=?,settings_hash=?,actor_id=?,updated_at=? WHERE preparation_id=? AND scope_id=?',[$revision,$json,hash('sha256',$json),$this->actor,$stamp,$before['preparation']['id'],$this->scope]);
    }

    private function mappingView(array $before,array $c):array
    {
        $site=json_decode($before['source']['connectionJson'],true);$catalog=$before['catalog'];$direct=array_map('intval',array_column(array_column($site['inputMappings'],'source'),'property_id'));
        if(!in_array($c['sourceId'],$direct,true))throw new \InvalidArgumentException('Источник должен быть явно связан с расчётом.');
        $source=$catalog['schemas'][$c['sourceId']]??null;if(!$source)throw new DocumentConflict('Исходное свойство удалено.');
        $meta=static fn($s)=>['id'=>(int)$s['ID'],'iblockId'=>(int)$s['IBLOCK_ID'],'name'=>$s['NAME']??$s['CODE'],'code'=>$s['CODE'],'type'=>$s['PROPERTY_TYPE'],'multiple'=>$s['MULTIPLE']==='Y',
            'choices'=>array_map(fn($v)=>['value'=>$s['PROPERTY_TYPE']==='L'?(int)$v['ID']:$v['XML_ID'],'label'=>$v['VALUE']],$catalog['choices'][(int)$s['ID']]??[])];
        $others=array_values(array_filter($before['propertyLinks']['values'],fn($l)=>$l['sourceId']!==$c['sourceId']));$occupied=array_column($others,'targetId');$sources=array_column($others,'sourceId');
        $candidates=[];foreach($catalog['allSchemas']??[] as $s){$id=(int)$s['ID'];$u=strtolower((string)($s['USER_TYPE']??''));
            if($s['ACTIVE']==='Y'&&str_starts_with($s['CODE'],'CALC_')&&in_array($s['PROPERTY_TYPE'],['L','N','S'],true)&&($u===''||($s['PROPERTY_TYPE']==='S'&&$u==='directory'))&&!in_array($id,array_merge($before['usedPropertyIds'],$occupied,$sources),true))$candidates[]=$meta($s);}
        $values=[];foreach($meta($source)['choices'] as $choice)$values[DocumentCatalogWritePlan::hash([$choice['value']])]=[$choice['value']];
        foreach($before['results'] as $result){$payload=json_decode($result['payload_json']);foreach($site['inputMappings'] as $m)if($m['source']['property_id']===$c['sourceId']){
            $field=$m['target']['field_id'];if(!property_exists($payload->values,$field))continue;
            $r=DocumentPreparationPropertyPlan::value($m,$payload->values->$field,$source,$catalog['choices'][$c['sourceId']]??[]);$values[DocumentCatalogWritePlan::hash($r['values'])]=$r['values'];}}
        $links=array_values(array_filter($before['propertyLinks']['values'],fn($l)=>$l['sourceId']===$c['sourceId']));
        foreach($links as $l)foreach($l['values'] as $v)$values[DocumentCatalogWritePlan::hash($v['source'])]=$v['source'];
        return ['revision'=>$before['propertyLinks']['revision'],'source'=>$meta($source),'candidates'=>$candidates,'sourceValues'=>array_values($values),'links'=>$links,
            'fingerprint'=>DocumentCatalogWritePlan::hash([$before['propertyLinks'],$catalog['configuration']??$catalog,$before['usedPropertyIds'],$c['sourceId'],$c['targetIds']])];
    }
    private function saveMapping(array $before,array $c):void
    {
        $view=$this->mappingView($before,$c);
        if(!is_int($c['expectedMappingRevision'])||$c['expectedMappingRevision']!==$view['revision']||!is_string($c['mappingFingerprint'])||!hash_equals($view['fingerprint'],$c['mappingFingerprint']))throw new DocumentConflict('Настройки или схема изменились. Откройте сопоставление заново.');
        if(!is_array($c['links'])||!array_is_list($c['links']))throw new \InvalidArgumentException('Некорректные сопоставления.');
        $submitted=[];foreach($c['links'] as $l){$l=json_decode(json_encode($l,JSON_THROW_ON_ERROR),true);if(($l['sourceId']??null)!==$c['sourceId']||!in_array($l['targetId']??null,$c['targetIds'],true))throw new \InvalidArgumentException('Постороннее свойство в сопоставлении.');$submitted[]=$l;}
        $site=json_decode($before['source']['connectionJson'],true);$direct=array_map('intval',array_column(array_column($site['inputMappings'],'source'),'property_id'));
        $kept=array_values(array_filter($before['propertyLinks']['values'],fn($l)=>$l['sourceId']!==$c['sourceId']));
        // Validate graph across all edges, but keep externally deleted targets dormant.
        $active=[];$dormant=[];foreach($kept as $l){if(!isset($before['catalog']['schemas'][$l['sourceId']]))throw new DocumentConflict('Исходное свойство #'.$l['sourceId'].' удалено.');
            if(!isset($before['catalog']['schemas'][$l['targetId']])){$dormant[]=$l;continue;}$active[]=array_intersect_key($l,array_flip(['sourceId','targetId','values']));}
        $values=array_merge(PreparationPropertyLinks::validate(array_merge($active,$submitted),$before['catalog'],$before['usedPropertyIds']),$dormant);
        $json=DocumentCatalogWritePlan::canonical($values);$revision=$view['revision']+1;$stamp=gmdate('Y-m-d\TH:i:s\Z');
        if($view['revision']===0)$this->db->execute('INSERT INTO b_pw_calc_property_links (id,scope_id,revision,settings_json,settings_hash,actor_id,updated_at) VALUES (?,?,?,?,?,?,?)',[$before['propertyLinks']['id'],$this->scope,$revision,$json,hash('sha256',$json),$this->actor,$stamp]);
        else $this->db->execute('UPDATE b_pw_calc_property_links SET revision=?,settings_json=?,settings_hash=?,actor_id=?,updated_at=? WHERE id=? AND scope_id=?',[$revision,$json,hash('sha256',$json),$this->actor,$stamp,$before['propertyLinks']['id'],$this->scope]);
    }
}
