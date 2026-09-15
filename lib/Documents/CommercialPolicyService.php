<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__.'/BitrixCoreGateway.php';
require_once __DIR__.'/CommercialPolicyQuote.php';

/** Versioned QA publications. Read never upgrades or enrolls a working calculator.
 * Full workspace editor and cohort activation remain separate program stages. */
final class CommercialPolicyService
{
    private $gateway;
    public function __construct(?callable $gateway=null) { $this->gateway=$gateway??new BitrixCoreGateway(); }
    public function capabilities(): array { return ($this->gateway)(['action'=>'commercialCapabilities']); }
    public function validate(object $document,array $resources,object $bundle): array
    { return ($this->gateway)(['action'=>'commercialValidate','document'=>$document,'resources'=>$resources,'bundle'=>$bundle]); }
    public function executeContext(object $document,array $resources,object $bundle,array $values,array $quantities,array $valuesByFieldId,string $fingerprint): array
    {
        $views=$document->presentations->views??[];$view=null;
        $matches=array_values(array_filter($views,static fn($candidate)=>($candidate->id??null)===$bundle->storefront->ownerId));
        if(count($matches)!==1)throw new \InvalidArgumentException('STOREFRONT_OWNER_MISSING_OR_AMBIGUOUS:bundle.storefront.ownerId');
        $view=$matches[0];
        $production=($this->gateway)(['action'=>'commercialProduction','document'=>$document,'resources'=>$resources,'bundle'=>$bundle,'values'=>(object)$values,'quantities'=>$quantities,'expectedRuntimeFingerprint'=>$fingerprint]);
        if(($production['runtimeFingerprint']??'')!==$fingerprint)throw new \RuntimeException('RUNTIME_FINGERPRINT_MISMATCH',409);
        $matched=[];
        if($view!==null){
            if(!\Bitrix\Main\Loader::includeModule('prospektweb.frontcalc'))throw new \RuntimeException('SCENARIO_RUNTIME_UNAVAILABLE');
            $presentation=json_decode(json_encode($view->presentation,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR);
            $resolver=new \Prospektweb\Frontcalc\Service\CalculatorConditionResolver();
            $matched=\Prospektweb\Frontcalc\Service\StorefrontScenarios::matchedCommercialContext($presentation,$valuesByFieldId,$production['globals'],$resolver);
        }
        $execution=$this->execute($document,$resources,$bundle,$values,$quantities,$matched,$fingerprint);
        if(($execution['technicalHash']??null)!==($production['technicalHash']??null)||($execution['bundleHash']??null)!==($production['bundleHash']??null))throw new \RuntimeException('TECHNICAL_CONTEXT_CHANGED',409);
        return $execution;
    }
    public function execute(object $document,array $resources,object $bundle,array $values,array $quantities,array $matchedScenarioIds,string $expectedRuntimeFingerprint): array
    {
        $result=($this->gateway)(['action'=>'commercialExecute','document'=>$document,'resources'=>$resources,'bundle'=>$bundle,'values'=>(object)$values,'quantities'=>$quantities,
            'matchedScenarioIds'=>$matchedScenarioIds,'expectedRuntimeFingerprint'=>$expectedRuntimeFingerprint]);
        if (!is_array($result['result']??null) || ($result['result']['runtimeFingerprint']??'')!==$expectedRuntimeFingerprint) throw new \RuntimeException('RUNTIME_FINGERPRINT_MISMATCH',409);
        return $result['result'];
    }
    public static function storeQa(string $publicationId,object $packet,string $expectedHash,int $actor): array
    {
        if (!preg_match('/^spm03_qa_[A-Za-z0-9_-]{1,45}$/D',$publicationId) || $actor<=0 || !isset($packet->document,$packet->bundle,$packet->validation,$packet->preview)) throw new \InvalidArgumentException('QA_PUBLICATION_REQUIRED');
        if (($packet->bundle->calculator->provenance->purpose??'')!=='qa' || ((array)$packet->preview)['enrollmentEnabled']!==false) throw new \InvalidArgumentException('QA_ENROLLMENT_DISABLED');
        $json=CommercialPolicyQuote::canonicalJson($packet); $hash=hash('sha256',$json);
        if(strlen($json)>60000)throw new \InvalidArgumentException('QA_PUBLICATION_TOO_LARGE');
        if (!hash_equals($hash,$expectedHash)) throw new \RuntimeException('PUBLICATION_STALE',409);
        $db=\Bitrix\Main\Application::getConnection(); $key='SPM03_QA_'.$publicationId;
        $lock=substr(hash('sha256',$key),0,48);
        if ((int)$db->queryScalar("SELECT GET_LOCK('".$lock."',10)")!==1) throw new \RuntimeException('PUBLICATION_LOCKED',409);
        try {
            $old=\Bitrix\Main\Config\Option::get('prospektweb.calc',$key,'');
            if ($old!=='') {
                $stored=json_decode($old,true,64,JSON_THROW_ON_ERROR);
                if (($stored['bodyHash']??'')!==$hash) throw new \RuntimeException('PUBLICATION_IMMUTABLE',409);
                return $stored;
            }
            $record=['contract'=>'prospektweb.orderterms.qa-publication/v1','id'=>$publicationId,'bodyHash'=>$hash,'packetJson'=>$json,'actor'=>$actor,'recordedAt'=>gmdate('c'),'enrollmentEnabled'=>false];
            \Bitrix\Main\Config\Option::set('prospektweb.calc',$key,json_encode($record,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            return $record;
        } finally { $db->query("SELECT RELEASE_LOCK('".$lock."')"); }
    }
    public static function readQa(string $publicationId): array
    {
        if (!preg_match('/^spm03_qa_[A-Za-z0-9_-]{1,45}$/D',$publicationId)) throw new \InvalidArgumentException('QA_PUBLICATION_REQUIRED');
        $json=\Bitrix\Main\Config\Option::get('prospektweb.calc','SPM03_QA_'.$publicationId,'');
        if ($json==='') throw new \RuntimeException('PUBLICATION_NOT_FOUND',404);
        $record=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        if (hash('sha256',$record['packetJson'])!==$record['bodyHash']) throw new \RuntimeException('PUBLICATION_HASH_MISMATCH',409);
        return $record;
    }
}
