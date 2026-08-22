<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;

/** Read-only section helper for bulk-selecting current preset products. */
final class PresetSectionSelectorService
{
    public const CONTRACT = 'prospektweb.calc.preset-section-selector/v1';
    public const PRODUCT_LIMIT = 1000;

    /** @var callable */
    private $authorityLoader;

    /** @var callable */
    private $sectionLoader;

    /** @var callable */
    private $productLoader;

    public function __construct(array $adapters = [])
    {
        $this->authorityLoader = $adapters['authority'] ?? static function (int $presetId): array {
            if (!Loader::includeModule('iblock')) {
                throw new \RuntimeException('The iblock module is unavailable.');
            }
            $config = new ConfigManager();
            $productIblockId = (int)$config->getProductIblockId();
            $presetIblockId = (int)$config->getIblockId('CALC_PRESETS');
            if ($productIblockId <= 0 || $presetIblockId <= 0) {
                throw new \RuntimeException('Product or preset iblock is not configured.');
            }
            $preset = \CIBlockElement::GetList(
                [],
                ['ID' => $presetId, 'IBLOCK_ID' => $presetIblockId],
                false,
                ['nTopCount' => 1],
                ['ID']
            )->Fetch();
            if (!$preset) {
                throw new \InvalidArgumentException('Preset not found.');
            }
            return ['product_iblock_id' => $productIblockId];
        };
        $this->sectionLoader = $adapters['sections'] ?? static function (int $productIblockId): array {
            $rows = [];
            $cursor = \CIBlockSection::GetList(
                ['LEFT_MARGIN' => 'ASC', 'ID' => 'ASC'],
                ['IBLOCK_ID' => $productIblockId],
                false,
                ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'DEPTH_LEVEL']
            );
            while ($cursor && ($row = $cursor->Fetch())) {
                $rows[] = $row;
            }
            return $rows;
        };
        $this->productLoader = $adapters['products'] ?? static function (int $presetId, int $productIblockId): array {
            $presetIblockId = (int)(new ConfigManager())->getIblockId('CALC_PRESETS');
            $propertyAuthority = (new PresetProductAssignmentPropertyAuthorityService())->resolve(
                $productIblockId,
                $presetIblockId
            );
            $propertyId = (int)$propertyAuthority['propertyId'];
            $rows = [];
            $cursor = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                [
                    'IBLOCK_ID' => $productIblockId,
                    'ACTIVE' => 'Y',
                    'ACTIVE_DATE' => 'Y',
                    'PROPERTY_' . $propertyId => $presetId,
                ],
                false,
                false,
                ['ID', 'IBLOCK_ID', 'NAME']
            );
            while ($cursor && ($row = $cursor->Fetch())) {
                $productId = (int)($row['ID'] ?? 0);
                if ($productId <= 0 || isset($rows[$productId])) {
                    continue;
                }
                $sectionIds = [];
                $groups = \CIBlockElement::GetElementGroups($productId, false, ['ID', 'IBLOCK_ID']);
                while ($groups && ($group = $groups->Fetch())) {
                    if ((int)($group['IBLOCK_ID'] ?? 0) !== $productIblockId) {
                        continue;
                    }
                    $sectionId = (int)($group['ID'] ?? 0);
                    if ($sectionId > 0) {
                        $sectionIds[$sectionId] = $sectionId;
                    }
                }
                ksort($sectionIds, SORT_NUMERIC);
                $rows[$productId] = [
                    'id' => $productId,
                    'iblock_id' => (int)($row['IBLOCK_ID'] ?? 0),
                    'name' => (string)($row['NAME'] ?? ''),
                    'section_ids' => array_values($sectionIds),
                ];
            }
            return array_values($rows);
        };
    }

    /** @return array<string,mixed> */
    public function listSections(int $presetId): array
    {
        [$productIblockId, $sections, $products] = $this->snapshot($presetId);
        $counts = $this->intersectionCounts($sections, $products);
        $result = [];
        foreach ($sections as $section) {
            $sectionId = (int)$section['id'];
            $result[] = [
                'id' => $sectionId,
                'parent_id' => (int)$section['parent_id'],
                'name' => (string)$section['name'],
                'depth' => (int)$section['depth'],
                'count' => count($counts[$sectionId] ?? []),
            ];
        }
        return [
            'contract' => self::CONTRACT,
            'preset_id' => $presetId,
            'product_iblock_id' => $productIblockId,
            'limit' => self::PRODUCT_LIMIT,
            'sections' => $result,
        ];
    }

    /** @return array<string,mixed> */
    public function preview(int $presetId, int $sectionId): array
    {
        if ($sectionId <= 0) {
            throw new \InvalidArgumentException('section_id must be positive.');
        }
        [$productIblockId, $sections, $products] = $this->snapshot($presetId);
        $sectionMap = [];
        foreach ($sections as $section) {
            $sectionMap[(int)$section['id']] = $section;
        }
        if (!isset($sectionMap[$sectionId])) {
            throw new \InvalidArgumentException('Section does not belong to the configured product iblock.');
        }

        $descendants = [$sectionId => true];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($sections as $section) {
                $id = (int)$section['id'];
                $parentId = (int)$section['parent_id'];
                if (!isset($descendants[$id]) && isset($descendants[$parentId])) {
                    $descendants[$id] = true;
                    $changed = true;
                }
            }
        }

        $selected = [];
        foreach ($products as $product) {
            foreach ($product['section_ids'] as $productSectionId) {
                if (isset($descendants[$productSectionId])) {
                    $productId = (int)$product['id'];
                    $selected[$productId] = ['id' => $productId, 'name' => (string)$product['name']];
                    break;
                }
            }
        }
        ksort($selected, SORT_NUMERIC);
        if (count($selected) > self::PRODUCT_LIMIT) {
            throw new \InvalidArgumentException(
                'Section preview exceeds the storefront product limit of ' . self::PRODUCT_LIMIT . '.'
            );
        }
        $section = $sectionMap[$sectionId];
        return [
            'contract' => self::CONTRACT,
            'preset_id' => $presetId,
            'product_iblock_id' => $productIblockId,
            'section' => [
                'id' => $sectionId,
                'parent_id' => (int)$section['parent_id'],
                'name' => (string)$section['name'],
                'depth' => (int)$section['depth'],
            ],
            'include_subsections' => true,
            'products' => array_values($selected),
            'count' => count($selected),
            'limit' => self::PRODUCT_LIMIT,
        ];
    }

    /** @return array{0:int,1:array<int,array<string,mixed>>,2:array<int,array<string,mixed>>} */
    private function snapshot(int $presetId): array
    {
        if ($presetId <= 0 || $presetId > 9007199254740991) {
            throw new \InvalidArgumentException('preset_id must be a safe positive integer.');
        }
        $authority = call_user_func($this->authorityLoader, $presetId);
        $productIblockId = is_array($authority) ? (int)($authority['product_iblock_id'] ?? 0) : 0;
        if ($productIblockId <= 0) {
            throw new \RuntimeException('Product iblock authority is invalid.');
        }
        $sections = $this->normalizeSections(call_user_func($this->sectionLoader, $productIblockId), $productIblockId);
        $products = $this->normalizeProducts(
            call_user_func($this->productLoader, $presetId, $productIblockId),
            $productIblockId,
            $sections
        );
        return [$productIblockId, $sections, $products];
    }

    /** @param mixed $raw @return array<int,array<string,mixed>> */
    private function normalizeSections($raw, int $productIblockId): array
    {
        if (!is_array($raw)) {
            throw new \RuntimeException('Section provider returned invalid rows.');
        }
        $sections = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('Section provider returned an invalid row.');
            }
            $id = (int)($row['id'] ?? $row['ID'] ?? 0);
            $iblockId = (int)($row['iblock_id'] ?? $row['IBLOCK_ID'] ?? 0);
            $name = trim((string)($row['name'] ?? $row['NAME'] ?? ''));
            if ($id <= 0 || $iblockId !== $productIblockId || $name === '' || isset($sections[$id])) {
                throw new \RuntimeException('Section provider returned an ambiguous or foreign row.');
            }
            $sections[$id] = [
                'id' => $id,
                'parent_id' => max(0, (int)($row['parent_id'] ?? $row['IBLOCK_SECTION_ID'] ?? 0)),
                'name' => $name,
                'depth' => max(1, (int)($row['depth'] ?? $row['DEPTH_LEVEL'] ?? 1)),
            ];
        }
        foreach ($sections as $section) {
            $parentId = (int)$section['parent_id'];
            if ($parentId > 0 && !isset($sections[$parentId])) {
                throw new \RuntimeException('Section tree contains a foreign parent.');
            }
        }
        return array_values($sections);
    }

    /** @param mixed $raw @param array<int,array<string,mixed>> $sections @return array<int,array<string,mixed>> */
    private function normalizeProducts($raw, int $productIblockId, array $sections): array
    {
        if (!is_array($raw)) {
            throw new \RuntimeException('Product provider returned invalid rows.');
        }
        $sectionIds = [];
        foreach ($sections as $section) {
            $sectionIds[(int)$section['id']] = true;
        }
        $products = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('Product provider returned an invalid row.');
            }
            $id = (int)($row['id'] ?? $row['ID'] ?? 0);
            $iblockId = (int)($row['iblock_id'] ?? $row['IBLOCK_ID'] ?? 0);
            $name = trim((string)($row['name'] ?? $row['NAME'] ?? ''));
            if ($id <= 0 || $iblockId !== $productIblockId || $name === '') {
                throw new \RuntimeException('Product provider returned a foreign or invalid row.');
            }
            $normalizedSections = [];
            foreach ((array)($row['section_ids'] ?? []) as $rawSectionId) {
                $productSectionId = (int)$rawSectionId;
                if ($productSectionId > 0 && isset($sectionIds[$productSectionId])) {
                    $normalizedSections[$productSectionId] = $productSectionId;
                }
            }
            if (!isset($products[$id])) {
                $products[$id] = [
                    'id' => $id,
                    'name' => $name,
                    'section_ids' => [],
                ];
            }
            foreach ($normalizedSections as $productSectionId) {
                $products[$id]['section_ids'][$productSectionId] = $productSectionId;
            }
        }
        foreach ($products as &$product) {
            ksort($product['section_ids'], SORT_NUMERIC);
            $product['section_ids'] = array_values($product['section_ids']);
        }
        unset($product);
        ksort($products, SORT_NUMERIC);
        return array_values($products);
    }

    /** @return array<int,array<int,bool>> */
    private function intersectionCounts(array $sections, array $products): array
    {
        $parents = [];
        foreach ($sections as $section) {
            $parents[(int)$section['id']] = (int)$section['parent_id'];
        }
        $counts = [];
        foreach ($products as $product) {
            $productId = (int)$product['id'];
            foreach ($product['section_ids'] as $sectionId) {
                $visited = [];
                while ($sectionId > 0 && isset($parents[$sectionId]) && !isset($visited[$sectionId])) {
                    $visited[$sectionId] = true;
                    $counts[$sectionId][$productId] = true;
                    $sectionId = $parents[$sectionId];
                }
            }
        }
        return $counts;
    }
}
