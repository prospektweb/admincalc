<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Install/Preset12740NeutralInputMigrationService.php';
require_once dirname(__DIR__) . '/lib/Services/NeutralFormulaPolicy.php';
require_once dirname(__DIR__) . '/lib/Install/Preset12740NeutralGlobalSymbolMigrationService.php';
require_once dirname(__DIR__) . '/lib/Services/GlobalCodeRefactorService.php';

use Prospektweb\Calc\Install\Preset12740NeutralGlobalSymbolMigrationService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$symbolsProperty = new ReflectionClass(Preset12740NeutralGlobalSymbolMigrationService::class);
$symbols = $symbolsProperty->getConstant('SYMBOLS');
$assert(is_array($symbols) && count($symbols) === 14, 'migration owns the exact fourteen audited global symbols');
$expectedTargets = [
    12777 => ['is_roll_lamination', 'boolean', 'contains(get(input, "values.protection"), "lamination-rulon")'],
    12780 => ['is_offset_printing', 'boolean', 'get(input, "values.method") == "OFSET"'],
    12787 => ['is_pouch_lamination', 'boolean', 'contains(get(input, "values.protection"), "lamination-pocket")'],
    12790 => ['is_digital_printing', 'boolean', 'get(input, "values.method") == "DIGITAL"'],
    12791 => ['is_coated_paper', 'boolean', 'get(input, "values.type.paper") == "mel-paper"'],
    12792 => ['is_offset_paper', 'boolean', 'get(input, "values.type.paper") == "vhi-paper"'],
    12793 => ['is_designer_paper', 'boolean', 'get(input, "values.type.paper") == "shyne" || get(input, "values.type.paper") == "plake" || get(input, "values.type.paper") == "gmund" || get(input, "values.type.paper") == "aquarello" || get(input, "values.type.paper") == "design-paper"'],
    12797 => ['has_rounded_corners', 'boolean', 'contains(get(input, "values.options"), "round-corners")'],
    12925 => ['finished_item_qty', 'number', 'toNumber(get(input, "values.volume"))'],
    12976 => ['has_holes', 'boolean', 'contains(get(input, "values.options"), "round-holes")'],
    12978 => ['product_name', 'string', '"Листовая продукция"'],
    12979 => ['value_format_text', 'string', 'toString(get(input, "values.format.width")) + "×" + toString(get(input, "values.format.height")) + " мм"'],
    13085 => ['is_text_filling_printing', 'boolean', 'get(input, "values.filling") == "text"'],
    13093 => ['is_standart_filling_printing', 'boolean', '(get(input, "values.filling") == "standart") || (get(input, "values.filling") != "text")'],
];
foreach ($expectedTargets as $id => [$code, $dataType, $formula]) {
    $actual = $symbols[$code] ?? null;
    $assert(
        is_array($actual)
            && ($actual['id'] ?? null) === $id
            && ($actual['kind'] ?? null) === 'constant'
            && ($actual['dataType'] ?? null) === $dataType
            && ($actual['neutral'] ?? null) === $formula,
        'symbol #' . $id . ' has the independently reviewed code, type and neutral formula'
    );
}

$makeState = static function (array $formulaOverrides = [], array $rowOverrides = []) use ($symbols): array {
    $rows = [];
    foreach ($symbols as $code => $specification) {
        $rows[] = array_merge([
            'id' => $specification['id'],
            'iblockId' => 77,
            'code' => $code,
            'title' => $code,
            'active' => 'Y',
            'sort' => 100,
            'description' => '',
            'descriptionType' => 'text',
            'presetId' => 12740,
            'kind' => $specification['kind'],
            'dataType' => $specification['dataType'],
            'initialValue' => $formulaOverrides[$code] ?? $specification['legacy'],
        ], $rowOverrides[$code] ?? []);
    }
    return [
        'presetId' => 12740,
        'iblockId' => 77,
        'config' => [
            'exists' => true,
            'moduleId' => 'prospektweb.calc',
            'name' => 'iblock_calc_global_values',
            'siteId' => null,
            'value' => '77',
            'iblockId' => 77,
        ],
        'active' => [
            'exists' => true,
            'moduleId' => 'prospektweb.calc',
            'name' => 'preset_12740_neutral_input_active',
            'siteId' => null,
            'value' => 'Y',
        ],
        'iblock' => [
            'id' => 77,
            'code' => 'CALC_GLOBAL_VALUES',
            'type' => 'calculator',
            'active' => 'Y',
            'version' => 2,
        ],
        'propertySchema' => [
            'DATA_TYPE' => ['id' => 2, 'code' => 'DATA_TYPE', 'active' => 'Y', 'type' => 'S', 'userType' => '', 'multiple' => 'N'],
            'INITIAL_VALUE' => ['id' => 3, 'code' => 'INITIAL_VALUE', 'active' => 'Y', 'type' => 'S', 'userType' => 'HTML', 'multiple' => 'N'],
            'KIND' => ['id' => 1, 'code' => 'KIND', 'active' => 'Y', 'type' => 'S', 'userType' => '', 'multiple' => 'N'],
            'PRESET_ID' => ['id' => 4, 'code' => 'PRESET_ID', 'active' => 'Y', 'type' => 'N', 'userType' => '', 'multiple' => 'N'],
        ],
        'rows' => $rows,
    ];
};

