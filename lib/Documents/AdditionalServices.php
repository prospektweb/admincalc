<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** Authoring-only boundary. Does not calculate prices or enable a public service. */
final class AdditionalServices
{
    public static function validate(object $document): void
    {
        foreach ($document->form->fields ?? [] as $field) {
            if (property_exists($field, 'processWindow')) {
                $kind=$field->systemKey??'';
                $keys=['design'=>['title','task','budget','timeline','description','placeholder','upload','uploadHint','verification','approval','total','cancel','apply'],'payment'=>['title','intro','time','note','cancel','apply'],'receipt'=>['title','method','addresses','address','addressHint','comment','recipient','name','phone','carrier','terminal','consent','note','total','cancel','apply']];
                if(!isset($keys[$kind]))self::fail('Настройки процесса недопустимы для этого поля');
                $p=$field->processWindow;self::shape($p,array_merge(['texts','help','rules','allowBudget','requireDescription'],property_exists($p,'storage')?['storage']:[],property_exists($p,'payment')?['payment']:[],property_exists($p,'deliverySlots')?['deliverySlots']:[],property_exists($p,'designLaterHours')?['designLaterHours']:[]));
                if(property_exists($p,'designLaterHours')&&($kind!=='design'||!is_int($p->designLaterHours)||$p->designLaterHours<1||$p->designLaterHours>8760))self::fail('Некорректный срок ожидания макета');
                if(property_exists($p,'deliverySlots')){
                    if($kind!=='receipt'||!is_array($p->deliverySlots)||count($p->deliverySlots)<1||count($p->deliverySlots)>12)self::fail('Некорректные интервалы доставки');
                    $last='';foreach($p->deliverySlots as $slot){self::shape($slot,['from','to']);if(!is_string($slot->from)||!is_string($slot->to)||!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$slot->from)||!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$slot->to)||$slot->from>=$slot->to||$slot->from<$last)self::fail('Интервалы доставки пересекаются или некорректны');$last=$slot->to;}
                }
                if(property_exists($p,'payment')){
                    if($kind!=='payment')self::fail('Условия оплаты допустимы только для оплаты');
                    $q=$p->payment;self::shape($q,['immediateMinutes','laterHours','fixPrice','fixHours','immediateHint','laterHint']);
                    foreach(['immediateMinutes','laterHours','fixHours'] as $key)if(!is_int($q->$key)||$q->$key<1||$q->$key>8760)self::fail('Некорректный интервал оплаты');
                    if(!is_bool($q->fixPrice))self::fail('Некорректная фиксация цены');
                    foreach(['immediateHint','laterHint'] as $key)if(!is_string($q->$key)||mb_strlen($q->$key)>10000)self::fail('Некорректное описание оплаты');
                }
                if(property_exists($p,'storage')){
                    if($kind!=='receipt')self::fail('Хранение допустимо только для получения');
                    $s=$p->storage;self::shape($s,array_merge(['mode','daily','maxAmount','anchors'],property_exists($s,'notice')?['notice']:[]));if(property_exists($s,'notice')&&(!is_string($s->notice)||mb_strlen($s->notice)>10000))self::fail('Некорректное описание хранения');
                    $money=static fn($n)=>is_numeric($n)&&!is_string($n)&&is_finite((float)$n)&&$n>=0&&$n<=1e12&&abs($n*100-round($n*100))<0.0001;
                    if(!in_array($s->mode,['anchors','daily'],true)||!$money($s->daily)||($s->maxAmount!==null&&(!$money($s->maxAmount)||$s->maxAmount<=0))||!is_array($s->anchors)||count($s->anchors)<2||count($s->anchors)>5)self::fail('Некорректные настройки хранения');
                    $day=0;$amount=0;foreach($s->anchors as $index=>$a){self::shape($a,['days','value']);if(!is_int($a->days)||$a->days<1||$a->days>365||$a->days<=$day||!$money($a->value)||($index===0&&$a->value!=0)||$a->value<$amount||($s->mode==='daily'&&$index<2&&$a->value!=0))self::fail('Некорректные отметки хранения');$day=$a->days;$amount=$a->value;}
                }$textKeys=$keys[$kind];if($kind==='design')foreach(['laterTitle','laterTime','chooseFiles'] as $key)if(property_exists($p->texts,$key))$textKeys[]=$key;self::shape($p->texts,$textKeys);
                if(!is_bool($p->allowBudget)||!is_bool($p->requireDescription)||!$p->help instanceof \stdClass||!$p->rules instanceof \stdClass)self::fail('Некорректные настройки процесса');
                foreach(get_object_vars($p->texts) as $text)if(!is_string($text)||mb_strlen($text)>10000)self::fail('Некорректный текст процесса');
                foreach(get_object_vars($p->help) as $key=>$help){if(!in_array($key,array_merge($textKeys,$kind==='design'?['chooseFiles']:[]),true))self::fail('Неизвестная подсказка');self::shape($help,['enabled','text']);if(!is_bool($help->enabled)||!is_string($help->text)||mb_strlen($help->text)>10000)self::fail('Некорректная подсказка');}
                if(count(get_object_vars($p->rules))>50)self::fail('Слишком много вариантов');
                foreach(get_object_vars($p->rules) as $key=>$rule){self::shape($rule,['days','serviceId']);if(!preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D',(string)$key)||!is_int($rule->days)||$rule->days<0||$rule->days>365||!is_string($rule->serviceId)||strlen($rule->serviceId)>100)self::fail('Некорректное правило процесса');}
            }
            if (property_exists($field, 'dateSelection')) {
                if (!in_array($field->systemKey??'',['desiredDate','design','payment'],true)) self::fail('Ограничения даты допустимы для желаемой даты, макета и подтверждения оплаты');
                $p=$field->dateSelection; self::shape($p,['calendarId','weekend','holiday','overtime']);
                if(!is_string($p->calendarId)||strlen($p->calendarId)>64) self::fail('Некорректный календарь');
                foreach(['weekend','holiday','overtime'] as $key){
                    $r=$p->$key;self::shape($r,['allowed','source','from','to']);
                    if(!is_bool($r->allowed)||!in_array($r->source,['global','individual'],true)||!is_int($r->from)||!is_int($r->to)||$r->from<0||$r->to>1440||$r->to<=0||($key==='overtime'?$r->from!==0:$r->from>=$r->to))self::fail('Некорректный интервал выбора даты');
                }
            }
            if (property_exists($field, 'flexibilityWindow')) {
                if (($field->systemKey??'')!=='flexible') self::fail('Окно гибкости допустимо только для гибкого срока');
                $f=$field->flexibilityWindow;self::shape($f,['texts','mode','unit','daily','maxAmount','anchors']);
                self::shape($f->texts,['title','intro','timeline','deadline','discount','percent','amount','basis','explanation','cancel','apply']);
                foreach(get_object_vars($f->texts) as $text) if(!is_string($text)||mb_strlen($text)>10000)self::fail('Некорректный текст гибкости');
                if(!in_array($f->mode,['anchors','daily'],true)||!in_array($f->unit,['percent','rubles'],true))self::fail('Некорректный режим гибкости');
                $number=static function($n):bool{return (is_int($n)||is_float($n))&&is_finite((float)$n)&&$n>=0&&$n<=1e12&&abs($n*100-round($n*100))<0.0001;};
                if(!$number($f->daily)||$f->daily<=0||($f->maxAmount!==null&&(!$number($f->maxAmount)||$f->maxAmount<=0)))self::fail('Некорректная скидка или ограничитель');
                if(!is_array($f->anchors)||count($f->anchors)<2||count($f->anchors)>5)self::fail('Допустимо от 2 до 5 отметок');
                $previous=null;
                foreach($f->anchors as $a){self::shape($a,['days','value']);if(!is_int($a->days)||$a->days<1||$a->days>365||!$number($a->value)||($f->unit==='percent'&&$a->value>100)||($previous&&($a->days<=$previous->days||($f->mode==='anchors'&&$a->value<=$previous->value))))self::fail('Отметки и скидки должны возрастать');$previous=$a;}
            }
            if (!property_exists($field, 'urgencyWindow')) continue;
            if (($field->systemKey ?? '') !== 'urgency') self::fail('Окно срочности допустимо только для поля Срочность');
            if (!$field->urgencyWindow instanceof \stdClass) self::fail('Некорректное окно срочности');
            $v=clone $field->urgencyWindow;
            if (property_exists($v, 'labelHelp')) {
                if (!$v->labelHelp instanceof \stdClass) self::fail('Некорректные подсказки лейблов');
                foreach (get_object_vars($v->labelHelp) as $key=>$help) {
                    if (!in_array($key,['desiredLabel','amountLabel','paymentTitle','paymentAfterLabel','paymentImmediateLabel','refundTitle','refundLabel','balanceLabel'],true)) self::fail('Неизвестный лейбл');
                    self::shape($help,['enabled','text']);
                    if (!is_bool($help->enabled) || !is_string($help->text) || mb_strlen($help->text)>10000) self::fail('Некорректная подсказка лейбла');
                }
                unset($v->labelHelp);
            }
            self::shape($v, ['paymentTitle','paymentDefault','paymentAfterLabel','paymentAfterHint','paymentImmediateLabel','paymentImmediateHint','title','desiredLabel','desiredPlaceholder','desiredHelp','amountLabel','minimum','step','explanation','allocation','refundTitle','refundLabel','refundHint','balanceLabel','balanceHint','refundNote','cancelLabel','doneLabel']);
            foreach (get_object_vars($v) as $text) if (!is_string($text) || mb_strlen($text)>10000) self::fail('Некорректный текст окна срочности');
            if (!in_array($v->paymentDefault,['after_confirmation','immediate'],true)) self::fail('Неизвестный порядок оплаты');
            self::money($v->minimum); self::money($v->step);
            if ((float)$v->step<=0) self::fail('Шаг доплаты должен быть больше нуля');
        }
        if (!isset($document->pricing) || !property_exists($document->pricing, 'additionalServices')) return;
        $s = $document->pricing->additionalServices;
        self::shape($s, ['contract','calculator','storefronts','scenarios']);
        if ($s->contract !== 'prospektweb.calculator.additional-services/v1') self::fail('Неизвестная версия настроек услуг');
        self::shape($s->calculator, ['design','delivery','urgency','flexibility']);
        self::block($s->calculator);
        foreach (['storefronts','scenarios'] as $key) if (!$s->$key instanceof \stdClass) self::fail('Ожидалась карта уровней услуг');
        foreach (get_object_vars($s->storefronts) as $block) self::block($block);
        foreach (get_object_vars($s->scenarios) as $view) {
            if (!$view instanceof \stdClass) self::fail('Ожидалась карта сценариев услуг');
            foreach (get_object_vars($view) as $block) self::block($block);
        }
    }
    private static function block($block): void
    {
        if (!$block instanceof \stdClass) self::fail('Некорректный уровень услуг');
        foreach (get_object_vars($block) as $key => $value) {
            if (in_array($key,['design','delivery'],true)) {
                if (!is_array($value) || count($value)>50) self::fail('Не более 50 услуг в разделе');
                $ids=[];
                foreach ($value as $row) {
                    self::shape($row,['id','name','enabled','description','rate','basis','minimum','days']);
                    foreach (['id','name','description'] as $field) if (!is_string($row->$field) || strlen($row->$field)>10000) self::fail('Некорректный текст услуги');
                    if (!trim($row->name) || !$row->id || isset($ids[$row->id])) self::fail('Услуге нужны название и уникальный код');
                    $ids[$row->id]=true;
                    if (!is_bool($row->enabled) || !in_array($row->basis,['position','layout'],true) || ($key==='delivery' && $row->basis!=='position')) self::fail('Некорректная база стоимости услуги');
                    self::money($row->minimum);
                    if (!is_int($row->days) || $row->days<0) self::fail('Срок должен быть целым неотрицательным числом');
                    self::shape($row->rate,['kind','value']);
                    if ($row->rate->kind==='amount') self::money($row->rate->value);
                    elseif ($row->rate->kind==='expression') {
                        if (!is_string($row->rate->value) || !trim($row->rate->value) || strlen($row->rate->value)>10000) self::fail('Укажите выражение стоимости');
                        // Stored as text only. Execution/producer validation is the next stage.
                    } else self::fail('Неизвестный источник стоимости');
                }
            } elseif ($key==='urgency') {
                self::shape($value,['enabled','minimum','step','description','desiredDescription']);
                self::money($value->minimum); self::money($value->step);
                if (!is_bool($value->enabled) || (float)$value->step<=0 || !is_string($value->description) || !is_string($value->desiredDescription)) self::fail('Некорректные настройки срочности');
            } elseif ($key==='flexibility') {
                self::shape($value,['enabled','percent','description']); self::money($value->percent);
                if (!is_bool($value->enabled) || (float)$value->percent>=100 || !is_string($value->description)) self::fail('Некорректная скидка за гибкость');
            } else self::fail('Неизвестный раздел услуг');
        }
    }
    private static function shape($value,array $keys): void
    {
        if (!$value instanceof \stdClass) self::fail('Ожидался объект настроек услуг');
        $actual=array_keys(get_object_vars($value)); sort($actual); sort($keys);
        if ($actual!==$keys) self::fail('Несовместимая структура настроек услуг');
    }
    private static function money($v): void
    {
        if (!is_string($v) || !preg_match('/^(0|[1-9]\d*)(\.\d{1,2})?$/D',$v) || (float)$v>1e12) self::fail('Укажите неотрицательную сумму с точностью до копеек');
    }
    private static function fail(string $message): void { throw new \InvalidArgumentException($message); }
}
