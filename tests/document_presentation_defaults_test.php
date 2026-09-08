<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentSchema.php';
require_once dirname(__DIR__) . '/lib/Documents/DocumentRepository.php';
use Prospektweb\Calc\Documents\{PdoConnection, DocumentSchema, DocumentRepository, SiteConnection};
$checks = 0;
function check(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
function rejected(callable $operation): void {
    try { $operation(); } catch (InvalidArgumentException $expected) { check(true, $expected->getMessage()); return; }
    throw new RuntimeException('Expected validation rejection');
}
$document = ['contract'=>'prospektweb.calculator/document-v1','schemaVersion'=>1,'id'=>'sheet','name'=>'Sheet',
    'presentations'=>['views'=>[['id'=>'BASE'],['id'=>'cards']]],'pricing'=>['types'=>[]]];
$site = ['contract'=>SiteConnection::CONTRACT,'provider'=>'bitrix:test','productsCatalog'=>'14','offersCatalog'=>'15',
    'products'=>[['key'=>'42','presentationId'=>'cards'],['key'=>'43','presentationId'=>'cards'],['key'=>'44','presentationId'=>'BASE']],
    'priceTypes'=>[],'formBindings'=>new stdClass(),'inputMappings'=>[],'outputMappings'=>[]];
$canonical = static fn($value) => SiteConnection::canonical(json_encode($value, JSON_THROW_ON_ERROR), $document);
$baseline = $canonical($site);
check(!property_exists(json_decode($baseline), 'presentationDefaults'), 'Old connection keeps its exact shape');
$site['presentationDefaults'] = [['presentationId'=>'cards','productKey'=>'43'],['presentationId'=>'BASE','productKey'=>null]];
$result = json_decode($canonical($site), true);
check($result['presentationDefaults'] === [['presentationId'=>'BASE','productKey'=>null],['presentationId'=>'cards','productKey'=>'43']], 'Explicit default/choose preserved and deterministically sorted');
foreach ([null, new stdClass(), [null], [['presentationId'=>'missing','productKey'=>null]],
    [['presentationId'=>'cards','productKey'=>'44']], [['presentationId'=>'cards','productKey'=>'999']],
    [['presentationId'=>'cards','productKey'=>43]], [['presentationId'=>'cards','productKey'=>'']],
    [['presentationId'=>'cards','productKey'=>null,'extra'=>true]], [['presentationId'=>'cards']],
    [['presentationId'=>'cards','productKey'=>null],['presentationId'=>'cards','productKey'=>'42']],
    array_fill(0,1001,['presentationId'=>'cards','productKey'=>null])] as $bad) {
    $candidate=$site; $candidate['presentationDefaults']=$bad; rejected(fn()=>$canonical($candidate));
}
$db = new PdoConnection(new PDO('sqlite::memory:')); DocumentSchema::install($db);
$repo = new DocumentRepository($db,'site:s1','user:1');
$repo->create(json_encode($document, JSON_THROW_ON_ERROR));
$saved=$repo->save('sheet',1,json_encode($document),$canonical($site),true);
check($saved['revision']===2 && json_decode($saved['connectionJson'],true)['presentationDefaults']===$result['presentationDefaults'], 'Defaults saved in immutable paired revision');
check($repo->productBinding('bitrix:test','14','43')===null, 'Draft defaults do not publish product bindings');
$moved=$site; $moved['products'][1]['presentationId']='BASE'; rejected(fn()=>$canonical($moved));
check($repo->load('sheet')['connectionJson']===$saved['connectionJson'], 'Rejected reassignment leaves storage unchanged');
$moved['presentationDefaults'][0]['productKey']=null;
$restored=$repo->save('sheet',2,json_encode($document),$canonical($moved),true);
check($restored['revision']===3 && $repo->load('sheet',2)['connectionJson']===$saved['connectionJson'], 'Explicit clearing permits reassignment and retains historical default');
echo "PASS $checks native presentation default checks\n";
