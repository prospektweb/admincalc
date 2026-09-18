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
