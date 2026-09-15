<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/Documents/CommercialPolicyQuote.php';
require_once dirname(__DIR__).'/lib/Documents/CommercialPolicyCatalogAdapter.php';
use Prospektweb\Calc\Documents\CommercialPolicyQuote;
$source=json_decode(file_get_contents(__DIR__.'/fixtures/commercial-execution.json'),true,64,JSON_THROW_ON_ERROR);
$calendar=$source['resolvedPolicy']['calendarRef']+['archived'=>false];$checks=0;
$assert=static function(bool $value)use(&$checks){if(!$value)throw new RuntimeException('Assertion failed');$checks++;};
$preview=static fn($calendar,$anchor,$asOf,$minutes,$window)=>['calendarHash'=>$calendar['bodyHash'],'anchor'=>$anchor,'asOf'=>$asOf,'elapsedWorkMinutes'=>$minutes,'readyAt'=>'2026-09-17T18:00:00+05:00','error'=>null];
$round=static fn($group,$price,$currency)=>['amount'=>number_format(round($price,2),2,'.',''),'roundingRef'=>['group'=>$group,'currency'=>$currency,'snapshot'=>['precision'=>0.01]]];
$run=static fn($body,$allowed=[1])=>CommercialPolicyQuote::finalize($body,$calendar,'2026-09-14T04:00:00Z','2026-09-14T04:00:00Z',[['typeId'=>'retail','catalogGroupId'=>1]],$allowed,$preview,$round);
foreach([1,2,6]as$n){$e=$source;$e['quantities']['layoutCount']=$e['quantities']['runCount']=$n;$q=$run($e);$assert($q['variants'][1]['prices'][0]['roundedPerRun']==='120.00');$assert((float)$q['variants'][1]['prices'][0]['positionTotal']===120.0*$n);$assert($run($e)===$q);}
$before=serialize($source);$run($source);$assert(serialize($source)===$before);
try{$run($source,[]);throw new RuntimeException('ACL bypass');}catch(DomainException $e){$assert(str_contains($e->getMessage(),'PRICE_ACCESS_DENIED'));}
$source['variants'][1]['prices'][0]['unroundedPerRun']='1.005';$q=$run($source);$assert($q['variants'][1]['prices'][0]['roundedPerRun']==='1.01');$assert($q['variants'][1]['prices'][0]['positionTotal']==='6.06');
$source['variants'][0]['enabled']=false;$q=$run($source);$assert($q['variants'][0]['available']===false&&$q['variants'][0]['prices']===[]&&$q['variants'][0]['reason']==='TYPE_DISABLED');
$plan=\Prospektweb\Calc\Documents\CommercialPolicyCatalogAdapter::line($q,'strict',1,$q['quoteFingerprint']);
$assert($plan['PRICE']==='1.01'&&$plan['QUANTITY']===6&&$plan['positionTotal']==='6.06'&&$plan['writerEnabled']===false);
$tampered=$q;$tampered['variants'][1]['prices'][0]['roundedPerRun']='0';
try{\Prospektweb\Calc\Documents\CommercialPolicyCatalogAdapter::line($tampered,'strict',1,$q['quoteFingerprint']);throw new RuntimeException('Tamper accepted');}catch(DomainException $e){$assert($e->getMessage()==='QUOTE_HASH_MISMATCH');}
echo "PASS $checks checks\n";
