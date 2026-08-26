<?php

require_once dirname(__DIR__) . '/lib/Services/CalculatorInputSourceCatalogService.php';

use Prospektweb\Calc\Services\CalculatorInputSourceCatalogService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$expectFailure = static function (callable $callback, string $message, ?int $code = null) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $error) {
        if ($code !== null) {
            $assert($error->getCode() === $code, $message . ' has the expected error code');
        }
        return;
    }
    $assert(false, $message);
};

$propertyRows = [
    14 => [
        ['ID' => '301', 'CODE' => 'TYPE_PAPER', 'NAME' => 'Тип бумаги', 'HINT' => 'Выберите подходящий тип бумаги', 'ACTIVE' => 'Y', 'PROPERTY_TYPE' => 'L', 'USER_TYPE' => '', 'MULTIPLE' => 'N'],
        ['ID' => '302', 'CODE' => '', 'NAME' => 'Без кода', 'ACTIVE' => 'Y', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => '', 'MULTIPLE' => 'N'],
        ['ID' => '304', 'CODE' => 'PAPER_DIRECTORY', 'NAME' => 'Справочник бумаги', 'ACTIVE' => 'Y', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'directory', 'MULTIPLE' => 'N', 'USER_TYPE_SETTINGS' => ['TABLE_NAME' => 'b_hlbd_paper']],
        ['ID' => '303', 'CODE' => 'INACTIVE', 'NAME' => 'Выключено', 'ACTIVE' => 'N', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => '', 'MULTIPLE' => 'N'],
    ],
    15 => [
        ['ID' => '902', 'CODE' => 'FORMAT_DIMENSIONS', 'NAME' => 'Размеры', 'ACTIVE' => 'Y', 'PROPERTY_TYPE' => 'L', 'USER_TYPE' => '', 'MULTIPLE' => 'Y'],
        ['ID' => '903', 'CODE' => 'URGENT', 'NAME' => 'Срочно', 'ACTIVE' => 'Y', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => '', 'MULTIPLE' => 'N'],
    ],
];
$enumRows = [
    301 => [
        ['ID' => '7001', 'XML_ID' => 'OFFSET', 'VALUE' => 'Офсетная'],
        ['ID' => '7002', 'XML_ID' => 'MEL', 'VALUE' => 'Мелованная'],
    ],
    902 => [
        ['ID' => '8001', 'XML_ID' => 'WIDTH', 'VALUE' => 'Ширина'],
        ['ID' => '8002', 'XML_ID' => 'LENGTH', 'VALUE' => 'Длина'],
    ],
];

$service = new CalculatorInputSourceCatalogService([
    'source_iblocks' => static fn(int $presetId): array => ['product' => 14, 'selected_offer' => 15],
    'property_rows' => static fn(int $iblockId, string $scope): array => $propertyRows[$iblockId] ?? [],
    'enum_rows' => static fn(int $propertyId): array => $enumRows[$propertyId] ?? [],
    'directory_rows' => static fn(array $property, int $propertyId): array => $propertyId === 304 ? [
        ['ID' => '9001', 'UF_XML_ID' => 'OFFSET_80', 'UF_NAME' => 'Офсетная 80 г/м²', 'UF_SORT' => 100],
        ['ID' => '9002', 'UF_XML_ID' => 'MEL_130', 'UF_NAME' => 'Мелованная 130 г/м²', 'UF_SORT' => 200],
    ] : [],
    'description_rows' => static fn(int $iblockId, int $propertyId, string $propertyCode, array $values): array => $propertyId === 301 ? [
        '14:301:MEL' => ['ID' => 1],
    ] : [],
]);

$catalog = $service->load(41);
$assert(
    array_keys($catalog) === ['contract', 'preset_id', 'product_iblock_id', 'offer_iblock_id', 'properties'],
    'source catalog has the exact read-only envelope'
);
$assert($catalog['contract'] === CalculatorInputSourceCatalogService::CONTRACT, 'source catalog is versioned');
$assert($catalog['preset_id'] === 41, 'source catalog is scoped to the requested preset');
$assert($catalog['product_iblock_id'] === 14 && $catalog['offer_iblock_id'] === 15, 'configured iblock identities are explicit');
$assert(count($catalog['properties']) === 4, 'only active properties with stable codes are selectable');
$assert($catalog['properties'][0] === [
    'scope' => 'product',
    'iblock_id' => 14,
    'property_id' => 301,
    'property_code' => 'TYPE_PAPER',
    'name' => 'Тип бумаги',
    'hint' => 'Выберите подходящий тип бумаги',
    'property_type' => 'L',
    'user_type' => '',
    'multiple' => false,
    'values' => [
        ['enum_id' => 7001, 'xml_id' => 'OFFSET', 'label' => 'Офсетная', 'sort' => 0, 'image_url' => '', 'has_description' => false],
        ['enum_id' => 7002, 'xml_id' => 'MEL', 'label' => 'Мелованная', 'sort' => 0, 'image_url' => '', 'has_description' => true],
    ],
    'admin_url' => '/bitrix/admin/iblock_edit_property.php?lang=ru&IBLOCK_ID=14&ID=301',
], 'product property exposes exact property and enum provenance');
$assert($catalog['properties'][1] === [
    'scope' => 'product',
    'iblock_id' => 14,
    'property_id' => 304,
    'property_code' => 'PAPER_DIRECTORY',
    'name' => 'Справочник бумаги',
    'hint' => '',
    'property_type' => 'S',
    'user_type' => 'directory',
    'multiple' => false,
    'values' => [
        ['enum_id' => 9001, 'xml_id' => 'OFFSET_80', 'label' => 'Офсетная 80 г/м²', 'sort' => 100, 'image_url' => '', 'has_description' => false],
        ['enum_id' => 9002, 'xml_id' => 'MEL_130', 'label' => 'Мелованная 130 г/м²', 'sort' => 200, 'image_url' => '', 'has_description' => false],
    ],
    'admin_url' => '/bitrix/admin/iblock_edit_property.php?lang=ru&IBLOCK_ID=14&ID=304',
], 'directory property exposes the same stable choice contract');
$assert(
    $catalog['properties'][2]['scope'] === 'selected_offer'
    && $catalog['properties'][2]['iblock_id'] === 15
    && $catalog['properties'][2]['property_id'] === 902
    && $catalog['properties'][2]['multiple'] === true,
    'offer property carries selected_offer scope without product inference'
);

