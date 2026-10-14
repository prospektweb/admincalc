<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Catalog;
require_once __DIR__.'/TradePropertyPlan.php';

/** Sole schema writer for calculation-related properties of storefront catalogs. */
final class TradePropertyService
{
    private const MODULE='prospektweb.calc';
    private array $manifest;
    public function __construct(?array $manifest=null){$this->manifest=$manifest??TradePropertyPlan::manifest();}
    public function inspect(): array{return TradePropertyPlan::build($this->manifest,$this->capture());}
    public function apply(string $expectedHash,bool $confirmed,int $actor): array
    {
        if(!$confirmed||$actor<=0||!preg_match('/^[a-f0-9]{64}$/D',$expectedHash))throw new \InvalidArgumentException('Подтвердите список изменений.');
        $db=\Bitrix\Main\Application::getConnection();
        $lock=$db->query("SELECT GET_LOCK('prospektweb.calc:trade-properties',5) AS ACQUIRED")->fetch();if((int)($lock['ACQUIRED']??0)!==1)throw new \RuntimeException('Свойства обновляются в другой операции.',409);
        try{
            $before=$this->capture();$plan=TradePropertyPlan::build($this->manifest,$before);
            if(!hash_equals($plan['hash'],$expectedHash))throw new \RuntimeException('Состав свойств изменился после анализа. Откройте список заново.',409);
            $backup=$this->backup($before,$plan,$actor);$tracked=$before['tracked'];$applied=[];
            foreach($plan['operations'] as $operation){
                if($operation['blocked'])continue;
                $fields=$operation['fields'];$fields['IBLOCK_ID']=$before['catalogs'][$operation['role']];$key=$operation['key'];
                if(isset($before['links'][$key]))$fields['LINK_IBLOCK_ID']=$before['links'][$key];
                // Preserve unrelated settings/IDs; no enum list goes through property Update.
                $api=new \CIBlockProperty();$id=$operation['id'];
                if($id){if(!$api->Update($id,$fields))throw new \RuntimeException('Не удалось обновить '.$key.': '.$api->LAST_ERROR);}else{$id=(int)$api->Add($fields);if(!$id)throw new \RuntimeException('Не удалось создать '.$key.': '.$api->LAST_ERROR);}
                foreach($operation['enums'] as $enum){$writer=new \CIBlockPropertyEnum();$payload=$enum['fields']+['PROPERTY_ID'=>$id];$ok=$enum['id']?$writer->Update($enum['id'],$payload):$writer->Add($payload);if(!$ok)throw new \RuntimeException('Не удалось записать значение '.$enum['xmlId']);}
                $tracked[$key]=(int)$id;$applied[]=['key'=>$key,'id'=>(int)$id];
            }
            $current=$this->capture();foreach($this->manifest['properties']as$item){$key=$item['role'].':'.$item['fields']['CODE'];$members=$current['properties'][$key]??[];if(count($members)===1)$tracked[$key]=(int)$members[0]['ID'];}
            \Bitrix\Main\Config\Option::set(self::MODULE,'trade_property_registry',json_encode($tracked,JSON_THROW_ON_ERROR));
            \Bitrix\Main\Config\Option::set(self::MODULE,'trade_property_initialized','Y');
            \Bitrix\Main\Config\Option::set(self::MODULE,'trade_property_catalogs',json_encode($before['catalogs'],JSON_THROW_ON_ERROR));
            $after=$this->inspect();if($after['summary']['create']||$after['summary']['update'])throw new \RuntimeException('Перечитывание обнаружило оставшиеся исправимые несоответствия. Сохранена резервная копия '.$backup,409);
            if(class_exists('CEventLog'))\CEventLog::Add(['SEVERITY'=>'SECURITY','AUDIT_TYPE_ID'=>'PROSPEKTWEB_TRADE_PROPERTIES','MODULE_ID'=>self::MODULE,'ITEM_ID'=>'catalog','DESCRIPTION'=>json_encode(['actor'=>$actor,'planHash'=>$expectedHash,'applied'=>$applied,'backup'=>$backup],JSON_THROW_ON_ERROR)]);
            return ['plan'=>$after,'applied'=>$applied,'backup'=>$backup];
        }finally{$db->query("SELECT RELEASE_LOCK('prospektweb.calc:trade-properties')");}
    }
    private function capture(): array
    {
        if(!\Bitrix\Main\ModuleManager::isModuleInstalled(self::MODULE)||!\Bitrix\Main\Loader::includeModule('iblock')||!\Bitrix\Main\Loader::includeModule('catalog'))throw new \RuntimeException('Модуль Calc и торговый каталог должны быть установлены.',503);
        $db=\Bitrix\Main\Application::getConnection();$raw=[];
        // Read configured identity directly, independent of FrontCalc registration or technical-property readiness.
        $r=$db->query("SELECT MODULE_ID,NAME,VALUE,SITE_ID FROM b_option WHERE MODULE_ID='prospektweb.frontcalc' AND NAME IN ('PRODUCTS_IBLOCK_ID','OFFERS_IBLOCK_ID') ORDER BY NAME");
        while($v=$r->fetch()){if($v['MODULE_ID']!=='prospektweb.frontcalc'||!in_array($v['NAME'],['PRODUCTS_IBLOCK_ID','OFFERS_IBLOCK_ID'],true)||$v['SITE_ID']!==null||isset($raw[$v['NAME']]))throw new \RuntimeException('Неоднозначная настройка торгового каталога.',409);$raw[$v['NAME']]=$v['VALUE'];}
        $saved=\Bitrix\Main\Config\Option::get(self::MODULE,'trade_property_catalogs','');$own=$saved===''?null:json_decode($saved,true,8,JSON_THROW_ON_ERROR);
        if($own!==null&&(!is_array($own)||array_keys($own)!==['products','offers']||!is_int($own['products'])||!is_int($own['offers'])||min($own)<=0))throw new \RuntimeException('Повреждена настройка торговых инфоблоков Calc.',409);
        $catalogs=[];
        if($raw){foreach(['products'=>'PRODUCTS_IBLOCK_ID','offers'=>'OFFERS_IBLOCK_ID']as$role=>$name){$id=$raw[$name]??'';if(!is_string($id)||!preg_match('/^[1-9][0-9]*$/D',$id))throw new \RuntimeException('Настройте точную пару инфоблоков товаров и ТП.',409);$catalogs[$role]=(int)$id;}if($own!==null&&$catalogs!==$own)throw new \RuntimeException('Пары торговых инфоблоков Calc и витрины отличаются. Требуется согласованное переподключение.',409);}
        elseif($own!==null)$catalogs=$own;
        else{
            $site=\Bitrix\Main\Config\Option::get(self::MODULE,'document_site_id','');if(!preg_match('/^[A-Za-z0-9]{1,2}$/D',$site))throw new \RuntimeException('Настройте сайт Calc и торговые инфоблоки.',409);
            $escaped=$db->getSqlHelper()->forSql($site);$pairs=[];$cursor=$db->query("SELECT DISTINCT c.PRODUCT_IBLOCK_ID,c.IBLOCK_ID FROM b_catalog_iblock c JOIN b_iblock_site p ON p.IBLOCK_ID=c.PRODUCT_IBLOCK_ID JOIN b_iblock_site o ON o.IBLOCK_ID=c.IBLOCK_ID WHERE c.PRODUCT_IBLOCK_ID>0 AND p.SITE_ID='$escaped' AND o.SITE_ID='$escaped' ORDER BY c.IBLOCK_ID");while($row=$cursor->fetch())$pairs[]=['products'=>(int)$row['PRODUCT_IBLOCK_ID'],'offers'=>(int)$row['IBLOCK_ID']];
            if(count($pairs)!==1)throw new \RuntimeException('Для сайта Calc нет единственной штатной пары товаров и ТП. Укажите пару в настройках витрины.',409);$catalogs=$pairs[0];
        }
        $pair=$db->query('SELECT IBLOCK_ID,PRODUCT_IBLOCK_ID,SKU_PROPERTY_ID FROM b_catalog_iblock WHERE IBLOCK_ID='.$catalogs['offers'])->fetch();
        if($catalogs['products']===$catalogs['offers']||(int)($pair['PRODUCT_IBLOCK_ID']??0)!==$catalogs['products'])throw new \RuntimeException('Инфоблоки не составляют штатную пару товаров и ТП.',409);
        $iblocks=[];$cursor=$db->query('SELECT ID FROM b_iblock WHERE ID IN ('.$catalogs['products'].','.$catalogs['offers'].') ORDER BY ID');while($row=$cursor->fetch())$iblocks[]=(int)$row['ID'];$wanted=array_values($catalogs);sort($wanted);if($iblocks!==$wanted)throw new \RuntimeException('Настроенные торговые инфоблоки отсутствуют.',409);
        $registry=\Bitrix\Main\Config\Option::get(self::MODULE,'trade_property_registry','{}');$tracked=json_decode($registry,true,16,JSON_THROW_ON_ERROR);if(!is_array($tracked))throw new \RuntimeException('Повреждён реестр свойств.');
        $keys=array_fill_keys(array_map(fn($item)=>$item['role'].':'.$item['fields']['CODE'],$this->manifest['properties']),true);
        foreach($this->manifest['properties']as$item)foreach($item['aliases']??[]as$alias)$keys[$item['role'].':'.$alias]=true;
        $allowed=['ID','IBLOCK_ID','NAME','CODE','ACTIVE','SORT','PROPERTY_TYPE','USER_TYPE','USER_TYPE_SETTINGS','MULTIPLE','WITH_DESCRIPTION','IS_REQUIRED','LIST_TYPE','LINK_IBLOCK_ID'];
        $properties=[];foreach($catalogs as$role=>$id){$cursor=\CIBlockProperty::GetList(['ID'=>'ASC'],['IBLOCK_ID'=>$id]);while($row=$cursor->Fetch()){
            $key=$role.':'.strtoupper($row['CODE']);if(!isset($keys[$key])&&!in_array((int)$row['ID'],$tracked,true))continue;
            $row=array_intersect_key($row,array_flip($allowed));$row['enums']=[];$enum=\CIBlockPropertyEnum::GetList(['ID'=>'ASC'],['PROPERTY_ID'=>$row['ID']]);while($e=$enum->Fetch())$row['enums'][]=array_intersect_key($e,array_flip(['ID','VALUE','XML_ID','SORT','DEF']));$properties[$key][]=$row;}}
        $dependencies=[];$links=[];$directoryValues=[];$hasValues=[];
        foreach($this->manifest['properties']as$item){$key=$item['role'].':'.$item['fields']['CODE'];$type=$item['fields']['USER_TYPE']??'';
            $members=$properties[$key]??[];if(!$members)foreach($item['aliases']??[]as$alias)$members=array_merge($members,$properties[$item['role'].':'.$alias]??[]);if(!$members&&isset($tracked[$key]))foreach($properties as$rows)foreach($rows as$row)if((int)$row['ID']===$tracked[$key])$members[]=$row;
            if(count($members)===1&&($members[0]['PROPERTY_TYPE']!==$item['fields']['PROPERTY_TYPE']||($members[0]['MULTIPLE']==='Y'&&$item['fields']['MULTIPLE']==='N')))$hasValues[$key]=(int)\CIBlockElement::GetList([],['IBLOCK_ID'=>$catalogs[$item['role']],'!PROPERTY_'.(int)$members[0]['ID']=>false],[],false,['ID'])>0;
            if($type&&!\CIBlockProperty::GetUserType($type))$dependencies[$key]='Недоступен редактор типа '.$type.'. Само свойство не удаляется.';
            if($type==='directory'){$table=$item['fields']['USER_TYPE_SETTINGS']['TABLE_NAME']??'';$escaped=$db->getSqlHelper()->forSql($table);if(!preg_match('/^b_hlbd_[a-z0-9_]+$/D',$table)||!$db->query("SELECT ID FROM b_hlblock_entity WHERE TABLE_NAME='$escaped'")->fetch())$dependencies[$key]='Отсутствует справочник '.$table.'. Его содержимое не создаётся этой функцией.';else{$cursor=$db->query('SELECT UF_NAME,UF_XML_ID FROM '.$table.' ORDER BY ID');$directoryValues[$key]=[];while($value=$cursor->fetch())$directoryValues[$key][]=['VALUE'=>(string)$value['UF_NAME'],'XML_ID'=>(string)$value['UF_XML_ID']];}}
            if(isset($item['linkTarget'])){$target=$item['linkTarget'];$matches=[];$cursor=\CIBlock::GetList([],['CODE'=>$target['code'],'TYPE'=>$target['iblock_type_id'],'CHECK_PERMISSIONS'=>'N']);while($row=$cursor->Fetch())if(($target['xml_id']??'')===''||$row['XML_ID']===$target['xml_id'])$matches[]=$row;if(count($matches)!==1)$dependencies[$key]='Не найден единственный связанный инфоблок '.$target['code'];else$links[$key]=(int)$matches[0]['ID'];}
        }
        return ['catalogs'=>$catalogs,'properties'=>$properties,'tracked'=>$tracked,'initialized'=>\Bitrix\Main\Config\Option::get(self::MODULE,'trade_property_initialized','N')==='Y','dependencies'=>$dependencies,'links'=>$links,'directoryValues'=>$directoryValues,'hasValues'=>$hasValues];
    }
    private function backup(array $snapshot,array $plan,int $actor): string
    {
        $dir=$_SERVER['DOCUMENT_ROOT'].'/bitrix/backup/prospektweb.calc/trade-properties';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new \RuntimeException('Не удалось создать резервную копию.');
        $name=gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';$path=$dir.'/'.$name;
        if(file_put_contents($path,json_encode(['actor'=>$actor,'snapshot'=>$snapshot,'plan'=>$plan],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new \RuntimeException('Не удалось сохранить резервную копию.');chmod($path,0600);return $name;
    }
}
