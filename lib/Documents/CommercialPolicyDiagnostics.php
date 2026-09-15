<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__.'/CommercialPolicyService.php';
require_once __DIR__.'/CommercialPolicyCatalogAdapter.php';

final class CommercialPolicyDiagnostics
{
    public static function fixture(): object
    {
        $f=json_decode(file_get_contents(__DIR__.'/fixtures/spm03-qa.json'),false,64,JSON_THROW_ON_ERROR);
        $calendar=\Prospektweb\OrderTerms\Diagnostics::fixture();
        $calendar['id']='spm03_qa_calendar_20260915';$calendar['provenance']=['purpose'=>'fixture','owner'=>'SPM-03'];
        $calendar=\Prospektweb\OrderTerms\Calendar::validate($calendar);
        $f->document->id='spm03_qa_calculator';$f->document->name='SPM-03 QA — не для продажи';
        $hash=hash('sha256',CommercialPolicyQuote::canonicalJson($f->document));
        $f->bundle->documentHash=$hash;$f->bundle->calculator->ownerId=$f->document->id;
        $f->bundle->calculator->calendarRef->value=(object)['id'=>$calendar['id'],'revision'=>1,'bodyHash'=>$calendar['bodyHash']];
        foreach([$f->bundle->calculator,$f->bundle->storefront]as$policy)$policy->provenance->sourceHash=$hash;
        self::seal($f->bundle->calculator);
        $f->bundle->storefront->parentRef->bodyHash=$f->bundle->calculator->bodyHash;
        self::seal($f->bundle->storefront);
        return (object)['document'=>$f->document,'resources'=>[],'bundle'=>$f->bundle,'calendar'=>$calendar,'values'=>(object)['qty'=>10],
            'quantities'=>(object)['unitCount'=>10,'layoutCount'=>6,'runCount'=>6],'valuesByFieldId'=>new \stdClass(),
            'anchor'=>'2026-09-14T09:00:00+05:00','asOf'=>'2026-09-14T04:00:00Z'];
    }
    private static function seal(object $policy): void { unset($policy->bodyHash);$policy->bodyHash=hash('sha256',CommercialPolicyQuote::canonicalJson($policy)); }
    public static function preview(object $request): object
    {
        if(array_diff(array_keys(get_object_vars($request)),['document','resources','bundle','calendar','values','quantities','valuesByFieldId','anchor','asOf']))throw new \InvalidArgumentException('UNKNOWN_FIELD:request');
        $service=new CommercialPolicyService();$caps=$service->capabilities();
        if(($caps['enrollmentEnabled']??true)!==false||!in_array('prospektweb.orderterms.execution/v1',$caps['contracts']??[],true))throw new \RuntimeException('COMMERCIAL_CAPABILITY_UNAVAILABLE');
        $validation=$service->validate($request->document,$request->resources,$request->bundle);
        $execution=$service->executeContext($request->document,$request->resources,$request->bundle,(array)$request->values,(array)$request->quantities,(array)$request->valuesByFieldId,$caps['runtimeFingerprint']);
        $calendar=\Prospektweb\OrderTerms\Calendar::validate(json_decode(json_encode($request->calendar,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR));
        // QA binds the existing base catalog group; the diagnostic never creates a type or writes a price.
        $group=\CCatalogGroup::GetBaseGroup();$groupId=(int)($group['ID']??0);
        if($groupId<=0)throw new \RuntimeException('BASE_PRICE_TYPE_UNAVAILABLE');
        $bindings=[];foreach($request->document->pricing->types as$type){if(!$type->base)throw new \InvalidArgumentException('QA_REQUIRES_EXPLICIT_PRICE_BINDINGS');$bindings[]=['typeId'=>$type->id,'catalogGroupId'=>$groupId];}
        $round=static function(int $id,float $price,string $currency):array{
            $rules=\Bitrix\Catalog\Product\Price::getRoundRules($id);
            $ref=['contract'=>'prospektweb.orderterms.bitrix-rounding/v1','catalogGroupId'=>$id,'currency'=>$currency,'rules'=>$rules];
            $ref['bodyHash']=hash('sha256',CommercialPolicyQuote::canonicalJson($ref));
            return ['amount'=>(string)\Bitrix\Catalog\Product\Price::roundPrice($id,$price,$currency),'roundingRef'=>$ref];
        };
        $preview=CommercialPolicyQuote::finalize($execution,$calendar,$request->anchor,$request->asOf,$bindings,[$groupId],[\Prospektweb\OrderTerms\Calendar::class,'preview'],$round);
        $plans=[];foreach($preview['variants']as$variant)if($variant['available'])$plans[]=CommercialPolicyCatalogAdapter::line($preview,$variant['deadlineType'],$groupId,$preview['quoteFingerprint']);
        return (object)['document'=>$request->document,'resources'=>$request->resources,'bundle'=>$request->bundle,'calendar'=>$calendar,'validation'=>$validation,'preview'=>$preview,'catalogPlans'=>$plans];
    }
}
