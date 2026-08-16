<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Install/Preset12740NeutralInputMigrationService.php';
require_once dirname(__DIR__) . '/lib/Services/NeutralFormulaPolicy.php';
require_once dirname(__DIR__) . '/lib/Install/Preset12740NeutralGlobalSymbolMigrationService.php';
require_once dirname(__DIR__) . '/lib/Install/Preset12740NeutralGlobalSymbolCorrectionMigrationService.php';

use Prospektweb\Calc\Install\Preset12740NeutralGlobalSymbolCorrectionMigrationService as Correction;
use Prospektweb\Calc\Install\Preset12740NeutralGlobalSymbolMigrationService as V2;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$correctionClass = new ReflectionClass(Correction::class);
$v2Class = new ReflectionClass(V2::class);
$symbols = $correctionClass->getConstant('SYMBOLS');
$v2Symbols = $v2Class->getConstant('SYMBOLS');
$typed = $correctionClass->getConstant('TYPED_INITIALIZERS');
$declarations = $correctionClass->getConstant('DECLARATIONS');
$assert(
    Correction::CONTRACT === 'prospektweb.calc.preset-12740-neutral-global-symbol-ownership-correction/v1'
        && Correction::EXPECTED_MUTATION_COUNT === 8
        && Correction::EXPECTED_PROSPECTIVE_RUNTIME_ROW_COUNT === 37,
    'correction has its own immutable v1 contract and exact 8-of-37 scope'
);
$assert(
    Correction::BACKUP_OPTION === 'PRESET_12740_GLOBAL_OWNERSHIP_BACKUP_V1'
        && Correction::MARKER_OPTION === 'PRESET_12740_GLOBAL_OWNERSHIP_MIGRATION_V1',
    'correction evidence options are separate from immutable V2 evidence'
);
$assert(
    V2::CONTRACT === 'prospektweb.calc.preset-12740-neutral-global-symbol-migration/v2'
        && V2::EXPECTED_MUTATION_COUNT === 24
        && $symbols === $v2Symbols,
    'predecessor V2 public contract and canonical symbol formulas remain unchanged'
);

$state = [
    'presetId' => 12740,
    'iblockId' => 77,
    'config' => ['iblockId' => 77, 'calc' => ['value' => '77'], 'frontcalc' => ['value' => '77']],
    'active' => ['exists' => true, 'value' => 'Y'],
    'iblock' => ['id' => 77, 'code' => 'CALC_GLOBAL_VALUES', 'type' => 'calculator', 'active' => 'Y', 'version' => 2],
    'propertySchema' => [
        'DATA_TYPE' => ['id' => 1],
        'INITIAL_VALUE' => ['id' => 2],
        'KIND' => ['id' => 3],
        'PRESET_ID' => ['id' => 4],
    ],
    'rows' => [],
];
$append = static function (
    array &$target,
    int $id,
    string $code,
    string $kind,
    string $dataType,
    string $initialValue,
    bool $exists = true
): void {
    $target['rows'][] = [
        'id' => $id,
        'iblockId' => 77,
        'code' => $code,
        'title' => $code,
        'active' => 'Y',
        'sort' => 100,
        'description' => '',
        'descriptionType' => 'text',
        'presetId' => 12740,
        'kind' => $kind,
        'dataType' => $dataType,
        'initialValue' => $initialValue,
        'initialValueExists' => $exists,
    ];
};
foreach ($symbols as $code => $specification) {
    $append(
        $state,
        (int)$specification['id'],
        (string)$code,
        (string)$specification['kind'],
        (string)$specification['dataType'],
        (string)$specification['neutral']
    );
}
foreach ($typed as $code => $specification) {
    $literal = isset($declarations[$code])
        ? (string)$declarations[$code]['literal']
        : (string)$specification['neutral'];
    $append(
        $state,
        (int)$specification['id'],
        (string)$code,
        (string)$specification['kind'],
        (string)$specification['dataType'],
        $literal
    );
}
for ($offset = 1; $offset <= 13; $offset++) {
    $append($state, 14000 + $offset, 'safe_extra_' . $offset, 'constant', 'number', (string)$offset);
}
usort($state['rows'], static fn(array $left, array $right): int => $left['id'] <=> $right['id']);

