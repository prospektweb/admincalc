<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/GlobalCalculatorMutationCoordinatorService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorSemanticMutationService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorRefreshActionRegistryService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorGlobalMutationService.php';

use Prospektweb\Calc\Services\CalculatorGlobalMutationService;
use Prospektweb\Calc\Services\GlobalCalculatorMutationCoordinatorService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = dirname(__DIR__);
$authoritySource = (string)file_get_contents($root . '/lib/Services/CalculatorMutationAuthorityService.php');
$globalSource = (string)file_get_contents($root . '/lib/Services/CalculatorGlobalMutationService.php');
$metaSource = (string)file_get_contents($root . '/lib/Services/CatalogMetaService.php');
$elementSource = (string)file_get_contents($root . '/lib/Calculator/ElementDataService.php');

foreach (['CALC_MATERIALS', 'CALC_MATERIALS_VARIANTS', 'CALC_OPERATIONS', 'CALC_OPERATIONS_VARIANTS'] as $code) {
    $assert(strpos($authoritySource, "'{$code}'") !== false, 'Global authority must include ' . $code);
}
$assert(strpos($authoritySource, 'lockConfiguredIblockIds(') !== false
    && strpos($authoritySource, 'FROM b_option') !== false
    && strpos($authoritySource, 'BINARY NAME IN (') !== false
    && strpos($authoritySource, 'SELECT ID, CODE FROM b_iblock WHERE ') !== false
    && strpos($authoritySource, 'CODE IN (') !== false,
    'Global authority must lock exact option mappings and complete code iblock identity sets');
$assert(strpos($globalSource, '$iblocks[$code] = $this->readIblock($iblockId)') !== false,
    'Global before/after readback must cover every pinned mutable iblock');
$assert(strpos($metaSource, 'private array $pinnedIblockIds') !== false
    && substr_count($metaSource, '$this->getIblocks($type, true)') === 3
    && strpos($metaSource, 'Catalog metadata mutation requires pinned calculator iblock authority.') !== false,
    'Catalog metadata writers must require pinned parent and variant iblocks');
$assert(substr_count($elementSource, 'new \\Prospektweb\\Calc\\Services\\CatalogMetaService(') === 4,
    'ElementData must pass pinned authority to every catalog metadata operation');

$equipmentStart = strpos($elementSource, "case 'saveSettingsEquipment':");
$equipmentEnd = strpos($elementSource, "case '", $equipmentStart + 10);
$equipment = $equipmentStart !== false && $equipmentEnd !== false
    ? substr($elementSource, $equipmentStart, $equipmentEnd - $equipmentStart)
    : '';
$assert($equipment !== ''
    && strpos($equipment, '$this->pinnedRuntimeIblockIds[\'CALC_EQUIPMENT\']') !== false
    && strpos($equipment, 'Option::get') === false,
    'Equipment writes must use only the pinned equipment iblock');

$revision = 10;
$state = [
    'CALC_MATERIALS' => ['name' => 'before'],
    'CALC_OPERATIONS' => ['name' => 'before'],
];
$audits = [];
$writerCalls = 0;
$iblockIds = [
    'CALC_PRESETS' => 1,
    'CALC_SETTINGS' => 2,
    'CALC_MATERIALS' => 31,
    'CALC_MATERIALS_VARIANTS' => 32,
    'CALC_OPERATIONS' => 41,
    'CALC_OPERATIONS_VARIANTS' => 42,
];
$coordinator = new GlobalCalculatorMutationCoordinatorService([
    'actor_id' => static fn(): int => 9,
    'audit' => static function (array $audit) use (&$audits): int {
        $audits[] = $audit;
        return count($audits);
    },
    'with_locked_revision' => static function (
        int $expectedRevision,
        callable $criticalSection
    ) use (&$revision, &$state, $iblockIds): array {
        if ($expectedRevision !== $revision) {
            throw new RuntimeException('stale revision', 409);
        }
        $snapshot = $state;
        try {
            $envelope = $criticalSection($revision, ['iblockIds' => $iblockIds], null);
            $revision = (int)$envelope['next_revision'];
            return $envelope;
        } catch (Throwable $error) {
            $state = $snapshot;
            throw $error;
        }
    },
]);
$service = new CalculatorGlobalMutationService([
    'coordinator' => static fn() => $coordinator,
    'state' => static function (array $lockedIds) use (&$state, $iblockIds): array {
        if ($lockedIds !== $iblockIds) {
            throw new RuntimeException('State read used a different iblock authority');
        }
        return $state;
    },
    'mutation' => static function (array $request, array $lockedIds) use (&$state, &$writerCalls): array {
        $writerCalls++;
        $state[(string)$request['iblockCode']] = ['name' => (string)$request['name']];
        return ['status' => 'ok', 'iblockId' => $lockedIds[(string)$request['iblockCode']]];
    },
    'affected_preset_ids' => static fn(): array => [12740],
]);

$fingerprint = CalculatorGlobalMutationService::fingerprintForRevision(10);
$service->mutatePayload([[
    'action' => 'saveCatalogTreeElement',
    'presetId' => 12740,
    'iblockCode' => 'CALC_MATERIALS',
    'iblockId' => 31,
    'name' => 'after',
]], 10, $fingerprint, 's1');
$assert($writerCalls === 1 && $revision === 11 && ($state['CALC_MATERIALS']['name'] ?? '') === 'after',
    'Pinned parent material mutation succeeds exactly once');
$assert(count($audits) === 1
    && ($audits[0]['beforeSha256'] ?? '') !== ($audits[0]['afterSha256'] ?? ''),
    'Parent material bytes must change the global authoritative audit hash');

$failedClosed = false;
try {
    $service->mutatePayload([[
        'action' => 'saveCatalogTreeElement',
        'presetId' => 12740,
        'iblockCode' => 'CALC_OPERATIONS',
        'iblockId' => 999,
        'name' => 'must-not-write',
    ]], 11, CalculatorGlobalMutationService::fingerprintForRevision(11), 's1');
} catch (Throwable $error) {
    $failedClosed = $error->getCode() === 422;
}
$assert($failedClosed && $writerCalls === 1 && $revision === 11 && count($audits) === 1,
    'Mismatched or repointed catalog target is rejected before write, revision and audit');

echo "Global pinned catalog authority tests passed\n";
