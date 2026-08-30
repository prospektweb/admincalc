<?php

$root = dirname(__DIR__);
$gateway = (string)file_get_contents($root . '/lib/Services/AiGatewayService.php');
$materialization = (string)file_get_contents($root . '/lib/Services/AiLogicPilotMaterializationService.php');
$repair = (string)file_get_contents($root . '/lib/Services/AiLogicPilotRepairService.php');
$bridge = (string)file_get_contents($root . '/install/assets/js/integration.js');

$checks = [
    'structural pilot zone exists' => strpos($gateway, "'logic_structure_pilot'") !== false,
    'draft response contract is versioned' => strpos($gateway, 'prospektweb.calc.ai-logic-pilot-draft/v1') !== false,
    'response echoes exact preset context' => strpos($gateway, "'presetId' => max(1, (int)(\$context['presetId'] ?? 0))") !== false,
    'response echoes exact version context' => strpos($gateway, "'versionKey' => mb_substr") !== false,
    'response echoes exact snapshot hash' => strpos($gateway, "'baseCompileHash' => mb_substr") !== false,
    'response echoes request token' => strpos($gateway, "'requestToken' => mb_substr") !== false,
    'response identity is normalized from trusted request context' => strpos($gateway, "\$decoded['context'] = [") !== false,
    'response mode is normalized from trusted request context' => strpos($gateway, "\$decoded['mode'] = \$pilotModeCode") !== false,
    'response level is normalized from trusted request context' => strpos($gateway, "\$decoded['level'] = \$pilotLevelCode") !== false,
    'response scheme is normalized from trusted request context' => strpos($gateway, "\$decoded['scheme'] = \$pilotSchemeCode") !== false,
    'acceptance copy removes virtual labels' => strpos($gateway, 'sanitizePilotAcceptanceCopy') !== false,
    'saved pilot prompt is migrated away from virtual labels' => strpos($gateway, "mb_stripos(\$template['prompt'], 'Виртуальным материалам')") !== false,
    'pilot prompt forbids virtual wording' => strpos($gateway, 'Не используй слово «виртуальный»') !== false,
    'pilot schema demonstrates every catalog entity kind' => strpos($gateway, "'draftId' => 'draft_material_variant_001', 'kind' => 'materialVariant'") !== false
        && strpos($gateway, "'draftId' => 'draft_operation_variant_001', 'kind' => 'operationVariant'") !== false
        && strpos($gateway, "'draftId' => 'draft_equipment_001', 'kind' => 'equipment'") !== false
        && strpos($gateway, "'draftId' => 'draft_custom_field_001', 'kind' => 'customField'") !== false
        && strpos($gateway, "'draftId' => 'draft_calculator_001', 'kind' => 'calculator'") !== false,
    'pilot prompt requires distinct stage calculators and production operations' => strpos($gateway, 'Для каждого этапа создай отдельный calculator, а отдельный operationVariant — для каждого производственного этапа с requiresConfiguration=true') !== false
        && strpos($gateway, 'запрещено ссылать из двух производственных этапов') !== false,
    'pilot prompt forbids catch-all stage entity lists' => strpos($gateway, 'запрещено копировать одинаковый полный catalogDraftIds во все этапы') !== false,
    'pilot prompt requires granular stage routes' => strpos($gateway, 'Для уровня detailed создай не менее 4 этапов, для professional — не менее 6') !== false
        && strpos($gateway, 'В одном этапе допустимо не более одного materialVariant, operationVariant, equipment и calculator') !== false,
    'detailed pilot requires concrete candidates' => strpos($gateway, 'Для уровня detailed предлагай конкретные кандидаты каталога') !== false,
    'server rejects low quality pilot topology' => strpos($gateway, 'validatePilotStructureQuality') !== false
        && strpos($gateway, 'не смог построить пригодную производственную структуру') !== false,
    'server retries one bounded topology repair' => strpos($gateway, 'Ответ не проходит обязательную проверку производственной структуры') !== false
        && strpos($gateway, 'AI-пилот не смог построить пригодную производственную структуру') !== false,
    'pilot prompt forbids base objects in stage links' => strpos($gateway, 'не ссылайся там на базовые material/operation') !== false,
    'prompt forbids real records and formulas' => strpos($gateway, 'Не добавляй реальные ID, sourcePath, формулы') !== false,
    'prompt requires exact context echo' => strpos($gateway, 'Скопируй context из обязательной схемы без единого изменения') !== false,
    'pilot schema shows branch mode' => strpos($gateway, "'draftId' => 'draft_branch_001', 'title' => '', 'mode' => 'and'") !== false,
    'pilot schema shows symbolic operands' => strpos($gateway, "'kind' => 'variable', 'code' => 'needs_lamination'") !== false,
    'pilot schema shows explicit else branch' => strpos($gateway, "'draftId' => 'draft_branch_else_001'") !== false,
];

