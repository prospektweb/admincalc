<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/BitrixCatalogPropertySnapshot.php';
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
use Prospektweb\Calc\Documents\{BitrixCatalogPropertySnapshot, PdoConnection, SqlConnection};
set_error_handler(static function(int $severity,string $message,string $file,int $line):void { if (!(error_reporting() & $severity)) return; throw new ErrorException($message,0,$severity,$file,$line); });
$checks = 0;
function check(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function reject(callable $work, string $message): void { try { $work(); } catch (Throwable $error) { check(true, $message); return; } throw new RuntimeException('Expected rejection: ' . $message); }

final class ObservedInputDb implements SqlConnection
{
    public array $queries = []; public bool $mysql = false; public string $engine = 'InnoDB';
    public function __construct(public PdoConnection $inner) {}
    public function dialect(): string { return $this->mysql ? 'mysql' : 'sqlite'; }
    public function inTransaction(): bool { return $this->inner->inTransaction(); }
    public function begin(bool $readSnapshot = false): void { $this->inner->begin($readSnapshot); }
    public function commit(): void { $this->inner->commit(); }
    public function rollback(): void { $this->inner->rollback(); }
    public function execute(string $sql, array $parameters = []): void { throw new RuntimeException('Snapshot must never execute a write.'); }
    public function rows(string $sql, array $parameters = []): array
    {
        $this->queries[] = [$sql, $parameters];
        if (str_contains($sql, 'information_schema.TABLES')) return array_map(fn(string $table): array => ['TABLE_NAME' => $table, 'ENGINE' => $this->engine], $parameters);
        return $this->inner->rows(str_replace(' FOR UPDATE', '', $sql), $parameters);
    }
}
function fixture(int $productVersion = 1, int $offerVersion = 2, int $count = 2): array
{
    $db = new PdoConnection(new PDO('sqlite::memory:'));
    $db->execute('CREATE TABLE b_iblock (ID INTEGER PRIMARY KEY, ACTIVE TEXT, VERSION INTEGER)');
    $db->execute('INSERT INTO b_iblock VALUES (14,?,?),(15,?,?)', ['Y',$productVersion,'Y',$offerVersion]);
    $db->execute('CREATE TABLE b_iblock_element (ID INTEGER PRIMARY KEY, IBLOCK_ID INTEGER, NAME TEXT, ACTIVE TEXT, ACTIVE_FROM TEXT, ACTIVE_TO TEXT)');
    $db->execute('CREATE TABLE b_iblock_property (ID INTEGER PRIMARY KEY, IBLOCK_ID INTEGER, CODE TEXT, ACTIVE TEXT, PROPERTY_TYPE TEXT, USER_TYPE TEXT, MULTIPLE TEXT, VERSION INTEGER, USER_TYPE_SETTINGS TEXT)');
    $db->execute('CREATE TABLE b_iblock_property_enum (ID INTEGER PRIMARY KEY, PROPERTY_ID INTEGER, XML_ID TEXT, VALUE TEXT, SORT INTEGER)');
    $db->execute('CREATE TABLE b_hlblock_entity (ID INTEGER PRIMARY KEY, NAME TEXT, TABLE_NAME TEXT)');
    $db->execute('CREATE TABLE b_user_field (ID INTEGER PRIMARY KEY, ENTITY_ID TEXT, FIELD_NAME TEXT, USER_TYPE_ID TEXT, MULTIPLE TEXT)');
    $db->execute('CREATE TABLE qa_colors (ID INTEGER PRIMARY KEY, UF_XML_ID TEXT, UF_NAME TEXT, UF_SORT INTEGER)');
    $db->execute('INSERT INTO b_hlblock_entity VALUES (7,?,?)', ['Colors','qa_colors']);
    foreach (['UF_XML_ID'=>'string','UF_NAME'=>'string','UF_SORT'=>'integer'] as $name=>$type) $db->execute('INSERT INTO b_user_field (ENTITY_ID,FIELD_NAME,USER_TYPE_ID,MULTIPLE) VALUES (?,?,?,?)',['HLBLOCK_7',$name,$type,'N']);
    $db->execute('INSERT INTO qa_colors VALUES (1,?,?,20),(2,?,?,10)', ['blue','Синий','red','Красный']);
    $defs = [11=>[14,'S','','N'],12=>[14,'L','','Y'],13=>[14,'L','','N'],21=>[15,'L','','N'],22=>[15,'S','directory','Y'],23=>[15,'N','','N'],24=>[15,'L','','Y'],25=>[15,'S','','N']];
    $sources=[];
    foreach($defs as $id=>[$iblock,$type,$user,$multiple]) {
        $db->execute('INSERT INTO b_iblock_property VALUES (?,?,?,?,?,?,?,?,?)',[$id,$iblock,'PROP_'.$id,'Y',$type,$user,$multiple,$iblock===14?$productVersion:$offerVersion,$user?serialize(['TABLE_NAME'=>'qa_colors']):null]);
        $sources[]=['scope'=>$iblock===14?'product':'selected_offer','iblock_id'=>$iblock,'property_id'=>$id,'property_code'=>'PROP_'.$id];
    }
    foreach([[101,12,'offset','Офсет',20],[102,12,'digital','Цифра',10],[103,13,'90x50','90 × 50',10],[104,21,'1000','1000',10],[105,24,'front','Лицевая',20],[106,24,'back','Оборотная',10]] as $row) {
        $db->execute('INSERT INTO b_iblock_property_enum VALUES (?,?,?,?,?)',$row);
    }
    foreach(['b_iblock_element_property','b_iblock_element_prop_m14','b_iblock_element_prop_m15'] as $table) $db->execute('CREATE TABLE '.$table.' (ID INTEGER PRIMARY KEY, IBLOCK_ELEMENT_ID INTEGER, IBLOCK_PROPERTY_ID INTEGER, VALUE TEXT, VALUE_ENUM INTEGER, DESCRIPTION TEXT)');
    $db->execute('CREATE TABLE b_iblock_element_prop_s14 (IBLOCK_ELEMENT_ID INTEGER PRIMARY KEY, PROPERTY_11 TEXT, PROPERTY_13 TEXT)');
    $db->execute('CREATE TABLE b_iblock_element_prop_s15 (IBLOCK_ELEMENT_ID INTEGER PRIMARY KEY, PROPERTY_21 TEXT, PROPERTY_23 TEXT, PROPERTY_25 TEXT)');
    $productIds=[];$offerIds=[];
    for($index=0;$index<$count;$index++) {
        foreach([14=>1001+$index,15=>2001+$index] as $iblock=>$element) {
            $db->execute('INSERT INTO b_iblock_element VALUES (?,?,?,?,NULL,NULL)',[$element,$iblock,'Элемент '.$element,'Y']);
            if($iblock===14)$productIds[]=$element;else $offerIds[]=$element;
            $values=$iblock===14?[11=>['Y'],12=>[101,102],13=>[103]]:[21=>[104],22=>['blue','red'],23=>['0'],24=>[105,106],25=>['N']];
            $version=$iblock===14?$productVersion:$offerVersion;
            if($version===2)$db->execute('INSERT INTO b_iblock_element_prop_s'.$iblock.' VALUES (?,?,?'.($iblock===15?',?':'').')', $iblock===14?[$element,'Y','103']:[$element,'104','0','N']);
            foreach($values as $prop=>$rows) {
                if($version===2 && $defs[$prop][3]==='N')continue;
                foreach($rows as $value)$db->execute('INSERT INTO '.($version===2?'b_iblock_element_prop_m'.$iblock:'b_iblock_element_property').' (IBLOCK_ELEMENT_ID,IBLOCK_PROPERTY_ID,VALUE,VALUE_ENUM,DESCRIPTION) VALUES (?,?,?,?,?)',[$element,$prop,(string)$value,$defs[$prop][1]==='L'?$value:null,'raw']);
            }
        }
    }
    $observed=new ObservedInputDb($db); $reader=new BitrixCatalogPropertySnapshot($observed);
    return compact('db','observed','reader','sources','productIds','offerIds');
}
function capture(array $f, bool $lock = false): array { return $f['reader']->capture(14,15,$f['productIds'],$f['offerIds'],$f['sources'],$lock); }

$projection=null;$queryCounts=[];
foreach([[1,1],[1,2],[2,1],[2,2]] as [$pv,$ov]) {
    $f=fixture($pv,$ov);$f['db']->begin(true);$snapshot=capture($f);$again=capture($f);
    check($snapshot==$again,'Repeat capture stable');
    $actual=[$snapshot['properties'],$snapshot['sourceAuthority'],$snapshot['elements']];
    if($projection===null)$projection=$actual; else check($actual===$projection,'V1/V2 preserve identical property semantics');
    check($snapshot['properties']['product'][1001][11]['values'][0]['value']==='Y','Scalar boolean');
    check(array_column($snapshot['properties']['product'][1001][12]['values'],'xml_id')===['digital','offset'],'Multiple list resolved and sorted by enum sort');
    check($snapshot['properties']['product'][1001][13]['values'][0]['xml_id']==='90x50','Exact dimension XML_ID');
    check($snapshot['properties']['selected_offer'][2001][21]['values'][0]['value']==='1000','Single enum value label');
    check(array_column($snapshot['properties']['selected_offer'][2001][22]['values'],'value')===['Красный','Синий'],'Directory values resolve exact XML_ID and order');
    check($snapshot['properties']['selected_offer'][2001][23]['values'][0]['value']==='0','Zero is not an empty source');
    check(array_column($snapshot['properties']['selected_offer'][2001][24]['values'],'xml_id')===['back','front'],'Multiple enum in V2 reads VALUE_ENUM');
    check($snapshot['sourceAuthority']['properties']['product'][14][12]['enum_xml_ids']===['digital','offset'],'Semantic authority shares same enum projection');
    check($f['db']->inTransaction(),'Reader retains outer transaction'); $f['db']->rollback();
}
foreach([1,100] as $count) {
    $f=fixture(1,2,$count);$f['db']->begin(true);$snapshot=capture($f);
    check(count($snapshot['properties']['selected_offer'])===$count,'Batch covers all offers');$queryCounts[]=count($f['observed']->queries);$f['db']->rollback();
}
check($queryCounts[0]===$queryCounts[1] && $queryCounts[0]<=12,'Bounded SQL roundtrips do not grow per element/property');
$f=fixture(); reject(fn()=>capture($f),'Outside transaction');
$f['db']->begin();$original=capture($f);
$f['sources'][]=$f['sources'][0]; check(capture($f)==$original,'Repeated exact source does not multiply queries or values');
$f['db']->execute('UPDATE b_iblock_element_property SET VALUE=? WHERE IBLOCK_PROPERTY_ID=11',['N']);$changed=capture($f);
check($original['authority']->fingerprint!==$changed['authority']->fingerprint && $changed['properties']['product'][1001][11]['values'][0]['value']==='N','No stale in-process property cache');
$f['db']->rollback();

foreach([
    'missing-property'=>['DELETE FROM b_iblock_property WHERE ID=11',[]],
    'renamed-property'=>['UPDATE b_iblock_property SET CODE=? WHERE ID=11',['RENAMED']],
    'inactive-property'=>['UPDATE b_iblock_property SET ACTIVE=? WHERE ID=11',['N']],
    'foreign-property'=>['UPDATE b_iblock_property SET IBLOCK_ID=15 WHERE ID=11',[]],
    'version-mismatch'=>['UPDATE b_iblock_property SET VERSION=2 WHERE ID=11',[]],
    'unsupported-storage'=>['UPDATE b_iblock SET VERSION=3 WHERE ID=14',[]],
    'inactive-element'=>['UPDATE b_iblock_element SET ACTIVE=? WHERE ID=1001',['N']],
    'future-element'=>['UPDATE b_iblock_element SET ACTIVE_FROM=? WHERE ID=1001',['2999-01-01 00:00:00']],
    'expired-element'=>['UPDATE b_iblock_element SET ACTIVE_TO=? WHERE ID=1001',['2000-01-01 00:00:00']],
    'foreign-element'=>['UPDATE b_iblock_element SET IBLOCK_ID=15 WHERE ID=1001',[]],
    'missing-element'=>['DELETE FROM b_iblock_element WHERE ID=1001',[]],
    'empty-enum'=>['DELETE FROM b_iblock_property_enum WHERE PROPERTY_ID=12',[]],
    'duplicate-xml'=>['UPDATE b_iblock_property_enum SET XML_ID=? WHERE ID=101',['DIGITAL']],
    'broken-enum'=>['UPDATE b_iblock_element_property SET VALUE_ENUM=999 WHERE IBLOCK_PROPERTY_ID=12',[]],
    'missing-enum-column'=>['UPDATE b_iblock_element_property SET VALUE_ENUM=NULL WHERE IBLOCK_PROPERTY_ID=12',[]],
    'missing-v2-single-row'=>['DELETE FROM b_iblock_element_prop_s15 WHERE IBLOCK_ELEMENT_ID=2001',[]],
    'directory-unknown-value'=>['UPDATE b_iblock_element_prop_m15 SET VALUE=? WHERE IBLOCK_PROPERTY_ID=22',['unknown']],
    'directory-case-drift'=>['UPDATE b_iblock_element_prop_m15 SET VALUE=? WHERE IBLOCK_PROPERTY_ID=22',['RED']],
    'directory-duplicate-xml'=>['UPDATE qa_colors SET UF_XML_ID=? WHERE ID=1',['RED']],
    'directory-registry-missing'=>['DELETE FROM b_hlblock_entity',[]],
    'directory-registry-duplicate'=>['INSERT INTO b_hlblock_entity VALUES (8,?,?)',['Other','qa_colors']],
    'directory-schema-multiple'=>['UPDATE b_user_field SET MULTIPLE=? WHERE FIELD_NAME=?',['Y','UF_XML_ID']],
    'directory-sort-type'=>['UPDATE b_user_field SET USER_TYPE_ID=? WHERE FIELD_NAME=?',['string','UF_SORT']],
    'directory-table-injection'=>['UPDATE b_iblock_property SET USER_TYPE_SETTINGS=? WHERE ID=22',[serialize(['TABLE_NAME'=>'qa_colors;DROP TABLE b_iblock'])]],
    'custom-callback'=>['UPDATE b_iblock_property SET USER_TYPE=? WHERE ID=11',['Arbitrary']],
    'single-with-two-values'=>['INSERT INTO b_iblock_element_property (IBLOCK_ELEMENT_ID,IBLOCK_PROPERTY_ID,VALUE) VALUES (1001,11,?)',['Y']],
] as $name=>[$sql,$args]) {
    $f=fixture();$f['db']->execute($sql,$args);$f['db']->begin();reject(fn()=>capture($f,true),$name);check($f['db']->inTransaction(),'Failure retains caller transaction: '.$name);$f['db']->rollback();
}
$f=fixture();$f['db']->execute('DELETE FROM b_iblock_element_property WHERE IBLOCK_PROPERTY_ID=11');$f['db']->begin(true);
$empty=capture($f);check($empty['properties']['product'][1001][11]['values']===[] && $empty['properties']['product'][1001][11]['active'],'Valid empty source retains exact schema');$f['db']->rollback();
foreach(['enum-name','enum-sort','raw-value','directory-name','schema','element-name'] as $change) {
    $f=fixture();$f['db']->begin();$before=capture($f);
    [$sql,$args]=match($change) {
        'enum-name'=>['UPDATE b_iblock_property_enum SET VALUE=? WHERE ID=103',['Другой размер']],
        'enum-sort'=>['UPDATE b_iblock_property_enum SET SORT=30 WHERE ID=103',[]],
        'raw-value'=>['UPDATE b_iblock_element_property SET DESCRIPTION=? WHERE IBLOCK_PROPERTY_ID=11',['changed']],
        'directory-name'=>['UPDATE qa_colors SET UF_NAME=? WHERE ID=1',['Другой синий']],
        'schema'=>['UPDATE b_iblock_property SET USER_TYPE_SETTINGS=? WHERE ID=11',[serialize(['changed'=>true])]],
        'element-name'=>['UPDATE b_iblock_element SET NAME=? WHERE ID=1001',['Другое имя']],
    };
    $f['db']->execute($sql,$args);$after=capture($f);
    check($before['authority']->fingerprint!==$after['authority']->fingerprint,'Fresh fingerprint includes '.$change);$f['db']->rollback();
}
foreach(['description','xml'] as $fallback) {
    $f=fixture();$f['db']->execute('DELETE FROM b_user_field WHERE FIELD_NAME IN (?,?)',['UF_NAME','UF_SORT']);
    if($fallback==='description') {
        $f['db']->execute('ALTER TABLE qa_colors ADD UF_DESCRIPTION TEXT');
        $f['db']->execute('UPDATE qa_colors SET UF_DESCRIPTION=? WHERE ID=1',['Описание']);
        $f['db']->execute('INSERT INTO b_user_field (ENTITY_ID,FIELD_NAME,USER_TYPE_ID,MULTIPLE) VALUES (?,?,?,?)',['HLBLOCK_7','UF_DESCRIPTION','string','N']);
    }
    $f['db']->begin();$snapshot=capture($f);
    check($snapshot['properties']['selected_offer'][2001][22]['values'][0]['value']===($fallback==='description'?'Описание':'blue'),'Directory label fallback: '.$fallback);
    check($snapshot['properties']['selected_offer'][2001][22]['values'][0]['sort']===0,'Directory without UF_SORT uses stable ID order');$f['db']->rollback();
}
foreach(['bad-id','duplicates','foreign-scope','source-key','source-code','conflicting-source'] as $bad) {
    $f=fixture();$f['db']->begin();
    if($bad==='bad-id')$f['offerIds']=['2001'];
    elseif($bad==='duplicates')$f['offerIds']=[2001,2001];
    elseif($bad==='foreign-scope')$f['sources'][0]['iblock_id']=15;
    elseif($bad==='source-key')$f['sources'][0]['evil']='x';
    elseif($bad==='source-code')$f['sources'][0]['property_code']='x;DROP';
    else {$f['sources'][]=$f['sources'][0];$f['sources'][count($f['sources'])-1]['property_code']='OTHER';}
    reject(fn()=>capture($f),$bad);check($f['observed']->queries===[],'Invalid request before SQL: '.$bad);$f['db']->rollback();
}
foreach([false,true] as $lock) {
    $f=fixture();$f['observed']->mysql=true;$f['db']->begin(!$lock);$snapshot=capture($f,$lock);
    foreach($f['observed']->queries as [$sql,$args]) {
        if(str_contains($sql,'information_schema.TABLES'))continue;
        check(str_ends_with($sql,' FOR UPDATE')===$lock,'Only locked capture uses FOR UPDATE');
        check(str_contains($sql,' LIMIT '),'Every data query bounded');
    }
    $f['observed']->engine='MyISAM';reject(fn()=>capture($f,$lock),'Nontransactional source table rejected');$f['db']->rollback();
}
echo "PASS $checks catalog property snapshot assertions\n";
