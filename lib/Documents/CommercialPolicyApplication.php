<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__.'/DocumentLibrary.php';
require_once __DIR__.'/CommercialPolicyService.php';

/** Detached immutable authoring revisions; identity comes from the authenticated adapter.
 * Every body pins the exact source bytes/resources. Saving never activates a website policy. */
final class CommercialPolicyApplication
{
    private DocumentLibrary $library;
    private DocumentRepository $documents;
    public function __construct(SqlConnection $db,string $scope,string $actor,private $core,private $resources)
    {
        $this->library=new DocumentLibrary($db,$scope,$actor,'commercial-policy');
        $this->documents=new DocumentRepository($db,$scope,$actor);
    }
    public function command(array $command): array
    {
        $fields=[
            'commercialPolicies'=>[],
            'loadCommercialPolicy'=>['id','revision'],
            'createCommercialPolicy'=>['expectedCatalogRevision','name','documentId','versionId','expectedSourceHash','bundleJson'],
            'saveCommercialPolicy'=>['id','expectedRevision','expectedCatalogRevision','documentId','versionId','expectedSourceHash','bundleJson'],
        ];
        $action=$command['action']??null;
        if(!is_string($action)||!isset($fields[$action]))throw new \InvalidArgumentException('UNKNOWN_POLICY_COMMAND');
        $keys=array_keys($command);sort($keys);$expected=array_merge(['action'],$fields[$action]);sort($expected);
        if($keys!==$expected)throw new \InvalidArgumentException('UNKNOWN_OR_MISSING_POLICY_FIELD');
        foreach($fields[$action] as $field){
            if(in_array($field,['revision','expectedRevision','expectedCatalogRevision'],true)){
                if(!is_int($command[$field])||$command[$field]<($field==='expectedCatalogRevision'?0:1)||$command[$field]>2147483646)throw new \InvalidArgumentException('INVALID_POLICY_REVISION');
            }elseif(!is_string($command[$field]))throw new \InvalidArgumentException('INVALID_POLICY_FIELD:'.$field);
        }
        if($action==='commercialPolicies')return $this->library->listing();
        // Historical reads return stored bytes, without calling a newer compiler or resources.
        if($action==='loadCommercialPolicy')return $this->library->load($command['id'],$command['revision']);
        $source=$this->documents->versions()->load($command['documentId'],$command['versionId']);
        if(!hash_equals($source['bodyHash'],$command['expectedSourceHash']))throw new DocumentConflict('POLICY_SOURCE_STALE');
        if($source['versionArchived'])throw new DocumentConflict('POLICY_SOURCE_ARCHIVED');
        if(strlen($command['bundleJson'])>500000)throw new \InvalidArgumentException('POLICY_TOO_LARGE');
        $bundle=json_decode($command['bundleJson'],false,64,JSON_THROW_ON_ERROR);
        if(!$bundle instanceof \stdClass||($bundle->ownerVersionId??null)!==$command['versionId']||($bundle->documentHash??null)!==$source['bodyHash'])throw new \InvalidArgumentException('POLICY_SOURCE_MISMATCH');
        $document=json_decode($source['bodyJson'],false,64,JSON_THROW_ON_ERROR);
        $resources=($this->resources)($document);
        $validation=(new CommercialPolicyService($this->core))->validate($document,$resources,$bundle);
        if(($validation['documentHash']??null)!==$source['bodyHash']||!preg_match('/^[a-f0-9]{64}$/D',$validation['runtimeFingerprint']??'')||!preg_match('/^[a-f0-9]{64}$/D',$validation['bundleHash']??''))throw new \RuntimeException('POLICY_VALIDATION_RECEIPT_INVALID');
        $state=$validation['validation']??[];
        if(($state['contract']??'')!=='prospektweb.orderterms.policy-validation/v1'||!in_array($state['status']??'', ['static-valid','runtime-preview-required'],true)
            ||($state['runtimeValidated']??null)!==false||($state['publicationValidated']??null)!==false)throw new \RuntimeException('POLICY_VALIDATION_STATE_REQUIRED');
        $json=CommercialPolicyQuote::canonicalJson((object)[
            'contract'=>'prospektweb.orderterms.policy-record/v1','documentId'=>$command['documentId'],
            'versionId'=>$command['versionId'],'sourceRevision'=>$source['revision'],'sourceHash'=>$source['bodyHash'],
            'sourceJson'=>$source['bodyJson'],'resources'=>$resources,'bundle'=>$bundle,'validation'=>$validation,'validationState'=>$state['status'],'publicationValidated'=>false,'enrollmentEnabled'=>false,
        ]);
        if($action==='createCommercialPolicy')return $this->library->create($command['expectedCatalogRevision'],$command['name'],$json);
        $old=json_decode($this->library->load($command['id'],$command['expectedRevision'])['bodyJson'],true,64,JSON_THROW_ON_ERROR);
        if($old['documentId']!==$command['documentId']||$old['versionId']!==$command['versionId'])throw new DocumentConflict('POLICY_OWNER_IMMUTABLE');
        return $this->library->change('save',$command['id'],$command['expectedCatalogRevision'],$command['expectedRevision'],$json);
    }
}
