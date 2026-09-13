<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__.'/DocumentCatalogWritePlan.php';

/** Direct, typed property-to-property value maps. No inference, chains or callbacks. */
final class PreparationPropertyLinks
{
    public static function signature(array $s):string
    {
        return DocumentCatalogWritePlan::hash(array_intersect_key($s,array_flip(['ID','IBLOCK_ID','PROPERTY_TYPE','USER_TYPE','USER_TYPE_SETTINGS','MULTIPLE'])));
    }
    public static function values(mixed $values,array $schema,array $choices):array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>100||($schema['MULTIPLE']==='N'&&count($values)>1))throw new \InvalidArgumentException('Множественность значения не соответствует свойству.');
        $type=$schema['PROPERTY_TYPE'];$user=strtolower((string)($schema['USER_TYPE']??''));
        if(!in_array($type,['N','S','L'],true)||($user!==''&&!($type==='S'&&$user==='directory')))throw new \InvalidArgumentException('Тип свойства не поддерживает точное сопоставление.');
        foreach($values as $value){
            if($type==='L'){if(!is_int($value)||!in_array($value,array_map('intval',array_column($choices,'ID')),true))throw new \InvalidArgumentException('Значение списка удалено или принадлежит другому свойству.');}
            elseif($type==='N'){if((!is_int($value)&&!is_float($value))||!is_finite((float)$value))throw new \InvalidArgumentException('Ожидается конечное число.');}
            elseif(!is_string($value)||($user==='directory'&&!in_array($value,array_column($choices,'XML_ID'),true)))throw new \InvalidArgumentException('Некорректное строковое значение или элемент справочника.');
        }
        if(count(array_unique($values,SORT_REGULAR))!==count($values))throw new \InvalidArgumentException('Повторные значения.');sort($values,SORT_REGULAR);return $values;
    }
    public static function validate(array $links,array $catalog,array $directIds):array
    {
        if(!array_is_list($links)||count($links)>200)throw new \InvalidArgumentException('Слишком много сопоставлений.');
        $sources=array_column($links,'sourceId');$targets=[];$result=[];
        foreach($links as $link){$link=(array)$link;$keys=array_keys($link);sort($keys);
            if($keys!==['sourceId','targetId','values']||!is_int($link['sourceId'])||!is_int($link['targetId']))throw new \InvalidArgumentException('Нужны точные ID свойств.');
            $source=$catalog['schemas'][$link['sourceId']]??null;$target=$catalog['schemas'][$link['targetId']]??null;
            if(!$source||!$target||$source['ACTIVE']!=='Y'||$target['ACTIVE']!=='Y'||!str_starts_with($source['CODE'],'CALC_')||!str_starts_with($target['CODE'],'CALC_'))throw new \InvalidArgumentException('Выберите доступные CALC_ свойства.');
            if(in_array($link['targetId'],$directIds,true)||isset($targets[$link['targetId']])||in_array($link['targetId'],$sources,true))throw new \InvalidArgumentException('Цепочка, цикл или несколько источников одного свойства запрещены.');
            $targets[$link['targetId']]=true;$values=[];$seen=[];
            if(!is_array($link['values'])||!array_is_list($link['values'])||count($link['values'])>1000)throw new \InvalidArgumentException('Некорректная таблица значений.');
            foreach($link['values'] as $entry){$e=(array)$entry;$k=array_keys($e);sort($k);if($k!==['source','target'])throw new \InvalidArgumentException('Некорректное соответствие.');
                $a=self::values($e['source'],$source,$catalog['choices'][$link['sourceId']]??[]);$b=self::values($e['target'],$target,$catalog['choices'][$link['targetId']]??[]);
                $hash=DocumentCatalogWritePlan::hash($a);if(isset($seen[$hash]))throw new \InvalidArgumentException('Повторное исходное значение.');$seen[$hash]=true;$values[]=['source'=>$a,'target'=>$b];}
            $result[]=$link+['sourceIblockId'=>(int)$source['IBLOCK_ID'],'targetIblockId'=>(int)$target['IBLOCK_ID'],'sourceSignature'=>self::signature($source),'targetSignature'=>self::signature($target)];$result[array_key_last($result)]['values']=$values;
        }
        return $result;
    }
    public static function expand(array $rows,array $links,array $catalog):array
    {
        $direct=array_column($rows,null,'propertyId');$out=$rows;
        foreach($links as $link){$id=$link['sourceId'];
            // A deleted source is a broken rule. A deleted target is intentionally dormant.
            $source=$catalog['schemas'][$id]??null;
            if(!$source)throw new \InvalidArgumentException('Исходное расчётное свойство #'.$id.' удалено.');
            $target=$catalog['schemas'][$link['targetId']]??null;if(!$target||$target['ACTIVE']!=='Y')continue;
            if(!isset($direct[$id]))continue;
            if(self::signature($source)!==$link['sourceSignature']||self::signature($target)!==$link['targetSignature'])throw new \InvalidArgumentException('Тип сопоставленного свойства изменился. Проверьте настройки.');
            if(isset($direct[$link['targetId']]))throw new \InvalidArgumentException('Свойство #'.$link['targetId'].' уже заполняется расчётом.');
            $value=self::values($direct[$id]['values'],$source,$catalog['choices'][$id]??[]);$matches=array_values(array_filter($link['values'],fn($e)=>DocumentCatalogWritePlan::hash($e['source'])===DocumentCatalogWritePlan::hash($value)));
            if(count($matches)!==1)throw new \InvalidArgumentException('Нет точного соответствия значения #'.$id.' → #'.$link['targetId'].'.');
            $mapped=self::values($matches[0]['target'],$target,$catalog['choices'][$link['targetId']]??[]);
            $out[]=['scope'=>(int)$target['IBLOCK_ID']===$catalog['products']?'product':'selected_offer','propertyId'=>$link['targetId'],'fieldId'=>'mapping:'.$id,'values'=>$mapped];
        }
        return $out;
    }
}
