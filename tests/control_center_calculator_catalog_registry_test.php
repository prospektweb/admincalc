<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/ControlCenterEditorsService.php';

use Prospektweb\Calc\Services\ControlCenterEditorsService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$receivedSectionId = null;
$createdSectionId = null;
$catalog = [
    'contract' => ControlCenterEditorsService::CALCULATOR_CATALOG_CONTRACT,
    'iblockId' => 77,
    'revision' => str_repeat('a', 64),
    'sections' => [
        ['id' => 10, 'parentId' => 0, 'name' => 'Полиграфия'],
        ['id' => 11, 'parentId' => 10, 'name' => 'Листовая печать'],
    ],
    'calculators' => [['id' => 12740, 'sectionId' => 11]],
    'calculatorCount' => 1,
    'unsectionedCount' => 0,
];

$service = new ControlCenterEditorsService(
    presetLoader: static fn(): array => [],
    presetListLoader: static function (
        string $_query,
        string $_status,
        string $_sort,
        int $_page,
        int $_pageSize,
        ?int $sectionId
    ) use (&$receivedSectionId): array {
        $receivedSectionId = $sectionId;
        return [
            '_serverPaged' => true,
            'rows' => [[
                'id' => 12740,
                'name' => 'Листовая печать',
                'active' => true,
                'sort' => 500,
                'updatedAt' => '2026-08-24 10:00:00',
                'sectionId' => 11,
            ]],
            'total' => 1,
        ];
    },
    presetCreator: static function (string $name, int $sectionId) use (&$createdSectionId): array {
        $createdSectionId = $sectionId;
        return [
            'presetId' => 12741,
            'presetName' => $name,
            'identityRevision' => str_repeat('b', 64),
        ];
    },
    presetUsageLoader: static fn(): array => [12740 => ['productCount' => 16, 'offerCount' => 278]],
    presetCatalogLoader: static fn(): array => $catalog
);

$registry = $service->getPresetRegistry('', 'all', 'updated_desc', 1, 50, 10);
$assert($receivedSectionId === 10, 'selected section is passed to the Bitrix-paged registry loader');
$assert($registry['sectionId'] === 10, 'registry echoes the exact selected section');
$assert($registry['rows'][0]['sectionId'] === 11, 'calculator direct section is explicit');
$assert(
    $registry['rows'][0]['sectionPath'] === [
        ['id' => 10, 'name' => 'Полиграфия'],
        ['id' => 11, 'name' => 'Листовая печать'],
    ],
    'calculator path is built from the authoritative nested Bitrix sections'
);

$created = $service->createStandalonePreset('Буклеты', 11);
$assert($createdSectionId === 11, 'new calculator is created directly in the selected Bitrix section');
$assert($created['presetId'] === 12741, 'created calculator identity is returned');

echo "Control center calculator catalog registry tests passed\n";