$legacyState = $makeState();
$plan = Preset12740NeutralGlobalSymbolMigrationService::buildPlan($legacyState);
$assert($plan['status'] === 'pending' && $plan['ready'] === true, 'the exact production legacy registry is ready');
$assert(count($plan['mutations']) === 14, 'all fourteen formulas are migrated atomically');
$assert($plan['neutralSymbolCount'] === 0, 'the legacy snapshot has no neutral formulas');
$assert($plan['fingerprint'] !== $plan['nextFingerprint'], 'before and after snapshots have distinct fingerprints');

$next = $plan['_nextState'];
$complete = Preset12740NeutralGlobalSymbolMigrationService::buildPlan($next);
$assert($complete['status'] === 'complete' && $complete['ready'] === true, 'the target registry is idempotently complete');
$assert($complete['mutations'] === [] && $complete['neutralSymbolCount'] === 14, 'complete means all fourteen exact targets');
$assert($complete['fingerprint'] === $plan['nextFingerprint'], 'read-back matches the planned target fingerprint');
Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows($next['rows']);
$customNeutralRows = $next['rows'];
foreach ($customNeutralRows as &$customNeutralRow) {
    if (($customNeutralRow['code'] ?? '') === 'finished_item_qty') {
        $customNeutralRow['initialValue'] = 'toNumber(get(input, "values.volume")) + 0';
    }
}
unset($customNeutralRow);
Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows($customNeutralRows);
$safeExtraRows = $customNeutralRows;
$safeExtraRows[] = [
    'id' => 14001,
    'code' => 'safe_extra',
    'presetId' => 12740,
    'active' => 'Y',
    'kind' => 'variable',
    'dataType' => 'number',
    'initialValue' => 'get(input, "values.volume") + 1',
];
Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows($safeExtraRows);
$refactor = new \Prospektweb\Calc\Services\GlobalCodeRefactorService();
$normalizeRenames = new ReflectionMethod($refactor, 'normalizeRenames');
$normalizeRenames->setAccessible(true);
foreach (['input', 'Get', 'stage_42', '__Proto__'] as $reservedRenameTarget) {
    try {
        $normalizeRenames->invoke($refactor, [[
            'source' => 'registry',
            'registryId' => 14001,
            'oldCode' => 'safe_extra',
            'newCode' => $reservedRenameTarget,
        ]]);
        $assert(false, 'global-code refactor must reject reserved target ' . $reservedRenameTarget);
    } catch (Throwable $error) {
        $assert(true, 'global-code refactor shares the runtime reserved namespace');
    }
}
$prospectiveRows = new ReflectionMethod($refactor, 'buildProspectiveNeutralRows');
$prospectiveRows->setAccessible(true);
$safeRenamedRows = $prospectiveRows->invoke($refactor, $safeExtraRows, [[
    'kind' => 'element_code',
    'storage' => 'registry',
    'elementId' => 14001,
    'after' => 'safe_extra_renamed',
]]);
Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows($safeRenamedRows);
$caseCollisionRows = $safeExtraRows;
$caseCollisionRows[] = [
    'id' => 14002,
    'code' => 'Bleed',
    'presetId' => 12740,
    'active' => 'Y',
    'kind' => 'constant',
    'dataType' => 'number',
    'initialValue' => '1',
];
try {
    $collisionProspective = $prospectiveRows->invoke($refactor, $caseCollisionRows, [[
        'kind' => 'element_code',
        'storage' => 'registry',
        'elementId' => 14001,
        'after' => 'bleed',
    ]]);
    Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows($collisionProspective);
    $assert(false, 'casefold collision with an existing extra symbol must reject the refactor');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'casefold collision is rejected by the prospective registry gate');
}
foreach (['get', 'stage_42', 'FINISHED_ITEM_QTY'] as $reservedOrDuplicateCode) {
    $invalidExtraRows = $customNeutralRows;
    $invalidExtraRows[] = [
        'id' => 14002,
        'code' => $reservedOrDuplicateCode,
        'presetId' => 12740,
        'active' => 'Y',
        'kind' => 'variable',
        'dataType' => 'number',
        'initialValue' => '1',
    ];
    try {
        Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows($invalidExtraRows);
        $assert(false, 'reserved or casefold-duplicate extra code must fail: ' . $reservedOrDuplicateCode);
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, 'invalid extra code is rejected fail-closed');
    }
}
$extraSafeRows = $next['rows'];
$extraSafeRows[] = [
    'id' => 14000,
    'iblockId' => 77,
    'code' => 'custom_rate',
    'title' => 'Custom rate',
    'active' => 'Y',
    'sort' => 1000,
    'description' => '',
    'descriptionType' => 'text',
    'presetId' => 12740,
    'kind' => 'variable',
    'dataType' => 'auto',
    'initialValue' => 'toNumber(get(input, "values.volume"))',
];
Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows($extraSafeRows);
foreach ([
    ['kind' => 'bogus'],
    ['dataType' => 'bogus'],
    ['code' => 'bad code'],
    ['code' => ' custom_rate '],
    ['code' => 'offer'],
] as $invalidExtraOverride) {
    $invalidExtraRows = $extraSafeRows;
    $invalidExtraRows[array_key_last($invalidExtraRows)] = array_merge(
        $invalidExtraRows[array_key_last($invalidExtraRows)],
        $invalidExtraOverride
    );
    try {
        Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows($invalidExtraRows);
        $assert(false, 'invalid extra neutral symbol metadata must fail closed');
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, 'invalid extra neutral symbol metadata is rejected');
    }
}

