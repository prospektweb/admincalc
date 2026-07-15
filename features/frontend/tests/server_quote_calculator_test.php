<?php
require_once __DIR__ . '/../lib/Service/ServerQuoteCalculator.php';
use Prospektweb\Frontcalc\Service\ServerQuoteCalculator;
function eq($a,$b,$m){ if($a!=$b){fwrite(STDERR,"$m: ".var_export($a,true)." != ".var_export($b,true)."\n"); exit(1);} }
$c=new ServerQuoteCalculator();
function off($key,$q,$p,$src='bitrix',$cur='RUB',$props=['CALC_PROP_FORMAT'=>['value'=>'A4 name','xml_id'=>'A4']],$ranges=null,$id=1,$virtual=null,$name=''){return ['offerKey'=>$key,'id'=>$id,'source'=>$src,'isVirtual'=>$virtual??($src!=='bitrix'),'name'=>$name,'quantity'=>$q,'properties'=>$props,'pricing'=>['ranges'=>$ranges??[['typeId'=>1,'quantityFrom'=>1,'quantityTo'=>null,'price'=>$p,'currency'=>$cur]]]];}
$sel=['CALC_PROP_FORMAT'=>['value'=>'browser spoof','xmlId'=>'A4']];
$cfg=['requiredPropertyCodes'=>['CALC_PROP_FORMAT','CALC_PROP_VOLUME']];
eq($c->calculate([off('a',100,1000)],[],100,1,'strict',$cfg)['code'],'FRONTCALC_QUOTE_SELECTION_INVALID','missing required');
eq($c->calculate([off('a',100,1000)],$sel+['CALC_PROP_BAD'=>['xmlId'=>'X']],100,1,'strict',$cfg)['code'],'FRONTCALC_QUOTE_SELECTION_INVALID','unknown prop');
eq($c->calculate([off('a',100,1000)],['CALC_PROP_FORMAT'=>['xmlId'=>['x']]],100,1,'strict',$cfg)['code'],'FRONTCALC_QUOTE_SELECTION_INVALID','array token rejected');
eq($c->calculate([off('a',100,1000)],['CALC_PROP_FORMAT'=>'A4'],100,1,'strict',$cfg)['code'],'FRONTCALC_QUOTE_SELECTION_INVALID','scalar selected value rejected');
eq($c->calculate([off('a',100,1000)],['CALC_PROP_FORMAT'=>true],100,1,'strict',$cfg)['code'],'FRONTCALC_QUOTE_SELECTION_INVALID','boolean selected value rejected');
eq($c->calculate([off('a',100,1000)],['CALC_PROP_FORMAT'=>['xmlId'=>true]],100,1,'strict',$cfg)['code'],'FRONTCALC_QUOTE_SELECTION_INVALID','boolean token rejected');
eq($c->calculate([off('a',100,1000)],$sel,100,1,'strict',$cfg)['price'],1000,'exact');
eq($c->calculate([off('a',100,1000),off('b',200,1800)],$sel,150,1,'strict',$cfg)['mode'],'interpolated','interp mode');
eq($c->calculate([off('a',100,1000),off('b',200,1800)],$sel,50,1,'strict',$cfg)['mode'],'extrapolated','extra low mode');
eq($c->calculate([off('a',100,777)],$sel,500,1,'strict',$cfg)['mode'],'single','single mode');
$r=[['typeId'=>1,'quantityFrom'=>1,'quantityTo'=>1,'price'=>1000,'currency'=>'RUB'],['typeId'=>1,'quantityFrom'=>2,'quantityTo'=>2,'price'=>900,'currency'=>'RUB'],['typeId'=>1,'quantityFrom'=>3,'quantityTo'=>null,'price'=>800,'currency'=>'RUB']]; eq($c->calculate([off('a',10000,0,'bitrix','RUB',['CALC_PROP_FORMAT'=>['xml_id'=>'A4']],$r)],$sel,10000,1,'strict',$cfg)['price'],1000,'catalog range uses one basket item rather than circulation');
eq($c->calculate([off('bad',100,1,'bitrix','RUB',[])],$sel,100,1,'strict',$cfg)['success'],false,'strict matching');
eq($c->calculate([off('v',100,1,'calc-server'),off('b',100,2,'bitrix')],$sel,100,1,'strict',$cfg)['price'],2,'dupe priority');
eq($c->calculate([off('a',100,1000,'bitrix','rub'),off('b',200,1800,'bitrix','RUB')],$sel,150,1,'strict',$cfg)['currency'],'RUB','case currency');
eq($c->calculate([off('a',100,1000,'bitrix','RUB'),off('b',200,1800,'bitrix','USD')],$sel,150,1,'strict',$cfg)['code'],'FRONTCALC_QUOTE_CURRENCY_MISMATCH','currency mismatch');
eq($c->calculate([off('a',100,1000)],$sel,100,1,'urgent',$cfg+['deadline_adjustments'=>['mode'=>'simple','urgent_markup'=>20]])['price'],1200,'urgent simple');
eq($c->calculate([off('a',100,1000)],$sel,100,1,'flexible',$cfg+['deadline_adjustments'=>['mode'=>'simple','flexible_discount'=>10]])['price'],900,'flex simple');
eq($c->calculate([off('a',100,1000)],$sel,100,1,'urgent',$cfg+['deadline_adjustments'=>['mode'=>'advanced','advanced'=>['urgent_markup'=>[['volume'=>1,'percent'=>10],['volume'=>100,'percent'=>20]]]]])['price'],1200,'urgent actual adv');
eq($c->calculate([off('a',100,1000)],$sel,100,1,'flexible',$cfg+['deadline_adjustments'=>['mode'=>'advanced','advanced'=>['flexible_discount'=>[['volume'=>1,'percent'=>5],['volume'=>100,'percent'=>10]]]]])['price'],900,'flex actual adv');
$res=$c->calculate([off('a',100,1000.005)],$sel,100,1,'strict',$cfg); eq($res['price'],1000.01,'rounded'); eq($res['normalizedSelectedValues']['CALC_PROP_FORMAT']['value'],'A4 name','normalized from offer');
$roundCalls=[];
$rounded=new ServerQuoteCalculator(static function($group,$price,$currency) use (&$roundCalls){$roundCalls[]=[$group,$price,$currency]; return ceil($price/10)*10;});
$roundedResult=$rounded->calculate([off('a',100,1000,'calc-server','RUB',['CALC_PROP_FORMAT'=>['value'=>'A4 name','xml_id'=>'A4']],null,1,true,'Calc title 100'),off('b',200,1800,'calc-server','RUB',['CALC_PROP_FORMAT'=>['value'=>'A4 name','xml_id'=>'A4']],null,2,true,'Calc title 200')],$sel,150,1,'strict',$cfg);
eq($roundedResult['price'],1400,'injected Bitrix rounder result');
eq($roundedResult['name'],'Calc title 100','nearest calc-server title returned');
eq($roundCalls[0][0],1,'rounder receives catalog group');
eq($roundCalls[0][2],'RUB','rounder receives currency');
echo "OK\n";
