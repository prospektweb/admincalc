<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** Compatibility reader. Schema writes belong to Catalog\TradePropertyService. */
final class UrgencyProperty
{
    public static function ensure(int $iblock): void
    {
        if ($iblock <= 0) throw new \RuntimeException('Не настроен инфоблок товаров.');
        $matches=[];
        $cursor=\CIBlockProperty::GetList([],['IBLOCK_ID'=>$iblock]);
        while($p=$cursor->Fetch()) if($p['NAME']==='Срочное изготовление'||$p['CODE']==='CALC_PROP_URGENCY') $matches[]=$p;
        if(count($matches)>1) throw new \RuntimeException('Найдено несколько свойств срочности. Требуется выбрать единственный источник.');
        $p=$matches[0]??null;
        if($p && ($p['PROPERTY_TYPE']!=='L'||$p['MULTIPLE']==='Y')) throw new \RuntimeException('Существующее свойство срочности должно быть одиночным списком. Данные не изменены.');
        if(!$p){
            throw new \RuntimeException('Создайте свойства в ЦУ → Калькуляции PROSPEKT → Свойства торговых инфоблоков.',409);
        }
        $values=[];$cursor=\CIBlockPropertyEnum::GetList([],['PROPERTY_ID'=>$p['ID']]);
        while($v=$cursor->Fetch()) $values[]=$v;
        foreach($values as $v) if(!in_array(mb_strtolower(trim($v['VALUE'])),['включено','не включено'],true)) throw new \RuntimeException('Список срочности содержит другие значения. Они не удалены; требуется согласовать их сопоставление.');
        if(count($values)>2)throw new \RuntimeException('В списке срочности есть повторные значения. Данные не изменены.');
        foreach(['on'=>'Включено','off'=>'Не включено'] as $xml=>$label){
            foreach($values as $v) if(($v['XML_ID']??'')===$xml && mb_strtolower(trim($v['VALUE']))!==mb_strtolower($label)) throw new \RuntimeException('Коды вариантов срочности заняты другими значениями. Данные не изменены.');
        }
        foreach(['on'=>'Включено','off'=>'Не включено'] as $xml=>$label){
            $found=array_filter($values,static fn($v)=>mb_strtolower(trim($v['VALUE']))===mb_strtolower($label));
            if(count($found)>1)throw new \RuntimeException('Неоднозначные значения срочности.');
            if(!$found)throw new \RuntimeException('Обновите свойства в ЦУ → Калькуляции PROSPEKT → Свойства торговых инфоблоков.',409);
        }
    }
}
