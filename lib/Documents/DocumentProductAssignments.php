<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__.'/DocumentRepository.php';

/** Product identities belong to the site connection. Nothing here writes the catalog or publishes. */
final class DocumentProductAssignments
{
    private DocumentRepository $repository;
    private string $provider;
    private string $catalog;
    private $read;
    /** read(queries, ids|null): permission-filtered rows {key,name,active,adminUrl,siteUrl}; batch size <=100. */
    public function __construct(DocumentRepository $repository, string $provider, string $catalog, callable $read)
    { $this->repository=$repository; $this->provider=$provider; $this->catalog=$catalog; $this->read=$read; }

    public function command(array $command): array
    {
        $action=$command['action']??null;
        $fields=['assignmentCatalog'=>['queries'],'previewProductAssignments'=>['productKeys'],'saveProductAssignments'=>['productKeys','impactFingerprint']];
        if (!is_string($action)||!isset($fields[$action])) throw new \InvalidArgumentException('Unknown product assignment action.');
        $keys=array_keys($command); sort($keys); $expected=array_merge(['action','id','versionId','expectedRevision'],$fields[$action]); sort($expected);
        if ($keys!==$expected) throw new \InvalidArgumentException('Unknown product assignment field.');
        foreach (['id','versionId'] as $key) if (!is_string($command[$key])||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$command[$key])) throw new \InvalidArgumentException('Invalid identity.');
        if (!is_int($command['expectedRevision'])||$command['expectedRevision']<1) throw new \InvalidArgumentException('Invalid revision.');
        $source=$this->repository->versions()->load($command['id'],$command['versionId']);
        if ($source['revision']!==$command['expectedRevision']) throw new DocumentConflict();
        if (!is_string($source['connectionJson'])) throw new \InvalidArgumentException('Сначала настройте подключение сайта.');
        $document=json_decode($source['bodyJson'],true,64,JSON_THROW_ON_ERROR);
        $site=json_decode(SiteConnection::canonical($source['connectionJson'],$document),false,64,JSON_THROW_ON_ERROR);
        if ($site->provider!==$this->provider||$site->productsCatalog!==$this->catalog) throw new DocumentConflict('Подключение относится к другому каталогу сайта.');
        $identity=['documentId'=>$command['id'],'versionId'=>$command['versionId'],'revision'=>$source['revision'],'connectionHash'=>hash('sha256',$source['connectionJson'])];
        if ($action==='assignmentCatalog') {
            $queries=$command['queries'];
            if (!is_array($queries)||array_values($queries)!==$queries||count($queries)<1||count($queries)>8) throw new \InvalidArgumentException('Поиск должен содержать от 1 до 8 ключей.');
            $normalized=[];$length=0;
            foreach($queries as $query){
                if(!is_string($query)||trim($query)===''||mb_strlen($query)>100)throw new \InvalidArgumentException('Ключ поиска должен содержать от 1 до 100 символов.');
                $query=trim($query);$key=mb_strtolower($query,'UTF-8');$length+=mb_strlen($query);
                if(isset($normalized[$key]))throw new \InvalidArgumentException('Повторяющийся ключ поиска.');
                $normalized[$key]=$query;
            }
            if($length>300)throw new \InvalidArgumentException('Поисковый запрос слишком длинный.');
            $rows=$this->rows(array_values($normalized),null);
            $result=['source'=>$identity,'items'=>$rows];
        } else {
            if ($source['archived']||($source['versionArchived']??false)) throw new DocumentConflict('Архивная версия доступна только для чтения.');
            $selected=$command['productKeys'];
            if (!is_array($selected)||count($selected)>10000||array_values($selected)!==$selected) throw new \InvalidArgumentException('Invalid product list.');
            foreach ($selected as $key) self::productKey($key);
            if (count(array_unique($selected))!==count($selected)) throw new \InvalidArgumentException('Повторяющийся товар.');
            sort($selected,SORT_STRING);
            $rows=[]; foreach (array_chunk($selected,100) as $batch) $rows=array_merge($rows,$this->rows([], $batch));
            usort($rows,static fn($a,$b)=>strcmp($a['key'],$b['key']));
            if (count($rows)!==count($selected)) throw new DocumentConflict('Выбранные товары отсутствуют или недоступны.');
            foreach ($rows as $row) if ($row['owner']!==null&&$row['owner']['documentId']!==$command['id']) throw new DocumentConflict('Товар #'.$row['key'].' уже открывает другой калькулятор.');
            $old=[]; foreach ($site->products as $product) $old[$product->key]=$product;
            $added=array_values(array_diff($selected,array_map('strval',array_keys($old))));
            $removed=array_values(array_diff(array_map('strval',array_keys($old)),$selected)); sort($removed,SORT_STRING);
            $hasBase=false; foreach ($document['presentations']['views'] as $view) if ($view['id']==='BASE') $hasBase=true;
            if ($added&&!$hasBase) throw new \InvalidArgumentException('Сначала создайте базовую витрину во вкладке «Витрина».');
            $site->products=array_map(static fn($key)=>$old[$key]??(object)['key'=>$key,'presentationId'=>'BASE'],$selected);
            $affected=[];
            foreach ($document['presentations']['views'] as $view) {
                $lost=array_values(array_filter($removed,static fn($key)=>$old[$key]->presentationId===$view['id']));
                if ($lost) $affected[]=['id'=>$view['id'],'name'=>$view['name'],'active'=>$view['active'],'removedProductIds'=>$lost];
            }
            $cleared=[];
            foreach ($site->presentationDefaults??[] as $default) {
                if ($default->productKey!==null&&in_array($default->productKey,$removed,true)) { $cleared[]=$default->presentationId; $default->productKey=null; }
            }
            $json=SiteConnection::canonical(SiteConnection::encode($site),$document);
            $result=['source'=>$identity,'nextProductIds'=>$selected,'addedProductIds'=>$added,'removedProductIds'=>$removed,'affectedStorefronts'=>$affected,'clearedDefaultIds'=>$cleared];
            $result['impactFingerprint']='sha256:'.hash('sha256',SiteConnection::encode((object)['impact'=>$result,'products'=>$rows,'connection'=>$json]));
            if ($action==='saveProductAssignments') {
                if (!is_string($command['impactFingerprint'])||!hash_equals($result['impactFingerprint'],$command['impactFingerprint'])) throw new DocumentConflict('Связи или товары изменились. Проверьте влияние заново.');
                return $this->repository->versions()->save($command['id'],$command['versionId'],$source['revision'],$source['bodyJson'],$json,true);
            }
        }
        if ($this->repository->versions()->load($command['id'],$command['versionId'])['revision']!==$source['revision']) throw new DocumentConflict();
        return $result;
    }

    private static function productKey($key): void
    { if (!is_string($key)||!preg_match('/^[1-9][0-9]{0,8}$/D',$key)) throw new \InvalidArgumentException('Invalid product key.'); }
    private function rows(array $queries, ?array $ids): array
    {
        $rows=($this->read)($queries,$ids);
        if (!is_array($rows)||count($rows)>($ids===null?50:100)) throw new \RuntimeException('Некорректный ответ каталога.');
        $seen=[];
        foreach ($rows as $row) {
            if (!is_array($row)||!isset($row['key'],$row['name'],$row['active'],$row['adminUrl'],$row['siteUrl'])) throw new \RuntimeException('Неполный ответ каталога.');
            self::productKey($row['key']);
            if (isset($seen[$row['key']])||!is_string($row['name'])||!is_bool($row['active'])||!is_string($row['adminUrl'])||!str_starts_with($row['adminUrl'],'/bitrix/admin/')
                ||!is_string($row['siteUrl'])||($row['siteUrl']!==''&&(!str_starts_with($row['siteUrl'],'/')||str_starts_with($row['siteUrl'],'//'))&&!preg_match('/^https?:\/\//i',$row['siteUrl']))||($ids!==null&&!in_array($row['key'],$ids,true))) throw new \RuntimeException('Некорректная строка каталога.');
            $seen[$row['key']]=true;
        }
        $owners=$this->repository->productBindings($this->provider,$this->catalog,array_column($rows,'key'));
        $result=[]; foreach ($rows as $row) {
            $owner=$owners[$row['key']]??null;
            $result[]=['key'=>$row['key'],'name'=>$row['name'],'active'=>$row['active'],'adminUrl'=>$row['adminUrl'],'siteUrl'=>$row['siteUrl'],'owner'=>$owner===null?null:['documentId'=>$owner['document_id'],'name'=>$owner['name'],'publicationId'=>$owner['publication_id']]];
        }
        return $result;
    }
}
