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
$p=(object)['texts'=>(object)array_fill_keys(['title','intro','time','note','cancel','apply'],'Text'),'help'=>(object)[],'rules'=>(object)['immediate'=>(object)['days'=>0,'serviceId'=>'']],'allowBudget'=>true,'requireDescription'=>false];
$d=(object)['form'=>(object)['fields'=>[(object)['systemKey'=>'payment','processWindow'=>$p]]]];
AdditionalServices::validate($d);$before=json_encode($d);AdditionalServices::validate($d);if(json_encode($d)!==$before)throw new Exception('Process settings mutated');
foreach(['days','wrongField','help'] as $case){$bad=unserialize(serialize($d));if($case==='days')$bad->form->fields[0]->processWindow->rules->immediate->days=-1;if($case==='wrongField')$bad->form->fields[0]->systemKey='urgency';if($case==='help')$bad->form->fields[0]->processWindow->help->unknown=(object)['enabled'=>true,'text'=>'x'];try{AdditionalServices::validate($bad);}catch(InvalidArgumentException $e){continue;}throw new Exception('Invalid process accepted: '.$case);}
echo "PASS process windows storage, rules and field boundary\n";
$design=(object)['texts'=>(object)array_fill_keys(['title','task','budget','timeline','description','placeholder','upload','uploadHint','verification','approval','total','cancel','apply'],'Text'),'help'=>(object)[],'rules'=>(object)['later'=>(object)['days'=>0,'serviceId'=>'']],'allowBudget'=>true,'requireDescription'=>false];
$designDocument=(object)['form'=>(object)['fields'=>[(object)['systemKey'=>'design','processWindow'=>$design]]]];
AdditionalServices::validate($designDocument);
$design->texts->laterTitle='Когда предоставите макет?';$design->texts->laterTime='Время';$design->help->laterTitle=(object)['enabled'=>true,'text'=>'Подготовьте макет'];
$design->designLaterHours=72;
AdditionalServices::validate($designDocument);
$invalidHours=unserialize(serialize($designDocument));$invalidHours->form->fields[0]->processWindow->designLaterHours=0;
try{AdditionalServices::validate($invalidHours);throw new Exception('Invalid design waiting limit accepted');}catch(InvalidArgumentException $e){}
$invalidDesign=unserialize(serialize($designDocument));$invalidDesign->form->fields[0]->processWindow->texts->unknown='x';
try{AdditionalServices::validate($invalidDesign);throw new Exception('Unknown design text accepted');}catch(InvalidArgumentException $e){}
echo "PASS design later window text and help storage\n";

$d=(object)['form'=>(object)['fields'=>[(object)['systemKey'=>'payment','dateSelection'=>(object)['calendarId'=>'qa','weekend'=>(object)['allowed'=>true,'source'=>'global','from'=>540,'to'=>1080],'holiday'=>(object)['allowed'=>false,'source'=>'global','from'=>540,'to'=>1080],'overtime'=>(object)['allowed'=>true,'source'=>'individual','from'=>0,'to'=>1200]]]]]];
AdditionalServices::validate($d);
$d->form->fields[0]->systemKey='design';
AdditionalServices::validate($d);
$d->form->fields[0]->systemKey='payment';
$d->form->fields[0]->dateSelection->overtime->from=540;
try{AdditionalServices::validate($d);throw new Exception('Invalid overtime start accepted');}catch(InvalidArgumentException $e){}
echo "PASS payment calendar rules and locked overtime start\n";

$storage=(object)['mode'=>'anchors','daily'=>0,'maxAmount'=>1000,'anchors'=>[(object)['days'=>1,'value'=>0],(object)['days'=>3,'value'=>0],(object)['days'=>30,'value'=>2000]],'notice'=>'Правила хранения'];
$texts=(object)array_fill_keys(['title','method','addresses','address','addressHint','comment','recipient','name','phone','carrier','terminal','consent','note','total','cancel','apply'],'text');
$d=(object)['form'=>(object)['fields'=>[(object)['systemKey'=>'receipt','processWindow'=>(object)['texts'=>$texts,'help'=>(object)[],'rules'=>(object)[],'allowBudget'=>true,'requireDescription'=>false,'storage'=>$storage]]]]];
AdditionalServices::validate($d);
$bad=unserialize(serialize($d));$bad->form->fields[0]->processWindow->storage->anchors[0]->value=50;$bad->form->fields[0]->processWindow->storage->anchors[1]->value=100;
try{AdditionalServices::validate($bad);throw new Exception('Paid first storage day accepted');}catch(InvalidArgumentException $e){}
$d->form->fields[0]->processWindow->storage->anchors[2]->days=3;
try{AdditionalServices::validate($d);throw new Exception('Duplicate storage marks accepted');}catch(InvalidArgumentException $e){}
echo "PASS storage zero marks, schema and duplicate rejection\n";
$q=(object)['immediateMinutes'=>60,'laterHours'=>72,'fixPrice'=>true,'fixHours'=>72,'immediateHint'=>'Now','laterHint'=>'Later'];
$p=(object)['texts'=>(object)array_fill_keys(['title','intro','time','note','cancel','apply'],'Text'),'help'=>(object)[],'rules'=>(object)[],'allowBudget'=>true,'requireDescription'=>false,'payment'=>$q];
$d=(object)['form'=>(object)['fields'=>[(object)['systemKey'=>'payment','processWindow'=>$p]]]];
AdditionalServices::validate($d);
foreach(['legacyProof','hours','legacyReason'] as $case){$bad=unserialize(serialize($d));$q=$bad->form->fields[0]->processWindow->payment;if($case==='legacyProof')$q->proofEnabled=false;if($case==='hours')$q->laterHours=0;if($case==='legacyReason')$q->reasons=[''];try{AdditionalServices::validate($bad);}catch(InvalidArgumentException $e){continue;}throw new Exception('Invalid payment accepted: '.$case);}
echo "PASS current pilot payment policy contract\n";

$rp=(object)['texts'=>$texts,'help'=>(object)[],'rules'=>(object)[],'allowBudget'=>true,'requireDescription'=>false,'deliverySlots'=>[(object)['from'=>'10:00','to'=>'14:00'],(object)['from'=>'14:00','to'=>'18:00']]];
$receipt=(object)['form'=>(object)['fields'=>[(object)['systemKey'=>'receipt','processWindow'=>$rp]]]];
AdditionalServices::validate($receipt);
$receipt->form->fields[0]->processWindow->deliverySlots[1]->from='13:00';
try{AdditionalServices::validate($receipt);throw new Exception('Overlapping delivery slots accepted');}catch(InvalidArgumentException $e){}
echo "PASS delivery slots and overlap rejection\n";