$plan = Correction::buildPlan($state);
$assert(
    ($plan['status'] ?? '') === 'pending'
        && ($plan['ready'] ?? false) === true
        && count((array)($plan['mutations'] ?? [])) === 8
        && count((array)($plan['_nextState']['rows'] ?? [])) === 37,
    'exact production-shaped predecessor yields one atomic eight-row correction'
);
$assert(
    array_column($plan['mutations'], 'elementId') === [12838, 12839, 12840, 12902, 12903, 12977, 12980, 12981]
        && !array_key_exists('before', $plan['mutations'][0])
        && !array_key_exists('after', $plan['mutations'][0]),
    'public mutation summary exposes exact IDs without formulas or raw rows'
);
$corrected = $plan['_nextState'];
$complete = Correction::buildPlan($corrected);
$assert(
    ($complete['status'] ?? '') === 'complete'
        && ($complete['ready'] ?? false) === true
        && ($complete['correctedDeclarationCount'] ?? 0) === 8
        && ($complete['mutations'] ?? null) === [],
    'exact corrected target is complete and idempotent'
);
Correction::assertNeutralRuntimeRows($corrected['rows']);
$byId = [];
foreach ($corrected['rows'] as $row) {
    $byId[(int)$row['id']] = $row;
}
foreach ([12838, 12839, 12840, 12902, 12903, 12977, 12980, 12981] as $id) {
    $assert(
        ($byId[$id]['initialValue'] ?? null) === ''
            && ($byId[$id]['initialValueExists'] ?? null) === false,
        'corrected declaration #' . $id . ' is blank and physically absent'
    );
}
foreach ([12794, 12796] as $id) {
    $assert(
        trim((string)($byId[$id]['initialValue'] ?? '')) !== ''
            && ($byId[$id]['initialValueExists'] ?? null) === true,
        'supplemental initializer #' . $id . ' remains nonblank and stored'
    );
}
$identities = Correction::declarationIdentities();
$assert(
    count($identities) === 10
        && ($identities['is_self_adhesive_paper']['blank'] ?? true) === false
        && ($identities['is_uv_printing']['blank'] ?? true) === false
        && ($identities['print_sheet_thickness_initial_mm']['blank'] ?? false) === true,
    'supplemental rename guard distinguishes two initializers from eight declarations'
);

$badLiteral = $state;
foreach ($badLiteral['rows'] as &$row) {
    if ((int)$row['id'] === 12902) {
        $row['initialValue'] = '0.0';
    }
}
unset($row);
$assert(Correction::buildPlan($badLiteral)['status'] === 'blocked', 'first apply rejects a non-exact current literal');

$partial = $corrected;
foreach ($partial['rows'] as &$row) {
    if ((int)$row['id'] === 12902) {
        $row['initialValue'] = '0';
        $row['initialValueExists'] = true;
    }
}
unset($row);
$assert(Correction::buildPlan($partial)['status'] === 'blocked', 'mixed corrected/predecessor rows fail closed');

$wrongPresence = $corrected;
foreach ($wrongPresence['rows'] as &$row) {
    if ((int)$row['id'] === 12903) {
        $row['initialValueExists'] = true;
    }
}
unset($row);
$assert(Correction::buildPlan($wrongPresence)['status'] === 'blocked', 'stored empty HTML cannot impersonate SQL NULL');

$wrongKind = $state;
foreach ($wrongKind['rows'] as &$row) {
    if ((int)$row['id'] === 12838) {
        $row['kind'] = 'constant';
    }
}
unset($row);
$assert(Correction::buildPlan($wrongKind)['status'] === 'blocked', 'correction preserves authoritative kinds and types');

