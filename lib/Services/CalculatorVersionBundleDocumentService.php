<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Config\Option;

/**
 * Immutable-by-convention component store for one calculator version.
 *
 * Large components are deliberately stored in separate option rows. The
 * manifest contains only hashes, sizes and the aggregate content hash, so a
 * corrupt or partially written bundle is detected on every read.
 */
final class CalculatorVersionBundleDocumentService
{
    public const CONTRACT = 'prospektweb.calc.calculator-version-bundle/v2';
    public const LEGACY_CONTRACT = 'prospektweb.calc.calculator-version-bundle/v1';

    public const COMPONENTS = [
        'form',
        'logic',
        'storefronts',
        'inputMappings',
        'outputMappings',
        'productAssignments',
        'publicationMetadata',
        'commercialPolicy',
    ];

    public const LEGACY_COMPONENTS = [
        'form',
        'logic',
        'storefronts',
        'inputMappings',
        'outputMappings',
        'productAssignments',
    ];

    private const MODULE_ID = 'prospektweb.calc';
    private const STORAGE_VERSION = 2;
    private const LEGACY_STORAGE_VERSION = 1;
    private const MAX_COMPONENT_BYTES = 8388608;
    private const MAX_BUNDLE_BYTES = 16777216;

    /** @var array<string,callable> */
    private array $adapters;

    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    public function has(int $presetId, string $versionId): bool
    {
        $this->assertIdentity($presetId, $versionId);
        return $this->rawGet($this->manifestName($presetId, $versionId)) !== '';
    }

    /** @param array<string,mixed> $components @return array<string,mixed> */
    public function inspect(array $components): array
    {
        $components = $this->normalizeComponents($components);
        $hashes = [];
        $totalBytes = 0;
        foreach (self::COMPONENTS as $component) {
            $raw = $this->encodeCanonical($components[$component]);
            $hashes[$component] = hash('sha256', $raw);
            $totalBytes += strlen($raw);
        }
        return [
            'contentHash' => $this->contentHash($components),
            'componentHashes' => $hashes,
            'totalBytes' => $totalBytes,
        ];
    }

    /** @param array<string,mixed> $components @return array<string,mixed> */
    public function save(int $presetId, string $versionId, array $components): array
    {
        $this->assertIdentity($presetId, $versionId);
        $components = $this->normalizeComponents($components);
        $encoded = [];
        $metadata = [];
        $totalBytes = 0;
        foreach (self::COMPONENTS as $component) {
            $raw = $this->encodeCanonical($components[$component]);
            $bytes = strlen($raw);
            if ($bytes > self::MAX_COMPONENT_BYTES) {
                throw new \InvalidArgumentException('Компонент версии ' . $component . ' превышает безопасный лимит 8 МБ.');
            }
            $totalBytes += $bytes;
            $encoded[$component] = $raw;
            $metadata[$component] = [
                'sha256' => hash('sha256', $raw),
                'bytes' => $bytes,
            ];
        }
        if ($totalBytes > self::MAX_BUNDLE_BYTES) {
            throw new \InvalidArgumentException('Полный снимок версии превышает безопасный лимит 16 МБ.');
        }
        $now = $this->now();
        $manifest = [
            'storageVersion' => self::STORAGE_VERSION,
            'contract' => self::CONTRACT,
            'presetId' => $presetId,
            'versionId' => $versionId,
            'contentHash' => $this->contentHash($components),
            'components' => $metadata,
            'totalBytes' => $totalBytes,
            'updatedAt' => $now,
        ];

        // Components are written first and the manifest is the commit marker.
        // A failed write therefore never exposes a complete-looking bundle.
        foreach ($encoded as $component => $raw) {
            $this->rawSet($this->componentName($presetId, $versionId, $component), $raw);
        }
        $this->rawSet($this->manifestName($presetId, $versionId), $this->encodeCanonical($manifest));
        $readBack = $this->load($presetId, $versionId);
        if ($readBack === null || !hash_equals($manifest['contentHash'], (string)$readBack['contentHash'])) {
            throw new \RuntimeException('Не удалось подтвердить полный снимок версии калькулятора.');
        }
        return $readBack;
    }

