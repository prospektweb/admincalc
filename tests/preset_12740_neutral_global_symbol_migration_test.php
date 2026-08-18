<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Install/Preset12740NeutralInputMigrationService.php';
require_once dirname(__DIR__) . '/lib/Services/NeutralFormulaPolicy.php';
require_once dirname(__DIR__) . '/lib/Install/Preset12740NeutralGlobalSymbolMigrationService.php';
require_once dirname(__DIR__) . '/lib/Services/GlobalCodeRefactorService.php';

use Prospektweb\Calc\Install\Preset12740NeutralGlobalSymbolMigrationService;

if (!class_exists('CIBlockElement')) {
    final class CIBlockElement
    {
        /** @var array<int,array<string,mixed>> */
        public static array $writes = [];

        public static function SetPropertyValues($id, $iblockId, $values, $propertyCode): void
        {
            self::$writes[] = [
                'operation' => 'clear',
                'id' => (int)$id,
                'iblockId' => (int)$iblockId,
                'propertyCode' => (string)$propertyCode,
            ];
        }

        public static function SetPropertyValuesEx($id, $iblockId, $values): void
        {
            self::$writes[] = [
                'operation' => 'set',
                'id' => (int)$id,
                'iblockId' => (int)$iblockId,
                'values' => $values,
            ];
        }
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$symbolsProperty = new ReflectionClass(Preset12740NeutralGlobalSymbolMigrationService::class);
$assert(
    Preset12740NeutralGlobalSymbolMigrationService::CONTRACT === 'prospektweb.calc.preset-12740-neutral-global-symbol-migration/v2',
    'the expanded twenty-four-row mutation and evidence contract is explicitly versioned v2'
);
$assert(
    Preset12740NeutralGlobalSymbolMigrationService::EXPECTED_MUTATION_COUNT === 24
        && Preset12740NeutralGlobalSymbolMigrationService::EXPECTED_PROSPECTIVE_RUNTIME_ROW_COUNT === 37,
    'migration and capability counts are pinned to the reviewed production shape'
);
$symbols = $symbolsProperty->getConstant('SYMBOLS');
$assert(is_array($symbols) && count($symbols) === 14, 'migration owns the exact fourteen audited global symbols');
$typedInitializers = $symbolsProperty->getConstant('TYPED_INITIALIZERS');
$assert(is_array($typedInitializers) && count($typedInitializers) === 10, 'migration owns the exact ten blank typed initializers');
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
    12979 => ['value_format_text', 'string', 'toString(get(input, "values.format.width")) + "×" + toString(get(input, "values.format.length")) + " мм"'],
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

$expectedInitializers = [
    12794 => ['is_self_adhesive_paper', 'constant', 'boolean', 'get(input, "values.type.paper") == "sticker-paper"'],
    12796 => ['is_uv_printing', 'constant', 'boolean', 'get(input, "values.method") == "UF_PECHAT"'],
    12838 => ['needs_pre_lamination_trim', 'variable', 'boolean', 'false'],
    12839 => ['print_layout_length_mm', 'constant', 'number', '0'],
    12840 => ['print_layout_width_mm', 'constant', 'number', '0'],
    12902 => ['print_sheet_thickness_initial_mm', 'constant', 'number', '0'],
    12903 => ['print_sheet_weight_initial_g', 'constant', 'number', '0'],
    12977 => ['title_for_base_in_offer', 'constant', 'string', '""'],
    12980 => ['print_vibrancy_text', 'constant', 'string', '""'],
    12981 => ['print_method_text', 'constant', 'string', '""'],
];
foreach ($expectedInitializers as $id => [$code, $kind, $dataType, $formula]) {
    $actual = $typedInitializers[$code] ?? null;
    $assert(
        is_array($actual)
            && ($actual['id'] ?? null) === $id
            && ($actual['kind'] ?? null) === $kind
            && ($actual['dataType'] ?? null) === $dataType
            && ($actual['legacy'] ?? null) === ''
            && ($actual['neutral'] ?? null) === $formula,
        'initializer #' . $id . ' has the exact production identity, blank source and typed neutral target'
    );
}
$requiredIdentities = Preset12740NeutralGlobalSymbolMigrationService::requiredSymbolIdentities();
$assert(
    count($requiredIdentities) === 14
        && array_intersect_key($typedInitializers, $requiredIdentities) === [],
    'typed initialization expands the one-time migration without silently expanding the cross-service required-identity contract'
);

$productionSafeExtras = [
    'print_sheet_width_mm' => ['id' => 12904, 'kind' => 'variable', 'dataType' => 'number', 'initialValue' => '0'],
    'print_sheet_length_mm' => ['id' => 12905, 'kind' => 'variable', 'dataType' => 'number', 'initialValue' => '0'],
    'print_sheet_thickness_mm' => ['id' => 12906, 'kind' => 'variable', 'dataType' => 'number', 'initialValue' => '0'],
    'print_sheet_weight_g' => ['id' => 12907, 'kind' => 'variable', 'dataType' => 'number', 'initialValue' => '0'],
    'offset_work_and_turn_enabled' => ['id' => 12910, 'kind' => 'variable', 'dataType' => 'boolean', 'initialValue' => 'false'],
    'offset_included_print_sheet_qty' => ['id' => 12911, 'kind' => 'variable', 'dataType' => 'number', 'initialValue' => '0'],
    'offset_front_color_qty' => ['id' => 12913, 'kind' => 'variable', 'dataType' => 'number', 'initialValue' => '0'],
    'offset_back_color_qty' => ['id' => 12914, 'kind' => 'variable', 'dataType' => 'number', 'initialValue' => '0'],
    'offset_print_side_qty' => ['id' => 12915, 'kind' => 'variable', 'dataType' => 'number', 'initialValue' => '0'],
    'offset_print_form_qty' => ['id' => 12916, 'kind' => 'variable', 'dataType' => 'number', 'initialValue' => '0'],
    'offset_plate_qty' => ['id' => 12917, 'kind' => 'variable', 'dataType' => 'number', 'initialValue' => '0'],
    'incoming_semifinished_purchasing_cost_rub' => ['id' => 12926, 'kind' => 'variable', 'dataType' => 'number', 'initialValue' => '0'],
    'incoming_semifinished_base_cost_rub' => ['id' => 12927, 'kind' => 'variable', 'dataType' => 'number', 'initialValue' => '0'],
];

$makeState = static function (array $formulaOverrides = [], array $rowOverrides = []) use (
    $symbols,
    $typedInitializers,
    $productionSafeExtras
): array {
    $rows = [];
    foreach (array_merge($symbols, $typedInitializers) as $code => $specification) {
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
            'initialValueExists' => !isset($typedInitializers[$code]),
        ], $rowOverrides[$code] ?? []);
    }
    foreach ($productionSafeExtras as $code => $specification) {
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
            'initialValue' => $formulaOverrides[$code] ?? $specification['initialValue'],
            'initialValueExists' => true,
        ], $rowOverrides[$code] ?? []);
    }
    usort($rows, static fn(array $left, array $right): int => (int)$left['id'] <=> (int)$right['id']);
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
$assert(count($legacyState['rows']) === 37, 'fixture reproduces the complete production registry membership');
$plan = Preset12740NeutralGlobalSymbolMigrationService::buildPlan($legacyState);
$assert($plan['status'] === 'pending' && $plan['ready'] === true, 'the exact production legacy registry is ready');
$assert(count($plan['mutations']) === 24, 'fourteen formulas and ten blank initializers are migrated atomically');
$assert($plan['neutralSymbolCount'] === 0, 'the legacy snapshot has no neutral formulas');
$assert($plan['fingerprint'] !== $plan['nextFingerprint'], 'before and after snapshots have distinct fingerprints');
$mutationsById = [];
foreach ($plan['mutations'] as $mutation) {
    $mutationsById[(int)($mutation['elementId'] ?? 0)] = $mutation;
}
foreach ($expectedInitializers as $id => [$code, $kind, $dataType, $formula]) {
    $mutation = $mutationsById[$id] ?? null;
    $assert(
        is_array($mutation)
            && ($mutation['code'] ?? '') === $code
            && ($mutation['before'] ?? null) === ''
            && ($mutation['after'] ?? null) === $formula
            && ($mutation['beforeExists'] ?? null) === false
            && ($mutation['afterExists'] ?? null) === true,
        'initializer #' . $id . ' is an exact blank-to-typed mutation'
    );
}

