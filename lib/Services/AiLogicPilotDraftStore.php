<?php

namespace Prospektweb\Calc\Services;

/**
 * Private auxiliary storage for immutable AI proposals and operator decisions.
 * It never writes calculator/catalog entities and is not part of a version bundle.
 */
final class AiLogicPilotDraftStore
{
    private const CONTRACT = 'prospektweb.calc.ai-logic-pilot-store/v1';
    private const DRAFT_CONTRACT = 'prospektweb.calc.ai-logic-pilot-draft/v1';
    private const MAX_BYTES = 1048576;

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim(str_replace('\\', '/', $root ?: dirname(__DIR__, 2) . '/var/ai-logic-pilot'), '/');
    }

    public function load(array $request): array
    {
        $userId = $this->assertAdmin();
        [$presetId, $versionKey, $baseCompileHash, $expectedContentHash] = $this->identity($request);
        $document = $this->readDocument($this->path($userId, $presetId, $versionKey));
        if ($document === null || (string)($document['baseCompileHash'] ?? '') !== $baseCompileHash
            || (string)($document['expectedContentHash'] ?? '') !== $expectedContentHash) {
            return ['status' => 'ok', 'found' => false, 'revision' => 0];
        }
        return ['status' => 'ok', 'found' => true, 'draftRevision' => (int)($document['revision'] ?? 0)] + $document;
    }

    public function save(array $request): array
    {
        $userId = $this->assertAdmin();
        [$presetId, $versionKey, $baseCompileHash, $expectedContentHash] = $this->identity($request);
        $draft = is_array($request['draft'] ?? null) ? $request['draft'] : null;
        $decisions = is_array($request['decisions'] ?? null) ? $request['decisions'] : null;
        $replacements = is_array($request['replacements'] ?? null) ? $request['replacements'] : [];
        $clientRevision = (int)($request['clientRevision'] ?? 0);
        if ($draft === null || ($draft['schema'] ?? null) !== self::DRAFT_CONTRACT || $decisions === null) {
            throw new \InvalidArgumentException('Некорректный AI-черновик или решения.');
        }
        if ($clientRevision <= 0 || $clientRevision > 9007199254740991) {
            throw new \InvalidArgumentException('Некорректная ревизия AI-черновика.');
        }
        $context = is_array($draft['context'] ?? null) ? $draft['context'] : [];
        if ((int)($context['presetId'] ?? 0) !== $presetId || (string)($context['versionKey'] ?? '') !== $versionKey
            || (string)($context['baseCompileHash'] ?? '') !== $baseCompileHash) {
            throw new \InvalidArgumentException('AI-черновик относится к другому калькулятору или версии.');
        }
        foreach ($decisions as $draftId => $decision) {
            if (!is_string($draftId) || !preg_match('/^draft_[a-z0-9][a-z0-9_-]*$/i', $draftId)
                || !in_array($decision, ['approved', 'rejected'], true)) {
                throw new \InvalidArgumentException('Некорректное решение по AI-черновику.');
            }
        }
        foreach ($replacements as $draftId => $replacement) {
            if (!is_string($draftId) || !preg_match('/^draft_[a-z0-9][a-z0-9_-]*$/i', $draftId)
                || !is_array($replacement)
                || !in_array((string)($replacement['realKind'] ?? ''), ['directory', 'material', 'materialVariant', 'operation', 'operationVariant', 'equipment', 'customField', 'calculator'], true)
                || (int)($replacement['realId'] ?? 0) <= 0
                || !preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)($replacement['expectedRevision'] ?? ''))))
                || ((string)($replacement['realKind'] ?? '') === 'directory'
                    && (!in_array((string)($replacement['catalogKind'] ?? ''), ['material', 'operation', 'equipment', 'customField', 'calculator'], true)
                        || !preg_match('/^[A-Z0-9_]{3,120}$/', (string)($replacement['iblockCode'] ?? ''))))) {
                throw new \InvalidArgumentException('Некорректная замена реального объекта AI-черновика.');
            }
        }

        $this->ensureRoot();
        $path = $this->path($userId, $presetId, $versionKey);
        $lock = fopen($path . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new \RuntimeException('Не удалось заблокировать AI-черновик.');
        try {
            $current = $this->readDocument($path);
            if ($current !== null && $clientRevision <= (int)($current['clientRevision'] ?? 0)) {
                return ['status' => 'ok', 'found' => true, 'stale' => true, 'draftRevision' => (int)($current['revision'] ?? 0)] + $current;
            }
            $document = [
                'contract' => self::CONTRACT,
                'presetId' => $presetId,
                'versionKey' => $versionKey,
                'baseCompileHash' => $baseCompileHash,
                'expectedContentHash' => $expectedContentHash,
                'revision' => (int)($current['revision'] ?? 0) + 1,
                'clientRevision' => $clientRevision,
                'updatedAt' => gmdate('c'),
                'draft' => $draft,
                'decisions' => $decisions,
                'replacements' => $replacements,
            ];
            $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($json) > self::MAX_BYTES) throw new \RuntimeException('AI-черновик превышает безопасный размер.');
            $temporary = tempnam($this->root, '.ai-pilot-');
            if ($temporary === false) throw new \RuntimeException('Не удалось подготовить атомарную запись AI-черновика.');
            try {
                if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false || !@rename($temporary, $path)) {
                    throw new \RuntimeException('Не удалось атомарно сохранить AI-черновик.');
                }
                @chmod($path, 0600);
            } finally {
                if (is_file($temporary)) @unlink($temporary);
            }
            return ['status' => 'ok', 'found' => true, 'draftRevision' => (int)$document['revision']] + $document;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function identity(array $request): array
    {
        $presetId = (int)($request['presetId'] ?? 0);
        $versionKey = trim((string)($request['versionKey'] ?? ''));
        $baseCompileHash = strtolower(trim((string)($request['baseCompileHash'] ?? '')));
        $expectedContentHash = strtolower(trim((string)($request['expectedContentHash'] ?? '')));
        if ($presetId <= 0 || $versionKey === '' || strlen($versionKey) > 180
            || !preg_match('/^[A-Za-z0-9_.:-]+$/', $versionKey) || !preg_match('/^[a-f0-9]{64}$/', $baseCompileHash)
            || !preg_match('/^[a-f0-9]{64}$/', $expectedContentHash)) {
            throw new \InvalidArgumentException('Некорректный контекст AI-черновика.');
        }
        return [$presetId, $versionKey, $baseCompileHash, $expectedContentHash];
    }

    private function path(int $userId, int $presetId, string $versionKey): string
    {
        return $this->root . '/' . hash('sha256', $userId . ':' . $presetId . ':' . $versionKey) . '.json';
    }

    private function readDocument(string $path): ?array
    {
        if (!is_file($path)) return null;
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) > self::MAX_BYTES) throw new \RuntimeException('Хранилище AI-черновика повреждено.');
        $document = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($document) || ($document['contract'] ?? null) !== self::CONTRACT) {
            throw new \RuntimeException('Хранилище AI-черновика имеет несовместимый формат.');
        }
        return $document;
    }

    private function ensureRoot(): void
    {
        if (!is_dir($this->root) && !@mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new \RuntimeException('Не удалось создать закрытое хранилище AI-черновиков.');
        }
        if (!is_file($this->root . '/.htaccess')) @file_put_contents($this->root . '/.htaccess', "Deny from all\n", LOCK_EX);
        if (!is_file($this->root . '/index.php')) @file_put_contents($this->root . '/index.php', "<?php http_response_code(404); die();\n", LOCK_EX);
    }

    private function assertAdmin(): int
    {
        global $USER;
        if (!$USER || !$USER->IsAdmin()) throw new \RuntimeException('Недостаточно прав для AI-черновика.');
        $userId = (int)$USER->GetID();
        if ($userId <= 0) throw new \RuntimeException('Не удалось определить владельца AI-черновика.');
        return $userId;
    }
}
