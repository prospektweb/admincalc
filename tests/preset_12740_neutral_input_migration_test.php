<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Install/Preset12740NeutralInputMigrationService.php';

use Prospektweb\Calc\Install\Preset12740NeutralInputMigrationService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$state = [
    'presetId' => 12740,
    'presetIblockId' => 41,
    'detailsIblockId' => 43,
    'stagesIblockId' => 42,
    'bindingMap' => [
        'CALC_PROP_COLOR_SCHEME' => 'color.scheme',
        'CALC_PROP_FORMAT' => 'format',
        'CALC_PROP_LAMINATION_SIDES' => 'lamination.sides',
        'CALC_PROP_VOLUME' => 'volume',
    ],
    'globals' => [
        'GLOBAL_CONSTANTS' => [
            [
                'VALUE' => 'finished_item_width_mm',
                'DESCRIPTION' => 'get(split(get(offer, "properties.CALC_PROP_FORMAT.VALUE_XML_ID"), "x"), 0)|Определяется первой стороной формата торгового предложения CALC_PROP_FORMAT.',
            ],
            [
                'VALUE' => 'finished_item_length_mm',
                'DESCRIPTION' => 'get(split(get(offer, "properties.CALC_PROP_FORMAT.VALUE_XML_ID"), "x"), 1)|Определяется второй стороной формата торгового предложения CALC_PROP_FORMAT.',
            ],
            [
                'VALUE' => 'is_double_sided_printing',
                'DESCRIPTION' => 'if(toNumber(get(split(get(offer, "properties.CALC_PROP_COLOR_SCHEME.VALUE_XML_ID"), "+"), 1)) != 0, true, false)|Источник: цветовая схема торгового предложения.',
            ],
        ],
        'GLOBAL_VARIABLES' => [],
    ],
    'stages' => [
        '12748' => [
            'id' => 12748,
            'name' => 'Резерв количества печатных листов на тираж',
            'rows' => [[
                'VALUE' => 'offerCALC_PROP_VOLUME',
                'DESCRIPTION' => 'offer.properties.CALC_PROP_VOLUME.VALUE_XML_ID',
            ]],
        ],
        '12758' => [
            'id' => 12758,
            'name' => 'Резка в готовый формат',
            'rows' => [[
                'VALUE' => 'offerCALC_PROP_VOLUME',
                'DESCRIPTION' => 'offer.properties.CALC_PROP_VOLUME.VALUE_XML_ID',
            ]],
        ],
        '12781' => [
            'id' => 12781,
            'name' => 'Печатные пластины',
            'rows' => [[
                'VALUE' => 'color_scheme',
                'DESCRIPTION' => 'offer.properties.CALC_PROP_COLOR_SCHEME.VALUE_XML_ID',
            ]],
        ],
        '12786' => [
            'id' => 12786,
            'name' => 'Скругление углов',
            'rows' => [[
                'VALUE' => 'offerCALC_PROP_VOLUME',
                'DESCRIPTION' => 'offer.properties.CALC_PROP_VOLUME.VALUE_XML_ID',
            ]],
        ],
        '12841' => [
            'id' => 12841,
            'name' => 'Ламинация рулонная',
            'rows' => [[
                'VALUE' => 'lamination_sides_value',
                'DESCRIPTION' => 'offer.properties.CALC_PROP_LAMINATION_SIDES.VALUE_XML_ID',
            ]],
        ],
    ],
];

$plan = Preset12740NeutralInputMigrationService::buildPlan($state);
$assert($plan['status'] === 'pending' && $plan['ready'] === true, 'the exact audited preset shape is ready for one-time migration');
$assert(count($plan['mutations']) === 8, 'the migration changes exactly the eight audited rows');
$assert($plan['neutralReferenceCount'] === 8, 'the migrated snapshot contains exactly eight neutral references');
$assert($plan['fingerprint'] !== $plan['nextFingerprint'], 'the migration is protected by distinct before/after fingerprints');

$next = $plan['_nextState'];
$serialized = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert(is_string($serialized), 'the migrated snapshot can be encoded');
$assert(strpos($serialized, 'offer.properties') === false, 'the migrated calculation sources contain no offer path');
$assert(strpos($serialized, 'product.properties') === false, 'the migrated calculation sources contain no product path');
$assert(strpos($serialized, 'get(input, \\"values.format.width\\")') !== false, 'finished width reads the structured semantic width');
$assert(strpos($serialized, 'get(input, \\"values.format.height\\")') !== false, 'finished length reads the structured semantic height');
$assert(strpos($serialized, 'if(toNumber(get(split(get(input, \\"values.color.scheme\\"), \\"+\\"), 1)) != 0, true, false)') !== false, 'duplex detection preserves non-zero back-side semantics on the exact semantic color field');
$assert(strpos($serialized, 'split(get(input, \\"values.format') === false, 'structured dimensions must never be passed to the legacy string split formula');
$assert(strpos($serialized, 'input.values.volume') !== false, 'stage inputs read semantic form values');
$assert(strpos($serialized, 'поля формы «Формат»') !== false, 'author-facing descriptions explain the neutral source');

