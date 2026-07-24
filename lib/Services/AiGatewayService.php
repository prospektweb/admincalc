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
    ];
    private const STRUCTURED_ZONES = [
        'calculator_description',
        'operation_description',
        'operation_variant_description',
        'equipment_description',
        'material_description',
        'material_variant_description',
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
        $models = [];
        $modelsError = '';
        if ($apiKey !== '') {
            try { $models = $this->getModels(false); } catch (\Throwable $error) { $modelsError = $error->getMessage(); }
        }
        return ['status' => 'ok', 'hasApiKey' => $apiKey !== '', 'templates' => $this->getTemplates(), 'models' => $models, 'modelsError' => $modelsError];
    }

    public function saveSettings(array $request): array
    {
        $this->assertAdmin();
        $apiKey = trim((string)($request['apiKey'] ?? ''));
        if ($apiKey !== '') {
            Option::set(self::MODULE_ID, self::KEY_OPTION, $apiKey);
            Option::delete(self::MODULE_ID, ['name' => self::MODELS_CACHE_OPTION]);
        }
        $templates = $this->sanitizeTemplates(is_array($request['templates'] ?? null) ? $request['templates'] : []);
        $models = [];
        $modelsError = '';
        try { $models = $this->getModels(true); } catch (\Throwable $error) { $modelsError = $error->getMessage(); }
        foreach ($templates as &$template) {
            if ($template['model'] === '') $template['model'] = self::DEFAULT_MODEL;
        }
        unset($template);
        Option::set(self::MODULE_ID, self::TEMPLATES_OPTION, json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['status' => 'ok', 'hasApiKey' => trim((string)Option::get(self::MODULE_ID, self::KEY_OPTION, '')) !== '', 'templates' => $templates, 'models' => $models, 'modelsError' => $modelsError];
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
        $tags = [];
        foreach (self::ZONE_CONTEXT[$zone] as $tag => $contextKey) $tags[$tag] = (string)($context[$contextKey] ?? '');
        $override = trim((string)($request['prompt'] ?? ''));
        $prompt = strtr($override !== '' ? mb_substr($override, 0, 12000) : (string)$template['prompt'], $tags);
        if (in_array($zone, self::STRUCTURED_ZONES, true)) {
            $schema = $zone === 'equipment_description' ? self::EQUIPMENT_RESPONSE_SCHEMA : self::CATALOG_RESPONSE_SCHEMA;
            $prompt .= "\n\nОбязательная схема ответа JSON:\n"
                . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                . "\nНе добавляй поля вне схемы. Неизвестные числа возвращай как null, неизвестные строки — как пустую строку. "
                . "В parameters помещай только подтверждённые технические особенности, для которых нет отдельного поля. "
                . "catalog.weightG означает физическую массу в граммах; catalog.lengthMm, catalog.widthMm и catalog.heightMm — внешние габариты в миллиметрах.";
        }
        $response = $this->request('POST', '/chat/completions', ['model' => (string)$template['model'], 'messages' => [['role' => 'user', 'content' => $prompt]]]);
        $content = trim((string)($response['choices'][0]['message']['content'] ?? ''));
        if ($content === '') throw new \RuntimeException('AI Gateway вернул пустой текст');
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
        }
        unset($template);
        $present = array_fill_keys(array_column($templates, 'zone'), true);
        foreach ($this->getTemplatesFallback() as $fallback) if (!isset($present[$fallback['zone']])) $templates[] = $fallback;
        return $templates;
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
        $client = new HttpClient(['socketTimeout' => 15, 'streamTimeout' => 60]);
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
