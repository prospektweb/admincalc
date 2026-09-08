<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Install;
require_once dirname(__DIR__) . '/Documents/ResourceCatalogRegistry.php';
require_once __DIR__ . '/SupplierDirectorySchemaService.php';

/** Schema only; existing resources are checked, never moved or populated. */
final class ResourceDirectoryInstaller
{
    public static function definitions(): array
    {
        $params = ['NAME' => 'Параметры', 'PROPERTY_TYPE' => 'S', 'MULTIPLE' => 'Y', 'MULTIPLE_CNT' => 1, 'WITH_DESCRIPTION' => 'Y'];
        $sources = ['NAME' => 'Ссылки на источники данных', 'PROPERTY_TYPE' => 'S', 'MULTIPLE' => 'Y', 'MULTIPLE_CNT' => 1, 'WITH_DESCRIPTION' => 'Y', 'SORT' => 510];
        $materials = ['PARAMETRS' => $params, 'SOURCE_LINKS' => $sources] + SupplierDirectorySchemaService::materialPropertySchema();
        $materials['SUPPLIERS']['LINK_CODE'] = 'CALC_SUPPLIERS';
        $supplier = SupplierDirectorySchemaService::supplierPropertySchema();
        $supplier['LINK_MATERIALS']['LINK_CODE'] = 'CALC_MATERIALS';
        return [
            'CALC_MATERIALS' => $materials,
            'CALC_MATERIALS_VARIANTS' => $materials,
            'CALC_OPERATIONS' => [
                'PARAMETRS' => $params, 'SOURCE_LINKS' => $sources,
                'SUPPORTED_EQUIPMENT_LIST' => ['NAME' => 'Поддерживаемое оборудование', 'PROPERTY_TYPE' => 'E', 'MULTIPLE' => 'Y', 'LINK_CODE' => 'CALC_EQUIPMENT'],
                'SUPPORTED_MATERIALS_VARIANTS_LIST' => ['NAME' => 'Поддерживаемые варианты материалов', 'PROPERTY_TYPE' => 'E', 'MULTIPLE' => 'Y', 'LINK_CODE' => 'CALC_MATERIALS_VARIANTS'],
            ],
            'CALC_OPERATIONS_VARIANTS' => ['PARAMETRS' => $params, 'SOURCE_LINKS' => $sources],
            'CALC_EQUIPMENT' => [
                'FIELDS' => ['NAME' => 'Поля печатной машины', 'PROPERTY_TYPE' => 'S'],
                'MIN_WIDTH' => ['NAME' => 'Мин. ширина, мм', 'PROPERTY_TYPE' => 'N'],
                'MIN_LENGTH' => ['NAME' => 'Мин. длина, мм', 'PROPERTY_TYPE' => 'N'],
                'MAX_WIDTH' => ['NAME' => 'Макс. ширина, мм', 'PROPERTY_TYPE' => 'N'],
                'MAX_LENGTH' => ['NAME' => 'Макс. длина, мм', 'PROPERTY_TYPE' => 'N'],
                'START_COST' => ['NAME' => 'Стоимость старта', 'PROPERTY_TYPE' => 'N'],
                'PARAMETRS' => $params, 'SOURCE_LINKS' => $sources,
            ],
            'CALC_SUPPLIERS' => $supplier,
        ];
    }

