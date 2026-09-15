<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__.'/CommercialPolicyQuote.php';

/** The same per-run carrier as FrontCalc. This produces a plan, never writes it. */
final class CommercialPolicyCatalogAdapter
{
    public static function line(array $quote,string $type,int $catalogGroupId,string $expectedFingerprint): array
    {
        if(($quote['contract']??'')!=='prospektweb.orderterms.quote-preview/v1'
            ||($quote['quoteFingerprint']??'')!==$expectedFingerprint)throw new \DomainException('QUOTE_STALE',409);
        $body=$quote;unset($body['quoteFingerprint']);
        if(!hash_equals(hash('sha256',CommercialPolicyQuote::canonicalJson((object)$body)),$expectedFingerprint))throw new \DomainException('QUOTE_HASH_MISMATCH',409);
        $matches=array_values(array_filter($quote['variants']??[],static fn($v)=>($v['deadlineType']??null)===$type&&($v['available']??false)===true));
        if(count($matches)!==1)throw new \DomainException('DEADLINE_UNAVAILABLE',422);
        $prices=array_values(array_filter($matches[0]['prices'],static fn($p)=>($p['catalogGroupId']??null)===$catalogGroupId));
        if(count($prices)!==1||($prices[0]['basis']??'')!=='per-run')throw new \DomainException('PRICE_ACCESS_DENIED',403);
        $price=$prices[0];$quantity=$quote['quantities']['layoutCount'];
        if(!is_int($quantity)||$quantity<1||$quantity!==$quote['quantities']['runCount'])throw new \DomainException('QUANTITY_ALIAS_MISMATCH',422);
        return ['PRICE'=>$price['roundedPerRun'],'QUANTITY'=>$quantity,'CURRENCY'=>$price['currency'],'CUSTOM_PRICE'=>'Y','CATALOG_GROUP_ID'=>$catalogGroupId,
            'positionTotal'=>$price['positionTotal'],'quoteFingerprint'=>$expectedFingerprint,'readyAt'=>$matches[0]['calendarResult']['readyAt'],
            'writerEnabled'=>false];
    }
}
