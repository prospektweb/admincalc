<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Bitrix-native section authority for the CALC_PRESETS calculator catalog.
 *
 * Sections and calculator placement live in the same iblock that owns preset
 * identities. There is deliberately no parallel module table to synchronize.
 */
final class CalculatorCatalogService
{
    public const CONTRACT = 'prospektweb.calc.calculator-catalog/v1';
    public const AUDIT_TYPE_ID = 'PROSPEKTWEB_CALCULATOR_CATALOG_V1';

    private const MODULE_ID = 'prospektweb.calc';

    /** @var array<string,callable> */
    private array $adapters;

    /** @param array<string,callable> $adapters */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $iblockId = $this->resolveIblockId();
        return $this->loadSnapshot($iblockId);
    }

    /** @return array<string,mixed> */
    public function createSection(string $name, int $parentId, string $expectedRevision): array
    {
        $name = $this->normalizeName($name);
        $this->assertNonNegativeId($parentId, 'parentId');
        return $this->mutate($expectedRevision, 'create_section', function (int $iblockId, array $before) use ($name, $parentId): array {
            $this->assertSectionExists($before, $parentId, true);
            $sectionId = isset($this->adapters['create_section'])
                ? (int)call_user_func($this->adapters['create_section'], $iblockId, $parentId, $name)
                : $this->createSectionInBitrix($iblockId, $parentId, $name);
            if ($sectionId <= 0) {
                throw new \RuntimeException('Bitrix did not return the created section ID.', 409);
            }
            return ['sectionId' => $sectionId, 'parentId' => $parentId, 'name' => $name];
        });
    }

    /** @return array<string,mixed> */
    public function renameSection(int $sectionId, string $name, string $expectedRevision): array
    {
        $this->assertPositiveId($sectionId, 'sectionId');
        $name = $this->normalizeName($name);
        return $this->mutate($expectedRevision, 'rename_section', function (int $iblockId, array $before) use ($sectionId, $name): array {
            $this->assertSectionExists($before, $sectionId);
            $updated = isset($this->adapters['update_section'])
                ? call_user_func($this->adapters['update_section'], $iblockId, $sectionId, ['NAME' => $name])
                : $this->updateSectionInBitrix($sectionId, ['NAME' => $name]);
            if ($updated === false) {
                throw new \RuntimeException('Bitrix rejected the section rename.', 409);
            }
            return ['sectionId' => $sectionId, 'name' => $name];
        });
    }

    /** @return array<string,mixed> */
    public function deleteSection(int $sectionId, string $expectedRevision): array
    {
        $this->assertPositiveId($sectionId, 'sectionId');
        return $this->mutate($expectedRevision, 'delete_section', function (int $iblockId, array $before) use ($sectionId): array {
            $section = $this->sectionById($before, $sectionId);
            $parentId = (int)$section['parentId'];
            $movedCalculatorIds = [];
            foreach ($before['calculators'] as $calculator) {
                if ((int)$calculator['sectionId'] !== $sectionId) {
                    continue;
                }
                $calculatorId = (int)$calculator['id'];
                $this->writeCalculatorSection($iblockId, $calculatorId, $parentId);
                $movedCalculatorIds[] = $calculatorId;
            }
            $movedSectionIds = [];
            foreach ($before['sections'] as $child) {
                if ((int)$child['parentId'] !== $sectionId) {
                    continue;
                }
                $childId = (int)$child['id'];
                $updated = isset($this->adapters['update_section'])
                    ? call_user_func(
                        $this->adapters['update_section'],
                        $iblockId,
                        $childId,
                        ['IBLOCK_SECTION_ID' => $parentId > 0 ? $parentId : false]
                    )
                    : $this->updateSectionInBitrix(
                        $childId,
                        ['IBLOCK_SECTION_ID' => $parentId > 0 ? $parentId : false]
                    );
                if ($updated === false) {
                    throw new \RuntimeException('Bitrix rejected moving a nested section.', 409);
                }
                $movedSectionIds[] = $childId;
            }
            $deleted = isset($this->adapters['delete_section'])
                ? call_user_func($this->adapters['delete_section'], $iblockId, $sectionId)
                : \CIBlockSection::Delete($sectionId);
            if ($deleted === false) {
                throw new \RuntimeException('Bitrix rejected deleting the section.', 409);
            }
            return [
                'sectionId' => $sectionId,
                'parentId' => $parentId,
                'movedCalculatorIds' => $movedCalculatorIds,
                'movedSectionIds' => $movedSectionIds,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function moveCalculator(int $calculatorId, int $sectionId, string $expectedRevision): array
    {
        $this->assertPositiveId($calculatorId, 'calculatorId');
        $this->assertNonNegativeId($sectionId, 'sectionId');
        return $this->mutate($expectedRevision, 'move_calculator', function (int $iblockId, array $before) use ($calculatorId, $sectionId): array {
            $this->assertSectionExists($before, $sectionId, true);
            $calculator = $this->calculatorById($before, $calculatorId);
            $previousSectionId = (int)$calculator['sectionId'];
            $this->writeCalculatorSection($iblockId, $calculatorId, $sectionId);
            return [
                'calculatorId' => $calculatorId,
                'previousSectionId' => $previousSectionId,
                'sectionId' => $sectionId,
            ];
        });
    }

    /** @return array<string,mixed> */
    private function mutate(string $expectedRevision, string $action, callable $write): array
    {
        $this->assertRevision($expectedRevision);
        return $this->withAuthority(function (int $iblockId) use ($expectedRevision, $action, $write): array {
            $before = $this->loadSnapshot($iblockId);
            if (!hash_equals((string)$before['revision'], strtolower($expectedRevision))) {
                throw new \RuntimeException(
                    'Структура калькуляторов уже изменена в другой сессии. Обновите каталог и повторите действие.',
                    409
                );
            }
            $receipt = $write($iblockId, $before);
            $after = $this->loadSnapshot($iblockId);
            if (hash_equals((string)$before['revision'], (string)$after['revision'])) {
                throw new \RuntimeException('Bitrix readback did not confirm the catalog change.', 409);
            }
            $this->assertMutationReadback($action, $receipt, $after);
            $this->writeAudit([
                'contract' => self::CONTRACT,
                'actorId' => $this->actorId(),
                'action' => $action,
                'beforeRevision' => $before['revision'],
                'afterRevision' => $after['revision'],
                'receipt' => $receipt,
                'result' => 'success',
            ]);
            return ['contract' => self::CONTRACT, 'receipt' => $receipt, 'catalog' => $after];
        });
    }

    /** @return array<string,mixed> */
    private function loadSnapshot(int $iblockId): array
    {
        $rawSections = isset($this->adapters['sections'])
            ? call_user_func($this->adapters['sections'], $iblockId)
            : $this->loadSectionsFromBitrix($iblockId);
        $rawCalculators = isset($this->adapters['calculators'])
            ? call_user_func($this->adapters['calculators'], $iblockId)
            : $this->loadCalculatorsFromBitrix($iblockId);
        if (!is_array($rawSections) || !is_array($rawCalculators)) {
            throw new \RuntimeException('Calculator catalog provider returned invalid data.');
        }

        $sections = [];
        foreach ($rawSections as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('Calculator catalog contains an invalid section row.');
            }
            $id = (int)($row['id'] ?? $row['ID'] ?? 0);
            $rowIblockId = (int)($row['iblockId'] ?? $row['IBLOCK_ID'] ?? 0);
            $name = trim((string)($row['name'] ?? $row['NAME'] ?? ''));
            if ($id <= 0 || $rowIblockId !== $iblockId || $name === '' || isset($sections[$id])) {
                throw new \RuntimeException('Calculator catalog contains a foreign or ambiguous section.');
            }
            $sections[$id] = [
                'id' => $id,
                'parentId' => max(0, (int)($row['parentId'] ?? $row['IBLOCK_SECTION_ID'] ?? 0)),
                'name' => $name,
                'sort' => (int)($row['sort'] ?? $row['SORT'] ?? 500),
                'active' => array_key_exists('active', $row)
                    ? (bool)$row['active']
                    : (string)($row['ACTIVE'] ?? 'Y') === 'Y',
            ];
        }
        foreach ($sections as $section) {
            $parentId = (int)$section['parentId'];
            if ($parentId > 0 && !isset($sections[$parentId])) {
                throw new \RuntimeException('Calculator catalog section tree contains a foreign parent.');
            }
        }
        $this->assertAcyclicSections($sections);

        $calculators = [];
        foreach ($rawCalculators as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('Calculator catalog contains an invalid calculator row.');
            }
            $id = (int)($row['id'] ?? $row['ID'] ?? 0);
            $rowIblockId = (int)($row['iblockId'] ?? $row['IBLOCK_ID'] ?? 0);
            $sectionId = max(0, (int)($row['sectionId'] ?? $row['IBLOCK_SECTION_ID'] ?? 0));
            if ($id <= 0 || $rowIblockId !== $iblockId || isset($calculators[$id])) {
                throw new \RuntimeException('Calculator catalog contains a foreign or ambiguous calculator.');
            }
            if ($sectionId > 0 && !isset($sections[$sectionId])) {
                throw new \RuntimeException('Calculator catalog contains a calculator in a foreign section.');
            }
            $calculators[$id] = ['id' => $id, 'sectionId' => $sectionId];
        }
        ksort($sections, SORT_NUMERIC);
        ksort($calculators, SORT_NUMERIC);

        $directCounts = [];
        $totalCounts = [];
        $unsectionedCount = 0;
        foreach ($calculators as $calculator) {
            $sectionId = (int)$calculator['sectionId'];
            if ($sectionId <= 0) {
                $unsectionedCount++;
                continue;
            }
            $directCounts[$sectionId] = ($directCounts[$sectionId] ?? 0) + 1;
            $visited = [];
            while ($sectionId > 0 && isset($sections[$sectionId]) && !isset($visited[$sectionId])) {
                $visited[$sectionId] = true;
                $totalCounts[$sectionId] = ($totalCounts[$sectionId] ?? 0) + 1;
                $sectionId = (int)$sections[$sectionId]['parentId'];
            }
        }
        $childCounts = [];
        foreach ($sections as $section) {
            $parentId = (int)$section['parentId'];
            if ($parentId > 0) {
                $childCounts[$parentId] = ($childCounts[$parentId] ?? 0) + 1;
            }
        }

        $canonicalSections = array_values($sections);
        $canonicalCalculators = array_values($calculators);
        $revision = hash('sha256', json_encode(
            ['iblockId' => $iblockId, 'sections' => $canonicalSections, 'calculators' => $canonicalCalculators],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        $resultSections = array_map(static function (array $section) use ($directCounts, $totalCounts, $childCounts): array {
            $id = (int)$section['id'];
            return $section + [
                'directCalculatorCount' => (int)($directCounts[$id] ?? 0),
                'calculatorCount' => (int)($totalCounts[$id] ?? 0),
                'childSectionCount' => (int)($childCounts[$id] ?? 0),
            ];
        }, $canonicalSections);

        return [
            'contract' => self::CONTRACT,
            'iblockId' => $iblockId,
            'revision' => $revision,
            'sections' => $resultSections,
            'calculators' => $canonicalCalculators,
            'calculatorCount' => count($canonicalCalculators),
            'unsectionedCount' => $unsectionedCount,
        ];
    }

    private function resolveIblockId(): int
    {
        if (isset($this->adapters['authority'])) {
            $iblockId = (int)call_user_func($this->adapters['authority']);
        } else {
            if (!Loader::includeModule('iblock')) {
                throw new \RuntimeException('The iblock module is unavailable.');
            }
            $iblockId = (int)(new ConfigManager())->getIblockId('CALC_PRESETS');
        }
        if ($iblockId <= 0) {
            throw new \RuntimeException('The CALC_PRESETS iblock is not configured.');
        }
        return $iblockId;
    }

    /** @return mixed */
    private function withAuthority(callable $criticalSection)
    {
        if (isset($this->adapters['with_authority'])) {
            return call_user_func($this->adapters['with_authority'], $criticalSection);
        }
        if (!Loader::includeModule('iblock') || !class_exists(Application::class)) {
            throw new \RuntimeException('Bitrix calculator catalog authority is unavailable.');
        }
        $connection = Application::getConnection();
        $authority = new CalculatorMutationAuthorityService();
        $connection->startTransaction();
        try {
            $locked = $authority->lockAllAuthority($connection);
            $iblockId = (int)($locked['iblockIds']['CALC_PRESETS'] ?? 0);
            if ($iblockId <= 0) {
                throw new \RuntimeException('Locked CALC_PRESETS authority is unavailable.', 409);
            }
            $result = $criticalSection($iblockId);
            $connection->commitTransaction();
            return $result;
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw $error;
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function loadSectionsFromBitrix(int $iblockId): array
    {
        $rows = [];
        $cursor = \CIBlockSection::GetList(
            ['LEFT_MARGIN' => 'ASC', 'SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId],
            false,
            ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'ACTIVE', 'SORT']
        );
        while ($cursor && ($row = $cursor->Fetch())) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadCalculatorsFromBitrix(int $iblockId): array
    {
        $rows = [];
        $cursor = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => $iblockId,
                '!%CODE' => PresetLifecycleMutationService::VERSION_WORKING_CODE_PREFIX,
            ],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID']
        );
        while ($cursor && ($row = $cursor->Fetch())) {
            $rows[] = $row;
        }
        return $rows;
    }

    private function createSectionInBitrix(int $iblockId, int $parentId, string $name): int
    {
        $section = new \CIBlockSection();
        $sectionId = (int)$section->Add([
            'IBLOCK_ID' => $iblockId,
            'IBLOCK_SECTION_ID' => $parentId > 0 ? $parentId : false,
            'ACTIVE' => 'Y',
            'SORT' => 500,
            'NAME' => $name,
            'CODE' => $this->uniqueSectionCode($iblockId, $name),
        ]);
        if ($sectionId <= 0) {
            throw new \RuntimeException($section->LAST_ERROR ?: 'Unable to create calculator section.', 409);
        }
        return $sectionId;
    }

    /** @param array<string,mixed> $fields */
    private function updateSectionInBitrix(int $sectionId, array $fields): bool
    {
        $section = new \CIBlockSection();
        $result = $section->Update($sectionId, $fields);
        if (!$result) {
            throw new \RuntimeException($section->LAST_ERROR ?: 'Unable to update calculator section.', 409);
        }
        return true;
    }

    private function writeCalculatorSection(int $iblockId, int $calculatorId, int $sectionId): void
    {
        $updated = isset($this->adapters['update_calculator'])
            ? call_user_func($this->adapters['update_calculator'], $iblockId, $calculatorId, $sectionId)
            : (new \CIBlockElement())->Update(
                $calculatorId,
                ['IBLOCK_SECTION_ID' => $sectionId > 0 ? $sectionId : false]
            );
        if ($updated === false) {
            throw new \RuntimeException('Bitrix rejected calculator placement.', 409);
        }
    }

    private function uniqueSectionCode(int $iblockId, string $name): string
    {
        $base = class_exists('CUtil')
            ? (string)\CUtil::translit($name, 'ru', ['replace_space' => '-', 'replace_other' => '-', 'change_case' => 'L'])
            : strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $name));
        $base = trim($base, '-_') ?: 'calculator-section';
        $candidate = $base;
        $suffix = 2;
        while (\CIBlockSection::GetList([], ['IBLOCK_ID' => $iblockId, '=CODE' => $candidate], false, ['ID'])->Fetch()) {
            $candidate = $base . '-' . $suffix++;
        }
        return $candidate;
    }

    /** @param array<int,array<string,mixed>> $sections */
    private function assertAcyclicSections(array $sections): void
    {
        foreach ($sections as $section) {
            $visited = [];
            $cursor = (int)$section['id'];
            while ($cursor > 0) {
                if (isset($visited[$cursor])) {
                    throw new \RuntimeException('Calculator catalog section tree contains a cycle.');
                }
                $visited[$cursor] = true;
                $cursor = isset($sections[$cursor]) ? (int)$sections[$cursor]['parentId'] : 0;
            }
        }
    }

    /** @return array<string,mixed> */
    private function sectionById(array $snapshot, int $sectionId): array
    {
        foreach ($snapshot['sections'] as $section) {
            if ((int)$section['id'] === $sectionId) {
                return $section;
            }
        }
        throw new \InvalidArgumentException('Раздел калькуляторов не найден.', 404);
    }

    /** @return array<string,mixed> */
    private function calculatorById(array $snapshot, int $calculatorId): array
    {
        foreach ($snapshot['calculators'] as $calculator) {
            if ((int)$calculator['id'] === $calculatorId) {
                return $calculator;
            }
        }
        throw new \InvalidArgumentException('Калькулятор не найден в CALC_PRESETS.', 404);
    }

    private function assertSectionExists(array $snapshot, int $sectionId, bool $allowRoot = false): void
    {
        if ($allowRoot && $sectionId === 0) {
            return;
        }
        $this->sectionById($snapshot, $sectionId);
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $after */
    private function assertMutationReadback(string $action, array $receipt, array $after): void
    {
        $sectionId = (int)($receipt['sectionId'] ?? 0);
        if ($action === 'create_section' || $action === 'rename_section') {
            $section = $this->sectionById($after, $sectionId);
            if ((string)$section['name'] !== (string)$receipt['name']) {
                throw new \RuntimeException('Calculator section readback does not match the write.', 409);
            }
            if ($action === 'create_section'
                && (int)$section['parentId'] !== (int)($receipt['parentId'] ?? 0)) {
                throw new \RuntimeException('Calculator section parent readback does not match the write.', 409);
            }
        } elseif ($action === 'delete_section') {
            foreach ($after['sections'] as $section) {
                if ((int)$section['id'] === $sectionId || (int)$section['parentId'] === $sectionId) {
                    throw new \RuntimeException('Deleted calculator section remains in the Bitrix tree.', 409);
                }
            }
            foreach ($after['calculators'] as $calculator) {
                if ((int)$calculator['sectionId'] === $sectionId) {
                    throw new \RuntimeException('Deleted calculator section still owns calculators.', 409);
                }
            }
        } elseif ($action === 'move_calculator') {
            $calculator = $this->calculatorById($after, (int)$receipt['calculatorId']);
            if ((int)$calculator['sectionId'] !== $sectionId) {
                throw new \RuntimeException('Calculator placement readback does not match the write.', 409);
            }
        }
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $length > 200) {
            throw new \InvalidArgumentException('Название раздела должно содержать от 1 до 200 символов.', 422);
        }
        return $name;
    }

    private function assertRevision(string $revision): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', strtolower($revision)) !== 1) {
            throw new \InvalidArgumentException('expected_revision must be a SHA-256 value.', 422);
        }
    }

    private function assertPositiveId(int $value, string $field): void
    {
        if ($value <= 0 || $value > 9007199254740991) {
            throw new \InvalidArgumentException($field . ' must be a safe positive integer.', 422);
        }
    }

    private function assertNonNegativeId(int $value, string $field): void
    {
        if ($value < 0 || $value > 9007199254740991) {
            throw new \InvalidArgumentException($field . ' must be a safe non-negative integer.', 422);
        }
    }

    /** @param array<string,mixed> $audit */
    private function writeAudit(array $audit): void
    {
        if (isset($this->adapters['audit'])) {
            $result = call_user_func($this->adapters['audit'], $audit);
        } else {
            $description = json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($description)) {
                throw new \RuntimeException('Unable to encode calculator catalog audit metadata.');
            }
            $result = class_exists('CEventLog') ? \CEventLog::Add([
                'SEVERITY' => 'SECURITY',
                'AUDIT_TYPE_ID' => self::AUDIT_TYPE_ID,
                'MODULE_ID' => self::MODULE_ID,
                'ITEM_ID' => (string)($audit['receipt']['sectionId'] ?? $audit['receipt']['calculatorId'] ?? ''),
                'DESCRIPTION' => $description,
            ]) : false;
        }
        if ($result === false) {
            throw new \RuntimeException('Calculator catalog audit write failed.');
        }
    }

    private function actorId(): int
    {
        if (isset($this->adapters['actor_id'])) {
            return max(0, (int)call_user_func($this->adapters['actor_id']));
        }
        $user = $GLOBALS['USER'] ?? null;
        return is_object($user) && method_exists($user, 'GetID') ? max(0, (int)$user->GetID()) : 0;
    }
}
