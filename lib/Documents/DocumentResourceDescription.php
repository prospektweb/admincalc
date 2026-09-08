<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/DocumentRepository.php';

/** Description drafting only. Version/resource authority is rechecked before
 * and after the gateway call; no transaction or write spans external I/O. */
final class DocumentResourceDescription
{
    public function __construct(private $loadCard, private $settings, private $generate) {}
    public function command(array $request): array
    {
        $action = $request['action'] ?? ''; $generate = $action === 'generateResourceDescription';
        $keys = array_keys($request); sort($keys);
        $expected = ['action', 'binding', 'expectedFingerprint', 'expectedRevision', 'id', 'versionId'];
        if ($generate) $expected = array_merge($expected, ['context', 'prompt', 'templateId', 'zone']); sort($expected);
        if (!in_array($action, ['resourceDescriptionTemplates', 'generateResourceDescription'], true) || $keys !== $expected
            || !is_string($request['expectedFingerprint']) || !preg_match('/^[a-f0-9]{64}$/D', $request['expectedFingerprint'])) throw new \InvalidArgumentException('Invalid resource description command.');
        $load = (object)['action' => 'loadResourceCard', 'binding' => $request['binding'], 'expectedRevision' => $request['expectedRevision'], 'id' => $request['id'], 'versionId' => $request['versionId']];
        $card = $this->current($load, $request['expectedFingerprint']);
        $type = $card['entityType'];
        $zones = match ($type) { 'equipment' => ['equipment_description'], 'material', 'operation' => [$type . '_description', $type . '_variant_description'], default => throw new \InvalidArgumentException('Unsupported description type.') };
        if (!$generate) {
            $settings = ($this->settings)(); $templates = [];
            if (($settings['status'] ?? '') !== 'ok' || !is_array($settings['templates'] ?? null)) throw new \RuntimeException('AI settings unavailable.', 503);
            foreach ($settings['templates'] as $template) {
                if (!in_array($template['zone'] ?? null, $zones, true)) continue;
                $row = [];
                foreach (['id', 'zone', 'name', 'prompt', 'model'] as $key) {
                    if (!is_string($template[$key] ?? null)) throw new \RuntimeException('Invalid resource description template.', 503);
                    $row[$key] = $template[$key];
                }
                $templates[] = $row;
            }
            $result = ['templates' => $templates];
        } else {
            if (!in_array($request['zone'], $zones, true) || !$request['context'] instanceof \stdClass) throw new \InvalidArgumentException('Invalid resource description zone/context.');
            foreach (['prompt' => 48000, 'templateId' => 128] as $key => $limit) if (!is_string($request[$key]) || !trim($request[$key]) || strlen($request[$key]) > $limit) throw new \InvalidArgumentException('Invalid resource description prompt.');
            $context = get_object_vars($request['context']);
            $allowed = array_merge(['sourceLinks', 'equipmentSources'], $type === 'equipment' ? ['equipmentName'] : [$type . 'Name', $type . 'Preview', $type . 'VariantName']);
            if (array_diff(array_keys($context), $allowed)) throw new \InvalidArgumentException('Unknown resource description context.');
            foreach ($context as $key => $value) if (!is_string($value) || strlen($value) > (str_ends_with($key, 'Name') ? 1020 : 100000)) throw new \InvalidArgumentException('Invalid resource description context value.');
            $response = ($this->generate)(['zone' => $request['zone'], 'templateId' => $request['templateId'], 'prompt' => trim($request['prompt']), 'context' => $context]);
            if (($response['status'] ?? '') !== 'ok' || !is_string($response['text'] ?? null) || !trim($response['text']) || strlen($response['text']) > 100000) throw new \RuntimeException('Invalid generated resource card.', 503);
            $result = ['text' => $response['text']];
        }
        $this->current($load, $request['expectedFingerprint']);
        return $result;
    }
    private function current(\stdClass $load, string $fingerprint): array
    {
        $card = ($this->loadCard)($load);
        if (!hash_equals($fingerprint, $card['fingerprint'])) throw new DocumentConflict('Ресурс изменился. Откройте актуальную карточку перед AI-заполнением.');
        return $card;
    }
}
