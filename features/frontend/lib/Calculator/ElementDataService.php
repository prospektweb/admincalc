<?php

namespace Prospektweb\Frontcalc\Calculator;

use Bitrix\Main\Loader;

class ElementDataService
{
    public function __construct()
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix iblock');
        }
        if (!Loader::includeModule('catalog')) {
            throw new \RuntimeException('Требуется модуль Bitrix catalog');
        }
    }

    public function prepareRefreshPayload(array $requests): array
    {
        $result = [];
        foreach ($requests as $request) {
            $ids = $this->normalizeIds($request['ids'] ?? []);
            $result[] = [
                'iblockId' => (int)($request['iblockId'] ?? 0),
                'iblockType' => $request['iblockType'] ?? null,
                'ids' => $ids,
                'data' => $this->loadElements($ids, !empty($request['includeParent'])),
            ];
        }
        return $result;
    }

    public function loadSingleElement(int $iblockId, int $id, ?string $iblockType = null, bool $includeParent = false): ?array
    {
        $payload = $this->prepareRefreshPayload([['iblockId' => $iblockId, 'iblockType' => $iblockType, 'ids' => [$id], 'includeParent' => $includeParent]]);
        return $payload[0]['data'][0] ?? null;
    }

    private function loadElements(array $ids, bool $includeParent = false): array
    {
        $elements = [];
        foreach ($ids as $elementId) {
            $elementObject = \CIBlockElement::GetList([], ['ID' => $elementId], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'TIMESTAMP_X', 'MODIFIED_BY', 'PROPERTY_CML2_LINK'])->GetNextElement();
            if (!$elementObject) {
                continue;
            }
            $fields = $elementObject->GetFields();
            $productData = \CCatalogProduct::GetByID($elementId) ?: [];
            $productId = (int)($fields['PROPERTY_CML2_LINK_VALUE'] ?? 0);
            if ($productId <= 0) {
                $skuParent = \CCatalogSku::GetProductInfo($elementId);
                $productId = (int)($skuParent['ID'] ?? 0);
            }
            $data = [
                'id' => (int)$fields['ID'],
                'iblockId' => (int)$fields['IBLOCK_ID'],
                'code' => $fields['CODE'] ?? null,
                'productId' => $productId > 0 ? $productId : null,
                'name' => $fields['NAME'] ?? '',
                'timestampX' => $fields['TIMESTAMP_X'] ?? null,
                'modifiedBy' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
                'timestamp_x' => $fields['TIMESTAMP_X'] ?? null,
                'modified_by' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
                'attributes' => [
                    'width' => isset($productData['WIDTH']) ? (float)$productData['WIDTH'] : null,
                    'height' => isset($productData['HEIGHT']) ? (float)$productData['HEIGHT'] : null,
                    'length' => isset($productData['LENGTH']) ? (float)$productData['LENGTH'] : null,
                    'weight' => isset($productData['WEIGHT']) ? (float)$productData['WEIGHT'] : null,
                ],
                'measure' => $this->getMeasureInfo((int)($productData['MEASURE'] ?? 0)),
                'measureRatio' => $this->getMeasureRatio($elementId),
                'purchasingPrice' => isset($productData['PURCHASING_PRICE']) ? (float)$productData['PURCHASING_PRICE'] : null,
                'purchasingCurrency' => $productData['PURCHASING_CURRENCY'] ?? null,
                'prices' => $this->getPrices($elementId),
                'properties' => PropertyPayloadLoader::loadElementProperties((int)$fields['IBLOCK_ID'], (int)$fields['ID']),
            ];
            if ($includeParent && $productId > 0) {
                $data['itemParent'] = $this->loadParentElement($productId);
            }
            $elements[] = $data;
        }
        return $elements;
    }

    private function loadParentElement(int $parentId): ?array
    {
        $elementObject = \CIBlockElement::GetList([], ['ID' => $parentId], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'TIMESTAMP_X', 'MODIFIED_BY'])->GetNextElement();
        if (!$elementObject) return null;
        $fields = $elementObject->GetFields();
        return [
            'id' => (int)$fields['ID'], 'iblockId' => (int)$fields['IBLOCK_ID'], 'code' => $fields['CODE'] ?? null,
            'name' => $fields['NAME'] ?? '', 'timestampX' => $fields['TIMESTAMP_X'] ?? null,
            'modifiedBy' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
            'timestamp_x' => $fields['TIMESTAMP_X'] ?? null, 'modified_by' => isset($fields['MODIFIED_BY']) ? (int)$fields['MODIFIED_BY'] : null,
            'properties' => PropertyPayloadLoader::loadElementProperties((int)$fields['IBLOCK_ID'], (int)$fields['ID']),
        ];
    }

    private function normalizeIds($ids): array { return array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : [$ids]), static fn($id) => $id > 0))); }
    private function getMeasureRatio(int $productId): ?float { $it = \CCatalogMeasureRatio::getList([], ['PRODUCT_ID' => $productId]); return ($r = $it->Fetch()) ? (float)($r['RATIO'] ?? 1) : null; }
    private function getMeasureInfo(int $measureId): ?array { if ($measureId <= 0) return null; $it = \CCatalogMeasure::getList(['ID'=>'ASC'], ['=ID'=>$measureId]); if ($m=$it->Fetch()) return ['id'=>(int)$m['ID'],'code'=>$m['CODE']??null,'symbol'=>$m['SYMBOL']??null,'symbolInt'=>$m['SYMBOL_INTL']??null,'title'=>$m['MEASURE_TITLE']??null]; return null; }
    private function getPrices(int $productId): array { $prices=[]; $it=\CPrice::GetList([], ['PRODUCT_ID'=>$productId]); while($p=$it->Fetch()) $prices[]=['typeId'=>(int)$p['CATALOG_GROUP_ID'],'price'=>(float)$p['PRICE'],'currency'=>$p['CURRENCY']??null,'quantityFrom'=>isset($p['QUANTITY_FROM'])?(int)$p['QUANTITY_FROM']:null,'quantityTo'=>isset($p['QUANTITY_TO'])?(int)$p['QUANTITY_TO']:null]; return $prices; }
}
