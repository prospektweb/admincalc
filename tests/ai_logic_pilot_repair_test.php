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
        ['draftId'=>'draft_real_calculator','kind'=>'calculator','title'=>'Реальный калькулятор','description'=>'Замена','folderDraftId'=>'draft_folder_calculator'],
        ['draftId'=>'draft_material','kind'=>'material','title'=>'Баннер','description'=>'Материал','folderDraftId'=>null],
        ['draftId'=>'draft_material_variant','kind'=>'materialVariant','title'=>'Баннер 440','description'=>'Вариант','parentDraftId'=>'draft_material'],
        ['draftId'=>'draft_operation','kind'=>'operation','title'=>'Печать','description'=>'Операция','folderDraftId'=>null],
        ['draftId'=>'draft_operation_variant','kind'=>'operationVariant','title'=>'Печать 720 dpi','description'=>'Вид операции','parentDraftId'=>'draft_operation'],
        ['draftId'=>'draft_equipment','kind'=>'equipment','title'=>'Плоттер','description'=>'Оборудование','folderDraftId'=>null],
        ['draftId'=>'draft_custom_field','kind'=>'customField','title'=>'Комментарий','description'=>'Дополнительное поле','folderDraftId'=>null],
    ],
    'globals' => [['draftId'=>'draft_global','kind'=>'variable','code'=>'needs_cut','title'=>'Нужна резка','description'=>'Флаг']],
    'details' => [['draftId'=>'draft_detail','kind'=>'detail','title'=>'Изделие','description'=>'Корневая деталь','parentDraftId'=>null]],
    'stages' => [['draftId'=>'draft_stage','detailDraftId'=>'draft_detail','title'=>'Печать','description'=>'Этап','catalogDraftIds'=>['draft_calculator']]],
    'groups' => [],
];
$stored = ['status'=>'ok','found'=>true,'draftRevision'=>3,'draft'=>$draft,'decisions'=>[],'replacements'=>[]];
$bundle = ['contentHash'=>$currentHash,'componentHashes'=>['logic'=>str_repeat('d',64)],'documents'=>['logic'=>['workingPresetId'=>20001]]];
$healthyLogicSnapshot = [
    'graph'=>['detailIds'=>[401],'stageIds'=>[501],'settingsIds'=>[201]],
    'runtimePayload'=>[
        'preset'=>['properties'=>['CALC_DETAILS'=>['VALUE'=>[401]]]],
        'elementsStore'=>[
            'CALC_DETAILS'=>[401],
            'CALC_STAGES'=>[['id'=>501]],
            'CALC_SETTINGS'=>['VALUE'=>[201]],
        ],
    ],
];
$latestReceipt = ['presetId'=>16488,'versionId'=>$versionId,'manifestHash'=>str_repeat('e',64),'appliedAt'=>'2026-08-30T12:00:00Z',
    'created'=>[
        'draft_folder_calculator'=>['kind'=>'directory','id'=>101],
        'draft_calculator'=>['kind'=>'calculator','id'=>201],
        'draft_detail'=>['kind'=>'detail','id'=>401],
        'draft_stage'=>['kind'=>'stage','id'=>501],
        'draft_material'=>['kind'=>'material','id'=>301],
        'draft_material_variant'=>['kind'=>'materialVariant','id'=>302],
        'draft_operation'=>['kind'=>'operation','id'=>303],
        'draft_operation_variant'=>['kind'=>'operationVariant','id'=>304],
        'draft_equipment'=>['kind'=>'equipment','id'=>305],
        'draft_custom_field'=>['kind'=>'customField','id'=>306],
    ], 'reused'=>[], 'replaced'=>['draft_real_calculator'=>['kind'=>'calculator','id'=>202]]];
