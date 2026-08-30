<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\HttpClient;

final class AiGatewayService
{
    private const MODULE_ID = 'prospektweb.calc';
    private const BASE_URL = 'https://api.timeweb.ai/v1';
    private const KEY_OPTION = 'AI_GATEWAY_API_KEY';
    private const TEMPLATES_OPTION = 'AI_PROMPT_TEMPLATES';
    private const MODELS_CACHE_OPTION = 'AI_MODELS_CACHE';
    private const MODELS_CACHE_TTL = 600;
    private const DEFAULT_MODEL = 'openai/gpt-5.4-mini';
    private const LOGIC_REQUEST_SCHEMA = 'prospektweb.calc.ai-logic-request/v1';
    private const LOGIC_PROPOSAL_SCHEMA = 'prospektweb.calc.ai-logic-proposal/v1';
    private const STAGE_LOGIC_REQUEST_SCHEMA = 'prospektweb.calc.ai-stage-logic-request/v1';
    private const STAGE_LOGIC_PROPOSAL_SCHEMA = 'prospektweb.calc.ai-stage-logic-proposal/v1';
    private const LOGIC_AUDIT_REQUEST_SCHEMA = 'prospektweb.calc.ai-logic-audit-request/v1';
    private const LOGIC_AUDIT_PROPOSAL_SCHEMA = 'prospektweb.calc.ai-logic-audit-proposal/v1';
    private const LOGIC_SYMBOL_TYPES = ['number', 'string', 'bool', 'array', 'any', 'unknown'];
    private const LOGIC_SYMBOL_KINDS = ['input', 'variable', 'global-variable', 'global-constant'];
    private const LOGIC_FORBIDDEN_KEYS = [
        'sourcePath',
        'settingsId',
        'stageId',
        'presetId',
        'detailId',
        'calculatorId',
        'elementId',
        'iblockId',
    ];
    private const ZONE_CONTEXT = [
        'preset_description' => ['{название пресета}' => 'presetName', '{название товара}' => 'productName', '{анонс товара}' => 'productPreview'],
        'detail_description' => ['{название детали}' => 'detailName', '{название пресета}' => 'presetName', '{анонс пресета}' => 'presetPreview', '{название товара}' => 'productName', '{анонс товара}' => 'productPreview'],
        'stage_description' => ['{название этапа}' => 'stageName', '{название детали}' => 'detailName', '{анонс детали}' => 'detailPreview', '{название пресета}' => 'presetName', '{анонс пресета}' => 'presetPreview', '{название товара}' => 'productName', '{анонс товара}' => 'productPreview'],
        'calculator_description' => ['{название калькулятора}' => 'calculatorName', '{Источники данных}' => 'sourceLinks'],
        'operation_description' => ['{название операции}' => 'operationName', '{Источники данных}' => 'sourceLinks'],
        'operation_variant_description' => ['{название варианта операции}' => 'operationVariantName', '{название операции}' => 'operationName', '{анонс операции}' => 'operationPreview', '{Источники данных}' => 'sourceLinks'],
        'equipment_description' => ['{название оборудования}' => 'equipmentName', '{Источники данных}' => 'equipmentSources'],
        'material_description' => ['{название материала}' => 'materialName', '{Источники данных}' => 'sourceLinks'],
        'material_variant_description' => ['{название варианта материала}' => 'materialVariantName', '{название материала}' => 'materialName', '{анонс материала}' => 'materialPreview', '{Источники данных}' => 'sourceLinks'],
        'logic_formula' => [],
        'logic_stage' => [],
        'logic_audit' => [],
        'logic_structure_pilot' => [
            '{контекст формы}' => 'formContext',
            '{текущая схема}' => 'currentStructure',
            '{режим пилота}' => 'pilotMode',
            '{уровень проработки}' => 'pilotLevel',
            '{тип схемы}' => 'schemeMode',
            '{пожелания пользователя}' => 'wishes',
        ],
    ];
    private const STRUCTURED_ZONES = [
        'calculator_description',
        'operation_description',
        'operation_variant_description',
        'equipment_description',
        'material_description',
        'material_variant_description',
        'logic_structure_pilot',
    ];
    private const LOGIC_STRUCTURE_PILOT_RESPONSE_SCHEMA = [
        'schema' => 'prospektweb.calc.ai-logic-pilot-draft/v1',
        'draftId' => 'draft_logic_001',
        'context' => [
            'presetId' => 0,
            'versionKey' => '',
            'baseCompileHash' => '',
            'requestToken' => '',
        ],
        'mode' => 'create',
        'level' => 'detailed',
        'scheme' => 'simple',
        'summary' => '',
        'assumptions' => [''],
        'warnings' => [''],
        'globals' => [[
            'draftId' => 'draft_global_001', 'kind' => 'variable', 'dataType' => 'boolean',
            'code' => 'needs_lamination', 'title' => '', 'description' => '',
        ]],
        'catalogFolders' => [
            ['draftId' => 'draft_folder_material_001', 'kind' => 'material', 'title' => '', 'description' => '', 'parentDraftId' => null],
            ['draftId' => 'draft_folder_operation_001', 'kind' => 'operation', 'title' => '', 'description' => '', 'parentDraftId' => null],
            ['draftId' => 'draft_folder_equipment_001', 'kind' => 'equipment', 'title' => '', 'description' => '', 'parentDraftId' => null],
            ['draftId' => 'draft_folder_custom_field_001', 'kind' => 'customField', 'title' => '', 'description' => '', 'parentDraftId' => null],
            ['draftId' => 'draft_folder_calculator_001', 'kind' => 'calculator', 'title' => '', 'description' => '', 'parentDraftId' => null],
        ],
        'catalogObjects' => [
            ['draftId' => 'draft_material_001', 'kind' => 'material', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_material_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_material_variant_001', 'kind' => 'materialVariant', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_material_001', 'parentDraftId' => 'draft_material_001', 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_material_002', 'kind' => 'material', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_material_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_material_variant_002', 'kind' => 'materialVariant', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_material_001', 'parentDraftId' => 'draft_material_002', 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_operation_001', 'kind' => 'operation', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_operation_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_operation_variant_001', 'kind' => 'operationVariant', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_operation_001', 'parentDraftId' => 'draft_operation_001', 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_operation_002', 'kind' => 'operation', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_operation_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_operation_variant_002', 'kind' => 'operationVariant', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_operation_001', 'parentDraftId' => 'draft_operation_002', 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_operation_003', 'kind' => 'operation', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_operation_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_operation_variant_003', 'kind' => 'operationVariant', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_operation_001', 'parentDraftId' => 'draft_operation_003', 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_operation_004', 'kind' => 'operation', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_operation_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_operation_variant_004', 'kind' => 'operationVariant', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_operation_001', 'parentDraftId' => 'draft_operation_004', 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_operation_005', 'kind' => 'operation', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_operation_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_operation_variant_005', 'kind' => 'operationVariant', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_operation_001', 'parentDraftId' => 'draft_operation_005', 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_operation_006', 'kind' => 'operation', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_operation_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_operation_variant_006', 'kind' => 'operationVariant', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_operation_001', 'parentDraftId' => 'draft_operation_006', 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_equipment_001', 'kind' => 'equipment', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_equipment_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_equipment_002', 'kind' => 'equipment', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_equipment_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_equipment_003', 'kind' => 'equipment', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_equipment_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_equipment_004', 'kind' => 'equipment', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_equipment_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_custom_field_001', 'kind' => 'customField', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_custom_field_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_calculator_001', 'kind' => 'calculator', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_calculator_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_calculator_002', 'kind' => 'calculator', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_calculator_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_calculator_003', 'kind' => 'calculator', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_calculator_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_calculator_004', 'kind' => 'calculator', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_calculator_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_calculator_005', 'kind' => 'calculator', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_calculator_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
            ['draftId' => 'draft_calculator_006', 'kind' => 'calculator', 'title' => '', 'description' => '', 'folderDraftId' => 'draft_folder_calculator_001', 'parentDraftId' => null, 'intendedInputs' => [''], 'intendedMappings' => ['']],
        ],
        'details' => [[
            'draftId' => 'draft_detail_001', 'kind' => 'detail', 'title' => '',
            'description' => '', 'parentDraftId' => null,
        ]],
        'stages' => [[
            'draftId' => 'draft_stage_001', 'detailDraftId' => 'draft_detail_001',
            'title' => '', 'description' => '', 'catalogDraftIds' => ['draft_material_variant_001', 'draft_operation_variant_001', 'draft_equipment_001', 'draft_custom_field_001', 'draft_calculator_001'],
            'requiresConfiguration' => true,
        ], [
            'draftId' => 'draft_stage_002', 'detailDraftId' => 'draft_detail_001',
            'title' => '', 'description' => '', 'catalogDraftIds' => ['draft_operation_variant_002', 'draft_equipment_002', 'draft_calculator_002'],
            'requiresConfiguration' => true,
        ], [
            'draftId' => 'draft_stage_003', 'detailDraftId' => 'draft_detail_001',
            'title' => '', 'description' => '', 'catalogDraftIds' => ['draft_material_variant_002', 'draft_operation_variant_003', 'draft_equipment_003', 'draft_calculator_003'],
            'requiresConfiguration' => true,
        ], [
            'draftId' => 'draft_stage_004', 'detailDraftId' => 'draft_detail_001',
            'title' => '', 'description' => '', 'catalogDraftIds' => ['draft_operation_variant_004', 'draft_equipment_004', 'draft_calculator_004'],
            'requiresConfiguration' => true,
        ], [
            'draftId' => 'draft_stage_005', 'detailDraftId' => 'draft_detail_001',
            'title' => '', 'description' => '', 'catalogDraftIds' => ['draft_operation_variant_005', 'draft_calculator_005'],
            'requiresConfiguration' => true,
        ], [
            'draftId' => 'draft_stage_006', 'detailDraftId' => 'draft_detail_001',
            'title' => '', 'description' => '', 'catalogDraftIds' => ['draft_operation_variant_006', 'draft_calculator_006'],
            'requiresConfiguration' => true,
        ]],
        'groups' => [[
            'draftId' => 'draft_group_001', 'kind' => 'group', 'title' => '', 'description' => '',
            'parentDraftId' => null, 'stageDraftIds' => ['draft_stage_001', 'draft_stage_002', 'draft_stage_003', 'draft_stage_006'], 'branches' => [],
        ], [
            'draftId' => 'draft_condition_001', 'kind' => 'condition', 'title' => '', 'description' => '',
            'parentDraftId' => 'draft_group_001', 'stageDraftIds' => [], 'branches' => [[
                'draftId' => 'draft_branch_001', 'title' => '', 'mode' => 'and',
                'operands' => [[
                    'kind' => 'variable', 'code' => 'needs_lamination',
                ]],
                'stageDraftIds' => ['draft_stage_004'], 'isElse' => false,
            ], [
                'draftId' => 'draft_branch_else_001', 'title' => '', 'mode' => 'and',
                'operands' => [], 'stageDraftIds' => ['draft_stage_005'], 'isElse' => true,
            ]],
        ]],
    ];
    private const CATALOG_RESPONSE_SCHEMA = [
        'version' => 1,
        'previewText' => '',
        'detailHtml' => '',
        'parameters' => [['code' => '', 'value' => '', 'title' => '', 'description' => '']],
        'catalog' => [
            'vatId' => null,
            'vatIncluded' => null,
            'purchasingPrice' => null,
            'purchasingCurrency' => '',
            'basePrice' => null,
            'baseCurrency' => '',
            'weightG' => null,
            'lengthMm' => null,
            'widthMm' => null,
            'heightMm' => null,
        ],
    ];
    private const EQUIPMENT_RESPONSE_SCHEMA = [
        'version' => 1,
        'previewText' => '',
        'detailHtml' => '',
        'startCost' => null,
        'workspace' => ['lengthMm' => null, 'widthMm' => null],
        'technicalMarginsMm' => ['top' => null, 'right' => null, 'bottom' => null, 'left' => null],
        'materialTolerancesMm' => ['minWidth' => null, 'minLength' => null],
        'parameters' => [['code' => '', 'value' => '', 'title' => '', 'description' => '']],
        'catalog' => [
            'vatRate' => null,
            'vatIncluded' => null,
            'purchasingPrice' => null,
            'purchasingCurrency' => '',
            'basePrice' => null,
            'baseCurrency' => '',
            'weightG' => null,
            'lengthMm' => null,
            'widthMm' => null,
            'heightMm' => null,
        ],
    ];

    public function getSettings(): array
    {
        $this->assertAdmin();
        $apiKey = trim((string)Option::get(self::MODULE_ID, self::KEY_OPTION, ''));
        $cached = json_decode((string)Option::get(self::MODULE_ID, self::MODELS_CACHE_OPTION, ''), true);
        $models = is_array($cached) && is_array($cached['models'] ?? null)
            ? array_values($cached['models'])
            : [];
        return [
            'status' => 'ok',
            'hasApiKey' => $apiKey !== '',
            'templates' => $this->getTemplates(),
            'models' => $models,
            'modelsError' => '',
            'modelsCacheStale' => !is_array($cached) || (int)($cached['expiresAt'] ?? 0) <= time(),
        ];
    }

    public function saveSettings(array $request): array
    {
        $this->assertAdmin();
        $apiKey = trim((string)($request['apiKey'] ?? ''));
        if ($apiKey !== '') {
            Option::set(self::MODULE_ID, self::KEY_OPTION, $apiKey);
        }
        $templates = $this->sanitizeTemplates(is_array($request['templates'] ?? null) ? $request['templates'] : []);
        foreach ($templates as &$template) {
            if ($template['model'] === '') $template['model'] = self::DEFAULT_MODEL;
        }
        unset($template);
        $encodedTemplates = json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encodedTemplates)) {
            throw new \RuntimeException('Не удалось сериализовать шаблоны AI-сервиса');
        }
        Option::set(self::MODULE_ID, self::TEMPLATES_OPTION, $encodedTemplates);

