<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__.'/DocumentCatalogWritePlan.php';

/** Exact inverse of an already validated input mapping. No catalog lookup or writes.
 * Schema and choices must come from the current locked catalog authority, never HTTP.
 * Non-injective or lossy mappings deliberately require operator correction. */
final class DocumentPreparationPropertyPlan
{
    public static function savedInput(object $values,string $field,array $shape):array
    {
        if(!property_exists($values,$field))return ['state'=>'absent','intended'=>false,'value'=>null];
        $value=$values->$field;
        if(!($shape[$field]['visible']??false)&&in_array($value,[null,'',[],false],true))return ['state'=>'inactive-placeholder','intended'=>false,'value'=>$value];
        return ['state'=>in_array($value,[null,'',[]],true)?'explicit-empty':'value','intended'=>true,'value'=>$value];
    }
    public static function value(array $mapping, mixed $value, array $property, array $choices): array
    {
        $source=$mapping['source']; $field=$mapping['target']['field_id'];
        $fail=static fn(string $why)=>new \InvalidArgumentException('Поле «'.$field.'», свойство #'.$source['property_id'].': '.$why);
        if((int)($property['ID']??0)!==$source['property_id']||(int)($property['IBLOCK_ID']??0)!==$source['iblock_id']
            ||($property['CODE']??null)!==$source['property_code']||($property['ACTIVE']??null)!=='Y'
            ||!in_array($property['MULTIPLE']??null,['Y','N'],true))throw $fail('схема свойства изменилась.');
        if(isset($mapping['transform_regex']))throw $fail('обратная запись преобразования по регулярному выражению не определена.');
        $type=$property['PROPERTY_TYPE'];$userType=strtolower((string)($property['USER_TYPE']??''));
        if($userType!==''&&!($type==='S'&&$userType==='directory'))throw $fail('пользовательский тип требует точного адаптера записи.');
        $enum=$type==='L'||($type==='S'&&$userType==='directory');
        if(!$enum&&(!in_array($type,['N','S'],true)||$userType!==''))throw $fail('тип свойства требует точного адаптера записи.');
        if(isset($mapping['target']['input_id'])){
            $object=$value instanceof \stdClass?get_object_vars($value):$value;
            if(!is_array($object)||!array_key_exists($mapping['target']['input_id'],$object))throw $fail('отсутствует вход размера.');
            $value=$object[$mapping['target']['input_id']];
        }
        if(($mapping['value_mode']??null)==='dimension_xml_id'){
            $object=$value instanceof \stdClass?get_object_vars($value):$value;
            if(!is_array($object)||count($object)!==2||!isset($object['width'],$object['length']))throw $fail('требуется цельный формат width × length.');
            foreach($object as $number)if(!is_int($number)&&!is_float($number)||!is_finite((float)$number)||$number<=0)throw $fail('некорректный размер.');
            // Compare parsed numeric components, while retaining the exact existing XML_ID.
            $matches=array_values(array_filter($choices,static function($c)use($object){
                return preg_match('/^(\d+(?:[.,]\d+)?)x(\d+(?:[.,]\d+)?)$/D',(string)$c['XML_ID'],$m)
                    &&(float)str_replace(',','.',$m[1])===(float)$object['width']&&(float)str_replace(',','.',$m[2])===(float)$object['length'];
            }));
            if(count($matches)!==1)throw $fail('формат отсутствует или неоднозначен в текущем списке.');
            $value=$matches[0]['XML_ID'];
        }elseif(($mapping['value_mode']??null)==='dimensions')throw $fail('для составного множественного формата не задана однозначная обратная запись.');
        elseif(($mapping['value_mode']??null)==='boolean_yn'){
            if(!is_bool($value))throw $fail('требуется логическое значение.');$value=$value?'Y':'N';
        }
        $values=$value===null||$value===''||$value===[]?[]:(is_array($value)?$value:[$value]);
        if(!array_is_list($values)||($property['MULTIPLE']==='N'&&count($values)>1))throw $fail('множественность не соответствует свойству.');
        $result=[];$xmlSeen=[];
        if($enum)foreach($choices as $choice){
            if(!is_string($choice['XML_ID']??null)||$choice['XML_ID']===''||isset($xmlSeen[$choice['XML_ID']]))throw $fail('неоднозначные XML_ID значений.');
            if($type==='L'&&(int)($choice['PROPERTY_ID']??0)!==$source['property_id'])throw $fail('значение принадлежит другому свойству.');
            if((int)($choice['ID']??0)<1)throw $fail('отсутствует ID значения.');$xmlSeen[$choice['XML_ID']]=true;
        }
        foreach($values as $v){
            if(!is_string($v)&&!is_int($v)&&!is_float($v))throw $fail('неподдерживаемое значение.');
            if($enum){
                $matches=[];
                foreach($choices as $choice){
                    $xml=$choice['XML_ID'];$token=$xml;
                    if(($mapping['source_value']??'xml_id')==='custom')$token=$mapping['custom_value_map'][$xml]??null;
                    elseif(($mapping['source_value']??'xml_id')==='value')$token=$choice['VALUE']??null;
                    if(isset($mapping['option_map']))$token=$mapping['option_map'][$xml]??null;
                    if($token!==null&&(string)$token===(string)$v)$matches[]=$choice;
                }
                if(count($matches)!==1)throw $fail('значение «'.$v.'» отсутствует или имеет несколько точных соответствий.');
                $result[]=$type==='L'?(int)$matches[0]['ID']:$matches[0]['XML_ID'];
            }else{
                if($type==='N'){
                    if(!is_int($v)&&!is_float($v)||!is_finite((float)$v))throw $fail('требуется конечное число без преобразования строки.');
                }elseif(!is_string($v))throw $fail('требуется строка.');
                $result[]=$v;
            }
        }
        if(count(array_unique($result,SORT_REGULAR))!==count($result))throw $fail('повторные значения не допускаются.');
        if($property['MULTIPLE']==='Y')sort($result,SORT_REGULAR);
        return ['scope'=>$source['scope'],'propertyId'=>$source['property_id'],'fieldId'=>$field,'values'=>$result];
    }

