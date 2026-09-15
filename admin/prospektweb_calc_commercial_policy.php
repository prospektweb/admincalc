<?php
define('NO_AGENT_CHECK',true);
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_before.php';
if(!$USER->IsAdmin()){$APPLICATION->AuthForm('Доступ запрещён');}
foreach(['prospektweb.calc','prospektweb.orderterms','catalog']as$module)if(!\Bitrix\Main\Loader::includeModule($module))die('Модуль недоступен');
require_once $_SERVER['DOCUMENT_ROOT'].getLocalPath('modules/prospektweb.calc').'/lib/Documents/CommercialPolicyDiagnostics.php';
use Prospektweb\Calc\Documents\CommercialPolicyDiagnostics;
use Prospektweb\Calc\Documents\CommercialPolicyService;
use Prospektweb\Calc\Documents\CommercialPolicyQuote;
$result=null;$error=null;$input=$_POST['request']??json_encode(CommercialPolicyDiagnostics::fixture(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!check_bitrix_sessid())throw new \RuntimeException('CSRF_DENIED',403);
  if(strlen($input)>2000000)throw new \InvalidArgumentException('REQUEST_TOO_LARGE');
  $request=json_decode($input,false,64,JSON_THROW_ON_ERROR);if(!$request instanceof \stdClass)throw new \InvalidArgumentException('JSON_OBJECT_REQUIRED');
  $packet=CommercialPolicyDiagnostics::preview($request);$result=$packet->preview;
  if(($_POST['action']??'preview')==='publish'){
   $site=defined('SITE_ID')?SITE_ID:'s1';
   (new \Prospektweb\OrderTerms\CalendarStore())->append($site,$packet->calendar,null,(int)$USER->GetID(),gmdate('Y-m-d\TH:i:s\Z'));
   $hash=hash('sha256',CommercialPolicyQuote::canonicalJson($packet));
   $record=CommercialPolicyService::storeQa('spm03_qa_'.substr($hash,0,24),$packet,$hash,(int)$USER->GetID());
   $readback=CommercialPolicyService::readQa($record['id']);
   if($readback!==$record)throw new \RuntimeException('QA_READBACK_MISMATCH');
   $result['publication']=['id'=>$record['id'],'bodyHash'=>$record['bodyHash'],'enrollmentEnabled'=>false];
  }
 }catch(\Throwable $e){$error=$e->getMessage();}
}
$APPLICATION->SetTitle('SPM-03 — проверка политик сроков и цен');
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
?>
<style>
#spm03 form{display:grid;grid-template-columns:minmax(320px,1fr) minmax(320px,1fr);gap:14px}#spm03 textarea{box-sizing:border-box;width:100%;height:55vh;font:12px/1.35 monospace}#spm03 .actions{display:flex;gap:8px;margin:8px 0}#spm03 button{padding:7px 12px;border:1px solid #167ca5;border-radius:4px;background:#edf2f5;color:#15435a;cursor:pointer}#spm03 button:hover,#spm03 button:focus-visible{background:#167ca5;color:white}#spm03 button:focus-visible{outline:2px solid #036;outline-offset:2px}#spm03 table{border-collapse:collapse;width:100%}#spm03 td,#spm03 th{padding:6px;border:1px solid #ccd3da;text-align:left}#spm03 pre{white-space:pre-wrap;max-height:45vh;overflow:auto;font-size:11px}#spm03 .error{color:#a31d22}@media(max-width:900px){#spm03 form{grid-template-columns:1fr}}
</style>
<div id="spm03"><p><b>Изолированная QA-проверка. Рабочие политики выключены.</b> Часы и проценты примера не являются настройками товаров.</p>
<?php if($error!==null):?><p role="alert" class="error">Не удалось выполнить действие: <?=htmlspecialcharsbx($error)?></p><?php endif?>
<form method="post"><?=bitrix_sessid_post()?><div><label for="request">Версионированный запрос (JSON)</label><textarea id="request" name="request" spellcheck="false"><?=htmlspecialcharsbx($input)?></textarea><div class="actions"><button name="action" value="preview">Проверить и рассчитать</button><button name="action" value="publish">Сохранить QA-публикацию</button></div></div><div aria-live="polite">
<?php if($result):?><table><tr><th>Тип</th><th>Готовность</th><th>Тираж</th><th>Позиция</th></tr><?php foreach($result['variants']as$v):?><tr><td><?=htmlspecialcharsbx($v['label'])?></td><td><?=htmlspecialcharsbx($v['calendarResult']['slot']['local']??(is_string($v['reason']??null)?$v['reason']:'Недоступно'))?></td><td><?=htmlspecialcharsbx($v['prices'][0]['roundedPerRun']??'—')?></td><td><?=htmlspecialcharsbx($v['prices'][0]['positionTotal']??'—')?></td></tr><?php endforeach?></table><p>Результат предварительный. Срок не изменяет техническое время.</p><details open><summary>Результат и доказательства исполнения</summary><pre id="result"><?=htmlspecialcharsbx(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT))?></pre></details><?php else:?><p>Ожидается проверка.</p><?php endif?>
</div></form></div>
<?php require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';
