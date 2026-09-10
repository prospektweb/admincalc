<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/DocumentInputContext.php';
use Prospektweb\Calc\Documents\DocumentInputContext;
$document = (object)['name'=>'Листовая печать','presentations'=>(object)['views'=>[
    (object)['id'=>'BASE','name'=>'Старое название'],
    (object)['id'=>'cards','name'=>'Визитки','active'=>true,'public'=>true],
    (object)['id'=>'hidden','name'=>'Скрытая','active'=>false,'public'=>true],
]]];
$values=(object)['qty'=>123,'storefront:name'=>'Подмена'];$before=serialize([$document,$values]);
foreach ([['BASE',false,'Листовая печать'],['cards',false,'Визитки'],['cards',true,'Визитки'],['hidden',false,'Скрытая'],['hidden',true,'Листовая печать']] as [$id,$public,$expected]) {
    $actual=DocumentInputContext::values($document,$values,$id,$public);
    if($actual->{'storefront:name'}!==$expected || $actual->qty!==123)throw new RuntimeException('Incorrect storefront context');
}
foreach (['missing',''] as $id) {
    try {DocumentInputContext::values($document,$values,$id);throw new LogicException('Missing storefront accepted');}
    catch(InvalidArgumentException $expected) {}
}
if(serialize([$document,$values])!==$before)throw new RuntimeException('Context mutated inputs');
echo "PASS 8 storefront context cases\n";