$next = $plan['_nextState'];
$assert(
    count(array_filter(
        $next['rows'],
        static fn(array $row): bool => ($row['initialValueExists'] ?? null) === true
    )) === 37,
    'the prospective registry physically stores INITIAL_VALUE for all thirty-seven runtime rows'
);
$complete = Preset12740NeutralGlobalSymbolMigrationService::buildPlan($next);
$assert($complete['status'] === 'complete' && $complete['ready'] === true, 'the target registry is idempotently complete');
$assert($complete['mutations'] === [] && $complete['neutralSymbolCount'] === 24, 'complete means all twenty-four exact targets');
$assert($complete['fingerprint'] === $plan['nextFingerprint'], 'read-back matches the planned target fingerprint');
Preset12740NeutralGlobalSymbolMigrationService::assertNeutralRuntimeRows($next['rows']);
$writeStateMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'writeState');
$writeStateMethod->setAccessible(true);
\CIBlockElement::$writes = [];
$writeStateMethod->invoke(new Preset12740NeutralGlobalSymbolMigrationService(), $next, $plan['mutations']);
$applyWrites = \CIBlockElement::$writes;
$assert(
    count($applyWrites) === 48
        && count(array_filter($applyWrites, static fn(array $write): bool => $write['operation'] === 'clear')) === 24
        && count(array_filter($applyWrites, static fn(array $write): bool => $write['operation'] === 'set')) === 24,
    'apply writes each of the exact twenty-four targets with clear-then-set HTML property semantics'
);
$appliedValuesById = [];
foreach ($applyWrites as $write) {
    if (($write['operation'] ?? '') !== 'set') continue;
    $appliedValuesById[(int)$write['id']] = (string)($write['values']['INITIAL_VALUE']['VALUE']['TEXT'] ?? '');
}
foreach ($expectedInitializers as $id => [$code, $kind, $dataType, $formula]) {
    $assert(($appliedValuesById[$id] ?? null) === $formula, 'apply writer persists typed initializer #' . $id);
}
\CIBlockElement::$writes = [];
$writeStateMethod->invoke(new Preset12740NeutralGlobalSymbolMigrationService(), $legacyState, $plan['mutations']);
$rollbackWrites = \CIBlockElement::$writes;
$rolledBackValuesById = [];
foreach ($rollbackWrites as $write) {
    if (($write['operation'] ?? '') !== 'set') continue;
    $rolledBackValuesById[(int)$write['id']] = (string)($write['values']['INITIAL_VALUE']['VALUE']['TEXT'] ?? '');
}
$rollbackClearsById = [];
foreach ($rollbackWrites as $write) {
    if (($write['operation'] ?? '') === 'clear') {
        $rollbackClearsById[(int)$write['id']] = true;
    }
}
$assert(
    count($rollbackWrites) === 38
        && count($rollbackClearsById) === 24
        && count($rolledBackValuesById) === 14,
    'rollback clears all twenty-four targets but recreates only the fourteen physically present legacy values'
);
foreach (array_keys($expectedInitializers) as $id) {
    $assert(
        isset($rollbackClearsById[$id]) && !array_key_exists($id, $rolledBackValuesById),
        'rollback restores physical INITIAL_VALUE absence for initializer #' . $id
    );
}
foreach ($symbols as $specification) {
    $id = (int)$specification['id'];
    $assert(
        isset($rollbackClearsById[$id])
            && ($rolledBackValuesById[$id] ?? null) === (string)$specification['legacy'],
        'rollback restores the physically present legacy formula for required symbol #' . $id
    );
}
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
$assert(strpos($serialized, 'values.format.width') !== false && strpos($serialized, 'values.format.length') !== false, 'format text is derived from canonical structured dimensions');

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
$firstInitializerCode = array_key_first($typedInitializers);
$partialInitializer = Preset12740NeutralGlobalSymbolMigrationService::buildPlan($makeState([
    $firstInitializerCode => $typedInitializers[$firstInitializerCode]['neutral'],
], [
    $firstInitializerCode => ['initialValueExists' => true],
]));
$assert(
    $partialInitializer['status'] === 'blocked'
        && $partialInitializer['ready'] === false
        && count($partialInitializer['mutations']) === 23
        && in_array('partial-migration-state', array_column($partialInitializer['unresolved'], 'reason'), true),
    'a pre-initialized subset cannot weaken the exact twenty-four-row atomic transition'
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
$expectBlocked(
    $makeState(['needs_pre_lamination_trim' => 'true']),
    'an unknown nonblank initializer source is never rewritten generically'
);
$physicallyPresentBlankInitializer = $makeState([], [
    'needs_pre_lamination_trim' => ['initialValueExists' => true],
]);
$physicallyPresentBlankPlan = Preset12740NeutralGlobalSymbolMigrationService::buildPlan(
    $physicallyPresentBlankInitializer
);
$assert(
    ($physicallyPresentBlankPlan['status'] ?? '') === 'blocked'
        && in_array(
            'unexpected-initial-value-presence',
            array_column($physicallyPresentBlankPlan['unresolved'], 'reason'),
            true
        ),
    'a physically stored empty initializer is not reinterpreted as the reviewed absent legacy value'
);
$missingLegacyFormulaStorage = $makeState([], [
    'finished_item_qty' => ['initialValueExists' => false],
]);
$missingLegacyFormulaPlan = Preset12740NeutralGlobalSymbolMigrationService::buildPlan($missingLegacyFormulaStorage);
$assert(
    ($missingLegacyFormulaPlan['status'] ?? '') === 'blocked'
        && in_array(
            'unexpected-initial-value-presence',
            array_column($missingLegacyFormulaPlan['unresolved'], 'reason'),
            true
        ),
    'an original legacy formula must remain physically present before migration'
);
$assert(
    $physicallyPresentBlankPlan['fingerprint'] !== $plan['fingerprint'],
    'INITIAL_VALUE physical presence participates in the CAS fingerprint'
);

$unknownBlankExtra = $legacyState;
$unknownBlankExtra['rows'][] = [
    'id' => 14003,
    'iblockId' => 77,
    'code' => 'unknown_blank_extra',
    'title' => 'Unknown blank extra',
    'active' => 'Y',
    'sort' => 1001,
    'description' => '',
    'descriptionType' => 'text',
    'presetId' => 12740,
    'kind' => 'variable',
    'dataType' => 'number',
    'initialValue' => '',
    'initialValueExists' => false,
];
$unknownBlankPlan = Preset12740NeutralGlobalSymbolMigrationService::buildPlan($unknownBlankExtra);
$assert(
    ($unknownBlankPlan['status'] ?? '') === 'blocked'
        && ($unknownBlankPlan['ready'] ?? true) === false
        && in_array('invalid-prospective-runtime-registry', array_column($unknownBlankPlan['unresolved'], 'reason'), true),
    'audit blocks an unknown blank extra instead of deferring the same failure to apply'
);

$unexpectedSafeExtra = $legacyState;
$unexpectedSafeExtra['rows'][] = [
    'id' => 14004,
    'iblockId' => 77,
    'code' => 'unexpected_safe_extra',
    'title' => 'Unexpected safe extra',
    'active' => 'Y',
    'sort' => 1002,
    'description' => '',
    'descriptionType' => 'text',
    'presetId' => 12740,
    'kind' => 'variable',
    'dataType' => 'number',
    'initialValue' => '1',
    'initialValueExists' => true,
];
$unexpectedSafeExtraPlan = Preset12740NeutralGlobalSymbolMigrationService::buildPlan($unexpectedSafeExtra);
$assert(
    ($unexpectedSafeExtraPlan['status'] ?? '') === 'blocked'
        && ($unexpectedSafeExtraPlan['ready'] ?? true) === false
        && in_array(
            'unexpected-prospective-runtime-row-count',
            array_column($unexpectedSafeExtraPlan['unresolved'], 'reason'),
            true
        ),
    'GET audit blocks a valid but unreviewed thirty-eighth row before rendering an apply-ready plan'
);

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
    'initialValueExists' => true,
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
$expectedActiveMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'assertExpectedActiveSnapshot');
$expectedActiveMethod->setAccessible(true);
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
$runtimeRowsMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'runtimeRowsFromState');
$runtimeRowsMethod->setAccessible(true);
$storageAuthorityMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'v2InitialValueStorageAuthority');
$storageAuthorityMethod->setAccessible(true);
$storageSchemaMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'assertV2InitialValueStorageSchema');
$storageSchemaMethod->setAccessible(true);
$normalizeStorageMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'normalizeInitialValueStorageRows');
$normalizeStorageMethod->setAccessible(true);
$storageCoverageMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'assertInitialValueStorageCoverage');
$storageCoverageMethod->setAccessible(true);
$normalizeDecodedMethod = new ReflectionMethod(Preset12740NeutralGlobalSymbolMigrationService::class, 'normalizeDecodedInitialValue');
$normalizeDecodedMethod->setAccessible(true);
$assert(
    $storageAuthorityMethod->invoke(null, 77, 763) === [
        'table' => 'b_iblock_element_prop_s77',
        'column' => 'PROPERTY_763',
    ],
    'VERSION=2 presence is read only from the exact pinned single-property table and column'
);
$storageSchemaMethod->invoke(null, [
    'IBLOCK_ELEMENT_ID' => new stdClass(),
    'PROPERTY_763' => new stdClass(),
], $storageAuthorityMethod->invoke(null, 77, 763));
foreach ([
    ['PROPERTY_763' => new stdClass()],
    ['IBLOCK_ELEMENT_ID' => new stdClass()],
    ['IBLOCK_ELEMENT_ID' => new stdClass(), 'PROPERTY_764' => new stdClass()],
] as $invalidStorageSchema) {
    try {
        $storageSchemaMethod->invoke(
            null,
            $invalidStorageSchema,
            $storageAuthorityMethod->invoke(null, 77, 763)
        );
        $assert(false, 'missing or mismatched VERSION=2 storage column must fail closed');
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, 'missing or mismatched VERSION=2 storage column is rejected');
    }
}
foreach ([[0, 763], [77, 0], [-1, 763]] as [$badIblockId, $badPropertyId]) {
    try {
        $storageAuthorityMethod->invoke(null, $badIblockId, $badPropertyId);
        $assert(false, 'invalid direct-storage authority must fail closed');
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, 'invalid direct-storage authority is rejected');
    }
}