$authority = $service->validationAuthority(41);
$assert($authority['product_iblock_id'] === 14 && $authority['offer_iblock_id'] === 15, 'validator uses the same iblock authority');
$assert($authority['properties']['product'][14][301] === [
    'scope' => 'product',
    'code' => 'TYPE_PAPER',
    'active' => true,
    'property_type' => 'L',
    'user_type' => '',
    'multiple' => false,
    'enum_xml_ids' => ['OFFSET', 'MEL'],
], 'validator projection is derived from the exact source catalog');
$assert(
    $authority['properties']['selected_offer'][15][902]['scope'] === 'selected_offer',
    'validator authority preserves entity scope independently of iblock identity'
);

$duplicateEnum = new CalculatorInputSourceCatalogService([
    'source_iblocks' => static fn(int $presetId): array => ['product' => 14, 'selected_offer' => 15],
    'property_rows' => static fn(int $iblockId, string $scope): array => $propertyRows[$iblockId] ?? [],
    'enum_rows' => static fn(int $propertyId): array => $propertyId === 301 ? [
        ['ID' => '1', 'XML_ID' => 'DUPLICATE', 'VALUE' => 'Первый'],
        ['ID' => '2', 'XML_ID' => 'DUPLICATE', 'VALUE' => 'Второй'],
    ] : ($enumRows[$propertyId] ?? []),
]);
$expectFailure(static fn() => $duplicateEnum->load(41), 'ambiguous enum XML_ID authority fails closed', 409);

$duplicateEnumId = new CalculatorInputSourceCatalogService([
    'source_iblocks' => static fn(int $presetId): array => ['product' => 14, 'selected_offer' => 15],
    'property_rows' => static fn(int $iblockId, string $scope): array => $propertyRows[$iblockId] ?? [],
    'enum_rows' => static fn(int $propertyId): array => $propertyId === 301 ? [
        ['ID' => '1', 'XML_ID' => 'FIRST', 'VALUE' => 'Первый'],
        ['ID' => '1', 'XML_ID' => 'SECOND', 'VALUE' => 'Второй'],
    ] : ($enumRows[$propertyId] ?? []),
]);
$expectFailure(static fn() => $duplicateEnumId->load(41), 'ambiguous enum ID authority fails closed', 409);

$incompleteEnum = new CalculatorInputSourceCatalogService([
    'source_iblocks' => static fn(int $presetId): array => ['product' => 14, 'selected_offer' => 15],
    'property_rows' => static fn(int $iblockId, string $scope): array => $propertyRows[$iblockId] ?? [],
    'enum_rows' => static fn(int $propertyId): array => $propertyId === 301 ? [
        ['ID' => '1', 'XML_ID' => '', 'VALUE' => 'Без стабильного XML_ID'],
    ] : ($enumRows[$propertyId] ?? []),
]);
$expectFailure(static fn() => $incompleteEnum->load(41), 'incomplete enum authority is never omitted or guessed', 409);

$invalidIblocks = new CalculatorInputSourceCatalogService([
    'source_iblocks' => static fn(int $presetId): array => ['product' => 14, 'offer' => 15],
    'property_rows' => static fn(int $iblockId, string $scope): array => [],
    'enum_rows' => static fn(int $propertyId): array => [],
]);
$expectFailure(static fn() => $invalidIblocks->load(41), 'scope aliases are not accepted', 409);

$source = file_get_contents(dirname(__DIR__) . '/lib/Services/CalculatorInputSourceCatalogService.php');
foreach (['CatalogAdapterDefinitionService', 'productProfiles', 'routes', 'families', '12740'] as $forbidden) {
    $assert(strpos($source, $forbidden) === false, 'source catalog contains no legacy or pilot authority: ' . $forbidden);
}

fwrite(STDOUT, "Calculator input source catalog service tests passed\n");