$serialized = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert(is_string($serialized), 'target state is serializable');
foreach (['offer', 'product', 'selectedOffer', 'selectedOffers', 'context', 'iblocks', 'elementsStore', 'priceTypes', 'globalSymbols', 'resources'] as $forbiddenRoot) {
    $assert(
        preg_match('/\\b' . preg_quote($forbiddenRoot, '/') . '\\b/i', $serialized) !== 1,
        'target formulas contain no forbidden root ' . $forbiddenRoot
    );
}
$assert(strpos($serialized, 'toNumber(get(input, \\"values.volume\\"))') !== false, 'quantity is form-owned');
$assert(strpos($serialized, '\\"Листовая продукция\\"') !== false, 'presentation name is an honest preset-owned literal');
$assert(strpos($serialized, 'values.format.width') !== false && strpos($serialized, 'values.format.height') !== false, 'format text is derived from structured dimensions');

$activeN = $next;
$activeN['active']['value'] = 'N';
$activeNPlan = Preset12740NeutralGlobalSymbolMigrationService::buildPlan($activeN);
$assert($activeNPlan['fingerprint'] === $complete['fingerprint'], 'activation state is CAS-protected separately and does not break rollback evidence');

$partialOverrides = [];
$firstCode = array_key_first($symbols);
$partialOverrides[$firstCode] = $symbols[$firstCode]['neutral'];
$partial = Preset12740NeutralGlobalSymbolMigrationService::buildPlan($makeState($partialOverrides));
$assert($partial['status'] === 'blocked' && $partial['ready'] === false, 'a partial rewrite cannot replace the recoverable all-or-nothing migration');
$assert(
    in_array('partial-migration-state', array_column($partial['unresolved'], 'reason'), true),
    'partial state reports its exact blocking reason'
);

$expectBlocked = static function (array $candidate, string $message) use ($assert): void {
    $candidatePlan = Preset12740NeutralGlobalSymbolMigrationService::buildPlan($candidate);
    $assert($candidatePlan['status'] === 'blocked' && $candidatePlan['ready'] === false, $message);
};
$expectBlocked($makeState([], ['finished_item_qty' => ['id' => 99999]]), 'wrong element identity is rejected');
$expectBlocked($makeState([], ['finished_item_qty' => ['code' => 'qty_alias']]), 'renamed required code is rejected');
$expectBlocked($makeState([], ['finished_item_qty' => ['presetId' => 42]]), 'wrong preset ownership is rejected');
$expectBlocked($makeState([], ['finished_item_qty' => ['active' => 'N']]), 'inactive required symbol is rejected');
$expectBlocked($makeState([], ['finished_item_qty' => ['kind' => 'variable']]), 'wrong symbol kind is rejected');
$expectBlocked($makeState([], ['finished_item_qty' => ['dataType' => 'string']]), 'wrong symbol data type is rejected');
$expectBlocked($makeState(['finished_item_qty' => 'toNumber(42)']), 'unknown pre-migration formula is rejected');

$extraLegacy = $legacyState;
$extraLegacy['rows'][] = [
    'id' => 14000,
    'iblockId' => 77,
    'code' => 'unexpected_catalog_dependency',
    'title' => 'Unexpected',
    'active' => 'Y',
    'sort' => 999,
    'description' => '',
    'descriptionType' => 'text',
    'presetId' => 12740,
    'kind' => 'constant',
    'dataType' => 'number',
    'initialValue' => 'get(offer, "id")',
];
$expectBlocked($extraLegacy, 'an extra preset-owned catalog dependency is rejected');
try {
    Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows($extraLegacy['rows']);
    $assert(false, 'runtime boundary must reject a reintroduced offer root');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'runtime boundary rejects catalog roots fail-closed');
}
$missingRuntime = $next['rows'];
array_pop($missingRuntime);
try {
    Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows($missingRuntime);
    $assert(false, 'runtime boundary must reject a missing required symbol');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'runtime boundary rejects incomplete required globals');
}
$expectRuntimeFailure = static function (array $rows, string $message) use ($assert): void {
    try {
        Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows($rows);
        $assert(false, $message);
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, $message . ' is rejected fail-closed');
    }
};
foreach ([
    'wrong preset' => ['presetId' => 7],
    'inactive row' => ['active' => 'N'],
    'empty formula' => ['initialValue' => ''],
    'wrong kind' => ['kind' => 'variable'],
    'wrong data type' => ['dataType' => 'string'],
] as $message => $override) {
    $candidate = $next['rows'];
    $candidate[0] = array_merge($candidate[0], $override);
    $expectRuntimeFailure($candidate, $message);
}
$duplicateCode = $next['rows'];
$duplicateCode[1]['code'] = $duplicateCode[0]['code'];
$expectRuntimeFailure($duplicateCode, 'duplicate runtime code');
$duplicateId = $next['rows'];
$duplicateId[1]['id'] = $duplicateId[0]['id'];
$expectRuntimeFailure($duplicateId, 'duplicate runtime id');

$canonicalMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'encodeCanonical');
$canonicalMethod->setAccessible(true);
$evidenceMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'assertCompletionEvidence');
$evidenceMethod->setAccessible(true);
$historicalEvidenceMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'assertHistoricalEvidence');
$historicalEvidenceMethod->setAccessible(true);
$currentNeutralMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'assertCurrentNeutralState');
$currentNeutralMethod->setAccessible(true);
$verifiedPlanMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'buildVerifiedCompletionPlan');
$verifiedPlanMethod->setAccessible(true);
$auditedPlanMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'buildAuditedPlan');
$auditedPlanMethod->setAccessible(true);
$normalizeActiveMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'normalizeActiveSnapshot');
$normalizeActiveMethod->setAccessible(true);
$normalizeConfigMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'normalizeConfigAuthorities');
$normalizeConfigMethod->setAccessible(true);
$normalizeOptionMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'normalizeOptionSnapshotRows');
$normalizeOptionMethod->setAccessible(true);
$retainedBackupMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'assertRetainedBackupMatchesState');
$retainedBackupMethod->setAccessible(true);
$prepareBackupMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'prepareBackupRaw');
$prepareBackupMethod->setAccessible(true);
$fingerprintMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'fingerprint');
$fingerprintMethod->setAccessible(true);
$backup = [
    'contract' => Preset12740NeutralGlobalSymbolMigrationService::CONTRACT,
    'presetId' => 12740,
    'fingerprint' => $plan['fingerprint'],
    'state' => $legacyState,
];
$backupRaw = $canonicalMethod->invoke(null, $backup);
$lowercaseActive = $normalizeOptionMethod->invoke(
    null,
    'prospektweb.calc',
    'PRESET_12740_NEUTRAL_INPUT_ACTIVE',
    [[
        'MODULE_ID' => 'prospektweb.calc',
        'NAME' => 'preset_12740_neutral_input_active',
        'VALUE' => 'Y',
        'SITE_ID' => null,
    ]],
    true
);
$assert(
    ($lowercaseActive['exists'] ?? false) === true
        && ($lowercaseActive['value'] ?? '') === 'Y'
        && ($lowercaseActive['name'] ?? '') === 'preset_12740_neutral_input_active'
        && ($lowercaseActive['moduleId'] ?? '') === 'prospektweb.calc'
        && array_key_exists('siteId', $lowercaseActive)
        && $lowercaseActive['siteId'] === null,
    'V2 accepts one production lowercase ACTIVE row and retains its exact database identity'
);
$lowercaseBackup = $normalizeOptionMethod->invoke(
    null,
    'prospektweb.calc',
    'PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_BACKUP_V1',
    [[
        'MODULE_ID' => 'prospektweb.calc',
        'NAME' => 'preset_12740_neutral_global_symbols_backup_v1',
        'VALUE' => $backupRaw,
        'SITE_ID' => '',
    ]],
    true
);
$assert(
    hash_equals($backupRaw, (string)($lowercaseBackup['value'] ?? ''))
        && ($lowercaseBackup['name'] ?? '') === 'preset_12740_neutral_global_symbols_backup_v1'
        && ($lowercaseBackup['siteId'] ?? null) === '',
    'V2 retained backup read-back preserves both byte content and lowercase empty-site row identity'
);
$expectOptionFailure = static function (
    string $moduleId,
    string $name,
    array $rows,
    bool $required,
    string $message
) use ($assert, $normalizeOptionMethod): void {
    try {
        $normalizeOptionMethod->invoke(null, $moduleId, $name, $rows, $required);
    } catch (Throwable $error) {
        $assert(in_array($error->getCode(), [0, 409], true), $message . ' fails closed');
        return;
    }
    $assert(false, $message);
};
$expectOptionFailure(
    'prospektweb.calc',
    'PRESET_12740_NEUTRAL_INPUT_ACTIVE',
    [
        ['MODULE_ID' => 'prospektweb.calc', 'NAME' => 'preset_12740_neutral_input_active', 'VALUE' => 'Y', 'SITE_ID' => null],
        ['MODULE_ID' => 'prospektweb.calc', 'NAME' => 'PRESET_12740_NEUTRAL_INPUT_ACTIVE', 'VALUE' => 'N', 'SITE_ID' => ''],
    ],
    false,
    'V2 rejects mixed-case duplicate global rows across NULL and empty SITE_ID'
);
$expectOptionFailure(
    'prospektweb.calc',
    'PRESET_12740_NEUTRAL_INPUT_ACTIVE',
    [['MODULE_ID' => 'prospektweb.calc', 'NAME' => ' preset_12740_neutral_input_active ', 'VALUE' => 'Y', 'SITE_ID' => null]],
    false,
    'V2 rejects whitespace option-name aliases'
);
$expectOptionFailure(
    'prospektweb.calc',
    'PRESET_12740_NEUTRAL_INPUT_ACTIVE',
    [['MODULE_ID' => 'Prospektweb.calc', 'NAME' => 'preset_12740_neutral_input_active', 'VALUE' => 'Y', 'SITE_ID' => null]],
    false,
    'V2 requires exact module-id case'
);
$expectOptionFailure(
    'prospektweb.calc',
    'PRESET_12740_NEUTRAL_INPUT_ACTIVE',
    [['MODULE_ID' => 'prospektweb.calc', 'NAME' => 'preset_12740_neutral_input_active', 'VALUE' => 'Y', 'SITE_ID' => 's1']],
    false,
    'V2 rejects site-scoped authorities instead of ignoring them'
);
$expectOptionFailure(
    'prospektweb.calc',
    'PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_BACKUP_V1',
    [['MODULE_ID' => 'prospektweb.calc', 'NAME' => 'preset_12740_neutral_global_symbols_backup_v1', 'VALUE' => '', 'SITE_ID' => null]],
    false,
    'V2 rejects an existing empty retained backup instead of overwriting it as if absent'
);
$expectOptionFailure(
    'prospektweb.calc',
    'ARBITRARY_OPTION',
    [],
    false,
    'V2 direct reader remains closed to arbitrary option names'
);
$expectOptionFailure(
    'prospektweb.calc',
    'PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_MIGRATION_V1',
    [],
    true,
    'V2 required evidence row absence is distinguishable from an empty value'
);

