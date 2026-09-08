<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/Documents/BitrixResourceLinks.php';
require_once dirname(__DIR__).'/lib/Documents/PdoConnection.php';
use Prospektweb\Calc\Documents\{PdoConnection, SqlConnection, BitrixResourceLinks};
final class ReadOnlyLinksDb implements SqlConnection {
    public array $queries = [];
    public function __construct(private PdoConnection $db) {}
    public function dialect(): string { return 'sqlite'; }
    public function inTransaction(): bool { return $this->db->inTransaction(); }
    public function begin(bool $readSnapshot = false): void { throw new LogicException('Caller owns transaction'); }
    public function commit(): void { throw new LogicException('Caller owns transaction'); }
    public function rollback(): void { throw new LogicException('Caller owns transaction'); }
    public function execute(string $sql, array $parameters = []): void { throw new LogicException('No write/read-repair allowed'); }
    public function rows(string $sql, array $parameters = []): array { if (!str_starts_with($sql,'SELECT ')) throw new LogicException('Only SELECT'); $this->queries[] = $sql; return $this->db->rows($sql,$parameters); }
}
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{$checks++;if(!$ok)throw new RuntimeException($message);};
foreach([1,2] as $version) {
    $db=new PdoConnection(new PDO('sqlite::memory:'));
    $db->execute('CREATE TABLE b_iblock (ID INTEGER,VERSION INTEGER)');$db->execute('INSERT INTO b_iblock VALUES (7,?)',[$version]);
    $db->execute('CREATE TABLE b_iblock_property (ID INTEGER,IBLOCK_ID INTEGER,ACTIVE TEXT,CODE TEXT,PROPERTY_TYPE TEXT,USER_TYPE TEXT,MULTIPLE TEXT)');
    $db->execute("INSERT INTO b_iblock_property VALUES (10,7,'Y','CML2_LINK','E','','N'),(11,7,'Y','SUPPORTED_EQUIPMENT_LIST','E','','Y'),(12,7,'N','SUPPORTED_MATERIALS_VARIANTS_LIST','E','','Y')");
    $db->execute('CREATE TABLE b_iblock_element_prop_s7 (IBLOCK_ELEMENT_ID INTEGER, PROPERTY_10 TEXT, PROPERTY_11 TEXT)');
    $db->execute("INSERT INTO b_iblock_element_prop_s7 VALUES (1,'100',NULL),(2,'200','intentionally stale serialized cache')");
    foreach(['b_iblock_element_property','b_iblock_element_prop_m7'] as $table) {
        $db->execute('CREATE TABLE '.$table.' (ID INTEGER, IBLOCK_ELEMENT_ID INTEGER,IBLOCK_PROPERTY_ID INTEGER,VALUE TEXT)');
        $db->execute('INSERT INTO '.$table." VALUES (1,1,10,'100'),(2,2,10,'200'),(3,1,11,'300'),(4,1,11,'301'),(5,2,11,'302'),(6,1,12,'secret-inactive')");
    }
    $db->begin();$reader=new ReadOnlyLinksDb($db);$links=new BitrixResourceLinks($reader);
    $before=$db->rows('SELECT * FROM b_iblock_element_prop_s7');
    $result=$links->load(7,[1,2]);
    $check($result === [1=>['CML2_LINK'=>['100'],'SUPPORTED_EQUIPMENT_LIST'=>['300','301']],2=>['CML2_LINK'=>['200'],'SUPPORTED_EQUIPMENT_LIST'=>['302']]],'Same V1/V2 semantics without serialized cache');
    $check($before===$db->rows('SELECT * FROM b_iblock_element_prop_s7'),'Serialized cache not repaired');
    $check(count($reader->queries)===($version===1?3:4),'Bounded metadata and value queries');
    $reader->queries=[];$links->load(7,[1]);$check(count($reader->queries)===($version===1?1:2),'Metadata reused within snapshot');
    foreach([[1,1],['1'],[0],[9999999999],[]] as $ids) {
        try{$links->load(7,$ids);throw new RuntimeException('Invalid IDs accepted');}catch(InvalidArgumentException $e){$checks++;}
    }
    $db->rollback();
    try{$links->load(7,[1]);throw new RuntimeException('No transaction accepted');}catch(LogicException $e){$checks++;}
}
echo "PASS $checks cache-free resource link assertions\n";