$complete = Preset12740NeutralInputMigrationService::buildPlan($next);
$assert($complete['status'] === 'complete' && $complete['ready'] === true, 'the verified migrated snapshot is idempotently complete');
$assert($complete['mutations'] === [] && $complete['neutralReferenceCount'] === 8, 'a complete snapshot requires all eight neutral references');
$assert($complete['fingerprint'] === $plan['nextFingerprint'], 'the read-back fingerprint matches the planned target');

$canonicalMethod = new ReflectionMethod(Preset12740NeutralInputMigrationService::class, 'encodeCanonical');
$canonicalMethod->setAccessible(true);
$evidenceMethod = new ReflectionMethod(Preset12740NeutralInputMigrationService::class, 'assertCompletionEvidence');
$evidenceMethod->setAccessible(true);
$backup = [
    'contract' => Preset12740NeutralInputMigrationService::CONTRACT,
    'presetId' => 12740,
    'fingerprint' => $plan['fingerprint'],
    'state' => $state,
];
$backupRaw = $canonicalMethod->invoke(null, $backup);
$marker = [
    'contract' => Preset12740NeutralInputMigrationService::CONTRACT,
    'presetId' => 12740,
    'beforeFingerprint' => $plan['fingerprint'],
    'afterFingerprint' => $complete['fingerprint'],
    'backupHash' => hash('sha256', $backupRaw),
    'appliedAt' => '2026-08-15T00:00:00+00:00',
];
$evidenceMethod->invoke(null, $complete, json_encode($marker), $backupRaw);
$expectEvidenceFailure = static function (array $candidateMarker, string $candidateBackup) use (
    $assert,
    $complete,
    $evidenceMethod
): void {
    try {
        $evidenceMethod->invoke(null, $complete, json_encode($candidateMarker), $candidateBackup);
    } catch (Throwable $error) {
        return;
    }
    $assert(false, 'complete preset requires matching migration evidence before activation');
};
$corruptMarker = $marker;
$corruptMarker['backupHash'] = str_repeat('0', 64);
$expectEvidenceFailure($corruptMarker, $backupRaw);
$expectEvidenceFailure([], '');

$normalizeConfigMethod = new ReflectionMethod(
    Preset12740NeutralInputMigrationService::class,
    'normalizeConfigSnapshotRows'
);
$normalizeConfigMethod->setAccessible(true);
$configSnapshot = $normalizeConfigMethod->invoke(null, [
    ['NAME' => 'IBLOCK_CALC_STAGES', 'VALUE' => '42', 'SITE_ID' => null],
    ['NAME' => 'IBLOCK_CALC_DETAILS', 'VALUE' => '43', 'SITE_ID' => ''],
    ['NAME' => 'IBLOCK_CALC_PRESETS', 'VALUE' => '41', 'SITE_ID' => null],
]);
$assert(
    ($configSnapshot['presetIblockId'] ?? 0) === 41
        && ($configSnapshot['detailsIblockId'] ?? 0) === 43
        && ($configSnapshot['stagesIblockId'] ?? 0) === 42,
    'the direct global config snapshot pins the exact three migration iblocks'
);
$assert(
    array_keys((array)($configSnapshot['options'] ?? [])) === [
        'IBLOCK_CALC_DETAILS',
        'IBLOCK_CALC_PRESETS',
        'IBLOCK_CALC_STAGES',
    ],
    'the raw config authority is canonicalized independently of database row order'
);
$expectConfigFailure = static function (array $rows, string $message) use (
    $assert,
    $normalizeConfigMethod
): void {
    try {
        $normalizeConfigMethod->invoke(null, $rows);
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, $message . ' fails closed as a stale authority conflict');
        return;
    }
    $assert(false, $message);
};
$expectConfigFailure([
    ['NAME' => 'IBLOCK_CALC_DETAILS', 'VALUE' => '43'],
    ['NAME' => 'IBLOCK_CALC_PRESETS', 'VALUE' => '41', 'SITE_ID' => null],
    ['NAME' => 'IBLOCK_CALC_PRESETS', 'VALUE' => '99', 'SITE_ID' => ''],
    ['NAME' => 'IBLOCK_CALC_STAGES', 'VALUE' => '42'],
], 'duplicate NULL/empty-site config authorities are rejected');
$expectConfigFailure([
    ['NAME' => 'IBLOCK_CALC_DETAILS', 'VALUE' => '43'],
    ['NAME' => 'IBLOCK_CALC_PRESETS', 'VALUE' => '41'],
], 'an incomplete config authority is rejected');
$expectConfigFailure([
    ['NAME' => 'IBLOCK_CALC_DETAILS', 'VALUE' => '43'],
    ['NAME' => 'IBLOCK_CALC_PRESETS', 'VALUE' => '41'],
    ['NAME' => 'IBLOCK_CALC_STAGES', 'VALUE' => '42-cache-poison'],
], 'a non-canonical config authority is rejected');

