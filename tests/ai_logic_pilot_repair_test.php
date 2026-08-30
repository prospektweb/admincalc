<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/AiLogicPilotDraftStore.php';
require_once dirname(__DIR__) . '/lib/Services/AiLogicPilotRepairService.php';

use Prospektweb\Calc\Services\AiLogicPilotDraftStore;
use Prospektweb\Calc\Services\AiLogicPilotRepairService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$baseHash = str_repeat('a', 64);
$oldHash = str_repeat('b', 64);
$currentHash = str_repeat('c', 64);
$versionId = 'v_1234567890abcdef';
$draft = [
    'schema' => 'prospektweb.calc.ai-logic-pilot-draft/v1',
    'context' => ['presetId' => 16488, 'versionKey' => $versionId, 'baseCompileHash' => $baseHash],
    'catalogFolders' => [
        ['draftId'=>'draft_folder_calculator','kind'=>'calculator','title'=>'Расчёты','description'=>'Папка','parentDraftId'=>null],
    ],
    'catalogObjects' => [
        ['draftId'=>'draft_calculator','kind'=>'calculator','title'=>'Расчёт площади','description'=>'CALC_SETTINGS','folderDraftId'=>'draft_folder_calculator'],
    ],
    'globals' => [['draftId'=>'draft_global','kind'=>'variable','code'=>'needs_cut','title'=>'Нужна резка','description'=>'Флаг']],
    'details' => [['draftId'=>'draft_detail','kind'=>'detail','title'=>'Изделие','description'=>'Корневая деталь','parentDraftId'=>null]],
    'stages' => [['draftId'=>'draft_stage','detailDraftId'=>'draft_detail','title'=>'Печать','description'=>'Этап','catalogDraftIds'=>['draft_calculator']]],
    'groups' => [],
];
$stored = ['status'=>'ok','found'=>true,'draftRevision'=>3,'draft'=>$draft,'decisions'=>[],'replacements'=>[]];
$bundle = ['contentHash'=>$currentHash,'componentHashes'=>['logic'=>str_repeat('d',64)],'documents'=>['logic'=>['workingPresetId'=>20001]]];
$latestReceipt = ['presetId'=>16488,'versionId'=>$versionId,'manifestHash'=>str_repeat('e',64),'appliedAt'=>'2026-08-30T12:00:00Z',
    'created'=>[
        'draft_folder_calculator'=>['kind'=>'directory','id'=>101],
        'draft_calculator'=>['kind'=>'calculator','id'=>201],
        'draft_detail'=>['kind'=>'detail','id'=>401],
        'draft_stage'=>['kind'=>'stage','id'=>501],
    ], 'reused'=>[], 'replaced'=>[]];