$policyClass = new ReflectionClass(\Prospektweb\Calc\Services\NeutralFormulaPolicy::class);
$normalizePolicyOptions = $policyClass->getMethod('normalizeOptionAuthorityRows');
$normalizePolicyOptions->setAccessible(true);
$policyAllowlist = $policyClass->getConstant('CONTRACT_OPTION_NAMES_BY_MODULE');
$policyLowercase = $normalizePolicyOptions->invoke(null, [[
    'MODULE_ID' => 'prospektweb.calc',
    'NAME' => 'preset_12740_neutral_input_active',
    'VALUE' => 'Y',
    'SITE_ID' => null,
]], $policyAllowlist);
$assert(
    ($policyLowercase['valuesByModule']['prospektweb.calc']['PRESET_12740_NEUTRAL_INPUT_ACTIVE'] ?? '') === 'Y'
        && ($policyLowercase['rowIdentities']['prospektweb.calc:PRESET_12740_NEUTRAL_INPUT_ACTIVE']['name'] ?? '')
            === 'preset_12740_neutral_input_active',
    'neutral authoring policy accepts lowercase singleton authority while preserving its raw name'
);
$expectPolicyFailure = static function (array $rows, string $message) use (
    $assert,
    $normalizePolicyOptions,
    $policyAllowlist
): void {
    try {
        $normalizePolicyOptions->invoke(null, $rows, $policyAllowlist);
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, $message . ' fails closed');
        return;
    }
    $assert(false, $message);
};
$expectPolicyFailure([
    ['MODULE_ID' => 'prospektweb.calc', 'NAME' => 'preset_12740_neutral_input_active', 'VALUE' => 'Y', 'SITE_ID' => null],
    ['MODULE_ID' => 'prospektweb.calc', 'NAME' => 'PRESET_12740_NEUTRAL_INPUT_ACTIVE', 'VALUE' => 'N', 'SITE_ID' => ''],
], 'neutral authoring rejects canonical mixed-case NULL/empty-site duplicates');
$expectPolicyFailure([
    ['MODULE_ID' => 'prospektweb.calc', 'NAME' => ' preset_12740_neutral_input_active ', 'VALUE' => 'Y', 'SITE_ID' => null],
], 'neutral authoring rejects whitespace aliases');
$expectPolicyFailure([
    ['MODULE_ID' => 'Prospektweb.calc', 'NAME' => 'preset_12740_neutral_input_active', 'VALUE' => 'Y', 'SITE_ID' => null],
], 'neutral authoring preserves exact module-id case');
$expectPolicyFailure([
    ['MODULE_ID' => 'prospektweb.calc', 'NAME' => 'preset_12740_neutral_input_active', 'VALUE' => 'Y', 'SITE_ID' => 's1'],
], 'neutral authoring rejects site-scoped option authority');
$expectPolicyFailure([
    ['MODULE_ID' => 'prospektweb.calc', 'NAME' => 'ARBITRARY_OPTION', 'VALUE' => 'Y', 'SITE_ID' => null],
], 'neutral authoring normalizer cannot accept arbitrary option names');
$marker = [
    'contract' => Preset12740NeutralGlobalSymbolMigrationService::CONTRACT,
    'presetId' => 12740,
    'beforeFingerprint' => $plan['fingerprint'],
    'afterFingerprint' => $complete['fingerprint'],
    'backupHash' => hash('sha256', $backupRaw),
    'appliedAt' => '2026-08-15T00:00:00+00:00',
];
$evidenceMethod->invoke(null, $complete, json_encode($marker), $backupRaw);
$unprovenTarget = $auditedPlanMethod->invoke(new Preset12740NeutralGlobalSymbolMigrationService(), $next, '', '');
$assert(
    ($unprovenTarget['status'] ?? '') === 'blocked'
        && ($unprovenTarget['ready'] ?? true) === false,
    'a manually pre-neutralized registry without marker and backup cannot impersonate a completed migration'
);
$historicalEvidence = $historicalEvidenceMethod->invoke(null, json_encode($marker), $backupRaw);
$assert(is_array($historicalEvidence) && is_array($historicalEvidence['targetState'] ?? null), 'historical evidence independently reconstructs the reviewed migration target');
$retainedBackup = $retainedBackupMethod->invoke(null, $legacyState, $backupRaw);
$retainedAudit = $auditedPlanMethod->invoke(
    new Preset12740NeutralGlobalSymbolMigrationService(),
    $legacyState,
    '',
    $backupRaw
);
$assert(
    is_array($retainedBackup)
        && ($retainedAudit['status'] ?? '') === 'pending'
        && ($retainedAudit['ready'] ?? false) === true
        && ($retainedAudit['backupRetained'] ?? false) === true,
    'rollback-retained backup supports a later exact re-apply without manual option deletion'
);
$legacyAfterDisable = $legacyState;
$legacyAfterDisable['active']['value'] = 'N';
$reusedBackupRaw = $prepareBackupMethod->invoke(null, $legacyAfterDisable, $backupRaw);
$assert(
    is_string($reusedBackupRaw) && hash_equals($backupRaw, $reusedBackupRaw),
    're-apply preserves the retained backup bytes even when ACTIVE changed outside its fingerprint'
);
try {
    $retainedBackupMethod->invoke(null, $next, $backupRaw);
    $assert(false, 'retained backup must not authorize a different current state');
} catch (Throwable $error) {
    $assert(true, 'retained backup is bound to the exact restored legacy fingerprint');
}
$customState = $next;
foreach ($customState['rows'] as &$customStateRow) {
    if (($customStateRow['code'] ?? '') === 'finished_item_qty') {
        $customStateRow['initialValue'] = 'toNumber(get(input, "values.volume")) + 0';
    }
}
unset($customStateRow);
$currentNeutralMethod->invoke(null, $customState, $historicalEvidence['targetState']);
$customVerified = $verifiedPlanMethod->invoke(null, $customState, $historicalEvidence);
$customAudited = $auditedPlanMethod->invoke(
    new Preset12740NeutralGlobalSymbolMigrationService(),
    $customState,
    json_encode($marker),
    $backupRaw
);
$assert(
    ($customVerified['status'] ?? '') === 'complete'
        && ($customVerified['ready'] ?? false) === true
        && ($customVerified['customized'] ?? false) === true
        && ($customVerified['evidenceVerified'] ?? false) === true,
    'safe author edits remain activation-ready without rewriting immutable migration evidence'
);
$assert(
    ($customAudited['status'] ?? '') === 'complete'
        && ($customAudited['customized'] ?? false) === true,
    'repeat audit reports a safely customized completed lifecycle'
);
try {
    $evidenceMethod->invoke(
        null,
        Preset12740NeutralGlobalSymbolMigrationService::buildPlan($customState),
        json_encode($marker),
        $backupRaw
    );
    $assert(false, 'rollback evidence must not authorize overwriting a customized current registry');
} catch (Throwable $error) {
    $assert(true, 'rollback requires the exact historical target, not only a safe customized registry');
}
$unsafeCustomState = $customState;
foreach ($unsafeCustomState['rows'] as &$unsafeCustomRow) {
    if (($unsafeCustomRow['code'] ?? '') === 'finished_item_qty') {
        $unsafeCustomRow['initialValue'] = 'toNumber(get(offer, "id"))';
    }
}
unset($unsafeCustomRow);
try {
    $currentNeutralMethod->invoke(null, $unsafeCustomState, $historicalEvidence['targetState']);
    $assert(false, 'unsafe author edits must not pass activation readiness');
} catch (Throwable $error) {
    $assert(true, 'unsafe author edits are rejected after the one-time migration');
}
$driftedAuthorityState = $customState;
$driftedAuthorityState['propertySchema']['KIND']['id'] = 999;
try {
    $currentNeutralMethod->invoke(null, $driftedAuthorityState, $historicalEvidence['targetState']);
    $assert(false, 'runtime authority drift must not pass activation readiness');
} catch (Throwable $error) {
    $assert(true, 'runtime authority drift is rejected after the one-time migration');
}
$implicitActive = $normalizeActiveMethod->invoke(null, [
    'exists' => false,
    'moduleId' => 'prospektweb.calc',
    'name' => 'PRESET_12740_NEUTRAL_INPUT_ACTIVE',
    'siteId' => null,
    'value' => '',
]);
$assert(
    ($implicitActive['value'] ?? '') === 'N'
        && ($implicitActive['implicit'] ?? false) === true,
    'a clean install without ACTIVE is a pinned authoring-state N, not a bootstrap deadlock'
);
$missingCalcAuthority = [
    'exists' => false,
    'moduleId' => 'prospektweb.calc',
    'name' => 'IBLOCK_CALC_GLOBAL_VALUES',
    'siteId' => null,
    'value' => '',
];
$frontAuthority = [
    'exists' => true,
    'moduleId' => 'prospektweb.frontcalc',
    'name' => 'IBLOCK_CALC_GLOBAL_VALUES',
    'siteId' => null,
    'value' => '77',
];
$frontOnlyConfig = $normalizeConfigMethod->invoke(null, $missingCalcAuthority, $frontAuthority);
$assert(($frontOnlyConfig['iblockId'] ?? 0) === 77, 'frontcalc-only global iblock authority is a valid pinned consensus');
$calcAuthority = $missingCalcAuthority;
$calcAuthority['exists'] = true;
$calcAuthority['value'] = '77';
$equalConfig = $normalizeConfigMethod->invoke(null, $calcAuthority, $frontAuthority);
$assert(($equalConfig['iblockId'] ?? 0) === 77, 'equal calc and frontcalc global iblock authorities are accepted');
$conflictingFrontAuthority = $frontAuthority;
$conflictingFrontAuthority['value'] = '78';
try {
    $normalizeConfigMethod->invoke(null, $calcAuthority, $conflictingFrontAuthority);
    $assert(false, 'conflicting global iblock authorities must fail closed');
} catch (Throwable $error) {
    $assert(true, 'conflicting global iblock authorities are rejected');
}
$emptyCalcAuthority = $calcAuthority;
$emptyCalcAuthority['value'] = '';
try {
    $normalizeConfigMethod->invoke(null, $emptyCalcAuthority, $frontAuthority);
    $assert(false, 'an existing empty global iblock authority must not be treated as absent');
} catch (Throwable $error) {
    $assert(true, 'an existing empty global iblock authority is rejected');
}
try {
    $normalizeActiveMethod->invoke(null, [
        'exists' => true,
        'moduleId' => 'prospektweb.calc',
        'name' => 'PRESET_12740_NEUTRAL_INPUT_ACTIVE',
        'siteId' => null,
        'value' => '',
    ]);
    $assert(false, 'an existing empty ACTIVE authority must fail closed');
} catch (Throwable $error) {
    $assert(true, 'an existing empty ACTIVE authority is rejected');
}
$corruptMarker = $marker;
$corruptMarker['beforeFingerprint'] = str_repeat('0', 64);
try {
    $evidenceMethod->invoke(null, $complete, json_encode($corruptMarker), $backupRaw);
    $assert(false, 'corrupt before fingerprint must fail evidence verification');
} catch (Throwable $error) {
    $assert(true, 'corrupt evidence is rejected');
}
$wrongTargetBackup = $backup;
$wrongTargetBackup['state']['rows'][] = [
    'id' => 14001,
    'iblockId' => 77,
    'code' => 'unrelated_safe_symbol',
    'title' => 'Unrelated safe symbol',
    'active' => 'Y',
    'sort' => 1000,
    'description' => '',
    'descriptionType' => 'text',
    'presetId' => 42,
    'kind' => 'constant',
    'dataType' => 'number',
    'initialValue' => '42',
];
$wrongTargetBackup['fingerprint'] = $fingerprintMethod->invoke(null, $wrongTargetBackup['state']);
$wrongTargetBackupRaw = $canonicalMethod->invoke(null, $wrongTargetBackup);
$wrongTargetMarker = $marker;
$wrongTargetMarker['beforeFingerprint'] = $wrongTargetBackup['fingerprint'];
$wrongTargetMarker['backupHash'] = hash('sha256', $wrongTargetBackupRaw);
try {
    $evidenceMethod->invoke(null, $complete, json_encode($wrongTargetMarker), $wrongTargetBackupRaw);
    $assert(false, 'backup whose planned target differs from the current complete state must fail');
} catch (Throwable $error) {
    $assert(true, 'backup-to-target evidence relation is enforced');
}