$membershipMethod = new ReflectionMethod(
    Preset12740NeutralInputMigrationService::class,
    'assertExactElementMembership'
);
$membershipMethod->setAccessible(true);
$membershipMethod->invoke(null, [12748, 12758], [
    ['ID' => 12748, 'IBLOCK_ID' => 42],
    ['ID' => 12758, 'IBLOCK_ID' => 42],
], 42, 'stage');
$expectMembershipFailure = static function (array $rows, string $message) use (
    $assert,
    $membershipMethod
): void {
    try {
        $membershipMethod->invoke(null, [12748, 12758], $rows, 42, 'stage');
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, $message . ' fails closed as a stale topology conflict');
        return;
    }
    $assert(false, $message);
};
$expectMembershipFailure([
    ['ID' => 12748, 'IBLOCK_ID' => 42],
], 'a dangling referenced stage is rejected before planning or writing');
$expectMembershipFailure([
    ['ID' => 12748, 'IBLOCK_ID' => 42],
    ['ID' => 12758, 'IBLOCK_ID' => 99],
], 'a referenced stage from the wrong iblock is rejected before planning or writing');

$partial = $next;
$partial['stages']['12748']['rows'][0]['DESCRIPTION'] = 'offer.properties.CALC_PROP_VOLUME.VALUE_XML_ID';
$partialPlan = Preset12740NeutralInputMigrationService::buildPlan($partial);
$assert($partialPlan['status'] === 'blocked' && $partialPlan['ready'] === false, 'a partial migration fails closed instead of silently finishing');

$missingBinding = $state;
unset($missingBinding['bindingMap']['CALC_PROP_LAMINATION_SIDES']);
$missingBindingPlan = Preset12740NeutralInputMigrationService::buildPlan($missingBinding);
$assert($missingBindingPlan['status'] === 'blocked', 'a missing published binding blocks migration');
$assert(count($missingBindingPlan['unresolved']) >= 1, 'a missing binding is reported for operator review');

$missingNeutralReference = $next;
array_pop($missingNeutralReference['stages']['12841']['rows']);
$missingNeutralPlan = Preset12740NeutralInputMigrationService::buildPlan($missingNeutralReference);
$assert($missingNeutralPlan['status'] === 'blocked', 'a supposedly complete snapshot missing one neutral source fails closed');

$unsupportedEntity = $state;
$unsupportedEntity['globals']['GLOBAL_VARIABLES'][] = [
    'VALUE' => 'legacy_product_name',
    'DESCRIPTION' => 'get(product, "name")|Unsupported direct product dependency.',
];
$unsupportedPlan = Preset12740NeutralInputMigrationService::buildPlan($unsupportedEntity);
$assert($unsupportedPlan['status'] === 'blocked', 'an unaudited product dependency blocks the one-time migration');

