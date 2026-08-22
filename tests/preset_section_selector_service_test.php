<?php

require_once dirname(__DIR__) . '/lib/Services/PresetSectionSelectorService.php';

use Prospektweb\Calc\Services\PresetSectionSelectorService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (callable $callback, string $messagePart) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $error) {
        $assert(strpos($error->getMessage(), $messagePart) !== false, 'Unexpected error: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected exception containing: ' . $messagePart);
};

$sections = [
    ['id' => 1, 'iblock_id' => 7, 'parent_id' => 0, 'name' => 'Root', 'depth' => 1],
    ['id' => 2, 'iblock_id' => 7, 'parent_id' => 1, 'name' => 'Child', 'depth' => 2],
    ['id' => 3, 'iblock_id' => 7, 'parent_id' => 1, 'name' => 'Sibling', 'depth' => 2],
];
$products = [
    ['id' => 10, 'iblock_id' => 7, 'name' => 'Ten', 'section_ids' => [1, 2]],
    ['id' => 11, 'iblock_id' => 7, 'name' => 'Eleven', 'section_ids' => [2]],
    ['id' => 11, 'iblock_id' => 7, 'name' => 'Eleven', 'section_ids' => [1]],
    ['id' => 12, 'iblock_id' => 7, 'name' => 'Twelve', 'section_ids' => [3]],
];
$service = new PresetSectionSelectorService([
    'authority' => static fn(int $presetId): array => ['product_iblock_id' => 7],
    'sections' => static fn(int $iblockId): array => $sections,
    'products' => static fn(int $presetId, int $iblockId): array => $products,
]);

$list = $service->listSections(12740);
$assert($list['contract'] === PresetSectionSelectorService::CONTRACT, 'section contract is exact');
$assert($list['preset_id'] === 12740 && $list['product_iblock_id'] === 7, 'authority is explicit');
$counts = array_column($list['sections'], 'count', 'id');
$assert($counts === [1 => 3, 2 => 2, 3 => 1], 'counts include descendants and deduplicate products');

$preview = $service->preview(12740, 2);
$assert($preview['include_subsections'] === true, 'subsections are always included');
$assert(array_column($preview['products'], 'id') === [10, 11], 'preview returns explicit deduplicated preset products');
$assert($preview['count'] === 2 && $preview['limit'] === 1000, 'preview exposes exact count and limit');
$throws(static fn() => $service->preview(12740, 999), 'configured product iblock');

$tooMany = [];
for ($id = 1; $id <= 1001; $id++) {
    $tooMany[] = ['id' => $id, 'iblock_id' => 7, 'name' => 'Product ' . $id, 'section_ids' => [2]];
}
$limited = new PresetSectionSelectorService([
    'authority' => static fn(int $presetId): array => ['product_iblock_id' => 7],
    'sections' => static fn(int $iblockId): array => $sections,
    'products' => static fn(int $presetId, int $iblockId): array => $tooMany,
]);
$throws(static fn() => $limited->preview(12740, 1), 'exceeds the storefront product limit');

$foreign = new PresetSectionSelectorService([
    'authority' => static fn(int $presetId): array => ['product_iblock_id' => 7],
    'sections' => static fn(int $iblockId): array => array_merge($sections, [[
        'id' => 99, 'iblock_id' => 8, 'parent_id' => 0, 'name' => 'Foreign', 'depth' => 1,
    ]]),
    'products' => static fn(int $presetId, int $iblockId): array => [],
]);
$throws(static fn() => $foreign->listSections(12740), 'foreign row');

echo "Preset section selector service tests passed\n";
