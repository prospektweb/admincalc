<?php
require_once __DIR__.'/../lib/Documents/AdditionalServices.php';
use Prospektweb\Calc\Documents\AdditionalServices;
AdditionalServices::validate((object)['pricing'=>(object)[]]);
$source=json_decode('{"pricing":{"additionalServices":{"contract":"prospektweb.calculator.additional-services/v1","calculator":{"design":[],"delivery":[],"urgency":{"enabled":false,"minimum":"1000","step":"100","description":"","desiredDescription":""},"flexibility":{"enabled":false,"percent":"0","description":""}},"storefronts":{},"scenarios":{}}}}');
$before=json_encode($source); AdditionalServices::validate($source);
if(json_encode($source)!==$before)throw new Exception('Validation mutated settings');
foreach (['zeroStep','missingSection','invalidMoney','unknownSection'] as $case) {
 $d=json_decode($before);$s=$d->pricing->additionalServices;
 if($case==='zeroStep')$s->calculator->urgency->step='0';
 if($case==='missingSection')unset($s->calculator->design);
 if($case==='invalidMoney')$s->calculator->urgency->minimum='NaN';
 if($case==='unknownSection')$s->storefronts->view=(object)['unknown'=>true];
 try{AdditionalServices::validate($d);}catch(InvalidArgumentException $e){continue;}
 throw new Exception('Accepted '.$case);
}
echo "PASS additional services storage validation, legacy compatibility and invalid inputs\n";

$keys=['paymentTitle','paymentAfterLabel','paymentAfterHint','paymentImmediateLabel','paymentImmediateHint','title','desiredLabel','desiredPlaceholder','desiredHelp','amountLabel','explanation','allocation','refundTitle','refundLabel','refundHint','balanceLabel','balanceHint','refundNote','cancelLabel','doneLabel'];
$v=(object)array_fill_keys($keys,'Text');$v->minimum='1000';$v->step='100';$v->paymentDefault='after_confirmation';
$d=(object)['form'=>(object)['fields'=>[(object)['systemKey'=>'urgency','urgencyWindow'=>$v]]]];
AdditionalServices::validate($d);
foreach(['step','paymentDefault','extra'] as $key){$bad=unserialize(serialize($d));$bad->form->fields[0]->urgencyWindow->$key='0';try{AdditionalServices::validate($bad);}catch(InvalidArgumentException $e){continue;}throw new Exception('Accepted invalid urgency '.$key);}
echo "PASS urgency window persistence validation\n";
$v->labelHelp=(object)['paymentTitle'=>(object)['enabled'=>false,'text'=>'Описание сохранено']];
$before=json_encode($d);AdditionalServices::validate($d);
if(json_encode($d)!==$before)throw new Exception('Label help mutated');
$v->labelHelp->paymentTitle->enabled='false';
try{AdditionalServices::validate($d);throw new Exception('Invalid switch accepted');}catch(InvalidArgumentException $e){}
echo "PASS optional label help validation and preservation\n";

$f=(object)['texts'=>(object)array_fill_keys(['title','intro','timeline','deadline','discount','percent','amount','basis','explanation','cancel','apply'],'Text'),'mode'=>'anchors','unit'=>'percent','daily'=>1,'maxAmount'=>1000,'anchors'=>[(object)['days'=>5,'value'=>5],(object)['days'=>30,'value'=>20]]];
$d=(object)['form'=>(object)['fields'=>[(object)['systemKey'=>'flexible','flexibilityWindow'=>$f]]]];
AdditionalServices::validate($d);
foreach(['max','count','descending','wrongField'] as $case){$bad=unserialize(serialize($d));$b=$bad->form->fields[0]->flexibilityWindow;if($case==='max')$b->maxAmount=-1;if($case==='count')$b->anchors=array_fill(0,6,$b->anchors[0]);if($case==='descending')$b->anchors[1]->value=1;if($case==='wrongField')$bad->form->fields[0]->systemKey='urgency';try{AdditionalServices::validate($bad);}catch(InvalidArgumentException $e){continue;}throw new Exception('Invalid flexibility accepted: '.$case);}
echo "PASS flexibility storage shape, cap, ordering and field boundary\n";
