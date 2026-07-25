<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Prospektweb\Calc\Calculator\ElementDataService;
use Prospektweb\Calc\Config\ConfigManager;

final class AiCalculatorContextService
{
    private const SCHEMA = 'prospektweb.calc.ai-calculator-context/v1';
    private const PROPERTY_CODE = 'AI_CONTEXT_JSON';
    private const MAX_BYTES = 262144;

    public function getBaseProducts(array $request): array
    {
        $this->assertAdmin();
        $this->includeModules();
        $mode = (string)($request['mode'] ?? 'tree');
        $config = new ConfigManager();
        $productIblockId = $config->getProductIblockId();
        $offersIblockId = $config->getSkuIblockId();
        if ($productIblockId <= 0) {
            throw new \RuntimeException('Инфоблок товаров не настроен');
        }
        if ($mode === 'tree') {
            return [
                'status' => 'ok',
                'mode' => 'tree',
                'tree' => $this->buildProductTree($productIblockId),
            ];
        }
        if ($mode !== 'details') {
            throw new \InvalidArgumentException('Неизвестный режим базисных продуктов');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($request['productIds'] ?? [])))));
        if (count($ids) > 30) {
            throw new \InvalidArgumentException('Можно выбрать не более 30 базисных продуктов');
        }
        $products = [];
        foreach ($ids as $productId) {
            $products[] = $this->loadProductSnapshot($productIblockId, $offersIblockId, $productId);
        }
        return ['status' => 'ok', 'mode' => 'details', 'products' => $products];
    }

    public function save(array $request): array
    {
        $this->assertAdmin();
        $this->includeModules();
        $settingsId = (int)($request['settingsId'] ?? 0);
        $context = $request['context'] ?? null;
        if ($settingsId <= 0 || !is_array($context) || ($context['schema'] ?? null) !== self::SCHEMA) {
            throw new \InvalidArgumentException('Некорректный контекст AI-конструктора');
        }
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Контекст AI-конструктора слишком велик');
        }
        $settingsIblockId = (new ConfigManager())->getIblockId('CALC_SETTINGS');
        if ($settingsIblockId <= 0 || !\CIBlockElement::GetList([], [
            'ID' => $settingsId,
            'IBLOCK_ID' => $settingsIblockId,
        ], false, ['nTopCount' => 1], ['ID'])->Fetch()) {
            throw new \RuntimeException('Калькулятор не найден');
        }
        $this->ensureProperty($settingsIblockId);
        \CIBlockElement::SetPropertyValuesEx($settingsId, $settingsIblockId, [
            self::PROPERTY_CODE => ['VALUE' => ['TEXT' => $json, 'TYPE' => 'text']],
        ]);
        return ['status' => 'ok', 'settingsId' => $settingsId, 'context' => $context];
    }

    private function loadProductSnapshot(int $productIblockId, int $offersIblockId, int $productId): array
    {
        $loader = new ElementDataService();
        $product = $loader->loadSingleElement($productIblockId, $productId, null, true);
        if (!$product) {
            throw new \RuntimeException('Базисный продукт #' . $productId . ' не найден');
        }
        $offerProperties = [];
        if ($offersIblockId > 0) {
            $cursor = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                ['IBLOCK_ID' => $offersIblockId, 'PROPERTY_CML2_LINK' => $productId, 'ACTIVE' => 'Y'],
                false,
                false,
                ['ID']
            );
            while ($row = $cursor->Fetch()) {
                $offer = $loader->loadSingleElement($offersIblockId, (int)$row['ID'], null, true);
                foreach ($this->propertyExamples((array)($offer['properties'] ?? []), false) as $property) {
                    $code = $property['code'];
                    if (!isset($offerProperties[$code])) {
                        $offerProperties[$code] = $property;
                    } else {
                        $offerProperties[$code]['values'] = $this->uniqueValues(array_merge(
                            $offerProperties[$code]['values'],
                            $property['values']
                        ));
                    }
                }
            }
        }
        return [
            'productId' => $productId,
            'iblockId' => $productIblockId,
            'iblockType' => $this->iblockType($productIblockId),
            'sectionId' => (int)($product['sectionId'] ?? 0),
            'name' => (string)($product['name'] ?? ''),
            'productProperties' => $this->propertyExamples((array)($product['properties'] ?? []), true),
            'offerProperties' => array_values(array_filter($offerProperties, static fn(array $property): bool =>
                stripos((string)($property['code'] ?? ''), 'CALC_') === 0
            )),
            'availableProductProperties' => $this->propertyExamples((array)($product['properties'] ?? []), false),
            'availableOfferProperties' => array_values($offerProperties),
        ];
    }

    private function propertyExamples(array $properties, bool $onlyCalc): array
    {
        $result = [];
        foreach ($properties as $code => $property) {
            if (!is_array($property) || ($onlyCalc && stripos((string)$code, 'CALC_') !== 0)) {
                continue;
            }
            $rawValues = is_array($property['VALUE'] ?? null) ? $property['VALUE'] : [$property['VALUE'] ?? null];
            $rawXmlIds = is_array($property['VALUE_XML_ID'] ?? null) ? $property['VALUE_XML_ID'] : [$property['VALUE_XML_ID'] ?? null];
            $values = [];
            foreach ($rawValues as $index => $value) {
                $normalized = trim(is_scalar($value) ? (string)$value : '');
                $xmlId = trim(is_scalar($rawXmlIds[$index] ?? null) ? (string)$rawXmlIds[$index] : '');
                if (($property['PROPERTY_TYPE'] ?? '') === 'L' && ctype_digit($normalized)) {
                    $enum = \CIBlockPropertyEnum::GetByID((int)$normalized);
                    if (is_array($enum)) {
                        $normalized = trim((string)($enum['VALUE'] ?? $normalized));
                        $xmlId = trim((string)($enum['XML_ID'] ?? $xmlId));
                    }
                }
                if ($normalized !== '' || $xmlId !== '') {
                    $values[] = ['value' => $normalized, 'xmlId' => $xmlId];
                }
            }
            if ($values === []) {
                continue;
            }
            $result[] = [
                'code' => (string)$code,
                'title' => (string)($property['NAME'] ?? $code),
                'valueType' => ($property['MULTIPLE'] ?? 'N') === 'Y'
                    ? 'array'
                    : (($property['PROPERTY_TYPE'] ?? 'S') === 'N' ? 'number' : 'string'),
                'values' => $this->uniqueValues($values),
                'xmlIdContract' => '',
                'description' => '',
            ];
        }
        return $result;
    }

    private function iblockType(int $iblockId): string
    {
        $row = \CIBlock::GetByID($iblockId)->Fetch();
        return trim((string)($row['IBLOCK_TYPE_ID'] ?? ''));
    }

    private function uniqueValues(array $values): array
    {
        $seen = [];
        $result = [];
        foreach ($values as $value) {
            $key = ($value['value'] ?? '') . "\0" . ($value['xmlId'] ?? '');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $value;
            }
        }
        return $result;
    }

    private function buildProductTree(int $iblockId): array
    {
        $nodes = [];
        $sections = [];
        $cursor = \CIBlockSection::GetList(
            ['LEFT_MARGIN' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
            false,
            ['ID', 'NAME', 'CODE', 'IBLOCK_SECTION_ID', 'IBLOCK_ID']
        );
        while ($row = $cursor->Fetch()) {
            $id = (int)$row['ID'];
            $sections[$id] = [
                'type' => 'section',
                'id' => $id,
                'name' => (string)$row['NAME'],
                'code' => (string)$row['CODE'],
                'iblockId' => $iblockId,
                'parentId' => (int)($row['IBLOCK_SECTION_ID'] ?? 0) ?: null,
                'children' => [],
            ];
        }
        $cursor = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
            false,
            false,
            ['ID', 'NAME', 'CODE', 'IBLOCK_SECTION_ID', 'IBLOCK_ID']
        );
        while ($row = $cursor->Fetch()) {
            $node = [
                'type' => 'element',
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'code' => (string)$row['CODE'],
                'iblockId' => $iblockId,
                'sectionId' => (int)($row['IBLOCK_SECTION_ID'] ?? 0),
            ];
            $sectionId = (int)($row['IBLOCK_SECTION_ID'] ?? 0);
            if ($sectionId > 0 && isset($sections[$sectionId])) {
                $sections[$sectionId]['children'][] = $node;
            } else {
                $nodes[] = $node;
            }
        }
        foreach (array_keys($sections) as $id) {
            $parentId = (int)($sections[$id]['parentId'] ?? 0);
            if ($parentId > 0 && isset($sections[$parentId])) {
                $sections[$parentId]['children'][] = &$sections[$id];
            }
        }
        foreach ($sections as $section) {
            if (empty($section['parentId'])) {
                $nodes[] = $section;
            }
        }
        return $nodes;
    }

    private function ensureProperty(int $iblockId): void
    {
        if (\CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => self::PROPERTY_CODE])->Fetch()) {
            return;
        }
        $property = new \CIBlockProperty();
        if (!$property->Add([
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'CODE' => self::PROPERTY_CODE,
            'NAME' => 'Контекст AI-конструктора',
            'PROPERTY_TYPE' => 'S',
            'USER_TYPE' => 'HTML',
            'MULTIPLE' => 'N',
            'SORT' => 820,
        ])) {
            throw new \RuntimeException('Не удалось создать свойство AI_CONTEXT_JSON: ' . trim((string)$property->LAST_ERROR));
        }
    }

    private function includeModules(): void
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            throw new \RuntimeException('Не удалось подключить модули iblock/catalog');
        }
    }

    private function assertAdmin(): void
    {
        global $USER;
        if (!is_object($USER) || !$USER->IsAdmin()) {
            throw new \RuntimeException('Недостаточно прав для AI-конструктора');
        }
    }
}
