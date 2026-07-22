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
    private const ZONE_CONTEXT = [
        'preset_description' => ['{название пресета}' => 'presetName', '{название товара}' => 'productName', '{анонс товара}' => 'productPreview'],
        'detail_description' => ['{название детали}' => 'detailName', '{название пресета}' => 'presetName', '{анонс пресета}' => 'presetPreview', '{название товара}' => 'productName', '{анонс товара}' => 'productPreview'],
        'stage_description' => ['{название этапа}' => 'stageName', '{название детали}' => 'detailName', '{анонс детали}' => 'detailPreview', '{название пресета}' => 'presetName', '{анонс пресета}' => 'presetPreview', '{название товара}' => 'productName', '{анонс товара}' => 'productPreview'],
        'calculator_description' => ['{название калькулятора}' => 'calculatorName'],
        'operation_description' => ['{название операции}' => 'operationName'],
        'operation_variant_description' => ['{название варианта операции}' => 'operationVariantName', '{название операции}' => 'operationName', '{анонс операции}' => 'operationPreview'],
        'equipment_description' => ['{название оборудования}' => 'equipmentName'],
        'material_description' => ['{название материала}' => 'materialName'],
        'material_variant_description' => ['{название варианта материала}' => 'materialVariantName', '{название материала}' => 'materialName', '{анонс материала}' => 'materialPreview'],
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
        $response = $this->request('POST', '/chat/completions', ['model' => (string)$template['model'], 'messages' => [['role' => 'user', 'content' => $prompt]]]);
        $content = trim((string)($response['choices'][0]['message']['content'] ?? ''));
        if ($content === '') throw new \RuntimeException('AI Gateway вернул пустой текст');
        return ['status' => 'ok', 'text' => $content, 'zone' => $zone, 'templateId' => $templateId];
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
        $definitions = [
            'preset_description' => ['Описание пресета', 'Напиши краткий анонс пресета. Пресет: {название пресета}. Товар: {название товара}. Анонс товара: {анонс товара}. Верни только готовый текст.'],
            'detail_description' => ['Описание детали', 'Напиши краткий технический анонс детали. Деталь: {название детали}. Пресет: {название пресета}. Анонс пресета: {анонс пресета}. Товар: {название товара}. Анонс товара: {анонс товара}. Верни только готовый текст.'],
            'stage_description' => ['Описание этапа', 'Напиши краткий технический анонс этапа. Этап: {название этапа}. Деталь: {название детали}. Анонс детали: {анонс детали}. Пресет: {название пресета}. Анонс пресета: {анонс пресета}. Товар: {название товара}. Анонс товара: {анонс товара}. Верни только готовый текст.'],
            'calculator_description' => ['Описание калькулятора', 'Опиши назначение калькулятора полиграфического производства: {название калькулятора}. Верни только готовый текст.'],
            'operation_description' => ['Описание операции', 'Опиши полиграфическую операцию: {название операции}. Верни только готовый текст.'],
            'operation_variant_description' => ['Описание варианта операции', 'Опиши вариант операции {название варианта операции}. Родительская операция: {название операции}. Анонс: {анонс операции}. Верни только готовый текст.'],
            'equipment_description' => ['Описание оборудования', 'Опиши назначение полиграфического оборудования: {название оборудования}. Верни только готовый текст.'],
            'material_description' => ['Описание материала', 'Опиши полиграфический материал: {название материала}. Верни только готовый текст.'],
            'material_variant_description' => ['Описание варианта материала', 'Опиши вариант материала {название варианта материала}. Родительский материал: {название материала}. Анонс: {анонс материала}. Верни только готовый текст.'],
        ];
        $result = [];
        foreach ($definitions as $zone => [$name, $prompt]) $result[] = ['id' => str_replace('_', '-', $zone) . '-default', 'zone' => $zone, 'name' => $name, 'prompt' => $prompt, 'model' => self::DEFAULT_MODEL];
        return $result;
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
