<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/CalculatorCatalogService.php';

use Prospektweb\Calc\Services\CalculatorCatalogService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$state = [
    'sections' => [
        10 => ['ID' => 10, 'IBLOCK_ID' => 77, 'IBLOCK_SECTION_ID' => 0, 'NAME' => 'Полиграфия', 'SORT' => 100, 'ACTIVE' => 'Y'],
        11 => ['ID' => 11, 'IBLOCK_ID' => 77, 'IBLOCK_SECTION_ID' => 10, 'NAME' => 'Листовая', 'SORT' => 100, 'ACTIVE' => 'Y'],
    ],
    'calculators' => [
        100 => ['ID' => 100, 'IBLOCK_ID' => 77, 'IBLOCK_SECTION_ID' => 11],
        101 => ['ID' => 101, 'IBLOCK_ID' => 77, 'IBLOCK_SECTION_ID' => 10],
        102 => ['ID' => 102, 'IBLOCK_ID' => 77, 'IBLOCK_SECTION_ID' => 0],
    ],
    'next_section_id' => 20,
];
$audits = [];

$service = new CalculatorCatalogService([
    'authority' => static fn(): int => 77,
    'with_authority' => static fn(callable $critical) => $critical(77),
    'sections' => static function () use (&$state): array {
        return array_values($state['sections']);
    },
    'calculators' => static function () use (&$state): array {
        return array_values($state['calculators']);
    },
    'create_section' => static function (int $iblockId, int $parentId, string $name) use (&$state): int {
        $id = $state['next_section_id']++;
        $state['sections'][$id] = [
            'ID' => $id,
            'IBLOCK_ID' => $iblockId,
            'IBLOCK_SECTION_ID' => $parentId,
            'NAME' => $name,
            'SORT' => 500,
            'ACTIVE' => 'Y',
        ];
        return $id;
    },
    'update_section' => static function (int $_iblockId, int $sectionId, array $fields) use (&$state): bool {
        if (!isset($state['sections'][$sectionId])) {
            return false;
        }
        if (array_key_exists('NAME', $fields)) {
            $state['sections'][$sectionId]['NAME'] = $fields['NAME'];
        }
        if (array_key_exists('IBLOCK_SECTION_ID', $fields)) {
            $state['sections'][$sectionId]['IBLOCK_SECTION_ID'] = (int)$fields['IBLOCK_SECTION_ID'];
        }
        return true;
    },
    'delete_section' => static function (int $_iblockId, int $sectionId) use (&$state): bool {
        if (!isset($state['sections'][$sectionId])) {
            return false;
        }
        unset($state['sections'][$sectionId]);
        return true;
    },
    'update_calculator' => static function (int $_iblockId, int $calculatorId, int $sectionId) use (&$state): bool {
        if (!isset($state['calculators'][$calculatorId])) {
            return false;
        }
        $state['calculators'][$calculatorId]['IBLOCK_SECTION_ID'] = $sectionId;
        return true;
    },
    'audit' => static function (array $audit) use (&$audits): bool {
        $audits[] = $audit;
        return true;
    },
    'actor_id' => static fn(): int => 1,
]);

$initial = $service->snapshot();
$assert($initial['contract'] === CalculatorCatalogService::CONTRACT, 'catalog contract is exact');
$assert($initial['calculatorCount'] === 3 && $initial['unsectionedCount'] === 1, 'root calculator counts are exact');
$rootSection = array_values(array_filter($initial['sections'], static fn(array $section): bool => $section['id'] === 10))[0];
$leafSection = array_values(array_filter($initial['sections'], static fn(array $section): bool => $section['id'] === 11))[0];
$assert($rootSection['directCalculatorCount'] === 1 && $rootSection['calculatorCount'] === 2, 'ancestor counts include descendants once');
$assert($leafSection['directCalculatorCount'] === 1 && $leafSection['calculatorCount'] === 1, 'leaf counts are exact');

$created = $service->createSection('Цифровая', 10, $initial['revision']);
$createdId = (int)$created['receipt']['sectionId'];
$assert($createdId === 20, 'new Bitrix section identity is returned');
$assert((int)$state['sections'][$createdId]['IBLOCK_SECTION_ID'] === 10, 'subsection is written below the requested parent');

$renamed = $service->renameSection($createdId, 'Цифровая печать', $created['catalog']['revision']);
$assert($state['sections'][$createdId]['NAME'] === 'Цифровая печать', 'section rename is authoritative');

$moved = $service->moveCalculator(102, $createdId, $renamed['catalog']['revision']);
$assert((int)$state['calculators'][102]['IBLOCK_SECTION_ID'] === $createdId, 'calculator is placed in the selected Bitrix section');

$deleted = $service->deleteSection(10, $moved['catalog']['revision']);
$assert(!isset($state['sections'][10]), 'only the requested section is deleted');
$assert(isset($state['sections'][11]) && (int)$state['sections'][11]['IBLOCK_SECTION_ID'] === 0, 'nested sections are promoted to the parent');
$assert(isset($state['sections'][20]) && (int)$state['sections'][20]['IBLOCK_SECTION_ID'] === 0, 'all direct subsections survive deletion');
$assert((int)$state['calculators'][101]['IBLOCK_SECTION_ID'] === 0, 'direct calculators are promoted to the parent');
$assert(count($state['calculators']) === 3, 'section deletion never deletes calculators');
$assert(count($audits) === 4, 'every catalog mutation is audited');

$staleRejected = false;
try {
    $service->renameSection(11, 'Устаревшая запись', $initial['revision']);
} catch (RuntimeException $error) {
    $staleRejected = $error->getCode() === 409;
}
$assert($staleRejected, 'stale catalog revisions are rejected before mutation');

echo "Calculator catalog service tests passed\n";
