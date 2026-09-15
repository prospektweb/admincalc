<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__.'/CommercialPolicyService.php';
require_once __DIR__.'/DocumentFormRuntime.php';
require_once __DIR__.'/DocumentBatchForm.php';
require_once __DIR__.'/DocumentInputContext.php';
/** Internal preview: owned source, real form validation, pinned policies and calendar. */
final class CommercialPolicyPreview
{
 public static function run(array $c,DocumentRepository $repository,callable $resources,string $site):array
 {
  $keys=array_keys($c);sort($keys);$allowed=['action','documentId','versionId','expectedSourceHash','bundleJson','values','execution','activation','anchor'];sort($allowed);
  if($keys!==$allowed)throw new \InvalidArgumentException('UNKNOWN_PREVIEW_FIELD');
  foreach(['values','activation','execution']as$key)if(!($c[$key] instanceof \stdClass))throw new \InvalidArgumentException('INVALID_PREVIEW_INPUT:'.$key);
  $source=$repository->versions()->load($c['documentId'],$c['versionId']);
  if(!hash_equals($source['bodyHash'],$c['expectedSourceHash']))throw new DocumentConflict('POLICY_SOURCE_STALE');
  $document=json_decode($source['bodyJson'],false,64,JSON_THROW_ON_ERROR);
  $bundle=json_decode($c['bundleJson'],false,64,JSON_THROW_ON_ERROR);
  if(($bundle->documentHash??null)!==$source['bodyHash']||($bundle->ownerVersionId??null)!==$c['versionId'])throw new \InvalidArgumentException('POLICY_SOURCE_MISMATCH');
  foreach(['prospektweb.orderterms','prospektweb.frontcalc','catalog']as$id)if(!\Bitrix\Main\Loader::includeModule($id))throw new \RuntimeException('MODULE_UNAVAILABLE',503);
  require_once \Bitrix\Main\Loader::getLocal('modules/prospektweb.frontcalc/lib/Service/FormSectionState.php');
  $runtime=(new DocumentFormRuntime())($document,$source['revision']);
  $view=$bundle->storefront->ownerId;
  DocumentBatchForm::validate($runtime,$view,$c['values'],$c['activation'],$c['execution']);
  $resourceSnapshot=$resources($document);$service=new CommercialPolicyService();$caps=$service->capabilities();
  $validation=$service->validate($document,$resourceSnapshot,$bundle);
  $execution=$service->executeContext($document,$resourceSnapshot,$bundle,(array)DocumentInputContext::values($document,$c['values'],$view),[
    'unitCount'=>$c['execution']->unitCount,'layoutCount'=>$c['execution']->layoutCount,'runCount'=>$c['execution']->runCount],(array)$c['values'],$caps['runtimeFingerprint']);
  $ref=$execution['resolvedPolicy']['calendarRef'];$calendar=(new \Prospektweb\OrderTerms\CalendarStore())->read($site,$ref['id'],$ref['revision']);
  $start=$execution['resolvedPolicy']['startRuleRef'];$settings=(new \Prospektweb\OrderTerms\SettingsStore())->read($site,$start['id'],$start['revision']);
  if($settings['bodyHash']!==$start['bodyHash'])throw new DocumentConflict('START_RULE_STALE');
  $connection=json_decode($source['connectionJson']??'null');$bindings=[];$groups=[];
  foreach($connection->priceTypes??[]as$b){
   if(!is_string($b->key)||!preg_match('/^[1-9][0-9]*$/D',$b->key)||!\CCatalogGroup::GetByID((int)$b->key))throw new \InvalidArgumentException('PRICE_BINDING_INVALID');
   $bindings[]=['typeId'=>$b->typeId,'catalogGroupId'=>(int)$b->key];$groups[]=(int)$b->key;
  }
  if(!$bindings)throw new \InvalidArgumentException('PRICE_BINDINGS_REQUIRED:connections.priceTypes');
  // The endpoint is administrator-only. No public user's price rights are inferred here.
  $round=static function(int $id,float $price,string $currency):array{
   $ref=['contract'=>'prospektweb.orderterms.bitrix-rounding/v1','catalogGroupId'=>$id,'currency'=>$currency,'rules'=>\Bitrix\Catalog\Product\Price::getRoundRules($id)];
   $ref['bodyHash']=hash('sha256',CommercialPolicyQuote::canonicalJson($ref));
   return ['amount'=>(string)\Bitrix\Catalog\Product\Price::roundPrice($id,$price,$currency),'roundingRef'=>$ref];
  };
  $result=CommercialPolicyQuote::finalize($execution,$calendar,$c['anchor'],gmdate('Y-m-d\TH:i:s\Z'),$bindings,$groups,[\Prospektweb\OrderTerms\Calendar::class,'preview'],$round);
  if($repository->versions()->load($c['documentId'],$c['versionId'])['bodyHash']!==$source['bodyHash'])throw new DocumentConflict('POLICY_SOURCE_STALE');
  return ['preview'=>$result,'validation'=>$validation,'publicationValidated'=>false,'enrollmentEnabled'=>false];
 }
}
