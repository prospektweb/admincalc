<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__.'/DocumentRepository.php';

/** Independent product preparation. The authenticated admin gateway owns permissions and catalog reads. */
final class DocumentProductPreparation
{
    public function __construct(private SqlConnection $db, private string $scope, private string $actor,
        private DocumentRepository $documents, private string $provider, private string $catalog, private $readProducts) {}

    private static function json(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) { $values=get_object_vars($value); ksort($values); return (object)array_map([self::class,'canonical'],$values); }
        if (is_array($value)) return array_map([self::class,'canonical'],$value);
        return $value;
    }
    /** Only semantic execution inputs; never results, tariff, runtime IDs or revision. */
    public static function variantKey(string $formHash, object $payload): string
    {
        $execution=new \stdClass();
        foreach (['unitCount','layoutCount','runCount','deadlineType'] as $key) {
            if (!property_exists($payload->execution,$key)) throw new \RuntimeException('Неполные входы сохранённого расчёта.');
            $execution->$key=$payload->execution->$key;
        }
        return hash('sha256',self::json(self::canonical((object)['form'=>$formHash,'values'=>$payload->values,'activation'=>$payload->activation,'execution'=>$execution])));
    }
    private static function identity(mixed $value): string
    {
        if (!is_string($value)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$value)) throw new \InvalidArgumentException('Invalid identity.');
        return $value;
    }
    public function command(array $c): array
    {
        $operation=$c['operation']??'';
        $fields=['targets'=>['groupId','query'],'list'=>['productKey'],'load'=>['productKey','resultId'],
            'preview'=>['productKey','groupId','snapshotIds'],'transfer'=>['productKey','groupId','snapshotIds','fingerprint','choices']];
        if (($c['action']??'')!=='productPreparation'||!is_string($operation)||!isset($fields[$operation])) throw new \InvalidArgumentException('Unknown preparation operation.');
        $keys=array_keys($c);sort($keys);$expected=array_merge(['action','operation','id','versionId','expectedRevision','storefrontId'],$fields[$operation]);sort($expected);
        if($keys!==$expected)throw new \InvalidArgumentException('Unknown preparation field.');
        $id=self::identity($c['id']);$version=self::identity($c['versionId']);$view=self::identity($c['storefrontId']);
        if(!is_int($c['expectedRevision'])||$c['expectedRevision']<1)throw new \InvalidArgumentException('Expected revision.');
        $this->db->begin();
        try {
            $row=$this->db->rows('SELECT id FROM b_pw_calc_document WHERE id = ? AND scope_id = ?'.($this->db->dialect()==='mysql'?' FOR UPDATE':''),[$id,$this->scope]);
            if(!$row)throw new \RuntimeException('Калькулятор не найден.',404);
            $source=$this->documents->versions()->loadInTransaction($id,$version);
            if($source['revision']!==$c['expectedRevision'])throw new DocumentConflict('Версия или привязки изменились. Перечитайте подготовку.');
            if($source['archived']||($source['versionArchived']??false))throw new DocumentConflict('Архивная версия доступна только для чтения.');
            if(!is_string($source['connectionJson']))throw new DocumentConflict('Сначала сохраните подключение сайта и товары.');
            $document=json_decode($source['bodyJson'],true,64,JSON_THROW_ON_ERROR);
            $site=json_decode(SiteConnection::canonical($source['connectionJson'],$document),true,64,JSON_THROW_ON_ERROR);
            if($site['provider']!==$this->provider||$site['productsCatalog']!==$this->catalog)throw new DocumentConflict('Подключение относится к другому каталогу.');
            if(!in_array($view,array_column($document['presentations']['views'],'id'),true))throw new DocumentConflict('Витрина больше не существует.');
            $linked=array_values(array_map(fn($p)=>$p['key'],array_filter($site['products'],fn($p)=>$p['presentationId']===$view)));
            $group=null;
            if(array_key_exists('groupId',$c)&&$c['groupId']!==null){
                $groupId=self::identity($c['groupId']);
                $groups=$this->db->rows('SELECT * FROM b_pw_calc_snapshot_group WHERE id = ? AND scope_id = ? AND document_id = ? AND version_id = ? AND actor_id = ? AND storefront_id = ?',[$groupId,$this->scope,$id,$version,$this->actor,$view]);
                if(!$groups)throw new \RuntimeException('Группа не найдена.',404);$group=$groups[0];
            }
            if($operation==='targets'){
                if(!is_string($c['query'])||mb_strlen($c['query'])>100)throw new \InvalidArgumentException('Поиск: до 100 символов.');
                $items=[];$query=mb_strtolower(trim($c['query']));
                foreach(array_chunk($linked,100) as $batch)foreach($this->catalogRows($batch,$id) as $product){
                    if($query===''||str_contains(mb_strtolower($product['name'].' '.$product['key']),$query))$items[]=$product;
                }
                $target=$group?$this->db->rows('SELECT product_key FROM b_pw_calc_group_target WHERE group_id = ?',[$group['id']]):[];
                $result=['items'=>$items,'linkedProductKey'=>$target[0]['product_key']??null];
            }else{
                $product=self::identity($c['productKey']);
                if(!in_array($product,$linked,true))throw new DocumentConflict('Товар не связан с этой витриной в сохранённой версии.');
                if($operation==='transfer'&&$this->db->dialect()==='mysql'){
                    $owners=$this->db->rows('SELECT document_id FROM b_pw_calc_product_binding WHERE scope_id = ? AND provider = ? AND catalog_key = ? AND product_key = ? FOR UPDATE',[$this->scope,$this->provider,$this->catalog,$product]);
                    if($owners&&$owners[0]['document_id']!==$id)throw new DocumentConflict('Товар связан с другим калькулятором.');
                }
                $rows=$this->catalogRows([$product],$id);
                if(count($rows)!==1)throw new DocumentConflict('Товар отсутствует, недоступен или связан с другим калькулятором.');
                $preparation=hash('sha256',self::json([$this->scope,$this->provider,$this->catalog,$product,$id,$view]));
                $heads=$this->db->rows('SELECT revision FROM b_pw_calc_preparation WHERE id = ?',[$preparation]);$revision=(int)($heads[0]['revision']??0);
                $stored=$this->db->rows('SELECT id, variant_key, snapshot_id, active, form_hash, summary_json, provenance_json, payload_hash, created_at FROM b_pw_calc_preparation_result WHERE preparation_id = ? ORDER BY created_at, id',[$preparation]);
                $form=DocumentCalculationSnapshots::signature($source['bodyJson'],$view);
                $items=array_map(fn($r)=>['id'=>$r['id'],'variantKey'=>$r['variant_key'],'snapshotId'=>$r['snapshot_id'],'active'=>(bool)$r['active'],
                    'needsReview'=>!hash_equals($form,$r['form_hash']),'summary'=>json_decode($r['summary_json'],true,64,JSON_THROW_ON_ERROR),
                    'provenance'=>json_decode($r['provenance_json'],true,64,JSON_THROW_ON_ERROR),'createdAt'=>$r['created_at']],$stored);
                $base=['preparationId'=>$preparation,'revision'=>$revision,'product'=>$rows[0],'items'=>$items];
                if($operation==='list')$result=$base;
                elseif($operation==='load'){
                    $resultId=self::identity($c['resultId']);
                    $found=$this->db->rows('SELECT payload_json, payload_hash FROM b_pw_calc_preparation_result WHERE preparation_id = ? AND id = ?',[$preparation,$resultId]);
                    if(!$found)throw new \RuntimeException('Результат подготовки не найден.',404);
                    if(!hash_equals($found[0]['payload_hash'],hash('sha256',$found[0]['payload_json'])))throw new \RuntimeException('Preparation integrity check failed.');
                    $result=$base+['payload'=>json_decode($found[0]['payload_json'],false,64,JSON_THROW_ON_ERROR)];
                }else{
                    $snapshots=$c['snapshotIds'];
                    if(!is_array($snapshots)||!array_is_list($snapshots)||!$snapshots||count($snapshots)>100||count(array_unique($snapshots))!==count($snapshots))throw new \InvalidArgumentException('Выберите от 1 до 100 расчётов.');
                    foreach($snapshots as $snapshot)self::identity($snapshot);sort($snapshots,SORT_STRING);
                    $all=$this->db->rows('SELECT s.id, s.payload_hash, m.group_id FROM b_pw_calc_snapshot s LEFT JOIN b_pw_calc_snapshot_member m ON m.snapshot_id = s.id WHERE s.scope_id = ? AND s.document_id = ? AND s.version_id = ? AND s.actor_id = ? AND s.storefront_id = ? ORDER BY s.id',[$this->scope,$id,$version,$this->actor,$view]);
                    $selected=array_values(array_filter($all,fn($r)=>in_array($r['id'],$snapshots,true)));
                    if(count($selected)!==count($snapshots))throw new DocumentConflict('Состав расчётов изменился. Выберите его заново.');
                    $active=[];$seen=[];foreach($stored as $r){$seen[$r['snapshot_id']]=true;if($r['active'])$active[$r['variant_key']]=$r['snapshot_id'];}
                    $incoming=[];$bytes=0;
                    foreach($selected as $s){
                        if($group&&$s['group_id']!==$group['id'])throw new DocumentConflict('Расчёт больше не входит в выбранную группу.');
                        $s=$s+$this->db->rows('SELECT * FROM b_pw_calc_snapshot WHERE id = ?',[$s['id']])[0];
                        if(!hash_equals($s['payload_hash'],hash('sha256',$s['payload_json'])))throw new \RuntimeException('Snapshot integrity check failed.');
                        $bytes+=strlen($s['payload_json']);if($bytes>32000000)throw new \InvalidArgumentException('Выберите меньшую подборку: суммарный размер превышает 32 МБ.');
                        $payload=json_decode($s['payload_json'],false,64,JSON_THROW_ON_ERROR);
                        $key=self::variantKey($s['form_hash'],$payload);
                        $incoming[$key][]=['snapshotId'=>$s['id'],'summary'=>json_decode($s['summary_json'],true,64,JSON_THROW_ON_ERROR),
                            'already'=>isset($seen[$s['id']]),'needsReview'=>!hash_equals($form,$s['form_hash'])];
                        $s['variant_key']=$key;$copies[$s['id']]=$s;
                    }
                    $variants=[];$counts=['new'=>0,'already'=>0,'conflicts'=>0];
                    foreach($incoming as $key=>$candidates){
                        $new=array_values(array_filter($candidates,fn($r)=>!$r['already']));
                        $conflict=count($new)>0&&(isset($active[$key])||count($new)>1);
                        foreach($candidates as $candidate)$counts[$candidate['already']?'already':($conflict?'conflicts':'new')]++;
                        $variants[]=['key'=>$key,'currentSnapshotId'=>$active[$key]??null,'conflict'=>$conflict,'candidates'=>$candidates];
                    }
                    $target=$group?$this->db->rows('SELECT product_key FROM b_pw_calc_group_target WHERE group_id = ?',[$group['id']]):[];
                    $composition=array_map(fn($r)=>[$r['id'],$r['group_id'],$r['payload_hash']],$all);
                    $fingerprint=hash('sha256',self::json([$source['revision'],$source['bodyHash'],$source['connectionJson'],$rows,$revision,$stored,$group,$target,$composition,$snapshots]));
                    $result=$base+['variants'=>$variants,'counts'=>$counts,'fingerprint'=>$fingerprint];
                    if($operation==='transfer'){
                        if(!is_string($c['fingerprint'])||!hash_equals($fingerprint,$c['fingerprint']))throw new DocumentConflict('Подборка, подготовка или связь изменились. Проверьте передачу заново.');
                        $choices=$c['choices'];if($choices instanceof \stdClass)$choices=get_object_vars($choices);
                        if(!is_array($choices))throw new \InvalidArgumentException('Expected choices.');
                        $conflictKeys=array_column(array_filter($variants,fn($v)=>$v['conflict']),'key');
                        if(array_diff(array_keys($choices),$conflictKeys)||array_diff($conflictKeys,array_keys($choices)))throw new \InvalidArgumentException('Выберите результат для каждого конфликта.');
                        $decisions=[];
                        foreach($variants as $v){
                            $new=array_values(array_filter($v['candidates'],fn($r)=>!$r['already']));
                            if(!$new)continue;
                            $winner=$v['conflict']?$choices[$v['key']]:$new[0]['snapshotId'];
                            if(!is_string($winner)||!in_array($winner,array_merge(array_column($new,'snapshotId'),$v['currentSnapshotId']?[$v['currentSnapshotId']]:[]),true))throw new \InvalidArgumentException('Выбранный результат недоступен.');
                            $decisions[$v['key']]=['before'=>$v['currentSnapshotId'],'after'=>$winner];
                        }
                        if(!$heads)$this->db->execute('INSERT INTO b_pw_calc_preparation (id, scope_id, provider, catalog_id, product_key, document_id, storefront_id, revision) VALUES (?, ?, ?, ?, ?, ?, ?, 0)',[$preparation,$this->scope,$this->provider,$this->catalog,$product,$id,$view]);
                        foreach($decisions as $key=>$decision){
                            $this->db->execute('UPDATE b_pw_calc_preparation_result SET active = 0 WHERE preparation_id = ? AND variant_key = ?',[$preparation,$key]);
                            foreach($incoming[$key] as $candidate){
                                if($candidate['already'])continue;$s=$copies[$candidate['snapshotId']];
                                $p=json_decode($s['payload_json'],false,64,JSON_THROW_ON_ERROR);
                                $provenance=['site'=>$this->scope,'provider'=>$this->provider,'catalog'=>$this->catalog,'productKey'=>$product,'documentId'=>$id,
                                    'versionId'=>$version,'storefrontId'=>$view,'sourceActor'=>$s['actor_id'],'transferredBy'=>$this->actor,'snapshotId'=>$s['id'],
                                    'groupId'=>$s['group_id'],'groupName'=>$group['name']??null,'sourceCreatedAt'=>$s['created_at'],'source'=>$p->response->source,
                                    'formHash'=>$s['form_hash'],'payloadHash'=>$s['payload_hash'],'resourcesHash'=>$p->resourcesHash,'needsReviewAtTransfer'=>$candidate['needsReview']];
                                $this->db->execute('INSERT INTO b_pw_calc_preparation_result (id, preparation_id, variant_key, snapshot_id, active, form_hash, payload_json, payload_hash, summary_json, provenance_json, created_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)',
                                    ['pr_'.bin2hex(random_bytes(16)),$preparation,$key,$s['id'],$s['form_hash'],$s['payload_json'],$s['payload_hash'],$s['summary_json'],self::json($provenance),gmdate('Y-m-d\TH:i:s\Z')]);
                            }
                            $this->db->execute('UPDATE b_pw_calc_preparation_result SET active = 1 WHERE preparation_id = ? AND variant_key = ? AND snapshot_id = ?',[$preparation,$key,$decision['after']]);
                        }
                        if($decisions){
                            $this->db->execute('UPDATE b_pw_calc_preparation SET revision = revision + 1 WHERE id = ?',[$preparation]);
                            $this->db->execute('INSERT INTO b_pw_calc_preparation_history (id, preparation_id, decision_json, actor_id, created_at) VALUES (?, ?, ?, ?, ?)',
                                ['ph_'.bin2hex(random_bytes(16)),$preparation,self::json($decisions),$this->actor,gmdate('Y-m-d\TH:i:s\Z')]);
                        }
                        if($group){
                            $this->db->execute('DELETE FROM b_pw_calc_group_target WHERE group_id = ?',[$group['id']]);
                            $this->db->execute('INSERT INTO b_pw_calc_group_target (group_id, product_key) VALUES (?, ?)',[$group['id'],$product]);
                        }
                        $result=['counts'=>$counts,'saved'=>count($decisions),'preparationId'=>$preparation];
                    }
                }
            }
            $this->db->commit();return $result;
        }catch(\Throwable $e){$this->db->rollback();throw $e;}
    }
    private function catalogRows(array $ids,string $document): array
    {
        if(!$ids)return [];
        $rows=($this->readProducts)([],$ids);$owners=$this->documents->productBindings($this->provider,$this->catalog,$ids);$seen=[];$result=[];
        foreach($rows as $row){
            if(!is_array($row)||!is_string($row['key']??null)||!in_array($row['key'],$ids,true)||isset($seen[$row['key']])||!is_string($row['name']??null))throw new \RuntimeException('Некорректный ответ каталога.');
            $seen[$row['key']]=true;
            if(isset($owners[$row['key']])&&$owners[$row['key']]['document_id']!==$document)continue;
            $result[]=['key'=>$row['key'],'name'=>$row['name']];
        }
        return $result;
    }

    /** Internal maintenance only; intentionally absent from the HTTP command dispatcher.
     * Requires an exact reviewed composition, revision and exclusive provenance ownership. */
    public function removeOwnedPreparation(string $preparationId, int $expectedRevision, array $expectedResultIds): void
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$preparationId)||$expectedRevision<1||!$expectedResultIds)throw new \InvalidArgumentException('Expected exact preparation and composition.');
        // Resolve the lock identity before starting the consistent read view.
        $heads=$this->db->rows('SELECT document_id FROM b_pw_calc_preparation WHERE id = ? AND scope_id = ?',[$preparationId,$this->scope]);
        if(!$heads)throw new \RuntimeException('Preparation not found.',404);$head=$heads[0];
        $this->db->begin();
        try{
            $this->db->rows('SELECT id FROM b_pw_calc_document WHERE id = ? AND scope_id = ?'.($this->db->dialect()==='mysql'?' FOR UPDATE':''),[$head['document_id'],$this->scope]);
            $fresh=$this->db->rows('SELECT revision FROM b_pw_calc_preparation WHERE id = ?',[$preparationId]);
            if(!$fresh||(int)$fresh[0]['revision']!==$expectedRevision)throw new DocumentConflict('Preparation changed.');
            $rows=$this->db->rows('SELECT id, provenance_json FROM b_pw_calc_preparation_result WHERE preparation_id = ?',[$preparationId]);
            $actual=array_column($rows,'id');sort($actual);sort($expectedResultIds);
            if($actual!==$expectedResultIds)throw new DocumentConflict('Preparation composition changed.');
            foreach($rows as $row){$p=json_decode($row['provenance_json'],true,64,JSON_THROW_ON_ERROR);if($p['transferredBy']!==$this->actor||$p['sourceActor']!==$this->actor)throw new DocumentConflict('Preparation contains another author.');}
            if($this->db->rows('SELECT preparation_id FROM b_pw_calc_preparation_offer WHERE preparation_id=?',[$preparationId])
                ||$this->db->rows('SELECT id FROM b_pw_calc_preparation_write WHERE preparation_id=?',[$preparationId]))throw new DocumentConflict('Preparation has catalog bindings or write receipts. Exact generation maintenance is required first.');
            $this->db->execute('DELETE FROM b_pw_calc_preparation_history WHERE preparation_id = ?',[$preparationId]);
            $this->db->execute('DELETE FROM b_pw_calc_preparation_result WHERE preparation_id = ?',[$preparationId]);
            $this->db->execute('DELETE FROM b_pw_calc_preparation WHERE id = ?',[$preparationId]);
            $this->db->commit();
        }catch(\Throwable $e){$this->db->rollback();throw $e;}
    }
}
