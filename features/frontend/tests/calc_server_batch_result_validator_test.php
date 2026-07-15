<?php
require_once __DIR__ . '/../lib/Service/CalcServerBatchResultValidator.php';
use Prospektweb\Frontcalc\Service\CalcServerBatchResultValidator;
function vr_same($expected,$actual,string $message):void { if($expected!==$actual) throw new RuntimeException($message); }
function vr_selected(array $ids):array { $result=[]; foreach($ids as $id)$result[$id]=['id'=>$id]; return $result; }
function vr_item(int $id):array { return ['offer_id'=>$id,'purchase_price'=>1000,'direct_purchase_price'=>null,'currency'=>'RUB','width'=>null,'length'=>null,'height'=>null,'weight'=>null,'parametr_values'=>[]]; }
$validator=new CalcServerBatchResultValidator(); $selected=vr_selected([-1,-2,-3]);
$result=$validator->validate([vr_item(-1),vr_item(-2),vr_item(-3)],$selected);
vr_same(true,$result['isComplete'],'full response'); vr_same(3,count($result['validItems']),'full items');
$result=$validator->validate([vr_item(-1),vr_item(-2)],$selected);
vr_same(false,$result['isComplete'],'partial response'); vr_same([-3],$result['missingOfferIds'],'missing id');
$damaged=vr_item(-2); unset($damaged['purchase_price']);
$result=$validator->validate([vr_item(-1),$damaged,vr_item(-3)],$selected);
vr_same('PURCHASE_PRICE_INVALID',$result['invalidItems'][0]['reason'],'invalid purchase price');
$result=$validator->validate([vr_item(-1),vr_item(-2),vr_item(-2),vr_item(-3)],$selected);
vr_same([-2],$result['duplicateOfferIds'],'duplicate id'); vr_same(2,count($result['validItems']),'duplicates removed');
$bad=vr_item(-1); $bad['currency']=['RUB']; $warnings=[];
set_error_handler(static function(int $severity,string $message)use(&$warnings):bool{$warnings[]=$message;return true;});
$result=$validator->validate([$bad,vr_item(-2),vr_item(-3)],$selected); restore_error_handler();
vr_same([],$warnings,'no PHP warnings'); vr_same('CURRENCY_INVALID',$result['invalidItems'][0]['reason'],'invalid currency');
$result=$validator->validate(['first'=>vr_item(-1)],$selected);
vr_same('DATA_NOT_LIST',$result['invalidItems'][0]['reason'],'list shape');
echo "CalcServerBatchResultValidator tests passed\n";
