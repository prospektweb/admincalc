<?php
declare(strict_types=1);
// Pass the canonical orderterms module directory; optional second argument exports Node fixtures.
require_once dirname(__DIR__).'/lib/Documents/CommercialPolicyQuote.php';
foreach(['Contract','Calendar','Diagnostics']as$name)require_once $argv[1].'/lib/'.$name.'.php';
use Prospektweb\Calc\Documents\CommercialPolicyQuote;
use Prospektweb\OrderTerms\{Calendar,Diagnostics};
$base=json_decode(file_get_contents(__DIR__.'/fixtures/commercial-execution.json'),true,64,JSON_THROW_ON_ERROR);
$calendar=Calendar::validate(Diagnostics::fixture());
$base['resolvedPolicy']['calendarRef']=['id'=>$calendar['id'],'revision'=>$calendar['revision'],'bodyHash'=>$calendar['bodyHash']];
$round=static fn($group,$price,$currency)=>['amount'=>(string)round($price,2),'roundingRef'=>['fixture'=>true]];
$checks=0;$cases=[];
$check=static function(bool $ok,string $name)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($name);};
foreach([0,1,59]as$second)foreach(['urgent'=>[480,1920,2399],'strict'=>[2400,2879],'flexible'=>[2880,4799,4800]]as$type=>$requests)foreach($requests as$requested){
 $anchor=sprintf('2026-09-14T09:00:%02d+05:00',$second);$asOf=sprintf('2026-09-14T04:00:%02dZ',$second);
 $execution=$base;foreach($execution['variants']as&$v)if($v['deadlineType']===$type){$v['defaultMinutes']=$requested;$window=$v['window'];}unset($v);
 $result=Calendar::preview($calendar,$anchor,$asOf,$requested,['min'=>$window['minMinutes'],'max'=>$window['maxMinutes'],'maxInclusive'=>$window['includeMax']]);
 $quote=CommercialPolicyQuote::finalize($execution,$calendar,$anchor,$asOf,[['typeId'=>'retail','catalogGroupId'=>1]],[1],[Calendar::class,'preview'],$round);
 $variant=array_values(array_filter($quote['variants'],static fn($v)=>$v['deadlineType']===$type))[0];
 $check($variant['calendarResult']===$result,'Exact calendar response retained');
 $check($variant['available']===($result['error']===null),'Availability matches real Calendar');
 $check($result['anchor']===$anchor&&$result['asOf']===$asOf,'Seconds retained');
 if($result['error']===null){
  $elapsed=$result['elapsedWorkMinutes'];
  $check($elapsed >= $window['minMinutes']&&($window['includeMax']?$elapsed <= $window['maxMinutes']:$elapsed < $window['maxMinutes']),'Direct boundary check');
  if($second>0)$check(is_float($elapsed),'Fractional elapsed accepted');
  if($second===1&&$type==='urgent'&&$requested===1920)$check($elapsed===1949.9833333333333,'R03-01 reproduction');
 }else{$check($variant['prices']===[]&&$variant['reason']==='NO_SLOT','No price or fallback');}
 $cases[]=['deadlineType'=>$type,'requestedMinutes'=>$requested,'calendar'=>$result];
}
// Invalid elapsed values must fail even when the injected calendar callback claims success.
foreach([NAN,INF,-INF,-1,9007199254740992,'1949.9',true]as$bad){
 $preview=static fn($c,$a,$s,$m,$w)=>['calendarHash'=>$c['bodyHash'],'anchor'=>$a,'asOf'=>$s,'error'=>null,'elapsedWorkMinutes'=>$bad];
 try{CommercialPolicyQuote::finalize($base,$calendar,$anchor,$asOf,[['typeId'=>'retail','catalogGroupId'=>1]],[1],$preview,$round);throw new RuntimeException('Invalid elapsed accepted');}
 catch(DomainException $e){$check(str_contains($e->getMessage(),'DEFAULT_OUTSIDE_WINDOW'),'Finite numeric guard');}
}
if(isset($argv[2]))file_put_contents($argv[2],json_encode(['execution'=>$base,'cases'=>$cases],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo "PASS $checks checks, ".count($cases)." real Calendar cases\n";