$source = file_get_contents(dirname(__DIR__) . '/lib/Install/Preset12740NeutralGlobalSymbolMigrationService.php');
$assert(is_string($source), 'migration source is readable');
$assert(strpos($source, "count((array)(\$plan['mutations'] ?? [])) !== self::EXPECTED_MUTATION_COUNT") !== false, 'apply requires the exact fourteen-row atomic plan');
$assert(strpos($source, "unset(\$state['active'])") !== false, 'fingerprint deliberately excludes activation state');
$assert(strpos($source, "SELECT ID FROM b_iblock_element WHERE IBLOCK_ID=") !== false, 'migration locks the full registry membership range');
$assert(strpos($source, "b_iblock_element_prop_s") !== false && strpos($source, "b_iblock_element_prop_m") !== false, 'migration locks both versioned property tables');
$assert(strpos($source, "Global-symbol migration option delete read-back failed.") !== false, 'rollback verifies marker deletion by direct database read-back');
$assert(
    strpos($source, '$calc = $this->readOptionSnapshot(self::MODULE_ID, self::CONFIG_OPTION, $forUpdate, false);') !== false,
    'either calc or frontcalc may own the pinned global-iblock authority, while disagreement still fails closed'
);
$v2ReadStart = strpos($source, 'private function readOptionSnapshot(');
$v2ReadRawStart = strpos($source, 'private function readOptionRaw(');
$v2SetStart = strpos($source, 'private function setGlobalOption(');
$v2DeleteStart = strpos($source, 'private function deleteGlobalOption(');
$assert(
    is_int($v2ReadStart) && is_int($v2ReadRawStart) && is_int($v2SetStart) && is_int($v2DeleteStart),
    'V2 direct option read/write boundaries are discoverable'
);
$v2ReadSource = substr($source, $v2ReadStart, $v2ReadRawStart - $v2ReadStart);
$v2SetSource = substr($source, $v2SetStart, $v2DeleteStart - $v2SetStart);
$v2DeleteSource = substr($source, $v2DeleteStart, strpos($source, '/**', $v2DeleteStart) - $v2DeleteStart);
$assert(
    strpos($v2ReadSource, 'UPPER(TRIM(NAME))') !== false
        && strpos($v2ReadSource, "AND (SITE_ID IS NULL OR SITE_ID='')") === false
        && strpos($v2ReadSource, 'self::normalizeOptionSnapshotRows(') !== false
        && strpos($v2SetSource, 'IMMUTABLE_EVIDENCE_OPTION_NAMES') !== false
        && strpos($v2SetSource, "\$before['name']") !== false
        && strpos($v2SetSource, "\$before['siteId']") !== false
        && strpos($v2DeleteSource, 'BINARY MODULE_ID=') !== false
        && strpos($v2DeleteSource, 'BINARY NAME=') !== false,
    'V2 evidence readers and writers reject aliases/scoped rows and preserve the validated raw identity without cache'
);