$serviceSource = file_get_contents(dirname(__DIR__) . '/lib/Install/Preset12740NeutralInputMigrationService.php');
$assert(is_string($serviceSource), 'migration service source is readable for transaction-boundary regression checks');
$applyStart = strpos($serviceSource, 'public function apply(');
$rollbackStart = strpos($serviceSource, 'public function rollback(');
$buildPlanStart = strpos($serviceSource, 'public static function buildPlan(');
$assert(is_int($applyStart) && is_int($rollbackStart) && is_int($buildPlanStart), 'migration method boundaries are discoverable');
$applySource = substr($serviceSource, $applyStart, $rollbackStart - $applyStart);
$rollbackSource = substr($serviceSource, $rollbackStart, $buildPlanStart - $rollbackStart);
$assert(
    strpos($rollbackSource, 'self::assertCompletionEvidence($currentPlan, $markerRaw, $backupRaw);') !== false,
    'rollback verifies the complete marker and backup evidence before restoring the preset'
);
foreach (['apply' => $applySource, 'rollback' => $rollbackSource] as $method => $methodSource) {
    $configRead = strpos($methodSource, '$initialConfigSnapshot = $this->readConfigSnapshot(false);');
    $topologyRead = strpos(
        $methodSource,
        '$initialState = $this->loadBitrixState($initialPublishedRaw, $initialConfigSnapshot);'
    );
    $transactionStart = strpos($methodSource, '$connection->startTransaction();');
    $assert(
        is_int($configRead) && is_int($topologyRead) && is_int($transactionStart)
            && $configRead < $topologyRead && $topologyRead < $transactionStart,
        $method . ' discovers direct raw config and element topology before opening the repeatable-read transaction'
    );
    $lockRows = strpos($methodSource, '$this->lockElements($initialState);');
    $lockedConfigRead = strpos($methodSource, '$lockedConfigSnapshot = $this->readConfigSnapshot(true);');
    $configCas = strpos(
        $methodSource,
        'self::assertConfigSnapshotUnchanged($initialConfigSnapshot, $lockedConfigSnapshot);'
    );
    $pinnedStateRead = strpos(
        $methodSource,
        '$currentState = $this->loadBitrixState($lockedPublishedRaw, $lockedConfigSnapshot);'
    );
    $assert(
        is_int($lockRows) && is_int($lockedConfigRead) && is_int($configCas) && is_int($pinnedStateRead)
            && $lockRows < $lockedConfigRead && $lockedConfigRead < $configCas && $configCas < $pinnedStateRead,
        $method . ' locks, rereads and CAS-checks config before loading state through pinned iblock ids'
    );
    if ($method === 'apply') {
        $activate = strpos($methodSource, "\$this->setGlobalOption(self::ACTIVE_OPTION, 'Y');");
        $assert(
            is_int($activate) && $configCas < $activate,
            'neutral runtime activation cannot precede the raw config CAS check'
        );
    }
}
$readOptionStart = strpos($serviceSource, 'private function readOptionRaw(');
$setGlobalStart = strpos($serviceSource, 'private function setGlobalOption(');
$assert(is_int($readOptionStart) && is_int($setGlobalStart), 'raw option reader boundary is discoverable');
$readOptionSource = substr($serviceSource, $readOptionStart, $setGlobalStart - $readOptionStart);
$assert(
    strpos($readOptionSource, "AND (SITE_ID IS NULL OR SITE_ID='')") !== false
        && strpos($readOptionSource, "(\$forUpdate ? ' FOR UPDATE' : '')") !== false
        && strpos($readOptionSource, '$duplicate') !== false,
    'raw option values are global-only, duplicate-rejecting and optionally locked without Bitrix cache'
);
$loadStateStart = strpos($serviceSource, 'private function loadBitrixState(');
$writeStateStart = strpos($serviceSource, 'private function writeAffectedState(');
$assert(is_int($loadStateStart) && is_int($writeStateStart), 'pinned Bitrix-state loader boundary is discoverable');
$loadStateSource = substr($serviceSource, $loadStateStart, $writeStateStart - $loadStateStart);
$assert(
    strpos($loadStateSource, 'Option::get') === false
        && strpos($loadStateSource, "\$pinnedConfigSnapshot['presetIblockId']") !== false
        && strpos($loadStateSource, "\$pinnedConfigSnapshot['detailsIblockId']") !== false
        && strpos($loadStateSource, "\$pinnedConfigSnapshot['stagesIblockId']") !== false
        && substr_count($loadStateSource, 'self::assertExactElementMembership(') === 2,
    'migration state never resolves iblock topology through a warm Option or ConfigManager cache'
);
$deleteGlobalStart = strpos($serviceSource, 'private function deleteGlobalOption(');
$storeBackupStart = strpos($serviceSource, 'private function storeBackup(');
$assert(is_int($deleteGlobalStart) && is_int($storeBackupStart), 'global option delete boundary is discoverable');
$deleteGlobalSource = substr($serviceSource, $deleteGlobalStart, $storeBackupStart - $deleteGlobalStart);
$assert(
    strpos($deleteGlobalSource, 'Option::delete') === false
        && strpos($deleteGlobalSource, "AND (SITE_ID IS NULL OR SITE_ID='')") !== false,
    'rollback deletes only the global marker and cannot erase per-site variants'
);
$lockStart = strpos($serviceSource, 'private function lockElements(');
$evidenceStart = strpos($serviceSource, 'private static function assertCompletionEvidence(');
$assert(is_int($lockStart) && is_int($evidenceStart), 'migration lock boundary is discoverable');
$lockSource = substr($serviceSource, $lockStart, $evidenceStart - $lockStart);
$assert(
    strpos($lockSource, "'IBLOCK_CALC_DETAILS','IBLOCK_CALC_PRESETS','IBLOCK_CALC_STAGES'") !== false
        && strpos($lockSource, "AND (SITE_ID IS NULL OR SITE_ID='')") !== false
        && strpos($lockSource, 'ORDER BY MODULE_ID, NAME, SITE_ID FOR UPDATE') !== false,
    'the shared deterministic option-lock order includes all three global config authorities'
);

echo "Preset 12740 neutral-input migration tests passed\n";