$states = [
    101=>['name'=>'Старая папка','description'=>'','parentId'=>0,'properties'=>[]],
    201=>['code'=>'','name'=>'Расчёт площади','description'=>'CALC_SETTINGS','sectionId'=>0,'properties'=>[]],
    401=>['code'=>'','name'=>'Изделие','description'=>'Корневая деталь','sectionId'=>0,'properties'=>['CALC_STAGES'=>[]]],
    501=>['code'=>'','name'=>'Печать','description'=>'Этап','sectionId'=>0,'properties'=>[]],
    601=>['code'=>'needs_cut','name'=>'Нужна резка','description'=>'Флаг','sectionId'=>0,'properties'=>[]],
    20001=>['code'=>'working','name'=>'Рабочий граф','description'=>'','sectionId'=>0,'properties'=>['CALC_DETAILS'=>[]]],
];
$options = [];
$applied = [];
$service = new AiLogicPilotRepairService([
    'assert_admin'=>static fn()=>null,
    'bundle'=>static fn(array $_context): array=>$bundle,
    'draft'=>static fn(array $_context): array=>$stored,
    'receipts'=>static fn(array $_context): array=>[
        array_merge($latestReceipt,['appliedAt'=>'2026-08-29T12:00:00Z','created'=>array_merge($latestReceipt['created'],['draft_stage'=>['kind'=>'stage','id'=>499]])]),
        $latestReceipt,
    ],
    'find_global'=>static fn(int $_presetId,string $_code): int=>601,
    'read_state'=>static fn(array $spec): ?array=>$states[(int)$spec['id']]??null,
    'apply_operations'=>static function(array $operations,int $workingPresetId) use (&$applied): void { $applied=$operations; if($workingPresetId!==20001)throw new RuntimeException('wrong graph'); },
    'transaction'=>static fn(callable $callback)=>$callback(),
    'option_get'=>static function(string $key)use(&$options){return $options[$key]??'';},
    'option_set'=>static function(string $key,string $raw)use(&$options):void{$options[$key]=$raw;},
    'skip_capture'=>static fn()=>true,
    'after_content_hash'=>$currentHash,
]);
$request=['presetId'=>16488,'versionId'=>$versionId,'baseCompileHash'=>$baseHash,'expectedContentHash'=>$currentHash];
$inspection=$service->inspect($request);
$assert($inspection['healthy']===false && $inspection['blockers']===[],'recoverable broken graph must be reported without blockers');
$assert($inspection['receipt']['appliedAt']==='2026-08-30T12:00:00Z','latest receipt must win for preset/version');
$types=array_values(array_unique(array_column($inspection['operations'],'type')));
$assert(in_array('metadata',$types,true)&&in_array('section',$types,true)&&in_array('properties',$types,true)&&in_array('append_property',$types,true),'repair plan must cover codes, folders and graph links');
$assert(count(array_filter($inspection['operations'],static fn(array $op):bool=>in_array($op['type'],['create','delete','activate'],true)))===0,'repair must never create, delete or activate');
$first=$service->repair($request+['explicitConfirm'=>true]);
$second=$service->repair($request+['explicitConfirm'=>true]);
$assert($first['status']==='ok'&&$first['idempotentReplay']===false&&$second['idempotentReplay']===true&&$applied!==[],'repair must be explicit and idempotent');

$forbidden=false;
try{$service->inspect(array_merge($request,['presetId'=>12740]));}catch(RuntimeException $error){$forbidden=$error->getCode()===403;}
$assert($forbidden,'preset 12740 must be hard denied');

$rolledBack=[];
$failing=new AiLogicPilotRepairService([
    'assert_admin'=>static fn()=>null,'bundle'=>static fn(array $_):array=>$bundle,'draft'=>static fn(array $_):array=>$stored,
    'receipts'=>static fn(array $_):array=>[$latestReceipt],'find_global'=>static fn(int $_,string $__):int=>601,
    'read_state'=>static fn(array $spec):?array=>$states[(int)$spec['id']]??null,
    'apply_operations'=>static function(array $_,int $__)use(&$rolledBack):void{$rolledBack[]='partial';throw new RuntimeException('injected');},
    'transaction'=>static function(callable $callback)use(&$rolledBack){$snapshot=$rolledBack;try{return $callback();}catch(Throwable $e){$rolledBack=$snapshot;throw $e;}},
    'option_get'=>static fn(string $_):string=>'','option_set'=>static fn(string $_,string $__)=>(null),'skip_capture'=>static fn()=>true,
]);
$failed=false;try{$failing->repair($request+['explicitConfirm'=>true]);}catch(RuntimeException $error){$failed=$error->getMessage()==='injected';}
$assert($failed&&$rolledBack===[],'failed repair must roll back and must not write a receipt');

// A post-apply content hash must not orphan the only stored draft.
if (!class_exists('AiPilotRepairTestUser')) { class AiPilotRepairTestUser { public function IsAdmin():bool{return true;} public function GetID():int{return 77;} } }
$USER=new AiPilotRepairTestUser();
$tmp=sys_get_temp_dir().'/pw-ai-repair-'.bin2hex(random_bytes(5));
$store=new AiLogicPilotDraftStore($tmp);
$store->save(['presetId'=>16488,'versionKey'=>$versionId,'baseCompileHash'=>$baseHash,'expectedContentHash'=>$oldHash,'draft'=>$draft,'decisions'=>[],'replacements'=>[],'clientRevision'=>1]);
$recovered=$store->loadLatestForRepair(['presetId'=>16488,'versionKey'=>$versionId,'baseCompileHash'=>str_repeat('f',64),'expectedContentHash'=>$currentHash,'appliedAt'=>gmdate('c',time()+60)]);
$assert(($recovered['found']??false)===true&&($recovered['expectedContentHash']??'')===$oldHash,'repair lookup must find a pre-apply draft after version hash advances');
foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);

echo "AI logic pilot repair tests passed\n";