$inputMigrationSource = file_get_contents(dirname(__DIR__) . '/lib/Install/Preset12740NeutralInputMigrationService.php');
$legacyMigrationSource = file_get_contents(dirname(__DIR__) . '/lib/Install/CatalogCalcPropertyMigrationService.php');
$globalServiceSource = file_get_contents(dirname(__DIR__) . '/lib/Services/GlobalSymbolService.php');
$initSource = file_get_contents(dirname(__DIR__) . '/lib/Calculator/InitPayloadService.php');
$policySource = file_get_contents(dirname(__DIR__) . '/lib/Services/NeutralFormulaPolicy.php');
$assert(
    is_string($policySource)
        && substr_count($policySource, 'UPPER(TRIM(NAME))') >= 4
        && strpos($policySource, 'normalizeOptionAuthorityRows(') !== false
        && strpos($policySource, "AND (SITE_ID IS NULL OR SITE_ID='')") === false,
    'neutral formula authorities inspect lowercase and whitespace-colliding rows, including scoped rows that must fail closed'
);
$assert(
    is_string($inputMigrationSource)
        && substr_count($inputMigrationSource, '->assertActivationReadyLocked(true);') === 1
        && strpos($inputMigrationSource, '->assertActivationReady();') === false,
    'V1 takes one transaction-scoped V2 evidence and registry gate before either activation branch'
);
$lockedGateStart = strpos($source, 'public function assertActivationReadyLocked(');
$runtimeBoundaryStart = strpos($source, 'public static function assertNeutralRuntimeRows(');
$lockedGateSource = is_int($lockedGateStart) && is_int($runtimeBoundaryStart)
    ? substr($source, $lockedGateStart, $runtimeBoundaryStart - $lockedGateStart)
    : '';
