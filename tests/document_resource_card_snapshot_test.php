<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/resource_card_fixture.php';
require_once dirname(__DIR__).'/lib/Documents/BitrixResourceCardSnapshot.php';
use Prospektweb\Calc\Documents\BitrixResourceCardSnapshot;
$checks=0;
$check=static function(bool $ok,string $label) use(&$checks):void {++$checks;if(!$ok)throw new RuntimeException($label);};
$rejects=static function(callable $call,int $code=409) use($check):void {try{$call();}catch(Throwable $e){$check($e->getCode()===$code,$e->getMessage());return;}throw new RuntimeException('Expected rejection');};
foreach([1,2] as $version) {
    [$pdo,$db]=resourceCardFixture($version);$snapshot=new BitrixResourceCardSnapshot($db,'bitrix:test');
    $binding=['provider'=>'bitrix:test','catalog'=>'CALC_MATERIALS_VARIANTS','key'=>'101'];
    $rejects(fn()=>$snapshot->capture($binding),0);
    $capture=static function(array $b,bool $lock=false) use($db,$snapshot):array {$db->begin(!$lock);try{return $snapshot->capture($b,$lock);}finally{$db->rollback();}};
    $before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();$s=$capture($binding);
    $check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,'Snapshot never writes caches or repairs data');
    $check($s['parentId']===100 && $s['variantIds']===[101,102],'Parent and every sibling including inactive; no foreign variant');
    $check(array_keys($s['raw']['elements'])===[100,101,102],'Bounded exact members');
    $check($s['data']['parent']['previewText']==='Описание &amp;' && $s['raw']['elements'][100]['PREVIEW_TEXT']===' <p>Описание &amp;</p> ','Same UI preview with exact protected HTML baseline');
    $check($s['data']['parent']['detailText']==='<p>Подробно &amp;</p>','HTML is not double decoded');
    $check($s['data']['parent']['parameters']===[['code'=>'rate','value'=>'12.50','title'=>'Цена &amp;','description'=>'Описание'],['code'=>'speed','value'=>'0','title'=>'Скорость','description'=>'']],'Legacy parameter layout and order');
    $check($s['data']['parent']['sourceLinks']===[['url'=>'https://example.test/100','title'=>'Источник','description'=>'Точно &amp;']],'Source layout retained');
    $check($s['data']['parent']['supplierIds']===[400,401] && count($s['data']['catalogOptions']['suppliers'])===2,'Supplier links including inactive preserved');
    $check($s['data']['catalogOptions']['suppliers'][0]['entityKey']==='supplier-400','Stable supplier key read without cache');
    $check(array_column($s['data']['parent']['sectionPath'],'name')===['Материалы','Бумага'],'Real section path');
    $check($s['data']['parent']['catalog']['basePrice']==='18.75' && count($s['raw']['prices'])===6,'Other price types remain in exact protected snapshot');
    $check(count($s['data']['catalogOptions']['vatRates'])===2 && count($s['raw']['vat'])===3,'Active choices but archived VAT still captured');
    $check($capture($binding,true)===$s,'Locked write snapshot matches read snapshot exactly');
    foreach([
        ['provider'=>'bitrix:foreign']+array_diff_key($binding,['provider'=>true]),
        array_replace($binding,['key'=>'101 OR 1=1']),array_replace($binding,['key'=>101]),
        array_replace($binding,['catalog'=>'CALC_SETTINGS']),$binding+['iblockId'=>42],
    ] as $invalid) $rejects(fn()=>$capture($invalid),0);
    $rejects(fn()=>$capture(array_replace($binding,['key'=>'300'])));
    $equipment=$capture(['provider'=>'bitrix:test','catalog'=>'CALC_EQUIPMENT','key'=>'300']);
    $check($equipment['type']==='equipment' && $equipment['data']['variants']===[],'Single equipment card');
    $operation=$capture(['provider'=>'bitrix:test','catalog'=>'CALC_OPERATIONS','key'=>'200']);
    $check($operation['type']==='operation' && $operation['variantIds']===[201,202],'Operation parent selects its full card');
    $db->execute("UPDATE b_iblock_element SET NAME='External winner' WHERE ID=102");
    $check($capture($binding)['fingerprint']!==$s['fingerprint'],'Sibling metadata change invalidates CAS');
    $db->execute("UPDATE b_iblock_element SET NAME='Бумага 250' WHERE ID=102");
    $check($capture($binding)['fingerprint']===$s['fingerprint'],'Restored exact bytes restore fingerprint');
    if($version===2) {
        $db->execute("UPDATE b_iblock_element_prop_s42 SET PROPERTY_4201='another broken cache' WHERE IBLOCK_ELEMENT_ID=101");
        $check($capture($binding)['fingerprint']===$s['fingerprint'],'Serialized multiple cache is not business authority');
        $db->execute("UPDATE b_iblock_element_prop_s42 SET PROPERTY_4203='changed unknown field' WHERE IBLOCK_ELEMENT_ID=101");
    } else $db->execute("UPDATE b_iblock_element_property SET VALUE='changed unknown field' WHERE IBLOCK_ELEMENT_ID=101 AND IBLOCK_PROPERTY_ID=4203");
    $check($capture($binding)['fingerprint']!==$s['fingerprint'],'Unowned property change is part of CAS');
    $db->execute("INSERT INTO b_option VALUES ('prospektweb.calc','iblock_calc_materials','41','s1')");
    $rejects(fn()=>$capture($binding));
    $db->execute("DELETE FROM b_option WHERE SITE_ID='s1'");
    $db->execute("UPDATE b_iblock_property SET LINK_IBLOCK_ID=43 WHERE ID=4205");
    $rejects(fn()=>$capture($binding));
    $db->execute('UPDATE b_iblock_property SET LINK_IBLOCK_ID=41 WHERE ID=4205');
    $db->execute("UPDATE b_catalog_iblock SET PRODUCT_IBLOCK_ID=43 WHERE IBLOCK_ID=42");
    $rejects(fn()=>$capture($binding));
    $db->execute('UPDATE b_catalog_iblock SET PRODUCT_IBLOCK_ID=41 WHERE IBLOCK_ID=42');
    $db->execute('UPDATE b_iblock_section SET IBLOCK_SECTION_ID=92 WHERE ID=91');
    $rejects(fn()=>$capture($binding));
    $db->execute('UPDATE b_iblock_section SET IBLOCK_SECTION_ID=NULL WHERE ID=91');
    if ($version===2) $db->execute("UPDATE b_iblock_element_prop_s44 SET PROPERTY_4404='999' WHERE IBLOCK_ELEMENT_ID IN (201,202)");
    else $db->execute("UPDATE b_iblock_element_property SET VALUE='999' WHERE IBLOCK_PROPERTY_ID=4404");
    $empty=$capture(['provider'=>'bitrix:test','catalog'=>'CALC_OPERATIONS','key'=>'200']);
    $check($empty['variantIds']===[] && count($empty['raw']['properties'][44]['schema'])===4,'Empty variant group still captures creation schema and table authority');
}
[$pdo,$inner]=resourceCardFixture(2);
$typed=new class($inner) implements \Prospektweb\Calc\Documents\SqlConnection {
    public function __construct(private \Prospektweb\Calc\Documents\SqlConnection $inner){}
    public function dialect(): string{return $this->inner->dialect();}
    public function inTransaction(): bool{return $this->inner->inTransaction();}
    public function begin(bool $readSnapshot=false): void{$this->inner->begin($readSnapshot);}
    public function commit(): void{$this->inner->commit();}
    public function rollback(): void{$this->inner->rollback();}
    public function execute(string $sql,array $parameters=[]): void{$this->inner->execute($sql,$parameters);}
    public function rows(string $sql,array $parameters=[]): array {
        $rows=$this->inner->rows($sql,$parameters);
        foreach($rows as &$row) if(isset($row['TIMESTAMP_X'])) $row['TIMESTAMP_X']=new DateTimeImmutable($row['TIMESTAMP_X']);
        unset($row);return $rows;
    }
};
$reader=new BitrixResourceCardSnapshot($typed,'bitrix:test');$b=['provider'=>'bitrix:test','catalog'=>'CALC_EQUIPMENT','key'=>'300'];
$typed->begin(true);$first=$reader->capture($b);$typed->rollback();
$typed->begin();$second=$reader->capture($b,true);$typed->rollback();
$check($first===$second && $first['raw']['elements'][300]['TIMESTAMP_X']==='2026-09-09 00:00:00.000000','SQL date values canonicalized; fresh DateTime objects do not cause false conflicts');
$inner->execute("UPDATE b_iblock_element SET TIMESTAMP_X='2026-09-09 00:00:01' WHERE ID=300");
$typed->begin(true);$third=$reader->capture($b);$typed->rollback();
$check($third['fingerprint']!==$first['fingerprint'],'Actual timestamp change remains part of CAS');
echo "PASS $checks resource card snapshot assertions\n";