$storageShape = $normalizeStorageMethod->invoke(null, [
    ['IBLOCK_ELEMENT_ID' => '1', 'INITIAL_VALUE_RAW' => null],
    ['IBLOCK_ELEMENT_ID' => '2', 'INITIAL_VALUE_RAW' => '0'],
    ['IBLOCK_ELEMENT_ID' => 3, 'INITIAL_VALUE_RAW' => 'false'],
    ['IBLOCK_ELEMENT_ID' => '4', 'INITIAL_VALUE_RAW' => serialize(['TEXT' => '""', 'TYPE' => 'TEXT'])],
    ['IBLOCK_ELEMENT_ID' => '5', 'INITIAL_VALUE_RAW' => false],
]);
$assert(
    $storageShape === [1 => false, 2 => true, 3 => true, 4 => true, 5 => true],
    'only SQL NULL means absence; stored zero, false text, HTML serialization and strict false remain present'
);
$storageCoverageMethod->invoke(null, $storageShape, [5, 4, 3, 2, 1]);
foreach ([
    [['IBLOCK_ELEMENT_ID' => 1]],
    [['INITIAL_VALUE_RAW' => null]],
    [
        ['IBLOCK_ELEMENT_ID' => 1, 'INITIAL_VALUE_RAW' => null],
        ['IBLOCK_ELEMENT_ID' => '1', 'INITIAL_VALUE_RAW' => '0'],
    ],
    [['IBLOCK_ELEMENT_ID' => 1, 'INITIAL_VALUE_RAW' => []]],
    [['IBLOCK_ELEMENT_ID' => '01', 'INITIAL_VALUE_RAW' => null]],
] as $invalidStorageRows) {
    try {
        $normalizeStorageMethod->invoke(null, $invalidStorageRows);
        $assert(false, 'missing, duplicate or invalid direct-storage rows must fail closed');
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, 'invalid direct-storage rows are rejected');
    }
}
foreach ([[1, 2, 3, 4], [1, 2, 3, 4, 5, 6]] as $mismatchedElementIds) {
    try {
        $storageCoverageMethod->invoke(null, $storageShape, $mismatchedElementIds);
        $assert(false, 'missing or extra direct-storage membership must fail closed');
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, 'direct-storage membership mismatch is rejected');
    }
}

