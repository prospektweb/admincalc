<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Config\Option;

/** Isolated editable form documents owned by calculator version ids. */
final class CalculatorVersionFormDocumentService
{
    public const CONTRACT = 'prospektweb.calc.calculator-version-form/v1';

    private const MODULE_ID = 'prospektweb.calc';
    private const STORAGE_VERSION = 1;
    private const MAX_BYTES = 2097152;

    /** @var array<string,callable> */
    private array $adapters;

    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    public function has(int $presetId, string $versionId): bool
    {
        $this->assertIdentity($presetId, $versionId);
        return $this->load($presetId, $versionId) !== null;
    }

    /** @return array<string,mixed> */
    public function ensure(
        int $presetId,
        string $versionId,
        ?string $sourceVersionId,
        array $legacyWorkspace
    ): array {
        $this->assertIdentity($presetId, $versionId);
        $stored = $this->load($presetId, $versionId);
        if ($stored !== null) {
            return $this->publicDocument($stored);
        }
        $source = null;
        if ($sourceVersionId !== null) {
            $this->assertIdentity($presetId, $sourceVersionId);
            $source = $this->load($presetId, $sourceVersionId);
        }
        $now = $this->now();
        $document = [
            'storageVersion' => self::STORAGE_VERSION,
            'presetId' => $presetId,
            'versionId' => $versionId,
            'formDefinition' => $source['formDefinition'] ?? $legacyWorkspace['formDefinition'] ?? null,
            'bindingDefinition' => $source['bindingDefinition'] ?? $legacyWorkspace['bindingDefinition'] ?? null,
            'createdAt' => $now,
            'updatedAt' => $now,
        ];
        $this->assertDocument($document);
        $this->save($document);
        return $this->publicDocument($document);
    }

    /** @return array<string,mixed> */
    public function saveDraft(
        int $presetId,
        string $versionId,
        string $expectedRevision,
        array $formDefinition,
        array $bindingDefinition
    ): array {
        $this->assertIdentity($presetId, $versionId);
        $this->assertRevision($expectedRevision);
        $current = $this->load($presetId, $versionId);
        if ($current === null) {
            throw new \RuntimeException('Документ версии не найден. Перезагрузите список версий.', 409);
        }
        if (!hash_equals($this->revision($current), $expectedRevision)) {
            throw new \RuntimeException('Форма версии изменена в другой вкладке. Перезагрузите редактор.', 409);
        }
        $current['formDefinition'] = $formDefinition;
        $current['bindingDefinition'] = $bindingDefinition;
        $current['updatedAt'] = $this->now();
        $this->assertDocument($current);
        $this->save($current);
        return $this->publicDocument($current);
    }

    public function delete(int $presetId, string $versionId): void
    {
        $this->assertIdentity($presetId, $versionId);
        if (isset($this->adapters['delete'])) {
            call_user_func($this->adapters['delete'], $this->optionName($presetId, $versionId));
            return;
        }
        Option::delete(self::MODULE_ID, ['name' => $this->optionName($presetId, $versionId)]);
    }

    /** @return array<string,mixed>|null */
    private function load(int $presetId, string $versionId): ?array
    {
        $raw = isset($this->adapters['get'])
            ? (string)call_user_func($this->adapters['get'], $this->optionName($presetId, $versionId))
            : (string)Option::get(self::MODULE_ID, $this->optionName($presetId, $versionId), '');
        if ($raw === '') {
            return null;
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new \RuntimeException('Документ формы версии превышает безопасный размер.');
        }
        $document = json_decode($raw, true);
        if (!is_array($document)) {
            throw new \RuntimeException('Документ формы версии повреждён.');
        }
        $this->assertDocument($document);
        if ((int)$document['presetId'] !== $presetId || (string)$document['versionId'] !== $versionId) {
            throw new \RuntimeException('Документ формы не принадлежит запрошенной версии.');
        }
        return $document;
    }

    private function save(array $document): void
    {
        $encoded = $this->encodeCanonical($document);
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Документ формы версии превышает безопасный лимит 2 МБ.');
        }
        $name = $this->optionName((int)$document['presetId'], (string)$document['versionId']);
        if (isset($this->adapters['set'])) {
            call_user_func($this->adapters['set'], $name, $encoded);
        } else {
            Option::set(self::MODULE_ID, $name, $encoded);
        }
        $readBack = $this->load((int)$document['presetId'], (string)$document['versionId']);
        if ($readBack === null || !hash_equals($this->revision($document), $this->revision($readBack))) {
            throw new \RuntimeException('Не удалось подтвердить сохранение документа формы версии.');
        }
    }

    /** @return array<string,mixed> */
    private function publicDocument(array $document): array
    {
        return [
            'contract' => self::CONTRACT,
            'presetId' => (int)$document['presetId'],
            'versionId' => (string)$document['versionId'],
            'revision' => $this->revision($document),
            'formDefinition' => $document['formDefinition'],
            'bindingDefinition' => $document['bindingDefinition'],
            'updatedAt' => (string)$document['updatedAt'],
        ];
    }

    private function assertDocument(array $document): void
    {
        $this->assertIdentity((int)($document['presetId'] ?? 0), (string)($document['versionId'] ?? ''));
        if ((int)($document['storageVersion'] ?? 0) !== self::STORAGE_VERSION
            || !is_array($document['formDefinition'] ?? null)
            || !is_array($document['bindingDefinition'] ?? null)
            || !is_string($document['createdAt'] ?? null)
            || !is_string($document['updatedAt'] ?? null)) {
            throw new \RuntimeException('Документ формы версии имеет несовместимый контракт.');
        }
    }

    private function assertIdentity(int $presetId, string $versionId): void
    {
        if ($presetId <= 0 || preg_match('/^v_[a-f0-9]{16,40}$/D', $versionId) !== 1) {
            throw new \InvalidArgumentException('Некорректный идентификатор версии калькулятора.');
        }
    }

    private function assertRevision(string $revision): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new \InvalidArgumentException('expectedVersionRevision must be a lowercase SHA-256.');
        }
    }

    private function optionName(int $presetId, string $versionId): string
    {
        return 'CALC_VERSION_FORM_' . $presetId . '_' . $versionId;
    }

    private function revision(array $document): string
    {
        return hash('sha256', $this->encodeCanonical($document));
    }

    private function encodeCanonical($value): string
    {
        $canonicalize = function ($node) use (&$canonicalize) {
            if (!is_array($node)) return $node;
            if (array_values($node) === $node) return array_map($canonicalize, $node);
            ksort($node, SORT_STRING);
            foreach ($node as $key => $child) $node[$key] = $canonicalize($child);
            return $node;
        };
        $encoded = json_encode($canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) throw new \RuntimeException('Не удалось сериализовать документ формы версии.');
        return $encoded;
    }

    private function now(): string
    {
        return isset($this->adapters['now']) ? (string)call_user_func($this->adapters['now']) : date(DATE_ATOM);
    }
}