    /** @return array<string,mixed>|null */
    public function load(int $presetId, string $versionId): ?array
    {
        $this->assertIdentity($presetId, $versionId);
        $rawManifest = $this->rawGet($this->manifestName($presetId, $versionId));
        if ($rawManifest === '') return null;
        $manifest = json_decode($rawManifest, true);
        $manifest = is_array($manifest) ? $manifest : [];
        $manifestComponents = $this->assertManifest($manifest, $presetId, $versionId);
        $documents = [];
        $encodedDocuments = [];
        $totalBytes = 0;
        foreach ($manifestComponents as $component) {
            $raw = $this->rawGet($this->componentName($presetId, $versionId, $component));
            $expected = $manifest['components'][$component];
            if ($raw === ''
                || strlen($raw) !== (int)$expected['bytes']
                || !hash_equals((string)$expected['sha256'], hash('sha256', $raw))) {
                throw new \RuntimeException('Компонент версии ' . $component . ' отсутствует или повреждён.');
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Компонент версии ' . $component . ' имеет несовместимый JSON-контракт.');
            }
            $documents[$component] = $decoded;
            $encodedDocuments[$component] = $raw;
            $totalBytes += strlen($raw);
        }
        $manifestContract = (string)$manifest['contract'];
        if ($totalBytes !== (int)$manifest['totalBytes']
            || !hash_equals(
                (string)$manifest['contentHash'],
                $this->contentHashFromEncodedComponents($encodedDocuments, $manifestContract)
            )) {
            throw new \RuntimeException('Агрегат полного снимка версии повреждён.');
        }
        $selfContainedLogic = $this->hasSelfContainedLogicRuntime(
            is_array($documents['logic'] ?? null) ? $documents['logic'] : [],
            $presetId
        );
        $complete = $manifestContract === self::CONTRACT
            && $manifestComponents === self::COMPONENTS
            && $selfContainedLogic;
        $missingComponents = array_values(array_diff(self::COMPONENTS, $manifestComponents));
        if (!$selfContainedLogic && in_array('logic', $manifestComponents, true)) {
            $missingComponents[] = 'logic.runtimePayload';
        }
        return [
            'contract' => $manifestContract,
            'presetId' => $presetId,
            'versionId' => $versionId,
            'contentHash' => (string)$manifest['contentHash'],
            'componentHashes' => array_map(
                static fn(array $row): string => (string)$row['sha256'],
                $manifest['components']
            ),
            'totalBytes' => $totalBytes,
            'updatedAt' => (string)$manifest['updatedAt'],
            'documents' => $documents,
            'readiness' => [
                'complete' => $complete,
                'missingComponents' => $missingComponents,
                'requiresRebuild' => !$complete,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function copy(int $presetId, string $sourceVersionId, string $targetVersionId): array
    {
        $source = $this->load($presetId, $sourceVersionId);
        if ($source === null) {
            throw new \RuntimeException('Полный снимок исходной версии отсутствует.', 409);
        }
        return $this->save($presetId, $targetVersionId, $source['documents']);
    }

    public function delete(int $presetId, string $versionId): void
    {
        $this->assertIdentity($presetId, $versionId);
        foreach (self::COMPONENTS as $component) {
            $this->rawDelete($this->componentName($presetId, $versionId, $component));
        }
        $this->rawDelete($this->manifestName($presetId, $versionId));
    }

    /** @param array<string,mixed> $components @return array<string,mixed> */
    private function normalizeComponents(array $components): array
    {
        $actual = array_map('strval', array_keys($components));
        $expected = self::COMPONENTS;
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('Полный снимок версии должен содержать все шесть компонентов.');
        }
        foreach (self::COMPONENTS as $component) {
            if (!is_array($components[$component])) {
                throw new \InvalidArgumentException('Компонент версии ' . $component . ' должен быть объектом.');
            }
        }
        return $components;
    }

    /** @return string[] */
    private function assertManifest(array $manifest, int $presetId, string $versionId): array
    {
        $componentKeys = is_array($manifest['components'] ?? null)
            ? array_map('strval', array_keys($manifest['components']))
            : [];
        $storageVersion = (int)($manifest['storageVersion'] ?? 0);
        $contract = (string)($manifest['contract'] ?? '');
        $expectedComponentKeys = $storageVersion === self::LEGACY_STORAGE_VERSION
            && $contract === self::LEGACY_CONTRACT
            ? self::LEGACY_COMPONENTS
            : self::COMPONENTS;
        sort($componentKeys, SORT_STRING);
        sort($expectedComponentKeys, SORT_STRING);
        $supportedManifest = ($storageVersion === self::STORAGE_VERSION && $contract === self::CONTRACT)
            || ($storageVersion === self::LEGACY_STORAGE_VERSION && $contract === self::LEGACY_CONTRACT);
        if (!$supportedManifest
            || (int)($manifest['presetId'] ?? 0) !== $presetId
            || (string)($manifest['versionId'] ?? '') !== $versionId
            || preg_match('/^[a-f0-9]{64}$/D', (string)($manifest['contentHash'] ?? '')) !== 1
            || !is_array($manifest['components'] ?? null)
            || $componentKeys !== $expectedComponentKeys
            || (int)($manifest['totalBytes'] ?? -1) < 0
            || !is_string($manifest['updatedAt'] ?? null)) {
            throw new \RuntimeException('Манифест полного снимка версии повреждён.');
        }
        $orderedComponents = $storageVersion === self::LEGACY_STORAGE_VERSION
            ? self::LEGACY_COMPONENTS
            : self::COMPONENTS;
        foreach ($orderedComponents as $component) {
            $row = $manifest['components'][$component] ?? null;
            if (!is_array($row)
                || preg_match('/^[a-f0-9]{64}$/D', (string)($row['sha256'] ?? '')) !== 1
                || (int)($row['bytes'] ?? 0) <= 0
                || (int)$row['bytes'] > self::MAX_COMPONENT_BYTES) {
                throw new \RuntimeException('Манифест компонента версии ' . $component . ' повреждён.');
            }
        }
        return $orderedComponents;
    }

    private function assertIdentity(int $presetId, string $versionId): void
    {
        if ($presetId <= 0 || preg_match('/^v_[a-f0-9]{16,40}$/D', $versionId) !== 1) {
            throw new \InvalidArgumentException('Некорректный идентификатор версии калькулятора.');
        }
    }

    /** @param array<string,mixed> $logic */
    private function hasSelfContainedLogicRuntime(array $logic, int $presetId): bool
    {
        $runtime = is_array($logic['runtimePayload'] ?? null) ? $logic['runtimePayload'] : [];
        $preset = is_array($runtime['preset'] ?? null) ? $runtime['preset'] : [];
        return ($logic['contract'] ?? null) === 'prospektweb.calc.version-logic-snapshot/v1'
            && (int)($logic['presetId'] ?? 0) === $presetId
            && ($runtime['contract'] ?? null) === 'prospektweb.calc.version-runtime-payload/v1'
            && (int)($preset['id'] ?? 0) === $presetId
            && (int)($preset['runtimePresetId'] ?? 0) > 0
            && is_array($runtime['elementsStore'] ?? null)
            && is_array($runtime['elementsSiblings'] ?? null)
            && is_array($runtime['globalSymbols'] ?? null)
            && is_array($runtime['priceTypes'] ?? null)
            && ($runtime['selectedOffers'] ?? null) === []
            && ($runtime['product'] ?? null) === null
            && ($runtime['neutralInputRequired'] ?? null) === true
            && is_array($runtime['runtimeConfigSnapshot'] ?? null)
            && $runtime['runtimeConfigSnapshot'] !== [];
    }

    /** @param array<string,mixed> $components */
    private function contentHash(array $components, string $contract = self::CONTRACT): string
    {
        return hash('sha256', $this->encodeCanonical([
            'contract' => $contract,
            'components' => $components,
        ]));
    }

    /**
     * Rebuild the aggregate hash from the exact component JSON that was
     * covered by the per-component hashes. Decoding JSON with assoc=true
     * cannot preserve the difference between an empty object and an empty
     * list, so hashing decoded documents produced false corruption reports.
     *
     * @param array<string,string> $encodedComponents
     */
    private function contentHashFromEncodedComponents(array $encodedComponents, string $contract): string
    {
        $ordered = [];
        foreach ($encodedComponents as $component => $raw) {
            $ordered[(string)$component] = (string)$raw;
        }
        ksort($ordered, SORT_STRING);
        $pairs = [];
        foreach ($ordered as $component => $raw) {
            $encodedKey = json_encode($component, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encodedKey)) {
                throw new \RuntimeException('Не удалось сериализовать имя компонента версии.');
            }
            $pairs[] = $encodedKey . ':' . $raw;
        }
        $encodedContract = json_encode($contract, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encodedContract)) {
            throw new \RuntimeException('Не удалось сериализовать контракт полного снимка версии.');
        }
        return hash('sha256', '{"components":{' . implode(',', $pairs) . '},"contract":' . $encodedContract . '}');
    }

