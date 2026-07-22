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

    public function getSettings(): array
    {
        $this->assertAdmin();
        $apiKey = trim((string)Option::get(self::MODULE_ID, self::KEY_OPTION, ''));
        $models = [];
        $modelsError = '';
        if ($apiKey !== '') {
            try {
                $models = $this->getModels(false);
            } catch (\Throwable $error) {
                $modelsError = $error->getMessage();
            }
        }

        return [
            'status' => 'ok',
            'hasApiKey' => $apiKey !== '',
            'templates' => $this->getTemplates(),
            'models' => $models,
            'modelsError' => $modelsError,
        ];
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
        try {
            $models = $this->getModels(true);
        } catch (\Throwable $error) {
            $modelsError = $error->getMessage();
        }
        if ($models !== []) {
            foreach ($templates as &$template) {
                if ($template['model'] === '') {
                    $template['model'] = (string)$models[0]['id'];
                }
            }
            unset($template);
        }
        Option::set(
            self::MODULE_ID,
            self::TEMPLATES_OPTION,
            json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        return [
            'status' => 'ok',
            'hasApiKey' => trim((string)Option::get(self::MODULE_ID, self::KEY_OPTION, '')) !== '',
            'templates' => $templates,
            'models' => $models,
            'modelsError' => $modelsError,
        ];
    }

    public function generateStagePreview(array $request): array
    {
        $this->assertAdmin();
        $templateId = trim((string)($request['templateId'] ?? ''));
        $template = null;
        foreach ($this->getTemplates() as $candidate) {
            if ((string)$candidate['id'] === $templateId) {
                $template = $candidate;
                break;
            }
        }
        if ($template === null || $template['zone'] !== 'stage_description') {
            throw new \InvalidArgumentException('Шаблон промпта не найден');
        }
        if (trim((string)$template['model']) === '') {
            throw new \InvalidArgumentException('Для шаблона не выбрана модель');
        }

        $context = is_array($request['context'] ?? null) ? $request['context'] : [];
        $tags = [
            '{название этапа}' => (string)($context['stageName'] ?? ''),
            '{название детали}' => (string)($context['detailName'] ?? ''),
            '{анонс детали}' => (string)($context['detailPreview'] ?? ''),
            '{название пресета}' => (string)($context['presetName'] ?? ''),
            '{анонс пресета}' => (string)($context['presetPreview'] ?? ''),
            '{название товара}' => (string)($context['productName'] ?? ''),
            '{анонс товара}' => (string)($context['productPreview'] ?? ''),
        ];
        $prompt = strtr((string)$template['prompt'], $tags);

        $response = $this->request('POST', '/chat/completions', [
            'model' => (string)$template['model'],
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
        ]);
        $content = trim((string)($response['choices'][0]['message']['content'] ?? ''));
        if ($content === '') {
            throw new \RuntimeException('AI Gateway вернул пустой текст');
        }

        return ['status' => 'ok', 'text' => $content];
    }

    public function getModels(bool $forceRefresh = false): array
    {
        $this->assertAdmin();
        $cached = json_decode((string)Option::get(self::MODULE_ID, self::MODELS_CACHE_OPTION, ''), true);
        if (!$forceRefresh && is_array($cached) && (int)($cached['expiresAt'] ?? 0) > time() && is_array($cached['models'] ?? null)) {
            return $cached['models'];
        }

        $response = $this->request('GET', '/models');
        $models = [];
        foreach ((array)($response['data'] ?? []) as $model) {
            $id = trim((string)($model['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $models[] = [
                'id' => $id,
                'name' => trim((string)($model['name'] ?? $model['display_name'] ?? $id)),
            ];
        }
        usort($models, static function (array $a, array $b): int {
            return strnatcasecmp($a['name'], $b['name']);
        });
        Option::set(self::MODULE_ID, self::MODELS_CACHE_OPTION, json_encode([
            'expiresAt' => time() + self::MODELS_CACHE_TTL,
            'models' => $models,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $models;
    }

    private function getTemplates(): array
    {
        $decoded = json_decode((string)Option::get(self::MODULE_ID, self::TEMPLATES_OPTION, ''), true);
        if (is_array($decoded) && $decoded !== []) {
            return $this->sanitizeTemplates($decoded);
        }

        return [[
            'id' => 'stage-description-default',
            'zone' => 'stage_description',
            'prompt' => 'Напиши краткий профессиональный анонс этапа полиграфического производства в 1–2 предложениях. Без списков, заголовков и рекламных штампов. Этап: {название этапа}. Деталь: {название детали}. Анонс детали: {анонс детали}. Пресет: {название пресета}. Анонс пресета: {анонс пресета}. Товар: {название товара}. Анонс товара: {анонс товара}. Верни только готовый анонс.',
            'model' => '',
        ]];
    }

    private function sanitizeTemplates(array $templates): array
    {
        $result = [];
        foreach ($templates as $index => $template) {
            if (!is_array($template)) {
                continue;
            }
            $prompt = trim((string)($template['prompt'] ?? ''));
            if ($prompt === '') {
                continue;
            }
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($template['id'] ?? ''));
            $result[] = [
                'id' => $id !== '' ? $id : 'prompt-' . ($index + 1) . '-' . substr(sha1($prompt), 0, 8),
                'zone' => 'stage_description',
                'prompt' => mb_substr($prompt, 0, 12000),
                'model' => mb_substr(trim((string)($template['model'] ?? '')), 0, 200),
            ];
        }
        return $result ?: $this->getTemplatesFallback();
    }

    private function getTemplatesFallback(): array
    {
        return [[
            'id' => 'stage-description-default',
            'zone' => 'stage_description',
            'prompt' => 'Напиши краткий профессиональный анонс этапа полиграфического производства в 1–2 предложениях. Этап: {название этапа}. Деталь: {название детали}. Пресет: {название пресета}. Товар: {название товара}. Верни только готовый анонс.',
            'model' => '',
        ]];
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $apiKey = trim((string)Option::get(self::MODULE_ID, self::KEY_OPTION, ''));
        if ($apiKey === '') {
            throw new \RuntimeException('API-ключ Timeweb AI Gateway не задан');
        }
        $client = new HttpClient(['socketTimeout' => 15, 'streamTimeout' => 60]);
        $client->setHeader('Authorization', 'Bearer ' . $apiKey);
        $client->setHeader('Accept', 'application/json');
        $url = self::BASE_URL . $path;
        if ($method === 'GET') {
            $raw = $client->get($url);
        } else {
            $client->setHeader('Content-Type', 'application/json');
            $raw = $client->post($url, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $status = (int)$client->getStatus();
        $decoded = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? (string)($decoded['error']['message'] ?? $decoded['message'] ?? '') : '';
            throw new \RuntimeException($message !== '' ? $message : 'Ошибка Timeweb AI Gateway (HTTP ' . $status . ')');
        }
        return $decoded;
    }

    private function assertAdmin(): void
    {
        global $USER;
        if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
            throw new \RuntimeException('Недостаточно прав для настройки AI-агентов');
        }
    }
}