$extraAuthority = $state;
$append($extraAuthority, 15000, 'safe_extra_14', 'constant', 'number', '14');
$assert(Correction::buildPlan($extraAuthority)['status'] === 'blocked', 'migration lifecycle pins exact 37-row authority');
$extraRuntime = $corrected['rows'];
$appendState = ['rows' => $extraRuntime];
$append($appendState, 15000, 'safe_extra_14', 'constant', 'number', '14');
try {
    Correction::assertNeutralRuntimeRows($appendState['rows']);
    $assert(false, 'protected runtime must reject a 38th otherwise-safe row');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'protected runtime preserves the exact 37-row authority');
}
$assert(
    Correction::buildPlan($corrected)['status'] === 'complete',
    'rejected 38th row leaves the exact corrected runtime ready'
);

$forbidden = $corrected['rows'];
$forbidden[array_key_last($forbidden)]['initialValue'] = 'get(offer, "id")';
try {
    Correction::assertNeutralRuntimeRows($forbidden);
    $assert(false, 'forbidden entity roots must fail');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'forbidden entity roots fail closed');
}
$duplicate = $corrected['rows'];
$duplicate[array_key_last($duplicate)]['code'] = $duplicate[0]['code'];
try {
    Correction::assertNeutralRuntimeRows($duplicate);
    $assert(false, 'duplicate codes must fail');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'duplicate codes fail closed');
}

$canonical = $correctionClass->getMethod('encodeCanonical');
$canonical->setAccessible(true);
$fingerprint = $correctionClass->getMethod('fingerprint');
$fingerprint->setAccessible(true);
$historicalEvidence = $correctionClass->getMethod('assertHistoricalEvidence');
$historicalEvidence->setAccessible(true);
$backup = [
    'contract' => Correction::CONTRACT,
    'presetId' => 12740,
    'fingerprint' => $plan['fingerprint'],
    'state' => $state,
];
$backupRaw = $canonical->invoke(null, $backup);
$marker = [
    'contract' => Correction::CONTRACT,
    'presetId' => 12740,
    'beforeFingerprint' => $plan['fingerprint'],
    'afterFingerprint' => $complete['fingerprint'],
    'backupHash' => hash('sha256', $backupRaw),
    'appliedAt' => '2026-08-16T00:00:00+00:00',
];
$evidence = $historicalEvidence->invoke(null, json_encode($marker), $backupRaw);
$assert(
    is_array($evidence)
        && hash_equals((string)$marker['afterFingerprint'], (string)$fingerprint->invoke(null, $evidence['targetState'])),
    'marker and immutable backup reconstruct the exact blank-declaration target'
);

