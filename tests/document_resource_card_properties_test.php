<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Documents/PdoConnection.php';
require_once dirname(__DIR__) . '/lib/Documents/BitrixResourceCardProperties.php';
use Prospektweb\Calc\Documents\{PdoConnection, BitrixResourceCardProperties};
$checks = 0;
$check = static function(bool $ok, string $label) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($label); };
$rejects = static function(callable $call, int $code = 409) use ($check): void { try { $call(); } catch (Throwable $e) { $check($e->getCode() === $code, $e->getMessage()); return; } throw new RuntimeException('Expected failure'); };
$specs = ['PARAMETRS'=>['type'=>'S','multiple'=>true,'description'=>true], 'SOURCE_LINKS'=>['type'=>'S','multiple'=>true,'description'=>true], 'CML2_LINK'=>['type'=>'E','multiple'=>false,'description'=>false]];
foreach ([1, 2] as $version) {
    $pdo = new PDO('sqlite::memory:'); $db = new PdoConnection($pdo);
    $db->execute('CREATE TABLE b_iblock (ID INTEGER PRIMARY KEY, VERSION INTEGER)');
    $db->execute('CREATE TABLE b_iblock_property (ID INTEGER,IBLOCK_ID INTEGER,CODE TEXT,ACTIVE TEXT,PROPERTY_TYPE TEXT,USER_TYPE TEXT,MULTIPLE TEXT,WITH_DESCRIPTION TEXT)');
    $db->execute('CREATE TABLE b_iblock_element_property (ID INTEGER PRIMARY KEY,IBLOCK_ELEMENT_ID INTEGER,IBLOCK_PROPERTY_ID INTEGER,VALUE TEXT,DESCRIPTION TEXT)');
    $db->execute('CREATE TABLE b_iblock_element_prop_s47 (IBLOCK_ELEMENT_ID INTEGER PRIMARY KEY,PROPERTY_103 TEXT,PROPERTY_101 TEXT)');
    $db->execute('CREATE TABLE b_iblock_element_prop_m47 (ID INTEGER PRIMARY KEY,IBLOCK_ELEMENT_ID INTEGER,IBLOCK_PROPERTY_ID INTEGER,VALUE TEXT,DESCRIPTION TEXT)');
    $db->execute('INSERT INTO b_iblock VALUES (47,?)', [$version]);
    foreach ([['PARAMETRS','S','Y','Y'],['SOURCE_LINKS','S','Y','Y'],['CML2_LINK','E','N','N']] as $index=>$p) $db->execute('INSERT INTO b_iblock_property VALUES (?,47,?,\'Y\',?,\'\',?,?)', array_merge([101+$index],$p));
    foreach ([1001,1002] as $id) $db->execute('INSERT INTO b_iblock_element_prop_s47 VALUES (?, ?, ?)', [$id, '900', 'invalid serialized cache - do not read or repair']);
    $table = $version === 2 ? 'b_iblock_element_prop_m47' : 'b_iblock_element_property';
    $db->execute('INSERT INTO ' . $table . ' VALUES (1,1001,101,?,?)',['sheet_cost','12.50|Цена &amp;|Keep literal']);
    $db->execute('INSERT INTO ' . $table . ' VALUES (2,1001,101,?,?)',['second','0|Второй|']);
    $db->execute('INSERT INTO ' . $table . ' VALUES (3,1001,102,?,?)',['https://example.test/source','Источник|Описание']);
    $db->execute('INSERT INTO ' . $table . ' VALUES (4,9999,101,?,?)',['foreign','must not leak']);
    if ($version === 1) foreach ([1001,1002] as $index=>$id) $db->execute('INSERT INTO b_iblock_element_property VALUES (?,?,103,?,NULL)',[10+$index,$id,'900']);
    $reader = new BitrixResourceCardProperties($db);
    $rejects(fn()=>$reader->read(47,[1001],$specs),0);
    $before = (int)$pdo->query('SELECT total_changes()')->fetchColumn();
    $db->begin(true); $result = $reader->read(47,[1001,1002],$specs); $db->commit();
    $check((int)$pdo->query('SELECT total_changes()')->fetchColumn() === $before,'Reads do not mutate V1 values or V2 caches');
    $check($result['version'] === $version && count($result['metadata']) === 3,'Exact metadata');
    $check(array_keys($result['values']) === [1001,1002],'Exact batch');
    $check($result['values'][1001]['PARAMETRS'] === [['value'=>'sheet_cost','description'=>'12.50|Цена &amp;|Keep literal'],['value'=>'second','description'=>'0|Второй|']],'Multiple values retain order, descriptions and literal entities');
    $check($result['values'][1002]['PARAMETRS'] === [] && $result['values'][1001]['CML2_LINK'] === [['value'=>'900','description'=>'']],'Empty multiple and single relationship');
    $db->begin(); $locked = $reader->read(47,[1001,1002],$specs,true); $db->rollback(); $check($locked['values'] === $result['values'],'Lock mode reads the same values');
    foreach ([[1001,1001],[],['1001'],[0],[1.5]] as $ids) { $db->begin(); $rejects(fn()=>$reader->read(47,$ids,$specs),0); $db->rollback(); }
    $db->execute("UPDATE b_iblock_property SET USER_TYPE='unexpected' WHERE ID=101"); $db->begin(); $rejects(fn()=>$reader->read(47,[1001],$specs)); $db->rollback();
    $db->execute("UPDATE b_iblock_property SET USER_TYPE='' WHERE ID=101");
    $db->execute("UPDATE b_iblock_property SET WITH_DESCRIPTION='N' WHERE ID=101"); $db->begin(); $rejects(fn()=>$reader->read(47,[1001],$specs)); $db->rollback();
    $db->execute("UPDATE b_iblock_property SET WITH_DESCRIPTION='Y' WHERE ID=101");
    $db->execute("INSERT INTO b_iblock_property SELECT 104,IBLOCK_ID,CODE,ACTIVE,PROPERTY_TYPE,USER_TYPE,MULTIPLE,WITH_DESCRIPTION FROM b_iblock_property WHERE ID=101");
    $db->begin(); $rejects(fn()=>$reader->read(47,[1001],$specs)); $db->rollback(); $db->execute('DELETE FROM b_iblock_property WHERE ID=104');
    if ($version === 2) $db->execute("UPDATE b_iblock_element_prop_s47 SET PROPERTY_103='900 OR 1=1' WHERE IBLOCK_ELEMENT_ID=1001");
    else $db->execute("UPDATE b_iblock_element_property SET VALUE='900 OR 1=1' WHERE IBLOCK_PROPERTY_ID=103");
    $db->begin(); $rejects(fn()=>$reader->read(47,[1001],$specs)); $db->rollback();
    if ($version === 2) { $db->execute('DELETE FROM b_iblock_element_prop_s47 WHERE IBLOCK_ELEMENT_ID=1001'); $db->begin(); $rejects(fn()=>$reader->read(47,[1001],$specs)); $db->rollback(); }
    else { $db->execute("UPDATE b_iblock_element_property SET VALUE='900' WHERE IBLOCK_PROPERTY_ID=103"); $db->execute("INSERT INTO b_iblock_element_property VALUES (15,1001,103,'901',NULL)"); $db->begin(); $rejects(fn()=>$reader->read(47,[1001],$specs)); $db->rollback(); }
}
echo "PASS $checks resource card property assertions\n";