    /** Every selected variant contributes, including an explicit empty value. */
    public static function consensus(array $variants): array
    {
        if(!$variants)throw new \InvalidArgumentException('Выберите текущие варианты подготовки.');
        $products=[];$offers=[];$conflicts=[];$all=[];
        foreach($variants as $variant=>$rows)foreach($rows as $row){
            $scope=$row['scope'];$id=$row['propertyId'];$hash=DocumentCatalogWritePlan::hash($row['values']);
            if(!in_array($scope,['product','selected_offer'],true))throw new \InvalidArgumentException('Неизвестная область свойства.');
            if(isset($all[$variant][$scope][$id])&&$all[$variant][$scope][$id]['hash']!==$hash)
                $conflicts[]=['propertyId'=>$id,'variantIds'=>[$variant],'message'=>'Входы одного варианта задают разные значения свойства #'.$id.'.'];
            $all[$variant][$scope][$id]=['hash'=>$hash,'row'=>$row];
        }
        $ids=[];foreach($all as $scopes)foreach($scopes['product']??[] as $id=>$entry)$ids[$id]=true;
        foreach(array_keys($ids) as $id){
            $groups=[];
            foreach(array_keys($variants) as $variant){$entry=$all[$variant]['product'][$id]??null;$groups[$entry['hash']??'unmapped'][]=$variant;}
            if(count($groups)!==1||isset($groups['unmapped']))$conflicts[]=['propertyId'=>$id,'variantIds'=>array_keys($variants),'message'=>'Выбранные варианты не согласуют значение свойства товара #'.$id.'.'];
            else $products[]=$all[array_key_first($variants)]['product'][$id]['row'];
        }
        foreach($all as $variant=>$scopes)$offers[$variant]=array_values(array_map(fn($entry)=>$entry['row'],$scopes['selected_offer']??[]));
        return ['productProperties'=>$products,'offerProperties'=>$offers,'conflicts'=>$conflicts,'ready'=>!$conflicts];
    }
}