    private function manifestName(int $presetId, string $versionId): string
    {
        return 'CALC_VERSION_BUNDLE_' . $presetId . '_' . $versionId;
    }

    private function componentName(int $presetId, string $versionId, string $component): string
    {
        return 'CALC_VERSION_COMPONENT_' . $presetId . '_' . $versionId . '_' . strtoupper($component);
    }

    private function rawGet(string $name): string
    {
        return isset($this->adapters['get'])
            ? (string)call_user_func($this->adapters['get'], $name)
            : (string)Option::get(self::MODULE_ID, $name, '');
    }

    private function rawSet(string $name, string $value): void
    {
        if (isset($this->adapters['set'])) call_user_func($this->adapters['set'], $name, $value);
        else Option::set(self::MODULE_ID, $name, $value);
    }

    private function rawDelete(string $name): void
    {
        if (isset($this->adapters['delete'])) call_user_func($this->adapters['delete'], $name);
        else Option::delete(self::MODULE_ID, ['name' => $name]);
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
        if (!is_string($encoded)) throw new \RuntimeException('Не удалось сериализовать полный снимок версии.');
        return $encoded;
    }

    private function now(): string
    {
        return isset($this->adapters['now']) ? (string)call_user_func($this->adapters['now']) : date(DATE_ATOM);
    }
}