$decodedHtml = static fn(string $formula): array => [
    '~VALUE' => ['TEXT' => $formula, 'TYPE' => 'TEXT'],
    'VALUE' => ['TEXT' => $formula, 'TYPE' => 'TEXT'],
];
foreach (['0', 'false', '""', 'get(input, "values.volume")'] as $storedFormula) {
    $assert(
        $normalizeDecodedMethod->invoke(null, $decodedHtml($storedFormula), true) === $storedFormula,
        'stored decoded formula remains byte-distinct from absence: ' . $storedFormula
    );
}
$assert(
    $normalizeDecodedMethod->invoke(null, ['~VALUE' => false, 'VALUE' => false], false) === '',
    'strict decoded false is accepted only when direct SQL storage proves absence'
);
foreach ([
    [['~VALUE' => false, 'VALUE' => false], true],
    [$decodedHtml('0'), false],
    [[], false],
    [['~VALUE' => ['TYPE' => 'TEXT']], true],
    [['~VALUE' => ['TEXT' => '0', 'TYPE' => false]], true],
] as [$decodedProperty, $physicalExists]) {
    try {
        $normalizeDecodedMethod->invoke(null, $decodedProperty, $physicalExists);
        $assert(false, 'decoded/direct INITIAL_VALUE storage mismatch must fail closed');
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, 'decoded/direct INITIAL_VALUE mismatch is rejected');
    }
}