$assert(
    strpos($lockedGateSource, '$this->lockOptionAuthorityRows();') !== false
        && strpos($lockedGateSource, '$this->lockRegistryRows(') !== false
        && strpos($lockedGateSource, '$this->loadState(') !== false
        && strpos($lockedGateSource, '$this->lockOptionAuthorityRows();')
            < strpos($lockedGateSource, '$this->lockRegistryRows(')
        && strpos($lockedGateSource, '$this->lockRegistryRows(')
            < strpos($lockedGateSource, '$this->loadState('),
    'the locked activation gate orders options before the complete registry lock and exact state reread'
);
$rollbackGateStart = strpos($source, 'public function assertV1RollbackReadyLocked(');
$rollbackGateSource = is_int($rollbackGateStart) && is_int($runtimeBoundaryStart)
    ? substr($source, $rollbackGateStart, $runtimeBoundaryStart - $rollbackGateStart)
    : '';
$assert(
    $rollbackGateSource !== ''
        && strpos($rollbackGateSource, '$this->lockRegistryRows(') !== false
        && strpos($rollbackGateSource, '$this->buildAuditedPlan(') !== false
        && strpos($rollbackGateSource, "(\$plan['evidenceVerified'] ?? false) !== true") !== false
        && strpos($rollbackGateSource, "(\$plan['customized'] ?? true) !== false") !== false
        && strpos($rollbackGateSource, "\$status === 'pending'") !== false
        && strpos($rollbackGateSource, '$markerRaw !==') !== false,
    'V1 rollback holds the V2 registry lock and accepts only uncustomized complete or exact pre-V2 pending state'
);
$assert(
    is_string($inputMigrationSource)
        && substr_count($inputMigrationSource, '->assertV1RollbackReadyLocked(true);') === 1,
    'V1 rollback performs the transaction-scoped symmetric V2 recovery gate'
);
$assert(
    is_string($inputMigrationSource)
        && substr_count($inputMigrationSource, '\\CIBlockElement::SetPropertyValues(') >= 2
        && strpos($inputMigrationSource, "'INPUTS'\n            );\n            \\CIBlockElement::SetPropertyValuesEx") !== false,
    'V1 writes clear unchanged-value properties before restoring exact DESCRIPTION formulas and paths'
);
$assert(
    is_string($legacyMigrationSource)
        && substr_count($legacyMigrationSource, '$this->assertLegacyGlobalRewriteAllowed($presetId);') === 3
        && substr_count(
            $legacyMigrationSource,
            '$this->assertLegacyGlobalRewriteAllowed($presetId, true);'
        ) === 5
        && strpos($legacyMigrationSource, 'PRESET_12740_NEUTRAL_GLOBAL_SYMBOLS_MIGRATION_V1') !== false,
    'historical catalogue migration stops before snapshots/phases and rechecks under each formula write transaction'
);
$assert(
    is_string($globalServiceSource)
        && strpos($globalServiceSource, 'assertNeutralRowsBeforeWrite(') !== false
        && substr_count($globalServiceSource, 'Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows(') >= 2,
    'global authoring validates the complete required-14 registry before and after an active write'
);
$assert(
    is_string($initSource)
        && substr_count($initSource, 'Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows(') >= 3,
    'manual and catalogue INIT payloads validate the neutral global registry'
);

fwrite(STDOUT, "OK\n");
