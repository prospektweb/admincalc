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

$multipleEncoder = new ReflectionMethod(Preset12740NeutralInputMigrationService::class, 'encodeMultiplePropertyRows');
$multipleEncoder->setAccessible(true);
$encodedRows = $multipleEncoder->invoke(null, [
    ['VALUE' => 'first', 'DESCRIPTION' => 'input.values.first'],
    ['VALUE' => 'second', 'DESCRIPTION' => 'input.values.second'],
]);
$assert(
    array_keys($encodedRows) === ['n0', 'n1']
        && ($encodedRows['n0']['DESCRIPTION'] ?? '') === 'input.values.first'
        && ($encodedRows['n1']['DESCRIPTION'] ?? '') === 'input.values.second',
    'Bitrix multi-value writes use new-value keys and preserve every VALUE/DESCRIPTION pair'
);

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
$resolveBackupMethod = new ReflectionMethod(
    Preset12740NeutralInputMigrationService::class,
    'resolveBackupRaw'
);
$resolveBackupMethod->setAccessible(true);
$assert(
    $resolveBackupMethod->invoke(null, $backup, $backupRaw) === $backupRaw,
    'retained V1 backup bytes are reused unchanged on an exact re-apply'
);
$rollbackResumeMethod = new ReflectionMethod(
    Preset12740NeutralInputMigrationService::class,
    'assertRollbackResumeEvidence'
);
$rollbackResumeMethod->setAccessible(true);
$rollbackResumePlan = $rollbackResumeMethod->invoke(
    null,
    $state,
    ['exists' => true, 'value' => 'N'],
    ['exists' => false, 'value' => ''],
    ['exists' => true, 'value' => $backupRaw]
);
$assert(
    ($rollbackResumePlan['rollbackResumeReady'] ?? false) === true
        && ($rollbackResumePlan['status'] ?? '') === 'pending'
        && count((array)($rollbackResumePlan['mutations'] ?? [])) === 8,
    'a committed V1 rollback with exact retained backup is a safe V2-only resume point'
);
$expectRollbackResumeFailure = static function (
    array $candidateState,
    array $active,
    array $markerState,
    array $backupState
) use ($assert, $rollbackResumeMethod): void {
    try {
        $rollbackResumeMethod->invoke(null, $candidateState, $active, $markerState, $backupState);
    } catch (Throwable $error) {
        return;
    }
    $assert(false, 'rollback resume evidence must fail closed when any V1 authority drifts');
};
$expectRollbackResumeFailure(
    $state,
    ['exists' => true, 'value' => ' Y '],
    ['exists' => false, 'value' => ''],
    ['exists' => true, 'value' => $backupRaw]
);
$expectRollbackResumeFailure(
    $state,
    ['exists' => true, 'value' => 'N'],
    ['exists' => true, 'value' => ''],
    ['exists' => true, 'value' => $backupRaw]
);
$changedRestoredState = $state;
$changedRestoredState['globals']['GLOBAL_CONSTANTS'][0]['DESCRIPTION'] .= ' ';
$expectRollbackResumeFailure(
    $changedRestoredState,
    ['exists' => true, 'value' => 'N'],
    ['exists' => false, 'value' => ''],
    ['exists' => true, 'value' => $backupRaw]
);
$alteredBackup = $backup;
$alteredBackup['unexpected'] = true;
try {
    $resolveBackupMethod->invoke(null, $alteredBackup, $backupRaw);
    $assert(false, 'same-fingerprint backup with altered body must fail before migration writes');
} catch (Throwable $error) {
    $assert(true, 'retained V1 backup authority is byte-exact, not fingerprint-only');
}
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
$reformattedBackupRaw = json_encode(
    $backup,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
$assert(is_string($reformattedBackupRaw), 'reformatted evidence fixture is encodable');
$expectEvidenceFailure(
    $marker,
    $reformattedBackupRaw
);
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
    ['NAME' => 'IBLOCK_CALC_SETTINGS', 'VALUE' => '44', 'SITE_ID' => null],
]);
$assert(
    ($configSnapshot['presetIblockId'] ?? 0) === 41
        && ($configSnapshot['detailsIblockId'] ?? 0) === 43
        && ($configSnapshot['settingsIblockId'] ?? 0) === 44
        && ($configSnapshot['stagesIblockId'] ?? 0) === 42,
    'the direct global config snapshot pins the exact four migration and activation iblocks'
);
$assert(
    array_keys((array)($configSnapshot['options'] ?? [])) === [
        'IBLOCK_CALC_DETAILS',
        'IBLOCK_CALC_PRESETS',
        'IBLOCK_CALC_SETTINGS',
        'IBLOCK_CALC_STAGES',
    ],
    'the raw config authority is canonicalized independently of database row order'
);
$lowercaseConfigSnapshot = $normalizeConfigMethod->invoke(null, [
    ['NAME' => 'iblock_calc_details', 'VALUE' => '50', 'SITE_ID' => null],
    ['NAME' => 'iblock_calc_presets', 'VALUE' => '41', 'SITE_ID' => null],
    ['NAME' => 'iblock_calc_settings', 'VALUE' => '44', 'SITE_ID' => null],
    ['NAME' => 'iblock_calc_stages', 'VALUE' => '42', 'SITE_ID' => null],
]);
$assert(
    ($lowercaseConfigSnapshot['presetIblockId'] ?? 0) === 41
        && ($lowercaseConfigSnapshot['detailsIblockId'] ?? 0) === 50
        && ($lowercaseConfigSnapshot['settingsIblockId'] ?? 0) === 44
        && ($lowercaseConfigSnapshot['stagesIblockId'] ?? 0) === 42,
    'the production lowercase global option rows resolve through Bitrix case-insensitive authority'
);
$assert(
    ($lowercaseConfigSnapshot['rowIdentities'] ?? []) === [
        'IBLOCK_CALC_DETAILS' => ['name' => 'iblock_calc_details', 'siteId' => null],
        'IBLOCK_CALC_PRESETS' => ['name' => 'iblock_calc_presets', 'siteId' => null],
        'IBLOCK_CALC_SETTINGS' => ['name' => 'iblock_calc_settings', 'siteId' => null],
        'IBLOCK_CALC_STAGES' => ['name' => 'iblock_calc_stages', 'siteId' => null],
    ],
    'the migration CAS snapshot preserves the exact lowercase database row identities'
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
    ['NAME' => 'IBLOCK_CALC_DETAILS', 'VALUE' => '43', 'SITE_ID' => null],
    ['NAME' => 'IBLOCK_CALC_PRESETS', 'VALUE' => '41', 'SITE_ID' => null],
    ['NAME' => 'IBLOCK_CALC_PRESETS', 'VALUE' => '99', 'SITE_ID' => ''],
    ['NAME' => 'IBLOCK_CALC_SETTINGS', 'VALUE' => '44', 'SITE_ID' => null],
    ['NAME' => 'IBLOCK_CALC_STAGES', 'VALUE' => '42', 'SITE_ID' => null],
], 'duplicate NULL/empty-site config authorities are rejected');
$expectConfigFailure([
    ['NAME' => 'iblock_calc_details', 'VALUE' => '50', 'SITE_ID' => null],
    ['NAME' => 'iblock_calc_presets', 'VALUE' => '41', 'SITE_ID' => null],
    ['NAME' => 'IBLOCK_CALC_PRESETS', 'VALUE' => '99', 'SITE_ID' => ''],
    ['NAME' => 'iblock_calc_settings', 'VALUE' => '44', 'SITE_ID' => null],
    ['NAME' => 'iblock_calc_stages', 'VALUE' => '42', 'SITE_ID' => null],
], 'mixed-case rows colliding on one canonical Bitrix option authority are rejected');
$expectConfigFailure([
    ['NAME' => 'IBLOCK_CALC_DETAILS', 'VALUE' => '43', 'SITE_ID' => null],
    ['NAME' => 'IBLOCK_CALC_PRESETS', 'VALUE' => '41', 'SITE_ID' => null],
], 'an incomplete config authority is rejected');
$expectConfigFailure([
    ['NAME' => 'IBLOCK_CALC_DETAILS', 'VALUE' => '43', 'SITE_ID' => null],
    ['NAME' => 'IBLOCK_CALC_PRESETS', 'VALUE' => '41', 'SITE_ID' => null],
    ['NAME' => 'IBLOCK_CALC_SETTINGS', 'VALUE' => '44', 'SITE_ID' => null],
    ['NAME' => 'IBLOCK_CALC_STAGES', 'VALUE' => '42-cache-poison', 'SITE_ID' => null],
], 'a non-canonical config authority is rejected');
$expectConfigFailure([
    ['NAME' => 'iblock_calc_details ', 'VALUE' => '50', 'SITE_ID' => null],
    ['NAME' => 'iblock_calc_presets', 'VALUE' => '41', 'SITE_ID' => null],
    ['NAME' => 'iblock_calc_settings', 'VALUE' => '44', 'SITE_ID' => null],
    ['NAME' => 'iblock_calc_stages', 'VALUE' => '42', 'SITE_ID' => null],
], 'a whitespace option-name alias is rejected instead of being trimmed');
$expectConfigFailure([
    ['NAME' => 'iblock_calc_details', 'VALUE' => '50', 'SITE_ID' => 's1'],
    ['NAME' => 'iblock_calc_presets', 'VALUE' => '41', 'SITE_ID' => null],
    ['NAME' => 'iblock_calc_settings', 'VALUE' => '44', 'SITE_ID' => null],
    ['NAME' => 'iblock_calc_stages', 'VALUE' => '42', 'SITE_ID' => null],
], 'a site-scoped option row cannot become the global migration authority');
$invalidRawValues = ['', ' ', ' 42 ', '042'];
foreach ($invalidRawValues as $invalidRawValue) {
    $expectConfigFailure([
        ['NAME' => 'IBLOCK_CALC_DETAILS', 'VALUE' => '43', 'SITE_ID' => null],
        ['NAME' => 'IBLOCK_CALC_PRESETS', 'VALUE' => '41', 'SITE_ID' => null],
        ['NAME' => 'IBLOCK_CALC_SETTINGS', 'VALUE' => '44', 'SITE_ID' => null],
        ['NAME' => 'IBLOCK_CALC_STAGES', 'VALUE' => $invalidRawValue, 'SITE_ID' => null],
    ], 'a non-exact raw iblock authority is rejected: ' . json_encode($invalidRawValue));
}

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
        $optionLock = strpos($methodSource, '$this->lockNeutralOptionAuthorities();');
        $v2Gate = strpos($methodSource, '->assertActivationReadyLocked(true);');
        $activate = strpos($methodSource, "\$this->setGlobalOption(self::ACTIVE_OPTION, 'Y');");
        $assert(
            is_int($optionLock) && is_int($v2Gate) && is_int($lockRows) && is_int($activate)
                && $transactionStart < $optionLock && $optionLock < $v2Gate
                && $v2Gate < $lockRows && $configCas < $activate,
            'the full neutral option superset precedes V2 registry and V1 formula rows before activation'
        );
        $assert(
            strpos($methodSource, '->assertActivationReady();') === false,
            'V1 activation never relies on a non-locking V2 evidence read'
        );
    } else {
        $optionLock = strpos($methodSource, '$this->lockNeutralOptionAuthorities();');
        $v2RollbackGate = strpos($methodSource, '->assertV1RollbackReadyLocked(true);');
        $assert(
            is_int($optionLock) && is_int($v2RollbackGate) && is_int($lockRows)
                && $transactionStart < $optionLock
                && $optionLock < $v2RollbackGate
                && $v2RollbackGate < $lockRows,
            'V1 rollback locks and revalidates exact V2 recovery evidence before any V1 formula row'
        );
    }
}
$readOptionStart = strpos($serviceSource, 'private function readOptionState(');
$readOptionRawStart = strpos($serviceSource, 'private function readOptionRaw(');
$setGlobalStart = strpos($serviceSource, 'private function setGlobalOption(');
$assert(
    is_int($readOptionStart) && is_int($readOptionRawStart) && is_int($setGlobalStart),
    'presence-aware raw option reader boundary is discoverable'
);
$readOptionSource = substr($serviceSource, $readOptionStart, $readOptionRawStart - $readOptionStart);
$assert(
    strpos($readOptionSource, "AND (SITE_ID IS NULL OR SITE_ID='')") !== false
        && strpos($readOptionSource, "(\$forUpdate ? ' FOR UPDATE' : '')") !== false
        && strpos($readOptionSource, '$duplicate') !== false
        && strpos($readOptionSource, "['exists' => false") !== false,
    'raw option values preserve missing versus empty, reject duplicates and optionally lock without Bitrix cache'
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
$optionLockStart = strpos($serviceSource, 'private function lockNeutralOptionAuthorities(');
$evidenceStart = strpos($serviceSource, 'private static function assertCompletionEvidence(');
$assert(
    is_int($lockStart) && is_int($optionLockStart) && is_int($evidenceStart),
    'migration element and option lock boundaries are discoverable'
);
$lockSource = substr($serviceSource, $optionLockStart, $evidenceStart - $optionLockStart);
$assert(
    strpos($lockSource, "'IBLOCK_CALC_DETAILS'") !== false
        && strpos($lockSource, "'IBLOCK_CALC_GLOBAL_VALUES'") !== false
        && strpos($lockSource, "'IBLOCK_CALC_PRESETS'") !== false
        && strpos($lockSource, "'IBLOCK_CALC_SETTINGS'") !== false
        && strpos($lockSource, "'IBLOCK_CALC_STAGES'") !== false
        && strpos($lockSource, "'PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_BACKUP_V1'") !== false
        && strpos($lockSource, "'PRESET_12740_NEUTRAL_INPUT_BACKUP_V1'") !== false
        && strpos($lockSource, "AND (SITE_ID IS NULL OR SITE_ID='')") !== false
        && strpos($lockSource, 'ORDER BY MODULE_ID, NAME, SITE_ID FOR UPDATE') !== false,
    'the deterministic option-lock superset includes all V1, V2 and formula/config authorities'
);
$elementLockSource = substr($serviceSource, $lockStart, $optionLockStart - $lockStart);
$assert(
    strpos($elementLockSource, 'b_option') === false
        && strpos($elementLockSource, 'b_iblock_element') !== false,
    'V1 formula element locks cannot acquire a late option row after the V2 registry lock'
);
$policySource = file_get_contents(dirname(__DIR__) . '/lib/Services/NeutralFormulaPolicy.php');
$assert(
    is_string($policySource)
        && strpos($policySource, 'ConfigManager') === false
        && strpos($policySource, 'CalculatorContractService') === false
        && strpos($policySource, 'ORDER BY MODULE_ID, NAME, SITE_ID') !== false
        && strpos($policySource, "(\$forUpdate ? ' FOR UPDATE' : '')") !== false,
    'activation and protected authoring use direct ordered option authorities, never warm config caches'
);
$assert(
    strpos($serviceSource, 'public function assertCompletionReady(): array') !== false
        && strpos($serviceSource, 'public function assertRollbackResumeReady(): array') !== false
        && strpos($serviceSource, 'self::assertCompletionEvidence(') !== false,
    'operational read-only gates cover both completed V1 evidence and an exact retained-backup rollback resume state'
);

echo "Preset 12740 neutral-input migration tests passed\n";
