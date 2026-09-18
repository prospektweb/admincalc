<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** Authoring-only boundary. Does not calculate prices or enable a public service. */
final class AdditionalServices
{
    public static function validate(object $document): void
    {
        foreach ($document->form->fields ?? [] as $field) {
            if (!property_exists($field, 'urgencyWindow')) continue;
            if (($field->systemKey ?? '') !== 'urgency') self::fail('Окно срочности допустимо только для поля Срочность');
            $v=$field->urgencyWindow;
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