$source = file_get_contents(dirname(__DIR__) . '/lib/Install/Preset12740NeutralGlobalSymbolCorrectionMigrationService.php');
$v2Source = file_get_contents(dirname(__DIR__) . '/lib/Install/Preset12740NeutralGlobalSymbolMigrationService.php');
$inputSource = file_get_contents(dirname(__DIR__) . '/lib/Install/Preset12740NeutralInputMigrationService.php');
$globalSource = file_get_contents(dirname(__DIR__) . '/lib/Services/GlobalSymbolService.php');
$policySource = file_get_contents(dirname(__DIR__) . '/lib/Services/NeutralFormulaPolicy.php');
$initSource = file_get_contents(dirname(__DIR__) . '/lib/Calculator/InitPayloadService.php');
$refactorSource = file_get_contents(dirname(__DIR__) . '/lib/Services/GlobalCodeRefactorService.php');
$includeSource = file_get_contents(dirname(__DIR__) . '/include.php');
$diagnosticSource = file_get_contents(dirname(__DIR__) . '/lib/Diagnostic/ModuleDiagnostic.php');
$assert(
    is_string($source)
        && strpos($source, '=NULL') !== false
        && strpos($source, 'INITIAL_VALUE_RAW') !== false
        && strpos($source, 'assertExpectedActiveSnapshot($lockedActive, $expectedActive)') !== false
        && strpos($source, "!== 'N'") !== false,
    'apply uses exact SQL NULL read-back and ACTIVE Y/N CAS while rollback requires N'
);
$assert(
    is_string($v2Source)
        && strpos($v2Source, 'assertHistoricalCompletionReadyLocked(') !== false
        && strpos($v2Source, 'Preset12740NeutralGlobalSymbolCorrectionMigrationService') !== false,
    'V2 exposes non-recursive predecessor proof and delegates current lifecycle gates'
);
$assert(
    is_string($inputSource)
        && substr_count($inputSource, 'Preset12740NeutralGlobalSymbolCorrectionMigrationService') >= 2
        && strpos($inputSource, 'PRESET_12740_GLOBAL_OWNERSHIP_BACKUP_V1') !== false
        && strpos($inputSource, 'PRESET_12740_GLOBAL_OWNERSHIP_MIGRATION_V1') !== false,
    'V1 activation and rollback share correction evidence locks and gates'
);
$assert(
    is_string($globalSource)
        && substr_count($globalSource, 'Preset12740NeutralGlobalSymbolCorrectionMigrationService::assertNeutralRuntimeRows(') >= 2
        && strpos($globalSource, 'clearInitialValueStorageDirect(') !== false
        && strpos($globalSource, 'INITIAL_VALUE_RAW') !== false
        && strpos($globalSource, 'Protected preset 12740 global registry must remain exactly 37 rows.') !== false
        && strpos($globalSource, '$this->assertNeutralRowsBeforeWrite($rows, $iblockId, $presetId);')
            < strpos($globalSource, 'foreach ($rows as $rowIndex => $row) {'),
    'protected saves keep blank declarations clear-only and reject additions before writes'
);
$assert(
    is_string($policySource)
        && strpos($policySource, 'PRESET_12740_GLOBAL_OWNERSHIP_BACKUP_V1') !== false
        && strpos($policySource, '!$ownershipMarkerExists && $ownershipBackupExists') !== false,
    'authoring policy freezes retained correction backup recovery'
);
$policyClass = new ReflectionClass(\Prospektweb\Calc\Services\NeutralFormulaPolicy::class);
$policyOptions = $policyClass->getConstant('CONTRACT_OPTION_NAMES_BY_MODULE');
$assert(
    in_array(Correction::BACKUP_OPTION, $policyOptions['prospektweb.calc'] ?? [], true)
        && in_array(Correction::MARKER_OPTION, $policyOptions['prospektweb.calc'] ?? [], true),
    'direct option authority allowlist includes both correction evidence rows'
);
$assert(
    is_string($initSource)
        && substr_count($initSource, 'Preset12740NeutralGlobalSymbolCorrectionMigrationService::assertNeutralRuntimeRows(') >= 3
        && substr_count($initSource, '->assertActivationReady();') >= 2,
    'live INIT validates both corrected rows and immutable correction evidence'
);
$assert(
    is_string($refactorSource)
        && strpos($refactorSource, 'declarationIdentities()') !== false
        && strpos($refactorSource, '->assertActivationReadyLocked(true);') !== false,
    'refactor protects all supplemental identities and correction readiness'
);
$assert(
    is_string($includeSource)
        && strpos($includeSource, 'Preset12740NeutralGlobalSymbolCorrectionMigrationService') !== false
        && is_string($diagnosticSource)
        && strpos($diagnosticSource, 'Preset12740NeutralGlobalSymbolCorrectionMigrationService.php') !== false,
    'correction service is autoloaded and covered by module integrity diagnostics'
);

fwrite(STDOUT, "Preset 12740 global-symbol ownership correction tests passed\n");