$legacyStorageRows = [];
foreach ($legacyState['rows'] as $row) {
    $legacyStorageRows[] = [
        'IBLOCK_ELEMENT_ID' => (string)$row['id'],
        'INITIAL_VALUE_RAW' => ($row['initialValueExists'] ?? false) === true
            ? serialize(['TEXT' => (string)$row['initialValue'], 'TYPE' => 'TEXT'])
            : null,
    ];
}
$legacyStorage = $normalizeStorageMethod->invoke(null, $legacyStorageRows);
$assert(
    count(array_filter($legacyStorage, static fn(bool $exists): bool => !$exists)) === 10
        && count(array_filter($legacyStorage, static fn(bool $exists): bool => $exists)) === 27,
    'production-shaped direct storage has exactly ten absent initializers and twenty-seven existing formulas'
);
$targetStorageRows = [];
foreach ($next['rows'] as $row) {
    $targetStorageRows[] = [
        'IBLOCK_ELEMENT_ID' => (string)$row['id'],
        'INITIAL_VALUE_RAW' => serialize(['TEXT' => (string)$row['initialValue'], 'TYPE' => 'TEXT']),
    ];
}
$targetStorage = $normalizeStorageMethod->invoke(null, $targetStorageRows);
$assert(
    count(array_filter($targetStorage, static fn(bool $exists): bool => $exists)) === 37,
    'post-migration direct storage preserves all formulas including 0, false and empty-string literals'
);
$prospectiveRuntimeRows = $runtimeRowsMethod->invoke(null, $next);
$assert(
    is_array($prospectiveRuntimeRows)
        && count($prospectiveRuntimeRows) === Preset12740NeutralGlobalSymbolMigrationService::EXPECTED_PROSPECTIVE_RUNTIME_ROW_COUNT
        && array_keys($prospectiveRuntimeRows[0]) === ['id', 'code', 'kind', 'dataType', 'presetId', 'active', 'initialValue']
        && ($prospectiveRuntimeRows[0]['id'] ?? 0) === 12777
        && ($prospectiveRuntimeRows[array_key_last($prospectiveRuntimeRows)]['id'] ?? 0) === 13093,
    'capability preview is the exact sorted minimal prospective 37-row runtime payload'
);
$backup = [
    'contract' => Preset12740NeutralGlobalSymbolMigrationService::CONTRACT,
    'presetId' => 12740,
    'fingerprint' => $plan['fingerprint'],
    'state' => $legacyState,
];
$backupRaw = $canonicalMethod->invoke(null, $backup);
$assert(
    substr_count($backupRaw, '"initialValueExists":false') === 10
        && substr_count($backupRaw, '"initialValueExists":true') === 27,
    'immutable backup records the exact ten absent and twenty-seven present INITIAL_VALUE rows'
);
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
$staleV1Backup = $backup;
$staleV1Backup['contract'] = 'prospektweb.calc.preset-12740-neutral-global-symbol-migration/v1';
$staleV1BackupRaw = $canonicalMethod->invoke(null, $staleV1Backup);
$staleV1Target = $legacyState;
foreach ($staleV1Target['rows'] as &$staleV1TargetRow) {
    $staleV1Code = (string)($staleV1TargetRow['code'] ?? '');
    if (isset($symbols[$staleV1Code])) {
        $staleV1TargetRow['initialValue'] = $symbols[$staleV1Code]['neutral'];
    }
}
unset($staleV1TargetRow);
$staleV1Marker = $marker;
$staleV1Marker['contract'] = 'prospektweb.calc.preset-12740-neutral-global-symbol-migration/v1';
$staleV1Marker['afterFingerprint'] = $fingerprintMethod->invoke(null, $staleV1Target);
$staleV1Marker['backupHash'] = hash('sha256', $staleV1BackupRaw);
try {
    $historicalEvidenceMethod->invoke(null, json_encode($staleV1Marker), $staleV1BackupRaw);
    $assert(false, 'stale fourteen-row v1 evidence must never be reinterpreted as the twenty-four-row v2 contract');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'stale v1 marker and backup fail closed under the v2 migration contract');
}
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
$expectedActiveMethod->invoke(null, ['value' => 'Y'], 'Y');
$expectedActiveMethod->invoke(null, ['value' => 'N'], 'N');
try {
    $expectedActiveMethod->invoke(null, ['value' => 'N'], 'Y');
    $assert(false, 'a concurrent ACTIVE Y-to-N transition must fail the migration CAS');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'the exact expected ACTIVE value is enforced under the migration lock');
}
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
    'initialValueExists' => true,
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
$assert(strpos($source, "count((array)(\$plan['mutations'] ?? [])) !== self::EXPECTED_MUTATION_COUNT") !== false, 'apply requires the exact twenty-four-row atomic plan');
$assert(
    strpos($source, 'public function apply(string $expectedFingerprint, string $expectedActive): array') !== false
        && substr_count($source, 'self::assertExpectedActiveSnapshot(') >= 2,
    'apply binds the caller-audited ACTIVE value and rechecks it inside the transaction before writes'
);
$assert(
    strpos($source, 'public function previewProspectiveRuntimeRows(string $expectedFingerprint): array') !== false
        && strpos($source, 'count($rows) !== self::EXPECTED_PROSPECTIVE_RUNTIME_ROW_COUNT') !== false,
    'signed capability probing receives only the fingerprint-bound exact prospective 37-row registry'
);
$assert(strpos($source, "unset(\$state['active'])") !== false, 'fingerprint deliberately excludes activation state');
$assert(strpos($source, "SELECT ID FROM b_iblock_element WHERE IBLOCK_ID=") !== false, 'migration locks the full registry membership range');
$assert(strpos($source, "b_iblock_element_prop_s") !== false && strpos($source, "b_iblock_element_prop_m") !== false, 'migration locks both versioned property tables');
$assert(strpos($source, "Global-symbol migration option delete read-back failed.") !== false, 'rollback verifies marker deletion by direct database read-back');
$assert(
    strpos($source, "'table' => 'b_iblock_element_prop_s' . \$iblockId") !== false
        && strpos($source, "'column' => 'PROPERTY_' . \$propertyId") !== false
        && strpos($source, 'getTableFields($authority[\'table\'])') !== false
        && strpos($source, "array_key_exists('INITIAL_VALUE_RAW', \$row)") !== false
        && strpos($source, '$rawValue !== null') !== false
        && strpos($source, 'PROPERTY_VALUE_ID') === false
        && strpos($source, 'if ($initialValueExists === false)') !== false,
    'state presence comes only from pinned VERSION=2 SQL storage and rollback keeps clear-only absence'
);
$assert(
    strpos($source, "|| (int)(\$iblock['VERSION'] ?? 0) !== 2") !== false
        && strpos($source, ". (\$forUpdate ? ' FOR UPDATE' : '')") !== false
        && substr_count($source, '$this->loadState($lockedConfig, $lockedActive, true)') >= 4,
    'production storage is VERSION=2-only and every transaction-locked reread is a current storage read'
);
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
$correctionSource = file_get_contents(dirname(__DIR__) . '/lib/Install/Preset12740NeutralGlobalSymbolCorrectionMigrationService.php');
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
    'V1 takes one transaction-scoped ownership-correction gate before either activation branch'
);
$lockedGateStart = strpos($correctionSource, 'public function assertActivationReadyLocked(');
$runtimeBoundaryStart = strpos($correctionSource, 'public static function assertNeutralRuntimeRows(');
$lockedGateSource = is_int($lockedGateStart) && is_int($runtimeBoundaryStart)
    ? substr($correctionSource, $lockedGateStart, $runtimeBoundaryStart - $lockedGateStart)
    : '';
