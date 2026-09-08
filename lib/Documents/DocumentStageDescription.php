<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/DocumentRepository.php';

/** Read-only adapter to the existing description generator. Never sends the
 * document, connection, catalog contents or repository IDs to the AI gateway. */
final class DocumentStageDescription
{
    private DocumentRepository $repository;
    private $settings;
    private $generate;
    public function __construct(DocumentRepository $repository, callable $settings, callable $generate)
    { $this->repository = $repository; $this->settings = $settings; $this->generate = $generate; }

    public function command(array $request): array
    {
        $action = $request['action'] ?? '';
        $keys = array_keys($request); sort($keys);
        $expectedKeys = ['action', 'expectedRevision', 'id', 'stageId', 'versionId'];
        if ($action === 'generateStageDescription') $expectedKeys = array_merge($expectedKeys, ['name', 'prompt', 'templateId']);
        sort($expectedKeys);
        if (!in_array($action, ['stageDescriptionTemplates', 'generateStageDescription'], true) || $keys !== $expectedKeys) throw new \InvalidArgumentException('Invalid stage description command fields.');
        foreach (['id', 'versionId', 'stageId'] as $key) if (!is_string($request[$key]) || $request[$key] === '' || strlen($request[$key]) > 128) throw new \InvalidArgumentException('Invalid stage description identity.');
        if (!is_int($request['expectedRevision']) || $request['expectedRevision'] < 1) throw new \InvalidArgumentException('Invalid stage description revision.');
        $source = $this->repository->versions()->load($request['id'], $request['versionId']);
        $this->assertCurrent($source, $request['expectedRevision']);
        $document = json_decode($source['bodyJson'], true, 64, JSON_THROW_ON_ERROR);
        $stage = null; $part = null;
        foreach ($document['stages'] ?? [] as $candidate) if (($candidate['id'] ?? null) === $request['stageId']) $stage = $candidate;
        if (!$stage) throw new \InvalidArgumentException('Этап не принадлежит версии калькулятора.');
        foreach ($document['parts'] ?? [] as $candidate) if (in_array($request['stageId'], $candidate['stages'] ?? [], true)) $part = $candidate;
        if ($action === 'stageDescriptionTemplates') {
            $settings = ($this->settings)(); $templates = [];
            if (($settings['status'] ?? '') !== 'ok' || !is_array($settings['templates'] ?? null)) throw new \RuntimeException('AI settings unavailable.', 503);
            foreach ($settings['templates'] as $template) {
                if (($template['zone'] ?? null) !== 'stage_description') continue;
                $row = [];
                foreach (['id', 'zone', 'name', 'prompt', 'model'] as $key) {
                    if (!is_string($template[$key] ?? null)) throw new \RuntimeException('Invalid description template.', 503);
                    $row[$key] = $template[$key];
                }
                $templates[] = $row;
            }
            $result = ['templates' => $templates];
        } else {
            foreach (['name' => 1020, 'prompt' => 48000, 'templateId' => 128] as $key => $limit) if (!is_string($request[$key]) || !trim($request[$key]) || strlen($request[$key]) > $limit) throw new \InvalidArgumentException('Invalid stage description prompt.');
            $response = ($this->generate)(['zone' => 'stage_description', 'templateId' => $request['templateId'], 'prompt' => trim($request['prompt']), 'context' => [
                'stageName' => trim($request['name']), 'detailName' => (string)($part['name'] ?? ''), 'detailPreview' => (string)($part['description'] ?? ''),
                'presetName' => (string)$document['name'], 'presetPreview' => (string)($document['description'] ?? ''), 'productName' => '', 'productPreview' => '',
            ]]);
            if (($response['status'] ?? '') !== 'ok' || !is_string($response['text'] ?? null) || !trim($response['text']) || strlen($response['text']) > 100000) throw new \RuntimeException('Invalid generated description.', 503);
            $result = ['text' => $response['text']];
        }
        $this->assertCurrent($this->repository->versions()->load($request['id'], $request['versionId']), $request['expectedRevision']);
        return $result;
    }
    private function assertCurrent(array $source, int $revision): void
    { if ($source['revision'] !== $revision || $source['archived'] || $source['versionArchived']) throw new DocumentConflict('Версия изменилась или находится в архиве. Откройте настройки этапа заново.'); }
}
