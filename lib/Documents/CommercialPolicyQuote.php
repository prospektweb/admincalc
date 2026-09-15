<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** Server-only finalization. The sole calendar runtime and the existing Bitrix
 * rounder are injected by the application. No basket or catalog writer here. */
final class CommercialPolicyQuote
{
    public static function finalize(array $execution, array $calendar, string $anchor, string $asOf, array $bindings, array $allowedGroups, callable $preview, callable $round): array
    {
        if (($execution['contract'] ?? '') !== 'prospektweb.orderterms.execution/v1'
            || !preg_match('/^[a-f0-9]{64}$/D', $execution['runtimeFingerprint'] ?? '')
            || !preg_match('/^[a-f0-9]{64}$/D', $execution['resolvedPolicy']['resolvedHash'] ?? '')) self::fail('execution', 'EXECUTION_INVALID');
        foreach(['inputHash','technicalHash','bundleHash']as$key)if(!preg_match('/^[a-f0-9]{64}$/D',$execution[$key]??''))self::fail('execution.'.$key,'EXECUTION_INVALID');
        $ref = $execution['resolvedPolicy']['calendarRef'] ?? [];
        if (($calendar['id'] ?? null) !== ($ref['id'] ?? null) || ($calendar['revision'] ?? null) !== ($ref['revision'] ?? null)
            || ($calendar['bodyHash'] ?? null) !== ($ref['bodyHash'] ?? null)) self::fail('calendarRef', 'CALENDAR_STALE');
        if (($calendar['archived'] ?? true) !== false) self::fail('calendarRef', 'CALENDAR_ARCHIVED');
        $count = $execution['quantities']['layoutCount'] ?? null;
        if (!is_int($count) || $count < 1 || $count > 9007199254740991 || $count !== ($execution['quantities']['runCount'] ?? null)) self::fail('quantities', 'QUANTITY_ALIAS_MISMATCH');
        $byType = []; $byGroup = [];
        foreach ($bindings as $binding) {
            $id = $binding['typeId'] ?? null; $group = $binding['catalogGroupId'] ?? null;
            if (!is_string($id) || $id === '' || !is_int($group) || $group < 1 || isset($byType[$id]) || isset($byGroup[$group])) self::fail('bindings','PRICE_BINDING_INVALID');
            $byType[$id]=$group; $byGroup[$group]=true;
        }
        $variants = $execution['variants'] ?? null;
        if (!is_array($variants) || count($variants) !== 3 || count(array_unique(array_column($variants,'deadlineType'))) !== 3) self::fail('variants','VARIANTS_INVALID');
        foreach ($variants as &$variant) {
            if (!in_array($variant['deadlineType'] ?? '', ['urgent','strict','flexible'],true)) self::fail('deadlineType','TYPE_UNKNOWN');
            $variant['calendarResult']=null;
            if (($variant['enabled'] ?? null) !== true) { $variant['available']=false; $variant['prices']=[]; $variant['reason']='TYPE_DISABLED'; continue; }
            $w=$variant['window'];
            $result=$preview($calendar,$anchor,$asOf,$variant['defaultMinutes'],['min'=>$w['minMinutes'],'max'=>$w['maxMinutes'],'maxInclusive'=>$w['includeMax']]);
            if (($result['calendarHash'] ?? null) !== $ref['bodyHash'] || ($result['anchor'] ?? null) !== $anchor || ($result['asOf'] ?? null) !== $asOf) self::fail('calendarResult','CALENDAR_STALE');
            $variant['calendarResult']=$result;
            if (($result['error'] ?? null) !== null) { $variant['available']=false; $variant['reason']=$result['error']; $variant['prices']=[]; continue; }
            $minutes=$result['elapsedWorkMinutes'] ?? null;
            // Calendar preserves anchor seconds. Do not quantize elapsed time at a boundary.
            if ((!is_int($minutes) && !is_float($minutes)) || !is_finite((float)$minutes) || $minutes < 0 || $minutes > 9007199254740991
                || $minutes < $w['minMinutes'] || ($w['includeMax'] ? $minutes > $w['maxMinutes'] : $minutes >= $w['maxMinutes'])) self::fail('calendarResult.elapsedWorkMinutes','DEFAULT_OUTSIDE_WINDOW');
            $variant['available']=true; $variant['reason']=null; $prices=[];
            foreach ($variant['prices'] as $price) {
                $group=$byType[$price['typeId']] ?? null;
                if ($group === null) self::fail('bindings.'.$price['typeId'],'PRICE_BINDING_MISSING');
                // Authority is the server's allowed groups, never a frontend typeId.
                if (!in_array($group,$allowedGroups,true)) continue;
                if (($price['basis'] ?? '') !== 'per-run') self::fail('price.basis','PRICE_BASIS_UNSUPPORTED');
                $raw=self::money($price['unroundedPerRun'] ?? null,'price.unroundedPerRun');
                $rounded=$round($group,$raw,$price['currency']);
                if (!is_array($rounded) || !isset($rounded['amount'],$rounded['roundingRef'])) self::fail('rounding','ROUNDING_UNAVAILABLE');
                $amount=self::money($rounded['amount'],'rounding.amount');
                $total=$amount*$count;
                if (!is_finite($total) || $total > 9007199254740991) self::fail('positionTotal','VALUE_RANGE');
                $prices[]=$price+['catalogGroupId'=>$group,'roundedPerRun'=>$rounded['amount'],'positionTotal'=>self::multiplyMoney($rounded['amount'],$count),'roundingRef'=>$rounded['roundingRef']];
            }
            if (!$prices) self::fail('allowedGroups','PRICE_ACCESS_DENIED');
            $variant['prices']=$prices;
        }
        unset($variant);
        $body=['contract'=>'prospektweb.orderterms.quote-preview/v1','preliminary'=>true,'enrollmentEnabled'=>false,'runtimeFingerprint'=>$execution['runtimeFingerprint'],'runtimeNodeVersion'=>$execution['runtimeNodeVersion']??null,
            'bundleHash'=>$execution['bundleHash'],'inputHash'=>$execution['inputHash'],'technicalHash'=>$execution['technicalHash'],'resolvedPolicy'=>$execution['resolvedPolicy'],'quantities'=>$execution['quantities'],'technical'=>$execution['technical'],
            'anchor'=>$anchor,'asOf'=>$asOf,'variants'=>$variants];
        $body['quoteFingerprint']=hash('sha256',self::canonicalJson($body));
        return $body;
    }
    public static function canonicalJson($value): string
    {
        $sort=static function($v) use (&$sort) {
            if (is_object($v)) { $v=get_object_vars($v); ksort($v,SORT_STRING); return (object)array_map($sort,$v); }
            if (!is_array($v)) return $v;
            if (!array_is_list($v)) ksort($v,SORT_STRING);
            return array_map($sort,$v);
        };
        return json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    private static function money($value,string $path): float
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/D',$value) || !is_finite((float)$value) || (float)$value > 9007199254740991) self::fail($path,'MONEY_INVALID');
        return (float)$value;
    }
    private static function multiplyMoney(string $amount,int $count): string
    {
        $parts=explode('.',$amount);$scale=strlen($parts[1]??'');$digits=implode('',$parts);$carry=0;$product='';
        for($i=strlen($digits)-1;$i>=0;$i--){$n=(int)$digits[$i]*$count+$carry;$product=(string)($n%10).$product;$carry=intdiv($n,10);}
        if($carry)$product=(string)$carry.$product;
        if($scale){$product=str_pad($product,$scale+1,'0',STR_PAD_LEFT);$product=substr($product,0,-$scale).'.'.substr($product,-$scale);$product=rtrim(rtrim($product,'0'),'.');}
        $product=ltrim($product,'0');return $product===''?'0':($product[0]==='.'?'0'.$product:$product);
    }
    private static function fail(string $path,string $code): void { throw new \DomainException($code.':'.$path,422); }
}