$assert(
    strpos($lockedGateSource, '$this->lockOptionAuthorityRows();') !== false
        && strpos($lockedGateSource, '$this->lockRegistryRows(') !== false
        && strpos($lockedGateSource, '$this->loadState(') !== false
        && strpos($lockedGateSource, '$this->lockOptionAuthorityRows();')
            < strpos($lockedGateSource, '$this->lockRegistryRows(')
        && strpos($lockedGateSource, '$this->lockRegistryRows(')
            < strpos($lockedGateSource, '$this->loadState('),
    'the correction activation gate orders options before the complete registry lock and exact state reread'
);
$rollbackGateStart = strpos($correctionSource, 'public function assertV1RollbackReadyLocked(');
$rollbackGateSource = is_int($rollbackGateStart) && is_int($runtimeBoundaryStart)
    ? substr($correctionSource, $rollbackGateStart, $runtimeBoundaryStart - $rollbackGateStart)
    : '';
$assert(
    $rollbackGateSource !== ''
        && strpos($rollbackGateSource, '$this->lockRegistryRows(') !== false
        && strpos($rollbackGateSource, '$this->buildAuditedPlan(') !== false
        && strpos($rollbackGateSource, "(\$plan['evidenceVerified'] ?? false) !== true") !== false
        && strpos($rollbackGateSource, "(\$plan['customized'] ?? true) !== false") !== false
        && strpos($rollbackGateSource, "\$status === 'pending'") !== false
        && strpos($rollbackGateSource, '$markerRaw !==') !== false,
    'V1 rollback holds the correction registry lock and accepts only uncustomized complete or exact pending state'
);
$assert(
    is_string($inputMigrationSource)
        && substr_count($inputMigrationSource, '->assertV1RollbackReadyLocked(true);') === 1,
    'V1 rollback performs the transaction-scoped symmetric correction recovery gate'
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
        && substr_count($globalServiceSource, 'Preset12740NeutralGlobalSymbolCorrectionMigrationService::assertNeutralRuntimeRows(') >= 2,
    'global authoring validates the corrected required registry before and after an active write'
);
$assert(
    is_string($initSource)
        && substr_count($initSource, 'Preset12740NeutralGlobalSymbolCorrectionMigrationService::assertNeutralRuntimeRows(') >= 3,
    'manual and catalogue INIT payloads validate the corrected neutral global registry'
);

fwrite(STDOUT, "OK\n");
