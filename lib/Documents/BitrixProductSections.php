<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** Read-only catalog membership for explicit native product keys; no preset or property assignments. */
final class BitrixProductSections
{
    public function load(string $provider, int $catalogId, $keys): array
    {
        if ($catalogId < 1 || !is_array($keys) || !array_is_list($keys) || count($keys) > 10000) throw new \InvalidArgumentException('Invalid product section batch.');
        foreach ($keys as $key) if (!is_string($key) || preg_match('/^[1-9][0-9]{0,8}$/D', $key) !== 1) throw new \InvalidArgumentException('Invalid product identity.');
        if (count(array_unique($keys, SORT_STRING)) !== count($keys)) throw new \InvalidArgumentException('Duplicate product identity.');
        $sections = []; $sectionIds = [];
        $cursor = \CIBlockSection::GetList(['LEFT_MARGIN'=>'ASC','ID'=>'ASC'], ['IBLOCK_ID'=>$catalogId,'CHECK_PERMISSIONS'=>'Y'], false,
            ['ID','IBLOCK_ID','IBLOCK_SECTION_ID','NAME','DEPTH_LEVEL']);
        while ($row = $cursor->Fetch()) {
            if (count($sections) >= 10000) throw new \InvalidArgumentException('Catalog section limit exceeded.');
            if ((int)$row['IBLOCK_ID'] !== $catalogId) throw new \RuntimeException('Section catalog mismatch.');
            $id=(int)$row['ID'];
            if ($id < 1 || isset($sectionIds[$id])) throw new \RuntimeException('Invalid or duplicate section identity.');
            $sectionIds[$id]=true;
            $sections[]=['id'=>$id,'parent_id'=>(int)$row['IBLOCK_SECTION_ID'],'name'=>(string)$row['NAME'],'depth'=>max(0,(int)$row['DEPTH_LEVEL']-1)];
        }
        $products = [];
        foreach (array_chunk($keys, 200) as $chunk) {
            $requested = array_fill_keys($chunk, true); $visible = [];
            $cursor = \CIBlockElement::GetList(['ID'=>'ASC'], ['IBLOCK_ID'=>$catalogId,'ID'=>array_map('intval',$chunk),
                'ACTIVE'=>'Y','ACTIVE_DATE'=>'Y','CHECK_PERMISSIONS'=>'Y'], false, false, ['ID','IBLOCK_ID','NAME']);
            while ($row=$cursor->Fetch()) {
                $id=(int)$row['ID'];
                if (!isset($requested[(string)$id]) || (int)$row['IBLOCK_ID'] !== $catalogId) throw new \RuntimeException('Product catalog mismatch.');
                $visible[$id]=['id'=>$id,'name'=>(string)$row['NAME'],'section_ids'=>[]];
            }
            if ($visible !== []) {
                // Batch API (including section-property memberships, as in the former selector).
                $cursor = \CIBlockElement::GetElementGroups(array_keys($visible), false, ['ID','IBLOCK_ID','IBLOCK_ELEMENT_ID']);
                while ($row=$cursor->Fetch()) {
                    $product=(int)$row['IBLOCK_ELEMENT_ID']; $section=(int)$row['ID'];
                    if ((int)$row['IBLOCK_ID'] !== $catalogId || !isset($visible[$product]) || !isset($sectionIds[$section])) continue;
                    $visible[$product]['section_ids'][$section]=$section;
                }
                foreach ($visible as $product) { $product['section_ids']=array_values($product['section_ids']); $products[]=$product; }
            }
        }
        return ['contract'=>'prospektweb.calculator/product-sections-v1','provider'=>$provider,'productsCatalog'=>(string)$catalogId,
            'sections'=>$sections,'products'=>$products];
    }
}