$states = [
    101=>['name'=>'Старая папка','description'=>'','parentId'=>0,'properties'=>[]],
    201=>['code'=>'','name'=>'Расчёт площади','description'=>'CALC_SETTINGS','sectionId'=>0,'properties'=>[]],
    202=>['code'=>'real','name'=>'Существующий калькулятор','description'=>'Не изменять','sectionId'=>999,'properties'=>[]],
    301=>['code'=>'ai_pilot_material_'.substr(hash('sha256','draft_material'),0,16),'name'=>'Баннер','description'=>'Материал','sectionId'=>0,'properties'=>[]],
    302=>['code'=>'ai_pilot_materialvariant_'.substr(hash('sha256','draft_material_variant'),0,16),'name'=>'Баннер 440','description'=>'Вариант','sectionId'=>0,'properties'=>['CML2_LINK'=>0]],
    303=>['code'=>'ai_pilot_operation_'.substr(hash('sha256','draft_operation'),0,16),'name'=>'Печать','description'=>'Операция','sectionId'=>0,'properties'=>[]],
    304=>['code'=>'ai_pilot_operationvariant_'.substr(hash('sha256','draft_operation_variant'),0,16),'name'=>'Печать 720 dpi','description'=>'Вид операции','sectionId'=>0,'properties'=>['CML2_LINK'=>303]],
    305=>['code'=>'ai_pilot_equipment_'.substr(hash('sha256','draft_equipment'),0,16),'name'=>'Плоттер','description'=>'Оборудование','sectionId'=>0,'properties'=>[]],
    306=>['code'=>'ai_pilot_customfield_'.substr(hash('sha256','draft_custom_field'),0,16),'name'=>'Комментарий','description'=>'Дополнительное поле','sectionId'=>0,'properties'=>[]],
    401=>['code'=>'','name'=>'Изделие','description'=>'Корневая деталь','sectionId'=>0,'properties'=>['CALC_STAGES'=>[]]],
    501=>['code'=>'','name'=>'Печать','description'=>'Этап','sectionId'=>0,'properties'=>[]],
    601=>['code'=>'needs_cut','name'=>'Нужна резка','description'=>'Флаг','sectionId'=>0,'properties'=>[]],
    20001=>['code'=>'working','name'=>'Рабочий граф','description'=>'','sectionId'=>0,'properties'=>['CALC_DETAILS'=>[]]],
];
$brokenStates = $states;
$brokenBundle = $bundle;
$options = [];
$applied = [];
$service = new AiLogicPilotRepairService([
    'assert_admin'=>static fn()=>null,
    'bundle'=>static function(array $_context) use (&$bundle): array { return $bundle; },
    'draft'=>static fn(array $_context): array=>$stored,
    'receipts'=>static fn(array $_context): array=>[
        array_merge($latestReceipt,['appliedAt'=>'2026-08-29T12:00:00Z','created'=>array_merge($latestReceipt['created'],['draft_stage'=>['kind'=>'stage','id'=>499]])]),
        $latestReceipt,
    ],
    'find_global'=>static fn(int $_presetId,string $_code): int=>601,
    'read_state'=>static function(array $spec) use (&$states): ?array { return $states[(int)$spec['id']]??null; },
    'apply_operations'=>static function(array $operations,int $workingPresetId) use (&$applied,&$states,&$bundle,$healthyLogicSnapshot): void {
        $applied=$operations;
        if($workingPresetId!==20001)throw new RuntimeException('wrong graph');
        foreach($operations as $operation){
            $id=(int)$operation['spec']['id'];
            if($operation['type']==='metadata')foreach($operation['fields'] as $field=>$value)$states[$id][$field]=$value;
            if($operation['type']==='section')$states[$id]['sectionId']=(int)$operation['sectionId'];
            if($operation['type']==='section_parent')$states[$id]['parentId']=(int)$operation['parentId'];
            if($operation['type']==='variant_parent')$states[$id]['properties']['CML2_LINK']=(int)$operation['parentId'];
            if($operation['type']==='properties')foreach($operation['properties'] as $code=>$value)$states[$id]['properties'][$code]=$value;
            if($operation['type']==='append_property'){
                $code=(string)$operation['property'];
                $states[$id]['properties'][$code]=array_values(array_unique(array_merge((array)($states[$id]['properties'][$code]??[]),$operation['values'])));
            }
        }
        $bundle['documents']['logic']=array_merge($bundle['documents']['logic'],$healthyLogicSnapshot);
    },
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
$inventoryByDraftId=array_column($inspection['entities'],null,'draftId');
$assert(($inventoryByDraftId['draft_material']['id']??0)===301
    && ($inventoryByDraftId['draft_material']['name']??'')==='Баннер'
    && ($inventoryByDraftId['draft_material']['description']??'')==='Материал'
    && ($inventoryByDraftId['draft_material']['exists']??false)===true,
    'readback inventory must expose the actual created material metadata');
$assert(($inventoryByDraftId['draft_material_variant']['path']??[])===['Баннер','Баннер 440']
    && ($inventoryByDraftId['draft_material_variant']['source']??'')==='created',
    'readback inventory must expose variant hierarchy and creation source');
$assert(($inventoryByDraftId['draft_real_calculator']['id']??0)===202
    && ($inventoryByDraftId['draft_real_calculator']['name']??'')==='Существующий калькулятор'
    && ($inventoryByDraftId['draft_real_calculator']['source']??'')==='replaced',
    'readback inventory must distinguish a real Bitrix replacement from created entities');
$inventoryKinds=array_values(array_unique(array_column($inspection['entities'],'kind')));
foreach(['material','materialVariant','operation','operationVariant','equipment','customField','calculator','detail','stage','global','directory'] as $kind){
    $assert(in_array($kind,$inventoryKinds,true),'readback inventory must expose '.$kind);
}
$types=array_values(array_unique(array_column($inspection['operations'],'type')));
$assert(in_array('metadata',$types,true)&&in_array('section',$types,true)&&in_array('properties',$types,true)&&in_array('append_property',$types,true),'repair plan must cover codes, folders and graph links');
$assert(in_array('variant_parent',$types,true),'repair plan must use the authoritative SKU parent operation for variants');
$assert(count(array_filter($inspection['operations'],static fn(array $op):bool=>in_array($op['type'],['create','delete','activate'],true)))===0,'repair must never create, delete or activate');
$assert(count(array_filter($inspection['operations'],static fn(array $op):bool=>(string)($op['spec']['draftId']??'')==='draft_real_calculator'))===0,'repair must not mutate a real Bitrix replacement');
$first=$service->repair($request+['explicitConfirm'=>true]);
$postInspection=$service->inspect($request);
$assert($postInspection['healthy']===true&&($postInspection['needsSnapshotRefresh']??true)===false,
    'healthy graph fixture with raw-list and VALUE-shape runtime payload must pass strict parity');

$runtimeEmptyBundle=$bundle;
$runtimeEmptyBundle['documents']['logic']['runtimePayload']=[];
$runtimeEmptyService=new AiLogicPilotRepairService([
    'assert_admin'=>static fn()=>null,
    'bundle'=>static fn(array $_):array=>$runtimeEmptyBundle,
    'draft'=>static fn(array $_):array=>$stored,
    'receipts'=>static fn(array $_):array=>[$latestReceipt],
    'find_global'=>static fn(int $_presetId,string $_code):int=>601,
    'read_state'=>static fn(array $spec):?array=>$states[(int)$spec['id']]??null,
]);
$runtimeEmptyInspection=$runtimeEmptyService->inspect($request);
$runtimeIssueCodes=array_column($runtimeEmptyInspection['issues'],'code');
$assert(($runtimeEmptyInspection['needsSnapshotRefresh']??false)===true
    && in_array('VERSION_RUNTIME_SNAPSHOT_MISMATCH',$runtimeIssueCodes,true)
    && !in_array('VERSION_GRAPH_SNAPSHOT_MISMATCH',$runtimeIssueCodes,true),
    'graph healthy/runtime empty must require a version runtime snapshot refresh');

$second=$service->repair($request+['explicitConfirm'=>true]);
$assert($first['status']==='ok'&&$first['idempotentReplay']===false&&$second['idempotentReplay']===true&&$applied!==[],
    'repair must be explicit and idempotent: '.json_encode(['first'=>$first,'inspection'=>$postInspection,'second'=>$second],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

$forbidden=false;
try{$service->inspect(array_merge($request,['presetId'=>12740]));}catch(RuntimeException $error){$forbidden=$error->getCode()===403;}
$assert($forbidden,'preset 12740 must be hard denied');

$rolledBack=[];
$failing=new AiLogicPilotRepairService([
    'assert_admin'=>static fn()=>null,'bundle'=>static fn(array $_):array=>$brokenBundle,'draft'=>static fn(array $_):array=>$stored,
    'receipts'=>static fn(array $_):array=>[$latestReceipt],'find_global'=>static fn(int $_,string $__):int=>601,
    'read_state'=>static fn(array $spec):?array=>$brokenStates[(int)$spec['id']]??null,
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