    public function install(): array
    {
        foreach (['iblock', 'catalog'] as $module) { if (!\Bitrix\Main\Loader::includeModule($module)) { throw new \RuntimeException('Required module unavailable: ' . $module); } }
        // Resource percentage prices are decoded at the adapter boundary, not as money.
        if (!\Bitrix\Main\Loader::includeModule('currency')) { throw new \RuntimeException('Resource pricing requires currency schema.'); }
        foreach (['PRC' => '%', 'MRG' => '% margin'] as $code => $label) {
            if (!\CCurrency::GetByID($code)) {
                if (!\CCurrency::Add(['CURRENCY' => $code, 'AMOUNT_CNT' => 1, 'AMOUNT' => 1, 'SORT' => 999, 'BASE' => 'N'])) { throw new \RuntimeException('Cannot create resource price mode.'); }
            }
            foreach (['ru', 'en'] as $language) {
                if (!\CCurrencyLang::GetByID($code, $language)
                    && !\CCurrencyLang::Add(['CURRENCY' => $code, 'LID' => $language, 'FORMAT_STRING' => '#', 'FULL_NAME' => $label, 'DEC_POINT' => '.', 'THOUSANDS_SEP' => ' ', 'DECIMALS' => 2])) { throw new \RuntimeException('Cannot create resource price mode label.'); }
            }
        }
        if (!\CIBlockType::GetByID('calculator_catalog')->Fetch()) {
            if (!(new \CIBlockType())->Add(['ID' => 'calculator_catalog', 'SECTIONS' => 'Y', 'IN_RSS' => 'N', 'LANG' => ['ru' => ['NAME' => 'Справочники калькулятора'], 'en' => ['NAME' => 'Calculator resources']]])) { throw new \RuntimeException('Cannot create resource directory type.'); }
        }
        $ids = [];
        foreach (\Prospektweb\Calc\Documents\ResourceCatalogRegistry::LABELS as $code => $name) {
            $cursor = \CIBlock::GetList(['ID' => 'ASC'], ['CODE' => $code, 'CHECK_PERMISSIONS' => 'N']);
            $rows = []; while ($row = $cursor->Fetch()) { $rows[] = $row; }
            if (count($rows) > 1 || ($rows && ($rows[0]['CODE'] !== $code || $rows[0]['IBLOCK_TYPE_ID'] !== 'calculator_catalog'))) { throw new \RuntimeException('Resource directory conflicts: ' . $code); }
            if ($rows) { $ids[$code] = (int)$rows[0]['ID']; continue; }
            $fields = ['ACTIVE' => 'Y', 'NAME' => $name, 'CODE' => $code, 'IBLOCK_TYPE_ID' => 'calculator_catalog',
                'SITE_ID' => [(string)\CSite::GetDefSite()], 'VERSION' => 2, 'WORKFLOW' => 'N', 'BIZPROC' => 'N',
                'GROUP_ID' => ['1' => 'X', '2' => 'D'], 'FIELDS' => ['CODE' => ['IS_REQUIRED' => 'Y', 'DEFAULT_VALUE' => ['UNIQUE' => 'Y']]]];
            if ($code === 'CALC_SUPPLIERS') { $fields['XML_ID'] = SupplierDirectorySchemaService::IBLOCK_XML_ID; }
            $ids[$code] = (int)(new \CIBlock())->Add($fields);
            if ($ids[$code] <= 0) { throw new \RuntimeException('Cannot create resource directory: ' . $code); }
        }
        foreach (self::definitions() as $code => $properties) {
            foreach ($properties as $property => $definition) {
                if (isset($definition['LINK_CODE'])) { $definition['LINK_IBLOCK_ID'] = $ids[$definition['LINK_CODE']]; unset($definition['LINK_CODE']); }
                $this->property($ids[$code], $property, $definition);
            }
        }
        foreach ([['CALC_MATERIALS', 'CALC_MATERIALS_VARIANTS'], ['CALC_OPERATIONS', 'CALC_OPERATIONS_VARIANTS']] as [$parent, $offers]) {
            $property = $this->property($ids[$offers], 'CML2_LINK', ['NAME' => 'Элемент каталога', 'PROPERTY_TYPE' => 'E', 'MULTIPLE' => 'N', 'LINK_IBLOCK_ID' => $ids[$parent], 'SORT' => 5]);
            $catalog = \CCatalog::GetByID($ids[$offers]);
            if (!$catalog) {
                if (!\CCatalog::Add(['IBLOCK_ID' => $ids[$offers], 'PRODUCT_IBLOCK_ID' => $ids[$parent], 'SKU_PROPERTY_ID' => $property])) { throw new \RuntimeException('Cannot create resource SKU relation.'); }
                $catalog = \CCatalog::GetByID($ids[$offers]);
            }
            if ((int)($catalog['PRODUCT_IBLOCK_ID'] ?? 0) !== $ids[$parent] || (int)($catalog['SKU_PROPERTY_ID'] ?? 0) !== $property) { throw new \RuntimeException('Resource SKU relation conflict.'); }
        }
        if (!\CCatalog::GetByID($ids['CALC_EQUIPMENT']) && !\CCatalog::Add(['IBLOCK_ID' => $ids['CALC_EQUIPMENT']])) { throw new \RuntimeException('Cannot create equipment catalog.'); }
        return $ids;
    }

    private function property(int $iblock, string $code, array $definition): int
    {
        $fields = $definition + ['PROPERTY_TYPE' => 'S', 'MULTIPLE' => 'N', 'IS_REQUIRED' => 'N', 'SORT' => 500];
        $cursor = \CIBlockProperty::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $iblock, 'CODE' => $code]);
        $rows = []; while ($row = $cursor->Fetch()) { $rows[] = $row; }
        if (count($rows) > 1) { throw new \RuntimeException('Duplicate resource property: ' . $code); }
        if (!$rows) {
            $id = (int)(new \CIBlockProperty())->Add($fields + ['IBLOCK_ID' => $iblock, 'CODE' => $code, 'ACTIVE' => 'Y']);
            if ($id <= 0) { throw new \RuntimeException('Cannot create resource property: ' . $code); }
            $rows[] = \CIBlockProperty::GetList([], ['ID' => $id])->Fetch();
        }
        $row = $rows[0];
        foreach (['PROPERTY_TYPE', 'MULTIPLE', 'USER_TYPE', 'LINK_IBLOCK_ID', 'WITH_DESCRIPTION'] as $field) {
            if (array_key_exists($field, $fields) && (string)($row[$field] ?? '') !== (string)$fields[$field]) { throw new \RuntimeException('Resource property schema conflict: ' . $code . '.' . $field); }
        }
        if ((int)($row['IBLOCK_ID'] ?? 0) !== $iblock || ($row['CODE'] ?? '') !== $code || ($row['ACTIVE'] ?? '') !== 'Y') { throw new \RuntimeException('Resource property readback failed: ' . $code); }
        return (int)$row['ID'];
    }
}