        // This method executes under the global calculator transaction. Its
        // readback must remain local and deterministic: model discovery/cache
        // belongs to a separate non-authoritative read contour.
        $storedTemplates = json_decode((string)Option::get(self::MODULE_ID, self::TEMPLATES_OPTION, ''), true);
        if (!is_array($storedTemplates)
            || self::canonicalTemplates($this->sanitizeTemplates($storedTemplates))
                !== self::canonicalTemplates($templates)
            || ($apiKey !== ''
                && !hash_equals($apiKey, trim((string)Option::get(self::MODULE_ID, self::KEY_OPTION, ''))))) {
            throw new \RuntimeException('Проверка сохранённых настроек AI-сервиса не прошла');
        }
        return $this->getSettings();
    }

    /** @param mixed $templates */
    private static function canonicalTemplates($templates): string
    {
        if (!is_array($templates)) {
            return '';
        }
        $encoded = json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '';
    }

    public function generateStagePreview(array $request): array
    {
        $request['zone'] = 'stage_description';
        return $this->generateText($request);
    }

    public function generateText(array $request): array
    {
        $this->assertAdmin();
        $templateId = trim((string)($request['templateId'] ?? ''));
        $zone = trim((string)($request['zone'] ?? ''));
        if (!isset(self::ZONE_CONTEXT[$zone])) throw new \InvalidArgumentException('Неизвестная зона AI-шаблона');
        $template = null;
        foreach ($this->getTemplates() as $candidate) if ((string)$candidate['id'] === $templateId) { $template = $candidate; break; }
        if ($template === null || $template['zone'] !== $zone) throw new \InvalidArgumentException('Шаблон промпта не найден или относится к другой зоне');
        if (trim((string)$template['model']) === '') throw new \InvalidArgumentException('Для шаблона не выбрана модель');
        $context = is_array($request['context'] ?? null) ? $request['context'] : [];
        if ($zone === 'logic_structure_pilot') {
            $pilotPresetId = (int)($context['presetId'] ?? 0);
            $pilotVersionKey = trim((string)($context['versionKey'] ?? ''));
            $pilotBaseCompileHash = strtolower(trim((string)($context['baseCompileHash'] ?? '')));
            $pilotRequestToken = trim((string)($context['requestToken'] ?? ''));
            $pilotModeCode = trim((string)($context['pilotModeCode'] ?? ''));
            $pilotLevelCode = trim((string)($context['pilotLevelCode'] ?? ''));
            $pilotSchemeCode = trim((string)($context['schemeCode'] ?? ''));
            if ($pilotPresetId <= 0 || $pilotVersionKey === '' || strlen($pilotVersionKey) > 180
                || !preg_match('/^[A-Za-z0-9_.:-]+$/', $pilotVersionKey)
                || !preg_match('/^[a-f0-9]{64}$/', $pilotBaseCompileHash)
                || $pilotRequestToken === '' || strlen($pilotRequestToken) > 180
                || !in_array($pilotModeCode, ['create', 'augment', 'replace'], true)
                || !in_array($pilotLevelCode, ['simple', 'detailed', 'professional'], true)
                || !in_array($pilotSchemeCode, ['simple', 'complex'], true)) {
                throw new \InvalidArgumentException('Некорректный контекст AI-пилота структуры.');
            }
        }
        $tags = [];
        foreach (self::ZONE_CONTEXT[$zone] as $tag => $contextKey) $tags[$tag] = (string)($context[$contextKey] ?? '');
        $override = trim((string)($request['prompt'] ?? ''));
        $prompt = strtr($override !== '' ? mb_substr($override, 0, 12000) : (string)$template['prompt'], $tags);
        if (in_array($zone, self::STRUCTURED_ZONES, true)) {
            $schema = $zone === 'equipment_description'
                ? self::EQUIPMENT_RESPONSE_SCHEMA
                : ($zone === 'logic_structure_pilot' ? self::LOGIC_STRUCTURE_PILOT_RESPONSE_SCHEMA : self::CATALOG_RESPONSE_SCHEMA);
            if ($zone === 'logic_structure_pilot') {
                $schema['context'] = [
                    'presetId' => max(1, (int)($context['presetId'] ?? 0)),
                    'versionKey' => mb_substr(trim((string)($context['versionKey'] ?? '')), 0, 180),
                    'baseCompileHash' => mb_substr(trim((string)($context['baseCompileHash'] ?? '')), 0, 64),
                    'requestToken' => mb_substr(trim((string)($context['requestToken'] ?? '')), 0, 180),
                ];
            }
            $prompt .= "\n\nОбязательная схема ответа JSON:\n"
                . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                . ($zone === 'logic_structure_pilot'
                    ? "\nНе добавляй поля вне схемы. Скопируй context из обязательной схемы без единого изменения: он связывает ответ с конкретным калькулятором и запросом. Используй только стабильные строковые draftId с префиксом draft_. Не добавляй реальные ID, sourcePath, формулы, значения глобальных переменных, цены или физические записи. У каждого condition должна быть ровно одна ветка isElse=true."
                        . "\nСтруктура должна быть технологически правдоподобной, а не демонстрационной. Каждая независимо рассчитываемая производственная операция должна быть отдельным этапом: не объединяй печать, сушку, ламинацию, резку, обработку краёв, монтаж и упаковку в одну карточку. Для уровня detailed создай не менее 4 этапов, для professional — не менее 6. Для каждого этапа создай отдельный calculator, а отдельный operationVariant — для каждого производственного этапа с requiresConfiguration=true; один calculator или operationVariant запрещено ссылать из двух производственных этапов."
                        . "\nВ одном этапе допустимо не более одного materialVariant, operationVariant, equipment и calculator. Альтернативные материалы, оборудование и технологические маршруты разделяй условиями и ветвями с отдельными этапами. Для detailed и professional создай хотя бы одно condition по необязательному или альтернативному значению формы. Не перечисляй несколько самостоятельных операций в названии одной карточки через запятую, косую черту или слово «или». Материал, оборудование и дополнительное поле прикрепляй только к тем этапам, где они действительно используются: запрещено копировать одинаковый полный catalogDraftIds во все этапы."
                        . "\nСоздай непустые пути material, operation, equipment, customField и calculator; базовые material и operation; их дочерние materialVariant и operationVariant; оборудование и необходимые дополнительные поля. parentDraftId варианта должен ссылаться на базовый объект своего вида. В catalogDraftIds этапа ссылайся только на конечные объекты — materialVariant, operationVariant, equipment, customField и ровно один calculator; не ссылайся там на базовые material/operation или каталожные пути."
                        . "\nДля уровня detailed предлагай конкретные кандидаты каталога: назначение, технология, класс/тип, значимые характеристики, а где разумно — производитель, серия, марка или модель. Нельзя называть сущности просто «Материал», «Материал — баннерная сетка», «Операция для производства», «Оборудование» или «Калькулятор этапа». Для уровня professional дополнительно опиши закупочный формат, единицу хранения/поставки, размеры заготовки или рулона и будущий контекст учёта, но не придумывай цену. Для simple допустимы родовые классы; calculator всё равно отдельный для каждого этапа, а operationVariant — для каждого производственного этапа с requiresConfiguration=true."
                        . "\nКаждое описание catalogObject должно объяснять назначение объекта, ожидаемые входы и будущие сопоставления. Если точная марка или модель является предположением, явно вынеси это в assumptions; не маскируй догадку под подтверждённый факт."
                    : "\nНе добавляй поля вне схемы. Неизвестные числа возвращай как null, неизвестные строки — как пустую строку. "
                        . "В parameters помещай только подтверждённые технические особенности, для которых нет отдельного поля. "
                        . "catalog.weightG означает физическую массу в граммах; catalog.lengthMm, catalog.widthMm и catalog.heightMm — внешние габариты в миллиметрах.");
        }
        $response = $this->request('POST', '/chat/completions', ['model' => (string)$template['model'], 'messages' => [['role' => 'user', 'content' => $prompt]]]);
        $content = trim((string)($response['choices'][0]['message']['content'] ?? ''));
        if ($content === '') throw new \RuntimeException('AI Gateway вернул пустой текст');
        if ($zone === 'logic_structure_pilot') {
            $json = preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', $content);
            $decoded = json_decode((string)$json, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('AI Gateway вернул некорректный JSON структуры.');
            }
            $decoded['context'] = [
                'presetId' => $pilotPresetId,
                'versionKey' => $pilotVersionKey,
                'baseCompileHash' => $pilotBaseCompileHash,
                'requestToken' => $pilotRequestToken,
            ];
            $decoded['mode'] = $pilotModeCode;
            $decoded['level'] = $pilotLevelCode;
            $decoded['scheme'] = $pilotSchemeCode;
            $decoded = $this->sanitizePilotAcceptanceCopy($decoded);
            $qualityErrors = $this->validatePilotStructureQuality($decoded, $pilotLevelCode);
            if ($qualityErrors !== []) {
                $repairResponse = $this->request('POST', '/chat/completions', [
                    'model' => (string)$template['model'],
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                        ['role' => 'assistant', 'content' => $content],
                        ['role' => 'user', 'content' => "Ответ не проходит обязательную проверку производственной структуры:\n- "
                            . implode("\n- ", array_slice($qualityErrors, 0, 12))
                            . "\nВерни заново полный JSON по той же схеме. Исправь все перечисленные дефекты; не сокращай структуру и не добавляй пояснения вне JSON."],
                    ],
                ]);
                $repairContent = trim((string)($repairResponse['choices'][0]['message']['content'] ?? ''));
                $repairJson = preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', $repairContent);
                $repairDecoded = json_decode((string)$repairJson, true);
                if (!is_array($repairDecoded)) throw new \RuntimeException('AI Gateway не смог исправить JSON структуры.');
                $repairDecoded['context'] = $decoded['context'];
                $repairDecoded['mode'] = $pilotModeCode;
                $repairDecoded['level'] = $pilotLevelCode;
                $repairDecoded['scheme'] = $pilotSchemeCode;
                $decoded = $this->sanitizePilotAcceptanceCopy($repairDecoded);
                $qualityErrors = $this->validatePilotStructureQuality($decoded, $pilotLevelCode);
                if ($qualityErrors !== []) {
                    throw new \RuntimeException('AI-пилот не смог построить пригодную производственную структуру: ' . implode(' ', array_slice($qualityErrors, 0, 4)));
                }
            }
            $content = (string)json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return ['status' => 'ok', 'text' => $content, 'zone' => $zone, 'templateId' => $templateId];
    }

    public function generateLogicProposal(array $request): array
    {
        $this->assertAdmin();
        $this->assertNoForbiddenLogicKeys($request);
        $cleanRequest = $this->sanitizeLogicRequest($request);

        $template = null;
        foreach ($this->getTemplates() as $candidate) {
            if ((string)($candidate['zone'] ?? '') === 'logic_formula') {
                $template = $candidate;
                break;
            }
        }
        if ($template === null || trim((string)($template['model'] ?? '')) === '') {
            throw new \RuntimeException('Для AI-формул не настроен шаблон или модель');
        }

        $responseShape = [
            'schema' => self::LOGIC_PROPOSAL_SCHEMA,
            'baseFingerprint' => $cleanRequest['baseFingerprint'],
            'status' => 'proposal | needs-clarification | cannot-propose',
            'summary' => '',
            'assumptions' => [''],
            'questions' => [['key' => '', 'text' => '']],
            'operations' => [[
                'op' => 'updateVariableFormula',
                'targetCode' => $cleanRequest['variable']['code'],
                'expectedFingerprint' => $cleanRequest['baseFingerprint'],
                'formula' => '',
                'rationale' => '',
            ]],
        ];
        $systemPrompt = trim((string)$template['prompt'])
            . "\n\nReturn exactly one JSON object and no Markdown."
            . "\nOnly propose an updateVariableFormula operation for the requested variable code."
            . "\nNever emit sourcePath, Bitrix IDs, settings IDs, stage IDs, preset IDs, or any operation that creates, binds, deletes, renames, reorders, imports, exports, saves, or publishes data."
            . "\nUse only symbol codes listed in availableSymbols. If essential information is missing, return status=needs-clarification, one to three questions, and an empty operations array."
            . "\nFor status=proposal return exactly one operation and an empty questions array."
            . "\nThe baseFingerprint must be copied without changes."
            . "\nRequired response shape:\n"
            . json_encode($responseShape, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $startedAt = microtime(true);
        $response = $this->request('POST', '/chat/completions', [
            'model' => (string)$template['model'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => json_encode($cleanRequest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
        ]);
        $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);
        $content = trim((string)($response['choices'][0]['message']['content'] ?? ''));
        if ($content === '') throw new \RuntimeException('AI Gateway вернул пустой ответ');
        $proposal = $this->parseLogicProposal($content, $cleanRequest);
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return [
            'status' => 'ok',
            'proposal' => $proposal,
            'usage' => [
                'model' => (string)$template['model'],
                'inputTokens' => (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
                'outputTokens' => (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
                'latencyMs' => $latencyMs,
            ],
        ];
    }

    public function generateStageLogicProposal(array $request): array
    {
        $this->assertAdmin();
        [$cleanRequest, $template, $systemPrompt] = $this->prepareStageLogicPrompt($request);
        $startedAt = microtime(true);
        $response = $this->request('POST', '/chat/completions', [
            'model' => (string)$template['model'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => json_encode($cleanRequest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
        ]);
        $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);
        $content = trim((string)($response['choices'][0]['message']['content'] ?? ''));
        if ($content === '') throw new \RuntimeException('AI Gateway вернул пустой проект этапа');
        $proposal = $this->parseStageLogicProposal($content, $cleanRequest);
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return [
            'status' => 'ok',
            'proposal' => $proposal,
            'usage' => [
                'model' => (string)$template['model'],
                'inputTokens' => (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
                'outputTokens' => (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
                'latencyMs' => $latencyMs,
            ],
        ];
    }

    public function generateFormPilot(array $request): array
    {
        $this->assertAdmin();
        $contract = new AiFormPilotProposalService();
        $cleanRequest = $contract->sanitizeRequest($request);
        $template = null;
        foreach ($this->getTemplates() as $candidate) {
            if ((string)($candidate['zone'] ?? '') === 'logic_stage') {
                $template = $candidate;
                break;
            }
        }
        $model = trim((string)($template['model'] ?? '')) ?: self::DEFAULT_MODEL;
        $startedAt = microtime(true);
        $response = $this->request('POST', '/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $contract->buildSystemPrompt($cleanRequest)],
                ['role' => 'user', 'content' => json_encode([
                    'calculatorName' => $cleanRequest['calculatorName'],
                    'pilotLevel' => $cleanRequest['level'],
                    'wishes' => $cleanRequest['wishes'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
        ]);
        $content = trim((string)($response['choices'][0]['message']['content'] ?? ''));
        if ($content === '') throw new \RuntimeException('AI Gateway вернул пустой вариант формы');
        $proposal = $contract->parseProposal($content, $cleanRequest['level']);
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        return [
            'status' => 'ok',
            'proposal' => $proposal,
            'usage' => [
                'model' => $model,
                'inputTokens' => (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
                'outputTokens' => (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
                'latencyMs' => (int)round((microtime(true) - $startedAt) * 1000),
            ],
        ];
    }

    public function generateLogicAudit(array $request): array
    {
        $this->assertAdmin();
        $this->assertNoForbiddenLogicKeys($request);
        $this->assertAllowedLogicKeys($request, 'request', ['schema', 'baseFingerprint', 'intent', 'contextTitle', 'items']);
        if (($request['schema'] ?? null) !== self::LOGIC_AUDIT_REQUEST_SCHEMA) {
            throw new \InvalidArgumentException('Неподдерживаемая схема AI-анализа');
        }
        $fingerprint = trim((string)($request['baseFingerprint'] ?? ''));
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $fingerprint)) {
            throw new \InvalidArgumentException('Некорректный fingerprint AI-анализа');
        }
        $rawItems = is_array($request['items'] ?? null) ? $request['items'] : [];
        if ($rawItems === [] || count($rawItems) > 300) {
            throw new \InvalidArgumentException('AI-анализ должен содержать от 1 до 300 объектов');
        }
        $items = [];
        $targets = [];
        foreach ($rawItems as $index => $item) {
            if (!is_array($item)) throw new \InvalidArgumentException('items должен содержать объекты');
            $this->assertAllowedLogicKeys($item, 'items[' . $index . ']', ['id', 'kind', 'code', 'title', 'description', 'type', 'formula', 'codeMutable']);
            $id = $this->logicText($item['id'] ?? '', 'items[' . $index . '].id', 180);
            $kind = trim((string)($item['kind'] ?? ''));
            if (!in_array($kind, ['input', 'variable', 'result', 'global'], true) || isset($targets[$id])) {
                throw new \InvalidArgumentException('Некорректный или повторный объект AI-анализа');
            }
            $targets[$id] = ['kind' => $kind, 'codeMutable' => (bool)($item['codeMutable'] ?? false)];
            $items[] = [
                'id' => $id,
                'kind' => $kind,
                'code' => $this->logicOptionalText($item['code'] ?? '', 120),
                'title' => $this->logicOptionalText($item['title'] ?? '', 250),
                'description' => $this->logicOptionalText($item['description'] ?? '', 4000),
                'type' => $this->logicOptionalText($item['type'] ?? 'unknown', 20),
                'formula' => $this->logicOptionalText($item['formula'] ?? '', 12000),
                'codeMutable' => (bool)($item['codeMutable'] ?? false),
            ];
        }
        $cleanRequest = [
            'schema' => self::LOGIC_AUDIT_REQUEST_SCHEMA,
            'baseFingerprint' => $fingerprint,
            'intent' => $this->logicOptionalText($request['intent'] ?? '', 12000),
            'contextTitle' => $this->logicOptionalText($request['contextTitle'] ?? '', 500),
            'items' => $items,
        ];
        $template = null;
        foreach ($this->getTemplates() as $candidate) {
            if (($candidate['zone'] ?? '') === 'logic_audit') { $template = $candidate; break; }
        }
        if ($template === null) throw new \RuntimeException('Для AI-анализа не настроен шаблон');
        $shape = [
            'schema' => self::LOGIC_AUDIT_PROPOSAL_SCHEMA,
            'baseFingerprint' => $fingerprint,
            'summary' => '',
            'suggestions' => [[
                'id' => 'suggestion_001',
                'targetId' => 'exact item id',
                'targetKind' => 'input | variable | result | global',
                'patch' => ['code' => '', 'title' => '', 'description' => '', 'type' => '', 'formula' => ''],
                'rationale' => '',
            ]],
        ];
        $systemPrompt = trim((string)$template['prompt'])
            . "\n\nReturn exactly one JSON object and no Markdown. Analyze all items as one calculation context."
            . "\nSuggest only material improvements. Each suggestion changes one existing targetId; never add or delete objects."
            . "\nA code may be proposed only when codeMutable=true. Global-code proposals are applied later through a separate impact-checked atomic refactoring workflow."
            . "\nWhen renaming a code or improving a formula, keep all references internally consistent. Use English ASCII identifiers and Russian user-facing titles and descriptions."
            . "\nNever emit sourcePath, Bitrix IDs, database IDs, preset IDs, stage IDs, or settings IDs. Copy baseFingerprint exactly."
            . "\nAllowed patch keys: code, title, description, type, formula. Omit unchanged keys."
            . "\nRequired response shape:\n"
            . json_encode($shape, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $startedAt = microtime(true);
        $response = $this->request('POST', '/chat/completions', [
            'model' => (string)$template['model'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => json_encode($cleanRequest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
        ]);
        $content = trim((string)($response['choices'][0]['message']['content'] ?? ''));
        if ($content === '') throw new \RuntimeException('AI Gateway вернул пустой анализ');
        $proposal = $this->parseLogicAuditProposal($content, $fingerprint, $targets);
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        return [
            'status' => 'ok',
            'proposal' => $proposal,
            'usage' => [
                'model' => (string)$template['model'],
                'inputTokens' => (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
                'outputTokens' => (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
                'latencyMs' => (int)round((microtime(true) - $startedAt) * 1000),
            ],
        ];
    }

    public function previewStageLogicPrompt(array $request): array
    {
        $this->assertAdmin();
        [$cleanRequest, $template, $systemPrompt] = $this->prepareStageLogicPrompt($request);
        return [
            'status' => 'ok',
            'model' => (string)$template['model'],
            'templateId' => (string)$template['id'],
            'templateName' => (string)$template['name'],
            'systemPrompt' => $systemPrompt,
            'userJson' => json_encode($cleanRequest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];
    }

    private function prepareStageLogicPrompt(array $request): array
    {
        $this->assertNoForbiddenLogicKeys($request);
        $cleanRequest = $this->sanitizeStageLogicRequest($request);
        $template = null;
        foreach ($this->getTemplates() as $candidate) {
            if ((string)($candidate['zone'] ?? '') === 'logic_stage') {
                $template = $candidate;
                break;
            }
        }
        if ($template === null || trim((string)($template['model'] ?? '')) === '') {
            throw new \RuntimeException('Для AI-конструктора этапа не настроен шаблон или модель');
        }
        $responseShape = [
            'schema' => self::STAGE_LOGIC_PROPOSAL_SCHEMA,
            'baseFingerprint' => $cleanRequest['baseFingerprint'],
            'status' => 'proposal | needs-clarification | cannot-propose',
            'summary' => '',
            'assumptions' => [''],
            'questions' => [['key' => '', 'text' => '']],
            'draft' => [
                'inputs' => [[
                    'code' => '',
                    'title' => '',
                    'description' => '',
                    'type' => 'number | string | bool | array | any | unknown',
                    'sourceRef' => 'source_001',
                ]],
                'variables' => [[
                    'code' => '',
                    'title' => '',
                    'description' => '',
                    'type' => 'number | string | bool | array | any | unknown',
                    'formula' => '',
                ]],
                'results' => [],
                'additionalResults' => [],
                'globalAssignments' => [],
            ],
        ];
        $systemPrompt = trim((string)$template['prompt'])
            . "\n\nReturn exactly one JSON object and no Markdown."
            . "\nTreat request.intent as the authoritative administrator brief. Never ask the administrator to repeat information already present in intent, currentLogic, availableSources, globals, expectedResults, instructions, entities, or baseProducts."
            . "\nDo not ask the administrator to choose sourceRef values, formula codes, field paths, intermediate variables, or implementation details. Select and design those yourself from the supplied contract."
            . "\nBuild the complete calculation draft for exactly one stage. Inputs must bind only by sourceRef values from availableSources. Never emit sourcePath or any ID."
            . "\nAn availableSources.example is a current verified value or compact sample, not a permanent constant. Use it to understand shape, units, currencies, VAT and price ranges without fixing the formula to that one value."
            . "\nTreat baseProducts as supported product examples, not as one fixed current product. Their XML_ID samples and optional xmlIdContract describe storefront input values; never invent or hard-code a missing XML_ID contract."
            . "\nEntities with role=mapped-candidate are only current candidates. Runtime mappings may select another operation, operation variant, equipment, material, or material variant. Keep formulas compatible with that replacement."
            . "\nProduce the standard results explicitly listed in expectedResults. You may add useful additionalResults when they help downstream stages."
            . "\nFollow the optional instructions array. An empty array is valid."
            . "\nUse only these formula functions: if, round, ceil, floor, min, max, abs, trim, lower, upper, len, contains, replace, toNumber, toString, split, join, get, getPrice, regexMatch, regexExtract."
            . "\nFormula signature rule: round, ceil, floor, abs, trim, lower, upper, len, toNumber, and toString accept exactly one argument. In particular, round(value, digits) is invalid. For six decimal places use round(value * 1000000) / 1000000."
            . "\nVariables are evaluated in array order. A formula may reference inputs, globals, and only earlier variables."
            . "\nFor running material consumption, prefer a supplied global quantity explicitly described as including technological waste when one exists. Do not silently replace it with the base sheet quantity."
            . "\nWhen running consumption is requested in metres, create an explicit metres variable and additional result. If the internal length is in millimetres, use metres = millimetres / 1000. Do not expose only millimetres or a fraction of the roll as a substitute for running metres."
            . "\nTreat feed allowance as longitudinal by default: add it to running length, not to roll-width compatibility, unless the source description explicitly states otherwise. Evaluate both valid orientations and choose the one with the lower longitudinal material consumption."
            . "\nFor technological pre-lamination trim, use this deterministic default without asking: decide inside the stage from the base sheet quantity, the configured quantity threshold, the free-space threshold, and positive print-layout dimensions; when eligible, trim only the longitudinal feed dimension exactly to its corresponding print-layout dimension and keep the cross-feed dimension unchanged. Expose the decision as a diagnostic result. Do not require or ask about a separate pre-lamination-trim global flag unless the administrator explicitly says that flag is authoritative."
            . "\nResult key must be one of width, length, height, weight, purchasingPrice, basePrice, operationPurchasingPrice, operationBasePrice, materialPurchasingPrice, materialBasePrice."
            . "\nEvery results item must be {\"key\":\"...\",\"source\":\"declaredInputOrVariableCode\"}. Include only results that are actually bound; never emit an item with an empty or placeholder source."
            . "\nEvery additionalResults item must be {\"code\":\"...\",\"title\":\"...\",\"source\":\"declaredInputOrVariableCode\"}. Use [] when there are no additional results."
            . "\nWhen the administrator asks to update dynamic global values, emit globalAssignments. Every item must be {\"targetCode\":\"mutableGlobalVariableCodeFromGlobals\",\"source\":\"declaredInputOrVariableCode\"}. Never target a global constant. Use [] when no global update is requested."
            . "\nA mutable global that this stage is responsible for assigning is not automatically an available input value. Do not read that same mutable global before its assignment unless the supplied context proves an earlier stage already assigned it. For first ownership of current dimensions, thickness, or weight, use the corresponding initial or baseline global constants as inputs and assign the mutable globals only at the end."
            . "\nMandatory PROSPEKT sheet-state contract for a roll-lamination stage when these codes are present: read print_sheet_width_initial_mm, print_sheet_length_initial_mm, print_sheet_thickness_initial_mm, and print_sheet_weight_initial_g as the incoming sheet state; write print_sheet_width_mm, print_sheet_length_mm, print_sheet_thickness_mm, and print_sheet_weight_g only through globalAssignments at the end. A proposal that reads any of those four mutable target codes in an earlier formula is invalid. Never assume they were initialized by a previous stage."
            . "\nPrefer explicit intermediate variables and meaningful English ASCII codes. Preserve Russian titles and descriptions."
            . "\nDo not ask about rare edge cases when a conservative deterministic fallback can be expressed and disclosed. Prefer a proposal with an explicit compatibility boolean additional result and zero operation or material consumption when the selected resource cannot physically process the item; list this fallback in assumptions."
            . "\nIf essential production rules are missing, return needs-clarification with one to three precise questions and draft=null. Do not guess norms, spoilage, make-ready, pricing, dimensions, or unit conversions."
            . "\nIf request.intent states that this is the only clarification round, do not return needs-clarification again. Build a proposal with explicit assumptions, or return cannot-propose only when the available contract makes the calculation technically impossible."
            . "\nFor status=proposal return questions=[] and a complete draft. Copy baseFingerprint exactly."
            . "\nRequired response shape:\n"
            . json_encode($responseShape, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return [$cleanRequest, $template, $systemPrompt];
    }

    public function getModels(bool $forceRefresh = false): array
    {
        $this->assertAdmin();
        $cached = json_decode((string)Option::get(self::MODULE_ID, self::MODELS_CACHE_OPTION, ''), true);
        if (!$forceRefresh && is_array($cached) && (int)($cached['expiresAt'] ?? 0) > time() && is_array($cached['models'] ?? null)) return $cached['models'];
        $response = $this->request('GET', '/models');
        $models = [];
        foreach ((array)($response['data'] ?? []) as $model) {
            $id = trim((string)($model['id'] ?? ''));
            if ($id !== '') $models[] = ['id' => $id, 'name' => trim((string)($model['name'] ?? $model['display_name'] ?? $id))];
        }
        usort($models, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        Option::set(self::MODULE_ID, self::MODELS_CACHE_OPTION, json_encode(['expiresAt' => time() + self::MODELS_CACHE_TTL, 'models' => $models], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $models;
    }

    private function getTemplates(): array
    {
        $decoded = json_decode((string)Option::get(self::MODULE_ID, self::TEMPLATES_OPTION, ''), true);
        $templates = is_array($decoded) && $decoded !== [] ? $this->sanitizeTemplates($decoded) : [];
        $cardNames = [
            'calculator_description' => 'Заполнение карточки калькулятора',
            'operation_description' => 'Заполнение карточки операции',
            'operation_variant_description' => 'Заполнение карточки варианта операции',
            'material_description' => 'Заполнение карточки материала',
            'material_variant_description' => 'Заполнение карточки варианта материала',
        ];
        foreach ($templates as &$template) {
            if (isset($cardNames[$template['zone']]) && strpos($template['name'], 'Описание ') === 0) {
                $template['name'] = $cardNames[$template['zone']];
            }
            if ($template['zone'] === 'equipment_description' && $template['name'] === 'Описание оборудования') {
                $template['name'] = 'Заполнение карточки оборудования';
            }
            if (
                $template['zone'] === 'equipment_description'
                && $template['prompt'] === 'Опиши назначение полиграфического оборудования: {название оборудования}. Верни только готовый текст.'
            ) {
                $template['prompt'] = 'Заполни техническую карточку полиграфического оборудования «{название оборудования}». В первую очередь используй сведения из блока «Источники данных»: {Источники данных}. Если содержимое источника недоступно или параметр не подтверждён, оставь соответствующее значение пустым и ничего не выдумывай. Подготовь краткий анонс, подробное HTML-описание, известные размеры, технические поля, допуски, цены и габариты. Особенности, для которых нет отдельного поля, добавь в массив «Другие параметры». Верни только JSON по обязательной схеме без Markdown и пояснений.';
            }
            if ($template['zone'] === 'logic_structure_pilot' && mb_stripos($template['prompt'], 'Виртуальным материалам') !== false) {
                $template['prompt'] = str_replace(
                    'Виртуальным материалам, операциям, вариантам и оборудованию',
                    'Материалам, операциям, вариантам и оборудованию',
                    $template['prompt']
                ) . ' Не используй слово «виртуальный» и его формы в названиях, описаниях и итоговом резюме.';
            }
        }
        unset($template);
        $present = array_fill_keys(array_column($templates, 'zone'), true);
        foreach ($this->getTemplatesFallback() as $fallback) if (!isset($present[$fallback['zone']])) $templates[] = $fallback;
        return $templates;
    }

    private function sanitizePilotAcceptanceCopy(array $draft): array
    {
        $walk = function ($value, ?string $key = null) use (&$walk) {
            if (is_array($value)) {
                foreach ($value as $nestedKey => $nestedValue) {
                    $value[$nestedKey] = $walk($nestedValue, is_string($nestedKey) ? $nestedKey : $key);
                }
                return $value;
            }
            if (!is_string($value) || !in_array($key, ['summary', 'title', 'description'], true)) return $value;

            $hadLeadingVirtual = preg_match('/^\s*виртуальн(?:ый|ая|ое|ые|ого|ой|ых|ому|ым|ыми|ую)\b/ui', $value) === 1;
            $value = preg_replace('/\bвиртуальн(?:ый|ая|ое|ые|ого|ой|ых|ому|ым|ыми|ую)\b/ui', '', $value) ?? $value;
            $value = preg_replace('/[ \t]{2,}/u', ' ', $value) ?? $value;
            $value = preg_replace('/\s+([,.;:])/u', '$1', $value) ?? $value;
            $value = trim($value);
            if ($hadLeadingVirtual && $value !== '') {
                $value = mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
            }
            return $value;
        };

        return $walk($draft);
    }

    /** @return string[] */
    private function validatePilotStructureQuality(array $draft, string $level): array
    {
        $errors = [];
        $objects = is_array($draft['catalogObjects'] ?? null) ? $draft['catalogObjects'] : [];
        $stages = is_array($draft['stages'] ?? null) ? $draft['stages'] : [];
        $byId = [];
        $kindCounts = [];
        foreach ($objects as $object) {
            if (!is_array($object)) continue;
            $id = trim((string)($object['draftId'] ?? ''));
            $kind = trim((string)($object['kind'] ?? ''));
            if ($id !== '') $byId[$id] = $object;
            $kindCounts[$kind] = ($kindCounts[$kind] ?? 0) + 1;
            if (in_array($level, ['detailed', 'professional'], true)) {
                $title = trim((string)($object['title'] ?? ''));
                $description = trim((string)($object['description'] ?? ''));
                if ($description === '') $errors[] = 'Для объекта «' . ($title !== '' ? $title : $id) . '» отсутствует описание.';
                if (preg_match('/^(?:материал|вид материала|операция|вид операции|оборудование|дополнительное поле|калькулятор)(?:\s*[-—:])?(?:\s+(?:для|этапа|широкоформатн|производственн).*)?$/ui', $title)) {
                    $errors[] = 'Объект «' . $title . '» имеет обобщённое название.';
                }
            }
        }
        $calculatorUsage = [];
        $operationUsage = [];
        $configuredStageCount = 0;
        $minimumStageCount = $level === 'professional' ? 6 : ($level === 'detailed' ? 4 : 1);
        if (count($stages) < $minimumStageCount) $errors[] = 'Для уровня ' . $level . ' требуется не менее ' . $minimumStageCount . ' технологически раздельных этапов.';
        if (in_array($level, ['detailed', 'professional'], true)) {
            $conditionCount = count(array_filter(is_array($draft['groups'] ?? null) ? $draft['groups'] : [], static fn($group): bool => is_array($group) && ($group['kind'] ?? '') === 'condition'));
            if ($conditionCount < 1) $errors[] = 'Детализированная структура должна содержать хотя бы одно условие с альтернативными ветвями.';
        }
        foreach ($stages as $stage) {
            if (!is_array($stage)) continue;
            $stageTitle = trim((string)($stage['title'] ?? '')) ?: 'Без названия';
            if (in_array($level, ['detailed', 'professional'], true) && preg_match('/[,\/]|\sили\s/ui', $stageTitle)) {
                $errors[] = 'Этап «' . $stageTitle . '» объединяет альтернативные или самостоятельные операции; разделите их на карточки и ветви.';
            }
            $refs = is_array($stage['catalogDraftIds'] ?? null) ? $stage['catalogDraftIds'] : [];
            $requiresConfiguration = ($stage['requiresConfiguration'] ?? false) === true;
            if ($requiresConfiguration) $configuredStageCount++;
            $calculators = [];
            $operations = [];
            $scalarCounts = ['materialVariant' => 0, 'operationVariant' => 0, 'equipment' => 0, 'calculator' => 0];
            foreach ($refs as $ref) {
                $object = $byId[(string)$ref] ?? null;
                $kind = is_array($object) ? (string)($object['kind'] ?? '') : '';
                if ($kind === 'calculator') $calculators[] = (string)$ref;
                if ($kind === 'operationVariant') $operations[] = (string)$ref;
                if (array_key_exists($kind, $scalarCounts)) $scalarCounts[$kind]++;
                if ($kind === 'material' || $kind === 'operation') $errors[] = 'Этап «' . $stageTitle . '» ссылается на базовый объект вместо его вида.';
            }
            foreach ($scalarCounts as $kind => $count) if ($count > 1) $errors[] = 'Этап «' . $stageTitle . '» содержит несколько одиночных связей типа ' . $kind . '.';
            if (count($calculators) !== 1) $errors[] = 'Этап «' . $stageTitle . '» должен иметь ровно один собственный калькулятор.';
            if ($requiresConfiguration && $operations === []) $errors[] = 'Производственный этап «' . $stageTitle . '» должен иметь собственный вид операции.';
            foreach ($calculators as $id) $calculatorUsage[$id][] = $stageTitle;
            if ($requiresConfiguration) foreach ($operations as $id) $operationUsage[$id][] = $stageTitle;
        }
        foreach ($calculatorUsage as $id => $usedBy) if (count($usedBy) > 1) $errors[] = 'Один калькулятор нельзя использовать в нескольких этапах: ' . implode(', ', $usedBy) . '.';
        foreach ($operationUsage as $id => $usedBy) if (count($usedBy) > 1) $errors[] = 'Один вид операции нельзя использовать как универсальный для нескольких этапов: ' . implode(', ', $usedBy) . '.';
        if (in_array($level, ['detailed', 'professional'], true)) {
            if (($kindCounts['materialVariant'] ?? 0) < 1) $errors[] = 'Нет конкретных видов материалов.';
            if (($kindCounts['equipment'] ?? 0) < 1) $errors[] = 'Нет конкретного оборудования.';
            if (($kindCounts['calculator'] ?? 0) < count($stages)) $errors[] = 'Калькуляторов меньше, чем этапов.';
            if (($kindCounts['operationVariant'] ?? 0) < $configuredStageCount) $errors[] = 'Видов операций меньше, чем производственных этапов.';
        }
        return array_values(array_unique($errors));
    }

    private function sanitizeTemplates(array $templates): array
    {
        $result = [];
        foreach ($templates as $index => $template) {
            if (!is_array($template)) continue;
            $prompt = trim((string)($template['prompt'] ?? ''));
            $zone = trim((string)($template['zone'] ?? 'stage_description'));
            if ($prompt === '' || !isset(self::ZONE_CONTEXT[$zone])) continue;
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($template['id'] ?? ''));
            $result[] = [
                'id' => $id !== '' ? $id : 'prompt-' . ($index + 1) . '-' . substr(sha1($prompt), 0, 8),
                'zone' => $zone,
                'name' => mb_substr(trim((string)($template['name'] ?? '')) ?: ('Шаблон ' . ($index + 1)), 0, 200),
                'prompt' => mb_substr($prompt, 0, 12000),
                'model' => mb_substr(trim((string)($template['model'] ?? '')) ?: self::DEFAULT_MODEL, 0, 200),
            ];
        }
        return $result;
    }

    private function getTemplatesFallback(): array
    {
        $cardPrompt = static fn(string $kind, string $nameTag): string =>
            'Заполни полную техническую карточку сущности «' . $kind . '» с названием ' . $nameTag
            . '. В первую очередь используй подтверждённые сведения из блока «Источники данных»: {Источники данных}. '
            . 'Не выдумывай характеристики. Подготовь краткий анонс, подробное HTML-описание, подтверждённые дополнительные параметры и параметры торгового каталога. '
            . 'Вес возвращай в граммах, внешние габариты — в миллиметрах. Верни только JSON по обязательной схеме без Markdown и пояснений.';
        $definitions = [
            'logic_formula' => [
                'AI-помощник формулы',
                'Ты помогаешь редактору полиграфических калькуляторов составить одну формулу для уже существующей переменной. Соблюдай доступный контракт и не придумывай источники данных, внутренние идентификаторы или связи.',
            ],
            'logic_stage' => [
                'AI-конструктор логики этапа',
                'Ты проектируешь полную расчётную логику одного этапа полиграфического изделия. Учитывай назначение этапа, выбранные калькулятор, операцию, оборудование и материал, доступные источники и глобальные значения. Построй связанные входы, упорядоченные переменные с формулами и результаты этапа. Не придумывай отсутствующие источники и не используй внутренние ID или пути.',
            ],
            'logic_audit' => [
                'AI-анализ логики и обозначений',
                'Ты технический редактор полиграфических калькуляторов. По исходным данным и полному контексту предложи понятные названия, однозначные ASCII-коды, точные описания и типы. Проверь формулы, единицы измерения и согласованность результатов. Не меняй бизнес-правила без достаточных данных.',
            ],
            'logic_structure_pilot' => [
                'AI-пилот структуры расчёта',
                'Ты архитектор производственных калькуляторов полиграфии. Создай только проверяемый структурный черновик без формул, цен, внутренних ID и записей в Bitrix. Форма: {контекст формы}. Текущая схема: {текущая схема}. Режим: {режим пилота}. Уровень: {уровень проработки}. Тип схемы: {тип схемы}. Пожелания: {пожелания пользователя}. Для глобальных значений указывай только тип, код, название и описание. Материалам, операциям, вариантам и оборудованию дай описания назначения, ожидаемых входов и будущих сопоставлений. Не используй слово «виртуальный» и его формы в названиях, описаниях и итоговом резюме. Верни только JSON по обязательной схеме.',
            ],
            'preset_description' => ['Описание пресета', 'Напиши краткий анонс пресета. Пресет: {название пресета}. Товар: {название товара}. Анонс товара: {анонс товара}. Верни только готовый текст.'],
            'detail_description' => ['Описание детали', 'Напиши краткий технический анонс детали. Деталь: {название детали}. Пресет: {название пресета}. Анонс пресета: {анонс пресета}. Товар: {название товара}. Анонс товара: {анонс товара}. Верни только готовый текст.'],
            'stage_description' => ['Описание этапа', 'Напиши краткий технический анонс этапа. Этап: {название этапа}. Деталь: {название детали}. Анонс детали: {анонс детали}. Пресет: {название пресета}. Анонс пресета: {анонс пресета}. Товар: {название товара}. Анонс товара: {анонс товара}. Верни только готовый текст.'],
            'calculator_description' => ['Заполнение карточки калькулятора', $cardPrompt('калькулятор', '{название калькулятора}')],
            'operation_description' => ['Заполнение карточки операции', $cardPrompt('операция', '{название операции}')],
            'operation_variant_description' => ['Заполнение карточки варианта операции', $cardPrompt('вариант операции', '{название варианта операции}') . ' Родительская операция: {название операции}. Анонс: {анонс операции}.'],
            'equipment_description' => ['Заполнение карточки оборудования', 'Заполни техническую карточку полиграфического оборудования «{название оборудования}». В первую очередь используй сведения из блока «Источники данных»: {Источники данных}. Если содержимое источника недоступно или параметр не подтверждён, оставь соответствующее значение пустым и ничего не выдумывай. Подготовь краткий анонс, подробное HTML-описание, известные размеры, технические поля, допуски, цены и габариты. Особенности, для которых нет отдельного поля, добавь в массив «Другие параметры». Верни только JSON по обязательной схеме без Markdown и пояснений.'],
            'material_description' => ['Заполнение карточки материала', $cardPrompt('материал', '{название материала}')],
            'material_variant_description' => ['Заполнение карточки варианта материала', $cardPrompt('вариант материала', '{название варианта материала}') . ' Родительский материал: {название материала}. Анонс: {анонс материала}.'],
        ];
        $result = [];
        foreach ($definitions as $zone => [$name, $prompt]) $result[] = ['id' => str_replace('_', '-', $zone) . '-default', 'zone' => $zone, 'name' => $name, 'prompt' => $prompt, 'model' => self::DEFAULT_MODEL];
        return $result;
    }

    private function sanitizeLogicRequest(array $request): array
    {
        $this->assertAllowedLogicKeys($request, 'request', ['schema', 'baseFingerprint', 'intent', 'variable', 'availableSymbols']);
        if (($request['schema'] ?? null) !== self::LOGIC_REQUEST_SCHEMA) {
            throw new \InvalidArgumentException('Неподдерживаемая схема AI-запроса');
        }
        $fingerprint = trim((string)($request['baseFingerprint'] ?? ''));
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $fingerprint)) {
            throw new \InvalidArgumentException('Некорректный fingerprint формулы');
        }
        $intent = $this->logicText($request['intent'] ?? '', 'intent', 6000);
        $variable = is_array($request['variable'] ?? null) ? $request['variable'] : [];
        $this->assertAllowedLogicKeys($variable, 'variable', ['code', 'title', 'description', 'formula']);
        $code = $this->logicCode($variable['code'] ?? '', 'variable.code');
        $formula = trim((string)($variable['formula'] ?? ''));
        if (mb_strlen($formula) > 4000) throw new \InvalidArgumentException('variable.formula превышает 4000 символов');

        $symbols = [];
        $rawSymbols = is_array($request['availableSymbols'] ?? null) ? $request['availableSymbols'] : [];
        if (count($rawSymbols) > 200) throw new \InvalidArgumentException('Слишком много доступных символов');
        foreach ($rawSymbols as $index => $rawSymbol) {
            if (!is_array($rawSymbol)) throw new \InvalidArgumentException('availableSymbols должен содержать объекты');
            $this->assertAllowedLogicKeys($rawSymbol, 'availableSymbols[' . $index . ']', ['code', 'title', 'description', 'type', 'kind']);
            $type = trim((string)($rawSymbol['type'] ?? 'unknown'));
            $kind = trim((string)($rawSymbol['kind'] ?? ''));
            if (!in_array($type, self::LOGIC_SYMBOL_TYPES, true)) $type = 'unknown';
            if (!in_array($kind, self::LOGIC_SYMBOL_KINDS, true)) {
                throw new \InvalidArgumentException('Неизвестный kind доступного символа');
            }
            $symbols[] = [
                'code' => $this->logicCode($rawSymbol['code'] ?? '', 'availableSymbols[' . $index . '].code'),
                'title' => $this->logicOptionalText($rawSymbol['title'] ?? '', 200),
                'description' => $this->logicOptionalText($rawSymbol['description'] ?? '', 500),
                'type' => $type,
                'kind' => $kind,
            ];
        }

        return [
            'schema' => self::LOGIC_REQUEST_SCHEMA,
            'baseFingerprint' => $fingerprint,
            'intent' => $intent,
            'variable' => [
                'code' => $code,
                'title' => $this->logicOptionalText($variable['title'] ?? '', 200),
                'description' => $this->logicOptionalText($variable['description'] ?? '', 1000),
                'formula' => $formula,
            ],
            'availableSymbols' => $symbols,
        ];
    }

    private function parseLogicProposal(string $content, array $request): array
    {
        $json = trim($content);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/si', $json, $matches)) $json = trim($matches[1]);
        $proposal = json_decode($json, true);
        if (!is_array($proposal) || array_values($proposal) === $proposal) {
            throw new \RuntimeException('AI вернул невалидный JSON proposal');
        }
        $this->assertNoForbiddenLogicKeys($proposal);
        $this->assertAllowedLogicKeys($proposal, 'proposal', ['schema', 'baseFingerprint', 'status', 'summary', 'assumptions', 'questions', 'operations']);
        if (($proposal['schema'] ?? null) !== self::LOGIC_PROPOSAL_SCHEMA) {
            throw new \RuntimeException('AI вернул неподдерживаемую схему proposal');
        }
        if (($proposal['baseFingerprint'] ?? null) !== $request['baseFingerprint']) {
            throw new \RuntimeException('AI proposal относится к другой версии формулы');
        }
        $status = trim((string)($proposal['status'] ?? ''));
        if (!in_array($status, ['proposal', 'needs-clarification', 'cannot-propose'], true)) {
            throw new \RuntimeException('AI вернул неизвестный статус proposal');
        }

        $assumptions = [];
        $rawAssumptions = is_array($proposal['assumptions'] ?? null) ? $proposal['assumptions'] : [];
        if (count($rawAssumptions) > 10) throw new \RuntimeException('AI вернул слишком много допущений');
        foreach ($rawAssumptions as $value) $assumptions[] = $this->logicText($value, 'assumption', 500);

        $questions = [];
        $rawQuestions = is_array($proposal['questions'] ?? null) ? $proposal['questions'] : [];
        if (count($rawQuestions) > 3) throw new \RuntimeException('AI вернул больше трёх уточняющих вопросов');
        foreach ($rawQuestions as $index => $question) {
            if (!is_array($question)) throw new \RuntimeException('AI вернул некорректный вопрос');
            $this->assertAllowedLogicKeys($question, 'questions[' . $index . ']', ['key', 'text']);
            $questions[] = [
                'key' => $this->logicCode($question['key'] ?? '', 'questions[' . $index . '].key'),
                'text' => $this->logicText($question['text'] ?? '', 'questions[' . $index . '].text', 500),
            ];
        }

        $operations = [];
        $rawOperations = is_array($proposal['operations'] ?? null) ? $proposal['operations'] : [];
        if (count($rawOperations) > 1) throw new \RuntimeException('AI-пилот допускает только одно изменение формулы');
        foreach ($rawOperations as $operation) {
            if (!is_array($operation) || ($operation['op'] ?? null) !== 'updateVariableFormula') {
                throw new \RuntimeException('AI предложил запрещённую операцию');
            }
            $this->assertAllowedLogicKeys($operation, 'operation', ['op', 'targetCode', 'expectedFingerprint', 'formula', 'rationale']);
            if (($operation['targetCode'] ?? null) !== $request['variable']['code']) {
                throw new \RuntimeException('AI попытался изменить другую переменную');
            }
            if (($operation['expectedFingerprint'] ?? null) !== $request['baseFingerprint']) {
                throw new \RuntimeException('AI operation относится к устаревшей формуле');
            }
            $operations[] = [
                'op' => 'updateVariableFormula',
                'targetCode' => $request['variable']['code'],
                'expectedFingerprint' => $request['baseFingerprint'],
                'formula' => $this->logicText($operation['formula'] ?? '', 'operation.formula', 4000),
                'rationale' => $this->logicText($operation['rationale'] ?? '', 'operation.rationale', 1000),
            ];
        }

        if ($status === 'proposal' && (count($operations) !== 1 || count($questions) !== 0)) {
            throw new \RuntimeException('AI proposal должен содержать одну операцию и не содержать вопросов');
        }
        if ($status !== 'proposal' && count($operations) !== 0) {
            throw new \RuntimeException('AI не должен менять формулу до уточнения');
        }
        if ($status === 'needs-clarification' && count($questions) === 0) {
            throw new \RuntimeException('AI не вернул уточняющий вопрос');
        }

        return [
            'schema' => self::LOGIC_PROPOSAL_SCHEMA,
            'baseFingerprint' => $request['baseFingerprint'],
            'status' => $status,
            'summary' => $this->logicText($proposal['summary'] ?? '', 'summary', 1000),
            'assumptions' => $assumptions,
            'questions' => $questions,
            'operations' => $operations,
        ];
    }

    private function sanitizeStageLogicRequest(array $request): array
    {
        // compatibleModules existed briefly in a newer client build. It has no
        // meaning in the v1 stage prompt and is intentionally ignored so a
        // cached browser bundle cannot break prompt preview after a rollback.
        $this->assertAllowedLogicKeys($request, 'request', ['schema', 'baseFingerprint', 'intent', 'stage', 'baseProducts', 'expectedResults', 'instructions', 'currentLogic', 'availableSources', 'globals', 'compatibleModules']);
        if (($request['schema'] ?? null) !== self::STAGE_LOGIC_REQUEST_SCHEMA) {
            throw new \InvalidArgumentException('Неподдерживаемая схема AI-запроса этапа');
        }
        $fingerprint = trim((string)($request['baseFingerprint'] ?? ''));
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $fingerprint)) throw new \InvalidArgumentException('Некорректный fingerprint логики этапа');

        $stage = is_array($request['stage'] ?? null) ? $request['stage'] : [];
        $this->assertAllowedLogicKeys($stage, 'stage', ['name', 'calculatorName', 'entities', 'entitySelectionContract']);
        $entities = [];
        foreach (array_slice(is_array($stage['entities'] ?? null) ? $stage['entities'] : [], 0, 12) as $index => $entity) {
            if (!is_array($entity)) throw new \InvalidArgumentException('stage.entities должен содержать объекты');
            $this->assertAllowedLogicKeys($entity, 'stage.entities[' . $index . ']', ['kind', 'name', 'description', 'role', 'selectionNote']);
            $role = (string)($entity['role'] ?? '');
            if (!in_array($role, ['fixed', 'mapped-candidate'], true)) {
                throw new \InvalidArgumentException('Некорректная роль сущности этапа');
            }
            $entities[] = [
                'kind' => $this->logicOptionalText($entity['kind'] ?? '', 40),
                'name' => $this->logicOptionalText($entity['name'] ?? '', 300),
                'description' => $this->logicOptionalText($entity['description'] ?? '', 1000),
                'role' => $role,
                'selectionNote' => $this->logicOptionalText($entity['selectionNote'] ?? '', 500),
            ];
        }

        $baseProducts = [];
        foreach (array_slice(is_array($request['baseProducts'] ?? null) ? $request['baseProducts'] : [], 0, 30) as $productIndex => $product) {
            if (!is_array($product)) throw new \InvalidArgumentException('baseProducts должен содержать объекты');
            $this->assertAllowedLogicKeys($product, 'baseProducts[' . $productIndex . ']', ['name', 'productProperties', 'offerProperties']);
            $sanitizeProperties = function ($rows, string $label) {
                $result = [];
                foreach (array_slice(is_array($rows) ? $rows : [], 0, 100) as $index => $property) {
                    if (!is_array($property)) throw new \InvalidArgumentException($label . ' должен содержать объекты');
                    $this->assertAllowedLogicKeys($property, $label . '[' . $index . ']', ['code', 'title', 'valueType', 'values', 'xmlIdContract', 'description']);
                    $values = [];
                    foreach (array_slice(is_array($property['values'] ?? null) ? $property['values'] : [], 0, 100) as $valueIndex => $value) {
                        if (!is_array($value)) throw new \InvalidArgumentException($label . '.values должен содержать объекты');
                        $this->assertAllowedLogicKeys($value, $label . '[' . $index . '].values[' . $valueIndex . ']', ['value', 'xmlId']);
                        $values[] = [
                            'value' => $this->logicOptionalText($value['value'] ?? '', 300),
                            'xmlId' => $this->logicOptionalText($value['xmlId'] ?? '', 300),
                        ];
                    }
                    $result[] = [
                        'code' => $this->logicOptionalText($property['code'] ?? '', 120),
                        'title' => $this->logicOptionalText($property['title'] ?? '', 200),
                        'valueType' => $this->logicOptionalText($property['valueType'] ?? 'unknown', 20),
                        'values' => $values,
                        'xmlIdContract' => $this->logicOptionalText($property['xmlIdContract'] ?? '', 500),
                        'description' => $this->logicOptionalText($property['description'] ?? '', 500),
                    ];
                }
                return $result;
            };
            $baseProducts[] = [
                'name' => $this->logicOptionalText($product['name'] ?? '', 300),
                'productProperties' => $sanitizeProperties($product['productProperties'] ?? [], 'baseProducts.productProperties'),
                'offerProperties' => $sanitizeProperties($product['offerProperties'] ?? [], 'baseProducts.offerProperties'),
            ];
        }

        $expectedResults = [];
        foreach (array_slice(is_array($request['expectedResults'] ?? null) ? $request['expectedResults'] : [], 0, 30) as $index => $result) {
            if (!is_array($result)) throw new \InvalidArgumentException('expectedResults должен содержать объекты');
            $this->assertAllowedLogicKeys($result, 'expectedResults[' . $index . ']', ['code', 'title', 'purpose', 'format']);
            $expectedResults[] = [
                'code' => $this->logicCode($result['code'] ?? '', 'expectedResults[' . $index . '].code'),
                'title' => $this->logicOptionalText($result['title'] ?? '', 200),
                'purpose' => $this->logicOptionalText($result['purpose'] ?? '', 500),
                'format' => $this->logicOptionalText($result['format'] ?? '', 200),
            ];
        }
        $instructions = [];
        foreach (array_slice(is_array($request['instructions'] ?? null) ? $request['instructions'] : [], 0, 30) as $instruction) {
            $normalized = $this->logicOptionalText($instruction, 1000);
            if ($normalized !== '') $instructions[] = $normalized;
        }

        $sources = [];
        $sourceRefs = [];
        $rawSources = is_array($request['availableSources'] ?? null) ? $request['availableSources'] : [];
        if (count($rawSources) > 180) throw new \InvalidArgumentException('Слишком много доступных источников этапа');
        foreach ($rawSources as $index => $source) {
            if (!is_array($source)) throw new \InvalidArgumentException('availableSources должен содержать объекты');
            $this->assertAllowedLogicKeys($source, 'availableSources[' . $index . ']', ['ref', 'suggestedCode', 'title', 'description', 'example', 'type', 'group']);
            $ref = trim((string)($source['ref'] ?? ''));
            if (!preg_match('/^source_[0-9]{3}$/', $ref) || isset($sourceRefs[$ref])) throw new \InvalidArgumentException('Некорректный или повторный sourceRef');
            $sourceRefs[$ref] = true;
            $type = trim((string)($source['type'] ?? 'unknown'));
            if (!in_array($type, self::LOGIC_SYMBOL_TYPES, true)) $type = 'unknown';
            $sources[] = [
                'ref' => $ref,
                'suggestedCode' => $this->logicCode($source['suggestedCode'] ?? '', 'availableSources[' . $index . '].suggestedCode'),
                'title' => $this->logicOptionalText($source['title'] ?? '', 200),
                'description' => $this->logicOptionalText($source['description'] ?? '', 1500),
                'example' => $this->logicOptionalText($source['example'] ?? '', 1500),
                'type' => $type,
                'group' => $this->logicOptionalText($source['group'] ?? '', 120),
            ];
        }

        $sanitizeSymbols = function ($rows, string $label, bool $withFormula = false) {
            $result = [];
            if (!is_array($rows)) return $result;
            foreach (array_slice($rows, 0, 150) as $index => $row) {
                if (!is_array($row)) throw new \InvalidArgumentException($label . ' должен содержать объекты');
                $allowed = ['code', 'title', 'description', 'type'];
                if ($withFormula) $allowed[] = 'formula';
                else $allowed[] = 'sourceRef';
                $this->assertAllowedLogicKeys($row, $label . '[' . $index . ']', $allowed);
                $type = trim((string)($row['type'] ?? 'unknown'));
                if (!in_array($type, self::LOGIC_SYMBOL_TYPES, true)) $type = 'unknown';
                $item = [
                    'code' => $this->logicCode($row['code'] ?? '', $label . '[' . $index . '].code'),
                    'title' => $this->logicOptionalText($row['title'] ?? '', 200),
                    'description' => $this->logicOptionalText($row['description'] ?? '', 500),
                    'type' => $type,
                ];
                if ($withFormula) $item['formula'] = $this->logicOptionalText($row['formula'] ?? '', 6000);
                elseif (trim((string)($row['sourceRef'] ?? '')) !== '') $item['sourceRef'] = trim((string)$row['sourceRef']);
                $result[] = $item;
            }
            return $result;
        };

        $current = is_array($request['currentLogic'] ?? null) ? $request['currentLogic'] : [];
        $this->assertAllowedLogicKeys($current, 'currentLogic', ['inputs', 'variables', 'results']);
        $results = [];
        foreach (is_array($current['results'] ?? null) ? $current['results'] : [] as $key => $value) {
            if (!is_string($key) || mb_strlen($key) > 60) continue;
            $results[$key] = $this->logicOptionalText($value, 120);
        }

        $globals = [];
        foreach (array_slice(is_array($request['globals'] ?? null) ? $request['globals'] : [], 0, 100) as $index => $global) {
            if (!is_array($global)) throw new \InvalidArgumentException('globals должен содержать объекты');
            $this->assertAllowedLogicKeys($global, 'globals[' . $index . ']', ['code', 'title', 'description', 'type', 'kind']);
            $kind = trim((string)($global['kind'] ?? ''));
            if (!in_array($kind, ['variable', 'constant'], true)) throw new \InvalidArgumentException('Некорректный kind глобального значения');
            $type = trim((string)($global['type'] ?? 'any'));
            if (!in_array($type, self::LOGIC_SYMBOL_TYPES, true)) $type = 'any';
            $globals[] = [
                'code' => $this->logicCode($global['code'] ?? '', 'globals[' . $index . '].code'),
                'title' => $this->logicOptionalText($global['title'] ?? '', 200),
                'description' => $this->logicOptionalText($global['description'] ?? '', 500),
                'type' => $type,
                'kind' => $kind,
            ];
        }

        return [
            'schema' => self::STAGE_LOGIC_REQUEST_SCHEMA,
            'baseFingerprint' => $fingerprint,
            'intent' => $this->logicText($request['intent'] ?? '', 'intent', 12000),
            'stage' => [
                'name' => $this->logicOptionalText($stage['name'] ?? '', 300),
                'calculatorName' => $this->logicOptionalText($stage['calculatorName'] ?? '', 300),
                'entities' => $entities,
                'entitySelectionContract' => $this->logicOptionalText($stage['entitySelectionContract'] ?? '', 1000),
            ],
            'baseProducts' => $baseProducts,
            'expectedResults' => $expectedResults,
            'instructions' => $instructions,
            'currentLogic' => [
                'inputs' => $sanitizeSymbols($current['inputs'] ?? [], 'currentLogic.inputs'),
                'variables' => $sanitizeSymbols($current['variables'] ?? [], 'currentLogic.variables', true),
                'results' => $results,
            ],
            'availableSources' => $sources,
            'globals' => $globals,
        ];
    }

    private function parseStageLogicProposal(string $content, array $request): array
    {
        $json = trim($content);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/si', $json, $matches)) $json = trim($matches[1]);
        $proposal = json_decode($json, true);
        if (!is_array($proposal) || array_values($proposal) === $proposal) throw new \RuntimeException('AI вернул невалидный JSON проекта этапа');
        $this->assertNoForbiddenLogicKeys($proposal);
        $this->assertAllowedLogicKeys($proposal, 'proposal', ['schema', 'baseFingerprint', 'status', 'summary', 'assumptions', 'questions', 'draft']);
        if (($proposal['schema'] ?? null) !== self::STAGE_LOGIC_PROPOSAL_SCHEMA) throw new \RuntimeException('AI вернул неподдерживаемую схему проекта этапа');
        if (($proposal['baseFingerprint'] ?? null) !== $request['baseFingerprint']) throw new \RuntimeException('AI-проект относится к другой версии этапа');
        $status = trim((string)($proposal['status'] ?? ''));
        if (!in_array($status, ['proposal', 'needs-clarification', 'cannot-propose'], true)) throw new \RuntimeException('AI вернул неизвестный статус проекта этапа');

        $assumptions = [];
        foreach (array_slice(is_array($proposal['assumptions'] ?? null) ? $proposal['assumptions'] : [], 0, 15) as $value) {
            $assumptions[] = $this->logicText($value, 'assumption', 600);
        }
        $questions = [];
        $rawQuestions = is_array($proposal['questions'] ?? null) ? $proposal['questions'] : [];
        if (count($rawQuestions) > 3) throw new \RuntimeException('AI вернул больше трёх уточняющих вопросов');
        foreach ($rawQuestions as $index => $question) {
            if (!is_array($question)) throw new \RuntimeException('Некорректный вопрос AI');
            $this->assertAllowedLogicKeys($question, 'questions[' . $index . ']', ['key', 'text']);
            $questions[] = [
                'key' => $this->logicCode($question['key'] ?? '', 'questions[' . $index . '].key'),
                'text' => $this->logicText($question['text'] ?? '', 'questions[' . $index . '].text', 500),
            ];
        }
        if ($status !== 'proposal') {
            if (($proposal['draft'] ?? null) !== null) throw new \RuntimeException('AI не должен возвращать draft до уточнения');
            if ($status === 'needs-clarification' && count($questions) === 0) throw new \RuntimeException('AI не вернул уточняющий вопрос');
            return [
                'schema' => self::STAGE_LOGIC_PROPOSAL_SCHEMA,
                'baseFingerprint' => $request['baseFingerprint'],
                'status' => $status,
                'summary' => $this->logicText($proposal['summary'] ?? '', 'summary', 1200),
                'assumptions' => $assumptions,
                'questions' => $questions,
                'draft' => null,
            ];
        }
        if (count($questions) !== 0) throw new \RuntimeException('Готовый AI-проект не должен содержать вопросы');
        $draft = is_array($proposal['draft'] ?? null) ? $proposal['draft'] : null;
        if ($draft === null) throw new \RuntimeException('AI не вернул draft этапа');
        $this->assertAllowedLogicKeys($draft, 'draft', ['inputs', 'variables', 'results', 'additionalResults', 'globalAssignments']);
        $allowedRefs = array_fill_keys(array_column($request['availableSources'], 'ref'), true);
        $symbols = [];
        $inputs = [];
        $rawInputs = is_array($draft['inputs'] ?? null) ? $draft['inputs'] : [];
        if (count($rawInputs) > 100) throw new \RuntimeException('Слишком много входов в AI-проекте');
        foreach ($rawInputs as $index => $input) {
            if (!is_array($input)) throw new \RuntimeException('draft.inputs должен содержать объекты');
            $this->assertAllowedLogicKeys($input, 'draft.inputs[' . $index . ']', ['code', 'title', 'description', 'type', 'sourceRef']);
            $code = $this->logicCode($input['code'] ?? '', 'draft.inputs[' . $index . '].code');
            if (isset($symbols[$code])) throw new \RuntimeException('Код в AI-проекте объявлен повторно');
            $symbols[$code] = 'input';
            $ref = trim((string)($input['sourceRef'] ?? ''));
            if (!isset($allowedRefs[$ref])) throw new \RuntimeException('AI сослался на недоступный источник');
            $type = trim((string)($input['type'] ?? 'unknown'));
            if (!in_array($type, self::LOGIC_SYMBOL_TYPES, true)) throw new \RuntimeException('Некорректный тип входа AI');
            $inputs[] = [
                'code' => $code,
                'title' => $this->logicOptionalText($input['title'] ?? '', 200),
                'description' => $this->logicOptionalText($input['description'] ?? '', 500),
                'type' => $type,
                'sourceRef' => $ref,
            ];
        }
        $variables = [];
        $rawVariables = is_array($draft['variables'] ?? null) ? $draft['variables'] : [];
        if (count($rawVariables) > 150) throw new \RuntimeException('Слишком много переменных в AI-проекте');
        foreach ($rawVariables as $index => $variable) {
            if (!is_array($variable)) throw new \RuntimeException('draft.variables должен содержать объекты');
            $this->assertAllowedLogicKeys($variable, 'draft.variables[' . $index . ']', ['code', 'title', 'description', 'type', 'formula']);
            $code = $this->logicCode($variable['code'] ?? '', 'draft.variables[' . $index . '].code');
            if (isset($symbols[$code])) throw new \RuntimeException('Код в AI-проекте объявлен повторно');
            $symbols[$code] = 'variable';
            $type = trim((string)($variable['type'] ?? 'unknown'));
            if (!in_array($type, self::LOGIC_SYMBOL_TYPES, true)) throw new \RuntimeException('Некорректный тип переменной AI');
            $variables[] = [
                'code' => $code,
                'title' => $this->logicOptionalText($variable['title'] ?? '', 200),
                'description' => $this->logicOptionalText($variable['description'] ?? '', 500),
                'type' => $type,
                'formula' => $this->logicText($variable['formula'] ?? '', 'draft.variables[' . $index . '].formula', 6000),
            ];
        }
        $allowedResultKeys = ['width', 'length', 'height', 'weight', 'purchasingPrice', 'basePrice', 'operationPurchasingPrice', 'operationBasePrice', 'materialPurchasingPrice', 'materialBasePrice'];
        $results = [];
        foreach (is_array($draft['results'] ?? null) ? $draft['results'] : [] as $index => $result) {
            if (!is_array($result)) throw new \RuntimeException('draft.results должен содержать объекты');
            $this->assertAllowedLogicKeys($result, 'draft.results[' . $index . ']', ['key', 'source']);
            $key = trim((string)($result['key'] ?? ''));
            $source = $this->logicCode($result['source'] ?? '', 'draft.results[' . $index . '].source');
            if (!in_array($key, $allowedResultKeys, true) || !isset($symbols[$source])) throw new \RuntimeException('Некорректная связь результата AI');
            $results[] = ['key' => $key, 'source' => $source];
        }
        $additional = [];
        foreach (array_slice(is_array($draft['additionalResults'] ?? null) ? $draft['additionalResults'] : [], 0, 30) as $index => $result) {
            if (!is_array($result)) throw new \RuntimeException('draft.additionalResults должен содержать объекты');
            $this->assertAllowedLogicKeys($result, 'draft.additionalResults[' . $index . ']', ['code', 'title', 'source']);
            $source = $this->logicCode($result['source'] ?? '', 'draft.additionalResults[' . $index . '].source');
            if (!isset($symbols[$source])) throw new \RuntimeException('Дополнительный результат ссылается на неизвестный код');
            $additional[] = [
                'code' => $this->logicCode($result['code'] ?? '', 'draft.additionalResults[' . $index . '].code'),
                'title' => $this->logicText($result['title'] ?? '', 'draft.additionalResults[' . $index . '].title', 200),
                'source' => $source,
            ];
        }

        $mutableGlobalCodes = [];
        foreach ($request['globals'] as $global) {
            if (($global['kind'] ?? '') === 'variable') $mutableGlobalCodes[(string)$global['code']] = true;
        }
        $globalAssignments = [];
        $assignedGlobalCodes = [];
        $rawGlobalAssignments = is_array($draft['globalAssignments'] ?? null) ? $draft['globalAssignments'] : [];
        if (count($rawGlobalAssignments) > 50) throw new \RuntimeException('Слишком много глобальных присваиваний в AI-проекте');
        foreach ($rawGlobalAssignments as $index => $assignment) {
            if (!is_array($assignment)) throw new \RuntimeException('draft.globalAssignments должен содержать объекты');
            $this->assertAllowedLogicKeys($assignment, 'draft.globalAssignments[' . $index . ']', ['targetCode', 'source']);
            $targetCode = $this->logicCode($assignment['targetCode'] ?? '', 'draft.globalAssignments[' . $index . '].targetCode');
            if (!isset($mutableGlobalCodes[$targetCode])) throw new \RuntimeException('AI попытался изменить недоступное глобальное значение');
            if (isset($assignedGlobalCodes[$targetCode])) throw new \RuntimeException('AI повторно назначил глобальное значение');
            $assignedGlobalCodes[$targetCode] = true;
            $source = $this->logicCode($assignment['source'] ?? '', 'draft.globalAssignments[' . $index . '].source');
            if (!isset($symbols[$source])) throw new \RuntimeException('Глобальное присваивание AI ссылается на необъявленный код');
            $globalAssignments[] = ['targetCode' => $targetCode, 'source' => $source];
        }

        return [
            'schema' => self::STAGE_LOGIC_PROPOSAL_SCHEMA,
            'baseFingerprint' => $request['baseFingerprint'],
            'status' => 'proposal',
            'summary' => $this->logicText($proposal['summary'] ?? '', 'summary', 1200),
            'assumptions' => $assumptions,
            'questions' => [],
            'draft' => ['inputs' => $inputs, 'variables' => $variables, 'results' => $results, 'additionalResults' => $additional, 'globalAssignments' => $globalAssignments],
        ];
    }

    private function parseLogicAuditProposal(string $content, string $fingerprint, array $targets): array
    {
        $json = trim($content);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/si', $json, $matches)) $json = trim($matches[1]);
        $proposal = json_decode($json, true);
        if (!is_array($proposal) || array_values($proposal) === $proposal) {
            throw new \RuntimeException('AI вернул невалидный JSON анализа');
        }
        $this->assertNoForbiddenLogicKeys($proposal);
        $this->assertAllowedLogicKeys($proposal, 'proposal', ['schema', 'baseFingerprint', 'summary', 'suggestions']);
        if (($proposal['schema'] ?? null) !== self::LOGIC_AUDIT_PROPOSAL_SCHEMA || ($proposal['baseFingerprint'] ?? null) !== $fingerprint) {
            throw new \RuntimeException('AI-анализ относится к другой версии логики');
        }
        $rawSuggestions = is_array($proposal['suggestions'] ?? null) ? $proposal['suggestions'] : [];
        if (count($rawSuggestions) > 300) throw new \RuntimeException('AI вернул слишком много предложений');
        $suggestions = [];
        $allowedTypes = ['auto', 'number', 'string', 'bool', 'boolean', 'array', 'object', 'any', 'unknown'];
        foreach ($rawSuggestions as $index => $suggestion) {
            if (!is_array($suggestion)) throw new \RuntimeException('Некорректное предложение AI');
            $this->assertAllowedLogicKeys($suggestion, 'suggestions[' . $index . ']', ['id', 'targetId', 'targetKind', 'patch', 'rationale']);
            $targetId = trim((string)($suggestion['targetId'] ?? ''));
            $target = $targets[$targetId] ?? null;
            if (!$target || ($suggestion['targetKind'] ?? null) !== $target['kind']) {
                throw new \RuntimeException('AI сослался на неизвестный объект анализа');
            }
            $rawPatch = is_array($suggestion['patch'] ?? null) ? $suggestion['patch'] : [];
            $this->assertAllowedLogicKeys($rawPatch, 'suggestions[' . $index . '].patch', ['code', 'title', 'description', 'type', 'formula']);
            $patch = [];
            if (array_key_exists('code', $rawPatch)) {
                if (!$target['codeMutable']) throw new \RuntimeException('AI попытался изменить неизменяемый системный код');
                $patch['code'] = $this->logicCode($rawPatch['code'], 'suggestions[' . $index . '].patch.code');
            }
            foreach (['title' => 250, 'description' => 4000, 'formula' => 12000] as $key => $maxLength) {
                if (array_key_exists($key, $rawPatch)) $patch[$key] = $this->logicOptionalText($rawPatch[$key], $maxLength);
            }
            if (array_key_exists('type', $rawPatch)) {
                $type = trim((string)$rawPatch['type']);
                if (!in_array($type, $allowedTypes, true)) throw new \RuntimeException('AI предложил неизвестный тип');
                $patch['type'] = $type;
            }
            if ($patch === []) continue;
            $suggestions[] = [
                'id' => $this->logicOptionalText($suggestion['id'] ?? ('suggestion_' . ($index + 1)), 120),
                'targetId' => $targetId,
                'targetKind' => $target['kind'],
                'patch' => $patch,
                'rationale' => $this->logicOptionalText($suggestion['rationale'] ?? '', 2000),
            ];
        }
        return [
            'schema' => self::LOGIC_AUDIT_PROPOSAL_SCHEMA,
            'baseFingerprint' => $fingerprint,
            'summary' => $this->logicOptionalText($proposal['summary'] ?? '', 4000),
            'suggestions' => $suggestions,
        ];
    }

    private function assertNoForbiddenLogicKeys($value): void
    {
        if (!is_array($value)) return;
        foreach ($value as $key => $nested) {
            if (is_string($key) && in_array($key, self::LOGIC_FORBIDDEN_KEYS, true)) {
                throw new \InvalidArgumentException('AI-контракт не принимает внутренние пути и идентификаторы');
            }
            $this->assertNoForbiddenLogicKeys($nested);
        }
    }

    private function assertAllowedLogicKeys(array $value, string $label, array $allowed): void
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || in_array($key, $allowed, true)) continue;
            throw new \InvalidArgumentException($label . ' содержит неизвестное поле ' . $key);
        }
    }

    private function logicCode($value, string $label): string
    {
        $code = trim((string)$value);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $code) || mb_strlen($code) > 120) {
            throw new \InvalidArgumentException($label . ' содержит недопустимый код');
        }
        return $code;
    }

    private function logicText($value, string $label, int $maxLength): string
    {
        $text = trim((string)$value);
        if ($text === '' || mb_strlen($text) > $maxLength) {
            throw new \InvalidArgumentException($label . ' не заполнен или слишком длинный');
        }
        return $text;
    }

    private function logicOptionalText($value, int $maxLength): string
    {
        return mb_substr(trim((string)$value), 0, $maxLength);
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $apiKey = trim((string)Option::get(self::MODULE_ID, self::KEY_OPTION, ''));
        if ($apiKey === '') throw new \RuntimeException('API-ключ Timeweb AI Gateway не задан');
        // A complete stage draft is substantially larger than a short text or
        // single-formula answer. Timeweb can legitimately need more than one
        // minute to return it, especially after a clarification round.
        $client = new HttpClient(['socketTimeout' => 15, 'streamTimeout' => 180]);
        $client->setHeader('Authorization', 'Bearer ' . $apiKey);
        $client->setHeader('Accept', 'application/json');
        $url = self::BASE_URL . $path;
        if ($method === 'GET') $raw = $client->get($url); else {
            $client->setHeader('Content-Type', 'application/json');
            $raw = $client->post($url, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $status = (int)$client->getStatus();
        $decoded = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = $this->extractGatewayError($decoded);
            if ($message === '' && method_exists($client, 'getError')) {
                $errors = $client->getError();
                if (is_array($errors) && $errors !== []) $message = implode('; ', array_map('strval', $errors));
            }
            throw new \RuntimeException('Timeweb AI Gateway: ' . mb_substr($message !== '' ? $message : ('HTTP ' . $status), 0, 1000));
        }
        return $decoded;
    }

    private function extractGatewayError($decoded): string
    {
        if (!is_array($decoded)) return '';
        $error = $decoded['error'] ?? null;
        if (is_string($error)) return trim($error);
        if (is_array($error)) {
            $message = $error['message'] ?? $error['detail'] ?? '';
            if (is_string($message)) return trim($message);
        }
        foreach (['message', 'detail'] as $key) if (isset($decoded[$key]) && is_string($decoded[$key])) return trim($decoded[$key]);
        return '';
    }

    private function assertAdmin(): void
    {
        global $USER;
        if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) throw new \RuntimeException('Недостаточно прав для настройки AI-сервиса');
    }
}