$checks += [
    'calculator objects materialize to CALC_SETTINGS' => strpos($materialization, "'calculator' => 'CALC_SETTINGS'") !== false,
    'apply is scoped to wide format preset' => strpos($materialization, 'private const TARGET_PRESET_ID = 16488') !== false,
    'known sheet preset is explicitly forbidden' => strpos($materialization, 'private const FORBIDDEN_PRESET_ID = 12740') !== false,
    'apply requires explicit confirmation' => strpos($materialization, "explicitConfirm") !== false,
    'apply requires idempotency' => strpos($materialization, "idempotencyKey") !== false,
    'variant parent link is persisted atomically' => strpos($materialization, "'PROPERTY_VALUES'") !== false
        && strpos($materialization, 'skuLinkProperty') !== false
        && strpos($materialization, 'assertSkuParentReadback') !== false,
    'repair uses explicit sku property id' => strpos($repair, "'variant_parent'") !== false
        && strpos($repair, 'SetPropertyValues($id,$iblockId') !== false
        && strpos($repair, "'LINK_IBLOCK_ID'") !== false
        && strpos($repair, 'GetInfoByProductIBlock') !== false
        && strpos($repair, 'GetProductInfo') !== false
        && strpos($repair, "['PRODUCT_IBLOCK_ID']") !== false
        && strpos($repair, 'readSkuParentId') !== false,
    'future materialization reads every link back' => strpos($materialization, 'setAndVerifyPropertyValues') !== false
        && strpos($materialization, 'SetPropertyValues($elementId, $iblockId, $expected') !== false
        && strpos($materialization, 'readPropertyIntValues') !== false
        && strpos($materialization, 'GetProductInfo') !== false
        && strpos($materialization, "['PRODUCT_IBLOCK_ID']") !== false
        && strpos($materialization, 'Bitrix не сохранил связь AI-пилота') !== false,
    'historical receipts cannot replay without readback' => strpos($materialization, "['readbackVerified']") !== false
        && strpos($materialization, 'требует проверки и восстановления связей') !== false,
    'repair revalidates receipts and version graph snapshots' => strpos($repair, "['needsSnapshotRefresh']") !== false
        && strpos($repair, 'VERSION_GRAPH_SNAPSHOT_MISMATCH') !== false
        && strpos($repair, "['idempotentReplay' => true] + \$previous") !== false,
    'repair reads graph properties by authoritative property id' => strpos($repair, "'stage' => ['MATERIAL_VARIANT','OPERATION_VARIANT','EQUIPMENT','CALC_SETTINGS']") !== false
        && strpos($repair, "'detail' => ['CALC_STAGES','DETAILS']") !== false
        && strpos($repair, "'preset' => ['CALC_DETAILS']") !== false,
    'repair resolves repeated stage entity kinds deterministically' => strpos($repair, '$expectedProps[$property] = (int)$ids[$catalogDraftId]') !== false
        && strpos($repair, 'foreach ($expectedProps as $property => $expectedId)') !== false,
    'variant folder ids are never copied across iblocks' => strpos($materialization, "in_array(\$kind, ['materialVariant','operationVariant'], true)\n                        ? ''") !== false,
    'repair never creates or deletes entities' => strpos($repair, '->Add(') === false
        && strpos($repair, '->Delete(') === false,
    'repair is hard scoped to wide format preset' => strpos($repair, 'private const TARGET_PRESET_ID = 16488') !== false
        && strpos($repair, 'private const FORBIDDEN_PRESET_ID = 12740') !== false,
    'bridge exposes candidate transport' => strpos($bridge, 'LOAD_AI_LOGIC_PILOT_REPLACEMENT_CANDIDATES_REQUEST') !== false,
    'bridge exposes manifest preview transport' => strpos($bridge, 'PREVIEW_AI_LOGIC_PILOT_MANIFEST_REQUEST') !== false,
    'bridge exposes manifest apply transport' => strpos($bridge, 'APPLY_AI_LOGIC_PILOT_MANIFEST_REQUEST') !== false,
    'bridge exposes applied graph inspection' => strpos($bridge, 'INSPECT_AI_LOGIC_PILOT_APPLICATION_REQUEST') !== false,
    'bridge exposes explicit repair transport' => strpos($bridge, 'REPAIR_AI_LOGIC_PILOT_APPLICATION_REQUEST') !== false,
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "Failed: {$label}\n");
        exit(1);
    }
}

require_once $root . '/lib/Services/AiGatewayService.php';
$service = new \Prospektweb\Calc\Services\AiGatewayService();
$method = new ReflectionMethod($service, 'sanitizePilotAcceptanceCopy');
$clean = $method->invoke($service, [
    'summary' => 'Каталог виртуальных под-калькуляторов',
    'catalogObjects' => [[
        'title' => 'Виртуальная операция: Печать',
        'description' => 'Виртуальная операция печати, ожидающая входы.',
        'draftId' => 'draft_operation_print',
    ]],
]);
if (($clean['summary'] ?? '') !== 'Каталог под-калькуляторов'
    || ($clean['catalogObjects'][0]['title'] ?? '') !== 'Операция: Печать'
    || ($clean['catalogObjects'][0]['description'] ?? '') !== 'Операция печати, ожидающая входы.'
    || ($clean['catalogObjects'][0]['draftId'] ?? '') !== 'draft_operation_print') {
    fwrite(STDERR, "Failed: acceptance copy sanitizer\n");
    exit(1);
}

$quality = new ReflectionMethod($service, 'validatePilotStructureQuality');
$bad = $quality->invoke($service, [
    'catalogObjects' => [
        ['draftId' => 'draft_operation_1', 'kind' => 'operationVariant', 'title' => 'Операция для производства', 'description' => ''],
        ['draftId' => 'draft_calculator_1', 'kind' => 'calculator', 'title' => 'Калькулятор этапа', 'description' => ''],
    ],
    'stages' => [
        ['title' => 'Печать', 'catalogDraftIds' => ['draft_operation_1', 'draft_calculator_1']],
        ['title' => 'Резка', 'catalogDraftIds' => ['draft_operation_1', 'draft_calculator_1']],
    ],
], 'detailed');
if ($bad === []
    || strpos(implode(' ', $bad), 'нескольких этапах') === false
    || strpos(implode(' ', $bad), 'обобщённое название') === false
    || strpos(implode(' ', $bad), 'Нет конкретных видов материалов') === false) {
    fwrite(STDERR, "Failed: pilot structure quality gate\n");
    exit(1);
}

echo "AI logic pilot static checks passed\n";
