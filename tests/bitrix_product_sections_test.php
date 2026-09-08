<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/BitrixProductSections.php';
use Prospektweb\Calc\Documents\BitrixProductSections;
$checks=0;
function check(bool $ok,string $message): void {global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function rejected(callable $fn): void {try{$fn();}catch(InvalidArgumentException|RuntimeException $e){check(true,$e->getMessage());return;}throw new LogicException('Expected rejection');}
final class Cursor {private array $rows;public function __construct(array $rows){$this->rows=$rows;}public function Fetch(){return array_shift($this->rows);}}
final class CIBlockSection {
    public static array $rows=[];
    public static function GetList($sort,$filter,$count,$select) {
        check($filter===['IBLOCK_ID'=>14,'CHECK_PERMISSIONS'=>'Y'],'Section authority and permissions');
        return new Cursor(self::$rows);
    }
}
final class CIBlockElement {
    public static array $batches=[];public static array $groups=[];public static bool $foreign=false;
    public static function GetList($sort,$filter,$group,$nav,$select) {
        check($filter['IBLOCK_ID']===14 && $filter['CHECK_PERMISSIONS']==='Y' && $filter['ACTIVE']==='Y' && $filter['ACTIVE_DATE']==='Y','Product authority/activity/permissions');
        self::$batches[]=$filter['ID'];$rows=[];
        foreach($filter['ID'] as $id) if($id!==2)$rows[]=['ID'=>(string)$id,'IBLOCK_ID'=>self::$foreign?'15':'14','NAME'=>'Product '.$id];
        return new Cursor($rows);
    }
    public static function GetElementGroups($ids,$only,$select) {
        check(is_array($ids) && count($ids)<=200 && $only===false,'Membership is batched and preserves section-property links');
        check(in_array('IBLOCK_ELEMENT_ID',$select,true),'Exact product discriminator requested');self::$groups[]=$ids;$rows=[];
        foreach($ids as $id)foreach([10,10,11] as $section)$rows[]=['ID'=>(string)$section,'IBLOCK_ID'=>'14','IBLOCK_ELEMENT_ID'=>(string)$id];
        $rows[]=['ID'=>'10','IBLOCK_ID'=>'15','IBLOCK_ELEMENT_ID'=>'1'];
        $rows[]=['ID'=>'10','IBLOCK_ID'=>'14','IBLOCK_ELEMENT_ID'=>'999'];
        $rows[]=['ID'=>'999','IBLOCK_ID'=>'14','IBLOCK_ELEMENT_ID'=>'1'];
        return new Cursor($rows);
    }
}
CIBlockSection::$rows=[['ID'=>'10','IBLOCK_ID'=>'14','IBLOCK_SECTION_ID'=>null,'NAME'=>'Root','DEPTH_LEVEL'=>'1'],['ID'=>'11','IBLOCK_ID'=>'14','IBLOCK_SECTION_ID'=>'10','NAME'=>'Child','DEPTH_LEVEL'=>'2']];
$service=new BitrixProductSections();$keys=array_map('strval',range(1,401));$result=$service->load('bitrix:test',14,$keys);
check(array_map('count',CIBlockElement::$batches)===[200,200,1],'Bounded queries, not N+1');
check(count(CIBlockElement::$groups)===3,'One membership query per visible batch');
check(count($result['products'])===400 && !in_array(2,array_column($result['products'],'id'),true),'Invisible products remain excluded');
check($result['products'][0]['section_ids']===[10,11],'Memberships deduplicated and foreign/unknown rows excluded');
check($result['sections'][1]['depth']===1 && $result['productsCatalog']==='14' && $result['provider']==='bitrix:test','Response identity and depth');
foreach([null,new stdClass(),[1],['01'],['1bad'],['1','1'],[['1']],['a'=>'1'],array_fill(0,10001,'1')] as $bad)rejected(fn()=>$service->load('bitrix:test',14,$bad));
CIBlockElement::$foreign=true;rejected(fn()=>$service->load('bitrix:test',14,['1']));CIBlockElement::$foreign=false;
$rows=CIBlockSection::$rows;CIBlockSection::$rows[]=$rows[0];rejected(fn()=>$service->load('bitrix:test',14,[]));
CIBlockSection::$rows=$rows;CIBlockSection::$rows[0]['IBLOCK_ID']='15';rejected(fn()=>$service->load('bitrix:test',14,[]));
CIBlockSection::$rows=$rows;CIBlockElement::$batches=[];$empty=$service->load('bitrix:test',14,[]);
check($empty['products']===[] && CIBlockElement::$batches===[],'Empty bound products never enumerate whole catalog');
$endpoint=file_get_contents(dirname(__DIR__).'/tools/documents.php');
check(strpos($endpoint,'ADMIN_REQUIRED')<strpos($endpoint,"'catalogProductSections'"),'Admin gate precedes catalog command');
check(strpos($endpoint,'INVALID_SESSION')<strpos($endpoint,"'catalogProductSections'"),'CSRF gate precedes catalog command');
check(strpos($endpoint,"['action', 'ids']")!==false && strpos($endpoint,'getProductIblockId(), $request->command->ids')!==false,'No caller-owned catalog authority');
echo "PASS $checks read-only Bitrix product section checks\n";
